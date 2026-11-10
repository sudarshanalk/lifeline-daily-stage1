<?php
/**
 * Healthy Weight programme content (P3-35, package P3; FR3-99, FR3-101 to FR3-105; Data Model "Mindset note",
 * "Activity habit tags and versions", "Focus block", "Festival calendar entry").
 *
 * The owner-approved programme content reaches the library through the approved content loader
 * (`wp lld content load`, mu-plugins/lld-tools/content-load.php), like every other item: the 56 programme notes and
 * the nine focus blocks' notes as `lld_note`, the joining and getting-to-know-you questions as `lld_question`, the
 * focus blocks as `lld_track` items with `track_kind` focus_block, and each activity's habit tags, easier and stretch
 * versions and the lines shown under it as fields of the activity. This class is the read side the programme
 * (package P2) codes against, plus the bundle's festival calendar, which the loader hands to load_festivals().
 *
 * Every look-up returns only what members may be shown: the approved version (LLD_Members_Content::shown()), through
 * the library's own queries, so the Stage 3 content filter (class-content-stage3.php) applies as it does everywhere.
 * An item with no approved version is never returned (TC-FR3-104-AS-04, TC-FR3-105-AS-02).
 *
 * The pure helpers (the *_view() shapers, festival_problems(), content_problems()) take plain arrays, so the tests
 * run them, and the WordPress-backed methods through a small stand-in library, without WordPress.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Healthy Weight notes, questions, focus blocks, activity versions and the festival calendar.
 */
class LLD_Members_HW_Content {

	/** The festival calendar table package P2 creates (wp_lld_festival_calendar). */
	const TABLE = 'festival_calendar';

	/** Option set once the P3-35 tags exist. */
	const TAG_SEED = 'lld_hw_tag_seed';

	/** Data Model "Festival calendar entry": kind. */
	const FESTIVAL_KINDS = array( 'one_day_festival', 'multi_day_festival', 'dawn_to_sunset_fast', 'one_day_fast', 'food_avoiding_fast' );

	/** The fields of one festival entry in the bundle's festivals[] list. */
	const FESTIVAL_FIELDS = array( 'key', 'name', 'kind', 'starts_on', 'ends_on', 'expected', 'approved_on', 'offers_block', 'tradition' );

	/** Columns the entry key may be stored in, in the order looked for (the table is package P2's). */
	const KEY_COLUMNS = array( 'festival_key', 'entry_key', 'key_name', 'slug' );

	/**
	 * Habit tags (FR3-103; HW-P3-35-06, and `stress` from the sleep and stress steps' S3 = A). They are an activity's
	 * habit_tags field, never its tags: `stress`, `sleep` and `alcohol` are also goal and safety tags with their own rules.
	 */
	const HABIT_TAGS = array(
		'evening-eating',
		'stress-eating',
		'eating-out',
		'short-activity',
		'alcohol',
		'snacking',
		'drink-swap',
		'breakfast-protein',
		'activity',
		'walking',
		'strength',
		'sleep',
		'stress',
		'drinks',
		'meals',
		'mindset',
		'tracking',
	);

	/** Food-rule tags (Food-Rule-Tags-R103-R176.md, Food-Rule-Tags-Jain.md): a recipe's food_rules field. */
	const FOOD_RULE_TAGS = array(
		'contains-poultry',
		'contains-beef',
		'contains-pork',
		'contains-fish',
		'contains-non-kosher-fish',
		'contains-shellfish',
		'contains-egg',
		'contains-dairy',
		'contains-meat-and-dairy',
		'needs-halal-meat',
		'needs-kosher-meat',
		'contains-onion-or-garlic',
		'contains-grains-or-pulses',
		'contains-alcohol',
		'contains-root-vegetables',
		'contains-mushrooms',
		'contains-honey',
	);

	/** Item tags P3-35 adds to the managed list: the `exercise` content tag (Owner Q55) and the shift-worker person tag. */
	const ITEM_TAGS = array(
		'exercise'     => 'content',
		'shift-worker' => 'person',
	);

	/**
	 * The approved urgent-help line (NFR2-07), word for word as the content builder adds it under every stress and mood
	 * item (`SAFETY_LINES`): the support wording of a note about stress or mood (FR3-105 AS-03).
	 */
	const URGENT_HELP = 'If you are struggling or feel unsafe, find urgent help in your country at findahelpline.com. If you are in danger now, call your local emergency number.';

	/** Focus blocks by content id, as LLD_Members_HW_Rules::BLOCKS names them (slug => id there). */
	const BLOCK_IDS = array(
		'HW-FB01' => 'evening-night-eating',
		'HW-FB02' => 'stress-emotional-eating',
		'HW-FB03' => 'eating-out-social-life',
		'HW-FB04' => 'breaking-a-plateau',
		'HW-FB05' => 'alcohol',
		'HW-FB06' => 'sleep-and-weight',
		'HW-FB07' => 'getting-stronger',
		'HW-FB08' => 'festivals-and-fasting',
		'HW-FB09' => 'shift-work',
	);

