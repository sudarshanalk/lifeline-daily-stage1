<?php
/**
 * Secure admin sign-in and limits (P3-87; NFR3-20, NFR3-21; data model: Admin session).
 *
 * - Every admin enters an emailed 6-digit code at every sign-in. The Owner already does (Stage 2, NFR2-02); a
 *   Support helper is sent through the same code step, and a helper's session that did not pass a code is ended.
 * - A fresh code is asked for before deleting a member, turning a feature back on for everyone, and adding or
 *   removing a helper (fresh_code_ok()).
 * - Admin sessions end after 30 minutes without activity and always after 12 hours.
 * - The Stage 2 lock (5 wrong passwords or codes, 15 minutes) applies; for an admin account the Owner is emailed.
 * - The Owner is emailed when an admin signs in from a new browser, and when a helper is added or removed.
 * - Each admin may delete at most 5 members an hour.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin sessions, devices, fresh codes and alerts.
 */
class LLD_Members_Admin_Security {

	const IDLE_SECONDS     = 1800;
	const MAX_SECONDS      = 43200;
	const DELETES_PER_HOUR = 5;
	const SESSIONS_META    = 'lld_admin_sessions';
	const DEVICE_COOKIE    = 'lld_adev';
	const TOUCH_EVERY      = 60;
	const OUT_COOKIE       = 'lld_admin_out';

