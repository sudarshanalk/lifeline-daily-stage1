<?php
/**
 * Decision engine rule catalogue, version 1.3.1 (P3-34 from P3-02; FR3-17, NFR3-17), written from the owner-signed
 * `04 Health Tools/Stage 3/P3-02-Decision-Engine-Rule-Catalogue-v1.md` (approval record
 * `approvals/stage3/decision-rules.json`, frozen fixtures `fixtures/decision-rules/`).
 *
 * Version 1.2 (D3-58, 6 Nov 2026) added section 9, the Healthy Weight programme rules R-HW-* and their T-HW-*
 * templates (`P3-02-Healthy-Weight-Programme-Rules-Draft.md`, wording from `P3-02-Healthy-Weight-Templates.md`);
 * version 1.3 (D3-59, 10 Nov 2026) added R-HW-BLOCK-03; version 1.3.1 (D3-60, 10 Nov 2026) times R-HW-ALC-02 for shift
 * and night workers from 10 hours after their wake-up time. The R-HW rules build and adjust the member's plan: they are a
 * programme class of their own, outside the insight quota, and are run by `includes/stage3/healthy-weight/`, not by
 * the nightly engine below. Sections 1 to 8 (the 23 rules) are unchanged.
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

	const VERSION = '1.3.1';

	const CLASS_SAFETY     = 'safety';
	const CLASS_STEP       = 'today_step';
	const CLASS_ADJUSTMENT = 'plan_adjustment';
	const CLASS_INSIGHT    = 'insight';
	const CLASS_EVENT      = 'event';
	const CLASS_PROGRAMME  = 'programme';

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
	 * The Healthy Weight programme templates (catalogue section 9, versions 1.2 and 1.3; D3-58), verbatim from the signed
	 * templates pack `04 Health Tools/Stage 3/P3-02-Healthy-Weight-Templates.md`, which wins where the rule draft
	 * differs. Buttons stay on the same line as the text, as in TEMPLATES above. Labels, reason phrases and step titles
	 * that a template names are keyed under it. Hashed in the approval record's copy_hashes (NFR3-17).
	 */
	const HW_TEMPLATES = array(
		'hw.T-HW-SAFE-03'                          => 'Poor sleep and high stress are hard on anyone. If this goes on for more than two weeks, talk to your doctor. You can try the Sleep and stress check, or add Sleep and Stress as your extra goal. [Sleep and stress check] [Add Sleep and Stress] [Not now]',
		'hw.T-HW-SAFE-05'                          => 'Drinking less is good for your health as well as your weight, and help is there if you want it. If you drink heavily every day, talk to your doctor before you cut down, because stopping suddenly can be dangerous. [Where to get help] [Not now]',
		'hw.T-HW-NUM-02'                           => 'A healthy weight range for your height: **{low} to {high} kg**. A guide for your height, not a target. Health is about more than one number.',
		'hw.T-HW-NUM-04'                           => '**{kg} kg** · {label} · around {date}',
		'hw.T-HW-NUM-04.label.5pct'                => '5 % of your starting weight',
		'hw.T-HW-NUM-04.label.10pct'               => '10 % of your starting weight',
		'hw.T-HW-NUM-04.label.healthy'             => 'Into the healthy range',
		'hw.T-HW-NUM-04.label.goal'                => 'Your goal',
		'hw.T-HW-NUM-05'                           => 'Your {target} kcal a day includes drinks. Your usual week of drinks is about {weekly} kcal, roughly {daily} a day.',
		'hw.T-HW-PACE-01.heading.gentle'           => 'We suggest a gentle pace: 0.25 kg a week',
		'hw.T-HW-PACE-01.heading.steady'           => 'We suggest a steady pace: 0.5 kg a week',
		'hw.T-HW-PACE-01.heading.faster'           => 'You could go a little faster: 0.75 kg a week',
		'hw.T-HW-PACE-01'                          => 'Because {reasons}. A pace you can keep beats a fast start. You can change it.',
		'hw.T-HW-PACE-01.reason.confidence'        => 'you rated your confidence {n} out of 10',
		'hw.T-HW-PACE-01.reason.time'              => 'you have less than 10 minutes on most days',
		'hw.T-HW-PACE-01.reason.shifts'            => 'you work shifts',
		'hw.T-HW-PACE-01.reason.nights'            => 'you work nights',
		'hw.T-HW-PACE-01.reason.irregular'         => 'your hours change a lot',
		'hw.T-HW-PACE-01.reason.stress'            => "you're under a lot of stress right now",
		'hw.T-HW-PACE-01.reason.faster'            => 'you feel confident, have time and are sleeping well',
		'hw.T-HW-PACE-01.reason.older'             => 'at 65 and over a steady pace is the safest',
		'hw.T-HW-PACE-01.reason.steady'            => "it's a pace most people can keep",
		'hw.T-HW-STEP-01'                          => 'Chosen first because you said {barrier} gets in the way.',
		'hw.T-HW-STEP-01.barrier.evening_snacking' => 'evening snacking',
		'hw.T-HW-STEP-01.barrier.stress_eating'    => 'eating when stressed',
		'hw.T-HW-STEP-01.barrier.eating_out'       => 'eating out',
		'hw.T-HW-STEP-01.barrier.no_time'          => 'not having time',
		'hw.T-HW-STEP-01.barrier.alcohol'          => 'drinking',
		'hw.T-HW-STEP-02'                          => "We've skipped a few steps you already do.",
		'hw.T-HW-STEP-04'                          => 'Added because short sleep makes us hungrier.',
		'hw.T-HW-STEP-05'                          => "Added because stress can lead to eating when we're not hungry.",
		'hw.T-HW-STEP-06'                          => 'Starting where you are: {minutes} minutes.',
		'hw.T-HW-STEP-06.very_active'              => "Keep your usual exercise. We've added strength twice a week.",
		'hw.T-HW-ALC-01'                           => 'Added because drinks add calories and can lead to more snacking and poorer sleep.',
		'hw.T-HW-ALC-01.title.free_day'            => 'Pick one alcohol-free day this week',
		'hw.T-HW-ALC-01.title.second_day'          => 'Add a second alcohol-free day',
		'hw.T-HW-ALC-01.title.one_fewer'           => 'Have one drink fewer on one day this week',
		'hw.R-HW-ALC-02.button'                    => 'Add a drink',
		'hw.T-HW-FOOD-02.quick'                    => 'Quick to make',
		'hw.T-HW-FOOD-02.low_cost'                 => 'Low cost',
		'hw.T-HW-FOOD-02.cuisine'                  => '{cuisine} dish',
		'hw.T-HW-MOT-01'                           => 'For {reason}: {weeks} weeks to go.',
		'hw.T-HW-MOT-01.event'                     => "By {event}, you're on course for about {kg} kg.",
		'hw.T-HW-MOT-02.5pct'                      => "You've reached your first milestone: {kg} kg. Losing 5 % of your weight can lower blood pressure and blood sugar, so this is a real health change.",
		'hw.T-HW-MOT-02.10pct'                     => "You've reached 10 % of your starting weight: {kg} kg. That brings bigger benefits again, for blood sugar, blood pressure and your joints.",
		'hw.T-HW-MOT-02.healthy'                   => "You're now in the healthy weight range for your height.",
		'hw.T-HW-MOT-02.goal'                      => "You've reached your goal of {kg} kg. Next, we'll help you keep it there. [Start Keep It Off]",
		'hw.T-HW-WEEK-01a'                         => 'Busy weeks happen. Next week your daily steps use the easier versions.',
		'hw.T-HW-WEEK-01b'                         => "Family life can make it harder. Next week's note has ideas for getting the people you live with on side.",
		'hw.T-HW-WEEK-01c'                         => "Cravings rise and fall, like a wave. Next week includes a planned snack, so you're ready for them.",
		'hw.T-HW-WEEK-01d'                         => 'Stress makes all of this harder. Next week includes a short step to help with it.',
		'hw.T-HW-WEEK-01e'                         => 'Social events are part of life. Next week includes your eating-out plan.',
		'hw.T-HW-WEEK-01f'                         => "We hope you're feeling better. Next week's steps are easier, and your plan won't change because of this week.",
		'hw.T-HW-WEEK-01g'                         => 'Good week. Your plan carries on as it is.',
		'hw.T-HW-WEEK-02'                          => "Your weight has stayed about the same for 3 weeks. That's normal, and it doesn't mean the plan has stopped working. [Start 'Breaking a plateau'] [Keep my current plan]",
		'hw.T-HW-BLOCK-01'                         => 'Suggested for you, because {reason}.',
		'hw.T-HW-BLOCK-01.reason.plateau'          => 'your weight has stayed about the same for 3 weeks',
		'hw.T-HW-BLOCK-01.reason.festival'         => '{festival} starts in {n} weeks',
		'hw.T-HW-BLOCK-01.reason.barrier'          => 'you said {barrier} gets in the way',
		'hw.T-HW-BLOCK-01.reason.stress'           => 'you rated your stress {n} out of 10',
		'hw.T-HW-BLOCK-01.reason.sleep'            => 'you usually sleep less than 7 hours',
		'hw.T-HW-BLOCK-01.reason.alcohol'          => 'of how often you drink',
		'hw.T-HW-BLOCK-01.reason.eating_out'       => 'you eat out 3 or more times a week',
		'hw.T-HW-BLOCK-01.reason.shift_work'       => 'you work shifts or nights',
		'hw.T-HW-BLOCK-01.reason.getting_stronger' => "it builds on everything you've done so far",
		'hw.T-HW-REM-01'                           => 'Your next step is ready on Lifeline Daily.',
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
	 * The 36 Healthy Weight programme rules (catalogue section 9; v1.2 D3-58, R-HW-BLOCK-03 v1.3 D3-59). They build and
	 * adjust the member's Healthy Weight plan, so they are class `programme`: never counted in the 3-a-week insight
	 * quota (QUOTA_PER_WEEK applies to insights only) and not run by the nightly engine; `LLD_Members_HW_Where`,
	 * `LLD_Members_HW_Rules` and `LLD_Members_HW_Festival` apply them. `group` and `order` give the section 9 precedence
	 * (safety 1; the signed Today steps R-STEP-01 to -05 are 2; numbers and pace 3; steps, alcohol, food, notes,
	 * reminders and motivation 4; weekly changes and focus blocks 5). `tier` is the owner's rule question 12: free
	 * (safety, the numbers, pace, milestones, the reason on Today, the food-rule and allergy filter) or premium
	 * (personalised step order and versions, extra steps, notes brought forward, food preferences, focus block
	 * suggestions); the six rules question 12 did not name follow Owner Question 163 (10 Nov 2026): R-HW-STEP-06 Premium
	 * (the R-HW-SAFE-04 limits stay free), R-HW-ALC-02 free, R-HW-FOOD-03 Premium (its safety lines free), R-HW-REM-01
	 * free, R-HW-WEEK-01 split (`tiers`: the question free, the changes to next week Premium, the illness wording free),
	 * R-HW-QOD-01 free. `low_mood` true: the rule keeps running in the 7 days after
	 * R-SAFE-04 (R-HW-SAFE, the calorie target and the low-mood note); every other R-HW rule pauses. `templates` are
	 * HW_TEMPLATES keys.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function programme_rules() {
		$r = function ( $id, $key, $group, $order, $tier, array $templates, $low_mood = false, $version = '1.2' ) {
			return array(
				'id'               => $id,
				'key'              => $key,
				'class'            => self::CLASS_PROGRAMME,
				'group'            => $group,
				'order'            => $order,
				'tier'             => $tier,
				'templates'        => $templates,
				'low_mood'         => $low_mood,
				'counted_in_quota' => false,
				'version'          => $version,
			);
		};
		return array(
			'R-HW-SAFE-01'  => $r( 1001, 'hw_who_can_start', 'safety', 1, 'free', array(), true ),
			'R-HW-SAFE-02'  => $r( 1002, 'hw_safe_goal_and_pace', 'safety', 1, 'free', array(), true ),
			'R-HW-SAFE-03'  => $r( 1003, 'hw_stress_sleep_card', 'safety', 1, 'free', array( 'hw.T-HW-SAFE-03' ), true ),
			'R-HW-SAFE-04'  => $r( 1004, 'hw_doctor_line', 'safety', 1, 'free', array(), true ),
			'R-HW-SAFE-05'  => $r( 1005, 'hw_drinking_card', 'safety', 1, 'free', array( 'hw.T-HW-SAFE-05' ), true ),
			'R-HW-NUM-01'   => $r( 1011, 'hw_bands', 'numbers', 3, 'free', array() ),
			'R-HW-NUM-02'   => $r( 1012, 'hw_range', 'numbers', 3, 'free', array( 'hw.T-HW-NUM-02' ) ),
			'R-HW-NUM-03'   => $r( 1013, 'hw_calorie_target', 'numbers', 3, 'free', array(), true ),
			'R-HW-NUM-04'   => $r( 1014, 'hw_milestones', 'numbers', 3, 'free', array( 'hw.T-HW-NUM-04', 'hw.T-HW-NUM-04.label.5pct', 'hw.T-HW-NUM-04.label.10pct', 'hw.T-HW-NUM-04.label.healthy', 'hw.T-HW-NUM-04.label.goal' ) ),
			'R-HW-NUM-05'   => $r( 1015, 'hw_drink_calories', 'numbers', 3, 'free', array( 'hw.T-HW-NUM-05' ) ),
			'R-HW-PACE-01'  => $r( 1021, 'hw_pace', 'pace', 3, 'free', array( 'hw.T-HW-PACE-01', 'hw.T-HW-PACE-01.heading.gentle', 'hw.T-HW-PACE-01.heading.steady', 'hw.T-HW-PACE-01.heading.faster' ) ),
			'R-HW-STEP-01'  => $r( 1031, 'hw_barrier_first', 'steps', 4, 'premium', array( 'hw.T-HW-STEP-01' ) ),
			'R-HW-STEP-02'  => $r( 1032, 'hw_habits_left_out', 'steps', 4, 'premium', array( 'hw.T-HW-STEP-02' ) ),
			'R-HW-STEP-03'  => $r( 1033, 'hw_step_version', 'steps', 4, 'premium', array() ),
			'R-HW-STEP-04'  => $r( 1034, 'hw_sleep_step', 'steps', 4, 'premium', array( 'hw.T-HW-STEP-04' ) ),
			'R-HW-STEP-05'  => $r( 1035, 'hw_stress_step', 'steps', 4, 'premium', array( 'hw.T-HW-STEP-05' ) ),
			'R-HW-STEP-06'  => $r( 1036, 'hw_exercise_level', 'steps', 4, 'premium', array( 'hw.T-HW-STEP-06', 'hw.T-HW-STEP-06.very_active' ) ),
			'R-HW-ALC-01'   => $r( 1041, 'hw_alcohol_step', 'alcohol', 4, 'premium', array( 'hw.T-HW-ALC-01', 'hw.T-HW-ALC-01.title.free_day', 'hw.T-HW-ALC-01.title.second_day', 'hw.T-HW-ALC-01.title.one_fewer' ) ),
			'R-HW-ALC-02'   => $r( 1042, 'hw_add_a_drink', 'alcohol', 4, 'free', array( 'hw.R-HW-ALC-02.button' ), false, '1.3.1' ),
			'R-HW-FOOD-01'  => $r( 1051, 'hw_food_rules', 'food', 4, 'free', array() ),
			'R-HW-FOOD-02'  => $r( 1052, 'hw_food_preferences', 'food', 4, 'premium', array( 'hw.T-HW-FOOD-02.quick', 'hw.T-HW-FOOD-02.low_cost', 'hw.T-HW-FOOD-02.cuisine' ) ),
			'R-HW-FOOD-03'  => $r( 1053, 'hw_fasting_meals', 'food', 4, 'premium', array() ),
			'R-HW-NOTE-01'  => $r( 1061, 'hw_note_low_mood', 'notes', 4, 'premium', array(), true ),
			'R-HW-NOTE-02'  => $r( 1062, 'hw_note_sleep_stress', 'notes', 4, 'premium', array() ),
			'R-HW-NOTE-03'  => $r( 1063, 'hw_note_missed_log', 'notes', 4, 'premium', array() ),
			'R-HW-NOTE-04'  => $r( 1064, 'hw_note_weight_up', 'notes', 4, 'premium', array() ),
			'R-HW-NOTE-05'  => $r( 1065, 'hw_note_plateau', 'notes', 4, 'premium', array() ),
			'R-HW-REM-01'   => $r( 1071, 'hw_reminder_times', 'reminders', 4, 'free', array( 'hw.T-HW-REM-01' ) ),
			'R-HW-MOT-01'   => $r( 1081, 'hw_reason_on_today', 'motivation', 4, 'free', array( 'hw.T-HW-MOT-01', 'hw.T-HW-MOT-01.event' ) ),
			'R-HW-MOT-02'   => $r( 1082, 'hw_milestone_reached', 'motivation', 4, 'free', array( 'hw.T-HW-MOT-02.5pct', 'hw.T-HW-MOT-02.10pct', 'hw.T-HW-MOT-02.healthy', 'hw.T-HW-MOT-02.goal' ) ),
			'R-HW-WEEK-01'  => array_merge(
				$r( 1091, 'hw_what_got_in_the_way', 'weekly', 5, 'split', array( 'hw.T-HW-WEEK-01a', 'hw.T-HW-WEEK-01b', 'hw.T-HW-WEEK-01c', 'hw.T-HW-WEEK-01d', 'hw.T-HW-WEEK-01e', 'hw.T-HW-WEEK-01f', 'hw.T-HW-WEEK-01g' ) ),
				array(
					'tiers' => array(
						'question'        => 'free',
						'next_week'       => 'premium',
						'illness_wording' => 'free',
					),
				)
			),
			'R-HW-WEEK-02'  => $r( 1092, 'hw_plateau', 'weekly', 5, 'premium', array( 'hw.T-HW-WEEK-02' ) ),
			'R-HW-BLOCK-01' => $r( 1101, 'hw_block_suggested', 'blocks', 5, 'premium', array( 'hw.T-HW-BLOCK-01' ) ),
			'R-HW-BLOCK-02' => $r( 1102, 'hw_festival_offer', 'blocks', 5, 'premium', array() ),
			'R-HW-BLOCK-03' => $r( 1103, 'hw_festival_timing', 'blocks', 5, 'premium', array(), false, '1.3' ),
			'R-HW-QOD-01'   => $r( 1104, 'hw_quick_questions', 'blocks', 5, 'free', array() ),
		);
	}

	/**
	 * A template's text from TEMPLATES or HW_TEMPLATES, or null.
	 *
	 * @param string $template Template key.
	 * @return string|null
	 */
	public static function text( $template ) {
		if ( isset( self::TEMPLATES[ $template ] ) ) {
			return self::TEMPLATES[ $template ];
		}
		return isset( self::HW_TEMPLATES[ $template ] ) ? self::HW_TEMPLATES[ $template ] : null;
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
		$text = self::text( $template );
		if ( null === $text ) {
			return null;
		}
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
		$text = self::text( $template );
		if ( null === $text ) {
			return array();
		}
		preg_match_all( '/\{([a-z_]+)\}/', $text, $m );
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
