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
	 * Logo, 120 by 32 pixels, sent inside the email (cid:) so no remote image is loaded and Gmail still shows it.
	 */
	const LOGO_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAAAgCAIAAABW2ysfAAAAWElEQVR42u3ZoQ0AMAhFQTbpPqzC/poOAK5J1ZGnkGd/nEp9KBCABi3QoEGPVz8cUNCgQYMGDZopaNCgQYMGzRQ0aNCgQYMWaNCgQYM2zgo0aNACDVoL9AWaFMzyBkvdrAAAAABJRU5ErkJggg==';

	/**
	 * True while WordPress is sending an email that this plugin wrote. Other plugins' mail is left as it is.
	 *
	 * @var bool
	 */
	private static $own = false;

	/**
	 * Runs at the start of every wp_mail() call: note whether the caller is this plugin.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array
	 */
	public static function mark( $args ) {
		self::$own = self::called_by_plugin();
		return $args;
	}

	private static function called_by_plugin() {
		$dir = wp_normalize_path( LLD_MEMBERS_DIR );
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( isset( $frame['function'], $frame['file'] ) && 'wp_mail' === $frame['function'] && empty( $frame['class'] ) ) {
				return 0 === strpos( wp_normalize_path( (string) $frame['file'] ), $dir );
			}
		}
		return false;
	}

	/**
	 * Point WordPress mail at Mailpit when LLD_SMTP_HOST is set and this is not production,
	 * then give this plugin's own emails the UI2-04 layout. Mail from WordPress or other plugins is not changed.
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
		$own       = self::$own;
		self::$own = false;
		if ( ! $own ) {
			return;
		}
		// This plugin writes plain text. Another plugin (Paid Memberships Pro) may set every email to text/html
		// and add paragraph tags, so the text is recovered from the HTML in that case.
		$plain = (string) $phpmailer->Body;
		if ( 'text/plain' !== (string) $phpmailer->ContentType || str_contains( $plain, '<' ) ) {
			$plain = html_entity_decode( wp_strip_all_tags( $plain ), ENT_QUOTES, 'UTF-8' );
		}
		$plain = trim( $plain );
		$phpmailer->isHTML( true );
		$phpmailer->AltBody = $plain;
		$phpmailer->Body    = self::layout( (string) $phpmailer->Subject, $plain );
		$phpmailer->addStringEmbeddedImage( (string) base64_decode( self::LOGO_PNG ), 'lld-logo', 'lifeline-daily.png', 'base64', 'image/png' );
	}

	/**
	 * At most 600 pixels wide, the logo, the text, one button for the main link, and no remote images or scripts.
	 *
	 * @param string $subject Subject, used as the button label.
	 * @param string $plain   Plain-text body.
	 */
	public static function layout( $subject, $plain ) {
		$button = '';
		$parts  = array();
		foreach ( preg_split( '/\r\n|\n/', $plain ) as $line ) {
			if ( preg_match( '#^(.*?)(https?://\S+)\s*$#', $line, $match ) ) {
				$url = esc_url( $match[2] );
				if ( '' === $button && false === stripos( $match[1] . $match[2], 'unsubscribe' ) ) {
					$button  = '<p style="margin:24px 0"><a href="' . $url . '" style="display:inline-block;background:#1f6b4a;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">' . esc_html( '' !== $subject ? $subject : 'Open' ) . '</a></p>';
					$parts[] = '' !== trim( $match[1] ) ? '<p style="margin:0 0 12px">' . esc_html( trim( $match[1] ) ) . '</p>' . $button : $button;
					continue;
				}
				$parts[] = '<p style="margin:0 0 12px">' . esc_html( $match[1] ) . '<a href="' . $url . '" style="color:#1f6b4a">' . esc_html( $match[2] ) . '</a></p>';
				continue;
			}
			$parts[] = '' === trim( $line ) ? '' : '<p style="margin:0 0 12px">' . esc_html( $line ) . '</p>';
		}
		$logo = '<img alt="Lifeline Daily" width="120" height="32" src="cid:lld-logo" style="display:block;border:0">';
		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f1">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px 16px;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1d1d1b">'
			. $logo . '<div style="margin-top:20px">' . implode( '', $parts ) . '</div></div></body></html>';
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

	public static function send_cancel_alert( $member_id ) {
		return wp_mail( get_option( 'admin_email' ), 'Stripe cancellation failed', "Stripe did not accept cancellation for member {$member_id}. Please cancel it by hand within 1 working day.\n" );
	}
}
