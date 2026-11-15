<?php
/**
 * Heart Attack and Stroke Risk Check page (P3-57, FR3-49): /health-tools/heart-risk-check/.
 *
 * Inputs and their order follow the signed sheet (P3-57 section 2); the result follows its section 3 and the
 * Stage 2 calculator screen order (SCR-45): any safety line, the headline and band, the comparison, what would lower
 * it most, the model used, then the notes and limits. Every sentence comes from LLD_Members_Heart_Risk::wording().
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The heart risk page.
 */
class LLD_Members_Calc_Page_Heart_Risk {

	const ENGINE = 'heart-risk';
	const SAVED  = 'heart_risk';
	const TITLE  = 'Heart attack and stroke risk check';
	const INTRO  = 'Estimates your chance of a heart attack or stroke in the next 10 years, and which change would lower it most.';

	/** What-if factor => its place in the signed template's {A | B | C | D} choice, and the name shown. */
	const FACTOR_SLOT = array( 'smoking' => 0, 'blood-pressure' => 1, 'cholesterol' => 2, 'weight' => 3 );
	const FACTOR_NAME = array( 'smoking' => 'smoking', 'blood-pressure' => 'blood pressure', 'cholesterol' => 'cholesterol', 'weight' => 'weight' );

	/** Engine field => how the error message names it. */
	const FIELDS = array(
		'age' => 'your age', 'sex' => 'your sex', 'country' => 'your country', 'region' => 'your country', 'smoke' => 'the smoking answer',
		'sbp' => 'the blood pressure', 'diabetes' => 'the diabetes answer', 'tc' => 'the cholesterol', 'bmi' => 'your height and weight',
		'cvd' => 'the heart disease answer', 'preg' => 'the pregnancy answer', 'eat' => 'the eating question',
	);

	/**
	 * Pre-fills for a signed-in member (sheet section 2): age, sex, height, weight, the time-zone country, and
	 * diabetes from the profile (the engine's apply_profile does that last one again on the server).
	 *
	 * @param array $ctx Context.
	 */
	public static function defaults( array $ctx ) {
		$v = array( 'tcUnit' => 'mmol/L', 'cvd' => '', 'preg' => '', 'eat' => 'no' );
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
		if ( ! empty( $f['height_cm'] ) ) {
			$v['heightCm'] = (string) round( (float) $f['height_cm'] );
		}
		if ( ! empty( $f['weight_kg'] ) ) {
			$v['weightKg'] = (string) round( (float) $f['weight_kg'], 1 );
		}
		$situations = isset( $f['situations'] ) && is_array( $f['situations'] ) ? $f['situations'] : array();
		if ( in_array( 'diabetes', $situations, true ) ) {
			$v['diabetes'] = 'yes';
		}
		$country = self::timezone_country( (int) $ctx['member'] );
		if ( '' !== $country ) {
			$v['country'] = $country;
		}
		return $v;
	}

	/**
	 * The appendix country for the member's time zone, or ''.
	 *
	 * @param int $member_id Member number.
	 */
	private static function timezone_country( $member_id ) {
		$zone = class_exists( 'LLD_Members_Programme' ) ? (string) LLD_Members_Programme::timezone( $member_id ) : '';
		if ( '' === $zone || 'UTC' === $zone ) {
			return '';
		}
		try {
			$where = ( new DateTimeZone( $zone ) )->getLocation();
		} catch ( Exception $e ) {
			return '';
		}
		$code = is_array( $where ) && isset( $where['country_code'] ) ? strtolower( (string) $where['country_code'] ) : '';
		if ( '' === $code || '??' === $code ) {
			return '';
		}
		$name = isset( LLD_Members_Heart_Risk::ALIASES[ $code ] ) ? LLD_Members_Heart_Risk::ALIASES[ $code ] : '';
		return in_array( $name, self::countries(), true ) ? $name : '';
	}

	/**
	 * The appendix's country names, sorted, for the list.
	 *
	 * @return string[]
	 */
	public static function countries() {
		static $names = null;
		if ( null === $names ) {
			$file  = dirname( __DIR__, 2 ) . '/calculators/' . LLD_Members_Heart_Risk::DATA_REGIONS;
			$doc   = json_decode( (string) file_get_contents( $file ), true );
			$names = is_array( $doc ) && isset( $doc['countries'] ) ? array_keys( $doc['countries'] ) : array();
			sort( $names );
		}
		return $names;
	}

