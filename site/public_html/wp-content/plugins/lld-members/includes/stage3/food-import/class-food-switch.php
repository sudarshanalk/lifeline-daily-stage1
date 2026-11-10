<?php
/**
 * Feature switch for the food database: the Stage 3 `food_tracker` switch (Build Method Stage 3 §5).
 *
 * Reads through LLD_Members_Features, so the owner turns it with
 * `wp lld-members feature food_tracker test_accounts|everyone`. This class never writes the option;
 * a missing option stays off.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the food_tracker switch. Import and search do nothing while it is off.
 */
class LLD_Members_Food_Switch {

	const FEATURE = 'food_tracker';

	/**
	 * off, test_accounts or everyone. Anything else, including a missing option, is off.
	 */
	public static function state() {
		if ( ! class_exists( 'LLD_Members_Features' ) || ! function_exists( 'get_option' ) ) {
			return 'off';
		}
		return LLD_Members_Features::state( self::FEATURE );
	}

	/**
	 * Import and search run only for test accounts or everyone. Who may see the results is decided
	 * per member by LLD_Members_Features::enabled() where the food tracker is shown (P3-41).
	 */
	public static function enabled() {
		return in_array( self::state(), array( 'test_accounts', 'everyone' ), true );
	}
}
