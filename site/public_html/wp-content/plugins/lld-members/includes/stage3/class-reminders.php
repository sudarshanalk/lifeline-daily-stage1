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
		'free.second'               => 'Free includes one reminder. Premium has as many as you like.',
		'hidden.weigh_in'           => 'Not available.',
	);

	/** Types the eating-disorder flag hides (Part B reminders column). */
	const ED_HIDDEN = array( 'weigh_in' );

	/**
	 * Hub chooser types (FR3-56). Healthy Weight step/snack stay on their own path.
	 *
	 * @var array<string,string>
	 */
	const HUB_TYPES = array(
		'log'      => 'Log',
		'workout'  => 'Work out',
		'weigh_in' => 'Weigh in',
	);

	/**
	 * Channels the Notifications tab offers.
	 *
	 * @var array<string,string>
	 */
	const HUB_CHANNELS = array(
		'email'   => 'Email',
		'browser' => 'Browser',
	);

	/** Day codes for the hub checkboxes. */
	const HUB_DAYS = array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' );

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
		foreach ( $list as $row ) {
			$id = (int) $row['id'];
			if ( ! in_array( $id, $due, true ) ) {
				continue;
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

	/**
	 * Safety flags for the hub and API (weigh-in hidden for eating-disorder).
	 *
	 * @param int $member_id Member number.
	 * @return array<string,bool>
	 */
	public static function hub_flags( $member_id ) {
		$flags = array(
			'eating_disorder' => false,
			'hide_weight'     => false,
		);
		if ( class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			$applies                  = LLD_Members_Stage3_Safety::applies( $member_id, 'reminders' );
			$flags['eating_disorder'] = in_array( 'eating_disorder', isset( $applies['situations'] ) ? $applies['situations'] : array(), true );
			$flags['hide_weight']     = in_array( 'reminder:weigh_in', isset( $applies['hidden'] ) ? $applies['hidden'] : array(), true )
				|| in_array( 'share:weight_tick', isset( $applies['hidden'] ) ? $applies['hidden'] : array(), true );
		}
		return $flags;
	}

	/**
	 * Notifications-tab markup: list, turn off, and add (P3-36 B4).
	 *
	 * @param int $member_id Member number.
	 */
	public static function hub_html( $member_id ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! class_exists( 'LLD_Members_Stage3_Data' ) ) {
			return '';
		}
		if ( class_exists( 'LLD_Members_Features' ) && ! LLD_Members_Features::enabled( 'reminders', $member_id ) ) {
			return '';
		}
		$rows   = LLD_Members_Stage3_Data::rows( 'reminders', $member_id, array(), '', 50 );
		$flags  = self::hub_flags( $member_id );
		$action = esc_url( home_url( '/members/settings/?section=notifications' ) );
		$html  = '<div class="lld-reminders-hub" data-lld-reminders>';
		$html .= '<h3>Reminders</h3>';

		if ( $rows ) {
			$html .= '<form method="post" action="' . $action . '" class="lld-form lld-reminders-list">';
			$html .= wp_nonce_field( 'lld_reminders_save', 'lld_reminders_nonce', true, false );
			$html .= '<input type="hidden" name="lld_reminders_action" value="update">';
			$html .= '<ul class="lld-reminder-rows">';
			foreach ( $rows as $row ) {
				$id    = (int) $row['id'];
				$type  = (string) $row['reminder_type'];
				$label = isset( self::HUB_TYPES[ $type ] ) ? self::HUB_TYPES[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
				$time  = substr( (string) $row['time_local'], 0, 5 );
				$days  = array_filter( explode( ',', (string) $row['days'] ) );
				$on    = ! empty( $row['active'] );
				$html .= '<li class="lld-reminder-row">';
				$html .= '<p class="lld-reminder-title">' . esc_html( $label );
				if ( isset( self::HUB_CHANNELS[ (string) $row['channel'] ] ) ) {
					$html .= ' · ' . esc_html( self::HUB_CHANNELS[ (string) $row['channel'] ] );
				}
				$html .= '</p>';
				$html .= '<label class="lld-check"><input type="checkbox" name="lld_reminder_active[' . $id . ']" value="1"' . checked( $on, true, false ) . '> On</label>';
				$html .= '<p class="lld-field"><label for="lld_reminder_time_' . $id . '">Time</label>';
				$html .= '<input id="lld_reminder_time_' . $id . '" type="time" name="lld_reminder_time[' . $id . ']" value="' . esc_attr( $time ) . '" required></p>';
				$html .= '<fieldset class="lld-reminder-days"><legend class="lld-sr">Days</legend>';
				foreach ( self::HUB_DAYS as $day ) {
					$html .= '<label class="lld-check"><input type="checkbox" name="lld_reminder_days[' . $id . '][]" value="' . esc_attr( $day ) . '"' . checked( in_array( $day, $days, true ), true, false ) . '> ' . esc_html( $day ) . '</label>';
				}
				$html .= '</fieldset></li>';
			}
			$html .= '</ul>';
			$html .= lld_button( array( 'label' => 'Save', 'type' => 'submit' ) );
			$html .= '</form>';
		}

		$premium = class_exists( 'LLD_Members_Access_Tiers' ) ? LLD_Members_Access_Tiers::allows( $member_id, 'more_reminders' ) : true;
		$limit   = self::gate_create( $member_id, $rows, $premium );

		$html .= '<form method="post" action="' . $action . '" class="lld-form lld-reminders-add">';
		$html .= wp_nonce_field( 'lld_reminders_save', 'lld_reminders_nonce', true, false );
		$html .= '<input type="hidden" name="lld_reminders_action" value="add">';
		if ( null !== $limit ) {
			$html .= '<p class="lld-muted">' . esc_html( $limit ) . '</p>';
		}
		$html .= '<p class="lld-field"><label for="lld_reminder_type">Type</label><select id="lld_reminder_type" name="lld_reminder_type" required>';
		foreach ( self::HUB_TYPES as $value => $label ) {
			if ( 'weigh_in' === $value && ( ! empty( $flags['eating_disorder'] ) || ! empty( $flags['hide_weight'] ) ) ) {
				continue;
			}
			$html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></p>';
		$html .= '<p class="lld-field"><label for="lld_reminder_channel">Channel</label><select id="lld_reminder_channel" name="lld_reminder_channel" required>';
		foreach ( self::HUB_CHANNELS as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></p>';
		$html .= '<p class="lld-field"><label for="lld_reminder_time_new">Time</label>';
		$html .= '<input id="lld_reminder_time_new" type="time" name="lld_reminder_time_new" value="08:00" required></p>';
		$html .= '<fieldset class="lld-reminder-days"><legend>Days</legend>';
		foreach ( self::HUB_DAYS as $day ) {
			$html .= '<label class="lld-check"><input type="checkbox" name="lld_reminder_days_new[]" value="' . esc_attr( $day ) . '" checked> ' . esc_html( $day ) . '</label>';
		}
		$html .= '</fieldset>';
		$html .= lld_button( array( 'label' => 'Save', 'type' => 'submit' ) );
		$html .= '</form></div>';
		return $html;
	}

	/**
	 * Save hub reminder posts (add or update). Never deletes rows (FR3-68).
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function save_hub( $member_id ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! class_exists( 'LLD_Members_Stage3_Data' ) ) {
			return new WP_Error( 'lld_reminders', 'Reminders are not available.' );
		}
		if ( class_exists( 'LLD_Members_Features' ) && ! LLD_Members_Features::enabled( 'reminders', $member_id ) ) {
			return new WP_Error( 'lld_reminders', 'Reminders are not available.' );
		}
		$action = isset( $_POST['lld_reminders_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_reminders_action'] ) ) : '';
		if ( 'add' === $action ) {
			return self::save_hub_add( $member_id );
		}
		if ( 'update' === $action ) {
			return self::save_hub_update( $member_id );
		}
		return new WP_Error( 'lld_reminders', 'Choose a reminder action.' );
	}

	/**
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	private static function save_hub_add( $member_id ) {
		$flags  = self::hub_flags( $member_id );
		$params = array(
			'reminder_type' => isset( $_POST['lld_reminder_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_reminder_type'] ) ) : '',
			'channel'       => isset( $_POST['lld_reminder_channel'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_reminder_channel'] ) ) : 'email',
			'time_local'    => isset( $_POST['lld_reminder_time_new'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['lld_reminder_time_new'] ) ) : '',
			'days'          => isset( $_POST['lld_reminder_days_new'] ) ? wp_unslash( $_POST['lld_reminder_days_new'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in for_save.
			'active'        => 1,
		);
		if ( ! isset( self::HUB_TYPES[ $params['reminder_type'] ] ) ) {
			return new WP_Error( 'lld_reminders', 'Choose a reminder type.' );
		}
		if ( ! isset( self::HUB_CHANNELS[ $params['channel'] ] ) ) {
			return new WP_Error( 'lld_reminders', 'Choose a channel.' );
		}
		$params['time_local'] = self::clean_time( $params['time_local'] );
		if ( '' === $params['time_local'] ) {
			return new WP_Error( 'lld_reminders', 'Choose a time.' );
		}
		$filled = self::for_save( $params, $flags );
		if ( isset( $filled['error'] ) ) {
			return new WP_Error( 'lld_reminders', (string) $filled['error'] );
		}
		$existing = LLD_Members_Stage3_Data::rows( 'reminders', $member_id, array(), '', 50 );
		$premium  = class_exists( 'LLD_Members_Access_Tiers' ) ? LLD_Members_Access_Tiers::allows( $member_id, 'more_reminders' ) : true;
		$denied   = self::gate_create( $member_id, $existing, $premium );
		if ( null !== $denied ) {
			return new WP_Error( 'lld_reminders', $denied );
		}
		$id = LLD_Members_Stage3_Data::insert( 'reminders', $member_id, $filled, array() );
		return $id > 0 ? true : new WP_Error( 'lld_reminders', 'Could not save that reminder.' );
	}

	/**
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	private static function save_hub_update( $member_id ) {
		$rows = LLD_Members_Stage3_Data::rows( 'reminders', $member_id, array(), '', 50 );
		if ( ! $rows ) {
			return true;
		}
		$times = isset( $_POST['lld_reminder_time'] ) && is_array( $_POST['lld_reminder_time'] ) ? wp_unslash( $_POST['lld_reminder_time'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$days  = isset( $_POST['lld_reminder_days'] ) && is_array( $_POST['lld_reminder_days'] ) ? wp_unslash( $_POST['lld_reminder_days'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ons   = isset( $_POST['lld_reminder_active'] ) && is_array( $_POST['lld_reminder_active'] ) ? wp_unslash( $_POST['lld_reminder_active'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( $id < 1 ) {
				continue;
			}
			$time = self::clean_time( isset( $times[ $id ] ) ? (string) $times[ $id ] : (string) $row['time_local'] );
			if ( '' === $time ) {
				return new WP_Error( 'lld_reminders', 'Choose a time.' );
			}
			$day_raw = isset( $days[ $id ] ) ? $days[ $id ] : array();
			$clean   = self::clean_days( $day_raw );
			if ( '' === $clean ) {
				return new WP_Error( 'lld_reminders', 'Choose at least one day.' );
			}
			$active = ! empty( $ons[ $id ] ) ? 1 : 0;
			LLD_Members_Stage3_Data::update(
				'reminders',
				$member_id,
				$id,
				array(
					'time_local' => $time,
					'days'       => $clean,
					'active'     => $active,
				),
				array()
			);
		}
		return true;
	}

	/**
	 * Normalise HH:MM or HH:MM:SS to HH:MM:SS for TIME columns.
	 *
	 * @param string $raw Posted time.
	 */
	private static function clean_time( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m ) ) {
			$h = (int) $m[1];
			$i = (int) $m[2];
			$s = isset( $m[3] ) ? (int) $m[3] : 0;
			if ( $h >= 0 && $h <= 23 && $i >= 0 && $i <= 59 && $s >= 0 && $s <= 59 ) {
				return sprintf( '%02d:%02d:%02d', $h, $i, $s );
			}
		}
		return '';
	}
}
