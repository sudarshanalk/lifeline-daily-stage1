<?php
/**
 * Healthy Weight "Where you are now" (P3-35 · FR3-97): BMI, waist-to-height ratio and the healthy range for the
 * member's height, in the Healthy Weight Check's approved wording, from the Check's own engine
 * (LLD_Members_HW_Where::where_now, P1). The background question (D3-54) shows only when the health profile does not
 * already set the bands; the activity level is the approved HW-QW-01 question.
 *
 * Shown on its own at /members/goal/?hw=where and as the first part of the weight goal (LLD_Members_Goal_Setting).
 * A pregnancy, eating-disorder or under-18 answer shows the Check's own stop wording and no number at all
 * (TC-FR3-97-AS-04). The bands are drawn in one neutral style: never red or green.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where you are now.
 */
class LLD_Members_Hw_Where_Screen {

	/**
	 * The member's "where you are now", or null when the Check's engine is missing.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     LLD_Members_Goal_Journey::facts().
	 * @param array $answers   LLD_Members_Hw_Screen_Parts::answers().
	 * @return array|null where_now(), or {stop: null, missing: true} without the measurements it needs.
	 */
	public static function where( $member_id, array $facts, array $answers ) {
		if ( ! LLD_Members_Hw_Screen_Parts::has( 'LLD_Members_HW_Where', 'where_now' ) ) {
			return null;
		}
		$sit = isset( $facts['situations'] ) ? (array) $facts['situations'] : array();
		$age = isset( $facts['age'] ) ? (int) $facts['age'] : 0;
		$eat = isset( $answers['eating_disorder'] ) ? $answers['eating_disorder'] : null; // the Check's own question (S1)
		$in  = array(
			'weight_kg'       => isset( $facts['weight_kg'] ) ? $facts['weight_kg'] : null,
			'height_cm'       => isset( $facts['height_cm'] ) ? $facts['height_cm'] : null,
			'waist_cm'        => self::waist( $member_id, $facts ),
			'age'             => $age > 0 ? $age : null,
			'pregnant'        => in_array( 'pregnancy', $sit, true ) ? 'yes' : 'no',
			'eating_disorder' => in_array( 'eating_disorder', $sit, true ) || 'yes' === $eat ? 'yes' : ( 'prefer' === $eat ? 'pnts' : 'no' ),
			'ethnicity'       => self::ethnicity( $member_id, $facts ),
			'bmi_background'  => isset( $answers['bmi_background'] ) ? $answers['bmi_background'] : null,
		);
		// The stops come first and need no measurement (the Check shows no number with any of them).
		if ( 'yes' === $in['pregnant'] ) {
			return array( 'stop' => 'pregnancy' );
		}
		if ( 'yes' === $in['eating_disorder'] ) {
			return array( 'stop' => 'eating' );
		}
		if ( null !== $in['age'] && $in['age'] < 18 ) {
			return array( 'stop' => 'under18' );
		}
		if ( null === $in['age'] || ! is_numeric( $in['weight_kg'] ) || ! is_numeric( $in['height_cm'] ) || (float) $in['height_cm'] <= 0 ) {
			return array(
				'stop'           => null,
				'missing'        => true,
				'ask_background' => 'ask' === LLD_Members_HW_Where::background_group( $in['ethnicity'], $in['bmi_background'] ),
			);
		}
		$w = LLD_Members_HW_Where::where_now( $in );
		return is_array( $w ) ? $w : null;
	}

	/** The latest waist reading, else the health profile's. */
	private static function waist( $member_id, array $facts ) {
		$w = LLD_Members_Hw_Screen_Parts::call( 'LLD_Members_Goal_Journey', 'latest_value', array( (int) $member_id, 'waist' ) );
		if ( is_numeric( $w ) && (float) $w > 0 ) {
			return (float) $w;
		}
		return isset( $facts['waist_cm'] ) && is_numeric( $facts['waist_cm'] ) ? (float) $facts['waist_cm'] : null;
	}

