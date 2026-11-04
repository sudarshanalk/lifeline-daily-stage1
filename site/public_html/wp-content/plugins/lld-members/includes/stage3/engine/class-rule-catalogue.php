<?php
/**
 * Decision engine rule catalogue, version 1 (P3-34 from P3-02; FR3-17, NFR3-17), written from the owner-signed
 * `04 Health Tools/Stage 3/P3-02-Decision-Engine-Rule-Catalogue-v1.md` (approval record
 * `approvals/stage3/decision-rules.json`, frozen fixtures `fixtures/decision-rules/`).
 *
 * The 23 rules and their message templates. Every template is the owner's wording verbatim: the engine fills only
 * the numbers and dates in braces (FR3-17 AS-04). The test hashes every string against the approval record, so a
 * change here without a new decision fails CI (NFR3-17 AS-03). No WordPress, no database.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules, templates and the gap filler.
 */
class LLD_Members_Rule_Catalogue {

	const VERSION = '1.1';

	const CLASS_SAFETY     = 'safety';
	const CLASS_STEP       = 'today_step';
	const CLASS_ADJUSTMENT = 'plan_adjustment';
	const CLASS_INSIGHT    = 'insight';
	const CLASS_EVENT      = 'event';

	const QUOTA_PER_WEEK    = 3;
	const QUOTA_WINDOW_DAYS = 7;
	const LOW_MOOD_DAYS     = 7;

	/**
	 * The signed templates, keyed as the approval record keys them. Text verbatim from the catalogue, bold marks and
	 * button labels included: the screens render the marks and the buttons.
	 */
	const TEMPLATES = array(
		'rule.R-SAFE-01'            => 'Your home blood pressure has averaged **{sys}/{dia}** over the last two weeks, which is above the 135/85 level doctors use for home readings. Please share your readings with your doctor. [Download my readings]',
		'rule.R-SAFE-01p'           => 'In pregnancy, a reading of 140/90 or more should be checked the same day. Please contact your midwife or maternity unit today.',
		'rule.R-SAFE-03'            => 'Your weight has gone down by **{pct} %** since **{date}** without a weight goal. Losing weight without trying can have many causes, and some need checking, so please make an appointment with your doctor.',
		'rule.R-SAFE-04'            => "It looks like the last few days have been hard. You don't have to work through this alone: talking to your doctor can help, and you can find free, confidential support near you at findahelpline.com. **If you are in danger now, call your local emergency number** \u{2014} for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, or 000 in Australia.",
		'rule.R-SAFE-04.today-step' => "A gentle day. If you'd like, take a 10-minute walk outside. [Done] [Skip]",
		'rule.R-STEP-01'            => 'Take your blood pressure today: sit quietly for 5 minutes, then take two readings a minute apart. [Log a reading]',
		'rule.R-STEP-02'            => 'Weigh-in day. Same time, same scales, before breakfast gives the steadiest trend. [Log my weight]',
		'rule.R-STEP-03'            => 'Your weekly check-in is ready: 2 minutes to see how the week went and keep the plan right for you. [Start check-in]',
		'rule.R-STEP-04'            => "You're at **{done}** of **{target}** minutes this week. A **{suggest}**-minute brisk walk today keeps you on track. [Done] [Skip]",
		'rule.R-STEP-05'            => 'Welcome back. One quick log today, water, a meal or your mood, keeps your week going. [Log something]',
		'rule.R-ADJ-01'             => "Your trend shows you'd reach **{goal} kg** around **{projected}** rather than **{target}**. That's normal: bodies don't lose weight in a straight line. Would you like to move your date to **{new_date}**, keep the plan as it is, or try a different meal plan this week?",
		'rule.R-ADJ-02'             => 'Your weight has held steady at about **{weight} kg** for three weeks. Plateaus are normal and usually pass. This week, logging every meal and drink often shows where a few extra calories hide. Try that, or swap to a different meal plan?',
		'rule.R-ADJ-03'             => "You're losing weight faster than the safe rate of about **{cap} kg** a week. We've raised your daily target to **{kcal} kcal** so your body keeps muscle and energy. If you're not trying to lose this fast, or feel unwell, please see your doctor.",
		'rule.R-ADJ-03.gentle'      => "We've given your daily food target a small lift, so your body has a little more energy. There's nothing you need to do. If you feel unwell, please see your doctor.",
		'rule.R-INS-01'             => 'After nights under 6 hours, your blood pressure averaged **{bp_short}**, compared with **{bp_long}** after 7 hours or more. Better sleep may be helping your blood pressure. Week 1 of Sleep and Stress has three habits that help.',
		'rule.R-INS-02'             => "Your average blood pressure over the last four weeks is **{now}**, down from **{before}** the four weeks before. Keep going with what you're doing, and keep sharing readings with your doctor.",
		'rule.R-INS-03'             => 'On days you were active, you rated your mood **{mood_active}** out of 5 on average; on other days **{mood_rest}**. Movement seems to lift your mood. Even a 10-minute walk counts.',
		'rule.R-INS-04'             => "You averaged **{hours}** hours' sleep this week, down from **{before}**. Most adults do best on 7 to 9. If something's keeping you up, the Sleep and Stress track's week 1 is a good place to start.",
		'rule.R-INS-05'             => "Four weeks in a row at 150 minutes or more. That's the level linked to the biggest health gains. Great habit.",
		'rule.R-INS-06'             => 'Your fibre averaged **{fibre} g** a day this week, against a target of **{target} g**. Oats, beans, lentils, fruit and vegetables all help, and fibre keeps you fuller for longer.',
		'rule.R-INS-07'             => 'Your protein averaged **{protein} g** a day this week, against your target of **{target} g**. Spreading protein across meals, especially breakfast, makes the target easier to reach.',
		'rule.R-INS-08'             => "Your waist is down **{cm} cm** since **{date}** while your weight has barely moved. That's a good sign: it usually means more muscle and less fat around the middle, which matters more for health than the number on the scales.",
		'rule.R-INS-09'             => '**{days}** days in a row of logging. Consistency is what turns small changes into results.',
		'rule.R-INS-10'             => "You've reached your goal: **{goal}**. [Keep it off] [Set a new goal]",
		'template.T-GEN-01'         => "Keep logging: we'll start spotting patterns after {n} days",
	);

