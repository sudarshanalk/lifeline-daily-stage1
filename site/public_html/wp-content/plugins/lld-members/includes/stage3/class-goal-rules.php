<?php
/**
 * Goal rules (P3-31 to P3-33; FR3-09, FR3-10, FR3-13, FR3-14, FR3-15, FR3-16): the pure arithmetic of the goal
 * journey, written from the owner-signed Goal Weight Timeline method sheet
 * (`04 Health Tools/Stage 3/P3-56-Goal-Weight-Timeline-Method-Sheet.md`, signed 3 November 2026) and the Stage 3
 * safety matrix (P3-03). No WordPress, no database, no clock: LLD_Members_Goal_Journey reads the tables and the
 * screens show what this class answers.
 *
 * Every date is a local Y-m-d; every weight is in kg (the screens convert from the member's units).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure goal arithmetic: safety limits, progress, trend, streaks and bars.
 */
class LLD_Members_Goal_Rules {

	const VERSION = '1.0';

	/** Sheet section 1: the goal BMI must be 18.5 or more for every background. */
	const BMI_FLOOR = 18.5;
	/** Sheet section 1: the rates a member may choose, kg a week. */
	const RATES = array( 0.25, 0.5, 0.75, 1.0 );
	const DEFAULT_RATE = 0.5;
	/** Sheet section 1: 1,100 kcal a day of deficit per kg a week. */
	const DEFICIT_PER_KG = 1100;
	/** Sheet section 4: "On track" when the trend reaches the goal by the target date plus this many days. */
	const ON_TRACK_GRACE_DAYS = 7;
	/** Sheet section 4: at least this many weigh-ins in the last 4 weeks before a trend is shown. */
	const TREND_MIN_POINTS = 3;
	const TREND_WINDOW_DAYS = 28;
	/** Safety matrix (P3-03) `bp_goal_default_135_85`: the home-reading guideline default. */
	const BP_DEFAULT = array( 135, 85 );
	/** FR3-11: the weekly activity target of a plan (minutes of moderate activity). */
	const ACTIVITY_DEFAULT = 150;
	/** FR3-14: the easier activity offer takes a quarter off for one week (catalogue R-ADJ-01). */
	const EASIER_ACTIVITY_FRACTION = 0.75;
	const EASIER_ACTIVITY_MIN = 60;

	/**
	 * Member-facing wording. The weight lines are the signed sheet's (section 3) verbatim; the rest is screen copy
	 * for the owner's wording list.
	 */
	const MESSAGES = array(
		'bmi_floor'       => 'A goal of {goal} kg would put you below the healthy range for your height (BMI {bmi}). The lowest weight in the healthy range for you is about {lowest} kg. Would you like to use that?',
		'rate_cap'        => "That's faster than the safe rate of about {cap} kg a week. At a safe rate you'd reach {goal} kg by about {safe_date}. Shall we use that date?",
		'over_10'         => 'Losing more than 10 % is a big change. Setting a first goal of 5 to 10 % ({milestone} kg) and then a new one works well for most people.',
		'plateau'         => "Weight loss isn't a straight line: expect plateaus and water-weight swings. We check your real trend every week and adjust.",
		'pregnancy_stop'  => "Weight-loss goals aren't advised during pregnancy or breastfeeding. Your midwife or doctor can advise.",
		'gain_not_now'    => 'Goals to gain weight are not offered yet. You can choose to keep your weight steady, or another kind of goal.',
		'maintain'        => 'A goal to keep your weight steady. The Keep It Off track is the best fit for this.',
		'trend_wait'      => "Keep weighing in; we'll show your trend after 3 weigh-ins.",
		'on_track'        => 'On track',
		'behind'          => 'Behind',
		'check_doctor'    => 'Check with your doctor that this target is right for you.',
		'bp_default'      => 'The guideline level for home readings is below 135/85. Your doctor may have set a different target for you.',
		'third_goal'      => 'You already have a main goal and an extra goal. Finish or stop one before adding another.',
		'under_a_minute'  => 'This takes under a minute.',
	);

