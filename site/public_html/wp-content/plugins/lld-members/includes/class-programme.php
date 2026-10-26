<?php
/**
 * The four-week programme: days, enrolments, check-ins, weekly plans, weekly reviews and programme emails.
 *
 * Timing follows Data Model Appendix T3. Safety (section 8.2) runs before access (section 8.3), and access
 * runs before matching. Emails carry no health details (FR2-12).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Track progression for one member.
 */
class LLD_Members_Programme {

	const DAYS          = 28;
	const TICK_HOOK     = 'lld_members_programme_tick';
	const TICK_SCHEDULE = 'lld_five_minutes';
	const TICK_SECONDS  = 300;
	const RULE_VERSION  = '1';

	/**
	 * Members whose enrolment has been brought up to date in this request.
	 *
	 * @var array<int,bool>
	 */
	private static $settled = array();

	/**
	 * Approved activities per track, read once per request.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private static $track_items = array();

	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule_tick' ) );
	}

	/**
	 * A five-minute schedule, so a reminder lands within 10 minutes of the chosen time (FR2-12-AS-01).
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function schedules( $schedules ) {
		$schedules[ self::TICK_SCHEDULE ] = array(
			'interval' => self::TICK_SECONDS,
			'display'  => 'Every 5 minutes',
		);
		return $schedules;
	}

	public static function schedule_tick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 60, self::TICK_SCHEDULE, self::TICK_HOOK );
		}
	}

	/**
	 * The tick event as WordPress holds it: whether it is scheduled and how often it repeats.
	 *
	 * @return array{scheduled:bool,schedule:string,interval:int}
	 */
	public static function tick_schedule() {
		self::schedule_tick();
		$next     = wp_next_scheduled( self::TICK_HOOK );
		$schedule = $next ? (string) wp_get_schedule( self::TICK_HOOK ) : '';
		$all      = wp_get_schedules();
		return array(
			'scheduled' => (bool) $next,
			'schedule'  => $schedule,
			'interval'  => isset( $all[ $schedule ]['interval'] ) ? (int) $all[ $schedule ]['interval'] : 0,
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Emails (EM-04 to EM-08). Notice and link only.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Opt-in emails. Transactional mail never uses this list.
	 *
	 * @return array<string,string>
	 */
	public static function mail_kinds() {
		return array(
			'welcome'        => 'Welcome',
			'daily_reminder' => "Today's activity is ready",
			'weekly_review'  => 'Your weekly review is ready',
			'nudges'         => 'Pick up where you left off',
			'end_of_track'   => 'You finished a track',
		);
	}

	/**
	 * @param string $kind  Purpose.
	 * @param string $link  Signed-in page.
	 * @param string $unsub One-click unsubscribe link.
	 */
	public static function mail_body( $kind, $link, $unsub ) {
		$lines = array(
			'welcome'        => 'Welcome to Lifeline Daily. What to do first:',
			'daily_reminder' => "Today's activity is ready.",
			'weekly_review'  => 'Your weekly review is ready.',
			'nudges'         => 'Pick up where you left off.',
			'end_of_track'   => 'You finished a track. A next step is ready.',
		);
		$line  = isset( $lines[ $kind ] ) ? $lines[ $kind ] : 'Lifeline Daily.';
		return $line . "\n\n" . $link . "\n\n" . $unsub . "\n";
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Local dates and time zones (T3 rules 1 and 2)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * @param string $name IANA zone name.
	 */
	public static function safe_zone( $name ) {
		try {
			return new DateTimeZone( '' !== (string) $name ? (string) $name : 'UTC' );
		} catch ( Exception $failed ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * @param DateTimeImmutable $now  A moment.
	 * @param string            $zone Zone name.
	 */
	public static function date_in( DateTimeImmutable $now, $zone ) {
		return $now->setTimezone( self::safe_zone( $zone ) )->format( 'Y-m-d' );
	}

	/**
	 * T3 rule 2. A change of zone applies from the member's next local date: while the old zone's date is still the
	 * date of the change, that date stands. Afterwards the new zone's date applies, so no date is counted twice.
	 *
	 * @param DateTimeImmutable $now         The moment, from the member's clock.
	 * @param string            $zone_now    Zone chosen last.
	 * @param string|null       $zone_before Zone in force before the change, while the change waits.
	 * @param string|null       $changed_on  Local date of the change, in the old zone.
	 */
	public static function zone_date( DateTimeImmutable $now, $zone_now, $zone_before = null, $changed_on = null ) {
		if ( $zone_before && $changed_on && self::date_in( $now, $zone_before ) === (string) $changed_on ) {
			return (string) $changed_on;
		}
		return self::date_in( $now, $zone_now );
	}

	/**
	 * The zone whose clock applies now: the old zone while its local date is still the date of the change.
	 *
	 * @param DateTimeImmutable    $now   The moment.
	 * @param array<string,string> $state now, before, on.
	 */
	private static function zone_in_force( DateTimeImmutable $now, $state ) {
		if ( ! empty( $state['before'] ) && ! empty( $state['on'] ) && self::date_in( $now, $state['before'] ) === $state['on'] ) {
			return $state['before'];
		}
		return $state['now'];
	}

	/**
	 * The member's zone record. The first read stores the zone in force. A later change is stored with the zone
	 * before it and the local date it was made on, so it takes effect from the next local date.
	 *
	 * @param int $member_id Member number.
	 * @return array{now:string,before:string,on:string}
	 */
	private static function zone_state( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$chosen    = $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! is_string( $chosen ) ) {
			return array( 'now' => 'UTC', 'before' => '', 'on' => '' );
		}
		$chosen = '' !== $chosen ? $chosen : 'UTC';
		$table  = LLD_Members_Schema::table( 'zone_change' );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT zone_now, zone_before, changed_on FROM {$table} WHERE member_id = %d", $member_id ) );
		if ( ! $row ) {
			$wpdb->replace( $table, array( 'member_id' => $member_id, 'zone_now' => $chosen ), array( '%d', '%s' ) );
			return array( 'now' => $chosen, 'before' => '', 'on' => '' );
		}
		$state = array(
			'now'    => (string) $row->zone_now,
			'before' => (string) $row->zone_before,
			'on'     => (string) $row->changed_on,
		);
		if ( $state['now'] === $chosen ) {
			return $state;
		}
		$now      = LLD_Members_Clock::now( $member_id );
		$in_force = self::zone_in_force( $now, $state );
		$today    = self::zone_date( $now, $state['now'], $state['before'] ? $state['before'] : null, $state['on'] ? $state['on'] : null );
		$next     = $in_force === $chosen
			? array( 'now' => $chosen, 'before' => '', 'on' => '' )
			: array( 'now' => $chosen, 'before' => $in_force, 'on' => $today );
		$wpdb->replace(
			$table,
			array(
				'member_id'   => $member_id,
				'zone_now'    => $next['now'],
				'zone_before' => '' !== $next['before'] ? $next['before'] : null,
				'changed_on'  => '' !== $next['on'] ? $next['on'] : null,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return $next;
	}

	/**
	 * Called when the member saves their settings: records a new time zone at the moment of the change, with the
	 * zone it replaces, so the change applies from the next local date (T3 rule 2).
	 *
	 * @param int    $member_id Member number.
	 * @param string $before    Zone before the save.
	 */
	public static function zone_saved( $member_id, $before ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'zone_change' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT member_id FROM {$table} WHERE member_id = %d", $member_id ) ) ) {
			$wpdb->replace( $table, array( 'member_id' => (int) $member_id, 'zone_now' => '' !== (string) $before ? (string) $before : 'UTC' ), array( '%d', '%s' ) );
		}
		self::zone_state( $member_id );
	}

	/**
	 * Today's local date for a member: their zone, T3 rule 2 and the test clock.
	 *
	 * @param int $member_id Member number.
	 */
	public static function local_today( $member_id ) {
		$state = self::zone_state( $member_id );
		return self::zone_date( LLD_Members_Clock::now( $member_id ), $state['now'], $state['before'] ? $state['before'] : null, $state['on'] ? $state['on'] : null );
	}

	/**
	 * The zone in force now. A change waiting for the next local date is not yet in force.
	 *
	 * @param int $member_id Member number.
	 */
	public static function timezone( $member_id ) {
		return self::zone_in_force( LLD_Members_Clock::now( $member_id ), self::zone_state( $member_id ) );
	}

	/**
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add.
	 */
	public static function add_days( $date, $days ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		if ( ! $at ) {
			return '';
		}
		return $at->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Calendar days from one date to another. Dates have no clock time, so clock changes never add or remove a day.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function days_between( $from, $to ) {
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, new DateTimeZone( 'UTC' ) );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, new DateTimeZone( 'UTC' ) );
		if ( ! $a || ! $b ) {
			return 0;
		}
		return (int) $a->diff( $b )->format( '%r%a' );
	}

	/**
	 * @param string $date Y-m-d.
	 */
	private static function nice_date( $date ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $at ? $at->format( 'l j F' ) : (string) $date;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Active periods and programme days (T3 rules 3 to 5). Pure functions over a list of periods.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * @param int $enrolment_id Enrolment.
	 * @return array<int,array{id:int,first:string,last:string}>
	 */
	public static function periods( $enrolment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, first_on, last_on FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' WHERE enrolment_id = %d ORDER BY first_on ASC, id ASC', (int) $enrolment_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'    => (int) $row->id,
				'first' => (string) $row->first_on,
				'last'  => $row->last_on ? (string) $row->last_on : '',
			);
		}
		return $out;
	}

	/**
	 * Local dates the periods cover up to and including a date. With the date set to today this is T3 rule 3.
	 *
	 * @param array<int,array{first:string,last:string}> $periods Periods.
	 * @param string                                      $date    Y-m-d.
	 */
	public static function days_until( $periods, $date ) {
		$total = 0;
		foreach ( $periods as $period ) {
			$end = '' !== $period['last'] ? $period['last'] : $date;
			if ( $end > $date ) {
				$end = $date;
			}
			if ( $end < $period['first'] ) {
				continue;
			}
			$total += self::days_between( $period['first'], $end ) + 1;
		}
		return $total;
	}

	/**
	 * The local date of a programme day. An open period runs on into the future.
	 *
	 * @param array<int,array{first:string,last:string}> $periods Periods.
	 * @param int                                         $day     Programme day.
	 */
	public static function day_date( $periods, $day ) {
		$left = (int) $day;
		if ( $left < 1 ) {
			return '';
		}
		foreach ( $periods as $period ) {
			if ( '' === $period['last'] ) {
				return self::add_days( $period['first'], $left - 1 );
			}
			$length = self::days_between( $period['first'], $period['last'] ) + 1;
			if ( $left <= $length ) {
				return self::add_days( $period['first'], $left - 1 );
			}
			$left -= $length;
		}
		return '';
	}

	/**
	 * @param int $programme Programme day, starting at 1.
	 * @return array{week:int,day:int,programme:int}
	 */
	public static function position_parts( $programme ) {
		$programme = max( 1, (int) $programme );
		return array(
			'programme' => $programme,
			'week'      => (int) ceil( $programme / 7 ),
			'day'       => ( ( $programme - 1 ) % 7 ) + 1,
		);
	}

	/**
	 * Days the enrolment has reached today, from 0 (starts tomorrow) to 28.
	 *
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 */
	public static function current_day( $member_id, $enrolment_id ) {
		return min( self::DAYS, self::days_until( self::periods( $enrolment_id ), self::local_today( $member_id ) ) );
	}

	/**
	 * Programme day for display, at least 1.
	 *
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 */
	public static function programme_day( $member_id, $enrolment_id ) {
		return max( 1, self::current_day( $member_id, $enrolment_id ) );
	}

	/**
	 * Days in the open period. Billing reads this for the free week's diary.
	 *
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 */
	public static function open_period_days( $member_id, $enrolment_id ) {
		$today = self::local_today( $member_id );
		foreach ( array_reverse( self::periods( $enrolment_id ) ) as $period ) {
			if ( '' === $period['last'] ) {
				return $today < $period['first'] ? 1 : self::days_between( $period['first'], $today ) + 1;
			}
		}
		return self::programme_day( $member_id, $enrolment_id );
	}

	/**
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 * @param int $day          Programme day.
	 */
	public static function date_for_day( $member_id, $enrolment_id, $day ) {
		unset( $member_id );
		return self::day_date( self::periods( $enrolment_id ), $day );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $date      Local date the activity belongs to.
	 */
	public static function within_two_days( $member_id, $date ) {
		if ( '' === (string) $date ) {
			return false;
		}
		$late = self::days_between( (string) $date, self::local_today( $member_id ) );
		return $late >= 0 && $late <= 2;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Enrolments (C5)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * @return array<string,string>
	 */
	public static function track_titles() {
		return array(
			'healthy-weight' => 'Healthy Weight',
			'blood-sugar'    => 'Blood Sugar Balance',
			'fitness'        => 'Fitness and Strength',
			'sleep-stress'   => 'Sleep and Stress',
		);
	}

	/**
	 * Name used by the safety matrix (B13 "Tracks not offered").
	 *
	 * @param string $key Track key.
	 */
	public static function safety_title( $key ) {
		$titles = self::track_titles();
		return isset( $titles[ $key ] ) ? $titles[ $key ] : (string) $key;
	}

	/**
	 * Name shown to members.
	 *
	 * @param string $key Track key.
	 */
	public static function track_title( $key ) {
		$titles = self::track_titles();
		if ( isset( $titles[ $key ] ) ) {
			return $titles[ $key ];
		}
		$id = self::track_id( $key );
		return $id ? get_the_title( $id ) : (string) $key;
	}

	/**
	 * @param string $key Track key.
	 */
	public static function track_exists( $key ) {
		$titles = self::track_titles();
		return isset( $titles[ $key ] ) || self::track_id( $key ) > 0;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 */
	public static function track_allowed( $member_id, $key ) {
		return LLD_Members_Safety::track_allowed( $member_id, self::safety_title( $key ) );
	}

	/**
	 * @param string $key Track slug.
	 * @return int
	 */
	public static function track_id( $key ) {
		if ( '' === (string) $key ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lld_track',
				'name'           => $key,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
			)
		);
		return $posts ? (int) $posts[0]->ID : 0;
	}

	/**
	 * The active enrolment, after it is brought up to date (finished, stopped or resumed as T3 says).
	 *
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	public static function active( $member_id ) {
		self::settle( $member_id );
		return self::active_row( $member_id );
	}

	/**
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	private static function active_row( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $member_id ) );
	}

	/**
	 * The enrolment to show: the active one, or the one the member followed most recently.
	 *
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	public static function current( $member_id ) {
		$row = self::active( $member_id );
		if ( $row ) {
			return $row;
		}
		return self::latest( $member_id );
	}

	/**
	 * The enrolment whose latest active period started last.
	 *
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	private static function latest( $member_id ) {
		global $wpdb;
		$enrolment = LLD_Members_Schema::table( 'enrolment' );
		$period    = LLD_Members_Schema::table( 'enrolment_period' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT e.* FROM {$enrolment} e WHERE e.member_id = %d ORDER BY (SELECT MAX(p.first_on) FROM {$period} p WHERE p.enrolment_id = e.id) DESC, e.id DESC LIMIT 1", $member_id ) );
	}

	/**
	 * One of the member's own enrolments, or the current one.
	 *
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment asked for, or 0.
	 * @return object|null
	 */
	private static function enrolment_for( $member_id, $enrolment_id ) {
		if ( $enrolment_id > 0 ) {
			self::settle( $member_id );
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . ' WHERE id = %d AND member_id = %d', $enrolment_id, $member_id ) );
			if ( $row ) {
				return $row;
			}
		}
		return self::current( $member_id );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 * @return object|null
	 */
	private static function latest_for_track( $member_id, $key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . ' WHERE member_id = %d AND track_key = %s ORDER BY id DESC LIMIT 1', $member_id, $key ) );
	}

	/**
	 * Kept for older callers: open a period from today when the enrolment has none.
	 *
	 * @param int $enrolment_id Enrolment.
	 * @param int $member_id    Member number.
	 */
	public static function ensure_period( $enrolment_id, $member_id ) {
		if ( $enrolment_id < 1 ) {
			return;
		}
		foreach ( self::periods( $enrolment_id ) as $period ) {
			if ( '' === $period['last'] ) {
				return;
			}
		}
		self::open_period( $enrolment_id, self::first_free_date( $member_id, self::local_today( $member_id ) ) );
	}

	/**
	 * @param int    $enrolment_id Enrolment.
	 * @param string $first        First local date.
	 */
	private static function open_period( $enrolment_id, $first ) {
		global $wpdb;
		return false !== $wpdb->insert(
			LLD_Members_Schema::table( 'enrolment_period' ),
			array(
				'enrolment_id' => (int) $enrolment_id,
				'first_on'     => $first,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * The earliest date a new period may start: never inside another period of the member (T1).
	 *
	 * @param int    $member_id Member number.
	 * @param string $wanted    Date asked for.
	 */
	private static function first_free_date( $member_id, $wanted ) {
		global $wpdb;
		$last = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(p.last_on) FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' p INNER JOIN ' . LLD_Members_Schema::table( 'enrolment' ) . ' e ON e.id = p.enrolment_id WHERE e.member_id = %d',
				$member_id
			)
		);
		if ( is_string( $last ) && '' !== $last && $last >= $wanted ) {
			return self::add_days( $last, 1 );
		}
		return $wanted;
	}

	/**
	 * Close the open period of an enrolment on a date. A period that would start after that date is removed.
	 *
	 * @param int    $enrolment_id Enrolment.
	 * @param string $last         Last local date, or '' to remove the open period.
	 */
	private static function close_period( $enrolment_id, $last ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment_period' );
		foreach ( self::periods( $enrolment_id ) as $period ) {
			if ( '' !== $period['last'] ) {
				continue;
			}
			if ( '' === $last || $last < $period['first'] ) {
				$wpdb->delete( $table, array( 'id' => $period['id'] ), array( '%d' ) );
			} else {
				$wpdb->update( $table, array( 'last_on' => $last ), array( 'id' => $period['id'] ), array( '%s' ), array( '%d' ) );
			}
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Access (requirements section 8.3, T3 rule 6, T4)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	private static function subscription( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function state( $member_id ) {
		return strtolower( trim( (string) LLD_Members_Access::access_state( $member_id ) ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function in_paid_period( $member_id ) {
		$sub = self::subscription( $member_id );
		return $sub && $sub->period_ends_on && self::local_today( $member_id ) <= (string) $sub->period_ends_on;
	}

	/**
	 * Premium, or cancelled but still in the paid period. Nothing else opens Premium programme content.
	 *
	 * @param int $member_id Member number.
	 */
	public static function premium_open( $member_id ) {
		$state = self::state( $member_id );
		if ( 'premium' === $state || 0 === strpos( $state, 'premium (' ) ) {
			return true;
		}
		if ( 'cancelled' === $state ) {
			return self::in_paid_period( $member_id );
		}
		return false;
	}

	/**
	 * Premium ended, refunded, or cancelled after the paid period.
	 *
	 * @param int $member_id Member number.
	 */
	public static function premium_ended( $member_id ) {
		$state = self::state( $member_id );
		if ( in_array( $state, array( 'premium ended', 'refunded' ), true ) ) {
			return true;
		}
		return 'cancelled' === $state && ! self::in_paid_period( $member_id );
	}

	/**
	 * Local date Premium ended on, or '' when unknown.
	 *
	 * @param int $member_id Member number.
	 */
	private static function ended_as_of( $member_id ) {
		$sub = self::subscription( $member_id );
		if ( ! $sub ) {
			return '';
		}
		if ( $sub->premium_ended_on ) {
			return (string) $sub->premium_ended_on;
		}
		if ( 'cancelled' === self::state( $member_id ) && $sub->period_ends_on ) {
			return (string) $sub->period_ends_on;
		}
		return '';
	}

	/**
	 * T3 rule 6, for C6 (Billing) to call when Premium ends or is refunded: the enrolment's last open day becomes the
	 * last local date of the track week that holds $as_of. Returns that date, or '' when nothing is active.
	 *
	 * @param int         $member_id Member number.
	 * @param string      $as_of     Local date Premium ended on.
	 * @param object|null $row       Enrolment, or null for the active one.
	 */
	public static function close_current_week( $member_id, $as_of, $row = null ) {
		$row = $row ? $row : self::active_row( $member_id );
		if ( ! $row ) {
			return '';
		}
		$periods = self::periods( (int) $row->id );
		$reached = min( self::DAYS, self::days_until( $periods, (string) $as_of ) );
		if ( $reached < 1 ) {
			return '';
		}
		$last = self::day_date( $periods, min( self::DAYS, (int) ceil( $reached / 7 ) * 7 ) );
		global $wpdb;
		$wpdb->update( LLD_Members_Schema::table( 'enrolment' ), array( 'last_open_day' => $last ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		unset( self::$settled[ (int) $member_id ] );
		return $last;
	}

	/**
	 * For C6 to call when Premium is restored: the last open day is cleared and a stopped track carries on.
	 *
	 * @param int $member_id Member number.
	 */
	public static function premium_restored( $member_id ) {
		unset( self::$settled[ (int) $member_id ] );
		self::settle( $member_id );
	}

	/**
	 * True when this enrolment holds the member's free week for its track (once per track, section 8.3).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 */
	private static function holds_free_week( $member_id, $row ) {
		global $wpdb;
		$first = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM ' . LLD_Members_Schema::table( 'enrolment' ) . ' WHERE member_id = %d AND track_key = %s', $member_id, $row->track_key ) );
		return $first === (int) $row->id;
	}

	/**
	 * The last programme day this member may open in this enrolment, 0 to 28.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 */
	public static function access_limit( $member_id, $row ) {
		if ( self::premium_open( $member_id ) ) {
			return self::DAYS;
		}
		$periods = self::periods( (int) $row->id );
		if ( self::premium_ended( $member_id ) ) {
			$last = (string) $row->last_open_day;
			if ( '' === $last && 'active' === (string) $row->status && $periods ) {
				$as_of = self::ended_as_of( $member_id );
				if ( '' !== $as_of && $as_of >= $periods[0]['first'] ) {
					$last = self::close_current_week( $member_id, $as_of, $row );
				}
			}
			if ( '' !== $last ) {
				return min( self::DAYS, self::days_until( $periods, $last ) );
			}
		}
		return self::holds_free_week( $member_id, $row ) ? 7 : 0;
	}

	/**
	 * Bring the member's enrolment up to date: safety first, then finishing at day 28, then access.
	 *
	 * @param int $member_id Member number.
	 */
	private static function settle( $member_id ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 || isset( self::$settled[ $member_id ] ) ) {
			return;
		}
		self::$settled[ $member_id ] = true;
		$row = self::active_row( $member_id );
		if ( ! $row ) {
			self::resume_after_upgrade( $member_id );
			return;
		}
		$today = self::local_today( $member_id );
		if ( ! self::track_allowed( $member_id, (string) $row->track_key ) ) {
			// Section 8.2: anything already assigned is removed at once. The member is told on the next visit.
			self::stop( $row, 'not_suitable', $today );
			return;
		}
		$periods = self::periods( (int) $row->id );
		$reached = self::days_until( $periods, $today );
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment' );
		if ( $reached >= 1 && ! (int) $row->free_week_used ) {
			$wpdb->update( $table, array( 'free_week_used' => 1 ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
		}
		if ( self::premium_open( $member_id ) && $row->last_open_day ) {
			$wpdb->update( $table, array( 'last_open_day' => null ), array( 'id' => (int) $row->id ) );
			$row->last_open_day = null;
		}
		$limit = self::access_limit( $member_id, $row );
		if ( $reached >= self::DAYS && $limit >= self::DAYS ) {
			self::finish( $member_id, $row, $periods );
			return;
		}
		if ( $reached > $limit ) {
			// Access ended: the period closes on the last open date, so the day count carries on from there later.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $row->id ) );
			self::stop( $row, $row->last_open_day ? 'premium_ended' : 'free_week', $limit > 0 ? self::day_date( $periods, $limit ) : '' );
		}
	}

	/**
	 * @param object $row      Enrolment.
	 * @param string $why      not_suitable, premium_ended or free_week.
	 * @param string $last_day Last local date of the period, or '' to remove the open period.
	 */
	private static function stop( $row, $why, $last_day ) {
		self::close_period( (int) $row->id, $last_day );
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'enrolment' ),
			array(
				'status'       => 'stopped',
				'stopped_why'  => $why,
				'notice_shown' => 'not_suitable' === $why ? 0 : 1,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Day 28 reached: the track is finished on that local date (section 8.3), and EM-08 is sent once if chosen.
	 *
	 * @param int                                         $member_id Member number.
	 * @param object                                      $row       Enrolment.
	 * @param array<int,array{first:string,last:string}> $periods   Periods.
	 */
	private static function finish( $member_id, $row, $periods ) {
		$last = self::day_date( $periods, self::DAYS );
		self::close_period( (int) $row->id, $last );
		global $wpdb;
		$won = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . LLD_Members_Schema::table( 'enrolment' ) . " SET status = 'finished', finished_on = %s WHERE id = %d AND status = 'active'",
				$last,
				(int) $row->id
			)
		);
		if ( $won ) {
			self::mail_if_chosen( $member_id, 'end_of_track', 'end:' . (int) $row->id, home_url( '/members/tracks/' ) );
		}
	}

	/**
	 * A track stopped only for access carries on when Premium is open again.
	 *
	 * @param int $member_id Member number.
	 */
	private static function resume_after_upgrade( $member_id ) {
		if ( ! self::premium_open( $member_id ) ) {
			return;
		}
		$row = self::latest( $member_id );
		if ( ! $row || 'stopped' !== (string) $row->status || ! in_array( (string) $row->stopped_why, array( 'premium_ended', 'free_week' ), true ) ) {
			return;
		}
		if ( ! self::track_allowed( $member_id, (string) $row->track_key ) ) {
			return;
		}
		self::reopen( $member_id, $row, self::local_today( $member_id ) );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param string $first     First local date of the new period.
	 */
	private static function reopen( $member_id, $row, $first ) {
		global $wpdb;
		$ok = $wpdb->update(
			LLD_Members_Schema::table( 'enrolment' ),
			array(
				'status'        => 'active',
				'stopped_why'   => null,
				'last_open_day' => null,
				'notice_shown'  => 1,
			),
			array( 'id' => (int) $row->id )
		);
		if ( false === $ok ) {
			return false;
		}
		return self::open_period( (int) $row->id, self::first_free_date( $member_id, $first ) );
	}

	/**
	 * Start, switch, resume or repeat a track (SCR-25, section 8.3, T3 rule 5).
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 * @param string $when      today or tomorrow.
	 * @return true|WP_Error
	 */
	public static function start( $member_id, $key, $when = 'today' ) {
		$key = sanitize_title( (string) $key );
		if ( 'sleep-stress' === $key && LLD_Members_Personal::track_held( $member_id ) ) {
			return new WP_Error( 'lld_track', 'Sleep and Stress is not available in this session.' );
		}
		if ( '' === $key || ! self::track_exists( $key ) ) {
			return new WP_Error( 'lld_track', 'Choose a track.' );
		}
		if ( ! self::track_allowed( $member_id, $key ) ) {
			return new WP_Error( 'lld_not_suitable', self::track_title( $key ) . ' is not suitable for you.' );
		}
		self::settle( $member_id );
		$today   = self::local_today( $member_id );
		$first   = 'tomorrow' === $when ? self::add_days( $today, 1 ) : $today;
		$current = self::active_row( $member_id );
		if ( $current && (string) $current->track_key === $key ) {
			return true;
		}
		$switch = '';
		if ( $current ) {
			$periods = self::periods( (int) $current->id );
			$reached = self::days_until( $periods, $today );
			if ( $reached > 0 && 0 !== $reached % 7 ) {
				return new WP_Error( 'lld_switch', 'You can switch at the end of this week on ' . self::nice_date( self::day_date( $periods, (int) ceil( $reached / 7 ) * 7 ) ) . '.' );
			}
			$switch = $reached > 0 ? 'week_end' : 'not_started';
		}
		$target = self::latest_for_track( $member_id, $key );
		$open   = self::premium_open( $member_id );
		$plan   = 'new';
		if ( $target && in_array( (string) $target->status, array( 'set_aside', 'stopped' ), true ) ) {
			$plan = 'resume';
			if ( ! $open && self::days_until( self::periods( (int) $target->id ), $today ) >= self::access_limit( $member_id, $target ) ) {
				return new WP_Error( 'lld_premium', 'Your free week of ' . self::track_title( $key ) . ' is used. Premium continues this track.' );
			}
		} elseif ( $target && 'finished' === (string) $target->status ) {
			$plan = 'repeat';
			if ( ! $open ) {
				return new WP_Error( 'lld_premium', 'Repeating a finished track is part of Premium.' );
			}
		} elseif ( $target ) {
			return new WP_Error( 'lld_track', 'Choose a track.' );
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment' );
		if ( 'week_end' === $switch ) {
			// T3 rule 5: the current period closes on the last day of this week; the new one opens the next day.
			self::close_period( (int) $current->id, $today );
			$wpdb->update( $table, array( 'status' => 'set_aside' ), array( 'id' => (int) $current->id ), array( '%s' ), array( '%d' ) );
			$first = self::add_days( $today, 1 );
		} elseif ( 'not_started' === $switch ) {
			self::close_period( (int) $current->id, '' );
			if ( ! self::periods( (int) $current->id ) ) {
				$wpdb->delete( $table, array( 'id' => (int) $current->id ), array( '%d' ) );
			} else {
				$wpdb->update( $table, array( 'status' => 'set_aside' ), array( 'id' => (int) $current->id ), array( '%s' ), array( '%d' ) );
			}
		}
		unset( self::$settled[ (int) $member_id ] );
		if ( 'resume' === $plan ) {
			if ( false === self::reopen( $member_id, $target, $first ) ) {
				return new WP_Error( 'lld_track', 'This member already has an active track.' );
			}
			return true;
		}
		$attempt = 'repeat' === $plan ? (int) $target->attempt + 1 : 1;
		$ok      = $wpdb->insert(
			$table,
			array(
				'member_id' => (int) $member_id,
				'track_key' => $key,
				'attempt'   => $attempt,
				'status'    => 'active',
			),
			array( '%d', '%s', '%d', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'lld_track', 'This member already has an active track.' );
		}
		self::open_period( (int) $wpdb->insert_id, self::first_free_date( $member_id, $first ) );
		return true;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function position_label( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return 'Week 1, day 1';
		}
		$day = self::current_day( $member_id, (int) $row->id );
		if ( $day < 1 ) {
			$periods = self::periods( (int) $row->id );
			return 'Starts ' . self::nice_date( self::day_date( $periods, 1 ) );
		}
		$pos = self::position_parts( $day );
		return 'Week ' . $pos['week'] . ', day ' . $pos['day'];
	}

	/**
	 * True when the member may open this day of their current track, by any route.
	 *
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	public static function can_open_day( $member_id, $day ) {
		$row = self::current( $member_id );
		return $row && '' === self::open_problem( $member_id, $row, (int) $day );
	}

	/**
	 * Why a day cannot be opened: '', not_suitable, premium, not_yet or missing.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $day       Programme day.
	 */
	private static function open_problem( $member_id, $row, $day ) {
		if ( $day < 1 || $day > self::DAYS ) {
			return 'missing';
		}
		if ( ! self::track_allowed( $member_id, (string) $row->track_key ) ) {
			return 'not_suitable';
		}
		if ( $day > self::access_limit( $member_id, $row ) ) {
			return 'premium';
		}
		if ( $day > min( self::DAYS, self::days_until( self::periods( (int) $row->id ), self::local_today( $member_id ) ) ) ) {
			return 'not_yet';
		}
		return '';
	}

	/**
	 * Whether a day may still be ticked: open, within 2 local dates (T3 rule 7), and, without Premium, only while
	 * the open week lasts (section 8.3: read-only afterwards).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $day       Programme day.
	 */
	private static function mark_problem( $member_id, $row, $day ) {
		$open = self::open_problem( $member_id, $row, $day );
		if ( 'not_suitable' === $open ) {
			return 'This activity is not suitable for you.';
		}
		if ( 'premium' === $open ) {
			return 'Day ' . (int) $day . ' is part of Premium.';
		}
		if ( '' !== $open ) {
			return 'This day has not opened yet.';
		}
		$periods = self::periods( (int) $row->id );
		if ( ! self::within_two_days( $member_id, self::day_date( $periods, $day ) ) ) {
			return 'This activity can no longer be marked.';
		}
		if ( ! self::premium_open( $member_id ) ) {
			$limit = self::access_limit( $member_id, $row );
			if ( $limit < 1 || self::local_today( $member_id ) > self::day_date( $periods, $limit ) ) {
				return 'This activity can no longer be marked.';
			}
		}
		return '';
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Content
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Programme day an activity belongs to. Day numbers 1 to 7 with a later week number count within that week.
	 *
	 * @param array<string,mixed> $fields Activity fields.
	 */
	public static function item_day( $fields ) {
		$day  = (int) ( $fields['day_number'] ?? 0 );
		$week = (int) ( $fields['week_number'] ?? 0 );
		if ( $day < 1 ) {
			return 0;
		}
		if ( $day <= 7 && $week > 1 ) {
			return ( ( $week - 1 ) * 7 ) + $day;
		}
		return $day;
	}

	/**
	 * @param int $track_id Track post.
	 * @return array<int,array<string,mixed>>
	 */
	private static function track_items( $track_id ) {
		$track_id = (int) $track_id;
		if ( isset( self::$track_items[ $track_id ] ) ) {
			return self::$track_items[ $track_id ];
		}
		$out = array();
		if ( $track_id > 0 ) {
			$posts = get_posts(
				array(
					'post_type'      => 'lld_activity',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'post_parent'    => 0,
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $posts as $post ) {
				$shown = LLD_Members_Content::shown( $post->ID );
				if ( ! is_array( $shown ) ) {
					continue;
				}
				$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
				if ( (int) ( $fields['track_id'] ?? 0 ) === $track_id ) {
					$out[] = $shown;
				}
			}
		}
		self::$track_items[ $track_id ] = $out;
		return $out;
	}

	/**
	 * Approved activities for a track day, or the action library, after the safety matrix.
	 *
	 * @param int $track_id  Track post.
	 * @param int $day       Programme day, or 0 for the action library.
	 * @param int $member_id Member, for the safety matrix.
	 * @param bool $safe     False to skip the safety matrix (counting only).
	 * @return array<int,array<string,mixed>>
	 */
	public static function activities( $track_id, $day, $member_id, $safe = true ) {
		$out = array();
		foreach ( self::track_items( $track_id ) as $shown ) {
			$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
			if ( $day > 0 && self::item_day( $fields ) !== (int) $day ) {
				continue;
			}
			if ( 0 === (int) $day && empty( $fields['in_library'] ) ) {
				continue;
			}
			if ( $safe && ! LLD_Members_Safety::allows( $member_id, $shown ) ) {
				continue;
			}
			$out[] = $shown;
		}
		return $out;
	}

	/**
	 * Whether a programme item opened by a direct link (the library, a download) is open to this member: track
	 * content from day 8 or week 2 onwards needs Premium (FR2-08-AS-02, section 8.3).
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $item      Snapshot.
	 */
	public static function item_open( $member_id, $item ) {
		$type   = isset( $item['type'] ) ? (string) $item['type'] : get_post_type( (int) ( $item['parent_id'] ?? 0 ) );
		$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		if ( (int) ( $fields['track_id'] ?? 0 ) < 1 ) {
			return true;
		}
		$late = false;
		if ( 'lld_activity' === $type ) {
			$late = self::item_day( $fields ) > 7;
		} elseif ( in_array( $type, array( 'lld_lesson', 'lld_week' ), true ) ) {
			$late = (int) ( $fields['week_number'] ?? 0 ) > 1;
		}
		return ! $late || self::premium_open( $member_id );
	}

	/**
	 * The full-page membership lock for a direct link to a later day (SCR-24). Never a blank page.
	 */
	public static function item_locked() {
		if ( isset( $_GET['download'] ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Part of Premium';
			exit;
		}
		lld_members_layout(
			array(
				'title'     => 'Part of Premium',
				'main'      => '<h1>Part of Premium</h1>' . lld_membership_lock( 'Day 8 onwards is part of Premium.' ),
				'signed_in' => true,
				'status'    => 403,
			)
		);
	}

	/**
	 * The day's safe activities. When the matrix removed every one, a safe library action stands in (SCR-10).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $day       Programme day.
	 * @return array{items:array<int,array<string,mixed>>,replaced:bool}
	 */
	private static function day_items( $member_id, $row, $day ) {
		$track = self::track_id( (string) $row->track_key );
		$items = self::activities( $track, $day, $member_id );
		if ( $items || ! self::activities( $track, $day, $member_id, false ) ) {
			return array( 'items' => $items, 'replaced' => false );
		}
		$library = self::activities( $track, 0, $member_id );
		if ( ! $library ) {
			return array( 'items' => array(), 'replaced' => true );
		}
		return array( 'items' => array( $library[ ( $day - 1 ) % count( $library ) ] ), 'replaced' => true );
	}

	/**
	 * Lesson or weekly theme of a track week, after the safety matrix.
	 *
	 * @param string $type      lld_lesson or lld_week.
	 * @param int    $track_id  Track post.
	 * @param int    $week      Week 1 to 4.
	 * @param int    $member_id Member number.
	 * @return array<string,mixed>|null
	 */
	private static function week_item( $type, $track_id, $week, $member_id ) {
		if ( $track_id < 1 ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$shown  = LLD_Members_Content::shown( $post->ID );
			$fields = is_array( $shown ) && isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
			if ( ! $shown || (int) ( $fields['track_id'] ?? 0 ) !== (int) $track_id || (int) ( $fields['week_number'] ?? 0 ) !== (int) $week ) {
				continue;
			}
			if ( LLD_Members_Safety::allows( $member_id, $shown ) ) {
				return $shown;
			}
		}
		return null;
	}

	/**
	 * A stored action version, or the current approved one when that version is no longer available.
	 *
	 * @param int $parent_id  Item.
	 * @param int $version_id Version row.
	 * @return array<string,mixed>|null
	 */
	private static function snapshot( $parent_id, $version_id ) {
		if ( $version_id > 0 && method_exists( 'LLD_Members_Content', 'shown_version' ) ) {
			$shown = LLD_Members_Content::shown_version( $version_id );
			if ( is_array( $shown ) && (int) ( $shown['parent_id'] ?? 0 ) === (int) $parent_id ) {
				return $shown;
			}
		}
		$shown = LLD_Members_Content::shown( (int) $parent_id );
		return is_array( $shown ) ? $shown : null;
	}

	/**
	 * Results recorded for an enrolment, as day => item => done or skipped.
	 *
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 * @return array<int,array<int,string>>
	 */
	private static function results( $member_id, $enrolment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT item_version, programme_day, result FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d AND context_type = %s AND context_id = %d ORDER BY id ASC', $member_id, 'enrolment', $enrolment_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( preg_match( '/^a(\d+)v/', (string) $row->item_version, $m ) ) {
				$out[ (int) $row->programme_day ][ (int) $m[1] ] = (string) $row->result;
			}
		}
		return $out;
	}

	/**
	 * Days of the current track with at least one activity done ("9 of 28 done").
	 *
	 * @param int $member_id Member number.
	 */
	public static function done_count( $member_id ) {
		$row = self::current( $member_id );
		if ( ! $row ) {
			return 0;
		}
		$done = 0;
		foreach ( self::results( $member_id, (int) $row->id ) as $items ) {
			if ( in_array( 'done', $items, true ) ) {
				$done++;
			}
		}
		return $done;
	}

	/**
	 * FR2-10 completion of one track week: activities done divided by activities available. Skips count as not done.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $week      Week 1 to 4.
	 * @return array{done:int,available:int,pct:float|null}
	 */
	public static function completion( $member_id, $row, $week ) {
		$track     = self::track_id( (string) $row->track_key );
		$results   = self::results( $member_id, (int) $row->id );
		$done      = 0;
		$available = 0;
		$start     = ( ( (int) $week - 1 ) * 7 ) + 1;
		for ( $day = $start; $day < $start + 7; $day++ ) {
			$found = self::day_items( $member_id, $row, $day );
			foreach ( $found['items'] as $item ) {
				$available++;
				if ( isset( $results[ $day ][ (int) $item['parent_id'] ] ) && 'done' === $results[ $day ][ (int) $item['parent_id'] ] ) {
					$done++;
				}
			}
		}
		unset( $track );
		return array(
			'done'      => $done,
			'available' => $available,
			'pct'       => $available > 0 ? round( ( $done / $available ) * 100, 2 ) : null,
		);
	}

	/**
	 * Last week's completion, the figure this week's plan is built from (FR2-10). None for week 1.
	 *
	 * @param int $member_id Member number.
	 * @return float|null
	 */
	public static function completion_pct( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return null;
		}
		$week = self::position_parts( self::programme_day( $member_id, (int) $row->id ) )['week'];
		if ( $week < 2 ) {
			return null;
		}
		return self::completion( $member_id, $row, $week - 1 )['pct'];
	}

	/**
	 * @param string $key Track slug.
	 * @return string[]
	 */
	public static function measures( $key ) {
		$map = array(
			'healthy-weight' => array( 'weight', 'waist' ),
			'blood-sugar'    => array( 'weight', 'waist', 'activity' ),
			'fitness'        => array( 'chair', 'push', 'activity', 'strength' ),
			'sleep-stress'   => array( 'sleep', 'quality', 'stress' ),
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : array();
	}

	/**
	 * @param float $height Height in centimetres.
	 * @param float $weight Weight in kilograms.
	 */
	public static function bmi( $height, $weight ) {
		$metres = (float) $height / 100;
		if ( $metres <= 0 ) {
			return '';
		}
		return number_format( (float) $weight / ( $metres * $metres ), 1, '.', '' );
	}

	/**
	 * @param float $height Height in centimetres.
	 * @param float $waist  Waist in centimetres.
	 */
	public static function waist_ratio( $height, $waist ) {
		if ( (float) $height <= 0 ) {
			return '';
		}
		return number_format( (float) $waist / (float) $height, 2, '.', '' );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Weekly action plan (FR2-10, C11)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Difficulty rule from FR2-10. No figure (week 1, or nothing to count) keeps the level.
	 *
	 * @param float|null $pct      Completion percentage, or null.
	 * @param string     $previous Unused; kept for older callers.
	 */
	public static function level_for( $pct, $previous = '' ) {
		unset( $previous );
		if ( null === $pct ) {
			return 'same';
		}
		if ( $pct < 50 ) {
			return 'easier';
		}
		if ( $pct <= 80 ) {
			return 'same';
		}
		return 'stretch';
	}

	/**
	 * @param array<string,mixed> $item Snapshot.
	 */
	private static function difficulty( $item ) {
		$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		$diff   = isset( $fields['difficulty'] ) ? (string) $fields['difficulty'] : 'standard';
		return in_array( $diff, array( 'easier', 'standard', 'stretch' ), true ) ? $diff : 'standard';
	}

	/**
	 * Choose 3 to 5 actions. Easier takes easier actions first; same keeps last week's band; stretch swaps one action
	 * for a stretch action and keeps the count. With fewer than 3 safe actions, all of them are shown with a note.
	 *
	 * @param array<int,array<string,mixed>> $items   Safe library actions.
	 * @param float|null                     $pct     Last week's completion.
	 * @param string                         $band    Last week's band: easier or standard.
	 * @param int                            $offset  Rotation, so weeks differ.
	 * @param int[]                          $exclude Actions to avoid (a swap).
	 * @param string                         $level   Level to use instead of working it out from $pct.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string}
	 */
	public static function select( $items, $pct, $band = 'standard', $offset = 0, $exclude = array(), $level = '' ) {
		$level = '' !== $level ? $level : self::level_for( $pct );
		$items = array_values( $items );
		if ( ! $items ) {
			return array( 'level' => $level, 'actions' => array(), 'note' => 'No safe actions are available this week.' );
		}
		if ( count( $items ) < 3 ) {
			return array( 'level' => $level, 'actions' => $items, 'note' => 'Fewer than 3 safe actions are available.' );
		}
		$band  = 'easier' === $level ? 'easier' : ( 'stretch' === $level ? 'standard' : ( 'easier' === $band ? 'easier' : 'standard' ) );
		$size  = min( 4, count( $items ) );
		$order = array();
		foreach ( $items as $i => $item ) {
			$diff    = self::difficulty( $item );
			$order[] = array(
				'item'    => $item,
				'stretch' => 'stretch' === $diff ? 1 : 0,
				'fresh'   => in_array( (int) $item['parent_id'], $exclude, true ) ? 1 : 0,
				'rank'    => $diff === $band ? 0 : ( 'standard' === $diff ? 1 : 2 ),
				'turn'    => ( ( $i - (int) $offset ) % count( $items ) + count( $items ) ) % count( $items ),
			);
		}
		usort(
			$order,
			static function ( $a, $b ) {
				return array( $a['stretch'], $a['fresh'], $a['rank'], $a['turn'] ) <=> array( $b['stretch'], $b['fresh'], $b['rank'], $b['turn'] );
			}
		);
		$picked = array();
		foreach ( array_slice( $order, 0, $size ) as $entry ) {
			$picked[] = $entry['item'];
		}
		if ( 'stretch' === $level ) {
			$stretch = array();
			foreach ( $order as $entry ) {
				if ( 'stretch' === self::difficulty( $entry['item'] ) && ! in_array( $entry['item'], $picked, true ) ) {
					$stretch[] = $entry['item'];
				}
			}
			$has = false;
			foreach ( $picked as $item ) {
				$has = $has || 'stretch' === self::difficulty( $item );
			}
			if ( ! $has && $stretch ) {
				$picked[ count( $picked ) - 1 ] = $stretch[0];
				$has                            = true;
			}
			if ( ! $has ) {
				$level = 'same';
			}
		}
		return array( 'level' => $level, 'actions' => array_values( $picked ), 'note' => '' );
	}

	/**
	 * The band last week's plan used, worked out from the stored levels.
	 *
	 * @param int $enrolment_id Enrolment.
	 * @param int $week         Week being planned.
	 */
	private static function band_before( $enrolment_id, $week ) {
		global $wpdb;
		$levels = $wpdb->get_col( $wpdb->prepare( 'SELECT level FROM ' . LLD_Members_Schema::table( 'weekly_plan' ) . ' WHERE enrolment_id = %d AND week_number < %d ORDER BY week_number ASC', $enrolment_id, $week ) );
		$band   = 'standard';
		foreach ( (array) $levels as $level ) {
			if ( 'easier' === $level ) {
				$band = 'easier';
			} elseif ( 'stretch' === $level ) {
				$band = 'standard';
			}
		}
		return $band;
	}

	/**
	 * Build a plan for a week without storing it.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $week      Week 1 to 4.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string,completion:array{done:int,available:int,pct:float|null}}
	 */
	public static function build_plan( $member_id, $row, $week ) {
		$items      = self::activities( self::track_id( (string) $row->track_key ), 0, $member_id );
		$completion = $week > 1 ? self::completion( $member_id, $row, $week - 1 ) : array( 'done' => 0, 'available' => 0, 'pct' => null );
		$built      = self::select( $items, $completion['pct'], self::band_before( (int) $row->id, $week ), $week - 1 );
		$built['completion'] = $completion;
		return $built;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string}
	 */
	public static function generate( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return array( 'level' => 'same', 'actions' => array(), 'note' => 'Start a track to get a plan.' );
		}
		$week  = self::position_parts( self::programme_day( $member_id, (int) $row->id ) )['week'];
		$built = self::build_plan( $member_id, $row, $week );
		unset( $built['completion'] );
		return $built;
	}

	/**
	 * @param array<int,array<string,mixed>> $actions Snapshots.
	 */
	private static function action_refs( $actions ) {
		$refs = array();
		foreach ( $actions as $action ) {
			$refs[] = array(
				'id' => (int) ( $action['parent_id'] ?? 0 ),
				'v'  => (int) ( $action['version_id'] ?? 0 ),
			);
		}
		return $refs;
	}

	/**
	 * @param string $json Stored actions.
	 * @return array<int,array{id:int,v:int}>
	 */
	private static function stored_refs( $json ) {
		$raw  = json_decode( (string) $json, true );
		$refs = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$refs[] = array( 'id' => (int) ( $entry['id'] ?? 0 ), 'v' => (int) ( $entry['v'] ?? 0 ) );
			} else {
				$refs[] = array( 'id' => (int) $entry, 'v' => 0 );
			}
		}
		return $refs;
	}

	/**
	 * @param int $count Actions shown.
	 */
	private static function note_for( $count ) {
		if ( $count < 1 ) {
			return 'No safe actions are available this week.';
		}
		if ( $count < 3 ) {
			return 'Fewer than 3 safe actions are available.';
		}
		return '';
	}

	/**
	 * The week's stored plan, created on first read. Every read filters the stored action versions through the
	 * safety matrix in force; a removed action is replaced with a safe one where the library allows (section 8.2).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $week      Week 1 to 4.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string,swapped:bool,completion:array{done:int,available:int,pct:float|null},id:int}
	 */
	public static function plan_for( $member_id, $row, $week ) {
		global $wpdb;
		$table  = LLD_Members_Schema::table( 'weekly_plan' );
		$stored = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrolment_id = %d AND week_number = %d", (int) $row->id, $week ) );
		if ( ! $stored ) {
			$built = self::build_plan( $member_id, $row, $week );
			$wpdb->insert(
				$table,
				array(
					'member_id'            => (int) $member_id,
					'enrolment_id'         => (int) $row->id,
					'week_number'          => (int) $week,
					'actions'              => wp_json_encode( self::action_refs( $built['actions'] ) ),
					'level'                => $built['level'],
					'completion_done'      => $built['completion']['done'],
					'completion_available' => $built['completion']['available'],
					'completion_pct'       => null === $built['completion']['pct'] ? 0 : $built['completion']['pct'],
					'rule_version'         => self::RULE_VERSION,
					'swapped'              => 0,
					'created_on'           => self::local_today( $member_id ),
				),
				array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%d', '%s' )
			);
			$stored = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrolment_id = %d AND week_number = %d", (int) $row->id, $week ) );
			if ( ! $stored ) {
				$built['swapped'] = false;
				$built['id']      = 0;
				return $built;
			}
		}
		$refs    = self::stored_refs( (string) $stored->actions );
		$actions = array();
		$removed = 0;
		foreach ( $refs as $ref ) {
			$shown = self::snapshot( $ref['id'], $ref['v'] );
			if ( $shown && LLD_Members_Safety::allows( $member_id, $shown ) ) {
				$actions[] = $shown;
			} else {
				$removed++;
			}
		}
		if ( $removed > 0 ) {
			$have = array();
			foreach ( $actions as $action ) {
				$have[] = (int) $action['parent_id'];
			}
			foreach ( self::activities( self::track_id( (string) $row->track_key ), 0, $member_id ) as $item ) {
				if ( $removed < 1 ) {
					break;
				}
				if ( ! in_array( (int) $item['parent_id'], $have, true ) && 'stretch' !== self::difficulty( $item ) ) {
					$actions[] = $item;
					$have[]    = (int) $item['parent_id'];
					$removed--;
				}
			}
			$wpdb->update( $table, array( 'actions' => wp_json_encode( self::action_refs( $actions ) ) ), array( 'id' => (int) $stored->id ), array( '%s' ), array( '%d' ) );
		}
		return array(
			'level'      => (string) $stored->level,
			'actions'    => $actions,
			'note'       => self::note_for( count( $actions ) ),
			'swapped'    => (bool) $stored->swapped,
			'completion' => array(
				'done'      => (int) $stored->completion_done,
				'available' => (int) $stored->completion_available,
				'pct'       => (int) $stored->completion_available > 0 ? (float) $stored->completion_pct : null,
			),
			'id'         => (int) $stored->id,
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string}
	 */
	public static function stored_plan( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return array( 'level' => 'same', 'actions' => array(), 'note' => 'Start a track to get a plan.' );
		}
		$day = self::current_day( $member_id, (int) $row->id );
		if ( $day < 1 ) {
			return array( 'level' => 'same', 'actions' => array(), 'note' => 'Your plan appears when your track starts.' );
		}
		return self::plan_for( $member_id, $row, self::position_parts( $day )['week'] );
	}

	/**
	 * One swap a week. The new plan keeps the level and the count, and is then fixed for the week.
	 *
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function swap( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lld_plan', 'Start a track first.' );
		}
		$day = self::current_day( $member_id, (int) $row->id );
		if ( $day < 1 ) {
			return new WP_Error( 'lld_plan', 'Your plan appears when your track starts.' );
		}
		$week = self::position_parts( $day )['week'];
		$plan = self::plan_for( $member_id, $row, $week );
		if ( $plan['swapped'] ) {
			return new WP_Error( 'lld_plan', 'You can swap again next week.' );
		}
		$now = array();
		foreach ( $plan['actions'] as $action ) {
			$now[] = (int) $action['parent_id'];
		}
		$items = self::activities( self::track_id( (string) $row->track_key ), 0, $member_id );
		$built = self::select( $items, null, self::band_before( (int) $row->id, $week ), $week, $now, $plan['level'] );
		$next  = array();
		foreach ( $built['actions'] as $action ) {
			$next[] = (int) $action['parent_id'];
		}
		$fresh = array_diff( $next, $now );
		if ( ! $fresh || count( $built['actions'] ) < count( $plan['actions'] ) ) {
			return new WP_Error( 'lld_plan_none', 'There is no other safe action to swap in this week.' );
		}
		global $wpdb;
		$won = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . LLD_Members_Schema::table( 'weekly_plan' ) . ' SET swapped = 1, actions = %s, level = %s WHERE id = %d AND swapped = 0',
				wp_json_encode( self::action_refs( $built['actions'] ) ),
				$built['level'],
				$plan['id']
			)
		);
		if ( ! $won ) {
			return new WP_Error( 'lld_plan', 'You can swap again next week.' );
		}
		return true;
	}

	public static function plan_screen() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$posted    = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		$title     = "This week's plan";
		if ( ! self::premium_open( $member_id ) ) {
			LLD_Members_Personal::render( $title, '<h1>This week\'s plan</h1>' . lld_membership_lock( 'The weekly plan is part of Premium.' ), $posted ? 403 : 200 );
			return;
		}
		$main  = '<h1>This week\'s plan</h1>';
		$error = '';
		if ( $posted ) {
			if ( ! isset( $_POST['lld_plan_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_plan_nonce'] ) ), 'lld_plan' ) ) {
				LLD_Members_Personal::render( $title, $main . '<p>' . esc_html( 'Your page expired. Open the plan again.' ) . '</p>', 403 );
				return;
			}
			$swapped = self::swap( $member_id );
			$error   = is_wp_error( $swapped ) ? $swapped->get_error_message() : '';
			if ( ! $error ) {
				$main .= lld_state_saved();
			}
		}
		$main .= self::notices_html( $member_id );
		$row   = self::active( $member_id );
		if ( ! $row ) {
			$main .= lld_empty( 'Start a track to get a plan.', lld_button( array( 'label' => 'Choose a track', 'href' => home_url( '/members/tracks/' ) ) ) );
			LLD_Members_Personal::render( $title, $main );
			return;
		}
		$day = self::current_day( $member_id, (int) $row->id );
		if ( $day < 1 ) {
			$main .= '<p>' . esc_html( 'Your plan appears when your track starts on ' . self::nice_date( self::day_date( self::periods( (int) $row->id ), 1 ) ) . '.' ) . '</p>';
			LLD_Members_Personal::render( $title, $main );
			return;
		}
		$week = self::position_parts( $day )['week'];
		$plan = self::plan_for( $member_id, $row, $week );
		if ( '' !== $error ) {
			$main .= '<div class="lld-fail" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( ! $plan['actions'] ) {
			$main .= '<p>' . esc_html( $plan['note'] ) . '</p>' . LLD_Members_Safety::empty_html( $member_id );
			LLD_Members_Personal::render( $title, $main );
			return;
		}
		$labels = array(
			'easier'  => 'A lighter week',
			'stretch' => 'One stretch goal',
			'same'    => 'Same level',
		);
		$main  .= '<p class="lld-kicker">Week ' . (int) $week . '</p>';
		$main  .= '<p>' . esc_html( isset( $labels[ $plan['level'] ] ) ? $labels[ $plan['level'] ] : 'Same level' ) . '</p>';
		$main  .= '<p>' . esc_html( self::plan_reason( $week, $plan ) ) . '</p>';
		if ( $plan['note'] ) {
			$main .= '<p>' . esc_html( $plan['note'] ) . '</p>';
		}
		foreach ( $plan['actions'] as $action ) {
			$main .= '<article class="lld-action"><h2>' . esc_html( (string) $action['title'] ) . '</h2>';
			$main .= '<p>' . esc_html( (string) ( $action['body'] ?? '' ) ) . '</p>' . LLD_Members_Safety::cautions_html( $member_id, $action ) . '</article>';
		}
		if ( $plan['swapped'] && 'You can swap again next week.' !== $error ) {
			$main .= '<p>You can swap again next week.</p>';
		}
		$main .= '<form method="post">';
		$main .= wp_nonce_field( 'lld_plan', 'lld_plan_nonce', true, false );
		$main .= '<button class="lld-button" type="submit" name="lld_swap" value="1">Swap my plan</button></form>';
		LLD_Members_Personal::render( $title, $main );
	}

	/**
	 * Why the plan was chosen (SCR-12). Numbers stay on the screen; they never go in an email.
	 *
	 * @param int                 $week Week.
	 * @param array<string,mixed> $plan Plan.
	 */
	private static function plan_reason( $week, $plan ) {
		if ( $week < 2 || null === $plan['completion']['pct'] ) {
			return 'Chosen from your track\'s actions. This first plan starts at the usual level.';
		}
		$line = 'Chosen from your track\'s actions. Last week you did ' . (int) $plan['completion']['done'] . ' of ' . (int) $plan['completion']['available'] . ' activities';
		if ( 'easier' === $plan['level'] ) {
			return $line . ', so this week is a little lighter.';
		}
		if ( 'stretch' === $plan['level'] ) {
			return $line . ', so one action is a stretch.';
		}
		return $line . ', so the level stays the same.';
	}

	/**
	 * Dashboard "This week's plan" (SCR-09): the stored plan's titles for Premium, a short line otherwise.
	 *
	 * @param int $member_id Member number.
	 */
	public static function dashboard_plan_html( $member_id ) {
		if ( ! self::premium_open( $member_id ) ) {
			return '<p>The weekly plan is part of Premium.</p>';
		}
		$plan = self::stored_plan( $member_id );
		if ( ! $plan['actions'] ) {
			return '<p>' . esc_html( $plan['note'] ) . '</p>';
		}
		$html = '<ul>';
		foreach ( array_slice( $plan['actions'], 0, 5 ) as $action ) {
			$html .= '<li>' . esc_html( (string) $action['title'] ) . '</li>';
		}
		return $html . '</ul><p><a href="' . esc_url( home_url( '/members/plan/' ) ) . '">Open this week\'s plan</a></p>';
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Screens: track overview and day (SCR-10), start and switch (SCR-25), dashboard line (SCR-09)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * @param string $screen Screen id.
	 */
	public static function screen( $screen ) {
		if ( 'track' === $screen ) {
			self::track_screen();
			return;
		}
		if ( 'review' === $screen ) {
			self::review_screen();
		}
	}

	/**
	 * A track stopped because the health profile now excludes it: told once, with the matrix row's wording.
	 *
	 * @param int $member_id Member number.
	 */
	public static function notices_html( $member_id ) {
		self::settle( $member_id );
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, track_key FROM {$table} WHERE member_id = %d AND status = 'stopped' AND stopped_why = %s AND notice_shown = 0", $member_id, 'not_suitable' ) );
		$html  = '';
		foreach ( (array) $rows as $row ) {
			$title = self::track_title( (string) $row->track_key );
			$html .= lld_not_suitable( $title . ' is no longer offered to you, so it has stopped. Your progress is kept.', '', home_url( '/members/tracks/' ), 'Choose another track', self::blocking_notes_html( $member_id, (string) $row->track_key ) );
			$wpdb->update( $table, array( 'notice_shown' => 1 ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
		}
		return $html;
	}

	/**
	 * The note of each matrix row that takes this track away, with its helpline link.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 */
	private static function blocking_notes_html( $member_id, $key ) {
		$title = self::safety_title( $key );
		$html  = '';
		$seen  = array();
		foreach ( LLD_Members_Safety::situations( $member_id ) as $situation ) {
			$rule    = LLD_Members_Safety::rule( $situation );
			$matches = false;
			foreach ( (array) $rule['tracks'] as $blocked ) {
				$matches = $matches || 0 === strcasecmp( (string) $blocked, $title );
			}
			if ( ! $matches || '' === $rule['note'] || isset( $seen[ $rule['note'] ] ) ) {
				continue;
			}
			$seen[ $rule['note'] ] = true;
			$html                 .= '<p>' . esc_html( $rule['note'] ) . '</p>';
			$link                  = esc_url( (string) $rule['help_link'], array( 'https' ) );
			if ( '' !== $link ) {
				$html .= '<p class="lld-help-link"><a href="' . $link . '">Find support in your country</a></p>';
			}
		}
		return $html;
	}

	/**
	 * The dashboard's "Today's activity" line (SCR-09 states).
	 *
	 * @param int $member_id Member number.
	 */
	public static function today_html( $member_id ) {
		$html = self::notices_html( $member_id );
		$row  = self::current( $member_id );
		if ( ! $row || ( 'stopped' === (string) $row->status && 'not_suitable' === (string) $row->stopped_why ) ) {
			return $html . '<p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Start a track</a></p>';
		}
		$title   = self::track_title( (string) $row->track_key );
		$periods = self::periods( (int) $row->id );
		$reached = min( self::DAYS, self::days_until( $periods, self::local_today( $member_id ) ) );
		if ( 'active' === (string) $row->status ) {
			if ( $reached < 1 ) {
				return $html . '<p>' . esc_html( $title . ' starts on ' . self::nice_date( self::day_date( $periods, 1 ) ) . '.' ) . '</p>';
			}
			$html .= '<p>' . esc_html( $title . '. ' . self::position_label( $member_id ) ) . '.</p>';
			$html .= '<p><a href="' . esc_url( home_url( '/members/track/?day=' . $reached ) ) . '">Open today\'s activity</a></p>';
			if ( self::premium_ended( $member_id ) && $row->last_open_day ) {
				$html .= '<p>' . esc_html( 'Your current week stays open until ' . self::nice_date( (string) $row->last_open_day ) . '.' ) . '</p>';
			}
			return $html;
		}
		if ( 'finished' === (string) $row->status ) {
			return $html . '<p>' . esc_html( 'You finished ' . $title . '.' ) . '</p><p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Choose your next track</a> or <a href="' . esc_url( home_url( '/members/challenge/' ) ) . '">try this month\'s challenge</a>.</p>';
		}
		if ( 'stopped' === (string) $row->status && 'free_week' === (string) $row->stopped_why ) {
			return $html . '<p>' . esc_html( $title . ' is set aside at day ' . $reached . '.' ) . '</p><p>Week 1 is complete. Premium opens the rest of this track.</p>';
		}
		if ( 'stopped' === (string) $row->status ) {
			return $html . '<p>' . esc_html( $title . ' is set aside at day ' . $reached . '.' ) . '</p><p>Premium opens the rest of this track.</p>';
		}
		return $html . '<p>' . esc_html( $title . ' is set aside at day ' . $reached . '.' ) . '</p><p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Resume or choose a track</a></p>';
	}

	/**
	 * One track on the start, switch, resume or repeat screen (SCR-25). Safety and the session hold run before this.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 * @param string $title     Track name.
	 */
	public static function track_choice_html( $member_id, $key, $title ) {
		$today   = self::local_today( $member_id );
		$current = self::active( $member_id );
		$target  = self::latest_for_track( $member_id, $key );
		$open    = self::premium_open( $member_id );
		$html    = '<section class="lld-card lld-track-choice"><h2>' . esc_html( $title ) . '</h2>';
		if ( $current && (string) $current->track_key === $key ) {
			$reached = self::current_day( $member_id, (int) $current->id );
			$status  = $reached < 1 ? 'Starts ' . self::nice_date( self::day_date( self::periods( (int) $current->id ), 1 ) ) : 'In progress, week ' . self::position_parts( $reached )['week'];
			return $html . '<p>' . esc_html( $status ) . '</p><p><a href="' . esc_url( home_url( '/members/track/' ) ) . '">Open your track</a></p></section>';
		}
		$verb   = 'Start';
		$status = $open ? '' : 'Free week available';
		$reach  = 0;
		if ( $target ) {
			$reach = min( self::DAYS, self::days_until( self::periods( (int) $target->id ), $today ) );
			if ( 'finished' === (string) $target->status ) {
				$verb   = 'Repeat';
				$status = 'Finished';
			} else {
				$verb   = 'Resume';
				$status = 'Set aside at day ' . $reach;
			}
			if ( ! $open && ( 'finished' === (string) $target->status || $reach >= self::access_limit( $member_id, $target ) ) ) {
				return $html . '<p>' . esc_html( $status . '. Free week used.' ) . '</p>' . lld_membership_lock( 'Premium continues ' . $title . '.' ) . '</section>';
			}
		}
		if ( '' !== $status ) {
			$html .= '<p>' . esc_html( $status ) . '</p>';
		}
		$forced = false;
		if ( $current ) {
			$periods = self::periods( (int) $current->id );
			$reached = self::days_until( $periods, $today );
			if ( $reached > 0 && 0 !== $reached % 7 ) {
				return $html . '<p>' . esc_html( 'You can switch at the end of this week on ' . self::nice_date( self::day_date( $periods, (int) ceil( $reached / 7 ) * 7 ) ) . '.' ) . '</p></section>';
			}
			if ( $reached > 0 ) {
				$forced = true;
				$html  .= '<p>' . esc_html( 'Your progress on ' . self::track_title( (string) $current->track_key ) . ' is kept, and you can resume it later. ' . $title . ' starts tomorrow.' ) . '</p>';
			}
		}
		if ( 'Resume' === $verb ) {
			$html .= '<p>' . esc_html( "You'll continue from day " . min( self::DAYS, $reach + 1 ) . '.' ) . '</p>';
		}
		if ( 'sleep-stress' === $key ) {
			return $html . '<p><a href="' . esc_url( home_url( '/members/screening/?track=sleep-stress' ) ) . '">' . esc_html( $verb . ' ' . $title ) . '</a></p></section>';
		}
		$html .= '<form method="post"><p>';
		$html .= wp_nonce_field( 'lld_track', 'lld_track_nonce', true, false );
		$html .= '<input type="hidden" name="lld_track" value="' . esc_attr( $key ) . '">';
		if ( ! $forced ) {
			$html .= '<span class="lld-check"><label><input type="radio" name="lld_start" value="today" checked> Start today</label> ';
			$html .= '<label><input type="radio" name="lld_start" value="tomorrow"> Start tomorrow</label></span> ';
		}
		$html .= '<button class="lld-button" type="submit">' . esc_html( $verb . ' ' . $title ) . '</button></p></form></section>';
		return $html;
	}

	private static function track_screen() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$day       = isset( $_GET['day'] ) ? absint( $_GET['day'] ) : 0;
		if ( $day > 0 || isset( $_GET['day'] ) ) {
			self::day_screen( $member_id, $day );
			return;
		}
		$main = '<h1>Your track</h1>' . self::notices_html( $member_id );
		$row  = self::current( $member_id );
		if ( ! $row ) {
			$main .= lld_empty( 'Your programme appears here once you start a track.', lld_button( array( 'label' => 'Start a track', 'href' => home_url( '/members/tracks/' ) ) ) );
			LLD_Members_Personal::render( 'Your track', $main );
			return;
		}
		$title   = self::track_title( (string) $row->track_key );
		$periods = self::periods( (int) $row->id );
		$today   = self::local_today( $member_id );
		$reached = min( self::DAYS, self::days_until( $periods, $today ) );
		$limit   = self::access_limit( $member_id, $row );
		$track   = self::track_id( (string) $row->track_key );
		$main   .= '<h2>' . esc_html( $title ) . '</h2>';
		$status  = (string) $row->status;
		if ( 'active' === $status && $reached < 1 ) {
			$main .= '<p>' . esc_html( 'Starts ' . self::nice_date( self::day_date( $periods, 1 ) ) . '.' ) . '</p>';
		} elseif ( 'active' === $status ) {
			$main .= '<p>' . esc_html( self::position_label( $member_id ) ) . '</p>';
			if ( count( $periods ) > 1 && self::days_until( $periods, $periods[ count( $periods ) - 1 ]['first'] ) === $reached ) {
				$main .= '<p>' . esc_html( 'Welcome back, day ' . $reached . '.' ) . '</p>';
			}
		} elseif ( 'finished' === $status ) {
			$main .= '<p>' . esc_html( 'Finished on ' . self::nice_date( (string) $row->finished_on ) . '.' ) . '</p>';
			$main .= '<p>Try the next track or this month\'s challenge.</p><p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Choose a track</a> <a href="' . esc_url( home_url( '/members/challenge/' ) ) . '">Monthly challenge</a></p>';
		} elseif ( 'stopped' === $status && 'not_suitable' === (string) $row->stopped_why ) {
			$main .= LLD_Members_Safety::not_suitable( $member_id, $title . ' is not suitable for you.', home_url( '/members/tracks/' ), 'Choose another track' );
			LLD_Members_Personal::render( 'Your track', $main );
			return;
		} else {
			$main .= '<p>' . esc_html( 'Set aside at day ' . $reached . '.' ) . '</p>';
			if ( ! self::premium_open( $member_id ) && 'stopped' === $status ) {
				$main .= lld_membership_lock( 'Premium opens the rest of this track.' );
			} else {
				$main .= '<p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Resume it from the tracks page</a></p>';
			}
		}
		if ( 'active' === $status && self::premium_ended( $member_id ) && $row->last_open_day ) {
			$main .= '<p>' . esc_html( 'Your current week stays open until ' . self::nice_date( (string) $row->last_open_day ) . '. Later weeks need Premium.' ) . '</p>';
		}
		$main .= lld_progress( self::done_count( $member_id ), self::DAYS );
		$main .= '<ol class="lld-weeks">';
		for ( $week = 1; $week <= 4; $week++ ) {
			$theme = self::week_item( 'lld_week', $track, $week, $member_id );
			if ( $week * 7 <= $reached ) {
				$state = 'done';
			} elseif ( ( $week - 1 ) * 7 + 1 > $limit ) {
				$state = 'locked';
			} elseif ( ( $week - 1 ) * 7 < $reached ) {
				$state = 'open';
			} else {
				$state = 'not open yet';
			}
			$main .= '<li>' . esc_html( 'Week ' . $week . ( $theme ? ': ' . (string) $theme['title'] : '' ) . ' (' . $state . ')' ) . '</li>';
		}
		$main .= '</ol>';
		if ( 'active' === $status && $reached > 0 ) {
			$main .= '<p><a href="' . esc_url( home_url( '/members/track/?day=' . $reached ) ) . '">Open today</a></p>';
		}
		$main .= '<p><a href="' . esc_url( home_url( '/members/check-in/' ) ) . '">Weekly check-in</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/plan/' ) ) . '">Weekly plan</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/review/' ) ) . '">Weekly review</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Start, switch or resume a track</a></p>';
		LLD_Members_Personal::render( 'Your track', $main );
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	private static function day_screen( $member_id, $day ) {
		$asked = isset( $_GET['enrolment'] ) ? absint( $_GET['enrolment'] ) : 0;
		$row   = self::enrolment_for( $member_id, $asked );
		$label = 'Day ' . (int) $day;
		$head  = '<h1>Day ' . (int) $day . '</h1>';
		$saved = false;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( ! isset( $_POST['lld_mark_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_mark_nonce'] ) ), 'lld_mark' ) ) {
				LLD_Members_Personal::render( $label, $head . '<p>Your page expired. Open the day again.</p>', 403 );
				return;
			}
			$result = isset( $_POST['lld_result'] ) ? sanitize_key( wp_unslash( $_POST['lld_result'] ) ) : '';
			$item   = isset( $_POST['lld_item'] ) ? absint( $_POST['lld_item'] ) : 0;
			$marked = self::mark( $member_id, $day, $result, $item, $row ? (int) $row->id : 0 );
			if ( is_wp_error( $marked ) ) {
				$status = 'lld_premium' === $marked->get_error_code() ? 403 : 200;
				LLD_Members_Personal::render( $label, $head . '<div class="lld-fail" role="alert"><p>' . esc_html( $marked->get_error_message() ) . '</p></div><p><a href="' . esc_url( home_url( '/members/track/?day=' . (int) $day ) ) . '">Back to day ' . (int) $day . '</a></p>', $status );
				return;
			}
			$saved = true;
		}
		if ( ! $row ) {
			LLD_Members_Personal::render( $label, $head . lld_empty( 'Your programme appears here once you start a track.', lld_button( array( 'label' => 'Start a track', 'href' => home_url( '/members/tracks/' ) ) ) ) );
			return;
		}
		$problem = self::open_problem( $member_id, $row, (int) $day );
		$title   = self::track_title( (string) $row->track_key );
		if ( 'missing' === $problem ) {
			LLD_Members_Personal::render( "This page isn't available", lld_state_not_allowed(), 404 );
			return;
		}
		if ( 'not_suitable' === $problem ) {
			LLD_Members_Personal::render( 'Not suitable for you', $head . LLD_Members_Safety::not_suitable( $member_id, $title . ' is not suitable for you.', home_url( '/members/tracks/' ), 'Choose another track' ) );
			return;
		}
		if ( 'premium' === $problem ) {
			$line = self::premium_ended( $member_id ) ? 'Later weeks of this track are part of Premium.' : 'Day 8 onwards is part of Premium.';
			LLD_Members_Personal::render( $label, $head . lld_membership_lock( $line ) );
			return;
		}
		$periods = self::periods( (int) $row->id );
		if ( 'not_yet' === $problem ) {
			$line = 'active' === (string) $row->status
				? 'Day ' . (int) $day . ' opens on ' . self::nice_date( self::day_date( $periods, (int) $day ) ) . '.'
				: 'Resume this track to open day ' . (int) $day . '.';
			LLD_Members_Personal::render( $label, $head . '<p>' . esc_html( $line ) . '</p><p><a href="' . esc_url( home_url( '/members/track/' ) ) . '">Back to your track</a></p>' );
			return;
		}
		$pos   = self::position_parts( $day );
		$main  = '<p class="lld-kicker">' . esc_html( $title . ', week ' . $pos['week'] . ', day ' . $pos['day'] ) . '</p>' . $head;
		$main .= $saved ? '<p class="lld-toast" role="status">Saved</p>' : '';
		if ( count( $periods ) > 1 && self::days_until( $periods, $periods[ count( $periods ) - 1 ]['first'] ) === (int) $day ) {
			$main .= '<p>' . esc_html( 'Welcome back, day ' . (int) $day . '.' ) . '</p>';
		}
		if ( self::premium_ended( $member_id ) && $row->last_open_day && 'active' === (string) $row->status ) {
			$main .= '<p>' . esc_html( 'Your current week stays open until ' . self::nice_date( (string) $row->last_open_day ) . '.' ) . '</p>';
		}
		$found   = self::day_items( $member_id, $row, $day );
		$results = self::results( $member_id, (int) $row->id );
		$late    = '' !== self::mark_problem( $member_id, $row, $day );
		if ( $found['replaced'] && $found['items'] ) {
			$main .= '<p>This day\'s activity is not suitable for you. Here is another one.</p>';
		} elseif ( $found['replaced'] ) {
			$main .= LLD_Members_Safety::empty_html( $member_id );
		} elseif ( ! $found['items'] ) {
			$main .= '<p>No activity for this day.</p>';
		}
		foreach ( $found['items'] as $item ) {
			$parent = (int) $item['parent_id'];
			$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
			$main  .= '<article class="lld-activity"><h2>' . esc_html( (string) $item['title'] ) . '</h2>';
			$main  .= LLD_Members_Safety::cautions_html( $member_id, $item );
			$main  .= '<div class="lld-content">' . esc_html( (string) $item['body'] ) . '</div>';
			if ( ! empty( $fields['link'] ) ) {
				$main .= '<p><a href="' . esc_url( (string) $fields['link'] ) . '">' . esc_html( 'More about ' . (string) $item['title'] ) . '</a></p>';
			}
			if ( isset( $results[ $day ][ $parent ] ) ) {
				$main .= '<p>' . esc_html( 'Marked ' . ( 'done' === $results[ $day ][ $parent ] ? 'done' : 'skipped' ) . '.' ) . ( $late ? '' : ' You can change it.' ) . '</p>';
			}
			if ( $late ) {
				$main .= '<p>This activity can no longer be marked.</p>';
			}
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_mark', 'lld_mark_nonce', true, false );
			$main .= '<input type="hidden" name="lld_day" value="' . (int) $day . '">';
			$main .= '<input type="hidden" name="lld_item" value="' . $parent . '">';
			$main .= '<button class="lld-button" type="submit" name="lld_result" value="done">Done</button> ';
			$main .= '<button class="lld-button lld-button-secondary" type="submit" name="lld_result" value="skipped">Skip</button>';
			$main .= '</form></article>';
		}
		$track  = self::track_id( (string) $row->track_key );
		$lesson = self::week_item( 'lld_lesson', $track, $pos['week'], $member_id );
		if ( $lesson ) {
			$main .= '<section class="lld-lesson"><h2>' . esc_html( 'Lesson: ' . (string) $lesson['title'] ) . '</h2><div class="lld-content">' . wp_kses_post( wpautop( (string) $lesson['body'] ) ) . '</div></section>';
		}
		$task = self::week_item( 'lld_week', $track, $pos['week'], $member_id );
		if ( $task ) {
			$main .= '<section class="lld-task"><h2>This week\'s task</h2><p>' . esc_html( (string) $task['title'] ) . '</p><div class="lld-content">' . esc_html( (string) $task['body'] ) . '</div></section>';
		}
		$main .= self::catch_up_html( $member_id, $row, $day );
		if ( 'finished' === (string) $row->status && self::DAYS === (int) $day ) {
			$main .= '<p>You finished this track. Try the next track or this month\'s challenge.</p>';
		}
		LLD_Members_Personal::render( $label, $main );
	}

	/**
	 * "Catch up": the previous days that can still be ticked, including the last days of a track set aside today.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $day       Day shown.
	 */
	private static function catch_up_html( $member_id, $row, $day ) {
		$links = '';
		foreach ( array( $day - 1, $day - 2 ) as $earlier ) {
			if ( $earlier >= 1 && '' === self::mark_problem( $member_id, $row, $earlier ) ) {
				$links .= '<li><a href="' . esc_url( home_url( '/members/track/?day=' . $earlier . '&enrolment=' . (int) $row->id ) ) . '">Day ' . (int) $earlier . '</a></li>';
			}
		}
		if ( 'active' === (string) $row->status && $day <= 2 ) {
			global $wpdb;
			$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND id <> %d AND status = 'set_aside' ORDER BY id DESC LIMIT 1", $member_id, (int) $row->id ) );
			if ( $before ) {
				$reached = min( self::DAYS, self::days_until( self::periods( (int) $before->id ), self::local_today( $member_id ) ) );
				foreach ( array( $reached, $reached - 1 ) as $earlier ) {
					if ( $earlier >= 1 && '' === self::mark_problem( $member_id, $before, $earlier ) ) {
						$links .= '<li><a href="' . esc_url( home_url( '/members/track/?day=' . $earlier . '&enrolment=' . (int) $before->id ) ) . '">' . esc_html( self::track_title( (string) $before->track_key ) . ', day ' . $earlier ) . '</a></li>';
					}
				}
			}
		}
		return '' === $links ? '' : '<h2>Catch up</h2><ul>' . $links . '</ul>';
	}

	/**
	 * Mark one activity done or skipped (C8). The result is stored against the exact item and version marked.
	 *
	 * @param int    $member_id    Member number.
	 * @param int    $day          Programme day.
	 * @param string $result       done or skipped.
	 * @param int    $item_id      Activity marked, or 0 when the day has one activity.
	 * @param int    $enrolment_id Enrolment, or 0 for the current one.
	 * @return true|WP_Error
	 */
	public static function mark( $member_id, $day, $result, $item_id = 0, $enrolment_id = 0 ) {
		if ( ! in_array( $result, array( 'done', 'skipped' ), true ) ) {
			return new WP_Error( 'lld_mark', 'Choose done or skip.' );
		}
		$row = self::enrolment_for( $member_id, (int) $enrolment_id );
		if ( ! $row ) {
			return new WP_Error( 'lld_mark', 'Start a track first.' );
		}
		$problem = self::mark_problem( $member_id, $row, (int) $day );
		if ( '' !== $problem ) {
			return new WP_Error( 0 === strpos( $problem, 'Day ' ) ? 'lld_premium' : 'lld_mark', $problem );
		}
		$found = self::day_items( $member_id, $row, (int) $day );
		$item  = null;
		foreach ( $found['items'] as $candidate ) {
			if ( (int) $candidate['parent_id'] === (int) $item_id || ( 0 === (int) $item_id && 1 === count( $found['items'] ) ) ) {
				$item = $candidate;
			}
		}
		if ( ! $item ) {
			return new WP_Error( 'lld_mark', $found['items'] ? 'Choose the activity to mark.' : 'This activity can no longer be marked.' );
		}
		$parent  = (int) $item['parent_id'];
		$version = 'a' . $parent . 'v' . (int) $item['version'];
		$when    = self::day_date( self::periods( (int) $row->id ), (int) $day );
		global $wpdb;
		$table = LLD_Members_Schema::table( 'result' );
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE member_id = %d AND context_type = %s AND context_id = %d AND programme_day = %d AND item_version LIKE %s",
				$member_id,
				'enrolment',
				(int) $row->id,
				(int) $day,
				$wpdb->esc_like( 'a' . $parent . 'v' ) . '%'
			)
		);
		$stored = array(
			'item_version' => $version,
			'result'       => $result,
			'recorded_at'  => LLD_Members_Clock::now( $member_id )->format( 'Y-m-d H:i:s' ),
			'local_date'   => $when,
		);
		if ( $id ) {
			$wpdb->update( $table, $stored, array( 'id' => $id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
			return true;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'     => $member_id,
				'item_version'  => $version,
				'context_type'  => 'enrolment',
				'context_id'    => (int) $row->id,
				'programme_day' => (int) $day,
				'local_date'    => $when,
				'result'        => $result,
				'recorded_at'   => $stored['recorded_at'],
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return true;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Weekly check-in (FR2-09, SCR-11, C9) and reflections (C10)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Measure definitions: field name, metric label, imperial label, unit, kind, metric range, plausible range.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function measure_defs() {
		return array(
			'weight'   => array( 'field' => 'lld_weight', 'label' => 'Weight in kilograms', 'imperial' => 'Weight in pounds', 'column' => 'weight_kg', 'unit' => 'kg', 'name' => 'Weight', 'decimal' => true, 'min' => 20, 'max' => 400, 'check' => array( 30, 250 ) ),
			'waist'    => array( 'field' => 'lld_waist', 'label' => 'Waist in centimetres', 'imperial' => 'Waist in inches', 'column' => 'waist_cm', 'unit' => 'cm', 'name' => 'Waist', 'decimal' => true, 'min' => 30, 'max' => 300, 'check' => array( 50, 200 ) ),
			'activity' => array( 'field' => 'lld_activity', 'label' => 'Minutes of activity this week', 'column' => 'activity_minutes', 'unit' => 'minutes', 'name' => 'Activity', 'decimal' => false, 'min' => 0, 'max' => 5040, 'check' => array( 0, 2100 ) ),
			'chair'    => array( 'field' => 'lld_chair', 'label' => 'Chair stands in 30 seconds', 'column' => 'chair_stands', 'unit' => '', 'name' => 'Chair stands', 'decimal' => false, 'min' => 0, 'max' => 100, 'check' => array( 0, 60 ) ),
			'push'     => array( 'field' => 'lld_push', 'label' => 'Push-ups', 'column' => 'push_ups', 'unit' => '', 'name' => 'Push-ups', 'decimal' => false, 'min' => 0, 'max' => 500, 'check' => array( 0, 150 ) ),
			'strength' => array( 'field' => 'lld_strength', 'label' => 'Strength sessions', 'column' => 'strength_sessions', 'unit' => '', 'name' => 'Strength sessions', 'decimal' => false, 'min' => 0, 'max' => 21, 'check' => array( 0, 14 ) ),
			'sleep'    => array( 'field' => 'lld_sleep', 'label' => 'Average sleep hours', 'column' => 'sleep_hours', 'unit' => 'hours', 'name' => 'Sleep', 'decimal' => true, 'min' => 0, 'max' => 24, 'check' => array( 3, 14 ) ),
			'quality'  => array( 'field' => 'lld_quality', 'label' => 'Sleep quality from 1 to 5', 'column' => 'sleep_quality', 'unit' => '', 'name' => 'Sleep quality', 'decimal' => false, 'min' => 1, 'max' => 5, 'check' => array( 1, 5 ) ),
			'stress'   => array( 'field' => 'lld_stress', 'label' => 'Stress level from 1 to 5', 'column' => 'stress_level', 'unit' => '', 'name' => 'Stress level', 'decimal' => false, 'min' => 1, 'max' => 5, 'check' => array( 1, 5 ) ),
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function imperial( $member_id ) {
		global $wpdb;
		return 'imperial' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT units FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function eating_disorder( $member_id ) {
		return in_array( 'eating-disorder', LLD_Members_Safety::situations( $member_id ), true );
	}

	/**
	 * Whether this enrolment's check-in may be saved today (section 8.3: read-only outside access).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 */
	private static function check_in_open( $member_id, $row ) {
		if ( 'active' !== (string) $row->status ) {
			return false;
		}
		$periods = self::periods( (int) $row->id );
		$reached = self::days_until( $periods, self::local_today( $member_id ) );
		if ( $reached < 1 ) {
			return false;
		}
		return $reached <= self::access_limit( $member_id, $row );
	}

	/**
	 * Reflection questions (B14): the approved wording, each with its version. Built-in wording is used until the
	 * owner approves the questions.
	 *
	 * @return array<int,array{label:string,version:string}>
	 */
	public static function reflection_questions() {
		$fallback = array(
			1 => 'What helped you rest this week?',
			2 => 'What got in the way?',
			3 => 'What will you try next?',
		);
		$out   = array();
		$posts = get_posts(
			array(
				'post_type'      => 'lld_wording',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
			)
		);
		foreach ( $posts as $post ) {
			$shown  = LLD_Members_Content::shown( $post->ID );
			$fields = is_array( $shown ) && isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
			if ( preg_match( '/^reflection-([123])$/', (string) ( $fields['kind'] ?? '' ), $m ) && '' !== trim( (string) $shown['body'] ) ) {
				$out[ (int) $m[1] ] = array(
					'label'   => wp_strip_all_tags( (string) $shown['body'] ),
					'version' => 'w' . (int) $shown['parent_id'] . 'v' . (int) $shown['version'],
				);
			}
		}
		foreach ( $fallback as $n => $label ) {
			if ( ! isset( $out[ $n ] ) ) {
				$out[ $n ] = array( 'label' => $label, 'version' => 'builtin-' . $n );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Start (week 1) or end (week 4) for the Sleep and Stress reflection, or ''.
	 *
	 * @param object $row  Enrolment.
	 * @param int    $week Week.
	 */
	private static function reflection_when( $row, $week ) {
		if ( 'sleep-stress' !== (string) $row->track_key ) {
			return '';
		}
		return 1 === (int) $week ? 'start' : ( 4 === (int) $week ? 'end' : '' );
	}

	/**
	 * Check-in fields for the active track. Height stays on the personal screen.
	 *
	 * @param int $member_id Member number.
	 */
	public static function check_in_html( $member_id ) {
		$row = self::current( $member_id );
		if ( ! $row ) {
			return lld_empty( 'Start a track to use the weekly check-in.', lld_button( array( 'label' => 'Start a track', 'href' => home_url( '/members/tracks/' ) ) ) );
		}
		if ( 'active' === (string) $row->status && self::current_day( $member_id, (int) $row->id ) < 1 ) {
			return '<p>' . esc_html( 'Your check-in opens when your track starts on ' . self::nice_date( self::day_date( self::periods( (int) $row->id ), 1 ) ) . '.' ) . '</p>';
		}
		if ( ! self::check_in_open( $member_id, $row ) ) {
			$html = '<p>Check-ins for this track are read-only now.</p>';
			if ( 'stopped' === (string) $row->status && 'not_suitable' !== (string) $row->stopped_why && ! self::premium_open( $member_id ) ) {
				$html .= lld_membership_lock( 'Check-ins after this week are part of Premium.' );
			}
			return $html . self::check_in_summary( $member_id, (int) $row->id );
		}
		global $wpdb;
		$week    = self::position_parts( self::programme_day( $member_id, (int) $row->id ) )['week'];
		$table   = LLD_Members_Schema::table( 'check_in' );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrolment_id = %d AND week_number = %d", (int) $row->id, $week ) );
		$fields  = self::measures( (string) $row->track_key );
		$defs    = self::measure_defs();
		$imp     = self::imperial( $member_id );
		$html    = '<h2>' . esc_html( 'Week ' . $week . ' check-in' ) . '</h2>';
		if ( $current ) {
			$html .= '<p>You have checked in this week. Saving again corrects this week\'s entry.</p>';
		} elseif ( $week > 1 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND week_number = %d", (int) $row->id, $week - 1 ) ) ) {
			$html .= '<p>There was no check-in last week. That is fine; this week is a fresh start.</p>';
		}
		if ( in_array( 'weight', $fields, true ) && self::eating_disorder( $member_id ) ) {
			$html .= '<p class="lld-info">Weight is optional. You can leave it empty.</p>';
		}
		$skipped = $current && $current->tests_skipped ? explode( ',', (string) $current->tests_skipped ) : array();
		$html   .= '<form method="post" class="lld-form">';
		$html   .= wp_nonce_field( 'lld_measures', 'lld_measures_nonce', true, false );
		foreach ( $fields as $field ) {
			if ( 'chair' === $field ) {
				$html .= '<p class="lld-caution">Use a sturdy chair with no wheels, placed against a wall. Stop if you feel dizzy or in pain. Skip this test if you have balance problems, a recent injury or a doctor\'s restriction.</p>';
			}
			$def   = $defs[ $field ];
			$value = $current && null !== $current->{$def['column']} ? (float) $current->{$def['column']} : null;
			if ( null !== $value && $imp && in_array( $field, array( 'weight', 'waist' ), true ) ) {
				$value = 'weight' === $field ? round( $value / 0.45359237, 1 ) : round( $value / 2.54, 1 );
			}
			$label = $imp && isset( $def['imperial'] ) ? $def['imperial'] : $def['label'];
			$shown = null === $value ? '' : rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
			$html .= '<p><label for="' . esc_attr( $def['field'] ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $def['field'] ) . '" name="' . esc_attr( $def['field'] ) . '" inputmode="' . ( $def['decimal'] ? 'decimal' : 'numeric' ) . '" value="' . esc_attr( $shown ) . '"></p>';
			if ( in_array( $field, array( 'chair', 'push' ), true ) ) {
				$html .= '<p><label class="lld-check"><input type="checkbox" name="lld_skip_' . esc_attr( $field ) . '" value="1"' . ( in_array( $field, $skipped, true ) ? ' checked' : '' ) . '> Skip this test</label></p>';
			}
		}
		$when = self::reflection_when( $row, $week );
		if ( '' !== $when ) {
			$html .= '<fieldset class="lld-reflection"><legend>Personal reflection</legend>';
			$html .= '<p class="lld-hint">These questions are a personal reflection, not a validated measure. Answers are optional.</p>';
			foreach ( self::reflection_questions() as $n => $question ) {
				$html .= '<p><label for="lld_reflect_' . (int) $n . '">' . esc_html( $question['label'] ) . '</label><textarea id="lld_reflect_' . (int) $n . '" name="lld_reflect_' . (int) $n . '" rows="2" maxlength="500"></textarea></p>';
			}
			$html .= '</fieldset>';
		}
		$html .= '<p><label class="lld-check"><input type="checkbox" name="lld_confirm" value="1"> I\'ve checked these values</label></p>';
		$html .= lld_button( array( 'label' => 'Save check-in' ) );
		$html .= '</form>';
		$html .= self::check_in_summary( $member_id, (int) $row->id );
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function save_check_in( $member_id ) {
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return new WP_Error( 'lld_check', 'Health consent is off.', array( 'status' => 403 ) );
		}
		$row = self::active( $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lld_check', 'Start a track first.', array( 'status' => 403 ) );
		}
		if ( ! self::check_in_open( $member_id, $row ) ) {
			return new WP_Error( 'lld_check', 'Check-ins for this track are read-only now.', array( 'status' => 403 ) );
		}
		$week    = self::position_parts( self::programme_day( $member_id, (int) $row->id ) )['week'];
		$fields  = self::measures( (string) $row->track_key );
		$defs    = self::measure_defs();
		$imp     = self::imperial( $member_id );
		$confirm = ! empty( $_POST['lld_confirm'] );
		$values  = array();
		$skipped = array();
		foreach ( $fields as $field ) {
			$def = $defs[ $field ];
			if ( in_array( $field, array( 'chair', 'push' ), true ) && ! empty( $_POST[ 'lld_skip_' . $field ] ) ) {
				$skipped[] = $field;
				continue;
			}
			$raw = isset( $_POST[ $def['field'] ] ) ? trim( (string) wp_unslash( $_POST[ $def['field'] ] ) ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$raw = str_replace( ',', '.', $raw );
			if ( ! is_numeric( $raw ) || ( ! $def['decimal'] && (string) (int) $raw !== ltrim( $raw, '+' ) ) ) {
				return new WP_Error( 'lld_check', $def['name'] . ': enter a number.' );
			}
			$number = (float) $raw;
			if ( $imp && 'weight' === $field ) {
				$number = $number * 0.45359237;
			} elseif ( $imp && 'waist' === $field ) {
				$number = $number * 2.54;
			}
			$number = $def['decimal'] ? round( $number, 1 ) : (int) $number;
			if ( $number < $def['min'] || $number > $def['max'] ) {
				return new WP_Error( 'lld_check', $def['name'] . ': this value is outside the range we can save.' );
			}
			if ( ( $number < $def['check'][0] || $number > $def['check'][1] ) && ! $confirm ) {
				return new WP_Error( 'lld_check', 'Please check this value: ' . $def['name'] . '. Tick "I\'ve checked these values" to save it.' );
			}
			$values[ $def['column'] ] = $number;
		}
		$data = array(
			'member_id'     => $member_id,
			'enrolment_id'  => (int) $row->id,
			'week_number'   => $week,
			'local_date'    => self::local_today( $member_id ),
			'measures'      => implode( ',', $fields ),
			'tests_skipped' => $skipped ? implode( ',', $skipped ) : null,
		);
		foreach ( $defs as $def ) {
			$data[ $def['column'] ] = isset( $values[ $def['column'] ] ) ? $values[ $def['column'] ] : null;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'check_in' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND week_number = %d", $row->id, $week ) );
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $data );
		}
		self::save_reflection( $member_id, $row, $week );
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			LLD_Members_Premium::award( $member_id );
		}
		return true;
	}

	/**
	 * C10: the Sleep and Stress reflection answers, each linked to the exact question version.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $week      Week.
	 */
	private static function save_reflection( $member_id, $row, $week ) {
		$when = self::reflection_when( $row, $week );
		if ( '' === $when ) {
			return;
		}
		$answers = array();
		foreach ( self::reflection_questions() as $n => $question ) {
			$text = isset( $_POST[ 'lld_reflect_' . $n ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'lld_reflect_' . $n ] ) ) : '';
			if ( '' !== $text ) {
				$answers[] = array(
					'question' => $question['version'],
					'answer'   => mb_substr( $text, 0, 500 ),
				);
			}
		}
		if ( ! $answers ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'reflection' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND when_key = %s", (int) $row->id, $when ) );
		if ( $id ) {
			$wpdb->update( $table, array( 'answers' => wp_json_encode( $answers ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'    => $member_id,
				'enrolment_id' => (int) $row->id,
				'when_key'     => $when,
				'answers'      => wp_json_encode( $answers ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * @param float  $change Change.
	 * @param string $unit   Unit.
	 */
	private static function change_words( $change, $unit ) {
		$size = rtrim( rtrim( number_format( abs( $change ), 1, '.', '' ), '0' ), '.' );
		if ( abs( $change ) < 0.05 ) {
			return 'the same as week 1';
		}
		return ( $change < 0 ? 'down ' : 'up ' ) . $size . ( '' !== $unit ? ' ' . $unit : '' ) . ' since week 1';
	}

	/**
	 * A small chart of the change since week 1, with its table and one-line summary (section 3.4).
	 *
	 * @param string            $summary Summary line.
	 * @param array<int,float>  $values  Week => value.
	 * @param string            $unit    Unit.
	 */
	private static function change_chart( $summary, $values, $unit ) {
		$first = reset( $values );
		$most  = 0.0;
		foreach ( $values as $value ) {
			$most = max( $most, abs( $value - $first ) );
		}
		$html = '<figure class="lld-chart"><figcaption>' . esc_html( $summary ) . '</figcaption><div class="lld-bars" aria-hidden="true">';
		foreach ( $values as $week => $value ) {
			$change = $value - $first;
			$height = $most > 0 ? (int) round( abs( $change ) / $most * 100 ) : 0;
			$html  .= '<div class="lld-bar"><span style="height:' . esc_attr( (string) $height ) . '%"></span><b>' . esc_html( 'Week ' . $week . ' ' . ( $change > 0 ? '+' : '' ) . rtrim( rtrim( number_format( $change, 1, '.', '' ), '0' ), '.' ) ) . '</b></div>';
		}
		$html .= '</div><button type="button" class="lld-text-button" data-lld-table-toggle aria-expanded="false">Show as table</button>';
		$html .= '<table class="lld-chart-table" hidden><caption>' . esc_html( $summary ) . '</caption><thead><tr><th>Week</th><th>Value</th><th>Change since week 1</th></tr></thead><tbody>';
		foreach ( $values as $week => $value ) {
			$change = $value - $first;
			$html  .= '<tr><td>' . esc_html( 'Week ' . $week ) . '</td><td>' . esc_html( rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' ) . ( '' !== $unit ? ' ' . $unit : '' ) ) . '</td><td>' . esc_html( ( $change > 0 ? '+' : '' ) . rtrim( rtrim( number_format( $change, 1, '.', '' ), '0' ), '.' ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></figure>';
	}

	/**
	 * Plain range guidance from Appendix B. No band judgement and no red or green.
	 *
	 * @param string $field Measure.
	 */
	private static function guidance( $field ) {
		$lines = array(
			'waist'    => 'Guidance: aim to keep your waist under half your height.',
			'activity' => 'Guidance: adults are advised to do 150 to 300 minutes of moderate activity a week, with muscle strengthening on at least 2 days.',
			'strength' => 'Guidance: adults are advised to do muscle strengthening on at least 2 days a week.',
			'sleep'    => 'Guidance: adults are advised to sleep at least 7 hours a night, regularly.',
			'chair'    => 'This is a personal progress count, not a score.',
			'push'     => 'This is a personal progress count, not a score.',
			'quality'  => 'This is a personal rating, not a validated measure.',
			'stress'   => 'This is a personal rating, not a validated measure.',
		);
		return isset( $lines[ $field ] ) ? $lines[ $field ] : '';
	}

	/**
	 * The change since week 1 for each of the track's measures, with a chart and table (FR2-09, SCR-11).
	 *
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 */
	private static function check_in_summary( $member_id, $enrolment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE enrolment_id = %d ORDER BY week_number ASC', $enrolment_id ) );
		if ( ! $rows ) {
			return '';
		}
		$key = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT track_key FROM ' . LLD_Members_Schema::table( 'enrolment' ) . ' WHERE id = %d', $enrolment_id ) );
		if ( 1 === count( $rows ) ) {
			return '<p>This is your starting point.</p>';
		}
		$defs   = self::measure_defs();
		$html   = '<h2>Your progress</h2><p>Change since week 1.</p>';
		$charts = 0;
		foreach ( self::measures( $key ) as $field ) {
			$def    = $defs[ $field ];
			$values = array();
			foreach ( $rows as $row ) {
				if ( null !== $row->{$def['column']} && '' !== (string) $row->{$def['column']} ) {
					$values[ (int) $row->week_number ] = (float) $row->{$def['column']};
				}
			}
			if ( count( $values ) < 2 ) {
				continue;
			}
			$change  = end( $values ) - reset( $values );
			$summary = $def['name'] . ' ' . self::change_words( $change, $def['unit'] );
			$html   .= self::change_chart( $summary, $values, $def['unit'] );
			$line    = self::guidance( $field );
			if ( '' !== $line ) {
				$html .= '<p class="lld-info">' . esc_html( $line ) . '</p>';
			}
			$charts++;
		}
		if ( 0 === $charts ) {
			$html .= '<p>Not enough this week to compare.</p>';
		}
		unset( $member_id );
		return $html;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Weekly review (FR2-11, SCR-14, C12)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Store C12 for a week whose review is ready, for every member, and send the neutral notice once if chosen.
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $week      Week 1 to 4.
	 */
	private static function review_ready( $member_id, $row, $week ) {
		global $wpdb;
		$periods = self::periods( (int) $row->id );
		$ready   = self::day_date( $periods, $week * 7 );
		$table   = LLD_Members_Schema::table( 'weekly_review' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND week_number = %d", (int) $row->id, $week ) ) ) {
			$steps = array();
			if ( self::premium_open( $member_id ) ) {
				$next  = self::build_plan( $member_id, $row, min( 4, $week + 1 ) );
				$steps = self::action_refs( array_slice( $next['actions'], 0, 2 ) );
			}
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (member_id, enrolment_id, week_number, ready_on, next_steps) VALUES (%d, %d, %d, %s, %s)",
					$member_id,
					(int) $row->id,
					$week,
					$ready,
					wp_json_encode( $steps )
				)
			);
		}
		if ( self::days_between( $ready, self::local_today( $member_id ) ) <= 1 ) {
			self::mail_if_chosen( $member_id, 'weekly_review', 'review:' . (int) $row->id . ':' . (int) $week, home_url( '/members/review/' ) );
		}
	}

	/**
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 */
	private static function ready_weeks( $member_id, $row ) {
		return (int) floor( min( self::DAYS, self::days_until( self::periods( (int) $row->id ), self::local_today( $member_id ) ) ) / 7 );
	}

	private static function review_screen() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$main      = '<h1>Weekly review</h1>' . self::notices_html( $member_id );
		$row       = self::current( $member_id );
		$ready     = $row ? self::ready_weeks( $member_id, $row ) : 0;
		if ( ! $row || $ready < 1 ) {
			$main .= '<p>Your review is not ready yet.</p>';
			if ( $row ) {
				$periods = self::periods( (int) $row->id );
				$main   .= '<p>' . esc_html( 'Your first review is ready on ' . self::nice_date( self::day_date( $periods, 7 ) ) . '.' ) . '</p>';
			}
			LLD_Members_Personal::render( 'Weekly review', $main );
			return;
		}
		$week = isset( $_GET['week'] ) ? absint( $_GET['week'] ) : $ready;
		$week = max( 1, min( $ready, $week ) );
		for ( $n = 1; $n <= $ready; $n++ ) {
			self::review_ready( $member_id, $row, $n );
		}
		$main .= self::review_html( $member_id, $row, $week );
		if ( $ready > 1 ) {
			$main .= '<nav aria-label="Earlier reviews"><ul>';
			for ( $n = 1; $n <= $ready; $n++ ) {
				if ( $n !== $week ) {
					$main .= '<li><a href="' . esc_url( home_url( '/members/review/?week=' . $n ) ) . '">' . esc_html( 'Week ' . $n . ' review' ) . '</a></li>';
				}
			}
			$main .= '</ul></nav>';
		}
		LLD_Members_Personal::render( 'Weekly review', $main );
	}

	/**
	 * One week's review, built fresh from C8, C9 and C13 so a late tick shows up (section 8.3).
	 *
	 * @param int    $member_id Member number.
	 * @param object $row       Enrolment.
	 * @param int    $week      Week.
	 */
	private static function review_html( $member_id, $row, $week ) {
		global $wpdb;
		$periods    = self::periods( (int) $row->id );
		$completion = self::completion( $member_id, $row, $week );
		$html       = '<h2>' . esc_html( 'Week ' . $week . ' review' ) . '</h2>';
		$html      .= '<p>' . esc_html( 'You completed ' . $completion['done'] . ' of ' . $completion['available'] . ' activities this week.' ) . '</p>';
		if ( ! self::premium_open( $member_id ) ) {
			$html .= '<p>Here is a short review of your week.</p>';
			$html .= lld_membership_lock( 'A full review is part of Premium.' );
			return $html . '<p>You showed up. That is enough for this week.</p>';
		}
		if ( $completion['available'] > 0 && $completion['pct'] >= 80 ) {
			$html .= '<p>A strong week. Well done for keeping going.</p>';
		} elseif ( $completion['available'] > 0 && $completion['pct'] >= 50 ) {
			$html .= '<p>A steady week. Every activity counts.</p>';
		} else {
			$html .= '<p>Some weeks are busier than others. Next week is a fresh start.</p>';
		}
		$table   = LLD_Members_Schema::table( 'check_in' );
		$checked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrolment_id = %d AND week_number = %d", (int) $row->id, $week ) );
		$first   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrolment_id = %d ORDER BY week_number ASC LIMIT 1", (int) $row->id ) );
		$html   .= '<h3>Check-in</h3>';
		if ( ! $checked ) {
			$html .= '<p>No check-in this week.</p>';
		} elseif ( ! $first || (int) $first->week_number === (int) $week ) {
			$html .= '<p>Not enough this week to compare.</p>';
		} else {
			$defs  = self::measure_defs();
			$lines = '';
			foreach ( self::measures( (string) $row->track_key ) as $field ) {
				$column = $defs[ $field ]['column'];
				if ( null === $checked->{$column} || null === $first->{$column} ) {
					continue;
				}
				$lines .= '<li>' . esc_html( $defs[ $field ]['name'] . ' ' . self::change_words( (float) $checked->{$column} - (float) $first->{$column}, $defs[ $field ]['unit'] ) ) . '</li>';
			}
			$html .= '' === $lines ? '<p>Not enough this week to compare.</p>' : '<ul>' . $lines . '</ul>';
		}
		$from  = self::day_date( $periods, ( ( $week - 1 ) * 7 ) + 1 );
		$to    = self::day_date( $periods, $week * 7 );
		$days  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT local_date) FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d AND local_date BETWEEN %s AND %s', $member_id, $from, $to ) );
		$html .= '<h3>Food diary</h3>';
		$html .= $days > 0 ? '<p>' . esc_html( 'You used your food diary on ' . $days . ' ' . ( 1 === $days ? 'day' : 'days' ) . ' this week.' ) . '</p>' : '<p>No diary entries this week.</p>';
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT next_steps FROM ' . LLD_Members_Schema::table( 'weekly_review' ) . ' WHERE enrolment_id = %d AND week_number = %d', (int) $row->id, $week ) );
		$steps  = array();
		foreach ( self::stored_refs( (string) $stored ) as $ref ) {
			$shown = self::snapshot( $ref['id'], $ref['v'] );
			if ( $shown && LLD_Members_Safety::allows( $member_id, $shown ) ) {
				$steps[] = $shown;
			}
		}
		if ( $steps ) {
			$html .= '<h3>Next steps</h3><ul>';
			foreach ( array_slice( $steps, 0, 2 ) as $step ) {
				$html .= '<li>' . esc_html( (string) $step['title'] ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( 4 === (int) $week ) {
			$html .= '<p>Try the next track or this month\'s challenge.</p><p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Choose your next track</a> <a href="' . esc_url( home_url( '/members/challenge/' ) ) . '">This month\'s challenge</a></p>';
		}
		return $html . '<p>You showed up. That is enough for this week.</p>';
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Sending (FR2-11, FR2-12). Each send is claimed first, so a repeated or overlapping run sends nothing twice.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Claim a send. One row per member and key; a dated key such as the daily reminder is moved on to the new date.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Claim key.
	 * @param string $date      Local date of the send.
	 * @param bool   $daily     True to allow one send per date for this key.
	 */
	private static function claim( $member_id, $key, $date, $daily = false ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'mail_sent' );
		if ( $daily ) {
			$sql = $wpdb->prepare( "INSERT INTO {$table} (member_id, claim_key, sent_on) VALUES (%d, %s, %s) ON DUPLICATE KEY UPDATE sent_on = IF(sent_on = VALUES(sent_on), sent_on, VALUES(sent_on))", $member_id, $key, $date );
		} else {
			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$table} (member_id, claim_key, sent_on) VALUES (%d, %s, %s)", $member_id, $key, $date );
		}
		return (int) $wpdb->query( $sql ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	}

	/**
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	private static function pref( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'email_pref' ) . ' WHERE member_id = %d', $member_id ) );
	}

	/**
	 * Send an opt-in email once, only when the member chose it (FR2-12-AS-05).
	 *
	 * @param int    $member_id Member number.
	 * @param string $kind      Preference column.
	 * @param string $key       Claim key.
	 * @param string $link      Page link.
	 */
	private static function mail_if_chosen( $member_id, $kind, $key, $link ) {
		$pref = self::pref( $member_id );
		if ( ! $pref || empty( $pref->{$kind} ) ) {
			return false;
		}
		$email = self::email_of( $member_id );
		if ( ! is_email( $email ) || ! self::claim( $member_id, $key, self::local_today( $member_id ) ) ) {
			return false;
		}
		self::send_kind( $member_id, $email, $kind, $link );
		return true;
	}

	/**
	 * Every five minutes: daily reminders and welcome emails.
	 */
	public static function tick() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . LLD_Members_Schema::table( 'email_pref' ) . ' WHERE daily_reminder = 1 OR welcome = 1' );
		foreach ( (array) $rows as $pref ) {
			$member_id = (int) $pref->member_id;
			if ( ! empty( $pref->welcome ) ) {
				self::mail_if_chosen( $member_id, 'welcome', 'welcome', home_url( '/members/home/' ) );
			}
			if ( ! empty( $pref->daily_reminder ) && self::reminder_due( $member_id, (string) $pref->reminder_time ) ) {
				$email = self::email_of( $member_id );
				$date  = self::date_in( LLD_Members_Clock::now( $member_id ), self::timezone( $member_id ) );
				if ( is_email( $email ) && self::claim( $member_id, 'daily', $date, true ) ) {
					self::send_kind( $member_id, $email, 'daily_reminder', home_url( '/members/home/' ) );
				}
			}
		}
	}

	/**
	 * Daily: welcome, nudges, weekly reviews (C12 for every member) and end of track.
	 */
	public static function dispatch_mail() {
		global $wpdb;
		$prefs = $wpdb->get_results( 'SELECT * FROM ' . LLD_Members_Schema::table( 'email_pref' ) . ' WHERE welcome = 1 OR nudges = 1' );
		foreach ( (array) $prefs as $pref ) {
			$member_id = (int) $pref->member_id;
			if ( ! empty( $pref->welcome ) ) {
				self::mail_if_chosen( $member_id, 'welcome', 'welcome', home_url( '/members/home/' ) );
			}
			if ( ! empty( $pref->nudges ) ) {
				$email = self::email_of( $member_id );
				if ( is_email( $email ) ) {
					self::maybe_nudge( $member_id, $email, $pref );
				}
			}
		}
		$members = $wpdb->get_col( 'SELECT DISTINCT member_id FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE status IN ('active', 'finished')" );
		foreach ( (array) $members as $member_id ) {
			$member_id = (int) $member_id;
			$row       = self::current( $member_id );
			if ( ! $row || ! in_array( (string) $row->status, array( 'active', 'finished' ), true ) ) {
				continue;
			}
			$ready = self::ready_weeks( $member_id, $row );
			if ( $ready > 0 ) {
				self::review_ready( $member_id, $row, $ready );
			}
		}
	}

	/**
	 * Nudges (FR2-12-AS-02). The count is claimed before sending.
	 *
	 * @param int    $member_id Member number.
	 * @param string $email     Address.
	 * @param object $pref      Preference row.
	 */
	private static function maybe_nudge( $member_id, $email, $pref ) {
		$sent = (int) $pref->nudges_sent;
		if ( $sent >= 2 ) {
			return;
		}
		global $wpdb;
		$signed = $wpdb->get_var( $wpdb->prepare( 'SELECT last_signed_in_at FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( ! is_string( $signed ) || '' === $signed ) {
			return;
		}
		$anchor = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $signed, new DateTimeZone( 'UTC' ) );
		if ( ! $anchor ) {
			return;
		}
		$today = self::local_today( $member_id );
		$from  = self::date_in( $anchor, self::timezone( $member_id ) );
		$due   = ( 0 === $sent && self::days_between( $from, $today ) >= 5 )
			|| ( 1 === $sent && $pref->last_nudge_on && self::days_between( (string) $pref->last_nudge_on, $today ) >= 5 );
		if ( ! $due ) {
			return;
		}
		$won = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . LLD_Members_Schema::table( 'email_pref' ) . ' SET nudges_sent = %d, last_nudge_on = %s WHERE member_id = %d AND nudges_sent = %d',
				$sent + 1,
				$today,
				$member_id,
				$sent
			)
		);
		if ( $won ) {
			self::send_kind( $member_id, $email, 'nudges', home_url( '/members/home/' ) );
		}
	}

	/**
	 * Whether the reminder is due: from the chosen local time to 10 minutes after, on this local date. A time that
	 * does not exist on a clock-change day moves forward with the clocks; a repeated time is sent once (claim).
	 *
	 * @param DateTimeImmutable $now   The moment.
	 * @param string            $zone  Zone in force.
	 * @param string            $clock H:i or H:i:s.
	 */
	public static function reminder_window( DateTimeImmutable $now, $zone, $clock ) {
		$parts  = array_map( 'intval', explode( ':', '' !== (string) $clock ? (string) $clock : '07:30' ) );
		$tz     = self::safe_zone( $zone );
		$date   = $now->setTimezone( $tz )->format( 'Y-m-d' );
		$target = new DateTimeImmutable( sprintf( '%s %02d:%02d:00', $date, $parts[0], $parts[1] ?? 0 ), $tz );
		$delta  = $now->getTimestamp() - $target->getTimestamp();
		return $delta >= 0 && $delta < 10 * MINUTE_IN_SECONDS;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $clock     Reminder time.
	 */
	public static function reminder_due( $member_id, $clock ) {
		return self::reminder_window( LLD_Members_Clock::now( $member_id ), self::timezone( $member_id ), $clock );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $clock     Reminder time H:i or H:i:s.
	 */
	public static function in_window( $member_id, $clock ) {
		return self::reminder_due( $member_id, $clock );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $email     Address.
	 * @param string $kind      Mail kind.
	 * @param string $link      Page link.
	 */
	private static function send_kind( $member_id, $email, $kind, $link ) {
		$subjects = self::mail_kinds();
		$subject  = isset( $subjects[ $kind ] ) ? $subjects[ $kind ] : 'Lifeline Daily';
		$unsub    = self::unsub_link( $member_id, $kind );
		wp_mail( $email, $subject, self::mail_body( $kind, $link, $unsub ) );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $kind      Preference column.
	 */
	public static function unsub_link( $member_id, $kind ) {
		$token = self::letters( 24 );
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'unsubscribe_code' ),
			array(
				'member_id'  => $member_id,
				'code_hash'  => hash( 'sha256', $token ),
				'purpose'    => $kind,
				'expires_at' => LLD_Members_Clock::now( $member_id )->modify( '+400 days' )->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return home_url( '/members/unsubscribe/?code=' . $token . '&kind=' . rawurlencode( $kind ) );
	}

	/**
	 * @param string $token Code from the link.
	 * @param string $kind  Purpose.
	 * @return string already|done|missing
	 */
	public static function consume_unsub( $token, $kind ) {
		$kinds = array( 'welcome', 'daily_reminder', 'weekly_review', 'end_of_track', 'nudges' );
		if ( ! in_array( $kind, $kinds, true ) || '' === $token ) {
			return 'missing';
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'unsubscribe_code' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s AND purpose = %s ORDER BY id DESC LIMIT 1", hash( 'sha256', $token ), $kind ) );
		if ( ! $row ) {
			return 'missing';
		}
		if ( $row->used_at ) {
			return 'already';
		}
		$wpdb->update( $table, array( 'used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		$wpdb->update( LLD_Members_Schema::table( 'email_pref' ), array( $kind => 0 ), array( 'member_id' => (int) $row->member_id ), array( '%d' ), array( '%d' ) );
		return 'done';
	}

	/**
	 * @param int $length Length.
	 */
	private static function letters( $length ) {
		$alphabet = 'abcdefghjkmnpqrstuvwxyz';
		$bytes    = random_bytes( $length );
		$out      = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ ord( $bytes[ $i ] ) % strlen( $alphabet ) ];
		}
		return $out;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function email_of( $member_id ) {
		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		return is_string( $email ) ? $email : '';
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Test route (ci and local only, behind the test secret)
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * @param string $email Address.
	 */
	private static function member_by_email( $email ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', sanitize_email( (string) $email ) ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $actions Snapshots.
	 * @return string[]
	 */
	private static function titles_of( $actions ) {
		$titles = array();
		foreach ( $actions as $action ) {
			$titles[] = (string) $action['title'];
		}
		return $titles;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		if ( ! empty( $params['tick'] ) ) {
			$schedule = self::tick_schedule();
			if ( $schedule['scheduled'] && self::TICK_SECONDS === $schedule['interval'] ) {
				do_action( self::TICK_HOOK );
			}
			return new WP_REST_Response( $schedule, 200 );
		}
		if ( ! empty( $params['pcts'] ) && is_array( $params['pcts'] ) ) {
			$track = isset( $params['track'] ) ? sanitize_title( (string) $params['track'] ) : '';
			$items = self::activities( self::track_id( $track ), 0, 0 );
			$out   = array();
			foreach ( $params['pcts'] as $pct ) {
				$built  = self::select( $items, (float) $pct, 'standard', 0 );
				$titles = self::titles_of( $built['actions'] );
				$out[]  = array(
					'pct'    => (float) $pct,
					'level'  => $built['level'],
					'count'  => count( $titles ),
					'titles' => $titles,
				);
			}
			return new WP_REST_Response( array( 'rows' => $out ), 200 );
		}
		$member_id = self::member_by_email( isset( $params['email'] ) ? (string) $params['email'] : '' );
		if ( ! $member_id ) {
			return new WP_REST_Response( array( 'message' => 'Member not found.' ), 404 );
		}
		if ( isset( $params['seed'] ) ) {
			return self::rest_seed( $member_id, (array) $params['seed'] );
		}
		if ( ! empty( $params['state'] ) ) {
			return new WP_REST_Response( self::rest_state( $member_id ), 200 );
		}
		if ( isset( $params['plan_week'] ) ) {
			$row = self::active( $member_id );
			if ( ! $row ) {
				return new WP_REST_Response( array( 'message' => 'No active track.' ), 400 );
			}
			$built = self::build_plan( $member_id, $row, max( 1, min( 4, (int) $params['plan_week'] ) ) );
			return new WP_REST_Response(
				array(
					'level'      => $built['level'],
					'note'       => $built['note'],
					'titles'     => self::titles_of( $built['actions'] ),
					'completion' => $built['completion'],
				),
				200
			);
		}
		$built = self::generate( $member_id );
		return new WP_REST_Response(
			array(
				'level'  => $built['level'],
				'note'   => $built['note'],
				'titles' => self::titles_of( $built['actions'] ),
				'bodies' => self::mail_catalogue(),
			),
			200
		);
	}

	/**
	 * Record "done" for the first N activities of a week of the member's current track (test data for FR2-10).
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $seed      week, done.
	 */
	private static function rest_seed( $member_id, $seed ) {
		$row = self::current( $member_id );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'message' => 'No track.' ), 400 );
		}
		$week    = max( 1, min( 4, (int) ( $seed['week'] ?? 1 ) ) );
		$want    = max( 0, (int) ( $seed['done'] ?? 0 ) );
		$periods = self::periods( (int) $row->id );
		global $wpdb;
		$table = LLD_Members_Schema::table( 'result' );
		$count = 0;
		for ( $day = ( ( $week - 1 ) * 7 ) + 1; $day <= $week * 7; $day++ ) {
			foreach ( self::day_items( $member_id, $row, $day )['items'] as $item ) {
				$result = $count < $want ? 'done' : 'skipped';
				$wpdb->replace(
					$table,
					array(
						'member_id'     => $member_id,
						'item_version'  => 'a' . (int) $item['parent_id'] . 'v' . (int) $item['version'],
						'context_type'  => 'enrolment',
						'context_id'    => (int) $row->id,
						'programme_day' => $day,
						'local_date'    => self::day_date( $periods, $day ),
						'result'        => $result,
						'recorded_at'   => LLD_Members_Clock::now( $member_id )->format( 'Y-m-d H:i:s' ),
					),
					array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
				);
				$count++;
			}
		}
		return new WP_REST_Response( self::completion( $member_id, $row, $week ), 200 );
	}

	/**
	 * Stored programme state for one member, for tests that check what is kept.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	private static function rest_state( $member_id ) {
		self::settle( $member_id );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'             => (int) $row->id,
				'track'          => (string) $row->track_key,
				'attempt'        => (int) $row->attempt,
				'status'         => (string) $row->status,
				'stopped_why'    => (string) $row->stopped_why,
				'free_week_used' => (int) $row->free_week_used,
				'last_open_day'  => (string) $row->last_open_day,
				'finished_on'    => (string) $row->finished_on,
				'periods'        => self::periods( (int) $row->id ),
				'day'            => min( self::DAYS, self::days_until( self::periods( (int) $row->id ), self::local_today( $member_id ) ) ),
			);
		}
		$plans   = $wpdb->get_results( $wpdb->prepare( 'SELECT enrolment_id, week_number, level, completion_done, completion_available, completion_pct, rule_version, swapped, actions FROM ' . LLD_Members_Schema::table( 'weekly_plan' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		$reviews = $wpdb->get_results( $wpdb->prepare( 'SELECT enrolment_id, week_number, ready_on, next_steps FROM ' . LLD_Members_Schema::table( 'weekly_review' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		$checks  = $wpdb->get_results( $wpdb->prepare( 'SELECT enrolment_id, week_number, local_date, weight_kg, waist_cm, chair_stands, push_ups, tests_skipped FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE member_id = %d AND enrolment_id IS NOT NULL ORDER BY id ASC', $member_id ), ARRAY_A );
		$reflect = $wpdb->get_results( $wpdb->prepare( 'SELECT enrolment_id, when_key, answers FROM ' . LLD_Members_Schema::table( 'reflection' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT item_version, context_id, programme_day, local_date, result FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		return array(
			'today'       => self::local_today( $member_id ),
			'zone'        => self::timezone( $member_id ),
			'enrolments'  => $out,
			'plans'       => $plans,
			'reviews'     => $reviews,
			'check_ins'   => $checks,
			'reflections' => $reflect,
			'results'     => $results,
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function mail_catalogue() {
		$out = array();
		foreach ( array_keys( self::mail_kinds() ) as $kind ) {
			$out[ $kind ] = self::mail_body( $kind, home_url( '/members/home/' ), home_url( '/members/unsubscribe/?code=example&kind=' . $kind ) );
		}
		return $out;
	}
}