	/**
	 * How each approved question is answered: the answer key the programme stores, the kind of answer, and the value
	 * of each approved choice, in the approved choices' order, as LLD_Members_HW_Rules reads them (its class comment
	 * lists the values). A follow-up names its own key, the answers that show it, and either its own values (its
	 * wording is the question's approved follow-up line) or the question it is (`question`).
	 */
	const ANSWERS = array(
		'HW-QW-01' => array( 'key' => 'activity_level', 'type' => 'choice', 'values' => array( 'low', 'some', 'active', 'very_active' ) ),
		'HW-QW-02' => array(
			'key'       => 'bmi_background',
			'type'      => 'choice',
			'values'    => array( 'White', 'Indian', 'Pakistani', 'Bangladeshi', 'OtherSouthAsian', 'Chinese', 'OtherAsian', 'MiddleEastern', 'BlackAfrican', 'BlackCaribbean', 'Mixed', 'NotSay' ),
			'follow_up' => array( 'key' => 'bmi_mixed', 'when' => array( 'Mixed' ), 'type' => 'choice', 'values' => array( 'yes', 'no', 'notsure' ) ),
		),
		'HW-QW-03' => array(
			'key'       => 'reason',
			'type'      => 'choice',
			'values'    => array( 'health', 'confidence', 'family', 'wedding', 'holiday', 'event', 'other' ),
			'follow_up' => array( 'key' => 'event_date', 'when' => array( 'wedding', 'holiday', 'event' ), 'type' => 'date', 'values' => array() ),
		),
		'HW-QW-04' => array( 'key' => 'barrier', 'type' => 'choice', 'values' => array( 'evening_snacking', 'stress_eating', 'eating_out', 'no_time', 'alcohol', 'not_sure' ) ),
		'HW-QW-05' => array( 'key' => 'time', 'type' => 'choice', 'values' => array( 'under_10', '10_30', 'over_30' ) ),
		'HW-QW-06' => array(
			'key'       => 'work',
			'type'      => 'choice',
			'values'    => array( 'days', 'shifts', 'nights', 'irregular' ),
			'follow_up' => array( 'key' => 'wake_time', 'when' => array( 'shifts', 'nights', 'irregular' ), 'question' => 'HW-QW-07' ),
		),
		'HW-QW-07' => array( 'key' => 'wake_time', 'type' => 'time', 'values' => array() ),
		'HW-QW-08' => array( 'key' => 'confidence', 'type' => 'scale', 'values' => array() ),
		'HW-QW-09' => array( 'key' => 'sleep', 'type' => 'choice', 'values' => array( 'under_6', '6_7', '7_9', 'over_9' ) ),
		'HW-QW-10' => array( 'key' => 'stress', 'type' => 'scale', 'values' => array() ),
		'HW-QW-11' => array(
			'key'       => 'alcohol_freq',
			'type'      => 'choice',
			'values'    => array( 'never', 'less_than_weekly', '1_2_days', '3_4_days', 'most_days' ),
			'follow_up' => array( 'key' => 'alcohol_amount', 'when' => array( 'less_than_weekly', '1_2_days', '3_4_days', 'most_days' ), 'question' => 'HW-QW-12' ),
		),
		'HW-QW-12' => array( 'key' => 'alcohol_amount', 'type' => 'choice', 'values' => array( '1_2', '3_4', '5_plus' ) ),
		'HW-QW-13' => array( 'key' => 'food_rules', 'type' => 'multi', 'values' => array( 'halal', 'kosher', 'no_pork', 'no_beef', 'pescatarian', 'vegetarian', 'vegan', 'no_eggs', 'dairy_free', 'no_onion_garlic', 'no_grains_pulses_fasting', 'jain', 'none' ) ),
		'HW-QW-14' => array(
			'key'    => 'festivals',
			'type'   => 'multi',
			// The approved calendar's entry ids (HW-P3-35-09); a member's festival is every dated entry with that id.
			'values' => array( 'CAL-RAM', 'CAL-EIDF', 'CAL-EIDA', 'CAL-ARAF', 'CAL-LENT', 'CAL-EAST', 'CAL-GLENT', 'CAL-PASCHA', 'CAL-NATF', 'CAL-DORM', 'CAL-XMAS', 'CAL-DIW', 'CAL-NAV-C', 'CAL-NAV-S', 'CAL-EKA', 'CAL-HOLI', 'CAL-VAIS', 'CAL-PARY-S', 'CAL-PARY-D', 'CAL-PES', 'CAL-YK', 'CAL-HAN', 'CAL-LNY', 'CAL-BFAST', 'CAL-NAWRUZ', 'none' ),
		),
		// The allergen names the recipes record (the bundle's allergens field), so R-HW-FOOD-01 matches them.
		'HW-QW-15' => array( 'key' => 'allergies', 'type' => 'multi', 'values' => array( 'gluten', 'milk', 'eggs', 'peanuts', 'tree nuts', 'sesame', 'soya', 'fish', 'shellfish', 'celery', 'mustard', 'buckwheat', 'none' ) ),
		'HW-QW-16' => array(
			'key'       => 'breakfast',
			'type'      => 'choice',
			'values'    => array( 'most_days', 'some_days', 'rarely' ),
			'follow_up' => array( 'key' => 'breakfast_protein', 'when' => array( 'most_days' ), 'type' => 'choice', 'values' => array( 'yes', 'no', 'not_sure' ) ),
		),
		// The usual snack time, so R-HW-REM-01's reminder falls 30 minutes before it (the approved table).
		'HW-QW-17' => array( 'key' => 'snack_time', 'type' => 'choice', 'values' => array( '10:30', '15:30', '18:00', '20:00', '21:00', 'none' ) ),
		'HW-QW-18' => array( 'key' => 'sugary_drinks', 'type' => 'choice', 'values' => array( 'never', 'less_than_weekly', 'weekly', '4_6_days', 'daily' ) ),
		'HW-QW-19' => array( 'key' => 'eats_out', 'type' => 'choice', 'values' => array( 'less_than_weekly', '1_2', '3_plus' ) ),
		'HW-QW-20' => array( 'key' => 'cooking_time', 'type' => 'choice', 'values' => array( 'under_15', '15_30', 'over_30' ) ),
		'HW-QW-21' => array( 'key' => 'cooking_skill', 'type' => 'choice', 'values' => array( 'beginner', 'follows_recipes', 'loves_cooking' ) ),
		'HW-QW-22' => array( 'key' => 'budget', 'type' => 'choice', 'values' => array( 'tight', 'some_room', 'not_a_worry' ) ),
		// The recipes' cuisine names (meals.json pilot cuisines).
		'HW-QW-23' => array( 'key' => 'cuisines', 'type' => 'multi', 'values' => array( 'everyday-british-american', 'south-asian', 'italian-mediterranean', 'chinese', 'mexican-latin-american', 'west-african', 'all' ) ),
		'HW-QW-24' => array( 'key' => 'enjoyment', 'type' => 'multi', 'values' => array( 'walking', 'dancing', 'swimming', 'cycling', 'home_workouts', 'yoga_stretching', 'sport', 'gardening', 'not_sure' ) ),
	);

