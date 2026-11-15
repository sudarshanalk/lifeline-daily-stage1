<?php
/**
 * Fitness Age page (P3-5A part 1, FR3-52): /health-tools/fitness-age/.
 *
 * Inputs and their order follow the signed sheet (P3-5A section 1.3, the HUNT activity questions of 1.1); the result
 * follows sections 1.4 and 1.5: any safety line first, the VO2max estimate and fitness age, the Premium change since
 * the last saved check, the Stage 2 workout safety line before "How it can improve", then the always-shown estimate
 * line. Every sentence comes from LLD_Members_Fitness_Age::wording().
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The fitness age page.
 */
class LLD_Members_Calc_Page_Fitness_Age {

	const ENGINE = 'fitness-age';
	const SAVED  = 'fitness_age';
	const TITLE  = 'Fitness age';
	const INTRO  = 'Estimates your fitness level (VO₂max) from a few questions, the age it is typical of, and what would raise it.';

	/**
	 * The HUNT answers as the sheet words them (section 1.1). The value is what the engine reads and also what fills
	 * {current} in the activity lever, so it is the sheet's wording in lower case; the label is the sheet's wording.
	 */
	const FREQUENCY = array(
		'never'                 => 'Never',
		'less than once a week' => 'Less than once a week',
		'once a week'           => 'Once a week',
		'2–3 times a week'      => '2–3 times a week',
		'almost every day'      => 'Almost every day',
	);
	const INTENSITY = array(
		'easy, no sweating'           => 'Easy, no sweating',
		'sweating, heavier breathing' => 'Sweating, heavier breathing',
		'near exhaustion'             => 'Near exhaustion',
	);
	const DURATION = array(
		'under 15 min' => 'Under 15 min',
		'15–29 min'    => '15–29 min',
		'30–60 min'    => '30–60 min',
		'over 1 hour'  => 'Over 1 hour',
	);

	/** Engine field => how the error message names it. */
	const FIELDS = array(
		'age' => 'your age', 'sex' => 'your sex', 'waist' => 'your waist', 'rhr' => 'your resting heart rate',
		'frequency' => 'how often you exercise', 'intensity' => 'how hard you exercise', 'duration' => 'how long you exercise each time',
		'heartMedicine' => 'the heart medicine answer', 'heartCondition' => 'the heart condition answer', 'pregnant' => 'the pregnancy answer',
		'eatingDisorder' => 'the eating question',
	);

	/** Page labels that are not clinical wording (listed in the pull request for the owner). */
	const UI = array(
		'improve' => 'How it can improve',
	);

