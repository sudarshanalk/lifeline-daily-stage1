<?php
/**
 * Healthy Weight pace and plan (P3-35 · FR3-100 to FR3-103, FR3-109, FR3-110), from LLD_Members_HW_Programme::plan_view
 * (P2):
 * - the pace (T-HW-PACE-01: the heading for the suggested pace and "Because …"), the paces the member may choose
 *   (never above the safe limit, R-HW-SAFE-02) and the milestones (T-HW-NUM-04);
 * - "Here's your plan": the daily calorie target with the signed P3-55 "Why this number" line
 *   (TC-FR3-102-AS-04), and the drinks line (T-HW-NUM-05);
 * - "Why your plan looks like this": the engine's reasons, in their signed wording;
 * - the free support cards (T-HW-SAFE-03 and -05), with nothing about weight or calories beside the drinking card.
 * With an eating-disorder flag no calorie number is shown anywhere (TC-FR3-102-AS-08).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pace and plan.
 */
class LLD_Members_Hw_Plan_Screen {

	/** Pace headings of T-HW-PACE-01, by pace. */
	const STEPS = array(
		'0.25' => 'gentle',
		'0.5'  => 'steady',
		'0.75' => 'faster',
	);

	/**
	 * The paces the member may choose: the view's, else the signed limits (P1 allowed_paces).
	 *
	 * @param array $view  plan_view().
	 * @param array $facts facts().
	 * @return float[]
	 */
	public static function paces( array $view, array $facts ) {
		if ( isset( $view['pace']['paces'] ) && is_array( $view['pace']['paces'] ) && $view['pace']['paces'] ) {
			return array_map( 'floatval', $view['pace']['paces'] );
		}
		$in = array(
			'weight_kg'       => isset( $facts['weight_kg'] ) ? $facts['weight_kg'] : null,
			'age'             => isset( $facts['age'] ) && (int) $facts['age'] > 0 ? (int) $facts['age'] : null,
			'eating_disorder' => LLD_Members_Hw_Screen_Parts::ed( $facts ) ? 'pnts' : 'no',
		);
		$p = LLD_Members_Hw_Screen_Parts::call( 'LLD_Members_HW_Rules', 'allowed_paces', array( $in ), array( 0.25 ) );
		return array_map( 'floatval', (array) $p );
	}

	/**
	 * The pace heading and its "Because …" line.
	 *
	 * @param array $pace plan_view()['pace'].
	 * @return array{heading:string,because:string}
	 */
	public static function pace_lines( array $pace ) {
		$c   = 'LLD_Members_Hw_Screen_Parts';
		$rec = isset( $pace['recommended'] ) ? (string) (float) $pace['recommended'] : '';
		$key = isset( self::STEPS[ $rec ] ) ? self::STEPS[ $rec ] : ( isset( $pace['step'] ) ? (string) $pace['step'] : '' );
		$head = '' !== $key ? $c::tpl( 'hw.T-HW-PACE-01.heading.' . $key ) : '';
		$phrases = array();
		foreach ( isset( $pace['reasons'] ) ? array_slice( (array) $pace['reasons'], 0, 2 ) : array() as $r ) {
			$t = $c::text_of( $r );
			if ( '' === $t && is_string( $r ) ) {
				$t = $c::tpl( 0 === strpos( $r, 'hw.' ) ? $r : 'hw.T-HW-PACE-01.reason.' . $r );
			}
			if ( '' !== $t ) {
				$phrases[] = $t;
			}
		}
		$because = isset( $pace['because'] ) && is_string( $pace['because'] ) ? $pace['because'] : ( $phrases ? $c::tpl( 'hw.T-HW-PACE-01', array( 'reasons' => implode( ' and ', $phrases ) ) ) : '' );
		return array(
			'heading' => $head,
			'because' => $because,
		);
	}

