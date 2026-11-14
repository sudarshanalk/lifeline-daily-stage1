<?php
/**
 * The admin module in My account (P3-87b; FR3-72, FR3-87 to FR3-95, NFR3-24, FR5-30; approved design
 * 03 UI Designs/Stage 3/admin/admin-module.html, 6 Nov 2026).
 *
 * /members/admin/ and /members/admin/<page>/ show the admin pages in the site's own design: Overview, Members, Tools,
 * Features, Activity log, Helpers, Payments, Weekly report, Group removals, Staging emails and AI. A Support helper
 * sees Overview and Members, can open Tools, Features and their own activity read only, and never the WordPress
 * admin (FR3-90). Everyone else gets "not found". Forms post to the same admin-post.php handlers as before
 * (LLD_Members_Admin_Pages), so every role check, reason, fresh code and log entry is unchanged. No page shows a
 * member's health data (NFR3-19): the pages read account tables only.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routing, the admin shell and the pages.
 */
class LLD_Members_Admin_Front {

	const HANDLE          = 'lld-members-stage3';
	const SCREEN          = 'admin3';
	const OPTION_REWRITE  = 'lld_members_admin3_rewrite';
	const REWRITE_VERSION = '1';
	const RULE            = '^members/admin(?:/([a-z-]+))?/?$';

	/** Test sites only: show the Staging emails page and copy each sent email to it (FR3-93 checks in CI). */
	const TEST_MAIL_OPTION = 'lld_admin_test_mailpage';

	/** Page => label, who may open it (support: in the menu; view: Support may open it read only; owner: Owner only). */
	const PAGES = array(
		'overview' => array( 'Overview', 'support' ),
		'members'  => array( 'Members', 'support' ),
		'tools'    => array( 'Tools', 'view' ),
		'features' => array( 'Features', 'view' ),
		'activity' => array( 'Activity log', 'view' ),
		'helpers'  => array( 'Helpers', 'owner' ),
		'payments' => array( 'Payments', 'owner' ),
		'report'   => array( 'Weekly report', 'owner' ),
		'group'    => array( 'Group removals', 'owner' ),
		'emails'   => array( 'Staging emails', 'owner' ),
		'ai'       => array( 'AI', 'owner' ),
	);

	/** Menu groups for the Owner (design: "Money and reports", "Staging and AI"). */
	const GROUPS = array(
		''                  => array( 'overview', 'members', 'tools', 'features', 'activity', 'helpers' ),
		'Money and reports' => array( 'payments', 'report', 'group' ),
		'Staging and AI'    => array( 'emails', 'ai' ),
	);

	/** The three standalone tools (Stage 1 and 2, switched through tools/status.json, FR1-15, FR3-89). */
	const STANDALONE = array(
		'protein'        => array( 'Daily Protein Target', '/health-tools/protein/' ),
		'diabetes'       => array( 'Diabetes Risk Check', '/health-tools/diabetes-risk/' ),
		'healthy-weight' => array( 'Healthy Weight Check', '/health-tools/healthy-weight/' ),
	);

	/** The eight calculators: feature switch => name, address (FR3-88). */
	const CALCULATORS = array(
		'calc_heart_risk'     => array( 'Heart Risk Check', '/health-tools/heart-risk-check/' ),
		'calc_bp_check'       => array( 'Blood Pressure Check', '/health-tools/blood-pressure-check/' ),
		'calc_metabolic'      => array( 'Metabolic Health Check', '/health-tools/metabolic-health-check/' ),
		'calc_fitness_age'    => array( 'Fitness Age', '/health-tools/fitness-age/' ),
		'calc_hr_zones'       => array( 'Heart Rate Zones', '/health-tools/heart-rate-zones/' ),
		'calc_sleep_stress'   => array( 'Sleep and Stress Check', '/health-tools/sleep-and-stress-check/' ),
		'calc_calorie_target' => array( 'Calorie Target', '' ),
		'calc_goal_timeline'  => array( 'Goal Timeline', '' ),
	);

	/** The AI tools, each with its own switch from Stage 5 (FR5-31). */
	const AI_TOOLS = array( 'Typed food logging', 'Food photo logging', 'Nutrition label reading', 'Recipe text understanding', 'Support chatbot', 'Personal suggestions' );

