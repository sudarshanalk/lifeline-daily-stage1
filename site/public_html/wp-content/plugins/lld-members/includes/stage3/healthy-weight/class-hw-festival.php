<?php
/**
 * Healthy Weight programme: when the Festivals and fasting focus block is offered (R-HW-BLOCK-02) and how it is fitted
 * around the festival (R-HW-BLOCK-03, rule catalogue version 1.3, signed 10 Nov 2026, D3-59; owner answers T1 to T3
 * all A; `04 Health Tools/Stage 3/P3-02-R-HW-BLOCK-03-Festival-Timing-Change-Draft.md`, fixtures HWB-03a to -03f).
 *
 * Pure date arithmetic on Y-m-d strings (UTC, the member's own calendar days): no WordPress, no database, no clock.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The festival block's offer window and its day map.
 */
class LLD_Members_HW_Festival {

	const BLOCK = 'festivals-and-fasting';

	/** R-HW-BLOCK-02: offered when the festival is 2 to 6 weeks away, counted in days from the check-in. */
	const WINDOW_MIN_DAYS = 14;
	const WINDOW_MAX_DAYS = 42;

	/** Week 3 items written for members who keep a fast without type 2 diabetes; everyone else gets the -ALT item. */
	const FASTING_ITEMS = array( 'W3-A2', 'W3-A3', 'W3-A4' );

	/**
	 * Is the Festivals and fasting block offered for this festival at this check-in (R-HW-BLOCK-02 with the v1.3 answers)?
	 *
	 * @param array $in festival {id, name, start}; today (the check-in date); programme_week (1…); keeps (the member chose
	 *                  this festival or fast, FR3-99); declined ("Not for this festival", T3); finished (the block has
	 *                  already run for this festival); running (a festival block is running now).
	 * @return array {offered: bool, rule: R-HW-BLOCK-02, reason: '' or why not, days: days to the first day, weeks: whole
	 *               weeks to it (nearest), festival}
	 */
	public static function offer( array $in ) {
		$f    = isset( $in['festival'] ) && is_array( $in['festival'] ) ? $in['festival'] : array();
		$days = isset( $f['start'], $in['today'] ) ? self::days_between( $in['today'], $f['start'] ) : null;
		$out  = array(
			'offered'  => false,
			'rule'     => 'R-HW-BLOCK-02',
			'reason'   => '',
			'days'     => $days,
			'weeks'    => null === $days ? null : (int) floor( $days / 7 + 0.5 ),
			'festival' => isset( $f['id'] ) ? $f['id'] : null,
		);
		if ( empty( $in['keeps'] ) ) {
			$out['reason'] = 'not_in_member_calendar'; // offered only to members who keep that festival or fast (FR3-104 AS-03)
		} elseif ( isset( $in['programme_week'] ) && (int) $in['programme_week'] <= 8 ) {
			$out['reason'] = 'weeks_1_to_8'; // T1 = A: no festival block in weeks 1 to 8
		} elseif ( null === $days || $days < self::WINDOW_MIN_DAYS || $days > self::WINDOW_MAX_DAYS ) {
			$out['reason'] = 'outside_window';
		} elseif ( ! empty( $in['declined'] ) ) {
			$out['reason'] = 'not_for_this_festival'; // T3 = A
		} elseif ( ! empty( $in['finished'] ) ) {
			$out['reason'] = 'finished_for_this_festival';
		} elseif ( ! empty( $in['running'] ) ) {
			$out['reason'] = 'festival_block_running'; // a second festival block never starts while one runs
		} else {
			$out['offered'] = true;
		}
		return $out;
	}