	/**
	 * The milestones list (T-HW-NUM-04): "**60.0 kg** · Your goal · around 18 December 2026".
	 *
	 * @param array $milestones plan_view()['milestones'].
	 */
	public static function milestones_html( array $milestones ) {
		$c     = 'LLD_Members_Hw_Screen_Parts';
		$items = '';
		foreach ( $milestones as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['kind'], $m['kg'] ) ) {
				continue;
			}
			$label = $c::tpl( 'hw.T-HW-NUM-04.label.' . $m['kind'] );
			$line  = '' !== $label ? $c::tpl(
				'hw.T-HW-NUM-04',
				array(
					'kg'    => $c::kg( $m['kg'] ),
					'label' => $label,
					'date'  => isset( $m['date'] ) ? $c::date( $m['date'] ) : '',
				)
			) : '';
			if ( '' !== $line ) {
				$items .= '<li data-lld-milestone="' . esc_attr( (string) $m['kind'] ) . '">' . $c::rich( $line ) . '</li>';
			}
		}
		return '' === $items ? '' : '<ol class="lld-hw-milestones">' . $items . '</ol>';
	}

	/**
	 * Step 4: the pace and the milestones, with the paces to choose from.
	 *
	 * @param array  $view   plan_view().
	 * @param array  $facts  facts().
	 * @param string $action Form address.
	 * @param string $error  Error text.
	 */
	public static function pace_html( array $view, array $facts, $action, $error = '' ) {
		$c      = 'LLD_Members_Hw_Screen_Parts';
		$pace   = isset( $view['pace'] ) && is_array( $view['pace'] ) ? $view['pace'] : array();
		$lines  = self::pace_lines( $pace );
		$chosen = isset( $pace['chosen'] ) ? (float) $pace['chosen'] : ( isset( $pace['recommended'] ) ? (float) $pace['recommended'] : 0.5 );
		$rec    = isset( $pace['recommended'] ) ? (float) $pace['recommended'] : null;
		$imp    = ! empty( $facts['imperial'] );
		$html   = '<div class="lld-stage3-goal lld-hw" data-lld-goal-step="pace"><h1>' . esc_html( $c::LABELS['pace_title'] ) . '</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		$html .= '<section class="lld-card" data-lld-section="hw-pace" data-lld-pace-recommended="' . esc_attr( null === $rec ? '' : (string) $rec ) . '">';
		if ( '' !== $lines['heading'] ) {
			$html .= '<h2>' . esc_html( $lines['heading'] ) . '</h2>';
		}
		if ( '' !== $lines['because'] ) {
			$html .= '<p data-lld-pace-because>' . $c::rich( $lines['because'] ) . '</p>';
		}
		$html .= '<form method="post" class="lld-form" action="' . esc_url( $action ) . '">' . wp_nonce_field( 'lld_hw_pace', 'lld_hw_pace_nonce', true, false );
		$html .= '<fieldset class="lld-options"><legend class="lld-sr">' . esc_html( $c::LABELS['pace_title'] ) . '</legend>';
		foreach ( self::paces( $view, $facts ) as $p ) {
			$html .= '<div class="lld-option"><label><input type="radio" name="lld_hw_pace" value="' . esc_attr( (string) $p ) . '"' . ( abs( $p - $chosen ) < 0.001 ? ' checked' : '' ) . '> ' . esc_html( LLD_Members_Goal_Setting::rate_label( $p, $imp ) ) . ( null !== $rec && abs( $p - $rec ) < 0.001 ? ' <span class="lld-pill" data-lld-suggested>' . esc_html( $c::LABELS['suggested'] ) . '</span>' : '' ) . '</label></div>';
		}
		$html .= '</fieldset>';
		$html .= '<div class="lld-row"><button class="lld-button" type="submit" name="lld_action" value="hw_pace">' . esc_html( $c::LABELS['next'] ) . '</button></div></form></section>';
		$ms    = isset( $view['milestones'] ) && is_array( $view['milestones'] ) && ! $c::ed( $facts ) ? self::milestones_html( $view['milestones'] ) : '';
		if ( '' !== $ms ) {
			$html .= '<section class="lld-card" data-lld-section="hw-milestones"><h2>' . esc_html( $c::LABELS['milestones_title'] ) . '</h2>' . $ms . '</section>';
		}
		return $html . '</div>';
	}

	/**
	 * Check and save the chosen pace through P2. A pace above the safe limit is refused (R-HW-SAFE-02).
	 *
	 * @param int    $member_id Member number.
	 * @param array  $view      plan_view().
	 * @param array  $facts     facts().
	 * @param string $today     Y-m-d.
	 * @return true|WP_Error
	 */
	public static function save_pace( $member_id, array $view, array $facts, $today ) {
		$raw  = isset( $_POST['lld_hw_pace'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_hw_pace'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		$want = is_numeric( $raw ) ? (float) $raw : null;
		$ok   = false;
		foreach ( self::paces( $view, $facts ) as $p ) {
			$ok = $ok || ( null !== $want && abs( $p - $want ) < 0.001 );
		}
		if ( ! $ok ) {
			return new WP_Error( 'lld_hw_pace', self::refusal( $view, $facts ) );
		}
		$got = LLD_Members_Hw_Screen_Parts::call( LLD_Members_Hw_Screen_Parts::PROGRAMME, 'join', array( (int) $member_id, array( 'pace' => $want, 'today' => (string) $today ) ), false );
		return is_wp_error( $got ) ? $got : true;
	}

	/**
	 * The signed P3-56 refusal for a pace above the safe limit, filled with the cap and the goal's safe date; '' when the
	 * goal milestone is not known.
	 *
	 * @param array $view  plan_view().
	 * @param array $facts facts().
	 */
	public static function refusal( array $view, array $facts ) {
		if ( ! class_exists( 'LLD_Members_Goal_Timeline' ) || ! defined( 'LLD_Members_Goal_Timeline::MESSAGES' ) || ! isset( LLD_Members_Goal_Timeline::MESSAGES['refusals.rate-cap'] ) ) {
			return '';
		}
		$goal = null;
		foreach ( isset( $view['milestones'] ) ? (array) $view['milestones'] : array() as $m ) {
			if ( is_array( $m ) && isset( $m['kind'], $m['kg'], $m['date'] ) && 'goal' === $m['kind'] ) {
				$goal = $m;
			}
		}
		$paces = self::paces( $view, $facts );
		if ( ! $goal || ! $paces ) {
			return '';
		}
		$cap = rtrim( rtrim( number_format( max( $paces ), 2, '.', '' ), '0' ), '.' );
		return str_replace( array( '{cap}', '{goal}', '{safe date}' ), array( $cap, LLD_Members_Hw_Screen_Parts::kg( $goal['kg'] ) . ' kg', LLD_Members_Hw_Screen_Parts::date( $goal['date'] ) ), LLD_Members_Goal_Timeline::MESSAGES['refusals.rate-cap'] );
	}

	/**
	 * Step 5: "Here's your plan", "Why your plan looks like this", the support cards and the drinks line. Inserted at
	 * the top of the goal's plan page.
	 *
	 * @param array $view  plan_view().
	 * @param array $facts facts().
	 */
	public static function plan_html( array $view, array $facts ) {
		$c     = 'LLD_Members_Hw_Screen_Parts';
		$ed    = $c::ed( $facts );
		$cards = isset( $view['cards'] ) && is_array( $view['cards'] ) ? $view['cards'] : array();
		$drink = false; // the drinking card: nothing about weight or calories beside it (R-HW-SAFE-05)
		foreach ( $cards as $card ) {
			$t     = is_array( $card ) && isset( $card['template'] ) ? (string) $card['template'] : (string) ( is_string( $card ) ? $card : '' );
			$drink = $drink || false !== strpos( $t, 'T-HW-SAFE-05' );
		}
		$html = '<section class="lld-card lld-hw-plan" data-lld-section="hw-plan"><h2>' . esc_html( $c::LABELS['plan_title'] ) . '</h2>';
		// The daily calorie target (R-HW-NUM-03) with the signed P3-55 wording: never with an eating-disorder flag.
		if ( ! $ed && ! $drink && isset( $view['target_kcal'] ) && is_numeric( $view['target_kcal'] ) && class_exists( 'LLD_Members_Calorie_Target' ) ) {
			$m     = LLD_Members_Calorie_Target::MESSAGES;
			$html .= '<p class="lld-stat" data-lld-kcal="' . (int) $view['target_kcal'] . '">' . $c::rich( str_replace( '{target}', $c::kcal( $view['target_kcal'] ), $m['headline'] ) ) . '</p>';
			if ( isset( $view['tdee'] ) && is_numeric( $view['tdee'] ) ) {
				$html .= '<p data-lld-why-number>' . esc_html( self::why_number( $m['why_this_number'], $view ) ) . '</p>';
			}
			// The drinks in the calorie picture (T-HW-NUM-05).
			if ( isset( $view['drinks'] ) && is_array( $view['drinks'] ) && isset( $view['drinks']['weekly'], $view['drinks']['daily'] ) ) {
				$line = $c::tpl(
					'hw.T-HW-NUM-05',
					array(
						'target' => $c::kcal( $view['target_kcal'] ),
						'weekly' => $c::kcal( $view['drinks']['weekly'] ),
						'daily'  => $c::kcal( $view['drinks']['daily'] ),
					)
				);
				if ( '' !== $line ) {
					$html .= '<p data-lld-drinks-line>' . $c::rich( $line ) . '</p>';
				}
			}
		}
		$ms = isset( $view['milestones'] ) && is_array( $view['milestones'] ) && ! $ed && ! $drink ? self::milestones_html( $view['milestones'] ) : '';
		if ( '' !== $ms ) {
			$html .= '<h3>' . esc_html( $c::LABELS['milestones_title'] ) . '</h3>' . $ms;
		}
		$html .= '</section>';
		// Why your plan looks like this: each reason in its signed wording, as the engine gives it.
		$why = '';
		foreach ( isset( $view['reasons'] ) && is_array( $view['reasons'] ) ? $view['reasons'] : array() as $r ) {
			$t = $c::text_of( $r );
			if ( '' === $t || ( ( $ed || $drink ) && preg_match( '/\bkcal\b|calorie|\bkg\b/i', $t ) ) ) {
				continue;
			}
			$label = is_array( $r ) && isset( $r['label'] ) && is_string( $r['label'] ) ? $r['label'] : '';
			$why  .= '<li data-lld-reason="' . esc_attr( is_array( $r ) && isset( $r['rule'] ) ? (string) $r['rule'] : '' ) . '">' . ( '' !== $label ? '<strong>' . esc_html( $label ) . '</strong> ' : '' ) . $c::rich( $t ) . '</li>';
		}
		if ( '' !== $why ) {
			$html .= '<section class="lld-card" data-lld-section="hw-why"><h2>' . esc_html( $c::LABELS['why_title'] ) . '</h2><ul class="lld-hw-why">' . $why . '</ul></section>';
		}
		return $html . $c::cards_html( $cards );
	}

	/**
	 * The signed "Why this number" line: "Your body uses about {tdee} kcal a day at your activity level.", with its
	 * optional deficit sentence when the deficit and pace are known.
	 *
	 * @param string $template LLD_Members_Calorie_Target::MESSAGES['why_this_number'].
	 * @param array  $view     plan_view().
	 */
	public static function why_number( $template, array $view ) {
		$tdee = (int) ( floor( (float) $view['tdee'] / 10 + 0.5 ) * 10 );
		$rate = isset( $view['pace']['chosen'] ) && is_numeric( $view['pace']['chosen'] ) ? (float) $view['pace']['chosen'] : null;
		$text = str_replace( '{tdee}', LLD_Members_Hw_Screen_Parts::kcal( $tdee ), (string) $template );
		if ( null !== $rate && $rate > 0 ) {
			$text = str_replace( array( '{deficit}', '{rate}' ), array( LLD_Members_Hw_Screen_Parts::kcal( round( 1100 * $rate ) ), rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' ) ), $text );
			$text = (string) preg_replace( '/\{([^{}]*)\}/', '$1', $text );
		} else {
			$text = (string) preg_replace( '/\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', '', $text );
		}
		return trim( $text );
	}
}
