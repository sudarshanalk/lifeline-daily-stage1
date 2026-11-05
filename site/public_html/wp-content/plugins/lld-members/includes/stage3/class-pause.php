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
		add_filter( 'lld_members_can_switch_yearly', array( __CLASS__, 'can_switch_yearly' ), 20, 2 );
		add_action( 'lld_members_subscription_post', array( __CLASS__, 'post' ), 10, 2 );
		add_action( 'lld_members_daily', array( __CLASS__, 'daily_mail' ) );
		add_action( 'lld_members_event', array( __CLASS__, 'on_event' ), 10, 3 );
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
			&& 'past_due' !== (string) ( $sub->stripe_state ?? '' )
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
		$sub = LLD_Members_Billing::subscription_for( $member_id );
		if ( self::paused_now( $member_id ) && self::same_subscription( self::open( $member_id ), $sub ) ) {
			return false;
		}
		// Cancelled during a pause: Premium does not come back when the pause ends. The paid period Stripe moved on
		// while collection was paused (the voided month) is not paid for, so it opens nothing.
		$last = ( $sub && 'cancelled' === strtolower( (string) $sub->access_state ) ) ? self::latest( $member_id ) : null;
		if ( $last && self::same_subscription( $last, $sub )
			&& LLD_Members_Programme::local_today( $member_id ) >= (string) $last->starts_at
			&& (string) $sub->period_ends_on <= (string) $last->ends_at ) {
			return false;
		}
		return $open;
	}

	/**
	 * A pause belongs to the subscription it was made for. A later subscription (bought after the old one ended)
	 * is never closed by an old pause.
	 *
	 * @param object|null $pause Pause row.
	 * @param object|null $sub   Stage 2 subscription row.
	 */
	public static function same_subscription( $pause, $sub ) {
		if ( ! $pause || ! $sub ) {
			return false;
		}
		$ref = (string) $sub->stripe_subscription;
		return (string) $pause->subscription_ref === ( '' !== $ref ? $ref : 'member:' . (int) $pause->member_id );
	}

	/**
	 * The member's most recent pause that was not undone, whatever its dates.
	 *
	 * @param int $member_id Member number.
	 */
	public static function latest( $member_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Stage3_Schema::table( 'pauses' ) . " WHERE member_id = %d AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", (int) $member_id ) );
		return $row ? $row : null;
	}

	/**
	 * Filter: no switch to yearly while a pause is scheduled or running; the voided renewal would never apply it.
	 *
	 * @param bool $allowed   Earlier answer.
	 * @param int  $member_id Member number.
	 */
	public static function can_switch_yearly( $allowed, $member_id ) {
		if ( ! $allowed || ! self::on( $member_id ) ) {
			return $allowed;
		}
		return null === self::open( $member_id );
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
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// The row first, so Stripe is never paused without a record here; if Stripe refuses, the row is undone.
		$saved = $wpdb->insert(
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
		if ( ! $saved ) {
			return new WP_Error( 'lld_pause', 'The pause could not be saved.' );
		}
		unset( self::$memo[ (int) $member_id ] );
		if ( LLD_Members_Stripe_API::on( $member_id ) && 0 === strpos( $ref, 'sub_' ) ) {
			$done = LLD_Members_Stripe_API::pause( $ref, (int) $months );
			if ( is_wp_error( $done ) ) {
				$wpdb->update( LLD_Members_Stage3_Schema::table( 'pauses' ), array( 'status' => 'cancelled', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $wpdb->insert_id ) );
				return $done;
			}
		}
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
		if ( self::on( $member_id ) && self::paused_now( $member_id ) ) {
			$row = self::open( $member_id );
			return $html . '<p data-lld-pause="cancel-while-paused">' . esc_html( 'Your plan is paused until ' . self::day( (string) $row->ends_at ) . '. If you cancel, you keep Free access, Premium does not come back and you are not charged again.' ) . '</p>';
		}
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
	 * Daily: EM-31 on the day the pause starts and EM-32 seven days before it ends (FR3-65), once each. A pause
	 * that was undone, or a plan that was cancelled, gets neither: Premium is not coming back.
	 */
	public static function daily_mail() {
		global $wpdb;
		$soon = gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Stage3_Schema::table( 'pauses' ) . " WHERE status IN ('scheduled', 'active') AND starts_at <= %s", $soon ) );
		foreach ( (array) $rows as $pause ) {
			$member_id = (int) $pause->member_id;
			if ( ! self::on( $member_id ) ) {
				continue;
			}
			$sub = LLD_Members_Billing::subscription_for( $member_id );
			if ( ! self::same_subscription( $pause, $sub ) || 'cancelled' === strtolower( (string) $sub->access_state ) ) {
				continue;
			}
			$today = LLD_Members_Programme::local_today( $member_id );
			if ( $today < (string) $pause->starts_at || $today >= (string) $pause->ends_at ) {
				continue;
			}
			self::send( $pause, 'started', $sub );
			if ( $today >= gmdate( 'Y-m-d', (int) strtotime( (string) $pause->ends_at . ' 00:00:00 UTC' ) - 7 * DAY_IN_SECONDS ) ) {
				self::send( $pause, 'ending', $sub );
			}
		}
	}

	/**
	 * EM-33 when the first payment after a pause goes through (the Stage 2 'renewed' event). If that payment
	 * fails, the Stage 2 failed-payment email is sent instead (TC-FR3-65-AS-04).
	 *
	 * @param string $event     Event name.
	 * @param int    $member_id Member number.
	 * @param array  $data      Event data.
	 */
	public static function on_event( $event, $member_id, $data = array() ) {
		if ( 'renewed' !== $event || ! self::on( $member_id ) ) {
			return;
		}
		$pause = self::latest( $member_id );
		$sub   = LLD_Members_Billing::subscription_for( $member_id );
		if ( ! $pause || ! self::same_subscription( $pause, $sub ) ) {
			return;
		}
		$today = LLD_Members_Programme::local_today( $member_id );
		$late  = gmdate( 'Y-m-d', (int) strtotime( (string) $pause->ends_at . ' 00:00:00 UTC' ) + 7 * DAY_IN_SECONDS );
		if ( $today >= (string) $pause->ends_at && $today <= $late ) {
			self::send( $pause, 'ended', $sub );
		}
	}

	/**
	 * The approved wording of EM-31, EM-32 and EM-33 (02 Content/Programme content/emails/stage3-emails.md,
	 * approved 3 Nov 2026). The amount is the member's own monthly price (EM-Q6, default a).
	 *
	 * @param string $kind       started, ending or ended.
	 * @param string $first_name Member's first name.
	 * @param object $pause      Pause row.
	 * @param string $amount     The member's monthly price, formatted.
	 * @return array{subject:string,body:string}
	 */
	public static function mail_copy( $kind, $first_name, $pause, $amount ) {
		$hi  = 'Hi ' . ( '' !== trim( $first_name ) ? trim( $first_name ) : 'there' ) . ',';
		$end = self::day( (string) $pause->ends_at );
		$sub = home_url( '/members/subscription/' );
		if ( 'started' === $kind ) {
			return array(
				'subject' => 'Your Premium pause has started',
				'body'    => $hi . "\n\nYour Premium membership is paused from today until " . $end . ". There's no charge while you're paused, and you have Free access in the meantime."
					. "\n\nNothing is deleted. Your logs, saved results, custom foods and recipes are all kept, and everything comes back as it was when Premium returns."
					. "\n\nPremium returns automatically on " . $end . ' at your current price of ' . $amount . ' a month, tax included. You can end the pause early in My subscription.'
					. "\n\nManage my subscription: " . $sub,
			);
		}
		if ( 'ending' === $kind ) {
			return array(
				'subject' => 'Your Premium returns on ' . $end,
				'body'    => $hi . "\n\nYour pause ends on " . $end . '. Premium returns automatically that day, and your card will be charged ' . $amount . ' a month, tax included, at your current price.'
					. "\n\nIf you'd like to continue, there's nothing to do. If you'd rather not, you can cancel in My subscription before " . $end . '.'
					. "\n\nManage my subscription: " . $sub,
			);
		}
		return array(
			'subject' => 'Welcome back to Premium',
			'body'    => $hi . "\n\nYour pause has ended and Premium is back. Your full plan, insights, meal plans, workouts, reminders and history are all available again, exactly as you left them."
				. "\n\nYour receipt for today's payment is in a separate email."
				. "\n\nGo to my dashboard: " . home_url( '/members/home/' ),
		);
	}

	/**
	 * Send one pause email, once per pause and kind (claimed in the Stage 2 mail_sent table).
	 *
	 * @param object      $pause Pause row.
	 * @param string      $kind  started, ending or ended.
	 * @param object|null $sub   Stage 2 subscription row.
	 */
	private static function send( $pause, $kind, $sub ) {
		global $wpdb;
		$member_id = (int) $pause->member_id;
		$member    = $wpdb->get_row( $wpdb->prepare( 'SELECT email, first_name FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! $member || ! is_email( (string) $member->email ) ) {
			return false;
		}
		$claimed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . LLD_Members_Schema::table( 'mail_sent' ) . ' (member_id, claim_key, sent_on) VALUES (%d, %s, %s)', $member_id, 'pause:' . (int) $pause->id . ':' . $kind, gmdate( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( (int) $claimed < 1 ) {
			return false;
		}
		$prices = LLD_Members_Billing::shown_prices();
		$cents  = $sub && $sub->locked_amount ? (int) $sub->locked_amount : (int) $prices['monthly'];
		$cur    = $sub && $sub->locked_currency ? (string) $sub->locked_currency : 'usd';
		$copy   = self::mail_copy( $kind, (string) $member->first_name, $pause, LLD_Members_Membership::money( $cents, $cur ) );
		return wp_mail( (string) $member->email, $copy['subject'], $copy['body'] . "\n" );
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