	/**
	 * The day map of R-HW-BLOCK-03.
	 *
	 * @param array $in start (S, the festival's or fast's first day), end (E, its last day; S for one day), accepted (the
	 *                  check-in date the block was accepted on; the member's weeks end on that weekday), fasts (the member
	 *                  keeps this fast), type2_diabetes, current ({block, next_week}: the block the member is in and the
	 *                  week of it due next, or null), next_block (the block the signed order suggests next, used if the
	 *                  current one finishes first), moves (list of {on, start, end?}: the calendar moved the dates on that
	 *                  day; end defaults to moving with start).
	 * @return array {rule: R-HW-BLOCK-03, T, shortened: bool, days: list of {date, kind, block, week, day, step, note,
	 *               version, repeat}, resume: {block, week, from}|null, week3_from, week4_from, week4_to}.
	 *               kind is current (the member's own block), filler (an unused action-library step and the next note),
	 *               festival (the block's weeks 1 to 4), repeat (week 3 again, a fast longer than 7 days).
	 */
	public static function schedule( array $in ) {
		$plan  = self::plan( $in, $in['start'], isset( $in['end'] ) ? $in['end'] : $in['start'] );
		$moves = isset( $in['moves'] ) && is_array( $in['moves'] ) ? $in['moves'] : array();
		foreach ( $moves as $move ) {
			$shift = self::days_between( $plan['S'], $move['start'] );
			$end   = isset( $move['end'] ) ? $move['end'] : self::add_days( $plan['E'], $shift );
			$next  = self::plan( $in, $move['start'], $end );
			$plan  = self::merge_after( $plan, $next, $move['on'] );
		}
		return array(
			'rule'       => 'R-HW-BLOCK-03',
			'T'          => $plan['T'],
			'shortened'  => $plan['shortened'],
			'days'       => array_values( $plan['days'] ),
			'resume'     => $plan['resume'],
			'week3_from' => $plan['S'],
			'week4_from' => $plan['week4_from'],
			'week4_to'   => self::add_days( $plan['week4_from'], 6 ),
		);
	}

