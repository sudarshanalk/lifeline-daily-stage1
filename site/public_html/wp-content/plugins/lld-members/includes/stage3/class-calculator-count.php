<?php
/**
 * Completed calculations, counted on the site itself (P3-72, FR3-70; owner answer 86, 3 Nov 2026).
 *
 * Google Analytics only sees calculator page views, never a result (TC-FR3-66-AS-04). The Monday report also wants
 * how often each calculator was completed, so the landing page adds one to a weekly total when its calculator
 * shows a result: once per calculator per page visit, as the Stage 2 count did.
 *
 * What is kept: a number per calculator per week. Nothing else: not the answers, not the result, not who it was,
 * no cookie, and the request is sent without cookies. Nothing here goes to Google (NFR3-16).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly totals of completed calculations.
 */
class LLD_Members_Calculator_Count {

	const OPTION = 'lld_calculator_counts';

	const WEEKS_KEPT = 12;

	/**
	 * The slug each calculator sends (tools/lld-members-link.js) and its name in the report.
	 */
	const CALCULATORS = array(
		'protein'        => 'Protein calculator',
		'diabetes'       => 'Diabetes Risk Check',
		'healthy-weight' => 'Healthy Weight Check',
	);

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		// The plugin's own landing screen (CI) prints through its layout; a published Stage 1 page at the same address
		// (staging, production) is a theme page and prints through wp_footer.
		add_action( 'lld_members_layout_foot', array( __CLASS__, 'print_script' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ), 99 );
	}

	public static function register() {
		register_rest_route(
			'lld-members/v1',
			'/calculator-count',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_count' ),
				'permission_callback' => '__return_true',
			)
		);
		if ( function_exists( 'lld_members_test_routes_open' ) && lld_members_test_routes_open() ) {
			register_rest_route(
				'lld-members/v1',
				'/test/calculator-count',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_test' ),
					'permission_callback' => array( 'LLD_Members_REST', 'test_only' ),
				)
			);
		}
	}

	/**
	 * One completion. Only a request from the site's own pages counts; the body may hold the calculator name and
	 * nothing else is read.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_count( $request ) {
		if ( ! self::same_site( (string) $request->get_header( 'origin' ), (string) $request->get_header( 'referer' ) ) ) {
			return new WP_REST_Response( array( 'counted' => false ), 403 );
		}
		$params = $request->get_json_params();
		$which  = is_array( $params ) && isset( $params['calculator'] ) ? (string) $params['calculator'] : '';
		return new WP_REST_Response( array( 'counted' => self::add( $which ) ), 200 );
	}

	/**
	 * @param string $origin  Origin header.
	 * @param string $referer Referer header, used when a browser sends no Origin.
	 */
	public static function same_site( $origin, $referer ) {
		$home = wp_parse_url( home_url( '/' ) );
		$from = wp_parse_url( '' !== $origin ? $origin : $referer );
		if ( ! is_array( $home ) || ! is_array( $from ) || empty( $from['host'] ) ) {
			return false;
		}
		return strtolower( (string) $from['host'] ) === strtolower( (string) ( $home['host'] ?? '' ) )
			&& ( $from['scheme'] ?? '' ) === ( $home['scheme'] ?? '' )
			&& (int) ( $from['port'] ?? 0 ) === (int) ( $home['port'] ?? 0 );
	}

	/**
	 * @param string $which Calculator slug.
	 * @param string $now   Optional date (Y-m-d) for tests.
	 */
	public static function add( $which, $now = '' ) {
		if ( ! isset( self::CALCULATORS[ $which ] ) ) {
			return false;
		}
		$week   = self::week_of( '' !== $now ? $now : wp_date( 'Y-m-d' ) );
		$counts = get_option( self::OPTION );
		$counts = is_array( $counts ) ? $counts : array();
		$counts[ $week ][ $which ] = (int) ( $counts[ $week ][ $which ] ?? 0 ) + 1;
		krsort( $counts );
		update_option( self::OPTION, array_slice( $counts, 0, self::WEEKS_KEPT, true ), false );
		return true;
	}

	/**
	 * Completions of the week that starts on this Monday.
	 *
	 * @param string $monday Y-m-d.
	 * @return array<string,int> Every calculator, 0 when none.
	 */
	public static function week( $monday ) {
		$counts = get_option( self::OPTION );
		$row    = is_array( $counts ) && isset( $counts[ $monday ] ) && is_array( $counts[ $monday ] ) ? $counts[ $monday ] : array();
		$out    = array();
		foreach ( array_keys( self::CALCULATORS ) as $which ) {
			$out[ $which ] = (int) ( $row[ $which ] ?? 0 );
		}
		return $out;
	}

	/**
	 * @param string $day Y-m-d.
	 */
	private static function week_of( $day ) {
		return ( new DateTimeImmutable( $day, wp_timezone() ) )->modify( 'monday this week' )->format( 'Y-m-d' );
	}

	/**
	 * Only on the calculator landing pages. The message also carries the result headline; the script reads the
	 * calculator name alone, and sends no cookie.
	 */
	public static function print_script() {
		static $done = false;
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, compared below.
		if ( $done || is_admin() || ! self::landing( $path, (string) get_query_var( 'lld_screen' ) ) ) {
			return;
		}
		$done = true;
		echo '<script>' . self::script( rest_url( 'lld-members/v1/calculator-count' ) ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the address is JSON-encoded.
	}

	/**
	 * A calculator landing page: the plugin's landing screen, or the Stage 1 page at one of the three addresses.
	 *
	 * @param string $path   Request path.
	 * @param string $screen Plugin screen, or ''.
	 */
	public static function landing( $path, $screen ) {
		return 'landing' === $screen || 1 === preg_match( '#^/health-tools/(protein|diabetes-risk-check|healthy-weight-check)/?$#', $path );
	}

	/**
	 * @param string $url Count address.
	 */
	public static function script( $url ) {
		return '(function(){var url=' . wp_json_encode( (string) $url ) . ',known=' . wp_json_encode( array_keys( self::CALCULATORS ) ) . ',done={};'
			. 'window.addEventListener("message",function(e){if(e.origin!==location.origin)return;var d=e.data||{};if(d.type!=="lld-calculator-result")return;'
			. 'var which=known.indexOf(d.calculator)>=0?d.calculator:"";if(!which||done[which])return;done[which]=1;'
			. 'try{fetch(url,{method:"POST",credentials:"omit",keepalive:true,headers:{"Content-Type":"application/json"},body:JSON.stringify({calculator:which})}).catch(function(){});}catch(x){}});'
			. '})();';
	}

	/**
	 * Test sites only: read a week's totals, or clear them.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) && ! empty( $params['reset'] ) ) {
			delete_option( self::OPTION );
		}
		$monday = self::week_of( wp_date( 'Y-m-d' ) );
		return new WP_REST_Response(
			array(
				'week'   => $monday,
				'counts' => self::week( $monday ),
			),
			200
		);
	}
}
