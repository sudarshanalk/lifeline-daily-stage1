<?php
/**
 * Healthy Weight joining questions (P3-35 · FR3-98, FR3-99): the eight quick questions after the weight goal, each with
 * its approved reason line (HW-QW-03 to HW-QW-12, approved 10 Nov 2026, loaded by LLD_Members_HW_Content):
 * reason, barrier, time, work (with the wake-up time only for shifts, nights or changing hours), confidence, sleep,
 * stress and alcohol (with the drinks-a-time tap and its three pictured drinks only for a member who drinks).
 * The alcohol questions, and the "Drinking" barrier, are skipped for a member whose food rules exclude alcohol, and
 * "never" is recorded (FR3-98 AS-08). Every question can be skipped. A question that is not loaded is left out, and
 * with none loaded the member goes straight to the pace.
 *
 * Also the "Quick question" card on Today (FR3-99: one or two a day, R-HW-QOD-01) and the edit screen for the answers
 * (/members/goal/?hw=answers). Answers are saved only through LLD_Members_HW_Answers (P2).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The joining questions.
 */
class LLD_Members_Hw_Questions_Screen {

	/** The eight questions in the approved order, with their two follow-ups. */
	const JOINING = array( 'reason', 'barrier', 'time', 'work', 'wake_time', 'confidence', 'sleep', 'stress', 'alcohol_freq', 'alcohol_amount' );

	/** Follow-ups shown only after some answers: key => [question, values that show it, or !value for "any but"]. */
	const SHOW_WHEN = array(
		'wake_time'      => array( 'work', 'shifts,nights,irregular' ),
		'alcohol_amount' => array( 'alcohol_freq', '!never' ),
	);

	/** Alcohol questions (skipped when the food rules exclude alcohol). */
	const ALCOHOL = array( 'alcohol_freq', 'alcohol_amount' );

	/**
	 * The approved questions to ask now, in order (the loaded ones only).
	 *
	 * @param array $answers Answers.
	 * @return array<string,array> key => norm_question().
	 */
	public static function joining_questions( array $answers ) {
		$out = array();
		$no  = LLD_Members_Hw_Screen_Parts::no_alcohol( $answers );
		foreach ( self::JOINING as $key ) {
			if ( $no && in_array( $key, self::ALCOHOL, true ) ) {
				continue;
			}
			$q = LLD_Members_Hw_Screen_Parts::question( $key );
			if ( $q ) {
				$out[ $key ] = $q;
			}
		}
		if ( isset( $out['alcohol_amount'] ) && ! isset( $out['alcohol_freq'] ) ) {
			unset( $out['alcohol_amount'] ); // the follow-up never stands alone
		}
		return $out;
	}

	/**
	 * The questions form (joining, or editing the answers).
	 *
	 * @param int    $member_id Member number.
	 * @param array  $facts     facts().
	 * @param string $mode      joining|edit.
	 * @param string $action    Form address.
	 * @param string $error     Error text.
	 */
	public static function html( $member_id, array $facts, $mode, $action, $error = '' ) {
		$c       = 'LLD_Members_Hw_Screen_Parts';
		$answers = $c::answers( $member_id );
		$qs      = self::joining_questions( $answers );
		$html    = '<div class="lld-stage3-goal lld-hw" data-lld-goal-step="questions" data-lld-hw-mode="' . esc_attr( $mode ) . '" data-lld-questions="' . count( $qs ) . '">';
		$html   .= '<h1>' . esc_html( $c::LABELS['questions_title'] ) . '</h1><p class="lld-hint">' . esc_html( $c::LABELS['questions_intro'] ) . '</p>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		$html .= '<form method="post" class="lld-form lld-card lld-form-card lld-hw-questions" action="' . esc_url( $action ) . '">' . wp_nonce_field( 'lld_hw_questions', 'lld_hw_questions_nonce', true, false );
		$hide  = $c::no_alcohol( $answers ) ? array( 'alcohol' ) : array();
		foreach ( $qs as $key => $q ) {
			$attrs = array();
			if ( isset( self::SHOW_WHEN[ $key ] ) ) {
				$attrs['data-lld-show-when'] = 'lld_hw[' . self::SHOW_WHEN[ $key ][0] . ']:' . self::SHOW_WHEN[ $key ][1];
			}
			$html .= $c::question_html( $q, isset( $answers[ $key ] ) ? $answers[ $key ] : null, 'lld_hw', $attrs, 'barrier' === $key ? $hide : array() );
		}
		if ( 'edit' === $mode ) {
			$where = LLD_Members_Hw_Where_Screen::fields_html( array( 'ask_background' => false ), $answers, $facts );
			$html .= $where;
		}
		$html .= '<div class="lld-row"><button class="lld-button" type="submit" name="lld_action" value="hw_questions">' . esc_html( $c::LABELS['next'] ) . '</button></div></form></div>';
		return $html;
	}

