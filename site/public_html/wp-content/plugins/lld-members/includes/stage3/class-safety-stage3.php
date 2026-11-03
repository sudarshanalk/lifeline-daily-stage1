<?php
/**
 * Stage 3 safety matrix (P3-03, FR3-69, FR3-46, FR3-45), written from the owner-signed
 * `04 Health Tools/Stage 3/P3-03-Safety-Matrix-Stage-3.md` (approval record `approvals/stage3/safety-matrix.json`).
 *
 * Extends the Stage 2 matrix (LLD_Members_Safety, unchanged) with the Stage 3 columns and the five new rows:
 * heart-rate-lowering medicine, known heart disease, age 65 and over, BMI under 18.5 and the low-mood signal; the
 * two new profile questions; and the frozen warning levels for every logged reading (Part C).
 *
 * Safety is applied first, access second, matching third, and is never paid for: every answer here is the same
 * for Free and Premium members and for the programming interface (FR3-69 AS-02). Nothing is cached: a profile
 * change applies at once (FR3-69 AS-04). With the Stage 3 switches off, applies() answers "no change" for every
 * member, so Stage 2 members see nothing new (NFR3-06).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows, levels, wording and the two questions the matrix adds.
 */
class LLD_Members_Stage3_Safety {

	const VERSION = '1.0';

	/** Every situation the Stage 3 matrix knows, in matrix order (Stage 2 rows first, then the new rows). */
	const SITUATIONS = array( 'unknown', 'eating_disorder', 'pregnancy', 'kidney', 'diabetes', 'prediabetes', 'blood_pressure', 'cholesterol', 'heart_rate_medicine', 'heart_disease', 'age_65_plus', 'bmi_under_18_5', 'low_mood' );

	/** The Stage 3 feature columns (Part B), by their feature switch. */
	const FEATURES = array( 'goals', 'food_tracker', 'health_logging', 'insights', 'calculators_new', 'reminders' );

	/** The owner's wording (Part C and Part E), keyed as the approval record keys it; hashed by the test. */
	const MESSAGES = array(
		'glucose.urgent-low'                      => "Very low blood sugar. Take 15 g of fast sugar now (glucose tablets, sugary drink, juice) and re-test in 15 minutes. If you can't swallow safely, are confused or don't improve, someone should call your local emergency number.",
		'glucose.urgent-high'                     => 'Very high blood sugar. If you feel unwell, vomit, breathe fast or feel drowsy, get medical help today.',
		'glucose.caution-low'                     => 'Low. If you take diabetes medicine, treat as your team advised and tell them.',
		'glucose.caution-high'                    => 'High. If this is unusual for you, contact your diabetes team.',
		'water.caution'                           => "that's a lot of water; if you're unusually thirsty, see your doctor",
		'ed.food-tracker.calorie-total'           => "This diary keeps a simple record of what you eat. It doesn't count calories.",
		'ed.food-tracker.diary-heading'           => 'My meals',
		'ed.weight-goal-screen'                   => "Weight goals aren't offered, based on your health profile. Choose a goal around activity, sleep, blood pressure or how you feel.",
		'pregnancy.calorie-target'                => "We don't set calorie targets during pregnancy or breastfeeding. Your midwife can advise on eating well for you and your baby.",
		'profile.heart-rate-medicine.question'    => 'Do you take medicine that slows your heart rate (for example a beta-blocker)?',
		'profile.heart-rate-medicine.effect-note' => "Heart-rate zones and the fitness age estimate won't be shown; we'll use the talk test instead.",
		'profile.known-heart-disease.question'    => 'Have you had a heart attack, stroke or mini-stroke, angina, or heart or artery surgery?',
		'profile.known-heart-disease.effect-note' => "The heart risk check and fitness age aren't suitable for you; your doctor or cardiac rehab team guides your exercise. Everything else is here for you.",
		'low-mood-signal'                         => 'It looks like the last few days have been hard. [the FR3-44 support message] You can find free, confidential support near you at findahelpline.com. If you are in danger now, call your local emergency number.',
		'reminder.log-meals'                      => "Time to log today's meals",
		'reminder.weigh-in-day'                   => 'Your weigh-in day',
		'reminder.workout-day'                    => 'Workout day',
		'reminder.weekly-check-in'                => 'Weekly check-in',
		'food-tracker.over-target'                => '{n} kcal over today',
	);

