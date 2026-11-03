<?php
/**
 * The decision engine (P3-34; FR3-17, FR3-12, FR3-14, FR3-15, NFR3-17): a pure evaluator of the signed rule
 * catalogue (LLD_Members_Rule_Catalogue) over one member's state for one run date.
 *
 * No WordPress, no database, no clock: the runner builds the state from the Stage 3 tables and decides what to
 * store. Every rule follows the catalogue's conventions (section 1): the member's local calendar days, one daily
 * value per reading type, windows of "the last N days ending yesterday" unless the rule says otherwise, days
 * without data ignored and never filled, and a rule silent below its minimum data.
 *
 * State (every date a local Y-m-d; every list keyed by date where it says "by day"):
 *   today                 the run date D
 *   premium               bool
 *   situations            safety-matrix situations in force: eating_disorder, pregnancy, kidney, diabetes,
 *                         blood_pressure, heart_rate_medicine, heart_disease, age_65_plus, bmi_under_18_5, unknown
 *   goal                  null or goal_type, measure, target_value, target_value_2, unit, target_date, reached,
 *                         rate_kg_week
 *   plan                  null or started, weigh_in_weekday (0 Monday … 6 Sunday, or null), activity_minutes_week,
 *                         calorie_target, cap_kg_week, track
 *   targets               null or fibre_g, protein_g
 *   daily                 blood_pressure (by day: [sys, dia]), weight, waist, sleep, activity (moderate-equivalent
 *                         minutes), mood (mean, 1 dp), glucose, water
 *   readings_today        blood_pressure: a list of [sys, dia] single readings saved today
 *   urgent_dates          days with an urgent blood-pressure reading
 *   log_dates             days with any reading or food entry
 *   food                  by day: meals, fibre_g, protein_g
 *   phq2_today            null or the PHQ-2 score from a Sleep and Stress check today
 *   check_ins             list of date, projected_late_days, weigh_ins_28 (most recent last)
 *   last_check_in         date or null; check_in_done_this_week bool; at_check_in bool (this run is the check-in)
 *   shown                 rule name => date it was last shown
 *   insights_shown_dates  dates on which an insight (quota class) was shown
 *   milestones_shown      streak milestones already shown
 *   gen_shown             date T-GEN-01 was last shown, or null
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * evaluate() runs the whole night; rule() runs one rule for the fixtures.
 */
class LLD_Members_Decision_Engine {

	const VERSION = '1.0';

	const OUTCOME_SHOWN      = 'shown';
	const OUTCOME_APPLIED    = 'applied';
	const OUTCOME_OFFERED    = 'offered';
	const OUTCOME_COOLDOWN   = 'cooldown';
	const OUTCOME_QUOTA      = 'quota';
	const OUTCOME_SUPPRESSED = 'suppressed';
	const OUTCOME_PRIORITY   = 'lower_priority';
	const OUTCOME_PREMIUM    = 'premium_required';
	const OUTCOME_SILENCED   = 'silenced';
	const OUTCOME_NOT_NOW    = 'not_at_check_in';

	const STREAK_MILESTONES = array( 14, 30, 60, 100 );
	const GEN_DAYS          = 14;

