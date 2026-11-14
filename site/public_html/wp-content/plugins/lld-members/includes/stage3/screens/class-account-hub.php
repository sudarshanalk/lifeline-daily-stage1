<?php
/**
 * Stage 3 Account Hub (P3-30 · FR3-06 to FR3-08).
 *
 * Shows the member settings screen (/members/settings/) as five sections (Profile, Sign-in & security,
 * Notifications, Privacy & consents, Membership) for members the `account_hub` switch applies to. It takes the
 * request at template_redirect priority 5, before the Stage 2 dispatcher (priority 10). With the hub off it returns
 * and Stage 2 handles the request. With the hub on, the old long-form address (?all=1) redirects to the matching Account tab (FR3-06 AS-04).
 * A post of the long form (lld_settings_nonce) still returns so Stage 2 can finish it (NFR3-06). It adds no rewrite rule.
 *
 * Health consent is withdrawn through the Stage 2 /members/withdraw/ screen, never here.
 * Reminders on the Notifications tab use LLD_Members_Reminders (P3-36 B4 / FR3-56).
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

	const SECTIONS    = array( 'profile', 'security', 'notifications', 'privacy', 'membership' );
	const HANDLE      = 'lld-members-stage3';
	const FORMS_STYLE = 'lld-members-forms';
	const FORMS_SCRIPT = 'lld-members-forms';

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
		// A long-form post that was already open still belongs to Stage 2.
		if ( isset( $_POST['lld_settings_nonce'] ) ) {
			return;
		}
		if ( isset( $_GET['all'] ) ) {
			$section = isset( $_GET['section'] ) ? sanitize_key( (string) wp_unslash( $_GET['section'] ) ) : 'profile';
			if ( ! in_array( $section, self::SECTIONS, true ) ) {
				$section = 'profile';
			}
			wp_safe_redirect( home_url( '/members/settings/?section=' . $section ) );
			exit;
		}
		$row = self::member_row( $member_id );
		if ( ! $row ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		wp_enqueue_style( self::FORMS_STYLE, plugins_url( 'assets/stage3-forms.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::FORMS_SCRIPT, plugins_url( 'assets/stage3-forms.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
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
		// Withdrawing an app connection comes back to /members/settings/?lld_app=withdrawn: its list is on Sign-in & security.
		$default = isset( $_GET['lld_app'] ) ? 'security' : 'profile';
		$section = isset( $_GET['section'] ) ? sanitize_key( (string) wp_unslash( $_GET['section'] ) ) : $default;
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			$section = $default;
		}

		$error  = '';
		$notice = '';
		if ( self::post_too_big() ) {
			// A request bigger than post_max_size arrives with no fields at all: say so, as the long form does.
			$error = 'Photos must be 10 MB or smaller.';
		} elseif ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$saved = self::handle_post( $member_id, $row );
			if ( is_wp_error( $saved ) ) {
				$error = $saved->get_error_message();
			} elseif ( is_string( $saved ) ) {
				// Post-redirect-get: a reload never posts the form again.
				wp_safe_redirect( home_url( '/members/settings/?section=' . $saved . '&saved=1' ) );
				exit;
			}
		}
		$saved_notices = array(
			'profile'       => 'Profile saved.',
			'security'      => 'Saved',
			'notifications' => 'Saved',
			'privacy'       => 'Saved',
		);
		if ( '' === $error && isset( $_GET['saved'] ) && isset( $saved_notices[ $section ] ) ) {
			$notice = $saved_notices[ $section ];
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
		wp_print_styles( array( self::HANDLE, self::FORMS_STYLE ) );
		$styles = (string) ob_get_clean();
		ob_start();
		wp_print_scripts( array( self::HANDLE, self::FORMS_SCRIPT ) );
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
	 * True when the browser posted something but PHP dropped all of it (above post_max_size).
	 */
	private static function post_too_big() {
		return 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && empty( $_POST ) && empty( $_FILES )
			&& isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 0;
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
	 * Every consent line of the member, oldest first. TODO lead: public accessor
	 * (LLD_Members_Account::latest_consent() is private; has_consent() is public and gives the state).
	 *
	 * @param int $member_id Member id.
	 * @return object[]
	 */
	private static function consent_lines( $member_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT consent_type, change_kind, document_version, occurred_at FROM ' . LLD_Members_Schema::table( 'consent' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ) );
	}

	/**
	 * Saves the posted tab and returns its section slug. Null when this request is not a hub save.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member row.
	 * @return string|WP_Error|null Section slug, an error, or null when nothing was posted.
	 */
	private static function handle_post( $member_id, $row ) {
		if ( isset( $_POST['lld_profile_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_profile_nonce'] ) ), 'lld_profile_save' ) ) {
			$result = LLD_Members_Account::save_hub_profile( $row );
			return is_wp_error( $result ) ? $result : 'profile';
		}
		if ( isset( $_POST['lld_security_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_security_nonce'] ) ), 'lld_security_save' ) ) {
			$result = LLD_Members_Account::save_hub_security( $row );
			return is_wp_error( $result ) ? $result : 'security';
		}
		if ( isset( $_POST['lld_notifications_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_notifications_nonce'] ) ), 'lld_notifications_save' ) ) {
			LLD_Members_Account::save_hub_notifications( $member_id );
			return 'notifications';
		}
		if ( isset( $_POST['lld_reminders_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_reminders_nonce'] ) ), 'lld_reminders_save' ) ) {
			if ( ! class_exists( 'LLD_Members_Reminders' ) ) {
				return new WP_Error( 'lld_reminders', 'Reminders are not available.' );
			}
			$result = LLD_Members_Reminders::save_hub( $member_id );
			return is_wp_error( $result ) ? $result : 'notifications';
		}
		if ( isset( $_POST['lld_privacy_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_privacy_nonce'] ) ), 'lld_privacy_save' ) ) {
			LLD_Members_Account::save_hub_marketing( $member_id );
			return 'privacy';
		}
		return null;
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
				$html .= self::section_notifications( $member_id );
				break;
			case 'privacy':
				$html .= self::section_privacy( $member_id );
				break;
			case 'membership':
				$html .= self::section_membership( $member_id, $row );
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
	 * Section 1: Profile, with its own save. Email, age, time zone, photo and the one units setting live here.
	 *
	 * @param object $row Member row.
	 */
	private static function section_profile( $row ) {
		$pending = LLD_Members_Account::pending_email_for( (int) $row->id );
		$html    = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-profile">';
		$html   .= '<h2 id="lld-section-profile">Profile</h2>';
		$html   .= '<p class="lld-muted">Your display name and personal settings. Only profile details are updated here.</p>';
		if ( $pending ) {
			$html .= '<p class="lld-info">Check ' . esc_html( (string) $pending ) . ' to confirm. Your current email still works until then.</p>';
		}
		$html .= '<form method="post" enctype="multipart/form-data" action="' . esc_url( home_url( '/members/settings/?section=profile' ) ) . '" class="lld-form">';
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
		$html .= lld_field(
			array(
				'id'           => 'lld_email',
				'name'         => 'lld_email',
				'label'        => 'Email',
				'type'         => 'email',
				'autocomplete' => 'email',
				'value'        => (string) $row->email,
			)
		);
		$html .= lld_field(
			array(
				'id'    => 'lld_age',
				'name'  => 'lld_age',
				'label' => 'Age in years',
				'type'  => 'number',
				'value' => (string) $row->age,
			)
		);
		$html .= self::timezone_field( (string) $row->timezone );
		$html .= self::photo_field( $row );
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_units" value="imperial"' . ( 'imperial' === $row->units ? ' checked' : '' ) . '> Show imperial units (lb, ft, in)</label>';
		$html .= lld_button(
			array(
				'label' => 'Save profile',
				'type'  => 'submit',
			)
		);
		$html .= '</form></section>';
		return $html;
	}

	/**
	 * Time zone as a city list. The posted value is still an identifier from timezone_identifiers_list().
	 *
	 * @param string $value Current identifier.
	 */
	private static function timezone_field( $value ) {
		$html = str_replace(
			'<input id="lld_timezone"',
			'<input list="lld_timezones" autocomplete="off" id="lld_timezone"',
			lld_field(
				array(
					'id'    => 'lld_timezone',
					'name'  => 'lld_timezone',
					'label' => 'Time zone',
					'hint'  => 'Start typing a city, for example London or New_York.',
					'value' => $value,
				)
			)
		);
		$html .= '<datalist id="lld_timezones">';
		foreach ( timezone_identifiers_list() as $zone ) {
			$city  = str_replace( '_', ' ', (string) preg_replace( '#^.*/#', '', $zone ) );
			$html .= '<option value="' . esc_attr( $zone ) . '">' . esc_html( $city ) . '</option>';
		}
		$html .= '</datalist>';
		return $html;
	}

	/**
	 * Profile photo. The file input keeps id lld_photo; the label is the visible control.
	 *
	 * @param object $row Member row.
	 */
	private static function photo_field( $row ) {
		$html  = '<p class="lld-file"><label for="lld_photo">Profile photo</label>';
		$html .= '<input id="lld_photo" name="lld_photo" type="file" accept=".jpg,.jpeg,.png,.heic,image/jpeg,image/png,image/heic">';
		$html .= '<span class="lld-file-name"></span></p>';
		if ( ! empty( $row->photo_path ) ) {
			$html .= '<p><img alt="" src="' . esc_url( home_url( '/members/photo/' . (int) $row->id . '/' ) ) . '" width="80"></p>';
			$html .= '<label class="lld-check"><input type="checkbox" name="lld_remove_photo" value="1"> Remove photo</label>';
		}
		return $html;
	}

	/**
	 * Section 2: Sign-in & security. Password and two-step sign-in save here.
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
		$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=security' ) ) . '" class="lld-form">';
		$html .= wp_nonce_field( 'lld_security_save', 'lld_security_nonce', true, false );
		$html .= lld_password_field(
			array(
				'id'           => 'lld_password',
				'name'         => 'lld_password',
				'label'        => 'New password',
				'hint'         => 'Leave blank to keep your password. At least 10 characters.',
				'autocomplete' => 'new-password',
			)
		);
		$html .= '<fieldset class="lld-card"><legend>Two-step sign-in</legend>';
		$html .= '<label class="lld-check"><input type="checkbox" name="lld_two_step" value="1"' . ( $on ? ' checked' : '' ) . ( $forced ? ' disabled' : '' ) . '> Email me a code when I sign in</label>';
		if ( $forced ) {
			$html .= '<input type="hidden" name="lld_two_step" value="1">';
		}
		$html .= '</fieldset>';
		$html .= lld_button( array( 'label' => 'Save', 'type' => 'submit' ) );
		$html .= '</form>';
		// P3-20 to P3-22: linked Google and Apple accounts, unlinking, and sign out everywhere (FR3-05).
		if ( class_exists( 'LLD_Members_Sign_In' ) && ( LLD_Members_Sign_In::on( 'sign_in_social' ) || LLD_Members_Sign_In::on( 'sign_in_code' ) ) ) {
			$html .= LLD_Members_Sign_In::security_html( (int) $row->id, $row );
		}
		// The member's app connections, each with Withdraw, and the note after one is withdrawn (moved from the long page).
		if ( class_exists( 'LLD_Members_Quality' ) ) {
			$html .= LLD_Members_Quality::app_keys_html( (int) $row->id );
		}
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 3: Notifications. Email choices and reminder chooser (P3-36 B4 / FR3-56).
	 *
	 * @param int $member_id Member id.
	 */
	private static function section_notifications( $member_id ) {
		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-notifications">';
		$html .= '<h2 id="lld-section-notifications">Notifications &amp; reminders</h2>';
		$html .= '<p class="lld-muted">Emails never include your health readings or private numbers.</p>';
		$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=notifications' ) ) . '" class="lld-form">';
		$html .= wp_nonce_field( 'lld_notifications_save', 'lld_notifications_nonce', true, false );
		$html .= LLD_Members_Account::email_preference_fields( $member_id );
		$html .= lld_button( array( 'label' => 'Save', 'type' => 'submit' ) );
		$html .= '</form>';
		if ( class_exists( 'LLD_Members_Reminders' ) ) {
			$html .= LLD_Members_Reminders::hub_html( $member_id );
		}
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
			'sale'      => 'Terms of sale',
			'health'    => 'Store my health information',
			'marketing' => 'Marketing emails',
		);
		$lines = self::consent_lines( $member_id );
		$dates = array();
		foreach ( $lines as $line ) {
			$dates[ (string) $line->consent_type ] = $line;
		}

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-privacy">';
		$html .= '<h2 id="lld-section-privacy">Privacy &amp; consents</h2>';
		$html .= '<p class="lld-muted">Every consent you have given is listed below with its date, and how to change it.</p>';
		$html .= '<ul class="lld-consent-list">';
		foreach ( $consents as $type => $label ) {
			// Terms of sale are listed only once the member has bought something, as on the long page.
			if ( 'sale' === $type && ! isset( $dates[ $type ] ) ) {
				continue;
			}
			$is_given = LLD_Members_Account::has_consent( $member_id, $type );
			$date_str = isset( $dates[ $type ] ) ? substr( (string) $dates[ $type ]->occurred_at, 0, 10 ) : '';

			$html .= '<li class="lld-consent-item" data-consent-type="' . esc_attr( $type ) . '">';
			$html .= '<div class="lld-consent-meta">';
			$html .= '<strong>' . esc_html( $label ) . '</strong>';
			$html .= '<span class="lld-consent-date">Status: ' . ( $is_given && '' !== $date_str ? 'Accepted on ' . esc_html( $date_str ) : 'Withdrawn / Not given' ) . '</span>';
			$html .= '</div>';
			if ( 'terms' === $type || 'privacy' === $type || 'sale' === $type ) {
				$html .= '<p class="lld-hint">Needed for your account. <a href="' . esc_url( home_url( '/members/delete/' ) ) . '">Delete my account</a> to end it.</p>';
			} elseif ( 'marketing' === $type ) {
				$html .= '<form method="post" action="' . esc_url( home_url( '/members/settings/?section=privacy' ) ) . '" class="lld-form">';
				$html .= wp_nonce_field( 'lld_privacy_save', 'lld_privacy_nonce', true, false );
				$html .= '<label class="lld-check"><input type="checkbox" name="lld_consent_marketing" value="1"' . ( $is_given ? ' checked' : '' ) . '> Marketing emails</label>';
				$html .= lld_button( array( 'label' => 'Save', 'type' => 'submit' ) );
				$html .= '</form>';
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
		$html .= '<details class="lld-card lld-consent-log"><summary>Consent history</summary><ul class="lld-consent-history">';
		foreach ( $lines as $line ) {
			$html .= '<li>' . esc_html( $line->consent_type . ' ' . $line->change_kind . ' ' . $line->document_version . ' ' . $line->occurred_at ) . '</li>';
		}
		$html .= '</ul></details>';
		$html .= '<p class="lld-hint lld-account-links"><a href="' . esc_url( home_url( '/members/data/' ) ) . '">Download my data</a> <span aria-hidden="true">·</span> <a href="' . esc_url( home_url( '/members/delete/' ) ) . '">Delete my account</a></p>';
		$html .= '</section>';
		return $html;
	}

	/**
	 * Section 5: Membership. Pause is a placeholder until P3-63; nothing here is saved.
	 *
	 * @param int    $member_id Member id.
	 * @param object $row       Member row.
	 */
	private static function section_membership( $member_id, $row ) {
		$state   = ucfirst( LLD_Members_Access::access_state( $member_id ) );
		$is_prem = class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::has_premium( $member_id );

		$html  = '<section class="lld-account-section lld-card" aria-labelledby="lld-section-membership">';
		$html .= '<h2 id="lld-section-membership">Membership</h2>';
		$html .= '<p class="lld-muted">Your current membership status and subscription management.</p>';
		$html .= '<div class="lld-membership-status">';
		$html .= '<p><strong>Current plan:</strong> ' . esc_html( $state ) . '</p>';
		if ( ! empty( $row->joined_on ) ) {
			$html .= '<p>Joined on ' . esc_html( (string) $row->joined_on ) . '</p>';
		}
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
