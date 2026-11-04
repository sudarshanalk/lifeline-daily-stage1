<?php
/**
 * Stage 3 tables (data model v0.2, Stage 3 section). Only adds: new tables here, new columns through
 * ensure_column(). Never a DROP, MODIFY, CHANGE or RENAME (NFR3-04; CI stage3-guards reads this file).
 *
 * Health values are stored as ciphertext columns (…_ct) sealed with the site data key by
 * LLD_Members_Stage3_Data, so a copied database shows no readable health value (NFR3-10).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage 3 schema. Runs inside LLD_Members_Schema::update(), so `wp lld-members db-update` covers it.
 */
class LLD_Members_Stage3_Schema {

	const VERSION = 4;

	/**
	 * Every Stage 3 table suffix, in creation order. Stage 2's all_suffixes() is left as it is; the
	 * erasure checker learns these through the lld_members_known_tables filter.
	 *
	 * @return string[]
	 */
	public static function suffixes() {
		return array(
			'identities',
			'email_codes',
			'goals',
			'goal_baselines',
			'plans',
			'plan_adjustments',
			'today_steps',
			'insights',
			'foods',
			'custom_foods',
			'food_log',
			'food_favourites',
			'nutrition_targets',
			'recipes',
			'food_preferences',
			'readings',
			'reminders',
			'shares',
			'pauses',
		);
	}

	/**
	 * Stage 3 tables whose rows go when health consent is withdrawn (data model: "deleted with the
	 * account or consent withdrawal").
	 *
	 * @return string[]
	 */
	public static function health_suffixes() {
		return array( 'goals', 'goal_baselines', 'plans', 'plan_adjustments', 'insights', 'food_log', 'nutrition_targets', 'food_preferences', 'readings' );
	}

	/**
	 * How each table links to a member, for the erasure checker (LLD_Members_Privacy::member_links()).
	 *
	 * @return array<string,string[]>
	 */
	public static function member_links() {
		$links = array();
		foreach ( self::suffixes() as $suffix ) {
			$links[ $suffix ] = array( 'member_id' );
		}
		$links['email_codes']    = array( 'email' );
		$links['goal_baselines'] = array( 'via:goals' );
		$links['foods']          = array( 'member_id' ); // only member-added packaged foods carry a member number
		return $links;
	}

	/**
	 * Create any missing Stage 3 table and add any missing column. Safe to run any number of times.
	 *
	 * @return array{changed:bool,version:int}
	 */
	public static function update() {
		$before = (int) get_option( 'lld_members_stage3_schema_version', 0 );
		self::create_all();
		self::upgrade();
		update_option( 'lld_members_stage3_schema_version', self::VERSION, true );
		return array(
			'changed' => $before < self::VERSION,
			'version' => self::VERSION,
		);
	}

	/**
	 * Full table name for a Stage 3 suffix.
	 *
	 * @param string $suffix Suffix after lld_.
	 */
	public static function table( $suffix ) {
		return LLD_Members_Schema::table( $suffix );
	}

