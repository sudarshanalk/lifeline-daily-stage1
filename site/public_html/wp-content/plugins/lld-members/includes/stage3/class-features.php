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
	// Each feature's change history (FR3-74): who, when, from, to and the note, from the admin panel and the command line.
	const HISTORY       = 'lld_feature_history';
	const HISTORY_KEEP  = 50;
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
			'calc_calorie_target' => 'P3-56 Calorie Target calculator (one tool; needs calculators_new on too)',
			'calc_goal_timeline'  => 'P3-56 Goal Timeline calculator (one tool; needs calculators_new on too)',
			'calc_heart_risk'     => 'P3-57 Heart Risk Check (one tool; needs calculators_new on too)',
			'calc_bp_check'       => 'P3-58 Blood Pressure Check (one tool; needs calculators_new on too)',
			'calc_metabolic'      => 'P3-59 Metabolic Health Check (one tool; needs calculators_new on too)',
			'calc_fitness_age'    => 'P3-5A Fitness Age (one tool; needs calculators_new on too)',
			'calc_hr_zones'       => 'P3-5A Heart Rate Zones (one tool; needs calculators_new on too)',
			'calc_sleep_stress'   => 'P3-5A Sleep and Stress Check (one tool; needs calculators_new on too)',
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
	 * The switch for one new calculator (P3-87, owner 5 Nov 2026): calc_ plus the engine slug, for example calc_heart_risk.
	 *
	 * @param string $tool Engine slug, for example heart-risk.
	 */
	public static function tool_feature( $tool ) {
		return 'calc_' . str_replace( '-', '_', sanitize_key( (string) $tool ) );
	}

	/**
	 * True when this new calculator may show: the calculators_new group is on for this member and so is the tool's own
	 * switch. A tool switch nobody has changed follows the group, so the group alone still decides as before.
	 *
	 * @param string $tool      Engine slug, for example heart-risk.
	 * @param int    $member_id Member number; 0 for the current request's member.
	 */
	public static function tool_enabled( $tool, $member_id = 0 ) {
		$feature = self::tool_feature( $tool );
		return self::enabled( 'calculators_new', $member_id ) && ( ! array_key_exists( $feature, self::catalog() ) || self::enabled( $feature, $member_id ) );
	}

	/**
	 * Stored state of one feature: off, test_accounts or everyone.
	 *
	 * @param string $feature Feature name from catalog().
	 */
	public static function state( $feature ) {
		$all = get_option( self::OPTION );
		// A calculator's own switch that was never changed follows the calculators_new group.
		if ( 0 === strpos( (string) $feature, 'calc_' ) && array_key_exists( $feature, self::catalog() ) && ( ! is_array( $all ) || ! isset( $all[ $feature ] ) || ! is_array( $all[ $feature ] ) ) ) {
			return self::state( 'calculators_new' );
		}
		// An emergency off (FR3-74, P3-87) holds even while every feature is on: set() marks it as an override.
		if ( get_option( self::ALL_ON ) && array_key_exists( $feature, self::catalog() ) ) {
			$row = is_array( $all ) && isset( $all[ $feature ] ) && is_array( $all[ $feature ] ) ? $all[ $feature ] : array();
			if ( empty( $row['override'] ) || ! in_array( (string) ( $row['state'] ?? '' ), array( 'off', 'test_accounts' ), true ) ) {
				return 'everyone';
			}
			return (string) $row['state'];
		}
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
		$own = $member_id < 1;
		if ( $own && class_exists( 'LLD_Members_Access' ) ) {
			$member_id = LLD_Members_Access::own_member_id();
		}
		// The Owner counts as a test account for their own requests. When a member number is named, that member decides,
		// so an Owner acting in the admin panel on an ordinary member never makes that member a test account (P3-87).
		if ( current_user_can( 'manage_options' ) && ( $own || ( class_exists( 'LLD_Members_Access' ) && (int) $member_id === (int) LLD_Members_Access::own_member_id() ) ) ) {
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
	 * Change a switch. "everyone" records who approved it (P3-92). Every change is added to the feature's history
	 * and the admin activity log, whether it came from the admin panel or the command line (FR3-74 AS-03, FR3-86).
	 * Off or test accounts is an override that holds while every feature is on.
	 *
	 * @param string $feature     Feature name from catalog().
	 * @param string $state       off, test_accounts or everyone.
	 * @param string $approved_by Who approved "everyone".
	 * @param string $note        Why (the reason or approval note).
	 * @return true|WP_Error
	 */
	public static function set( $feature, $state, $approved_by = '', $note = '' ) {
		if ( ! array_key_exists( $feature, self::catalog() ) ) {
			return new WP_Error( 'lld_feature_unknown', 'Unknown feature: ' . $feature );
		}
		if ( ! in_array( $state, self::STATES, true ) ) {
			return new WP_Error( 'lld_feature_state', 'State must be off, test_accounts or everyone.' );
		}
		if ( 'everyone' === $state && '' === trim( (string) $approved_by ) ) {
			return new WP_Error( 'lld_feature_approval', 'Switching a feature on for everyone needs --approved-by (the owner\'s approval).' );
		}
		$before = self::state( $feature );
		$all    = get_option( self::OPTION );
		$all    = is_array( $all ) ? $all : array();
		$all[ $feature ] = array(
			'state'       => $state,
			'approved_by' => 'everyone' === $state ? sanitize_text_field( (string) $approved_by ) : '',
			'changed_at'  => gmdate( 'Y-m-d H:i:s' ),
			'override'    => 'everyone' !== $state,
		);
		update_option( self::OPTION, $all, true );
		$note = sanitize_text_field( '' !== trim( (string) $note ) ? (string) $note : (string) $approved_by );
		self::add_history( $feature, $before, $state, $note );
		if ( class_exists( 'LLD_Members_Admin_Log' ) ) {
			LLD_Members_Admin_Log::add(
				'everyone' === $state ? 'feature_on' : ( 'off' === $state ? 'feature_off' : 'feature_test_accounts' ),
				array(
					'target' => $feature,
					'before' => $before,
					'after'  => $state,
					'reason' => '' !== $note ? $note : 'no note given',
				)
			);
		}
		return true;
	}

	/**
	 * Who is changing a switch: the command line, the signed-in admin, or the system.
	 */
	public static function who() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'command line';
		}
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		return $user && ! empty( $user->ID ) ? (string) $user->user_login : 'system';
	}

	/**
	 * @param string $feature Feature name.
	 * @param string $from    State before.
	 * @param string $to      State after.
	 * @param string $note    Reason or approval note.
	 */
	private static function add_history( $feature, $from, $to, $note ) {
		$all = get_option( self::HISTORY );
		$all = is_array( $all ) ? $all : array();
		$rows   = isset( $all[ $feature ] ) && is_array( $all[ $feature ] ) ? $all[ $feature ] : array();
		$rows[] = array(
			'who'  => self::who(),
			'when' => gmdate( 'Y-m-d H:i:s' ),
			'from' => (string) $from,
			'to'   => (string) $to,
			'note' => (string) $note,
		);
		$all[ $feature ] = array_slice( $rows, -self::HISTORY_KEEP );
		update_option( self::HISTORY, $all, false );
	}

	/**
	 * A feature's changes, newest first.
	 *
	 * @param string $feature Feature name.
	 * @return array<int,array{who:string,when:string,from:string,to:string,note:string}>
	 */
	public static function history( $feature ) {
		$all = get_option( self::HISTORY );
		$rows = is_array( $all ) && isset( $all[ $feature ] ) && is_array( $all[ $feature ] ) ? $all[ $feature ] : array();
		return array_reverse( $rows );
	}

	/**
	 * Replace the test-account list (FR3-74, FR3-82). Returns the list as saved.
	 *
	 * @param string[] $emails Emails.
	 * @return string[]
	 */
	public static function save_test_accounts( array $emails ) {
		$clean = array();
		foreach ( $emails as $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $clean, true ) ) {
				$clean[] = $email;
			}
		}
		update_option( self::TEST_ACCOUNTS, $clean, true );
		return $clean;
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
