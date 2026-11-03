<?php
/**
 * USDA FoodData Central mapper for basic foods (Foundation and SR Legacy).
 *
 * Packaged foods are left to Open Food Facts. Branded, survey and experimental
 * records are skipped. Nutrient amounts are per 100 g.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps one FoodData Central food into a Food row.
 */
class LLD_Members_Food_Usda extends LLD_Members_Food_Source {

	/**
	 * Older nutrient numbers used by some FoodData Central extracts, mapped to the current ids.
	 *
	 * @var array<int,int>
	 */
	const LEGACY = array(
		208 => 1008,
		203 => 1003,
		205 => 1005,
		204 => 1004,
		606 => 1258,
		291 => 1079,
		269 => 2000,
		307 => 1093,
		221 => 1018,
	);

	/**
	 * Food.source value.
	 */
	public function key() {
		return 'usda';
	}

	/**
	 * @param array<string,mixed> $raw One FoodData Central food.
	 * @return array<string,mixed>|null
	 */
	public function map( array $raw ) {
		if ( ! $this->is_basic( $raw ) ) {
			return null;
		}
		$fdc = $raw['fdcId'] ?? ( $raw['fdc_id'] ?? '' );
		$fdc = trim( (string) $fdc );
		$name = trim( (string) ( $raw['description'] ?? '' ) );
		if ( '' === $fdc || '' === $name ) {
			return null;
		}
		$nutrients = $this->nutrients( $raw );
		if ( null === $nutrients['kcal_100g'] ) {
			return null;
		}
		$extras = array();
		foreach ( array( 'commonNames', 'additionalDescriptions' ) as $field ) {
			if ( ! empty( $raw[ $field ] ) && is_string( $raw[ $field ] ) ) {
				$extras[] = $raw[ $field ];
			}
		}
		return LLD_Members_Food_Row::make(
			array(
				'source'       => 'usda',
				'source_ref'   => $fdc,
				'name'         => $name,
				'brand'        => '',
				'barcode'      => '',
				'portions'     => $this->portions( $raw ),
				'search_names' => $extras,
			) + $nutrients
		);
	}

	/**
	 * Foundation and SR Legacy only. A barcode or brand means a packaged food.
	 *
	 * @param array<string,mixed> $raw Source record.
	 */
	private function is_basic( array $raw ) {
		if ( '' !== trim( (string) ( $raw['gtinUpc'] ?? '' ) ) ) {
			return false;
		}
		if ( '' !== trim( (string) ( $raw['brandOwner'] ?? '' ) ) ) {
			return false;
		}
		if ( '' !== trim( (string) ( $raw['brandName'] ?? '' ) ) ) {
			return false;
		}
		$type = strtolower( (string) ( $raw['dataType'] ?? ( $raw['data_type'] ?? '' ) ) );
		$type = str_replace( array( '_', '-' ), ' ', $type );
		$type = trim( (string) preg_replace( '/\s+/', ' ', $type ) );
		if ( '' === $type ) {
			return true;
		}
		if ( str_contains( $type, 'branded' ) || str_contains( $type, 'experimental' ) || str_contains( $type, 'survey' ) || str_contains( $type, 'fndds' ) ) {
			return false;
		}
		return str_contains( $type, 'foundation' ) || str_contains( $type, 'sr legacy' );
	}