	/** The Stage 2 health profile's family background (read only, needs health consent). */
	private static function ethnicity( $member_id, array $facts ) {
		if ( empty( $facts['health'] ) || ! class_exists( 'LLD_Members_Schema' ) ) {
			return null;
		}
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT ethnicity FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', (int) $member_id ) );
		return null === $v ? null : (string) $v;
	}

	/**
	 * The result in the Check's wording: a stop, or the BMI with its band, the meaning, the waist ratio and the range.
	 *
	 * @param array|null $where   where().
	 * @param array      $answers Answers (for the "prefer not to say" thresholds line).
	 */
	public static function result_html( $where, array $answers ) {
		$c = 'LLD_Members_Hw_Screen_Parts';
		if ( ! is_array( $where ) ) {
			return '';
		}
		if ( ! empty( $where['stop'] ) ) {
			$stop = (string) $where['stop'];
			$html = '<section class="lld-card lld-hw-stop" data-lld-section="hw-where" data-lld-hw-stop="' . esc_attr( $stop ) . '"><h2>' . esc_html( $c::check( 'stops.' . $stop . '.title' ) ) . '</h2>';
			return $html . '<p>' . $c::linked( $c::check( 'stops.' . $stop . '.body' ) ) . '</p></section>';
		}
		if ( ! empty( $where['missing'] ) || ! isset( $where['bmi'] ) ) {
			return '';
		}
		$cat  = (string) $where['category'];
		$bg   = isset( $answers['bmi_background'] ) ? $answers['bmi_background'] : null;
		$bg   = is_array( $bg ) ? ( isset( $bg['bg'] ) ? $bg['bg'] : null ) : $bg;
		$line = 'NotSay' === $bg ? 'thresholds.notSay' : ( ! empty( $where['adjusted'] ) ? 'thresholds.adjusted' : 'thresholds.standard' );
		$html = '<section class="lld-card lld-hw-where" data-lld-section="hw-where" data-lld-bmi="' . esc_attr( number_format( (float) $where['bmi'], 1, '.', '' ) ) . '" data-lld-band="' . esc_attr( $cat ) . '">';
		$html .= '<h2>' . esc_html( $c::LABELS['where_title'] ) . '</h2>';
		// BMI and its band, in one neutral style (never red or green).
		$html .= '<div class="lld-hw-result" data-lld-result="bmi"><p class="lld-kicker">' . esc_html( $c::check( 'headline.eyebrow' ) ) . '</p>';
		$html .= '<p class="lld-stat"><strong>' . esc_html( number_format( (float) $where['bmi'], 1, '.', '' ) ) . '</strong> ' . esc_html( $c::check( 'headline.unit' ) ) . '</p>';
		$html .= '<p class="lld-hw-band" data-lld-band-name>' . esc_html( $c::check( 'categories.' . $cat ) ) . '</p>';
		$html .= '<p class="lld-hint" data-lld-thresholds>' . esc_html( $c::check( $line ) ) . '</p></div>';
		// What this means, with the Check's notes.
		$key   = 0 === strpos( $cat, 'obese' ) ? 'obese' : $cat;
		$html .= '<div class="lld-hw-result" data-lld-result="meaning"><h3>' . esc_html( $c::check( 'meaning.title' ) ) . '</h3><p>' . esc_html( $c::check( 'meaning.' . $key ) ) . '</p>';
		foreach ( isset( $where['notes'] ) ? (array) $where['notes'] : array() as $note ) {
			if ( 'underweight' === $note ) {
				$html .= '<p class="lld-hint" data-lld-note="underweight">' . $c::linked( $c::check( 'meaning.eatingSupport' ) ) . '</p>';
			} elseif ( '' !== $c::check( 'meaning.' . $note ) ) {
				$html .= '<p class="lld-hint" data-lld-note="' . esc_attr( $note ) . '">' . esc_html( $c::check( 'meaning.' . $note ) ) . '</p>';
			}
		}
		$html .= '</div>';
		// The waist-to-height ratio: the BMI alone until the waist is added (TC-FR3-97-AS-05).
		$waist = isset( $where['waist'] ) && is_array( $where['waist'] ) ? $where['waist'] : null;
		$html .= '<div class="lld-hw-result" data-lld-result="waist" data-lld-waist="' . ( $waist ? 'shown' : 'none' ) . '"><h3>' . esc_html( $c::check( 'waist.title' ) ) . '</h3>';
		if ( ! $waist ) {
			$html .= '<p data-lld-need-waist>' . esc_html( $c::check( 'waist.needWaist' ) ) . '</p>';
		} elseif ( 'notShown' === $waist['band'] ) {
			$html .= '<p>' . esc_html( $c::check( 'waist.bmi35' ) ) . '</p>';
		} else {
			$html .= '<p class="lld-stat" data-lld-whtr><strong>' . esc_html( number_format( (float) $waist['ratio'], 2, '.', '' ) ) . '</strong></p>';
			if ( 'below' === $waist['band'] ) {
				$html .= '<p>' . esc_html( $c::check( 'waist.below' ) ) . '</p>';
			} else {
				$html .= '<p class="lld-hw-band" data-lld-waist-band="' . esc_attr( (string) $waist['band'] ) . '">' . esc_html( $c::check( 'waist.bands.' . $waist['band'] ) ) . '</p><p>' . esc_html( $c::check( 'waist.meaning.' . $waist['band'] ) ) . '</p>';
			}
		}
		$html .= '</div>';
		// The healthy range for the height (T-HW-NUM-02, the Check's own title and note).
		if ( isset( $where['range']['lowKg'], $where['range']['highKg'] ) ) {
			$range = $c::tpl(
				'hw.T-HW-NUM-02',
				array(
					'low'  => $c::kg( $where['range']['lowKg'] ),
					'high' => $c::kg( $where['range']['highKg'] ),
				)
			);
			if ( '' !== $range ) {
				$html .= '<div class="lld-hw-result" data-lld-result="range"><p>' . $c::rich( $range ) . '</p></div>';
			}
		}
		return $html . '</section>';
	}

