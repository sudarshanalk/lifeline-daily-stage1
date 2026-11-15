<?php
/**
 * The admin panel's form handlers and the WordPress-admin menu (P3-87, P3-87b; FR3-72 to FR3-86, FR3-88, FR3-94).
 *
 * The admin pages themselves are in My account → Admin (LLD_Members_Admin_Front, decision of 6 Nov 2026). This class
 * keeps what both use: the admin-post.php handlers (every role check, reason, fresh code and log entry), the counts,
 * and the saved message with Undo. In the WordPress admin, one "Lifeline Daily content" menu holds the content
 * screens and the Stage 2 screens, which keep their addresses (admin.php?page=lld-payments and so on; the Stage 2
 * tests use them). The old addresses of the five Stage 3 pages take the Owner to the same page in My account
 * (TC-FR3-72-AS-03). No page shows a member's health data (NFR3-19).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, handlers and shared helpers.
 */
class LLD_Members_Admin_Pages {

	/** The top of the WordPress-admin content menu. */
	const SLUG = 'lld-approvals';

	/** Old WordPress-admin addresses of the Stage 3 pages => their page in My account. */
	const MOVED = array(
		'lld-overview'      => 'overview',
		'lld-members-admin' => 'members',
		'lld-features'      => 'features',
		'lld-activity'      => 'activity',
		'lld-helpers'       => 'helpers',
	);

	/** Stage 2 parents whose screens move under the one content menu (FR3-72). */
	const OLD_PARENTS = array( 'lld-payments', 'lld-approvals' );

	/** Stage 2 screens now in My account → Admin: hidden from the content menu; their addresses still work (Stage 2 tests). */
	const HIDDEN = array( 'lld-payments', 'lld-report', 'lld-group' );

