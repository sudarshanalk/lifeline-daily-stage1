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
		register_rest_route(
			'lld-members/v1',
			'/security/password-storage',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'passwords' ),
				'permission_callback' => array( __CLASS__, 'loopback_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/member',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_member' ),
				'permission_callback' => array( __CLASS__, 'loopback_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_stripe' ),
				'permission_callback' => array( __CLASS__, 'loopback_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/ciphertext',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'test_ciphertext' ),
				'permission_callback' => array( __CLASS__, 'loopback_only' ),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/test/db-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_db_update' ),
				'permission_callback' => array( __CLASS__, 'loopback_only' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function loopback_only() {
		return ! lld_members_is_production() && lld_members_is_loopback();
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
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT text_ciphertext FROM {$table} WHERE member_id = %d ORDER BY id DESC LIMIT 1", $member_id ) );
		$text  = '';
		if ( is_string( $raw ) && '' !== $raw ) {
			$text = LLD_Members_Crypto::decrypt( $raw );
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
			$wpdb->insert(
				LLD_Members_Schema::table( 'something_else' ),
				array(
					'member_id'        => $member_id,
					'written_at'       => $now_time,
					'text_ciphertext'  => LLD_Members_Crypto::encrypt( (string) $params['sentinel'] ),
				)
			);
		}
		if ( ! empty( $params['access_state'] ) ) {
			$state = sanitize_text_field( (string) $params['access_state'] );
			$wpdb->replace(
				LLD_Members_Schema::table( 'subscription' ),
				array(
					'member_id'    => $member_id,
					'plan'         => 0 === strcasecmp( $state, 'free' ) ? 'free' : 'yearly',
					'access_state' => $state,
					'stripe_state' => 'active',
				)
			);
		}
		return new WP_REST_Response( array( 'member_id' => $member_id, 'user_id' => $user_id ), 200 );
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
		return new WP_REST_Response( array( 'unreachable' => (bool) get_option( 'lld_members_stripe_unreachable' ) ), 200 );
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

	private static function refused() {
		return new WP_REST_Response( array( 'message' => "This page isn't available" ), 403 );
	}
}
