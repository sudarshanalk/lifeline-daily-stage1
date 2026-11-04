<?php
/**
 * Heart-Rate Training Zones engine (P3-5A part 2, FR3-53), written from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-5A-Fitness-Age-HR-Zones-Sleep-Stress-Method-Sheet.md` (approval record
 * `approvals/stage3/hr-zones.json`, fixtures `fixtures/hr-zones/`).
 *
 * Pure arithmetic and rules: no WordPress, no database, no member lookup.
 *
 * Maximum heart rate by Tanaka 2001 (208 - 0.7 x age, whole bpm half-up) or the member's measured maximum (100 to
 * 220, at least 30 above resting). With a resting heart rate, zones by heart-rate reserve (Karvonen): resting +
 * fraction x (max - resting); without one, by % of max. ACSM 11th ed. table 5.2 fractions (D3-31): Easy 30-39 % HRR /
 * 57-63 % HRmax, Moderate 40-59 % / 64-76 %, Hard 60-89 % / 77-95 %; every bound whole bpm half-up. No zones with a
 * heart-rate-lowering medicine (FR3-53 AS-02), a heart condition or pregnancy; under 18 stops. Every stop wins over
 * every note. Frozen: any change needs a new decision and a new version (NFR3-14).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screens, the workout plans and the tests read.
 */
class LLD_Members_HR_Zones {

	const VERSION = '1.0';

	/** Zone bounds in hundredths: [% HRR low, high], [% HRmax low, high] (sheet 2.1, D3-31). */
	const ZONES = array(
		'easy'     => array( 'hrr' => array( 30, 39 ), 'max' => array( 57, 63 ) ),
		'moderate' => array( 'hrr' => array( 40, 59 ), 'max' => array( 64, 76 ) ),
		'hard'     => array( 'hrr' => array( 60, 89 ), 'max' => array( 77, 95 ) ),
	);

	/** Measured maximum and resting limits (sheet 2.2). */
	const MEASURED_MIN = 100;
	const MEASURED_MAX = 220;
	const RESERVE_MIN  = 30;

	/**
	 * The signed wording (sheet section 2.1 and 2.3). Keys and text are hashed in the approval record's copy_hashes;
	 * the test checks them. {HRmax} is filled by the screen from the result's `fill`.
	 */
	const MESSAGES = array(
		'talk.easy'             => 'You can sing',
		'talk.moderate'         => 'You can talk but not sing',
		'talk.hard'             => 'Only a few words at a time',
		'result.weekly'         => "Most of your weekly 150 minutes should be in Moderate; Hard sessions are for when you're used to exercise.",
		'result.hrmax'          => 'Your estimated maximum is about {HRmax}. Formulas like this can be 10 to 12 beats out for an individual, so use how you feel (the talk test) as the final guide.',
		'safety.medicine'       => "Beta-blockers and some other medicines lower your heart rate, so heart-rate zones don't apply. Talk to your doctor about exercise, and use the talk test instead: moderate means you can talk but not sing.",
		'safety.heartCondition' => 'Ask your doctor or cardiac rehab team for your safe heart-rate range.',
		'safety.pregnancy'      => 'In pregnancy, use the talk test rather than heart-rate targets, and check with your midwife.',
		'safety.under18'        => "These heart-rate zones are for adults. The formula we use to estimate maximum heart rate was made for adults and doesn't fit children and teenagers. To judge effort, use the talk test: moderate means you can talk but not sing. Your doctor or school nurse can advise on exercise.", // CW-17
		'refused.reserve'       => "Please check the numbers: your maximum heart rate should be at least 30 beats a minute higher than your resting heart rate, so we can't work out zones from these. If you're not sure of your maximum, leave it blank and we'll estimate it from your age. If you measured both and they're right, talk to your doctor before doing hard exercise.", // CW-18
	);

	/**
	 * Wording quoted unchanged from the built Stage 2 plugin (sheet 2.3): the workout safety line
	 * (LLD_Members_Premium::WORKOUT_ADVICE), and the Stage 2 safety matrix's "before starting intense exercise" note
	 * (LLD_Members_Safety, blood-pressure row), shown over 75 with no exercise history and with high blood pressure
	 * (Stage 3 matrix, blood-pressure row: "hr-zones:note"). The test reads both from those files.
	 */
	const QUOTED = array(
		'workoutSafety'   => 'Warm up before you start. Stop if you feel pain. Check with your doctor if you need to.',
		'intenseExercise' => 'Talk to your doctor before starting intense exercise',
	);

	/**
	 * Apply the member's health profile (Stage 3 safety matrix, FR3-69): heart-rate-lowering medicine, known heart
	 * disease and pregnancy stop the zones; high blood pressure adds the intense-exercise note. A profile answer only
	 * ever makes the result safer.
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
		if ( in_array( 'blood_pressure', $situations, true ) ) {
			$in['bloodPressure'] = true;
		}
		return $in;
	}

	/**
	 * Tanaka maximum heart rate, whole bpm half-up, in exact integer arithmetic: (2080 - 7 x age) / 10.
	 *
	 * @param int|float $age Age in years.
	 */
	public static function tanaka( $age ) {
		return (int) floor( ( 2080 - 7 * $age ) / 10 + 0.5 + 1e-9 );
	}

