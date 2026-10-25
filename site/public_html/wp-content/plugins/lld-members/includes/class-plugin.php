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
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( 'LLD_Members_Screens', 'dispatch' ) );
		add_action( 'rest_api_init', array( 'LLD_Members_Rest', 'register' ) );
		add_action( 'phpmailer_init', array( 'LLD_Members_Mail', 'configure' ) );
		add_filter( 'wp_mail_from', array( 'LLD_Members_Mail', 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( 'LLD_Members_Mail', 'from_name' ) );
		add_filter( 'authenticate', array( 'LLD_Members_Auth', 'require_code' ), 99, 3 );
		add_action( 'wp_login_failed', array( 'LLD_Members_Auth', 'redirect_wp_login' ), 10, 2 );
		add_filter( 'auth_cookie_expiration', array( 'LLD_Members_Auth', 'cookie_expiration' ), 10, 3 );
		add_action( 'lld_members_daily', array( 'LLD_Members_Erasure', 'email_new_lines' ) );
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
		if ( '2' !== get_option( 'lld_members_rewrites' ) ) {
			flush_rewrite_rules( false );
			update_option( 'lld_members_rewrites', '2', true );
		}
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'lld_screen';
		$vars[] = 'lld_member';
		return $vars;
	}

	public static function register_rewrites() {
		add_rewrite_rule( '^members/sign-in/?$', 'index.php?lld_screen=sign-in', 'top' );
		add_rewrite_rule( '^members/two-step/?$', 'index.php?lld_screen=two-step', 'top' );
		add_rewrite_rule( '^members/settings/?$', 'index.php?lld_screen=settings', 'top' );
		add_rewrite_rule( '^members/home/?$', 'index.php?lld_screen=home', 'top' );
		add_rewrite_rule( '^members/checkout/?$', 'index.php?lld_screen=checkout', 'top' );
		add_rewrite_rule( '^members/foundations/?$', 'index.php?lld_screen=foundations', 'top' );
		add_rewrite_rule( '^members/record/([0-9]+)/?$', 'index.php?lld_screen=record&lld_member=$matches[1]', 'top' );
		add_rewrite_rule( '^members/photo/([0-9]+)/?$', 'index.php?lld_screen=photo&lld_member=$matches[1]', 'top' );
	}
}
