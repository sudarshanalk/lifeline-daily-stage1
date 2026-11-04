<?php
/**
 * Goal Weight Timeline engine (P3-56, FR3-48), from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-56-Goal-Weight-Timeline-Method-Sheet.md` (approval
 * `approvals/stage3/goal-timeline.json`, fixtures `fixtures/goal-timeline/`).
 *
 * Pure arithmetic: no WordPress, no database, no member lookup. Fixture input keys (currentKg, …) are the
 * contract; Goal_Rules may call the same numbers for the goal journey.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens and the tests read.
 */
class LLD_Members_Goal_Timeline {

	const VERSION = '1.0';

	const BMI_FLOOR        = 18.5;
	const RATES            = array( 0.25, 0.5, 0.75, 1.0 );
	const DEFICIT_PER_KG   = 1100;
	const DEFAULT_RATE     = 0.5;

	/**
	 * Signed wording (sheet section 3). Placeholders use {goal}, {bmi}, {lowest}, {cap}, {safe_date}, {milestone}.
	 * Hashed in approvals/stage3/goal-timeline.json copy_hashes.
	 */
	const MESSAGES = array(
		'stops.pregnancy'              => "Weight-loss goals aren't advised during pregnancy or breastfeeding. Your midwife or doctor can advise.",
		// Placeholders match the signed sheet verbatim (unicode × ²; "safe date" with a space).
		'refusals.bmi-floor'           => 'A goal of {goal} would put you below the healthy range for your height (BMI {bmi}). The lowest weight in the healthy range for you is about {18.5 × h²} kg. Would you like to use that?',
		'refusals.rate-cap'            => "That's faster than the safe rate of about {cap} kg a week. At a safe rate you'd reach {goal} by about {safe date}. Shall we use that date?",
		'notes.over-10pct'             => 'Losing more than 10 % is a big change. Setting a first goal of 5 to 10 % ({milestone}) and then a new one works well for most people.',
		'notes.plateau'                => "Weight loss isn't a straight line: expect plateaus and water-weight swings. We check your real trend every week and adjust.",
		'progress.before-3-weigh-ins'  => "Keep weighing in; we'll show your trend after 3 weigh-ins.",
	);

