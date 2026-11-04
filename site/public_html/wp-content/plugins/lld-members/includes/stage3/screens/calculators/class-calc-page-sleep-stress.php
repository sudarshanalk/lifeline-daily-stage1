<?php
/**
 * Sleep and Stress Check page (P3-5A part 3, FR3-54): /health-tools/sleep-and-stress-check/.
 *
 * The questions and their order follow the signed sheet (P3-5A section 3.2): the self-harm question first, then
 * stress and worry (GAD-2), low mood (PHQ-2) and the four sleep questions, each in the engine's signed words. The
 * result follows section 3.3: a Yes to the self-harm question shows Stage 2 Response 1 alone, with no scores, no
 * track and nothing to save (FR2-38); otherwise the headline, the stress, mood and sleep results with their doctor,
 * insomnia and short-sleep lines, Response 3 for low mood, the help-line footer when the engine asks for it
 * (FR3-54 AS-02) and the track pointer. Every sentence comes from LLD_Members_Sleep_Stress::wording(). Nothing is
 * pre-filled, and the answers are never stored (sheet 3.4): a saved row holds the three scores only.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sleep and stress page.
 */
class LLD_Members_Calc_Page_Sleep_Stress {

	const ENGINE = 'sleep-stress';
	const SAVED  = 'sleep_stress';
	const TITLE  = 'Sleep and stress check';
	const INTRO  = 'A few short questions about stress and worry, mood and sleep. The result shows whether any of them is worth attention and where to find help.';

	/** Engine field => how the error message names it. */
	const FIELDS = array(
		'q0' => 'the first question', 'q1' => 'the question about feeling nervous', 'q2' => 'the question about worrying',
		'q3' => 'the question about interest or pleasure', 'q4' => 'the question about feeling down',
		'q5' => 'the question about trouble sleeping', 'q6' => 'how long the sleep trouble has gone on',
		'q7' => 'the question about how sleep affects your day', 'q8' => 'your hours of sleep',
	);

	/** Track week => what it covers (sheet 3.3). */
	const WEEKS = array( 1 => 'Week 1: sleep basics', 2 => 'Week 2: stress habits', 3 => 'Week 3: sleep routine' );

	/** Page labels (not clinical wording; listed for the owner). */
	const UI = array(
		'q6_hint'   => 'Answer this only if you said Yes to the question above.',
		'q8_hint'   => 'Your usual sleep, from 0 to 16 hours.',
		'track'     => 'In the Sleep and Stress track:',
		'dashboard' => 'Back to my dashboard',
	);

	/**
	 * No pre-fill on this page (the answers are asked fresh every time).
	 *
	 * @param array $ctx Context.
	 */
	public static function defaults( array $ctx ) {
		unset( $ctx );
		return array();
	}

	/**
	 * The signed question texts (the engine's QUESTIONS keys).
	 *
	 * @return array<string,string>
	 */
	private static function questions() {
		return LLD_Members_Sleep_Stress::wording( LLD_Members_Sleep_Stress::QUESTIONS );
	}

	/**
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function form( array $values, array $ctx ) {
		unset( $ctx );
		$p      = 'LLD_Members_Calculator_Pages';
		$q      = self::questions();
		$v      = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) ? (string) $values[ $k ] : '';
		};
		$yes_no = array( 'yes' => 'Yes', 'no' => 'No' );
		$scale  = array();
		for ( $i = 0; $i <= 3; $i++ ) {
			$scale[ (string) $i ] = $q[ 'scale.' . $i ];
		}
		$html  = $p::choice( 'q0', $q['q0'], $yes_no, $v( 'q0' ) );
		$html .= '<p class="lld-calc-stem" data-lld-key="gad2.stem">' . esc_html( ucfirst( $q['gad2.stem'] ) ) . ':</p>';
		foreach ( array( 'q1', 'q2', 'q3', 'q4' ) as $key ) {
			$html .= $p::choice( $key, $q[ $key ], $scale, $v( $key ) );
		}
		$html .= $p::choice( 'q5', $q['q5'], $yes_no, $v( 'q5' ) );
		$html .= $p::choice( 'q6', $q['q6'], $yes_no, $v( 'q6' ), self::UI['q6_hint'] );
		$html .= $p::choice( 'q7', $q['q7'], $yes_no, $v( 'q7' ) );
		$html .= $p::number( 'q8', $q['q8'], $v( 'q8' ), array( 'min' => 0, 'max' => 16, 'step' => '0.5', 'unit' => 'hours', 'hint' => self::UI['q8_hint'] ) );
		return $html;
	}

	/**
	 * The engine's inputs from the answers: yes / no strings, whole numbers 0 to 3, hours as a number. Q6 is passed
	 * only after a Yes to Q5 (the engine ignores it otherwise); anything blank goes as null so the engine names it.
	 *
	 * @param array $values Answers.
	 * @param array $ctx    Context.
	 */
	public static function inputs( array $values, array $ctx ) {
		unset( $ctx );
		$yn = function ( $k ) use ( $values ) {
			return isset( $values[ $k ] ) && '' !== trim( (string) $values[ $k ] ) ? (string) $values[ $k ] : null;
		};
		$in = array( 'q0' => $yn( 'q0' ) );
		foreach ( array( 'q1', 'q2', 'q3', 'q4' ) as $k ) {
			$in[ $k ] = isset( $values[ $k ] ) && preg_match( '/^[0-3]$/', trim( (string) $values[ $k ] ) ) ? (int) trim( (string) $values[ $k ] ) : null;
		}
		$in['q5'] = $yn( 'q5' );
		$in['q6'] = 'yes' === strtolower( (string) $in['q5'] ) ? $yn( 'q6' ) : null;
		$in['q7'] = $yn( 'q7' );
		$in['q8'] = LLD_Members_Calculator_Pages::num( $values, 'q8' );
		return $in;
	}