	/**
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function form( array $values, array $ctx ) {
		unset( $ctx );
		$p        = 'LLD_Members_Calculator_Pages';
		$v        = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) ? (string) $values[ $k ] : '';
		};
		$yes_no   = array( 'yes' => 'Yes', 'no' => 'No' );
		$countries = array( '' => 'Choose your country' );
		foreach ( self::countries() as $name ) {
			$countries[ $name ] = $name;
		}
		$html  = $p::number( 'age', 'Age', $v( 'age' ), array( 'min' => 18, 'max' => 99, 'step' => 1, 'unit' => 'years', 'hint' => 'This check is for ages 40 to 74.' ) );
		$html .= $p::choice( 'sex', 'Sex', array( 'female' => 'Female', 'male' => 'Male', 'pnts' => 'Prefer not to say' ), $v( 'sex' ) );
		$html .= $p::select( 'country', 'Country', $countries, $v( 'country' ) );
		$html .= $p::choice( 'smoke', 'Do you smoke?', array( 'yes' => 'Yes, I smoke now or stopped less than a year ago', 'no' => 'No, never, or I stopped a year ago or more' ), $v( 'smoke' ) );
		$html .= $p::number( 'sbp', 'Systolic blood pressure (the top number)', $v( 'sbp' ), array( 'min' => 80, 'max' => 250, 'step' => 1, 'unit' => 'mmHg' ) );
		$html .= $p::choice( 'diabetes', 'Have you been diagnosed with diabetes (type 1 or type 2)?', $yes_no, $v( 'diabetes' ) );
		$html .= '<div class="lld-field-row">';
		$html .= $p::number( 'tc', 'Total cholesterol', $v( 'tc' ), array( 'min' => 0, 'step' => '0.1', 'hint' => 'Leave blank if you don\'t know it.' ) );
		$html .= $p::select( 'tcUnit', 'Unit', array( 'mmol/L' => 'mmol/L', 'mg/dL' => 'mg/dL' ), '' !== $v( 'tcUnit' ) ? $v( 'tcUnit' ) : 'mmol/L' );
		$html .= '</div><div class="lld-field-row">';
		$html .= $p::number( 'heightCm', 'Height', $v( 'heightCm' ), array( 'min' => 100, 'max' => 250, 'step' => 1, 'unit' => 'cm' ) );
		$html .= $p::number( 'weightKg', 'Weight', $v( 'weightKg' ), array( 'min' => 25, 'max' => 300, 'step' => '0.1', 'unit' => 'kg' ) );
		$html .= '</div>';
		$html .= $p::choice( 'cvd', 'Have you ever had a heart attack, stroke, mini-stroke (TIA), angina, or heart or artery surgery (stent, bypass)?', $yes_no, $v( 'cvd' ) );
		$html .= $p::choice( 'preg', 'Are you pregnant or breastfeeding?', array( 'yes' => 'Yes', 'no' => 'No', 'notsure' => 'Not sure' ), $v( 'preg' ) );
		$html .= $p::choice( 'eat', 'Do you have, or have you had, an eating disorder?', array( 'no' => 'No', 'yes' => 'Yes', 'pnts' => 'Prefer not to say' ), $v( 'eat' ) );
		return $html;
	}

	/**
	 * The engine's inputs from the answers. A blank cholesterol is "I don't know" (the non-laboratory model).
	 *
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function inputs( array $values, array $ctx ) {
		unset( $ctx );
		$p  = 'LLD_Members_Calculator_Pages';
		$in = array(
			'age'      => $p::num( $values, 'age' ),
			'sex'      => isset( $values['sex'] ) ? $values['sex'] : '',
			'country'  => isset( $values['country'] ) ? $values['country'] : '',
			'smoke'    => isset( $values['smoke'] ) ? $values['smoke'] : null,
			'sbp'      => $p::num( $values, 'sbp' ),
			'diabetes' => isset( $values['diabetes'] ) && '' !== $values['diabetes'] ? $values['diabetes'] : null,
			'tc'       => null === $p::num( $values, 'tc' ) ? 'dontknow' : $p::num( $values, 'tc' ),
			'tcUnit'   => isset( $values['tcUnit'] ) ? $values['tcUnit'] : 'mmol/L',
			'heightCm' => $p::num( $values, 'heightCm' ),
			'weightKg' => $p::num( $values, 'weightKg' ),
			'cvd'      => isset( $values['cvd'] ) && '' !== $values['cvd'] ? $values['cvd'] : null,
			'preg'     => isset( $values['preg'] ) && '' !== $values['preg'] ? $values['preg'] : null,
			'eat'      => isset( $values['eat'] ) && '' !== $values['eat'] ? $values['eat'] : 'no',
		);
		if ( null === $in['heightCm'] || null === $in['weightKg'] ) {
			unset( $in['heightCm'], $in['weightKg'] );
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
	 * The result in the sheet's order. A stop shows the safety line (if any) and the stop wording, never a number.
	 *
	 * @param array $r   Engine result with `wording`.
	 * @param array $ctx Context.
	 */
	public static function result( array $r, array $ctx ) {
		unset( $ctx );
		$p    = 'LLD_Members_Calculator_Pages';
		$w    = isset( $r['wording'] ) && is_array( $r['wording'] ) ? $r['wording'] : array();
		$fill = isset( $r['fill'] ) && is_array( $r['fill'] ) ? $r['fill'] : array();
		$html = '';
		foreach ( isset( $r['messages'] ) ? $r['messages'] : array() as $key ) {
			if ( 0 === strpos( $key, 'bp-check.' ) ) {
				$html .= $p::safety_key( $key, isset( $w[ $key ] ) ? $w[ $key ] : '' );
			}
		}
		if ( ! empty( $r['stop'] ) ) {
			return $html . '<div class="lld-calc-stop" data-lld-stop="' . esc_attr( (string) $r['stop'] ) . '">' . $p::para( $w, 'stops.' . $r['stop'] ) . '</div>';
		}
		$risk  = (int) $r['risk'];
		$html .= '<div class="lld-calc-headline lld-band-' . esc_attr( (string) $r['band'] ) . '" data-lld-risk="' . esc_attr( (string) $risk ) . '" data-lld-band="' . esc_attr( (string) $r['band'] ) . '">';
		$html .= '<p class="lld-calc-figure">' . ( 0 === $risk ? $p::text( $w, 'headline.under1' ) : $p::text( $w, 'headline', $fill ) ) . '</p>';
		$html .= $p::para( $w, 'band.' . $r['band'], array(), 'lld-calc-band' ) . '</div>';
		$html .= self::people( $risk );
		$html .= 0 === $risk ? $p::para( $w, 'comparison.under1' ) : $p::para( $w, 'comparison', $fill );
		$html .= self::whatif( $r, $w, $fill );
		foreach ( array( 'whatif.none', 'whatif.noFall', 'whatif.diabetes' ) as $key ) {
			if ( in_array( $key, $r['messages'], true ) ) {
				$html .= $p::para( $w, $key );
			}
		}
		$html .= $p::para( $w, 'model.' . $r['model'], array(), 'lld-hint' );
		foreach ( array( 'notes.diabetes', 'always', 'other-methods', 'limits.other-conditions' ) as $key ) {
			if ( in_array( $key, $r['messages'], true ) ) {
				$html .= $p::para( $w, $key, $fill, 'lld-calc-note' );
			}
		}
		return $html;
	}

