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
		return is_string( $state ) && '' !== $state ? $state : 'free';
	}
}
