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
		if ( self::outage_forced() ) {
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
	/**
	 * Cancel the member's subscription at Stripe when their account is deleted (FR2-04).
	 *
	 * No Stripe call is made yet: that comes with the Stripe package. Until then, any open Stripe
	 * subscription returns an error, so the member is never told billing stopped and the owner is
	 * alerted with the reference to cancel it by hand. A non-production option forces the failure.
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error Error data holds the Stripe subscription and customer references.
	 */
	public static function cancel_member( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$row       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
		$ref       = $row && ! empty( $row->stripe_subscription ) ? (string) $row->stripe_subscription : '';
		$customer  = $row && ! empty( $row->stripe_customer ) ? (string) $row->stripe_customer : '';
		$ended     = $row && in_array( strtolower( (string) $row->stripe_state ), array( 'cancelled', 'canceled', 'incomplete_expired' ), true );
		$forced    = ! lld_members_is_production() && get_option( 'lld_members_stripe_cancel_fail' );
		$open      = '' !== $ref && ! $ended;
		if ( $forced || $open ) {
			$why = $forced ? 'forced' : 'not_connected';
			update_option(
				'lld_members_last_cancel',
				array(
					'member_id'    => $member_id,
					'ok'           => false,
					'why'          => $why,
					'subscription' => $ref,
				),
				false
			);
			return new WP_Error(
				'lld_stripe',
				$forced ? 'cancel failed' : 'Stripe cancellation is not connected yet.',
				array(
					'subscription' => $ref,
					'customer'     => $customer,
				)
			);
		}
		update_option(
			'lld_members_last_cancel',
			array(
				'member_id'    => $member_id,
				'ok'           => true,
				'why'          => '' === $ref ? 'no_subscription' : 'already_ended',
				'subscription' => $ref,
			),
			false
		);
		return true;
	}

	public static function try_again_message() {
		return 'Please try again shortly.';
	}

	/**
	 * The test option is ignored in production, even when it is set.
	 */
	public static function outage_forced() {
		if ( lld_members_is_production() ) {
			return false;
		}
		return (bool) get_option( 'lld_members_stripe_unreachable' );
	}
}
