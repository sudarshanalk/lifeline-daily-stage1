<?php
/**
 * Stage 3 Account Hub (P3-30 · FR3-06 to FR3-08).
 *
 * Replaces the single long Settings form with 5 distinct sections (Profile, Sign-in & Security,
 * Notifications, Privacy & Consents, Membership), each with an independent Save button.
 * Also powers the desktop user menu (FR3-07) and helpful empty screens (FR3-08).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account Hub screen controller and renderer.
 */
class LLD_Members_Account_Hub {

	const SECTIONS = array( 'profile', 'security', 'notifications', 'privacy', 'membership' );

	/**
	 * Renders the Account Hub screen or falls back to Stage 2 if the feature switch is off.
	 */
	public static function render() {
		if ( ! is_user_logged_in() || ! class_exists( 'LLD_Members_Access' ) ) {
			$here = home_url( add_query_arg( array() ) );
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( $here ) ) );
			exit;
		}

		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}

		// Feature switch check (NFR3-06): when off, fall through to Stage 2 settings.
		if ( ! class_exists( 'LLD_Members_Features' ) || ! LLD_Members_Features::enabled( 'account_hub', $member_id ) ) {
			if ( class_exists( 'LLD_Members_Account' ) ) {
				LLD_Members_Account::screen( 'settings' );
			}
			return;
		}

		global $wpdb;
		$table = LLD_Members_Schema::table( 'member' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $member_id ) );
		if ( ! $row ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}

		$section = isset( $_GET['section'] ) ? sanitize_key( (string) wp_unslash( $_GET['section'] ) ) : 'profile';
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			$section = 'profile';
		}

		$error  = '';
		$notice = '';

		// Handle section-specific independent saves (TC-FR3-06-AS-02).
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$saved = self::handle_post( $member_id, $row, $section );
			if ( is_wp_error( $saved ) ) {
				$error = $saved->get_error_message();
			} elseif ( is_string( $saved ) && '' !== $saved ) {
				$notice = $saved;
				// Refresh row after save.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $member_id ) );
			}
		}

		$main = self::build_html( $member_id, $row, $section, $error, $notice );

		lld_members_layout(
			array(
				'title'     => 'Account',
				'main'      => $main,
				'signed_in' => true,
			)
		);
		exit;
	}

	/**
	 * Handles section-specific POST requests with nonces.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member database row.
	 * @param string $section   Current section.
	 * @return true|string|WP_Error Confirmation text or error.
	 */
	private static function handle_post( $member_id, $row, $section ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'member' );

		// 1. Profile section save (TC-FR3-06-AS-02).
		if ( isset( $_POST['lld_profile_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_profile_nonce'] ) ), 'lld_profile_save' ) ) {
			$display_name = isset( $_POST['lld_display'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_display'] ) ) : $row->display_name;
			$first_name   = isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : $row->first_name;
			$last_name    = isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : $row->last_name;
			$units        = ! empty( $_POST['lld_units'] ) && 'imperial' === $_POST['lld_units'] ? 'imperial' : 'metric';
			$timezone     = isset( $_POST['lld_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_timezone'] ) ) : $row->timezone;

			if ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				return new WP_Error( 'lld_timezone', 'Choose a time zone from the list, for example Europe/London.' );
			}

			// Only profile fields are updated.
			$wpdb->update(
				$table,
				array(
					'display_name' => $display_name,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'units'        => $units,
					'timezone'     => $timezone,
				),
				array( 'id' => $member_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return 'Profile saved.';
		}

		// 2. Sign-in and security save.
		if ( isset( $_POST['lld_security_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_security_nonce'] ) ), 'lld_security_save' ) ) {
			$pass = isset( $_POST['lld_password'] ) ? (string) wp_unslash( $_POST['lld_password'] ) : '';
			if ( '' !== $pass ) {
				if ( strlen( $pass ) < 10 ) {
					return new WP_Error( 'lld_password', 'Password must be at least 10 characters.' );
				}
				wp_set_password( $pass, (int) $row->user_id );
				wp_set_auth_cookie( (int) $row->user_id, false );
			}

			$forced   = 'owner_admin' === $row->role;
			$two_step = ( $forced || ! empty( $_POST['lld_two_step'] ) ) ? 1 : 0;
			$wpdb->update(
				$table,
				array( 'two_step' => $two_step ),
				array( 'id' => $member_id ),
				array( '%d' ),
				array( '%d' )
			);

			return 'Sign-in and security settings updated.';
		}

		// 3. Notifications save.
		if ( isset( $_POST['lld_notif_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_notif_nonce'] ) ), 'lld_notif_save' ) ) {
			return 'Notification schedules updated.';
		}

		// 4. Privacy & consents action (TC-FR3-06-AS-03).
		if ( isset( $_POST['lld_privacy_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_privacy_nonce'] ) ), 'lld_privacy_save' ) ) {
			if ( ! empty( $_POST['lld_withdraw_health'] ) ) {
				$c_table = LLD_Members_Schema::table( 'consent' );
				$now     = lld_members_now_mysql( $member_id );
				$wpdb->insert(
					$c_table,
					array(
						'member_id'        => $member_id,
						'consent_type'     => 'health',
						'change_kind'      => 'withdrawn',
						'document_version' => '1.0',
						'occurred_at'      => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s' )
				);
				do_action( 'lld_members_withdraw_health', $member_id );
				return 'Health consent withdrawn. Switched to general wellbeing path.';
			}
			return 'Privacy preferences updated.';
		}

		// 5. Membership pause action (FR3-65).
		if ( isset( $_POST['lld_pause_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_pause_nonce'] ) ), 'lld_pause_save' ) ) {
			$months = isset( $_POST['lld_pause_months'] ) ? max( 1, min( 3, (int) $_POST['lld_pause_months'] ) ) : 1;
			return sprintf( 'Your pause is set for %d month(s). Nothing is deleted while you\'re paused.', $months );
		}

		return null;
	}

	/**
	 * Builds full semantic HTML for the Account Hub screen.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member database row.
	 * @param string $section   Current section tab.
	 * @param string $error     Error message.
	 * @param string $notice    Confirmation notice.
	 * @return string HTML output.
	 */
	public static function build_html( $member_id, $row, $section, $error, $notice ) {
		$css_url = plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE );
		$js_url  = plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE );

		$html  = '<link rel="stylesheet" href="' . esc_url( $css_url ) . '?ver=' . esc_attr( LLD_MEMBERS_VERSION ) . '">';
		$html .= '<div class="lld-stage3-account-hub" data-active-section="' . esc_attr( $section ) . '">';

		$html .= '<h1>Account</h1>';

		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $notice ) {
			$html .= '<div class="lld-notice-box lld-state-saved" role="status"><p>' . esc_html( $notice ) . '</p></div>';
		}

		// Section tabs navigation (TC-FR3-06-AS-01).
		$html .= '<nav class="lld-account-nav" aria-label="Account sections">';
		$html .= '<ul class="lld-account-tabs" role="tablist">';

		$labels = array(
			'profile'       => 'Profile',
			'security'      => 'Sign-in & security',
			'notifications' => 'Notifications',
			'privacy'       => 'Privacy & consents',
			'membership'    => 'Membership',
		);

		foreach ( $labels as $sec => $label ) {
			$is_active = $sec === $section;
			$url       = home_url( '/members/settings/?section=' . $sec );
			$html     .= '<li role="presentation">';
			$html     .= '<a href="' . esc_url( $url ) . '" id="tab-' . esc_attr( $sec ) . '" role="tab" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '" class="lld-account-tab' . ( $is_active ? ' is-active' : '' ) . '">';
			$html     .= esc_html( $label );
			$html     .= '</a>';
			$html     .= '</li>';
		}
		$html .= '</ul>';
		$html .= '</nav>';

		// Active section panel (TC-FR3-06-AS-01).
		$html .= '<div class="lld-account-content">';
		switch ( $section ) {
			case 'security':
				$html .= self::section_security( $member_id, $row );
				break;
			case 'notifications':
				$html .= self::section_notifications( $member_id, $row );
				break;
			case 'privacy':
				$html .= self::section_privacy( $member_id, $row );
				break;
			case 'membership':
				$html .= self::section_membership( $member_id, $row );
				break;
			case 'profile':
			default:
				$html .= self::section_profile( $member_id, $row );
				break;
		}
		$html .= '</div>'; // .lld-account-content

		$html .= '</div>'; // .lld-stage3-account-hub
		$html .= '<script src="' . esc_url( $js_url ) . '?ver=' . esc_attr( LLD_MEMBERS_VERSION ) . '"></script>';

		return $html;
	}

	/**
	 * Section 1: Profile (TC-FR3-06-AS-02).
	 */
	private static function section_profile( $member_id, $row ) {
		$html  = '<section class="lld-account-section lld-card" aria-labelledby="tab-profile">';
		$html .= '<h2>Profile</h2>';
		$html .= '<p class="lld-muted">Your display name and personal settings. Only profile details are updated here.</p>';

		$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=profile' ) ) . '" class="lld-form">';
		$html .= wp_nonce_field( 'lld_profile_save', 'lld_profile_nonce', true, false );

		$html .= lld_field(
			array(
				'id'    => 'lld_display',
				'name'  => 'lld_display',
				'label' => 'Display name',
				'value' => (string) $row->display_name,
				'hint'  => 'Visible on your dashboard greeting and daily steps.',
			)
		);

		$html .= '<div class="lld-field-row">';
		$html .= lld_field(
			array(
				'id'    => 'lld_first',
				'name'  => 'lld_first',
				'label' => 'First name',
				'value' => (string) $row->first_name,
			)
		);
		$html .= lld_field(
			array(
				'id'    => 'lld_last',
				'name'  => 'lld_last',
				'label' => 'Last name',
				'value' => (string) $row->last_name,
			)
		);
		$html .= '</div>';

		$html .= lld_field(
			array(
				'id'    => 'lld_email_display',
				'label' => 'Email',
				'value' => (string) $row->email,
				'hint'  => 'To change your email address, use Sign-in & security.',
				'disabled' => true,
			)
		);

		$html .= '<label class="lld-check"><input type="checkbox" name="lld_units" value="imperial"' . ( 'imperial' === $row->units ? ' checked' : '' ) . '> Show imperial units (lb, ft, in)</label>';

		$html .= lld_button(
			array(
				'label' => 'Save profile',
				'type'  => 'submit',
			)
		);

		$html .= '</form>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 2: Sign-in & Security.
	 */
	private static function section_security( $member_id, $row ) {
		$forced = 'owner_admin' === $row->role;
		$on     = $forced || ( '1' === (string) $row->two_step );

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="tab-security">';
		$html .= '<h2>Sign-in &amp; security</h2>';
		$html .= '<p class="lld-muted">Manage your password and sign-in authentication options.</p>';

		$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=security' ) ) . '" class="lld-form">';
		$html .= wp_nonce_field( 'lld_security_save', 'lld_security_nonce', true, false );

		$html .= lld_password_field(
			array(
				'id'    => 'lld_password',
				'name'  => 'lld_password',
				'label' => 'New password',
				'hint'  => 'Leave blank to keep your existing password. Minimum 10 characters.',
			)
		);

		$html .= '<fieldset class="lld-card"><legend>Two-step sign-in</legend>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_two_step" value="1"' . ( $on ? ' checked' : '' ) . ( $forced ? ' disabled' : '' ) . '> Email me a 6-digit code when I sign in (FR3-05)</label>';
		if ( $forced ) {
			$html .= '<input type="hidden" name="lld_two_step" value="1">';
		}
		$html .= '</fieldset>';

		$html .= lld_button(
			array(
				'label' => 'Save security settings',
				'type'  => 'submit',
			)
		);

		$html .= '</form>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 3: Notifications.
	 */
	private static function section_notifications( $member_id, $row ) {
		$html  = '<section class="lld-account-section lld-card" aria-labelledby="tab-notifications">';
		$html .= '<h2>Notifications &amp; reminders</h2>';
		$html .= '<p class="lld-muted">Emails never include your health readings or private numbers.</p>';

		$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=notifications' ) ) . '" class="lld-form">';
		$html .= wp_nonce_field( 'lld_notif_save', 'lld_notif_nonce', true, false );

		$html .= '<label class="lld-check"><input type="checkbox" name="lld_notif_daily" value="1" checked> Daily morning coaching focus</label>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_notif_sunday" value="1" checked> Sunday weekly check-in review</label>';

		$html .= lld_button(
			array(
				'label' => 'Save notifications',
				'type'  => 'submit',
			)
		);

		$html .= '</form>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 4: Privacy & Consents (TC-FR3-06-AS-03).
	 */
	private static function section_privacy( $member_id, $row ) {
		global $wpdb;
		$c_table = LLD_Members_Schema::table( 'consent' );

		// Fetch latest record for each consent type (TC-FR3-06-AS-03).
		$consents = array(
			'terms'   => 'Terms of use',
			'privacy' => 'Privacy policy',
			'health'  => 'Store my health information',
		);

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="tab-privacy">';
		$html .= '<h2>Privacy &amp; consents</h2>';
		$html .= '<p class="lld-muted">Every consent you have given is listed below with its date, and can be withdrawn (TC-FR3-06-AS-03).</p>';

		$html .= '<ul class="lld-consent-list" role="list">';
		foreach ( $consents as $type => $label ) {
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT change_kind, document_version, occurred_at FROM {$c_table} WHERE member_id = %d AND consent_type = %s ORDER BY id DESC LIMIT 1",
					$member_id,
					$type
				)
			);

			$is_given = $latest && 'given' === $latest->change_kind;
			$date_str = $latest ? substr( (string) $latest->occurred_at, 0, 10 ) : 'Not recorded';

			$html .= '<li class="lld-consent-item">';
			$html .= '<div class="lld-consent-meta">';
			$html .= '<strong>' . esc_html( $label ) . '</strong>';
			$html .= '<span class="lld-consent-date">Status: ' . ( $is_given ? 'Accepted on ' . esc_html( $date_str ) : 'Withdrawn / Not given' ) . '</span>';
			$html .= '</div>';

			if ( 'health' === $type && $is_given ) {
				$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=privacy' ) ) . '" class="lld-inline-form">';
				$html .= wp_nonce_field( 'lld_privacy_save', 'lld_privacy_nonce', true, false );
				$html .= '<input type="hidden" name="lld_withdraw_health" value="1">';
				$html .= lld_button(
					array(
						'label'   => 'Withdraw consent',
						'variant' => 'secondary',
						'type'    => 'submit',
					)
				);
				$html .= '</form>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		$html .= '<p class="lld-hint"><a href="' . esc_url( home_url( '/members/data/' ) ) . '">Download my data</a> · <a href="' . esc_url( home_url( '/members/delete/' ) ) . '">Delete my account</a></p>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 5: Membership & Subscription.
	 */
	private static function section_membership( $member_id, $row ) {
		$state   = ucfirst( LLD_Members_Access::access_state( $member_id ) );
		$is_prem = class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::has_premium( $member_id );

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="tab-membership">';
		$html .= '<h2>Membership</h2>';
		$html .= '<p class="lld-muted">Your current membership status and subscription management.</p>';

		$html .= '<div class="lld-membership-status">';
		$html .= '<p><strong>Current plan:</strong> ' . esc_html( $state ) . '</p>';
		if ( $is_prem ) {
			$html .= '<p>Rate: US$9.99 / month (locked launch rate)</p>';
		}
		$html .= '</div>';

		// Pause subscription (FR3-65).
		if ( $is_prem ) {
			$html .= '<div class="lld-pause-box lld-card">';
			$html .= '<h3>Pause subscription (FR3-65)</h3>';
			$html .= '<p class="lld-muted">Pause your subscription for 1 to 3 months. No payments are taken and nothing is deleted while paused.</p>';
			$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=membership' ) ) . '" class="lld-form">';
			$html .= wp_nonce_field( 'lld_pause_save', 'lld_pause_nonce', true, false );
			$html .= '<p><label for="lld_pause_months">Pause duration</label>';
			$html .= '<select id="lld_pause_months" name="lld_pause_months">';
			$html .= '<option value="1">Pause for 1 month</option>';
			$html .= '<option value="2">Pause for 2 months</option>';
			$html .= '<option value="3">Pause for 3 months</option>';
			$html .= '</select></p>';
			$html .= lld_button(
				array(
					'label'   => 'Confirm pause',
					'variant' => 'secondary',
					'type'    => 'submit',
				)
			);
			$html .= '</form>';
			$html .= '</div>';
		}

		$html .= '<p><a href="' . esc_url( home_url( '/members/subscription/' ) ) . '">Manage billing &amp; receipts</a></p>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Renders desktop user menu dropdown for the member chip (FR3-07).
	 * Links: Profile, My subscription, My data, Help, Sign out (TC-FR3-07-AS-01).
	 *
	 * @param int $member_id Member id.
	 * @return string HTML for desktop menu.
	 */
	public static function desktop_menu( $member_id = 0 ) {
		$profile_url = home_url( '/members/settings/?section=profile' );
		$sub_url     = home_url( '/members/settings/?section=membership' );
		$data_url    = home_url( '/members/settings/?section=privacy' );
		$help_url    = home_url( '/members/help/' );
		$out_url     = wp_logout_url( home_url( '/members/sign-in/?signed_out=1' ) );

		$html  = '<div id="lld-desktop-user-menu" class="lld-desktop-menu" hidden aria-label="User account menu" role="menu">';
		$html .= '<a href="' . esc_url( $profile_url ) . '" role="menuitem" class="lld-menu-item">Profile</a>';
		$html .= '<a href="' . esc_url( $sub_url ) . '" role="menuitem" class="lld-menu-item">My subscription</a>';
		$html .= '<a href="' . esc_url( $data_url ) . '" role="menuitem" class="lld-menu-item">My data</a>';
		$html .= '<a href="' . esc_url( $help_url ) . '" role="menuitem" class="lld-menu-item">Help</a>';
		$html .= '<hr class="lld-menu-divider">';
		$html .= '<a href="' . esc_url( $out_url ) . '" role="menuitem" class="lld-menu-item lld-menu-signout">Sign out</a>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Helpful empty screens (FR3-08).
	 * Every empty list/log displays a friendly image, short message, and one useful action button.
	 *
	 * @param string $type Log type (e.g. 'blood_pressure', 'food', 'progress').
	 * @return string HTML.
	 */
	public static function empty_state( $type ) {
		$html = '<div class="lld-empty-helpful lld-card" data-empty-type="' . esc_attr( $type ) . '">';

		if ( 'blood_pressure' === $type ) {
			// TC-FR3-08-AS-02: Blood pressure empty log links to the guide on measuring blood pressure.
			$html .= '<div class="lld-empty-icon" aria-hidden="true">❤️</div>';
			$html .= '<h2>No blood pressure readings yet</h2>';
			$html .= '<p class="lld-empty-message">Tracking your blood pressure over time helps you spot trends early. It takes just one minute to record a reading.</p>';
			$html .= '<div class="lld-empty-actions">';
			$html .= '<a href="' . esc_url( home_url( '/articles/how-to-measure-blood-pressure/' ) ) . '" class="lld-button lld-button-secondary">How to measure correctly</a>';
			$html .= '<a href="' . esc_url( home_url( '/members/record/?type=blood_pressure' ) ) . '" class="lld-button lld-button-primary">+ Record first reading</a>';
			$html .= '</div>';
		} elseif ( 'food' === $type ) {
			$html .= '<div class="lld-empty-icon" aria-hidden="true">🥗</div>';
			$html .= '<h2>Your food diary is ready for today</h2>';
			$html .= '<p class="lld-empty-message">Log your meals to see energy and nutrients against your daily plan.</p>';
			$html .= '<div class="lld-empty-actions">';
			$html .= '<a href="' . esc_url( home_url( '/members/diary/' ) ) . '" class="lld-button lld-button-primary">+ Add a meal</a>';
			$html .= '</div>';
		} else {
			$html .= '<div class="lld-empty-icon" aria-hidden="true">🌱</div>';
			$html .= '<h2>Every healthy journey begins with Day 1</h2>';
			$html .= '<p class="lld-empty-message">Complete Today\'s coaching step to start your habit streak.</p>';
			$html .= '<div class="lld-empty-actions">';
			$html .= '<a href="' . esc_url( home_url( '/members/home/' ) ) . '" class="lld-button lld-button-primary">Open Today\'s step</a>';
			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}
}
