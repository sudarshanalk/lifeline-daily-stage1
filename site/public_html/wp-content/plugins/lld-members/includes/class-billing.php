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
		add_action( 'lld_members_notice_retry', array( __CLASS__, 'retry_notices' ) );
		add_action( 'lld_members_erase_account', array( __CLASS__, 'erase_member' ) );
		if ( ! wp_next_scheduled( 'lld_members_notice_retry' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'lld_members_notice_retry' );
		}
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
	 * Paid Memberships Pro levels for the two Premium plans (Data Model P1). Free has no level.
	 */
	const PMPRO_LEVELS = array(
		'yearly'  => array(
			'name'   => 'Premium yearly',
			'period' => 'Year',
		),
		'monthly' => array(
			'name'   => 'Premium monthly',
			'period' => 'Month',
		),
	);

	/**
	 * Database update for payments. Safe to run any number of times; makes no Stripe call.
	 */
	public static function db_update() {
		self::rename_statuses();
		self::sync_pmpro_levels();
	}

	/**
	 * Earlier builds used their own status names. Move them to the Data Model's (P3, P5) once.
	 */
	private static function rename_statuses() {
		global $wpdb;
		$order    = LLD_Members_Schema::table( 'order' );
		$referral = LLD_Members_Schema::table( 'referral' );
		$wpdb->query( "UPDATE {$order} SET status = 'succeeded' WHERE status = 'paid' OR ( kind = 'refund' AND status = 'refunded' )" );
		$wpdb->query( "UPDATE {$referral} SET status = 'qualified' WHERE status = 'rewarded'" );
		foreach ( array( 'limited' => 'limit', 'self' => 'self', 'refunded' => 'refunded' ) as $old => $why ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$referral} SET status = 'not_qualified', not_qualified_why = %s WHERE status = %s", $why, $old ) );
		}
	}

	/**
	 * Create or update the Premium levels in Paid Memberships Pro at the current catalogue prices.
	 * Levels never take sign-ups through Paid Memberships Pro's own checkout: Stripe Checkout is the only way in.
	 *
	 * @return array<string,int> Level id for each plan; empty when Paid Memberships Pro is not installed.
	 */
	public static function sync_pmpro_levels() {
		global $wpdb;
		$table = $wpdb->prefix . 'pmpro_membership_levels';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$prices  = self::prices();
		$stored  = get_option( 'lld_pmpro_levels' );
		$stored  = is_array( $stored ) ? $stored : array();
		$ids     = array();
		foreach ( self::PMPRO_LEVELS as $plan => $level ) {
			$amount = number_format( (int) $prices[ $plan ] / 100, 2, '.', '' );
			$row    = array(
				'name'              => $level['name'],
				'description'       => 'Lifeline Daily Premium, paid ' . $plan . ' through Stripe. Tax included.',
				'confirmation'      => '',
				'initial_payment'   => $amount,
				'billing_amount'    => $amount,
				'cycle_number'      => 1,
				'cycle_period'      => $level['period'],
				'billing_limit'     => 0,
				'trial_amount'      => 0,
				'trial_limit'       => 0,
				'allow_signups'     => 0,
				'expiration_number' => 0,
			);
			$row = array_intersect_key( $row, array_flip( $columns ) );
			$id  = isset( $stored[ $plan ] ) ? (int) $stored[ $plan ] : 0;
			if ( $id > 0 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ) ) {
				$id = 0;
			}
			if ( $id < 1 ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s ORDER BY id ASC LIMIT 1", $level['name'] ) );
			}
			if ( $id > 0 ) {
				$wpdb->update( $table, $row, array( 'id' => $id ) );
			} elseif ( false !== $wpdb->insert( $table, $row ) ) {
				$id = (int) $wpdb->insert_id;
			}
			if ( $id > 0 ) {
				$ids[ $plan ] = $id;
			}
		}
		if ( $ids !== $stored ) {
			update_option( 'lld_pmpro_levels', $ids, false );
		}
		return $ids;
	}

	/**
	 * Account deletion (S7): remove what payments keep about the member in wp_options. Card fingerprints and the
	 * referral code go; so do quotes, retry windows and mismatch notes that name the member.
	 *
	 * @param int $member_id Member number.
	 */
	public static function erase_member( $member_id ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return;
		}
		$cards = get_option( 'lld_card_fp' );
		if ( is_array( $cards ) ) {
			$kept = array_values(
				array_filter(
					$cards,
					static function ( $row ) use ( $member_id ) {
						return ! is_array( $row ) || (int) $row['member_id'] !== $member_id;
					}
				)
			);
			if ( count( $kept ) !== count( $cards ) ) {
				update_option( 'lld_card_fp', $kept, false );
			}
		}
		$codes = get_option( 'lld_ref_codes' );
		if ( is_array( $codes ) && array_key_exists( (string) $member_id, $codes ) ) {
			unset( $codes[ (string) $member_id ] );
			update_option( 'lld_ref_codes', $codes, false );
		}
		$quotes = get_option( 'lld_quotes' );
		if ( is_array( $quotes ) ) {
			$kept = array_filter(
				$quotes,
				static function ( $row ) use ( $member_id ) {
					return ! is_array( $row ) || (int) $row['member_id'] !== $member_id;
				}
			);
			if ( count( $kept ) !== count( $quotes ) ) {
				update_option( 'lld_quotes', $kept, false );
			}
		}
		$notes = get_option( 'lld_mismatches' );
		if ( is_array( $notes ) ) {
			$kept = array_values(
				array_filter(
					$notes,
					static function ( $row ) use ( $member_id ) {
						return ! is_array( $row ) || (int) $row['member_id'] !== $member_id;
					}
				)
			);
			if ( count( $kept ) !== count( $notes ) ) {
				update_option( 'lld_mismatches', $kept, false );
			}
		}
		self::clear_retry( $member_id );
	}

	/**
	 * What wp_options still holds about one member, for the erasure checks.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,int>
	 */
	public static function member_options( $member_id ) {
		$member_id = (int) $member_id;
		$count     = static function ( $list, $key = 'member_id' ) use ( $member_id ) {
			$n = 0;
			foreach ( is_array( $list ) ? $list : array() as $row ) {
				if ( is_array( $row ) && isset( $row[ $key ] ) && (int) $row[ $key ] === $member_id ) {
					++$n;
				}
			}
			return $n;
		};
		$codes = get_option( 'lld_ref_codes' );
		return array(
			'card_fingerprints' => $count( get_option( 'lld_card_fp' ) ),
			'referral_code'     => is_array( $codes ) && array_key_exists( (string) $member_id, $codes ) ? 1 : 0,
			'quotes'            => $count( get_option( 'lld_quotes' ) ),
			'mismatches'        => $count( get_option( 'lld_mismatches' ) ),
			'retries'           => $count( self::retries() ),
		);
	}

	/**
	 * Premium now, including cancelled in its paid period and the failed-payment grace period.
	 *
	 * @param int $member_id Member number.
	 */
	public static function has_premium( $member_id ) {
		return (int) $member_id > 0 && self::paid_open( (int) $member_id );
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
		return LLD_Members_Programme::open_period_days( $member_id, (int) $row->id ) <= 7;
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
		self::purge_notices();
	}

	/**
	 * Public pricing page.
	 */
	public static function pricing() {
		$prices = self::prices();
		$main   = '<h1>Pricing</h1>';
		$main  .= '<article data-lld-plan="yearly"><p>best value</p><p>' . esc_html( self::money( $prices['yearly'], 'usd' ) ) . ' a year</p><p>tax included</p>';
		$main  .= '<p>What\'s included: weeks 2 to 4, meal plans, workouts and the food diary.</p>';
		$main  .= '<p>How to cancel: cancel from My subscription. Access continues to the end of the paid period.</p>';
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
		$main  .= '<h2>Testimonials</h2>';
		$quotes = class_exists( 'LLD_Members_Growth' ) ? LLD_Members_Growth::testimonial_html() : '';
		$main  .= '' !== $quotes ? $quotes : '<p>Testimonials appear here once the owner approves them.</p>';
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
		$which = 'diabetes' === $which ? 'diabetes' : 'protein';
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
		$member_id = class_exists( 'LLD_Members_Access' ) ? LLD_Members_Access::current_member_id() : 0;
		$can_save  = $member_id > 0 && LLD_Members_Account::has_consent( $member_id, 'health' );
		$saved     = $can_save && self::save_calculator_result( $member_id, $which );
		$main      = '<h1>' . esc_html( $title ) . '</h1>';
		$main     .= '<iframe class="lld-tool-frame" title="' . esc_attr( $title ) . '" src="' . esc_url( $src ) . '" style="width:100%;height:900px;border:0"></iframe>';
		if ( $can_save ) {
			$main .= $saved ? '<p>Saved</p>' : '';
			$main .= '<form method="post" id="lld-save-result" data-calculator="' . esc_attr( $which ) . '" hidden>';
			$main .= wp_nonce_field( 'lld_result', 'lld_result_nonce', true, false );
			$main .= '<input type="hidden" name="lld_headline" value="">';
			$main .= '<input type="hidden" name="lld_unit" value="">';
			$main .= '<input type="hidden" name="lld_band" value="">';
			$main .= '<input type="hidden" name="lld_version" value="">';
			$main .= lld_button( array( 'label' => 'Save this result to my profile' ) );
			$main .= '</form>';
			$main .= '<script>(function(){var form=document.getElementById("lld-save-result");if(!form)return;window.addEventListener("message",function(event){if(event.origin!==location.origin)return;var data=event.data||{};if(data.type!=="lld-calculator-result"||data.calculator!==form.getAttribute("data-calculator"))return;form.querySelector("[name=lld_headline]").value=String(data.headline||"").slice(0,64);form.querySelector("[name=lld_unit]").value=String(data.unit||"").slice(0,64);form.querySelector("[name=lld_band]").value=String(data.band||"").slice(0,64);form.querySelector("[name=lld_version]").value=String(data.version||"").slice(0,64);form.hidden=false;});})();</script>';
		}
		$main .= '<p data-lld-track-link><a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></p>';
		if ( class_exists( 'LLD_Members_Growth' ) ) {
			LLD_Members_Growth::queue( 'calculator_used', array( 'calculator' => 'diabetes' === $which ? 'diabetes' : 'protein' ) );
			$guide = 'diabetes' === $which ? 'blood-sugar' : 'fitness';
			$main .= LLD_Members_Growth::guide_form( $guide, 'guide' );
		}
		lld_members_layout(
			array(
				'title'       => $title,
				'description' => $desc,
				'main'        => $main,
				'signed_in'   => $member_id > 0,
			)
		);
	}

	/**
	 * Store the headline, unit and band from the landing page. Answers are not posted.
	 *
	 * @param int    $member_id Member number.
	 * @param string $which     protein or diabetes.
	 */
	private static function save_calculator_result( $member_id, $which ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['lld_result_nonce'] ) ) {
			return false;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_result_nonce'] ) ), 'lld_result' ) ) {
			return false;
		}
		$headline = isset( $_POST['lld_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_headline'] ) ) : '';
		$unit     = isset( $_POST['lld_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_unit'] ) ) : '';
		$band     = isset( $_POST['lld_band'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_band'] ) ) : '';
		$version  = isset( $_POST['lld_version'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_version'] ) ) : '';
		$headline = substr( $headline, 0, 64 );
		$unit     = substr( $unit, 0, 64 );
		$band     = substr( $band, 0, 64 );
		$version  = substr( $version, 0, 64 );
		if ( '' === $headline || '' === $band || false !== strpos( $headline . $unit . $band, '@' ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'calculator_result' ),
			array(
				'member_id'           => $member_id,
				'calculator'          => $which,
				'saved_at'            => current_time( 'mysql', true ),
				'headline'            => $headline,
				'unit'                => '' !== $unit ? $unit : 'result',
				'band'                => $band,
				'calculator_version'  => '' !== $version ? $version : 'unversioned',
			)
		);
		return true;
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
		if ( self::live_keys_refused() ) {
			$main .= '<div class="lld-fail" role="alert"><p>Payments are switched off on this site.</p></div>';
			LLD_Members_Personal::render( 'Checkout', $main );
			return;
		}
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
		if ( self::has_premium( $member_id ) ) {
			// A second checkout would start a second subscription while the first keeps charging.
			$main .= '<p>You already have Premium. No payment was taken.</p>';
			$main .= lld_button( array( 'label' => 'My subscription', 'href' => home_url( '/members/subscription/' ) ) );
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
		// The unique notice_ref decides who processes a notice: only the request that wrote the row goes on.
		$quiet   = $wpdb->suppress_errors( true );
		$written = $wpdb->insert(
			$table,
			array(
				'notice_ref'       => $ref,
				'notice_type'      => sanitize_text_field( (string) $event['type'] ),
				'received_at'      => gmdate( 'Y-m-d H:i:s' ),
				'processing_state' => 'received',
				'attempts'         => 0,
				'payload'          => $raw,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		$wpdb->suppress_errors( $quiet );
		if ( 1 !== $written ) {
			$seen = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE notice_ref = %s", $ref ) );
			if ( $seen ) {
				return new WP_REST_Response( array( 'replay' => true ), 200 );
			}
			// Not recorded: Stripe sends it again after a non-2xx answer, so nothing is lost.
			return new WP_REST_Response( array( 'message' => 'not recorded' ), 500 );
		}
		$state = self::process_notice( (int) $wpdb->insert_id, $event );
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'state' => $state,
			),
			200
		);
	}

	/**
	 * Hours to wait after each failed attempt: retries at 1, 3, 7, 15 and 24 hours (T4: 5 times over 24 hours).
	 */
	const NOTICE_RETRY_HOURS = array( 1, 2, 4, 8, 9 );

	/**
	 * True while a notice is applied: a failed write then throws and the notice is retried.
	 *
	 * @var bool
	 */
	private static $in_notice = false;

	/**
	 * Apply one recorded notice inside a transaction, then mark it processed, retrying or failed.
	 *
	 * @param int                 $id    Notice row.
	 * @param array<string,mixed> $event Notice.
	 * @return string processed, retrying or failed.
	 */
	private static function process_notice( $id, $event ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'stripe_notice' );
		$error = '';
		$wpdb->query( 'START TRANSACTION' );
		self::$in_notice = true;
		try {
			$forced = lld_members_test_routes_open() ? (string) get_option( 'lld_notice_fail' ) : '';
			if ( '' !== $forced && $forced === (string) $event['id'] ) {
				throw new RuntimeException( 'Processing stopped for a test.' );
			}
			self::apply_event( $event );
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$error = '' !== $e->getMessage() ? $e->getMessage() : get_class( $e );
		}
		self::$in_notice = false;
		$attempts = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id ) );
		if ( '' === $error ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET processing_state = 'processed', attempts = %d, last_error = NULL, next_attempt_at = NULL, payload = NULL WHERE id = %d", $attempts, $id ) );
			return 'processed';
		}
		$error = substr( sanitize_text_field( $error ), 0, 250 );
		$retry = $attempts - 1;
		if ( $retry >= count( self::NOTICE_RETRY_HOURS ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET processing_state = 'failed', attempts = %d, last_error = %s, next_attempt_at = NULL WHERE id = %d", $attempts, $error, $id ) );
			wp_mail(
				(string) get_option( 'admin_email' ),
				'A Stripe notice could not be processed',
				'Stripe notice ' . sanitize_text_field( (string) $event['id'] ) . ' (' . sanitize_text_field( (string) $event['type'] ) . ') failed ' . $attempts . " times over 24 hours. Please check it in Stripe.\n"
			);
			return 'failed';
		}
		$next = gmdate( 'Y-m-d H:i:s', time() + self::NOTICE_RETRY_HOURS[ $retry ] * HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET processing_state = 'retrying', attempts = %d, last_error = %s, next_attempt_at = %s WHERE id = %d", $attempts, $error, $next, $id ) );
		return 'retrying';
	}

	/**
	 * Hourly: run notices that are due again, and any left "received" by a request that stopped part way.
	 *
	 * @param string $now UTC time to judge "due" from; the real time when empty.
	 * @return int Notices attempted.
	 */
	public static function retry_notices( $now = '' ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'stripe_notice' );
		$now   = '' !== $now ? $now : gmdate( 'Y-m-d H:i:s' );
		$stuck = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - 15 * MINUTE_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, processing_state, next_attempt_at, payload FROM {$table} WHERE processing_state IN ('received', 'retrying') AND ( ( next_attempt_at IS NOT NULL AND next_attempt_at <= %s ) OR ( next_attempt_at IS NULL AND received_at <= %s ) ) ORDER BY id ASC LIMIT 50",
				$now,
				$stuck
			)
		);
		$count = 0;
		foreach ( $rows as $row ) {
			// Claim the row so a second runner leaves it alone.
			$hold    = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + 15 * MINUTE_IN_SECONDS );
			$claimed = null === $row->next_attempt_at
				? $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET processing_state = 'received', next_attempt_at = %s WHERE id = %d AND processing_state = %s AND next_attempt_at IS NULL", $hold, (int) $row->id, (string) $row->processing_state ) )
				: $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET processing_state = 'received', next_attempt_at = %s WHERE id = %d AND processing_state = %s AND next_attempt_at = %s", $hold, (int) $row->id, (string) $row->processing_state, (string) $row->next_attempt_at ) );
			if ( 1 !== $claimed ) {
				continue;
			}
			$event = is_string( $row->payload ) ? json_decode( $row->payload, true ) : null;
			if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET processing_state = 'failed', last_error = %s, next_attempt_at = NULL WHERE id = %d", 'The stored notice could not be read.', (int) $row->id ) );
				continue;
			}
			self::process_notice( (int) $row->id, $event );
			++$count;
		}
		return $count;
	}

	/**
	 * P6: notice records are kept for 90 days.
	 */
	private static function purge_notices() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'stripe_notice' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE received_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Throw when a write in the notice path fails, so the transaction rolls back and the notice is retried.
	 *
	 * @param int|false $result wpdb result.
	 * @param string    $what   What was being written.
	 * @return int
	 */
	private static function written( $result, $what ) {
		if ( false === $result && self::$in_notice ) {
			global $wpdb;
			throw new RuntimeException( 'Could not write ' . $what . ': ' . (string) $wpdb->last_error );
		}
		return (int) $result;
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
			delete_option( 'lld_notice_fail' );
			delete_option( 'lld_members_stripe_unreachable' );
			self::sync_pmpro_levels();
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'launch' === $action && ! empty( $params['on'] ) ) {
			update_option( 'lld_launch_on', sanitize_text_field( (string) $params['on'] ), false );
			return new WP_REST_Response( array( 'expires_on' => self::launch_expires() ), 200 );
		}
		if ( 'prices' === $action ) {
			$saved = self::save_prices(
				isset( $params['monthly'] ) ? (int) $params['monthly'] : self::prices()['monthly'],
				isset( $params['yearly'] ) ? (int) $params['yearly'] : self::prices()['yearly'],
				isset( $params['code'] ) ? (string) $params['code'] : '',
				isset( $params['percent'] ) ? (int) $params['percent'] : 0,
				! empty( $params['confirm'] ),
				array(
					'plans'      => isset( $params['plans'] ) && is_array( $params['plans'] ) ? array_map( 'sanitize_key', $params['plans'] ) : array( 'monthly' ),
					'valid_from' => isset( $params['valid_from'] ) ? sanitize_text_field( (string) $params['valid_from'] ) : '',
					'valid_to'   => isset( $params['valid_to'] ) ? sanitize_text_field( (string) $params['valid_to'] ) : '',
				)
			);
			if ( is_wp_error( $saved ) ) {
				return new WP_REST_Response( array( 'message' => $saved->get_error_message() ), 400 );
			}
			return new WP_REST_Response( self::prices(), 200 );
		}
		if ( 'member_options' === $action ) {
			return new WP_REST_Response( self::member_options( isset( $params['member_id'] ) ? (int) $params['member_id'] : 0 ), 200 );
		}
		if ( 'codes' === $action ) {
			return new WP_REST_Response( array( 'codes' => self::codes() ), 200 );
		}
		if ( 'pmpro_levels' === $action ) {
			global $wpdb;
			LLD_Members_Billing::db_update();
			$ids   = self::sync_pmpro_levels();
			$table = $wpdb->prefix . 'pmpro_membership_levels';
			$rows  = array();
			foreach ( $ids as $plan => $id ) {
				$rows[ $plan ] = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, initial_payment, billing_amount, cycle_number, cycle_period, allow_signups FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			}
			$named = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE name IN (%s, %s)", self::PMPRO_LEVELS['yearly']['name'], self::PMPRO_LEVELS['monthly']['name'] ) );
			return new WP_REST_Response( array( 'levels' => $rows, 'named' => $named ), 200 );
		}
		if ( 'admin_log' === $action ) {
			global $wpdb;
			$rows = $wpdb->get_results( 'SELECT action, member_number, member_name, member_email, detail, money_related FROM ' . LLD_Members_Schema::table( 'admin_log' ) . " WHERE action IN ('price_change', 'code_created', 'refund', 'reward_withdrawn') ORDER BY id DESC LIMIT 50", ARRAY_A );
			return new WP_REST_Response( array( 'lines' => $rows ), 200 );
		}
		if ( 'stop_reward' === $action ) {
			update_option( 'lld_reward_stop', '1', false );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		$notice_ref = isset( $params['ref'] ) ? sanitize_text_field( (string) $params['ref'] ) : '';
		if ( 'fail_notice' === $action ) {
			if ( '' === $notice_ref ) {
				delete_option( 'lld_notice_fail' );
			} else {
				update_option( 'lld_notice_fail', $notice_ref, false );
			}
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'notice' === $action || 'retry_notice' === $action ) {
			global $wpdb;
			$table = LLD_Members_Schema::table( 'stripe_notice' );
			if ( 'retry_notice' === $action ) {
				// Make this one notice due now; other notices keep their own times.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_attempt_at = %s WHERE notice_ref = %s AND processing_state IN ('received', 'retrying')", gmdate( 'Y-m-d H:i:s', time() - 60 ), $notice_ref ) );
				self::retry_notices();
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT notice_ref, notice_type, processing_state, attempts, last_error, next_attempt_at, payload IS NOT NULL AS has_payload FROM {$table} WHERE notice_ref = %s", $notice_ref ), ARRAY_A );
			return new WP_REST_Response( array( 'notice' => $row ), 200 );
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
			if ( self::has_premium( $member_id ) ) {
				return new WP_REST_Response( array( 'message' => 'You already have Premium.' ), 409 );
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
			$confirm = ! empty( $_POST['lld_confirm_free'] );
			$applies = isset( $_POST['lld_code_plans'] ) ? sanitize_key( wp_unslash( $_POST['lld_code_plans'] ) ) : 'monthly';
			$terms   = array(
				'plans'      => 'both' === $applies ? array( 'monthly', 'yearly' ) : array( 'yearly' === $applies ? 'yearly' : 'monthly' ),
				'valid_from' => isset( $_POST['lld_valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_valid_from'] ) ) : '',
				'valid_to'   => isset( $_POST['lld_valid_to'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_valid_to'] ) ) : '',
			);
			$saved   = self::save_prices( $monthly, $yearly, $code, $percent, $confirm, $terms );
			if ( is_wp_error( $saved ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $saved->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>Saved</p></div>';
			}
		}
		$ask = 0;
		if ( isset( $_POST['lld_refund_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_refund_nonce'] ) ), 'lld_refund' ) ) {
			$order_id  = isset( $_POST['lld_order'] ) ? absint( wp_unslash( $_POST['lld_order'] ) ) : 0;
			$confirmed = isset( $_POST['lld_refund_confirm'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_refund_confirm'] ) ), 'lld_refund_confirm_' . $order_id );
			if ( $confirmed ) {
				$result = self::refund_order( $order_id );
				echo '<div class="notice"><p>' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : 'Refunded' ) . '</p></div>';
			} else {
				$ask = $order_id;
			}
		}
		$prices = self::prices();
		echo '<div class="wrap"><h1>Payments</h1>';
		if ( self::live_keys_refused() ) {
			echo '<div class="notice notice-error"><p>A live Stripe key is set outside production. Checkout is switched off until it is replaced with a test key.</p></div>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'lld_pay', 'lld_pay_nonce' );
		echo '<p><label>Monthly price US$ <input name="lld_monthly" value="' . esc_attr( number_format( $prices['monthly'] / 100, 2, '.', '' ) ) . '"></label></p>';
		echo '<p><label>Yearly price US$ <input name="lld_yearly" value="' . esc_attr( number_format( $prices['yearly'] / 100, 2, '.', '' ) ) . '"></label></p>';
		echo '<p><label>New code <input name="lld_new_code"></label> <label>Percent off the first payment <input name="lld_percent" value="50"></label></p>';
		echo '<p><label>Applies to <select name="lld_code_plans"><option value="monthly">Monthly</option><option value="yearly">Yearly</option><option value="both">Monthly and yearly</option></select></label>';
		echo ' <label>Valid from <input type="date" name="lld_valid_from" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label>';
		echo ' <label>Valid to <input type="date" name="lld_valid_to" value="' . esc_attr( gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) ) . '"></label></p>';
		echo '<p><label><input type="checkbox" name="lld_confirm_free" value="1"> I confirm a price of 0 or a 100 % code</label></p>';
		echo '<p><button class="button button-primary">Save prices</button></p></form>';
		echo '<h2>Discount codes</h2><table class="widefat" data-lld-codes><thead><tr><th>Code</th><th>Percent off</th><th>Plans</th><th>Valid from</th><th>Valid to</th><th>Times used</th></tr></thead><tbody>';
		foreach ( self::codes() as $row ) {
			echo '<tr><td>' . esc_html( $row['code'] ) . '</td><td>' . (int) $row['percent'] . ' %</td><td>' . esc_html( implode( ', ', $row['plans'] ) ) . '</td><td>' . esc_html( $row['valid_from'] ) . '</td><td>' . esc_html( $row['valid_to'] ) . '</td><td>' . (int) $row['times_used'] . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>Refund the first payment</h2>';
		if ( $ask > 0 ) {
			self::refund_confirm( $ask );
		}
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
		if ( '' !== $code && self::code_applies( $member_id, $code, $plan ) ) {
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
			self::apply_subscription_deleted( $object );
			return;
		}
		if ( 'customer.subscription.updated' === $type ) {
			self::apply_subscription_updated( $object );
			return;
		}
		if ( 'invoice.payment_failed' === $type ) {
			$sub_id = isset( $object['subscription'] ) ? (string) $object['subscription'] : '';
			$row    = self::by_stripe_subscription( $sub_id );
			if ( $row ) {
				$invoice = isset( $object['id'] ) && '' !== (string) $object['id'] ? (string) $object['id'] : 'sub:' . $sub_id;
				self::mark_retry( (int) $row->member_id, sanitize_text_field( $invoice ) );
				self::record_failed_attempt( (int) $row->member_id, $object );
			}
			return;
		}
		if ( 'invoice.paid' === $type ) {
			// The first invoice is already recorded from checkout.session.completed; a second order and receipt would double it.
			if ( isset( $object['billing_reason'] ) && 'subscription_create' === (string) $object['billing_reason'] ) {
				return;
			}
			self::apply_renewal( $object );
			return;
		}
		if ( 'charge.refunded' === $type ) {
			self::apply_charge_refunded( $object );
			return;
		}
		if ( 'checkout.session.completed' === $type ) {
			self::apply_checkout( $object );
		}
	}

	/**
	 * T4: a subscription that has ended means Premium ended, unless the first payment was refunded.
	 *
	 * @param array<string,mixed> $object Subscription object.
	 */
	private static function apply_subscription_deleted( $object ) {
		$sub_id = isset( $object['id'] ) ? (string) $object['id'] : '';
		$row    = self::by_stripe_subscription( $sub_id );
		if ( ! $row ) {
			// The cancellation arrived before its payment notice; checkout applies it when the payment arrives.
			self::hold_cancel( $sub_id );
			return;
		}
		$member_id = (int) $row->member_id;
		if ( in_array( strtolower( (string) $row->access_state ), array( 'premium ended', 'refunded' ), true ) ) {
			return;
		}
		$reason = isset( $object['cancellation_details']['reason'] ) ? (string) $object['cancellation_details']['reason'] : '';
		self::clear_retry( $member_id );
		self::end_premium( $member_id, 'payment_failed' === $reason ? 'failed' : 'expired', LLD_Members_Programme::local_today( $member_id ) );
	}

	/**
	 * T4 mapping from the subscription in the notice. Fetching the current state from Stripe is still to come.
	 *
	 * @param array<string,mixed> $object Subscription object.
	 */
	private static function apply_subscription_updated( $object ) {
		$row = self::by_stripe_subscription( isset( $object['id'] ) ? (string) $object['id'] : '' );
		if ( ! $row ) {
			return;
		}
		$member_id = (int) $row->member_id;
		$access    = strtolower( (string) $row->access_state );
		if ( in_array( $access, array( 'premium ended', 'refunded' ), true ) ) {
			// Ended access comes back only through a new checkout.
			return;
		}
		$status = isset( $object['status'] ) ? (string) $object['status'] : '';
		$fields = array();
		$ends   = self::period_end_of( $member_id, $object );
		if ( '' !== $ends ) {
			$fields['period_ends_on'] = $ends;
		}
		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			$cancelling = ! empty( $object['cancel_at_period_end'] ) || ! empty( $object['cancel_at'] );
			$fields['access_state'] = $cancelling ? 'cancelled' : 'Premium';
			$fields['stripe_state'] = $cancelling ? 'cancel_at_period_end' : 'active';
			if ( ! $cancelling ) {
				self::clear_retry( $member_id );
			}
		} elseif ( 'past_due' === $status ) {
			// Grace period: access is unchanged while Stripe retries (the window starts at the failed invoice).
			$fields['stripe_state'] = 'past_due';
		} elseif ( in_array( $status, array( 'unpaid', 'canceled', 'incomplete_expired' ), true ) ) {
			self::clear_retry( $member_id );
			self::end_premium( $member_id, 'canceled' === $status ? 'expired' : 'failed', LLD_Members_Programme::local_today( $member_id ) );
			return;
		}
		if ( $fields ) {
			self::save_sub( $member_id, $fields );
		}
	}

	/**
	 * A charge refunded in full at Stripe. The admin refund already recorded it when it started here.
	 *
	 * @param array<string,mixed> $object Charge object.
	 */
	private static function apply_charge_refunded( $object ) {
		if ( empty( $object['refunded'] ) ) {
			// A partial refund changes no access.
			return;
		}
		$payment = isset( $object['payment_intent'] ) ? (string) $object['payment_intent'] : '';
		if ( '' === $payment ) {
			return;
		}
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE stripe_payment = %s AND kind IN ('payment', 'renewal') ORDER BY id DESC LIMIT 1", $payment ) );
		if ( ! $order || 'refunded' === (string) $order->status ) {
			return;
		}
		$refund = '';
		if ( isset( $object['refunds']['data'][0]['id'] ) ) {
			$refund = (string) $object['refunds']['data'][0]['id'];
		} elseif ( isset( $object['id'] ) ) {
			$refund = 're_' . (string) $object['id'];
		}
		self::record_refund( $order, sanitize_text_field( $refund ), 'Stripe' );
	}

	/**
	 * Current period end as the member's local date, from the subscription or its first item.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $object    Subscription object.
	 */
	private static function period_end_of( $member_id, $object ) {
		$stamp = 0;
		if ( isset( $object['current_period_end'] ) && is_numeric( $object['current_period_end'] ) ) {
			$stamp = (int) $object['current_period_end'];
		} elseif ( isset( $object['items']['data'][0]['current_period_end'] ) && is_numeric( $object['items']['data'][0]['current_period_end'] ) ) {
			$stamp = (int) $object['items']['data'][0]['current_period_end'];
		}
		if ( $stamp < 1 ) {
			return '';
		}
		return ( new DateTimeImmutable( '@' . $stamp ) )->setTimezone( self::member_zone( $member_id ) )->format( 'Y-m-d' );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member_zone( $member_id ) {
		global $wpdb;
		$zone = $member_id > 0 ? $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) ) : '';
		try {
			return new DateTimeZone( is_string( $zone ) && '' !== $zone ? $zone : 'UTC' );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * The member's local date of a UTC time stored on an order.
	 *
	 * @param int    $member_id Member number.
	 * @param string $utc       MySQL datetime in UTC.
	 */
	private static function local_date_of( $member_id, $utc ) {
		try {
			$at = new DateTimeImmutable( (string) $utc, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return substr( (string) $utc, 0, 10 );
		}
		return $at->setTimezone( self::member_zone( (int) $member_id ) )->format( 'Y-m-d' );
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
		$current  = self::subscription_row( $member_id );
		if ( self::has_premium( $member_id ) && $current && '' !== (string) $current->stripe_subscription && (string) $current->stripe_subscription !== $stripe ) {
			// Paid twice: keep the first subscription, record the payment so the owner can refund it, and flag it.
			self::insert_order( $member_id, 'payment', $quote, $payment, $email, $fingerprint );
			self::flag_mismatch( $member_id, $today );
			return;
		}
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
		self::mail_member( $member_id, 'Your receipt', 'Your receipt. Amount ' . self::money( (int) $quote['charged'], (string) $quote['currency'] ) . ', tax included. Date ' . $today . '.' );
		if ( self::cancel_was_held( $stripe ) ) {
			// The subscription ended before its payment notice arrived: T4 maps that to Premium ended.
			self::end_premium( $member_id, 'expired', $today );
		}
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
		$order_id = self::insert_order(
			$member_id,
			'renewal',
			$quote,
			$payment,
			self::email_of( $member_id ),
			'',
			array(
				'related_order_id' => self::first_payment_id( $member_id ),
				'discount_code'    => ! empty( $next['reward_id'] ) ? 'referral-reward-' . (int) $next['reward_id'] : '',
			)
		);
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
	 * Retry windows keyed on the failed invoice. Older entries keyed on the member are read as one window each.
	 *
	 * @return array<string,array{member_id:int,from:string}>
	 */
	private static function retries() {
		$stored = get_option( 'lld_retries' );
		$out    = array();
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $value['member_id'], $value['from'] ) ) {
				$out[ (string) $key ] = array(
					'member_id' => (int) $value['member_id'],
					'from'      => (string) $value['from'],
				);
			} elseif ( is_string( $value ) && is_numeric( $key ) ) {
				$out[ 'member:' . (int) $key ] = array(
					'member_id' => (int) $key,
					'from'      => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $invoice   Stripe invoice the failure belongs to.
	 */
	private static function mark_retry( $member_id, $invoice ) {
		$retries = self::retries();
		// The 7-day window belongs to the invoice and starts at its first failure. Later retries of it never move it.
		if ( ! isset( $retries[ $invoice ] ) ) {
			$retries[ $invoice ] = array(
				'member_id' => (int) $member_id,
				'from'      => LLD_Members_Programme::local_today( $member_id ),
			);
			update_option( 'lld_retries', $retries, false );
		}
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
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			LLD_Members_Premium::note_ended( $member_id, $as_of );
		}
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
		$paid_on   = self::local_date_of( $member_id, (string) $order->ordered_at );
		$limit     = ( new DateTimeImmutable( $paid_on ) )->modify( '+14 days' )->format( 'Y-m-d' );
		if ( $today > $limit ) {
			return new WP_Error( 'lld_refund', 'Refunds of the first payment are available for 14 days.' );
		}
		if ( 'refunded' === (string) $order->status ) {
			return new WP_Error( 'lld_refund', 'This payment is already refunded.' );
		}
		self::record_refund( $order, 're_test_' . $order_id, 'owner' );
		return true;
	}

	/**
	 * Record a refund of a payment or renewal once. Only a refunded first payment changes access (T4).
	 *
	 * @param object $order Order row that was refunded.
	 * @param string $ref   Stripe refund reference.
	 * @param string $by    Who started it: owner or Stripe.
	 */
	private static function record_refund( $order, $ref, $by ) {
		global $wpdb;
		$table     = LLD_Members_Schema::table( 'order' );
		$member_id = (int) $order->member_id;
		$order_id  = (int) $order->id;
		$marked    = self::written( $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'refunded' WHERE id = %d AND status <> 'refunded'", $order_id ) ), 'an order' );
		if ( 1 !== $marked ) {
			return;
		}
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
			'',
			array( 'related_order_id' => $order_id )
		);
		self::admin_log( 'refund', $member_id, 'Order ' . $order_id . ' (' . (string) $order->kind . ') refunded in full: ' . self::money( (int) $order->charged_amount, (string) $order->charged_currency ) . ', started by ' . $by . ', reference ' . $ref . '.' );
		if ( 'payment' === (string) $order->kind ) {
			self::end_premium( $member_id, 'refund', LLD_Members_Programme::local_today( $member_id ) );
			self::withdraw_rewards_for_order( $order_id );
		}
	}

	private static function retry_window() {
		$retries = self::retries();
		if ( ! $retries ) {
			return;
		}
		foreach ( $retries as $invoice => $window ) {
			$member_id = (int) $window['member_id'];
			$today     = LLD_Members_Programme::local_today( $member_id );
			$due       = ( new DateTimeImmutable( (string) $window['from'] ) )->modify( '+7 days' )->format( 'Y-m-d' );
			if ( $today < $due ) {
				continue;
			}
			$sub = self::subscription_row( $member_id );
			if ( $sub && ! in_array( strtolower( (string) $sub->access_state ), array( 'premium ended', 'refunded' ), true ) ) {
				self::end_premium( $member_id, 'failed', $today );
			}
			unset( $retries[ $invoice ] );
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
				self::not_qualified( (int) $row->id, 'refunded' );
				continue;
			}
			$paid = self::local_date_of( (int) $row->friend_member_id, (string) $order->ordered_at );
			$due  = ( new DateTimeImmutable( $paid ) )->modify( '+14 days' )->format( 'Y-m-d' );
			$today = LLD_Members_Programme::local_today( (int) $row->friend_member_id );
			if ( $today <= $due ) {
				continue;
			}
			if ( self::rewards_this_year( (int) $row->referrer_member_id, substr( $paid, 0, 4 ) ) >= 3 ) {
				self::not_qualified( (int) $row->id, 'limit' );
				continue;
			}
			if ( self::grant_pair( (int) $row->id, (int) $row->referrer_member_id, (int) $row->friend_member_id ) ) {
				$wpdb->update( $table, array( 'status' => 'qualified' ), array( 'id' => (int) $row->id ) );
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
		self::not_qualified( (int) $referral->id, 'refunded' );
		if ( 'waiting' === (string) $referral->status ) {
			self::admin_log( 'reward_withdrawn', (int) $referral->referrer_member_id, 'Referral ' . (int) $referral->id . ' earns no reward: the friend\'s first payment, order ' . (int) $order_id . ', was refunded.' );
		}
		$table   = LLD_Members_Schema::table( 'reward' );
		$pending = $wpdb->get_results( $wpdb->prepare( "SELECT id, recipient_member_id, form FROM {$table} WHERE referral_id = %d AND status = 'pending'", (int) $referral->id ) );
		foreach ( $pending as $reward ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'withdrawn', withdrawn_on = %s, withdrawn_why = %s WHERE id = %d AND status = 'pending'", lld_members_now_mysql(), 'refund', (int) $reward->id ) );
			if ( 1 === $done ) {
				self::admin_log( 'reward_withdrawn', (int) $reward->recipient_member_id, 'Reward ' . (int) $reward->id . ' (' . (string) $reward->form . ') withdrawn: the friend\'s payment, order ' . (int) $order_id . ', was refunded.' );
			}
		}
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
		$wpdb->insert(
			$table,
			array(
				'referrer_member_id' => $referrer,
				'code'               => $code,
				'friend_member_id'   => $member_id,
				'friend_order_id'    => $order_id,
				'status'             => '' !== $self ? 'not_qualified' : 'waiting',
				'not_qualified_why'  => '' !== $self ? 'self' : null,
				'self_referral'      => '' !== $self ? $self : null,
			)
		);
	}

	/**
	 * P5: a referral that earns no reward, and why: refunded, limit (3 a calendar year) or self.
	 *
	 * @param int    $referral_id Referral.
	 * @param string $why         Reason.
	 */
	private static function not_qualified( $referral_id, $why ) {
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'referral' ),
			array(
				'status'            => 'not_qualified',
				'not_qualified_why' => $why,
			),
			array( 'id' => (int) $referral_id )
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
	 * @param int    $percent   Percent off the first payment.
	 * @param bool   $confirmed The owner ticked the confirm box for a price of 0 or a 100 % code.
	 * @param array  $terms     P4 fields for a new code: plans, valid_from, valid_to.
	 * @return true|WP_Error
	 */
	private static function save_prices( $monthly, $yearly, $code, $percent, $confirmed = false, $terms = array() ) {
		$monthly = max( 0, (int) $monthly );
		$yearly  = max( 0, (int) $yearly );
		$code    = strtoupper( trim( $code ) );
		$percent = min( 100, (int) $percent );
		$new     = '' !== $code && $percent >= 1;
		$free    = 0 === $monthly || 0 === $yearly || ( $new && 100 === $percent );
		if ( $free && ! $confirmed ) {
			return new WP_Error( 'lld_free', 'A price of 0 or a 100 % code needs the confirm box ticked. Nothing was saved.' );
		}
		$before = self::prices();
		update_option( 'lld_prices', array( 'monthly' => $monthly, 'yearly' => $yearly ), false );
		$changes = array();
		foreach ( array( 'monthly' => $monthly, 'yearly' => $yearly ) as $plan => $cents ) {
			if ( (int) $before[ $plan ] !== $cents ) {
				$changes[] = ucfirst( $plan ) . ' ' . self::money( (int) $before[ $plan ], 'usd' ) . ' to ' . self::money( $cents, 'usd' );
			}
		}
		if ( $changes ) {
			self::sync_pmpro_levels();
			self::admin_log( 'price_change', 0, implode( '; ', $changes ) . ( $confirmed && ( 0 === $monthly || 0 === $yearly ) ? ' (price of 0 confirmed)' : '' ) );
		}
		if ( ! $new ) {
			return true;
		}
		$row = self::code_fields(
			array(
				'code'       => $code,
				'percent'    => $percent,
				'plans'      => isset( $terms['plans'] ) ? $terms['plans'] : array( 'monthly' ),
				'valid_from' => ! empty( $terms['valid_from'] ) ? $terms['valid_from'] : gmdate( 'Y-m-d' ),
				'valid_to'   => ! empty( $terms['valid_to'] ) ? $terms['valid_to'] : gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
				'times_used' => 0,
			)
		);
		if ( $row['valid_to'] < $row['valid_from'] ) {
			return new WP_Error( 'lld_code_dates', 'The code ends before it starts. The prices were saved; the code was not.' );
		}
		if ( self::find_code( $code ) ) {
			return new WP_Error( 'lld_code_taken', 'That code already exists. The prices were saved; the code was not.' );
		}
		$stored   = self::stored_codes();
		$stored[] = $row;
		update_option( 'lld_codes', $stored, false );
		self::admin_log( 'code_created', 0, $code . ', ' . $percent . ' % off the first payment, plans ' . implode( ' and ', $row['plans'] ) . ', valid ' . $row['valid_from'] . ' to ' . $row['valid_to'] . ( 100 === $percent ? ' (100 % confirmed)' : '' ) );
		return true;
	}

	/**
	 * F2 admin log line for a money action. Erasure later removes the name and email and keeps the member number.
	 *
	 * @param string $action    price_change, code_created, refund or reward_withdrawn.
	 * @param int    $member_id Member named in the action, or 0.
	 * @param string $detail    What happened, with no health details.
	 */
	private static function admin_log( $action, $member_id, $detail ) {
		global $wpdb;
		$name  = null;
		$email = null;
		if ( $member_id > 0 ) {
			$member = $wpdb->get_row( $wpdb->prepare( 'SELECT first_name, last_name, email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
			if ( $member ) {
				$name  = trim( (string) $member->first_name . ' ' . (string) $member->last_name );
				$email = (string) $member->email;
			}
		}
		$user  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$by    = $user > 0 ? ' By user ' . $user . '.' : '';
		$wpdb->insert(
			LLD_Members_Schema::table( 'admin_log' ),
			array(
				'action'        => $action,
				'member_number' => $member_id > 0 ? $member_id : null,
				'member_name'   => '' !== (string) $name ? $name : null,
				'member_email'  => $email,
				'detail'        => $detail . $by,
				'money_related' => 1,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function codes() {
		$out    = array();
		$launch = null;
		foreach ( self::stored_codes() as $row ) {
			if ( 'LAUNCH' === $row['code'] ) {
				$launch = $row;
				continue;
			}
			$out[] = $row;
		}
		// The launch code always runs for 30 days from the launch date, monthly sign-ups only (8.4).
		array_unshift(
			$out,
			self::code_fields(
				array(
					'code'       => 'LAUNCH',
					'percent'    => 50,
					'plans'      => array( 'monthly' ),
					'valid_from' => self::launch_on(),
					'valid_to'   => self::launch_expires(),
					'times_used' => $launch ? (int) $launch['times_used'] : 0,
				)
			)
		);
		return $out;
	}

	/**
	 * Codes saved in the option, read into the P4 fields. Older rows had only expires_on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function stored_codes() {
		$stored = get_option( 'lld_codes' );
		$out    = array();
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $row ) {
			if ( is_array( $row ) && ! empty( $row['code'] ) ) {
				$out[] = self::code_fields( $row );
			}
		}
		return $out;
	}

	/**
	 * P4: the code, percent off the first payment, the plans it applies to, valid-from and valid-to dates, times used.
	 *
	 * @param array<string,mixed> $row Code as stored or entered.
	 * @return array{code:string,percent:int,plans:string[],valid_from:string,valid_to:string,times_used:int}
	 */
	private static function code_fields( $row ) {
		$plans = isset( $row['plans'] ) && is_array( $row['plans'] ) ? array_values( array_intersect( array( 'monthly', 'yearly' ), $row['plans'] ) ) : array( 'monthly' );
		$date  = static function ( $value ) {
			return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		$to    = $date( isset( $row['valid_to'] ) ? $row['valid_to'] : ( isset( $row['expires_on'] ) ? $row['expires_on'] : '' ) );
		return array(
			'code'       => strtoupper( (string) $row['code'] ),
			'percent'    => max( 1, min( 100, (int) ( isset( $row['percent'] ) ? $row['percent'] : 0 ) ) ),
			'plans'      => $plans ? $plans : array( 'monthly' ),
			'valid_from' => $date( isset( $row['valid_from'] ) ? $row['valid_from'] : '' ),
			'valid_to'   => '' !== $to ? $to : '9999-12-31',
			'times_used' => isset( $row['times_used'] ) ? max( 0, (int) $row['times_used'] ) : 0,
		);
	}

	/**
	 * Count one use of a code on a first payment.
	 *
	 * @param string $code Code.
	 */
	private static function count_code_use( $code ) {
		$code   = strtoupper( $code );
		$stored = self::stored_codes();
		$found  = false;
		foreach ( $stored as $i => $row ) {
			if ( $row['code'] === $code ) {
				$stored[ $i ]['times_used'] = (int) $row['times_used'] + 1;
				$found                      = true;
			}
		}
		if ( ! $found && 'LAUNCH' === $code ) {
			$stored[] = array(
				'code'       => 'LAUNCH',
				'percent'    => 50,
				'plans'      => array( 'monthly' ),
				'times_used' => 1,
			);
			$found = true;
		}
		if ( $found ) {
			update_option( 'lld_codes', $stored, false );
		}
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $code      Code.
	 */
	private static function code_applies( $member_id, $code, $plan ) {
		$row = self::find_code( $code );
		if ( ! $row || ! in_array( $plan, $row['plans'], true ) ) {
			return false;
		}
		$today = $member_id ? LLD_Members_Programme::local_today( $member_id ) : self::today_for( 0 );
		if ( $today > (string) $row['valid_to'] || ( '' !== $row['valid_from'] && $today < (string) $row['valid_from'] ) ) {
			return false;
		}
		global $wpdb;
		$prior = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE member_id = %d AND kind = 'payment' AND status = 'succeeded'", $member_id ) );
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
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.friend_member_id, o.ordered_at FROM ' . LLD_Members_Schema::table( 'referral' ) . ' r INNER JOIN ' . LLD_Members_Schema::table( 'order' ) . " o ON o.id = r.friend_order_id WHERE r.referrer_member_id = %d AND r.status = 'qualified'",
				$member_id
			)
		);
		$count = 0;
		foreach ( $rows as $row ) {
			if ( substr( self::local_date_of( (int) $row->friend_member_id, (string) $row->ordered_at ), 0, 4 ) === (string) $year ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * One order row (P3). stripe_invoice stays empty until the Stripe integration reads invoices.
	 *
	 * @param int                  $member_id   Member.
	 * @param string               $kind        payment, renewal, refund or failed_attempt.
	 * @param array<string,mixed>  $quote       Amounts.
	 * @param string               $payment     Stripe payment id; empty stores none.
	 * @param string               $email       Billing email.
	 * @param string               $fingerprint Card fingerprint, stored aside from the order row.
	 * @param array<string,mixed>  $extra       status, related_order_id, discount_code (a referral reward).
	 */
	private static function insert_order( $member_id, $kind, $quote, $payment, $email, $fingerprint, $extra = array() ) {
		unset( $fingerprint );
		global $wpdb;
		$code     = '' !== (string) $quote['code'] ? (string) $quote['code'] : ( isset( $extra['discount_code'] ) ? (string) $extra['discount_code'] : '' );
		$discount = 'refund' !== $kind && '' !== $code ? max( 0, (int) $quote['base'] - (int) $quote['charged'] ) : 0;
		if ( 'payment' === $kind && '' !== (string) $quote['code'] ) {
			self::count_code_use( (string) $quote['code'] );
		}
		$result   = $wpdb->insert(
			LLD_Members_Schema::table( 'order' ),
			array(
				'member_id'          => $member_id,
				'ordered_at'         => lld_members_now_mysql( $member_id ),
				'kind'               => $kind,
				'related_order_id'   => ! empty( $extra['related_order_id'] ) ? (int) $extra['related_order_id'] : null,
				'catalogue_amount'   => (int) $quote['base'],
				'catalogue_currency' => (string) $quote['currency'],
				'charged_amount'     => (int) $quote['charged'],
				'charged_currency'   => (string) $quote['currency'],
				'tax_amount'         => (int) $quote['tax'],
				'tax_currency'       => (string) $quote['currency'],
				'discount_code'      => '' !== $code ? $code : null,
				'discount_amount'    => $discount > 0 ? $discount : null,
				'discount_currency'  => $discount > 0 ? (string) $quote['currency'] : null,
				'status'             => isset( $extra['status'] ) ? (string) $extra['status'] : 'succeeded',
				'stripe_payment'     => '' !== (string) $payment ? (string) $payment : null,
				'stripe_invoice'     => null,
				'billing_email'      => $email,
				'billing_country'    => isset( $quote['country'] ) ? (string) $quote['country'] : null,
			)
		);
		self::written( $result, 'an order' );
		return (int) $wpdb->insert_id;
	}

	/**
	 * The first payment of the member's current subscription, which renewals, refunds and failed attempts belong to.
	 *
	 * @param int $member_id Member number.
	 */
	private static function first_payment_id( $member_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE member_id = %d AND kind = 'payment' ORDER BY id DESC LIMIT 1", $member_id ) );
	}

	/**
	 * P3: a failed renewal attempt is an order of its own, pointing to the payment it belongs to.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $object    Invoice object.
	 */
	private static function record_failed_attempt( $member_id, $object ) {
		$sub      = self::subscription_row( $member_id );
		$next     = self::next_charge( $member_id );
		$amount   = isset( $object['amount_due'] ) && is_numeric( $object['amount_due'] ) ? (int) $object['amount_due'] : (int) $next['charged'];
		$country  = 'gbp' === $next['currency'] ? 'GB' : ( 'eur' === $next['currency'] ? 'DE' : 'US' );
		$charge   = isset( $object['charge'] ) && is_string( $object['charge'] ) ? sanitize_text_field( $object['charge'] ) : '';
		self::insert_order(
			$member_id,
			'failed_attempt',
			array(
				'plan'     => $sub ? (string) $sub->plan : 'monthly',
				'country'  => $country,
				'currency' => $next['currency'],
				'base'     => $sub && $sub->locked_amount ? (int) $sub->locked_amount : $amount,
				'charged'  => $amount,
				'tax'      => self::tax_inside( $amount, $country ),
				'code'     => '',
			),
			$charge,
			self::email_of( $member_id ),
			'',
			array(
				'status'           => 'failed',
				'related_order_id' => self::first_payment_id( $member_id ),
			)
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function receipt_list( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'order' ) . " WHERE member_id = %d AND kind <> 'failed_attempt' ORDER BY id ASC", $member_id ) );
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

	/**
	 * Second step before a refund: the owner sees the order, amount and member, then confirms.
	 *
	 * @param int $order_id Order.
	 */
	private static function refund_confirm( $order_id ) {
		$order = self::order_row( $order_id );
		if ( ! $order || 'payment' !== (string) $order->kind || 'succeeded' !== (string) $order->status ) {
			echo '<div class="notice notice-error"><p>Only a paid first payment can be refunded.</p></div>';
			return;
		}
		echo '<div class="notice notice-warning" data-lld-refund-confirm="' . (int) $order_id . '"><form method="post"><p>';
		wp_nonce_field( 'lld_refund', 'lld_refund_nonce' );
		wp_nonce_field( 'lld_refund_confirm_' . (int) $order_id, 'lld_refund_confirm' );
		echo 'Refund order ' . (int) $order_id . ' in full: ' . esc_html( self::money( (int) $order->charged_amount, (string) $order->charged_currency ) ) . ' to member ' . (int) $order->member_id . ' (' . esc_html( (string) $order->billing_email ) . ')? Premium ends and the rest of the current track week stays open. This cannot be undone.';
		echo ' <input type="hidden" name="lld_order" value="' . (int) $order_id . '">';
		echo ' <button class="button button-primary">Confirm refund</button>';
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=lld-payments' ) ) . '">Keep the payment</a>';
		echo '</p></form></div>';
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
			if ( 'succeeded' === (string) $row->status ) {
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
				self::written( $wpdb->update( $table, $fields, array( 'id' => $id ) ), 'a subscription' );
			}
			foreach ( $nulls as $column ) {
				self::written( $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET `{$column}` = NULL WHERE id = %d", $id ) ), 'a subscription' );
			}
			return;
		}
		$fields['member_id']    = $member_id;
		$fields['plan']         = isset( $fields['plan'] ) ? $fields['plan'] : 'free';
		$fields['access_state'] = isset( $fields['access_state'] ) ? $fields['access_state'] : 'free';
		self::written( $wpdb->insert( $table, $fields ), 'a subscription' );
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
		$retries = self::retries();
		$kept    = array();
		foreach ( $retries as $invoice => $window ) {
			if ( (int) $window['member_id'] !== (int) $member_id ) {
				$kept[ $invoice ] = $window;
			}
		}
		if ( count( $kept ) !== count( $retries ) ) {
			update_option( 'lld_retries', $kept, false );
		}
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

	/**
	 * LLD_STRIPE_WEBHOOK is required on staging and production. Only ci and local may sign
	 * test notices with the shared test secret; staging never falls back to it.
	 */
	private static function webhook_secret() {
		if ( defined( 'LLD_STRIPE_WEBHOOK' ) && '' !== (string) LLD_STRIPE_WEBHOOK ) {
			return (string) LLD_STRIPE_WEBHOOK;
		}
		if ( ! in_array( lld_members_env(), array( 'ci', 'local' ), true ) ) {
			return '';
		}
		return lld_members_test_secret();
	}

	/**
	 * A Stripe key from wp-config.php. A live key is refused unless LLD_ENV is exactly production.
	 *
	 * @param string $kind secret or publishable.
	 * @return string|WP_Error The key, an empty string when it is not set, or an error for a refused live key.
	 */
	public static function stripe_key( $kind ) {
		$name = 'publishable' === $kind ? 'LLD_STRIPE_PUBLISHABLE' : 'LLD_STRIPE_SECRET';
		$key  = defined( $name ) ? (string) constant( $name ) : '';
		if ( self::live_key_refused( $key ) ) {
			return new WP_Error( 'lld_stripe_live', 'A live Stripe key is set outside production.' );
		}
		return $key;
	}

	/**
	 * True when any configured Stripe key is a live key outside production. Checkout stays closed then.
	 */
	public static function live_keys_refused() {
		foreach ( array( 'LLD_STRIPE_SECRET', 'LLD_STRIPE_PUBLISHABLE' ) as $name ) {
			if ( defined( $name ) && self::live_key_refused( (string) constant( $name ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $key Stripe key.
	 */
	private static function live_key_refused( $key ) {
		$production = defined( 'LLD_ENV' ) && 'production' === (string) LLD_ENV;
		if ( $production ) {
			return false;
		}
		foreach ( array( 'sk_live_', 'pk_live_', 'rk_live_' ) as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
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
			'orders'       => $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, ordered_at, related_order_id, charged_amount, tax_amount, charged_currency, discount_code, discount_amount, discount_currency, status, stripe_payment, stripe_invoice FROM ' . LLD_Members_Schema::table( 'order' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A ),
			'rewards'      => $wpdb->get_results( $wpdb->prepare( 'SELECT id, form, status, operation_key FROM ' . LLD_Members_Schema::table( 'reward' ) . ' WHERE recipient_member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A ),
			'referrals'    => $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, not_qualified_why, self_referral, friend_member_id FROM ' . LLD_Members_Schema::table( 'referral' ) . ' WHERE referrer_member_id = %d OR friend_member_id = %d ORDER BY id ASC', $member_id, $member_id ), ARRAY_A ),
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
