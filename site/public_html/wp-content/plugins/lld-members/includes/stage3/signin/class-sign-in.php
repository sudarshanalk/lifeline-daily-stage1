<?php
/**
 * Stage 3 sign-in: email codes at sign-up and sign-in, Google and Apple, one account per email
 * (P3-20 to P3-22: FR3-01 to FR3-05, NFR3-18).
 *
 * Nothing here edits a Stage 2 file. The class takes over the Stage 2 screens join, confirm, sign-in and delete
 * from template_redirect priority 4 (the Stage 2 dispatcher runs at 10) only when a switch is on for the
 * request; otherwise it returns and Stage 2 answers exactly as before (NFR3-06). Switch states:
 *   sign_in_code    everyone: the code flows replace the confirmation link for all; test_accounts: only for the
 *                   listed addresses, and only when the page is opened with ?code=1 (so the Stage 2 suite is untouched)
 *   sign_in_social  everyone: provider buttons on the sign-in and join pages for all; test_accounts: provider
 *                   sign-ins complete only for listed addresses
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screens, routes and account rules for the Stage 3 ways to sign in.
 */
class LLD_Members_Sign_In {

	const META_PASSWORDLESS = 'lld_members_passwordless';
	const COOKIE_PENDING    = 'lld_s3_pending';
	const COOKIE_OAUTH      = 'lld_s3_oauth';
	const PENDING_SECONDS   = 900;
	const OAUTH_SECONDS     = 600;

