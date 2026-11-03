<?php
/**
 * Metabolic Health Check engine (P3-59, FR3-51, FR3-45), written from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-59-Metabolic-Health-Check-Method-Sheet.md` (approval record
 * `approvals/stage3/metabolic.json`, fixtures `fixtures/metabolic/`).
 *
 * Pure rules: no WordPress, no database, no member lookup. The caller (the members' interface) supplies the answers
 * and, through apply_profile(), the member's health profile; it decides what to store (saving over time is P3-5B).
 *
 * IDF 2006 consensus worldwide definition (D3-22): central obesity (waist at or above the background- and
 * sex-specific cut-off) plus any two of raised triglycerides, reduced HDL cholesterol, raised blood pressure and
 * raised fasting glucose, each also met by its specific treatment (type 2 diabetes for glucose). Every threshold is
 * applied in the unit entered, using the IDF's own paired mmol/L and mg/dL values, with no conversion or rounding
 * before comparison (sheet 1.2); conversions are for display only. "Prefer not to say" uses the female HDL
 * threshold and the 80 cm waist, with a note (D3-23). Fasting glucose only; HbA1c is not accepted (D3-24). An
 * eating-disorder answer runs the check and hides the weight-loss suggestion (D3-25). Frozen: any change to these
 * rules or the wording needs a new decision and a new version of the approval record (NFR3-14).
 *
 * The rows of the sheet's fixture table decide where they differ from section 3's prose
 * (`Transcription-Open-Points-2026-11-03.md`, MS-12 and MS-14); see TALK_TO_DOCTOR_MIN.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens, the saved results and the tests read.
 */
class LLD_Members_Metabolic {

	const VERSION = '1.0';

	/** The four criteria, in the order the sheet lists them. */
	const CRITERIA = array( 'triglycerides', 'hdl', 'bloodPressure', 'fastingGlucose' );

	/** Waist cut-offs in cm (sheet section 1): women 80 for every group; men 90 for these groups, else 94. */
	const WAIST_FEMALE   = 80;
	const WAIST_MALE     = 94;
	const WAIST_MALE_90  = array( 'south_asian', 'chinese', 'japanese', 'south_central_american' );
	const CM_PER_INCH    = 2.54;

	/** IDF thresholds in each unit (sheet 1.2). Triglycerides and glucose: at or above; HDL: below. */
	const THRESHOLDS = array(
		'triglycerides'  => array( 'mmol' => 1.7, 'mg' => 150 ),
		'hdl'            => array(
			'M' => array( 'mmol' => 1.03, 'mg' => 40 ),
			'F' => array( 'mmol' => 1.29, 'mg' => 50 ),
		),
		'fastingGlucose' => array( 'mmol' => 5.6, 'mg' => 100 ),
		'bloodPressure'  => array( 'sys' => 130, 'dia' => 85 ),
	);

	/** Display conversion factors, mg/dL per mmol/L (sheet 1.2: for display only, never before comparison). */
	const MG_PER_MMOL = array(
		'triglycerides'  => 88.57,
		'hdl'            => 38.67,
		'fastingGlucose' => 18.016,
	);

	/** Accepted ranges in each unit (sheet section 2); outside them the answer is refused. */
	const RANGES = array(
		'triglycerides'  => array( 'mmol' => array( 0.2, 20 ), 'mg' => array( 18, 1770 ) ),
		'hdl'            => array( 'mmol' => array( 0.3, 5 ), 'mg' => array( 12, 195 ) ),
		'fastingGlucose' => array( 'mmol' => array( 2, 30 ), 'mg' => array( 36, 540 ) ),
		'waistCm'        => array( 40, 250 ),
		'sys'            => array( 60, 260 ),
		'dia'            => array( 30, 160 ),
		'age'            => array( 18, 99 ),
	);

