<?php
/**
 * Accounts and privacy screens: register, confirm, reset, profile, consent, download and deletion.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FR2-01 to FR2-04 and the account half of NFR2-01.
 */
class LLD_Members_Account {

	const TERMS   = 'terms-2026-11';
	const PRIVACY = 'privacy-2026-11';
	const SALE    = 'sale-2026-11';

	/**
	 * @param string $screen Screen id.
	 */
	public static function handles( $screen ) {
		return in_array( $screen, array( 'join', 'confirm', 'reset', 'agreements', 'goals', 'settings', 'data', 'withdraw', 'delete', 'email', 'unsubscribe' ), true );
	}

	/**
	 * @param string $screen Screen id.
	 */
	public static function screen( $screen ) {
		$map = array(
			'join'         => 'join',
			'confirm'      => 'confirm',
			'reset'        => 'reset',
			'agreements'   => 'agreements',
			'goals'        => 'goals',
			'settings'     => 'profile',
			'data'         => 'data',
			'withdraw'     => 'withdraw',
			'delete'       => 'delete_screen',
			'email'        => 'email_confirmed',
			'unsubscribe'  => 'unsubscribe',
		);
		$method = $map[ $screen ];
		self::$method();
	}

	/**
	 * Sign the member out when their remembered session has passed on the test clock.
	 */
	public static function expire_sessions() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id < 1 ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'session' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, remember, expires_at FROM {$table} WHERE member_id = %d AND ended_at IS NULL ORDER BY id DESC LIMIT 1", $member_id ) );
		if ( ! $row ) {
			return;
		}
		$now = LLD_Members_Clock::now( $member_id )->format( 'Y-m-d H:i:s' );
		if ( $row->remember && $row->expires_at < $now ) {
			$wpdb->update( $table, array( 'ended_at' => $now ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			wp_logout();
		}
	}

	/**
	 * Keep member.email equal to the WordPress account email.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function sync_email( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'member' ),
			array( 'email' => $user->user_email ),
			array( 'user_id' => (int) $user_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $type      Consent type.
	 */
	public static function has_consent( $member_id, $type ) {
		global $wpdb;
		$kind = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT change_kind FROM ' . LLD_Members_Schema::table( 'consent' ) . ' WHERE member_id = %d AND consent_type = %s ORDER BY id DESC LIMIT 1',
				$member_id,
				$type
			)
		);
		return 'given' === $kind;
	}

	/**
	 * Age as shown today. It moves forward on each anniversary of the day it was entered.
	 *
	 * @param object $row Member row.
	 */
	public static function shown_age( $row ) {
		$entered = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $row->age_entered_on, new DateTimeZone( 'UTC' ) );
		$today   = LLD_Members_Clock::now( (int) $row->id );
		$years   = $entered ? (int) $entered->diff( $today )->y : 0;
		return (int) $row->age + max( 0, $years );
	}

	/**
	 * @param array<string,mixed> $fields Profile fields.
	 */
	private static function unrealistic_profile( $fields ) {
		if ( isset( $fields['height_cm'] ) && '' !== $fields['height_cm'] && null !== $fields['height_cm'] ) {
			$height = (float) $fields['height_cm'];
			if ( $height < 120 || $height > 220 ) {
				return true;
			}
		}
		if ( isset( $fields['weight_kg'] ) && '' !== $fields['weight_kg'] && null !== $fields['weight_kg'] ) {
			$weight = (float) $fields['weight_kg'];
			if ( $weight < 30 || $weight > 250 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Save a health record. Refused, and nothing is written, without health consent.
	 *
	 * @param int   $member_id Member number.
	 * @param array $fields    kind plus the values for that kind.
	 * @return true|WP_Error
	 */
	public static function save_health( $member_id, $fields ) {
		$member_id = (int) $member_id;
		if ( ! self::has_consent( $member_id, 'health' ) ) {
			return new WP_Error( 'lld_health', 'Health consent is needed before this can be saved.' );
		}
		global $wpdb;
		$kind = isset( $fields['kind'] ) ? (string) $fields['kind'] : '';
		$now  = lld_members_now_mysql( $member_id );
		$date = LLD_Members_Clock::now( $member_id )->format( 'Y-m-d' );
		if ( 'goal' === $kind ) {
			$table    = LLD_Members_Schema::table( 'goal_choice' );
			$key      = sanitize_title( (string) $fields['goal_key'] );
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND goal_key = %s", $member_id, $key ) );
			if ( $existing ) {
				$ok = $wpdb->update(
					$table,
					array(
						'sort_order' => (int) $fields['sort_order'],
						'chosen_on'  => $date,
					),
					array( 'id' => $existing ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			} else {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE member_id = %d", $member_id ) );
				if ( $count >= 3 ) {
					return new WP_Error( 'lld_health', 'You can choose up to 3 goals' );
				}
				$ok = $wpdb->insert(
					$table,
					array(
						'member_id'  => $member_id,
						'goal_key'   => $key,
						'sort_order' => (int) $fields['sort_order'],
						'chosen_on'  => $date,
					),
					array( '%d', '%s', '%d', '%s' )
				);
			}
		} elseif ( 'profile' === $kind ) {
			if ( self::unrealistic_profile( $fields ) && empty( $fields['confirmed'] ) ) {
				return new WP_Error( 'lld_check', 'Please check this value' );
			}
			$table   = LLD_Members_Schema::table( 'health_profile' );
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d", $member_id ), ARRAY_A );
			$blank   = array(
				'member_id'           => $member_id,
				'sex'                 => null,
				'height_cm'           => null,
				'weight_kg'           => null,
				'waist_cm'            => null,
				'activity_level'      => null,
				'diet_type'           => null,
				'ethnicity'           => null,
				'conditions_answered' => 'not_answered',
				'conditions'          => null,
				'updated_on'          => $date,
				'reminder_sent_on'    => null,
			);
			if ( is_array( $current ) ) {
				$blank = array_merge( $blank, $current );
			}
			foreach ( array( 'sex', 'height_cm', 'weight_kg', 'waist_cm', 'activity_level', 'diet_type', 'ethnicity', 'conditions_answered', 'conditions' ) as $key ) {
				if ( ! array_key_exists( $key, $fields ) ) {
					continue;
				}
				$value = $fields[ $key ];
				if ( 'conditions' === $key && is_array( $value ) ) {
					$value = wp_json_encode( array_values( $value ) );
				}
				$blank[ $key ] = ( '' === $value || null === $value ) ? null : $value;
			}
			$blank['member_id']  = $member_id;
			$blank['updated_on'] = $date;
			$ok = $wpdb->replace( $table, $blank );
		} elseif ( 'something_else' === $kind ) {
			$text = trim( sanitize_textarea_field( (string) ( $fields['text'] ?? '' ) ) );
			if ( '' === $text ) {
				$ok = 1;
			} else {
				$theme = preg_replace( '/\S+@\S+/', '', $text );
				$theme = preg_replace( '/\d{6,}/', '', (string) $theme );
				$theme = sanitize_text_field( wp_trim_words( (string) $theme, 3, '' ) );
				$table = LLD_Members_Schema::table( 'something_else' );
				$wpdb->insert(
					$table,
					array(
						'member_id'       => $member_id,
						'written_at'      => $now,
						'text_ciphertext' => LLD_Members_Crypto::encrypt( 'pending', 'something_else:0' ),
						'theme'           => $theme,
					)
				);
				$else_id = (int) $wpdb->insert_id;
				$ok      = $wpdb->update(
					$table,
					array( 'text_ciphertext' => LLD_Members_Crypto::encrypt( $text, 'something_else:' . $else_id ) ),
					array( 'id' => $else_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		} elseif ( 'check_in' === $kind ) {
			$ok = $wpdb->insert(
				LLD_Members_Schema::table( 'check_in' ),
				array(
					'member_id'  => $member_id,
					'local_date' => $date,
					'month_key'  => substr( $date, 0, 7 ),
				)
			);
		} elseif ( 'diary' === $kind ) {
			$ok = $wpdb->insert(
				LLD_Members_Schema::table( 'diary_day' ),
				array(
					'member_id'  => $member_id,
					'local_date' => $date,
				)
			);
		} elseif ( 'result' === $kind ) {
			$wpdb->insert(
				LLD_Members_Schema::table( 'calculator_result' ),
				array(
					'member_id'            => $member_id,
					'calculator'           => 'test',
					'saved_at'             => $now,
					'headline'             => 'Saved',
					'unit'                 => 'kg',
					'band'                 => 'middle',
					'calculator_version'   => 'test',
				)
			);
			$id = (int) $wpdb->insert_id;
			$ok = $id > 0;
		} else {
			return new WP_Error( 'lld_health', 'Unknown health record.' );
		}
		if ( false === $ok ) {
			return new WP_Error( 'lld_health', 'That could not be saved.' );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_health( $request ) {
		$member_id = (int) $request['id'];
		if ( ! LLD_Members_Access::can_read( $member_id ) ) {
			return new WP_REST_Response( array( 'message' => 'This page isn\'t available' ), 403 );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = self::save_health( $member_id, $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 403 );
		}
		return new WP_REST_Response( array( 'saved' => true ), 200 );
	}

	private static function join() {
		$error = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_join_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_join_nonce'] ) ), 'lld_join' ) ) {
			$result = self::register_from_post();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$sent = bin2hex( random_bytes( 12 ) );
				set_transient( 'lld_sent_' . $sent, $result, DAY_IN_SECONDS );
				wp_safe_redirect( home_url( '/members/confirm/?sent=' . $sent ) );
				exit;
			}
		}
		$main  = '<p class="lld-steps">Account, Email, Agreements, Goals</p><h1>Create account</h1>';
		$main .= self::error_box( $error );
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_join', 'lld_join_nonce', true, false );
		$keep  = 'POST' === $_SERVER['REQUEST_METHOD'];
		$main .= lld_field( array( 'id' => 'lld_first', 'name' => 'lld_first', 'label' => 'First name', 'value' => $keep && isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : '' ) );
		$main .= lld_field( array( 'id' => 'lld_last', 'name' => 'lld_last', 'label' => 'Last name', 'value' => $keep && isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : '' ) );
		$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email', 'value' => $keep && isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '' ) );
		$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'Password', 'hint' => 'At least 10 characters.' ) );
		$main .= lld_field( array( 'id' => 'lld_age', 'name' => 'lld_age', 'label' => 'Age in years', 'type' => 'number', 'value' => $keep && isset( $_POST['lld_age'] ) ? (string) (int) $_POST['lld_age'] : '' ) );
		$main .= '<p>Lifeline Daily relies on the age you give us. Content and results are based on it.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/terms/' ) ) . '">Terms</a> and <a href="' . esc_url( home_url( '/privacy/' ) ) . '">privacy policy</a></p>';
		$main .= lld_button( array( 'label' => 'Create account' ) );
		$main .= '</form><p>Already a member? <a href="' . esc_url( home_url( '/members/sign-in/' ) ) . '">Sign in</a>.</p>';
		self::layout( 'Create account', $main, false );
	}

	/**
	 * @return string|WP_Error Email on success.
	 */
	private static function register_from_post() {
		$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
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
		$user_id = wp_insert_user(
			array(
				'user_login' => self::unique_login( $email ),
				'user_pass'  => $pass,
				'user_email' => $email,
				'first_name' => $first,
				'last_name'  => $last,
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		global $wpdb;
		$today = gmdate( 'Y-m-d' );
		$wpdb->insert(
			LLD_Members_Schema::table( 'member' ),
			array(
				'user_id'        => (int) $user_id,
				'email'          => $email,
				'first_name'     => $first,
				'last_name'      => $last,
				'age'            => $age,
				'age_entered_on' => $today,
				'timezone'       => 'UTC',
				'units'          => 'metric',
				'role'           => 'member',
				'joined_on'      => $today,
				'two_step'       => 0,
			)
		);
		$member_id = (int) $wpdb->insert_id;
		self::issue_link( get_user_by( 'id', $user_id ), $member_id, 'email_confirm', 24 );
		return $email;
	}

	/**
	 * SCR-03. The link confirms the email once, while it is unconfirmed. It never signs anyone in:
	 * the member signs in with their password afterwards.
	 */
	/**
	 * A WordPress login nobody else has. Members sign in by email; the login is never shown.
	 * Two emails that share the part before the @ get different logins.
	 *
	 * @param string $email Email.
	 */
	public static function unique_login( $email ) {
		$base = sanitize_user( (string) strstr( (string) $email, '@', true ), true );
		$base = substr( '' !== $base ? $base : 'member', 0, 50 );
		$login = $base;
		for ( $n = 2; username_exists( $login ) || email_exists( $login ); ++$n ) {
			$login = $n < 20 ? $base . '-' . $n : $base . '-' . strtolower( wp_generate_password( 8, false, false ) );
		}
		return $login;
	}

	private static function confirm() {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$sent  = isset( $_GET['sent'] ) ? sanitize_key( wp_unslash( $_GET['sent'] ) ) : '';
		$email = '';
		if ( '' !== $sent ) {
			$stored = get_transient( 'lld_sent_' . $sent );
			$email  = is_string( $stored ) ? $stored : '';
		}
		if ( isset( $_POST['lld_email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['lld_email'] ) );
		}
		$notice = '';
		$error  = '';
		if ( '' !== $token ) {
			$result = self::confirm_email( $token );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$notice = 'confirmed';
			}
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_resend_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_resend_nonce'] ) ), 'lld_resend_confirm' ) ) {
			$again  = self::resend_confirm( $email );
			$error  = is_wp_error( $again ) ? $again->get_error_message() : '';
			$notice = is_wp_error( $again ) ? '' : 'resent';
		}
		if ( 'confirmed' === $notice ) {
			$next  = home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/agreements/' ) ) );
			$main  = '<h1>Email confirmed</h1><p>Your email is confirmed. Sign in with your password to continue.</p>';
			$main .= lld_button( array( 'label' => 'Continue', 'href' => $next ) );
			self::layout( 'Email confirmed', $main, false );
			return;
		}
		$main = '<h1>Check your email</h1>';
		if ( '' !== $email ) {
			$main .= '<p>We\'ve sent a link to ' . esc_html( $email ) . '.</p>';
		}
		$main .= self::error_box( $error );
		if ( 'resent' === $notice ) {
			$main .= '<p class="lld-info">If this email is waiting to be confirmed, we\'ve sent a new link.</p>';
		}
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_resend_confirm', 'lld_resend_nonce', true, false );
		if ( '' !== $email ) {
			$main .= '<input type="hidden" name="lld_email" value="' . esc_attr( $email ) . '">';
		} else {
			$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email' ) );
		}
		$main .= '<button type="submit" class="lld-text-button">Resend</button></form>';
		self::layout( 'Check your email', $main, false );
	}

	/**
	 * Use an email-confirmation link. Only an unconfirmed account can be confirmed, and no session is opened.
	 *
	 * @param string $token Token from the link.
	 * @return true|WP_Error
	 */
	private static function confirm_email( $token ) {
		$result = self::consume_link( $token, 'email_confirm' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		global $wpdb;
		$member_id = (int) $result['member_id'];
		$changed   = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . LLD_Members_Schema::table( 'member' ) . ' SET email_confirmed_at = %s WHERE id = %d AND email_confirmed_at IS NULL',
				lld_members_now_mysql( $member_id ),
				$member_id
			)
		);
		if ( 1 !== (int) $changed ) {
			return new WP_Error( 'lld_link', 'Already confirmed — sign in' );
		}
		return true;
	}

	private static function reset() {
		$error  = '';
		$notice = isset( $_GET['sent'] ) ? 'If an account exists, we\'ve sent a link' : '';
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_reset_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_reset_nonce'] ) ), 'lld_reset' ) ) {
			$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
			self::request_reset( $email );
			$notice = 'If an account exists, we\'ve sent a link';
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_new_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_new_nonce'] ) ), 'lld_new_password' ) ) {
			$pass   = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
			$result = self::complete_reset( $token, $pass );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$main = '<h1>Password changed</h1><p>Password changed. Other devices have been signed out.</p>';
				$main .= lld_button( array( 'label' => 'Sign in', 'href' => home_url( '/members/sign-in/' ) ) );
				self::layout( 'Password changed', $main, false );
				return;
			}
		}
		if ( '' !== $token ) {
			if ( '' === $error ) {
				$error = self::link_problem( $token, 'password_reset' );
			}
			if ( '' !== $error && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
				$main = '<h1>Reset password</h1>' . self::error_box( $error );
				self::layout( 'Reset password', $main, false );
				return;
			}
			$main  = '<h1>Choose a new password</h1>' . self::error_box( $error );
			$main .= '<form method="post" class="lld-form">';
			$main .= wp_nonce_field( 'lld_new_password', 'lld_new_nonce', true, false );
			$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'New password', 'hint' => 'At least 10 characters.' ) );
			$main .= lld_button( array( 'label' => 'Save password' ) );
			$main .= '</form>';
			self::layout( 'Reset password', $main, false );
			return;
		}
		$main  = '<h1>Reset password</h1>' . self::error_box( $error );
		if ( '' !== $notice ) {
			$main .= '<p class="lld-info">' . esc_html( $notice ) . '</p>';
		}
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_reset', 'lld_reset_nonce', true, false );
		$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email' ) );
		$main .= lld_button( array( 'label' => 'Send link' ) );
		$main .= '</form>';
		self::layout( 'Reset password', $main, false );
	}

	private static function agreements() {
		self::require_confirmed();
		$member_id = LLD_Members_Access::current_member_id();
		$error     = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_agree_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_agree_nonce'] ) ), 'lld_agree' ) ) {
			if ( empty( $_POST['lld_terms'] ) ) {
				$error = 'Please accept the terms and privacy policy to continue.';
			} else {
				self::add_consent( $member_id, 'terms', 'given', self::TERMS );
				self::add_consent( $member_id, 'privacy', 'given', self::PRIVACY );
				self::add_consent( $member_id, 'health', empty( $_POST['lld_health'] ) ? 'withdrawn' : 'given', '' );
				self::add_consent( $member_id, 'marketing', empty( $_POST['lld_marketing'] ) ? 'withdrawn' : 'given', '' );
				wp_safe_redirect( home_url( '/members/goals/' ) );
				exit;
			}
		}
		$main  = '<p class="lld-steps">Account, Email, Agreements, Goals</p><h1>Agreements</h1>';
		$main .= self::error_box( $error );
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_agree', 'lld_agree_nonce', true, false );
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_terms" value="1"> I accept the <a href="' . esc_url( home_url( '/terms/' ) ) . '">terms</a> and <a href="' . esc_url( home_url( '/privacy/' ) ) . '">privacy policy</a></label>';
		$main .= '<fieldset class="lld-card"><legend>Health information</legend>';
		$main .= '<p>This allows goals, tracks, a health profile, a food diary and check-ins. Without it you can still use the calculators, read articles and manage a membership.</p>';
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_health" value="1"> Allow Lifeline Daily to store my health information</label></fieldset>';
		$main .= '<fieldset class="lld-card"><legend>Marketing emails</legend>';
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_marketing" value="1"> Send me marketing emails</label></fieldset>';
		$main .= lld_button( array( 'label' => 'Continue' ) );
		$main .= '</form>';
		self::layout( 'Agreements', $main, true );
	}

	private static function goals() {
		LLD_Members_Personal::goals();
	}

	private static function profile() {
		if ( ! is_user_logged_in() || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}
		global $wpdb;
		$member_id = LLD_Members_Access::current_member_id();
		$row       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		$saved     = false;
		$error     = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_settings_nonce'] ) ), 'lld_settings' ) ) {
			$result = self::save_profile( $row );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$saved = true;
				$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
			}
		}
		$forced = $row && 'owner_admin' === $row->role;
		$on     = $forced || ( $row && '1' === (string) $row->two_step );
		$renew  = self::renewal_label( $member_id );
		$main   = '<h1>Profile and settings</h1>' . self::error_box( $error );
		if ( $saved ) {
			$main .= lld_state_saved();
		}
		$pending = self::pending_email( $member_id );
		if ( $pending ) {
			$main .= '<p class="lld-info">Check ' . esc_html( $pending ) . ' to confirm. Your current email still works until then.</p>';
		}
		$main .= '<p>Joined on ' . esc_html( (string) $row->joined_on ) . '</p>';
		$main .= '<p>Membership: ' . esc_html( LLD_Members_Access::access_state( $member_id ) ) . '</p>';
		$main .= '<p>Next renewal: ' . esc_html( $renew ) . '</p>';
		$main .= '<p>Age: ' . esc_html( (string) self::shown_age( $row ) ) . '</p>';
		$main .= '<form method="post" class="lld-form" enctype="multipart/form-data" data-lld-form>';
		$main .= wp_nonce_field( 'lld_settings', 'lld_settings_nonce', true, false );
		$main .= lld_field( array( 'id' => 'lld_first', 'name' => 'lld_first', 'label' => 'First name', 'value' => $row->first_name ) );
		$main .= lld_field( array( 'id' => 'lld_last', 'name' => 'lld_last', 'label' => 'Last name', 'value' => $row->last_name ) );
		$main .= lld_field( array( 'id' => 'lld_display', 'name' => 'lld_display', 'label' => 'Display name', 'value' => (string) $row->display_name ) );
		$main .= lld_field( array( 'id' => 'lld_email', 'name' => 'lld_email', 'label' => 'Email', 'type' => 'email', 'value' => $row->email ) );
		$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'New password', 'hint' => 'Leave blank to keep your password. At least 10 characters.' ) );
		$main .= lld_field( array( 'id' => 'lld_age', 'name' => 'lld_age', 'label' => 'Age in years', 'type' => 'number', 'value' => (string) $row->age ) );
		$main .= lld_field( array( 'id' => 'lld_timezone', 'name' => 'lld_timezone', 'label' => 'Time zone', 'value' => $row->timezone ) );
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_units" value="imperial"' . ( 'imperial' === $row->units ? ' checked' : '' ) . '> Show imperial units</label>';
		$main .= '<p><label for="lld_photo">Profile photo</label><input id="lld_photo" name="lld_photo" type="file" accept=".jpg,.jpeg,.png,.heic,image/jpeg,image/png,image/heic"></p>';
		if ( $row->photo_path ) {
			$main .= '<p><img alt="" src="' . esc_url( home_url( '/members/photo/' . $member_id . '/' ) ) . '" width="80"></p>';
			$main .= '<label class="lld-check"><input type="checkbox" name="lld_remove_photo" value="1"> Remove photo</label>';
		}
		$main .= '<fieldset class="lld-card"><legend>Two-step sign-in</legend>';
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_two_step" value="1"' . ( $on ? ' checked' : '' ) . ( $forced ? ' disabled' : '' ) . '> Email me a code when I sign in</label>';
		if ( $forced ) {
			$main .= '<input type="hidden" name="lld_two_step" value="1">';
		}
		$main .= '</fieldset>';
		$main .= self::preference_fields( $member_id );
		if ( self::has_consent( $member_id, 'health' ) ) {
			$goal   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT goal_key FROM ' . LLD_Members_Schema::table( 'goal_choice' ) . ' WHERE member_id = %d ORDER BY sort_order ASC LIMIT 1', $member_id ) );
			$track  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT track_key FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $member_id ) );
			$height = $wpdb->get_var( $wpdb->prepare( 'SELECT height_cm FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) );
			$height = null === $height ? '' : rtrim( rtrim( (string) $height, '0' ), '.' );
			$main .= lld_field( array( 'id' => 'lld_goal', 'name' => 'lld_goal', 'label' => 'Goal', 'value' => $goal ) );
			$main .= lld_field( array( 'id' => 'lld_track', 'name' => 'lld_track', 'label' => 'Track', 'value' => $track ) );
			$main .= lld_field( array( 'id' => 'lld_height', 'name' => 'lld_height', 'label' => 'Height in centimetres', 'type' => 'number', 'value' => $height ) );
			$main .= '<p><label for="lld_diary">Diary photo</label><input id="lld_diary" name="lld_diary" type="file" accept=".jpg,.jpeg,.png,.heic"></p>';
		} else {
			$main .= lld_health_consent_notice();
		}
		$main .= '<p>Health consent: ' . ( self::has_consent( $member_id, 'health' ) ? 'on' : 'off' ) . '. <a href="' . esc_url( home_url( '/members/withdraw/' ) ) . '">Withdraw health consent</a></p>';
		$history = $wpdb->get_results( $wpdb->prepare( 'SELECT consent_type, change_kind, document_version, occurred_at FROM ' . LLD_Members_Schema::table( 'consent' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ) );
		$main   .= '<ul class="lld-consent-history">';
		foreach ( $history as $line ) {
			$main .= '<li>' . esc_html( $line->consent_type . ' ' . $line->change_kind . ' ' . $line->document_version . ' ' . $line->occurred_at ) . '</li>';
		}
		$main .= '</ul>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/data/' ) ) . '">My data</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/delete/' ) ) . '">Delete account</a></p>';
		$main .= lld_button( array( 'label' => 'Save' ) );
		$main .= '</form>';
		self::layout( 'Profile and settings', $main, true );
	}

	/**
	 * @param object $row Member row.
	 * @return true|WP_Error
	 */
	private static function save_profile( $row ) {
		$age = isset( $_POST['lld_age'] ) ? (int) $_POST['lld_age'] : (int) $row->age;
		if ( $age < 18 ) {
			return new WP_Error( 'lld_age', 'Age cannot be under 18.' );
		}
		global $wpdb;
		$member_id = (int) $row->id;
		$pass      = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
		if ( '' !== $pass ) {
			if ( strlen( $pass ) < 10 ) {
				return new WP_Error( 'lld_password', 'Use at least 10 characters.' );
			}
			wp_set_password( $pass, (int) $row->user_id );
			wp_set_auth_cookie( (int) $row->user_id, false );
		}
		$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : $row->email;
		if ( $email !== $row->email ) {
			if ( ! is_email( $email ) || email_exists( $email ) ) {
				return new WP_Error( 'lld_email', 'That email is already registered.' );
			}
			self::issue_link( get_user_by( 'id', $row->user_id ), $member_id, 'email_change', 24, $email );
		}
		$photo = $row->photo_path;
		if ( ! empty( $_POST['lld_remove_photo'] ) ) {
			LLD_Members_Photos::delete_member( $member_id, 'profile' );
			$photo = null;
		}
		if ( isset( $_FILES['lld_photo'] ) && ! empty( $_FILES['lld_photo']['tmp_name'] ) ) {
			$stored = LLD_Members_Photos::store( $member_id, 'profile', $_FILES['lld_photo']['tmp_name'], (string) $_FILES['lld_photo']['name'] );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$photo = $stored;
		}
		$forced = 'owner_admin' === $row->role;
		$wpdb->update(
			LLD_Members_Schema::table( 'member' ),
			array(
				'first_name'   => isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : $row->first_name,
				'last_name'    => isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : $row->last_name,
				'display_name' => isset( $_POST['lld_display'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_display'] ) ) : $row->display_name,
				'age'          => $age,
				'age_entered_on' => ( $age !== (int) $row->age ) ? LLD_Members_Clock::now( $member_id )->format( 'Y-m-d' ) : $row->age_entered_on,
				'timezone'     => isset( $_POST['lld_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_timezone'] ) ) : $row->timezone,
				'units'        => empty( $_POST['lld_units'] ) ? 'metric' : 'imperial',
				'photo_path'   => $photo,
				'two_step'     => ( $forced || ! empty( $_POST['lld_two_step'] ) ) ? 1 : 0,
			),
			array( 'id' => $member_id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
		self::save_preferences( $member_id );
		if ( self::has_consent( $member_id, 'health' ) ) {
			if ( ! empty( $_POST['lld_goal'] ) ) {
				self::save_health( $member_id, array( 'kind' => 'goal', 'goal_key' => sanitize_text_field( wp_unslash( $_POST['lld_goal'] ) ), 'sort_order' => 1 ) );
			}
			if ( ! empty( $_POST['lld_track'] ) ) {
				$track = sanitize_text_field( wp_unslash( $_POST['lld_track'] ) );
				$enrol = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND status = 'active'", $member_id ) );
				if ( $enrol ) {
					$wpdb->update( LLD_Members_Schema::table( 'enrolment' ), array( 'track_key' => $track ), array( 'id' => $enrol ), array( '%s' ), array( '%d' ) );
				} else {
					$wpdb->insert(
						LLD_Members_Schema::table( 'enrolment' ),
						array(
							'member_id' => $member_id,
							'track_key' => $track,
							'status'    => 'active',
						),
						array( '%d', '%s', '%s' )
					);
				}
			}
			if ( isset( $_POST['lld_height'] ) && '' !== (string) $_POST['lld_height'] ) {
				self::save_health( $member_id, array( 'kind' => 'profile', 'height_cm' => (float) $_POST['lld_height'] ) );
			}
			if ( isset( $_FILES['lld_diary'] ) && ! empty( $_FILES['lld_diary']['tmp_name'] ) ) {
				$stored = LLD_Members_Photos::store( $member_id, 'diary', $_FILES['lld_diary']['tmp_name'], (string) $_FILES['lld_diary']['name'] );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				$date = LLD_Members_Clock::now( $member_id )->format( 'Y-m-d' );
				$wpdb->replace(
					LLD_Members_Schema::table( 'diary_day' ),
					array(
						'member_id'  => $member_id,
						'local_date' => $date,
					)
				);
				$wpdb->insert(
					LLD_Members_Schema::table( 'diary_meal' ),
					array(
						'member_id'   => $member_id,
						'local_date'  => $date,
						'meal'        => 'lunch',
						'entry_type'  => 'photo',
						'photo_path'  => $stored,
						'recorded_at' => lld_members_now_mysql( $member_id ),
					)
				);
			}
		}
		return true;
	}

	private static function data() {
		self::require_member_page();
		$member_id = LLD_Members_Access::current_member_id();
		$error     = '';
		if ( isset( $_GET['token'] ) ) {
			self::send_download( $member_id, sanitize_text_field( wp_unslash( $_GET['token'] ) ) );
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_data_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_data_nonce'] ) ), 'lld_data' ) ) {
			$token = self::build_download( $member_id );
			if ( is_wp_error( $token ) ) {
				$error = 'Try again';
			} else {
				$field = 'token';
				wp_safe_redirect( home_url( '/members/data/?' . $field . '=' . rawurlencode( $token ) ) );
				exit;
			}
		}
		$main  = '<h1>My data</h1><p>Download all my data. One ZIP file holds a readable summary, the data and your photos. Passwords and security keys are never included.</p>';
		$main .= self::error_box( $error );
		$main .= '<form method="post">';
		$main .= wp_nonce_field( 'lld_data', 'lld_data_nonce', true, false );
		$main .= lld_button( array( 'label' => 'Download all my data' ) );
		$main .= '</form>';
		self::layout( 'My data', $main, true );
	}

	private static function withdraw() {
		self::require_member_page();
		$member_id = LLD_Members_Access::current_member_id();
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_withdraw_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_withdraw_nonce'] ) ), 'lld_withdraw' ) ) {
			LLD_Members_Erasure::withdraw_health( $member_id, true );
			$main  = '<h1>Health information deleted</h1>';
			$main .= '<p>Your goals, track progress, health profile, check-ins, food diary, diary photos, saved results and "what changed" answers have been deleted.</p>';
			$main .= '<p><strong>Your subscription continues. To stop paying, cancel it separately.</strong></p>';
			self::layout( 'Health information deleted', $main, true );
			return;
		}
		$main  = '<h1>Withdraw health consent</h1>';
		$main .= '<p>This deletes your goals, track progress, health profile, check-ins, food diary and diary photos, saved results and "what changed" survey answers. Personalised tracks and tracking will stop.</p>';
		$main .= '<p><strong>Your subscription continues. To stop paying, cancel it separately.</strong></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/checkout/' ) ) . '">My subscription</a></p>';
		$main .= '<form method="post">';
		$main .= wp_nonce_field( 'lld_withdraw', 'lld_withdraw_nonce', true, false );
		$main .= lld_button( array( 'label' => 'Confirm' ) );
		$main .= '</form>';
		self::layout( 'Withdraw health consent', $main, true );
	}

	private static function delete_screen() {
		self::require_member_page();
		$member_id = LLD_Members_Access::current_member_id();
		$error     = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lld_delete_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_delete_nonce'] ) ), 'lld_delete' ) ) {
			$password = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
			$user     = wp_get_current_user();
			if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
				$error = 'Those details don\'t match our records.';
			} else {
				$row    = self::member_row( $member_id );
				$cancel = LLD_Members_Stripe::cancel_member( $member_id );
				LLD_Members_Mail::send_account_deleted( (string) $row->email, (string) $row->first_name );
				wp_logout();
				LLD_Members_Erasure::delete_account( $member_id, true );
				if ( is_wp_error( $cancel ) ) {
					LLD_Members_Mail::send_cancel_alert( (int) $row->id );
					$main = '<h1>Account closed</h1><p>Your account is closed. We\'re finishing cancelling your subscription with our payment provider and will email you when it\'s done.</p>';
				} else {
					$main = '<h1>Account closed</h1><p>Your account is closed. Your data will be removed within 24 hours.</p>';
				}
				self::layout( 'Account closed', $main, false );
				return;
			}
		}
		$main  = '<h1>Delete account</h1>';
		$main .= '<p>This deletes your account and cancels any subscription. For tax law we keep your name, email, payment amounts and dates.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/data/' ) ) . '">Download your data first</a></p>';
		$main .= self::error_box( $error );
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_delete', 'lld_delete_nonce', true, false );
		$main .= lld_password_field( array( 'id' => 'lld_password', 'name' => 'lld_password', 'label' => 'Password' ) );
		$main .= lld_button( array( 'label' => 'Delete my account' ) );
		$main .= '</form>';
		self::layout( 'Delete account', $main, true );
	}

	private static function email_confirmed() {
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$result = self::consume_link( $token, 'email_change' );
		if ( is_wp_error( $result ) ) {
			$main = '<h1>Link expired</h1><p>' . esc_html( $result->get_error_message() ) . '</p>';
			$main .= lld_button( array( 'label' => 'Back to profile', 'href' => home_url( '/members/settings/' ) ) );
			self::layout( 'Link expired', $main, false );
			return;
		}
		global $wpdb;
		$row = self::member_row( (int) $result['member_id'] );
		wp_update_user( array( 'ID' => (int) $row->user_id, 'user_email' => $result['new_email'] ) );
		self::sync_email( (int) $row->user_id );
		$main = '<h1>Email confirmed</h1><p>Your email is now ' . esc_html( $result['new_email'] ) . '.</p>';
		self::layout( 'Email confirmed', $main, false );
	}

	private static function unsubscribe() {
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$kind   = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$result = ( '' !== $code && class_exists( 'LLD_Members_Programme' ) ) ? LLD_Members_Programme::consume_unsub( $code, $kind ) : 'missing';
		if ( 'already' === $result ) {
			$main = '<h1>Already unsubscribed</h1><p>Already unsubscribed.</p>';
			self::layout( 'Already unsubscribed', $main, is_user_logged_in() );
			return;
		}
		$main = '<h1>Unsubscribed</h1><p>You won\'t get these emails any more.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/settings/' ) ) . '">Email preferences</a></p>';
		self::layout( 'Unsubscribed', $main, is_user_logged_in() );
	}

	/**
	 * Send a members reset link. Used by WordPress's lost-password page too. Nothing is sent for an unknown email.
	 *
	 * @param string $email Email.
	 */
	public static function send_reset_link( $email ) {
		self::request_reset( sanitize_email( $email ) );
	}

	/**
	 * @param string $email Email.
	 */
	private static function request_reset( $email ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return;
		}
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
		self::issue_link( $user, $member_id, 'password_reset', 1 );
	}

	/**
	 * @param string $token Token from the link.
	 * @param string $pass  New password.
	 * @return true|WP_Error
	 */
	private static function complete_reset( $token, $pass ) {
		if ( strlen( $pass ) < 10 ) {
			return new WP_Error( 'lld_password', 'Use at least 10 characters.' );
		}
		$result = self::consume_link( $token, 'password_reset' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row     = self::member_row( (int) $result['member_id'] );
		$user_id = $row ? (int) $row->user_id : (int) $result['user_id'];
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'lld_reset', 'That link has expired.' );
		}
		wp_set_password( $pass, $user_id );
		$tokens = WP_Session_Tokens::get_instance( $user_id );
		$tokens->destroy_all();
		if ( $row ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'session' ) . ' SET ended_at = %s WHERE member_id = %d AND ended_at IS NULL', gmdate( 'Y-m-d H:i:s' ), $row->id ) );
		}
		return true;
	}

	/**
	 * @param string $email Email the form named.
	 * @return true|WP_Error
	 */
	private static function resend_confirm( $email ) {
		// The reply is the same for every address: registered or not, confirmed or not.
		// The limit counts the typed address, so reaching it says nothing about an account either.
		$key   = 'lld_resend_' . substr( hash( 'sha256', strtolower( trim( (string) $email ) ) ), 0, 40 );
		$count = (int) get_transient( $key );
		if ( $count >= 3 ) {
			return new WP_Error( 'lld_confirm', 'Please wait a few minutes' );
		}
		set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user ) {
			return true;
		}
		global $wpdb;
		$member = $wpdb->get_row( $wpdb->prepare( 'SELECT id, email_confirmed_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
		if ( ! $member || ! empty( $member->email_confirmed_at ) ) {
			return true;
		}
		$table = LLD_Members_Schema::table( 'security_code' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT resend_count FROM {$table} WHERE user_id = %d AND kind = %s ORDER BY id DESC LIMIT 1", $user->ID, 'email_confirm' ) );
		self::issue_link( $user, (int) $member->id, 'email_confirm', 24, '', $row ? (int) $row->resend_count + 1 : 1 );
		return true;
	}

	/**
	 * @param WP_User $user        User.
	 * @param int     $member_id   Member number.
	 * @param string  $kind        Code kind.
	 * @param int     $hours       Lifetime in hours.
	 * @param string  $new_email   New address for an email change.
	 * @param int     $resend      Resend count.
	 */
	private static function issue_link( $user, $member_id, $kind, $hours, $new_email = '', $resend = 0 ) {
		global $wpdb;
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = LLD_Members_Clock::now( (int) $member_id )->modify( '+' . (int) $hours . ' hours' )->format( 'Y-m-d H:i:s' );
		$wpdb->insert(
			LLD_Members_Schema::table( 'security_code' ),
			array(
				'member_id'          => (int) $member_id,
				'user_id'            => (int) $user->ID,
				'kind'               => $kind,
				'code_hash'          => hash( 'sha256', $token ),
				'new_email'          => '' === $new_email ? null : $new_email,
				'pending_token_hash' => hash( 'sha256', $token ),
				'resend_count'       => (int) $resend,
				'expires_at'         => $expires,
			)
		);
		$field = 'token';
		$link = home_url( '/members/' . ( 'password_reset' === $kind ? 'reset' : ( 'email_change' === $kind ? 'email' : 'confirm' ) ) . '/?' . $field . '=' . rawurlencode( $token ) );
		if ( 'password_reset' === $kind ) {
			LLD_Members_Mail::send_reset( $user->user_email, $link );
		} elseif ( 'email_change' === $kind ) {
			LLD_Members_Mail::send_email_change( $new_email, $user->user_email, $link );
		} else {
			LLD_Members_Mail::send_confirm( $user->user_email, $link );
		}
	}

	/**
	 * @param string $token Token.
	 * @param string $kind  Code kind.
	 * @return string Empty when the link can still be used.
	 */
	private static function link_problem( $token, $kind ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'security_code' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE pending_token_hash = %s AND kind = %s ORDER BY id DESC LIMIT 1", hash( 'sha256', $token ), $kind ) );
		if ( ! $row ) {
			return 'That link has expired.';
		}
		if ( $row->used_at ) {
			return 'Already confirmed — sign in';
		}
		$now = LLD_Members_Clock::now( (int) $row->member_id )->format( 'Y-m-d H:i:s' );
		if ( $row->expires_at < $now ) {
			return 'That link has expired.';
		}
		return '';
	}

	private static function consume_link( $token, $kind ) {
		global $wpdb;
		if ( '' === $token ) {
			return new WP_Error( 'lld_link', 'That link has expired.' );
		}
		$table = LLD_Members_Schema::table( 'security_code' );
		$hash  = hash( 'sha256', $token );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE pending_token_hash = %s AND kind = %s ORDER BY id DESC LIMIT 1", $hash, $kind ) );
		if ( ! $row ) {
			return new WP_Error( 'lld_link', 'That link has expired.' );
		}
		if ( $row->used_at ) {
			return new WP_Error( 'lld_link', 'Already confirmed — sign in' );
		}
		$now = LLD_Members_Clock::now( (int) $row->member_id )->format( 'Y-m-d H:i:s' );
		if ( $row->expires_at < $now ) {
			return new WP_Error( 'lld_link', 'That link has expired.' );
		}
		$marked = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $row->id ) );
		if ( 1 !== (int) $marked ) {
			return new WP_Error( 'lld_link', 'Already confirmed — sign in' );
		}
		return array(
			'member_id' => (int) $row->member_id,
			'user_id'   => (int) $row->user_id,
			'new_email' => (string) $row->new_email,
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $type      Consent type.
	 * @param string $kind      given or withdrawn.
	 * @param string $version   Document version.
	 */
	private static function add_consent( $member_id, $type, $kind, $version ) {
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'consent' ),
			array(
				'member_id'        => (int) $member_id,
				'consent_type'     => $type,
				'change_kind'      => $kind,
				'document_version' => $version,
				'occurred_at'      => lld_members_now_mysql( (int) $member_id ),
			)
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function renewal_label( $member_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT access_state, period_ends_on FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
		if ( ! $row || 'free' === strtolower( (string) $row->access_state ) || ! $row->period_ends_on ) {
			return 'not applicable';
		}
		return (string) $row->period_ends_on;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function pending_email( $member_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT new_email FROM ' . LLD_Members_Schema::table( 'security_code' ) . " WHERE member_id = %d AND kind = 'email_change' AND used_at IS NULL ORDER BY id DESC LIMIT 1", $member_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function preference_fields( $member_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'email_pref' ) . ' WHERE member_id = %d', $member_id ) );
		$on  = $row && '1' === (string) $row->daily_reminder;
		$html  = '<fieldset class="lld-card"><legend>Email preferences</legend>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_welcome" value="1"' . ( $row && '1' === (string) $row->welcome ? ' checked' : '' ) . '> Welcome</label>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_reminder" value="1"' . ( $on ? ' checked' : '' ) . '> Daily reminder</label>';
		$html .= lld_field( array( 'id' => 'lld_reminder_time', 'name' => 'lld_reminder_time', 'label' => 'Reminder time', 'value' => $row ? (string) $row->reminder_time : '07:30' ) );
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_weekly" value="1"' . ( $row && '1' === (string) $row->weekly_review ? ' checked' : '' ) . '> Weekly review</label>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_end" value="1"' . ( $row && '1' === (string) $row->end_of_track ? ' checked' : '' ) . '> End of track</label>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_nudges" value="1"' . ( $row && '1' === (string) $row->nudges ? ' checked' : '' ) . '> Nudges</label>';
		$html .= '</fieldset>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function save_preferences( $member_id ) {
		global $wpdb;
		$table    = LLD_Members_Schema::table( 'email_pref' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT nudges_sent, last_nudge_on FROM {$table} WHERE member_id = %d", $member_id ) );
		$wpdb->replace(
			$table,
			array(
				'member_id'      => $member_id,
				'welcome'        => empty( $_POST['lld_welcome'] ) ? 0 : 1,
				'daily_reminder' => empty( $_POST['lld_reminder'] ) ? 0 : 1,
				'reminder_time'  => isset( $_POST['lld_reminder_time'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_reminder_time'] ) ) : null,
				'weekly_review'  => empty( $_POST['lld_weekly'] ) ? 0 : 1,
				'end_of_track'   => empty( $_POST['lld_end'] ) ? 0 : 1,
				'nudges'         => empty( $_POST['lld_nudges'] ) ? 0 : 1,
				'nudges_sent'    => $existing ? (int) $existing->nudges_sent : 0,
				'last_nudge_on'  => $existing ? $existing->last_nudge_on : null,
			)
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return string|WP_Error Download token.
	 */
	private static function build_download( $member_id ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'lld_zip', 'Try again' );
		}
		global $wpdb;
		$dir = lld_members_private_dir() . '/downloads';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$path  = $dir . '/' . $token . '.zip';
		$zip   = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE ) ) {
			return new WP_Error( 'lld_zip', 'Try again' );
		}
		$data = array();
		foreach ( LLD_Members_Schema::all_suffixes() as $suffix ) {
			if ( in_array( $suffix, array( 'security_code', 'sign_in_lock', 'session', 'app_key', 'error_log' ), true ) ) {
				continue;
			}
			$table   = LLD_Members_Schema::table( $suffix );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			$column  = null;
			foreach ( array( 'member_id', 'referrer_member_id', 'recipient_member_id', 'member_number' ) as $candidate ) {
				if ( in_array( $candidate, $columns, true ) ) {
					$column = $candidate;
					break;
				}
			}
			if ( ! $column ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %d", $member_id ), ARRAY_A );
			if ( 'something_else' === $suffix && $rows ) {
				foreach ( $rows as $index => $record ) {
					try {
						$rows[ $index ]['text'] = LLD_Members_Crypto::decrypt( (string) $record['text_ciphertext'], 'something_else:' . $record['id'] );
					} catch ( RuntimeException $e ) {
						$rows[ $index ]['text'] = '';
					}
					unset( $rows[ $index ]['text_ciphertext'] );
				}
			}
			if ( 'diary_meal' === $suffix && $rows ) {
				foreach ( $rows as $index => $record ) {
					if ( ! empty( $record['description_ciphertext'] ) ) {
						try {
							$rows[ $index ]['description'] = LLD_Members_Crypto::decrypt( (string) $record['description_ciphertext'], 'diary_meal:' . $record['id'] );
						} catch ( RuntimeException $e ) {
							$rows[ $index ]['description'] = '';
						}
					}
					unset( $rows[ $index ]['description_ciphertext'] );
				}
			}
			$data[ $suffix ] = $rows ? $rows : array();
		}
		$zip->addFromString( 'summary.html', '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Your Lifeline Daily data</title></head><body><h1>Your data</h1><p>This file was made when you asked for it.</p></body></html>' );
		$zip->addFromString( 'data.json', wp_json_encode( $data ) );
		$row = self::member_row( $member_id );
		if ( $row && $row->photo_path ) {
			$file = LLD_Members_Photos::resolve( $row->photo_path );
			if ( $file ) {
				$zip->addFile( $file, 'photos/' . basename( $file ) );
			}
		}
		$zip->close();
		set_transient( 'lld_dl_' . $token, array( 'path' => $path, 'member_id' => $member_id ), 10 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $token     Download token.
	 */
	private static function send_download( $member_id, $token ) {
		$stored = get_transient( 'lld_dl_' . $token );
		delete_transient( 'lld_dl_' . $token );
		if ( ! is_array( $stored ) || (int) $stored['member_id'] !== (int) $member_id || ! is_readable( $stored['path'] ) ) {
			$main = '<h1>My data</h1><p>Try again</p>';
			self::layout( 'My data', $main, true );
			return;
		}
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="lifeline-daily-data.zip"' );
		header( 'Cache-Control: no-store' );
		readfile( $stored['path'] );
		unlink( $stored['path'] );
		exit;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member_row( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}

	public static function require_confirmed_page() {
		self::require_confirmed();
	}

	private static function require_confirmed() {
		self::require_member_page();
		global $wpdb;
		$member_id = LLD_Members_Access::current_member_id();
		$confirmed = $wpdb->get_var( $wpdb->prepare( 'SELECT email_confirmed_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! $confirmed ) {
			wp_safe_redirect( home_url( '/members/confirm/' ) );
			exit;
		}
	}

	private static function require_member_page() {
		if ( ! is_user_logged_in() || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( add_query_arg( array() ) ) ) ) );
			exit;
		}
	}

	/**
	 * @param string $error Message.
	 */
	private static function error_box( $error ) {
		if ( '' === $error ) {
			return '';
		}
		$html = '<div class="lld-fail" role="alert"><p>' . esc_html( $error ) . '</p>';
		if ( false !== strpos( $error, 'calculators' ) ) {
			$html .= '<p><a href="' . esc_url( home_url( '/tools/' ) ) . '">Free calculators</a> <a href="' . esc_url( home_url( '/articles/' ) ) . '">Articles</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * @param string $title    Title.
	 * @param string $main     Main HTML.
	 * @param bool   $signed_in Signed in.
	 */
	private static function layout( $title, $main, $signed_in ) {
		lld_members_layout(
			array(
				'title'     => $title,
				'main'      => $main,
				'signed_in' => $signed_in,
			)
		);
	}
}