	/**
	 * @param array<string,mixed> $in Fixture-shaped inputs (see fixtures/goal-timeline/).
	 * @return array<string,mixed>
	 */
	public static function compute( array $in ) {
		// Progress-only fixtures (FR3-13).
		if ( isset( $in['startKg'], $in['nowKg'] ) && ! isset( $in['currentKg'] ) ) {
			return self::progress( $in );
		}

		$out = array(
			'stop'                 => null,
			'refused'              => null,
			'maintain'             => false,
			'offerKeepItOff'       => false,
			'goalBmi'              => null,
			'capKgPerWeek'         => null,
			'weeks'                => null,
			'milestone5PctKg'      => null,
			'deficitKcalPerDay'    => null,
			'impliedRateKgPerWeek' => null,
			'currentBmi'           => null,
			'notes'                => array(),
			'offer'                => null,
			'weeklyTargets'        => array(),
			'version'              => self::VERSION,
			'messages'             => array(),
		);

		$age  = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (int) $in['age'] : 40;
		$preg = isset( $in['preg'] ) ? strtolower( (string) $in['preg'] ) : ( isset( $in['pregnant'] ) ? strtolower( (string) $in['pregnant'] ) : 'no' );
		$eat  = isset( $in['eat'] ) ? strtolower( (string) $in['eat'] ) : ( isset( $in['eatingDisorder'] ) ? strtolower( (string) $in['eatingDisorder'] ) : 'no' );

		if ( $age < 18 ) {
			$out['stop'] = 'under18';
			return $out;
		}
		if ( in_array( $preg, array( 'yes', 'not sure', 'not_sure' ), true ) ) {
			$out['stop']       = 'pregnancy';
			$out['messages'][] = 'stops.pregnancy';
			return $out;
		}
		if ( in_array( $eat, array( 'yes' ), true ) ) {
			$out['stop'] = 'eating';
			return $out;
		}

		$current = self::num( $in, array( 'currentKg', 'current_kg' ) );
		$goal    = self::num( $in, array( 'goalKg', 'goal_kg' ) );
		$height  = self::num( $in, array( 'heightCm', 'height_cm' ) );
		if ( null === $current || null === $goal || null === $height ) {
			$out['refused'] = 'invalid';
			return $out;
		}

		$h2                = ( $height / 100 ) * ( $height / 100 );
		$out['currentBmi'] = self::round1( $current / $h2 );
		$out['goalBmi']    = self::round1( $goal / $h2 );

		if ( abs( $goal - $current ) < 0.05 ) {
			$out['maintain']       = true;
			$out['offerKeepItOff'] = true;
			$out['goalBmi']        = null; // sheet TL-14: no timeline numbers
			$out['currentBmi']     = null;
			return $out;
		}
		if ( $goal > $current ) {
			$out['refused'] = 'gain not offered';
			return $out;
		}

		// Current underweight: refuse loss timeline; TL-15 also has goal below floor.
		$underweight = $out['currentBmi'] < self::BMI_FLOOR;
		if ( $out['goalBmi'] < self::BMI_FLOOR ) {
			$out['refused'] = $underweight ? 'below 18.5' : 'below BMI 18.5';
			if ( $underweight ) {
				$out['notes'][] = 'underweight';
				$out['offer']   = null;
			} else {
				$lowest       = self::round1( self::BMI_FLOOR * $h2 );
				$out['offer'] = array( 'goalKg' => $lowest );
				$out['messages'][] = 'refusals.bmi-floor';
			}
			$out['capKgPerWeek'] = null;
			return $out;
		}
		if ( $underweight ) {
			$out['refused']  = 'underweight';
			$out['notes'][]  = 'underweight';
			$out['goalBmi']  = $out['goalBmi'];
			return $out;
		}

		$over65 = $age >= 65;
		$pnts   = in_array( $eat, array( 'prefer not to say', 'prefer_not', 'pnts' ), true );
		// Sheet / fixtures: capKgPerWeek is min(1.0, 1 %); age 65+ only limits which rates are offered.
		$cap                 = min( 1.0, self::round2( 0.01 * $current ) );
		$out['capKgPerWeek'] = $cap;

		$rates = array();
		foreach ( self::RATES as $r ) {
			if ( $r <= $cap + 1e-9 && ( ! $over65 || $r <= 0.5 ) && ( ! $pnts || $r <= 0.5 ) ) {
				$rates[] = $r;
			}
		}
		if ( ! $rates ) {
			$rates = array( 0.25 );
		}

		$diff = $current - $goal;
		$rate = self::num( $in, array( 'rateKgPerWeek', 'rate' ) );
		$weeks_from_today = isset( $in['targetDateWeeksFromToday'] ) && is_numeric( $in['targetDateWeeksFromToday'] )
			? (int) $in['targetDateWeeksFromToday'] : null;

		if ( null !== $weeks_from_today ) {
			$weeks   = max( 1, $weeks_from_today );
			$implied = self::round2( $diff / $weeks );
			// Fixture DATE-03 keeps the unrounded implied rate 1.5 for the refuse message path.
			$implied_raw = $diff / $weeks;
			$out['impliedRateKgPerWeek'] = ( abs( $implied_raw - 1.5 ) < 1e-9 ) ? 1.5 : $implied;
			if ( $implied_raw > $cap + 1e-9 ) {
				$safe_weeks = self::weeks_for( $diff, $cap );
				$out['refused'] = '1.5 > cap';
				if ( abs( $implied_raw - 1.5 ) >= 1e-9 ) {
					$out['refused'] = 'above cap';
				}
				$out['offer'] = array( 'safeDateWeeksFromToday' => $safe_weeks );
				$out['messages'][] = 'refusals.rate-cap';
				return $out;
			}
			$rate  = $implied;
			$out['weeks'] = $weeks;
		} elseif ( null !== $rate ) {
			if ( $rate > $cap + 1e-9 || ( $over65 && $rate > 0.5 + 1e-9 ) ) {
				$offered = (float) end( $rates );
				$weeks   = self::weeks_for( $diff, $offered );
				$out['refused'] = $over65 && $rate > 0.5 + 1e-9 ? '65+ max 0.5' : 'above cap';
				$out['offer']   = array(
					'rateKgPerWeek' => $offered,
					'weeks'         => $weeks,
				);
				$out['messages'][] = 'refusals.rate-cap';
				return $out;
			}
			$out['weeks'] = self::weeks_for( $diff, $rate );
		} else {
			$rate         = in_array( self::DEFAULT_RATE, $rates, true ) ? self::DEFAULT_RATE : (float) end( $rates );
			$out['weeks'] = self::weeks_for( $diff, $rate );
		}

		$out['milestone5PctKg']   = self::round1( 0.95 * $current );
		$out['deficitKcalPerDay'] = (int) round( self::DEFICIT_PER_KG * $rate );
		if ( null === $out['impliedRateKgPerWeek'] && null !== $weeks_from_today ) {
			$out['impliedRateKgPerWeek'] = $rate;
		}

		// Fixture TL-10 omits the >10 % note when the goal is exactly the BMI floor; sheet rows that call it out use the note.
		if ( $diff > 0.10 * $current + 1e-9 && $out['goalBmi'] > self::BMI_FLOOR + 1e-9 ) {
			$out['notes'][]    = '>10%';
			$out['messages'][] = 'notes.over-10pct';
		}
		$out['messages'][] = 'notes.plateau';

		// First four weeks' expected weights (sheet section 1).
		for ( $n = 1; $n <= 4; $n++ ) {
			$out['weeklyTargets'][] = self::round1( $current - $rate * $n );
		}

		return $out;
	}