	/**
	 * The goal types a member can set (data model: Goal.goal_type), with the measure, unit and direction of each.
	 * `plan_track` is the track the plan suggests (FR3-11); `item` the safety-matrix item that can refuse it.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types() {
		return array(
			'lose_weight'          => array( 'label' => 'Lose weight', 'measure' => 'weight', 'unit' => 'kg', 'direction' => 'down', 'plan_track' => 'healthy-weight', 'item' => 'goal:lose_weight', 'baseline' => array( 'weight', 'waist' ) ),
			'keep_weight'          => array( 'label' => 'Keep my weight steady', 'measure' => 'weight', 'unit' => 'kg', 'direction' => 'hold', 'plan_track' => 'keep-it-off', 'item' => 'goal:keep_weight', 'baseline' => array( 'weight', 'waist' ) ),
			'lower_blood_pressure' => array( 'label' => 'Lower my blood pressure', 'measure' => 'blood_pressure', 'unit' => 'mmHg', 'direction' => 'down', 'plan_track' => 'heart-health', 'item' => 'goal:lower_blood_pressure', 'baseline' => array( 'blood_pressure' ) ),
			'blood_sugar'          => array( 'label' => 'Improve my blood sugar', 'measure' => 'hba1c', 'unit' => '%', 'direction' => 'down', 'plan_track' => 'blood-sugar', 'item' => 'goal:blood_sugar', 'baseline' => array( 'weight', 'waist' ) ),
			'activity'             => array( 'label' => 'Be more active', 'measure' => 'activity_minutes', 'unit' => 'minutes', 'direction' => 'up', 'plan_track' => 'fitness', 'item' => 'goal:activity', 'baseline' => array( 'activity' ) ),
			'strength'             => array( 'label' => 'Get stronger', 'measure' => 'reps', 'unit' => 'reps', 'direction' => 'up', 'plan_track' => 'fitness', 'item' => 'goal:strength', 'baseline' => array() ),
			'sleep'                => array( 'label' => 'Sleep better', 'measure' => 'sleep_hours', 'unit' => 'hours', 'direction' => 'up', 'plan_track' => 'sleep-stress', 'item' => 'goal:sleep', 'baseline' => array( 'sleep' ) ),
		);
	}

	/**
	 * The goal types open to a member with these safety situations (P3-03 Part B, goals feature). `unknown` refuses
	 * every goal (the general wellbeing path, FR3-09 AS-02); the weight rows refuse the loss goal.
	 *
	 * @param string[] $situations Safety situations in force.
	 * @return array<string,array<string,mixed>> Allowed types, in catalogue order.
	 */
	public static function allowed_types( array $situations ) {
		if ( in_array( 'unknown', $situations, true ) ) {
			return array();
		}
		$out = array();
		foreach ( self::types() as $key => $type ) {
			if ( 'lose_weight' === $key && array_intersect( array( 'eating_disorder', 'pregnancy', 'bmi_under_18_5' ), $situations ) ) {
				continue;
			}
			if ( 'keep_weight' === $key && in_array( 'eating_disorder', $situations, true ) ) {
				continue; // the eating-disorder row refuses every weight goal
			}
			$out[ $key ] = $type;
		}
		return $out;
	}

