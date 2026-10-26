<?php
/**
 * WP-CLI commands. `wp lld-members db-update` is the contract with deploy and CI.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * lld-members commands.
 */
class LLD_Members_CLI {

	/**
	 * Register both the hyphenated names CI calls and the underscore names.
	 */
	public static function register() {
		$commands = array(
			'db-update'       => 'db_update',
			'db_update'       => 'db_update',
			'maintenance'     => 'maintenance',
			'erasure-email'   => 'erasure_email',
			'erasure_email'   => 'erasure_email',
			'erasure-reapply' => 'erasure_reapply',
			'erasure_reapply' => 'erasure_reapply',
			'clock'           => 'clock',
		);
		foreach ( $commands as $name => $method ) {
			WP_CLI::add_command( 'lld-members ' . $name, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Create or upgrade every Lifeline Daily table. Safe to run any number of times.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lld-members db-update
	 *
	 * @param string[] $args Positional args.
	 * @param array    $assoc Assoc args.
	 */
	public function db_update( $args, $assoc ) {
		unset( $args, $assoc );
		try {
			$result = LLD_Members_Schema::update();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( $result['changed'] ) {
			WP_CLI::success( 'Schema updated to version ' . $result['version'] );
		} else {
			WP_CLI::success( 'Schema already at version ' . $result['version'] );
		}
	}

	/**
	 * Close or reopen the site to members while erasures are re-applied.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off
	 *
	 * @param string[] $args Positional args.
	 */
	public function maintenance( $args ) {
		$state = isset( $args[0] ) ? $args[0] : '';
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			WP_CLI::error( 'Use: wp lld-members maintenance on|off' );
		}
		if ( 'on' === $state ) {
			update_option( 'lld_members_closed', '1', false );
		} else {
			delete_option( 'lld_members_closed' );
		}
		WP_CLI::success( 'Maintenance ' . $state );
	}

	/**
	 * Email new erasure lines to the owner.
	 */
	/**
	 * Freeze one member's test clock.
	 *
	 * ## OPTIONS
	 *
	 * <member>
	 * : Member number or email.
	 *
	 * <utc>
	 * : UTC time, as YYYY-MM-DD HH:MM:SS.
	 *
	 * @param string[] $args Positional args.
	 */
	public function clock( $args ) {
		if ( isset( $args[0] ) && 'set' === $args[0] ) {
			array_shift( $args );
		}
		$who = isset( $args[0] ) ? (string) $args[0] : '';
		$utc = isset( $args[1] ) ? (string) $args[1] : '';
		if ( isset( $args[2] ) ) {
			$utc .= ' ' . $args[2];
		}
		if ( '' === $who || '' === trim( $utc ) ) {
			WP_CLI::error( 'Use: wp lld-members clock set <member> <utc>' );
		}
		global $wpdb;
		if ( is_email( $who ) ) {
			$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $who ) );
		} else {
			$member_id = (int) $who;
		}
		$result = LLD_Members_Clock::set( $member_id, trim( $utc ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'Clock set for member ' . $member_id );
	}

	public function erasure_email() {
		$count = LLD_Members_Erasure::email_new_lines();
		WP_CLI::success( 'Emailed ' . $count . ' erasure lines' );
	}

	/**
	 * Re-apply every erasure line. Run this while maintenance is on, after a restore.
	 */
	/**
	 * Re-apply erasures from the restored database, or from an emailed list, then verify.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<file>]
	 * : Mailbox copy of the erasure list. Lines dated after the backup are imported, then re-applied.
	 *
	 * [--since=<utc>]
	 * : When the backup was taken, as YYYY-MM-DD HH:MM:SS UTC. Without it, the latest line in the
	 * restored database marks the backup (T6). Earlier lines were applied before the backup.
	 *
	 * @param string[] $args  Positional args.
	 * @param array    $assoc Assoc args.
	 */
	public function erasure_reapply( $args, $assoc ) {
		unset( $args );
		$from  = isset( $assoc['from'] ) ? (string) $assoc['from'] : '';
		$since = isset( $assoc['since'] ) ? (string) $assoc['since'] : LLD_Members_Erasure::restore_floor();
		WP_CLI::log( 'Re-applying erasure lines dated after ' . $since . ' UTC' );
		if ( '' !== $from ) {
			$imported = LLD_Members_Erasure::import_file( $from );
			WP_CLI::log( 'Imported ' . $imported . ' erasure lines' );
		}
		$count    = LLD_Members_Erasure::reapply_all( $since );
		$problems = LLD_Members_Erasure::verify_stored( $since );
		if ( $problems ) {
			foreach ( $problems as $problem ) {
				WP_CLI::warning( $problem );
			}
			WP_CLI::error( 'Erasure verify failed. Leave maintenance on.' );
		}
		WP_CLI::success( 'Re-applied ' . $count . ' erasure lines. Verify found no erased data.' );
	}
}