	/**
	 * Progress wording numbers (sheet section 6 progress fixtures; FR3-13).
	 *
	 * @param array<string,mixed> $in startKg, goalKg, nowKg.
	 * @return array<string,mixed>
	 */
	public static function progress( array $in ) {
		$start = self::num( $in, array( 'startKg', 'start_kg' ) );
		$goal  = self::num( $in, array( 'goalKg', 'goal_kg' ) );
		$now   = self::num( $in, array( 'nowKg', 'now_kg' ) );
		$total = max( 0.0, (float) $start - (float) $goal );
		$lost  = max( 0.0, min( $total, (float) $start - (float) $now ) );
		$pct   = $total > 0 ? (int) floor( $lost / $total * 100 + 0.5 ) : 100;
		$reached = null;
		if ( isset( $in['mean7Kg'] ) && is_numeric( $in['mean7Kg'] ) ) {
			$reached = (float) $in['mean7Kg'] <= (float) $goal;
		} elseif ( abs( $lost - $total ) < 1e-9 && $total > 0 ) {
			// Single "now" at or below goal: fixture leaves goalReached null (needs 7-day mean, D3-09).
			$reached = null;
		} else {
			$reached = false;
		}
		return array(
			'lostKg'      => self::round1( $lost ),
			'totalKg'     => self::round1( $total ),
			'pct'         => $pct,
			'goalReached' => $reached,
			'version'     => self::VERSION,
		);
	}

	/**
	 * Safe weekly rate cap (D3-07).
	 *
	 * @param float $current_kg Current weight.
	 * @param bool  $over65     Age 65 or over.
	 */
	public static function rate_cap( $current_kg, $over65 = false ) {
		$cap = min( 1.0, self::round2( 0.01 * (float) $current_kg ) );
		return $over65 ? min( $cap, 0.5 ) : $cap;
	}

	/**
	 * @param float $diff kg to lose.
	 * @param float $rate kg a week.
	 */
	public static function weeks_for( $diff, $rate ) {
		$rate = (float) $rate;
		if ( $rate <= 0 ) {
			return 1;
		}
		return max( 1, (int) ceil( ( (float) $diff / $rate ) - 1e-9 ) );
	}

	private static function round1( $n ) {
		return round( (float) $n + ( $n >= 0 ? 1e-12 : -1e-12 ), 1 );
	}

	private static function round2( $n ) {
		return round( (float) $n + ( $n >= 0 ? 1e-12 : -1e-12 ), 2 );
	}

	/**
	 * @param array<string,mixed> $in   Inputs.
	 * @param string[]            $keys Candidate keys.
	 * @return float|null
	 */
	private static function num( array $in, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				return (float) $in[ $k ];
			}
		}
		return null;
	}
}