	/** Answer keys the screens also use for a question (the screens' own names, LLD_Members_Hw_Screen_Parts::QW_IDS). */
	const ANSWER_ALIASES = array(
		'eating_pattern'     => 'HW-QW-16',
		'eating_out'         => 'HW-QW-19',
		'cooking_skill'      => 'HW-QW-21',
		'food_budget'        => 'HW-QW-22',
		'favourite_cuisines' => 'HW-QW-23',
		'activity_enjoyment' => 'HW-QW-24',
	);

	/** When a line under a step is shown (the bundle's shown_under[].when). */
	const SHOWN_WHEN = array( 'always', 'shift_worker', 'fasting', 't2d_fasting', 'support_card' );

	/**
	 * Phrases that tell a Healthy Weight member calorie numbers never appear (TC-FR3-102-AS-07, TC-FR3-20-AS-03): the
	 * approved rewording removed every one, so a new item that says it again is refused.
	 */
	const NO_COUNT_RE = '/without counting|no calorie counting|not count anything|need to count anything|no numbers on the plate|there is no number to chase|never see (a )?calorie|calorie numbers (never|don[\'’]t) appear|no calorie numbers/i';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'seed_tags' ), 21 );
	}

	/**
	 * The P3-35 tags exist as managed lld_tag terms, so the loader accepts the bundle: `exercise` and `shift-worker`
	 * (item tags), and the habit and food-rule tags with their kinds (Data Model B2). A term that already exists keeps
	 * its kind (`stress`, `sleep` and `alcohol` are goal and safety tags first). Runs once.
	 */
	public static function seed_tags() {
		if ( '1' === (string) get_option( self::TAG_SEED ) || ! taxonomy_exists( 'lld_tag' ) ) {
			return;
		}
		$kinds = self::ITEM_TAGS;
		foreach ( self::HABIT_TAGS as $tag ) {
			$kinds += array( $tag => 'habit' );
		}
		foreach ( self::FOOD_RULE_TAGS as $tag ) {
			$kinds += array( $tag => 'food-rule' );
		}
		foreach ( $kinds as $name => $kind ) {
			$term = term_exists( $name, 'lld_tag' );
			if ( $term ) {
				continue;
			}
			$term = wp_insert_term( $name, 'lld_tag', array( 'slug' => $name ) );
			if ( is_wp_error( $term ) ) {
				return;
			}
			update_term_meta( (int) $term['term_id'], 'lld_kind', $kind );
		}
		update_option( self::TAG_SEED, '1', true );
	}

	// ---- Library look-ups (what members may be shown) ----------------------------------------------------------------

	/**
	 * The approved notes of a track or focus block, in their approved order (week, then day): the 56 programme notes
	 * for `healthy-weight`, 28 for each block's slug (FR3-105).
	 *
	 * @param string $track_key Track or block slug, for example healthy-weight or evening-night-eating.
	 * @return array<int,array> note_view() of each note.
	 */
	public static function notes( $track_key ) {
		$track = self::track_post( $track_key );
		if ( ! $track ) {
			return array();
		}
		$out = array();
		foreach ( self::approved( 'lld_note' ) as $id => $snap ) {
			if ( (int) ( $snap['fields']['track_id'] ?? 0 ) === $track ) {
				$out[] = self::note_view( $snap, $id );
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['week'], $a['day'] ) <=> array( $b['week'], $b['day'] );
			}
		);
		return $out;
	}

	/**
	 * One approved question, by the answer key the programme stores (sleep, work, food_rules …) or by its id
	 * (HW-QW-01 to HW-QW-24), or null. A follow-up that is its own question (wake_time after work, alcohol_amount after
	 * alcohol_freq) comes with that question's approved wording.
	 *
	 * @param string $key Answer key or question id.
	 * @return array|null question_view().
	 */
	public static function question( $key ) {
		$id  = self::question_id( (string) $key );
		$all = self::questions();
		foreach ( $all as $q ) {
			if ( $q['id_key'] === $id ) {
				if ( isset( self::ANSWERS[ $id ]['follow_up']['question'] ) && is_array( $q['follow_up'] ) ) {
					foreach ( $all as $f ) {
						if ( $f['id_key'] === self::ANSWERS[ $id ]['follow_up']['question'] ) {
							$q['follow_up'] = array_merge( $f, array( 'key' => $q['follow_up']['key'], 'when' => $q['follow_up']['when'] ) );
						}
					}
				}
				return $q;
			}
		}
		return null;
	}

	/**
	 * The question id for an answer key or id (pure).
	 *
	 * @param string $key Answer key or question id.
	 */
	public static function question_id( $key ) {
		if ( isset( self::ANSWERS[ $key ] ) ) {
			return $key;
		}
		if ( isset( self::ANSWER_ALIASES[ $key ] ) ) {
			return self::ANSWER_ALIASES[ $key ];
		}
		foreach ( self::ANSWERS as $id => $a ) {
			if ( $a['key'] === $key ) {
				return $id;
			}
		}
		return $key;
	}

	/**
	 * Every approved question, in key order (the approved wording's order, R-HW-QOD-01 within each part).
	 *
	 * @return array<int,array>
	 */
	public static function questions() {
		$out = array();
		foreach ( self::approved( 'lld_question' ) as $id => $snap ) {
			$out[] = self::question_view( $snap, $id );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['id_key'], $b['id_key'] );
			}
		);
		return $out;
	}

	/**
	 * One focus block by its slug, with its four weeks, or null when it has no approved version (FR3-104).
	 *
	 * @param string $key Block slug (LLD_Members_HW_Rules::BLOCKS), for example evening-night-eating.
	 * @return array|null {key, id, title, body, offer_rule_ids, offer_window, weeks: {n: {week_number, theme, task,
	 *                    task_alt, lesson, activities: day => activity, alts: [activity], notes: [note]}}}
	 */
	public static function block( $key ) {
		$key = (string) $key;
		if ( 'urgent_help' === $key ) {
			return array(
				'key'   => $key,
				'title' => '',
				'text'  => self::URGENT_HELP,
				'body'  => self::URGENT_HELP,
			);
		}
		if ( preg_match( '/^HW-MS-\d\d/', $key ) ) {
			return self::milestone_line( $key );
		}
		if ( isset( self::BLOCK_IDS[ $key ] ) ) {
			$block = self::block( self::BLOCK_IDS[ $key ] );
			if ( $block ) {
				$block['content_id'] = $key;
			}
			return $block;
		}
		$track = self::track_post( $key );
		$snap  = $track ? LLD_Members_Content::shown( $track ) : null;
		if ( ! is_array( $snap ) || 'focus_block' !== (string) ( $snap['fields']['track_kind'] ?? '' ) ) {
			return null;
		}
		$weeks = array();
		for ( $w = 1; $w <= 4; $w++ ) {
			$weeks[ $w ] = array(
				'week_number' => $w,
				'theme'       => '',
				'task'        => null,
				'task_alt'    => null,
				'lesson'      => null,
				'activities'  => array(),
				'alts'        => array(),
				'notes'       => array(),
			);
		}
		foreach ( array( 'lld_week', 'lld_lesson', 'lld_activity' ) as $type ) {
			foreach ( self::approved( $type ) as $id => $item ) {
				$f = $item['fields'] ?? array();
				$w = (int) ( $f['week_number'] ?? 0 );
				if ( (int) ( $f['track_id'] ?? 0 ) !== $track || ! isset( $weeks[ $w ] ) ) {
					continue;
				}
				$view = array(
					'id'     => self::source_id( $id ),
					'title'  => (string) ( $item['title'] ?? '' ),
					'body'   => (string) ( $item['body'] ?? '' ),
					'tags'   => array_values( (array) ( $item['tags'] ?? array() ) ),
					'fields' => $f,
				);
				if ( 'lld_week' === $type ) {
					$alt = '' !== (string) ( $f['alt_for'] ?? '' );
					if ( ! $alt ) {
						$weeks[ $w ]['theme'] = $view['title'];
					}
					$weeks[ $w ][ $alt ? 'task_alt' : 'task' ] = $view;
				} elseif ( 'lld_lesson' === $type ) {
					$weeks[ $w ]['lesson'] = $view;
				} else {
					$view['day']  = (int) ( $f['day_number'] ?? 0 );
					$view['meta'] = self::activity_meta_view( $f );
					if ( '' !== $view['meta']['alt_for'] ) {
						$weeks[ $w ]['alts'][] = $view;
					} elseif ( $view['day'] >= 1 && $view['day'] <= 7 ) {
						$weeks[ $w ]['activities'][ $view['day'] ] = $view;
					}
				}
			}
		}
		foreach ( self::notes( $key ) as $note ) {
			if ( isset( $weeks[ $note['week'] ] ) ) {
				$weeks[ $note['week'] ]['notes'][] = $note;
			}
		}
		foreach ( $weeks as $w => $week ) {
			ksort( $weeks[ $w ]['activities'] );
		}
		return array(
			'key'            => (string) $key,
			'id'             => $track,
			'content_id'     => (string) array_search( (string) $key, self::BLOCK_IDS, true ),
			'title'          => (string) ( $snap['title'] ?? '' ),
			'text'           => (string) ( $snap['body'] ?? '' ),
			'body'           => (string) ( $snap['body'] ?? '' ),
			'offer_rule_ids' => array_values( (array) ( $snap['fields']['offer_rule_ids'] ?? array() ) ),
			'offer_window'   => (string) ( $snap['fields']['offer_window'] ?? '' ),
			'weeks'          => $weeks,
		);
	}

	/**
	 * The FR3-103 details of one activity: habit tags, easier and stretch versions (its body's instruction is the
	 * standard), the -ALT item it stands in for, and the lines shown under every version, or null when the activity
	 * has no approved version.
	 *
	 * @param int|string $activity_id Post id, bundle key (act:HW-W1-A1) or draft id (HW-W1-A1, HW-FB05-W1-A5-ALT).
	 * @return array|null activity_meta_view().
	 */
	public static function activity_meta( $activity_id ) {
		$id = is_numeric( $activity_id ) ? (int) $activity_id : self::post_by_key( 'lld_activity', self::activity_key( (string) $activity_id ) );
		if ( $id < 1 ) {
			return null;
		}
		$snap = LLD_Members_Content::shown( $id );
		if ( ! is_array( $snap ) || 'lld_activity' !== (string) ( $snap['type'] ?? 'lld_activity' ) ) {
			return null;
		}
		$meta = self::activity_meta_view( isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array() );
		return array(
			'id'    => self::source_id( $id ),
			'title' => (string) ( $snap['title'] ?? '' ),
			'body'  => (string) ( $snap['body'] ?? '' ),
		) + $meta;
	}

	/**
	 * An approved milestone health line (HW-MS-03, HW-MS-03-older for 65 and over, HW-MS-04), or null.
	 *
	 * @param string $key Line id.
	 * @return array|null {key, title, text, body}
	 */
	private static function milestone_line( $key ) {
		$kind = strtolower( (string) preg_replace( '/-older$/', '-65-plus', $key ) );
		foreach ( self::approved( 'lld_wording' ) as $snap ) {
			if ( (string) ( $snap['fields']['kind'] ?? '' ) === $kind ) {
				return array(
					'key'   => $key,
					'title' => (string) ( $snap['title'] ?? '' ),
					'text'  => (string) ( $snap['body'] ?? '' ),
					'body'  => (string) ( $snap['body'] ?? '' ),
				);
			}
		}
		return null;
	}

	// ---- The festival calendar -------------------------------------------------------------------------------------

	/**
	 * The approved festival calendar, in date order, as the engine reads it (LLD_Members_HW_Festival::offer() takes
	 * {id, name, start}): each entry's bundle fields plus id, start and end. Empty until the table exists.
	 *
	 * @return array<int,array>
	 */
	public static function festivals() {
		global $wpdb;
		$table = self::table();
		if ( ! self::table_exists() ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY starts_on ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$entry = self::festival_view( (array) $row );
			if ( '' !== $entry['approved_on'] ) {
				$out[] = $entry; // Data Model: approved_on is required before members see an entry
			}
		}
		return $out;
	}

	/**
	 * Write the bundle's approved festival calendar into the festival_calendar table (package P2 creates it), keyed by
	 * the entry key: a new entry is added, an existing one is brought up to the approved dates. Nothing is written when
	 * the table does not exist yet, or when any entry fails festival_problems().
	 *
	 * @param array $list The bundle's festivals[] (FESTIVAL_FIELDS each).
	 * @return array {written, created, updated, skipped: reason or '', problems: []}
	 */
	public static function load_festivals( array $list ) {
		global $wpdb;
		$out = array(
			'written'  => 0,
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => '',
			'problems' => self::festival_problems( $list ),
		);
		if ( $out['problems'] ) {
			$out['skipped'] = 'the festival calendar has problems';
			return $out;
		}
		if ( ! self::table_exists() ) {
			$out['skipped'] = 'the ' . self::table() . ' table does not exist yet (package P2 creates it)';
			return $out;
		}
		$table   = self::table();
		$columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keycol  = '';
		foreach ( self::KEY_COLUMNS as $c ) {
			if ( in_array( $c, $columns, true ) ) {
				$keycol = $c;
				break;
			}
		}
		foreach ( $list as $entry ) {
			$row = self::festival_row( (array) $entry, $columns, $keycol );
			if ( $keycol ) {
				$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$keycol} = %s", (string) $entry['key'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s AND starts_on = %s", (string) $entry['name'], (string) $entry['starts_on'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( $found ) {
				$ok = false !== $wpdb->update( $table, $row, array( 'id' => (int) $found ) );
				$out['updated'] += $ok ? 1 : 0;
			} else {
				$ok = false !== $wpdb->insert( $table, $row );
				$out['created'] += $ok ? 1 : 0;
			}
			$out['written'] += $ok ? 1 : 0;
		}
		return $out;
	}

	// ---- Pure helpers -----------------------------------------------------------------------------------------------

	/**
	 * A note as the programme shows it (pure).
	 *
	 * @param array $snap An approved lld_note snapshot.
	 * @param int   $id   Its post id.
	 * @return array {key, id, title, body, try_this, week, day, support_wording: bool, tags}
	 */
	public static function note_view( array $snap, $id = 0 ) {
		$f = isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array();
		return array(
			'key'             => (string) ( $f['note_key'] ?? '' ),
			'id'              => (int) $id,
			'title'           => (string) ( $snap['title'] ?? '' ),
			'body'            => (string) ( $snap['body'] ?? '' ),
			'try_this'        => (string) ( $f['try_this'] ?? '' ),
			'week'            => (int) ( $f['week'] ?? 0 ),
			'day'             => (int) ( $f['day'] ?? 0 ),
			'support_wording' => ! empty( $f['support_wording'] ),
			'support'         => ! empty( $f['support_wording'] ),
			'support_line'    => ! empty( $f['support_wording'] ) ? self::URGENT_HELP : null,
			'tags'            => array_values( (array) ( $snap['tags'] ?? array() ) ),
		);
	}

	/**
	 * A question as a screen asks it (pure).
	 *
	 * @param array $snap An approved lld_question snapshot.
	 * @param int   $id   Its post id.
	 * @return array {key, id, title, body, choices, why, asked_in, rule_ids, excluded_for, answer, follow_up}
	 */
	public static function question_view( array $snap, $id = 0 ) {
		$f      = isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array();
		$qid    = (string) ( $f['question_key'] ?? '' );
		$spec   = self::ANSWERS[ $qid ] ?? array( 'key' => $qid, 'type' => 'choice', 'values' => array() );
		$labels = array_values( array_map( 'strval', (array) ( $f['choices'] ?? array() ) ) );
		$answer = (string) ( $f['answer'] ?? '' );
		$scale  = array();
		if ( preg_match_all( '/["“](\d+) = ([^"”]+)["”]/u', $answer, $m ) && count( $m[1] ) >= 2 ) {
			$scale = array( (int) $m[1][0], (int) end( $m[1] ), $m[2][0], end( $m[2] ) );
		}
		$follow = null;
		if ( isset( $spec['follow_up'] ) ) {
			$fu     = $spec['follow_up'];
			$parsed = self::follow_up_text( (string) ( $f['follow_up'] ?? '' ) );
			$follow = array(
				'key'     => $fu['key'],
				'when'    => $fu['when'],
				'text'    => $parsed['text'],
				'type'    => $fu['type'] ?? 'choice',
				'choices' => self::choices( $parsed['choices'], $fu['values'] ?? array() ),
			);
		}
		return array(
			'key'          => (string) $spec['key'],
			'id_key'       => $qid,
			'id'           => (int) $id,
			'text'         => (string) ( $snap['title'] ?? '' ),
			'title'        => (string) ( $snap['title'] ?? '' ),
			'why'          => (string) ( $f['why'] ?? '' ),
			'note'         => (string) ( $snap['body'] ?? '' ),
			'type'         => (string) $spec['type'],
			'choices'      => self::choices( $labels, $spec['values'] ),
			'min'          => $scale ? $scale[0] : 1,
			'max'          => $scale ? $scale[1] : 10,
			'low'          => $scale ? $scale[2] : '',
			'high'         => $scale ? $scale[3] : '',
			'follow_up'    => $follow,
			'asked_in'     => (string) ( $f['asked_in'] ?? '' ),
			'rule_ids'     => array_values( array_map( 'strval', (array) ( $f['rule_ids'] ?? array() ) ) ),
			'excluded_for' => array_values( array_map( 'strval', (array) ( $f['excluded_for'] ?? array() ) ) ),
			'answer'       => $answer,
		);
	}

	/**
	 * Approved choice labels paired with their answer values, in order (pure). A label with no value (the approved
	 * list grew) keeps its own words as its value, so nothing approved is ever dropped.
	 *
	 * @param string[] $labels Approved labels.
	 * @param string[] $values Answer values.
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function choices( array $labels, array $values ) {
		$out = array();
		foreach ( array_values( $labels ) as $i => $label ) {
			$out[] = array(
				'value' => isset( $values[ $i ] ) ? (string) $values[ $i ] : (string) $label,
				'label' => (string) $label,
			);
		}
		return $out;
	}

	/**
	 * The wording of a follow-up line from the approved question (pure): 'Follow-up after "Most days": "Does it …?" ·
	 * Yes · No · Not sure.' gives the quoted question and the choices after it; 'For an event: an optional date,
	 * "When is it?" …' gives the quoted question.
	 *
	 * @param string $line Approved follow-up line.
	 * @return array{text:string,choices:string[]}
	 */
	public static function follow_up_text( $line ) {
		$body = preg_replace( '/^[^:]*?:\s*/', '', (string) $line, 1 );
		if ( ! preg_match( '/["“]([^"”]+)["”](.*)$/u', (string) $body, $m ) ) {
			return array( 'text' => '', 'choices' => array() );
		}
		$choices = array();
		if ( preg_match( '/^\s*·\s*(.+?)(?:\.\s|\.$|$)/u', $m[2], $c ) ) {
			$choices = array_values( array_filter( array_map( 'trim', explode( '·', $c[1] ) ) ) );
		}
		return array( 'text' => trim( $m[1] ), 'choices' => $choices );
	}

	/**
	 * An activity's FR3-103 fields (pure). An activity loaded before P3-35, or one without versions, gives empty values.
	 *
	 * @param array $fields The activity's approved fields.
	 * @return array {habit_tags, easier, stretch, alt_for, shown_under: [{text, when}]}
	 */
	public static function activity_meta_view( array $fields ) {
		$under = array();
		foreach ( (array) ( $fields['shown_under'] ?? array() ) as $line ) {
			if ( is_array( $line ) && '' !== trim( (string) ( $line['text'] ?? '' ) ) ) {
				$when    = (string) ( $line['when'] ?? 'always' );
				$under[] = array(
					'text' => (string) $line['text'],
					'when' => in_array( $when, self::SHOWN_WHEN, true ) ? $when : 'always',
				);
			}
		}
		return array(
			'habit_tags'  => array_values( array_intersect( array_map( 'strval', (array) ( $fields['habit_tags'] ?? array() ) ), self::HABIT_TAGS ) ),
			'easier'      => (string) ( $fields['easier'] ?? '' ),
			'stretch'     => (string) ( $fields['stretch'] ?? '' ),
			'alt_for'     => (string) ( $fields['alt_for'] ?? '' ),
			'shown_under' => $under,
		);
	}

	/**
	 * What is wrong with a festival list (pure): every entry needs a unique key, a name, a known kind, S and E as
	 * Y-m-d dates with S on or before E, an approved_on date, and yes-no expected and offers_block.
	 *
	 * @param array $list Festival entries.
	 * @return string[]
	 */
	public static function festival_problems( array $list ) {
		$problems = array();
		$seen     = array();
		$date     = '/^\d{4}-\d{2}-\d{2}$/';
		foreach ( array_values( $list ) as $i => $e ) {
			$where = 'festival ' . ( $i + 1 ) . ( is_array( $e ) && isset( $e['key'] ) ? ' (' . $e['key'] . ')' : '' );
			if ( ! is_array( $e ) ) {
				$problems[] = "$where is not an object";
				continue;
			}
			$key = (string) ( $e['key'] ?? '' );
			if ( ! preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $key ) ) {
				$problems[] = "$where has no valid key";
			} elseif ( isset( $seen[ $key ] ) ) {
				$problems[] = "$where repeats a key";
			}
			$seen[ $key ] = true;
			if ( '' === trim( (string) ( $e['name'] ?? '' ) ) ) {
				$problems[] = "$where has no name";
			}
			if ( ! in_array( $e['kind'] ?? '', self::FESTIVAL_KINDS, true ) ) {
				$problems[] = "$where has an unknown kind";
			}
			$s = (string) ( $e['starts_on'] ?? '' );
			$x = (string) ( $e['ends_on'] ?? '' );
			if ( ! preg_match( $date, $s ) || ! preg_match( $date, $x ) || ! self::real_date( $s ) || ! self::real_date( $x ) ) {
				$problems[] = "$where has no valid first and last day";
			} elseif ( $s > $x ) {
				$problems[] = "$where ends before it starts";
			}
			if ( ! preg_match( $date, (string) ( $e['approved_on'] ?? '' ) ) ) {
				$problems[] = "$where has no approval date";
			}
			foreach ( array( 'expected', 'offers_block' ) as $flag ) {
				if ( ! is_bool( $e[ $flag ] ?? null ) ) {
					$problems[] = "$where has no yes-no $flag";
				}
			}
		}
		return $problems;
	}

	/**
	 * What is wrong with the bundle's Healthy Weight items (pure): a note without its key, track, week and day or its
	 * "Try this today" line, a question without its key, choices or reason, a habit tag or food-rule tag that is not on
	 * the approved list, a line under a step with no wording, and a new Healthy Weight item (stage 3, or with habit
	 * tags) that says calorie numbers never appear (TC-FR3-102-AS-07).
	 *
	 * @param array $items Bundle items.
	 * @return string[]
	 */
	public static function content_problems( array $items ) {
		$problems = array();
		$tracks   = array( 'track:healthy-weight', 'track:healthy-weight-2', 'track:keep-it-off' );
		foreach ( $items as $it ) {
			if ( is_array( $it ) && 'focus_block' === (string) ( $it['fields']['track_kind'] ?? '' ) ) {
				$tracks[] = (string) ( $it['key'] ?? '' );
			}
		}
		foreach ( array_values( $items ) as $i => $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$where = 'item ' . ( $i + 1 ) . ( isset( $it['key'] ) ? ' (' . $it['key'] . ')' : '' );
			$type  = (string) ( $it['type'] ?? '' );
			$f     = isset( $it['fields'] ) && is_array( $it['fields'] ) ? $it['fields'] : array();
			if ( 'lld_note' === $type ) {
				$week = (int) ( $f['week'] ?? 0 );
				$day  = (int) ( $f['day'] ?? 0 );
				if ( '' === (string) ( $f['note_key'] ?? '' ) || ! preg_match( '/^@[A-Za-z0-9:._-]+$/', (string) ( $f['track_id'] ?? '' ) ) || $week < 1 || $week > 8 || $day < 1 || $day > 7 ) {
					$problems[] = "$where is a note without its key, track, week (1 to 8) and day (1 to 7)";
				}
				if ( '' === trim( (string) ( $f['try_this'] ?? '' ) ) || '' === trim( (string) ( $it['body'] ?? '' ) ) ) {
					$problems[] = "$where is a note without its text and 'Try this today' line";
				}
			}
			if ( 'lld_question' === $type ) {
				if ( '' === (string) ( $f['question_key'] ?? '' ) || '' === trim( (string) ( $f['why'] ?? '' ) ) || ! is_array( $f['choices'] ?? null ) ) {
					$problems[] = "$where is a question without its key, choices and reason";
				}
				if ( ! array_filter( (array) ( $f['rule_ids'] ?? array() ) ) ) {
					$problems[] = "$where is a question with no signed rule (TC-FR3-99-AS-04)";
				}
			}
			$bad = array_diff( array_map( 'strval', (array) ( $f['habit_tags'] ?? array() ) ), self::HABIT_TAGS );
			if ( $bad ) {
				$problems[] = "$where has a habit tag that is not on the approved list";
			}
			$bad = array_diff( array_map( 'strval', (array) ( $f['food_rules'] ?? array() ) ), self::FOOD_RULE_TAGS );
			if ( $bad ) {
				$problems[] = "$where has a food-rule tag that is not on the approved list";
			}
			foreach ( (array) ( $f['shown_under'] ?? array() ) as $line ) {
				if ( ! is_array( $line ) || '' === trim( (string) ( $line['text'] ?? '' ) ) || ! in_array( $line['when'] ?? '', self::SHOWN_WHEN, true ) ) {
					$problems[] = "$where has a line under the step without its wording or when it is shown";
					break;
				}
			}
			$new = 3 === ( $it['stage'] ?? null ) || ! empty( $f['habit_tags'] );
			if ( $new && in_array( $type, array( 'lld_lesson', 'lld_activity', 'lld_week', 'lld_track', 'lld_note' ), true ) && self::healthy_weight( $it, $tracks ) ) {
				$text = (string) ( $it['title'] ?? '' ) . ' ' . (string) ( $it['body'] ?? '' ) . ' ' . wp_json_encode_safe( $f );
				if ( preg_match( self::NO_COUNT_RE, $text ) ) {
					$problems[] = "$where says calorie numbers never appear, which Healthy Weight no longer says (FR3-102)";
				}
			}
		}
		return $problems;
	}

	/**
	 * True for an item of the Healthy Weight programme or Keep It Off (pure): its key names the two levels, Keep It Off,
	 * a Healthy Weight step, note or guide, it is a focus block, or it belongs to one of these tracks.
	 *
	 * @param array    $it     Bundle item.
	 * @param string[] $tracks Keys of the Healthy Weight tracks and focus blocks in the bundle.
	 */
	public static function healthy_weight( array $it, array $tracks = array( 'track:healthy-weight', 'track:healthy-weight-2', 'track:keep-it-off' ) ) {
		$key = (string) ( $it['key'] ?? '' );
		$f   = isset( $it['fields'] ) && is_array( $it['fields'] ) ? $it['fields'] : array();
		$ref = preg_replace( '/^@/', '', (string) ( $f['track_id'] ?? '' ) );
		return (bool) preg_match( '/^(?:(?:track|week|lesson):(?:healthy-weight|healthy-weight-2|keep-it-off)(?::|$)|act:(?:HW|HW2|KO)-|note:HW-|guide:)/', $key )
			|| 'focus_block' === (string) ( $f['track_kind'] ?? '' ) || in_array( $ref, $tracks, true ) || in_array( $key, $tracks, true );
	}

	/**
	 * One stored row for a festival entry (pure): the entry's fields that the table has columns for, with the key in
	 * the key column.
	 *
	 * @param array  $entry   Festival entry.
	 * @param array  $columns The table's columns.
	 * @param string $keycol  Column that holds the key, or ''.
	 * @return array<string,mixed>
	 */
	public static function festival_row( array $entry, array $columns, $keycol = '' ) {
		$row = array();
		foreach ( self::FESTIVAL_FIELDS as $field ) {
			if ( 'key' === $field ) {
				if ( $keycol ) {
					$row[ $keycol ] = (string) $entry['key'];
				}
				continue;
			}
			if ( ! in_array( $field, $columns, true ) ) {
				continue;
			}
			$value         = $entry[ $field ] ?? '';
			$row[ $field ] = is_bool( $value ) ? ( $value ? 1 : 0 ) : (string) $value;
		}
		return $row;
	}

	/**
	 * A stored festival row as festivals() returns it (pure).
	 *
	 * @param array $row Table row.
	 * @return array
	 */
	public static function festival_view( array $row ) {
		$key = '';
		foreach ( self::KEY_COLUMNS as $c ) {
			if ( isset( $row[ $c ] ) && '' !== (string) $row[ $c ] ) {
				$key = (string) $row[ $c ];
				break;
			}
		}
		$out = array(
			'key'          => $key,
			'name'         => (string) ( $row['name'] ?? '' ),
			'kind'         => (string) ( $row['kind'] ?? '' ),
			'starts_on'    => (string) ( $row['starts_on'] ?? '' ),
			'ends_on'      => (string) ( $row['ends_on'] ?? '' ),
			'expected'     => ! empty( $row['expected'] ),
			'approved_on'  => (string) ( $row['approved_on'] ?? '' ),
			'offers_block' => ! empty( $row['offers_block'] ),
			'tradition'    => (string) ( $row['tradition'] ?? '' ),
		);
		$out['id']    = '' !== $key ? $key : (string) ( $row['id'] ?? '' );
		$out['start'] = $out['starts_on'];
		$out['end']   = $out['ends_on'];
		return $out;
	}

	// ---- Internals --------------------------------------------------------------------------------------------------

	/**
	 * Approved snapshots of one type, by post id, in id order (the library's own query, so the Stage 3 filter applies).
	 *
	 * @param string $type Content type.
	 * @return array<int,array>
	 */
	private static function approved( $type ) {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( (array) $posts as $post ) {
			$id   = is_object( $post ) ? (int) $post->ID : (int) $post;
			$snap = LLD_Members_Content::shown( $id );
			if ( is_array( $snap ) ) {
				$out[ $id ] = $snap;
			}
		}
		return $out;
	}

	/**
	 * Post id of a track or block by its slug, or 0.
	 *
	 * @param string $key Slug.
	 */
	private static function track_post( $key ) {
		if ( '' === (string) $key ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lld_track',
				'name'           => (string) $key,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => 1,
			)
		);
		if ( ! $posts ) {
			return 0;
		}
		return is_object( $posts[0] ) ? (int) $posts[0]->ID : (int) $posts[0];
	}

	/**
	 * Post id of a library item by its bundle key, or 0.
	 *
	 * @param string $type Content type.
	 * @param string $key  Bundle key.
	 */
	private static function post_by_key( $type, $key ) {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => 1,
				'meta_key'       => '_lld_source_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! $posts ) {
			return 0;
		}
		return is_object( $posts[0] ) ? (int) $posts[0]->ID : (int) $posts[0];
	}

	/**
	 * The bundle key of an activity given its draft id or key.
	 *
	 * @param string $id Draft id or key.
	 */
	private static function activity_key( $id ) {
		return 0 === strpos( $id, 'act:' ) || 0 === strpos( $id, 'chact:' ) ? $id : 'act:' . $id;
	}

	/**
	 * The draft id an item was loaded from (act:HW-FB01-W1-A1 gives HW-FB01-W1-A1), or its post id as a string.
	 *
	 * @param int $id Post id.
	 */
	private static function source_id( $id ) {
		$key = (string) get_post_meta( (int) $id, '_lld_source_key', true );
		return '' !== $key ? (string) preg_replace( '/^[a-z]+:/', '', $key ) : (string) $id;
	}

	private static function table() {
		return LLD_Members_Schema::table( self::TABLE );
	}

	private static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * True for a real calendar date in Y-m-d.
	 *
	 * @param string $date Date.
	 */
	private static function real_date( $date ) {
		$p = array_map( 'intval', explode( '-', $date ) );
		return 3 === count( $p ) && checkdate( $p[1], $p[2], $p[0] );
	}
}

if ( ! function_exists( 'wp_json_encode_safe' ) ) {
	/**
	 * json_encode with WordPress's wp_json_encode when it is there (pure fallback for the tests).
	 *
	 * @param mixed $value Value.
	 */
	function wp_json_encode_safe( $value ) {
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value ) : (string) json_encode( $value );
	}
}
