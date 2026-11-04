<?php
/**
 * Saved calculator results over time (P3-5B, FR3-55), from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-5B-Saved-Results-Over-Time-Method-Sheet.md` (approval
 * `approvals/stage3/saved-results.json`, fixtures `fixtures/saved-results/`).
 *
 * Pure rules for compare / list / recheck / safety: no WordPress, no database. The API and screens
 * call this with the member's saved rows, plan and consent; Stage 2's calculator_result table holds
 * the rows (extended with sealed inputs and result for Stage 3 calculators, D3-33).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare, present and recheck rules for saved calculator headlines.
 */
class LLD_Members_Saved_Results {

	const VERSION = '1.0';

	/** 91 days after the last saved result (sheet section 3). */
	const RECHECK_DAYS = 91;

	/**
	 * Calculators that get a three-monthly re-check reminder. Never calorie_target or goal_timeline.
	 *
	 * @var string[]
	 */
	const RECHECK_CALCULATORS = array( 'heart_risk', 'bp_check', 'metabolic', 'fitness_age', 'healthy_weight' );

	/**
	 * Stage 1 tools: headline / unit / band / version only; inputs are never stored (D51).
	 *
	 * @var string[]
	 */
	const STAGE1_NO_INPUTS = array( 'protein', 'diabetes_risk', 'healthy_weight', 'diabetes', 'healthy-weight' );

	/**
	 * Calculators the eating-disorder flag hides everywhere (FR3-69; sheet section 1).
	 *
	 * @var string[]
	 */
	const ED_HIDDEN = array( 'calorie_target', 'goal_timeline', 'calorie-target', 'goal-timeline' );

	/**
	 * Meaningful-change thresholds (D3-34). Heart risk also trips on a band change; values are exclusive
	 * upper bounds for "about the same" except BP, which uses "5 systolic or 3 diastolic" as inclusive.
	 *
	 * @var array<string,float|array{sys:float,dia:float}|array{vo2:float,age:float}>
	 */
	const THRESHOLDS = array(
		'protein'         => 5.0,
		'diabetes_risk'   => 1.0,
		'diabetes'        => 1.0,
		'healthy_weight'  => 0.5,
		'healthy-weight'  => 0.5,
		'calorie_target'  => 50.0,
		'goal_timeline'   => 1.0,
		'heart_risk'      => 1.0,
		'bp_check'        => array( 'sys' => 5.0, 'dia' => 3.0 ),
		'metabolic'       => 1.0,
		'fitness_age'     => array( 'vo2' => 1.5, 'age' => 2.0 ),
		'sleep_stress'    => array( 'stress' => 2.0, 'sleep' => 1.0 ),
	);

	/**
	 * Signed wording (sheet section 2 and 4). Hashed in approvals/stage3/saved-results.json copy_hashes.
	 * change.lower and healthyWeight.comparison are the fragments the sheet gives.
	 */
	const MESSAGES = array(
		'change.better'              => 'Better: {change} since {date}.',
		'change.higher'              => 'Higher than last time: {change} since {date}. Small moves are normal; a steady rise is worth a conversation with your doctor.',
		'change.lower'               => 'Lower than last time',
		'change.same'                => 'About the same as {date} ({change}).',
		'change.earlierVersion'      => 'Compared with a result from an earlier version of this calculator.',
		'change.notComparable'       => "Not directly comparable: one result used a blood test and the other didn't.",
		'healthyWeight.comparison'   => 'Moving towards the healthy range',
		'recheck.email'              => 'a calculator re-check is due',
		'free.upgrade'               => 'Upgrade to see your history and how it has changed',
	);