	/**
	 * @param string $field Engine field.
	 */
	public static function error_text( $field ) {
		return LLD_Members_Calculator_Pages::field_error( $field, self::FIELDS );
	}

	/**
	 * The result in the sheet's order. A Yes to the self-harm question shows Response 1 alone.
	 *
	 * @param array $r   Engine result with `wording`.
	 * @param array $ctx Context.
	 */
	public static function result( array $r, array $ctx ) {
		unset( $ctx );
		$p        = 'LLD_Members_Calculator_Pages';
		$w        = isset( $r['wording'] ) && is_array( $r['wording'] ) ? $r['wording'] : array();
		$messages = isset( $r['messages'] ) && is_array( $r['messages'] ) ? $r['messages'] : array();
		if ( ! empty( $r['response1'] ) ) {
			// FR2-38: Response 1 only, as the never-dismissible alert. No scores, no track, nothing else.
			return self::quoted( 'quoted.response1', isset( $w['quoted.response1'] ) ? $w['quoted.response1'] : '', 'urgent' );
		}
		$html  = '<div class="lld-calc-headline" data-lld-headline="' . esc_attr( (string) $r['headline'] ) . '" data-lld-stress="' . esc_attr( (string) $r['stressScore'] ) . '" data-lld-mood="' . esc_attr( (string) $r['moodScore'] ) . '" data-lld-sleep="' . esc_attr( (string) $r['sleepCount'] ) . '">';
		$html .= '<p class="lld-calc-figure">' . $p::text( $w, (string) $r['headline'] ) . '</p></div>';
		foreach ( $messages as $key ) {
			if ( 'quoted.response3' === $key ) {
				$html .= self::quoted( $key, isset( $w[ $key ] ) ? $w[ $key ] : '', 'caution' );
			} elseif ( 'quoted.helpFooter' === $key ) {
				$html .= self::quoted( $key, isset( $w[ $key ] ) ? $w[ $key ] : '', 'footer' );
			} elseif ( in_array( $key, array( 'result.stress.usual', 'result.stress.attention', 'result.mood.attention', 'result.sleep.none', 'result.sleep.some', 'result.sleep.needs' ), true ) ) {
				$html .= $p::para( $w, $key, array(), 'lld-calc-band' );
			} else {
				$html .= $p::para( $w, $key, array(), 'lld-calc-note' );
			}
		}
		$html .= self::track( $r );
		return $html;
	}

