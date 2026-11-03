<?php
/**
 * D2 / P3-60: the real Stripe connection, behind the `stripe_api` switch.
 *
 * With the switch off (or no secret key) the Stage 2 billing code keeps its own simulated sessions, so every Stage 2
 * test runs as before. With it on, checkout opens a real Stripe Checkout Session with Managed Payments (Stripe is the
 * merchant of record), prices come from Stripe by lookup key, and cancellation, account deletion and refunds call Stripe.
 *
 * Keys are read only through LLD_Members_Billing::stripe_key(); no key, card or health detail is ever logged or shown.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe programming interface for checkout, cancellation and refunds.
 */
class LLD_Members_Stripe_API {

	/**
	 * Pinned API version. Webhook notices arrive in the account's own version; the billing code reads both shapes.
	 */
	const VERSION = '2026-10-28.endive';

	const BASE = 'https://api.stripe.com/v1/';

	/**
	 * Stripe price lookup keys, set on the product in the owner's Stripe account (sandbox and live).
	 */
	const LOOKUP = array(
		'monthly' => 'lld_premium_monthly',
		'yearly'  => 'lld_premium_yearly',
	);

	public static function boot() {
		add_filter( 'lld_members_plan_price', array( __CLASS__, 'plan_price' ), 10, 3 );
		add_filter( 'lld_members_plan_currency', array( __CLASS__, 'plan_currency' ), 10, 3 );
		add_filter( 'lld_members_checkout_session', array( __CLASS__, 'checkout_session' ), 10, 4 );
		add_filter( 'lld_members_stripe_cancel', array( __CLASS__, 'cancel_at_period_end' ), 10, 3 );
		add_filter( 'lld_members_stripe_cancel_member', array( __CLASS__, 'cancel_member' ), 10, 3 );
		add_filter( 'lld_members_stripe_refund', array( __CLASS__, 'refund' ), 10, 2 );
		add_filter( 'lld_members_payment_ref', array( __CLASS__, 'payment_ref' ), 10, 2 );
		add_filter( 'lld_members_can_switch_yearly', array( __CLASS__, 'can_switch_yearly' ), 10, 2 );
	}

	/**
	 * True when this member's billing goes to the real Stripe: the switch is on for them and a usable secret key is set.
	 *
	 * @param int $member_id Member number; 0 for the current member.
	 */
	public static function on( $member_id = 0 ) {
		if ( ! LLD_Members_Features::enabled( 'stripe_api', (int) $member_id ) ) {
			return false;
		}
		$key = LLD_Members_Billing::stripe_key( 'secret' );
		return is_string( $key ) && '' !== $key;
	}

	/**
	 * Test or live, from the key prefix. Never returns the key.
	 */
	public static function mode() {
		$key = LLD_Members_Billing::stripe_key( 'secret' );
		if ( is_wp_error( $key ) ) {
			return 'refused';
		}
		if ( '' === $key ) {
			return 'none';
		}
		return 0 === strpos( $key, 'sk_live_' ) || 0 === strpos( $key, 'rk_live_' ) ? 'live' : 'test';
	}

