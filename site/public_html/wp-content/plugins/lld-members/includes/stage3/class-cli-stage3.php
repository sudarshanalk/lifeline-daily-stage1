<?php
/**
 * Stage 3 WP-CLI commands: feature switches, test accounts, the food database and the Stripe check.
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
		WP_CLI::add_command( 'lld-members stripe', array( __CLASS__, 'stripe' ) );
		WP_CLI::add_command( 'lld-members insights', array( __CLASS__, 'insights' ) );
	}

	/**
	 * Run the decision engine (P3-34, FR3-17) for every member with Stage 3 data, or for one member.
	 *
	 * Needs the insights switch at test_accounts or everyone. Prints only rule names and outcomes: never a
	 * reading, a number or a message (NFR3-16).
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : What to do.
	 *
	 * [--member=<id>]
	 * : One member number. Omit for every member with Stage 3 data.
	 *
	 * [--date=<Y-m-d>]
	 * : Run as if it were this local date (tests on staging).
	 *
	 * [--dry-run]
	 * : Evaluate and report, write nothing.
	 *
	 * [--check-in]
	 * : Evaluate the weekly check-in adjustments too.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lld-members insights run --dry-run
	 *     wp lld-members insights run --member=12 --date=2026-11-10
	 *
	 * @param string[] $args  Positional args.
	 * @param array    $assoc Assoc args.
	 */
	public function insights( $args, $assoc ) {
		if ( 'run' !== ( isset( $args[0] ) ? (string) $args[0] : '' ) ) {
			WP_CLI::error( 'Use "run".' );
		}
		if ( 'off' === LLD_Members_Features::state( 'insights' ) ) {
			WP_CLI::error( 'The insights switch is off. Nothing was done.' );
		}
		$options = array(
			'dry_run'     => ! empty( $assoc['dry-run'] ),
			'at_check_in' => ! empty( $assoc['check-in'] ),
		);
		$date    = isset( $assoc['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $assoc['date'] ) ? (string) $assoc['date'] : null;
		$members = isset( $assoc['member'] ) ? array( (int) $assoc['member'] ) : array();
		if ( ! $members ) {
			global $wpdb;
			foreach ( array( 'readings', 'goals', 'food_log' ) as $suffix ) {
				$found = $wpdb->get_col( 'SELECT DISTINCT member_id FROM ' . LLD_Members_Stage3_Schema::table( $suffix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
				$members = array_merge( $members, array_map( 'intval', is_array( $found ) ? $found : array() ) );
			}
			$members = array_values( array_unique( $members ) );
		}
		$rows = array();
		foreach ( $members as $member_id ) {
			$s      = LLD_Members_Insights_Runner::run_member( $member_id, $date, $options );
			$rows[] = array(
				'member'      => $member_id,
				'date'        => isset( $s['date'] ) ? $s['date'] : '',
				'status'      => $s['status'],
				'safety'      => isset( $s['safety'] ) ? implode( ' ', $s['safety'] ) : '',
				'today_step'  => isset( $s['today_step'] ) ? (string) $s['today_step'] : '',
				'insight'     => isset( $s['insight'] ) ? (string) $s['insight'] : '',
				'adjustments' => isset( $s['adjustments'] ) ? implode( ' ', $s['adjustments'] ) : '',
				'outcomes'    => isset( $s['outcomes'] ) ? implode( ' ', array_map( function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $s['outcomes'] ), $s['outcomes'] ) ) : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'member', 'date', 'status', 'safety', 'today_step', 'insight', 'adjustments', 'outcomes' ) );
		WP_CLI::success( count( $rows ) . ' member(s) ' . ( $options['dry_run'] ? 'evaluated (dry run)' : 'run' ) . '.' );
	}


	/**
	 * Check the Stripe connection (D2) without showing any key, or make the two prices tax-inclusive.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : check (default) or tax-inclusive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lld-members stripe check
	 *     wp lld-members stripe tax-inclusive
	 *
	 * @param string[] $args  Positional args.
	 * @param array    $assoc Assoc args.
	 */
	public function stripe( $args, $assoc ) {
		unset( $assoc );
		$action = isset( $args[0] ) ? (string) $args[0] : 'check';
		if ( 'tax-inclusive' === $action ) {
			foreach ( LLD_Members_Stripe_API::make_tax_inclusive() as $plan => $result ) {
				WP_CLI::line( $plan . ': ' . $result );
			}
			return;
		}
		$status = LLD_Members_Stripe_API::status();
		WP_CLI::line( 'mode: ' . $status['mode'] );
		WP_CLI::line( 'switch stripe_api: ' . $status['switch'] );
		WP_CLI::line( 'webhook signing (whsec_): ' . $status['webhook_secret'] );
		foreach ( $status['prices'] as $plan => $price ) {
			WP_CLI::line( $plan . ': ' . ( is_array( $price ) ? $price['id'] . ', ' . $price['amount'] . ' ' . $price['currency'] . ', tax ' . $price['tax_behavior'] : $price ) );
		}
	}

	/**
	 * Show or change a feature switch (NFR3-06).
	 *
	 * ## OPTIONS
	 *
	 * [<feature>]
	 * : Feature name, "list", or "all-on" / "all-off" (every feature always on, including later ones).
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
		if ( 'all-on' === $feature || 'all-off' === $feature ) {
			update_option( LLD_Members_Features::ALL_ON, 'all-on' === $feature ? 1 : 0, true );
			WP_CLI::success( 'all-on' === $feature ? 'Every feature is now on for everyone, including features added later.' : 'Each feature follows its own switch again.' );
			return;
		}
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
