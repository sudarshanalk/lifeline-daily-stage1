<?php
/**
 * Where Food rows are written. The WordPress store is one implementation.
 * Tests use their own store. Neither implementation touches another table.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inserts or refreshes one food, matched on source and source_ref.
 */
abstract class LLD_Members_Food_Store {

	/**
	 * @param array<string,mixed> $row A row from LLD_Members_Food_Row::make().
	 * @return string inserted or updated.
	 */
	abstract public function upsert( array $row );
}
