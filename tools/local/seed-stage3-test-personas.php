<?php
/**
 * Stage 3 Full Spectrum Test Personas Seeder
 *
 * Populates 7 diverse personas covering all 4 programs, Free & Premium tiers,
 * 11 health calculators, daily logging, food tracking, and clinical safety triggers.
 *
 * Usage:
 *   powershell -File tools\local\stage2-local.ps1 wp eval-file tools\local\seed-stage3-test-personas.php [seed|clean|status]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LLD_Stage3_Persona_Seeder {

	const PASSWORD = 'Password123!Safe';

	const PERSONAS = array(
		'weight_loss' => array(
			'key'         => 'weight_loss',
			'email'       => 'persona-weight-loss@test.lifelinedaily.invalid',
			'name'        => 'Sarah',
			'last_name'   => 'Jenkins',
			'tier'        => 'premium',
			'sex'         => 'female',
			'age'         => 38,
			'height_cm'   => 168.0,
			'start_weight'=> 84.0,
			'curr_weight' => 80.8,
			'target_weight'=> 74.0,
			'start_waist' => 92.0,
			'curr_waist'  => 88.0,
			'program'     => 'healthy-weight',
			'program_title'=> 'Healthy Weight',
			'goal_type'   => 'weight_loss',
			'days_active' => 28,
		),
		'blood_sugar' => array(
			'key'         => 'blood_sugar',
			'email'       => 'persona-blood-sugar@test.lifelinedaily.invalid',
			'name'        => 'Marcus',
			'last_name'   => 'Thorne',
			'tier'        => 'free',
			'sex'         => 'male',
			'age'         => 45,
			'height_cm'   => 178.0,
			'start_weight'=> 88.0,
			'curr_weight' => 87.5,
			'target_weight'=> 82.0,
			'start_waist' => 98.0,
			'curr_waist'  => 97.0,
			'program'     => 'blood-sugar',
			'program_title'=> 'Blood Sugar Balance',
			'goal_type'   => 'blood_sugar',
			'days_active' => 20,
		),
		'sleep_stress' => array(
			'key'         => 'sleep_stress',
			'email'       => 'persona-sleep-stress@test.lifelinedaily.invalid',
			'name'        => 'Elena',
			'last_name'   => 'Rostova',
			'tier'        => 'premium',
			'sex'         => 'female',
			'age'         => 34,
			'height_cm'   => 165.0,
			'start_weight'=> 62.0,
			'curr_weight' => 61.5,
			'target_weight'=> 60.0,
			'start_waist' => 74.0,
			'curr_waist'  => 73.0,
			'program'     => 'sleep-stress',
			'program_title'=> 'Sleep and Stress',
			'goal_type'   => 'sleep_stress',
			'days_active' => 21,
		),
		'fitness_strength' => array(
			'key'         => 'fitness_strength',
			'email'       => 'persona-fitness-strength@test.lifelinedaily.invalid',
			'name'        => 'David',
			'last_name'   => 'Miller',
			'tier'        => 'premium',
			'sex'         => 'male',
			'age'         => 52,
			'height_cm'   => 182.0,
			'start_weight'=> 79.0,
			'curr_weight' => 78.5,
			'target_weight'=> 78.0,
			'start_waist' => 85.0,
			'curr_waist'  => 84.0,
			'program'     => 'fitness',
			'program_title'=> 'Fitness and Strength',
			'goal_type'   => 'fitness_strength',
			'days_active' => 21,
		),
		'cardio_metabolic' => array(
			'key'         => 'cardio_metabolic',
			'email'       => 'persona-cardio-metabolic@test.lifelinedaily.invalid',
			'name'        => 'Amara',
			'last_name'   => 'Okafor',
			'tier'        => 'premium',
			'sex'         => 'female',
			'age'         => 49,
			'height_cm'   => 163.0,
			'start_weight'=> 75.0,
			'curr_weight' => 74.5,
			'target_weight'=> 70.0,
			'start_waist' => 89.0,
			'curr_waist'  => 88.5,
			'program'     => 'healthy-weight',
			'program_title'=> 'Healthy Weight',
			'goal_type'   => 'blood_pressure',
			'days_active' => 21,
		),
		'clinical_safety' => array(
			'key'         => 'clinical_safety',
			'email'       => 'persona-clinical-safety@test.lifelinedaily.invalid',
			'name'        => 'Liam',
			'last_name'   => 'Gallagher',
			'tier'        => 'free',
			'sex'         => 'male',
			'age'         => 60,
			'height_cm'   => 175.0,
			'start_weight'=> 94.0,
			'curr_weight' => 94.0,
			'target_weight'=> 85.0,
			'start_waist' => 106.0,
			'curr_waist'  => 106.0,
			'program'     => 'blood-sugar',
			'program_title'=> 'Blood Sugar Balance',
			'goal_type'   => 'blood_pressure',
			'days_active' => 5,
		),
		'fresh_join' => array(
			'key'         => 'fresh_join',
			'email'       => 'persona-fresh-join@test.lifelinedaily.invalid',
			'name'        => 'Priya',
			'last_name'   => 'Patel',
			'tier'        => 'free',
			'sex'         => 'female',
			'age'         => 29,
			'height_cm'   => 160.0,
			'start_weight'=> 65.0,
			'curr_weight' => 65.0,
			'target_weight'=> 60.0,
			'start_waist' => 78.0,
			'curr_waist'  => 78.0,
			'program'     => '',
			'program_title'=> '',
			'goal_type'   => '',
			'days_active' => 0,
		),
	);

	public static function run( $action = 'seed' ) {
		echo "=== Lifeline Daily Stage 3 Test Personas Seeder ===\n";
		echo "Action: {$action}\n\n";

		if ( 'clean' === $action ) {
			self::clean_all();
			echo "Clean complete. All test personas removed.\n";
			return;
		}

		if ( 'status' === $action ) {
			self::show_status();
			return;
		}

		self::setup_features_and_accounts();
		self::seed_foods();
		self::ensure_tracks();

		foreach ( self::PERSONAS as $p ) {
			echo "Seeding Persona: {$p['name']} {$p['last_name']} ({$p['email']})...\n";
			self::seed_persona( $p );
		}

		echo "\nRunning Decision Engine insights for all members...\n";
		self::run_insights();

		echo "\n======================================================\n";
		echo "=== Seeding Complete! Persona Access Directory:     ===\n";
		echo "======================================================\n";
		self::show_status();
	}

	private static function setup_features_and_accounts() {
		// Register all test emails into test_accounts option
		$emails = array_map( function( $p ) { return strtolower( $p['email'] ); }, self::PERSONAS );
		$existing = LLD_Members_Features::test_account_emails();
		$merged = array_values( array_unique( array_merge( $existing, $emails ) ) );
		update_option( LLD_Members_Features::TEST_ACCOUNTS, $merged, true );

		// Set essential feature switches to 'everyone' so all calculators, food tracker, today, progress are live
		$features = array(
			'api', 'account_hub', 'goals', 'today', 'progress', 'insights',
			'food_tracker', 'health_logging', 'calculators_new',
			'calc_calorie_target', 'calc_goal_timeline', 'calc_heart_risk',
			'calc_bp_check', 'calc_metabolic', 'calc_fitness_age',
			'calc_hr_zones', 'calc_sleep_stress', 'barcode', 'access_tiers'
		);
		foreach ( $features as $feat ) {
			LLD_Members_Features::set( $feat, 'everyone', 'test-seeder', 'Full spectrum test data run' );
		}
		echo "Feature switches configured & test accounts registered.\n";
	}

	private static function seed_foods() {
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'foods' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count >= 10 ) {
			echo "Staple food database already populated ({$count} foods).\n";
			return;
		}

		$staples = array(
			array( 'name' => 'Rolled Oats', 'brand' => 'Whole Grain', 'kcal' => 389.0, 'p' => 16.9, 'c' => 66.3, 'f' => 6.9, 'fib' => 10.6 ),
			array( 'name' => 'Greek Yogurt (0% Fat)', 'brand' => 'Natural', 'kcal' => 59.0, 'p' => 10.3, 'c' => 3.6, 'f' => 0.4, 'fib' => 0.0 ),
			array( 'name' => 'Chicken Breast (Grilled)', 'brand' => 'Fresh', 'kcal' => 165.0, 'p' => 31.0, 'c' => 0.0, 'f' => 3.6, 'fib' => 0.0 ),
			array( 'name' => 'Atlantic Salmon Fillet', 'brand' => 'Fresh Wild', 'kcal' => 208.0, 'p' => 20.4, 'c' => 0.0, 'f' => 13.4, 'fib' => 0.0 ),
			array( 'name' => 'Brown Rice (Cooked)', 'brand' => 'Whole Grain', 'kcal' => 123.0, 'p' => 2.7, 'c' => 25.6, 'f' => 1.0, 'fib' => 1.6 ),
			array( 'name' => 'Steamed Broccoli', 'brand' => 'Fresh Veg', 'kcal' => 35.0, 'p' => 2.4, 'c' => 7.2, 'f' => 0.4, 'fib' => 2.6 ),
			array( 'name' => 'Medium Banana', 'brand' => 'Fresh Fruit', 'kcal' => 89.0, 'p' => 1.1, 'c' => 22.8, 'f' => 0.3, 'fib' => 2.6 ),
			array( 'name' => 'Medium Apple', 'brand' => 'Fresh Fruit', 'kcal' => 52.0, 'p' => 0.3, 'c' => 13.8, 'f' => 0.2, 'fib' => 2.4 ),
			array( 'name' => 'Whole Wheat Bread', 'brand' => 'Bakery', 'kcal' => 247.0, 'p' => 13.0, 'c' => 41.0, 'f' => 3.4, 'fib' => 7.0 ),
			array( 'name' => 'Large Boiled Egg', 'brand' => 'Farm Fresh', 'kcal' => 155.0, 'p' => 12.6, 'c' => 1.1, 'f' => 10.6, 'fib' => 0.0 ),
			array( 'name' => 'Extra Virgin Olive Oil', 'brand' => 'Mediterranean', 'kcal' => 884.0, 'p' => 0.0, 'c' => 0.0, 'f' => 100.0, 'fib' => 0.0 ),
			array( 'name' => 'Almonds (Raw)', 'brand' => 'Nuts & Seeds', 'kcal' => 579.0, 'p' => 21.2, 'c' => 21.6, 'f' => 49.9, 'fib' => 12.5 ),
		);

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $staples as $i => $food ) {
			$wpdb->insert(
				$table,
				array(
					'source'       => 'usda',
					'source_ref'   => 'usda-staple-' . ( $i + 1 ),
					'name'         => $food['name'],
					'brand'        => $food['brand'],
					'barcode'      => '500000000' . str_pad( (string) ( $i + 1 ), 4, '0', STR_PAD_LEFT ),
					'portions'     => json_encode( array( array( 'label' => '1 serving (100g)', 'grams' => 100 ) ) ),
					'kcal_100g'    => $food['kcal'],
					'protein_100g' => $food['p'],
					'carb_100g'    => $food['c'],
					'sugar_100g'   => round( $food['c'] * 0.2, 1 ),
					'fat_100g'     => $food['f'],
					'satfat_100g'  => round( $food['f'] * 0.25, 1 ),
					'fibre_100g'   => $food['fib'],
					'salt_100g'    => 0.05,
					'alcohol_100g' => 0.0,
					'search_names' => strtolower( $food['name'] . ' ' . $food['brand'] ),
					'attribution'  => 'USDA FoodData Central / Synthetic fixture',
					'member_id'    => null,
					'updated_at'   => $now,
				)
			);
		}
		echo "Seeded " . count( $staples ) . " staple foods into food catalogue.\n";
	}

	private static function ensure_tracks() {
		$track_defs = array(
			'healthy-weight' => 'Healthy Weight',
			'blood-sugar'    => 'Blood Sugar Balance',
			'fitness'        => 'Fitness and Strength',
			'sleep-stress'   => 'Sleep and Stress',
		);
		foreach ( $track_defs as $slug => $title ) {
			$post = get_page_by_path( $slug, OBJECT, 'lld_track' );
			if ( ! $post ) {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'lld_track',
						'post_status'  => 'publish',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => "Welcome to the {$title} 4-week track.",
					)
				);
			} else {
				$post_id = $post->ID;
				if ( 'publish' !== $post->post_status ) {
					wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
				}
			}
			LLD_Members_Content::approve_now( (int) $post_id );
		}
		echo "Approved 4 core 4-week tracks.\n";
	}

	private static function seed_persona( array $p ) {
		global $wpdb;

		// 1. Create WP User
		$user = get_user_by( 'email', $p['email'] );
		if ( ! $user ) {
			$login = 'persona_' . sanitize_title( $p['name'] . '_' . $p['last_name'] );
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => self::PASSWORD,
					'user_email'   => $p['email'],
					'first_name'   => $p['name'],
					'last_name'    => $p['last_name'],
					'role'         => 'subscriber',
					'display_name' => $p['name'] . ' ' . $p['last_name'],
				)
			);
			if ( is_wp_error( $user_id ) ) {
				echo "Error creating user {$p['email']}: " . $user_id->get_error_message() . "\n";
				return;
			}
		} else {
			$user_id = (int) $user->ID;
			wp_set_password( self::PASSWORD, $user_id );
		}

		// 2. Create/Update lld_member row
		$member_table = LLD_Members_Schema::table( 'member' );
		$existing_member_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$member_table} WHERE user_id = %d", $user_id ) );

		$joined_days_ago = max( 1, $p['days_active'] );
		$joined_date = gmdate( 'Y-m-d', strtotime( "-{$joined_days_ago} days" ) );
		$now_time    = gmdate( 'Y-m-d H:i:s' );

		$member_data = array(
			'user_id'            => $user_id,
			'email'              => $p['email'],
			'first_name'         => $p['name'],
			'last_name'          => $p['last_name'],
			'age'                => $p['age'],
			'age_entered_on'     => $joined_date,
			'timezone'           => 'Europe/London',
			'units'              => 'metric',
			'role'               => 'member',
			'email_confirmed_at' => $now_time,
			'joined_on'          => $joined_date,
			'two_step'           => 0,
		);

		if ( $existing_member_id ) {
			$wpdb->update( $member_table, $member_data, array( 'id' => $existing_member_id ) );
			$member_id = $existing_member_id;
		} else {
			$wpdb->insert( $member_table, $member_data );
			$member_id = (int) $wpdb->insert_id;
		}

		// Clean old Stage 3 rows for this member to guarantee fresh clean seed
		self::clean_member_rows( $member_id );

		// 3. Insert Consents (Terms, Privacy, Health)
		$consent_table = LLD_Members_Schema::table( 'consent' );
		foreach ( array( 'terms', 'health' ) as $ctype ) {
			$wpdb->insert(
				$consent_table,
				array(
					'member_id'        => $member_id,
					'consent_type'     => $ctype,
					'change_kind'      => 'given',
					'document_version' => $ctype . '-v1',
					'occurred_at'      => $joined_date . ' 09:00:00',
				)
			);
		}

		// 4. Subscription (Free or Premium)
		$sub_table = LLD_Members_Schema::table( 'subscription' );
		$wpdb->replace(
			$sub_table,
			array(
				'member_id'           => $member_id,
				'plan'                => ( 'premium' === $p['tier'] ) ? 'yearly' : 'free',
				'access_state'        => $p['tier'],
				'stripe_state'        => ( 'premium' === $p['tier'] ) ? 'active' : 'none',
				'started_on'          => $joined_date,
				'period_ends_on'      => ( 'premium' === $p['tier'] ) ? gmdate( 'Y-m-d', strtotime( '+1 year' ) ) : null,
				'premium_ended_on'    => null,
				'stripe_subscription' => ( 'premium' === $p['tier'] ) ? 'sub_test_' . $p['key'] : '',
			)
		);

		// 5. Health Profile
		$profile_table = LLD_Members_Schema::table( 'health_profile' );
		$conditions = array();
		if ( 'clinical_safety' === $p['key'] ) {
			$conditions = array( 'kidney_disease' );
		}
		$wpdb->replace(
			$profile_table,
			array(
				'member_id'          => $member_id,
				'sex'                => $p['sex'],
				'height_cm'          => $p['height_cm'],
				'weight_kg'          => $p['curr_weight'],
				'waist_cm'           => $p['curr_waist'],
				'activity_level'     => ( 'fitness_strength' === $p['key'] ) ? 'active' : 'some',
				'diet_type'          => 'balanced',
				'ethnicity'          => ( 'cardio_metabolic' === $p['key'] ) ? 'black_african' : 'white',
				'conditions_answered'=> ! empty( $conditions ) ? 'answered' : 'none',
				'conditions'         => wp_json_encode( $conditions ),
				'updated_on'         => $joined_date,
			)
		);

		if ( 'fresh_join' === $p['key'] ) {
			echo "  Fresh join persona seeded with zero logs.\n";
			return;
		}

		// 6. Track Enrolment & Goal
		$track_id = 0;
		if ( ! empty( $p['program'] ) ) {
			$track_post = get_page_by_path( $p['program'], OBJECT, 'lld_track' );
			if ( $track_post ) {
				$track_id = (int) $track_post->ID;
				// Enrol in track
				$wpdb->insert(
					LLD_Members_Schema::table( 'enrolment' ),
					array(
						'member_id'    => $member_id,
						'track_id'     => $track_id,
						'enroled_at'   => $joined_date . ' 10:00:00',
						'status'       => 'active',
					)
				);
			}
		}

		// Goal setup
		$target_date = gmdate( 'Y-m-d', strtotime( "+12 weeks" ) );
		$goal_row = array(
			'role'          => 'main',
			'goal_type'     => $p['goal_type'],
			'measure'       => ( 'blood_pressure' === $p['goal_type'] ) ? 'blood_pressure' : ( 'sleep_stress' === $p['goal_type'] ? 'sleep' : 'weight' ),
			'start_value'   => ( 'blood_pressure' === $p['goal_type'] ) ? ( 'clinical_safety' === $p['key'] ? 180 : 142 ) : ( 'sleep_stress' === $p['goal_type'] ? 5.5 : $p['start_weight'] ),
			'target_value'  => ( 'blood_pressure' === $p['goal_type'] ) ? 120 : ( 'sleep_stress' === $p['goal_type'] ? 7.5 : $p['target_weight'] ),
			'start_value_2' => ( 'blood_pressure' === $p['goal_type'] ) ? ( 'clinical_safety' === $p['key'] ? 115 : 92 ) : null,
			'target_value_2'=> ( 'blood_pressure' === $p['goal_type'] ) ? 80 : null,
			'unit'          => ( 'blood_pressure' === $p['goal_type'] ) ? 'mmHg' : ( 'sleep_stress' === $p['goal_type'] ? 'h' : 'kg' ),
			'target_date'   => $target_date,
			'target_source' => 'member',
			'status'        => 'active',
		);
		$goal_id = LLD_Members_Stage3_Data::insert( 'goals', $member_id, $goal_row, LLD_Members_Goal_Journey::SEALED_GOAL );

		// Plan setup
		$plan_row = array(
			'goal_id'               => $goal_id,
			'version'               => 1,
			'track_id'              => $track_id,
			'calorie_target'        => ( 'weight_loss' === $p['key'] ) ? 1750 : ( 'fitness_strength' === $p['key'] ? 2400 : 2000 ),
			'protein_g'             => ( 'fitness_strength' === $p['key'] ) ? 135 : 95,
			'carb_g'                => 180,
			'fat_g'                 => 60,
			'fibre_g'               => 30,
			'activity_minutes_week' => ( 'fitness_strength' === $p['key'] ) ? 210 : 150,
			'valid_from'            => $joined_date,
			'valid_to'              => $target_date,
			'created_by'            => 'engine',
		);
		LLD_Members_Stage3_Data::insert( 'plans', $member_id, $plan_row );

		// 7. Seed Daily Historical Data
		self::seed_history( $member_id, $p, $goal_id, $track_id );

		// 8. Seed Saved Calculator Results
		self::seed_calculators( $member_id, $p );
	}

	private static function seed_history( $member_id, array $p, $goal_id, $track_id ) {
		global $wpdb;
		$days = $p['days_active'];
		$food_table = LLD_Members_Stage3_Schema::table( 'foods' );
		$staples = $wpdb->get_results( "SELECT id, name, kcal_100g, protein_100g, carb_100g, fat_100g, fibre_100g FROM {$food_table} LIMIT 8", ARRAY_A );

		for ( $i = $days; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$fraction = ( $days > 0 ) ? ( ( $days - $i ) / $days ) : 1.0;

			// --- Readings ---
			// Weight & Waist
			$w = round( $p['start_weight'] - ( ( $p['start_weight'] - $p['curr_weight'] ) * $fraction ), 1 );
			$waist = round( $p['start_waist'] - ( ( $p['start_waist'] - $p['curr_waist'] ) * $fraction ), 1 );

			LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
				'reading_type' => 'weight',
				'taken_at'     => $date . ' 07:30:00',
				'value_1'      => $w,
				'unit_shown'   => 'kg',
				'urgent_shown' => 0,
				'source'       => 'manual',
			), LLD_Members_Goal_Journey::SEALED_READING );

			if ( $i % 7 === 0 ) {
				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'waist',
					'taken_at'     => $date . ' 07:35:00',
					'value_1'      => $waist,
					'unit_shown'   => 'cm',
					'urgent_shown' => 0,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );
			}

			// Persona-specific Readings
			if ( 'blood_sugar' === $p['key'] ) {
				// Fasting Glucose (mmol/L)
				$glucose = round( 6.8 - ( 0.6 * $fraction ) + ( ( ( $i % 3 ) - 1 ) * 0.2 ), 1 );
				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'glucose',
					'taken_at'     => $date . ' 07:15:00',
					'value_1'      => $glucose,
					'detail'       => 'fasting',
					'unit_shown'   => 'mmol/L',
					'urgent_shown' => 0,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );
			}

			if ( 'cardio_metabolic' === $p['key'] ) {
				// Stage 1 Hypertension (136/88 to 144/92)
				$sys = 142 - (int) round( 4 * $fraction ) + ( ( $i % 3 ) * 2 );
				$dia = 90 - (int) round( 3 * $fraction ) + ( ( $i % 2 ) * 2 );
				$pulse = 72 + ( $i % 4 );
				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'blood_pressure',
					'taken_at'     => $date . ' 08:00:00',
					'value_1'      => $sys,
					'value_2'      => $dia,
					'value_3'      => $pulse,
					'unit_shown'   => 'mmHg',
					'urgent_shown' => 0,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );
			}

			if ( 'clinical_safety' === $p['key'] && $i === 0 ) {
				// Hypertensive Crisis Urgent Trigger (188/122 mmHg)
				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'blood_pressure',
					'taken_at'     => $date . ' 11:30:00',
					'value_1'      => 188,
					'value_2'      => 122,
					'value_3'      => 98,
					'unit_shown'   => 'mmHg',
					'urgent_shown' => 1,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );

				// Low glucose trigger (2.4 mmol/L)
				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'glucose',
					'taken_at'     => $date . ' 12:00:00',
					'value_1'      => 2.4,
					'detail'       => 'hypo',
					'unit_shown'   => 'mmol/L',
					'urgent_shown' => 1,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );
			}

			if ( 'sleep_stress' === $p['key'] ) {
				// Sleep hours (5.2 - 7.6h)
				$hours = round( 5.5 + ( 1.8 * $fraction ) + ( ( ( $i % 4 ) - 2 ) * 0.3 ), 1 );
				$mood = 4 + (int) round( 3 * $fraction );
				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'sleep',
					'taken_at'     => $date . ' 07:00:00',
					'value_1'      => $hours,
					'detail'       => 'Good rest',
					'unit_shown'   => 'h',
					'urgent_shown' => 0,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );

				LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
					'reading_type' => 'mood',
					'taken_at'     => $date . ' 20:00:00',
					'value_1'      => $mood,
					'unit_shown'   => 'score',
					'urgent_shown' => 0,
					'source'       => 'manual',
				), LLD_Members_Goal_Journey::SEALED_READING );
			}

			// Steps & Water
			$steps = 7500 + ( ( $i * 350 ) % 4000 );
			$water = 2000 + ( ( $i * 150 ) % 750 );
			LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
				'reading_type' => 'steps',
				'taken_at'     => $date . ' 21:00:00',
				'value_1'      => $steps,
				'unit_shown'   => 'steps',
				'urgent_shown' => 0,
				'source'       => 'manual',
			), LLD_Members_Goal_Journey::SEALED_READING );

			LLD_Members_Stage3_Data::insert( 'readings', $member_id, array(
				'reading_type' => 'water',
				'taken_at'     => $date . ' 19:30:00',
				'value_1'      => $water,
				'unit_shown'   => 'ml',
				'urgent_shown' => 0,
				'source'       => 'manual',
			), LLD_Members_Goal_Journey::SEALED_READING );

			// --- Today Step ---
			$day_num = max( 1, $days - $i + 1 );
			$step_status = ( $i === 0 ) ? 'shown' : ( ( $i % 6 === 0 ) ? 'skipped' : 'done' );
			LLD_Members_Stage3_Data::insert( 'today_steps', $member_id, array(
				'step_date'   => $date,
				'action_type' => 'activity',
				'ref_id'      => $track_id,
				'status'      => $step_status,
			) );

			// --- Weekly Check-In (every 7 days) ---
			if ( $i > 0 && $i % 7 === 0 ) {
				$check_week = (int) ( ( $days - $i ) / 7 ) + 1;
				$wpdb->insert(
					LLD_Members_Schema::table( 'check_in' ),
					array(
						'member_id'    => $member_id,
						'track_id'     => $track_id,
						'week_number'  => $check_week,
						'checked_in_on'=> $date,
						'responses'    => wp_json_encode( array( 'weight' => $w, 'waist' => $waist, 'feeling' => 'great' ) ),
					)
				);
			}

			// --- Food Log (Breakfast, Lunch, Dinner) ---
			if ( ! empty( $staples ) && count( $staples ) >= 4 ) {
				$meals = array(
					array( 'meal' => 'breakfast', 'food' => $staples[0], 'g' => 70 ),  // Oats
					array( 'meal' => 'breakfast', 'food' => $staples[1], 'g' => 150 ), // Greek Yogurt
					array( 'meal' => 'lunch',     'food' => $staples[2], 'g' => 180 ), // Chicken
					array( 'meal' => 'lunch',     'food' => $staples[5], 'g' => 120 ), // Broccoli
					array( 'meal' => 'dinner',    'food' => $staples[3], 'g' => 160 ), // Salmon
					array( 'meal' => 'dinner',    'food' => $staples[4], 'g' => 150 ), // Brown Rice
					array( 'meal' => 'snacks',    'food' => $staples[6], 'g' => 120 ), // Banana
				);

				foreach ( $meals as $m ) {
					$factor = $m['g'] / 100.0;
					$wpdb->insert(
						LLD_Members_Stage3_Schema::table( 'food_log' ),
						array(
							'member_id'     => $member_id,
							'entry_date'    => $date,
							'meal'          => $m['meal'],
							'food_id'       => (int) $m['food']['id'],
							'food_kind'     => 'usda',
							'grams'         => $m['g'],
							'portion_label' => $m['g'] . 'g portion',
							'kcal'          => round( (float) $m['food']['kcal_100g'] * $factor, 1 ),
							'protein_g'     => round( (float) $m['food']['protein_100g'] * $factor, 1 ),
							'carb_g'        => round( (float) $m['food']['carb_100g'] * $factor, 1 ),
							'fat_g'         => round( (float) $m['food']['fat_100g'] * $factor, 1 ),
							'fibre_g'       => round( (float) $m['food']['fibre_100g'] * $factor, 1 ),
						)
					);
				}
			}
		}
	}

	private static function seed_calculators( $member_id, array $p ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'calculator_result' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$past = gmdate( 'Y-m-d H:i:s', strtotime( '-21 days' ) );

		// 1. Healthy Weight Check (BMI, WHtR, range)
		$h_m = $p['height_cm'] / 100.0;
		$bmi = round( $p['curr_weight'] / ( $h_m * $h_m ), 1 );
		$whtr = round( $p['curr_waist'] / $p['height_cm'], 2 );
		$wpdb->insert( $table, array(
			'member_id'          => $member_id,
			'calculator'         => 'healthy_weight',
			'saved_at'           => $now,
			'headline'           => 'BMI ' . $bmi,
			'unit'               => 'kg/m²',
			'band'               => ( $bmi >= 30.0 ? 'obese' : ( $bmi >= 25.0 ? 'overweight' : 'healthy' ) ),
			'calculator_version' => '1.0',
			'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'height' => $p['height_cm'], 'weight' => $p['curr_weight'], 'waist' => $p['curr_waist'] ) ) ),
			'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'bmi' => $bmi, 'whtr' => $whtr ) ) ),
		) );

		// Earlier Healthy Weight Check (21 days ago)
		$start_bmi = round( $p['start_weight'] / ( $h_m * $h_m ), 1 );
		$wpdb->insert( $table, array(
			'member_id'          => $member_id,
			'calculator'         => 'healthy_weight',
			'saved_at'           => $past,
			'headline'           => 'BMI ' . $start_bmi,
			'unit'               => 'kg/m²',
			'band'               => ( $start_bmi >= 30.0 ? 'obese' : ( $start_bmi >= 25.0 ? 'overweight' : 'healthy' ) ),
			'calculator_version' => '1.0',
			'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'height' => $p['height_cm'], 'weight' => $p['start_weight'], 'waist' => $p['start_waist'] ) ) ),
			'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'bmi' => $start_bmi ) ) ),
		) );

		// 2. Daily Protein Target
		$prot_target = round( $p['curr_weight'] * 1.2 );
		$wpdb->insert( $table, array(
			'member_id'          => $member_id,
			'calculator'         => 'protein',
			'saved_at'           => $past,
			'headline'           => $prot_target . ' g',
			'unit'               => 'g/day',
			'band'               => 'standard',
			'calculator_version' => '2.0',
		) );

		// 3. Heart Attack & Stroke Risk (QRISK)
		if ( in_array( $p['key'], array( 'cardio_metabolic', 'blood_sugar', 'fitness_strength' ), true ) ) {
			$risk = ( 'cardio_metabolic' === $p['key'] ) ? 14.2 : 6.8;
			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'heart_risk',
				'saved_at'           => $now,
				'headline'           => $risk . '%',
				'unit'               => '% 10-year risk',
				'band'               => ( $risk >= 10.0 ? 'moderate' : 'low' ),
				'calculator_version' => '1.0',
				'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'age' => $p['age'], 'sex' => $p['sex'] ) ) ),
				'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'risk' => $risk ) ) ),
			) );
		}

		// 4. Blood Pressure Check
		if ( in_array( $p['key'], array( 'cardio_metabolic', 'clinical_safety' ), true ) ) {
			$sys = ( 'clinical_safety' === $p['key'] ) ? 188 : 138;
			$dia = ( 'clinical_safety' === $p['key'] ) ? 122 : 88;
			$band = ( $sys >= 180 || $dia >= 120 ) ? 'crisis' : ( ( $sys >= 130 || $dia >= 80 ) ? 'stage1' : 'normal' );
			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'bp_check',
				'saved_at'           => $now,
				'headline'           => "{$sys}/{$dia} mmHg",
				'unit'               => 'mmHg',
				'band'               => $band,
				'calculator_version' => '1.1',
				'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'sys' => $sys, 'dia' => $dia ) ) ),
				'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'band' => $band ) ) ),
			) );
		}

		// 5. Metabolic Health Check
		if ( 'cardio_metabolic' === $p['key'] ) {
			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'metabolic',
				'saved_at'           => $now,
				'headline'           => '3 of 5 criteria met',
				'unit'               => 'criteria',
				'band'               => 'elevated',
				'calculator_version' => '1.1',
				'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'waist' => $p['curr_waist'], 'bp_sys' => 138, 'bp_dia' => 88 ) ) ),
				'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'criteria' => 3 ) ) ),
			) );
		}

		// 6. Fitness Age & Heart Rate Zones
		if ( 'fitness_strength' === $p['key'] ) {
			$fit_age = 44;
			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'fitness_age',
				'saved_at'           => $now,
				'headline'           => 'Fitness Age ' . $fit_age,
				'unit'               => 'years',
				'band'               => 'younger',
				'calculator_version' => '1.0',
				'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'chrono_age' => $p['age'], 'resting_hr' => 62 ) ) ),
				'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'fitness_age' => $fit_age ) ) ),
			) );

			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'hr_zones',
				'saved_at'           => $past,
				'headline'           => 'Zone 2: 110-128 bpm',
				'unit'               => 'bpm',
				'band'               => 'endurance',
				'calculator_version' => '1.0',
				'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'max_hr' => 168, 'resting_hr' => 62 ) ) ),
				'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'zone2_min' => 110, 'zone2_max' => 128 ) ) ),
			) );
		}

		// 7. Sleep and Stress Check
		if ( 'sleep_stress' === $p['key'] ) {
			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'sleep_stress',
				'saved_at'           => $now,
				'headline'           => 'Moderate stress / light sleep',
				'unit'               => 'score',
				'band'               => 'moderate',
				'calculator_version' => '1.0',
				'inputs_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'avg_sleep' => 5.8, 'stress_q' => 'often' ) ) ),
				'result_ct'          => LLD_Members_Stage3_Data::seal( $member_id, wp_json_encode( array( 'band' => 'moderate' ) ) ),
			) );
		}

		// 8. Type 2 Diabetes Risk Check (QDiabetes)
		if ( 'blood_sugar' === $p['key'] ) {
			$wpdb->insert( $table, array(
				'member_id'          => $member_id,
				'calculator'         => 'diabetes_risk',
				'saved_at'           => $past,
				'headline'           => '8.5% 10-year risk',
				'unit'               => '% risk',
				'band'               => 'moderate',
				'calculator_version' => '1.0',
			) );
		}
	}

	private static function clean_member_rows( $member_id ) {
		global $wpdb;
		$suffixes = array(
			'goals', 'goal_baselines', 'plans', 'plan_adjustments', 'today_steps',
			'insights', 'food_log', 'readings', 'reminders', 'shares', 'calculator_result'
		);
		foreach ( $suffixes as $s ) {
			$tbl = ( 'calculator_result' === $s ) ? LLD_Members_Schema::table( $s ) : LLD_Members_Stage3_Schema::table( $s );
			$wpdb->delete( $tbl, array( 'member_id' => $member_id ), array( '%d' ) );
		}
	}

	private static function clean_all() {
		global $wpdb;
		foreach ( self::PERSONAS as $p ) {
			$user = get_user_by( 'email', $p['email'] );
			if ( $user ) {
				$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
				if ( $member_id ) {
					self::clean_member_rows( $member_id );
					$wpdb->delete( LLD_Members_Schema::table( 'member' ), array( 'id' => $member_id ), array( '%d' ) );
					$wpdb->delete( LLD_Members_Schema::table( 'consent' ), array( 'member_id' => $member_id ), array( '%d' ) );
					$wpdb->delete( LLD_Members_Schema::table( 'subscription' ), array( 'member_id' => $member_id ), array( '%d' ) );
					$wpdb->delete( LLD_Members_Schema::table( 'health_profile' ), array( 'member_id' => $member_id ), array( '%d' ) );
					$wpdb->delete( LLD_Members_Schema::table( 'enrolment' ), array( 'member_id' => $member_id ), array( '%d' ) );
				}
				wp_delete_user( $user->ID );
			}
		}
	}

	private static function run_insights() {
		global $wpdb;
		foreach ( self::PERSONAS as $p ) {
			$user = get_user_by( 'email', $p['email'] );
			if ( ! $user ) continue;
			$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
			if ( ! $member_id ) continue;

			if ( class_exists( 'LLD_Members_Insights_Runner' ) ) {
				$res = LLD_Members_Insights_Runner::run_member( $member_id, gmdate( 'Y-m-d' ), array( 'dry_run' => false, 'at_check_in' => false ) );
				echo "  Member {$member_id} ({$p['name']}): status={$res['status']}, today_step=" . ( $res['today_step'] ?? 'none' ) . "\n";
			}
		}
	}

	private static function show_status() {
		global $wpdb;
		echo sprintf( "%-18s | %-8s | %-20s | %-6s | %-12s | %-10s\n", "Persona", "Tier", "Track", "Readings", "Food Logs", "Calc Saves" );
		echo str_repeat( "-", 85 ) . "\n";

		foreach ( self::PERSONAS as $p ) {
			$user = get_user_by( 'email', $p['email'] );
			if ( ! $user ) {
				echo sprintf( "%-18s | (not seeded yet)\n", $p['name'] . ' ' . $p['last_name'] );
				continue;
			}
			$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
			if ( ! $member_id ) {
				echo sprintf( "%-18s | (no member row)\n", $p['name'] . ' ' . $p['last_name'] );
				continue;
			}

			$readings_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Stage3_Schema::table( 'readings' ) . ' WHERE member_id = %d', $member_id ) );
			$food_count     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Stage3_Schema::table( 'food_log' ) . ' WHERE member_id = %d', $member_id ) );
			$calc_count     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'calculator_result' ) . ' WHERE member_id = %d', $member_id ) );

			echo sprintf(
				"%-18s | %-8s | %-20s | %-8d | %-12d | %-10d\n",
				$p['name'] . ' ' . $p['last_name'],
				strtoupper( $p['tier'] ),
				substr( $p['program_title'] ?: 'None (Fresh)', 0, 20 ),
				$readings_count,
				$food_count,
				$calc_count
			);
		}
		echo "\nSign-in Password for all personas: " . self::PASSWORD . "\n";
		echo "Base URL: http://localhost:8090/members/sign-in/\n";
	}
}

// CLI execution dispatch
$action = ( isset( $args[0] ) && '' !== $args[0] ) ? $args[0] : 'seed';
LLD_Stage3_Persona_Seeder::run( $action );
