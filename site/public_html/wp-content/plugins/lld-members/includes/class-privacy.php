<?php
/**
 * Retention, inactive-account deletion, and the erasure checker (NFR2-01, F1, A4).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily clean-up and the list of personal stores.
 */
class LLD_Members_Privacy {

	/**
	 * Delete expired security records and warn or delete inactive free accounts.
	 */
	public static function sweep() {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$codes = LLD_Members_Schema::table( 'security_code' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$codes} WHERE used_at IS NOT NULL OR expires_at < %s", $now ) );
		$locks = LLD_Members_Schema::table( 'sign_in_lock' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$locks} WHERE lock_until IS NOT NULL AND lock_until < %s", $now ) );
		$erasure = LLD_Members_Schema::table( 'erasure' );
		$cut     = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$erasure} WHERE erased_at < %s", $cut ) );
		self::inactive_accounts();
	}

	/**
	 * A free account with no sign-in for 24 months is warned 30 days before, then deleted.
	 */
	public static function inactive_accounts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, user_id, email, first_name, joined_on, last_signed_in_at FROM ' . LLD_Members_Schema::table( 'member' ) . " WHERE role = 'member'" );
		$warned = get_option( 'lld_members_inactive_warned', array() );
		if ( ! is_array( $warned ) ) {
			$warned = array();
		}
		foreach ( $rows as $row ) {
			if ( 'free' !== LLD_Members_Access::access_state( (int) $row->id ) && 'Free' !== LLD_Members_Access::access_state( (int) $row->id ) ) {
				continue;
			}
			$anchor = $row->last_signed_in_at ? $row->last_signed_in_at : $row->joined_on . ' 00:00:00';
			$start  = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $anchor, new DateTimeZone( 'UTC' ) );
			if ( ! $start ) {
				continue;
			}
			$delete_on = $start->modify( '+24 months' );
			$warn_on   = $delete_on->modify( '-30 days' );
			$today     = LLD_Members_Clock::now( (int) $row->id );
			$key       = (string) $row->id;
			if ( $today->format( 'Y-m-d' ) === $warn_on->format( 'Y-m-d' ) && ( ! isset( $warned[ $key ] ) || $warned[ $key ] !== $delete_on->format( 'Y-m-d' ) ) ) {
				LLD_Members_Mail::send_inactive_warning( (string) $row->email, (string) $row->first_name, $delete_on->format( 'j F Y' ) );
				$warned[ $key ] = $delete_on->format( 'Y-m-d' );
			}
			if ( $today >= $delete_on ) {
				LLD_Members_Erasure::delete_account( (int) $row->id, true );
				unset( $warned[ $key ] );
			}
		}
		update_option( 'lld_members_inactive_warned', $warned, false );
	}

	/**
	 * Personal tables and file stores, compared with the live database by TC-NFR2-01-AS-01.
	 *
	 * @return array{tables:string[],files:string[],extra_tables:string[],extra_files:string[]}
	 */
	public static function stores() {
		global $wpdb;
		$known = array();
		foreach ( LLD_Members_Schema::all_suffixes() as $suffix ) {
			$known[] = LLD_Members_Schema::table( $suffix );
		}
		$live  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lld_' ) . '%' ) );
		$extra = array();
		foreach ( $live as $table ) {
			if ( ! in_array( $table, $known, true ) ) {
				$extra[] = $table;
			}
		}
		$files = array( 'photos', 'data.key' );
		$root  = lld_members_private_dir();
		$found = array();
		if ( is_dir( $root ) ) {
			foreach ( scandir( $root ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$found[] = $name;
				}
			}
		}
		$extra_files = array();
		foreach ( $found as $name ) {
			if ( ! in_array( $name, $files, true ) && 'downloads' !== $name ) {
				$extra_files[] = $name;
			}
		}
		$downloads = is_dir( $root . '/downloads' ) ? array_diff( scandir( $root . '/downloads' ), array( '.', '..' ) ) : array();
		return array(
			'tables'         => $known,
			'files'          => $files,
			'extra_tables'   => $extra,
			'extra_files'    => $extra_files,
			'download_files' => count( $downloads ),
		);
	}

	/**
	 * Report personal data still linked to a member. Tax fields, the erasure line and anonymised money logs are allowed.
	 *
	 * @param int    $member_id Member number.
	 * @param string $sentinel  Value that must not remain.
	 * @return string[]
	 */
	public static function scan( $member_id, $sentinel = '', $scope = 'account' ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$problems  = array();
		if ( 'health' === $scope ) {
			foreach ( LLD_Members_Schema::health_tables() as $suffix ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( $suffix ) . ' WHERE member_id = %d', $member_id ) );
				if ( $count > 0 ) {
					$problems[] = $suffix . ' still has ' . $count . ' rows.';
				}
			}
			$changed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'survey' ) . ' WHERE member_id = %d AND what_changed IS NOT NULL AND what_changed <> ""', $member_id ) );
			if ( $changed > 0 ) {
				$problems[] = 'A what-changed answer remains.';
			}
			$dir = lld_members_private_dir() . '/photos/' . $member_id;
			if ( is_dir( $dir ) ) {
				foreach ( scandir( $dir ) as $file ) {
					if ( 0 === strpos( $file, 'diary-' ) ) {
						$problems[] = 'A diary photo remains.';
					}
				}
			}
			if ( '' !== $sentinel ) {
				$problems = array_merge( $problems, self::sentinel_hits( $sentinel ) );
			}
			return $problems;
		}
		$skip      = array( 'erasure', 'order', 'admin_log', 'error_log', 'stripe_notice', 'theme_count', 'weekly_report', 'test_clock' );
		foreach ( LLD_Members_Schema::all_suffixes() as $suffix ) {
			if ( in_array( $suffix, $skip, true ) ) {
				continue;
			}
			$table   = LLD_Members_Schema::table( $suffix );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			$column  = null;
			foreach ( array( 'member_id', 'referrer_member_id', 'friend_member_id', 'recipient_member_id', 'member_number' ) as $candidate ) {
				if ( in_array( $candidate, $columns, true ) ) {
					$column = $candidate;
					break;
				}
			}
			if ( ! $column ) {
				continue;
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $member_id ) );
			if ( $count > 0 ) {
				$problems[] = $suffix . ' still has ' . $count . ' rows.';
			}
		}
		$order = LLD_Members_Schema::table( 'order' );
		$linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$order} WHERE member_id = %d", $member_id ) );
		if ( $linked > 0 ) {
			$problems[] = 'An order is still linked to the member.';
		}
		$log = LLD_Members_Schema::table( 'admin_log' );
		$named = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE member_number = %d AND (member_name IS NOT NULL OR member_email IS NOT NULL) AND money_related = 1", $member_id ) );
		if ( $named > 0 ) {
			$problems[] = 'A money log still names the member.';
		}
		$personal = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE member_number = %d AND money_related = 0", $member_id ) );
		if ( $personal > 0 ) {
			$problems[] = 'A non-money admin log still names the member.';
		}
		$dir = lld_members_private_dir() . '/photos/' . $member_id;
		if ( is_dir( $dir ) ) {
			$left = array_diff( scandir( $dir ), array( '.', '..' ) );
			if ( $left ) {
				$problems[] = 'Photo files remain.';
			}
		}
		if ( '' !== $sentinel ) {
			$problems = array_merge( $problems, self::sentinel_hits( $sentinel ) );
		}
		return $problems;
	}

	/**
	 * @param string $sentinel Value that must not remain in a text column.
	 * @return string[]
	 */
	private static function sentinel_hits( $sentinel ) {
		global $wpdb;
		$problems = array();
		$like     = '%' . $wpdb->esc_like( $sentinel ) . '%';
		foreach ( LLD_Members_Schema::all_suffixes() as $suffix ) {
			$table = LLD_Members_Schema::table( $suffix );
			$cols  = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
			foreach ( $cols as $col ) {
				$type = strtolower( (string) $col->Type );
				if ( false === strpos( $type, 'char' ) && false === strpos( $type, 'text' ) ) {
					continue;
				}
				$name = $col->Field;
				$hit  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$name} LIKE %s", $like ) );
				if ( $hit > 0 ) {
					$problems[] = 'Sentinel remains in ' . $suffix . '.' . $name . '.';
				}
			}
		}
		return $problems;
	}
}