	/**
	 * One call to Stripe.
	 *
	 * @param string              $method      GET, POST or DELETE.
	 * @param string              $path        Path under /v1/, such as "checkout/sessions".
	 * @param array<string,mixed> $params      Form fields (POST) or query (GET, DELETE).
	 * @param string              $idempotency Idempotency key for a POST that must not happen twice.
	 * @return array<string,mixed>|WP_Error Decoded object, or an error whose message is Stripe's own (never the key).
	 */
	public static function request( $method, $path, $params = array(), $idempotency = '' ) {
		$key = LLD_Members_Billing::stripe_key( 'secret' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( '' === $key ) {
			return new WP_Error( 'lld_stripe', 'Stripe is not connected.' );
		}
		$url     = self::BASE . ltrim( $path, '/' );
		$query   = $params ? self::encode( $params ) : '';
		$headers = array(
			'Authorization'  => 'Bearer ' . $key,
			'Stripe-Version' => self::VERSION,
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = $query;
			if ( '' !== $idempotency ) {
				$args['headers']['Idempotency-Key'] = $idempotency;
			}
		} elseif ( '' !== $query ) {
			$url .= '?' . $query;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'lld_stripe', 'unreachable' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Stripe answered ' . $code . '.';
			return new WP_Error(
				'lld_stripe',
				sanitize_text_field( $message ),
				array(
					'status' => $code,
					'type'   => is_array( $body ) && isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '',
				)
			);
		}
		return $body;
	}

	/**
	 * Stripe's form encoding: nested arrays as a[b][0][c]=v.
	 *
	 * @param array<string,mixed> $params Fields.
	 * @param string              $prefix Parent key.
	 */
	public static function encode( $params, $prefix = '' ) {
		$parts = array();
		foreach ( $params as $name => $value ) {
			$key = '' === $prefix ? (string) $name : $prefix . '[' . $name . ']';
			if ( is_array( $value ) ) {
				$inner = self::encode( $value, $key );
				if ( '' !== $inner ) {
					$parts[] = $inner;
				}
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$parts[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * The Stripe price for a plan, found by lookup key and kept for an hour.
	 *
	 * @param string $plan monthly or yearly.
	 * @return array{id:string,amount:int,currency:string,tax_behavior:string}|WP_Error
	 */
	public static function price( $plan ) {
		$plan = 'yearly' === $plan ? 'yearly' : 'monthly';
		$mode = self::mode();
		$keep = 'lld_stripe_price_' . $mode . '_' . $plan;
		$hit  = get_transient( $keep );
		if ( is_array( $hit ) && ! empty( $hit['id'] ) ) {
			return $hit;
		}
		$found = self::request(
			'GET',
			'prices',
			array(
				'lookup_keys' => array( self::LOOKUP[ $plan ] ),
				'active'      => 'true',
				'limit'       => 1,
			)
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$row = isset( $found['data'][0] ) && is_array( $found['data'][0] ) ? $found['data'][0] : null;
		if ( ! $row || empty( $row['id'] ) || ! isset( $row['unit_amount'] ) ) {
			return new WP_Error( 'lld_stripe', 'No active Stripe price has the lookup key ' . self::LOOKUP[ $plan ] . '.' );
		}
		$price = array(
			'id'           => (string) $row['id'],
			'amount'       => (int) $row['unit_amount'],
			'currency'     => strtolower( (string) $row['currency'] ),
			'tax_behavior' => isset( $row['tax_behavior'] ) ? (string) $row['tax_behavior'] : 'unspecified',
		);
		set_transient( $keep, $price, HOUR_IN_SECONDS );
		return $price;
	}

	/**
	 * Filter: the catalogue price shown and charged is Stripe's own, so the two can never differ (TC-FR3-62-AS-02).
	 *
	 * @param int    $cents     Stage 2 price in cents.
	 * @param string $plan      monthly or yearly.
	 * @param int    $member_id Member number.
	 */
	public static function plan_price( $cents, $plan, $member_id ) {
		if ( ! self::on( $member_id ) ) {
			return $cents;
		}
		$price = self::price( $plan );
		return is_wp_error( $price ) ? $cents : $price['amount'];
	}

	/**
	 * Filter: Stripe charges the price's own currency for every country (Adaptive Pricing is off on the session).
	 *
	 * @param string $currency  Stage 2 currency for the country.
	 * @param string $plan      monthly or yearly.
	 * @param int    $member_id Member number.
	 */
	public static function plan_currency( $currency, $plan, $member_id ) {
		if ( ! self::on( $member_id ) ) {
			return $currency;
		}
		$price = self::price( $plan );
		return is_wp_error( $price ) ? $currency : $price['currency'];
	}

	/**
	 * The fields of the Checkout Session for one quote. Kept separate so tests can read them without calling Stripe.
	 *
	 * @param array<string,mixed> $quote     Quote from LLD_Members_Billing::quote().
	 * @param int                 $member_id Member number.
	 * @param string              $price_id  Stripe price.
	 * @param string              $email     Member's email, or ''.
	 * @param string              $customer  Existing Stripe customer, or ''.
	 * @param string              $coupon    Stripe coupon for a discount code, or ''.
	 * @return array<string,mixed>
	 */
	public static function session_fields( $quote, $member_id, $price_id, $email, $customer, $coupon ) {
		$fields = array(
			'mode'                => 'subscription',
			'line_items'          => array(
				array(
					'price'    => $price_id,
					'quantity' => 1,
				),
			),
			// Stripe is the merchant of record: it charges, collects and files sales tax and VAT.
			'managed_payments'    => array( 'enabled' => true ),
			// The member pays exactly the price shown on the site, in its currency (FR3-62).
			'adaptive_pricing'    => array( 'enabled' => false ),
			'client_reference_id' => (string) (int) $member_id,
			'metadata'            => array(
				'member_id' => (string) (int) $member_id,
				'plan'      => (string) $quote['plan'],
			),
			'subscription_data'   => array(
				'metadata' => array(
					'member_id' => (string) (int) $member_id,
					'plan'      => (string) $quote['plan'],
				),
			),
			'success_url'         => home_url( '/members/checkout/?result=back' ),
			'cancel_url'          => home_url( '/members/checkout/?result=cancel' ),
		);
		if ( '' !== $customer ) {
			$fields['customer'] = $customer;
		} elseif ( '' !== $email ) {
			$fields['customer_email'] = $email;
		}
		if ( '' !== $coupon ) {
			$fields['discounts'] = array( array( 'coupon' => $coupon ) );
		}
		return $fields;
	}

	/**
	 * Filter: open a real Checkout Session. Returns the Stage 2 value untouched when the switch is off.
	 *
	 * @param array|WP_Error|null $session   null for the simulated session.
	 * @param array<string,mixed> $quote     Quote.
	 * @param int                 $member_id Member number.
	 * @param string              $ref       Referral code (kept on our side only).
	 * @return array{id:string,url:string}|WP_Error|null
	 */
	public static function checkout_session( $session, $quote, $member_id, $ref ) {
		unset( $ref );
		if ( ! self::on( $member_id ) ) {
			return $session;
		}
		$price = self::price( (string) $quote['plan'] );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		if ( (int) $price['amount'] !== (int) $quote['base'] || $price['currency'] !== (string) $quote['currency'] ) {
			// The quote and Stripe disagree: refuse rather than charge a price that was not shown.
			return new WP_Error( 'lld_stripe', 'The price changed. Please reload the page.' );
		}
		$coupon = '';
		if ( '' !== (string) $quote['code'] && (int) $quote['charged'] < (int) $quote['base'] ) {
			$percent = (int) round( 100 - ( (int) $quote['charged'] * 100 / max( 1, (int) $quote['base'] ) ) );
			$coupon  = self::coupon( (string) $quote['code'], $percent );
			if ( is_wp_error( $coupon ) ) {
				return $coupon;
			}
		}
		global $wpdb;
		$customer = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT stripe_customer FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', (int) $member_id ) );
		$email    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT user_email FROM ' . $wpdb->users . ' u JOIN ' . LLD_Members_Schema::table( 'member' ) . ' m ON m.user_id = u.ID WHERE m.id = %d', (int) $member_id ) );
		$fields   = self::session_fields( $quote, (int) $member_id, $price['id'], sanitize_email( $email ), 0 === strpos( $customer, 'cus_' ) ? $customer : '', $coupon );
		$made     = self::request( 'POST', 'checkout/sessions', $fields, 'lld-checkout-' . (int) $member_id . '-' . wp_generate_uuid4() );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		if ( empty( $made['id'] ) || empty( $made['url'] ) ) {
			return new WP_Error( 'lld_stripe', 'Stripe did not return a checkout page.' );
		}
		return array(
			'id'  => (string) $made['id'],
			'url' => (string) $made['url'],
		);
	}

	/**
	 * A once-only percentage coupon for a site discount code, made the first time it is used.
	 *
	 * @param string $code    Discount code.
	 * @param int    $percent Percent off the first payment.
	 * @return string|WP_Error Coupon id.
	 */
	public static function coupon( $code, $percent ) {
		$id    = 'lld_' . strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ) . '_' . (int) $percent;
		$found = self::request( 'GET', 'coupons/' . rawurlencode( $id ) );
		if ( ! is_wp_error( $found ) ) {
			return $id;
		}
		$data = $found->get_error_data();
		if ( ! is_array( $data ) || 404 !== (int) ( $data['status'] ?? 0 ) ) {
			return $found;
		}
		$made = self::request(
			'POST',
			'coupons',
			array(
				'id'          => $id,
				'name'        => strtoupper( $code ),
				'percent_off' => (int) $percent,
				'duration'    => 'once',
			),
			'lld-coupon-' . $id
		);
		return is_wp_error( $made ) ? $made : $id;
	}

	/**
	 * Filter: cancel at the end of the paid period, at Stripe first. The site changes only if Stripe agreed.
	 *
	 * @param true|WP_Error $result    Stage 2 result.
	 * @param int           $member_id Member number.
	 * @param object        $sub       Subscription row.
	 * @return true|WP_Error
	 */
	public static function cancel_at_period_end( $result, $member_id, $sub ) {
		$ref = $sub && ! empty( $sub->stripe_subscription ) ? (string) $sub->stripe_subscription : '';
		if ( ! self::on( $member_id ) || 0 !== strpos( $ref, 'sub_' ) ) {
			return $result;
		}
		$done = self::request( 'POST', 'subscriptions/' . rawurlencode( $ref ), array( 'cancel_at_period_end' => 'true' ), 'lld-cancel-' . $ref );
		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * Filter: on account deletion, end the Stripe subscription at once (FR2-04). Without the switch Stage 2 alerts the owner.
	 *
	 * @param true|WP_Error|null $result    null when the Stage 2 code should decide.
	 * @param int                $member_id Member number.
	 * @param string             $ref       Stripe subscription.
	 * @return true|WP_Error|null
	 */
	public static function cancel_member( $result, $member_id, $ref ) {
		if ( ! self::on( $member_id ) || 0 !== strpos( (string) $ref, 'sub_' ) ) {
			return $result;
		}
		$done = self::request( 'DELETE', 'subscriptions/' . rawurlencode( (string) $ref ) );
		if ( is_wp_error( $done ) ) {
			$data = $done->get_error_data();
			if ( is_array( $data ) && 'resource_missing' === ( $data['type'] ?? '' ) ) {
				// Already gone at Stripe, for example after the customer ended it through Onelink.
				return true;
			}
			return $done;
		}
		return true;
	}

	/**
	 * Filter: refund the order at Stripe and return Stripe's refund reference.
	 *
	 * @param string|WP_Error $ref   Stage 2 simulated reference.
	 * @param object          $order Order row.
	 * @return string|WP_Error
	 */
	public static function refund( $ref, $order ) {
		if ( ! self::on( (int) $order->member_id ) ) {
			return $ref;
		}
		$payment = (string) $order->stripe_payment;
		if ( 0 === strpos( $payment, 'in_' ) ) {
			$payment = self::invoice_payment( $payment );
		}
		if ( 0 !== strpos( $payment, 'pi_' ) ) {
			return new WP_Error( 'lld_refund', 'This payment has no Stripe reference to refund. Refund it in the Stripe Dashboard.' );
		}
		$done = self::request( 'POST', 'refunds', array( 'payment_intent' => $payment ), 'lld-refund-' . (int) $order->id );
		if ( is_wp_error( $done ) || empty( $done['id'] ) ) {
			return is_wp_error( $done ) ? $done : new WP_Error( 'lld_refund', 'Stripe did not confirm the refund.' );
		}
		return (string) $done['id'];
	}

	/**
	 * Filter, inside a payment notice: store the payment intent, so refunds from either side find the order.
	 *
	 * @param string              $payment Reference found in the notice (may be an invoice or session).
	 * @param array<string,mixed> $object  Notice object.
	 */
	public static function payment_ref( $payment, $object ) {
		if ( 0 === strpos( (string) $payment, 'pi_' ) ) {
			return $payment;
		}
		// A real notice can only come from Stripe, so a usable key is enough; the switch may since have been turned off.
		if ( ! in_array( self::mode(), array( 'test', 'live' ), true ) ) {
			return $payment;
		}
		$invoice = '';
		if ( isset( $object['object'] ) && 'invoice' === $object['object'] && ! empty( $object['id'] ) ) {
			$invoice = (string) $object['id'];
		} elseif ( ! empty( $object['invoice'] ) && is_string( $object['invoice'] ) ) {
			$invoice = $object['invoice'];
		}
		if ( 0 !== strpos( $invoice, 'in_' ) ) {
			return $payment;
		}
		$found = self::invoice_payment( $invoice );
		return 0 === strpos( $found, 'pi_' ) ? $found : $invoice;
	}

	/**
	 * The payment intent that paid an invoice ('' when Stripe has none or cannot be reached).
	 *
	 * @param string $invoice Invoice reference.
	 */
	public static function invoice_payment( $invoice ) {
		$found = self::request(
			'GET',
			'invoice_payments',
			array(
				'invoice' => $invoice,
				'limit'   => 1,
			)
		);
		if ( is_wp_error( $found ) || empty( $found['data'][0]['payment']['payment_intent'] ) ) {
			return '';
		}
		$intent = $found['data'][0]['payment']['payment_intent'];
		return is_array( $intent ) ? (string) ( $intent['id'] ?? '' ) : (string) $intent;
	}

	/**
	 * Filter: switching a monthly plan to yearly at Stripe is not built yet, so the button is hidden while the switch is on.
	 *
	 * @param bool $allowed   Stage 2 answer.
	 * @param int  $member_id Member number.
	 */
	public static function can_switch_yearly( $allowed, $member_id ) {
		return self::on( $member_id ) ? false : $allowed;
	}

	/**
	 * For the owner's check: prices found, their tax behaviour and whether the notice secret is set. Never shows a key.
	 *
	 * @return array<string,mixed>
	 */
	public static function status() {
		$out = array(
			'mode'           => self::mode(),
			'switch'         => LLD_Members_Features::state( 'stripe_api' ),
			'webhook_secret' => defined( 'LLD_STRIPE_WEBHOOK' ) && 0 === strpos( (string) LLD_STRIPE_WEBHOOK, 'whsec_' ) ? 'set' : 'missing',
			'prices'         => array(),
		);
		if ( in_array( $out['mode'], array( 'none', 'refused' ), true ) ) {
			return $out;
		}
		foreach ( array_keys( self::LOOKUP ) as $plan ) {
			delete_transient( 'lld_stripe_price_' . $out['mode'] . '_' . $plan );
			$price                  = self::price( $plan );
			$out['prices'][ $plan ] = is_wp_error( $price ) ? $price->get_error_message() : $price;
		}
		return $out;
	}

	/**
	 * Set both prices to tax-inclusive where Stripe still has no tax behaviour (Stripe allows this once).
	 *
	 * @return array<string,string> Plan to result.
	 */
	public static function make_tax_inclusive() {
		$out = array();
		foreach ( array_keys( self::LOOKUP ) as $plan ) {
			delete_transient( 'lld_stripe_price_' . self::mode() . '_' . $plan );
			$price = self::price( $plan );
			if ( is_wp_error( $price ) ) {
				$out[ $plan ] = $price->get_error_message();
				continue;
			}
			if ( 'inclusive' === $price['tax_behavior'] ) {
				$out[ $plan ] = 'already inclusive';
				continue;
			}
			if ( 'unspecified' !== $price['tax_behavior'] ) {
				$out[ $plan ] = 'is ' . $price['tax_behavior'] . ': make a new inclusive price in the Dashboard and move the lookup key to it';
				continue;
			}
			$done         = self::request( 'POST', 'prices/' . rawurlencode( $price['id'] ), array( 'tax_behavior' => 'inclusive' ) );
			$out[ $plan ] = is_wp_error( $done ) ? $done->get_error_message() : 'now inclusive';
			delete_transient( 'lld_stripe_price_' . self::mode() . '_' . $plan );
		}
		return $out;
	}
}
