<?php
/**
 * Email codes for sign-up, sign-in and sensitive changes (P3-21: FR3-03, FR3-04, FR3-05, NFR3-18).
 *
 * A code is six digits, lasts 10 minutes, works once and allows five wrong tries. At most five codes an hour
 * are issued for one address, and at most thirty from one connection, so the forms cannot be used to flood other
 * people's inboxes. The code itself is never stored: only its hash (data model, Email code).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues, emails and checks codes in the email_codes table.
 */
class LLD_Members_Email_Codes {

	const MINUTES      = 10;
	const ATTEMPTS     = 5;
	const HOURLY_LIMIT = 5;
	const KEEP_HOURS   = 24;
	const PURPOSES     = array( 'sign_up', 'sign_in', 'delete' );

	/** Codes one connection may ask for in an hour, across every address. */
	const IP_HOURLY_LIMIT = 30;

	/**
	 * Shown when more than five codes were asked for in an hour (emails/stage3-emails.md, EM-23).
	 */
	const LIMIT_MESSAGE = "You've asked for several codes in the last hour. Please wait a while and try again, or sign in with your password.";

	/**
	 * Create a code for an address, store its hash and return the code for sending.
	 *
	 * @param string $email   Address, already validated.
	 * @param string $purpose sign_up, sign_in or delete.
	 * @return string|WP_Error The six digits, or lld_code_limit.
	 */
	public static function issue( $email, $purpose ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( '' === $email || ! in_array( $purpose, self::PURPOSES, true ) ) {
			return new WP_Error( 'lld_code_purpose', 'That code could not be sent.' );
		}
		$table = LLD_Members_Stage3_Schema::table( 'email_codes' );
		self::sweep();
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND created_at > %s", $email, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $recent >= self::HOURLY_LIMIT || ! self::count_connection() ) {
			return new WP_Error( 'lld_code_limit', self::LIMIT_MESSAGE );
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$now  = time();
		$ok   = $wpdb->insert(
			$table,
			array(
				'email'      => $email,
				'purpose'    => $purpose,
				'code_hash'  => password_hash( $code, PASSWORD_BCRYPT ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::MINUTES * MINUTE_IN_SECONDS ),
				'attempts'   => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'lld_code_store', 'That code could not be sent.' );
		}
		return $code;
	}

	/**
	 * Count a code against the connection that asked for it: false once it has asked for IP_HOURLY_LIMIT in the
	 * hour. Only a keyed hash of the address is kept, in a transient that ends with the hour.
	 *
	 * @return bool
	 */
	private static function count_connection() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // never a forwarded header, which anyone can set
		if ( '' === $ip ) {
			return true;
		}
		$key   = 'lld_s3_code_ip_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$count = get_transient( $key );
		if ( is_array( $count ) && isset( $count['n'], $count['until'] ) && (int) $count['until'] > time() ) {
			if ( (int) $count['n'] >= self::IP_HOURLY_LIMIT ) {
				return false;
			}
			$count['n'] = (int) $count['n'] + 1;
		} else {
			$count = array( 'n' => 1, 'until' => time() + HOUR_IN_SECONDS );
		}
		set_transient( $key, $count, max( 1, (int) $count['until'] - time() ) );
		return true;
	}

	/**
	 * Check a typed code against the newest unused code for the address and purpose, and use it up.
	 *
	 * @param string $email   Address.
	 * @param string $purpose sign_up, sign_in or delete.
	 * @param string $typed   What the member typed.
	 * @return true|WP_Error
	 */
	public static function verify( $email, $purpose, $typed ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		$typed = preg_replace( '/\D/', '', (string) $typed );
		$table = LLD_Members_Stage3_Schema::table( 'email_codes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND purpose = %s AND used_at IS NULL ORDER BY id DESC LIMIT 1", $email, $purpose ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			// The same answer as a wrong code, so nothing says whether a code exists for this address.
			return new WP_Error( 'lld_code_wrong', 'That code does not match. Check the email and try again.' );
		}
		if ( $row->expires_at < gmdate( 'Y-m-d H:i:s' ) ) {
			return new WP_Error( 'lld_code_expired', 'That code has expired. Ask for a new one.' );
		}
		if ( (int) $row->attempts >= self::ATTEMPTS ) {
			return new WP_Error( 'lld_code_tries', 'Too many wrong tries. Ask for a new code.' );
		}
		// The try is counted before the check, and only while tries remain, so parallel guesses cannot exceed five.
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d AND used_at IS NULL AND attempts < %d", (int) $row->id, self::ATTEMPTS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $counted ) {
			return new WP_Error( 'lld_code_tries', 'Too many wrong tries. Ask for a new code.' );
		}
		if ( 6 !== strlen( $typed ) || ! password_verify( $typed, (string) $row->code_hash ) ) {
			if ( (int) $row->attempts + 1 >= self::ATTEMPTS ) {
				return new WP_Error( 'lld_code_tries', 'Too many wrong tries. Ask for a new code.' );
			}
			return new WP_Error( 'lld_code_wrong', 'That code does not match. Check the email and try again.' );
		}
		$used = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $used ) {
			return new WP_Error( 'lld_code_used', 'That code has already been used. Ask for a new one.' );
		}
		return true;
	}

	/**
	 * Email a code with the approved wording (emails/stage3-emails.md EM-22 and EM-23; EM-23 for a deletion).
	 *
	 * @param string $email   Address.
	 * @param string $purpose sign_up, sign_in or delete.
	 * @param string $code    Six digits.
	 */
	public static function send( $email, $purpose, $code ) {
		if ( 'sign_up' === $purpose ) {
			$subject = 'Your Lifeline Daily sign-up code';
			$body    = "Thanks for joining Lifeline Daily. Your sign-up code is:\n\n{$code}\n\n"
				. "Enter it on the page where you signed up. It works once and expires in 10 minutes. After 5 wrong tries you'll need to ask for a new one.\n\n"
				. "We'll never ask you for this code by phone or message. If you didn't sign up, you can ignore this email and no account will be created.\n";
		} elseif ( 'delete' === $purpose ) {
			// Not in the approved email file yet: listed in the pull request for the owner's approval.
			$subject = 'Your Lifeline Daily confirmation code';
			$body    = "You asked to delete your Lifeline Daily account. Your confirmation code is:\n\n{$code}\n\n"
				. "Enter it on the Delete account page. It works once and expires in 10 minutes.\n\n"
				. "If you didn't ask to delete your account, don't enter the code, and sign out everywhere from your account page.\n";
		} else {
			$subject = 'Your Lifeline Daily sign-in code';
			$body    = "Your sign-in code is:\n\n{$code}\n\n"
				. "Enter it on the sign-in page. It works once and expires in 10 minutes.\n\n"
				. "We'll never ask you for this code by phone or message. If you didn't try to sign in, you can ignore this email. If it keeps happening, change your password from the sign-in page.\n";
		}
		return wp_mail( $email, $subject, $body );
	}

	/**
	 * Rows are kept 24 hours after creation (data model), then deleted.
	 */
	public static function sweep() {
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'email_codes' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_HOURS * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
