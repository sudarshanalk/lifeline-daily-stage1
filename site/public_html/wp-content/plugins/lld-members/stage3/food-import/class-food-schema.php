<?php
/**
 * Creates wp_lld_foods. Statements only add this table, its columns and its keys.
 *
 * The table is created when an import runs with the feature switch on.
 * The Stage 2 schema class is not edited. The lead may later call install()
 * from the plugin database update; the statements stay additive.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Additive schema for the Food table.
 */
class LLD_Members_Food_Schema {

	/**
	 * @param string $wpdb_prefix WordPress table prefix, usually wp_.
	 */
	public static function table( $wpdb_prefix ) {
		$prefix = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb_prefix );
		return $prefix . 'lld_foods';
	}

	/**
	 * Column definitions added when a column is missing. Never a change to an existing column.
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return array(
			'source'       => 'VARCHAR(16) NOT NULL',
			'source_ref'   => 'VARCHAR(64) NOT NULL',
			'name'         => 'VARCHAR(190) NOT NULL',
			'brand'        => 'VARCHAR(190) NULL',
			'barcode'      => 'VARCHAR(32) NULL',
			'portions'     => 'LONGTEXT NOT NULL',
			'kcal_100g'    => 'DECIMAL(6,2) NULL',
			'protein_100g' => 'DECIMAL(6,2) NULL',
			'carb_100g'    => 'DECIMAL(6,2) NULL',
			'sugar_100g'   => 'DECIMAL(6,2) NULL',
			'fat_100g'     => 'DECIMAL(6,2) NULL',
			'satfat_100g'  => 'DECIMAL(6,2) NULL',
			'fibre_100g'   => 'DECIMAL(6,2) NULL',
			'salt_100g'    => 'DECIMAL(6,2) NULL',
			'alcohol_100g' => 'DECIMAL(6,2) NULL',
			'search_names' => 'LONGTEXT NULL',
			'attribution'  => 'TEXT NULL',
			'updated_at'   => 'DATETIME NOT NULL',
		);
	}

	/**
	 * CREATE TABLE statement for inspection and install. The table name is internal.
	 *
	 * @param string $table Full table name from self::table().
	 */
	public static function create_sql( $table ) {
		$lines = array(
			'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
		);
		foreach ( self::columns() as $name => $definition ) {
			$lines[] = $name . ' ' . $definition;
		}
		$lines[] = 'PRIMARY KEY (id)';
		$lines[] = 'UNIQUE KEY source_and_ref (source, source_ref)';
		$lines[] = 'KEY barcode (barcode)';
		$lines[] = 'KEY food_name (name)';
		$lines[] = 'FULLTEXT KEY food_search (name, search_names)';
		return 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode( ', ', $lines ) . ')';
	}

	/**
	 * Create the Food table and add any missing column or key. Safe to run more than once.
	 */
	public static function install() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			throw new RuntimeException( 'Food table install needs WordPress.' );
		}
		$table   = self::table( $wpdb->prefix );
		$charset = $wpdb->get_charset_collate();
		$ok      = $wpdb->query( self::create_sql( $table ) . ' ' . $charset ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL, table name is internal.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not create the food table: ' . $wpdb->last_error );
		}
		foreach ( self::columns() as $name => $definition ) {
			self::ensure_column( $table, $name, $definition );
		}
		self::ensure_index( $table, 'source_and_ref', 'UNIQUE KEY source_and_ref (source, source_ref)' );
		self::ensure_index( $table, 'barcode', 'KEY barcode (barcode)' );
		self::ensure_index( $table, 'food_name', 'KEY food_name (name)' );
		self::ensure_index( $table, 'food_search', 'FULLTEXT KEY food_search (name, search_names)' );
		self::ensure_check( $table, 'lld_foods_source', "source IN ('usda','off','licensed','member')" );
		self::ensure_check( $table, 'lld_foods_kcal', 'kcal_100g IS NULL OR (kcal_100g >= 0 AND kcal_100g <= 900)' );
	}

	/**
	 * @param string $table      Full table name.
	 * @param string $column     Column name from this class.
	 * @param string $definition Column definition from this class.
	 */
	private static function ensure_column( $table, $column, $definition ) {
		global $wpdb;
		$names = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		if ( in_array( $column, $names, true ) ) {
			return;
		}
		$ok = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL from this class.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not add ' . $column . ' on the food table: ' . $wpdb->last_error );
		}
	}

	/**
	 * @param string $table Full table name.
	 * @param string $name  Index name from this class.
	 * @param string $ddl   Index clause from this class.
	 */
	private static function ensure_index( $table, $name, $ddl ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = '{$name}'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names are internal.
		if ( $rows ) {
			return;
		}
		$ok = $wpdb->query( "ALTER TABLE {$table} ADD {$ddl}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL from this class.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not add ' . $name . ' on the food table: ' . $wpdb->last_error );
		}
	}

	/**
	 * @param string $table Full table name.
	 * @param string $name  Constraint name from this class.
	 * @param string $expr  Check expression from this class.
	 */
	private static function ensure_check( $table, $name, $expr ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s', $table, $name ) );
		if ( $found ) {
			return;
		}
		$ok = $wpdb->query( "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expr})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL from this class.
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not add check ' . $name . ' on the food table: ' . $wpdb->last_error );
		}
	}
}