	/**
	 * Run every rule for one night and apply the precedence (catalogue section 1).
	 *
	 * @param array $state See the class comment.
	 * @return array date, rules (name => fires, reason, values), safety[], suppressed (bool), today_step,
	 *               adjustments[], insight (the one shown, or null), insights[] (every candidate with its outcome),
	 *               events[].
	 */
	public static function evaluate( array $state ) {
		$state = self::normalise( $state );
		$D     = $state['today'];
		$out   = array(
			'date'        => $D,
			'version'     => self::VERSION,
			'rules'       => array(),
			'safety'      => array(),
			'suppressed'  => false,
			'today_step'  => null,
			'adjustments' => array(),
			'insight'     => null,
			'insights'    => array(),
			'events'      => array(),
		);

		// 1. Safety rules: first, always shown, free (FR3-69).
		foreach ( array( 'R-SAFE-01', 'R-SAFE-01p', 'R-SAFE-03', 'R-SAFE-04' ) as $name ) {
			$r                     = self::rule( $name, $state );
			$out['rules'][ $name ] = $r;
			if ( ! $r['fires'] ) {
				continue;
			}
			$out['safety'][] = self::outcome( $name, $r );
		}
		$out['rules']['R-SAFE-02'] = array( 'fires' => false, 'reason' => 'at_save_time', 'values' => array() );

		// R-SAFE-04 suppresses every non-safety insight and adjustment for 7 days (D3-44, P3-03 low-mood row).
		$low_mood_now     = $out['rules']['R-SAFE-04']['fires'];
		$low_mood_recent  = isset( $state['shown']['R-SAFE-04'] ) && self::days_between( $state['shown']['R-SAFE-04'], $D ) < LLD_Members_Rule_Catalogue::LOW_MOOD_DAYS;
		$out['suppressed'] = $low_mood_now || $low_mood_recent;

		// 2. Today step: exactly one, first condition in priority order (catalogue section 3).
		if ( $out['suppressed'] ) {
			$rule               = LLD_Members_Rule_Catalogue::rule( 'R-SAFE-04' );
			$out['today_step']  = array(
				'rule'     => 'R-SAFE-04',
				'id'       => $rule['id'],
				'class'    => LLD_Members_Rule_Catalogue::CLASS_STEP,
				'template' => $rule['step_template'],
				'text'     => LLD_Members_Rule_Catalogue::fill( $rule['step_template'], array() ),
				'values'   => array(),
				'action'   => 'activity',
				'outcome'  => self::OUTCOME_SHOWN,
				'notes'    => array( 'no_streak_penalty_for_skip' ),
			);
		}
		foreach ( array( 'R-STEP-01', 'R-STEP-02', 'R-STEP-03', 'R-STEP-04', 'R-STEP-05' ) as $name ) {
			$r                     = self::rule( $name, $state );
			$out['rules'][ $name ] = $r;
			if ( $r['fires'] && null === $out['today_step'] ) {
				$o                 = self::outcome( $name, $r );
				$o['action']       = LLD_Members_Rule_Catalogue::rule( $name )['action'];
				$out['today_step'] = $o;
			}
		}
		if ( null === $out['today_step'] ) {
			$out['today_step'] = self::fallback_step( $state );
		}

		// 3. Plan adjustments (Premium): R-ADJ-03 applies automatically on any night (D3-46); the others are offered
		//    at the weekly check-in only and applied only when the member accepts (FR3-14).
		foreach ( array( 'R-ADJ-03', 'R-ADJ-01', 'R-ADJ-02' ) as $name ) {
			$rule                  = LLD_Members_Rule_Catalogue::rule( $name );
			$r                     = self::rule( $name, $state );
			$out['rules'][ $name ] = $r;
			if ( ! $r['fires'] ) {
				if ( 'cooldown' === $r['reason'] ) {
					$out['adjustments'][] = self::waiting( $name, self::OUTCOME_COOLDOWN );
				}
				continue;
			}
			$o = self::outcome( $name, $r );
			if ( ! $state['premium'] ) {
				$o['outcome'] = self::OUTCOME_PREMIUM;
			} elseif ( $out['suppressed'] ) {
				$o['outcome'] = self::OUTCOME_SUPPRESSED;
			} elseif ( ! empty( $rule['at_check_in'] ) && ! $state['at_check_in'] ) {
				$o['outcome'] = self::OUTCOME_NOT_NOW;
			} else {
				$o['outcome'] = ! empty( $rule['automatic'] ) ? self::OUTCOME_APPLIED : self::OUTCOME_OFFERED;
			}
			$out['adjustments'][] = $o;
		}

		// 4. Insights (Premium): priority order, 1 a night, 3 per rolling 7 days, cooldown per rule (FR3-17 AS-03).
		$quota_used = 0;
		foreach ( $state['insights_shown_dates'] as $date ) {
			$gap = self::days_between( $date, $D );
			if ( $gap >= 0 && $gap < LLD_Members_Rule_Catalogue::QUOTA_WINDOW_DAYS ) {
				$quota_used++;
			}
		}
		$chosen = false;
		foreach ( array( 'R-INS-01', 'R-INS-02', 'R-INS-03', 'R-INS-04', 'R-INS-05', 'R-INS-06', 'R-INS-07', 'R-INS-08', 'R-INS-09' ) as $name ) {
			$r                     = self::rule( $name, $state );
			$out['rules'][ $name ] = $r;
			if ( ! $r['fires'] ) {
				if ( 'cooldown' === $r['reason'] ) {
					$out['insights'][] = self::waiting( $name, self::OUTCOME_COOLDOWN ); // listed, never shown (ENG-03)
				}
				continue;
			}
			$o = self::outcome( $name, $r );
			if ( ! $state['premium'] ) {
				$o['outcome'] = self::OUTCOME_PREMIUM;   // nothing stored, nothing shown; the Insights area shows the upgrade line (ENG-05)
			} elseif ( $out['suppressed'] ) {
				$o['outcome'] = self::OUTCOME_SUPPRESSED;
			} elseif ( $quota_used >= LLD_Members_Rule_Catalogue::QUOTA_PER_WEEK ) {
				$o['outcome'] = self::OUTCOME_QUOTA;
			} elseif ( $chosen ) {
				$o['outcome'] = self::OUTCOME_PRIORITY;  // re-evaluated next night, never queued with stale numbers
			} else {
				$o['outcome']   = self::OUTCOME_SHOWN;
				$chosen         = true;
				$out['insight'] = $o;
			}
			$out['insights'][] = $o;
		}

		// 5. Goal reached (FR3-15): an event outside the quota.
		$r                         = self::rule( 'R-INS-10', $state );
		$out['rules']['R-INS-10']  = $r;
		if ( $r['fires'] ) {
			$o            = self::outcome( 'R-INS-10', $r );
			$o['outcome'] = $out['suppressed'] ? self::OUTCOME_SUPPRESSED : self::OUTCOME_SHOWN;
			$out['events'][] = $o;
		}
		return $out;
	}

	/**
	 * Evaluate one rule against the state, with its safety check applied (the matrix silences it for the member)
	 * but without the night's precedence, quota or cooldown. For the fixtures (NFR3-17 AS-01).
	 *
	 * @param string $name  Rule name, R-….
	 * @param array  $state See the class comment.
	 * @return array fires, reason, values
	 */
	public static function rule( $name, array $state ) {
		$state = self::normalise( $state );
		$rule  = LLD_Members_Rule_Catalogue::rule( $name );
		if ( ! $rule ) {
			return self::no( 'unknown_rule' );
		}
		if ( ! empty( $rule['at_save'] ) ) {
			return self::no( 'at_save_time' );
		}
		foreach ( isset( $rule['requires'] ) ? $rule['requires'] : array() as $flag ) {
			if ( ! in_array( $flag, $state['situations'], true ) ) {
				return self::no( 'not_' . $flag );
			}
		}
		foreach ( $rule['silenced_by'] as $flag ) {
			if ( in_array( $flag, $state['situations'], true ) ) {
				return self::no( 'safety_check:' . $flag );
			}
		}
		if ( in_array( 'eating_disorder', $state['situations'], true ) && array_intersect( $rule['tags'], array( 'calorie', 'weight', 'portion' ) ) ) {
			return self::no( 'safety_check:eating_disorder' );
		}
		if ( self::in_cooldown( $name, $state ) ) {
			return self::no( 'cooldown' ); // the same rule is not repeated within its cooldown (FR3-17 AS-03)
		}
		$method = 'rule_' . strtolower( str_replace( '-', '_', $name ) );
		return self::{$method}( $state );
	}

