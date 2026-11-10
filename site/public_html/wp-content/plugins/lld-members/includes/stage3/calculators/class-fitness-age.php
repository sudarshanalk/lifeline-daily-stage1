<?php
/**
 * Fitness Age engine (P3-5A part 1, FR3-52), written from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-5A-Fitness-Age-HR-Zones-Sleep-Stress-Method-Sheet.md` (approval record
 * `approvals/stage3/fitness-age.json`, fixtures `fixtures/fitness-age/`).
 *
 * Pure arithmetic and rules: no WordPress, no database, no member lookup. The caller supplies the answers and the
 * member's profile (apply_profile) and decides what to store.
 *
 * HUNT non-exercise VO2peak model (Nes 2011; coefficients as reproduced in Peterman et al., JAHA 2020, accepted by
 * the owner, D3-32), the HUNT activity index (frequency x intensity x duration), and the site's fitness-age
 * definition (D3-26): the age at which the same-sex reference person (waist 94 cm men / 80 cm women, resting heart
 * rate 70, activity index 3.75) has the member's VO2peak, on the model's own age slope; whole years half-up, clamped
 * 18 to 90. VO2 to 1 dp half-up. "Prefer not to say" cannot calculate (D3-27). Every stop wins over every note.
 * Frozen: any change to these rules or the wording needs a new decision and a new version (NFR3-14).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens and the tests read.
 */
class LLD_Members_Fitness_Age {

	const VERSION = '1.0';

	/** Nes 2011 by sex: intercept, age, waist (cm), resting heart rate, activity index (sheet 1.1). */
	const COEF = array(
		'M' => array( 'c' => 100.27, 'age' => 0.296, 'waist' => 0.369, 'rhr' => 0.155, 'pa' => 0.226 ),
		'F' => array( 'c' => 74.736, 'age' => 0.247, 'waist' => 0.259, 'rhr' => 0.114, 'pa' => 0.198 ),
	);

	/** The reference person (D3-26). */
	const REF_WAIST = array( 'M' => 94, 'F' => 80 );
	const REF_RHR   = 70;
	const REF_PA    = 3.75;

	/** Clamp for the shown fitness age (D3-26). */
	const AGE_MIN = 18;
	const AGE_MAX = 90;

	/** Below this estimate (mL/kg/min, shown 1 dp) no result is given: stop cannotEstimate (lead review, P3-5A). */
	const VO2_FLOOR = 10;

	/** "Almost every day, sweating, 30-60 minutes" (5 x 2 x 0.75), the activity lever's target (sheet 1.4). */
	const PA_TARGET = 7.5;

	/** HUNT activity questions (sheet 1.1, Kurtze 2008): normalised answer => score. */
	const FREQUENCY = array(
		'never'             => 0,
		'lessthanonceaweek' => 0,
		'ltonce'            => 0,
		'onceaweek'         => 1,
		'once'              => 1,
		'23timesaweek'      => 2.5,
		'23'                => 2.5,
		'almosteveryday'    => 5,
		'daily'             => 5,
	);
	const INTENSITY = array(
		'easynosweating'            => 1,
		'easy'                      => 1,
		'sweatingheavierbreathing'  => 2,
		'sweating'                  => 2,
		'nearexhaustion'            => 3,
		'exhaustion'                => 3,
	);
	const DURATION = array(
		'under15min' => 0.10,
		'lt15'       => 0.10,
		'1529min'    => 0.38,
		'1529'       => 0.38,
		'3060min'    => 0.75,
		'3060'       => 0.75,
		'over1hour'  => 1.0,
		'gt60'       => 1.0,
	);

