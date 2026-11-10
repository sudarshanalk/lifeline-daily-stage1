<?php
/**
 * Sign-in limits and two-step email codes (NFR2-02, FR2-01 limits).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Passwords stay in WordPress, hashed. Codes are stored only as hashes.
 */
class LLD_Members_Auth {

	const FAIL_LIMIT   = 5;
	const LOCK_MINUTES = 15;
	const CODE_MINUTES = 10;
	const RESEND_LIMIT = 3;

	/**
	 * Hash checked when the email is unknown, so the answer takes the same time.
	 */
	const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

	/**
	 * The same sentence for a wrong password and an unknown email.
	 */
	const GENERIC = 'Those details don\'t match our records.';

	/**
	 * Block WordPress's own login from completing when an email code is required.
	 *
	 * @param WP_User|WP_Error|null $user     User so far.
	 * @param string                $username Login.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function require_code( $user, $username, $password ) {
		unset( $username, $password );
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		if ( ! self::needs_two_step( $user ) ) {
			return $user;
		}
		self::issue_code( $user, false, 0, self::login_redirect() );
		return new WP_Error( 'lld_two_step', 'Enter the 6-digit code we emailed you.' );
	}

	/**
	 * Where the sign-in started from wp-login.php wanted to go (its redirect_to), when that address is safe.
	 */
	private static function login_redirect() {
		if ( 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) || empty( $_REQUEST['redirect_to'] ) ) {
			return '';
		}
		return (string) wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ), '' );
	}

	/**
	 * The redirect_to kept for a pending code, under the hash of its pending token (never the token itself).
	 *
	 * @param string $token Pending token from the lld_2fa cookie.
	 */
	private static function redirect_key( $token ) {
		return 'lld_2fa_to_' . substr( hash( 'sha256', (string) $token ), 0, 40 );
	}

	/**
	 * The redirect_to of the sign-in that complete_code() just finished, already checked.
	 *
	 * @var string
	 */
	private static $code_redirect = '';

	/**
	 * Where to go after a correct code. An administrator goes to wp-admin, or to the safe redirect_to that
	 * wp-login.php carried. A member goes to Today, or to a safe redirect_to outside wp-admin and wp-login.php.
	 * An administrator may have no member record, so Today would only send them back to sign-in.
	 */
	public static function after_code_destination() {
		$home = home_url( '/members/home/' );
		$to   = self::$code_redirect;
		if ( current_user_can( 'manage_options' ) ) {
			return '' !== $to ? (string) wp_validate_redirect( $to, admin_url() ) : admin_url();
		}
		if ( '' === $to || 0 === strpos( $to, admin_url() ) || false !== strpos( $to, 'wp-login.php' ) ) {
			return $home;
		}
		return (string) wp_validate_redirect( $to, $home );
	}

	/**
	 * Refuse every WordPress sign-in (wp-login.php, XML-RPC, application passwords) until the
	 * member has confirmed their email (FR2-01). Accounts without a member record are not members.
	 *
	 * @param WP_User|WP_Error|null $user User so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function require_confirmed( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, email_confirmed_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
		if ( $row && empty( $row->email_confirmed_at ) ) {
			return new WP_Error( 'lld_unconfirmed', 'Confirm your email before you sign in.' );
		}
		return $user;
	}

	/**
	 * WordPress's lost-password page sends members to the Lifeline Daily reset (SCR-05), which signs
	 * out every other device. A posted request from a member is handled there, with the same reply
	 * for every email. Only an administrator's own request stays with WordPress.
	 */
	public static function lost_password_page() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			wp_safe_redirect( home_url( '/members/reset/' ) );
			exit;
		}
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			return;
		}
		if ( $user ) {
			LLD_Members_Account::send_reset_link( (string) $user->user_email );
		}
		wp_safe_redirect( home_url( '/members/reset/?sent=1' ) );
		exit;
	}

	/**
	 * WordPress reset keys are for administrators only. Members reset through SCR-05.
	 *
	 * @param bool $allow   Whether WordPress would allow a reset.
	 * @param int  $user_id User id.
	 */
	public static function allow_password_reset( $allow, $user_id ) {
		if ( ! user_can( (int) $user_id, 'manage_options' ) ) {
			return false;
		}
		return $allow;
	}

	/**
	 * Every "Forgot password?" link points at the members reset page.
	 */
	public static function lost_password_url() {
		return home_url( '/members/reset/' );
	}

	/**
	 * After an administrator's own WordPress reset request, show the same page as everyone else.
	 */
	public static function lost_password_redirect() {
		return home_url( '/members/reset/?sent=1' );
	}

	/**
	 * Send wp-login.php on to the code screen instead of signing the admin in.
	 *
	 * @param string   $username Login.
	 * @param WP_Error $error    Error from authenticate.
	 */
	public static function redirect_wp_login( $username, $error ) {
		unset( $username );
		if ( $error instanceof WP_Error && 'lld_two_step' === $error->get_error_code() ) {
			wp_safe_redirect( home_url( '/members/two-step/' ) );
			exit;
		}
	}

	/**
	 * Remember me lasts 30 days. Without it, the cookie ends when the browser closes.
	 *
	 * @param int  $length   Seconds.
	 * @param int  $user_id  User id.
	 * @param bool $remember Remember me.
	 */
	public static function cookie_expiration( $length, $user_id, $remember ) {
		unset( $user_id );
		if ( $remember ) {
			return 30 * DAY_IN_SECONDS;
		}
		return $length;
	}

	/**
	 * Owner admins always use an email code. Members use one after they opt in.
	 *
	 * @param WP_User $user User.
	 */
	public static function needs_two_step( $user ) {
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT role, two_step FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
		if ( ! $row ) {
			return false;
		}
		if ( 'owner_admin' === $row->role ) {
			return true;
		}
		return '1' === (string) $row->two_step;
	}

	/**
	 * Administrators cannot use application passwords. Those skip the email code.
	 *
	 * @param bool    $available Whether WordPress would allow an application password.
	 * @param WP_User $user      User.
	 */
	public static function application_passwords( $available, $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
			return false;
		}
		return $available;
	}

	/**
	 * Pause sign-in for every email after repeated failures, including wp-login.php and xmlrpc.php.
	 *
	 * @param WP_User|WP_Error|null $user     User so far.
	 * @param string                $username Login.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function enforce_lock( $user, $username, $password ) {
		$email = self::login_email( (string) $username );
		if ( '' !== $email && self::is_locked( $email ) ) {
			return new WP_Error( 'lld_locked', 'Sign-in is paused. Try again in 15 minutes.' );
		}
		if ( $user instanceof WP_User ) {
			if ( '' !== $email ) {
				self::clear_failures( $email );
			}
			return $user;
		}
		if ( '' === $email ) {
			return $user;
		}
		if ( ! get_user_by( 'email', $email ) && ! get_user_by( 'login', (string) $username ) ) {
			wp_check_password( (string) $password, self::DUMMY_HASH );
		}
		self::record_failure( $email );
		return $user;
	}

	/**
	 * Refuse member sign-in while a restore is being checked.
	 *
	 * @param WP_User|WP_Error|null $user User so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_when_closed( $user ) {
		if ( ! get_option( 'lld_members_closed' ) ) {
			return $user;
		}
		if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
			return $user;
		}
		return new WP_Error( 'lld_closed', 'Lifeline Daily is closed' );
	}

	/**
	 * Check a password without revealing whether the email exists, and honour the 15-minute pause.
	 *
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @return WP_User|WP_Error
	 */
	public static function check_password( $email, $password ) {
		$email = sanitize_email( $email );
		if ( self::is_locked( $email ) ) {
			return new WP_Error( 'lld_locked', 'Sign-in is paused. Try again in 15 minutes.' );
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			wp_check_password( $password, self::DUMMY_HASH );
			self::record_failure( $email );
			return new WP_Error( 'lld_details', self::GENERIC );
		}
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			self::record_failure( $email );
			return new WP_Error( 'lld_details', self::GENERIC );
		}
		self::clear_failures( $email );
		return $user;
	}

	/**
	 * Create a 6-digit code, store its hash, and email the code.
	 *
	 * @param WP_User $user         User.
	 * @param bool    $remember     Remember me through the code step.
	 * @param int     $resend_count Codes already sent for this sign-in.
	 * @param string  $redirect     Safe redirect_to from wp-login.php, kept until the code is entered.
	 */
	public static function issue_code( $user, $remember, $resend_count = 0, $redirect = '' ) {
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
		$code      = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$token     = bin2hex( random_bytes( 16 ) );
		$expires   = LLD_Members_Clock::now( $member_id )->modify( '+' . self::CODE_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
		$wpdb->insert(
			LLD_Members_Schema::table( 'security_code' ),
			array(
				'member_id'          => $member_id,
				'user_id'            => (int) $user->ID,
				'kind'               => 'two_step',
				'code_hash'          => password_hash( $code, PASSWORD_DEFAULT ),
				'pending_token_hash' => hash( 'sha256', $token ),
				'remember'           => $remember ? 1 : 0,
				'attempts'           => 0,
				'resend_count'       => (int) $resend_count,
				'expires_at'         => $expires,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		$cookie = array(
			'expires'  => time() + self::CODE_MINUTES * 60,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) {
			$cookie['domain'] = COOKIE_DOMAIN;
		}
		setcookie( 'lld_2fa', $token, $cookie );
		$_COOKIE['lld_2fa'] = $token;
		if ( '' !== (string) $redirect ) {
			set_transient( self::redirect_key( $token ), (string) $redirect, self::CODE_MINUTES * MINUTE_IN_SECONDS );
		}
		if ( $user->user_email ) {
			LLD_Members_Mail::send_sign_in_code( $user->user_email, $code );
		}
	}

	/**
	 * Replace the unused code with a new one for the same sign-in attempt.
	 *
	 * @return true|WP_Error
	 */
	public static function resend_code() {
		global $wpdb;
		$token = isset( $_COOKIE['lld_2fa'] ) ? (string) $_COOKIE['lld_2fa'] : '';
		if ( '' === $token ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$table = LLD_Members_Schema::table( 'security_code' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE pending_token_hash = %s AND kind = %s AND used_at IS NULL ORDER BY id DESC LIMIT 1",
				hash( 'sha256', $token ),
				'two_step'
			)
		);
		$now = LLD_Members_Clock::now( $row ? (int) $row->member_id : 0 )->format( 'Y-m-d H:i:s' );
		if ( ! $row || $row->expires_at < $now ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		if ( (int) $row->resend_count >= self::RESEND_LIMIT ) {
			return new WP_Error( 'lld_code', 'We can\'t send another code. Sign in again.' );
		}
		$marked = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $row->id ) );
		if ( 1 !== (int) $marked ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user && (int) $row->member_id ) {
			$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $row->member_id ) );
			$user    = get_user_by( 'id', $user_id );
		}
		if ( ! $user ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$redirect = (string) get_transient( self::redirect_key( $token ) );
		delete_transient( self::redirect_key( $token ) );
		self::issue_code( $user, (bool) $row->remember, (int) $row->resend_count + 1, $redirect );
		return true;
	}

	/**
	 * Finish sign-in when the emailed code matches.
	 *
	 * @param string $code Six digits.
	 * @return true|WP_Error
	 */
	public static function complete_code( $code ) {
		global $wpdb;
		$token = isset( $_COOKIE['lld_2fa'] ) ? (string) $_COOKIE['lld_2fa'] : '';
		if ( '' === $token ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$table = LLD_Members_Schema::table( 'security_code' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE pending_token_hash = %s AND kind = %s AND used_at IS NULL ORDER BY id DESC LIMIT 1",
				hash( 'sha256', $token ),
				'two_step'
			)
		);
		$now = LLD_Members_Clock::now( $row ? (int) $row->member_id : 0 )->format( 'Y-m-d H:i:s' );
		if ( ! $row || $row->expires_at < $now ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$email = self::email_for_code( $row );
		if ( '' !== $email && self::is_locked( $email ) ) {
			return new WP_Error( 'lld_locked', 'Sign-in is paused. Try again in 15 minutes.' );
		}
		if ( ! password_verify( $code, $row->code_hash ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d AND used_at IS NULL", $row->id ) );
			if ( '' !== $email ) {
				self::record_failure( $email );
			}
			return new WP_Error( 'lld_code', 'That code does not match. Try again.' );
		}
		$marked = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $row->id ) );
		if ( 1 !== (int) $marked ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		$user_id   = (int) $row->user_id;
		$member_id = (int) $row->member_id;
		if ( ! $user_id && $member_id ) {
			$member  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
			$user_id = $member ? (int) $member->user_id : 0;
		}
		if ( ! $user_id ) {
			return new WP_Error( 'lld_code', 'That code has expired. Sign in again to get a new one.' );
		}
		self::start_session( $user_id, $member_id, (bool) $row->remember );
		setcookie( 'lld_2fa', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		$redirect = get_transient( self::redirect_key( $token ) );
		delete_transient( self::redirect_key( $token ) );
		self::$code_redirect = is_string( $redirect ) ? (string) wp_validate_redirect( $redirect, '' ) : '';
		return true;
	}

	/**
	 * Open a WordPress session and record it.
	 *
	 * @param int  $user_id   WordPress user id.
	 * @param int  $member_id Member number.
	 * @param bool $remember  30-day cookie.
	 */
	public static function start_session( $user_id, $member_id, $remember ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember );
		if ( ! $member_id ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'member' ),
			array( 'last_signed_in_at' => lld_members_now_mysql( $member_id ) ),
			array( 'id' => $member_id ),
			array( '%s' ),
			array( '%d' )
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'email_pref' ) . ' SET nudges_sent = 0, last_nudge_on = NULL WHERE member_id = %d', $member_id ) );
		$token = bin2hex( random_bytes( 16 ) );
		$until = LLD_Members_Clock::now( $member_id )->modify( $remember ? '+30 days' : '+12 hours' )->format( 'Y-m-d H:i:s' );
		$wpdb->insert(
			LLD_Members_Schema::table( 'session' ),
			array(
				'member_id'  => $member_id,
				'token_hash' => hash( 'sha256', $token ),
				'remember'   => $remember ? 1 : 0,
				'expires_at' => $until,
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * True when every stored WordPress password is a hash, not readable text.
	 *
	 * @return array{all_hashed:bool,count:int,prefixes:string[]}
	 */
	public static function password_storage() {
		global $wpdb;
		$rows     = $wpdb->get_col( "SELECT user_pass FROM {$wpdb->users}" );
		$prefixes = array();
		$ok       = true;
		foreach ( $rows as $hash ) {
			$hashed = is_string( $hash ) && ( 0 === strpos( $hash, '$wp$' ) || 0 === strpos( $hash, '$P$' ) || 0 === strpos( $hash, '$2y$' ) || 0 === strpos( $hash, '$2a$' ) );
			if ( ! $hashed ) {
				$ok = false;
			}
			$prefixes[] = substr( (string) $hash, 0, 4 );
		}
		return array(
			'all_hashed' => $ok && count( $rows ) > 0,
			'count'      => count( $rows ),
			'prefixes'   => array_values( array_unique( $prefixes ) ),
		);
	}

	/**
	 * @param string $email Email.
	 */
	private static function is_locked( $email ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT fail_count, lock_until FROM ' . LLD_Members_Schema::table( 'sign_in_lock' ) . ' WHERE email = %s', $email ) );
		if ( ! $row || (int) $row->fail_count < self::FAIL_LIMIT || ! is_string( $row->lock_until ) || '' === $row->lock_until ) {
			return false;
		}
		return $row->lock_until > self::moment( $email )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param string $email Email.
	 */
	private static function record_failure( $email ) {
		if ( '' === $email ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'sign_in_lock' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT fail_count FROM {$table} WHERE email = %s", $email ) );
		$count = $row ? (int) $row->fail_count + 1 : 1;
		$when  = self::moment( $email );
		$lock  = $count >= self::FAIL_LIMIT ? $when->modify( '+' . self::LOCK_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' ) : $when->modify( '+24 hours' )->format( 'Y-m-d H:i:s' );
		$wpdb->replace(
			$table,
			array(
				'email'      => $email,
				'fail_count' => $count,
				'lock_until' => $lock,
			),
			array( '%s', '%d', '%s' )
		);
	}

	/**
	 * @param string $email Email.
	 */
	private static function clear_failures( $email ) {
		global $wpdb;
		$wpdb->delete( LLD_Members_Schema::table( 'sign_in_lock' ), array( 'email' => $email ), array( '%s' ) );
	}

	/**
	 * Email address to count, whether or not an account exists.
	 *
	 * @param string $username Typed login.
	 */
	/**
	 * Clock for this email, so a test member's pause follows the test clock.
	 *
	 * @param string $email Email.
	 */
	private static function moment( $email ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		return LLD_Members_Clock::now( $id );
	}

	private static function login_email( $username ) {
		$username = trim( $username );
		if ( '' === $username ) {
			return '';
		}
		if ( is_email( $username ) ) {
			return sanitize_email( $username );
		}
		$user = get_user_by( 'login', $username );
		if ( $user ) {
			return (string) $user->user_email;
		}
		return sanitize_text_field( $username );
	}

	/**
	 * @param object $row Security-code row.
	 */
	private static function email_for_code( $row ) {
		$user = get_user_by( 'id', (int) $row->user_id );
		if ( $user ) {
			return (string) $user->user_email;
		}
		if ( ! (int) $row->member_id ) {
			return '';
		}
		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $row->member_id ) );
		return is_string( $email ) ? $email : '';
	}
}