	// ---- Safety rules (catalogue section 2) ----------------------------------------------------------------------

	private static function rule_r_safe_01( array $s ) {
		$D  = $s['today'];
		$w1 = self::bp_window( $s, -14, -8 );
		$w2 = self::bp_window( $s, -7, -1 );
		if ( $w1['days'] < 3 || $w2['days'] < 3 ) {
			return self::no( 'min_data' );
		}
		$above = function ( $w ) {
			return $w['sys'] >= 135 || $w['dia'] >= 85;
		};
		if ( ! $above( $w1 ) || ! $above( $w2 ) ) {
			return self::no( 'only_one_window' );
		}
		foreach ( $s['urgent_dates'] as $date ) {
			$gap = self::days_between( $date, $D );
			if ( $gap >= 1 && $gap <= 14 ) {
				return self::no( 'urgent_shown_instead' );
			}
		}
		$all = self::bp_window( $s, -14, -1 );
		return self::yes( array( 'sys' => (int) round( $all['sys'] ), 'dia' => (int) round( $all['dia'] ) ) );
	}

	private static function rule_r_safe_01p( array $s ) {
		$readings = isset( $s['readings_today']['blood_pressure'] ) ? $s['readings_today']['blood_pressure'] : array();
		foreach ( $readings as $r ) {
			if ( isset( $r[0], $r[1] ) && ( (float) $r[0] >= 140 || (float) $r[1] >= 90 ) ) {
				return self::yes( array() );
			}
		}
		return self::no( 'below_140_90' );
	}

	private static function rule_r_safe_03( array $s ) {
		if ( $s['goal'] && 'lose_weight' === $s['goal']['goal_type'] && ! $s['goal']['reached'] ) {
			return self::no( 'active_lose_weight_goal' );
		}
		$w = $s['daily']['weight'];
		if ( ! $w ) {
			return self::no( 'min_data' );
		}
		krsort( $w );
		$last_date  = key( $w );
		$last_value = (float) current( $w );
		foreach ( array( array( 10, 7, 2.0 ), array( 35, 28, 5.0 ) ) as $test ) {
			list( $far, $near, $min_pct ) = $test;
			$found = null;
			foreach ( $w as $date => $value ) {
				$gap = self::days_between( $date, $last_date );
				if ( $gap >= $near && $gap <= $far ) {
					$found = array( $date, (float) $value );
					break; // krsort: the nearest reading in the range comes first
				}
			}
			if ( ! $found || $found[1] <= 0 ) {
				continue;
			}
			$pct = ( $found[1] - $last_value ) / $found[1] * 100;
			if ( $pct >= $min_pct - 1e-9 ) {
				return self::yes( array( 'pct' => round( $pct, 1 ), 'date' => $found[0] ) );
			}
		}
		return self::no( 'below_minimum_difference' );
	}

	private static function rule_r_safe_04( array $s ) {
		if ( null !== $s['phq2_today'] && (int) $s['phq2_today'] >= 3 ) {
			return self::yes( array( 'trigger' => 'phq2' ) );
		}
		$mood = $s['daily']['mood'];
		foreach ( array( 0, -1 ) as $end ) {
			$run = 0;
			for ( $i = 0; $i < 3; $i++ ) {
				$date = self::add_days( $s['today'], $end - $i );
				if ( ! isset( $mood[ $date ] ) || null === $mood[ $date ] || (float) $mood[ $date ] > 2 ) {
					break;
				}
				$run++;
			}
			if ( 3 === $run ) {
				return self::yes( array( 'trigger' => 'mood' ) );
			}
		}
		return self::no( 'no_three_consecutive_low_days' );
	}

	// ---- Today steps (catalogue section 3) -----------------------------------------------------------------------

	private static function rule_r_step_01( array $s ) {
		$bp_goal = $s['goal'] && 'lower_blood_pressure' === $s['goal']['goal_type'];
		$hh      = $s['plan'] && 'heart-health' === $s['plan']['track'];
		if ( ! $bp_goal && ! $hh ) {
			return self::no( 'no_blood_pressure_goal' );
		}
		if ( self::has_any( $s['daily']['blood_pressure'], $s['today'], -7, 0 ) ) {
			return self::no( 'reading_within_8_days' );
		}
		$r = self::yes( array() );
		if ( in_array( 'pregnancy', $s['situations'], true ) ) {
			$r['variant'] = 'T-STEP-01p'; // "share it with your midwife": wording not written out in the catalogue (approval limitation)
		}
		return $r;
	}

	private static function rule_r_step_02( array $s ) {
		if ( ! $s['plan'] || null === $s['plan']['weigh_in_weekday'] ) {
			return self::no( 'no_weigh_in_in_plan' );
		}
		if ( self::has_any( $s['daily']['weight'], $s['today'], -6, 0 ) ) {
			return self::no( 'weight_within_7_days' );
		}
		if ( self::weekday( $s['today'] ) < (int) $s['plan']['weigh_in_weekday'] ) {
			return self::no( 'before_weigh_in_day' );
		}
		return self::yes( array() );
	}

