<?php
/**
 * Healthy Weight programme answers (P3-35, package P2; FR3-97 to FR3-99, FR3-101, FR3-107, FR3-109, FR3-110).
 *
 * The joining questions, the getting-to-know-you questions and the programme's own background answer, stored one row
 * per question in wp_lld_profile_answers with the answer sealed (data model: Getting-to-know-you answer). The food rules,
 * the fasts kept and the allergies live in the one food-preferences store (wp_lld_food_preferences, FR3-36), reused by
 * FR3-99; the activity level lives in the Stage 2 health profile (FR3-97: "a change is saved back to the profile").
 *
 * Every answer is health data: it needs health consent (nothing is stored without it, FR3-98 AS-04), is in "Download my
 * data", goes with the account or when consent is withdrawn (LLD_Members_Stage3_Erasure), and is never put in an email,
 * an analytics event or an admin page (this class has no hook into any of them).
 *
 * The member-facing wording of each question is approved content (LLD_Members_HW_Content::question(), package P3); this
 * class holds only the keys, the allowed values and the signed rules each answer feeds.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get, set and forget a member's Healthy Weight answers.
 */
class LLD_Members_HW_Answers {

	const SEALED = array( 'answer' );

	/** Sealed fields of the food preferences row: the food rules with the fasts kept (they can show religion). */
	const PREF_SEALED = array( 'food_rules' );

	/** Where a question is asked (data model asked_in), and the account hub for a change. */
	const ASKED_IN = array( 'joining', 'track_start', 'today', 'recheck', 'account_hub' );

