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
	 * A data download that is not collected is deleted after this many minutes (S5).
	 */
	const DOWNLOAD_MINUTES = 10;

	/**
	 * Delete expired security records and warn or delete inactive free accounts.
	 */
	public static function sweep() {
		global $wpdb;
		self::sweep_downloads();
		$now = gmdate( 'Y-m-d H:i:s' );
		$codes = LLD_Members_Schema::table( 'security_code' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$codes} WHERE used_at IS NOT NULL OR expires_at < %s", $now ) );
		self::sweep_sessions();
		$locks = LLD_Members_Schema::table( 'sign_in_lock' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$locks} WHERE lock_until IS NOT NULL AND lock_until < %s", $now ) );
		$erasure = LLD_Members_Schema::table( 'erasure' );
		$cut     = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$erasure} WHERE erased_at < %s", $cut ) );
		self::inactive_accounts();
	}

	/**
	 * Delete website session records once they have ended (A4: kept "until they end"). An unended
	 * record is deleted 2 days after its expiry on the member's own clock, by when any sign-in
	 * cookie it stood for has expired too.
	 *
	 * @return int Records removed.
	 */
	public static function sweep_sessions() {
		global $wpdb;
		$table   = LLD_Members_Schema::table( 'session' );
		$removed = (int) $wpdb->query( "DELETE FROM {$table} WHERE ended_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows    = $wpdb->get_results( "SELECT id, member_id, expires_at FROM {$table} WHERE ended_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$cut = LLD_Members_Clock::now( (int) $row->member_id )->modify( '-2 days' )->format( 'Y-m-d H:i:s' );
			if ( (string) $row->expires_at < $cut ) {
				$removed += (int) $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			}
		}
		return $removed;
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
		// Photos (S2), the key (S3) and data downloads waiting to be collected (S5, section 11).
		$files = array( 'photos', 'data.key', 'downloads' );
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
			if ( ! in_array( $name, $files, true ) ) {
				$extra_files[] = $name;
			}
		}
		$downloads = is_dir( self::download_dir() ) ? array_diff( scandir( self::download_dir() ), array( '.', '..' ) ) : array();
		return array(
			'tables'         => $known,
			'files'          => $files,
			'extra_tables'   => $extra,
			'extra_files'    => $extra_files,
			'download_files' => count( $downloads ),
		);
	}

	/**
	 * The erasure checker's list: every table that can hold a member's records, and how a record
	 * is linked to the member. A column name links directly; "via:enrolment" and "via:workout"
	 * link through the member's enrolments or workout plans; "email" and "contact" link through
	 * the member's email address (sign-in pauses and the email list, E1 and E2).
	 *
	 * @return array<string,string[]>
	 */
	public static function member_links() {
		$links = array(
			'member'           => array( 'id' ),
			'consent'          => array( 'member_id' ),
			'email_pref'       => array( 'member_id' ),
			'unsubscribe_code' => array( 'member_id' ),
			'security_code'    => array( 'member_id' ),
			'sign_in_lock'     => array( 'email' ),
			'session'          => array( 'member_id' ),
			'app_key'          => array( 'member_id' ),
		);
		foreach ( LLD_Members_Schema::health_tables() as $suffix ) {
			$links[ $suffix ] = array( 'member_id' );
		}
		$links['enrolment_period'] = array( 'via:enrolment' );
		$links['workout_change']   = array( 'via:workout' );
		$links['subscription']     = array( 'member_id' );
		$links['order']            = array( 'member_id' );
		$links['referral']         = array( 'referrer_member_id', 'friend_member_id' );
		$links['reward']           = array( 'recipient_member_id' );
		$links['contact']          = array( 'email' );
		$links['contact_consent']  = array( 'contact' );
		$links['contact_request']  = array( 'contact' );
		$links['erasure']          = array( 'member_number' );
		$links['admin_log']        = array( 'member_number' );
		$links['support']          = array( 'member_id' );
		$links['survey']           = array( 'member_id' );
		$links['fb_removal']       = array( 'member_number' );
		$links['test_clock']       = array( 'member_id' );
		return $links;
	}

	/**
	 * Tables in the data download (Data Model section 11). Never A4 security records, unsubscribe
	 * codes, the test clock, operations records or the email-list contact itself.
	 *
	 * @return string[]
	 */
	public static function download_tables() {
		return array_merge(
			array( 'member', 'email_pref', 'consent' ),
			LLD_Members_Schema::health_tables(),
			array( 'enrolment_period', 'workout_change', 'subscription', 'order', 'referral', 'reward', 'contact_request', 'support', 'survey' )
		);
	}

	/**
	 * Every record linked to one member, table by table.
	 *
	 * @param int           $member_id Member number.
	 * @param string        $email     Member email, for the email-linked tables. Empty skips them.
	 * @param string[]|null $only      Limit to these tables.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function member_rows( $member_id, $email = '', $only = null ) {
		$out = array();
		foreach ( self::member_links() as $suffix => $how ) {
			if ( is_array( $only ) && ! in_array( $suffix, $only, true ) ) {
				continue;
			}
			$out[ $suffix ] = self::linked_rows( $suffix, $how, (int) $member_id, (string) $email );
		}
		return $out;
	}

	/**
	 * @param string   $suffix    Table suffix.
	 * @param string[] $how       Links from member_links().
	 * @param int      $member_id Member number.
	 * @param string   $email     Member email.
	 * @return array<int,array<string,mixed>>
	 */
	private static function linked_rows( $suffix, $how, $member_id, $email ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( $suffix );
		$first = $how[0];
		// Table and column names below come from member_links(), never from a request.
		if ( 'via:enrolment' === $first || 'via:workout' === $first ) {
			$parent = 'via:enrolment' === $first ? 'enrolment' : 'workout';
			$key    = $parent . '_id';
			$sql    = $wpdb->prepare( "SELECT x.* FROM {$table} x INNER JOIN " . LLD_Members_Schema::table( $parent ) . " p ON p.id = x.{$key} WHERE p.member_id = %d ORDER BY x.id ASC", $member_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( 'email' === $first ) {
			if ( '' === $email ) {
				return array();
			}
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( 'contact' === $first ) {
			if ( '' === $email ) {
				return array();
			}
			$sql = $wpdb->prepare( "SELECT x.* FROM {$table} x INNER JOIN " . LLD_Members_Schema::table( 'contact' ) . ' c ON c.id = x.contact_id WHERE c.email = %s ORDER BY x.id ASC', $email ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$where = implode( ' OR ', array_map( static function ( $column ) { return $column . ' = %d'; }, $how ) );
			$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where}", array_fill( 0, count( $how ), $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Folder for data downloads waiting to be collected.
	 */
	public static function download_dir() {
		return lld_members_private_dir() . '/downloads';
	}

	/**
	 * Delete data downloads older than 10 minutes, and every download of one member when given.
	 *
	 * @param int $member_id Member number, or 0 for the age rule only.
	 * @return int Files removed.
	 */
	public static function sweep_downloads( $member_id = 0 ) {
		$dir = self::download_dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$member_id = (int) $member_id;
		$cut       = time() - self::DOWNLOAD_MINUTES * MINUTE_IN_SECONDS;
		$removed   = 0;
		foreach ( (array) scandir( $dir ) as $name ) {
			$path = $dir . '/' . $name;
			if ( '.' === $name || '..' === $name || ! is_file( $path ) ) {
				continue;
			}
			$mine = $member_id > 0 && 0 === strpos( $name, 'm' . $member_id . '-' );
			if ( $mine || (int) filemtime( $path ) < $cut ) {
				if ( unlink( $path ) ) {
					++$removed;
				}
			}
		}
		return $removed;
	}

	/**
	 * Record counts per table for one member, for the download and erasure checks.
	 *
	 * @param int    $member_id Member number.
	 * @param string $email     Member email.
	 * @return array<string,int>
	 */
	public static function member_counts( $member_id, $email = '' ) {
		return array_map( 'count', self::member_rows( $member_id, $email ) );
	}

	/**
	 * Report personal data still linked to a member. Tax fields, the erasure line and anonymised money logs are allowed.
	 *
	 * @param int    $member_id Member number.
	 * @param string $sentinel  Value that must not remain.
	 * @param string $scope     account or health.
	 * @param string $email     The member's email, for records linked by address. Empty skips them.
	 * @return string[]
	 */
	public static function scan( $member_id, $sentinel = '', $scope = 'account', $email = '' ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$problems  = array();
		if ( 'health' === $scope ) {
			$health = array_merge( LLD_Members_Schema::health_tables(), array( 'enrolment_period', 'workout_change' ) );
			foreach ( self::member_rows( $member_id, $email, $health ) as $suffix => $rows ) {
				if ( $rows ) {
					$problems[] = $suffix . ' still has ' . count( $rows ) . ' rows.';
				}
			}
			$problems = array_merge( $problems, self::orphans() );
			$survey   = LLD_Members_Schema::table( 'survey' );
			$changed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$survey} WHERE member_id = %d AND what_changed IS NOT NULL AND what_changed <> ''", $member_id ) );
			if ( $changed > 0 ) {
				$problems[] = 'A what-changed answer remains.';
			}
			$quoted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$survey} WHERE member_id = %d AND ( ( quote_text IS NOT NULL AND quote_text <> '' ) OR permission <> 0 OR approved_on IS NOT NULL )", $member_id ) );
			if ( $quoted > 0 ) {
				$problems[] = 'A testimonial remains.';
			}
			if ( LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
				$problems[] = 'Health consent is still on.';
			}
			$dir = lld_members_private_dir() . '/photos/' . $member_id;
			if ( is_dir( $dir ) ) {
				foreach ( scandir( $dir ) as $file ) {
					if ( 0 === strpos( $file, 'diary-' ) ) {
						$problems[] = 'A diary photo remains.';
					}
				}
			}
			if ( self::downloads_of( $member_id ) ) {
				$problems[] = 'A data download remains.';
			}
			if ( '' !== $sentinel ) {
				$problems = array_merge( $problems, self::sentinel_hits( $sentinel ) );
			}
			return $problems;
		}
		// S7 exceptions are checked below; the test clock is test-site infrastructure (F9).
		$skip = array( 'erasure', 'order', 'admin_log', 'test_clock' );
		foreach ( self::member_rows( $member_id, $email ) as $suffix => $rows ) {
			if ( in_array( $suffix, $skip, true ) || ! $rows ) {
				continue;
			}
			$problems[] = $suffix . ' still has ' . count( $rows ) . ' rows.';
		}
		$problems = array_merge( $problems, self::orphans() );
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
		if ( self::downloads_of( $member_id ) ) {
			$problems[] = 'A data download remains.';
		}
		if ( '' !== $sentinel ) {
			$problems = array_merge( $problems, self::sentinel_hits( $sentinel ) );
		}
		return $problems;
	}

	/**
	 * Track periods and workout level changes left without their enrolment or plan, which can no
	 * longer be linked to anyone and must not remain.
	 *
	 * @return string[]
	 */
	private static function orphans() {
		global $wpdb;
		$problems = array();
		$pairs    = array(
			'enrolment_period' => array( 'enrolment', 'enrolment_id' ),
			'workout_change'   => array( 'workout', 'workout_id' ),
		);
		foreach ( $pairs as $child => $parent ) {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( $child ) . ' x LEFT JOIN ' . LLD_Members_Schema::table( $parent[0] ) . ' p ON p.id = x.' . $parent[1] . ' WHERE p.id IS NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names from this class.
			if ( $count > 0 ) {
				$problems[] = $child . ' has ' . $count . ' rows without their ' . $parent[0] . '.';
			}
		}
		return $problems;
	}

	/**
	 * @param int $member_id Member number.
	 * @return string[] Download file names waiting for this member.
	 */
	private static function downloads_of( $member_id ) {
		$dir = self::download_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$prefix = 'm' . (int) $member_id . '-';
		return array_values(
			array_filter(
				(array) scandir( $dir ),
				static function ( $name ) use ( $prefix ) {
					return 0 === strpos( (string) $name, $prefix );
				}
			)
		);
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
