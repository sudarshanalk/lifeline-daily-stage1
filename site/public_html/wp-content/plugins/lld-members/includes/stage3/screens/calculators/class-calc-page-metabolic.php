<?php
/**
 * Metabolic Health Check page (P3-59, FR3-51): /health-tools/metabolic-health-check/.
 *
 * Inputs and their order follow the signed sheet (P3-59 section 2, units section 1.2); the result follows its
 * sections 3 and 4 and the engine's message order: the urgent safety lines first, the headline and body, a table
 * with one row per component (waist and the four criteria), the other flags and notes, the lifestyle paragraphs, then
 * the "about" line. Every sentence comes from LLD_Members_Metabolic::wording(); the page adds labels and layout only.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The metabolic health check page.
 */
class LLD_Members_Calc_Page_Metabolic {

	const ENGINE = 'metabolic';
	const SAVED  = 'metabolic';
	const TITLE  = 'Metabolic health check';
	const INTRO  = 'Checks your waist, blood pressure and blood test results against the International Diabetes Federation definition of metabolic syndrome.';

	/** Engine field => how the error message names it. */
	/** The Healthy Weight Check's approved waist instructions (healthy-weight-tool.html, howWaist), word for word. */
	const WAIST_GUIDE = array(
		'title' => 'How to measure your waist',
		'body'  => 'Stand up straight and breathe out normally. Find the point halfway between your lowest rib and the top of your hip bone, usually just above your belly button. Wrap a tape measure around your body at that level, keeping it level and snug but not tight, and read it without pulling your stomach in.',
	);

	/** The Blood Pressure Reading Check's approved 7-day hint (stage3-calculator-page-wording.md). */
	const AVERAGE_HINT = 'The 7-day average uses the blood pressure readings you have logged in the last 7 days.';

	const FIELDS = array(
		'bpAverage'               => 'your logged readings. Add readings on at least 3 days for a 7-day average',
		'age'                     => 'your age',
		'sex'                     => 'your sex',
		'pregnantOrBreastfeeding' => 'the pregnancy answer',
		'eatingDisorder'          => 'the eating question',
		'waist'                   => 'your waist measurement',
		'bloodPressure'           => 'the blood pressure (both numbers, or tick the medicine box)',
		'bpMedicine'              => 'the blood pressure medicine answer',
		'triglycerides'           => 'the triglycerides',
		'triglyceridesUnit'       => 'the triglycerides unit',
		'triglyceridesMedicine'   => 'the triglycerides medicine answer',
		'hdl'                     => 'the HDL cholesterol',
		'hdlUnit'                 => 'the HDL cholesterol unit',
		'hdlMedicine'             => 'the HDL medicine answer',
		'fastingGlucose'          => 'the fasting glucose',
		'fastingGlucoseUnit'      => 'the fasting glucose unit',
		'fastingGlucoseFasting'   => 'whether the glucose result was taken fasting',
		'type2Diabetes'           => 'the type 2 diabetes answer',
	);

	/**
	 * Family background (sheet input 3: the Healthy Weight Check list, plus the two IDF groups it lacks), in spellings
	 * LLD_Members_Metabolic::background() maps to the IDF groups. Anything it does not name is "other".
	 */
	const BACKGROUNDS = array(
		''                     => 'Choose your family background',
		'White'                => 'White',
		'Indian'               => 'Indian',
		'Pakistani'            => 'Pakistani',
		'Bangladeshi'          => 'Bangladeshi',
		'OtherSouthAsian'      => 'Other South Asian',
		'Chinese'              => 'Chinese',
		'Japanese'             => 'Japanese',
		'OtherAsian'           => 'Other Asian',
		'MiddleEastern'        => 'Middle Eastern',
		'BlackAfrican'         => 'Black African',
		'BlackCaribbean'       => 'Black Caribbean (African-Caribbean)',
		'SouthCentralAmerican' => 'South or Central American',
		'Mixed'                => 'Mixed or other',
		'NotSay'               => 'Prefer not to say',
	);

	/** The three blood values, their answer names and their medicine tick. */
	const LABS = array(
		'triglycerides'  => 'triglyceridesMedicine',
		'hdl'            => 'hdlMedicine',
		'fastingGlucose' => 'type2Diabetes',
	);

