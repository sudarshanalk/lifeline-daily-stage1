<?php
/**
 * Programme days, check-ins, weekly plans, reviews and opt-in emails.
 *
 * Safety has already run before a plan action is offered. Free members stop at day 7.
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
	 * @param string $kind Purpose.
	 * @param string $link Signed-in page.
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

	/**
	 * @param int $member_id Member number.
	 */
	public static function local_today( $member_id ) {
		return lld_members_local_date( $member_id, self::timezone( $member_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function timezone( $member_id ) {
		global $wpdb;
		$zone = $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		return is_string( $zone ) && '' !== $zone ? $zone : 'UTC';
	}

	/**
	 * @param int $member_id Member number.
	 * @return object|null
	 */
	public static function active( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $member_id ) );
	}

	/**
	 * Open a period when the member has none, so day 1 is the local start date.
	 *
	 * @param int $enrolment_id Enrolment.
	 * @param int $member_id    Member number.
	 */
	public static function ensure_period( $enrolment_id, $member_id ) {
		if ( $enrolment_id < 1 ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment_period' );
		$open  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND last_on IS NULL LIMIT 1", $enrolment_id ) );
		if ( $open ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'enrolment_id' => $enrolment_id,
				'first_on'     => self::local_today( $member_id ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 */
	public static function programme_day( $member_id, $enrolment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT first_on, last_on FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' WHERE enrolment_id = %d ORDER BY first_on ASC, id ASC', $enrolment_id ) );
		if ( ! $rows ) {
			return 1;
		}
		$today = self::local_today( $member_id );
		$total = 0;
		foreach ( $rows as $row ) {
			$start = (string) $row->first_on;
			$end   = $row->last_on ? (string) $row->last_on : $today;
			if ( $end < $start ) {
				continue;
			}
			$from   = new DateTimeImmutable( $start );
			$to     = new DateTimeImmutable( $end );
			$total += (int) $from->diff( $to )->days + 1;
		}
		return max( 1, $total );
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
	 * @param int $member_id Member number.
	 */
	public static function position_label( $member_id ) {
		$row = self::active( $member_id );
		$day = $row ? self::programme_day( $member_id, (int) $row->id ) : 1;
		$pos = self::position_parts( $day );
		return 'Week ' . $pos['week'] . ', day ' . $pos['day'];
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	public static function can_open_day( $member_id, $day ) {
		$day   = (int) $day;
		$state = strtolower( LLD_Members_Access::access_state( $member_id ) );
		if ( 'free' === $state && $day > 7 ) {
			return false;
		}
		return $day >= 1 && $day <= 28;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Track slug.
	 * @return int
	 */
	public static function track_id( $key ) {
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
	 * Approved activities for a track.
	 *
	 * @param int  $track_id Track post.
	 * @param int  $day      Programme day, or 0 for the action library.
	 * @param int  $member_id Member, for the safety matrix.
	 * @return array<int,array<string,mixed>>
	 */
	public static function activities( $track_id, $day, $member_id ) {
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
		$out = array();
		foreach ( $posts as $post ) {
			$shown = LLD_Members_Content::shown( $post->ID );
			if ( ! is_array( $shown ) ) {
				continue;
			}
			$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
			if ( (int) ( $fields['track_id'] ?? 0 ) !== (int) $track_id ) {
				continue;
			}
			$item_day = (int) ( $fields['day_number'] ?? 0 );
			$library  = ! empty( $fields['in_library'] );
			if ( $day > 0 && $item_day !== (int) $day ) {
				continue;
			}
			if ( 0 === (int) $day && ! $library ) {
				continue;
			}
			if ( ! LLD_Members_Safety::allows( $member_id, $shown ) ) {
				continue;
			}
			$out[] = $shown;
		}
		return $out;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function done_count( $member_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'result' ) . " WHERE member_id = %d AND result = 'done'", $member_id ) );
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

	/**
	 * Difficulty rule from FR2-10. A missing figure keeps the previous level.
	 *
	 * @param float|null $pct      Completion percentage, or null when there is nothing to count.
	 * @param string     $previous Previous level name.
	 */
	public static function level_for( $pct, $previous ) {
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
	 * @param array<int,array<string,mixed>> $items    Safe library actions.
	 * @param float|null                     $pct      Completion.
	 * @param string                         $previous Previous difficulty.
	 * @param int                            $offset   Swap rotation.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string}
	 */
	public static function select( $items, $pct, $previous, $offset ) {
		$level = self::level_for( $pct, $previous );
		if ( ! $items ) {
			return array(
				'level'   => $level,
				'actions' => array(),
				'note'    => 'No safe actions are available this week.',
			);
		}
		if ( count( $items ) < 3 ) {
			return array(
				'level'   => $level,
				'actions' => array_values( $items ),
				'note'    => 'Fewer than 3 safe actions are available.',
			);
		}
		$bucket = 'easier' === $level ? 'easier' : 'standard';
		if ( 'same' === $level && in_array( $previous, array( 'easier', 'standard', 'stretch' ), true ) ) {
			$bucket = 'stretch' === $previous ? 'standard' : $previous;
		}
		$pool = array();
		foreach ( $items as $item ) {
			$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
			$diff   = isset( $fields['difficulty'] ) ? (string) $fields['difficulty'] : 'standard';
			if ( $diff === $bucket ) {
				$pool[] = $item;
			}
		}
		if ( count( $pool ) < 3 ) {
			$pool = array_values( $items );
		}
		$offset = $offset % max( 1, count( $pool ) );
		$turned = array_merge( array_slice( $pool, $offset ), array_slice( $pool, 0, $offset ) );
		$picked = array_slice( $turned, 0, 4 );
		if ( 'stretch' === $level ) {
			$stretch = array();
			foreach ( $items as $item ) {
				$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
				if ( 'stretch' === (string) ( $fields['difficulty'] ?? '' ) ) {
					$stretch[] = $item;
				}
			}
			if ( $stretch && $picked ) {
				$picked[ count( $picked ) - 1 ] = $stretch[ $offset % count( $stretch ) ];
			}
		}
		return array(
			'level'   => $level,
			'actions' => $picked,
			'note'    => '',
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string}
	 */
	public static function generate( $member_id ) {
		$row     = self::active( $member_id );
		$track   = $row ? self::track_id( (string) $row->track_key ) : 0;
		$items   = $track ? self::activities( $track, 0, $member_id ) : array();
		$pct     = self::completion_pct( $member_id );
		$prior   = self::previous_level( $member_id );
		$offset  = self::swap_offset( $member_id );
		return self::select( $items, $pct, $prior, $offset );
	}

	/**
	 * Activities done divided by activities available this week. Check-ins are not an input.
	 *
	 * @param int $member_id Member number.
	 * @return float|null
	 */
	public static function completion_pct( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return null;
		}
		$programme = self::programme_day( $member_id, (int) $row->id );
		$pos       = self::position_parts( $programme );
		$start     = ( $pos['week'] - 1 ) * 7 + 1;
		$track     = self::track_id( (string) $row->track_key );
		$available = 0;
		$done      = 0;
		for ( $day = $start; $day < $start + 7; $day++ ) {
			$items = self::activities( $track, $day, $member_id );
			if ( ! $items ) {
				continue;
			}
			$available++;
			if ( self::marked( $member_id, (int) $row->id, $day ) === 'done' ) {
				$done++;
			}
		}
		if ( $available < 1 ) {
			return null;
		}
		return round( ( $done / $available ) * 100, 2 );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function previous_level( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return 'standard';
		}
		global $wpdb;
		$level = $wpdb->get_var( $wpdb->prepare( 'SELECT level FROM ' . LLD_Members_Schema::table( 'weekly_plan' ) . ' WHERE enrolment_id = %d ORDER BY week_number DESC LIMIT 1', $row->id ) );
		return is_string( $level ) && '' !== $level ? $level : 'standard';
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function swap_offset( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return 0;
		}
		$pos = self::position_parts( self::programme_day( $member_id, (int) $row->id ) );
		global $wpdb;
		$swapped = $wpdb->get_var( $wpdb->prepare( 'SELECT swapped FROM ' . LLD_Members_Schema::table( 'weekly_plan' ) . ' WHERE enrolment_id = %d AND week_number = %d', $row->id, $pos['week'] ) );
		return $swapped ? 1 : 0;
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $enrolment Enrolment.
	 * @param int $day       Programme day.
	 */
	private static function marked( $member_id, $enrolment, $day ) {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT result FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d AND context_type = %s AND context_id = %d AND programme_day = %d ORDER BY id DESC LIMIT 1',
				$member_id,
				'enrolment',
				$enrolment,
				$day
			)
		);
		return is_string( $value ) ? $value : '';
	}

	public static function screen( $screen ) {
		if ( 'track' === $screen ) {
			self::track_screen();
			return;
		}
		if ( 'review' === $screen ) {
			self::review_screen();
		}
	}

	private static function track_screen() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$day       = isset( $_GET['day'] ) ? absint( $_GET['day'] ) : 0;
		if ( $day > 0 ) {
			self::day_screen( $member_id, $day );
			return;
		}
		$row  = self::active( $member_id );
		$main = '<h1>Your track</h1>';
		if ( ! $row ) {
			$main .= '<p><a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Start a track</a></p>';
			LLD_Members_Personal::render( 'Your track', $main );
			return;
		}
		$programme = self::programme_day( $member_id, (int) $row->id );
		$pos       = self::position_parts( $programme );
		$main     .= '<p>' . esc_html( self::position_label( $member_id ) ) . '</p>';
		$main     .= '<ol class="lld-weeks">';
		for ( $week = 1; $week <= 4; $week++ ) {
			$state = $week < $pos['week'] ? 'done' : ( $week === $pos['week'] ? 'open' : 'locked' );
			$main .= '<li>' . esc_html( 'Week ' . $week . ' ' . $state ) . '</li>';
		}
		$main .= '</ol>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/track/?day=' . $programme ) ) . '">Open today</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/check-in/' ) ) . '">Weekly check-in</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/plan/' ) ) . '">Weekly plan</a></p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/review/' ) ) . '">Weekly review</a></p>';
		LLD_Members_Personal::render( 'Your track', $main );
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	private static function day_screen( $member_id, $day ) {
		$saved = false;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$saved = self::take_mark( $member_id, $day );
		}
		if ( ! self::can_open_day( $member_id, $day ) ) {
			LLD_Members_Personal::render( 'Day ' . $day, '<h1>Day ' . (int) $day . '</h1>' . lld_membership_lock( 'Day 8 onwards is part of Premium.' ) );
			return;
		}
		$row   = self::active( $member_id );
		$track = $row ? self::track_id( (string) $row->track_key ) : 0;
		$items = $track ? self::activities( $track, $day, $member_id ) : array();
		$main  = '<h1>Day ' . (int) $day . '</h1>';
		if ( $saved ) {
			$main .= '<p>Saved</p>';
		}
		if ( ! $items ) {
			$main .= '<p>No activity for this day.</p>';
		}
		foreach ( $items as $item ) {
			$main .= '<article class="lld-activity"><h2>' . esc_html( (string) $item['title'] ) . '</h2>';
			$main .= '<div class="lld-content">' . esc_html( (string) $item['body'] ) . '</div>';
			$when = $row ? self::date_for_day( $member_id, (int) $row->id, $day ) : '';
			if ( ! $when || ! self::within_two_days( $member_id, $when ) ) {
				$main .= '<p>This activity can no longer be marked.</p>';
			}
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_mark', 'lld_mark_nonce', true, false );
			$main .= '<input type="hidden" name="lld_day" value="' . (int) $day . '">';
			$main .= '<button class="lld-button" type="submit" name="lld_result" value="done">Done</button> ';
			$main .= '<button class="lld-button" type="submit" name="lld_result" value="skipped">Skip</button>';
			$main .= '</form>';
			$main .= '</article>';
		}
		if ( $row && $day > 1 ) {
			$main .= '<h2>Catch up</h2><ul>';
			foreach ( array( $day - 1, $day - 2 ) as $earlier ) {
				if ( $earlier < 1 ) {
					continue;
				}
				$when = self::date_for_day( $member_id, (int) $row->id, $earlier );
				if ( $when && self::within_two_days( $member_id, $when ) ) {
					$main .= '<li><a href="' . esc_url( home_url( '/members/track/?day=' . $earlier ) ) . '">Day ' . (int) $earlier . '</a></li>';
				}
			}
			$main .= '</ul>';
		}
		LLD_Members_Personal::render( 'Day ' . $day, $main );
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $day       Programme day.
	 */
	private static function take_mark( $member_id, $day ) {
		if ( ! isset( $_POST['lld_mark_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_mark_nonce'] ) ), 'lld_mark' ) ) {
			return false;
		}
		$result = isset( $_POST['lld_result'] ) ? sanitize_key( wp_unslash( $_POST['lld_result'] ) ) : '';
		$marked = self::mark( $member_id, $day, $result );
		if ( is_wp_error( $marked ) ) {
			LLD_Members_Personal::render( 'Day ' . $day, '<h1>Day ' . (int) $day . '</h1><p>' . esc_html( $marked->get_error_message() ) . '</p>' );
			exit;
		}
		return true;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param int    $day       Programme day.
	 * @param string $result    done or skipped.
	 * @return true|WP_Error
	 */
	public static function mark( $member_id, $day, $result ) {
		if ( ! in_array( $result, array( 'done', 'skipped' ), true ) ) {
			return new WP_Error( 'lld_mark', 'Choose done or skip.' );
		}
		if ( ! self::can_open_day( $member_id, $day ) ) {
			return new WP_Error( 'lld_mark', 'This activity can no longer be marked.' );
		}
		$row = self::active( $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lld_mark', 'Start a track first.' );
		}
		$when = self::date_for_day( $member_id, (int) $row->id, $day );
		if ( ! $when || ! self::within_two_days( $member_id, $when ) ) {
			return new WP_Error( 'lld_mark', 'This activity can no longer be marked.' );
		}
		$track = self::track_id( (string) $row->track_key );
		$items = self::activities( $track, $day, $member_id );
		if ( ! $items ) {
			return new WP_Error( 'lld_mark', 'This activity can no longer be marked.' );
		}
		$version = 'a' . (int) $items[0]['parent_id'] . 'v' . (int) $items[0]['version'];
		global $wpdb;
		$table = LLD_Members_Schema::table( 'result' );
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE member_id = %d AND item_version = %s AND context_type = %s AND context_id = %d AND programme_day = %d",
				$member_id,
				$version,
				'enrolment',
				$row->id,
				$day
			)
		);
		$stored = array(
			'result'      => $result,
			'recorded_at' => LLD_Members_Clock::now( $member_id )->format( 'Y-m-d H:i:s' ),
			'local_date'  => $when,
		);
		if ( $id ) {
			$wpdb->update( $table, $stored, array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			return true;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'     => $member_id,
				'item_version'  => $version,
				'context_type'  => 'enrolment',
				'context_id'    => (int) $row->id,
				'programme_day' => $day,
				'local_date'    => $when,
				'result'        => $result,
				'recorded_at'   => $stored['recorded_at'],
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return true;
	}

	/**
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 * @param int $day          Programme day.
	 */
	public static function date_for_day( $member_id, $enrolment_id, $day ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT first_on, last_on FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' WHERE enrolment_id = %d ORDER BY first_on ASC, id ASC', $enrolment_id ) );
		$left = (int) $day;
		$today = self::local_today( $member_id );
		foreach ( $rows as $row ) {
			$cursor = new DateTimeImmutable( (string) $row->first_on );
			$end    = new DateTimeImmutable( $row->last_on ? (string) $row->last_on : $today );
			while ( $cursor <= $end ) {
				$left--;
				if ( 0 === $left ) {
					return $cursor->format( 'Y-m-d' );
				}
				$cursor = $cursor->modify( '+1 day' );
			}
		}
		return '';
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $date      Local date the activity belongs to.
	 */
	public static function within_two_days( $member_id, $date ) {
		$today = new DateTimeImmutable( self::local_today( $member_id ) );
		$when  = new DateTimeImmutable( $date );
		if ( $today < $when ) {
			return false;
		}
		return (int) $when->diff( $today )->days <= 2;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function plan_screen() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$posted    = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		if ( 'free' === strtolower( LLD_Members_Access::access_state( $member_id ) ) ) {
			if ( $posted ) {
				status_header( 403 );
			}
			LLD_Members_Personal::render( "This week's plan", '<h1>This week\'s plan</h1>' . lld_membership_lock( 'The weekly plan is part of Premium.' ), $posted ? 403 : 200 );
			return;
		}
		if ( $posted && isset( $_POST['lld_plan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_plan_nonce'] ) ), 'lld_plan' ) ) {
			$swapped = self::swap( $member_id );
			if ( is_wp_error( $swapped ) ) {
				LLD_Members_Personal::render( "This week's plan", '<h1>This week\'s plan</h1><p>' . esc_html( $swapped->get_error_message() ) . '</p>' );
				return;
			}
		}
		$plan = self::stored_plan( $member_id );
		$main = '<h1>This week\'s plan</h1>';
		if ( ! $plan['actions'] && $plan['note'] ) {
			$main .= '<p>' . esc_html( $plan['note'] ) . '</p>';
		} else {
			if ( 'easier' === $plan['level'] ) {
				$main .= '<p>A lighter week</p>';
			} elseif ( 'stretch' === $plan['level'] ) {
				$main .= '<p>One stretch goal</p>';
			} else {
				$main .= '<p>Same level</p>';
			}
			if ( $plan['note'] ) {
				$main .= '<p>' . esc_html( $plan['note'] ) . '</p>';
			}
			foreach ( $plan['actions'] as $action ) {
				$main .= '<article class="lld-action"><h2>' . esc_html( (string) $action['title'] ) . '</h2></article>';
			}
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_plan', 'lld_plan_nonce', true, false );
			$main .= '<button class="lld-button" type="submit" name="lld_swap" value="1">Swap my plan</button></form>';
		}
		LLD_Members_Personal::render( "This week's plan", $main );
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{level:string,actions:array<int,array<string,mixed>>,note:string}
	 */
	public static function stored_plan( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return array(
				'level'   => 'same',
				'actions' => array(),
				'note'    => 'Start a track to get a plan.',
			);
		}
		$pos = self::position_parts( self::programme_day( $member_id, (int) $row->id ) );
		global $wpdb;
		$table  = LLD_Members_Schema::table( 'weekly_plan' );
		$stored = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrolment_id = %d AND week_number = %d", $row->id, $pos['week'] ) );
		if ( $stored && ! $stored->swapped ) {
			$ids = json_decode( (string) $stored->actions, true );
			return array(
				'level'   => (string) $stored->level,
				'actions' => self::actions_from_ids( is_array( $ids ) ? $ids : array() ),
				'note'    => self::note_for( json_decode( (string) $stored->actions, true ) ),
			);
		}
		$built = self::generate( $member_id );
		self::save_plan( $member_id, $built, $stored ? 1 : 0 );
		return $built;
	}

	/**
	 * @param mixed $ids Stored ids.
	 */
	private static function note_for( $ids ) {
		if ( ! is_array( $ids ) || ! $ids ) {
			return 'No safe actions are available this week.';
		}
		if ( count( $ids ) < 3 ) {
			return 'Fewer than 3 safe actions are available.';
		}
		return '';
	}

	/**
	 * @param int[] $ids Parent ids.
	 * @return array<int,array<string,mixed>>
	 */
	private static function actions_from_ids( $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$shown = LLD_Members_Content::shown( (int) $id );
			if ( is_array( $shown ) ) {
				$out[] = $shown;
			}
		}
		return $out;
	}

	/**
	 * @param int                                      $member_id Member number.
	 * @param array{level:string,actions:array,note:string} $built Plan.
	 * @param int                                      $swapped   Swap flag.
	 */
	private static function save_plan( $member_id, $built, $swapped ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return;
		}
		$pos = self::position_parts( self::programme_day( $member_id, (int) $row->id ) );
		$ids = array();
		foreach ( $built['actions'] as $action ) {
			$ids[] = (int) ( $action['parent_id'] ?? 0 );
		}
		$pct = self::completion_pct( $member_id );
		global $wpdb;
		$table = LLD_Members_Schema::table( 'weekly_plan' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND week_number = %d", $row->id, $pos['week'] ) );
		$data  = array(
			'actions'              => wp_json_encode( $ids ),
			'level'                => $built['level'],
			'completion_done'      => 0,
			'completion_available' => 0,
			'completion_pct'       => null === $pct ? 0 : $pct,
			'rule_version'         => '1',
			'swapped'              => $swapped ? 1 : 0,
			'created_on'           => self::local_today( $member_id ),
		);
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
			return;
		}
		$data['member_id']    = $member_id;
		$data['enrolment_id'] = (int) $row->id;
		$data['week_number']  = $pos['week'];
		$wpdb->insert( $table, $data );
	}

	/**
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function swap( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return new WP_Error( 'lld_plan', 'Start a track first.' );
		}
		$pos = self::position_parts( self::programme_day( $member_id, (int) $row->id ) );
		global $wpdb;
		$table   = LLD_Members_Schema::table( 'weekly_plan' );
		$swapped = (int) $wpdb->get_var( $wpdb->prepare( "SELECT swapped FROM {$table} WHERE enrolment_id = %d AND week_number = %d", $row->id, $pos['week'] ) );
		if ( $swapped ) {
			return new WP_Error( 'lld_plan', 'You can swap again next week.' );
		}
		$wpdb->update( $table, array( 'swapped' => 1 ), array( 'enrolment_id' => (int) $row->id, 'week_number' => $pos['week'] ) );
		$built = self::generate( $member_id );
		self::save_plan( $member_id, $built, 1 );
		return true;
	}

	/**
	 * Check-in fields for the active track. Height stays on the personal screen.
	 *
	 * @param int $member_id Member number.
	 */
	public static function check_in_html( $member_id ) {
		$row = self::active( $member_id );
		if ( ! $row ) {
			return '';
		}
		$fields = self::measures( (string) $row->track_key );
		$html   = '';
		if ( in_array( 'chair', $fields, true ) ) {
			$html .= '<p class="lld-caution">Use a sturdy chair with no wheels, placed against a wall. Stop if you feel dizzy or in pain. Skip this test if you have balance problems, a recent injury or a doctor\'s restriction.</p>';
		}
		$html .= '<form method="post" class="lld-form">';
		$html .= wp_nonce_field( 'lld_measures', 'lld_measures_nonce', true, false );
		foreach ( $fields as $field ) {
			$html .= self::measure_field( $field );
		}
		if ( 'sleep-stress' === (string) $row->track_key ) {
			$pos = self::position_parts( self::programme_day( $member_id, (int) $row->id ) );
			if ( 1 === $pos['week'] || 4 === $pos['week'] ) {
				$html .= self::measure_field( 'reflect1' );
				$html .= self::measure_field( 'reflect2' );
				$html .= self::measure_field( 'reflect3' );
			}
		}
		$html .= lld_button( array( 'label' => 'Save check-in' ) );
		$html .= '</form>';
		$html .= self::check_in_summary( $member_id, (int) $row->id );
		return $html;
	}

	/**
	 * @param string $field Field key.
	 */
	private static function measure_field( $field ) {
		$labels = array(
			'weight'   => array( 'lld_weight', 'Weight in kilograms' ),
			'waist'    => array( 'lld_waist', 'Waist in centimetres' ),
			'activity' => array( 'lld_activity', 'Minutes of activity this week' ),
			'chair'    => array( 'lld_chair', 'Chair stands in 30 seconds' ),
			'push'     => array( 'lld_push', 'Push-ups' ),
			'strength' => array( 'lld_strength', 'Strength sessions' ),
			'sleep'    => array( 'lld_sleep', 'Average sleep hours' ),
			'quality'  => array( 'lld_quality', 'Sleep quality from 1 to 5' ),
			'stress'   => array( 'lld_stress', 'Stress level from 1 to 5' ),
			'reflect1' => array( 'lld_reflect_1', 'What helped you rest this week?' ),
			'reflect2' => array( 'lld_reflect_2', 'What got in the way?' ),
			'reflect3' => array( 'lld_reflect_3', 'What will you try next?' ),
		);
		if ( ! isset( $labels[ $field ] ) ) {
			return '';
		}
		return '<p><label for="' . esc_attr( $labels[ $field ][0] ) . '">' . esc_html( $labels[ $field ][1] ) . '</label><input id="' . esc_attr( $labels[ $field ][0] ) . '" name="' . esc_attr( $labels[ $field ][0] ) . '" inputmode="decimal"></p>';
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
			return new WP_Error( 'lld_check', 'Start a track first.' );
		}
		$pos = self::position_parts( self::programme_day( $member_id, (int) $row->id ) );
		$data = array(
			'member_id'        => $member_id,
			'enrolment_id'     => (int) $row->id,
			'week_number'      => $pos['week'],
			'local_date'       => self::local_today( $member_id ),
			'measures'         => (string) $row->track_key,
			'weight_kg'        => self::posted_decimal( 'lld_weight' ),
			'waist_cm'         => self::posted_decimal( 'lld_waist' ),
			'activity_minutes' => self::posted_int( 'lld_activity' ),
			'chair_stands'     => self::posted_int( 'lld_chair' ),
			'push_ups'         => self::posted_int( 'lld_push' ),
			'strength_sessions'=> self::posted_int( 'lld_strength' ),
			'sleep_hours'      => self::posted_decimal( 'lld_sleep' ),
			'sleep_quality'    => self::posted_int( 'lld_quality' ),
			'stress_level'     => self::posted_int( 'lld_stress' ),
		);
		global $wpdb;
		$table = LLD_Members_Schema::table( 'check_in' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND week_number = %d", $row->id, $pos['week'] ) );
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $data );
		}
		return true;
	}

	/**
	 * @param string $name Field name.
	 * @return float|null
	 */
	private static function posted_decimal( $name ) {
		if ( ! isset( $_POST[ $name ] ) || '' === trim( (string) wp_unslash( $_POST[ $name ] ) ) ) {
			return null;
		}
		return (float) wp_unslash( $_POST[ $name ] );
	}

	/**
	 * @param string $name Field name.
	 * @return int|null
	 */
	private static function posted_int( $name ) {
		if ( ! isset( $_POST[ $name ] ) || '' === trim( (string) wp_unslash( $_POST[ $name ] ) ) ) {
			return null;
		}
		return (int) wp_unslash( $_POST[ $name ] );
	}

	/**
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 */
	private static function check_in_summary( $member_id, $enrolment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT week_number, weight_kg, waist_cm FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE enrolment_id = %d ORDER BY week_number ASC', $enrolment_id ) );
		if ( ! $rows ) {
			return '';
		}
		if ( 1 === count( $rows ) ) {
			return '<p>This is your starting point.</p>';
		}
		$chart = array();
		foreach ( $rows as $row ) {
			if ( null === $row->weight_kg || '' === (string) $row->weight_kg ) {
				continue;
			}
			$chart[ 'Week ' . (int) $row->week_number ] = (int) round( (float) $row->weight_kg );
		}
		$html = '<p>Change since week 1.</p>';
		if ( count( $chart ) >= 2 ) {
			$html .= lld_chart( 'Change since week 1', $chart );
		} else {
			$html .= '<p>Not enough this week to compare.</p>';
		}
		unset( $member_id );
		return $html;
	}

	private static function review_screen() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		$row       = self::active( $member_id );
		$main      = '<h1>Weekly review</h1>';
		if ( ! $row ) {
			$main .= '<p>Your review is not ready yet.</p>';
			LLD_Members_Personal::render( 'Weekly review', $main );
			return;
		}
		$programme = self::programme_day( $member_id, (int) $row->id );
		$pos       = self::position_parts( $programme );
		$ready     = $pos['day'] >= 7 || $programme >= ( $pos['week'] * 7 );
		if ( $programme < 7 ) {
			$main .= '<p>Your review is not ready yet.</p>';
			LLD_Members_Personal::render( 'Weekly review', $main );
			return;
		}
		$free = 'free' === strtolower( LLD_Members_Access::access_state( $member_id ) );
		if ( $free ) {
			$main .= '<p>Here is a short review of your week.</p>';
			$main .= lld_membership_lock( 'A full review is part of Premium.' );
		} else {
			$done  = self::done_count( $member_id );
			$main .= '<p>You completed ' . (int) $done . ' activities.</p>';
			$main .= self::check_in_summary( $member_id, (int) $row->id );
			global $wpdb;
			$meals = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d', $member_id ) );
			$main .= $meals ? '<p>Your diary has entries this week.</p>' : '<p>No diary entries this week.</p>';
			$plan = self::generate( $member_id );
			$steps = array_slice( $plan['actions'], 0, 2 );
			if ( $steps ) {
				$main .= '<h2>Next steps</h2><ul>';
				foreach ( $steps as $step ) {
					$main .= '<li>' . esc_html( (string) $step['title'] ) . '</li>';
				}
				$main .= '</ul>';
			}
		}
		if ( $programme >= 28 ) {
			$main .= '<p>Try the next track or this month\'s challenge.</p>';
		}
		$main .= '<p>You showed up. That is enough for this week.</p>';
		unset( $ready );
		LLD_Members_Personal::render( 'Weekly review', $main );
	}

	/**
	 * Send opt-in programme mail. Nothing here reads health data.
	 */
	public static function dispatch_mail() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'email_pref' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE welcome = 1 OR daily_reminder = 1 OR weekly_review = 1 OR end_of_track = 1 OR nudges = 1" );
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $pref ) {
			self::mail_member( $pref );
		}
	}

	/**
	 * @param object $pref Preference row.
	 */
	private static function mail_member( $pref ) {
		$member_id = (int) $pref->member_id;
		$email     = self::email_of( $member_id );
		if ( ! is_email( $email ) ) {
			return;
		}
		if ( ! empty( $pref->welcome ) && ! get_option( 'lld_mail_welcome_' . $member_id ) ) {
			self::send_kind( $member_id, $email, 'welcome', home_url( '/members/home/' ) );
			update_option( 'lld_mail_welcome_' . $member_id, self::local_today( $member_id ), false );
		}
		if ( ! empty( $pref->daily_reminder ) && self::in_window( $member_id, (string) $pref->reminder_time ) ) {
			$key = 'lld_mail_daily_' . $member_id . '_' . self::local_today( $member_id );
			if ( ! get_option( $key ) ) {
				self::send_kind( $member_id, $email, 'daily_reminder', home_url( '/members/home/' ) );
				update_option( $key, '1', false );
			}
		}
		if ( ! empty( $pref->nudges ) ) {
			self::maybe_nudge( $member_id, $email, $pref );
		}
		$row = self::active( $member_id );
		if ( ! $row ) {
			return;
		}
		$programme = self::programme_day( $member_id, (int) $row->id );
		$pos       = self::position_parts( $programme );
		if ( ! empty( $pref->weekly_review ) && $pos['day'] >= 7 && $programme <= 28 ) {
			$key = 'lld_mail_review_' . (int) $row->id . '_' . $pos['week'];
			if ( ! get_option( $key ) ) {
				self::send_kind( $member_id, $email, 'weekly_review', home_url( '/members/review/' ) );
				update_option( $key, '1', false );
				self::save_review_row( $member_id, (int) $row->id, $pos['week'] );
			}
		}
		if ( ! empty( $pref->end_of_track ) && $programme >= 28 && ! get_option( 'lld_mail_end_' . (int) $row->id ) ) {
			self::send_kind( $member_id, $email, 'end_of_track', home_url( '/members/tracks/' ) );
			update_option( 'lld_mail_end_' . (int) $row->id, '1', false );
		}
	}

	/**
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
		try {
			$zone = new DateTimeZone( self::timezone( $member_id ) );
		} catch ( Exception $failed ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$anchor = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $signed, new DateTimeZone( 'UTC' ) );
		if ( ! $anchor ) {
			return;
		}
		$from = new DateTimeImmutable( $anchor->setTimezone( $zone )->format( 'Y-m-d' ) );
		$to   = new DateTimeImmutable( self::local_today( $member_id ) );
		$days = (int) $from->diff( $to )->days;
		$due  = ( 0 === $sent && $days >= 5 ) || ( 1 === $sent && $pref->last_nudge_on && (int) ( new DateTimeImmutable( (string) $pref->last_nudge_on ) )->diff( $to )->days >= 5 );
		if ( ! $due ) {
			return;
		}
		self::send_kind( $member_id, $email, 'nudges', home_url( '/members/home/' ) );
		$wpdb->update(
			LLD_Members_Schema::table( 'email_pref' ),
			array(
				'nudges_sent'   => $sent + 1,
				'last_nudge_on' => self::local_today( $member_id ),
			),
			array( 'member_id' => $member_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $clock     Reminder time H:i or H:i:s.
	 */
	public static function in_window( $member_id, $clock ) {
		$parts = array_map( 'intval', explode( ':', $clock ? $clock : '07:30' ) );
		$target = ( $parts[0] * 60 ) + ( $parts[1] ?? 0 );
		try {
			$zone = new DateTimeZone( self::timezone( $member_id ) );
		} catch ( Exception $failed ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		$local   = LLD_Members_Clock::now( $member_id )->setTimezone( $zone );
		$minutes = ( (int) $local->format( 'H' ) * 60 ) + (int) $local->format( 'i' );
		return $minutes >= $target && $minutes < ( $target + 10 );
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

	/**
	 * @param int $member_id    Member number.
	 * @param int $enrolment_id Enrolment.
	 * @param int $week         Week number.
	 */
	private static function save_review_row( $member_id, $enrolment_id, $week ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'weekly_review' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrolment_id = %d AND week_number = %d", $enrolment_id, $week ) );
		if ( $id ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'    => $member_id,
				'enrolment_id' => $enrolment_id,
				'week_number'  => $week,
				'ready_on'     => self::local_today( $member_id ),
				'next_steps'   => '[]',
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		if ( ! empty( $params['pcts'] ) && is_array( $params['pcts'] ) ) {
			$track = isset( $params['track'] ) ? sanitize_title( (string) $params['track'] ) : '';
			$items = self::activities( self::track_id( $track ), 0, 0 );
			$out   = array();
			foreach ( $params['pcts'] as $pct ) {
				$built  = self::select( $items, (float) $pct, 'standard', 0 );
				$titles = array();
				foreach ( $built['actions'] as $action ) {
					$titles[] = (string) $action['title'];
				}
				$out[] = array(
					'pct'    => (float) $pct,
					'level'  => $built['level'],
					'count'  => count( $titles ),
					'titles' => $titles,
				);
			}
			return new WP_REST_Response( array( 'rows' => $out ), 200 );
		}
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		if ( ! $member_id ) {
			return new WP_REST_Response( array( 'message' => 'Member not found.' ), 404 );
		}
		$built = self::generate( $member_id );
		$titles = array();
		foreach ( $built['actions'] as $action ) {
			$titles[] = (string) $action['title'];
		}
		return new WP_REST_Response(
			array(
				'level'  => $built['level'],
				'note'   => $built['note'],
				'titles' => $titles,
				'bodies' => self::mail_catalogue(),
			),
			200
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
