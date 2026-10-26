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
 * Preview for the owner (NFR2-08, AD-08), and the member-only app routes (NFR2-09, Data Model section 12 and T5).
 *
 * App keys are WordPress application passwords marked with APP_ID, one per app installation. They are accepted only on
 * the /lld-members/v1/app/ routes, expire after 90 days, end when the password changes, and can be withdrawn from the
 * member's profile. Signing in for a key goes through wp_authenticate(), so the sign-in pause, the two-step code and
 * the closed-site block all apply.
 */
class LLD_Members_Quality {

	const APP_ID = '6f1d3c2a-8b4e-4f7a-9c5d-2e1b0a9f8c7d';

	const APP_NAME = 'Lifeline Daily app';

	const APP_ROUTES = '/lld-members/v1/app/';

	const KEY_DAYS = 90;

	const STATES = array(
		'visitor' => 'a visitor',
		'free'    => 'a free member',
		'premium' => 'a Premium member',
	);

	/**
	 * Stop determine_current_user from calling itself while an app password is checked.
	 *
	 * @var bool
	 */
	private static $reading = false;

	/**
	 * True once an application password authenticated this request.
	 *
	 * @var bool
	 */
	private static $key_used = false;

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'preview_post' ) );
		add_action( 'admin_post_lld_preview', array( __CLASS__, 'preview_switch' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'app_passwords' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'app_user' ), 20 );
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'key_rules' ), 10, 3 );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'key_seen' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'app_scope' ), 5, 3 );
		add_action( 'wp_set_password', array( __CLASS__, 'password_set' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'password_reset' ) );
		add_action( 'profile_update', array( __CLASS__, 'profile_updated' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'withdraw_post' ), 5 );
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
		self::set_preview( $state );
		wp_safe_redirect( 'off' === $state ? admin_url( 'admin.php?page=lld-preview' ) : home_url( '/members/home/' ) );
		exit;
	}

	/**
	 * Admin-bar and banner links: admin-post.php?action=lld_preview&state=...&_wpnonce=...
	 */
	public static function preview_switch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lld_preview_switch' );
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		self::set_preview( $state );
		wp_safe_redirect( 'off' === $state ? admin_url( 'admin.php?page=lld-preview' ) : home_url( '/members/home/' ) );
		exit;
	}

	/**
	 * @param string $state visitor, free, premium or off.
	 */
	private static function set_preview( $state ) {
		if ( isset( self::STATES[ $state ] ) ) {
			setcookie( 'lld_preview', $state, time() + HOUR_IN_SECONDS, '/', '', is_ssl(), true );
			$_COOKIE['lld_preview'] = $state;
		} elseif ( 'off' === $state ) {
			setcookie( 'lld_preview', '', time() - HOUR_IN_SECONDS, '/', '', is_ssl(), true );
			unset( $_COOKIE['lld_preview'] );
		} else {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'admin_log' ),
			array(
				'action'        => 'preview',
				'detail'        => $state,
				'money_related' => 0,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * @param string $state visitor, free, premium or off.
	 */
	private static function switch_url( $state ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=lld_preview&state=' . rawurlencode( $state ) ), 'lld_preview_switch' );
	}

	public static function preview_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = LLD_Members_Access::preview_state();
		echo '<div class="wrap"><h1>Preview</h1>';
		echo '<p>See the site as a visitor, a free member or a Premium member. Only your own account changes while you preview.</p>';
		if ( '' !== $current ) {
			echo '<p>You are previewing as ' . esc_html( self::STATES[ $current ] ) . '.</p>';
		}
		$labels = array(
			'visitor' => 'Preview as a visitor',
			'free'    => 'Preview as a free member',
			'premium' => 'Preview as a Premium member',
		);
		if ( '' !== $current ) {
			$labels['off'] = 'Exit preview';
		}
		foreach ( $labels as $state => $label ) {
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'lld_preview', 'lld_preview_nonce' );
			echo '<input type="hidden" name="lld_preview" value="' . esc_attr( $state ) . '">';
			submit_button( $label, 'secondary', 'submit', false );
			echo '</form> ';
		}
		echo '</div>';
	}

	/**
	 * AD-08: the switch in the admin bar.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! $bar instanceof WP_Admin_Bar || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = LLD_Members_Access::preview_state();
		$bar->add_node(
			array(
				'id'    => 'lld-preview',
				'title' => '' === $current ? 'Preview as' : 'Previewing as ' . self::STATES[ $current ],
				'href'  => admin_url( 'admin.php?page=lld-preview' ),
			)
		);
		foreach ( self::STATES as $state => $label ) {
			$bar->add_node(
				array(
					'parent' => 'lld-preview',
					'id'     => 'lld-preview-' . $state,
					'title'  => ucfirst( $label ),
					'href'   => self::switch_url( $state ),
				)
			);
		}
		if ( '' !== $current ) {
			$bar->add_node(
				array(
					'parent' => 'lld-preview',
					'id'     => 'lld-preview-off',
					'title'  => 'Exit preview',
					'href'   => self::switch_url( 'off' ),
				)
			);
		}
	}

	/**
	 * Banner at the top of member pages while the owner previews, with the switch and an exit.
	 */
	public static function preview_banner() {
		$current = LLD_Members_Access::preview_state();
		if ( '' === $current ) {
			return '';
		}
		$html = '<div class="lld-preview-bar" role="status" data-lld-preview="' . esc_attr( $current ) . '" style="background:#fff4d6;color:#1d1d1b;padding:8px 16px">';
		$html .= '<p style="margin:0">Preview: you are seeing what ' . esc_html( self::STATES[ $current ] ) . ' sees. ';
		foreach ( self::STATES as $state => $label ) {
			if ( $state !== $current ) {
				$html .= '<a href="' . esc_url( self::switch_url( $state ) ) . '">See ' . esc_html( $label ) . '</a> &middot; ';
			}
		}
		$html .= '<a href="' . esc_url( self::switch_url( 'off' ) ) . '">Exit preview</a></p></div>';
		return $html;
	}

	/**
	 * REST route of this request, once WordPress has parsed it.
	 */
	private static function rest_route() {
		global $wp;
		if ( isset( $wp ) && is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) ) {
			return '/' . ltrim( (string) $wp->query_vars['rest_route'], '/' );
		}
		return '';
	}

	/**
	 * @param string $route Route.
	 */
	private static function is_app_route( $route ) {
		return 0 === strpos( (string) $route, self::APP_ROUTES );
	}

	private static function on_app_route() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && self::is_app_route( self::rest_route() );
	}

	/**
	 * The app sends X-LLD-App: base64(login:key). It is read only on the app routes.
	 *
	 * @param int|false $user_id User so far.
	 */
	public static function app_user( $user_id ) {
		if ( $user_id || self::$reading || ! self::on_app_route() ) {
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
	 * Every application password check, whichever header carried it (X-LLD-App, Basic auth or XML-RPC):
	 * only on the app routes, only an app key, and only before it expires.
	 *
	 * @param WP_Error $error Errors so far.
	 * @param WP_User  $user  User.
	 * @param array    $item  Application password.
	 */
	public static function key_rules( $error, $user, $item ) {
		if ( ! $error instanceof WP_Error || ! $user instanceof WP_User || ! is_array( $item ) ) {
			return;
		}
		if ( ! self::on_app_route() ) {
			$error->add( 'lld_app_scope', 'App keys work only on the Lifeline Daily app routes.', array( 'status' => 403 ) );
			return;
		}
		if ( self::APP_ID !== ( $item['app_id'] ?? '' ) ) {
			$error->add( 'lld_app_key', 'This is not a Lifeline Daily app key.', array( 'status' => 401 ) );
			return;
		}
		if ( self::expired( $user, $item ) ) {
			WP_Application_Passwords::delete_application_password( $user->ID, (string) $item['uuid'] );
			$error->add( 'lld_app_expired', 'This app key has expired. Sign in again.', array( 'status' => 401 ) );
		}
	}

	public static function key_seen() {
		self::$key_used = true;
	}

	/**
	 * A request authenticated by an app key reaches only the app routes, including batch and internal requests.
	 *
	 * @param mixed           $result  Response so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function app_scope( $result, $server, $request ) {
		unset( $server );
		$used = self::$key_used || ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() );
		if ( ! $used || ! $request instanceof WP_REST_Request || self::is_app_route( $request->get_route() ) ) {
			return $result;
		}
		return new WP_Error( 'lld_app_scope', 'App keys work only on the Lifeline Daily app routes.', array( 'status' => 403 ) );
	}

	/**
	 * @param WP_User $user User.
	 * @param array   $item Application password.
	 */
	private static function expired( $user, $item ) {
		$created = isset( $item['created'] ) ? (int) $item['created'] : 0;
		$now     = LLD_Members_Clock::now( self::member_of( (int) $user->ID ) )->getTimestamp();
		return $created < 1 || $now - $created > self::KEY_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * @param int $user_id WordPress user.
	 */
	private static function member_of( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user_id ) );
	}

	/**
	 * The member's app keys.
	 *
	 * @param int $user_id WordPress user.
	 * @return array<int,array<string,mixed>>
	 */
	private static function keys( $user_id ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) || $user_id < 1 ) {
			return array();
		}
		$out = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( self::APP_ID === ( $item['app_id'] ?? '' ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param int $user_id WordPress user.
	 */
	private static function revoke_all( $user_id ) {
		foreach ( self::keys( (int) $user_id ) as $item ) {
			WP_Application_Passwords::delete_application_password( (int) $user_id, (string) $item['uuid'] );
		}
	}

	/**
	 * @param string $password New password (unused).
	 * @param int    $user_id  User.
	 */
	public static function password_set( $password, $user_id ) {
		unset( $password );
		self::revoke_all( (int) $user_id );
	}

	/**
	 * @param WP_User $user User.
	 */
	public static function password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::revoke_all( (int) $user->ID );
		}
	}

	/**
	 * Password changed through WordPress's own profile screen.
	 *
	 * @param int     $user_id User.
	 * @param WP_User $old     User before the change.
	 */
	public static function profile_updated( $user_id, $old ) {
		$now = get_userdata( (int) $user_id );
		if ( $old instanceof WP_User && $now instanceof WP_User && $old->user_pass !== $now->user_pass ) {
			self::revoke_all( (int) $user_id );
		}
	}

	/**
	 * The member's app connections, each with Withdraw (T5). Shown on the profile page.
	 *
	 * @param int $member_id Member number.
	 */
	public static function app_keys_html( $member_id ) {
		global $wpdb;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		$keys    = self::keys( $user_id );
		$note    = ( isset( $_GET['lld_app'] ) && 'withdrawn' === $_GET['lld_app'] ) ? '<p class="lld-info">App connection withdrawn.</p>' : '';
		if ( ! $keys ) {
			return '' === $note ? '' : '<section data-lld-app-keys>' . $note . '</section>';
		}
		$html = '<section data-lld-app-keys><h2>App connections</h2>' . $note . '<ul>';
		foreach ( $keys as $item ) {
			$created = (int) $item['created'];
			$html   .= '<li>Lifeline Daily app, connected ' . esc_html( wp_date( 'j F Y', $created ) ) . ', ends ' . esc_html( wp_date( 'j F Y', $created + self::KEY_DAYS * DAY_IN_SECONDS ) );
			$html   .= '<form method="post">' . wp_nonce_field( 'lld_app_withdraw', 'lld_app_nonce', true, false );
			$html   .= '<input type="hidden" name="lld_app_key" value="' . esc_attr( (string) $item['uuid'] ) . '">';
			$html   .= lld_button( array( 'label' => 'Withdraw' ) ) . '</form></li>';
		}
		return $html . '</ul></section>';
	}

	public static function withdraw_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['lld_app_nonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_app_nonce'] ) ), 'lld_app_withdraw' ) ) {
			return;
		}
		$uuid    = isset( $_POST['lld_app_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_app_key'] ) ) : '';
		$user_id = get_current_user_id();
		foreach ( self::keys( $user_id ) as $item ) {
			if ( hash_equals( (string) $item['uuid'], $uuid ) ) {
				WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			}
		}
		wp_safe_redirect( home_url( '/members/settings/?lld_app=withdrawn' ) );
		exit;
	}

	/**
	 * Sign in for an app key: { installation, email, password } or, after a two-step code, { installation, pending, code }.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function session( $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$install = isset( $params['installation'] ) ? (string) $params['installation'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $install ) ) {
			return new WP_REST_Response( array( 'message' => 'Send this installation\'s id.' ), 400 );
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_REST_Response( array( 'message' => 'Not available.' ), 403 );
		}
		$pending = isset( $params['pending'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $params['pending'] ) : '';
		if ( '' !== $pending ) {
			$code = isset( $params['code'] ) ? preg_replace( '/\D/', '', (string) $params['code'] ) : '';
			$user = self::finish_code( $pending, $code );
		} else {
			$email    = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
			$password = isset( $params['password'] ) ? (string) $params['password'] : '';
			$user     = self::check_sign_in( $email, $password );
		}
		if ( is_wp_error( $user ) ) {
			return self::sign_in_error( $user );
		}
		if ( ! $user instanceof WP_User || user_can( $user, 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => 'Not available.' ), 403 );
		}
		foreach ( self::keys( (int) $user->ID ) as $item ) {
			if ( self::APP_NAME . ' ' . $install === (string) $item['name'] ) {
				WP_Application_Passwords::delete_application_password( (int) $user->ID, (string) $item['uuid'] );
			}
		}
		$created = WP_Application_Passwords::create_new_application_password(
			(int) $user->ID,
			array(
				'name'   => self::APP_NAME . ' ' . $install,
				'app_id' => self::APP_ID,
			)
		);
		if ( is_wp_error( $created ) ) {
			return new WP_REST_Response( array( 'message' => $created->get_error_message() ), 400 );
		}
		$ends = LLD_Members_Clock::now( self::member_of( (int) $user->ID ) )->modify( '+' . self::KEY_DAYS . ' days' );
		return new WP_REST_Response(
			array(
				'login'      => $user->user_login,
				'password'   => $created[0],
				'expires_on' => $ends->format( 'Y-m-d' ),
			),
			200
		);
	}

	/**
	 * WordPress sign-in with this plugin's rules. An app key is never accepted as the password here.
	 *
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @return WP_User|WP_Error
	 */
	private static function check_sign_in( $email, $password ) {
		if ( ! is_email( $email ) || '' === $password ) {
			return new WP_Error( 'lld_details', LLD_Members_Auth::GENERIC );
		}
		$core     = has_filter( 'authenticate', 'wp_authenticate_application_password' );
		$redirect = has_action( 'wp_login_failed', array( 'LLD_Members_Auth', 'redirect_wp_login' ) );
		if ( false !== $core ) {
			remove_filter( 'authenticate', 'wp_authenticate_application_password', $core );
		}
		if ( false !== $redirect ) {
			remove_action( 'wp_login_failed', array( 'LLD_Members_Auth', 'redirect_wp_login' ), $redirect );
		}
		$user = wp_authenticate( $email, $password );
		if ( false !== $core ) {
			add_filter( 'authenticate', 'wp_authenticate_application_password', $core, 3 );
		}
		if ( false !== $redirect ) {
			add_action( 'wp_login_failed', array( 'LLD_Members_Auth', 'redirect_wp_login' ), $redirect, 2 );
		}
		if ( is_wp_error( $user ) && 'lld_two_step' === $user->get_error_code() ) {
			$token = isset( $_COOKIE['lld_2fa'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE['lld_2fa'] ) : '';
			return new WP_Error( 'lld_two_step', 'Enter the 6-digit code we emailed you.', array( 'pending' => $token ) );
		}
		return $user;
	}

	/**
	 * Check the emailed code for a pending app sign-in. No website session is left behind.
	 *
	 * @param string $pending Pending sign-in token.
	 * @param string $code    Six digits.
	 * @return WP_User|WP_Error
	 */
	private static function finish_code( $pending, $code ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, member_id FROM ' . LLD_Members_Schema::table( 'security_code' ) . " WHERE pending_token_hash = %s AND kind = 'two_step' AND used_at IS NULL ORDER BY id DESC LIMIT 1", hash( 'sha256', $pending ) ) );
		if ( ! $row ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$user_id = (int) $row->user_id;
		if ( ! $user_id && (int) $row->member_id ) {
			$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', (int) $row->member_id ) );
		}
		$candidate = get_user_by( 'id', $user_id );
		if ( ! $candidate || user_can( $candidate, 'manage_options' ) ) {
			return new WP_Error( 'lld_admin', 'Not available.' );
		}
		$_COOKIE['lld_2fa'] = $pending;
		$session            = '';
		$grab               = static function ( $cookie, $expire, $expiration, $uid, $scheme, $token ) use ( &$session ) {
			unset( $cookie, $expire, $expiration, $uid, $scheme );
			$session = (string) $token;
		};
		add_action( 'set_logged_in_cookie', $grab, 10, 6 );
		$done = LLD_Members_Auth::complete_code( $code );
		remove_action( 'set_logged_in_cookie', $grab, 10 );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		if ( '' !== $session ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy( $session );
		}
		$member_id = self::member_of( $user_id );
		if ( $member_id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'session' ) . ' SET ended_at = %s WHERE member_id = %d AND ended_at IS NULL ORDER BY id DESC LIMIT 1', gmdate( 'Y-m-d H:i:s' ), $member_id ) );
		}
		return $candidate;
	}

	/**
	 * @param WP_Error $error Sign-in error.
	 */
	private static function sign_in_error( $error ) {
		$code = $error->get_error_code();
		if ( 'lld_two_step' === $code ) {
			$data = $error->get_error_data();
			return new WP_REST_Response(
				array(
					'step'    => 'code',
					'pending' => is_array( $data ) && isset( $data['pending'] ) ? (string) $data['pending'] : '',
					'message' => $error->get_error_message(),
				),
				202
			);
		}
		if ( 'lld_locked' === $code ) {
			return new WP_REST_Response( array( 'message' => $error->get_error_message() ), 429 );
		}
		if ( 'lld_closed' === $code ) {
			return new WP_REST_Response( array( 'message' => $error->get_error_message() ), 503 );
		}
		if ( in_array( $code, array( 'lld_code', 'lld_admin' ), true ) ) {
			return new WP_REST_Response( array( 'message' => $error->get_error_message() ), 'lld_admin' === $code ? 403 : 401 );
		}
		return new WP_REST_Response( array( 'message' => LLD_Members_Auth::GENERIC ), 401 );
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
