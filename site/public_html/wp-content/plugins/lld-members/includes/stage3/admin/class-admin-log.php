<?php
/**
 * Admin activity log (P3-87; FR3-86, NFR3-19, NFR3-23; data model: Admin activity log).
 *
 * The Stage 2 admin log (wp_lld_admin_log) gains who did it, what else was affected, the state before and after,
 * the reason and a scrambled IP. Entries are only ever added: no page, request or command edits or deletes one.
 * The only removals are the Stage 2 rule on member deletion (names and emails go; Stage 3 entries never hold
 * them) and the 2-year limit. Entries never hold health data or note text.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads the admin activity log.
 */
class LLD_Members_Admin_Log {

	const KEEP_DAYS = 730;

	/** Actions that need a reason (FR3-86). */
	const NEED_REASON = array(
		'member_delete',
		'premium_grant',
		'premium_grant_remove',
		'membership_end',
		'member_block',
		'member_unblock',
		'test_account_mark',
		'test_account_unmark',
		'test_accounts_set',
		'feature_off',
		'feature_test_accounts',
		'feature_on',
		'helper_add',
		'helper_remove',
	);

	/** Plain-English names for the log page and CSV. */
	const LABELS = array(
		'admin_sign_in'          => 'Signed in',
		'admin_sign_in_failed'   => 'Failed sign-in attempt',
		'admin_sign_in_locked'   => 'Sign-in locked for 15 minutes',
		'admin_signed_out'       => 'Signed out by the time limit',
		'admin_new_device'       => 'Signed in from a new browser',
		'member_view'            => 'Opened a member account',
		'member_search'          => 'Searched members',
		'member_note'            => 'Added a note',
		'member_resend'          => 'Resent an account email',
		'member_pause'           => 'Paused a membership',
		'member_resume'          => 'Resumed a membership',
		'premium_grant'          => 'Gave free Premium',
		'premium_grant_remove'   => 'Removed free Premium',
		'membership_end'         => 'Ended a membership',
		'test_account_mark'      => 'Marked as a test account',
		'test_account_unmark'    => 'Unmarked as a test account',
		'test_accounts_set'      => 'Changed the test-account list',
		'member_block'           => 'Blocked a member',
		'member_unblock'         => 'Unblocked a member',
		'member_delete'          => 'Deleted a member',
		'member_download_sent'   => 'Sent the data download link',
		'feature_off'            => 'Turned a feature off',
		'feature_test_accounts'  => 'Limited a feature to test accounts',
		'feature_on'             => 'Turned a feature on for everyone',
		'feature_all_on'         => 'Turned every feature on',
		'feature_all_off'        => 'Made each feature follow its own switch',
		'helper_add'             => 'Added a Support helper',
		'helper_remove'          => 'Removed a Support helper',
		'admin_code_sent'        => 'Asked for a fresh code',
	);

	public static function boot() {
		add_action( 'lld_members_daily', array( __CLASS__, 'sweep' ) );
		add_action( 'admin_post_lld_admin_log_csv', array( __CLASS__, 'csv' ) );
	}

