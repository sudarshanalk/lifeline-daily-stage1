<?php
/**
 * Recorded licence terms for the free food sources (NFR3-15).
 *
 * USDA FoodData Central is a US Government work and is in the public domain.
 * Open Food Facts is under the Open Database Licence and requires attribution.
 * A licensed database can be added later as another source key; these terms stay here.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licence text the importer copies onto foods and can show to members.
 */
class LLD_Members_Food_Licence {

	/**
	 * Terms for the sources this importer knows. Keyed by the Food.source choice.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return array(
			'usda' => array(
				'source'               => 'usda',
				'name'                 => 'USDA FoodData Central',
				'covers'               => 'basic foods',
				'licence'              => 'US Government work, public domain',
				'url'                  => 'https://fdc.nal.usda.gov/',
				'attribution_required' => false,
				'attribution'          => 'U.S. Department of Agriculture, FoodData Central.',
			),
			'off'  => array(
				'source'               => 'off',
				'name'                 => 'Open Food Facts',
				'covers'               => 'packaged foods',
				'licence'              => 'Open Database License (ODbL) 1.0',
				'url'                  => 'https://world.openfoodfacts.org/terms-of-use',
				'attribution_required' => true,
				'attribution'          => 'Data from Open Food Facts (https://world.openfoodfacts.org), made available under the Open Database Licence.',
			),
		);
	}

	/**
	 * @param string $source Food.source value.
	 * @return array<string,mixed>|null
	 */
	public static function for_source( $source ) {
		$all = self::all();
		return $all[ $source ] ?? null;
	}

	/**
	 * Name shown beside a food so every result names its source.
	 *
	 * @param string $source Food.source value.
	 */
	public static function name_for( $source ) {
		$row = self::for_source( $source );
		if ( $row ) {
			return (string) $row['name'];
		}
		if ( 'licensed' === $source ) {
			return 'Licensed food data';
		}
		if ( 'member' === $source ) {
			return 'Member';
		}
		return '';
	}
}
