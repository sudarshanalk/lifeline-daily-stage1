<?php
/**
 * Food search over rows that are already in the Food shape.
 *
 * The same ordering is used for the database store and for tests.
 * With the feature switch off, search returns no foods.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ranks foods by name and search_names and reports calories for the standard portion.
 */
class LLD_Members_Food_Search {

	/**
	 * Keys every search hit has, for every source.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'name',
		'source',
		'source_name',
		'brand',
		'barcode',
		'portion',
		'kcal',
		'protein_g',
		'carb_g',
		'fat_g',
		'fibre_g',
		'attribution',
	);

	/**
	 * @param string                    $query Member's search text.
	 * @param array<int,array<string,mixed>> $rows Foods to rank.
	 * @return array{enabled:bool,foods:array<int,array<string,mixed>>}
	 */
	public static function results( $query, array $rows ) {
		if ( ! LLD_Members_Food_Switch::enabled() ) {
			return array(
				'enabled' => false,
				'foods'   => array(),
			);
		}
		$query = LLD_Members_Food_Row::normalise( $query );
		if ( strlen( $query ) < 2 ) {
			return array(
				'enabled' => true,
				'foods'   => array(),
			);
		}
		$queries = array_merge( array( $query ), LLD_Members_Food_Row::spelling_variants( $query ) );
		$matched = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rank = self::rank( $queries, $row );
			if ( null === $rank ) {
				continue;
			}
			$matched[] = array(
				'rank' => $rank,
				'row'  => $row,
			);
		}
		usort(
			$matched,
			static function ( $a, $b ) {
				if ( $a['rank'] !== $b['rank'] ) {
					return $a['rank'] <=> $b['rank'];
				}
				$length = strlen( (string) $a['row']['name'] ) <=> strlen( (string) $b['row']['name'] );
				if ( 0 !== $length ) {
					return $length;
				}
				return strcmp( (string) $a['row']['name'], (string) $b['row']['name'] );
			}
		);
		$foods = array();
		foreach ( array_slice( $matched, 0, 20 ) as $hit ) {
			$foods[] = self::present( $hit['row'] );
		}
		return array(
			'enabled' => true,
			'foods'   => $foods,
		);
	}

	/**
	 * Boolean-mode full text query. Operators typed by a member are removed.
	 *
	 * @param string $query Normalised query.
	 */
	public static function boolean( $query ) {
		$query = LLD_Members_Food_Row::normalise( $query );
		$words = preg_split( '/\s+/', $query );
		$out   = array();
		if ( ! is_array( $words ) ) {
			return '';
		}
		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-z0-9]+/', '', (string) $word );
			if ( ! is_string( $word ) || strlen( $word ) < 2 ) {
				continue;
			}
			$out[] = $word . '*';
		}
		return implode( ' ', $out );
	}

	/**
	 * @param string[]                $queries Normalised query and its spellings.
	 * @param array<string,mixed>     $row     Food row.
	 */
	private static function rank( array $queries, array $row ) {
		$names = array( LLD_Members_Food_Row::normalise( (string) ( $row['name'] ?? '' ) ) );
		foreach ( self::list_value( $row['search_names'] ?? array() ) as $extra ) {
			if ( is_string( $extra ) ) {
				$names[] = LLD_Members_Food_Row::normalise( $extra );
			}
		}
		$best = null;
		foreach ( $queries as $query ) {
			foreach ( $names as $index => $name ) {
				if ( '' === $name || '' === $query ) {
					continue;
				}
				$primary = ( 0 === $index );
				if ( $name === $query ) {
					$score = 0;
				} elseif ( str_starts_with( $name, $query ) ) {
					$score = $primary ? 1 : 2;
				} elseif ( str_contains( ' ' . $name, ' ' . $query ) ) {
					$score = $primary ? 2 : 3;
				} elseif ( str_contains( $name, $query ) ) {
					$score = 4;
				} else {
					continue;
				}
				if ( null === $best || $score < $best ) {
					$best = $score;
				}
			}
		}
		return $best;
	}

	/**
	 * @param array<string,mixed> $row Food row.
	 * @return array<string,mixed>
	 */
	private static function present( array $row ) {
		$list    = self::list_value( $row['portions'] ?? array() );
		$portion = array(
			'name'  => '100 g',
			'grams' => 100.0,
		);
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$grams = LLD_Members_Food_Row::number( $item['grams'] ?? null );
			$name  = trim( (string) ( $item['name'] ?? '' ) );
			if ( null === $grams || $grams <= 0 || '' === $name ) {
				continue;
			}
			$portion = array(
				'name'  => $name,
				'grams' => $grams,
			);
			break;
		}
		$factor = $portion['grams'] / 100;
		$source = (string) ( $row['source'] ?? '' );
		$hit    = array(
			'name'        => (string) ( $row['name'] ?? '' ),
			'source'      => $source,
			'source_name' => LLD_Members_Food_Licence::name_for( $source ),
			'brand'       => (string) ( $row['brand'] ?? '' ),
			'barcode'     => (string) ( $row['barcode'] ?? '' ),
			'portion'     => $portion,
			'kcal'        => self::per( $row['kcal_100g'] ?? null, $factor ),
			'protein_g'   => self::per( $row['protein_100g'] ?? null, $factor ),
			'carb_g'      => self::per( $row['carb_100g'] ?? null, $factor ),
			'fat_g'       => self::per( $row['fat_100g'] ?? null, $factor ),
			'fibre_g'     => self::per( $row['fibre_100g'] ?? null, $factor ),
			'attribution' => (string) ( $row['attribution'] ?? '' ),
		);
		$ordered = array();
		foreach ( self::FIELDS as $field ) {
			$ordered[ $field ] = $hit[ $field ];
		}
		if ( isset( $row['id'] ) ) {
			$ordered['id'] = (int) $row['id'];
		}
		return $ordered;
	}

	/**
	 * @param mixed $value Nutrient per 100 g.
	 * @param float $factor Portion grams divided by 100.
	 */
	private static function per( $value, $factor ) {
		$number = LLD_Members_Food_Row::number( $value );
		if ( null === $number ) {
			return null;
		}
		return round( $number * $factor, 1 );
	}

	/**
	 * @param mixed $value Array or JSON list.
	 * @return array<int,mixed>
	 */
	private static function list_value( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $value ) ? array_values( $value ) : array();
	}
}
