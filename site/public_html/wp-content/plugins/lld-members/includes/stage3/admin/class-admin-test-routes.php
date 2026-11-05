<?php
/**
 * Test-site routes for the admin panel specs (P3-87). Registered only on CI and local sites, behind the test
 * secret, as every Stage 2 test route is. Never on staging or production.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /lld-members/v1/test/admin with an "op".
 */
class LLD_Members_Admin_Test_Routes {

	const CLOCK = 'lld_admin_test_clock_offset';

	public static function boot() {
		if ( ! function_exists( 'lld_members_test_routes_open' ) || ! lld_members_test_routes_open() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		add_filter( 'lld_admin_now', array( __CLASS__, 'clock' ) );
	}

	public static function register() {
		register_rest_route(
			'lld-members/v1',
			'/test/admin',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( 'LLD_Members_REST', 'test_only' ),
			)
		);
	}

	/**
	 * The admin clock moved on by the test (TC-NFR3-20-AS-03).
	 *
	 * @param int $now Seconds.
	 */
	public static function clock( $now ) {
		return (int) $now + (int) get_option( self::CLOCK, 0 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function run( $request ) {
		global $wpdb;
		$p   = (array) $request->get_json_params();
		$op  = isset( $p['op'] ) ? (string) $p['op'] : '';
		$log = LLD_Members_Schema::table( 'admin_log' );
		if ( 'owner' === $op ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
			if ( $admins ) {
				// A new run starts with no fresh codes counted against the hour (repeated local runs).
				$wpdb->delete( LLD_Members_Stage3_Schema::table( 'email_codes' ), array( 'email' => strtolower( (string) $admins[0]->user_email ), 'purpose' => 'admin_step' ), array( '%s', '%s' ) );
			}
			return new WP_REST_Response( array( 'email' => $admins ? (string) $admins[0]->user_email : '', 'id' => $admins ? (int) $admins[0]->ID : 0, 'admin_email' => (string) get_option( 'admin_email' ) ), 200 );
		}
		if ( 'helper' === $op ) {
			// A Support helper with a known password (test sites only), made as the Owner's "Add a helper" makes one.
			$email = sanitize_email( (string) ( $p['email'] ?? '' ) );
			$user  = get_user_by( 'email', $email );
			$id    = $user ? (int) $user->ID : wp_insert_user(
				array(
					'user_login'   => 'support-' . substr( md5( $email ), 0, 10 ),
					'user_email'   => $email,
					'display_name' => 'Test helper',
					'user_pass'    => (string) ( $p['password'] ?? '' ),
					'role'         => LLD_Members_Admin_Roles::ROLE,
				)
			);
			if ( is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'error' => $id->get_error_message() ), 400 );
			}
			if ( $user ) {
				wp_set_password( (string) ( $p['password'] ?? '' ), (int) $id );
				$user->set_role( LLD_Members_Admin_Roles::ROLE );
			}
			$wpdb->delete( LLD_Members_Schema::table( 'sign_in_lock' ), array( 'email' => strtolower( $email ) ), array( '%s' ) );
			return new WP_REST_Response( array( 'id' => (int) $id ), 200 );
		}
		if ( 'clear_spam' === $op ) {
			// Paid Memberships Pro counts every code step of a sign-in as a failed login for this address; the specs
			// sign in many times, so they clear that count first (test sites only).
			if ( function_exists( 'pmpro_clear_spam_activity' ) ) {
				pmpro_clear_spam_activity();
			}
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'clock' === $op ) {
			update_option( self::CLOCK, (int) ( $p['seconds'] ?? 0 ), false );
			return new WP_REST_Response( array( 'offset' => (int) get_option( self::CLOCK, 0 ) ), 200 );
		}
		if ( 'age_log' === $op ) {
			// Move this test's newest entry back in time, for the 2-year limit (TC-FR3-86-AS-06).
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$log} WHERE target = %s ORDER BY id DESC LIMIT 1", (string) ( $p['target'] ?? '' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update( $log, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - (int) ( $p['days'] ?? 0 ) * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
			return new WP_REST_Response( array( 'id' => $id ), 200 );
		}
		if ( 'sweep' === $op ) {
			LLD_Members_Admin_Log::sweep();
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'log' === $op ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log} WHERE member_number = %d OR target = %s ORDER BY id ASC", (int) ( $p['member'] ?? 0 ), (string) ( $p['target'] ?? '' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_REST_Response( array( 'rows' => $rows ), 200 );
		}
		if ( 'member' === $op ) {
			$id = (int) ( $p['member'] ?? 0 );
			return new WP_REST_Response(
				array(
					'premium'  => LLD_Members_Billing::has_premium( $id ),
					'state'    => LLD_Members_Access::access_state( $id ),
					'blocked'  => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT blocked_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $id ) ),
					'notes'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Stage3_Schema::table( 'member_notes' ) . ' WHERE member_id = %d', $id ) ),
					'grants'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Stage3_Schema::table( 'premium_grants' ) . ' WHERE member_id = %d', $id ) ),
					'problems' => LLD_Members_Privacy::scan( $id, '', 'account', (string) ( $p['email'] ?? '' ) ),
				),
				200
			);
		}
		if ( 'prepare' === $op ) {
			// Fields the Stage 2 test member route does not set: a Stripe customer, or an unconfirmed email.
			$id = (int) ( $p['member'] ?? 0 );
			if ( ! empty( $p['customer'] ) ) {
				$wpdb->update( LLD_Members_Schema::table( 'subscription' ), array( 'stripe_customer' => sanitize_text_field( (string) $p['customer'] ) ), array( 'member_id' => $id ) );
			}
			if ( ! empty( $p['unconfirm'] ) ) {
				$wpdb->update( LLD_Members_Schema::table( 'member' ), array( 'email_confirmed_at' => null ), array( 'id' => $id ) );
			}
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'grant_end' === $op ) {
			// Move a grant's end date into the past, as if the date had come (TC-FR3-80-AS-02).
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Stage3_Schema::table( 'premium_grants' ) . ' SET ends_at = %s WHERE member_id = %d', gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) ( $p['member'] ?? 0 ) ) );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'restore' === $op ) {
			// Put a switch and the test-account list back as the spec found them (CI sets them in stage2-wp.sh).
			$all  = get_option( LLD_Members_Features::OPTION );
			$all  = is_array( $all ) ? $all : array();
			$name = sanitize_key( (string) ( $p['feature'] ?? '' ) );
			if ( isset( $p['row'] ) && is_array( $p['row'] ) ) {
				$all[ $name ] = $p['row'];
			} else {
				unset( $all[ $name ] );
			}
			update_option( LLD_Members_Features::OPTION, $all, true );
			if ( isset( $p['emails'] ) && is_array( $p['emails'] ) ) {
				update_option( LLD_Members_Features::TEST_ACCOUNTS, array_values( $p['emails'] ), true );
			}
			update_option( self::CLOCK, 0, false );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'feature' === $op ) {
			$stored = get_option( LLD_Members_Features::OPTION );
			$name   = sanitize_key( (string) ( $p['feature'] ?? '' ) );
			return new WP_REST_Response(
				array(
					'state'   => LLD_Members_Features::state( $name ),
					'history' => LLD_Members_Features::history( $name ),
					'row'     => is_array( $stored ) && isset( $stored[ $name ] ) ? $stored[ $name ] : null,
					'emails'  => LLD_Members_Features::test_account_emails(),
				),
				200
			);
		}
		if ( 'feature_old' === $op ) {
			$name = sanitize_key( (string) ( $p['feature'] ?? '' ) );
			return new WP_REST_Response( array( 'state' => LLD_Members_Features::state( $name ), 'history' => LLD_Members_Features::history( $name ) ), 200 );
		}
		return new WP_REST_Response( array( 'error' => 'unknown op' ), 400 );
	}
}
