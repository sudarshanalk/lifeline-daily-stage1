<?php
/**
 * Owner preview and the Stage 3 app connection.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview for the owner, and the member-only app routes.
 */
class LLD_Members_Quality {

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'preview_post' ) );
		add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'app_passwords' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'app_user' ), 20 );
	}

	/**
	 * Application passwords work on local HTTP. Production keeps WordPress's own rule.
	 *
	 * @param bool $available Whether WordPress would allow them.
	 */
	public static function app_passwords( $available ) {
		if ( function_exists( 'lld_members_is_production' ) && ! lld_members_is_production() ) {
			return true;
		}
		return $available;
	}

	public static function admin_menu() {
		add_submenu_page( 'lld-payments', 'Preview', 'Preview', 'manage_options', 'lld-preview', array( __CLASS__, 'preview_screen' ) );
	}

	public static function preview_post() {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['lld_preview_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_preview_nonce'] ) ), 'lld_preview' ) ) {
			return;
		}
		$state = isset( $_POST['lld_preview'] ) ? sanitize_key( wp_unslash( $_POST['lld_preview'] ) ) : '';
		if ( in_array( $state, array( 'visitor', 'free', 'premium' ), true ) ) {
			setcookie( 'lld_preview', $state, time() + HOUR_IN_SECONDS, '/', '', is_ssl(), true );
			$_COOKIE['lld_preview'] = $state;
		}
		wp_safe_redirect( home_url( '/members/home/' ) );
		exit;
	}

	public static function preview_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Preview</h1>';
		echo '<p>See the site as a visitor, a free member or a Premium member.</p>';
		foreach ( array( 'visitor' => 'Preview as a visitor', 'free' => 'Preview as a free member', 'premium' => 'Preview as a Premium member' ) as $state => $label ) {
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'lld_preview', 'lld_preview_nonce' );
			echo '<input type="hidden" name="lld_preview" value="' . esc_attr( $state ) . '">';
			submit_button( $label, 'secondary', 'submit', false );
			echo '</form> ';
		}
		echo '</div>';
	}

	/**
	 * Stop determine_current_user from calling itself while an app password is checked.
	 *
	 * @var bool
	 */
	private static $reading = false;

	/**
	 * @param int|false $user_id User so far.
	 */
	public static function app_user( $user_id ) {
		if ( $user_id || self::$reading ) {
			return $user_id;
		}
		$header  = isset( $_SERVER['HTTP_X_LLD_APP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LLD_APP'] ) ) : '';
		$decoded = base64_decode( $header, true );
		if ( ! is_string( $decoded ) || ! str_contains( $decoded, ':' ) ) {
			return $user_id;
		}
		list( $login, $pass ) = explode( ':', $decoded, 2 );
		$user = get_user_by( 'login', $login );
		if ( ! $user || user_can( $user, 'manage_options' ) || ! function_exists( 'wp_authenticate_application_password' ) ) {
			return $user_id;
		}
		self::$reading = true;
		$found         = wp_authenticate_application_password( null, $user->user_login, $pass );
		self::$reading = false;
		return $found instanceof WP_User ? (int) $found->ID : $user_id;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function session( $request ) {
		$params   = $request->get_json_params();
		$email    = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$password = isset( $params['password'] ) ? (string) $params['password'] : '';
		$user     = get_user_by( 'email', $email );
		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_REST_Response( array( 'message' => 'Sign-in failed.' ), 401 );
		}
		if ( user_can( $user, 'manage_options' ) || ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_REST_Response( array( 'message' => 'Not available.' ), 403 );
		}
		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'Lifeline Daily app' ) );
		if ( is_wp_error( $created ) ) {
			return new WP_REST_Response( array( 'message' => $created->get_error_message() ), 400 );
		}
		return new WP_REST_Response(
			array(
				'login'    => $user->user_login,
				'password' => $created[0],
			),
			200
		);
	}

	public static function me() {
		$member_id = self::member_id();
		if ( $member_id < 1 ) {
			return new WP_REST_Response( array( 'message' => 'Sign in.' ), 401 );
		}
		return new WP_REST_Response( self::bundle( $member_id ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function profile( $request ) {
		$member_id = self::member_id();
		if ( $member_id < 1 ) {
			return new WP_REST_Response( array( 'message' => 'Sign in.' ), 401 );
		}
		$params = $request->get_json_params();
		$name   = isset( $params['display_name'] ) ? sanitize_text_field( (string) $params['display_name'] ) : '';
		if ( '' === $name || strlen( $name ) > 80 ) {
			return new WP_REST_Response( array( 'message' => 'Add a display name.' ), 400 );
		}
		global $wpdb;
		$wpdb->update( LLD_Members_Schema::table( 'member' ), array( 'display_name' => $name ), array( 'id' => $member_id ), array( '%s' ), array( '%d' ) );
		return new WP_REST_Response( self::bundle( $member_id ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function other( $request ) {
		$member_id = self::member_id();
		$asked     = (int) $request->get_param( 'id' );
		if ( $member_id < 1 || $asked !== $member_id ) {
			return new WP_REST_Response( array( 'message' => 'Not allowed.' ), 403 );
		}
		return new WP_REST_Response( self::bundle( $member_id ), 200 );
	}

	public static function refused() {
		return new WP_REST_Response( array( 'message' => 'Not allowed.' ), 403 );
	}

	private static function member_id() {
		return class_exists( 'LLD_Members_Access' ) ? LLD_Members_Access::current_member_id() : 0;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	private static function bundle( $member_id ) {
		global $wpdb;
		$member = $wpdb->get_row( $wpdb->prepare( 'SELECT first_name, display_name FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		$goals  = $wpdb->get_col( $wpdb->prepare( 'SELECT goal_key FROM ' . LLD_Members_Schema::table( 'goal_choice' ) . ' WHERE member_id = %d', $member_id ) );
		$track  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT track_key FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $member_id ) );
		$height = $wpdb->get_var( $wpdb->prepare( 'SELECT height_cm FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) );
		$diary  = $wpdb->get_col( $wpdb->prepare( 'SELECT local_date FROM ' . LLD_Members_Schema::table( 'diary_day' ) . ' WHERE member_id = %d ORDER BY local_date ASC', $member_id ) );
		$plan   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT plan FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
		$done   = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::done_count( $member_id ) : 0;
		return array(
			'goals'    => array_values( array_map( 'strval', $goals ? $goals : array() ) ),
			'track'    => $track,
			'profile'  => array(
				'first_name'   => $member ? (string) $member->first_name : '',
				'display_name' => $member ? (string) $member->display_name : '',
				'height_cm'    => null === $height ? null : (float) $height,
			),
			'progress' => (int) $done . ' of 28 done',
			'diary'    => array_values( array_map( 'strval', $diary ? $diary : array() ) ),
			'plan'     => '' !== $plan ? $plan : 'free',
		);
	}
}