	/**
	 * The questions that belong to "where you are now": the background (only when needed) and the activity level.
	 *
	 * @param array|null $where   where().
	 * @param array      $answers Answers.
	 * @param array      $facts   facts().
	 */
	public static function fields_html( $where, array $answers, array $facts ) {
		$c    = 'LLD_Members_Hw_Screen_Parts';
		$html = '';
		if ( is_array( $where ) && ! empty( $where['stop'] ) ) {
			return '';
		}
		if ( self::ask_eating( $answers, $facts ) ) {
			// The Healthy Weight Check's own approved question, asked once (the Stage 2 profile holds only yes or no).
			$html .= '<div class="lld-hw-question" data-lld-question="eating_disorder"><fieldset class="lld-options" aria-describedby="lld-hw-eat-why"><legend>' . esc_html( $c::check( 'q.eat' ) ) . '</legend>';
			foreach ( $c::check_options( 'options.eat' ) as $o ) {
				$html .= '<div class="lld-option"><label><input type="radio" name="lld_hw_eat" value="' . esc_attr( $o[0] ) . '"> ' . esc_html( $o[1] ) . '</label></div>';
			}
			$html .= '</fieldset><p class="lld-hint" id="lld-hw-eat-why">' . esc_html( $c::check( 'q.eatWhy' ) ) . '</p></div>';
		}
		if ( is_array( $where ) && ! empty( $where['ask_background'] ) ) {
			// The Check's question and its mixed follow-up (D3-54), with the reason line signed with the method sheet.
			$q     = $c::question( 'bmi_background' );
			$cur   = isset( $answers['bmi_background'] ) ? $answers['bmi_background'] : null;
			$bg    = is_array( $cur ) ? ( isset( $cur['bg'] ) ? (string) $cur['bg'] : '' ) : (string) $cur;
			$mixed = is_array( $cur ) && isset( $cur['mixed'] ) ? (string) $cur['mixed'] : '';
			$why   = $q && '' !== $q['why'] ? $q['why'] : '';
			$html .= '<div class="lld-hw-question" data-lld-question="bmi_background"><fieldset class="lld-options"' . ( '' !== $why ? ' aria-describedby="lld-hw-bg-why"' : '' ) . '><legend>' . esc_html( $c::check( 'q.bg' ) ) . '</legend>';
			foreach ( $c::check_options( 'options.bg' ) as $o ) {
				$html .= '<div class="lld-option"><label><input type="radio" name="lld_hw_bg" value="' . esc_attr( $o[0] ) . '"' . ( $bg === $o[0] ? ' checked' : '' ) . '> ' . esc_html( $o[1] ) . '</label></div>';
			}
			$html .= '</fieldset>' . ( '' !== $why ? '<p class="lld-hint" id="lld-hw-bg-why">' . esc_html( $why ) . '</p>' : '' );
			$html .= '<fieldset class="lld-options" data-lld-show-when="lld_hw_bg:Mixed"><legend>' . esc_html( $c::check( 'q.mixed' ) ) . '</legend>';
			foreach ( $c::check_options( 'options.mixed' ) as $o ) {
				$html .= '<div class="lld-option"><label><input type="radio" name="lld_hw_mixed" value="' . esc_attr( $o[0] ) . '"' . ( $mixed === $o[0] ? ' checked' : '' ) . '> ' . esc_html( $o[1] ) . '</label></div>';
			}
			$html .= '</fieldset></div>';
		}
		$q = $c::question( 'activity_level' );
		if ( $q ) {
			$cur   = isset( $answers['activity_level'] ) ? $answers['activity_level'] : ( isset( $facts['activity'] ) ? $facts['activity'] : null );
			$html .= $c::question_html( $q, $cur );
		}
		return $html;
	}

