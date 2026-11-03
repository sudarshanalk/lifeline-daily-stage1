<?php
/**
 * Feature switches (NFR3-06; data model: Feature switch). Each Stage 3 feature is off, visible to test
 * accounts only, or on for everyone. Default off, so Stage 2 screens stay exactly as they are until the
 * owner switches a feature on.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switch states and the catalogue of features.
 */
class LLD_Members_Features {

	const OPTION        = 'lld_feature_switches';
	// The owner retired the switches on 3 Nov 2026 (the whole system launches together): with this option set, every
	// catalogue feature is on for everyone, including features added later. CI leaves it unset, so the Stage 2 suite
	// still runs against the switch states that tests/ci/stage2-wp.sh sets.
	const ALL_ON        = 'lld_features_all_on';
	const TEST_ACCOUNTS = 'lld_test_account_emails';
	const STATES        = array( 'off', 'test_accounts', 'everyone' );

	public static function boot() {
		// A test account is an ordinary member whose email the owner listed (staging), or the owner admin.
		// Nothing else is hooked here: each feature's own class checks enabled() before it does anything.
	}

	/**
	 * Every Stage 3 feature, with the package that builds it. A package adds its feature here in the same
	 * pull request. Unknown names are always off.
	 *
	 * @return array<string,string>
	 */
	public static function catalog() {
		return array(
			'api'              => 'P3-14 members\' programming interface for Stage 3 data',
			'sign_in_social'   => 'P3-20 Google and Apple sign-in',
			'sign_in_code'     => 'P3-21 email codes for sign-up and sign-in',
			'account_hub'      => 'P3-30 account hub, desktop menu, empty screens',
			'goals'            => 'P3-31 goal setting, starting measurements, personal plan',
			'today'            => 'P3-32 Today screen, goal progress, weekly check-in',
			'progress'         => 'P3-33 progress page and member dashboard',
			'insights'         => 'P3-34 decision engine',
			'food_tracker'     => 'P3-40 to P3-43 food database, logging, targets, recipes',
			'barcode'          => 'P3-44 barcode scanning in the browser',
			'health_logging'   => 'P3-50, P3-51 health logging and urgent-care warnings',
			'calculators_new'  => 'P3-55 to P3-5B new calculators and saved results',
			'prices_launch'    => 'P3-60 launch prices',
			'stripe_api'       => 'D2 real Stripe checkout, cancellation and refunds (Managed Payments)',
			'access_tiers'     => 'P3-61 Free and Premium access, downgrade rules',
			'membership_card'  => 'P3-62 membership card',
			'pause'            => 'P3-63 pause subscription',
			'analytics'        => 'P3-70 Google Analytics 4 on public pages',
			'cookie_banner'    => 'P3-71 cookie banner',
			'weekly_report'    => 'P3-72 weekly owner report',
			'reminders'        => 'P3-85 reminders, shareable summary, add to home screen',
			'consent_new'      => 'P3-86 health consent for new data, download and deletion',
			'content_new'      => 'P3-73 to P3-77: new tracks, challenges and wording',
		);
	}

	/**
	 * Stored state of one feature: off, test_accounts or everyone.
	 *
	 * @param string $feature Feature name from catalog().
	 */
	public static function state( $feature ) {
		if ( get_option( self::ALL_ON ) && array_key_exists( $feature, self::catalog() ) ) {
			return 'everyone';
		}
		$all = get_option( self::OPTION );
		if ( ! is_array( $all ) || ! isset( $all[ $feature ] ) || ! is_array( $all[ $feature ] ) ) {
			return 'off';
		}
		$state = isset( $all[ $feature ]['state'] ) ? (string) $all[ $feature ]['state'] : 'off';
		return in_array( $state, self::STATES, true ) ? $state : 'off';
	}

	/**
	 * True when this feature may show for this member (or visitor, member_id 0).
	 *
	 * @param string $feature   Feature name from catalog().
	 * @param int    $member_id Member number; 0 for the current request's member.
	 */
	public static function enabled( $feature, $member_id = 0 ) {
		if ( ! array_key_exists( $feature, self::catalog() ) ) {
			return false;
		}
		$state = self::state( $feature );
		if ( 'everyone' === $state ) {
			return true;
		}
		if ( 'off' === $state ) {
			return false;
		}
		return self::is_test_account( $member_id );
	}

	/**
	 * Test accounts: the owner admin, and members whose email is in the owner's list.
	 *
	 * @param int $member_id Member number; 0 for the signed-in member.
	 */
	public static function is_test_account( $member_id = 0 ) {
		if ( $member_id < 1 && class_exists( 'LLD_Members_Access' ) ) {
			$member_id = LLD_Members_Access::own_member_id();
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( $member_id < 1 ) {
			return false;
		}
		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! is_string( $email ) || '' === $email ) {
			return false;
		}
		return in_array( strtolower( $email ), self::test_account_emails(), true );
	}

	/**
	 * @return string[] Lower-case emails.
	 */
	public static function test_account_emails() {
		$list = get_option( self::TEST_ACCOUNTS );
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,]+/', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( '' !== $email ) {
				$out[] = $email;
			}
		}
		return $out;
	}

	/**
	 * Change a switch. "everyone" records who approved it (P3-92).
	 *
	 * @param string $feature     Feature name from catalog().
	 * @param string $state       off, test_accounts or everyone.
	 * @param string $approved_by Who approved "everyone".
	 * @return true|WP_Error
	 */
	public static function set( $feature, $state, $approved_by = '' ) {
		if ( ! array_key_exists( $feature, self::catalog() ) ) {
			return new WP_Error( 'lld_feature_unknown', 'Unknown feature: ' . $feature );
		}
		if ( ! in_array( $state, self::STATES, true ) ) {
			return new WP_Error( 'lld_feature_state', 'State must be off, test_accounts or everyone.' );
		}
		if ( 'everyone' === $state && '' === trim( (string) $approved_by ) ) {
			return new WP_Error( 'lld_feature_approval', 'Switching a feature on for everyone needs --approved-by (the owner\'s approval).' );
		}
		$all = get_option( self::OPTION );
		$all = is_array( $all ) ? $all : array();
		$all[ $feature ] = array(
			'state'       => $state,
			'approved_by' => 'everyone' === $state ? sanitize_text_field( (string) $approved_by ) : '',
			'changed_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::OPTION, $all, true );
		return true;
	}

	/**
	 * Every feature with its state, for the CLI and the owner's admin screen.
	 *
	 * @return array<string,array{state:string,approved_by:string,changed_at:string,what:string}>
	 */
	public static function all() {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::catalog() as $feature => $what ) {
			$row = isset( $stored[ $feature ] ) && is_array( $stored[ $feature ] ) ? $stored[ $feature ] : array();
			$out[ $feature ] = array(
				'state'       => self::state( $feature ),
				'approved_by' => isset( $row['approved_by'] ) ? (string) $row['approved_by'] : '',
				'changed_at'  => isset( $row['changed_at'] ) ? (string) $row['changed_at'] : '',
				'what'        => $what,
			);
		}
		return $out;
	}
}
