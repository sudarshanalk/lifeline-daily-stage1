<?php
/**
 * Blood Pressure Reading Check page (P3-58, FR3-50): /health-tools/blood-pressure-check/.
 *
 * Inputs follow the signed sheet (P3-58 section 2); the result follows its sections 3 to 5: any urgent, pregnancy or
 * dizzy line first, then the reading (or the 7-day average) with its category, then the low, pulse, 3-day, 80-plus and
 * medicine notes. Every sentence comes from LLD_Members_BP_Check::wording(). The 7-day average is offered only to a
 * signed-in member: the members' API reads the member's own logged readings for it (sheet section 5).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The blood pressure page.
 */
class LLD_Members_Calc_Page_BP_Check {

	const ENGINE = 'bp-check';
	const SAVED  = 'bp_check';
	const TITLE  = 'Blood pressure reading check';
	const INTRO  = 'Shows what your blood pressure reading means, and when to get it checked.';

	/** Safety-layer lines shown first, in this order (sheet sections 4.1 to 4.3). */
	const SAFETY_FIRST = array( 'urgent.alert', 'pregnancy.urgent', 'pregnancy.same-day', 'low.dizzy' );

	/** Notes after the category, in the engine's order (sheet sections 3, 4.3 and 5). */
	const NOTES = array( 'category.normal-low.body', 'pulse.note', 'home.min-days', 'age80.note', 'medicine.note' );

	/** Engine field => how the error message names it. */
	const FIELDS = array(
		'mode'      => 'the type of check. The 7-day average is for signed-in members',
		'systolic'  => 'the top number',
		'diastolic' => 'the bottom number',
		'pulse'     => 'the pulse',
		'age'       => 'your age',
		'pregnant'  => 'the pregnancy answer',
		'medicine'  => 'the blood pressure medicine answer',
		'dizzy'     => 'the dizzy or faint answer',
		'readings'  => 'your logged readings',
		'today'     => 'your logged readings',
	);

	/**
	 * Pre-fills for a signed-in member: age, and pregnancy when the member's own profile says so (the engine's
	 * apply_profile does that again on the server). Nothing else is pre-filled.
	 *
	 * @param array $ctx Context.
	 */
	public static function defaults( array $ctx ) {
		$v = array( 'mode' => 'single', 'pregnant' => '', 'medicine' => '', 'dizzy' => '' );
		$f = $ctx['facts'];
		if ( ! is_array( $f ) ) {
			return $v;
		}
		if ( ! empty( $f['age'] ) && (int) $f['age'] >= LLD_Members_BP_Check::AGE_RANGE[0] && (int) $f['age'] <= LLD_Members_BP_Check::AGE_RANGE[1] ) {
			$v['age'] = (string) (int) $f['age'];
		}
		$situations = isset( $f['situations'] ) && is_array( $f['situations'] ) ? $f['situations'] : array();
		if ( in_array( 'pregnancy', $situations, true ) ) {
			$v['pregnant'] = 'yes';
		}
		return $v;
	}

