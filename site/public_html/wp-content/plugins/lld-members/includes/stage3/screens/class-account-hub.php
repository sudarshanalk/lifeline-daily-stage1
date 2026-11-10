<?php
/**
 * Stage 3 Account Hub (P3-30 · FR3-06 to FR3-08).
 *
 * Shows the member settings screen (/members/settings/) as five sections (Profile, Sign-in & security,
 * Notifications, Privacy & consents, Membership) for members the `account_hub` switch applies to. It takes the
 * request at template_redirect priority 5, before the Stage 2 dispatcher (priority 10); for everyone else, and
 * for the full Stage 2 settings form (?all=1 and its posts), it returns and Stage 2 handles the request untouched
 * (NFR3-06). It adds no rewrite rule and edits no Stage 2 file.
 *
 * Sign-in changes (P3-20 to P3-22), notifications (P3-85) and pause (P3-63) are later packages: their sections
 * show state only. Health consent is withdrawn through the Stage 2 /members/withdraw/ screen, never here.
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
	const HANDLE   = 'lld-members-stage3';

	/**
	 * Called by the Stage 3 screens loader. Hooks nothing while the switch is off for everyone.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'account_hub' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * Renders the hub for the member settings screen when the switch is on for the signed-in member;
	 * otherwise returns so the Stage 2 dispatcher handles the request exactly as before.
	 */
	public static function maybe_render() {
		if ( 'settings' !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		// The full Stage 2 settings form stays reachable (and its own posts stay with Stage 2).
		if ( isset( $_GET['all'] ) || isset( $_POST['lld_settings_nonce'] ) ) {
			return;
		}
		// The Stage 2 maintenance screen wins while the member area is closed.
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! class_exists( 'LLD_Members_Access' ) ) {
			return;
		}
		$member_id = (int) LLD_Members_Access::current_member_id();
		if ( $member_id < 1 || ! LLD_Members_Features::enabled( 'account_hub', $member_id ) ) {
			return;
		}
		$row = self::member_row( $member_id );
		if ( ! $row ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		self::render( $member_id, $row );
		exit;
	}

	/**
	 * Renders the Account Hub screen.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member row.
	 */
	public static function render( $member_id, $row ) {
		$section = isset( $_GET['section'] ) ? sanitize_key( (string) wp_unslash( $_GET['section'] ) ) : 'profile';
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			$section = 'profile';
		}

		$error  = '';
		$notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$saved = self::handle_post( $member_id, $row );
			if ( is_wp_error( $saved ) ) {
				$error = $saved->get_error_message();
			} elseif ( true === $saved ) {
				// Post-redirect-get: a reload never posts the form again.
				wp_safe_redirect( home_url( '/members/settings/?section=profile&saved=1' ) );
				exit;
			}
		}
		if ( '' === $error && 'profile' === $section && isset( $_GET['saved'] ) ) {
			$notice = 'Profile saved.';
		}

		ob_start();
		lld_members_layout(
			array(
				'title'     => 'Account',
				'main'      => self::build_html( $member_id, $row, $section, $error, $notice ),
				'signed_in' => true,
			)
		);
		$page = (string) ob_get_clean();

		// The Stage 2 layout has no wp_head()/wp_footer(), so the enqueued handles are printed into its head and foot.
		ob_start();
		wp_print_styles( array( self::HANDLE ) );
		$styles = (string) ob_get_clean();
		ob_start();
		wp_print_scripts( array( self::HANDLE ) );
		$scripts = (string) ob_get_clean();
		$head    = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}
		$foot = strrpos( $page, '</body>' );
		if ( false !== $foot ) {
			$page = substr_replace( $page, $scripts, $foot, 0 );
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The member row. TODO lead: public accessor (LLD_Members_Account::member_row() is private).
	 *
	 * @param int $member_id Member id.
	 * @return object|null
	 */
	private static function member_row( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}

	/**
	 * Latest consent line of each type, for the dates. TODO lead: public accessor
	 * (LLD_Members_Account::latest_consent() is private; has_consent() is public and gives the state).
	 *
	 * @param int $member_id Member id.
	 * @return array<string,object> Latest line per consent type.
	 */
	private static function consent_dates( $member_id ) {
		global $wpdb;
		$lines  = $wpdb->get_results( $wpdb->prepare( 'SELECT consent_type, change_kind, occurred_at FROM ' . LLD_Members_Schema::table( 'consent' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ) );
		$latest = array();
		foreach ( (array) $lines as $line ) {
			$latest[ (string) $line->consent_type ] = $line;
		}
		return $latest;
	}

	/**
	 * Handles the Profile section's own save. The other sections have no save here: sign-in changes, notifications
	 * and pause are later packages, and consent goes through the Stage 2 screens.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member row.
	 * @return true|WP_Error|null True once saved, an error when the save failed, or null when nothing was posted.
	 */
	private static function handle_post( $member_id, $row ) {
		if ( ! isset( $_POST['lld_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_profile_nonce'] ) ), 'lld_profile_save' ) ) {
			return null;
		}
		global $wpdb;
		// Only the profile fields of this section, the same columns and sanitising as the Stage 2 form.
		// TODO lead: public writer (LLD_Members_Account::save_profile() is private and saves the whole form).
		$updated = $wpdb->update(
			LLD_Members_Schema::table( 'member' ),
			array(
				'display_name' => isset( $_POST['lld_display'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_display'] ) ) : $row->display_name,
				'first_name'   => isset( $_POST['lld_first'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_first'] ) ) : $row->first_name,
				'last_name'    => isset( $_POST['lld_last'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_last'] ) ) : $row->last_name,
				'units'        => empty( $_POST['lld_units'] ) ? 'metric' : 'imperial',
			),
			array( 'id' => $member_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		// update() gives the number of rows changed (0 when nothing changed) or false on a database error.
		if ( false === $updated ) {
			return new WP_Error( 'lld_profile_save', 'Try again' );
		}
		return true;
	}

	/**
	 * Builds the hub markup.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member row.
	 * @param string $section   Current section.
	 * @param string $error     Error message.
	 * @param string $notice    Confirmation notice.
	 * @return string HTML.
	 */
	public static function build_html( $member_id, $row, $section, $error, $notice ) {
		$menu = array(
			'profile'      => home_url( '/members/settings/?section=profile' ),
			'subscription' => home_url( '/members/settings/?section=membership' ),
			'data'         => home_url( '/members/settings/?section=privacy' ),
			'help'         => home_url( '/members/help/' ),
		);
		$html  = '<div class="lld-stage3-account-hub" data-active-section="' . esc_attr( $section ) . '"';
		$html .= ' data-lld-menu="' . esc_attr( (string) wp_json_encode( $menu ) ) . '"';
		$html .= ' data-lld-sign-out="lld-hub-sign-out">';
		$html .= '<h1>Account</h1>';
		// The menu's Sign out submits this form to the Stage 2 sign-out (the More screen), which closes the member's
		// session line, signs this browser out and goes to /members/sign-in/?signed_out=1.
		$html .= '<form method="post" action="' . esc_url( home_url( '/members/more/' ) ) . '" id="lld-hub-sign-out" class="lld-sign-out" hidden>';
		$html .= wp_nonce_field( 'lld_sign_out', 'lld_sign_out_nonce', true, false );
		$html .= '<button type="submit">Sign out</button></form>';

		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $notice ) {
			$html .= '<div class="lld-notice-box lld-state-saved" role="status"><p>' . esc_html( $notice ) . '</p></div>';
		}

		$labels = array(
			'profile'       => 'Profile',
			'security'      => 'Sign-in & security',
			'notifications' => 'Notifications',
			'privacy'       => 'Privacy & consents',
			'membership'    => 'Membership',
		);
		$html .= '<nav class="lld-account-nav" aria-label="Account sections"><ul class="lld-account-tabs">';
		foreach ( $labels as $sec => $label ) {
			$is_active = $sec === $section;
			$html     .= '<li><a href="' . esc_url( home_url( '/members/settings/?section=' . $sec ) ) . '" id="tab-' . esc_attr( $sec ) . '" class="lld-account-tab' . ( $is_active ? ' is-active' : '' ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul></nav>';

		$html .= '<div class="lld-account-content">';
		switch ( $section ) {
			case 'security':
				$html .= self::section_security( $row );
				break;
			case 'notifications':
				$html .= self::section_notifications();
				break;
			case 'privacy':
				$html .= self::section_privacy( $member_id );
				break;
			case 'membership':
				$html .= self::section_membership( $member_id );
				break;
			case 'profile':
			default:
				$html .= self::section_profile( $row );
				break;
		}
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Link to the full Stage 2 settings form, where sign-in, email and notification changes are made today.
	 */
	private static function all_settings_url() {
		return home_url( '/members/settings/?all=1' );
	}

	/**
	 * Section 1: Profile, with its own save.
	 *
	 * @param object $row Member row.
	 */
	private static function section_profile( $row ) {
		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-profile">';
		$html .= '<h2 id="lld-section-profile">Profile</h2>';
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
				'id'           => 'lld_first',
				'name'         => 'lld_first',
				'label'        => 'First name',
				'autocomplete' => 'given-name',
				'value'        => (string) $row->first_name,
			)
		);
		$html .= lld_field(
			array(
				'id'           => 'lld_last',
				'name'         => 'lld_last',
				'label'        => 'Last name',
				'autocomplete' => 'family-name',
				'value'        => (string) $row->last_name,
			)
		);
		$html .= '</div>';
		// Read-only, not disabled: it stays focusable and is read out. It has no name, so it is never posted.
		$email = lld_field(
			array(
				'id'    => 'lld_email_display',
				'label' => 'Email',
				'value' => (string) $row->email,
				'hint'  => 'To change your email address, use all settings.',
			)
		);
		$html .= str_replace( '<input id="lld_email_display" name="lld_email_display"', '<input id="lld_email_display" readonly aria-describedby="lld_email_display-hint"', $email );
		$html .= '<p><a class="lld-account-link" href="' . esc_url( self::all_settings_url() ) . '">All settings</a></p>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_units" value="imperial"' . ( 'imperial' === $row->units ? ' checked' : '' ) . '> Show imperial units (lb, ft, in)</label>';
		$html .= lld_button(
			array(
				'label' => 'Save profile',
				'type'  => 'submit',
			)
		);
		$html .= '</form>';
		$html .= self::hw_answers_html( (int) $row->id );
		$html .= '</section>';
		return $html;
	}

	/**
	 * P3-35: the member's Healthy Weight answers (each approved question with its answer) and the way to change them
	 * and the activity level (/members/goal/?hw=answers). Shown only once the programme is built and answers exist.
	 *
	 * @param int $member_id Member id.
	 */
	private static function hw_answers_html( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Hw_Screen_Parts' ) || ! class_exists( 'LLD_Members_Goal_Setting' ) || ! LLD_Members_Goal_Setting::hw_flow() || ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return '';
		}
		$c       = 'LLD_Members_Hw_Screen_Parts';
		$answers = $c::answers( $member_id );
		$rows    = '';
		foreach ( array_merge( array( 'activity_level' ), LLD_Members_Hw_Questions_Screen::JOINING ) as $key ) {
			$q = isset( $answers[ $key ] ) ? $c::question( $key ) : null;
			if ( ! $q ) {
				continue;
			}
			$shown = array();
			foreach ( (array) $answers[ $key ] as $v ) {
				foreach ( $q['choices'] as $choice ) {
					if ( (string) $v === $choice[0] ) {
						$shown[] = $choice[1];
					}
				}
				if ( ! $q['choices'] && is_scalar( $v ) ) {
					$shown[] = (string) $v;
				}
			}
			if ( $shown ) {
				$rows .= '<dt>' . esc_html( $q['text'] ) . '</dt><dd data-lld-answer="' . esc_attr( $key ) . '">' . esc_html( implode( ', ', $shown ) ) . '</dd>';
			}
		}
		if ( '' === $rows ) {
			return '';
		}
		$html  = '<div class="lld-hw-answers" data-lld-section="hw-answers"><h3>' . esc_html( $c::LABELS['questions_title'] ) . '</h3>';
		$html .= '<p class="lld-muted">' . esc_html( $c::LABELS['questions_intro'] ) . '</p><dl class="lld-account-state">' . $rows . '</dl>';
		$html .= '<p><a class="lld-account-link" href="' . esc_url( home_url( '/members/goal/?hw=answers' ) ) . '">Change your answers</a></p></div>';
		return $html;
	}

	/**
	 * Section 2: Sign-in & security. State only; changes are made in the Stage 2 settings until P3-20 to P3-22.
	 *
	 * @param object $row Member row.
	 */
	private static function section_security( $row ) {
		$forced = 'owner_admin' === $row->role;
		$on     = $forced || ( '1' === (string) $row->two_step );

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-security">';
		$html .= '<h2 id="lld-section-security">Sign-in &amp; security</h2>';
		$html .= '<p class="lld-muted">Manage your password and sign-in authentication options.</p>';
		$html .= '<dl class="lld-account-state">';
		$html .= '<dt>Two-step sign-in</dt><dd data-lld-two-step="' . ( $on ? 'on' : 'off' ) . '">' . ( $on ? 'On: we email you a code when you sign in.' : 'Off' ) . '</dd>';
		$html .= '</dl>';
		if ( $forced ) {
			$html .= '<p class="lld-hint">The owner admin account always uses an email code.</p>';
		}
		$html .= '<p><a class="lld-account-link" href="' . esc_url( self::all_settings_url() ) . '">Change your password or two-step sign-in</a></p>';
		// P3-20 to P3-22: linked Google and Apple accounts, unlinking, and sign out everywhere (FR3-05).
		if ( class_exists( 'LLD_Members_Sign_In' ) && ( LLD_Members_Sign_In::on( 'sign_in_social' ) || LLD_Members_Sign_In::on( 'sign_in_code' ) ) ) {
			$html .= LLD_Members_Sign_In::security_html( (int) $row->id, $row );
		}
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 3: Notifications. A placeholder until reminders arrive (P3-85); nothing here is saved.
	 */
	private static function section_notifications() {
		$html  = '<section class="lld-account-section lld-card lld-coming-soon" aria-labelledby="lld-section-notifications">';
		$html .= '<h2 id="lld-section-notifications">Notifications &amp; reminders</h2>';
		$html .= '<p class="lld-muted">Emails never include your health readings or private numbers.</p>';
		$html .= '<p class="lld-badge-soon">Coming soon</p>';
		$html .= '<fieldset disabled class="lld-form"><legend class="lld-sr">Reminder choices, coming soon</legend>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_notif_daily" value="1"> Daily morning coaching focus</label>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_notif_sunday" value="1"> Sunday weekly check-in review</label>';
		$html .= '</fieldset>';
		$html .= '<p><a class="lld-account-link" href="' . esc_url( self::all_settings_url() ) . '">Your current email choices are in all settings</a></p>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 4: Privacy & consents. Consents are read here and changed only through the Stage 2 screens.
	 *
	 * @param int $member_id Member id.
	 */
	private static function section_privacy( $member_id ) {
		$consents = array(
			'terms'     => 'Terms of use',
			'privacy'   => 'Privacy policy',
			'health'    => 'Store my health information',
			'marketing' => 'Marketing emails',
		);
		$dates = self::consent_dates( $member_id );

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-privacy">';
		$html .= '<h2 id="lld-section-privacy">Privacy &amp; consents</h2>';
		$html .= '<p class="lld-muted">Every consent you have given is listed below with its date, and how to change it.</p>';
		$html .= '<ul class="lld-consent-list">';
		foreach ( $consents as $type => $label ) {
			$is_given = LLD_Members_Account::has_consent( $member_id, $type );
			$date_str = isset( $dates[ $type ] ) ? substr( (string) $dates[ $type ]->occurred_at, 0, 10 ) : '';

			$html .= '<li class="lld-consent-item" data-consent-type="' . esc_attr( $type ) . '">';
			$html .= '<div class="lld-consent-meta">';
			$html .= '<strong>' . esc_html( $label ) . '</strong>';
			$html .= '<span class="lld-consent-date">Status: ' . ( $is_given && '' !== $date_str ? 'Accepted on ' . esc_html( $date_str ) : 'Withdrawn / Not given' ) . '</span>';
			$html .= '</div>';
			if ( 'terms' === $type || 'privacy' === $type ) {
				$html .= '<p class="lld-hint">Needed for your account. <a href="' . esc_url( home_url( '/members/delete/' ) ) . '">Delete my account</a> to end it.</p>';
			} elseif ( 'marketing' === $type ) {
				$html .= '<p class="lld-hint"><a href="' . esc_url( self::all_settings_url() ) . '">Change marketing emails in all settings</a></p>';
			} elseif ( 'health' === $type && $is_given ) {
				// The Stage 2 screen shows what will be deleted, then withdraws and deletes the health data.
				$html .= lld_button(
					array(
						'label'   => 'Withdraw consent',
						'variant' => 'secondary',
						'href'    => home_url( '/members/withdraw/' ),
					)
				);
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		$html .= '<p class="lld-hint lld-account-links"><a href="' . esc_url( home_url( '/members/data/' ) ) . '">Download my data</a> <span aria-hidden="true">·</span> <a href="' . esc_url( home_url( '/members/delete/' ) ) . '">Delete my account</a></p>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 5: Membership. Pause is a placeholder until P3-63; nothing here is saved.
	 *
	 * @param int $member_id Member id.
	 */
	private static function section_membership( $member_id ) {
		$state   = ucfirst( LLD_Members_Access::access_state( $member_id ) );
		$is_prem = class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::has_premium( $member_id );

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-membership">';
		$html .= '<h2 id="lld-section-membership">Membership</h2>';
		$html .= '<p class="lld-muted">Your current membership status and subscription management.</p>';
		$html .= '<div class="lld-membership-status">';
		$html .= '<p><strong>Current plan:</strong> ' . esc_html( $state ) . '</p>';
		$html .= '</div>';

		if ( $is_prem ) {
			$html .= '<div class="lld-pause-box lld-card lld-coming-soon">';
			$html .= '<h3>Pause subscription</h3>';
			$html .= '<p class="lld-badge-soon">Coming soon</p>';
			$html .= '<p class="lld-muted">Pause your subscription for 1 to 3 months. No payments are taken and nothing is deleted while paused.</p>';
			$html .= '<fieldset disabled class="lld-form"><legend class="lld-sr">Pause, coming soon</legend>';
			$html .= '<p><label for="lld_pause_months">Pause duration</label>';
			$html .= '<select id="lld_pause_months" name="lld_pause_months">';
			$html .= '<option value="1">Pause for 1 month</option>';
			$html .= '<option value="2">Pause for 2 months</option>';
			$html .= '<option value="3">Pause for 3 months</option>';
			$html .= '</select></p>';
			$html .= lld_button(
				array(
					'label'    => 'Confirm pause',
					'variant'  => 'secondary',
					'type'     => 'button',
					'disabled' => true,
				)
			);
			$html .= '</fieldset></div>';
		}

		$html .= '<p><a class="lld-account-link" href="' . esc_url( home_url( '/members/subscription/' ) ) . '">Manage billing &amp; receipts</a></p>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Helpful empty screens (FR3-08): a friendly picture, a short message and one useful action.
	 * The blood pressure wording is the owner-approved ST-HL-02 (stage3-safety-text.md, approved 3 Nov 2026).
	 *
	 * @param string $type Log type (e.g. 'blood_pressure', 'food', 'progress').
	 * @return string HTML.
	 */
	public static function empty_state( $type ) {
		$html = '<div class="lld-empty-helpful lld-card" data-empty-type="' . esc_attr( $type ) . '">';

		if ( 'blood_pressure' === $type ) {
			$html .= '<div class="lld-empty-icon" aria-hidden="true">❤️</div>';
			$html .= '<h2>No readings yet</h2>';
			$html .= '<p class="lld-empty-message">A good reading takes a few minutes of preparation. Sit quietly for 5 minutes, feet flat, back supported, arm resting at heart level. Don\'t talk during the reading. Take two readings a minute apart and record the second. Try the same time each day.</p>';
			$html .= '<div class="lld-empty-actions">';
			$html .= '<a href="' . esc_url( home_url( '/members/record/?type=blood_pressure' ) ) . '" class="lld-button lld-button-primary">Record my first reading</a>';
			$html .= '<a href="' . esc_url( home_url( '/articles/how-to-measure-blood-pressure/' ) ) . '" class="lld-button lld-button-secondary">How to measure blood pressure at home</a>';
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
