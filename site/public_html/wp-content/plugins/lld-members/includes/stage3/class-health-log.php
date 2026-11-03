<?php
/**
 * Health logging (P3-50, FR3-38 to FR3-46).
 *
 * Values are stored metric (kg, cm, mmol/L, ml, hours). unit_shown remembers what the member typed.
 * Warning levels and wording come only from LLD_Members_Stage3_Safety::warning_level().
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading normalisation, history limits and the small views the logging screens read.
 */
class LLD_Members_Health_Log {

	const HISTORY_DAYS = 14;
	const GLASS_ML     = 250;
	const LB_PER_KG    = 2.2046226218;
	const CM_PER_IN    = 2.54;
	const MG_PER_MMOL  = 18.016;

	/**
	 * Fill value_1 for sleep from bedtime+wake before the API required-field check.
	 *
	 * @param array<string,mixed> $params Request body.
	 * @return array<string,mixed>
	 */
	public static function accept_input( array $params ) {
		$type = isset( $params['reading_type'] ) ? (string) $params['reading_type'] : '';
		if ( 'sleep' === $type && ( ! isset( $params['value_1'] ) || '' === $params['value_1'] || null === $params['value_1'] ) ) {
			$hours = self::sleep_hours( $params );
			if ( null !== $hours ) {
				$params['value_1'] = $hours;
			}
		}
		return $params;
	}

	/**
	 * Prepare a reading for insert or update. Refuses impossible values. Does not invent warning text.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $entry     Validated fields (and optional bedtime, glasses, symptoms, dizzy).
	 * @param array<string,mixed> $existing  Current row when editing.
	 * @return array<string,mixed>
	 */
	public static function for_member( $member_id, array $entry, array $existing = array() ) {
		$type = isset( $entry['reading_type'] ) ? (string) $entry['reading_type'] : (string) ( $existing['reading_type'] ?? '' );
		if ( '' === $type ) {
			return array( 'error' => 'Add a reading type.', 'status' => 400 );
		}
		if ( class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			$a = LLD_Members_Stage3_Safety::applies( (int) $member_id, 'health_logging' );
			// Unknown / no consent: the matrix refuses every store (I-12 allows glucose for everyone else).
			if ( $a['active'] && in_array( 'store', $a['refused'], true ) ) {
				return array( 'error' => 'Not available.', 'status' => 403 );
			}
		}
		$merged = array_merge( $existing, $entry );
		$merged['reading_type'] = $type;
		$norm = self::normalise( $merged );
		if ( isset( $norm['error'] ) ) {
			return $norm;
		}
		$flags  = self::flags_for( $member_id, $merged );
		$values = self::warning_values( $type, $norm, $member_id );
		$warn   = LLD_Members_Stage3_Safety::warning_level( $type, $values, $flags );
		if ( 'impossible' === $warn['level'] ) {
			return array(
				'error'   => null !== $warn['message'] && '' !== $warn['message'] ? $warn['message'] : 'Check the numbers.',
				'status'  => 400,
				'warning' => $warn,
			);
		}
		$norm['urgent_shown'] = ( 'urgent' === $warn['level'] ) ? 1 : 0;
		if ( ! isset( $norm['source'] ) || '' === (string) $norm['source'] ) {
			$norm['source'] = 'manual';
		}
		// Persist only columns the readings table knows.
		$row = array();
		foreach ( array( 'reading_type', 'taken_at', 'value_1', 'value_2', 'value_3', 'detail', 'unit_shown', 'urgent_shown', 'source', 'external_ref' ) as $key ) {
			if ( array_key_exists( $key, $norm ) ) {
				$row[ $key ] = $norm[ $key ];
			}
		}
		return array(
			'row'     => $row,
			'warning' => $warn,
		);
	}

