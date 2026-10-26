<?php
/**
 * Hooks, rewrites and activation.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
class LLD_Members_Plugin {

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		LLD_Members_Content::boot();
		LLD_Members_Personal::boot();
		LLD_Members_Billing::boot();
		LLD_Members_Premium::boot();
		LLD_Members_Growth::boot();
		LLD_Members_Quality::boot();
		add_action( 'init', array( 'LLD_Members_Account', 'expire_sessions' ), 20 );
		add_action( 'profile_update', array( 'LLD_Members_Account', 'sync_email' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'keep_sitemap_url' ), 10, 2 );
		add_action( 'template_redirect', array( 'LLD_Members_Screens', 'dispatch' ) );
		add_action( 'rest_api_init', array( 'LLD_Members_Rest', 'register' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_closed' ), 10, 3 );
		add_filter( 'rest_namespace_index', array( __CLASS__, 'namespace_index' ), 10, 2 );
		add_action( 'phpmailer_init', array( 'LLD_Members_Mail', 'configure' ), 20 );
		add_filter( 'wp_mail_from', array( 'LLD_Members_Mail', 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( 'LLD_Members_Mail', 'from_name' ) );
		add_filter( 'authenticate', array( 'LLD_Members_Auth', 'block_when_closed' ), 1, 1 );
		add_filter( 'authenticate', array( 'LLD_Members_Auth', 'enforce_lock' ), 30, 3 );
		add_filter( 'authenticate', array( 'LLD_Members_Auth', 'require_confirmed' ), 40, 1 );
		add_action( 'login_form_lostpassword', array( 'LLD_Members_Auth', 'lost_password_page' ) );
		add_action( 'login_form_retrievepassword', array( 'LLD_Members_Auth', 'lost_password_page' ) );
		add_filter( 'allow_password_reset', array( 'LLD_Members_Auth', 'allow_password_reset' ), 10, 2 );
		add_filter( 'lostpassword_url', array( 'LLD_Members_Auth', 'lost_password_url' ) );
		add_filter( 'lostpassword_redirect', array( 'LLD_Members_Auth', 'lost_password_redirect' ) );
		add_filter( 'authenticate', array( 'LLD_Members_Auth', 'require_code' ), 99, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( 'LLD_Members_Auth', 'application_passwords' ), 10, 2 );
		add_action( 'wp_login_failed', array( 'LLD_Members_Auth', 'redirect_wp_login' ), 10, 2 );
		add_filter( 'auth_cookie_expiration', array( 'LLD_Members_Auth', 'cookie_expiration' ), 10, 3 );
		add_action( 'lld_members_daily', array( 'LLD_Members_Erasure', 'email_new_lines' ) );
		add_action( 'lld_members_daily', array( 'LLD_Members_Privacy', 'sweep' ) );
		add_action( 'lld_members_sweep_downloads', array( 'LLD_Members_Privacy', 'sweep_downloads' ), 10, 0 );
		if ( ! wp_next_scheduled( 'lld_members_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lld_members_daily' );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once LLD_MEMBERS_DIR . 'includes/class-cli.php';
			LLD_Members_CLI::register();
		}
	}

	public static function activate() {
		LLD_Members_Schema::update();
		self::register_rewrites();
		flush_rewrite_rules();
		delete_option( 'lld_members_rewrites' );
	}

	public static function rewrites() {
		self::register_rewrites();
		if ( '9' !== get_option( 'lld_members_rewrites' ) ) {
			flush_rewrite_rules( false );
			update_option( 'lld_members_rewrites', '9', true );
		}
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'lld_screen';
		$vars[] = 'lld_member';
		$vars[] = 'lld_item';
		$vars[] = 'lld_track';
		return $vars;
	}

	/**
	 * Keep /sitemap.xml as it is. WordPress would add a trailing slash (redirect_canonical), and /sitemap.xml/
	 * no longer matches the plugin's sitemap rule, so the sitemap answered 404 after the redirect.
	 *
	 * @param string|false $redirect_url  Canonical URL WordPress wants.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_sitemap_url( $redirect_url, $requested_url ) {
		unset( $requested_url );
		return 'sitemap' === get_query_var( 'lld_screen' ) ? false : $redirect_url;
	}

	/**
	 * Member JSON stays closed while a restore is being checked.
	 *
	 * @param mixed           $result  Response so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_closed( $result, $server, $request ) {
		unset( $server );
		if ( ! get_option( 'lld_members_closed' ) || current_user_can( 'manage_options' ) ) {
			return $result;
		}
		$route = $request->get_route();
		// The restore procedure's test helpers (ci and local only, with the test secret) still run.
		if ( is_string( $route ) && 0 === strpos( $route, '/lld-members/v1/test/' ) && lld_members_test_request_allowed() ) {
			return $result;
		}
		if ( is_string( $route ) && 0 === strpos( $route, '/lld-members/' ) ) {
			return new WP_Error( 'lld_closed', 'Lifeline Daily is closed', array( 'status' => 503 ) );
		}
		return $result;
	}

	/**
	 * Add the live member rewrite rules to the namespace index so tests do not trust a hand-written list.
	 *
	 * @param WP_REST_Response $response Index.
	 * @param WP_REST_Request  $request  Request.
	 */
	public static function namespace_index( $response, $request ) {
		if ( '/lld-members/v1' !== $request->get_route() ) {
			return $response;
		}
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$paths = array();
		if ( is_array( $rules ) ) {
			foreach ( array_keys( $rules ) as $pattern ) {
				$pattern = (string) $pattern;
				if ( 0 === strpos( $pattern, '^members/' ) || 0 === strpos( $pattern, 'members/' ) ) {
					$paths[] = $pattern;
				}
			}
		}
		$data = $response->get_data();
		$data['lld_member_rewrites'] = $paths;
		$response->set_data( $data );
		return $response;
	}

	public static function register_rewrites() {
		add_rewrite_rule( '^members/join/?$', 'index.php?lld_screen=join', 'top' );
		add_rewrite_rule( '^members/confirm/?$', 'index.php?lld_screen=confirm', 'top' );
		add_rewrite_rule( '^members/reset/?$', 'index.php?lld_screen=reset', 'top' );
		add_rewrite_rule( '^members/agreements/?$', 'index.php?lld_screen=agreements', 'top' );
		add_rewrite_rule( '^members/goals/?$', 'index.php?lld_screen=goals', 'top' );
		add_rewrite_rule( '^members/data/?$', 'index.php?lld_screen=data', 'top' );
		add_rewrite_rule( '^members/withdraw/?$', 'index.php?lld_screen=withdraw', 'top' );
		add_rewrite_rule( '^members/delete/?$', 'index.php?lld_screen=delete', 'top' );
		add_rewrite_rule( '^members/email/?$', 'index.php?lld_screen=email', 'top' );
		add_rewrite_rule( '^members/unsubscribe/?$', 'index.php?lld_screen=unsubscribe', 'top' );
		add_rewrite_rule( '^members/sign-in/?$', 'index.php?lld_screen=sign-in', 'top' );
		add_rewrite_rule( '^members/two-step/?$', 'index.php?lld_screen=two-step', 'top' );
		add_rewrite_rule( '^members/settings/?$', 'index.php?lld_screen=settings', 'top' );
		add_rewrite_rule( '^members/home/?$', 'index.php?lld_screen=home', 'top' );
		add_rewrite_rule( '^members/checkout/?$', 'index.php?lld_screen=checkout', 'top' );
		add_rewrite_rule( '^members/subscription/?$', 'index.php?lld_screen=subscription', 'top' );
		add_rewrite_rule( '^pricing/?$', 'index.php?lld_screen=pricing', 'top' );
		add_rewrite_rule( '^programmes/(healthy-weight|blood-sugar|fitness|sleep-stress)/?$', 'index.php?lld_screen=sales&lld_track=$matches[1]', 'top' );
		add_rewrite_rule( '^health-tools/protein/?$', 'index.php?lld_screen=landing&lld_track=protein', 'top' );
		add_rewrite_rule( '^health-tools/diabetes-risk-check/?$', 'index.php?lld_screen=landing&lld_track=diabetes', 'top' );
		add_rewrite_rule( '^safety/?$', 'index.php?lld_screen=safety', 'top' );
		add_rewrite_rule( '^medical-disclaimer/?$', 'index.php?lld_screen=disclaimer', 'top' );
		add_rewrite_rule( '^sitemap\\.xml$', 'index.php?lld_screen=sitemap', 'top' );
		add_rewrite_rule( '^members/foundations/?$', 'index.php?lld_screen=foundations', 'top' );
		add_rewrite_rule( '^members/record/([0-9]+)/?$', 'index.php?lld_screen=record&lld_member=$matches[1]', 'top' );
		add_rewrite_rule( '^members/photo/([0-9]+)/?$', 'index.php?lld_screen=photo&lld_member=$matches[1]', 'top' );
		add_rewrite_rule( '^members/library/?$', 'index.php?lld_screen=library', 'top' );
		add_rewrite_rule( '^members/library/([0-9]+)/?$', 'index.php?lld_screen=library&lld_item=$matches[1]', 'top' );
		add_rewrite_rule( '^members/read/([0-9]+)/?$', 'index.php?lld_screen=read&lld_item=$matches[1]', 'top' );
		add_rewrite_rule( '^members/track/?$', 'index.php?lld_screen=track', 'top' );
		add_rewrite_rule( '^members/review/?$', 'index.php?lld_screen=review', 'top' );
		add_rewrite_rule( '^members/(health|suggestions|tracks|screening|check-in|plan|meals|workout|challenge|badges|diary)/?$', 'index.php?lld_screen=$matches[1]', 'top' );
		add_rewrite_rule( '^members/group/?$', 'index.php?lld_screen=group', 'top' );
		add_rewrite_rule( '^members/help/?$', 'index.php?lld_screen=help', 'top' );
		add_rewrite_rule( '^members/more/?$', 'index.php?lld_screen=more', 'top' );
		add_rewrite_rule( '^coming-soon/(healthy-weight|blood-sugar|fitness|sleep-stress)/?$', 'index.php?lld_screen=waitlist&lld_track=$matches[1]', 'top' );
		add_rewrite_rule( '^guide/confirm/?$', 'index.php?lld_screen=guide-confirm', 'top' );
		add_rewrite_rule( '^guide/unsubscribe/?$', 'index.php?lld_screen=guide-unsub', 'top' );
		add_rewrite_rule( '^testimonials/?$', 'index.php?lld_screen=quotes', 'top' );
		add_rewrite_rule( '^members/diary-photo/([0-9]+)/?$', 'index.php?lld_screen=diary-photo&lld_member=$matches[1]', 'top' );
	}
}
