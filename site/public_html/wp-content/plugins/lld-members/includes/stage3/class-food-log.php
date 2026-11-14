<?php
/**
 * Food logging (P3-41, FR3-27 to FR3-32) and earlier Stage 2 diary entries (P3-42, FR3-33).
 *
 * Calories and nutrients are worked out when an entry is saved, and stored on the entry.
 * Changing the portion scales those saved values. A later refresh of the food database
 * does not change a past day.
 *
 * Stage 2 diary meals are only read, never copied or converted, so they stay exactly as
 * the member left them and cannot be duplicated.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diary arithmetic and the small set of rules the food tracker screens read.
 */
class LLD_Members_Food_Log {

	const MEALS     = array( 'breakfast', 'lunch', 'dinner', 'snacks' );
	const NUTRIENTS = array( 'kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g' );
	const HISTORY   = 14;

	const EARLIER_LABEL = 'Earlier diary entry, nutrition unknown';

	/**
	 * Premium is the access state stored for the membership. "Premium ended" is not Premium.
	 *
	 * @param int $member_id Member number.
	 */
	public static function premium( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Access' ) ) {
			return false;
		}
		return 'premium' === strtolower( (string) LLD_Members_Access::access_state( (int) $member_id ) );
	}

	/**
	 * Custom foods are Premium (FR3-30). Null means the member may continue.
	 *
	 * @param bool $premium Whether the member's access state is Premium.
	 * @return string|null
	 */
	public static function gate_custom( $premium ) {
		return $premium ? null : 'Custom foods are part of Premium.';
	}

	/**
	 * A target the member types themselves is Premium (FR3-28). Plan and calculator targets are not.
	 *
	 * @param string $set_by  plan, calculator or member.
	 * @param bool   $premium Whether the member's access state is Premium.
	 * @return string|null
	 */
	public static function gate_member_target( $set_by, $premium ) {
		if ( 'member' === $set_by && ! $premium ) {
			return 'Setting your own target is part of Premium.';
		}
		return null;
	}

	/**
	 * Below the safe minimum is not saved (FR3-28 AS-02). The wording is the calculator's approved note.
	 *
	 * @param float $kcal    Target the member asked for.
	 * @param float $floor   Safe minimum for this member.
	 * @param bool  $premium Whether a member-set target is allowed.
	 * @return array{saved:bool,kcal?:float,message?:string}
	 */
	public static function own_target( $kcal, $floor, $premium ) {
		$denied = self::gate_member_target( 'member', $premium );
		if ( null !== $denied ) {
			return array(
				'saved'   => false,
				'message' => $denied,
			);
		}
		if ( (float) $kcal < (float) $floor ) {
			$message = class_exists( 'LLD_Members_Calorie_Target' ) ? LLD_Members_Calorie_Target::MESSAGES['floor_note'] : 'Below the safe minimum.';
			return array(
				'saved'   => false,
				'message' => $message,
			);
		}
		return array(
			'saved' => true,
			'kcal'  => (float) $kcal,
		);
	}

	/**
	 * The row written when the calorie calculator is completed (FR3-28 AS-01, AS-03).
	 * Protein is the Daily Protein Target result passed in, not a figure derived here.
	 *
	 * @param int|float  $kcal       Calculator result.
	 * @param float|null $protein_g  Daily Protein Target, or null when it must not be shown.
	 * @param string     $valid_from First day the target applies.
	 * @return array<string,mixed>
	 */
	public static function target_from_result( $kcal, $protein_g, $valid_from ) {
		if ( ! class_exists( 'LLD_Members_Calorie_Target' ) || ! is_numeric( $kcal ) ) {
			return array( 'error' => 'The calorie calculator is not available.' );
		}
		$macros = LLD_Members_Calorie_Target::macros( (int) $kcal, $protein_g );
		return array(
			'kcal'       => (int) $kcal,
			'protein_g'  => $macros['proteinG'],
			'carb_g'     => $macros['carbG'],
			'fat_g'      => $macros['fatG'],
			'fibre_g'    => $macros['fibreG'],
			'set_by'     => 'calculator',
			'valid_from' => $valid_from,
		);
	}

	/**
	 * Nutrients for an amount, from per-100 g values. Null in, null out: a missing nutrient is not stored as zero.
	 *
	 * @param array<string,mixed> $per   Per 100 g. Keys kcal_100g, protein_100g, carb_100g, fat_100g, fibre_100g.
	 * @param float               $grams Amount eaten.
	 * @return array<string,float|null>|null
	 */
	public static function from_per_100( array $per, $grams ) {
		$grams = round( (float) $grams, 1 );
		if ( $grams < 0.1 || $grams > 5000 ) {
			return null;
		}
		$factor = $grams / 100;
		return array(
			'grams'     => $grams,
			'kcal'      => self::scale_number( $per['kcal_100g'] ?? null, $factor, 1 ),
			'protein_g' => self::scale_number( $per['protein_100g'] ?? null, $factor, 2 ),
			'carb_g'    => self::scale_number( $per['carb_100g'] ?? null, $factor, 2 ),
			'fat_g'     => self::scale_number( $per['fat_100g'] ?? null, $factor, 2 ),
			'fibre_g'   => self::scale_number( $per['fibre_100g'] ?? null, $factor, 2 ),
		);
	}

	/**
	 * Nutrients for an amount of a custom food. Its values are for one portion, not per 100 g.
	 *
	 * @param array<string,mixed> $portion Custom food row.
	 * @param float               $grams   Amount eaten.
	 * @return array<string,float|null>|null
	 */
	public static function from_portion( array $portion, $grams ) {
		$grams   = round( (float) $grams, 1 );
		$portion_g = round( (float) ( $portion['portion_g'] ?? 0 ), 1 );
		if ( $grams < 0.1 || $grams > 5000 || $portion_g < 0.1 ) {
			return null;
		}
		$factor = $grams / $portion_g;
		return array(
			'grams'     => $grams,
			'kcal'      => self::scale_number( $portion['kcal'] ?? null, $factor, 1 ),
			'protein_g' => self::scale_number( $portion['protein_g'] ?? null, $factor, 2 ),
			'carb_g'    => self::scale_number( $portion['carb_g'] ?? null, $factor, 2 ),
			'fat_g'     => self::scale_number( $portion['fat_g'] ?? null, $factor, 2 ),
			'fibre_g'   => self::scale_number( $portion['fibre_g'] ?? null, $factor, 2 ),
		);
	}

	/**
	 * Replace whatever calories the request carried with the values worked out from the food.
	 *
	 * @param array<string,mixed> $basis per_100g or per_portion.
	 * @param array<string,mixed> $entry Validated log fields.
	 * @return array<string,mixed>
	 */
	public static function apply_basis( array $basis, array $entry ) {
		if ( isset( $basis['per_100g'] ) ) {
			$nuts = self::from_per_100( $basis['per_100g'], $entry['grams'] ?? 0 );
		} else {
			$nuts = self::from_portion( $basis['per_portion'] ?? array(), $entry['grams'] ?? 0 );
		}
		if ( null === $nuts ) {
			return array( 'error' => 'Check the value of grams.' );
		}
		$out = array(
			'entry_date' => $entry['entry_date'],
			'meal'       => $entry['meal'],
			'food_id'    => (int) $entry['food_id'],
			'food_kind'  => $entry['food_kind'],
			'grams'      => $nuts['grams'],
			'source'     => isset( $entry['source'] ) && '' !== $entry['source'] ? $entry['source'] : 'search',
		);
		if ( isset( $entry['portion_label'] ) && null !== $entry['portion_label'] && '' !== $entry['portion_label'] ) {
			$out['portion_label'] = $entry['portion_label'];
		}
		foreach ( self::NUTRIENTS as $key ) {
			$out[ $key ] = $nuts[ $key ];
		}
		return $out;
	}

	/**
	 * Look up the food for this member and store the nutrients for the amount eaten.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $entry     Validated log fields. Any calories in it are ignored.
	 * @return array<string,mixed>
	 */
	public static function for_member( $member_id, array $entry ) {
		$kind = isset( $entry['food_kind'] ) ? (string) $entry['food_kind'] : '';
		$basis = self::basis( (int) $member_id, $kind, (int) ( $entry['food_id'] ?? 0 ) );
		if ( null === $basis ) {
			return array( 'error' => 'That food is not available.' );
		}
		$out = self::apply_basis( $basis, $entry );
		if ( 'recipe' === $kind && ! isset( $out['error'] ) ) {
			$out['source'] = isset( $entry['source'] ) && '' !== (string) $entry['source'] ? (string) $entry['source'] : 'recipe';
			if ( ! isset( $out['portion_label'] ) || '' === (string) $out['portion_label'] ) {
				$out['portion_label'] = LLD_Members_Food_Log::format_amount( $out['grams'] ) . ' serving' . ( abs( (float) $out['grams'] - 1 ) < 0.001 ? '' : 's' );
			}
		}
		return $out;
	}

	/**
	 * New portion: scale the calories already stored on the entry. The food table is not read again.
	 *
	 * @param array<string,mixed> $entry Saved entry.
	 * @param float               $grams New amount.
	 * @return array<string,mixed>|null
	 */
	public static function scale( array $entry, $grams ) {
		$grams = round( (float) $grams, 1 );
		$old   = round( (float) ( $entry['grams'] ?? 0 ), 1 );
		if ( $grams < 0.1 || $grams > 5000 || $old < 0.1 ) {
			return null;
		}
		$factor = $grams / $old;
		$out    = $entry;
		$out['grams'] = $grams;
		foreach ( self::NUTRIENTS as $key ) {
			$places      = 'kcal' === $key ? 1 : 2;
			$out[ $key ] = self::scale_number( $entry[ $key ] ?? null, $factor, $places );
		}
		return $out;
	}

	/**
	 * One day, grouped by meal, with totals, what is left, and the amount over.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries, including ones from other days. Deleted rows are skipped.
	 * @param string                          $date    Y-m-d.
	 * @param array<string,mixed>|null        $target  Target that applied that day, or null.
	 * @return array<string,mixed>
	 */
	public static function day( array $entries, $date, $target = null ) {
		$meals = array();
		foreach ( self::MEALS as $meal ) {
			$meals[ $meal ] = array();
		}
		$sums    = array_fill_keys( self::NUTRIENTS, 0.0 );
		$missing = array_fill_keys( self::NUTRIENTS, false );
		$seen    = array_fill_keys( self::NUTRIENTS, false );
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['deleted_at'] ) ) {
				continue;
			}
			if ( ( $entry['entry_date'] ?? '' ) !== $date ) {
				continue;
			}
			$meal = $entry['meal'] ?? '';
			if ( ! isset( $meals[ $meal ] ) ) {
				continue;
			}
			$meals[ $meal ][] = $entry;
			foreach ( self::NUTRIENTS as $key ) {
				if ( ! isset( $entry[ $key ] ) || ! is_numeric( $entry[ $key ] ) ) {
					$missing[ $key ] = true;
					continue;
				}
				$sums[ $key ] += (float) $entry[ $key ];
				$seen[ $key ]  = true;
			}
		}
		$totals = array();
		foreach ( self::NUTRIENTS as $key ) {
			if ( ! $seen[ $key ] ) {
				$totals[ $key ] = self::round_nutrient( 0, $key );
			} elseif ( $missing[ $key ] ) {
				$totals[ $key ] = null;
			} else {
				$totals[ $key ] = self::round_nutrient( $sums[ $key ], $key );
			}
		}
		$left = array();
		$over = array();
		if ( is_array( $target ) ) {
			foreach ( self::NUTRIENTS as $key ) {
				if ( ! isset( $target[ $key ] ) || ! is_numeric( $target[ $key ] ) || ! is_numeric( $totals[ $key ] ) ) {
					continue;
				}
				$diff = self::round_nutrient( (float) $target[ $key ] - (float) $totals[ $key ], $key );
				if ( $diff >= 0 ) {
					$left[ $key ] = $diff;
				} else {
					$left[ $key ] = self::round_nutrient( 0, $key );
					$over[ $key ] = self::round_nutrient( -1 * $diff, $key );
				}
			}
		}
		$note = isset( $over['kcal'] ) && $over['kcal'] > 0 ? 'Over by ' . self::format_amount( $over['kcal'] ) . ' kcal today.' : null;
		return array(
			'date'      => $date,
			'locked'    => false,
			'meals'     => $meals,
			'totals'    => $totals,
			'target'    => $target,
			'left'      => $left,
			'over'      => $over,
			'over_note' => $note,
		);
	}

	/**
	 * The target that applied on a day: the latest valid_from on or before that day.
	 *
	 * @param array<int,array<string,mixed>> $targets Saved targets.
	 * @param string                          $date    Y-m-d.
	 * @return array<string,mixed>|null
	 */
	public static function target_for_day( array $targets, $date ) {
		$best = null;
		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}
			$from = (string) ( $target['valid_from'] ?? '' );
			if ( '' === $from || $from > $date ) {
				continue;
			}
			if ( null === $best || $from > (string) $best['valid_from'] || ( $from === (string) $best['valid_from'] && (int) ( $target['id'] ?? 0 ) > (int) ( $best['id'] ?? 0 ) ) ) {
				$best = $target;
			}
		}
		return $best;
	}

	/**
	 * Free members see today and the 13 days before it. Premium sees every stored day.
	 *
	 * @param string $date    Entry date.
	 * @param string $today   Today's date.
	 * @param bool   $premium Premium access.
	 */
	public static function history_open( $date, $today, $premium ) {
		if ( $premium ) {
			return true;
		}
		try {
			$zone   = new DateTimeZone( 'UTC' );
			$today_d = new DateTimeImmutable( $today . ' 00:00:00', $zone );
			$date_d  = new DateTimeImmutable( $date . ' 00:00:00', $zone );
		} catch ( Exception $e ) {
			unset( $e );
			return false;
		}
		return $date_d >= $today_d->modify( '-13 days' );
	}

	/**
	 * Drop diary rows a free member does not see on screen. The rows stay stored.
	 *
	 * @param array<int,array<string,mixed>> $rows    Log rows.
	 * @param bool                            $premium Premium access.
	 * @param string                          $today   Today's date.
	 * @return array<int,array<string,mixed>>
	 */
	public static function limit_history( array $rows, $premium, $today ) {
		if ( $premium ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$date = substr( (string) ( $row['entry_date'] ?? '' ), 0, 10 );
			if ( self::history_open( $date, $today, false ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * A day a free member cannot open.
	 *
	 * @param string $date Y-m-d.
	 * @return array<string,mixed>
	 */
	public static function locked_day( $date ) {
		$meals = array();
		foreach ( self::MEALS as $meal ) {
			$meals[ $meal ] = array();
		}
		return array(
			'date'      => $date,
			'locked'    => true,
			'message'   => 'The last 14 days are included. Earlier days are part of Premium.',
			'meals'     => $meals,
			'totals'    => array_fill_keys( self::NUTRIENTS, null ),
			'target'    => null,
			'left'      => array(),
			'over'      => array(),
			'over_note' => null,
		);
	}

	/**
	 * One Stage 2 diary meal as the food tracker shows it: read-only, with no nutrition numbers.
	 *
	 * @param array<string,mixed> $row         Stage 2 diary_meal row. It is not changed.
	 * @param string              $description Decrypted description, or ''.
	 * @param string|null         $photo_url   Link to the member's own photo, or null.
	 * @return array<string,mixed>
	 */
	public static function earlier_entry( array $row, $description, $photo_url ) {
		$meal = (string) ( $row['meal'] ?? '' );
		if ( 'snack' === $meal || ! in_array( $meal, self::MEALS, true ) ) {
			$meal = 'snacks';
		}
		$checks = array();
		foreach ( array( 'vegetables', 'protein_source', 'sugary_drink' ) as $check ) {
			$checks[ $check ] = isset( $row[ $check ] ) && is_numeric( $row[ $check ] ) ? 1 === (int) $row[ $check ] : null;
		}
		$portion = (string) ( $row['portion'] ?? '' );
		return array(
			'kind'           => 'earlier',
			'id'             => (int) ( $row['id'] ?? 0 ),
			'label'          => self::EARLIER_LABEL,
			'nutrition'      => 'unknown',
			'read_only'      => true,
			'entry_date'     => substr( (string) ( $row['local_date'] ?? '' ), 0, 10 ),
			'meal'           => $meal,
			'entry_type'     => (string) ( $row['entry_type'] ?? '' ),
			'planned'        => 'planned' === (string) ( $row['entry_type'] ?? '' ),
			'recipe_version' => '' !== (string) ( $row['recipe_version'] ?? '' ) ? (string) $row['recipe_version'] : null,
			'description'    => (string) $description,
			'portion'        => '' !== $portion ? $portion : null,
			'photo'          => $photo_url,
			'checks'         => $checks,
			'recorded_at'    => (string) ( $row['recorded_at'] ?? '' ),
		);
	}

	/**
	 * A Stage 2 day row (water, hunger, energy), or null when nothing was logged.
	 *
	 * @param array<string,mixed>|null $row Stage 2 diary_day row.
	 * @return array<string,int|null>|null
	 */
	public static function earlier_extras( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'water_glasses', 'hunger', 'energy' ) as $field ) {
			$out[ $field ] = isset( $row[ $field ] ) && is_numeric( $row[ $field ] ) ? (int) $row[ $field ] : null;
		}
		return $out;
	}

	/**
	 * Add earlier diary entries to a day, grouped by meal. They sit beside the new entries,
	 * never inside them, so totals, what is left and the amount over stay as they were.
	 *
	 * @param array<string,mixed>            $day     Result of day().
	 * @param array<int,array<string,mixed>> $earlier Results of earlier_entry().
	 * @param array<string,int|null>|null    $extras  Result of earlier_extras().
	 * @return array<string,mixed>
	 */
	public static function with_earlier( array $day, array $earlier, $extras ) {
		$grouped = array();
		foreach ( self::MEALS as $meal ) {
			$grouped[ $meal ] = array();
		}
		$seen = array();
		foreach ( $earlier as $entry ) {
			if ( ! is_array( $entry ) || ( $entry['entry_date'] ?? '' ) !== ( $day['date'] ?? '' ) ) {
				continue;
			}
			$id = (int) ( $entry['id'] ?? 0 );
			if ( $id > 0 && isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ]                          = true;
			$grouped[ (string) $entry['meal'] ][] = $entry;
		}
		$day['earlier']       = $grouped;
		$day['earlier_day']   = $extras;
		$day['earlier_label'] = self::EARLIER_LABEL;
		return $day;
	}

	/**
	 * Read this member's Stage 2 diary for one day. Read only: nothing is written back.
	 *
	 * @param int    $member_id Member number.
	 * @param string $date      Y-m-d.
	 * @return array{meals: array<int,array<string,mixed>>, day: array<string,int|null>|null}
	 */
	private static function earlier_stored( $member_id, $date ) {
		global $wpdb;
		$none = array(
			'meals' => array(),
			'day'   => null,
		);
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Schema' ) ) {
			return $none;
		}
		$meals = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d AND local_date = %s ORDER BY id ASC', $member_id, $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$day   = $wpdb->get_row( $wpdb->prepare( 'SELECT water_glasses, hunger, energy FROM ' . LLD_Members_Schema::table( 'diary_day' ) . ' WHERE member_id = %d AND local_date = %s', $member_id, $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$out   = array();
		foreach ( is_array( $meals ) ? $meals : array() as $row ) {
			$text = '';
			if ( is_string( $row['description_ciphertext'] ?? null ) && '' !== $row['description_ciphertext'] && class_exists( 'LLD_Members_Crypto' ) ) {
				try {
					$text = LLD_Members_Crypto::decrypt( $row['description_ciphertext'], 'diary_meal:' . $row['id'] );
				} catch ( RuntimeException $e ) {
					unset( $e );
					$text = '';
				}
			}
			$photo = is_string( $row['photo_path'] ?? null ) && '' !== $row['photo_path'] ? home_url( '/members/diary-photo/' . (int) $member_id . '/?meal=' . (int) $row['id'] ) : null;
			$out[] = self::earlier_entry( $row, $text, $photo );
		}
		return array(
			'meals' => $out,
			'day'   => self::earlier_extras( is_array( $day ) ? $day : null ),
		);
	}

	/**
	 * Foods this member can log: the shared catalogue, then their own custom foods.
	 * Another member's custom food is never included. A deleted custom food is not included.
	 *
	 * @param array<int,array<string,mixed>> $catalogue Shared foods.
	 * @param array<int,array<string,mixed>> $custom    Custom foods for every member.
	 * @param int                            $member_id The member who is searching.
	 * @param string                         $query     Search text.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( array $catalogue, array $custom, $member_id, $query ) {
		$query = strtolower( trim( $query ) );
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$out = array();
		foreach ( $catalogue as $food ) {
			if ( ! is_array( $food ) || ! self::name_hit( (string) ( $food['name'] ?? '' ), $query ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (int) ( $food['id'] ?? 0 ),
				'food_kind' => 'food',
				'name'      => (string) $food['name'],
			);
		}
		foreach ( $custom as $food ) {
			if ( ! is_array( $food ) || (int) ( $food['member_id'] ?? 0 ) !== (int) $member_id || ! empty( $food['deleted_at'] ) ) {
				continue;
			}
			if ( ! self::name_hit( (string) ( $food['name'] ?? '' ), $query ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (int) ( $food['id'] ?? 0 ),
				'food_kind' => 'custom',
				'name'      => (string) $food['name'],
				'member_id' => (int) $member_id,
			);
		}
		return $out;
	}

	/**
	 * Recently logged foods, newest first, one line per food.
	 *
	 * @param array<int,array<string,mixed>> $entries Log rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( array $entries ) {
		usort(
			$entries,
			static function ( $a, $b ) {
				$by_time = strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
				return 0 !== $by_time ? $by_time : ( (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
			}
		);
		$seen = array();
		$out  = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['deleted_at'] ) ) {
				continue;
			}
			$key = ( $entry['food_kind'] ?? 'food' ) . ':' . (int) ( $entry['food_id'] ?? 0 );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'food_id'   => (int) ( $entry['food_id'] ?? 0 ),
				'food_kind' => $entry['food_kind'] ?? 'food',
				'name'      => (string) ( $entry['name'] ?? '' ),
				'grams'     => $entry['grams'] ?? null,
				'logged_at' => (string) ( $entry['created_at'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * One tap logs the favourite again, using the amount and calories from the last time it was logged.
	 *
	 * @param array<string,mixed>            $favourite Favourite row.
	 * @param array<int,array<string,mixed>> $entries   This member's log.
	 * @param string                          $date      Day to add it to.
	 * @param string                          $meal      Meal to add it to.
	 * @return array<string,mixed>|null
	 */
	public static function log_favourite( array $favourite, array $entries, $date, $meal ) {
		$kind = isset( $favourite['food_kind'] ) ? (string) $favourite['food_kind'] : 'food';
		$id   = (int) ( $favourite['food_id'] ?? 0 );
		$best = null;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['deleted_at'] ) ) {
				continue;
			}
			if ( (int) ( $entry['food_id'] ?? 0 ) !== $id || (string) ( $entry['food_kind'] ?? 'food' ) !== $kind ) {
				continue;
			}
			if ( null === $best || strcmp( (string) ( $entry['created_at'] ?? '' ), (string) ( $best['created_at'] ?? '' ) ) > 0 ) {
				$best = $entry;
			}
		}
		if ( null === $best ) {
			return null;
		}
		unset( $best['id'], $best['deleted_at'], $best['created_at'], $best['updated_at'], $best['member_id'] );
		$best['entry_date'] = $date;
		$best['meal']       = $meal;
		$best['source']     = 'favourite';
		return $best;
	}

	/**
	 * Copy a meal, or a whole day when $meal is null. Nutrition is copied as stored. Premium only.
	 *
	 * @param array<int,array<string,mixed>> $entries Log rows.
	 * @param string                          $from    Source day.
	 * @param string                          $to      Destination day.
	 * @param string|null                     $meal    One meal, or null for the whole day.
	 * @param bool                            $premium Premium access.
	 * @return array{entries?:array<int,array<string,mixed>>,error?:string}
	 */
	public static function copied( array $entries, $from, $to, $meal, $premium ) {
		if ( ! $premium ) {
			return array( 'error' => 'Copying a meal or a day is part of Premium.' );
		}
		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['deleted_at'] ) ) {
				continue;
			}
			if ( ( $entry['entry_date'] ?? '' ) !== $from ) {
				continue;
			}
			if ( null !== $meal && '' !== $meal && ( $entry['meal'] ?? '' ) !== $meal ) {
				continue;
			}
			unset( $entry['id'], $entry['deleted_at'], $entry['created_at'], $entry['updated_at'], $entry['member_id'] );
			$entry['entry_date'] = $to;
			$entry['source']     = 'copy';
			$out[]               = $entry;
		}
		return array( 'entries' => $out );
	}

	/**
	 * Read a computed food-tracker view: a day, search, or recent foods.
	 *
	 * @param string               $which     day, search or recent.
	 * @param int                  $member_id Member number.
	 * @param array<string,string> $query     date and q.
	 * @return array<string,mixed>
	 */
	public static function view( $which, $member_id, array $query ) {
		if ( 'day' === $which ) {
			$date = $query['date'] ?? '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return array( 'error' => 'Add a date.' );
			}
			$premium = self::premium( $member_id );
			$today   = gmdate( 'Y-m-d' );
			if ( ! self::history_open( $date, $today, $premium ) ) {
				return self::locked_day( $date );
			}
			$rows    = self::named( $member_id, self::rows_for( 'food_log', $member_id ), $date );
			$targets = self::rows_for( 'nutrition_targets', $member_id );
			$day     = self::day( $rows, $date, self::target_for_day( $targets, $date ) );
			$earlier = self::earlier_stored( $member_id, $date );
			return self::with_earlier( $day, $earlier['meals'], $earlier['day'] );
		}
		if ( 'search' === $which ) {
			return array( 'foods' => self::search_member( $member_id, $query['q'] ?? '' ) );
		}
		if ( 'recent' === $which ) {
			$rows = self::named( $member_id, self::limit_history( self::rows_for( 'food_log', $member_id ), self::premium( $member_id ), gmdate( 'Y-m-d' ) ) );
			return array( 'foods' => self::recent( $rows ) );
		}
		return array( 'error' => 'Not available.' );
	}

	/**
	 * Each log row with the name of its food, so a day and the Recent list can be read (P3-36 B1). The log stores
	 * the food's number, not its name: the name is looked up in the food table, the member's own foods (a deleted
	 * one keeps its name on past entries, FR3-30 AS-03) or the member's recipes. Nothing is stored.
	 *
	 * @param int                            $member_id Member number.
	 * @param array<int,array<string,mixed>> $rows      Log rows.
	 * @param string|null                    $date      Only name the rows of this day (the others are returned as they are).
	 * @return array<int,array<string,mixed>>
	 */
	public static function named( $member_id, array $rows, $date = null ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return $rows;
		}
		$ids = array( 'food' => array(), 'custom' => array(), 'recipe' => array() );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) || ( null !== $date && ( $row['entry_date'] ?? '' ) !== $date ) ) {
				continue;
			}
			$kind = (string) ( $row['food_kind'] ?? 'food' );
			$id   = (int) ( $row['food_id'] ?? 0 );
			if ( isset( $ids[ $kind ] ) && $id > 0 ) {
				$ids[ $kind ][ $id ] = $id;
			}
		}
		$names  = array();
		$tables = array( 'food' => 'foods', 'custom' => 'custom_foods', 'recipe' => 'recipes' );
		foreach ( $ids as $kind => $list ) {
			if ( ! $list ) {
				continue;
			}
			$table = LLD_Members_Stage3_Schema::table( $tables[ $kind ] );
			$in    = implode( ',', array_map( 'intval', array_slice( array_values( $list ), 0, 500 ) ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is internal; the ids are integers.
			$found = 'food' === $kind
				? $wpdb->get_results( "SELECT id, name FROM {$table} WHERE id IN ({$in})", ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$table} WHERE member_id = %d AND id IN ({$in})", (int) $member_id ), ARRAY_A );
			// phpcs:enable
			foreach ( is_array( $found ) ? $found : array() as $hit ) {
				$names[ $kind . ':' . (int) $hit['id'] ] = (string) $hit['name'];
			}
		}
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = (string) ( $row['food_kind'] ?? 'food' ) . ':' . (int) ( $row['food_id'] ?? 0 );
			if ( isset( $names[ $key ] ) ) {
				$rows[ $i ]['name'] = $names[ $key ];
			}
		}
		return $rows;
	}

	/**
	 * Copy stored entries onto another day and save the copies.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $request   from_date, to_date, optional meal.
	 * @return array<string,mixed>
	 */
	public static function copy_into( $member_id, array $request ) {
		$result = self::copied(
			self::rows_for( 'food_log', $member_id ),
			(string) ( $request['from_date'] ?? '' ),
			(string) ( $request['to_date'] ?? '' ),
			isset( $request['meal'] ) ? (string) $request['meal'] : null,
			self::premium( $member_id )
		);
		if ( isset( $result['error'] ) ) {
			$result['status'] = 403;
			return $result;
		}
		$saved = array();
		foreach ( $result['entries'] as $entry ) {
			$id = LLD_Members_Stage3_Data::insert( 'food_log', $member_id, $entry, array() );
			if ( $id > 0 ) {
				$row = LLD_Members_Stage3_Data::row( 'food_log', $member_id, $id, array() );
				if ( is_array( $row ) ) {
					$saved[] = $row;
				}
			}
		}
		return array(
			'copied'  => count( $saved ),
			'entries' => $saved,
			'day'     => self::day( $saved, (string) $request['to_date'], null ),
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $query     Search text.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search_member( $member_id, $query ) {
		$query = trim( $query );
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$foods  = array();
		$loader = __DIR__ . '/food-import/load.php';
		if ( is_readable( $loader ) ) {
			require_once $loader;
		}
		if ( class_exists( 'LLD_Members_Food_Wp_Store' ) ) {
			$found = ( new LLD_Members_Food_Wp_Store() )->search( $query );
			foreach ( isset( $found['foods'] ) && is_array( $found['foods'] ) ? $found['foods'] : array() as $hit ) {
				$hit['food_kind'] = 'food';
				$foods[]          = $hit;
			}
		}
		return array_merge( $foods, self::custom_hits( $member_id, $query ) );
	}

	/**
	 * @param float|int $value  Amount.
	 * @param string    $key    Nutrient key.
	 * @return float
	 */
	private static function round_nutrient( $value, $key ) {
		return round( (float) $value, 'kcal' === $key ? 1 : 2 );
	}

	/**
	 * Whole numbers without a decimal, so "100" stays "100".
	 *
	 * @param float|int $value Amount.
	 */
	public static function format_amount( $value ) {
		$rounded = round( (float) $value, 1 );
		if ( abs( $rounded - round( $rounded ) ) < 0.001 ) {
			return (string) (int) round( $rounded );
		}
		return number_format( $rounded, 1, '.', '' );
	}

	/**
	 * @param mixed $value  Number or empty.
	 * @param float $factor Multiplier.
	 * @param int   $places Decimal places.
	 * @return float|null
	 */
	private static function scale_number( $value, $factor, $places ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		return round( (float) $value * (float) $factor, $places );
	}

	/**
	 * @param string $name  Food name.
	 * @param string $query Lowercased query of at least two characters.
	 */
	private static function name_hit( $name, $query ) {
		return '' !== $query && str_contains( strtolower( $name ), $query );
	}

	/**
	 * @param string $suffix    Stage 3 table suffix.
	 * @param int    $member_id Member number.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows_for( $suffix, $member_id ) {
		if ( ! class_exists( 'LLD_Members_Stage3_Data' ) ) {
			return array();
		}
		return LLD_Members_Stage3_Data::rows( $suffix, (int) $member_id, array(), '', 2000 );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $kind      food or custom.
	 * @param int    $id        Food id.
	 * @return array<string,array<string,mixed>>|null
	 */
	private static function basis( $member_id, $kind, $id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || $id < 1 || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return null;
		}
		if ( 'food' === $kind ) {
			$table = LLD_Members_Stage3_Schema::table( 'foods' );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, kcal_100g, protein_100g, carb_100g, fat_100g, fibre_100g FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			return is_array( $row ) ? array( 'per_100g' => $row ) : null;
		}
		if ( 'custom' === $kind ) {
			$table = LLD_Members_Stage3_Schema::table( 'custom_foods' );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, portion_g, kcal, protein_g, carb_g, fat_g, fibre_g FROM {$table} WHERE id = %d AND member_id = %d AND deleted_at IS NULL", $id, $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			return is_array( $row ) ? array( 'per_portion' => $row ) : null;
		}
		if ( 'recipe' === $kind ) {
			$table = LLD_Members_Stage3_Schema::table( 'recipes' );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, servings, kcal_per_serving, protein_per_serving, carb_per_serving, fat_per_serving, fibre_per_serving, deleted_at FROM {$table} WHERE id = %d AND member_id = %d", $id, $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			if ( ! is_array( $row ) || ! empty( $row['deleted_at'] ) ) {
				return null;
			}
			return class_exists( 'LLD_Members_Recipes' ) ? LLD_Members_Recipes::serving_basis( $row, 1 ) : null;
		}
		return null;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $query     Search text.
	 * @return array<int,array<string,mixed>>
	 */
	private static function custom_hits( $member_id, $query ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return array();
		}
		$table = LLD_Members_Stage3_Schema::table( 'custom_foods' );
		$like  = '%' . $wpdb->esc_like( $query ) . '%';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, portion_g, kcal, protein_g, carb_g, fat_g, fibre_g FROM {$table} WHERE member_id = %d AND deleted_at IS NULL AND name LIKE %s ORDER BY name ASC LIMIT 20", $member_id, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'        => (int) $row['id'],
				'food_kind' => 'custom',
				'name'      => (string) $row['name'],
				'portion_g' => isset( $row['portion_g'] ) ? (float) $row['portion_g'] : null,
				'kcal'      => isset( $row['kcal'] ) && is_numeric( $row['kcal'] ) ? (float) $row['kcal'] : null,
				'member_id' => (int) $member_id,
			);
		}
		return $out;
	}
}
