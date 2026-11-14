<?php
/**
 * Members only ever see their own data (Data Model S1, NFR2-02).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access checks used by every member route.
 */
class LLD_Members_Access {

	/**
	 * Member routes a signed-in member can open only for their own id.
	 * The access test requests every one of these for another member.
	 *
	 * @return array<int,array{id:string,method:string,path:string}>
	 */
	public static function catalog() {
		return array(
			array(
				'id'     => 'account',
				'method' => 'GET',
				'path'   => '/wp-json/lld-members/v1/members/{id}',
			),
			array(
				'id'     => 'journey',
				'method' => 'GET',
				'path'   => '/wp-json/lld-members/v1/members/{id}/journey',
			),
			array(
				'id'     => 'page',
				'method' => 'GET',
				'path'   => '/members/record/{id}/',
			),
			array(
				'id'     => 'photo',
				'method' => 'GET',
				'path'   => '/members/photo/{id}/',
			),
		);
	}

	/**
	 * Member number for the signed-in user, or 0.
	 */
	public static function current_member_id() {
		if ( 'visitor' === self::preview_state() ) {
			return 0;
		}
		return self::own_member_id();
	}

	/**
	 * Member number of the signed-in account itself, ignoring preview mode.
	 */
	public static function own_member_id() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user_id ) );
	}

	/**
	 * Owner preview cookie. Empty unless an administrator set it.
	 */
	public static function preview_state() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$state = isset( $_COOKIE['lld_preview'] ) ? sanitize_key( wp_unslash( $_COOKIE['lld_preview'] ) ) : '';
		return in_array( $state, array( 'visitor', 'free', 'premium' ), true ) ? $state : '';
	}

	/**
	 * True when this request may read that member's data.
	 * A normal member may read only their own. The owner admin may read a member when giving support.
	 *
	 * @param int $member_id Member number in the address.
	 */
	public static function can_read( $member_id ) {
		$current = self::current_member_id();
		if ( $current && $current === (int) $member_id ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Latest health-consent line is "given".
	 *
	 * @param int $member_id Member number.
	 */
	public static function has_health_consent( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'consent' );
		$kind  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT change_kind FROM {$table} WHERE member_id = %d AND consent_type = %s ORDER BY id DESC LIMIT 1",
				$member_id,
				'health'
			)
		);
		return 'given' === $kind;
	}

	/**
	 * Access state stored from Stripe's last confirmed state. This never calls Stripe.
	 * Owner preview changes only the owner's own record, never another member's (NFR2-08).
	 *
	 * @param int $member_id Member number.
	 */
	public static function access_state( $member_id ) {
		$preview = self::preview_state();
		if ( '' !== $preview && (int) $member_id === self::own_member_id() ) {
			if ( 'free' === $preview ) {
				return 'free';
			}
			if ( 'premium' === $preview ) {
				return 'Premium';
			}
		}
		global $wpdb;
		$state = $wpdb->get_var( $wpdb->prepare( 'SELECT access_state FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
		$state = is_string( $state ) && '' !== $state ? $state : 'free';
		// Stage 3 (P3-87, FR3-80): the Owner's free Premium grant gives Premium wherever the access state is read.
		return (string) apply_filters( 'lld_members_access_state', $state, (int) $member_id );
	}

	/**
	 * P3-36 A1 (TC-FR3-64-AS-04): is this member Premium, and why. The one answer the chip, Account → Membership and
	 * My subscription all use, so they never disagree.
	 *
	 * For display only, never an access check: 'premium' is true for a paused member, whose Premium features are
	 * closed. To decide access, use LLD_Members_Billing::has_premium() (or LLD_Members_Access_Tiers::allows()).
	 *
	 * source: 'free'; 'paid' (a Premium subscription, or cancelled within its paid period); 'paused' (paid, with a
	 * Stage 3 pause running: still a Premium member); 'grant' (free Premium given by the Owner, with no paid
	 * subscription behind it); 'preview' (the owner's own preview). until: the grant's end date as the Owner set it
	 * (Y-m-d; Premium runs until that date), '' for no end date or another source.
	 *
	 * @param int $member_id Member number.
	 * @return array{premium:bool,source:string,until:string}
	 */
	public static function premium_status( $member_id ) {
		$member_id = (int) $member_id;
		$free      = array(
			'premium' => false,
			'source'  => 'free',
			'until'   => '',
		);
		if ( $member_id < 1 ) {
			return $free;
		}
		$preview = self::preview_state();
		if ( '' !== $preview && $member_id === self::own_member_id() && 'visitor' !== $preview ) {
			return 'premium' === $preview ? array(
				'premium' => true,
				'source'  => 'preview',
				'until'   => '',
			) : $free;
		}
		$open = class_exists( 'LLD_Members_Billing' ) ? LLD_Members_Billing::has_premium( $member_id ) : 'premium' === strtolower( self::access_state( $member_id ) );
		if ( ! $open ) {
			// A running pause keeps a paid member Premium, as long as the paid Premium itself has not ended or been
			// refunded (the same test as the membership card's).
			$pause = class_exists( 'LLD_Members_Pause' ) ? LLD_Members_Pause::open( $member_id ) : null;
			if ( $pause && 'active' === (string) $pause->status && self::stored_paid( $member_id ) ) {
				return array(
					'premium' => true,
					'source'  => 'paused',
					'until'   => '',
				);
			}
			return $free;
		}
		$grant = class_exists( 'LLD_Members_Admin_Members' ) ? LLD_Members_Admin_Members::active_grant( $member_id ) : null;
		if ( $grant ) {
			if ( ! self::stored_paid( $member_id ) ) {
				$until = substr( (string) $grant->ends_at, 0, 10 );
				return array(
					'premium' => true,
					'source'  => 'grant',
					'until'   => ( defined( 'LLD_Members_Admin_Members::NO_END' ) && $until >= LLD_Members_Admin_Members::NO_END ) ? '' : $until,
				);
			}
		}
		return array(
			'premium' => true,
			'source'  => 'paid',
			'until'   => '',
		);
	}

	/**
	 * The stored subscription is paid Premium: Premium, or cancelled within its paid period. Read from the stored row
	 * itself, not through the access-state filter, so the Owner's grant does not count here.
	 *
	 * @param int $member_id Member number.
	 */
	private static function stored_paid( $member_id ) {
		global $wpdb;
		$sub    = $wpdb->get_row( $wpdb->prepare( 'SELECT access_state, period_ends_on FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', (int) $member_id ) );
		$stored = $sub ? strtolower( (string) $sub->access_state ) : 'free';
		return 'premium' === $stored || ( 'cancelled' === $stored && $sub->period_ends_on && (string) $sub->period_ends_on >= gmdate( 'Y-m-d' ) );
	}

	/**
	 * P3-36 A1: the line members read for granted Premium: "Premium, given free until 14 December 2026."
	 *
	 * @param array{premium:bool,source:string,until:string} $status From premium_status().
	 */
	public static function grant_line( array $status ) {
		if ( '' === $status['until'] ) {
			return 'Your membership is Premium, given free with no end date.';
		}
		$ts = strtotime( $status['until'] . ' 00:00:00 UTC' );
		return 'Your membership is Premium, given free until ' . ( false === $ts ? $status['until'] : gmdate( 'j F Y', $ts ) ) . '.';
	}
}
