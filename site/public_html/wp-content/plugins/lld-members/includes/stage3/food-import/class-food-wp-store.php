<?php
/**
 * Writes Food rows with WordPress. Reads candidates back for search.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Food table store. Upsert matches source plus source_ref and refreshes that row.
 */
class LLD_Members_Food_Wp_Store extends LLD_Members_Food_Store {

	/**
	 * @param array<string,mixed> $row Validated food row.
	 * @return string inserted or updated.
	 */
	public function upsert( array $row ) {
		global $wpdb;
		$table = LLD_Members_Food_Schema::table( $wpdb->prefix );
		$data  = $this->data( $row );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source = %s AND source_ref = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$row['source'],
				$row['source_ref']
			)
		);
		if ( $found ) {
			$ok = $wpdb->update( $table, $data, array( 'id' => (int) $found ) );
			if ( false === $ok ) {
				throw new RuntimeException( 'Could not refresh a food row.' );
			}
			return 'updated';
		}
		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not store a food row.' );
		}
		return 'inserted';
	}

	/**
	 * Search the food table. Returns no rows when the feature switch is off.
	 *
	 * @param string $query Member's search text.
	 * @return array{enabled:bool,foods:array<int,array<string,mixed>>}
	 */
	public function search( $query ) {
		if ( ! LLD_Members_Food_Switch::enabled() ) {
			return array(
				'enabled' => false,
				'foods'   => array(),
			);
		}
		$normal = LLD_Members_Food_Row::normalise( $query );
		if ( strlen( $normal ) < 2 ) {
			return array(
				'enabled' => true,
				'foods'   => array(),
			);
		}
		return LLD_Members_Food_Search::results( $query, $this->candidates( $normal ) );
	}

	/**
	 * @param string $normal Lowercased query.
	 * @return array<int,array<string,mixed>>
	 */
	private function candidates( $normal ) {
		global $wpdb;
		$table   = LLD_Members_Food_Schema::table( $wpdb->prefix );
		$boolean = LLD_Members_Food_Search::boolean( $normal );
		$rows    = array();
		if ( '' !== $boolean ) {
			// Best matches first, shortest name first among equals, so the ranker sees the plain foods before the
			// long branded names. The food_search key is added by Stage 3 schema version 2; if it is missing the
			// query fails quietly and the LIKE search below runs instead.
			$quiet = $wpdb->suppress_errors();
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE MATCH(name, search_names) AGAINST (%s IN BOOLEAN MODE) ORDER BY MATCH(name, search_names) AGAINST (%s IN BOOLEAN MODE) DESC, CHAR_LENGTH(name) ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
					$boolean,
					$boolean
				),
				ARRAY_A
			);
			$wpdb->suppress_errors( $quiet );
			if ( is_array( $found ) ) {
				$rows = $found;
			}
		}
		if ( ! $rows ) {
			$like  = '%' . $wpdb->esc_like( $normal ) . '%';
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE name LIKE %s OR search_names LIKE %s ORDER BY CHAR_LENGTH(name) ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
					$like,
					$like
				),
				ARRAY_A
			);
			if ( is_array( $found ) ) {
				$rows = $found;
			}
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $this->hydrate( $row );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row Row from the mapper.
	 * @return array<string,mixed>
	 */
	private function data( array $row ) {
		return array(
			'source'       => $row['source'],
			'source_ref'   => $row['source_ref'],
			'name'         => $row['name'],
			'brand'        => '' !== $row['brand'] ? $row['brand'] : null,
			'barcode'      => '' !== $row['barcode'] ? $row['barcode'] : null,
			'portions'     => self::encode( $row['portions'] ),
			'kcal_100g'    => $row['kcal_100g'],
			'protein_100g' => $row['protein_100g'],
			'carb_100g'    => $row['carb_100g'],
			'sugar_100g'   => $row['sugar_100g'],
			'fat_100g'     => $row['fat_100g'],
			'satfat_100g'  => $row['satfat_100g'],
			'fibre_100g'   => $row['fibre_100g'],
			'salt_100g'    => $row['salt_100g'],
			'alcohol_100g' => $row['alcohol_100g'],
			'search_names' => self::encode( $row['search_names'] ),
			'attribution'  => '' !== $row['attribution'] ? $row['attribution'] : null,
			'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ) {
		foreach ( array( 'portions', 'search_names' ) as $field ) {
			if ( is_string( $row[ $field ] ?? null ) ) {
				$decoded = json_decode( $row[ $field ], true );
				$row[ $field ] = is_array( $decoded ) ? $decoded : array();
			}
		}
		return $row;
	}

	/**
	 * @param mixed $value List to store.
	 */
	private static function encode( $value ) {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $value, $flags );
		}
		return json_encode( $value, $flags );
	}
}
