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
}