	/**
	 * The two new profile questions (D3-38). The answer is stored as a key in the health profile's conditions list
	 * (data model: Health profile, "extended"), so no Stage 2 column changes.
	 */
	const PROFILE_QUESTIONS = array(
		'heart-rate-medicine' => array(
			'question'    => 'profile.heart-rate-medicine.question',
			'answers'     => array( 'yes', 'no', 'not_sure' ),
			'effect_note' => 'profile.heart-rate-medicine.effect-note',
			'situation'   => 'heart_rate_medicine',
			'applies_on'  => array( 'yes', 'not_sure' ),
		),
		'heart-disease'       => array(
			'question'    => 'profile.known-heart-disease.question',
			'answers'     => array( 'yes', 'no' ),
			'effect_note' => 'profile.known-heart-disease.effect-note',
			'situation'   => 'heart_disease',
			'applies_on'  => array( 'yes' ),
		),
	);

	/** Part C warning levels (frozen like the calculators). */
	const LEVELS = array(
		'blood_pressure' => array(
			'impossible'          => array( 'sys' => array( 60, 260 ), 'dia' => array( 30, 160 ), 'gap' => 10 ),
			'urgent'              => array( 'sys' => 180, 'dia' => 120 ),
			'urgent_pregnancy'    => array( 'sys' => 160, 'dia' => 110 ),
			'same_day_pregnancy'  => array( 'sys' => 140, 'dia' => 90 ),
			'low'                 => array( 'sys' => 90, 'dia' => 60 ),
			'pulse'               => array( 40, 120 ),
		),
		'glucose'        => array(
			'impossible'          => array( 1, 35 ),
			'urgent_low'          => 3.0,
			'urgent_high'         => 15.0,
			'urgent_high_symptoms' => 13.9,
			'caution_low'         => array( 3.0, 3.9 ),
			'caution_high'        => array( 11.1, 14.9 ),
			'mg_per_mmol'         => 18.016,
		),
		'weight'         => array( 'impossible' => array( 25, 350 ), 'check_change_pct' => 10, 'check_hours' => 24, 'fall_7_days_pct' => 2, 'fall_28_days_pct' => 5, 'bmi_under' => 18.5 ),
		'waist'          => array( 'impossible' => array( 40, 250 ) ),
		'sleep'          => array( 'impossible' => array( 0, 16 ), 'short_average_7_nights' => 5 ),
		'mood'           => array( 'impossible' => array( 1, 5 ), 'low' => 2, 'consecutive_days' => 3 ),
		'activity'       => array( 'impossible' => array( 1, 600 ), 'steps_max' => 100000, 'vigorous_day_check' => 300 ),
		'water'          => array( 'impossible' => array( 50, 5000 ), 'day_caution_ml' => 5000 ),
	);

	/** Which symptoms turn a glucose of 13.9 mmol/L or more into the urgent level (D3-39). */
	const GLUCOSE_SYMPTOMS = array( 'vomiting', 'drowsiness', 'deep_breathing', 'stomach_pain' );

	public static function boot() {
		// The matrix reaches the programming interface through two filters in LLD_Members_Stage3_API; both check
		// the member's switches and situations on every call, so a profile change applies at once.
		add_filter( 'lld_members_stage3_api_refuse', array( __CLASS__, 'api_refuse' ), 10, 3 );
		add_filter( 'lld_members_stage3_api_rows', array( __CLASS__, 'api_rows' ), 10, 3 );
	}

