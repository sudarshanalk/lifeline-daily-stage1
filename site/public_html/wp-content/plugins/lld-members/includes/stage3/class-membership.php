<?php
/**
 * Launch prices and the membership card (P3-60, P3-62; FR3-62, FR3-64).
 *
 * Prices: with `prices_launch` on, Premium is US$9.99 a month or US$69.99 a year, tax included. They are kept in
 * their own option (lld_prices_launch), so the Stage 2 prices and their tests stay as they were with the switch
 * off, and the owner's price screen edits the launch prices once it is on. A member keeps the price they joined
 * at (the Stage 2 locked_amount, and the price on their Stripe subscription) when the price for new members
 * changes (TC-FR3-62-AS-04).
 *
 * Card: My subscription opens with the member's plan, price, renewal date, yearly saving, what Premium includes
 * and what they have done with it; a Free member sees an upgrade card instead.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prices for new members and the card on My subscription.
 */
class LLD_Members_Membership {

	/** FR3-62 launch prices, in cents. */
	const LAUNCH = array(
		'monthly' => 999,
		'yearly'  => 6999,
	);

	const LAUNCH_OPTION = 'lld_prices_launch';

	/** What Premium includes, in members' words (FR3-63). */
	const INCLUDES = array(
		'Your full guided plan, adjusted each week',
		'Every week and level of each track',
		'Your full history, long-term trends and insights',
		'Food tracker extras: barcode, your own targets and foods, recipes, copying meals and a weekly summary',
		'Meal plans, workout plans and monthly challenges',
		'Saved calculator history and as many reminders as you like',
	);

	public static function boot() {
		add_filter( 'lld_members_price_option', array( __CLASS__, 'price_option' ) );
		add_filter( 'lld_members_default_prices', array( __CLASS__, 'default_prices' ) );
		add_filter( 'lld_members_subscription_top', array( __CLASS__, 'subscription_top' ), 10, 3 );
	}

	public static function launch_on() {
		return LLD_Members_Features::enabled( 'prices_launch' );
	}

	/**
	 * @param string $option Stage 2 option name.
	 */
	public static function price_option( $option ) {
		return self::launch_on() ? self::LAUNCH_OPTION : $option;
	}

	/**
	 * @param array{yearly:int,monthly:int} $defaults Stage 2 defaults.
	 */
	public static function default_prices( $defaults ) {
		return self::launch_on() ? self::LAUNCH : $defaults;
	}

	/**
	 * What paying yearly saves against twelve monthly payments, in cents and as a whole percent.
	 * Launch prices: US$49.89, 42 % (FR3-62).
	 *
	 * @param int $monthly Monthly price in cents.
	 * @param int $yearly  Yearly price in cents.
	 * @return array{cents:int,percent:int,per_month:int}
	 */
	public static function saving( $monthly, $yearly ) {
		$twelve = 12 * (int) $monthly;
		$cents  = max( 0, $twelve - (int) $yearly );
		return array(
			'cents'     => $cents,
			'percent'   => $twelve > 0 ? (int) round( 100 * $cents / $twelve ) : 0,
			'per_month' => (int) round( (int) $yearly / 12 ),
		);
	}

	/**
	 * US$9.99; whole dollars drop the cents (US$5).
	 *
	 * @param int    $cents    Amount.
	 * @param string $currency Currency code.
	 */
	public static function money( $cents, $currency = 'usd' ) {
		$major  = number_format( (int) $cents / 100, 2, '.', '' );
		$major  = '.00' === substr( $major, -3 ) ? substr( $major, 0, -3 ) : $major;
		$symbol = array(
			'gbp' => '£',
			'eur' => '€',
		);
		return ( isset( $symbol[ strtolower( $currency ) ] ) ? $symbol[ strtolower( $currency ) ] : 'US$' ) . $major;
	}

	/**
	 * Pricing page wording under the yearly price (TC-FR3-62-AS-01).
	 *
	 * @param array{yearly:int,monthly:int} $prices Prices in cents.
	 */
	public static function yearly_line( $prices ) {
		$s = self::saving( $prices['monthly'], $prices['yearly'] );
		return 'tax included. That is ' . self::money( $s['per_month'] ) . ' a month, a saving of ' . $s['percent'] . ' % (' . self::money( $s['cents'] ) . ' a year).';
	}

