<?php
/**
 * Healthy Weight programme screens (P3-35, package P4): the parts every Healthy Weight screen shares.
 *
 * Member wording comes only from approved sources:
 * - the signed T-HW templates, through LLD_Members_Rule_Catalogue::text() and ::fill() (P1);
 * - the Healthy Weight Check's approved copy (FR2-39 AS-19), quoted in CHECK_COPY below word for word from
 *   copy/healthy-weight-messages.json (the source test compares every key, so the two cannot drift);
 * - the approved questions, notes and focus blocks, through LLD_Members_HW_Content (P3);
 * - the screen titles and button labels of the approved prototype and the approved question wording (LABELS).
 * When an approved item is not loaded, the screen leaves it out; nothing is filled in by hand.
 *
 * The member's data comes from LLD_Members_HW_Programme and LLD_Members_HW_Answers (P2). Every call is guarded with
 * class_exists and method_exists, so the screens render safely, and unchanged, while either package is missing.
 * This class has no boot(): it registers nothing.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared Healthy Weight screen parts.
 */
class LLD_Members_Hw_Screen_Parts {

	const HANDLE        = 'lld-members-stage3';
	const PROGRAMME     = 'LLD_Members_HW_Programme';
	const ANSWERS       = 'LLD_Members_HW_Answers';
	const CONTENT       = 'LLD_Members_HW_Content';
	const HELPLINE      = 'https://findahelpline.com';
	const SLEEP_CHECK   = '/health-tools/sleep-and-stress-check/';
	const SLEEP_SCREEN  = '/members/screening/?track=sleep-stress';

	/**
	 * The Healthy Weight Check's approved copy (copy/healthy-weight-messages.json, owner approval FR2-39 AS-19), word for
	 * word. Only the keys these screens show. The two HTML values carry the Check's own findahelpline.com link.
	 */
	const CHECK_COPY = array(
		'q.height'                => 'Height',
		'q.weight'                => 'Weight',
		'q.waist'                 => 'Waist (optional)',
		'q.bg'                    => 'Your family background',
		'q.mixed'                 => 'Does your family background include any of the groups above?',
		'q.eat'                   => 'Do you have, or have you had, an eating disorder, or are you worried about your eating?',
		'q.eatWhy'                => 'We ask because numbers about weight are not helpful for everyone. Your answer stays on this page.',
		'options.eat'             => '[["yes","Yes"],["no","No"],["prefer","Prefer not to say"]]',
		'errors.eat'              => 'Please choose an answer.',
		'q.submit'                => 'See my result',
		'options.bg'              => '[["White","White"],["Indian","Indian"],["Pakistani","Pakistani"],["Bangladeshi","Bangladeshi"],["OtherSouthAsian","Other South Asian"],["Chinese","Chinese"],["OtherAsian","Other Asian"],["MiddleEastern","Middle Eastern"],["BlackAfrican","Black African"],["BlackCaribbean","Black Caribbean (African-Caribbean)"],["Mixed","Mixed or other"],["NotSay","Prefer not to say"]]',
		'options.mixed'           => '[["yes","Yes"],["no","No"],["notsure","Not sure"]]',
		'errors.waist'            => 'Enter a waist between 50 cm and 200 cm (19.7 in to 78.7 in), or leave it empty.',
		'stops.under18.title'     => 'This check is for adults',
		'stops.under18.body'      => 'BMI works differently for children and teenagers, who need age-based growth charts. Your doctor or school nurse can help you understand a healthy weight for your age.',
		'stops.pregnancy.title'   => 'This check isn\'t suitable right now',
		'stops.pregnancy.body'    => 'BMI and waist measures don\'t give a reliable picture during pregnancy or while breastfeeding. Your midwife or doctor can advise you about weight at this time.',
		'stops.eating.title'      => 'Thank you for telling us',
		'stops.eating.body'       => 'Numbers like these aren\'t the most helpful thing to focus on, so we haven\'t shown any. Your doctor can help, and you can find free, confidential support near you at <a href="https://findahelpline.com" target="_blank" rel="noopener">findahelpline.com</a>.',
		'categories.underweight'  => 'Underweight',
		'categories.healthy'      => 'Healthy weight',
		'categories.overweight'   => 'Overweight',
		'categories.obese1'       => 'Obesity (class 1)',
		'categories.obese2'       => 'Obesity (class 2)',
		'categories.obese3'       => 'Obesity (class 3)',
		'thresholds.standard'     => 'Standard thresholds used.',
		'thresholds.adjusted'     => 'Lower thresholds used for your family background.',
		'thresholds.notSay'       => 'Standard thresholds used. For some family backgrounds the guidance uses lower thresholds, so a healthy weight may be lower for you.',
		'headline.eyebrow'        => 'Your BMI',
		'headline.unit'           => 'kg/m²',
		'range.title'             => 'A healthy weight range for your height',
		'range.note'              => 'A guide for your height, not a target. Health is about more than one number.',
		'waist.title'             => 'Your waist-to-height ratio',
		'waist.bands.healthy'     => 'Healthy',
		'waist.bands.increased'   => 'Increased',
		'waist.bands.high'        => 'High',
		'waist.meaning.healthy'   => 'Your waist is less than half your height, which the guidance describes as healthy.',
		'waist.meaning.increased' => 'Your waist is half your height or more. The guidance links this with increased health risks, such as type 2 diabetes and heart disease.',
		'waist.meaning.high'      => 'Your waist is well over half your height. The guidance links this with high health risks, such as type 2 diabetes and heart disease.',
		'waist.below'             => 'This is below the range the guidance describes. Your BMI result gives a better picture.',
		'waist.needWaist'         => 'Add your waist above for a fuller picture: keeping your waist to less than half your height is a simple, useful aim.',
		'waist.bmi35'             => 'At a BMI of 35 or more, your waist adds little to the picture, so the ratio isn\'t shown.',
		'meaning.title'           => 'What this means',
		'meaning.underweight'     => 'Your BMI is below the healthy range. Being underweight can affect your energy, bones and immunity. It\'s worth talking to your doctor, who can check for any cause and help you look after your health.',
		'meaning.healthy'         => 'Your BMI is in the healthy range for your height. Eating well, staying active and sleeping well all help you stay well.',
		'meaning.overweight'      => 'Your BMI is above the healthy range. Small, steady changes to eating, activity and sleep can make a real difference to health, and your doctor can help if you\'d like support.',
		'meaning.obese'           => 'Your BMI is in the obesity range. This raises the risk of conditions such as type 2 diabetes, high blood pressure and joint problems. Your doctor can talk to you about support that suits you.',
		'meaning.eatingSupport'   => 'If you\'re worried about eating, weight or body image, your doctor can help, and you can find support near you at <a href="https://findahelpline.com" target="_blank" rel="noopener">findahelpline.com</a>.',
		'meaning.class3'          => 'Your doctor can talk to you about support that suits you, including specialist services.',
		'meaning.older'           => 'At 65 and over, BMI is a less reliable guide, and a slightly higher BMI may not carry the same risk. Keeping your muscle strength and staying active matter more than a number.',
	);

