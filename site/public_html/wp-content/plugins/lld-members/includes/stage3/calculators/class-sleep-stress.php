<?php
/**
 * Sleep and Stress Check engine (P3-5A part 3, FR3-54), written from the owner-signed method sheet
 * `04 Health Tools/Stage 3/P3-5A-Fitness-Age-HR-Zones-Sleep-Stress-Method-Sheet.md` (approval record
 * `approvals/stage3/sleep-stress.json`, fixtures `fixtures/sleep-stress/`).
 *
 * Pure scoring: no WordPress, no database, nothing stored or logged. The answers (the self-harm question, GAD-2 and
 * PHQ-2) are never stored (D3-30); `toSave` carries the only values the caller may keep, and only if the member
 * chooses to save.
 *
 * The Stage 2 self-harm question first (FR2-38): Yes shows Stage 2 Response 1 alone, with no scores and no track
 * offer. GAD-2 (stress and worry) and PHQ-2 (low mood), 0-6 each, scored as published, >= 3 worth attention
 * (D3-28, D3-29). A 4-item insomnia-symptom count from the ICSD-3 / DSM-5 criteria, not called a validated test:
 * Q5 trouble sleeping 3+ nights a week; Q6 for 3+ months (counts only with Q5); Q7 daytime effect; Q8 usual sleep
 * under 7 hours. 0-1 no major concern, 2 some concern, 3-4 needs attention. It screens and signposts; it never
 * diagnoses. Frozen: any change needs a new decision and a new version (NFR3-14).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine. `compute()` takes the answers and returns the result the screen and the tests read.
 */
class LLD_Members_Sleep_Stress {

	const VERSION = '1.0';

	/** Thresholds (sheet 3.3). */
	const ATTENTION     = 3;   // GAD-2 and PHQ-2
	const SLEEP_SOME    = 2;
	const SLEEP_NEEDS   = 3;
	const SHORT_SLEEP_H = 7;   // under 7 hours scores 1 (AASM / SRS consensus)
	const VERY_SHORT_H  = 5;   // under 5 hours: the short-sleep note with "needs attention"

	/** The questions in order (sheet 3.2): the keys of MESSAGES that the screen shows as the form. */
	const QUESTIONS = array( 'q0', 'gad2.stem', 'q1', 'q2', 'q3', 'q4', 'scale.0', 'scale.1', 'scale.2', 'scale.3', 'q5', 'q6', 'q7', 'q8' );

	/**
	 * The signed wording (sheet sections 3.2 and 3.3). Keys and text are hashed in the approval record's
	 * copy_hashes; the test checks them.
	 */
	const MESSAGES = array(
		'q0'                      => 'Have you had any thoughts of harming yourself or ending your life?',
		'gad2.stem'               => 'over the last 2 weeks, how often have you been bothered by',
		'q1'                      => 'Feeling nervous, anxious or on edge.',
		'q2'                      => 'Not being able to stop or control worrying.',
		'q3'                      => 'Little interest or pleasure in doing things.',
		'q4'                      => 'Feeling down, depressed or hopeless.',
		'scale.0'                 => 'Not at all',
		'scale.1'                 => 'Several days',
		'scale.2'                 => 'More than half the days',
		'scale.3'                 => 'Nearly every day',
		'q5'                      => 'Do you have trouble falling asleep, staying asleep, or waking too early, on 3 or more nights a week?',
		'q6'                      => 'Has this been going on for 3 months or more?',
		'q7'                      => 'Does poor sleep affect your day (tiredness, mood, concentration)?',
		'q8'                      => 'How many hours do you usually sleep a night?',
		'result.stress.usual'     => 'Stress and worry: in the usual range',
		'result.stress.attention' => 'Stress and worry: worth attention',
		'result.stress.doctor'    => 'A score of 3 or more is the level at which doctors suggest talking it through. Please consider seeing your doctor.',
		'result.mood.attention'   => 'Low mood: worth attention',
		'result.sleep.none'       => 'Sleep: no major concern',
		'result.sleep.some'       => 'Sleep: some concern',
		'result.sleep.needs'      => 'Sleep: needs attention',
		'result.sleep.insomnia'   => "Sleep problems on 3 or more nights a week for 3 months are what doctors call insomnia. It's treatable; your doctor can help, often without medicines (CBT-I).",
		'result.sleep.shortSleep' => "Under 5 hours a night is very short sleep: most adults need 7 to 9 hours. If you often feel very sleepy during the day, tell your doctor, and don't drive or use machinery when you feel drowsy.", // CW-19
	);

