<?php
/**
 * The decision engine's runner (P3-34; FR3-17, FR3-12, FR3-14, FR3-15, FR3-69, NFR3-16): builds a member's state
 * from the Stage 3 tables through LLD_Members_Stage3_Data (sealed values opened only in memory, never logged),
 * runs LLD_Members_Decision_Engine, and writes the insights, today_steps, plan_adjustments and plans rows.
 *
 * Runs once a night per member at 02:00 in the member's time zone (D3-48) from an hourly WP-Cron event that is
 * registered only while the `insights` switch is not off, and again at save time for the safety rules through the
 * `lld_members_reading_saved` action the logging packages call. Safety rules run for every member, Free and
 * Premium (FR3-69); insights and adjustments are Premium, except the safety adjustment R-ADJ-03 (Owner Q68). The runner never sends an email (EM-* are P3-85's).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron, hooks, state building and the writes.
 */
class LLD_Members_Insights_Runner {

	const HOOK       = 'lld_members_insights_hourly';
	const SAVED_HOOK = 'lld_members_reading_saved';
	const RUNS       = 'lld_insights_last_run';
	const RUN_HOUR   = 2;
	const SEALED_READING = array( 'value_1', 'value_2', 'value_3', 'detail' );

	public static function boot() {
		// Safety at save time is always listened for; the handler checks the member's switches itself.
		add_action( self::SAVED_HOOK, array( __CLASS__, 'on_reading_saved' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'run_due' ) );
		if ( ! class_exists( 'LLD_Members_Features' ) ) {
			return;
		}
		$on = 'off' !== LLD_Members_Features::state( 'insights' );
		if ( $on && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		} elseif ( ! $on && wp_next_scheduled( self::HOOK ) ) {
			wp_unschedule_event( wp_next_scheduled( self::HOOK ), self::HOOK );
		}
	}

	/**
	 * Every hour: run each member whose local time has passed 02:00 today and who has not run today.
	 *
	 * @return array<int,string> member => outcome word.
	 */
	public static function run_due() {
		$runs = get_option( self::RUNS );
		$runs = is_array( $runs ) ? $runs : array();
		$out  = array();
		foreach ( self::members_with_data() as $member_id ) {
			$zone  = self::timezone( $member_id );
			$local = LLD_Members_Clock::now( $member_id )->setTimezone( $zone );
			$today = $local->format( 'Y-m-d' );
			if ( (int) $local->format( 'G' ) < self::RUN_HOUR || ( isset( $runs[ $member_id ] ) && $runs[ $member_id ] === $today ) ) {
				continue;
			}
			$summary          = self::run_member( $member_id, $today );
			$runs[ $member_id ] = $today;
			$out[ $member_id ] = $summary['status'];
		}
		update_option( self::RUNS, $runs, false );
		return $out;
	}