	private static function rule_r_step_03( array $s ) {
		if ( ! $s['plan'] ) {
			return self::no( 'no_plan' );
		}
		$ref = $s['last_check_in'] ? $s['last_check_in'] : $s['plan']['started'];
		if ( ! $ref || self::days_between( $ref, $s['today'] ) < 7 ) {
			return self::no( 'under_7_days' );
		}
		if ( $s['check_in_done_this_week'] ) {
			return self::no( 'done_this_week' );
		}
		return self::yes( array() );
	}

	private static function rule_r_step_04( array $s ) {
		if ( ! $s['plan'] || ! $s['plan']['started'] || (int) $s['plan']['activity_minutes_week'] < 1 ) {
			return self::no( 'no_activity_target' );
		}
		$week_day = self::plan_week_day( $s );
		if ( $week_day < 5 ) {
			return self::no( 'before_day_5' );
		}
		$target = (int) $s['plan']['activity_minutes_week'];
		$done   = (int) round( self::sum_window( $s['daily']['activity'], $s['today'], 1 - $week_day, 0 ) );
		if ( $done >= 0.5 * $target ) {
			return self::no( 'not_below_half' );
		}
		$days_left = 8 - $week_day;
		$suggest   = (int) max( 10, min( 30, ceil( ( $target - $done ) / $days_left / 5 ) * 5 ) );
		$r         = self::yes( array( 'done' => $done, 'target' => $target, 'suggest' => $suggest ) );
		if ( array_intersect( array( 'heart_disease', 'blood_pressure' ), $s['situations'] ) ) {
			$r['notes'][] = 'check_with_doctor_before_intense_exercise'; // moderate activity only
		}
		return $r;
	}

	private static function rule_r_step_05( array $s ) {
		foreach ( $s['log_dates'] as $date ) {
			$gap = self::days_between( $date, $s['today'] );
			if ( $gap >= 1 && $gap <= 3 ) {
				return self::no( 'logged_within_3_days' );
			}
		}
		return self::yes( array() );
	}

	/**
	 * After the rules: the plan's scheduled item for the day, else T-GEN-01 at most once a week.
	 */
	private static function fallback_step( array $s ) {
		if ( $s['plan'] ) {
			return array( 'rule' => null, 'source' => 'plan', 'template' => null, 'text' => null, 'values' => array(), 'outcome' => self::OUTCOME_SHOWN );
		}
		if ( $s['gen_shown'] && self::days_between( $s['gen_shown'], $s['today'] ) < 7 ) {
			return null;
		}
		return array(
			'rule'     => null,
			'source'   => 'generic',
			'id'       => 0,
			'template' => 'template.T-GEN-01',
			'text'     => LLD_Members_Rule_Catalogue::fill( 'template.T-GEN-01', array( 'n' => self::GEN_DAYS ) ),
			'values'   => array( 'n' => self::GEN_DAYS ),
			'action'   => 'read',
			'outcome'  => self::OUTCOME_SHOWN,
		);
	}

	// ---- Plan adjustments (catalogue section 4) ------------------------------------------------------------------

	private static function rule_r_adj_01( array $s ) {
		if ( ! self::lose_weight_goal( $s ) ) {
			return self::no( 'no_lose_weight_goal' );
		}
		$n = count( $s['check_ins'] );
		if ( $n < 2 ) {
			return self::no( 'needs_two_check_ins' );
		}
		$last = array_slice( $s['check_ins'], -2 );
		foreach ( $last as $c ) {
			if ( (int) $c['weigh_ins_28'] < 3 ) {
				return self::no( 'min_data' );
			}
			if ( (int) $c['projected_late_days'] <= 7 ) {
				return self::no( 'not_over_7_days_late' );
			}
		}
		$goal   = $s['goal'];
		$weight = self::latest( $s['daily']['weight'] );
		$cap    = self::rate_cap( $s );
		$rate   = min( $goal['rate_kg_week'] > 0 ? (float) $goal['rate_kg_week'] : $cap, $cap );
		if ( null === $weight || $rate <= 0 || null === $goal['target_value'] ) {
			return self::no( 'min_data' );
		}
		$remaining = max( 0, (float) $weight - (float) $goal['target_value'] );
		$current   = $last[1];
		$values    = array(
			'goal'      => (float) $goal['target_value'],
			'projected' => $goal['target_date'] ? self::add_days( $goal['target_date'], (int) $current['projected_late_days'] ) : '',
			'target'    => (string) $goal['target_date'],
			'new_date'  => self::add_days( $s['today'], (int) ceil( $remaining / $rate ) * 7 ),
		);
		$r = self::yes( $values );
		$r['offers'] = array( 'later_date', 'easier_activity_25pct_one_week', 'different_meal_plan' );
		return $r;
	}

	private static function rule_r_adj_02( array $s ) {
		if ( ! self::lose_weight_goal( $s ) ) {
			return self::no( 'no_lose_weight_goal' );
		}
		if ( $s['goal']['reached'] ) {
			return self::no( 'goal_reached' );
		}
		$values = self::values_window( $s['daily']['weight'], $s['today'], -21, -1 );
		if ( count( $values ) < 4 ) {
			return self::no( 'min_data' );
		}
		if ( round( max( $values ) - min( $values ), 3 ) > 0.5 ) {
			return self::no( 'range_above_0_5_kg' );
		}
		$r = self::yes( array( 'weight' => round( array_sum( $values ) / count( $values ), 1 ) ) );
		$r['offers'] = array( 'log_every_meal_7_days', 'different_meal_plan' ); // never a lower calorie target (D3-45)
		return $r;
	}

