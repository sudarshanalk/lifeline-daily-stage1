<?php
/**
 * One Food row, the same shape for every source (NFR3-15).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a mapped food and fills portions, spellings and attribution.
 */
class LLD_Members_Food_Row {

	const SOURCES = array( 'usda', 'off', 'licensed', 'member' );

	/**
	 * British and American spellings stored on the food so search can use either.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	const SPELLINGS = array(
		array( 'yogurt', 'yoghurt' ),
		array( 'flavor', 'flavour' ),
		array( 'fiber', 'fibre' ),
		array( 'omelet', 'omelette' ),
		array( 'chili', 'chilli' ),
		array( 'licorice', 'liquorice' ),
		array( 'mold', 'mould' ),
		array( 'liter', 'litre' ),
	);

	/**
	 * Fields every source must produce, in order. updated_at is set by the store.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'source',
		'source_ref',
		'name',
		'brand',
		'barcode',
		'portions',
		'kcal_100g',
		'protein_100g',
		'carb_100g',
		'sugar_100g',
		'fat_100g',
		'satfat_100g',
		'fibre_100g',
		'salt_100g',
		'alcohol_100g',
		'search_names',
		'attribution',
	);

	/**
	 * @param array<string,mixed> $input Mapped values from one source.
	 * @return array<string,mixed>|null Null when the record is not safe to store.
	 */
	public static function make( array $input ) {
		$source = (string) ( $input['source'] ?? '' );
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return null;
		}
		$ref = self::reference( (string) ( $input['source_ref'] ?? '' ) );
		$name = self::label( (string) ( $input['name'] ?? '' ), 190 );
		if ( '' === $ref || '' === $name ) {
			return null;
		}
		$kcal = self::nutrient( $input['kcal_100g'] ?? null );
		if ( null === $kcal || $kcal > 900 ) {
			return null;
		}
		$licence = LLD_Members_Food_Licence::for_source( $source );
		$attribution = self::label( (string) ( $input['attribution'] ?? '' ), 500 );
		if ( '' === $attribution && $licence ) {
			$attribution = (string) $licence['attribution'];
		}
		if ( $licence && ! empty( $licence['attribution_required'] ) && '' === $attribution ) {
			return null;
		}
		if ( ! $licence && '' === $attribution ) {
			return null;
		}
		$extras = array();
		if ( isset( $input['search_names'] ) && is_array( $input['search_names'] ) ) {
			$extras = $input['search_names'];
		}
		$row = array(
			'source'        => $source,
			'source_ref'    => $ref,
			'name'          => $name,
			'brand'         => self::label( (string) ( $input['brand'] ?? '' ), 190 ),
			'barcode'       => self::barcode( (string) ( $input['barcode'] ?? '' ) ),
			'portions'      => self::portions( isset( $input['portions'] ) && is_array( $input['portions'] ) ? $input['portions'] : array() ),
			'kcal_100g'     => $kcal,
			'protein_100g'  => self::nutrient( $input['protein_100g'] ?? null ),
			'carb_100g'     => self::nutrient( $input['carb_100g'] ?? null ),
			'sugar_100g'    => self::nutrient( $input['sugar_100g'] ?? null ),
			'fat_100g'      => self::nutrient( $input['fat_100g'] ?? null ),
			'satfat_100g'   => self::nutrient( $input['satfat_100g'] ?? null ),
			'fibre_100g'    => self::nutrient( $input['fibre_100g'] ?? null ),
			'salt_100g'     => self::nutrient( $input['salt_100g'] ?? null ),
			'alcohol_100g'  => self::nutrient( $input['alcohol_100g'] ?? null ),
			'search_names'  => self::search_names( $name, $extras ),
			'attribution'   => $attribution,
		);
		$ordered = array();
		foreach ( self::FIELDS as $field ) {
			$ordered[ $field ] = $row[ $field ];
		}
		return $ordered;
	}

	/**
	 * @param mixed $value A number from a source file.
	 */
	public static function number( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( str_replace( ',', '', $value ) );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return null;
			}
			$value = (float) $value;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		if ( ! is_finite( (float) $value ) ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Lowercased spelling variants of a name or a search query.
	 *
	 * @return string[]
	 */
	public static function spelling_variants( $text ) {
		$lower = self::normalise( $text );
		if ( '' === $lower ) {
			return array();
		}
		$out = array();
		foreach ( self::SPELLINGS as $pair ) {
			if ( str_contains( $lower, $pair[0] ) ) {
				$out[] = str_replace( $pair[0], $pair[1], $lower );
			}
			if ( str_contains( $lower, $pair[1] ) ) {
				$out[] = str_replace( $pair[1], $pair[0], $lower );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Letters, digits and single spaces, for matching.
	 *
	 * @param string $text Raw name or query.
	 */
	public static function normalise( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$text = preg_replace( '/[^a-z0-9\s]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) > 80 ) {
			$text = substr( $text, 0, 80 );
		}
		return $text;
	}

	/**
	 * @param mixed $value Nutrient amount per 100 g.
	 */
	private static function nutrient( $value ) {
		$number = self::number( $value );
		if ( null === $number || $number < 0 ) {
			return null;
		}
		return round( $number, 2 );
	}

	/**
	 * @param string $value Source identifier.
	 */
	private static function reference( $value ) {
		$value = trim( $value );
		$value = preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $value );
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > 64 ) {
			return '';
		}
		return $value;
	}

	/**
	 * @param string $value Free text.
	 * @param int    $max   Maximum characters.
	 */
	private static function label( $value, $max ) {
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * @param string $value Barcode from the source. Digits only.
	 */
	private static function barcode( $value ) {
		$digits = preg_replace( '/\D/', '', $value );
		if ( ! is_string( $digits ) || '' === $digits || strlen( $digits ) > 32 ) {
			return '';
		}
		return $digits;
	}

	/**
	 * Household portions first, then 100 g when the source did not include it.
	 *
	 * @param array<int,mixed> $items Portion rows from a mapper.
	 * @return array<int,array{name:string,grams:float}>
	 */
	private static function portions( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$grams = self::number( $item['grams'] ?? null );
			$name  = self::label( (string) ( $item['name'] ?? '' ), 80 );
			if ( null === $grams || $grams <= 0 || $grams > 5000 || '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'  => $name,
				'grams' => round( $grams, 2 ),
			);
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		$has_hundred = false;
		foreach ( $out as $portion ) {
			if ( 100.0 === (float) $portion['grams'] && '100 g' === strtolower( $portion['name'] ) ) {
				$has_hundred = true;
				break;
			}
		}
		if ( ! $has_hundred ) {
			$out[] = array(
				'name'  => '100 g',
				'grams' => 100,
			);
		}
		return $out;
	}

	/**
	 * @param string           $name   Stored food name.
	 * @param array<int,mixed> $extras Other names from the source.
	 * @return string[]
	 */
	private static function search_names( $name, array $extras ) {
		$seeds = array( $name );
		foreach ( $extras as $extra ) {
			if ( is_string( $extra ) && '' !== trim( $extra ) ) {
				$seeds[] = $extra;
			}
		}
		$own    = self::normalise( $name );
		$found  = array();
		foreach ( $seeds as $seed ) {
			foreach ( self::spelling_variants( $seed ) as $variant ) {
				if ( $variant !== $own && ! in_array( $variant, $found, true ) ) {
					$found[] = $variant;
				}
			}
			$normal = self::normalise( $seed );
			if ( $normal !== $own && '' !== $normal && ! in_array( $normal, $found, true ) ) {
				$found[] = $normal;
			}
		}
		return array_slice( $found, 0, 30 );
	}
}