	/**
	 * Screen titles and button labels, each quoted from an approved source:
	 * Q = the approved question wording HW-P3-35-11 (10 Nov 2026); P = the approved clickable prototype
	 * `03 UI Designs/Stage 3/healthy-weight-programme.html`; T = the signed templates pack (section 7); B = the brief
	 * for this package (FR3-97 to FR3-110 screen names).
	 */
	const LABELS = array(
		'questions_title'  => 'Eight quick questions',                                          // Q, part 2 heading.
		'questions_intro'  => 'Your answers shape your plan. You can change them any time.',    // Q, part 2.
		'qod_title'        => 'Quick question',                                                 // Q, part 3 heading.
		'qod_intro'        => 'One or two of these a day help us fit your plan to you.',        // Q, part 3.
		'where_title'      => 'Where you are now',                                              // B (FR3-97).
		'pace_title'       => 'Your pace',                                                      // P, step 4.
		'milestones_title' => 'Your milestones',                                                // P, step 4.
		'plan_title'       => "Here's your plan",                                               // P, step 5.
		'why_title'        => 'Why your plan looks like this',                                  // P.
		'blocks_title'     => 'Choose your next four weeks',                                    // P, weeks 1 to 8 done.
		'blocks_start'     => 'Start this block',                                               // P.
		'suggested'        => 'Suggested for you',                                              // P (pace and blocks tag).
		'note_kicker'      => 'One-minute note',                                                // P, Today.
		'food_today'       => 'Food today',                                                     // P, Today.
		'log_meal'         => 'Log a meal',                                                     // P, Today.
		'got_in_way'       => 'What got in the way?',                                           // T, T-HW-WEEK-01.
		'next'             => 'Next',                                                           // P, navigation.
		'back'             => 'Back',                                                           // P, navigation.
		'skip'             => 'Skip',                                                           // Q, HW-QW-07 ("with a Skip button").
		'carry_on'         => 'Carry on to week 5',                                             // Owner Question 161.
	);

	/** "What got in the way?": the seven choices of the signed T-HW-WEEK-01 table, with R-HW-WEEK-01's values. */
	const GOT_IN_WAY = array(
		'time'     => 'Time or work',
		'family'   => 'Family',
		'cravings' => 'Cravings',
		'stress'   => 'Stress',
		'social'   => 'Social events',
		'illness'  => 'Illness',
		'nothing'  => 'Nothing',
	);