	/**
	 * Column and key definitions of every Stage 3 table, by suffix. Packages read their own table's
	 * definition from here (for example the food importer); nothing else defines a Stage 3 table.
	 *
	 * @return array<string,string>
	 */
	public static function definitions() {
		return array(
			// Linked sign-in (FR3-01, FR3-02, FR3-05).
			'identities' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(16) NOT NULL,
			provider_user_id VARCHAR(190) NOT NULL,
			provider_email VARCHAR(190) NOT NULL,
			linked_at DATETIME NOT NULL,
			last_used_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_identity (provider, provider_user_id),
			KEY member_id (member_id)
			',
			// Email code (FR3-03, FR3-04, NFR3-18): the code itself is never stored.
			'email_codes' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			purpose VARCHAR(16) NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			used_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY email_purpose (email, purpose)
			',
			// Goal (FR3-09, FR3-13, FR3-15): values sealed.
			'goals' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(8) NOT NULL,
			goal_type VARCHAR(32) NOT NULL,
			measure VARCHAR(32) NOT NULL,
			start_value_ct TEXT NULL,
			target_value_ct TEXT NULL,
			unit VARCHAR(16) NOT NULL,
			target_date DATE NULL,
			target_source VARCHAR(24) NOT NULL DEFAULT "member",
			status VARCHAR(16) NOT NULL DEFAULT "active",
			reached_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_updated (member_id, updated_at)
			',
			// Goal baseline (FR3-10).
			'goal_baselines' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			goal_id BIGINT UNSIGNED NOT NULL,
			measure VARCHAR(32) NOT NULL,
			reading_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY goal_id (goal_id)
			',
			// Personal plan (FR3-11, FR3-14, FR3-28).
			'plans' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			goal_id BIGINT UNSIGNED NOT NULL,
			version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			track_id BIGINT UNSIGNED NULL,
			calorie_target SMALLINT UNSIGNED NULL,
			protein_g SMALLINT UNSIGNED NULL,
			carb_g SMALLINT UNSIGNED NULL,
			fat_g SMALLINT UNSIGNED NULL,
			fibre_g SMALLINT UNSIGNED NULL,
			activity_minutes_week SMALLINT UNSIGNED NULL,
			meal_plan_id BIGINT UNSIGNED NULL,
			workout_plan_id BIGINT UNSIGNED NULL,
			log_schedule TEXT NULL,
			valid_from DATE NULL,
			valid_to DATE NULL,
			created_by VARCHAR(8) NOT NULL DEFAULT "engine",
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_updated (member_id, updated_at)
			',
			// Plan adjustment (FR3-14).
			'plan_adjustments' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			previous_plan_id BIGINT UNSIGNED NOT NULL,
			rule_id BIGINT UNSIGNED NOT NULL,
			changes TEXT NULL,
			accepted TINYINT(1) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
			',
			// Today step (FR3-12).
			'today_steps' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			step_date DATE NOT NULL,
			action_type VARCHAR(16) NOT NULL,
			ref_id BIGINT UNSIGNED NULL,
			rule_id BIGINT UNSIGNED NULL,
			status VARCHAR(8) NOT NULL DEFAULT "shown",
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_step (member_id, step_date)
			',
			// Insight shown (FR3-17): the numbers used are sealed.
			'insights' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			rule_id BIGINT UNSIGNED NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL,
			values_ct TEXT NULL,
			shown_at DATETIME NOT NULL,
			dismissed TINYINT(1) NOT NULL DEFAULT 0,
			source VARCHAR(8) NOT NULL DEFAULT "engine",
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_shown (member_id, shown_at)
			',
			// Food (FR3-26, NFR3-15): public reference data; member_id only for member-added packaged foods.
			'foods' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(16) NOT NULL,
			source_ref VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			brand VARCHAR(190) NULL,
			barcode VARCHAR(32) NULL,
			portions TEXT NULL,
			kcal_100g DECIMAL(6,1) NULL,
			protein_100g DECIMAL(6,2) NULL,
			carb_100g DECIMAL(6,2) NULL,
			sugar_100g DECIMAL(6,2) NULL,
			fat_100g DECIMAL(6,2) NULL,
			satfat_100g DECIMAL(6,2) NULL,
			fibre_100g DECIMAL(6,2) NULL,
			salt_100g DECIMAL(6,3) NULL,
			alcohol_100g DECIMAL(6,2) NULL,
			search_names TEXT NULL,
			attribution VARCHAR(255) NULL,
			member_id BIGINT UNSIGNED NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_source (source, source_ref),
			KEY barcode (barcode),
			KEY name (name(64))
			',
			// Custom food (FR3-30).
			'custom_foods' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			portion_g DECIMAL(7,1) NOT NULL,
			kcal DECIMAL(7,1) NULL,
			protein_g DECIMAL(6,2) NULL,
			carb_g DECIMAL(6,2) NULL,
			fat_g DECIMAL(6,2) NULL,
			fibre_g DECIMAL(6,2) NULL,
			barcode VARCHAR(32) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_updated (member_id, updated_at)
			',
			// Food log entry (FR3-27 to FR3-33): nutrition saved so past days never change.
			'food_log' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			entry_date DATE NOT NULL,
			meal VARCHAR(16) NOT NULL,
			food_id BIGINT UNSIGNED NOT NULL,
			food_kind VARCHAR(8) NOT NULL,
			grams DECIMAL(7,1) NOT NULL,
			portion_label VARCHAR(64) NULL,
			kcal DECIMAL(7,1) NULL,
			protein_g DECIMAL(6,2) NULL,
			carb_g DECIMAL(6,2) NULL,
			fat_g DECIMAL(6,2) NULL,
			fibre_g DECIMAL(6,2) NULL,
			source VARCHAR(16) NOT NULL DEFAULT "search",
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_day (member_id, entry_date),
			KEY member_updated (member_id, updated_at)
			',
			// Favourite food (FR3-31).
			'food_favourites' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			food_id BIGINT UNSIGNED NOT NULL,
			food_kind VARCHAR(8) NOT NULL DEFAULT "food",
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_favourite (member_id, food_kind, food_id)
			',
			// Nutrition target (FR3-28, FR3-29).
			'nutrition_targets' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			kcal SMALLINT UNSIGNED NULL,
			protein_g SMALLINT UNSIGNED NULL,
			carb_g SMALLINT UNSIGNED NULL,
			fat_g SMALLINT UNSIGNED NULL,
			fibre_g SMALLINT UNSIGNED NULL,
			set_by VARCHAR(16) NOT NULL DEFAULT "plan",
			valid_from DATE NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_from (member_id, valid_from)
			',
			// Recipe a member built (FR3-35).
			'recipes' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			servings SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			ingredients TEXT NOT NULL,
			kcal_per_serving DECIMAL(7,1) NULL,
			protein_per_serving DECIMAL(6,2) NULL,
			carb_per_serving DECIMAL(6,2) NULL,
			fat_per_serving DECIMAL(6,2) NULL,
			fibre_per_serving DECIMAL(6,2) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_updated (member_id, updated_at)
			',
			// Allergies and dislikes for recipe suggestions (FR3-36).
			'food_preferences' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			allergies TEXT NULL,
			dislikes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_member (member_id)
			',
			// Health reading (FR3-38 to FR3-46): every value sealed.
			'readings' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			reading_type VARCHAR(16) NOT NULL,
			taken_at DATETIME NOT NULL,
			value_1_ct TEXT NULL,
			value_2_ct TEXT NULL,
			value_3_ct TEXT NULL,
			detail_ct TEXT NULL,
			unit_shown VARCHAR(8) NULL,
			urgent_shown TINYINT(1) NOT NULL DEFAULT 0,
			source VARCHAR(16) NOT NULL DEFAULT "manual",
			external_ref VARCHAR(128) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_type_time (member_id, reading_type, taken_at),
			KEY member_updated (member_id, updated_at),
			UNIQUE KEY one_external (member_id, source, external_ref)
			',
			// Reminder (FR3-56).
			'reminders' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			reminder_type VARCHAR(16) NOT NULL,
			channel VARCHAR(8) NOT NULL DEFAULT "email",
			time_local TIME NOT NULL,
			days VARCHAR(32) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
			',
			// Shared summary (FR3-57).
			'shares' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(64) NOT NULL,
			include_health TINYINT(1) NOT NULL DEFAULT 0,
			content TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token (token),
			KEY member_id (member_id)
			',
			// Subscription pause (FR3-65).
			'pauses' => '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			subscription_ref VARCHAR(190) NOT NULL,
			months TINYINT UNSIGNED NOT NULL,
			starts_at DATE NULL,
			ends_at DATE NULL,
			status VARCHAR(16) NOT NULL DEFAULT "scheduled",
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
			',
		);
	}

