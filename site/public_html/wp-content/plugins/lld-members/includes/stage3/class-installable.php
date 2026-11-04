<?php
/**
 * Add to home screen / installable website (P3-85, FR3-58). Serves the web app manifest, a small
 * service worker and an offline page under /members/. Screens (Antigravity) link the manifest.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manifest, service worker and offline fallback.
 */
class LLD_Members_Installable {

	const MANIFEST_PATH = '/members/app.webmanifest';
	const SW_PATH       = '/members/sw.js';
	const OFFLINE_PATH  = '/members/offline/';

	/** Offline page line — friendly, no health details. */
	const MESSAGES = array(
		'offline.title'   => 'You are offline',
		'offline.body'    => 'This page needs a connection. Check your network and try again.',
		'offline.heading' => 'You are offline',
		'name'            => 'Lifeline Daily',
		'short_name'      => 'Lifeline',
		'description'     => 'Your Lifeline Daily member area',
	);

	public static function boot() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'link_tags' ), 5 );
	}

	/**
	 * Manifest + theme-color links on member pages (screens can also add their own).
	 */
	public static function link_tags() {
		if ( ! self::is_members_request() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( home_url( self::MANIFEST_PATH ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="#0B6E4F">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icon_url( 192 ) ) . '">' . "\n";
	}

	/**
	 * Serve manifest / SW / offline when the path matches.
	 */
	public static function serve() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		$uri = untrailingslashit( $uri ) . ( substr( $uri, -1 ) === '/' && false !== strpos( $uri, 'offline' ) ? '/' : '' );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = '/' === $home_path ? '' : untrailingslashit( $home_path );
		$path      = $uri;
		if ( '' !== $home_path && 0 === strpos( $uri, $home_path ) ) {
			$path = substr( $uri, strlen( $home_path ) ) ?: '/';
		}
		if ( self::MANIFEST_PATH === $path || self::MANIFEST_PATH === untrailingslashit( $path ) ) {
			self::send_json( self::manifest() );
		}
		if ( self::SW_PATH === $path || self::SW_PATH === untrailingslashit( $path ) ) {
			self::send_js( self::service_worker() );
		}
		$offline = untrailingslashit( self::OFFLINE_PATH );
		if ( $offline === untrailingslashit( $path ) || self::OFFLINE_PATH === $path ) {
			self::send_offline_html();
		}
	}

	/**
	 * Web app manifest (FR3-58 AS-01).
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest() {
		return array(
			'name'             => self::MESSAGES['name'],
			'short_name'       => self::MESSAGES['short_name'],
			'description'      => self::MESSAGES['description'],
			'start_url'        => home_url( '/members/home/' ),
			'scope'            => home_url( '/members/' ),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#0B6E4F',
			'icons'            => array(
				array(
					'src'   => self::icon_url( 192 ),
					'sizes' => '192x192',
					'type'  => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'   => self::icon_url( 512 ),
					'sizes' => '512x512',
					'type'  => 'image/png',
					'purpose' => 'any maskable',
				),
			),
		);
	}

	/**
	 * Service worker source: precache offline page; network-first for members pages.
	 */
	public static function service_worker() {
		$offline = home_url( self::OFFLINE_PATH );
		$scope   = home_url( '/members/' );
		$offline_js = wp_json_encode( $offline );
		$scope_js   = wp_json_encode( $scope );
		return <<<JS
/* Lifeline Daily member service worker (P3-85, FR3-58). */
const OFFLINE = {$offline_js};
const SCOPE = {$scope_js};
const CACHE = 'lld-members-v2';
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', (event) => {
  const req = event.request;
  // Only member page loads fall back to the offline page; images, scripts and data fail as normal.
  if (req.method !== 'GET' || req.mode !== 'navigate' || !req.url.startsWith(SCOPE)) return;
  event.respondWith(
    fetch(req).then((res) => res).catch(() => caches.match(OFFLINE).then((r) => r || new Response('You are offline', { status: 503, headers: { 'Content-Type': 'text/plain' } })))
  );
});
JS;
	}

	/**
	 * Whether the current request is under /members/.
	 */
	private static function is_members_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return false !== strpos( $uri, '/members/' );
	}

	/**
	 * Icon URL: prefer a plugin asset if present, else a data-safe public placeholder path.
	 *
	 * @param int $size Pixel size.
	 */
	private static function icon_url( $size ) {
		$file = 'lld-icon-' . (int) $size . '.png';
		$path = defined( 'LLD_MEMBERS_DIR' ) ? LLD_MEMBERS_DIR . 'assets/' . $file : '';
		if ( $path && file_exists( $path ) ) {
			return plugins_url( 'assets/' . $file, LLD_MEMBERS_DIR . 'lld-members.php' );
		}
		// Fallback: site icon or a 1×1 is not installable; use a stable members path the theme may map.
		return home_url( '/members/icon-' . (int) $size . '.png' );
	}

	/**
	 * @param array<string,mixed> $data Manifest.
	 */
	private static function send_json( array $data ) {
		// The paths have no rewrite rule, so WordPress has already marked the request 404.
		status_header( 200 );
		self::no_cache();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $data );
		exit;
	}

	/**
	 * @param string $js Service worker source.
	 */
	private static function send_js( $js ) {
		// The paths have no rewrite rule, so WordPress has already marked the request 404.
		status_header( 200 );
		self::no_cache();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . (string) wp_parse_url( home_url( '/members/' ), PHP_URL_PATH ) );
		echo $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JS source.
		exit;
	}

	/**
	 * No browser or LiteSpeed page caching: a cached copy would outlive a fix or a new service worker.
	 */
	private static function no_cache() {
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	private static function send_offline_html() {
		// The paths have no rewrite rule, so WordPress has already marked the request 404.
		status_header( 200 );
		self::no_cache();
		header( 'Content-Type: text/html; charset=utf-8' );
		$title = esc_html( self::MESSAGES['offline.heading'] );
		$body  = esc_html( self::MESSAGES['offline.body'] );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
			. $title . '</title></head><body><main><h1>' . $title . '</h1><p>' . $body . '</p></main></body></html>';
		exit;
	}
}