	/**
	 * One plan for one pair of dates (before any calendar move).
	 *
	 * @param array  $in    schedule() input.
	 * @param string $s     First day.
	 * @param string $e     Last day.
	 * @return array
	 */
	private static function plan( array $in, $s, $e ) {
		$c       = $in['accepted'];
		$t       = self::add_days( $s, -14 );
		$fasts   = ! empty( $in['fasts'] );
		$t2d     = ! empty( $in['type2_diabetes'] );
		$current = isset( $in['current'] ) && is_array( $in['current'] ) ? $in['current'] : null;
		$days    = array();
		$resume  = null;
		$short   = false;

		$gap = self::days_between( $c, $t );
		if ( $gap > 0 ) {
			// 1. The current block carries on in whole weeks up to the last week that ends before T, then pauses.
			$n     = (int) ceil( $gap / 7 ) - 1;
			$block = $current ? $current['block'] : null;
			$week  = $current ? (int) $current['next_week'] : 1;
			for ( $k = 1; $k <= $n; $k++ ) {
				if ( $block && $week > 4 ) {
					// The current block finished earlier: the next block the signed order suggests starts as usual.
					$block = isset( $in['next_block'] ) ? $in['next_block'] : null;
					$week  = 1;
				}
				for ( $d = 1; $d <= 7; $d++ ) {
					$date          = self::add_days( $c, 7 * ( $k - 1 ) + $d );
					$days[ $date ] = self::day( $date, $block ? 'current' : 'filler', $block, $block ? $week : null, $block ? $d : null, null, $block ? null : 'next' );
				}
				++$week;
			}
			if ( $block && $week <= 4 ) {
				$resume = array(
					'block' => $block,
					'week'  => $week,
				);
			}
			// 2. The days left before T (0 to 6) use an unused action-library step and the next approved note.
			for ( $date = self::add_days( $c, 7 * $n + 1 ); self::days_between( $date, $t ) > 0; $date = self::add_days( $date, 1 ) ) {
				$days[ $date ] = self::day( $date, 'filler', null, null, null, 'action_library', 'next' );
			}
			// 3. The block's week 1 starts on T; weeks 1 and 2 run in full.
			for ( $i = 0; $i < 14; $i++ ) {
				$date          = self::add_days( $t, $i );
				$w             = $i < 7 ? 1 : 2;
				$d             = $i % 7 + 1;
				$days[ $date ] = self::day( $date, 'festival', self::BLOCK, $w, $d, self::step( $w, $d, $fasts, $t2d ), 'HW-FB08-MN-W' . $w . '-' . $d );
			}
		} else {
			// 4. The current week ends on or after T (accepted 14 days or fewer before S): the block starts the day after,
			// and weeks 1 and 2 are shortened to the days left before S.
			$short = true;
			$from  = self::add_days( $c, 1 );
			$avail = max( 0, self::days_between( $from, $s ) );
			$steps = array();
			if ( $fasts ) {
				$steps[] = 'W1-A2'; // "Before a fast, talk to your doctor" always on day 1 for members who fast
			}
			for ( $w = 1; $w <= 2; $w++ ) {
				for ( $d = 1; $d <= 7; $d++ ) {
					if ( ! ( $fasts && 1 === $w && 2 === $d ) ) {
						$steps[] = 'W' . $w . '-A' . $d;
					}
				}
			}
			for ( $i = 0; $i < $avail && $i < 14; $i++ ) {
				$date          = self::add_days( $from, $i );
				$w             = $i < 7 ? 1 : 2;
				$sid           = $steps[ $i ];
				$wk            = (int) substr( $sid, 1, 1 );
				$dy            = (int) substr( $sid, 4 );
				$note_w        = $i < 7 ? 1 : 2;
				$days[ $date ] = self::day( $date, 'festival', self::BLOCK, $w, $i + 1, self::step( $wk, $dy, $fasts, $t2d ), 'HW-FB08-MN-W' . $note_w . '-' . ( $i % 7 + 1 ) );
				$days[ $date ]['task'] = 0 === $i ? 'HW-FB08-W1-T' : ( 7 === $i ? 'HW-FB08-W2-T' : null );
			}
			if ( $current ) {
				$resume = array(
					'block' => $current['block'],
					'week'  => (int) $current['next_week'],
				);
			}
		}

		// 5. Week 3 runs for 7 days from S.
		for ( $d = 1; $d <= 7; $d++ ) {
			$date          = self::add_days( $s, $d - 1 );
			$days[ $date ] = self::day( $date, 'festival', self::BLOCK, 3, $d, self::step( 3, $d, $fasts, $t2d ), 'HW-FB08-MN-W3-' . $d );
		}
		// 6. A festival or fast longer than 7 days: week 3's steps repeat in order, one a day, until E.
		$length = self::days_between( $s, $e ) + 1;
		if ( $length > 7 ) {
			for ( $i = 7; $i < $length; $i++ ) {
				$date                     = self::add_days( $s, $i );
				$d                        = $i % 7 + 1;
				$days[ $date ]            = self::day( $date, 'repeat', self::BLOCK, 3, $d, self::step( 3, $d, $fasts, $t2d ), 'weeks_1_to_8_next' );
				$days[ $date ]['repeat']  = (int) floor( $i / 7 );
				$days[ $date ]['version'] = 'R-HW-STEP-03'; // chosen from the week before, by the signed thresholds
			}
		}
		// 7. Week 4 starts on E + 1 (S + 7 for a festival of 7 days or fewer) and runs 7 days.
		$week4 = $length > 7 ? self::add_days( $e, 1 ) : self::add_days( $s, 7 );
		for ( $d = 1; $d <= 7; $d++ ) {
			$date          = self::add_days( $week4, $d - 1 );
			$days[ $date ] = self::day( $date, 'festival', self::BLOCK, 4, $d, self::step( 4, $d, $fasts, $t2d ), 'HW-FB08-MN-W4-' . $d );
		}
		// 8. Afterwards the paused block resumes at the week it reached, the day after week 4 ends.
		if ( $resume ) {
			$resume['from'] = self::add_days( $week4, 7 );
		}
		ksort( $days );
		return array(
			'S'          => $s,
			'E'          => $e,
			'T'          => $t,
			'shortened'  => $short,
			'days'       => $days,
			'resume'     => $resume,
			'week4_from' => $week4,
		);
	}