	/** The approved question IDs (HW-P3-35-11) of the answer keys, for a content loader keyed by ID. */
	const QW_IDS = array(
		'activity_level'       => 'HW-QW-01',
		'bmi_background'       => 'HW-QW-02',
		'reason'               => 'HW-QW-03',
		'barrier'              => 'HW-QW-04',
		'time'                 => 'HW-QW-05',
		'work'                 => 'HW-QW-06',
		'wake_time'            => 'HW-QW-07',
		'confidence'           => 'HW-QW-08',
		'sleep'                => 'HW-QW-09',
		'stress'               => 'HW-QW-10',
		'alcohol_freq'         => 'HW-QW-11',
		'alcohol_amount'       => 'HW-QW-12',
		'food_rules'           => 'HW-QW-13',
		'festivals'            => 'HW-QW-14',
		'allergies'            => 'HW-QW-15',
		'eating_pattern'       => 'HW-QW-16',
		'snack_time'           => 'HW-QW-17',
		'sugary_drinks'        => 'HW-QW-18',
		'eating_out'           => 'HW-QW-19',
		'cooking_time'         => 'HW-QW-20',
		'cooking_skill'        => 'HW-QW-21',
		'food_budget'          => 'HW-QW-22',
		'favourite_cuisines'   => 'HW-QW-23',
		'activity_enjoyment'   => 'HW-QW-24',
	);

	/** Food rules that exclude alcohol: its questions are skipped and "never" is recorded (FR3-98 AS-08). */
	const NO_ALCOHOL_RULES = array( 'halal', 'jain' );

	// ---- Guards and data (P2) -----------------------------------------------------------------------------------------

	/**
	 * Whether a class has a static method (the P2 and P3 classes may not be merged yet).
	 *
	 * @param string $class  Class.
	 * @param string $method Method.
	 */
	public static function has( $class, $method ) {
		return class_exists( $class ) && method_exists( $class, $method );
	}

	/**
	 * Call a P2 or P3 method when it exists; otherwise the default.
	 *
	 * @param string $class   Class.
	 * @param string $method  Method.
	 * @param array  $args    Arguments.
	 * @param mixed  $default Returned when the method is missing.
	 * @return mixed
	 */
	public static function call( $class, $method, array $args = array(), $default = null ) {
		if ( ! self::has( $class, $method ) ) {
			return $default;
		}
		return call_user_func_array( array( $class, $method ), $args );
	}

	/** The Healthy Weight programme is built (P2 merged). */
	public static function programme_on() {
		return self::has( self::PROGRAMME, 'plan_view' );
	}

	/**
	 * The plan view (P2 plan_view), or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Y-m-d.
	 * @return array|null
	 */
	public static function plan_view( $member_id, $today ) {
		$v = self::call( self::PROGRAMME, 'plan_view', array( (int) $member_id, (string) $today ) );
		return is_array( $v ) && ! is_wp_error( $v ) ? $v : null;
	}

	/**
	 * Today's Healthy Weight view (P2 today), or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Y-m-d.
	 * @return array|null
	 */
	public static function today_view( $member_id, $today ) {
		$v = self::call( self::PROGRAMME, 'today', array( (int) $member_id, (string) $today ) );
		return is_array( $v ) && ! is_wp_error( $v ) ? $v : null;
	}