	/**
	 * Safety levels (sheet section 4), in each unit. Blood pressure urgent at 180 systolic or 120 diastolic (P3-58
	 * section 4.1). Glucose: 11.1 mmol/L (200 mg/dL) or more is the diagnostic-range line, 15 (270) or more the
	 * urgent wording; below 3.9 (70) the low note, below 3.0 (54, the standard paired value) the urgent wording.
	 * Triglycerides 10 mmol/L (885 mg/dL) or more: medical review soon.
	 */
	const FLAG_LEVELS = array(
		'bp_urgent'           => array( 'sys' => 180, 'dia' => 120 ),
		'glucose_diagnostic'  => array( 'mmol' => 11.1, 'mg' => 200 ),
		'glucose_urgent_high' => array( 'mmol' => 15, 'mg' => 270 ),
		'glucose_low'         => array( 'mmol' => 3.9, 'mg' => 70 ),
		'glucose_urgent_low'  => array( 'mmol' => 3.0, 'mg' => 54 ),
		'triglycerides_high'  => array( 'mmol' => 10, 'mg' => 885 ),
	);

	/**
	 * With a waist below the cut-off, the "talk to your doctor" line is shown from this many raised results. The
	 * sheet's section 3 prose says 2; its fixture rows show the line at 4 raised (MS-06) and not at 2 (MS-14), and
	 * the engine follows the rows (Transcription-Open-Points, MS-14). 3 is the lowest value both rows allow.
	 * Reported to the lead for an owner decision.
	 */
	const TALK_TO_DOCTOR_MIN = 3;

	/** The labels the signed partial wording names, in its order. */
	const MISSING_LABELS = array(
		'triglycerides'  => 'triglycerides',
		'hdl'            => 'HDL cholesterol',
		'fastingGlucose' => 'fasting glucose',
	);

	/**
	 * The signed wording (sheet sections 3 and 4), exactly as quoted. Keys and text are hashed in the approval
	 * record's copy_hashes; the test checks them. Placeholders ({n}, {missing list} and the partial body's list)
	 * are filled by the screen from the result's `placeholders`.
	 */
	const MESSAGES = array(
		'results.meets.headline'           => 'Your results meet the IDF definition of metabolic syndrome',
		'results.meets.body'               => "This isn't a diagnosis: a doctor confirms it from your full history and tests. It means several results that raise the risk of type 2 diabetes and heart disease are present together, and lifestyle changes work on all of them at once. Please share these results with your doctor.",
		'results.notMet.headline'          => "Your results don't meet the definition",
		'results.notMet.body'              => '{n} of 4 other results are above their thresholds.',
		'results.notMet.waistBelow.headline' => "Your results don't meet the definition",
		'results.notMet.waistBelow.body'   => 'Central weight (waist) is the first part of the IDF definition, and yours is below the cut-off for your background. {n} of your other results are above their thresholds: see below for what helps.',
		'results.partial.headline'         => 'Partial result: {missing list} needed',
		'results.partial.body'             => "We can't complete the check without {triglycerides, HDL cholesterol, fasting glucose}. Ask your doctor for a recent blood test result, or check your last results, and come back.",
		'about.definition'                 => "Based on the International Diabetes Federation's 2006 worldwide definition. Other definitions exist (for example the 2009 harmonised definition, which doesn't require a large waist), and your doctor may use one of those.",
		'lifestyle.waist'                  => 'Losing 5 to 10 % of body weight reduces waist and improves every other result',
		'stops.pregnancy'                  => "Waist, blood fats and glucose all change during pregnancy and breastfeeding, so this check isn't suitable. Your midwife or doctor can advise.",
		'flags.glucoseDiagnostic'          => 'A fasting glucose of 11.1 or more is in the range doctors use to diagnose diabetes. Please see your doctor soon to have it confirmed.',
		'flags.glucoseLow'                 => "That's a low fasting glucose. If you take diabetes medicine, talk to your doctor; if you had symptoms (shaky, sweaty, confused), see your doctor.",
		'flags.triglyceridesHigh'          => 'Very high triglycerides need medical review soon: please contact your doctor.',
		'notes.diabetes'                   => 'With diabetes, your diabetes team already monitors these results.',
	);

