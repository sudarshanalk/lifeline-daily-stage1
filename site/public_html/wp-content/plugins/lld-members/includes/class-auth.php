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
		self::issue_code( $user, false );
		return new WP_Error( 'lld_two_step', 'Enter the 6-digit code we emailed you.' );
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
	 * @param WP_User $user     User.
	 * @param bool    $remember Remember me through the code step.
	 */
	public static function issue_code( $user, $remember, $resend_count = 0 ) {
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
		self::issue_code( $user, (bool) $row->remember, (int) $row->resend_count + 1 );
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
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			LLD_Members_Schema::table( 'session' ),
			array(
				'member_id'  => $member_id,
				'token_hash' => hash( 'sha256', $token ),
				'remember'   => $remember ? 1 : 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( $remember ? 30 : 2 ) * DAY_IN_SECONDS ),
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
		$until = $wpdb->get_var( $wpdb->prepare( 'SELECT lock_until FROM ' . LLD_Members_Schema::table( 'sign_in_lock' ) . ' WHERE email = %s', $email ) );
		return is_string( $until ) && '' !== $until && $until > gmdate( 'Y-m-d H:i:s' );
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
		$lock  = $count >= self::FAIL_LIMIT ? gmdate( 'Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60 ) : null;
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