	private static function rule_r_adj_03( array $s ) {
		if ( ! self::lose_weight_goal( $s ) ) {
			return self::no( 'no_lose_weight_goal' );
		}
		$cap     = self::rate_cap( $s );
		$points  = self::points_window( $s['daily']['weight'], $s['today'], -28, -1 );
		$slope   = count( $points ) >= 4 ? self::loss_per_week( $points ) : null;
		$too_fast = null !== $slope && $slope > 1.25 * $cap + 1e-9;
		$seven   = self::seven_day_loss_pct( $s['daily']['weight'], $s['today'] );
		$doctor  = null !== $seven && $seven > 2.0;
		if ( ! $too_fast && ! $doctor ) {
			return self::no( null === $slope ? 'min_data' : 'within_safe_rate' );
		}
		if ( ! $s['plan'] || (int) $s['plan']['calorie_target'] < 1 ) {
			return self::no( 'no_calorie_target' );
		}
		$r = self::yes( array( 'cap' => round( $cap, 2 ), 'kcal' => (int) $s['plan']['calorie_target'] + 250 ) );
		$r['slope_kg_week'] = null === $slope ? null : round( $slope, 3 );
		if ( $doctor ) {
			$r['notes'][] = 'doctor_line';
		}
		return $r;
	}

	// ---- Insights (catalogue section 5) --------------------------------------------------------------------------

	private static function rule_r_ins_01( array $s ) {
		$short = array();
		$long  = array();
		$paired = 0;
		for ( $i = -42; $i <= -1; $i++ ) {
			$date = self::add_days( $s['today'], $i );
			if ( ! isset( $s['daily']['sleep'][ $date ], $s['daily']['blood_pressure'][ $date ] ) ) {
				continue;
			}
			$paired++;
			$h  = (float) $s['daily']['sleep'][ $date ];
			$bp = $s['daily']['blood_pressure'][ $date ];
			if ( $h < 6 ) {
				$short[] = $bp;
			} elseif ( $h >= 7 ) {
				$long[] = $bp;
			}
		}
		if ( $paired < 14 || count( $short ) < 4 || count( $long ) < 4 ) {
			return self::no( 'min_data' );
		}
		$ms = self::bp_mean( $short );
		$ml = self::bp_mean( $long );
		if ( $ms['sys'] - $ml['sys'] < 5 ) {
			return self::no( 'difference_below_5' );
		}
		$r = self::yes( array( 'bp_short' => self::bp_text( $ms ), 'bp_long' => self::bp_text( $ml ) ) );
		if ( in_array( 'blood_pressure', $s['situations'], true ) ) {
			$r['notes'][] = 'on_medicine_line'; // shown to members on blood-pressure medicine with the on-medicine line
		}
		return $r;
	}

	private static function rule_r_ins_02( array $s ) {
		$now    = self::bp_window( $s, -28, -1 );
		$before = self::bp_window( $s, -56, -29 );
		if ( $now['days'] < 12 || $before['days'] < 12 ) {
			return self::no( 'min_data' );
		}
		foreach ( $s['urgent_dates'] as $date ) {
			$gap = self::days_between( $date, $s['today'] );
			if ( $gap >= 1 && $gap <= 56 ) {
				return self::no( 'urgent_reading_in_window' );
			}
		}
		if ( $before['sys'] - $now['sys'] < 5 ) {
			return self::no( 'difference_below_5' );
		}
		return self::yes( array( 'now' => self::bp_text( $now ), 'before' => self::bp_text( $before ) ) );
	}

	private static function rule_r_ins_03( array $s ) {
		$active   = array();
		$inactive = array();
		for ( $i = -28; $i <= -1; $i++ ) {
			$date = self::add_days( $s['today'], $i );
			if ( ! isset( $s['daily']['mood'][ $date ] ) ) {
				continue;
			}
			$minutes = isset( $s['daily']['activity'][ $date ] ) ? (float) $s['daily']['activity'][ $date ] : 0.0;
			if ( $minutes >= 20 ) {
				$active[] = (float) $s['daily']['mood'][ $date ];
			} elseif ( $minutes <= 0 ) {
				$inactive[] = (float) $s['daily']['mood'][ $date ];
			}
		}
		if ( count( $active ) < 7 || count( $inactive ) < 7 ) {
			return self::no( 'min_data' );
		}
		$ma = array_sum( $active ) / count( $active );
		$mi = array_sum( $inactive ) / count( $inactive );
		if ( $ma - $mi < 0.7 - 1e-9 ) {
			return self::no( 'difference_below_0_7' );
		}
		return self::yes( array( 'mood_active' => round( $ma, 1 ), 'mood_rest' => round( $mi, 1 ) ) );
	}

	private static function rule_r_ins_04( array $s ) {
		$last = self::values_window( $s['daily']['sleep'], $s['today'], -7, -1 );
		$prev = self::values_window( $s['daily']['sleep'], $s['today'], -14, -8 );
		if ( count( $last ) < 5 || count( $prev ) < 5 ) {
			return self::no( 'min_data' );
		}
		$hours  = array_sum( $last ) / count( $last );
		$before = array_sum( $prev ) / count( $prev );
		if ( $hours >= 6.0 ) {
			return self::no( 'not_below_6' );
		}
		if ( $before < 6.5 ) {
			return self::no( 'previous_week_below_6_5' );
		}
		return self::yes( array( 'hours' => round( $hours, 1 ), 'before' => round( $before, 1 ) ) );
	}

	private static function rule_r_ins_05( array $s ) {
		$weeks = self::plan_weeks( $s, 4 );
		foreach ( $weeks as $w ) {
			if ( self::sum_window( $s['daily']['activity'], $w[0], 0, 6 ) < 150 ) {
				return self::no( 'a_week_below_150' );
			}
		}
		return self::yes( array() );
	}

	private static function rule_r_ins_06( array $s ) {
		return self::nutrient_rule( $s, 'fibre_g', 0.60, 'fibre' );
	}

