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
			'eating_disorder' => isset( $facts['member_id'] ) && 'prefer' === LLD_Members_Hw_Screen_Parts::answer( (int) $facts['member_id'], 'eating_disorder' ) ? 'pnts' : 'no',
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
	 * Step 4: the pace and the milestones, with the paces to choose from or a date instead (FR3-100: "or pick a date
	 * instead"; the signed P3-56 timeline checks it).
	 *
	 * @param array           $view   plan_view().
	 * @param array           $facts  facts().
	 * @param string          $action Form address.
	 * @param string|WP_Error $error  Error text, or the refusal from save_pace() (data: code, date, by_date).
	 */
	public static function pace_html( array $view, array $facts, $action, $error = '' ) {
		$c       = 'LLD_Members_Hw_Screen_Parts';
		if ( $c::stopped( $view ) ) {
			// R-HW-SAFE-01 at any time (pregnancy or an eating disorder): the SCR-26 stop and no number at all.
			return '<div class="lld-stage3-goal lld-hw" data-lld-goal-step="pace"><h1>' . esc_html( $c::LABELS['pace_title'] ) . '</h1>' . $c::stop_html( $view['stop'] ) . '</div>';
		}
		$pace    = isset( $view['pace'] ) && is_array( $view['pace'] ) ? $view['pace'] : array();
		$lines   = self::pace_lines( $pace );
		$chosen  = isset( $pace['chosen'] ) ? (float) $pace['chosen'] : ( isset( $pace['recommended'] ) ? (float) $pace['recommended'] : 0.5 );
		$rec     = isset( $pace['recommended'] ) ? (float) $pace['recommended'] : null;
		$imp     = ! empty( $facts['imperial'] );
		$data    = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
		$message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
		$by_date = ! empty( $data['by_date'] );
		$date    = ! empty( $data['date'] ) ? (string) $data['date'] : ( isset( $_POST['lld_date'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_date'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- the posted form shown again.
		$html    = '<div class="lld-stage3-goal lld-hw" data-lld-goal-step="pace"><h1>' . esc_html( $c::LABELS['pace_title'] ) . '</h1>';
		if ( '' !== $message ) {
			$html .= '<div class="lld-error-box" role="alert" data-lld-refused="' . esc_attr( isset( $data['code'] ) ? (string) $data['code'] : 'error' ) . '"><p>' . esc_html( $message ) . '</p>';
			if ( ! empty( $data['date'] ) ) {
				$html .= '<p class="lld-hint">The suggestion is filled in below.</p>';
			}
			$html .= '</div>';
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
			$html .= '<div class="lld-option"><label><input type="radio" name="lld_hw_pace" value="' . esc_attr( (string) $p ) . '"' . ( ! $by_date && abs( $p - $chosen ) < 0.001 ? ' checked' : '' ) . '> ' . esc_html( LLD_Members_Goal_Setting::rate_label( $p, $imp ) ) . ( null !== $rec && abs( $p - $rec ) < 0.001 ? ' <span class="lld-pill" data-lld-suggested>' . esc_html( $c::LABELS['suggested'] ) . '</span>' : '' ) . '</label></div>';
		}
		// The goal screen's own "By a date instead" choice and date field (P3-31, signed P3-56), now on the pace step.
		$html .= '<div class="lld-option"><label><input type="radio" name="lld_hw_pace" value="date"' . ( $by_date ? ' checked' : '' ) . '> By a date instead</label></div>';
		$html .= '</fieldset>';
		$html .= '<div class="lld-field"><label for="lld_date">Date to reach it (optional)</label><input id="lld_date" name="lld_date" type="date" value="' . esc_attr( $date ) . '"></div>';
		$html .= '<div class="lld-row"><button class="lld-button" type="submit" name="lld_action" value="hw_pace">' . esc_html( $c::LABELS['next'] ) . '</button></div></form></section>';
		$ms    = isset( $view['milestones'] ) && is_array( $view['milestones'] ) && ! $c::ed( $facts ) ? self::milestones_html( $view['milestones'] ) : '';
		if ( '' !== $ms ) {
			$html .= '<section class="lld-card" data-lld-section="hw-milestones"><h2>' . esc_html( $c::LABELS['milestones_title'] ) . '</h2>' . $ms . '</section>';
		}
		return $html . '</div>';
	}

	/**
	 * Check and save the chosen pace, or the date instead, through P2. A pace above the safe limit, or a date that would
	 * need one, is refused with the signed P3-56 message and the earliest safe date (R-HW-SAFE-02), and nothing changes.
	 *
	 * @param int        $member_id Member number.
	 * @param array      $view      plan_view().
	 * @param array      $facts     facts().
	 * @param string     $today     Y-m-d.
	 * @param array|null $goal      The weight-loss goal row (start_value, target_value), for the refusal.
	 * @return true|WP_Error Refusals carry data {code, date (the safe date), by_date}.
	 */
	public static function save_pace( $member_id, array $view, array $facts, $today, $goal = null ) {
		$raw = isset( $_POST['lld_hw_pace'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_hw_pace'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		if ( 'date' === $raw ) {
			$date = isset( $_POST['lld_date'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return new WP_Error( 'lld_hw_pace', 'Choose a date in the future.', array( 'code' => 'invalid', 'by_date' => true ) );
			}
			$got = LLD_Members_Hw_Screen_Parts::call( LLD_Members_Hw_Screen_Parts::PROGRAMME, 'join', array( (int) $member_id, array( 'target_date' => $date, 'today' => (string) $today ) ), false );
			if ( is_wp_error( $got ) ) {
				$d = (array) $got->get_error_data();
				if ( isset( $d['code'] ) && 'rate_cap' === $d['code'] ) {
					return self::refused( $view, $facts, $today, $goal, true );
				}
				return new WP_Error( 'lld_hw_pace', $got->get_error_message(), array( 'code' => isset( $d['code'] ) ? (string) $d['code'] : 'error', 'by_date' => true ) );
			}
			return true;
		}
		$want = is_numeric( $raw ) ? (float) $raw : null;
		$ok   = false;
		foreach ( self::paces( $view, $facts ) as $p ) {
			$ok = $ok || ( null !== $want && abs( $p - $want ) < 0.001 );
		}
		if ( ! $ok ) {
			return self::refused( $view, $facts, $today, $goal, false );
		}
		$got = LLD_Members_Hw_Screen_Parts::call( LLD_Members_Hw_Screen_Parts::PROGRAMME, 'join', array( (int) $member_id, array( 'pace' => $want, 'today' => (string) $today ) ), false );
		if ( is_wp_error( $got ) && 'lld_hw_pace' === $got->get_error_code() ) {
			return self::refused( $view, $facts, $today, $goal, false );
		}
		return is_wp_error( $got ) ? $got : true;
	}

	/**
	 * A rate-cap refusal as a WP_Error with its code and the safe date.
	 *
	 * @param array      $view    plan_view().
	 * @param array      $facts   facts().
	 * @param string     $today   Y-m-d.
	 * @param array|null $goal    Goal row.
	 * @param bool       $by_date The member chose a date.
	 */
	private static function refused( array $view, array $facts, $today, $goal, $by_date ) {
		$r = self::refusal( $view, $facts, $today, $goal );
		return new WP_Error( 'lld_hw_pace', $r['text'], array( 'code' => 'rate_cap', 'date' => $r['date'], 'by_date' => $by_date ) );
	}

	/**
	 * The signed P3-56 rate-cap refusal (LLD_Members_Goal_Timeline::MESSAGES['refusals.rate-cap']): {cap} is the
	 * member's safe rate (LLD_Members_Goal_Timeline::rate_cap: 1 % of the starting weight, at most 1 kg, 0.5 kg from 65)
	 * and {safe date} the date the goal is reached at that rate (the signed weeks, rounded up), as the goal screen
	 * printed it. Text '' when the goal's weights are not known.
	 *
	 * @param array      $view  plan_view().
	 * @param array      $facts facts().
	 * @param string     $today Y-m-d.
	 * @param array|null $goal  The goal row (start_value, target_value).
	 * @return array{text:string,date:string,cap:float|null}
	 */
	public static function refusal( array $view, array $facts, $today, $goal ) {
		$none = array(
			'text' => '',
			'date' => '',
			'cap'  => null,
		);
		if ( ! class_exists( 'LLD_Members_Goal_Timeline' ) || ! isset( LLD_Members_Goal_Timeline::MESSAGES['refusals.rate-cap'] ) || ! is_array( $goal ) || ! isset( $goal['start_value'], $goal['target_value'] ) || ! is_numeric( $goal['start_value'] ) || ! is_numeric( $goal['target_value'] ) ) {
			return $none;
		}
		$start  = (float) $goal['start_value'];
		$target = (float) $goal['target_value'];
		$over65 = in_array( 'age_65_plus', isset( $facts['situations'] ) ? (array) $facts['situations'] : array(), true );
		$cap    = LLD_Members_Goal_Timeline::rate_cap( $start, $over65 );
		// Eating disorder "prefer not to say" (the Check's own question): 0.25 or 0.5 kg a week, so the cap is 0.5 (HWS-01b).
		if ( isset( $facts['member_id'] ) && 'prefer' === LLD_Members_Hw_Screen_Parts::answer( (int) $facts['member_id'], 'eating_disorder' ) ) {
			$cap = min( $cap, 0.5 );
		}
		$d      = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $today, new DateTimeZone( 'UTC' ) );
		if ( ! $d || $cap <= 0 ) {
			return $none;
		}
		$weeks = LLD_Members_Goal_Timeline::weeks_for( $start - $target, $cap );
		$date  = $d->modify( '+' . ( 7 * (int) $weeks ) . ' days' )->format( 'Y-m-d' );
		$imp   = ! empty( $facts['imperial'] );
		$show  = $imp ? LLD_Members_Goal_Rules::plain( LLD_Members_Goal_Rules::round1( $target / 0.45359237 ) ) . ' lb' : LLD_Members_Goal_Rules::plain( $target ) . ' kg';
		return array(
			'text' => str_replace( array( '{cap}', '{goal}', '{safe date}' ), array( LLD_Members_Goal_Rules::plain( $cap ), $show, LLD_Members_Goal_Rules::nice_date( $date ) ), LLD_Members_Goal_Timeline::MESSAGES['refusals.rate-cap'] ),
			'date' => $date,
			'cap'  => $cap,
		);
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
		if ( $c::stopped( $view ) ) {
			return $c::stop_html( $view['stop'] ); // the SCR-26 stop: no target, milestones, drinks line or reasons
		}
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
