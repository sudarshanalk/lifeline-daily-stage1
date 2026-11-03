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
		if ( 'calorie-target' !== $tool || ! LLD_Members_Features::enabled( 'calculators_new', $member_id ) ) {
			return self::refuse( 404, 'Not available.' );
		}
		if ( $member_id < 1 ) {
			return self::refuse( 401, 'Sign in.' );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
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
			$m  = $result['macros'];
			$id = LLD_Members_Stage3_Data::insert(
				'nutrition_targets',
				$member_id,
				array(
					'kcal'       => $result['targetKcal'],
					'protein_g'  => $m['proteinG'],
					'carb_g'     => $m['carbG'],
					'fat_g'      => $m['fatG'],
					'fibre_g'    => $m['fibreG'],
					'set_by'     => 'calculator',
					'valid_from' => gmdate( 'Y-m-d' ),
				)
			);
			$result['saved'] = $id > 0 ? $id : null;
		}
		return new WP_REST_Response( $result, 200 );
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
		$since = self::clean_datetime( (string) $request->get_param( 'since' ) );
		$limit = (int) $request->get_param( 'limit' );
		if ( ! empty( $def['stage2'] ) ) {
			return new WP_REST_Response( array( 'rows' => self::stage2_rows( $def['table'], $member_id, $since ) ), 200 );
		}
		$rows = LLD_Members_Stage3_Data::rows( $def['table'], $member_id, $def['sealed'], null === $since ? '' : $since, $limit > 0 ? $limit : 500 );
		return new WP_REST_Response(
			array(
				'rows'   => array_map( array( __CLASS__, 'present' ), $rows ),
				'synced' => gmdate( 'Y-m-d H:i:s' ),
			),
			200
		);
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
		$clean  = self::validate( $def, $params, true );
		if ( $clean instanceof WP_REST_Response ) {
			return $clean;
		}
		if ( 'shares' === $def['table'] ) {
			$clean['token'] = bin2hex( random_bytes( 16 ) );
		}
		// A calorie target below the member's safe minimum is never saved (FR3-28 AS-02, D3-02).
		if ( 'nutrition_targets' === $def['table'] && isset( $clean['kcal'] ) && (float) $clean['kcal'] < self::kcal_floor( $member_id ) ) {
			return self::refuse( 422, LLD_Members_Calorie_Target::MESSAGES['floor_note'] );
		}
		$id = LLD_Members_Stage3_Data::insert( $def['table'], $member_id, $clean, $def['sealed'] );
		if ( $id < 1 ) {
			return self::refuse( 409, 'Could not save.' );
		}
		$row = LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] );
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
		$id = (int) $request['id'];
		if ( ! LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] ) ) {
			return self::refuse( 404, 'Not found.' );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		if ( ! empty( $def['patch'] ) ) {
			$params = array_intersect_key( $params, array_flip( $def['patch'] ) );
		}
		$clean = self::validate( $def, $params, false );
		if ( $clean instanceof WP_REST_Response ) {
			return $clean;
		}
		if ( ! $clean ) {
			return self::refuse( 400, 'Nothing to change.' );
		}
		LLD_Members_Stage3_Data::update( $def['table'], $member_id, $id, $clean, $def['sealed'] );
		return new WP_REST_Response( self::present( LLD_Members_Stage3_Data::row( $def['table'], $member_id, $id, $def['sealed'] ) ), 200 );
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
			$type   = isset( $clean['reading_type'] ) ? $clean['reading_type'] : '';
			$ranges = self::reading_ranges();
			if ( isset( $ranges[ $type ] ) ) {
				foreach ( $ranges[ $type ] as $field => $range ) {
					if ( isset( $clean[ $field ] ) && ( $clean[ $field ] < $range[0] || $clean[ $field ] > $range[1] ) ) {
						return self::refuse( 400, 'Check the value of ' . $field . '.' );
					}
				}
				if ( 'blood_pressure' === $type && isset( $clean['value_1'], $clean['value_2'] ) && $clean['value_2'] >= $clean['value_1'] ) {
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
