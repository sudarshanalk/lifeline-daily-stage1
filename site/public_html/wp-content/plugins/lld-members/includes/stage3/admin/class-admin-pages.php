<?php
/**
 * The Lifeline Daily admin panel pages (P3-87; FR3-72 to FR3-86, NFR3-19, NFR3-22).
 *
 * One "Lifeline Daily" menu in wp-admin holds the new Overview, Members, Features, Activity log and Helpers pages
 * and the existing Payments, Content, Approvals, Safety matrix, Live Q&A, Testimonials, Weekly report, Group
 * removals and Preview screens, which keep their addresses (admin.php?page=lld-payments and so on). A Support
 * helper sees only Overview and Members in the menu. Every page is marked noindex, loads no Google Analytics, and
 * fits a phone from 360 pixels wide. No page shows a member's health data (NFR3-19).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, page rendering and form handlers.
 */
class LLD_Members_Admin_Pages {

	const SLUG = 'lld-overview';

	/** The panel's own pages. */
	const PAGES = array( 'lld-overview', 'lld-members-admin', 'lld-features', 'lld-activity', 'lld-helpers' );

	/** Stage 2 parents whose screens move under the one menu (FR3-72). */
	const OLD_PARENTS = array( 'lld-payments', 'lld-approvals' );

	/** Order of the moved Stage 2 screens. */
	const OLD_ORDER = array( 'lld-payments', 'lld-approvals', 'lld-matrix', 'lld-qa', 'lld-quotes', 'lld-report', 'lld-group', 'lld-preview' );

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 999 );
		add_action( 'admin_head', array( __CLASS__, 'head' ) );
		add_action( 'admin_init', array( __CLASS__, 'robots_header' ) );
		add_action( 'admin_post_lld_admin_member', array( __CLASS__, 'post_member' ) );
		add_action( 'admin_post_lld_admin_feature', array( __CLASS__, 'post_feature' ) );
		add_action( 'admin_post_lld_admin_test_accounts', array( __CLASS__, 'post_test_accounts' ) );
		add_action( 'admin_post_lld_admin_helper', array( __CLASS__, 'post_helper' ) );
		add_action( 'admin_post_lld_admin_code', array( __CLASS__, 'post_code' ) );
	}

	// ----- Menu (FR3-72) -----

	/**
	 * Build the one menu after the Stage 2 classes added theirs. Each moved screen is registered again under the new
	 * menu with its own callback, so its address stays the same.
	 */
	public static function menu() {
		global $submenu, $admin_page_hooks, $wp_filter;
		add_menu_page( 'Lifeline Daily', 'Lifeline Daily', LLD_Members_Admin_Roles::PANEL, self::SLUG, array( __CLASS__, 'overview_page' ), 'dashicons-heart', 3 );
		add_submenu_page( self::SLUG, 'Overview', 'Overview', LLD_Members_Admin_Roles::PANEL, self::SLUG, array( __CLASS__, 'overview_page' ) );
		add_submenu_page( self::SLUG, 'Members', 'Members', LLD_Members_Admin_Roles::PANEL, 'lld-members-admin', array( __CLASS__, 'members_page' ) );
		// Support can open Features (read only) and their own activity, but the menu shows them only Overview and Members.
		$support = LLD_Members_Admin_Roles::is_support();
		// For Support they are registered without a parent: reachable by address, not listed (FR3-72 AS-03).
		$parent = $support ? '' : self::SLUG;
		add_submenu_page( $parent, 'Features', 'Features', LLD_Members_Admin_Roles::PANEL, 'lld-features', array( __CLASS__, 'features_page' ) );
		add_submenu_page( $parent, 'Activity log', 'Activity log', LLD_Members_Admin_Roles::PANEL, 'lld-activity', array( __CLASS__, 'activity_page' ) );
		add_submenu_page( self::SLUG, 'Helpers', 'Helpers', 'manage_options', 'lld-helpers', array( __CLASS__, 'helpers_page' ) );

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
		foreach ( self::OLD_ORDER as $slug ) {
			if ( isset( $moved[ $slug ] ) ) {
				$title = 'lld-payments' === $slug ? 'Payments' : ( 'lld-approvals' === $slug ? 'Approvals' : $moved[ $slug ]['title'] );
				add_submenu_page( self::SLUG, $title, $title, $moved[ $slug ]['cap'], $slug, $moved[ $slug ]['callback'] );
			}
		}
		foreach ( $other as $item ) {
			$submenu[ self::SLUG ][] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- moving the Content lists.
		}
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
	 * noindex meta and the panel's styles for phones (NFR3-22).
	 */
	public static function head() {
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, self::PAGES, true ) ) {
			return;
		}
		echo '<style>
