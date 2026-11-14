<?php
/**
 * Terms before the member area (P3-36 A6; FR3-113).
 *
 * A confirmed member who has not accepted the terms is taken to Agreements before any member page, and after
 * accepting is returned to the page they were going to (TC-FR3-113-AS-01 to AS-04). Calculators, articles and
 * Help stay open: they are public pages, and /members/help/ is left out below. Sign-in, the email steps,
 * Agreements itself and the privacy pages (download, withdraw, delete, unsubscribe) stay open too, so a member can
 * always leave or take their data.
 *
 * Behind the terms_first switch, on for everyone on staging. Stage 2's own journey is unchanged: its test members are
 * created without accepting the terms and then use member pages, and a new member still goes from Agreements to the
 * goal step (FR2-03 AS-01), because only a member sent here by this gate carries a page to return to.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The gate in front of member pages.
 */
class LLD_Members_Terms_Gate {

	const FEATURE = 'terms_first';

	/** The query argument on /members/agreements/ that carries the page the member was going to. */
	const ARG = 'redirect_to';

	/**
	 * First path part after /members/ that stays open without the terms. 'admin' is the helpers' panel, which has
	 * its own sign-in rules; 'more' takes the sign-out form (C1), so a member can always sign out.
	 */
	const OPEN = array( 'join', 'confirm', 'reset', 'agreements', 'sign-in', 'sign-out', 'two-step', 'email', 'unsubscribe', 'data', 'withdraw', 'delete', 'help', 'more', 'admin' );

	public static function boot() {
		// Before the screens are dispatched (LLD_Members_Screens::dispatch runs at 10).
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 1 );
		add_filter( 'wp_redirect', array( __CLASS__, 'after_accepting' ), 10, 1 );
	}

	/**
	 * The member this gate applies to now, or 0: signed in, a member record, email confirmed, the switch on for them,
	 * not the owner or a helper (they run the site), and no accepted terms.
	 */
	public static function member_needing_terms() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return 0;
		}
		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id < 1 || ! class_exists( 'LLD_Members_Features' ) || ! LLD_Members_Features::enabled( self::FEATURE, $member_id ) ) {
			return 0;
		}
		global $wpdb;
		$confirmed = $wpdb->get_var( $wpdb->prepare( 'SELECT email_confirmed_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! $confirmed ) {
			return 0; // Stage 2 sends an unconfirmed member to the email step first.
		}
		return LLD_Members_Account::has_consent( $member_id, 'terms' ) ? 0 : $member_id;
	}

	/**
	 * True when this path (no host) is a member page the gate covers.
	 *
	 * @param string $path Request path, for example /members/tracks/.
	 */
	public static function covers( $path ) {
		$path = '/' . trim( (string) $path, '/' ) . '/';
		if ( 0 !== strpos( $path, '/members/' ) ) {
			return false;
		}
		$rest  = substr( $path, strlen( '/members/' ) );
		$first = (string) strtok( $rest, '/' );
		return '' === $first || ! in_array( $first, self::OPEN, true );
	}

	/**
	 * The path of this site's address, with its query, when it is a member page the gate covers; '' otherwise. Used
	 * for the page to return to, so it can never lead off the site.
	 *
	 * @param string $target Address or path.
	 */
	public static function safe_target( $target ) {
		$target = (string) $target;
		if ( '' === $target || preg_match( '#[\\\\\s]#', $target ) || 0 === strpos( $target, '//' ) ) {
			return '';
		}
		$parts = wp_parse_url( $target );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) || isset( $parts['scheme'] ) ) {
			$home = wp_parse_url( home_url( '/' ) );
			if ( ! isset( $parts['host'] ) || ! is_array( $home ) || strtolower( (string) $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
				return '';
			}
		}
		if ( ! self::covers( $parts['path'] ) ) {
			return '';
		}
		return $parts['path'] . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	/**
	 * Action: a member without accepted terms who opens a member page goes to Agreements, carrying that page.
	 */
	public static function gate() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! self::covers( $path ) || ! self::member_needing_terms() ) {
			return;
		}
		$back = self::safe_target( $uri );
		$to   = home_url( '/members/agreements/' );
		if ( '' !== $back ) {
			$to = add_query_arg( self::ARG, rawurlencode( $back ), $to );
		}
		nocache_headers();
		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Filter: Stage 2's Agreements step sends the member on to the goal step once the terms are accepted. A member
	 * this gate sent there goes back to the page they were going to instead (TC-FR3-113-AS-04).
	 *
	 * @param string $location Where Stage 2 redirects.
	 */
	public static function after_accepting( $location ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'agreements' !== (string) get_query_var( 'lld_screen' ) || empty( $_GET[ self::ARG ] ) ) {
			return $location;
		}
		if ( untrailingslashit( (string) $location ) !== untrailingslashit( home_url( '/members/goals/' ) ) ) {
			return $location; // only the "accepted" redirect is changed
		}
		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id < 1 || ! LLD_Members_Account::has_consent( $member_id, 'terms' ) ) {
			return $location;
		}
		$back = self::safe_target( (string) wp_unslash( $_GET[ self::ARG ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- safe_target() keeps only a member page on this site.
		return '' !== $back ? home_url( $back ) : $location;
	}
}
