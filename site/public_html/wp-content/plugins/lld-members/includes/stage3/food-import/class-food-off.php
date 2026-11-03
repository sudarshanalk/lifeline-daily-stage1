<?php
/**
 * Open Food Facts mapper for packaged foods.
 *
 * Nutrition is read per 100 g. A household serving is kept only when it is in grams.
 * The barcode is the product code, digits only.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps one Open Food Facts product into a Food row.
 */
class LLD_Members_Food_Off extends LLD_Members_Food_Source {

	/**
	 * Food.source value.
	 */
	public function key() {
		return 'off';
	}

	/**
	 * @param array<string,mixed> $raw One product, or an API envelope with a product key.
	 * @return array<string,mixed>|null
	 */
	public function map( array $raw ) {
		if ( isset( $raw['product'] ) && is_array( $raw['product'] ) && ! isset( $raw['product_name'] ) && ! isset( $raw['code'] ) ) {
			$raw = $raw['product'];
		}
		$code = preg_replace( '/\D/', '', (string) ( $raw['code'] ?? ( $raw['_id'] ?? '' ) ) );
		$name = trim( (string) ( $raw['product_name_en'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $raw['product_name'] ?? '' ) );
		}
		if ( ! is_string( $code ) || '' === $code || '' === $name ) {
			return null;
		}
		$nutriments = $raw['nutriments'] ?? array();
		if ( ! is_array( $nutriments ) ) {
			$nutriments = array();
		}
		$kcal = LLD_Members_Food_Row::number( $nutriments['energy-kcal_100g'] ?? null );
		if ( null === $kcal ) {
			$kj = LLD_Members_Food_Row::number( $nutriments['energy_100g'] ?? null );
			if ( null !== $kj ) {
				$kcal = $kj / 4.184;
			}
		}
		$extras = array();
		foreach ( array( 'product_name', 'generic_name', 'generic_name_en' ) as $field ) {
			if ( ! empty( $raw[ $field ] ) && is_string( $raw[ $field ] ) ) {
				$extras[] = $raw[ $field ];
			}
		}
		$brand = trim( (string) ( $raw['brands'] ?? '' ) );
		if ( str_contains( $brand, ',' ) ) {
			$cut = strstr( $brand, ',', true );
			if ( is_string( $cut ) ) {
				$brand = trim( $cut );
			}
		}
		return LLD_Members_Food_Row::make(
			array(
				'source'       => 'off',
				'source_ref'   => $code,
				'name'         => $name,
				'brand'        => $brand,
				'barcode'      => $code,
				'portions'     => $this->portions( $raw ),
				'kcal_100g'    => $kcal,
				'protein_100g' => $nutriments['proteins_100g'] ?? null,
				'carb_100g'    => $nutriments['carbohydrates_100g'] ?? null,
				'sugar_100g'   => $nutriments['sugars_100g'] ?? null,
				'fat_100g'     => $nutriments['fat_100g'] ?? null,
				'satfat_100g'  => $nutriments['saturated-fat_100g'] ?? null,
				'fibre_100g'   => $nutriments['fiber_100g'] ?? null,
				'salt_100g'    => $nutriments['salt_100g'] ?? null,
				'alcohol_100g' => $nutriments['alcohol_100g'] ?? null,
				'search_names' => $extras,
			)
		);
	}

	/**
	 * A serving counted in grams. Millilitres are not treated as grams.
	 *
	 * @param array<string,mixed> $raw Product.
	 * @return array<int,array{name:string,grams:mixed}>
	 */
	private function portions( array $raw ) {
		$size = trim( (string) ( $raw['serving_size'] ?? '' ) );
		$unit = strtolower( trim( (string) ( $raw['serving_quantity_unit'] ?? '' ) ) );
		$qty  = LLD_Members_Food_Row::number( $raw['serving_quantity'] ?? null );
		if ( preg_match( '/ml/i', $size ) || 'ml' === $unit ) {
			return array();
		}
		$grams = null;
		$label = $size;
		if ( null !== $qty && $qty > 0 && ( '' === $unit || 'g' === $unit || 'gram' === $unit || 'grams' === $unit ) ) {
			$grams = $qty;
			if ( '' === $label ) {
				$label = $qty . ' g';
			}
		}
		if ( null === $grams && preg_match( '/([\d.]+)\s*g$/i', $size, $match ) ) {
			$grams = (float) $match[1];
			$label = $size;
		}
		if ( null === $grams || $grams <= 0 || '' === $label ) {
			return array();
		}
		return array(
			array(
				'name'  => $label,
				'grams' => $grams,
			),
		);
	}
}
