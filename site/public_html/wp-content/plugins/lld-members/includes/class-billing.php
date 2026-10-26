<?php
/**
 * Premium prices, Stripe hosted checkout, notices, referrals and access after payment.
 *
 * Access changes only from a verified Stripe notice or an owner refund. The return page never grants Premium.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Billing for Stage 2. Card details are never collected on this site.
 */
class LLD_Members_Billing {

	/**
	 * Owner screens for prices, codes and refunds.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	public static function admin_menu() {
		add_menu_page( 'Payments', 'Payments', 'manage_options', 'lld-payments', array( __CLASS__, 'admin_page' ), 'dashicons-tickets', 27 );
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function paid_open( $member_id ) {
		$state = strtolower( LLD_Members_Access::access_state( $member_id ) );
		if ( in_array( $state, array( 'free', 'premium ended', 'refunded', '' ), true ) ) {
			return false;
		}
		if ( 'cancelled' === $state ) {
			return self::in_period( $member_id );
		}
		return true;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function ended( $member_id ) {
		$state = strtolower( LLD_Members_Access::access_state( $member_id ) );
		if ( in_array( $state, array( 'premium ended', 'refunded' ), true ) ) {
			return true;
		}
		return 'cancelled' === $state && ! self::in_period( $member_id );
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	public static function week_remains( $member_id, $day ) {
		if ( ! self::ended( $member_id ) ) {
			return false;
		}
		self::ensure_closed_week( $member_id );
		global $wpdb;
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row || ! $row->last_open_day ) {
			return false;
		}
		$date = self::programme_date( $member_id, (int) $day );
		return '' !== $date && $date <= (string) $row->last_open_day;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function diary_writable( $member_id ) {
		if ( self::paid_open( $member_id ) ) {
			return true;
		}
		if ( self::ended( $member_id ) ) {
			self::ensure_closed_week( $member_id );
			$row = LLD_Members_Programme::active( $member_id );
			return $row && $row->last_open_day && LLD_Members_Programme::local_today( $member_id ) <= (string) $row->last_open_day;
		}
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row ) {
			return false;
		}
		return LLD_Members_Programme::programme_day( $member_id, (int) $row->id ) <= 7;
	}

	/**
	 * Reminders, retries, reward settlement and mismatch notes. No health details.
	 */
	public static function daily() {
		self::settle_rewards();
		self::retry_window();
		self::close_elapsed();
		self::renewal_reminders();
		self::mismatch_mail();
	}

	/**
	 * Public pricing page.
	 */
	public static function pricing() {
		$prices = self::prices();
		$main   = '<h1>Pricing</h1>';
		$main  .= '<article data-lld-plan="yearly"><p>best value</p><p>' . esc_html( self::money( $prices['yearly'], 'usd' ) ) . ' a year</p><p>tax included</p>';
		$main  .= '<p>That is US$5 a month, a saving of 37 %.</p>';
		$main  .= lld_button( array( 'label' => 'Choose yearly', 'href' => home_url( '/members/checkout/?plan=yearly' ) ) ) . '</article>';
		$main  .= '<article data-lld-plan="monthly"><p>' . esc_html( self::money( $prices['monthly'], 'usd' ) ) . ' a month</p><p>tax included</p>';
		$main  .= lld_button( array( 'label' => 'Choose monthly', 'href' => home_url( '/members/checkout/?plan=monthly' ) ) ) . '</article>';
		lld_members_layout(
			array(
				'title'       => 'Pricing',
				'description' => 'Premium is US$59.99 a year or US$7.99 a month, tax included.',
				'main'        => $main,
			)
		);
	}

	/**
	 * One sales page for a track.
	 *
	 * @param string $key Track slug.
	 */
	public static function sales( $key ) {
		$pages = self::sales_pages();
		if ( ! isset( $pages[ $key ] ) ) {
			status_header( 404 );
			return;
		}
		$page   = $pages[ $key ];
		$prices = self::prices();
		$main   = '<h1>' . esc_html( $page['title'] ) . '</h1>';
		$main  .= '<p>best value</p><p>' . esc_html( self::money( $prices['yearly'], 'usd' ) ) . ' a year</p><p>tax included</p>';
		$main  .= '<ul><li>Weeks 2 to 4 of this track</li><li>Meal plans, workouts and the monthly challenge</li><li>The food diary after week 1</li></ul>';
		$main  .= '<p>Cancel any time. Access continues to the end of the paid period.</p>';
		$main  .= lld_button( array( 'label' => 'See pricing', 'href' => home_url( '/pricing/' ) ) );
		$main  .= '<h2>Who it is for</h2><p>' . esc_html( $page['for'] ) . '</p>';
		$main  .= '<h2>Who it is not for</h2><p>' . esc_html( $page['not'] ) . '</p>';
		$main  .= '<h2>Weekly themes</h2><ol><li>Week 1: getting started</li><li>Week 2: a steady rhythm</li><li>Week 3: building on it</li><li>Week 4: keeping it going</li></ol>';
		$main  .= '<h2>Sample activity</h2><p>' . esc_html( $page['activity'] ) . '</p>';
		$main  .= '<h2>Sample meal</h2><p>' . esc_html( $page['meal'] ) . '</p>';
		$main  .= '<h2>Testimonials</h2><p>Testimonials appear here once the owner approves them.</p>';
		$main  .= '<h2>Questions and answers</h2><p>Week 1 is free. Premium opens the rest.</p>';
		$main  .= '<h2>Safety note</h2><p>' . esc_html( $page['safety'] ) . '</p>';
		lld_members_layout(
			array(
				'title'       => $page['title'],
				'description' => $page['description'],
				'main'        => $main,
			)
		);
	}

	/**
	 * Calculator landing page. The track link sits below the tool, not inside its result.
	 *
	 * @param string $which protein or diabetes.
	 */
	public static function landing( $which ) {
		if ( 'diabetes' === $which ) {
			$title = 'Diabetes Risk Check';
			$src   = home_url( '/tools/diabetes-risk-tool.html' );
			$href  = home_url( '/programmes/blood-sugar/' );
			$label = 'Blood Sugar Balance';
			$desc  = 'Check diabetes risk, then look at the Blood Sugar Balance track.';
		} else {
			$title = 'Daily Protein Target';
			$src   = home_url( '/tools/protein-requirement-tool.html' );
			$href  = home_url( '/programmes/fitness/' );
			$label = 'Fitness and Strength';
			$desc  = 'Find a daily protein target, then look at the Fitness and Strength track.';
		}
		$main  = '<h1>' . esc_html( $title ) . '</h1>';
		$main .= '<iframe class="lld-tool-frame" title="' . esc_attr( $title ) . '" src="' . esc_url( $src ) . '" style="width:100%;height:900px;border:0"></iframe>';
		$main .= '<p data-lld-track-link><a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></p>';
		lld_members_layout(
			array(
				'title'       => $title,
				'description' => $desc,
				'main'        => $main,
			)
		);
	}

	/**
	 * Safety information and the medical disclaimer stay open with no account.
	 *
	 * @param string $which safety or disclaimer.
	 */
	public static function public_note( $which ) {
		if ( 'disclaimer' === $which ) {
			$title = 'Medical disclaimer';
			$main  = '<h1>Medical disclaimer</h1><p>Lifeline Daily is general information, not a diagnosis or personal medical advice.</p>';
		} else {
			$title = 'Safety information';
			$main  = '<h1>Safety information</h1><p>Stop if something hurts, and check with a doctor when you need to. This page is open to everyone.</p>';
		}
		lld_members_layout(
			array(
				'title' => $title,
				'main'  => $main,
			)
		);
	}

