<?php
/**
 * Source contract. A later licensed database is another class with the same map() result.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns one raw source record into a Food row, or null to skip it.
 */
abstract class LLD_Members_Food_Source {

	/**
	 * Food.source value: usda, off or licensed.
	 */
	abstract public function key();

	/**
	 * @param array<string,mixed> $raw One food from the source file.
	 * @return array<string,mixed>|null
	 */
	abstract public function map( array $raw );

	/**
	 * @return array<string,mixed>|null
	 */
	public function licence() {
		return LLD_Members_Food_Licence::for_source( $this->key() );
	}
}