	/**
	 * @param array<string,mixed> $raw Source record.
	 * @return array<string,float|null>
	 */
	private function nutrients( array $raw ) {
		$by_id = array();
		$list  = $raw['foodNutrients'] ?? array();
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = $item['nutrient']['id'] ?? ( $item['nutrientId'] ?? null );
			if ( null === $id || '' === $id ) {
				$legacy = $item['nutrient']['number'] ?? ( $item['nutrientNumber'] ?? null );
				$legacy = (int) $legacy;
				$id     = self::LEGACY[ $legacy ] ?? ( $legacy > 0 ? $legacy : null );
			}
			if ( null === $id ) {
				continue;
			}
			$by_id[ (int) $id ] = array(
				'amount' => $item['amount'] ?? ( $item['value'] ?? null ),
				'unit'   => strtoupper( trim( (string) ( $item['nutrient']['unitName'] ?? ( $item['unitName'] ?? 'G' ) ) ) ),
			);
		}
		$sugar = $this->grams( $by_id, 2000 );
		if ( null === $sugar ) {
			$sugar = $this->grams( $by_id, 1063 );
		}
		return array(
			'kcal_100g'    => $this->energy( $by_id ),
			'protein_100g' => $this->grams( $by_id, 1003 ),
			'carb_100g'    => $this->grams( $by_id, 1005 ),
			'sugar_100g'   => $sugar,
			'fat_100g'     => $this->grams( $by_id, 1004 ),
			'satfat_100g'  => $this->grams( $by_id, 1258 ),
			'fibre_100g'   => $this->grams( $by_id, 1079 ),
			'salt_100g'    => $this->salt( $by_id ),
			'alcohol_100g' => $this->grams( $by_id, 1018 ),
		);
	}

	/**
	 * Prefer measured kilocalories. Kilojoules are converted.
	 *
	 * @param array<int,array{amount:mixed,unit:string}> $by_id Nutrients by id.
	 */
	private function energy( array $by_id ) {
		foreach ( array( 1008, 2047, 2048 ) as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$amount = LLD_Members_Food_Row::number( $by_id[ $id ]['amount'] );
			if ( null === $amount ) {
				continue;
			}
			if ( 'KJ' === $by_id[ $id ]['unit'] ) {
				$amount = $amount / 4.184;
			}
			return $amount;
		}
		return null;
	}

	/**
	 * @param array<int,array{amount:mixed,unit:string}> $by_id Nutrients by id.
	 * @param int                                        $id    Nutrient id.
	 */
	private function grams( array $by_id, $id ) {
		if ( ! isset( $by_id[ $id ] ) ) {
			return null;
		}
		$amount = LLD_Members_Food_Row::number( $by_id[ $id ]['amount'] );
		if ( null === $amount ) {
			return null;
		}
		if ( 'MG' === $by_id[ $id ]['unit'] ) {
			return $amount / 1000;
		}
		if ( 'UG' === $by_id[ $id ]['unit'] || 'MCG' === $by_id[ $id ]['unit'] ) {
			return $amount / 1000000;
		}
		return $amount;
	}

	/**
	 * Salt grams from sodium. 1 g of sodium is 2.5 g of salt.
	 *
	 * @param array<int,array{amount:mixed,unit:string}> $by_id Nutrients by id.
	 */
	private function salt( array $by_id ) {
		if ( ! isset( $by_id[1093] ) ) {
			return null;
		}
		$amount = LLD_Members_Food_Row::number( $by_id[1093]['amount'] );
		if ( null === $amount ) {
			return null;
		}
		$sodium_g = ( 'MG' === $by_id[1093]['unit'] ) ? $amount / 1000 : $amount;
		return $sodium_g * 2.5;
	}

	/**
	 * @param array<string,mixed> $raw Source record.
	 * @return array<int,array{name:string,grams:mixed}>
	 */
	private function portions( array $raw ) {
		$out  = array();
		$list = $raw['foodPortions'] ?? array();
		if ( ! is_array( $list ) ) {
			return $out;
		}
		foreach ( $list as $portion ) {
			if ( ! is_array( $portion ) ) {
				continue;
			}
			$name = trim( (string) ( $portion['portionDescription'] ?? '' ) );
			if ( '' === $name ) {
				$amount = trim( (string) ( $portion['amount'] ?? '' ) );
				$unit   = '';
				if ( isset( $portion['measureUnit'] ) && is_array( $portion['measureUnit'] ) ) {
					$unit = trim( (string) ( $portion['measureUnit']['name'] ?? ( $portion['measureUnit']['abbreviation'] ?? '' ) ) );
				}
				$name = trim( $amount . ' ' . $unit );
			}
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'  => $name,
				'grams' => $portion['gramWeight'] ?? null,
			);
		}
		return $out;
	}
}