	/**
	 * The signed what-if line, with the template's {A | B | C | D} choice made for the first factor.
	 *
	 * @param array $r    Engine result.
	 * @param array $w    Wording.
	 * @param array $fill Placeholder values.
	 */
	private static function whatif( array $r, array $w, array $fill ) {
		$key = in_array( 'whatif.under1', $r['messages'], true ) ? 'whatif.under1' : ( in_array( 'whatif', $r['messages'], true ) ? 'whatif' : '' );
		if ( '' === $key || empty( $r['whatif'] ) || ! isset( $w[ $key ] ) ) {
			return '';
		}
		$factor = (string) $r['whatif'][0]['factor'];
		$text   = self::choose( (string) $w[ $key ], isset( self::FACTOR_SLOT[ $factor ] ) ? self::FACTOR_SLOT[ $factor ] : 0 );
		$fill['factor'] = isset( self::FACTOR_NAME[ $factor ] ) ? self::FACTOR_NAME[ $factor ] : $factor;
		$out  = $text;
		foreach ( $fill as $name => $value ) {
			if ( null !== $value && is_scalar( $value ) ) {
				$out = str_replace( '{' . $name . '}', (string) $value, $out );
			}
		}
		return '<p class="lld-calc-whatif" data-lld-key="' . esc_attr( $key ) . '" data-lld-factor="' . esc_attr( $factor ) . '">' . str_replace( ' %', '&nbsp;%', esc_html( $out ) ) . '</p>';
	}