	/**
	 * The 23 rules (catalogue section 6). `id` is the stable number stored in insights.rule_id and today_steps.rule_id;
	 * `template` the key in TEMPLATES; `silenced_by` the safety-matrix situations that silence the rule for a member
	 * (P3-03 "Safety check" column); `tags` the content tags the eating-disorder row forbids (calorie, weight, portion).
	 * `every_member` marks the safety adjustment R-ADJ-03: every member with a calorie target, Free or Premium, never
	 * suppressed by R-SAFE-04, with `gentle_template` during the low-mood days (Owner Q68 = B, catalogue v1.1).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function rules() {
		return array(
			'R-SAFE-01'  => array( 'id' => 1, 'key' => 'safe_bp_two_weeks', 'class' => self::CLASS_SAFETY, 'priority' => 1, 'cooldown_days' => 28, 'template' => 'rule.R-SAFE-01', 'silenced_by' => array( 'pregnancy' ), 'tags' => array() ),
			'R-SAFE-01p' => array( 'id' => 2, 'key' => 'safe_bp_pregnancy', 'class' => self::CLASS_SAFETY, 'priority' => 1, 'cooldown_days' => 0, 'template' => 'rule.R-SAFE-01p', 'silenced_by' => array(), 'tags' => array(), 'requires' => array( 'pregnancy' ) ),
			'R-SAFE-02'  => array( 'id' => 3, 'key' => 'safe_glucose', 'class' => self::CLASS_SAFETY, 'priority' => 0, 'cooldown_days' => 0, 'template' => null, 'silenced_by' => array(), 'tags' => array(), 'at_save' => true ),
			'R-SAFE-03'  => array( 'id' => 4, 'key' => 'safe_weight_falling', 'class' => self::CLASS_SAFETY, 'priority' => 2, 'cooldown_days' => 28, 'template' => 'rule.R-SAFE-03', 'silenced_by' => array(), 'tags' => array() ),
			'R-SAFE-04'  => array( 'id' => 5, 'key' => 'safe_low_mood', 'class' => self::CLASS_SAFETY, 'priority' => 0, 'cooldown_days' => 7, 'suppresses_days' => 7, 'template' => 'rule.R-SAFE-04', 'step_template' => 'rule.R-SAFE-04.today-step', 'silenced_by' => array(), 'tags' => array() ),
			'R-STEP-01'  => array( 'id' => 11, 'key' => 'step_bp_due', 'class' => self::CLASS_STEP, 'priority' => 1, 'cooldown_days' => 1, 'template' => 'rule.R-STEP-01', 'silenced_by' => array(), 'tags' => array(), 'action' => 'log_reading' ),
			'R-STEP-02'  => array( 'id' => 12, 'key' => 'step_weigh_in', 'class' => self::CLASS_STEP, 'priority' => 2, 'cooldown_days' => 1, 'template' => 'rule.R-STEP-02', 'silenced_by' => array( 'eating_disorder', 'pregnancy' ), 'tags' => array( 'weight' ), 'action' => 'log_reading' ),
			'R-STEP-03'  => array( 'id' => 13, 'key' => 'step_check_in', 'class' => self::CLASS_STEP, 'priority' => 3, 'cooldown_days' => 1, 'template' => 'rule.R-STEP-03', 'silenced_by' => array(), 'tags' => array(), 'action' => 'check_in' ),
			'R-STEP-04'  => array( 'id' => 14, 'key' => 'step_activity_behind', 'class' => self::CLASS_STEP, 'priority' => 4, 'cooldown_days' => 1, 'template' => 'rule.R-STEP-04', 'silenced_by' => array(), 'tags' => array(), 'action' => 'activity', 'note_for' => array( 'heart_disease', 'blood_pressure' ) ),
			'R-STEP-05'  => array( 'id' => 15, 'key' => 'step_welcome_back', 'class' => self::CLASS_STEP, 'priority' => 5, 'cooldown_days' => 1, 'template' => 'rule.R-STEP-05', 'silenced_by' => array(), 'tags' => array(), 'action' => 'log_food' ),
			'R-ADJ-01'   => array( 'id' => 21, 'key' => 'adj_behind_two_weeks', 'class' => self::CLASS_ADJUSTMENT, 'priority' => 1, 'cooldown_days' => 0, 'template' => 'rule.R-ADJ-01', 'silenced_by' => array( 'eating_disorder', 'pregnancy' ), 'tags' => array( 'weight', 'calorie' ), 'at_check_in' => true ),
			'R-ADJ-02'   => array( 'id' => 22, 'key' => 'adj_plateau', 'class' => self::CLASS_ADJUSTMENT, 'priority' => 2, 'cooldown_days' => 28, 'template' => 'rule.R-ADJ-02', 'silenced_by' => array( 'eating_disorder', 'pregnancy', 'bmi_under_18_5' ), 'tags' => array( 'weight', 'calorie' ), 'at_check_in' => true ),
			'R-ADJ-03'   => array( 'id' => 23, 'key' => 'adj_too_fast', 'class' => self::CLASS_ADJUSTMENT, 'priority' => 0, 'cooldown_days' => 14, 'template' => 'rule.R-ADJ-03', 'gentle_template' => 'rule.R-ADJ-03.gentle', 'silenced_by' => array(), 'tags' => array(), 'automatic' => true, 'every_member' => true ),
			'R-INS-01'   => array( 'id' => 31, 'key' => 'ins_sleep_vs_bp', 'class' => self::CLASS_INSIGHT, 'priority' => 1, 'cooldown_days' => 28, 'template' => 'rule.R-INS-01', 'silenced_by' => array( 'pregnancy' ), 'tags' => array() ),
			'R-INS-02'   => array( 'id' => 32, 'key' => 'ins_bp_trend_down', 'class' => self::CLASS_INSIGHT, 'priority' => 2, 'cooldown_days' => 28, 'template' => 'rule.R-INS-02', 'silenced_by' => array( 'pregnancy' ), 'tags' => array() ),
			'R-INS-03'   => array( 'id' => 33, 'key' => 'ins_activity_mood', 'class' => self::CLASS_INSIGHT, 'priority' => 3, 'cooldown_days' => 28, 'template' => 'rule.R-INS-03', 'silenced_by' => array(), 'tags' => array() ),
			'R-INS-04'   => array( 'id' => 34, 'key' => 'ins_short_sleep_week', 'class' => self::CLASS_INSIGHT, 'priority' => 4, 'cooldown_days' => 28, 'template' => 'rule.R-INS-04', 'silenced_by' => array(), 'tags' => array() ),
			'R-INS-05'   => array( 'id' => 35, 'key' => 'ins_activity_four_weeks', 'class' => self::CLASS_INSIGHT, 'priority' => 5, 'cooldown_days' => 56, 'template' => 'rule.R-INS-05', 'silenced_by' => array(), 'tags' => array() ),
			'R-INS-06'   => array( 'id' => 36, 'key' => 'ins_fibre_low', 'class' => self::CLASS_INSIGHT, 'priority' => 6, 'cooldown_days' => 28, 'template' => 'rule.R-INS-06', 'silenced_by' => array( 'eating_disorder' ), 'tags' => array( 'calorie' ) ),
			'R-INS-07'   => array( 'id' => 37, 'key' => 'ins_protein_low', 'class' => self::CLASS_INSIGHT, 'priority' => 7, 'cooldown_days' => 28, 'template' => 'rule.R-INS-07', 'silenced_by' => array( 'eating_disorder', 'kidney' ), 'tags' => array( 'calorie' ) ),
			'R-INS-08'   => array( 'id' => 38, 'key' => 'ins_waist_vs_weight', 'class' => self::CLASS_INSIGHT, 'priority' => 8, 'cooldown_days' => 56, 'template' => 'rule.R-INS-08', 'silenced_by' => array( 'eating_disorder', 'pregnancy', 'bmi_under_18_5' ), 'tags' => array( 'weight' ) ),
			'R-INS-09'   => array( 'id' => 39, 'key' => 'ins_streak', 'class' => self::CLASS_INSIGHT, 'priority' => 9, 'cooldown_days' => 0, 'template' => 'rule.R-INS-09', 'silenced_by' => array(), 'tags' => array(), 'once_per_milestone' => true ),
			'R-INS-10'   => array( 'id' => 40, 'key' => 'goal_reached', 'class' => self::CLASS_EVENT, 'priority' => 0, 'cooldown_days' => 0, 'template' => 'rule.R-INS-10', 'silenced_by' => array(), 'tags' => array(), 'once_per_goal' => true ),
		);
	}

	/**
	 * Numeric ids of the templates (insights.template_id): the rule's id for its message, 100 + id for a rule's
	 * second (Today step) template, 200 + id for its gentle low-mood template, 99 for T-GEN-01.
	 *
	 * @return array<string,int>
	 */
	public static function template_ids() {
		$ids = array( 'template.T-GEN-01' => 99 );
		foreach ( self::rules() as $rule ) {
			if ( ! empty( $rule['template'] ) ) {
				$ids[ $rule['template'] ] = (int) $rule['id'];
			}
			if ( ! empty( $rule['step_template'] ) ) {
				$ids[ $rule['step_template'] ] = 100 + (int) $rule['id'];
			}
			if ( ! empty( $rule['gentle_template'] ) ) {
				$ids[ $rule['gentle_template'] ] = 200 + (int) $rule['id'];
			}
		}
		return $ids;
	}

