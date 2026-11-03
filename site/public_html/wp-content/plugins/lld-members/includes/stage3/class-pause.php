<?php
/**
 * Pause subscription (P3-63; FR3-65, FR3-68).
 *
 * A monthly Premium member pauses for 1, 2 or 3 months instead of cancelling. The pause starts at the end of the
 * month already paid for and the renewal date moves back by its length. While paused there is no charge and the
 * member has Free access; nothing is deleted. Premium returns at the end at the member's own price, and a failed
 * payment then follows the Stage 2 retries. Yearly members are not offered a pause.
 *
 * Where it stands is worked out from the dates on each read (scheduled, active, ended), so no job has to run on
 * the day it starts or ends. With real Stripe on (`stripe_api`), the subscription's collection is paused with
 * behaviour "void" until just before the moved renewal: the renewal at the start of the pause is not charged and
 * the next one is. Stripe keeps the subscription's own price, so the member's price is unchanged.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pauses, stored in lld_pauses.
 */
class LLD_Members_Pause {

	const MONTHS = array( 1, 2, 3 );

	/** @var array<int,object|null> Pause per member for this request. */
	private static $memo = array();

	public static function boot() {
		add_filter( 'lld_members_paid_open', array( __CLASS__, 'paid_open' ), 10, 2 );
		add_filter( 'lld_members_cancel_offer', array( __CLASS__, 'cancel_offer' ), 10, 3 );
		add_action( 'lld_members_subscription_post', array( __CLASS__, 'post' ), 10, 2 );
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function on( $member_id ) {
		return LLD_Members_Features::enabled( 'pause', (int) $member_id );
	}

	/**
	 * The dates of a pause that starts when the paid month ends (TC-FR3-65-AS-01).
	 *
	 * @param string $renewal Next renewal, Y-m-d (end of the month already paid for).
	 * @param int    $months  1, 2 or 3.
	 * @return array{starts_at:string,ends_at:string}|null
	 */
	public static function dates( $renewal, $months ) {
		if ( ! in_array( (int) $months, self::MONTHS, true ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $renewal ) ) {
			return null;
		}
		return array(
			'starts_at' => (string) $renewal,
			'ends_at'   => self::add_months( (string) $renewal, (int) $months ),
		);
	}

	/**
	 * Calendar months, keeping the day where it exists and otherwise the month's last day (31 Jan + 1 = 28 or 29 Feb).
	 *
	 * @param string $date   Y-m-d.
	 * @param int    $months Months to add.
	 */
	public static function add_months( $date, $months ) {
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
		$m += $months;
		$y += intdiv( $m - 1, 12 );
		$m  = ( ( $m - 1 ) % 12 ) + 1;
		$last = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, min( $d, $last ) );
	}

	/**
	 * Where a pause stands on a day. A cancelled pause stays cancelled.
	 *
	 * @param string $status    Stored status.
	 * @param string $starts_at Y-m-d.
	 * @param string $ends_at   Y-m-d.
	 * @param string $today     Y-m-d.
	 */
	public static function status_on( $status, $starts_at, $ends_at, $today ) {
		if ( 'cancelled' === $status ) {
			return 'cancelled';
		}
		if ( $today < $starts_at ) {
			return 'scheduled';
		}
		return $today < $ends_at ? 'active' : 'ended';
	}

	/**
	 * Who may pause: monthly, Premium now, not cancelling, not switching to yearly, and no pause already open.
	 *
	 * @param object|null $sub     Stage 2 subscription row.
	 * @param bool        $premium Premium now.
	 * @param object|null $pause   The member's open pause.
	 */
	public static function may_pause( $sub, $premium, $pause ) {
		if ( ! $sub || ! $premium || null !== $pause ) {
			return false;
		}
		return 'monthly' === (string) $sub->plan
			&& 'premium' === strtolower( (string) $sub->access_state )
			&& empty( $sub->pending_yearly_on )
			&& ! empty( $sub->period_ends_on );
	}

