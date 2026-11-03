<?php
/**
 * Command-line entry for the food importer. Not loaded by WordPress.
 *
 * The food_database switch must be test_accounts or everyone. It defaults to off.
 *
 *   php run.php --source=usda --file=foundation.json
 *   php run.php --source=off --file=products.jsonl
 *   php run.php --search=banana
 *
 * USDA files are Foundation or SR Legacy JSON (or JSONL). Open Food Facts files
 * are product JSON or JSONL. Packaged USDA foods are skipped.
 *
 * @package LLD_Members
 */

if ( PHP_SAPI !== 'cli' ) {
	if ( function_exists( 'http_response_code' ) ) {
		http_response_code( 404 );
	}
	exit;
}

$file   = '';
$source = '';
$search = '';
$wp     = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--file=' ) ) {
		$file = substr( $arg, 7 );
	} elseif ( str_starts_with( $arg, '--source=' ) ) {
		$source = substr( $arg, 9 );
	} elseif ( str_starts_with( $arg, '--search=' ) ) {
		$search = substr( $arg, 9 );
	} elseif ( str_starts_with( $arg, '--wp=' ) ) {
		$wp = substr( $arg, 5 );
	} else {
		fwrite( STDERR, "Unknown argument.\n" );
		exit( 1 );
	}
}

if ( '' === $file && '' === $search ) {
	fwrite( STDERR, "Use --source=usda|off --file=path or --search=text.\n" );
	exit( 1 );
}

$candidates = array();
if ( '' !== $wp ) {
	$candidates[] = $wp;
}
$candidates[] = dirname( __DIR__, 5 ) . '/wp-load.php';
$loaded       = false;
foreach ( $candidates as $candidate ) {
	if ( is_file( $candidate ) ) {
		require_once $candidate;
		$loaded = true;
		break;
	}
}
if ( ! $loaded ) {
	fwrite( STDERR, "WordPress could not be loaded. Pass --wp=/path/to/wp-load.php.\n" );
	exit( 1 );
}

require_once __DIR__ . '/load.php';

if ( ! LLD_Members_Food_Switch::enabled() ) {
	fwrite( STDERR, "The food_database switch is off. Nothing was imported.\n" );
	exit( 2 );
}

if ( '' !== $file && ! in_array( $source, array( 'usda', 'off' ), true ) ) {
	fwrite( STDERR, "Source must be usda or off.\n" );
	exit( 1 );
}

try {
	LLD_Members_Food_Schema::install();
	$store = new LLD_Members_Food_Wp_Store();
	if ( '' !== $file ) {
		$mapper  = LLD_Members_Food_Sources::get( $source );
		$summary = LLD_Members_Food_Import::path( $file, $mapper, $store );
		echo json_encode( $summary ) . "\n";
	}
	if ( '' !== $search ) {
		echo json_encode( $store->search( $search ) ) . "\n";
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 3 );
}