	/**
	 * The signed wording (sheet sections 1.3 to 1.5). Keys and text are hashed in the approval record's copy_hashes;
	 * the test checks them. Placeholders in braces are filled by the screen from the result's `fill`.
	 */
	const MESSAGES = array(
		'input.rhrHint'         => 'taken sitting quietly for 5 minutes, before caffeine',
		'result.vo2'            => 'Your estimated fitness level (VO₂max) is about {vo2} mL/kg/min',
		'result.fitnessAge'     => "That's typical of a {fitness_age}-year-old",
		'result.clampedLow'     => 'better than typical for any age',
		'result.clampedHigh'    => 'lower than typical at any age; a doctor can check for causes and help you build it safely',
		'improve.activity'      => "going from {current} to 'almost every day, sweating, 30–60 minutes' would raise your estimate by about {delta} points",
		'improve.rhr'           => 'regular aerobic exercise lowers resting pulse; each 10 bpm lower adds about {b_RHR×10} points',
		'improve.waist'         => 'each 5 cm off the waist adds about {b_waist×5} points',
		'premium.change'        => '{+2.1} points since {date}',
		'always.estimate'       => "An estimate from a questionnaire, not a fitness test (±5–6 points for most people). It's for healthy adults; medicines that slow the heart make it inaccurate.",
		'safety.medicine'       => 'Medicines that slow your heart make this estimate unreliable. Your doctor or a supervised fitness test can give a better picture.',
		'safety.rhrOver100'     => "A resting pulse over 100 is worth mentioning to your doctor, especially if it's new.",
		'safety.rhrUnder40'     => 'A resting pulse under 40 is common in very fit people, but if you feel faint or dizzy, see your doctor.',
		'safety.heartCondition' => 'Ask your doctor or cardiac rehab team about exercise.',
		'safety.under18'        => 'This estimate is for adults. The formula was made from adults, and fitness changes a lot as children and teenagers grow. Your doctor or school nurse can advise on staying active.', // CW-13
		'safety.pregnancy'      => "This estimate isn't suitable right now. Heart rate and waist change during pregnancy and breastfeeding, so the result wouldn't be reliable. Your midwife or doctor can advise you on staying active. The talk test is a good guide: moderate activity means you can talk but not sing.", // CW-14
		'safety.sexNotSaid'     => "We can't work out a fitness age without knowing your sex. The estimate uses separate formulas for men and women, which give quite different results, so there's no fair middle value. If you'd like a result, you can choose a sex above. A fitness test with a trained professional can also measure your fitness directly.", // CW-15
		'note.age18to19'        => 'This estimate comes from a study of adults aged 20 and over, so it may be less accurate at 18 or 19.', // CW-16
		'stops.cannotEstimate'  => "We can't give a reliable fitness estimate from these answers. Your doctor or a supervised fitness test can give a better picture.", // CW-21 (Owner_Questions 95, 96)
	);

	/**
	 * Wording quoted unchanged from the built Stage 2 plugin (sheet 1.5, "Everyone"): the workout safety line shown
	 * before any exercise suggestion (LLD_Members_Premium::WORKOUT_ADVICE). The test reads it from that file.
	 */
	const QUOTED = array(
		'workoutSafety' => 'Warm up before you start. Stop if you feel pain. Check with your doctor if you need to.',
	);

	/**
	 * Apply the member's health profile (Stage 3 safety matrix, FR3-69): pregnancy, heart-rate-lowering medicine and
	 * known heart disease stop the tool; an eating-disorder flag hides the waist lever. A profile answer only ever
	 * makes the result safer; it never clears a "yes" given on the page.
	 *
	 * @param array    $in         The answers on the page.
	 * @param string[] $situations LLD_Members_Stage3_Safety::SITUATIONS keys in force.
	 */
	public static function apply_profile( array $in, array $situations ) {
		if ( in_array( 'pregnancy', $situations, true ) ) {
			$in['pregnant'] = 'yes';
		}
		if ( in_array( 'heart_rate_medicine', $situations, true ) ) {
			$in['heartMedicine'] = 'yes';
		}
		if ( in_array( 'heart_disease', $situations, true ) ) {
			$in['heartCondition'] = 'yes';
		}
		if ( in_array( 'eating_disorder', $situations, true ) ) {
			$in['eatingDisorder'] = 'yes';
		}
		return $in;
	}

