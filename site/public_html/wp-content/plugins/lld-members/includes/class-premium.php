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

	/**
	 * FR2-34: warming up, stopping if in pain and checking with a doctor. Draft wording for the owner's clinical review.
	 */
	const WORKOUT_ADVICE = 'Warm up before you start. Stop if you feel pain. Check with your doctor if you need to.';

	/**
	 * Requirements section 8.2, Type 2 diabetes row: shown before exercise.
	 */
	const DIABETES_EXERCISE = 'If you take insulin or medicine that can cause low blood sugar, check with your doctor.';

	/**
	 * Requirements section 8.2, high blood pressure row: high-intensity content carries this note.
	 */
	const INTENSE_EXERCISE = 'Check with your doctor first.';

	const MEAL_SLOTS = array( 'breakfast', 'lunch', 'dinner', 'snack' );

	/**
	 * Shopping-list groups by type of food, in shop order, with the words that place an ingredient in each. A recipe
	 * can name the group itself in a fourth column. Lead default; the owner may change the groups.
	 */
	const AISLES = array(
		'Beans, pulses, fish, eggs and meat' => array( 'lentil', 'bean', 'chickpea', 'tofu', 'tempeh', 'egg', 'chicken', 'turkey', 'beef', 'pork', 'lamb', 'mince', 'fish', 'salmon', 'tuna', 'cod', 'haddock', 'mackerel', 'sardine', 'prawn', 'edamame', 'hummus', 'butter bean' ),
		'Vegetables and fruit'               => array( 'spinach', 'kale', 'lettuce', 'salad', 'rocket', 'broccoli', 'cauliflower', 'cabbage', 'carrot', 'onion', 'shallot', 'garlic', 'leek', 'celery', 'tomato', 'pepper', 'courgette', 'aubergine', 'cucumber', 'mushroom', 'potato', 'squash', 'pumpkin', 'beetroot', 'pea', 'sweetcorn', 'corn', 'avocado', 'apple', 'pear', 'banana', 'berr', 'orange', 'lemon', 'lime', 'grape', 'mango', 'melon', 'kiwi', 'plum', 'peach', 'cherr', 'fruit', 'veg', 'eggplant', 'sweet potato' ),
		'Bread, rice, pasta and grains'      => array( 'oat', 'porridge', 'bread', 'roll', 'pitta', 'wrap', 'tortilla', 'rice', 'pasta', 'spaghetti', 'noodle', 'couscous', 'quinoa', 'bulgur', 'barley', 'flour', 'cornflour', 'cereal', 'cracker' ),
		'Dairy and alternatives'             => array( 'milk', 'yoghurt', 'yogurt', 'cheese', 'feta', 'cream', 'butter', 'kefir' ),
		'Tins, jars and dry goods'           => array( 'broth', 'stock', 'tinned', 'canned', 'passata', 'puree', 'sauce', 'vinegar', 'nut', 'peanut', 'peanut butter', 'almond', 'walnut', 'seed', 'honey', 'jam', 'raisin', 'sultana', 'cocoa', 'stock cube' ),
		'Oils, herbs and spices'             => array( 'oil', 'salt', 'black pepper', 'spice', 'cumin', 'paprika', 'chilli', 'turmeric', 'cinnamon', 'ginger', 'herb', 'basil', 'parsley', 'coriander', 'thyme', 'oregano', 'rosemary', 'mint', 'dill' ),
	);

	/**
	 * Check-in measures (Data Model C9) and their labels, for the progress charts.
	 */
	const CHART_MEASURES = array(
		'weight_kg'         => 'Weight in kilograms',
		'waist_cm'          => 'Waist in centimetres',
		'activity_minutes'  => 'Minutes of activity this week',
		'chair_stands'      => 'Chair stands in 30 seconds',
		'push_ups'          => 'Push-ups',
		'strength_sessions' => 'Strength sessions',
		'sleep_hours'       => 'Average sleep hours',
		'sleep_quality'     => 'Sleep quality from 1 to 5',
		'stress_level'      => 'Stress level from 1 to 5',
	);

	const LEVELS = array(
		'beginner'     => 1,
		'intermediate' => 2,
		'active'       => 3,
	);

	const NEXT_LEVEL = array(
		'beginner'     => 'intermediate',
		'intermediate' => 'active',
	);

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
		self::flag_late();
		self::mail_removals();
		self::purge_removals();
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
		// One row per Premium end (F7). The same end is never added twice, even after its removal was recorded,
		// because the daily check sees an ended subscription every day; a later end is a new row.
		$table = LLD_Members_Schema::table( 'fb_removal' );
		$known = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_number = %d AND premium_ended_on = %s LIMIT 1", $member_id, $on ) );
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
		$main = '<h1>Meal plan</h1><div class="lld-card"><div class="lld-card-head"><div>';
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			$main .= '<p class="lld-kicker">General plan</p>';
		}
		$main .= '<p data-lld-week="' . esc_attr( $plan['week']['starting'] ) . '">Week ' . (int) $plan['week']['week'] . ', starting ' . esc_html( ( new DateTimeImmutable( $plan['week']['starting'] ) )->format( 'l j F' ) ) . '</p></div>';
		if ( $plan['days'] ) {
			$main .= '<a class="lld-button lld-button-secondary" href="#lld-shopping">Shopping list</a>';
		}
		$main .= '</div>';
		if ( ! $plan['days'] && ! LLD_Members_Content::has_approved( 'lld_meal' ) ) {
			$main .= '</div>' . lld_coming_soon( 'Weekly meal plans with recipes and a shopping list are being prepared. Your first plan will appear here as soon as it is ready.', 'Open the food diary', '/members/diary/' );
			self::page( 'Meal plan', $main, 200, true );
			return;
		}
		if ( ! $plan['days'] ) {
			$main .= '<p>' . esc_html( LLD_Members_Safety::empty_line( $member_id ) ) . '</p><p>Contact support if you need a plan that fits you.</p></div>';
			self::page( 'Meal plan', $main, 200, true );
			return;
		}
		$consent = LLD_Members_Account::has_consent( $member_id, 'health' );
		foreach ( LLD_Members_Safety::notes( $member_id ) as $note ) {
			$main .= '<p class="lld-hint">' . esc_html( $note ) . '</p>';
		}
		$main .= '<p class="lld-hint">Each meal can be swapped for a safe alternative.</p></div>';
		$nonce = $consent ? wp_create_nonce( 'lld_swap' ) : '';
		$first = new DateTimeImmutable( $plan['week']['starting'] );
		foreach ( $plan['days'] as $day => $meals ) {
			$date  = $first->modify( '+' . ( (int) $day - 1 ) . ' days' );
			$main .= '<section data-lld-day="' . (int) $day . '" data-lld-date="' . esc_attr( $date->format( 'Y-m-d' ) ) . '"><h2>Day ' . (int) $day . ', ' . esc_html( $date->format( 'l j F' ) ) . '</h2><div class="lld-recipes">';
			foreach ( $meals as $slot => $recipe ) {
				$key   = (int) $day . '-' . $slot;
				$badge = '' !== trim( (string) $recipe['diet'] ) ? (string) $recipe['diet'] : ( '' !== trim( (string) $recipe['servings'] ) ? (string) $recipe['servings'] . ' servings' : '' );
				$main .= '<article class="lld-meal lld-recipe" data-lld-meal="' . esc_attr( $key ) . '">';
				$main .= '<div class="lld-recipe-top"><span class="lld-recipe-slot">' . esc_html( ucfirst( (string) $slot ) ) . '</span>' . ( '' !== $badge ? '<span class="lld-recipe-badge">' . esc_html( $badge ) . '</span>' : '' ) . '</div>';
				$main .= '<div class="lld-recipe-body"><p><span class="lld-sr">' . esc_html( ucfirst( (string) $slot ) ) . ': </span>' . esc_html( $recipe['title'] ) . '</p></div>';
				$main .= '<div class="lld-recipe-actions"><a href="' . esc_url( home_url( '/members/library/' . (int) $recipe['id'] . '/' ) ) . '">View recipe<span class="lld-sr">: ' . esc_html( $recipe['title'] ) . '</span></a></div>';
				if ( $consent && $recipe['options'] ) {
					$field = 'lld_recipe_' . (int) $day . '_' . $slot;
					$main .= '<form method="post" class="lld-form lld-recipe-swap" data-lld-swap="' . esc_attr( $key ) . '">';
					$main .= '<input type="hidden" name="lld_swap_nonce" value="' . esc_attr( $nonce ) . '">';
					$main .= '<input type="hidden" name="lld_slot" value="' . esc_attr( $key ) . '">';
					$main .= '<p><label for="' . esc_attr( $field ) . '">Swap Day ' . (int) $day . ' ' . esc_html( $slot ) . '</label> <select id="' . esc_attr( $field ) . '" name="lld_recipe">';
					foreach ( $recipe['options'] as $option ) {
						$main .= '<option value="' . (int) $option['id'] . '">' . esc_html( $option['title'] ) . '</option>';
					}
					$main .= '</select></p>';
					$main .= lld_button( array( 'label' => 'Swap', 'variant' => 'secondary' ) );
					$main .= '</form>';
				} elseif ( $consent ) {
					$main .= '<p class="lld-hint">No safe swap for this meal.</p>';
				}
				$main .= '</article>';
			}
			$main .= '</div></section>';
		}
		$main .= '<section class="lld-card" id="lld-shopping"><div class="lld-card-head"><h2>Shopping list</h2><p class="lld-hint">Ticks are kept on this device only.</p></div><div data-lld-shopping data-lld-shop-week="' . esc_attr( $plan['week']['starting'] ) . '">';
		foreach ( $plan['aisles'] as $aisle => $lines ) {
			$main .= '<section data-lld-aisle="' . esc_attr( $aisle ) . '"><h3>' . esc_html( $aisle ) . '</h3><ul class="lld-ticks">';
			foreach ( $lines as $line ) {
				$main .= '<li><label class="lld-check"><input type="checkbox" data-lld-tick="' . esc_attr( $line ) . '"> ' . esc_html( $line ) . '</label></li>';
			}
			$main .= '</ul></section>';
		}
		$main .= '</div>';
		$main .= '<p class="lld-shopping-actions"><button type="button" class="lld-button lld-button-secondary" onclick="window.print()">Print</button> ';
		$main .= '<a class="lld-button" href="' . esc_url( home_url( '/members/meals/?pdf=1' ) ) . '">Download PDF</a></p></section>';
		self::page( 'Meal plan', $main, 200, true );
	}

	public static function workout() {
		$member_id = LLD_Members_Access::current_member_id();
		$consent   = LLD_Members_Account::has_consent( $member_id, 'health' );
		$is_post   = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		$saved     = $consent ? self::active_workout( $member_id ) : null;
		$notice    = '';
		$level     = '';
		$place     = '';
		if ( $consent && $is_post && isset( $_POST['lld_plan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_plan_nonce'] ) ), 'lld_plan' ) ) {
			$level = self::level_of( isset( $_POST['level'] ) ? wp_unslash( $_POST['level'] ) : '' );
			$place = self::place_of( isset( $_POST['place'] ) ? wp_unslash( $_POST['place'] ) : '' );
			if ( isset( $_POST['lld_plan_action'] ) && 'up' === sanitize_key( wp_unslash( $_POST['lld_plan_action'] ) ) ) {
				$from  = $saved ? (string) $saved->level : $level;
				$level = self::NEXT_LEVEL[ $from ] ?? $from;
			}
			self::save_workout( $member_id, $level, $place );
			$saved  = self::active_workout( $member_id );
			$notice = 'Your plan is now ' . $level . ', ' . $place . '.';
		}
		if ( '' === $level ) {
			$level = isset( $_REQUEST['level'] ) ? self::level_of( wp_unslash( $_REQUEST['level'] ) ) : ( $saved ? self::level_of( $saved->level ) : 'beginner' );
			$place = isset( $_REQUEST['place'] ) ? self::place_of( wp_unslash( $_REQUEST['place'] ) ) : ( $saved ? self::place_of( $saved->version_key ) : 'home' );
		}
		$weeks    = self::workout_weeks( $member_id, $level, $place );
		$mine     = $saved && (string) $saved->level === $level && (string) $saved->version_key === $place;
		$last     = $weeks ? max( array_keys( $weeks ) ) : 1;
		$elapsed  = $mine ? self::elapsed_week( $member_id, $saved ) : 1;
		$current  = $mine ? self::advance_week( $saved, $elapsed, $last ) : ( $weeks ? min( array_keys( $weeks ) ) : 1 );
		$week     = isset( $_REQUEST['week'] ) ? (int) $_REQUEST['week'] : $current;
		$week     = isset( $weeks[ $week ] ) ? $week : $current;
		$plan     = $weeks[ $week ] ?? null;
		$session  = isset( $_GET['session'] ) ? max( 1, (int) $_GET['session'] ) : 0;
		if ( $is_post && $plan && isset( $_POST['lld_done_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_done_nonce'] ) ), 'lld_done' ) ) {
			$index = isset( $_POST['lld_session'] ) ? (int) $_POST['lld_session'] : 0;
			if ( $consent && isset( $plan['sessions'][ $index - 1 ] ) ) {
				if ( ! $saved ) {
					self::save_workout( $member_id, $level, $place );
					$saved = self::active_workout( $member_id );
					$mine  = (bool) $saved;
				}
				if ( $mine ) {
					$notice = self::store_session( $member_id, (int) $saved->id, $plan, $week, $index ) ? 'Session saved.' : 'This session is already saved for week ' . $week . '.';
				} else {
					$notice = 'Choose this plan to save your progress on it.';
				}
			}
		}
		$main = '<h1>Workout</h1>';
		if ( '' !== $notice ) {
			$main .= '<p role="status">' . esc_html( $notice ) . '</p>';
		}
		$main .= LLD_Members_Safety::cautions_html( $member_id, array( 'type' => 'lld_workout' ) );
		if ( ! $consent ) {
			$main .= '<p>progress isn\'t saved</p>';
		}
		$main .= '<form method="get" class="lld-form"><p><label for="lld_level">Level</label> <select id="lld_level" name="level">';
		foreach ( array_keys( self::LEVELS ) as $choice ) {
			$main .= '<option value="' . esc_attr( $choice ) . '"' . selected( $level, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
		}
		$main .= '</select> <label for="lld_place">Version</label> <select id="lld_place" name="place">';
		foreach ( array( 'home', 'equipment' ) as $choice ) {
			$main .= '<option value="' . esc_attr( $choice ) . '"' . selected( $place, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
		}
		$main .= '</select> <button class="lld-button" type="submit">Show this week</button></p></form>';
		if ( $consent ) {
			$hidden = wp_nonce_field( 'lld_plan', 'lld_plan_nonce', true, false ) . '<input type="hidden" name="level" value="' . esc_attr( $level ) . '"><input type="hidden" name="place" value="' . esc_attr( $place ) . '">';
			if ( ! $mine ) {
				$main .= '<form method="post"><p>' . $hidden . '<input type="hidden" name="lld_plan_action" value="choose"><button class="lld-button" type="submit">Choose this plan</button></p></form>';
			}
			if ( isset( self::NEXT_LEVEL[ $level ] ) ) {
				if ( $mine && $elapsed > $last ) {
					$main .= '<p>You have finished this level\'s weeks. Move up a level when you\'re ready.</p>';
				}
				$main .= '<form method="post"><p>' . str_replace( 'id="lld_plan_nonce"', 'id="lld_plan_nonce_up"', $hidden ) . '<input type="hidden" name="lld_plan_action" value="up"><button class="lld-button" type="submit">Move up a level</button></p></form>';
			}
		}
		if ( ! $plan ) {
			$main .= LLD_Members_Content::has_approved( 'lld_workout' ) ? LLD_Members_Safety::empty_html( $member_id ) : lld_coming_soon( 'Workout plans for home and with equipment are being prepared. They will appear here as soon as they are ready.', 'See today\'s activity', '/members/home/' );
			self::page( 'Workout', $main );
			return;
		}
		$base  = '/members/workout/?level=' . rawurlencode( $level ) . '&place=' . rawurlencode( $place );
		$main .= '<h2>Week ' . (int) $week . '</h2>';
		if ( count( $weeks ) > 1 ) {
			$main .= '<p>';
			foreach ( array_keys( $weeks ) as $number ) {
				$main .= ( $number === $week ? '<strong>Week ' . (int) $number . '</strong>' : '<a href="' . esc_url( home_url( $base . '&week=' . (int) $number ) ) . '">Week ' . (int) $number . '</a>' ) . ' ';
			}
			$main .= '</p>';
		}
		$index = 0;
		foreach ( $plan['sessions'] as $row ) {
			++$index;
			$href  = home_url( $base . '&week=' . (int) $week . '&session=' . $index );
			$main .= '<p><a href="' . esc_url( $href ) . '">' . esc_html( $row['name'] ) . '</a></p>';
		}
		if ( $session > 0 && isset( $plan['sessions'][ $session - 1 ] ) ) {
			$row   = $plan['sessions'][ $session - 1 ];
			$steps = array();
			foreach ( $row['exercises'] as $exercise ) {
				$steps[] = (int) $exercise['seconds'] . '|' . (int) $exercise['rest'];
			}
			$first = $row['exercises'][0];
			$main .= '<section id="lld-safety"><h2>Safety advice</h2>';
			foreach ( $plan['safety'] as $line ) {
				$main .= '<p>' . esc_html( $line ) . '</p>';
			}
			$main .= '<p><button type="button" class="lld-button" id="lld-ready">I\'m ready</button></p></section>';
			$main .= '<section id="lld-session" hidden><h2 id="lld-exercise">' . esc_html( $first['name'] ) . '</h2>';
			$main .= '<p id="lld-how">' . esc_html( $first['how'] ) . '</p>';
			$main .= '<p id="lld-timer" data-steps="' . esc_attr( implode( ';', $steps ) ) . '">' . (int) $first['seconds'] . '</p>';
			$main .= '<p id="lld-phase">exercise</p>';
			$main .= '<p><button type="button" class="lld-button" id="lld-start">Start</button> <button type="button" class="lld-button" id="lld-pause">Pause</button></p>';
			$main .= '<ol id="lld-steps">';
			foreach ( $row['exercises'] as $exercise ) {
				$main .= '<li data-lld-step data-name="' . esc_attr( $exercise['name'] ) . '" data-how="' . esc_attr( $exercise['how'] ) . '">' . esc_html( $exercise['name'] ) . ': ' . (int) $exercise['seconds'] . ' seconds, then ' . (int) $exercise['rest'] . ' seconds rest.' . ( '' !== $exercise['how'] ? ' ' . esc_html( $exercise['how'] ) : '' ) . '</li>';
			}
			$main .= '</ol>';
			$main .= '<form method="post">';
			$main .= wp_nonce_field( 'lld_done', 'lld_done_nonce', true, false );
			$main .= '<input type="hidden" name="lld_session" value="' . (int) $session . '">';
			$main .= '<input type="hidden" name="level" value="' . esc_attr( $level ) . '"><input type="hidden" name="place" value="' . esc_attr( $place ) . '"><input type="hidden" name="week" value="' . (int) $week . '">';
			$main .= lld_button( array( 'label' => 'Done' ) );
			$main .= '</form></section>';
		}
		self::page( 'Workout', $main );
	}

	public static function challenge() {
		$member_id = LLD_Members_Access::current_member_id();
		$consent   = LLD_Members_Account::has_consent( $member_id, 'health' );
		$today     = LLD_Members_Programme::local_today( $member_id );
		// A joined challenge runs its 28 days by the version stored on joining, across the month boundary (Data Model C6).
		$open  = $consent ? self::open_challenge( $member_id ) : null;
		$shown = $open ? self::challenge_by_version( $member_id, (string) $open->challenge_version ) : null;
		if ( ! $shown ) {
			$open  = null;
			$shown = self::current_challenge( $member_id, $today );
		}
		if ( $consent && ! $open && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_join_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_join_nonce'] ) ), 'lld_join' ) && $shown ) {
			self::join_challenge( $member_id, $shown, $today );
		}
		$row  = $open ? $open : ( $shown && $consent ? self::challenge_row( $member_id, (int) $shown['parent_id'] ) : null );
		$main = '<h1>Challenge</h1>';
		if ( ! $consent ) {
			$main .= '<p>progress isn\'t saved</p>';
		}
		$next = $shown ? null : self::next_challenge( $member_id, $today );
		if ( $next ) {
			// No challenge this month, but an approved one is set for a later month: say when it starts.
			$starts = DateTimeImmutable::createFromFormat( '!Y-m', (string) $next['fields']['month'] );
			$main  .= lld_coming_soon( 'The next challenge, ' . $next['title'] . ', starts on 1 ' . ( $starts ? $starts->format( 'F Y' ) : (string) $next['fields']['month'] ) . '.', 'See today\'s activity', '/members/home/' );
			self::page( 'Challenge', $main );
			return;
		}
		if ( ! $shown ) {
			$main .= LLD_Members_Content::has_approved( 'lld_challenge' ) ? LLD_Members_Safety::empty_html( $member_id ) : lld_coming_soon( 'A new 28-day challenge starts every month. The first one will appear here as soon as it is ready.', 'See today\'s activity', '/members/home/' );
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
		$items   = self::challenge_items( $member_id, $shown );
		$day     = isset( $_REQUEST['day'] ) ? (int) $_REQUEST['day'] : 0;
		$current = 0;
		$done    = array();
		$notice  = '';
		if ( $row && $consent ) {
			$start   = new DateTimeImmutable( (string) $row->joined_on );
			$current = (int) $start->diff( new DateTimeImmutable( $today ) )->days + 1;
			$day     = $day > 0 ? $day : $current;
			if ( $today >= $start->modify( '+27 days' )->format( 'Y-m-d' ) && ! $row->finished_on ) {
				self::finish_challenge( $member_id, $row, $shown, $today );
				$row = self::challenge_row( $member_id, (int) $shown['parent_id'] );
			}
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_tick_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_tick_nonce'] ) ), 'lld_tick' ) ) {
				$notice = self::tick_challenge( $member_id, $row, $items, $day, $current );
			}
			$done = self::challenge_done( $member_id, (int) $row->id );
		}
		if ( $day < 1 ) {
			$day = 1;
		}
		if ( $day > 28 ) {
			$day = 28;
		}
		if ( '' !== $notice ) {
			$main .= '<p role="status">' . esc_html( $notice ) . '</p>';
		}
		if ( $row && $consent ) {
			$main .= '<p data-lld-progress>' . count( $done ) . ' of 28 days done</p>';
		}
		$main .= '<ol class="lld-days">';
		for ( $i = 1; $i <= 28; $i++ ) {
			$main .= '<li><a href="' . esc_url( home_url( '/members/challenge/?day=' . $i ) ) . '">Day ' . $i . '</a>' . ( isset( $done[ $i ] ) ? ' (done)' : '' ) . '</li>';
		}
		$main .= '</ol>';
		if ( array_key_exists( $day - 1, $items ) ) {
			$action = $items[ $day - 1 ];
			$main  .= '<h2>Action for this day</h2><p>' . esc_html( null === $action ? LLD_Members_Safety::empty_line( $member_id ) : $action['title'] ) . '</p>';
			if ( $row && $consent && null !== $action ) {
				if ( isset( $done[ $day ] ) ) {
					$main .= '<p data-lld-day-done>Day ' . (int) $day . ' is done.</p>';
				} elseif ( self::tick_open( $day, $current ) ) {
					$main .= '<form method="post">' . wp_nonce_field( 'lld_tick', 'lld_tick_nonce', true, false ) . '<input type="hidden" name="day" value="' . (int) $day . '">' . lld_button( array( 'label' => 'Mark day ' . (int) $day . ' done' ) ) . '</form>';
				}
			}
		}
		if ( $row && $consent ) {
			$main .= self::challenge_review( $items, $done, $current, (bool) $row->finished_on );
		}
		if ( $row && $row->finished_on ) {
			$badge = self::badge_title( $shown );
			if ( '' !== $badge && self::badge_allowed( $member_id, $shown ) ) {
				$main .= '<p>Badge: ' . esc_html( $badge ) . '</p>';
			}
		}
		self::page( 'Challenge', $main );
	}

	/**
	 * SCR-21 Progress and badges. FR2-36 is Premium: a free member gets the membership lock. When Premium has
	 * ended, the history stays readable with "Download my data" (requirements 8.3), and nothing new is awarded.
	 */
	public static function badges() {
		$member_id = LLD_Members_Access::current_member_id();
		$paid      = LLD_Members_Billing::paid_open( $member_id );
		if ( ! $paid && ! LLD_Members_Billing::ended( $member_id ) ) {
			self::page( 'Progress and badges', '<h1>Progress and badges</h1>' . lld_membership_lock( 'Progress charts and badges are part of Premium.' ) );
			return;
		}
		if ( $paid && LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			self::award( $member_id );
		}
		$main = '<h1>Progress and badges</h1>';
		if ( ! $paid ) {
			$main .= '<p>Your history is still here to read. <a href="' . esc_url( home_url( '/members/data/' ) ) . '">Download my data</a></p>';
		}
		$main .= self::totals_html( $member_id );
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
			} elseif ( ! empty( $_POST['lld_remove_photo'] ) ) {
				self::remove_photo( $member_id, (int) $_POST['lld_remove_photo'] );
			} elseif ( ! empty( $_POST['lld_day_save'] ) ) {
				self::save_day( $member_id );
			} elseif ( self::hides_portion( $member_id ) && array_key_exists( 'lld_portion', $_POST ) ) {
				self::page( 'Food diary', '<h1>Food diary</h1><p>The portion question is not shown.</p>', 403 );
				return;
			} else {
				$saved = self::save_meal( $member_id );
				if ( is_wp_error( $saved ) ) {
					$error = 'This file is too large or not a supported type';
				} else {
					self::save_day( $member_id );
				}
			}
		}
		$main = '<h1>Food diary</h1>';
		if ( '' !== $error ) {
			$main .= '<p class="lld-error">' . esc_html( $error ) . '</p>';
		}
		$main .= '<p class="lld-hint">' . ( $writable ? 'You can add meals.' : 'This diary is read-only.' ) . ' Portion size and three quality checks, no numbers to count.</p>';
		$main .= '<div class="lld-diary-entries">' . self::diary_list( $member_id, $writable ) . '</div>';
		$main .= '<section class="lld-card">' . self::day_html( $member_id, $writable ) . '</section>';
		$summary = self::summary_line( $member_id );
		$focus   = self::focus_line( $member_id );
		if ( '' !== $summary || '' !== $focus ) {
			$main .= '<section class="lld-card"><h2>This week</h2>';
			if ( '' !== $summary ) {
				$main .= '<p data-lld-summary>' . esc_html( $summary ) . '</p>';
			}
			if ( '' !== $focus ) {
				$main .= '<p>' . esc_html( $focus ) . '</p>';
			}
			$main .= '</section>';
		}
		if ( $writable ) {
			$main .= '<section class="lld-card lld-form-card"><h2>Log a meal</h2>' . self::diary_form( $member_id ) . '</section>';
		}
		self::page( 'Food diary', $main, 200, true );
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
		$main .= '<p>A private Facebook group for Premium members, with a live Q&amp;A with the Lifeline Daily team.</p>';
		$main .= self::qa_paragraph( $member_id, false );
		$link  = self::group_link();
		if ( '' !== $link ) {
			$main .= '<p><a class="lld-button lld-button-primary" href="' . esc_url( $link ) . '" rel="noopener">Join the private Facebook group</a></p>';
			$main .= '<p>The owner will approve your request.</p>';
		} else {
			// The group address is an owner input (LLD_FACEBOOK_GROUP_URL): until it is set, say so instead of a dead end.
			$main .= lld_info( 'The group opens soon. The link to join will appear here.' );
		}
		self::page( 'Members\' group', $main );
	}

	/**
	 * The private group's address, from LLD_FACEBOOK_GROUP_URL in wp-config.php (an owner input). Empty while it
	 * is not set or is not a facebook.com https address, and then no link is shown.
	 */
	public static function group_link() {
		if ( ! defined( 'LLD_FACEBOOK_GROUP_URL' ) ) {
			return '';
		}
		$url  = trim( (string) constant( 'LLD_FACEBOOK_GROUP_URL' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! preg_match( '/(^|\.)facebook\.com$/', $host ) ) {
			return '';
		}
		return $url;
	}

	public static function diary_photo() {
		if ( ! is_user_logged_in() || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}
		$requested = (int) get_query_var( 'lld_member' );
		// Diary photos open for their own member only: not for another member, and not for an administrator.
		if ( $requested < 1 || LLD_Members_Access::current_member_id() !== $requested ) {
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
	public static function qa_paragraph( $member_id, $on_dashboard = true ) {
		if ( ! LLD_Members_Billing::paid_open( $member_id ) ) {
			return '';
		}
		$date = self::next_qa( LLD_Members_Programme::local_today( $member_id ) );
		$html = '<div class="lld-group-note">';
		if ( $on_dashboard ) {
			$html .= '<h3>Members\' group and live Q&amp;A</h3>';
		}
		$html .= '' !== $date ? '<p>Next live Q&amp;A ' . esc_html( $date ) . '</p>' : '<p>The next live Q&amp;A date will appear here once it is set.</p>';
		$html .= '<p>Facebook\'s own privacy terms apply.</p>';
		if ( $on_dashboard ) {
			$html .= '<p><a href="' . esc_url( home_url( '/members/group/' ) ) . '">Open the members\' group</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_test( $request ) {
		$params = $request->get_json_params();
		$email  = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		global $wpdb;
		$member_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE email = %s', $email ) );
		if ( $member_id && isset( $params['note_ended'] ) ) {
			self::note_ended( $member_id, sanitize_text_field( (string) $params['note_ended'] ) );
		}
		// Move this member's recorded removals back in time, and the date of the last weekly list.
		if ( $member_id && isset( $params['age_removed'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $params['age_removed'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . LLD_Members_Schema::table( 'fb_removal' ) . ' SET removed_on = %s WHERE member_number = %d AND removed_on IS NOT NULL', (string) $params['age_removed'], $member_id ) );
		}
		if ( isset( $params['fb_list_on'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $params['fb_list_on'] ) ) {
			update_option( 'lld_fb_list_on', (string) $params['fb_list_on'], false );
		}
		// The owner edits and re-approves a meal plan or challenge (a new approved version).
		if ( isset( $params['revise'] ) && is_array( $params['revise'] ) ) {
			$post_id = (int) ( $params['revise']['id'] ?? 0 );
			$changes = isset( $params['revise']['fields'] ) && is_array( $params['revise']['fields'] ) ? $params['revise']['fields'] : array();
			if ( in_array( get_post_type( $post_id ), array( 'lld_meal', 'lld_challenge' ), true ) ) {
				$fields = get_post_meta( $post_id, '_lld_fields', true );
				$fields = is_array( $fields ) ? $fields : array();
				foreach ( $changes as $key => $value ) {
					$fields[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
				}
				update_post_meta( $post_id, '_lld_fields', $fields );
				LLD_Members_Content::approve_now( $post_id );
			}
		}
		return new WP_REST_Response(
			array(
				'badges'    => self::badge_rows( $member_id ),
				'challenge' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'challenge' ) . ' WHERE member_id = %d', $member_id ) ),
				'result'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d', $member_id ) ),
				'workout'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'workout' ) . ' WHERE member_id = %d', $member_id ) ),
				'plan'      => $wpdb->get_row( $wpdb->prepare( 'SELECT level, version_key, started_on, current_week FROM ' . LLD_Members_Schema::table( 'workout' ) . ' WHERE member_id = %d AND active = 1', $member_id ), ARRAY_A ),
				'changes'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'workout_change' ) . ' c JOIN ' . LLD_Members_Schema::table( 'workout' ) . ' w ON w.id = c.workout_id WHERE w.member_id = %d', $member_id ) ),
				'results'   => $wpdb->get_results( $wpdb->prepare( 'SELECT item_version, context_type, programme_day FROM ' . LLD_Members_Schema::table( 'result' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A ),
				'meal_plans' => $wpdb->get_results( $wpdb->prepare( 'SELECT week_starting, template_version, swaps FROM ' . LLD_Members_Schema::table( 'meal_plan' ) . ' WHERE member_id = %d ORDER BY week_starting ASC', $member_id ), ARRAY_A ),
				'removals'  => $wpdb->get_results( $wpdb->prepare( 'SELECT premium_ended_on, removed_on, late FROM ' . LLD_Members_Schema::table( 'fb_removal' ) . ' WHERE member_number = %d ORDER BY id ASC', $member_id ), ARRAY_A ),
				'days'      => $wpdb->get_results( $wpdb->prepare( 'SELECT local_date, water_glasses, hunger, energy FROM ' . LLD_Members_Schema::table( 'diary_day' ) . ' WHERE member_id = %d ORDER BY local_date ASC', $member_id ), ARRAY_A ),
				'photos'    => $member_id > 0 ? array_map( 'basename', glob( lld_members_private_dir() . '/photos/' . $member_id . '/diary-*' ) ?: array() ) : array(),
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
			self::record_removal( isset( $_POST['lld_removal'] ) ? (int) $_POST['lld_removal'] : 0 );
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
				echo '<input type="hidden" name="lld_removal" value="' . (int) $row->id . '">';
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
	private static function page( $title, $main, $status = 200, $wide = false ) {
		lld_members_layout(
			array(
				'title'     => $title,
				'main'      => $main,
				'signed_in' => true,
				'status'    => $status,
				'wide'      => $wide,
			)
		);
	}

	/**
	 * The dashboard's diary shortcut (SCR-09 item 5, SCR-16): the water-glass counter with plus and minus, and the
	 * one-tap "I ate the planned meal" for a Premium member. Both post to the diary page exactly as its own forms do.
	 * Never a calorie number (FR2-37).
	 *
	 * @param int $member_id Member number.
	 */
	public static function dashboard_diary_html( $member_id ) {
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) || ! LLD_Members_Billing::diary_writable( $member_id ) ) {
			return '';
		}
		global $wpdb;
		$today = LLD_Members_Programme::local_today( $member_id );
		$water = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT water_glasses FROM ' . LLD_Members_Schema::table( 'diary_day' ) . ' WHERE member_id = %d AND local_date = %s', $member_id, $today ) );
		$nonce = wp_create_nonce( 'lld_diary' );
		$diary = home_url( '/members/diary/' );
		$step  = static function ( $value, $class, $label, $sign ) use ( $nonce, $diary ) {
			return '<form method="post" action="' . esc_url( $diary ) . '"><input type="hidden" name="lld_diary_nonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="lld_day_save" value="1">'
				. '<input type="hidden" name="lld_water" value="' . (int) $value . '"><button class="lld-step ' . $class . '" type="submit" aria-label="' . esc_attr( $label ) . '">' . $sign . '</button></form>';
		};
		$html  = '<div class="lld-water"><p><strong>Water today</strong><span class="lld-hint">' . esc_html( $water . ( 1 === $water ? ' glass' : ' glasses' ) . ' logged' ) . '</span></p>';
		$html .= '<div class="lld-water-count">' . ( $water > 0 ? $step( $water - 1, 'lld-step-down', 'One glass of water fewer', '&minus;' ) : '' );
		$html .= '<output aria-live="polite">' . $water . '</output>' . ( $water < 40 ? $step( $water + 1, 'lld-step-up', 'One more glass of water', '+' ) : '' ) . '</div></div>';
		if ( LLD_Members_Billing::paid_open( $member_id ) ) {
			$slot  = self::next_slot( $member_id, $today );
			$html .= '<form method="post" action="' . esc_url( $diary ) . '" class="lld-one-tap"><input type="hidden" name="lld_diary_nonce" value="' . esc_attr( $nonce ) . '">';
			$html .= '<input type="hidden" name="lld_meal" value="' . esc_attr( $slot ) . '"><button class="lld-button" type="submit" name="lld_planned" value="1">I ate the planned meal<span class="lld-sr"> for ' . esc_html( $slot ) . '</span></button>';
			$html .= '<p class="lld-hint">Logs ' . esc_html( $slot ) . ' in one tap. Portion size and the three quality checks are in the diary.</p></form>';
		}
		return $html;
	}

	/**
	 * The first meal slot of the day without an entry.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Local date.
	 */
	private static function next_slot( $member_id, $today ) {
		global $wpdb;
		$logged = $wpdb->get_col( $wpdb->prepare( 'SELECT meal FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d AND local_date = %s', $member_id, $today ) );
		foreach ( self::MEAL_SLOTS as $slot ) {
			if ( ! in_array( $slot, (array) $logged, true ) ) {
				return $slot;
			}
		}
		return 'snack';
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
	 * The member's week: each meal with its safe swap alternatives, and the shopping list.
	 *
	 * @param int $member_id Member number.
	 * @return array{days:array<int,array<string,array<string,mixed>>>,shopping:string[]}
	 */
	private static function meal_view( $member_id ) {
		$where = self::meal_week( $member_id );
		$given = self::week_template( $member_id, $where );
		$empty = array(
			'days'     => array(),
			'shopping' => array(),
			'aisles'   => array(),
			'week'     => $where,
			'template' => null,
			'plan_id'  => 0,
			'swaps'    => array(),
		);
		$template = $given['template'];
		if ( ! $template ) {
			return $empty;
		}
		$swaps = $given['swaps'];
		$meals = self::decode_meals( $template );
		$offer = self::decode_swaps( $template );
		$days  = array();
		$bag   = array();
		$group = array();
		foreach ( $meals as $day => $slots ) {
			if ( ! is_array( $slots ) || (int) $day < 1 || (int) $day > 7 ) {
				continue;
			}
			foreach ( $slots as $name => $recipe_id ) {
				$slot = sanitize_key( (string) $name );
				if ( ! in_array( $slot, self::MEAL_SLOTS, true ) ) {
					continue;
				}
				$key     = (int) $day . '-' . $slot;
				$offered = self::offered_ids( $offer, $key, $slot );
				$planned = self::safe_recipe( $member_id, (int) $recipe_id );
				$chosen  = null;
				if ( isset( $swaps[ $key ] ) && in_array( self::version_parent( (string) $swaps[ $key ] ), $offered, true ) ) {
					$chosen = self::safe_recipe( $member_id, self::version_parent( (string) $swaps[ $key ] ) );
				}
				$recipe = $chosen ? $chosen : $planned;
				if ( ! $recipe ) {
					continue;
				}
				$options = array();
				$ids     = $planned ? array_merge( array( (int) $planned['id'] ), $offered ) : $offered;
				foreach ( array_unique( $ids ) as $option_id ) {
					if ( (int) $option_id === (int) $recipe['id'] ) {
						continue;
					}
					$option = self::safe_recipe( $member_id, (int) $option_id );
					if ( $option ) {
						$options[] = $option;
					}
				}
				$recipe['options']           = $options;
				$days[ (int) $day ][ $slot ] = $recipe;
				foreach ( $recipe['ingredients'] as $line ) {
					$bag[ $line ]   = $line;
					$group[ $line ] = $recipe['aisles'][ $line ] ?? 'Other';
				}
			}
		}
		ksort( $days );
		return array(
			'days'     => $days,
			'shopping' => array_values( $bag ),
			'aisles'   => self::group_aisles( $group ),
			'week'     => $where,
			'template' => $template,
			'plan_id'  => $given['plan_id'],
			'swaps'    => $swaps,
		);
	}

	/**
	 * Shopping lines by group, in the shop's usual order, then "Other".
	 *
	 * @param array<string,string> $group Line => group.
	 * @return array<string,string[]>
	 */
	private static function group_aisles( $group ) {
		$out = array();
		foreach ( array_merge( array_keys( self::AISLES ), array( 'Other' ) ) as $aisle ) {
			foreach ( $group as $line => $name ) {
				if ( $name === $aisle ) {
					$out[ $aisle ][] = (string) $line;
				}
			}
		}
		return $out;
	}

	/**
	 * Where the member is for meal plans: the track, its week and the local date that week started. A week is 7 days
	 * from the day the track started (requirements section 8.3), so the meal week and the track week stay in step.
	 * With no active track, the latest enrolment's track counts on from its first day; with no enrolment at all,
	 * weeks count from the day the member joined, with no track.
	 *
	 * @param int $member_id Member number.
	 * @return array{track:int,week:int,starting:string}
	 */
	private static function meal_week( $member_id ) {
		global $wpdb;
		$today  = LLD_Members_Programme::local_today( $member_id );
		$active = LLD_Members_Programme::active( $member_id );
		if ( $active ) {
			$parts = LLD_Members_Programme::position_parts( LLD_Members_Programme::programme_day( $member_id, (int) $active->id ) );
			return array(
				'track'    => (int) LLD_Members_Programme::track_id( (string) $active->track_key ),
				'week'     => (int) $parts['week'],
				'starting' => ( new DateTimeImmutable( $today ) )->modify( '-' . ( (int) $parts['day'] - 1 ) . ' days' )->format( 'Y-m-d' ),
			);
		}
		$last   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, track_key FROM ' . LLD_Members_Schema::table( 'enrolment' ) . ' WHERE member_id = %d ORDER BY id DESC LIMIT 1', $member_id ) );
		$track  = 0;
		$anchor = '';
		if ( $last ) {
			$track  = (int) LLD_Members_Programme::track_id( (string) $last->track_key );
			$anchor = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(first_on) FROM ' . LLD_Members_Schema::table( 'enrolment_period' ) . ' WHERE enrolment_id = %d', (int) $last->id ) );
		} else {
			$anchor = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT joined_on FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		}
		return self::week_from( $track, $anchor, $today );
	}

	/**
	 * Week number and its first day, counting 7-day weeks from an anchor date.
	 *
	 * @param int    $track  Track id, or 0.
	 * @param string $anchor First day, Y-m-d; today when missing or later than today.
	 * @param string $today  Local date.
	 * @return array{track:int,week:int,starting:string}
	 */
	private static function week_from( $track, $anchor, $today ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $anchor ) || $anchor > $today ) {
			$anchor = $today;
		}
		$days = (int) ( new DateTimeImmutable( $anchor ) )->diff( new DateTimeImmutable( $today ) )->days;
		$week = intdiv( $days, 7 ) + 1;
		return array(
			'track'    => (int) $track,
			'week'     => $week,
			'starting' => ( new DateTimeImmutable( $anchor ) )->modify( '+' . ( ( $week - 1 ) * 7 ) . ' days' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * The template for this week. Once a week is given, its template version is fixed (Data Model C14): later edits
	 * don't change it. It is replaced only if that version is no longer approved and live, or no longer safe for
	 * the member, and then the week's swaps start again. Without health consent nothing is stored.
	 *
	 * @param int                                         $member_id Member number.
	 * @param array{track:int,week:int,starting:string}   $where     Meal week.
	 * @return array{template:array<string,mixed>|null,plan_id:int,swaps:array<string,string>}
	 */
	private static function week_template( $member_id, $where ) {
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return array(
				'template' => self::matching_template( $member_id, $where ),
				'plan_id'  => 0,
				'swaps'    => array(),
			);
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'meal_plan' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, template_version, swaps FROM {$table} WHERE member_id = %d AND week_starting = %s", $member_id, $where['starting'] ) );
		$swaps = $row ? json_decode( (string) $row->swaps, true ) : array();
		$swaps = is_array( $swaps ) ? $swaps : array();
		if ( $row ) {
			$kept = self::exact_version( 'lld_meal', (string) $row->template_version );
			if ( $kept && LLD_Members_Safety::allows( $member_id, $kept ) ) {
				return array(
					'template' => $kept,
					'plan_id'  => (int) $row->id,
					'swaps'    => $swaps,
				);
			}
		}
		$template = self::matching_template( $member_id, $where );
		if ( ! $template ) {
			return array(
				'template' => null,
				'plan_id'  => $row ? (int) $row->id : 0,
				'swaps'    => array(),
			);
		}
		$key = self::version_key( $template );
		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'member_id'        => $member_id,
					'week_starting'    => $where['starting'],
					'template_version' => $key,
					'swaps'            => '{}',
				),
				array( '%d', '%s', '%s', '%s' )
			);
			return array(
				'template' => $template,
				'plan_id'  => (int) $wpdb->insert_id,
				'swaps'    => array(),
			);
		}
		if ( self::version_parent( (string) $row->template_version ) !== (int) $template['parent_id'] ) {
			$swaps = array();
		}
		$wpdb->update( $table, array( 'template_version' => $key, 'swaps' => wp_json_encode( (object) $swaps ) ), array( 'id' => (int) $row->id ), array( '%s', '%s' ), array( '%d' ) );
		return array(
			'template' => $template,
			'plan_id'  => (int) $row->id,
			'swaps'    => $swaps,
		);
	}

	/**
	 * The exact approved version a member record points at ("parent:vN"), while the item is still approved and live.
	 * Older records that hold only the parent id get the live version.
	 *
	 * @param string $type   Content type.
	 * @param string $stored Stored version key.
	 * @return array<string,mixed>|null
	 */
	private static function exact_version( $type, $stored ) {
		$stored = (string) $stored;
		if ( preg_match( '/^\d+$/', $stored ) ) {
			$live = LLD_Members_Content::shown( (int) $stored );
			if ( ! is_array( $live ) || $type !== (string) ( $live['type'] ?? $type ) ) {
				return null;
			}
			$live['type'] = $type;
			return $live;
		}
		if ( ! preg_match( '/^(\d+):v(\d+)$/', $stored, $m ) ) {
			return null;
		}
		$live = LLD_Members_Content::shown( (int) $m[1] );
		if ( ! is_array( $live ) || $type !== (string) ( $live['type'] ?? $type ) ) {
			return null;
		}
		if ( (int) ( $live['version'] ?? 0 ) === (int) $m[2] ) {
			$live['type'] = $type;
			return $live;
		}
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'lld_version',
				'post_parent'    => (int) $m[1],
				'posts_per_page' => 1,
				'meta_key'       => '_lld_version',
				'meta_value'     => (int) $m[2],
			)
		);
		if ( ! $posts || '' === (string) get_post_meta( $posts[0]->ID, '_lld_approved_on', true ) ) {
			return null;
		}
		$snap = json_decode( (string) $posts[0]->post_content, true );
		if ( ! is_array( $snap ) ) {
			return null;
		}
		$snap['type']        = $type;
		$snap['parent_id']   = (int) $m[1];
		$snap['version']     = (int) $m[2];
		$snap['version_id']  = (int) $posts[0]->ID;
		$snap['fingerprint'] = (string) get_post_meta( $posts[0]->ID, '_lld_fingerprint', true );
		$snap['approved_on'] = (string) get_post_meta( $posts[0]->ID, '_lld_approved_on', true );
		return $snap;
	}

	/**
	 * Recipe ids the template offers for one meal: "3-dinner" first, then the slot on every day ("dinner").
	 *
	 * @param array<string,mixed> $offer Template swap options.
	 * @param string              $key   Day and slot, for example 3-dinner.
	 * @param string              $slot  Slot.
	 * @return int[]
	 */
	private static function offered_ids( $offer, $key, $slot ) {
		$list = array();
		if ( isset( $offer[ $key ] ) && is_array( $offer[ $key ] ) ) {
			$list = $offer[ $key ];
		} elseif ( isset( $offer[ $slot ] ) && is_array( $offer[ $slot ] ) ) {
			$list = $offer[ $slot ];
		}
		return array_values( array_filter( array_map( 'intval', $list ) ) );
	}

	/**
	 * The approved, safe template for the member's track, diet and week.
	 *
	 * @param int                                            $member_id Member number.
	 * @param array{track:int,week:int,starting:string}|null $where     Meal week; worked out when null.
	 * @return array<string,mixed>|null
	 */
	private static function matching_template( $member_id, $where = null ) {
		$where   = is_array( $where ) ? $where : self::meal_week( $member_id );
		$track   = (int) $where['track'];
		$diet    = self::diet_of( $member_id );
		$weeks   = array();
		$general = array();
		foreach ( self::approved_of( 'lld_meal' ) as $meal ) {
			$meal['type'] = 'lld_meal';
			if ( ! LLD_Members_Safety::allows( $member_id, $meal ) ) {
				continue;
			}
			$fields = isset( $meal['fields'] ) && is_array( $meal['fields'] ) ? $meal['fields'] : array();
			$owner  = (int) ( $fields['track_id'] ?? 0 );
			if ( $track && $owner !== $track ) {
				continue;
			}
			$meal_diet = sanitize_title( (string) ( $fields['diet'] ?? '' ) );
			if ( $diet && $meal_diet && $meal_diet !== $diet && 'everyone' !== $meal_diet ) {
				continue;
			}
			$number = max( 1, (int) ( $fields['week_number'] ?? 1 ) );
			if ( ! $track && ! $owner && ! isset( $general[ $number ] ) ) {
				$general[ $number ] = $meal;
			}
			if ( ! isset( $weeks[ $number ] ) ) {
				$weeks[ $number ] = $meal;
			}
		}
		if ( ! $track && $general ) {
			$weeks = $general;
		}
		return self::pick_week( $weeks, (int) $where['week'] );
	}

	/**
	 * The template for a week number: that week if approved, otherwise the approved weeks in turn, so a member past
	 * the last approved week keeps getting new weeks rather than week 1 again and again.
	 *
	 * @param array<int,array<string,mixed>> $weeks Week number => template.
	 * @param int                            $week  Week wanted.
	 * @return array<string,mixed>|null
	 */
	private static function pick_week( $weeks, $week ) {
		if ( ! $weeks ) {
			return null;
		}
		if ( isset( $weeks[ $week ] ) ) {
			return $weeks[ $week ];
		}
		ksort( $weeks );
		$list = array_values( $weeks );
		return $list[ ( max( 1, (int) $week ) - 1 ) % count( $list ) ];
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
		if ( ! preg_match( '/^([1-7])-(breakfast|lunch|dinner|snack)$/', $slot, $parts ) ) {
			return;
		}
		// Only an alternative this meal offers right now; meal_view has applied the safety matrix to each one.
		$view     = self::meal_view( $member_id );
		$template = $view['template'];
		if ( ! $template || ! $view['plan_id'] ) {
			return;
		}
		$meal   = $view['days'][ (int) $parts[1] ][ $parts[2] ] ?? null;
		$picked = null;
		foreach ( $meal ? $meal['options'] : array() as $option ) {
			if ( (int) $option['id'] === $recipe ) {
				$picked = $option;
			}
		}
		if ( ! $picked ) {
			return;
		}
		$meals   = self::decode_meals( $template );
		$planned = (int) ( $meals[ $parts[1] ][ $parts[2] ] ?? 0 );
		$swaps   = $view['swaps'];
		if ( $recipe === $planned ) {
			unset( $swaps[ $slot ] );
		} else {
			$swaps[ $slot ] = (string) $picked['version_key'];
		}
		global $wpdb;
		$wpdb->update(
			LLD_Members_Schema::table( 'meal_plan' ),
			array( 'swaps' => wp_json_encode( (object) $swaps ) ),
			array(
				'id'        => (int) $view['plan_id'],
				'member_id' => $member_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
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
		$aisles = array();
		$raw    = (string) ( $fields['ingredients'] ?? '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts           = array_map( 'trim', explode( '|', $line ) );
			$text            = $parts[0] . ( isset( $parts[1] ) && '' !== $parts[1] ? ', ' . $parts[1] . ( isset( $parts[2] ) && '' !== $parts[2] ? ' ' . $parts[2] : '' ) : '' );
			$lines[]         = $text;
			$aisles[ $text ] = self::aisle_of( $parts[0], $parts[3] ?? '' );
		}
		return array(
			'id'          => (int) $snap['parent_id'],
			'version_key' => self::version_key( $snap ),
			'title'       => (string) $snap['title'],
			'ingredients' => $lines,
			'aisles'      => $aisles,
			'servings'    => (string) ( $fields['servings'] ?? '' ),
			'diet'        => (string) ( $fields['diet'] ?? '' ),
		);
	}

	/**
	 * The shopping-list group for an ingredient (SCR-15 "grouped by type of food"). The recipe's own fourth
	 * column ("name | amount | unit | group") wins when it names a group; otherwise the name decides.
	 *
	 * @param string $name  Ingredient name.
	 * @param string $given Group written on the recipe, if any.
	 */
	private static function aisle_of( $name, $given = '' ) {
		$given = trim( (string) $given );
		foreach ( array_keys( self::AISLES ) as $aisle ) {
			if ( '' !== $given && 0 === strcasecmp( $given, $aisle ) ) {
				return $aisle;
			}
		}
		// The longest word that starts a word in the name decides: "butter beans" is beans, "black pepper" a spice.
		$name = ' ' . strtolower( (string) preg_replace( '/[^a-z ]+/i', ' ', (string) $name ) ) . ' ';
		$best = 'Other';
		$size = 0;
		foreach ( self::AISLES as $aisle => $words ) {
			foreach ( $words as $word ) {
				if ( strlen( $word ) > $size && false !== strpos( $name, ' ' . $word ) ) {
					$best = $aisle;
					$size = strlen( $word );
				}
			}
		}
		return $best;
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
		$pdf  = self::shopping_pdf( 'Shopping list', $view['aisles'] );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="shopping-list.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Cache-Control: no-store' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * A plain PDF 1.4 shopping list without a library: Helvetica in WinAnsi encoding, one line per item
	 * (long items wrap onto indented lines), as many pages as needed, and a cross-reference table with
	 * the real byte offset of every object.
	 *
	 * @param string   $title Heading on the first page.
	 * @param string[] $items Shopping-list lines, UTF-8.
	 * @return string PDF bytes.
	 */
	public static function shopping_pdf( $title, $items ) {
		$size    = 11;
		$leading = 16;
		$left    = 56;
		$width   = 612 - 2 * $left;
		$top     = 740;
		$bottom  = 56;
		$groups  = array();
		foreach ( $items ? $items : array( 'Nothing on the list this week.' ) as $key => $item ) {
			if ( is_array( $item ) ) {
				$groups[ (string) $key ] = $item;
			} else {
				$groups[''][] = $item;
			}
		}
		$rows = array();
		foreach ( $groups as $heading => $lines ) {
			if ( '' !== $heading ) {
				$rows[] = array( 'F2', 12, self::win_ansi( $heading ) );
			}
			foreach ( $lines as $item ) {
				$first = true;
				foreach ( self::pdf_wrap( "\x95 " . self::win_ansi( (string) $item ), $size, $width - 12 ) as $piece ) {
					$rows[] = array( 'F1', $size, $first ? $piece : '  ' . $piece );
					$first  = false;
				}
			}
		}
		$pages = array();
		$y     = $top - 30;
		$body  = 'BT /F2 16 Tf ' . $left . ' ' . $top . ' Td (' . self::pdf_string( self::win_ansi( (string) $title ) ) . ") Tj ET\n";
		foreach ( $rows as $index => $row ) {
			// a heading never ends a page: it moves on with its first line
			$needs = 'F2' === $row[0] ? $bottom + $leading : $bottom;
			if ( $y < $needs ) {
				$pages[] = $body;
				$body    = '';
				$y       = $top;
			} elseif ( 'F2' === $row[0] && $index > 0 ) {
				$y -= 6;
			}
			$body .= 'BT /' . $row[0] . ' ' . $row[1] . ' Tf ' . $left . ' ' . $y . ' Td (' . self::pdf_string( $row[2] ) . ") Tj ET\n";
			$y    -= $leading;
		}
		$pages[] = $body;

		$objects    = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$kids       = array();
		foreach ( $pages as $index => $stream ) {
			$page_id             = 5 + 2 * $index;
			$content_id          = $page_id + 1;
			$kids[]              = $page_id . ' 0 R';
			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
			$objects[ $content_id ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $pages ) . ' >>';
		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $object ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	/**
	 * UTF-8 to WinAnsi (Windows-1252) bytes; anything outside it becomes "?". Control characters become spaces.
	 *
	 * @param string $text UTF-8 text.
	 */
	private static function win_ansi( $text ) {
		static $map = array(
			0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87,
			0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91,
			0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98,
			0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
		);
		$chars = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return (string) preg_replace( '/[^\x20-\x7E]/', '?', (string) $text );
		}
		$out = '';
		foreach ( $chars as $char ) {
			$bytes = array_values( unpack( 'C*', $char ) );
			$count = count( $bytes );
			if ( 1 === $count ) {
				$code = $bytes[0];
			} elseif ( 2 === $count ) {
				$code = ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
			} elseif ( 3 === $count ) {
				$code = ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
			} else {
				$code = 0x10000;
			}
			if ( $code < 0x20 ) {
				$out .= ' ';
			} elseif ( $code < 0x7F ) {
				$out .= chr( $code );
			} elseif ( $code >= 0xA0 && $code <= 0xFF ) {
				$out .= chr( $code );
			} elseif ( isset( $map[ $code ] ) ) {
				$out .= chr( $map[ $code ] );
			} else {
				$out .= '?';
			}
		}
		return $out;
	}

	/**
	 * Word-wrap WinAnsi text to a width in points, using approximate Helvetica widths.
	 *
	 * @param string $text  WinAnsi bytes.
	 * @param int    $size  Font size.
	 * @param int    $width Width in points.
	 * @return string[]
	 */
	private static function pdf_wrap( $text, $size, $width ) {
		$measure = static function ( $piece ) use ( $size ) {
			$units = 0.0;
			foreach ( '' === $piece ? array() : str_split( $piece ) as $byte ) {
				if ( false !== strpos( " ,.:;'!|ijlrtf()[]", $byte ) ) {
					$units += 0.3;
				} elseif ( false !== strpos( 'ABCDEFGHKMNOQRUVWXYmw@%&', $byte ) || ord( $byte ) >= 0x80 ) {
					$units += 0.85;
				} else {
					$units += 0.56;
				}
			}
			return $units * $size;
		};
		$lines = array();
		$line  = '';
		foreach ( explode( ' ', $text ) as $word ) {
			while ( $measure( $word ) > $width ) {
				$cut = strlen( $word );
				while ( $cut > 1 && $measure( substr( $word, 0, $cut ) ) > $width ) {
					--$cut;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}
				$lines[] = substr( $word, 0, $cut );
				$word    = (string) substr( $word, $cut );
			}
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && $measure( $try ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line || ! $lines ) {
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Escape WinAnsi bytes for a PDF literal string; bytes above ASCII are written as octal.
	 *
	 * @param string $text WinAnsi bytes.
	 */
	private static function pdf_string( $text ) {
		$out = '';
		foreach ( '' === (string) $text ? array() : str_split( (string) $text ) as $byte ) {
			if ( '\\' === $byte || '(' === $byte || ')' === $byte ) {
				$out .= '\\' . $byte;
			} elseif ( ord( $byte ) >= 0x80 || ord( $byte ) < 0x20 ) {
				$out .= sprintf( '\\%03o', ord( $byte ) );
			} else {
				$out .= $byte;
			}
		}
		return $out;
	}

	/**
	 * Approved workout weeks for one level and version that pass the safety matrix: week number => plan.
	 *
	 * @param int    $member_id Member number.
	 * @param string $level     Level.
	 * @param string $place     home or equipment.
	 * @return array<int,array{id:int,version_key:string,safety:string[],sessions:array<int,array<string,mixed>>}>
	 */
	private static function workout_weeks( $member_id, $level, $place ) {
		$weeks = array();
		foreach ( self::approved_of( 'lld_workout' ) as $plan ) {
			$plan['type'] = 'lld_workout';
			if ( ! LLD_Members_Safety::allows( $member_id, $plan ) ) {
				continue;
			}
			$fields = isset( $plan['fields'] ) && is_array( $plan['fields'] ) ? $plan['fields'] : array();
			if ( sanitize_key( (string) ( $fields['level'] ?? '' ) ) !== $level || sanitize_key( (string) ( $fields['place'] ?? '' ) ) !== $place ) {
				continue;
			}
			$number = max( 1, (int) ( $fields['week_number'] ?? 1 ) );
			if ( isset( $weeks[ $number ] ) ) {
				continue;
			}
			$sessions = self::parse_sessions( (string) ( $fields['sessions'] ?? '' ) );
			if ( ! $sessions ) {
				continue;
			}
			$weeks[ $number ] = array(
				'id'          => (int) $plan['parent_id'],
				'version_key' => self::version_key( $plan ),
				'safety'      => self::workout_advice( $member_id, $plan ),
				'sessions'    => $sessions,
			);
		}
		ksort( $weeks );
		return $weeks;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $level     Level.
	 * @param string $place     home or equipment.
	 * @param int    $week      Week number.
	 * @return array{id:int,version_key:string,safety:string[],sessions:array<int,array<string,mixed>>}|null
	 */
	private static function workout_plan( $member_id, $level, $place, $week ) {
		$weeks = self::workout_weeks( $member_id, $level, $place );
		return $weeks[ (int) $week ] ?? null;
	}

	/**
	 * Sessions from the owner's text. Sessions are blocks separated by a blank line; a line without "|" names the
	 * session, and each "name | seconds | rest | instructions" line is one exercise. Text with no blank line and no
	 * session names is the older form: each line is a session with one exercise.
	 *
	 * @param string $text Sessions field.
	 * @return array<int,array{name:string,exercises:array<int,array{name:string,seconds:int,rest:int,how:string}>}>
	 */
	private static function parse_sessions( $text ) {
		$text   = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		$blocks = '' === $text ? array() : preg_split( '/\n[ \t]*\n/', $text );
		$named  = (bool) preg_match( '/^[^|\n]+$/m', $text );
		if ( 1 === count( $blocks ) && ! $named ) {
			$blocks = explode( "\n", $text );
		}
		$sessions = array();
		foreach ( $blocks as $block ) {
			$name      = '';
			$exercises = array();
			foreach ( explode( "\n", (string) $block ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( false === strpos( $line, '|' ) ) {
					$name = '' === $name ? $line : $name;
					continue;
				}
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( '' === $parts[0] ) {
					continue;
				}
				$exercises[] = array(
					'name'    => $parts[0],
					'seconds' => isset( $parts[1] ) && (int) $parts[1] > 0 ? (int) $parts[1] : 30,
					'rest'    => isset( $parts[2] ) && '' !== $parts[2] ? max( 0, (int) $parts[2] ) : 15,
					'how'     => isset( $parts[3] ) ? implode( ' | ', array_slice( $parts, 3 ) ) : '',
				);
			}
			if ( ! $exercises ) {
				continue;
			}
			$sessions[] = array(
				'name'      => '' !== $name ? $name : $exercises[0]['name'],
				'exercises' => $exercises,
			);
		}
		return $sessions;
	}

	/**
	 * Safety advice shown before every workout (FR2-34, requirements section 8.2).
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $plan      Approved workout snapshot.
	 * @return string[]
	 */
	private static function workout_advice( $member_id, $plan ) {
		$fields = isset( $plan['fields'] ) && is_array( $plan['fields'] ) ? $plan['fields'] : array();
		$tags   = array();
		foreach ( ( isset( $plan['tags'] ) && is_array( $plan['tags'] ) ) ? $plan['tags'] : array() as $tag ) {
			$tags[] = sanitize_title( (string) $tag );
		}
		$lines = array();
		$own   = trim( (string) ( $fields['safety'] ?? '' ) );
		if ( '' !== $own ) {
			$lines[] = $own;
		}
		$lines[]    = self::WORKOUT_ADVICE;
		$situations = LLD_Members_Safety::situations( $member_id );
		if ( in_array( 'diabetes', $situations, true ) ) {
			$lines[] = self::DIABETES_EXERCISE;
		}
		if ( in_array( 'blood-pressure', $situations, true ) && in_array( 'high-intensity', $tags, true ) ) {
			$lines[] = self::INTENSE_EXERCISE;
		}
		foreach ( LLD_Members_Safety::notes( $member_id ) as $note ) {
			$lines[] = (string) $note;
		}
		return array_values( array_unique( $lines ) );
	}

	/**
	 * @param mixed $value Posted or stored level.
	 */
	private static function level_of( $value ) {
		$value = sanitize_key( (string) $value );
		return isset( self::LEVELS[ $value ] ) ? $value : 'beginner';
	}

	/**
	 * @param mixed $value Posted or stored version.
	 */
	private static function place_of( $value ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'home', 'equipment' ), true ) ? $value : 'home';
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function active_workout( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'workout' ) . ' WHERE member_id = %d AND active = 1 ORDER BY id DESC LIMIT 1', $member_id ) );
	}

	/**
	 * Week of the plan by the calendar: week 1 for the first 7 days from the start, and so on.
	 *
	 * @param int    $member_id Member number.
	 * @param object $saved     Active workout row.
	 */
	private static function elapsed_week( $member_id, $saved ) {
		$start = (string) $saved->started_on;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			return 1;
		}
		$today = LLD_Members_Programme::local_today( $member_id );
		if ( $today <= $start ) {
			return 1;
		}
		$days = (int) ( new DateTimeImmutable( $start ) )->diff( new DateTimeImmutable( $today ) )->days;
		return intdiv( $days, 7 ) + 1;
	}

	/**
	 * Current week moves on after 7 days (Data Model C7), never back, and not past the last approved week.
	 *
	 * @param object $saved   Active workout row.
	 * @param int    $elapsed Week by the calendar.
	 * @param int    $last    Last approved week for this plan.
	 */
	private static function advance_week( $saved, $elapsed, $last ) {
		$stored = max( 1, (int) $saved->current_week );
		$week   = min( max( $stored, (int) $elapsed ), max( 1, (int) $last ) );
		if ( $week !== $stored ) {
			global $wpdb;
			$wpdb->update( LLD_Members_Schema::table( 'workout' ), array( 'current_week' => $week ), array( 'id' => (int) $saved->id ), array( '%d' ), array( '%d' ) );
		}
		return $week;
	}

	/**
	 * Save the member's chosen plan. A new level starts again at week 1 and is recorded as a change; a new version
	 * of the same level keeps the week.
	 *
	 * @param int    $member_id Member number.
	 * @param string $level     Level.
	 * @param string $place     Version.
	 */
	private static function save_workout( $member_id, $level, $place ) {
		global $wpdb;
		$table   = LLD_Members_Schema::table( 'workout' );
		$today   = LLD_Members_Programme::local_today( $member_id );
		$current = self::active_workout( $member_id );
		if ( $current && (string) $current->level === $level ) {
			if ( (string) $current->version_key !== $place ) {
				$wpdb->update( $table, array( 'version_key' => $place ), array( 'id' => (int) $current->id ), array( '%s' ), array( '%d' ) );
			}
			return;
		}
		if ( $current ) {
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
		$wpdb->insert(
			$table,
			array(
				'member_id'    => $member_id,
				'level'        => $level,
				'version_key'  => $place,
				'started_on'   => $today,
				'current_week' => 1,
				'active'       => 1,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * One result per occurrence (Data Model C8): this session version, in this week of this workout plan.
	 *
	 * @param int                 $member_id  Member number.
	 * @param int                 $workout_id Active workout row.
	 * @param array<string,mixed> $plan       The week's plan.
	 * @param int                 $week       Week number.
	 * @param int                 $index      Session number in the week.
	 * @return bool False when this occurrence is already saved.
	 */
	private static function store_session( $member_id, $workout_id, $plan, $week, $index ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'result' );
		$item  = (string) $plan['version_key'] . ':s' . (int) $index;
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND item_version = %s AND context_type = 'workout' AND context_id = %d AND programme_day = %d", $member_id, $item, $workout_id, $week ) );
		if ( $found ) {
			return false;
		}
		$ok = $wpdb->insert(
			$table,
			array(
				'member_id'     => $member_id,
				'item_version'  => $item,
				'context_type'  => 'workout',
				'context_id'    => $workout_id,
				'programme_day' => (int) $week,
				'local_date'    => LLD_Members_Programme::local_today( $member_id ),
				'result'        => 'done',
				'recorded_at'   => lld_members_now_mysql( $member_id ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return false !== $ok;
	}

	/**
	 * @param string $today Local date.
	 * @return array<string,mixed>|null
	 */
	private static function current_challenge( $member_id, $today ) {
		$month = substr( $today, 0, 7 );
		$found = null;
		foreach ( self::approved_of( 'lld_challenge' ) as $challenge ) {
			$challenge['type'] = 'lld_challenge';
			if ( ! LLD_Members_Safety::allows( $member_id, $challenge ) ) {
				continue;
			}
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
	 * The earliest approved challenge set for a month after this one that the safety rules allow this member, or null.
	 *
	 * @param int    $member_id Member number.
	 * @param string $today     Member's local date.
	 * @return array<string,mixed>|null
	 */
	private static function next_challenge( $member_id, $today ) {
		$month = substr( $today, 0, 7 );
		$found = null;
		foreach ( self::approved_of( 'lld_challenge' ) as $challenge ) {
			$challenge['type'] = 'lld_challenge';
			$when              = (string) ( $challenge['fields']['month'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $when ) || $when <= $month || ! LLD_Members_Safety::allows( $member_id, $challenge ) ) {
				continue;
			}
			if ( ! $found || $when < (string) $found['fields']['month'] ) {
				$found = $challenge;
			}
		}
		return $found;
	}

	/**
	 * Daily actions by position. An action the safety matrix excludes stays in its day as null, so later days keep their place.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $challenge Challenge snapshot.
	 * @return array<int,string|null>
	 */
	private static function challenge_activities( $member_id, $challenge ) {
		return array_map(
			static function ( $item ) {
				return null === $item ? null : $item['title'];
			},
			self::challenge_items( $member_id, $challenge )
		);
	}

	/**
	 * Daily actions by position, with their exact versions; null where the safety matrix excludes the action.
	 *
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $challenge Challenge snapshot.
	 * @return array<int,array{title:string,version_key:string}|null>
	 */
	private static function challenge_items( $member_id, $challenge ) {
		$fields = isset( $challenge['fields'] ) && is_array( $challenge['fields'] ) ? $challenge['fields'] : array();
		$ids    = preg_split( '/\s*,\s*/', trim( (string) ( $fields['activity_ids'] ?? '' ) ) );
		$titles = array();
		foreach ( $ids as $id ) {
			if ( (int) $id < 1 ) {
				continue;
			}
			$snap = LLD_Members_Content::shown( (int) $id );
			if ( ! is_array( $snap ) ) {
				continue;
			}
			$snap['type'] = 'lld_activity';
			$titles[]     = LLD_Members_Safety::allows( $member_id, $snap ) ? array(
				'title'       => (string) $snap['title'],
				'version_key' => self::version_key( $snap ),
			) : null;
		}
		return $titles;
	}

	/**
	 * @param int                 $member_id Member number.
	 * @param array<string,mixed> $challenge Approved challenge snapshot.
	 * @param string              $today     Local date.
	 */
	private static function join_challenge( $member_id, $challenge, $today ) {
		if ( self::challenge_row( $member_id, (int) $challenge['parent_id'] ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'challenge' ),
			array(
				'member_id'         => $member_id,
				'challenge_version' => self::version_key( $challenge ),
				'joined_on'         => $today,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Exact approved version of a content item, as stored on member records: "parent:vN".
	 *
	 * @param array<string,mixed> $snap Approved snapshot.
	 */
	private static function version_key( $snap ) {
		return (int) ( $snap['parent_id'] ?? 0 ) . ':v' . (int) ( $snap['version'] ?? 0 );
	}

	/**
	 * Parent id from a stored "parent:vN" key; older rows hold the parent id alone.
	 *
	 * @param string $stored Stored key.
	 */
	private static function version_parent( $stored ) {
		return (int) strtok( (string) $stored, ':' );
	}

	/**
	 * A challenge day can be ticked on the day and up to 2 days late (requirements 8.3), never ahead.
	 *
	 * @param int $day     Challenge day.
	 * @param int $current Today's challenge day.
	 */
	private static function tick_open( $day, $current ) {
		return $day >= 1 && $day <= 28 && $day <= $current && $current - $day <= 2;
	}

	/**
	 * Record a challenge day as done (Data Model C8: challenge and day).
	 *
	 * @param int                               $member_id Member number.
	 * @param object                            $row       Challenge participation.
	 * @param array<int,array<string,string>|null> $items  Daily actions.
	 * @param int                               $day       Challenge day.
	 * @param int                               $current   Today's challenge day.
	 */
	private static function tick_challenge( $member_id, $row, $items, $day, $current ) {
		$item = $items[ $day - 1 ] ?? null;
		if ( ! $item ) {
			return 'There is no action to mark for that day.';
		}
		if ( ! self::tick_open( $day, $current ) ) {
			return 'Day ' . (int) $day . ' can no longer be marked. Days can be marked up to 2 days late.';
		}
		if ( isset( self::challenge_done( $member_id, (int) $row->id )[ $day ] ) ) {
			return 'Day ' . (int) $day . ' is already done.';
		}
		global $wpdb;
		$wpdb->insert(
			LLD_Members_Schema::table( 'result' ),
			array(
				'member_id'     => $member_id,
				'item_version'  => (string) $item['version_key'],
				'context_type'  => 'challenge',
				'context_id'    => (int) $row->id,
				'programme_day' => (int) $day,
				'local_date'    => ( new DateTimeImmutable( (string) $row->joined_on ) )->modify( '+' . ( (int) $day - 1 ) . ' days' )->format( 'Y-m-d' ),
				'result'        => 'done',
				'recorded_at'   => lld_members_now_mysql( $member_id ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return 'Day ' . (int) $day . ' done.';
	}

	/**
	 * Challenge days done, built fresh from the results (Data Model C12: reviews are worked out when opened).
	 *
	 * @param int $member_id Member number.
	 * @param int $row_id    Challenge participation.
	 * @return array<int,bool>
	 */
	private static function challenge_done( $member_id, $row_id ) {
		global $wpdb;
		$days = $wpdb->get_col( $wpdb->prepare( 'SELECT programme_day FROM ' . LLD_Members_Schema::table( 'result' ) . " WHERE member_id = %d AND context_type = 'challenge' AND context_id = %d AND result = 'done'", $member_id, $row_id ) );
		$out  = array();
		foreach ( (array) $days as $day ) {
			$out[ (int) $day ] = true;
		}
		return $out;
	}

	/**
	 * The challenge's weekly review (FR2-33): for each finished week, what was done of the actions available, with
	 * an encouraging close that never shames a missed week; the last week suggests what to do next. Draft wording
	 * for the owner.
	 *
	 * @param array<int,array<string,string>|null> $items    Daily actions.
	 * @param array<int,bool>                      $done     Days done.
	 * @param int                                  $current  Today's challenge day.
	 * @param bool                                 $finished Day 28 reached.
	 */
	private static function challenge_review( $items, $done, $current, $finished ) {
		$html = '';
		for ( $week = 1; $week <= 4; $week++ ) {
			if ( $current <= 7 * $week && ! ( 4 === $week && $finished ) ) {
				break;
			}
			$open = 0;
			$got  = 0;
			for ( $day = 7 * $week - 6; $day <= 7 * $week; $day++ ) {
				if ( ! empty( $items[ $day - 1 ] ) ) {
					++$open;
					$got += isset( $done[ $day ] ) ? 1 : 0;
				}
			}
			if ( 0 === $open ) {
				$close = 'There were no actions for you this week.';
			} elseif ( $got * 10 >= $open * 7 ) {
				$close = 'A strong week. Keep it going.';
			} elseif ( $got * 10 >= $open * 3 ) {
				$close = 'Every action counts. Keep going at your own pace.';
			} else {
				$close = 'A new week is a fresh start. Pick one action to try.';
			}
			$html .= '<section data-lld-review="' . $week . '"><h3>Week ' . $week . ' review</h3><p>' . $got . ' of ' . $open . ' actions done. ' . esc_html( $close ) . '</p>';
			if ( 4 === $week ) {
				$html .= '<p>What next: <a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">start a track</a>, or join next month\'s challenge.</p>';
			}
			$html .= '</section>';
		}
		return '' === $html ? '' : '<h2>Weekly review</h2>' . $html;
	}

	/**
	 * @param int $member_id    Member number.
	 * @param int $challenge_id Challenge id.
	 */
	private static function challenge_row( $member_id, $challenge_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'challenge' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE member_id = %d AND ( challenge_version = %s OR challenge_version LIKE %s ) ORDER BY id DESC LIMIT 1", $member_id, (string) (int) $challenge_id, $wpdb->esc_like( (int) $challenge_id . ':' ) . '%' ) );
	}

	/**
	 * The member's joined challenge that has not reached day 28 yet.
	 *
	 * @param int $member_id Member number.
	 */
	private static function open_challenge( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'challenge' ) . ' WHERE member_id = %d AND finished_on IS NULL ORDER BY id DESC LIMIT 1', $member_id ) );
	}

	/**
	 * The challenge a stored row points at, if it is still approved and safe for this member.
	 *
	 * @param int    $member_id Member number.
	 * @param string $stored    Stored challenge version.
	 * @return array<string,mixed>|null
	 */
	private static function challenge_by_version( $member_id, $stored ) {
		$snap = self::exact_version( 'lld_challenge', $stored );
		if ( ! $snap ) {
			return null;
		}
		return LLD_Members_Safety::allows( $member_id, $snap ) ? $snap : null;
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
	 * Activity totals (SCR-21): track activities, challenge days and workout sessions done.
	 *
	 * @param int $member_id Member number.
	 */
	private static function totals_html( $member_id ) {
		global $wpdb;
		$counts = array(
			'enrolment' => 0,
			'challenge' => 0,
			'workout'   => 0,
		);
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT context_type, COUNT(*) AS n FROM ' . LLD_Members_Schema::table( 'result' ) . " WHERE member_id = %d AND result = 'done' GROUP BY context_type", $member_id ) );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ (string) $row->context_type ] ) ) {
				$counts[ (string) $row->context_type ] = (int) $row->n;
			}
		}
		return '<h2>Activity</h2><p data-lld-totals>Track activities done: ' . $counts['enrolment'] . '. Challenge days done: ' . $counts['challenge'] . '. Workout sessions done: ' . $counts['workout'] . '.</p>';
	}

	/**
	 * Check-in charts across all months since joining (FR2-36, SCR-21).
	 *
	 * @param int $member_id Member number.
	 */
	private static function chart_html( $member_id ) {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT local_date, ' . implode( ', ', array_keys( self::CHART_MEASURES ) ) . ' FROM ' . LLD_Members_Schema::table( 'check_in' ) . ' WHERE member_id = %d ORDER BY local_date ASC, id ASC', $member_id ), ARRAY_A );
		$joined = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT joined_on FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		return self::progress_chart( is_array( $rows ) ? $rows : array(), $joined, LLD_Members_Programme::local_today( $member_id ) );
	}

	/**
	 * Every month from joining (or the first check-in, if earlier) to this month (or the last check-in, if later),
	 * with a check-in or not. Each measure the member has recorded gets a bar chart, and "Show as table" gives the
	 * same numbers: the last value recorded in each month.
	 *
	 * @param array<int,array<string,mixed>> $rows   Check-ins, oldest first, with local_date and measure columns.
	 * @param string                         $joined Join date.
	 * @param string                         $today  Local date.
	 */
	private static function progress_chart( $rows, $joined, $today ) {
		$months = array();
		$values = array();
		$seen   = array();
		foreach ( $rows as $row ) {
			$month = substr( (string) ( $row['local_date'] ?? '' ), 0, 7 );
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
				continue;
			}
			$seen[ $month ] = true;
			foreach ( array_keys( self::CHART_MEASURES ) as $column ) {
				if ( isset( $row[ $column ] ) && '' !== (string) $row[ $column ] && is_numeric( $row[ $column ] ) ) {
					$values[ $column ][ $month ] = 0 + $row[ $column ];
				}
			}
		}
		$ends = array_keys( $seen );
		foreach ( array( (string) $joined, (string) $today ) as $date ) {
			if ( preg_match( '/^\d{4}-\d{2}/', $date ) ) {
				$ends[] = substr( $date, 0, 7 );
			}
		}
		$html = '<section data-lld-chart><h2>Check-ins since joining</h2>';
		if ( ! $seen ) {
			$html .= '<p>No history yet</p>';
		}
		if ( $ends ) {
			sort( $ends );
			$cursor = new DateTimeImmutable( $ends[0] . '-01' );
			$stop   = $ends[ count( $ends ) - 1 ];
			for ( $i = 0; $i < 600 && $cursor->format( 'Y-m' ) <= $stop; $i++ ) {
				$months[] = $cursor->format( 'Y-m' );
				$cursor   = $cursor->modify( '+1 month' );
			}
		}
		foreach ( self::CHART_MEASURES as $column => $label ) {
			if ( empty( $values[ $column ] ) ) {
				continue;
			}
			$top   = max( $values[ $column ] );
			$top   = $top > 0 ? $top : 1;
			$width = max( 1, count( $months ) ) * 24;
			$bars  = '';
			$said  = array();
			foreach ( $months as $index => $month ) {
				if ( ! isset( $values[ $column ][ $month ] ) ) {
					continue;
				}
				$value  = $values[ $column ][ $month ];
				$height = (int) round( 100 * $value / $top );
				$bars  .= '<rect x="' . ( $index * 24 + 4 ) . '" y="' . ( 110 - $height ) . '" width="16" height="' . $height . '" fill="#157a42"><title>' . esc_html( $month . ': ' . $value ) . '</title></rect>';
				$said[] = $month . ' ' . $value;
			}
			$html .= '<figure data-lld-measure="' . esc_attr( $column ) . '"><figcaption>' . esc_html( $label ) . '</figcaption>';
			$html .= '<svg viewBox="0 0 ' . $width . ' 112" width="100%" height="112" role="img" aria-label="' . esc_attr( $label . ' by month: ' . implode( ', ', $said ) ) . '">' . $bars . '</svg></figure>';
		}
		$shown = array_keys( array_filter( $values ) );
		$html .= '<details data-lld-chart-table><summary>Show as table</summary><table><caption>Check-ins by month</caption><thead><tr><th scope="col">Month</th><th scope="col">Check-in</th>';
		foreach ( $shown as $column ) {
			$html .= '<th scope="col">' . esc_html( self::CHART_MEASURES[ $column ] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $months as $month ) {
			$html .= '<tr data-lld-month="' . esc_attr( $month ) . '"><th scope="row">' . esc_html( $month ) . '</th><td>' . ( isset( $seen[ $month ] ) ? 'Yes' : 'No check-in' ) . '</td>';
			foreach ( $shown as $column ) {
				$html .= '<td>' . esc_html( isset( $values[ $column ][ $month ] ) ? (string) $values[ $column ][ $month ] : '—' ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></details></section>';
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
		// "I ate the planned meal" is Premium only (FR2-37); a free member's post is taken as a description.
		$planned = ! empty( $_POST['lld_planned'] ) && LLD_Members_Billing::paid_open( $member_id );
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
		$old = $id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT photo_path FROM {$table} WHERE id = %d AND member_id = %d", $id, $member_id ) ) : '';
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		if ( '' !== $photo && '' !== $old && $old !== $photo ) {
			self::delete_photo_file( $member_id, $old );
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
		$view  = self::meal_view( $member_id );
		$today = LLD_Members_Programme::local_today( $member_id );
		$day   = (int) ( new DateTimeImmutable( $view['week']['starting'] ) )->diff( new DateTimeImmutable( $today ) )->days + 1;
		if ( isset( $view['days'][ $day ][ $meal ]['version_key'] ) ) {
			return (string) $view['days'][ $day ][ $meal ]['version_key'];
		}
		return '';
	}

	/**
	 * Remove a meal entry, and its photo file with it (requirements section 8.1).
	 *
	 * @param int $member_id Member number.
	 * @param int $meal_id   Meal row.
	 */
	private static function undo_meal( $member_id, $meal_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'diary_meal' );
		$photo = (string) $wpdb->get_var( $wpdb->prepare( "SELECT photo_path FROM {$table} WHERE id = %d AND member_id = %d", $meal_id, $member_id ) );
		$gone  = $wpdb->delete(
			$table,
			array(
				'id'        => $meal_id,
				'member_id' => $member_id,
			),
			array( '%d', '%d' )
		);
		if ( $gone && '' !== $photo ) {
			self::delete_photo_file( $member_id, $photo );
		}
	}

	/**
	 * Remove the photo from one meal entry and delete its file.
	 *
	 * @param int $member_id Member number.
	 * @param int $meal_id   Meal row.
	 */
	private static function remove_photo( $member_id, $meal_id ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'diary_meal' );
		$photo = (string) $wpdb->get_var( $wpdb->prepare( "SELECT photo_path FROM {$table} WHERE id = %d AND member_id = %d", $meal_id, $member_id ) );
		if ( '' === $photo ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET photo_path = NULL WHERE id = %d AND member_id = %d", $meal_id, $member_id ) );
		self::delete_photo_file( $member_id, $photo );
	}

	/**
	 * Delete one stored diary photo of this member from the protected store.
	 *
	 * @param int    $member_id Member number.
	 * @param string $relative  Path as stored on the meal row.
	 */
	private static function delete_photo_file( $member_id, $relative ) {
		$relative = (string) $relative;
		if ( 0 !== strpos( $relative, (int) $member_id . '/diary-' ) ) {
			return;
		}
		$file = LLD_Members_Photos::resolve( $relative );
		if ( $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Today's water, hunger and energy (Data Model C13 daily entries). Only the values given are changed.
	 *
	 * @param int $member_id Member number.
	 */
	private static function save_day( $member_id ) {
		$limits = array(
			'water_glasses' => array( 'lld_water', 0, 40 ),
			'hunger'        => array( 'lld_hunger', 1, 5 ),
			'energy'        => array( 'lld_energy', 1, 5 ),
		);
		$values = array();
		foreach ( $limits as $column => $limit ) {
			$value = self::posted_int( $limit[0] );
			if ( null !== $value && $value >= $limit[1] && $value <= $limit[2] ) {
				$values[ $column ] = $value;
			}
		}
		if ( ! $values ) {
			return;
		}
		global $wpdb;
		$table   = LLD_Members_Schema::table( 'diary_day' );
		$columns = implode( ', ', array_keys( $values ) );
		$holders = implode( ', ', array_fill( 0, count( $values ), '%d' ) );
		$updates = implode( ', ', array_map( static function ( $column ) { return $column . ' = VALUES(' . $column . ')'; }, array_keys( $values ) ) );
		$args    = array_merge( array( $member_id, LLD_Members_Programme::local_today( $member_id ) ), array_values( $values ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (member_id, local_date, {$columns}) VALUES (%d, %s, {$holders}) ON DUPLICATE KEY UPDATE {$updates}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns from the list above.
	}

	/**
	 * Today's water, hunger and energy, with a form while the diary is open.
	 *
	 * @param int  $member_id Member number.
	 * @param bool $writable  Diary open.
	 */
	private static function day_html( $member_id, $writable ) {
		global $wpdb;
		$today = LLD_Members_Programme::local_today( $member_id );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT water_glasses, hunger, energy FROM ' . LLD_Members_Schema::table( 'diary_day' ) . ' WHERE member_id = %d AND local_date = %s', $member_id, $today ) );
		$water = $row && null !== $row->water_glasses ? (string) (int) $row->water_glasses : '';
		$hunger = $row && null !== $row->hunger ? (string) (int) $row->hunger : '';
		$energy = $row && null !== $row->energy ? (string) (int) $row->energy : '';
		$html  = '<h2>Today</h2>';
		$html .= '<p data-lld-day-extras>Water: ' . esc_html( '' === $water ? 'not logged' : $water . ' glasses' ) . '. Hunger: ' . esc_html( '' === $hunger ? 'not logged' : $hunger . ' of 5' ) . '. Energy: ' . esc_html( '' === $energy ? 'not logged' : $energy . ' of 5' ) . '.</p>';
		if ( ! $writable ) {
			return $html;
		}
		$html .= '<form method="post" class="lld-form">';
		$html .= wp_nonce_field( 'lld_diary', 'lld_diary_nonce', true, false );
		$html .= '<input type="hidden" name="lld_day_save" value="1">';
		$html .= '<p><label for="lld_water">Glasses of water</label><input id="lld_water" name="lld_water" type="number" min="0" max="40" step="1" inputmode="numeric" value="' . esc_attr( $water ) . '"></p>';
		$html .= '<p><label for="lld_hunger">Hunger from 1 to 5</label><input id="lld_hunger" name="lld_hunger" type="number" min="1" max="5" step="1" inputmode="numeric" value="' . esc_attr( $hunger ) . '"></p>';
		$html .= '<p><label for="lld_energy">Energy from 1 to 5</label><input id="lld_energy" name="lld_energy" type="number" min="1" max="5" step="1" inputmode="numeric" value="' . esc_attr( $energy ) . '"></p>';
		$html .= lld_button( array( 'label' => 'Save today' ) );
		$html .= '</form>';
		return $html;
	}

	/**
	 * @param int  $member_id Member number.
	 * @param bool $writable  Diary open.
	 */
	private static function diary_list( $member_id, $writable = false ) {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'diary_meal' ) . ' WHERE member_id = %d ORDER BY local_date ASC, id ASC', $member_id ) );
		$html  = '';
		$nonce = $writable ? wp_create_nonce( 'lld_diary' ) : '';
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
			$html .= '<div class="lld-diary-entry" data-lld-entry="' . (int) $row->id . '"><p>' . esc_html( ucfirst( (string) $row->meal ) ) . ': ' . esc_html( $state );
			if ( '' !== $text ) {
				$html .= ' ' . esc_html( $text );
			}
			$photo = is_string( $row->photo_path ) && '' !== $row->photo_path;
			if ( $photo ) {
				$html .= ' <a href="' . esc_url( '/members/diary-photo/' . $member_id . '/?meal=' . (int) $row->id ) . '">Photo</a>';
			}
			$html .= '</p>';
			if ( $writable ) {
				$html .= '<form method="post" class="lld-inline"><input type="hidden" name="lld_diary_nonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="lld_undo" value="' . (int) $row->id . '"><button class="lld-button lld-button-secondary" type="submit">Undo</button></form>';
				if ( $photo ) {
					$html .= '<form method="post" class="lld-inline"><input type="hidden" name="lld_diary_nonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="lld_remove_photo" value="' . (int) $row->id . '"><button class="lld-button lld-button-secondary" type="submit">Remove photo</button></form>';
				}
			}
			$html .= '</div>';
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
		$html .= '<fieldset class="lld-options"><legend>Quality checks</legend><div class="lld-diary-checks">';
		$html .= '<label class="lld-tile-check"><input type="checkbox" name="lld_vegetables" value="1"> Vegetables</label>';
		$html .= '<label class="lld-tile-check"><input type="checkbox" name="lld_protein" value="1"> Protein source</label>';
		$html .= '<label class="lld-tile-check"><input type="checkbox" name="lld_sugary" value="1"> Sugary drink</label></div></fieldset>';
		if ( ! $hide ) {
			$html .= '<fieldset class="lld-options" id="lld_portion"><legend>Portion size</legend><div class="lld-choice">';
			foreach ( array( 'small' => 'Small', 'normal' => 'Normal', 'large' => 'Large' ) as $value => $label ) {
				$html .= '<label><input type="radio" name="lld_portion" value="' . $value . '"> ' . $label . '</label>';
			}
			$html .= '</div></fieldset>';
		}
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

	/**
	 * Safety net for ends the billing code did not report: ended subscriptions from the last year (the log is kept
	 * 1 year, Data Model F7), so an end whose record has been purged is not added again.
	 */
	private static function catch_ended() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT member_id, access_state, premium_ended_on FROM ' . LLD_Members_Schema::table( 'subscription' ) );
		if ( ! $rows ) {
			return;
		}
		$table = LLD_Members_Schema::table( 'fb_removal' );
		foreach ( $rows as $row ) {
			$state = strtolower( (string) $row->access_state );
			if ( ! in_array( $state, array( 'premium ended', 'refunded' ), true ) ) {
				continue;
			}
			$member = (int) $row->member_id;
			$today  = LLD_Members_Programme::local_today( $member );
			$on     = is_string( $row->premium_ended_on ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row->premium_ended_on ) ? $row->premium_ended_on : '';
			if ( '' === $on ) {
				// no end date recorded: note it once, today, unless this member is already on the log
				if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE member_number = %d", $member ) ) ) {
					continue;
				}
				$on = $today;
			}
			if ( $on < self::year_before( $today ) ) {
				continue;
			}
			self::note_ended( $member, $on );
		}
	}

	/**
	 * @param string $date Y-m-d.
	 */
	private static function year_before( $date ) {
		return ( new DateTimeImmutable( (string) $date ) )->modify( '-1 year' )->format( 'Y-m-d' );
	}

	/**
	 * The owner's removal list (FR2-35): sent when someone new is to be removed, and again every 7 days while
	 * anyone is still outstanding, each time listing everyone not yet removed.
	 */
	private static function mail_removals() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, member_number, premium_ended_on, late FROM ' . LLD_Members_Schema::table( 'fb_removal' ) . ' WHERE removed_on IS NULL ORDER BY id ASC' );
		if ( ! $rows ) {
			return;
		}
		$listed = get_option( 'lld_fb_sent' );
		$listed = is_array( $listed ) ? array_map( 'intval', $listed ) : array();
		$last   = (string) get_option( 'lld_fb_list_on', '' );
		$today  = current_time( 'Y-m-d' );
		$fresh  = false;
		$lines  = array();
		$ids    = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row->id;
			if ( ! in_array( (int) $row->id, $listed, true ) ) {
				$fresh = true;
			}
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', (int) $row->member_number ) );
			if ( '' !== $email ) {
				$lines[] = $email . ', Premium ended ' . (string) $row->premium_ended_on . ( (int) $row->late ? ', overdue' : '' );
			}
		}
		$due = '' === $last || $last <= ( new DateTimeImmutable( $today ) )->modify( '-7 days' )->format( 'Y-m-d' );
		if ( ! $lines || ( ! $fresh && ! $due ) ) {
			return;
		}
		$sent = wp_mail( (string) get_option( 'admin_email' ), 'People to remove from the group', "Remove these people from the private group, then record each removal under Payments, Group removals:\n" . implode( "\n", $lines ) );
		if ( $sent ) {
			update_option( 'lld_fb_sent', $ids, false );
			update_option( 'lld_fb_list_on', $today, false );
		}
	}

	/**
	 * Removal records are kept for 1 year after the removal was recorded (Data Model F7), then deleted. Records still
	 * outstanding are kept.
	 */
	private static function purge_removals() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'fb_removal' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE removed_on IS NOT NULL AND removed_on < %s", self::year_before( current_time( 'Y-m-d' ) ) ) );
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
	 * Record the removal for one Premium end that is still outstanding.
	 *
	 * @param int $removal_id Removal row.
	 */
	private static function record_removal( $removal_id ) {
		if ( $removal_id < 1 ) {
			return;
		}
		global $wpdb;
		$table  = LLD_Members_Schema::table( 'fb_removal' );
		$member = (int) $wpdb->get_var( $wpdb->prepare( "SELECT member_number FROM {$table} WHERE id = %d AND removed_on IS NULL", $removal_id ) );
		if ( $member < 1 ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET removed_on = %s, late = 0 WHERE id = %d AND removed_on IS NULL", LLD_Members_Programme::local_today( $member ), $removal_id ) );
	}
}
