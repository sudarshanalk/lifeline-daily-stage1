<?php
/**
 * Stripe reachability. Access never depends on a live Stripe call (Data Model T4).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout asks whether Stripe can be reached. Membership state is read from our table.
 */
class LLD_Members_Stripe {

	/**
	 * True when Stripe can be contacted. A non-production option forces the failure path for tests.
	 *
	 * @return true|WP_Error
	 */
	public static function reachable() {
		if ( ! lld_members_is_production() && get_option( 'lld_members_stripe_unreachable' ) ) {
			return new WP_Error( 'lld_stripe', 'unreachable' );
		}
		$response = wp_remote_head(
			'https://api.stripe.com/',
			array(
				'timeout'     => 3,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 || 0 === $code ) {
			return new WP_Error( 'lld_stripe', 'unreachable' );
		}
		return true;
	}

	/**
	 * The sentence shown when a new checkout cannot reach Stripe.
	 */
	public static function try_again_message() {
		return 'Please try again shortly.';
	}
}