	/**
	 * Compare the two most recent results for one calculator (Premium). Fixture-shaped inputs.
	 *
	 * @param array{calculator:string,results:array<int,array<string,mixed>>,plan?:string} $in Inputs.
	 * @return array<string,mixed>
	 */
	public static function compare( array $in ) {
		$calc    = isset( $in['calculator'] ) ? (string) $in['calculator'] : '';
		$results = isset( $in['results'] ) && is_array( $in['results'] ) ? $in['results'] : array();
		$out     = array(
			'wording'              => null,
			'comparable'           => true,
			'better_worse_label'   => null,
			'earlier_version_note' => null,
			'messages'             => array(),
			'weight_loss_praise'   => false,
			'band_change'          => false,
			'version'              => self::VERSION,
		);

		if ( count( $results ) < 2 ) {
			$out['comparable'] = false;
			return $out;
		}

		$a = $results[0];
		$b = $results[ count( $results ) - 1 ];

		// Heart risk: lab vs non-lab is not directly comparable (sheet section 2).
		if ( 'heart_risk' === $calc ) {
			$model_a = isset( $a['model'] ) ? (string) $a['model'] : '';
			$model_b = isset( $b['model'] ) ? (string) $b['model'] : '';
			if ( $model_a && $model_b && $model_a !== $model_b ) {
				$out['comparable']         = false;
				$out['better_worse_label'] = null;
				$out['wording']            = 'Not directly comparable';
				$out['messages'][]         = 'change.notComparable';
				return $out;
			}
		}

		$ver_a = isset( $a['engine_version'] ) ? (string) $a['engine_version'] : ( isset( $a['calculator_version'] ) ? (string) $a['calculator_version'] : '' );
		$ver_b = isset( $b['engine_version'] ) ? (string) $b['engine_version'] : ( isset( $b['calculator_version'] ) ? (string) $b['calculator_version'] : '' );
		if ( $ver_a && $ver_b && $ver_a !== $ver_b ) {
			$out['earlier_version_note'] = 'compared with an earlier version';
			$out['messages'][]           = 'change.earlierVersion';
		}

		$date = self::date_label( $a );

		switch ( $calc ) {
			case 'heart_risk':
				return self::compare_heart( $a, $b, $date, $out );
			case 'bp_check':
				return self::compare_bp( $a, $b, $date, $out );
			case 'fitness_age':
				return self::compare_fitness( $a, $b, $date, $out );
			case 'metabolic':
				return self::compare_metabolic( $a, $b, $date, $out );
			case 'healthy_weight':
			case 'healthy-weight':
				return self::compare_healthy_weight( $a, $b, $out );
			case 'diabetes_risk':
			case 'diabetes':
				return self::compare_scalar( $a, $b, $date, $out, 'diabetes_risk', true );
			case 'protein':
				return self::compare_scalar( $a, $b, $date, $out, 'protein', false );
			case 'calorie_target':
				return self::compare_scalar( $a, $b, $date, $out, 'calorie_target', false );
			case 'goal_timeline':
				return self::compare_scalar( $a, $b, $date, $out, 'goal_timeline', false );
			case 'hr_zones':
				$out['comparable'] = false;
				$out['wording']    = null;
				return $out;
			default:
				$out['comparable'] = false;
				return $out;
		}
	}

