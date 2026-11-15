<?php
/**
 * Bare /members/ (staging review m3, P3-36): sends the visitor to /members/home/ instead of "page not found". Today's
 * own Stage 2 gate then sends a signed-out visitor to sign-in. Kept in Stage 3 so Stage 2's rewrite rules stay as built.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect for the member area's root address.
 */
class LLD_Members_Members_Root {

	public static function boot() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 0 );
	}

	/**
	 * True when the request path is exactly /members or /members/ under the site's home path.
	 *
	 * @param string $uri  Request URI.
	 * @param string $home Path of home_url(), such as '' or '/blog'.
	 */
	public static function is_root( $uri, $home = '' ) {
		$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		$home = rtrim( (string) $home, '/' );
		if ( '' !== $home ) {
			if ( 0 !== strpos( $path, $home . '/' ) ) {
				return false;
			}
			$path = substr( $path, strlen( $home ) );
		}
		return '/members' === rtrim( $path, '/' );
	}

	public static function redirect() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared, never printed
		if ( ! self::is_root( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ) {
			return;
		}
		nocache_headers();
		wp_safe_redirect( home_url( '/members/home/' ), 302 );
		exit;
	}
}