	/**
	 * Part B: what each situation hides, refuses or annotates in each Stage 3 feature. Keys name the item; the
	 * screens, the engine and the interface look their items up here. A situation not listed for a feature changes
	 * nothing ("—"). The Stage 2 columns (content tags, badges, tracks) stay in LLD_Members_Safety.
	 *
	 * @return array<string,array<string,array{hidden?:string[],refused?:string[],notes?:string[]}>>
	 */
	public static function rows() {
		return array(
			'unknown'             => array(
				'goals'           => array( 'refused' => array( 'goal' ), 'notes' => array( 'general_wellbeing_path' ) ),
				'food_tracker'    => array( 'hidden' => array( 'targets', 'stored_entries' ), 'notes' => array( 'simple_meal_log' ) ),
				'health_logging'  => array( 'refused' => array( 'store' ), 'notes' => array( 'consent_asked_first' ) ),
				'insights'        => array( 'refused' => array( 'insights', 'today_step_from_logs' ), 'notes' => array( 'generic_today_step_from_track' ) ),
				'calculators_new' => array( 'hidden' => array( 'save' ), 'notes' => array( 'one_off_results' ) ),
				'reminders'       => array( 'hidden' => array( 'saved_results', 'share_health_numbers' ) ),
			),
			'eating_disorder'     => array(
				'goals'           => array( 'refused' => array( 'goal:lose_weight', 'goal:gain_weight', 'suggest:weight', 'plan:weight_loss', 'step:weigh_in' ), 'notes' => array( 'ed.weight-goal-screen' ) ),
				'food_tracker'    => array( 'hidden' => array( 'calories', 'targets', 'left_today', 'portion_questions', 'calorie_recipe_suggestions', 'nutrient_totals', 'weekly_nutrition_numbers', 'recipe_calories', 'barcode_calories' ), 'notes' => array( 'ed.food-tracker.diary-heading', 'ed.food-tracker.calorie-total' ) ),
				'health_logging'  => array( 'hidden' => array( 'weigh_in_prompt', 'weight_trend_insight', 'dashboard_bmi' ) ),
				'insights'        => array( 'refused' => array( 'tag:calorie', 'tag:weight', 'tag:portion', 'rule:R-ADJ-01', 'rule:R-ADJ-02', 'rule:R-STEP-02', 'rule:R-INS-06', 'rule:R-INS-07', 'rule:R-INS-08' ) ),
				'calculators_new' => array( 'refused' => array( 'calorie-target', 'goal-timeline', 'healthy-weight' ), 'hidden' => array( 'heart-risk:weight_what_if', 'metabolic:weight_loss_suggestion', 'fitness-age:waist_lever' ), 'notes' => array( 'findahelpline' ) ),
				'reminders'       => array( 'hidden' => array( 'saved:calorie-target', 'saved:goal-timeline', 'share:weight_tick', 'reminder:weigh_in' ) ),
			),
			'pregnancy'           => array(
				'goals'           => array( 'refused' => array( 'goal:lose_weight' ), 'notes' => array( 'midwife_note', 'bp_goal_midwife_sets_this' ) ),
				'food_tracker'    => array( 'hidden' => array( 'calorie_deficit', 'calorie_target' ), 'notes' => array( 'pregnancy.calorie-target' ) ),
				'health_logging'  => array( 'notes' => array( 'bp_pregnancy_thresholds', 'no_weight_trend_judgement', 'gestational_diabetes_note' ) ),
				'insights'        => array( 'refused' => array( 'tag:weight', 'tag:calorie', 'rule:R-SAFE-01', 'rule:R-INS-01', 'rule:R-INS-02', 'rule:R-INS-08', 'rule:R-ADJ-01', 'rule:R-ADJ-02', 'rule:R-STEP-02' ), 'notes' => array( 'rule:R-SAFE-01p' ) ),
				'calculators_new' => array( 'refused' => array( 'calorie-target', 'goal-timeline', 'heart-risk', 'metabolic', 'fitness-age', 'hr-zones' ), 'notes' => array( 'bp-check:pregnancy_mode', 'talk_test' ) ),
				'reminders'       => array( 'hidden' => array( 'share:weight_tick' ), 'notes' => array( 'saved:from_before_pregnancy_label' ) ),
			),
			'kidney'              => array(
				'food_tracker'    => array( 'hidden' => array( 'protein_target' ), 'notes' => array( 'stage1_kidney_message' ) ),
				'insights'        => array( 'refused' => array( 'rule:R-INS-07' ) ),
				'calculators_new' => array( 'hidden' => array( 'calorie-target:protein_line' ) ),
			),
			'diabetes'            => array(
				'goals'           => array( 'notes' => array( 'check_with_your_doctor', 'medicine_note' ) ),
				'food_tracker'    => array( 'notes' => array( 'insulin_calorie_note' ) ),
				'health_logging'  => array( 'notes' => array( 'glucose_warning_levels', 'before_exercise_note' ) ),
				'insights'        => array( 'refused' => array( 'glucose_interpretation' ), 'notes' => array( 'before_exercise_note' ) ),
				'calculators_new' => array( 'refused' => array( 'diabetes-risk' ), 'notes' => array( 'calorie-target:medicine_note', 'metabolic:glucose_criterion_met', 'heart-risk:diabetes_prefilled' ) ),
			),
			'prediabetes'         => array(
				'food_tracker'    => array( 'notes' => array( 'blood_sugar_friendly_first' ) ),
			),
			'blood_pressure'      => array(
				'goals'           => array( 'notes' => array( 'bp_goal_default_135_85', 'check_with_your_doctor', 'high_intensity_note' ) ),
				'health_logging'  => array( 'notes' => array( 'on_medicine_wording' ) ),
				'insights'        => array( 'notes' => array( 'high_intensity_note' ) ),
				'calculators_new' => array( 'notes' => array( 'hr-zones:note' ) ),
			),
			'cholesterol'         => array(),
			'heart_rate_medicine' => array(
				'goals'           => array( 'notes' => array( 'activity_goals_by_minutes' ) ),
				'health_logging'  => array( 'hidden' => array( 'pulse_judgement' ) ),
				'insights'        => array( 'refused' => array( 'tag:heart_rate' ) ),
				'calculators_new' => array( 'refused' => array( 'hr-zones', 'fitness-age' ), 'notes' => array( 'talk_test' ) ),
			),
			'heart_disease'       => array(
				'goals'           => array( 'notes' => array( 'ask_cardiac_rehab_team' ) ),
				'insights'        => array( 'refused' => array( 'tag:push_harder', 'step:high_intensity' ), 'notes' => array( 'moderate_wording_only' ) ),
				'calculators_new' => array( 'refused' => array( 'heart-risk', 'fitness-age', 'hr-zones' ) ),
			),
			'age_65_plus'         => array(
				'goals'           => array( 'notes' => array( 'rate_cap_0_5', 'muscle_note' ) ),
				'food_tracker'    => array( 'notes' => array( 'protein_from_stage1_tool' ) ),
				'insights'        => array( 'notes' => array( 'rate_cap_0_5' ) ),
				'calculators_new' => array( 'notes' => array( 'rates_limited', 'bp-check:80_plus_note' ) ),
			),
			'bmi_under_18_5'      => array(
				'goals'           => array( 'refused' => array( 'goal:lose_weight' ), 'notes' => array( 'maintain_or_gain_only', 'underweight_wording_once' ) ),
				'food_tracker'    => array( 'hidden' => array( 'calorie_deficit' ) ),
				'health_logging'  => array( 'notes' => array( 'underweight_wording_once' ) ),
				'insights'        => array( 'refused' => array( 'tag:weight_loss', 'rule:R-ADJ-02', 'rule:R-INS-08' ), 'notes' => array( 'rule:R-SAFE-03' ) ),
				'calculators_new' => array( 'refused' => array( 'goal-timeline' ), 'notes' => array( 'calorie-target:maintenance_or_gain_only' ) ),
			),
			'low_mood'            => array(
				'health_logging'  => array( 'notes' => array( 'low-mood-signal' ) ),
				'insights'        => array( 'refused' => array( 'all_non_safety_insights_7_days' ), 'notes' => array( 'rule:R-SAFE-04' ) ),
				'calculators_new' => array( 'notes' => array( 'sleep-stress:pointed_to' ) ),
				'reminders'       => array( 'hidden' => array( 'share_prompt_this_week' ) ),
			),
		);
	}