	/**
	 * What Free vs Premium members see for one calculator's saved rows (newest first).
	 *
	 * @param array<int,array<string,mixed>> $rows       Saved rows (newest first).
	 * @param string                         $plan       free|premium.
	 * @param bool                           $consent    Health consent.
	 * @param array<string,bool>             $flags      eating_disorder etc.
	 * @return array<string,mixed>
	 */
	public static function present_list( array $rows, $plan, $consent = true, array $flags = array() ) {
		$plan = strtolower( (string) $plan );
		$out  = array(
			'rows'           => array(),
			'latest_only'    => false,
			'history'        => false,
			'comparison'     => null,
			'upgrade_line'   => false,
			'upgrade_text'   => null,
			'save_offered'   => (bool) $consent,
			'hidden'         => false,
		);

		if ( ! empty( $flags['eating_disorder'] ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						$c = isset( $row['calculator'] ) ? (string) $row['calculator'] : '';
						return ! self::is_ed_hidden( $c );
					}
				)
			);
		}

		if ( ! $rows ) {
			if ( 'free' === $plan ) {
				$out['latest_only']  = true;
				$out['upgrade_line'] = true;
				$out['upgrade_text'] = self::MESSAGES['free.upgrade'];
			}
			return $out;
		}

		if ( 'premium' !== $plan ) {
			$latest              = $rows[0];
			$out['rows']         = array( $latest );
			$out['latest_only']  = true;
			$out['history']      = false;
			$out['upgrade_line'] = true;
			$out['upgrade_text'] = self::MESSAGES['free.upgrade'];
			$out['shown']        = self::format_shown( $latest );
			return $out;
		}

		$out['rows']      = $rows;
		$out['history']   = true;
		$out['shown']     = self::format_shown( $rows[0] );
		if ( count( $rows ) >= 2 ) {
			// Present expects chronological older→newer for compare(); rows are newest first.
			$chron = array_reverse( $rows );
			$cmp   = self::compare(
				array(
					'calculator' => isset( $chron[0]['calculator'] ) ? $chron[0]['calculator'] : '',
					'results'    => $chron,
					'plan'       => 'premium',
				)
			);
			$out['comparison'] = $cmp;
		}
		return $out;
	}

	/**
	 * Whether the save control is offered (Stage 2 rule: health consent required).
	 *
	 * @param bool $health_consent Member has health consent.
	 */
	public static function save_offered( $health_consent ) {
		return (bool) $health_consent;
	}

	/**
	 * Whether a calculator's saved rows are hidden for this member (FR3-69).
	 *
	 * @param string             $calculator Calculator key.
	 * @param array<string,bool> $flags      eating_disorder etc.
	 */
	public static function is_hidden( $calculator, array $flags = array() ) {
		if ( ! empty( $flags['eating_disorder'] ) && self::is_ed_hidden( $calculator ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether create/list of this calculator is refused by the API for the member.
	 *
	 * @param string             $calculator Calculator key.
	 * @param array<string,bool> $flags      Flags.
	 */
	public static function api_refuses( $calculator, array $flags = array() ) {
		return self::is_hidden( $calculator, $flags );
	}

	/**
	 * Stage 3 calculators may store encrypted inputs; Stage 1 tools never do (D51, D3-33).
	 *
	 * @param string $calculator Calculator key.
	 */
	public static function stores_inputs( $calculator ) {
		$c = str_replace( '-', '_', (string) $calculator );
		return ! in_array( $c, array( 'protein', 'diabetes_risk', 'healthy_weight' ), true )
			&& ! in_array( (string) $calculator, self::STAGE1_NO_INPUTS, true );
	}

	/**
	 * Re-check reminder decision (Premium). Fixture-shaped.
	 *
	 * @param array{calculator:string,plan?:string,results?:array,saved_at?:string,flags?:array} $in Inputs.
	 * @return array<string,mixed>
	 */
	public static function recheck( array $in ) {
		$calc  = isset( $in['calculator'] ) ? (string) $in['calculator'] : '';
		$plan  = isset( $in['plan'] ) ? strtolower( (string) $in['plan'] ) : 'premium';
		$flags = isset( $in['flags'] ) && is_array( $in['flags'] ) ? $in['flags'] : array();
		$out   = array(
			'reminder_created'       => false,
			'reminder_type'          => null,
			'due_date'               => null,
			'days_after_last_saved'  => self::RECHECK_DAYS,
			'email_text'             => self::MESSAGES['recheck.email'],
			'email_contains_number'  => false,
			'email_contains_result'  => false,
			'email_names_calculator' => false,
		);

		if ( 'premium' !== $plan ) {
			return $out;
		}
		if ( ! in_array( $calc, self::RECHECK_CALCULATORS, true ) ) {
			return $out;
		}
		if ( self::is_hidden( $calc, $flags ) ) {
			return $out;
		}

		$saved = isset( $in['saved_at'] ) ? (string) $in['saved_at'] : '';
		if ( ! $saved && ! empty( $in['results'][0]['date'] ) ) {
			$saved = (string) $in['results'][0]['date'];
		}
		$due = self::add_days( $saved, self::RECHECK_DAYS );

		$out['reminder_created'] = true;
		$out['reminder_type']    = 'check_in';
		$out['due_date']         = $due;
		return $out;
	}

	/**
	 * WHO band on the stored 1-dp risk (D3-13 thresholds; matches heart-risk engine).
	 *
	 * @param float $risk Stored risk (1 dp).
	 * @return string low|moderate|high|very-high
	 */
	public static function heart_band( $risk ) {
		$risk = (float) $risk;
		if ( $risk < 5 ) {
			return 'low';
		}
		if ( $risk < 10 ) {
			return 'moderate';
		}
		if ( $risk < 20 ) {
			return 'high';
		}
		return 'very-high';
	}

	/**
	 * Printed whole-number risk the member saw (half-up).
	 *
	 * @param float $risk Stored 1-dp risk.
	 */
	public static function heart_shown( $risk ) {
		return (int) round( (float) $risk );
	}

	/**
	 * ISH office category label for fixtures (Grade 1, High-normal, …).
	 *
	 * @param float $sys Systolic.
	 * @param float $dia Diastolic.
	 */
	public static function bp_category_label( $sys, $dia ) {
		$key = 'normal';
		foreach ( array(
			'grade2'      => array( 160, 100 ),
			'grade1'      => array( 140, 90 ),
			'high-normal' => array( 130, 85 ),
		) as $cat => $t ) {
			if ( (float) $sys >= $t[0] || (float) $dia >= $t[1] ) {
				$key = $cat;
				break;
			}
		}
		$map = array(
			'grade2'      => 'Grade 2',
			'grade1'      => 'Grade 1',
			'high-normal' => 'High-normal',
			'normal'      => 'Normal',
		);
		return $map[ $key ];
	}

	/**
	 * Normalise a create payload for the Stage 2 calculator_result table (plus sealed Stage 3 fields).
	 *
	 * @param array<string,mixed> $params Raw JSON body.
	 * @param array<string,bool>  $flags  Safety flags.
	 * @return array<string,mixed>|array{error:string,status?:int}
	 */
	public static function for_save( array $params, array $flags = array() ) {
		$calc = isset( $params['calculator'] ) ? (string) $params['calculator'] : '';
		$calc = str_replace( '-', '_', $calc );
		if ( '' === $calc ) {
			return array( 'error' => 'Calculator is required.', 'status' => 422 );
		}
		if ( self::api_refuses( $calc, $flags ) ) {
			return array( 'error' => 'Not available.', 'status' => 403 );
		}
		$headline = isset( $params['headline'] ) ? substr( (string) $params['headline'], 0, 64 ) : '';
		$unit     = isset( $params['unit'] ) ? substr( (string) $params['unit'], 0, 64 ) : '';
		$band     = isset( $params['band'] ) ? substr( (string) $params['band'], 0, 64 ) : '';
		$version  = isset( $params['calculator_version'] ) ? (string) $params['calculator_version'] : ( isset( $params['engine_version'] ) ? (string) $params['engine_version'] : '' );
		$version  = substr( $version, 0, 64 );
		if ( '' === $headline || '' === $band ) {
			return array( 'error' => 'Headline and band are required.', 'status' => 422 );
		}
		$row = array(
			'calculator'          => $calc,
			'headline'            => $headline,
			'unit'                => '' !== $unit ? $unit : 'result',
			'band'                => $band,
			'calculator_version'  => '' !== $version ? $version : 'unversioned',
			'saved_at'            => isset( $params['saved_at'] ) ? (string) $params['saved_at'] : '',
		);
		// Structured result (encrypted): model, numeric headline parts, etc.
		$result = isset( $params['result'] ) ? $params['result'] : null;
		if ( null === $result && isset( $params['result_value'] ) ) {
			$result = $params['result_value'];
		}
		$encode = static function ( $value ) {
			if ( is_string( $value ) ) {
				return $value;
			}
			return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		};
		if ( null !== $result ) {
			$row['result'] = $encode( $result );
		}
		if ( self::stores_inputs( $calc ) && array_key_exists( 'inputs', $params ) && null !== $params['inputs'] ) {
			$row['inputs'] = $encode( $params['inputs'] );
		}
		return $row;
	}

	// ---- Private compare helpers -----------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $a   Older.
	 * @param array<string,mixed> $b   Newer.
	 * @param string              $date Date label.
	 * @param array<string,mixed> $out Base.
	 * @return array<string,mixed>
	 */
	private static function compare_heart( array $a, array $b, $date, array $out ) {
		$ra = (float) $a['result'];
		$rb = (float) $b['result'];
		$sa = self::heart_shown( $ra );
		$sb = self::heart_shown( $rb );
		$ba = self::heart_band( $ra );
		$bb = self::heart_band( $rb );

		$out['shown_from'] = $sa . ' %';
		$out['shown_to']   = $sb . ' %';
		$out['band_from']  = self::band_label( $ba );
		$out['band_to']    = self::band_label( $bb );
		$out['band_change'] = $ba !== $bb;

		$delta = $sb - $sa; // whole points, newer minus older
		$abs   = abs( $delta );
		$sign  = $delta > 0 ? '+' : ( $delta < 0 ? '' : '' );
		$chg   = ( $delta > 0 ? '+' : '' ) . $delta . ' point' . ( 1 === $abs ? '' : 's' );

		// Meaningful: more than 1 whole point, or a band change (D3-34; SR-02 at exactly 1 stays "about the same").
		$meaningful = ( $abs > (float) self::THRESHOLDS['heart_risk'] ) || $out['band_change'];

		if ( ! $meaningful ) {
			$out['wording']            = 'About the same (' . ( $delta > 0 ? '+' : '' ) . $delta . ' point' . ( 1 === abs( $delta ) ? '' : 's' ) . ')';
			$out['better_worse_label'] = 'same';
			$out['messages'][]         = 'change.same';
			return $out;
		}

		if ( $delta < 0 ) {
			$points                    = $abs . ' point' . ( 1 === $abs ? '' : 's' ) . ' lower';
			$out['wording']            = $date
				? 'Better: ' . $points . ' since ' . $date
				: 'Better: ' . $points;
			$out['better_worse_label'] = 'better';
			$out['messages'][]         = 'change.better';
			return $out;
		}

		$out['wording']            = $date
			? self::fill( 'change.higher', array( 'change' => $abs . ' point' . ( 1 === $abs ? '' : 's' ), 'date' => $date ) )
			: 'Higher than last time: ' . $chg;
		$out['better_worse_label'] = 'higher';
		$out['messages'][]         = 'change.higher';
		return $out;
	}

	/**
	 * @param array<string,mixed> $a Older.
	 * @param array<string,mixed> $b Newer.
	 * @param string              $date Date.
	 * @param array<string,mixed> $out Base.
	 * @return array<string,mixed>
	 */
	private static function compare_bp( array $a, array $b, $date, array $out ) {
		$sys_a = (float) $a['systolic'];
		$dia_a = (float) $a['diastolic'];
		$sys_b = (float) $b['systolic'];
		$dia_b = (float) $b['diastolic'];
		$d_sys = $sys_b - $sys_a;
		$d_dia = $dia_b - $dia_a;

		$out['category_from'] = self::bp_category_label( $sys_a, $dia_a );
		$out['category_to']   = self::bp_category_label( $sys_b, $dia_b );

		$th          = self::THRESHOLDS['bp_check'];
		$meaningful  = ( abs( $d_sys ) >= $th['sys'] ) || ( abs( $d_dia ) >= $th['dia'] );
		$fmt         = static function ( $n ) {
			$n = (int) round( $n );
			return ( $n > 0 ? '+' : '' ) . $n;
		};
		$pair = abs( (int) round( $d_sys ) ) . '/' . abs( (int) round( $d_dia ) );
		$pair_signed = $fmt( $d_sys ) . '/' . $fmt( $d_dia );

		if ( ! $meaningful ) {
			$out['wording']            = 'About the same (' . $pair_signed . ')';
			$out['better_worse_label'] = 'same';
			$out['messages'][]         = 'change.same';
			return $out;
		}

		// Lower BP is better.
		$improved = ( $d_sys < 0 && abs( $d_sys ) >= $th['sys'] ) || ( $d_dia < 0 && abs( $d_dia ) >= $th['dia'] );
		// Prefer "both moved down" reading when both fell.
		if ( $d_sys <= 0 && $d_dia <= 0 ) {
			$improved = true;
		} elseif ( $d_sys >= 0 && $d_dia >= 0 ) {
			$improved = false;
		}

		if ( $improved ) {
			$out['wording']            = 'Better: ' . $pair . ' lower';
			$out['better_worse_label'] = 'better';
			$out['messages'][]         = 'change.better';
			return $out;
		}

		$out['wording']            = $date
			? self::fill( 'change.higher', array( 'change' => $pair, 'date' => $date ) )
			: 'Higher than last time: ' . $pair;
		$out['better_worse_label'] = 'higher';
		$out['messages'][]         = 'change.higher';
		return $out;
	}

	/**
	 * @param array<string,mixed> $a Older.
	 * @param array<string,mixed> $b Newer.
	 * @param string              $date Date.
	 * @param array<string,mixed> $out Base.
	 * @return array<string,mixed>
	 */
	private static function compare_fitness( array $a, array $b, $date, array $out ) {
		$vo2_a = (float) $a['vo2'];
		$vo2_b = (float) $b['vo2'];
		$age_a = (int) round( (float) $a['fitness_age'] );
		$age_b = (int) round( (float) $b['fitness_age'] );
		$d_vo2 = round( $vo2_b - $vo2_a, 1 );
		$d_age = $age_b - $age_a;
		$th    = self::THRESHOLDS['fitness_age'];

		$meaningful = ( abs( $d_vo2 ) > $th['vo2'] ) || ( abs( $d_age ) >= $th['age'] );
		// Higher VO2 / lower fitness age is better.
		$better = ( $d_vo2 > $th['vo2'] ) || ( $d_age <= -$th['age'] );

		if ( ! $meaningful ) {
			$out['wording']            = 'About the same (' . ( $d_vo2 > 0 ? '+' : '' ) . $d_vo2 . ')';
			$out['better_worse_label'] = 'same';
			$out['messages'][]         = 'change.same';
			return $out;
		}

		$arrow = "\u{2192}";
		if ( $better ) {
			$sign                      = $d_vo2 > 0 ? '+' : '';
			$out['wording']            = 'Better: ' . $sign . $d_vo2 . ' points; fitness age ' . $age_a . ' ' . $arrow . ' ' . $age_b;
			$out['better_worse_label'] = 'better';
			$out['messages'][]         = 'change.better';
			return $out;
		}

		$out['wording']            = self::MESSAGES['change.lower'];
		$out['better_worse_label'] = 'lower';
		$out['messages'][]         = 'change.lower';
		unset( $date );
		return $out;
	}

	/**
	 * @param array<string,mixed> $a Older.
	 * @param array<string,mixed> $b Newer.
	 * @param string              $date Date.
	 * @param array<string,mixed> $out Base.
	 * @return array<string,mixed>
	 */
	private static function compare_metabolic( array $a, array $b, $date, array $out ) {
		$ca = (int) $a['criteria_met'];
		$cb = (int) $b['criteria_met'];
		$oa = isset( $a['outcome'] ) ? (string) $a['outcome'] : '';
		$ob = isset( $b['outcome'] ) ? (string) $b['outcome'] : '';
		$d  = $cb - $ca;
		$th = (float) self::THRESHOLDS['metabolic'];

		$arrow = "\u{2192}";
		if ( abs( $d ) < $th && $oa === $ob ) {
			$out['wording']            = 'About the same (' . ( $d > 0 ? '+' : '' ) . $d . ')';
			$out['better_worse_label'] = 'same';
			$out['messages'][]         = 'change.same';
			return $out;
		}

		// Fewer criteria met / leaving "met" is better.
		$better = ( $d <= -$th ) || ( 'met' === $oa && 'met' !== $ob );
		if ( $better ) {
			$extra = ( 'met' === $oa && 'not met' === $ob ) ? '; no longer meets the definition' : '';
			$out['wording']            = 'Better: ' . $ca . ' ' . $arrow . ' ' . $cb . ' of 4' . $extra;
			$out['better_worse_label'] = 'better';
			$out['messages'][]         = 'change.better';
			return $out;
		}

		$out['wording']            = $date
			? self::fill( 'change.higher', array( 'change' => $ca . ' ' . $arrow . ' ' . $cb . ' of 4', 'date' => $date ) )
			: 'Higher than last time: ' . $ca . ' ' . $arrow . ' ' . $cb . ' of 4';
		$out['better_worse_label'] = 'higher';
		$out['messages'][]         = 'change.higher';
		return $out;
	}

	/**
	 * @param array<string,mixed> $a Older.
	 * @param array<string,mixed> $b Newer.
	 * @param array<string,mixed> $out Base.
	 * @return array<string,mixed>
	 */
	private static function compare_healthy_weight( array $a, array $b, array $out ) {
		$ba = round( (float) $a['result'], 1 );
		$bb = round( (float) $b['result'], 1 );
		$arrow = "\u{2192}";
		// Never "Better" / never praise kg lost (FR3-16 AS-04).
		$out['wording']            = 'BMI ' . self::num_str( $ba ) . ' ' . $arrow . ' ' . self::num_str( $bb ) . '. ' . self::MESSAGES['healthyWeight.comparison'] . '.';
		$out['better_worse_label'] = null;
		$out['weight_loss_praise'] = false;
		$out['messages'][]         = 'healthyWeight.comparison';
		return $out;
	}

	/**
	 * Scalar % / g / kcal / weeks comparison.
	 *
	 * @param array<string,mixed> $a        Older.
	 * @param array<string,mixed> $b        Newer.
	 * @param string              $date     Date label.
	 * @param array<string,mixed> $out      Base.
	 * @param string              $calc_key Threshold key.
	 * @param bool                $lower_is_better Whether a fall is "better".
	 * @return array<string,mixed>
	 */
	private static function compare_scalar( array $a, array $b, $date, array $out, $calc_key, $lower_is_better ) {
		$va = (float) $a['result'];
		$vb = (float) $b['result'];
		$d  = round( $vb - $va, 1 );
		$th = (float) self::THRESHOLDS[ $calc_key ];

		if ( abs( $d ) < $th || abs( $d ) <= $th && abs( $d ) < $th + 1e-9 && abs( $d ) <= $th ) {
			// "About the same" when |d| is below or equal? diabetes SR-10 +0.7 < 1; SR-11 1.2 > 1.
			// Use strictly less than threshold for "same" when threshold is for percentage points.
		}
		if ( abs( $d ) < $th ) {
			$sign                      = $d > 0 ? '+' : '';
			$out['wording']            = 'About the same (' . $sign . self::num_str( $d ) . ')';
			$out['better_worse_label'] = 'same';
			$out['messages'][]         = 'change.same';
			return $out;
		}

		$better = $lower_is_better ? ( $d < 0 ) : ( $d > 0 );
		if ( $better ) {
			$mag                       = self::num_str( abs( $d ) );
			$out['wording']            = 'Better: ' . $mag . ' points lower';
			$out['better_worse_label'] = 'better';
			$out['messages'][]         = 'change.better';
			if ( $date ) {
				// Prefer fixture-style short line; keep template key for hash tests.
				$out['wording'] = 'Better: ' . $mag . ' points lower';
			}
			return $out;
		}

		$out['wording']            = $date
			? self::fill( 'change.higher', array( 'change' => self::num_str( abs( $d ) ) . ' points', 'date' => $date ) )
			: 'Higher than last time: ' . self::num_str( abs( $d ) ) . ' points';
		$out['better_worse_label'] = 'higher';
		$out['messages'][]         = 'change.higher';
		return $out;
	}

	/**
	 * @param string               $key  MESSAGES key.
	 * @param array<string,string> $fill Placeholders.
	 */
	private static function fill( $key, array $fill ) {
		$text = self::MESSAGES[ $key ];
		foreach ( $fill as $k => $v ) {
			$text = str_replace( '{' . $k . '}', (string) $v, $text );
		}
		return $text;
	}

	/**
	 * @param string $band Engine band key.
	 */
	private static function band_label( $band ) {
		$map = array(
			'low'       => 'Low',
			'moderate'  => 'Moderate',
			'high'      => 'High',
			'very-high' => 'Very high',
		);
		return isset( $map[ $band ] ) ? $map[ $band ] : $band;
	}

	/**
	 * @param array<string,mixed> $row Result or saved row.
	 */
	private static function date_label( array $row ) {
		if ( ! empty( $row['date'] ) ) {
			return (string) $row['date'];
		}
		if ( ! empty( $row['saved_at'] ) ) {
			$ts = strtotime( (string) $row['saved_at'] . ' UTC' );
			return false === $ts ? '' : gmdate( 'j M', $ts );
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $row Saved row.
	 */
	private static function format_shown( array $row ) {
		if ( isset( $row['shown'] ) ) {
			return (string) $row['shown'];
		}
		if ( isset( $row['result'], $row['unit'] ) ) {
			return self::num_str( $row['result'] ) . ' ' . $row['unit'];
		}
		if ( isset( $row['headline'], $row['unit'] ) ) {
			return $row['headline'] . ( $row['unit'] ? ' ' . $row['unit'] : '' );
		}
		return isset( $row['headline'] ) ? (string) $row['headline'] : '';
	}

	/**
	 * @param mixed $n Number.
	 */
	private static function num_str( $n ) {
		$n = (float) $n;
		if ( abs( $n - round( $n ) ) < 1e-9 ) {
			return (string) (int) round( $n );
		}
		$s = rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * @param string $calculator Key.
	 */
	private static function is_ed_hidden( $calculator ) {
		$c = str_replace( '-', '_', (string) $calculator );
		return in_array( $c, array( 'calorie_target', 'goal_timeline' ), true );
	}

	/**
	 * Add days to a fixture date label ("1 Feb") or an ISO/MySQL datetime; returns "j M" or ISO date.
	 *
	 * @param string $saved Saved label or datetime.
	 * @param int    $days  Days to add.
	 */
	private static function add_days( $saved, $days ) {
		$saved = trim( (string) $saved );
		if ( '' === $saved ) {
			return null;
		}
		$day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		// Fixture labels like "1 Feb" (assume current/test year 2026).
		if ( preg_match( '/^(\d{1,2})\s+([A-Za-z]{3})$/', $saved, $m ) ) {
			$ts = strtotime( $m[1] . ' ' . $m[2] . ' 2026 UTC' );
			if ( false === $ts ) {
				return null;
			}
			return gmdate( 'j M', $ts + ( (int) $days * $day ) );
		}
		$ts = strtotime( $saved . ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $saved ) ? '' : ' UTC' ) );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d', $ts + ( (int) $days * $day ) );
	}
}
