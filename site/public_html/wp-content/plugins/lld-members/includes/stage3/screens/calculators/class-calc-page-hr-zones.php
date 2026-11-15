<?php
/**
 * Heart-rate training zones page (P3-5A part 2, FR3-53): /health-tools/heart-rate-zones/.
 *
 * Inputs follow the signed sheet (P3-5A section 2.2) plus the two optional answers the engine reads for its notes
 * (exercise history over 75, high blood pressure; sheet 2.3 and the Stage 3 safety matrix). The result follows
 * section 2.3: any safety note first, the three zones with their talk-test lines, the weekly line, the maximum used,
 * then the Stage 2 workout safety line. A stop (medicine, heart condition, pregnancy, under 18) or a refused reserve
 * shows its signed wording and no zones. Every sentence comes from LLD_Members_HR_Zones::wording().
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The heart-rate zones page.
 */
class LLD_Members_Calc_Page_HR_Zones {

	const ENGINE = 'hr-zones';
	const SAVED  = 'hr_zones';
	const TITLE  = 'Heart-rate training zones';
	const INTRO  = 'Works out your easy, moderate and hard heart-rate zones for exercise, with a simple talk test for each.';

	/** Zone => the name shown (the sheet's "Zone (shown as)" column). */
	const ZONE_NAMES = array( 'easy' => 'Easy', 'moderate' => 'Moderate', 'hard' => 'Hard' );

	/** Engine field => how the error message names it. */
	const FIELDS = array(
		'age'             => 'your age',
		'rhr'             => 'your resting heart rate',
		'measuredHrMax'   => 'your measured maximum heart rate',
		'heartMedicine'   => 'the heart medicine answer',
		'heartCondition'  => 'the heart condition answer',
		'pregnant'        => 'the pregnancy answer',
		'exerciseHistory' => 'the exercise answer',
		'bloodPressure'   => 'the blood pressure answer',
	);

	/** Profile situation => the answer it gives "yes" to (as the engine's apply_profile does on the server). */
	const SITUATION_ANSWERS = array(
		'heart_rate_medicine' => 'heartMedicine',
		'heart_disease'       => 'heartCondition',
		'pregnancy'           => 'pregnant',
		'blood_pressure'      => 'bloodPressure',
	);

	/**
	 * Pre-fills for a signed-in member: age, and "yes" to any safety question the member's own health profile
	 * already answers. Nothing else is assumed.
	 *
	 * @param array $ctx Context.
	 */
	public static function defaults( array $ctx ) {
		$v = array( 'heartMedicine' => '', 'heartCondition' => '', 'pregnant' => '', 'exerciseHistory' => '', 'bloodPressure' => '' );
		$f = $ctx['facts'];
		if ( ! is_array( $f ) ) {
			return $v;
		}
		if ( ! empty( $f['age'] ) ) {
			$v['age'] = (string) (int) $f['age'];
		}
		$situations = isset( $f['situations'] ) && is_array( $f['situations'] ) ? $f['situations'] : array();
		foreach ( self::SITUATION_ANSWERS as $situation => $answer ) {
			if ( in_array( $situation, $situations, true ) ) {
				$v[ $answer ] = 'yes';
			}
		}
		return $v;
	}

