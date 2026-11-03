<?php
/**
 * Which source class handles a key. Built-in sources are USDA and Open Food Facts.
 * A licensed source registers itself here; import and search stay unchanged.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks up a food source by its key.
 */
class LLD_Members_Food_Sources {

	/**
	 * @var array<string,LLD_Members_Food_Source>
	 */
	private static $extra = array();

	/**
	 * @param LLD_Members_Food_Source $source A source added without editing the importer.
	 */
	public static function register( LLD_Members_Food_Source $source ) {
		self::$extra[ $source->key() ] = $source;
	}

	/**
	 * @param string $key usda, off, or a registered key such as licensed.
	 */
	public static function get( $key ) {
		if ( isset( self::$extra[ $key ] ) ) {
			return self::$extra[ $key ];
		}
		if ( 'usda' === $key ) {
			return new LLD_Members_Food_Usda();
		}
		if ( 'off' === $key ) {
			return new LLD_Members_Food_Off();
		}
		return null;
	}
}