	/**
	 * Every question key: where it is asked first, its allowed values (a list = one of; 'multi' = any of; 'scale' =
	 * 1 to 10; 'time' = HH:MM; 'date' = Y-m-d; 'bool'), where it is stored, the R-HW-QOD-01 quick-question key it
	 * belongs to, and the signed rules that read it (rule catalogue v1.3.1; HW-QW wording file, approved 10 Nov 2026).
	 */
	const KEYS = array(
		// "Where you are now" (FR3-97).
		'activity_level'    => array( 'asked_in' => 'joining', 'values' => array( 'low', 'some', 'active', 'very_active' ), 'store' => 'profile', 'rules' => array( 'R-HW-NUM-03', 'R-HW-STEP-06' ) ),
		'bmi_background'    => array( 'asked_in' => 'joining', 'values' => array( 'White', 'Indian', 'Pakistani', 'Bangladeshi', 'OtherSouthAsian', 'Chinese', 'OtherAsian', 'MiddleEastern', 'BlackAfrican', 'BlackCaribbean', 'Mixed', 'NotSay' ), 'store' => 'answers', 'rules' => array( 'R-HW-NUM-01', 'R-HW-NUM-02' ) ),
		'bmi_mixed'         => array( 'asked_in' => 'joining', 'values' => array( 'yes', 'no', 'notsure' ), 'store' => 'answers', 'rules' => array( 'R-HW-NUM-01', 'R-HW-NUM-02' ) ),
		// The Healthy Weight Check's own question (copy q.eat, options.eat), asked once on "where you are now" when the
		// Stage 2 profile has no answer (it holds only yes or no): "yes" stops (SAFE-01), "prefer" limits the pace (HWS-01b).
		'eating_disorder'   => array( 'asked_in' => 'joining', 'values' => array( 'yes', 'no', 'prefer' ), 'store' => 'answers', 'rules' => array( 'R-HW-SAFE-01', 'R-HW-SAFE-02', 'R-HW-PACE-01' ) ),
		// The eight quick questions when joining (FR3-98), with the drinks-a-time tap and the optional wake-up time.
		'reason'            => array( 'asked_in' => 'joining', 'values' => array( 'health', 'confidence', 'family', 'wedding', 'holiday', 'event', 'other' ), 'store' => 'answers', 'rules' => array( 'R-HW-MOT-01' ) ),
		'event_date'        => array( 'asked_in' => 'joining', 'values' => 'date', 'store' => 'answers', 'rules' => array( 'R-HW-MOT-01' ) ),
		'barrier'           => array( 'asked_in' => 'joining', 'values' => array( 'evening_snacking', 'stress_eating', 'eating_out', 'no_time', 'alcohol', 'not_sure' ), 'store' => 'answers', 'rules' => array( 'R-HW-STEP-01', 'R-HW-BLOCK-01' ) ),
		'time'              => array( 'asked_in' => 'joining', 'values' => array( 'under_10', '10_30', 'over_30' ), 'store' => 'answers', 'rules' => array( 'R-HW-PACE-01', 'R-HW-STEP-03', 'R-HW-STEP-06' ) ),
		'work'              => array( 'asked_in' => 'joining', 'values' => array( 'days', 'shifts', 'nights', 'irregular' ), 'store' => 'answers', 'rules' => array( 'R-HW-PACE-01', 'R-HW-REM-01', 'R-HW-BLOCK-01' ) ),
		'wake_time'         => array( 'asked_in' => 'joining', 'values' => 'time', 'store' => 'answers', 'rules' => array( 'R-HW-REM-01', 'R-HW-ALC-02' ) ),
		'confidence'        => array( 'asked_in' => 'joining', 'values' => 'scale', 'store' => 'answers', 'rules' => array( 'R-HW-PACE-01', 'R-HW-STEP-03' ) ),
		'sleep'             => array( 'asked_in' => 'joining', 'values' => array( 'under_6', '6_7', '7_9', 'over_9' ), 'store' => 'answers', 'rules' => array( 'R-HW-SAFE-03', 'R-HW-PACE-01', 'R-HW-STEP-04', 'R-HW-NOTE-02', 'R-HW-BLOCK-01' ) ),
		'stress'            => array( 'asked_in' => 'joining', 'values' => 'scale', 'store' => 'answers', 'rules' => array( 'R-HW-SAFE-03', 'R-HW-PACE-01', 'R-HW-STEP-05', 'R-HW-NOTE-02', 'R-HW-BLOCK-01' ) ),
		'alcohol_freq'      => array( 'asked_in' => 'joining', 'values' => array( 'never', 'less_than_weekly', '1_2_days', '3_4_days', 'most_days' ), 'store' => 'answers', 'rules' => array( 'R-HW-SAFE-05', 'R-HW-NUM-05', 'R-HW-ALC-01', 'R-HW-ALC-02', 'R-HW-STEP-02', 'R-HW-BLOCK-01' ) ),
		'alcohol_amount'    => array( 'asked_in' => 'joining', 'values' => array( '1_2', '3_4', '5_plus' ), 'store' => 'answers', 'rules' => array( 'R-HW-SAFE-05', 'R-HW-NUM-05', 'R-HW-ALC-01' ) ),
		// Getting-to-know-you questions (FR3-99), in the R-HW-QOD-01 order.
		'food_rules'        => array( 'asked_in' => 'track_start', 'qod' => 'food_rules_allergies', 'values' => 'multi', 'choices' => array( 'halal', 'kosher', 'no_pork', 'no_beef', 'pescatarian', 'vegetarian', 'vegan', 'no_eggs', 'dairy_free', 'no_onion_garlic', 'no_grains_pulses_fasting_days', 'jain', 'none' ), 'store' => 'food_preferences', 'rules' => array( 'R-HW-FOOD-01', 'R-HW-STEP-02' ) ),
		'fasts'             => array( 'asked_in' => 'track_start', 'qod' => 'food_rules_allergies', 'values' => 'multi', 'choices' => 'festival_calendar', 'store' => 'food_preferences', 'rules' => array( 'R-HW-FOOD-03', 'R-HW-BLOCK-01', 'R-HW-BLOCK-02', 'R-HW-BLOCK-03' ) ),
		'allergies'         => array( 'asked_in' => 'track_start', 'qod' => 'food_rules_allergies', 'values' => 'multi', 'choices' => array( 'gluten', 'milk', 'egg', 'peanuts', 'nuts', 'sesame', 'soy', 'fish', 'shellfish', 'celery', 'mustard', 'buckwheat', 'none' ), 'store' => 'food_preferences', 'rules' => array( 'R-HW-FOOD-01' ) ),
		'breakfast'         => array( 'asked_in' => 'track_start', 'qod' => 'eating_pattern', 'values' => array( 'most_days', 'some_days', 'rarely' ), 'store' => 'answers', 'rules' => array( 'R-HW-STEP-02' ) ),
		'breakfast_protein' => array( 'asked_in' => 'track_start', 'qod' => 'eating_pattern', 'values' => array( 'yes', 'no', 'not_sure' ), 'store' => 'answers', 'rules' => array( 'R-HW-STEP-02' ) ),
		'snack_time'        => array( 'asked_in' => 'track_start', 'qod' => 'snack_time', 'values' => array( '10:30', '15:30', '18:00', '20:00', '21:00', 'none' ), 'store' => 'answers', 'rules' => array( 'R-HW-REM-01' ) ),
		'sugary_drinks'     => array( 'asked_in' => 'track_start', 'qod' => 'sugary_drinks', 'values' => array( 'never', 'less_than_weekly', 'weekly', '4_6_days', 'daily' ), 'store' => 'answers', 'rules' => array( 'R-HW-STEP-02' ) ),
		'eats_out'          => array( 'asked_in' => 'track_start', 'qod' => 'eating_out', 'values' => array( 'less_than_weekly', '1_2', '3_plus' ), 'store' => 'answers', 'rules' => array( 'R-HW-STEP-02', 'R-HW-BLOCK-01' ) ),
		'cooking_time'      => array( 'asked_in' => 'track_start', 'qod' => 'cooking_time_skill', 'values' => array( 'under_15', '15_30', 'over_30' ), 'store' => 'answers', 'rules' => array( 'R-HW-FOOD-02' ) ),
		'cooking_skill'     => array( 'asked_in' => 'track_start', 'qod' => 'cooking_time_skill', 'values' => array( 'beginner', 'follows_recipes', 'loves_cooking' ), 'store' => 'answers', 'rules' => array( 'R-HW-FOOD-02' ) ),
		'budget'            => array( 'asked_in' => 'track_start', 'qod' => 'food_budget', 'values' => array( 'tight', 'some_room', 'not_a_worry' ), 'store' => 'answers', 'rules' => array( 'R-HW-FOOD-02' ) ),
		'cuisines'          => array( 'asked_in' => 'track_start', 'qod' => 'favourite_cuisines', 'values' => 'multi', 'choices' => array( 'everyday-british-american', 'south-asian', 'italian-mediterranean', 'chinese', 'mexican-latin-american', 'west-african', 'all' ), 'store' => 'answers', 'rules' => array( 'R-HW-FOOD-02' ) ),
		'enjoyment'         => array( 'asked_in' => 'track_start', 'qod' => 'activity_enjoyment', 'values' => 'multi', 'choices' => array( 'walking', 'dancing', 'swimming', 'cycling', 'home_workouts', 'yoga_stretching', 'sport', 'gardening', 'not_sure' ), 'store' => 'answers', 'rules' => array( 'R-HW-STEP-06' ) ),
	);

