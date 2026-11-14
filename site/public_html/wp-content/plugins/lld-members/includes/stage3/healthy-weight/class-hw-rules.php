<?php
/**
 * Healthy Weight programme rules (P3-35; FR3-97 to FR3-110, FR3-36; rule catalogue version 1.3, section 9: the signed
 * `04 Health Tools/Stage 3/P3-02-Healthy-Weight-Programme-Rules-Draft.md` with the wording of
 * `P3-02-Healthy-Weight-Templates.md`, the method sheets P3-35, P3-55 and P3-56, and the approved food-rule tags).
 *
 * Static and pure: every function takes plain arrays and returns plain arrays naming the rule that decided. No
 * WordPress, no database, no clock (the caller passes `today`). The calorie target and weeks come from the signed
 * calculators (LLD_Members_Calorie_Target, LLD_Members_Goal_Timeline); the plateau test is the signed R-ADJ-02 of the
 * decision engine; the wording is LLD_Members_Rule_Catalogue::HW_TEMPLATES, filled only with numbers, dates and
 * names of approved items. Package P2 builds the inputs from the member's answers and logs and the approved content.
 *
 * Answer values used throughout (a skipped question is null, and a rule that needs it stays silent):
 *   time: under_10 | 10_30 | over_30           work: days | shifts | nights | irregular
 *   sleep: under_6 | 6_7 | 7_9 | over_9        stress, confidence: 1 to 10
 *   alcohol_freq: never | less_than_weekly | 1_2_days | 3_4_days | most_days
 *   alcohol_amount: 1_2 | 3_4 | 5_plus
 *   barrier: evening_snacking | stress_eating | eating_out | no_time | alcohol | not_sure
 *   activity_level: low | some | active | very_active
 *   sugary_drinks: never | less_than_weekly | weekly | daily (anything else keeps the drink-swap steps)
 *   eats_out: less_than_weekly | 1_2 | 3_plus, or a number a week
 *   food_rules: halal, kosher, no_pork, no_beef, pescatarian, vegetarian, vegan, no_eggs, dairy_free,
 *               no_onion_garlic, no_grains_pulses_fasting, jain (halal and jain exclude alcohol)
 *   conditions: high_blood_pressure, heart_disease, type2_diabetes, … ; eating_disorder: no | pnts | yes
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The R-HW rules.
 */
class LLD_Members_HW_Rules {

	/** R-HW-SAFE-05 / R-HW-NUM-05: days a week and drinks a time (method sheet 5.2; "5 or more" = 5, D3-57). */
	const DRINK_DAYS   = array(
		'never'            => 0,
		'less_than_weekly' => 0.5,
		'1_2_days'         => 1.5,
		'3_4_days'         => 3.5,
		'most_days'        => 6,
	);
	const DRINK_AMOUNT = array(
		'1_2'    => 1.5,
		'3_4'    => 3.5,
		'5_plus' => 5,
	);
	/** D3-56: one pictured drink. */
	const KCAL_PER_DRINK = 130;
	/** D3-55: the support card shows above this many pictured drinks a week. */
	const DRINK_LIMIT = 8;

	/** R-HW-STEP-01: barrier → habit tag. */
	const BARRIER_TAGS = array(
		'evening_snacking' => 'evening-eating',
		'stress_eating'    => 'stress-eating',
		'eating_out'       => 'eating-out',
		'no_time'          => 'short-activity',
		'alcohol'          => 'alcohol',
	);

	/** Focus blocks (FR3-104): slug => content id. */
	const BLOCKS = array(
		'evening-night-eating'    => 'HW-FB01',
		'stress-emotional-eating' => 'HW-FB02',
		'eating-out-social-life'  => 'HW-FB03',
		'breaking-a-plateau'      => 'HW-FB04',
		'alcohol'                 => 'HW-FB05',
		'sleep-and-weight'        => 'HW-FB06',
		'getting-stronger'        => 'HW-FB07',
		'festivals-and-fasting'   => 'HW-FB08',
		'shift-work'              => 'HW-FB09',
	);

	/** R-HW-BLOCK-01 step 3: barrier → block. */
	const BARRIER_BLOCKS = array(
		'evening_snacking' => 'evening-night-eating',
		'stress_eating'    => 'stress-emotional-eating',
		'eating_out'       => 'eating-out-social-life',
		'alcohol'          => 'alcohol',
	);

	/** The mindset notes the signed rules name (approved content HW-MN, 8 Nov 2026). */
	const NOTE_LOW_MOOD   = 'HW-MN-W4-4'; // Talk to yourself like a friend
	const NOTE_STRESS     = 'HW-MN-W8-1'; // Stress and eating
	const NOTE_SLEEP      = 'HW-MN-W1-6'; // Sleep and appetite
	const NOTE_MISSED_LOG = 'HW-MN-W2-6'; // Missed a log? Just log the next meal
	const NOTE_WEIGHT_UP  = 'HW-MN-W1-4'; // Weight goes up and down
	const NOTE_PLATEAU    = 'HW-MN-W7-4'; // Plateaus are part of the process
	const NOTE_FAMILY     = 'HW-MN-W6-5'; // Family and housemates
	const NOTE_CRAVINGS   = 'HW-MN-W3-3'; // Cravings rise and fall like a wave
	/** Notes that carry the approved support wording (FR3-105 AS-03; content question N4 as decided). */
	const SUPPORT_NOTES = array( 'HW-MN-W4-4', 'HW-MN-W8-1', 'HW-MN-W8-4', 'HW-MN-W8-5' );

	/** No note twice within this many days (FR3-106). */
	const NOTE_REPEAT_DAYS = 30;

	/**
	 * R-HW-QOD-01: the quick questions in their signed order, each with the signed rule that reads it. A question with
	 * no rule (null) is never asked ("A question without a signed rule is not asked").
	 */
	const QUESTIONS = array(
		'food_rules_allergies' => 'R-HW-FOOD-01',
		'eating_pattern'       => 'R-HW-STEP-02',
		'snack_time'           => 'R-HW-REM-01',
		'sugary_drinks'        => 'R-HW-STEP-02',
		'eating_out'           => 'R-HW-STEP-02',
		'cooking_time_skill'   => 'R-HW-FOOD-02',
		'food_budget'          => 'R-HW-FOOD-02',
		'favourite_cuisines'   => 'R-HW-FOOD-02',
		'activity_enjoyment'   => 'R-HW-STEP-06',
		'stress_eating'        => null,
		'tried_before'         => null,
	);

	/** Habit tags that drive no rule (H6): never used as a week's theme for a replacement step. */
	const LABEL_TAGS = array( 'meals', 'mindset', 'tracking', 'activity' );

	/** Approved food-rule lines (Food-Rule-Tags R-103 to R-176, FT-Q1 and FT-Q3 = A). */
	const FOOD_LINES = array(
		'halal'        => 'Use halal meat and chicken in these recipes.',
		'kosher'       => 'Use kosher meat and chicken in these recipes.',
		'halal_extras' => 'Some vanilla extracts and soy sauces contain alcohol. Choose alcohol-free or halal-certified versions if you prefer.',
		// The Jain choice (Food-Rule-Tags-Jain.md, approved 10 Nov 2026, J1 to J5 = A).
		'jain'         => 'Practice varies, so check each recipe against your own rules. Leave out any optional honey or root vegetables. Meal times, seasonal rules and how long food is kept are up to you.',
	);

	/** Food rules that exclude alcohol: no alcohol question, "never" recorded (FR3-98 AS-08), no alcohol step. */
	const NO_ALCOHOL_RULES = array( 'halal', 'jain' );

	/** Food rule → food-rule tags left out (R-HW-FOOD-01 mapping, approved 10 Nov 2026). */
	const FOOD_RULE_TAGS = array(
		'halal'           => array( 'contains-pork', 'contains-alcohol' ),
		'kosher'          => array( 'contains-pork', 'contains-shellfish', 'contains-non-kosher-fish', 'contains-meat-and-dairy' ),
		'no_pork'         => array( 'contains-pork' ),
		'no_beef'         => array( 'contains-beef' ),
		'pescatarian'     => array( 'contains-poultry', 'contains-beef', 'contains-pork' ),
		'no_eggs'         => array( 'contains-egg' ),
		'dairy_free'      => array( 'contains-dairy' ),
		'no_onion_garlic' => array( 'contains-onion-or-garlic' ),
		// Jain: vegetarian (the diet type, checked in food_ok), and these tags (Food-Rule-Tags-Jain.md).
		'jain'            => array( 'contains-egg', 'contains-root-vegetables', 'contains-mushrooms', 'contains-honey', 'contains-alcohol' ),
	);

	/** Allergens that a food-rule tag also proves (so an allergy is filtered even where the allergen list is short). */
	const ALLERGEN_TAGS = array(
		'shellfish' => 'contains-shellfish',
		'egg'       => 'contains-egg',
		'eggs'      => 'contains-egg',
		'milk'      => 'contains-dairy',
		'dairy'     => 'contains-dairy',
		'fish'      => 'contains-fish',
	);

	// ---- Safety (R-HW-SAFE) -----------------------------------------------------------------------------------------

	/**
	 * R-HW-SAFE-01: who can start Healthy Weight.
	 *
	 * @param array $in pregnant (yes|no|notsure|breastfeeding), eating_disorder (yes|no|pnts), age.
	 * @return array {rule, offered: bool, stop: null|pregnancy|eating|under18, calorie_target_stored: bool,
	 *               paces: allowed paces (pnts: 0.25 and 0.5), screen: SCR-26 when not offered}
	 */
	public static function eligibility( array $in ) {
		$preg = isset( $in['pregnant'] ) ? (string) $in['pregnant'] : 'no';
		$ed   = isset( $in['eating_disorder'] ) ? (string) $in['eating_disorder'] : 'no';
		$age  = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (int) $in['age'] : null;
		$stop = null;
		if ( in_array( $preg, array( 'yes', 'breastfeeding', 'notsure' ), true ) ) {
			$stop = 'pregnancy';
		} elseif ( 'yes' === $ed ) {
			$stop = 'eating';
		} elseif ( null !== $age && $age < 18 ) {
			$stop = 'under18'; // no weight goal and no BMI bands (FR3-97)
		}
		return array(
			'rule'                  => 'R-HW-SAFE-01',
			'offered'               => null === $stop,
			'stop'                  => $stop,
			'screen'                => null === $stop ? null : 'SCR-26',
			'calorie_target_stored' => null === $stop,
			'paces'                 => 'pnts' === $ed ? array( 0.25, 0.5 ) : array( 0.25, 0.5, 0.75, 1.0 ),
		);
	}

