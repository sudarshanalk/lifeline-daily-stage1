<?php
/**
 * Member reminders (P3-85, FR3-56). Free: one active reminder; Premium: as many as they like.
 * Emails never carry health details (FR3-56 AS-02). Sending reuses the five-minute programme tick.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create gates, due checks and generic reminder emails.
 */
class LLD_Members_Reminders {

	const TICK_HOOK = 'lld_members_programme_tick';

	/**
	 * Generic email lines. No health numbers, no readings, no weights (FR3-56 AS-02).
	 * Placeholders: {first_name}, {link}.
	 */
	const MESSAGES = array(
		'email.subject'             => 'A reminder from Lifeline Daily',
		'email.body.log'            => "Hi {first_name},\n\nThis is your reminder to log on Lifeline Daily.\n\n{link}\n\n— Lifeline Daily",
		'email.body.workout'        => "Hi {first_name},\n\nThis is your reminder for a workout on Lifeline Daily.\n\n{link}\n\n— Lifeline Daily",
		'email.body.weigh_in'       => "Hi {first_name},\n\nThis is your weigh-in reminder on Lifeline Daily.\n\n{link}\n\n— Lifeline Daily",
		'email.body.check_in'       => "Hi {first_name},\n\nThis is your check-in reminder on Lifeline Daily.\n\n{link}\n\n— Lifeline Daily",
		// R-HW-REM-01 (P3-35): every Healthy Weight reminder says only the signed T-HW-REM-01 line.
		'email.body.hw_step'        => "Hi {first_name},\n\nYour next step is ready on Lifeline Daily.\n\n{link}\n\n— Lifeline Daily",
		'email.body.hw_snack'       => "Hi {first_name},\n\nYour next step is ready on Lifeline Daily.\n\n{link}\n\n— Lifeline Daily",
		'free.second'               => 'Free includes one reminder. Premium has as many as you like.',
		'hidden.weigh_in'           => 'Not available.',
	);

	/** The Healthy Weight reminder types, by R-HW-REM-01 kind (P3-35). */
	const HW_TYPES = array(
		'step'  => 'hw_step',
		'snack' => 'hw_snack',
	);

	/** Types the eating-disorder flag hides (Part B reminders column). */
	const ED_HIDDEN = array( 'weigh_in' );

