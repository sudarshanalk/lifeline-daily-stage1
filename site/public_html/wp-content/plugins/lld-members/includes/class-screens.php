<?php
/**
 * Member screens for foundations: sign-in, two-step code, settings switch, checkout and shared components.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders plugin screens and refuses another member's address.
 */
class LLD_Members_Screens {

	public static function dispatch() {
		$screen = get_query_var( 'lld_screen' );
		if ( ! is_string( $screen ) || '' === $screen ) {
			if ( get_option( 'lld_members_closed' ) && is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
				self::maintenance();
			}
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			self::maintenance();
		}
		switch ( $screen ) {
			case 'sign-in':
				self::sign_in();
				break;
			case 'two-step':
				self::two_step();
				break;
			case 'settings':
				LLD_Members_Account::screen( 'settings' );
				break;
			case 'home':
				LLD_Members_Personal::dashboard();
				break;
			case 'checkout':
				LLD_Members_Billing::checkout();
				break;
			case 'subscription':
				LLD_Members_Billing::subscription();
				break;
			case 'pricing':
				LLD_Members_Billing::pricing();
				break;
			case 'programmes':
				LLD_Members_Billing::programmes();
				break;
			case 'sales':
				LLD_Members_Billing::sales( (string) get_query_var( 'lld_track' ) );
				break;
			case 'landing':
				LLD_Members_Billing::landing( (string) get_query_var( 'lld_track' ) );
				break;
			case 'safety':
				LLD_Members_Billing::public_note( 'safety' );
				break;
			case 'disclaimer':
				LLD_Members_Billing::public_note( 'disclaimer' );
				break;
			case 'sitemap':
				LLD_Members_Billing::sitemap();
				break;
			case 'record':
				self::record();
				break;
			case 'photo':
				self::photo();
				break;
			case 'foundations':
				self::foundations();
				break;
			case 'library':
				LLD_Members_Content::screen();
				break;
			case 'health':
			case 'suggestions':
			case 'tracks':
			case 'screening':
			case 'check-in':
			case 'plan':
			case 'meals':
			case 'workout':
			case 'challenge':
			case 'badges':
			case 'diary':
			case 'read':
				LLD_Members_Personal::screen( $screen );
				break;
			case 'group':
				LLD_Members_Premium::group();
				break;
			case 'diary-photo':
				LLD_Members_Premium::diary_photo();
				break;
			case 'help':
				LLD_Members_Growth::help();
				break;
			case 'more':
				LLD_Members_Growth::more();
				break;
			case 'waitlist':
				LLD_Members_Growth::waitlist_screen( (string) get_query_var( 'lld_track' ) );
				break;
			case 'guide-confirm':
				LLD_Members_Growth::confirm_screen();
				break;
			case 'guide-unsub':
				LLD_Members_Growth::unsubscribe_screen();
				break;
			case 'quotes':
				LLD_Members_Growth::quotes_screen();
				break;
			case 'track':
			case 'review':
				LLD_Members_Programme::screen( $screen );
				break;
			default:
				if ( LLD_Members_Account::handles( $screen ) ) {
					LLD_Members_Account::screen( $screen );
					break;
				}
				status_header( 404 );
				return;
		}
		exit;
	}

