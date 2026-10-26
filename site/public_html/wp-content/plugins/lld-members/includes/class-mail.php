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
		if ( ! lld_members_is_production() && defined( 'LLD_SMTP_HOST' ) && '' !== (string) LLD_SMTP_HOST ) {
			$phpmailer->isSMTP();
			$phpmailer->Host        = (string) LLD_SMTP_HOST;
			$phpmailer->Port        = defined( 'LLD_SMTP_PORT' ) ? (int) LLD_SMTP_PORT : 1025;
			$phpmailer->SMTPAuth    = false;
			$phpmailer->SMTPAutoTLS = false;
			$phpmailer->SMTPSecure  = '';
		}
		$body = (string) $phpmailer->Body;
		if ( str_contains( $body, 'max-width:600px' ) ) {
			return;
		}
		$plain = trim( (string) $phpmailer->AltBody );
		if ( '' === $plain || str_contains( $plain, '<' ) ) {
			$plain = trim( wp_strip_all_tags( $body ) );
		}
		$phpmailer->isHTML( true );
		$phpmailer->AltBody = $plain;
		$logo = '<img alt="Lifeline Daily" width="120" height="32" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%2232%22%3E%3Crect width=%22120%22 height=%2232%22 fill=%22%231f6b4a%22/%3E%3C/svg%3E">';
		$phpmailer->Body    = '<div style="max-width:600px">' . $logo . '<pre style="white-space:pre-wrap">' . esc_html( $plain ) . '</pre></div>';
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
	/**
	 * @param string $email      Address.
	 * @param string $first_name First name. Not included: the note is not about health.
	 */
	public static function send_profile_reminder( $email, $first_name ) {
		unset( $first_name );
		return wp_mail( $email, 'Complete your profile', "Complete your Lifeline Daily profile:\n\n" . home_url( '/members/health/' ) . "\n" );
	}

	/**
	 * @param string $email Address.
	 * @param string $title Goal title.
	 */
	public static function send_goal_live( $email, $title ) {
		return wp_mail( $email, 'A goal you asked for is now available', "A goal you asked for is now available: {$title}\n\n" . home_url( '/members/goals/' ) . "\n" );
	}

	/**
	 * @param string $subject Subject.
	 * @param string $body    Note for the owner.
	 */
	public static function send_owner_note( $subject, $body ) {
		return wp_mail( get_option( 'admin_email' ), $subject, $body );
	}

	/**
	 * @param int    $member_id    Member number.
	 * @param string $subscription Stripe subscription reference, kept here because the account is deleted.
	 * @param string $customer     Stripe customer reference.
	 */
	public static function send_cancel_alert( $member_id, $subscription = '', $customer = '' ) {
		$ref  = '' !== (string) $subscription ? (string) $subscription : 'none recorded';
		$body = "Stripe did not accept cancellation for member {$member_id}. Please cancel it by hand within 1 working day.\n\n";
		$body .= "Stripe subscription: {$ref}\n";
		if ( '' !== (string) $customer ) {
			$body .= "Stripe customer: {$customer}\n";
		}
		return wp_mail( get_option( 'admin_email' ), 'Stripe cancellation failed', $body );
	}
}