	/**
	 * The member's pause that is scheduled or running today, or null.
	 *
	 * @param int $member_id Member number.
	 */
	public static function open( $member_id ) {
		$member_id = (int) $member_id;
		if ( array_key_exists( $member_id, self::$memo ) ) {
			return self::$memo[ $member_id ];
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Stage3_Schema::table( 'pauses' ) . " WHERE member_id = %d AND status IN ('scheduled', 'active') ORDER BY id DESC LIMIT 1", $member_id ) );
		if ( $row ) {
			$now = self::status_on( (string) $row->status, (string) $row->starts_at, (string) $row->ends_at, LLD_Members_Programme::local_today( $member_id ) );
			if ( $now !== (string) $row->status ) {
				$wpdb->update( LLD_Members_Stage3_Schema::table( 'pauses' ), array( 'status' => $now, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row->id ) );
				$row->status = $now;
			}
			if ( ! in_array( $now, array( 'scheduled', 'active' ), true ) ) {
				$row = null;
			}
		}
		self::$memo[ $member_id ] = $row ? $row : null;
		return self::$memo[ $member_id ];
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function paused_now( $member_id ) {
		$row = self::open( $member_id );
		return $row && 'active' === (string) $row->status;
	}

	/**
	 * Filter on Stage 2 paid_open(): during a pause the member has Free access (TC-FR3-65-AS-02). The stored
	 * access state is untouched, so Premium returns by itself when the pause ends (AS-03).
	 *
	 * @param bool $open      Stage 2 answer.
	 * @param int  $member_id Member number.
	 */
	public static function paid_open( $open, $member_id ) {
		if ( ! $open || (int) $member_id < 1 || ! self::on( $member_id ) ) {
			return $open;
		}
		return ! self::paused_now( $member_id );
	}

