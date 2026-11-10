<?php
/**
 * Recipes, weekly nutrition summary and recipe suggestions (P3-43, FR3-34 to FR3-36).
 *
 * Member recipes store nutrition per serving when saved. Suggestions only ever come from the
 * owner-approved library: nothing here invents a recipe.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recipe arithmetic, the weekly summary and suggestion filters.
 */
class LLD_Members_Recipes {

	// Buckwheat joined the list with the fasting-day recipes (owner, 10 Nov 2026; data model: Approved recipe tags).
	const ALLERGENS = array( 'nuts', 'peanuts', 'gluten', 'milk', 'egg', 'fish', 'shellfish', 'soy', 'sesame', 'celery', 'mustard', 'sulphites', 'lupin', 'molluscs', 'buckwheat' );

	/** Approved recipes that carry the vegetarian hard cheese line (Food-Rule-Tags FT-Q4 = A, 10 Nov 2026). */
	const PARMESAN_RECIPES = array( 'R-118', 'R-161' );
	const PARMESAN_LINE    = 'Choose a vegetarian hard cheese if you prefer.';

	/**
	 * Owner-facing tip list for the weekly summary (FR3-34). Plain, factual wording only.
	 * The package review presents this exact list for the owner's approval.
	 */
	const TIPS = array(
		'Spread protein across your meals when you can.',
		'Vegetables at lunch and dinner help fibre add up over the week.',
		'A glass of water with meals is a simple habit many people find helpful.',
		'When a day goes over your calorie target, the next day is a fresh start.',
		'Logging most days, even briefly, makes the weekly average more useful.',
	);

	/**
	 * Premium only. Same rule as custom foods.
	 *
	 * @param bool $premium Premium access.
	 * @return string|null
	 */
	public static function gate_premium( $premium ) {
		return $premium ? null : 'Recipes are part of Premium.';
	}

	/**
	 * Premium only for the weekly summary.
	 *
	 * @param bool $premium Premium access.
	 * @return string|null
	 */
	public static function gate_week( $premium ) {
		return $premium ? null : 'The weekly nutrition summary is part of Premium.';
	}

	/**
	 * Premium only for suggestions.
	 *
	 * @param bool $premium Premium access.
	 * @return string|null
	 */
	public static function gate_suggest( $premium ) {
		return $premium ? null : 'Recipe suggestions are part of Premium.';
	}

	/**
	 * Nutrition per serving from ingredient lines. Each line is either a linked food
	 * (food_kind + food_id + grams) with nutrients already resolved, or an inline amount
	 * (kcal and macros for that amount). Servings must be 1 to 50.
	 *
	 * @param array<int,array<string,mixed>> $ingredients Resolved ingredient rows.
	 * @param int|float                      $servings    Number of servings.
	 * @return array<string,mixed>
	 */
	public static function nutrition_for( array $ingredients, $servings ) {
		$servings = (int) $servings;
		if ( $servings < 1 || $servings > 50 ) {
			return array( 'error' => 'Servings must be between 1 and 50.' );
		}
		if ( ! $ingredients ) {
			return array( 'error' => 'Add at least one ingredient.' );
		}
		$sums = array_fill_keys( LLD_Members_Food_Log::NUTRIENTS, 0.0 );
		$seen = array_fill_keys( LLD_Members_Food_Log::NUTRIENTS, false );
		foreach ( $ingredients as $line ) {
			if ( ! is_array( $line ) ) {
				return array( 'error' => 'Each ingredient needs an amount.' );
			}
			foreach ( LLD_Members_Food_Log::NUTRIENTS as $key ) {
				if ( ! isset( $line[ $key ] ) || ! is_numeric( $line[ $key ] ) ) {
					continue;
				}
				$sums[ $key ] += (float) $line[ $key ];
				$seen[ $key ]  = true;
			}
		}
		$map = array(
			'kcal'      => 'kcal_per_serving',
			'protein_g' => 'protein_per_serving',
			'carb_g'    => 'carb_per_serving',
			'fat_g'     => 'fat_per_serving',
			'fibre_g'   => 'fibre_per_serving',
		);
		$out = array( 'servings' => $servings );
		foreach ( $map as $key => $col ) {
			$out[ $col ] = $seen[ $key ] ? round( $sums[ $key ] / $servings, 'kcal' === $key ? 1 : 2 ) : null;
		}
		return $out;
	}

