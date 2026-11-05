<?php
/**
 * Members management in the admin panel (P3-87; FR3-75 to FR3-85, NFR3-19).
 *
 * Reads only the account tables (member, subscription, identities, pauses, member_notes, premium_grants) and never
 * a health, food, goal or calculator table, for any role (NFR3-19). There is no way to sign in as a member.
 * Every action checks the role on the server (LLD_Members_Admin_Roles::can()) and is logged (FR3-86).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member search, the account summary and the member actions.
 */
class LLD_Members_Admin_Members {

	/** Tables the admin panel may read (NFR3-19 AS-02). */
	const READS = array( 'member', 'subscription', 'identities', 'pauses', 'member_notes', 'premium_grants', 'admin_log' );

	const BLOCK_PAUSED = 'lld_block_paused';

	public static function boot() {
		add_filter( 'lld_members_paid_open', array( __CLASS__, 'grant_open' ), 30, 2 );
		add_filter( 'lld_members_access_state', array( __CLASS__, 'grant_state' ), 10, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'refuse_blocked' ), 35, 1 );
		add_filter( 'determine_current_user', array( __CLASS__, 'drop_blocked' ), 99 );
		add_action( 'lld_members_erase_account', array( __CLASS__, 'erase_account' ) );
	}

	// ----- Reading -----

	/**
	 * Members matching a search and filters (FR3-75). Search: part of an email or name, or a member number.
	 *
	 * @param string              $q       Search text.
	 * @param array<string,string> $filters plan (free, premium), status (active, paused, cancelled, blocked), test (yes, no).
	 * @param int                 $limit   Rows.
	 * @return object[]
	 */
	public static function search( $q, array $filters = array(), $limit = 50 ) {
		return self::query( $q, $filters, $limit, false );
	}

	/**
	 * How many members match the filters, for the Overview (FR3-73).
	 *
	 * @param array<string,string> $filters Filters as search().
	 */
	public static function count( array $filters ) {
		return (int) self::query( '', $filters, 0, true );
	}

	/**
	 * @param string               $q       Search text.
	 * @param array<string,string> $filters Filters.
	 * @param int                  $limit   Rows.
	 * @param bool                 $count   Count instead of rows.
	 * @return object[]|int
	 */
	private static function query( $q, array $filters, $limit, $count ) {
		global $wpdb;
		$m     = LLD_Members_Schema::table( 'member' );
		$s     = LLD_Members_Schema::table( 'subscription' );
		$p     = LLD_Members_Stage3_Schema::table( 'pauses' );
		$g     = LLD_Members_Stage3_Schema::table( 'premium_grants' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$today = gmdate( 'Y-m-d' );
		$where = array( '1=1' );
		$args  = array();
		$q     = trim( (string) $q );
		if ( '' !== $q ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			if ( ctype_digit( ltrim( $q, '#' ) ) ) {
				$where[] = '(m.id = %d OR m.email LIKE %s)';
				$args[]  = (int) ltrim( $q, '#' );
				$args[]  = $like;
			} else {
				$where[] = "(m.email LIKE %s OR CONCAT(m.first_name, ' ', m.last_name) LIKE %s OR m.display_name LIKE %s)";
				array_push( $args, $like, $like, $like );
			}
		}
		// COALESCE: a member with no subscription row is Free, never "unknown" (NULL would drop them from both filters).
		$premium = "(COALESCE(s.access_state, '') = 'Premium' OR (COALESCE(s.access_state, '') = 'cancelled' AND COALESCE(s.period_ends_on, '0000-00-00') >= %s) OR EXISTS (SELECT 1 FROM {$g} g WHERE g.member_id = m.id AND g.removed_at IS NULL AND g.starts_at <= %s AND g.ends_at > %s))";
		$paused  = "EXISTS (SELECT 1 FROM {$p} p WHERE p.member_id = m.id AND p.status IN ('scheduled', 'active') AND p.starts_at <= %s AND p.ends_at > %s)";
		$plan    = $filters['plan'] ?? '';
		if ( 'premium' === $plan || 'free' === $plan ) {
			$where[] = ( 'free' === $plan ? 'NOT ' : '' ) . $premium;
			array_push( $args, $today, $now, $now );
		}
		$status = $filters['status'] ?? '';
		if ( 'blocked' === $status ) {
			$where[] = 'm.blocked_at IS NOT NULL';
		} elseif ( 'paused' === $status ) {
			$where[] = $paused;
			array_push( $args, $today, $today );
		} elseif ( 'cancelled' === $status ) {
			$where[] = "s.access_state IN ('cancelled', 'Premium ended', 'Refunded')";
		} elseif ( 'active' === $status ) {
			$where[] = "m.blocked_at IS NULL AND (s.access_state IS NULL OR s.access_state NOT IN ('cancelled', 'Premium ended', 'Refunded')) AND NOT " . $paused;
			array_push( $args, $today, $today );
		}
		$test = $filters['test'] ?? '';
		if ( 'yes' === $test || 'no' === $test ) {
			$emails = LLD_Members_Features::test_account_emails();
			if ( ! $emails ) {
				if ( 'yes' === $test ) {
					return $count ? 0 : array();
				}
			} else {
				$where[] = 'LOWER(m.email) ' . ( 'no' === $test ? 'NOT ' : '' ) . 'IN (' . implode( ', ', array_fill( 0, count( $emails ), '%s' ) ) . ')';
				$args    = array_merge( $args, $emails );
			}
		}
		if ( $count ) {
			$sql = "SELECT COUNT(*) FROM {$m} m LEFT JOIN {$s} s ON s.member_id = m.id WHERE " . implode( ' AND ', $where );
			return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments above.
		}
		$args[] = max( 1, min( 200, (int) $limit ) );
		$sql    = "SELECT m.id, m.email, m.first_name, m.last_name, m.joined_on, m.last_signed_in_at, m.blocked_at, s.plan, s.access_state FROM {$m} m LEFT JOIN {$s} s ON s.member_id = m.id WHERE " . implode( ' AND ', $where ) . ' ORDER BY m.id DESC LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments above.
	}

	/**
	 * The member row, or null.
	 *
	 * @param int $member_id Member number.
	 */
	public static function member( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', (int) $member_id ) );
	}

	/**
	 * Account details only (FR3-76): never the goal, track, health answers, readings, diary or calculator results.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>|null
	 */
	public static function account( $member_id ) {
		global $wpdb;
		$row = self::member( $member_id );
		if ( ! $row ) {
			return null;
		}
		$sub     = LLD_Members_Billing::subscription_for( (int) $member_id );
		$methods = array( 'Email' );
		$ids     = $wpdb->get_col( $wpdb->prepare( 'SELECT provider FROM ' . LLD_Members_Stage3_Schema::table( 'identities' ) . ' WHERE member_id = %d ORDER BY provider', (int) $member_id ) );
		foreach ( (array) $ids as $provider ) {
			$methods[] = ucfirst( (string) $provider );
		}
		$pause = LLD_Members_Pause::open( (int) $member_id );
		return array(
			'id'          => (int) $row->id,
			'name'        => trim( $row->first_name . ' ' . $row->last_name ),
			'email'       => (string) $row->email,
			'methods'     => $methods,
			'joined'      => (string) $row->joined_on,
			'last_sign'   => (string) $row->last_signed_in_at,
			'plan'        => self::plan_label( $sub, (int) $member_id ),
			'status'      => self::status_label( $row, $sub, $pause ),
			'renewal'     => $sub && $sub->period_ends_on ? (string) $sub->period_ends_on : '',
			'payment'     => $sub ? self::payment_label( (string) $sub->stripe_state ) : 'No payments',
			'customer'    => $sub && $sub->stripe_customer ? (string) $sub->stripe_customer : '',
			'paying'      => $sub && '' !== (string) $sub->stripe_subscription,
			'monthly'     => $sub && 'monthly' === (string) $sub->plan,
			'pause'       => $pause,
			'test'        => in_array( strtolower( (string) $row->email ), LLD_Members_Features::test_account_emails(), true ),
			'blocked'     => ! empty( $row->blocked_at ),
			'blocked_why' => (string) $row->blocked_reason,
			'grants'      => self::grants( (int) $member_id ),
			'notes'       => self::notes( (int) $member_id ),
			'confirmed'   => ! empty( $row->email_confirmed_at ),
		);
	}

	/**
	 * @param object|null $sub       Subscription row.
	 * @param int         $member_id Member number.
	 */
	private static function plan_label( $sub, $member_id ) {
		$premium = LLD_Members_Billing::has_premium( $member_id );
		if ( ! $premium ) {
			return 'Free';
		}
		if ( self::grant_only( $sub, $member_id ) ) {
			return 'Premium (free, given by the Owner)';
		}
		return 'Premium' . ( $sub && $sub->plan ? ', ' . (string) $sub->plan : '' );
	}

	/**
	 * @param object      $row   Member.
	 * @param object|null $sub   Subscription.
	 * @param object|null $pause Open pause.
	 */
	private static function status_label( $row, $sub, $pause ) {
		if ( ! empty( $row->blocked_at ) ) {
			return 'Blocked';
		}
		if ( $pause ) {
			return 'active' === (string) $pause->status ? 'Paused until ' . (string) $pause->ends_at : 'Pause starts ' . (string) $pause->starts_at;
		}
		$state = $sub ? strtolower( (string) $sub->access_state ) : 'free';
		if ( 'cancelled' === $state ) {
			return 'Cancelling at the end of the period';
		}
		if ( in_array( $state, array( 'premium ended', 'refunded' ), true ) ) {
			return 'Cancelled';
		}
		return 'Active';
	}

	/**
	 * Payment status from Stripe's state; never card details.
	 *
	 * @param string $state stripe_state.
	 */
	private static function payment_label( $state ) {
		$labels = array(
			''                     => 'No payment problems',
			'active'               => 'Paid up',
			'past_due'             => 'Payment failed, retrying',
			'cancel_at_period_end' => 'Cancels at the end of the period',
			'ended'                => 'Ended',
		);
		return $labels[ $state ] ?? ucfirst( str_replace( '_', ' ', $state ) );
	}

	/**
	 * @param int $member_id Member number.
	 * @return object[]
	 */
	public static function notes( $member_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, admin_user_id, note, created_at FROM ' . LLD_Members_Stage3_Schema::table( 'member_notes' ) . ' WHERE member_id = %d ORDER BY id DESC', (int) $member_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 * @return object[]
	 */
	public static function grants( $member_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Stage3_Schema::table( 'premium_grants' ) . ' WHERE member_id = %d ORDER BY id DESC', (int) $member_id ) );
	}

	/**
	 * The member's grant running now, or null. Worked out from the dates on each read, so no job has to run on the
	 * end date (TC-FR3-80-AS-02).
	 *
	 * @param int $member_id Member number.
	 */
	public static function active_grant( $member_id ) {
		static $memo = array();
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return null;
		}
		if ( ! array_key_exists( $member_id, $memo ) ) {
			global $wpdb;
			$now                = gmdate( 'Y-m-d H:i:s' );
			$memo[ $member_id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Stage3_Schema::table( 'premium_grants' ) . ' WHERE member_id = %d AND removed_at IS NULL AND starts_at <= %s AND ends_at > %s ORDER BY id DESC LIMIT 1', $member_id, $now, $now ) );
		}
		return $memo[ $member_id ];
	}

	/**
	 * Filter: a running grant gives Premium, kept apart from Stripe (FR3-80).
	 *
	 * @param bool $open      Premium so far.
	 * @param int  $member_id Member number.
	 */
	public static function grant_open( $open, $member_id ) {
		if ( $open || (int) $member_id < 1 ) {
			return $open;
		}
		return null !== self::active_grant( (int) $member_id );
	}

	/**
	 * Filter: a running grant reads as Premium everywhere the access state is used (Stage 2 programme weeks, the food
	 * tracker's Premium parts), not only where Premium is worked out by the payments class.
	 *
	 * @param string $state     Stored access state.
	 * @param int    $member_id Member number.
	 */
	public static function grant_state( $state, $member_id ) {
		if ( 'premium' === strtolower( (string) $state ) || (int) $member_id < 1 ) {
			return $state;
		}
		return null !== self::active_grant( (int) $member_id ) ? 'Premium' : $state;
	}

	/**
	 * True when Premium comes only from the Owner's grant (the stored subscription is not Premium).
	 *
	 * @param object|null $sub       Subscription row.
	 * @param int         $member_id Member number.
	 */
	private static function grant_only( $sub, $member_id ) {
		if ( ! self::active_grant( $member_id ) ) {
			return false;
		}
		$stored = $sub ? strtolower( (string) $sub->access_state ) : 'free';
		return 'premium' !== $stored && ! ( 'cancelled' === $stored && $sub->period_ends_on && (string) $sub->period_ends_on >= gmdate( 'Y-m-d' ) );
	}

	// ----- Blocking (FR3-83) -----

	/**
	 * @param int $user_id WordPress user.
	 */
	public static function blocked_user( $user_id ) {
		static $memo = array();
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}
		if ( ! array_key_exists( $user_id, $memo ) ) {
			global $wpdb;
			$memo[ $user_id ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT blocked_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE user_id = %d AND blocked_at IS NOT NULL', $user_id ) );
		}
		return $memo[ $user_id ];
	}

	/**
	 * A blocked member cannot sign in (wp-login.php, XML-RPC, application passwords).
	 *
	 * @param WP_User|WP_Error|null $user User so far.
	 */
	public static function refuse_blocked( $user ) {
		if ( $user instanceof WP_User && self::blocked_user( (int) $user->ID ) ) {
			return new WP_Error( 'lld_blocked', LLD_Members_Auth::GENERIC );
		}
		return $user;
	}

	/**
	 * A blocked member is not signed in on any request: website pages, the programming interface and app keys
	 * (TC-FR3-83-AS-01). This also covers the members' own sign-in pages, which start a session directly.
	 *
	 * @param int|false $user_id User so far.
	 */
	public static function drop_blocked( $user_id ) {
		if ( $user_id && self::blocked_user( (int) $user_id ) ) {
			return false;
		}
		return $user_id;
	}

	/**
	 * Sign the member out everywhere: WordPress sessions, the Stage 2 session record and app keys.
	 *
	 * @param object $row Member row.
	 */
	private static function sign_out_everywhere( $row ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'session' ) . ' SET ended_at = %s WHERE member_id = %d AND ended_at IS NULL', $now, (int) $row->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'app_key' ) . ' SET withdrawn_at = %s WHERE member_id = %d AND withdrawn_at IS NULL', $now, (int) $row->id ) );
		if ( (int) $row->user_id > 0 ) {
			WP_Session_Tokens::get_instance( (int) $row->user_id )->destroy_all();
		}
	}

	// ----- Actions -----

	/**
	 * Run one member action for the current admin. Returns a short message or an error.
	 *
	 * @param string              $action    Action name.
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $in        Posted values: reason, note, months, ends_on, when, kind, email, code.
	 * @return string|WP_Error
	 */
	public static function run( $action, $member_id, array $in ) {
		if ( ! LLD_Members_Admin_Roles::can( $action ) ) {
			return new WP_Error( 'lld_admin_role', 'Your role can\'t do that.' );
		}
		$row = self::member( $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lld_admin_member', 'That member no longer exists.' );
		}
		if ( in_array( $action, array( 'block', 'delete', 'test' ), true ) && (int) $row->user_id > 0 && LLD_Members_Admin_Roles::is_admin_user( (int) $row->user_id ) ) {
			// An admin's own account is never blocked, deleted or marked here: the Owner could lock themself out.
			return new WP_Error( 'lld_admin_target', 'This is an admin account. Admin accounts can\'t be blocked, deleted or marked here.' );
		}
		$reason = trim( (string) ( $in['reason'] ?? '' ) );
		$method = 'act_' . $action;
		if ( ! method_exists( __CLASS__, $method ) ) {
			return new WP_Error( 'lld_admin_action', 'Unknown action.' );
		}
		if ( in_array( self::log_action( $action, $in ), LLD_Members_Admin_Log::NEED_REASON, true ) && '' === $reason ) {
			return new WP_Error( 'lld_admin_reason', 'Give a reason. It is kept in the activity log.' );
		}
		return self::$method( $row, $in, $reason );
	}

	/**
	 * The log action an admin action writes (for the reason check before anything changes).
	 *
	 * @param string $action Action.
	 * @param array  $in     Input.
	 */
	public static function log_action( $action, array $in ) {
		$map = array(
			'note'         => 'member_note',
			'resend'       => 'member_resend',
			'pause'        => 'member_pause',
			'resume'       => 'member_resume',
			'grant'        => 'premium_grant',
			'ungrant'      => 'premium_grant_remove',
			'end'          => 'membership_end',
			'test'         => ! empty( $in['on'] ) ? 'test_account_mark' : 'test_account_unmark',
			'block'        => 'member_block',
			'unblock'      => 'member_unblock',
			'delete'       => 'member_delete',
			'download'     => 'member_download_sent',
		);
		return $map[ $action ] ?? $action;
	}

	/**
	 * FR3-78: a private note. The log records that a note was added, never its text.
	 */
	private static function act_note( $row, array $in, $reason ) {
		unset( $reason );
		$note = trim( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ) );
		if ( '' === $note ) {
			return new WP_Error( 'lld_admin_note', 'Write the note first.' );
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Stage3_Schema::table( 'member_notes' ),
			array(
				'member_id'     => (int) $row->id,
				'admin_user_id' => get_current_user_id(),
				'note'          => mb_substr( $note, 0, 2000 ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		LLD_Members_Admin_Log::add( 'member_note', array( 'member' => (int) $row->id ) );
		return 'Note added.';
	}

	/**
	 * FR3-77: resend the standard sign-in code, email confirmation or welcome email. No health details.
	 */
	private static function act_resend( $row, array $in, $reason ) {
		$kind  = (string) ( $in['kind'] ?? '' );
		$email = (string) $row->email;
		if ( 'sign_in_code' === $kind ) {
			$code = LLD_Members_Email_Codes::issue( $email, 'sign_in' );
			if ( is_wp_error( $code ) ) {
				return $code;
			}
			LLD_Members_Email_Codes::send( $email, 'sign_in', $code );
		} elseif ( 'confirm' === $kind ) {
			if ( ! empty( $row->email_confirmed_at ) ) {
				return new WP_Error( 'lld_admin_resend', 'This email is already confirmed.' );
			}
			self::send_confirm_link( $row );
		} elseif ( 'welcome' === $kind ) {
			global $wpdb;
			$chosen = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT welcome FROM ' . LLD_Members_Schema::table( 'email_pref' ) . ' WHERE member_id = %d', (int) $row->id ) );
			if ( 1 !== $chosen ) {
				return new WP_Error( 'lld_admin_resend', 'This member has not chosen to get the welcome email, so it can\'t be resent.' );
			}
			$subjects = LLD_Members_Programme::mail_kinds();
			$link     = home_url( '/members/home/' );
			wp_mail( $email, $subjects['welcome'] ?? 'Welcome', LLD_Members_Programme::mail_body( 'welcome', $link, LLD_Members_Programme::unsub_link( (int) $row->id, 'welcome' ) ) );
		} else {
			return new WP_Error( 'lld_admin_resend', 'Choose which email to resend.' );
		}
		LLD_Members_Admin_Log::add( 'member_resend', array( 'member' => (int) $row->id, 'target' => $kind, 'reason' => $reason ) );
		return 'Email sent.';
	}

	/**
	 * A fresh 24-hour email confirmation link, made exactly as the Stage 2 sign-up makes it (LLD_Members_Account).
	 *
	 * @param object $row Member.
	 */
	private static function send_confirm_link( $row ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			LLD_Members_Schema::table( 'security_code' ),
			array(
				'member_id'          => (int) $row->id,
				'user_id'            => (int) $row->user_id,
				'kind'               => 'email_confirm',
				'code_hash'          => hash( 'sha256', $token ),
				'pending_token_hash' => hash( 'sha256', $token ),
				'resend_count'       => 1,
				'expires_at'         => LLD_Members_Clock::now( (int) $row->id )->modify( '+24 hours' )->format( 'Y-m-d H:i:s' ),
			)
		);
		LLD_Members_Mail::send_confirm( (string) $row->email, add_query_arg( 'token', rawurlencode( $token ), home_url( '/members/confirm/' ) ) );
	}

	/**
	 * FR3-79: pause for 1, 2 or 3 months, with the member's own pause rules (FR3-65); Stripe follows.
	 */
	private static function act_pause( $row, array $in, $reason ) {
		$months = (int) ( $in['months'] ?? 0 );
		$done   = LLD_Members_Pause::start( (int) $row->id, $months );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		LLD_Members_Admin_Log::add( 'member_pause', array( 'member' => (int) $row->id, 'before' => 'not paused', 'after' => 'paused ' . $months . ' month' . ( 1 === $months ? '' : 's' ), 'reason' => $reason ) );
		return 'Membership paused.';
	}

	/**
	 * FR3-79: resume. A pause that has not started is undone; a running one ends today and Premium returns.
	 */
	private static function act_resume( $row, array $in, $reason ) {
		unset( $in );
		$pause = LLD_Members_Pause::open( (int) $row->id );
		if ( ! $pause ) {
			return new WP_Error( 'lld_admin_resume', 'This membership is not paused.' );
		}
		if ( 'scheduled' === (string) $pause->status ) {
			$done = LLD_Members_Pause::undo( (int) $row->id );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		} else {
			$ref = (string) $pause->subscription_ref;
			if ( LLD_Members_Stripe_API::on( (int) $row->id ) && 0 === strpos( $ref, 'sub_' ) ) {
				$done = LLD_Members_Stripe_API::unpause( $ref );
				if ( is_wp_error( $done ) ) {
					return $done;
				}
			}
			global $wpdb;
			$wpdb->update(
				LLD_Members_Stage3_Schema::table( 'pauses' ),
				array(
					'status'     => 'ended',
					'ends_at'    => LLD_Members_Programme::local_today( (int) $row->id ),
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $pause->id )
			);
		}
		LLD_Members_Admin_Log::add( 'member_resume', array( 'member' => (int) $row->id, 'before' => (string) $pause->status . ' pause', 'after' => 'not paused', 'reason' => $reason ) );
		return 'Membership resumed.';
	}

	/**
	 * FR3-80: free Premium until a date, with a reason; no Stripe charge or subscription.
	 */
	private static function act_grant( $row, array $in, $reason ) {
		$ends = (string) ( $in['ends_on'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ends ) || $ends <= gmdate( 'Y-m-d' ) ) {
			return new WP_Error( 'lld_admin_grant', 'Choose an end date after today.' );
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			LLD_Members_Stage3_Schema::table( 'premium_grants' ),
			array(
				'member_id'  => (int) $row->id,
				'starts_at'  => $now,
				'ends_at'    => $ends . ' 00:00:00',
				'reason'     => sanitize_textarea_field( $reason ),
				'granted_by' => get_current_user_id(),
				'created_at' => $now,
			)
		);
		LLD_Members_Admin_Log::add( 'premium_grant', array( 'member' => (int) $row->id, 'before' => 'no grant', 'after' => 'free Premium until ' . $ends, 'reason' => $reason ) );
		return 'Free Premium given until ' . $ends . '.';
	}

	/**
	 * FR3-80: remove a running grant. The member returns to Free with the downgrade rules; nothing is deleted.
	 */
	private static function act_ungrant( $row, array $in, $reason ) {
		unset( $in );
		$grant = self::active_grant( (int) $row->id );
		if ( ! $grant ) {
			return new WP_Error( 'lld_admin_grant', 'This member has no free Premium running.' );
		}
		global $wpdb;
		$wpdb->update( LLD_Members_Stage3_Schema::table( 'premium_grants' ), array( 'removed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $grant->id ) );
		LLD_Members_Admin_Log::add( 'premium_grant_remove', array( 'member' => (int) $row->id, 'before' => 'free Premium until ' . substr( (string) $grant->ends_at, 0, 10 ), 'after' => 'no grant', 'reason' => $reason ) );
		return 'Free Premium removed.';
	}

	/**
	 * FR3-81: end Premium at the end of the paid period or now; Stripe cancels to match. No refunds here.
	 */
	private static function act_end( $row, array $in, $reason ) {
		$when = 'now' === ( $in['when'] ?? '' ) ? 'now' : 'period_end';
		$done = LLD_Members_Billing::admin_end_membership( (int) $row->id, $when );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		LLD_Members_Admin_Log::add( 'membership_end', array( 'member' => (int) $row->id, 'before' => 'Premium', 'after' => 'now' === $when ? 'ended now' : 'ends at the end of the period', 'reason' => $reason ) );
		return 'now' === $when ? 'Membership ended now.' : 'Membership ends at the end of the paid period.';
	}

	/**
	 * FR3-82: mark or unmark a test account, in the same list as the Features page.
	 */
	private static function act_test( $row, array $in, $reason ) {
		$on     = ! empty( $in['on'] );
		$emails = LLD_Members_Features::test_account_emails();
		$email  = strtolower( (string) $row->email );
		$emails = array_values( array_diff( $emails, array( $email ) ) );
		if ( $on ) {
			$emails[] = $email;
		}
		LLD_Members_Features::save_test_accounts( $emails );
		LLD_Members_Admin_Log::add( $on ? 'test_account_mark' : 'test_account_unmark', array( 'member' => (int) $row->id, 'before' => $on ? 'not a test account' : 'test account', 'after' => $on ? 'test account' : 'not a test account', 'reason' => $reason ) );
		return $on ? 'Marked as a test account.' : 'No longer a test account.';
	}

	/**
	 * FR3-83: block. Signed out at once everywhere; a paying member's billing is paused with no time limit.
	 */
	private static function act_block( $row, array $in, $reason ) {
		unset( $in );
		if ( ! empty( $row->blocked_at ) ) {
			return new WP_Error( 'lld_admin_block', 'This member is already blocked.' );
		}
		global $wpdb;
		$wpdb->update( LLD_Members_Schema::table( 'member' ), array( 'blocked_at' => gmdate( 'Y-m-d H:i:s' ), 'blocked_reason' => sanitize_textarea_field( $reason ) ), array( 'id' => (int) $row->id ) );
		self::sign_out_everywhere( $row );
		$billing = self::pause_billing( (int) $row->id );
		LLD_Members_Admin_Log::add( 'member_block', array( 'member' => (int) $row->id, 'before' => 'can sign in', 'after' => 'blocked' . ( $billing ? ', billing paused' : '' ), 'reason' => $reason ) );
		return 'Member blocked and signed out.' . ( $billing ? ' Billing is paused.' : '' );
	}

	/**
	 * FR3-83: unblock. Sign-in returns and billing restarts if the block paused it.
	 */
	private static function act_unblock( $row, array $in, $reason ) {
		unset( $in );
		if ( empty( $row->blocked_at ) ) {
			return new WP_Error( 'lld_admin_block', 'This member is not blocked.' );
		}
		global $wpdb;
		$wpdb->update( LLD_Members_Schema::table( 'member' ), array( 'blocked_at' => null, 'blocked_reason' => null ), array( 'id' => (int) $row->id ) );
		$billing = self::restart_billing( (int) $row->id );
		LLD_Members_Admin_Log::add( 'member_unblock', array( 'member' => (int) $row->id, 'before' => 'blocked', 'after' => 'can sign in' . ( $billing ? ', billing restarted' : '' ), 'reason' => $reason ) );
		return 'Member unblocked.' . ( $billing ? ' Billing has restarted.' : '' );
	}

	/**
	 * Pause a paying member's Stripe collection with no end date (the FR3-65 pause, no time limit). Not a pause of
	 * the member's own (no lld_pauses row), so their own pauses are unchanged. A member already in their own pause
	 * keeps it as it is.
	 *
	 * @param int $member_id Member number.
	 * @return bool True when billing was paused.
	 */
	private static function pause_billing( $member_id ) {
		$sub = LLD_Members_Billing::subscription_for( $member_id );
		$ref = $sub ? (string) $sub->stripe_subscription : '';
		if ( 0 !== strpos( $ref, 'sub_' ) || LLD_Members_Pause::open( $member_id ) || ! LLD_Members_Billing::paid_state( $member_id ) ) {
			return false;
		}
		if ( LLD_Members_Stripe_API::on( $member_id ) ) {
			$done = LLD_Members_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $ref ), array( 'pause_collection' => array( 'behavior' => 'void' ) ) );
			if ( is_wp_error( $done ) ) {
				LLD_Members_Admin_Security::alert_owner( 'Billing could not be paused', 'Member #' . (int) $member_id . ' is blocked, but Stripe did not pause their subscription. Pause it in the Stripe dashboard.' );
				return false;
			}
		}
		$all              = get_option( self::BLOCK_PAUSED );
		$all              = is_array( $all ) ? $all : array();
		$all[ $member_id ] = $ref;
		update_option( self::BLOCK_PAUSED, $all, false );
		return true;
	}

	/**
	 * @param int $member_id Member number.
	 * @return bool True when billing was restarted.
	 */
	private static function restart_billing( $member_id ) {
		$all = get_option( self::BLOCK_PAUSED );
		if ( ! is_array( $all ) || ! isset( $all[ $member_id ] ) ) {
			return false;
		}
		$ref = (string) $all[ $member_id ];
		unset( $all[ $member_id ] );
		update_option( self::BLOCK_PAUSED, $all, false );
		if ( LLD_Members_Stripe_API::on( $member_id ) ) {
			$done = LLD_Members_Stripe_API::unpause( $ref );
			if ( is_wp_error( $done ) ) {
				LLD_Members_Admin_Security::alert_owner( 'Billing could not be restarted', 'Member #' . (int) $member_id . ' is unblocked, but Stripe did not restart their subscription. Resume it in the Stripe dashboard.' );
				return false;
			}
		}
		return true;
	}

	/**
	 * FR3-85: email the member a link to their own data download. They sign in to download it; the admin never
	 * sees the file.
	 */
	private static function act_download( $row, array $in, $reason ) {
		unset( $in );
		wp_mail(
			(string) $row->email,
			'Your Lifeline Daily data download',
			"Hello" . ( '' !== (string) $row->first_name ? ' ' . $row->first_name : '' ) . ",\n\nYou asked for a copy of your Lifeline Daily data. Sign in and download it here:\n\n" . home_url( '/members/data/' ) . "\n\nOnly you can download it, after signing in. If you didn't ask for this, you can ignore this email.\n"
		);
		LLD_Members_Admin_Log::add( 'member_download_sent', array( 'member' => (int) $row->id, 'reason' => $reason ) );
		return 'Download link sent to the member.';
	}

	/**
	 * FR3-84: delete with exactly the member's own erasure, after the typed email, a reason and a fresh code. At
	 * most 5 an hour per admin (NFR3-21).
	 */
	private static function act_delete( $row, array $in, $reason ) {
		if ( strtolower( trim( (string) ( $in['email'] ?? '' ) ) ) !== strtolower( (string) $row->email ) ) {
			return new WP_Error( 'lld_admin_delete', 'Type the member\'s email exactly to confirm.' );
		}
		$limit = LLD_Members_Admin_Security::may_delete();
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$code = LLD_Members_Admin_Security::fresh_code_ok( (string) ( $in['code'] ?? '' ) );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		$member_id = (int) $row->id;
		// The member's own closing sequence (LLD_Members_Account::delete_screen), without signing anyone out here.
		self::sign_out_everywhere( $row );
		$cancel = LLD_Members_Stripe::cancel_member( $member_id );
		LLD_Members_Mail::send_account_deleted( (string) $row->email, (string) $row->first_name );
		LLD_Members_Erasure::delete_account( $member_id, true );
		if ( is_wp_error( $cancel ) ) {
			$refs = (array) $cancel->get_error_data();
			LLD_Members_Mail::send_cancel_alert( $member_id, isset( $refs['subscription'] ) ? (string) $refs['subscription'] : '', isset( $refs['customer'] ) ? (string) $refs['customer'] : '' );
		}
		LLD_Members_Admin_Log::add( 'member_delete', array( 'member' => $member_id, 'before' => 'account', 'after' => 'deleted', 'reason' => $reason ) );
		return 'Member #' . $member_id . ' deleted.' . ( is_wp_error( $cancel ) ? ' Stripe did not confirm the cancellation: check the Stripe dashboard.' : '' );
	}

	/**
	 * Account deletion: notes and grants go with the Stage 3 suffix loop; the block's billing note goes here.
	 *
	 * @param int $member_id Member number.
	 */
	public static function erase_account( $member_id ) {
		$all = get_option( self::BLOCK_PAUSED );
		if ( is_array( $all ) && isset( $all[ (int) $member_id ] ) ) {
			unset( $all[ (int) $member_id ] );
			update_option( self::BLOCK_PAUSED, $all, false );
		}
	}

	/**
	 * The Owner's link to this member's customer page in the Stripe dashboard (FR3-81 AS-03).
	 *
	 * @param string $customer Stripe customer.
	 */
	public static function stripe_link( $customer ) {
		if ( 0 !== strpos( $customer, 'cus_' ) ) {
			return '';
		}
		$test = 'live' !== LLD_Members_Stripe_API::mode();
		return 'https://dashboard.stripe.com/' . ( $test ? 'test/' : '' ) . 'customers/' . rawurlencode( $customer );
	}
}