	/**
	 * Plugin sitemap for the pricing page and the four sales pages.
	 */
	public static function sitemap() {
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		$paths = array( '/pricing/', '/programmes/healthy-weight/', '/programmes/blood-sugar/', '/programmes/fitness/', '/programmes/sleep-stress/' );
		foreach ( $paths as $path ) {
			echo '<url><loc>' . esc_url( home_url( $path ) ) . '</loc></url>';
		}
		echo '</urlset>';
		exit;
	}

	/**
	 * Checkout handover. Stripe hosts the card form.
	 */
	public static function checkout() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$reach     = LLD_Members_Stripe::reachable();
		$main      = '<h1>Checkout</h1>';
		if ( is_wp_error( $reach ) ) {
			$main .= '<div class="lld-fail" role="alert"><p>' . esc_html( LLD_Members_Stripe::try_again_message() ) . '</p></div>';
			LLD_Members_Personal::render( 'Checkout', $main );
			return;
		}
		$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
		if ( 'cancel' === $result ) {
			$main .= '<p>No payment was taken.</p><p><a href="' . esc_url( home_url( '/pricing/' ) ) . '">Back to pricing</a></p>';
			LLD_Members_Personal::render( 'Checkout', $main );
			return;
		}
		if ( 'back' === $result ) {
			$main .= '<p>We\'re confirming your payment. This usually takes less than a minute. Please don\'t pay again.</p>';
			$main .= lld_button( array( 'label' => 'Check again', 'href' => home_url( '/members/subscription/' ) ) );
			LLD_Members_Personal::render( 'Checkout', $main );
			return;
		}
		$plan = 'yearly';
		if ( isset( $_REQUEST['lld_plan'] ) ) {
			$plan = sanitize_key( wp_unslash( $_REQUEST['lld_plan'] ) );
		} elseif ( isset( $_GET['plan'] ) ) {
			$plan = sanitize_key( wp_unslash( $_GET['plan'] ) );
		}
		if ( ! in_array( $plan, array( 'yearly', 'monthly' ), true ) ) {
			$plan = 'yearly';
		}
		$country = isset( $_POST['lld_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['lld_country'] ) ) ) : 'US';
		if ( ! in_array( $country, array( 'GB', 'DE', 'US' ), true ) ) {
			$country = 'US';
		}
		$code = isset( $_REQUEST['lld_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['lld_code'] ) ) ) : '';
		$ref  = isset( $_REQUEST['lld_ref'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lld_ref'] ) ) : '';
		$shown = false;
		$note  = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_checkout_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_checkout_nonce'] ) ), 'lld_checkout' ) ) {
			$quote = self::quote( $member_id, $plan, $country, $code );
			if ( $code && empty( $quote['code'] ) ) {
				$note = 'This code has ended.';
			}
			$session = 'cs_test_' . bin2hex( random_bytes( 8 ) );
			self::store_quote( $session, $quote, $member_id, $ref );
			$shown = true;
			$main .= '<p>You\'ll pay securely with Stripe. We never see your card details.</p>';
			$main .= '<p data-lld-charge="' . (int) $quote['charged'] . '" data-lld-shown="' . (int) $quote['charged'] . '" data-lld-tax="' . (int) $quote['tax'] . '" data-lld-currency="' . esc_attr( $quote['currency'] ) . '" data-lld-session="' . esc_attr( $session ) . '">';
			$main .= esc_html( self::money( $quote['charged'], $quote['currency'] ) ) . '</p>';
			$main .= '<p>tax included</p>';
			$main .= '<p>' . esc_html( self::tax_line( $quote ) ) . '</p>';
			$main .= '<p>Apple Pay and Google Pay are offered by Stripe on devices that support them.</p>';
			$main .= '<p><a href="' . esc_url( 'https://checkout.stripe.com/c/pay/' . $session ) . '">Continue to payment</a></p>';
			if ( $note ) {
				$main .= '<p>' . esc_html( $note ) . '</p>';
			}
		}
		if ( ! $shown ) {
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_checkout', 'lld_checkout_nonce', true, false );
			$main .= '<input type="hidden" name="lld_ref" value="' . esc_attr( $ref ) . '">';
			$main .= '<fieldset><legend>Plan</legend>';
			$main .= '<label><input type="radio" name="lld_plan" value="yearly"' . checked( $plan, 'yearly', false ) . '> Yearly</label> ';
			$main .= '<label><input type="radio" name="lld_plan" value="monthly"' . checked( $plan, 'monthly', false ) . '> Monthly</label>';
			$main .= '</fieldset>';
			$main .= '<label for="lld_country">Country</label> <select id="lld_country" name="lld_country">';
			$main .= '<option value="US">United States</option><option value="GB">United Kingdom</option><option value="DE">Germany</option></select>';
			$main .= '<label for="lld_code">Discount code</label> <input id="lld_code" name="lld_code" value="' . esc_attr( $code ) . '">';
			$main .= lld_button( array( 'label' => 'Show the price' ) );
			$main .= '</form>';
		}
		LLD_Members_Personal::render( 'Checkout', $main );
	}

	/**
	 * My subscription, cancel, switch and the referral link.
	 */
	public static function subscription() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$posted    = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		if ( $posted && isset( $_POST['lld_sub_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_sub_nonce'] ) ), 'lld_sub' ) ) {
			$action = isset( $_POST['lld_sub_action'] ) ? sanitize_key( wp_unslash( $_POST['lld_sub_action'] ) ) : '';
			if ( 'cancel' === $action ) {
				self::cancel_at_period_end( $member_id );
			} elseif ( 'switch' === $action ) {
				self::queue_yearly( $member_id );
			} elseif ( 'undo' === $action ) {
				self::save_sub( $member_id, array( 'pending_yearly_on' => null ) );
			}
		}
		$sub  = self::subscription_row( $member_id );
		$main = '<h1>My subscription</h1>';
		if ( ! $sub || 'free' === strtolower( (string) $sub->access_state ) ) {
			$main .= '<p>Your membership is Free.</p>';
			$main .= '<p><a href="' . esc_url( home_url( '/pricing/' ) ) . '">See pricing</a></p>';
		} else {
			$main .= '<p>Plan: ' . esc_html( (string) $sub->plan ) . '</p>';
			$main .= '<p>Your membership is ' . esc_html( (string) $sub->access_state ) . '.</p>';
			if ( $sub->locked_amount ) {
				$main .= '<p>Your price is ' . esc_html( self::money( (int) $sub->locked_amount, (string) $sub->locked_currency ) ) . ', tax included.</p>';
			}
			if ( $sub->period_ends_on ) {
				$main .= '<p>Next renewal ' . esc_html( (string) $sub->period_ends_on ) . '</p>';
			}
			if ( 'cancelled' === strtolower( (string) $sub->access_state ) && self::in_period( $member_id ) ) {
				$main .= '<p>Premium until ' . esc_html( (string) $sub->period_ends_on ) . '</p>';
			}
			if ( 'past_due' === (string) $sub->stripe_state ) {
				$main .= '<p>Update your card</p>';
			}
			$app = ( 'yearly' === (string) $sub->plan && self::paid_open( $member_id ) ) ? 'included' : 'not included';
			$main .= '<p data-lld-app="' . esc_attr( $app ) . '">Stage 3 app: ' . esc_html( $app ) . '</p>';
			$next = self::next_charge( $member_id );
			$main .= '<p data-lld-next="' . (int) $next['charged'] . '" data-lld-next-currency="' . esc_attr( $next['currency'] ) . '">Next payment ' . esc_html( self::money( $next['charged'], $next['currency'] ) ) . '</p>';
			if ( $sub->pending_yearly_on ) {
				$main .= '<p>Switch to yearly on ' . esc_html( (string) $sub->pending_yearly_on ) . '</p>';
			}
		}
		$main .= self::receipt_list( $member_id );
		$main .= self::reward_list( $member_id );
		$link  = home_url( '/pricing/?ref=' . rawurlencode( self::referral_code( $member_id ) ) );
		$main .= '<p>Your referral link is <a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
		$main .= '<form method="post">';
		$main .= wp_nonce_field( 'lld_sub', 'lld_sub_nonce', true, false );
		if ( $sub && 'monthly' === (string) $sub->plan && self::paid_open( $member_id ) && ! $sub->pending_yearly_on ) {
			$main .= '<button class="lld-button" type="submit" name="lld_sub_action" value="switch">Switch to yearly</button> ';
		}
		if ( $sub && $sub->pending_yearly_on ) {
			$main .= '<button class="lld-button" type="submit" name="lld_sub_action" value="undo">Undo</button> ';
		}
		if ( self::paid_open( $member_id ) && ( ! $sub || 'cancelled' !== strtolower( (string) $sub->access_state ) ) ) {
			$confirm = isset( $_GET['confirm'] );
			if ( ! $confirm ) {
				$main .= '<p><a href="' . esc_url( home_url( '/members/subscription/?confirm=1' ) ) . '">Cancel</a></p>';
			} else {
				$main .= '<button class="lld-button" type="submit" name="lld_sub_action" value="cancel">Confirm cancellation</button>';
			}
		}
		$main .= '</form>';
		LLD_Members_Personal::render( 'My subscription', $main );
	}

	/**
	 * The referral link also appears on the dashboard.
	 *
	 * @param int $member_id Member number.
	 */
	public static function referral_paragraph( $member_id ) {
		$link = home_url( '/pricing/?ref=' . rawurlencode( self::referral_code( $member_id ) ) );
		$html = '<p>Your referral link is <a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
		$html .= '<p><a href="' . esc_url( home_url( '/members/subscription/' ) ) . '">My subscription</a></p>';
		return $html;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_notice( $request ) {
		$raw    = $request->get_body();
		$header = $request->get_header( 'stripe_signature' );
		if ( ! is_string( $header ) || '' === $header ) {
			$header = $request->get_header( 'stripe-signature' );
		}
		if ( ! self::valid_signature( $raw, $header ) ) {
			return new WP_REST_Response( array( 'message' => 'rejected' ), 400 );
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'message' => 'rejected' ), 400 );
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'stripe_notice' );
		$ref   = sanitize_text_field( (string) $event['id'] );
		$seen  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE notice_ref = %s", $ref ) );
		if ( $seen ) {
			return new WP_REST_Response( array( 'replay' => true ), 200 );
		}
		$wpdb->insert(
			$table,
			array(
				'notice_ref'        => $ref,
				'notice_type'       => sanitize_text_field( (string) $event['type'] ),
				'received_at'       => lld_members_now_mysql(),
				'processing_state'  => 'processed',
				'attempts'          => 1,
			)
		);
		self::apply_event( $event );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		$action = isset( $params['action'] ) ? sanitize_key( (string) $params['action'] ) : '';
		if ( 'reset' === $action ) {
			delete_option( 'lld_prices' );
			delete_option( 'lld_codes' );
			delete_option( 'lld_launch_on' );
			delete_option( 'lld_reward_stop' );
			delete_option( 'lld_members_stripe_unreachable' );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'launch' === $action && ! empty( $params['on'] ) ) {
			update_option( 'lld_launch_on', sanitize_text_field( (string) $params['on'] ), false );
			return new WP_REST_Response( array( 'expires_on' => self::launch_expires() ), 200 );
		}
		if ( 'prices' === $action ) {
			self::save_prices(
				isset( $params['monthly'] ) ? (int) $params['monthly'] : self::prices()['monthly'],
				isset( $params['yearly'] ) ? (int) $params['yearly'] : self::prices()['yearly'],
				isset( $params['code'] ) ? (string) $params['code'] : '',
				isset( $params['percent'] ) ? (int) $params['percent'] : 0
			);
			return new WP_REST_Response( self::prices(), 200 );
		}
		if ( 'stop_reward' === $action ) {
			update_option( 'lld_reward_stop', '1', false );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$member_id = self::member_by_email( $email );
		if ( 'session' === $action ) {
			$plan    = isset( $params['plan'] ) ? sanitize_key( (string) $params['plan'] ) : 'monthly';
			$country = isset( $params['country'] ) ? strtoupper( sanitize_text_field( (string) $params['country'] ) ) : 'US';
			$code    = isset( $params['code'] ) ? strtoupper( sanitize_text_field( (string) $params['code'] ) ) : '';
			$ref     = isset( $params['ref'] ) ? sanitize_text_field( (string) $params['ref'] ) : '';
			if ( ! in_array( $plan, array( 'yearly', 'monthly' ), true ) ) {
				$plan = 'monthly';
			}
			if ( ! in_array( $country, array( 'GB', 'DE', 'US' ), true ) ) {
				$country = 'US';
			}
			$quote   = self::quote( $member_id, $plan, $country, $code );
			$session = 'cs_test_' . bin2hex( random_bytes( 8 ) );
			self::store_quote( $session, $quote, $member_id, $ref );
			$quote['session'] = $session;
			return new WP_REST_Response( $quote, 200 );
		}
		if ( 'read' === $action ) {
			return new WP_REST_Response( self::snapshot( $member_id ), 200 );
		}
		return new WP_REST_Response( array( 'message' => 'Unknown action.' ), 400 );
	}

	/**
	 * Owner page.
	 */
	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['lld_pay_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_pay_nonce'] ) ), 'lld_pay' ) ) {
			$monthly = isset( $_POST['lld_monthly'] ) ? (int) round( (float) wp_unslash( $_POST['lld_monthly'] ) * 100 ) : self::prices()['monthly'];
			$yearly  = isset( $_POST['lld_yearly'] ) ? (int) round( (float) wp_unslash( $_POST['lld_yearly'] ) * 100 ) : self::prices()['yearly'];
			$code    = isset( $_POST['lld_new_code'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_new_code'] ) ) : '';
			$percent = isset( $_POST['lld_percent'] ) ? (int) wp_unslash( $_POST['lld_percent'] ) : 0;
			self::save_prices( $monthly, $yearly, $code, $percent );
			echo '<div class="notice notice-success"><p>Saved</p></div>';
		}
		if ( isset( $_POST['lld_refund_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_refund_nonce'] ) ), 'lld_refund' ) ) {
			$order_id = isset( $_POST['lld_order'] ) ? (int) $_POST['lld_order'] : 0;
			$result   = self::refund_order( $order_id );
			echo '<div class="notice"><p>' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : 'Refunded' ) . '</p></div>';
		}
		$prices = self::prices();
		echo '<div class="wrap"><h1>Payments</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'lld_pay', 'lld_pay_nonce' );
		echo '<p><label>Monthly price US$ <input name="lld_monthly" value="' . esc_attr( number_format( $prices['monthly'] / 100, 2, '.', '' ) ) . '"></label></p>';
		echo '<p><label>Yearly price US$ <input name="lld_yearly" value="' . esc_attr( number_format( $prices['yearly'] / 100, 2, '.', '' ) ) . '"></label></p>';
		echo '<p><label>New code <input name="lld_new_code"></label> <label>Percent off the first month <input name="lld_percent" value="50"></label></p>';
		echo '<p><button class="button button-primary">Save prices</button></p></form>';
		echo '<h2>Refund the first payment</h2>';
		self::admin_refunds();
		echo '</div>';
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $plan      yearly or monthly.
	 * @param string $country   GB, DE or US.
	 * @param string $code      Discount code.
	 * @return array<string,mixed>
	 */
	public static function quote( $member_id, $plan, $country, $code ) {
		$prices   = self::prices();
		$currency = self::currency_for( $country );
		$base     = 'yearly' === $plan ? (int) $prices['yearly'] : (int) $prices['monthly'];
		$used     = '';
		$charged  = $base;
		if ( 'monthly' === $plan && '' !== $code && self::code_applies( $member_id, $code ) ) {
			$row     = self::find_code( $code );
			$charged = (int) round( $base * ( 100 - (int) $row['percent'] ) / 100 );
			$used    = $code;
		}
		return array(
			'plan'     => $plan,
			'country'  => $country,
			'currency' => $currency,
			'base'     => $base,
			'charged'  => $charged,
			'tax'      => self::tax_inside( $charged, $country ),
			'code'     => $used,
		);
	}

	/**
	 * @param array<string,mixed> $event Notice.
	 */
	private static function apply_event( $event ) {
		$type   = (string) $event['type'];
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		if ( 'customer.subscription.deleted' === $type ) {
			$sub_id = isset( $object['id'] ) ? (string) $object['id'] : '';
			$row    = self::by_stripe_subscription( $sub_id );
			if ( ! $row ) {
				self::hold_cancel( $sub_id );
				return;
			}
			self::cancel_at_period_end( (int) $row->member_id );
			return;
		}
		if ( 'invoice.payment_failed' === $type ) {
			$row = self::by_stripe_subscription( isset( $object['subscription'] ) ? (string) $object['subscription'] : '' );
			if ( $row ) {
				self::mark_retry( (int) $row->member_id );
			}
			return;
		}
		if ( 'invoice.paid' === $type ) {
			self::apply_renewal( $object );
			return;
		}
		if ( 'checkout.session.completed' === $type ) {
			self::apply_checkout( $object );
		}
	}

	/**
	 * @param array<string,mixed> $object Checkout session object.
	 */
	private static function apply_checkout( $object ) {
		$session = isset( $object['id'] ) ? (string) $object['id'] : '';
		$stored  = self::take_quote( $session );
		if ( ! $stored ) {
			self::flag_mismatch( isset( $object['client_reference_id'] ) ? (int) $object['client_reference_id'] : 0, self::today_for( 0 ) );
			return;
		}
		$member_id = (int) $stored['member_id'];
		$amount    = isset( $object['amount_total'] ) ? (int) $object['amount_total'] : -1;
		if ( $amount !== (int) $stored['quote']['charged'] ) {
			self::flag_mismatch( $member_id, LLD_Members_Programme::local_today( $member_id ) );
			return;
		}
		$quote    = $stored['quote'];
		$today    = LLD_Members_Programme::local_today( $member_id );
		$ends     = self::add_period( $today, (string) $quote['plan'] );
		$stripe   = isset( $object['subscription'] ) ? sanitize_text_field( (string) $object['subscription'] ) : '';
		$payment  = isset( $object['payment_intent'] ) ? sanitize_text_field( (string) $object['payment_intent'] ) : $session;
		$fingerprint = isset( $object['fingerprint'] ) ? sanitize_text_field( (string) $object['fingerprint'] ) : '';
		$email    = isset( $object['email'] ) ? sanitize_email( (string) $object['email'] ) : self::email_of( $member_id );
		self::insert_order( $member_id, 'payment', $quote, $payment, $email, $fingerprint );
		self::save_sub(
			$member_id,
			array(
				'plan'                => (string) $quote['plan'],
				'access_state'        => 'Premium',
				'stripe_state'        => 'active',
				'started_on'          => $today,
				'period_ends_on'      => $ends,
				'locked_amount'       => (int) $quote['base'],
				'locked_currency'     => (string) $quote['currency'],
				'stripe_customer'     => isset( $object['customer'] ) ? sanitize_text_field( (string) $object['customer'] ) : '',
				'stripe_subscription' => $stripe,
				'premium_ended_on'    => null,
				'premium_ended_why'   => null,
			)
		);
		self::remember_card( $member_id, $fingerprint );
		self::note_referral( $member_id, isset( $stored['ref'] ) ? (string) $stored['ref'] : '', $email, $fingerprint );
		if ( self::cancel_was_held( $stripe ) ) {
			self::cancel_at_period_end( $member_id );
		}
		self::mail_member( $member_id, 'Your receipt', 'Your receipt. Amount ' . self::money( (int) $quote['charged'], (string) $quote['currency'] ) . ', tax included. Date ' . $today . '.' );
	}

	/**
	 * @param array<string,mixed> $object Invoice object.
	 */
	private static function apply_renewal( $object ) {
		$row = self::by_stripe_subscription( isset( $object['subscription'] ) ? (string) $object['subscription'] : '' );
		if ( ! $row ) {
			return;
		}
		$member_id = (int) $row->member_id;
		$next      = self::next_charge( $member_id );
		$amount    = isset( $object['amount_paid'] ) ? (int) $object['amount_paid'] : -1;
		if ( $amount !== (int) $next['charged'] ) {
			self::flag_mismatch( $member_id, LLD_Members_Programme::local_today( $member_id ) );
			return;
		}
		$today = LLD_Members_Programme::local_today( $member_id );
		$plan  = (string) $row->plan;
		if ( $row->pending_yearly_on && (string) $row->pending_yearly_on <= $today ) {
			$plan = 'yearly';
		}
		$payment = isset( $object['payment_intent'] ) ? sanitize_text_field( (string) $object['payment_intent'] ) : sanitize_text_field( (string) $object['id'] );
		$quote   = array(
			'plan'     => $plan,
			'country'  => 'gbp' === $next['currency'] ? 'GB' : ( 'eur' === $next['currency'] ? 'DE' : 'US' ),
			'currency' => $next['currency'],
			'base'     => (int) $row->locked_amount,
			'charged'  => $amount,
			'tax'      => self::tax_inside( $amount, 'gbp' === $next['currency'] ? 'GB' : ( 'eur' === $next['currency'] ? 'DE' : 'US' ) ),
			'code'     => '',
		);
		$order_id = self::insert_order( $member_id, 'renewal', $quote, $payment, self::email_of( $member_id ), '' );
		if ( ! empty( $next['reward_id'] ) ) {
			self::consume_reward( (int) $next['reward_id'], $order_id );
		}
		self::save_sub(
			$member_id,
			array(
				'plan'              => $plan,
				'access_state'      => 'Premium',
				'stripe_state'      => 'active',
				'period_ends_on'    => self::add_period( $today, $plan ),
				'pending_yearly_on' => null,
			)
		);
		self::clear_retry( $member_id );
		self::mail_member( $member_id, 'Your receipt', 'Your receipt. Amount ' . self::money( $amount, $next['currency'] ) . ', tax included. Date ' . $today . '.' );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function mark_retry( $member_id ) {
		$retries = get_option( 'lld_retries' );
		if ( ! is_array( $retries ) ) {
			$retries = array();
		}
		$retries[ (string) $member_id ] = LLD_Members_Programme::local_today( $member_id );
		update_option( 'lld_retries', $retries, false );
		self::save_sub( $member_id, array( 'stripe_state' => 'past_due' ) );
		self::mail_member( $member_id, 'Please update your card', 'Please update your card. Your Premium access continues while we retry for 7 days.' );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $why       expired, failed or refund.
	 * @param string $as_of     Local date the week is judged from.
	 */
	private static function end_premium( $member_id, $why, $as_of ) {
		$state = 'refund' === $why ? 'Refunded' : 'Premium ended';
		self::save_sub(
			$member_id,
			array(
				'access_state'      => $state,
				'stripe_state'      => 'ended',
				'premium_ended_on'  => $as_of,
				'premium_ended_why' => $why,
			)
		);
		self::close_week( $member_id, $as_of );
		if ( 'failed' === $why ) {
			self::mail_member( $member_id, 'Premium has ended', 'Premium has ended after a failed payment. The rest of your current track week stays open.' );
		}
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function cancel_at_period_end( $member_id ) {
		$sub = self::subscription_row( $member_id );
		if ( ! $sub ) {
			return;
		}
		self::save_sub(
			$member_id,
			array(
				'access_state' => 'cancelled',
				'stripe_state' => 'cancel_at_period_end',
			)
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function queue_yearly( $member_id ) {
		$sub = self::subscription_row( $member_id );
		if ( ! $sub || 'monthly' !== (string) $sub->plan || ! $sub->period_ends_on ) {
			return;
		}
		self::save_sub( $member_id, array( 'pending_yearly_on' => (string) $sub->period_ends_on ) );
	}

	/**
	 * @param int $order_id Order number.
	 * @return true|WP_Error
	 */
	private static function refund_order( $order_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'order' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $order_id ) );
		if ( ! $order || 'payment' !== (string) $order->kind ) {
			return new WP_Error( 'lld_refund', 'Only the first payment can be refunded.' );
		}
		$member_id = (int) $order->member_id;
		$today     = LLD_Members_Programme::local_today( $member_id );
		$paid_on   = substr( (string) $order->ordered_at, 0, 10 );
		$limit     = ( new DateTimeImmutable( $paid_on ) )->modify( '+14 days' )->format( 'Y-m-d' );
		if ( $today > $limit ) {
			return new WP_Error( 'lld_refund', 'Refunds of the first payment are available for 14 days.' );
		}
		$ref = 're_test_' . $order_id;
		self::insert_order(
			$member_id,
			'refund',
			array(
				'currency' => (string) $order->charged_currency,
				'base'     => (int) $order->catalogue_amount,
				'charged'  => (int) $order->charged_amount,
				'tax'      => (int) $order->tax_amount,
				'code'     => '',
				'country'  => (string) $order->billing_country,
			),
			$ref,
			(string) $order->billing_email,
			''
		);
		$wpdb->update( $table, array( 'status' => 'refunded' ), array( 'id' => $order_id ) );
		self::end_premium( $member_id, 'refund', $today );
		self::withdraw_rewards_for_order( $order_id );
		return true;
	}

	private static function retry_window() {
		$retries = get_option( 'lld_retries' );
		if ( ! is_array( $retries ) ) {
			return;
		}
		foreach ( $retries as $member_id => $from ) {
			$member_id = (int) $member_id;
			$today     = LLD_Members_Programme::local_today( $member_id );
			$due       = ( new DateTimeImmutable( (string) $from ) )->modify( '+7 days' )->format( 'Y-m-d' );
			if ( $today >= $due ) {
				self::end_premium( $member_id, 'failed', $today );
				unset( $retries[ (string) $member_id ] );
			}
		}
		update_option( 'lld_retries', $retries, false );
	}

	private static function close_elapsed() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'subscription' );
		$rows  = $wpdb->get_results( "SELECT member_id, period_ends_on FROM {$table} WHERE access_state = 'cancelled' AND period_ends_on IS NOT NULL" );
		foreach ( $rows as $row ) {
			$member_id = (int) $row->member_id;
			if ( LLD_Members_Programme::local_today( $member_id ) > (string) $row->period_ends_on ) {
				self::end_premium( $member_id, 'expired', (string) $row->period_ends_on );
			}
		}
	}

	private static function renewal_reminders() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'subscription' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE plan = 'yearly' AND access_state = 'Premium' AND period_ends_on IS NOT NULL" );
		foreach ( $rows as $row ) {
			$member_id = (int) $row->member_id;
			$today     = LLD_Members_Programme::local_today( $member_id );
			$remind    = ( new DateTimeImmutable( (string) $row->period_ends_on ) )->modify( '-7 days' )->format( 'Y-m-d' );
			if ( $today === $remind && (string) $row->renewal_reminder_on !== $today ) {
				self::mail_member( $member_id, 'Your plan renews in 7 days', 'Your yearly plan renews on ' . (string) $row->period_ends_on . '. ' . home_url( '/members/subscription/' ) );
				self::save_sub( $member_id, array( 'renewal_reminder_on' => $today ) );
			}
		}
	}

	private static function settle_rewards() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'referral' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'waiting' ORDER BY id ASC" );
		foreach ( $rows as $row ) {
			$order = self::order_row( (int) $row->friend_order_id );
			if ( ! $order ) {
				continue;
			}
			if ( 'refunded' === (string) $order->status ) {
				$wpdb->update( $table, array( 'status' => 'refunded' ), array( 'id' => (int) $row->id ) );
				continue;
			}
			$paid = substr( (string) $order->ordered_at, 0, 10 );
			$due  = ( new DateTimeImmutable( $paid ) )->modify( '+14 days' )->format( 'Y-m-d' );
			$today = LLD_Members_Programme::local_today( (int) $row->friend_member_id );
			if ( $today <= $due ) {
				continue;
			}
			if ( self::rewards_this_year( (int) $row->referrer_member_id, substr( $paid, 0, 4 ) ) >= 3 ) {
				$wpdb->update( $table, array( 'status' => 'limited' ), array( 'id' => (int) $row->id ) );
				continue;
			}
			if ( self::grant_pair( (int) $row->id, (int) $row->referrer_member_id, (int) $row->friend_member_id ) ) {
				$wpdb->update( $table, array( 'status' => 'rewarded' ), array( 'id' => (int) $row->id ) );
			}
		}
	}

	/**
	 * @param int $referral_id Referral.
	 * @param int $referrer    Referrer.
	 * @param int $friend      Friend.
	 */
	private static function grant_pair( $referral_id, $referrer, $friend ) {
		self::insert_reward( $referral_id, $referrer, 'referrer-' . $referral_id );
		if ( lld_members_test_routes_open() && get_option( 'lld_reward_stop' ) ) {
			delete_option( 'lld_reward_stop' );
			return false;
		}
		self::insert_reward( $referral_id, $friend, 'friend-' . $referral_id );
		return true;
	}

	/**
	 * @param int    $referral_id Referral.
	 * @param int    $member_id   Recipient.
	 * @param string $key         Operation key.
	 */
	private static function insert_reward( $referral_id, $member_id, $key ) {
		global $wpdb;
		$sub  = self::subscription_row( $member_id );
		$form = ( $sub && 'yearly' === (string) $sub->plan ) ? 'percent_13' : 'month_free';
		$wpdb->suppress_errors( true );
		$wpdb->insert(
			LLD_Members_Schema::table( 'reward' ),
			array(
				'referral_id'         => $referral_id,
				'recipient_member_id' => $member_id,
				'form'                => $form,
				'operation_key'       => $key,
				'status'              => 'pending',
			)
		);
		$wpdb->suppress_errors( false );
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $order_id  Order the reward reduced.
	 */
	private static function consume_reward( $reward_id, $order_id ) {
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'reward' ),
			array(
				'status'           => 'used',
				'used_on_order_id' => $order_id,
			),
			array( 'id' => $reward_id )
		);
	}

	/**
	 * @param int $order_id Friend's first payment.
	 */
	private static function withdraw_rewards_for_order( $order_id ) {
		global $wpdb;
		$referral = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'referral' ) . ' WHERE friend_order_id = %d', $order_id ) );
		if ( ! $referral ) {
			return;
		}
		$wpdb->update( LLD_Members_Schema::table( 'referral' ), array( 'status' => 'refunded' ), array( 'id' => (int) $referral->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'reward' ) . " SET status = 'withdrawn', withdrawn_on = %s, withdrawn_why = %s WHERE referral_id = %d AND status = 'pending'", lld_members_now_mysql(), 'refund', (int) $referral->id ) );
	}

	/**
	 * @param int    $member_id    Friend.
	 * @param string $code         Referral code.
	 * @param string $email        Email on the payment.
	 * @param string $fingerprint  Card fingerprint.
	 */
	private static function note_referral( $member_id, $code, $email, $fingerprint ) {
		$referrer = self::referrer_by_code( $code );
		if ( $referrer < 1 || $referrer === $member_id ) {
			return;
		}
		global $wpdb;
		$table  = LLD_Members_Schema::table( 'referral' );
		$self   = '';
		if ( 0 === strcasecmp( self::email_of( $referrer ), $email ) ) {
			$self = 'email';
		} elseif ( '' !== $fingerprint && self::card_belongs_to( $referrer, $fingerprint ) ) {
			$self = 'card';
		}
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE member_id = %d AND kind = 'payment' ORDER BY id DESC LIMIT 1", $member_id ) );
		$status   = '' !== $self ? 'self' : 'waiting';
		$wpdb->insert(
			$table,
			array(
				'referrer_member_id' => $referrer,
				'code'               => $code,
				'friend_member_id'   => $member_id,
				'friend_order_id'    => $order_id,
				'status'             => $status,
				'self_referral'      => '' !== $self ? $self : null,
			)
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $as_of     Date the ending week is judged from.
	 */
	private static function close_week( $member_id, $as_of ) {
		global $wpdb;
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row || $row->last_open_day ) {
			return;
		}
		$start = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(first_on) FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' WHERE enrolment_id = %d', (int) $row->id ) );
		if ( ! is_string( $start ) || '' === $start ) {
			return;
		}
		$start_d = new DateTimeImmutable( $start );
		$as      = new DateTimeImmutable( $as_of );
		if ( $as < $start_d ) {
			$as = $start_d;
		}
		$day  = (int) $start_d->diff( $as )->days + 1;
		$week = (int) ceil( $day / 7 );
		$last = $start_d->modify( '+' . ( ( $week * 7 ) - 1 ) . ' days' )->format( 'Y-m-d' );
		$wpdb->update( LLD_Members_Schema::table( 'enrolment' ), array( 'last_open_day' => $last ), array( 'id' => (int) $row->id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function ensure_closed_week( $member_id ) {
		if ( ! self::ended( $member_id ) ) {
			return;
		}
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row || $row->last_open_day ) {
			return;
		}
		$sub   = self::subscription_row( $member_id );
		$as_of = ( $sub && 'expired' === (string) $sub->premium_ended_why && $sub->period_ends_on ) ? (string) $sub->period_ends_on : LLD_Members_Programme::local_today( $member_id );
		self::close_week( $member_id, $as_of );
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	private static function programme_date( $member_id, $day ) {
		global $wpdb;
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row ) {
			return '';
		}
		$start = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(first_on) FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' WHERE enrolment_id = %d', (int) $row->id ) );
		if ( ! is_string( $start ) || '' === $start ) {
			return '';
		}
		return ( new DateTimeImmutable( $start ) )->modify( '+' . ( (int) $day - 1 ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * @return array{yearly:int,monthly:int}
	 */
	private static function prices() {
		$stored = get_option( 'lld_prices' );
		$yearly = 5999;
		$monthly = 799;
		if ( is_array( $stored ) ) {
			$yearly  = isset( $stored['yearly'] ) ? (int) $stored['yearly'] : $yearly;
			$monthly = isset( $stored['monthly'] ) ? (int) $stored['monthly'] : $monthly;
		}
		return array(
			'yearly'  => $yearly,
			'monthly' => $monthly,
		);
	}

	/**
	 * @param int    $monthly Monthly cents.
	 * @param int    $yearly  Yearly cents.
	 * @param string $code    Optional new code.
	 * @param int    $percent Percent off the first month.
	 */
	private static function save_prices( $monthly, $yearly, $code, $percent ) {
		update_option( 'lld_prices', array( 'monthly' => max( 0, $monthly ), 'yearly' => max( 0, $yearly ) ), false );
		$code = strtoupper( trim( $code ) );
		if ( '' === $code || $percent < 1 ) {
			return;
		}
		$codes   = self::codes();
		$codes[] = array(
			'code'       => $code,
			'percent'    => min( 100, $percent ),
			'expires_on' => self::launch_expires(),
		);
		update_option( 'lld_codes', $codes, false );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function codes() {
		$stored = get_option( 'lld_codes' );
		if ( ! is_array( $stored ) || ! $stored ) {
			return array(
				array(
					'code'       => 'LAUNCH',
					'percent'    => 50,
					'expires_on' => self::launch_expires(),
				),
			);
		}
		return $stored;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $code      Code.
	 */
	private static function code_applies( $member_id, $code ) {
		$row = self::find_code( $code );
		if ( ! $row ) {
			return false;
		}
		$today = $member_id ? LLD_Members_Programme::local_today( $member_id ) : self::today_for( 0 );
		if ( $today > (string) $row['expires_on'] ) {
			return false;
		}
		global $wpdb;
		$prior = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE member_id = %d AND kind = 'payment' AND status = 'paid'", $member_id ) );
		return 0 === $prior;
	}

	/**
	 * @param string $code Code.
	 * @return array<string,mixed>|null
	 */
	private static function find_code( $code ) {
		foreach ( self::codes() as $row ) {
			if ( isset( $row['code'] ) && strtoupper( (string) $row['code'] ) === strtoupper( $code ) ) {
				return $row;
			}
		}
		return null;
	}

	private static function launch_on() {
		$stored = get_option( 'lld_launch_on' );
		if ( ! is_string( $stored ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored ) ) {
			$stored = gmdate( 'Y-m-d' );
			update_option( 'lld_launch_on', $stored, false );
		}
		return $stored;
	}

	private static function launch_expires() {
		return ( new DateTimeImmutable( self::launch_on() ) )->modify( '+30 days' )->format( 'Y-m-d' );
	}

	/**
	 * @param int    $cents    Minor units.
	 * @param string $country  Country.
	 */
	private static function tax_inside( $cents, $country ) {
		$rate = 0;
		if ( 'GB' === $country ) {
			$rate = 20;
		} elseif ( 'DE' === $country ) {
			$rate = 19;
		}
		if ( 0 === $rate ) {
			return 0;
		}
		return (int) round( $cents * $rate / ( 100 + $rate ) );
	}

	/**
	 * @param string $country Country.
	 */
	private static function currency_for( $country ) {
		if ( 'GB' === $country ) {
			return 'gbp';
		}
		if ( 'DE' === $country ) {
			return 'eur';
		}
		return 'usd';
	}

	/**
	 * @param int    $cents    Minor units.
	 * @param string $currency Currency.
	 */
	private static function money( $cents, $currency ) {
		$major = number_format( $cents / 100, 2, '.', '' );
		if ( 'gbp' === $currency ) {
			return '£' . $major;
		}
		if ( 'eur' === $currency ) {
			return '€' . $major;
		}
		return 'US$' . $major;
	}

	/**
	 * @param array<string,mixed> $quote Quote.
	 */
	private static function tax_line( $quote ) {
		$name = 'US' === $quote['country'] ? 'no sales tax' : ( 'GB' === $quote['country'] ? 'GB VAT' : 'DE VAT' );
		return 'Tax inside this price: ' . self::money( (int) $quote['tax'], (string) $quote['currency'] ) . ' (' . $name . ')';
	}

	/**
	 * @param string               $session   Session id.
	 * @param array<string,mixed>  $quote     Quote.
	 * @param int                  $member_id Member.
	 * @param string               $ref       Referral code.
	 */
	private static function store_quote( $session, $quote, $member_id, $ref ) {
		$all = get_option( 'lld_quotes' );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $session ] = array(
			'quote'     => $quote,
			'member_id' => $member_id,
			'ref'       => $ref,
		);
		if ( count( $all ) > 40 ) {
			$all = array_slice( $all, -20, null, true );
		}
		update_option( 'lld_quotes', $all, false );
	}

	/**
	 * @param string $session Session id.
	 * @return array<string,mixed>|null
	 */
	private static function take_quote( $session ) {
		$all = get_option( 'lld_quotes' );
		if ( ! is_array( $all ) || ! isset( $all[ $session ] ) ) {
			return null;
		}
		return $all[ $session ];
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	private static function next_charge( $member_id ) {
		$sub      = self::subscription_row( $member_id );
		$plan     = $sub ? (string) $sub->plan : 'monthly';
		$prices   = self::prices();
		$currency = ( $sub && $sub->locked_currency ) ? (string) $sub->locked_currency : 'usd';
		$base     = ( $sub && $sub->locked_amount ) ? (int) $sub->locked_amount : ( 'yearly' === $plan ? (int) $prices['yearly'] : (int) $prices['monthly'] );
		$reward   = self::pending_reward( $member_id );
		$charged  = $base;
		$reward_id = 0;
		if ( $reward ) {
			$reward_id = (int) $reward->id;
			$charged   = 'percent_13' === (string) $reward->form ? (int) round( $base * 87 / 100 ) : 0;
		}
		return array(
			'charged'   => $charged,
			'currency'  => $currency,
			'reward_id' => $reward_id,
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function pending_reward( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'reward' ) . " WHERE recipient_member_id = %d AND status = 'pending' ORDER BY id ASC LIMIT 1", $member_id ) );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $year      Calendar year.
	 */
	private static function rewards_this_year( $member_id, $year ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'referral' ) . " WHERE referrer_member_id = %d AND status = 'rewarded' AND friend_order_id IN (SELECT id FROM " . LLD_Members_Schema::table( 'order' ) . ' WHERE ordered_at LIKE %s)',
				$member_id,
				$wpdb->esc_like( $year ) . '%'
			)
		);
	}

	/**
	 * @param int                  $member_id Member.
	 * @param string               $kind      payment, renewal or refund.
	 * @param array<string,mixed>  $quote     Amounts.
	 * @param string               $payment   Stripe payment id.
	 * @param string               $email     Billing email.
	 * @param string               $fingerprint Card fingerprint, stored aside from the order row.
	 */
	private static function insert_order( $member_id, $kind, $quote, $payment, $email, $fingerprint ) {
		unset( $fingerprint );
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'order' ),
			array(
				'member_id'          => $member_id,
				'ordered_at'         => LLD_Members_Programme::local_today( $member_id ) . ' 12:00:00',
				'kind'               => $kind,
				'catalogue_amount'   => (int) $quote['base'],
				'catalogue_currency' => (string) $quote['currency'],
				'charged_amount'     => (int) $quote['charged'],
				'charged_currency'   => (string) $quote['currency'],
				'tax_amount'         => (int) $quote['tax'],
				'tax_currency'       => (string) $quote['currency'],
				'discount_code'      => '' !== (string) $quote['code'] ? (string) $quote['code'] : null,
				'status'             => 'refund' === $kind ? 'refunded' : 'paid',
				'stripe_payment'     => $payment,
				'billing_email'      => $email,
				'billing_country'    => isset( $quote['country'] ) ? (string) $quote['country'] : null,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function receipt_list( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'order' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ) );
		$html = '<h2>Receipts</h2><ul>';
		if ( ! $rows ) {
			return $html . '<li>No receipts yet.</li></ul>';
		}
		foreach ( $rows as $row ) {
			$html .= '<li>' . esc_html( (string) $row->kind ) . ' ' . esc_html( self::money( (int) $row->charged_amount, (string) $row->charged_currency ) );
			$html .= ' tax ' . esc_html( self::money( (int) $row->tax_amount, (string) $row->tax_currency ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function reward_list( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'reward' ) . " WHERE recipient_member_id = %d AND status = 'pending' ORDER BY id ASC", $member_id ) );
		$html = '';
		foreach ( $rows as $row ) {
			$label = 'percent_13' === (string) $row->form ? '13 % off your next yearly renewal' : 'One month free';
			$html .= '<p>' . esc_html( $label ) . '</p>';
		}
		return $html;
	}

	private static function admin_refunds() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, member_id, charged_amount, charged_currency, ordered_at, status FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE kind = 'payment' ORDER BY id DESC LIMIT 20" );
		if ( ! $rows ) {
			echo '<p>No payments yet.</p>';
			return;
		}
		foreach ( $rows as $row ) {
			echo '<form method="post"><p>';
			wp_nonce_field( 'lld_refund', 'lld_refund_nonce' );
			echo 'Order ' . (int) $row->id . ' member ' . (int) $row->member_id . ' ' . esc_html( self::money( (int) $row->charged_amount, (string) $row->charged_currency ) ) . ' ' . esc_html( (string) $row->status );
			echo ' <input type="hidden" name="lld_order" value="' . (int) $row->id . '">';
			if ( 'paid' === (string) $row->status ) {
				echo ' <button class="button">Refund</button>';
			}
			echo '</p></form>';
		}
	}

	/**
	 * @param int                  $member_id Member number.
	 * @param array<string,mixed>  $fields    Columns.
	 */
	private static function save_sub( $member_id, $fields ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'subscription' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d", $member_id ) );
		if ( $id ) {
			$nulls = array();
			foreach ( array( 'pending_yearly_on', 'premium_ended_on', 'premium_ended_why' ) as $column ) {
				if ( array_key_exists( $column, $fields ) && null === $fields[ $column ] ) {
					$nulls[] = $column;
					unset( $fields[ $column ] );
				}
			}
			if ( $fields ) {
				$wpdb->update( $table, $fields, array( 'id' => $id ) );
			}
			foreach ( $nulls as $column ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET `{$column}` = NULL WHERE id = %d", $id ) );
			}
			return;
		}
		$fields['member_id']    = $member_id;
		$fields['plan']         = isset( $fields['plan'] ) ? $fields['plan'] : 'free';
		$fields['access_state'] = isset( $fields['access_state'] ) ? $fields['access_state'] : 'free';
		$wpdb->insert( $table, $fields );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function subscription_row( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
	}

	/**
	 * @param string $stripe_subscription Stripe subscription id.
	 */
	private static function by_stripe_subscription( $stripe_subscription ) {
		if ( '' === $stripe_subscription ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE stripe_subscription = %s', $stripe_subscription ) );
	}

	/**
	 * @param int $order_id Order.
	 */
	private static function order_row( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'order' ) . ' WHERE id = %d', $order_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function in_period( $member_id ) {
		$sub = self::subscription_row( $member_id );
		if ( ! $sub || ! $sub->period_ends_on ) {
			return false;
		}
		return LLD_Members_Programme::local_today( $member_id ) <= (string) $sub->period_ends_on;
	}

	/**
	 * @param string $from Start date.
	 * @param string $plan yearly or monthly.
	 */
	private static function add_period( $from, $plan ) {
		$modify = 'yearly' === $plan ? '+1 year' : '+1 month';
		return ( new DateTimeImmutable( $from ) )->modify( $modify )->format( 'Y-m-d' );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function referral_code( $member_id ) {
		$map = get_option( 'lld_ref_codes' );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$key = (string) (int) $member_id;
		if ( empty( $map[ $key ] ) ) {
			$map[ $key ] = 'r' . substr( hash( 'sha256', 'lld-ref-' . $key ), 0, 10 );
			update_option( 'lld_ref_codes', $map, false );
		}
		return (string) $map[ $key ];
	}

	/**
	 * @param string $code Referral code.
	 */
	private static function referrer_by_code( $code ) {
		$map = get_option( 'lld_ref_codes' );
		if ( ! is_array( $map ) || '' === $code ) {
			return 0;
		}
		$found = array_search( $code, $map, true );
		return false === $found ? 0 : (int) $found;
	}

	/**
	 * @param int    $member_id    Member number.
	 * @param string $fingerprint  Card fingerprint.
	 */
	private static function remember_card( $member_id, $fingerprint ) {
		if ( '' === $fingerprint ) {
			return;
		}
		$list = get_option( 'lld_card_fp' );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[] = array(
			'member_id'   => $member_id,
			'fingerprint' => $fingerprint,
		);
		update_option( 'lld_card_fp', $list, false );
	}

	/**
	 * @param int    $member_id   Member number.
	 * @param string $fingerprint Card fingerprint.
	 */
	private static function card_belongs_to( $member_id, $fingerprint ) {
		$list = get_option( 'lld_card_fp' );
		if ( ! is_array( $list ) ) {
			return false;
		}
		foreach ( $list as $row ) {
			if ( (int) $row['member_id'] === $member_id && (string) $row['fingerprint'] === $fingerprint ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $stripe_subscription Stripe subscription id.
	 */
	private static function hold_cancel( $stripe_subscription ) {
		if ( '' === $stripe_subscription ) {
			return;
		}
		$held = get_option( 'lld_held_cancels' );
		if ( ! is_array( $held ) ) {
			$held = array();
		}
		$held[] = $stripe_subscription;
		update_option( 'lld_held_cancels', $held, false );
	}

	/**
	 * @param string $stripe_subscription Stripe subscription id.
	 */
	private static function cancel_was_held( $stripe_subscription ) {
		$held = get_option( 'lld_held_cancels' );
		if ( ! is_array( $held ) ) {
			return false;
		}
		return in_array( $stripe_subscription, $held, true );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $on        Local date it was noticed.
	 */
	private static function flag_mismatch( $member_id, $on ) {
		if ( $member_id < 1 ) {
			return;
		}
		$list = get_option( 'lld_mismatches' );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[] = array(
			'member_id'  => $member_id,
			'flagged_on' => $on,
		);
		update_option( 'lld_mismatches', $list, false );
	}

	private static function mismatch_mail() {
		$list = get_option( 'lld_mismatches' );
		if ( ! is_array( $list ) || ! $list ) {
			return;
		}
		$keep = array();
		foreach ( $list as $row ) {
			$member_id = (int) $row['member_id'];
			$today     = LLD_Members_Programme::local_today( $member_id );
			if ( $today > (string) $row['flagged_on'] ) {
				wp_mail( (string) get_option( 'admin_email' ), 'A payment does not match a member', 'A payment does not match member ' . $member_id . '. Please check it.' );
			} else {
				$keep[] = $row;
			}
		}
		update_option( 'lld_mismatches', $keep, false );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function clear_retry( $member_id ) {
		$retries = get_option( 'lld_retries' );
		if ( ! is_array( $retries ) ) {
			return;
		}
		unset( $retries[ (string) $member_id ] );
		update_option( 'lld_retries', $retries, false );
	}

	/**
	 * @param string $payload Raw body.
	 * @param string $header  Stripe-Signature header.
	 */
	private static function valid_signature( $payload, $header ) {
		if ( ! is_string( $header ) || ! preg_match( '/t=(\d+),v1=([a-f0-9]+)/', $header, $match ) ) {
			return false;
		}
		$secret = self::webhook_secret();
		if ( '' === $secret ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $match[1] . '.' . $payload, $secret );
		if ( ! hash_equals( $expected, $match[2] ) ) {
			return false;
		}
		return abs( time() - (int) $match[1] ) < 300;
	}

	private static function webhook_secret() {
		if ( defined( 'LLD_STRIPE_WEBHOOK' ) && '' !== (string) LLD_STRIPE_WEBHOOK ) {
			return (string) LLD_STRIPE_WEBHOOK;
		}
		if ( lld_members_is_production() ) {
			return '';
		}
		return lld_members_test_secret();
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $subject   Subject.
	 * @param string $body      Plain text with no health details.
	 */
	private static function mail_member( $member_id, $subject, $body ) {
		$email = self::email_of( $member_id );
		if ( '' === $email ) {
			return;
		}
		wp_mail( $email, $subject, $body . "\n" );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function email_of( $member_id ) {
		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		return is_string( $email ) ? $email : '';
	}

	/**
	 * @param string $email Email.
	 */
	private static function member_by_email( $email ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
	}

	/**
	 * @param int $member_id Member, or 0 for the server date.
	 */
	private static function today_for( $member_id ) {
		if ( $member_id > 0 ) {
			return LLD_Members_Programme::local_today( $member_id );
		}
		return gmdate( 'Y-m-d' );
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	private static function snapshot( $member_id ) {
		global $wpdb;
		$sub = self::subscription_row( $member_id );
		return array(
			'subscription' => $sub,
			'orders'       => $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, charged_amount, tax_amount, charged_currency, discount_code, status FROM ' . LLD_Members_Schema::table( 'order' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A ),
			'rewards'      => $wpdb->get_results( $wpdb->prepare( 'SELECT id, form, status, operation_key FROM ' . LLD_Members_Schema::table( 'reward' ) . ' WHERE recipient_member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A ),
			'referrals'    => $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, self_referral, friend_member_id FROM ' . LLD_Members_Schema::table( 'referral' ) . ' WHERE referrer_member_id = %d OR friend_member_id = %d ORDER BY id ASC', $member_id, $member_id ), ARRAY_A ),
			'next'         => $member_id ? self::next_charge( $member_id ) : array(),
			'app'          => ( $sub && 'yearly' === (string) $sub->plan && self::paid_open( $member_id ) ) ? 'included' : 'not included',
		);
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private static function sales_pages() {
		return array(
			'healthy-weight' => array(
				'title'       => 'Healthy Weight',
				'description' => 'A four-week Healthy Weight track for people who want a steady weight.',
				'for'         => 'People who want to reach and keep a healthy weight.',
				'not'         => 'Anyone who has been told to avoid weight-loss eating, including during pregnancy.',
				'activity'    => 'A ten-minute walk after lunch.',
				'meal'        => 'Oats with fruit and yoghurt.',
				'safety'      => 'This is not a diet for pregnancy or an eating disorder.',
			),
			'blood-sugar'    => array(
				'title'       => 'Blood Sugar Balance',
				'description' => 'A four-week Blood Sugar Balance track for people with raised diabetes risk.',
				'for'         => 'People with raised diabetes risk or prediabetes who want to lower it.',
				'not'         => 'Anyone looking for a diagnosis or a change to prescribed treatment.',
				'activity'    => 'A short walk after a meal.',
				'meal'        => 'Eggs with vegetables and wholegrain toast.',
				'safety'      => 'This does not replace care from your own doctor.',
			),
			'fitness'        => array(
				'title'       => 'Fitness and Strength',
				'description' => 'A four-week Fitness and Strength track for adults who want to get fitter.',
				'for'         => 'Adults of any age who want to get fitter and stronger.',
				'not'         => 'Anyone who needs a personal exercise plan from a clinician first.',
				'activity'    => 'Sit to stand from a sturdy chair, at your own pace.',
				'meal'        => 'Yoghurt with fruit and nuts.',
				'safety'      => 'Warm up, stop if you feel pain, and check with a doctor if you need to.',
			),
			'sleep-stress'   => array(
				'title'       => 'Sleep and Stress',
				'description' => 'A four-week Sleep and Stress track for better sleep and calmer days.',
				'for'         => 'People who want better sleep and less day-to-day stress.',
				'not'         => 'Anyone in a mental health crisis. Seek urgent help instead.',
				'activity'    => 'A five-minute wind-down before bed.',
				'meal'        => 'A regular evening meal, earlier when you can.',
				'safety'      => 'This is not treatment for anxiety, depression or a sleep disorder.',
			),
		);
	}
}
