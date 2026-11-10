<?php
/**
 * Goal journey data (P3-31 to P3-33; FR3-09 to FR3-16, FR3-18): goals, baselines, plans, Today steps, check-ins and
 * adjustments, read and written through LLD_Members_Stage3_Data (sealed health values, scoped to the member).
 * The arithmetic is LLD_Members_Goal_Rules; the screens in stage3/screens/ show what this class answers.
 *
 * Order on every answer (Build Method §3.5): the feature switch, the safety matrix, access, then the data.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Goals, plans and the member's daily state.
 */
class LLD_Members_Goal_Journey {

	const SEALED_GOAL    = array( 'start_value', 'target_value', 'start_value_2', 'target_value_2' );
	const SEALED_READING = array( 'value_1', 'value_2', 'value_3', 'detail' );
	const OPTION_REWRITE = 'lld_members_stage3_goal_rewrites';
	const REWRITE_VERSION = '1';
	/** Default horizon for goals without a rate (blood pressure, blood sugar, activity, sleep, strength). */
	const DEFAULT_WEEKS = 12;
	/** A glass of water on the quick-log tile, ml. */
	const GLASS_ML = 250;

	/** Daily values already read in this request, member:type => date => value; cleared by every reading saved. */
	private static $daily_cache = array();

	/**
	 * Member-facing wording for the safety-matrix note keys of the goals feature (P3-03 Part B). Where an approved
	 * text exists elsewhere (the calorie target sheet, the matrix, the Stage 2 rows) it is reused verbatim.
	 *
	 * @return array<string,string>
	 */
	public static function note_texts() {
		$cal = class_exists( 'LLD_Members_Calorie_Target' ) ? LLD_Members_Calorie_Target::MESSAGES : array();
		$quo = class_exists( 'LLD_Members_Calorie_Target' ) ? LLD_Members_Calorie_Target::QUOTED : array();
		$mx  = class_exists( 'LLD_Members_Stage3_Safety' ) ? LLD_Members_Stage3_Safety::MESSAGES : array();
		return array(
			'general_wellbeing_path'    => "Answer the health questions in your health profile first, and we'll set a goal together. Until then the articles, calculators and tracks are all here for you.",
			'midwife_note'              => 'Talk to your midwife or doctor about weight and exercise during pregnancy.',
			'bp_goal_midwife_sets_this' => 'In pregnancy, your midwife sets any blood pressure target.',
			'ed.weight-goal-screen'     => isset( $mx['ed.weight-goal-screen'] ) ? $mx['ed.weight-goal-screen'] : '',
			'check_with_your_doctor'    => LLD_Members_Goal_Rules::MESSAGES['check_doctor'],
			'medicine_note'             => isset( $cal['diabetes_note'] ) ? $cal['diabetes_note'] : 'If you take diabetes medicine, check with your diabetes team before changing what you eat or how much you move.',
			'bp_goal_default_135_85'    => LLD_Members_Goal_Rules::MESSAGES['bp_default'],
			'high_intensity_note'       => 'Build activity up gradually and keep hard efforts for when your blood pressure is under control.',
			'activity_goals_by_minutes' => "Your activity goal is set in minutes, not heart rate, because of the medicine you take.",
			'ask_cardiac_rehab_team'    => isset( $mx['profile.known-heart-disease.effect-note'] ) ? $mx['profile.known-heart-disease.effect-note'] : '',
			'rate_cap_0_5'              => 'After 65 the safe rate of weight loss is 0.5 kg a week at most.',
			'muscle_note'               => isset( $cal['over65_note'] ) ? $cal['over65_note'] : '',
			'maintain_or_gain_only'     => "Your BMI is below the healthy range, so weight-loss goals aren't offered. You can keep your weight steady or choose another goal.",
			'underweight_wording_once'  => isset( $quo['underweight'] ) ? $quo['underweight'] : '',
		);
	}

