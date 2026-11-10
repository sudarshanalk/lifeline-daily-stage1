<?php
/**
 * The Food table (wp_lld_foods) as the Stage 3 schema defines it.
 *
 * The table itself is created and upgraded by LLD_Members_Stage3_Schema (P3-14), which
 * `wp lld-members db-update` runs on every deploy; its statements only add (NFR3-04). This class
 * names the table and the columns the importer writes, and asks the Stage 3 schema to make sure the
 * table is there before an import.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Food table's name, columns and create statement, read from the Stage 3 schema.
 */
class LLD_Members_Food_Schema {

	const SUFFIX = 'foods';

	/**
	 * @param string $wpdb_prefix WordPress table prefix, usually wp_.
	 */
	public static function table( $wpdb_prefix ) {
		$prefix = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb_prefix );
		return $prefix . 'lld_' . self::SUFFIX;
	}

	/**
	 * The table's columns and their definitions, in the Stage 3 schema's order (data model: Food).
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		$columns = array();
		foreach ( preg_split( '/,\s*\n/', self::definition() ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || preg_match( '/^(PRIMARY|UNIQUE|KEY|FULLTEXT)\b/', $line ) ) {
				continue;
			}
			list( $name, $definition ) = array_pad( explode( ' ', $line, 2 ), 2, '' );
			$columns[ $name ]          = $definition;
		}
		return $columns;
	}

	/**
	 * CREATE TABLE statement for inspection. The same statement the Stage 3 schema runs.
	 *
	 * @param string $table Full table name from self::table().
	 */
	public static function create_sql( $table ) {
		return 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . trim( self::definition() ) . ')';
	}

	/**
	 * Create the Food table and add any missing column or key, through the Stage 3 schema update.
	 * Safe to run more than once.
	 */
	public static function install() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			throw new RuntimeException( 'Food table install needs WordPress.' );
		}
		LLD_Members_Stage3_Schema::update();
	}

	/**
	 * The table body from the Stage 3 schema.
	 */
	private static function definition() {
		return LLD_Members_Stage3_Schema::definition( self::SUFFIX );
	}
}