	/** Table wording (page labels, listed for the owner). */
	const TABLE = array(
		'caption'   => 'Each result explained',
		'col_name'  => 'Result',
		'col_value' => 'You entered',
		'col_limit' => 'Threshold',
		'col_state' => 'Status',
		'waist'     => 'Waist',
		'bp'        => 'Blood pressure',
		'above'     => 'Above the threshold',
		'below'     => 'Below the threshold',
		'within'    => 'Within range',
		'missing'   => 'Not provided',
		'not_used'  => 'Not used: not a fasting result',
		'medicine'  => 'Counted as above: you take medicine for this',
		'diabetes'  => 'Counted as above: type 2 diabetes',
		'none'      => '-',
	);

	/**
	 * Pre-fills for a signed-in member, from the profile only: age, sex, waist, and the type 2 diabetes tick when
	 * diabetes is in the profile (the engine's apply_profile does that last one again on the server).
	 *
	 * @param array $ctx Context.
	 */
	public static function defaults( array $ctx ) {
		$v = array( 'triglyceridesUnit' => 'mmol/L', 'hdlUnit' => 'mmol/L', 'fastingGlucoseUnit' => 'mmol/L', 'pregnantOrBreastfeeding' => '', 'eatingDisorder' => 'no', 'fastingGlucoseFasting' => 'yes' ); // the engine reads a blank as fasting, so the form shows it
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
			$v['waistCm'] = (string) round( (float) $f['waist_cm'], 1 );
		}
		$situations = isset( $f['situations'] ) && is_array( $f['situations'] ) ? $f['situations'] : array();
		if ( in_array( 'diabetes', $situations, true ) ) {
			$v['type2Diabetes'] = 'yes';
		}
		return $v;
	}

	/**
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function form( array $values, array $ctx ) {
		$p      = 'LLD_Members_Calculator_Pages';
		$v      = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) ? (string) $values[ $k ] : '';
		};
		$units  = array( 'mmol/L' => 'mmol/L', 'mg/dL' => 'mg/dL' );
		$html   = $p::number( 'age', 'Age', $v( 'age' ), array( 'min' => 18, 'max' => 99, 'step' => 1, 'unit' => 'years' ) );
		$html  .= $p::choice( 'sex', 'Sex', array( 'female' => 'Female', 'male' => 'Male', 'pnts' => 'Prefer not to say' ), $v( 'sex' ) );
		$html  .= $p::select( 'background', 'Family background', self::BACKGROUNDS, $v( 'background' ) );
		$html  .= $p::choice( 'pregnantOrBreastfeeding', 'Are you pregnant or breastfeeding?', array( 'yes' => 'Yes', 'no' => 'No', 'notsure' => 'Not sure' ), $v( 'pregnantOrBreastfeeding' ) );
		$html  .= $p::number( 'waistCm', 'Waist', $v( 'waistCm' ), array( 'min' => 40, 'max' => 250, 'step' => '0.1', 'unit' => 'cm' ) );
		// The measuring guide (sheet 2, input 5): the Healthy Weight Check's approved instructions, word for word.
		$html  .= '<details class="lld-calc-guide" data-lld-guide="waist"><summary>' . esc_html( self::WAIST_GUIDE['title'] ) . '</summary><p>' . esc_html( self::WAIST_GUIDE['body'] ) . '</p></details>';
		$html  .= '<fieldset class="lld-field lld-calc-group"><legend>Blood pressure</legend><div class="lld-field-row">';
		$html  .= $p::number( 'bpSystolic', 'Systolic (the top number)', $v( 'bpSystolic' ), array( 'min' => 60, 'max' => 260, 'step' => 1, 'unit' => 'mmHg' ) );
		$html  .= $p::number( 'bpDiastolic', 'Diastolic (the bottom number)', $v( 'bpDiastolic' ), array( 'min' => 30, 'max' => 160, 'step' => 1, 'unit' => 'mmHg' ) );
		$html  .= '</div>';
		if ( self::average_offered( $ctx ) ) {
			// Signed-in members with health information on (sheet 2, input 6): the 7-day average of their own readings.
			$html .= self::tick( 'bpAverage', 'Use my 7-day average', $v( 'bpAverage' ) ) . '<p class="lld-hint">' . esc_html( self::AVERAGE_HINT ) . '</p>';
		}
		$html  .= self::tick( 'bpMedicine', 'I take blood pressure medicine', $v( 'bpMedicine' ) ) . '</fieldset>';
		$labels = array(
			'triglycerides'  => array( 'Triglycerides', 'From a fasting blood test. Leave blank if you don\'t know it.', 'I take medicine for high triglycerides' ),
			'hdl'            => array( 'HDL cholesterol', 'Leave blank if you don\'t know it.', 'I take medicine to raise HDL' ),
			'fastingGlucose' => array( 'Fasting glucose', 'Fasting results only. Leave blank if you don\'t know it.', 'I have type 2 diabetes' ),
		);
		foreach ( self::LABS as $field => $tick ) {
			$unit  = '' !== $v( $field . 'Unit' ) ? $v( $field . 'Unit' ) : 'mmol/L';
			$html .= '<div class="lld-calc-group" data-lld-group="' . esc_attr( $field ) . '"><div class="lld-field-row">';
			$html .= $p::number( $field, $labels[ $field ][0], $v( $field ), array( 'min' => 0, 'step' => '0.01', 'hint' => $labels[ $field ][1] ) );
			$html .= $p::select( $field . 'Unit', 'Unit', $units, $unit );
			$html .= '</div>' . self::tick( $tick, $labels[ $field ][2], $v( $tick ) ) . '</div>';
		}
		$html .= $p::choice( 'fastingGlucoseFasting', 'Was this glucose result taken fasting?', array( 'yes' => 'Yes', 'no' => 'No' ), $v( 'fastingGlucoseFasting' ) );
		$html .= $p::choice( 'eatingDisorder', 'Do you have, or have you had, an eating disorder?', array( 'no' => 'No', 'yes' => 'Yes', 'pnts' => 'Prefer not to say' ), $v( 'eatingDisorder' ) );
		return $html;
	}

	/**
	 * A tick box that always posts an answer: a hidden "no" before the box, so unticking a pre-filled box sends "no"
	 * (PHP keeps the last of two same-named fields).
	 *
	 * @param string $name  Answer name.
	 * @param string $label Label.
	 * @param string $value Current answer.
	 */
	private static function tick( $name, $label, $value ) {
		$id = 'lld-c-' . $name;
		return '<div class="lld-field lld-calc-tick"><input type="hidden" name="lld_c[' . esc_attr( $name ) . ']" value="no">'
			. '<label class="lld-calc-option" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="lld_c[' . esc_attr( $name ) . ']" value="yes"' . ( 'yes' === $value ? ' checked' : '' ) . '> <span>' . esc_html( $label ) . '</span></label></div>';
	}

	/**
	 * The engine's inputs from the answers. A blank blood value is "don't know"; a blank fasting answer and a blank
	 * pregnancy answer take the engine's own defaults.
	 *
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function inputs( array $values, array $ctx ) {
		$p   = 'LLD_Members_Calculator_Pages';
		$s   = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) && '' !== (string) $values[ $k ] ? (string) $values[ $k ] : null;
		};
		$in  = array(
			'age'                     => $p::num( $values, 'age' ),
			'sex'                     => isset( $values['sex'] ) ? (string) $values['sex'] : '',
			'background'              => isset( $values['background'] ) ? (string) $values['background'] : '',
			// A skipped answer is asked for, never read as "no" (the engine defaults a missing answer to no).
			'pregnantOrBreastfeeding' => null !== $s( 'pregnantOrBreastfeeding' ) ? $s( 'pregnantOrBreastfeeding' ) : 'unanswered',
			'waistCm'                 => $p::num( $values, 'waistCm' ),
			'bpSystolic'              => $p::num( $values, 'bpSystolic' ),
			'bpDiastolic'             => $p::num( $values, 'bpDiastolic' ),
			'bpMedicine'              => $s( 'bpMedicine' ),
		);
		foreach ( self::LABS as $field => $tick ) {
			$in[ $field ]          = $p::num( $values, $field );
			$in[ $field . 'Unit' ] = null !== $s( $field . 'Unit' ) ? $s( $field . 'Unit' ) : 'mmol/L';
			$in[ $tick ]           = $s( $tick );
		}
		$in['fastingGlucoseFasting'] = $s( 'fastingGlucoseFasting' );
		$in['eatingDisorder']        = null !== $s( 'eatingDisorder' ) ? $s( 'eatingDisorder' ) : 'no';
		if ( 'yes' === $s( 'bpAverage' ) ) {
			$average = self::average_offered( $ctx ) ? self::seven_day_average( (int) $ctx['member'] ) : null;
			if ( null === $average ) {
				$in['_error'] = 'bpAverage'; // fewer than 3 days of readings, or not available: say so, never guess
				return $in;
			}
			$in['bpSystolic']  = $average['systolic'];
			$in['bpDiastolic'] = $average['diastolic'];
		}
		return $in;
	}

	/**
	 * @param array $ctx Context.
	 * @return bool The 7-day average is offered: a signed-in member with health information on.
	 */
	private static function average_offered( array $ctx ) {
		return ! empty( $ctx['member'] ) && ! empty( $ctx['health'] ) && class_exists( 'LLD_Members_Stage3_API' );
	}

	/**
	 * The member's 7-day blood pressure average, worked out exactly as the Blood Pressure Reading Check does it (the
	 * same engine and the same readings, through the members' interface), or null without one.
	 *
	 * @param int $member_id Member number.
	 * @return array{systolic:int,diastolic:int}|null
	 */
	private static function seven_day_average( $member_id ) {
		static $cache = array();
		if ( ! array_key_exists( $member_id, $cache ) ) {
			$r                    = LLD_Members_Stage3_API::run_page_calculator( 'bp-check', array( 'mode' => '7-day', 'pregnant' => 'no' ), $member_id );
			$cache[ $member_id ] = ( ! isset( $r['error'] ) && ! empty( $r['average_shown'] ) && isset( $r['average']['systolic'], $r['average']['diastolic'] ) )
				? array( 'systolic' => (int) $r['average']['systolic'], 'diastolic' => (int) $r['average']['diastolic'] )
				: null;
		}
		return $cache[ $member_id ];
	}

	/**
	 * @param string $field Engine field.
	 */
	public static function error_text( $field ) {
		return LLD_Members_Calculator_Pages::field_error( $field, self::FIELDS );
	}

	/**
	 * The result in the engine's message order. A stop or a refused reading shows the safety lines (if any) and its
	 * own wording, never a table.
	 *
	 * @param array $r   Engine result with `wording`.
	 * @param array $ctx Context.
	 */
	public static function result( array $r, array $ctx ) {
		unset( $ctx );
		$p        = 'LLD_Members_Calculator_Pages';
		$w        = isset( $r['wording'] ) && is_array( $r['wording'] ) ? $r['wording'] : array();
		$fill     = isset( $r['placeholders'] ) && is_array( $r['placeholders'] ) ? $r['placeholders'] : array();
		$messages = isset( $r['messages'] ) && is_array( $r['messages'] ) ? $r['messages'] : array();
		$html     = '';
		foreach ( $messages as $key ) {
			if ( in_array( $key, LLD_Members_Metabolic::SAFETY_FIRST, true ) ) {
				$html .= $p::safety_key( self::safety_name( $key, $r ), isset( $w[ $key ] ) ? $w[ $key ] : '' );
			}
		}
		if ( ! empty( $r['stop'] ) ) {
			return $html . '<div class="lld-calc-stop" data-lld-stop="' . esc_attr( substr( (string) $r['stop'], 6 ) ) . '">' . $p::para( $w, (string) $r['stop'] ) . '</div>';
		}
		if ( ! empty( $r['refused'] ) ) {
			return $html . '<div class="lld-calc-stop" data-lld-refused="' . esc_attr( (string) $r['refusedField'] ) . '">' . ( in_array( 'refused.impossible', $messages, true ) ? $p::para( $w, 'refused.impossible' ) : '' ) . '</div>';
		}
		$outcome = (string) $r['result'];
		$html   .= '<div class="lld-calc-headline" data-lld-outcome="' . esc_attr( self::outcome( $outcome ) ) . '" data-lld-count="' . esc_attr( (string) (int) $r['count'] ) . '">';
		$html   .= '<p class="lld-calc-figure" data-lld-key="' . esc_attr( $outcome . '.headline' ) . '">' . $p::text( $w, $outcome . '.headline', $fill ) . '</p>';
		$html   .= $p::para( $w, $outcome . '.body', $fill ) . '</div>';
		$html   .= self::table( $r );
		$cautions = array( 'flags.glucoseDiagnostic', 'flags.glucoseDiagnosticNonFasting', 'flags.glucoseLow', 'flags.triglyceridesHigh' );
		foreach ( $messages as $key ) {
			if ( in_array( $key, LLD_Members_Metabolic::SAFETY_FIRST, true ) || 0 === strpos( $key, 'results.' ) || 'about.definition' === $key ) {
				continue;
			}
			if ( in_array( $key, $cautions, true ) ) {
				$html .= $p::safety( $p::text( $w, $key, $fill ), false, $key );
			} elseif ( 0 === strpos( $key, 'lifestyle.' ) ) {
				$html .= $p::para( $w, $key, $fill, 'lld-calc-lifestyle' );
			} else {
				$html .= $p::para( $w, $key, $fill, 'lld-calc-note' );
			}
		}
		$html .= $p::para( $w, 'about.definition', array(), 'lld-hint' );
		return $html;
	}

	/**
	 * The safety-layer key a SAFETY_FIRST flag stands for, so safety_key() shows it as the alert or the caution.
	 *
	 * @param string $key Flag.
	 * @param array  $r   Engine result.
	 */
	private static function safety_name( $key, array $r ) {
		if ( 'flags.glucoseUrgent' === $key ) {
			return isset( $r['glucoseUrgent'] ) && 'low' === $r['glucoseUrgent'] ? 'glucose.urgent-low' : 'glucose.urgent-high';
		}
		$bp = array_flip( LLD_Members_Metabolic::BP_FLAGS );
		return isset( $bp[ $key ] ) ? $bp[ $key ] : $key;
	}

	/**
	 * The saved outcome word for a result key (LLD_Members_Saved_Results::compare_metabolic reads met / not met).
	 *
	 * @param string $result results.meets, results.notMet, results.notMet.waistBelow or results.partial.
	 */
	private static function outcome( $result ) {
		if ( 'results.meets' === $result ) {
			return 'met';
		}
		return 'results.partial' === $result ? 'partial' : 'not met';
	}

	/**
	 * One row per component (sheet 3.1): waist, then the engine's components in the sheet's order.
	 *
	 * @param array $r Engine result.
	 */
	private static function table( array $r ) {
		$t        = self::TABLE;
		$hdl_sex  = 80 === (int) $r['waistCutoffCm'] ? 'F' : 'M'; // the engine's women's (and prefer-not-to-say) pair
		$not_used = in_array( 'notes.useFasting', isset( $r['messages'] ) ? $r['messages'] : array(), true );
		$html     = '<table class="lld-calc-table" data-lld-components><caption>' . esc_html( $t['caption'] ) . '</caption><thead><tr>';
		foreach ( array( 'col_name', 'col_value', 'col_limit', 'col_state' ) as $col ) {
			$html .= '<th scope="col">' . esc_html( $t[ $col ] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		$html .= self::row( 'waist', $t['waist'], self::fmt( $r['waistCm'] ) . ' cm', 'at or above ' . (int) $r['waistCutoffCm'] . ' cm', $r['central'] ? $t['above'] : $t['within'], $r['central'] ? 'met' : 'not-met' );
		foreach ( $r['components'] as $c ) {
			$field  = (string) $c['component'];
			$status = (string) $c['status'];
			if ( 'bloodPressure' === $field ) {
				$name  = $t['bp'];
				$value = is_array( $c['value'] ) ? (int) $c['value']['systolic'] . '/' . (int) $c['value']['diastolic'] . ' mmHg' : $t['none'];
				$limit = 'at or above ' . (int) $c['threshold']['sys'] . ' (top) or ' . (int) $c['threshold']['dia'] . ' (bottom) mmHg';
			} else {
				$name  = ucfirst( LLD_Members_Metabolic::MISSING_LABELS[ $field ] );
				$value = $t['none'];
				if ( null !== $c['value'] ) {
					$value = self::fmt( $c['value'] ) . ' ' . $c['unit'];
					if ( is_array( $c['converted'] ) ) {
						$value .= ' (' . self::fmt( $c['converted']['value'] ) . ' ' . $c['converted']['unit'] . ')';
					}
				}
				$limit = self::limit( $field, $c, $hdl_sex );
			}
			if ( ! empty( $c['treatment'] ) ) {
				$state = 'fastingGlucose' === $field ? $t['diabetes'] : $t['medicine'];
			} elseif ( 'met' === $status ) {
				$state = 'hdl' === $field ? $t['below'] : $t['above'];
			} elseif ( 'not-met' === $status ) {
				$state = $t['within'];
			} else {
				$state = 'fastingGlucose' === $field && $not_used ? $t['not_used'] : $t['missing'];
			}
			$html .= self::row( $field, $name, $value, $limit, $state, $status );
		}
		return $html . '</tbody></table>';
	}

	/**
	 * The threshold in the unit entered, or in both units when no value was entered.
	 *
	 * @param string $field   Component.
	 * @param array  $c       Component row.
	 * @param string $hdl_sex F or M.
	 */
	private static function limit( $field, array $c, $hdl_sex ) {
		$pair  = 'hdl' === $field ? LLD_Members_Metabolic::THRESHOLDS['hdl'][ $hdl_sex ] : LLD_Members_Metabolic::THRESHOLDS[ $field ];
		$words = (string) $c['comparison'] . ' ';
		if ( null !== $c['unit'] && null !== $c['threshold'] ) {
			return $words . self::fmt( $c['threshold'] ) . ' ' . $c['unit'];
		}
		return $words . self::fmt( $pair['mmol'] ) . ' mmol/L (' . self::fmt( $pair['mg'] ) . ' mg/dL)';
	}

	/**
	 * @param string $field  Component.
	 * @param string $name   Row name.
	 * @param string $value  Value shown.
	 * @param string $limit  Threshold shown.
	 * @param string $state  Status shown.
	 * @param string $status Engine status.
	 */
	private static function row( $field, $name, $value, $limit, $state, $status ) {
		return '<tr data-lld-component="' . esc_attr( $field ) . '" data-lld-status="' . esc_attr( $status ) . '"><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( $value ) . '</td><td>' . esc_html( $limit ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
	}

	/**
	 * A number as entered: whole numbers without decimals, others to at most 2 decimals.
	 *
	 * @param mixed $x Number.
	 */
	private static function fmt( $x ) {
		$x = round( (float) $x, 2 );
		return floor( $x ) === $x ? (string) (int) $x : rtrim( rtrim( number_format( $x, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * The saved row (P3-5B, sheet section 5): the overall outcome, the count of criteria met and each component's
	 * status, with the inputs. Nothing to save after a stop or a refused reading.
	 *
	 * @param array $r      Engine result.
	 * @param array $inputs Engine inputs.
	 * @return array|null
	 */
	public static function saved( array $r, array $inputs ) {
		if ( ! empty( $r['stop'] ) || ! empty( $r['refused'] ) || ! isset( $r['result'], $r['count'] ) ) {
			return null;
		}
		$components = array( 'waist' => $r['central'] ? 'met' : 'not-met' );
		foreach ( $r['components'] as $c ) {
			$components[ (string) $c['component'] ] = (string) $c['status'];
		}
		$outcome = self::outcome( (string) $r['result'] );
		return array(
			'calculator'         => self::SAVED,
			'headline'           => (string) (int) $r['count'],
			'unit'               => 'of 4',
			'band'               => $outcome,
			'calculator_version' => (string) $r['version'],
			'result'             => array( 'outcome' => $outcome, 'criteria_met' => (int) $r['count'], 'components' => $components ),
			'inputs'             => $inputs,
		);
	}

	/**
	 * Method and sources (sheet sections 3.4 and 8), in the engine's own signed words where they exist.
	 */
	public static function about() {
		return '<p>' . esc_html( LLD_Members_Metabolic::MESSAGES['about.definition'] ) . '</p>'
			. '<p>Sources: International Diabetes Federation consensus worldwide definition of the metabolic syndrome (2006); Alberti, Zimmet and Shaw, Lancet 2005. Version ' . esc_html( LLD_Members_Metabolic::VERSION ) . '.</p>';
	}
}
