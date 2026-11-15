<?php
/**
 * Barcode scanning in the browser (P3-44 · FR3-37): /members/food/scan/.
 *
 * The camera and the barcode reading run in the member's browser (assets/stage3.js: the browser's own BarcodeDetector
 * where there is one, otherwise the script's EAN and UPC reader), and the product is found through the members' API
 * (GET /me/food-barcode). Found: the product and its nutrition, ready to log (AS-01). Not found: "Add this product"
 * saves it as the member's own food with its barcode (AS-02). Camera refused or missing: typing the number and the food
 * search still work, with a short reason (AS-03). The page itself works without a camera.
 *
 * Behind the `barcode` and `food_tracker` switches; the address exists only while `barcode` is on, so with the switch
 * off nothing changes (NFR3-06). Barcode scanning is Premium (FR3-63): a Free member sees the upgrade card and still
 * has the typed food search.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan page controller and renderer.
 */
class LLD_Members_Barcode_Scan {

	const HANDLE = 'lld-members-stage3';

	/** Rewrite rule version; a change flushes the rules once. */
	const REWRITE_VERSION = 'p3-44-1';

	const OPTION_REWRITE = 'lld_members_stage3_scan_rewrite';

	/**
	 * Called by the Stage 3 screens loader. Hooks nothing while the switch is off for everyone.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'barcode' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * The scan address. Registered here, not in the Stage 2 plugin class; flushed once per version.
	 */
	public static function rewrites() {
		add_rewrite_rule( '^members/food/scan/?$', 'index.php?lld_screen=food-scan', 'top' );
		$rules = get_option( 'rewrite_rules' );
		// Flushed once per version, and again if another flush dropped the rule (the switch was off at the time).
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VERSION || ( is_array( $rules ) && ! isset( $rules['^members/food/scan/?$'] ) ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VERSION, true );
		}
	}

	/**
	 * Renders /members/food/scan/ for a signed-in member both switches apply to.
	 */
	public static function maybe_render() {
		if ( 'food-scan' !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$signed_in = is_user_logged_in() && class_exists( 'LLD_Members_Access' ) && LLD_Members_Access::current_member_id() > 0;
		$member_id = $signed_in ? (int) LLD_Members_Access::current_member_id() : 0;
		// Where the screen does not apply, the address does not exist: a 404 for everyone, signed in or not (NFR3-06).
		if ( ! LLD_Members_Features::enabled( 'barcode', $member_id ) || ! LLD_Members_Features::enabled( 'food_tracker', $member_id ) || ( $signed_in && '' !== LLD_Members_Access::preview_state() ) ) {
			self::not_found();
			return;
		}
		if ( ! $signed_in ) {
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/food/scan/' ) ) ) );
			exit;
		}
		self::render( $member_id );
		exit;
	}

	/**
	 * The WordPress "not found" page.
	 */
	private static function not_found() {
		global $wp_query;
		if ( isset( $wp_query ) && is_object( $wp_query ) ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * The page.
	 *
	 * @param int $member_id Member number.
	 */
	public static function render( $member_id ) {
		$premium = ! class_exists( 'LLD_Members_Access_Tiers' ) || LLD_Members_Access_Tiers::allows( $member_id, 'barcode' );
		$consent = LLD_Members_Access::has_health_consent( $member_id );
		$today   = class_exists( 'LLD_Members_Programme' ) ? (string) LLD_Members_Programme::local_today( $member_id ) : gmdate( 'Y-m-d' );
		$words   = LLD_Members_Barcode::MESSAGES;
		$words['consent'] = 'Adding food to your diary needs your health consent. You can give it in Privacy & consents.';
		$words['error']   = 'Something went wrong. Please try again.';
		// The safety matrix hides calories for some members (FR3-69, 'barcode_calories'): the page then shows no
		// nutrition for any product it opens, scanned or searched, and "Add this product" asks no calories.
		$flags  = class_exists( 'LLD_Members_Stage3_Safety' ) ? LLD_Members_Stage3_Safety::nutrition_hidden( LLD_Members_Stage3_Safety::applies( $member_id, 'food_tracker' ) ) : array( 'all' => false, 'protein' => false );
		$hidden = $flags['all'];

		$html  = '<div class="lld-stage3-scan" data-lld-scan data-lld-premium="' . ( $premium ? 'yes' : 'no' ) . '" data-lld-consent="' . ( $consent ? 'yes' : 'no' ) . '" data-lld-hide-nutrition="' . ( $hidden ? 'yes' : 'no' ) . '" data-lld-hide-protein="' . ( $flags['protein'] ? 'yes' : 'no' ) . '"';
		$html .= ' data-lld-api="' . esc_url( rest_url( 'lld-members/v1/me/' ) ) . '" data-lld-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
		$html .= ' data-lld-today="' . esc_attr( $today ) . '" data-lld-words="' . esc_attr( (string) wp_json_encode( $words ) ) . '">';
		$html .= '<h1>Scan a barcode</h1>';

		if ( $premium ) {
			$html .= '<p class="lld-hint">Point your phone\'s camera at the barcode on a packaged food. It works best in good light, with the barcode filling the frame.</p>';
			$html .= '<section class="lld-card" data-lld-section="camera"><h2>Camera</h2>';
			$html .= '<div class="lld-scan-view" data-lld-scan-view hidden><video data-lld-scan-video playsinline muted autoplay aria-label="Camera view"></video><span class="lld-scan-frame" aria-hidden="true"></span></div>';
			$html .= '<p class="lld-scan-actions"><button type="button" class="lld-button lld-button-primary" data-lld-scan-start>Scan with the camera</button> ';
			$html .= '<button type="button" class="lld-button lld-button-secondary" data-lld-scan-stop hidden>Stop the camera</button></p>';
			$html .= '<p class="lld-scan-status" data-lld-scan-status role="status" aria-live="polite"></p></section>';

			$html .= '<section class="lld-card" data-lld-section="typed-barcode"><h2>Type the barcode</h2>';
			$html .= '<form class="lld-scan-form" data-lld-barcode-form novalidate><label for="lld-barcode-number">Barcode number</label>';
			$html .= '<span class="lld-hint" id="lld-barcode-help">The numbers under the bars, 8 to 14 digits.</span>';
			$html .= '<span class="lld-scan-row"><input id="lld-barcode-number" name="code" type="text" inputmode="numeric" autocomplete="off" maxlength="20" aria-describedby="lld-barcode-help">';
			$html .= '<button type="submit" class="lld-button lld-button-secondary">Find</button></span></form></section>';

			$html .= '<section class="lld-card lld-scan-result" data-lld-section="result" data-lld-scan-result tabindex="-1" hidden></section>';
		} else {
			$html .= lld_membership_lock( LLD_Members_Access_Tiers::PREMIUM['barcode'] );
		}

		// Typed search works for every member and without a camera (AS-03).
		$html .= '<section class="lld-card" data-lld-section="search"><h2>Search for a food</h2>';
		$html .= '<form class="lld-scan-form" data-lld-food-search-form role="search"><label for="lld-food-query">Food name</label>';
		$html .= '<span class="lld-scan-row"><input id="lld-food-query" name="q" type="search" autocomplete="off" maxlength="80">';
		$html .= '<button type="submit" class="lld-button lld-button-secondary">Search</button></span></form>';
		$html .= '<p class="lld-scan-status" data-lld-search-status role="status" aria-live="polite"></p>';
		$html .= '<ul class="lld-scan-hits" data-lld-food-results></ul></section>';
		if ( ! $premium ) {
			$html .= '<section class="lld-card lld-scan-result" data-lld-section="result" data-lld-scan-result tabindex="-1" hidden></section>';
		}

		$html .= '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Back to Today</a></p></div>';
		self::output( 'Scan a barcode', $html );
	}

	/**
	 * Print the page with the Stage 3 stylesheet and script (the Stage 2 layout has no wp_head).
	 *
	 * @param string $title Title.
	 * @param string $main  Main HTML.
	 */
	private static function output( $title, $main ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		ob_start();
		lld_members_layout( array( 'title' => $title, 'main' => $main, 'signed_in' => true ) );
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
}