	/**
	 * Schedule a pause from the end of the paid month.
	 *
	 * @param int $member_id Member number.
	 * @param int $months    1, 2 or 3.
	 * @return true|WP_Error
	 */
	public static function start( $member_id, $months ) {
		$sub = LLD_Members_Billing::subscription_for( $member_id );
		if ( ! self::may_pause( $sub, LLD_Members_Billing::has_premium( $member_id ), self::open( $member_id ) ) ) {
			return new WP_Error( 'lld_pause', 'A pause is for monthly Premium plans that are not already paused or cancelling.' );
		}
		$dates = self::dates( (string) $sub->period_ends_on, (int) $months );
		if ( ! $dates ) {
			return new WP_Error( 'lld_pause', 'Choose 1, 2 or 3 months.' );
		}
		$ref = (string) $sub->stripe_subscription;
		if ( LLD_Members_Stripe_API::on( $member_id ) && 0 === strpos( $ref, 'sub_' ) ) {
			$done = LLD_Members_Stripe_API::pause( $ref, (int) $months );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			LLD_Members_Stage3_Schema::table( 'pauses' ),
			array(
				'member_id'        => (int) $member_id,
				'subscription_ref' => '' !== $ref ? $ref : 'member:' . (int) $member_id,
				'months'           => (int) $months,
				'starts_at'        => $dates['starts_at'],
				'ends_at'          => $dates['ends_at'],
				'status'           => 'scheduled',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		unset( self::$memo[ (int) $member_id ] );
		do_action( 'lld_members_event', 'paused', (int) $member_id, array( 'months' => (int) $months ) );
		return true;
	}

	/**
	 * Undo a pause that has not started, or stop a running one at the member's request (cancelling Premium).
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function undo( $member_id ) {
		$row = self::open( $member_id );
		if ( ! $row ) {
			return true;
		}
		if ( 'scheduled' === (string) $row->status && LLD_Members_Stripe_API::on( $member_id ) && 0 === strpos( (string) $row->subscription_ref, 'sub_' ) ) {
			$done = LLD_Members_Stripe_API::unpause( (string) $row->subscription_ref );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		if ( 'scheduled' !== (string) $row->status ) {
			// A running pause keeps its dates: the member stays Free until it ends.
			return true;
		}
		global $wpdb;
		$wpdb->update( LLD_Members_Stage3_Schema::table( 'pauses' ), array( 'status' => 'cancelled', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row->id ) );
		unset( self::$memo[ (int) $member_id ] );
		return true;
	}

	/**
	 * My subscription form actions: pause_1, pause_2, pause_3 and pause_undo. Cancelling Premium also drops a pause
	 * that has not started, since there is nothing left to pause.
	 *
	 * @param string $action    Posted action.
	 * @param int    $member_id Member number.
	 */
	public static function post( $action, $member_id ) {
		if ( ! self::on( $member_id ) ) {
			return;
		}
		if ( preg_match( '/^pause_([123])$/', (string) $action, $m ) ) {
			self::$failed = is_wp_error( self::start( $member_id, (int) $m[1] ) );
		} elseif ( 'pause_undo' === $action || 'cancel' === $action ) {
			self::$failed = is_wp_error( self::undo( $member_id ) );
		}
	}

	/** @var bool The last pause action failed (shown on My subscription). */
	public static $failed = false;

	/**
	 * The pause part of My subscription: the choice, or the pause already set.
	 *
	 * @param int         $member_id Member number.
	 * @param object|null $sub       Stage 2 subscription row.
	 */
	public static function section( $member_id, $sub ) {
		if ( ! self::on( $member_id ) || ! $sub ) {
			return '';
		}
		$html = self::$failed ? '<div class="lld-fail" role="alert"><p>Your pause was not saved. ' . esc_html( LLD_Members_Stripe::try_again_message() ) . '</p></div>' : '';
		$row  = self::open( $member_id );
		if ( $row ) {
			$html .= '<div class="lld-card" data-lld-pause="' . esc_attr( (string) $row->status ) . '"><h2>Pause</h2>';
			if ( 'scheduled' === (string) $row->status ) {
				$html .= '<p>' . esc_html( 'Premium continues to ' . self::day( (string) $row->starts_at ) . '. Your plan is then paused for ' . self::months_text( (int) $row->months ) . ', with no charge.' ) . '</p>';
			} else {
				$html .= '<p>' . esc_html( 'Your plan is paused until ' . self::day( (string) $row->ends_at ) . '. You have Free access until then, and all your data is kept.' ) . '</p>';
			}
			$html .= '<p data-lld-pause-next="' . esc_attr( (string) $row->ends_at ) . '">' . esc_html( 'Premium returns and your next payment is on ' . self::day( (string) $row->ends_at ) . ', at your current price.' ) . '</p>';
			if ( 'scheduled' === (string) $row->status ) {
				$html .= '<form method="post">' . wp_nonce_field( 'lld_sub', 'lld_sub_nonce', true, false ) . '<button class="lld-button lld-button-secondary" type="submit" name="lld_sub_action" value="pause_undo">Keep Premium without a pause</button></form>';
			}
			return $html . '</div>';
		}
		if ( ! self::may_pause( $sub, LLD_Members_Billing::has_premium( $member_id ), null ) ) {
			return $html;
		}
		return $html . '<div class="lld-card" data-lld-pause="offer"><h2>Need a break?</h2>' . self::choice( $sub ) . '</div>';
	}

	/**
	 * TC-FR3-65-AS-06: the cancellation step offers a pause before the final cancel button. Yearly: nothing (AS-05).
	 *
	 * @param string      $html      Earlier offers.
	 * @param int         $member_id Member number.
	 * @param object|null $sub       Stage 2 subscription row.
	 */
	public static function cancel_offer( $html, $member_id, $sub ) {
		if ( ! self::on( $member_id ) || ! self::may_pause( $sub, LLD_Members_Billing::has_premium( $member_id ), self::open( $member_id ) ) ) {
			return $html;
		}
		return $html . '<div data-lld-pause="cancel-offer"><h3>Pause instead?</h3>' . self::choice( $sub, false ) . '</div>';
	}

	/**
	 * @param object $sub  Stage 2 subscription row.
	 * @param bool   $form Wrap in its own form (the cancel step is already inside one).
	 */
	private static function choice( $sub, $form = true ) {
		$html = '<p>' . esc_html( 'Pause your monthly plan for 1, 2 or 3 months instead of cancelling. Premium continues to ' . self::day( (string) $sub->period_ends_on ) . ', then there is no charge while paused, you have Free access, and all your data is kept. Premium returns by itself at your current price.' ) . '</p><p class="lld-row">';
		foreach ( self::MONTHS as $months ) {
			$html .= '<button class="lld-button lld-button-secondary" type="submit" name="lld_sub_action" value="pause_' . $months . '">' . esc_html( 'Pause for ' . self::months_text( $months ) ) . '</button> ';
		}
		$html .= '</p>';
		return $form ? '<form method="post">' . wp_nonce_field( 'lld_sub', 'lld_sub_nonce', true, false ) . $html . '</form>' : $html;
	}

	/**
	 * @param int $months Months.
	 */
	private static function months_text( $months ) {
		return 1 === (int) $months ? '1 month' : (int) $months . ' months';
	}

	/**
	 * 10 March 2027.
	 *
	 * @param string $date Y-m-d.
	 */
	public static function day( $date ) {
		$ts = strtotime( $date . ' 00:00:00 UTC' );
		return false === $ts ? $date : gmdate( 'j F Y', $ts );
	}
}