	/** Every other feature, in plain words, by group (NFR3-24 AS-01: no code names). */
	const FEATURE_GROUPS = array(
		'Sign-in and account' => array(
			'sign_in_social' => array( 'Google sign-in', 'Continue with Google on the sign-in page' ),
			'sign_in_code'   => array( 'Email-code sign-in', 'Sign in or join with a 6-digit code' ),
			'account_hub'    => array( 'Account hub', 'The My account sections' ),
		),
		'Goal journey'        => array(
			'goals'    => array( 'Goals and personal plan', 'Goal setting, starting measurements and the plan' ),
			'today'    => array( 'Today screen', 'Next step, goal progress and the weekly check-in' ),
			'progress' => array( 'Progress and dashboard', 'Progress page and member dashboard' ),
			'insights' => array( 'Personal insights', 'Insights from the decision engine' ),
		),
		'Food and health'     => array(
			'food_tracker'   => array( 'Food tracker', 'Food search, logging, targets and recipes' ),
			'barcode'        => array( 'Barcode scanning', 'Scan a food barcode with the phone camera' ),
			'health_logging' => array( 'Health logging', 'Blood pressure, weight, sleep, water and more' ),
			'reminders'      => array( 'Reminders', 'Reminders, shareable summary and add to home screen' ),
		),
		'Membership'          => array(
			'prices_launch'   => array( 'Launch prices', 'US$9.99 a month and US$69.99 a year' ),
			'stripe_api'      => array( 'Stripe checkout', 'Real Stripe checkout, cancellation and refunds' ),
			'access_tiers'    => array( 'Free and Premium access', 'What each plan can open' ),
			'membership_card' => array( 'Membership card', 'The member\'s card in My account' ),
			'pause'           => array( 'Pause membership', 'Pause for 1 to 3 months' ),
		),
		'Site'                => array(
			'analytics'     => array( 'Google Analytics', 'On public pages only, after cookie consent' ),
			'cookie_banner' => array( 'Cookie banner', 'The consent banner' ),
			'weekly_report' => array( 'Weekly owner report', 'The weekly numbers email' ),
			'consent_new'   => array( 'New health consent', 'Consent, download and deletion for the new data' ),
			'content_new'   => array( 'New content', 'New tracks, challenges and wording' ),
			'api'           => array( 'Members\' data connection', 'The connection the website and the future app use' ),
		),
	);

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_action( 'init', array( __CLASS__, 'flush_if_needed' ), 999 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 4 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ), 50 );
		add_action( 'admin_init', array( __CLASS__, 'keep_support_out' ), 0 );
		// Screens a helper may not open (edit.php and so on) are refused while the menu is built, before admin_init.
		add_action( 'admin_menu', array( __CLASS__, 'keep_support_out' ), 0 );
		add_action( 'admin_page_access_denied', array( __CLASS__, 'keep_support_out' ), 0 );
		if ( function_exists( 'lld_members_test_routes_open' ) && lld_members_test_routes_open() ) {
			add_filter( 'wp_mail', array( __CLASS__, 'test_copy_mail' ), 1 );
		}
	}

	/**
	 * Test sites only: while the test option is on, a copy of each email goes where staging's catcher writes, so the
	 * Staging emails page can be checked in CI. The email itself is still sent (to the CI mail catcher).
	 *
	 * @param array $atts wp_mail arguments.
	 * @return array
	 */
	public static function test_copy_mail( $atts ) {
		if ( get_option( self::TEST_MAIL_OPTION ) && function_exists( 'lld_mail_record' ) && function_exists( 'lld_mail_dir' ) ) {
			$dir = lld_mail_dir();
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			file_put_contents( $dir . '/' . gmdate( 'Y-m-d' ) . '.jsonl', wp_json_encode( lld_mail_record( (array) $atts, gmdate( 'c' ) ) ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $atts;
	}

	// ----- Routing -----

	public static function rewrites() {
		add_rewrite_rule( self::RULE, 'index.php?lld_screen=' . self::SCREEN . '&lld_track=$matches[1]', 'top' );
	}

	public static function flush_if_needed() {
		$stored = get_option( 'rewrite_rules' );
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VERSION || ! is_array( $stored ) || ! isset( $stored[ self::RULE ] ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VERSION, true );
		}
	}

	/**
	 * Address of an admin page in My account.
	 *
	 * @param string $page Page key ('overview' is /members/admin/).
	 * @param array  $args Query arguments.
	 */
	public static function url( $page = 'overview', array $args = array() ) {
		$path = 'overview' === $page || '' === $page ? '/members/admin/' : '/members/admin/' . $page . '/';
		return add_query_arg( $args, home_url( $path ) );
	}

	/**
	 * May the current admin open this page?
	 *
	 * @param string $page Page key.
	 */
	public static function allowed( $page ) {
		if ( ! isset( self::PAGES[ $page ] ) || ! current_user_can( LLD_Members_Admin_Roles::PANEL ) ) {
			return false;
		}
		if ( 'emails' === $page && ! self::mail_page_on() ) {
			return false;
		}
		return LLD_Members_Admin_Roles::is_owner() || 'owner' !== self::PAGES[ $page ][1];
	}

	/**
	 * The Staging emails page exists only where staging catches mail (FR3-93 AS-03).
	 */
	public static function mail_page_on() {
		$on = function_exists( 'lld_mail_catching' ) && lld_mail_catching( defined( 'LLD_ENV' ) ? (string) LLD_ENV : null );
		$on = $on || ( function_exists( 'lld_members_test_routes_open' ) && lld_members_test_routes_open() && get_option( self::TEST_MAIL_OPTION ) );
		return (bool) apply_filters( 'lld_admin_staging_mail_page', $on );
	}

	public static function maybe_render() {
		if ( self::SCREEN !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		do_action( 'litespeed_control_set_nocache', 'lld admin page' );
		$page = (string) get_query_var( 'lld_track' );
		$page = '' === $page ? 'overview' : $page;
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url( $page ) ) );
			exit;
		}
		if ( ! self::allowed( $page ) ) {
			// Members, and pages a role may not open: the address does not exist (FR3-87 AS-03, FR3-93 AS-02).
			global $wp_query;
			if ( isset( $wp_query ) && is_object( $wp_query ) ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow', true );
		$method = 'page_' . $page;
		$main   = self::$method();
		self::output( self::PAGES[ $page ][0] . ' · Admin', self::shell( $page, $main ) );
		exit;
	}

	/**
	 * Admin bar: never for a Support helper (FR3-90 AS-02).
	 *
	 * @param bool $show Show.
	 */
	public static function admin_bar( $show ) {
		return LLD_Members_Admin_Roles::is_support() ? false : $show;
	}

	/**
	 * A Support helper never enters the WordPress admin: any wp-admin address goes to My account → Admin. The background
	 * addresses forms use (admin-post.php, admin-ajax.php) still work, and their handlers check the role (FR3-90).
	 */
	public static function keep_support_out() {
		if ( ! LLD_Members_Admin_Roles::is_support() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	// ----- Shell -----

	/**
	 * Print the page in the member layout with the Stage 3 stylesheet and script.
	 *
	 * @param string $title Title.
	 * @param string $main  Main HTML.
	 */
	private static function output( $title, $main ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		$member = class_exists( 'LLD_Members_Access' ) && LLD_Members_Access::current_member_id() > 0;
		ob_start();
		// 'account': the chip's menu with Sign out on every Admin page, also for a helper without a member record (P3-36 C1).
		lld_members_layout( array( 'title' => $title, 'main' => $main, 'signed_in' => $member, 'wide' => true, 'account' => true ) );
		$page = (string) ob_get_clean();
		ob_start();
		wp_print_styles( array( self::HANDLE ) );
		$styles = (string) ob_get_clean();
		$head   = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}
		ob_start();
		wp_print_scripts( array( self::HANDLE ) );
		$scripts = (string) ob_get_clean();
		$end     = strrpos( $page, '</body>' );
		if ( false !== $end ) {
			$page = substr_replace( $page, $scripts, $end, 0 );
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The admin shell: the menu (a side column on a desktop, scrolling tabs on a phone), the saved message and the page.
	 *
	 * @param string $page Current page.
	 * @param string $main Page HTML.
	 */
	private static function shell( $page, $main ) {
		$owner = LLD_Members_Admin_Roles::is_owner();
		$user  = wp_get_current_user();
		$name  = '' !== trim( (string) $user->display_name ) ? (string) $user->display_name : (string) $user->user_login;
		$nav   = '';
		$tabs  = '';
		foreach ( self::GROUPS as $group => $pages ) {
			$items = '';
			foreach ( $pages as $p ) {
				if ( ! self::allowed( $p ) || ( ! $owner && 'support' !== self::PAGES[ $p ][1] ) ) {
					continue;
				}
				$current = $p === $page ? ' aria-current="page"' : '';
				$items  .= '<li><a class="lld-adm-nav-link" href="' . esc_url( self::url( $p ) ) . '"' . $current . '>' . esc_html( self::PAGES[ $p ][0] ) . '</a></li>';
				$tabs   .= '<li><a href="' . esc_url( self::url( $p ) ) . '"' . $current . '>' . esc_html( self::PAGES[ $p ][0] ) . '</a></li>';
			}
			if ( '' === $items ) {
				continue;
			}
			$nav .= ( '' !== $group ? '<p class="lld-adm-nav-group">' . esc_html( $group ) . '</p>' : '' ) . '<ul>' . $items . '</ul>';
		}
		$wp = $owner ? '<a class="lld-adm-wp-link" href="' . esc_url( admin_url( 'admin.php?page=lld-approvals' ) ) . '">Content and site settings (WordPress)</a>' : '';

		$html  = '<div class="lld-adm" data-lld-admin>';
		$html .= '<aside class="lld-adm-side" aria-label="Admin"><div class="lld-adm-who"><span class="lld-adm-avatar" aria-hidden="true">' . esc_html( strtoupper( substr( $name, 0, 1 ) ) ) . '</span><span><strong>' . esc_html( $name ) . '</strong><span class="lld-adm-role">' . ( $owner ? 'Owner' : 'Support helper' ) . '</span></span></div>';
		$html .= '<nav aria-label="Admin pages">' . $nav . '</nav>' . $wp . '</aside>';
		$html .= '<div class="lld-adm-body"><nav class="lld-adm-tabs" aria-label="Admin pages (phone)"><ul>' . $tabs . ( $owner ? '<li><a href="' . esc_url( admin_url( 'admin.php?page=lld-approvals' ) ) . '">WordPress</a></li>' : '' ) . '</ul></nav>';
		$html .= self::notice() . $main . '</div></div>';
		return $html;
	}

	/**
	 * The page title block.
	 *
	 * @param string $title Title.
	 * @param string $intro Intro.
	 * @param string $right Extra on the right (a button).
	 * @param string $crumb Extra breadcrumb.
	 */
	private static function head( $title, $intro = '', $right = '', $crumb = '' ) {
		$html  = '<p class="lld-adm-crumb">Admin' . $crumb . '</p>';
		$html .= '<div class="lld-adm-head"><div><h1>' . esc_html( $title ) . '</h1>' . ( '' !== $intro ? '<p class="lld-hint">' . esc_html( $intro ) . '</p>' : '' ) . '</div>' . $right . '</div>';
		return $html;
	}

	/**
	 * The saved message, with Undo when the change can be undone (FR3-74 AS-02, AS-07).
	 */
	private static function notice() {
		$note = LLD_Members_Admin_Pages::take_notice();
		if ( ! $note ) {
			return '';
		}
		$error = 'error' === $note['type'];
		$html  = '<div class="lld-adm-notice' . ( $error ? ' is-error' : '' ) . '" role="' . ( $error ? 'alert' : 'status' ) . '" data-lld-adm-notice><p>' . ( $error ? '' : 'Saved · ' ) . esc_html( $note['message'] ) . '</p>';
		if ( ! $error && ! empty( $note['undo'] ) && is_array( $note['undo'] ) ) {
			$u     = $note['undo'];
			$html .= LLD_Members_Admin_Pages::form_open( (string) $u['action'], (string) $u['action'], array( 'key' => (string) $u['key'], 'prev' => (string) ( $u['prev'] ?? '' ), 'undo' => '1' ), 'lld-adm-undo' );
			$html .= '<button type="submit" class="lld-adm-undo-button">Undo</button></form>';
		}
		return $html . '</div>';
	}

	/**
	 * A count card.
	 *
	 * @param string $key   data-count key.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $sub   Line under it.
	 * @param bool   $warn  Amber.
	 */
	private static function stat( $key, $label, $value, $sub = '', $warn = false ) {
		return '<div class="lld-card lld-adm-stat' . ( $warn ? ' is-warn' : '' ) . '" data-count="' . esc_attr( $key ) . '"><span class="lld-adm-stat-label">' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong>' . ( '' !== $sub ? '<span class="lld-adm-stat-sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
	}

	/**
	 * An on/off toggle that saves at once (FR3-74, FR3-88). Support and switches that cannot change see it disabled.
	 *
	 * @param string $action admin-post action (lld_admin_feature or lld_admin_tool).
	 * @param string $key    Feature or tool key.
	 * @param string $name   Plain name.
	 * @param bool   $on     Current state.
	 * @param bool   $can    May change it.
	 */
	private static function toggle( $action, $key, $name, $on, $can ) {
		$id   = 'lld-adm-t-' . sanitize_html_class( $key );
		$html = '';
		if ( $can ) {
			$html .= LLD_Members_Admin_Pages::form_open( $action, $action, array( 'key' => $key ), 'lld-adm-toggle-form' );
		}
		$html .= '<label class="lld-adm-switch" for="' . esc_attr( $id ) . '"><input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="on" value="1"' . ( $on ? ' checked' : '' ) . ( $can ? ' data-lld-adm-autosubmit' : ' disabled' ) . ' aria-label="' . esc_attr( $name ) . '"><span class="lld-adm-track" aria-hidden="true"></span><span class="lld-adm-state">' . ( $on ? 'On' : 'Off' ) . '</span></label>';
		if ( $can ) {
			$html .= '<details class="lld-adm-reason"><summary>Add a reason</summary><label for="' . esc_attr( $id ) . '-why">Reason (optional)</label><input type="text" id="' . esc_attr( $id ) . '-why" name="reason" maxlength="300" placeholder="For example: checking a wording issue"><p class="lld-hint">Type the reason first, then flip the switch. Kept in the history. No names, emails or health details.</p></details>';
			$html .= '<noscript><button type="submit" class="lld-button lld-button-secondary">Save</button></noscript></form>';
		}
		return $html;
	}

	/**
	 * A switch's history, newest first.
	 *
	 * @param string $key History key.
	 */
	private static function history( $key ) {
		$rows = LLD_Members_Features::history( $key );
		$html = '<details class="lld-adm-history"><summary>History (' . count( $rows ) . ')</summary>';
		if ( ! $rows ) {
			return $html . '<p class="lld-hint">Never changed.</p></details>';
		}
		$html .= '<ul>';
		foreach ( array_slice( $rows, 0, 10 ) as $h ) {
			$html .= '<li>' . esc_html( $h['when'] . ' UTC · ' . $h['who'] . ' · ' . self::state_word( $h['from'] ) . ' → ' . self::state_word( $h['to'] ) . ( '' !== $h['note'] ? ' · “' . $h['note'] . '”' : '' ) ) . '</li>';
		}
		return $html . '</ul></details>';
	}

	/**
	 * @param string $state Stored state.
	 */
	private static function state_word( $state ) {
		return array( 'everyone' => 'on', 'off' => 'off', 'test_accounts' => 'test accounts only', 'active' => 'on', 'withdrawn' => 'off' )[ $state ] ?? (string) $state;
	}

	// ----- Overview (FR3-73) -----

	private static function page_overview() {
		$owner = LLD_Members_Admin_Roles::is_owner();
		$c     = LLD_Members_Admin_Pages::counts();
		$html  = self::head( 'Overview', 'Counts only. Test members are left out and shown separately. No health figures appear anywhere in Admin.' );
		$html .= '<div class="lld-adm-grid lld-adm-g4">';
		$html .= self::stat( 'total', 'Members', number_format( (int) $c['total'] ), '+' . (int) $c['new_week'] . ' this week' );
		$html .= self::stat( 'seen_7', 'Signed in, last 7 days', number_format( (int) $c['seen_7'] ), (int) $c['seen_30'] . ' in the last 30 days' );
		$html .= self::stat( 'premium', 'Premium', number_format( (int) $c['premium'] ), 'Free: ' . (int) $c['free'] );
		$html .= self::stat( 'paused', 'Paused or cancelled', (int) $c['paused'] + (int) $c['cancelled'], 'Paused ' . (int) $c['paused'] . ' · cancelled ' . (int) $c['cancelled'] . ' · blocked ' . (int) $c['blocked'] );
		$html .= '</div>';
		if ( $owner ) {
			$html .= '<div class="lld-adm-grid lld-adm-g4">';
			$html .= self::stat( 'monthly', 'Monthly plans', (int) $c['monthly'] );
			$html .= self::stat( 'yearly', 'Yearly plans', (int) $c['yearly'] );
			$html .= self::stat( 'failed', 'Failed payments', (int) $c['failed'], (int) $c['failed'] > 0 ? 'Open them in Stripe' : '', (int) $c['failed'] > 0 );
			$html .= self::stat( 'test', 'Test members', (int) $c['test'], 'Not counted above' );
			$html .= '</div>';
		}
		$html .= '<div class="lld-adm-grid lld-adm-g2"><section class="lld-card"><h2>Needs your attention</h2><ul class="lld-adm-list">';
		$any   = false;
		if ( $owner && (int) $c['approvals'] > 0 ) {
			$any   = true;
			$html .= '<li data-count="approvals"><a href="' . esc_url( admin_url( 'admin.php?page=lld-approvals' ) ) . '"><strong>' . (int) $c['approvals'] . ' content item' . ( 1 === (int) $c['approvals'] ? '' : 's' ) . ' waiting for approval</strong><span>Approvals, in the WordPress admin</span></a></li>';
		}
		$off = self::tools_off();
		if ( $owner && $off ) {
			$any   = true;
			$html .= '<li><a href="' . esc_url( self::url( 'tools' ) ) . '"><strong>' . count( $off ) . ' tool' . ( 1 === count( $off ) ? ' is' : 's are' ) . ' off</strong><span>' . esc_html( implode( ', ', $off ) ) . '</span></a></li>';
		}
		if ( ! $any ) {
			$html .= '<li class="lld-adm-empty-row"><strong>Nothing waiting for you</strong><span>' . ( $owner ? 'Approvals, switched-off tools and failed payments show here.' : 'Member requests arrive by email.' ) . '</span></li>';
		}
		$html .= '<li data-count="qa" class="lld-adm-empty-row"><strong>Live Q&amp;A</strong><span>Questions are asked in the live session, not stored on the site.</span></li>';
		$html .= '</ul></section>';
		if ( $owner ) {
			$html .= '<section class="lld-card"><div class="lld-adm-head-row"><h2>Recent admin actions</h2><a class="lld-button lld-button-secondary" href="' . esc_url( self::url( 'activity' ) ) . '">Activity log</a></div>' . self::log_list( LLD_Members_Admin_Log::visible( 10 ) ) . '</section>';
		} else {
			$html .= '<section class="lld-card"><h2>Find a member</h2><p class="lld-hint">Search by email, name or member number.</p><p><a class="lld-button" href="' . esc_url( self::url( 'members' ) ) . '">Open Members</a></p></section>';
		}
		return $html . '</div>';
	}

	/**
	 * Names of the tools that are off now.
	 *
	 * @return string[]
	 */
	private static function tools_off() {
		$off = array();
		foreach ( self::STANDALONE as $slug => $t ) {
			if ( function_exists( 'lld_tools_tool_status' ) && 'withdrawn' === lld_tools_tool_status( $slug ) ) {
				$off[] = $t[0];
			}
		}
		$master = 'off' !== LLD_Members_Features::state( 'calculators_new' );
		foreach ( self::CALCULATORS as $key => $t ) {
			if ( ! $master || 'off' === LLD_Members_Features::state( $key ) ) {
				$off[] = $t[0];
			}
		}
		return $off;
	}

	/**
	 * Activity entries as a list.
	 *
	 * @param object[] $rows Rows.
	 */
	private static function log_list( array $rows ) {
		if ( ! $rows ) {
			return '<div class="lld-adm-empty"><h3>No actions yet</h3><p>Every admin action appears here.</p></div>';
		}
		$html = '<ul class="lld-adm-list lld-adm-log">';
		foreach ( $rows as $row ) {
			$c     = array_map(
				static function ( $v ) {
					return ltrim( (string) $v, "'" );
				},
				LLD_Members_Admin_Log::csv_row( $row )
			);
			$item  = '' !== $c[4] ? ' · Member #' . $c[4] : '';
			$item .= '' !== $c[5] ? ' · ' . ( preg_match( '/^[a-z]+(_[a-z]+)+$/', $c[5] ) || isset( self::FEATURE_GROUPS['Food and health'][ $c[5] ] ) ? LLD_Members_Admin_Pages::plain_name( $c[5] ) : $c[5] ) : '';
			$state = '' !== $c[6] || '' !== $c[7] ? ' · ' . $c[6] . ' → ' . $c[7] : '';
			$html .= '<li><span class="lld-adm-when">' . esc_html( $c[1] . ' UTC' ) . '</span><span><strong>' . esc_html( $c[3] . $item ) . '</strong><span>' . esc_html( $c[2] . $state . ( '' !== $c[8] ? ' · “' . $c[8] . '”' : '' ) ) . '</span></span></li>';
		}
		return $html . '</ul>';
	}

	// ----- Members (FR3-75 to FR3-85, FR3-91, FR3-92) -----

	private static function page_members() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only search.
		$member = isset( $_GET['member'] ) ? (int) $_GET['member'] : 0;
		if ( $member > 0 ) {
			return self::member_page( $member );
		}
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$filters = array(
			'plan'   => isset( $_GET['plan'] ) ? sanitize_key( $_GET['plan'] ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			'test'   => isset( $_GET['test'] ) ? sanitize_key( $_GET['test'] ) : '',
		);
		// phpcs:enable
		$chips = array(
			'All'       => array(),
			'Free'      => array( 'plan' => 'free' ),
			'Premium'   => array( 'plan' => 'premium' ),
			'Paused'    => array( 'status' => 'paused' ),
			'Cancelled' => array( 'status' => 'cancelled' ),
			'Blocked'   => array( 'status' => 'blocked' ),
			'Test'      => array( 'test' => 'yes' ),
		);
		$html  = self::head( 'Members', 'Find a member, then open their account. Account details only: never goals, tracks, health data or results.' );
		$html .= '<section class="lld-card"><form method="get" action="' . esc_url( self::url( 'members' ) ) . '" role="search" class="lld-adm-search-form">';
		$html .= '<label class="lld-sr" for="lld-q">Email, name or member number</label><input type="search" id="lld-q" name="q" value="' . esc_attr( $q ) . '" placeholder="Search by email, name or member number" class="lld-adm-search">';
		foreach ( array_filter( $filters ) as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$html .= '<button type="submit" class="lld-button">Search</button></form>';
		$html .= '<ul class="lld-adm-chips" aria-label="Filter">';
		foreach ( $chips as $label => $args ) {
			$active = array_filter( $filters ) === $args;
			$html  .= '<li><a class="lld-adm-chip" href="' . esc_url( self::url( 'members', array_merge( '' !== $q ? array( 'q' => $q ) : array(), $args ) ) ) . '"' . ( $active ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		$html .= '</ul>';
		$rows  = LLD_Members_Admin_Members::search( $q, $filters, 100 );
		$html .= '<p class="lld-hint">' . count( $rows ) . ' member' . ( 1 === count( $rows ) ? '' : 's' ) . ( 100 === count( $rows ) ? ' (the first 100)' : '' ) . '.</p>';
		if ( ! $rows ) {
			return $html . '<div class="lld-adm-empty"><h3>No member matches</h3><p>Try part of the email, or the member number.</p></div></section>';
		}
		$tests = LLD_Members_Features::test_account_emails();
		$html .= '<ul class="lld-adm-list lld-adm-members">';
		foreach ( $rows as $row ) {
			$name  = trim( $row->first_name . ' ' . $row->last_name );
			$name  = '' !== $name ? $name : 'No name';
			$plan  = $row->access_state ? (string) $row->access_state : 'Free';
			$badge = in_array( strtolower( (string) $row->email ), $tests, true ) ? ( LLD_Members_Admin_Members::is_premium_test( (int) $row->id ) ? '<span class="lld-adm-badge is-test">Premium test</span>' : '<span class="lld-adm-badge is-test">Test</span>' ) : '';
			$html .= '<li><a href="' . esc_url( self::url( 'members', array( 'member' => (int) $row->id ) ) ) . '"><span class="lld-adm-avatar" aria-hidden="true">' . esc_html( strtoupper( substr( $name, 0, 1 ) ) ) . '</span>';
			$html .= '<span class="lld-adm-grow"><strong>' . esc_html( $name ) . ' <span class="lld-adm-num">#' . (int) $row->id . '</span></strong><span>' . esc_html( (string) $row->email ) . '</span></span>';
			$html .= '<span class="lld-adm-badges">' . $badge . '<span class="lld-adm-badge' . ( 0 === stripos( $plan, 'premium' ) ? ' is-green' : '' ) . '">' . esc_html( $plan ) . '</span>' . ( $row->blocked_at ? '<span class="lld-adm-badge is-red">Blocked</span>' : '' ) . '</span></a></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * One member's account page (FR3-76): account details, help actions for both roles, Owner actions apart.
	 *
	 * @param int $member_id Member number.
	 */
	private static function member_page( $member_id ) {
		$a     = LLD_Members_Admin_Members::account( $member_id );
		$crumb = ' › <a href="' . esc_url( self::url( 'members' ) ) . '">Members</a>';
		if ( ! $a ) {
			return self::head( 'Member not found', 'This member no longer exists.', '', $crumb );
		}
		$owner = LLD_Members_Admin_Roles::is_owner();
		$hide  = array( 'member' => $member_id );
		$ptm   = LLD_Members_Admin_Members::is_premium_test( $member_id );
		$badges = ( $a['test'] ? '<span class="lld-adm-badge is-test">' . ( $ptm ? 'Premium test member' : 'Test account' ) . '</span>' : '' ) . '<span class="lld-adm-badge">' . esc_html( $a['plan'] ) . '</span><span class="lld-adm-badge' . ( $a['blocked'] ? ' is-red' : '' ) . '">' . esc_html( $a['status'] ) . '</span>';
		$html   = self::head( '' !== $a['name'] ? $a['name'] : 'Member #' . $member_id, 'Member #' . $member_id . ' · ' . $a['email'], '<div class="lld-adm-badges">' . $badges . '</div>', $crumb );

		$facts = array(
			'Email'           => $a['email'] . ( $a['confirmed'] ? '' : ' (not confirmed yet)' ),
			'Sign-in methods' => implode( ', ', $a['methods'] ),
			'Joined'          => $a['joined'],
			'Last sign-in'    => '' !== $a['last_sign'] ? $a['last_sign'] . ' UTC' : 'Never',
			'Plan'            => $a['plan'] . ( $ptm ? ' (free, no end date)' : '' ),
			'Status'          => $a['status'] . ( $a['blocked'] && $owner ? ' (reason: ' . $a['blocked_why'] . ')' : '' ),
			'Renewal date'    => '' !== $a['renewal'] ? $a['renewal'] : '—',
			'Payment status'  => $a['payment'],
			'Test account'    => $a['test'] ? 'Yes' : 'No',
			'AI allowance'    => 'Not in use yet (Stage 5)',
		);
		$html .= '<div class="lld-adm-grid lld-adm-g2"><section class="lld-card"><h2>Account</h2><dl class="lld-facts lld-adm-facts">';
		foreach ( $facts as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
		}
		$html .= '</dl>';
		if ( $owner && '' !== $a['customer'] ) {
			$link = LLD_Members_Admin_Members::stripe_link( $a['customer'] );
			if ( '' !== $link ) {
				$html .= '<p><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">Open this member\'s customer page in Stripe</a> (refunds are made in Stripe)</p>';
			}
		}
		$html .= '<p class="lld-adm-note">Health details, goals, tracks, food diaries and results are never shown here.</p></section>';

		// Help this member: both roles (FR3-77 to FR3-79).
		$html .= '<section class="lld-card"><h2>Help this member</h2><p class="lld-hint">You and Support can do these.</p>';
		$html .= self::action( 'Resend an account email', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'resend' ) ) . self::select( 'kind', 'Email', array( 'sign_in_code' => 'Sign-in code', 'confirm' => 'Email confirmation', 'welcome' => 'Welcome email' ) ) . '<p><button class="lld-button">Resend</button></p></form>' );
		if ( $a['pause'] ) {
			$html .= self::action( 'Resume membership', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'resume' ) ) . '<p class="lld-hint">Premium returns today and Stripe restarts billing.</p>' . self::reason( 'lld-resume-reason', false ) . '<p><button class="lld-button">Resume membership</button></p></form>' );
		} elseif ( $a['monthly'] && 0 === strpos( $a['plan'], 'Premium' ) ) {
			$html .= self::action( 'Pause membership', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'pause' ) ) . self::select( 'months', 'Pause for', array( '1' => '1 month', '2' => '2 months', '3' => '3 months' ) ) . '<p class="lld-hint">Only when the member asks. It starts at the end of the paid month, and Stripe moves the renewal date.</p>' . self::reason( 'lld-pause-reason', false ) . '<p><button class="lld-button">Pause membership</button></p></form>' );
		}
		$html .= '<h3>Private notes</h3>' . LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'note' ) );
		$html .= '<label for="lld-note">Add a note</label><p class="lld-hint" id="lld-note-hint">No health details in notes. Members never see notes.</p><textarea id="lld-note" name="note" rows="3" maxlength="2000" aria-describedby="lld-note-hint" required></textarea><p><button class="lld-button lld-button-secondary">Add note</button></p></form>';
		if ( $a['notes'] ) {
			$html .= '<ul class="lld-adm-list">';
			foreach ( $a['notes'] as $n ) {
				$html .= '<li class="lld-note"><span><strong>' . nl2br( esc_html( (string) $n->note ) ) . '</strong><span>' . esc_html( LLD_Members_Admin_Log::who( (string) $n->admin_user_id ) . ', ' . $n->created_at . ' UTC' ) . '</span></span></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section></div>';

		if ( $owner ) {
			$html .= self::owner_actions( $a, $hide, $ptm );
		}
		return $html;
	}

	/**
	 * A collapsed action: its title opens the form.
	 *
	 * @param string $title Title.
	 * @param string $form  Form HTML.
	 * @param bool   $danger Red.
	 */
	private static function action( $title, $form, $danger = false ) {
		return '<details class="lld-adm-action' . ( $danger ? ' is-danger' : '' ) . '"><summary>' . esc_html( $title ) . '</summary><div class="lld-adm-action-body">' . $form . '</div></details>';
	}

	/**
	 * @param string               $name    Field.
	 * @param string               $label   Label.
	 * @param array<string,string> $options Options.
	 */
	private static function select( $name, $label, array $options ) {
		$html = '<label for="lld-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select id="lld-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			$html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $text ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * @param string $id       Field id.
	 * @param bool   $required Required.
	 * @param string $example  Placeholder.
	 */
	private static function reason( $id, $required = true, $example = '' ) {
		return '<label for="' . esc_attr( $id ) . '">' . ( $required ? 'Reason' : 'Note (optional)' ) . '</label><input type="text" id="' . esc_attr( $id ) . '" name="reason" maxlength="300"' . ( '' !== $example ? ' placeholder="' . esc_attr( $example ) . '"' : '' ) . ' aria-describedby="' . esc_attr( $id ) . '-hint"' . ( $required ? ' required' : '' ) . '><p class="lld-hint" id="' . esc_attr( $id ) . '-hint">Kept in the activity log. No names, emails or health details.</p>';
	}

	/**
	 * The fresh-code field and its "Email me a code" button (NFR3-20).
	 *
	 * @param string $id   Field id.
	 * @param string $back Where to come back to.
	 */
	private static function code( $id, $back ) {
		return '<label for="' . esc_attr( $id ) . '">Fresh code from your email</label><input type="text" id="' . esc_attr( $id ) . '" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>'
			. '<p class="lld-hint">No code yet? <button type="submit" class="lld-adm-linkbutton" form="lld-adm-code-' . esc_attr( $id ) . '">Email me a code</button></p>'
			. '</form>' . str_replace( '<form method="post"', '<form id="lld-adm-code-' . esc_attr( $id ) . '" method="post"', LLD_Members_Admin_Pages::form_open( 'lld_admin_code', 'lld_admin_code', array( 'back' => $back ), 'lld-adm-code-form' ) );
	}

	/**
	 * The Owner-only cards on a member's page. Support never sees them, and the handler refuses them for Support.
	 *
	 * @param array $a    Account.
	 * @param array $hide Hidden fields.
	 * @param bool  $ptm  Premium test member.
	 */
	private static function owner_actions( array $a, array $hide, $ptm ) {
		$back  = self::url( 'members', array( 'member' => (int) $a['id'] ) );
		$html  = '<h2 class="lld-adm-section">Owner actions</h2><div class="lld-adm-grid lld-adm-g3">';

		// Premium test member (FR3-91) and reset (FR3-92).
		$html .= '<section class="lld-card" data-lld-adm="premium-test"><h3>Premium test member</h3><p class="lld-hint">See the site exactly as a paying member does, without paying. Left out of counts and revenue.</p>';
		if ( $ptm ) {
			$html .= '<p><span class="lld-adm-badge is-test">On</span></p>';
			$html .= self::action( 'Return to Free', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'ptm', 'on' => '' ) ) . '<p class="lld-hint">Nothing is deleted.</p>' . self::reason( 'lld-ptm-off-reason', true, 'For example: testing finished' ) . '<p><button class="lld-button">Return to Free</button></p></form>' );
		} else {
			$html .= self::action( 'Make Premium test member', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'ptm', 'on' => '1' ) ) . '<p class="lld-hint">Every Premium feature, free, with no end date and no Stripe charge. Up to ' . (int) LLD_Members_Admin_Members::PREMIUM_TEST_LIMIT . ' accounts.</p>' . self::reason( 'lld-ptm-on-reason', true, 'For example: testing tracks and guides' ) . '<p><button class="lld-button">Make Premium test member</button></p></form>' );
		}
		if ( $a['test'] ) {
			$html .= self::action( 'Reset to a clean start', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'reset' ) ) . '<p class="lld-hint">Deletes this test member\'s goals, plans, logs, saved results and track progress. The account and its test status stay. This can\'t be undone.</p>' . self::reason( 'lld-reset-reason', true, 'For example: walk through joining again' ) . '<label for="lld-reset-typed">Type RESET to confirm</label><input type="text" id="lld-reset-typed" name="typed" autocomplete="off" required><p><button class="lld-button lld-adm-danger-button">Reset</button></p></form>', true );
		}
		$html .= '</section>';

		// Free Premium (FR3-80).
		$grant = LLD_Members_Admin_Members::active_grant( (int) $a['id'] );
		$html .= '<section class="lld-card"><h3>Free Premium</h3><p class="lld-hint">Premium until a date, separate from Stripe. When it ends the member returns to Free and nothing is deleted.</p>';
		if ( $grant && ! $ptm ) {
			$html .= '<p>Until ' . esc_html( substr( (string) $grant->ends_at, 0, 10 ) ) . '</p>' . self::action( 'Remove free Premium', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'ungrant' ) ) . self::reason( 'lld-ungrant-reason' ) . '<p><button class="lld-button">Remove free Premium</button></p></form>' );
		} elseif ( ! $ptm ) {
			$html .= self::action( 'Give free Premium', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'grant' ) ) . '<label for="lld-grant-end">Until</label><input type="date" id="lld-grant-end" name="ends_on" min="' . esc_attr( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ) . '" required>' . self::reason( 'lld-grant-reason', true, 'For example: thank-you for feedback' ) . '<p><button class="lld-button">Give free Premium</button></p></form>' );
		} else {
			$html .= '<p class="lld-hint">A Premium test member already has Premium.</p>';
		}
		if ( $a['grants'] ) {
			$html .= '<ul class="lld-adm-small">';
			foreach ( $a['grants'] as $g ) {
				$ends  = substr( (string) $g->ends_at, 0, 10 );
				$html .= '<li>' . esc_html( substr( (string) $g->starts_at, 0, 10 ) . ' to ' . ( $ends >= LLD_Members_Admin_Members::NO_END ? 'no end date' : $ends ) . ( $g->removed_at ? ', removed ' . substr( (string) $g->removed_at, 0, 10 ) : '' ) . ' — ' . $g->reason ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section>';

		// Data download (FR3-85).
		$html .= '<section class="lld-card"><h3>Their data</h3><p class="lld-hint">Email the member a link to download their own data. They sign in to download it; you never see the file.</p>';
		$html .= self::action( 'Send the download link', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'download' ) ) . self::reason( 'lld-download-reason', false ) . '<p><button class="lld-button">Send the download link</button></p></form>' ) . '</section>';
		$html .= '</div>';

		// Changes that matter: test account, end, block, delete.
		$html .= '<section class="lld-card lld-adm-danger-card"><h2>Account changes that matter</h2><p class="lld-hint">Each needs a reason and is kept in the activity log.</p><div class="lld-adm-actions">';
		if ( ! $ptm ) {
			$html .= self::action( $a['test'] ? 'Unmark as a test account' : 'Mark as a test account', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'test', 'on' => $a['test'] ? '' : '1' ) ) . self::reason( 'lld-test-reason' ) . '<p><button class="lld-button">' . ( $a['test'] ? 'Unmark as a test account' : 'Mark as a test account' ) . '</button></p></form>' );
		}
		if ( $a['paying'] && LLD_Members_Billing::paid_state( $a['id'] ) ) {
			$end   = LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'end' ) );
			$end  .= '<fieldset><legend>When</legend><label class="lld-adm-radio"><input type="radio" name="when" value="period_end" checked> At the end of the paid period (keeps Premium until then)</label><label class="lld-adm-radio"><input type="radio" name="when" value="now"> Now (Premium stops at once)</label></fieldset>';
			$end  .= '<p class="lld-hint">Stripe is cancelled to match. Refunds are made in Stripe, not here.</p>' . self::reason( 'lld-end-reason' ) . '<p><button class="lld-button lld-adm-danger-button">End membership</button></p></form>';
			$html .= self::action( 'End membership', $end, true );
		}
		$block = LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => $a['blocked'] ? 'unblock' : 'block' ) );
		$block .= '<p class="lld-hint">' . ( $a['blocked'] ? 'The member can sign in again, and billing restarts if the block paused it.' : 'The member is signed out at once and can\'t sign in. A paying member\'s billing is paused while blocked.' ) . '</p>' . self::reason( 'lld-block-reason' ) . '<p><button class="lld-button lld-adm-danger-button">' . ( $a['blocked'] ? 'Unblock member' : 'Block member' ) . '</button></p></form>';
		$html .= self::action( $a['blocked'] ? 'Unblock' : 'Block', $block, true );
		$del   = str_replace( '<form method="post"', '<form id="lld-adm-delete" method="post"', LLD_Members_Admin_Pages::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'delete' ) ) );
		$del  .= '<p><strong>This can\'t be undone.</strong> It runs the same deletion as the member\'s own request: account, profile and sign-in, goals, logs, results and notes. Activity log entries keep only the member number.</p>';
		$del  .= '<label for="lld-delete-email">Type the member\'s email</label><input type="email" id="lld-delete-email" name="email" autocomplete="off" required>' . self::reason( 'lld-delete-reason' );
		$del  .= self::code( 'lld-delete-code', $back ) . '<button class="lld-button lld-adm-danger-button" form="lld-adm-delete">Delete for good</button></form>';
		$html .= self::action( 'Delete member', $del, true );
		return $html . '</div></section>';
	}

	// ----- Tools (FR3-88, FR3-89) -----

	private static function page_tools() {
		$owner = LLD_Members_Admin_Roles::is_owner();
		$html  = self::head( 'Tools', 'Every health tool, with one switch each. A switch saves straight away; members see the change on their next page.' );
		if ( ! $owner ) {
			$html .= '<p class="lld-adm-note is-amber">You can see the switches. Only the Owner can change them.</p>';
		}
		$html .= '<section class="lld-card"><h2>Standalone tools</h2><p class="lld-hint">Turning one off shows "temporarily unavailable" on its page and card. Its approved calculation never changes.</p><ul class="lld-adm-tools">';
		foreach ( self::STANDALONE as $slug => $t ) {
			$on    = ! function_exists( 'lld_tools_tool_status' ) || 'withdrawn' !== lld_tools_tool_status( $slug );
			$html .= self::tool_row( 'lld_admin_tool', $slug, $t[0], $t[1], $on, $owner && function_exists( 'lld_tools_write_status' ), 'tool_' . $slug );
		}
		$html .= '</ul></section>';

		$master = 'off' !== LLD_Members_Features::state( 'calculators_new' );
		$html  .= '<section class="lld-card"><h2>Calculators</h2><p class="lld-hint">Six pages on the Health Tools hub, and two inside members\' plans.</p>';
		$html  .= '<div class="lld-adm-master"><span><strong>All eight calculators</strong>' . ( $master ? '' : '<span class="lld-hint">All eight are off. Each keeps its own switch for when you turn them back on.</span>' ) . '</span>' . self::toggle( 'lld_admin_feature', 'calculators_new', 'All eight calculators', $master, $owner ) . '</div><ul class="lld-adm-tools">';
		$members_only = false;
		foreach ( self::CALCULATORS as $key => $t ) {
			if ( '' === $t[1] && ! $members_only ) {
				$members_only = true;
				$html        .= '</ul><p class="lld-adm-nav-group">Inside members\' plans</p><ul class="lld-adm-tools">';
			}
			$on    = 'off' !== LLD_Members_Features::state( $key );
			$html .= self::tool_row( 'lld_admin_feature', $key, $t[0], '' !== $t[1] ? $t[1] : 'Inside members\' plans', $on, $owner && $master, $key );
		}
		$html .= '</ul></section>';

		$html .= '<section class="lld-card lld-adm-muted"><h2>AI tools <span class="lld-adm-badge">Stage 5</span></h2><p class="lld-hint">Each AI tool gets its own switch here when it is built. A new AI tool starts off until you have tried it.</p><ul class="lld-adm-tools">';
		foreach ( self::AI_TOOLS as $name ) {
			$html .= '<li><span class="lld-adm-grow"><strong>' . esc_html( $name ) . '</strong><span class="lld-hint">Arrives in Stage 5</span></span><label class="lld-adm-switch"><input type="checkbox" role="switch" disabled aria-label="' . esc_attr( $name ) . '"><span class="lld-adm-track" aria-hidden="true"></span><span class="lld-adm-state">Off</span></label></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * One tool line: plain name, address, switch, history.
	 *
	 * @param string $action  Handler.
	 * @param string $key     Key.
	 * @param string $name    Name.
	 * @param string $where   Address.
	 * @param bool   $on      On.
	 * @param bool   $can     May change.
	 * @param string $history History key.
	 */
	private static function tool_row( $action, $key, $name, $where, $on, $can, $history ) {
		$html  = '<li id="tool-' . esc_attr( $key ) . '" data-lld-tool="' . esc_attr( $key ) . '"><span class="lld-adm-grow"><strong>' . esc_html( $name ) . ( $on ? '' : ' <span class="lld-adm-badge is-amber">Off</span>' ) . '</strong>';
		$html .= '<span class="lld-hint">' . esc_html( $where ) . '</span>' . self::history( $history ) . '</span>';
		return $html . '<span class="lld-adm-toggle">' . self::toggle( $action, $key, $name, $on, $can ) . '</span></li>';
	}

	// ----- Features (FR3-74) -----

	private static function page_features() {
		$owner = LLD_Members_Admin_Roles::is_owner();
		$html  = self::head( 'Features', 'Every other part of the site, with one switch each. The health tools are on the Tools page.' );
		if ( ! $owner ) {
			$html .= '<p class="lld-adm-note is-amber">You can see the switches. Only the Owner can change them.</p>';
		}
		$catalog = LLD_Members_Features::catalog();
		$listed  = array();
		foreach ( self::FEATURE_GROUPS as $group => $items ) {
			$html .= '<section class="lld-card"><h2>' . esc_html( $group ) . '</h2><ul class="lld-adm-tools">';
			foreach ( $items as $key => $f ) {
				if ( ! isset( $catalog[ $key ] ) ) {
					continue;
				}
				$listed[] = $key;
				$html    .= self::tool_row( 'lld_admin_feature', $key, $f[0], $f[1], 'off' !== LLD_Members_Features::state( $key ), $owner, $key );
			}
			$html .= '</ul></section>';
		}
		// A feature added to the catalogue later still shows, by its description, until it is given a plain name here.
		$rest = array_diff( array_keys( $catalog ), $listed, array( 'calculators_new' ), array_keys( self::CALCULATORS ) );
		if ( $rest ) {
			$html .= '<section class="lld-card"><h2>Other</h2><ul class="lld-adm-tools">';
			foreach ( $rest as $key ) {
				$html .= self::tool_row( 'lld_admin_feature', $key, ucfirst( preg_replace( '/^P3-\S+\s*/', '', (string) $catalog[ $key ] ) ), '', 'off' !== LLD_Members_Features::state( $key ), $owner, $key );
			}
			$html .= '</ul></section>';
		}

		$html .= '<section class="lld-card" id="test-accounts"><h2>Test accounts</h2><p class="lld-hint">These members are labelled Test and left out of member counts, revenue and reports. The Owner counts as a test account automatically.</p>';
		$emails = LLD_Members_Features::test_account_emails();
		if ( ! $emails ) {
			$html .= '<div class="lld-adm-empty"><h3>No test accounts yet</h3><p>Add an email below, or mark a member as a test account from their page.</p></div>';
		} else {
			$html .= '<ul class="lld-adm-list">';
			foreach ( $emails as $email ) {
				$html .= '<li><span class="lld-adm-grow"><strong>' . esc_html( $email ) . '</strong></span>';
				if ( $owner ) {
					$html .= self::action( 'Remove', LLD_Members_Admin_Pages::form_open( 'lld_admin_test_accounts', 'lld_admin_test_accounts', array( 'do' => 'remove', 'email' => $email ) ) . self::reason( 'lld-ta-' . md5( $email ) ) . '<p><button class="lld-button lld-button-secondary">Remove from test accounts</button></p></form>' );
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}
		if ( $owner ) {
			$html .= LLD_Members_Admin_Pages::form_open( 'lld_admin_test_accounts', 'lld_admin_test_accounts', array( 'do' => 'add' ), 'lld-adm-inline-form' );
			$html .= '<div><label for="lld-ta-email">Add an email</label><input type="email" id="lld-ta-email" name="email" required></div><div>' . self::reason( 'lld-ta-reason', true, 'For example: a new test member' ) . '</div><p><button class="lld-button">Add</button></p></form>';
		}
		return $html . '</section>';
	}

	// ----- Activity log (FR3-86) -----

	private static function page_activity() {
		$owner = LLD_Members_Admin_Roles::is_owner();
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$csv   = $owner ? '<a class="lld-button lld-button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lld_admin_log_csv' ), 'lld_admin_log_csv' ) ) . '">Download as CSV</a>' : '';
		$html  = self::head( $owner ? 'Activity log' : 'Your actions', $owner ? 'Every admin action, from both roles and the command line. Entries can\'t be changed or deleted, and are kept for 2 years.' : 'The actions you have taken in Admin.', $csv );
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows  = LLD_Members_Admin_Log::visible( '' !== $q ? 500 : 50, '' !== $q ? 0 : ( $paged - 1 ) * 50 );
		if ( '' !== $q ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $q ) {
						// Matches the words shown on the page too: "Member #12" and plain switch names.
						$c = LLD_Members_Admin_Log::csv_row( $row );
						$shown = implode( ' ', $c ) . ( '' !== (string) $c[4] ? ' Member #' . $c[4] : '' ) . ( '' !== (string) $c[5] ? ' ' . LLD_Members_Admin_Pages::plain_name( (string) $c[5] ) : '' );
						return false !== stripos( $shown, $q );
					}
				)
			);
		}
		$html .= '<section class="lld-card"><form method="get" action="' . esc_url( self::url( 'activity' ) ) . '" role="search" class="lld-adm-search-form"><label class="lld-sr" for="lld-log-q">Search the log</label><input type="search" id="lld-log-q" name="q" value="' . esc_attr( $q ) . '" placeholder="Search by action, member number or who" class="lld-adm-search"><button type="submit" class="lld-button">Search</button></form>';
		$html .= self::log_list( $rows ) . '<p class="lld-adm-pager">';
		if ( $paged > 1 ) {
			$html .= '<a href="' . esc_url( self::url( 'activity', array( 'paged' => $paged - 1 ) ) ) . '">Newer</a> ';
		}
		if ( '' === $q && count( $rows ) === 50 ) {
			$html .= '<a href="' . esc_url( self::url( 'activity', array( 'paged' => $paged + 1 ) ) ) . '">Older</a>';
		}
		return $html . '</p></section>';
	}

	// ----- Helpers (FR3-71 AS-03) -----

	private static function page_helpers() {
		$back    = self::url( 'helpers' );
		$helpers = LLD_Members_Admin_Roles::helpers();
		$html    = self::head( 'Helpers', 'People who help members. Each has their own account and enters an emailed code at every sign-in. They see only Overview and Members.' );
		$html   .= '<div class="lld-adm-grid lld-adm-g2"><section class="lld-card"><h2>Support helpers</h2>';
		if ( ! $helpers ) {
			$html .= '<div class="lld-adm-empty"><h3>No helpers yet</h3><p>Add someone you trust to help members.</p></div>';
		} else {
			$html .= '<ul class="lld-adm-list">';
			foreach ( $helpers as $h ) {
				$html .= '<li><span class="lld-adm-grow"><strong>' . esc_html( $h->display_name ) . '</strong><span>' . esc_html( $h->user_email ) . '</span></span>';
				$html .= self::action( 'Email a set-password link', LLD_Members_Admin_Pages::form_open( 'lld_admin_helper', 'lld_admin_helper', array( 'do' => 'reset', 'user' => (int) $h->ID ) ) . '<p><button class="lld-button lld-button-secondary">Send the link</button></p></form>' );
				$rm    = str_replace( '<form method="post"', '<form id="lld-adm-rm-' . (int) $h->ID . '" method="post"', LLD_Members_Admin_Pages::form_open( 'lld_admin_helper', 'lld_admin_helper', array( 'do' => 'remove', 'user' => (int) $h->ID ) ) );
				$rm   .= '<p class="lld-hint">They are signed out at once and lose Admin. Their activity log entries stay.</p>' . self::reason( 'lld-remove-' . (int) $h->ID ) . self::code( 'lld-remove-code-' . (int) $h->ID, $back ) . '<button class="lld-button lld-adm-danger-button" form="lld-adm-rm-' . (int) $h->ID . '">Remove helper</button></form>';
				$html .= self::action( 'Remove', $rm, true ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section><section class="lld-card"><h2>Add a helper</h2><p class="lld-hint">They get an email to set their own password. You get an alert email too.</p>';
		$add   = str_replace( '<form method="post"', '<form id="lld-adm-add-helper" method="post"', LLD_Members_Admin_Pages::form_open( 'lld_admin_helper', 'lld_admin_helper', array( 'do' => 'add' ) ) );
		$add  .= '<label for="lld-helper-name">Name</label><input type="text" id="lld-helper-name" name="name" required>';
		$add  .= '<label for="lld-helper-email">Email</label><input type="email" id="lld-helper-email" name="email" required aria-describedby="lld-helper-email-hint"><p class="lld-hint" id="lld-helper-email-hint">Their own email, not a member\'s.</p>';
		$add  .= self::reason( 'lld-helper-reason', true, 'For example: covers support at weekends' ) . self::code( 'lld-helper-code', $back ) . '<button class="lld-button" form="lld-adm-add-helper">Add helper</button></form>';
		return $html . $add . '</section></div>';
	}

	// ----- The Stage 2 screens, shown here as they are (Payments, Weekly report, Group removals) -----

	/**
	 * Show a Stage 2 admin screen inside the admin shell. Its own form posts back to this address and the screen
	 * handles it, as in the WordPress admin (its old address keeps working too: the Stage 2 tests use it).
	 *
	 * @param callable $callback Screen.
	 * @param string   $title    Title.
	 * @param string   $intro    Intro.
	 */
	private static function stage2( $callback, $title, $intro ) {
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		ob_start();
		call_user_func( $callback );
		$screen = (string) ob_get_clean();
		$screen = preg_replace( '#<div class="wrap">\s*<h1>[^<]*</h1>#', '<div class="wrap">', $screen, 1 );
		return self::head( $title, $intro ) . '<section class="lld-card lld-adm-legacy">' . $screen . '</section>';
	}

	private static function page_payments() {
		return self::stage2( array( 'LLD_Members_Billing', 'admin_page' ), 'Payments', 'Prices, discount codes and the first-payment refund. Other refunds are made in Stripe.' );
	}

	private static function page_report() {
		return self::stage2( array( 'LLD_Members_Growth', 'admin_report' ), 'Weekly report', 'The weekly numbers, without names or health details.' );
	}

	private static function page_group() {
		return self::stage2( array( 'LLD_Members_Premium', 'admin_page' ), 'Group removals', 'Members who left Premium and need removing from the Premium group.' );
	}

	// ----- Staging emails (FR3-93) -----

	private static function page_emails() {
		$html  = self::head( 'Staging emails', 'Staging never sends real email. Every message is caught here, newest first. This page exists on staging only.' );
		$html .= '<p class="lld-adm-note">Waiting for a sign-in code? Reload this page; it appears at the top within a few seconds.</p>';
		$mail  = self::caught_mail( 30 );
		if ( ! $mail ) {
			return $html . '<section class="lld-card"><div class="lld-adm-empty"><h3>No emails caught yet</h3><p>Emails the site would send appear here.</p></div></section>';
		}
		$html .= '<section class="lld-card"><ul class="lld-adm-list lld-adm-mail">';
		foreach ( $mail as $i => $m ) {
			$code  = preg_match( '/\b(\d{6})\b/', (string) $m['subject'] . ' ' . (string) $m['message'], $mm ) && false !== stripos( (string) $m['subject'], 'code' ) ? $mm[1] : '';
			$html .= '<li><details' . ( 0 === $i ? ' open' : '' ) . '><summary><span class="lld-adm-when">' . esc_html( str_replace( 'T', ' ', substr( (string) $m['time'], 0, 16 ) ) . ' UTC' ) . '</span><span class="lld-adm-grow"><strong>' . esc_html( (string) $m['subject'] ) . ( '' !== $code ? ' <span class="lld-adm-code" data-lld-code>' . esc_html( $code ) . '</span>' : '' ) . '</strong><span>To ' . esc_html( implode( ', ', (array) $m['to'] ) ) . '</span></span></summary>';
			$html .= '<pre class="lld-adm-mail-body">' . esc_html( (string) $m['message'] ) . '</pre></details></li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * The newest caught messages (lld-tools staging-mail.php writes one JSON line per message).
	 *
	 * @param int $limit How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function caught_mail( $limit ) {
		if ( ! function_exists( 'lld_mail_dir' ) ) {
			return array();
		}
		$files = glob( lld_mail_dir() . '/*.jsonl' ) ?: array();
		rsort( $files );
		$out = array();
		foreach ( $files as $file ) {
			$lines = array_reverse( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
			foreach ( $lines as $line ) {
				$row = json_decode( (string) $line, true );
				if ( is_array( $row ) ) {
					$out[] = $row;
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		}
		return $out;
	}

	// ----- AI (FR5-30) -----

	private static function page_ai() {
		$parts = array( 'Providers and keys', 'Privacy check for each provider', 'Models and prices', 'On and off', 'Test connection', 'Which AI does each job', 'Spending limits and alerts', 'Member allowances and use' );
		$html  = self::head( 'AI', 'Settings for the AI tools.' );
		$html .= '<section class="lld-card lld-adm-ai"><h2>AI tools arrive in Stage 5</h2><p class="lld-hint">This page will hold everything about AI in one place. Nothing here can be used yet.</p><ul class="lld-adm-ai-parts">';
		foreach ( $parts as $p ) {
			$html .= '<li>' . esc_html( $p ) . '</li>';
		}
		return $html . '</ul></section>';
	}
}