	/**
	 * Work out the result.
	 *
	 * @param array $in age, sex (female|male|prefer not to say), waistCm or waistIn, rhr, frequency, intensity,
	 *                  duration (the three HUNT answers, as the sheet words them or as short keys), heartMedicine
	 *                  (yes|no|notsure), heartCondition (yes|no), pregnant (yes|no|notsure), eatingDisorder
	 *                  (yes|no|pnts), previousVo2 and previousDate (Premium: the last saved check, FR3-52 AS-02).
	 * @return array stop, paIndex, vo2, fitnessAgeRaw, fitnessAge, clamped, notes[], waistLeverHidden, improve,
	 *               change, fill, messages (the wording keys to show, in order), version; or error.
	 */
	public static function compute( array $in ) {
		$out = array(
			'stop'             => null,
			'paIndex'          => null,
			'vo2'              => null,
			'fitnessAgeRaw'    => null,
			'fitnessAge'       => null,
			'clamped'          => null,
			'notes'            => array(),
			'waistLeverHidden' => false,
			'improve'          => null,
			'change'           => null,
			'fill'             => array(),
			'messages'         => array(),
			'version'          => self::VERSION,
		);
		// Answers first (sheet 1.3): age, sex and the safety questions; then the stops; then the measurements.
		$age = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (float) $in['age'] : null;
		if ( null === $age || $age < 0 || $age > 99 ) {
			return self::invalid( 'age' );
		}
		$sex = self::sex( isset( $in['sex'] ) ? $in['sex'] : '' );
		if ( '' === $sex ) {
			return self::invalid( 'sex' );
		}
		// The three safety answers are required: a missing answer is refused, never read as "no" (sheet 1.3, 1.5).
		// The eating-disorder answer is optional (it only hides the waist lever) and the profile sets it.
		$medicine  = self::answer( isset( $in['heartMedicine'] ) ? $in['heartMedicine'] : null, array( 'notsure' ) );
		$condition = self::answer( isset( $in['heartCondition'] ) ? $in['heartCondition'] : null, array( 'notsure' ) );
		$pregnant  = self::answer( isset( $in['pregnant'] ) ? $in['pregnant'] : null, array( 'notsure' ) );
		$eating    = self::answer( isset( $in['eatingDisorder'] ) ? $in['eatingDisorder'] : 'no', array( 'pnts' ) );
		foreach ( array( 'heartMedicine' => $medicine, 'heartCondition' => $condition, 'pregnant' => $pregnant, 'eatingDisorder' => $eating ) as $field => $value ) {
			if ( null === $value ) {
				return self::invalid( $field );
			}
		}
		if ( $age < 18 ) {
			return self::stop( $out, 'under18', 'safety.under18' );
		}
		if ( 'no' !== $pregnant ) {
			return self::stop( $out, 'pregnancy', 'safety.pregnancy' );
		}
		if ( 'no' !== $condition ) {
			return self::stop( $out, 'heartCondition', 'safety.heartCondition' );
		}
		if ( 'no' !== $medicine ) {
			return self::stop( $out, 'medicine', 'safety.medicine' );
		}
		if ( 'PNTS' === $sex ) {
			return self::stop( $out, 'sexNotSaid', 'safety.sexNotSaid' ); // the two equations differ by about 7 points (D3-27)
		}
		$waist = self::waist_cm( $in );
		if ( null === $waist || $waist < 40 || $waist > 250 ) {
			return self::invalid( 'waist' );
		}
		$rhr = isset( $in['rhr'] ) && is_numeric( $in['rhr'] ) ? (float) $in['rhr'] : null;
		if ( null === $rhr || $rhr < 30 || $rhr > 120 ) {
			return self::invalid( 'rhr' );
		}
		$pa = self::pa_index( $in );
		if ( is_string( $pa ) ) {
			return self::invalid( $pa );
		}

		$k       = self::COEF[ $sex ];
		$vo2     = $k['c'] - $k['age'] * $age - $k['waist'] * $waist - $k['rhr'] * $rhr + $k['pa'] * $pa;
		$ref0    = $k['c'] - $k['waist'] * self::REF_WAIST[ $sex ] - $k['rhr'] * self::REF_RHR + $k['pa'] * self::REF_PA;
		$raw_age = ( $ref0 - $vo2 ) / $k['age'];
		$shown   = (int) self::rnd( $raw_age, 0 );

		// The sheet's input ranges allow combinations far outside the model (for example 99, waist 250, resting 120,
		// no exercise: about -28 mL/kg/min). Below a plausible floor no number is shown (lead review, P3-5A); the
		// stop has no signed wording yet, so wording() returns null for its key.
		if ( self::rnd( $vo2, 1 ) < self::VO2_FLOOR ) {
			$out['stop']     = 'cannotEstimate';
			$out['notes']    = array( 'stops.cannotEstimate' );
			$out['messages'] = array( 'stops.cannotEstimate' );
			return $out;
		}

		$out['paIndex']       = self::rnd( $pa, 2 );
		$out['vo2']           = self::rnd( $vo2, 1 );
		$out['fitnessAgeRaw'] = self::rnd( $raw_age, 1 );
		if ( $shown < self::AGE_MIN ) {
			$shown          = self::AGE_MIN;
			$out['clamped'] = 'low';
			$out['notes'][] = 'clampedLow';
		} elseif ( $shown > self::AGE_MAX ) {
			$shown          = self::AGE_MAX;
			$out['clamped'] = 'high';
			$out['notes'][] = 'clampedHigh';
		}
		$out['fitnessAge'] = $shown;
		if ( $rhr > 100 ) {
			$out['notes'][] = 'safety.rhrOver100';
		} elseif ( $rhr < 40 ) {
			$out['notes'][] = 'safety.rhrUnder40';
		}
		if ( $age < 20 ) {
			$out['notes'][] = 'age18to19'; // the model was derived at 20 to 90 (sheet 1.3)
		}

		// How it can improve (sheet 1.4): the three levers the model contains.
		$delta                   = $pa < self::PA_TARGET ? self::rnd( $k['pa'] * ( self::PA_TARGET - $pa ), 1 ) : null;
		$out['waistLeverHidden'] = 'no' !== $eating;
		$out['improve']          = array(
			'activityDelta' => $delta,
			'rhrPer10'      => self::rnd( $k['rhr'] * 10, 1 ),
			'waistPer5'     => $out['waistLeverHidden'] ? null : self::rnd( $k['waist'] * 5, 1 ),
		);

		// Premium, signed in: the change since the last saved check (FR3-52 AS-02), on the shown values.
		if ( isset( $in['previousVo2'] ) && is_numeric( $in['previousVo2'] ) ) {
			$out['change'] = self::rnd( $out['vo2'] - (float) $in['previousVo2'], 1 );
		}

		$out['fill']     = array(
			'vo2'         => number_format( $out['vo2'], 1, '.', '' ),
			'fitness_age' => (string) $shown,
			'current'     => self::current_label( $in ),
			'delta'       => null === $delta ? null : number_format( $delta, 1, '.', '' ),
			'b_RHR×10'    => number_format( $out['improve']['rhrPer10'], 1, '.', '' ),
			'b_waist×5'   => null === $out['improve']['waistPer5'] ? null : number_format( $out['improve']['waistPer5'], 1, '.', '' ),
			'+2.1'        => null === $out['change'] ? null : ( $out['change'] >= 0 ? '+' : '−' ) . number_format( abs( $out['change'] ), 1, '.', '' ),
			'date'        => null === $out['change'] || ! isset( $in['previousDate'] ) || ! is_scalar( $in['previousDate'] ) ? null : (string) $in['previousDate'],
		);
		$out['messages'] = self::messages_for( $out );
		return $out;
	}