	/**
	 * The safety wording shown before a goal is set (FR3-09 AS-07): the matrix notes for these situations, in order.
	 *
	 * @param string[] $situations Safety situations in force.
	 * @return string[] Note keys (P3-03 Part B, goals column).
	 */
	public static function safety_notes( array $situations ) {
		$notes = array(
			'pregnancy'           => array( 'midwife_note', 'bp_goal_midwife_sets_this' ),
			'eating_disorder'     => array( 'ed.weight-goal-screen' ),
			'diabetes'            => array( 'check_with_your_doctor', 'medicine_note' ),
			'blood_pressure'      => array( 'bp_goal_default_135_85', 'check_with_your_doctor', 'high_intensity_note' ),
			'heart_rate_medicine' => array( 'activity_goals_by_minutes' ),
			'heart_disease'       => array( 'ask_cardiac_rehab_team' ),
			'age_65_plus'         => array( 'rate_cap_0_5', 'muscle_note' ),
			'bmi_under_18_5'      => array( 'maintain_or_gain_only', 'underweight_wording_once' ),
		);
		$out   = array();
		foreach ( $situations as $s ) {
			if ( isset( $notes[ $s ] ) ) {
				$out = array_merge( $out, $notes[ $s ] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * 'Help me choose' (FR3-09 AS-04): one suggested goal type from what the member has told us.
	 *
	 * @param array $facts situations (string[]), bmi (float|null), stage2_goals (slugs the member chose in Stage 2).
	 * @return array{type:string,reason:string}|null Null when no goal may be set.
	 */
	public static function suggest( array $facts ) {
		$situations = isset( $facts['situations'] ) ? (array) $facts['situations'] : array();
		$allowed    = self::allowed_types( $situations );
		if ( ! $allowed ) {
			return null;
		}
		$bmi    = isset( $facts['bmi'] ) && is_numeric( $facts['bmi'] ) ? (float) $facts['bmi'] : null;
		$stage2 = isset( $facts['stage2_goals'] ) ? array_map( 'strval', (array) $facts['stage2_goals'] ) : array();
		$pick   = function ( $type, $reason ) use ( $allowed ) {
			return isset( $allowed[ $type ] ) ? array( 'type' => $type, 'reason' => $reason ) : null;
		};
		$order = array();
		if ( in_array( 'blood_pressure', $situations, true ) ) {
			$order[] = array( 'lower_blood_pressure', 'You told us about high blood pressure, and home readings are the best way to see it come down.' );
		}
		if ( array_intersect( array( 'diabetes', 'prediabetes' ), $situations ) ) {
			$order[] = array( 'blood_sugar', 'You told us about your blood sugar, and steady habits make the biggest difference to it.' );
		}
		if ( null !== $bmi && $bmi >= 25 ) {
			$order[] = array( 'lose_weight', 'From your height and weight, a steady weight loss would help your health most.' );
		}
		$map = array( 'healthy-weight' => 'lose_weight', 'blood-sugar' => 'blood_sugar', 'fitness' => 'activity', 'sleep-stress' => 'sleep', 'heart' => 'lower_blood_pressure', 'sleep' => 'sleep', 'stress' => 'sleep' );
		foreach ( $stage2 as $slug ) {
			if ( isset( $map[ $slug ] ) ) {
				$order[] = array( $map[ $slug ], 'It matches the goal you chose when you joined.' );
			}
		}
		$order[] = array( 'activity', 'Moving more is the goal with the widest benefits, whatever else you work on.' );
		foreach ( $order as $o ) {
			$found = $pick( $o[0], $o[1] );
			if ( $found ) {
				return $found;
			}
		}
		$first = array_keys( $allowed );
		return array( 'type' => $first[0], 'reason' => 'A good first goal.' );
	}

	// ---- Weight goals (sheet sections 1 to 3) -----------------------------------------------------------------------

	/**
	 * Check a weight goal and work out its timeline (sheet section 1.2; FR3-09 AS-05, FR3-14 AS-02).
	 *
	 * @param array $in current_kg, goal_kg, height_cm, rate (kg a week, or null), target_date (Y-m-d, or null),
	 *                  today (Y-m-d), situations (string[]), eating_pnts (bool: "prefer not to say").
	 * @return array ok (bool), code (why not: pregnancy, eating_disorder, underweight, gain, bmi_floor, rate_cap,
	 *               invalid), message, maintain (bool), goal_bmi, cap, rate, weeks, target_date, milestone5, deficit,
	 *               offered (rate|date|goal_kg, or null), notes (message keys), rates (the rates open to the member).
	 */
	public static function weight_check( array $in ) {
		$out = array(
			'ok'          => false,
			'code'        => null,
			'message'     => '',
			'maintain'    => false,
			'goal_bmi'    => null,
			'cap'         => null,
			'rate'        => null,
			'weeks'       => null,
			'target_date' => null,
			'milestone5'  => null,
			'deficit'     => null,
			'offered'     => null,
			'notes'       => array(),
			'rates'       => array(),
			'version'     => self::VERSION,
		);
		$situations = isset( $in['situations'] ) ? (array) $in['situations'] : array();
		$current    = isset( $in['current_kg'] ) && is_numeric( $in['current_kg'] ) ? (float) $in['current_kg'] : null;
		$goal       = isset( $in['goal_kg'] ) && is_numeric( $in['goal_kg'] ) ? (float) $in['goal_kg'] : null;
		$height     = isset( $in['height_cm'] ) && is_numeric( $in['height_cm'] ) ? (float) $in['height_cm'] : null;
		$today      = isset( $in['today'] ) ? (string) $in['today'] : '';
		if ( in_array( 'pregnancy', $situations, true ) ) {
			return self::refused( $out, 'pregnancy', self::MESSAGES['pregnancy_stop'] );
		}
		if ( in_array( 'eating_disorder', $situations, true ) ) {
			return self::refused( $out, 'eating_disorder', '' ); // the screen shows the matrix wording, no numbers
		}
		if ( null === $current || null === $goal || $current < 25 || $current > 350 || $goal < 25 || $goal > 350 || null === $height || $height < 120 || $height > 230 || ! preg_match( '/^\d{4}-\d\d-\d\d$/', $today ) ) {
			return self::refused( $out, 'invalid', 'Check the numbers and try again.' );
		}
		$h2              = ( $height / 100 ) * ( $height / 100 );
		$current_bmi     = self::round1( $current / $h2 );
		$out['goal_bmi'] = self::round1( $goal / $h2 );
		if ( abs( $goal - $current ) < 0.05 ) {
			$out['ok']       = true;
			$out['maintain'] = true;
			$out['message']  = self::MESSAGES['maintain'];
			return $out;
		}
		if ( $goal > $current ) {
			return self::refused( $out, 'gain', self::MESSAGES['gain_not_now'] ); // D3-08: loss and maintain only at launch
		}
		if ( $current_bmi < self::BMI_FLOOR || in_array( 'bmi_under_18_5', $situations, true ) ) {
			return self::refused( $out, 'underweight', '' ); // the Healthy Weight Check's underweight wording, once
		}
		if ( $out['goal_bmi'] < self::BMI_FLOOR ) {
			$lowest         = self::round1( self::BMI_FLOOR * $h2 );
			$out['offered'] = array( 'goal_kg' => $lowest );
			return self::refused( $out, 'bmi_floor', self::fill( 'bmi_floor', array( 'goal' => $goal, 'bmi' => $out['goal_bmi'], 'lowest' => $lowest ) ) );
		}
		$over65 = in_array( 'age_65_plus', $situations, true );
		$cap    = min( 1.0, self::round2( 0.01 * $current ) );
		if ( $over65 ) {
			$cap = min( $cap, 0.5 );
		}
		$out['cap'] = $cap;
		$rates      = array();
		foreach ( self::RATES as $r ) {
			if ( $r <= $cap + 1e-9 && ( ! $over65 || $r <= 0.5 ) && ( empty( $in['eating_pnts'] ) || $r <= 0.5 ) ) {
				$rates[] = $r;
			}
		}
		if ( ! $rates ) {
			$rates = array( 0.25 );
		}
		$out['rates'] = $rates;
		$diff         = $current - $goal;
		$rate         = isset( $in['rate'] ) && is_numeric( $in['rate'] ) ? (float) $in['rate'] : null;
		$date         = isset( $in['target_date'] ) && preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $in['target_date'] ) ? (string) $in['target_date'] : null;
		if ( null === $rate && null === $date ) {
			$rate = in_array( self::DEFAULT_RATE, $rates, true ) ? self::DEFAULT_RATE : (float) end( $rates );
		}
		if ( null !== $rate ) {
			if ( $rate <= 0 ) {
				return self::refused( $out, 'invalid', 'Choose a rate.' );
			}
			if ( $rate > $cap + 1e-9 || ( $over65 && $rate > 0.5 ) || ( ! empty( $in['eating_pnts'] ) && $rate > 0.5 ) ) {
				$offered         = (float) end( $rates );
				$weeks           = self::weeks_for( $diff, $offered );
				$out['offered']  = array( 'rate' => $offered, 'weeks' => $weeks, 'date' => self::add_days( $today, 7 * $weeks ) );
				$out['rates']    = $rates;
				return self::refused( $out, 'rate_cap', self::fill( 'rate_cap', array( 'cap' => $cap, 'goal' => $goal, 'safe_date' => self::nice_date( $out['offered']['date'] ) ) ) );
			}
			$weeks = self::weeks_for( $diff, $rate );
		} else {
			$days = self::days_between( $today, $date );
			if ( $days < 7 ) {
				$days = 7;
			}
			$weeks   = (int) ceil( $days / 7 - 1e-9 );
			$implied = $diff / ( $days / 7 );
			if ( $implied > $cap + 1e-9 ) {
				$safe_weeks     = self::weeks_for( $diff, $cap );
				$out['offered'] = array( 'rate' => $cap, 'weeks' => $safe_weeks, 'date' => self::add_days( $today, 7 * $safe_weeks ) );
				return self::refused( $out, 'rate_cap', self::fill( 'rate_cap', array( 'cap' => $cap, 'goal' => $goal, 'safe_date' => self::nice_date( $out['offered']['date'] ) ) ) );
			}
			$rate = self::round2( $implied );
		}
		$out['ok']          = true;
		$out['rate']        = $rate;
		$out['weeks']       = $weeks;
		$out['target_date'] = self::add_days( $today, 7 * $weeks );
		$out['milestone5']  = self::round1( 0.95 * $current );
		$out['deficit']     = (int) round( self::DEFICIT_PER_KG * $rate );
		if ( $diff > 0.10 * $current + 1e-9 ) {
			$out['notes'][] = 'over_10';
		}
		$out['notes'][] = 'plateau';
		if ( $over65 ) {
			$out['notes'][] = 'over65';
		}
		return $out;
	}

	/**
	 * A later date for a goal that is behind (FR3-14 AS-01, catalogue R-ADJ-01): from the current weight at a rate no
	 * higher than the member's choice or the cap (sheet section 4). Never earlier than today + 7.
	 *
	 * @param float    $current_kg Latest weight.
	 * @param float    $goal_kg    Goal weight.
	 * @param float    $rate       The plan's rate, kg a week.
	 * @param float    $cap        The member's cap, kg a week.
	 * @param string   $today      Local date.
	 * @return array{rate:float,weeks:int,date:string}
	 */
	public static function later_date( $current_kg, $goal_kg, $rate, $cap, $today ) {
		$rate  = min( (float) $rate > 0 ? (float) $rate : (float) $cap, (float) $cap );
		$rate  = max( 0.25, $rate );
		$diff  = max( 0.0, (float) $current_kg - (float) $goal_kg );
		$weeks = max( 1, self::weeks_for( $diff, $rate ) );
		return array( 'rate' => $rate, 'weeks' => $weeks, 'date' => self::add_days( $today, 7 * $weeks ) );
	}

	/**
	 * The easier activity target for one week (R-ADJ-01 "easier activity"): three quarters, never under 60 minutes.
	 *
	 * @param int $minutes The plan's weekly minutes.
	 */
	public static function easier_activity( $minutes ) {
		return max( self::EASIER_ACTIVITY_MIN, (int) round( (int) $minutes * self::EASIER_ACTIVITY_FRACTION ) );
	}

	// ---- Progress (FR3-13; sheet section 4) --------------------------------------------------------------------------

	/**
	 * How far the member is: "{lost} of {total} kg, {pct} %", never negative (sheet section 2, progress wording).
	 *
	 * @param float|null $start     Starting value.
	 * @param float|null $target    Target value.
	 * @param float|null $latest    Latest value.
	 * @param string     $direction down, up or hold.
	 * @return array{done:float,total:float,pct:int|null,remaining:float}|null Null without the numbers.
	 */
	public static function progress( $start, $target, $latest, $direction = 'down' ) {
		if ( ! is_numeric( $start ) || ! is_numeric( $target ) || ! is_numeric( $latest ) ) {
			return null;
		}
		$start  = (float) $start;
		$target = (float) $target;
		$latest = (float) $latest;
		if ( 'hold' === $direction ) {
			return array( 'done' => 0.0, 'total' => 0.0, 'pct' => null, 'remaining' => self::round1( $latest - $target ) );
		}
		$total = 'up' === $direction ? $target - $start : $start - $target;
		$done  = 'up' === $direction ? $latest - $start : $start - $latest;
		$total = max( 0.0, $total );
		$done  = max( 0.0, min( $total, $done ) );
		$pct   = $total > 0 ? (int) floor( $done / $total * 100 + 0.5 ) : 100;
		return array( 'done' => self::round1( $done ), 'total' => self::round1( $total ), 'pct' => $pct, 'remaining' => self::round1( $total - $done ) );
	}

	/**
	 * On track or behind (sheet section 4): the least-squares trend of the last 4 weeks of daily values (3 or more)
	 * projects the goal on or before the target date plus 7 days.
	 *
	 * @param array<string,float> $points      Daily values by local date.
	 * @param float               $target      Target value.
	 * @param string|null         $target_date Target date.
	 * @param string              $today       Local date.
	 * @param string              $direction   down or up.
	 * @return array{status:string,projected:?string,late_days:?int,points:int,slope_per_week:?float}
	 *               status: on_track, behind, waiting (fewer than 3 points), reached, or none (no date).
	 */
	public static function on_track( array $points, $target, $target_date, $today, $direction = 'down' ) {
		$from = self::add_days( $today, -self::TREND_WINDOW_DAYS + 1 );
		$xs   = array();
		$ys   = array();
		ksort( $points );
		foreach ( $points as $date => $value ) {
			if ( ! is_numeric( $value ) || $date < $from || $date > $today ) {
				continue;
			}
			$xs[] = self::days_between( $from, $date );
			$ys[] = (float) $value;
		}
		$n   = count( $xs );
		$out = array( 'status' => 'waiting', 'projected' => null, 'late_days' => null, 'points' => $n, 'slope_per_week' => null );
		if ( ! $target_date ) {
			$out['status'] = 'none';
			return $out;
		}
		if ( $n < self::TREND_MIN_POINTS ) {
			return $out;
		}
		$mx = array_sum( $xs ) / $n;
		$my = array_sum( $ys ) / $n;
		$sxx = 0.0;
		$sxy = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$sxx += ( $xs[ $i ] - $mx ) * ( $xs[ $i ] - $mx );
			$sxy += ( $xs[ $i ] - $mx ) * ( $ys[ $i ] - $my );
		}
		$slope = $sxx > 0 ? $sxy / $sxx : 0.0; // per day
		$out['slope_per_week'] = self::round2( $slope * 7 );
		$last_x   = end( $xs );
		$last_y   = end( $ys );
		$last_day = self::add_days( $from, (int) $last_x );
		$toward   = 'up' === $direction ? $slope > 1e-9 : $slope < -1e-9;
		$reached  = 'up' === $direction ? $last_y >= (float) $target : $last_y <= (float) $target;
		if ( $reached ) {
			$out['status']    = 'reached';
			$out['projected'] = $last_day;
			$out['late_days'] = 0;
			return $out;
		}
		if ( ! $toward ) {
			$out['status']    = 'behind';
			$out['late_days'] = 9999;
			return $out;
		}
		$days_needed      = ( (float) $target - $last_y ) / $slope;
		$projected        = self::add_days( $last_day, (int) ceil( $days_needed - 1e-9 ) );
		$out['projected'] = $projected;
		$late             = self::days_between( $target_date, $projected );
		$out['late_days'] = max( 0, $late );
		$out['status']    = $late <= self::ON_TRACK_GRACE_DAYS ? 'on_track' : 'behind';
		return $out;
	}

	/**
	 * Goal reached (D3-09, FR3-15 AS-01): the 7-day mean of the daily values ending today is at or past the target.
	 *
	 * @param array<string,float> $points    Daily values by local date.
	 * @param float               $target    Target value.
	 * @param string              $today     Local date.
	 * @param string              $direction down or up.
	 */
	public static function reached( array $points, $target, $today, $direction = 'down' ) {
		$from   = self::add_days( $today, -6 );
		$values = array();
		foreach ( $points as $date => $value ) {
			if ( is_numeric( $value ) && $date >= $from && $date <= $today ) {
				$values[] = (float) $value;
			}
		}
		if ( ! $values ) {
			return false;
		}
		$mean = array_sum( $values ) / count( $values );
		return 'up' === $direction ? $mean >= (float) $target - 1e-9 : $mean <= (float) $target + 1e-9;
	}

	// ---- Weekly ring, streaks and bars (FR3-12 AS-02/04, FR3-16 AS-01) -------------------------------------------

	/**
	 * The weekly ring (FR3-12): active days in the week that holds today, counting Monday to Sunday or from the plan's
	 * start weekday. A missed day lowers the count and never touches anything else.
	 *
	 * @param string[]    $active_dates Days with a step done or any log.
	 * @param string      $today        Local date.
	 * @param string|null $plan_start   Plan start date, or null for Monday weeks.
	 * @return array{done:int,total:int,start:string,end:string}
	 */
	public static function weekly_ring( array $active_dates, $today, $plan_start = null ) {
		$start = self::week_start( $today, $plan_start );
		$end   = self::add_days( $start, 6 );
		$set   = array_fill_keys( $active_dates, true );
		$done  = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$day = self::add_days( $start, $i );
			if ( $day > $today ) {
				break;
			}
			if ( isset( $set[ $day ] ) ) {
				$done++;
			}
		}
		return array( 'done' => $done, 'total' => 7, 'start' => $start, 'end' => $end );
	}

	/**
	 * Days in a row of activity, ending today or yesterday (today may not be logged yet).
	 *
	 * @param string[] $active_dates Days with a step done or any log.
	 * @param string   $today        Local date.
	 */
	public static function streak( array $active_dates, $today ) {
		$set = array_fill_keys( $active_dates, true );
		$day = isset( $set[ $today ] ) ? $today : self::add_days( $today, -1 );
		$n   = 0;
		while ( isset( $set[ $day ] ) ) {
			$n++;
			$day = self::add_days( $day, -1 );
		}
		return $n;
	}

	/**
	 * Weekly bars for the last N weeks (FR3-16 AS-01): active days per week, oldest first, the current week last.
	 *
	 * @param string[]    $active_dates Days with a step done or any log.
	 * @param string      $today        Local date.
	 * @param int         $weeks        Number of weeks.
	 * @param string|null $plan_start   Plan start date, or null for Monday weeks.
	 * @return array<int,array{label:string,start:string,end:string,days:int}>
	 */
	public static function weekly_bars( array $active_dates, $today, $weeks = 4, $plan_start = null ) {
		$set   = array_fill_keys( $active_dates, true );
		$start = self::week_start( $today, $plan_start );
		$out   = array();
		for ( $k = $weeks - 1; $k >= 0; $k-- ) {
			$ws   = self::add_days( $start, -7 * $k );
			$days = 0;
			for ( $i = 0; $i < 7; $i++ ) {
				$d = self::add_days( $ws, $i );
				if ( $d <= $today && isset( $set[ $d ] ) ) {
					$days++;
				}
			}
			$out[] = array( 'label' => 0 === $k ? 'This week' : ( 1 === $k ? 'Last week' : $k . ' weeks ago' ), 'start' => $ws, 'end' => self::add_days( $ws, 6 ), 'days' => $days );
		}
		return $out;
	}

	/**
	 * A trend series for the progress page (FR3-16 AS-03): the daily values in the window, oldest first, with the
	 * first and last values and the change.
	 *
	 * @param array<string,float|array> $points Daily values by date (a blood pressure day is [sys, dia]).
	 * @param string                    $today  Local date.
	 * @param int                       $days   Window in days (14 for Free members, FR3-18 AS-03).
	 * @return array{rows:array<int,array{date:string,value:mixed}>,first:mixed,last:mixed,change:?float}
	 */
	public static function trend( array $points, $today, $days ) {
		$from = self::add_days( $today, -$days + 1 );
		ksort( $points );
		$rows = array();
		foreach ( $points as $date => $value ) {
			if ( $date >= $from && $date <= $today ) {
				$rows[] = array( 'date' => $date, 'value' => $value );
			}
		}
		$first  = $rows ? $rows[0]['value'] : null;
		$last   = $rows ? $rows[ count( $rows ) - 1 ]['value'] : null;
		$change = is_numeric( $first ) && is_numeric( $last ) ? self::round1( (float) $last - (float) $first ) : null;
		return array( 'rows' => $rows, 'first' => $first, 'last' => $last, 'change' => $change );
	}

	/**
	 * Badge names never mention weight lost (FR3-16 AS-04).
	 *
	 * @param string $text A badge name or description.
	 */
	public static function badge_text_allowed( $text ) {
		return ! preg_match( '/\b(kg|kilograms?|kilos?|lbs?|pounds?)\b/i', (string) $text );
	}

	// ---- Helpers -----------------------------------------------------------------------------------------------------

	/** ceil(diff ÷ rate) with the sheet's 1e-9 tolerance (0.4 kg at 0.5 → 1 week). */
	public static function weeks_for( $diff, $rate ) {
		if ( $rate <= 0 ) {
			return 0;
		}
		return max( 0, (int) ceil( (float) $diff / (float) $rate - 1e-9 ) );
	}

	/**
	 * The start of the week that holds $today: the plan's start weekday, else Monday.
	 *
	 * @param string      $today      Local date.
	 * @param string|null $plan_start Plan start date.
	 */
	public static function week_start( $today, $plan_start = null ) {
		if ( $plan_start && preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $plan_start ) ) {
			$since = self::days_between( $plan_start, $today );
			$off   = ( ( $since % 7 ) + 7 ) % 7;
			return self::add_days( $today, -$off );
		}
		return self::add_days( $today, -self::weekday( $today ) );
	}