	/**
	 * Create every table if it is missing.
	 */
	public static function create_all() {
		foreach ( self::definitions() as $suffix => $body ) {
			self::create( $suffix, $body );
		}
	}

	/**
	 * One table's column and key definitions, for the package that owns it.
	 *
	 * @param string $suffix Table suffix from suffixes().
	 */
	public static function definition( $suffix ) {
		$all = self::definitions();
		return isset( $all[ $suffix ] ) ? $all[ $suffix ] : '';
	}

	/**
	 * Columns and keys added after a table first shipped. Only ever ADD COLUMN or ADD KEY.
	 */
	private static function upgrade() {
		// Version 2 (P3-40): the food search key. Later packages add ensure_column() and ensure_key() lines
		// here and leave every Stage 2 statement as it is.
		self::ensure_key( 'foods', 'food_search', 'FULLTEXT KEY food_search (name, search_names)' );
		// Version 4 (P3-5B): Stage 3 calculators keep the answers, sealed, on the Stage 2 saved-result row (D3-33).
		// Stage 1 tools leave this column empty (D51).
		self::ensure_column( 'calculator_result', 'inputs_ct', 'TEXT NULL' );
	}

	/**
	 * @param string $suffix Table suffix.
	 * @param string $body   Column definitions.
	 */
	private static function create( $suffix, $body ) {
		global $wpdb;
		$table   = self::table( $suffix );
		$charset = $wpdb->get_charset_collate();
		$sql     = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . $body . ') ' . $charset;
		$ok      = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL, no user input.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not create ' . $table . ': ' . $wpdb->last_error );
		}
	}

	/**
	 * Add a column if it is missing. Never changes or drops one.
	 *
	 * @param string $suffix     Table suffix.
	 * @param string $column     Column name from this class.
	 * @param string $definition Column definition from this class.
	 */
	public static function ensure_column( $suffix, $column, $definition ) {
		global $wpdb;
		$table = self::table( $suffix );
		$names = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		if ( in_array( $column, $names, true ) ) {
			return;
		}
		$ok = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL from this class.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not add ' . $column . ' on ' . $table . ': ' . $wpdb->last_error );
		}
	}

	/**
	 * Add a key if it is missing. Never changes or drops one.
	 *
	 * @param string $suffix Table suffix.
	 * @param string $name   Key name.
	 * @param string $ddl    Key clause, for example "KEY barcode (barcode)".
	 */
	public static function ensure_key( $suffix, $name, $ddl ) {
		global $wpdb;
		$table = self::table( $suffix );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		if ( $rows ) {
			return;
		}
		$ok = $wpdb->query( "ALTER TABLE {$table} ADD {$ddl}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL from this class.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not add key ' . $name . ' on ' . $table . ': ' . $wpdb->last_error );
		}
	}
}