.lld-admin{max-width:1100px}
.lld-admin *{box-sizing:border-box}
.lld-admin .lld-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:12px 0 24px}
.lld-admin .lld-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px}
.lld-admin .lld-card strong{display:block;font-size:24px;line-height:1.2;margin-bottom:4px}
.lld-admin .lld-scroll{overflow-x:auto;max-width:100%}
.lld-admin table{width:100%;border-collapse:collapse}
.lld-admin input[type=text],.lld-admin input[type=email],.lld-admin input[type=search],.lld-admin input[type=date],.lld-admin select,.lld-admin textarea{max-width:100%;width:100%}
.lld-admin .lld-box{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px 16px;margin:0 0 16px}
.lld-admin .lld-box h2{margin-top:4px}
.lld-admin dl.lld-facts{display:grid;grid-template-columns:minmax(120px,max-content) 1fr;gap:6px 16px;margin:0}
.lld-admin dl.lld-facts dt{font-weight:600}
.lld-admin dl.lld-facts dd{margin:0;overflow-wrap:anywhere}
.lld-admin .lld-hint{color:#50575e;margin:4px 0 8px}
.lld-admin .lld-row{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.lld-admin .lld-row > *{flex:1 1 180px}
.lld-admin .lld-row > .button{flex:0 0 auto}
.lld-admin label{display:block;font-weight:600;margin:6px 0 2px}
.lld-admin .lld-danger{border-color:#d63638}
@media (max-width:782px){
.lld-admin table.lld-stack thead{display:none}
.lld-admin table.lld-stack tr{display:block;border-bottom:1px solid #dcdcde;padding:6px 0}
.lld-admin table.lld-stack td{display:block;padding:2px 8px;overflow-wrap:anywhere}
.lld-admin table.lld-stack td[data-label]::before{content:attr(data-label) ": ";font-weight:600}
}
</style>' . "\n";
	}

	// ----- Shared bits -----

	/**
	 * @param string $page Page slug.
	 * @param array  $args Query arguments.
	 */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param string $type    success or error.
	 * @param string $message Text.
	 */
	private static function remember( $type, $message ) {
		set_transient( 'lld_admin_notice_' . get_current_user_id(), array( $type, (string) $message ), 120 );
	}

	private static function notice() {
		$key  = 'lld_admin_notice_' . get_current_user_id();
		$note = get_transient( $key );
		if ( ! is_array( $note ) ) {
			return '';
		}
		delete_transient( $key );
		return '<div class="notice notice-' . ( 'error' === $note[0] ? 'error' : 'success' ) . '" role="' . ( 'error' === $note[0] ? 'alert' : 'status' ) . '"><p>' . esc_html( (string) $note[1] ) . '</p></div>';
	}

	/**
	 * @param string|WP_Error $result Result.
	 * @param string          $back   Where to go.
	 */
	private static function finish( $result, $back ) {
		if ( is_wp_error( $result ) ) {
			self::remember( 'error', $result->get_error_message() );
		} else {
			self::remember( 'success', (string) $result );
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
	private static function form_open( $action, $nonce, array $fields = array(), $class = '' ) {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= wp_nonce_field( $nonce, '_lldnonce', true, false );
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		return $html;
	}

	/**
	 * @param string $id       Field id.
	 * @param string $label    Label.
	 * @param string $required Required attribute.
	 */
	private static function reason_field( $id, $label = 'Reason (kept in the activity log)', $required = ' required' ) {
		return '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input type="text" id="' . esc_attr( $id ) . '" name="reason" maxlength="300"' . $required . '>';
	}

	/**
	 * The fresh-code field, with a separate "Email me a code" form (NFR3-20).
	 *
	 * @param string $id Field id.
	 */
	private static function code_field( $id ) {
		return '<label for="' . esc_attr( $id ) . '">Fresh code from your email</label><input type="text" id="' . esc_attr( $id ) . '" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>';
	}

	/**
	 * @param string $back Return address.
	 */
	private static function code_form( $back ) {
		return self::form_open( 'lld_admin_code', 'lld_admin_code', array( 'back' => $back ) ) . '<p class="lld-hint">This step needs a fresh code. <button class="button">Email me a code</button></p></form>';
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

	private static function start( $title ) {
		echo '<div class="wrap lld-admin"><h1>' . esc_html( $title ) . '</h1>';
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice().
	}

	// ----- Overview (FR3-73) -----

	/**
	 * Counts only: never a health figure, even as a total.
	 *
	 * @return array<string,int|string>
	 */
	public static function counts() {
		global $wpdb;
		$m     = LLD_Members_Schema::table( 'member' );
		$s     = LLD_Members_Schema::table( 'subscription' );
		$today = gmdate( 'Y-m-d' );
		$c     = array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'new_week'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE joined_on >= %s", gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'seen_7'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE last_signed_in_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'seen_30'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE last_signed_in_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'premium'   => LLD_Members_Admin_Members::count( array( 'plan' => 'premium' ) ),
			'paused'    => LLD_Members_Admin_Members::count( array( 'status' => 'paused' ) ),
			'cancelled' => LLD_Members_Admin_Members::count( array( 'status' => 'cancelled' ) ),
			'blocked'   => LLD_Members_Admin_Members::count( array( 'status' => 'blocked' ) ),
			'monthly'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE plan = 'monthly' AND (access_state = 'Premium' OR (access_state = 'cancelled' AND period_ends_on >= %s))", $today ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'yearly'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE plan = 'yearly' AND (access_state = 'Premium' OR (access_state = 'cancelled' AND period_ends_on >= %s))", $today ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'failed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} WHERE stripe_state = 'past_due'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'approvals' => self::waiting_approvals(),
		);
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

	public static function overview_page() {
		if ( ! current_user_can( LLD_Members_Admin_Roles::PANEL ) ) {
			return;
		}
		$owner = LLD_Members_Admin_Roles::is_owner();
		$c     = self::counts();
		self::start( 'Lifeline Daily' );
		echo '<p class="lld-hint">Counts only. Members\' health details are never shown in the admin panel.</p>';
		$cards = array(
			'total'     => 'Members',
			'new_week'  => 'New this week',
			'seen_7'    => 'Signed in, last 7 days',
			'seen_30'   => 'Signed in, last 30 days',
			'free'      => 'Free',
			'premium'   => 'Premium',
			'paused'    => 'Paused',
			'cancelled' => 'Cancelled',
			'blocked'   => 'Blocked',
		);
		if ( $owner ) {
			$cards += array(
				'monthly'   => 'Monthly plans',
				'yearly'    => 'Yearly plans',
				'failed'    => 'Open failed payments',
				'approvals' => 'Content waiting for approval',
			);
		}
		echo '<h2>Members</h2><div class="lld-cards">';
		foreach ( $cards as $key => $label ) {
			echo '<div class="lld-card" data-count="' . esc_attr( $key ) . '"><strong>' . (int) $c[ $key ] . '</strong>' . esc_html( $label ) . '</div>';
		}
		echo '<div class="lld-card" data-count="qa"><strong>–</strong>Unanswered Live Q&amp;A questions: questions are asked in the live session, not stored on the site</div>';
		echo '</div>';

		echo '<h2>Features</h2><div class="lld-scroll"><table class="widefat striped lld-stack"><thead><tr><th scope="col">Feature</th><th scope="col">State</th></tr></thead><tbody>';
		foreach ( LLD_Members_Features::all() as $name => $row ) {
			echo '<tr><td data-label="Feature">' . esc_html( $name ) . '</td><td data-label="State">' . esc_html( self::state_label( $row['state'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div><p><a href="' . esc_url( self::url( 'lld-features' ) ) . '">Open Features</a></p>';

		if ( $owner ) {
			echo '<h2>Last 10 admin actions</h2>';
			self::log_table( LLD_Members_Admin_Log::visible( 10 ) );
			echo '<p><a href="' . esc_url( self::url( 'lld-activity' ) ) . '">Open the activity log</a></p>';
		} else {
			echo '<p><a href="' . esc_url( self::url( 'lld-members-admin' ) ) . '">Find a member</a> · <a href="' . esc_url( self::url( 'lld-activity' ) ) . '">Your own actions</a></p>';
		}
		echo '</div>';
	}

	/**
	 * @param string $state off, test_accounts or everyone.
	 */
	private static function state_label( $state ) {
		$labels = array(
			'off'           => 'Off',
			'test_accounts' => 'Test accounts only',
			'everyone'      => 'On for everyone',
		);
		return $labels[ $state ] ?? $state;
	}

	// ----- Activity log (FR3-86) -----

	/**
	 * @param object[] $rows Log rows.
	 */
	private static function log_table( array $rows ) {
		if ( ! $rows ) {
			echo '<p>No actions yet.</p>';
			return;
		}
		echo '<div class="lld-scroll"><table class="widefat striped lld-stack"><thead><tr>';
		foreach ( array( 'When (UTC)', 'Who', 'What', 'Member', 'Item', 'Before', 'After', 'Reason' ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$cells = LLD_Members_Admin_Log::csv_row( $row );
			echo '<tr>';
			$labels = array( 'When', 'Who', 'What', 'Member', 'Item', 'Before', 'After', 'Reason' );
			foreach ( array_slice( $cells, 1 ) as $i => $cell ) {
				echo '<td data-label="' . esc_attr( $labels[ $i ] ) . '">' . esc_html( ltrim( $cell, "'" ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function activity_page() {
		if ( ! current_user_can( LLD_Members_Admin_Roles::PANEL ) ) {
			return;
		}
		$owner = LLD_Members_Admin_Roles::is_owner();
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::start( $owner ? 'Activity log' : 'Your actions' );
		echo '<p class="lld-hint">' . ( $owner ? 'Every admin action, from both roles and the command line. Entries can\'t be changed or deleted, and are kept for 2 years.' : 'The actions you have taken in the admin panel.' ) . '</p>';
		if ( $owner ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lld_admin_log_csv' ), 'lld_admin_log_csv' ) ) . '">Download as CSV</a></p>';
		}
		$rows = LLD_Members_Admin_Log::visible( 50, ( $paged - 1 ) * 50 );
		self::log_table( $rows );
		echo '<p>';
		if ( $paged > 1 ) {
			echo '<a href="' . esc_url( self::url( 'lld-activity', array( 'paged' => $paged - 1 ) ) ) . '">Newer</a> ';
		}
		if ( count( $rows ) === 50 ) {
			echo '<a href="' . esc_url( self::url( 'lld-activity', array( 'paged' => $paged + 1 ) ) ) . '">Older</a>';
		}
		echo '</p></div>';
	}

	// ----- Features (FR3-74) -----

	public static function features_page() {
		if ( ! current_user_can( LLD_Members_Admin_Roles::PANEL ) ) {
			return;
		}
		$owner = LLD_Members_Admin_Roles::is_owner();
		$back  = self::url( 'lld-features' );
		self::start( 'Features' );
		echo '<p class="lld-hint">Every feature is on. Use this page only to turn a feature off in an emergency, or to limit it to test accounts while it is fixed. Members stop seeing a feature on their next page load.</p>';
		if ( ! $owner ) {
			echo '<p class="lld-hint"><strong>You can view this page. Only the Owner can change it.</strong></p>';
		}
		foreach ( LLD_Members_Features::all() as $name => $row ) {
			echo '<div class="lld-box" id="feature-' . esc_attr( $name ) . '"><h2>' . esc_html( $name ) . '</h2>';
			echo '<p>' . esc_html( $row['what'] ) . '</p><p><strong>Now: ' . esc_html( self::state_label( $row['state'] ) ) . '</strong></p>';
			if ( $owner ) {
				if ( 'off' !== $row['state'] ) {
					echo self::form_open( 'lld_admin_feature', 'lld_admin_feature', array( 'feature' => $name, 'state' => 'off' ), 'lld-row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<div>' . self::reason_field( 'off-' . $name, 'Reason for turning it off' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<button class="button button-primary" onclick="return confirm(\'Turn ' . esc_js( $name ) . ' off for everyone now?\')">Turn off</button></form>';
				}
				if ( 'test_accounts' !== $row['state'] ) {
					echo self::form_open( 'lld_admin_feature', 'lld_admin_feature', array( 'feature' => $name, 'state' => 'test_accounts' ), 'lld-row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<div>' . self::reason_field( 'test-' . $name, 'Reason for limiting it to test accounts' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<button class="button">Limit to test accounts</button></form>';
				}
				if ( 'everyone' !== $row['state'] ) {
					echo '<h3>Turn back on for everyone</h3>';
					echo self::code_form( $back . '#feature-' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::form_open( 'lld_admin_feature', 'lld_admin_feature', array( 'feature' => $name, 'state' => 'everyone' ), 'lld-row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<div><label for="name-' . esc_attr( $name ) . '">Type the feature name: ' . esc_html( $name ) . '</label><input type="text" id="name-' . esc_attr( $name ) . '" name="typed" required autocomplete="off"></div>';
					echo '<div>' . self::reason_field( 'note-' . $name, 'Approval note' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<div>' . self::code_field( 'code-' . $name ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<button class="button button-primary">Turn on for everyone</button></form>';
				}
			}
			$history = LLD_Members_Features::history( $name );
			echo '<details><summary>Change history (' . count( $history ) . ')</summary>';
			if ( $history ) {
				echo '<div class="lld-scroll"><table class="widefat striped lld-stack"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Note</th></tr></thead><tbody>';
				foreach ( $history as $h ) {
					echo '<tr><td data-label="When">' . esc_html( $h['when'] ) . '</td><td data-label="Who">' . esc_html( $h['who'] ) . '</td><td data-label="From">' . esc_html( self::state_label( $h['from'] ) ) . '</td><td data-label="To">' . esc_html( self::state_label( $h['to'] ) ) . '</td><td data-label="Note">' . esc_html( $h['note'] ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			} else {
				echo '<p>No changes recorded.</p>';
			}
			echo '</details></div>';
		}

		echo '<div class="lld-box" id="test-accounts"><h2>Test accounts</h2>';
		echo '<p class="lld-hint">Members with these emails see features limited to test accounts. The Owner counts as a test account automatically; Support helpers do not unless their email is listed.</p>';
		$list = implode( "\n", LLD_Members_Features::test_account_emails() );
		if ( $owner ) {
			echo self::form_open( 'lld_admin_test_accounts', 'lld_admin_test_accounts' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<label for="lld-test-emails">One email per line</label><textarea id="lld-test-emails" name="emails" rows="6">' . esc_textarea( $list ) . '</textarea>';
			echo self::reason_field( 'lld-test-reason' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p><button class="button button-primary">Save the list</button></p></form>';
		} else {
			echo '<p>' . ( '' === $list ? 'None.' : nl2br( esc_html( $list ) ) ) . '</p>';
		}
		echo '</div></div>';
	}

	public static function post_feature() {
		self::check_post( 'lld_admin_feature' );
		$back = self::url( 'lld-features' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::finish( new WP_Error( 'lld_admin_role', 'Only the Owner can change a feature.' ), $back );
		}
		$feature = sanitize_key( self::posted( 'feature' ) );
		$state   = sanitize_key( self::posted( 'state' ) );
		$reason  = self::posted( 'reason' );
		$back   .= '#feature-' . $feature;
		if ( '' === $reason ) {
			self::finish( new WP_Error( 'lld_admin_reason', 'Give a reason. It is kept in the feature\'s history.' ), $back );
		}
		if ( 'everyone' === $state ) {
			if ( self::posted( 'typed' ) !== $feature ) {
				self::finish( new WP_Error( 'lld_admin_typed', 'Type the feature name exactly to turn it back on.' ), $back );
			}
			$code = LLD_Members_Admin_Security::fresh_code_ok( self::posted( 'code' ) );
			if ( is_wp_error( $code ) ) {
				self::finish( $code, $back );
			}
		}
		$done = LLD_Members_Features::set( $feature, $state, 'everyone' === $state ? wp_get_current_user()->display_name . ', ' . gmdate( 'j M Y' ) . ': ' . $reason : '', $reason );
		self::finish( is_wp_error( $done ) ? $done : $feature . ' is now: ' . self::state_label( $state ) . '.', $back );
	}

	public static function post_test_accounts() {
		self::check_post( 'lld_admin_test_accounts' );
		$back = self::url( 'lld-features' ) . '#test-accounts';
		if ( ! current_user_can( 'manage_options' ) ) {
			self::finish( new WP_Error( 'lld_admin_role', 'Only the Owner can change the test-account list.' ), $back );
		}
		$reason = self::posted( 'reason' );
		if ( '' === $reason ) {
			self::finish( new WP_Error( 'lld_admin_reason', 'Give a reason. It is kept in the activity log.' ), $back );
		}
		$before = LLD_Members_Features::test_account_emails();
		$after  = LLD_Members_Features::save_test_accounts( preg_split( '/[\s,]+/', self::posted( 'emails' ) ) );
		LLD_Members_Admin_Log::add( 'test_accounts_set', array( 'target' => 'test-account list', 'before' => count( $before ) . ' emails', 'after' => count( $after ) . ' emails', 'reason' => $reason ) );
		self::finish( 'Test-account list saved (' . count( $after ) . ' emails).', $back );
	}

	// ----- Members (FR3-75 to FR3-85) -----

	public static function members_page() {
		if ( ! current_user_can( LLD_Members_Admin_Roles::PANEL ) ) {
			return;
		}
		$member = isset( $_GET['member'] ) ? (int) $_GET['member'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $member > 0 ) {
			self::member_page( $member );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only search form.
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$filters = array(
			'plan'   => isset( $_GET['plan'] ) ? sanitize_key( $_GET['plan'] ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			'test'   => isset( $_GET['test'] ) ? sanitize_key( $_GET['test'] ) : '',
		);
		// phpcs:enable
		self::start( 'Members' );
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="lld-box" role="search"><input type="hidden" name="page" value="lld-members-admin">';
		echo '<div class="lld-row"><div><label for="lld-q">Email, name or member number</label><input type="search" id="lld-q" name="q" value="' . esc_attr( $q ) . '"></div>';
		echo self::select( 'plan', 'Plan', array( '' => 'Any', 'free' => 'Free', 'premium' => 'Premium' ), $filters['plan'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::select( 'status', 'Status', array( '' => 'Any', 'active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled', 'blocked' => 'Blocked' ), $filters['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::select( 'test', 'Test account', array( '' => 'Any', 'yes' => 'Yes', 'no' => 'No' ), $filters['test'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button class="button button-primary">Search</button></div></form>';
		$rows = LLD_Members_Admin_Members::search( $q, $filters, 100 );
		echo '<p>' . count( $rows ) . ' member' . ( 1 === count( $rows ) ? '' : 's' ) . ( 100 === count( $rows ) ? ' (the first 100)' : '' ) . '.</p>';
		if ( $rows ) {
			echo '<div class="lld-scroll"><table class="widefat striped lld-stack"><thead><tr><th scope="col">Member</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Plan</th><th scope="col">Joined</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$link = self::url( 'lld-members-admin', array( 'member' => (int) $row->id ) );
				echo '<tr><td data-label="Member"><a href="' . esc_url( $link ) . '">#' . (int) $row->id . '</a>' . ( $row->blocked_at ? ' (blocked)' : '' ) . '</td>';
				echo '<td data-label="Name">' . esc_html( trim( $row->first_name . ' ' . $row->last_name ) ) . '</td>';
				echo '<td data-label="Email">' . esc_html( (string) $row->email ) . '</td>';
				echo '<td data-label="Plan">' . esc_html( $row->access_state ? (string) $row->access_state : 'Free' ) . '</td>';
				echo '<td data-label="Joined">' . esc_html( (string) $row->joined_on ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}

	/**
	 * @param string               $name    Field.
	 * @param string               $label   Label.
	 * @param array<string,string> $options Options.
	 * @param string               $current Current.
	 */
	private static function select( $name, $label, array $options, $current ) {
		$html = '<div><label for="lld-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select id="lld-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		return $html . '</select></div>';
	}

	/**
	 * One member's account page: account details, notes and the actions the role allows (FR3-76).
	 *
	 * @param int $member_id Member number.
	 */
	private static function member_page( $member_id ) {
		$a = LLD_Members_Admin_Members::account( $member_id );
		self::start( $a ? 'Member #' . $member_id : 'Member not found' );
		echo '<p><a href="' . esc_url( self::url( 'lld-members-admin' ) ) . '">← All members</a></p>';
		if ( ! $a ) {
			echo '<p>This member no longer exists.</p></div>';
			return;
		}
		$owner = LLD_Members_Admin_Roles::is_owner();
		$back  = self::url( 'lld-members-admin', array( 'member' => $member_id ) );
		$hide  = array( 'member' => $member_id );

		echo '<div class="lld-box"><h2>Account</h2><dl class="lld-facts">';
		$facts = array(
			'Name'            => '' !== $a['name'] ? $a['name'] : '—',
			'Email'           => $a['email'] . ( $a['confirmed'] ? '' : ' (not confirmed yet)' ),
			'Sign-in methods' => implode( ', ', $a['methods'] ),
			'Joined'          => $a['joined'],
			'Last sign-in'    => '' !== $a['last_sign'] ? $a['last_sign'] . ' UTC' : 'Never',
			'Plan'            => $a['plan'],
			'Status'          => $a['status'] . ( $a['blocked'] && $owner ? ' (reason: ' . $a['blocked_why'] . ')' : '' ),
			'Renewal date'    => '' !== $a['renewal'] ? $a['renewal'] : '—',
			'Payment status'  => $a['payment'],
			'Test account'    => $a['test'] ? 'Yes' : 'No',
			'AI allowance'    => 'Not in use yet (Stage 5)',
		);
		foreach ( $facts as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
		}
		echo '</dl>';
		if ( $owner && '' !== $a['customer'] ) {
			$link = LLD_Members_Admin_Members::stripe_link( $a['customer'] );
			if ( '' !== $link ) {
				echo '<p><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">Open this member\'s customer page in Stripe</a> (refunds are made in Stripe)</p>';
			}
		}
		echo '</div>';

		if ( $a['grants'] ) {
			echo '<div class="lld-box"><h2>Free Premium given</h2><ul>';
			foreach ( $a['grants'] as $g ) {
				echo '<li>' . esc_html( substr( (string) $g->starts_at, 0, 10 ) . ' to ' . substr( (string) $g->ends_at, 0, 10 ) . ( $g->removed_at ? ', removed ' . substr( (string) $g->removed_at, 0, 10 ) : '' ) . ( $owner ? ' — ' . $g->reason : '' ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		// Notes (FR3-78): both roles.
		echo '<div class="lld-box"><h2>Private notes</h2><p class="lld-hint">Members never see these notes.</p>';
		echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'note' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label for="lld-note">Add a note</label><p class="lld-hint" id="lld-note-hint">No health details in notes</p><textarea id="lld-note" name="note" rows="3" maxlength="2000" aria-describedby="lld-note-hint" required></textarea><p><button class="button">Add note</button></p></form>';
		foreach ( $a['notes'] as $n ) {
			echo '<div class="lld-note"><p>' . nl2br( esc_html( (string) $n->note ) ) . '</p><p class="lld-hint">' . esc_html( LLD_Members_Admin_Log::who( (string) $n->admin_user_id ) . ', ' . $n->created_at . ' UTC' ) . '</p></div>';
		}
		echo '</div>';

		// Resend (FR3-77): both roles.
		echo '<div class="lld-box"><h2>Resend an account email</h2>';
		echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'resend' ), 'lld-row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::select( 'kind', 'Email', array( 'sign_in_code' => 'Sign-in code', 'confirm' => 'Email confirmation', 'welcome' => 'Welcome email' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button class="button">Resend</button></form></div>';

		// Pause and resume (FR3-79): both roles, when the member asks.
		echo '<div class="lld-box"><h2>Pause or resume</h2><p class="lld-hint">Only when the member asks. The member\'s own pause rules apply, and Stripe is updated to match.</p>';
		if ( $a['pause'] ) {
			echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'resume' ), 'lld-row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div>' . self::reason_field( 'lld-resume-reason', 'Note (optional)', '' ) . '</div><button class="button">Resume membership</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( $a['monthly'] && 0 === strpos( $a['plan'], 'Premium' ) ) {
			echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'pause' ), 'lld-row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::select( 'months', 'Pause for', array( '1' => '1 month', '2' => '2 months', '3' => '3 months' ), '1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div>' . self::reason_field( 'lld-pause-reason', 'Note (optional)', '' ) . '</div><button class="button">Pause membership</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<p>A pause is for monthly Premium plans.</p>';
		}
		echo '</div>';

		if ( $owner ) {
			self::owner_actions( $a, $hide, $back );
		}
		echo '</div>';
	}

	/**
	 * The Owner-only actions. Support never sees them, and the handler refuses them for Support (FR3-71 AS-02).
	 *
	 * @param array  $a    Account.
	 * @param array  $hide Hidden fields.
	 * @param string $back Return address.
	 */
	private static function owner_actions( array $a, array $hide, $back ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped helpers.
		$premium_by_grant = null !== LLD_Members_Admin_Members::active_grant( $a['id'] );
		echo '<div class="lld-box"><h2>Free Premium</h2>';
		if ( $premium_by_grant ) {
			echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'ungrant' ), 'lld-row' );
			echo '<div>' . self::reason_field( 'lld-ungrant-reason' ) . '</div><button class="button">Remove free Premium</button></form>';
		} else {
			echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'grant' ), 'lld-row' );
			echo '<div><label for="lld-grant-end">Until</label><input type="date" id="lld-grant-end" name="ends_on" min="' . esc_attr( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ) . '" required></div>';
			echo '<div>' . self::reason_field( 'lld-grant-reason' ) . '</div><button class="button">Give free Premium</button></form>';
		}
		echo '<p class="lld-hint">Kept apart from Stripe: no charge and no subscription. When it ends the member returns to Free and nothing is deleted.</p></div>';

		if ( $a['paying'] && LLD_Members_Billing::paid_state( $a['id'] ) ) {
			echo '<div class="lld-box"><h2>End membership</h2>';
			echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'end' ) );
			echo '<fieldset><legend class="screen-reader-text">When</legend><label><input type="radio" name="when" value="period_end" checked> At the end of the paid period (keeps Premium until then)</label><label><input type="radio" name="when" value="now"> Now (Premium stops at once)</label></fieldset>';
			echo self::reason_field( 'lld-end-reason' );
			echo '<p><button class="button" onclick="return confirm(\'End this membership?\')">End membership</button></p></form>';
			echo '<p class="lld-hint">Refunds are made in Stripe, not here.</p></div>';
		}

		echo '<div class="lld-box"><h2>Test account</h2>';
		echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'test', 'on' => $a['test'] ? '' : '1' ), 'lld-row' );
		echo '<div>' . self::reason_field( 'lld-test-reason' ) . '</div><button class="button">' . ( $a['test'] ? 'Unmark as a test account' : 'Mark as a test account' ) . '</button></form></div>';

		echo '<div class="lld-box"><h2>' . ( $a['blocked'] ? 'Unblock' : 'Block' ) . '</h2>';
		echo '<p class="lld-hint">' . ( $a['blocked'] ? 'Unblocking lets the member sign in again and restarts billing if the block paused it.' : 'A blocked member is signed out at once and can\'t sign in. A paying member\'s billing is paused while blocked.' ) . '</p>';
		echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => $a['blocked'] ? 'unblock' : 'block' ), 'lld-row' );
		echo '<div>' . self::reason_field( 'lld-block-reason' ) . '</div><button class="button">' . ( $a['blocked'] ? 'Unblock member' : 'Block member' ) . '</button></form></div>';

		echo '<div class="lld-box"><h2>Data download</h2><p class="lld-hint">Emails the member a link. They sign in to download their data; you never see it.</p>';
		echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'download' ), 'lld-row' );
		echo '<div>' . self::reason_field( 'lld-download-reason', 'Note (optional)', '' ) . '</div><button class="button">Send the download link</button></form></div>';

		echo '<div class="lld-box lld-danger"><h2>Delete member</h2><p class="lld-hint">Runs the same deletion as the member\'s own request. This can\'t be undone.</p>';
		echo self::code_form( $back );
		echo self::form_open( 'lld_admin_member', 'lld_admin_member', $hide + array( 'do' => 'delete' ) );
		echo '<label for="lld-delete-email">Type the member\'s email</label><input type="email" id="lld-delete-email" name="email" autocomplete="off" required>';
		echo self::reason_field( 'lld-delete-reason' );
		echo self::code_field( 'lld-delete-code' );
		echo '<p><button class="button button-link-delete">Delete member</button></p></form></div>';
		// phpcs:enable
	}

	public static function post_member() {
		self::check_post( 'lld_admin_member' );
		$member = (int) self::posted( 'member' );
		$do     = sanitize_key( self::posted( 'do' ) );
		$back   = self::url( 'lld-members-admin', array( 'member' => $member ) );
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
			)
		);
		if ( 'delete' === $do && ! is_wp_error( $result ) ) {
			$back = self::url( 'lld-members-admin' );
		}
		self::finish( $result, $back );
	}

	public static function post_code() {
		self::check_post( 'lld_admin_code' );
		$back = wp_validate_redirect( esc_url_raw( wp_unslash( (string) ( $_POST['back'] ?? '' ) ) ), self::url( self::SLUG ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_post() first.
		$sent = LLD_Members_Admin_Security::send_fresh_code();
		self::finish( is_wp_error( $sent ) ? $sent : 'We\'ve emailed you a 6-digit code. It expires in 10 minutes.', $back );
	}

	// ----- Helpers (FR3-71 AS-03) -----

	public static function helpers_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$back = self::url( 'lld-helpers' );
		self::start( 'Support helpers' );
		echo '<p class="lld-hint">A Support helper can find members, see account details, resend account emails, add notes, and pause or resume a membership when the member asks. Each helper has their own account and enters an emailed code at every sign-in.</p>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped helpers.
		echo self::code_form( $back );
		$helpers = LLD_Members_Admin_Roles::helpers();
		echo '<div class="lld-box"><h2>Helpers</h2>';
		if ( ! $helpers ) {
			echo '<p>No helpers yet.</p>';
		}
		foreach ( $helpers as $h ) {
			echo '<div class="lld-box"><p><strong>' . esc_html( $h->display_name ) . '</strong> — ' . esc_html( $h->user_email ) . '</p>';
			echo self::form_open( 'lld_admin_helper', 'lld_admin_helper', array( 'do' => 'reset', 'user' => (int) $h->ID ) ) . '<p><button class="button">Email a set-password link</button></p></form>';
			echo self::form_open( 'lld_admin_helper', 'lld_admin_helper', array( 'do' => 'remove', 'user' => (int) $h->ID ), 'lld-row' );
			echo '<div>' . self::reason_field( 'lld-remove-' . (int) $h->ID ) . '</div><div>' . self::code_field( 'lld-remove-code-' . (int) $h->ID ) . '</div><button class="button">Remove helper</button></form></div>';
		}
		echo '</div><div class="lld-box"><h2>Add a helper</h2>';
		echo self::form_open( 'lld_admin_helper', 'lld_admin_helper', array( 'do' => 'add' ) );
		echo '<label for="lld-helper-name">Name</label><input type="text" id="lld-helper-name" name="name" required>';
		echo '<label for="lld-helper-email">Email (their own, not a member\'s)</label><input type="email" id="lld-helper-email" name="email" required>';
		echo self::reason_field( 'lld-helper-reason' );
		echo self::code_field( 'lld-helper-code' );
		echo '<p><button class="button button-primary">Add helper</button></p></form></div></div>';
		// phpcs:enable
	}

	public static function post_helper() {
		self::check_post( 'lld_admin_helper' );
		$back = self::url( 'lld-helpers' );
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