	/** The approved content's answer keys that differ from these (HW-QW-14 is "festivals"; the store is "fasts"). */
	const ALIASES = array( 'festivals' => 'fasts' );

	/**
	 * The values above are the one value set of each question, the same as the approved content's
	 * (LLD_Members_HW_Content::ANSWERS: a CI test keeps the two equal), read as they are by the screens, the engine and
	 * the recipes. Snack times are clock times (R-HW-REM-01 sets the reminder 30 minutes before); allergies are the
	 * recipe allergen keys (LLD_Members_HW_Rules::allergen() reads the recipes' names, "tree nuts", "soya"…, as these);
	 * cuisines are the recipes' cuisine names. "none" in a multi-choice clears the others.
	 *
	 * Values stored before the value sets were joined, read as the value they meant.
	 */
	const VALUE_ALIASES = array(
		'reason'            => array( 'something_else' => 'other' ),
		'food_rules'        => array( 'no_grains_pulses_fasting' => 'no_grains_pulses_fasting_days' ), // the engine's name, given by mistake
		'allergies'         => array( 'eggs' => 'egg', 'tree nuts' => 'nuts', 'tree_nuts' => 'nuts', 'soya' => 'soy' ),
		'breakfast_protein' => array( 'notsure' => 'not_sure' ),
		'snack_time'        => array( 'mid_morning' => '10:30', 'afternoon' => '15:30', 'early_evening' => '18:00', 'after_evening_meal' => '20:00', 'late' => '21:00', 'after 9 pm' => '21:00', 'hardly' => 'none' ),
		'sugary_drinks'     => array( '1_3_days' => 'weekly' ),
		'cooking_skill'     => array( 'starting' => 'beginner', 'follow_recipe' => 'follows_recipes', 'love_cooking' => 'loves_cooking' ),
		'cuisines'          => array( 'everyday' => 'everyday-british-american', 'south_asian' => 'south-asian', 'italian_mediterranean' => 'italian-mediterranean', 'mexican_latin' => 'mexican-latin-american', 'west_african' => 'west-african' ),
		'enjoyment'         => array( 'yoga' => 'yoga_stretching' ),
	);

	/** The data model's food-rule value for the fasting-day rule, and the engine's. */
	const FASTING_RULE        = 'no_grains_pulses_fasting_days';
	const FASTING_RULE_ENGINE = 'no_grains_pulses_fasting';

	/** Answers read in this request, by member. Cleared by every write. */
	private static $cache = array();

	/**
	 * The question set with the approved wording where package P3 has loaded it (null-safe): every question has at
	 * least one signed rule (TC-FR3-99-AS-04); "stress eating" and "what they have tried before" have none, so they are
	 * not here and are never asked (rule draft reading 36).
	 *
	 * @param string|null $asked_in Only the questions first asked there (joining or track_start), or null for all.
	 * @return array<int,array{key:string,asked_in:string,qod:string|null,choices:array|string,multiple:bool,rules:string[],wording:string|null,reason:string|null}>
	 */
	public static function questions( $asked_in = null ) {
		$out = array();
		foreach ( self::KEYS as $key => $def ) {
			if ( null !== $asked_in && $def['asked_in'] !== $asked_in ) {
				continue;
			}
			$content = self::content_question( $key );
			$choices = is_array( $def['values'] ) ? $def['values'] : ( isset( $def['choices'] ) ? $def['choices'] : $def['values'] );
			if ( 'festival_calendar' === $choices ) {
				// The festival families (CAL-RAM …), so a fast kept carries from one year's calendar entry to the next.
				$choices = LLD_Members_HW_Programme::festival_families();
			}
			$out[] = array(
				'key'      => $key,
				'asked_in' => $def['asked_in'],
				'qod'      => isset( $def['qod'] ) ? $def['qod'] : null,
				'choices'  => $choices,
				'multiple' => 'multi' === $def['values'],
				'rules'    => $def['rules'],
				'wording'  => isset( $content['title'] ) ? (string) $content['title'] : ( isset( $content['wording'] ) ? (string) $content['wording'] : null ),
				'reason'   => isset( $content['why'] ) ? (string) $content['why'] : ( isset( $content['reason'] ) ? (string) $content['reason'] : null ),
			);
		}
		return $out;
	}

