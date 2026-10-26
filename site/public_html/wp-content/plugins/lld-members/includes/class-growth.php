<?php
/**
 * Starter guides, waitlist, surveys, support and the Monday report.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Growth screens. Analytics events stay on the section 8.5 allowlist.
 */
class LLD_Members_Growth {

	/**
	 * Events for the page that is being rendered.
	 *
	 * @var array<int,array{name:string,props:array<string,string>}>
	 */
	private static $queue = array();

	public static function boot() {
		add_action( 'template_redirect', array( __CLASS__, 'capture_source' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'capture_public' ), 1 );
		add_filter( 'the_content', array( __CLASS__, 'article_guide' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'lld-payments', 'Testimonials', 'Testimonials', 'manage_options', 'lld-quotes', array( __CLASS__, 'admin_quotes' ) );
		add_submenu_page( 'lld-payments', 'Weekly report', 'Weekly report', 'manage_options', 'lld-report', array( __CLASS__, 'admin_report' ) );
	}

	public static function capture_source() {
		if ( ! isset( $_GET['utm_source'] ) ) {
			return;
		}
		$source = sanitize_title( wp_unslash( $_GET['utm_source'] ) );
		if ( '' === $source || ! empty( $_COOKIE['lld_src'] ) ) {
			return;
		}
		setcookie( 'lld_src', $source, time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
		$_COOKIE['lld_src'] = $source;
	}

	/**
	 * Guide and waitlist forms posted on articles.
	 */
	public static function capture_public() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['lld_guide_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_guide_nonce'] ) ), 'lld_guide' ) ) {
			return;
		}
		if ( 'waitlist' === get_query_var( 'lld_screen' ) ) {
			return;
		}
		$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
		$track = isset( $_POST['lld_track'] ) ? sanitize_title( wp_unslash( $_POST['lld_track'] ) ) : '';
		$kind  = isset( $_POST['lld_kind'] ) ? sanitize_key( wp_unslash( $_POST['lld_kind'] ) ) : 'guide';
		self::request( $email, $track, $kind );
		$back = isset( $_POST['lld_back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['lld_back'] ) ), '' ) : '';
		if ( '' === $back ) {
			$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		}
		wp_safe_redirect( add_query_arg( 'lld_check', '1', $back ) );
		exit;
	}

	/**
	 * @param string $html Post body.
	 */
	public static function article_guide( $html ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $html;
		}
		$track = self::article_track( get_the_ID() );
		if ( '' === $track ) {
			return $html;
		}
		return $html . self::guide_form( $track, 'guide' );
	}

	/**
	 * @param int $post_id Post.
	 */
	private static function article_track( $post_id ) {
		$tracks = array( 'healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress' );
		foreach ( array( 'post_tag', 'category', 'lld_tag' ) as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $slug ) {
				if ( in_array( (string) $slug, $tracks, true ) ) {
					return (string) $slug;
				}
			}
		}
		return '';
	}

	/**
	 * @param string $track Track slug.
	 * @param string $kind  guide or waitlist.
	 */
	public static function guide_form( $track, $kind ) {
		$track = sanitize_title( $track );
		$kind  = 'waitlist' === $kind ? 'waitlist' : 'guide';
		$html  = '<section data-lld-guide><h2>' . ( 'waitlist' === $kind ? 'Join the waitlist' : 'Get the free guide' ) . '</h2>';
		if ( isset( $_GET['lld_check'] ) ) {
			$html .= '<p>Check your email to confirm.</p>';
		}
		$html .= '<form method="post" class="lld-form">';
		$html .= wp_nonce_field( 'lld_guide', 'lld_guide_nonce', true, false );
		$here  = esc_url( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$html .= '<input type="hidden" name="lld_track" value="' . esc_attr( $track ) . '">';
		$html .= '<input type="hidden" name="lld_kind" value="' . esc_attr( $kind ) . '">';
		$html .= '<input type="hidden" name="lld_back" value="' . esc_attr( $here ) . '">';
		$html .= '<p><label for="lld_guide_email">Email</label><input id="lld_guide_email" name="lld_email" type="email" autocomplete="email" required></p>';
		$html .= lld_button( array( 'label' => 'waitlist' === $kind ? 'Join the waitlist' : 'Send me the guide' ) );
		$html .= '</form></section>';
		return $html;
	}

	/**
	 * @param string $email Email.
	 * @param string $track Track slug.
	 * @param string $kind  guide or waitlist.
	 */
	public static function request( $email, $track, $kind ) {
		$email = sanitize_email( $email );
		$track = sanitize_title( $track );
		$kind  = 'waitlist' === $kind ? 'waitlist' : 'guide';
		$known = array( 'healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress' );
		if ( ! is_email( $email ) || ! in_array( $track, $known, true ) ) {
			return;
		}
		if ( self::unsubscribed( $email ) ) {
			return;
		}
		$id = self::contact_id( $email, true );
		global $wpdb;
		$table = LLD_Members_Schema::table( 'contact_request' );
		$have  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE contact_id = %d AND kind = %s AND track_key = %s", $id, $kind, $track ) );
		if ( ! $have ) {
			$wpdb->insert(
				$table,
				array(
					'contact_id'   => $id,
					'kind'         => $kind,
					'track_key'    => $track,
					'requested_on' => gmdate( 'Y-m-d' ),
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}
		self::queue( 'waitlist' === $kind ? 'waitlist_joined' : 'starter_guide_requested', array( 'track' => $track ) );
		if ( ! self::confirmed( $email ) ) {
			self::mail_confirm( $email );
		}
	}

	public static function confirm_screen() {
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$sig   = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		if ( ! is_email( $email ) || ! hash_equals( self::sig( $email, 'confirm' ), $sig ) ) {
			lld_members_layout( array( 'title' => 'Link expired', 'main' => '<h1>Link expired</h1><p>Ask for the guide again.</p>' ) );
			return;
		}
		if ( ! self::confirmed( $email ) ) {
			self::consent( $email, 'confirmed' );
		}
		if ( ! self::unsubscribed( $email ) ) {
			self::send_guides( $email );
		}
		lld_members_layout( array( 'title' => 'Confirmed', 'main' => '<h1>Confirmed</h1><p>Your guide is on its way.</p>' ) );
	}

	public static function unsubscribe_screen() {
		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$sig   = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		if ( ! is_email( $email ) || ! hash_equals( self::sig( $email, 'unsub' ), $sig ) ) {
			lld_members_layout( array( 'title' => 'Link expired', 'main' => '<h1>Link expired</h1>' ) );
			return;
		}
		if ( self::unsubscribed( $email ) ) {
			lld_members_layout( array( 'title' => 'Already unsubscribed', 'main' => '<h1>Already unsubscribed</h1><p>Already unsubscribed.</p>', 'signed_in' => is_user_logged_in() ) );
			return;
		}
		self::consent( $email, 'unsubscribe' );
		lld_members_layout( array( 'title' => 'Unsubscribed', 'main' => '<h1>Unsubscribed</h1><p>You won\'t get these emails any more.</p>', 'signed_in' => is_user_logged_in() ) );
	}

	public static function waitlist_screen( $track ) {
		$pages = array(
			'healthy-weight' => 'Healthy Weight',
			'blood-sugar'    => 'Blood Sugar Balance',
			'fitness'        => 'Fitness and Strength',
			'sleep-stress'   => 'Sleep and Stress',
		);
		if ( ! isset( $pages[ $track ] ) ) {
			status_header( 404 );
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_guide_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_guide_nonce'] ) ), 'lld_guide' ) ) {
			$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
			self::request( $email, $track, 'waitlist' );
			$main = '<h1>' . esc_html( $pages[ $track ] ) . ' is coming soon</h1><p>Check your email to confirm.</p>';
			lld_members_layout( array( 'title' => 'Coming soon', 'main' => $main ) );
			return;
		}
		$main  = '<h1>' . esc_html( $pages[ $track ] ) . ' is coming soon</h1>';
		$main .= '<p>Four weekly themes, then the full track opens.</p>';
		$main .= '<ol><li>Week 1: getting started</li><li>Week 2: a steady rhythm</li><li>Week 3: building on it</li><li>Week 4: keeping it going</li></ol>';
		$main .= '<p>Launch discount: 50% off the first month.</p>';
		$main .= self::guide_form( $track, 'waitlist' );
		lld_members_layout( array( 'title' => $pages[ $track ] . ' coming soon', 'main' => $main ) );
	}

	public static function help() {
		$member_id = LLD_Members_Access::current_member_id();
		$error     = '';
		$sent      = false;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_support_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_support_nonce'] ) ), 'lld_support' ) ) {
			$name    = isset( $_POST['lld_name'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_name'] ) ) : '';
			$email   = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
			$message = isset( $_POST['lld_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lld_message'] ) ) : '';
			if ( $member_id ) {
				$row = self::member( $member_id );
				if ( $row ) {
					$name  = '' !== $name ? $name : (string) $row->first_name;
					$email = is_email( $email ) ? $email : (string) $row->email;
				}
			}
			if ( ! is_email( $email ) || '' === $message ) {
				$error = 'Add your email and a message.';
			} else {
				$plan = $member_id ? (string) LLD_Members_Access::access_state( $member_id ) : '';
				global $wpdb;
				$data = array(
					'person_name' => $name,
					'email'       => $email,
					'message'     => $message,
					'created_at'  => current_time( 'mysql', true ),
				);
				if ( $member_id ) {
					$data['member_id'] = $member_id;
					$data['plan']      = '' !== $plan ? $plan : 'free';
				}
				$wpdb->insert( LLD_Members_Schema::table( 'support' ), $data );
				$body = "Support message\nFrom: {$name}\n";
				$body .= $member_id ? 'Plan: ' . ( '' !== $plan ? $plan : 'free' ) . "\n" : "Plan: none\n";
				$body .= $message;
				wp_mail( (string) get_option( 'admin_email' ), 'Support message', $body );
				$sent = true;
			}
		}
		$main  = '<h1>Help</h1>';
		$main .= '<h2>Billing</h2><p>Yearly and monthly prices are shown on the pricing page. Stripe handles the card.</p>';
		$main .= '<h2>Cancelling</h2><p>Cancel from My subscription. Access continues to the end of the paid period.</p>';
		$main .= '<h2>Refunds</h2><p>The first payment can be refunded within 14 days.</p>';
		$main .= '<h2>Referrals</h2><p>A referral reward is a discount on a later payment, up to 3 a year.</p>';
		$main .= '<h2>Data</h2><p>Download or delete your data from My data and Delete my account.</p>';
		$main .= '<p>We aim to reply within 2 working days.</p>';
		if ( $sent ) {
			$main .= '<p>Message sent.</p>';
		}
		if ( '' !== $error ) {
			$main .= '<p>' . esc_html( $error ) . '</p>';
		}
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_support', 'lld_support_nonce', true, false );
		$main .= '<p><label for="lld_name">Name</label><input id="lld_name" name="lld_name" autocomplete="name"></p>';
		$main .= '<p><label for="lld_email">Email</label><input id="lld_email" name="lld_email" type="email" autocomplete="email"></p>';
		$main .= '<p><label for="lld_message">Message</label><textarea id="lld_message" name="lld_message" rows="4" required></textarea></p>';
		$main .= lld_button( array( 'label' => 'Send message' ) );
		$main .= '</form>';
		lld_members_layout(
			array(
				'title'     => 'Help',
				'main'      => $main,
				'signed_in' => $member_id > 0,
			)
		);
	}

	public static function more() {
		$member_id = LLD_Members_Access::current_member_id();
		$main      = '<h1>More</h1><p><a href="' . esc_url( home_url( '/members/help/' ) ) . '">Help</a></p>';
		lld_members_layout(
			array(
				'title'     => 'More',
				'main'      => $main,
				'signed_in' => $member_id > 0,
			)
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function survey_html( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Programme' ) ) {
			return '';
		}
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row ) {
			return '';
		}
		$day  = LLD_Members_Programme::programme_day( $member_id, (int) $row->id );
		$week = LLD_Members_Programme::position_parts( $day )['week'];
		if ( 7 !== (int) $day && 4 !== (int) $week ) {
			return '';
		}
		$week4 = 4 === (int) $week;
		$html  = '<section data-lld-survey><h2>' . ( $week4 ? 'Week 4 survey' : 'Week 1 survey' ) . '</h2>';
		$html .= '<form method="post" class="lld-form">';
		$html .= wp_nonce_field( 'lld_survey', 'lld_survey_nonce', true, false );
		$html .= '<input type="hidden" name="lld_survey_week" value="' . ( $week4 ? '4' : '1' ) . '">';
		$html .= '<p><label for="lld_score">How likely are you to recommend Lifeline Daily, from 0 to 10?</label> <input id="lld_score" name="lld_score" inputmode="numeric"></p>';
		$html .= '<p><label for="lld_open">What should we know?</label><textarea id="lld_open" name="lld_open" rows="3"></textarea></p>';
		if ( $week4 ) {
			$html .= '<p><label for="lld_changed">What changed for you?</label><textarea id="lld_changed" name="lld_changed" rows="3"></textarea></p>';
			$html .= '<p><label for="lld_country">Country</label><input id="lld_country" name="lld_country" maxlength="2"></p>';
			$html .= '<p><label class="lld-check"><input type="checkbox" name="lld_permission" value="1"> You may quote my first name and country</label></p>';
		}
		$html .= lld_button( array( 'label' => 'Send survey' ) );
		$html .= '</form></section>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function save_survey( $member_id ) {
		if ( ! isset( $_POST['lld_survey_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_survey_nonce'] ) ), 'lld_survey' ) ) {
			return;
		}
		$week = isset( $_POST['lld_survey_week'] ) ? (int) $_POST['lld_survey_week'] : 1;
		if ( ! in_array( $week, array( 1, 4 ), true ) ) {
			return;
		}
		$score   = isset( $_POST['lld_score'] ) ? (int) $_POST['lld_score'] : 0;
		$open    = isset( $_POST['lld_open'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lld_open'] ) ) : '';
		$changed = isset( $_POST['lld_changed'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lld_changed'] ) ) : '';
		$country = isset( $_POST['lld_country'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['lld_country'] ) ), 0, 2 ) ) : '';
		$allow   = ! empty( $_POST['lld_permission'] );
		$member  = self::member( $member_id );
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'survey' ),
			array(
				'member_id'    => $member_id,
				'week_number'  => $week,
				'answers'      => wp_json_encode( array( 'score' => $score, 'open' => $open ) ),
				'what_changed' => $changed,
				'quote_text'   => $allow ? $open : '',
				'permission'   => $allow ? 1 : 0,
				'display_name' => $member ? (string) $member->first_name : '',
				'country'      => $country,
			)
		);
	}

	public static function testimonial_html() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT quote_text, display_name, country FROM ' . LLD_Members_Schema::table( 'survey' ) . ' WHERE permission = 1 AND approved_on IS NOT NULL ORDER BY id ASC' );
		$html = '';
		if ( ! $rows ) {
			return $html;
		}
		foreach ( $rows as $row ) {
			$html .= '<p data-lld-quote>' . esc_html( (string) $row->quote_text ) . '. ' . esc_html( (string) $row->display_name ) . ', ' . esc_html( (string) $row->country ) . '</p>';
		}
		return $html;
	}

	public static function quotes_screen() {
		$main = '<h1>Testimonials</h1>' . self::testimonial_html();
		lld_members_layout( array( 'title' => 'Testimonials', 'main' => $main ) );
	}

	public static function admin_quotes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_quote_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_quote_nonce'] ) ), 'lld_quote' ) ) {
			global $wpdb;
			$wpdb->update(
				LLD_Members_Schema::table( 'survey' ),
				array( 'approved_on' => gmdate( 'Y-m-d' ) ),
				array( 'id' => isset( $_POST['lld_survey'] ) ? (int) $_POST['lld_survey'] : 0 ),
				array( '%s' ),
				array( '%d' )
			);
			echo '<div class="notice notice-success"><p>Approved.</p></div>';
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, display_name, country, quote_text FROM ' . LLD_Members_Schema::table( 'survey' ) . ' WHERE permission = 1 AND approved_on IS NULL ORDER BY id ASC' );
		echo '<div class="wrap"><h1>Testimonials</h1><ul>';
		if ( ! $rows ) {
			echo '<li>None waiting.</li>';
		}
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( (string) $row->display_name ) . ', ' . esc_html( (string) $row->country );
			echo '<form method="post">';
			wp_nonce_field( 'lld_quote', 'lld_quote_nonce' );
			echo '<input type="hidden" name="lld_survey" value="' . (int) $row->id . '">';
			submit_button( 'Approve', 'secondary', 'submit', false );
			echo '</form></li>';
		}
		echo '</ul></div>';
	}

	public static function admin_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Weekly report</h1><pre>' . esc_html( self::report_text() ) . '</pre></div>';
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function attach_source( $member_id ) {
		$source = isset( $_COOKIE['lld_src'] ) ? sanitize_title( wp_unslash( $_COOKIE['lld_src'] ) ) : '';
		if ( '' === $source ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'member' );
		$have  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT arrived_from FROM {$table} WHERE id = %d", $member_id ) );
		if ( '' !== $have ) {
			return;
		}
		$wpdb->update( $table, array( 'arrived_from' => $source ), array( 'id' => $member_id ), array( '%s' ), array( '%d' ) );
		self::bump_source( $source, 'signups' );
		self::bump_track( 'healthy-weight', 'registrations' );
	}

	/**
	 * @param string $email Email.
	 * @param string $kind  renewals or cancellations.
	 */
	public static function note_member_event( $email, $kind ) {
		if ( ! in_array( $kind, array( 'renewals', 'cancellations' ), true ) ) {
			return;
		}
		global $wpdb;
		$source = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT arrived_from FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', sanitize_email( $email ) ) );
		if ( '' === $source ) {
			return;
		}
		self::bump_source( $source, $kind );
	}

	public static function launch() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, email FROM ' . LLD_Members_Schema::table( 'contact' ) );
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $row ) {
			$email = (string) $row->email;
			if ( ! self::confirmed( $email ) || self::unsubscribed( $email ) ) {
				continue;
			}
			$wait = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'contact_request' ) . " WHERE contact_id = %d AND kind = 'waitlist'", (int) $row->id ) );
			if ( $wait < 1 ) {
				continue;
			}
			wp_mail( $email, 'Lifeline Daily is open', "The tracks are open. Launch discount: 50% off the first month.\n" . self::unsub_line( $email ) );
		}
	}

	public static function send_report() {
		$text = self::report_text();
		wp_mail( (string) get_option( 'admin_email' ), 'Weekly report', $text );
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'weekly_report' ),
			array(
				'week_starting' => gmdate( 'Y-m-d' ),
				'totals'        => $text,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		$action = isset( $params['action'] ) ? sanitize_key( (string) $params['action'] ) : 'figures';
		if ( 'reset' === $action ) {
			update_option( 'lld_funnel', self::empty_funnel(), false );
			return new WP_REST_Response( array( 'reset' => true ), 200 );
		}
		if ( 'prepare' === $action && isset( $params['tracks'] ) && is_array( $params['tracks'] ) ) {
			$funnel = self::empty_funnel();
			foreach ( $params['tracks'] as $track => $counts ) {
				$track = sanitize_title( (string) $track );
				if ( ! isset( $funnel['tracks'][ $track ] ) || ! is_array( $counts ) ) {
					continue;
				}
				foreach ( self::track_keys() as $key ) {
					if ( isset( $counts[ $key ] ) ) {
						$funnel['tracks'][ $track ][ $key ] = (int) $counts[ $key ];
					}
				}
			}
			update_option( 'lld_funnel', $funnel, false );
		}
		if ( 'renew' === $action || 'cancel' === $action ) {
			self::note_member_event( isset( $params['email'] ) ? (string) $params['email'] : '', 'renew' === $action ? 'renewals' : 'cancellations' );
		}
		if ( 'report' === $action ) {
			self::send_report();
		}
		if ( 'launch' === $action ) {
			self::launch();
		}
		if ( 'article' === $action ) {
			$track = isset( $params['track'] ) ? sanitize_title( (string) $params['track'] ) : 'healthy-weight';
			$id    = wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'Guide article ' . $track,
					'post_content' => 'A public article.',
				)
			);
			if ( ! is_wp_error( $id ) ) {
				wp_set_object_terms( (int) $id, $track, 'post_tag' );
			}
			return new WP_REST_Response( array( 'url' => get_permalink( $id ) ), 200 );
		}
		return new WP_REST_Response( self::figures(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_analytics( $request ) {
		$params = $request->get_json_params();
		$name   = isset( $params['name'] ) ? sanitize_key( (string) $params['name'] ) : '';
		$props  = isset( $params['props'] ) && is_array( $params['props'] ) ? $params['props'] : array();
		if ( ! self::allowed_event( $name, $props ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		self::count_event( $name, $props );
		return new WP_REST_Response( array( 'ok' => true ), 202 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function figures() {
		$funnel = self::funnel();
		$money  = self::money_figures();
		return array(
			'tracks'  => $funnel['tracks'],
			'sources' => $funnel['sources'],
			'mrr'     => $money['mrr'],
			'active'  => $money['active'],
			'yearly'  => $money['yearly'],
			'monthly' => $money['monthly'],
		);
	}

	public static function events_json() {
		return wp_json_encode( self::$queue );
	}

	public static function script() {
		return '<script id="lld-analytics" type="application/json">' . self::events_json() . '</script><script>(function(){var allow={pageview:["url"],calculator_used:["calculator"],registered:[],track_started:["track"],week1_completed:["track"],upgraded:["plan"],switched_to_yearly:[],starter_guide_requested:["track"],waitlist_joined:["track"]};function clean(href){var u=new URL(href,location.origin);var next=u.pathname;["utm_source","utm_medium","utm_campaign"].forEach(function(key){var value=u.searchParams.get(key);if(value&&/^[A-Za-z0-9_-]{1,64}$/.test(value)){next+=(next.indexOf("?")<0?"?":"&")+key+"="+encodeURIComponent(value);}});return next;}function send(name,props){if(!allow[name])return;var safe={};(allow[name]||[]).forEach(function(key){if(props&&props[key])safe[key]=String(props[key]).slice(0,64);});if(name==="pageview")safe.url=clean(location.href);var body=JSON.stringify({name:name,props:safe});fetch("https://plausible.io/api/event",{method:"POST",headers:{"Content-Type":"application/json"},body:body}).catch(function(){});fetch("/wp-json/lld-members/v1/analytics",{method:"POST",headers:{"Content-Type":"application/json"},body:body}).catch(function(){});}send("pageview",{});var node=document.getElementById("lld-analytics");if(!node)return;try{JSON.parse(node.textContent||"[]").forEach(function(event){send(event.name,event.props||{});});}catch(e){}})();</script>';
	}

	/**
	 * @param string               $name  Event name.
	 * @param array<string,string> $props Props.
	 */
	public static function queue( $name, $props ) {
		if ( ! self::allowed_event( $name, $props ) ) {
			return;
		}
		$clean = array();
		foreach ( $props as $key => $value ) {
			$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}
		self::$queue[] = array(
			'name'  => $name,
			'props' => $clean,
		);
	}

	/**
	 * @param string               $name  Event.
	 * @param array<string,mixed>  $props Props.
	 */
	private static function allowed_event( $name, $props ) {
		$allow = array(
			'pageview'                => array( 'url' ),
			'calculator_used'         => array( 'calculator' ),
			'registered'              => array(),
			'track_started'           => array( 'track' ),
			'week1_completed'         => array( 'track' ),
			'upgraded'                => array( 'plan' ),
			'switched_to_yearly'      => array(),
			'starter_guide_requested' => array( 'track' ),
			'waitlist_joined'         => array( 'track' ),
		);
		if ( ! isset( $allow[ $name ] ) ) {
			return false;
		}
		foreach ( $props as $key => $value ) {
			if ( ! in_array( (string) $key, $allow[ $name ], true ) ) {
				return false;
			}
			if ( is_string( $value ) && false !== strpos( $value, '@' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string              $name  Event.
	 * @param array<string,mixed> $props Props.
	 */
	private static function count_event( $name, $props ) {
		$map = array(
			'pageview'           => 'visits',
			'calculator_used'    => 'calculator',
			'registered'         => 'registrations',
			'track_started'      => 'track_starts',
			'week1_completed'    => 'week1',
			'upgraded'           => 'upgrades',
			'switched_to_yearly' => 'switches',
		);
		if ( ! isset( $map[ $name ] ) ) {
			return;
		}
		$track = isset( $props['track'] ) ? sanitize_title( (string) $props['track'] ) : 'healthy-weight';
		if ( 'calculator_used' === $name ) {
			$track = 'diabetes' === ( $props['calculator'] ?? '' ) ? 'blood-sugar' : 'fitness';
		}
		self::bump_track( $track, $map[ $name ] );
	}

	private static function report_text() {
		$figures = self::figures();
		$names   = array(
			'healthy-weight' => 'Healthy Weight',
			'blood-sugar'    => 'Blood Sugar Balance',
			'fitness'        => 'Fitness and Strength',
			'sleep-stress'   => 'Sleep and Stress',
		);
		$labels  = array(
			'visits'        => 'visits',
			'calculator'    => 'calculator use',
			'registrations' => 'registrations',
			'track_starts'  => 'track starts',
			'week1'         => 'week 1 completions',
			'upgrades'      => 'upgrades',
			'switches'      => 'monthly-to-yearly switches',
			'renewals'      => 'renewals',
			'cancellations' => 'cancellations',
			'reasons'       => 'cancellation reasons',
		);
		$lines   = array();
		foreach ( $names as $key => $label ) {
			foreach ( $labels as $field => $caption ) {
				$lines[] = $label . ' ' . $caption . ' ' . (int) $figures['tracks'][ $key ][ $field ];
			}
		}
		$lines[] = 'Monthly recurring revenue ' . $figures['mrr'];
		$lines[] = 'Active Premium members ' . (int) $figures['active'];
		$lines[] = 'Yearly members ' . (int) $figures['yearly'];
		$lines[] = 'Monthly members ' . (int) $figures['monthly'];
		foreach ( $figures['sources'] as $source => $counts ) {
			$lines[] = 'Source ' . $source . ' sign-ups ' . (int) $counts['signups'];
			$lines[] = 'Source ' . $source . ' renewals ' . (int) $counts['renewals'];
			$lines[] = 'Source ' . $source . ' cancellations ' . (int) $counts['cancellations'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * @return array{mrr:string,active:int,yearly:int,monthly:int}
	 */
	private static function money_figures() {
		global $wpdb;
		$rows    = $wpdb->get_results( 'SELECT plan, access_state FROM ' . LLD_Members_Schema::table( 'subscription' ) );
		$active  = 0;
		$yearly  = 0;
		$monthly = 0;
		$mrr     = 0.0;
		if ( $rows ) {
			foreach ( $rows as $row ) {
				if ( 'Premium' !== (string) $row->access_state ) {
					continue;
				}
				++$active;
				if ( 'yearly' === (string) $row->plan ) {
					++$yearly;
					$mrr += 59.99 / 12;
				} else {
					++$monthly;
					$mrr += 7.99;
				}
			}
		}
		return array(
			'mrr'     => number_format( $mrr, 2, '.', '' ),
			'active'  => $active,
			'yearly'  => $yearly,
			'monthly' => $monthly,
		);
	}

	/**
	 * @return string[]
	 */
	private static function track_keys() {
		return array( 'visits', 'calculator', 'registrations', 'track_starts', 'week1', 'upgrades', 'switches', 'renewals', 'cancellations', 'reasons' );
	}

	/**
	 * @return array{tracks:array<string,array<string,int>>,sources:array<string,array<string,int>>}
	 */
	private static function empty_funnel() {
		$blank  = array_fill_keys( self::track_keys(), 0 );
		$tracks = array();
		foreach ( array( 'healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress' ) as $track ) {
			$tracks[ $track ] = $blank;
		}
		return array(
			'tracks'  => $tracks,
			'sources' => array(),
		);
	}

	/**
	 * @return array{tracks:array<string,array<string,int>>,sources:array<string,array<string,int>>}
	 */
	private static function funnel() {
		$saved = get_option( 'lld_funnel' );
		$base  = self::empty_funnel();
		if ( ! is_array( $saved ) ) {
			return $base;
		}
		if ( isset( $saved['tracks'] ) && is_array( $saved['tracks'] ) ) {
			foreach ( $base['tracks'] as $track => $blank ) {
				if ( isset( $saved['tracks'][ $track ] ) && is_array( $saved['tracks'][ $track ] ) ) {
					foreach ( $blank as $key => $value ) {
						$base['tracks'][ $track ][ $key ] = (int) ( $saved['tracks'][ $track ][ $key ] ?? 0 );
					}
				}
			}
		}
		if ( isset( $saved['sources'] ) && is_array( $saved['sources'] ) ) {
			$base['sources'] = $saved['sources'];
		}
		return $base;
	}

	/**
	 * @param string $track Track.
	 * @param string $key   Figure.
	 */
	private static function bump_track( $track, $key ) {
		$funnel = self::funnel();
		if ( ! isset( $funnel['tracks'][ $track ][ $key ] ) ) {
			$track = 'healthy-weight';
		}
		if ( ! isset( $funnel['tracks'][ $track ][ $key ] ) ) {
			return;
		}
		$funnel['tracks'][ $track ][ $key ]++;
		update_option( 'lld_funnel', $funnel, false );
	}

	/**
	 * @param string $source Source slug.
	 * @param string $key    signups, renewals or cancellations.
	 */
	private static function bump_source( $source, $key ) {
		$funnel = self::funnel();
		if ( ! isset( $funnel['sources'][ $source ] ) ) {
			$funnel['sources'][ $source ] = array(
				'signups'       => 0,
				'renewals'      => 0,
				'cancellations' => 0,
			);
		}
		$funnel['sources'][ $source ][ $key ]++;
		update_option( 'lld_funnel', $funnel, false );
	}

	/**
	 * @param string $email Email.
	 * @param bool   $create Create when missing.
	 */
	private static function contact_id( $email, $create ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'contact' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );
		if ( $id || ! $create ) {
			return $id;
		}
		$source = isset( $_COOKIE['lld_src'] ) ? sanitize_title( wp_unslash( $_COOKIE['lld_src'] ) ) : '';
		$wpdb->insert(
			$table,
			array(
				'email'      => $email,
				'first_name' => '',
				'source'     => $source,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $email  Email.
	 * @param string $action confirmed or unsubscribe.
	 */
	private static function consent( $email, $action ) {
		$id = self::contact_id( $email, true );
		if ( $id < 1 ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'contact_consent' ),
			array(
				'contact_id'  => $id,
				'action'      => $action,
				'occurred_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * @param string $email Email.
	 */
	private static function confirmed( $email ) {
		return self::last_action( $email, 'confirmed' );
	}

	/**
	 * @param string $email Email.
	 */
	private static function unsubscribed( $email ) {
		global $wpdb;
		$id = self::contact_id( $email, false );
		if ( $id < 1 ) {
			return false;
		}
		$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT action FROM ' . LLD_Members_Schema::table( 'contact_consent' ) . ' WHERE contact_id = %d ORDER BY id DESC LIMIT 1', $id ) );
		return 'unsubscribe' === $last;
	}

	/**
	 * @param string $email  Email.
	 * @param string $action Action.
	 */
	private static function last_action( $email, $action ) {
		global $wpdb;
		$id = self::contact_id( $email, false );
		if ( $id < 1 ) {
			return false;
		}
		$found = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'contact_consent' ) . ' WHERE contact_id = %d AND action = %s LIMIT 1', $id, $action ) );
		return $found > 0 && ! self::unsubscribed( $email );
	}

	/**
	 * @param string $email Email.
	 */
	private static function mail_confirm( $email ) {
		$url = add_query_arg(
			array(
				'email' => $email,
				'sig'   => self::sig( $email, 'confirm' ),
			),
			home_url( '/guide/confirm/' )
		);
		wp_mail( $email, 'Confirm your starter guide', "Confirm your email:\n" . $url . "\n" . self::unsub_line( $email ) );
	}

	/**
	 * @param string $email Email.
	 */
	private static function send_guides( $email ) {
		if ( self::unsubscribed( $email ) || ! self::confirmed( $email ) ) {
			return;
		}
		global $wpdb;
		$id   = self::contact_id( $email, false );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT track_key FROM ' . LLD_Members_Schema::table( 'contact_request' ) . " WHERE contact_id = %d AND kind = 'guide'", $id ) );
		if ( ! $rows ) {
			return;
		}
		$lines = array( 'Your starter guide' );
		foreach ( $rows as $row ) {
			$lines[] = home_url( '/programmes/' . $row->track_key . '/' );
		}
		$lines[] = self::unsub_line( $email );
		wp_mail( $email, 'Your starter guide', implode( "\n", $lines ) );
	}

	/**
	 * @param string $email Email.
	 */
	private static function unsub_line( $email ) {
		$url = add_query_arg(
			array(
				'email' => $email,
				'sig'   => self::sig( $email, 'unsub' ),
			),
			home_url( '/guide/unsubscribe/' )
		);
		return 'Unsubscribe: ' . $url;
	}

	/**
	 * @param string $email   Email.
	 * @param string $purpose confirm or unsub.
	 */
	private static function sig( $email, $purpose ) {
		return substr( hash_hmac( 'sha256', strtolower( $email ) . '|' . $purpose, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT first_name, email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}
}
