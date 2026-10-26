<?php
/**
 * Premium meals, diary, challenges, workouts, badges and the group list.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safety runs before a premium item is shown. Free members keep the membership lock.
 */
class LLD_Members_Premium {

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 11 );
	}

	public static function admin_menu() {
		add_submenu_page( 'lld-payments', 'Group removals', 'Group removals', 'manage_options', 'lld-group', array( __CLASS__, 'admin_page' ) );
	}

	/**
	 * People whose Premium has ended, and the weekly removal list.
	 */
	public static function daily() {
		self::catch_ended();
		self::mail_removals();
		self::flag_late();
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $on        Local date Premium ended.
	 */
	public static function note_ended( $member_id, $on ) {
		global $wpdb;
		$member_id = (int) $member_id;
		if ( $member_id < 1 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $on ) ) {
			return;
		}
		$table = LLD_Members_Schema::table( 'fb_removal' );
		$known = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_number = %d LIMIT 1", $member_id ) );
		if ( $known ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'member_number'    => $member_id,
				'premium_ended_on' => $on,
				'late'             => 0,
			),
			array( '%d', '%s', '%d' )
		);
	}

	public static function meals() {
		$member_id = LLD_Members_Access::current_member_id();
		if ( isset( $_GET['pdf'] ) ) {
			self::send_shopping_pdf( $member_id );
		}
		self::take_swap( $member_id );
		$plan = self::meal_view( $member_id );
		$main = '<h1>Meal plan</h1>';
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			$main .= '<p>General plan</p>';
		}
		if ( ! $plan['days'] ) {
			$main .= '<p>' . esc_html( LLD_Members_Safety::empty_line( $member_id ) ) . '</p><p>Contact support if you need a plan that fits you.</p>';
			self::page( 'Meal plan', $main );
			return;
		}
		foreach ( $plan['days'] as $day => $meals ) {
			$main .= '<section data-lld-day="' . (int) $day . '"><h2>Day ' . (int) $day . '</h2>';
			foreach ( $meals as $slot => $recipe ) {
				$main .= '<p>' . esc_html( ucfirst( (string) $slot ) ) . ': ' . esc_html( $recipe['title'] ) . '</p>';
			}
			$main .= '</section>';
		}
		if ( $plan['swap_slot'] && $plan['swap_options'] && LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			$main .= '<form method="post" class="lld-form">';
			$main .= wp_nonce_field( 'lld_swap', 'lld_swap_nonce', true, false );
			$main .= '<input type="hidden" name="lld_slot" value="' . esc_attr( $plan['swap_slot'] ) . '">';
			$main .= '<p><label for="lld_recipe">Swap Day 1 breakfast</label> <select id="lld_recipe" name="lld_recipe">';
			foreach ( $plan['swap_options'] as $option ) {
				$main .= '<option value="' . (int) $option['id'] . '">' . esc_html( $option['title'] ) . '</option>';
			}
			$main .= '</select></p>';
			$main .= lld_button( array( 'label' => 'Swap' ) );
			$main .= '</form>';
		}
		$main .= '<h2>Shopping list</h2><ul data-lld-shopping>';
		foreach ( $plan['shopping'] as $line ) {
			$main .= '<li>' . esc_html( $line ) . '</li>';
		}
		$main .= '</ul>';
		$main .= '<p><button type="button" onclick="window.print()">Print</button> ';
		$main .= '<a href="' . esc_url( home_url( '/members/meals/?pdf=1' ) ) . '">Download PDF</a></p>';
		self::page( 'Meal plan', $main );
	}

	public static function workout() {
		$member_id = LLD_Members_Access::current_member_id();
		$consent   = LLD_Members_Account::has_consent( $member_id, 'health' );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_done_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_done_nonce'] ) ), 'lld_done' ) ) {
			if ( $consent ) {
				self::store_result( $member_id, 'workout', isset( $_POST['lld_session'] ) ? (int) $_POST['lld_session'] : 1 );
			}
		}
		$level = isset( $_REQUEST['level'] ) ? sanitize_key( wp_unslash( $_REQUEST['level'] ) ) : 'beginner';
		$place = isset( $_REQUEST['place'] ) ? sanitize_key( wp_unslash( $_REQUEST['place'] ) ) : 'home';
		if ( ! in_array( $level, array( 'beginner', 'intermediate', 'active' ), true ) ) {
			$level = 'beginner';
		}
		if ( ! in_array( $place, array( 'home', 'equipment' ), true ) ) {
			$place = 'home';
		}
		if ( $consent && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_plan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_plan_nonce'] ) ), 'lld_plan' ) ) {
			self::save_workout( $member_id, $level, $place );
		}
		$week    = 1;
		$plan    = self::workout_plan( $level, $place, $week );
		$session = isset( $_GET['session'] ) ? max( 1, (int) $_GET['session'] ) : 0;
		$main    = '<h1>Workout</h1>';
		$main   .= LLD_Members_Safety::cautions_html( $member_id, array( 'type' => 'lld_workout' ) );
		if ( ! $consent ) {
			$main .= '<p>progress isn\'t saved</p>';
		}
		$main .= '<form method="get" class="lld-form"><p><label for="lld_level">Level</label> <select id="lld_level" name="level">';
		foreach ( array( 'beginner', 'intermediate', 'active' ) as $choice ) {
			$main .= '<option value="' . esc_attr( $choice ) . '"' . selected( $level, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
		}
		$main .= '</select> <label for="lld_place">Version</label> <select id="lld_place" name="place">';
		foreach ( array( 'home', 'equipment' ) as $choice ) {
			$main .= '<option value="' . esc_attr( $choice ) . '"' . selected( $place, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
		}
		$main .= '</select> <button class="lld-button" type="submit">Show this week</button></p></form>';
		if ( $consent ) {
			$main .= '<form method="post"><p>';
			$main .= wp_nonce_field( 'lld_plan', 'lld_plan_nonce', true, false );
			$main .= '<input type="hidden" name="level" value="' . esc_attr( $level ) . '"><input type="hidden" name="place" value="' . esc_attr( $place ) . '">';
			$main .= '<button class="lld-button" type="submit">Move up a level</button></p></form>';
		}
		if ( ! $plan ) {
			$main .= LLD_Members_Safety::empty_html( $member_id );
			self::page( 'Workout', $main );
			return;
		}
		$main .= '<h2>Week ' . (int) $week . '</h2>';
		$index = 0;
		foreach ( $plan['sessions'] as $row ) {
			++$index;
			$href  = home_url( '/members/workout/?level=' . rawurlencode( $level ) . '&place=' . rawurlencode( $place ) . '&session=' . $index );
			$main .= '<p><a href="' . esc_url( $href ) . '">' . esc_html( $row['name'] ) . '</a></p>';
		}
		if ( $session > 0 && isset( $plan['sessions'][ $session - 1 ] ) ) {
			$row   = $plan['sessions'][ $session - 1 ];
			$steps = (int) $row['seconds'] . '|' . (int) $row['rest'];
			$main .= '<section id="lld-safety"><h2>Safety advice</h2><p>' . esc_html( $plan['safety'] ) . '</p>';
			$main .= '<p><button type="button" class="lld-button" id="lld-ready">I\'m ready</button></p></section>';
			$main .= '<section id="lld-session" hidden><h2 id="lld-exercise">' . esc_html( $row['name'] ) . '</h2>';
			$main .= '<p id="lld-timer" data-steps="' . esc_attr( $steps ) . '">' . (int) $row['seconds'] . '</p>';
			$main .= '<p id="lld-phase">exercise</p>';
			$main .= '<p><button type="button" class="lld-button" id="lld-start">Start</button> <button type="button" class="lld-button" id="lld-pause">Pause</button></p>';
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_done', 'lld_done_nonce', true, false );
			$main .= '<input type="hidden" name="lld_session" value="' . (int) $session . '">';
			$main .= '<input type="hidden" name="level" value="' . esc_attr( $level ) . '"><input type="hidden" name="place" value="' . esc_attr( $place ) . '">';
			$main .= lld_button( array( 'label' => 'Done' ) );
			$main .= '</form></section>';
		}
		self::page( 'Workout', $main );
	}

	public static function challenge() {
		$member_id = LLD_Members_Access::current_member_id();
		$consent   = LLD_Members_Account::has_consent( $member_id, 'health' );
		$today     = LLD_Members_Programme::local_today( $member_id );
		$shown     = self::current_challenge( $today );
		if ( $consent && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_join_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_join_nonce'] ) ), 'lld_join' ) && $shown ) {
			self::join_challenge( $member_id, (int) $shown['parent_id'], $today );
		}
		$row  = $shown ? self::challenge_row( $member_id, (int) $shown['parent_id'] ) : null;
		$main = '<h1>Challenge</h1>';
		if ( ! $consent ) {
			$main .= '<p>progress isn\'t saved</p>';
		}
		if ( ! $shown ) {
			$main .= LLD_Members_Safety::empty_html( $member_id );
			self::page( 'Challenge', $main );
			return;
		}
		$main .= '<h2>' . esc_html( (string) $shown['title'] ) . '</h2>';
		if ( ! $row && $consent ) {
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_join', 'lld_join_nonce', true, false );
			$main .= lld_button( array( 'label' => 'Join' ) );
			$main .= '</form>';
		}
		$activities = self::challenge_activities( $shown );
		$day        = isset( $_GET['day'] ) ? (int) $_GET['day'] : 0;
		if ( $row && $consent ) {
			$start = new DateTimeImmutable( (string) $row->joined_on );
			$now   = new DateTimeImmutable( $today );
			$day   = $day > 0 ? $day : ( (int) $start->diff( $now )->days + 1 );
			if ( $today >= $start->modify( '+27 days' )->format( 'Y-m-d' ) && ! $row->finished_on ) {
				self::finish_challenge( $member_id, $row, $shown, $today );
				$row = self::challenge_row( $member_id, (int) $shown['parent_id'] );
			}
		}
		if ( $day < 1 ) {
			$day = 1;
		}
		if ( $day > 28 ) {
			$day = 28;
		}
		for ( $i = 1; $i <= 28; $i++ ) {
			$main .= '<p><a href="' . esc_url( home_url( '/members/challenge/?day=' . $i ) ) . '">Day ' . $i . '</a></p>';
		}
		if ( isset( $activities[ $day - 1 ] ) ) {
			$main .= '<h2>Action for this day</h2><p>' . esc_html( $activities[ $day - 1 ] ) . '</p>';
		}
		if ( $row && $row->finished_on ) {
			$badge = self::badge_title( $shown );
			if ( '' !== $badge && self::badge_allowed( $member_id, $shown ) ) {
				$main .= '<p>Badge: ' . esc_html( $badge ) . '</p>';
			}
		}
		self::page( 'Challenge', $main );
	}

	public static function badges() {
		$member_id = LLD_Members_Access::current_member_id();
		if ( LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			self::award( $member_id );
		}
		$main = '<h1>Progress and badges</h1>';
		$main .= self::chart_html( $member_id );
		$main .= '<h2>Badges</h2>';
		$any  = false;
		foreach ( self::earned_badges( $member_id ) as $title ) {
			$any   = true;
			$main .= '<p>' . esc_html( $title ) . '</p>';
		}
		if ( ! $any ) {
			$main .= '<p>No badges yet.</p>';
		}
		self::page( 'Progress and badges', $main );
	}

	public static function diary() {
		$member_id = LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			$status = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? 403 : 200;
			self::page( 'Food diary', '<h1>Food diary</h1>' . lld_health_consent_notice(), $status );
			return;
		}
		$writable = LLD_Members_Billing::diary_writable( $member_id );
		$error    = '';
		if ( $writable && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_diary_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_diary_nonce'] ) ), 'lld_diary' ) ) {
			if ( ! empty( $_POST['lld_undo'] ) ) {
				self::undo_meal( $member_id, (int) $_POST['lld_undo'] );
			} elseif ( self::hides_portion( $member_id ) && array_key_exists( 'lld_portion', $_POST ) ) {
				self::page( 'Food diary', '<h1>Food diary</h1><p>The portion question is not shown.</p>', 403 );
				return;
			} else {
				$saved = self::save_meal( $member_id );
				if ( is_wp_error( $saved ) ) {
					$error = 'This file is too large or not a supported type';
				}
			}
		}
		$main = '<h1>Food diary</h1>';
		if ( '' !== $error ) {
			$main .= '<p>' . esc_html( $error ) . '</p>';
		}
		$main .= $writable ? '<p>You can add meals.</p>' : '<p>This diary is read-only.</p>';
		$main .= self::diary_list( $member_id );
		$summary = self::summary_line( $member_id );
		if ( '' !== $summary ) {
			$main .= '<p data-lld-summary>' . esc_html( $summary ) . '</p>';
		}
		$focus = self::focus_line( $member_id );
		if ( '' !== $focus ) {
			$main .= '<p>' . esc_html( $focus ) . '</p>';
		}
		if ( $writable ) {
			$main .= self::diary_form( $member_id );
		}
		self::page( 'Food diary', $main );
	}

	public static function group() {
		$member_id = LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Billing::paid_open( $member_id ) ) {
			if ( LLD_Members_Billing::ended( $member_id ) ) {
				self::page( 'Members\' group', '<h1>Members\' group</h1><p>You\'ll be removed from the group within 7 days.</p>' );
				return;
			}
			self::page( 'Members\' group', '<h1>Members\' group</h1>' . lld_membership_lock( 'The members\' group is part of Premium.' ) );
			return;
		}
		$main  = '<h1>Members\' group</h1>';
		$main .= self::qa_paragraph( $member_id );
		$main .= '<p><a href="https://www.facebook.com/">Join the private Facebook group</a></p>';
		$main .= '<p>The owner will approve your request.</p>';
		self::page( 'Members\' group', $main );
	}

	public static function diary_photo() {
		if ( ! is_user_logged_in() || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}
		$requested = (int) get_query_var( 'lld_member' );
		if ( ! LLD_Members_Access::can_read( $requested ) ) {
			lld_members_layout(
				array(
					'title'     => 'This page isn\'t available',
					'main'      => lld_state_not_allowed() . '<p>You can only open your own pages.</p>',
					'signed_in' => true,
					'status'    => 403,
				)
			);
			return;
		}
		global $wpdb;
		$meal = isset( $_GET['meal'] ) ? (int) $_GET['meal'] : 0;
		$path = $wpdb->get_var( $wpdb->prepare( 'SELECT photo_path FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE id = %d AND member_id = %d', $meal, $requested ) );
		if ( ! is_string( $path ) || '' === $path ) {
			status_header( 404 );
			self::page( 'Photo', '<h1>No photo yet</h1>', 404 );
			return;
		}
		LLD_Members_Photos::send( $requested, $path );
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function maybe_finish( $member_id ) {
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row ) {
			return;
		}
		if ( LLD_Members_Programme::programme_day( $member_id, (int) $row->id ) < 28 ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'enrolment' ),
			array(
				'status'      => 'finished',
				'finished_on' => LLD_Members_Programme::local_today( $member_id ),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function monthly_html( $member_id ) {
		$finished = self::last_finished( $member_id );
		if ( ! $finished ) {
			return '';
		}
		$chosen = self::month_measures( $member_id, (string) $finished->track_key );
		$html   = '<h2>Monthly check-in</h2><p>Measures from ' . esc_html( (string) $finished->track_key ) . '.</p>';
		$html  .= '<form method="post" class="lld-form">';
		$html  .= wp_nonce_field( 'lld_month', 'lld_month_nonce', true, false );
		$html  .= '<fieldset><legend>Measures</legend>';
		foreach ( self::measure_choices() as $key => $label ) {
			$html .= '<label class="lld-check"><input type="checkbox" name="lld_month_measure[]" value="' . esc_attr( $key ) . '"' . ( in_array( $key, $chosen, true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
		}
		$html .= '</fieldset>';
		foreach ( array_keys( self::measure_choices() ) as $key ) {
			$html .= self::month_field( $key );
		}
		$html .= lld_button( array( 'label' => 'Save monthly check-in' ) );
		$html .= '</form>';
		$html .= '<p>Saved measures: ' . esc_html( implode( ', ', $chosen ) ) . '</p>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	public static function save_month( $member_id ) {
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return new WP_Error( 'lld_month', 'Health consent is off.' );
		}
		$chosen = array();
		if ( isset( $_POST['lld_month_measure'] ) && is_array( $_POST['lld_month_measure'] ) ) {
			foreach ( wp_unslash( $_POST['lld_month_measure'] ) as $key ) {
				$key = sanitize_key( (string) $key );
				if ( isset( self::measure_choices()[ $key ] ) ) {
					$chosen[] = $key;
				}
			}
		}
		$today = LLD_Members_Programme::local_today( $member_id );
		$data  = array(
			'member_id'         => $member_id,
			'month_key'         => substr( $today, 0, 7 ),
			'local_date'        => $today,
			'measures'          => implode( ',', $chosen ),
			'weight_kg'         => self::posted_decimal( 'lld_weight' ),
			'waist_cm'          => self::posted_decimal( 'lld_waist' ),
			'activity_minutes'  => self::posted_int( 'lld_activity' ),
			'chair_stands'      => self::posted_int( 'lld_chair' ),
			'push_ups'          => self::posted_int( 'lld_push' ),
			'strength_sessions' => self::posted_int( 'lld_strength' ),
			'sleep_hours'       => self::posted_decimal( 'lld_sleep' ),
			'sleep_quality'     => self::posted_int( 'lld_quality' ),
			'stress_level'      => self::posted_int( 'lld_stress' ),
		);
		global $wpdb;
		$table = LLD_Members_Schema::table( 'check_in' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND month_key = %s", $member_id, $data['month_key'] ) );
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $data );
		}
		self::award( $member_id );
		return true;
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function award( $member_id ) {
		foreach ( self::approved_of( 'lld_badge' ) as $badge ) {
			if ( ! self::rule_met( $member_id, $badge ) ) {
				continue;
			}
			if ( ! LLD_Members_Safety::allows( $member_id, $badge ) ) {
				continue;
			}
			self::insert_badge( $member_id, (int) $badge['parent_id'], '' );
		}
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function summary_line( $member_id ) {
		global $wpdb;
		$today = LLD_Members_Programme::local_today( $member_id );
		$from  = ( new DateTimeImmutable( $today ) )->modify( '-6 days' )->format( 'Y-m-d' );
		$table = LLD_Members_Schema::table( 'diary_meal' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE member_id = %d AND local_date >= %s AND local_date <= %s", $member_id, $from, $today ) );
		if ( $total < 1 ) {
			return '';
		}
		$veg = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE member_id = %d AND local_date >= %s AND local_date <= %s AND vegetables = 1", $member_id, $from, $today ) );
		return 'Vegetables at ' . $veg . ' of ' . $total . ' meals';
	}

	/**
	 * @param int $member_id Member number.
	 */
	public static function qa_paragraph( $member_id ) {
		if ( ! LLD_Members_Billing::paid_open( $member_id ) ) {
			return '';
		}
		$date = self::next_qa( LLD_Members_Programme::local_today( $member_id ) );
		$html = '<p>Facebook\'s own privacy terms apply.</p>';
		if ( '' !== $date ) {
			$html .= '<p>Next live Q&amp;A ' . esc_html( $date ) . '</p>';
		}
		return $html;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		return new WP_REST_Response(
			array(
				'badges'    => self::badge_rows( $member_id ),
				'challenge' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'challenge' ) . ' WHERE member_id = %d', $member_id ) ),
				'result'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d', $member_id ) ),
				'workout'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'workout' ) . ' WHERE member_id = %d', $member_id ) ),
			),
			200
		);
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::catch_ended();
		self::flag_late();
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_removal_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_removal_nonce'] ) ), 'lld_removal' ) ) {
			self::record_removal( isset( $_POST['lld_member'] ) ? (int) $_POST['lld_member'] : 0 );
			echo '<div class="notice notice-success"><p>Removal recorded.</p></div>';
		}
		echo '<div class="wrap"><h1>Group removals</h1>';
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . LLD_Members_Schema::table( 'fb_removal' ) . ' ORDER BY id ASC' );
		if ( ! $rows ) {
			echo '<p>Nobody to remove.</p></div>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', (int) $row->member_number ) );
			echo '<li>' . esc_html( $email ) . ' ended ' . esc_html( (string) $row->premium_ended_on );
			if ( $row->removed_on ) {
				echo ' Removed on ' . esc_html( (string) $row->removed_on );
			} else {
				if ( (int) $row->late ) {
					echo ' overdue';
				}
				echo '<form method="post">';
				wp_nonce_field( 'lld_removal', 'lld_removal_nonce' );
				echo '<input type="hidden" name="lld_member" value="' . (int) $row->member_number . '">';
				submit_button( 'Record removal', 'secondary', 'submit', false );
				echo '</form>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * @param string $title  Title.
	 * @param string $main   HTML.
	 * @param int    $status Status.
	 */
	private static function page( $title, $main, $status = 200 ) {
		lld_members_layout(
			array(
				'title'     => $title,
				'main'      => $main,
				'signed_in' => true,
				'status'    => $status,
			)
		);
	}

	/**
	 * @param string $type Post type.
	 * @return array<int,array<string,mixed>>
	 */
	private static function approved_of( $type ) {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 200,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$snap = LLD_Members_Content::shown( $post->ID );
			if ( is_array( $snap ) ) {
				$out[] = $snap;
			}
		}
		return $out;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{days:array<int,array<string,array<string,mixed>>>,shopping:string[],swap_slot:string,swap_options:array<int,array<string,mixed>>}
	 */
	private static function meal_view( $member_id ) {
		$empty = array(
			'days'         => array(),
			'shopping'     => array(),
			'swap_slot'    => '',
			'swap_options' => array(),
		);
		$template = self::matching_template( $member_id );
		if ( ! $template ) {
			return $empty;
		}
		$swaps = self::saved_swaps( $member_id, $template );
		$meals = self::decode_meals( $template );
		$offer = self::decode_swaps( $template );
		$days  = array();
		$bag   = array();
		$slot_key = '';
		$options  = array();
		foreach ( $meals as $day => $slots ) {
			if ( ! is_array( $slots ) ) {
				continue;
			}
			foreach ( $slots as $name => $recipe_id ) {
				$key    = (int) $day . '-' . sanitize_key( (string) $name );
				$chosen = isset( $swaps[ $key ] ) ? (int) $swaps[ $key ] : (int) $recipe_id;
				$recipe = self::safe_recipe( $member_id, $chosen );
				if ( ! $recipe && (int) $chosen !== (int) $recipe_id ) {
					$recipe = self::safe_recipe( $member_id, (int) $recipe_id );
				}
				if ( ! $recipe ) {
					continue;
				}
				$days[ (int) $day ][ sanitize_key( (string) $name ) ] = $recipe;
				foreach ( $recipe['ingredients'] as $line ) {
					$bag[ $line ] = $line;
				}
			}
		}
		if ( isset( $offer['1-breakfast'] ) && is_array( $offer['1-breakfast'] ) ) {
			$slot_key = '1-breakfast';
			foreach ( $offer['1-breakfast'] as $recipe_id ) {
				$recipe = self::safe_recipe( $member_id, (int) $recipe_id );
				if ( $recipe ) {
					$options[] = $recipe;
				}
			}
		}
		ksort( $days );
		return array(
			'days'         => $days,
			'shopping'     => array_values( $bag ),
			'swap_slot'    => $slot_key,
			'swap_options' => $options,
		);
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<string,mixed>|null
	 */
	private static function matching_template( $member_id ) {
		$active = LLD_Members_Programme::active( $member_id );
		$track  = $active ? LLD_Members_Programme::track_id( (string) $active->track_key ) : 0;
		$week   = 1;
		if ( $active ) {
			$week = LLD_Members_Programme::position_parts( LLD_Members_Programme::programme_day( $member_id, (int) $active->id ) )['week'];
		}
		$diet    = self::diet_of( $member_id );
		$best    = null;
		$fallback = null;
		foreach ( self::approved_of( 'lld_meal' ) as $meal ) {
			$fields = isset( $meal['fields'] ) && is_array( $meal['fields'] ) ? $meal['fields'] : array();
			if ( $track && (int) ( $fields['track_id'] ?? 0 ) !== (int) $track ) {
				continue;
			}
			$meal_diet = sanitize_title( (string) ( $fields['diet'] ?? '' ) );
			if ( $diet && $meal_diet && $meal_diet !== $diet && 'everyone' !== $meal_diet ) {
				continue;
			}
			$meal_week = (int) ( $fields['week_number'] ?? 1 );
			if ( $meal_week === (int) $week ) {
				return $meal;
			}
			if ( 1 === $meal_week ) {
				$fallback = $meal;
			}
			if ( ! $best ) {
				$best = $meal;
			}
		}
		return $fallback ? $fallback : $best;
	}

	/**
	 * @param int                  $member_id Member number.
	 * @param array<string,mixed>  $template  Approved meal template.
	 * @return array<string,int>
	 */
	private static function saved_swaps( $member_id, $template ) {
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return array();
		}
		global $wpdb;
		$today = LLD_Members_Programme::local_today( $member_id );
		$start = ( new DateTimeImmutable( $today ) )->modify( '-' . ( (int) ( new DateTimeImmutable( $today ) )->format( 'N' ) - 1 ) . ' days' )->format( 'Y-m-d' );
		$table = LLD_Members_Schema::table( 'meal_plan' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, swaps FROM {$table} WHERE member_id = %d AND week_starting = %s", $member_id, $start ) );
		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'member_id'        => $member_id,
					'week_starting'    => $start,
					'template_version' => (int) $template['parent_id'] . ':' . (string) ( $template['fingerprint'] ?? '' ),
					'swaps'            => '{}',
				),
				array( '%d', '%s', '%s', '%s' )
			);
			return array();
		}
		$decoded = json_decode( (string) $row->swaps, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function take_swap( $member_id ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['lld_swap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_swap_nonce'] ) ), 'lld_swap' ) ) {
			return;
		}
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return;
		}
		$slot   = isset( $_POST['lld_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['lld_slot'] ) ) : '';
		$recipe = isset( $_POST['lld_recipe'] ) ? (int) $_POST['lld_recipe'] : 0;
		if ( '' === $slot || ! self::safe_recipe( $member_id, $recipe ) ) {
			return;
		}
		$template = self::matching_template( $member_id );
		if ( ! $template ) {
			return;
		}
		$swaps          = self::saved_swaps( $member_id, $template );
		$swaps[ $slot ] = $recipe;
		global $wpdb;
		$today = LLD_Members_Programme::local_today( $member_id );
		$start = ( new DateTimeImmutable( $today ) )->modify( '-' . ( (int) ( new DateTimeImmutable( $today ) )->format( 'N' ) - 1 ) . ' days' )->format( 'Y-m-d' );
		$wpdb->update(
			LLD_Members_Schema::table( 'meal_plan' ),
			array( 'swaps' => wp_json_encode( $swaps ) ),
			array(
				'member_id'     => $member_id,
				'week_starting' => $start,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $template Template snapshot.
	 * @return array<string,mixed>
	 */
	private static function decode_meals( $template ) {
		$fields = isset( $template['fields'] ) && is_array( $template['fields'] ) ? $template['fields'] : array();
		$raw    = $fields['meals'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array<string,mixed> $template Template snapshot.
	 * @return array<string,mixed>
	 */
	private static function decode_swaps( $template ) {
		$fields = isset( $template['fields'] ) && is_array( $template['fields'] ) ? $template['fields'] : array();
		$raw    = $fields['swaps'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $recipe_id Recipe id.
	 * @return array<string,mixed>|null
	 */
	private static function safe_recipe( $member_id, $recipe_id ) {
		$snap = LLD_Members_Content::shown( $recipe_id );
		if ( ! is_array( $snap ) ) {
			return null;
		}
		$snap['type'] = 'lld_recipe';
		if ( ! LLD_Members_Safety::allows( $member_id, $snap ) ) {
			return null;
		}
		$fields = isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array();
		$lines  = array();
		$raw    = (string) ( $fields['ingredients'] ?? '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( '|', $line ) );
			$lines[] = $parts[0] . ( isset( $parts[1] ) ? ', ' . $parts[1] . ( isset( $parts[2] ) ? ' ' . $parts[2] : '' ) : '' );
		}
		return array(
			'id'          => (int) $snap['parent_id'],
			'title'       => (string) $snap['title'],
			'ingredients' => $lines,
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function diet_of( $member_id ) {
		global $wpdb;
		$diet = $wpdb->get_var( $wpdb->prepare( 'SELECT diet_type FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) );
		return is_string( $diet ) ? sanitize_title( $diet ) : '';
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function send_shopping_pdf( $member_id ) {
		$view = self::meal_view( $member_id );
		$text = $view['shopping'] ? implode( '; ', $view['shopping'] ) : 'Shopping list';
		$text = str_replace( array( '(', ')', '\\' ), ' ', $text );
		$body = "BT /F1 12 Tf 72 720 Td (" . $text . ") Tj ET";
		$pdf  = "%PDF-1.1\n";
		$pdf .= "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
		$pdf .= "2 0 obj<< /Type /Pages /Count 1 /Kids [3 0 R] >>endobj\n";
		$pdf .= "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj\n";
		$pdf .= '4 0 obj<< /Length ' . strlen( $body ) . " >>stream\n" . $body . "\nendstream\nendobj\n";
		$pdf .= "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";
		$start = strlen( $pdf );
		$pdf  .= "xref\n0 6\n";
		$pdf  .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= 5; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", 9 );
		}
		$pdf .= "trailer<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $start . "\n%%EOF";
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="shopping-list.pdf"' );
		header( 'Cache-Control: no-store' );
		echo $pdf;
		exit;
	}

	/**
	 * @param string $level Level.
	 * @param string $place home or equipment.
	 * @param int    $week  Week number.
	 * @return array{safety:string,sessions:array<int,array<string,mixed>>}|null
	 */
	private static function workout_plan( $level, $place, $week ) {
		foreach ( self::approved_of( 'lld_workout' ) as $plan ) {
			$fields = isset( $plan['fields'] ) && is_array( $plan['fields'] ) ? $plan['fields'] : array();
			if ( sanitize_key( (string) ( $fields['level'] ?? '' ) ) !== $level ) {
				continue;
			}
			if ( sanitize_key( (string) ( $fields['place'] ?? '' ) ) !== $place ) {
				continue;
			}
			if ( (int) ( $fields['week_number'] ?? 1 ) !== (int) $week ) {
				continue;
			}
			$sessions = array();
			foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $fields['sessions'] ?? '' ) ) as $line ) {
				$parts = array_map( 'trim', explode( '|', (string) $line ) );
				if ( '' === ( $parts[0] ?? '' ) ) {
					continue;
				}
				$sessions[] = array(
					'name'    => $parts[0],
					'seconds' => isset( $parts[1] ) ? (int) $parts[1] : 30,
					'rest'    => isset( $parts[2] ) ? (int) $parts[2] : 15,
				);
			}
			return array(
				'safety'   => (string) ( $fields['safety'] ?? 'Warm up. Stop if you feel pain.' ),
				'sessions' => $sessions,
			);
		}
		return null;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $level     Level.
	 * @param string $place     Version.
	 */
	private static function save_workout( $member_id, $level, $place ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'workout' );
		$today = LLD_Members_Programme::local_today( $member_id );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT id, level FROM {$table} WHERE member_id = %d AND active = 1 LIMIT 1", $member_id ) );
		if ( $current && (string) $current->level !== $level ) {
			$wpdb->update( $table, array( 'active' => 0 ), array( 'id' => (int) $current->id ), array( '%d' ), array( '%d' ) );
			$wpdb->insert(
				LLD_Members_Schema::table( 'workout_change' ),
				array(
					'workout_id' => (int) $current->id,
					'level'      => $level,
					'changed_on' => $today,
				),
				array( '%d', '%s', '%s' )
			);
		}
		if ( ! $current || (string) $current->level !== $level ) {
			$wpdb->insert(
				$table,
				array(
					'member_id'   => $member_id,
					'level'       => $level,
					'version_key' => $place,
					'started_on'  => $today,
					'current_week'=> 1,
					'active'      => 1,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%d' )
			);
		}
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $context   workout or challenge.
	 * @param int    $day       Session or day.
	 */
	private static function store_result( $member_id, $context, $day ) {
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'result' ),
			array(
				'member_id'    => $member_id,
				'item_version' => $context . '-' . $day,
				'context_type' => $context,
				'context_id'   => 0,
				'programme_day'=> $day,
				'local_date'   => LLD_Members_Programme::local_today( $member_id ),
				'result'       => 'done',
				'recorded_at'  => lld_members_now_mysql( $member_id ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param string $today Local date.
	 * @return array<string,mixed>|null
	 */
	private static function current_challenge( $today ) {
		$month = substr( $today, 0, 7 );
		$found = null;
		foreach ( self::approved_of( 'lld_challenge' ) as $challenge ) {
			$fields = isset( $challenge['fields'] ) && is_array( $challenge['fields'] ) ? $challenge['fields'] : array();
			if ( (string) ( $fields['month'] ?? '' ) !== $month ) {
				continue;
			}
			if ( ! $found || (int) $challenge['parent_id'] > (int) $found['parent_id'] ) {
				$found = $challenge;
			}
		}
		return $found;
	}

	/**
	 * @param array<string,mixed> $challenge Challenge snapshot.
	 * @return string[]
	 */
	private static function challenge_activities( $challenge ) {
		$fields = isset( $challenge['fields'] ) && is_array( $challenge['fields'] ) ? $challenge['fields'] : array();
		$ids    = preg_split( '/\s*,\s*/', (string) ( $fields['activity_ids'] ?? '' ) );
		$titles = array();
		foreach ( $ids as $id ) {
			$snap = LLD_Members_Content::shown( (int) $id );
			if ( is_array( $snap ) ) {
				$titles[] = (string) $snap['title'];
			}
		}
		return $titles;
	}

	/**
	 * @param int    $member_id    Member number.
	 * @param int    $challenge_id Challenge id.
	 * @param string $today        Local date.
	 */
	private static function join_challenge( $member_id, $challenge_id, $today ) {
		if ( self::challenge_row( $member_id, $challenge_id ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'challenge' ),
			array(
				'member_id'         => $member_id,
				'challenge_version' => (string) $challenge_id,
				'joined_on'         => $today,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * @param int $member_id    Member number.
	 * @param int $challenge_id Challenge id.
	 */
	private static function challenge_row( $member_id, $challenge_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'challenge' ) . ' WHERE member_id = %d AND challenge_version = %s ORDER BY id DESC LIMIT 1', $member_id, (string) $challenge_id ) );
	}

	/**
	 * @param int                  $member_id Member number.
	 * @param object               $row       Challenge row.
	 * @param array<string,mixed>  $shown     Challenge snapshot.
	 * @param string               $today     Local date.
	 */
	private static function finish_challenge( $member_id, $row, $shown, $today ) {
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'challenge' ),
			array( 'finished_on' => $today ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);
		$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
		$badge  = (int) ( $fields['badge_id'] ?? 0 );
		if ( $badge && self::badge_allowed( $member_id, $shown ) ) {
			self::insert_badge( $member_id, $badge, (string) $shown['parent_id'] );
		}
	}

	/**
	 * @param array<string,mixed> $shown Challenge snapshot.
	 */
	private static function badge_title( $shown ) {
		$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
		$snap   = LLD_Members_Content::shown( (int) ( $fields['badge_id'] ?? 0 ) );
		return is_array( $snap ) ? (string) $snap['title'] : '';
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $shown     Challenge snapshot.
	 */
	private static function badge_allowed( $member_id, $shown ) {
		$fields = isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array();
		$snap   = LLD_Members_Content::shown( (int) ( $fields['badge_id'] ?? 0 ) );
		if ( ! is_array( $snap ) ) {
			return false;
		}
		$snap['type'] = 'lld_badge';
		return LLD_Members_Safety::allows( $member_id, $snap );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param int    $badge_id  Badge id.
	 * @param string $challenge Challenge id, or empty.
	 */
	private static function insert_badge( $member_id, $badge_id, $challenge ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . LLD_Members_Schema::table( 'badge' ) . ' (member_id, badge_key, challenge_key, earned_on) VALUES (%d, %s, %s, %s)',
				$member_id,
				(string) $badge_id,
				$challenge,
				LLD_Members_Programme::local_today( $member_id )
			)
		);
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $badge     Badge snapshot.
	 */
	private static function rule_met( $member_id, $badge ) {
		$fields = isset( $badge['fields'] ) && is_array( $badge['fields'] ) ? $badge['fields'] : array();
		$rule   = (string) ( $fields['rule'] ?? '' );
		if ( 'weight-down-5' === $rule ) {
			return self::weight_down( $member_id ) >= 5;
		}
		if ( 'active-90' === $rule ) {
			global $wpdb;
			$joined = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT joined_on FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $joined ) ) {
				return false;
			}
			$due = ( new DateTimeImmutable( $joined ) )->modify( '+90 days' )->format( 'Y-m-d' );
			return LLD_Members_Programme::local_today( $member_id ) >= $due;
		}
		return false;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function weight_down( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT weight_kg FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE member_id = %d AND weight_kg IS NOT NULL ORDER BY local_date ASC, id ASC', $member_id ) );
		if ( count( $rows ) < 2 ) {
			return 0;
		}
		return (float) $rows[0] - (float) $rows[ count( $rows ) - 1 ];
	}

	/**
	 * @param int $member_id Member number.
	 * @return string[]
	 */
	private static function earned_badges( $member_id ) {
		$titles = array();
		foreach ( self::badge_rows( $member_id ) as $row ) {
			$snap = LLD_Members_Content::shown( (int) $row['badge_key'] );
			if ( ! is_array( $snap ) ) {
				continue;
			}
			$snap['type'] = 'lld_badge';
			if ( ! LLD_Members_Safety::allows( $member_id, $snap ) ) {
				continue;
			}
			$titles[] = (string) $snap['title'];
		}
		return $titles;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<int,array<string,string>>
	 */
	private static function badge_rows( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT badge_key, challenge_key, earned_on FROM ' . LLD_Members_Schema::table( 'badge' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function chart_html( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT month_key, weight_kg FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE member_id = %d AND month_key IS NOT NULL AND month_key <> "" ORDER BY month_key ASC', $member_id ) );
		$html = '<table data-lld-chart><caption>Check-ins since joining</caption><thead><tr><th>Month</th><th>Weight</th></tr></thead><tbody>';
		if ( ! $rows ) {
			$html .= '<tr><td colspan="2">No history yet</td></tr>';
		}
		foreach ( $rows as $row ) {
			$html .= '<tr><td>' . esc_html( (string) $row->month_key ) . '</td><td>' . esc_html( (string) $row->weight_kg ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function last_finished( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'enrolment' ) . " WHERE member_id = %d AND status = 'finished' ORDER BY id DESC LIMIT 1", $member_id ) );
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $track     Track key.
	 * @return string[]
	 */
	private static function month_measures( $member_id, $track ) {
		global $wpdb;
		$today = LLD_Members_Programme::local_today( $member_id );
		$saved = $wpdb->get_var( $wpdb->prepare( 'SELECT measures FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE member_id = %d AND month_key = %s', $member_id, substr( $today, 0, 7 ) ) );
		if ( is_string( $saved ) && '' !== $saved && ! in_array( $saved, array( 'healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress' ), true ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', explode( ',', $saved ) ) ) );
		}
		return LLD_Members_Programme::measures( $track );
	}

	/**
	 * @return array<string,string>
	 */
	private static function measure_choices() {
		return array(
			'weight'   => 'Weight in kilograms',
			'waist'    => 'Waist in centimetres',
			'activity' => 'Minutes of activity this week',
			'chair'    => 'Chair stands in 30 seconds',
			'push'     => 'Push-ups',
			'strength' => 'Strength sessions',
			'sleep'    => 'Average sleep hours',
			'quality'  => 'Sleep quality from 1 to 5',
			'stress'   => 'Stress level from 1 to 5',
		);
	}

	/**
	 * @param string $key Measure key.
	 */
	private static function month_field( $key ) {
		$map = array(
			'weight'   => array( 'lld_weight', 'Weight in kilograms' ),
			'waist'    => array( 'lld_waist', 'Waist in centimetres' ),
			'activity' => array( 'lld_activity', 'Minutes of activity this week' ),
			'chair'    => array( 'lld_chair', 'Chair stands in 30 seconds' ),
			'push'     => array( 'lld_push', 'Push-ups' ),
			'strength' => array( 'lld_strength', 'Strength sessions' ),
			'sleep'    => array( 'lld_sleep', 'Average sleep hours' ),
			'quality'  => array( 'lld_quality', 'Sleep quality from 1 to 5' ),
			'stress'   => array( 'lld_stress', 'Stress level from 1 to 5' ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}
		return '<p><label for="' . esc_attr( $map[ $key ][0] ) . '">' . esc_html( $map[ $key ][1] ) . '</label><input id="' . esc_attr( $map[ $key ][0] ) . '" name="' . esc_attr( $map[ $key ][0] ) . '" inputmode="decimal"></p>';
	}

	/**
	 * @param string $name Field name.
	 */
	private static function posted_decimal( $name ) {
		if ( ! isset( $_POST[ $name ] ) || '' === trim( (string) wp_unslash( $_POST[ $name ] ) ) || ! is_numeric( wp_unslash( $_POST[ $name ] ) ) ) {
			return null;
		}
		return (float) wp_unslash( $_POST[ $name ] );
	}

	/**
	 * @param string $name Field name.
	 */
	private static function posted_int( $name ) {
		if ( ! isset( $_POST[ $name ] ) || '' === trim( (string) wp_unslash( $_POST[ $name ] ) ) || ! is_numeric( wp_unslash( $_POST[ $name ] ) ) ) {
			return null;
		}
		return (int) wp_unslash( $_POST[ $name ] );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function hides_portion( $member_id ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT conditions FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) );
		$list = json_decode( (string) $raw, true );
		return is_array( $list ) && in_array( 'eating-disorder', $list, true );
	}

	/**
	 * @param int $member_id Member number.
	 * @return true|WP_Error
	 */
	private static function save_meal( $member_id ) {
		$meal = isset( $_POST['lld_meal'] ) ? sanitize_key( wp_unslash( $_POST['lld_meal'] ) ) : '';
		if ( ! in_array( $meal, array( 'breakfast', 'lunch', 'dinner', 'snack' ), true ) ) {
			$meal = 'breakfast';
		}
		$planned = ! empty( $_POST['lld_planned'] );
		$photo   = '';
		if ( isset( $_FILES['lld_photo'] ) && is_array( $_FILES['lld_photo'] ) ) {
			$upload_error = isset( $_FILES['lld_photo']['error'] ) ? (int) $_FILES['lld_photo']['error'] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_NO_FILE !== $upload_error ) {
				if ( UPLOAD_ERR_OK !== $upload_error ) {
					return new WP_Error( 'lld_photo', 'refused' );
				}
				$stored = LLD_Members_Photos::store( $member_id, 'diary', (string) $_FILES['lld_photo']['tmp_name'], (string) $_FILES['lld_photo']['name'] );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				$photo = (string) $stored;
			}
		}
		$portion = '';
		if ( ! self::hides_portion( $member_id ) && isset( $_POST['lld_portion'] ) ) {
			$portion = sanitize_key( wp_unslash( $_POST['lld_portion'] ) );
			if ( ! in_array( $portion, array( 'small', 'normal', 'large' ), true ) ) {
				$portion = '';
			}
		}
		$today = LLD_Members_Programme::local_today( $member_id );
		$text  = isset( $_POST['lld_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lld_description'] ) ) : '';
		global $wpdb;
		$table = LLD_Members_Schema::table( 'diary_meal' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND local_date = %s AND meal = %s", $member_id, $today, $meal ) );
		$data  = array(
			'member_id'     => $member_id,
			'local_date'    => $today,
			'meal'          => $meal,
			'entry_type'    => $planned ? 'planned' : 'description',
			'recipe_version'=> $planned ? self::planned_recipe( $member_id, $meal ) : '',
			'vegetables'    => empty( $_POST['lld_vegetables'] ) ? 0 : 1,
			'protein_source'=> empty( $_POST['lld_protein'] ) ? 0 : 1,
			'sugary_drink'  => empty( $_POST['lld_sugary'] ) ? 0 : 1,
			'recorded_at'   => lld_members_now_mysql( $member_id ),
		);
		if ( '' !== $photo ) {
			$data['photo_path'] = $photo;
		}
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		$cipher = '' !== $text ? LLD_Members_Crypto::encrypt( $text, 'diary_meal:' . $id ) : '';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET description_ciphertext = %s, `portion` = %s WHERE id = %d AND member_id = %d",
				$cipher,
				$portion,
				$id,
				$member_id
			)
		);
		return true;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $meal      Meal slot.
	 */
	private static function planned_recipe( $member_id, $meal ) {
		$view = self::meal_view( $member_id );
		$day  = 1;
		$active = LLD_Members_Programme::active( $member_id );
		if ( $active ) {
			$day = LLD_Members_Programme::position_parts( LLD_Members_Programme::programme_day( $member_id, (int) $active->id ) )['day'];
		}
		if ( isset( $view['days'][ $day ][ $meal ]['id'] ) ) {
			return (string) $view['days'][ $day ][ $meal ]['id'];
		}
		return '';
	}

	/**
	 * @param int $member_id Member number.
	 * @param int $meal_id   Meal row.
	 */
	private static function undo_meal( $member_id, $meal_id ) {
		global $wpdb;
		$wpdb->delete(
			LLD_Members_Schema::table( 'diary_meal' ),
			array(
				'id'        => $meal_id,
				'member_id' => $member_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function diary_list( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d ORDER BY local_date ASC, id ASC', $member_id ) );
		$html = '';
		foreach ( $rows as $row ) {
			$text = '';
			if ( is_string( $row->description_ciphertext ) && '' !== $row->description_ciphertext ) {
				try {
					$text = LLD_Members_Crypto::decrypt( $row->description_ciphertext, 'diary_meal:' . $row->id );
				} catch ( RuntimeException $e ) {
					$text = '';
				}
			}
			$state = ( 'planned' === (string) $row->entry_type || '' !== $text ) ? 'Logged' : 'Add';
			$html .= '<p>' . esc_html( ucfirst( (string) $row->meal ) ) . ': ' . esc_html( $state );
			if ( '' !== $text ) {
				$html .= ' ' . esc_html( $text );
			}
			if ( is_string( $row->photo_path ) && '' !== $row->photo_path ) {
				$html .= ' <a href="' . esc_url( '/members/diary-photo/' . $member_id . '/?meal=' . (int) $row->id ) . '">Photo</a>';
			}
			$html .= '</p>';
		}
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function diary_form( $member_id ) {
		$hide = self::hides_portion( $member_id );
		$html = '<form method="post" enctype="multipart/form-data" class="lld-form">';
		$html .= wp_nonce_field( 'lld_diary', 'lld_diary_nonce', true, false );
		$html .= '<p><label for="lld_meal">Meal</label> <select id="lld_meal" name="lld_meal"><option>breakfast</option><option>lunch</option><option>dinner</option><option>snack</option></select></p>';
		if ( LLD_Members_Billing::paid_open( $member_id ) ) {
			$html .= '<p><button class="lld-button" type="submit" name="lld_planned" value="1">I ate the planned meal</button></p>';
		}
		$html .= '<p><label for="lld_description">Description</label><textarea id="lld_description" name="lld_description" rows="2"></textarea></p>';
		$html .= '<p><label for="lld_photo">Photo</label><input id="lld_photo" name="lld_photo" type="file" accept=".jpg,.jpeg,.png,.heic"></p>';
		$html .= '<p><label class="lld-check"><input type="checkbox" name="lld_vegetables" value="1"> Vegetables</label></p>';
		$html .= '<p><label class="lld-check"><input type="checkbox" name="lld_protein" value="1"> Protein source</label></p>';
		$html .= '<p><label class="lld-check"><input type="checkbox" name="lld_sugary" value="1"> Sugary drink</label></p>';
		if ( ! $hide ) {
			$html .= '<p><label for="lld_portion">Portion</label> <select id="lld_portion" name="lld_portion"><option value=""> </option><option>small</option><option>normal</option><option>large</option></select></p>';
		}
		$html .= '<p><label for="lld_water">Glasses of water</label><input id="lld_water" name="lld_water" inputmode="numeric"></p>';
		$html .= '<p><label for="lld_hunger">Hunger from 1 to 5</label><input id="lld_hunger" name="lld_hunger" inputmode="numeric"></p>';
		$html .= '<p><label for="lld_energy">Energy from 1 to 5</label><input id="lld_energy" name="lld_energy" inputmode="numeric"></p>';
		$html .= lld_button( array( 'label' => 'Save meal' ) );
		$html .= '</form>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function focus_line( $member_id ) {
		$row = LLD_Members_Programme::active( $member_id );
		if ( ! $row ) {
			return '';
		}
		if ( 'blood-sugar' === (string) $row->track_key ) {
			return 'Sugary drinks are the focus this week.';
		}
		if ( 'fitness' === (string) $row->track_key ) {
			return 'A protein source is the focus this week.';
		}
		return '';
	}

	/**
	 * @param string $today Local date.
	 */
	private static function next_qa( $today ) {
		$next = '';
		foreach ( self::approved_of( 'lld_qa' ) as $qa ) {
			$fields = isset( $qa['fields'] ) && is_array( $qa['fields'] ) ? $qa['fields'] : array();
			$date   = (string) ( $fields['session_date'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date < $today ) {
				continue;
			}
			if ( '' === $next || $date < $next ) {
				$next = $date;
			}
		}
		return $next;
	}

	private static function catch_ended() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT member_id, access_state, premium_ended_on FROM ' . LLD_Members_Schema::table( 'subscription' ) );
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $row ) {
			$state = strtolower( (string) $row->access_state );
			if ( ! in_array( $state, array( 'premium ended', 'refunded' ), true ) ) {
				continue;
			}
			$on = is_string( $row->premium_ended_on ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row->premium_ended_on ) ? $row->premium_ended_on : LLD_Members_Programme::local_today( (int) $row->member_id );
			self::note_ended( (int) $row->member_id, $on );
		}
	}

	private static function mail_removals() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, member_number FROM ' . LLD_Members_Schema::table( 'fb_removal' ) . ' WHERE removed_on IS NULL ORDER BY id ASC' );
		if ( ! $rows ) {
			return;
		}
		$sent = get_option( 'lld_fb_sent' );
		if ( ! is_array( $sent ) ) {
			$sent = array();
		}
		$lines = array();
		foreach ( $rows as $row ) {
			if ( in_array( (int) $row->id, $sent, true ) ) {
				continue;
			}
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', (int) $row->member_number ) );
			if ( '' !== $email ) {
				$lines[] = $email;
			}
			$sent[] = (int) $row->id;
		}
		if ( ! $lines ) {
			return;
		}
		wp_mail( (string) get_option( 'admin_email' ), 'People to remove from the group', "Remove these people from the private group:\n" . implode( "\n", $lines ) );
		update_option( 'lld_fb_sent', $sent, false );
	}

	private static function flag_late() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, member_number, premium_ended_on FROM ' . LLD_Members_Schema::table( 'fb_removal' ) . ' WHERE removed_on IS NULL' );
		if ( ! $rows ) {
			return;
		}
		foreach ( $rows as $row ) {
			$today = LLD_Members_Programme::local_today( (int) $row->member_number );
			$due   = ( new DateTimeImmutable( (string) $row->premium_ended_on ) )->modify( '+7 days' )->format( 'Y-m-d' );
			$wpdb->update(
				LLD_Members_Schema::table( 'fb_removal' ),
				array( 'late' => $today > $due ? 1 : 0 ),
				array( 'id' => (int) $row->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function record_removal( $member_id ) {
		if ( $member_id < 1 ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'fb_removal' ),
			array(
				'removed_on' => LLD_Members_Programme::local_today( $member_id ),
				'late'       => 0,
			),
			array( 'member_number' => $member_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}
}
