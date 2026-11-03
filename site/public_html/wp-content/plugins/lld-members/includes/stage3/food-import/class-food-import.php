<?php
/**
 * Imports USDA FoodData Central basic foods and Open Food Facts packaged foods
 * into the Food table (wp_lld_foods).
 *
 * This file is not loaded by the Stage 2 plugin: `wp lld-members food import` loads it,
 * and later packages call these methods from the Stage 3 programming interface.
 * While the food_tracker switch is missing or off, import writes nothing.
 *
 * Pass a downloaded JSON or JSONL file. No API key is read or stored.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads source files and upserts Food rows.
 */
class LLD_Members_Food_Import {

	/**
	 * @param array<int,mixed>         $records Raw source records.
	 * @param LLD_Members_Food_Source  $source  Mapper.
	 * @param LLD_Members_Food_Store   $store   Destination.
	 * @return array<string,mixed>
	 */
	public static function records( array $records, LLD_Members_Food_Source $source, LLD_Members_Food_Store $store ) {
		if ( ! LLD_Members_Food_Switch::enabled() ) {
			return self::stopped();
		}
		$counts = self::blank();
		foreach ( $records as $raw ) {
			self::apply( $raw, $source, $store, $counts );
		}
		$counts['ok'] = true;
		return $counts;
	}

	/**
	 * @param string                  $path   JSON or JSONL file.
	 * @param LLD_Members_Food_Source $source Mapper.
	 * @param LLD_Members_Food_Store  $store  Destination.
	 * @return array<string,mixed>
	 */
	public static function path( $path, LLD_Members_Food_Source $source, LLD_Members_Food_Store $store ) {
		if ( ! LLD_Members_Food_Switch::enabled() ) {
			return self::stopped();
		}
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			throw new RuntimeException( 'Food file not found.' );
		}
		$counts = self::blank();
		self::each_record(
			$path,
			static function ( $raw ) use ( $source, $store, &$counts ) {
				self::apply( $raw, $source, $store, $counts );
			}
		);
		$counts['ok'] = true;
		return $counts;
	}

	/**
	 * Decode a JSON document, a known wrapper, or JSONL text.
	 *
	 * @param string $text File contents.
	 * @return array<int,array<string,mixed>>
	 */
	public static function decode( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$first = $text[0];
		if ( '{' === $first || '[' === $first ) {
			$data = json_decode( $text, true );
			if ( is_array( $data ) ) {
				if ( array_is_list( $data ) ) {
					return array_map( array( self::class, 'unwrap' ), $data );
				}
				foreach ( array( 'FoundationFoods', 'SRLegacyFoods', 'foods', 'products' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
						return array_map( array( self::class, 'unwrap' ), array_values( $data[ $key ] ) );
					}
				}
				return array( self::unwrap( $data ) );
			}
		}
		$rows = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $text ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$rows[] = self::unwrap( $decoded );
			}
		}
		return $rows;
	}

	/**
	 * @param string   $path     File path.
	 * @param callable $callback Called with one raw record.
	 */
	private static function each_record( $path, callable $callback ) {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Food file could not be read.' );
		}
		$first = stream_get_line( $handle, 2 * 1024 * 1024, "\n" );
		if ( false === $first ) {
			fclose( $handle );
			return;
		}
		$trim    = trim( $first );
		$decoded = '' === $trim ? null : json_decode( $trim, true );
		if ( is_array( $decoded ) && self::looks_like_food( $decoded ) ) {
			$callback( self::unwrap( $decoded ) );
			while ( false !== ( $line = stream_get_line( $handle, 2 * 1024 * 1024, "\n" ) ) ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					$callback( self::unwrap( $row ) );
				}
			}
			fclose( $handle );
			return;
		}
		fclose( $handle );
		$size = filesize( $path );
		if ( false !== $size && $size > 256 * 1024 * 1024 ) {
			throw new RuntimeException( 'Food file is too large to read as one JSON document. Use JSONL, one food per line.' );
		}
		$text = file_get_contents( $path );
		if ( false === $text ) {
			throw new RuntimeException( 'Food file could not be read.' );
		}
		foreach ( self::decode( $text ) as $row ) {
			$callback( $row );
		}
	}

	/**
	 * @param mixed                   $raw    One source record.
	 * @param LLD_Members_Food_Source $source Mapper.
	 * @param LLD_Members_Food_Store  $store  Destination.
	 * @param array<string,mixed>     $counts Running counts.
	 */
	private static function apply( $raw, LLD_Members_Food_Source $source, LLD_Members_Food_Store $store, array &$counts ) {
		if ( ! is_array( $raw ) ) {
			$counts['skipped']++;
			return;
		}
		$row = $source->map( $raw );
		if ( ! is_array( $row ) || $row['source'] !== $source->key() ) {
			$counts['skipped']++;
			return;
		}
		$action = $store->upsert( $row );
		if ( 'inserted' === $action ) {
			$counts['written']++;
		} elseif ( 'updated' === $action ) {
			$counts['updated']++;
		} else {
			$counts['skipped']++;
		}
	}

	/**
	 * @param array<string,mixed> $raw A JSON object from a file.
	 * @return array<string,mixed>
	 */
	private static function unwrap( array $raw ) {
		if ( isset( $raw['product'] ) && is_array( $raw['product'] ) && ! isset( $raw['fdcId'] ) && ! isset( $raw['description'] ) ) {
			return $raw['product'];
		}
		return $raw;
	}

	/**
	 * @param array<string,mixed> $raw A decoded JSON line.
	 */
	private static function looks_like_food( array $raw ) {
		foreach ( array( 'fdcId', 'fdc_id', 'code', 'product_name', 'product', 'description' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function blank() {
		return array(
			'ok'       => false,
			'reason'   => '',
			'written'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function stopped() {
		return array(
			'ok'      => false,
			'reason'  => 'off',
			'written' => 0,
			'updated' => 0,
			'skipped' => 0,
		);
	}
}