	/**
	 * Messages for ?provider_error= on the sign-in page and ?s3= on the hub's security section. Member-facing
	 * wording listed in the pull request for the owner's approval.
	 */
	const MESSAGES = array(
		'unavailable'      => 'That way of signing in is not available right now. Sign in with your email instead.',
		'cancelled'        => 'The sign-in was cancelled. You can try again or sign in with your email.',
		'mismatch'         => 'That sign-in could not be matched to this browser. Please try again.',
		'failed'           => 'The sign-in did not complete. Please try again.',
		'unverified'       => "We couldn't confirm the email on that account. Sign in with your email and password first, then link it from your account page.",
		'linked_elsewhere' => 'That account is already linked to another Lifeline Daily member.',
		'owner'            => 'The owner admin signs in with a password and code.',
		'two_step'         => 'This account uses two-step sign-in: sign in with your password, then the emailed code.',
		'already_linked'   => 'An account from that service is already linked. Unlink it first to link a different one.',
		'not_open'         => 'That way of signing in is not open to this account yet.',
		'linked'           => 'Linked. You can now sign in with it.',
		'unlinked'         => 'Unlinked.',
		'last'             => "You can't remove your only way to sign in. Set a password or link another account first.",
		'signed_out_all'   => "You're signed out everywhere.",
	);

	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) ) {
			return;
		}
		if ( ! self::on( 'sign_in_code' ) && ! self::on( 'sign_in_social' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'screens' ), 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_set_password', array( __CLASS__, 'password_set' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'password_reset_done' ) );
		add_action( 'profile_update', array( __CLASS__, 'profile_updated' ), 10, 2 );
		add_action( 'lld_members_daily', array( 'LLD_Members_Email_Codes', 'sweep' ) );
	}

	/* ---------- switches ---------- */

	/**
	 * True when the switch is not off.
	 *
	 * @param string $switch sign_in_code or sign_in_social.
	 */
	public static function on( $switch ) {
		return 'off' !== LLD_Members_Features::state( $switch );
	}

	/**
	 * True when the switch applies to this address: everyone, or a listed test account (or an administrator).
	 *
	 * @param string $switch sign_in_code or sign_in_social.
	 * @param string $email  Address.
	 */
	public static function applies( $switch, $email ) {
		$state = LLD_Members_Features::state( $switch );
		if ( 'everyone' === $state ) {
			return true;
		}
		if ( 'off' === $state ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return in_array( strtolower( trim( (string) $email ) ), LLD_Members_Features::test_account_emails(), true );
	}

	/* ---------- screens ---------- */

	public static function screens() {
		$screen = get_query_var( 'lld_screen' );
		if ( ! is_string( $screen ) || '' === $screen ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return; // Stage 2 shows its maintenance screen.
		}
		switch ( $screen ) {
			case 'join':
				self::join();
				break;
			case 'confirm':
				self::confirm();
				break;
			case 'sign-in':
				self::sign_in();
				break;
			case 'delete':
				self::delete();
				break;
			case 'settings':
				self::settings_actions();
				break;
		}
	}

	/**
	 * Join: the Stage 2 form, but the email is confirmed with a code and the account is created only when the
	 * code is right (FR3-03), and a new provider account finishes here with name and age (FR3-01, Apple FR4-30).
	 */
	private static function join() {
		if ( is_user_logged_in() && LLD_Members_Access::current_member_id() ) {
			return;
		}
		$provider_pending = self::pending( 'provider' );
		if ( $provider_pending ) {
			self::provider_join( $provider_pending );
			exit;
		}
		if ( ! self::on( 'sign_in_code' ) ) {
			return;
		}
		$everyone = 'everyone' === LLD_Members_Features::state( 'sign_in_code' );
		if ( ! $everyone && ! isset( $_GET['code'] ) ) {
			return;
		}
		$error = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_join_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_join_nonce'] ) ), 'lld_join' ) ) {
			$email = isset( $_POST['lld_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['lld_email'] ) ) ) : '';
			if ( ! self::applies( 'sign_in_code', $email ) ) {
				return; // Stage 2 creates the account and sends its confirmation link.
			}
			$result = self::start_sign_up();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				wp_safe_redirect( home_url( '/members/confirm/' ) );
				exit;
			}
		}
		$keep  = 'POST' === $_SERVER['REQUEST_METHOD'];
		$main  = lld_steps( array( 'Account', 'Email', 'Agreements', 'Goals' ), 0 ) . '<h1>Create account</h1>';
		$main .= self::error_box( $error );
		$main .= self::provider_buttons( 'join' );
		$main .= '<form method="post" class="lld-form lld-card lld-form-card">';
		$main .= wp_nonce_field( 'lld_join', 'lld_join_nonce', true, false );
		$main .= '<div class="lld-field-row">';
		$main .= lld_field( array( 'id' => 'lld_first', 'name' => 'lld_first', 'label' => 'First name', 'autocomplete' => 'given-name', 'value' => $keep && isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : '' ) );
		$main .= lld_field( array( 'id' => 'lld_last', 'name' => 'lld_last', 'label' => 'Last name', 'autocomplete' => 'family-name', 'value' => $keep && isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : '' ) );
		$main .= '</div>';
		$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'email', 'value' => $keep && isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '' ) );
		$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'Password', 'hint' => 'At least 10 characters.', 'autocomplete' => 'new-password' ) );
		$main .= lld_field( array( 'id' => 'lld_age', 'name' => 'lld_age', 'label' => 'Age in years', 'type' => 'number', 'value' => $keep && isset( $_POST['lld_age'] ) ? (string) (int) $_POST['lld_age'] : '' ) );
		$main .= '<p class="lld-note">Lifeline Daily relies on the age you give us. Content and results are based on it. Membership is for adults aged 18 and over.</p>';
		$main .= '<p class="lld-note">We\'ll email you a 6-digit code to confirm your email.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/terms/' ) ) . '">Terms</a> and <a href="' . esc_url( home_url( '/privacy/' ) ) . '">privacy policy</a></p>';
		$main .= lld_button( array( 'label' => 'Create account' ) );
		$main .= '</form><p>Already a member? <a href="' . esc_url( home_url( '/members/sign-in/' ) ) . '">Sign in</a>.</p>';
		lld_members_layout( array( 'title' => 'Create account', 'main' => $main, 'signed_in' => false ) );
		exit;
	}

	/**
	 * Check the join form, email the sign-up code and keep the details until the code is typed. The password
	 * is kept only as its hash; no account exists until the code is right (EM-22: "no account will be created").
	 *
	 * @return true|WP_Error
	 */
	private static function start_sign_up() {
		$email = isset( $_POST['lld_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['lld_email'] ) ) ) : '';
		$pass  = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
		$age   = isset( $_POST['lld_age'] ) ? (int) $_POST['lld_age'] : 0;
		$first = isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : '';
		$last  = isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : '';
		if ( $age < 18 ) {
			return new WP_Error( 'lld_age', 'Membership is for adults. You can still use the free calculators and read the articles.' );
		}
		if ( strlen( $pass ) < 10 ) {
			return new WP_Error( 'lld_password', 'Use at least 10 characters.' );
		}
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			return new WP_Error( 'lld_email', 'That email is already registered. Sign in.' );
		}
		$code = LLD_Members_Email_Codes::issue( $email, 'sign_up' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		LLD_Members_Email_Codes::send( $email, 'sign_up', $code );
		self::set_pending(
			array(
				'purpose'   => 'sign_up',
				'email'     => $email,
				'first'     => $first,
				'last'      => $last,
				'age'       => $age,
				'pass_hash' => wp_hash_password( $pass ),
			)
		);
		return true;
	}

	/**
	 * Confirm: the sign-up code page (FR3-03). Without a pending sign-up, Stage 2's link page answers.
	 */
	private static function confirm() {
		$pending = self::pending( 'sign_up' );
		if ( ! $pending ) {
			return;
		}
		$error  = '';
		$notice = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_s3_resend_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_resend_nonce'] ) ), 'lld_s3_resend' ) ) {
			$again = self::resend( $pending['email'], 'sign_up' );
			if ( is_wp_error( $again ) ) {
				$error = $again->get_error_message();
			} else {
				$notice = 'We emailed a new code.';
			}
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_s3_code_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_code_nonce'] ) ), 'lld_s3_code' ) ) {
			$typed  = isset( $_POST['lld_code'] ) ? (string) wp_unslash( $_POST['lld_code'] ) : '';
			$result = LLD_Members_Email_Codes::verify( $pending['email'], 'sign_up', $typed );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$account = self::create_account( $pending );
				if ( is_wp_error( $account ) ) {
					$error = $account->get_error_message();
				} else {
					self::clear_pending();
					LLD_Members_Auth::start_session( $account['user_id'], $account['member_id'], false );
					wp_safe_redirect( home_url( '/members/agreements/' ) );
					exit;
				}
			}
		}
		if ( isset( $_GET['change'] ) ) {
			self::clear_pending();
			wp_safe_redirect( home_url( '/members/join/' ) );
			exit;
		}
		$main  = lld_steps( array( 'Account', 'Email', 'Agreements', 'Goals' ), 1 ) . '<h1>Check your email</h1>';
		$main .= '<p>We\'ve emailed a 6-digit code to ' . esc_html( $pending['email'] ) . '. It works for 10 minutes.</p>';
		$main .= self::error_box( $error );
		if ( '' !== $notice ) {
			$main .= '<p class="lld-info" role="status">' . esc_html( $notice ) . '</p>';
		}
		$main .= self::code_form( 'Continue' );
		$main .= self::resend_form();
		$main .= '<p><a href="' . esc_url( home_url( '/members/confirm/?change=1' ) ) . '">Use a different email</a></p>';
		lld_members_layout( array( 'title' => 'Check your email', 'main' => $main, 'signed_in' => false ) );
		exit;
	}

	/**
	 * Sign in: password (as Stage 2), or a code by email (FR3-04), or Google and Apple (FR3-01, Apple FR4-30).
	 */
	private static function sign_in() {
		if ( is_user_logged_in() && LLD_Members_Access::current_member_id() ) {
			return;
		}
		$code_on   = self::on( 'sign_in_code' );
		$social_on = self::on( 'sign_in_social' );
		$pending   = $code_on ? self::pending( 'sign_in' ) : null;
		$everyone  = 'everyone' === LLD_Members_Features::state( 'sign_in_code' ) || 'everyone' === LLD_Members_Features::state( 'sign_in_social' );
		if ( ! $everyone && ! $pending && ! isset( $_GET['code'] ) && ! isset( $_GET['provider_error'] ) && ! isset( $_GET['everywhere'] ) ) {
			return;
		}
		$error  = '';
		$notice = '';
		$post   = 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( $post && $code_on && isset( $_POST['lld_s3_request_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_request_nonce'] ) ), 'lld_s3_request' ) ) {
			$email = isset( $_POST['lld_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['lld_email'] ) ) ) : '';
			if ( ! is_email( $email ) ) {
				$error = 'Enter your email address.';
			} elseif ( ! self::applies( 'sign_in_code', $email ) ) {
				$error = self::MESSAGES['not_open'];
			} else {
				$sent = self::request_sign_in_code( $email, ! empty( $_POST['lld_remember'] ) );
				if ( is_wp_error( $sent ) ) {
					$error = $sent->get_error_message();
				} else {
					wp_safe_redirect( home_url( '/members/sign-in/?code=sent' ) );
					exit;
				}
			}
		}
		if ( $post && $pending && isset( $_POST['lld_s3_resend_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_resend_nonce'] ) ), 'lld_s3_resend' ) ) {
			// A new code keeps where the member was going and whether the password was already proved.
			$again = self::request_sign_in_code( $pending['email'], ! empty( $pending['remember'] ), array_intersect_key( $pending, array( 'redirect_to' => 1, 'password_proved' => 1 ) ) );
			if ( is_wp_error( $again ) ) {
				$error = $again->get_error_message();
			} else {
				$notice = 'We emailed a new code.';
			}
		}
		if ( $post && $pending && isset( $_POST['lld_s3_code_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_code_nonce'] ) ), 'lld_s3_code' ) ) {
			$typed  = isset( $_POST['lld_code'] ) ? (string) wp_unslash( $_POST['lld_code'] ) : '';
			$result = LLD_Members_Email_Codes::verify( $pending['email'], 'sign_in', $typed );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$member = self::member_by_email( $pending['email'] );
				if ( ! $member ) {
					$error = 'That code does not match. Check the email and try again.';
				} elseif ( 'owner_admin' === (string) $member->role || '1' === (string) $member->two_step ) {
					// A code alone is one factor: the owner admin and members with two-step on keep their password step.
					$error = self::MESSAGES['two_step'];
				} else {
					self::clear_pending();
					self::open_session( $member, ! empty( $pending['remember'] ), ! empty( $pending['password_proved'] ) );
					wp_safe_redirect( self::destination( isset( $pending['redirect_to'] ) ? (string) $pending['redirect_to'] : '' ) );
					exit;
				}
			}
		}
		if ( $post && isset( $_POST['lld_sign_in_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_sign_in_nonce'] ) ), 'lld_sign_in' ) ) {
			$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
			if ( ! $everyone && ! self::applies( 'sign_in_code', $email ) ) {
				return; // Stage 2 answers this password sign-in.
			}
			$error = self::password_sign_in( $email );
			if ( '' === $error ) {
				return; // An unconfirmed member outside the code path: Stage 2 shows its link page.
			}
		}
		if ( isset( $_GET['change'] ) ) {
			self::clear_pending();
			wp_safe_redirect( home_url( '/members/sign-in/?code=1' ) );
			exit;
		}
		if ( '' === $error && isset( $_GET['provider_error'] ) ) {
			$key   = sanitize_key( (string) wp_unslash( $_GET['provider_error'] ) );
			$error = isset( self::MESSAGES[ $key ] ) ? self::MESSAGES[ $key ] : self::MESSAGES['failed'];
		}
		$pending = $code_on ? self::pending( 'sign_in' ) : null;
		if ( $pending ) {
			$main  = '<h1>Enter your code</h1>';
			$main .= '<p>If ' . esc_html( $pending['email'] ) . ' has an account, we\'ve emailed it a 6-digit code. It works for 10 minutes.</p>';
			$main .= self::error_box( $error );
			if ( '' !== $notice ) {
				$main .= '<p class="lld-info" role="status">' . esc_html( $notice ) . '</p>';
			}
			$main .= self::code_form( 'Sign in' );
			$main .= self::resend_form();
			$main .= '<p><a href="' . esc_url( home_url( '/members/sign-in/?change=1' ) ) . '">Use a different email</a> · <a href="' . esc_url( home_url( '/members/sign-in/' ) ) . '">Sign in with your password</a></p>';
			lld_members_layout( array( 'title' => 'Enter your code', 'main' => $main, 'signed_in' => false ) );
			exit;
		}
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$kept_email  = ( $post && isset( $_POST['lld_email'] ) ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
		$main        = '<h1>Sign in</h1>';
		if ( isset( $_GET['signed_out'] ) && ! $post ) {
			$main .= '<p class="lld-info" role="status">' . ( isset( $_GET['everywhere'] ) ? esc_html( self::MESSAGES['signed_out_all'] ) : 'You\'re signed out.' ) . '</p>';
		}
		$main .= self::error_box( $error );
		$main .= self::provider_buttons( 'sign-in', $redirect_to );
		if ( $code_on && isset( $_GET['code'] ) ) {
			$main .= '<form method="post" class="lld-form lld-card lld-form-card" novalidate>';
			$main .= wp_nonce_field( 'lld_s3_request', 'lld_s3_request_nonce', true, false );
			if ( '' !== $redirect_to ) {
				$main .= '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect_to ) . '">';
			}
			$main .= '<p>We\'ll email you a 6-digit code to sign in with. No password needed.</p>';
			$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email', 'value' => $kept_email, 'autocomplete' => 'email' ) );
			$main .= '<label class="lld-check"><input type="checkbox" name="lld_remember" value="1"> <span>Remember me for 30 days</span></label>';
			$main .= lld_button( array( 'label' => 'Email me a code' ) );
			$main .= '</form>';
			$main .= '<p class="lld-link-row"><a href="' . esc_url( home_url( '/members/sign-in/' . ( '' !== $redirect_to ? '?redirect_to=' . rawurlencode( $redirect_to ) : '' ) ) ) . '">Sign in with your password instead</a></p>';
		} else {
			$main .= '<form method="post" class="lld-form lld-card lld-form-card" novalidate>';
			$main .= wp_nonce_field( 'lld_sign_in', 'lld_sign_in_nonce', true, false );
			if ( '' !== $redirect_to ) {
				$main .= '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect_to ) . '">';
			}
			$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email', 'value' => $kept_email, 'autocomplete' => 'email' ) );
			$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'Password' ) );
			$main .= '<label class="lld-check"><input type="checkbox" name="lld_remember" value="1"> <span>Remember me for 30 days</span></label>';
			$main .= lld_button( array( 'label' => 'Sign in' ) );
			$main .= '</form>';
			if ( $code_on ) {
				$main .= '<p class="lld-link-row"><a href="' . esc_url( home_url( '/members/sign-in/?code=1' . ( '' !== $redirect_to ? '&redirect_to=' . rawurlencode( $redirect_to ) : '' ) ) ) . '">Email me a sign-in code instead</a></p>';
			}
			$main .= '<p class="lld-link-row"><a href="' . esc_url( home_url( '/members/reset/' ) ) . '">Forgot password?</a></p>';
		}
		$main .= '<p class="lld-link-row"><a href="' . esc_url( home_url( '/members/join/' ) ) . '">Join free</a></p>';
		lld_members_layout( array( 'title' => 'Sign in', 'main' => $main, 'signed_in' => false ) );
		exit;
	}

	/**
	 * The Stage 2 password sign-in, through the same public checks (TC-FR3-04-AS-04). An unconfirmed member
	 * whose password is right gets a code instead of a new link.
	 *
	 * @param string $email Address typed.
	 * @return string Error message, or empty after a redirect.
	 */
	private static function password_sign_in( $email ) {
		$password = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
		$remember = ! empty( $_POST['lld_remember'] );
		$user     = LLD_Members_Auth::check_password( $email, $password );
		if ( is_wp_error( $user ) ) {
			return $user->get_error_message();
		}
		$member = self::member_by_user( (int) $user->ID );
		if ( $member && empty( $member->email_confirmed_at ) ) {
			if ( self::on( 'sign_in_code' ) && self::applies( 'sign_in_code', (string) $member->email ) ) {
				$sent = self::request_sign_in_code( (string) $member->email, $remember, array( 'password_proved' => true ) );
				if ( is_wp_error( $sent ) ) {
					return $sent->get_error_message();
				}
				wp_safe_redirect( home_url( '/members/sign-in/?code=sent' ) );
				exit;
			}
			return ''; // Stage 2's own page answers, with its "Send a new link" form (sign_in() returns on empty).
		}
		if ( LLD_Members_Auth::needs_two_step( $user ) ) {
			LLD_Members_Auth::issue_code( $user, $remember );
			wp_safe_redirect( home_url( '/members/two-step/' ) );
			exit;
		}
		LLD_Members_Auth::start_session( (int) $user->ID, $member ? (int) $member->id : 0, $remember );
		wp_safe_redirect( self::destination( isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '' ) );
		exit;
	}

	/**
	 * Email a sign-in code when the address has an account; keep the request pending either way, so the
	 * answer never says whether the address is known.
	 *
	 * @param string $email    Address.
	 * @param bool   $remember Remember me.
	 * @return true|WP_Error
	 */
	private static function request_sign_in_code( $email, $remember, array $keep = array() ) {
		$member = self::member_by_email( $email );
		// The code row (and its hourly count) is written for every address, so the limit message and the
		// time taken are the same whether or not the address has an account; only the email is skipped.
		$code = LLD_Members_Email_Codes::issue( $email, 'sign_in' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		if ( $member && 'owner_admin' !== (string) $member->role && '1' !== (string) $member->two_step ) {
			LLD_Members_Email_Codes::send( $email, 'sign_in', $code );
		}
		self::set_pending(
			array_merge(
				array(
					'purpose'     => 'sign_in',
					'email'       => $email,
					'remember'    => $remember,
					'redirect_to' => isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '',
				),
				$keep
			)
		);
		return true;
	}

	/**
	 * A new code for a pending sign-up.
	 *
	 * @param string $email   Address.
	 * @param string $purpose Purpose.
	 * @return true|WP_Error
	 */
	private static function resend( $email, $purpose ) {
		$code = LLD_Members_Email_Codes::issue( $email, $purpose );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		LLD_Members_Email_Codes::send( $email, $purpose, $code );
		return true;
	}

	/**
	 * The last step for a new Google or Apple account: name (editable) and age, then the Agreements step.
	 *
	 * @param array $p Pending provider details.
	 */
	private static function provider_join( $p ) {
		$label = LLD_Members_Identity_Provider::label( (string) $p['provider'] );
		$error = '';
		if ( isset( $_GET['change'] ) ) {
			self::clear_pending();
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_s3_provider_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_provider_nonce'] ) ), 'lld_s3_provider' ) ) {
			$age   = isset( $_POST['lld_age'] ) ? (int) $_POST['lld_age'] : 0;
			$first = isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : '';
			$last  = isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : '';
			if ( $age < 18 ) {
				$error = 'Membership is for adults. You can still use the free calculators and read the articles.';
			} else {
				$account = self::create_account(
					array(
						'email'     => (string) $p['email'],
						'first'     => $first,
						'last'      => $last,
						'age'       => $age,
						'pass_hash' => '',
					)
				);
				if ( is_wp_error( $account ) ) {
					$error = $account->get_error_message();
				} else {
					self::link_identity( $account['member_id'], (string) $p['provider'], (string) $p['sub'], (string) $p['email'] );
					self::clear_pending();
					LLD_Members_Auth::start_session( $account['user_id'], $account['member_id'], false );
					wp_safe_redirect( home_url( '/members/agreements/' ) );
					exit;
				}
			}
		}
		$keep  = 'POST' === $_SERVER['REQUEST_METHOD'];
		$main  = lld_steps( array( 'Account', 'Email', 'Agreements', 'Goals' ), 0 ) . '<h1>Create account</h1>';
		$main .= '<p>' . esc_html( $label ) . ' confirmed your email ' . esc_html( (string) $p['email'] ) . '. Tell us your name and age to finish.</p>';
		$main .= self::error_box( $error );
		$main .= '<form method="post" class="lld-form lld-card lld-form-card">';
		$main .= wp_nonce_field( 'lld_s3_provider', 'lld_s3_provider_nonce', true, false );
		$main .= '<div class="lld-field-row">';
		$main .= lld_field( array( 'id' => 'lld_first', 'name' => 'lld_first', 'label' => 'First name', 'autocomplete' => 'given-name', 'value' => $keep && isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : (string) $p['first'] ) );
		$main .= lld_field( array( 'id' => 'lld_last', 'name' => 'lld_last', 'label' => 'Last name', 'autocomplete' => 'family-name', 'value' => $keep && isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : (string) $p['last'] ) );
		$main .= '</div>';
		$main .= lld_field( array( 'id' => 'lld_age', 'name' => 'lld_age', 'label' => 'Age in years', 'type' => 'number', 'value' => $keep && isset( $_POST['lld_age'] ) ? (string) (int) $_POST['lld_age'] : '' ) );
		$main .= '<p class="lld-note">Lifeline Daily relies on the age you give us. Content and results are based on it. Membership is for adults aged 18 and over.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/terms/' ) ) . '">Terms</a> and <a href="' . esc_url( home_url( '/privacy/' ) ) . '">privacy policy</a></p>';
		$main .= lld_button( array( 'label' => 'Create account' ) );
		$main .= '</form><p><a href="' . esc_url( home_url( '/members/join/?change=1' ) ) . '">Use a different account</a></p>';
		lld_members_layout( array( 'title' => 'Create account', 'main' => $main, 'signed_in' => false ) );
	}

	/**
	 * Delete account for a member with no password: confirmed with an email code instead (TC-FR3-05-AS-05).
	 */
	private static function delete() {
		if ( ! self::on( 'sign_in_code' ) || ! is_user_logged_in() ) {
			return;
		}
		$member_id = LLD_Members_Access::current_member_id();
		$member    = $member_id ? self::member_row( $member_id ) : null;
		if ( ! $member || self::has_password( (int) $member->user_id ) || ! self::applies( 'sign_in_code', (string) $member->email ) ) {
			return;
		}
		$error   = '';
		$pending = self::pending( 'delete' );
		if ( $pending && (int) $pending['member_id'] !== (int) $member_id ) {
			$pending = null;
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_s3_delete_request_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_delete_request_nonce'] ) ), 'lld_s3_delete_request' ) ) {
			$code = LLD_Members_Email_Codes::issue( (string) $member->email, 'delete' );
			if ( is_wp_error( $code ) ) {
				$error = $code->get_error_message();
			} else {
				LLD_Members_Email_Codes::send( (string) $member->email, 'delete', $code );
				self::set_pending( array( 'purpose' => 'delete', 'email' => (string) $member->email, 'member_id' => (int) $member_id ) );
				wp_safe_redirect( home_url( '/members/delete/' ) );
				exit;
			}
		}
		if ( $pending && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_s3_delete_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_delete_nonce'] ) ), 'lld_s3_delete' ) ) {
			$typed  = isset( $_POST['lld_code'] ) ? (string) wp_unslash( $_POST['lld_code'] ) : '';
			$result = LLD_Members_Email_Codes::verify( (string) $member->email, 'delete', $typed );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				self::clear_pending();
				$main = self::close_account( $member );
				lld_members_layout( array( 'title' => 'Account closed', 'main' => $main, 'signed_in' => false ) );
				exit;
			}
		}
		$main  = '<h1>Delete account</h1>';
		$main .= '<p>This deletes your account and cancels any subscription. For tax law we keep your name, email, payment amounts and dates.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/data/' ) ) . '">Download your data first</a></p>';
		$main .= self::error_box( $error );
		if ( $pending ) {
			$main .= '<p>We\'ve emailed a 6-digit code to ' . esc_html( (string) $member->email ) . '. Enter it to confirm.</p>';
			$main .= '<form method="post" class="lld-form">';
			$main .= wp_nonce_field( 'lld_s3_delete', 'lld_s3_delete_nonce', true, false );
			$main .= self::code_field();
			$main .= lld_button( array( 'label' => 'Delete my account' ) );
			$main .= '</form>';
		} else {
			$main .= '<p>Your account has no password, so we\'ll email you a code to confirm it\'s you.</p>';
			$main .= '<form method="post" class="lld-form">';
			$main .= wp_nonce_field( 'lld_s3_delete_request', 'lld_s3_delete_request_nonce', true, false );
			$main .= lld_button( array( 'label' => 'Email me a code' ) );
			$main .= '</form>';
		}
		lld_members_layout( array( 'title' => 'Delete account', 'main' => $main, 'signed_in' => true ) );
		exit;
	}

	/**
	 * The Stage 2 closing sequence (LLD_Members_Account::delete_screen), after the code instead of the password.
	 *
	 * @param object $row Member row.
	 * @return string Page body.
	 */
	private static function close_account( $row ) {
		$member_id = (int) $row->id;
		$cancel    = LLD_Members_Stripe::cancel_member( $member_id );
		LLD_Members_Mail::send_account_deleted( (string) $row->email, (string) $row->first_name );
		wp_logout();
		LLD_Members_Erasure::delete_account( $member_id, true );
		if ( is_wp_error( $cancel ) ) {
			$refs = (array) $cancel->get_error_data();
			LLD_Members_Mail::send_cancel_alert( $member_id, isset( $refs['subscription'] ) ? (string) $refs['subscription'] : '', isset( $refs['customer'] ) ? (string) $refs['customer'] : '' );
			return '<h1>Account closed</h1><p>Your account is closed. We\'re finishing cancelling your subscription with our payment provider and will email you when it\'s done.</p>';
		}
		return '<h1>Account closed</h1><p>Your account is closed. Your data will be removed within 24 hours.</p>';
	}

	/**
	 * The hub's Sign-in & security actions: unlink a provider, sign out everywhere (FR3-05).
	 */
	private static function settings_actions() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! is_user_logged_in() ) {
			return;
		}
		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id < 1 ) {
			return;
		}
		$back = home_url( '/members/settings/?section=security' );
		if ( isset( $_POST['lld_s3_unlink_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_unlink_nonce'] ) ), 'lld_s3_unlink' ) ) {
			$provider = isset( $_POST['lld_provider'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_provider'] ) ) : '';
			$result   = self::unlink( $member_id, $provider );
			wp_safe_redirect( $back . '&s3=' . ( is_wp_error( $result ) ? $result->get_error_code() : 'unlinked' ) );
			exit;
		}
		if ( isset( $_POST['lld_s3_signout_all_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_s3_signout_all_nonce'] ) ), 'lld_s3_signout_all' ) ) {
			self::end_all_sessions( $member_id, get_current_user_id() );
			wp_safe_redirect( home_url( '/members/sign-in/?signed_out=1&everywhere=1' ) );
			exit;
		}
	}

	/**
	 * The Sign-in & security section body for the account hub (class-account-hub.php calls this).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Member row.
	 */
	public static function security_html( $member_id, $row ) {
		$has_password = self::has_password( (int) $row->user_id );
		$identities   = self::identities( $member_id );
		$methods      = ( $has_password ? 1 : 0 ) + count( $identities );
		$html         = '';
		if ( isset( $_GET['s3'] ) ) {
			$key = sanitize_key( (string) wp_unslash( $_GET['s3'] ) );
			if ( isset( self::MESSAGES[ $key ] ) ) {
				$role  = in_array( $key, array( 'last', 'linked_elsewhere', 'failed', 'mismatch', 'cancelled', 'unavailable' ), true ) ? 'alert' : 'status';
				$html .= '<div class="' . ( 'alert' === $role ? 'lld-error-box' : 'lld-notice-box' ) . '" role="' . $role . '"><p>' . esc_html( self::MESSAGES[ $key ] ) . '</p></div>';
			}
		}
		$html .= '<h3>Ways to sign in</h3><ul class="lld-sign-in-methods">';
		$html .= '<li><strong>Password</strong>: ' . ( $has_password ? 'set' : 'not set' );
		$html .= ' <a class="lld-account-link" href="' . esc_url( home_url( '/members/reset/' ) ) . '">' . ( $has_password ? 'Change' : 'Set a password' ) . '</a></li>';
		if ( self::on( 'sign_in_social' ) ) {
			foreach ( LLD_Members_Identity_Provider::PROVIDERS as $provider ) {
				$label  = LLD_Members_Identity_Provider::label( $provider );
				$linked = null;
				foreach ( $identities as $identity ) {
					if ( $identity->provider === $provider ) {
						$linked = $identity;
					}
				}
				$html .= '<li data-lld-provider="' . esc_attr( $provider ) . '" data-lld-linked="' . ( $linked ? 'yes' : 'no' ) . '"><strong>' . esc_html( $label ) . '</strong>: ';
				if ( $linked ) {
					$html .= 'linked on ' . esc_html( gmdate( 'j M Y', strtotime( (string) $linked->linked_at ) ) ) . ' (' . esc_html( (string) $linked->provider_email ) . ')';
					$html .= ' <form method="post" class="lld-inline-form">' . wp_nonce_field( 'lld_s3_unlink', 'lld_s3_unlink_nonce', true, false );
					$html .= '<input type="hidden" name="lld_provider" value="' . esc_attr( $provider ) . '">';
					$html .= '<button type="submit" class="lld-text-button"' . ( $methods <= 1 ? ' aria-describedby="lld-last-method"' : '' ) . '>Unlink</button></form>';
				} elseif ( LLD_Members_Identity_Provider::configured( $provider ) ) {
					$link  = add_query_arg( array( 'link' => '1', '_wpnonce' => wp_create_nonce( 'wp_rest' ) ), rest_url( 'lld-members/v1/sign-in/' . $provider . '/start' ) );
					$html .= 'not linked <a class="lld-account-link" href="' . esc_url( $link ) . '">Link ' . esc_html( $label ) . '</a>';
				} else {
					$html .= 'not available yet';
				}
				$html .= '</li>';
			}
		}
		$html .= '</ul>';
		if ( $methods <= 1 ) {
			$html .= '<p class="lld-hint" id="lld-last-method">This is your only way to sign in, so it can\'t be removed.</p>';
		}
		$html .= '<h3>Sessions</h3>';
		// P3-36 C1 (TC-FR3-07-AS-06): this browser only, through the Stage 2 sign-out (the More screen) and its nonce;
		// the member's other devices stay signed in.
		$html .= '<form method="post" action="' . esc_url( home_url( '/members/more/' ) ) . '" class="lld-inline-form lld-sign-out-device">';
		$html .= '<input type="hidden" name="lld_sign_out_nonce" value="' . esc_attr( wp_create_nonce( 'lld_sign_out' ) ) . '">';
		$html .= '<button type="submit" class="lld-button lld-button-secondary">Sign out of this device</button></form>';
		$html .= '<p class="lld-muted">Sign out of this website and the app on every device.</p>';
		$html .= '<form method="post" class="lld-inline-form">' . wp_nonce_field( 'lld_s3_signout_all', 'lld_s3_signout_all_nonce', true, false );
		$html .= '<button type="submit" class="lld-button lld-button-secondary">Sign out everywhere</button></form>';
		return $html;
	}

	/* ---------- routes ---------- */

	public static function routes() {
		register_rest_route(
			'lld-members/v1',
			'/sign-in/(?P<provider>google|apple)/start',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'start' ), 'permission_callback' => '__return_true' )
		);
		register_rest_route(
			'lld-members/v1',
			'/sign-in/(?P<provider>google|apple)/callback',
			array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'callback' ), 'permission_callback' => '__return_true' )
		);
		if ( function_exists( 'lld_members_test_routes_open' ) && lld_members_test_routes_open() ) {
			// CI and local only (the Stage 2 test-route gate): a provider result without Google or Apple.
			register_rest_route(
				'lld-members/v1',
				'/test/sign-in/provider',
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test_provider' ), 'permission_callback' => array( 'LLD_Members_Rest', 'test_only' ) )
			);
		}
	}

	/**
	 * Send the member to Google or Apple.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function start( $request ) {
		$provider = (string) $request['provider'];
		if ( ! self::on( 'sign_in_social' ) || ! LLD_Members_Identity_Provider::configured( $provider ) || get_option( 'lld_members_closed' ) ) {
			self::leave( home_url( '/members/sign-in/?provider_error=unavailable' ) );
		}
		$intent    = 'sign_in';
		$member_id = 0;
		if ( $request->get_param( 'link' ) ) {
			// Cookie sign-in counts in REST only with the nonce the hub puts in the link; without it, the member is a visitor.
			$member_id = is_user_logged_in() ? LLD_Members_Access::current_member_id() : 0;
			if ( $member_id < 1 ) {
				self::leave( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/settings/?section=security' ) ) ) );
			}
			$intent = 'link';
		}
		$state    = bin2hex( random_bytes( 16 ) );
		$nonce    = bin2hex( random_bytes( 16 ) );
		$verifier = LLD_Members_Identity_Provider::base64url( random_bytes( 32 ) );
		$redirect = (string) $request->get_param( 'redirect_to' );
		set_transient(
			'lld_s3_oauth_' . $state,
			array( 'provider' => $provider, 'nonce' => $nonce, 'verifier' => $verifier, 'intent' => $intent, 'member_id' => $member_id, 'redirect_to' => $redirect ),
			self::OAUTH_SECONDS
		);
		// Apple answers with a cross-site POST, so its cookie needs SameSite=None (which needs Secure).
		self::cookie( self::COOKIE_OAUTH, $state, self::OAUTH_SECONDS, 'apple' === $provider && is_ssl() ? 'None' : 'Lax' );
		// The provider's own address, built here from the constants: wp_safe_redirect would refuse the external host.
		nocache_headers();
		wp_redirect( LLD_Members_Identity_Provider::authorize_url( $provider, $state, $nonce, $verifier ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Back from the provider: check the state, exchange the code, then sign in, link or finish the account.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function callback( $request ) {
		$provider = (string) $request['provider'];
		$state    = (string) $request->get_param( 'state' );
		$ctx      = '' !== $state ? get_transient( 'lld_s3_oauth_' . $state ) : false;
		if ( '' !== $state ) {
			delete_transient( 'lld_s3_oauth_' . $state );
		}
		$cookie = isset( $_COOKIE[ self::COOKIE_OAUTH ] ) ? (string) $_COOKIE[ self::COOKIE_OAUTH ] : '';
		self::cookie( self::COOKIE_OAUTH, '', -1, 'Lax' );
		$fail = home_url( '/members/sign-in/?provider_error=' );
		if ( get_option( 'lld_members_closed' ) ) {
			self::leave( $fail . 'unavailable' ); // a flow started before the site closed does not sign in during it
		}
		if ( ! is_array( $ctx ) || $ctx['provider'] !== $provider || '' === $cookie || ! hash_equals( $state, $cookie ) ) {
			self::leave( $fail . 'mismatch' );
		}
		if ( 'link' === $ctx['intent'] ) {
			$fail = home_url( '/members/settings/?section=security&s3=' );
		}
		if ( $request->get_param( 'error' ) || '' === (string) $request->get_param( 'code' ) ) {
			self::leave( $fail . 'cancelled' );
		}
		$claims = LLD_Members_Identity_Provider::exchange( $provider, (string) $request->get_param( 'code' ), (string) $ctx['verifier'], (string) $ctx['nonce'] );
		if ( is_wp_error( $claims ) ) {
			self::leave( $fail . 'failed' );
		}
		if ( 'apple' === $provider ) {
			$claims = LLD_Members_Identity_Provider::with_apple_name( $claims, (string) $request->get_param( 'user' ) );
		}
		self::leave( self::complete( $provider, $claims, $ctx ) );
	}

	/**
	 * CI and local: the same completion with claims given by the test instead of a provider.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_provider( $request ) {
		$p        = $request->get_json_params();
		$provider = isset( $p['provider'] ) && in_array( $p['provider'], LLD_Members_Identity_Provider::PROVIDERS, true ) ? (string) $p['provider'] : 'google';
		if ( ! self::on( 'sign_in_social' ) ) {
			return new WP_Error( 'lld_off', 'sign_in_social is off.', array( 'status' => 409 ) );
		}
		if ( get_option( 'lld_members_closed' ) ) {
			return rest_ensure_response( array( 'redirect' => home_url( '/members/sign-in/?provider_error=unavailable' ) ) );
		}
		$claims = array(
			'sub'            => isset( $p['sub'] ) ? (string) $p['sub'] : '',
			'email'          => isset( $p['email'] ) ? strtolower( sanitize_email( (string) $p['email'] ) ) : '',
			'email_verified' => ! empty( $p['email_verified'] ),
			'first_name'     => isset( $p['first_name'] ) ? sanitize_text_field( (string) $p['first_name'] ) : '',
			'last_name'      => isset( $p['last_name'] ) ? sanitize_text_field( (string) $p['last_name'] ) : '',
		);
		$intent = isset( $p['intent'] ) && 'link' === $p['intent'] ? 'link' : 'sign_in';
		$ctx    = array( 'intent' => $intent, 'member_id' => 'link' === $intent ? LLD_Members_Access::current_member_id() : 0, 'redirect_to' => '' );
		return rest_ensure_response( array( 'redirect' => self::complete( $provider, $claims, $ctx ) ) );
	}

	/**
	 * Sign in, link, or start a new account from verified claims (FR3-05). Returns where to send the browser.
	 *
	 * @param string $provider google or apple.
	 * @param array  $claims   sub, email, email_verified, first_name, last_name.
	 * @param array  $ctx      intent, member_id, redirect_to.
	 * @return string URL.
	 */
	private static function complete( $provider, $claims, $ctx ) {
		global $wpdb;
		$fail     = home_url( '/members/sign-in/?provider_error=' );
		$table    = LLD_Members_Stage3_Schema::table( 'identities' );
		$identity = '' !== $claims['sub'] ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE provider = %s AND provider_user_id = %s", $provider, $claims['sub'] ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' === $claims['sub'] ) {
			return $fail . 'failed';
		}
		if ( 'link' === $ctx['intent'] ) {
			$back      = home_url( '/members/settings/?section=security&s3=' );
			$member_id = (int) $ctx['member_id'];
			// The member was signed in when the link started (a nonce-checked request), and the state cookie binds
			// this browser to that start; the provider's redirect back carries no REST nonce, so the session is not re-read.
			if ( $member_id < 1 || ! self::member_row( $member_id ) ) {
				return $back . 'mismatch';
			}
			if ( $identity && (int) $identity->member_id !== $member_id ) {
				return $back . 'linked_elsewhere';
			}
			if ( ! $identity ) {
				if ( self::has_provider( $member_id, $provider ) ) {
					return $back . 'already_linked';
				}
				self::link_identity( $member_id, $provider, $claims['sub'], $claims['email'] );
			}
			return $back . 'linked';
		}
		if ( $identity ) {
			$member = self::member_row( (int) $identity->member_id );
			if ( ! $member ) {
				$wpdb->delete( $table, array( 'id' => (int) $identity->id ), array( '%d' ) );
				$identity = null;
			} else {
				if ( ! self::applies( 'sign_in_social', (string) $member->email ) ) {
					return $fail . 'not_open';
				}
				if ( 'owner_admin' === (string) $member->role ) {
					return $fail . 'owner';
				}
				if ( '1' === (string) $member->two_step ) {
					return $fail . 'two_step'; // a provider is one factor: two-step members keep their password and code
				}
				// The account is known by the provider's ID, so a changed email at the provider keeps it (TC-FR3-05-AS-03).
				$wpdb->update( $table, array( 'provider_email' => $claims['email'], 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $identity->id ), array( '%s', '%s' ), array( '%d' ) );
				self::open_session( $member, false );
				return self::destination( (string) $ctx['redirect_to'] );
			}
		}
		if ( ! $claims['email_verified'] || ! is_email( $claims['email'] ) ) {
			return $fail . 'unverified'; // TC-FR3-05-AS-02: no automatic link without a verified email.
		}
		if ( ! self::applies( 'sign_in_social', $claims['email'] ) ) {
			return $fail . 'not_open';
		}
		$member = self::member_by_email( $claims['email'] );
		if ( $member ) {
			if ( 'owner_admin' === (string) $member->role ) {
				return $fail . 'owner';
			}
			if ( '1' === (string) $member->two_step ) {
				return $fail . 'two_step';
			}
			if ( self::has_provider( (int) $member->id, $provider ) ) {
				return $fail . 'already_linked'; // another account at the same provider: the member unlinks the first one in the hub
			}
			self::link_identity( (int) $member->id, $provider, $claims['sub'], $claims['email'] ); // TC-FR3-05-AS-01
			self::open_session( $member, false );
			return self::destination( (string) $ctx['redirect_to'] );
		}
		if ( email_exists( $claims['email'] ) ) {
			return $fail . 'failed'; // A WordPress user without a member record (an administrator) never signs in this way.
		}
		self::set_pending(
			array(
				'purpose'  => 'provider',
				'provider' => $provider,
				'sub'      => $claims['sub'],
				'email'    => $claims['email'],
				'first'    => $claims['first_name'],
				'last'     => $claims['last_name'],
			)
		);
		return home_url( '/members/join/' );
	}

	/* ---------- accounts ---------- */

	/**
	 * Create the WordPress user and the member row exactly as the Stage 2 join does, with the email confirmed.
	 *
	 * @param array $p email, first, last, age, pass_hash (empty for a provider account).
	 * @return array|WP_Error user_id, member_id.
	 */
	private static function create_account( $p ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $p['email'] ) );
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			return new WP_Error( 'lld_email', 'That email is already registered. Sign in.' );
		}
		$user_id = wp_insert_user(
			array(
				'user_login' => LLD_Members_Account::unique_login( $email ),
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => $email,
				'first_name' => (string) $p['first'],
				'last_name'  => (string) $p['last'],
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		if ( '' !== (string) $p['pass_hash'] ) {
			$wpdb->update( $wpdb->users, array( 'user_pass' => (string) $p['pass_hash'] ), array( 'ID' => (int) $user_id ), array( '%s' ), array( '%d' ) );
			clean_user_cache( (int) $user_id );
		} else {
			update_user_meta( (int) $user_id, self::META_PASSWORDLESS, '1' );
		}
		$today = gmdate( 'Y-m-d' );
		$wpdb->insert(
			LLD_Members_Schema::table( 'member' ),
			array(
				'user_id'            => (int) $user_id,
				'email'              => $email,
				'first_name'         => (string) $p['first'],
				'last_name'          => (string) $p['last'],
				'age'                => (int) $p['age'],
				'age_entered_on'     => $today,
				'timezone'           => 'UTC',
				'units'              => 'metric',
				'role'               => 'member',
				'joined_on'          => $today,
				'two_step'           => 0,
				'email_confirmed_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$member_id = (int) $wpdb->insert_id;
		do_action( 'lld_members_event', 'registered', $member_id );
		return array( 'user_id' => (int) $user_id, 'member_id' => $member_id );
	}

	/**
	 * Record that a provider account opens this member's account.
	 *
	 * @param int    $member_id Member number.
	 * @param string $provider  google or apple.
	 * @param string $sub       The provider's account ID.
	 * @param string $email     Email the provider gave (may be an Apple relay address).
	 */
	private static function link_identity( $member_id, $provider, $sub, $email ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			LLD_Members_Stage3_Schema::table( 'identities' ),
			array( 'member_id' => (int) $member_id, 'provider' => $provider, 'provider_user_id' => $sub, 'provider_email' => $email, 'linked_at' => $now, 'last_used_at' => $now ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $provider  google or apple.
	 */
	private static function has_provider( $member_id, $provider ) {
		foreach ( self::identities( $member_id ) as $identity ) {
			if ( $identity->provider === $provider ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove a linked provider, unless it is the member's only way in (TC-FR3-05-AS-04).
	 *
	 * @param int    $member_id Member number.
	 * @param string $provider  google or apple.
	 * @return true|WP_Error
	 */
	public static function unlink( $member_id, $provider ) {
		global $wpdb;
		$row = self::member_row( $member_id );
		if ( ! $row || ! in_array( $provider, LLD_Members_Identity_Provider::PROVIDERS, true ) ) {
			return new WP_Error( 'failed', self::MESSAGES['failed'] );
		}
		$identities = self::identities( $member_id );
		$methods    = ( self::has_password( (int) $row->user_id ) ? 1 : 0 ) + count( $identities );
		if ( $methods <= 1 ) {
			return new WP_Error( 'last', self::MESSAGES['last'] );
		}
		$wpdb->delete( LLD_Members_Stage3_Schema::table( 'identities' ), array( 'member_id' => (int) $member_id, 'provider' => $provider ), array( '%d', '%s' ) );
		return true;
	}

	/**
	 * End every session of the member: WordPress's own, and the member session lines (TC-FR3-05-AS-06).
	 *
	 * @param int $member_id Member number.
	 * @param int $user_id   WordPress user.
	 */
	public static function end_all_sessions( $member_id, $user_id ) {
		global $wpdb;
		$now = lld_members_now_mysql( (int) $member_id );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'session' ) . ' SET ended_at = %s WHERE member_id = %d AND ended_at IS NULL', $now, (int) $member_id ) );
		// The app's keys (Stage 2 app_key, class-quality.php) are withdrawn as well: "on the website and the app".
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'app_key' ) . ' SET withdrawn_at = %s WHERE member_id = %d AND withdrawn_at IS NULL', $now, (int) $member_id ) );
		if ( $user_id > 0 ) {
			WP_Session_Tokens::get_instance( (int) $user_id )->destroy_all();
		}
		wp_logout();
	}

	/**
	 * A member's linked providers.
	 *
	 * @param int $member_id Member number.
	 * @return object[]
	 */
	public static function identities( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'identities' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d ORDER BY id", (int) $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * False for an account created through Google or Apple that has never set a password.
	 *
	 * @param int $user_id WordPress user.
	 */
	public static function has_password( $user_id ) {
		return '1' !== (string) get_user_meta( (int) $user_id, self::META_PASSWORDLESS, true );
	}

	/**
	 * A password set in any way makes the member a password account again.
	 *
	 * @param string $password Not used.
	 * @param int    $user_id  WordPress user.
	 */
	public static function password_set( $password, $user_id ) {
		unset( $password );
		delete_user_meta( (int) $user_id, self::META_PASSWORDLESS );
	}

	/**
	 * @param WP_User $user User.
	 */
	public static function password_reset_done( $user ) {
		if ( $user instanceof WP_User ) {
			delete_user_meta( (int) $user->ID, self::META_PASSWORDLESS );
		}
	}

	/**
	 * @param int     $user_id  WordPress user.
	 * @param WP_User $old_data The user before the change.
	 */
	public static function profile_updated( $user_id, $old_data ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( $user && $old_data instanceof WP_User && (string) $user->user_pass !== (string) $old_data->user_pass ) {
			delete_user_meta( (int) $user_id, self::META_PASSWORDLESS );
		}
	}

	/**
	 * Sign a member in: confirm the email if it never was (a provider or code proved it), then the Stage 2 session.
	 *
	 * @param object $member          Member row.
	 * @param bool   $remember        Remember me.
	 * @param bool   $password_proved The member typed the right password before the code (password_sign_in()).
	 */
	private static function open_session( $member, $remember, $password_proved = false ) {
		global $wpdb;
		if ( empty( $member->email_confirmed_at ) ) {
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'member' ) . ' SET email_confirmed_at = %s WHERE id = %d AND email_confirmed_at IS NULL', lld_members_now_mysql( (int) $member->id ), (int) $member->id ) );
			if ( 1 === (int) $changed && ! $password_proved ) {
				// The password on a never-confirmed account was typed by whoever registered the address, which may not
				// be this member: it is replaced by a random one (set a new one at /members/reset/) and its sessions end.
				wp_set_password( wp_generate_password( 32, true, true ), (int) $member->user_id );
				update_user_meta( (int) $member->user_id, self::META_PASSWORDLESS, '1' );
				WP_Session_Tokens::get_instance( (int) $member->user_id )->destroy_all();
			}
		}
		LLD_Members_Auth::start_session( (int) $member->user_id, (int) $member->id, (bool) $remember );
	}

	/* ---------- helpers ---------- */

	/**
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	private static function member_row( $member_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', (int) $member_id ) );
		return $row ? $row : null;
	}

	/**
	 * @param string $email Address.
	 * @return object|null
	 */
	private static function member_by_email( $email ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE LOWER(email) = %s', strtolower( trim( (string) $email ) ) ) );
		return $row ? $row : null;
	}

	/**
	 * @param int $user_id WordPress user.
	 * @return object|null
	 */
	private static function member_by_user( $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', (int) $user_id ) );
		return $row ? $row : null;
	}

	/**
	 * Where to go after signing in: a safe redirect_to, or the member home.
	 *
	 * @param string $raw Requested address.
	 */
	private static function destination( $raw ) {
		$raw = esc_url_raw( (string) $raw );
		if ( '' === $raw ) {
			return home_url( '/members/home/' );
		}
		return wp_validate_redirect( $raw, home_url( '/members/home/' ) );
	}

	/**
	 * The pending details behind the browser's cookie, when they are for this purpose.
	 *
	 * @param string $purpose sign_up, sign_in, delete or provider.
	 * @return array|null
	 */
	private static function pending( $purpose ) {
		$token = isset( $_COOKIE[ self::COOKIE_PENDING ] ) ? (string) $_COOKIE[ self::COOKIE_PENDING ] : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$data = get_transient( 'lld_s3_pending_' . hash( 'sha256', $token ) );
		if ( ! is_array( $data ) || ! isset( $data['purpose'] ) || $data['purpose'] !== $purpose ) {
			return null;
		}
		return $data;
	}

	/**
	 * @param array $data Details to keep for 15 minutes.
	 */
	private static function set_pending( $data ) {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( 'lld_s3_pending_' . hash( 'sha256', $token ), $data, self::PENDING_SECONDS );
		self::cookie( self::COOKIE_PENDING, $token, self::PENDING_SECONDS, 'Lax' );
	}

	private static function clear_pending() {
		$token = isset( $_COOKIE[ self::COOKIE_PENDING ] ) ? (string) $_COOKIE[ self::COOKIE_PENDING ] : '';
		if ( preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			delete_transient( 'lld_s3_pending_' . hash( 'sha256', $token ) );
		}
		self::cookie( self::COOKIE_PENDING, '', -1, 'Lax' );
	}

	/**
	 * @param string $name     Cookie.
	 * @param string $value    Value.
	 * @param int    $seconds  Lifetime; negative deletes.
	 * @param string $samesite Lax or None.
	 */
	private static function cookie( $name, $value, $seconds, $samesite ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $seconds < 0 ? time() - DAY_IN_SECONDS : time() + $seconds,
				'path'     => '/',
				'secure'   => is_ssl() || 'None' === $samesite,
				'httponly' => true,
				'samesite' => $samesite,
			)
		);
		if ( '' === $value ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = $value;
		}
	}

	/**
	 * Redirect from a REST callback.
	 *
	 * @param string $url Where to.
	 */
	private static function leave( $url ) {
		nocache_headers();
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param string $label Button label.
	 */
	private static function code_form( $label ) {
		$html  = '<form method="post" class="lld-form lld-card lld-form-card" novalidate>';
		$html .= wp_nonce_field( 'lld_s3_code', 'lld_s3_code_nonce', true, false );
		$html .= self::code_field();
		$html .= lld_button( array( 'label' => $label ) );
		return $html . '</form>';
	}

	/**
	 * The six-digit box: numeric keyboard on phones, and browsers may offer the code from the email.
	 */
	private static function code_field() {
		return '<div class="lld-field"><label for="lld_code">6-digit code</label>'
			. '<input id="lld_code" name="lld_code" type="text" value="" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"></div>';
	}

	private static function resend_form() {
		return '<form method="post" class="lld-form">' . wp_nonce_field( 'lld_s3_resend', 'lld_s3_resend_nonce', true, false ) . '<button type="submit" class="lld-text-button">Send a new code</button></form>';
	}

	/**
	 * Continue with Google / Apple, for the providers whose settings exist.
	 *
	 * @param string $where       sign-in or join.
	 * @param string $redirect_to Address to return to after signing in.
	 */
	private static function provider_buttons( $where, $redirect_to = '' ) {
		if ( ! self::on( 'sign_in_social' ) ) {
			return '';
		}
		$html = '';
		foreach ( LLD_Members_Identity_Provider::PROVIDERS as $provider ) {
			if ( ! LLD_Members_Identity_Provider::configured( $provider ) ) {
				continue;
			}
			$url   = rest_url( 'lld-members/v1/sign-in/' . $provider . '/start' );
			if ( '' !== $redirect_to ) {
				$url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
			}
			$html .= '<a class="lld-button lld-button-secondary lld-provider-' . esc_attr( $provider ) . '" href="' . esc_url( $url ) . '">Continue with ' . esc_html( LLD_Members_Identity_Provider::label( $provider ) ) . '</a> ';
		}
		if ( '' === $html ) {
			return '';
		}
		return '<div class="lld-providers lld-card" data-lld-where="' . esc_attr( $where ) . '">' . $html . '<p class="lld-note">Or ' . ( 'join' === $where ? 'create an account' : 'sign in' ) . ' with your email below.</p></div>';
	}

	/**
	 * @param string $error Message.
	 */
	private static function error_box( $error ) {
		return '' === $error ? '' : '<div class="lld-fail" role="alert" tabindex="-1"><p>' . esc_html( $error ) . '</p></div>';
	}
}