	/**
	 * Counts on the card, from the same records as the progress page (TC-FR3-64-AS-02): workout sessions marked
	 * done (Stage 2 results), and days with anything logged (Stage 2 diary, Stage 3 food log or health readings).
	 *
	 * @param int $member_id Member number.
	 * @return array{workouts:int,days_logged:int}
	 */
	public static function counts( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$workouts  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'result' ) . " WHERE member_id = %d AND context_type = 'workout' AND result = 'done'", $member_id ) );
		$days      = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT d) FROM ('
				. 'SELECT local_date AS d FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d'
				. ' UNION SELECT entry_date FROM ' . LLD_Members_Stage3_Schema::table( 'food_log' ) . ' WHERE member_id = %d AND deleted_at IS NULL'
				. ' UNION SELECT DATE(taken_at) FROM ' . LLD_Members_Stage3_Schema::table( 'readings' ) . ' WHERE member_id = %d AND deleted_at IS NULL'
				. ') logged',
				$member_id,
				$member_id,
				$member_id
			)
		);
		return array(
			'workouts'    => $workouts,
			'days_logged' => $days,
		);
	}

	/**
	 * Filter: the card at the top of My subscription.
	 *
	 * @param string      $html      Earlier content.
	 * @param int         $member_id Member number.
	 * @param object|null $sub       Stage 2 subscription row.
	 */
	public static function subscription_top( $html, $member_id, $sub ) {
		if ( LLD_Members_Features::enabled( 'membership_card', $member_id ) ) {
			$html .= self::card( $member_id, $sub );
		}
		if ( class_exists( 'LLD_Members_Pause' ) ) {
			$html .= LLD_Members_Pause::section( $member_id, $sub );
		}
		return $html;
	}

	/**
	 * @param int         $member_id Member number.
	 * @param object|null $sub       Stage 2 subscription row.
	 */
	public static function card( $member_id, $sub ) {
		$prices = LLD_Members_Billing::shown_prices();
		$pause  = class_exists( 'LLD_Members_Pause' ) ? LLD_Members_Pause::open( $member_id ) : null;
		$paid   = $sub && in_array( strtolower( (string) $sub->access_state ), array( 'premium', 'cancelled' ), true ) && ( LLD_Members_Billing::has_premium( $member_id ) || ( $pause && 'active' === (string) $pause->status ) );
		if ( ! $paid ) {
			return self::upgrade_card( $prices );
		}
		$plan     = 'yearly' === (string) $sub->plan ? 'yearly' : 'monthly';
		$amount   = $sub->locked_amount ? (int) $sub->locked_amount : (int) $prices[ $plan ];
		$currency = $sub->locked_currency ? (string) $sub->locked_currency : 'usd';
		$counts   = self::counts( $member_id );
		$html     = '<section class="lld-card lld-membership-card" data-lld-membership="' . esc_attr( $plan ) . '"><h2>Your membership</h2>';
		$html    .= '<p class="lld-plan-name" data-lld-card-plan>' . esc_html( ucfirst( $plan ) . ' Premium' ) . '</p>';
		$html    .= '<p class="lld-plan-price" data-lld-card-price="' . $amount . '">' . esc_html( self::money( $amount, $currency ) . ( 'yearly' === $plan ? ' a year' : ' a month' ) . ', tax included' ) . '</p>';
		if ( 'cancelled' === strtolower( (string) $sub->access_state ) && $pause && 'active' === (string) $pause->status ) {
			$html .= '<p data-lld-card-renews="">' . esc_html( 'Paused, and cancelled: Premium will not return after the pause.' ) . '</p>';
		} elseif ( 'cancelled' === strtolower( (string) $sub->access_state ) ) {
			$html .= '<p data-lld-card-renews="">' . esc_html( 'Premium until ' . LLD_Members_Pause::day( (string) $sub->period_ends_on ) . '. It will not renew.' ) . '</p>';
		} else {
			$renews = $pause ? (string) $pause->ends_at : (string) $sub->period_ends_on;
			$html  .= '<p data-lld-card-renews="' . esc_attr( $renews ) . '">' . esc_html( 'Renews on ' . LLD_Members_Pause::day( $renews ) ) . '</p>';
		}
		if ( 'usd' === strtolower( $currency ) ) {
			$saving = 'yearly' === $plan ? self::saving( $prices['monthly'], $amount ) : self::saving( $amount, $prices['yearly'] );
			if ( $saving['cents'] > 0 ) {
				$html .= '<p data-lld-card-saving="' . $saving['cents'] . '">' . esc_html( 'yearly' === $plan ? 'You save ' . self::money( $saving['cents'] ) . ' a year compared with paying monthly.' : 'Paying yearly would save you ' . self::money( $saving['cents'] ) . ' a year.' ) . '</p>';
			}
		}
		$html .= '<h3>What Premium includes</h3><ul>';
		foreach ( self::INCLUDES as $line ) {
			$html .= '<li>' . esc_html( $line ) . '</li>';
		}
		$html .= '</ul><h3>What you have done</h3>';
		$html .= '<p data-lld-card-counts data-workouts="' . $counts['workouts'] . '" data-days="' . $counts['days_logged'] . '">' . esc_html( 'Workouts completed: ' . $counts['workouts'] . '. Days logged: ' . $counts['days_logged'] . '.' ) . '</p>';
		$html .= '<p class="lld-row">' . lld_button(
			array(
				'label'   => 'Manage my plan',
				'href'    => home_url( '/members/subscription/#lld-plan-actions' ),
				'variant' => 'secondary',
			)
		) . '</p></section>';
		return $html;
	}

	/**
	 * TC-FR3-64-AS-03: a Free member sees an upgrade card.
	 *
	 * @param array{yearly:int,monthly:int} $prices Prices for new members.
	 */
	public static function upgrade_card( $prices ) {
		$saving = self::saving( $prices['monthly'], $prices['yearly'] );
		$html   = '<section class="lld-card lld-upgrade" data-lld-membership="free"><h2>Go Premium</h2>';
		$html  .= '<p>' . esc_html( self::money( $prices['monthly'] ) . ' a month or ' . self::money( $prices['yearly'] ) . ' a year, tax included. Yearly saves ' . self::money( $saving['cents'] ) . '.' ) . '</p><ul>';
		foreach ( self::INCLUDES as $line ) {
			$html .= '<li>' . esc_html( $line ) . '</li>';
		}
		$html .= '</ul><p>Safety warnings and your data download are always free.</p>';
		$html .= lld_button(
			array(
				'label' => 'See Premium',
				'href'  => home_url( '/pricing/' ),
			)
		) . '</section>';
		return $html;
	}
}
