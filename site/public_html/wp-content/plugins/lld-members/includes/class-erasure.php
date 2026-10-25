<?php
/**
 * One erasure routine (Data Model S6, S7, T6).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes a member from every table and file store, keeping only the S7 exceptions.
 */
class LLD_Members_Erasure {

	/**
	 * Delete an account. Tax fields, the erasure line and anonymised money logs remain.
	 *
	 * @param int  $member_id Member number.
	 * @param bool $record    Write an erasure line. False when re-applying one that already exists.
	 */
	public static function delete_account( $member_id, $record = true ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$member    = self::member( $member_id );
		if ( $record && $member ) {
			self::write_line( $member_id, (string) $member->email, 'account' );
		}
		self::withdraw_health( $member_id, false );
		LLD_Members_Photos::delete_member( $member_id, 'all' );

		$order = LLD_Members_Schema::table( 'order' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$order} SET member_id = NULL WHERE member_id = %d", $member_id ) );

		self::delete_where( 'referral', 'referrer_member_id', $member_id );
		self::delete_where( 'referral', 'friend_member_id', $member_id );
		self::delete_where( 'reward', 'recipient_member_id', $member_id );
		self::delete_where( 'support', 'member_id', $member_id );
		self::delete_where( 'survey', 'member_id', $member_id );
		self::delete_where( 'fb_removal', 'member_number', $member_id );
		self::delete_where( 'subscription', 'member_id', $member_id );
		self::delete_where( 'email_pref', 'member_id', $member_id );
		self::delete_where( 'unsubscribe_code', 'member_id', $member_id );
		self::delete_where( 'security_code', 'member_id', $member_id );
		self::delete_where( 'session', 'member_id', $member_id );
		self::delete_where( 'app_key', 'member_id', $member_id );
		self::delete_where( 'consent', 'member_id', $member_id );
		self::delete_where( 'test_clock', 'member_id', $member_id );

		$log = LLD_Members_Schema::table( 'admin_log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE member_number = %d AND money_related = 0", $member_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$log} SET member_name = NULL, member_email = NULL WHERE member_number = %d AND money_related = 1", $member_id ) );

		if ( $member ) {
			$wpdb->delete( LLD_Members_Schema::table( 'sign_in_lock' ), array( 'email' => $member->email ), array( '%s' ) );
			$contact = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'contact' ) . ' WHERE email = %s', $member->email ) );
			if ( $contact ) {
				self::delete_where( 'contact_consent', 'contact_id', (int) $contact );
				self::delete_where( 'contact_request', 'contact_id', (int) $contact );
				$wpdb->delete( LLD_Members_Schema::table( 'contact' ), array( 'id' => (int) $contact ), array( '%d' ) );
			}
			$user_id = (int) $member->user_id;
			$wpdb->delete( LLD_Members_Schema::table( 'member' ), array( 'id' => $member_id ), array( '%d' ) );
			if ( $user_id && get_userdata( $user_id ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}
		}
		self::delete_periods_for_missing_enrolments();
	}

	/**
	 * Health-consent withdrawal: Area C, survey "what changed" answers, and diary photos.
	 *
	 * @param int  $member_id Member number.
	 * @param bool $record    Write an erasure line and a consent-history line.
	 */
	public static function withdraw_health( $member_id, $record = true ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$member    = self::member( $member_id );
		if ( $record && $member ) {
			self::write_line( $member_id, (string) $member->email, 'health' );
			$wpdb->insert(
				LLD_Members_Schema::table( 'consent' ),
				array(
					'member_id'        => $member_id,
					'consent_type'     => 'health',
					'change_kind'      => 'withdrawn',
					'document_version' => '',
					'occurred_at'      => lld_members_now_mysql( $member_id ),
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
		foreach ( LLD_Members_Schema::health_tables() as $suffix ) {
			$column = 'diary_day' === $suffix || 'health_profile' === $suffix ? 'member_id' : 'member_id';
			self::delete_where( $suffix, $column, $member_id );
		}
		self::delete_periods_for_missing_enrolments();
		self::delete_workout_changes_for_missing_workouts();
		LLD_Members_Photos::delete_member( $member_id, 'diary' );
		$survey = LLD_Members_Schema::table( 'survey' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$survey} SET what_changed = NULL WHERE member_id = %d", $member_id ) );
	}

	/**
	 * Email today's new erasure lines to the owner. The message has no health details.
	 */
	public static function email_new_lines() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'erasure' );
		$rows  = $wpdb->get_results( "SELECT id, member_number, email_hash, what_erased, erased_at FROM {$table} WHERE emailed_on IS NULL ORDER BY id ASC" );
		if ( ! $rows ) {
			return 0;
		}
		$lines = array( 'Lifeline Daily erasure list' );
		$ids   = array();
		foreach ( $rows as $row ) {
			$lines[] = $row->member_number . ' ' . $row->what_erased . ' ' . $row->erased_at . ' ' . $row->email_hash;
			$ids[]   = (int) $row->id;
		}
		$sent = wp_mail( get_option( 'admin_email' ), 'Erasure list', implode( "\n", $lines ) );
		if ( ! $sent ) {
			return 0;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $ids as $id ) {
			$wpdb->update( $table, array( 'emailed_on' => $now ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		return count( $ids );
	}

	/**
	 * Import erasure lines from the owner's emailed list, then the caller re-applies them.
	 *
	 * @param string $path Path to the mailbox copy.
	 * @return int Number of new lines stored.
	 */
	public static function import_file( $path ) {
		global $wpdb;
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return 0;
		}
		$table = LLD_Members_Schema::table( 'erasure' );
		$added = 0;
		foreach ( preg_split( '/\r\n|\n|\r/', $raw ) as $line ) {
			if ( ! preg_match( '/^(\d+)\s+(account|health)\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+([a-f0-9]+)\s*$/', trim( $line ), $match ) ) {
				continue;
			}
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE member_number = %d AND what_erased = %s AND erased_at = %s",
					(int) $match[1],
					$match[2],
					$match[3]
				)
			);
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'member_number' => (int) $match[1],
					'email_hash'    => $match[4],
					'what_erased'   => $match[2],
					'erased_at'     => $match[3],
				),
				array( '%d', '%s', '%s', '%s' )
			);
			++$added;
		}
		return $added;
	}

	/**
	 * Confirm the stored erasure lines no longer have the data they removed.
	 *
	 * @return string[] Problems, empty when the restore is safe to reopen.
	 */
	public static function verify_stored() {
		global $wpdb;
		$table    = LLD_Members_Schema::table( 'erasure' );
		$rows     = $wpdb->get_results( "SELECT member_number, what_erased FROM {$table} ORDER BY id ASC" );
		$problems = array();
		foreach ( $rows as $row ) {
			$member_id = (int) $row->member_number;
			if ( 'account' === $row->what_erased ) {
				$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
				if ( $left ) {
					$problems[] = 'Member ' . $member_id . ' is still present after account erasure.';
				}
			}
			if ( 'health' === $row->what_erased || 'account' === $row->what_erased ) {
				foreach ( LLD_Members_Schema::health_tables() as $suffix ) {
					$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( $suffix ) . ' WHERE member_id = %d', $member_id ) );
					if ( $count > 0 ) {
						$problems[] = 'Member ' . $member_id . ' still has ' . $suffix . ' rows.';
					}
				}
			}
		}
		return $problems;
	}

	/**
	 * Run every stored erasure again. Used after a backup restore, before members return.
	 */
	public static function reapply_all() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'erasure' );
		$rows  = $wpdb->get_results( "SELECT member_number, what_erased FROM {$table} ORDER BY id ASC" );
		foreach ( $rows as $row ) {
			if ( 'health' === $row->what_erased ) {
				self::withdraw_health( (int) $row->member_number, false );
			} else {
				self::delete_account( (int) $row->member_number, false );
			}
		}
		return count( $rows );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $email     Plain email, stored only as a hash.
	 * @param string $what      account or health.
	 */
	private static function write_line( $member_id, $email, $what ) {
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'erasure' ),
			array(
				'member_number' => (int) $member_id,
				'email_hash'    => hash( 'sha256', strtolower( $email ) ),
				'what_erased'   => $what,
				'erased_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}

	/**
	 * @param string $suffix Table suffix.
	 * @param string $column Column name from this class, never from a request.
	 * @param int    $id     Id value.
	 */
	private static function delete_where( $suffix, $column, $id ) {
		global $wpdb;
		$allowed = array( 'member_id', 'referrer_member_id', 'friend_member_id', 'recipient_member_id', 'member_number', 'contact_id' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return;
		}
		$table = LLD_Members_Schema::table( $suffix );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column is an allowlist.
	}

	private static function delete_periods_for_missing_enrolments() {
		global $wpdb;
		$period    = LLD_Members_Schema::table( 'enrolment_period' );
		$enrolment = LLD_Members_Schema::table( 'enrolment' );
		$wpdb->query( "DELETE p FROM {$period} p LEFT JOIN {$enrolment} e ON e.id = p.enrolment_id WHERE e.id IS NULL" );
	}

	private static function delete_workout_changes_for_missing_workouts() {
		global $wpdb;
		$change  = LLD_Members_Schema::table( 'workout_change' );
		$workout = LLD_Members_Schema::table( 'workout' );
		$wpdb->query( "DELETE c FROM {$change} c LEFT JOIN {$workout} w ON w.id = c.workout_id WHERE w.id IS NULL" );
	}
}
