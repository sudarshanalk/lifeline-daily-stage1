<?php
/**
 * Google Analytics 4 on public pages (P3-70, FR3-66) and the cookie banner (P3-71, FR3-67), under NFR3-16.
 *
 * Rules (owner, 2 Nov 2026):
 * - nothing is sent to Google before the visitor accepts: the Google tag itself is only fetched after Accept, so
 *   Consent Mode's cookieless pings never happen (TC-FR3-67-AS-02, AS-03);
 * - the tag is never output in the member area or on a calculator result: only the public screens listed in
 *   PUBLIC_SCREENS and ordinary WordPress pages get it, plus the sign-up and checkout steps (TC-NFR3-16-AS-01);
 * - events carry no health data and no member number: page_view with a cleaned address, sign_up with nothing,
 *   begin_checkout with the plan only. The calculators send nothing at all (TC-FR3-66-AS-04).
 *
 * Everything here is off until a measurement ID is set (LLD_GA4_ID in wp-config.php), so Stage 2 and CI pages
 * are unchanged unless a test sets one through the test route.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Google tag, Consent Mode and the banner.
 */
class LLD_Members_Analytics {

	const TEST_OPTION = 'lld_test_ga4_id';

	const CONSENT_COOKIE = 'lld_consent';

	const TAG = 'https://www.googletagmanager.com/gtag/js';

	/**
	 * Plugin screens that are public (FR3-66: pricing, programmes, calculator pages, safety notes), plus sign-up
	 * and checkout. Every other plugin screen is the member area and never gets the tag.
	 */
	const PUBLIC_SCREENS = array( 'pricing', 'programmes', 'sales', 'landing', 'safety', 'disclaimer', 'waitlist', 'quotes', 'join', 'checkout' );

	/**
	 * Events for the page being rendered.
	 *
	 * @var array<int,array{name:string,params:array<string,string>}>
	 */
	private static $queue = array();

	public static function boot() {
		if ( '' === self::measurement_id() ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_test_route' ) );
			return;
		}
		// TC-FR3-66-AS-03: with GA4 set, the Stage 2 Plausible script is never printed.
		remove_action( 'wp_footer', array( 'LLD_Members_Growth', 'footer_script' ) );
		add_filter( 'lld_members_plausible_on', '__return_false' );
		add_action( 'template_redirect', array( __CLASS__, 'note_page' ), 3 );
		add_action( 'lld_members_event', array( __CLASS__, 'on_event' ), 20, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'print_foot' ), 99 );
		add_action( 'lld_members_layout_foot', array( __CLASS__, 'print_foot' ) );
		add_filter( 'lld_members_footer_links', array( __CLASS__, 'footer_link' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_test_route' ) );
	}

	/**
	 * LLD_GA4_ID from wp-config.php. Test sites (ci, local) may set one through the test route instead.
	 */
	public static function measurement_id() {
		$id = defined( 'LLD_GA4_ID' ) ? (string) LLD_GA4_ID : '';
		if ( '' === $id && function_exists( 'lld_members_test_routes_open' ) && lld_members_test_routes_open() ) {
			$id = (string) get_option( self::TEST_OPTION, '' );
		}
		$id = strtoupper( trim( $id ) );
		return preg_match( '/^G-[A-Z0-9]{4,16}$/', $id ) ? $id : '';
	}

	/**
	 * True when this request may carry the Google tag: a public page, never the member area, an admin screen, a
	 * token link or a calculator file (the calculators under /tools/ are not WordPress pages and never get it).
	 */
	public static function page_measured() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, compared below.
		return self::path_measured( $path, (string) get_query_var( 'lld_screen' ) );
	}