	/**
	 * R-HW-SAFE-03, -04 and -05: the free support cards and lines on the plan screen.
	 *
	 * @param array $in stress, sleep, alcohol_freq, alcohol_amount, food_rules, conditions (list), medicines (bool),
	 *                  occasion (joining|check_in), check_in_number (1, 2, 3 …), unchanged (the answers are the same as at
	 *                  joining; for check-ins).
	 * @return array {cards: list of {rule, template, text}, doctor_line: bool, diabetes_exercise_note: bool,
	 *               moderate_only: bool, no_weight_message: bool (nothing about weight or calories beside the drinking
	 *               card)}
	 */
	public static function support_cards( array $in ) {
		$occasion = isset( $in['occasion'] ) ? $in['occasion'] : 'joining';
		$n        = isset( $in['check_in_number'] ) ? (int) $in['check_in_number'] : 0;
		$repeat   = 'joining' === $occasion || ( 'check_in' === $occasion && $n >= 1 && $n <= 2 && ! empty( $in['unchanged'] ) );
		$stress   = self::num( $in, 'stress' );
		$sleep    = isset( $in['sleep'] ) ? $in['sleep'] : null;
		$cards    = array();
		if ( $repeat && ( ( null !== $stress && $stress >= 9 ) || 'under_6' === $sleep ) ) {
			$cards[] = self::card( 'R-HW-SAFE-03', 'hw.T-HW-SAFE-03' );
		}
		$drinks = self::drinks( $in );
		if ( $repeat && $drinks['support_card'] ) {
			$cards[] = self::card( 'R-HW-SAFE-05', 'hw.T-HW-SAFE-05' );
		}
		$conditions = self::lower_list( isset( $in['conditions'] ) ? $in['conditions'] : array() );
		return array(
			'cards'                  => $cards,
			'doctor_line'            => (bool) $conditions || ! empty( $in['medicines'] ),
			'diabetes_exercise_note' => in_array( 'type2_diabetes', $conditions, true ),
			'moderate_only'          => (bool) array_intersect( array( 'high_blood_pressure', 'heart_disease' ), $conditions ),
			'no_weight_message'      => $repeat && $drinks['support_card'],
			'rule_doctor_line'       => 'R-HW-SAFE-04',
		);
	}

	// ---- Numbers (R-HW-NUM) -----------------------------------------------------------------------------------------

	/**
	 * R-HW-NUM-03: the daily calorie target (signed P3-55, through LLD_Members_Calorie_Target).
	 *
	 * @param array $in sex (F|M|PNTS), age, height_cm, weight_kg, activity_level, pace (kg a week), eating_disorder,
	 *                  pregnant (yes|no|notsure|breastfeeding; default no).
	 * @return array {rule, target: kcal|null, tdee: unrounded, tdee_shown: nearest 10, deficit: 1100 × pace, rate,
	 *               floor, notes, why: {tdee, deficit, rate} for the signed "Why this number" text}
	 */
	public static function target( array $in ) {
		$r = LLD_Members_Calorie_Target::compute(
			array(
				'sex'            => isset( $in['sex'] ) ? $in['sex'] : '',
				'age'            => isset( $in['age'] ) ? $in['age'] : null,
				'heightCm'       => isset( $in['height_cm'] ) ? $in['height_cm'] : null,
				'weightKg'       => isset( $in['weight_kg'] ) ? $in['weight_kg'] : null,
				'activity'       => isset( $in['activity_level'] ) ? str_replace( '_', ' ', (string) $in['activity_level'] ) : '',
				'goal'           => 'lose',
				'rateKgWeek'     => isset( $in['pace'] ) ? $in['pace'] : 0.5,
				// The member's pregnancy answer (breastfeeding counts as pregnancy for P3-55): the signed calculator stops.
				'pregnant'       => isset( $in['pregnant'] ) && in_array( (string) $in['pregnant'], array( 'yes', 'breastfeeding' ), true ) ? 'yes' : ( isset( $in['pregnant'] ) && 'notsure' === (string) $in['pregnant'] ? 'notsure' : 'no' ),
				'eatingDisorder' => isset( $in['eating_disorder'] ) ? $in['eating_disorder'] : 'no',
			)
		);
		if ( ! empty( $r['error'] ) || ! empty( $r['stop'] ) || ! isset( $r['targetKcal'] ) ) {
			return array(
				'rule'   => 'R-HW-NUM-03',
				'target' => null,
				'stop'   => isset( $r['stop'] ) ? $r['stop'] : null,
				'error'  => isset( $r['error'] ) ? $r['error'] : null,
			);
		}
		$rate = null !== $r['achievableRate'] ? (float) $r['achievableRate'] : null;
		return array(
			'rule'       => 'R-HW-NUM-03',
			'target'     => $r['targetKcal'],
			'tdee'       => $r['tdee'],
			'tdee_shown' => (int) ( floor( $r['tdee'] / 10 + 0.5 ) * 10 ),
			'deficit'    => null === $rate ? null : (int) round( 1100 * $rate ),
			'rate'       => $rate,
			'floor'      => $r['floor'],
			'notes'      => $r['notes'],
		);
	}