	/**
	 * The screen on its own (/members/goal/?hw=where): the result, then the questions and the waist, with the Check's
	 * own button.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $facts     facts().
	 * @param string $error     Error text.
	 * @param string $notice    Saved notice.
	 */
	public static function html( $member_id, array $facts, $error = '', $notice = '' ) {
		$c       = 'LLD_Members_Hw_Screen_Parts';
		$answers = $c::answers( $member_id );
		$where   = self::where( $member_id, $facts, $answers );
		$imp     = ! empty( $facts['imperial'] );
		$html    = '<div class="lld-stage3-goal lld-hw" data-lld-goal-step="where"><h1>' . esc_html( $c::LABELS['where_title'] ) . '</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $notice ) {
			$html .= '<div class="lld-notice-box lld-state-saved" role="status"><p>' . esc_html( $notice ) . '</p></div>';
		}
		$html .= self::result_html( $where, $answers );
		if ( is_array( $where ) && ! empty( $where['stop'] ) ) {
			return $html . '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">' . esc_html( $c::LABELS['back'] ) . '</a></p></div>';
		}
		$html .= '<form method="post" class="lld-form lld-card lld-form-card" action="' . esc_url( home_url( '/members/goal/?hw=where' ) ) . '">' . wp_nonce_field( 'lld_hw_where', 'lld_hw_where_nonce', true, false );
		$html .= '<div class="lld-field"><label for="lld_hw_waist">' . esc_html( $c::check( 'q.waist' ) ) . ' (' . ( $imp ? 'in' : 'cm' ) . ')</label><input id="lld_hw_waist" name="lld_hw_waist" type="number" inputmode="decimal" step="any"></div>';
		$html .= self::fields_html( $where, $answers, $facts );
		$html .= '<div class="lld-row"><button class="lld-button" type="submit">' . esc_html( $c::check( 'q.submit' ) ) . '</button></div></form>';
		return $html . '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">' . esc_html( $c::LABELS['back'] ) . '</a></p></div>';
	}

	/**
	 * Save the screen's answers: the waist as a reading, the background and the activity level through P2.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 * @return true|WP_Error
	 */
	public static function save( $member_id, array $facts ) {
		$c   = 'LLD_Members_Hw_Screen_Parts';
		$imp = ! empty( $facts['imperial'] );
		$raw = isset( $_POST['lld_hw_waist'] ) ? str_replace( ',', '.', trim( sanitize_text_field( wp_unslash( $_POST['lld_hw_waist'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		if ( '' !== $raw ) {
			$cm = is_numeric( $raw ) && class_exists( 'LLD_Members_Goal_Rules' ) ? LLD_Members_Goal_Rules::to_cm( (float) $raw, $imp ) : null;
			if ( null === $cm || $cm < 50 || $cm > 200 ) {
				return new WP_Error( 'lld_hw_waist', $c::check( 'errors.waist' ) );
			}
			$c::call( 'LLD_Members_Goal_Journey', 'save_reading', array( (int) $member_id, 'waist', array( 'value_1' => $cm ), $imp ? 'in' : 'cm' ) );
		}
		return self::save_fields( $member_id );
	}

	/**
	 * Whether "where you are now" asks the Check's eating-disorder question: P2 is there, the Stage 2 profile does not
	 * flag an eating disorder, and the question is not answered yet.
	 *
	 * @param array $answers Answers.
	 * @param array $facts   facts().
	 */
	public static function ask_eating( array $answers, array $facts ) {
		$sit = isset( $facts['situations'] ) ? (array) $facts['situations'] : array();
		return LLD_Members_Hw_Screen_Parts::has( LLD_Members_Hw_Screen_Parts::ANSWERS, 'set' ) && ! in_array( 'eating_disorder', $sit, true ) && ( ! isset( $answers['eating_disorder'] ) || null === $answers['eating_disorder'] );
	}

	/**
	 * Save the posted eating-disorder answer (yes, no or prefer not to say) as a sealed programme answer. Nothing when
	 * none was chosen.
	 *
	 * @param int $member_id Member number.
	 * @return string|null|WP_Error The answer saved, null when none was posted.
	 */
	public static function save_eating( $member_id ) {
		$c   = 'LLD_Members_Hw_Screen_Parts';
		$eat = isset( $_POST['lld_hw_eat'] ) ? sanitize_key( wp_unslash( $_POST['lld_hw_eat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		if ( '' === $eat || ! in_array( $eat, array_column( $c::check_options( 'options.eat' ), 0 ), true ) ) {
			return null;
		}
		$got = $c::set_answer( $member_id, 'eating_disorder', $eat );
		return is_wp_error( $got ) ? $got : $eat;
	}

	/**
	 * Save the background and activity answers posted with fields_html().
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function save_fields( $member_id ) {
		$c   = 'LLD_Members_Hw_Screen_Parts';
		$got = self::save_eating( $member_id );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$bg = isset( $_POST['lld_hw_bg'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_hw_bg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		if ( '' !== $bg && in_array( $bg, array_column( $c::check_options( 'options.bg' ), 0 ), true ) ) {
			$mixed = isset( $_POST['lld_hw_mixed'] ) ? sanitize_key( wp_unslash( $_POST['lld_hw_mixed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$value = 'Mixed' === $bg ? array( 'bg' => 'Mixed', 'mixed' => in_array( $mixed, array( 'yes', 'no', 'notsure' ), true ) ? $mixed : null ) : $bg;
			$got   = $c::set_answer( $member_id, 'bmi_background', $value );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
		}
		$q   = $c::question( 'activity_level' );
		$act = isset( $_POST['lld_hw']['activity_level'] ) ? sanitize_key( wp_unslash( $_POST['lld_hw']['activity_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $q && '' !== $act && in_array( $act, array_column( $q['choices'], 0 ), true ) ) {
			$got = $c::set_answer( $member_id, 'activity_level', $act );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
		}
		return true;
	}
}
