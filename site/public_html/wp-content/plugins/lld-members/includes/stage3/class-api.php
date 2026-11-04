<?php
/**
 * The members' programming interface for Stage 3 data (NFR3-09, NFR3-10, FR4-12). The website's screens and
 * the Stage 4 app both use it. Every route is /wp-json/lld-members/v1/me/<type>: there is no member number
 * in the address, so a member can only ever reach their own rows (TC-NFR3-09-AS-02).
 *
 * Order on every call: feature switch, then sign-in, then health consent for health types, then the data.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic, typed routes over LLD_Members_Stage3_Data.
 */
class LLD_Members_Stage3_API {

	/**
	 * Every data type the interface serves. Keys: table (Stage 3 suffix), feature (switch that opens it),
	 * health (needs health consent), sealed (fields sealed at rest), fields (name => rule), write (false for
	 * read-only), patch (fields a member may change after creation; empty = any writable field).
	 *
	 * Rules: int, number[min,max], text[max], choice:a|b|c, date, datetime, time, bool, json.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types() {
		return array(
			'goals'              => array(
				'table'   => 'goals',
				'feature' => 'goals',
				'health'  => true,
				'sealed'  => array( 'start_value', 'target_value' ),
				'fields'  => array(
					'role'          => 'choice:main|extra',
					'goal_type'     => 'choice:lose_weight|keep_weight|lower_blood_pressure|blood_sugar|activity|strength|sleep|other',
					'measure'       => 'choice:weight|waist|blood_pressure|glucose|hba1c|activity_minutes|steps|sleep_hours|reps',
					'start_value'   => 'number[0,100000]',
					'target_value'  => 'number[0,100000]',
					'unit'          => 'choice:kg|lb|cm|in|mmHg|mmol/L|mg/dL|%|minutes|steps|hours|reps',
					'target_date'   => 'date',
					'target_source' => 'choice:member|doctor|guideline_default',
					'status'        => 'choice:active|reached|stopped|replaced',
					'reached_at'    => 'datetime',
				),
				'required' => array( 'role', 'goal_type', 'measure', 'unit' ),
			),
			'plans'              => array(
				'table'   => 'plans',
				'feature' => 'goals',
				'health'  => true,
				'sealed'  => array(),
				'fields'  => array(
					'goal_id'               => 'int',
					'version'               => 'int',
					'track_id'              => 'int',
					'calorie_target'        => 'number[0,10000]',
					'protein_g'             => 'number[0,1000]',
					'carb_g'                => 'number[0,2000]',
					'fat_g'                 => 'number[0,1000]',
					'fibre_g'               => 'number[0,300]',
					'activity_minutes_week' => 'number[0,10000]',
					'meal_plan_id'          => 'int',
					'workout_plan_id'       => 'int',
					'log_schedule'          => 'json',
					'valid_from'            => 'date',
					'valid_to'              => 'date',
					'created_by'            => 'choice:engine|member',
				),
				'required' => array( 'goal_id' ),
			),
			'today'              => array(
				'table'   => 'today_steps',
				'feature' => 'today',
				'health'  => false,
				'sealed'  => array(),
				'fields'  => array(
					'step_date'   => 'date',
					'action_type' => 'choice:activity|log_reading|log_food|read|workout|check_in',
					'ref_id'      => 'int',
					'rule_id'     => 'int',
					'status'      => 'choice:shown|done|skipped',
				),
				'required' => array( 'step_date', 'action_type' ),
				'patch'    => array( 'status' ),
			),
			'insights'           => array(
				'table'   => 'insights',
				'feature' => 'insights',
				'health'  => true,
				'sealed'  => array( 'values' ),
				'fields'  => array( 'dismissed' => 'bool' ),
				'write'   => false,
				'patch'   => array( 'dismissed' ),
			),
			'custom-foods'       => array(
				'table'   => 'custom_foods',
				'feature' => 'food_tracker',
				'health'  => false,
				'sealed'  => array(),
				'fields'  => array(
					'name'      => 'text[255]',
					'portion_g' => 'number[0.1,5000]',
					'kcal'      => 'number[0,9000]',
					'protein_g' => 'number[0,1000]',
					'carb_g'    => 'number[0,2000]',
					'fat_g'     => 'number[0,1000]',
					'fibre_g'   => 'number[0,300]',
					'barcode'   => 'text[32]',
				),
				'required' => array( 'name', 'portion_g' ),
			),
			'food-log'           => array(
				'table'   => 'food_log',
				'feature' => 'food_tracker',
				'health'  => true,
				'sealed'  => array(),
				'fields'  => array(
					'entry_date'    => 'date',
					'meal'          => 'choice:breakfast|lunch|dinner|snacks',
					'food_id'       => 'int',
					'food_kind'     => 'choice:food|custom|recipe',
					'grams'         => 'number[0.1,5000]',
					'portion_label' => 'text[64]',
					'kcal'          => 'number[0,20000]',
					'protein_g'     => 'number[0,2000]',
					'carb_g'        => 'number[0,4000]',
					'fat_g'         => 'number[0,2000]',
					'fibre_g'       => 'number[0,600]',
					'source'        => 'choice:search|barcode|recent|favourite|copy|recipe|ai_text|ai_photo',
				),
				'required' => array( 'entry_date', 'meal', 'food_id', 'food_kind', 'grams' ),
			),
			'favourites'         => array(
				'table'   => 'food_favourites',
				'feature' => 'food_tracker',
				'health'  => false,
				'sealed'  => array(),
				'fields'  => array(
					'food_id'   => 'int',
					'food_kind' => 'choice:food|custom|recipe',
				),
				'required' => array( 'food_id' ),
			),
			'targets'            => array(
				'table'   => 'nutrition_targets',
				'feature' => 'food_tracker',
				'health'  => true,
				'sealed'  => array(),
				'fields'  => array(
					'kcal'       => 'number[0,10000]',
					'protein_g'  => 'number[0,1000]',
					'carb_g'     => 'number[0,2000]',
					'fat_g'      => 'number[0,1000]',
					'fibre_g'    => 'number[0,300]',
					'set_by'     => 'choice:plan|calculator|member',
					'valid_from' => 'date',
				),
				'required' => array( 'valid_from' ),
			),
			'recipes'            => array(
				'table'   => 'recipes',
				'feature' => 'food_tracker',
				'health'  => false,
				'sealed'  => array(),
				'fields'  => array(
					'name'                => 'text[255]',
					'servings'            => 'number[1,50]',
					'ingredients'         => 'json',
					'kcal_per_serving'    => 'number[0,20000]',
					'protein_per_serving' => 'number[0,2000]',
					'carb_per_serving'    => 'number[0,4000]',
					'fat_per_serving'     => 'number[0,2000]',
					'fibre_per_serving'   => 'number[0,600]',
				),
				'required' => array( 'name', 'servings', 'ingredients' ),
			),
			'readings'           => array(
				'table'   => 'readings',
				'feature' => 'health_logging',
				'health'  => true,
				'sealed'  => array( 'value_1', 'value_2', 'value_3', 'detail' ),
				'fields'  => array(
					'reading_type' => 'choice:blood_pressure|weight|waist|activity|sleep|water|mood|glucose',
					'taken_at'     => 'datetime',
					'value_1'      => 'number[0,100000]',
					'value_2'      => 'number[0,100000]',
					'value_3'      => 'number[0,100000]',
					'detail'       => 'text[280]',
					'unit_shown'   => 'choice:kg|lb|cm|in|mmol/L|mg/dL|ml|glasses',
					'urgent_shown' => 'bool',
					'source'       => 'choice:manual|quick_tile|apple_health|health_connect|fitbit|garmin',
					'external_ref' => 'text[128]',
				),
				'required' => array( 'reading_type', 'taken_at', 'value_1' ),
			),
			'calculator-results' => array(
				'table'   => 'calculator_result',
				'feature' => 'calculators_new',
				'health'  => true,
				'stage2'  => true,
				'write'   => false,
				'sealed'  => array(),
				'fields'  => array(),
			),
			'reminders'          => array(
				'table'   => 'reminders',
				'feature' => 'reminders',
				'health'  => false,
				'sealed'  => array(),
				'fields'  => array(
					'reminder_type' => 'choice:log|workout|weigh_in|check_in',
					'channel'       => 'choice:email|browser|push',
					'time_local'    => 'time',
					'days'          => 'text[32]',
					'active'        => 'bool',
				),
				'required' => array( 'reminder_type', 'time_local', 'days' ),
			),
			'shares'             => array(
				'table'   => 'shares',
				'feature' => 'reminders',
				'health'  => false,
				'sealed'  => array(),
				'fields'  => array(
					'include_health' => 'bool',
					'content'        => 'text[4000]',
				),
				'required' => array(),
			),
			// Computed views (P3-41). They are not tables: the food log class builds them.
			'food-day'           => array(
				'table'    => 'food_log',
				'feature'  => 'food_tracker',
				'health'   => true,
				'computed' => 'day',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
			'food-search'        => array(
				'table'    => 'custom_foods',
				'feature'  => 'food_tracker',
				'health'   => false,
				'computed' => 'search',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
			'food-recent'        => array(
				'table'    => 'food_log',
				'feature'  => 'food_tracker',
				'health'   => true,
				'computed' => 'recent',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
			'food-copy'          => array(
				'table'    => 'food_log',
				'feature'  => 'food_tracker',
				'health'   => true,
				'computed' => 'copy',
				'sealed'   => array(),
				'fields'   => array(
					'from_date' => 'date',
					'to_date'   => 'date',
					'meal'      => 'choice:breakfast|lunch|dinner|snacks',
				),
				'required' => array( 'from_date', 'to_date' ),
			),
			// Weekly summary and suggestions (P3-43).
			'food-week'          => array(
				'table'    => 'food_log',
				'feature'  => 'food_tracker',
				'health'   => true,
				'computed' => 'week',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
			'recipe-suggestions' => array(
				'table'    => 'recipes',
				'feature'  => 'food_tracker',
				'health'   => false,
				'computed' => 'suggest',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
			'food-preferences'   => array(
				'table'   => 'food_preferences',
				'feature' => 'food_tracker',
				'health'  => true,
				'sealed'  => array(),
				'fields'  => array(
					'allergies' => 'json',
					'dislikes'  => 'json',
				),
				'required' => array(),
			),
			// Health logging views (P3-50).
			'readings-trend'         => array(
				'table'    => 'readings',
				'feature'  => 'health_logging',
				'health'   => true,
				'computed' => 'trend',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
			'readings-activity-week' => array(
				'table'    => 'readings',
				'feature'  => 'health_logging',
				'health'   => true,
				'computed' => 'activity-week',
				'write'    => false,
				'sealed'   => array(),
				'fields'   => array(),
			),
		);
	}

	/**
	 * Per-reading ranges from the data model (Health reading). Checked on top of the generic field rules.
	 *
	 * @return array<string,array<string,array{0:float,1:float}>>
	 */
	public static function reading_ranges() {
		return array(
			'blood_pressure' => array( 'value_1' => array( 60, 260 ), 'value_2' => array( 30, 160 ), 'value_3' => array( 30, 220 ) ),
			'weight'         => array( 'value_1' => array( 25, 350 ) ),
			'waist'          => array( 'value_1' => array( 40, 250 ) ),
			'activity'       => array( 'value_1' => array( 1, 600 ), 'value_2' => array( 0, 200000 ), 'value_3' => array( 1, 3 ) ),
			'sleep'          => array( 'value_1' => array( 0, 16 ), 'value_3' => array( 1, 5 ) ),
			'water'          => array( 'value_1' => array( 50, 5000 ) ),
			'mood'           => array( 'value_1' => array( 1, 5 ) ),
			'glucose'        => array( 'value_1' => array( 1, 35 ) ),
		);
	}