	/**
	 * One answer: the value, or null when not answered or skipped.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       A KEYS key.
	 * @return mixed
	 */
	public static function get( $member_id, $key ) {
		$key = isset( self::ALIASES[ $key ] ) ? self::ALIASES[ $key ] : $key;
		$all = self::details( $member_id );
		return isset( $all[ $key ] ) ? $all[ $key ]['value'] : null;
	}

	/**
	 * Every answer of the member, opened: key => value (null for a skipped question, whose key is still present).
	 * Answers held in the food preferences and the health profile are included (FR3-99 AS-03). Nothing without health
	 * consent.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	public static function all( $member_id ) {
		$out = array();
		foreach ( self::details( $member_id ) as $key => $d ) {
			$out[ $key ] = $d['value'];
		}
		return $out;
	}

	/**
	 * Skip a question: one key, or a quick-question group of R-HW-QOD-01 (for example eating_pattern), every key in it.
	 * A skipped answer counts as not known and the rule that needs it stays silent.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       A KEYS key or a quick-question key.
	 * @return true|WP_Error
	 */
	public static function skip( $member_id, $key ) {
		$key = isset( self::ALIASES[ $key ] ) ? self::ALIASES[ $key ] : $key;
		if ( isset( self::KEYS[ $key ] ) ) {
			return self::set( $member_id, $key, null, 'today' );
		}
		$done = false;
		foreach ( self::KEYS as $k => $def ) {
			if ( isset( $def['qod'] ) && $def['qod'] === $key ) {
				$r = self::set( $member_id, $k, null, 'today' );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$done = true;
			}
		}
		return $done ? true : new WP_Error( 'lld_hw_answer', 'Not a question.' );
	}

	/**
	 * Every answer of the member, opened: key => {value, skipped, asked_in, updated_at}.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,array{value:mixed,skipped:bool,asked_in:string|null,updated_at:string|null}>
	 */
	public static function details( $member_id ) {
		$member_id = (int) $member_id;
		if ( isset( self::$cache[ $member_id ] ) ) {
			return self::$cache[ $member_id ];
		}
		$out = array();
		if ( $member_id < 1 || ! self::consent( $member_id ) ) {
			return $out;
		}
		foreach ( LLD_Members_Stage3_Data::rows( 'profile_answers', $member_id, self::SEALED, '', 200 ) as $row ) {
			$key = (string) $row['question_key'];
			if ( ! isset( self::KEYS[ $key ] ) || isset( $out[ $key ] ) ) {
				continue;
			}
			$value       = null === $row['answer'] ? null : self::canonical( $key, json_decode( (string) $row['answer'], true ) );
			$out[ $key ] = array(
				'value'      => ! empty( $row['skipped'] ) ? null : $value,
				'skipped'    => ! empty( $row['skipped'] ),
				'asked_in'   => isset( $row['asked_in'] ) ? (string) $row['asked_in'] : null,
				'updated_at' => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null,
			);
		}
		$prefs = self::preferences_row( $member_id );
		if ( $prefs ) {
			$rules = self::json_list( isset( $prefs['food_rules'] ) ? $prefs['food_rules'] : null );
			if ( null !== $rules ) {
				$out['food_rules'] = self::stored( self::canonical( 'food_rules', array_values( array_filter( $rules, function ( $r ) {
					return 0 !== strpos( (string) $r, 'fast:' );
				} ) ) ), $prefs );
				$fasts             = array();
				foreach ( $rules as $r ) {
					if ( 0 === strpos( (string) $r, 'fast:' ) ) {
						$fam = LLD_Members_HW_Programme::festival_family( substr( (string) $r, 5 ) ); // an older entry id reads as its family
						if ( ! in_array( $fam, $fasts, true ) ) {
							$fasts[] = $fam;
						}
					}
				}
				$out['fasts'] = self::stored( $fasts, $prefs );
			}
			$allergies = self::json_list( isset( $prefs['allergies'] ) ? $prefs['allergies'] : null );
			if ( null !== $allergies ) {
				$out['allergies'] = self::stored( self::canonical( 'allergies', $allergies ), $prefs );
			}
		}
		$level = self::profile_activity( $member_id );
		if ( null !== $level ) {
			$out['activity_level'] = array(
				'value'      => $level,
				'skipped'    => false,
				'asked_in'   => 'joining',
				'updated_at' => null,
			);
		}
		self::$cache[ $member_id ] = $out;
		return $out;
	}