	public static function boot() {
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Whether a Free member may create another reminder (FR3-56 AS-03). Premium always may.
	 * Existing extras stay stored but paused when Free (FR3-68); create of a new one is refused.
	 *
	 * @param int                            $member_id Member number.
	 * @param array<int,array<string,mixed>> $existing  Current reminder rows (any active).
	 * @param bool                           $premium   Premium now (or access_tiers off).
	 * @return string|null Refusal message, or null when allowed.
	 */
	public static function gate_create( $member_id, array $existing, $premium ) {
		unset( $member_id );
		if ( $premium ) {
			return null;
		}
		$active = 0;
		foreach ( $existing as $row ) {
			if ( ! empty( $row['active'] ) && empty( $row['deleted_at'] ) ) {
				++$active;
			}
		}
		if ( $active >= LLD_Members_Access_Tiers::FREE_REMINDERS ) {
			return self::MESSAGES['free.second'];
		}
		return null;
	}

	/**
	 * Safety: refuse weigh_in for eating-disorder members.
	 *
	 * @param string             $type  Reminder type.
	 * @param array<string,bool> $flags Flags from the safety matrix.
	 * @return string|null Refusal, or null.
	 */
	public static function gate_type( $type, array $flags = array() ) {
		if ( ! empty( $flags['eating_disorder'] ) && in_array( (string) $type, self::ED_HIDDEN, true ) ) {
			return self::MESSAGES['hidden.weigh_in'];
		}
		return null;
	}

	/**
	 * Normalise a create/patch payload.
	 *
	 * @param array<string,mixed> $params Raw fields.
	 * @param array<string,bool>  $flags  Safety flags.
	 * @return array<string,mixed>|array{error:string,status?:int}
	 */
	public static function for_save( array $params, array $flags = array() ) {
		$type = isset( $params['reminder_type'] ) ? (string) $params['reminder_type'] : '';
		$deny = self::gate_type( $type, $flags );
		if ( null !== $deny ) {
			return array( 'error' => $deny, 'status' => 403 );
		}
		$days = isset( $params['days'] ) ? self::clean_days( $params['days'] ) : '';
		if ( '' === $days ) {
			return array( 'error' => 'Choose at least one day.', 'status' => 422 );
		}
		$params['days'] = $days;
		if ( ! isset( $params['channel'] ) || '' === (string) $params['channel'] ) {
			$params['channel'] = 'email';
		}
		if ( ! array_key_exists( 'active', $params ) ) {
			$params['active'] = 1;
		}
		return $params;
	}

	/**
	 * Days string: Mon,Tue,… or JSON list → canonical "Mon,Tue".
	 *
	 * @param mixed $raw Days.
	 */
	public static function clean_days( $raw ) {
		$allowed = array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' );
		if ( is_array( $raw ) ) {
			$list = $raw;
		} else {
			$text = trim( (string) $raw );
			if ( '' !== $text && '[' === $text[0] ) {
				$decoded = json_decode( $text, true );
				$list    = is_array( $decoded ) ? $decoded : array();
			} else {
				$list = preg_split( '/[,\s]+/', $text ) ?: array();
			}
		}
		$out = array();
		foreach ( $list as $day ) {
			$d = ucfirst( strtolower( substr( (string) $day, 0, 3 ) ) );
			if ( in_array( $d, $allowed, true ) && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		return implode( ',', $out );
	}

	/**
	 * Whether this reminder should fire now in the member's time zone.
	 *
	 * @param array<string,mixed> $row       Reminder row.
	 * @param DateTimeImmutable   $local_now Member-local now.
	 * @param int                 $window_s  Due window in seconds (tick interval).
	 */
	public static function is_due( array $row, DateTimeImmutable $local_now, $window_s = 300 ) {
		if ( empty( $row['active'] ) || ! empty( $row['deleted_at'] ) ) {
			return false;
		}
		$days = array_filter( explode( ',', (string) ( $row['days'] ?? '' ) ) );
		$dow  = $local_now->format( 'D' ); // Mon …
		if ( $days && ! in_array( $dow, $days, true ) ) {
			return false;
		}
		$time = (string) ( $row['time_local'] ?? '' );
		if ( ! preg_match( '/^(\d{2}):(\d{2})/', $time, $m ) ) {
			return false;
		}
		$due  = $local_now->setTime( (int) $m[1], (int) $m[2], 0 );
		$diff = $local_now->getTimestamp() - $due->getTimestamp();
		return $diff >= 0 && $diff < (int) $window_s;
	}

	/**
	 * Build the email subject and body. Never interpolates health data.
	 *
	 * @param string $type       Reminder type.
	 * @param string $first_name First name (or empty).
	 * @param string $link       Dashboard link.
	 * @return array{subject:string,body:string}
	 */
	public static function email_copy( $type, $first_name, $link ) {
		$key  = 'email.body.' . $type;
		$body = isset( self::MESSAGES[ $key ] ) ? self::MESSAGES[ $key ] : self::MESSAGES['email.body.log'];
		$name = '' !== trim( (string) $first_name ) ? trim( (string) $first_name ) : 'there';
		$fill = array(
			'{first_name}' => $name,
			'{link}'       => (string) $link,
		);
		return array(
			'subject' => self::MESSAGES['email.subject'],
			'body'    => strtr( $body, $fill ),
		);
	}

	/**
	 * True when the email text contains none of the forbidden health tokens (test helper).
	 *
	 * @param string $text Email body.
	 */
	public static function email_is_safe( $text ) {
		// Reject bodies that look like they embedded a reading or weight figure.
		if ( preg_match( '/\b\d+(\.\d+)?\s*(kg|lb|mmHg|mmol\/?L|BMI)\b/i', $text ) ) {
			return false;
		}
		if ( preg_match( '/\b(systolic|diastolic|glucose|blood pressure|calorie)\b/i', $text ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Ids that should run for this member right now (Access_Tiers + due).
	 *
	 * @param array<int,array<string,mixed>> $rows     Reminder rows.
	 * @param bool                           $premium  Premium / unlimited.
	 * @param DateTimeImmutable              $local    Local now.
	 * @return int[]
	 */
	public static function due_ids( array $rows, $premium, DateTimeImmutable $local ) {
		$running = LLD_Members_Access_Tiers::running_reminders( $rows, $premium );
		$due     = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id && in_array( $id, $running, true ) && self::is_due( $row, $local ) ) {
				$due[] = $id;
			}
		}
		return $due;
	}

	/**
	 * Five-minute tick: send due email reminders. No health values in the message.
	 */
	public static function tick() {
		if ( ! class_exists( 'LLD_Members_Features' ) || ! LLD_Members_Features::enabled( 'reminders', 0 ) ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Stage3_Schema::table( 'reminders' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 AND deleted_at IS NULL AND channel = 'email'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) || ! $rows ) {
			return;
		}
		$by_member = array();
		foreach ( $rows as $row ) {
			$by_member[ (int) $row['member_id'] ][] = $row;
		}
		foreach ( $by_member as $member_id => $list ) {
			self::send_for_member( $member_id, $list );
		}
	}

	/**
	 * @param int                            $member_id Member number.
	 * @param array<int,array<string,mixed>> $list      That member's active email reminders.
	 */
	private static function send_for_member( $member_id, array $list ) {
		$premium = class_exists( 'LLD_Members_Access_Tiers' )
			? LLD_Members_Access_Tiers::allows( $member_id, 'more_reminders' )
			: true;
		$tz_name = 'UTC';
		if ( function_exists( 'lld_members_member_timezone' ) ) {
			$tz_name = lld_members_member_timezone( $member_id );
		} elseif ( class_exists( 'LLD_Members_Clock' ) ) {
			global $wpdb;
			$tz_name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		}
		try {
			$tz = new DateTimeZone( $tz_name ? $tz_name : 'UTC' );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$local = class_exists( 'LLD_Members_Clock' )
			? LLD_Members_Clock::now( $member_id )->setTimezone( $tz )
			: new DateTimeImmutable( 'now', $tz );
		$due = self::due_ids( $list, $premium, $local );
		if ( ! $due ) {
			return;
		}
		global $wpdb;
		$member = $wpdb->get_row( $wpdb->prepare( 'SELECT email, first_name FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! $member || ! is_email( (string) $member->email ) ) {
			return;
		}
		$link = home_url( '/members/home/' );
		$date = $local->format( 'Y-m-d' );
		$hw_held = null;
		foreach ( $list as $row ) {
			$id = (int) $row['id'];
			if ( ! in_array( $id, $due, true ) ) {
				continue;
			}
			if ( in_array( (string) $row['reminder_type'], self::HW_TYPES, true ) ) {
				// P3-35: none while R-HW-SAFE-01 stops the programme or R-HW-REM-01 is paused (low mood).
				if ( null === $hw_held ) {
					$hw_held = class_exists( 'LLD_Members_HW_Programme' ) && LLD_Members_HW_Programme::reminders_held( $member_id );
				}
				if ( $hw_held ) {
					continue;
				}
			}
			if ( ! self::claim( $member_id, 'reminder:' . $id, $date ) ) {
				continue;
			}
			$copy = self::email_copy( (string) $row['reminder_type'], (string) $member->first_name, $link );
			if ( ! self::email_is_safe( $copy['body'] ) ) {
				continue; // never send a body that somehow gained a health word
			}
			wp_mail( (string) $member->email, $copy['subject'], $copy['body'] );
		}
	}

	/**
	 * Claim one send for today (mail_sent table), same pattern as Stage 2 programme emails.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Claim key.
	 * @param string $date      Local date Y-m-d.
	 */
	private static function claim( $member_id, $key, $date ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'mail_sent' );
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table} (member_id, claim_key, sent_on) VALUES (%d, %s, %s) ON DUPLICATE KEY UPDATE sent_on = IF(sent_on = VALUES(sent_on), sent_on, VALUES(sent_on))",
			$member_id,
			$key,
			$date
		);
		return (int) $wpdb->query( $sql ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * R-HW-REM-01 (P3-35, FR3-98 AS-09): the Healthy Weight reminders at the times the answers give. Existing ones move to
	 * the new times; new ones are made only when the member asked for reminders ($create); a kind the rule no longer
	 * gives (or "ask for the wake-up time first") is paused. Free (Q163); the Free one-reminder limit still applies when
	 * they are sent. No health detail is ever in a reminder: the text is the T-HW-REM-01 line.
	 *
	 * @param int   $member_id Member number.
	 * @param array $answers   LLD_Members_HW_Answers::engine(): work, snack_time, wake_time.
	 * @param bool  $create    Make reminders that do not exist yet.
	 * @return array{times:array,ask_wake_up:bool} The rule's answer.
	 */
	public static function sync_hw( $member_id, array $answers, $create = false ) {
		$member_id = (int) $member_id;
		$r         = LLD_Members_HW_Rules::reminder_times(
			array(
				'work'       => isset( $answers['work'] ) ? $answers['work'] : null,
				'snack_time' => isset( $answers['snack_time'] ) ? $answers['snack_time'] : null,
				'wake_time'  => isset( $answers['wake_time'] ) ? $answers['wake_time'] : null,
				'bedtime'    => isset( $answers['bedtime'] ) ? $answers['bedtime'] : null,
			)
		);
		$want = array();
		foreach ( $r['reminders'] as $x ) {
			$want[ self::HW_TYPES[ $x['kind'] ] ] = $x['time'] . ':00';
		}
		$have = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'reminders', $member_id, array(), '', 50 ) as $row ) {
			if ( empty( $row['deleted_at'] ) && in_array( (string) $row['reminder_type'], self::HW_TYPES, true ) ) {
				$have[ (string) $row['reminder_type'] ] = $row;
			}
		}
		foreach ( self::HW_TYPES as $type ) {
			if ( isset( $want[ $type ] ) && isset( $have[ $type ] ) ) {
				LLD_Members_Stage3_Data::update( 'reminders', $member_id, (int) $have[ $type ]['id'], array( 'time_local' => $want[ $type ] ) );
			} elseif ( isset( $want[ $type ] ) && $create ) {
				LLD_Members_Stage3_Data::insert(
					'reminders',
					$member_id,
					array(
						'reminder_type' => $type,
						'channel'       => 'email',
						'time_local'    => $want[ $type ],
						'days'          => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
						'active'        => 1,
					)
				);
			} elseif ( ! isset( $want[ $type ] ) && isset( $have[ $type ] ) ) {
				LLD_Members_Stage3_Data::update( 'reminders', $member_id, (int) $have[ $type ]['id'], array( 'active' => 0 ) );
			}
		}
		return array(
			'times'       => $r['reminders'],
			'ask_wake_up' => $r['ask_wake_up'],
		);
	}

	/**
	 * Carry Stage 2 email_pref daily_reminder into a first Stage 3 reminder row when none exist yet.
	 *
	 * @param int $member_id Member number.
	 * @return int New id, or 0.
	 */
	public static function migrate_stage2_pref( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$existing  = LLD_Members_Stage3_Data::rows( 'reminders', $member_id, array(), '', 1 );
		if ( $existing ) {
			return 0;
		}
		$pref = $wpdb->get_row( $wpdb->prepare( 'SELECT daily_reminder, reminder_time FROM ' . LLD_Members_Schema::table( 'email_pref' ) . ' WHERE member_id = %d', $member_id ) );
		if ( ! $pref || ! (int) $pref->daily_reminder ) {
			return 0;
		}
		$time = $pref->reminder_time ? substr( (string) $pref->reminder_time, 0, 8 ) : '08:00:00';
		if ( 5 === strlen( $time ) ) {
			$time .= ':00';
		}
		return LLD_Members_Stage3_Data::insert(
			'reminders',
			$member_id,
			array(
				'reminder_type' => 'log',
				'channel'       => 'email',
				'time_local'    => $time,
				'days'          => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
				'active'        => 1,
			),
			array()
		);
	}
}