	/** 0 Monday … 6 Sunday. */
	public static function weekday( $date ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $d ? ( (int) $d->format( 'N' ) ) - 1 : 0;
	}

	public static function add_days( $date, $days ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		if ( ! $d ) {
			return (string) $date;
		}
		return $d->modify( ( (int) $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
	}

	/** Days from $from to $to (negative when $to is earlier). */
	public static function days_between( $from, $to ) {
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, new DateTimeZone( 'UTC' ) );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, new DateTimeZone( 'UTC' ) );
		if ( ! $a || ! $b ) {
			return 0;
		}
		return (int) round( ( $b->getTimestamp() - $a->getTimestamp() ) / 86400 );
	}

	/** "Monday 16 November 2026". */
	public static function nice_date( $date ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $d ? $d->format( 'l j F Y' ) : (string) $date;
	}

	public static function round1( $v ) {
		return round( (float) $v, 1, PHP_ROUND_HALF_UP );
	}

	public static function round2( $v ) {
		return round( (float) $v, 2, PHP_ROUND_HALF_UP );
	}

	/**
	 * A number as the member reads it: whole numbers plain, others to the decimals they have (at most 2).
	 *
	 * @param mixed $v Number.
	 */
	public static function plain( $v ) {
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		if ( is_float( $v ) ) {
			$s = rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
			return '' === $s || '-0' === $s ? '0' : $s;
		}
		return (string) $v;
	}