	/**
	 * Work out the result.
	 *
	 * @param array $in age, rhr (optional), measuredHrMax (optional), heartMedicine (yes|no|notsure), heartCondition
	 *                  (yes|no|notsure), pregnant (yes|no|notsure), exerciseHistory (yes|no, optional), bloodPressure (yes|no, optional).
	 * @return array stop, hrMax, hrMaxEstimated, method (hrr|pctHrMax), easy, moderate, hard ([low, high] bpm),
	 *               notes[], fill, messages (the wording keys to show, in order), version; or error.
	 */
	public static function compute( array $in ) {
		$out = array(
			'stop'           => null,
			'hrMax'          => null,
			'hrMaxEstimated' => null,
			'method'         => null,
			'easy'           => null,
			'moderate'       => null,
			'hard'           => null,
			'notes'          => array(),
			'fill'           => array(),
			'messages'       => array(),
			'version'        => self::VERSION,
		);
		$age = isset( $in['age'] ) && is_numeric( $in['age'] ) ? (float) $in['age'] : null;
		if ( null === $age || $age < 0 || $age > 99 ) {
			return self::invalid( 'age' );
		}
		// The three safety answers are required: a missing answer is refused, never read as "no" (sheet 2.2, FR3-53
		// AS-02). Exercise history and the profile's blood-pressure flag are optional (missing = no), but an answer
		// that is not yes or no is refused.
		$medicine  = self::answer( isset( $in['heartMedicine'] ) ? $in['heartMedicine'] : null, array( 'notsure' ) );
		$condition = self::answer( isset( $in['heartCondition'] ) ? $in['heartCondition'] : null, array( 'notsure' ) );
		$pregnant  = self::answer( isset( $in['pregnant'] ) ? $in['pregnant'] : null, array( 'notsure' ) );
		$history   = self::answer( isset( $in['exerciseHistory'] ) ? $in['exerciseHistory'] : 'yes' );
		$bp        = self::answer( isset( $in['bloodPressure'] ) ? $in['bloodPressure'] : 'no' );
		foreach ( array( 'heartMedicine' => $medicine, 'heartCondition' => $condition, 'pregnant' => $pregnant, 'exerciseHistory' => $history, 'bloodPressure' => $bp ) as $field => $value ) {
			if ( null === $value ) {
				return self::invalid( $field );
			}
		}
		$rhr      = self::optional( $in, 'rhr' );
		$measured = self::optional( $in, 'measuredHrMax' );
		if ( false === $rhr ) {
			return self::invalid( 'rhr' );
		}
		if ( false === $measured ) {
			return self::invalid( 'measuredHrMax' );
		}
		if ( null !== $rhr && ( $rhr < 30 || $rhr > 120 ) ) {
			return self::invalid( 'rhr' );
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
		if ( null !== $measured ) {
			if ( null !== $rhr && $measured - $rhr < self::RESERVE_MIN ) {
				$out['stop']     = 'refused'; // the measured maximum must be at least 30 above resting (sheet 2.2)
				$out['messages'] = array( 'refused.reserve' ); // CW-18
				return $out;
			}
			if ( $measured < self::MEASURED_MIN || $measured > self::MEASURED_MAX ) {
				return self::invalid( 'measuredHrMax' );
			}
		}

		$max                   = null === $measured ? self::tanaka( $age ) : (int) self::half_up( $measured );
		$out['hrMax']          = $max;
		$out['hrMaxEstimated'] = null === $measured;
		$out['method']         = null === $rhr ? 'pctHrMax' : 'hrr';
		foreach ( self::ZONES as $zone => $f ) {
			$bounds = array();
			foreach ( array( 0, 1 ) as $j ) {
				$bounds[] = null === $rhr
					? (int) self::half_up( $f['max'][ $j ] * $max / 100 )
					: (int) self::half_up( $rhr + $f['hrr'][ $j ] * ( $max - $rhr ) / 100 );
			}
			$out[ $zone ] = $bounds;
		}
		if ( $age > 75 && 'no' === $history ) {
			$out['notes'][] = 'over75NoHistory';
		}
		if ( 'yes' === $bp ) {
			$out['notes'][] = 'bloodPressure';
		}
		$out['fill']     = array( 'HRmax' => (string) $max );
		$out['messages'] = array( 'talk.easy', 'talk.moderate', 'talk.hard', 'result.weekly', 'result.hrmax' );
		if ( $out['notes'] ) {
			$out['messages'][] = 'quoted.intenseExercise';
		}
		$out['messages'][] = 'quoted.workoutSafety';
		return $out;
	}

	/**
	 * The signed text for wording keys (`quoted.*` from QUOTED). Unknown keys are left out.
	 *
	 * @param string[] $keys   Wording keys, as compute() returns them.
	 * @param array    $result The compute() result (unused: the screen fills {HRmax} from `fill`).
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
	 * An optional number: null when absent or empty, false when not a number.
	 *
	 * @param array  $in  Answers.
	 * @param string $key Field.
	 * @return float|null|false
	 */
	private static function optional( array $in, $key ) {
		if ( ! isset( $in[ $key ] ) || '' === $in[ $key ] ) {
			return null;
		}
		return is_numeric( $in[ $key ] ) ? (float) $in[ $key ] : false;
	}

	/**
	 * @param array  $out  Result so far.
	 * @param string $what under18, pregnancy, heartCondition or medicine.
	 * @param string $key  Signed wording key for the stop (the sheet's, or CW-17).
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
	 * A yes-or-no answer, the same in all three P3-5A engines: "yes" / "no", true / false or "true" / "false" (any
	 * case), plus the extra answers a question allows ("notsure"); anything else, including a missing answer, is
	 * null (refused), so "no" never counts as yes.
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
		$map = array( 'yes' => 'yes', 'true' => 'yes', 'no' => 'no', 'false' => 'no' );
		$v   = isset( $map[ $v ] ) ? $map[ $v ] : $v;
		return in_array( $v, array_merge( array( 'yes', 'no' ), $extra ), true ) ? $v : null;
	}

	/** Whole number half-up, after removing binary noise. */
	private static function half_up( $x ) {
		return floor( round( $x, 9 ) + 0.5 );
	}
}