	/**
	 * Wording this check quotes unchanged from another signed tool (sheet section 4): the Blood Pressure Check's
	 * frozen urgent alert (P3-58 section 4.1, hashed in approvals/stage3/bp-check.json as urgent.alert). The glucose
	 * urgent wording is read from the safety matrix (LLD_Members_Stage3_Safety::MESSAGES, P3-03), not copied.
	 */
	const QUOTED = array(
		'flags.bpUrgent' => "Your reading is very high.\nSit quietly for 5 minutes and take it again. If it is still 180/120 or more, contact a doctor today.\nIf you also have chest pain, shortness of breath, a severe headache, weakness or numbness, trouble speaking or seeing, or feel confused or very unwell, call your local emergency number now — for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, or 000 in Australia. [When to seek urgent care]",
	);

	/**
	 * Apply the member's health profile (sheet section 4, safety matrix P3-03): pregnancy stops the check, an
	 * eating-disorder flag hides the weight-loss suggestion, diabetes counts the glucose criterion as met, kidney
	 * disease is passed on (no protein-related suggestion is made here). A profile answer never weakens a page answer.
	 *
	 * @param array    $in         The answers on the page.
	 * @param string[] $situations LLD_Members_Stage3_Safety::SITUATIONS keys in force for the member.
	 */
	public static function apply_profile( array $in, array $situations ) {
		if ( in_array( 'pregnancy', $situations, true ) ) {
			$in['pregnantOrBreastfeeding'] = 'yes';
		}
		if ( in_array( 'eating_disorder', $situations, true ) ) {
			$in['eatingDisorder'] = 'yes';
		}
		if ( in_array( 'diabetes', $situations, true ) ) {
			$in['type2Diabetes'] = true;
		}
		if ( in_array( 'kidney', $situations, true ) ) {
			$in['kidneyDisease'] = true;
		}
		return $in;
	}