	/**
	 * Resolve each ingredient for this member, then store per-serving nutrition.
	 * Client-sent per-serving numbers are ignored.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $recipe    Validated recipe fields.
	 * @return array<string,mixed>
	 */
	public static function for_member( $member_id, array $recipe ) {
		$raw = $recipe['ingredients'] ?? null;
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || ! $raw ) {
			return array( 'error' => 'Add at least one ingredient.' );
		}
		$resolved = array();
		foreach ( $raw as $line ) {
			if ( ! is_array( $line ) ) {
				return array( 'error' => 'Each ingredient needs an amount.' );
			}
			$one = self::resolve_ingredient( $member_id, $line );
			if ( isset( $one['error'] ) ) {
				return $one;
			}
			$resolved[] = $one;
		}
		$nuts = self::nutrition_for( $resolved, isset( $recipe['servings'] ) ? $recipe['servings'] : 1 );
		if ( isset( $nuts['error'] ) ) {
			return $nuts;
		}
		$out                 = $recipe;
		$out['ingredients']  = wp_json_encode( $resolved );
		$out['servings']     = $nuts['servings'];
		foreach ( array( 'kcal_per_serving', 'protein_per_serving', 'carb_per_serving', 'fat_per_serving', 'fibre_per_serving' ) as $key ) {
			$out[ $key ] = $nuts[ $key ];
		}
		return $out;
	}

	/**
	 * One ingredient line: linked food, or inline nutrients for the amount given.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $line      Ingredient.
	 * @return array<string,mixed>
	 */
	public static function resolve_ingredient( $member_id, array $line ) {
		$grams = isset( $line['grams'] ) ? round( (float) $line['grams'], 1 ) : 0.0;
		if ( $grams < 0.1 || $grams > 5000 ) {
			return array( 'error' => 'Each ingredient needs an amount between 0.1 and 5,000 g.' );
		}
		$kind = isset( $line['food_kind'] ) ? (string) $line['food_kind'] : '';
		$id   = isset( $line['food_id'] ) ? (int) $line['food_id'] : 0;
		if ( '' !== $kind && $id > 0 ) {
			$basis = self::food_basis( $member_id, $kind, $id );
			if ( null === $basis ) {
				return array( 'error' => 'That food is not available.' );
			}
			$nuts = isset( $basis['per_100g'] ) ? LLD_Members_Food_Log::from_per_100( $basis['per_100g'], $grams ) : LLD_Members_Food_Log::from_portion( $basis['per_portion'], $grams );
			return array_merge(
				array(
					'food_kind' => $kind,
					'food_id'   => $id,
					'name'      => (string) ( $basis['name'] ?? ( $line['name'] ?? '' ) ),
					'grams'     => $grams,
				),
				$nuts
			);
		}
		// Inline nutrients for this amount (used when the food is not in the catalogue).
		$out = array(
			'name'  => isset( $line['name'] ) ? sanitize_text_field( (string) $line['name'] ) : '',
			'grams' => $grams,
		);
		foreach ( LLD_Members_Food_Log::NUTRIENTS as $key ) {
			$out[ $key ] = isset( $line[ $key ] ) && is_numeric( $line[ $key ] ) ? (float) $line[ $key ] : null;
		}
		if ( null === $out['kcal'] && null === $out['protein_g'] && null === $out['carb_g'] && null === $out['fat_g'] && null === $out['fibre_g'] ) {
			// Name-only lines stay, with unknown nutrition, so a simple save still works.
			return $out;
		}
		return $out;
	}

	/**
	 * One serving of a member recipe, as the diary stores it (grams = number of servings).
	 *
	 * @param array<string,mixed> $recipe Saved recipe row.
	 * @param float               $servings_eaten How many servings.
	 * @return array<string,mixed>|null
	 */
	public static function serving_basis( array $recipe, $servings_eaten ) {
		$servings_eaten = round( (float) $servings_eaten, 1 );
		if ( $servings_eaten < 0.1 || $servings_eaten > 5000 ) {
			return null;
		}
		if ( ! empty( $recipe['deleted_at'] ) ) {
			return null;
		}
		$one = array(
			'portion_g'  => 1.0,
			'kcal'       => $recipe['kcal_per_serving'] ?? null,
			'protein_g'  => $recipe['protein_per_serving'] ?? null,
			'carb_g'     => $recipe['carb_per_serving'] ?? null,
			'fat_g'      => $recipe['fat_per_serving'] ?? null,
			'fibre_g'    => $recipe['fibre_per_serving'] ?? null,
		);
		return array(
			'per_portion' => $one,
			'name'        => (string) ( $recipe['name'] ?? '' ),
		);
	}

	/**
	 * Weekly summary for a Premium member: averages, days on target, and one tip from the tip list.
	 *
	 * @param array<int,array<string,mixed>> $entries Food log rows.
	 * @param array<int,array<string,mixed>> $targets Nutrition targets.
	 * @param string                          $week_start Monday Y-m-d.
	 * @param bool                            $premium   Premium access.
	 * @return array<string,mixed>
	 */
	public static function week( array $entries, array $targets, $week_start, $premium ) {
		$denied = self::gate_week( $premium );
		if ( null !== $denied ) {
			return array(
				'error'  => $denied,
				'status' => 403,
			);
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $week_start ) ) {
			return array( 'error' => 'Add a week start date.' );
		}
		try {
			$start = new DateTimeImmutable( $week_start . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			unset( $e );
			return array( 'error' => 'Add a week start date.' );
		}
		if ( '1' !== $start->format( 'N' ) ) {
			return array( 'error' => 'The week start must be a Monday.' );
		}
		$days   = array();
		$sums   = array_fill_keys( LLD_Members_Food_Log::NUTRIENTS, 0.0 );
		$seen   = array_fill_keys( LLD_Members_Food_Log::NUTRIENTS, false );
		$on     = 0;
		$logged = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$date    = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$target  = LLD_Members_Food_Log::target_for_day( $targets, $date );
			$day     = LLD_Members_Food_Log::day( $entries, $date, $target );
			$has_log = false;
			foreach ( $day['meals'] as $meal_entries ) {
				if ( is_array( $meal_entries ) && $meal_entries ) {
					$has_log = true;
					break;
				}
			}
			$days[] = array(
				'date'   => $date,
				'totals' => $day['totals'],
				'target' => $target,
				'logged' => $has_log,
			);
			if ( ! $has_log ) {
				continue;
			}
			++$logged;
			foreach ( LLD_Members_Food_Log::NUTRIENTS as $key ) {
				if ( isset( $day['totals'][ $key ] ) && is_numeric( $day['totals'][ $key ] ) ) {
					$sums[ $key ] += (float) $day['totals'][ $key ];
					$seen[ $key ]  = true;
				}
			}
			if ( is_array( $target ) && isset( $target['kcal'] ) && is_numeric( $target['kcal'] ) && isset( $day['totals']['kcal'] ) && is_numeric( $day['totals']['kcal'] ) && (float) $day['totals']['kcal'] <= (float) $target['kcal'] ) {
				++$on;
			}
		}
		$averages = array();
		foreach ( LLD_Members_Food_Log::NUTRIENTS as $key ) {
			if ( ! $seen[ $key ] || $logged < 1 ) {
				$averages[ $key ] = null;
			} else {
				$averages[ $key ] = round( $sums[ $key ] / $logged, 'kcal' === $key ? 1 : 2 );
			}
		}
		return array(
			'week_start'     => $week_start,
			'week_end'       => $start->modify( '+6 days' )->format( 'Y-m-d' ),
			'days'           => $days,
			'logged_days'    => $logged,
			'days_on_target' => $on,
			'averages'       => $averages,
			'tip'            => self::tip_for( $week_start ),
		);
	}

	/**
	 * One tip from the owner-approved list, stable for a given week.
	 *
	 * @param string $week_start Monday Y-m-d.
	 */
	public static function tip_for( $week_start ) {
		$tips = self::TIPS;
		$n    = count( $tips );
		if ( $n < 1 ) {
			return '';
		}
		$day = (int) ( new DateTimeImmutable( $week_start . ' 00:00:00', new DateTimeZone( 'UTC' ) ) )->format( 'z' );
		return $tips[ $day % $n ];
	}

	/**
	 * Filter the approved library for this member. No AI: only what the library already holds.
	 *
	 * @param array<int,array<string,mixed>> $library Approved recipes with tags.
	 * @param array<string,mixed>            $prefs   allergies (list) and dislikes (list).
	 * @param float|null                     $left    Calories left today, or null when unknown.
	 * @param string|null                    $goal    Main goal_type, or null.
	 * @param bool                           $premium Premium access.
	 * @return array<string,mixed>
	 */
	public static function suggestions( array $library, array $prefs, $left, $goal, $premium ) {
		$denied = self::gate_suggest( $premium );
		if ( null !== $denied ) {
			return array(
				'error'  => $denied,
				'status' => 403,
			);
		}
		$allergies = array();
		foreach ( isset( $prefs['allergies'] ) && is_array( $prefs['allergies'] ) ? $prefs['allergies'] : array() as $a ) {
			$a = sanitize_key( (string) $a );
			if ( in_array( $a, self::ALLERGENS, true ) ) {
				$allergies[] = $a;
			}
		}
		$dislikes = array();
		foreach ( isset( $prefs['dislikes'] ) && is_array( $prefs['dislikes'] ) ? $prefs['dislikes'] : array() as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( '' !== $d ) {
				$dislikes[] = $d;
			}
		}
		$member = self::food_member( $prefs, $allergies );
		$out    = array();
		foreach ( $library as $recipe ) {
			if ( ! is_array( $recipe ) || empty( $recipe['approved'] ) ) {
				continue;
			}
			if ( ! isset( $recipe['kcal_per_serving'] ) || ! is_numeric( $recipe['kcal_per_serving'] ) ) {
				continue;
			}
			if ( ! isset( $recipe['allergens'] ) || ! is_array( $recipe['allergens'] ) ) {
				continue;
			}
			$hit = false;
			foreach ( $recipe['allergens'] as $a ) {
				if ( in_array( sanitize_key( (string) $a ), $allergies, true ) ) {
					$hit = true;
					break;
				}
			}
			if ( $hit ) {
				continue;
			}
			if ( null !== $left && (float) $recipe['kcal_per_serving'] > (float) $left ) {
				continue;
			}
			$suits = isset( $recipe['suits_goals'] ) && is_array( $recipe['suits_goals'] ) ? $recipe['suits_goals'] : array();
			if ( $suits && null !== $goal && ! in_array( (string) $goal, $suits, true ) ) {
				continue;
			}
			$name = strtolower( (string) ( $recipe['name'] ?? '' ) );
			foreach ( $dislikes as $d ) {
				if ( str_contains( $name, $d ) ) {
					continue 2;
				}
			}
			// R-HW-FOOD-01 (FR3-36, FR3-99): every food rule the member keeps, through the approved food-rule tags.
			$lines = array();
			if ( $member ) {
				$ok = LLD_Members_HW_Rules::food_ok( $recipe + array( 'diets' => isset( $recipe['diets'] ) ? $recipe['diets'] : array() ), $member );
				if ( ! $ok['ok'] ) {
					continue;
				}
				$lines = $ok['lines'];
			}
			if ( isset( $recipe['key'] ) && in_array( (string) $recipe['key'], self::PARMESAN_RECIPES, true ) ) {
				$lines[] = self::PARMESAN_LINE;
			}
			$item = array(
				'id'               => (int) ( $recipe['id'] ?? 0 ),
				'name'             => (string) ( $recipe['name'] ?? '' ),
				'kcal_per_serving' => (float) $recipe['kcal_per_serving'],
				'allergens'        => array_values( $recipe['allergens'] ),
				'suits_goals'      => array_values( $suits ),
				'approved'         => true,
				'source'           => 'approved_library',
			);
			if ( $lines ) {
				$item['lines'] = $lines;
			}
			$out[] = $item;
		}
		$result = array( 'recipes' => $out );
		// The halal vanilla and soy-sauce line, once a list (FT-Q3 = A).
		if ( $member && in_array( 'halal', $member['food_rules'], true ) ) {
			$result['lines'] = array( LLD_Members_HW_Rules::FOOD_LINES['halal_extras'] );
		}
		return $result;
	}

	/**
	 * The member's side of R-HW-FOOD-01, or null when they keep no food rule and no allergy, or the Healthy Weight rules
	 * are absent (allergies are also matched through the food-rule tags, rule draft reading 37).
	 *
	 * @param array    $prefs     prefs_of(): allergies, dislikes, food_rules, fasting_day.
	 * @param string[] $allergies Clean allergies.
	 * @return array|null {food_rules, allergies, fasting_day}
	 */
	private static function food_member( array $prefs, array $allergies ) {
		if ( ! class_exists( 'LLD_Members_HW_Rules' ) ) {
			return null;
		}
		$rules = array();
		foreach ( isset( $prefs['food_rules'] ) && is_array( $prefs['food_rules'] ) ? $prefs['food_rules'] : array() as $r ) {
			$r = (string) $r;
			if ( 0 === strpos( $r, 'fast:' ) ) {
				continue; // a fast kept is a calendar entry, not a food rule
			}
			$rules[] = 'no_grains_pulses_fasting_days' === $r ? 'no_grains_pulses_fasting' : $r;
		}
		if ( ! $rules && ! $allergies ) {
			return null;
		}
		return array(
			'food_rules'  => $rules,
			'allergies'   => $allergies,
			'fasting_day' => ! empty( $prefs['fasting_day'] ),
		);
	}

	/**
	 * R-HW-FOOD-01 and -02 for one meal type of a meal plan (FR3-99): only matching approved items, the empty state
	 * below 5, preferences only reorder (Premium, R-HW-FOOD-02), and the approved halal and kosher lines.
	 *
	 * @param int         $member_id Member number.
	 * @param string|null $meal_type breakfast, lunch, dinner, snack, or null.
	 * @param string      $date      Local date (a fasting day changes the grains and pulses rule).
	 * @return array LLD_Members_HW_Rules::food_filter() result.
	 */
	public static function for_meal_type( $member_id, $meal_type, $date ) {
		$prefs  = self::prefs_of( $member_id, $date );
		$member = self::food_member( $prefs, isset( $prefs['allergies'] ) ? (array) $prefs['allergies'] : array() );
		$member = $member ? $member : array(
			'food_rules'  => array(),
			'allergies'   => (array) $prefs['allergies'],
			'fasting_day' => false,
		);
		if ( LLD_Members_Food_Log::premium( $member_id ) && class_exists( 'LLD_Members_HW_Answers' ) ) {
			$member['cooking_time'] = LLD_Members_HW_Answers::get( $member_id, 'cooking_time' );
			$member['budget']       = LLD_Members_HW_Answers::get( $member_id, 'budget' );
			$member['cuisines']     = (array) LLD_Members_HW_Answers::get( $member_id, 'cuisines' );
		}
		return LLD_Members_HW_Rules::food_filter( self::library(), $member, $meal_type );
	}

	/**
	 * R-HW-FOOD-03 (FR3-99 AS-07): during a dawn-to-sunset fast the member keeps, the approved fasting meal plan (pre-dawn
	 * meal, breaking the fast, evening meal), with the same calorie target; back to the usual plan the day after. A member
	 * with type 2 diabetes keeps the usual plan and sees the approved line. The switch is Premium; the line is free (Q163).
	 *
	 * @param int    $member_id Member number.
	 * @param string $date      Local date.
	 * @return array{rule:string,plan:string,fast:string|null,target_changes:bool,line:string|null}
	 */
	public static function meal_plan_for( $member_id, $date ) {
		$fasts = class_exists( 'LLD_Members_HW_Answers' ) ? (array) LLD_Members_HW_Answers::get( $member_id, 'fasts' ) : array();
		$facts = class_exists( 'LLD_Members_Goal_Journey' ) ? LLD_Members_Goal_Journey::facts( $member_id ) : array( 'situations' => array() );
		$out   = LLD_Members_HW_Rules::meal_timing(
			array(
				'date'       => (string) $date,
				'fasts'      => LLD_Members_HW_Programme::meal_fasts( $fasts ),
				'conditions' => in_array( 'diabetes', (array) $facts['situations'], true ) ? array( 'type2_diabetes' ) : array(),
			)
		);
		if ( 'fasting' === $out['plan'] && ! LLD_Members_Food_Log::premium( $member_id ) ) {
			$out['plan'] = 'usual';
		}
		return $out;
	}

	/**
	 * Clean allergies and dislikes for storage.
	 *
	 * @param array<string,mixed> $prefs Raw preferences.
	 * @return array<string,mixed>
	 */
	public static function prefs_for( array $prefs ) {
		$allergies = array();
		foreach ( isset( $prefs['allergies'] ) && is_array( $prefs['allergies'] ) ? $prefs['allergies'] : array() as $a ) {
			$a = sanitize_key( (string) $a );
			if ( in_array( $a, self::ALLERGENS, true ) && ! in_array( $a, $allergies, true ) ) {
				$allergies[] = $a;
			}
		}
		$dislikes = array();
		foreach ( isset( $prefs['dislikes'] ) && is_array( $prefs['dislikes'] ) ? $prefs['dislikes'] : array() as $d ) {
			$d = sanitize_text_field( (string) $d );
			if ( '' !== $d && ! in_array( $d, $dislikes, true ) ) {
				$dislikes[] = $d;
			}
		}
		return array(
			'allergies' => wp_json_encode( $allergies ),
			'dislikes'  => wp_json_encode( $dislikes ),
		);
	}

	/**
	 * Computed views for the food tracker.
	 *
	 * @param string               $which     week or suggest.
	 * @param int                  $member_id Member number.
	 * @param array<string,string> $query     week_start or date.
	 * @return array<string,mixed>
	 */
	public static function view( $which, $member_id, array $query ) {
		$premium = LLD_Members_Food_Log::premium( $member_id );
		if ( 'week' === $which ) {
			return self::week(
				self::rows_for( 'food_log', $member_id ),
				self::rows_for( 'nutrition_targets', $member_id ),
				$query['week_start'] ?? '',
				$premium
			);
		}
		if ( 'suggest' === $which ) {
			$date = $query['date'] ?? gmdate( 'Y-m-d' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return array( 'error' => 'Add a date.' );
			}
			$day = LLD_Members_Food_Log::day(
				self::rows_for( 'food_log', $member_id ),
				$date,
				LLD_Members_Food_Log::target_for_day( self::rows_for( 'nutrition_targets', $member_id ), $date )
			);
			$left = null;
			if ( isset( $day['left']['kcal'] ) && is_numeric( $day['left']['kcal'] ) ) {
				$left = (float) $day['left']['kcal'];
			} elseif ( isset( $day['target']['kcal'] ) && is_numeric( $day['target']['kcal'] ) && isset( $day['totals']['kcal'] ) && is_numeric( $day['totals']['kcal'] ) ) {
				$left = max( 0, (float) $day['target']['kcal'] - (float) $day['totals']['kcal'] );
			}
			return self::suggestions(
				self::library(),
				self::prefs_of( $member_id, $date ),
				$left,
				self::main_goal( $member_id ),
				$premium
			);
		}
		return array( 'error' => 'Not available.' );
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{allergies: array<int,string>, dislikes: array<int,string>}
	 */
	private static function prefs_of( $member_id, $date = null ) {
		// The food rules are sealed (P3-35): opened here for this member only.
		$rows = class_exists( 'LLD_Members_Stage3_Data' ) ? LLD_Members_Stage3_Data::rows( 'food_preferences', (int) $member_id, array( 'food_rules' ), '', 1 ) : array();
		$row  = $rows ? $rows[0] : array();
		$a    = isset( $row['allergies'] ) ? ( is_string( $row['allergies'] ) ? json_decode( $row['allergies'], true ) : $row['allergies'] ) : array();
		$d    = isset( $row['dislikes'] ) ? ( is_string( $row['dislikes'] ) ? json_decode( $row['dislikes'], true ) : $row['dislikes'] ) : array();
		// The food rules the member keeps, with the fasts as "fast:<entry>" (P3-35, one store reused by FR3-99).
		$f     = isset( $row['food_rules'] ) ? ( is_string( $row['food_rules'] ) ? json_decode( $row['food_rules'], true ) : $row['food_rules'] ) : array();
		$f     = is_array( $f ) ? array_map( 'strval', $f ) : array();
		$fasts = array();
		foreach ( $f as $r ) {
			if ( 0 === strpos( $r, 'fast:' ) ) {
				$fasts[] = substr( $r, 5 );
			}
		}
		return array(
			'allergies'   => is_array( $a ) ? $a : array(),
			'dislikes'    => is_array( $d ) ? $d : array(),
			'food_rules'  => $f,
			'fasting_day' => null !== $date && $fasts && class_exists( 'LLD_Members_HW_Programme' ) && LLD_Members_HW_Programme::fasting_day( $fasts, (string) $date ),
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return string|null
	 */
	private static function main_goal( $member_id ) {
		foreach ( self::rows_for( 'goals', $member_id ) as $goal ) {
			if ( is_array( $goal ) && 'main' === ( $goal['role'] ?? '' ) && empty( $goal['deleted_at'] ) ) {
				return isset( $goal['goal_type'] ) ? (string) $goal['goal_type'] : null;
			}
		}
		return null;
	}

	/**
	 * Owner-approved Stage 2 recipes that carry Stage 3 suggestion tags (kcal, allergens, suits_goals).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function library() {
		if ( ! function_exists( 'get_posts' ) || ! class_exists( 'LLD_Members_Content' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lld_recipe',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$shown = LLD_Members_Content::shown( (int) $post->ID );
			if ( ! is_array( $shown ) ) {
				continue;
			}
			$tagged = self::tag_from_fields( $shown, (int) $post->ID );
			if ( $tagged ) {
				$out[] = $tagged;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item      Approved content snapshot from LLD_Members_Content::shown().
	 * @param int                 $parent_id Working copy id.
	 * @return array<string,mixed>|null
	 */
	private static function tag_from_fields( array $item, $parent_id ) {
		$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		if ( ! isset( $fields['kcal_per_serving'] ) || ! is_numeric( $fields['kcal_per_serving'] ) ) {
			return null;
		}
		$allergens = $fields['allergens'] ?? null;
		if ( is_string( $allergens ) ) {
			$allergens = array_values( array_filter( array_map( 'trim', explode( ',', $allergens ) ) ) );
		}
		if ( ! is_array( $allergens ) ) {
			return null;
		}
		$suits = $fields['suits_goals'] ?? array();
		if ( is_string( $suits ) ) {
			$suits = array_values( array_filter( array_map( 'trim', explode( ',', $suits ) ) ) );
		}
		if ( ! is_array( $suits ) ) {
			$suits = array();
		}
		$list = function ( $v ) {
			if ( is_string( $v ) ) {
				$v = array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
			}
			return is_array( $v ) ? array_values( array_map( 'strval', $v ) ) : array();
		};
		// Approved recipe tags (P3-35): food-rule tags, diet suitability, meal type and the R-HW-FOOD-02 order tags.
		$tags = isset( $item['tags'] ) && is_array( $item['tags'] ) ? $item['tags'] : array();
		return array(
			'id'               => (int) ( $item['parent_id'] ?? $parent_id ),
			'name'             => (string) ( $item['title'] ?? '' ),
			'kcal_per_serving' => (float) $fields['kcal_per_serving'],
			'kcal'             => (float) $fields['kcal_per_serving'],
			'allergens'        => array_values( $allergens ),
			'suits_goals'      => array_values( $suits ),
			'approved'         => true,
			'key'              => isset( $fields['recipe_key'] ) ? (string) $fields['recipe_key'] : null,
			'food_rules'       => $list( $fields['food_rules'] ?? array() ),
			'diets'            => $list( $fields['diet'] ?? array() ),
			'meal_type'        => isset( $fields['meal_type'] ) ? (string) $fields['meal_type'] : null,
			'quick'            => in_array( 'quick', $tags, true ),
			'low_cost'         => in_array( 'low-cost', $tags, true ),
			'cuisine'          => isset( $fields['cuisine'] ) ? (string) $fields['cuisine'] : null,
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $kind      food or custom.
	 * @param int    $id        Food id.
	 * @return array<string,mixed>|null
	 */
	private static function food_basis( $member_id, $kind, $id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || $id < 1 || ! class_exists( 'LLD_Members_Stage3_Schema' ) ) {
			return null;
		}
		if ( 'food' === $kind ) {
			$table = LLD_Members_Stage3_Schema::table( 'foods' );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, kcal_100g, protein_100g, carb_100g, fat_100g, fibre_100g FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			return is_array( $row ) ? array(
				'per_100g' => $row,
				'name'     => (string) $row['name'],
			) : null;
		}
		if ( 'custom' === $kind ) {
			$table = LLD_Members_Stage3_Schema::table( 'custom_foods' );
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, portion_g, kcal, protein_g, carb_g, fat_g, fibre_g FROM {$table} WHERE id = %d AND member_id = %d AND deleted_at IS NULL", $id, $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			return is_array( $row ) ? array(
				'per_portion' => $row,
				'name'        => (string) $row['name'],
			) : null;
		}
		return null;
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
}