	/**
	 * Called by the Stage 3 loader. Hooks nothing while every goal-journey switch is off for everyone (NFR3-06).
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) ) {
			return;
		}
		$any = false;
		foreach ( array( 'goals', 'today', 'progress' ) as $feature ) {
			if ( 'off' !== LLD_Members_Features::state( $feature ) ) {
				$any = true;
			}
		}
		if ( ! $any ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_filter( 'lld_members_stage3_check_ins', array( __CLASS__, 'filter_check_ins' ), 10, 3 );
		add_filter( 'lld_members_stage3_last_check_in', array( __CLASS__, 'filter_last_check_in' ), 10, 3 );
		add_filter( 'lld_members_stage3_check_in_done_this_week', array( __CLASS__, 'filter_check_in_this_week' ), 10, 3 );
		add_filter( 'lld_members_stage3_latest_weight', array( __CLASS__, 'filter_latest_weight' ), 10, 2 );
	}

	/**
	 * The two new member addresses. Registered here, not in the Stage 2 plugin class; flushed once per version.
	 */
	public static function rewrites() {
		add_rewrite_rule( '^members/goal/?$', 'index.php?lld_screen=goal', 'top' );
		add_rewrite_rule( '^members/progress/?$', 'index.php?lld_screen=progress', 'top' );
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VERSION, true );
		}
	}

	// ---- Facts about the member -------------------------------------------------------------------------------------

	/**
	 * What the rules need to know: age, units, profile measurements, situations, consent, Premium, today.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	public static function facts( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$member    = $wpdb->get_row( $wpdb->prepare( 'SELECT age, units, timezone, first_name, display_name FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		$health    = class_exists( 'LLD_Members_Account' ) && LLD_Members_Account::has_consent( $member_id, 'health' );
		$profile   = $health ? $wpdb->get_row( $wpdb->prepare( 'SELECT sex, height_cm, weight_kg, waist_cm, activity_level, conditions_answered FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) ) : null;
		$today     = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::local_today( $member_id ) : gmdate( 'Y-m-d' );
		$weight    = $health ? self::latest_value( $member_id, 'weight' ) : null;
		if ( null === $weight && $profile && is_numeric( $profile->weight_kg ) && (float) $profile->weight_kg > 0 ) {
			$weight = (float) $profile->weight_kg;
		}
		$height = $profile && is_numeric( $profile->height_cm ) && (float) $profile->height_cm > 0 ? (float) $profile->height_cm : null;
		$known  = array();
		if ( null !== $weight ) {
			$known['latest_weight_kg'] = $weight;
		}
		$situations = $health && class_exists( 'LLD_Members_Stage3_Safety' ) ? LLD_Members_Stage3_Safety::situations( $member_id, $known ) : array( 'unknown' );
		$stage2     = $health ? $wpdb->get_col( $wpdb->prepare( 'SELECT goal_key FROM ' . LLD_Members_Schema::table( 'goal_choice' ) . ' WHERE member_id = %d ORDER BY sort_order ASC', $member_id ) ) : array();
		return array(
			'member_id'    => $member_id,
			'name'         => $member ? ( '' !== (string) $member->display_name ? (string) $member->display_name : (string) $member->first_name ) : '',
			'age'          => $member ? (int) $member->age : 0,
			'imperial'     => $member && 'imperial' === (string) $member->units,
			'timezone'     => $member ? (string) $member->timezone : 'UTC',
			'today'        => $today,
			'health'       => $health,
			'premium'      => class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::has_premium( $member_id ),
			'sex'          => $profile ? (string) $profile->sex : '',
			'height_cm'    => $height,
			'weight_kg'    => $weight,
			'waist_cm'     => $profile && is_numeric( $profile->waist_cm ) ? (float) $profile->waist_cm : null,
			'activity'     => $profile ? (string) $profile->activity_level : '',
			'bmi'          => $height && $weight ? LLD_Members_Goal_Rules::round1( $weight / ( ( $height / 100 ) * ( $height / 100 ) ) ) : null,
			'situations'   => $situations,
			'stage2_goals' => is_array( $stage2 ) ? array_map( 'strval', $stage2 ) : array(),
		);
	}

	// ---- Goals --------------------------------------------------------------------------------------------------------

	/**
	 * Every goal of the member, newest first, values opened.
	 *
	 * @param int $member_id Member number.
	 * @return array<int,array<string,mixed>>
	 */
	public static function goals( $member_id ) {
		$out = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'goals', $member_id, self::SEALED_GOAL, '', 50 ) as $g ) {
			if ( empty( $g['deleted_at'] ) ) {
				$out[] = $g;
			}
		}
		return $out;
	}

	/**
	 * The active goal of one role, or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $role      main or extra.
	 */
	public static function active_goal( $member_id, $role = 'main' ) {
		foreach ( self::goals( $member_id ) as $g ) {
			if ( 'active' === (string) $g['status'] && $role === (string) $g['role'] ) {
				return $g;
			}
		}
		return null;
	}

	/**
	 * The newest main goal whatever its status (for the Goal reached screen), or null.
	 *
	 * @param int $member_id Member number.
	 */
	public static function latest_main_goal( $member_id ) {
		foreach ( self::goals( $member_id ) as $g ) {
			if ( 'main' === (string) $g['role'] ) {
				return $g;
			}
		}
		return null;
	}

	/**
	 * Whether the member must set a main goal before the dashboard (FR3-09 AS-01): health consent given, the
	 * matrix allows a goal, and no main goal exists in any state.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	public static function needs_goal( $member_id, array $facts ) {
		if ( empty( $facts['health'] ) || in_array( 'unknown', $facts['situations'], true ) ) {
			return false;
		}
		return null === self::latest_main_goal( $member_id );
	}

	/**
	 * Create a goal (FR3-09, FR3-10, FR3-11) from the screen's cleaned input.
	 *
	 * @param int    $member_id Member number.
	 * @param string $role      main or extra.
	 * @param string $type      A LLD_Members_Goal_Rules::types() key.
	 * @param array  $in        current (number), target (number), target_2 (diastolic), rate, target_date, source
	 *                          (member|doctor|guideline_default), height_cm, waist_cm, facts (facts()).
	 * @return int|WP_Error The new goal id. The error's data carries the rules' answer (code, offered).
	 */
	public static function create_goal( $member_id, $role, $type, array $in ) {
		$facts = isset( $in['facts'] ) && is_array( $in['facts'] ) ? $in['facts'] : self::facts( $member_id );
		$types = LLD_Members_Goal_Rules::types();
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'lld_goal', 'Choose a goal.' );
		}
		if ( empty( $facts['health'] ) ) {
			return new WP_Error( 'lld_goal', 'Health consent is needed before a goal can be saved.' );
		}
		$allowed = LLD_Members_Goal_Rules::allowed_types( $facts['situations'] );
		if ( ! isset( $allowed[ $type ] ) ) {
			return new WP_Error( 'lld_goal_refused', in_array( 'unknown', $facts['situations'], true ) ? self::note_texts()['general_wellbeing_path'] : 'That goal is not offered, based on your health profile.' );
		}
		$main  = self::active_goal( $member_id, 'main' );
		$extra = self::active_goal( $member_id, 'extra' );
		if ( 'main' === $role && $main ) {
			return new WP_Error( 'lld_goal', 'You already have a main goal.' );
		}
		if ( 'extra' === $role && ( ! $main || $extra ) ) {
			return new WP_Error( 'lld_goal_third', LLD_Members_Goal_Rules::MESSAGES['third_goal'] );
		}
		$def    = $types[ $type ];
		$today  = (string) $facts['today'];
		$row    = array(
			'role'          => $role,
			'goal_type'     => $type,
			'measure'       => $def['measure'],
			'unit'          => $def['unit'],
			'target_source' => 'member',
			'status'        => 'active',
		);
		$check  = null;
		$number = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (float) $in[ $key ] : null;
		};
		$date = isset( $in['target_date'] ) && preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $in['target_date'] ) ? (string) $in['target_date'] : null;
		switch ( $type ) {
			case 'lose_weight':
			case 'keep_weight':
				$height = $number( 'height_cm' );
				if ( null === $height ) {
					$height = $facts['height_cm'];
				}
				$current = $number( 'current' );
				if ( null === $current ) {
					$current = $facts['weight_kg'];
				}
				$goal_kg = 'keep_weight' === $type ? $current : $number( 'target' );
				$check   = LLD_Members_Goal_Rules::weight_check(
					array(
						'current_kg'  => $current,
						'goal_kg'     => $goal_kg,
						'height_cm'   => $height,
						'rate'        => $number( 'rate' ),
						'target_date' => $date,
						'today'       => $today,
						'situations'  => $facts['situations'],
						'imperial'    => ! empty( $facts['imperial'] ),
					)
				);
				if ( ! $check['ok'] ) {
					return new WP_Error( 'lld_goal_refused', $check['message'], $check );
				}
				$row['start_value']  = $current;
				$row['target_value'] = $goal_kg;
				$row['target_date']  = $check['target_date'];
				break;
			case 'lower_blood_pressure':
				// Both numbers (owner decision Q79 = B): the doctor's top and bottom targets, or the guideline 135/85.
				$sys = $number( 'target' );
				$dia = $number( 'target_2' );
				$src = isset( $in['source'] ) && 'doctor' === $in['source'] ? 'doctor' : 'guideline_default';
				if ( 'doctor' === $src && null !== $sys && null === $dia ) {
					return new WP_Error( 'lld_goal', "Enter both numbers of your doctor's target." );
				}
				if ( 'guideline_default' === $src || null === $sys ) {
					$sys = (float) LLD_Members_Goal_Rules::BP_DEFAULT[0];
					$dia = (float) LLD_Members_Goal_Rules::BP_DEFAULT[1];
					$src = 'guideline_default';
				}
				if ( $sys < 90 || $sys > 200 || $dia < 50 || $dia > 130 || $dia >= $sys ) {
					return new WP_Error( 'lld_goal', 'Check the blood pressure target.' );
				}
				$row['start_value']    = $number( 'current' );
				$row['target_value']   = $sys;
				$row['start_value_2']  = null !== $number( 'current' ) ? $number( 'current_2' ) : null;
				$row['target_value_2'] = $dia;
				$row['target_source'] = $src;
				$row['target_date']   = $date ? $date : LLD_Members_Goal_Rules::add_days( $today, 7 * self::DEFAULT_WEEKS );
				break;
			case 'blood_sugar':
				$target = $number( 'target' );
				if ( null === $target || $target < 3 || $target > 20 ) {
					return new WP_Error( 'lld_goal', 'Enter the fasting glucose target your doctor gave you, in mmol/L.' );
				}
				$row['start_value']   = $number( 'current' );
				$row['target_value']  = $target;
				$row['target_source'] = 'doctor';
				$row['target_date']   = $date ? $date : LLD_Members_Goal_Rules::add_days( $today, 7 * self::DEFAULT_WEEKS );
				break;
			case 'activity':
				$target = $number( 'target' );
				if ( null === $target ) {
					$target = (float) LLD_Members_Goal_Rules::ACTIVITY_DEFAULT;
				}
				if ( $target < 30 || $target > 2100 ) {
					return new WP_Error( 'lld_goal', 'Choose between 30 and 2,100 minutes a week.' );
				}
				$row['start_value']  = $number( 'current' );
				$row['target_value'] = $target;
				$row['target_date']  = $date ? $date : LLD_Members_Goal_Rules::add_days( $today, 7 * 8 );
				break;
			case 'strength':
				$target = $number( 'target' );
				if ( null === $target || $target < 1 || $target > 500 ) {
					return new WP_Error( 'lld_goal', 'Choose a number of push-ups between 1 and 500.' );
				}
				$row['start_value']  = $number( 'current' );
				$row['target_value'] = $target;
				$row['target_date']  = $date ? $date : LLD_Members_Goal_Rules::add_days( $today, 7 * 8 );
				break;
			case 'sleep':
				$target = $number( 'target' );
				if ( null === $target ) {
					$target = 7.5;
				}
				if ( $target < 5 || $target > 12 ) {
					return new WP_Error( 'lld_goal', 'Choose between 5 and 12 hours.' );
				}
				$row['start_value']  = $number( 'current' );
				$row['target_value'] = $target;
				$row['target_date']  = $date ? $date : LLD_Members_Goal_Rules::add_days( $today, 7 * 8 );
				break;
		}
		if ( ! empty( $row['target_date'] ) && $row['target_date'] <= $today ) {
			return new WP_Error( 'lld_goal', 'Choose a date in the future.' );
		}
		$goal_id = LLD_Members_Stage3_Data::insert( 'goals', $member_id, $row, self::SEALED_GOAL );
		if ( $goal_id < 1 ) {
			return new WP_Error( 'lld_goal', 'Try again.' );
		}
		// Height typed here goes to the health profile, as the Stage 2 check-in does (FR3-10).
		if ( in_array( $type, array( 'lose_weight', 'keep_weight' ), true ) && null !== $number( 'height_cm' ) && null === $facts['height_cm'] && class_exists( 'LLD_Members_Account' ) ) {
			LLD_Members_Account::save_health( $member_id, array( 'kind' => 'profile', 'height_cm' => $number( 'height_cm' ), 'confirmed' => true ) );
		}
		// Starting measurements (FR3-10): each becomes a reading and a baseline line. Any may be skipped.
		$baseline = array();
		if ( in_array( $def['measure'], array( 'weight', 'blood_pressure', 'activity_minutes', 'sleep_hours', 'glucose' ), true ) && null !== $number( 'current' ) ) {
			$reading = array( 'weight' => 'weight', 'blood_pressure' => 'blood_pressure', 'activity_minutes' => 'activity', 'sleep_hours' => 'sleep', 'glucose' => 'glucose' );
			$values  = array( 'value_1' => $number( 'current' ) );
			if ( 'blood_pressure' === $def['measure'] && null !== $number( 'current_2' ) ) {
				$values['value_2'] = $number( 'current_2' );
			}
			$baseline[ $def['measure'] ] = self::save_reading( $member_id, $reading[ $def['measure'] ], $values, isset( $in['unit_shown'] ) ? (string) $in['unit_shown'] : '', 'manual', $facts );
		}
		if ( in_array( 'waist', $def['baseline'], true ) && null !== $number( 'waist_cm' ) ) {
			$baseline['waist'] = self::save_reading( $member_id, 'waist', array( 'value_1' => $number( 'waist_cm' ) ), ! empty( $facts['imperial'] ) ? 'in' : 'cm', 'manual', $facts );
		}
		foreach ( $baseline as $measure => $saved ) {
			if ( ! empty( $saved['id'] ) ) {
				LLD_Members_Stage3_Data::insert( 'goal_baselines', $member_id, array( 'goal_id' => $goal_id, 'measure' => $measure, 'reading_id' => (int) $saved['id'] ) );
			}
		}
		if ( 'main' === $role ) {
			$goal = LLD_Members_Stage3_Data::row( 'goals', $member_id, $goal_id, self::SEALED_GOAL );
			self::build_plan( $member_id, $goal, $facts, $check );
		}
		return $goal_id;
	}

	/**
	 * Add a starting measurement later (FR3-10 AS-03).
	 *
	 * @param int    $member_id Member number.
	 * @param array  $goal      Goal row.
	 * @param string $measure   weight, waist or blood_pressure.
	 * @param array  $values    value_1, value_2.
	 * @param string $unit      Unit shown.
	 * @return array{id:int,level:string}
	 */
	public static function add_baseline( $member_id, array $goal, $measure, array $values, $unit = '' ) {
		$types = array( 'weight' => 'weight', 'waist' => 'waist', 'blood_pressure' => 'blood_pressure' );
		if ( ! isset( $types[ $measure ] ) ) {
			return array( 'id' => 0, 'level' => 'none' );
		}
		$saved = self::save_reading( $member_id, $types[ $measure ], $values, $unit );
		if ( $saved['id'] > 0 ) {
			LLD_Members_Stage3_Data::insert( 'goal_baselines', $member_id, array( 'goal_id' => (int) $goal['id'], 'measure' => $measure, 'reading_id' => $saved['id'] ) );
			if ( (string) $goal['measure'] === $measure && ! is_numeric( $goal['start_value'] ) ) {
				$start = array( 'start_value' => $values['value_1'] );
				if ( 'blood_pressure' === $measure && isset( $values['value_2'] ) && is_numeric( $values['value_2'] ) ) {
					$start['start_value_2'] = $values['value_2'];
				}
				LLD_Members_Stage3_Data::update( 'goals', $member_id, (int) $goal['id'], $start, self::SEALED_GOAL );
			}
		}
		return $saved;
	}

	/**
	 * Save a health reading with the matrix's warning level (P3-03 Part C) and the save-time safety hook.
	 *
	 * @param int    $member_id Member number.
	 * @param string $type      Reading type.
	 * @param array  $values    value_1, value_2, value_3.
	 * @param string $unit      Unit shown.
	 * @param string $source    manual or quick_tile.
	 * @return array{id:int,level:string,message:string}
	 */
	public static function save_reading( $member_id, $type, array $values, $unit = '', $source = 'manual', $facts = null ) {
		$level = array( 'level' => 'none', 'message' => null );
		if ( class_exists( 'LLD_Members_Stage3_Safety' ) && in_array( $type, array( 'blood_pressure', 'weight', 'glucose' ), true ) ) {
			$facts = is_array( $facts ) ? $facts : self::facts( $member_id );
			$got   = LLD_Members_Stage3_Safety::warning_level( $type, $values, array_fill_keys( $facts['situations'], true ) );
			if ( is_array( $got ) ) {
				$level = $got;
			}
			if ( 'impossible' === $level['level'] ) {
				return array( 'id' => 0, 'level' => 'impossible', 'message' => 'Check the number and try again.' );
			}
		}
		$row = array(
			'reading_type' => $type,
			'taken_at'     => lld_members_now_mysql( $member_id ),
			'value_1'      => isset( $values['value_1'] ) ? (float) $values['value_1'] : null,
			'value_2'      => isset( $values['value_2'] ) ? (float) $values['value_2'] : null,
			'value_3'      => isset( $values['value_3'] ) && empty( $level['pulse_left_out'] ) ? (float) $values['value_3'] : null, // D3-50
			'urgent_shown' => 'urgent' === $level['level'] ? 1 : 0,
			'source'       => $source,
		);
		if ( '' !== $unit ) {
			$row['unit_shown'] = $unit;
		}
		$id = LLD_Members_Stage3_Data::insert( 'readings', $member_id, $row, self::SEALED_READING );
		self::$daily_cache = array();
		if ( $id > 0 && class_exists( 'LLD_Members_Insights_Runner' ) ) {
			LLD_Members_Insights_Runner::on_reading_saved( $member_id, array( 'reading_type' => $type ) );
		}
		return array( 'id' => $id, 'level' => (string) $level['level'], 'message' => isset( $level['message'] ) ? (string) $level['message'] : '' );
	}

	// ---- Plans (FR3-11, FR3-14) ---------------------------------------------------------------------------------------

	/**
	 * Build the personal plan for a main goal: the track, the daily targets (from the Daily Calorie Target, P3-55),
	 * the weekly activity target and what to log. Version 1 on creation; the engine and the member add versions.
	 *
	 * @param int        $member_id Member number.
	 * @param array      $goal      Goal row.
	 * @param array      $facts     facts().
	 * @param array|null $check     The weight_check() answer for a weight goal.
	 * @return int Plan id.
	 */
	public static function build_plan( $member_id, array $goal, array $facts, $check = null ) {
		$types = LLD_Members_Goal_Rules::types();
		$type  = (string) $goal['goal_type'];
		$def   = isset( $types[ $type ] ) ? $types[ $type ] : $types['activity'];
		$today = (string) $facts['today'];
		$track = (string) $def['plan_track'];
		if ( class_exists( 'LLD_Members_Programme' ) && ! LLD_Members_Programme::track_exists( $track ) ) {
			$fallback = array( 'keep-it-off' => 'healthy-weight', 'heart-health' => 'blood-sugar' );
			$track    = isset( $fallback[ $track ] ) && LLD_Members_Programme::track_exists( $fallback[ $track ] ) ? $fallback[ $track ] : '';
		}
		$schedule = array(
			'track'            => $track,
			'track_suggested'  => (string) $def['plan_track'],
			'weigh_in_weekday' => strtolower( substr( DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new DateTimeZone( 'UTC' ) )->format( 'D' ), 0, 3 ) ),
			'measures'         => $def['baseline'],
		);
		$row      = array(
			'goal_id'               => (int) $goal['id'],
			'version'               => 1,
			'track_id'              => '' !== $track && class_exists( 'LLD_Members_Programme' ) ? (int) LLD_Members_Programme::track_id( $track ) : null,
			'activity_minutes_week' => LLD_Members_Goal_Rules::ACTIVITY_DEFAULT,
			'valid_from'            => $today,
			'created_by'            => 'engine',
		);
		if ( in_array( $type, array( 'lose_weight', 'keep_weight' ), true ) && is_array( $check ) ) {
			// The rate only: the cap and the 5 % milestone derive from the sealed starting weight, so they are recomputed
			// from the opened goal where needed and never written in plain text (NFR3-10, Build Method §3.6).
			$schedule['rate_kg_week'] = $check['rate'];
			$schedule['weigh_ins']    = 'weekly';
			$targets                  = self::calorie_targets( $facts, 'lose_weight' === $type ? 'lose' : 'maintain', $check['rate'] );
			if ( $targets ) {
				$row = array_merge( $row, $targets );
			}
		}
		if ( 'lower_blood_pressure' === $type ) {
			$schedule['bp_per_week'] = 3;
		}
		$row['log_schedule'] = wp_json_encode( $schedule );
		return LLD_Members_Stage3_Data::insert( 'plans', $member_id, $row );
	}

	/**
	 * Daily calorie and nutrient targets from the signed calculator, or null when it cannot give one (no profile,
	 * a stop, or a matrix row that hides calories).
	 *
	 * @param array      $facts facts().
	 * @param string     $goal  lose or maintain.
	 * @param float|null $rate  kg a week.
	 * @return array<string,int>|null calorie_target, protein_g, carb_g, fat_g, fibre_g.
	 */
	public static function calorie_targets( array $facts, $goal, $rate ) {
		if ( ! class_exists( 'LLD_Members_Calorie_Target' ) || empty( $facts['height_cm'] ) || empty( $facts['weight_kg'] ) || '' === (string) $facts['sex'] ) {
			return null;
		}
		if ( array_intersect( array( 'eating_disorder', 'pregnancy', 'unknown' ), $facts['situations'] ) ) {
			return null;
		}
		// The profile's very-active (and the programme's very_active) is the calculator's "very active" (P3-55, FR3-97).
		$activity = array( 'low' => 'low', 'some' => 'some', 'active' => 'active', 'very-active' => 'very active', 'very_active' => 'very active' );
		$result   = LLD_Members_Calorie_Target::compute(
			array(
				'sex'            => $facts['sex'],
				'age'            => (int) $facts['age'],
				'heightCm'       => (float) $facts['height_cm'],
				'weightKg'       => (float) $facts['weight_kg'],
				'activity'       => isset( $activity[ (string) $facts['activity'] ] ) ? $activity[ (string) $facts['activity'] ] : 'low',
				'goal'           => $goal,
				'rateKgWeek'     => null === $rate ? 0.5 : (float) $rate,
				'pregnant'       => 'no',
				'eatingDisorder' => 'no',
				'type2Diabetes'  => in_array( 'diabetes', $facts['situations'], true ) ? 'yes' : 'no',
				'kidneyDisease'  => in_array( 'kidney', $facts['situations'], true ) ? 'yes' : 'no',
			)
		);
		if ( ! is_array( $result ) || ! empty( $result['error'] ) || null !== $result['stop'] || ! empty( $result['refused'] ) || ! is_numeric( $result['targetKcal'] ) ) {
			return null;
		}
		$protein = in_array( 'kidney', $facts['situations'], true ) ? null : self::protein_target( (int) $facts['member_id'] );
		$macros  = LLD_Members_Calorie_Target::macros( (int) $result['targetKcal'], $protein );
		return array(
			'calorie_target' => (int) $result['targetKcal'],
			'protein_g'      => $macros['proteinG'],
			'carb_g'         => $macros['carbG'],
			'fat_g'          => $macros['fatG'],
			'fibre_g'        => $macros['fibreG'],
		);
	}

	/** The member's Daily Protein Target from a saved Stage 1 result, or null (D3-06: never recomputed). */
	private static function protein_target( $member_id ) {
		global $wpdb;
		$headline = $wpdb->get_var( $wpdb->prepare( 'SELECT headline FROM ' . LLD_Members_Schema::table( 'calculator_result' ) . ' WHERE member_id = %d AND calculator = %s ORDER BY id DESC LIMIT 1', $member_id, 'protein' ) );
		if ( ! is_string( $headline ) || ! preg_match( '/(\d+(?:\.\d+)?)/', $headline, $m ) ) {
			return null;
		}
		return (float) $m[1];
	}

	/**
	 * The current plan version of a goal, or null.
	 *
	 * @param int $member_id Member number.
	 * @param int $goal_id   Goal id.
	 */
	public static function plan( $member_id, $goal_id ) {
		$plan = null;
		foreach ( LLD_Members_Stage3_Data::rows( 'plans', $member_id, array(), '', 100 ) as $p ) {
			if ( (int) $p['goal_id'] === (int) $goal_id && ( null === $plan || (int) $p['version'] > (int) $plan['version'] ) ) {
				$plan = $p;
			}
		}
		if ( $plan ) {
			$schedule         = json_decode( (string) $plan['log_schedule'], true );
			$plan['schedule'] = is_array( $schedule ) ? $schedule : array();
		}
		return $plan;
	}

	/**
	 * The engine's offers waiting for the member's answer (FR3-14 AS-01): each with its approved text and offers.
	 *
	 * @param int $member_id Member number.
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending_adjustments( $member_id ) {
		$out = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'plan_adjustments', $member_id, array(), '', 20 ) as $a ) {
			if ( null !== $a['accepted'] && '' !== (string) $a['accepted'] ) {
				continue;
			}
			$changes = json_decode( (string) $a['changes'], true );
			$changes = is_array( $changes ) ? $changes : array();
			$text    = '';
			if ( ! empty( $changes['insight_id'] ) ) {
				$ins = LLD_Members_Stage3_Data::row( 'insights', $member_id, (int) $changes['insight_id'], array( 'values' ) );
				if ( $ins && ! empty( $changes['template'] ) && class_exists( 'LLD_Members_Rule_Catalogue' ) ) {
					$values = json_decode( (string) $ins['values'], true );
					$text   = (string) LLD_Members_Rule_Catalogue::fill( (string) $changes['template'], is_array( $values ) ? $values : array() );
				}
			}
			$out[] = array( 'id' => (int) $a['id'], 'plan_id' => (int) $a['plan_id'], 'rule' => class_exists( 'LLD_Members_Rule_Catalogue' ) ? LLD_Members_Rule_Catalogue::name_for_id( (int) $a['rule_id'] ) : '', 'offers' => isset( $changes['offers'] ) ? (array) $changes['offers'] : array(), 'text' => $text );
		}
		return $out;
	}

	/**
	 * The member's answer to an offer (FR3-14): the plan changes only when they accept, and only within the safe
	 * limits (AS-02). Adjustments are Premium (AS-03); "keep" is open to everyone.
	 *
	 * @param int    $member_id Member number.
	 * @param int    $adj_id    Adjustment id.
	 * @param string $choice    later_date, easier_activity, different_meal_plan or keep.
	 * @param array  $facts     facts().
	 * @return true|WP_Error
	 */
	public static function answer_adjustment( $member_id, $adj_id, $choice, array $facts ) {
		$adj = LLD_Members_Stage3_Data::row( 'plan_adjustments', $member_id, (int) $adj_id );
		if ( ! $adj || ( null !== $adj['accepted'] && '' !== (string) $adj['accepted'] ) ) {
			return new WP_Error( 'lld_adjust', 'This offer is no longer open.' );
		}
		if ( 'keep' === $choice ) {
			LLD_Members_Stage3_Data::update( 'plan_adjustments', $member_id, (int) $adj['id'], array( 'accepted' => 0 ) );
			return true;
		}
		if ( empty( $facts['premium'] ) ) {
			return new WP_Error( 'lld_premium', 'Plan adjustments are part of Premium.' );
		}
		$goal = self::active_goal( $member_id, 'main' );
		$plan = $goal ? self::plan( $member_id, (int) $goal['id'] ) : null;
		if ( ! $goal || ! $plan ) {
			return new WP_Error( 'lld_adjust', 'There is no plan to adjust.' );
		}
		$today    = (string) $facts['today'];
		$schedule = $plan['schedule'];
		$copy     = array_intersect_key( $plan, array_flip( array( 'goal_id', 'track_id', 'calorie_target', 'protein_g', 'carb_g', 'fat_g', 'fibre_g', 'activity_minutes_week', 'meal_plan_id', 'workout_plan_id' ) ) );
		$note     = '';
		switch ( $choice ) {
			case 'later_date':
				$latest = self::latest_value( $member_id, 'weight' );
				if ( null === $latest || ! is_numeric( $goal['target_value'] ) ) {
					return new WP_Error( 'lld_adjust', 'A recent weigh-in is needed first.' );
				}
				$cap   = LLD_Members_Goal_Rules::rate_cap( $latest, in_array( 'age_65_plus', $facts['situations'], true ) );
				$later = LLD_Members_Goal_Rules::later_date( $latest, (float) $goal['target_value'], isset( $schedule['rate_kg_week'] ) ? (float) $schedule['rate_kg_week'] : $cap, $cap, $today );
				if ( $later['date'] > (string) $goal['target_date'] ) {
					LLD_Members_Stage3_Data::update( 'goals', $member_id, (int) $goal['id'], array( 'target_date' => $later['date'] ) );
				}
				$schedule['rate_kg_week'] = $later['rate'];
				$note                     = 'target date moved to ' . $later['date'];
				break;
			case 'easier_activity':
				$copy['activity_minutes_week'] = LLD_Members_Goal_Rules::easier_activity( (int) $plan['activity_minutes_week'] );
				$schedule['easier_until']      = LLD_Members_Goal_Rules::add_days( $today, 7 );
				$note                          = 'activity target eased for one week';
				break;
			case 'different_meal_plan':
				$schedule['meal_plan_swap'] = $today;
				$note                       = 'a different meal plan this week';
				break;
			default:
				return new WP_Error( 'lld_adjust', 'Choose an option.' );
		}
		// The safe limits hold on every version (FR3-14 AS-02): the calorie target never drops below the floor.
		if ( isset( $copy['calorie_target'] ) && is_numeric( $copy['calorie_target'] ) && class_exists( 'LLD_Members_Calorie_Target' ) ) {
			$copy['calorie_target'] = max( (int) $copy['calorie_target'], (int) LLD_Members_Calorie_Target::floor_for( (string) $facts['sex'] ) );
		}
		$copy['version']      = (int) $plan['version'] + 1;
		$copy['log_schedule'] = wp_json_encode( $schedule );
		$copy['valid_from']   = $today;
		$copy['created_by']   = 'member';
		$new_id               = LLD_Members_Stage3_Data::insert( 'plans', $member_id, $copy );
		if ( $new_id < 1 ) {
			return new WP_Error( 'lld_adjust', 'Try again.' );
		}
		LLD_Members_Stage3_Data::update( 'plans', $member_id, (int) $plan['id'], array( 'valid_to' => $today ) );
		$changes          = json_decode( (string) $adj['changes'], true );
		$changes          = is_array( $changes ) ? $changes : array();
		$changes['chose'] = $choice;
		$changes['note']  = $note;
		LLD_Members_Stage3_Data::update( 'plan_adjustments', $member_id, (int) $adj['id'], array( 'accepted' => 1, 'plan_id' => $new_id, 'changes' => wp_json_encode( $changes ) ) );
		return true;
	}

	// ---- Readings and daily values ------------------------------------------------------------------------------------

	/**
	 * One daily value per local date for a reading type, as the engine builds it (ENG-08 to ENG-10).
	 *
	 * @param int    $member_id Member number.
	 * @param string $type      Reading type.
	 * @return array<string,mixed> date => value (blood pressure: [sys, dia]).
	 */
	public static function daily( $member_id, $type ) {
		$key = (int) $member_id . ':' . $type;
		if ( isset( self::$daily_cache[ $key ] ) ) {
			return self::$daily_cache[ $key ];
		}
		$zone = self::zone( $member_id );
		$days = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'readings', $member_id, self::SEALED_READING, '', 2000 ) as $r ) {
			if ( ! empty( $r['deleted_at'] ) || (string) $r['reading_type'] !== $type || ! is_numeric( $r['value_1'] ) ) {
				continue;
			}
			$at = self::local( (string) $r['taken_at'], $zone );
			$days[ $at->format( 'Y-m-d' ) ][] = array(
				'at' => $at->getTimestamp(),
				'v1' => (float) $r['value_1'],
				'v2' => is_numeric( $r['value_2'] ) ? (float) $r['value_2'] : null,
				'v3' => is_numeric( $r['value_3'] ) ? (float) $r['value_3'] : null,
			);
		}
		$out = array();
		foreach ( $days as $date => $list ) {
			$v = class_exists( 'LLD_Members_Insights_Runner' ) ? LLD_Members_Insights_Runner::daily_value( $type, $list ) : end( $list )['v1'];
			if ( null !== $v ) {
				$out[ $date ] = $v;
			}
		}
		ksort( $out );
		self::$daily_cache[ $key ] = $out;
		return $out;
	}

	/**
	 * The latest daily value of a type, or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $type      Reading type.
	 */
	public static function latest_value( $member_id, $type ) {
		$daily = self::daily( $member_id, $type );
		if ( ! $daily ) {
			return null;
		}
		$v = end( $daily );
		return is_array( $v ) ? $v[0] : $v;
	}

	/**
	 * Progress towards a goal (FR3-13): done of total, the trend and on track or behind.
	 *
	 * @param int   $member_id Member number.
	 * @param array $goal      Goal row.
	 * @param array $facts     facts().
	 * @return array progress (or null), trend, latest, points (date => value), direction, measure_label.
	 */
	public static function progress( $member_id, array $goal, array $facts ) {
		$types     = LLD_Members_Goal_Rules::types();
		$type      = isset( $types[ $goal['goal_type'] ] ) ? $types[ $goal['goal_type'] ] : $types['activity'];
		$direction = (string) $type['direction'];
		$today     = (string) $facts['today'];
		$points    = array();
		switch ( (string) $goal['measure'] ) {
			case 'weight':
				$points = self::daily( $member_id, 'weight' );
				break;
			case 'blood_pressure':
				foreach ( self::daily( $member_id, 'blood_pressure' ) as $d => $v ) {
					$points[ $d ] = is_array( $v ) ? (float) $v[0] : (float) $v;
				}
				break;
			case 'glucose':
				$points = self::daily( $member_id, 'glucose' );
				break;
			case 'activity_minutes':
				// Weekly totals, keyed by the last day of each week, so the trend reads week to week.
				$daily = self::daily( $member_id, 'activity' );
				foreach ( $daily as $d => $v ) {
					$end = LLD_Members_Goal_Rules::add_days( LLD_Members_Goal_Rules::week_start( $d ), 6 );
					$points[ $end ] = ( isset( $points[ $end ] ) ? $points[ $end ] : 0 ) + (float) $v;
				}
				ksort( $points );
				break;
			case 'sleep_hours':
				$points = self::daily( $member_id, 'sleep' );
				break;
			case 'reps':
				$points = self::check_in_values( $member_id, 'push_ups' );
				break;
		}
		$latest = $points ? end( $points ) : null;
		if ( 'activity_minutes' === (string) $goal['measure'] && $points ) {
			$this_week = LLD_Members_Goal_Rules::add_days( LLD_Members_Goal_Rules::week_start( $today ), 6 );
			$latest    = isset( $points[ $this_week ] ) ? $points[ $this_week ] : 0.0;
		}
		$start = is_numeric( $goal['start_value'] ) ? (float) $goal['start_value'] : null;
		if ( null === $start && $points ) {
			$start = (float) reset( $points );
		}
		$target = is_numeric( $goal['target_value'] ) ? (float) $goal['target_value'] : null;
		return array(
			'progress'  => null !== $latest ? LLD_Members_Goal_Rules::progress( $start, $target, $latest, $direction ) : null,
			'trend'     => null !== $target ? LLD_Members_Goal_Rules::on_track( $points, $target, $goal['target_date'] ? (string) $goal['target_date'] : null, $today, 'hold' === $direction ? 'down' : $direction ) : array( 'status' => 'none', 'projected' => null, 'late_days' => null, 'points' => count( $points ), 'slope_per_week' => null ),
			'latest'    => $latest,
			'start'     => $start,
			'target'    => $target,
			'points'    => $points,
			'direction' => $direction,
			'unit'      => (string) $goal['unit'],
		);
	}

	/**
	 * Goal reached (FR3-15 AS-01, D3-09): the 7-day mean at or past the target marks the goal reached. The nightly
	 * engine does the same through R-INS-10; this makes a check-in or a Today visit see it the same day.
	 *
	 * @param int   $member_id Member number.
	 * @param array $goal      Goal row (active).
	 * @param array $facts     facts().
	 * @return bool True when marked reached now.
	 */
	public static function check_reached( $member_id, array $goal, array $facts ) {
		if ( 'active' !== (string) $goal['status'] || ! is_numeric( $goal['target_value'] ) ) {
			return false;
		}
		$types = LLD_Members_Goal_Rules::types();
		$type  = isset( $types[ $goal['goal_type'] ] ) ? $types[ $goal['goal_type'] ] : null;
		if ( ! $type || 'hold' === $type['direction'] ) {
			return false;
		}
		if ( 'blood_pressure' === (string) $goal['measure'] ) {
			// Both 7-day means at or below their targets (owner decision Q79 = B); progress() keeps the top number only.
			$points = self::daily( $member_id, 'blood_pressure' );
			if ( ! $points || ! LLD_Members_Goal_Rules::bp_reached( $points, (float) $goal['target_value'], isset( $goal['target_value_2'] ) ? $goal['target_value_2'] : null, (string) $facts['today'] ) ) {
				return false;
			}
		} else {
			$p = self::progress( $member_id, $goal, $facts );
			if ( ! $p['points'] || ! LLD_Members_Goal_Rules::reached( $p['points'], (float) $goal['target_value'], (string) $facts['today'], $type['direction'] ) ) {
				return false;
			}
		}
		LLD_Members_Stage3_Data::update( 'goals', $member_id, (int) $goal['id'], array( 'status' => 'reached', 'reached_at' => lld_members_now_mysql( $member_id ) ) );
		return true;
	}

	/**
	 * "Keep it off" after a reached weight goal (FR3-15 AS-02): a new main goal to hold the weight, and the Keep It
	 * Off track when it exists (Healthy Weight otherwise).
	 *
	 * @param int   $member_id Member number.
	 * @param array $reached   The reached goal.
	 * @param array $facts     facts().
	 * @return int|WP_Error New goal id.
	 */
	public static function keep_it_off( $member_id, array $reached, array $facts ) {
		if ( 'weight' !== (string) $reached['measure'] ) {
			return new WP_Error( 'lld_keep', 'Keep it off follows a weight goal. Set a new goal to carry on.' );
		}
		$latest = self::latest_value( $member_id, 'weight' );
		if ( null === $latest ) {
			$latest = is_numeric( $reached['target_value'] ) ? (float) $reached['target_value'] : null;
		}
		$id = self::create_goal( $member_id, 'main', 'keep_weight', array( 'current' => $latest, 'facts' => $facts ) );
		if ( ! is_wp_error( $id ) ) {
			// Keep It Off keeps a daily target as a guide, at the P3-55 maintain level, no deficit (FR3-20, 10 Nov 2026).
			self::save_target( $member_id, self::calorie_targets( $facts, 'maintain', null ), (string) $facts['today'] );
		}
		if ( ! is_wp_error( $id ) && class_exists( 'LLD_Members_Programme' ) ) {
			$track = LLD_Members_Programme::track_exists( 'keep-it-off' ) ? 'keep-it-off' : 'healthy-weight';
			if ( LLD_Members_Programme::track_exists( $track ) && LLD_Members_Programme::track_allowed( $member_id, $track ) ) {
				LLD_Members_Programme::start( $member_id, $track );
			}
		}
		return $id;
	}

	/**
	 * Values from the Stage 2 weekly check-ins (the strength goal's push-ups), by date.
	 *
	 * @param int    $member_id Member number.
	 * @param string $column    A check_in column.
	 * @return array<string,float>
	 */
	private static function check_in_values( $member_id, $column ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT local_date, {$column} AS v FROM " . LLD_Members_Schema::table( 'check_in' ) . " WHERE member_id = %d AND {$column} IS NOT NULL ORDER BY local_date ASC", $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column from a fixed list.
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['local_date'] ] = (float) $r['v'];
		}
		return $out;
	}

	// ---- Today (FR3-12) ----------------------------------------------------------------------------------------------

	/**
	 * Days with any activity: a reading, a food entry or a step done. For the ring, streak and bars.
	 *
	 * @param int $member_id Member number.
	 * @param int $days      How far back.
	 * @return string[] Local dates.
	 */
	public static function active_dates( $member_id, $days = 42 ) {
		global $wpdb;
		$zone  = self::zone( $member_id );
		$dates = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'readings', $member_id, array(), '', 2000 ) as $r ) {
			if ( empty( $r['deleted_at'] ) ) {
				$dates[ self::local( (string) $r['taken_at'], $zone )->format( 'Y-m-d' ) ] = true;
			}
		}
		foreach ( LLD_Members_Stage3_Data::rows( 'food_log', $member_id, array(), '', 2000 ) as $f ) {
			if ( empty( $f['deleted_at'] ) ) {
				$dates[ (string) $f['entry_date'] ] = true;
			}
		}
		$steps = $wpdb->get_col( $wpdb->prepare( 'SELECT step_date FROM ' . LLD_Members_Stage3_Schema::table( 'today_steps' ) . ' WHERE member_id = %d AND status = %s', $member_id, 'done' ) );
		foreach ( (array) $steps as $d ) {
			$dates[ (string) $d ] = true;
		}
		// The Stage 2 programme: a day with an activity marked done counts too.
		$results = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT local_date FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d AND result = %s', $member_id, 'done' ) );
		foreach ( (array) $results as $d ) {
			if ( $d ) {
				$dates[ (string) $d ] = true;
			}
		}
		$from = LLD_Members_Goal_Rules::add_days( class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::local_today( $member_id ) : gmdate( 'Y-m-d' ), -$days );
		$out  = array();
		foreach ( array_keys( $dates ) as $d ) {
			if ( $d >= $from ) {
				$out[] = $d;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Today's step row, or null. With `$run`, the engine runs for the member first when no step exists yet for the
	 * day (the nightly run, done at first sight; it writes nothing twice).
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Local date.
	 * @param bool   $run       Run the engine when no row exists.
	 * @return array<string,mixed>|null
	 */
	public static function today_step( $member_id, $today, $run = true ) {
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'today_steps' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d AND step_date = %s", $member_id, $today ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row && $run && class_exists( 'LLD_Members_Insights_Runner' ) ) {
			LLD_Members_Insights_Runner::run_member( $member_id, $today );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d AND step_date = %s", $member_id, $today ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The approved text of an engine-chosen step: the rule's template with the numbers the engine stored for today.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $step      today_steps row.
	 * @param string $today     Local date.
	 */
	public static function step_text( $member_id, array $step, $today ) {
		if ( ! class_exists( 'LLD_Members_Rule_Catalogue' ) ) {
			return '';
		}
		if ( empty( $step['rule_id'] ) ) {
			return 'read' === (string) $step['action_type'] ? (string) LLD_Members_Rule_Catalogue::fill( 'template.T-GEN-01', array( 'n' => LLD_Members_Decision_Engine::GEN_DAYS ) ) : '';
		}
		$name = LLD_Members_Rule_Catalogue::name_for_id( (int) $step['rule_id'] );
		$rule = LLD_Members_Rule_Catalogue::rule( $name );
		if ( ! $rule ) {
			return '';
		}
		$template = ! empty( $rule['step_template'] ) ? $rule['step_template'] : $rule['template'];
		$values   = array();
		$zone     = self::zone( $member_id );
		foreach ( LLD_Members_Stage3_Data::rows( 'insights', $member_id, array( 'values' ), '', 200 ) as $ins ) {
			if ( (int) $ins['rule_id'] === (int) $step['rule_id'] && self::local( (string) $ins['shown_at'], $zone )->format( 'Y-m-d' ) === $today ) {
				$v = json_decode( (string) $ins['values'], true );
				if ( is_array( $v ) ) {
					$values = $v;
				}
				break;
			}
		}
		return (string) LLD_Members_Rule_Catalogue::fill( $template, $values );
	}

	/**
	 * Done or Skip (FR3-12 AS-02): today's row is updated, or written for a plan-sourced step.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Local date.
	 * @param string $status    done or skipped.
	 * @param string $action    Action type for a new row.
	 */
	public static function mark_step( $member_id, $today, $status, $action = 'activity' ) {
		$status = 'done' === $status ? 'done' : 'skipped';
		$row    = self::today_step( $member_id, $today, false );
		if ( $row ) {
			return LLD_Members_Stage3_Data::update( 'today_steps', $member_id, (int) $row['id'], array( 'status' => $status ) );
		}
		return LLD_Members_Stage3_Data::insert( 'today_steps', $member_id, array( 'step_date' => $today, 'action_type' => $action, 'status' => $status ) ) > 0;
	}

	/**
	 * A quick-log tile (FR3-12): water (glasses), mood (1 to 5) or meals (sent to the diary).
	 *
	 * @param int    $member_id Member number.
	 * @param string $kind      water or mood.
	 * @param mixed  $value     Glasses or mood score.
	 * @return array{id:int,level:string,message:string}
	 */
	public static function quick_log( $member_id, $kind, $value ) {
		if ( 'water' === $kind ) {
			$glasses = max( 1, min( 8, (int) $value ) );
			return self::save_reading( $member_id, 'water', array( 'value_1' => $glasses * self::GLASS_ML ), 'glasses', 'quick_tile' );
		}
		if ( 'mood' === $kind ) {
			$mood = max( 1, min( 5, (int) $value ) );
			return self::save_reading( $member_id, 'mood', array( 'value_1' => $mood ), '', 'quick_tile' );
		}
		return array( 'id' => 0, 'level' => 'none', 'message' => '' );
	}

	// ---- Check-ins (FR3-14) --------------------------------------------------------------------------------------------

	/**
	 * Dates the member completed the weekly check-in, newest first.
	 *
	 * @param int $member_id Member number.
	 * @return string[]
	 */
	public static function check_in_dates( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT step_date FROM ' . LLD_Members_Stage3_Schema::table( 'today_steps' ) . ' WHERE member_id = %d AND action_type = %s AND status = %s ORDER BY step_date DESC', $member_id, 'check_in', 'done' ) );
		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Record today's check-in as done: today's step becomes the check-in (one step a day, data model Today step).
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Local date.
	 */
	public static function record_check_in( $member_id, $today ) {
		$row  = self::today_step( $member_id, $today, false );
		$rule = class_exists( 'LLD_Members_Rule_Catalogue' ) ? LLD_Members_Rule_Catalogue::rule( 'R-STEP-03' ) : null;
		if ( $row ) {
			return LLD_Members_Stage3_Data::update( 'today_steps', $member_id, (int) $row['id'], array( 'action_type' => 'check_in', 'status' => 'done' ) );
		}
		return LLD_Members_Stage3_Data::insert( 'today_steps', $member_id, array( 'step_date' => $today, 'action_type' => 'check_in', 'rule_id' => $rule ? (int) $rule['id'] : null, 'status' => 'done' ) ) > 0;
	}

	/**
	 * Whether a check-in is due: none yet, or the last one 7 or more days ago.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Local date.
	 */
	public static function check_in_due( $member_id, $today ) {
		$dates = self::check_in_dates( $member_id );
		return ! $dates || LLD_Members_Goal_Rules::days_between( $dates[0], $today ) >= 7;
	}

	/**
	 * The engine's check-in history (catalogue R-ADJ-01): for each completed check-in, how late the trend projected
	 * the goal that day and how many weigh-ins the 28 days before held. Oldest first.
	 *
	 * @param array  $list      Filter default.
	 * @param int    $member_id Member number.
	 * @param string $today     Local date.
	 */
	public static function filter_check_ins( $list, $member_id, $today ) {
		$goal = self::active_goal( $member_id, 'main' );
		if ( ! $goal || 'weight' !== (string) $goal['measure'] || ! is_numeric( $goal['target_value'] ) ) {
			return is_array( $list ) ? $list : array();
		}
		$points = self::daily( $member_id, 'weight' );
		$out    = array();
		foreach ( array_reverse( array_slice( self::check_in_dates( $member_id ), 0, 8 ) ) as $date ) {
			$upto = array();
			$n28  = 0;
			foreach ( $points as $d => $v ) {
				if ( $d <= $date ) {
					$upto[ $d ] = $v;
					if ( LLD_Members_Goal_Rules::days_between( $d, $date ) < 28 ) {
						$n28++;
					}
				}
			}
			$trend = LLD_Members_Goal_Rules::on_track( $upto, (float) $goal['target_value'], $goal['target_date'] ? (string) $goal['target_date'] : null, $date, 'down' );
			$out[] = array( 'date' => $date, 'projected_late_days' => null === $trend['late_days'] ? 0 : (int) $trend['late_days'], 'weigh_ins_28' => $n28 );
		}
		return $out;
	}

	public static function filter_last_check_in( $value, $member_id, $today ) {
		$dates = self::check_in_dates( $member_id );
		return $dates ? $dates[0] : $value;
	}

	public static function filter_check_in_this_week( $value, $member_id, $today ) {
		$dates = self::check_in_dates( $member_id );
		if ( ! $dates ) {
			return $value;
		}
		$goal  = self::active_goal( $member_id, 'main' );
		$plan  = $goal ? self::plan( $member_id, (int) $goal['id'] ) : null;
		$start = LLD_Members_Goal_Rules::week_start( $today, $plan && $plan['valid_from'] ? (string) $plan['valid_from'] : null );
		return $dates[0] >= $start && $dates[0] <= $today;
	}

	/** The latest logged weight for the matrix's BMI row, when the profile has none newer. */
	public static function filter_latest_weight( $weight, $member_id ) {
		$latest = self::latest_value( $member_id, 'weight' );
		return null === $latest ? $weight : (float) $latest;
	}

	// ---- Healthy Weight programme (P3-35, FR3-100 to FR3-102, FR3-20) -------------------------------------------------

	/** Milestone weights are sealed (data model: Weight milestone). */
	const SEALED_MILESTONE = array( 'target_value' );

	/**
	 * Save the recommended pace, the chosen pace and the rule that recommended it on the goal (FR3-100 AS-06), with the
	 * date the chosen pace gives (weeks rounded up, P3-56) and the plan's rate.
	 *
	 * @param int         $member_id   Member number.
	 * @param int         $goal_id     Goal id.
	 * @param float       $recommended kg a week.
	 * @param float       $chosen      kg a week, within the safe limit (checked by R-HW-SAFE-02 before this).
	 * @param int|null    $rule_id     Catalogue id of R-HW-PACE-01.
	 * @param string|null $date        Target date for the chosen pace.
	 */
	public static function save_pace( $member_id, $goal_id, $recommended, $chosen, $rule_id, $date = null ) {
		$row = array(
			'recommended_rate' => (float) $recommended,
			'chosen_rate'      => (float) $chosen,
			'pace_rule_id'     => null === $rule_id ? null : (int) $rule_id,
		);
		if ( is_string( $date ) && preg_match( '/^\d{4}-\d\d-\d\d$/', $date ) ) {
			$row['target_date'] = $date;
		}
		$ok   = LLD_Members_Stage3_Data::update( 'goals', $member_id, (int) $goal_id, $row );
		$plan = self::plan( $member_id, (int) $goal_id );
		if ( $plan ) {
			$schedule                 = $plan['schedule'];
			$schedule['rate_kg_week'] = (float) $chosen;
			LLD_Members_Stage3_Data::update( 'plans', $member_id, (int) $plan['id'], array( 'log_schedule' => wp_json_encode( $schedule ) ) );
		}
		return $ok;
	}

	/**
	 * Replace the goal's milestones not yet reached with a new list (R-HW-NUM-04): milestones already passed or beyond
	 * the goal are never created (the engine leaves them out). Weights sealed, in kg.
	 *
	 * @param int   $member_id Member number.
	 * @param array $goal      Goal row.
	 * @param array $list      LLD_Members_HW_Rules::milestones()['milestones'].
	 * @return int Rows written.
	 */
	public static function save_milestones( $member_id, array $goal, array $list ) {
		global $wpdb;
		$kinds = array( '5pct' => 'five_percent', '10pct' => 'ten_percent', 'healthy' => 'healthy_range', 'goal' => 'goal' );
		$keep  = array();
		foreach ( self::milestone_rows( $member_id, (int) $goal['id'] ) as $r ) {
			if ( $r['reached_at'] ) {
				$keep[] = (string) $r['kind'];
				continue;
			}
			$wpdb->delete( LLD_Members_Stage3_Schema::table( 'goal_milestones' ), array( 'id' => (int) $r['id'], 'member_id' => (int) $member_id ), array( '%d', '%d' ) );
		}
		$n = 0;
		foreach ( $list as $m ) {
			$kind = isset( $kinds[ $m['kind'] ] ) ? $kinds[ $m['kind'] ] : (string) $m['kind'];
			if ( in_array( $kind, $keep, true ) ) {
				continue;
			}
			$n += LLD_Members_Stage3_Data::insert( 'goal_milestones', $member_id, array( 'goal_id' => (int) $goal['id'], 'kind' => $kind, 'target_value' => (float) $m['kg'] ), self::SEALED_MILESTONE ) > 0 ? 1 : 0;
		}
		return $n;
	}

	/**
	 * The goal's milestone rows, weights opened.
	 *
	 * @param int $member_id Member number.
	 * @param int $goal_id   Goal id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function milestone_rows( $member_id, $goal_id ) {
		$out = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'goal_milestones', $member_id, self::SEALED_MILESTONE, '', 50 ) as $r ) {
			if ( (int) $r['goal_id'] === (int) $goal_id ) {
				$r['target_value'] = is_numeric( $r['target_value'] ) ? (float) $r['target_value'] : null;
				$out[]             = $r;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);
		return $out;
	}

	/**
	 * The P3-55 maintain target (no deficit), or null when no target may be shown (FR3-20 AS-03).
	 *
	 * @param array $facts facts().
	 * @return int|null
	 */
	public static function maintain_target( array $facts ) {
		$t = self::calorie_targets( $facts, 'maintain', null );
		return $t ? (int) $t['calorie_target'] : null;
	}

	/**
	 * Work the main weight goal's target out again at once from the confirmed activity level and the chosen pace
	 * (FR3-102 AS-05): the current plan version's numbers and the member's nutrition target. A reached goal keeps the
	 * maintain target (Keep It Off). Nothing for a member the safety matrix keeps calorie numbers from.
	 *
	 * @param int         $member_id Member number.
	 * @param array|null  $facts     facts(), or null to read them.
	 * @param float|null  $rate      kg a week, or null for the goal's chosen pace.
	 * @param string|null $method    Eating method to record (calorie_count), or null to keep it.
	 * @return array|null The targets written.
	 */
	public static function retarget( $member_id, $facts = null, $rate = null, $method = null ) {
		$facts = is_array( $facts ) ? $facts : self::facts( $member_id );
		$goal  = null;
		foreach ( self::goals( $member_id ) as $g ) {
			if ( 'main' === (string) $g['role'] && 'weight' === (string) $g['measure'] && in_array( (string) $g['status'], array( 'active', 'reached' ), true ) ) {
				$goal = $g;
				break;
			}
		}
		$plan = $goal ? self::plan( $member_id, (int) $goal['id'] ) : null;
		if ( ! $plan ) {
			return null;
		}
		if ( null === $rate ) {
			$rate = isset( $goal['chosen_rate'] ) && is_numeric( $goal['chosen_rate'] ) ? (float) $goal['chosen_rate'] : ( isset( $plan['schedule']['rate_kg_week'] ) ? (float) $plan['schedule']['rate_kg_week'] : null );
		}
		$maintain = 'reached' === (string) $goal['status'] || 'keep_weight' === (string) $goal['goal_type'];
		$targets  = self::calorie_targets( $facts, $maintain ? 'maintain' : 'lose', $rate );
		$row      = $targets ? $targets : array();
		// Calorie counting for every member at launch; never for a member with an eating-disorder flag (data model).
		if ( null !== $method && ! in_array( 'eating_disorder', (array) $facts['situations'], true ) ) {
			$row['eating_method'] = (string) $method;
		}
		if ( $row ) {
			LLD_Members_Stage3_Data::update( 'plans', $member_id, (int) $plan['id'], $row );
		}
		self::save_target( $member_id, $targets, (string) $facts['today'] );
		return $targets;
	}

	/**
	 * The member's nutrition target from the plan, from a date (the food tracker reads the latest valid one).
	 *
	 * @param int        $member_id Member number.
	 * @param array|null $targets   calorie_targets().
	 * @param string     $from      Local date.
	 */
	private static function save_target( $member_id, $targets, $from ) {
		if ( ! is_array( $targets ) || empty( $targets['calorie_target'] ) ) {
			return 0;
		}
		return LLD_Members_Stage3_Data::insert(
			'nutrition_targets',
			$member_id,
			array(
				'kcal'       => (int) $targets['calorie_target'],
				'protein_g'  => $targets['protein_g'],
				'carb_g'     => $targets['carb_g'],
				'fat_g'      => $targets['fat_g'],
				'fibre_g'    => $targets['fibre_g'],
				'set_by'     => 'plan',
				'valid_from' => $from,
			)
		);
	}

	// ---- Helpers -----------------------------------------------------------------------------------------------------

	private static function zone( $member_id ) {
		global $wpdb;
		if ( class_exists( 'LLD_Members_Programme' ) ) {
			$zone = LLD_Members_Programme::timezone( $member_id );
			if ( $zone instanceof DateTimeZone ) {
				return $zone;
			}
		}
		$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		try {
			return new DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	private static function local( $utc, DateTimeZone $zone ) {
		$at = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $utc, new DateTimeZone( 'UTC' ) );
		if ( ! $at ) {
			$at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}
		return $at->setTimezone( $zone );
	}
}