	/**
	 * Save the posted answers. A question left empty is skipped (not known). The alcohol questions record "never"
	 * for a member whose food rules exclude alcohol; a follow-up is kept only after the answer that shows it.
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function save( $member_id ) {
		$c       = 'LLD_Members_Hw_Screen_Parts';
		$answers = $c::answers( $member_id );
		$posted  = isset( $_POST['lld_hw'] ) && is_array( $_POST['lld_hw'] ) ? wp_unslash( $_POST['lld_hw'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked by the caller; each value is checked below.
		$qs      = self::joining_questions( $answers );
		$values  = array();
		foreach ( $qs as $key => $q ) {
			$v = self::clean( $q, isset( $posted[ $key ] ) ? $posted[ $key ] : null );
			if ( null !== $v ) {
				$values[ $key ] = $v;
			}
			$values = $values + self::follow_up_value( $q, $v, $posted );
		}
		if ( isset( $values['wake_time'] ) && ( ! isset( $values['work'] ) || ! in_array( $values['work'], array( 'shifts', 'nights', 'irregular' ), true ) ) ) {
			unset( $values['wake_time'] );
		}
		if ( isset( $values['alcohol_amount'] ) && ( ! isset( $values['alcohol_freq'] ) || 'never' === $values['alcohol_freq'] ) ) {
			unset( $values['alcohol_amount'] );
		}
		if ( $c::no_alcohol( $answers ) ) {
			$values['alcohol_freq'] = 'never';
			if ( isset( $values['barrier'] ) && 'alcohol' === $values['barrier'] ) {
				unset( $values['barrier'] ); // the "Drinking" choice is not offered to them
			}
		}
		foreach ( $values as $key => $v ) {
			$got = $c::set_answer( $member_id, $key, $v );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
		}
		return true;
	}

	/**
	 * One posted value checked against its approved question, or null (skipped or not valid).
	 *
	 * @param array $q   norm_question().
	 * @param mixed $raw Posted value.
	 * @return mixed
	 */
	public static function clean( array $q, $raw ) {
		if ( null === $raw || '' === $raw || array() === $raw ) {
			return null;
		}
		$values = array_column( $q['choices'], 0 );
		switch ( $q['type'] ) {
			case 'scale':
				$n = is_numeric( $raw ) ? (int) $raw : null;
				return null !== $n && $n >= (int) $q['min'] && $n <= (int) $q['max'] ? $n : null;
			case 'time':
				return is_string( $raw ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $raw ) ? $raw : null;
			case 'date':
				return is_string( $raw ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : null;
			case 'multi':
				$out = array();
				foreach ( (array) $raw as $v ) {
					$v = sanitize_text_field( (string) $v );
					if ( in_array( $v, $values, true ) ) {
						$out[] = $v;
					}
				}
				return $out ? array_values( array_unique( $out ) ) : null;
			default:
				$v = sanitize_text_field( (string) ( is_array( $raw ) ? reset( $raw ) : $raw ) );
				return in_array( $v, $values, true ) ? $v : null;
		}
	}

	/**
	 * Today's "Quick question" card (FR3-99, R-HW-QOD-01): at most two approved questions a day, each with Skip.
	 *
	 * @param array $qod Today view's qod (keys, or {key}).
	 */
	public static function qod_html( array $qod ) {
		$c    = 'LLD_Members_Hw_Screen_Parts';
		$list = array();
		foreach ( $qod as $item ) {
			$key = is_array( $item ) ? ( isset( $item['key'] ) ? (string) $item['key'] : '' ) : (string) $item;
			$q   = '' !== $key ? $c::question( $key ) : null;
			if ( $q ) {
				$list[] = $q;
			}
			if ( count( $list ) >= 2 ) {
				break; // never more than two a day (TC-FR3-99-AS-02)
			}
		}
		if ( ! $list ) {
			return '';
		}
		$html = '<section class="lld-card lld-hw-qod" data-lld-section="hw-qod" data-lld-qod="' . count( $list ) . '"><h2>' . esc_html( $c::LABELS['qod_title'] ) . '</h2>';
		foreach ( $list as $q ) {
			$html .= '<form method="post" class="lld-form lld-hw-qod-form">' . wp_nonce_field( 'lld_hw_qod', 'lld_hw_qod_nonce', true, false ) . '<input type="hidden" name="lld_hw_qod_key" value="' . esc_attr( $q['key'] ) . '">';
			$html .= $c::question_html( $q, null, 'lld_hw' );
			$html .= '<div class="lld-row"><button class="lld-button" type="submit" name="lld_hw_qod_do" value="answer">' . esc_html( $c::LABELS['next'] ) . '</button> <button class="lld-button lld-button-secondary" type="submit" name="lld_hw_qod_do" value="skip" formnovalidate>' . esc_html( $c::LABELS['skip'] ) . '</button></div></form>';
		}
		return $html . '<p class="lld-hint">' . esc_html( $c::LABELS['qod_intro'] ) . '</p></section>';
	}

	/**
	 * Save a quick question's answer, or its skip.
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error|null Null when nothing valid was posted.
	 */
	public static function save_qod( $member_id ) {
		$c   = 'LLD_Members_Hw_Screen_Parts';
		$key = isset( $_POST['lld_hw_qod_key'] ) ? sanitize_key( wp_unslash( $_POST['lld_hw_qod_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		$q   = '' !== $key ? $c::question( $key ) : null;
		if ( ! $q ) {
			return null;
		}
		if ( isset( $_POST['lld_hw_qod_do'] ) && 'skip' === $_POST['lld_hw_qod_do'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( $c::has( $c::ANSWERS, 'skip' ) ) {
				LLD_Members_HW_Answers::skip( (int) $member_id, $key );
				return true;
			}
			return $c::set_answer( $member_id, $key, null );
		}
		$posted = isset( $_POST['lld_hw'] ) && is_array( $_POST['lld_hw'] ) ? wp_unslash( $_POST['lld_hw'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked in clean().
		$v      = self::clean( $q, isset( $posted[ $key ] ) ? $posted[ $key ] : null );
		if ( null === $v ) {
			return null;
		}
		foreach ( array( $key => $v ) + self::follow_up_value( $q, $v, $posted ) as $k => $value ) {
			$got = $c::set_answer( $member_id, $k, $value );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
		}
		return true;
	}

	/**
	 * A question's follow-up answer, kept only after an answer that shows it.
	 *
	 * @param array $q      norm_question().
	 * @param mixed $v      The main answer.
	 * @param array $posted Posted lld_hw values.
	 * @return array key => value (empty when none).
	 */
	private static function follow_up_value( array $q, $v, array $posted ) {
		$f = $q['follow_up'];
		if ( ! $f || null === $v ) {
			return array();
		}
		$have = array_map( 'strval', (array) $v );
		if ( $f['when'] && ! array_intersect( $f['when'], $have ) ) {
			return array();
		}
		$fv = self::clean( $f, isset( $posted[ $f['key'] ] ) ? $posted[ $f['key'] ] : null );
		return null === $fv ? array() : array( $f['key'] => $fv );
	}
}