	/**
	 * The signed text for wording keys (`quoted.*` from QUOTED). Unknown keys are left out.
	 *
	 * @param string[] $keys   Wording keys, as compute() returns them.
	 * @param array    $result The compute() result (unused: the screen fills the placeholders from `fill`).
	 * @return array<string,string>
	 */
	public static function wording( array $keys, array $result = array() ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, 'quoted.' ) && isset( self::QUOTED[ substr( $key, 7 ) ] ) ) {
				$out[ $key ] = self::QUOTED[ substr( $key, 7 ) ];
			} elseif ( isset( self::MESSAGES[ $key ] ) ) {
				$out[ $key ] = self::MESSAGES[ $key ];
			}
		}
		return $out;
	}

	/**
	 * The HUNT activity index, or the name of the answer that is missing or not recognised.
	 *
	 * @param array $in Answers.
	 * @return float|string
	 */
	public static function pa_index( array $in ) {
		$f = self::pick( self::FREQUENCY, isset( $in['frequency'] ) ? $in['frequency'] : null );
		if ( null === $f ) {
			return 'frequency';
		}
		if ( 0 == $f ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 0 or 0.0
			return 0.0; // never, or less than once a week: the other two answers are not asked
		}
		$i = self::pick( self::INTENSITY, isset( $in['intensity'] ) ? $in['intensity'] : null );
		if ( null === $i ) {
			return 'intensity';
		}
		$d = self::pick( self::DURATION, isset( $in['duration'] ) ? $in['duration'] : null );
		if ( null === $d ) {
			return 'duration';
		}
		return $f * $i * $d;
	}

	/**
	 * The wording keys a result shows, in order.
	 *
	 * @param array $out A compute() result.
	 * @return string[]
	 */
	private static function messages_for( array $out ) {
		$keys = array( 'result.vo2' );
		if ( 'low' === $out['clamped'] ) {
			$keys[] = 'result.clampedLow';
		} elseif ( 'high' === $out['clamped'] ) {
			$keys[] = 'result.clampedHigh';
		} else {
			$keys[] = 'result.fitnessAge';
		}
		foreach ( $out['notes'] as $note ) {
			if ( 0 === strpos( $note, 'safety.' ) ) {
				$keys[] = $note;
			} elseif ( 'age18to19' === $note ) {
				$keys[] = 'note.age18to19'; // CW-16
			}
		}
		if ( null !== $out['change'] ) {
			$keys[] = 'premium.change';
		}
		$keys[] = 'quoted.workoutSafety'; // before any exercise suggestion (sheet 1.5)
		if ( null !== $out['improve']['activityDelta'] ) {
			$keys[] = 'improve.activity';
		}
		$keys[] = 'improve.rhr';
		if ( ! $out['waistLeverHidden'] ) {
			$keys[] = 'improve.waist';
		}
		$keys[] = 'always.estimate';
		return $keys;
	}

	/**
	 * The member's current activity in words, for {current}.
	 *
	 * @param array $in Answers.
	 */
	private static function current_label( array $in ) {
		$parts = array();
		foreach ( array( 'frequency', 'intensity', 'duration' ) as $k ) {
			if ( isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) && '' !== trim( (string) $in[ $k ] ) ) {
				$parts[] = trim( (string) $in[ $k ] );
			}
			if ( 'frequency' === $k && 0 == self::pick( self::FREQUENCY, isset( $in['frequency'] ) ? $in['frequency'] : null ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
				break;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * @param array  $out      Result so far.
	 * @param string $what     under18, pregnancy, heartCondition, medicine or sexNotSaid.
	 * @param string $key      Signed wording key for the stop (the sheet's, or CW-13 to CW-15).
	 */
	private static function stop( array $out, $what, $key = '' ) {
		$out['stop']     = $what;
		$out['notes']    = '' === $key ? array() : array( $key );
		$out['messages'] = '' === $key ? array() : array( $key );
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
	 * @param array $map   Normalised answer => score.
	 * @param mixed $value Answer.
	 * @return float|int|null
	 */
	private static function pick( array $map, $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$v = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value ) );
		return isset( $map[ $v ] ) ? $map[ $v ] : null;
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
	 * A yes-or-no answer, the same in all three P3-5A engines: "yes" / "no", true / false or "true" / "false" (any
	 * case), plus the extra answers a question allows ("notsure", "pnts"); anything else, including a missing
	 * answer, is null (refused), so "no" never counts as yes.
	 *
	 * @param mixed    $value Answer.
	 * @param string[] $extra Accepted answers besides yes and no.
	 * @return string|null yes, no, one of $extra, or null.
	 */
	private static function answer( $value, array $extra = array() ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$v   = strtolower( str_replace( array( ' ', '-', '_' ), '', trim( $value ) ) );
		$map = array( 'yes' => 'yes', 'true' => 'yes', 'no' => 'no', 'false' => 'no', 'prefernottosay' => 'pnts', 'prefernot' => 'pnts' );
		$v   = isset( $map[ $v ] ) ? $map[ $v ] : $v;
		return in_array( $v, array_merge( array( 'yes', 'no' ), $extra ), true ) ? $v : null;
	}

	/**
	 * Waist in cm from cm or inches (1 in = 2.54 cm), unrounded.
	 *
	 * @param array $in Answers.
	 */
	private static function waist_cm( array $in ) {
		if ( isset( $in['waistCm'] ) && is_numeric( $in['waistCm'] ) ) {
			return (float) $in['waistCm'];
		}
		if ( isset( $in['waistIn'] ) && is_numeric( $in['waistIn'] ) ) {
			return (float) $in['waistIn'] * 2.54;
		}
		return null;
	}

	/** Half-up to $places, after removing binary noise. */
	private static function rnd( $x, $places ) {
		return round( round( $x, 9 ), $places, PHP_ROUND_HALF_UP );
	}
}
