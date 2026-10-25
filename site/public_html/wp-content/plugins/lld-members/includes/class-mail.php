<?php
/**
 * Mail through Mailpit outside production. Nothing health-related is put in an email by this plugin.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SMTP settings from wp-config.php.
 */
class LLD_Members_Mail {

	/**
	 * Point WordPress mail at Mailpit when LLD_SMTP_HOST is set and this is not production.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public static function configure( $phpmailer ) {
		if ( lld_members_is_production() || ! defined( 'LLD_SMTP_HOST' ) || '' === (string) LLD_SMTP_HOST ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host        = (string) LLD_SMTP_HOST;
		$phpmailer->Port        = defined( 'LLD_SMTP_PORT' ) ? (int) LLD_SMTP_PORT : 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPAutoTLS = false;
		$phpmailer->SMTPSecure  = '';
	}

	/**
	 * WordPress rejects wordpress@localhost before phpmailer_init runs, so local and CI mail never leaves.
	 *
	 * @param string $email Address WordPress would use.
	 */
	public static function from_email( $email ) {
		if ( lld_members_is_production() ) {
			return $email;
		}
		return 'noreply@lifelinedaily.invalid';
	}

	/**
	 * @param string $name Name WordPress would use.
	 */
	public static function from_name( $name ) {
		if ( lld_members_is_production() ) {
			return $name;
		}
		return 'Lifeline Daily';
	}

	/**
	 * Send a sign-in code. The message contains the code and nothing else about the member.
	 *
	 * @param string $email Member email.
	 * @param string $code  Six digits.
	 */
	public static function send_sign_in_code( $email, $code ) {
		$body = "Your Lifeline Daily sign-in code is {$code}.\n\nIt expires in 10 minutes. If you did not try to sign in, you can ignore this email.\n";
		return wp_mail( $email, 'Your Lifeline Daily sign-in code', $body );
	}

	/**
	 * @param string $email Address.
	 * @param string $link  Confirmation link.
	 */
	public static function send_confirm( $email, $link ) {
		return wp_mail( $email, 'Confirm your email', "Confirm your Lifeline Daily email {$email}:\n\n{$link}\n\nThis link works once and expires in 24 hours.\n" );
	}

	/**
	 * @param string $email Address.
	 * @param string $link  Reset link.
	 */
	public static function send_reset( $email, $link ) {
		return wp_mail( $email, 'Reset your password', "Reset your Lifeline Daily password:\n\n{$link}\n\nThis link works once and expires in 60 minutes.\n" );
	}

	/**
	 * @param string $new_email New address.
	 * @param string $old_email Current address.
	 * @param string $link      Confirmation link.
	 */
	public static function send_email_change( $new_email, $old_email, $link ) {
		wp_mail( $old_email, 'Email change requested', "We received a request to change your Lifeline Daily email. Your current email keeps working until you confirm the new one.\n" );
		return wp_mail( $new_email, 'Confirm your new email address', "Confirm this email address for Lifeline Daily:\n\n{$link}\n\nThis link expires in 24 hours.\n" );
	}

	/**
	 * @param string $email      Address.
	 * @param string $first_name First name only.
	 */
	public static function send_account_deleted( $email, $first_name ) {
		return wp_mail( $email, 'Your account is closed', "Hello {$first_name},\n\nYour Lifeline Daily account is closed. Any subscription cancellation is confirmed separately once our payment provider accepts it.\n" );
	}

	/**
	 * @param string $email       Address.
	 * @param string $first_name  First name.
	 * @param string $delete_date Deletion date.
	 */
	public static function send_inactive_warning( $email, $first_name, $delete_date ) {
		unset( $first_name );
		return wp_mail( $email, 'Your account will be deleted', "Your free Lifeline Daily account is due to be deleted on {$delete_date}.\n\nSign in to keep your account: " . home_url( '/members/sign-in/' ) . "\n" );
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function send_cancel_alert( $member_id ) {
		return wp_mail( get_option( 'admin_email' ), 'Stripe cancellation failed', "Stripe did not accept cancellation for member {$member_id}. Please cancel it by hand within 1 working day.\n" );
	}
}