	/** Order of the Stage 2 screens in the content menu. */
	const OLD_ORDER = array( 'lld-approvals', 'lld-matrix', 'lld-qa', 'lld-quotes', 'lld-preview', 'lld-payments', 'lld-report', 'lld-group' );

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 999 );
		add_action( 'admin_head', array( __CLASS__, 'head' ) );
		add_action( 'admin_init', array( __CLASS__, 'robots_header' ) );
		add_action( 'admin_init', array( __CLASS__, 'send_to_account' ), 1 );
		add_action( 'admin_post_lld_admin_member', array( __CLASS__, 'post_member' ) );
		add_action( 'admin_post_lld_admin_feature', array( __CLASS__, 'post_feature' ) );
		add_action( 'admin_post_lld_admin_tool', array( __CLASS__, 'post_tool' ) );
		add_action( 'admin_post_lld_admin_test_accounts', array( __CLASS__, 'post_test_accounts' ) );
		add_action( 'admin_post_lld_admin_helper', array( __CLASS__, 'post_helper' ) );
		add_action( 'admin_post_lld_admin_code', array( __CLASS__, 'post_code' ) );
	}

	// ----- WordPress-admin menu (FR3-72) -----

	/**
	 * One "Lifeline Daily content" menu after the Stage 2 classes added theirs. Each Stage 2 screen is registered again
	 * with its own callback, so its address stays the same. The Stage 3 pages stay registered (hidden) so their old
	 * addresses reach send_to_account() instead of WordPress's "not allowed" page.
	 */
	public static function menu() {
		global $submenu, $admin_page_hooks, $wp_filter;
		$moved = array();
		$other = array();
		foreach ( self::OLD_PARENTS as $parent ) {
			$items = (array) ( $submenu[ $parent ] ?? array() );
			if ( isset( $admin_page_hooks[ $parent ] ) && ! in_array( $parent, array_map( static function ( $i ) { return (string) $i[2]; }, $items ), true ) ) {
				// A parent with no first sub-item of its own (WordPress adds one only when it can): keep its screen too.
				array_unshift( $items, array( 'lld-payments' === $parent ? 'Payments' : 'Approvals', 'manage_options', $parent ) );
			}
			foreach ( $items as $item ) {
				$slug = (string) $item[2];
				if ( in_array( $slug, self::OLD_ORDER, true ) ) {
					$hook     = get_plugin_page_hookname( $slug, $parent );
					$callback = null;
					if ( isset( $wp_filter[ $hook ] ) ) {
						foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
							foreach ( $callbacks as $cb ) {
								$callback = $cb['function'];
								break 2;
							}
						}
					}
					if ( $callback ) {
						$moved[ $slug ] = array( 'title' => (string) $item[0], 'cap' => (string) $item[1], 'callback' => $callback );
					}
				} else {
					$other[] = $item; // Content lists (edit.php?post_type=…): core screens, kept as links.
				}
			}
			unset( $submenu[ $parent ] );
			remove_menu_page( $parent );
			unset( $admin_page_hooks[ $parent ] );
		}
		$top = isset( $moved[ self::SLUG ] ) ? $moved[ self::SLUG ]['callback'] : array( __CLASS__, 'to_account' );
		add_menu_page( 'Lifeline Daily content', 'Lifeline Daily content', 'manage_options', self::SLUG, $top, 'dashicons-heart', 3 );
		foreach ( self::OLD_ORDER as $slug ) {
			if ( isset( $moved[ $slug ] ) ) {
				$title = 'lld-payments' === $slug ? 'Payments' : ( self::SLUG === $slug ? 'Approvals' : $moved[ $slug ]['title'] );
				add_submenu_page( in_array( $slug, self::HIDDEN, true ) ? '' : self::SLUG, $title, $title, $moved[ $slug ]['cap'], $slug, $moved[ $slug ]['callback'] );
			}
		}
		foreach ( $other as $item ) {
			$submenu[ self::SLUG ][] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- moving the Content lists.
		}
		// The link to the admin pages in My account (FR3-87 AS-04).
		$submenu[ self::SLUG ][] = array( 'Admin (in My account)', LLD_Members_Admin_Roles::PANEL, LLD_Members_Admin_Front::url() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		foreach ( self::MOVED as $slug => $page ) {
			unset( $page );
			add_submenu_page( '', 'Lifeline Daily', 'Lifeline Daily', LLD_Members_Admin_Roles::PANEL, $slug, array( __CLASS__, 'to_account' ) );
		}
	}

	/**
	 * The old WordPress-admin address of a Stage 3 page takes the admin to the same page in My account (FR3-72 AS-03).
	 */
	public static function send_to_account() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a redirect for a page address.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! isset( self::MOVED[ $page ] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		$args = array();
		foreach ( array( 'member', 'q', 'plan', 'status', 'test', 'paged' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		wp_safe_redirect( LLD_Members_Admin_Front::url( self::MOVED[ $page ], $args ) );
		exit;
	}

	/**
	 * Callback for the hidden Stage 3 pages (send_to_account() normally runs first).
	 */
	public static function to_account() {
		echo '<div class="wrap"><p><a href="' . esc_url( LLD_Members_Admin_Front::url() ) . '">Open Admin in My account</a></p></div>';
	}

	/**
	 * noindex on every admin page, as a header (NFR3-22).
	 */
	public static function robots_header() {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * noindex meta, and the way back to My account → Admin in the WordPress admin (FR3-87 AS-04).
	 */
	public static function head() {
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
	}

	// ----- Shared bits -----

	/**
	 * Address of an admin page: Stage 3 pages live in My account → Admin.
	 *
	 * @param string $page Page slug (old WordPress-admin slug or My account page key).
	 * @param array  $args Query arguments.
	 */
	public static function url( $page, array $args = array() ) {
		if ( isset( self::MOVED[ $page ] ) ) {
			return LLD_Members_Admin_Front::url( self::MOVED[ $page ], $args );
		}
		if ( isset( LLD_Members_Admin_Front::PAGES[ $page ] ) ) {
			return LLD_Members_Admin_Front::url( $page, $args );
		}
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param string     $type    success or error.
	 * @param string     $message Text.
	 * @param array|null $undo    The form that undoes the change: action, key, on.
	 */
	private static function remember( $type, $message, $undo = null ) {
		set_transient( 'lld_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => (string) $message, 'undo' => $undo ), 120 );
	}

	/**
	 * The saved message for the current admin, once.
	 *
	 * @return array{type:string,message:string,undo:?array}|null
	 */
	public static function take_notice() {
		$key  = 'lld_admin_notice_' . get_current_user_id();
		$note = get_transient( $key );
		if ( ! is_array( $note ) ) {
			return null;
		}
		delete_transient( $key );
		if ( isset( $note[0] ) ) { // a message stored before this change
			$note = array( 'type' => $note[0], 'message' => $note[1], 'undo' => null );
		}
		return $note;
	}

	/**
	 * @param string|WP_Error $result Result.
	 * @param string          $back   Where to go.
	 * @param array|null      $undo   Undo form.
	 */
	private static function finish( $result, $back, $undo = null ) {
		if ( is_wp_error( $result ) ) {
			self::remember( 'error', $result->get_error_message() );
		} else {
			self::remember( 'success', (string) $result, $undo );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * @param string $action admin-post action.
	 * @param string $nonce  Nonce action.
	 * @param array  $fields Hidden fields.
	 * @param string $class  Extra class.
	 */
	public static function form_open( $action, $nonce, array $fields = array(), $class = '' ) {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= wp_nonce_field( $nonce, '_lldnonce', true, false );
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		return $html;
	}

	/**
	 * @param string $nonce Nonce action.
	 */
	private static function check_post( $nonce ) {
		if ( ! isset( $_POST['_lldnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_lldnonce'] ) ), $nonce ) ) {
			wp_die( 'This form has expired. Go back, reload the page and try again.', 403 );
		}
		if ( ! current_user_can( LLD_Members_Admin_Roles::PANEL ) ) {
			wp_die( 'You can\'t use the Lifeline Daily admin panel.', 403 );
		}
	}

	/**
	 * @param string $key Field.
	 */
	private static function posted( $key ) {
		return isset( $_POST[ $key ] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_post() first.
	}

	/**
	 * A switch change shows at once to signed-out visitors too: purge the page cache (FR3-94). On staging the
	 * LiteSpeed plugin's own purge does not clear the server's page cache, but this response header does.
	 */
	private static function purge_cache() {
		do_action( 'litespeed_purge_all' );
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' );
		}
	}

	// ----- Overview counts (FR3-73) -----

	/**
	 * Counts only: never a health figure, even as a total. Test members are left out and counted apart (FR3-91).
	 *
	 * @return array<string,int|string>
	 */
	public static function counts() {
		global $wpdb;
		$m     = LLD_Members_Schema::table( 'member' );
		$s     = LLD_Members_Schema::table( 'subscription' );
		$today = gmdate( 'Y-m-d' );
		$tests = LLD_Members_Features::test_account_emails();
		$not   = '';
		$sub   = '';
		if ( $tests ) {
			$list = implode( ',', array_map( static function ( $e ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $e );
			}, $tests ) );
			$not  = " AND LOWER(email) NOT IN ({$list})";
			$sub  = " AND member_id NOT IN (SELECT id FROM {$m} WHERE LOWER(email) IN ({$list}))";
		}
		$real = array( 'test' => 'no' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and prepared email lists.
		$c = array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE 1=1{$not}" ),
			'new_week'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE joined_on >= %s{$not}", gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) ) ),
			'seen_7'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE last_signed_in_at >= %s{$not}", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ),
			'seen_30'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE last_signed_in_at >= %s{$not}", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ),
			'premium'   => LLD_Members_Admin_Members::count( array( 'plan' => 'premium' ) + $real ),
			'paused'    => LLD_Members_Admin_Members::count( array( 'status' => 'paused' ) + $real ),
			'cancelled' => LLD_Members_Admin_Members::count( array( 'status' => 'cancelled' ) + $real ),
			'blocked'   => LLD_Members_Admin_Members::count( array( 'status' => 'blocked' ) + $real ),
			'monthly'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE plan = 'monthly' AND (access_state = 'Premium' OR (access_state = 'cancelled' AND period_ends_on >= %s)){$sub}", $today ) ),
			'yearly'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE plan = 'yearly' AND (access_state = 'Premium' OR (access_state = 'cancelled' AND period_ends_on >= %s)){$sub}", $today ) ),
			'failed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} WHERE stripe_state = 'past_due'{$sub}" ),
			'test'      => LLD_Members_Admin_Members::count( array( 'test' => 'yes' ) ),
			'approvals' => self::waiting_approvals(),
		);
		// phpcs:enable
		$c['free'] = max( 0, $c['total'] - $c['premium'] );
		return $c;
	}

	/**
	 * Content waiting for approval: the same list the Approvals screen shows.
	 */
	private static function waiting_approvals() {
		if ( ! class_exists( 'LLD_Members_Content' ) || ! method_exists( 'LLD_Members_Content', 'pending' ) ) {
			return 0;
		}
		$method = new ReflectionMethod( 'LLD_Members_Content', 'pending' );
		$method->setAccessible( true );
		return count( (array) $method->invoke( null ) );
	}

	// ----- Switches (FR3-74, FR3-88, FR3-89) -----

	/**
	 * A feature or calculator toggle: on or off, saved at once, the reason optional, with Undo (FR3-74 AS-02, AS-07).
	 * No typed name and no fresh code (decision of 6 Nov 2026). The command line keeps "test accounts only".
	 */
	public static function post_feature() {
		self::check_post( 'lld_admin_feature' );
		$feature = sanitize_key( self::posted( 'key' ) );
		$back    = LLD_Members_Admin_Front::url( isset( LLD_Members_Admin_Front::CALCULATORS[ $feature ] ) || 'calculators_new' === $feature ? 'tools' : 'features' ) . '#tool-' . $feature;
		if ( ! current_user_can( 'manage_options' ) ) {
			self::finish( new WP_Error( 'lld_admin_role', 'Only the Owner can change a switch.' ), $back );
		}
		$name = self::plain_name( $feature );
		$row  = LLD_Members_Features::stored( $feature );
		if ( '1' === self::posted( 'undo' ) ) {
			// Undo puts the switch back exactly as it was, including "follows the group" and "test accounts only".
			$prev = json_decode( self::posted( 'prev' ), true );
			$done = LLD_Members_Features::restore_row( $feature, is_array( $prev ) ? $prev : null, 'Undo' );
			if ( is_wp_error( $done ) ) {
				self::finish( $done, $back );
			}
			self::purge_cache();
			self::finish( $name . ' is ' . ( 'off' === LLD_Members_Features::state( $feature ) ? 'off' : 'on' ) . ' again.', $back );
		}
		$on     = '1' === self::posted( 'on' );
		$before = 'off' !== LLD_Members_Features::state( $feature );
		if ( $before === $on ) {
			self::finish( $name . ' is already ' . ( $on ? 'on' : 'off' ) . '.', $back ); // nothing changed, nothing logged
		}
		$reason = self::posted( 'reason' );
		$who    = wp_get_current_user()->display_name . ', ' . gmdate( 'j M Y' );
		$done   = LLD_Members_Features::set( $feature, $on ? 'everyone' : 'off', $on ? $who . ( '' !== $reason ? ': ' . $reason : '' ) : '', $reason );
		if ( is_wp_error( $done ) ) {
			self::finish( $done, $back );
		}
		self::purge_cache();
		self::finish( $name . ' is ' . ( $on ? 'on' : 'off' ) . '.', $back, array( 'action' => 'lld_admin_feature', 'key' => $feature, 'prev' => (string) wp_json_encode( $row ) ) );
	}

	/**
	 * A standalone tool's toggle: the same tools/status.json switch as `wp lld status set` (FR1-15, FR3-89). Turning a
	 * tool back on uses the version it had when it was last approved and active, and only while that file is deployed.
	 */
	public static function post_tool() {
		self::check_post( 'lld_admin_tool' );
		$slug = sanitize_key( self::posted( 'key' ) );
		$back = LLD_Members_Admin_Front::url( 'tools' ) . '#tool-' . $slug;
		if ( ! current_user_can( 'manage_options' ) ) {
			self::finish( new WP_Error( 'lld_admin_role', 'Only the Owner can change a switch.' ), $back );
		}
		if ( ! isset( LLD_Members_Admin_Front::STANDALONE[ $slug ] ) || ! function_exists( 'lld_tools_write_status' ) ) {
			self::finish( new WP_Error( 'lld_admin_tool', 'Unknown tool.' ), $back );
		}
		$undo   = '1' === self::posted( 'undo' );
		$before = 'withdrawn' !== lld_tools_tool_status( $slug );
		$on     = $undo ? '1' === self::posted( 'prev' ) : '1' === self::posted( 'on' );
		$name   = LLD_Members_Admin_Front::STANDALONE[ $slug ][0];
		if ( $before === $on ) {
			self::finish( $name . ' is already ' . ( $on ? 'on' : 'off' ) . '.', $back ); // nothing changed, nothing logged
		}
		$reason = $undo ? 'Undo' : self::posted( 'reason' );
		$entry  = $on ? lld_tools_reactivate( $slug ) : lld_tools_write_status( $slug, 'withdrawn' );
		if ( is_string( $entry ) ) {
			self::finish( new WP_Error( 'lld_admin_tool', $entry ), $back );
		}
		LLD_Members_Features::record( 'tool_' . $slug, $before ? 'active' : 'withdrawn', $on ? 'active' : 'withdrawn', $reason );
		LLD_Members_Admin_Log::add( $on ? 'feature_on' : 'feature_off', array( 'target' => $name, 'before' => $before ? 'on' : 'off', 'after' => $on ? 'on' : 'off', 'reason' => '' !== $reason ? $reason : 'no reason given' ) );
		self::purge_cache();
		self::finish( $name . ' is ' . ( $on ? 'on' : 'off' ) . ( $undo ? ' again.' : '.' ), $back, $undo ? null : array( 'action' => 'lld_admin_tool', 'key' => $slug, 'prev' => $before ? '1' : '' ) );
	}

	/**
	 * A switch's plain name (NFR3-24 AS-01).
	 *
	 * @param string $key Feature key.
	 */
	public static function plain_name( $key ) {
		if ( 'calculators_new' === $key ) {
			return 'All eight calculators';
		}
		if ( isset( LLD_Members_Admin_Front::CALCULATORS[ $key ] ) ) {
			return LLD_Members_Admin_Front::CALCULATORS[ $key ][0];
		}
		foreach ( LLD_Members_Admin_Front::FEATURE_GROUPS as $items ) {
			if ( isset( $items[ $key ] ) ) {
				return $items[ $key ][0];
			}
		}
		$kinds = array( 'sign_in_code' => 'Sign-in code', 'confirm' => 'Email confirmation', 'welcome' => 'Welcome email', 'calculators_new' => 'All eight calculators' );
		return $kinds[ $key ] ?? ( 0 === strpos( (string) $key, 'calc_' ) || false !== strpos( (string) $key, '_' ) ? ucfirst( str_replace( '_', ' ', (string) $key ) ) : 'The feature' );
	}

	/**
	 * Add or remove one test-account email, with a reason (FR3-74 AS-04, FR3-86).
	 */
	public static function post_test_accounts() {
		self::check_post( 'lld_admin_test_accounts' );
		$back = LLD_Members_Admin_Front::url( 'features' ) . '#test-accounts';
		if ( ! current_user_can( 'manage_options' ) ) {
			self::finish( new WP_Error( 'lld_admin_role', 'Only the Owner can change the test-account list.' ), $back );
		}
		$reason = self::posted( 'reason' );
		if ( '' === $reason ) {
			self::finish( new WP_Error( 'lld_admin_reason', 'Give a reason. It is kept in the activity log.' ), $back );
		}
		$before = LLD_Members_Features::test_account_emails();
		$email  = strtolower( sanitize_email( self::posted( 'email' ) ) );
		$do     = sanitize_key( self::posted( 'do' ) );
		if ( 'add' === $do || 'remove' === $do ) {
			if ( ! is_email( $email ) ) {
				self::finish( new WP_Error( 'lld_admin_email', 'Enter an email address.' ), $back );
			}
			$list = array_values( array_diff( $before, array( $email ) ) );
			if ( 'add' === $do ) {
				$list[] = $email;
			}
		} else {
			$list = preg_split( '/[\s,]+/', self::posted( 'emails' ) );
		}
		$after = LLD_Members_Features::save_test_accounts( $list );
		LLD_Members_Admin_Log::add( 'test_accounts_set', array( 'target' => 'test-account list', 'before' => count( $before ) . ' emails', 'after' => count( $after ) . ' emails', 'reason' => $reason ) );
		self::finish( 'add' === $do ? 'Test account added.' : ( 'remove' === $do ? 'Removed from test accounts.' : 'Test-account list saved (' . count( $after ) . ' emails).' ), $back );
	}

	// ----- Members (FR3-75 to FR3-85, FR3-91, FR3-92) -----

	public static function post_member() {
		self::check_post( 'lld_admin_member' );
		$member = (int) self::posted( 'member' );
		$do     = sanitize_key( self::posted( 'do' ) );
		$back   = LLD_Members_Admin_Front::url( 'members', array( 'member' => $member ) );
		$result = LLD_Members_Admin_Members::run(
			$do,
			$member,
			array(
				'reason'  => self::posted( 'reason' ),
				'note'    => self::posted( 'note' ),
				'months'  => self::posted( 'months' ),
				'ends_on' => self::posted( 'ends_on' ),
				'when'    => self::posted( 'when' ),
				'kind'    => self::posted( 'kind' ),
				'email'   => self::posted( 'email' ),
				'code'    => self::posted( 'code' ),
				'on'      => self::posted( 'on' ),
				'typed'   => self::posted( 'typed' ),
			)
		);
		if ( 'delete' === $do && ! is_wp_error( $result ) ) {
			$back = LLD_Members_Admin_Front::url( 'members' );
		}
		self::finish( $result, $back );
	}

	public static function post_code() {
		self::check_post( 'lld_admin_code' );
		$back = wp_validate_redirect( esc_url_raw( wp_unslash( (string) ( $_POST['back'] ?? '' ) ) ), LLD_Members_Admin_Front::url() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_post() first.
		$sent = LLD_Members_Admin_Security::send_fresh_code();
		self::finish( is_wp_error( $sent ) ? $sent : 'We\'ve emailed you a 6-digit code. It expires in 10 minutes.', $back );
	}

	// ----- Helpers (FR3-71 AS-03) -----

	public static function post_helper() {
		self::check_post( 'lld_admin_helper' );
		$back = LLD_Members_Admin_Front::url( 'helpers' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::finish( new WP_Error( 'lld_admin_role', 'Only the Owner can manage helpers.' ), $back );
		}
		$do = sanitize_key( self::posted( 'do' ) );
		if ( 'reset' === $do ) {
			$user = get_userdata( (int) self::posted( 'user' ) );
			if ( ! $user || ! LLD_Members_Admin_Roles::is_support( $user ) ) {
				self::finish( new WP_Error( 'lld_admin_helper', 'That account is not a Support helper.' ), $back );
			}
			wp_new_user_notification( (int) $user->ID, null, 'user' );
			self::finish( 'Set-password link sent.', $back );
		}
		$reason = self::posted( 'reason' );
		if ( '' === $reason ) {
			self::finish( new WP_Error( 'lld_admin_reason', 'Give a reason. It is kept in the activity log.' ), $back );
		}
		$code = LLD_Members_Admin_Security::fresh_code_ok( self::posted( 'code' ) );
		if ( is_wp_error( $code ) ) {
			self::finish( $code, $back );
		}
		if ( 'add' === $do ) {
			$done = LLD_Members_Admin_Roles::add_helper( self::posted( 'email' ), self::posted( 'name' ), $reason );
			self::finish( is_wp_error( $done ) ? $done : 'Helper added. They have been emailed a link to set their password.', $back );
		}
		if ( 'remove' === $do ) {
			$done = LLD_Members_Admin_Roles::remove_helper( (int) self::posted( 'user' ), $reason );
			self::finish( is_wp_error( $done ) ? $done : 'Helper removed.', $back );
		}
		self::finish( new WP_Error( 'lld_admin_helper', 'Unknown action.' ), $back );
	}
}