	/**
	 * @param string $path   Request path.
	 * @param string $screen Plugin screen, or '' for an ordinary WordPress page.
	 */
	public static function path_measured( $path, $screen ) {
		if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|tools)(/|$)#', $path ) ) {
			return false;
		}
		if ( '' !== $screen ) {
			return in_array( $screen, self::PUBLIC_SCREENS, true );
		}
		// A Stage 3 member screen that is not a plugin screen still lives under /members/.
		return ! preg_match( '#^/members(/|$)#', $path ) && ! preg_match( '#^/guide/#', $path );
	}

	/**
	 * begin_checkout on the checkout step itself (not its cancel, back or already-Premium answers), and any
	 * sign_up the site recorded for this member since their last public page.
	 */
	public static function note_page() {
		if ( ! self::page_measured() ) {
			return;
		}
		if ( 'checkout' === get_query_var( 'lld_screen' ) && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! isset( $_GET['result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
			$plan = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : 'yearly'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
			self::queue( 'begin_checkout', array( 'plan' => 'monthly' === $plan ? 'monthly' : 'yearly' ) );
		}
		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id > 0 ) {
			$key     = 'lld_ga_events_' . $member_id;
			$pending = get_transient( $key );
			if ( is_array( $pending ) ) {
				foreach ( $pending as $event ) {
					if ( is_array( $event ) && isset( $event['name'] ) ) {
						self::queue( (string) $event['name'], isset( $event['params'] ) && is_array( $event['params'] ) ? $event['params'] : array() );
					}
				}
				delete_transient( $key );
			}
		}
	}

	/**
	 * A registration is kept for the member's next public page; their own browser sends it there, after consent.
	 *
	 * @param string $name      Site event.
	 * @param int    $member_id Member number.
	 */
	public static function on_event( $name, $member_id = 0 ) {
		$member_id = (int) $member_id;
		if ( 'registered' !== (string) $name || $member_id < 1 ) {
			return;
		}
		set_transient( 'lld_ga_events_' . $member_id, array( array( 'name' => 'sign_up', 'params' => array() ) ), DAY_IN_SECONDS );
	}

	/**
	 * Only these events and parameters ever reach Google (NFR3-16): no value is free text.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function allowlist() {
		return array(
			'sign_up'        => array(),
			'begin_checkout' => array( 'plan' => array( 'monthly', 'yearly' ) ),
		);
	}

	/**
	 * @param string               $name   Event.
	 * @param array<string,string> $params Parameters.
	 */
	public static function queue( $name, $params ) {
		$allow = self::allowlist();
		$name  = (string) $name;
		if ( ! isset( $allow[ $name ] ) || ! is_array( $params ) ) {
			return;
		}
		$clean = array();
		foreach ( $params as $key => $value ) {
			if ( ! isset( $allow[ $name ][ $key ] ) || ! in_array( (string) $value, $allow[ $name ][ $key ], true ) ) {
				return;
			}
			$clean[ (string) $key ] = (string) $value;
		}
		self::$queue[] = array(
			'name'   => $name,
			'params' => $clean,
		);
	}

	/**
	 * @param string $links Footer list items so far.
	 */
	public static function footer_link( $links ) {
		return (string) $links . '<li><a href="#cookie-settings" data-lld-cookie-settings>Cookie settings</a></li>';
	}

	public static function print_foot() {
		static $done = false;
		if ( $done || is_admin() ) {
			return;
		}
		$done = true;
		echo self::foot_html( self::page_measured() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts and hex-escaped JSON.
	}

	/**
	 * The banner, the footer link for theme pages, and the script. On a page that is not measured the banner can
	 * still be opened to change the choice, but no tag is ever loaded there.
	 *
	 * @param bool $measured This page may carry the tag.
	 */
	public static function foot_html( $measured ) {
		$id = self::measurement_id();
		if ( '' === $id ) {
			return '';
		}
		$config = wp_json_encode(
			array(
				'id'       => $id,
				'measured' => (bool) $measured,
				'events'   => $measured ? self::$queue : array(),
				'cookie'   => self::CONSENT_COOKIE,
				'tag'      => self::TAG,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		self::$queue = array();
		$policy      = home_url( '/privacy/' );
		$html        = '<style>' . self::css() . '</style>';
		$html       .= '<div id="lld-consent" class="lld-consent" role="region" aria-labelledby="lld-consent-title" hidden>';
		$html       .= '<p id="lld-consent-title" class="lld-consent-title"><strong>Cookies on Lifeline Daily</strong></p>';
		$html       .= '<p class="lld-consent-text">We\'d like to use Google Analytics cookies to count visits to our public pages, so we can see what helps people. They are never used in your account or on calculator results, and never carry health details. <a href="' . esc_url( $policy ) . '">Privacy policy</a></p>';
		$html       .= '<div class="lld-consent-actions"><button type="button" class="lld-consent-button" data-lld-consent="accept">Accept</button><button type="button" class="lld-consent-button" data-lld-consent="reject">Reject</button></div>';
		$html       .= '</div>';
		// Theme pages have their own footer; this keeps a way back to the choice on every page (TC-FR3-67-AS-04).
		$html .= '<p class="lld-consent-footer" data-lld-consent-footer><a href="#cookie-settings" data-lld-cookie-settings>Cookie settings</a></p>';
		$html .= '<script id="lld-ga4" type="application/json">' . $config . '</script><script>' . self::script() . '</script>';
		return $html;
	}

	/**
	 * Accept and Reject share one class, so they are the same size and weight (TC-FR3-67-AS-01).
	 */
	private static function css() {
		return '.lld-consent{position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:640px;margin:0 auto;padding:16px 20px;background:#fff;color:#1a2b3c;border:1px solid #c9d3dd;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.18);font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
			. '.lld-consent[hidden]{display:none}.lld-consent-title,.lld-consent-text{margin:0 0 12px}.lld-consent a{color:inherit;text-decoration:underline}'
			. '.lld-consent-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
			. '.lld-consent-button{min-height:44px;padding:10px 16px;border:2px solid #0b3d5c;border-radius:8px;background:#0b3d5c;color:#fff;font:inherit;font-weight:600;cursor:pointer}'
			. '.lld-consent-button:focus-visible{outline:3px solid #e8a33d;outline-offset:2px}'
			. '.lld-consent-footer{margin:0;padding:0 16px;text-align:center;font:14px/1.5 system-ui,sans-serif}.lld-consent-footer a{display:inline-flex;align-items:center;min-height:44px}.lld-members-body .lld-consent-footer{display:none}';
	}

	/**
	 * Consent Mode defaults to denied. The Google tag is fetched only after Accept and only on a measured page.
	 * Reject, or a change to Reject, removes Google's cookies; a page that had the tag reloads without it.
	 */
	public static function script() {
		return '(function(){var node=document.getElementById("lld-ga4");if(!node)return;var cfg;try{cfg=JSON.parse(node.textContent||"{}");}catch(e){return;}if(!cfg||!cfg.id)return;'
			. 'window.dataLayer=window.dataLayer||[];function gtag(){window.dataLayer.push(arguments);}'
			. 'gtag("consent","default",{analytics_storage:"denied",ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied"});'
			. 'var banner=document.getElementById("lld-consent"),started=false;'
			. 'function choice(){var m=document.cookie.match(new RegExp("(?:^|; )"+cfg.cookie+"=([^;]*)"));return m?m[1]:"";}'
			. 'function remember(v){document.cookie=cfg.cookie+"="+v+"; path=/; max-age=15552000; SameSite=Lax"+(location.protocol==="https:"?"; Secure":"");}'
			. 'function clean(href){var u=new URL(href,location.origin);var q=[];["utm_source","utm_medium","utm_campaign"].forEach(function(k){var v=u.searchParams.get(k);if(v&&/^[A-Za-z0-9_-]{1,64}$/.test(v))q.push(k+"="+encodeURIComponent(v));});return u.origin+u.pathname+(q.length?"?"+q.join("&"):"");}'
			. 'function referrer(){try{if(!document.referrer)return"";var r=new URL(document.referrer);return r.origin===location.origin&&/^\/members(\/|$)/.test(r.pathname)?r.origin+"/members/":r.origin+r.pathname;}catch(e){return"";}}'
			. 'function start(){if(started||!cfg.measured)return;started=true;gtag("consent","update",{analytics_storage:"granted"});gtag("js",new Date());'
			. 'var page=clean(location.href);gtag("config",cfg.id,{send_page_view:false,allow_google_signals:false,allow_ad_personalization_signals:false,page_location:page,page_referrer:referrer()});'
			. 'gtag("event","page_view",{page_location:page});(cfg.events||[]).forEach(function(e){if(e&&e.name)gtag("event",e.name,e.params||{});});'
			. 'var s=document.createElement("script");s.async=true;s.src=cfg.tag+"?id="+encodeURIComponent(cfg.id);document.head.appendChild(s);}'
			. 'function forget(){var host=location.hostname.split("."),names=document.cookie.split("; ").map(function(c){return c.split("=")[0];}).filter(function(n){return n==="_ga"||n.indexOf("_ga_")===0||n==="_gid";});'
			. 'names.forEach(function(n){document.cookie=n+"=; path=/; max-age=0";for(var i=0;i<host.length-1;i++){document.cookie=n+"=; path=/; max-age=0; domain=."+host.slice(i).join(".");}});}'
			. 'function show(){if(!banner)return;banner.hidden=false;var b=banner.querySelector("[data-lld-consent=accept]");if(b)b.focus();}'
			. 'function decide(v){remember(v);if(banner)banner.hidden=true;if(v==="granted"){start();return;}gtag("consent","update",{analytics_storage:"denied"});forget();if(started){location.reload();}}'
			. 'if(banner){banner.addEventListener("click",function(e){var t=e.target.closest("[data-lld-consent]");if(!t)return;decide(t.getAttribute("data-lld-consent")==="accept"?"granted":"denied");});}'
			. 'document.addEventListener("click",function(e){var a=e.target.closest("[data-lld-cookie-settings],a[href=\"#cookie-settings\"]");if(!a)return;e.preventDefault();show();});'
			. 'if(document.querySelector(".lld-footer [data-lld-cookie-settings]")){var f=document.querySelector("[data-lld-consent-footer]");if(f)f.hidden=true;}'
			. 'var now=choice();if(now==="granted"){start();}else if(now==="denied"){forget();}else{show();}'
			. '})();';
	}

	public static function register_test_route() {
		if ( ! function_exists( 'lld_members_test_routes_open' ) || ! lld_members_test_routes_open() ) {
			return;
		}
		register_rest_route(
			'lld-members/v1',
			'/test/analytics',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_test' ),
				'permission_callback' => array( 'LLD_Members_REST', 'test_only' ),
			)
		);
	}

	/**
	 * Test sites only: set or clear the measurement ID. The hooks are added on the next request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		$id     = is_array( $params ) && isset( $params['id'] ) ? (string) $params['id'] : '';
		if ( '' === $id ) {
			delete_option( self::TEST_OPTION );
		} else {
			update_option( self::TEST_OPTION, sanitize_text_field( $id ), false );
		}
		return new WP_REST_Response( array( 'id' => self::measurement_id() ), 200 );
	}
}