	public static function boot() {
		add_filter( 'authenticate', array( __CLASS__, 'support_code' ), 100, 3 );
		add_action( 'set_auth_cookie', array( __CLASS__, 'session_started' ), 10, 6 );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'cookie_expiration' ), 20, 3 );
		add_action( 'init', array( __CLASS__, 'enforce_session' ), 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'failed' ), 5, 2 );
		add_action( 'shutdown', array( __CLASS__, 'check_code_lock' ) );
		add_filter( 'wp_redirect', array( __CLASS__, 'support_home' ), 10, 1 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'allow_helper_reset' ), 20, 2 );
		add_action( 'login_message', array( __CLASS__, 'timeout_message' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'no_helper_app_passwords' ), 20, 2 );
	}

	/**
	 * Seconds since the epoch. Tests move it with the lld_admin_now filter.
	 */
	public static function now() {
		return (int) apply_filters( 'lld_admin_now', time() );
	}

	/**
	 * A Support helper is sent to the emailed-code step at every sign-in, as the Owner is (TC-NFR3-20-AS-01).
	 *
	 * @param WP_User|WP_Error|null $user     User so far.
	 * @param string                $username Login.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function support_code( $user, $username, $password ) {
		unset( $username, $password );
		if ( ! $user instanceof WP_User || ! LLD_Members_Admin_Roles::is_support( $user ) ) {
			return $user;
		}
		LLD_Members_Auth::issue_code( $user, false, 0, '' );
		return new WP_Error( 'lld_two_step', 'Enter the 6-digit code we emailed you.' );
	}

	/**
	 * After the code step a helper has no member home: send them to the panel's Overview instead.
	 *
	 * @param string $location Redirect.
	 */
	public static function support_home( $location ) {
		if ( is_string( $location ) && 0 === strpos( $location, home_url( '/members/home/' ) ) && LLD_Members_Admin_Roles::is_support() ) {
			return admin_url( 'admin.php?page=lld-overview' );
		}
		return $location;
	}

	/**
	 * Helpers set their own password from WordPress's link (members reset theirs on the members pages).
	 *
	 * @param bool $allow   Allowed so far.
	 * @param int  $user_id User.
	 */
	public static function allow_helper_reset( $allow, $user_id ) {
		return LLD_Members_Admin_Roles::is_support( (int) $user_id ) ? true : $allow;
	}

	/**
	 * Helpers cannot use application passwords, which would skip the emailed code and the time limits.
	 *
	 * @param bool    $available Allowed so far.
	 * @param WP_User $user      User.
	 */
	public static function no_helper_app_passwords( $available, $user ) {
		return $user instanceof WP_User && LLD_Members_Admin_Roles::is_support( $user ) ? false : $available;
	}

	/**
	 * An admin session lasts at most 12 hours, even with "remember me".
	 *
	 * @param int  $length   Seconds.
	 * @param int  $user_id  User.
	 * @param bool $remember Remember me.
	 */
	public static function cookie_expiration( $length, $user_id, $remember ) {
		unset( $remember );
		return LLD_Members_Admin_Roles::is_admin_user( (int) $user_id ) ? min( (int) $length, self::MAX_SECONDS ) : $length;
	}

	/**
	 * True when this user's sign-in has just passed the emailed code (a two-step code used in the last 2 minutes).
	 *
	 * @param int $user_id User.
	 */
	public static function passed_code( $user_id ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 120 );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'security_code' ) . " WHERE user_id = %d AND kind = 'two_step' AND used_at IS NOT NULL AND used_at > %s LIMIT 1", (int) $user_id, $since ) );
	}

	/**
	 * A new admin session: record when it started, whether it passed a code, log the sign-in and check the browser.
	 *
	 * @param string $cookie     Cookie.
	 * @param int    $expire     Expiry.
	 * @param int    $expiration Expiration.
	 * @param int    $user_id    User.
	 * @param string $scheme     Scheme.
	 * @param string $token      Session token.
	 */
	public static function session_started( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		unset( $cookie, $expire, $expiration, $scheme );
		if ( ! LLD_Members_Admin_Roles::is_admin_user( (int) $user_id ) || '' === (string) $token ) {
			return;
		}
		$key = hash( 'sha256', (string) $token );
		if ( null !== self::session( (int) $user_id, $key ) ) {
			return;
		}
		self::prune( (int) $user_id );
		if ( isset( $_COOKIE[ self::OUT_COOKIE ] ) && ! headers_sent() ) {
			setcookie( self::OUT_COOKIE, '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', '', is_ssl(), true );
		}
		$now  = self::now();
		$code = self::passed_code( (int) $user_id );
		self::save_session(
			(int) $user_id,
			$key,
			array(
				'started' => $now,
				'last'    => $now,
				'code'    => $code,
			)
		);
		if ( $code ) {
			LLD_Members_Admin_Log::add( 'admin_sign_in', array( 'user' => (int) $user_id ) );
			self::check_device( (int) $user_id );
		}
	}

	/**
	 * One meta row per session, so two requests of the same admin never overwrite each other's record.
	 *
	 * @param int    $user_id User.
	 * @param string $key     Session hash.
	 * @return array{started:int,last:int,code:bool}|null
	 */
	private static function session( $user_id, $key ) {
		$row = get_user_meta( (int) $user_id, self::SESSIONS_META . '_' . $key, true );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int        $user_id User.
	 * @param string     $key     Session hash.
	 * @param array|null $row     Record, or null to remove it.
	 */
	private static function save_session( $user_id, $key, $row ) {
		if ( null === $row ) {
			delete_user_meta( (int) $user_id, self::SESSIONS_META . '_' . $key );
			return;
		}
		update_user_meta( (int) $user_id, self::SESSIONS_META . '_' . $key, $row );
	}

	/**
	 * Remove this admin's session records that are past the 12-hour limit.
	 *
	 * @param int $user_id User.
	 */
	private static function prune( $user_id ) {
		$now = self::now();
		foreach ( (array) get_user_meta( (int) $user_id ) as $meta => $values ) {
			if ( 0 !== strpos( (string) $meta, self::SESSIONS_META . '_' ) ) {
				continue;
			}
			$row = maybe_unserialize( $values[0] ?? '' );
			if ( ! is_array( $row ) || $now - (int) ( $row['started'] ?? 0 ) > self::MAX_SECONDS + DAY_IN_SECONDS ) {
				delete_user_meta( (int) $user_id, (string) $meta );
			}
		}
	}

	/**
	 * Why a session must end now: 'idle', 'max', 'no_code' or '' (TC-NFR3-20-AS-03).
	 *
	 * @param array|null $session  Session record.
	 * @param int        $now      Now.
	 * @param bool       $support  The user is a Support helper.
	 */
	public static function end_reason( $session, $now, $support ) {
		if ( ! is_array( $session ) ) {
			return $support ? 'no_code' : '';
		}
		if ( $support && empty( $session['code'] ) ) {
			return 'no_code';
		}
		if ( $now - (int) $session['started'] >= self::MAX_SECONDS ) {
			return 'max';
		}
		if ( $now - (int) $session['last'] >= self::IDLE_SECONDS ) {
			return 'idle';
		}
		return '';
	}

	/**
	 * On every request by a signed-in admin: end the session after 30 minutes idle or 12 hours, otherwise note the
	 * activity. Background heartbeat requests do not count as activity.
	 */
	public static function enforce_session() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! LLD_Members_Admin_Roles::is_admin_user( $user ) ) {
			return;
		}
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return;
		}
		$key     = hash( 'sha256', $token );
		$session = self::session( (int) $user->ID, $key );
		$now     = self::now();
		$support = LLD_Members_Admin_Roles::is_support( $user );
		if ( null === $session && ! $support ) {
			// An Owner session from before this package: its clock starts now.
			self::save_session( (int) $user->ID, $key, array( 'started' => $now, 'last' => $now, 'code' => false ) );
			return;
		}
		$why = self::end_reason( $session, $now, $support );
		if ( '' !== $why ) {
			self::save_session( (int) $user->ID, $key, null );
			WP_Session_Tokens::get_instance( (int) $user->ID )->destroy( $token );
			LLD_Members_Admin_Log::add( 'admin_signed_out', array( 'user' => (int) $user->ID, 'after' => $why ) );
			wp_clear_auth_cookie();
			wp_set_current_user( 0 );
			// Remember why for 5 minutes, so the sign-in page explains it even when a background request ended the
			// session and the page load arrives already signed out (WordPress then sends it to ?reauth=1). Kept on the
			// server under the ended session, because a browser may drop a cancelled request's cookie (WebKit does).
			set_transient( 'lld_admin_out_' . substr( $key, 0, 40 ), 'no_code' === $why ? 'code' : 'timeout', 300 );
			if ( ! headers_sent() ) {
				setcookie(
					self::OUT_COOKIE,
					'no_code' === $why ? 'code' : 'timeout',
					array(
						'expires'  => time() + 300,
						'path'     => COOKIEPATH ? COOKIEPATH : '/',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				wp_safe_redirect( wp_login_url() . ( false === strpos( wp_login_url(), '?' ) ? '?' : '&' ) . ( 'no_code' === $why ? 'lld_admin_code=1' : 'lld_admin_timeout=1' ) );
				exit;
			}
			return;
		}
		$heartbeat = wp_doing_ajax() && isset( $_POST['action'] ) && 'heartbeat' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only.
		if ( ! $heartbeat && $now - (int) $session['last'] >= self::TOUCH_EVERY ) {
			$session['last'] = $now;
			self::save_session( (int) $user->ID, $key, $session );
		}
	}

	/**
	 * The sign-in page after a time-out.
	 *
	 * @param string $message Message so far.
	 */
	public static function timeout_message( $message ) {
		$out = isset( $_COOKIE[ self::OUT_COOKIE ] ) ? (string) $_COOKIE[ self::OUT_COOKIE ] : '';
		if ( '' === $out ) {
			$out = self::ended_reason();
		}
		if ( isset( $_GET['lld_admin_timeout'] ) || 'timeout' === $out ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message .= '<p class="message">You were signed out for safety after 30 minutes without activity, or after 12 hours. Please sign in again.</p>';
		}
		if ( isset( $_GET['lld_admin_code'] ) || 'code' === $out ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message .= '<p class="message">Support helpers sign in here, with the 6-digit code we email you.</p>';
		}
		return $message;
	}

	/**
	 * Why the session in the browser's (now invalid) sign-in cookie was ended, if it was ended in the last 5 minutes.
	 */
	private static function ended_reason() {
		foreach ( array( 'logged_in' => defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : '', 'auth' => defined( 'AUTH_COOKIE' ) ? AUTH_COOKIE : '', 'secure_auth' => defined( 'SECURE_AUTH_COOKIE' ) ? SECURE_AUTH_COOKIE : '' ) as $scheme => $name ) {
			if ( '' === $name || empty( $_COOKIE[ $name ] ) ) {
				continue;
			}
			$parts = wp_parse_auth_cookie( (string) $_COOKIE[ $name ], $scheme );
			if ( is_array( $parts ) && ! empty( $parts['token'] ) ) {
				$why = get_transient( 'lld_admin_out_' . substr( hash( 'sha256', (string) $parts['token'] ), 0, 40 ) );
				if ( is_string( $why ) && '' !== $why ) {
					return $why;
				}
			}
		}
		return '';
	}

	/**
	 * A failed password for an admin account is logged; the 5th locks the account (Stage 2) and emails the Owner.
	 *
	 * @param string   $username Login.
	 * @param WP_Error $error    Error.
	 */
	public static function failed( $username, $error = null ) {
		if ( $error instanceof WP_Error && 'lld_two_step' === $error->get_error_code() ) {
			return;
		}
		$user = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );
		if ( ! $user || ! LLD_Members_Admin_Roles::is_admin_user( $user ) ) {
			return;
		}
		LLD_Members_Admin_Log::add( 'admin_sign_in_failed', array( 'user' => (int) $user->ID ) );
		self::alert_if_locked( $user );
	}

	/**
	 * Wrong codes are counted by Stage 2 against the same lock; after a code attempt, check it for admin accounts.
	 */
	public static function check_code_lock() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_COOKIE['lld_2fa'] ) || false === strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/members/two-step' ) ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id FROM ' . LLD_Members_Schema::table( 'security_code' ) . " WHERE pending_token_hash = %s AND kind = 'two_step' ORDER BY id DESC LIMIT 1", hash( 'sha256', (string) $_COOKIE['lld_2fa'] ) ) );
		$user = $row ? get_userdata( (int) $row->user_id ) : false;
		if ( $user && LLD_Members_Admin_Roles::is_admin_user( $user ) ) {
			self::alert_if_locked( $user );
		}
	}

	/**
	 * Email the Owner once per lock when an admin account is locked (TC-NFR3-20-AS-04).
	 *
	 * @param WP_User $user Admin.
	 */
	private static function alert_if_locked( $user ) {
		global $wpdb;
		$lock = $wpdb->get_row( $wpdb->prepare( 'SELECT fail_count, lock_until FROM ' . LLD_Members_Schema::table( 'sign_in_lock' ) . ' WHERE email = %s', strtolower( (string) $user->user_email ) ) );
		if ( ! $lock || (int) $lock->fail_count < LLD_Members_Auth::FAIL_LIMIT ) {
			return;
		}
		$key = 'lld_admin_lock_' . (int) $user->ID . '_' . md5( (string) $lock->lock_until );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		LLD_Members_Admin_Log::add( 'admin_sign_in_locked', array( 'user' => (int) $user->ID ) );
		self::alert_owner( 'An admin account was locked', 'After 5 wrong passwords or codes, sign-in for admin account #' . (int) $user->ID . ' (' . $user->display_name . ') is locked for 15 minutes. If this was not you or your helper, change that account\'s password.' );
	}

	/**
	 * A new browser for this admin emails the Owner (TC-NFR3-21-AS-02). The browser keeps a random cookie; only its
	 * hash is stored.
	 *
	 * @param int $user_id Admin.
	 */
	private static function check_device( $user_id ) {
		global $wpdb;
		$raw = isset( $_COOKIE[ self::DEVICE_COOKIE ] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE[ self::DEVICE_COOKIE ] ) : '';
		if ( 32 !== strlen( $raw ) ) {
			$raw = bin2hex( random_bytes( 16 ) );
			if ( ! headers_sent() ) {
				setcookie(
					self::DEVICE_COOKIE,
					$raw,
					array(
						'expires'  => time() + 400 * DAY_IN_SECONDS,
						'path'     => COOKIEPATH ? COOKIEPATH : '/',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			$_COOKIE[ self::DEVICE_COOKIE ] = $raw;
		}
		$hash  = hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
		$table = LLD_Members_Stage3_Schema::table( 'admin_devices' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE admin_user_id = %d AND device_hash = %s", (int) $user_id, $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			$wpdb->update( $table, array( 'last_active_at' => $now ), array( 'id' => (int) $id ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'admin_user_id'  => (int) $user_id,
				'device_hash'    => $hash,
				'started_at'     => $now,
				'last_active_at' => $now,
			)
		);
		$user = get_userdata( (int) $user_id );
		LLD_Members_Admin_Log::add( 'admin_new_device', array( 'user' => (int) $user_id ) );
		self::alert_owner( 'New browser sign-in to the admin panel', 'Admin account #' . (int) $user_id . ' (' . ( $user ? $user->display_name : '' ) . ') signed in from a browser it has not used before, at ' . $now . ' UTC. If this was not expected, change that account\'s password and remove the helper if needed.' );
	}

	/**
	 * Email the Owner (the site's admin email). No member details, no health details.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Text.
	 */
	public static function alert_owner( $subject, $body ) {
		$to = (string) get_option( 'admin_email' );
		if ( is_email( $to ) ) {
			wp_mail( $to, 'Lifeline Daily admin: ' . $subject, $body . "\n\nThis is an automatic security email from your Lifeline Daily admin panel.\n" );
		}
	}

	/**
	 * Email the current admin a fresh code for a risky action (NFR3-20).
	 *
	 * @return true|WP_Error
	 */
	public static function send_fresh_code() {
		$user = wp_get_current_user();
		if ( empty( $user->ID ) || ! LLD_Members_Admin_Roles::is_admin_user( $user ) ) {
			return new WP_Error( 'lld_admin_code', 'Sign in again.' );
		}
		$code = LLD_Members_Email_Codes::issue( (string) $user->user_email, 'admin_step' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		wp_mail(
			(string) $user->user_email,
			'Your Lifeline Daily admin confirmation code',
			"Your confirmation code is:\n\n{$code}\n\nEnter it in the admin panel to confirm the action you asked for. It works once and expires in 10 minutes.\n\nIf you didn't ask for it, don't enter it, and change your password.\n"
		);
		LLD_Members_Admin_Log::add( 'admin_code_sent' );
		return true;
	}

	/**
	 * Check and use up the fresh code the admin typed.
	 *
	 * @param string $typed Code typed.
	 * @return true|WP_Error
	 */
	public static function fresh_code_ok( $typed ) {
		$user = wp_get_current_user();
		if ( empty( $user->ID ) ) {
			return new WP_Error( 'lld_admin_code', 'Sign in again.' );
		}
		if ( '' === trim( (string) $typed ) ) {
			return new WP_Error( 'lld_admin_code', 'Enter the fresh code we email you. Use "Email me a code" first.' );
		}
		return LLD_Members_Email_Codes::verify( (string) $user->user_email, 'admin_step', (string) $typed );
	}

	/**
	 * May this admin delete another member now (TC-NFR3-21-AS-01)? Returns the time it is allowed again when not.
	 *
	 * @return true|WP_Error
	 */
	public static function may_delete() {
		$recent = LLD_Members_Admin_Log::recent( 'member_delete', LLD_Members_Admin_Log::actor() );
		if ( $recent['count'] < self::DELETES_PER_HOUR ) {
			return true;
		}
		$again = '' !== $recent['oldest'] ? gmdate( 'H:i', strtotime( $recent['oldest'] . ' UTC' ) + HOUR_IN_SECONDS ) : '';
		return new WP_Error( 'lld_admin_delete_limit', 'You have deleted ' . self::DELETES_PER_HOUR . ' members in the last hour. You can delete another at ' . $again . ' UTC.' );
	}
}
