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
 *
 * The Monday report (FR2-17) is built from two sources:
 * - visits and calculator use come from Plausible's Stats API for the previous Monday to Sunday;
 * - registrations, track starts, week 1 completions, upgrades, switches, renewals, cancellations and their reasons are
 *   counted here when the site itself records them (the lld_members_event action and the week 1 check below).
 * Payments come from the subscription state Stripe last confirmed. Nothing is counted from a browser request.
 */
class LLD_Members_Growth {

	const TRACKS = array(
		'healthy-weight' => 'Healthy Weight',
		'blood-sugar'    => 'Blood Sugar Balance',
		'fitness'        => 'Fitness and Strength',
		'sleep-stress'   => 'Sleep and Stress',
	);

	/**
	 * Stripe's cancellation feedback values. Free-text comments are never kept.
	 */
	const REASONS = array( 'customer_service', 'low_quality', 'missing_features', 'other', 'switched_service', 'too_complex', 'too_expensive', 'unused' );

	const REPORT_HOOK = 'lld_members_weekly_report';

	const PLAUSIBLE_EVENTS = 'https://plausible.io/api/event';

	const PLAUSIBLE_STATS = 'https://plausible.io/api/v2/query';

	/**
	 * Analytics events for the page that is being rendered.
	 *
	 * @var array<int,array{name:string,props:array<string,string>}>
	 */
	private static $queue = array();

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'template_redirect', array( __CLASS__, 'capture_source' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'capture_public' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'note_page' ), 2 );
		add_filter( 'the_content', array( __CLASS__, 'article_guide' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_script' ) );
		add_action( 'lld_members_event', array( __CLASS__, 'on_event' ), 10, 3 );
		add_action( self::REPORT_HOOK, array( __CLASS__, 'monday_report' ) );
		add_action( 'lld_members_daily', array( __CLASS__, 'sweep_week1' ) );
		add_action( 'lld_members_daily', array( __CLASS__, 'sweep_contacts' ) );
		if ( lld_members_test_routes_open() ) {
			add_filter( 'pre_http_request', array( __CLASS__, 'stub_plausible' ), 10, 3 );
		}
	}

	/**
	 * The report goes every Monday at 07:00 site time.
	 */
	public static function schedule() {
		if ( wp_next_scheduled( self::REPORT_HOOK ) ) {
			return;
		}
		$next = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'next monday 07:00' );
		wp_schedule_event( $next->getTimestamp(), 'weekly', self::REPORT_HOOK );
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
		setcookie( 'lld_src', $source, 0, '/', '', is_ssl(), true );
		$_COOKIE['lld_src'] = $source;
	}

	/**
	 * Guide and waitlist forms posted on articles and calculator pages.
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
		$kind  = isset( $_POST['lld_kind'] ) && 'waitlist' === sanitize_key( wp_unslash( $_POST['lld_kind'] ) ) ? 'waitlist' : 'guide';
		$done  = self::request( $email, $track, $kind );
		$back  = isset( $_POST['lld_back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['lld_back'] ) ), '' ) : '';
		if ( '' === $back ) {
			$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		}
		wp_safe_redirect( add_query_arg( 'lld_check', $done ? $kind . '-' . $track : '1', $back ) );
		exit;
	}

	/**
	 * The page after a guide request sends the allowlisted event, and a signed-in member's week 1 is checked.
	 */
	public static function note_page() {
		$check = isset( $_GET['lld_check'] ) ? sanitize_title( wp_unslash( $_GET['lld_check'] ) ) : '';
		if ( preg_match( '/^(guide|waitlist)-(' . implode( '|', array_keys( self::TRACKS ) ) . ')$/', $check, $match ) ) {
			self::queue( 'waitlist' === $match[1] ? 'waitlist_joined' : 'starter_guide_requested', array( 'track' => $match[2] ) );
		}
		$member_id = LLD_Members_Access::current_member_id();
		if ( $member_id > 0 ) {
			self::check_week1( $member_id );
		}
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
		foreach ( array( 'post_tag', 'category', 'lld_tag' ) as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $slug ) {
				if ( isset( self::TRACKS[ (string) $slug ] ) ) {
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
		$here  = esc_url( home_url( remove_query_arg( 'lld_check', wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ) );
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
	 * @return bool True when the request was recorded.
	 */
	public static function request( $email, $track, $kind ) {
		$email = sanitize_email( $email );
		$track = sanitize_title( $track );
		$kind  = 'waitlist' === $kind ? 'waitlist' : 'guide';
		if ( ! is_email( $email ) || ! isset( self::TRACKS[ $track ] ) ) {
			return false;
		}
		if ( self::unsubscribed( $email ) ) {
			return false;
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
		if ( ! self::confirmed( $email ) ) {
			self::mail_confirm( $email );
		} elseif ( 'guide' === $kind ) {
			// Already confirmed: a further guide is sent straight away.
			self::send_guides( $email, array( $track ) );
		}
		return true;
	}

	/**
	 * Email of the contact named by a link token, or '' when the token is not valid.
	 *
	 * @param string $purpose guide-confirm or guide-unsub.
	 * @param int    $max_age Seconds the link stays valid, or 0.
	 */
	private static function email_from_link( $purpose, $max_age ) {
		$token = isset( $_REQUEST['c'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['c'] ) ) : '';
		$id    = LLD_Members_Mail::read_token( $purpose, $token, $max_age );
		if ( $id < 1 ) {
			return '';
		}
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'contact' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * Double opt-in. The link names the contact by a signed token, never by email, and lasts 30 days (DM-7).
	 */
	public static function confirm_screen() {
		$email = self::email_from_link( 'guide-confirm', 30 * DAY_IN_SECONDS );
		if ( ! is_email( $email ) ) {
			lld_members_layout( array( 'title' => 'Link expired', 'main' => '<h1>Link expired</h1><p>Ask for the guide again.</p>' ) );
			return;
		}
		if ( ! self::confirmed( $email ) && ! self::unsubscribed( $email ) ) {
			self::consent( $email, 'confirmed' );
			self::send_guides( $email );
		}
		lld_members_layout( array( 'title' => 'Confirmed', 'main' => '<h1>Confirmed</h1><p>Your guide is on its way.</p>' ) );
	}

	/**
	 * GET shows a button; POST unsubscribes, including a mail app's one-click POST. A bad link never says "Unsubscribed".
	 */
	public static function unsubscribe_screen() {
		$email = self::email_from_link( 'guide-unsub', 0 );
		$in    = is_user_logged_in();
		if ( ! is_email( $email ) ) {
			lld_members_layout( array( 'title' => 'Link expired', 'main' => '<h1>Link expired</h1><p>This unsubscribe link is not valid any more.</p>', 'signed_in' => $in ) );
			return;
		}
		if ( self::unsubscribed( $email ) ) {
			lld_members_layout( array( 'title' => 'Already unsubscribed', 'main' => '<h1>Already unsubscribed</h1><p>Already unsubscribed.</p>', 'signed_in' => $in ) );
			return;
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$token = sanitize_text_field( wp_unslash( $_REQUEST['c'] ) );
			$main  = '<h1>Stop these emails?</h1><p>You will no longer get starter guides, waitlist news or the launch email.</p>';
			$main .= '<form method="post" action="' . esc_url( add_query_arg( 'c', $token, home_url( '/guide/unsubscribe/' ) ) ) . '">';
			$main .= lld_button( array( 'label' => 'Unsubscribe' ) ) . '</form>';
			lld_members_layout( array( 'title' => 'Unsubscribe', 'main' => $main, 'signed_in' => $in ) );
			return;
		}
		self::consent( $email, 'unsubscribe' );
		lld_members_layout( array( 'title' => 'Unsubscribed', 'main' => '<h1>Unsubscribed</h1><p>You won\'t get these emails any more.</p>', 'signed_in' => $in ) );
	}

	/**
	 * DM-7: contacts are deleted 30 days after unsubscribing, and unconfirmed sign-ups 30 days after the last request.
	 *
	 * @param int $now Unix time to judge from (tests only pass a later time).
	 */
	public static function sweep_contacts( $now = 0 ) {
		global $wpdb;
		$now      = $now > 0 ? (int) $now : time();
		$cut      = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$contacts = LLD_Members_Schema::table( 'contact' );
		$consents = LLD_Members_Schema::table( 'contact_consent' );
		$requests = LLD_Members_Schema::table( 'contact_request' );
		$removed  = 0;
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$contacts}" ) as $id ) {
			$id   = (int) $id;
			$last = $wpdb->get_row( $wpdb->prepare( "SELECT action, occurred_at FROM {$consents} WHERE contact_id = %d ORDER BY id DESC LIMIT 1", $id ) );
			if ( $last && 'unsubscribe' === (string) $last->action ) {
				$gone = (string) $last->occurred_at < $cut;
			} elseif ( $last ) {
				$gone = false;
			} else {
				$asked = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(requested_on) FROM {$requests} WHERE contact_id = %d", $id ) );
				$gone  = '' === $asked || $asked < substr( $cut, 0, 10 );
			}
			if ( ! $gone ) {
				continue;
			}
			$wpdb->delete( $requests, array( 'contact_id' => $id ), array( '%d' ) );
			$wpdb->delete( $consents, array( 'contact_id' => $id ), array( '%d' ) );
			$wpdb->delete( $contacts, array( 'id' => $id ), array( '%d' ) );
			++$removed;
		}
		return $removed;
	}

	public static function waitlist_screen( $track ) {
		if ( ! isset( self::TRACKS[ $track ] ) ) {
			status_header( 404 );
			return;
		}
		$name = self::TRACKS[ $track ];
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_guide_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_guide_nonce'] ) ), 'lld_guide' ) ) {
			$email = isset( $_POST['lld_email'] ) ? sanitize_email( wp_unslash( $_POST['lld_email'] ) ) : '';
			if ( self::request( $email, $track, 'waitlist' ) ) {
				self::queue( 'waitlist_joined', array( 'track' => $track ) );
			}
			$main = '<h1>' . esc_html( $name ) . ' is coming soon</h1><p>Check your email to confirm.</p>';
			lld_members_layout( array( 'title' => 'Coming soon', 'main' => $main ) );
			return;
		}
		$main  = '<h1>' . esc_html( $name ) . ' is coming soon</h1>';
		$main .= '<p>Four weekly themes, then the full track opens.</p>';
		$main .= '<ol><li>Week 1: getting started</li><li>Week 2: a steady rhythm</li><li>Week 3: building on it</li><li>Week 4: keeping it going</li></ol>';
		$main .= '<p>Launch discount: 50% off the first month.</p>';
		$main .= self::guide_form( $track, 'waitlist' );
		lld_members_layout( array( 'title' => $name . ' coming soon', 'main' => $main ) );
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
				$body  = "Support message\nFrom: {$name}\n";
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

	/**
	 * More: help, and the member's own testimonial with a way to withdraw permission (F4).
	 */
	public static function more() {
		LLD_Members_Personal::require_page();
		$member_id = LLD_Members_Access::current_member_id();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_sign_out_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_sign_out_nonce'] ) ), 'lld_sign_out' ) ) {
			self::sign_out( $member_id );
		}
		// The More list (Screen Design section 6): every member screen that is not in the bottom bar, then Sign out.
		$items = array(
			array( 'Workouts', '/members/workout/', 'Your level, this week\'s sessions and moving up a level.' ),
			array( 'Monthly challenge', '/members/challenge/', 'This month\'s 28-day challenge and today\'s action.' ),
			array( 'Progress and badges', '/members/badges/', 'Your totals, check-in charts and badges.' ),
			array( 'Suggestions', '/members/suggestions/', 'Articles and ideas chosen for your goals.' ),
			array( 'Members\' group', '/members/group/', 'The private group and the live Q&A.' ),
			array( 'Profile and settings', '/members/settings/', 'Name, email, password, reminders and consents.' ),
			array( 'Health profile', '/members/health/', 'Height, weight, diet and conditions that keep content safe for you.' ),
			array( 'My subscription', '/members/subscription/', 'Your plan, receipts and referral link.' ),
			array( 'My data', '/members/data/', 'Download everything we hold about you.' ),
			array( 'Help', '/members/help/', 'Questions and answers, and the contact form.' ),
		);
		$main = '<h1>More</h1><ul class="lld-more-list">';
		foreach ( $items as $item ) {
			$main .= '<li><a href="' . esc_url( home_url( $item[1] ) ) . '"' . lld_members_current( home_url( $item[1] ) ) . '><strong>' . esc_html( $item[0] ) . '</strong><span>' . esc_html( $item[2] ) . '</span></a></li>';
		}
		$main .= '</ul>';
		$main .= '<form method="post" class="lld-sign-out">' . wp_nonce_field( 'lld_sign_out', 'lld_sign_out_nonce', true, false );
		$main .= lld_button( array( 'label' => 'Sign out', 'variant' => 'secondary' ) ) . '</form>';
		if ( $member_id > 0 ) {
			$main .= self::own_quote_html( $member_id );
		}
		lld_members_layout(
			array(
				'title'     => 'More',
				'main'      => $main,
				'signed_in' => $member_id > 0,
			)
		);
	}

	/**
	 * Ends this browser's session: the member's latest open session line is closed (the one the remember-me check
	 * reads) and WordPress drops this browser's sign-in. Other devices stay signed in.
	 *
	 * @param int $member_id Member number.
	 */
	private static function sign_out( $member_id ) {
		global $wpdb;
		if ( $member_id > 0 ) {
			$table = LLD_Members_Schema::table( 'session' );
			$open  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND ended_at IS NULL ORDER BY id DESC LIMIT 1", $member_id ) );
			if ( $open ) {
				$wpdb->update( $table, array( 'ended_at' => lld_members_now_mysql( $member_id ) ), array( 'id' => $open ), array( '%s' ), array( '%d' ) );
			}
		}
		wp_logout();
		wp_safe_redirect( home_url( '/members/sign-in/?signed_out=1' ) );
		exit;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function own_quote_html( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'survey' );
		$note  = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_quote_withdraw_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_quote_withdraw_nonce'] ) ), 'lld_quote_withdraw_' . $member_id ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET permission = 0, quote_text = '', approved_on = NULL WHERE member_id = %d AND permission = 1", $member_id ) );
			$note = '<p>Permission withdrawn. Your words are no longer shown.</p>';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT quote_text, approved_on FROM {$table} WHERE member_id = %d AND permission = 1 ORDER BY id ASC", $member_id ) );
		if ( ! $rows && '' === $note ) {
			return '';
		}
		$html = '<section data-lld-own-quote><h2>Your testimonial</h2>' . $note;
		foreach ( (array) $rows as $row ) {
			$html .= '<blockquote>' . esc_html( (string) $row->quote_text ) . '</blockquote>';
			$html .= '<p>' . ( $row->approved_on ? 'Shown on the site.' : 'Waiting for the owner to check it.' ) . '</p>';
		}
		if ( $rows ) {
			$html .= '<form method="post">' . wp_nonce_field( 'lld_quote_withdraw_' . $member_id, 'lld_quote_withdraw_nonce', true, false );
			$html .= lld_button( array( 'label' => 'Withdraw permission to quote me' ) ) . '</form>';
		}
		return $html . '</section>';
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
		$allow   = 4 === $week && ! empty( $_POST['lld_permission'] ) && '' !== $open;
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
		$rows = $wpdb->get_results( 'SELECT quote_text, display_name, country FROM ' . LLD_Members_Schema::table( 'survey' ) . " WHERE permission = 1 AND approved_on IS NOT NULL AND quote_text <> '' ORDER BY id ASC" );
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

	/**
	 * Owner approval (AD-01, FR2-19). The owner reads the exact words before approving, and can reject or take one down.
	 */
	public static function admin_quotes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'survey' );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_quote_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_quote_nonce'] ) ), 'lld_quote' ) ) {
			$id   = isset( $_POST['lld_survey'] ) ? (int) $_POST['lld_survey'] : 0;
			$what = isset( $_POST['lld_quote_action'] ) ? sanitize_key( wp_unslash( $_POST['lld_quote_action'] ) ) : '';
			if ( 'approve' === $what ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET approved_on = %s WHERE id = %d AND permission = 1 AND quote_text <> ''", gmdate( 'Y-m-d' ), $id ) );
				echo '<div class="notice notice-success"><p>Approved.</p></div>';
			} elseif ( 'reject' === $what ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET permission = 0, quote_text = '', approved_on = NULL WHERE id = %d", $id ) );
				echo '<div class="notice notice-success"><p>Rejected. The quote has been removed.</p></div>';
			}
		}
		$rows = $wpdb->get_results( "SELECT id, display_name, country, quote_text, approved_on FROM {$table} WHERE permission = 1 AND quote_text <> '' ORDER BY approved_on IS NOT NULL, id ASC" );
		echo '<div class="wrap"><h1>Testimonials</h1>';
		echo '<p>Check that a quote makes no claim about a medical result before you approve it.</p><ul>';
		if ( ! $rows ) {
			echo '<li>None waiting.</li>';
		}
		foreach ( (array) $rows as $row ) {
			echo '<li data-lld-quote-row="' . (int) $row->id . '">';
			echo '<blockquote>' . esc_html( (string) $row->quote_text ) . '</blockquote>';
			echo '<p>' . esc_html( (string) $row->display_name ) . ', ' . esc_html( (string) $row->country ) . ' &middot; ' . ( $row->approved_on ? 'Published ' . esc_html( (string) $row->approved_on ) : 'Waiting' ) . '</p>';
			echo '<form method="post">';
			wp_nonce_field( 'lld_quote', 'lld_quote_nonce' );
			echo '<input type="hidden" name="lld_survey" value="' . (int) $row->id . '">';
			if ( ! $row->approved_on ) {
				echo '<button class="button button-primary" type="submit" name="lld_quote_action" value="approve">Approve</button> ';
			}
			echo '<button class="button" type="submit" name="lld_quote_action" value="reject">' . ( $row->approved_on ? 'Take down' : 'Reject' ) . '</button>';
			echo '</form></li>';
		}
		echo '</ul></div>';
	}

	public static function admin_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$last = $wpdb->get_row( 'SELECT week_starting, totals FROM ' . LLD_Members_Schema::table( 'weekly_report' ) . ' ORDER BY week_starting DESC LIMIT 1' );
		echo '<div class="wrap"><h1>Weekly report</h1>';
		if ( $last ) {
			echo '<h2>Week starting ' . esc_html( (string) $last->week_starting ) . '</h2><pre>' . esc_html( (string) $last->totals ) . '</pre>';
		}
		echo '<h2>This week so far</h2><pre>' . esc_html( self::report_text( self::current_week(), false ) ) . '</pre></div>';
	}

	/**
	 * Events the site records itself. Other classes call do_action( 'lld_members_event', $name, $member_id, $props ).
	 *
	 * @param string              $name      registered, track_started, upgraded, renewed or cancelled.
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $props     track, plan, switched (renewal that moved monthly to yearly) or reason.
	 */
	public static function on_event( $name, $member_id = 0, $props = array() ) {
		$member_id = (int) $member_id;
		$props     = is_array( $props ) ? $props : array();
		if ( $member_id < 1 ) {
			return;
		}
		switch ( (string) $name ) {
			case 'registered':
				self::attach_source( $member_id );
				self::bump_total( 'registrations' );
				self::later( $member_id, 'registered', array() );
				break;
			case 'track_started':
				$track = isset( $props['track'] ) ? sanitize_title( (string) $props['track'] ) : '';
				if ( ! isset( self::TRACKS[ $track ] ) ) {
					return;
				}
				self::count( $track, 'track_starts' );
				if ( 1 === self::period_count( $member_id ) ) {
					self::count( $track, 'registrations' );
				}
				self::later( $member_id, 'track_started', array( 'track' => $track ) );
				break;
			case 'upgraded':
				$plan = isset( $props['plan'] ) && 'yearly' === $props['plan'] ? 'yearly' : 'monthly';
				self::count( self::member_track( $member_id ), 'upgrades' );
				self::later( $member_id, 'upgraded', array( 'plan' => $plan ) );
				break;
			case 'renewed':
				$track = self::member_track( $member_id );
				self::count( $track, 'renewals' );
				self::source_event( $member_id, 'renewals' );
				if ( ! empty( $props['switched'] ) ) {
					self::count( $track, 'switches' );
					self::later( $member_id, 'switched_to_yearly', array() );
				}
				break;
			case 'cancelled':
				$track = self::member_track( $member_id );
				self::count( $track, 'cancellations' );
				$reason = isset( $props['reason'] ) ? sanitize_key( (string) $props['reason'] ) : '';
				self::count_reason( $track, in_array( $reason, self::REASONS, true ) ? $reason : 'not_given' );
				self::source_event( $member_id, 'cancellations' );
				break;
		}
	}

	/**
	 * Week 1 is complete once the member's programme reaches day 8. Counted once per enrolment.
	 *
	 * @param int $member_id Member number.
	 */
	public static function check_week1( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Programme' ) ) {
			return;
		}
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row || LLD_Members_Programme::programme_day( $member_id, (int) $row->id ) < 8 ) {
			return;
		}
		if ( ! add_option( 'lld_funnel_w1_' . (int) $row->id, '1', '', false ) ) {
			return;
		}
		$track = (string) $row->track_key;
		self::count( $track, 'week1' );
		self::later( $member_id, 'week1_completed', array( 'track' => $track ) );
	}

	/**
	 * Members who do not visit still have week 1 counted by the daily run.
	 */
	public static function sweep_week1() {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT DISTINCT member_id FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE status = 'active'" );
		foreach ( (array) $ids as $id ) {
			self::check_week1( (int) $id );
		}
	}

	/**
	 * The first tagged link a person arrived by, saved on the account once at registration.
	 *
	 * @param int $member_id Member number.
	 */
	private static function attach_source( $member_id ) {
		$source = isset( $_COOKIE['lld_src'] ) ? sanitize_title( wp_unslash( $_COOKIE['lld_src'] ) ) : '';
		if ( '' === $source ) {
			return;
		}
		global $wpdb;
		$table   = LLD_Members_Schema::table( 'member' );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET arrived_from = %s WHERE id = %d AND ( arrived_from IS NULL OR arrived_from = '' )", $source, $member_id ) );
		if ( 1 === (int) $updated ) {
			self::bump_source( $source, 'signups' );
		}
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $kind      renewals or cancellations.
	 */
	private static function source_event( $member_id, $kind ) {
		global $wpdb;
		$source = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT arrived_from FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		if ( '' !== $source ) {
			self::bump_source( $source, $kind );
		}
	}

	/**
	 * Number of programme periods across all of a member's enrolments. One means this is their first track.
	 *
	 * @param int $member_id Member number.
	 */
	private static function period_count( $member_id ) {
		global $wpdb;
		$periods = LLD_Members_Schema::table( 'enrolment_period' );
		$enrol   = LLD_Members_Schema::table( 'enrolment' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$periods} p INNER JOIN {$enrol} e ON e.id = p.enrolment_id WHERE e.member_id = %d", $member_id ) );
	}

	/**
	 * The member's current track, else their latest one, else "none".
	 *
	 * @param int $member_id Member number.
	 */
	private static function member_track( $member_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'enrolment' );
		$track = (string) $wpdb->get_var( $wpdb->prepare( "SELECT track_key FROM {$table} WHERE member_id = %d ORDER BY status = 'active' DESC, id DESC LIMIT 1", $member_id ) );
		return isset( self::TRACKS[ $track ] ) ? $track : 'none';
	}

	/**
	 * Plausible site domain from wp-config.php (LLD_PLAUSIBLE_DOMAIN). Empty means nothing is sent.
	 * Test sites (ci, local) may set it through the test route instead.
	 */
	public static function plausible_domain() {
		$domain = defined( 'LLD_PLAUSIBLE_DOMAIN' ) ? (string) LLD_PLAUSIBLE_DOMAIN : '';
		if ( '' === $domain && lld_members_test_routes_open() ) {
			$domain = (string) get_option( 'lld_test_plausible_domain', '' );
		}
		$domain = strtolower( trim( $domain ) );
		return preg_match( '/^[a-z0-9][a-z0-9.-]{0,252}$/', $domain ) ? $domain : '';
	}

	/**
	 * Read-only Stats API key (LLD_PLAUSIBLE_STATS_KEY). Test sites use a stub key while the stub is set.
	 */
	private static function plausible_key() {
		if ( defined( 'LLD_PLAUSIBLE_STATS_KEY' ) && '' !== (string) LLD_PLAUSIBLE_STATS_KEY ) {
			return (string) LLD_PLAUSIBLE_STATS_KEY;
		}
		if ( lld_members_test_routes_open() && is_array( get_option( 'lld_test_plausible_stats' ) ) ) {
			return 'test-stub';
		}
		return '';
	}

	/**
	 * Events for this page, plus any the site recorded for this member since their last page.
	 */
	public static function events_json() {
		$events = self::$queue;
		self::$queue = array();
		$member_id   = LLD_Members_Access::current_member_id();
		if ( $member_id > 0 ) {
			$key     = 'lld_events_' . $member_id;
			$pending = get_transient( $key );
			if ( is_array( $pending ) ) {
				$events = array_merge( $pending, $events );
				delete_transient( $key );
			}
		}
		return $events;
	}

	public static function footer_script() {
		if ( is_admin() ) {
			return;
		}
		echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded with hex escapes.
	}

	/**
	 * Plausible Events API: top-level name, url and domain; props only from the section 8.5 allowlist.
	 */
	public static function script() {
		$domain = self::plausible_domain();
		if ( '' === $domain ) {
			return '';
		}
		$config = wp_json_encode(
			array(
				'domain' => $domain,
				'events' => self::events_json(),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		$allow = wp_json_encode( self::allowlist() );
		$js    = '(function(){var node=document.getElementById("lld-analytics");if(!node)return;var cfg;try{cfg=JSON.parse(node.textContent||"{}");}catch(e){return;}if(!cfg||!cfg.domain)return;'
			. 'var allow=' . $allow . ';'
			. 'function clean(href){var u=new URL(href,location.origin);var path=u.pathname.replace(/^\/members\/(record|photo|diary-photo)\/\d+/,"/members/$1/0");var q=[];["utm_source","utm_medium","utm_campaign"].forEach(function(key){var value=u.searchParams.get(key);if(value&&/^[A-Za-z0-9_-]{1,64}$/.test(value))q.push(key+"="+encodeURIComponent(value));});return u.origin+path+(q.length?"?"+q.join("&"):"");}'
			. 'function send(name,props){if(!allow[name])return;var safe={},n=0;allow[name].forEach(function(key){var value=props?props[key]:null;if(typeof value==="string"&&/^[a-z0-9-]{1,32}$/.test(value)){safe[key]=value;n++;}});var body={name:name,url:clean(location.href),domain:cfg.domain};if(n)body.props=safe;try{fetch("' . self::PLAUSIBLE_EVENTS . '",{method:"POST",headers:{"Content-Type":"text/plain"},body:JSON.stringify(body),keepalive:true}).catch(function(){});}catch(e){}}'
			. 'send("pageview",{});(cfg.events||[]).forEach(function(event){if(event&&event.name)send(event.name,event.props||{});});'
			. 'var used={};window.addEventListener("message",function(event){if(event.origin!==location.origin)return;var data=event.data||{};if(data.type!=="lld-calculator-result")return;var which=["protein","diabetes","healthy-weight"].indexOf(data.calculator)>=0?data.calculator:"";if(!which||used[which])return;used[which]=1;send("calculator_used",{calculator:which});});'
			. '})();';
		return '<script id="lld-analytics" type="application/json">' . $config . '</script><script>' . $js . '</script>';
	}

	/**
	 * @param string               $name  Event name.
	 * @param array<string,string> $props Props.
	 */
	public static function queue( $name, $props ) {
		$clean = self::clean_event( $name, $props );
		if ( null !== $clean ) {
			self::$queue[] = $clean;
		}
	}

	/**
	 * Keep an event for the member's next page, where their own browser sends it like any page view.
	 *
	 * @param int                  $member_id Member number.
	 * @param string               $name      Event.
	 * @param array<string,string> $props     Props.
	 */
	private static function later( $member_id, $name, $props ) {
		if ( '' === self::plausible_domain() ) {
			return;
		}
		$clean = self::clean_event( $name, $props );
		if ( null === $clean ) {
			return;
		}
		$key     = 'lld_events_' . (int) $member_id;
		$pending = get_transient( $key );
		$pending = is_array( $pending ) ? array_slice( $pending, -19 ) : array();
		$pending[] = $clean;
		set_transient( $key, $pending, DAY_IN_SECONDS );
	}

	/**
	 * @param string              $name  Event.
	 * @param array<string,mixed> $props Props.
	 * @return array{name:string,props:array<string,string>}|null
	 */
	private static function clean_event( $name, $props ) {
		$allow = self::allowlist();
		$name  = (string) $name;
		if ( ! isset( $allow[ $name ] ) || ! is_array( $props ) ) {
			return null;
		}
		$clean = array();
		foreach ( $props as $key => $value ) {
			$key   = (string) $key;
			$value = (string) $value;
			if ( ! in_array( $key, $allow[ $name ], true ) || ! preg_match( '/^[a-z0-9-]{1,32}$/', $value ) ) {
				return null;
			}
			$clean[ $key ] = $value;
		}
		return array(
			'name'  => $name,
			'props' => $clean,
		);
	}

	/**
	 * Requirements section 8.5. Page view props are empty: the address itself is the page view.
	 *
	 * @return array<string,string[]>
	 */
	private static function allowlist() {
		return array(
			'pageview'                => array(),
			'calculator_used'         => array( 'calculator' ),
			'registered'              => array(),
			'track_started'           => array( 'track' ),
			'week1_completed'         => array( 'track' ),
			'upgraded'                => array( 'plan' ),
			'switched_to_yearly'      => array(),
			'starter_guide_requested' => array( 'track' ),
			'waitlist_joined'         => array( 'track' ),
		);
	}

	public static function monday_report() {
		self::send_report();
	}

	/**
	 * Email the previous week's report to the owner once, keep the totals (F6), and start the new week's counts.
	 */
	public static function send_report() {
		$week = self::report_week();
		global $wpdb;
		$table = LLD_Members_Schema::table( 'weekly_report' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE week_starting = %s", $week[0] ) ) ) {
			return false;
		}
		$text = self::report_text( $week, true );
		$wpdb->insert(
			$table,
			array(
				'week_starting' => $week[0],
				'totals'        => $text,
			),
			array( '%s', '%s' )
		);
		update_option( 'lld_funnel', self::empty_funnel(), false );
		wp_mail( (string) get_option( 'admin_email' ), 'Weekly report', $text );
		return true;
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

	/**
	 * Test sites only (ci, local, with the test secret).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$action = isset( $params['action'] ) ? sanitize_key( (string) $params['action'] ) : 'figures';
		if ( 'reset' === $action ) {
			global $wpdb;
			update_option( 'lld_funnel', self::empty_funnel(), false );
			$wpdb->delete( LLD_Members_Schema::table( 'weekly_report' ), array( 'week_starting' => self::report_week()[0] ), array( '%s' ) );
			delete_option( 'lld_test_plausible_stats' );
			delete_option( 'lld_test_plausible_seen' );
			delete_option( 'lld_test_plausible_domain' );
			return new WP_REST_Response( array( 'reset' => true ), 200 );
		}
		if ( 'domain' === $action ) {
			$domain = isset( $params['domain'] ) ? (string) $params['domain'] : '';
			if ( '' === $domain ) {
				delete_option( 'lld_test_plausible_domain' );
			} else {
				update_option( 'lld_test_plausible_domain', sanitize_text_field( $domain ), false );
			}
			return new WP_REST_Response( array( 'domain' => self::plausible_domain() ), 200 );
		}
		if ( 'plausible' === $action ) {
			$stub = array(
				'pages'      => self::stub_rows( $params['pages'] ?? array() ),
				'calculator' => self::stub_rows( $params['calculator'] ?? array() ),
			);
			update_option( 'lld_test_plausible_stats', $stub, false );
			delete_option( 'lld_test_plausible_seen' );
			if ( ! empty( $params['domain'] ) ) {
				update_option( 'lld_test_plausible_domain', sanitize_text_field( (string) $params['domain'] ), false );
			}
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( 'monday' === $action ) {
			$next = (int) wp_next_scheduled( self::REPORT_HOOK );
			do_action( self::REPORT_HOOK );
			$seen = get_option( 'lld_test_plausible_seen' );
			return new WP_REST_Response(
				array(
					'next'    => $next,
					'weekday' => $next ? wp_date( 'l H:i', $next ) : '',
					'week'    => self::report_week(),
					'queries' => is_array( $seen ) ? $seen : array(),
				),
				200
			);
		}
		if ( 'sweep' === $action ) {
			$days = isset( $params['days'] ) ? (int) $params['days'] : 0;
			return new WP_REST_Response( array( 'removed' => self::sweep_contacts( time() + $days * DAY_IN_SECONDS ) ), 200 );
		}
		if ( 'contact' === $action ) {
			$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
			return new WP_REST_Response( array( 'exists' => self::contact_id( $email, false ) > 0 ), 200 );
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
	 * @param mixed $rows List of [dimension, count].
	 * @return array<int,array{0:string,1:int}>
	 */
	private static function stub_rows( $rows ) {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && isset( $row[0], $row[1] ) ) {
				$out[] = array( sanitize_text_field( (string) $row[0] ), (int) $row[1] );
			}
		}
		return $out;
	}

	/**
	 * Test sites answer Plausible Stats API calls from the stub the test prepared, and keep each query for the test to read.
	 *
	 * @param false|array|WP_Error $pre  Response so far.
	 * @param array                $args Request arguments.
	 * @param string               $url  Address.
	 * @return false|array|WP_Error
	 */
	public static function stub_plausible( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, self::PLAUSIBLE_STATS ) ) {
			return $pre;
		}
		$stub = get_option( 'lld_test_plausible_stats' );
		if ( ! is_array( $stub ) ) {
			return $pre;
		}
		$body = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$body = is_array( $body ) ? $body : array();
		$seen = get_option( 'lld_test_plausible_seen' );
		$seen = is_array( $seen ) ? $seen : array();
		$seen[] = $body;
		update_option( 'lld_test_plausible_seen', $seen, false );
		$rows = ( isset( $body['dimensions'][0] ) && 'event:page' === $body['dimensions'][0] ) ? $stub['pages'] : $stub['calculator'];
		$results = array();
		foreach ( (array) $rows as $row ) {
			$results[] = array(
				'dimensions' => array( (string) $row[0] ),
				'metrics'    => array( (int) $row[1] ),
			);
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'results' => $results ) ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function figures() {
		$funnel = self::funnel();
		$money  = self::money_figures();
		return array(
			'since'         => $funnel['since'],
			'registrations' => $funnel['registrations'],
			'tracks'        => $funnel['tracks'],
			'sources'       => $funnel['sources'],
			'mrr'           => $money['mrr'],
			'active'        => $money['active'],
			'yearly'        => $money['yearly'],
			'monthly'       => $money['monthly'],
		);
	}

	/**
	 * Monday to Sunday before this week, in the site's time zone.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function report_week() {
		$monday = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( 'monday this week' );
		return array( $monday->modify( '-7 days' )->format( 'Y-m-d' ), $monday->modify( '-1 day' )->format( 'Y-m-d' ) );
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private static function current_week() {
		$monday = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( 'monday this week' );
		return array( $monday->format( 'Y-m-d' ), $monday->modify( '+6 days' )->format( 'Y-m-d' ) );
	}

	/**
	 * @param array{0:string,1:string} $week      First and last day.
	 * @param bool                     $plausible Ask Plausible for visits and calculator use.
	 */
	private static function report_text( $week, $plausible ) {
		$funnel  = self::funnel();
		$money   = self::money_figures();
		$visits  = $plausible ? self::plausible_figures( $week[0], $week[1] ) : null;
		$labels  = array(
			'registrations' => 'registrations',
			'track_starts'  => 'track starts',
			'week1'         => 'week 1 completions',
			'upgrades'      => 'upgrades',
			'switches'      => 'monthly-to-yearly switches',
			'renewals'      => 'renewals',
			'cancellations' => 'cancellations',
		);
		$lines   = array( 'Weekly report for ' . $week[0] . ' to ' . $week[1] );
		if ( null === $visits ) {
			$lines[] = 'Visits and calculator use: Plausible is not connected';
		}
		foreach ( self::TRACKS as $key => $label ) {
			if ( null !== $visits ) {
				$lines[] = $label . ' visits ' . (int) $visits[ $key ]['visits'];
				$lines[] = $label . ' calculator use ' . (int) $visits[ $key ]['calculator'];
			}
			foreach ( $labels as $field => $caption ) {
				$lines[] = $label . ' ' . $caption . ' ' . (int) $funnel['tracks'][ $key ][ $field ];
			}
			$lines[] = $label . ' cancellation reasons ' . self::reasons_text( $funnel['tracks'][ $key ]['reasons'] );
		}
		foreach ( array( 'upgrades', 'switches', 'renewals', 'cancellations' ) as $field ) {
			$lines[] = 'No track yet ' . $labels[ $field ] . ' ' . (int) $funnel['tracks']['none'][ $field ];
		}
		$lines[] = 'No track yet cancellation reasons ' . self::reasons_text( $funnel['tracks']['none']['reasons'] );
		$lines[] = 'All registrations ' . (int) $funnel['registrations'];
		$lines[] = 'Monthly recurring revenue ' . $money['mrr'];
		$lines[] = 'Active Premium members ' . (int) $money['active'];
		$lines[] = 'Yearly members ' . (int) $money['yearly'];
		$lines[] = 'Monthly members ' . (int) $money['monthly'];
		foreach ( $funnel['sources'] as $source => $counts ) {
			$lines[] = 'Source ' . $source . ' sign-ups ' . (int) ( $counts['signups'] ?? 0 );
			$lines[] = 'Source ' . $source . ' renewals ' . (int) ( $counts['renewals'] ?? 0 );
			$lines[] = 'Source ' . $source . ' cancellations ' . (int) ( $counts['cancellations'] ?? 0 );
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,int> $reasons Counts by reason.
	 */
	private static function reasons_text( $reasons ) {
		if ( ! is_array( $reasons ) || ! $reasons ) {
			return 'none';
		}
		ksort( $reasons );
		$parts = array();
		foreach ( $reasons as $reason => $count ) {
			$parts[] = $reason . ' ' . (int) $count;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Visits to each track's pages and calculator use, from Plausible's Stats API.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array<string,array{visits:int,calculator:int}>|null Null when Plausible is not connected or does not answer.
	 */
	private static function plausible_figures( $from, $to ) {
		$domain = self::plausible_domain();
		$key    = self::plausible_key();
		if ( '' === $domain || '' === $key ) {
			return null;
		}
		$range = array( $from, $to );
		$pages = self::plausible_query(
			array(
				'site_id'    => $domain,
				'metrics'    => array( 'pageviews' ),
				'date_range' => $range,
				'dimensions' => array( 'event:page' ),
			),
			$key
		);
		$calc  = self::plausible_query(
			array(
				'site_id'    => $domain,
				'metrics'    => array( 'events' ),
				'date_range' => $range,
				'filters'    => array( array( 'is', 'event:name', array( 'calculator_used' ) ) ),
				'dimensions' => array( 'event:props:calculator' ),
			),
			$key
		);
		if ( null === $pages || null === $calc ) {
			return null;
		}
		$out = array();
		foreach ( array_keys( self::TRACKS ) as $track ) {
			$out[ $track ] = array(
				'visits'     => 0,
				'calculator' => 0,
			);
		}
		$looked = 0;
		foreach ( $pages as $row ) {
			$path  = isset( $row['dimensions'][0] ) ? (string) $row['dimensions'][0] : '';
			$track = self::track_for_path( $path, $looked );
			if ( '' !== $track ) {
				$out[ $track ]['visits'] += isset( $row['metrics'][0] ) ? (int) $row['metrics'][0] : 0;
			}
		}
		foreach ( $calc as $row ) {
			$which = isset( $row['dimensions'][0] ) ? (string) $row['dimensions'][0] : '';
			$track = self::calculator_track( $which );
			if ( '' !== $track ) {
				$out[ $track ]['calculator'] += isset( $row['metrics'][0] ) ? (int) $row['metrics'][0] : 0;
			}
		}
		return $out;
	}

	/**
	 * Each calculator's related track, the same link its landing page shows.
	 *
	 * @param string $which protein or diabetes.
	 */
	private static function calculator_track( $which ) {
		if ( 'diabetes' === $which ) {
			return 'blood-sugar';
		}
		if ( 'healthy-weight' === $which ) {
			return 'healthy-weight';
		}
		return 'protein' === $which ? 'fitness' : '';
	}

	/**
	 * @param string $path   Page path.
	 * @param int    $looked Article look-ups so far (capped).
	 */
	private static function track_for_path( $path, &$looked ) {
		$slugs = implode( '|', array_keys( self::TRACKS ) );
		if ( preg_match( '#^/(?:programmes|coming-soon)/(' . $slugs . ')/?$#', $path, $match ) ) {
			return $match[1];
		}
		if ( preg_match( '#^/health-tools/protein/?$#', $path ) ) {
			return self::calculator_track( 'protein' );
		}
		if ( preg_match( '#^/health-tools/diabetes-risk-check/?$#', $path ) ) {
			return self::calculator_track( 'diabetes' );
		}
		if ( preg_match( '#^/health-tools/healthy-weight-check/?$#', $path ) ) {
			return self::calculator_track( 'healthy-weight' );
		}
		if ( $looked >= 200 || 0 === strpos( $path, '/members/' ) ) {
			return '';
		}
		++$looked;
		$post_id = url_to_postid( home_url( $path ) );
		return $post_id ? self::article_track( $post_id ) : '';
	}

	/**
	 * @param array<string,mixed> $body Query.
	 * @param string              $key  Stats API key.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function plausible_query( $body, $key ) {
		$res = wp_remote_post(
			self::PLAUSIBLE_STATS,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) && isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : null;
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
	 * Figures the site counts itself, per track.
	 *
	 * @return string[]
	 */
	private static function track_keys() {
		return array( 'registrations', 'track_starts', 'week1', 'upgrades', 'switches', 'renewals', 'cancellations' );
	}

	/**
	 * @return array{since:string,registrations:int,tracks:array<string,array<string,mixed>>,sources:array<string,array<string,int>>}
	 */
	private static function empty_funnel() {
		$blank            = array_fill_keys( self::track_keys(), 0 );
		$blank['reasons'] = array();
		$tracks           = array();
		foreach ( array_merge( array_keys( self::TRACKS ), array( 'none' ) ) as $track ) {
			$tracks[ $track ] = $blank;
		}
		return array(
			'since'         => wp_date( 'Y-m-d' ),
			'registrations' => 0,
			'tracks'        => $tracks,
			'sources'       => array(),
		);
	}

	/**
	 * @return array{since:string,registrations:int,tracks:array<string,array<string,mixed>>,sources:array<string,array<string,int>>}
	 */
	private static function funnel() {
		$saved = get_option( 'lld_funnel' );
		$base  = self::empty_funnel();
		if ( ! is_array( $saved ) ) {
			return $base;
		}
		if ( isset( $saved['since'] ) ) {
			$base['since'] = (string) $saved['since'];
		}
		$base['registrations'] = (int) ( $saved['registrations'] ?? 0 );
		if ( isset( $saved['tracks'] ) && is_array( $saved['tracks'] ) ) {
			foreach ( $base['tracks'] as $track => $blank ) {
				if ( ! isset( $saved['tracks'][ $track ] ) || ! is_array( $saved['tracks'][ $track ] ) ) {
					continue;
				}
				foreach ( self::track_keys() as $key ) {
					$base['tracks'][ $track ][ $key ] = (int) ( $saved['tracks'][ $track ][ $key ] ?? 0 );
				}
				$reasons = $saved['tracks'][ $track ]['reasons'] ?? array();
				$base['tracks'][ $track ]['reasons'] = is_array( $reasons ) ? array_map( 'intval', $reasons ) : array();
			}
		}
		if ( isset( $saved['sources'] ) && is_array( $saved['sources'] ) ) {
			$base['sources'] = $saved['sources'];
		}
		return $base;
	}

	/**
	 * @param string $track Track or "none".
	 * @param string $key   Figure.
	 */
	private static function count( $track, $key ) {
		$funnel = self::funnel();
		if ( ! isset( $funnel['tracks'][ $track ] ) ) {
			$track = 'none';
		}
		if ( ! isset( $funnel['tracks'][ $track ][ $key ] ) ) {
			return;
		}
		++$funnel['tracks'][ $track ][ $key ];
		update_option( 'lld_funnel', $funnel, false );
	}

	/**
	 * @param string $track  Track or "none".
	 * @param string $reason Reason key.
	 */
	private static function count_reason( $track, $reason ) {
		$funnel = self::funnel();
		if ( ! isset( $funnel['tracks'][ $track ] ) ) {
			$track = 'none';
		}
		$reasons            = $funnel['tracks'][ $track ]['reasons'];
		$reasons[ $reason ] = (int) ( $reasons[ $reason ] ?? 0 ) + 1;
		$funnel['tracks'][ $track ]['reasons'] = $reasons;
		update_option( 'lld_funnel', $funnel, false );
	}

	/**
	 * @param string $key Overall figure.
	 */
	private static function bump_total( $key ) {
		$funnel = self::funnel();
		$funnel[ $key ] = (int) ( $funnel[ $key ] ?? 0 ) + 1;
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
		$funnel['sources'][ $source ][ $key ] = (int) ( $funnel['sources'][ $source ][ $key ] ?? 0 ) + 1;
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
		$url = add_query_arg( 'c', LLD_Members_Mail::token( 'guide-confirm', self::contact_id( $email, false ), time() ), home_url( '/guide/confirm/' ) );
		wp_mail( $email, 'Confirm your starter guide', "Confirm your email:\n" . $url . "\n" . self::unsub_line( $email ) );
	}

	/**
	 * @param string        $email  Email.
	 * @param string[]|null $tracks Only these guides, or every guide the contact asked for.
	 */
	private static function send_guides( $email, $tracks = null ) {
		if ( self::unsubscribed( $email ) || ! self::confirmed( $email ) ) {
			return;
		}
		if ( null === $tracks ) {
			global $wpdb;
			$id     = self::contact_id( $email, false );
			$tracks = $wpdb->get_col( $wpdb->prepare( 'SELECT track_key FROM ' . LLD_Members_Schema::table( 'contact_request' ) . " WHERE contact_id = %d AND kind = 'guide'", $id ) );
		}
		if ( ! $tracks ) {
			return;
		}
		$lines = array( 'Your starter guide' );
		foreach ( $tracks as $track ) {
			$lines[] = home_url( '/programmes/' . sanitize_title( (string) $track ) . '/' );
		}
		$lines[] = self::unsub_line( $email );
		wp_mail( $email, 'Your starter guide', implode( "\n", $lines ) );
	}

	/**
	 * Unsubscribe link: a signed contact token, never the email address. It does not expire.
	 *
	 * @param string $email Email.
	 */
	private static function unsub_line( $email ) {
		return 'Unsubscribe: ' . add_query_arg( 'c', LLD_Members_Mail::token( 'guide-unsub', self::contact_id( $email, false ) ), home_url( '/guide/unsubscribe/' ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT first_name, email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}
}
