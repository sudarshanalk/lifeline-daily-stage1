<?php
/**
 * Runs the food importer without WordPress. The test sends one JSON object on stdin.
 *
 * @package LLD_Members
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

$payload = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $payload ) ) {
	fwrite( STDERR, "Expected JSON on stdin.\n" );
	exit( 1 );
}

$GLOBALS['lld_food_switch'] = array_key_exists( 'switch', $payload ) ? $payload['switch'] : null;

/**
 * Stub for the feature switch option. A missing or empty state stays off.
 *
 * @param string $name    Option name.
 * @param mixed  $default Value when the option is absent.
 */
function get_option( $name, $default = false ) {
	if ( 'lld_feature_switches' !== $name ) {
		return $default;
	}
	$state = $GLOBALS['lld_food_switch'];
	if ( ! is_string( $state ) || '' === $state ) {
		return array();
	}
	return array(
		'food_tracker' => array(
			'state'       => $state,
			'approved_by' => '',
			'changed_at'  => '',
		),
	);
}

require dirname( __DIR__, 3 ) . '/site/public_html/wp-content/plugins/lld-members/includes/stage3/food-import/load.php';

/**
 * In-memory Food store. A side list stands in for a Stage 2 table the importer must not touch.
 */
class LLD_Food_Test_Store extends LLD_Members_Food_Store {

	/** @var array<int,array<string,mixed>> */
	public $rows = array();

	/** @var array<string,mixed> */
	public $side;

	public function __construct() {
		$this->side = array( 'diary' => array( 'stage-2-kept' ) );
	}

	/**
	 * @param array<string,mixed> $row Validated food.
	 */
	public function upsert( array $row ) {
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		foreach ( $this->rows as $index => $existing ) {
			if ( $existing['source'] === $row['source'] && $existing['source_ref'] === $row['source_ref'] ) {
				$this->rows[ $index ] = $row;
				return 'updated';
			}
		}
		$this->rows[] = $row;
		return 'inserted';
	}
}

/**
 * Stand-in for a later licensed database. Same row shape, different values.
 */
class LLD_Food_Test_Licensed extends LLD_Members_Food_Source {

	public function key() {
		return 'licensed';
	}

	/**
	 * @param array<string,mixed> $raw Test record.
	 */
	public function map( array $raw ) {
		return LLD_Members_Food_Row::make(
			array(
				'source'        => 'licensed',
				'source_ref'    => (string) ( $raw['id'] ?? '' ),
				'name'          => (string) ( $raw['name'] ?? '' ),
				'brand'         => '',
				'barcode'       => '',
				'portions'      => $raw['portions'] ?? array(),
				'kcal_100g'     => $raw['kcal_100g'] ?? null,
				'protein_100g'  => $raw['protein_100g'] ?? null,
				'carb_100g'     => $raw['carb_100g'] ?? null,
				'sugar_100g'    => $raw['sugar_100g'] ?? null,
				'fat_100g'      => $raw['fat_100g'] ?? null,
				'satfat_100g'   => $raw['satfat_100g'] ?? null,
				'fibre_100g'    => $raw['fibre_100g'] ?? null,
				'salt_100g'     => $raw['salt_100g'] ?? null,
				'alcohol_100g'  => $raw['alcohol_100g'] ?? null,
				'search_names'  => array(),
				'attribution'   => 'Licensed food data. Test catalogue only.',
			)
		);
	}
}

if ( ! empty( $payload['register_licensed'] ) ) {
	LLD_Members_Food_Sources::register( new LLD_Food_Test_Licensed() );
}

$call = (string) ( $payload['call'] ?? 'import' );
if ( 'decode' === $call ) {
	echo json_encode( LLD_Members_Food_Import::decode( (string) ( $payload['document'] ?? '' ) ) );
	exit;
}
if ( 'schema' === $call ) {
	echo json_encode(
		array(
			'table'   => LLD_Members_Food_Schema::table( 'wp_' ),
			'sql'     => LLD_Members_Food_Schema::create_sql( LLD_Members_Food_Schema::table( 'wp_' ) ),
			'columns' => array_keys( LLD_Members_Food_Schema::columns() ),
		)
	);
	exit;
}

$store  = new LLD_Food_Test_Store();
$before = $store->side;
$source = LLD_Members_Food_Sources::get( (string) ( $payload['source'] ?? 'usda' ) );
if ( ! $source ) {
	fwrite( STDERR, "Unknown source.\n" );
	exit( 1 );
}

if ( ! empty( $payload['file'] ) ) {
	$imported = LLD_Members_Food_Import::path( (string) $payload['file'], $source, $store );
} else {
	$imported = LLD_Members_Food_Import::records( $payload['records'] ?? array(), $source, $store );
}
if ( ! empty( $payload['replace'] ) && LLD_Members_Food_Switch::enabled() ) {
	$imported['replaced'] = LLD_Members_Food_Import::records( $payload['replace'], $source, $store );
}

$search  = null;
$elapsed = null;
if ( array_key_exists( 'query', $payload ) ) {
	$start   = hrtime( true );
	$search  = LLD_Members_Food_Search::results( (string) $payload['query'], $store->rows );
	$elapsed = ( hrtime( true ) - $start ) / 1e6;
}

echo json_encode(
	array(
		'import'        => $imported,
		'rows'          => $store->rows,
		'search'        => $search,
		'elapsed_ms'    => $elapsed,
		'side'          => $store->side,
		'side_before'   => $before,
		'fields'        => LLD_Members_Food_Row::FIELDS,
		'result_fields' => LLD_Members_Food_Search::FIELDS,
		'licences'      => LLD_Members_Food_Licence::all(),
		'switch'        => LLD_Members_Food_Switch::state(),
	)
);