	/**
	 * Pre-fills for a signed-in member (sheet section 1.3): age, sex and waist from the profile. The safety answers
	 * are never pre-filled; the engine's apply_profile adds the profile's pregnancy, medicine and heart flags on the
	 * server.
	 *
	 * @param array $ctx Context.
	 */
	public static function defaults( array $ctx ) {
		$v = array( 'heartMedicine' => '', 'heartCondition' => '', 'pregnant' => '' );
		$f = $ctx['facts'];
		if ( ! is_array( $f ) ) {
			return $v;
		}
		if ( ! empty( $f['age'] ) ) {
			$v['age'] = (string) (int) $f['age'];
		}
		if ( ! empty( $f['sex'] ) && in_array( $f['sex'], array( 'female', 'male' ), true ) ) {
			$v['sex'] = (string) $f['sex'];
		}
		if ( ! empty( $f['waist_cm'] ) ) {
			$v['waistCm'] = (string) round( (float) $f['waist_cm'] );
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
		$hint   = LLD_Members_Fitness_Age::wording( array( 'input.rhrHint' ) );
		$hint   = isset( $hint['input.rhrHint'] ) ? ucfirst( $hint['input.rhrHint'] ) . '.' : '';
		$yes_no = array( 'yes' => 'Yes', 'no' => 'No' );
		$unsure = array( 'yes' => 'Yes', 'no' => 'No', 'notsure' => 'Not sure' );
		$html   = $p::number( 'age', 'Age', $v( 'age' ), array( 'min' => 18, 'max' => 99, 'step' => 1, 'unit' => 'years' ) );
		$html  .= $p::choice( 'sex', 'Sex', array( 'female' => 'Female', 'male' => 'Male', 'pnts' => 'Prefer not to say' ), $v( 'sex' ) );
		$html  .= '<div class="lld-field-row">';
		$html  .= $p::number( 'waistCm', 'Waist', $v( 'waistCm' ), array( 'min' => 40, 'max' => 250, 'step' => '0.5', 'unit' => 'cm' ) );
		$html  .= $p::number( 'rhr', 'Resting heart rate', $v( 'rhr' ), array( 'min' => 30, 'max' => 120, 'step' => 1, 'unit' => 'beats per minute', 'hint' => $hint ) );
		$html  .= '</div>';
		$html  .= $p::choice( 'frequency', 'How often do you exercise?', self::FREQUENCY, $v( 'frequency' ) );
		$html  .= $p::choice( 'intensity', 'How hard?', self::INTENSITY, $v( 'intensity' ), 'Skip this if you never exercise or exercise less than once a week.' );
		$html  .= $p::choice( 'duration', 'How long each time?', self::DURATION, $v( 'duration' ), 'Skip this if you never exercise or exercise less than once a week.' );
		$html  .= $p::choice( 'heartMedicine', 'Do you take medicine that slows your heart (for example a beta-blocker)?', $unsure, $v( 'heartMedicine' ) );
		$html  .= $p::choice( 'heartCondition', 'Have you ever had a heart attack, stroke, mini-stroke (TIA), angina, or heart or artery surgery (stent, bypass)?', $yes_no, $v( 'heartCondition' ) );
		$html  .= $p::choice( 'pregnant', 'Are you pregnant or breastfeeding?', $unsure, $v( 'pregnant' ) );
		$html  .= $p::choice( 'eatingDisorder', 'Do you have, or have you had, an eating disorder?', array( 'no' => 'No', 'yes' => 'Yes', 'pnts' => 'Prefer not to say' ), '' !== $v( 'eatingDisorder' ) ? $v( 'eatingDisorder' ) : 'no' );
		return $html;
	}

	/**
	 * The engine's inputs from the answers. For a signed-in Premium member, the latest saved result is passed as
	 * previousVo2 and previousDate so the engine works out the change since the last check (FR3-52 AS-02).
	 *
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function inputs( array $values, array $ctx ) {
		$p   = 'LLD_Members_Calculator_Pages';
		$ans = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) && '' !== $values[ $k ] ? (string) $values[ $k ] : null;
		};
		$in  = array(
			'age'            => $p::num( $values, 'age' ),
			'sex'            => isset( $values['sex'] ) ? $values['sex'] : '',
			'waistCm'        => $p::num( $values, 'waistCm' ),
			'rhr'            => $p::num( $values, 'rhr' ),
			'frequency'      => $ans( 'frequency' ),
			'intensity'      => $ans( 'intensity' ),
			'duration'       => $ans( 'duration' ),
			'heartMedicine'  => $ans( 'heartMedicine' ),
			'heartCondition' => $ans( 'heartCondition' ),
			'pregnant'       => $ans( 'pregnant' ),
			'eatingDisorder' => isset( $values['eatingDisorder'] ) && '' !== (string) $values['eatingDisorder'] ? (string) $values['eatingDisorder'] : 'no',
		);
		if ( ! empty( $ctx['premium'] ) && isset( $ctx['member'] ) && (int) $ctx['member'] > 0 ) {
			$last = self::previous( (int) $ctx['member'] );
			if ( null !== $last ) {
				$in['previousVo2']  = $last['vo2'];
				$in['previousDate'] = $last['date'];
			}
		}
		return $in;
	}

	/**
	 * The member's latest saved fitness_age result: its VO2 (from the sealed result, or the headline) and its date.
	 * Looked up once per request; null when there is none or it cannot be read.
	 *
	 * @param int $member_id Member number.
	 * @return array{vo2:float,date:string}|null
	 */
	private static function previous( $member_id ) {
		static $cache = array();
		if ( array_key_exists( $member_id, $cache ) ) {
			return $cache[ $member_id ];
		}
		$cache[ $member_id ] = null;
		if ( ! class_exists( 'LLD_Members_Stage3_API' ) || ! method_exists( 'LLD_Members_Stage3_API', 'page_saved_results' ) ) {
			return null;
		}
		$view = LLD_Members_Stage3_API::page_saved_results( self::SAVED );
		if ( ! is_array( $view ) || empty( $view['rows'] ) || ! is_array( $view['rows'] ) ) {
			return null;
		}
		$row    = (array) reset( $view['rows'] );
		$result = isset( $row['result'] ) ? $row['result'] : null;
		if ( is_string( $result ) ) {
			$result = json_decode( $result, true );
		}
		$vo2 = null;
		if ( is_array( $result ) && isset( $result['vo2'] ) && is_numeric( $result['vo2'] ) ) {
			$vo2 = (float) $result['vo2'];
		} elseif ( isset( $row['headline'] ) && is_numeric( $row['headline'] ) ) {
			$vo2 = (float) $row['headline'];
		}
		if ( null === $vo2 ) {
			return null;
		}
		$date = '';
		if ( ! empty( $row['saved_at'] ) ) {
			$ts   = strtotime( (string) $row['saved_at'] . ' UTC' );
			$date = false === $ts ? '' : gmdate( 'j M Y', $ts );
		}
		if ( '' === $date ) {
			return null; // the signed line needs its date
		}
		$cache[ $member_id ] = array( 'vo2' => $vo2, 'date' => $date );
		return $cache[ $member_id ];
	}

	/**
	 * @param string $field Engine field.
	 */
	public static function error_text( $field ) {
		return LLD_Members_Calculator_Pages::field_error( $field, self::FIELDS );
	}

	/**
	 * The result in the sheet's order. A stop shows its signed wording and no number.
	 *
	 * @param array $r   Engine result with `wording`.
	 * @param array $ctx Context.
	 */
	public static function result( array $r, array $ctx ) {
		unset( $ctx );
		$p        = 'LLD_Members_Calculator_Pages';
		$w        = isset( $r['wording'] ) && is_array( $r['wording'] ) ? $r['wording'] : array();
		$fill     = isset( $r['fill'] ) && is_array( $r['fill'] ) ? $r['fill'] : array();
		$messages = isset( $r['messages'] ) && is_array( $r['messages'] ) ? $r['messages'] : array();
		if ( ! empty( $r['stop'] ) ) {
			$key  = isset( $messages[0] ) ? (string) $messages[0] : '';
			$body = 0 === strpos( $key, 'safety.' ) ? $p::safety_key( $key, isset( $w[ $key ] ) ? $w[ $key ] : '' ) : $p::para( $w, $key );
			return '<div class="lld-calc-stop" data-lld-stop="' . esc_attr( (string) $r['stop'] ) . '">' . $body . '</div>';
		}
		$html = '';
		foreach ( $messages as $key ) {
			if ( 0 === strpos( $key, 'safety.' ) ) {
				$html .= $p::safety_key( $key, isset( $w[ $key ] ) ? $w[ $key ] : '' );
			}
		}
		$html .= '<div class="lld-calc-headline" data-lld-vo2="' . esc_attr( (string) $r['vo2'] ) . '" data-lld-fitness-age="' . esc_attr( (string) $r['fitnessAge'] ) . '"' . ( $r['clamped'] ? ' data-lld-clamped="' . esc_attr( (string) $r['clamped'] ) . '"' : '' ) . '>';
		$html .= '<p class="lld-calc-figure" data-lld-key="result.vo2">' . $p::text( $w, 'result.vo2', $fill ) . '</p>';
		foreach ( array( 'result.fitnessAge', 'result.clampedLow', 'result.clampedHigh' ) as $key ) {
			if ( in_array( $key, $messages, true ) ) {
				$html .= '<p class="lld-calc-band" data-lld-key="' . esc_attr( $key ) . '">' . ucfirst( $p::text( $w, $key, $fill ) ) . '</p>';
			}
		}
		$html .= '</div>';
		if ( in_array( 'note.age18to19', $messages, true ) ) {
			$html .= $p::para( $w, 'note.age18to19', array(), 'lld-calc-note' );
		}
		if ( in_array( 'premium.change', $messages, true ) && null !== $fill['+2.1'] && null !== $fill['date'] ) {
			$html .= '<p class="lld-stat" data-lld-key="premium.change" data-lld-change="' . esc_attr( (string) $r['change'] ) . '">' . $p::text( $w, 'premium.change', $fill ) . '</p>';
		}
		$levers = '';
		foreach ( array( 'improve.activity', 'improve.rhr', 'improve.waist' ) as $key ) {
			if ( in_array( $key, $messages, true ) && self::filled( $w, $key, $fill ) ) {
				$levers .= '<li data-lld-key="' . esc_attr( $key ) . '">' . ucfirst( $p::text( $w, $key, $fill ) ) . '</li>';
			}
		}
		if ( '' !== $levers ) {
			$html .= '<h3>' . esc_html( self::UI['improve'] ) . '</h3>';
			$html .= $p::para( $w, 'quoted.workoutSafety', array(), 'lld-caution' ); // before any exercise suggestion (sheet 1.5)
			$html .= '<ul class="lld-calc-levers">' . $levers . '</ul>';
		}
		$html .= $p::para( $w, 'always.estimate', array(), 'lld-calc-note' );
		return $html;
	}

	/**
	 * Whether every {placeholder} in a signed template has a value, so no braces reach the page.
	 *
	 * @param array  $w    Wording.
	 * @param string $key  Key.
	 * @param array  $fill Placeholder values.
	 */
	private static function filled( array $w, $key, array $fill ) {
		if ( ! isset( $w[ $key ] ) || ! is_string( $w[ $key ] ) ) {
			return false;
		}
		preg_match_all( '/\{([^{}]+)\}/u', $w[ $key ], $m );
		foreach ( $m[1] as $name ) {
			if ( ! isset( $fill[ $name ] ) || '' === (string) $fill[ $name ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The saved row (P3-5B): the VO2 estimate (1 dp) and fitness age, which LLD_Members_Saved_Results::compare()
	 * reads, with the inputs (without the previous result).
	 *
	 * @param array $r      Engine result.
	 * @param array $inputs Engine inputs.
	 * @return array|null
	 */
	public static function saved( array $r, array $inputs ) {
		if ( ! empty( $r['stop'] ) || ! isset( $r['vo2'] ) || null === $r['vo2'] ) {
			return null;
		}
		unset( $inputs['previousVo2'], $inputs['previousDate'] );
		return array(
			'calculator'         => self::SAVED,
			'headline'           => number_format( (float) $r['vo2'], 1, '.', '' ),
			'unit'               => 'mL/kg/min',
			'band'               => 'fitness age ' . (int) $r['fitnessAge'],
			'calculator_version' => (string) $r['version'],
			'result'             => array( 'vo2' => $r['vo2'], 'fitness_age' => $r['fitnessAge'], 'clamped' => $r['clamped'], 'paIndex' => $r['paIndex'] ),
			'inputs'             => $inputs,
		);
	}

	/**
	 * Method and sources (sheet sections 1.1 and 1.2).
	 */
	public static function about() {
		return '<p>Based on the HUNT non-exercise fitness model (Nes et al., Medicine and Science in Sports and Exercise 2011), made from 4,631 healthy adults aged 20 to 90. Your fitness age is the age at which a typical person of the same sex has your estimated fitness. Version ' . esc_html( LLD_Members_Fitness_Age::VERSION ) . '.</p>';
	}
}