	/**
	 * Wording reused verbatim from the Stage 2 screening (FR2-38, NFR2-07), drafted in
	 * `02 Content/Programme content/safety/screening-sleep-and-stress.md` and approved with this sheet (sign-off
	 * section 4): Response 1 (self-harm: yes), Response 3 (low mood) and the "Need help now?" footer. Each is that
	 * file's quoted block byte for byte, with only the leading "> " of each line removed and lines joined with "\n";
	 * the button lines are kept as the file writes them ("**[Find support in your country]** (button, opens
	 * https://findahelpline.com)", "[Back to my dashboard]", "[Continue]") for the screen to render as buttons.
	 * The test pins each one's SHA-256.
	 */
	const QUOTED = array(
		'response1'  => "**Please get support now**\n\n**If you are in danger now, call your local emergency number**, for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, or 000 in Australia.\n\nTo find a free, confidential helpline in your country, by phone, text or chat:\n\n**[Find support in your country]** (button, opens https://findahelpline.com)\n\nThank you for telling us. You don't have to cope with these thoughts alone, and talking to someone can help. A doctor can also help you find the right support.\n\nWe won't start Sleep and Stress today. Your account and everything else on Lifeline Daily are still here for you.\n\n[Back to my dashboard]",
		'response3'  => "**Please talk to a doctor about your mood**\nFeeling low most days for 2 weeks or more is worth talking to a doctor about. They can help you find out what's going on and what support is available. This track offers general sleep and stress habits and isn't a treatment for low mood or depression.\n\nIf things get worse, or you have thoughts of harming yourself, get help straight away: call your local emergency number or visit findahelpline.com.\n\nYou can still start the track if you'd like to.\n\n[Continue]",
		'helpFooter' => '**Need help now?** If you are in danger now, call your local emergency number (for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, 000 in Australia), or find support in your country at findahelpline.com.',
	);