	/**
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function form( array $values, array $ctx ) {
		$p    = 'LLD_Members_Calculator_Pages';
		$v    = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) ? (string) $values[ $k ] : '';
		};
		$html = '';
		if ( (int) $ctx['member'] > 0 ) {
			$html .= $p::choice( 'mode', 'What do you want to check?', array( 'single' => 'Single reading', '7-day' => '7-day average' ), '' !== $v( 'mode' ) ? $v( 'mode' ) : 'single', 'The 7-day average uses the blood pressure readings you have logged in the last 7 days.' );
		}
		$html .= '<div class="lld-field-row">';
		$html .= $p::number( 'systolic', 'Systolic (top number)', $v( 'systolic' ), array( 'min' => 60, 'max' => 260, 'step' => 1, 'unit' => 'mmHg' ) );
		$html .= $p::number( 'diastolic', 'Diastolic (bottom number)', $v( 'diastolic' ), array( 'min' => 30, 'max' => 160, 'step' => 1, 'unit' => 'mmHg' ) );
		$html .= '</div>';
		$html .= $p::number( 'pulse', 'Pulse (optional)', $v( 'pulse' ), array( 'min' => 30, 'max' => 220, 'step' => 1, 'unit' => 'beats a minute' ) );
		$html .= $p::number( 'age', 'Age', $v( 'age' ), array( 'min' => 18, 'max' => 99, 'step' => 1, 'unit' => 'years' ) );
		$html .= $p::choice( 'pregnant', 'Pregnant?', array( 'yes' => 'Yes', 'no' => 'No', 'notsure' => 'Not sure' ), $v( 'pregnant' ) );
		$html .= $p::choice( 'medicine', 'Do you take blood pressure medicine?', array( 'yes' => 'Yes', 'no' => 'No' ), $v( 'medicine' ) );
		$html .= '<div class="lld-field lld-calc-tick"><label class="lld-calc-option" for="lld-c-dizzy"><input type="hidden" name="lld_c[dizzy]" value="no"><input type="checkbox" id="lld-c-dizzy" name="lld_c[dizzy]" value="yes"' . ( 'yes' === $v( 'dizzy' ) ? ' checked' : '' ) . ' data-lld-tick="dizzy"> <span>' . esc_html( 'I feel dizzy or faint' ) . '</span></label></div>';
		return $html;
	}

	/**
	 * The engine's inputs from the answers. A pregnancy question left blank is sent as unanswered, so the engine asks
	 * for it rather than reading it as "No" (the pregnancy levels depend on it). In 7-day mode the members' API adds
	 * the member's own readings; the reading fields are not sent.
	 *
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function inputs( array $values, array $ctx ) {
		unset( $ctx );
		$p    = 'LLD_Members_Calculator_Pages';
		$mode = isset( $values['mode'] ) && '' !== $values['mode'] ? (string) $values['mode'] : 'single';
		$in   = array(
			'mode'     => $mode,
			'pregnant' => isset( $values['pregnant'] ) && '' !== $values['pregnant'] ? (string) $values['pregnant'] : 'unanswered',
			'medicine' => isset( $values['medicine'] ) ? (string) $values['medicine'] : '',
			'dizzy'    => isset( $values['dizzy'] ) ? (string) $values['dizzy'] : '',
			'age'      => null === $p::num( $values, 'age' ) ? ( isset( $values['age'] ) && '' !== trim( (string) $values['age'] ) ? (string) $values['age'] : '' ) : $p::num( $values, 'age' ),
		);
		if ( ! LLD_Members_BP_Check::home_mode( $mode ) ) {
			$in['systolic']  = $p::num( $values, 'systolic' );
			$in['diastolic'] = $p::num( $values, 'diastolic' );
			$pulse           = $p::num( $values, 'pulse' );
			if ( null !== $pulse ) {
				$in['pulse'] = $pulse;
			} elseif ( isset( $values['pulse'] ) && '' !== trim( (string) $values['pulse'] ) ) {
				$in['pulse'] = (string) $values['pulse']; // not a number: the engine asks for it
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
	 * The result in the sheet's order. A refused reading shows the refusal wording only, never a number or category.
	 *
	 * @param array $r   Engine result with `wording`.
	 * @param array $ctx Context.
	 */
	public static function result( array $r, array $ctx ) {
		unset( $ctx );
		$p    = 'LLD_Members_Calculator_Pages';
		$w    = isset( $r['wording'] ) && is_array( $r['wording'] ) ? $r['wording'] : array();
		$keys = isset( $r['messages'] ) && is_array( $r['messages'] ) ? $r['messages'] : array();
		$html = '';
		foreach ( self::SAFETY_FIRST as $key ) {
			if ( in_array( $key, $keys, true ) ) {
				$html .= $p::safety_key( 'bp-check.' . $key, isset( $w[ $key ] ) ? $w[ $key ] : '' );
			}
		}
		if ( ! empty( $r['refused'] ) ) {
			return $html . '<div class="lld-calc-stop" data-lld-stop="refused">' . $p::para( $w, 'refused.impossible' ) . '</div>';
		}
		$home     = '7-day' === ( isset( $r['mode'] ) ? $r['mode'] : '' );
		$category = isset( $r['category'] ) && null !== $r['category'] ? (string) $r['category'] : '';
		$html    .= '<div class="lld-calc-headline' . ( '' !== $category ? ' lld-band-' . esc_attr( $category ) : '' ) . '" data-lld-mode="' . esc_attr( $home ? '7-day' : 'single' ) . '" data-lld-category="' . esc_attr( $category ) . '">';
		if ( ! $home ) {
			$html .= '<p class="lld-calc-figure" data-lld-reading="' . esc_attr( $r['systolic'] . '/' . $r['diastolic'] ) . '">' . esc_html( $r['systolic'] . '/' . $r['diastolic'] ) . ' <span class="lld-muted">mmHg</span></p>';
		} elseif ( ! empty( $r['average_shown'] ) && is_array( $r['average'] ) ) {
			$avg   = $r['average']['systolic'] . '/' . $r['average']['diastolic'];
			$html .= '<p class="lld-calc-figure" data-lld-average="' . esc_attr( $avg ) . '">' . esc_html( $avg ) . ' <span class="lld-muted">mmHg</span></p>';
			$html .= '<p class="lld-hint" data-lld-used="' . esc_attr( (string) count( $r['used'] ) ) . '">' . esc_html( sprintf( '7-day average of %1$d %2$s on %3$d days.', count( $r['used'] ), 1 === count( $r['used'] ) ? 'reading' : 'readings', (int) $r['days'] ) ) . '</p>';
		}
		$main = array();
		if ( in_array( 'pregnancy.neutral', $keys, true ) ) {
			$main[] = 'pregnancy.neutral';
		}
		if ( '' !== $category ) {
			$prefix = $home ? 'home.' : 'category.';
			$name   = ( ! $home && 'normal' === $category && in_array( 'category.normal-low.headline', $keys, true ) ) ? 'normal-low' : $category;
			$main[] = $prefix . $name . '.headline';
			$main[] = $prefix . $name . '.body';
		}
		foreach ( $main as $key ) {
			if ( ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$html .= '.headline' === substr( $key, -9 )
				? '<p class="lld-calc-band" data-lld-key="' . esc_attr( $key ) . '"><strong>' . $p::text( $w, $key ) . '</strong></p>'
				: $p::para( $w, $key );
		}
		$html .= '</div>';
		foreach ( self::NOTES as $key ) {
			if ( in_array( $key, $keys, true ) && ! in_array( $key, $main, true ) ) {
				$html .= $p::para( $w, $key, array(), 'lld-calc-note' );
			}
		}
		if ( $home && ! empty( $r['used'] ) ) {
			$html .= '<details class="lld-calc-used"><summary>' . esc_html( 'Readings used' ) . '</summary><ul>';
			foreach ( $r['used'] as $u ) {
				$html .= '<li>' . esc_html( $u['date'] . ': ' . $u['systolic'] . '/' . $u['diastolic'] . ' mmHg' ) . '</li>';
			}
			$html .= '</ul></details>';
		}
		return $html;
	}

	/**
	 * The saved row (P3-5B, sheet section 6): the two numbers and the category. Nothing to save for a refused reading,
	 * in pregnancy (no category, D3-19), or a 7-day check without an average or category.
	 *
	 * @param array $r      Engine result.
	 * @param array $inputs Engine inputs.
	 * @return array|null
	 */
	public static function saved( array $r, array $inputs ) {
		if ( ! empty( $r['refused'] ) || empty( $r['category'] ) ) {
			return null;
		}
		$home = '7-day' === $r['mode'];
		if ( $home && ( empty( $r['average_shown'] ) || ! is_array( $r['average'] ) ) ) {
			return null;
		}
		$sys = $home ? (int) $r['average']['systolic'] : (int) $r['systolic'];
		$dia = $home ? (int) $r['average']['diastolic'] : (int) $r['diastolic'];
		return array(
			'calculator'         => self::SAVED,
			'headline'           => $sys . '/' . $dia,
			'unit'               => 'mmHg',
			'band'               => (string) $r['category'],
			'calculator_version' => (string) $r['version'],
			'result'             => array( 'systolic' => $sys, 'diastolic' => $dia, 'unit' => 'mmHg', 'category' => $r['category'], 'mode' => $r['mode'], 'days' => $r['days'] ),
			'inputs'             => $inputs,
		);
	}

	/**
	 * Method and sources (sheet sections 1, 1.1 and 8), with the engine's signed "guidelines differ" text.
	 */
	public static function about() {
		$w = LLD_Members_BP_Check::wording( array( 'about.guidelines-differ' ) );
		return '<p>Based on the International Society of Hypertension 2020 global guidelines (Unger et al., Hypertension 2020). Version ' . esc_html( LLD_Members_BP_Check::VERSION ) . '.</p>'
			. LLD_Members_Calculator_Pages::para( $w, 'about.guidelines-differ' );
	}
}