	/**
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function form( array $values, array $ctx ) {
		unset( $ctx );
		$p      = 'LLD_Members_Calculator_Pages';
		$v      = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) ? (string) $values[ $k ] : '';
		};
		$yes_no = array( 'yes' => 'Yes', 'no' => 'No' );
		$unsure = array( 'yes' => 'Yes', 'no' => 'No', 'notsure' => 'Not sure' );
		$html   = $p::number( 'age', 'Age', $v( 'age' ), array( 'min' => 18, 'max' => 99, 'step' => 1, 'unit' => 'years', 'hint' => 'This check is for adults, 18 and over.' ) );
		$html  .= $p::number( 'rhr', 'Resting heart rate', $v( 'rhr' ), array( 'min' => 30, 'max' => 120, 'step' => 1, 'unit' => 'beats a minute', 'hint' => 'Optional. Taken sitting quietly for 5 minutes, before caffeine.' ) );
		$html  .= $p::number( 'measuredHrMax', 'Measured maximum heart rate', $v( 'measuredHrMax' ), array( 'min' => 100, 'max' => 220, 'step' => 1, 'unit' => 'beats a minute', 'hint' => 'Optional. Only if you know it from a fitness test. Leave blank and we will estimate it from your age.' ) );
		$html  .= $p::choice( 'heartMedicine', 'Do you take medicine that slows your heart rate (for example a beta-blocker)?', $unsure, $v( 'heartMedicine' ) );
		$html  .= $p::choice( 'heartCondition', 'Have you ever had a heart attack, stroke, mini-stroke (TIA), angina, or heart or artery surgery (stent, bypass)?', $unsure, $v( 'heartCondition' ) );
		$html  .= $p::choice( 'pregnant', 'Are you pregnant?', $unsure, $v( 'pregnant' ) );
		$html  .= $p::choice( 'exerciseHistory', 'Do you exercise regularly now, or have you in the past?', $yes_no, $v( 'exerciseHistory' ), 'Optional.' );
		$html  .= $p::choice( 'bloodPressure', 'Do you have high blood pressure?', $yes_no, $v( 'bloodPressure' ), 'Optional.' );
		return $html;
	}

	/**
	 * The engine's inputs from the answers. Blank optional numbers stay blank (the engine estimates the maximum and
	 * uses % of maximum); a blank safety answer is sent as missing, so the engine refuses it (never read as "no");
	 * blank optional answers are left out (the engine reads them as no history note and no blood-pressure note).
	 *
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function inputs( array $values, array $ctx ) {
		unset( $ctx );
		$p   = 'LLD_Members_Calculator_Pages';
		$ans = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) && '' !== trim( (string) $values[ $k ] ) ? (string) $values[ $k ] : null;
		};
		$in  = array(
			'age'            => $p::num( $values, 'age' ),
			'heartMedicine'  => $ans( 'heartMedicine' ),
			'heartCondition' => $ans( 'heartCondition' ),
			'pregnant'       => $ans( 'pregnant' ),
		);
		$rhr = isset( $values['rhr'] ) ? trim( (string) $values['rhr'] ) : '';
		if ( '' !== $rhr ) {
			$in['rhr'] = null === $p::num( $values, 'rhr' ) ? $rhr : $p::num( $values, 'rhr' ); // not a number: the engine refuses it
		}
		$max = isset( $values['measuredHrMax'] ) ? trim( (string) $values['measuredHrMax'] ) : '';
		if ( '' !== $max ) {
			$in['measuredHrMax'] = null === $p::num( $values, 'measuredHrMax' ) ? $max : $p::num( $values, 'measuredHrMax' );
		}
		foreach ( array( 'exerciseHistory', 'bloodPressure' ) as $k ) {
			if ( null !== $ans( $k ) ) {
				$in[ $k ] = $ans( $k );
			}
		}
		return $in;
	}

	/**
	 * @param string $field Engine field.
	 */
	public static function error_text( $field ) {
		return LLD_Members_Calculator_Pages::field_error( $field, self::FIELDS );
	}

