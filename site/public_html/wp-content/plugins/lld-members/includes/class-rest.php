<?php
/**
 * JSON routes. Every member route checks that the data belongs to the signed-in member.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST registration.
 */
class LLD_Members_Rest {

	public static function register() {
		register_rest_route(
			'lld-members/v1',
			'/routes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'routes' ),
				'permission_callback' => array( __CLASS__, 'logged_in_member' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/members/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'account' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/members/(?P<id>\d+)/journey',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'journey' ),
				'permission_callback' => '__return_true',
			)
		);
		if ( ! lld_members_test_routes_open() ) {
			return;
		}
		// Test-only: saves any kind of health record directly, so the consent checks can try every kind.
		// Members save health records through their pages. Still checks the member and health consent.
		register_rest_route(
			'lld-members/v1',
			'/members/(?P<id>\d+)/health',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Account', 'rest_health' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/security/password-storage',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'passwords' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/member',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_member' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/stripe/notice',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Billing', 'rest_notice' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/billing',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Billing', 'rest_test' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_stripe' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/ciphertext',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'test_ciphertext' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/db-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_db_update' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_logout' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_reset' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/clock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_clock' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/retain',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_retain' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_scan' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/erasure',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_erasure' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/stores',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'test_stores' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/integrity',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_integrity' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/owner',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_owner' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/screen-state',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_screen_state' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/content-audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'test_content_audit' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/fixture',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_fixture' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/reports',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_reports' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/programme',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Programme', 'rest' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/premium',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Premium', 'rest_test' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/growth',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Growth', 'rest_test' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/analytics',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Growth', 'rest_analytics' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/calculator',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_calculator' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/mail',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_mail' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/app/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Quality', 'session' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/app/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'LLD_Members_Quality', 'me' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/app/profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Quality', 'profile' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/app/members/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'LLD_Members_Quality', 'other' ),
				'permission_callback' => '__return_true',
			)
		);
		foreach ( array( 'orders', 'premium', 'rewards', 'role' ) as $blocked ) {
			register_rest_route(
				'lld-members/v1',
				'/app/' . $blocked,
				array(
					'methods'             => 'POST',
					'callback'            => array( 'LLD_Members_Quality', 'refused' ),
					'permission_callback' => '__return_true',
				)
			);
		}
		register_rest_route(
			'lld-members/v1',
			'/test/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_search' ),
				'permission_callback' => array( __CLASS__, 'test_only' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function test_only() {
		return lld_members_test_request_allowed();
	}

	/**
	 * @return bool
	 */
	public static function logged_in_member() {
		return LLD_Members_Access::current_member_id() > 0;
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function routes() {
		return new WP_REST_Response( array( 'routes' => LLD_Members_Access::catalog() ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function account( $request ) {
		$member_id = (int) $request['id'];
		if ( ! LLD_Members_Access::can_read( $member_id ) ) {
			return self::refused();
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, email, first_name, two_step, role FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'message' => 'Not found' ), 404 );
		}
		return new WP_REST_Response(
			array(
				'id'            => (int) $row->id,
				'email'         => $row->email,
				'first_name'    => $row->first_name,
				'two_step'      => (bool) $row->two_step,
				'access_state'  => LLD_Members_Access::access_state( $member_id ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function journey( $request ) {
		$member_id = (int) $request['id'];
		if ( ! LLD_Members_Access::can_read( $member_id ) ) {
			return self::refused();
		}
		if ( ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return new WP_REST_Response( array( 'message' => 'Health consent needed' ), 403 );
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'something_else' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, text_ciphertext FROM {$table} WHERE member_id = %d ORDER BY id DESC LIMIT 1", $member_id ) );
		$text  = '';
		if ( $row && is_string( $row->text_ciphertext ) && '' !== $row->text_ciphertext ) {
			try {
				$text = LLD_Members_Crypto::decrypt( $row->text_ciphertext, 'something_else:' . $row->id );
			} catch ( RuntimeException $bound ) {
				$text = LLD_Members_Crypto::decrypt( $row->text_ciphertext );
			}
		}
		return new WP_REST_Response( array( 'something_else' => $text ), 200 );
	}

	public static function passwords() {
		$report = LLD_Members_Auth::password_storage();
		return new WP_REST_Response( $report, $report['all_hashed'] ? 200 : 500 );
	}

	/**
	 * Latest saved calculator headline for a test member.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_calculator( $request ) {
		$params = $request->get_json_params();
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'message' => 'Email is required.' ), 400 );
		}
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		$row       = $wpdb->get_row( $wpdb->prepare( 'SELECT calculator, headline, unit, band, calculator_version FROM ' . LLD_Members_Schema::table( 'calculator_result' ) . ' WHERE member_id = %d ORDER BY id DESC LIMIT 1', $member_id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'saved' => false ), 404 );
		}
		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_mail( $request ) {
		$params  = $request->get_json_params();
		$subject = isset( $params['subject'] ) ? sanitize_text_field( (string) $params['subject'] ) : 'Layout check';
		$sent    = wp_mail( 'layout@test.lifelinedaily.invalid', $subject, "Hello from Lifeline Daily\n" );
		return new WP_REST_Response( array( 'sent' => (bool) $sent ), $sent ? 200 : 500 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_member( $request ) {
		$params   = $request->get_json_params();
		$email    = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$password = isset( $params['password'] ) ? (string) $params['password'] : '';
		if ( ! is_email( $email ) || strlen( $password ) < 10 ) {
			return new WP_REST_Response( array( 'message' => 'Email and a password of at least 10 characters are required.' ), 400 );
		}
		$role = ( isset( $params['role'] ) && 'owner_admin' === $params['role'] ) ? 'owner_admin' : 'member';
		$first = isset( $params['first_name'] ) ? sanitize_text_field( (string) $params['first_name'] ) : 'Test';
		$user  = get_user_by( 'email', $email );
		if ( $user ) {
			$user_id = (int) $user->ID;
			wp_set_password( $password, $user_id );
			$user->set_role( 'owner_admin' === $role ? 'administrator' : 'subscriber' );
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login'   => LLD_Members_Account::unique_login( $email ),
					'user_pass'    => $password,
					'user_email'   => $email,
					'first_name'   => $first,
					'role'         => 'owner_admin' === $role ? 'administrator' : 'subscriber',
					'display_name' => $first,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return new WP_REST_Response( array( 'message' => $user_id->get_error_message() ), 400 );
			}
		}
		global $wpdb;
		if ( ! empty( $params['no_member'] ) ) {
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user_id ) );
			if ( $existing_id ) {
				$wpdb->delete( LLD_Members_Schema::table( 'member' ), array( 'id' => $existing_id ), array( '%d' ) );
			}
			return new WP_REST_Response( array( 'member_id' => 0, 'user_id' => (int) $user_id ), 200 );
		}
		$table     = LLD_Members_Schema::table( 'member' );
		$existing  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );
		$two_step  = ( 'owner_admin' === $role ) || ! empty( $params['two_step'] );
		$now_date  = gmdate( 'Y-m-d' );
		$now_time  = gmdate( 'Y-m-d H:i:s' );
		$row       = array(
			'user_id'            => $user_id,
			'email'              => $email,
			'first_name'         => $first,
			'last_name'          => 'Member',
			'age'                => 40,
			'age_entered_on'     => $now_date,
			'timezone'           => 'Europe/London',
			'units'              => 'metric',
			'role'               => $role,
			'email_confirmed_at' => $now_time,
			'joined_on'          => $now_date,
			'two_step'           => $two_step ? 1 : 0,
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing ) );
			$member_id = $existing;
		} else {
			$wpdb->insert( $table, $row );
			$member_id = (int) $wpdb->insert_id;
		}
		if ( class_exists( 'LLD_Members_Growth' ) ) {
			LLD_Members_Growth::attach_source( $member_id );
		}
		$consents = array();
		if ( ! empty( $params['health_consent'] ) ) {
			$consents[] = 'health';
		}
		if ( ! empty( $params['consents'] ) && is_array( $params['consents'] ) ) {
			foreach ( $params['consents'] as $type ) {
				$type = sanitize_key( (string) $type );
				if ( in_array( $type, array( 'terms', 'health', 'marketing' ), true ) ) {
					$consents[] = $type;
				}
			}
		}
		foreach ( array_unique( $consents ) as $type ) {
			$wpdb->insert(
				LLD_Members_Schema::table( 'consent' ),
				array(
					'member_id'        => $member_id,
					'consent_type'     => $type,
					'change_kind'      => 'given',
					'document_version' => $type . '-test',
					'occurred_at'      => $now_time,
				)
			);
		}
		if ( ! empty( $params['sentinel'] ) ) {
			if ( empty( $params['health_consent'] ) ) {
				return new WP_REST_Response( array( 'message' => 'Health data needs health consent.' ), 403 );
			}
			$else = LLD_Members_Schema::table( 'something_else' );
			$wpdb->insert(
				$else,
				array(
					'member_id'       => $member_id,
					'written_at'      => $now_time,
					'text_ciphertext' => LLD_Members_Crypto::encrypt( 'pending', 'something_else:0' ),
				)
			);
			$else_id = (int) $wpdb->insert_id;
			$wpdb->update(
				$else,
				array( 'text_ciphertext' => LLD_Members_Crypto::encrypt( (string) $params['sentinel'], 'something_else:' . $else_id ) ),
				array( 'id' => $else_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
		if ( ! empty( $params['what_changed'] ) || ! empty( $params['quote'] ) ) {
			$survey = array(
				'member_id'    => $member_id,
				'week_number'  => 4,
				'what_changed' => ! empty( $params['what_changed'] ) ? (string) $params['what_changed'] : null,
			);
			if ( ! empty( $params['quote'] ) ) {
				$survey['quote_text']   = (string) $params['quote'];
				$survey['permission']   = 1;
				$survey['approved_on']  = $now_date;
				$survey['display_name'] = $first;
				$survey['country']      = 'GB';
			}
			$wpdb->insert( LLD_Members_Schema::table( 'survey' ), $survey );
		}
		if ( ! empty( $params['referral'] ) ) {
			$friend = isset( $params['friend_member_id'] ) ? (int) $params['friend_member_id'] : ( 800000 + $member_id );
			$wpdb->insert(
				LLD_Members_Schema::table( 'referral' ),
				array(
					'referrer_member_id' => $member_id,
					'code'               => 'c2',
					'friend_member_id'   => $friend,
					'status'             => 'qualified',
				)
			);
			$referral_id = (int) $wpdb->insert_id;
			LLD_Members_Integrity::create_referral_rewards( $referral_id, $member_id, $friend );
		}
		if ( ! empty( $params['access_state'] ) ) {
			$state = sanitize_text_field( (string) $params['access_state'] );
			$plan  = isset( $params['plan'] ) ? sanitize_key( (string) $params['plan'] ) : '';
			if ( ! in_array( $plan, array( 'free', 'monthly', 'yearly' ), true ) ) {
				$plan = 0 === strcasecmp( $state, 'free' ) ? 'free' : 'yearly';
			}
			$wpdb->replace(
				LLD_Members_Schema::table( 'subscription' ),
				array(
					'member_id'           => $member_id,
					'plan'                => $plan,
					'access_state'        => $state,
					'stripe_state'        => 'active',
					'started_on'          => isset( $params['started_on'] ) ? sanitize_text_field( (string) $params['started_on'] ) : $now_date,
					'period_ends_on'      => isset( $params['period_ends_on'] ) ? sanitize_text_field( (string) $params['period_ends_on'] ) : null,
					'premium_ended_on'    => isset( $params['premium_ended_on'] ) ? sanitize_text_field( (string) $params['premium_ended_on'] ) : null,
					'stripe_subscription' => isset( $params['stripe_subscription'] ) ? sanitize_text_field( (string) $params['stripe_subscription'] ) : '',
				)
			);
		}
		return new WP_REST_Response( array( 'member_id' => $member_id, 'user_id' => $user_id, 'joined_on' => $now_date ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_stripe( $request ) {
		$params = $request->get_json_params();
		if ( ! empty( $params['unreachable'] ) ) {
			update_option( 'lld_members_stripe_unreachable', '1', false );
		} else {
			delete_option( 'lld_members_stripe_unreachable' );
		}
		if ( is_array( $params ) && array_key_exists( 'cancel_fail', $params ) ) {
			if ( ! empty( $params['cancel_fail'] ) ) {
				update_option( 'lld_members_stripe_cancel_fail', '1', false );
			} else {
				delete_option( 'lld_members_stripe_cancel_fail' );
			}
		}
		return new WP_REST_Response(
			array(
				'unreachable' => (bool) get_option( 'lld_members_stripe_unreachable' ),
				'last_cancel' => get_option( 'lld_members_last_cancel' ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_ciphertext( $request ) {
		global $wpdb;
		$member_id = (int) $request->get_param( 'member_id' );
		$table     = LLD_Members_Schema::table( 'something_else' );
		$raw       = $wpdb->get_var( $wpdb->prepare( "SELECT text_ciphertext FROM {$table} WHERE member_id = %d ORDER BY id DESC LIMIT 1", $member_id ) );
		return new WP_REST_Response( array( 'ciphertext' => is_string( $raw ) ? $raw : '' ), 200 );
	}

	public static function test_db_update() {
		$result = LLD_Members_Schema::update();
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_logout() {
		wp_logout();
		return new WP_REST_Response( array( 'out' => true ), 200 );
	}

	public static function test_reset( $request ) {
		$params = $request->get_json_params();
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		global $wpdb;
		$wpdb->delete( LLD_Members_Schema::table( 'sign_in_lock' ), array( 'email' => $email ), array( '%s' ) );
		$ids   = array();
		$by_email = get_user_by( 'email', $email );
		if ( $by_email ) {
			$ids[] = (int) $by_email->ID;
		}
		$login = sanitize_user( (string) strstr( $email, '@', true ), true );
		$by_login = $login ? get_user_by( 'login', $login ) : false;
		if ( $by_login ) {
			$ids[] = (int) $by_login->ID;
		}
		foreach ( array_unique( $ids ) as $user_id ) {
			$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user_id ) );
			if ( $member_id ) {
				LLD_Members_Erasure::delete_account( $member_id, false );
			} elseif ( get_userdata( $user_id ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}
		}
		return new WP_REST_Response( array( 'reset' => true ), 200 );
	}

	public static function test_clock( $request ) {
		$params = $request->get_json_params();
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$utc    = isset( $params['utc'] ) ? (string) $params['utc'] : '';
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		if ( ! $member_id && ! empty( $params['member_id'] ) ) {
			$member_id = (int) $params['member_id'];
		}
		$result = LLD_Members_Clock::set( $member_id, $utc );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		return new WP_REST_Response( array( 'member_id' => $member_id, 'utc' => $utc ), 200 );
	}

	public static function test_retain() {
		LLD_Members_Privacy::sweep();
		LLD_Members_Erasure::email_new_lines();
		return new WP_REST_Response( array( 'swept' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_scan( $request ) {
		$params    = $request->get_json_params();
		$member_id = isset( $params['member_id'] ) ? (int) $params['member_id'] : 0;
		$sentinel  = isset( $params['sentinel'] ) ? (string) $params['sentinel'] : '';
		$scope     = isset( $params['scope'] ) ? (string) $params['scope'] : 'account';
		$email     = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$problems  = LLD_Members_Privacy::scan( $member_id, $sentinel, $scope, $email );
		$user      = $email ? get_user_by( 'email', $email ) : false;
		$rows      = $member_id ? LLD_Members_Privacy::member_rows( $member_id, $email ) : array();
		return new WP_REST_Response(
			array(
				'problems'    => $problems,
				'user_id'     => $user ? (int) $user->ID : 0,
				'records'     => array_map( 'count', $rows ),
				'diary_meals' => isset( $rows['diary_meal'] ) ? array_map( 'intval', wp_list_pluck( $rows['diary_meal'], 'id' ) ) : array(),
			),
			200
		);
	}

	/**
	 * Restore procedure (T6) for the restore checks: close or reopen the site, or import an emailed
	 * erasure list, re-apply the lines dated after "since", verify, and reopen when nothing returned.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_erasure( $request ) {
		$params = $request->get_json_params();
		$action = isset( $params['action'] ) ? sanitize_key( (string) $params['action'] ) : '';
		if ( 'close' === $action ) {
			update_option( 'lld_members_closed', '1', false );
			return new WP_REST_Response( array( 'closed' => true ), 200 );
		}
		if ( 'open' === $action ) {
			delete_option( 'lld_members_closed' );
			return new WP_REST_Response( array( 'closed' => false ), 200 );
		}
		if ( 'reapply' !== $action ) {
			return new WP_REST_Response( array( 'message' => 'Unknown action.' ), 400 );
		}
		$since = isset( $params['since'] ) ? sanitize_text_field( (string) $params['since'] ) : '';
		$list  = isset( $params['list'] ) ? (string) $params['list'] : '';
		$path  = trailingslashit( get_temp_dir() ) . 'lld-erasure-' . wp_generate_password( 12, false, false ) . '.txt';
		file_put_contents( $path, $list );
		$imported = LLD_Members_Erasure::import_file( $path );
		unlink( $path );
		$count    = LLD_Members_Erasure::reapply_all( '' !== $since ? $since : LLD_Members_Erasure::restore_floor() );
		$problems = LLD_Members_Erasure::verify_stored( $since );
		if ( ! $problems ) {
			delete_option( 'lld_members_closed' );
		}
		return new WP_REST_Response(
			array(
				'imported'  => $imported,
				'reapplied' => $count,
				'problems'  => $problems,
				'closed'    => (bool) get_option( 'lld_members_closed' ),
			),
			200
		);
	}

	public static function test_stores() {
		global $wpdb;
		$columns = array();
		foreach ( LLD_Members_Schema::all_suffixes() as $suffix ) {
			$table = LLD_Members_Schema::table( $suffix );
			$names = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			$columns[ $suffix ] = is_array( $names ) ? array_values( $names ) : array();
		}
		$body = LLD_Members_Privacy::stores();
		$body['columns'] = $columns;
		return new WP_REST_Response( $body, 200 );
	}

	public static function test_integrity() {
		$results = LLD_Members_Integrity::exercise();
		$ok      = ! in_array( false, $results, true );
		return new WP_REST_Response( array( 'ok' => $ok, 'rules' => $results ), $ok ? 200 : 500 );
	}

	public static function test_owner() {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		if ( ! $users ) {
			return new WP_REST_Response( array( 'message' => 'No owner account' ), 500 );
		}
		wp_set_current_user( $users[0]->ID );
		wp_set_auth_cookie( $users[0]->ID, true );
		return new WP_REST_Response( array( 'user' => (int) $users[0]->ID ), 200 );
	}

	/**
	 * What the site keeps about one member's Sleep and Stress screening (TC-FR2-38-AS-04).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_screen_state( $request ) {
		$params = $request->get_json_params();
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		if ( $member_id < 1 ) {
			return new WP_REST_Response( array( 'message' => 'No such member.' ), 404 );
		}
		return new WP_REST_Response( LLD_Members_Personal::screening_state( $member_id ), 200 );
	}

	public static function test_content_audit() {
		return new WP_REST_Response( LLD_Members_Content::audit(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_fixture( $request ) {
		$params = $request->get_json_params();
		$kind   = isset( $params['kind'] ) ? sanitize_key( (string) $params['kind'] ) : '';
		$stamp  = isset( $params['stamp'] ) ? sanitize_title( (string) $params['stamp'] ) : '';
		$result = LLD_Members_Fixtures::build( $kind, $stamp, is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_reports( $request ) {
		$params = $request->get_json_params();
		LLD_Members_Personal::daily();
		if ( ! empty( $params['notify'] ) ) {
			LLD_Members_Personal::notify_live_goals();
		}
		return new WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_search( $request ) {
		$params = $request->get_json_params();
		$needle = isset( $params['q'] ) ? (string) $params['q'] : '';
		if ( strlen( $needle ) < 8 ) {
			return new WP_REST_Response( array( 'message' => 'The search text is too short.' ), 400 );
		}
		global $wpdb;
		$hits    = array();
		$tables  = $wpdb->get_col( 'SHOW TABLES' );
		$paths   = array( ini_get( 'error_log' ), WP_CONTENT_DIR . '/debug.log' );
		$offsets = array();
		foreach ( $paths as $path ) {
			$offsets[] = ( is_string( $path ) && '' !== $path && is_readable( $path ) ) ? (int) filesize( $path ) : 0;
		}
		$watched = array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->usermeta );
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			$personal = 0 === strpos( $table, $wpdb->prefix . 'lld_' );
			if ( ! $personal && ! in_array( $table, $watched, true ) ) {
				continue;
			}
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
			if ( ! is_array( $columns ) ) {
				continue;
			}
			foreach ( $columns as $column ) {
				$name = isset( $column->Field ) ? (string) $column->Field : '';
				$type = isset( $column->Type ) ? strtolower( (string) $column->Type ) : '';
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) || ! preg_match( '/char|text|blob/', $type ) ) {
					continue;
				}
				$wpdb->suppress_errors( true );
				$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$name}` LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ) );
				$wpdb->suppress_errors( false );
				if ( $found > 0 ) {
					$hits[] = $table . '.' . $name;
				}
			}
		}
		$logged = false;
		foreach ( $paths as $index => $path ) {
			if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
				continue;
			}
			$handle = fopen( $path, 'rb' );
			if ( ! $handle ) {
				continue;
			}
			fseek( $handle, (int) $offsets[ $index ] );
			$chunk = stream_get_contents( $handle );
			fclose( $handle );
			if ( is_string( $chunk ) && false !== strpos( $chunk, $needle ) ) {
				$logged = true;
			}
		}
		return new WP_REST_Response( array( 'hits' => $hits, 'log' => $logged ), 200 );
	}

	private static function refused() {
		return new WP_REST_Response( array( 'message' => "This page isn't available" ), 403 );
	}
}