	/**
	 * Work out the result.
	 *
	 * @param array $in age (optional, 18-99), sex (female|male|prefer-not-to-say), background, pregnantOrBreastfeeding
	 *                  (yes|no|not-sure), eatingDisorder (yes|no|pnts), waistCm or waistIn, bpSystolic and bpDiastolic,
	 *                  bpMedicine, triglycerides + triglyceridesUnit (mmol/L|mg/dL), triglyceridesMedicine, hdl +
	 *                  hdlUnit, hdlMedicine, fastingGlucose + fastingGlucoseUnit, fastingGlucoseFasting (default
	 *                  true), type2Diabetes, kidneyDisease. A blood value left empty (or "don't know") is unknown.
	 * @return array stop (null, stops.under18 or stops.pregnancy), central, waistCutoffCm, criteria, count, result, missing, flags, notes, components,
	 *               lifestyle, lifestyleWaistShown, glucoseUrgent, placeholders, messages, version; or error.
	 */
	public static function compute( array $in ) {
		$out = array(
			'stop'                => null,
			'central'             => null,
			'waistCm'             => null,
			'waistCutoffCm'       => null,
			'criteria'            => null,
			'count'               => null,
			'result'              => null,
			'missing'             => array(),
			'flags'               => array(),
			'notes'               => array(),
			'components'          => array(),
			'lifestyle'           => array(),
			'lifestyleWaistShown' => false,
			'glucoseUrgent'       => null,
			'placeholders'        => array(),
			'messages'            => array(),
			'version'             => self::VERSION,
		);

		// Answers first: age, sex, pregnancy, eating; then the stops; then the measurements.
		$age = null;
		if ( isset( $in['age'] ) && null !== $in['age'] && '' !== $in['age'] ) {
			if ( ! is_numeric( $in['age'] ) || (float) $in['age'] < 0 || (float) $in['age'] > self::RANGES['age'][1] ) {
				return self::invalid( 'age' );
			}
			$age = (int) $in['age'];
		}
		$sex = self::sex( isset( $in['sex'] ) ? $in['sex'] : '' );
		if ( '' === $sex ) {
			return self::invalid( 'sex' );
		}
		$preg_in  = isset( $in['pregnantOrBreastfeeding'] ) ? $in['pregnantOrBreastfeeding'] : ( isset( $in['pregnant'] ) ? $in['pregnant'] : 'no' );
		$pregnant = self::answer( null === $preg_in ? 'no' : $preg_in, array( 'yes', 'no', 'notsure' ) );
		if ( null === $pregnant ) {
			return self::invalid( 'pregnantOrBreastfeeding' );
		}
		$eating = self::answer( isset( $in['eatingDisorder'] ) && null !== $in['eatingDisorder'] ? $in['eatingDisorder'] : 'no', array( 'yes', 'no', 'pnts' ) );
		if ( null === $eating ) {
			return self::invalid( 'eatingDisorder' );
		}
		if ( null !== $age && $age < self::RANGES['age'][0] ) {
			return self::stop( $out, 'under18' );
		}
		if ( 'no' !== $pregnant ) {
			return self::stop( $out, 'pregnancy' );
		}

		// Waist (required) and its cut-off.
		$waist = self::waist_cm( $in );
		if ( null === $waist || $waist < self::RANGES['waistCm'][0] || $waist > self::RANGES['waistCm'][1] ) {
			return self::invalid( 'waist' );
		}
		$group  = self::background( isset( $in['background'] ) ? $in['background'] : '' );
		$cutoff = 'M' === $sex ? ( in_array( $group, self::WAIST_MALE_90, true ) ? 90 : self::WAIST_MALE ) : self::WAIST_FEMALE;
		$central = $waist >= $cutoff;

		// Blood pressure: required unless the medicine box is ticked (then counted as met).
		$bp_med = ! empty( $in['bpMedicine'] );
		$sys    = self::number( $in, 'bpSystolic' );
		$dia    = self::number( $in, 'bpDiastolic' );
		if ( false === $sys || false === $dia ) {
			return self::invalid( 'bloodPressure' );
		}
		if ( ( null === $sys ) !== ( null === $dia ) || ( null === $sys && ! $bp_med ) ) {
			return self::invalid( 'bloodPressure' );
		}
		if ( null !== $sys && ( $sys < self::RANGES['sys'][0] || $sys > self::RANGES['sys'][1] || $dia < self::RANGES['dia'][0] || $dia > self::RANGES['dia'][1] ) ) {
			return self::invalid( 'bloodPressure' );
		}

		// The three blood values: each in its own unit, or unknown.
		$labs = array();
		foreach ( array( 'triglycerides', 'hdl', 'fastingGlucose' ) as $field ) {
			$value = self::number( $in, $field );
			if ( false === $value ) {
				return self::invalid( $field );
			}
			$unit = null;
			if ( null !== $value ) {
				$unit = self::unit( isset( $in[ $field . 'Unit' ] ) ? $in[ $field . 'Unit' ] : null );
				if ( null === $unit ) {
					return self::invalid( $field . 'Unit' );
				}
				$range = self::RANGES[ $field ][ $unit ];
				if ( $value < $range[0] || $value > $range[1] ) {
					return self::invalid( $field );
				}
			}
			$labs[ $field ] = array( 'value' => $value, 'unit' => $unit );
		}
		$fasting = ! array_key_exists( 'fastingGlucoseFasting', $in ) || null === $in['fastingGlucoseFasting'] || ! empty( $in['fastingGlucoseFasting'] );
		$t2d     = ! empty( $in['type2Diabetes'] );
		$tg_med  = ! empty( $in['triglyceridesMedicine'] );
		$hdl_med = ! empty( $in['hdlMedicine'] );

		// Criteria (sheet section 1, applied in the unit entered).
		$criteria = array();
		$tg       = $labs['triglycerides'];
		$criteria['triglycerides'] = $tg_med ? 'met' : ( null === $tg['value'] ? 'unknown' : ( $tg['value'] >= self::THRESHOLDS['triglycerides'][ $tg['unit'] ] ? 'met' : 'not-met' ) );
		$hdl      = $labs['hdl'];
		$hdl_sex  = 'M' === $sex ? 'M' : 'F';
		$criteria['hdl'] = $hdl_med ? 'met' : ( null === $hdl['value'] ? 'unknown' : ( $hdl['value'] < self::THRESHOLDS['hdl'][ $hdl_sex ][ $hdl['unit'] ] ? 'met' : 'not-met' ) );
		$criteria['bloodPressure'] = $bp_med || ( null !== $sys && ( $sys >= self::THRESHOLDS['bloodPressure']['sys'] || $dia >= self::THRESHOLDS['bloodPressure']['dia'] ) ) ? 'met' : 'not-met';
		$fpg      = $labs['fastingGlucose'];
		$fpg_used = null !== $fpg['value'] && $fasting;
		$criteria['fastingGlucose'] = $t2d ? 'met' : ( ! $fpg_used ? 'unknown' : ( $fpg['value'] >= self::THRESHOLDS['fastingGlucose'][ $fpg['unit'] ] ? 'met' : 'not-met' ) );

		$count   = 0;
		$unknown = array();
		foreach ( self::CRITERIA as $c ) {
			if ( 'met' === $criteria[ $c ] ) {
				$count++;
			} elseif ( 'unknown' === $criteria[ $c ] ) {
				$unknown[] = $c;
			}
		}

		// Overall (sheet section 3.2 and the fixture rows).
		if ( ! $central ) {
			$result = 'results.notMet.waistBelow';
		} elseif ( $count >= 2 ) {
			$result = 'results.meets';
		} elseif ( $count + count( $unknown ) >= 2 ) {
			$result         = 'results.partial';
			$out['missing'] = $unknown;
		} else {
			$result = 'results.notMet';
		}

		// Flags and notes (sheet section 4).
		$flags = array();
		if ( null !== $sys && ( $sys >= self::FLAG_LEVELS['bp_urgent']['sys'] || $dia >= self::FLAG_LEVELS['bp_urgent']['dia'] ) ) {
			$flags[] = 'flags.bpUrgent';
		}
		if ( null !== $fpg['value'] ) {
			$u = $fpg['unit'];
			$v = $fpg['value'];
			if ( $v >= self::FLAG_LEVELS['glucose_urgent_high'][ $u ] ) {
				$flags[]              = 'flags.glucoseUrgent';
				$out['glucoseUrgent'] = 'high';
			} elseif ( $v < self::FLAG_LEVELS['glucose_urgent_low'][ $u ] ) {
				$flags[]              = 'flags.glucoseUrgent';
				$out['glucoseUrgent'] = 'low';
			} elseif ( $fasting && $v >= self::FLAG_LEVELS['glucose_diagnostic'][ $u ] ) {
				$flags[] = 'flags.glucoseDiagnostic';
			} elseif ( $fasting && $v < self::FLAG_LEVELS['glucose_low'][ $u ] ) {
				$flags[] = 'flags.glucoseLow';
			}
		}
		if ( null !== $tg['value'] && $tg['value'] >= self::FLAG_LEVELS['triglycerides_high'][ $tg['unit'] ] ) {
			$flags[] = 'flags.triglyceridesHigh';
		}
		if ( ! $central && $count >= self::TALK_TO_DOCTOR_MIN ) {
			$flags[] = 'notes.talkToDoctor';
		}
		if ( 'PNTS' === $sex ) {
			$flags[] = 'notes.sex';
		}
		if ( 'other' === $group ) {
			$flags[] = 'notes.background';
		}
		$notes = array();
		if ( $t2d ) {
			$notes[] = 'notes.diabetes';
		}
		if ( null !== $fpg['value'] && ! $fasting ) {
			$notes[] = 'notes.useFasting';
		}

		// Lifestyle suggestions, one per raised component (sheet 3.3). An eating-disorder answer (or "prefer not to
		// say", cautiously) hides the weight-loss line and shows activity and sleep instead (D3-25).
		$lifestyle = array();
		$waist_shown = false;
		if ( $central ) {
			if ( 'no' === $eating ) {
				$lifestyle[] = 'lifestyle.waist';
				$waist_shown = true;
			} else {
				$lifestyle[] = 'lifestyle.activitySleep';
			}
		}
		foreach ( self::CRITERIA as $c ) {
			if ( 'met' === $criteria[ $c ] ) {
				$lifestyle[] = 'lifestyle.' . $c;
			}
		}

		$out['central']             = $central;
		$out['waistCm']             = self::r1( $waist );
		$out['waistCutoffCm']       = $cutoff;
		$out['criteria']            = $criteria;
		$out['count']               = $count;
		$out['result']              = $result;
		$out['flags']               = $flags;
		$out['notes']               = $notes;
		$out['lifestyle']           = $lifestyle;
		$out['lifestyleWaistShown'] = $waist_shown;
		$out['components']          = self::components( $criteria, $labs, $sys, $dia, $bp_med, $tg_med, $hdl_med, $t2d, $fpg_used, $hdl_sex );
		$out['placeholders']        = array(
			'n'                                              => $count,
			'missing list'                                   => self::list_labels( $out['missing'] ),
			'triglycerides, HDL cholesterol, fasting glucose' => self::list_labels( $out['missing'] ),
		);
		$out['messages'] = self::messages_for( $out );
		return $out;
	}