	/**
	 * Point 10: the calendar moved the dates on $on. Days up to $on stay as they were; later days follow the new plan, and
	 * a step already shown is never shown again (that day takes an action-library step and the next note).
	 *
	 * @param array  $old Plan before the move.
	 * @param array  $new Plan with the new dates.
	 * @param string $on  The day the move was made.
	 * @return array
	 */
	private static function merge_after( array $old, array $new, $on ) {
		$days  = array();
		$shown = array();
		foreach ( $old['days'] as $date => $day ) {
			if ( self::days_between( $date, $on ) >= 0 ) {
				$days[ $date ]           = $day;
				$shown[ self::key( $day ) ] = true;
			}
		}
		foreach ( $new['days'] as $date => $day ) {
			if ( self::days_between( $date, $on ) >= 0 ) {
				continue;
			}
			if ( 'filler' !== $day['kind'] && isset( $shown[ self::key( $day ) ] ) ) {
				$day = self::day( $date, 'filler', null, null, null, 'action_library', 'next' );
			}
			$days[ $date ] = $day;
		}
		ksort( $days );
		$new['days'] = $days;
		$new['T']    = $old['T'];
		return $new;
	}

	/** Identity of a day's content, for "nothing already shown is shown again". */
	private static function key( array $day ) {
		return $day['kind'] . '|' . $day['block'] . '|' . $day['week'] . '|' . $day['day'] . '|' . ( isset( $day['repeat'] ) ? $day['repeat'] : 0 );
	}

	/**
	 * The block's activity for a week and day: the -ALT item for members it is not written for (FB08-Q1 = A).
	 *
	 * @param int  $w     Week 1 to 4.
	 * @param int  $d     Day 1 to 7.
	 * @param bool $fasts Keeps this fast.
	 * @param bool $t2d   Type 2 diabetes.
	 * @return string
	 */
	private static function step( $w, $d, $fasts, $t2d ) {
		$id = 'W' . $w . '-A' . $d;
		if ( 'W1-A2' === $id && ! $fasts ) {
			return 'HW-FB08-' . $id . '-ALT';
		}
		if ( in_array( $id, self::FASTING_ITEMS, true ) && ( ! $fasts || $t2d ) ) {
			return 'HW-FB08-' . $id . '-ALT';
		}
		return 'HW-FB08-' . $id;
	}

	/**
	 * One day of the map.
	 */
	private static function day( $date, $kind, $block, $week, $day, $step, $note ) {
		return array(
			'date'    => $date,
			'kind'    => $kind,
			'block'   => $block,
			'week'    => $week,
			'day'     => $day,
			'step'    => $step,
			'note'    => $note,
			'version' => null,
		);
	}

	/**
	 * @param string $date Y-m-d.
	 * @param int    $n    Days to add (may be negative).
	 * @return string
	 */
	public static function add_days( $date, $n ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $at ? $at->modify( ( $n >= 0 ? '+' : '' ) . (int) $n . ' days' )->format( 'Y-m-d' ) : '';
	}

	/**
	 * Days from $a to $b (positive when $b is later).
	 */
	public static function days_between( $a, $b ) {
		$za = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $a, new DateTimeZone( 'UTC' ) );
		$zb = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $b, new DateTimeZone( 'UTC' ) );
		if ( ! $za || ! $zb ) {
			return 0;
		}
		return (int) round( ( $zb->getTimestamp() - $za->getTimestamp() ) / 86400 );
	}
}
