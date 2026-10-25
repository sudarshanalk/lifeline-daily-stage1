<?php
/**
 * Database schema. Updates only add tables. Later versions may only add or widen columns.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the Stage 2 tables for areas A, C, P, E and F.
 */
class LLD_Members_Schema {

	const VERSION = 1;

	/**
	 * Full table name for a suffix such as "member".
	 *
	 * @param string $suffix Suffix after lld_.
	 */
	public static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'lld_' . $suffix;
	}

	/**
	 * Create any missing tables and move the schema version forward.
	 *
	 * Safe to run any number of times. Exits 0 from WP-CLI when already up to date.
	 *
	 * @return array{changed:bool,version:int}
	 */
	public static function update() {
		$before = (int) get_option( 'lld_members_schema_version', 0 );
		self::create_all();
		update_option( 'lld_members_schema_version', self::VERSION, true );
		return array(
			'changed' => $before < self::VERSION,
			'version' => self::VERSION,
		);
	}

	/**
	 * Area C tables removed when health consent is withdrawn.
	 *
	 * @return string[]
	 */
	public static function health_tables() {
		return array(
			'goal_choice',
			'something_else',
			'health_profile',
			'calculator_result',
			'enrolment',
			'challenge',
			'workout',
			'result',
			'check_in',
			'reflection',
			'weekly_plan',
			'weekly_review',
			'diary_meal',
			'diary_day',
			'meal_plan',
			'suggestion',
			'badge',
		);
	}

	/**
	 * Every plugin table, so the erasure checker can compare the live database later.
	 *
	 * @return string[]
	 */
	public static function all_suffixes() {
		return array(
			'member',
			'consent',
			'email_pref',
			'unsubscribe_code',
			'security_code',
			'sign_in_lock',
			'session',
			'app_key',
			'goal_choice',
			'something_else',
			'health_profile',
			'calculator_result',
			'enrolment',
			'enrolment_period',
			'challenge',
			'workout',
			'workout_change',
			'result',
			'check_in',
			'reflection',
			'weekly_plan',
			'weekly_review',
			'diary_meal',
			'diary_day',
			'meal_plan',
			'suggestion',
			'badge',
			'subscription',
			'order',
			'referral',
			'reward',
			'stripe_notice',
			'contact',
			'contact_consent',
			'contact_request',
			'erasure',
			'admin_log',
			'support',
			'survey',
			'theme_count',
			'weekly_report',
			'fb_removal',
			'error_log',
			'test_clock',
		);
	}

	/**
	 * Create every table if it is missing. Never drops or narrows a column.
	 */
	public static function create_all() {
		self::create( 'member', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL,
			first_name VARCHAR(100) NOT NULL DEFAULT "",
			last_name VARCHAR(100) NOT NULL DEFAULT "",
			display_name VARCHAR(100) NULL,
			photo_path VARCHAR(255) NULL,
			age SMALLINT UNSIGNED NOT NULL,
			age_entered_on DATE NOT NULL,
			timezone VARCHAR(64) NOT NULL DEFAULT "UTC",
			units VARCHAR(16) NOT NULL DEFAULT "metric",
			role VARCHAR(32) NOT NULL DEFAULT "member",
			arrived_from VARCHAR(190) NULL,
			email_confirmed_at DATETIME NULL,
			joined_on DATE NOT NULL,
			last_signed_in_at DATETIME NULL,
			two_step TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY email (email)
		' );
		self::create( 'consent', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			consent_type VARCHAR(32) NOT NULL,
			change_kind VARCHAR(16) NOT NULL,
			document_version VARCHAR(64) NOT NULL DEFAULT "",
			occurred_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_type (member_id, consent_type, id)
		' );
		self::create( 'email_pref', '
			member_id BIGINT UNSIGNED NOT NULL,
			welcome TINYINT(1) NOT NULL DEFAULT 0,
			daily_reminder TINYINT(1) NOT NULL DEFAULT 0,
			reminder_time TIME NULL,
			weekly_review TINYINT(1) NOT NULL DEFAULT 0,
			end_of_track TINYINT(1) NOT NULL DEFAULT 0,
			nudges TINYINT(1) NOT NULL DEFAULT 0,
			nudges_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_nudge_on DATE NULL,
			PRIMARY KEY (member_id)
		' );
		self::create( 'unsubscribe_code', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			purpose VARCHAR(32) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'security_code', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(32) NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			new_email VARCHAR(190) NULL,
			pending_token_hash CHAR(64) NULL,
			remember TINYINT(1) NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY pending_token_hash (pending_token_hash),
			KEY member_kind (member_id, kind)
		' );
		self::create( 'sign_in_lock', '
			email VARCHAR(190) NOT NULL,
			fail_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			lock_until DATETIME NULL,
			PRIMARY KEY (email)
		' );
		self::create( 'session', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			remember TINYINT(1) NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			ended_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'app_key', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			key_hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			withdrawn_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'goal_choice', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			goal_key VARCHAR(64) NOT NULL,
			sort_order TINYINT UNSIGNED NOT NULL,
			chosen_on DATE NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY member_goal (member_id, goal_key),
			UNIQUE KEY member_order (member_id, sort_order)
		' );
		self::create( 'something_else', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			written_at DATETIME NOT NULL,
			text_ciphertext TEXT NOT NULL,
			theme VARCHAR(190) NULL,
			told_when_live DATE NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'health_profile', '
			member_id BIGINT UNSIGNED NOT NULL,
			sex VARCHAR(32) NULL,
			height_cm DECIMAL(5,1) NULL,
			weight_kg DECIMAL(5,1) NULL,
			waist_cm DECIMAL(5,1) NULL,
			activity_level VARCHAR(32) NULL,
			diet_type VARCHAR(32) NULL,
			ethnicity VARCHAR(64) NULL,
			conditions_answered VARCHAR(32) NOT NULL DEFAULT "not_answered",
			conditions TEXT NULL,
			updated_on DATE NULL,
			reminder_sent_on DATE NULL,
			PRIMARY KEY (member_id)
		' );
		self::create( 'calculator_result', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			calculator VARCHAR(32) NOT NULL,
			saved_at DATETIME NOT NULL,
			headline VARCHAR(64) NOT NULL,
			unit VARCHAR(64) NOT NULL,
			band VARCHAR(64) NOT NULL,
			calculator_version VARCHAR(64) NOT NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'enrolment', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			track_key VARCHAR(64) NOT NULL,
			attempt INT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(32) NOT NULL,
			free_week_used TINYINT(1) NOT NULL DEFAULT 0,
			last_open_day DATE NULL,
			finished_on DATE NULL,
			PRIMARY KEY (id),
			KEY member_status (member_id, status)
		' );
		self::create( 'enrolment_period', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			enrolment_id BIGINT UNSIGNED NOT NULL,
			first_on DATE NOT NULL,
			last_on DATE NULL,
			PRIMARY KEY (id),
			KEY enrolment_id (enrolment_id)
		' );
		self::create( 'challenge', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			challenge_version VARCHAR(64) NOT NULL,
			joined_on DATE NOT NULL,
			finished_on DATE NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'workout', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			level VARCHAR(32) NOT NULL,
			version_key VARCHAR(64) NOT NULL,
			started_on DATE NOT NULL,
			current_week SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			KEY member_active (member_id, active)
		' );
		self::create( 'workout_change', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			workout_id BIGINT UNSIGNED NOT NULL,
			level VARCHAR(32) NOT NULL,
			changed_on DATE NOT NULL,
			PRIMARY KEY (id),
			KEY workout_id (workout_id)
		' );
		self::create( 'result', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			item_version VARCHAR(190) NOT NULL,
			context_type VARCHAR(32) NOT NULL,
			context_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			programme_day INT UNSIGNED NOT NULL,
			local_date DATE NOT NULL,
			result VARCHAR(16) NOT NULL,
			recorded_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_result (member_id, item_version, context_type, context_id, programme_day)
		' );
		self::create( 'check_in', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			enrolment_id BIGINT UNSIGNED NULL,
			week_number INT UNSIGNED NULL,
			month_key CHAR(7) NULL,
			local_date DATE NOT NULL,
			measures TEXT NULL,
			weight_kg DECIMAL(5,1) NULL,
			waist_cm DECIMAL(5,1) NULL,
			activity_minutes INT UNSIGNED NULL,
			chair_stands INT UNSIGNED NULL,
			push_ups INT UNSIGNED NULL,
			strength_sessions INT UNSIGNED NULL,
			sleep_hours DECIMAL(3,1) NULL,
			sleep_quality TINYINT UNSIGNED NULL,
			stress_level TINYINT UNSIGNED NULL,
			tests_skipped VARCHAR(255) NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'reflection', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			enrolment_id BIGINT UNSIGNED NOT NULL,
			when_key VARCHAR(16) NOT NULL,
			answers TEXT NOT NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'weekly_plan', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			enrolment_id BIGINT UNSIGNED NOT NULL,
			week_number INT UNSIGNED NOT NULL,
			actions TEXT NOT NULL,
			level VARCHAR(16) NOT NULL,
			completion_done INT UNSIGNED NOT NULL DEFAULT 0,
			completion_available INT UNSIGNED NOT NULL DEFAULT 0,
			completion_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			rule_version VARCHAR(16) NOT NULL DEFAULT "",
			swapped TINYINT(1) NOT NULL DEFAULT 0,
			created_on DATE NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_plan (enrolment_id, week_number)
		' );
		self::create( 'weekly_review', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			enrolment_id BIGINT UNSIGNED NOT NULL,
			week_number INT UNSIGNED NOT NULL,
			ready_on DATE NOT NULL,
			next_steps TEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_review (enrolment_id, week_number)
		' );
		self::create( 'diary_meal', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			local_date DATE NOT NULL,
			meal VARCHAR(16) NOT NULL,
			entry_type VARCHAR(32) NOT NULL,
			recipe_version VARCHAR(190) NULL,
			description_ciphertext TEXT NULL,
			photo_path VARCHAR(255) NULL,
			vegetables TINYINT(1) NULL,
			protein_source TINYINT(1) NULL,
			sugary_drink TINYINT(1) NULL,
			`portion` VARCHAR(16) NULL,
			recorded_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_date (member_id, local_date)
		' );
		self::create( 'diary_day', '
			member_id BIGINT UNSIGNED NOT NULL,
			local_date DATE NOT NULL,
			water_glasses INT UNSIGNED NULL,
			hunger TINYINT UNSIGNED NULL,
			energy TINYINT UNSIGNED NULL,
			PRIMARY KEY (member_id, local_date)
		' );
		self::create( 'meal_plan', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			week_starting DATE NOT NULL,
			template_version VARCHAR(190) NOT NULL,
			swaps TEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_week (member_id, week_starting)
		' );
		self::create( 'suggestion', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			item_key VARCHAR(64) NOT NULL,
			last_opened_on DATE NULL,
			hidden_on DATE NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'badge', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			badge_key VARCHAR(64) NOT NULL,
			challenge_key VARCHAR(64) NOT NULL DEFAULT "",
			earned_on DATE NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_badge (member_id, badge_key, challenge_key)
		' );
		self::create( 'subscription', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			plan VARCHAR(32) NOT NULL,
			access_state VARCHAR(40) NOT NULL,
			stripe_state VARCHAR(32) NOT NULL DEFAULT "",
			started_on DATE NULL,
			period_ends_on DATE NULL,
			locked_amount BIGINT NULL,
			locked_currency CHAR(3) NULL,
			pending_yearly_on DATE NULL,
			premium_ended_on DATE NULL,
			premium_ended_why VARCHAR(32) NULL,
			renewal_reminder_on DATE NULL,
			stripe_customer VARCHAR(64) NULL,
			stripe_subscription VARCHAR(64) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY member_id (member_id)
		' );
		self::create( 'order', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NULL,
			ordered_at DATETIME NOT NULL,
			kind VARCHAR(32) NOT NULL,
			related_order_id BIGINT UNSIGNED NULL,
			catalogue_amount BIGINT NULL,
			catalogue_currency CHAR(3) NULL,
			charged_amount BIGINT NULL,
			charged_currency CHAR(3) NULL,
			tax_amount BIGINT NULL,
			tax_currency CHAR(3) NULL,
			discount_code VARCHAR(64) NULL,
			discount_amount BIGINT NULL,
			discount_currency CHAR(3) NULL,
			status VARCHAR(32) NOT NULL,
			stripe_payment VARCHAR(64) NULL,
			stripe_invoice VARCHAR(64) NULL,
			billing_name VARCHAR(190) NULL,
			billing_email VARCHAR(190) NULL,
			billing_country CHAR(2) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY stripe_payment (stripe_payment),
			KEY member_id (member_id)
		' );
		self::create( 'referral', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			referrer_member_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(32) NOT NULL,
			friend_member_id BIGINT UNSIGNED NOT NULL,
			friend_order_id BIGINT UNSIGNED NULL,
			status VARCHAR(32) NOT NULL,
			self_referral VARCHAR(16) NULL,
			PRIMARY KEY (id),
			KEY referrer_member_id (referrer_member_id),
			KEY friend_member_id (friend_member_id)
		' );
		self::create( 'reward', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			referral_id BIGINT UNSIGNED NOT NULL,
			recipient_member_id BIGINT UNSIGNED NOT NULL,
			form VARCHAR(32) NOT NULL,
			operation_key VARCHAR(64) NOT NULL,
			status VARCHAR(32) NOT NULL,
			stripe_discount VARCHAR(64) NULL,
			used_on_order_id BIGINT UNSIGNED NULL,
			withdrawn_on DATETIME NULL,
			withdrawn_why VARCHAR(190) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY operation_key (operation_key),
			KEY recipient_member_id (recipient_member_id)
		' );
		self::create( 'stripe_notice', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notice_ref VARCHAR(190) NOT NULL,
			notice_type VARCHAR(64) NOT NULL,
			received_at DATETIME NOT NULL,
			processing_state VARCHAR(32) NOT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY notice_ref (notice_ref)
		' );
		self::create( 'contact', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			first_name VARCHAR(100) NULL,
			source VARCHAR(190) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY email (email)
		' );
		self::create( 'contact_consent', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(32) NOT NULL,
			occurred_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY contact_id (contact_id)
		' );
		self::create( 'contact_request', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(16) NOT NULL,
			track_key VARCHAR(64) NOT NULL,
			requested_on DATE NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY one_request (contact_id, kind, track_key)
		' );
		self::create( 'erasure', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_number BIGINT UNSIGNED NOT NULL,
			email_hash CHAR(64) NOT NULL,
			what_erased VARCHAR(32) NOT NULL,
			erased_at DATETIME NOT NULL,
			emailed_on DATETIME NULL,
			PRIMARY KEY (id),
			KEY emailed_on (emailed_on)
		' );
		self::create( 'admin_log', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action VARCHAR(64) NOT NULL,
			member_number BIGINT UNSIGNED NULL,
			member_name VARCHAR(190) NULL,
			member_email VARCHAR(190) NULL,
			detail TEXT NULL,
			money_related TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_number (member_number)
		' );
		self::create( 'support', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NULL,
			person_name VARCHAR(190) NOT NULL,
			email VARCHAR(190) NOT NULL,
			message TEXT NOT NULL,
			plan VARCHAR(32) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'survey', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			week_number TINYINT UNSIGNED NOT NULL,
			answers TEXT NULL,
			what_changed TEXT NULL,
			quote_text TEXT NULL,
			permission TINYINT(1) NOT NULL DEFAULT 0,
			approved_on DATE NULL,
			display_name VARCHAR(100) NULL,
			country CHAR(2) NULL,
			PRIMARY KEY (id),
			KEY member_id (member_id)
		' );
		self::create( 'theme_count', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			theme VARCHAR(190) NOT NULL,
			item_count INT UNSIGNED NOT NULL DEFAULT 0,
			period_key CHAR(7) NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY theme_period (theme, period_key)
		' );
		self::create( 'weekly_report', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			week_starting DATE NOT NULL,
			totals TEXT NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY week_starting (week_starting)
		' );
		self::create( 'fb_removal', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_number BIGINT UNSIGNED NOT NULL,
			premium_ended_on DATE NOT NULL,
			removed_on DATE NULL,
			late TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY member_number (member_number)
		' );
		self::create( 'error_log', '
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message VARCHAR(500) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id)
		' );
		self::create( 'test_clock', '
			member_id BIGINT UNSIGNED NOT NULL,
			frozen_at DATETIME NOT NULL,
			PRIMARY KEY (member_id)
		' );
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
}
