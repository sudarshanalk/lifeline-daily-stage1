<?php
/**
 * Feature switch for the food database. Off unless the option says otherwise.
 *
 * Stored in wp_options under lld_feature_switches, as the data model describes.
 * This class never writes the option, so a missing option stays off.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the food_database switch. Import and search do nothing while it is off.
 */
class LLD_Members_Food_Switch {

	const FEATURE = 'food_database';

	/**
	 * off, test_accounts or everyone. Anything else, including a missing option, is off.
	 */
	public static function state() {
		if ( ! function_exists( 'get_option' ) ) {
			return 'off';
		}
		$all = get_option( 'lld_feature_switches', array() );
		if ( ! is_array( $all ) || ! isset( $all[ self::FEATURE ] ) || ! is_array( $all[ self::FEATURE ] ) ) {
			return 'off';
		}
		$state = (string) ( $all[ self::FEATURE ]['state'] ?? 'off' );
		if ( ! in_array( $state, array( 'off', 'test_accounts', 'everyone' ), true ) ) {
			return 'off';
		}
		return $state;
	}

	/**
	 * Import and search run only for test accounts or everyone.
	 */
	public static function enabled() {
		return in_array( self::state(), array( 'test_accounts', 'everyone' ), true );
	}
}
