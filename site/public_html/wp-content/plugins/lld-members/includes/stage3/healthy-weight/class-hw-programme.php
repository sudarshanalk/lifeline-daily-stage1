<?php
/**
 * Healthy Weight programme wiring (P3-35, package P2; FR3-97 to FR3-110, FR3-20, FR3-36).
 *
 * Joins a member to the programme and answers what the screens (package P4) show, by calling the pure P1 engine
 * (LLD_Members_HW_Where, LLD_Members_HW_Rules, LLD_Members_HW_Festival) with the member's answers, logs and the approved
 * content, and storing what the data model keeps: the pace on the goal, the milestones, the notes shown, the block
 * choices and the check-in barriers. The programme's running state (the note queue, the notes still to bring forward,
 * each week's version, a festival day map) sits in the current plan's log_schedule under "hw", beside the Stage 3
 * goal journey's own schedule.
 *
 * Content (notes, questions, activities' habit tags and versions, focus blocks, the festival calendar) comes from
 * LLD_Members_HW_Content, built by package P3. Every call to it is null-safe: without it the programme still runs on the
 * Stage 2 track content and the engine's own ids.
 *
 * Shapes agreed with package P4 (screens):
 *   plan_view(): {where{bmi, category, adjusted, range, waist}, activity, tdee, target_kcal,
 *                 pace{recommended, chosen, rule, reasons[]}, drinks{weekly, daily}|null, milestones[{kind, kg, date}],
 *                 cards[], reasons[]}
 *   today():     {note{key, title, body, try_this, support}, step{activity_key, version, reason}, qod[≤2],
 *                 drink_shortcut, repeat_meal, reason_line}
 * A reasons[] item and a cards[] item are {rule, key, text}. Health values never leave through anything but these
 * member-scoped answers: no email, analytics event, log line or admin page reads them.
 *
 * Tiers (owner question 12 and Q163): safety, numbers, pace, milestones, food rules, the quick questions, reminder
 * timing and "Add a drink" are Free; personalising the steps and notes, the focus blocks, the fasting meal plan and the
 * check-in's changes to next week are Premium. A Free member gets the track's own order in the standard version, with
 * the R-HW-SAFE-04 swaps.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The programme for one member.
 */
class LLD_Members_HW_Programme {

	const TRACK_1 = 'healthy-weight';
	const TRACK_2 = 'healthy-weight-2';
	const KEEP    = 'keep-it-off';

	/** Level 1 activities are HW-Wn-Ak, level 2 HW2-Wn-Ak (approved HW-P3-35-06; fixture track-content.json). */
	const PREFIX = array(
		self::TRACK_1 => 'HW',
		self::TRACK_2 => 'HW2',
	);

	/** Owner question 163 for the rules question 12 does not name; the rest follow the catalogue's tier. */
	const TIERS = array(
		'R-HW-STEP-06' => 'premium',
		'R-HW-ALC-02'  => 'free',
		'R-HW-FOOD-03' => 'premium',
		'R-HW-REM-01'  => 'free',
		'R-HW-WEEK-01' => 'free', // the question; its changes to next week are Premium
		'R-HW-QOD-01'  => 'free',
	);

	/** Data model milestone kinds, by the engine's. */
	const KINDS = array(
		'5pct'    => 'five_percent',
		'10pct'   => 'ten_percent',
		'healthy' => 'healthy_range',
		'goal'    => 'goal',
	);

	/** Barrier choices at the check-in (FR3-107). */
	const BARRIERS = array( 'time', 'work', 'family', 'cravings', 'stress', 'social', 'illness', 'nothing' );

	/** Festival kinds that make a day a fasting day for "no grains or pulses on fasting days", and the meal-plan fast. */
	const FASTING_KINDS = array( 'dawn_to_sunset_fast', 'one_day_fast', 'food_avoiding_fast' );

	/** The member's reason as the approved T-HW-MOT-01 lines say it (HW-QW-03). */
	const REASONS = array(
		'health'     => 'your health',
		'confidence' => 'feeling more confident',
		'family'     => 'keeping up with your family',
	);
	const EVENTS  = array(
		'wedding' => 'your wedding',
		'holiday' => 'your holiday',
		'event'   => 'your event',
	);

	/** Built in this request: activities by member, week plans by member and week, and a re-entry guard. */
	private static $activities = null;
	private static $plans      = array();
	private static $busy       = false;
	/** Contexts for the day-items hook, by member (cleared by every write). */
	private static $ctx = array();

	public static function boot() {
		add_filter( 'lld_members_day_items', array( __CLASS__, 'day_items' ), 10, 3 );
	}

	/**
	 * Forget the week plans built in this request (an answer changed).
	 */
	public static function flush() {
		self::$plans = array();
		self::$ctx   = array();
	}

	/**
	 * Free or Premium for a programme rule.
	 *
	 * @param string $rule Rule id.
	 */
	public static function tier( $rule ) {
		if ( isset( self::TIERS[ $rule ] ) ) {
			return self::TIERS[ $rule ];
		}
		$rules = class_exists( 'LLD_Members_Rule_Catalogue' ) ? LLD_Members_Rule_Catalogue::programme_rules() : array();
		return isset( $rules[ $rule ]['tier'] ) && 'premium' === $rules[ $rule ]['tier'] ? 'premium' : 'free';
	}

	// ---- Joining (FR3-97, FR3-98, FR3-100, FR3-101, FR3-102) --------------------------------------------------------