	/**
	 * R-HW-NUM-04: milestones and their dates (method sheet section 4).
	 *
	 * @param array $in start_kg, goal_kg, height_cm, adjusted (lower cut-offs), pace, today (Y-m-d).
	 * @return array {rule, milestones: list of {kind: 5pct|10pct|healthy|goal, kg, weeks, date, label, text}} in order.
	 */
	public static function milestones( array $in ) {
		$w     = (float) $in['start_kg'];
		$g     = (float) $in['goal_kg'];
		$pace  = (float) $in['pace'];
		$range = LLD_Members_HW_Where::range( (float) $in['height_cm'], ! empty( $in['adjusted'] ) );
		$cands = array(
			'5pct'    => self::round1( 0.95 * $w ),
			'10pct'   => self::round1( 0.90 * $w ),
			'healthy' => (float) $range['highKg'],
		);
		$out = array();
		foreach ( $cands as $kind => $kg ) {
			if ( $kg >= $w - 1e-9 || $kg <= $g + 1e-9 ) {
				continue; // not below the start, or at or past the goal: left out
			}
			$out[] = self::milestone( $kind, $kg, $w, $pace, $in['today'] );
		}
		$out[] = self::milestone( 'goal', $g, $w, $pace, $in['today'] );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['kg'] <=> $a['kg'];
			}
		);
		return array(
			'rule'       => 'R-HW-NUM-04',
			'milestones' => $out,
		);
	}

	/**
	 * R-HW-MOT-02: a milestone reached (the 7-day mean at or below it, D3-09).
	 *
	 * @param array $in milestones (from milestones()), mean7 (7-day mean weight, null without one), celebrated (kinds
	 *                  already celebrated), eating_disorder.
	 * @return array {rule, kind|null, template, text, reask: the questions asked again, passed: kinds newly passed}
	 */
	public static function milestone_reached( array $in ) {
		$none = array(
			'rule'     => 'R-HW-MOT-02',
			'kind'     => null,
			'template' => null,
			'text'     => null,
			'reask'    => array(),
			'passed'   => array(),
		);
		if ( isset( $in['eating_disorder'] ) && 'yes' === $in['eating_disorder'] ) { // Owner Question 164: not for pnts
			return $none;
		}
		if ( ! isset( $in['mean7'] ) || ! is_numeric( $in['mean7'] ) ) {
			return $none;
		}
		$done   = isset( $in['celebrated'] ) ? (array) $in['celebrated'] : array();
		$passed = array();
		foreach ( $in['milestones'] as $m ) {
			if ( ! in_array( $m['kind'], $done, true ) && (float) $in['mean7'] <= (float) $m['kg'] + 1e-9 ) {
				$passed[] = $m;
			}
		}
		if ( ! $passed ) {
			return $none;
		}
		$m        = end( $passed ); // the furthest one reached; the others are passed with it
		$template = 'hw.T-HW-MOT-02.' . $m['kind'];
		return array(
			'rule'     => 'R-HW-MOT-02',
			'kind'     => $m['kind'],
			'template' => $template,
			'text'     => LLD_Members_Rule_Catalogue::fill( $template, array( 'kg' => self::kg( $m['kg'] ) ) ),
			'reask'    => 'goal' === $m['kind'] ? array() : array( 'time', 'work', 'stress' ),
			'passed'   => array_map(
				function ( $x ) {
					return $x['kind'];
				},
				$passed
			),
		);
	}

	/**
	 * R-HW-NUM-05, R-HW-SAFE-05 and R-HW-ALC-01's condition: drinks in the calorie picture (method sheet section 5).
	 *
	 * @param array $in alcohol_freq, alcohol_amount, eating_disorder, food_rules, target (the calorie target, for the line).
	 * @return array {weekly_drinks, weekly_kcal, daily_kcal, line: {rule, template, text}|null, support_card: bool,
	 *               alcohol_step: none|free_day|one_fewer, drinks: bool, rules}
	 */
	public static function drinks( array $in ) {
		$freq   = self::alcohol_freq( $in );
		$amount = isset( $in['alcohol_amount'] ) ? $in['alcohol_amount'] : null;
		$ed     = isset( $in['eating_disorder'] ) ? (string) $in['eating_disorder'] : 'no';
		$out    = array(
			'drinks'        => null !== $freq && 'never' !== $freq,
			'weekly_drinks' => null,
			'weekly_kcal'   => null,
			'daily_kcal'    => null,
			'line'          => null,
			'support_card'  => false,
			'alcohol_step'  => 'none',
			'rules'         => array(
				'line'  => 'R-HW-NUM-05',
				'card'  => 'R-HW-SAFE-05',
				'step'  => 'R-HW-ALC-01',
			),
		);
		if ( ! $out['drinks'] || ! isset( self::DRINK_DAYS[ $freq ] ) ) {
			return $out;
		}
		$five = '5_plus' === $amount;
		if ( isset( self::DRINK_AMOUNT[ $amount ] ) ) {
			$weekly                = self::DRINK_DAYS[ $freq ] * self::DRINK_AMOUNT[ $amount ];
			$kcal                  = $weekly * self::KCAL_PER_DRINK;
			$out['weekly_drinks']  = $weekly;
			$out['weekly_kcal']    = self::r10( $kcal );
			$out['daily_kcal']     = self::r10( $kcal / 7 );
			$out['support_card']   = $weekly > self::DRINK_LIMIT + 1e-9 || $five;
			if ( 'yes' !== $ed ) { // Owner Question 164: pnts sees the line
				$values = array(
					'target' => isset( $in['target'] ) && null !== $in['target'] ? number_format( (float) $in['target'], 0, '.', ',' ) : '',
					'weekly' => number_format( $out['weekly_kcal'], 0, '.', ',' ),
					'daily'  => number_format( $out['daily_kcal'], 0, '.', ',' ),
				);
				$out['line'] = array(
					'rule'     => 'R-HW-NUM-05',
					'template' => 'hw.T-HW-NUM-05',
					'values'   => $values,
					'text'     => LLD_Members_Rule_Catalogue::fill( 'hw.T-HW-NUM-05', $values ),
				);
			}
		}
		if ( in_array( $freq, array( '3_4_days', 'most_days' ), true ) || $five ) {
			$out['alcohol_step'] = ( 'most_days' === $freq && $five ) ? 'one_fewer' : 'free_day';
		}
		return $out;
	}

	/**
	 * R-HW-ALC-02: the evening "Add a drink" shortcut on Today (never a reminder). From 18:00 local time; for shift and
	 * night workers who gave a wake-up time, from 10 hours after they wake (D3-60, catalogue v1.3.1: no sleep time is
	 * asked), until their next wake-up; without a wake-up time, from 18:00. Hidden once a drink is logged that day.
	 *
	 * @param array $in alcohol_freq, food_rules, eating_disorder, counts_calories (default true), time (HH:MM now),
	 *                  work, wake_time (HH:MM, shift and night workers), drink_logged_today.
	 * @return array {rule, show: bool, label, from: HH:MM|null, reason}
	 */
	public static function drink_shortcut( array $in ) {
		$freq = self::alcohol_freq( $in );
		$out  = array(
			'rule'   => 'R-HW-ALC-02',
			'show'   => false,
			'label'  => LLD_Members_Rule_Catalogue::text( 'hw.R-HW-ALC-02.button' ),
			'from'   => null,
			'reason' => '',
		);
		if ( null === $freq || 'never' === $freq ) {
			$out['reason'] = 'does_not_drink';
			return $out;
		}
		if ( isset( $in['eating_disorder'] ) && 'yes' === $in['eating_disorder'] ) { // Owner Question 164: not for pnts
			$out['reason'] = 'eating_disorder';
			return $out;
		}
		if ( isset( $in['counts_calories'] ) && ! $in['counts_calories'] ) {
			$out['reason'] = 'not_counting';
			return $out;
		}
		$shift = isset( $in['work'] ) && in_array( $in['work'], array( 'shifts', 'nights', 'irregular' ), true );
		$wake  = $shift && ! empty( $in['wake_time'] ) ? self::minutes( $in['wake_time'] ) : null;
		$from  = null === $wake ? 18 * 60 : ( $wake + 10 * 60 ) % 1440;
		$out['from'] = self::hhmm( $from );
		if ( ! empty( $in['drink_logged_today'] ) ) {
			$out['reason'] = 'logged_today';
			return $out;
		}
		$now = isset( $in['time'] ) ? self::minutes( $in['time'] ) : null;
		if ( null === $now ) {
			$out['reason'] = 'no_time';
			return $out;
		}
		if ( null === $wake ) {
			$out['show'] = $now >= $from; // 18:00 to the end of the day
		} else {
			$out['show'] = ( $now - $wake + 1440 ) % 1440 >= 10 * 60; // 10 hours after waking, until the next wake-up
		}
		$out['reason'] = $out['show'] ? '' : 'before_evening';
		return $out;
	}

	// ---- Pace (R-HW-PACE-01, with R-HW-SAFE-01 and -02) -------------------------------------------------------------

	/**
	 * R-HW-PACE-01: the recommended pace, the paces the member may choose, and the weeks and date (P3-56, weeks up).
	 *
	 * @param array $in confidence, time, work, stress, sleep, age, weight_kg, eating_disorder; optional chosen (kg a
	 *                  week), start_kg, goal_kg, today (Y-m-d) for the weeks and date.
	 * @return array {rule, recommended, step: gentle|steady|faster, reasons: list of {key, values}, heading, because,
	 *               cap, paces (allowed), chosen, refused: bool, refusal (P3-56 message key)|null, safe_pace, weeks, date}
	 */
	public static function pace( array $in ) {
		$conf   = self::num( $in, 'confidence' );
		$stress = self::num( $in, 'stress' );
		$age    = self::num( $in, 'age' );
		$time   = isset( $in['time'] ) ? $in['time'] : null;
		$work   = isset( $in['work'] ) ? $in['work'] : null;
		$sleep  = isset( $in['sleep'] ) ? $in['sleep'] : null;
		$weight = self::num( $in, 'weight_kg' );
		$paces  = self::allowed_paces( $in );
		$cap    = null === $weight ? null : LLD_Members_Goal_Timeline::rate_cap( $weight, null !== $age && $age >= 65 );

		$gentle = array();
		if ( null !== $conf && $conf <= 4 ) {
			$gentle[] = array(
				'key'    => 'hw.T-HW-PACE-01.reason.confidence',
				'values' => array( 'n' => (int) $conf ),
			);
		}
		if ( 'under_10' === $time ) {
			$gentle[] = array(
				'key'    => 'hw.T-HW-PACE-01.reason.time',
				'values' => array(),
			);
		}
		if ( in_array( $work, array( 'shifts', 'nights', 'irregular' ), true ) ) {
			$gentle[] = array(
				'key'    => 'hw.T-HW-PACE-01.reason.' . $work,
				'values' => array(),
			);
		}
		if ( null !== $stress && $stress >= 8 ) {
			$gentle[] = array(
				'key'    => 'hw.T-HW-PACE-01.reason.stress',
				'values' => array(),
			);
		}
		if ( $gentle ) {
			$step    = 'gentle';
			$rec     = 0.25;
			$reasons = array_slice( $gentle, 0, 2 );
		} elseif ( null !== $conf && $conf >= 8 && 'over_30' === $time && null !== $stress && $stress <= 6
			&& in_array( $sleep, array( '7_9', 'over_9' ), true ) && null !== $age && $age < 65 && in_array( 0.75, $paces, true ) ) {
			$step    = 'faster';
			$rec     = 0.75;
			$reasons = array(
				array(
					'key'    => 'hw.T-HW-PACE-01.reason.faster',
					'values' => array(),
				),
			);
		} else {
			$step    = 'steady';
			$rec     = 0.5;
			$reasons = array(
				array(
					'key'    => null !== $age && $age >= 65 ? 'hw.T-HW-PACE-01.reason.older' : 'hw.T-HW-PACE-01.reason.steady',
					'values' => array(),
				),
			);
		}
		$phrases = array();
		foreach ( $reasons as $r ) {
			$phrases[] = LLD_Members_Rule_Catalogue::fill( $r['key'], $r['values'] );
		}
		$out = array(
			'rule'        => 'R-HW-PACE-01',
			'recommended' => $rec,
			'step'        => $step,
			'reasons'     => $reasons,
			'heading'     => LLD_Members_Rule_Catalogue::text( 'hw.T-HW-PACE-01.heading.' . $step ),
			'because'     => LLD_Members_Rule_Catalogue::fill( 'hw.T-HW-PACE-01', array( 'reasons' => implode( ' and ', $phrases ) ) ),
			'cap'         => $cap,
			'paces'       => $paces,
			'chosen'      => $rec,
			'refused'     => false,
			'refusal'     => null,
			'safe_pace'   => null,
			'weeks'       => null,
			'date'        => null,
		);
		if ( isset( $in['chosen'] ) && is_numeric( $in['chosen'] ) ) {
			$want = (float) $in['chosen'];
			$fits = false;
			foreach ( $paces as $p ) {
				$fits = $fits || abs( $p - $want ) < 1e-9;
			}
			if ( $fits ) {
				$out['chosen'] = $want;
			} else {
				// R-HW-SAFE-02: refused with the signed P3-56 message and the safe suggestion.
				$safe = 0.25;
				foreach ( $paces as $p ) {
					if ( $p <= $want + 1e-9 ) {
						$safe = $p;
					}
				}
				$out['refused']   = true;
				$out['refusal']   = 'goal-timeline:refusals.rate-cap';
				$out['safe_pace'] = $safe;
				$out['chosen']    = $safe;
			}
		}
		if ( isset( $in['start_kg'], $in['goal_kg'], $in['today'] ) ) {
			$out['weeks'] = LLD_Members_Goal_Timeline::weeks_for( (float) $in['start_kg'] - (float) $in['goal_kg'], $out['chosen'] );
			$out['date']  = self::add_days( $in['today'], 7 * $out['weeks'] );
		}
		return $out;
	}

	/**
	 * The paces a member may choose (R-HW-SAFE-01, -02; P3-56): ≤ min(1.0, 1 % of weight); 0.25 or 0.5 from 65 and for
	 * eating disorder "prefer not to say"; never below 0.25.
	 *
	 * @param array $in weight_kg, age, eating_disorder.
	 * @return float[]
	 */
	public static function allowed_paces( array $in ) {
		$weight = self::num( $in, 'weight_kg' );
		$age    = self::num( $in, 'age' );
		$cap    = null === $weight ? 1.0 : LLD_Members_Goal_Timeline::rate_cap( $weight, false );
		$limit  = ( ( null !== $age && $age >= 65 ) || ( isset( $in['eating_disorder'] ) && 'pnts' === $in['eating_disorder'] ) ) ? 0.5 : 1.0;
		$out    = array();
		foreach ( array( 0.25, 0.5, 0.75, 1.0 ) as $p ) {
			if ( $p <= $cap + 1e-9 && $p <= $limit + 1e-9 ) {
				$out[] = $p;
			}
		}
		return $out ? $out : array( 0.25 );
	}

	// ---- Daily steps, weeks 1 to 8 (R-HW-STEP, R-HW-ALC-01, R-HW-WEEK-01) -------------------------------------------

	/**
	 * The plan of one week of levels 1 and 2: which approved activity fills each day, in which version, with the rule
	 * that decided and its reason line; plus the week's exercise level.
	 *
	 * @param array $in week (1 to 8), answers (see the class comment: barrier, time, confidence, sleep, stress,
	 *                  alcohol_freq, alcohol_amount, sugary_drinks, breakfast (most_days|…), breakfast_protein (bool),
	 *                  eats_out, activity_level, enjoyment, food_rules, conditions), activities (the 56 approved track
	 *                  activities: {id, week 1–8, day 1–7, tags[], high_intensity?, alt_id?, variants?{enjoyment: id}}),
	 *                  library (approved action-library items {id, tags[]}), steps (optional ids: sleep[week],
	 *                  stress[week], alcohol {free_day, second_day, one_fewer}), history ({version: last week's
	 *                  version, done_pct: last week's percentage done, alcohol_done: last week's alcohol step done,
	 *                  check_in: last check-in's "what got in the way" choices}), never_drinks (optional bool: the
	 *                  member does not drink, said outright when the answers carry no alcohol answer, as for Free).
	 * @return array {rule list, week, version, days: list of 7 {day, id, version, rule, reason: {template, values,
	 *               text}|null, minutes|null}, left_out: ids, notice: 'hw.T-HW-STEP-02'|null, exercise: {…},
	 *               check_in: check_in() effects applied}
	 */
	public static function week_plan( array $in ) {
		$week    = isset( $in['week'] ) ? max( 1, min( 8, (int) $in['week'] ) ) : 1;
		$a       = self::answers( $in );
		$history = isset( $in['history'] ) && is_array( $in['history'] ) ? $in['history'] : array();
		$effects = self::check_in( array( 'choices' => isset( $history['check_in'] ) ? $history['check_in'] : array() ) );
		$layout  = self::layout( $in, $week, $effects );
		$slots   = $layout['weeks'][ $week ];

		// R-HW-STEP-03: the week's version; R-HW-WEEK-01 (time, work, illness) overrides it for that week.
		$version = self::version( $week, $a, $history );
		$vrule   = 'R-HW-STEP-03';
		if ( $effects['easier'] ) {
			$version = 'easier';
			$vrule   = 'R-HW-WEEK-01';
		}
		$exercise   = self::exercise( $week, $a, $in );
		$conditions = $a['conditions'];
		$moderate   = (bool) array_intersect( array( 'high_blood_pressure', 'heart_disease' ), $conditions );
		$by_id      = self::index( isset( $in['activities'] ) ? $in['activities'] : array() );
		$days       = array();
		foreach ( $slots as $i => $slot ) {
			$id   = $slot['id'];
			$item = isset( $by_id[ $id ] ) ? $by_id[ $id ] : array( 'tags' => isset( $slot['tags'] ) ? $slot['tags'] : array() );
			$v    = $version;
			$day  = array(
				'day'     => $i + 1,
				'id'      => $id,
				'version' => $v,
				'rule'    => $slot['rule'],
				'reason'  => $slot['reason'],
				'minutes' => null,
			);
			// Safety first: high blood pressure or heart disease never get a high-intensity item, in any version (H4).
			if ( $moderate && ! empty( $item['high_intensity'] ) && ! empty( $item['alt_id'] ) ) {
				$day['id']   = $item['alt_id'];
				$day['rule'] = 'R-HW-SAFE-04';
			}
			// The member's enjoyment answer: that type where an approved version exists (R-HW-STEP-06).
			if ( ! empty( $a['enjoyment'] ) && ! empty( $item['variants'][ $a['enjoyment'] ] ) && 'R-HW-SAFE-04' !== $day['rule'] ) {
				$day['id'] = $item['variants'][ $a['enjoyment'] ];
			}
			if ( in_array( 'walking', isset( $item['tags'] ) ? $item['tags'] : array(), true ) && null !== $exercise['walk_minutes'] ) {
				$day['minutes'] = $exercise['walk_minutes'];
				if ( $exercise['capped'] && 'stretch' === $v ) {
					$day['version'] = 'standard'; // the 10-minute cap of weeks 1 to 4 (R-HW-STEP-06)
				}
				if ( null === $day['reason'] ) {
					$day['reason'] = self::reason( 'hw.T-HW-STEP-06', array( 'minutes' => $exercise['walk_minutes'] ) );
				}
			}
			$days[] = $day;
		}
		return array(
			'rules'        => array( 'R-HW-STEP-01', 'R-HW-STEP-02', 'R-HW-STEP-03', 'R-HW-STEP-04', 'R-HW-STEP-05', 'R-HW-STEP-06', 'R-HW-ALC-01', 'R-HW-WEEK-01' ),
			'week'         => $week,
			'version'      => $version,
			'version_rule' => $vrule,
			'days'         => $days,
			'left_out'     => $layout['left_out'],
			'notice'       => $layout['left_out'] ? 'hw.T-HW-STEP-02' : null,
			'exercise'     => $exercise,
			'check_in'     => $effects,
		);
	}

	/**
	 * The order of all eight weeks from the answers (R-HW-STEP-01, -02, -04, -05, R-HW-ALC-01 and, for $week, the
	 * check-in effects of R-HW-WEEK-01). Versions are not decided here.
	 *
	 * @param array      $in      As week_plan().
	 * @param int        $week    The week being planned (gets the check-in effects and last week's alcohol history).
	 * @param array|null $effects check_in() result for $week.
	 * @return array {weeks: [1..8 => list of 7 {id, rule, reason}], left_out: ids}
	 */
	public static function layout( array $in, $week = 1, $effects = null ) {
		$a        = self::answers( $in );
		$history  = isset( $in['history'] ) && is_array( $in['history'] ) ? $in['history'] : array();
		$acts     = isset( $in['activities'] ) && is_array( $in['activities'] ) ? $in['activities'] : array();
		$library  = isset( $in['library'] ) && is_array( $in['library'] ) ? $in['library'] : array();
		$steps    = isset( $in['steps'] ) && is_array( $in['steps'] ) ? $in['steps'] : array();
		$drinks   = self::drinks( $a );
		$heavy    = 'one_fewer' === $drinks['alcohol_step'];
		// The caller may say outright whether the member drinks (a Free plan has no alcohol answers, only the food-rules
		// filter: never_drinks true for "never" or food rules that exclude alcohol).
		$no_drink = array_key_exists( 'never_drinks', $in ) ? (bool) $in['never_drinks'] : ! $drinks['drinks'];
		$used     = array();
		foreach ( $acts as $x ) {
			$used[ $x['id'] ] = true;
		}

		// Base order and R-HW-STEP-02 (habits already in place).
		$weeks    = array();
		$left_out = array();
		$moved    = array();
		usort(
			$acts,
			function ( $x, $y ) {
				return array( (int) $x['week'], (int) $x['day'] ) <=> array( (int) $y['week'], (int) $y['day'] );
			}
		);
		foreach ( $acts as $x ) {
			$w    = (int) $x['week'];
			$tags = isset( $x['tags'] ) ? (array) $x['tags'] : array();
			$slot = array(
				'id'     => $x['id'],
				'tags'   => $tags,
				'rule'   => null,
				'reason' => null,
			);
			$out  = false;
			if ( in_array( 'drink-swap', $tags, true ) && in_array( $a['sugary_drinks'], array( 'never', 'less_than_weekly' ), true ) ) {
				$out = true;
			}
			if ( in_array( 'breakfast-protein', $tags, true ) && 'most_days' === $a['breakfast'] && ! empty( $a['breakfast_protein'] ) ) {
				$out = true;
			}
			if ( in_array( 'alcohol', $tags, true ) && $no_drink ) {
				$out = true;
			}
			if ( $out ) {
				$left_out[] = $x['id'];
				$slot       = array(
					'id'      => null,
					'vacated' => $x['id'],
					'tags'    => $tags,
					'rule'    => 'R-HW-STEP-02',
					'reason'  => null,
				);
			} elseif ( in_array( 'alcohol', $tags, true ) && $heavy ) {
				// Heavy daily drinkers never get an alcohol-free-day item: the week's "one drink fewer" step instead (H5 = A).
				$slot = array(
					'id'     => self::step_id( $steps, 'alcohol', 'one_fewer', $w ),
					'tags'   => array( 'alcohol' ),
					'rule'   => 'R-HW-ALC-01',
					'reason' => self::reason( 'hw.T-HW-ALC-01' ),
				);
			} elseif ( in_array( 'eating-out', $tags, true ) && 'less_than_weekly' === $a['eats_out'] && $w < 8 ) {
				// Eats out rarely: the eating-out plan moves to the last week, it is not left out.
				$moved[] = $slot + array( 'moved_from' => $w );
				$slot    = array(
					'id'      => null,
					'vacated' => $x['id'],
					'tags'    => $tags,
					'rule'    => 'R-HW-STEP-02',
					'reason'  => null,
				);
			}
			$weeks[ $w ][] = $slot;
		}
		for ( $w = 1; $w <= 8; $w++ ) {
			if ( ! isset( $weeks[ $w ] ) ) {
				$weeks[ $w ] = array();
			}
		}

		// R-HW-STEP-01: biggest barrier first.
		$tag = isset( self::BARRIER_TAGS[ $a['barrier'] ] ) ? self::BARRIER_TAGS[ $a['barrier'] ] : null;
		if ( $tag ) {
			$reason = self::reason( 'hw.T-HW-STEP-01', array( 'barrier' => LLD_Members_Rule_Catalogue::text( 'hw.T-HW-STEP-01.barrier.' . $a['barrier'] ) ) );
			$taken  = null;
			// Week 1: the first barrier-tagged activity in weeks 1 to 2 goes to day 3 (after the "why" and "measure" days).
			foreach ( array( 1, 2 ) as $w ) {
				foreach ( $weeks[ $w ] as $i => $s ) {
					if ( $s['id'] && null === $s['rule'] && in_array( $tag, $s['tags'], true ) ) {
						$taken = array( $w, $i );
						break 2;
					}
				}
			}
			if ( $taken ) {
				list( $w, $i ) = $taken;
				$s             = $weeks[ $w ][ $i ];
				$s['rule']     = 'R-HW-STEP-01';
				$s['reason']   = $reason;
				if ( 1 === $w ) {
					array_splice( $weeks[1], $i, 1 );
					array_splice( $weeks[1], min( 2, count( $weeks[1] ) ), 0, array( $s ) );
				} else {
					// From week 2: it trades places with week 1's day 3, so each week keeps seven steps.
					$weeks[2][ $i ] = $weeks[1][2];
					$weeks[1][2]    = $s;
				}
			}
			// Other weeks: the week's first activity tagged for the barrier moves to day 1.
			for ( $w = 2; $w <= 8; $w++ ) {
				foreach ( $weeks[ $w ] as $i => $s ) {
					if ( $s['id'] && null === $s['rule'] && in_array( $tag, $s['tags'], true ) ) {
						$s['rule']   = 'R-HW-STEP-01';
						$s['reason'] = $reason;
						array_splice( $weeks[ $w ], $i, 1 );
						array_unshift( $weeks[ $w ], $s );
						break;
					}
				}
			}
		}

		// R-HW-STEP-04 and -05: one sleep or stress step a week; both → sleep in odd weeks, stress in even weeks.
		$sleep_step  = in_array( $a['sleep'], array( 'under_6', '6_7' ), true );
		$stress_step = null !== $a['stress'] && $a['stress'] >= 7;
		$ex          = isset( $effects ) ? $effects : self::check_in( array() );
		for ( $w = 1; $w <= 8; $w++ ) {
			$kind = null;
			if ( $sleep_step && $stress_step ) {
				$kind = 1 === $w % 2 ? 'sleep' : 'stress';
			} elseif ( $sleep_step ) {
				$kind = 'sleep';
			} elseif ( $stress_step ) {
				$kind = 'stress';
			}
			if ( $kind ) {
				self::insert(
					$weeks[ $w ],
					array(
						'id'     => self::step_id( $steps, $kind, null, $w ),
						'tags'   => array( $kind ),
						'rule'   => 'sleep' === $kind ? 'R-HW-STEP-04' : 'R-HW-STEP-05',
						'reason' => self::reason( 'sleep' === $kind ? 'hw.T-HW-STEP-04' : 'hw.T-HW-STEP-05' ),
					)
				);
			}
			// R-HW-ALC-01: one alcohol step a week, on day 5.
			if ( 'none' !== $drinks['alcohol_step'] && isset( $weeks[ $w ][4] ) ) {
				if ( $heavy ) {
					$which = 'one_fewer';
				} elseif ( $w <= 2 ) {
					$which = 'free_day';
				} else {
					$done  = $w === (int) $week ? ! empty( $history['alcohol_done'] ) : false;
					$which = $done ? 'second_day' : 'free_day';
				}
				$weeks[ $w ][4] = array(
					'id'     => self::step_id( $steps, 'alcohol', $which, $w ),
					'tags'   => array( 'alcohol' ),
					'rule'   => 'R-HW-ALC-01',
					'reason' => self::reason( 'hw.T-HW-ALC-01' ),
					'title'  => 'hw.T-HW-ALC-01.title.' . $which,
				);
			}
			// R-HW-WEEK-01: last check-in's steps, in the week being planned.
			if ( $w === (int) $week ) {
				foreach ( $ex['steps'] as $kind ) {
					$extra = null;
					if ( 'stress' === $kind ) {
						$extra = array(
							'id'   => self::step_id( $steps, 'stress', null, $w ),
							'tags' => array( 'stress' ),
						);
					} elseif ( 'planned_snack' === $kind ) {
						$extra = self::pick( $library, $used, array( 'snacking' ), $a );
					} elseif ( 'eating_out_plan' === $kind ) {
						foreach ( $acts as $x ) {
							if ( in_array( 'eating-out', isset( $x['tags'] ) ? (array) $x['tags'] : array(), true ) ) {
								$extra = array(
									'id'   => $x['id'],
									'tags' => (array) $x['tags'],
								);
								break;
							}
						}
					}
					if ( $extra && ! self::has_id( $weeks[ $w ], $extra['id'] ) ) {
						$extra['rule']   = 'R-HW-WEEK-01';
						$extra['reason'] = null;
						self::insert( $weeks[ $w ], $extra );
					}
				}
			}
		}
		// The eating-out plan of a member who rarely eats out, placed in the last week.
		foreach ( $moved as $m ) {
			if ( ! self::has_id( $weeks[8], $m['id'] ) ) {
				$m['rule'] = 'R-HW-STEP-02';
				unset( $m['moved_from'] );
				self::insert( $weeks[8], $m );
			}
		}
		// Slots still empty: the next unused action-library step of the week's theme (FR2-10).
		for ( $w = 1; $w <= 8; $w++ ) {
			$theme = self::theme( $weeks[ $w ], $in, $w );
			foreach ( $weeks[ $w ] as $i => $s ) {
				if ( null === $s['id'] ) {
					$pick = self::pick( $library, $used, $theme, $a );
					if ( $pick ) {
						$weeks[ $w ][ $i ] = array(
							'id'      => $pick['id'],
							'tags'    => $pick['tags'],
							'rule'    => 'R-HW-STEP-02',
							'reason'  => null,
							'vacated' => isset( $s['vacated'] ) ? $s['vacated'] : null,
						);
					}
				}
			}
			$weeks[ $w ] = array_slice( $weeks[ $w ], 0, 7 );
		}
		return array(
			'weeks'    => $weeks,
			'left_out' => $left_out,
		);
	}

	/**
	 * R-HW-WEEK-01: what next week's plan does with "What got in the way?" (the free-text box is never read).
	 *
	 * @param array $in choices: list of time, work, family, cravings, stress, social, illness, nothing.
	 * @return array {rule, easier: bool, notes: note ids to bring forward, steps: planned_snack|stress|eating_out_plan,
	 *               no_behind: bool, no_adj_01: bool, replies: the first two reply templates, texts}
	 */
	public static function check_in( array $in ) {
		$choices = isset( $in['choices'] ) ? array_values( array_unique( (array) $in['choices'] ) ) : array();
		$map     = array(
			'time'     => 'a',
			'work'     => 'a',
			'family'   => 'b',
			'cravings' => 'c',
			'stress'   => 'd',
			'social'   => 'e',
			'illness'  => 'f',
			'nothing'  => 'g',
		);
		$out     = array(
			'rule'      => 'R-HW-WEEK-01',
			'easier'    => false,
			'notes'     => array(),
			'steps'     => array(),
			'no_behind' => false,
			'no_adj_01' => false,
			'replies'   => array(),
			'texts'     => array(),
		);
		foreach ( $choices as $c ) {
			if ( ! isset( $map[ $c ] ) ) {
				continue;
			}
			$key = 'hw.T-HW-WEEK-01' . $map[ $c ];
			if ( ! in_array( $key, $out['replies'], true ) ) {
				$out['replies'][] = $key;
			}
			if ( 'time' === $c || 'work' === $c ) {
				$out['easier'] = true;
			} elseif ( 'family' === $c ) {
				$out['notes'][] = self::NOTE_FAMILY;
			} elseif ( 'cravings' === $c ) {
				$out['notes'][] = self::NOTE_CRAVINGS;
				$out['steps'][] = 'planned_snack';
			} elseif ( 'stress' === $c ) {
				$out['notes'][] = self::NOTE_STRESS;
				$out['steps'][] = 'stress';
			} elseif ( 'social' === $c ) {
				$out['steps'][] = 'eating_out_plan';
			} elseif ( 'illness' === $c ) {
				$out['easier']    = true;
				$out['no_behind'] = true;
				$out['no_adj_01'] = true;
			}
		}
		$out['replies'] = array_slice( $out['replies'], 0, 2 ); // with more than one choice, the first two replies
		foreach ( $out['replies'] as $k ) {
			$out['texts'][] = LLD_Members_Rule_Catalogue::text( $k );
		}
		return $out;
	}

	// ---- Mindset notes (R-HW-NOTE) ----------------------------------------------------------------------------------

	/**
	 * The day's mindset note: the default order (FR3-105), or one note brought forward (R-HW-NOTE-01 to -05, then the
	 * check-in notes of R-HW-WEEK-01). A note brought forward replaces the day's note, which moves to the next day; no
	 * note shows twice within 30 days, so the list rejoins itself; at most one bring-forward a day.
	 *
	 * @param array $in date (Y-m-d), programme_day (1…), queue (the default order from the next unshown note on),
	 *                  shown (note id => date last shown), safe04_date (R-SAFE-04 last fired), mood_today (1–5),
	 *                  sleep, stress (joining answers), counts_calories, last_food_date, weigh_in_this_week,
	 *                  weigh_in_last_week, plateau (bool), eating_disorder, pending (check-in notes still to bring
	 *                  forward).
	 * @return array {note, rule (the rule that brought it forward, or null), queue (for tomorrow), pending (for
	 *               tomorrow), support_wording: bool}
	 */
	public static function note_for_day( array $in ) {
		$date    = $in['date'];
		$queue   = isset( $in['queue'] ) ? array_values( (array) $in['queue'] ) : array();
		$shown   = isset( $in['shown'] ) && is_array( $in['shown'] ) ? $in['shown'] : array();
		$pending = isset( $in['pending'] ) ? array_values( (array) $in['pending'] ) : array();
		$ed      = isset( $in['eating_disorder'] ) && 'yes' === $in['eating_disorder']; // Owner Question 164
		$day     = isset( $in['programme_day'] ) ? (int) $in['programme_day'] : 0;
		$recent  = function ( $id ) use ( $shown, $date ) {
			return isset( $shown[ $id ] ) && self::days_between( $shown[ $id ], $date ) < self::NOTE_REPEAT_DAYS;
		};
		$paused  = ! empty( $in['safe04_date'] ) && self::days_between( $in['safe04_date'], $date ) >= 0 && self::days_between( $in['safe04_date'], $date ) < 7;
		$low     = $paused || ( isset( $in['mood_today'] ) && is_numeric( $in['mood_today'] ) && $in['mood_today'] <= 2 );

		$candidates = array();
		if ( $low ) {
			$candidates[] = array( 'R-HW-NOTE-01', self::NOTE_LOW_MOOD );
		}
		if ( ! $paused ) {
			$sleep  = isset( $in['sleep'] ) ? $in['sleep'] : null;
			$stress = self::num( $in, 'stress' );
			if ( ( in_array( $sleep, array( 'under_6', '6_7' ), true ) || ( null !== $stress && $stress >= 7 ) ) && in_array( $day, array( 2, 4 ), true ) ) {
				$candidates[] = array( 'R-HW-NOTE-02', 2 === $day ? self::NOTE_STRESS : self::NOTE_SLEEP );
			}
			if ( ! empty( $in['counts_calories'] ) && ! empty( $in['last_food_date'] ) && self::days_between( $in['last_food_date'], $date ) >= 4 ) {
				$candidates[] = array( 'R-HW-NOTE-03', self::NOTE_MISSED_LOG );
			}
			if ( ! $ed && isset( $in['weigh_in_this_week'], $in['weigh_in_last_week'] ) && is_numeric( $in['weigh_in_this_week'] ) && is_numeric( $in['weigh_in_last_week'] )
				&& round( (float) $in['weigh_in_this_week'] - (float) $in['weigh_in_last_week'], 1 ) >= 0.3 ) {
				$candidates[] = array( 'R-HW-NOTE-04', self::NOTE_WEIGHT_UP );
			}
			if ( ! $ed && ! empty( $in['plateau'] ) ) {
				$candidates[] = array( 'R-HW-NOTE-05', self::NOTE_PLATEAU );
			}
		}
		foreach ( $candidates as $c ) {
			if ( ! $recent( $c[1] ) ) {
				return array(
					'note'            => $c[1],
					'rule'            => $c[0],
					'queue'           => $queue,
					'pending'         => $pending,
					'support_wording' => in_array( $c[1], self::SUPPORT_NOTES, true ),
				);
			}
		}
		if ( ! $paused ) {
			foreach ( $pending as $i => $id ) {
				if ( ! $recent( $id ) ) {
					array_splice( $pending, $i, 1 );
					return array(
						'note'            => $id,
						'rule'            => 'R-HW-WEEK-01',
						'queue'           => $queue,
						'pending'         => $pending,
						'support_wording' => in_array( $id, self::SUPPORT_NOTES, true ),
					);
				}
			}
		}
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( ! $recent( $id ) ) {
				return array(
					'note'            => $id,
					'rule'            => null,
					'queue'           => $queue,
					'pending'         => $pending,
					'support_wording' => in_array( $id, self::SUPPORT_NOTES, true ),
				);
			}
		}
		return array(
			'note'            => null,
			'rule'            => null,
			'queue'           => array(),
			'pending'         => $pending,
			'support_wording' => false,
		);
	}

	// ---- Plateau and focus blocks (R-HW-WEEK-02, R-HW-BLOCK-01, -02) -------------------------------------------------

	/**
	 * R-HW-WEEK-02: the plateau, by the signed R-ADJ-02 (≥ 4 weigh-ins in the 21 days before today, highest minus
	 * lowest ≤ 0.5 kg, lose-weight goal not reached), never with an eating-disorder flag, in pregnancy or below BMI 18.5.
	 * Not checked while Festivals and fasting runs, and that block's weeks 3 and 4 weigh-ins never count (T2 = A).
	 *
	 * @param array $in today, weights (Y-m-d => kg), goal_type (lose_weight), goal_reached, situations (eating_disorder,
	 *                  pregnancy, bmi_under_18_5), festival_running (bool), festival_weeks_3_4 (list of [from, to]).
	 * @return array {rule, fires, reason, template, text, offers, block, note, calorie_change: null (never lowers it)}
	 */
	public static function plateau( array $in ) {
		$out = array(
			'rule'           => 'R-HW-WEEK-02',
			'fires'          => false,
			'reason'         => '',
			'template'       => null,
			'text'           => null,
			'offers'         => array(),
			'block'          => null,
			'note'           => null,
			'calorie_change' => null,
		);
		if ( ! empty( $in['festival_running'] ) ) {
			$out['reason'] = 'festival_block_running';
			return $out;
		}
		$weights = isset( $in['weights'] ) && is_array( $in['weights'] ) ? $in['weights'] : array();
		foreach ( isset( $in['festival_weeks_3_4'] ) ? (array) $in['festival_weeks_3_4'] : array() as $span ) {
			foreach ( array_keys( $weights ) as $d ) {
				if ( self::days_between( $span[0], $d ) >= 0 && self::days_between( $d, $span[1] ) >= 0 ) {
					unset( $weights[ $d ] );
				}
			}
		}
		$r = LLD_Members_Decision_Engine::rule(
			'R-ADJ-02',
			array(
				'today'      => $in['today'],
				'situations' => isset( $in['situations'] ) ? array_values( (array) $in['situations'] ) : array(),
				'goal'       => array(
					'goal_type' => isset( $in['goal_type'] ) ? $in['goal_type'] : 'lose_weight',
					'measure'   => 'weight',
					'reached'   => ! empty( $in['goal_reached'] ),
				),
				'daily'      => array( 'weight' => $weights ),
			)
		);
		if ( ! $r['fires'] ) {
			$out['reason'] = $r['reason'];
			return $out;
		}
		$out['fires']    = true;
		$out['template'] = 'hw.T-HW-WEEK-02';
		$out['text']     = LLD_Members_Rule_Catalogue::text( 'hw.T-HW-WEEK-02' );
		$out['offers']   = $r['offers'];
		$out['block']    = 'breaking-a-plateau';
		$out['note']     = self::NOTE_PLATEAU;
		return $out;
	}

	/**
	 * R-HW-BLOCK-01: the focus block suggested from week 9, with its one-line reason (the member may choose any).
	 *
	 * @param array $in programme_week, today, goal_reached, plateau (bool), festivals (list of {id, name, start} in the
	 *                  member's approved calendar), declined_festivals, finished_festivals, festival_running, finished
	 *                  (block slugs already finished), barrier, stress, sleep, alcohol_freq, alcohol_amount, food_rules,
	 *                  eats_out, work.
	 * @return array {rule, block: slug|null, id, step (1 to 9), reason: {template, values, text}, text, choices,
	 *               festival|null, keep_it_off: bool}
	 */
	public static function suggest_block( array $in ) {
		$out = array(
			'rule'        => 'R-HW-BLOCK-01',
			'block'       => null,
			'id'          => null,
			'step'        => null,
			'reason'      => null,
			'text'        => null,
			'choices'     => array_keys( self::BLOCKS ),
			'festival'    => null,
			'keep_it_off' => false,
		);
		if ( ! empty( $in['goal_reached'] ) ) {
			$out['keep_it_off'] = true; // blocks continue until the goal is reached; then Keep It Off (FR3-104 AS-05)
			$out['rule']        = 'R-HW-MOT-02';
			$out['choices']     = array();
			return $out;
		}
		if ( isset( $in['programme_week'] ) && (int) $in['programme_week'] <= 8 ) {
			$out['choices'] = array();
			return $out; // focus blocks start after week 8
		}
		$finished = isset( $in['finished'] ) ? (array) $in['finished'] : array();
		$a        = self::answers( array( 'answers' => $in ) );
		$matches  = array();
		if ( ! empty( $in['plateau'] ) ) {
			$matches[] = array( 1, 'breaking-a-plateau', 'plateau', array() );
		}
		$fest = null;
		foreach ( isset( $in['festivals'] ) ? (array) $in['festivals'] : array() as $f ) {
			$o = LLD_Members_HW_Festival::offer(
				array(
					'festival'       => $f,
					'today'          => $in['today'],
					'programme_week' => isset( $in['programme_week'] ) ? $in['programme_week'] : 9,
					'keeps'          => true,
					'declined'       => in_array( $f['id'], isset( $in['declined_festivals'] ) ? (array) $in['declined_festivals'] : array(), true ),
					'finished'       => in_array( $f['id'], isset( $in['finished_festivals'] ) ? (array) $in['finished_festivals'] : array(), true ),
					'running'        => ! empty( $in['festival_running'] ),
				)
			);
			if ( $o['offered'] && ( null === $fest || self::days_between( $f['start'], $fest[0]['start'] ) > 0 ) ) {
				$fest = array( $f, $o ); // overlapping windows: the earlier festival
			}
		}
		if ( $fest ) {
			$matches[] = array(
				2,
				'festivals-and-fasting',
				'festival',
				array(
					'festival' => isset( $fest[0]['name'] ) ? $fest[0]['name'] : $fest[0]['id'],
					'n'        => $fest[1]['weeks'],
				),
			);
		}
		if ( isset( self::BARRIER_BLOCKS[ $a['barrier'] ] ) ) {
			$matches[] = array( 3, self::BARRIER_BLOCKS[ $a['barrier'] ], 'barrier', array( 'barrier' => LLD_Members_Rule_Catalogue::text( 'hw.T-HW-STEP-01.barrier.' . $a['barrier'] ) ) );
		}
		if ( null !== $a['stress'] && $a['stress'] >= 7 ) {
			$matches[] = array( 4, 'stress-emotional-eating', 'stress', array( 'n' => (int) $a['stress'] ) );
		}
		if ( in_array( $a['sleep'], array( 'under_6', '6_7' ), true ) ) {
			$matches[] = array( 5, 'sleep-and-weight', 'sleep', array() );
		}
		if ( 'none' !== self::drinks( $a )['alcohol_step'] ) {
			$matches[] = array( 6, 'alcohol', 'alcohol', array() );
		}
		if ( self::eats_out_per_week( $a['eats_out'] ) >= 3 ) {
			$matches[] = array( 7, 'eating-out-social-life', 'eating_out', array() );
		}
		if ( in_array( $a['work'], array( 'shifts', 'nights' ), true ) ) {
			$matches[] = array( 8, 'shift-work', 'shift_work', array() );
		}
		$matches[] = array( 9, 'getting-stronger', 'getting_stronger', array() );
		foreach ( $matches as $m ) {
			if ( 'festivals-and-fasting' !== $m[1] && in_array( $m[1], $finished, true ) ) {
				continue; // a block already finished is skipped (Festivals and fasting counts per festival)
			}
			$phrase        = LLD_Members_Rule_Catalogue::fill( 'hw.T-HW-BLOCK-01.reason.' . $m[2], $m[3] );
			$out['block']  = $m[1];
			$out['id']     = self::BLOCKS[ $m[1] ];
			$out['step']   = $m[0];
			$out['reason'] = array(
				'template' => 'hw.T-HW-BLOCK-01.reason.' . $m[2],
				'values'   => $m[3],
				'text'     => $phrase,
			);
			$out['text']   = LLD_Members_Rule_Catalogue::fill( 'hw.T-HW-BLOCK-01', array( 'reason' => $phrase ) );
			if ( 2 === $m[0] ) {
				$out['festival'] = $fest[0]['id'];
			}
			return $out;
		}
		return $out; // every match finished: no suggestion, the member chooses from the list
	}

	// ---- Reminders, quick questions, food (R-HW-REM-01, R-HW-QOD-01, R-HW-FOOD) -------------------------------------

	/**
	 * R-HW-REM-01: reminder times. Every reminder says only the T-HW-REM-01 line (no health details, NFR3-16).
	 *
	 * @param array $in work, snack_time (HH:MM or "after 9 pm"), wake_time (HH:MM, shift workers), bedtime (HH:MM,
	 *                  optional: 16 hours after waking when not given), reminders_on (default true).
	 * @return array {rule, reminders: list of {kind: step|snack, time}, ask_wake_up: bool, text}
	 */
	public static function reminder_times( array $in ) {
		$out = array(
			'rule'        => 'R-HW-REM-01',
			'reminders'   => array(),
			'ask_wake_up' => false,
			'text'        => LLD_Members_Rule_Catalogue::text( 'hw.T-HW-REM-01' ),
		);
		if ( isset( $in['reminders_on'] ) && ! $in['reminders_on'] ) {
			return $out;
		}
		$shift = isset( $in['work'] ) && in_array( $in['work'], array( 'shifts', 'nights', 'irregular' ), true );
		$snack = isset( $in['snack_time'] ) ? self::minutes( $in['snack_time'] ) : null;
		if ( $shift ) {
			$wake = isset( $in['wake_time'] ) ? self::minutes( $in['wake_time'] ) : null;
			if ( null === $wake ) {
				$out['ask_wake_up'] = true; // no fixed times: asked once for their wake-up time
				return $out;
			}
			$out['reminders'][] = array(
				'kind' => 'step',
				'time' => self::hhmm( $wake + 30 ),
			);
			if ( null !== $snack ) {
				$t   = ( $snack - 30 + 1440 ) % 1440;
				// Bedtime: the member's own, else 16 hours after waking (the D3-60 reading), so a snack reminder never
				// falls in their main sleep.
				$bed = isset( $in['bedtime'] ) ? self::minutes( $in['bedtime'] ) : null;
				$bed = null === $bed ? ( $wake + 16 * 60 ) % 1440 : $bed;
				if ( ! self::between( $t, $bed, $wake ) ) {
					$out['reminders'][] = array(
						'kind' => 'snack',
						'time' => self::hhmm( $t ),
					);
				}
			}
			return $out;
		}
		$out['reminders'][] = array(
			'kind' => 'step',
			'time' => '08:00',
		);
		if ( null !== $snack && $snack >= 18 * 60 ) {
			$out['reminders'][] = array(
				'kind' => 'snack',
				'time' => self::hhmm( $snack - 30 ),
			);
		}
		return $out;
	}

	/**
	 * R-HW-QOD-01: one or two unanswered quick questions for Today, in the signed order.
	 *
	 * @param array $in answered (keys answered or skipped), known (keys already in the health profile or food
	 *                  preferences), eating_disorder.
	 * @return array {rule, questions: up to 2 keys, rules: key => rule that reads it}
	 */
	public static function qod( array $in ) {
		$out = array(
			'rule'      => 'R-HW-QOD-01',
			'questions' => array(),
			'rules'     => array(),
		);
		if ( isset( $in['eating_disorder'] ) && 'yes' === $in['eating_disorder'] ) {
			return $out; // Healthy Weight is not offered (R-HW-SAFE-01), so nothing is asked
		}
		$skip = array_merge( isset( $in['answered'] ) ? (array) $in['answered'] : array(), isset( $in['known'] ) ? (array) $in['known'] : array() );
		foreach ( self::QUESTIONS as $key => $rule ) {
			if ( null === $rule || in_array( $key, $skip, true ) ) {
				continue;
			}
			$out['questions'][]   = $key;
			$out['rules'][ $key ] = $rule;
			if ( count( $out['questions'] ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	/** Allergen names as the recipes record them, read as the members' allergy keys (FR3-36, HW-QW-15). */
	const ALLERGEN_NAMES = array(
		'eggs'        => 'egg',
		'tree nuts'   => 'nuts',
		'tree_nuts'   => 'nuts',
		'treenuts'    => 'nuts',
		'pine nuts'   => 'nuts',
		'soya'        => 'soy',
		'crustaceans' => 'shellfish',
		'dairy'       => 'milk',
	);

	/**
	 * One allergen as a member allergy key: the recipes' "tree nuts", "soya", "eggs"… and the members' "nuts", "soy",
	 * "egg" are the same allergen.
	 *
	 * @param string $name Allergen name.
	 */
	public static function allergen( $name ) {
		$n = strtolower( trim( (string) $name ) );
		return isset( self::ALLERGEN_NAMES[ $n ] ) ? self::ALLERGEN_NAMES[ $n ] : $n;
	}

	/**
	 * R-HW-FOOD-01 for one approved item: every food rule met and no listed allergen (FR3-36, FR3-99).
	 *
	 * @param array $item   {id, approved, diets: [everything|vegetarian|vegan], allergens: [], food_rules: [tags]}.
	 * @param array $member {food_rules: [], allergies: [], fasting_day: bool (a "no grains or pulses" fasting day)}.
	 * @return array {rule, ok: bool, reasons: why not, lines: approved lines to show with the kept item}
	 */
	public static function food_ok( array $item, array $member ) {
		$rules     = self::lower_list( isset( $member['food_rules'] ) ? $member['food_rules'] : array() );
		$allergies = array_map( array( __CLASS__, 'allergen' ), self::lower_list( isset( $member['allergies'] ) ? $member['allergies'] : array() ) );
		$tags      = self::lower_list( isset( $item['food_rules'] ) ? $item['food_rules'] : array() );
		$diets     = self::lower_list( isset( $item['diets'] ) ? $item['diets'] : array() );
		$allergens = array_map( array( __CLASS__, 'allergen' ), self::lower_list( isset( $item['allergens'] ) ? $item['allergens'] : array() ) );
		$reasons   = array();
		if ( empty( $item['approved'] ) ) {
			$reasons[] = 'not_approved';
		}
		foreach ( $rules as $r ) {
			if ( isset( self::FOOD_RULE_TAGS[ $r ] ) ) {
				foreach ( array_intersect( self::FOOD_RULE_TAGS[ $r ], $tags ) as $t ) {
					$reasons[] = $r . ':' . $t;
				}
			}
			if ( ( 'vegetarian' === $r || 'vegan' === $r ) && ! in_array( $r, $diets, true ) ) {
				$reasons[] = $r . ':diet';
			}
			if ( 'jain' === $r && ! in_array( 'vegetarian', $diets, true ) ) {
				$reasons[] = 'jain:diet'; // lacto-vegetarian
			}
			if ( 'no_grains_pulses_fasting' === $r && ! empty( $member['fasting_day'] ) && in_array( 'contains-grains-or-pulses', $tags, true ) ) {
				$reasons[] = $r . ':contains-grains-or-pulses';
			}
		}
		foreach ( $allergies as $al ) {
			if ( in_array( $al, $allergens, true ) || ( isset( self::ALLERGEN_TAGS[ $al ] ) && in_array( self::ALLERGEN_TAGS[ $al ], $tags, true ) ) ) {
				$reasons[] = 'allergy:' . $al;
			}
		}
		$lines = array();
		if ( ! $reasons ) {
			$meat = in_array( 'needs-halal-meat', $tags, true ) || in_array( 'needs-kosher-meat', $tags, true );
			if ( in_array( 'halal', $rules, true ) && $meat ) {
				$lines[] = self::FOOD_LINES['halal'];
			}
			if ( in_array( 'kosher', $rules, true ) && $meat ) {
				$lines[] = self::FOOD_LINES['kosher'];
			}
		}
		return array(
			'rule'    => 'R-HW-FOOD-01',
			'ok'      => ! $reasons,
			'reasons' => $reasons,
			'lines'   => $lines,
		);
	}

	/**
	 * R-HW-FOOD-01 for a meal type, then R-HW-FOOD-02's order: only matching items; fewer than 5 → the empty state,
	 * never a non-matching item. Preferences only reorder (quick, low cost, favourite cuisines first). Optional
	 * calories left (FR3-36): items above it are not suggested.
	 *
	 * @param array $items  Approved items (food_ok() shape, plus meal_type, kcal, quick, low_cost, cuisine).
	 * @param array $member food_ok() member, plus cooking_time (under_15|…), budget (tight|…), cuisines (list),
	 *                      kcal_left (optional).
	 * @param string|null $meal_type Meal type to suggest for, or null for all.
	 * @return array {rule, empty: bool, items: list of {id, reason (T-HW-FOOD-02 text)|null, lines}}, lines (general)}
	 */
	public static function food_filter( array $items, array $member, $meal_type = null ) {
		$kept = array();
		foreach ( $items as $pos => $item ) {
			if ( null !== $meal_type && ( isset( $item['meal_type'] ) ? $item['meal_type'] : null ) !== $meal_type ) {
				continue;
			}
			$ok = self::food_ok( $item, $member );
			if ( ! $ok['ok'] ) {
				continue;
			}
			if ( isset( $member['kcal_left'] ) && is_numeric( $member['kcal_left'] ) && isset( $item['kcal'] ) && (float) $item['kcal'] > (float) $member['kcal_left'] ) {
				continue;
			}
			$score  = 0;
			$reason = null;
			$cuisines = self::lower_list( isset( $member['cuisines'] ) ? $member['cuisines'] : array() );
			if ( isset( $member['cooking_time'] ) && 'under_15' === $member['cooking_time'] && ! empty( $item['quick'] ) ) {
				$score += 4;
				$reason = LLD_Members_Rule_Catalogue::text( 'hw.T-HW-FOOD-02.quick' );
			}
			if ( isset( $member['budget'] ) && 'tight' === $member['budget'] && ! empty( $item['low_cost'] ) ) {
				$score += 2;
				$reason = $reason ? $reason : LLD_Members_Rule_Catalogue::text( 'hw.T-HW-FOOD-02.low_cost' );
			}
			if ( ! empty( $item['cuisine'] ) && in_array( strtolower( $item['cuisine'] ), $cuisines, true ) ) {
				$score += 1;
				$reason = $reason ? $reason : ( isset( $member['cuisine_labels'][ $item['cuisine'] ] ) ? LLD_Members_Rule_Catalogue::fill( 'hw.T-HW-FOOD-02.cuisine', array( 'cuisine' => $member['cuisine_labels'][ $item['cuisine'] ] ) ) : null ); // the approved choice label, never the raw value
			}
			$kept[] = array(
				'id'     => $item['id'],
				'score'  => $score,
				'pos'    => $pos,
				'reason' => $reason,
				'lines'  => $ok['lines'],
			);
		}
		$general = array();
		$mrules  = self::lower_list( isset( $member['food_rules'] ) ? $member['food_rules'] : array() );
		if ( in_array( 'jain', $mrules, true ) ) {
			$general[] = self::FOOD_LINES['jain'];
		}
		if ( in_array( 'halal', $mrules, true ) || in_array( 'jain', $mrules, true ) ) {
			$general[] = self::FOOD_LINES['halal_extras']; // shown to Jain members too
		}
		if ( count( $kept ) < 5 ) {
			return array(
				'rule'  => 'R-HW-FOOD-01',
				'empty' => true,
				'items' => array(),
				'lines' => $general,
			);
		}
		usort(
			$kept,
			function ( $x, $y ) {
				return array( -$x['score'], $x['pos'] ) <=> array( -$y['score'], $y['pos'] );
			}
		);
		return array(
			'rule'  => 'R-HW-FOOD-01',
			'empty' => false,
			'items' => array_map(
				function ( $k ) {
					return array(
						'id'     => $k['id'],
						'reason' => $k['reason'],
						'lines'  => $k['lines'],
					);
				},
				$kept
			),
			'lines' => $general,
		);
	}

	/**
	 * R-HW-FOOD-03: fasting periods. During a fast the member keeps (from the approved calendar), meal timing switches to
	 * the approved fasting meal plan; the calorie target is unchanged. A member with type 2 diabetes keeps the usual plan
	 * and sees the approved line (FR3-99 AS-07).
	 *
	 * @param array $in date, fasts (list of {id, start, end} the member keeps), conditions.
	 * @return array {rule, plan: fasting|usual, fast|null, target_changes: false, line|null}
	 */
	public static function meal_timing( array $in ) {
		$out = array(
			'rule'           => 'R-HW-FOOD-03',
			'plan'           => 'usual',
			'fast'           => null,
			'target_changes' => false,
			'line'           => null,
		);
		foreach ( isset( $in['fasts'] ) ? (array) $in['fasts'] : array() as $f ) {
			if ( self::days_between( $f['start'], $in['date'] ) >= 0 && self::days_between( $in['date'], $f['end'] ) >= 0 ) {
				$out['fast'] = $f['id'];
				if ( in_array( 'type2_diabetes', self::lower_list( isset( $in['conditions'] ) ? $in['conditions'] : array() ), true ) ) {
					$out['line'] = 'If you are planning to fast, follow the plan you agreed with your doctor or diabetes team.';
				} else {
					$out['plan'] = 'fasting';
				}
				break;
			}
		}
		return $out;
	}

	/**
	 * R-HW-MOT-01: the member's reason on Today; with an event date, the weight they are on course for by then.
	 *
	 * @param array $in reason (short text), event (name, e.g. "your wedding"), event_date, today, start_kg, goal_kg,
	 *                  pace, goal_date (for the weeks to go).
	 * @return array {rule, template, text, kg|null, weeks|null}
	 */
	public static function motivation( array $in ) {
		if ( ! empty( $in['event_date'] ) && isset( $in['start_kg'], $in['pace'], $in['today'] ) ) {
			$weeks = self::days_between( $in['today'], $in['event_date'] ) / 7;
			$kg    = max( isset( $in['goal_kg'] ) ? (float) $in['goal_kg'] : 0, (float) $in['start_kg'] - (float) $in['pace'] * $weeks );
			$kg    = self::round1( $kg );
			return array(
				'rule'     => 'R-HW-MOT-01',
				'template' => 'hw.T-HW-MOT-01.event',
				'text'     => LLD_Members_Rule_Catalogue::fill(
					'hw.T-HW-MOT-01.event',
					array(
						'event' => isset( $in['event'] ) ? $in['event'] : '',
						'kg'    => self::kg( $kg ),
					)
				),
				'kg'       => $kg,
				'weeks'    => round( $weeks, 1 ),
			);
		}
		$weeks = isset( $in['goal_date'], $in['today'] ) ? max( 0, (int) ceil( self::days_between( $in['today'], $in['goal_date'] ) / 7 ) ) : null;
		return array(
			'rule'     => 'R-HW-MOT-01',
			'template' => 'hw.T-HW-MOT-01',
			'text'     => null === $weeks ? null : LLD_Members_Rule_Catalogue::fill(
				'hw.T-HW-MOT-01',
				array(
					'reason' => isset( $in['reason'] ) ? $in['reason'] : '',
					'weeks'  => $weeks,
				)
			),
			'kg'       => null,
			'weeks'    => $weeks,
		);
	}

	// ---- Helpers ----------------------------------------------------------------------------------------------------

	/** The answers with defaults; alcohol is "never" when the food rules exclude it (FR3-98 AS-08). */
	private static function answers( array $in ) {
		$a = isset( $in['answers'] ) && is_array( $in['answers'] ) ? $in['answers'] : array();
		$d = array(
			'barrier'           => null,
			'time'              => null,
			'confidence'        => null,
			'work'              => null,
			'sleep'             => null,
			'stress'            => null,
			'alcohol_freq'      => null,
			'alcohol_amount'    => null,
			'sugary_drinks'     => null,
			'breakfast'         => null,
			'breakfast_protein' => false,
			'eats_out'          => null,
			'activity_level'    => null,
			'enjoyment'         => null,
			'food_rules'        => array(),
			'conditions'        => array(),
		);
		$a                 = array_merge( $d, array_intersect_key( $a, $d ) );
		$a['stress']       = is_numeric( $a['stress'] ) ? (float) $a['stress'] : null;
		$a['confidence']   = is_numeric( $a['confidence'] ) ? (float) $a['confidence'] : null;
		$a['conditions']   = self::lower_list( $a['conditions'] );
		$a['alcohol_freq'] = self::alcohol_freq( $a );
		return $a;
	}

	/** How often the member drinks, "never" when their food rules exclude alcohol. */
	private static function alcohol_freq( array $in ) {
		$rules = self::lower_list( isset( $in['food_rules'] ) ? $in['food_rules'] : array() );
		if ( array_intersect( self::NO_ALCOHOL_RULES, $rules ) ) {
			return 'never';
		}
		return isset( $in['alcohol_freq'] ) && '' !== $in['alcohol_freq'] ? $in['alcohol_freq'] : null;
	}

	/** R-HW-STEP-03: easier, standard or stretch for the week. */
	private static function version( $week, array $a, array $h ) {
		if ( 1 === $week ) {
			if ( 'under_10' === $a['time'] || ( null !== $a['confidence'] && $a['confidence'] <= 4 ) ) {
				return 'easier';
			}
			if ( null !== $a['confidence'] && $a['confidence'] >= 8 && 'over_30' === $a['time'] ) {
				return 'stretch';
			}
			return 'standard';
		}
		$levels = array( 'easier', 'standard', 'stretch' );
		$was    = isset( $h['version'] ) && in_array( $h['version'], $levels, true ) ? $h['version'] : 'standard';
		$i      = array_search( $was, $levels, true );
		if ( isset( $h['done_pct'] ) && is_numeric( $h['done_pct'] ) ) {
			if ( $h['done_pct'] < 50 ) {
				$i = max( 0, $i - 1 );
			} elseif ( $h['done_pct'] > 80 ) {
				$i = min( 2, $i + 1 );
			}
		}
		return $levels[ $i ];
	}

	/**
	 * R-HW-STEP-06: the week's exercise level.
	 *
	 * @return array {rule, level, walk_minutes|null (null: keep their usual exercise), capped: bool, strength_per_week,
	 *               strength_steps: ids, moderate_only, diabetes_note, enjoyment, reason: {template, values, text}}
	 */
	private static function exercise( $week, array $a, array $in ) {
		$level  = $a['activity_level'] ? str_replace( ' ', '_', strtolower( $a['activity_level'] ) ) : null;
		$capped = 'under_10' === $a['time'];
		$plan   = function ( $w ) use ( $level ) {
			if ( 'low' === $level ) {
				return min( 30, 10 + 5 * ( $w - 1 ) );
			}
			if ( 'some' === $level ) {
				return min( 30, 15 + 5 * ( $w - 1 ) );
			}
			if ( 'active' === $level ) {
				return 30;
			}
			return null;
		};
		$minutes = null;
		if ( null !== $plan( 1 ) ) {
			$m = null;
			for ( $w = 1; $w <= $week; $w++ ) {
				$u = $plan( $w );
				if ( $capped && $w <= 4 ) {
					$m = min( $u, 10 );
				} else {
					$m = null === $m ? $u : min( $u, $m + 5 ); // after the cap, adding 5 minutes a week from where they are
				}
			}
			$minutes = $m;
		}
		$strength = 0;
		if ( 'active' === $level && $week >= 3 ) {
			$strength = 1;
		} elseif ( 'very_active' === $level ) {
			$strength = 2;
		}
		$ids = array();
		if ( $strength ) {
			$pool = array();
			foreach ( array_merge( isset( $in['library'] ) ? (array) $in['library'] : array(), isset( $in['activities'] ) ? (array) $in['activities'] : array() ) as $x ) {
				if ( in_array( 'strength', isset( $x['tags'] ) ? (array) $x['tags'] : array(), true ) && ! in_array( $x['id'], $pool, true ) ) {
					$pool[] = $x['id'];
				}
			}
			for ( $k = 0; $k < $strength && $pool; $k++ ) {
				$ids[] = $pool[ $k % count( $pool ) ];
			}
		}
		$reason = null;
		if ( 'very_active' === $level ) {
			$reason = self::reason( 'hw.T-HW-STEP-06.very_active' );
		} elseif ( null !== $minutes ) {
			$reason = self::reason( 'hw.T-HW-STEP-06', array( 'minutes' => $minutes ) );
		}
		return array(
			'rule'              => 'R-HW-STEP-06',
			'level'             => $level,
			'walk_minutes'      => $minutes,
			'capped'            => $capped && $week <= 4,
			'strength_per_week' => $strength,
			'strength_steps'    => $ids,
			'moderate_only'     => (bool) array_intersect( array( 'high_blood_pressure', 'heart_disease' ), $a['conditions'] ),
			'diabetes_note'     => in_array( 'type2_diabetes', $a['conditions'], true ),
			'enjoyment'         => $a['enjoyment'],
			'reason'            => $reason,
		);
	}

	/**
	 * Put a step in a week: a slot left empty by R-HW-STEP-02 (not day 5, kept for the alcohol step), otherwise day 6,
	 * then day 4, then day 2; never over another rule's step.
	 */
	private static function insert( array &$slots, array $step ) {
		foreach ( $slots as $i => $s ) {
			if ( null === $s['id'] && 4 !== $i ) {
				$slots[ $i ] = $step;
				return;
			}
		}
		foreach ( array( 5, 3, 1 ) as $i ) {
			if ( isset( $slots[ $i ] ) && null === $slots[ $i ]['rule'] ) {
				$slots[ $i ] = $step;
				return;
			}
		}
	}

	/** Does the week already hold this id? */
	private static function has_id( array $slots, $id ) {
		foreach ( $slots as $s ) {
			if ( $s['id'] === $id ) {
				return true;
			}
		}
		return false;
	}

	/** The theme tags of a week: given, or the rule-bearing habit tags of its activities in order. */
	private static function theme( array $slots, array $in, $w ) {
		if ( isset( $in['themes'][ $w ] ) ) {
			return (array) $in['themes'][ $w ];
		}
		$tags = array();
		foreach ( $slots as $s ) {
			if ( null === $s['id'] ) {
				continue;
			}
			foreach ( isset( $s['tags'] ) ? $s['tags'] : array() as $t ) {
				if ( ! in_array( $t, self::LABEL_TAGS, true ) && ! in_array( $t, $tags, true ) && ! in_array( $t, array( 'drink-swap', 'breakfast-protein', 'alcohol' ), true ) ) {
					$tags[] = $t;
				}
			}
		}
		return $tags;
	}

	/**
	 * The next unused action-library item, preferring the theme's tags in order; never one the member's answers leave
	 * out (drink swaps, alcohol).
	 */
	private static function pick( array $library, array &$used, array $theme, array $a ) {
		$no_drink = in_array( self::alcohol_freq( $a ), array( 'never', null ), true );
		$ok       = function ( $x ) use ( &$used, $a, $no_drink ) {
			$tags = isset( $x['tags'] ) ? (array) $x['tags'] : array();
			if ( isset( $used[ $x['id'] ] ) ) {
				return false;
			}
			if ( in_array( 'drink-swap', $tags, true ) && in_array( $a['sugary_drinks'], array( 'never', 'less_than_weekly' ), true ) ) {
				return false;
			}
			if ( in_array( 'alcohol', $tags, true ) && $no_drink ) {
				return false;
			}
			return true;
		};
		foreach ( array_merge( $theme, array( null ) ) as $t ) {
			foreach ( $library as $x ) {
				if ( $ok( $x ) && ( null === $t || in_array( $t, isset( $x['tags'] ) ? (array) $x['tags'] : array(), true ) ) ) {
					$used[ $x['id'] ] = true;
					return array(
						'id'   => $x['id'],
						'tags' => isset( $x['tags'] ) ? (array) $x['tags'] : array(),
					);
				}
			}
		}
		return null;
	}

	/** Content ids of the programme's own steps (approved HW-SL, HW-ST and HW-AL items), unless given. */
	private static function step_id( array $steps, $kind, $which, $w ) {
		if ( 'alcohol' === $kind ) {
			$ids = array_merge(
				array(
					'free_day'   => 'HW-AL-01',
					'second_day' => 'HW-AL-02',
					'one_fewer'  => 'HW-AL-03',
				),
				isset( $steps['alcohol'] ) ? (array) $steps['alcohol'] : array()
			);
			return $ids[ $which ];
		}
		if ( isset( $steps[ $kind ][ $w ] ) ) {
			return $steps[ $kind ][ $w ];
		}
		return ( 'sleep' === $kind ? 'HW-SL-0' : 'HW-ST-0' ) . $w; // "the step numbered for that week"
	}

	/** Index items by id. */
	private static function index( array $items ) {
		$out = array();
		foreach ( $items as $x ) {
			if ( isset( $x['id'] ) ) {
				$out[ $x['id'] ] = $x;
			}
		}
		return $out;
	}

	/** A reason line: template, values and text. */
	private static function reason( $template, array $values = array() ) {
		return array(
			'template' => $template,
			'values'   => $values,
			'text'     => LLD_Members_Rule_Catalogue::fill( $template, $values ),
		);
	}

	/** A card. */
	private static function card( $rule, $template ) {
		return array(
			'rule'     => $rule,
			'template' => $template,
			'text'     => LLD_Members_Rule_Catalogue::text( $template ),
		);
	}

	/** One milestone line. */
	private static function milestone( $kind, $kg, $start, $pace, $today ) {
		$weeks = LLD_Members_Goal_Timeline::weeks_for( $start - $kg, $pace );
		$date  = self::add_days( $today, 7 * $weeks );
		$label = LLD_Members_Rule_Catalogue::text( 'hw.T-HW-NUM-04.label.' . $kind );
		return array(
			'kind'  => $kind,
			'kg'    => $kg,
			'weeks' => $weeks,
			'date'  => $date,
			'label' => $label,
			'text'  => LLD_Members_Rule_Catalogue::fill(
				'hw.T-HW-NUM-04',
				array(
					'kg'    => self::kg( $kg ),
					'label' => $label,
					'date'  => self::long_date( $date ),
				)
			),
		);
	}

	/** Eats out a week, as a number. */
	private static function eats_out_per_week( $v ) {
		if ( is_numeric( $v ) ) {
			return (float) $v;
		}
		return '3_plus' === $v ? 3 : 0;
	}

	/** A number from the input, or null. */
	private static function num( array $in, $key ) {
		return isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (float) $in[ $key ] : null;
	}

	/** Lower-case list of strings. */
	private static function lower_list( $v ) {
		return array_values( array_map( 'strtolower', array_map( 'strval', (array) $v ) ) );
	}

	/** Nearest 10, half up (method sheet 5.2). */
	private static function r10( $x ) {
		return (int) ( floor( $x / 10 + 0.5 + 1e-9 ) * 10 );
	}

	/** 1 decimal, half up. */
	private static function round1( $x ) {
		return floor( $x * 10 + 0.5 + 1e-9 ) / 10;
	}

	/** kg as shown: 1 decimal. */
	private static function kg( $x ) {
		return number_format( (float) $x, 1, '.', '' );
	}

	/** "18 December 2026". */
	private static function long_date( $date ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $at ? $at->format( 'j F Y' ) : '';
	}

	/** Minutes after midnight from HH:MM or "after 9 pm"; null when unreadable. */
	private static function minutes( $t ) {
		$t = strtolower( trim( (string) $t ) );
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $t, $m ) ) {
			return ( (int) $m[1] % 24 ) * 60 + (int) $m[2];
		}
		if ( preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/', $t, $m ) ) {
			$h = (int) $m[1] % 12 + ( 'pm' === $m[3] ? 12 : 0 );
			return $h * 60 + ( isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0 );
		}
		return null;
	}

	/** HH:MM. */
	private static function hhmm( $m ) {
		$m = ( (int) $m % 1440 + 1440 ) % 1440;
		return sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
	}

	/** Is minute $t in the window [$from, $to) that may wrap midnight? */
	private static function between( $t, $from, $to ) {
		return $from <= $to ? ( $t >= $from && $t < $to ) : ( $t >= $from || $t < $to );
	}

	/** Y-m-d plus days. */
	private static function add_days( $date, $n ) {
		return LLD_Members_HW_Festival::add_days( $date, $n );
	}

	/** Days from $a to $b. */
	private static function days_between( $a, $b ) {
		return LLD_Members_HW_Festival::days_between( $a, $b );
	}
}