	/**
	 * Safety rules at save time (catalogue section 2; P3-03 Part C): the logging packages call
	 * do_action( 'lld_members_reading_saved', $member_id, $reading ). Never paid for; behind the switches only.
	 *
	 * @param int   $member_id Member number.
	 * @param array $reading   The saved row (reading_type at least).
	 * @return array Summary (rule names and outcomes only).
	 */
	public static function on_reading_saved( $member_id, $reading = array() ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! self::safety_on( $member_id ) ) {
			return array( 'status' => 'off' );
		}
		$type = isset( $reading['reading_type'] ) ? (string) $reading['reading_type'] : '';
		if ( ! in_array( $type, array( 'blood_pressure', 'weight', 'mood' ), true ) ) {
			return array( 'status' => 'no_safety_rule' ); // glucose (R-SAFE-02) is answered by warning_level() in the response itself
		}
		return self::run_member( $member_id, null, array( 'safety_only' => true ) );
	}

	/**
	 * One member, one run. Options: dry_run (write nothing), safety_only, at_check_in.
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $today     Local Y-m-d, or null for the member's today.
	 * @param array       $options   See above.
	 * @return array status, date, safety[], today_step, adjustments[], insight, events[], outcomes (rule => outcome);
	 *               never a value or a text.
	 */
	public static function run_member( $member_id, $today = null, array $options = array() ) {
		$member_id = (int) $member_id;
		$dry       = ! empty( $options['dry_run'] );
		$safety_on = self::safety_on( $member_id );
		$engine_on = class_exists( 'LLD_Members_Features' ) && LLD_Members_Features::enabled( 'insights', $member_id );
		if ( ! $safety_on && ! $engine_on ) {
			return array( 'status' => 'off', 'date' => $today );
		}
		if ( ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return array( 'status' => 'no_health_consent', 'date' => $today );
		}
		$state  = self::build_state( $member_id, $today, ! empty( $options['at_check_in'] ) );
		$result = LLD_Members_Decision_Engine::evaluate( $state );
		// The matrix again at display time, in case the profile changed since the state was read (P3-03 D4, ENG-07).
		$situations = LLD_Members_Stage3_Safety::situations( $member_id );
		$summary    = array( 'status' => 'ran', 'date' => $result['date'], 'safety' => array(), 'today_step' => null, 'adjustments' => array(), 'insight' => null, 'events' => array(), 'outcomes' => array() );
		foreach ( $result['safety'] as $o ) {
			$summary['outcomes'][ $o['rule'] ] = $o['outcome'];
			if ( isset( $state['shown'][ $o['rule'] ] ) && $state['shown'][ $o['rule'] ] === $result['date'] ) {
				$summary['outcomes'][ $o['rule'] ] = 'shown_today'; // a rule without a cooldown (R-SAFE-01p) is written once a day
				continue;
			}
			if ( LLD_Members_Decision_Engine::OUTCOME_SHOWN === $o['outcome'] && LLD_Members_Stage3_Safety::allows_rule( $situations, $o['rule'] ) ) {
				$summary['safety'][] = $o['rule'];
				if ( ! $dry ) {
					self::write_insight( $member_id, $o );
				}
			}
		}
		if ( ! $engine_on || ! empty( $options['safety_only'] ) ) {
			$summary['status'] = 'safety_only';
			return $summary;
		}
		if ( in_array( 'unknown', $situations, true ) ) {
			$summary['status'] = 'safety_only_unknown'; // Part B "Unknown": no insights, no Today step from logs, no adjustments
			return $summary;
		}
		$step = $result['today_step'];
		if ( $step && ! empty( $step['template'] ) && ( null === $step['rule'] || LLD_Members_Stage3_Safety::allows_rule( $situations, $step['rule'] ) ) ) {
			$summary['today_step'] = $step['rule'] ? $step['rule'] : 'T-GEN-01';
			if ( ! $dry ) {
				self::write_today_step( $member_id, $result['date'], $step );
			}
		} elseif ( $step && 'plan' === ( isset( $step['source'] ) ? $step['source'] : '' ) ) {
			$summary['today_step'] = 'plan';
		}
		foreach ( $result['adjustments'] as $o ) {
			$summary['outcomes'][ $o['rule'] ] = $o['outcome'];
			if ( ! LLD_Members_Stage3_Safety::allows_rule( $situations, $o['rule'] ) ) {
				continue;
			}
			if ( LLD_Members_Decision_Engine::OUTCOME_APPLIED === $o['outcome'] || LLD_Members_Decision_Engine::OUTCOME_OFFERED === $o['outcome'] ) {
				$summary['adjustments'][] = $o['rule'];
				if ( ! $dry ) {
					self::write_adjustment( $member_id, $state, $o );
				}
			}
		}
		foreach ( $result['insights'] as $o ) {
			$summary['outcomes'][ $o['rule'] ] = $o['outcome'];
		}
		$ins = $result['insight'];
		if ( $ins && LLD_Members_Stage3_Safety::allows_rule( $situations, $ins['rule'] ) ) {
			$summary['insight'] = $ins['rule'];
			if ( ! $dry ) {
				self::write_insight( $member_id, $ins );
			}
		} elseif ( $ins ) {
			$summary['outcomes'][ $ins['rule'] ] = LLD_Members_Decision_Engine::OUTCOME_SILENCED;
		}
		foreach ( $result['events'] as $o ) {
			$summary['outcomes'][ $o['rule'] ] = $o['outcome'];
			if ( LLD_Members_Decision_Engine::OUTCOME_SHOWN === $o['outcome'] ) {
				$summary['events'][] = $o['rule'];
				if ( ! $dry ) {
					self::write_insight( $member_id, $o );
					self::mark_goal_reached( $member_id, $state );
				}
			}
		}
		return $summary;
	}

	/**
	 * True while R-SAFE-04 was shown to the member in the last 7 days (the low-mood row of the matrix).
	 *
	 * @param int $member_id Member number.
	 */
	public static function low_mood_active( $member_id ) {
		global $wpdb;
		$rule = LLD_Members_Rule_Catalogue::rule( 'R-SAFE-04' );
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(shown_at) FROM ' . LLD_Members_Stage3_Schema::table( 'insights' ) . ' WHERE member_id = %d AND rule_id = %d', (int) $member_id, (int) $rule['id'] ) );
		if ( ! $last ) {
			return false;
		}
		return ( LLD_Members_Clock::now( (int) $member_id )->getTimestamp() - strtotime( $last . ' UTC' ) ) < LLD_Members_Rule_Catalogue::LOW_MOOD_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * The member's state for the engine (class comment of LLD_Members_Decision_Engine). Every reading is opened in
	 * memory only; the array is never stored, logged or returned by a route.
	 *
	 * @param int         $member_id   Member number.
	 * @param string|null $today       Local Y-m-d, or null for today.
	 * @param bool        $at_check_in Whether this run is the weekly check-in.
	 * @return array
	 */
	private static function build_state( $member_id, $today = null, $at_check_in = false ) {
		$member_id = (int) $member_id;
		$zone      = self::timezone( $member_id );
		$today     = $today ? $today : LLD_Members_Clock::now( $member_id )->setTimezone( $zone )->format( 'Y-m-d' );
		$state     = array(
			'today'          => $today,
			'premium'        => class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::has_premium( $member_id ),
			'daily'          => array(),
			'readings_today' => array(),
			'urgent_dates'   => array(),
			'log_dates'      => array(),
			'food'           => array(),
			'at_check_in'    => (bool) $at_check_in,
		);

		// Readings: one daily value per type in the member's calendar (catalogue section 1; ENG-08 to ENG-10).
		$by_day = array();
		$dates  = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'readings', $member_id, self::SEALED_READING, '', 2000 ) as $r ) {
			if ( ! empty( $r['deleted_at'] ) ) {
				continue;
			}
			$type = (string) $r['reading_type'];
			$at   = self::local( $r['taken_at'], $zone );
			$date = $at->format( 'Y-m-d' );
			$v1   = is_numeric( $r['value_1'] ) ? (float) $r['value_1'] : null;
			$v2   = is_numeric( $r['value_2'] ) ? (float) $r['value_2'] : null;
			$v3   = is_numeric( $r['value_3'] ) ? (float) $r['value_3'] : null;
			if ( null === $v1 ) {
				continue;
			}
			$dates[ $date ]                 = true;
			$by_day[ $type ][ $date ][]     = array( 'at' => $at->getTimestamp(), 'v1' => $v1, 'v2' => $v2, 'v3' => $v3 );
			if ( 'blood_pressure' === $type && ! empty( $r['urgent_shown'] ) ) {
				$state['urgent_dates'][] = $date;
			}
			if ( 'blood_pressure' === $type && $date === $today && null !== $v2 ) {
				$state['readings_today']['blood_pressure'][] = array( $v1, $v2 );
			}
		}
		foreach ( $by_day as $type => $days ) {
			foreach ( $days as $date => $list ) {
				$state['daily'][ $type ][ $date ] = self::daily_value( $type, $list );
			}
		}

		// Food: meals and nutrients per day (R-INS-06, R-INS-07, R-STEP-05).
		foreach ( LLD_Members_Stage3_Data::rows( 'food_log', $member_id, array(), '', 2000 ) as $f ) {
			if ( ! empty( $f['deleted_at'] ) ) {
				continue;
			}
			$date           = (string) $f['entry_date'];
			$dates[ $date ] = true;
			if ( ! isset( $state['food'][ $date ] ) ) {
				$state['food'][ $date ] = array( 'meals' => array(), 'fibre_g' => 0.0, 'protein_g' => 0.0 );
			}
			$state['food'][ $date ]['meals'][ (string) $f['meal'] ] = true;
			$state['food'][ $date ]['fibre_g']   += (float) $f['fibre_g'];
			$state['food'][ $date ]['protein_g'] += (float) $f['protein_g'];
		}
		foreach ( $state['food'] as $date => $day ) {
			$state['food'][ $date ]['meals'] = count( $day['meals'] );
		}
		$state['log_dates'] = array_keys( $dates );

		// Goal and plan (FR3-09, FR3-11); targets (FR3-28).
		$goal = null;
		foreach ( LLD_Members_Stage3_Data::rows( 'goals', $member_id, array( 'start_value', 'target_value', 'start_value_2', 'target_value_2' ), '', 50 ) as $g ) {
			if ( ! empty( $g['deleted_at'] ) || 'active' !== (string) $g['status'] ) {
				continue;
			}
			if ( null === $goal || 'main' === (string) $g['role'] ) {
				$goal = $g;
			}
		}
		$plan = null;
		if ( $goal ) {
			// A blood pressure goal's bottom number (owner decision Q79 = B); older goals without one use the guideline 85.
			$target_2 = null;
			if ( 'blood_pressure' === (string) $goal['measure'] ) {
				$target_2 = is_numeric( $goal['target_value_2'] ) ? (float) $goal['target_value_2'] : (float) LLD_Members_Goal_Rules::BP_DEFAULT[1];
			}
			$detail   = array();
			$state['goal'] = array(
				'id'             => (int) $goal['id'],
				'goal_type'      => (string) $goal['goal_type'],
				'measure'        => (string) $goal['measure'],
				'target_value'   => is_numeric( $goal['target_value'] ) ? (float) $goal['target_value'] : null,
				'target_value_2' => $target_2,
				'unit'           => (string) $goal['unit'],
				'target_date'    => $goal['target_date'] ? (string) $goal['target_date'] : null,
				'reached'        => 'keep_weight' === (string) $goal['goal_type'], // a maintain goal has no finish line (P3-31: R-INS-10 never fires for it)
				'rate_kg_week'   => null,
			);
			foreach ( LLD_Members_Stage3_Data::rows( 'plans', $member_id, array(), '', 50 ) as $p ) {
				if ( (int) $p['goal_id'] === (int) $goal['id'] && ( null === $plan || (int) $p['version'] > (int) $plan['version'] ) ) {
					$plan = $p;
				}
			}
			if ( $plan ) {
				$schedule = json_decode( (string) $plan['log_schedule'], true );
				$schedule = is_array( $schedule ) ? $schedule : array();
				$state['plan'] = array(
					'id'                    => (int) $plan['id'],
					'started'               => $plan['valid_from'] ? (string) $plan['valid_from'] : substr( (string) $plan['created_at'], 0, 10 ),
					'weigh_in_weekday'      => self::weekday_key( isset( $schedule['weigh_in_weekday'] ) ? $schedule['weigh_in_weekday'] : null ),
					'activity_minutes_week' => is_numeric( $plan['activity_minutes_week'] ) ? (int) $plan['activity_minutes_week'] : null,
					'calorie_target'        => is_numeric( $plan['calorie_target'] ) ? (int) $plan['calorie_target'] : null,
					'cap_kg_week'           => isset( $schedule['cap_kg_week'] ) && is_numeric( $schedule['cap_kg_week'] ) ? (float) $schedule['cap_kg_week'] : null,
					'track'                 => isset( $schedule['track'] ) ? (string) $schedule['track'] : '',
				);
				$state['goal']['rate_kg_week'] = isset( $schedule['rate_kg_week'] ) && is_numeric( $schedule['rate_kg_week'] ) ? (float) $schedule['rate_kg_week'] : null;
				$state['targets']              = array( 'fibre_g' => $plan['fibre_g'], 'protein_g' => $plan['protein_g'] );
			}
		}
		foreach ( LLD_Members_Stage3_Data::rows( 'nutrition_targets', $member_id, array(), '', 20 ) as $t ) {
			if ( (string) $t['valid_from'] <= $today ) {
				$state['targets'] = array( 'fibre_g' => $t['fibre_g'], 'protein_g' => $t['protein_g'] );
				break; // newest change first
			}
		}

		// What the engine already showed (cooldown, quota, milestones, T-GEN-01).
		$state['shown']                = array();
		$state['insights_shown_dates'] = array();
		$state['milestones_shown']     = array();
		$ids                           = LLD_Members_Rule_Catalogue::rules();
		foreach ( LLD_Members_Stage3_Data::rows( 'insights', $member_id, array( 'values' ), '', 500 ) as $row ) {
			$name = LLD_Members_Rule_Catalogue::name_for_id( (int) $row['rule_id'] );
			if ( '' === $name ) {
				continue;
			}
			if ( LLD_Members_Rule_Catalogue::CLASS_STEP === $ids[ $name ]['class'] ) {
				continue; // a Today step's stored numbers (write_today_step); today_steps holds one step a day, so no cooldown is read from here
			}
			$date = self::local( $row['shown_at'], $zone )->format( 'Y-m-d' );
			if ( ! isset( $state['shown'][ $name ] ) || $date > $state['shown'][ $name ] ) {
				$state['shown'][ $name ] = $date;
			}
			if ( LLD_Members_Rule_Catalogue::CLASS_INSIGHT === $ids[ $name ]['class'] ) {
				$state['insights_shown_dates'][] = $date;
			}
			if ( 'R-INS-09' === $name ) {
				$values = json_decode( (string) $row['values'], true );
				if ( isset( $values['milestone'] ) ) {
					$state['milestones_shown'][] = (int) $values['milestone'];
				}
			}
		}
		global $wpdb;
		$state['gen_shown'] = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(step_date) FROM ' . LLD_Members_Stage3_Schema::table( 'today_steps' ) . ' WHERE member_id = %d AND rule_id IS NULL AND action_type = %s', $member_id, 'read' ) );

		// Check-ins and the Sleep and Stress check belong to other packages; they supply them through filters.
		$state['check_ins']               = apply_filters( 'lld_members_stage3_check_ins', array(), $member_id, $today );
		$state['last_check_in']           = apply_filters( 'lld_members_stage3_last_check_in', null, $member_id, $today );
		$state['check_in_done_this_week'] = (bool) apply_filters( 'lld_members_stage3_check_in_done_this_week', false, $member_id, $today );
		$state['phq2_today']              = apply_filters( 'lld_members_stage3_phq2_today', null, $member_id, $today );

		// The safety matrix, from the profile as it is now (P3-03 D4).
		$latest_weight       = isset( $state['daily']['weight'] ) && $state['daily']['weight'] ? max( array_keys( $state['daily']['weight'] ) ) : null;
		$state['situations'] = LLD_Members_Stage3_Safety::situations( $member_id, null === $latest_weight ? array() : array( 'latest_weight_kg' => $state['daily']['weight'][ $latest_weight ] ) );
		return $state;
	}

	// ---- Writes ----------------------------------------------------------------------------------------------------

	/**
	 * Store a shown message: the rule and template ids plain (for the cooldown and quota queries), the numbers and
	 * the filled text sealed (NFR3-10).
	 */
	private static function write_insight( $member_id, array $o ) {
		$values = $o['values'];
		if ( isset( $o['extra']['milestone'] ) ) {
			$values['milestone'] = $o['extra']['milestone'];
		}
		$template_ids = LLD_Members_Rule_Catalogue::template_ids();
		return LLD_Members_Stage3_Data::insert(
			'insights',
			$member_id,
			array(
				'rule_id'     => (int) $o['id'],
				'template_id' => isset( $template_ids[ $o['template'] ] ) ? $template_ids[ $o['template'] ] : 0,
				'values'      => wp_json_encode( array( 'values' => $values, 'text' => $o['text'], 'notes' => $o['notes'], 'template' => $o['template'] ) ),
				'shown_at'    => lld_members_now_mysql( $member_id ),
				'source'      => 'engine',
			),
			array( 'values' )
		);
	}

	/**
	 * Today's step, once a day; a step already shown, done or skipped today is left alone (FR3-12).
	 *
	 * A step whose template has numbers (R-STEP-04: minutes done, target, suggestion) keeps them in a sealed insights
	 * row under the step rule's id, the only place health numbers are stored; LLD_Members_Goal_Journey::step_text()
	 * reads them back to fill the approved template.
	 */
	private static function write_today_step( $member_id, $date, array $step ) {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Stage3_Schema::table( 'today_steps' ) . ' WHERE member_id = %d AND step_date = %s', $member_id, $date ) );
		if ( $exists ) {
			return (int) $exists;
		}
		if ( $step['rule'] && ! empty( $step['values'] ) && isset( $step['class'] ) && LLD_Members_Rule_Catalogue::CLASS_STEP === $step['class'] ) {
			self::write_insight( $member_id, $step );
		}
		return LLD_Members_Stage3_Data::insert(
			'today_steps',
			$member_id,
			array(
				'step_date'   => $date,
				'action_type' => isset( $step['action'] ) ? $step['action'] : 'read',
				'rule_id'     => $step['rule'] ? (int) $step['id'] : null,
				'status'      => 'shown',
			)
		);
	}

	/**
	 * A plan adjustment: offered ones wait for the member's answer (accepted NULL); R-ADJ-03 is applied at once as a
	 * new plan version with the calorie target raised by 250 kcal (D3-46), and the member is told. Health numbers go
	 * only into the sealed insights row; `changes` names the template and the offers.
	 */
	private static function write_adjustment( $member_id, array $state, array $o ) {
		if ( empty( $state['plan']['id'] ) ) {
			return 0;
		}
		$plan_id    = (int) $state['plan']['id'];
		$insight_id = null === $o['template'] ? 0 : self::write_insight( $member_id, $o ); // no message for an eating-disorder member (Q68)
		$new_plan   = $plan_id;
		$applied    = LLD_Members_Decision_Engine::OUTCOME_APPLIED === $o['outcome'];
		if ( $applied ) {
			$old = LLD_Members_Stage3_Data::row( 'plans', $member_id, $plan_id );
			if ( $old ) {
				$copy = array_intersect_key( $old, array_flip( array( 'goal_id', 'track_id', 'calorie_target', 'protein_g', 'carb_g', 'fat_g', 'fibre_g', 'activity_minutes_week', 'meal_plan_id', 'workout_plan_id', 'log_schedule', 'valid_to' ) ) );
				$copy['version']        = (int) $old['version'] + 1;
				$copy['calorie_target'] = (int) $o['values']['kcal'];
				$schedule               = json_decode( (string) $old['log_schedule'], true );
				$schedule               = is_array( $schedule ) ? $schedule : array();
				if ( isset( $schedule['rate_kg_week'] ) && is_numeric( $schedule['rate_kg_week'] ) ) {
					$schedule['rate_kg_week'] = self::rate_one_step_lower( (float) $schedule['rate_kg_week'] ); // the rate is lowered one step with the target (catalogue R-ADJ-03)
					$copy['log_schedule']     = wp_json_encode( $schedule );
				}
				$copy['valid_from']     = $state['today'];
				$copy['created_by']     = 'engine';
				$new_plan               = LLD_Members_Stage3_Data::insert( 'plans', $member_id, $copy );
				if ( $new_plan > 0 ) {
					LLD_Members_Stage3_Data::update( 'plans', $member_id, $plan_id, array( 'valid_to' => $state['today'] ) );
				}
			}
		}
		return LLD_Members_Stage3_Data::insert(
			'plan_adjustments',
			$member_id,
			array(
				'plan_id'          => $new_plan > 0 ? $new_plan : $plan_id,
				'previous_plan_id' => $plan_id,
				'rule_id'          => (int) $o['id'],
				'changes'          => wp_json_encode( array( 'template' => $o['template'], 'insight_id' => $insight_id, 'offers' => isset( $o['extra']['offers'] ) ? $o['extra']['offers'] : array(), 'automatic' => $applied, 'notes' => $o['notes'] ) ),
				'accepted'         => $applied ? 1 : null,
			)
		);
	}

	private static function mark_goal_reached( $member_id, array $state ) {
		if ( empty( $state['goal']['id'] ) ) {
			return;
		}
		LLD_Members_Stage3_Data::update( 'goals', $member_id, (int) $state['goal']['id'], array( 'status' => 'reached', 'reached_at' => lld_members_now_mysql( $member_id ) ) );
	}

	// ---- Helpers ---------------------------------------------------------------------------------------------------

	/** Safety rules run behind the health_logging or insights switch, whatever the member pays (FR3-69). */
	private static function safety_on( $member_id ) {
		return class_exists( 'LLD_Members_Features' ) && ( LLD_Members_Features::enabled( 'health_logging', $member_id ) || LLD_Members_Features::enabled( 'insights', $member_id ) );
	}

	/**
	 * One daily value from a day's readings: blood pressure, glucose and pulse the mean; weight and waist the last;
	 * sleep, water and activity the sum (vigorous effort counts double, FR3-41); mood the mean to 1 dp.
	 *
	 * @param string $type Reading type.
	 * @param array  $list at, v1, v2, v3 rows.
	 */
	public static function daily_value( $type, array $list ) {
		switch ( $type ) {
			case 'blood_pressure':
				$s = 0.0;
				$d = 0.0;
				$n = 0;
				foreach ( $list as $r ) {
					if ( null === $r['v2'] ) {
						continue;
					}
					$s += $r['v1'];
					$d += $r['v2'];
					$n++;
				}
				return $n ? array( $s / $n, $d / $n ) : null;
			case 'weight':
			case 'waist':
				usort( $list, function ( $a, $b ) { return $a['at'] <=> $b['at']; } );
				return end( $list )['v1'];
			case 'activity':
				$sum = 0.0;
				foreach ( $list as $r ) {
					$sum += $r['v1'] * ( null !== $r['v3'] && $r['v3'] >= 3 ? 2 : 1 );
				}
				return $sum;
			case 'sleep':
			case 'water':
				return array_sum( array_column( $list, 'v1' ) );
			case 'mood':
				return round( array_sum( array_column( $list, 'v1' ) ) / count( $list ), 1 );
			default:
				return array_sum( array_column( $list, 'v1' ) ) / count( $list );
		}
	}

	private static function members_with_data() {
		global $wpdb;
		$ids = array();
		foreach ( array( 'readings', 'goals', 'food_log' ) as $suffix ) {
			$found = $wpdb->get_col( 'SELECT DISTINCT member_id FROM ' . LLD_Members_Stage3_Schema::table( $suffix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
			foreach ( is_array( $found ) ? $found : array() as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		return array_keys( $ids );
	}

	private static function timezone( $member_id ) {
		$name = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::timezone( $member_id ) : 'UTC';
		try {
			return new DateTimeZone( $name ? $name : 'UTC' );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * The member's local calendar day of a stored UTC time (ENG-09, ENG-10): a reading saved at 23:50 local on D−1
	 * by a member in UTC+10 (stored D−1 13:50 UTC) counts on D−1; a night's sleep is dated by its wake-up time.
	 *
	 * @param string $utc       Y-m-d H:i:s in UTC.
	 * @param string $zone_name IANA time zone.
	 */
	public static function local_date( $utc, $zone_name ) {
		try {
			$zone = new DateTimeZone( $zone_name ? $zone_name : 'UTC' );
		} catch ( Exception $e ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		return self::local( $utc, $zone )->format( 'Y-m-d' );
	}

	private static function local( $utc, DateTimeZone $zone ) {
		$at = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $utc, new DateTimeZone( 'UTC' ) );
		if ( ! $at ) {
			$at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}
		return $at->setTimezone( $zone );
	}

	/** The next lower of the plan rates 0.25, 0.5, 0.75 and 1.0 kg a week (P3-55 RATES); 0.25 stays. */
	public static function rate_one_step_lower( $rate ) {
		$lower = 0.25;
		foreach ( array( 0.25, 0.5, 0.75, 1.0 ) as $step ) {
			if ( $step < $rate - 1e-9 ) {
				$lower = $step;
			}
		}
		return $lower;
	}

	/** 0 Monday … 6 Sunday from a number or a day name; null when unset. */
	public static function weekday_key( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return max( 0, min( 6, (int) $value ) );
		}
		$names = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		$i     = array_search( strtolower( substr( (string) $value, 0, 3 ) ), array_map( function ( $n ) { return substr( $n, 0, 3 ); }, $names ), true );
		return false === $i ? null : $i;
	}
}