	/**
	 * Fill one of MESSAGES.
	 *
	 * @param string              $key    Message key.
	 * @param array<string,mixed> $values Gap => value.
	 */
	public static function fill( $key, array $values = array() ) {
		if ( ! isset( self::MESSAGES[ $key ] ) ) {
			return '';
		}
		$text = self::MESSAGES[ $key ];
		foreach ( $values as $gap => $value ) {
			$text = str_replace( '{' . $gap . '}', is_int( $value ) || is_float( $value ) ? self::plain( $value ) : (string) $value, $text );
		}
		return $text;
	}

	/** kg → the member's unit. */
	public static function show_weight( $kg, $imperial ) {
		if ( ! is_numeric( $kg ) ) {
			return '';
		}
		return $imperial ? self::plain( self::round1( (float) $kg / 0.45359237 ) ) . ' lb' : self::plain( self::round1( (float) $kg ) ) . ' kg';
	}

	/** cm → the member's unit. */
	public static function show_length( $cm, $imperial ) {
		if ( ! is_numeric( $cm ) ) {
			return '';
		}
		return $imperial ? self::plain( self::round1( (float) $cm / 2.54 ) ) . ' in' : self::plain( self::round1( (float) $cm ) ) . ' cm';
	}

	/** A typed weight in the member's unit → kg. */
	public static function to_kg( $value, $imperial ) {
		return $imperial ? self::round1( (float) $value * 0.45359237 ) : self::round1( (float) $value );
	}

	/** A typed length in the member's unit → cm. */
	public static function to_cm( $value, $imperial ) {
		return $imperial ? self::round1( (float) $value * 2.54 ) : self::round1( (float) $value );
	}

	private static function refused( array $out, $code, $message ) {
		$out['ok']      = false;
		$out['code']    = $code;
		$out['message'] = $message;
		return $out;
	}
}
