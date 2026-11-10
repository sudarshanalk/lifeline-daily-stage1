<?php
/**
 * Stage 3 data access (NFR3-09, NFR3-10). Every read and write is scoped to one member; health values are
 * sealed with the site data key, bound to the member's number, before they reach the database. Nothing here
 * logs a value.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member-scoped rows, soft deletes kept 30 days for the app's sync (data model, "Ready for the app").
 */
class LLD_Members_Stage3_Data {

	const KEEP_DELETED_DAYS = 30;

	/**
	 * Seal a value for one member. The member number is the authenticated data, so a ciphertext moved to
	 * another member's row will not open.
	 *
	 * @param int        $member_id Member number.
	 * @param mixed      $value     Number or text.
	 */
	public static function seal( $member_id, $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return LLD_Members_Crypto::encrypt( (string) $value, self::aad( $member_id ) );
	}

	/**
	 * Open a sealed value. Returns null when empty or when it does not belong to this member.
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $token     Ciphertext.
	 */
	public static function open( $member_id, $token ) {
		if ( null === $token || '' === $token ) {
			return null;
		}
		try {
			return LLD_Members_Crypto::decrypt( (string) $token, self::aad( $member_id ) );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function aad( $member_id ) {
		return 'lld-member:' . (int) $member_id;
	}

	/**
	 * Insert a row for a member. $sealed names the plain fields to seal into their _ct columns.
	 *
	 * @param string               $suffix    Stage 3 table suffix.
	 * @param int                  $member_id Member number.
	 * @param array<string,mixed>  $row       Column values (plain names for sealed fields).
	 * @param string[]             $sealed    Fields stored as <field>_ct.
	 * @return int New row id, or 0.
	 */
	public static function insert( $suffix, $member_id, array $row, array $sealed = array() ) {
		global $wpdb;
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return 0;
		}
		$now = lld_members_now_mysql( $member_id );
		$data = self::prepare_columns( $member_id, $row, $sealed );
		$data['member_id'] = $member_id;
		// Only the columns the table has (today_steps keeps updated_at alone; found by the live interface test, PR #173).
		$table   = LLD_Members_Stage3_Schema::table( $suffix );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		if ( ! isset( $data['created_at'] ) && in_array( 'created_at', $columns, true ) ) {
			$data['created_at'] = $now;
		}
		if ( in_array( 'updated_at', $columns, true ) ) {
			$data['updated_at'] = $now;
		}
		$ok = $wpdb->insert( $table, $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update one of the member's rows. A row of another member is never touched: the WHERE names both.
	 *
	 * @param string              $suffix    Stage 3 table suffix.
	 * @param int                 $member_id Member number.
	 * @param int                 $id        Row id.
	 * @param array<string,mixed> $row       Column values.
	 * @param string[]            $sealed    Fields stored as <field>_ct.
	 * @return bool True when a row of this member changed.
	 */
	public static function update( $suffix, $member_id, $id, array $row, array $sealed = array() ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$id        = (int) $id;
		if ( $member_id < 1 || $id < 1 ) {
			return false;
		}
		$data = self::prepare_columns( $member_id, $row, $sealed );
		unset( $data['member_id'], $data['id'], $data['created_at'] );
		$data['updated_at'] = lld_members_now_mysql( $member_id );
		$changed = $wpdb->update(
			LLD_Members_Stage3_Schema::table( $suffix ),
			$data,
			array(
				'id'        => $id,
				'member_id' => $member_id,
			),
			null,
			array( '%d', '%d' )
		);
		return false !== $changed && $changed > 0;
	}

	/**
	 * Soft delete: deleted_at is set and the row is removed for good after 30 days.
	 *
	 * @param string $suffix    Stage 3 table suffix.
	 * @param int    $member_id Member number.
	 * @param int    $id        Row id.
	 */
	public static function soft_delete( $suffix, $member_id, $id ) {
		return self::update( $suffix, $member_id, $id, array( 'deleted_at' => lld_members_now_mysql( (int) $member_id ) ) );
	}

	/**
	 * The member's rows, newest change first, sealed fields opened. `since` returns only rows changed after
	 * that UTC time, including deleted ones, for the app's sync.
	 *
	 * @param string   $suffix    Stage 3 table suffix.
	 * @param int      $member_id Member number.
	 * @param string[] $sealed    Fields stored as <field>_ct.
	 * @param string   $since     UTC datetime, or empty.
	 * @param int      $limit     Most rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( $suffix, $member_id, array $sealed = array(), $since = '', $limit = 500 ) {
		global $wpdb;
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return array();
		}
		$table   = LLD_Members_Stage3_Schema::table( $suffix );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$columns = is_array( $columns ) ? $columns : array();
		$where   = 'member_id = %d';
		$params  = array( $member_id );
		if ( '' !== $since && in_array( 'updated_at', $columns, true ) ) {
			$where   .= ' AND updated_at > %s';
			$params[] = $since;
		} elseif ( in_array( 'deleted_at', $columns, true ) ) {
			$where .= ' AND deleted_at IS NULL';
		}
		$order = in_array( 'updated_at', $columns, true ) ? 'updated_at DESC, id DESC' : 'id DESC';
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT %d", array_merge( $params, array( max( 1, min( 2000, (int) $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::open_row( $member_id, $row, $sealed );
		}
		return $out;
	}

	/**
	 * One of the member's rows, or null.
	 *
	 * @param string   $suffix    Stage 3 table suffix.
	 * @param int      $member_id Member number.
	 * @param int      $id        Row id.
	 * @param string[] $sealed    Fields stored as <field>_ct.
	 * @return array<string,mixed>|null
	 */
	public static function row( $suffix, $member_id, $id, array $sealed = array() ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$id        = (int) $id;
		if ( $member_id < 1 || $id < 1 ) {
			return null;
		}
		$table = LLD_Members_Stage3_Schema::table( $suffix );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND member_id = %d", $id, $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::open_row( $member_id, $row, $sealed ) : null;
	}

	/**
	 * Delete every row of a member from one table, for good (account deletion, consent withdrawal).
	 *
	 * @param string $suffix    Stage 3 table suffix.
	 * @param int    $member_id Member number.
	 */
	public static function purge( $suffix, $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return;
		}
		$wpdb->delete( LLD_Members_Stage3_Schema::table( $suffix ), array( 'member_id' => $member_id ), array( '%d' ) );
	}

	/**
	 * Remove soft-deleted rows older than 30 days, on the plugin's daily sweep.
	 */
	public static function sweep_deleted() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DELETED_DAYS * DAY_IN_SECONDS );
		foreach ( LLD_Members_Stage3_Schema::suffixes() as $suffix ) {
			$table   = LLD_Members_Stage3_Schema::table( $suffix );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( is_array( $columns ) && in_array( 'deleted_at', $columns, true ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		// Email codes are deleted 24 hours after creation (data model: Email code).
		$codes = LLD_Members_Stage3_Schema::table( 'email_codes' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$codes} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $row       Plain values.
	 * @param string[]            $sealed    Fields to seal.
	 * @return array<string,mixed>
	 */
	private static function prepare_columns( $member_id, array $row, array $sealed ) {
		$data = array();
		foreach ( $row as $field => $value ) {
			if ( in_array( $field, $sealed, true ) ) {
				$data[ $field . '_ct' ] = self::seal( $member_id, $value );
			} else {
				$data[ $field ] = $value;
			}
		}
		return $data;
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $row       Database row.
	 * @param string[]            $sealed    Fields stored as <field>_ct.
	 * @return array<string,mixed>
	 */
	private static function open_row( $member_id, array $row, array $sealed ) {
		foreach ( $sealed as $field ) {
			$column = $field . '_ct';
			if ( array_key_exists( $column, $row ) ) {
				$row[ $field ] = self::open( $member_id, $row[ $column ] );
				unset( $row[ $column ] );
			}
		}
		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}
		unset( $row['member_id'] );
		return $row;
	}
}
