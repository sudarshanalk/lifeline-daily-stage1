<?php
/**
 * Stage 3 erasure: the Stage 2 routine (LLD_Members_Erasure) fires hooks; this class removes the Stage 3
 * rows and tells the Stage 2 checker about the Stage 3 tables (data model: "one erasure routine").
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into account deletion, health-consent withdrawal, the erasure checker and the daily sweep.
 */
class LLD_Members_Stage3_Erasure {

	public static function boot() {
		add_action( 'lld_members_erase_account', array( __CLASS__, 'erase_account' ) );
		add_action( 'lld_members_withdraw_health', array( __CLASS__, 'withdraw_health' ) );
		add_filter( 'lld_members_known_tables', array( __CLASS__, 'known_tables' ) );
		add_filter( 'lld_members_member_links', array( __CLASS__, 'member_links' ) );
		add_filter( 'lld_members_health_tables', array( __CLASS__, 'health_tables' ) );
		add_action( 'lld_members_daily', array( 'LLD_Members_Stage3_Data', 'sweep_deleted' ) );
		add_action( 'lld_members_daily', array( __CLASS__, 'sweep_notes_shown' ) );
	}

	/**
	 * Account deletion: every Stage 3 row of the member goes, except pauses, which are payment records
	 * and keep only the member number blanked (data model: Subscription pause).
	 *
	 * @param int $member_id Member number.
	 */
	public static function erase_account( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return;
		}
		$member = $wpdb->get_row( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		foreach ( LLD_Members_Stage3_Schema::suffixes() as $suffix ) {
			// The festival calendar is content with no member column (P3-35).
			if ( 'pauses' === $suffix || 'email_codes' === $suffix || 'goal_baselines' === $suffix || 'admin_devices' === $suffix || 'festival_calendar' === $suffix ) {
				continue;
			}
			LLD_Members_Stage3_Data::purge( $suffix, $member_id );
		}
		self::delete_orphan_baselines();
		$pauses = LLD_Members_Stage3_Schema::table( 'pauses' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$pauses} SET member_id = 0 WHERE member_id = %d", $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $member && is_string( $member->email ) && '' !== $member->email ) {
			$wpdb->delete( LLD_Members_Stage3_Schema::table( 'email_codes' ), array( 'email' => $member->email ), array( '%s' ) );
		}
	}

	/**
	 * Health-consent withdrawal: readings, food log, goals' values, plans, insights, targets and the Healthy Weight
	 * answers, milestones, block choices and check-in barriers go (P3-35).
	 *
	 * @param int $member_id Member number.
	 */
	public static function withdraw_health( $member_id ) {
		foreach ( LLD_Members_Stage3_Schema::health_suffixes() as $suffix ) {
			if ( 'goal_baselines' === $suffix ) {
				continue;
			}
			LLD_Members_Stage3_Data::purge( $suffix, (int) $member_id );
		}
		self::delete_orphan_baselines();
		self::forget_note_reasons( (int) $member_id );
	}

	/**
	 * The notes shown stay (personal data, kept 13 months), but which rule chose a note can tell a health answer
	 * (low mood, sleep, stress, weight), so on health-consent withdrawal it goes: rule_id and reason become empty.
	 *
	 * @param int $member_id Member number.
	 */
	public static function forget_note_reasons( $member_id ) {
		global $wpdb;
		if ( (int) $member_id < 1 ) {
			return 0;
		}
		$n = $wpdb->update(
			LLD_Members_Stage3_Schema::table( 'notes_shown' ),
			array(
				'rule_id' => null,
				'reason'  => null,
			),
			array( 'member_id' => (int) $member_id )
		);
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Mindset notes shown are kept for 13 months (data model: Mindset note shown, P3-35).
	 */
	public static function sweep_notes_shown() {
		global $wpdb;
		$table  = LLD_Members_Stage3_Schema::table( 'notes_shown' );
		$cutoff = gmdate( 'Y-m-d', strtotime( '-13 months', time() ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE shown_on < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
	}

	/**
	 * Baselines point at goals; when the goals go, so do they.
	 */
	private static function delete_orphan_baselines() {
		global $wpdb;
		$baselines = LLD_Members_Stage3_Schema::table( 'goal_baselines' );
		$goals     = LLD_Members_Stage3_Schema::table( 'goals' );
		$wpdb->query( "DELETE b FROM {$baselines} b LEFT JOIN {$goals} g ON g.id = b.goal_id WHERE g.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
	}

	/**
	 * @param string[] $known Full table names the Stage 2 checker knows.
	 * @return string[]
	 */
	public static function known_tables( $known ) {
		foreach ( LLD_Members_Stage3_Schema::suffixes() as $suffix ) {
			$known[] = LLD_Members_Stage3_Schema::table( $suffix );
		}
		return $known;
	}

	/**
	 * @param array<string,string[]> $links Stage 2 links.
	 * @return array<string,string[]>
	 */
	public static function member_links( $links ) {
		return array_merge( $links, LLD_Members_Stage3_Schema::member_links() );
	}

	/**
	 * @param string[] $tables Stage 2 health tables.
	 * @return string[]
	 */
	public static function health_tables( $tables ) {
		return array_merge( $tables, LLD_Members_Stage3_Schema::health_suffixes() );
	}
}
