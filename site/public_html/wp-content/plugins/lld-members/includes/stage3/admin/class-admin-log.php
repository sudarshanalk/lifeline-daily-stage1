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

	/** Actions that need a reason (FR3-86). Feature and tool switches take an optional reason (FR3-74, 6 Nov 2026). */
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
		'premium_test_on',
		'premium_test_off',
		'member_reset',
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
		'premium_test_on'        => 'Made a Premium test member',
		'premium_test_off'       => 'Returned a Premium test member to Free',
		'member_reset'           => 'Reset a test member to a clean start',
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
			// Stage 2 lines (refunds, prices, codes, rewards, approvals, preview) have no admin_user_id: shown as Stage 2 records.
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE admin_user_id = %s ORDER BY id DESC LIMIT %d OFFSET %d", (string) get_current_user_id(), (int) $limit, (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Name of who did it, for display: an admin's display name, "Command line" or "System".
	 *
	 * @param string $actor admin_user_id value.
	 */
	public static function who( $actor ) {
		if ( '' === (string) $actor ) {
			return 'Not recorded (these older entries don\'t say who)';
		}
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
	 * Admin time in the WordPress site zone (E5 A): "11 Nov, 15:51" or "11 Nov 2025, 15:51".
	 *
	 * @param string $utc Y-m-d H:i:s UTC.
	 */
	public static function when_site( $utc ) {
		$ts = strtotime( (string) $utc . ' UTC' );
		if ( false === $ts ) {
			return (string) $utc;
		}
		$year     = (int) wp_date( 'Y', $ts );
		$now_year = (int) wp_date( 'Y' );
		$fmt      = $year === $now_year ? 'j M, H:i' : 'j M Y, H:i';
		return wp_date( $fmt, $ts );
	}

	/**
	 * Member-facing words for a stored feature/state code (E6 A).
	 *
	 * @param string $state Stored before/after state.
	 */
	private static function state_phrase( $state ) {
		$map = array(
			'everyone'      => 'on for everyone',
			'on'            => 'on for everyone',
			'off'           => 'off',
			'test_accounts' => 'limited to test accounts',
		);
		$state = (string) $state;
		return isset( $map[ $state ] ) ? $map[ $state ] : $state;
	}

	/**
	 * A typed reason only (E6 A). Placeholders like "no reason given" are hidden.
	 *
	 * @param string $reason Stored reason.
	 * @param bool   $quoted Wrap as Reason: "…" on the sentence (pause), else return the bare text for the second line.
	 */
	private static function typed_reason( $reason, $quoted = false ) {
		$reason = trim( (string) $reason );
		if ( '' === $reason || preg_match( '/^no (reason|note) given$/i', $reason ) ) {
			return '';
		}
		return $quoted ? ' Reason: "' . $reason . '"' : '"' . $reason . '"';
	}

	/**
	 * One plain activity-log sentence (E6 A). Returns array( when, sentence, detail ).
	 *
	 * @param object $row Log row.
	 * @return array{0:string,1:string,2:string}
	 */
	public static function sentence( $row ) {
		$when   = self::when_site( (string) $row->created_at );
		$who    = self::who( (string) $row->admin_user_id );
		$action = (string) $row->action;
		$member = null !== $row->member_number ? (int) $row->member_number : 0;
		$target = null !== $row->target ? (string) $row->target : '';
		$before = null !== $row->before_state ? (string) $row->before_state : '';
		$after  = null !== $row->after_state ? (string) $row->after_state : '';
		$reason = null !== $row->reason ? trim( (string) $row->reason ) : '';
		$detail = null === $row->admin_user_id ? (string) $row->detail : '';
		$m      = $member > 0 ? 'member #' . $member : '';
		$why    = self::typed_reason( $reason );

		$plain_target = $target;
		if ( '' !== $target && class_exists( 'LLD_Members_Admin_Pages' ) ) {
			$named = LLD_Members_Admin_Pages::plain_name( $target );
			if ( $named ) {
				$plain_target = $named;
			}
		}

		switch ( $action ) {
			case 'preview':
				$as = 'a visitor';
				if ( false !== stripos( $detail, 'premium' ) ) {
					$as = 'a Premium member';
				} elseif ( false !== stripos( $detail, 'free' ) ) {
					$as = 'a free member';
				}
				return array( $when, 'You previewed the site as ' . $as . '.', $detail );
			case 'refund':
				return array( $when, 'Refunded a payment for ' . ( $m ? $m : 'a member' ) . '.', $detail );
			case 'price_change':
				return array( $when, 'Changed the Premium prices.', $detail );
			case 'code_created':
				return array( $when, 'Created a discount code.', $detail );
			case 'reward_withdrawn':
				return array( $when, 'A referral reward for ' . ( $m ? $m : 'a member' ) . ' was taken back.', $detail );
			case 'admin_signed_out':
				if ( 'idle' === $after ) {
					$line = $who . ' was signed out after 30 minutes without activity.';
				} elseif ( 'max' === $after ) {
					$line = $who . ' was signed out after 12 hours, the longest a session can last.';
				} elseif ( 'no_code' === $after ) {
					$line = $who . ' was signed out because the emailed code wasn\'t entered.';
				} else {
					$line = $who . ' was signed out by the time limit.';
				}
				return array( $when, $line, '' );
			case 'admin_sign_in':
				return array( $when, $who . ' signed in.', '' );
			case 'admin_sign_in_failed':
				return array( $when, 'A sign-in to ' . $who . '\'s account failed.', '' );
			case 'admin_sign_in_locked':
				return array( $when, $who . '\'s sign-in was locked for 15 minutes after failed attempts.', '' );
			case 'admin_new_device':
				return array( $when, $who . ' signed in from a new browser.', '' );
			case 'admin_code_sent':
				return array( $when, $who . ' asked for a fresh sign-in code.', '' );
			case 'feature_on':
				$line = $who . ' turned ' . $plain_target . ' on for everyone';
				if ( '' !== $before ) {
					$line .= ' (it was ' . self::state_phrase( $before ) . ')';
				}
				$line .= '.';
				return array( $when, $line, $why );
			case 'feature_off':
				$line = $who . ' turned ' . $plain_target . ' off';
				if ( '' !== $before ) {
					$line .= ' (it was ' . self::state_phrase( $before ) . ')';
				}
				$line .= '.';
				return array( $when, $line, $why );
			case 'feature_test_accounts':
				$line = $who . ' limited ' . $plain_target . ' to test accounts';
				if ( '' !== $before ) {
					$line .= ' (it was ' . self::state_phrase( $before ) . ')';
				}
				$line .= '.';
				return array( $when, $line, $why );
			case 'feature_all_on':
				return array( $when, $who . ' turned every feature on.', $why );
			case 'feature_all_off':
				// Not in the E6 A table: fall back to the existing LABELS wording, not a new sentence.
				return array( $when, $who . ': ' . self::label( 'feature_all_off' ) . '.', $why );
			case 'test_accounts_set':
				$line = $who . ' changed the test-account list';
				if ( '' !== $before || '' !== $after ) {
					$line .= ' from ' . $before . ' to ' . $after;
				}
				$line .= '.';
				return array( $when, $line, $why );
			case 'member_view':
				return array( $when, $who . ' opened ' . ( $m ? $m . "'s account" : 'a member account' ) . '.', '' );
			case 'member_search':
				return array( $when, $who . ' searched the member list.', '' );
			case 'member_note':
				return array( $when, $who . ' added a note to ' . ( $m ? $m : 'a member' ) . '.', '' );
			case 'member_resend':
				return array( $when, $who . ' resent an account email to ' . ( $m ? $m : 'a member' ) . '.', '' );
			case 'member_pause':
				$months = '';
				if ( preg_match( '/(\d+)\s*month/', $after . ' ' . $detail, $mm ) ) {
					$months = ' for ' . $mm[1] . ' month' . ( '1' === $mm[1] ? '' : 's' );
				}
				$line = $who . ' paused ' . ( $m ? $m . "'s" : 'a' ) . ' membership' . $months . '.' . self::typed_reason( $reason, true );
				return array( $when, $line, '' );
			case 'member_resume':
				return array( $when, $who . ' ended ' . ( $m ? $m . "'s" : 'a' ) . ' pause.', $why );
			case 'premium_grant':
				$until = '';
				if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $after . ' ' . $detail, $dm ) ) {
					$ts = strtotime( $dm[1] . ' 00:00:00 UTC' );
					$until = false === $ts ? '' : ' until ' . gmdate( 'j F Y', $ts );
				}
				return array( $when, $who . ' gave ' . ( $m ? $m : 'a member' ) . ' free Premium' . $until . '.', $why );
			case 'premium_grant_remove':
				$was = '';
				if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $before . ' ' . $detail, $dm ) ) {
					$ts = strtotime( $dm[1] . ' 00:00:00 UTC' );
					$was = false === $ts ? '' : ' (it was due to end on ' . gmdate( 'j F Y', $ts ) . ')';
				}
				return array( $when, $who . ' removed ' . ( $m ? $m . "'s" : 'a member\'s' ) . ' free Premium' . $was . '.', $why );
			case 'membership_end':
				$how = false !== stripos( $after . ' ' . $detail, 'period' ) ? ' at the end of the period' : ' now';
				return array( $when, $who . ' ended ' . ( $m ? $m . "'s" : 'a member\'s' ) . ' Premium' . $how . '.', $why );
			case 'test_account_mark':
				return array( $when, $who . ' marked ' . ( $m ? $m : 'a member' ) . ' as a test account.', $why );
			case 'test_account_unmark':
				return array( $when, $who . ' unmarked ' . ( $m ? $m : 'a member' ) . ' as a test account.', $why );
			case 'premium_test_on':
				return array( $when, $who . ' made ' . ( $m ? $m : 'a member' ) . ' a Premium test member.', $why );
			case 'premium_test_off':
				return array( $when, $who . ' returned ' . ( $m ? $m : 'a member' ) . ' to Free.', $why );
			case 'member_reset':
				return array( $when, $who . ' reset test ' . ( $m ? $m : 'member' ) . ' to a clean start.', $why );
			case 'member_block':
				return array( $when, $who . ' blocked ' . ( $m ? $m : 'a member' ) . ' and paused their billing.', $why );
			case 'member_unblock':
				return array( $when, $who . ' unblocked ' . ( $m ? $m : 'a member' ) . ' and restarted their billing.', $why );
			case 'member_delete':
				return array( $when, $who . ' deleted ' . ( $m ? $m . "'s" : 'a member\'s' ) . ' account.', $why );
			case 'member_download_sent':
				return array( $when, $who . ' sent ' . ( $m ? $m : 'a member' ) . ' their data download link.', '' );
			case 'helper_add':
				$h = '' !== $target ? $target : 'a helper';
				return array( $when, $who . ' made ' . $h . ' a Support helper.', $why );
			case 'helper_remove':
				$h = '' !== $target ? $target : 'a helper';
				return array( $when, $who . ' removed ' . $h . ' as a Support helper.', $why );
			default:
				$label = self::label( $action );
				$line  = $who . ': ' . $label;
				if ( $m ) {
					$line .= ' · ' . $m;
				}
				if ( '' !== $plain_target && $plain_target !== $m ) {
					$line .= ' · ' . $plain_target;
				}
				$line .= '.';
				return array( $when, $line, '' !== $why ? $why : $detail );
		}
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
			null === $row->admin_user_id ? (string) $row->detail : (string) $row->target,
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