	private static function rule_r_ins_07( array $s ) {
		return self::nutrient_rule( $s, 'protein_g', 0.80, 'protein' );
	}

	private static function nutrient_rule( array $s, $field, $fraction, $gap ) {
		$target = $s['targets'] && isset( $s['targets'][ $field ] ) ? (float) $s['targets'][ $field ] : 0.0;
		if ( $target <= 0 ) {
			return self::no( 'no_target' );
		}
		// The last 7 logged days ending yesterday (days with any food entry), of which at least 5 have 2 or more meals.
		$days   = array();
		$under  = 0;
		$logged = 0;
		for ( $i = -1; $i >= -120 && $logged < 7; $i-- ) {
			$date = self::add_days( $s['today'], $i );
			if ( ! isset( $s['food'][ $date ] ) ) {
				continue;
			}
			$logged++;
			if ( (int) $s['food'][ $date ]['meals'] < 2 ) {
				continue;
			}
			$value  = isset( $s['food'][ $date ][ $field ] ) ? (float) $s['food'][ $date ][ $field ] : 0.0;
			$days[] = $value;
			if ( $value < $fraction * $target ) {
				$under++;
			}
		}
		if ( count( $days ) < 5 ) {
			return self::no( 'min_data' );
		}
		if ( $under < 5 ) {
			return self::no( 'fewer_than_5_days_under' );
		}
		return self::yes( array( $gap => (int) round( array_sum( $days ) / count( $days ) ), 'target' => (int) round( $target ) ) );
	}

	private static function rule_r_ins_08( array $s ) {
		$waist = self::points_window( $s['daily']['waist'], $s['today'], -56, 0 );
		if ( count( $waist ) < 2 ) {
			return self::no( 'min_data' );
		}
		ksort( $waist );
		$last_date  = array_key_last( $waist );
		$last_value = (float) $waist[ $last_date ];
		$first      = null;
		foreach ( $waist as $date => $value ) {
			if ( self::days_between( $date, $last_date ) >= 21 ) {
				$first = array( $date, (float) $value );
				break;
			}
		}
		if ( ! $first ) {
			return self::no( 'min_data' );
		}
		$fall = round( $first[1] - $last_value, 1 );
		if ( $fall < 2.0 ) {
			return self::no( 'waist_fall_below_2_cm' );
		}
		$w_start = self::nearest( $s['daily']['weight'], $first[0], 3 );
		$w_end   = self::nearest( $s['daily']['weight'], $last_date, 3 );
		if ( null === $w_start || null === $w_end ) {
			return self::no( 'min_data' );
		}
		if ( abs( $w_end - $w_start ) >= 1.0 ) {
			return self::no( 'weight_changed_1_kg_or_more' );
		}
		return self::yes( array( 'cm' => $fall, 'date' => $first[0] ) );
	}

	private static function rule_r_ins_09( array $s ) {
		$streak = self::streak( $s['log_dates'], self::add_days( $s['today'], -1 ) );
		$hit    = null;
		$top    = $s['milestones_shown'] ? max( array_map( 'intval', $s['milestones_shown'] ) ) : 0;
		foreach ( self::STREAK_MILESTONES as $m ) {
			if ( $streak >= $m && $m > $top ) {
				$hit = $m; // the highest one not yet shown; every lower milestone counts as shown with it
			}
		}
		if ( null === $hit ) {
			return self::no( $streak >= 14 ? 'milestone_already_shown' : 'below_14_days' );
		}
		$r              = self::yes( array( 'days' => $streak ) );
		$r['milestone'] = $hit;
		return $r;
	}

	private static function rule_r_ins_10( array $s ) {
		$g = $s['goal'];
		if ( ! $g || $g['reached'] || null === $g['target_value'] ) {
			return self::no( $g && $g['reached'] ? 'already_reached' : 'no_goal' );
		}
		$target = (float) $g['target_value'];
		$met    = false;
		$measure = $g['measure'] ? $g['measure'] : $g['goal_type'];
		switch ( $measure ) {
			case 'weight':
			case 'lose_weight':
				$v = self::values_window( $s['daily']['weight'], $s['today'], -7, -1 );
				if ( count( $v ) < 3 ) {
					return self::no( 'min_data' );
				}
				$met = array_sum( $v ) / count( $v ) <= $target;
				break;
			case 'blood_pressure':
			case 'lower_blood_pressure':
				$w = self::bp_window( $s, -7, -1 );
				if ( $w['days'] < 3 ) {
					return self::no( 'min_data' );
				}
				$met = $w['sys'] <= $target && ( null === $g['target_value_2'] || $w['dia'] <= (float) $g['target_value_2'] );
				break;
			case 'activity_minutes':
			case 'activity':
				$weeks = self::plan_weeks( $s, 2 );
				$met   = count( $weeks ) === 2;
				foreach ( $weeks as $w ) {
					$met = $met && self::sum_window( $s['daily']['activity'], $w[0], 0, 6 ) >= $target;
				}
				break;
			case 'sleep_hours':
			case 'sleep':
				$v = self::values_window( $s['daily']['sleep'], $s['today'], -7, -1 );
				if ( count( $v ) < 5 ) {
					return self::no( 'min_data' );
				}
				$met = array_sum( $v ) / count( $v ) >= $target;
				break;
			default:
				return self::no( 'measure_not_judged' );
		}
		if ( ! $met ) {
			return self::no( 'target_not_met' );
		}
		$unit = $g['unit'] ? ' ' . $g['unit'] : '';
		return self::yes( array( 'goal' => rtrim( rtrim( number_format( $target, 2, '.', '' ), '0' ), '.' ) . $unit ) );
	}

	// ---- Shared arithmetic ---------------------------------------------------------------------------------------

