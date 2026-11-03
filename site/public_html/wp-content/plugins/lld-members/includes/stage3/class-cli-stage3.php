<?php
/**
 * Stage 3 WP-CLI commands: feature switches, test accounts and the food database.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp lld-members feature …`, `wp lld-members test-accounts …` and `wp lld-members food …`.
 */
class LLD_Members_Stage3_CLI {

	public static function register() {
		WP_CLI::add_command( 'lld-members feature', array( __CLASS__, 'feature' ) );
		WP_CLI::add_command( 'lld-members test-accounts', array( __CLASS__, 'test_accounts' ) );
		WP_CLI::add_command( 'lld-members food', array( __CLASS__, 'food' ) );
	}

	/**
	 * Show or change a feature switch (NFR3-06).
	 *
	 * ## OPTIONS
	 *
	 * [<feature>]
	 * : Feature name, or "list".
	 *
	 * [<state>]
	 * : off, test_accounts or everyone.
	 *
	 * [--approved-by=<who>]
	 * : Required for everyone: who approved it and when.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lld-members feature list
	 *     wp lld-members feature food_tracker test_accounts
	 *     wp lld-members feature food_tracker everyone --approved-by="Owner, 14 Nov 2026"
	 *
	 * @param string[] $args  Positional args.
	 * @param array    $assoc Assoc args.
	 */
	public function feature( $args, $assoc ) {
		$feature = isset( $args[0] ) ? (string) $args[0] : 'list';
		if ( 'list' === $feature ) {
			$rows = array();
			foreach ( LLD_Members_Features::all() as $name => $row ) {
				$rows[] = array(
					'feature'     => $name,
					'state'       => $row['state'],
					'approved_by' => $row['approved_by'],
					'changed_at'  => $row['changed_at'],
					'what'        => $row['what'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'feature', 'state', 'approved_by', 'changed_at', 'what' ) );
			return;
		}
		if ( ! isset( $args[1] ) ) {
			WP_CLI::line( $feature . ': ' . LLD_Members_Features::state( $feature ) );
			return;
		}
		$result = LLD_Members_Features::set( $feature, (string) $args[1], isset( $assoc['approved-by'] ) ? (string) $assoc['approved-by'] : '' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( $feature . ' is now ' . $args[1] );
	}

	/**
	 * Show or set the test-account emails (members who see test_accounts features).
	 *
	 * ## OPTIONS
	 *
	 * [<emails>...]
	 * : Emails to set. Omit to list.
	 *
	 * @param string[] $args Positional args.
	 */
	public function test_accounts( $args ) {
		if ( ! $args ) {
			foreach ( LLD_Members_Features::test_account_emails() as $email ) {
				WP_CLI::line( $email );
			}
			return;
		}
		update_option( LLD_Members_Features::TEST_ACCOUNTS, array_values( array_map( 'strtolower', array_map( 'trim', $args ) ) ), true );
		WP_CLI::success( count( $args ) . ' test account(s) set.' );
	}

	/**
	 * Import a food file into the food table, or search it (P3-40, FR3-26, NFR3-15).
	 *
	 * Needs the food_tracker switch at test_accounts or everyone. USDA files are Foundation or
	 * SR Legacy JSON (or JSONL); Open Food Facts files are product JSON or JSONL. Packaged USDA
	 * foods are skipped. Every imported row keeps its source and attribution.
	 *
	 * ## OPTIONS
	 *
	 * <import|search>
	 * : What to do.
	 *
	 * [<text>]
	 * : For search: the text to search for.
	 *
	 * [--source=<source>]
	 * : For import: usda or off.
	 *
	 * [--file=<path>]
	 * : For import: the file to read.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lld-members food import --source=usda --file=foundation.json
	 *     wp lld-members food import --source=off --file=products.jsonl
	 *     wp lld-members food search banana
	 *
	 * @param string[] $args  Positional args.
	 * @param array    $assoc Assoc args.
	 */
	public function food( $args, $assoc ) {
		require_once __DIR__ . '/food-import/load.php';
		if ( ! LLD_Members_Food_Switch::enabled() ) {
			WP_CLI::error( 'The food_tracker switch is off. Nothing was done.' );
		}
		$what = isset( $args[0] ) ? (string) $args[0] : '';
		try {
			if ( 'import' === $what ) {
				$source = isset( $assoc['source'] ) ? (string) $assoc['source'] : '';
				$file   = isset( $assoc['file'] ) ? (string) $assoc['file'] : '';
				if ( ! in_array( $source, array( 'usda', 'off' ), true ) || '' === $file ) {
					WP_CLI::error( 'Use --source=usda|off --file=<path>.' );
				}
				LLD_Members_Food_Schema::install();
				$summary = LLD_Members_Food_Import::path( $file, LLD_Members_Food_Sources::get( $source ), new LLD_Members_Food_Wp_Store() );
				WP_CLI::line( wp_json_encode( $summary ) );
				return;
			}
			if ( 'search' === $what ) {
				$store = new LLD_Members_Food_Wp_Store();
				WP_CLI::line( wp_json_encode( $store->search( isset( $args[1] ) ? (string) $args[1] : '' ) ) );
				return;
			}
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::error( 'Use "import" or "search".' );
	}
}