	public static function register() {
		register_rest_route(
			'lld-members/v1',
			'/me/features',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'features' ),
				'permission_callback' => '__return_true',
			)
		);
		// Calculators (P3-55 onwards): the engine runs for a signed-in member; "save" writes the nutrition target.
		register_rest_route(
			'lld-members/v1',
			'/me/calculate/(?P<tool>[a-z-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'calculate' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/me/(?P<type>[a-z-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_rows' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => '__return_true',
				),
			)
		);
		register_rest_route(
			'lld-members/v1',
			'/me/(?P<type>[a-z-]+)/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'read' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'patch' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Which Stage 3 features this caller may use. Visitors get every switch as off.
	 */
	public static function features() {
		$member_id = LLD_Members_Access::current_member_id();
		$out       = array();
		foreach ( array_keys( LLD_Members_Features::catalog() ) as $feature ) {
			$out[ $feature ] = $member_id > 0 && LLD_Members_Features::enabled( $feature, $member_id );
		}
		return new WP_REST_Response( array( 'features' => $out ), 200 );
	}

	/**
	 * Feature, sign-in and consent checks shared by every route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0:int,1:array<string,mixed>}|WP_REST_Response Member number and type, or the refusal.
	 */
	private static function gate( $request ) {
		$type  = (string) $request['type'];
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return self::refuse( 404, 'Not available.' );
		}
		$def       = $types[ $type ];
		$member_id = LLD_Members_Access::current_member_id();
		// A switched-off feature looks as if it does not exist, for members and visitors alike (NFR3-06).
		if ( ! LLD_Members_Features::enabled( $def['feature'], $member_id ) ) {
			return self::refuse( 404, 'Not available.' );
		}
		if ( $member_id < 1 ) {
			return self::refuse( 401, 'Sign in.' );
		}
		if ( ! empty( $def['health'] ) && ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			return self::refuse( 403, 'Health consent is needed first.' );
		}
		// The safety matrix may refuse a type for this member (P3-03; FR3-69 AS-02): safety before access.
		$refusal = apply_filters( 'lld_members_stage3_api_refuse', null, $type, $member_id );
		if ( $refusal instanceof WP_REST_Response ) {
			return $refusal;
		}
		return array( $member_id, $def );
	}

	/**
	 * Run a calculator for the signed-in member (FR3-47). Behind calculators_new; a switched-off tool looks absent.
	 * With {"save": true} the result also becomes the member's nutrition target (FR3-28 AS-01), which needs health
	 * consent; the answers themselves are never stored here.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function calculate( $request ) {
		$tool      = (string) $request['tool'];
		$member_id = LLD_Members_Access::current_member_id();
		$engines   = self::calculator_engines();
		if ( ! isset( $engines[ $tool ] ) || ! class_exists( $engines[ $tool ] ) || ! LLD_Members_Features::enabled( 'calculators_new', $member_id ) ) {
			return self::refuse( 404, 'Not available.' );
		}
		if ( $member_id < 1 ) {
			return self::refuse( 401, 'Sign in.' );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		if ( 'calorie-target' !== $tool ) {
			return self::calculate_engine( $engines[ $tool ], $params, $member_id );
		}
		// The member's health profile applies as well as the answers on the page (sheet section 2, FR3-69 AS-03/04):
		// an eating-disorder flag stops the calculator; kidney disease removes the protein target; diabetes adds its note.
		if ( class_exists( 'LLD_Members_Safety' ) ) {
			$flags = LLD_Members_Safety::situations( $member_id );
			if ( in_array( 'eating-disorder', $flags, true ) ) {
				$params['eatingDisorder'] = 'yes';
			}
			if ( in_array( 'kidney', $flags, true ) ) {
				$params['kidneyDisease'] = true;
			}
			if ( in_array( 'diabetes', $flags, true ) ) {
				$params['type2Diabetes'] = true;
			}
		}
		$result = LLD_Members_Calorie_Target::compute( $params );
		if ( isset( $result['error'] ) ) {
			return self::refuse( 422, 'Check ' . $result['error'] . '.' );
		}
		$result['wording'] = array();
		foreach ( $result['messages'] as $key ) {
			$result['wording'][ $key ] = 0 === strpos( $key, 'quoted.' ) ? LLD_Members_Calorie_Target::QUOTED[ substr( $key, 7 ) ] : LLD_Members_Calorie_Target::MESSAGES[ $key ];
		}
		// The profile's safe minimum applies to every saved or own target, whatever sex the page answered.
		if ( null !== $result['targetKcal'] && ! $result['refused'] && ! $result['maintenanceOnly'] && $result['targetKcal'] < self::kcal_floor( $member_id ) ) {
			$result['refused']               = true;
			$result['targetKcal']            = null;
			$result['notes'][]               = 'floor';
			$result['wording']['floor_note'] = LLD_Members_Calorie_Target::MESSAGES['floor_note'];
		}
		if ( null !== $result['targetKcal'] && ! $result['refused'] ) {
			$protein           = in_array( 'kidney', $result['notes'], true ) ? null : self::protein_target( $member_id );
			$result['macros']  = LLD_Members_Calorie_Target::macros( $result['targetKcal'], $protein );
		}
		if ( ! empty( $params['save'] ) && null !== $result['targetKcal'] && ! $result['refused'] ) {
			if ( ! LLD_Members_Access::has_health_consent( $member_id ) ) {
				return self::refuse( 403, 'Health consent is needed first.' );
			}
			$target = LLD_Members_Food_Log::target_from_result( $result['targetKcal'], $protein, gmdate( 'Y-m-d' ) );
			$id     = isset( $target['error'] ) ? 0 : LLD_Members_Stage3_Data::insert(
				'nutrition_targets',
				$member_id,
				array(
					'kcal'       => $target['kcal'],
					'protein_g'  => $target['protein_g'],
					'carb_g'     => $target['carb_g'],
					'fat_g'      => $target['fat_g'],
					'fibre_g'    => $target['fibre_g'],
					'set_by'     => 'calculator',
					'valid_from' => $target['valid_from'],
				)
			);
			$result['saved'] = $id > 0 ? $id : null;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * The Stage 3 calculator engines by tool slug (the approval record's tool_slug). A tool whose class is not loaded
	 * looks absent.
	 */
	private static function calculator_engines() {
		return array(
			'calorie-target' => 'LLD_Members_Calorie_Target',
			'heart-risk'     => 'LLD_Members_Heart_Risk',
			'bp-check'       => 'LLD_Members_BP_Check',
			'metabolic'      => 'LLD_Members_Metabolic',
			'fitness-age'    => 'LLD_Members_Fitness_Age',
			'hr-zones'       => 'LLD_Members_HR_Zones',
			'sleep-stress'   => 'LLD_Members_Sleep_Stress',
		);
	}

	/**
	 * Run a pure engine (P3-57 to P3-5A): the member's health profile is passed to the engine's apply_profile() when it
	 * has one (sheet section 2 of each tool), then compute(), then the signed wording for the keys it returned. Nothing
	 * is stored here; saving results over time is P3-5B.
	 *
	 * @param string $class     Engine class.
	 * @param array  $params    The answers on the page.
	 * @param int    $member_id Member number.
	 */
	private static function calculate_engine( $class, array $params, $member_id ) {
		// The Blood Pressure Reading Check's 7-day average reads the member's own logged readings (P3-58 sheet section 5):
		// whatever readings or date the page sent are ignored.
		if ( 'LLD_Members_BP_Check' === $class && LLD_Members_BP_Check::home_mode( isset( $params['mode'] ) ? $params['mode'] : null ) ) {
			$params = array_merge( $params, self::bp_home_readings( $member_id ) );
		}
		if ( method_exists( $class, 'apply_profile' ) && class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			$params = $class::apply_profile( $params, LLD_Members_Stage3_Safety::situations( $member_id ) );
		}
		$result = $class::compute( $params );
		if ( isset( $result['error'] ) ) {
			return self::refuse( 422, 'Check ' . $result['error'] . '.' );
		}
		$result['wording'] = $class::wording( isset( $result['messages'] ) ? $result['messages'] : array(), $result );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * The member's blood-pressure readings for the 7-day average (P3-58 sheet section 5): the Stage 3 readings table,
	 * type blood_pressure, not deleted, taken in the 7 calendar days ending today in the member's time zone (the
	 * programme's zone in force; the site's zone when that is unavailable), sealed values opened in memory only, at
	 * most LLD_Members_BP_Check::MAX_READINGS (the newest). Without health consent there are no readings to read.
	 *
	 * @param int $member_id Member number.
	 * @return array{today:string,readings:array<int,array{date:string,systolic:float,diastolic:float}>}
	 */
	private static function bp_home_readings( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$name      = class_exists( 'LLD_Members_Programme' ) ? (string) LLD_Members_Programme::timezone( $member_id ) : '';
		try {
			$zone = '' !== $name ? new DateTimeZone( $name ) : wp_timezone();
		} catch ( Exception $e ) {
			$zone = wp_timezone();
		}
		$utc      = new DateTimeZone( 'UTC' );
		$now      = class_exists( 'LLD_Members_Clock' ) ? LLD_Members_Clock::now( $member_id ) : new DateTimeImmutable( 'now', $utc );
		$today    = $now->setTimezone( $zone )->format( 'Y-m-d' );
		$midnight = new DateTimeImmutable( $today . ' 00:00:00', $zone );
		$from     = $midnight->modify( '-' . ( LLD_Members_BP_Check::WINDOW_DAYS - 1 ) . ' days' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$to       = $midnight->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$readings = array();
		if ( $member_id > 0 && LLD_Members_Access::has_health_consent( $member_id ) ) {
			$table = LLD_Members_Stage3_Schema::table( 'readings' );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT taken_at, value_1_ct, value_2_ct FROM {$table} WHERE member_id = %d AND reading_type = %s AND deleted_at IS NULL AND taken_at >= %s AND taken_at < %s ORDER BY taken_at DESC, id DESC LIMIT %d", $member_id, 'blood_pressure', $from, $to, LLD_Members_BP_Check::MAX_READINGS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$at  = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row['taken_at'], $utc );
				$sys = LLD_Members_Stage3_Data::open( $member_id, $row['value_1_ct'] );
				$dia = LLD_Members_Stage3_Data::open( $member_id, $row['value_2_ct'] );
				if ( ! $at || ! is_numeric( $sys ) || ! is_numeric( $dia ) ) {
					continue; // a value that does not open for this member is not theirs to count
				}
				$readings[] = array(
					'date'      => $at->setTimezone( $zone )->format( 'Y-m-d' ),
					'systolic'  => (float) $sys,
					'diastolic' => (float) $dia,
				);
			}
		}
		return array(
			'today'    => $today,
			'readings' => $readings,
		);
	}

	/**
	 * The member's Daily Protein Target (Stage 1 tool), read from the saved result's headline; null when none is
	 * saved. Never recomputed here (D3-06).
	 *
	 * @param int $member_id Member number.
	 */
	private static function protein_target( $member_id ) {
		global $wpdb;
		$table    = LLD_Members_Schema::table( 'calculator_result' );
		$headline = $wpdb->get_var( $wpdb->prepare( "SELECT headline FROM {$table} WHERE member_id = %d AND calculator = %s ORDER BY id DESC LIMIT 1", $member_id, 'protein' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		if ( ! is_string( $headline ) || ! preg_match( '/(\d+(?:\.\d+)?)/', $headline, $m ) ) {
			return null;
		}
		return (float) $m[1];
	}

	/**
	 * The member's safe minimum (FR3-28 AS-02): 1,500 kcal when the health profile says male, else 1,200.
	 *
	 * @param int $member_id Member number.
	 */
	private static function kcal_floor( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'health_profile' );
		$sex   = $wpdb->get_var( $wpdb->prepare( "SELECT sex FROM {$table} WHERE member_id = %d", $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return LLD_Members_Calorie_Target::floor_for( is_string( $sex ) ? $sex : '' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function list_rows( $request ) {
		$gate = self::gate( $request );
		if ( $gate instanceof WP_REST_Response ) {
			return $gate;
		}
		list( $member_id, $def ) = $gate;
		$computed = ! empty( $def['computed'] );
		if ( $computed ) {
			$query = array(
				'date'       => (string) $request->get_param( 'date' ),
				'q'          => (string) $request->get_param( 'q' ),
				'week_start' => (string) $request->get_param( 'week_start' ),
				'type'       => self::query_type( $request ),
				'from'       => (string) $request->get_param( 'from' ),
				'to'         => (string) $request->get_param( 'to' ),
				'unit'       => (string) $request->get_param( 'unit' ),
			);
			if ( in_array( $def['computed'], array( 'trend', 'activity-week' ), true ) ) {
				$rows = LLD_Members_Health_Log::view( $def['computed'], $member_id, $query );
			} elseif ( in_array( $def['computed'], array( 'week', 'suggest' ), true ) ) {
				$rows = LLD_Members_Recipes::view( $def['computed'], $member_id, $query );
			} else {
				$rows = LLD_Members_Food_Log::view( $def['computed'], $member_id, $query );
			}
			if ( isset( $rows['error'] ) ) {
				return self::refuse( isset( $rows['status'] ) ? (int) $rows['status'] : 400, $rows['error'] );
			}
		} elseif ( ! empty( $def['stage2'] ) ) {
			$since = self::clean_datetime( (string) $request->get_param( 'since' ) );
			return new WP_REST_Response( array( 'rows' => self::stage2_rows( $def['table'], $member_id, $since ) ), 200 );
		} else {
			$since = self::clean_datetime( (string) $request->get_param( 'since' ) );
			$limit = (int) $request->get_param( 'limit' );
			$rows  = LLD_Members_Stage3_Data::rows( $def['table'], $member_id, $def['sealed'], null === $since ? '' : $since, $limit > 0 ? $limit : 500 );
			if ( 'food_log' === $def['table'] ) {
				$rows = LLD_Members_Food_Log::limit_history( $rows, LLD_Members_Food_Log::premium( $member_id ), gmdate( 'Y-m-d' ) );
			}
			if ( 'readings' === $def['table'] ) {
				$premium = LLD_Members_Food_Log::premium( $member_id );
				$rows    = LLD_Members_Health_Log::limit_history( $rows, $premium, substr( lld_members_now_mysql( $member_id ), 0, 10 ) ); // the member's own today, as the readings were saved by it
				$rows    = LLD_Members_Health_Log::filter(
					$rows,
					self::query_type( $request ),
					(string) $request->get_param( 'from' ),
					(string) $request->get_param( 'to' )
				);
			}
		}
		$rows = apply_filters( 'lld_members_stage3_api_rows', $rows, (string) $request['type'], $member_id ); // the safety matrix strips what the member must not see (FR3-69)
		if ( $computed ) {
			return new WP_REST_Response( $rows, 200 );
		}
		return new WP_REST_Response(
			array(
				'rows'   => array_map( array( __CLASS__, 'present' ), $rows ),
				'synced' => gmdate( 'Y-m-d H:i:s' ),
			),
			200
		);
	}

	/**
	 * The reading type asked for in the query string (?type=weight). Never get_param( 'type' ): with no query value it
	 * falls back to the route's own {type} ("readings"), which matches no reading and empties every list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function query_type( $request ) {
		$query = $request->get_query_params();
		return isset( $query['type'] ) && is_scalar( $query['type'] ) ? (string) $query['type'] : '';
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function read( $request ) {
		$gate = self::gate( $request );
		if ( $gate instanceof WP_REST_Response ) {
			return $gate;
		}
		list( $member_id, $def ) = $gate;
		if ( ! empty( $def['stage2'] ) ) {
			return self::refuse( 404, 'Not available.' );
		}
		$row = LLD_Members_Stage3_Data::row( $def['table'], $member_id, (int) $request['id'], $def['sealed'] );
		if ( ! $row ) {
			return self::refuse( 404, 'Not found.' );
		}
		$row = apply_filters( 'lld_members_stage3_api_rows', $row, (string) $request['type'], $member_id );
		return new WP_REST_Response( self::present( $row ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function create( $request ) {
		$gate = self::gate( $request );
		if ( $gate instanceof WP_REST_Response ) {
			return $gate;
		}
		list( $member_id, $def ) = $gate;
		if ( isset( $def['write'] ) && false === $def['write'] ) {
			return self::refuse( 405, 'This type is read-only here.' );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		if ( 'readings' === $def['table'] ) {
			$params = LLD_Members_Health_Log::accept_input( $params );
		}
		$clean  = self::validate( $def, $params, true );
		if ( $clean instanceof WP_REST_Response ) {
			return $clean;
		}
		if ( 'shares' === $def['table'] ) {
			$clean['token'] = bin2hex( random_bytes( 16 ) );
		}
		if ( ! empty( $def['computed'] ) && 'copy' === $def['computed'] ) {
			$result = LLD_Members_Food_Log::copy_into( $member_id, $clean );
			if ( isset( $result['error'] ) ) {
				return self::refuse( isset( $result['status'] ) ? (int) $result['status'] : 422, $result['error'] );
			}
			return new WP_REST_Response( $result, 201 );
		}
		if ( 'custom_foods' === $def['table'] ) {
			$denied = LLD_Members_Food_Log::gate_custom( LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
		}
		if ( 'recipes' === $def['table'] ) {
			$denied = LLD_Members_Recipes::gate_premium( LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
			$filled = LLD_Members_Recipes::for_member( $member_id, $clean );
			if ( isset( $filled['error'] ) ) {
				return self::refuse( 422, $filled['error'] );
			}
			$clean = $filled;
		}
		if ( 'food_preferences' === $def['table'] ) {
			$raw = array(
				'allergies' => isset( $params['allergies'] ) ? $params['allergies'] : array(),
				'dislikes'  => isset( $params['dislikes'] ) ? $params['dislikes'] : array(),
			);
			if ( is_string( $raw['allergies'] ) ) {
				$decoded = json_decode( $raw['allergies'], true );
				$raw['allergies'] = is_array( $decoded ) ? $decoded : array();
			}
			if ( is_string( $raw['dislikes'] ) ) {
				$decoded = json_decode( $raw['dislikes'], true );
				$raw['dislikes'] = is_array( $decoded ) ? $decoded : array();
			}
			$clean = LLD_Members_Recipes::prefs_for( $raw );
			$existing = LLD_Members_Stage3_Data::rows( 'food_preferences', $member_id, array(), '', 1 );
			if ( $existing ) {
				LLD_Members_Stage3_Data::update( 'food_preferences', $member_id, (int) $existing[0]['id'], $clean, array() );
				$row = LLD_Members_Stage3_Data::row( 'food_preferences', $member_id, (int) $existing[0]['id'], array() );
				return new WP_REST_Response( self::present( $row ), 200 );
			}
		}
		// A target the member types is Premium, and one below the safe minimum is never saved (FR3-28).
		if ( 'nutrition_targets' === $def['table'] ) {
			$denied = LLD_Members_Food_Log::gate_member_target( isset( $clean['set_by'] ) ? (string) $clean['set_by'] : 'plan', LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
			if ( isset( $clean['kcal'] ) && (float) $clean['kcal'] < self::kcal_floor( $member_id ) ) {
				return self::refuse( 422, LLD_Members_Calorie_Target::MESSAGES['floor_note'] );
			}
		}
		if ( 'food_log' === $def['table'] ) {
			$filled = LLD_Members_Food_Log::for_member( $member_id, $clean );
			if ( isset( $filled['error'] ) ) {
				return self::refuse( 422, $filled['error'] );
			}
			$clean = $filled;
		}
		$warning = null;
		if ( 'readings' === $def['table'] ) {
			$extras = array();
			foreach ( array( 'bedtime', 'dizzy', 'self_harm', 'symptoms' ) as $extra ) {
				if ( array_key_exists( $extra, $params ) ) {
					$extras[ $extra ] = $params[ $extra ];
				}
			}
			$filled = LLD_Members_Health_Log::for_member( $member_id, array_merge( $clean, $extras ) );
			if ( isset( $filled['error'] ) ) {
				return self::refuse( isset( $filled['status'] ) ? (int) $filled['status'] : 400, $filled['error'] );
			}
			$clean   = $filled['row'];
			$warning = $filled['warning'];
		}
		$id = LLD_Members_Stage3_Data::insert( $def['table'], $member_id, $clean, $def['sealed'] );
		if ( $id < 1 ) {
			return self::refuse( 409, 'Could not save.' );
		}
		$row = LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] );
		if ( 'readings' === $def['table'] ) {
			do_action( 'lld_members_reading_saved', $member_id, is_array( $row ) ? $row : $clean );
			$out = self::present( $row );
			if ( is_array( $warning ) ) {
				$out['warning'] = $warning;
			}
			return new WP_REST_Response( $out, 201 );
		}
		return new WP_REST_Response( self::present( $row ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function patch( $request ) {
		$gate = self::gate( $request );
		if ( $gate instanceof WP_REST_Response ) {
			return $gate;
		}
		list( $member_id, $def ) = $gate;
		if ( ! empty( $def['stage2'] ) ) {
			return self::refuse( 405, 'This type is read-only here.' );
		}
		$id  = (int) $request['id'];
		$row = LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] );
		if ( ! $row ) {
			return self::refuse( 404, 'Not found.' );
		}
		if ( 'custom_foods' === $def['table'] ) {
			$denied = LLD_Members_Food_Log::gate_custom( LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
		}
		if ( 'recipes' === $def['table'] ) {
			$denied = LLD_Members_Recipes::gate_premium( LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		if ( ! empty( $def['patch'] ) ) {
			$params = array_intersect_key( $params, array_flip( $def['patch'] ) );
		}
		if ( 'readings' === $def['table'] ) {
			$filled_sleep = LLD_Members_Health_Log::accept_input( array_merge( array( 'reading_type' => $row['reading_type'] ), $params ) );
			if ( isset( $filled_sleep['value_1'] ) && ! array_key_exists( 'value_1', $params ) && isset( $params['bedtime'] ) ) {
				$params['value_1'] = $filled_sleep['value_1'];
			}
		}
		$clean = self::validate( $def, $params, false );
		if ( $clean instanceof WP_REST_Response ) {
			return $clean;
		}
		if ( ! $clean ) {
			return self::refuse( 400, 'Nothing to change.' );
		}
		if ( 'recipes' === $def['table'] ) {
			$merged = array_merge( $row, $clean );
			$filled = LLD_Members_Recipes::for_member( $member_id, $merged );
			if ( isset( $filled['error'] ) ) {
				return self::refuse( 422, $filled['error'] );
			}
			$clean = $filled;
		}
		if ( 'food_preferences' === $def['table'] ) {
			$raw = array(
				'allergies' => array_key_exists( 'allergies', $params ) ? $params['allergies'] : ( isset( $row['allergies'] ) ? $row['allergies'] : array() ),
				'dislikes'  => array_key_exists( 'dislikes', $params ) ? $params['dislikes'] : ( isset( $row['dislikes'] ) ? $row['dislikes'] : array() ),
			);
			if ( is_string( $raw['allergies'] ) ) {
				$decoded = json_decode( $raw['allergies'], true );
				$raw['allergies'] = is_array( $decoded ) ? $decoded : array();
			}
			if ( is_string( $raw['dislikes'] ) ) {
				$decoded = json_decode( $raw['dislikes'], true );
				$raw['dislikes'] = is_array( $decoded ) ? $decoded : array();
			}
			$clean = LLD_Members_Recipes::prefs_for( $raw );
		}
		if ( 'nutrition_targets' === $def['table'] && isset( $clean['kcal'] ) && (float) $clean['kcal'] < self::kcal_floor( $member_id ) ) {
			return self::refuse( 422, LLD_Members_Calorie_Target::MESSAGES['floor_note'] );
		}
		if ( 'food_log' === $def['table'] && isset( $clean['grams'] ) && round( (float) $clean['grams'], 1 ) !== round( (float) $row['grams'], 1 ) ) {
			$scaled = LLD_Members_Food_Log::scale( $row, $clean['grams'] );
			if ( null === $scaled ) {
				return self::refuse( 400, 'Check the value of grams.' );
			}
			foreach ( array( 'grams', 'kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g' ) as $key ) {
				$clean[ $key ] = $scaled[ $key ];
			}
		}
		$warning = null;
		if ( 'readings' === $def['table'] ) {
			$extras = array();
			foreach ( array( 'bedtime', 'dizzy', 'self_harm', 'symptoms' ) as $extra ) {
				if ( array_key_exists( $extra, $params ) ) {
					$extras[ $extra ] = $params[ $extra ];
				}
			}
			$filled = LLD_Members_Health_Log::for_member( $member_id, array_merge( $clean, $extras ), $row );
			if ( isset( $filled['error'] ) ) {
				return self::refuse( isset( $filled['status'] ) ? (int) $filled['status'] : 400, $filled['error'] );
			}
			$clean   = $filled['row'];
			$warning = $filled['warning'];
		}
		LLD_Members_Stage3_Data::update( $def['table'], $member_id, $id, $clean, $def['sealed'] );
		$updated = LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] );
		if ( 'readings' === $def['table'] ) {
			do_action( 'lld_members_reading_saved', $member_id, is_array( $updated ) ? $updated : $clean );
			$out = self::present( $updated );
			if ( is_array( $warning ) ) {
				$out['warning'] = $warning;
			}
			return new WP_REST_Response( $out, 200 );
		}
		return new WP_REST_Response( self::present( $updated ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function delete( $request ) {
		$gate = self::gate( $request );
		if ( $gate instanceof WP_REST_Response ) {
			return $gate;
		}
		list( $member_id, $def ) = $gate;
		if ( ! empty( $def['stage2'] ) || ( isset( $def['write'] ) && false === $def['write'] ) ) {
			return self::refuse( 405, 'This type is read-only here.' );
		}
		$id  = (int) $request['id'];
		$row = LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] );
		if ( ! $row ) {
			return self::refuse( 404, 'Not found.' );
		}
		if ( 'custom_foods' === $def['table'] ) {
			$denied = LLD_Members_Food_Log::gate_custom( LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
		}
		if ( 'recipes' === $def['table'] ) {
			$denied = LLD_Members_Recipes::gate_premium( LLD_Members_Food_Log::premium( $member_id ) );
			if ( null !== $denied ) {
				return self::refuse( 403, $denied );
			}
		}
		if ( array_key_exists( 'deleted_at', $row ) ) {
			LLD_Members_Stage3_Data::soft_delete( $def['table'], $member_id, $id );
		} else {
			global $wpdb;
			$wpdb->delete( LLD_Members_Stage3_Schema::table( $def['table'] ), array( 'id' => $id, 'member_id' => $member_id ), array( '%d', '%d' ) );
		}
		return new WP_REST_Response( array( 'deleted' => $id ), 200 );
	}

	/**
	 * Check every field against its rule. Returns the clean row, or a 400 response.
	 *
	 * @param array<string,mixed> $def      Type definition.
	 * @param array<string,mixed> $params   Request body.
	 * @param bool                $creating Whether required fields must be present.
	 * @return array<string,mixed>|WP_REST_Response
	 */
	private static function validate( array $def, array $params, $creating ) {
		$clean  = array();
		$fields = isset( $def['fields'] ) ? $def['fields'] : array();
		foreach ( $fields as $name => $rule ) {
			if ( ! array_key_exists( $name, $params ) ) {
				continue;
			}
			$value = $params[ $name ];
			if ( null === $value || '' === $value ) {
				$clean[ $name ] = null;
				continue;
			}
			$checked = self::check( $rule, $value );
			if ( null === $checked ) {
				return self::refuse( 400, 'Check the value of ' . $name . '.' );
			}
			$clean[ $name ] = $checked;
		}
		if ( $creating ) {
			foreach ( isset( $def['required'] ) ? $def['required'] : array() as $name ) {
				if ( ! isset( $clean[ $name ] ) ) {
					return self::refuse( 400, 'Add ' . $name . '.' );
				}
			}
		}
		if ( 'readings' === $def['table'] ) {
			// Ranges apply to stored metric values; convert display units first. warning_level() still decides impossible.
			$type  = isset( $clean['reading_type'] ) ? $clean['reading_type'] : ( isset( $params['reading_type'] ) ? (string) $params['reading_type'] : '' );
			$probe = LLD_Members_Health_Log::normalise( array_merge( array( 'reading_type' => $type ), $clean, array_intersect_key( $params, array_flip( array( 'bedtime' ) ) ) ) );
			$ranges = self::reading_ranges();
			if ( '' !== $type && isset( $ranges[ $type ] ) ) {
				foreach ( $ranges[ $type ] as $field => $range ) {
					if ( isset( $probe[ $field ] ) && is_numeric( $probe[ $field ] ) && ( $probe[ $field ] < $range[0] || $probe[ $field ] > $range[1] ) ) {
						return self::refuse( 400, 'Check the numbers.' );
					}
				}
				if ( 'blood_pressure' === $type && isset( $probe['value_1'], $probe['value_2'] ) && $probe['value_2'] >= $probe['value_1'] ) {
					return self::refuse( 400, 'The bottom number must be lower than the top number.' );
				}
			}
			if ( isset( $clean['taken_at'] ) && strtotime( $clean['taken_at'] . ' UTC' ) > time() + 300 ) {
				return self::refuse( 400, 'The time cannot be in the future.' );
			}
		}
		return $clean;
	}

	/**
	 * One field against one rule. Null means refused.
	 *
	 * @param string $rule  Rule from types().
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function check( $rule, $value ) {
		if ( 'int' === $rule ) {
			return is_numeric( $value ) && (int) $value >= 0 ? (int) $value : null;
		}
		if ( 'bool' === $rule ) {
			return in_array( $value, array( true, false, 0, 1, '0', '1' ), true ) ? ( $value ? 1 : 0 ) : null;
		}
		if ( 'date' === $rule ) {
			return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ? $value : null;
		}
		if ( 'datetime' === $rule ) {
			return self::clean_datetime( $value );
		}
		if ( 'time' === $rule ) {
			return is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value ) ? substr( $value, 0, 5 ) . ':00' : null;
		}
		if ( 'json' === $rule ) {
			if ( is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				return null === $decoded ? null : wp_json_encode( $decoded );
			}
			return is_array( $value ) ? wp_json_encode( $value ) : null;
		}
		if ( preg_match( '/^number\[([\d.]+),([\d.]+)\]$/', $rule, $m ) ) {
			if ( ! is_numeric( $value ) ) {
				return null;
			}
			$n = (float) $value;
			return $n >= (float) $m[1] && $n <= (float) $m[2] ? $n : null;
		}
		if ( preg_match( '/^text\[(\d+)\]$/', $rule, $m ) ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}
			$text = sanitize_text_field( (string) $value );
			return strlen( $text ) <= (int) $m[1] ? $text : null;
		}
		if ( 0 === strpos( $rule, 'choice:' ) ) {
			$choices = explode( '|', substr( $rule, 7 ) );
			return is_string( $value ) && in_array( $value, $choices, true ) ? $value : null;
		}
		return null;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string|null MySQL UTC datetime.
	 */
	private static function clean_datetime( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value . ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ? '' : ' UTC' ) );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Saved calculator results (Stage 2 table), read-only here.
	 *
	 * @param string      $suffix    Stage 2 table suffix.
	 * @param int         $member_id Member number.
	 * @param string|null $since     Not used yet: the Stage 2 table has no updated_at column.
	 * @return array<int,array<string,mixed>>
	 */
	private static function stage2_rows( $suffix, $member_id, $since ) {
		global $wpdb;
		unset( $since );
		$table = LLD_Members_Schema::table( $suffix );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, calculator, saved_at FROM {$table} WHERE member_id = %d ORDER BY id DESC LIMIT 500", $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Numbers as numbers in the JSON; nothing else changes.
	 *
	 * @param array<string,mixed>|null $row Opened row.
	 * @return array<string,mixed>
	 */
	public static function present( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		foreach ( $row as $k => $v ) {
			if ( is_string( $v ) && is_numeric( $v ) && ! preg_match( '/^0\d/', $v ) && 'token' !== $k && 'barcode' !== $k && 'external_ref' !== $k ) {
				$row[ $k ] = $v + 0;
			}
		}
		return $row;
	}

	/**
	 * @param int    $status  HTTP status.
	 * @param string $message Plain wording; never a health value.
	 */
	private static function refuse( $status, $message ) {
		return new WP_REST_Response( array( 'message' => $message ), $status );
	}
}