	/**
	 * Daily means of blood pressure over a window of day offsets from D.
	 *
	 * @return array{days:int,sys:float,dia:float}
	 */
	private static function bp_window( array $s, $from, $to ) {
		$rows = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$date = self::add_days( $s['today'], $i );
			if ( isset( $s['daily']['blood_pressure'][ $date ] ) ) {
				$rows[] = $s['daily']['blood_pressure'][ $date ];
			}
		}
		$m = self::bp_mean( $rows );
		return array( 'days' => count( $rows ), 'sys' => $m['sys'], 'dia' => $m['dia'] );
	}

	private static function bp_mean( array $rows ) {
		if ( ! $rows ) {
			return array( 'sys' => 0.0, 'dia' => 0.0 );
		}
		$sys = 0.0;
		$dia = 0.0;
		foreach ( $rows as $r ) {
			$sys += (float) $r[0];
			$dia += (float) $r[1];
		}
		return array( 'sys' => $sys / count( $rows ), 'dia' => $dia / count( $rows ) );
	}

	/** "137/87": the daily means averaged and shown whole (ENG-08). */
	private static function bp_text( array $m ) {
		return (int) round( $m['sys'] ) . '/' . (int) round( $m['dia'] );
	}

	private static function has_any( array $by_day, $D, $from, $to ) {
		for ( $i = $from; $i <= $to; $i++ ) {
			if ( isset( $by_day[ self::add_days( $D, $i ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function values_window( array $by_day, $D, $from, $to ) {
		$out = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$date = self::add_days( $D, $i );
			if ( isset( $by_day[ $date ] ) ) {
				$out[] = (float) $by_day[ $date ];
			}
		}
		return $out;
	}

	private static function points_window( array $by_day, $D, $from, $to ) {
		$out = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$date = self::add_days( $D, $i );
			if ( isset( $by_day[ $date ] ) ) {
				$out[ $date ] = (float) $by_day[ $date ];
			}
		}
		return $out;
	}

	private static function sum_window( array $by_day, $D, $from, $to ) {
		return array_sum( self::values_window( $by_day, $D, $from, $to ) );
	}

	private static function latest( array $by_day ) {
		if ( ! $by_day ) {
			return null;
		}
		krsort( $by_day );
		return (float) reset( $by_day );
	}

	/** The value on the nearest day within ± $within days of $date, or null. */
	private static function nearest( array $by_day, $date, $within ) {
		for ( $d = 0; $d <= $within; $d++ ) {
			foreach ( array( -$d, $d ) as $off ) {
				$key = self::add_days( $date, $off );
				if ( isset( $by_day[ $key ] ) ) {
					return (float) $by_day[ $key ];
				}
			}
		}
		return null;
	}

	/** Least-squares loss in kg per week over dated weights (positive = losing). */
	private static function loss_per_week( array $points ) {
		ksort( $points );
		$first = array_key_first( $points );
		$xs    = array();
		$ys    = array();
		foreach ( $points as $date => $kg ) {
			$xs[] = self::days_between( $first, $date );
			$ys[] = $kg;
		}
		$n  = count( $xs );
		$mx = array_sum( $xs ) / $n;
		$my = array_sum( $ys ) / $n;
		$num = 0.0;
		$den = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$num += ( $xs[ $i ] - $mx ) * ( $ys[ $i ] - $my );
			$den += ( $xs[ $i ] - $mx ) * ( $xs[ $i ] - $mx );
		}
		if ( $den <= 0 ) {
			return 0.0;
		}
		return -( $num / $den ) * 7;
	}

	/**
	 * The largest loss over any 7-day span (6 to 8 days between two readings) in the last 4 weeks, as % of the
	 * earlier weight; null without such a pair.
	 */
	private static function seven_day_loss_pct( array $by_day, $D ) {
		$points = self::points_window( $by_day, $D, -28, 0 );
		ksort( $points );
		$dates = array_keys( $points );
		$max   = null;
		foreach ( $dates as $i => $from ) {
			foreach ( array_slice( $dates, $i + 1 ) as $to ) {
				$gap = self::days_between( $from, $to );
				if ( $gap < 6 || $gap > 8 || $points[ $from ] <= 0 ) {
					continue;
				}
				$pct = ( $points[ $from ] - $points[ $to ] ) / $points[ $from ] * 100;
				if ( null === $max || $pct > $max ) {
					$max = $pct;
				}
			}
		}
		return $max;
	}

	/** Weight-loss rate cap (P3-56): the plan's, else min(1 kg, 1 % of weight), 0.5 kg at 65 and over. */
	private static function rate_cap( array $s ) {
		if ( $s['plan'] && (float) $s['plan']['cap_kg_week'] > 0 ) {
			return (float) $s['plan']['cap_kg_week'];
		}
		$weight = self::latest( $s['daily']['weight'] );
		$cap    = min( 1.0, null === $weight ? 1.0 : 0.01 * $weight );
		if ( in_array( 'age_65_plus', $s['situations'], true ) ) {
			$cap = min( $cap, 0.5 );
		}
		return $cap;
	}

	private static function lose_weight_goal( array $s ) {
		return $s['goal'] && 'lose_weight' === $s['goal']['goal_type'];
	}

	/** Day 1 to 7 of the current plan week (weeks start on the plan's start weekday). */
	private static function plan_week_day( array $s ) {
		$since = self::days_between( $s['plan']['started'], $s['today'] );
		return ( ( $since % 7 ) + 7 ) % 7 + 1;
	}

	/**
	 * The last $n complete plan weeks before the current one, oldest first, as [start, end]. Without a plan, weeks
	 * run Monday to Sunday.
	 */
	private static function plan_weeks( array $s, $n ) {
		if ( $s['plan'] && $s['plan']['started'] ) {
			$start = self::add_days( $s['today'], 1 - self::plan_week_day( $s ) );
		} else {
			$start = self::add_days( $s['today'], -self::weekday( $s['today'] ) );
		}
		$weeks = array();
		for ( $k = $n; $k >= 1; $k-- ) {
			$weeks[] = array( self::add_days( $start, -7 * $k ), self::add_days( $start, -7 * $k + 6 ) );
		}
		return $weeks;
	}

	/** Consecutive logged days ending on $end. */
	private static function streak( array $dates, $end ) {
		$set = array_flip( $dates );
		$n   = 0;
		while ( isset( $set[ self::add_days( $end, -$n ) ] ) ) {
			$n++;
		}
		return $n;
	}

	private static function in_cooldown( $name, array $s ) {
		$rule = LLD_Members_Rule_Catalogue::rule( $name );
		if ( (int) $rule['cooldown_days'] < 1 || ! isset( $s['shown'][ $name ] ) ) {
			return false;
		}
		return self::days_between( $s['shown'][ $name ], $s['today'] ) < (int) $rule['cooldown_days'];
	}

	private static function outcome( $name, array $r ) {
		$rule = LLD_Members_Rule_Catalogue::rule( $name );
		return array(
			'rule'             => $name,
			'id'               => $rule['id'],
			'key'              => $rule['key'],
			'class'            => $rule['class'],
			'priority'         => $rule['priority'],
			'template'         => $rule['template'],
			'text'             => $rule['template'] ? LLD_Members_Rule_Catalogue::fill( $rule['template'], $r['values'] ) : null,
			'values'           => $r['values'],
			'cooldown_days'    => $rule['cooldown_days'],
			'counted_in_quota' => LLD_Members_Rule_Catalogue::CLASS_INSIGHT === $rule['class'],
			'automatic'        => ! empty( $rule['automatic'] ),
			'suppresses_days'  => isset( $rule['suppresses_days'] ) ? $rule['suppresses_days'] : 0,
			'notes'            => isset( $r['notes'] ) ? $r['notes'] : array(),
			'extra'            => array_diff_key( $r, array_flip( array( 'fires', 'reason', 'values', 'notes' ) ) ),
			'outcome'          => self::OUTCOME_SHOWN,
		);
	}

	/** A candidate that could not be evaluated to the end (its cooldown is running): listed without text or values. */
	private static function waiting( $name, $outcome ) {
		$o            = self::outcome( $name, self::no( $outcome ) );
		$o['text']    = null;
		$o['outcome'] = $outcome;
		return $o;
	}

	private static function yes( array $values ) {
		return array( 'fires' => true, 'reason' => '', 'values' => $values, 'notes' => array() );
	}

	private static function no( $reason ) {
		return array( 'fires' => false, 'reason' => $reason, 'values' => array(), 'notes' => array() );
	}

	/** Fill every state key with its empty default so the rules never test for presence. */
	private static function normalise( array $s ) {
		if ( isset( $s['_normalised'] ) ) {
			return $s;
		}
		$defaults = array(
			'today'                   => '',
			'premium'                 => false,
			'situations'              => array(),
			'goal'                    => null,
			'plan'                    => null,
			'targets'                 => null,
			'daily'                   => array(),
			'readings_today'          => array(),
			'urgent_dates'            => array(),
			'log_dates'               => array(),
			'food'                    => array(),
			'phq2_today'              => null,
			'check_ins'               => array(),
			'last_check_in'           => null,
			'check_in_done_this_week' => false,
			'at_check_in'             => false,
			'shown'                   => array(),
			'insights_shown_dates'    => array(),
			'milestones_shown'        => array(),
			'gen_shown'               => null,
		);
		$s = array_merge( $defaults, $s );
		foreach ( array( 'blood_pressure', 'weight', 'waist', 'sleep', 'activity', 'mood', 'glucose', 'water' ) as $type ) {
			if ( ! isset( $s['daily'][ $type ] ) || ! is_array( $s['daily'][ $type ] ) ) {
				$s['daily'][ $type ] = array();
			}
		}
		if ( $s['goal'] ) {
			$s['goal'] = array_merge( array( 'goal_type' => '', 'measure' => '', 'target_value' => null, 'target_value_2' => null, 'unit' => '', 'target_date' => null, 'reached' => false, 'rate_kg_week' => null ), $s['goal'] );
		}
		if ( $s['plan'] ) {
			$s['plan'] = array_merge( array( 'started' => null, 'weigh_in_weekday' => null, 'activity_minutes_week' => null, 'calorie_target' => null, 'cap_kg_week' => null, 'track' => '' ), $s['plan'] );
		}
		$s['situations']  = array_values( array_map( 'strval', (array) $s['situations'] ) );
		$s['_normalised'] = true;
		return $s;
	}

	// ---- Dates (UTC arithmetic on Y-m-d strings; the runner has already put every date in the member's zone) ------

	public static function add_days( $date, $days ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		if ( ! $at ) {
			return '';
		}
		return $at->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
	}

	/** Days from $a to $b (positive when $b is later). */
	public static function days_between( $a, $b ) {
		$za = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $a, new DateTimeZone( 'UTC' ) );
		$zb = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $b, new DateTimeZone( 'UTC' ) );
		if ( ! $za || ! $zb ) {
			return PHP_INT_MAX;
		}
		return (int) round( ( $zb->getTimestamp() - $za->getTimestamp() ) / 86400 );
	}

	/** 0 Monday … 6 Sunday. */
	public static function weekday( $date ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $at ? (int) $at->format( 'N' ) - 1 : 0;
	}
}