	/**
	 * Work out the result. The self-harm answer is read first: a Yes returns Response 1 whatever else was answered.
	 *
	 * @param array $in q0 (yes|no), q1 to q4 (0 to 3), q5 (yes|no), q6 (yes|no; asked only when q5 is yes), q7 (yes|no),
	 *                  q8 (hours, 0 to 16).
	 * @return array response1, stressScore, moodScore, sleepCount, stressResult, moodResult, sleepResult (wording keys),
	 *               stressDoctorLine, insomniaLine, shortSleepNote, helpline, trackOffer, trackWeeks, headline, toSave,
	 *               messages (the wording keys to show, in order), version; or error.
	 */
	public static function compute( array $in ) {
		$out = array(
			'response1'        => false,
			'stressScore'      => null,
			'moodScore'        => null,
			'sleepCount'       => null,
			'stressResult'     => null,
			'moodResult'       => null,
			'sleepResult'      => null,
			'stressDoctorLine' => false,
			'insomniaLine'     => false,
			'shortSleepNote'   => false,
			'helpline'         => false,
			'trackOffer'       => false,
			'trackWeeks'       => array(),
			'headline'         => null,
			'toSave'           => null,
			'messages'         => array(),
			'version'          => self::VERSION,
		);
		$q0 = self::yes_no( isset( $in['q0'] ) ? $in['q0'] : null );
		if ( null === $q0 ) {
			return self::invalid( 'q0' );
		}
		if ( 'yes' === $q0 ) {
			// Response 1 only: no scores, no track offer in that session (FR2-38). Nothing is kept.
			$out['response1'] = true;
			$out['messages']  = array( 'quoted.response1' );
			return $out;
		}
		$items = array();
		foreach ( array( 'q1', 'q2', 'q3', 'q4' ) as $q ) {
			$v = isset( $in[ $q ] ) && is_numeric( $in[ $q ] ) ? $in[ $q ] + 0 : null;
			if ( ! is_int( $v ) || $v < 0 || $v > 3 ) {
				return self::invalid( $q );
			}
			$items[ $q ] = $v;
		}
		$q5 = self::yes_no( isset( $in['q5'] ) ? $in['q5'] : null );
		if ( null === $q5 ) {
			return self::invalid( 'q5' );
		}
		$q6 = 'yes' === $q5 ? self::yes_no( isset( $in['q6'] ) ? $in['q6'] : null ) : null; // Q6 is asked only after Q5 Yes
		if ( 'yes' === $q5 && null === $q6 ) {
			return self::invalid( 'q6' );
		}
		$q7 = self::yes_no( isset( $in['q7'] ) ? $in['q7'] : null );
		if ( null === $q7 ) {
			return self::invalid( 'q7' );
		}
		$q8 = isset( $in['q8'] ) && is_numeric( $in['q8'] ) ? (float) $in['q8'] : null;
		if ( null === $q8 || $q8 < 0 || $q8 > 16 ) {
			return self::invalid( 'q8' );
		}

		$stress = $items['q1'] + $items['q2'];
		$mood   = $items['q3'] + $items['q4'];
		$sleep  = ( 'yes' === $q5 ? 1 : 0 ) + ( 'yes' === $q5 && 'yes' === $q6 ? 1 : 0 ) + ( 'yes' === $q7 ? 1 : 0 ) + ( $q8 < self::SHORT_SLEEP_H ? 1 : 0 );

		$out['stressScore'] = $stress;
		$out['moodScore']   = $mood;
		$out['sleepCount']  = $sleep;
		$stress_attention   = $stress >= self::ATTENTION;
		$mood_attention     = $mood >= self::ATTENTION;
		$sleep_level        = $sleep >= self::SLEEP_NEEDS ? 2 : ( $sleep >= self::SLEEP_SOME ? 1 : 0 );

		$out['stressResult']     = $stress_attention ? 'result.stress.attention' : 'result.stress.usual';
		$out['moodResult']       = $mood_attention ? 'result.mood.attention' : null; // the sheet words only "worth attention"
		$out['sleepResult']      = array( 'result.sleep.none', 'result.sleep.some', 'result.sleep.needs' )[ $sleep_level ];
		$out['stressDoctorLine'] = $stress_attention;
		$out['insomniaLine']     = 2 === $sleep_level;
		$out['shortSleepNote']   = 2 === $sleep_level && $q8 < self::VERY_SHORT_H;
		$out['helpline']         = $stress_attention || $mood_attention || 2 === $sleep_level; // FR3-54 AS-02
		$out['trackOffer']       = true;

		// Track pointer (sheet 3.3): stress -> week 2 (general support or attention); sleep none -> week 1;
		// some or needs -> weeks 1 and 3.
		$weeks = array( 2, 1 );
		if ( $sleep_level > 0 ) {
			$weeks[] = 3;
		}
		sort( $weeks );
		$out['trackWeeks'] = $weeks;

		// The combined headline is the higher of the concerns (stress first on a tie, then mood, then sleep).
		$levels = array(
			$out['stressResult'] => $stress_attention ? 2 : 0,
		);
		if ( $mood_attention ) {
			$levels[ $out['moodResult'] ] = 2;
		}
		$levels[ $out['sleepResult'] ] = $sleep_level;
		$best                          = max( $levels );
		$out['headline']               = array_search( $best, $levels, true );

		$out['toSave'] = array(
			'stressScore' => $stress,
			'moodScore'   => $mood,
			'sleepCount'  => $sleep,
		);

		$keys = array( $out['stressResult'] );
		if ( $stress_attention ) {
			$keys[] = 'result.stress.doctor';
		}
		if ( $mood_attention ) {
			$keys[] = 'result.mood.attention';
			$keys[] = 'quoted.response3';
		}
		$keys[] = $out['sleepResult'];
		if ( $out['insomniaLine'] ) {
			$keys[] = 'result.sleep.insomnia';
		}
		if ( $out['shortSleepNote'] ) {
			$keys[] = 'result.sleep.shortSleep'; // CW-19, after the insomnia line
		}
		if ( $out['helpline'] ) {
			$keys[] = 'quoted.helpFooter';
		}
		$out['messages'] = $keys;
		return $out;
	}

	/**
	 * The signed text for wording keys (`quoted.*` from QUOTED). Unknown keys are left out.
	 *
	 * @param string[] $keys   Wording keys, as compute() returns them (or QUESTIONS for the form).
	 * @param array    $result The compute() result (unused).
	 * @return array<string,string>
	 */
	public static function wording( array $keys, array $result = array() ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, 'quoted.' ) && isset( self::QUOTED[ substr( $key, 7 ) ] ) ) {
				$out[ $key ] = self::QUOTED[ substr( $key, 7 ) ];
			} elseif ( isset( self::MESSAGES[ $key ] ) ) {
				$out[ $key ] = self::MESSAGES[ $key ];
			}
		}
		return $out;
	}

	/**
	 * A yes-or-no answer, the same in all three P3-5A engines: "yes" / "no", true / false or "true" / "false" (any
	 * case); anything else, including a missing answer, is null (refused), so "no" never counts as yes.
	 *
	 * @param mixed $value Answer.
	 * @return string|null yes, no or null.
	 */
	private static function yes_no( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$v = strtolower( trim( $value ) );
		$map = array( 'yes' => 'yes', 'true' => 'yes', 'no' => 'no', 'false' => 'no' );
		return isset( $map[ $v ] ) ? $map[ $v ] : null;
	}

	/**
	 * @param string $field The field that failed.
	 */
	private static function invalid( $field ) {
		return array(
			'error'   => $field,
			'version' => self::VERSION,
		);
	}
}