	/**
	 * Part C: the warning level of one reading, with the owner's wording key. Pure: the logging package calls it at
	 * save time and when a reading is edited (D2), the calculators on any blood-pressure input (D5).
	 *
	 * @param string $reading_type blood_pressure, glucose, weight, waist, sleep, mood, activity or water.
	 * @param array  $values       value_1, value_2, value_3 (or sys, dia, pulse; mmol; kg; hours; mood; minutes, steps,
	 *                             effort; ml), unit (mg/dL for glucose), previous (kg, hours_ago) for weight,
	 *                             day_total_ml for water, vigorous_minutes_day for activity, avg_7_nights for sleep.
	 * @param array  $flags        Situations in force (pregnancy, bmi_under_18_5, lose_weight_goal) and glucose
	 *                             symptoms, self_harm.
	 * @return array{level:string,key:?string,message:?string,emergency_numbers:bool}
	 *               level: none, caution, check, urgent or impossible. key: a MESSAGES key, 'global_urgent' (the
	 *               frozen Stage 2 urgent-care wording, FR3-46), 'rule.R-SAFE-01p' (the catalogue's pregnancy line),
	 *               'stage2_response_1' (self-harm), 'healthy_weight_check.underweight', or 'check_the_number'.
	 */
	public static function warning_level( $reading_type, array $values, array $flags = array() ) {
		$v1 = self::num( $values, array( 'value_1', 'sys', 'mmol', 'kg', 'hours', 'mood', 'minutes', 'ml', 'cm' ) );
		$v2 = self::num( $values, array( 'value_2', 'dia', 'steps' ) );
		$v3 = self::num( $values, array( 'value_3', 'pulse', 'effort' ) );
		$L  = isset( self::LEVELS[ $reading_type ] ) ? self::LEVELS[ $reading_type ] : null;
		if ( ! $L || null === $v1 ) {
			return self::level( 'impossible', 'check_the_number' );
		}
		$pregnant = self::flag( $flags, 'pregnancy' );
		switch ( $reading_type ) {
			case 'blood_pressure':
				if ( null === $v2 || $v1 < $L['impossible']['sys'][0] || $v1 > $L['impossible']['sys'][1] || $v2 < $L['impossible']['dia'][0] || $v2 > $L['impossible']['dia'][1] || $v2 > $v1 - $L['impossible']['gap'] ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				$u = $pregnant ? $L['urgent_pregnancy'] : $L['urgent'];
				if ( $v1 >= $u['sys'] || $v2 >= $u['dia'] ) {
					return self::level( 'urgent', 'global_urgent' );
				}
				if ( $pregnant && ( $v1 >= $L['same_day_pregnancy']['sys'] || $v2 >= $L['same_day_pregnancy']['dia'] ) ) {
					return self::level( 'caution', 'rule.R-SAFE-01p' );
				}
				if ( $v1 < $L['low']['sys'] || $v2 < $L['low']['dia'] ) {
					return self::level( 'caution', 'blood_pressure.low' );
				}
				if ( null !== $v3 && ( $v3 < $L['pulse'][0] || $v3 > $L['pulse'][1] ) ) {
					return self::level( 'caution', 'blood_pressure.pulse' );
				}
				return self::level( 'none', null );

			case 'glucose':
				$mmol = isset( $values['unit'] ) && 'mg/dL' === $values['unit'] ? round( $v1 / $L['mg_per_mmol'], 1 ) : $v1;
				if ( $mmol < $L['impossible'][0] || $mmol > $L['impossible'][1] ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				if ( $mmol < $L['urgent_low'] ) {
					return self::level( 'urgent', 'glucose.urgent-low', true );
				}
				$symptoms = isset( $flags['symptoms'] ) && is_array( $flags['symptoms'] ) ? array_intersect( $flags['symptoms'], self::GLUCOSE_SYMPTOMS ) : array();
				if ( $mmol >= $L['urgent_high'] || ( $mmol >= $L['urgent_high_symptoms'] && $symptoms ) ) {
					return self::level( 'urgent', 'glucose.urgent-high', true );
				}
				if ( $mmol <= $L['caution_low'][1] ) {
					return self::level( 'caution', 'glucose.caution-low' );
				}
				if ( $mmol >= $L['caution_high'][0] ) {
					return self::level( 'caution', 'glucose.caution-high' );
				}
				return self::level( 'none', null );

			case 'weight':
				if ( $v1 < $L['impossible'][0] || $v1 > $L['impossible'][1] ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				if ( isset( $values['previous']['kg'], $values['previous']['hours_ago'] ) && (float) $values['previous']['kg'] > 0 && (float) $values['previous']['hours_ago'] <= $L['check_hours'] ) {
					$change = abs( $v1 - (float) $values['previous']['kg'] ) / (float) $values['previous']['kg'] * 100;
					if ( $change > $L['check_change_pct'] ) {
						return self::level( 'check', 'check_the_number' ); // D3-40: asked, not refused
					}
				}
				if ( self::flag( $flags, 'bmi_under_18_5' ) ) {
					return self::level( 'caution', 'healthy_weight_check.underweight' );
				}
				return self::level( 'none', null ); // the fall-without-a-goal caution is the engine's R-SAFE-03, run at save

			case 'waist':
				return $v1 < $L['impossible'][0] || $v1 > $L['impossible'][1] ? self::level( 'impossible', 'check_the_number' ) : self::level( 'none', null );

			case 'sleep':
				if ( $v1 < $L['impossible'][0] || $v1 > $L['impossible'][1] ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				if ( isset( $values['avg_7_nights'] ) && null !== $values['avg_7_nights'] && (float) $values['avg_7_nights'] < $L['short_average_7_nights'] ) {
					return self::level( 'caution', 'sleep.short_average' );
				}
				return self::level( 'none', null );

			case 'mood':
				if ( $v1 < $L['impossible'][0] || $v1 > $L['impossible'][1] ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				if ( self::flag( $flags, 'self_harm' ) ) {
					return self::level( 'urgent', 'stage2_response_1' );
				}
				return self::level( 'none', null ); // three low days in a row is the engine's R-SAFE-04, run at save

			case 'activity':
				if ( $v1 < $L['impossible'][0] || $v1 > $L['impossible'][1] || ( null !== $v2 && $v2 > $L['steps_max'] ) ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				if ( isset( $values['vigorous_minutes_day'] ) && (float) $values['vigorous_minutes_day'] > $L['vigorous_day_check'] ) {
					return self::level( 'check', 'check_the_number' );
				}
				return self::level( 'none', null );

			case 'water':
				if ( $v1 < $L['impossible'][0] || $v1 > $L['impossible'][1] ) {
					return self::level( 'impossible', 'check_the_number' );
				}
				if ( isset( $values['day_total_ml'] ) && (float) $values['day_total_ml'] > $L['day_caution_ml'] ) {
					return self::level( 'caution', 'water.caution' );
				}
				return self::level( 'none', null );
		}
		return self::level( 'impossible', 'check_the_number' );
	}

	/**
	 * The situations in force for a member right now, from the Stage 2 profile (through LLD_Members_Safety), the
	 * two new conditions, the member's age, the latest height and weight, and the low-mood signal. Never cached.
	 * Packages add what they know through the filter (for example the logging package's latest weight).
	 *
	 * @param int   $member_id Member number.
	 * @param array $known     Optional: latest_weight_kg, height_cm, low_mood (bool), to save a lookup.
	 * @return string[] Keys from SITUATIONS.
	 */
	public static function situations( $member_id, array $known = array() ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$out       = array();
		$stage2    = class_exists( 'LLD_Members_Safety' ) ? LLD_Members_Safety::situations( $member_id ) : array( 'unknown' );
		$map       = array( 'eating-disorder' => 'eating_disorder', 'blood-pressure' => 'blood_pressure' );
		foreach ( $stage2 as $key ) {
			if ( 'none' === $key || 'unknown' === $key ) {
				continue;
			}
			$out[] = isset( $map[ $key ] ) ? $map[ $key ] : str_replace( '-', '_', $key );
		}
		// "Unknown" is decided from the profile itself (no health consent, no profile row, or conditions not answered),
		// not from the Stage 2 list being empty: a member whose only conditions are the two new questions is known.
		$consent = ! class_exists( 'LLD_Members_Account' ) || LLD_Members_Account::has_consent( $member_id, 'health' );
		$row     = $consent ? $wpdb->get_row( $wpdb->prepare( 'SELECT p.conditions, p.conditions_answered, p.height_cm, p.weight_kg, m.age FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' p JOIN ' . LLD_Members_Schema::table( 'member' ) . ' m ON m.id = p.member_id WHERE p.member_id = %d', $member_id ) ) : null;
		if ( ! $row || 'not_answered' === (string) $row->conditions_answered ) {
			$out = array( 'unknown' ); // as Stage 2: an unknown profile is unknown and nothing else
		}
		if ( ! in_array( 'unknown', $out, true ) ) {
			$conditions = json_decode( (string) $row->conditions, true );
			$conditions = is_array( $conditions ) ? array_map( 'strval', $conditions ) : array();
			foreach ( self::PROFILE_QUESTIONS as $key => $q ) {
				foreach ( $q['applies_on'] as $answer ) {
					if ( in_array( 'yes' === $answer ? $key : $key . ':' . $answer, $conditions, true ) ) {
						$out[] = $q['situation'];
						break;
					}
				}
			}
			if ( $row && (int) $row->age >= 65 ) {
				$out[] = 'age_65_plus';
			}
			$height = isset( $known['height_cm'] ) ? (float) $known['height_cm'] : ( $row ? (float) $row->height_cm : 0.0 );
			$weight = isset( $known['latest_weight_kg'] ) ? (float) $known['latest_weight_kg'] : ( $row ? (float) $row->weight_kg : 0.0 );
			$weight = (float) apply_filters( 'lld_members_stage3_latest_weight', $weight, $member_id );
			if ( $height > 0 && $weight > 0 && $weight / ( ( $height / 100 ) * ( $height / 100 ) ) < self::LEVELS['weight']['bmi_under'] ) {
				$out[] = 'bmi_under_18_5';
			}
			$low = isset( $known['low_mood'] ) ? (bool) $known['low_mood'] : ( class_exists( 'LLD_Members_Insights_Runner' ) && LLD_Members_Insights_Runner::low_mood_active( $member_id ) );
			if ( apply_filters( 'lld_members_stage3_low_mood', $low, $member_id ) ) {
				$out[] = 'low_mood';
			}
		}
		$out = array_values( array_unique( apply_filters( 'lld_members_stage3_safety_situations', $out, $member_id ) ) );
		return array_values( array_intersect( self::SITUATIONS, $out ) );
	}

	/**
	 * Whether the Stage 3 matrix is live for this member in this feature: the feature's own switch, or the
	 * health_logging switch, must be on for them. Otherwise everything answers "off" and Stage 2 stands.
	 *
	 * @param int    $member_id Member number.
	 * @param string $feature   A FEATURES key.
	 */
	public static function active( $member_id, $feature ) {
		if ( ! class_exists( 'LLD_Members_Features' ) ) {
			return false;
		}
		return LLD_Members_Features::enabled( 'health_logging', $member_id ) || ( in_array( $feature, self::FEATURES, true ) && LLD_Members_Features::enabled( $feature, $member_id ) );
	}

	/**
	 * What the matrix does to one feature for one member: the union of every situation's row (more than one
	 * condition: everything excluded by any of them, notes shown together). The calculators, logging, food tracker
	 * and engine call this before showing anything.
	 *
	 * @param int    $member_id Member number.
	 * @param string $feature   A FEATURES key.
	 * @param array  $context   Optional: situations (to reuse a fresh list), latest_weight_kg, height_cm.
	 * @return array{active:bool,situations:string[],hidden:string[],refused:string[],notes:string[]}
	 */
	public static function applies( $member_id, $feature, array $context = array() ) {
		$off = array( 'active' => false, 'situations' => array(), 'hidden' => array(), 'refused' => array(), 'notes' => array() );
		if ( ! self::active( $member_id, $feature ) ) {
			return $off;
		}
		$situations = isset( $context['situations'] ) && is_array( $context['situations'] ) ? $context['situations'] : self::situations( $member_id, $context );
		return self::combine( $situations, $feature );
	}

	/**
	 * The rows combined for a list of situations, without any member lookup (pure; the engine and the tests use it).
	 *
	 * @param string[] $situations Keys from SITUATIONS.
	 * @param string   $feature    A FEATURES key.
	 * @return array{active:bool,situations:string[],hidden:string[],refused:string[],notes:string[]}
	 */
	public static function combine( array $situations, $feature ) {
		$rows = self::rows();
		$out  = array( 'active' => true, 'situations' => array_values( $situations ), 'hidden' => array(), 'refused' => array(), 'notes' => array() );
		foreach ( $situations as $key ) {
			if ( ! isset( $rows[ $key ][ $feature ] ) ) {
				continue;
			}
			foreach ( array( 'hidden', 'refused', 'notes' ) as $part ) {
				if ( isset( $rows[ $key ][ $feature ][ $part ] ) ) {
					$out[ $part ] = array_values( array_unique( array_merge( $out[ $part ], $rows[ $key ][ $feature ][ $part ] ) ) );
				}
			}
		}
		return $out;
	}

	/**
	 * True when one item is hidden or refused for these situations in this feature.
	 *
	 * @param string[] $situations Keys from SITUATIONS.
	 * @param string   $feature    A FEATURES key.
	 * @param string   $item       An item key from rows().
	 */
	public static function forbids( array $situations, $feature, $item ) {
		$c = self::combine( $situations, $feature );
		return in_array( $item, $c['hidden'], true ) || in_array( $item, $c['refused'], true );
	}

	/**
	 * Whether the engine may show a rule to a member with these situations (P3-02 "Safety check" column, D4).
	 *
	 * @param string[] $situations Keys from SITUATIONS.
	 * @param string   $rule_name  R-… name.
	 */
	public static function allows_rule( array $situations, $rule_name ) {
		$rule = class_exists( 'LLD_Members_Rule_Catalogue' ) ? LLD_Members_Rule_Catalogue::rule( $rule_name ) : null;
		if ( ! $rule ) {
			return false;
		}
		if ( array_intersect( $rule['silenced_by'], $situations ) ) {
			return false;
		}
		// The "Unknown" row (no consent, no profile, conditions not answered): no insights, no Today step from logs,
		// no adjustments; the safety rules still run (Part B, row 1).
		if ( in_array( 'unknown', $situations, true ) && LLD_Members_Rule_Catalogue::CLASS_SAFETY !== $rule['class'] ) {
			return false;
		}
		if ( self::forbids( $situations, 'insights', 'rule:' . $rule_name ) ) {
			return false;
		}
		foreach ( $rule['tags'] as $tag ) {
			if ( self::forbids( $situations, 'insights', 'tag:' . $tag ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Interface refusals (FR3-69 AS-02): calorie data for an eating-disorder member, a protein target for kidney
	 * disease. Only when the matrix is live for the member.
	 *
	 * @param mixed  $refusal   Null, or an earlier filter's WP_REST_Response.
	 * @param string $type      Interface type name.
	 * @param int    $member_id Member number.
	 */
	public static function api_refuse( $refusal, $type, $member_id ) {
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( ! in_array( $type, array( 'targets', 'food-log', 'recipes', 'custom-foods', 'plans' ), true ) ) {
			return null;
		}
		$feature = 'food_tracker'; // a plan's calorie and protein targets are food-tracker targets (Part B column 2)
		$a = self::applies( $member_id, $feature );
		if ( ! $a['active'] ) {
			return null;
		}
		if ( 'targets' === $type && in_array( 'targets', $a['hidden'], true ) ) {
			return new WP_REST_Response( array( 'message' => 'Not available.' ), 403 );
		}
		return null;
	}

	/**
	 * Strip calorie and nutrient fields from interface rows for an eating-disorder member, and the protein target
	 * for kidney disease, so no screen and no app can show them (FR3-69 AS-01 to AS-03).
	 *
	 * @param array  $rows      Opened rows.
	 * @param string $type      Interface type name.
	 * @param int    $member_id Member number.
	 * @return array
	 */
	public static function api_rows( $rows, $type, $member_id ) {
		if ( ! is_array( $rows ) || ! $rows ) {
			return $rows;
		}
		if ( ! in_array( $type, array( 'food-log', 'recipes', 'custom-foods', 'targets', 'plans' ), true ) ) {
			return $rows;
		}
		$feature = 'food_tracker'; // a plan's calorie and protein targets are food-tracker targets (Part B column 2)
		$a = self::applies( $member_id, $feature );
		if ( ! $a['active'] ) {
			return $rows;
		}
		$strip = array();
		if ( in_array( 'calories', $a['hidden'], true ) || in_array( 'calorie_target', $a['hidden'], true ) ) {
			$strip = array_merge( $strip, array( 'kcal', 'kcal_per_serving', 'calorie_target', 'protein_g', 'carb_g', 'fat_g', 'fibre_g', 'protein_per_serving', 'carb_per_serving', 'fat_per_serving', 'fibre_per_serving' ) );
		}
		if ( in_array( 'protein_target', $a['hidden'], true ) ) {
			$strip[] = 'protein_g';
		}
		if ( ! $strip ) {
			return $rows;
		}
		$single = isset( $rows['id'] ) && ! isset( $rows[0] );
		$list   = $single ? array( $rows ) : $rows;
		foreach ( $list as $i => $row ) {
			foreach ( $strip as $field ) {
				if ( is_array( $row ) && array_key_exists( $field, $row ) ) {
					$list[ $i ][ $field ] = null;
				}
			}
		}
		return $single ? $list[0] : $list;
	}

	// ---- Helpers -------------------------------------------------------------------------------------------------

	private static function level( $level, $key, $emergency = false ) {
		return array(
			'level'             => $level,
			'key'               => $key,
			'message'           => null !== $key && isset( self::MESSAGES[ $key ] ) ? self::MESSAGES[ $key ] : null,
			'emergency_numbers' => (bool) $emergency,
		);
	}

	private static function num( array $values, array $names ) {
		foreach ( $names as $n ) {
			if ( isset( $values[ $n ] ) && is_numeric( $values[ $n ] ) ) {
				return (float) $values[ $n ];
			}
		}
		return null;
	}

	private static function flag( array $flags, $name ) {
		if ( isset( $flags[ $name ] ) ) {
			return (bool) $flags[ $name ];
		}
		return in_array( $name, $flags, true );
	}
}