	/**
	 * The result in the sheet's order. A stop or a refused reserve shows its signed wording and no zones.
	 *
	 * @param array $r   Engine result with `wording`.
	 * @param array $ctx Context.
	 */
	public static function result( array $r, array $ctx ) {
		unset( $ctx );
		$p    = 'LLD_Members_Calculator_Pages';
		$w    = isset( $r['wording'] ) && is_array( $r['wording'] ) ? $r['wording'] : array();
		$fill = isset( $r['fill'] ) && is_array( $r['fill'] ) ? $r['fill'] : array();
		$msgs = isset( $r['messages'] ) && is_array( $r['messages'] ) ? $r['messages'] : array();
		if ( ! empty( $r['stop'] ) ) {
			$html = '<div class="lld-calc-stop" data-lld-stop="' . esc_attr( (string) $r['stop'] ) . '">';
			foreach ( $msgs as $key ) {
				$html .= 0 === strpos( $key, 'safety.' )
					? $p::safety_key( $key, isset( $w[ $key ] ) ? $w[ $key ] : '' )
					: $p::para( $w, $key );
			}
			return $html . '</div>';
		}
		$html = '';
		if ( in_array( 'quoted.intenseExercise', $msgs, true ) ) {
			$notes = isset( $r['notes'] ) && is_array( $r['notes'] ) ? $r['notes'] : array();
			$html .= '<div data-lld-notes="' . esc_attr( implode( ' ', $notes ) ) . '">' . $p::safety_key( 'quoted.intenseExercise', isset( $w['quoted.intenseExercise'] ) ? $w['quoted.intenseExercise'] : '' ) . '</div>';
		}
		$html .= '<table class="lld-calc-zones" data-lld-method="' . esc_attr( (string) $r['method'] ) . '" data-lld-hrmax="' . esc_attr( (string) $r['hrMax'] ) . '">';
		$html .= '<thead><tr><th scope="col">Zone</th><th scope="col">Heart rate (beats a minute)</th><th scope="col">Talk test</th></tr></thead><tbody>';
		foreach ( self::ZONE_NAMES as $zone => $name ) {
			if ( empty( $r[ $zone ] ) || ! is_array( $r[ $zone ] ) ) {
				continue;
			}
			$low   = (int) $r[ $zone ][0];
			$high  = (int) $r[ $zone ][1];
			$html .= '<tr data-lld-zone="' . esc_attr( $zone ) . '" data-lld-low="' . esc_attr( (string) $low ) . '" data-lld-high="' . esc_attr( (string) $high ) . '">';
			$html .= '<th scope="row">' . esc_html( $name ) . '</th>';
			$html .= '<td class="lld-calc-figure">' . esc_html( $low . "\u{2013}" . $high ) . '</td>';
			$html .= '<td data-lld-key="' . esc_attr( 'talk.' . $zone ) . '">' . $p::text( $w, 'talk.' . $zone ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		$html .= $p::para( $w, 'result.weekly' );
		$html .= $p::para( $w, empty( $r['hrMaxEstimated'] ) ? 'result.hrmax-measured' : 'result.hrmax', $fill, 'lld-hint' ); // D3-51
		$html .= $p::para( $w, 'quoted.workoutSafety', array(), 'lld-calc-note' );
		return $html;
	}

	/**
	 * The saved row (P3-5B section 1: "HRmax used and the three ranges", bpm, no band and no comparison). The row
	 * needs a band, so it holds the three ranges.
	 *
	 * @param array $r      Engine result.
	 * @param array $inputs Engine inputs.
	 * @return array|null
	 */
	public static function saved( array $r, array $inputs ) {
		if ( ! empty( $r['stop'] ) || empty( $r['hrMax'] ) ) {
			return null;
		}
		$ranges = array();
		foreach ( self::ZONE_NAMES as $zone => $name ) {
			$ranges[] = $name . ' ' . (int) $r[ $zone ][0] . "\u{2013}" . (int) $r[ $zone ][1];
		}
		return array(
			'calculator'         => self::SAVED,
			'headline'           => (string) $r['hrMax'],
			'unit'               => 'bpm',
			'band'               => implode( ', ', $ranges ),
			'calculator_version' => (string) $r['version'],
			'result'             => array(
				'hrMax'          => $r['hrMax'],
				'hrMaxEstimated' => $r['hrMaxEstimated'],
				'method'         => $r['method'],
				'easy'           => $r['easy'],
				'moderate'       => $r['moderate'],
				'hard'           => $r['hard'],
			),
			'inputs'             => $inputs,
		);
	}

	/**
	 * Method and sources (sheet section 2.1).
	 */
	public static function about() {
		return '<p>Maximum heart rate by Tanaka 2001 (208 minus 0.7 times your age), or your measured maximum. With a resting heart rate, zones use your heart-rate reserve (Karvonen); without one, a percentage of your maximum. Zone ranges from the American College of Sports Medicine guidelines, 11th edition (2021). Version ' . esc_html( LLD_Members_HR_Zones::VERSION ) . '.</p>';
	}
}