	private static function sign_in() {
		if ( is_user_logged_in() && LLD_Members_Access::current_member_id() ) {
			wp_safe_redirect( self::destination() );
			exit;
		}
		$error       = '';
		$unconfirmed = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_sign_in_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_sign_in_nonce'] ) ), 'lld_sign_in' ) ) {
			$email    = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
			$password = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
			$remember = ! empty( $_POST['lld_remember'] );
			$user     = LLD_Members_Auth::check_password( $email, $password );
			if ( is_wp_error( $user ) ) {
				$error = $user->get_error_message();
			} else {
				global $wpdb;
				$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
				$confirmed = $member_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT email_confirmed_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) ) : 'yes';
				if ( $member_id && ! $confirmed ) {
					$error       = 'Confirm your email before you sign in.';
					$unconfirmed = (string) $user->user_email;
				} elseif ( LLD_Members_Auth::needs_two_step( $user ) ) {
					LLD_Members_Auth::issue_code( $user, $remember );
					wp_safe_redirect( home_url( '/members/two-step/' ) );
					exit;
				} else {
					LLD_Members_Auth::start_session( $user->ID, $member_id, $remember );
					wp_safe_redirect( self::destination() );
					exit;
				}
			}
		}
		$main = '<h1>Sign in</h1>';
		if ( '' !== $unconfirmed ) {
			// The password was right, so the member may be told the link is the only thing missing and get a new one (SCR-03 resend).
			// The link expires after 24 hours, and the first email may never have arrived.
			$main .= '<div class="lld-fail" role="alert" tabindex="-1"><p>' . esc_html( $error ) . ' Open the link we emailed to ' . esc_html( $unconfirmed ) . '. It expires after 24 hours.</p>';
			$main .= '<form method="post" action="' . esc_url( home_url( '/members/confirm/' ) ) . '">';
			$main .= wp_nonce_field( 'lld_resend_confirm', 'lld_resend_nonce', true, false );
			$main .= '<input type="hidden" name="lld_email" value="' . esc_attr( $unconfirmed ) . '">';
			$main .= '<button type="submit" class="lld-text-button">Send a new link</button></form></div>';
			$error = '';
		}
		$main .= '<form method="post" class="lld-form lld-card lld-form-card" novalidate>';
		$main .= wp_nonce_field( 'lld_sign_in', 'lld_sign_in_nonce', true, false );
		if ( isset( $_GET['redirect_to'] ) ) {
			$main .= '<input type="hidden" name="redirect_to" value="' . esc_attr( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) ) . '">';
		}
		if ( '' !== $error ) {
			$main .= '<div class="lld-fail" role="alert" tabindex="-1"><p>' . esc_html( $error ) . '</p></div>';
		}
		$kept_email = ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_email'] ) ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
		$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email', 'value' => $kept_email ) );
		$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'Password' ) );
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_remember" value="1"> <span>Remember me for 30 days</span></label>';
		$main .= lld_button( array( 'label' => 'Sign in' ) );
		$main .= '</form>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/reset/' ) ) . '">Forgot password?</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/join/' ) ) . '">Join free</a></p>';
		lld_members_layout( array( 'title' => 'Sign in', 'main' => $main, 'signed_in' => false ) );
	}

	private static function two_step() {
		$error  = '';
		$notice = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_resend_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_resend_nonce'] ) ), 'lld_resend' ) ) {
			$result = LLD_Members_Auth::resend_code();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$notice = 'We emailed a new code.';
			}
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_code_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_code_nonce'] ) ), 'lld_code' ) ) {
			$code   = isset( $_POST['lld_code'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['lld_code'] ) ) : '';
			$result = LLD_Members_Auth::complete_code( $code );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				// An administrator has no member home (often no member record): wp-admin, or wp-login.php's redirect_to.
				wp_safe_redirect( LLD_Members_Auth::after_code_destination() );
				exit;
			}
		}
		$main  = '<h1>Enter your code</h1>';
		$main .= '<p>We emailed you a 6-digit code.</p>';
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_code', 'lld_code_nonce', true, false );
		if ( '' !== $error ) {
			$main .= '<div class="lld-fail" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $notice ) {
			$main .= '<div class="lld-info" role="status"><p>' . esc_html( $notice ) . '</p></div>';
		}
		$main .= lld_field( array( 'id' => 'lld_code', 'name' => 'lld_code', 'label' => '6-digit code', 'hint' => 'It expires in 10 minutes.' ) );
		$main .= lld_button( array( 'label' => 'Continue' ) );
		$main .= '</form>';
		$main .= '<form method="post" action="' . esc_url( home_url( '/members/two-step/' ) ) . '">';
		$main .= wp_nonce_field( 'lld_resend', 'lld_resend_nonce', true, false );
		$main .= '<button type="submit" class="lld-text-button" name="lld_resend" value="1">Resend code</button></form>';
		lld_members_layout( array( 'title' => 'Enter your code', 'main' => $main ) );
	}

	private static function settings() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT role, two_step FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		$forced = $row && 'owner_admin' === $row->role;
		$on     = $forced || ( $row && '1' === (string) $row->two_step );
		$saved  = false;
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_settings_nonce'] ) ), 'lld_settings' ) ) {
			$on = $forced || ! empty( $_POST['lld_two_step'] );
			$wpdb->update(
				LLD_Members_Schema::table( 'member' ),
				array( 'two_step' => $on ? 1 : 0 ),
				array( 'id' => $member_id ),
				array( '%d' ),
				array( '%d' )
			);
			$saved = true;
		}
		$main  = '<h1>Profile and settings</h1>';
		$main .= '<form method="post" class="lld-form" data-lld-form>';
		$main .= wp_nonce_field( 'lld_settings', 'lld_settings_nonce', true, false );
		$main .= '<fieldset class="lld-card"><legend>Two-step sign-in</legend>';
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_two_step" value="1"' . ( $on ? ' checked' : '' ) . ( $forced ? ' disabled' : '' ) . '> Email me a code when I sign in</label>';
		if ( $forced ) {
			$main .= '<input type="hidden" name="lld_two_step" value="1">';
			$main .= '<p class="lld-hint">The owner admin account always uses an email code.</p>';
		}
		$main .= '</fieldset>';
		$main .= lld_button( array( 'label' => 'Save' ) );
		$main .= '</form>';
		if ( $saved ) {
			$main .= lld_state_saved();
		}
		lld_members_layout(
			array(
				'title'     => 'Profile and settings',
				'main'      => $main,
				'signed_in' => true,
			)
		);
	}

	private static function home() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		$state     = LLD_Members_Access::access_state( $member_id );
		$label     = 'free' === $state ? 'Free' : $state;
		$main      = '<h1>Today</h1>';
		$main     .= '<p class="lld-membership">Your membership is ' . esc_html( $label ) . '.</p>';
		$main     .= lld_empty( 'Your programme appears here once you start a track.', lld_button( array( 'label' => 'Look at programmes', 'href' => home_url( '/programmes/' ), 'variant' => 'secondary' ) ) );
		lld_members_layout(
			array(
				'title'     => 'Today',
				'main'      => $main,
				'signed_in' => true,
			)
		);
	}

	private static function record() {
		self::require_member();
		$requested = (int) get_query_var( 'lld_member' );
		if ( ! LLD_Members_Access::can_read( $requested ) ) {
			self::not_allowed();
		}
		$main = '<h1>Your record</h1><p>This is your own membership record.</p>';
		lld_members_layout(
			array(
				'title'     => 'Your record',
				'main'      => $main,
				'signed_in' => true,
			)
		);
	}

	private static function photo() {
		self::require_member();
		$requested = (int) get_query_var( 'lld_member' );
		if ( ! LLD_Members_Access::can_read( $requested ) ) {
			self::not_allowed();
		}
		global $wpdb;
		$path = $wpdb->get_var( $wpdb->prepare( 'SELECT photo_path FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $requested ) );
		if ( ! is_string( $path ) || '' === $path ) {
			status_header( 404 );
			lld_members_layout(
				array(
					'title'     => 'Photo',
					'main'      => '<h1>No photo yet</h1>',
					'signed_in' => true,
					'status'    => 404,
				)
			);
			return;
		}
		LLD_Members_Photos::send( $requested, $path );
	}

	/**
	 * Shared components and the standard states. Hidden on production.
	 */
	private static function foundations() {
		if ( lld_members_is_production() || ! current_user_can( 'manage_options' ) ) {
			lld_members_layout(
				array(
					'title'     => 'Page not found',
					'main'      => '<h1>Page not found</h1><p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Go to Today</a></p>',
					'status'    => 404,
					'signed_in' => is_user_logged_in(),
				)
			);
			exit;
		}
		$main  = '<h1>Foundations</h1>';
		$main .= '<p>Shared components and the standard states.</p>';
		$main .= lld_steps( array( 'Account', 'Email', 'Agreements', 'Goals' ), 2 );
		$main .= '<h2>Buttons</h2><div class="lld-row">';
		$main .= lld_button( array( 'label' => 'Save', 'type' => 'button' ) );
		$main .= lld_button( array( 'label' => 'Cancel', 'variant' => 'secondary', 'type' => 'button' ) );
		$main .= lld_button( array( 'label' => 'Save', 'type' => 'button', 'busy' => true ) );
		$main .= lld_button( array( 'label' => 'Save', 'type' => 'button', 'disabled' => true, 'disabled_reason' => 'Add your email first.' ) );
		$main .= '</div>';
		$main .= '<h2>Fields</h2>';
		$main .= lld_field( array( 'id' => 'demo-email', 'label' => 'Email', 'hint' => 'We send a confirmation link.', 'error' => 'Enter an email address.' ) );
		$main .= lld_password_field( array( 'id' => 'demo-password', 'label' => 'Password' ) );
		$main .= lld_options( 'pace', array( 'low' => 'Low', 'some' => 'Some', 'active' => 'Active' ) );
		$main .= '<h2>Cards</h2>';
		$main .= lld_card( 'A short walk', 'Ten minutes outside after lunch.', lld_button( array( 'label' => 'Open the activity', 'href' => '#activity', 'type' => 'button' ) ) );
		$main .= lld_suggestion_card(
			array(
				'label'        => 'Article',
				'title'        => 'A steadier evening meal',
				'reason'       => 'Matches your blood sugar goal.',
				'reviewer'     => 'Lifeline Daily',
				'date'         => '1 November 2026',
				'action_label' => 'Read the article',
				'action_href'  => '#article',
			)
		);
		$main .= lld_activity_card(
			array(
				'week'         => 1,
				'day'          => 3,
				'title'        => 'Swap one sugary drink',
				'instructions' => 'Choose water or tea once today.',
				'href'         => '#activity',
			)
		);
		$main .= lld_progress( 9, 28 );
		$main .= lld_chart( 'Waist down 3 cm since week 1', array( 'Week 1' => 40, 'Week 2' => 70, 'Week 3' => 55 ) );
		$main .= lld_badge( 'First week finished' );
		$main .= '<h2>Notes</h2>';
		$main .= lld_safety_banner( 'Get help now', 'If you are in immediate danger, contact local emergency services.', '<p><a href="https://findahelpline.com">Find a helpline</a></p>' );
		$main .= lld_caution( 'Check with your doctor before you change a medicine routine.' );
		$main .= lld_info( 'A short walk after eating is a useful place to start.' );
		$main .= '<h2>Three reasons something is not available</h2>';
		$main .= lld_membership_lock( 'Premium adds the full 28-day track, meal plans and workouts.' );
		$main .= lld_health_consent_notice();
		$main .= lld_not_suitable( 'Not suggested during pregnancy', 'The safety note for this item.', home_url( '/members/home/' ), 'See a general walking idea' );
		$main .= '<h2>Standard states</h2>';
		$main .= lld_state_loading( 'Today' );
		$main .= lld_state_saved();
		$main .= lld_state_save_failed();
		$main .= lld_state_offline();
		$main .= lld_state_not_allowed( 'h2' );
		$main .= lld_empty( 'Nothing here yet.', lld_button( array( 'label' => 'Choose a goal', 'href' => '#goals', 'variant' => 'secondary' ) ) );
		$main .= '<h2>Timer</h2>';
		$main .= lld_timer();
		$main .= '<h2>Dialog</h2><button type="button" class="lld-button" data-lld-open="lld-demo-dialog">Open a note</button>';
		$main .= '<dialog id="lld-demo-dialog"><h2>A short note</h2><p>Focus stays in this dialog until you close it.</p><button type="button" class="lld-button" data-lld-close>Close</button></dialog>';
		lld_members_layout(
			array(
				'title'     => 'Foundations',
				'main'      => $main,
				'signed_in' => is_user_logged_in(),
			)
		);
	}

	private static function not_allowed() {
		lld_members_layout(
			array(
				'title'     => 'This page isn\'t available',
				'main'      => lld_state_not_allowed() . '<p>You can only open your own pages.</p>',
				'signed_in' => is_user_logged_in(),
				'status'    => 403,
			)
		);
		exit;
	}

	private static function maintenance() {
		lld_members_layout(
			array(
				'title'  => 'Closed',
				'main'   => '<h1>Lifeline Daily is closed</h1><p>We are restoring the site. Members can sign in again once that is finished.</p>',
				'status' => 503,
			)
		);
		exit;
	}

	private static function require_member() {
		if ( is_user_logged_in() && LLD_Members_Access::current_member_id() ) {
			return;
		}
		$here = home_url( add_query_arg( array() ) );
		wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( $here ) ) );
		exit;
	}

	private static function destination() {
		$raw = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		// An empty address is not a valid redirect. PHP 8 parse_url('') is an empty path, so
		// wp_validate_redirect would keep it and the sign-in response would never leave the page.
		if ( '' === $raw ) {
			return home_url( '/members/home/' );
		}
		return wp_validate_redirect( $raw, home_url( '/members/home/' ) );
	}
}