	/**
	 * Pick option $slot from the first "{A | B | …}" group that holds a "|" (inner {placeholders} kept).
	 *
	 * @param string $text Template.
	 * @param int    $slot Option.
	 */
	public static function choose( $text, $slot ) {
		$start = -1;
		$depth = 0;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '{' === $text[ $i ] ) {
				if ( 0 === $depth ) {
					$start = $i;
				}
				++$depth;
			} elseif ( '}' === $text[ $i ] && $depth > 0 ) {
				--$depth;
				if ( 0 === $depth ) {
					$inner = substr( $text, $start + 1, $i - $start - 1 );
					if ( false !== strpos( $inner, '|' ) ) {
						$parts = array_map( 'trim', self::split_top( $inner ) );
						$pick  = isset( $parts[ $slot ] ) ? $parts[ $slot ] : $parts[0];
						return substr( $text, 0, $start ) . $pick . substr( $text, $i + 1 );
					}
				}
			}
		}
		return $text;
	}

	/**
	 * Split on "|" outside nested braces.
	 *
	 * @param string $s Text.
	 * @return string[]
	 */
	private static function split_top( $s ) {
		$parts = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				--$depth;
			}
			if ( '|' === $c && 0 === $depth ) {
				$parts[] = $cur;
				$cur     = '';
				continue;
			}
			$cur .= $c;
		}
		$parts[] = $cur;
		return $parts;
	}

	/**
	 * The icon array of 100 (sheet section 3): one dot per person, the risk's number filled. Decorative: the
	 * comparison sentence says the same in words.
	 *
	 * @param int $risk Whole-number risk.
	 */
	private static function people( $risk ) {
		$svg = '<svg class="lld-calc-people" viewBox="0 0 200 200" width="160" height="160" aria-hidden="true" focusable="false">';
		for ( $i = 0; $i < 100; $i++ ) {
			$x    = 10 + ( $i % 10 ) * 20;
			$y    = 10 + intdiv( $i, 10 ) * 20;
			$svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="7" class="' . ( $i < $risk ? 'is-on' : 'is-off' ) . '"/>';
		}
		return $svg . '</svg>';
	}

	/**
	 * The saved row (P3-5B): the whole-number risk, band, model and region, with the sealed inputs.
	 *
	 * @param array $r      Engine result.
	 * @param array $inputs Engine inputs.
	 * @return array|null
	 */
	public static function saved( array $r, array $inputs ) {
		if ( ! empty( $r['stop'] ) || ! isset( $r['risk'] ) ) {
			return null;
		}
		return array(
			'calculator'         => self::SAVED,
			'headline'           => (string) $r['risk'],
			'unit'               => '%',
			'band'               => (string) $r['band'],
			'calculator_version' => (string) $r['version'],
			'result'             => array( 'risk' => $r['risk'], 'band' => $r['band'], 'model' => $r['model'], 'region' => $r['region'] ),
			'inputs'             => $inputs,
		);
	}

	/**
	 * Method and sources (sheet sections 1 and 8), in the engine's own signed words where they exist.
	 */
	public static function about() {
		return '<p>Based on the World Health Organization 2019 cardiovascular disease risk charts (Kaptoge et al., Lancet Global Health 2019), for 21 world regions. Version ' . esc_html( LLD_Members_Heart_Risk::VERSION ) . '.</p>';
	}
}
