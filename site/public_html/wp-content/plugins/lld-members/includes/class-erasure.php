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
	 * Placeholder for a deleted friend on a referral. The friend column is unique, so each blanked
	 * referral gets its own value (this minus the referral number). It is never a real member number.
	 */
	const BLANK_FRIEND = PHP_INT_MAX;

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
		LLD_Members_Privacy::sweep_downloads( $member_id );

		$order = LLD_Members_Schema::table( 'order' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$order} SET member_id = NULL WHERE member_id = %d", $member_id ) );

		// Referrals (P5): this member's own rewards are lost. The other person's reward, and the
		// referral that counts towards their yearly limit, stay with this member's number blanked.
		$referral = LLD_Members_Schema::table( 'referral' );
		self::delete_where( 'reward', 'recipient_member_id', $member_id );
		$wpdb->query( $wpdb->prepare( "UPDATE {$referral} SET referrer_member_id = 0, code = '' WHERE referrer_member_id = %d", $member_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$referral} SET friend_member_id = %d - id WHERE friend_member_id = %d", self::BLANK_FRIEND, $member_id ) );
		do_action( 'lld_members_erase_account', $member_id );
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
				self::delete_pmpro( $user_id );
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
			self::add_withdrawn_line( $member_id );
		}
		foreach ( LLD_Members_Schema::health_tables() as $suffix ) {
			$column = 'diary_day' === $suffix || 'health_profile' === $suffix ? 'member_id' : 'member_id';
			self::delete_where( $suffix, $column, $member_id );
		}
		self::delete_periods_for_missing_enrolments();
		self::delete_workout_changes_for_missing_workouts();
		LLD_Members_Photos::delete_member( $member_id, 'diary' );
		LLD_Members_Privacy::sweep_downloads( $member_id );
		// "What changed for you" answers and testimonials go with health consent (FR2-03, F4).
		$survey = LLD_Members_Schema::table( 'survey' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$survey} SET what_changed = NULL, quote_text = NULL, permission = 0, approved_on = NULL, display_name = NULL, country = NULL WHERE member_id = %d", $member_id ) );
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
	 * The time after which erasure lines must be re-applied once a backup is restored (T6).
	 * Call it on the restored database before importing the mailbox copy: every line already in
	 * the restored database was applied before the backup was taken. With no lines at all, the
	 * oldest restorable backup is 30 days old, so the floor is 31 days ago.
	 *
	 * @return string UTC datetime.
	 */
	public static function restore_floor() {
		global $wpdb;
		$latest = $wpdb->get_var( 'SELECT MAX(erased_at) FROM ' . LLD_Members_Schema::table( 'erasure' ) );
		if ( is_string( $latest ) && '' !== $latest ) {
			return $latest;
		}
		return gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
	}

	/**
	 * Confirm the erasure lines dated after $since no longer have the data they removed.
	 *
	 * @param string $since UTC datetime from restore_floor(), or empty for every stored line.
	 * @return string[] Problems, empty when the restore is safe to reopen.
	 */
	public static function verify_stored( $since = '' ) {
		global $wpdb;
		$problems = array();
		foreach ( self::lines_after( $since ) as $row ) {
			$number = (int) $row->member_number;
			$ids    = self::matching_members( $number, (string) $row->email_hash );
			if ( 'account' === $row->what_erased ) {
				foreach ( $ids as $id ) {
					$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $id ) );
					if ( $left ) {
						$problems[] = 'Member ' . $id . ' is still present after account erasure ' . $number . '.';
					}
				}
			}
			$ids[] = $number;
			$scope = 'account' === $row->what_erased ? 'account' : 'health';
			foreach ( array_unique( $ids ) as $id ) {
				foreach ( LLD_Members_Privacy::scan( $id, '', $scope ) as $problem ) {
					$problems[] = 'Member ' . $id . ': ' . $problem;
				}
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * Re-apply the erasure lines dated after the backup. Run while maintenance is on, after a restore.
	 * Each line is matched on the member number and on the scrambled email. A health line also adds
	 * a "withdrawn" consent line, so a restored "given" line is no longer the latest.
	 *
	 * @param string $since UTC datetime from restore_floor(). Empty uses restore_floor() now.
	 * @return int Number of lines re-applied.
	 */
	public static function reapply_all( $since = '' ) {
		if ( '' === (string) $since ) {
			$since = self::restore_floor();
		}
		$rows = self::lines_after( $since );
		foreach ( $rows as $row ) {
			$number = (int) $row->member_number;
			$ids    = self::matching_members( $number, (string) $row->email_hash );
			if ( 'health' === $row->what_erased ) {
				$ids[] = $number;
				foreach ( array_unique( $ids ) as $id ) {
					self::withdraw_health( $id, false );
					if ( LLD_Members_Account::has_consent( $id, 'health' ) ) {
						self::add_withdrawn_line( $id );
					}
				}
				continue;
			}
			foreach ( $ids as $id ) {
				self::delete_account( $id, false );
			}
			if ( ! in_array( $number, $ids, true ) ) {
				self::delete_account( $number, false );
			}
			self::delete_contacts_by_hash( (string) $row->email_hash );
		}
		return count( $rows );
	}

	/**
	 * @param string $since UTC datetime, or empty for every line.
	 * @return object[]
	 */
	private static function lines_after( $since ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'erasure' );
		if ( '' === (string) $since ) {
			return (array) $wpdb->get_results( "SELECT id, member_number, email_hash, what_erased, erased_at FROM {$table} ORDER BY id ASC" );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, member_number, email_hash, what_erased, erased_at FROM {$table} WHERE erased_at > %s ORDER BY id ASC", (string) $since ) );
	}

	/**
	 * Members a line refers to: the same member number, or the same scrambled email.
	 *
	 * @param int    $number Member number on the line.
	 * @param string $hash   Scrambled email on the line.
	 * @return int[]
	 */
	private static function matching_members( $number, $hash ) {
		global $wpdb;
		$ids  = array();
		$same = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $number ) );
		if ( $same ) {
			$ids[] = $same;
		}
		if ( '' !== $hash ) {
			foreach ( (array) $wpdb->get_results( 'SELECT id, email FROM ' . LLD_Members_Schema::table( 'member' ) ) as $member ) {
				if ( hash_equals( $hash, self::email_hash( (string) $member->email ) ) ) {
					$ids[] = (int) $member->id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Remove email-list records (E1, E2) whose address matches a deleted account's scrambled email.
	 *
	 * @param string $hash Scrambled email.
	 */
	private static function delete_contacts_by_hash( $hash ) {
		global $wpdb;
		if ( '' === $hash ) {
			return;
		}
		foreach ( (array) $wpdb->get_results( 'SELECT id, email FROM ' . LLD_Members_Schema::table( 'contact' ) ) as $contact ) {
			if ( ! hash_equals( $hash, self::email_hash( (string) $contact->email ) ) ) {
				continue;
			}
			self::delete_where( 'contact_consent', 'contact_id', (int) $contact->id );
			self::delete_where( 'contact_request', 'contact_id', (int) $contact->id );
			$wpdb->delete( LLD_Members_Schema::table( 'contact' ), array( 'id' => (int) $contact->id ), array( '%d' ) );
		}
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function add_withdrawn_line( $member_id ) {
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'consent' ),
			array(
				'member_id'        => (int) $member_id,
				'consent_type'     => 'health',
				'change_kind'      => 'withdrawn',
				'document_version' => '',
				'occurred_at'      => lld_members_now_mysql( (int) $member_id ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
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
				'email_hash'    => self::email_hash( $email ),
				'what_erased'   => $what,
				'erased_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	/**
	 * Keyed hash of an email. The key is the same one used for health text, so a copied table is not a lookup list.
	 *
	 * @param string $email Email address.
	 */
	public static function email_hash( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), LLD_Members_Crypto::key() );
	}

	/**
	 * Remove Paid Memberships Pro copies of this person. Tax fields stay in our order table only.
	 *
	 * @param int $user_id WordPress user id.
	 */
	private static function delete_pmpro( $user_id ) {
		global $wpdb;
		$orders = $wpdb->prefix . 'pmpro_membership_orders';
		$meta   = $wpdb->prefix . 'pmpro_membership_ordermeta';
		if ( self::table_exists( $orders ) && self::table_exists( $meta ) ) {
			$columns   = $wpdb->get_col( "SHOW COLUMNS FROM {$meta}", 0 );
			$column    = is_array( $columns ) && in_array( 'order_id', $columns, true ) ? 'order_id' : 'pmpro_membership_order_id';
			$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$orders} WHERE user_id = %d", $user_id ) );
			foreach ( $order_ids as $order_id ) {
				$wpdb->delete( $meta, array( $column => (int) $order_id ), array( '%d' ) );
			}
		}
		foreach ( array( 'pmpro_memberships_users', 'pmpro_membership_orders', 'pmpro_discount_codes_uses' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( self::table_exists( $table ) ) {
				$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, $wpdb->esc_like( 'pmpro' ) . '%' ) );
	}

	/**
	 * @param string $table Full table name.
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

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
