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
 * (`Transcription-Open-Points-2026-11-03.md`, MS-12), except the "talk to your doctor" line (see TALK_TO_DOCTOR_MIN).
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

	/**
	 * Accepted ranges in each unit (sheet section 2); outside them the answer is refused. Blood pressure and waist use
	 * the safety matrix's impossible ranges (LLD_Members_Stage3_Safety::LEVELS, P3-51), never retyped here.
	 */
	const RANGES = array(
		'triglycerides'  => array( 'mmol' => array( 0.2, 20 ), 'mg' => array( 18, 1770 ) ),
		'hdl'            => array( 'mmol' => array( 0.3, 5 ), 'mg' => array( 12, 195 ) ),
		'fastingGlucose' => array( 'mmol' => array( 2, 30 ), 'mg' => array( 36, 540 ) ),
		'age'            => array( 18, 99 ),
	);

	/**
	 * The metabolic check's own flags (sheet section 4), in each unit: 11.1 mmol/L (200 mg/dL) or more is the
	 * diagnostic-range line, below 3.9 (70) the low note, triglycerides 10 mmol/L (885 mg/dL) or more "medical review
	 * soon". The urgent levels (blood pressure, and glucose below 3.0 or at 15 or more) are decided by the safety
	 * matrix, LLD_Members_Stage3_Safety::warning_level() (P3-03, D5), so they are the logging screen's answer too.
	 */
	const FLAG_LEVELS = array(
		'glucose_diagnostic' => array( 'mmol' => 11.1, 'mg' => 200 ),
		'glucose_low'        => array( 'mmol' => 3.9, 'mg' => 70 ),
		'triglycerides_high' => array( 'mmol' => 10, 'mg' => 885 ),
	);

	/**
	 * With a waist below the cut-off, the "talk to your doctor" line is shown from this many raised results: 2, as
	 * the sheet's section 3 says (owner, 3 Nov 2026, Owner_Questions 90; fixture MS-14 re-derived by ORC-10).
	 */
	const TALK_TO_DOCTOR_MIN = 2;

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
		'flags.glucoseDiagnosticNonFasting' => "A glucose of 11.1 or more, even when you haven't fasted, is in the range doctors use to diagnose diabetes. Please see your doctor soon to have it checked.", // CW-23 (Owner_Questions 104)
		'flags.glucoseLow'                 => "That's a low fasting glucose. If you take diabetes medicine, talk to your doctor; if you had symptoms (shaky, sweaty, confused), see your doctor.",
		'flags.triglyceridesHigh'          => 'Very high triglycerides need medical review soon: please contact your doctor.',
		'notes.diabetes'                   => 'With diabetes, your diabetes team already monitors these results.',
		'stops.under18'                    => 'This check is for adults. Waist and blood test results are judged differently in children and teenagers, because healthy levels change with age and growth. Your doctor or school nurse can advise.', // CW-03
		'notes.talkToDoctor'               => "Even though the definition isn't met, having two or more of these results above their thresholds is worth talking to your doctor about. Please share these results with them.", // CW-04
		'notes.sex'                        => "We've used the more cautious of the two sets of cut-offs: a waist of 80 cm (31.5 in) and HDL cholesterol below 1.29 mmol/L (50 mg/dL). This means a result may show as raised when it wouldn't for some people. Choose a sex above for a closer check.", // CW-05
		'notes.background'                 => "We've used the general waist cut-off: 94 cm (37 in) for men and 80 cm (31.5 in) for women. For men of South Asian, Chinese, Japanese, or South or Central American background, the cut-off is lower, 90 cm (35.4 in). If that applies to you, choose that background above, or ask your doctor which cut-off suits you.", // CW-06
		'notes.useFasting'                 => "This check needs a fasting glucose result, so we haven't used the one you entered. A fasting test is taken after at least 8 hours with nothing to eat or drink except water. If you have a fasting result, enter it above, or ask your doctor about having one.", // CW-07
		'lifestyle.activitySleep'          => 'Regular activity and enough sleep help with all of these results. Aim for about 150 minutes of moderate activity a week, such as brisk walking, spread over most days, and 7 to 9 hours of sleep a night.', // CW-08
		'lifestyle.triglycerides'          => 'Triglycerides can come down with a few everyday changes: less alcohol, fewer sugary drinks and sweet foods, whole grains in place of refined ones (for example brown rice or wholemeal bread instead of white), oily fish such as salmon, sardines or mackerel once or twice a week, and regular activity. If you take medicine for this, keep taking it as prescribed.', // CW-09
		'lifestyle.hdl'                    => 'HDL is sometimes called "good" cholesterol. Regular activity that leaves you a little out of breath, such as brisk walking, cycling or swimming, is one of the best ways to raise it. If you smoke, stopping helps too. So does swapping saturated fats (butter, ghee, coconut or palm oil, fatty meat) for unsaturated ones (olive, rapeseed (canola) or sunflower oil, nuts and seeds). If you take medicine for your cholesterol, keep taking it as prescribed.', // CW-10
		'lifestyle.bloodPressure'          => 'Blood pressure can improve with everyday changes: less salt, more vegetables, fruit and pulses (beans, lentils, chickpeas), regular activity such as brisk walking, and less alcohol. If you have kidney disease, or have been told to limit potassium, ask your doctor before eating much more fruit, vegetables or pulses. If you take blood pressure medicine, keep taking it as prescribed.', // CW-11
		'lifestyle.fastingGlucose'         => 'Fasting blood sugar can improve with a short walk after meals, more fibre (vegetables, pulses, whole grains, and whole fruit rather than juice) and fewer sugary drinks. The Blood Sugar Balance track builds these habits step by step. If you take diabetes medicine, keep taking it as prescribed.', // CW-12
	);

	/**
	 * Blood-pressure wording quoted from the Blood Pressure Reading Check (P3-58, LLD_Members_BP_Check::signed_text(),
	 * hashed in approvals/stage3/bp-check.json), never retyped: this check's key => the BP check's key.
	 */
	const BP_TEXT = array(
		'flags.bpUrgent'           => 'urgent.alert',
		'flags.bpUrgentPregnancy'  => 'pregnancy.urgent',
		'flags.bpSameDayPregnancy' => 'pregnancy.same-day',
		'refused.impossible'       => 'refused.impossible',
	);

	/** warning_level() blood-pressure keys (P3-51) and this check's flag for each; other levels add nothing here. */
	const BP_FLAGS = array(
		'bp-check.urgent.alert'       => 'flags.bpUrgent',
		'bp-check.pregnancy.urgent'   => 'flags.bpUrgentPregnancy',
		'bp-check.pregnancy.same-day' => 'flags.bpSameDayPregnancy',
	);

	/** The safety wording shown before anything else, a stop included (P3-03 A1). */
	const SAFETY_FIRST = array( 'flags.bpUrgent', 'flags.bpUrgentPregnancy', 'flags.bpSameDayPregnancy', 'flags.glucoseUrgent' );

	/** The yes/no answers and ticks, with the value used when left out. */
	const YES_NO = array(
		'bpMedicine'            => false,
		'triglyceridesMedicine' => false,
		'hdlMedicine'           => false,
		'type2Diabetes'         => false,
		'fastingGlucoseFasting' => true,
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
	 *                  true), type2Diabetes, kidneyDisease. Yes/no answers: yes, no, true, false, 1, 0. A blood value
	 *                  left empty (or "don't know") is unknown.
	 * @return array stop (null, stops.under18 or stops.pregnancy), refused, refusedField, central, waistCm, waistCutoffCm, criteria,
	 *               count, result, missing, flags, notes, components, lifestyle, lifestyleWaistShown, glucoseUrgent,
	 *               placeholders, messages, version; or error (the field to check).
	 */
	public static function compute( array $in ) {
		$out    = array(
			'stop'                => null,
			'refused'             => false,
			'refusedField'        => null,
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
		$safety = self::safety_class();

		// Answers first: age, sex, pregnancy, eating and the yes/no ticks.
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
		$ticks = array();
		foreach ( self::YES_NO as $field => $default ) {
			$ticks[ $field ] = self::yes_no( array_key_exists( $field, $in ) ? $in[ $field ] : null, $default );
			if ( null === $ticks[ $field ] ) {
				return self::invalid( $field );
			}
		}
		$bp_med  = $ticks['bpMedicine'];
		$tg_med  = $ticks['triglyceridesMedicine'];
		$hdl_med = $ticks['hdlMedicine'];
		$t2d     = $ticks['type2Diabetes'];
		$fasting = $ticks['fastingGlucoseFasting'];

		// Blood pressure and glucose before the stops: their urgent wording comes before anything else, a stop included
		// (P3-03 A1; D5: the urgent level applies on any blood-pressure input). Whole mmHg, half-up, before the levels.
		$sys = self::number( $in, 'bpSystolic' );
		$dia = self::number( $in, 'bpDiastolic' );
		if ( false === $sys || false === $dia || ( null === $sys ) !== ( null === $dia ) ) {
			return self::invalid( 'bloodPressure' );
		}
		$fpg = self::lab( $in, 'fastingGlucose' );
		if ( isset( $fpg['error'] ) ) {
			return self::invalid( $fpg['error'] );
		}
		$flags   = array();
		$refused = false;
		if ( null !== $sys ) {
			$sys   = self::half_up( $sys );
			$dia   = self::half_up( $dia );
			$level = $safety::warning_level( 'blood_pressure', array( 'sys' => $sys, 'dia' => $dia ), 'no' !== $pregnant ? array( 'pregnancy' ) : array() );
			if ( 'impossible' === $level['level'] ) {
				$refused = true;
			} elseif ( isset( self::BP_FLAGS[ $level['key'] ] ) ) {
				$flags[] = self::BP_FLAGS[ $level['key'] ];
			}
		}
		if ( null !== $fpg['value'] ) {
			$level = $safety::warning_level( 'glucose', array( 'value_1' => $fpg['value'], 'unit' => 'mg' === $fpg['unit'] ? 'mg/dL' : 'mmol/L' ) );
			if ( 'urgent' === $level['level'] ) {
				$flags[]              = 'flags.glucoseUrgent';
				$out['glucoseUrgent'] = 'glucose.urgent-low' === $level['key'] ? 'low' : 'high';
			}
		}
		$out['flags'] = $flags;
		if ( $refused ) {
			// A reading that isn't possible is refused as the Blood Pressure Check refuses it (P3-58 section 4.4).
			$out['refused']      = true;
			$out['refusedField'] = 'bloodPressure';
			$out['messages']     = array_merge( $flags, array( 'refused.impossible' ) );
			return $out;
		}
		if ( null !== $age && $age < self::RANGES['age'][0] ) {
			return self::stop( $out, 'under18' );
		}
		if ( 'no' !== $pregnant ) {
			return self::stop( $out, 'pregnancy' );
		}
		if ( $fpg['outOfRange'] ) {
			// A glucose the safety matrix accepts but outside the sheet's input range (2-30 mmol/L, 36-540 mg/dL): its
			// urgent wording is shown, and no metabolic result is given from it.
			$out['refused']      = true;
			$out['refusedField'] = 'fastingGlucose';
			$out['messages']     = $flags;
			return $out;
		}

		// Waist (required) and its cut-off.
		$waist  = self::waist_cm( $in );
		$wrange = $safety::LEVELS['waist']['impossible'];
		if ( null === $waist || $waist < $wrange[0] || $waist > $wrange[1] ) {
			return self::invalid( 'waist' );
		}
		$group   = self::background( isset( $in['background'] ) ? $in['background'] : '' );
		$cutoff  = 'M' === $sex ? ( in_array( $group, self::WAIST_MALE_90, true ) ? 90 : self::WAIST_MALE ) : self::WAIST_FEMALE;
		$central = $waist >= $cutoff;

		// Blood pressure is required unless the medicine box is ticked (then counted as met).
		if ( null === $sys && ! $bp_med ) {
			return self::invalid( 'bloodPressure' );
		}

		// The other two blood values: each in its own unit, or unknown.
		$labs = array();
		foreach ( array( 'triglycerides', 'hdl' ) as $field ) {
			$labs[ $field ] = self::lab( $in, $field );
			if ( isset( $labs[ $field ]['error'] ) ) {
				return self::invalid( $labs[ $field ]['error'] );
			}
		}
		$labs['fastingGlucose'] = $fpg;

		// Criteria (sheet section 1, applied in the unit entered).
		$criteria = array();
		$tg       = $labs['triglycerides'];
		$criteria['triglycerides'] = $tg_med ? 'met' : ( null === $tg['value'] ? 'unknown' : ( $tg['value'] >= self::THRESHOLDS['triglycerides'][ $tg['unit'] ] ? 'met' : 'not-met' ) );
		$hdl      = $labs['hdl'];
		$hdl_sex  = 'M' === $sex ? 'M' : 'F';
		$criteria['hdl'] = $hdl_med ? 'met' : ( null === $hdl['value'] ? 'unknown' : ( $hdl['value'] < self::THRESHOLDS['hdl'][ $hdl_sex ][ $hdl['unit'] ] ? 'met' : 'not-met' ) );
		$criteria['bloodPressure'] = $bp_med || ( null !== $sys && ( $sys >= self::THRESHOLDS['bloodPressure']['sys'] || $dia >= self::THRESHOLDS['bloodPressure']['dia'] ) ) ? 'met' : 'not-met';
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

		// The check's own flags and notes (sheet section 4), after the urgent levels above. A non-fasting glucose of
		// 11.1 (200) or more gets its own diagnostic-range line, since 11.1 is the random-glucose level too
		// (Owner_Questions 101, decided 4 Nov 2026), with its own wording, CW-23 (Owner_Questions 104). The low line stays about a fasting result.
		if ( null !== $fpg['value'] && ! $fasting && null === $out['glucoseUrgent'] && $fpg['value'] >= self::FLAG_LEVELS['glucose_diagnostic'][ $fpg['unit'] ] ) {
			$flags[] = 'flags.glucoseDiagnosticNonFasting';
		}
		if ( $fpg_used && null === $out['glucoseUrgent'] ) {
			if ( $fpg['value'] >= self::FLAG_LEVELS['glucose_diagnostic'][ $fpg['unit'] ] ) {
				$flags[] = 'flags.glucoseDiagnostic';
			} elseif ( $fpg['value'] < self::FLAG_LEVELS['glucose_low'][ $fpg['unit'] ] ) {
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
		$lifestyle   = array();
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
		$out['waistCm']             = self::r2( $waist ); // 2 dp: a converted waist shows as it was compared (37 in = 93.98)
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
			'n'                                               => $count,
			'missing list'                                    => self::list_labels( $out['missing'] ),
			'triglycerides, HDL cholesterol, fasting glucose' => self::list_labels( $out['missing'] ),
		);
		$out['messages'] = self::messages_for( $out );
		return $out;
	}

	/**
	 * The signed text for wording keys: this check's own, the Blood Pressure Check's (BP_TEXT) and the safety
	 * matrix's glucose urgent wording (low or high, from the result). The texts the sheet describes but does not
	 * quote (the under-18 stop, the four notes and the five lifestyle paragraphs) are CW-03 to CW-12, approved by
	 * the owner on 3 November 2026 and held in MESSAGES with the rest.
	 *
	 * @param string[] $keys   Wording keys, as compute() returned them.
	 * @param array    $result The compute() result (decides low or high glucose urgent wording).
	 * @return array<string,string>
	 */
	public static function wording( array $keys, array $result = array() ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( isset( self::MESSAGES[ $key ] ) ) {
				$out[ $key ] = self::MESSAGES[ $key ];
			} elseif ( isset( self::BP_TEXT[ $key ] ) ) {
				$bp = self::bp_text();
				if ( isset( $bp[ self::BP_TEXT[ $key ] ] ) ) {
					$out[ $key ] = $bp[ self::BP_TEXT[ $key ] ];
				}
			} elseif ( 'flags.glucoseUrgent' === $key ) {
				$which       = isset( $result['glucoseUrgent'] ) && 'low' === $result['glucoseUrgent'] ? 'glucose.urgent-low' : 'glucose.urgent-high';
				$messages    = self::safety_class()::MESSAGES;
				$out[ $key ] = $messages[ $which ];
			}
		}
		return $out;
	}

	/**
	 * The wording keys a result shows, in order: the urgent wording first, then the headline and body, the other
	 * flags and notes, the lifestyle paragraphs and the "about" line.
	 *
	 * @param array $out A compute() result.
	 * @return string[]
	 */
	private static function messages_for( array $out ) {
		$keys   = array_values( array_intersect( $out['flags'], self::SAFETY_FIRST ) );
		$keys[] = $out['result'] . '.headline';
		$keys[] = $out['result'] . '.body';
		foreach ( array_merge( array_diff( $out['flags'], self::SAFETY_FIRST ), $out['notes'], $out['lifestyle'] ) as $key ) {
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
		$out['messages'] = array_merge( $out['flags'], array( $out['stop'] ) ); // the urgent wording first (P3-03 A1)
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
		} elseif ( 'true' === $v || '1' === $v ) {
			$v = 'yes';
		} elseif ( 'false' === $v || '0' === $v ) {
			$v = 'no';
		}
		return in_array( $v, $allowed, true ) ? $v : null;
	}

	/**
	 * One yes/no parser for every tick (bpMedicine, triglyceridesMedicine, hdlMedicine, type2Diabetes,
	 * fastingGlucoseFasting): yes, no, true, false (strings or booleans), 1, 0.
	 *
	 * @param mixed $value   Answer.
	 * @param bool  $default Used when the answer is left out (null or empty).
	 * @return bool|null null when the answer is not a yes/no.
	 */
	private static function yes_no( $value, $default ) {
		if ( null === $value || '' === $value ) {
			return $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$v = strtolower( trim( (string) $value ) );
		if ( in_array( $v, array( 'yes', 'true', '1' ), true ) ) {
			return true;
		}
		return in_array( $v, array( 'no', 'false', '0' ), true ) ? false : null;
	}

	/**
	 * A blood value in its own unit: value and unit (mmol or mg), both null when not given; or error.
	 *
	 * Fasting glucose is the exception: a value outside the sheet's input range that the safety matrix still treats
	 * as possible (LLD_Members_Stage3_Safety::warning_level(), P3-51) is returned with outOfRange set, so its urgent
	 * wording can be shown; only a value the matrix calls impossible is an error.
	 *
	 * @param array  $in    Answers.
	 * @param string $field triglycerides, hdl or fastingGlucose.
	 */
	private static function lab( array $in, $field ) {
		$value = self::number( $in, $field );
		if ( false === $value ) {
			return array( 'error' => $field );
		}
		$unit  = null;
		$outside = false;
		if ( null !== $value ) {
			$unit = self::unit( isset( $in[ $field . 'Unit' ] ) ? $in[ $field . 'Unit' ] : null );
			if ( null === $unit ) {
				return array( 'error' => $field . 'Unit' );
			}
			$range = self::RANGES[ $field ][ $unit ];
			if ( $value < $range[0] || $value > $range[1] ) {
				if ( 'fastingGlucose' !== $field ) {
					return array( 'error' => $field );
				}
				$level = self::safety_class()::warning_level( 'glucose', array( 'value_1' => $value, 'unit' => 'mg' === $unit ? 'mg/dL' : 'mmol/L' ) );
				if ( 'impossible' === $level['level'] ) {
					return array( 'error' => $field );
				}
				$outside = true;
			}
		}
		return array( 'value' => $value, 'unit' => $unit, 'outOfRange' => $outside );
	}

	/**
	 * The safety matrix class, loaded on first use when the engine runs on its own (PHP CLI tests); in WordPress the
	 * Stage 3 loader has already loaded it.
	 */
	private static function safety_class() {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			require_once dirname( __DIR__ ) . '/class-safety-stage3.php';
		}
		return 'LLD_Members_Stage3_Safety';
	}

	/** The Blood Pressure Reading Check's signed text (P3-58), loaded on first use as safety_class() is. */
	private static function bp_text() {
		if ( ! class_exists( 'LLD_Members_BP_Check' ) ) {
			require_once __DIR__ . '/class-bp-check.php';
		}
		return LLD_Members_BP_Check::signed_text();
	}

	/** Whole mmHg, half-up, as the heart-risk check rounds systolic (the numbers are positive). */
	private static function half_up( $x ) {
		return (int) round( $x, 0, PHP_ROUND_HALF_UP );
	}

	/** Two decimals, half-up (display only). */
	private static function r2( $x ) {
		return round( $x, 2, PHP_ROUND_HALF_UP );
	}
}