	/**
	 * A quoted Stage 2 block (Response 1, Response 3, the help footer), line by line as the safety file writes it:
	 * **bold** kept as bold, the helpline button line as a button to findahelpline.com, "[Back to my dashboard]" as
	 * its link, and "[Continue]" left out (there is no next step on a calculator page).
	 *
	 * @param string $key  Wording key.
	 * @param string $text Signed text.
	 * @param string $kind urgent, caution or footer.
	 */
	private static function quoted( $key, $text, $kind ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		$html = '';
		foreach ( preg_split( '/\r\n|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '[Continue]' === $line ) {
				continue;
			}
			if ( '[Back to my dashboard]' === $line ) {
				$html .= '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">' . esc_html( self::UI['dashboard'] ) . '</a></p>';
				continue;
			}
			if ( preg_match( '/^\*\*\[([^\]]+)\]\*\* \(button, opens (https:\/\/[^\s)]+)\)$/', $line, $m ) ) {
				$html .= '<p><a class="lld-button lld-button-primary" href="' . esc_url( $m[2] ) . '" data-lld-helpline>' . esc_html( $m[1] ) . '</a></p>';
				continue;
			}
			$line  = esc_html( $line );
			$line  = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line );
			$line  = preg_replace( '/(?<![\/\w.])findahelpline\.com/', '<a href="https://findahelpline.com" data-lld-helpline>findahelpline.com</a>', $line );
			$html .= '<p>' . $line . '</p>';
		}
		if ( 'urgent' === $kind ) {
			return '<div class="lld-safety lld-calc-urgent" role="alert" data-lld-key="' . esc_attr( $key ) . '">' . $html . '</div>';
		}
		if ( 'caution' === $kind ) {
			return '<div class="lld-caution lld-calc-caution" role="status" data-lld-key="' . esc_attr( $key ) . '">' . $html . '</div>';
		}
		return '<div class="lld-help-link lld-calc-helpline" data-lld-key="' . esc_attr( $key ) . '">' . $html . '</div>';
	}

	/**
	 * The track pointer (sheet 3.3): the weeks of the Sleep and Stress track the engine points to.
	 *
	 * @param array $r Engine result.
	 */
	private static function track( array $r ) {
		if ( empty( $r['trackOffer'] ) || empty( $r['trackWeeks'] ) ) {
			return '';
		}
		$items = '';
		foreach ( $r['trackWeeks'] as $week ) {
			if ( isset( self::WEEKS[ (int) $week ] ) ) {
				$items .= '<li>' . esc_html( self::WEEKS[ (int) $week ] ) . '</li>';
			}
		}
		if ( '' === $items ) {
			return '';
		}
		return '<div class="lld-calc-track" data-lld-track="' . esc_attr( implode( ',', array_map( 'intval', $r['trackWeeks'] ) ) ) . '"><p><a href="' . esc_url( home_url( '/programmes/sleep-stress/' ) ) . '">' . esc_html( self::UI['track'] ) . '</a></p><ul>' . $items . '</ul></div>';
	}

	/**
	 * The saved row (P3-5B, sheet 3.4): the three scores only, never the answers, so there is no `inputs` key. The
	 * headline is the engine's signed headline; the band is its level. A Yes to the self-harm question saves nothing.
	 *
	 * @param array $r      Engine result.
	 * @param array $inputs Engine inputs (never saved here).
	 * @return array|null
	 */
	public static function saved( array $r, array $inputs ) {
		unset( $inputs );
		if ( ! empty( $r['response1'] ) || ! isset( $r['toSave'] ) || ! is_array( $r['toSave'] ) || empty( $r['headline'] ) ) {
			return null;
		}
		$text = LLD_Members_Sleep_Stress::wording( array( (string) $r['headline'] ) );
		if ( empty( $text[ $r['headline'] ] ) ) {
			return null;
		}
		$level = in_array( $r['headline'], array( 'result.stress.attention', 'result.mood.attention', 'result.sleep.needs' ), true ) ? 'attention' : ( 'result.sleep.some' === $r['headline'] ? 'some' : 'usual' );
		return array(
			'calculator'         => self::SAVED,
			// Short (the saved headline holds 64 characters): the three scores; the history line shows the signed words.
			'headline'           => (int) $r['toSave']['stressScore'] . '/' . (int) $r['toSave']['moodScore'] . '/' . (int) $r['toSave']['sleepCount'],
			'unit'               => 'scores',
			'band'               => $level,
			'calculator_version' => (string) $r['version'],
			'result'             => array(
				'stressScore' => (int) $r['toSave']['stressScore'],
				'moodScore'   => (int) $r['toSave']['moodScore'],
				'sleepCount'  => (int) $r['toSave']['sleepCount'],
				'headline'    => (string) $r['headline'],
			),
		);
	}

	/**
	 * A Yes to the self-harm question keeps the page quiet: only Response 1, no save, no saved results (FR2-38).
	 *
	 * @param array $r Engine result.
	 */
	public static function quiet( array $r ) {
		return ! empty( $r['response1'] );
	}

	/**
	 * A saved row in the history list: the engine's signed headline for that result.
	 *
	 * @param array $row Saved row (comparable_row lifted the result's fields).
	 */
	public static function history_line( array $row ) {
		$key  = isset( $row['result_detail']['headline'] ) ? (string) $row['result_detail']['headline'] : '';
		$text = '' !== $key ? LLD_Members_Sleep_Stress::wording( array( $key ) ) : array();
		return ! empty( $text[ $key ] ) ? (string) $text[ $key ] : (string) ( $row['headline'] ?? '' );
	}

	/**
	 * Method and sources (sheet sections 3.1 and 3.6).
	 */
	public static function about() {
		return '<p>Stress and worry uses the GAD-2 questions and low mood the PHQ-2 questions (Kroenke, Spitzer and Williams), scored as published. The sleep questions count symptoms from the ICSD-3 and DSM-5 insomnia criteria; they are not a validated test. This check does not diagnose anything. If you save the result, only the three scores and the date are kept, never your answers. Version ' . esc_html( LLD_Members_Sleep_Stress::VERSION ) . '.</p>';
	}
}