	/**
	 * Every answer of the member (P2 LLD_Members_HW_Answers::all), or an empty list.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	public static function answers( $member_id ) {
		$a = self::call( self::ANSWERS, 'all', array( (int) $member_id ), array() );
		return is_array( $a ) ? $a : array();
	}

	/**
	 * One answer, or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Answer key.
	 */
	public static function answer( $member_id, $key ) {
		if ( self::has( self::ANSWERS, 'get' ) ) {
			$v = LLD_Members_HW_Answers::get( (int) $member_id, (string) $key );
			return is_wp_error( $v ) ? null : $v;
		}
		$all = self::answers( $member_id );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Save one answer through P2. Returns true, a WP_Error, or false when P2 is missing.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Answer key.
	 * @param mixed  $value     Value.
	 * @return bool|WP_Error
	 */
	public static function set_answer( $member_id, $key, $value ) {
		if ( ! self::has( self::ANSWERS, 'set' ) ) {
			return false;
		}
		$r = LLD_Members_HW_Answers::set( (int) $member_id, (string) $key, $value );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * The member's food rules exclude alcohol (halal, Jain or no alcohol), from the answers or the food preferences.
	 *
	 * @param array $answers answers().
	 */
	public static function no_alcohol( array $answers ) {
		$rules = isset( $answers['food_rules'] ) ? (array) $answers['food_rules'] : array();
		$rules = array_map( 'strtolower', array_map( 'strval', $rules ) );
		return (bool) array_intersect( self::NO_ALCOHOL_RULES, $rules );
	}

	/**
	 * The member has an eating-disorder flag (now, in the past, or not said): no calorie number anywhere.
	 *
	 * @param array $facts LLD_Members_Goal_Journey::facts().
	 */
	public static function ed( array $facts ) {
		if ( in_array( 'eating_disorder', isset( $facts['situations'] ) ? (array) $facts['situations'] : array(), true ) ) {
			return true;
		}
		// The Check's own question on "where you are now": yes, or prefer not to say (S1).
		$answer = isset( $facts['member_id'] ) ? self::answer( (int) $facts['member_id'], 'eating_disorder' ) : null;
		return in_array( $answer, array( 'yes', 'prefer' ), true );
	}

	/**
	 * Whether P2 says R-HW-SAFE-01 stops the programme for this view (pregnancy or an eating disorder reported, at any
	 * time): the screen then shows only the stop.
	 *
	 * @param array|null $view plan_view(), today() or blocks().
	 */
	public static function stopped( $view ) {
		return is_array( $view ) && ! empty( $view['stopped'] ) && isset( $view['stop'] ) && is_array( $view['stop'] );
	}

	/**
	 * The SCR-26 stop: the Healthy Weight Check's own stop wording, then the safety matrix's note. No number.
	 *
	 * @param array $stop The view's stop: {rule, stop: pregnancy|eating|under18, screen, note_key, note}.
	 */
	public static function stop_html( array $stop ) {
		$key  = isset( $stop['stop'] ) ? (string) $stop['stop'] : '';
		$html = '<section class="lld-card lld-hw-stop" data-lld-section="hw-stop" data-lld-hw-stop="' . esc_attr( $key ) . '" data-lld-screen="SCR-26">';
		if ( '' !== self::check( 'stops.' . $key . '.title' ) ) {
			$html .= '<h2>' . esc_html( self::check( 'stops.' . $key . '.title' ) ) . '</h2><p>' . self::linked( self::check( 'stops.' . $key . '.body' ) ) . '</p>';
		}
		if ( ! empty( $stop['note'] ) && is_string( $stop['note'] ) ) {
			$html .= '<p data-lld-matrix-note="' . esc_attr( isset( $stop['note_key'] ) ? (string) $stop['note_key'] : '' ) . '">' . esc_html( $stop['note'] ) . '</p>';
		}
		return $html . '</section>';
	}

	/**
	 * The goal's pace is not chosen yet (S6): "Your pace", with the button back to the pace step.
	 *
	 * @param int $goal_id The weight-loss goal.
	 */
	public static function pace_pending_html( $goal_id ) {
		$href = home_url( '/members/goal/?done=' . (int) $goal_id . '&hw=pace' );
		return '<section class="lld-card" data-lld-section="hw-pace-pending" data-lld-pace="not-chosen"><h2>' . esc_html( self::LABELS['pace_title'] ) . '</h2><p>' . lld_button( array( 'label' => self::LABELS['next'], 'href' => $href ) ) . '</p></section>';
	}

	// ---- Approved content (P3) ----------------------------------------------------------------------------------------

	/**
	 * One approved question, normalised, or null when it is not loaded.
	 *
	 * Accepted shapes (P3 LLD_Members_HW_Content::question): {text|question|label, why|reason, note, type (choice |
	 * multi | scale | time | date), multi, choices|options (list of {value, label}, of [value, label], or value =>
	 * label), min, max, low, high (scale ends) or scale {min, max, low, high}, follow_up {key, when: values, …}}.
	 *
	 * @param string $key Answer key (or HW-QW id).
	 * @return array|null {key, text, why, note, type, choices: list of [value, label], min, max, low, high, follow_up}
	 */
	public static function question( $key ) {
		if ( ! self::has( self::CONTENT, 'question' ) ) {
			return null;
		}
		$q = LLD_Members_HW_Content::question( (string) $key );
		if ( ( ! is_array( $q ) || ! $q ) && isset( self::QW_IDS[ $key ] ) ) {
			$q = LLD_Members_HW_Content::question( self::QW_IDS[ $key ] );
		}
		return self::norm_question( (string) $key, $q );
	}

	/**
	 * A question array as the screens use it, or null.
	 *
	 * @param string $key Answer key.
	 * @param mixed  $q   Loader answer.
	 */
	public static function norm_question( $key, $q ) {
		if ( ! is_array( $q ) || is_wp_error( $q ) ) {
			return null;
		}
		$text = self::first( $q, array( 'text', 'question', 'label', 'title' ) );
		if ( '' === $text ) {
			return null;
		}
		$scale = isset( $q['scale'] ) && is_array( $q['scale'] ) ? $q['scale'] : $q;
		$type  = isset( $q['type'] ) ? (string) $q['type'] : '';
		if ( '' === $type ) {
			$type = isset( $scale['min'], $scale['max'] ) && ! isset( $q['choices'] ) && ! isset( $q['options'] ) ? 'scale' : 'choice';
		}
		if ( ! empty( $q['multi'] ) ) {
			$type = 'multi';
		}
		$choices = array();
		$raw     = isset( $q['choices'] ) ? $q['choices'] : ( isset( $q['options'] ) ? $q['options'] : array() );
		foreach ( (array) $raw as $k => $c ) {
			if ( is_array( $c ) && isset( $c['value'] ) ) {
				$choices[] = array( (string) $c['value'], (string) ( isset( $c['label'] ) ? $c['label'] : ( isset( $c['text'] ) ? $c['text'] : $c['value'] ) ) );
			} elseif ( is_array( $c ) && isset( $c[0], $c[1] ) ) {
				$choices[] = array( (string) $c[0], (string) $c[1] );
			} elseif ( is_string( $c ) || is_numeric( $c ) ) {
				$choices[] = array( is_int( $k ) ? (string) $c : (string) $k, (string) $c );
			}
		}
		$follow = null;
		if ( isset( $q['follow_up'] ) && is_array( $q['follow_up'] ) ) {
			$fk     = isset( $q['follow_up']['key'] ) ? (string) $q['follow_up']['key'] : $key . '_follow_up';
			$follow = self::norm_question( $fk, $q['follow_up'] );
			if ( $follow ) {
				$follow['when'] = isset( $q['follow_up']['when'] ) ? array_map( 'strval', (array) $q['follow_up']['when'] ) : array();
			}
		}
		return array(
			'key'       => $key,
			'text'      => $text,
			'why'       => self::first( $q, array( 'why', 'reason', 'why_we_ask' ) ),
			'note'      => self::first( $q, array( 'note', 'shown_with', 'picture_line' ) ),
			'type'      => in_array( $type, array( 'choice', 'multi', 'scale', 'time', 'date' ), true ) ? $type : 'choice',
			'choices'   => $choices,
			'min'       => isset( $scale['min'] ) ? (int) $scale['min'] : 1,
			'max'       => isset( $scale['max'] ) ? (int) $scale['max'] : 10,
			'low'       => self::first( $scale, array( 'low', 'min_label' ) ),
			'high'      => self::first( $scale, array( 'high', 'max_label' ) ),
			'follow_up' => $follow,
		);
	}

	/**
	 * An approved content block's text (P3 block()), or ''.
	 *
	 * @param string $key Block key.
	 * @param string $field Field to read from an array block (text, body, title).
	 */
	public static function block_text( $key, $field = 'text' ) {
		$b = self::call( self::CONTENT, 'block', array( (string) $key ) );
		if ( is_string( $b ) ) {
			return 'text' === $field || 'body' === $field ? $b : '';
		}
		if ( ! is_array( $b ) ) {
			return '';
		}
		if ( isset( $b[ $field ] ) && is_string( $b[ $field ] ) ) {
			return $b[ $field ];
		}
		return 'text' === $field ? self::first( $b, array( 'body', 'text', 'line' ) ) : '';
	}

	/**
	 * The approved activity's meta (P3 activity_meta), or null.
	 *
	 * @param string $id Activity id.
	 * @return array|null
	 */
	public static function activity( $id ) {
		$a = self::call( self::CONTENT, 'activity_meta', array( (string) $id ) );
		return is_array( $a ) && ! is_wp_error( $a ) ? $a : null;
	}

	// ---- Signed templates (P1) ----------------------------------------------------------------------------------------

	/**
	 * A signed template's text, filled, with its [buttons] taken out; '' when the template is unknown.
	 *
	 * @param string $key    HW_TEMPLATES key.
	 * @param array  $values Gap => value.
	 */
	public static function tpl( $key, array $values = array() ) {
		if ( ! self::has( 'LLD_Members_Rule_Catalogue', 'fill' ) ) {
			return '';
		}
		$t = LLD_Members_Rule_Catalogue::fill( (string) $key, $values );
		return null === $t ? '' : trim( (string) preg_replace( '/\s*\[[^\]]+\]/', '', (string) $t ) );
	}

	/**
	 * The [button] labels of a signed template, in order.
	 *
	 * @param string $key HW_TEMPLATES key.
	 * @return string[]
	 */
	public static function buttons( $key ) {
		$t = self::has( 'LLD_Members_Rule_Catalogue', 'text' ) ? LLD_Members_Rule_Catalogue::text( (string) $key ) : null;
		if ( null === $t || ! preg_match_all( '/\[([^\]]+)\]/', (string) $t, $m ) ) {
			return array();
		}
		return $m[1];
	}

	/**
	 * The text of an engine item: its `text`, else its template filled with its values; '' otherwise.
	 *
	 * @param mixed $item String, or {text} or {template|key, values}.
	 */
	public static function text_of( $item ) {
		if ( is_string( $item ) ) {
			return $item;
		}
		if ( ! is_array( $item ) ) {
			return '';
		}
		if ( isset( $item['text'] ) && is_string( $item['text'] ) && '' !== $item['text'] ) {
			return trim( (string) preg_replace( '/\s*\[[^\]]+\]/', '', $item['text'] ) );
		}
		$key = isset( $item['template'] ) ? $item['template'] : ( isset( $item['key'] ) ? $item['key'] : '' );
		return '' === $key ? '' : self::tpl( (string) $key, isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array() );
	}

	/** A template's **bold** marks as <strong>, everything else escaped. */
	public static function rich( $text ) {
		$safe = esc_html( trim( (string) $text ) );
		return (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe );
	}

	/**
	 * Approved copy that carries a link (the Check's findahelpline.com lines): links kept, every other tag removed.
	 *
	 * @param string $html Approved HTML.
	 */
	public static function linked( $html ) {
		return wp_kses(
			(string) $html,
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
	}

	/**
	 * One of the Check's approved strings.
	 *
	 * @param string $key CHECK_COPY key.
	 */
	public static function check( $key ) {
		return isset( self::CHECK_COPY[ $key ] ) ? self::CHECK_COPY[ $key ] : '';
	}

	/**
	 * A choice list of the Check's copy ([[value, label], …]).
	 *
	 * @param string $key options.* key.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function check_options( $key ) {
		$o = json_decode( self::check( $key ), true );
		return is_array( $o ) ? $o : array();
	}

	/** kg to 0.1, as the signed methods show it ("60.0"). */
	public static function kg( $kg ) {
		return number_format( round( (float) $kg, 1 ), 1, '.', '' );
	}

	/** kcal with a thousands comma. */
	public static function kcal( $kcal ) {
		return number_format( (float) $kcal, 0, '.', ',' );
	}

	/** A date as the signed templates show it: "18 December 2026". */
	public static function date( $ymd ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( (string) $ymd, 0, 10 ), new DateTimeZone( 'UTC' ) );
		return $d ? $d->format( 'j F Y' ) : (string) $ymd;
	}

	// ---- Shared pieces ------------------------------------------------------------------------------------------------

	/**
	 * The free support cards (R-HW-SAFE-03, -05) with their signed buttons. T-HW-SAFE-03: the Sleep and stress check,
	 * Add Sleep and Stress (the Stage 2 safety questions first, FR3-109 AS-05) and Not now. T-HW-SAFE-05: Where to get
	 * help (findahelpline.com, new tab, T1 = A) and Not now. Nothing about weight or calories is put beside them.
	 *
	 * @param array $cards List of {rule, template, text} (P1 support_cards()['cards']).
	 */
	public static function cards_html( array $cards ) {
		$html = '';
		foreach ( $cards as $card ) {
			$template = is_array( $card ) && isset( $card['template'] ) ? (string) $card['template'] : ( is_string( $card ) ? (string) $card : '' );
			if ( '' !== $template && 0 !== strpos( $template, 'hw.' ) ) {
				$template = 'hw.' . $template;
			}
			$text = is_array( $card ) ? self::text_of( $card ) : '';
			if ( '' === $text ) {
				$text = self::tpl( $template );
			}
			if ( '' === $text ) {
				continue;
			}
			$labels = self::buttons( $template );
			$rule   = 'hw.T-HW-SAFE-03' === $template ? 'R-HW-SAFE-03' : ( 'hw.T-HW-SAFE-05' === $template ? 'R-HW-SAFE-05' : ( is_array( $card ) && isset( $card['rule'] ) ? (string) $card['rule'] : '' ) );
			$html  .= '<section class="lld-card lld-hw-support" data-lld-section="hw-support" data-lld-card="' . esc_attr( $rule ) . '">';
			$html  .= '<p>' . self::rich( $text ) . '</p><div class="lld-row lld-hw-actions">';
			foreach ( $labels as $i => $label ) {
				$html .= self::card_button( $template, $i, $label, count( $labels ) );
			}
			$html .= '</div></section>';
		}
		return $html;
	}

	/**
	 * One signed button of a support card.
	 *
	 * @param string $template Template key.
	 * @param int    $i        Position.
	 * @param string $label    Signed label.
	 * @param int    $count    Buttons on the card.
	 */
	private static function card_button( $template, $i, $label, $count ) {
		if ( 'hw.T-HW-MOT-02.goal' === $template ) {
			// [Start Keep It Off]: the Goal reached choice on Today (FR3-15).
			return '<form method="post" class="lld-inline-form" action="' . esc_url( home_url( '/members/home/' ) ) . '" data-lld-keep-it-off>' . wp_nonce_field( 'lld_reached', 'lld_reached_nonce', true, false ) . '<button class="lld-button" type="submit" name="lld_next" value="keep">' . esc_html( $label ) . '</button></form> ';
		}
		if ( 'hw.T-HW-WEEK-02' === $template && 0 === $i ) {
			// [Start 'Breaking a plateau']: that focus block, chosen on the blocks screen (R-HW-WEEK-02).
			return '<form method="post" class="lld-inline-form" action="' . esc_url( home_url( '/members/goal/?hw=blocks' ) ) . '">' . wp_nonce_field( 'lld_hw_block', 'lld_hw_block_nonce', true, false ) . '<input type="hidden" name="lld_hw_block" value="breaking-a-plateau"><button class="lld-button" type="submit" name="lld_action" value="hw_block">' . esc_html( $label ) . '</button></form> ';
		}
		if ( $i === $count - 1 ) {
			// "Not now": closes the card on this page; nothing is saved.
			return '<button type="button" class="lld-button lld-button-secondary" data-lld-hw-dismiss>' . esc_html( $label ) . '</button> ';
		}
		if ( 'hw.T-HW-SAFE-05' === $template ) {
			return '<a class="lld-button lld-button-secondary" href="' . esc_url( self::HELPLINE ) . '" target="_blank" rel="noopener" data-lld-help-link>' . esc_html( $label ) . '</a> ';
		}
		if ( 'hw.T-HW-SAFE-03' === $template ) {
			$href = 0 === $i ? home_url( self::SLEEP_CHECK ) : home_url( self::SLEEP_SCREEN );
			return '<a class="lld-button lld-button-secondary" href="' . esc_url( $href ) . '" data-lld-card-action="' . ( 0 === $i ? 'sleep-check' : 'add-sleep-stress' ) . '">' . esc_html( $label ) . '</a> ';
		}
		return '';
	}

	/**
	 * One approved question as form controls (one tap each): radios, checkboxes, a 1 to 10 row, a time or a date.
	 * The reason line is the hint, tied to the group with aria-describedby.
	 *
	 * @param array  $q       norm_question().
	 * @param mixed  $current Saved answer.
	 * @param string $prefix  Field name prefix (lld_hw).
	 * @param array  $attrs   Extra attributes for the wrapper (data-lld-show-when).
	 * @param array  $hide    Choice values to leave out.
	 */
	public static function question_html( array $q, $current, $prefix = 'lld_hw', array $attrs = array(), array $hide = array() ) {
		$key  = (string) $q['key'];
		$id   = 'lld-hw-' . str_replace( '_', '-', $key );
		$name = $prefix . '[' . $key . ']';
		$hint = '' !== $q['why'] ? $id . '-why' : '';
		$wrap = '';
		foreach ( $attrs as $a => $v ) {
			$wrap .= ' ' . $a . '="' . esc_attr( (string) $v ) . '"';
		}
		$html = '<div class="lld-hw-question" data-lld-question="' . esc_attr( $key ) . '"' . $wrap . '>';
		if ( 'time' === $q['type'] || 'date' === $q['type'] ) {
			$html .= '<div class="lld-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $q['text'] ) . '</label>';
			$html .= '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $q['type'] ) . '"' . ( 'time' === $q['type'] ? ' step="900"' : '' ) . ' value="' . esc_attr( is_scalar( $current ) ? (string) $current : '' ) . '"' . ( '' !== $hint ? ' aria-describedby="' . esc_attr( $hint ) . '"' : '' ) . '>';
			$html .= self::why( $q, $hint ) . '</div>';
			return $html . self::follow_up_html( $q, $prefix ) . '</div>';
		}
		$html .= '<fieldset class="lld-options' . ( 'scale' === $q['type'] ? ' lld-hw-scale' : '' ) . '"' . ( '' !== $hint ? ' aria-describedby="' . esc_attr( $hint ) . '"' : '' ) . '><legend>' . esc_html( $q['text'] ) . '</legend>';
		if ( '' !== $q['note'] ) {
			$html .= '<p class="lld-hint lld-hw-note">' . esc_html( $q['note'] ) . '</p>';
		}
		if ( 'alcohol_amount' === $key ) {
			$html .= self::drinks_pictures();
		}
		if ( 'scale' === $q['type'] ) {
			$html .= '<div class="lld-hw-scale-row">';
			for ( $n = (int) $q['min']; $n <= (int) $q['max']; $n++ ) {
				$html .= '<label class="lld-hw-scale-step"><input type="radio" name="' . esc_attr( $name ) . '" value="' . $n . '"' . ( is_numeric( $current ) && (int) $current === $n ? ' checked' : '' ) . '><span>' . $n . '</span></label>';
			}
			$html .= '</div>';
			if ( '' !== $q['low'] || '' !== $q['high'] ) {
				$html .= '<p class="lld-hint lld-hw-scale-ends"><span>' . esc_html( $q['low'] ) . '</span> <span>' . esc_html( $q['high'] ) . '</span></p>';
			}
		} else {
			$multi = 'multi' === $q['type'];
			$have  = $multi ? array_map( 'strval', (array) $current ) : array( is_scalar( $current ) ? (string) $current : '' );
			foreach ( $q['choices'] as $c ) {
				if ( in_array( $c[0], $hide, true ) ) {
					continue;
				}
				$html .= '<div class="lld-option"><label><input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '" value="' . esc_attr( $c[0] ) . '"' . ( in_array( $c[0], $have, true ) && '' !== $c[0] ? ' checked' : '' ) . '> ' . esc_html( $c[1] ) . '</label></div>';
			}
		}
		$html .= '</fieldset>' . self::why( $q, $hint );
		return $html . self::follow_up_html( $q, $prefix ) . '</div>';
	}

	/** The reason line under a question. */
	private static function why( array $q, $hint ) {
		return '' !== $hint ? '<p class="lld-hint" id="' . esc_attr( $hint ) . '">' . esc_html( $q['why'] ) . '</p>' : '';
	}

	/** A question's follow-up, shown only after the answers it names. */
	private static function follow_up_html( array $q, $prefix ) {
		if ( empty( $q['follow_up'] ) ) {
			return '';
		}
		$f    = $q['follow_up'];
		$when = $f['when'] ? $prefix . '[' . $q['key'] . ']:' . implode( ',', $f['when'] ) : '';
		return self::question_html( $f, null, $prefix, '' !== $when ? array( 'data-lld-show-when' => $when ) : array() );
	}

	/**
	 * The three pictured drinks (method sheet section 5: a glass of wine, a bottle of beer, a single spirit), as
	 * pictures only; the approved picture line says what they are.
	 */
	public static function drinks_pictures() {
		$wine   = '<svg viewBox="0 0 32 48" width="32" height="48" aria-hidden="true" focusable="false"><path d="M8 4h16c0 10-2 18-8 18S8 14 8 4z" fill="currentColor" opacity=".25" stroke="currentColor" stroke-width="2"/><path d="M16 22v16M10 42h12" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
		$beer   = '<svg viewBox="0 0 32 48" width="32" height="48" aria-hidden="true" focusable="false"><path d="M13 3h6v8l3 6v26a2 2 0 0 1-2 2h-8a2 2 0 0 1-2-2V17l3-6z" fill="currentColor" opacity=".25" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
		$spirit = '<svg viewBox="0 0 32 48" width="32" height="48" aria-hidden="true" focusable="false"><path d="M9 24h14l-2 18H11z" fill="currentColor" opacity=".25" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M11 34h10" stroke="currentColor" stroke-width="2"/></svg>';
		return '<p class="lld-hw-drinks" data-lld-drink-pictures="3">' . $wine . $beer . $spirit . '</p>';
	}

	/**
	 * Print a page in the Stage 2 layout with the Stage 3 stylesheet and script (the layout has no wp_head()).
	 *
	 * @param string $title  Title.
	 * @param string $main   Main HTML.
	 * @param bool   $wide   Wide layout.
	 * @param int    $status HTTP status.
	 */
	public static function output( $title, $main, $wide = false, $status = 200 ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/stage3.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		ob_start();
		lld_members_layout( array( 'title' => $title, 'main' => $main, 'signed_in' => true, 'wide' => $wide, 'status' => $status ) );
		$page = (string) ob_get_clean();
		ob_start();
		wp_print_styles( array( self::HANDLE ) );
		$styles = (string) ob_get_clean();
		ob_start();
		wp_print_scripts( array( self::HANDLE ) );
		$scripts = (string) ob_get_clean();
		$head    = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}
		$foot = strrpos( $page, '</body>' );
		if ( false !== $foot ) {
			$page = substr_replace( $page, $scripts, $foot, 0 );
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/** The first non-empty string field of an array. */
	private static function first( $a, array $keys ) {
		foreach ( $keys as $k ) {
			if ( is_array( $a ) && isset( $a[ $k ] ) && is_string( $a[ $k ] ) && '' !== trim( $a[ $k ] ) ) {
				return (string) $a[ $k ];
			}
		}
		return '';
	}
}