	/**
	 * Save one answer, or record it as skipped when $value is null (data model: "Empty when skipped"). Refused without
	 * health consent, with nothing stored (FR3-98 AS-04).
	 *
	 * @param int         $member_id Member number.
	 * @param string      $key       A KEYS key.
	 * @param mixed       $value     The answer, or null to skip.
	 * @param string|null $asked_in  Where it was answered; defaults to where the question is first asked.
	 * @return true|WP_Error
	 */
	public static function set( $member_id, $key, $value, $asked_in = null ) {
		$member_id = (int) $member_id;
		$key       = isset( self::ALIASES[ $key ] ) ? self::ALIASES[ $key ] : $key;
		if ( ! isset( self::KEYS[ $key ] ) ) {
			return new WP_Error( 'lld_hw_answer', 'Not a question.' );
		}
		if ( $member_id < 1 || ! self::consent( $member_id ) ) {
			return new WP_Error( 'lld_hw_consent', 'Health consent is needed first.' );
		}
		$def      = self::KEYS[ $key ];
		$asked_in = in_array( (string) $asked_in, self::ASKED_IN, true ) ? (string) $asked_in : $def['asked_in'];
		$clean    = null === $value ? null : self::clean( $key, $value );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}
		unset( self::$cache[ $member_id ] );
		if ( class_exists( 'LLD_Members_HW_Programme' ) ) {
			LLD_Members_HW_Programme::flush();
		}
		if ( 'profile' === $def['store'] ) {
			if ( null === $clean ) {
				return true; // the profile keeps its level; a skip changes nothing
			}
			$saved = self::save_profile_activity( $member_id, $clean );
			// A changed level works the target out again at once (FR3-102 AS-05).
			if ( true === $saved && class_exists( 'LLD_Members_Goal_Journey' ) ) {
				LLD_Members_Goal_Journey::retarget( $member_id );
			}
			return $saved;
		}
		if ( 'food_preferences' === $def['store'] ) {
			return self::save_preference( $member_id, $key, null === $clean ? array() : $clean );
		}
		$row = array(
			'question_key' => $key,
			'answer'       => null === $clean ? null : wp_json_encode( $clean ),
			'skipped'      => null === $clean ? 1 : 0,
			'asked_in'     => $asked_in,
		);
		$existing = self::answer_row( $member_id, $key );
		if ( $existing ) {
			LLD_Members_Stage3_Data::update( 'profile_answers', $member_id, (int) $existing['id'], $row, self::SEALED );
		} elseif ( LLD_Members_Stage3_Data::insert( 'profile_answers', $member_id, $row, self::SEALED ) < 1 ) {
			return new WP_Error( 'lld_hw_answer', 'Try again.' );
		}
		unset( self::$cache[ $member_id ] );
		// The reminder times follow the answers (R-HW-REM-01); only reminders the member already has are moved.
		if ( in_array( $key, array( 'work', 'wake_time', 'snack_time' ), true ) && class_exists( 'LLD_Members_Reminders' ) && method_exists( 'LLD_Members_Reminders', 'sync_hw' ) ) {
			LLD_Members_Reminders::sync_hw( $member_id, self::engine( $member_id ), false );
		}
		return true;
	}

	/**
	 * Forget one answer, or every programme answer (the food preferences and the profile keep theirs: they belong to
	 * FR3-36 and FR2-25 and are erased with them).
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $key       A KEYS key, or null for all.
	 * @return int Rows removed.
	 */
	public static function forget( $member_id, $key = null ) {
		global $wpdb;
		$member_id = (int) $member_id;
		unset( self::$cache[ $member_id ] );
		if ( class_exists( 'LLD_Members_HW_Programme' ) ) {
			LLD_Members_HW_Programme::flush(); // the programme's cached contexts read these answers
		}
		if ( $member_id < 1 ) {
			return 0;
		}
		$where = array( 'member_id' => $member_id );
		$types = array( '%d' );
		if ( null !== $key ) {
			$where['question_key'] = (string) $key;
			$types[]               = '%s';
		}
		$n = $wpdb->delete( LLD_Members_Stage3_Schema::table( 'profile_answers' ), $where, $types );
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * The answers as the P1 engine reads them (LLD_Members_HW_Rules class comment): skipped answers are absent, values
	 * are mapped to the engine's names, alcohol is "never" when the food rules exclude it (FR3-98 AS-08), and the safety
	 * profile adds the conditions and flags.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     LLD_Members_Goal_Journey::facts(), for the conditions.
	 * @return array<string,mixed>
	 */
	public static function engine( $member_id, array $facts = array() ) {
		$all = self::details( $member_id );
		$v   = function ( $k ) use ( $all ) {
			return isset( $all[ $k ] ) ? $all[ $k ]['value'] : null;
		};
		$rules = array();
		foreach ( (array) $v( 'food_rules' ) as $r ) {
			$rules[] = self::FASTING_RULE === $r ? self::FASTING_RULE_ENGINE : (string) $r;
		}
		$situations = isset( $facts['situations'] ) ? (array) $facts['situations'] : array();
		$conditions = array();
		$map        = array( 'diabetes' => 'type2_diabetes', 'blood_pressure' => 'high_blood_pressure', 'heart_disease' => 'heart_disease', 'kidney' => 'kidney_disease' );
		foreach ( $situations as $s ) {
			if ( isset( $map[ $s ] ) ) {
				$conditions[] = $map[ $s ];
			}
		}
		$enjoy = array_values( array_diff( (array) $v( 'enjoyment' ), array( 'not_sure' ) ) );
		$snack = $v( 'snack_time' );
		$out   = array(
			'barrier'           => 'not_sure' === $v( 'barrier' ) ? null : $v( 'barrier' ),
			'time'              => $v( 'time' ),
			'work'              => $v( 'work' ),
			'confidence'        => $v( 'confidence' ),
			'sleep'             => $v( 'sleep' ),
			'stress'            => $v( 'stress' ),
			'alcohol_freq'      => $v( 'alcohol_freq' ),
			'alcohol_amount'    => $v( 'alcohol_amount' ),
			'sugary_drinks'     => in_array( $v( 'sugary_drinks' ), array( 'never', 'less_than_weekly' ), true ) ? $v( 'sugary_drinks' ) : ( null === $v( 'sugary_drinks' ) ? null : 'weekly' ),
			'breakfast'         => $v( 'breakfast' ),
			'breakfast_protein' => 'yes' === $v( 'breakfast_protein' ),
			'eats_out'          => $v( 'eats_out' ),
			'activity_level'    => $v( 'activity_level' ),
			'enjoyment'         => $enjoy ? $enjoy[0] : null,
			'food_rules'        => $rules,
			'allergies'         => (array) $v( 'allergies' ),
			'fasts'             => (array) $v( 'fasts' ),
			'conditions'        => $conditions,
			'medicines'         => in_array( 'heart_rate_medicine', $situations, true ),
			'eating_disorder'   => self::eating( $situations, $v( 'eating_disorder' ) ),
			'pregnant'          => in_array( 'pregnancy', $situations, true ) ? 'yes' : 'no',
			'snack_time'        => is_string( $snack ) && preg_match( '/^\d\d:\d\d$/', $snack ) ? $snack : null, // a clock time; "none" is no snack time
			'wake_time'         => $v( 'wake_time' ),
			'cooking_time'      => $v( 'cooking_time' ),
			'budget'            => $v( 'budget' ),
			'cuisines'          => (array) $v( 'cuisines' ),
			'reason'            => $v( 'reason' ),
			'event_date'        => $v( 'event_date' ),
			'bmi_background'    => 'Mixed' === $v( 'bmi_background' ) ? array( 'bg' => 'Mixed', 'mixed' => $v( 'bmi_mixed' ) ) : $v( 'bmi_background' ),
		);
		if ( array_intersect( LLD_Members_HW_Rules::NO_ALCOHOL_RULES, $rules ) ) {
			$out['alcohol_freq']   = 'never'; // FR3-98 AS-08: not asked, "never" recorded
			$out['alcohol_amount'] = null;
		}
		return $out;
	}

	/**
	 * The eating-disorder answer the engine reads: the Stage 2 profile's flag first, else the programme's own answer
	 * ("prefer" is the engine's pnts).
	 *
	 * @param string[]    $situations Safety situations.
	 * @param string|null $answer     The programme answer (yes, no, prefer), or null.
	 * @return string yes, pnts or no.
	 */
	public static function eating( array $situations, $answer ) {
		if ( in_array( 'eating_disorder', $situations, true ) || 'yes' === $answer ) {
			return 'yes';
		}
		return 'prefer' === $answer ? 'pnts' : 'no';
	}

	/**
	 * The lld_members_stage3_safety_situations filter: a stored "yes" to the Healthy Weight Check's eating question is
	 * the eating_disorder situation, so the safety matrix hides calorie numbers everywhere, as for the Stage 2 flag.
	 * "Prefer not to say" adds nothing (Owner Question 164).
	 *
	 * @param string[] $situations Situations so far.
	 * @param int      $member_id  Member number.
	 * @return string[]
	 */
	public static function safety_situations( $situations, $member_id = 0 ) {
		$situations = is_array( $situations ) ? $situations : array();
		if ( (int) $member_id > 0 && ! in_array( 'eating_disorder', $situations, true ) && 'yes' === self::get( (int) $member_id, 'eating_disorder' ) ) {
			$situations[] = 'eating_disorder';
		}
		return $situations;
	}

	/**
	 * Whether "where you are now" asks the eating-disorder question: the Stage 2 profile holds only yes or no, so a
	 * member it does not flag is asked the Check's own question once, until it is answered (yes, no or prefer not to say).
	 *
	 * @param int   $member_id  Member number.
	 * @param array $situations Safety situations.
	 */
	public static function ask_eating( $member_id, array $situations ) {
		if ( in_array( 'eating_disorder', $situations, true ) ) {
			return false;
		}
		$all = self::details( $member_id );
		return ! ( isset( $all['eating_disorder'] ) && null !== $all['eating_disorder']['value'] );
	}

	/**
	 * The R-HW-QOD-01 keys already answered or skipped, and those known from the health profile or food preferences.
	 *
	 * @param int $member_id Member number.
	 * @return array{answered:string[],known:string[]}
	 */
	public static function qod_state( $member_id ) {
		$all      = self::details( $member_id );
		$answered = array();
		$known    = array();
		$open = array(); // a group counts as answered only when every one of its keys is answered or skipped
		foreach ( self::KEYS as $key => $def ) {
			if ( ! empty( $def['qod'] ) && ! isset( $all[ $key ] ) ) {
				$open[ $def['qod'] ] = true;
			}
		}
		foreach ( self::KEYS as $key => $def ) {
			if ( empty( $def['qod'] ) || ! isset( $all[ $key ] ) || isset( $open[ $def['qod'] ] ) ) {
				continue;
			}
			if ( 'food_preferences' === $def['store'] ) {
				$known[] = $def['qod'];
			} else {
				$answered[] = $def['qod'];
			}
		}
		return array(
			'answered' => array_values( array_unique( $answered ) ),
			'known'    => array_values( array_unique( $known ) ),
		);
	}

	/**
	 * The answer keys a quick-question group asks, in KEYS order, leaving out those already answered or skipped.
	 * A group key (for example food_rules_allergies) comes from the engine's R-HW-QOD-01 order; the screens and the
	 * approved wording use the answer keys (food_rules, fasts, allergies).
	 *
	 * @param int    $member_id Member number.
	 * @param string $group     A KEYS 'qod' group key, or an answer key.
	 * @return string[] Answer keys still to ask.
	 */
	public static function group_keys( $member_id, $group ) {
		if ( isset( self::KEYS[ $group ] ) ) {
			return array( $group );
		}
		$all  = self::details( $member_id );
		$keys = array();
		foreach ( self::KEYS as $key => $def ) {
			if ( isset( $def['qod'] ) && $group === $def['qod'] && ! isset( $all[ $key ] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	// ---- Helpers --------------------------------------------------------------------------------------------------

	/**
	 * A clean value for a key, or an error naming the question.
	 *
	 * @param string $key   A KEYS key.
	 * @param mixed  $value Raw value.
	 * @return mixed|WP_Error
	 */
	public static function clean( $key, $value ) {
		$def  = self::KEYS[ $key ];
		$kind = $def['values'];
		$bad  = new WP_Error( 'lld_hw_answer', 'Choose one of the answers.', array( 'key' => $key ) );
		$value = self::canonical( $key, $value );
		if ( is_array( $kind ) ) {
			return in_array( (string) $value, $kind, true ) ? (string) $value : $bad;
		}
		switch ( $kind ) {
			case 'scale':
				return is_numeric( $value ) && (int) $value == $value && $value >= 1 && $value <= 10 ? (int) $value : $bad; // phpcs:ignore Universal.Operators.StrictComparisons -- whole numbers only.
			case 'time':
				return is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $bad;
			case 'date':
				return is_string( $value ) && preg_match( '/^\d{4}-\d\d-\d\d$/', $value ) ? $value : $bad;
			case 'multi':
				$list    = is_array( $value ) ? $value : array( $value );
				if ( in_array( 'none', array_map( 'strval', $list ), true ) ) {
					return array(); // "None of these" clears every other choice
				}
				$choices = isset( $def['choices'] ) ? $def['choices'] : array();
				$fasts = 'festival_calendar' === $choices;
				if ( $fasts ) {
					$choices = LLD_Members_HW_Programme::festival_families();
				}
				$out = array();
				foreach ( $list as $item ) {
					$item = (string) $item;
					if ( 'none' === $item ) {
						continue; // "None of these"
					}
					if ( $fasts ) {
						// One year's entry id is stored as its family; a family named for a tradition's entries counts.
						$item  = LLD_Members_HW_Programme::festival_family( $item );
						$known = in_array( $item, $choices, true );
						foreach ( $choices as $fam ) {
							$known = $known || 0 === strpos( $fam, $item . '-' );
						}
						if ( ! $known ) {
							return $bad;
						}
						if ( ! in_array( $item, $out, true ) ) {
							$out[] = $item;
						}
						continue;
					}
					if ( ! in_array( $item, $choices, true ) ) {
						return $bad;
					}
					if ( ! in_array( $item, $out, true ) ) {
						$out[] = $item;
					}
				}
				return $out;
		}
		return $bad;
	}

	/**
	 * A value read as the question's one value set: an old stored value becomes the value it meant (VALUE_ALIASES).
	 *
	 * @param string $key   A KEYS key.
	 * @param mixed  $value Value, or list for a multi-choice.
	 * @return mixed
	 */
	public static function canonical( $key, $value ) {
		$map = isset( self::VALUE_ALIASES[ $key ] ) ? self::VALUE_ALIASES[ $key ] : array();
		if ( ! $map ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $v ) {
				$v = isset( $map[ (string) $v ] ) ? $map[ (string) $v ] : $v;
				if ( ! in_array( $v, $out, true ) ) {
					$out[] = $v;
				}
			}
			return $out;
		}
		return is_scalar( $value ) && isset( $map[ (string) $value ] ) ? $map[ (string) $value ] : $value;
	}

	/** @param int $member_id Member number. */
	private static function consent( $member_id ) {
		if ( class_exists( 'LLD_Members_Access' ) && method_exists( 'LLD_Members_Access', 'has_health_consent' ) ) {
			return (bool) LLD_Members_Access::has_health_consent( (int) $member_id );
		}
		return class_exists( 'LLD_Members_Account' ) && LLD_Members_Account::has_consent( (int) $member_id, 'health' );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Question key.
	 * @return array<string,mixed>|null
	 */
	private static function answer_row( $member_id, $key ) {
		foreach ( LLD_Members_Stage3_Data::rows( 'profile_answers', $member_id, array(), '', 200 ) as $row ) {
			if ( (string) $row['question_key'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	/** @param int $member_id Member number. */
	private static function preferences_row( $member_id ) {
		$rows = LLD_Members_Stage3_Data::rows( 'food_preferences', $member_id, self::PREF_SEALED, '', 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * The food rules (with the fasts as "fast:<festival family>", e.g. "fast:CAL-RAM") and the allergies, in the one food-preferences store.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       food_rules, fasts or allergies.
	 * @param array  $list      Clean list.
	 * @return true|WP_Error
	 */
	private static function save_preference( $member_id, $key, array $list ) {
		$row   = self::preferences_row( $member_id );
		$rules = self::json_list( $row && isset( $row['food_rules'] ) ? $row['food_rules'] : null );
		$rules = null === $rules ? array() : $rules;
		$data  = array();
		if ( 'allergies' === $key ) {
			$data['allergies'] = wp_json_encode( array_values( $list ) );
		} else {
			$keep = array();
			foreach ( $rules as $r ) {
				$is_fast = 0 === strpos( (string) $r, 'fast:' );
				if ( ( 'fasts' === $key ) !== $is_fast ) {
					$keep[] = (string) $r;
				}
			}
			foreach ( $list as $item ) {
				$keep[] = 'fasts' === $key ? 'fast:' . $item : $item;
			}
			$data['food_rules'] = wp_json_encode( array_values( $keep ) );
		}
		if ( $row ) {
			LLD_Members_Stage3_Data::update( 'food_preferences', $member_id, (int) $row['id'], $data, self::PREF_SEALED );
			return true;
		}
		if ( ! isset( $data['allergies'] ) ) {
			$data['allergies'] = null;
		}
		return LLD_Members_Stage3_Data::insert( 'food_preferences', $member_id, $data, self::PREF_SEALED ) > 0 ? true : new WP_Error( 'lld_hw_answer', 'Try again.' );
	}

	/**
	 * The activity level from the Stage 2 health profile, in the engine's spelling (very-active → very_active).
	 *
	 * @param int $member_id Member number.
	 * @return string|null
	 */
	private static function profile_activity( $member_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'LLD_Members_Schema' ) ) {
			return null;
		}
		$level = $wpdb->get_var( $wpdb->prepare( 'SELECT activity_level FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', (int) $member_id ) );
		$level = str_replace( array( '-', ' ' ), '_', strtolower( (string) $level ) );
		return in_array( $level, self::KEYS['activity_level']['values'], true ) ? $level : null;
	}

	/**
	 * A changed level is saved back to the health profile (FR3-97 AS-08), in the profile's spelling.
	 *
	 * @param int    $member_id Member number.
	 * @param string $level     low, some, active or very_active.
	 * @return true|WP_Error
	 */
	private static function save_profile_activity( $member_id, $level ) {
		if ( ! class_exists( 'LLD_Members_Account' ) ) {
			return new WP_Error( 'lld_hw_answer', 'Try again.' );
		}
		$saved = LLD_Members_Account::save_health(
			$member_id,
			array(
				'kind'           => 'profile',
				'activity_level' => str_replace( '_', '-', $level ),
				'confirmed'      => true,
			)
		);
		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * @param mixed $raw JSON text or a list.
	 * @return array|null Null when nothing is stored.
	 */
	private static function json_list( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : null;
	}

	/**
	 * @param array $value List.
	 * @param array $row   Food preferences row.
	 */
	private static function stored( array $value, array $row ) {
		return array(
			'value'      => $value,
			'skipped'    => false,
			'asked_in'   => 'track_start',
			'updated_at' => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null,
		);
	}

	/**
	 * @param string $key Question key.
	 * @return array<string,mixed>|null
	 */
	private static function content_question( $key ) {
		if ( ! class_exists( 'LLD_Members_HW_Content' ) || ! method_exists( 'LLD_Members_HW_Content', 'question' ) ) {
			return null;
		}
		$q = LLD_Members_HW_Content::question( $key );
		return is_array( $q ) ? $q : null;
	}
}
