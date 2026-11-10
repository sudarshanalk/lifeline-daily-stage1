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
			$rows    = self::rows_for( 'food_log', $member_id );
			$targets = self::rows_for( 'nutrition_targets', $member_id );
			$day     = self::day( $rows, $date, self::target_for_day( $targets, $date ) );
			$earlier = self::earlier_stored( $member_id, $date );
			return self::with_earlier( $day, $earlier['meals'], $earlier['day'] );
		}
		if ( 'search' === $which ) {
			return array( 'foods' => self::search_member( $member_id, $query['q'] ?? '' ) );
		}
		if ( 'drinks' === $which ) {
			return array( 'drinks' => self::drinks_for( $member_id ) );
		}
		if ( 'repeat' === $which ) {
			// The member's own local date by default (the meal repeated is "today's" for them).
			$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $query['date'] ?? '' ) ) ? (string) $query['date'] : self::local_today( $member_id );
			$meal = self::premium( $member_id ) ? self::repeat_meal( self::rows_for( 'food_log', $member_id ), $date ) : null;
			if ( $meal && self::hidden( $member_id )['all'] ) {
				$meal['kcal']             = null; // FR3-69: no calorie number for a member the matrix covers
				$meal['nutrition_hidden'] = true;
			}
			return array( 'meal' => $meal );
		}
		if ( 'recent' === $which ) {
			$rows = self::limit_history( self::rows_for( 'food_log', $member_id ), self::premium( $member_id ), gmdate( 'Y-m-d' ) );
			return array( 'foods' => self::recent( $rows ) );
		}
		return array( 'error' => 'Not available.' );
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

	// ---- Healthy Weight: drinks and the repeat meal (P3-35; FR3-110 AS-02, AS-03; FR3-102 AS-06) --------------------

	/**
	 * The food tracker's drinks in standard sizes, with mixers as separate items (R-HW-ALC-02). Sizes from the signed
	 * rule and the P3-35 method sheet (section 5.1); the calories come from the food database's own entry for the drink,
	 * found by its search words, so no figure is written here. A pint is the 568 ml pint the method sheet cites.
	 */
	const DRINKS = array(
		'wine_125'     => array( 'label' => 'Glass of wine, 125 ml', 'ml' => 125, 'kind' => 'drink', 'search' => 'wine' ),
		'wine_175'     => array( 'label' => 'Glass of wine, 175 ml', 'ml' => 175, 'kind' => 'drink', 'search' => 'wine' ),
		'beer_bottle'  => array( 'label' => 'Bottle of beer, 330 ml', 'ml' => 330, 'kind' => 'drink', 'search' => 'beer' ),
		'beer_pint'    => array( 'label' => 'Pint of beer, 568 ml', 'ml' => 568, 'kind' => 'drink', 'search' => 'beer' ),
		'cider_bottle' => array( 'label' => 'Bottle of cider, 330 ml', 'ml' => 330, 'kind' => 'drink', 'search' => 'cider' ),
		'cider_pint'   => array( 'label' => 'Pint of cider, 568 ml', 'ml' => 568, 'kind' => 'drink', 'search' => 'cider' ),
		'spirit'       => array( 'label' => 'Single spirit, 25 ml', 'ml' => 25, 'kind' => 'drink', 'search' => 'spirits' ),
		'tonic'        => array( 'label' => 'Tonic water, 150 ml', 'ml' => 150, 'kind' => 'mixer', 'search' => 'tonic water' ),
		'cola'         => array( 'label' => 'Cola, 150 ml', 'ml' => 150, 'kind' => 'mixer', 'search' => 'cola' ),
		'lemonade'     => array( 'label' => 'Lemonade, 150 ml', 'ml' => 150, 'kind' => 'mixer', 'search' => 'lemonade' ),
		'soda_water'   => array( 'label' => 'Soda water, 150 ml', 'ml' => 150, 'kind' => 'mixer', 'search' => 'soda water' ),
	);

	/** Food-log source of a drink logged from the drinks list (hides the evening shortcut for the day). */
	const DRINK_SOURCE = 'drink';

	/**
	 * The drinks list from the foods found for each search word (pure): {key, label, ml, kind, food_id, kcal}. A size
	 * whose drink is not in the food database is left out, never given a made-up figure.
	 *
	 * @param array<string,array<string,mixed>> $foods search word => food row with kcal_100g … fibre_100g and id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function drinks_menu( array $foods ) {
		$out = array();
		foreach ( self::DRINKS as $key => $d ) {
			$food = isset( $foods[ $d['search'] ] ) ? $foods[ $d['search'] ] : null;
			if ( ! is_array( $food ) || ! isset( $food['kcal_100g'] ) || ! is_numeric( $food['kcal_100g'] ) ) {
				continue;
			}
			$n     = self::from_per_100( $food, $d['ml'] ); // 1 ml of a drink taken as 1 g, as the food database lists drinks
			$out[] = array(
				'key'     => $key,
				'label'   => $d['label'],
				'ml'      => $d['ml'],
				'kind'    => $d['kind'],
				'food_id' => (int) ( $food['id'] ?? 0 ),
				'kcal'    => $n['kcal'],
			);
		}
		return $out;
	}

	/**
	 * A food-log entry for one drink of a standard size (pure). It counts against the day's target like any food
	 * (FR3-110 AS-02).
	 *
	 * @param array<string,mixed> $food Food row (per 100 g, with id).
	 * @param string              $size A DRINKS key.
	 * @param string              $date Local date.
	 * @return array<string,mixed>|null
	 */
	public static function drink_entry( array $food, $size, $date ) {
		if ( ! isset( self::DRINKS[ $size ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return null;
		}
		$d = self::DRINKS[ $size ];
		$n = self::from_per_100( $food, $d['ml'] );
		return array(
			'entry_date'    => (string) $date,
			'meal'          => 'snacks',
			'food_id'       => (int) ( $food['id'] ?? 0 ),
			'food_kind'     => 'food',
			'grams'         => (float) $d['ml'],
			'portion_label' => $d['label'],
			'kcal'          => $n['kcal'],
			'protein_g'     => $n['protein_g'],
			'carb_g'        => $n['carb_g'],
			'fat_g'         => $n['fat_g'],
			'fibre_g'       => $n['fibre_g'],
			'source'        => self::DRINK_SOURCE,
		);
	}

	/**
	 * Whether an alcoholic drink was logged on a day (R-HW-ALC-02 hides the shortcut then): one from the drinks list,
	 * or any food whose database entry has alcohol, however it was logged.
	 *
	 * @param array<int,array<string,mixed>> $entries   Log rows.
	 * @param string                          $date      Local date.
	 * @param int[]                           $alcoholic Food ids with alcohol (alcoholic_food_ids()).
	 */
	public static function drink_logged( array $entries, $date, array $alcoholic = array() ) {
		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) || ! empty( $e['deleted_at'] ) || (string) ( $e['entry_date'] ?? '' ) !== (string) $date ) {
				continue;
			}
			if ( self::DRINK_SOURCE === (string) ( $e['source'] ?? '' ) ) {
				return true;
			}
			if ( 'food' === (string) ( $e['food_kind'] ?? 'food' ) && in_array( (int) ( $e['food_id'] ?? 0 ), array_map( 'intval', $alcoholic ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The food ids logged on a day whose food database entry has alcohol (alcohol_100g above 0).
	 *
	 * @param array<int,array<string,mixed>> $entries Log rows.
	 * @param string                          $date    Local date.
	 * @return int[]
	 */
	public static function alcoholic_food_ids( array $entries, $date ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return array();
		}
		$ids = array();
		foreach ( $entries as $e ) {
			if ( is_array( $e ) && empty( $e['deleted_at'] ) && (string) ( $e['entry_date'] ?? '' ) === (string) $date && 'food' === (string) ( $e['food_kind'] ?? 'food' ) && (int) ( $e['food_id'] ?? 0 ) > 0 ) {
				$ids[ (int) $e['food_id'] ] = true;
			}
		}
		$out   = array();
		$table = LLD_Members_Stage3_Schema::table( 'foods' );
		foreach ( array_keys( $ids ) as $id ) {
			$hit = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND alcohol_100g > 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			if ( null !== $hit ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * The meal the member often repeats, for one tap on Today (FR3-102): the meal logged most often with the same foods
	 * in the last 14 days (at least twice), otherwise yesterday's last meal. Logging it again copies it (copy_into).
	 *
	 * @param array<int,array<string,mixed>> $entries Log rows.
	 * @param string                          $today   Local date.
	 * @return array{meal:string,from_date:string,items:int,kcal:float|null,times:int}|null
	 */
	public static function repeat_meal( array $entries, $today ) {
		$from      = gmdate( 'Y-m-d', strtotime( $today . ' -14 days' ) );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		$meals     = array();
		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) || ! empty( $e['deleted_at'] ) || self::DRINK_SOURCE === (string) ( $e['source'] ?? '' ) ) {
				continue;
			}
			$date = (string) ( $e['entry_date'] ?? '' );
			if ( $date < $from || $date >= $today ) {
				continue;
			}
			$k = $date . '|' . (string) ( $e['meal'] ?? '' );
			if ( ! isset( $meals[ $k ] ) ) {
				$meals[ $k ] = array( 'date' => $date, 'meal' => (string) ( $e['meal'] ?? '' ), 'foods' => array(), 'kcal' => 0.0, 'known' => true, 'last' => '' );
			}
			$meals[ $k ]['foods'][] = ( $e['food_kind'] ?? 'food' ) . ':' . (int) ( $e['food_id'] ?? 0 );
			if ( is_numeric( $e['kcal'] ?? null ) ) {
				$meals[ $k ]['kcal'] += (float) $e['kcal'];
			} else {
				$meals[ $k ]['known'] = false;
			}
			$meals[ $k ]['last'] = max( $meals[ $k ]['last'], (string) ( $e['created_at'] ?? '' ) );
		}
		if ( ! $meals ) {
			return null;
		}
		$groups = array();
		foreach ( $meals as $m ) {
			$foods = $m['foods'];
			sort( $foods );
			$g = $m['meal'] . '|' . implode( ',', $foods );
			if ( ! isset( $groups[ $g ] ) || $m['date'] > $groups[ $g ]['m']['date'] ) {
				$groups[ $g ] = array( 'm' => $m, 'times' => isset( $groups[ $g ] ) ? $groups[ $g ]['times'] + 1 : 1 );
			} else {
				$groups[ $g ]['times']++;
			}
		}
		$best = null;
		foreach ( $groups as $g ) {
			if ( $g['times'] >= 2 && ( null === $best || $g['times'] > $best['times'] || ( $g['times'] === $best['times'] && $g['m']['date'] > $best['m']['date'] ) ) ) {
				$best = $g;
			}
		}
		if ( null === $best ) {
			foreach ( $meals as $m ) {
				if ( $m['date'] === $yesterday && ( null === $best || strcmp( $m['last'], $best['m']['last'] ) > 0 ) ) {
					$best = array( 'm' => $m, 'times' => 1 );
				}
			}
		}
		if ( null === $best ) {
			return null;
		}
		return array(
			'meal'      => $best['m']['meal'],
			'from_date' => $best['m']['date'],
			'items'     => count( $best['m']['foods'] ),
			'kcal'      => $best['m']['known'] ? round( $best['m']['kcal'], 1 ) : null,
			'times'     => $best['times'],
		);
	}

	/**
	 * The drinks list for a member, from the shared food database. A member the safety matrix keeps calorie numbers
	 * from gets the sizes with no calories (FR3-69).
	 *
	 * @param int        $member_id Member number.
	 * @param array|null $found     Search word => food row (per 100 g, with id), when already looked up.
	 * @return array<int,array<string,mixed>>
	 */
	public static function drinks_for( $member_id, $found = null ) {
		$foods = is_array( $found ) ? $found : array(); // $found: search word => food row, already looked up
		foreach ( is_array( $found ) ? array() : array_unique( array_column( self::DRINKS, 'search' ) ) as $word ) {
			foreach ( self::search_member( $member_id, $word ) as $hit ) {
				if ( 'food' !== ( $hit['food_kind'] ?? '' ) || empty( $hit['id'] ) ) {
					continue;
				}
				$basis = self::basis( $member_id, 'food', (int) $hit['id'] );
				if ( $basis && isset( $basis['per_100g'] ) ) {
					$foods[ $word ] = $basis['per_100g'];
					break;
				}
			}
		}
		$menu = self::drinks_menu( $foods );
		if ( self::hidden( $member_id )['all'] ) {
			foreach ( $menu as $i => $d ) {
				$menu[ $i ]['kcal']             = null; // FR3-69: the sizes stay, the calories never leave the server
				$menu[ $i ]['nutrition_hidden'] = true;
			}
		}
		return $menu;
	}

	/**
	 * Whether a food database entry is the drink of a DRINKS size: found by that size's search word (its name or search
	 * names), and with alcohol for a drink, none for a mixer (a drink with no alcohol figure recorded still counts).
	 *
	 * @param int    $food_id Food id.
	 * @param string $size    A DRINKS key.
	 */
	public static function drink_food( $food_id, $size ) {
		global $wpdb;
		if ( ! isset( self::DRINKS[ $size ] ) || $food_id < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return false;
		}
		$table = LLD_Members_Stage3_Schema::table( 'foods' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, search_names, alcohol_100g FROM {$table} WHERE id = %d", $food_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		if ( ! is_array( $row ) ) {
			return false;
		}
		$d    = self::DRINKS[ $size ];
		$text = strtolower( (string) ( $row['name'] ?? '' ) . ' ' . (string) ( $row['search_names'] ?? '' ) );
		if ( false === strpos( $text, strtolower( $d['search'] ) ) ) {
			return false;
		}
		$alcohol = isset( $row['alcohol_100g'] ) && is_numeric( $row['alcohol_100g'] ) ? (float) $row['alcohol_100g'] : null;
		return 'drink' === $d['kind'] ? ( null === $alcohol || $alcohol > 0 ) : ( null === $alcohol || $alcohol <= 0 );
	}

	/**
	 * What the safety matrix hides of food nutrition for the member (FR3-69): {all, protein}.
	 *
	 * @param int $member_id Member number.
	 * @return array{all:bool,protein:bool}
	 */
	private static function hidden( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) || ! method_exists( 'LLD_Members_Stage3_Safety', 'nutrition_hidden' ) || ! method_exists( 'LLD_Members_Stage3_Safety', 'applies' ) ) {
			return array( 'all' => false, 'protein' => false );
		}
		$h = LLD_Members_Stage3_Safety::nutrition_hidden( LLD_Members_Stage3_Safety::applies( (int) $member_id, 'food_tracker' ) );
		// "Yes" to the Healthy Weight Check's own eating question counts as the profile flag ("prefer not to say" does
		// not, Owner Question 164).
		if ( ! $h['all'] && class_exists( 'LLD_Members_HW_Answers' ) && 'yes' === LLD_Members_HW_Answers::get( (int) $member_id, 'eating_disorder' ) ) {
			$h = array( 'all' => true, 'protein' => true );
		}
		return $h;
	}

	/**
	 * The member's local date.
	 *
	 * @param int $member_id Member number.
	 */
	private static function local_today( $member_id ) {
		return class_exists( 'LLD_Members_Programme' ) && method_exists( 'LLD_Members_Programme', 'local_today' ) ? (string) LLD_Members_Programme::local_today( (int) $member_id ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Log one drink from the drinks list.
	 *
	 * @param int    $member_id Member number.
	 * @param string $size      A DRINKS key.
	 * @param int    $food_id   Food id from drinks_for().
	 * @param string $date      Local date.
	 * @return array<string,mixed>
	 */
	public static function log_drink( $member_id, $size, $food_id, $date ) {
		// Only a drink of the drinks list, in the size chosen: the food must be that size's drink.
		if ( ! self::drink_food( (int) $food_id, (string) $size ) ) {
			return array( 'error' => 'Choose a drink.', 'status' => 422 );
		}
		$basis = self::basis( $member_id, 'food', (int) $food_id );
		if ( ! $basis || ! isset( $basis['per_100g'] ) ) {
			return array( 'error' => 'Choose a drink.', 'status' => 422 );
		}
		$entry = self::drink_entry( $basis['per_100g'], (string) $size, (string) $date );
		if ( ! $entry ) {
			return array( 'error' => 'Choose a drink.', 'status' => 422 );
		}
		$id = LLD_Members_Stage3_Data::insert( 'food_log', $member_id, $entry, array() );
		if ( $id < 1 ) {
			return array( 'error' => 'Try again.', 'status' => 500 );
		}
		$h = self::hidden( $member_id );
		if ( $h['all'] || $h['protein'] ) {
			// The entry is stored with its numbers (they count against the day like any food); the answer carries none.
			foreach ( $h['all'] ? array( 'kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g' ) : array( 'protein_g' ) as $k ) {
				$entry[ $k ] = null;
			}
			$entry['nutrition_hidden'] = $h['all'];
		}
		return array( 'id' => $id, 'entry' => $entry );
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
