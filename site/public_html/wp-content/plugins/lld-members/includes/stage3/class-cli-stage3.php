<?php
/**
 * Stage 3 WP-CLI commands: feature switches and test accounts.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp lld-members feature …` and `wp lld-members test-accounts …`.
 */
class LLD_Members_Stage3_CLI {

	public static function register() {
		WP_CLI::add_command( 'lld-members feature', array( __CLASS__, 'feature' ) );
		WP_CLI::add_command( 'lld-members test-accounts', array( __CLASS__, 'test_accounts' ) );
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
}
