<?php
/**
 * Test clock. Sets "today" for one member. Off on production, and cannot be switched on there.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UTC clock with an optional per-member freeze.
 */
class LLD_Members_Clock {

	/**
	 * Now, in UTC. A frozen time is used only when the test clock is allowed.
	 *
	 * @param int $member_id Member number.
	 */
	public static function now( $member_id = 0 ) {
		$real = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		if ( ! lld_members_test_clock_allowed() || $member_id < 1 ) {
			return $real;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'test_clock' );
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT frozen_at FROM {$table} WHERE member_id = %d", $member_id ) );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $real;
		}
		$frozen = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw, new DateTimeZone( 'UTC' ) );
		return $frozen instanceof DateTimeImmutable ? $frozen : $real;
	}

	/**
	 * Freeze one member's clock. Refused on production.
	 *
	 * @param int    $member_id Member number.
	 * @param string $utc       MySQL datetime in UTC.
	 */
	public static function set( $member_id, $utc ) {
		if ( ! lld_members_test_clock_allowed() ) {
			return new WP_Error( 'lld_clock', 'The test clock is off.' );
		}
		$when = DateTimeImmutable::createFromFormat( DateTimeInterface::ATOM, $utc )
			?: DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $utc, new DateTimeZone( 'UTC' ) );
		if ( ! $when ) {
			return new WP_Error( 'lld_clock', 'The time is not a UTC datetime.' );
		}
		global $wpdb;
		$wpdb->replace(
			LLD_Members_Schema::table( 'test_clock' ),
			array(
				'member_id' => (int) $member_id,
				'frozen_at' => $when->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s' )
		);
		return true;
	}

	/**
	 * Return one member to the real time.
	 *
	 * @param int $member_id Member number.
	 */
	public static function clear( $member_id ) {
		global $wpdb;
		$wpdb->delete( LLD_Members_Schema::table( 'test_clock' ), array( 'member_id' => (int) $member_id ), array( '%d' ) );
	}
}