	/**
	 * The signed text for wording keys. Keys whose text the sheet describes but does not quote (the per-component
	 * and other lifestyle paragraphs, the "talk to your doctor" line, the D3-23 sex note, the background note, the
	 * fasting note, the under-18 stop) come from the approved content library and are left out here.
	 *
	 * @param string[] $keys   Wording keys, as compute() returned them.
	 * @param array    $result The compute() result (decides low or high glucose urgent wording).
	 * @return array<string,string>
	 */
	public static function wording( array $keys, array $result ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( isset( self::MESSAGES[ $key ] ) ) {
				$out[ $key ] = self::MESSAGES[ $key ];
			} elseif ( isset( self::QUOTED[ $key ] ) ) {
				$out[ $key ] = self::QUOTED[ $key ];
			} elseif ( 'flags.glucoseUrgent' === $key && class_exists( 'LLD_Members_Stage3_Safety' ) ) {
				$which = isset( $result['glucoseUrgent'] ) && 'low' === $result['glucoseUrgent'] ? 'glucose.urgent-low' : 'glucose.urgent-high';
				$out[ $key ] = LLD_Members_Stage3_Safety::MESSAGES[ $which ];
			}
		}
		return $out;
	}

	/**
	 * The wording keys a result shows, in order: the stop alone; or the urgent wording first, then the headline and
	 * body, the other flags and notes, the lifestyle paragraphs and the "about" line.
	 *
	 * @param array $out A compute() result.
	 * @return string[]
	 */
	private static function messages_for( array $out ) {
		if ( null !== $out['stop'] ) {
			return array( $out['stop'] );
		}
		$urgent = array( 'flags.bpUrgent', 'flags.glucoseUrgent' );
		$keys   = array_values( array_intersect( $out['flags'], $urgent ) );
		$keys[] = $out['result'] . '.headline';
		$keys[] = $out['result'] . '.body';
		foreach ( array_merge( array_diff( $out['flags'], $urgent ), $out['notes'], $out['lifestyle'] ) as $key ) {
			$keys[] = $key;
		}
		$keys[] = 'about.definition';
		return $keys;
	}

	/**
	 * One row per component (sheet 3.1): the value entered, its unit, the threshold in that unit, the status and the
	 * value converted to the other unit for display.
	 */
	private static function components( array $criteria, array $labs, $sys, $dia, $bp_med, $tg_med, $hdl_med, $t2d, $fpg_used, $hdl_sex ) {
		$rows = array();
		foreach ( array( 'triglycerides', 'hdl', 'fastingGlucose' ) as $field ) {
			$lab       = $labs[ $field ];
			$threshold = 'hdl' === $field ? self::THRESHOLDS['hdl'][ $hdl_sex ] : self::THRESHOLDS[ $field ];
			$row       = array(
				'component'  => $field,
				'status'     => $criteria[ $field ],
				'value'      => 'fastingGlucose' === $field && ! $fpg_used ? null : $lab['value'],
				'unit'       => null === $lab['unit'] ? null : ( 'mg' === $lab['unit'] ? 'mg/dL' : 'mmol/L' ),
				'threshold'  => null === $lab['unit'] ? null : $threshold[ $lab['unit'] ],
				'comparison' => 'hdl' === $field ? 'below' : 'at or above',
				'converted'  => null,
				'treatment'  => ( 'triglycerides' === $field && $tg_med ) || ( 'hdl' === $field && $hdl_med ) || ( 'fastingGlucose' === $field && $t2d ),
			);
			if ( null !== $row['value'] ) {
				$row['converted'] = 'mg' === $lab['unit']
					? array( 'value' => self::r2( $lab['value'] / self::MG_PER_MMOL[ $field ] ), 'unit' => 'mmol/L' )
					: array( 'value' => (int) round( $lab['value'] * self::MG_PER_MMOL[ $field ], 0, PHP_ROUND_HALF_UP ), 'unit' => 'mg/dL' );
			}
			$rows[] = $row;
		}
		$rows[] = array(
			'component'  => 'bloodPressure',
			'status'     => $criteria['bloodPressure'],
			'value'      => null === $sys ? null : array( 'systolic' => $sys, 'diastolic' => $dia ),
			'unit'       => 'mmHg',
			'threshold'  => self::THRESHOLDS['bloodPressure'],
			'comparison' => 'at or above',
			'converted'  => null,
			'treatment'  => (bool) $bp_med,
		);
		return $rows;
	}

	/**
	 * "a", "a and b", "a, b and c" from the signed labels, in the sheet's order.
	 *
	 * @param string[] $missing Criterion keys.
	 */
	private static function list_labels( array $missing ) {
		$labels = array();
		foreach ( $missing as $m ) {
			if ( isset( self::MISSING_LABELS[ $m ] ) ) {
				$labels[] = self::MISSING_LABELS[ $m ];
			}
		}
		if ( count( $labels ) < 2 ) {
			return implode( '', $labels );
		}
		$last = array_pop( $labels );
		return implode( ', ', $labels ) . ' and ' . $last;
	}

	/**
	 * @param array  $out  Result so far.
	 * @param string $what under18 or pregnancy.
	 */
	private static function stop( array $out, $what ) {
		$out['stop']     = 'stops.' . $what;
		$out['messages'] = array( $out['stop'] );
		return $out;
	}

	/**
	 * @param string $field The field that failed.
	 */
	private static function invalid( $field ) {
		return array(
			'error'   => $field,
			'version' => self::VERSION,
		);
	}

	/**
	 * A numeric answer: the number, null when left empty or "don't know", false when not a number.
	 *
	 * @param array  $in    Answers.
	 * @param string $field Field name.
	 * @return float|null|false
	 */
	private static function number( array $in, $field ) {
		if ( ! array_key_exists( $field, $in ) || null === $in[ $field ] || '' === $in[ $field ] ) {
			return null;
		}
		$v = $in[ $field ];
		if ( is_bool( $v ) || ! is_scalar( $v ) ) {
			return false;
		}
		if ( is_string( $v ) ) {
			$k = strtolower( preg_replace( '/[^a-z]/i', '', $v ) );
			if ( in_array( $k, array( 'dontknow', 'unknown', 'notknown' ), true ) ) {
				return null;
			}
		}
		return is_numeric( $v ) ? (float) $v : false;
	}

	/**
	 * Waist in cm from cm or inches (1 in = 2.54 cm), unrounded; null when missing or not a number.
	 *
	 * @param array $in Answers.
	 */
	private static function waist_cm( array $in ) {
		$cm = self::number( $in, 'waistCm' );
		if ( is_float( $cm ) ) {
			return $cm;
		}
		$inch = self::number( $in, 'waistIn' );
		return is_float( $inch ) ? $inch * self::CM_PER_INCH : null;
	}

	/**
	 * @param mixed $value Unit as entered.
	 * @return string|null mmol, mg or null.
	 */
	private static function unit( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$v = strtolower( preg_replace( '/[^a-z]/i', '', (string) $value ) );
		if ( in_array( $v, array( 'mmoll', 'mmol' ), true ) ) {
			return 'mmol';
		}
		if ( in_array( $v, array( 'mgdl', 'mg' ), true ) ) {
			return 'mg';
		}
		return null;
	}

	/**
	 * The IDF group for a family-background answer (the Healthy Weight Check list or the IDF names). Anything else,
	 * including mixed, other Asian, Black Caribbean and prefer not to say, is "other" (94 cm men, 80 cm women, with a
	 * note that lower cut-offs apply to some backgrounds).
	 *
	 * @param mixed $value Background answer.
	 */
	public static function background( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 'other';
		}
		$v   = strtolower( preg_replace( '/[^a-z]/i', '', (string) $value ) );
		$map = array(
			'europid'                              => 'europid',
			'white'                                => 'europid',
			'southasian'                           => 'south_asian',
			'indian'                               => 'south_asian',
			'pakistani'                            => 'south_asian',
			'bangladeshi'                          => 'south_asian',
			'othersouthasian'                      => 'south_asian',
			'chinese'                              => 'chinese',
			'japanese'                             => 'japanese',
			'southandcentralamerican'              => 'south_central_american',
			'ethnicsouthandcentralamerican'        => 'south_central_american',
			'southcentralamerican'                 => 'south_central_american',
			'subsaharanafrican'                    => 'sub_saharan_african',
			'blackafrican'                         => 'sub_saharan_african',
			'middleeastern'                        => 'middle_eastern',
			'arab'                                 => 'middle_eastern',
			'easternmediterranean'                 => 'middle_eastern',
			'easternmediterraneanandmiddleeast'    => 'middle_eastern',
			'easternmediterraneanandmiddleeastarab' => 'middle_eastern',
		);
		return isset( $map[ $v ] ) ? $map[ $v ] : 'other';
	}

	/**
	 * @param mixed $value Sex answer in any accepted spelling.
	 * @return string F, M, PNTS or ''.
	 */
	private static function sex( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$v = strtolower( trim( (string) $value ) );
		if ( in_array( $v, array( 'f', 'female', 'woman' ), true ) ) {
			return 'F';
		}
		if ( in_array( $v, array( 'm', 'male', 'man' ), true ) ) {
			return 'M';
		}
		if ( in_array( $v, array( 'pnts', 'prefer not to say', 'prefer_not', 'prefer-not-to-say', 'prefer_not_to_say' ), true ) ) {
			return 'PNTS';
		}
		return '';
	}

	/**
	 * @param mixed    $value   Answer.
	 * @param string[] $allowed Accepted answers.
	 */
	private static function answer( $value, array $allowed ) {
		if ( is_bool( $value ) ) {
			$value = $value ? 'yes' : 'no';
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$v = strtolower( str_replace( array( ' ', '-', '_' ), '', trim( (string) $value ) ) );
		if ( 'prefernottosay' === $v || 'prefernot' === $v ) {
			$v = 'pnts';
		}
		return in_array( $v, $allowed, true ) ? $v : null;
	}

	/** Two decimals, half-up (display only). */
	private static function r2( $x ) {
		return round( $x, 2, PHP_ROUND_HALF_UP );
	}

	/** One decimal, half-up (display only). */
	private static function r1( $x ) {
		return round( $x, 1, PHP_ROUND_HALF_UP );
	}
}