	/**
	 * One rule's record, or null.
	 *
	 * @param string $name R-… name.
	 */
	public static function rule( $name ) {
		$all = self::rules();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Rule name for a stored rule id, or ''.
	 *
	 * @param int $id Stored id.
	 */
	public static function name_for_id( $id ) {
		foreach ( self::rules() as $name => $rule ) {
			if ( (int) $rule['id'] === (int) $id ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Fill a template's gaps. Only the named gaps are replaced, only with numbers, dates or the plain goal text the
	 * engine computed; every other character of the template stays (FR3-17 AS-04, NFR3-17 AS-02).
	 *
	 * @param string              $template Template key.
	 * @param array<string,mixed> $values   Gap name => value.
	 * @return string|null Null for an unknown template.
	 */
	public static function fill( $template, array $values ) {
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return null;
		}
		$text = self::TEMPLATES[ $template ];
		foreach ( $values as $gap => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', (string) $gap ) ) {
				continue;
			}
			$text = str_replace( '{' . $gap . '}', self::plain( $value ), $text );
		}
		return $text;
	}

	/**
	 * The gaps a template has, in order.
	 *
	 * @param string $template Template key.
	 * @return string[]
	 */
	public static function gaps( $template ) {
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return array();
		}
		preg_match_all( '/\{([a-z_]+)\}/', self::TEMPLATES[ $template ], $m );
		return $m[1];
	}

	/**
	 * A value as the member sees it: whole numbers without decimals, others to the decimals given, dates as given.
	 *
	 * @param mixed $value Number, date string or short text.
	 */
	private static function plain( $value ) {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			$s = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
			return '' === $s ? '0' : $s;
		}
		return trim( strip_tags( (string) $value ) );
	}
}
