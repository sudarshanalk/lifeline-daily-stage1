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
			'/members/(?P<id>\d+)/health',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'LLD_Members_Account', 'rest_health' ),
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
			$login   = sanitize_user( strstr( $email, '@', true ), true );
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login ? $login : 'member',
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
		if ( ! empty( $params['health_consent'] ) ) {
			$wpdb->insert(
				LLD_Members_Schema::table( 'consent' ),
				array(
					'member_id'        => $member_id,
					'consent_type'     => 'health',
					'change_kind'      => 'given',
					'document_version' => 'health-test',
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
		if ( ! empty( $params['what_changed'] ) ) {
			$wpdb->insert(
				LLD_Members_Schema::table( 'survey' ),
				array(
					'member_id'    => $member_id,
					'week_number'  => 4,
					'what_changed' => (string) $params['what_changed'],
				),
				array( '%d', '%d', '%s' )
			);
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
			$wpdb->replace(
				LLD_Members_Schema::table( 'subscription' ),
				array(
					'member_id'           => $member_id,
					'plan'                => 0 === strcasecmp( $state, 'free' ) ? 'free' : 'yearly',
					'access_state'        => $state,
					'stripe_state'        => 'active',
					'period_ends_on'      => isset( $params['period_ends_on'] ) ? sanitize_text_field( (string) $params['period_ends_on'] ) : null,
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
		$problems  = LLD_Members_Privacy::scan( $member_id, $sentinel, $scope );
		$email     = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$user      = $email ? get_user_by( 'email', $email ) : false;
		return new WP_REST_Response(
			array(
				'problems' => $problems,
				'user_id'  => $user ? (int) $user->ID : 0,
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

	private static function refused() {
		return new WP_REST_Response( array( 'message' => "This page isn't available" ), 403 );
	}
}