	/**
	 * Who is acting: a WordPress user number, 'cli' for the command line, or 'system'.
	 *
	 * @param int $user_id A user to record instead of the current one (for example a failed sign-in).
	 */
	public static function actor( $user_id = 0 ) {
		if ( $user_id > 0 ) {
			return (string) (int) $user_id;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$current = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $current > 0 ? (string) $current : 'system';
	}

	/**
	 * Add one entry. Returns false when a reason is required and missing, so the caller refuses the action.
	 *
	 * @param string              $action What was done, from LABELS.
	 * @param array<string,mixed> $args   member, target, before, after, reason, user (actor override).
	 * @return bool
	 */
	public static function add( $action, array $args = array() ) {
		$reason = isset( $args['reason'] ) ? trim( (string) $args['reason'] ) : '';
		if ( in_array( $action, self::NEED_REASON, true ) && '' === $reason && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		global $wpdb;
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$row = array(
			'action'        => substr( (string) $action, 0, 64 ),
			'member_number' => ! empty( $args['member'] ) ? (int) $args['member'] : null,
			'detail'        => self::label( $action ),
			'money_related' => 0,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'admin_user_id' => self::actor( isset( $args['user'] ) ? (int) $args['user'] : 0 ),
			'target'        => isset( $args['target'] ) ? substr( sanitize_text_field( (string) $args['target'] ), 0, 190 ) : null,
			'before_state'  => isset( $args['before'] ) ? sanitize_text_field( (string) $args['before'] ) : null,
			'after_state'   => isset( $args['after'] ) ? sanitize_text_field( (string) $args['after'] ) : null,
			'reason'        => '' !== $reason ? sanitize_textarea_field( $reason ) : null,
			'ip_hash'       => '' !== $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : null,
		);
		return false !== $wpdb->insert( LLD_Members_Schema::table( 'admin_log' ), $row );
	}

	/**
	 * @param string $action Action key.
	 */
	public static function label( $action ) {
		return isset( self::LABELS[ $action ] ) ? self::LABELS[ $action ] : (string) $action;
	}

	/**
	 * Entries the current admin may see: every entry for the Owner, only their own for Support (FR3-86 AS-04).
	 *
	 * @param int $limit  Rows.
	 * @param int $offset Offset.
	 * @return object[]
	 */
	public static function visible( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'admin_log' );
		$cols  = 'id, action, member_number, detail, created_at, admin_user_id, target, before_state, after_state, reason';
		if ( current_user_can( 'manage_options' ) ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE admin_user_id IS NOT NULL ORDER BY id DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE admin_user_id = %s ORDER BY id DESC LIMIT %d OFFSET %d", (string) get_current_user_id(), (int) $limit, (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Name of who did it, for display: an admin's display name, "Command line" or "System".
	 *
	 * @param string $actor admin_user_id value.
	 */
	public static function who( $actor ) {
		if ( 'cli' === $actor ) {
			return 'Command line';
		}
		if ( ! ctype_digit( (string) $actor ) ) {
			return 'System';
		}
		$user = get_userdata( (int) $actor );
		return $user ? (string) $user->display_name : 'Removed admin #' . (int) $actor;
	}

	/**
	 * The Owner's CSV download of the whole log (FR3-86 AS-04). Same columns as the page.
	 */
	public static function csv() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'lld_admin_log_csv' ) ) {
			wp_die( 'You can\'t download the activity log.', 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lifeline-daily-activity-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, self::csv_header() );
		$offset = 0;
		do {
			$rows = self::visible( 500, $offset );
			foreach ( $rows as $row ) {
				fputcsv( $out, self::csv_row( $row ) );
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * @return string[]
	 */
	public static function csv_header() {
		return array( 'Entry', 'When (UTC)', 'Who', 'What', 'Member number', 'Item', 'Before', 'After', 'Reason' );
	}

	/**
	 * @param object $row Log row.
	 * @return string[]
	 */
	public static function csv_row( $row ) {
		$cells = array(
			(string) $row->id,
			(string) $row->created_at,
			self::who( (string) $row->admin_user_id ),
			self::label( (string) $row->action ),
			null === $row->member_number ? '' : (string) $row->member_number,
			(string) $row->target,
			(string) $row->before_state,
			(string) $row->after_state,
			(string) $row->reason,
		);
		// A cell starting with = + - @ would run as a formula in a spreadsheet.
		return array_map(
			static function ( $cell ) {
				return preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
			},
			$cells
		);
	}

	/**
	 * Entries are kept 2 years, then removed automatically (FR3-86 AS-06). Stage 2 lines follow the same limit (F2).
	 */
	public static function sweep() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'admin_log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * How many times this admin did an action in the last hour (NFR3-21 deletion limit).
	 *
	 * @param string $action Action key.
	 * @param string $actor  admin_user_id.
	 * @return array{count:int,oldest:string}
	 */
	public static function recent( $action, $actor ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'admin_log' );
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM {$table} WHERE action = %s AND admin_user_id = %s AND created_at > %s", $action, $actor, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'count'  => $row ? (int) $row->n : 0,
			'oldest' => $row && $row->oldest ? (string) $row->oldest : '',
		);
	}
}