	/**
	 * Join Healthy Weight with a weight goal: save the answers, the pace, the milestones and the target, and start the
	 * level 1 track. $answers holds question keys (LLD_Members_HW_Answers::KEYS; a key present with null is skipped),
	 * plus chosen_rate (kg a week, optional: the recommendation otherwise) and reminders_on (bool).
	 *
	 * @param int   $member_id Member number.
	 * @param int   $goal_id   The member's main lose_weight goal.
	 * @param array $answers   Answers and options.
	 * @return array|WP_Error plan_view() on success. Errors: lld_hw_consent (nothing stored), lld_hw_goal,
	 *                        lld_hw_not_offered (data: {stop, screen}), lld_hw_pace (data: {refusal, safe_pace}).
	 */
	public static function join( $member_id, $goal_id, $answers = array() ) {
		$member_id = (int) $member_id;
		// Package P4 calls join( $member, {pace, today} ) after saving the answers one by one: the goal is the member's
		// active weight-loss goal and the pace is the one they chose.
		if ( is_array( $goal_id ) ) {
			$opts    = $goal_id;
			$answers = array();
			if ( isset( $opts['pace'] ) && is_numeric( $opts['pace'] ) ) {
				$answers['chosen_rate'] = (float) $opts['pace'];
			}
			if ( array_key_exists( 'reminders_on', $opts ) ) {
				$answers['reminders_on'] = $opts['reminders_on'];
			}
			if ( isset( $opts['target_date'] ) && is_string( $opts['target_date'] ) ) {
				$answers['target_date'] = $opts['target_date']; // "or pick a date instead" (FR3-100)
			}
			$goal_id = 0;
			foreach ( LLD_Members_Goal_Journey::goals( $member_id ) as $g ) {
				if ( 'main' === (string) $g['role'] && 'lose_weight' === (string) $g['goal_type'] && 'active' === (string) $g['status'] ) {
					$goal_id = (int) $g['id'];
					break;
				}
			}
		}
		$answers   = is_array( $answers ) ? $answers : array();
		$facts     = LLD_Members_Goal_Journey::facts( $member_id );
		if ( empty( $facts['health'] ) ) {
			return new WP_Error( 'lld_hw_consent', 'Health consent is needed first.' );
		}
		$goal = LLD_Members_Stage3_Data::row( 'goals', $member_id, (int) $goal_id, LLD_Members_Goal_Journey::SEALED_GOAL );
		if ( ! $goal || 'lose_weight' !== (string) $goal['goal_type'] || 'active' !== (string) $goal['status'] ) {
			return new WP_Error( 'lld_hw_goal', 'Healthy Weight follows a weight-loss goal.' );
		}
		// Already joined with this goal: nothing is reset (a second tap, a resent form). A new goal has a new plan with
		// no programme state, so joining after it starts fresh.
		$current = LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] );
		if ( $current && ! empty( $current['schedule']['hw'] ) ) {
			return self::plan_view( $member_id, $facts['today'] );
		}
		$known = LLD_Members_HW_Answers::engine( $member_id, $facts );
		$elig  = LLD_Members_HW_Rules::eligibility(
			array(
				'pregnant'        => $known['pregnant'],
				'eating_disorder' => $known['eating_disorder'],
				'age'             => $facts['age'],
			)
		);
		if ( ! $elig['offered'] ) {
			return new WP_Error( 'lld_hw_not_offered', 'Healthy Weight is not offered.', array( 'stop' => $elig['stop'], 'screen' => $elig['screen'] ) );
		}
		// The pace is checked before anything is stored, so a refused pace changes nothing (TC-FR3-100-AS-05).
		$pending = array();
		foreach ( $answers as $key => $value ) {
			if ( isset( LLD_Members_HW_Answers::KEYS[ $key ] ) ) {
				$clean = null === $value ? null : LLD_Members_HW_Answers::clean( $key, $value );
				if ( $clean instanceof WP_Error ) {
					return $clean;
				}
				$pending[ $key ] = $clean;
			}
		}
		$preview = array_merge( $known, self::engine_preview( $pending ) );
		$by_date = isset( $answers['target_date'] ) && is_string( $answers['target_date'] ) && '' !== $answers['target_date'];
		$pace    = self::pace_for( $preview, $facts, $goal, $by_date || ! isset( $answers['chosen_rate'] ) ? null : $answers['chosen_rate'] );
		if ( $pace['refused'] ) {
			return new WP_Error( 'lld_hw_pace', 'That pace is above your safe limit.', array( 'refusal' => $pace['refusal'], 'safe_pace' => $pace['safe_pace'] ) );
		}
		if ( $by_date ) {
			// A date instead of a pace (FR3-100): the signed P3-56 timeline checks the implied rate against the cap, keeps
			// the member's date when it is safe and refuses it with the earliest safe date when it is not.
			$check = LLD_Members_Goal_Rules::weight_check(
				array(
					'current_kg'  => is_numeric( $goal['start_value'] ) ? (float) $goal['start_value'] : $facts['weight_kg'],
					'goal_kg'     => is_numeric( $goal['target_value'] ) ? (float) $goal['target_value'] : null,
					'height_cm'   => $facts['height_cm'],
					'target_date' => (string) $answers['target_date'],
					'today'       => (string) $facts['today'],
					'situations'  => (array) $facts['situations'],
					'imperial'    => ! empty( $facts['imperial'] ),
					'eating_pnts' => 'pnts' === $known['eating_disorder'],
				)
			);
			if ( ! empty( $check['ok'] ) && 'pnts' === $known['eating_disorder'] && (float) $check['rate'] > 0.5 + 1e-9 ) {
				// HWS-01b: "prefer not to say" keeps the pace at 0.25 or 0.5 by a date too. The signed timeline's date path
				// checks only the cap, so the implied rate is put to its rate path, which refuses it with the signed message.
				$check = LLD_Members_Goal_Rules::weight_check(
					array(
						'current_kg'  => is_numeric( $goal['start_value'] ) ? (float) $goal['start_value'] : $facts['weight_kg'],
						'goal_kg'     => is_numeric( $goal['target_value'] ) ? (float) $goal['target_value'] : null,
						'height_cm'   => $facts['height_cm'],
						'rate'        => (float) $check['rate'],
						'today'       => (string) $facts['today'],
						'situations'  => (array) $facts['situations'],
						'imperial'    => ! empty( $facts['imperial'] ),
						'eating_pnts' => true,
					)
				);
			}
			if ( empty( $check['ok'] ) ) {
				return new WP_Error( 'lld_hw_pace', (string) $check['message'], array( 'code' => $check['code'], 'offered' => $check['offered'] ) );
			}
			$pace['chosen'] = (float) $check['rate'];
			$pace['date']   = (string) $check['target_date'];
		}
		foreach ( $pending as $key => $clean ) {
			$saved = LLD_Members_HW_Answers::set( $member_id, $key, $clean, 'joining' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		$facts  = LLD_Members_Goal_Journey::facts( $member_id ); // the activity level may have changed
		$engine = LLD_Members_HW_Answers::engine( $member_id, $facts );
		LLD_Members_Goal_Journey::save_pace( $member_id, (int) $goal['id'], $pace['recommended'], $pace['chosen'], self::rule_id( 'R-HW-PACE-01' ), $pace['date'] );
		$goal = LLD_Members_Stage3_Data::row( 'goals', $member_id, (int) $goal['id'], LLD_Members_Goal_Journey::SEALED_GOAL );
		$where = self::where( $facts, $engine );
		LLD_Members_Goal_Journey::save_milestones( $member_id, $goal, self::milestone_list( $goal, $facts, $where, $pace['chosen'], $facts['today'] ) );
		$plan = LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] );
		$state = array(
			'joined'   => $facts['today'],
			'level'    => 1,
			'queue'    => self::default_notes(),
			'pending'  => array(),
			'versions' => array(),
		);
		if ( $plan ) {
			LLD_Members_Goal_Journey::retarget( $member_id, $facts, $pace['chosen'], 'calorie_count' );
			self::save_state( $member_id, $state );
		}
		if ( class_exists( 'LLD_Members_Programme' ) && LLD_Members_Programme::track_exists( self::TRACK_1 ) ) {
			$started = LLD_Members_Programme::start( $member_id, self::TRACK_1 );
			unset( $started ); // a member already on the track, or not allowed it, keeps the plan; the screen shows why
		}
		if ( class_exists( 'LLD_Members_Reminders' ) ) {
			LLD_Members_Reminders::sync_hw( $member_id, $engine, ! empty( $answers['reminders_on'] ) );
		}
		self::flush();
		return self::plan_view( $member_id, $facts['today'] );
	}

	/**
	 * Whether the member has joined (a weight goal whose current plan holds the programme state).
	 *
	 * @param int $member_id Member number.
	 */
	public static function joined( $member_id ) {
		return null !== self::state( $member_id );
	}

	// ---- The plan screen ------------------------------------------------------------------------------------------

	/**
	 * What the plan screen shows (shape in the class comment). Before joining it previews the plan for the member's active
	 * weight-loss goal (the pace step shows the recommended pace and milestones, FR3-100, FR3-101); null without one.
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $today     Local date, or null for the member's today.
	 * @return array|null
	 */
	public static function plan_view( $member_id, $today = null ) {
		$c = self::context( $member_id, $today, true );
		if ( ! $c ) {
			return null;
		}
		if ( $c['stop'] ) {
			return self::stopped_plan( $c );
		}
		$facts  = $c['facts'];
		$a      = $c['answers'];
		$ed     = 'no' !== $a['eating_disorder'];
		$where  = self::where( $facts, $a );
		$goal   = $c['goal'];
		$chosen = is_numeric( $goal['chosen_rate'] ) ? (float) $goal['chosen_rate'] : null;
		$pace   = self::pace_for( $a, $facts, $goal, $chosen, isset( $c['state']['joined'] ) ? $c['state']['joined'] : null );
		$target = null;
		$tdee   = null;
		if ( ! $ed ) {
			$t    = LLD_Members_HW_Rules::target( self::body( $facts, $a ) + array( 'pace' => null === $chosen ? 0.5 : $chosen ) );
			$tdee = isset( $t['tdee_shown'] ) ? $t['tdee_shown'] : null;
			if ( $c['reached'] ) {
				// Keep It Off (FR3-20, owner 10 Nov 2026): the P3-55 maintain target, no deficit.
				$target = LLD_Members_Goal_Journey::maintain_target( $facts );
			} else {
				$target = $t['target'];
			}
		}
		$drinks = LLD_Members_HW_Rules::drinks( $a + array( 'target' => $target ) );
		$cards  = array();
		$sc     = LLD_Members_HW_Rules::support_cards( $a + self::occasion( $member_id, $c ) );
		foreach ( $sc['cards'] as $card ) {
			$cards[] = array(
				'rule'     => $card['rule'],
				'key'      => $card['template'],
				'template' => $card['template'],
				'text'     => $card['text'],
			);
		}
		$state     = $c['state'];
		$celebrate = null;
		// The celebration shows once, on the member's own today (marking it shown is a write), never in the low-mood pause.
		if ( ! $ed && $c['live'] && ! self::paused( $c, 'R-HW-MOT-02' ) && ! empty( $state['celebrate'] ) ) {
			$card = self::celebration( $member_id, $c, (string) $state['celebrate'] );
			if ( $card ) {
				$cards[]   = $card;
				$celebrate = array(
					'kind'  => (string) $state['celebrate'],
					'rule'  => 'R-HW-MOT-02',
					'text'  => $card['text'],
					'reask' => isset( $state['reask'] ) ? (array) $state['reask'] : array(),
				);
			}
		}
		// The plateau card is Premium (R-HW-WEEK-02) and pauses in the low-mood week.
		if ( ! $ed && $c['premium'] && ! self::paused( $c, 'R-HW-WEEK-02' ) && ! empty( $state['plateau'] ) && empty( $sc['no_weight_message'] ) ) {
			$cards[] = array(
				'rule'     => 'R-HW-WEEK-02',
				'key'      => 'hw.T-HW-WEEK-02',
				'template' => 'hw.T-HW-WEEK-02',
				'text'     => LLD_Members_Rule_Catalogue::text( 'hw.T-HW-WEEK-02' ),
			);
		}
		$reasons = array(
			array(
				'rule' => 'R-HW-PACE-01',
				'key'  => 'hw.T-HW-PACE-01.heading.' . $pace['step'],
				'text' => $pace['heading'],
			),
			array(
				'rule' => 'R-HW-PACE-01',
				'key'  => 'hw.T-HW-PACE-01',
				'text' => $pace['because'],
			),
		);
		if ( $drinks['line'] && ! $ed && ! self::paused( $c, 'R-HW-NUM-05' ) ) {
			$reasons[] = array(
				'rule' => 'R-HW-NUM-05',
				'key'  => 'hw.T-HW-NUM-05',
				'text' => $drinks['line']['text'],
			);
		}
		$pos = self::position( $member_id, $c );
		if ( 'level' === $pos['stage'] && $c['premium'] && ! self::paused( $c, 'R-HW-STEP-02' ) ) {
			$week = self::week_plan( $member_id, $c, $pos['programme_week'] );
			if ( $week['notice'] ) {
				$reasons[] = array(
					'rule' => 'R-HW-STEP-02',
					'key'  => $week['notice'],
					'text' => LLD_Members_Rule_Catalogue::text( $week['notice'] ),
				);
			}
		}
		foreach ( array( 'doctor_line' => 'R-HW-SAFE-04', 'diabetes_exercise_note' => 'R-HW-SAFE-04', 'moderate_only' => 'R-HW-SAFE-04' ) as $flag => $rule ) {
			if ( ! empty( $sc[ $flag ] ) ) {
				$reasons[] = array(
					'rule' => $rule,
					'key'  => $flag,
					'text' => null, // approved wording held by the content (P3)
				);
			}
		}
		$milestones = array();
		if ( ! $ed ) {
			foreach ( self::milestone_list( $goal, $facts, $where, null === $chosen ? $pace['chosen'] : $chosen, isset( $state['joined'] ) ? $state['joined'] : $c['today'] ) as $m ) {
				$milestones[] = array(
					'kind' => $m['kind'],
					'kg'   => $m['kg'],
					'date' => $m['date'],
				);
			}
		}
		$pace_reasons = array();
		foreach ( $pace['reasons'] as $r ) {
			$pace_reasons[] = array(
				'rule' => 'R-HW-PACE-01',
				'key'  => $r['key'],
				'text' => LLD_Members_Rule_Catalogue::fill( $r['key'], $r['values'] ),
			);
		}
		return array(
			'where'       => array(
				'bmi'      => isset( $where['bmi'] ) ? $where['bmi'] : null,
				'category' => isset( $where['category'] ) ? $where['category'] : null,
				'adjusted' => ! empty( $where['adjusted'] ),
				'range'    => isset( $where['range'] ) ? $where['range'] : null,
				'waist'    => isset( $where['waist'] ) ? $where['waist'] : null,
			),
			'activity'    => $a['activity_level'],
			'tdee'        => $tdee,
			'target_kcal' => $target,
			'pace'        => array(
				'recommended' => is_numeric( $goal['recommended_rate'] ) ? (float) $goal['recommended_rate'] : $pace['recommended'],
				'chosen'      => null === $chosen ? $pace['chosen'] : $chosen,
				'rule'        => 'R-HW-PACE-01',
				'reasons'     => $pace_reasons,
				'paces'       => $pace['paces'],
			),
			'drinks'      => ( $ed || null === $drinks['weekly_kcal'] ) ? null : array(
				'weekly' => $drinks['weekly_kcal'],
				'daily'  => $drinks['daily_kcal'],
			),
			'milestones'  => $milestones,
			'cards'       => $cards,
			'reasons'     => $reasons,
			// Package P4: the celebration being shown now (once), and the milestone kinds already celebrated.
			'milestone_reached' => $celebrate,
			'celebrated'        => $ed ? array() : self::celebrated_kinds( $member_id, $c ),
		);
	}

	/**
	 * The plan view when R-HW-SAFE-01 stops the programme: the SCR-26 stop and its matrix note, with no number at all.
	 *
	 * @param array $c context().
	 */
	private static function stopped_plan( array $c ) {
		return array(
			'where'             => array(
				'bmi'      => null,
				'category' => null,
				'adjusted' => false,
				'range'    => null,
				'waist'    => null,
			),
			'activity'          => $c['answers']['activity_level'],
			'tdee'              => null,
			'target_kcal'       => null,
			'pace'              => null,
			'drinks'            => null,
			'milestones'        => array(),
			'cards'             => array(),
			'reasons'           => array(),
			'milestone_reached' => null,
			'celebrated'        => array(),
			'stopped'           => true,
			'stop'              => self::stop_view( $c['stop'] ),
		);
	}

	/**
	 * Today when R-HW-SAFE-01 stops the programme: nothing but the stop.
	 *
	 * @param array $c context().
	 */
	private static function stopped_today( array $c ) {
		return array(
			'note'           => null,
			'step'           => null,
			'qod'            => array(),
			'drink_shortcut' => array(
				'rule'  => 'R-HW-ALC-02',
				'show'  => false,
				'label' => null,
				'from'  => null,
			),
			'repeat_meal'    => null,
			'reason_line'    => null,
			'carry_on'       => false,
			'cards'          => array(),
			'stopped'        => true,
			'stop'           => self::stop_view( $c['stop'] ),
		);
	}

	/**
	 * The engine kinds of the goal's milestones already celebrated.
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 * @return string[]
	 */
	private static function celebrated_kinds( $member_id, array $c ) {
		$back = array_flip( self::KINDS );
		$out  = array();
		foreach ( LLD_Members_Goal_Journey::milestone_rows( $member_id, (int) $c['goal']['id'] ) as $r ) {
			if ( ! empty( $r['celebrated'] ) ) {
				$out[] = isset( $back[ $r['kind'] ] ) ? $back[ $r['kind'] ] : (string) $r['kind'];
			}
		}
		return $out;
	}

	// ---- Today ------------------------------------------------------------------------------------------------------

	/**
	 * What the Today screen shows (shape in the class comment). Null when the member has not joined. The day's note is
	 * written by nightly(), run here at first sight when it has not run yet (as LLD_Members_Goal_Journey::today_step()).
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $today     Local date, or null for the member's today.
	 * @return array|null
	 */
	public static function today( $member_id, $today = null ) {
		$c = self::context( $member_id, $today );
		if ( ! $c ) {
			return null;
		}
		if ( $c['stop'] ) {
			return self::stopped_today( $c );
		}
		$today = $c['today'];
		$a     = $c['answers'];
		$ed    = 'no' !== $a['eating_disorder'];
		$shown = self::note_row( $member_id, $today );
		if ( ! $shown && $c['live'] ) {
			// First sight of the member's own today: the nightly part runs now. Another date is only read.
			self::nightly( $member_id, $today );
			$c     = self::context( $member_id, $today );
			$shown = self::note_row( $member_id, $today );
		}
		$note = null;
		if ( $shown ) {
			$n    = self::note_content( (string) $shown['note_key'] );
			$note = array(
				'key'      => (string) $shown['note_key'],
				'title'    => isset( $n['title'] ) ? (string) $n['title'] : null,
				'body'     => isset( $n['body'] ) ? (string) $n['body'] : null,
				'try_this' => isset( $n['try_this'] ) ? (string) $n['try_this'] : null,
				'support'  => in_array( (string) $shown['note_key'], LLD_Members_HW_Rules::SUPPORT_NOTES, true ) || ! empty( $n['mood_related'] ),
			);
		}
		$qs  = LLD_Members_HW_Answers::qod_state( $member_id );
		$qod = array();
		$q   = LLD_Members_HW_Rules::qod( $qs + array( 'eating_disorder' => $a['eating_disorder'] ) );
		if ( self::paused( $c, 'R-HW-QOD-01' ) ) {
			$q['questions'] = array(); // no quick questions in the low-mood pause
		}
		$asks = array(); // a group key (food_rules_allergies) becomes the answer keys it asks (food_rules, fasts, allergies)
		foreach ( $q['questions'] as $group ) {
			$left = LLD_Members_HW_Answers::group_keys( $member_id, $group );
			if ( $left ) { // one question per group a day, the group's first still to answer (R-HW-QOD-01 order)
				$asks[ $left[0] ] = $q['rules'][ $group ];
			}
		}
		$q['rules'] = $asks;
		foreach ( array_keys( $asks ) as $key ) {
			$content = class_exists( 'LLD_Members_HW_Content' ) && method_exists( 'LLD_Members_HW_Content', 'question' ) ? LLD_Members_HW_Content::question( $key ) : null;
			$qod[]   = array(
				'key'     => $key,
				'rule'    => $q['rules'][ $key ],
				'wording' => is_array( $content ) ? ( isset( $content['title'] ) ? $content['title'] : ( isset( $content['wording'] ) ? $content['wording'] : null ) ) : null,
				'reason'  => is_array( $content ) ? ( isset( $content['why'] ) ? $content['why'] : ( isset( $content['reason'] ) ? $content['reason'] : null ) ) : null,
				'choices' => is_array( $content ) && isset( $content['choices'] ) ? $content['choices'] : null,
			);
		}
		$entries   = LLD_Members_Stage3_Data::rows( 'food_log', $member_id, array(), '', 2000 );
		$alcoholic = LLD_Members_Food_Log::alcoholic_food_ids( $entries, $today );
		$counts    = ! $ed && 'calorie_count' === ( isset( $c['plan']['eating_method'] ) ? $c['plan']['eating_method'] : 'calorie_count' );
		$now       = self::local_time( $member_id );
		// The clock time goes over the answers: the answers' own 'time' key is the time-for-the-plan question.
		$shortcut  = LLD_Members_HW_Rules::drink_shortcut(
			array(
				'counts_calories'    => $counts,
				'time'               => $now,
				'sleep_time'         => self::sleep_time( $a ),
				'drink_logged_today' => LLD_Members_Food_Log::drink_logged( $entries, $today, $alcoholic ),
			) + $a
		);
		if ( self::paused( $c, 'R-HW-ALC-02' ) ) {
			$shortcut['show'] = false;
		}
		$repeat = $counts && $c['premium'] ? LLD_Members_Food_Log::repeat_meal( $entries, $today ) : null;
		return array(
			'note'           => $note,
			'step'           => self::step_for( $member_id, $c, $today ),
			'qod'            => array_slice( $qod, 0, 2 ),
			'drink_shortcut' => array(
				'rule'  => $shortcut['rule'],
				'show'  => $shortcut['show'],
				'label' => $shortcut['label'],
				'from'  => $shortcut['from'],
			),
			'repeat_meal'    => $repeat,
			'reason_line'    => $ed || self::paused( $c, 'R-HW-MOT-01' ) ? null : self::reason_line( $c ),
			// Package P4: the "Carry on to week 5" button (Q161) and the free support cards.
			'carry_on'       => self::position( $member_id, $c, $today )['carry_on'],
			'cards'          => self::support_card_list( $member_id, $c ),
		);
	}

	/**
	 * The weight-loss goal whose pace is still to choose (S6): saved at the goal step, the programme not joined yet.
	 * Today asks the member to finish choosing a pace.
	 *
	 * @param int $member_id Member number.
	 * @return int|null The goal id, or null.
	 */
	public static function pending_pace( $member_id ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return null;
		}
		$goal = self::hw_goal( $member_id );
		if ( ! $goal || 'active' !== (string) $goal['status'] || is_numeric( $goal['chosen_rate'] ) ) {
			return null;
		}
		$plan = LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] );
		if ( ! $plan || ! empty( $plan['schedule']['hw'] ) ) {
			return null;
		}
		$c = self::context( $member_id, null, true );
		return $c && ! $c['stop'] ? (int) $goal['id'] : null;
	}

	/**
	 * The free support cards (R-HW-SAFE-03, -05) as {rule, key, template, text}.
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 */
	private static function support_card_list( $member_id, array $c ) {
		$out = array();
		$sc  = LLD_Members_HW_Rules::support_cards( $c['answers'] + self::occasion( $member_id, $c ) );
		foreach ( $sc['cards'] as $card ) {
			$out[] = array(
				'rule'     => $card['rule'],
				'key'      => $card['template'],
				'template' => $card['template'],
				'text'     => $card['text'],
			);
		}
		return $out;
	}

	/**
	 * Log the meal the member often repeats again today, with one tap (FR3-102 AS-06): a copy as stored, through the
	 * food log's copy (Premium, FR3-32).
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $today     Local date.
	 * @return array|WP_Error {copied, entries, day}
	 */
	public static function repeat_meal( $member_id, $today = null ) {
		$member_id = (int) $member_id;
		if ( ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return new WP_Error( 'lld_hw_consent', 'Health consent is needed first.' );
		}
		$today = null === $today ? LLD_Members_Goal_Journey::facts( $member_id )['today'] : (string) $today;
		$meal  = LLD_Members_Food_Log::repeat_meal( LLD_Members_Stage3_Data::rows( 'food_log', $member_id, array(), '', 2000 ), $today );
		if ( ! $meal ) {
			return new WP_Error( 'lld_hw_repeat', 'There is no meal to repeat yet.' );
		}
		$r = LLD_Members_Food_Log::copy_into(
			$member_id,
			array(
				'from_date' => $meal['from_date'],
				'to_date'   => $today,
				'meal'      => $meal['meal'],
			)
		);
		return isset( $r['error'] ) ? new WP_Error( 'lld_premium', $r['error'] ) : $r;
	}

	/**
	 * The day's step: the festival day map, a focus block's day, or the week plan of levels 1 and 2.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $c         context().
	 * @param string $date      Local date.
	 * @return array{activity_key:string|null,version:string,reason:string|null}|null
	 */
	private static function step_for( $member_id, array $c, $date ) {
		$state = $c['state'];
		if ( ! empty( $state['festival']['days'][ $date ] ) ) {
			$d = $state['festival']['days'][ $date ];
			return array(
				'activity_key' => isset( $d['step'] ) ? $d['step'] : null,
				'version'      => isset( $d['version'] ) && $d['version'] ? $d['version'] : 'standard',
				'reason'       => null,
			);
		}
		$pos = self::position( $member_id, $c, $date );
		if ( 'block' === $pos['stage'] ) {
			$block = self::content( 'block', $pos['block'] );
			if ( is_array( $block ) && isset( $block['weeks'][ $pos['week'] ]['activities'][ $pos['day'] ]['id'] ) ) {
				return array(
					'activity_key' => (string) $block['weeks'][ $pos['week'] ]['activities'][ $pos['day'] ]['id'],
					'version'      => 'standard',
					'reason'       => null,
				);
			}
			foreach ( is_array( $block ) && isset( $block['activities'] ) ? (array) $block['activities'] : array() as $x ) {
				if ( (int) $x['week'] === $pos['week'] && (int) $x['day'] === $pos['day'] ) {
					return array(
						'activity_key' => (string) $x['id'],
						'version'      => 'standard',
						'reason'       => null,
					);
				}
			}
			return null;
		}
		if ( 'level' !== $pos['stage'] ) {
			return null;
		}
		$plan = self::week_plan( $member_id, $c, $pos['programme_week'] );
		$day  = isset( $plan['days'][ $pos['day'] - 1 ] ) ? $plan['days'][ $pos['day'] - 1 ] : null;
		if ( ! $day ) {
			return null;
		}
		return array(
			'activity_key' => $day['id'],
			'version'      => $day['version'],
			'reason'       => isset( $day['reason']['text'] ) ? $day['reason']['text'] : null,
		);
	}

	/**
	 * The Stage 2 filter of the day's items (owner decision Q160): on a Healthy Weight track day the item becomes the
	 * programme's step. Anything else, or anything unsure, returns the items unchanged, so the Stage 2 programme runs
	 * exactly as before. Never an item the safety matrix leaves out (FR3-103 AS-05).
	 *
	 * @param array  $items     The day's safe approved activities.
	 * @param int    $member_id Member number.
	 * @param string $date      Local date of the programme day.
	 * @return array
	 */
	public static function day_items( $items, $member_id, $date ) {
		if ( self::$busy || ! is_array( $items ) || '' === (string) $date || ! class_exists( 'LLD_Members_Programme' ) ) {
			return $items;
		}
		if ( class_exists( 'LLD_Members_Features' ) && ! LLD_Members_Features::enabled( 'goals', (int) $member_id ) ) {
			return $items;
		}
		self::$busy = true;
		try {
			// One context a request and member: a Stage 2 programme page asks for every day of the track.
			$key = (int) $member_id;
			if ( ! array_key_exists( $key, self::$ctx ) ) {
				self::$ctx[ $key ] = self::context( $member_id, null );
			}
			$c = self::$ctx[ $key ];
			if ( ! $c || $c['stop'] ) {
				return $items; // not joined, or stopped by R-HW-SAFE-01: the Stage 2 items exactly as they are
			}
			$c['today']    = (string) $date;
			$c['live']     = (string) $date === (string) $c['facts']['today'];
			$c['low_mood'] = self::low_mood_on( $c['safe04'], (string) $date );
			$pos = self::position( $member_id, $c, $date );
			if ( 'level' !== $pos['stage'] ) {
				return $items;
			}
			$plan = self::week_plan( $member_id, $c, $pos['programme_week'] );
			$day  = isset( $plan['days'][ $pos['day'] - 1 ] ) ? $plan['days'][ $pos['day'] - 1 ] : null;
			if ( $day && null === $day['id'] && 'R-HW-STEP-02' === $day['rule'] ) {
				// A step the food rules or "never drinks" leave out (the free part of R-HW-STEP-02), with no approved
				// library step to stand in: the track's own item is not shown either.
				return array();
			}
			$snap = $day ? self::snapshot( $member_id, (string) $day['id'] ) : null;
			if ( ! $snap || ( class_exists( 'LLD_Members_Safety' ) && ! LLD_Members_Safety::allows( (int) $member_id, $snap ) ) ) {
				return $items;
			}
			$snap['hw_step'] = array(
				'activity_key' => $day['id'],
				'version'      => $day['version'],
				'rule'         => $day['rule'],
				'reason'       => isset( $day['reason']['text'] ) ? $day['reason']['text'] : null,
			);
			return array( $snap );
		} catch ( Throwable $e ) {
			return $items;
		} finally {
			self::$busy = false;
		}
	}

	// ---- The weekly check-in (FR3-107, R-HW-WEEK-01) ------------------------------------------------------------------

	/**
	 * Save "What got in the way this week?" (both answers sealed; the text is never read, by staff or by a rule) and
	 * return the approved replies. The question is Free; its changes to next week are Premium, except the illness
	 * wording (Q163). Skipping it (no choices, no text) stores nothing and still completes the check-in.
	 *
	 * @param int    $member_id Member number.
	 * @param string $date      The check-in date.
	 * @param array  $choices   BARRIERS values.
	 * @param string $text      Short free text.
	 * @return array{saved:bool,replies:string[]}|WP_Error
	 */
	public static function record_barrier( $member_id, $date, $choices = array(), $text = '' ) {
		$member_id = (int) $member_id;
		if ( is_array( $date ) ) { // package P4: record_barrier( $member, $choices, $today )
			list( $date, $choices ) = array( is_string( $choices ) ? $choices : '', $date );
			$text                   = '';
		}
		$choices = is_array( $choices ) ? $choices : array();
		if ( ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return new WP_Error( 'lld_hw_consent', 'Health consent is needed first.' );
		}
		if ( ! preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $date ) ) {
			return new WP_Error( 'lld_hw_barrier', 'Add a date.' );
		}
		$clean = array();
		foreach ( $choices as $ch ) {
			if ( ! in_array( (string) $ch, self::BARRIERS, true ) ) {
				return new WP_Error( 'lld_hw_barrier', 'Choose one of the answers.' );
			}
			$clean[] = (string) $ch;
		}
		$clean = array_values( array_unique( $clean ) );
		$text  = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) $text ) : trim( (string) $text );
		$text  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 280 ) : substr( $text, 0, 280 );
		if ( ! $clean && '' === $text ) {
			return array(
				'saved'   => false,
				'replies' => array(),
			);
		}
		$row = array(
			'check_in_date'   => (string) $date,
			'barrier_choices' => $clean ? wp_json_encode( $clean ) : null,
			'barrier_text'    => '' === $text ? null : $text,
		);
		$sealed   = array( 'barrier_choices', 'barrier_text' );
		$existing = null;
		foreach ( LLD_Members_Stage3_Data::rows( 'check_in_barriers', $member_id, array(), '', 100 ) as $r ) {
			if ( (string) $r['check_in_date'] === (string) $date ) {
				$existing = $r;
			}
		}
		if ( $existing ) {
			LLD_Members_Stage3_Data::update( 'check_in_barriers', $member_id, (int) $existing['id'], $row, $sealed );
		} else {
			LLD_Members_Stage3_Data::insert( 'check_in_barriers', $member_id, $row, $sealed );
		}
		$effects = LLD_Members_HW_Rules::check_in( array( 'choices' => $clean ) );
		$premium = self::premium( $member_id );
		$state   = self::state( $member_id );
		if ( $premium && is_array( $state ) && $effects['notes'] ) {
			$state['pending'] = array_values( array_unique( array_merge( isset( $state['pending'] ) ? (array) $state['pending'] : array(), $effects['notes'] ) ) );
			self::save_state( $member_id, $state );
		}
		$replies = array();
		foreach ( $effects['replies'] as $i => $key ) {
			if ( $premium || 'hw.T-HW-WEEK-01f' === $key ) {
				$replies[] = $effects['texts'][ $i ];
			}
		}
		self::flush();
		return array(
			'saved'   => true,
			'replies' => $replies,
		);
	}

	/**
	 * The check-in choices that shape a week (the latest check-in before the week starts).
	 *
	 * @param int    $member_id Member number.
	 * @param string $before    First day of the week.
	 * @return string[]
	 */
	private static function barrier_choices( $member_id, $before ) {
		$best = null;
		foreach ( LLD_Members_Stage3_Data::rows( 'check_in_barriers', $member_id, array( 'barrier_choices' ), '', 100 ) as $r ) {
			if ( (string) $r['check_in_date'] <= $before && ( null === $best || (string) $r['check_in_date'] > (string) $best['check_in_date'] ) ) {
				$best = $r;
			}
		}
		$list = $best && $best['barrier_choices'] ? json_decode( (string) $best['barrier_choices'], true ) : array();
		return is_array( $list ) ? $list : array();
	}

	// ---- Levels and focus blocks (Q161, FR3-104, R-HW-BLOCK-01 to -03) ------------------------------------------------

	/**
	 * Where the member is and what comes next: {stage: level|between|block|keep_it_off|none, programme_week, carry_on,
	 * current {block, week, day, started_at}|null, suggested {block, id, reason, text, festival}|null, choices[],
	 * keep_it_off, festival {entry, from, to}|null}. The suggestion is Premium (R-HW-BLOCK-01).
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $today     Local date.
	 * @return array|null
	 */
	public static function blocks( $member_id, $today = null ) {
		$c = self::context( $member_id, $today );
		if ( ! $c ) {
			return null;
		}
		if ( $c['stop'] ) {
			return array(
				'stage'          => 'none',
				'programme_week' => null,
				'carry_on'       => false,
				'current'        => null,
				'suggested'      => null,
				'choices'        => array(),
				'keep_it_off'    => false,
				'due'            => false,
				'festival'       => null,
				'stopped'        => true,
				'stop'           => self::stop_view( $c['stop'] ),
			);
		}
		$pos     = self::position( $member_id, $c );
		$choices = self::block_rows( $member_id, $c );
		$current = null;
		foreach ( $choices as $r ) {
			if ( $r['started_at'] && ! $r['finished_at'] && $r['chosen_block_key'] ) {
				$current = array(
					'block'      => (string) $r['chosen_block_key'],
					'week'       => $pos['week'],
					'day'        => $pos['day'],
					'started_at' => (string) $r['started_at'],
				);
			}
		}
		$out = array(
			'stage'          => $c['reached'] ? 'keep_it_off' : $pos['stage'],
			'programme_week' => $pos['programme_week'],
			'carry_on'       => $pos['carry_on'],
			'current'        => $current,
			'suggested'      => null,
			'choices'        => $pos['programme_week'] > 8 || 'between' === $pos['stage'] ? array_keys( LLD_Members_HW_Rules::BLOCKS ) : array(),
			'keep_it_off'    => $c['reached'],
			'due'            => ! $current && ( 'between' === $pos['stage'] || $c['reached'] ),
			'festival'       => empty( $c['state']['festival'] ) ? null : array(
				'entry' => $c['state']['festival']['entry'],
				'from'  => $c['state']['festival']['from'],
				'to'    => $c['state']['festival']['to'],
			),
		);
		if ( $c['premium'] && ! self::paused( $c, 'R-HW-BLOCK-01' ) && ! $current && ( 'between' === $pos['stage'] || $c['reached'] ) ) {
			$s = LLD_Members_HW_Rules::suggest_block( self::block_input( $member_id, $c, $pos, $choices ) );
			if ( $s['block'] ) {
				$out['suggested'] = array(
					'block'    => $s['block'],
					'id'       => $s['id'],
					'reason'   => $s['reason']['text'],
					'text'     => $s['text'],
					'festival' => $s['festival'],
				);
			}
			$out['keep_it_off'] = $s['keep_it_off'] || $c['reached'];
		}
		return $out;
	}

	/**
	 * Choose a focus block, or decline the festival block for one festival ("Not for this festival", T3 = A). A
	 * festival block is timed by R-HW-BLOCK-03 around the chosen calendar entry. Premium.
	 *
	 * @param int         $member_id Member number.
	 * @param string      $block     Block slug (LLD_Members_HW_Rules::BLOCKS), or "decline_festival".
	 * @param int|null    $entry_id  Festival calendar entry, for the festival block and a decline.
	 * @param string|null $today     Local date.
	 * @return array|WP_Error blocks().
	 */
	public static function choose_block( $member_id, $block, $entry_id = null, $today = null ) {
		$member_id = (int) $member_id;
		if ( null === $today && is_string( $entry_id ) && preg_match( '/^\d{4}-\d\d-\d\d$/', $entry_id ) ) {
			list( $today, $entry_id ) = array( $entry_id, null ); // package P4: choose_block( $member, $slug, $today )
		}
		$c         = self::context( $member_id, $today );
		if ( ! $c ) {
			return new WP_Error( 'lld_hw_block', 'Join Healthy Weight first.' );
		}
		if ( $c['stop'] ) {
			return new WP_Error( 'lld_hw_not_offered', 'Healthy Weight is not offered.', array( 'stop' => $c['stop'], 'screen' => 'SCR-26' ) );
		}
		if ( ! $c['premium'] ) {
			return new WP_Error( 'lld_premium', 'Focus blocks are part of Premium.' );
		}
		$pos     = self::position( $member_id, $c );
		$rows    = self::block_rows( $member_id, $c );
		$suggest = LLD_Members_HW_Rules::suggest_block( self::block_input( $member_id, $c, $pos, $rows ) );
		$now     = lld_members_now_mysql( $member_id );
		$entry   = null;
		if ( null !== $entry_id ) {
			foreach ( self::member_festivals( $c ) as $f ) {
				if ( (string) $f['id'] === (string) $entry_id ) {
					$entry = $f;
				}
			}
			if ( ! $entry ) {
				return new WP_Error( 'lld_hw_block', 'Choose one of your festivals.' );
			}
		}
		if ( null === $entry && LLD_Members_HW_Festival::BLOCK === $block && $suggest['festival'] ) {
			foreach ( self::member_festivals( $c ) as $f ) {
				if ( (string) $f['id'] === (string) $suggest['festival'] ) {
					$entry = $f; // the festival the suggestion was timed to
				}
			}
		}
		if ( 'decline_festival' === $block ) {
			if ( ! $entry ) {
				return new WP_Error( 'lld_hw_block', 'Choose one of your festivals.' );
			}
			LLD_Members_Stage3_Data::insert(
				'block_choices',
				$member_id,
				array(
					'suggested_block_key' => LLD_Members_HW_Festival::BLOCK,
					'rule_id'             => self::rule_id( 'R-HW-BLOCK-02' ),
					'festival_entry_id'   => self::entry_row_id( $entry['id'] ),
					'declined_festival'   => 1,
				)
			);
			return self::blocks( $member_id, $c['today'] );
		}
		if ( ! isset( LLD_Members_HW_Rules::BLOCKS[ $block ] ) ) {
			return new WP_Error( 'lld_hw_block', 'Choose a focus block.' );
		}
		$festival = LLD_Members_HW_Festival::BLOCK === $block;
		if ( $pos['programme_week'] <= 8 && 'between' !== $pos['stage'] ) {
			return new WP_Error( 'lld_hw_block', 'Focus blocks start after week 8.' );
		}
		$running = null;
		foreach ( $rows as $r ) {
			if ( $r['started_at'] && ! $r['finished_at'] && $r['chosen_block_key'] ) {
				$running = $r;
			}
		}
		if ( $running && ! $festival ) {
			return new WP_Error( 'lld_hw_block', 'Finish this block first.' );
		}
		if ( $festival && ! $entry ) {
			return new WP_Error( 'lld_hw_block', 'Choose one of your festivals.' );
		}
		$row = array(
			'suggested_block_key' => $suggest['block'],
			'suggested_block_id'  => $suggest['block'] ? self::track_post( $suggest['block'] ) : null,
			'rule_id'             => self::rule_id( 'R-HW-BLOCK-01' ),
			'chosen_block_key'    => $block,
			'chosen_block_id'     => self::track_post( $block ),
			'started_at'          => $festival ? null : $now,
			'festival_entry_id'   => $entry ? self::entry_row_id( $entry['id'] ) : null,
		);
		$state = $c['state'];
		if ( $festival ) {
			$sched = LLD_Members_HW_Festival::schedule(
				array(
					'start'          => $entry['start'],
					'end'            => $entry['end'],
					'accepted'       => $c['today'],
					'fasts'          => in_array( $entry['kind'], self::FASTING_KINDS, true ),
					'type2_diabetes' => in_array( 'type2_diabetes', $c['answers']['conditions'], true ),
					'current'        => $running ? array(
						'block'     => (string) $running['chosen_block_key'],
						'next_week' => min( 4, $pos['week'] + 1 ),
					) : null,
					'next_block'     => $suggest['block'] && LLD_Members_HW_Festival::BLOCK !== $suggest['block'] ? $suggest['block'] : 'getting-stronger',
				)
			);
			$days = array();
			foreach ( $sched['days'] as $d ) {
				$days[ $d['date'] ] = array_intersect_key( $d, array_flip( array( 'kind', 'block', 'week', 'day', 'step', 'note', 'version' ) ) );
			}
			$state['festival'] = array(
				'entry'  => (int) $entry['id'],
				'from'   => $sched['T'],
				'to'     => $sched['week4_to'],
				'days'   => $days,
				'resume' => $sched['resume'],
			);
			$row['started_at'] = $sched['T'] . ' 00:00:00';
			if ( $running ) {
				LLD_Members_Stage3_Data::update( 'block_choices', $member_id, (int) $running['id'], array( 'paused_at' => $sched['T'] . ' 00:00:00', 'resumed_at' => isset( $sched['resume']['from'] ) ? $sched['resume']['from'] . ' 00:00:00' : null ) );
			}
			self::save_state( $member_id, $state );
		}
		LLD_Members_Stage3_Data::insert( 'block_choices', $member_id, $row );
		self::flush();
		return self::blocks( $member_id, $c['today'] );
	}

	/**
	 * "Carry on to week 5" (Q161 = A): level 1 runs straight into level 2, from the end of week 4.
	 *
	 * @param int $member_id Member number.
	 * @return array|WP_Error blocks().
	 */
	public static function carry_on( $member_id, $today = null ) {
		$member_id = (int) $member_id;
		$c         = self::context( $member_id, $today );
		if ( ! $c ) {
			return new WP_Error( 'lld_hw_carry_on', 'Join Healthy Weight first.' );
		}
		if ( $c['stop'] ) {
			return new WP_Error( 'lld_hw_not_offered', 'Healthy Weight is not offered.', array( 'stop' => $c['stop'], 'screen' => 'SCR-26' ) );
		}
		$pos = self::position( $member_id, $c );
		if ( ! $pos['carry_on'] ) {
			return new WP_Error( 'lld_hw_carry_on', 'Week 5 opens at the end of week 4.' );
		}
		// On day 28 itself level 2 starts tomorrow, so week 4 keeps its last day; once level 1 has finished, today.
		$started = LLD_Members_Programme::start( $member_id, self::TRACK_2, $pos['programme_day'] >= 28 && ! $pos['finished'] ? 'tomorrow' : 'today' );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$state          = $c['state'];
		$state['level'] = 2;
		self::save_state( $member_id, $state );
		self::flush();
		return self::blocks( $member_id, $c['today'] );
	}

	// ---- The nightly run --------------------------------------------------------------------------------------------

	/**
	 * The programme's part of the nightly engine run (LLD_Members_Insights_Runner::run_member): the day's note, the
	 * milestones reached, the plateau and the week's version. During the 7 days after R-SAFE-04 (low mood) only the
	 * kindness note runs: no milestone, plateau or other note rule (rule draft reading 17; catalogue low_mood flags).
	 * Returns only what ran, never a value.
	 *
	 * @param int    $member_id  Member number.
	 * @param string $today      Local date.
	 * @param array  $engine_out The runner's summary (safety[], …), or empty.
	 * @return array{status:string,note:bool,low_mood_pause:bool,milestone:bool,plateau:bool}
	 */
	public static function nightly( $member_id, $today, array $engine_out = array() ) {
		$out = array(
			'status'         => 'not_joined',
			'note'           => false,
			'low_mood_pause' => false,
			'milestone'      => false,
			'plateau'        => false,
		);
		$c = self::context( $member_id, $today );
		if ( ! $c ) {
			return $out;
		}
		if ( $c['stop'] ) {
			$out['status'] = 'stopped'; // R-HW-SAFE-01: nothing runs and nothing is written
			return $out;
		}
		if ( ! $c['live'] ) {
			$out['status'] = 'not_today'; // only the member's own today is written
			return $out;
		}
		$out['status'] = 'ran';
		$today         = $c['today'];
		$a             = $c['answers'];
		$ed            = 'no' !== $a['eating_disorder'];
		$state         = $c['state'];
		$safe04        = in_array( 'R-SAFE-04', isset( $engine_out['safety'] ) ? (array) $engine_out['safety'] : array(), true ) ? $today : $c['safe04'];
		$low           = self::low_mood_on( $safe04, $today );
		$c['safe04']   = $safe04;
		$c['low_mood'] = $low;
		$out['low_mood_pause'] = $low;
		$weights = LLD_Members_Goal_Journey::daily( $member_id, 'weight' );
		$plateau = array( 'fires' => false );
		// The plateau check is Premium and pauses in the low-mood week; whenever it does not run, no plateau is kept.
		$state['plateau'] = false;
		if ( ! $low && ! $ed && $c['premium'] ) {
			$plateau = LLD_Members_HW_Rules::plateau(
				array(
					'today'            => $today,
					'weights'          => $weights,
					'goal_type'        => 'lose_weight',
					'goal_reached'     => $c['reached'],
					'situations'       => $c['facts']['situations'],
					'festival_running' => ! empty( $state['festival']['days'][ $today ] ) && 'festival' === $state['festival']['days'][ $today ]['kind'],
				)
			);
			$state['plateau'] = $plateau['fires'];
			$out['plateau']   = $plateau['fires'];
		}
		if ( ! self::note_row( $member_id, $today ) ) {
			$pos    = self::position( $member_id, $c, $today );
			$shown  = self::shown_map( $member_id );
			$data   = $c['premium'] ? array(
				'safe04_date'        => $safe04,
				'mood_today'         => self::mood_today( $member_id, $today ),
				'sleep'              => $a['sleep'],
				'stress'             => $a['stress'],
				'counts_calories'    => ! $ed && 'calorie_count' === ( isset( $c['plan']['eating_method'] ) ? $c['plan']['eating_method'] : '' ),
				'last_food_date'     => self::last_food_date( $member_id ),
				'weigh_in_this_week' => self::week_weight( $weights, $today, 0 ),
				'weigh_in_last_week' => self::week_weight( $weights, $today, 7 ),
				'plateau'            => $plateau['fires'],
				'pending'            => isset( $state['pending'] ) ? (array) $state['pending'] : array(),
			) : array();
			$n = LLD_Members_HW_Rules::note_for_day(
				$data + array(
					'date'            => $today,
					'programme_day'   => $pos['programme_day'],
					'queue'           => isset( $state['queue'] ) ? (array) $state['queue'] : self::default_notes(),
					'shown'           => $shown,
					'eating_disorder' => $a['eating_disorder'],
				)
			);
			if ( $n['note'] ) {
				LLD_Members_Stage3_Data::insert(
					'notes_shown',
					$member_id,
					array(
						'note_key' => $n['note'],
						'shown_on' => $today,
						'reason'   => $n['rule'] ? 'rule' : 'default',
						'rule_id'  => $n['rule'] ? self::rule_id( $n['rule'] ) : null,
					)
				);
				$out['note'] = true;
			}
			$state['queue'] = $n['queue'];
			if ( $c['premium'] ) {
				$state['pending'] = $n['pending'];
			}
		}
		if ( ! $low && ! $ed ) {
			$out['milestone'] = self::milestones_reached( $member_id, $c, $weights, $state );
		}
		$pos = self::position( $member_id, $c, $today ); // also closes a focus block whose days have passed
		if ( 'level' === $pos['stage'] && ! self::paused( $c, 'R-HW-STEP-03' ) ) {
			$plan = self::week_plan( $member_id, $c, $pos['programme_week'] );
			$state['versions'][ (string) $pos['programme_week'] ] = $plan['version'];
		}
		self::save_state( $member_id, $state );
		self::flush();
		return $out;
	}

	// ---- The API's computed views -----------------------------------------------------------------------------------

	/**
	 * GET views for the members' interface: plan, today, blocks, questions, festivals.
	 *
	 * @param string $which     hw-plan, hw-today, hw-blocks, hw-questions or hw-festivals.
	 * @param int    $member_id Member number.
	 * @param array  $query     date.
	 * @return array
	 */
	public static function view( $which, $member_id, array $query = array() ) {
		$date = isset( $query['date'] ) && preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $query['date'] ) ? (string) $query['date'] : null;
		switch ( $which ) {
			case 'hw-plan':
				$v = self::plan_view( $member_id, $date );
				return null === $v ? array( 'joined' => false ) : $v;
			case 'hw-today':
				$v = self::today( $member_id, $date );
				return null === $v ? array( 'joined' => false ) : $v;
			case 'hw-blocks':
				$v = self::blocks( $member_id, $date );
				return null === $v ? array( 'joined' => false ) : $v;
			case 'hw-questions':
				$state = self::state( $member_id );
				return array(
					'questions' => LLD_Members_HW_Answers::questions(),
					'answered'  => array_keys( LLD_Members_HW_Answers::all( $member_id ) ),
					'recheck'   => is_array( $state ) && ! empty( $state['reask'] ) ? array_values( (array) $state['reask'] ) : array(),
				);
			case 'hw-festivals':
				return array( 'festivals' => self::festivals() );
		}
		return array( 'error' => 'Not available.' );
	}

	// ---- Content ----------------------------------------------------------------------------------------------------

	/**
	 * The approved festival calendar: {id, name, kind, start, end, expected}. From the content loader (P3) when present,
	 * otherwise wp_lld_festival_calendar rows the owner has approved. Content data, no member data.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function festivals() {
		$list = self::content( 'festivals' );
		if ( ! is_array( $list ) ) {
			global $wpdb;
			$list = array();
			if ( isset( $wpdb ) && class_exists( 'LLD_Members_Stage3_Schema' ) ) {
				$rows = $wpdb->get_results( 'SELECT * FROM ' . LLD_Members_Stage3_Schema::table( 'festival_calendar' ) . ' WHERE approved_on IS NOT NULL ORDER BY starts_on ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input.
				$list = is_array( $rows ) ? $rows : array();
			}
		}
		$out = array();
		foreach ( $list as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['id'] ) ) {
				continue;
			}
			if ( array_key_exists( 'approved_on', $f ) && empty( $f['approved_on'] ) ) {
				continue; // members see only approved entries
			}
			$start = isset( $f['start'] ) ? $f['start'] : ( isset( $f['starts_on'] ) ? $f['starts_on'] : null );
			$end   = isset( $f['end'] ) ? $f['end'] : ( isset( $f['ends_on'] ) ? $f['ends_on'] : $start );
			if ( ! $start ) {
				continue;
			}
			// The approved entry key (festival_key, e.g. CAL-RAM-2027) names the entry when the table holds it.
			$id    = isset( $f['festival_key'] ) && '' !== (string) $f['festival_key'] ? (string) $f['festival_key'] : (string) $f['id'];
			$out[] = array(
				'id'       => $id,
				'name'     => isset( $f['name'] ) ? (string) $f['name'] : $id,
				'kind'     => isset( $f['kind'] ) ? (string) $f['kind'] : 'one_day_festival',
				'start'    => (string) $start,
				'end'      => (string) $end,
				'expected' => ! empty( $f['expected'] ),
			);
		}
		return $out;
	}

	/**
	 * The calendar row number of an entry (block_choices.festival_entry_id is that row's id), from its key.
	 *
	 * @param string $id Calendar entry id (its festival_key, or the row number itself).
	 * @return int|null
	 */
	private static function entry_row_id( $id ) {
		if ( is_numeric( $id ) ) {
			return (int) $id;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return null;
		}
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Stage3_Schema::table( 'festival_calendar' ) . ' WHERE festival_key = %s', (string) $id ) );
		return null === $row ? null : (int) $row;
	}

	/**
	 * The entry id festivals() uses for a stored calendar row number (its festival_key when it has one).
	 *
	 * @param int $row_id Calendar row number.
	 */
	private static function entry_key( $row_id ) {
		global $wpdb;
		if ( isset( $wpdb ) && class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			$key = $wpdb->get_var( $wpdb->prepare( 'SELECT festival_key FROM ' . LLD_Members_Stage3_Schema::table( 'festival_calendar' ) . ' WHERE id = %d', (int) $row_id ) );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}
		return (string) (int) $row_id;
	}

	/**
	 * The festival family a calendar entry belongs to: its key without the year or date the content loader adds
	 * ("CAL-RAM-2027" and "CAL-RAM-2028" are both "CAL-RAM"; "CAL-EKA-20270115-V" is "CAL-EKA"). A member keeps a
	 * family, so a fast carries from one year's entry to the next. An entry with no such ending is its own family.
	 *
	 * @param string $id Calendar entry id (its festival_key).
	 */
	public static function festival_family( $id ) {
		return (string) preg_replace( '/-(?:\d{4}|\d{8})(?:-V)?$/', '', (string) $id );
	}

	/**
	 * The festival families of the approved calendar, in calendar order: the choices of the fasts question (HW-QW-14).
	 *
	 * @return string[]
	 */
	public static function festival_families() {
		$out = array();
		foreach ( self::festivals() as $f ) {
			$fam = self::festival_family( $f['id'] );
			if ( ! in_array( $fam, $out, true ) ) {
				$out[] = $fam;
			}
		}
		return $out;
	}

	/**
	 * Whether a member who keeps these families keeps this calendar entry: the entry's family, or a tradition of it
	 * ("CAL-NATF" keeps "CAL-NATF-N-2027"). An entry id saved before families (one year's key) still counts.
	 *
	 * @param array  $fasts Families (or entry ids) the member keeps.
	 * @param string $id    Calendar entry id.
	 */
	public static function keeps( array $fasts, $id ) {
		$fam = self::festival_family( $id );
		foreach ( $fasts as $kept ) {
			$kept = self::festival_family( (string) $kept );
			if ( '' !== $kept && ( $kept === $fam || 0 === strpos( $fam, $kept . '-' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a date is one of the member's fasting days (a fast they keep from the calendar).
	 *
	 * @param array  $fasts Festival families the member keeps (keeps()).
	 * @param string $date  Local date.
	 */
	public static function fasting_day( array $fasts, $date ) {
		foreach ( self::festivals() as $f ) {
			if ( self::keeps( $fasts, $f['id'] ) && in_array( $f['kind'], self::FASTING_KINDS, true ) && $f['start'] <= $date && $date <= $f['end'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The member's dawn-to-sunset fasts as R-HW-FOOD-03 reads them: {id, start, end}.
	 *
	 * @param array $fasts Festival families the member keeps (keeps()): every year's entry of each.
	 * @return array<int,array{id:string,start:string,end:string}>
	 */
	public static function meal_fasts( array $fasts ) {
		$out = array();
		foreach ( self::festivals() as $f ) {
			if ( self::keeps( $fasts, $f['id'] ) && 'dawn_to_sunset_fast' === $f['kind'] ) {
				$out[] = array(
					'id'    => $f['id'],
					'start' => $f['start'],
					'end'   => $f['end'],
				);
			}
		}
		return $out;
	}

	/**
	 * Call the content loader (package P3) when it is there; null otherwise.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed|null
	 */
	private static function content( $method, ...$args ) {
		if ( ! class_exists( 'LLD_Members_HW_Content' ) || ! method_exists( 'LLD_Members_HW_Content', $method ) ) {
			return null;
		}
		return call_user_func_array( array( 'LLD_Members_HW_Content', $method ), $args );
	}

	/** The default order of the 56 notes of weeks 1 to 8: the content's order, or HW-MN-Wn-k (approved HW-MN). */
	private static function default_notes() {
		$out = array();
		foreach ( array( self::TRACK_1, self::TRACK_2 ) as $track ) {
			$notes = self::content( 'notes', $track );
			foreach ( is_array( $notes ) ? $notes : array() as $n ) {
				if ( isset( $n['key'] ) && ! in_array( (string) $n['key'], $out, true ) ) {
					$out[] = (string) $n['key'];
				}
			}
		}
		if ( ! $out ) {
			for ( $i = 0; $i < 56; $i++ ) {
				$out[] = 'HW-MN-W' . ( intdiv( $i, 7 ) + 1 ) . '-' . ( ( $i % 7 ) + 1 );
			}
		}
		return $out;
	}

	/**
	 * A note's approved content by key, or null.
	 *
	 * @param string $key Note key.
	 */
	private static function note_content( $key ) {
		$tracks = array_merge( array( self::TRACK_1, self::TRACK_2 ), array_keys( LLD_Members_HW_Rules::BLOCKS ) );
		foreach ( $tracks as $track ) {
			$notes = self::content( 'notes', $track );
			foreach ( is_array( $notes ) ? $notes : array() as $n ) {
				if ( isset( $n['key'] ) && (string) $n['key'] === $key ) {
					return $n;
				}
			}
		}
		return null;
	}

	/**
	 * The approved activities of levels 1 and 2 for the engine ({id, week, day, tags, high_intensity, alt_id, variants})
	 * and the library, with each one's Stage 2 snapshot by id. Built once a request.
	 *
	 * @param int $member_id Member number.
	 * @return array{activities:array,library:array,snapshots:array}
	 */
	private static function activities( $member_id ) {
		if ( null !== self::$activities ) {
			return self::$activities;
		}
		$out  = array(
			'activities' => array(),
			'library'    => array(),
			'snapshots'  => array(),
		);
		$alts = array();
		foreach ( array( self::TRACK_1 => 0, self::TRACK_2 => 4 ) as $track => $offset ) {
			$tid = LLD_Members_Programme::track_id( $track );
			if ( $tid < 1 ) {
				continue;
			}
			for ( $d = 1; $d <= 28; $d++ ) {
				foreach ( LLD_Members_Programme::activities( $tid, $d, $member_id, false ) as $snap ) {
					$week = (int) ceil( $d / 7 );
					$day  = ( ( $d - 1 ) % 7 ) + 1;
					$meta = self::meta( $snap );
					if ( ! empty( $meta['alt_for'] ) && isset( $meta['key'] ) ) {
						$alts[ (string) $meta['alt_for'] ] = (string) $meta['key']; // an -ALT item stands in, it is not the day's item
						$out['snapshots'][ (string) $meta['key'] ] = $snap;
						continue;
					}
					$id   = isset( $meta['key'] ) ? (string) $meta['key'] : self::PREFIX[ $track ] . '-W' . $week . '-A' . $day;
					$out['activities'][]     = self::engine_item( $id, $meta, $snap ) + array(
						'week' => $week + $offset,
						'day'  => $day,
					);
					$out['snapshots'][ $id ] = $snap;
				}
			}
			foreach ( LLD_Members_Programme::activities( $tid, 0, $member_id, false ) as $snap ) {
				$meta = self::meta( $snap );
				$id   = isset( $meta['key'] ) ? (string) $meta['key'] : 'post-' . (int) $snap['parent_id'];
				if ( ! isset( $out['snapshots'][ $id ] ) ) {
					$out['library'][]        = self::engine_item( $id, $meta, $snap );
					$out['snapshots'][ $id ] = $snap;
				}
			}
		}
		// The approved content marks the steady-walk alternative, not the item: an item with an -ALT item standing in for
		// it is the high-intensity one, never given to members with high blood pressure or heart disease (R-HW-SAFE-04).
		foreach ( $out['activities'] as $i => $x ) {
			if ( isset( $alts[ $x['id'] ] ) && empty( $x['alt_id'] ) ) {
				$out['activities'][ $i ]['alt_id']         = $alts[ $x['id'] ];
				$out['activities'][ $i ]['high_intensity'] = true;
			}
		}
		self::$activities = $out;
		return $out;
	}

	/**
	 * @param array $snap Stage 2 snapshot.
	 * @return array Activity metadata from the content loader, or {}.
	 */
	private static function meta( array $snap ) {
		$m = self::content( 'activity_meta', isset( $snap['parent_id'] ) ? (int) $snap['parent_id'] : 0 );
		$m = is_array( $m ) ? $m : array();
		if ( ! isset( $m['key'] ) && isset( $m['id'] ) && is_string( $m['id'] ) && '' !== $m['id'] ) {
			$m['key'] = $m['id']; // package P3: the approved draft id, for example HW-W1-A1
		}
		return $m;
	}

	/**
	 * @param string $id   Content id.
	 * @param array  $meta activity_meta().
	 * @param array  $snap Snapshot.
	 */
	private static function engine_item( $id, array $meta, array $snap ) {
		$tags = isset( $meta['habit_tags'] ) ? (array) $meta['habit_tags'] : ( isset( $snap['tags'] ) ? (array) $snap['tags'] : array() );
		$item = array(
			'id'   => $id,
			'tags' => array_values( array_map( 'strval', $tags ) ),
		);
		if ( ! empty( $meta['high_intensity'] ) ) {
			$item['high_intensity'] = true;
		}
		if ( ! empty( $meta['alt_id'] ) ) {
			$item['alt_id'] = (string) $meta['alt_id'];
		}
		if ( ! empty( $meta['variants'] ) && is_array( $meta['variants'] ) ) {
			$item['variants'] = $meta['variants'];
		}
		return $item;
	}

	/**
	 * The Stage 2 snapshot for an engine id, or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $id        Content id.
	 */
	private static function snapshot( $member_id, $id ) {
		$all = self::activities( $member_id );
		return isset( $all['snapshots'][ $id ] ) ? $all['snapshots'][ $id ] : null;
	}

	// ---- The week plan ----------------------------------------------------------------------------------------------

	/**
	 * One week of levels 1 and 2 from LLD_Members_HW_Rules::week_plan(). A Free member gets no personalisation: only the
	 * safety swaps (the conditions) reach the engine.
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 * @param int   $week      Programme week 1 to 8.
	 * @return array
	 */
	private static function week_plan( $member_id, array $c, $week ) {
		$week = max( 1, min( 8, (int) $week ) );
		// In the low-mood pause the step rules pause (R-HW-STEP-01 to -06, R-HW-ALC-01): the track's own order in the
		// standard version, as for a Free member, with the safety swaps and the food-rules filter.
		$own  = $c['premium'] && ! self::paused( $c, 'R-HW-STEP-03' );
		$key  = (int) $member_id . ':' . $week . ':' . ( $own ? 'p' : 'f' );
		if ( isset( self::$plans[ $key ] ) ) {
			return self::$plans[ $key ];
		}
		$acts    = self::activities( $member_id );
		$a       = $c['answers'];
		$in      = array();
		if ( $own ) {
			$answers = $a;
		} else {
			// Free: the R-HW-SAFE-04 swaps, and the alcohol part of R-HW-STEP-02 as part of the free food-rules filter
			// (rule question 12): a member who never drinks, or whose food rules exclude alcohol, gets no alcohol step.
			$answers         = array(
				'conditions' => $a['conditions'],
				'food_rules' => $a['food_rules'],
			);
			$in['never_drinks'] = 'never' === $a['alcohol_freq'];
		}
		$history = array();
		if ( $own && $week > 1 ) {
			$state   = $c['state'];
			$history = array(
				'version'  => isset( $state['versions'][ (string) ( $week - 1 ) ] ) ? $state['versions'][ (string) ( $week - 1 ) ] : null,
				'done_pct' => self::done_pct( $member_id, $week - 1 ),
				'check_in' => self::barrier_choices( $member_id, self::week_start( $member_id, $c, $week ) ),
			);
		}
		self::$plans[ $key ] = LLD_Members_HW_Rules::week_plan(
			$in + array(
				'week'       => $week,
				'answers'    => $answers,
				'activities' => $acts['activities'],
				// The approved library fills a slot left out; for Free only the food-rules filter leaves one out.
				'library'    => $acts['library'],
				'history'    => $history,
			)
		);
		return self::$plans[ $key ];
	}

	/**
	 * Last week's percentage done, from the Stage 2 results (FR2-10), or null.
	 *
	 * @param int $member_id Member number.
	 * @param int $week      Programme week 1 to 8.
	 */
	private static function done_pct( $member_id, $week ) {
		$row = LLD_Members_Programme::current( $member_id );
		if ( ! $row ) {
			return null;
		}
		$level = self::TRACK_2 === (string) $row->track_key ? 2 : 1;
		$in    = $week - ( $level - 1 ) * 4;
		if ( $in < 1 || $in > 4 ) {
			return null;
		}
		$was        = self::$busy;
		self::$busy = true; // completion() reads the day items, which must be the track's own here
		$pct        = LLD_Members_Programme::completion( $member_id, $row, $in )['pct'];
		self::$busy = $was;
		return $pct;
	}

	/**
	 * First day of a programme week.
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 * @param int   $week      Programme week.
	 */
	private static function week_start( $member_id, array $c, $week ) {
		$row = LLD_Members_Programme::current( $member_id );
		if ( $row ) {
			$level = self::TRACK_2 === (string) $row->track_key ? 2 : 1;
			$date  = LLD_Members_Programme::day_date( LLD_Members_Programme::periods( (int) $row->id ), ( $week - ( $level - 1 ) * 4 - 1 ) * 7 + 1 );
			if ( '' !== $date ) {
				return $date;
			}
		}
		return $c['today'];
	}

	// ---- Position ---------------------------------------------------------------------------------------------------

	/**
	 * Where the member is on a date: {stage: level|between|block|none, programme_week, programme_day, week, day, block,
	 * carry_on}. Levels 1 and 2 follow the Stage 2 enrolment (level 2's weeks are programme weeks 5 to 8); a focus block
	 * counts from its start.
	 *
	 * @param int         $member_id Member number.
	 * @param array       $c         context().
	 * @param string|null $date      Local date (context's today by default).
	 * @return array
	 */
	private static function position( $member_id, array $c, $date = null ) {
		$date = null === $date ? $c['today'] : $date;
		$out  = array(
			'stage'          => 'none',
			'programme_week' => 0,
			'programme_day'  => 0,
			'week'           => 0,
			'day'            => 0,
			'block'          => null,
			'carry_on'       => false,
			'finished'       => false,
		);
		$done = 0;
		foreach ( self::block_rows( $member_id, $c, $date ) as $r ) {
			if ( ! $r['chosen_block_key'] || ! $r['started_at'] ) {
				continue;
			}
			if ( $r['finished_at'] ) {
				$done++;
				continue;
			}
			$days = LLD_Members_HW_Festival::days_between( substr( (string) $r['started_at'], 0, 10 ), $date );
			if ( ! empty( $r['resumed_at'] ) && $date >= substr( (string) $r['resumed_at'], 0, 10 ) ) {
				$days -= self::paused_days( $r ); // the block resumed after a festival block: its days carry on from there
			}
			if ( $days >= 0 && $days < 28 ) {
				$out['stage']          = 'block';
				$out['block']          = (string) $r['chosen_block_key'];
				$out['week']           = intdiv( $days, 7 ) + 1;
				$out['day']            = ( $days % 7 ) + 1;
				$out['programme_day']  = 56 + 28 * $done + $days + 1;
				$out['programme_week'] = 8 + 4 * $done + $out['week'];
				return $out;
			}
		}
		$row = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::current( $member_id ) : null;
		if ( ! $row || ! isset( self::PREFIX[ (string) $row->track_key ] ) ) {
			if ( $done > 0 ) {
				$out['stage']          = 'between';
				$out['programme_week'] = 8 + 4 * $done + 1;
			}
			return $out;
		}
		$level   = self::TRACK_2 === (string) $row->track_key ? 2 : 1;
		$periods = LLD_Members_Programme::periods( (int) $row->id );
		$d       = (int) LLD_Members_Programme::days_until( $periods, $date );
		$last    = LLD_Members_Programme::day_date( $periods, 28 );
		$over    = 'finished' === (string) $row->status || ( '' !== $last && $date > $last );
		if ( $d < 1 ) {
			return $out;
		}
		$d = min( 28, $d );
		if ( 2 === $level && $over ) {
			$out['stage']          = 'between';
			$out['programme_week'] = 9 + 4 * $done;
			return $out;
		}
		$out['stage']          = 'level';
		$out['programme_day']  = ( $level - 1 ) * 28 + $d;
		$out['week']           = (int) ceil( $d / 7 );
		$out['day']            = ( ( $d - 1 ) % 7 ) + 1;
		$out['programme_week'] = ( $level - 1 ) * 4 + $out['week'];
		$out['carry_on']       = 1 === $level && ( 28 === $d );
		$out['finished']       = $over;
		return $out;
	}

	// ---- Helpers ----------------------------------------------------------------------------------------------------

	/**
	 * Everything the answers need, or null when the member has not joined: facts, answers (engine form), goal, plan,
	 * state, premium, reached, today.
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $today     Local date.
	 * @return array|null
	 */
	private static function context( $member_id, $today, $preview = false ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return null;
		}
		$goal = self::hw_goal( $member_id );
		if ( ! $goal ) {
			return null;
		}
		$plan = LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] );
		if ( ! $plan ) {
			return null;
		}
		// Before joining, only the plan screen previews the plan (the pace step, FR3-100): an active goal, no state yet.
		if ( empty( $plan['schedule']['hw'] ) && ( ! $preview || 'active' !== (string) $goal['status'] ) ) {
			return null;
		}
		$facts   = LLD_Members_Goal_Journey::facts( $member_id );
		$answers = LLD_Members_HW_Answers::engine( $member_id, $facts );
		$date    = null === $today ? (string) $facts['today'] : (string) $today;
		// R-HW-SAFE-01 on the member's situations now, not only when joining: pregnancy or an eating disorder reported
		// later stops the programme (SCR-26, no numbers at all).
		$elig   = LLD_Members_HW_Rules::eligibility(
			array(
				'pregnant'        => $answers['pregnant'],
				'eating_disorder' => $answers['eating_disorder'],
				'age'             => $facts['age'],
			)
		);
		$safe04 = self::safe04_date( $member_id );
		return array(
			'facts'    => $facts,
			'answers'  => $answers,
			'goal'     => $goal,
			'plan'     => $plan,
			'state'    => empty( $plan['schedule']['hw'] ) ? array() : (array) $plan['schedule']['hw'],
			'premium'  => ! empty( $facts['premium'] ),
			'reached'  => 'reached' === (string) $goal['status'],
			'today'    => $date,
			// Writes happen only for the member's own today: a view of another date (?date=) is read-only.
			'live'     => $date === (string) $facts['today'],
			'stop'     => $elig['offered'] ? null : (string) $elig['stop'],
			'safe04'   => $safe04,
			'low_mood' => self::low_mood_on( $safe04, $date ),
		);
	}

	/**
	 * The R-SAFE-04 pause: the 7 days from the day R-SAFE-04 (low mood) last showed.
	 *
	 * @param string|null $safe04 Local date R-SAFE-04 showed, or null.
	 * @param string      $date   Local date.
	 */
	private static function low_mood_on( $safe04, $date ) {
		if ( null === $safe04 ) {
			return false;
		}
		$d = LLD_Members_HW_Festival::days_between( $safe04, $date );
		return $d >= 0 && $d < 7;
	}

	/**
	 * Whether a programme rule is paused today: during the R-SAFE-04 pause every R-HW rule whose catalogue `low_mood`
	 * flag is false pauses (R-HW-SAFE, the calorie target and R-HW-NOTE-01 keep running).
	 *
	 * @param array  $c    context().
	 * @param string $rule Rule id.
	 */
	private static function paused( array $c, $rule ) {
		if ( empty( $c['low_mood'] ) ) {
			return false;
		}
		$rules = LLD_Members_Rule_Catalogue::programme_rules();
		return empty( $rules[ $rule ]['low_mood'] );
	}

	/**
	 * The SCR-26 stop (R-HW-SAFE-01) with the safety matrix's note: {rule, stop, screen, note_key, note}. No number.
	 *
	 * @param string $stop pregnancy, eating or under18.
	 */
	private static function stop_view( $stop ) {
		$keys = array(
			'pregnancy' => 'pregnancy.calorie-target',
			'eating'    => 'ed.weight-goal-screen',
		);
		$key  = isset( $keys[ $stop ] ) ? $keys[ $stop ] : null;
		$text = null;
		if ( null !== $key && class_exists( 'LLD_Members_Stage3_Safety' ) && defined( 'LLD_Members_Stage3_Safety::MESSAGES' ) && isset( LLD_Members_Stage3_Safety::MESSAGES[ $key ] ) ) {
			$text = LLD_Members_Stage3_Safety::MESSAGES[ $key ];
		}
		return array(
			'rule'     => 'R-HW-SAFE-01',
			'stop'     => (string) $stop,
			'screen'   => 'SCR-26',
			'note_key' => $key,
			'note'     => $text,
		);
	}

	/**
	 * The goal the programme follows: the active lose_weight goal, or the latest one reached (Keep It Off offered).
	 *
	 * @param int $member_id Member number.
	 */
	private static function hw_goal( $member_id ) {
		foreach ( LLD_Members_Goal_Journey::goals( $member_id ) as $g ) {
			if ( 'main' === (string) $g['role'] && 'lose_weight' === (string) $g['goal_type'] && in_array( (string) $g['status'], array( 'active', 'reached' ), true ) ) {
				foreach ( array( 'recommended_rate', 'chosen_rate', 'pace_rule_id' ) as $k ) {
					if ( ! array_key_exists( $k, $g ) ) {
						$g[ $k ] = null;
					}
				}
				return $g;
			}
		}
		return null;
	}

	/** @param int $member_id Member number. */
	private static function state( $member_id ) {
		$goal = self::hw_goal( (int) $member_id );
		$plan = $goal ? LLD_Members_Goal_Journey::plan( (int) $member_id, (int) $goal['id'] ) : null;
		return $plan && ! empty( $plan['schedule']['hw'] ) ? (array) $plan['schedule']['hw'] : null;
	}

	/**
	 * Write the programme state into the current plan's schedule (no new plan version: the plan's numbers are unchanged).
	 *
	 * @param int   $member_id Member number.
	 * @param array $state     State.
	 */
	private static function save_state( $member_id, array $state ) {
		$goal = self::hw_goal( (int) $member_id );
		$plan = $goal ? LLD_Members_Goal_Journey::plan( (int) $member_id, (int) $goal['id'] ) : null;
		if ( ! $plan ) {
			return false;
		}
		$schedule       = $plan['schedule'];
		$schedule['hw'] = $state;
		self::$ctx      = array();
		return LLD_Members_Stage3_Data::update( 'plans', (int) $member_id, (int) $plan['id'], array( 'log_schedule' => wp_json_encode( $schedule ) ) );
	}

	/** @param int $member_id Member number. */
	private static function premium( $member_id ) {
		return class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::has_premium( (int) $member_id );
	}

	/**
	 * Engine-form values for answers not yet saved (the join preview).
	 *
	 * @param array $pending key => clean value.
	 */
	private static function engine_preview( array $pending ) {
		$out = array();
		foreach ( array( 'barrier', 'time', 'work', 'confidence', 'sleep', 'stress', 'alcohol_freq', 'alcohol_amount', 'activity_level' ) as $k ) {
			if ( array_key_exists( $k, $pending ) ) {
				$out[ $k ] = 'barrier' === $k && 'not_sure' === $pending[ $k ] ? null : $pending[ $k ];
			}
		}
		return $out;
	}

	/**
	 * R-HW-PACE-01 for the member, with the weeks and date from the goal.
	 *
	 * @param array      $a      Engine answers.
	 * @param array      $facts  facts().
	 * @param array      $goal   Goal row.
	 * @param mixed      $chosen Chosen kg a week, or null.
	 * @param string|null $from  The date the weeks count from: the joining date (today when joining).
	 */
	private static function pace_for( array $a, array $facts, array $goal, $chosen, $from = null ) {
		$in              = $a;
		$in['age']       = $facts['age'];
		$in['weight_kg'] = is_numeric( $goal['start_value'] ) ? (float) $goal['start_value'] : $facts['weight_kg'];
		if ( null !== $chosen && is_numeric( $chosen ) ) {
			$in['chosen'] = (float) $chosen;
		}
		if ( is_numeric( $goal['start_value'] ) && is_numeric( $goal['target_value'] ) ) {
			$in['start_kg'] = (float) $goal['start_value'];
			$in['goal_kg']  = (float) $goal['target_value'];
			$in['today']    = null === $from ? (string) $facts['today'] : (string) $from;
		}
		return LLD_Members_HW_Rules::pace( $in );
	}

	/**
	 * R-HW-NUM-01 and -02 for the member: "where you are now".
	 *
	 * @param array $facts facts().
	 * @param array $a     Engine answers.
	 */
	private static function where( array $facts, array $a ) {
		return LLD_Members_HW_Where::where_now(
			array(
				'weight_kg'       => $facts['weight_kg'],
				'height_cm'       => $facts['height_cm'],
				'waist_cm'        => $facts['waist_cm'],
				'age'             => $facts['age'],
				'pregnant'        => $a['pregnant'],
				'eating_disorder' => $a['eating_disorder'],
				'ethnicity'       => self::ethnicity( (int) $facts['member_id'] ),
				'bmi_background'  => $a['bmi_background'],
			)
		);
	}

	/** @param int $member_id Member number. */
	private static function ethnicity( $member_id ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT ethnicity FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) );
		return null === $v ? null : (string) $v;
	}

	/**
	 * The body numbers for R-HW-NUM-03.
	 *
	 * @param array $facts facts().
	 * @param array $a     Engine answers.
	 */
	private static function body( array $facts, array $a ) {
		return array(
			'sex'             => $facts['sex'],
			'age'             => $facts['age'],
			'height_cm'       => $facts['height_cm'],
			'weight_kg'       => $facts['weight_kg'],
			'activity_level'  => $a['activity_level'] ? $a['activity_level'] : str_replace( '-', '_', (string) $facts['activity'] ),
			'eating_disorder' => $a['eating_disorder'],
			'pregnant'        => $a['pregnant'],
		);
	}

	/**
	 * R-HW-NUM-04 milestones for the goal.
	 *
	 * @param array      $goal  Goal row.
	 * @param array      $facts facts().
	 * @param array      $where where().
	 * @param float      $pace  Chosen pace.
	 * @param string     $from  Date the dates count from.
	 * @return array list of {kind, kg, weeks, date, label, text}
	 */
	private static function milestone_list( array $goal, array $facts, array $where, $pace, $from ) {
		if ( ! is_numeric( $goal['start_value'] ) || ! is_numeric( $goal['target_value'] ) || empty( $facts['height_cm'] ) ) {
			return array();
		}
		$r = LLD_Members_HW_Rules::milestones(
			array(
				'start_kg'  => (float) $goal['start_value'],
				'goal_kg'   => (float) $goal['target_value'],
				'height_cm' => (float) $facts['height_cm'],
				'adjusted'  => ! empty( $where['adjusted'] ),
				'pace'      => $pace ? (float) $pace : 0.5,
				'today'     => (string) $from,
			)
		);
		return $r['milestones'];
	}

	/**
	 * R-HW-MOT-02: the 7-day mean weight against the stored milestones; marks those passed and keeps the furthest for
	 * its celebration (shown once, from plan_view()).
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 * @param array $weights   Daily weights.
	 * @param array $state     State, updated.
	 */
	private static function milestones_reached( $member_id, array $c, array $weights, array &$state ) {
		$rows = LLD_Members_Goal_Journey::milestone_rows( $member_id, (int) $c['goal']['id'] );
		if ( ! $rows ) {
			return false;
		}
		$from = LLD_Members_HW_Festival::add_days( $c['today'], -6 );
		$sum  = 0.0;
		$n    = 0;
		foreach ( $weights as $d => $w ) {
			if ( $d >= $from && $d <= $c['today'] ) {
				$sum += (float) $w;
				$n++;
			}
		}
		$list = array();
		$done = array();
		$back = array_flip( self::KINDS );
		foreach ( $rows as $r ) {
			$kind   = isset( $back[ $r['kind'] ] ) ? $back[ $r['kind'] ] : (string) $r['kind'];
			$list[] = array(
				'kind' => $kind,
				'kg'   => (float) $r['target_value'],
			);
			if ( $r['reached_at'] ) {
				$done[] = $kind;
			}
		}
		$m = LLD_Members_HW_Rules::milestone_reached(
			array(
				'milestones'      => $list,
				'mean7'           => $n ? $sum / $n : null,
				'celebrated'      => $done,
				'eating_disorder' => $c['answers']['eating_disorder'],
			)
		);
		if ( ! $m['kind'] ) {
			return false;
		}
		foreach ( $rows as $r ) {
			$kind = isset( $back[ $r['kind'] ] ) ? $back[ $r['kind'] ] : (string) $r['kind'];
			if ( in_array( $kind, $m['passed'], true ) && ! $r['reached_at'] ) {
				LLD_Members_Stage3_Data::update( 'goal_milestones', $member_id, (int) $r['id'], array( 'reached_at' => lld_members_now_mysql( $member_id ) ) );
			}
		}
		$state['celebrate'] = $m['kind'];
		$state['reask']     = $m['reask'];
		return true;
	}

	/**
	 * The celebration card, once: marks the milestone celebrated (data model: "Shown once").
	 *
	 * @param int    $member_id Member number.
	 * @param array  $c         context().
	 * @param string $kind      Engine kind.
	 * @return array|null
	 */
	private static function celebration( $member_id, array $c, $kind ) {
		foreach ( LLD_Members_Goal_Journey::milestone_rows( $member_id, (int) $c['goal']['id'] ) as $r ) {
			if ( ( isset( self::KINDS[ $kind ] ) ? self::KINDS[ $kind ] : $kind ) !== (string) $r['kind'] || ! empty( $r['celebrated'] ) ) {
				continue;
			}
			LLD_Members_Stage3_Data::update( 'goal_milestones', $member_id, (int) $r['id'], array( 'celebrated' => 1 ) );
			$template = 'hw.T-HW-MOT-02.' . $kind;
			return array(
				'rule' => 'R-HW-MOT-02',
				'key'  => $template,
				'text' => LLD_Members_Rule_Catalogue::fill( $template, array( 'kg' => number_format( (float) $r['target_value'], 1, '.', '' ) ) ),
			);
		}
		return null;
	}

	/**
	 * The support cards' occasion: joining, or the check-in number since joining with "unchanged" answers.
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 */
	private static function occasion( $member_id, array $c ) {
		$joined = isset( $c['state']['joined'] ) ? (string) $c['state']['joined'] : $c['today'];
		$n      = 0;
		foreach ( LLD_Members_Goal_Journey::check_in_dates( $member_id ) as $d ) {
			if ( $d > $joined ) {
				$n++;
			}
		}
		if ( 0 === $n ) {
			return array( 'occasion' => 'joining' );
		}
		$all       = LLD_Members_HW_Answers::details( $member_id );
		$unchanged = true;
		foreach ( array( 'sleep', 'stress', 'alcohol_freq', 'alcohol_amount' ) as $k ) {
			if ( isset( $all[ $k ]['updated_at'] ) && substr( (string) $all[ $k ]['updated_at'], 0, 10 ) > $joined ) {
				$unchanged = false;
			}
		}
		return array(
			'occasion'        => 'check_in',
			'check_in_number' => $n,
			'unchanged'       => $unchanged,
		);
	}

	/**
	 * R-HW-MOT-01: the member's reason on Today, or null ("Something else" has no line).
	 *
	 * @param array $c context().
	 */
	private static function reason_line( array $c ) {
		$a    = $c['answers'];
		$goal = $c['goal'];
		if ( ! $a['reason'] || 'something_else' === $a['reason'] ) {
			return null;
		}
		$event = isset( self::EVENTS[ $a['reason'] ] ) ? self::EVENTS[ $a['reason'] ] : null;
		$in    = array(
			'reason'    => $event ? $event : ( isset( self::REASONS[ $a['reason'] ] ) ? self::REASONS[ $a['reason'] ] : '' ),
			'today'     => $c['today'],
			'goal_date' => $goal['target_date'] ? (string) $goal['target_date'] : null,
		);
		if ( $event && $a['event_date'] && is_numeric( $goal['start_value'] ) ) {
			$in += array(
				'event'      => $event,
				'event_date' => $a['event_date'],
				'start_kg'   => (float) $goal['start_value'],
				'goal_kg'    => is_numeric( $goal['target_value'] ) ? (float) $goal['target_value'] : null,
				'pace'       => is_numeric( $goal['chosen_rate'] ) ? (float) $goal['chosen_rate'] : 0.5,
			);
			$in['today'] = isset( $c['state']['joined'] ) ? $c['state']['joined'] : $c['today'];
		}
		$r = LLD_Members_HW_Rules::motivation( $in );
		return $r['text'];
	}

	/**
	 * The input of R-HW-BLOCK-01.
	 *
	 * @param int   $member_id Member number.
	 * @param array $c         context().
	 * @param array $pos       position().
	 * @param array $rows      block_rows().
	 */
	private static function block_input( $member_id, array $c, array $pos, array $rows ) {
		$a        = $c['answers'];
		$declined = array();
		$finished = array();
		$fest     = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['declined_festival'] ) && $r['festival_entry_id'] ) {
				$declined[] = self::entry_key( $r['festival_entry_id'] );
			}
			if ( $r['finished_at'] && $r['chosen_block_key'] ) {
				$finished[] = (string) $r['chosen_block_key'];
				if ( $r['festival_entry_id'] ) {
					$fest[] = self::entry_key( $r['festival_entry_id'] );
				}
			}
		}
		$festivals = array();
		foreach ( self::member_festivals( $c ) as $f ) {
			$festivals[] = array(
				'id'    => $f['id'],
				'name'  => $f['name'],
				'start' => $f['start'],
			);
		}
		return array(
			'programme_week'     => max( 9, $pos['programme_week'] ),
			'today'              => $c['today'],
			'goal_reached'       => $c['reached'],
			'plateau'            => ! empty( $c['state']['plateau'] ),
			'festivals'          => $festivals,
			'declined_festivals' => $declined,
			'finished_festivals' => $fest,
			'festival_running'   => ! empty( $c['state']['festival']['days'][ $c['today'] ] ),
			'finished'           => $finished,
			'barrier'            => $a['barrier'],
			'stress'             => $a['stress'],
			'sleep'              => $a['sleep'],
			'alcohol_freq'       => $a['alcohol_freq'],
			'alcohol_amount'     => $a['alcohol_amount'],
			'food_rules'         => $a['food_rules'],
			'eats_out'           => $a['eats_out'],
			'work'               => $a['work'],
		);
	}

	/**
	 * The festivals and fasts the member keeps.
	 *
	 * @param array $c context().
	 */
	private static function member_festivals( array $c ) {
		$out = array();
		foreach ( self::festivals() as $f ) {
			if ( self::keeps( (array) $c['answers']['fasts'], $f['id'] ) ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/**
	 * The member's block choices, oldest first. With the context, a block whose days have passed reads as finished on
	 * $date, and on the member's own today finished_at is written (in nightly() and position()).
	 *
	 * @param int         $member_id Member number.
	 * @param array|null  $c         context(), to close finished blocks.
	 * @param string|null $date      Local date (the context's today by default).
	 */
	private static function block_rows( $member_id, $c = null, $date = null ) {
		$rows = LLD_Members_Stage3_Data::rows( 'block_choices', (int) $member_id, array(), '', 100 );
		usort(
			$rows,
			function ( $x, $y ) {
				return (int) $x['id'] <=> (int) $y['id'];
			}
		);
		foreach ( $rows as $i => $r ) {
			foreach ( array( 'chosen_block_key', 'started_at', 'finished_at', 'paused_at', 'resumed_at', 'festival_entry_id', 'declined_festival' ) as $k ) {
				if ( ! isset( $rows[ $i ][ $k ] ) ) {
					$rows[ $i ][ $k ] = null;
				}
			}
		}
		if ( ! is_array( $c ) ) {
			return $rows;
		}
		$date  = null === $date ? (string) $c['today'] : (string) $date;
		$write = ! empty( $c['live'] ) && $date === (string) $c['facts']['today'];
		foreach ( $rows as $i => $r ) {
			if ( ! $r['chosen_block_key'] || ! $r['started_at'] || $r['finished_at'] ) {
				continue;
			}
			$end = self::block_end( $r, $c );
			if ( $date >= $end ) {
				$rows[ $i ]['finished_at'] = $end . ' 00:00:00';
				if ( $write ) {
					LLD_Members_Stage3_Data::update( 'block_choices', (int) $member_id, (int) $r['id'], array( 'finished_at' => $end . ' 00:00:00' ) );
				}
			}
		}
		return $rows;
	}

	/**
	 * The first day after a focus block (FR3-104): 28 days from its start, plus the days a festival block paused it
	 * (paused_at to resumed_at, R-HW-BLOCK-03). The festival block itself ends after its week 4 (the day map's last day).
	 *
	 * @param array $r Block choice row.
	 * @param array $c context().
	 * @return string Y-m-d.
	 */
	private static function block_end( array $r, array $c ) {
		$start = substr( (string) $r['started_at'], 0, 10 );
		$fest  = isset( $c['state']['festival'] ) && is_array( $c['state']['festival'] ) ? $c['state']['festival'] : null;
		if ( LLD_Members_HW_Festival::BLOCK === (string) $r['chosen_block_key'] && $fest && ! empty( $fest['to'] ) && isset( $fest['from'] ) && (string) $fest['from'] === $start ) {
			return LLD_Members_HW_Festival::add_days( (string) $fest['to'], 1 );
		}
		return LLD_Members_HW_Festival::add_days( $start, 28 + self::paused_days( $r ) );
	}

	/**
	 * Days a block was paused for a festival block (0 when it was not).
	 *
	 * @param array $r Block choice row.
	 */
	private static function paused_days( array $r ) {
		if ( empty( $r['paused_at'] ) || empty( $r['resumed_at'] ) ) {
			return 0;
		}
		return max( 0, LLD_Members_HW_Festival::days_between( substr( (string) $r['paused_at'], 0, 10 ), substr( (string) $r['resumed_at'], 0, 10 ) ) );
	}

	/**
	 * A focus block's track post, or null before the block's track is loaded.
	 *
	 * @param string $slug Block slug.
	 */
	private static function track_post( $slug ) {
		if ( ! class_exists( 'LLD_Members_Programme' ) ) {
			return null;
		}
		$id = (int) LLD_Members_Programme::track_id( $slug );
		return $id > 0 ? $id : null;
	}

	/**
	 * The catalogue id of a programme rule.
	 *
	 * @param string $rule Rule id.
	 */
	public static function rule_id( $rule ) {
		$rules = LLD_Members_Rule_Catalogue::programme_rules();
		return isset( $rules[ $rule ]['id'] ) ? (int) $rules[ $rule ]['id'] : null;
	}

	/**
	 * Today's notes_shown row, or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $date      Local date.
	 */
	private static function note_row( $member_id, $date ) {
		foreach ( LLD_Members_Stage3_Data::rows( 'notes_shown', (int) $member_id, array(), '', 60 ) as $r ) {
			if ( (string) $r['shown_on'] === (string) $date ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Note key => date last shown, for the 30-day rule (FR3-106).
	 *
	 * @param int $member_id Member number.
	 */
	private static function shown_map( $member_id ) {
		$out = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'notes_shown', (int) $member_id, array(), '', 400 ) as $r ) {
			$k = (string) $r['note_key'];
			if ( ! isset( $out[ $k ] ) || (string) $r['shown_on'] > $out[ $k ] ) {
				$out[ $k ] = (string) $r['shown_on'];
			}
		}
		return $out;
	}

	/**
	 * The local date R-SAFE-04 last showed, or null.
	 *
	 * @param int $member_id Member number.
	 */
	private static function safe04_date( $member_id ) {
		$rule = LLD_Members_Rule_Catalogue::rule( 'R-SAFE-04' );
		if ( ! $rule ) {
			return null;
		}
		$best = null;
		foreach ( LLD_Members_Stage3_Data::rows( 'insights', (int) $member_id, array(), '', 200 ) as $r ) {
			if ( (int) $r['rule_id'] === (int) $rule['id'] && ( null === $best || (string) $r['shown_at'] > $best ) ) {
				$best = (string) $r['shown_at'];
			}
		}
		return null === $best ? null : substr( $best, 0, 10 );
	}

	/**
	 * Today's mood (1 to 5), or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $date      Local date.
	 */
	private static function mood_today( $member_id, $date ) {
		$daily = LLD_Members_Goal_Journey::daily( $member_id, 'mood' );
		return isset( $daily[ $date ] ) ? $daily[ $date ] : null;
	}

	/** @param int $member_id Member number. */
	private static function last_food_date( $member_id ) {
		$best = null;
		foreach ( LLD_Members_Stage3_Data::rows( 'food_log', (int) $member_id, array(), '', 2000 ) as $r ) {
			if ( empty( $r['deleted_at'] ) && ( null === $best || (string) $r['entry_date'] > $best ) ) {
				$best = (string) $r['entry_date'];
			}
		}
		return $best;
	}

	/**
	 * The latest weigh-in of a 7-day window ending $back days before today, or null.
	 *
	 * @param array  $weights Daily weights.
	 * @param string $today   Local date.
	 * @param int    $back    0 for this week, 7 for last week.
	 */
	private static function week_weight( array $weights, $today, $back ) {
		$to   = LLD_Members_HW_Festival::add_days( $today, -$back );
		$from = LLD_Members_HW_Festival::add_days( $to, -6 );
		$out  = null;
		foreach ( $weights as $d => $w ) {
			if ( $d >= $from && $d <= $to ) {
				$out = (float) $w;
			}
		}
		return $out;
	}

	/**
	 * Shift workers' "usual sleep time" for R-HW-ALC-02: 16 hours after waking, so the shortcut shows from 10 hours
	 * after they wake (D3-60, Q162). Null without a wake-up time (18:00 then).
	 *
	 * @param array $a Engine answers.
	 */
	public static function sleep_time( array $a ) {
		if ( empty( $a['wake_time'] ) || ! preg_match( '/^(\d\d):(\d\d)$/', (string) $a['wake_time'], $m ) ) {
			return null;
		}
		$t = ( (int) $m[1] * 60 + (int) $m[2] + 16 * 60 ) % 1440;
		return sprintf( '%02d:%02d', intdiv( $t, 60 ), $t % 60 );
	}

	/** @param int $member_id Member number. */
	private static function local_time( $member_id ) {
		$zone = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::timezone( $member_id ) : null;
		$now  = LLD_Members_Clock::now( $member_id );
		return ( $zone instanceof DateTimeZone ? $now->setTimezone( $zone ) : $now )->format( 'H:i' );
	}
}
