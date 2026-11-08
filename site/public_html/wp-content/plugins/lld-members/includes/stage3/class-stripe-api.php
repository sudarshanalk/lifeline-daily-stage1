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
		add_filter( 'lld_members_stripe_switch', array( __CLASS__, 'switch_plan' ), 10, 3 );
		add_action( 'lld_members_reward_ready', array( __CLASS__, 'reward_ready' ), 10, 1 );
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
		// Half-configured is off: without the webhook signing secret every Stripe notice would be refused and no
		// member would ever receive Premium after paying.
		if ( ! defined( 'LLD_STRIPE_WEBHOOK' ) || 0 !== strpos( (string) LLD_STRIPE_WEBHOOK, 'whsec_' ) ) {
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
			// Stripe is the merchant of record: it charges, collects and files sales tax and VAT. Adaptive Pricing
			// is always on with Managed Payments (the parameter is unsupported there), so a member paying in another
			// currency sees a converted amount and may choose US$ on Stripe's page (Owner_Questions 70).
			'managed_payments'    => array( 'enabled' => true ),
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
			// The exact cents the site took off, so Stripe's total equals the quote's charged amount to the cent
			// (a percentage would let Stripe round the other way and the notice would be refused as a mismatch).
			$coupon = self::coupon( (string) $quote['code'], (int) $quote['base'] - (int) $quote['charged'], (string) $quote['currency'] );
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
	 * A once-only fixed-amount coupon for a site discount code, made the first time that amount is used. The id
	 * carries the amount and currency, so a changed discount makes a new coupon rather than reusing the old one.
	 *
	 * @param string $code     Discount code.
	 * @param int    $amount   Minor units off the first payment (the quote's base minus charged).
	 * @param string $currency Three-letter currency code of the quote.
	 * @return string|WP_Error Coupon id.
	 */
	public static function coupon( $code, $amount, $currency ) {
		$amount   = max( 1, (int) $amount );
		$currency = strtolower( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$id       = 'lld_' . strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ) . '_' . $amount . $currency;
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
				'id'         => $id,
				'name'       => strtoupper( $code ),
				'amount_off' => $amount,
				'currency'   => $currency,
				'duration'   => 'once',
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
		$live     = self::request( 'GET', 'subscriptions/' . rawurlencode( $ref ) );
		$schedule = ( ! is_wp_error( $live ) && isset( $live['schedule'] ) ) ? self::schedule_id( $live['schedule'] ) : '';
		if ( '' !== $schedule ) {
			// A queued switch to yearly: Stripe refuses to change a subscription its schedule manages, so let go first.
			$released = self::request( 'POST', 'subscription_schedules/' . rawurlencode( $schedule ) . '/release', array(), 'lld-release-' . $schedule . '-' . wp_generate_uuid4() );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
		}
		$done = self::request( 'POST', 'subscriptions/' . rawurlencode( $ref ), array( 'cancel_at_period_end' => 'true' ), 'lld-cancel-' . $ref );
		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * A schedule reference from a subscription's "schedule" field (an id or an expanded object), or ''.
	 *
	 * @param mixed $field The field.
	 */
	public static function schedule_id( $field ) {
		$id = is_array( $field ) ? (string) ( $field['id'] ?? '' ) : (string) $field;
		return 0 === strpos( $id, 'sub_sched_' ) ? $id : '';
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
	 * Pause a monthly subscription (P3-63, FR3-65). Collection is paused with behaviour "void" until an hour before
	 * the renewal that follows the pause: the renewal at the start is not charged, the next one is, at the
	 * subscription's own price. A failed payment then follows Stripe's retries as in Stage 2.
	 *
	 * @param string $ref    Stripe subscription.
	 * @param int    $months 1, 2 or 3.
	 * @return true|WP_Error
	 */
	public static function pause( $ref, $months ) {
		$sub = self::request( 'GET', 'subscriptions/' . rawurlencode( $ref ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		$resumes = self::resumes_at( isset( $sub['current_period_end'] ) ? (int) $sub['current_period_end'] : ( isset( $sub['items']['data'][0]['current_period_end'] ) ? (int) $sub['items']['data'][0]['current_period_end'] : 0 ), (int) $months );
		if ( $resumes < 1 ) {
			return new WP_Error( 'lld_stripe', 'Stripe did not give the end of the paid month.' );
		}
		$done = self::request(
			'POST',
			'subscriptions/' . rawurlencode( $ref ),
			array(
				'pause_collection' => array(
					'behavior'   => 'void',
					'resumes_at' => $resumes,
				),
			)
		);
		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * The moment collection resumes: the paid month's end moved on by the pause, less an hour, so the renewal
	 * that falls on that day is charged (TC-FR3-65-AS-01).
	 *
	 * @param int $period_end Unix time the paid month ends.
	 * @param int $months     Months paused.
	 */
	public static function resumes_at( $period_end, $months ) {
		if ( $period_end < 1 || $months < 1 ) {
			return 0;
		}
		// Calendar months kept to the month's last day, as Stripe moves a monthly renewal (31 Jan, then 28 Feb).
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', gmdate( 'Y-n-j', $period_end ) ) );
		$m   += (int) $months;
		$y   += intdiv( $m - 1, 12 );
		$m    = ( ( $m - 1 ) % 12 ) + 1;
		$day  = min( $d, (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) ) );
		return gmmktime( 0, 0, 0, $m, $day, $y ) + ( $period_end % 86400 ) - 3600;
	}

	/**
	 * Undo a pause that has not started.
	 *
	 * @param string $ref Stripe subscription.
	 * @return true|WP_Error
	 */
	public static function unpause( $ref ) {
		$done = self::request( 'POST', 'subscriptions/' . rawurlencode( $ref ), array( 'pause_collection' => '' ) );
		return is_wp_error( $done ) ? $done : true;
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
	 * Filter: with real Stripe, switching needs a Stripe subscription to schedule the change on (D2a, owner 8 Nov 2026).
	 *
	 * @param bool $allowed   Stage 2 answer.
	 * @param int  $member_id Member number.
	 */
	public static function can_switch_yearly( $allowed, $member_id ) {
		if ( ! $allowed || ! self::on( $member_id ) ) {
			return $allowed;
		}
		$sub = LLD_Members_Billing::subscription_for( (int) $member_id );
		return $sub && 0 === strpos( (string) $sub->stripe_subscription, 'sub_' );
	}

	/**
	 * Filter: monthly to yearly at the next renewal, at Stripe (D2a). A subscription schedule keeps the monthly price to
	 * the end of the month already paid for, then moves to the yearly price; changing the price straight on the
	 * subscription would charge the yearly price at once, because the billing period changes. "undo" releases the
	 * schedule, which leaves the monthly subscription as it was. Returns the Stage 2 value when Stripe is off.
	 *
	 * @param true|WP_Error $result    Stage 2 result.
	 * @param int           $member_id Member number.
	 * @param string        $to        "yearly" or "undo".
	 * @return true|WP_Error
	 */
	public static function switch_plan( $result, $member_id, $to ) {
		$sub = self::on( $member_id ) ? LLD_Members_Billing::subscription_for( (int) $member_id ) : null;
		$ref = $sub ? (string) $sub->stripe_subscription : '';
		if ( 0 !== strpos( $ref, 'sub_' ) ) {
			return $result;
		}
		$live = self::request( 'GET', 'subscriptions/' . rawurlencode( $ref ) );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$schedule = isset( $live['schedule'] ) ? self::schedule_id( $live['schedule'] ) : '';
		if ( 'undo' === $to ) {
			if ( '' === $schedule ) {
				return true;   // nothing scheduled at Stripe
			}
			$done = self::request( 'POST', 'subscription_schedules/' . rawurlencode( $schedule ) . '/release', array(), 'lld-switch-undo-' . $schedule . '-' . wp_generate_uuid4() );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			// The reward discount was on the yearly phase; it goes back on the subscription through the reward hook.
			return true;
		}
		$yearly = self::price( 'yearly' );
		if ( is_wp_error( $yearly ) ) {
			return $yearly;
		}
		$monthly = isset( $live['items']['data'][0]['price']['id'] ) ? (string) $live['items']['data'][0]['price']['id'] : '';
		$ends    = isset( $live['items']['data'][0]['current_period_end'] ) ? (int) $live['items']['data'][0]['current_period_end'] : (int) ( $live['current_period_end'] ?? 0 );
		if ( '' === $monthly || $ends < 1 ) {
			return new WP_Error( 'lld_stripe', 'Stripe did not give the current plan.' );
		}
		// Fresh idempotency keys each time: after an undo, a new switch in the same month must make a new schedule,
		// not get the released one back from Stripe's replay of the first request.
		if ( '' === $schedule ) {
			$found = self::request( 'POST', 'subscription_schedules', array( 'from_subscription' => $ref ), 'lld-switch-' . $ref . '-' . wp_generate_uuid4() );
		} else {
			$found = self::request( 'GET', 'subscription_schedules/' . rawurlencode( $schedule ) );
		}
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$schedule = self::schedule_id( $found['id'] ?? '' );
		$start    = isset( $found['phases'][0]['start_date'] ) ? (int) $found['phases'][0]['start_date'] : 0;
		if ( '' === $schedule || $start < 1 || ! in_array( (string) ( $found['status'] ?? '' ), array( 'not_started', 'active' ), true ) ) {
			return new WP_Error( 'lld_stripe', 'Stripe did not schedule the change.' );
		}
		// A waiting reward reduces the first yearly payment: 13 % off the yearly price, as the site expects (D2a).
		$coupon = '';
		$next   = LLD_Members_Billing::next_charge_for( (int) $member_id );
		if ( ! empty( $next['reward_id'] ) ) {
			$coupon = self::coupon( 'reward', self::yearly_reward_off( (int) $yearly['amount'] ), 'usd' );
			if ( is_wp_error( $coupon ) ) {
				return $coupon;
			}
			if ( ! empty( $live['discounts'] ) || ! empty( $live['discount'] ) ) {
				self::request( 'DELETE', 'subscriptions/' . rawurlencode( $ref ) . '/discount' );   // the monthly-form coupon
			}
		}
		$done = self::request( 'POST', 'subscription_schedules/' . rawurlencode( $schedule ), self::switch_phases( $start, $ends, $monthly, (string) $yearly['id'], (int) $member_id, $coupon ), 'lld-switch-phases-' . $schedule . '-' . wp_generate_uuid4() );
		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * Cents a 13 % reward takes off a yearly price, matching the site's own rounding (charged = round(base x 87 %)).
	 *
	 * @param int $amount Yearly price in cents.
	 */
	public static function yearly_reward_off( $amount ) {
		return (int) $amount - (int) round( (int) $amount * 87 / 100 );
	}

	/**
	 * The schedule's two phases: monthly to the end of the paid month, then one yearly period, after which the schedule
	 * lets go and the subscription simply renews yearly. No proration either side. Pure (tested).
	 *
	 * @param int    $start     Unix start of the current phase.
	 * @param int    $ends      Unix end of the month already paid for.
	 * @param string $monthly   Current (monthly) price id.
	 * @param string $yearly    Yearly price id.
	 * @param int    $member_id Member number (kept in the subscription's metadata).
	 * @param string $coupon    Once-only reward coupon for the first yearly payment, or ''.
	 * @return array<string,mixed>
	 */
	public static function switch_phases( $start, $ends, $monthly, $yearly, $member_id, $coupon = '' ) {
		$fields = array(
			'end_behavior' => 'release',
			'phases'       => array(
				array(
					'start_date'         => (int) $start,
					'end_date'           => (int) $ends,
					'items'              => array( array( 'price' => $monthly, 'quantity' => 1 ) ),
					'proration_behavior' => 'none',
				),
				array(
					'items'              => array( array( 'price' => $yearly, 'quantity' => 1 ) ),
					'iterations'         => 1,
					'proration_behavior' => 'none',
					'metadata'           => array(
						'member_id' => (string) (int) $member_id,
						'plan'      => 'yearly',
					),
				),
			),
		);
		if ( '' !== (string) $coupon ) {
			$fields['phases'][1]['discounts'] = array( array( 'coupon' => (string) $coupon ) );
		}
		return $fields;
	}

	/**
	 * Action: a referral reward is waiting (D2a). With real Stripe it is put on the member's next invoice as a once-only
	 * discount of exactly the cents the site takes off, so the invoice matches the site's expected charge to the cent.
	 * Only one discount at a time: a further reward waits until this one has been used.
	 *
	 * @param int $member_id Member number.
	 */
	public static function reward_ready( $member_id ) {
		$member_id = (int) $member_id;
		$sub       = self::on( $member_id ) ? LLD_Members_Billing::subscription_for( $member_id ) : null;
		$ref       = $sub ? (string) $sub->stripe_subscription : '';
		if ( 0 !== strpos( $ref, 'sub_' ) ) {
			return;
		}
		$next = LLD_Members_Billing::next_charge_for( $member_id );
		$off  = self::reward_off( $next );
		if ( $off < 1 ) {
			return;
		}
		$live = self::request( 'GET', 'subscriptions/' . rawurlencode( $ref ) );
		if ( is_wp_error( $live ) ) {
			return;   // tried again by the daily run
		}
		$schedule = isset( $live['schedule'] ) ? self::schedule_id( $live['schedule'] ) : '';
		if ( ! empty( $sub->pending_yearly_on ) || '' !== $schedule ) {
			// A queued switch: the reward rides on the schedule's yearly phase. Put it there if it is missing.
			$found = '' !== $schedule ? self::request( 'GET', 'subscription_schedules/' . rawurlencode( $schedule ) ) : null;
			if ( is_array( $found ) && empty( $found['phases'][1]['discounts'] ) ) {
				self::switch_plan( true, $member_id, 'yearly' );
			}
			return;
		}
		if ( ! empty( $live['discounts'] ) || ! empty( $live['discount'] ) ) {
			return;   // a discount is already waiting there
		}
		$coupon = self::coupon( 'reward', $off, (string) $next['currency'] );
		if ( is_wp_error( $coupon ) ) {
			return;
		}
		self::request( 'POST', 'subscriptions/' . rawurlencode( $ref ), array( 'discounts' => array( array( 'coupon' => $coupon ) ) ), 'lld-reward-' . $ref . '-' . (int) $next['reward_id'] . '-' . wp_generate_uuid4() );
	}

	/**
	 * Cents a waiting reward takes off the next charge (0 when none). Pure (tested).
	 *
	 * @param array<string,mixed> $next The site's next charge (base, charged, reward_id).
	 */
	public static function reward_off( $next ) {
		if ( empty( $next['reward_id'] ) ) {
			return 0;
		}
		return max( 0, (int) ( $next['base'] ?? 0 ) - (int) ( $next['charged'] ?? 0 ) );
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