	/**
	 * Convert display units into stored metric values.
	 *
	 * @param array<string,mixed> $entry Raw or merged fields.
	 * @return array<string,mixed>
	 */
	public static function normalise( array $entry ) {
		$type = (string) ( $entry['reading_type'] ?? '' );
		$unit = isset( $entry['unit_shown'] ) ? (string) $entry['unit_shown'] : '';
		$out  = array(
			'reading_type' => $type,
			'taken_at'     => $entry['taken_at'] ?? null,
			'value_1'      => isset( $entry['value_1'] ) && is_numeric( $entry['value_1'] ) ? (float) $entry['value_1'] : null,
			'value_2'      => isset( $entry['value_2'] ) && is_numeric( $entry['value_2'] ) ? (float) $entry['value_2'] : null,
			'value_3'      => isset( $entry['value_3'] ) && is_numeric( $entry['value_3'] ) ? (float) $entry['value_3'] : null,
			'detail'       => isset( $entry['detail'] ) ? (string) $entry['detail'] : null,
			'unit_shown'   => '' !== $unit ? $unit : null,
			'source'       => isset( $entry['source'] ) ? (string) $entry['source'] : 'manual',
			'external_ref' => isset( $entry['external_ref'] ) ? (string) $entry['external_ref'] : null,
		);
		if ( 'weight' === $type ) {
			if ( 'lb' === $unit && null !== $out['value_1'] ) {
				$out['value_1'] = round( $out['value_1'] / self::LB_PER_KG, 2 );
			}
			if ( '' === $unit ) {
				$out['unit_shown'] = 'kg';
			}
		}
		if ( 'waist' === $type ) {
			if ( 'in' === $unit && null !== $out['value_1'] ) {
				$out['value_1'] = round( $out['value_1'] * self::CM_PER_IN, 1 );
			}
			if ( '' === $unit ) {
				$out['unit_shown'] = 'cm';
			}
		}
		if ( 'glucose' === $type ) {
			if ( 'mg/dL' === $unit && null !== $out['value_1'] ) {
				$out['value_1'] = round( $out['value_1'] / self::MG_PER_MMOL, 1 );
			}
			if ( '' === $unit ) {
				$out['unit_shown'] = 'mmol/L';
			}
		}
		if ( 'water' === $type ) {
			if ( 'glasses' === $unit && null !== $out['value_1'] ) {
				$out['value_1'] = round( $out['value_1'] * self::GLASS_ML, 0 );
			}
			if ( '' === $unit || 'glasses' === $unit ) {
				$out['unit_shown'] = '' !== $unit ? $unit : 'ml';
			}
		}
		if ( 'sleep' === $type ) {
			$hours = self::sleep_hours( $entry );
			if ( null !== $hours ) {
				$out['value_1'] = $hours;
			}
			if ( isset( $entry['bedtime'] ) && is_string( $entry['bedtime'] ) && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)/', $entry['bedtime'], $m ) ) {
				$out['value_2'] = ( (int) $m[1] * 60 ) + (int) $m[2];
			}
		}
		return $out;
	}

	/**
	 * Hours of sleep from bedtime and wake, or from value_1.
	 *
	 * @param array<string,mixed> $entry Fields. bedtime HH:MM optional; taken_at is wake time.
	 * @return float|null
	 */
	public static function sleep_hours( array $entry ) {
		if ( isset( $entry['value_1'] ) && is_numeric( $entry['value_1'] ) && ! isset( $entry['bedtime'] ) ) {
			return round( (float) $entry['value_1'], 1 );
		}
		$bed = isset( $entry['bedtime'] ) ? (string) $entry['bedtime'] : '';
		$wake = isset( $entry['taken_at'] ) ? (string) $entry['taken_at'] : '';
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)/', $bed, $b ) || ! preg_match( '/\d{4}-\d{2}-\d{2}[ T]([01]\d|2[0-3]):([0-5]\d)/', $wake, $w ) ) {
			return isset( $entry['value_1'] ) && is_numeric( $entry['value_1'] ) ? round( (float) $entry['value_1'], 1 ) : null;
		}
		$bed_m  = ( (int) $b[1] * 60 ) + (int) $b[2];
		$wake_m = ( (int) $w[1] * 60 ) + (int) $w[2];
		$mins   = $wake_m - $bed_m;
		if ( $mins <= 0 ) {
			$mins += 24 * 60;
		}
		return round( $mins / 60, 1 );
	}

	/**
	 * Show a stored metric value in the unit the member chose.
	 *
	 * @param string     $type  Reading type.
	 * @param float|null $value Stored metric value.
	 * @param string     $unit  unit_shown.
	 * @return float|null
	 */
	public static function display_value( $type, $value, $unit ) {
		if ( null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		if ( 'weight' === $type && 'lb' === $unit ) {
			return round( $value * self::LB_PER_KG, 1 );
		}
		if ( 'waist' === $type && 'in' === $unit ) {
			return round( $value / self::CM_PER_IN, 1 );
		}
		if ( 'glucose' === $type && 'mg/dL' === $unit ) {
			return round( $value * self::MG_PER_MMOL, 0 );
		}
		if ( 'water' === $type && 'glasses' === $unit ) {
			return round( $value / self::GLASS_ML, 1 );
		}
		return $value;
	}

	/**
	 * Free members see today and the 13 days before it. Premium sees every stored day.
	 *
	 * @param string $taken_at Datetime.
	 * @param string $today    Y-m-d.
	 * @param bool   $premium  Premium access.
	 */
	public static function history_open( $taken_at, $today, $premium ) {
		if ( $premium ) {
			return true;
		}
		$date = substr( (string) $taken_at, 0, 10 );
		try {
			$zone    = new DateTimeZone( 'UTC' );
			$today_d = new DateTimeImmutable( $today . ' 00:00:00', $zone );
			$date_d  = new DateTimeImmutable( $date . ' 00:00:00', $zone );
		} catch ( Exception $e ) {
			unset( $e );
			return false;
		}
		return $date_d >= $today_d->modify( '-' . ( self::HISTORY_DAYS - 1 ) . ' days' );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows    Reading rows.
	 * @param bool                            $premium Premium access.
	 * @param string                          $today   Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	public static function limit_history( array $rows, $premium, $today ) {
		if ( $premium ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( self::history_open( $row['taken_at'] ?? '', $today, false ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Filter by type and optional date range (inclusive Y-m-d on taken_at).
	 *
	 * @param array<int,array<string,mixed>> $rows Reading rows.
	 * @param string                          $type reading_type or ''.
	 * @param string                          $from Y-m-d or ''.
	 * @param string                          $to   Y-m-d or ''.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter( array $rows, $type, $from, $to ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			if ( '' !== $type && ( $row['reading_type'] ?? '' ) !== $type ) {
				continue;
			}
			$date = substr( (string) ( $row['taken_at'] ?? '' ), 0, 10 );
			if ( '' !== $from && $date < $from ) {
				continue;
			}
			if ( '' !== $to && $date > $to ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Points for a trend chart: date and the main value (plus bottom number for blood pressure).
	 *
	 * @param array<int,array<string,mixed>> $rows Filtered readings, oldest first preferred.
	 * @param string                          $type Reading type.
	 * @return array<int,array<string,mixed>>
	 */
	public static function trend( array $rows, $type ) {
		$points = array();
		foreach ( $rows as $row ) {
			if ( ( $row['reading_type'] ?? '' ) !== $type ) {
				continue;
			}
			$point = array(
				'id'       => (int) ( $row['id'] ?? 0 ),
				'date'     => substr( (string) ( $row['taken_at'] ?? '' ), 0, 10 ),
				'taken_at' => (string) ( $row['taken_at'] ?? '' ),
				'value_1'  => isset( $row['value_1'] ) && is_numeric( $row['value_1'] ) ? (float) $row['value_1'] : null,
			);
			if ( 'blood_pressure' === $type ) {
				$point['value_2'] = isset( $row['value_2'] ) && is_numeric( $row['value_2'] ) ? (float) $row['value_2'] : null;
				$point['value_3'] = isset( $row['value_3'] ) && is_numeric( $row['value_3'] ) ? (float) $row['value_3'] : null;
			}
			$points[] = $point;
		}
		return $points;
	}

	/**
	 * Activity minutes in a week (Monday start) against the 150-minute guide.
	 *
	 * @param array<int,array<string,mixed>> $rows       Activity readings.
	 * @param string                          $week_start Monday Y-m-d.
	 * @return array<string,mixed>
	 */
	public static function activity_week( array $rows, $week_start ) {
		try {
			$start = new DateTimeImmutable( $week_start . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			unset( $e );
			return array( 'error' => 'Add a week start date.' );
		}
		if ( '1' !== $start->format( 'N' ) ) {
			return array( 'error' => 'The week start must be a Monday.' );
		}
		$end   = $start->modify( '+6 days' )->format( 'Y-m-d' );
		$total = 0.0;
		$days  = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$days[ $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ) ] = 0.0;
		}
		foreach ( $rows as $row ) {
			if ( 'activity' !== ( $row['reading_type'] ?? '' ) || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			$date = substr( (string) ( $row['taken_at'] ?? '' ), 0, 10 );
			if ( $date < $week_start || $date > $end || ! isset( $days[ $date ] ) ) {
				continue;
			}
			$mins = isset( $row['value_1'] ) && is_numeric( $row['value_1'] ) ? (float) $row['value_1'] : 0.0;
			$days[ $date ] += $mins;
			$total         += $mins;
		}
		return array(
			'week_start'   => $week_start,
			'week_end'     => $end,
			'minutes'      => round( $total, 1 ),
			'guide'        => 150,
			'days'         => $days,
		);
	}

	/**
	 * Water total for one local date (ml).
	 *
	 * @param array<int,array<string,mixed>> $rows Readings.
	 * @param string                          $date Y-m-d.
	 * @return float
	 */
	public static function water_day_ml( array $rows, $date ) {
		$total = 0.0;
		foreach ( $rows as $row ) {
			if ( 'water' !== ( $row['reading_type'] ?? '' ) || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			if ( substr( (string) ( $row['taken_at'] ?? '' ), 0, 10 ) !== $date ) {
				continue;
			}
			if ( isset( $row['value_1'] ) && is_numeric( $row['value_1'] ) ) {
				$total += (float) $row['value_1'];
			}
		}
		return round( $total, 0 );
	}

	/**
	 * Present a stored row with optional display conversion and no ciphertext.
	 *
	 * @param array<string,mixed> $row  Stored row.
	 * @param string|null         $unit Override unit for display.
	 * @return array<string,mixed>
	 */
	public static function present( array $row, $unit = null ) {
		$type = (string) ( $row['reading_type'] ?? '' );
		$unit = null !== $unit && '' !== $unit ? $unit : (string) ( $row['unit_shown'] ?? '' );
		$out  = $row;
		$out['display_value_1'] = self::display_value( $type, isset( $row['value_1'] ) ? $row['value_1'] : null, $unit );
		$out['display_unit']    = $unit;
		return $out;
	}

	/**
	 * Computed list/trend views.
	 *
	 * @param string               $which     list, trend or activity-week.
	 * @param int                  $member_id Member number.
	 * @param array<string,string> $query     type, from, to, week_start, unit.
	 * @return array<string,mixed>
	 */
	public static function view( $which, $member_id, array $query ) {
		$premium = class_exists( 'LLD_Members_Food_Log' ) ? LLD_Members_Food_Log::premium( $member_id ) : false;
		$today   = gmdate( 'Y-m-d' );
		$rows    = self::limit_history( self::rows_for( $member_id ), $premium, $today );
		$type    = isset( $query['type'] ) ? (string) $query['type'] : '';
		$from    = isset( $query['from'] ) ? (string) $query['from'] : '';
		$to      = isset( $query['to'] ) ? (string) $query['to'] : '';
		$filtered = self::filter( $rows, $type, $from, $to );
		if ( 'trend' === $which ) {
			if ( '' === $type ) {
				return array( 'error' => 'Add a reading type.' );
			}
			return array(
				'type'   => $type,
				'points' => self::trend( $filtered, $type ),
			);
		}
		if ( 'activity-week' === $which ) {
			return self::activity_week( $rows, $query['week_start'] ?? '' );
		}
		$presented = array();
		foreach ( $filtered as $row ) {
			$presented[] = self::present( $row, $query['unit'] ?? null );
		}
		return array( 'rows' => $presented );
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $entry     Incoming fields.
	 * @return array<string,mixed>
	 */
	private static function flags_for( $member_id, array $entry ) {
		$flags = array();
		if ( class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			foreach ( LLD_Members_Stage3_Safety::situations( $member_id ) as $s ) {
				$flags[ $s ] = true;
			}
		}
		if ( ! empty( $entry['dizzy'] ) ) {
			$flags['dizzy'] = true;
		}
		if ( ! empty( $entry['self_harm'] ) ) {
			$flags['self_harm'] = true;
		}
		if ( isset( $entry['symptoms'] ) && is_array( $entry['symptoms'] ) ) {
			$flags['symptoms'] = $entry['symptoms'];
		} elseif ( isset( $entry['detail'] ) && is_string( $entry['detail'] ) ) {
			$decoded = json_decode( $entry['detail'], true );
			if ( is_array( $decoded ) && isset( $decoded['symptoms'] ) && is_array( $decoded['symptoms'] ) ) {
				$flags['symptoms'] = $decoded['symptoms'];
			}
		}
		return $flags;
	}

	/**
	 * @param string              $type      Reading type.
	 * @param array<string,mixed> $norm      Normalised row.
	 * @param int                 $member_id Member number.
	 * @return array<string,mixed>
	 */
	private static function warning_values( $type, array $norm, $member_id ) {
		$values = array(
			'value_1' => $norm['value_1'],
			'value_2' => $norm['value_2'],
			'value_3' => $norm['value_3'],
		);
		if ( 'glucose' === $type ) {
			$values['mmol'] = $norm['value_1'];
			$values['unit'] = 'mmol/L';
		}
		if ( 'weight' === $type ) {
			$values['kg'] = $norm['value_1'];
			$prev         = self::previous_weight( $member_id, $norm['taken_at'] ?? '' );
			if ( $prev ) {
				$values['previous'] = $prev;
			}
		}
		if ( 'water' === $type ) {
			$values['ml']           = $norm['value_1'];
			$date                   = substr( (string) ( $norm['taken_at'] ?? '' ), 0, 10 );
			$values['day_total_ml'] = self::water_day_ml( self::rows_for( $member_id ), $date ) + (float) $norm['value_1'];
		}
		if ( 'sleep' === $type ) {
			$values['hours'] = $norm['value_1'];
			$avg             = self::avg_sleep_7( $member_id, $norm );
			if ( null !== $avg ) {
				$values['avg_7_nights'] = $avg;
			}
		}
		if ( 'mood' === $type ) {
			$values['mood'] = $norm['value_1'];
		}
		if ( 'activity' === $type ) {
			$values['minutes'] = $norm['value_1'];
			$values['steps']   = $norm['value_2'];
			$values['effort']  = $norm['value_3'];
		}
		if ( 'waist' === $type ) {
			$values['cm'] = $norm['value_1'];
		}
		return $values;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $before    taken_at of the new reading.
	 * @return array{kg:float,hours_ago:float}|null
	 */
	private static function previous_weight( $member_id, $before ) {
		$best = null;
		foreach ( self::rows_for( $member_id ) as $row ) {
			if ( 'weight' !== ( $row['reading_type'] ?? '' ) || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			$at = (string) ( $row['taken_at'] ?? '' );
			if ( '' === $at || ( '' !== $before && $at >= $before ) ) {
				continue;
			}
			if ( null === $best || $at > $best['taken_at'] ) {
				$best = $row;
			}
		}
		if ( ! $best || ! is_numeric( $best['value_1'] ?? null ) ) {
			return null;
		}
		try {
			$a = new DateTimeImmutable( $best['taken_at'] . ' UTC' );
			$b = new DateTimeImmutable( ( '' !== $before ? $before : gmdate( 'Y-m-d H:i:s' ) ) . ' UTC' );
			$hours = max( 0, ( $b->getTimestamp() - $a->getTimestamp() ) / 3600 );
		} catch ( Exception $e ) {
			unset( $e );
			$hours = 999;
		}
		return array(
			'kg'        => (float) $best['value_1'],
			'hours_ago' => $hours,
		);
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $norm      New sleep row included in the average.
	 * @return float|null
	 */
	private static function avg_sleep_7( $member_id, array $norm ) {
		$date = substr( (string) ( $norm['taken_at'] ?? '' ), 0, 10 );
		if ( '' === $date ) {
			return null;
		}
		try {
			$end = new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			unset( $e );
			return null;
		}
		$start = $end->modify( '-6 days' )->format( 'Y-m-d' );
		$sum   = 0.0;
		$n     = 0;
		$seen  = array();
		if ( isset( $norm['value_1'] ) && is_numeric( $norm['value_1'] ) ) {
			$sum += (float) $norm['value_1'];
			$n++;
			$seen[ $date ] = true;
		}
		foreach ( self::rows_for( $member_id ) as $row ) {
			if ( 'sleep' !== ( $row['reading_type'] ?? '' ) || ! empty( $row['deleted_at'] ) ) {
				continue;
			}
			$d = substr( (string) ( $row['taken_at'] ?? '' ), 0, 10 );
			if ( $d < $start || $d > $date || isset( $seen[ $d ] ) || ! is_numeric( $row['value_1'] ?? null ) ) {
				continue;
			}
			$sum += (float) $row['value_1'];
			$n++;
			$seen[ $d ] = true;
		}
		return $n > 0 ? round( $sum / $n, 1 ) : null;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows_for( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Stage3_Data' ) ) {
			return array();
		}
		return LLD_Members_Stage3_Data::rows( 'readings', (int) $member_id, array( 'value_1', 'value_2', 'value_3', 'detail' ), '', 2000 );
	}
}
