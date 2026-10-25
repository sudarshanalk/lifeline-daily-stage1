<?php
/**
 * Environment helpers. Settings come from wp-config.php, never hard-coded.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Environment name: ci, local, staging or production. Anything else is treated as production.
 */
function lld_members_env() {
	$env = defined( 'LLD_ENV' ) ? (string) LLD_ENV : 'production';
	$allowed = array( 'ci', 'local', 'staging', 'production' );
	return in_array( $env, $allowed, true ) ? $env : 'production';
}

/**
 * Production is the live site. The test clock cannot be switched on here.
 */
function lld_members_is_production() {
	return 'production' === lld_members_env();
}

/**
 * Whether the test clock may move "today" for a member.
 */
function lld_members_test_clock_allowed() {
	if ( lld_members_is_production() ) {
		return false;
	}
	if ( ! defined( 'LLD_TEST_CLOCK' ) ) {
		return false;
	}
	$value = LLD_TEST_CLOCK;
	return true === $value || 1 === $value || '1' === $value || 'true' === $value;
}

/**
 * Folder outside the web root for the encryption key and photos.
 */
function lld_members_private_dir() {
	$base = defined( 'LLD_PRIVATE_DIR' ) ? (string) LLD_PRIVATE_DIR : dirname( ABSPATH ) . '/lld-private';
	return rtrim( $base, '/\\' );
}

/**
 * A request from this machine. Used for test helpers, which never run in production.
 */
function lld_members_is_loopback() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return '127.0.0.1' === $ip || '::1' === $ip;
}

/**
 * Current time in UTC as a MySQL datetime, or a member's test-clock time.
 *
 * @param int $member_id Member number, or 0 for the real time.
 */
function lld_members_now_mysql( $member_id = 0 ) {
	return LLD_Members_Clock::now( (int) $member_id )->format( 'Y-m-d H:i:s' );
}

/**
 * Today's local calendar date for a member, using their time zone and test clock.
 *
 * @param int    $member_id Member number.
 * @param string $timezone  IANA time zone.
 */
function lld_members_local_date( $member_id, $timezone ) {
	try {
		$zone = new DateTimeZone( $timezone ? $timezone : 'UTC' );
	} catch ( Exception $e ) {
		$zone = new DateTimeZone( 'UTC' );
	}
	return LLD_Members_Clock::now( (int) $member_id )->setTimezone( $zone )->format( 'Y-m-d' );
}
