<?php
/**
 * Stage 3 journey personas (S3-P91): seven made-up members replayed day by day through the plugin's own code.
 *
 * Every step calls the same functions the screens call (health profile, goal, track, readings with the safety
 * check, food log, Today step, check-in, insights), so a broken step prints FAIL and the run exits with code 1.
 * Nothing is written straight into a table except the account itself, which is made the way the CI test route
 * makes it. History is replayed with the member test clock (LLD_TEST_CLOCK); without it only "today" is seeded.
 *
 * Not for production: it stops there. Take a staging backup before running it on staging.
 *
 * Usage (run as an administrator, needed for the Premium test member action):
 *   wp eval-file tools/local/seed-stage3-test-personas.php seed   --user=<admin login>
 *   wp eval-file tools/local/seed-stage3-test-personas.php status --user=<admin login>
 *   wp eval-file tools/local/seed-stage3-test-personas.php clean  --user=<admin login>
 *
 * Password: LLD_PERSONA_PASSWORD if set (12+ characters), otherwise a new random one per seed. It is never printed:
 * it is written with the emails to LLD_PERSONA_FILE (default ~/lld-personas.json, outside the web root, mode 600),
 * which the browser check reads. All names, emails and numbers are made up; emails end in .invalid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LLD_Stage3_Persona_Seeder {

	/** Premium personas are Premium test members (FR3-91): the panel allows 5 in all, so only three are Premium. */
	const PERSONAS = array(
		'weight_loss'      => array( 'first' => 'Sarah', 'last' => 'Jenkins', 'premium' => true, 'sex' => 'female', 'age' => 38, 'height' => 168, 'weight' => 84.0, 'weight_end' => 80.8, 'waist' => 92, 'conditions' => array(), 'goal' => 'lose_weight', 'goal_in' => array( 'current' => 84.0, 'target' => 74.0, 'rate' => 0.5 ), 'track' => 'healthy-weight', 'days' => 28 ),
		'blood_sugar'      => array( 'first' => 'Marcus', 'last' => 'Thorne', 'premium' => false, 'sex' => 'male', 'age' => 45, 'height' => 178, 'weight' => 88.0, 'weight_end' => 87.5, 'waist' => 98, 'conditions' => array(), 'goal' => 'blood_sugar', 'goal_in' => array( 'current' => 6.2, 'target' => 5.9 ), 'track' => 'blood-sugar', 'days' => 20 ),
		'sleep_stress'     => array( 'first' => 'Elena', 'last' => 'Rostova', 'premium' => true, 'sex' => 'female', 'age' => 34, 'height' => 165, 'weight' => 62.0, 'weight_end' => 61.5, 'waist' => 74, 'conditions' => array(), 'goal' => 'sleep', 'goal_in' => array( 'current' => 5.5, 'target' => 7.5 ), 'track' => 'sleep-stress', 'days' => 21 ),
		'fitness_strength' => array( 'first' => 'David', 'last' => 'Miller', 'premium' => true, 'sex' => 'male', 'age' => 52, 'height' => 182, 'weight' => 79.0, 'weight_end' => 78.5, 'waist' => 85, 'conditions' => array(), 'goal' => 'activity', 'goal_in' => array( 'current' => 60, 'target' => 150 ), 'track' => 'fitness', 'days' => 21 ),
		'cardio_metabolic' => array( 'first' => 'Amara', 'last' => 'Okafor', 'premium' => false, 'sex' => 'female', 'age' => 49, 'height' => 163, 'weight' => 75.0, 'weight_end' => 74.5, 'waist' => 89, 'conditions' => array(), 'goal' => 'lower_blood_pressure', 'goal_in' => array( 'current' => 142, 'current_2' => 90 ), 'track' => 'healthy-weight', 'days' => 21 ),
		'clinical_safety'  => array( 'first' => 'Liam', 'last' => 'Gallagher', 'premium' => false, 'sex' => 'male', 'age' => 60, 'height' => 175, 'weight' => 94.0, 'weight_end' => 94.0, 'waist' => 106, 'conditions' => array( 'kidney' ), 'goal' => 'lower_blood_pressure', 'goal_in' => array( 'current' => 150, 'current_2' => 95 ), 'track' => 'blood-sugar', 'days' => 5 ),
		'fresh_join'       => array( 'first' => 'Priya', 'last' => 'Patel', 'premium' => false, 'sex' => 'female', 'age' => 29, 'height' => 160, 'weight' => 65.0, 'weight_end' => 65.0, 'waist' => 78, 'conditions' => array(), 'goal' => '', 'goal_in' => array(), 'track' => '', 'days' => 0 ),
	);

	const REASON = 'Stage 3 journey personas (made-up test data)';

	/** @var array<int,array{0:string,1:string,2:string}> [status, persona, step]. */
	private static $results = array();

	public static function email( $key ) {
		return 'persona-' . str_replace( '_', '-', $key ) . '@test.lifelinedaily.invalid';
	}

	public static function run( $action ) {
		if ( lld_members_is_production() ) {
			self::stop( 'This is production. The persona seeder never runs here.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::stop( 'Run as an administrator: add --user=<admin login>.' );
		}
		if ( 'status' === $action ) {
			self::status();
			return;
		}
		if ( 'clean' === $action ) {
			foreach ( array_keys( self::PERSONAS ) as $key ) {
				self::remove( $key );
			}
			echo "Clean finished: the persona accounts and every row of theirs are deleted.\n";
			return;
		}
		if ( 'seed' !== $action ) {
			self::stop( 'Unknown action. Use seed, status or clean.' );
		}

		$clock = lld_members_test_clock_allowed();
		echo $clock ? "Test clock on: history is replayed day by day.\n" : "Test clock off (LLD_TEST_CLOCK): only today is seeded, no history.\n";
		$password = self::password();
		$foods    = self::foods();
		if ( ! $foods ) {
			self::note( 'FAIL', 'all', 'food catalogue has no foods with calories (food logging skipped)' );
		}
		$out = array();
		foreach ( self::PERSONAS as $key => $p ) {
			echo "\n== {$p['first']} {$p['last']} ({$key})\n";
			$member_id = self::persona( $key, $p, $password, $foods, $clock );
			$out[]     = array( 'key' => $key, 'email' => self::email( $key ), 'name' => $p['first'] . ' ' . $p['last'], 'premium' => $p['premium'], 'goal' => $p['goal'], 'track' => $p['track'], 'member_id' => $member_id );
		}
		self::write_file( $password, $out );
		self::summary();
	}

	// ---- One persona ---------------------------------------------------------------------------------------------

	private static function persona( $key, array $p, $password, array $foods, $clock ) {
		self::remove( $key ); // a clean start every run
		$member_id = self::account( $key, $p, $password );
		if ( ! $member_id ) {
			return 0;
		}
		$days  = $clock ? (int) $p['days'] : 0;
		$start = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		self::at( $member_id, $start, '08:00:00', $clock );

		if ( $p['premium'] ) {
			$r = LLD_Members_Admin_Members::run( 'ptm', $member_id, array( 'on' => '1', 'reason' => self::REASON ) );
			self::check( $key, 'Premium test member', $r );
		} else {
			self::check( $key, 'test account', LLD_Members_Admin_Members::run( 'test', $member_id, array( 'on' => '1', 'reason' => self::REASON ) ) );
		}

		$profile = array(
			'kind'                => 'profile',
			'sex'                 => $p['sex'],
			'height_cm'           => $p['height'],
			'weight_kg'           => $p['weight'],
			'waist_cm'            => $p['waist'],
			'activity_level'      => 'some',
			'conditions_answered' => $p['conditions'] ? 'declared' : 'none',
			'conditions'          => $p['conditions'],
			'confirmed'           => true,
		);
		if ( 'fresh_join' !== $key ) {
			self::check( $key, 'health profile', LLD_Members_Account::save_health( $member_id, $profile ) );
			$facts = LLD_Members_Goal_Journey::facts( $member_id );
			if ( in_array( 'kidney', $p['conditions'], true ) ) {
				self::expect( $key, 'kidney disease recognised by the safety matrix', in_array( 'kidney', (array) $facts['situations'], true ), 'situations: ' . implode( ',', (array) $facts['situations'] ) );
			}
		}
		if ( '' === $p['goal'] ) {
			$facts = LLD_Members_Goal_Journey::facts( $member_id );
			self::expect( $key, 'fresh member is sent to goal setting', LLD_Members_Goal_Journey::needs_goal( $member_id, $facts ) || in_array( 'unknown', (array) $facts['situations'], true ), '' );
			self::clear( $member_id, $clock );
			return $member_id;
		}

		$goal = LLD_Members_Goal_Journey::create_goal( $member_id, 'main', $p['goal'], $p['goal_in'] + array( 'height_cm' => $p['height'] ) );
		self::check( $key, "goal {$p['goal']} and plan", $goal );
		if ( $p['track'] ) {
			self::check( $key, "track {$p['track']}", LLD_Members_Programme::start( $member_id, $p['track'] ) );
		}

		for ( $d = 0; $d <= $days; $d++ ) {
			$date = gmdate( 'Y-m-d', strtotime( $start . ' +' . $d . ' days' ) );
			$f    = $days ? $d / $days : 1.0;
			self::at( $member_id, $date, '07:30:00', $clock );
			self::reading( $key, $member_id, 'weight', array( 'value_1' => round( $p['weight'] - ( $p['weight'] - $p['weight_end'] ) * $f, 1 ) ), 'kg', 'none', 0 === $d );
			if ( 'blood_sugar' === $key ) {
				self::reading( $key, $member_id, 'glucose', array( 'value_1' => round( 6.6 - 0.5 * $f + ( ( $d % 3 ) - 1 ) * 0.2, 1 ) ), 'mmol/L', 'none', 0 === $d );
			}
			if ( 'cardio_metabolic' === $key ) {
				self::reading( $key, $member_id, 'blood_pressure', array( 'value_1' => 142 - (int) round( 4 * $f ) + ( $d % 3 ), 'value_2' => 90 - (int) round( 3 * $f ), 'value_3' => 72 ), 'mmHg', 'none', 0 === $d );
			}
			if ( 'sleep_stress' === $key ) {
				self::reading( $key, $member_id, 'sleep', array( 'value_1' => round( 5.5 + 1.8 * $f, 1 ) ), 'h', null, 0 === $d );
				self::check( $key, 'mood tile', LLD_Members_Goal_Journey::quick_log( $member_id, 'mood', 2 + (int) round( 2 * $f ) ), 0 === $d );
			}
			if ( 'fitness_strength' === $key ) {
				self::reading( $key, $member_id, 'activity', array( 'value_1' => 30 ), 'min', null, 0 === $d );
			}
			self::check( $key, 'water tile', LLD_Members_Goal_Journey::quick_log( $member_id, 'water', 6 ), 0 === $d );
			foreach ( $foods ? array( 'breakfast', 'lunch', 'dinner' ) : array() as $i => $meal ) {
				self::food( $key, $member_id, $foods[ ( $d + $i ) % count( $foods ) ], $date, $meal, 0 === $d );
			}
			self::at( $member_id, $date, '20:00:00', $clock );
			if ( $d < $days ) {
				// The engine picks a step on some days only; on the others Today shows the plan's step and Done still
				// saves a row (mark_step, as the Done and Skip buttons do). The real today stays open for the browser.
				LLD_Members_Goal_Journey::today_step( $member_id, $date );
				self::expect( $key, 'Today step Done or Skip saved', (bool) LLD_Members_Goal_Journey::mark_step( $member_id, $date, 0 === $d % 6 ? 'skipped' : 'done' ), $date, 0 === $d );
			}
			if ( $d > 0 && 0 === $d % 7 && LLD_Members_Goal_Journey::check_in_due( $member_id, $date ) ) {
				self::expect( $key, 'weekly check-in recorded', (bool) LLD_Members_Goal_Journey::record_check_in( $member_id, $date ), $date );
			}
		}

		if ( 'clinical_safety' === $key ) {
			self::at( $member_id, gmdate( 'Y-m-d' ), '11:30:00', $clock );
			self::reading( $key, $member_id, 'blood_pressure', array( 'value_1' => 188, 'value_2' => 122, 'value_3' => 98 ), 'mmHg', 'urgent', true );
			self::reading( $key, $member_id, 'glucose', array( 'value_1' => 2.4 ), 'mmol/L', 'urgent', true );
		}
		self::clear( $member_id, $clock );
		if ( class_exists( 'LLD_Members_Insights_Runner' ) ) {
			$res = LLD_Members_Insights_Runner::run_member( $member_id, gmdate( 'Y-m-d' ), array( 'dry_run' => false, 'at_check_in' => false ) );
			self::note( 'INFO', $key, 'insights run: ' . ( is_array( $res ) && isset( $res['status'] ) ? $res['status'] : 'no result' ) );
		}
		return $member_id;
	}

	/** The account, as the CI test route makes it: user, member row, terms and health consent. */
	private static function account( $key, array $p, $password ) {
		global $wpdb;
		$email   = self::email( $key );
		$user_id = wp_insert_user(
			array(
				'user_login'   => LLD_Members_Account::unique_login( $email ),
				'user_pass'    => $password,
				'user_email'   => $email,
				'first_name'   => $p['first'],
				'last_name'    => $p['last'],
				'role'         => 'subscriber',
				'display_name' => $p['first'],
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::note( 'FAIL', $key, 'account: ' . $user_id->get_error_message() );
			return 0;
		}
		$joined = gmdate( 'Y-m-d', time() - (int) $p['days'] * DAY_IN_SECONDS );
		$ok     = $wpdb->insert(
			LLD_Members_Schema::table( 'member' ),
			array(
				'user_id'            => $user_id,
				'email'              => $email,
				'first_name'         => $p['first'],
				'last_name'          => $p['last'],
				'age'                => $p['age'],
				'age_entered_on'     => $joined,
				'timezone'           => 'UTC',
				'units'              => 'metric',
				'role'               => 'member',
				'email_confirmed_at' => $joined . ' 08:00:00',
				'joined_on'          => $joined,
				'two_step'           => 0,
			)
		);
		if ( ! $ok ) {
			self::note( 'FAIL', $key, 'member row: ' . $wpdb->last_error );
			return 0;
		}
		$member_id = (int) $wpdb->insert_id;
		foreach ( array( 'terms', 'health' ) as $type ) {
			$wpdb->insert( LLD_Members_Schema::table( 'consent' ), array( 'member_id' => $member_id, 'consent_type' => $type, 'change_kind' => 'given', 'document_version' => $type . '-test', 'occurred_at' => $joined . ' 08:00:00' ) );
		}
		self::note( 'PASS', $key, "account (member {$member_id})" );
		return $member_id;
	}

	/** A reading through save_reading (the safety check included). $want is the warning level expected, or null. */
	private static function reading( $key, $member_id, $type, array $values, $unit, $want, $show = true ) {
		$r = LLD_Members_Goal_Journey::save_reading( $member_id, $type, $values, $unit );
		if ( empty( $r['id'] ) ) {
			self::note( 'FAIL', $key, "{$type} reading not saved (level " . ( $r['level'] ?? '?' ) . ')' );
			return;
		}
		if ( null !== $want && $want !== $r['level'] ) {
			self::note( 'FAIL', $key, "{$type} " . implode( '/', $values ) . ": warning level {$r['level']}, expected {$want}" );
			return;
		}
		if ( $show ) {
			self::note( 'PASS', $key, "{$type} reading" . ( 'urgent' === $want ? ' shows the urgent warning' : '' ) );
		}
	}

	/** A food entry the way the members' interface saves it: Food_Log::for_member fills the numbers, then insert. */
	private static function food( $key, $member_id, array $food, $date, $meal, $show ) {
		$filled = LLD_Members_Food_Log::for_member( $member_id, array( 'food_kind' => 'food', 'food_id' => (int) $food['id'], 'grams' => 100, 'meal' => $meal, 'entry_date' => $date ) );
		if ( isset( $filled['error'] ) ) {
			self::note( 'FAIL', $key, 'food entry: ' . $filled['error'] );
			return;
		}
		$id = LLD_Members_Stage3_Data::insert( 'food_log', $member_id, $filled );
		if ( ! $id ) {
			global $wpdb;
			self::note( 'FAIL', $key, 'food entry not saved: ' . $wpdb->last_error );
		} elseif ( $show ) {
			self::note( 'PASS', $key, 'food entry' );
		}
	}

	private static function foods() {
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'foods' );
		return (array) $wpdb->get_results( "SELECT id FROM {$table} WHERE member_id IS NULL AND kcal_100g > 0 ORDER BY id LIMIT 6", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ---- Clock, removal, output ----------------------------------------------------------------------------------

	private static function at( $member_id, $date, $time, $clock ) {
		if ( $clock ) {
			LLD_Members_Clock::set( $member_id, $date . ' ' . $time );
		}
	}

	private static function clear( $member_id, $clock ) {
		if ( $clock ) {
			LLD_Members_Clock::clear( $member_id );
		}
	}

	/** Remove a persona through the plugin's own account deletion, then the WordPress user. */
	private static function remove( $key ) {
		global $wpdb;
		$user = get_user_by( 'email', self::email( $key ) );
		if ( ! $user ) {
			return;
		}
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) );
		if ( $member_id ) {
			if ( LLD_Members_Admin_Members::is_premium_test( $member_id ) ) {
				LLD_Members_Admin_Members::run( 'ptm', $member_id, array( 'on' => '', 'reason' => self::REASON ) );
			}
			LLD_Members_Clock::clear( $member_id );
			LLD_Members_Erasure::delete_account( $member_id, false );
		}
		LLD_Members_Features::save_test_accounts( array_values( array_diff( LLD_Members_Features::test_account_emails(), array( strtolower( self::email( $key ) ) ) ) ) );
		if ( get_user_by( 'id', $user->ID ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID );
		}
	}

	private static function password() {
		$env = getenv( 'LLD_PERSONA_PASSWORD' );
		if ( is_string( $env ) && strlen( $env ) >= 12 ) {
			return $env;
		}
		return wp_generate_password( 24, true, false );
	}

	private static function write_file( $password, array $personas ) {
		$path = getenv( 'LLD_PERSONA_FILE' );
		if ( ! is_string( $path ) || '' === $path ) {
			$home = getenv( 'HOME' ) ?: getenv( 'USERPROFILE' );
			$path = rtrim( (string) $home, '/\\' ) . '/lld-personas.json';
		}
		$real_root = realpath( ABSPATH );
		$real_dir  = realpath( dirname( $path ) );
		if ( $real_root && $real_dir && 0 === strpos( $real_dir, $real_root ) ) {
			self::stop( 'LLD_PERSONA_FILE is inside the web root. Choose a path outside it.' );
		}
		file_put_contents( $path, wp_json_encode( array( 'password' => $password, 'seeded_at' => gmdate( 'c' ), 'personas' => $personas ), JSON_PRETTY_PRINT ) );
		@chmod( $path, 0600 );
		echo "\nSign-in details written to {$path} (the password is not printed).\n";
	}

	private static function status() {
		global $wpdb;
		foreach ( self::PERSONAS as $key => $p ) {
			$user = get_user_by( 'email', self::email( $key ) );
			$id   = $user ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d', $user->ID ) ) : 0;
			if ( ! $id ) {
				printf( "%-18s not seeded\n", $p['first'] . ' ' . $p['last'] );
				continue;
			}
			$count = function ( $suffix ) use ( $wpdb, $id ) {
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Stage3_Schema::table( $suffix ) . ' WHERE member_id = %d AND deleted_at IS NULL', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			};
			printf( "%-18s member %-6d %-8s goals %d, readings %d, food entries %d\n", $p['first'] . ' ' . $p['last'], $id, LLD_Members_Admin_Members::is_premium_test( $id ) ? 'Premium' : 'Free', count( LLD_Members_Goal_Journey::goals( $id ) ), $count( 'readings' ), $count( 'food_log' ) );
		}
	}

	// ---- Results ---------------------------------------------------------------------------------------------------

	private static function check( $key, $step, $result, $show = true ) {
		if ( is_wp_error( $result ) ) {
			self::note( 'FAIL', $key, $step . ': ' . $result->get_error_message() );
		} elseif ( false === $result || ( is_array( $result ) && isset( $result['id'] ) && empty( $result['id'] ) ) ) {
			self::note( 'FAIL', $key, $step . ': not saved' );
		} elseif ( $show ) {
			self::note( 'PASS', $key, $step );
		}
	}

	private static function expect( $key, $step, $ok, $detail, $show = true ) {
		if ( ! $ok ) {
			self::note( 'FAIL', $key, $step . ( $detail ? " ({$detail})" : '' ) );
		} elseif ( $show ) {
			self::note( 'PASS', $key, $step );
		}
	}

	private static function note( $status, $key, $step ) {
		self::$results[] = array( $status, $key, $step );
		echo "  [{$status}] {$step}\n";
	}

	private static function summary() {
		$fails = array_filter( self::$results, function ( $r ) { return 'FAIL' === $r[0]; } );
		$pass  = count( array_filter( self::$results, function ( $r ) { return 'PASS' === $r[0]; } ) );
		echo "\n== Summary: {$pass} passed, " . count( $fails ) . " failed\n";
		foreach ( $fails as $f ) {
			echo "  FAIL {$f[1]}: {$f[2]}\n";
		}
		if ( $fails ) {
			exit( 1 );
		}
	}

	private static function stop( $message ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

LLD_Stage3_Persona_Seeder::run( isset( $args[0] ) && '' !== $args[0] ? (string) $args[0] : 'seed' );
