<?php
/**
 * Goals, health profile, dashboard, suggestions and safety screening.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member personalisation screens.
 */
class LLD_Members_Personal {

	public static function boot() {
		add_action( 'lld_members_daily', array( __CLASS__, 'daily' ) );
	}

	/**
	 * Member gate used by programme screens.
	 */
	public static function require_page() {
		self::require_member();
	}

	/**
	 * @param string $title  Page title.
	 * @param string $main   Main HTML.
	 * @param int    $status HTTP status.
	 */
	public static function render( $title, $main, $status = 200, $wide = false ) {
		self::layout( $title, $main, $status, $wide );
	}

	/**
	 * @param string $screen Screen id.
	 */
	public static function screen( $screen ) {
		$map = array(
			'health'      => 'health',
			'check-in'    => 'check_in',
			'suggestions' => 'suggestions',
			'tracks'      => 'tracks',
			'screening'   => 'screening',
			'plan'        => 'plan',
			'meals'       => 'meals',
			'workout'     => 'workout',
			'challenge'   => 'challenge',
			'badges'      => 'badges',
			'diary'       => 'diary',
			'read'        => 'read_article',
		);
		if ( isset( $map[ $screen ] ) ) {
			self::{ $map[ $screen ] }();
		}
	}

	public static function goals() {
		LLD_Members_Account::require_confirmed_page();
		$member_id = LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Account::has_consent( $member_id, 'terms' ) ) {
			LLD_Members_Account::screen( 'agreements' );
			return;
		}
		$error = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_goal_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_goal_nonce'] ) ), 'lld_goal' ) ) {
			if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
				self::layout( 'Goals', '<h1>Goals</h1>' . lld_health_consent_notice() );
				return;
			}
			if ( ! empty( $_POST['lld_skip'] ) ) {
				wp_safe_redirect( home_url( '/members/home/' ) );
				exit;
			}
			$chosen = array();
			if ( isset( $_POST['lld_goals'] ) && is_array( $_POST['lld_goals'] ) ) {
				foreach ( wp_unslash( $_POST['lld_goals'] ) as $key ) {
					$key = sanitize_title( (string) $key );
					if ( '' !== $key ) {
						$chosen[] = $key;
					}
				}
			}
			$chosen = array_values( array_unique( $chosen ) );
			$allowed = array();
			foreach ( LLD_Members_Content::member_goals( $member_id ) as $goal ) {
				$allowed[] = $goal['key'];
			}
			if ( count( $chosen ) > 3 ) {
				$error = 'You can choose up to 3 goals';
			} else {
				self::replace_goals( $member_id, array_values( array_intersect( $chosen, $allowed ) ) );
				$text = isset( $_POST['lld_else'] ) ? trim( (string) wp_unslash( $_POST['lld_else'] ) ) : '';
				if ( '' !== $text ) {
					LLD_Members_Account::save_health( $member_id, array( 'kind' => 'something_else', 'text' => $text ) );
				}
				wp_safe_redirect( home_url( '/members/home/' ) );
				exit;
			}
		}
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			self::layout( 'Goals', '<h1>Goals</h1>' . lld_health_consent_notice() );
			return;
		}
		$main  = lld_steps( array( 'Account', 'Email', 'Agreements', 'Goals' ), 3 ) . '<h1>Choose up to 3 goals</h1>' . self::fail( $error );
		$main .= '<form method="post" class="lld-form lld-card lld-form-card">';
		$main .= wp_nonce_field( 'lld_goal', 'lld_goal_nonce', true, false );
		$main .= '<fieldset class="lld-options"><legend>Goals</legend>';
		foreach ( LLD_Members_Content::member_goals( $member_id ) as $goal ) {
			// The label keeps its original shape (input, then the title) so the safety-matrix check can read it.
			$main .= '<div class="lld-option"><label><input type="checkbox" data-lld-goal name="lld_goals[]" value="' . esc_attr( $goal['key'] ) . '"> ' . esc_html( $goal['title'] ) . '</label>';
			if ( ! empty( $goal['line'] ) && (string) $goal['line'] !== (string) $goal['title'] ) {
				$main .= '<small>' . esc_html( (string) $goal['line'] ) . '</small>';
			}
			$main .= '</div>';
		}
		$main .= '<p id="lld-goal-limit" class="lld-hint" hidden>You can choose up to 3</p></fieldset>';
		$main .= '<div class="lld-field"><label for="lld_else">Something else</label><textarea id="lld_else" name="lld_else" rows="3"></textarea></div>';
		$main .= '<div class="lld-row"><button class="lld-button" type="submit" name="lld_save" value="1">Save goals</button> ';
		$main .= '<button class="lld-button lld-button-secondary" type="submit" name="lld_skip" value="1">Skip for now</button></div>';
		$main .= '</form>';
		self::layout( 'Goals', $main );
	}

	public static function dashboard() {
		self::require_member();
		self::take_suggestion_post();
		$member_id = LLD_Members_Access::current_member_id();
		$row       = self::member_row( $member_id );
		$state     = LLD_Members_Access::access_state( $member_id );
		$label     = 'free' === $state ? 'Free' : $state;
		$name      = $row && '' !== (string) $row->display_name ? (string) $row->display_name : ( $row ? (string) $row->first_name : '' );
		$programme = class_exists( 'LLD_Members_Programme' );
		$today     = $programme ? LLD_Members_Programme::local_today( $member_id ) : gmdate( 'Y-m-d' );
		$date      = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new DateTimeZone( 'UTC' ) );
		// SCR-09, fixed order (FR2-07): greeting, today's activity, this week's plan, meals, diary, progress, suggestions, membership.
		$main  = '<h1>Today</h1><div class="lld-dash">';
		$main .= '<section data-lld-section="greeting" class="lld-card lld-greeting"><div class="lld-greeting-row">';
		if ( $row && $row->photo_path ) {
			$main .= '<img alt="" width="56" height="56" src="' . esc_url( home_url( '/members/photo/' . $member_id . '/' ) ) . '">';
		} else {
			$main .= '<svg class="lld-avatar" viewBox="0 0 64 64" width="56" height="56" role="img" aria-label="Profile photo"><circle cx="32" cy="24" r="12" fill="#cfe8db"/><circle cx="32" cy="78" r="28" fill="#cfe8db"/></svg>';
		}
		$main .= '<div><p class="lld-kicker">' . esc_html( $date ? $date->format( 'l j F' ) : $today ) . '</p><h2>Hello ' . esc_html( $name ) . '</h2></div></div>';
		$badge = $programme ? LLD_Members_Programme::track_badge( $member_id ) : '';
		if ( '' !== $badge ) {
			$main .= '<p class="lld-pill">' . esc_html( $badge ) . '</p>';
		}
		$main .= '</section>';
		$main .= '<section data-lld-section="today" class="lld-card lld-today"><h2>Activity</h2><p class="lld-kicker">Today\'s activity</p>';
		$main .= ( $programme ? LLD_Members_Programme::today_card_html( $member_id ) : '' ) . self::today_line( $member_id ) . '</section>';
		$main .= '<section data-lld-section="plan" class="lld-card"><h2>This week\'s plan</h2>' . ( $programme ? LLD_Members_Programme::dashboard_plan_html( $member_id ) : '' ) . '</section>';
		$main .= '<div class="lld-dash-pair">';
		$main .= '<section data-lld-section="meals" class="lld-card"><div class="lld-card-head"><h2>Meals</h2><p class="lld-hint">Today\'s meals</p></div>';
		$main .= self::meal_list( self::safe_titles( $member_id, array( 'lld_meal', 'lld_recipe' ), 3 ) );
		$main .= '<p><a href="' . esc_url( home_url( '/members/meals/' ) ) . '">View the week\'s meal plan and shopping list</a></p></section>';
		$main .= '<section data-lld-section="diary" class="lld-card"><div class="lld-card-head"><h2>Food diary</h2><p class="lld-hint">No numbers to count</p></div>';
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			$main .= LLD_Members_Premium::dashboard_diary_html( $member_id );
		}
		$main .= '<p><a href="' . esc_url( home_url( '/members/diary/' ) ) . '">Open food diary</a></p>';
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			$summary = LLD_Members_Premium::summary_line( $member_id );
			if ( '' !== $summary ) {
				$main .= '<p data-lld-summary>' . esc_html( $summary ) . '</p>';
			}
		}
		$main .= '</section></div>';
		$done   = $programme ? LLD_Members_Programme::done_count( $member_id ) : 0;
		$streak = $programme ? LLD_Members_Programme::streak( $member_id ) : 0;
		$main  .= '<section data-lld-section="progress" class="lld-card"><div class="lld-card-head"><h2>Progress</h2>';
		if ( $streak > 1 ) {
			$main .= '<p class="lld-streak">' . esc_html( $streak . '-day streak' ) . '</p>';
		}
		$main .= '</div>' . lld_progress( $done, 28 );
		$main .= $programme ? LLD_Members_Programme::week_labels_html( $member_id ) : '';
		$main .= '</section>';
		if ( 'free' === $state ) {
			$main .= '<aside class="lld-premium-adds"><p class="lld-kicker">Free member</p><h2>what Premium adds</h2><p>Meal plans, the full tracks and extra tools.</p>';
			$main .= lld_button( array( 'label' => 'See Premium', 'href' => home_url( '/pricing/' ) ) ) . '</aside>';
		}
		$main .= '<section data-lld-section="suggestions"><h2>Suggestions</h2>' . self::suggestion_block( $member_id ) . '<p><a href="' . esc_url( home_url( '/members/suggestions/' ) ) . '">See more</a></p></section>';
		$main .= '<section data-lld-section="membership" class="lld-card"><h2>Membership</h2><p class="lld-membership">Your membership is ' . esc_html( $label ) . '.</p>';
		if ( self::show_yearly( $member_id, $state ) ) {
			$main .= '<p>Switch to yearly</p>';
		}
		if ( class_exists( 'LLD_Members_Billing' ) ) {
			$active = LLD_Members_Programme::active( $member_id );
			if ( $active && ! LLD_Members_Billing::paid_open( $member_id ) && LLD_Members_Programme::programme_day( $member_id, (int) $active->id ) > 7 ) {
				$main .= '<p>Week 1 is complete. Premium opens the rest of this track.</p>';
			}
			$main .= LLD_Members_Billing::referral_paragraph( $member_id );
		}
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			$main .= LLD_Members_Premium::qa_paragraph( $member_id );
		}
		$main .= '</section></div>';
		self::layout( 'Today', $main, 200, true );
	}

	/**
	 * Today's meals as rows, each with its own "Swap" link into the meal plan (SCR-09 item 4).
	 *
	 * @param string[] $titles Meal titles.
	 */
	private static function meal_list( $titles ) {
		if ( ! $titles ) {
			return '';
		}
		$html = '<ul class="lld-meal-list">';
		foreach ( $titles as $title ) {
			$html .= '<li class="lld-meal-row"><span>' . esc_html( $title ) . '</span><a href="' . esc_url( home_url( '/members/meals/' ) ) . '">Swap<span class="lld-sr"> ' . esc_html( $title ) . '</span></a></li>';
		}
		return $html . '</ul>';
	}

	private static function health() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			self::layout( 'Health profile', '<h1>Health profile</h1>' . lld_health_consent_notice() );
			return;
		}
		$row      = self::member_row( $member_id );
		$profile  = self::profile_row( $member_id );
		$error    = '';
		$saved    = false;
		$warnings = array();
		// With the account hub on (P3-36 D4): units come from Account, the stored measurements are the values in the
		// boxes, and BMI and waist-to-height are tiles. With it off, the Stage 2 form and its "Stored ..." lines stay.
		$hub_units = class_exists( 'LLD_Members_Features' ) && LLD_Members_Features::enabled( 'account_hub', $member_id );
		$posted   = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_health_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_health_nonce'] ) ), 'lld_health' );
		if ( $posted ) {
			$fields = self::profile_from_post();
			if ( $hub_units ) {
				// A box still holding the stored value is not a change: it is not saved again, so a units round trip
				// cannot shift it and the Conditions rule (A7) asks only when something is really entered.
				$fields = self::without_unchanged( $fields, $profile );
			}
			if ( ! empty( $fields['reject'] ) ) {
				$error = (string) $fields['reject'];
			} elseif ( self::conditions_needed( $member_id, $profile, $fields ) ) {
				// P3-36 A7 (FR3-69 AS-06): with the Stage 3 matrix live, health details wait for the Conditions answer.
				$error = LLD_Members_Stage3_Safety::CONDITIONS_WORDING['conditions.required'];
			} else {
				$warnings = empty( $_POST['lld_hide_ok'] ) ? self::hiding_warnings( $profile, $fields ) : array();
				if ( ! $warnings ) {
					$result = LLD_Members_Account::save_health( $member_id, $fields );
					if ( is_wp_error( $result ) ) {
						$error = $result->get_error_message();
					} else {
						$saved = true;
						// With the account hub on, units live on Account only (P3-36 D4 / FR3-06 AS-05). Hub-off keeps the Stage 2 checkbox write.
						if ( ! $hub_units && ! empty( $_POST['lld_imperial'] ) ) {
							global $wpdb;
							$wpdb->update(
								LLD_Members_Schema::table( 'member' ),
								array( 'units' => 'imperial' ),
								array( 'id' => $member_id ),
								array( '%s' ),
								array( '%d' )
							);
						}
						$profile = self::profile_row( $member_id );
						$row     = self::member_row( $member_id );
						// P3-36 A7 (FR3-09 AS-08): sent here from goal setting, an answered profile goes back to the goal.
						if ( self::returning_to_goal( $member_id ) && 'missing' !== LLD_Members_Stage3_Safety::conditions_state_of( $profile ) ) {
							wp_safe_redirect( home_url( '/members/goal/' . ( isset( $_GET['role'] ) && 'extra' === $_GET['role'] ? '?role=extra' : '' ) ) );
							exit;
						}
					}
				}
			}
		}
		$keep     = $posted && ! $saved;
		$shown    = $hub_units ? $profile : null;
		$imperial = $posted ? ! empty( $_POST['lld_imperial'] ) : ( $row && 'imperial' === $row->units );
		if ( $hub_units ) {
			$imperial = $row && 'imperial' === $row->units;
		}
		$main = '<h1>Health profile</h1>' . self::fail( $error ) . ( $saved ? lld_state_saved() : '' );
		if ( ! $saved && self::returning_to_goal( $member_id ) && class_exists( 'LLD_Members_Goal_Journey' ) && 'missing' === LLD_Members_Stage3_Safety::conditions_state_of( $profile ) ) {
			$main .= lld_info( LLD_Members_Goal_Journey::note_texts()['general_wellbeing_path'] );
		}
		// P3-36 (safety matrix, eating disorder: "no BMI shown on the dashboard"): BMI and waist-to-height are left
		// out for a member the matrix hides them from, as tiles and as Stage 2 lines alike.
		$no_figures = self::body_figures_hidden( $member_id );
		$main .= $hub_units ? self::measure_tiles( $profile, $no_figures ) : self::stored_lines( $profile, $no_figures );
		$main .= self::calculator_lines( $member_id, $no_figures );
		$main .= '<form method="post" class="lld-form">';
		$main .= wp_nonce_field( 'lld_health', 'lld_health_nonce', true, false );
		if ( $warnings ) {
			foreach ( $warnings as $warning ) {
				$main .= lld_caution( $warning );
			}
			$main .= '<input type="hidden" name="lld_hide_ok" value="1">';
		}
		$sex   = $posted ? self::posted_text( 'lld_sex' ) : ( $profile ? (string) $profile->sex : '' );
		$main .= self::choice_field( 'lld_sex', 'Sex', self::sex_choices(), $sex );
		$main .= self::sex_pills( $sex );
		// With the account hub on, units are set once on Profile (FR3-06 AS-05). This page follows that setting and has no switch of its own.
		if ( $hub_units ) {
			if ( $imperial ) {
				$main .= '<input type="hidden" name="lld_imperial" value="1">';
			}
		} else {
			$main .= '<label class="lld-check"><input type="checkbox" name="lld_imperial" value="1" data-lld-imperial' . ( $imperial ? ' checked' : '' ) . '> Use imperial units</label>';
		}
		$main .= '<div data-lld-imperial-fields' . ( $imperial ? '' : ' hidden' ) . '>';
		$main .= self::number_field( 'lld_feet', 'Height, feet', 'lld_feet', $keep, self::imperial_parts( $shown, 'feet' ) );
		$main .= self::number_field( 'lld_inches', 'Height, inches', 'lld_inches', $keep, self::imperial_parts( $shown, 'inches' ) );
		$main .= self::number_field( 'lld_pounds', 'Weight, pounds', 'lld_pounds', $keep, self::imperial_parts( $shown, 'pounds' ) );
		$main .= self::number_field( 'lld_waist_in', 'Waist, inches', 'lld_waist_in', $keep, self::imperial_parts( $shown, 'waist_in' ) );
		$main .= '</div>';
		$main .= '<div data-lld-metric-fields' . ( $hub_units && $imperial ? ' hidden' : '' ) . '>';
		$main .= self::number_field( 'lld_height', 'Height in centimetres', 'lld_height', $keep, self::metric_part( $shown, 'height_cm' ) );
		$main .= self::number_field( 'lld_weight', 'Weight in kilograms', 'lld_weight', $keep, self::metric_part( $shown, 'weight_kg' ) );
		$main .= self::number_field( 'lld_waist', 'Waist in centimetres', 'lld_waist', $keep, self::metric_part( $shown, 'waist_cm' ) );
		$main .= '</div>';
		$main .= self::choice_field( 'lld_activity', 'Activity level', self::activity_choices(), $posted ? self::posted_text( 'lld_activity' ) : ( $profile ? (string) $profile->activity_level : '' ) );
		$main .= self::choice_field( 'lld_diet', 'Diet type', self::diet_choices(), $posted ? self::posted_text( 'lld_diet' ) : ( $profile ? (string) $profile->diet_type : '' ) );
		$main .= self::choice_field( 'lld_ethnicity', 'Ethnicity', self::ethnicity_choices(), $posted ? self::posted_text( 'lld_ethnicity' ) : ( $profile ? (string) $profile->ethnicity : '' ) );
		$main .= '<fieldset id="lld-conditions"><legend>Conditions</legend>';
		$selected = self::selected_conditions( $profile, $keep );
		$main    .= '<label class="lld-check"><input type="checkbox" name="lld_condition[]" value="none"' . ( in_array( 'none', $selected, true ) ? ' checked' : '' ) . '> None of these</label>';
		$main    .= '<label class="lld-check"><input type="checkbox" name="lld_condition[]" value="prefer-not"' . ( in_array( 'prefer-not', $selected, true ) ? ' checked' : '' ) . '> Prefer not to answer</label>';
		foreach ( self::condition_choices() as $key => $label ) {
			$main .= '<label class="lld-check"><input type="checkbox" name="lld_condition[]" value="' . esc_attr( $key ) . '"' . ( in_array( $key, $selected, true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
		}
		$main .= '</fieldset>';
		$main .= '<label class="lld-check"><input type="checkbox" name="lld_confirm" id="lld_confirm" value="1"' . ( $keep && ! empty( $_POST['lld_confirm'] ) ? ' checked' : '' ) . '> I\'ve checked this value</label>';
		$main .= lld_button( array( 'label' => 'Save' ) );
		$main .= '</form>';
		$main .= self::forms_assets();
		self::layout( 'Health profile', $main );
	}

	/**
	 * Stage 3 form stylesheet and script when WordPress URL helpers are available (P3-36 D4).
	 */
	private static function forms_assets() {
		if ( ! function_exists( 'plugins_url' ) || ! defined( 'LLD_MEMBERS_FILE' ) ) {
			return '';
		}
		$ver = defined( 'LLD_MEMBERS_VERSION' ) ? LLD_MEMBERS_VERSION : '1';
		$css = plugins_url( 'assets/stage3-forms.css', LLD_MEMBERS_FILE );
		$js  = plugins_url( 'assets/stage3-forms.js', LLD_MEMBERS_FILE );
		return '<link rel="stylesheet" href="' . esc_url( $css ) . '?ver=' . esc_attr( $ver ) . '">'
			. '<script src="' . esc_url( $js ) . '?ver=' . esc_attr( $ver ) . '"></script>';
	}

	/**
	 * Warnings for conditions this save adds that hide content (SCR-13). Empty when nothing new is hidden.
	 *
	 * @param object|null         $profile Stored profile.
	 * @param array<string,mixed> $fields  Fields about to be saved.
	 * @return string[]
	 */
	private static function hiding_warnings( $profile, $fields ) {
		if ( ! isset( $fields['conditions_answered'] ) || 'declared' !== $fields['conditions_answered'] ) {
			return array();
		}
		$new = json_decode( (string) $fields['conditions'], true );
		$old = array();
		if ( $profile && 'declared' === (string) $profile->conditions_answered ) {
			$old = json_decode( (string) $profile->conditions, true );
		}
		$new    = is_array( $new ) ? $new : array();
		$old    = is_array( $old ) ? $old : array();
		$labels = self::condition_choices();
		$map    = array(
			'type-2-diabetes'     => 'diabetes',
			'high-blood-pressure' => 'blood-pressure',
			'high-cholesterol'    => 'cholesterol',
		);
		$out = array();
		foreach ( array_diff( $new, $old ) as $key ) {
			$key  = (string) $key;
			$rule = LLD_Members_Safety::rule( isset( $map[ $key ] ) ? $map[ $key ] : $key );
			if ( 'pregnancy' === $key ) {
				$out[] = "Because you are pregnant, the Healthy Weight track and weight-loss content won't be shown";
				continue;
			}
			$parts = array();
			foreach ( $rule['tracks'] as $track ) {
				$parts[] = 'the ' . $track . ' track';
			}
			if ( $rule['tags'] ) {
				$parts[] = 'content tagged ' . implode( ', ', $rule['tags'] );
			}
			if ( $rule['badges'] ) {
				$parts[] = 'weight-loss badges';
			}
			if ( $parts && isset( $labels[ $key ] ) ) {
				$out[] = 'Because you chose ' . strtolower( $labels[ $key ] ) . ', ' . implode( ' and ', $parts ) . " won't be shown";
			}
		}
		return $out;
	}

	/**
	 * The latest saved result from each calculator, as the calculator showed it (C4).
	 *
	 * @param int $member_id Member number.
	 */
	private static function calculator_lines( $member_id, $no_figures = false ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT calculator, headline, unit, band FROM ' . LLD_Members_Schema::table( 'calculator_result' ) . ' WHERE member_id = %d ORDER BY id DESC', $member_id ) );
		if ( ! $rows ) {
			return '';
		}
		$names = array(
			'protein'  => 'Daily Protein Target',
			'diabetes' => 'Diabetes Risk Check',
		);
		$seen  = array();
		$html  = '';
		foreach ( $rows as $result ) {
			$which = (string) $result->calculator;
			// A saved Healthy Weight Check result's headline is a BMI: left out with the figures (safety matrix).
			if ( isset( $seen[ $which ] ) || ( $no_figures && 'healthy-weight' === $which ) ) {
				continue;
			}
			$seen[ $which ] = true;
			$unit           = 'result' === (string) $result->unit ? '' : ' ' . (string) $result->unit;
			$name           = isset( $names[ $which ] ) ? $names[ $which ] : ucfirst( $which );
			$html          .= '<p data-lld-calculator="' . esc_attr( $which ) . '">' . esc_html( $name . ': ' . (string) $result->headline . $unit . ', ' . (string) $result->band ) . '</p>';
		}
		return '' === $html ? '' : '<h2>Calculator results</h2>' . $html;
	}

	/**
	 * @param string               $id       Field id and name.
	 * @param string               $label    Label.
	 * @param array<string,string> $choices  Value to label.
	 * @param string               $selected Selected value.
	 */
	private static function choice_field( $id, $label, $choices, $selected ) {
		$html  = '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
		$html .= '<option value="">Leave blank</option>';
		foreach ( $choices as $value => $text ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		return $html . '</select></p>';
	}

	/**
	 * @param string $name Field name.
	 */
	private static function posted_text( $name ) {
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * @return array<string,string>
	 */
	private static function sex_choices() {
		return array(
			'female'            => 'Female',
			'male'              => 'Male',
			'prefer-not-to-say' => 'Prefer not to say',
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function activity_choices() {
		return array(
			'low'         => 'Low',
			'some'        => 'Some',
			'active'      => 'Active',
			'very-active' => 'Very active',
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function diet_choices() {
		return array(
			'everything' => 'Everything',
			'vegetarian' => 'Vegetarian',
			'vegan'      => 'Vegan',
			'other'      => 'Other',
		);
	}

	/**
	 * The same list as the diabetes calculator (C3).
	 *
	 * @return array<string,string>
	 */
	private static function ethnicity_choices() {
		return array(
			'WhiteNA'        => 'White / not stated',
			'Indian'         => 'Indian',
			'Pakistani'      => 'Pakistani',
			'Bangladeshi'    => 'Bangladeshi',
			'OtherAsian'     => 'Other Asian',
			'BlackCaribbean' => 'Black Caribbean',
			'BlackAfrican'   => 'Black African',
			'Chinese'        => 'Chinese',
			'Other'          => 'Other / mixed',
		);
	}

	private static function check_in() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			$status = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? 403 : 200;
			self::layout( 'Check-in', '<h1>Check-in</h1>' . lld_health_consent_notice(), $status );
			return;
		}
		if ( class_exists( 'LLD_Members_Billing' ) && LLD_Members_Billing::ended( $member_id ) && ! LLD_Members_Billing::diary_writable( $member_id ) ) {
			self::layout( 'Check-in', '<h1>Check-in</h1>' . lld_membership_lock( 'Check-ins after this week are part of Premium.' ) );
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_month_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_month_nonce'] ) ), 'lld_month' ) ) {
			LLD_Members_Premium::save_month( $member_id );
		}
		if ( class_exists( 'LLD_Members_Growth' ) ) {
			LLD_Members_Growth::save_survey( $member_id );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_measures_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_measures_nonce'] ) ), 'lld_measures' ) ) {
			$measured = LLD_Members_Programme::save_check_in( $member_id );
			if ( is_wp_error( $measured ) ) {
				self::layout( 'Check-in', '<h1>Check-in</h1><p>' . esc_html( $measured->get_error_message() ) . '</p>', 403 );
				return;
			}
		}
		$profile = self::profile_row( $member_id );
		$has     = $profile && null !== $profile->height_cm && '' !== (string) $profile->height_cm;
		$error   = '';
		$saved   = false;
		if ( ! $has && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_checkin_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_checkin_nonce'] ) ), 'lld_checkin' ) ) {
			$height = isset( $_POST['lld_height'] ) ? trim( (string) wp_unslash( $_POST['lld_height'] ) ) : '';
			$result = LLD_Members_Account::save_health(
				$member_id,
				array(
					'kind'      => 'profile',
					'height_cm' => '' === $height ? null : (float) $height,
					'confirmed' => ! empty( $_POST['lld_confirm'] ),
				)
			);
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$saved   = true;
				$profile = self::profile_row( $member_id );
				$has     = $profile && null !== $profile->height_cm && '' !== (string) $profile->height_cm;
			}
		}
		$main = '<h1>Check-in</h1>' . self::fail( $error ) . ( $saved ? lld_state_saved() : '' );
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			LLD_Members_Premium::maybe_finish( $member_id );
		}
		if ( $has ) {
			$main .= '<p>Height is saved.</p>';
		} else {
			$main .= '<form method="post" class="lld-form">';
			$main .= wp_nonce_field( 'lld_checkin', 'lld_checkin_nonce', true, false );
			$main .= self::number_field( 'lld_height', 'Height in centimetres', 'lld_height' );
			$main .= '<label class="lld-check"><input type="checkbox" name="lld_confirm" value="1"> I\'ve checked this value</label>';
			$main .= lld_button( array( 'label' => 'Save' ) );
			$main .= '</form>';
		}
		$main .= LLD_Members_Programme::check_in_html( $member_id );
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			$main .= LLD_Members_Premium::monthly_html( $member_id );
		}
		if ( class_exists( 'LLD_Members_Growth' ) ) {
			$main .= LLD_Members_Growth::survey_html( $member_id );
		}
		self::layout( 'Check-in', $main );
	}

	private static function screening() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( self::track_held( $member_id ) ) {
			self::layout( 'Before you start', self::emergency_html() );
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_screen_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_screen_nonce'] ) ), 'lld_screen' ) ) {
			if ( ! empty( $_POST['lld_start'] ) ) {
				if ( self::screen_passed( $member_id ) ) {
					self::session_mark( $member_id, 'lld_pass', null );
					self::enrol( $member_id, 'sleep-stress' );
					wp_safe_redirect( home_url( '/members/home/' ) );
					exit;
				}
			} else {
				$harm  = isset( $_POST['lld_harm'] ) ? sanitize_key( wp_unslash( $_POST['lld_harm'] ) ) : '';
				$panic = isset( $_POST['lld_panic'] ) ? sanitize_key( wp_unslash( $_POST['lld_panic'] ) ) : '';
				$mood  = isset( $_POST['lld_mood'] ) ? sanitize_key( wp_unslash( $_POST['lld_mood'] ) ) : '';
				if ( 'yes' === $harm ) {
					// Overrides every other answer, answered or not. The hold names the track only, never an answer.
					self::session_mark( $member_id, 'lld_pass', null );
					self::session_mark( $member_id, 'lld_hold', 'sleep-stress' );
					self::layout( 'Before you start', self::emergency_html() );
					return;
				}
				if ( ! in_array( $harm, array( 'yes', 'no' ), true ) || ! in_array( $panic, array( 'yes', 'no' ), true ) || ! in_array( $mood, array( 'yes', 'no' ), true ) ) {
					self::layout( 'Before you start', self::screen_form( 'Please answer all three questions to continue.' ) );
					return;
				}
				self::session_mark( $member_id, 'lld_pass', time() + 10 * MINUTE_IN_SECONDS );
				// The approved wording (screening-sleep-and-stress.md, Owner_Questions 93 = B): Response 2 and Response 3, both
				// together when both are yes (Response 4), then one "Continue". All three no: no extra message (Response 5).
				$main = '<h1>Before you start</h1>';
				if ( 'yes' === $panic ) {
					$main .= '<div class="lld-caution"><h2>Please talk to a doctor about panic attacks</h2>';
					$main .= '<p>Panic attacks can be very frightening. A doctor can check what\'s causing them and talk to you about treatments and support that can help. This track offers general sleep and stress habits and isn\'t a treatment for panic attacks.</p></div>';
				}
				if ( 'yes' === $mood ) {
					$main .= '<div class="lld-caution"><h2>Please talk to a doctor about your mood</h2>';
					$main .= '<p>Feeling low most days for 2 weeks or more is worth talking to a doctor about. They can help you find out what\'s going on and what support is available. This track offers general sleep and stress habits and isn\'t a treatment for low mood or depression.</p>';
					$main .= '<p>If things get worse, or you have thoughts of harming yourself, get help straight away: call your local emergency number or visit <a href="https://findahelpline.com">findahelpline.com</a>.</p></div>';
				}
				if ( 'yes' === $panic || 'yes' === $mood ) {
					$main .= '<p>You can still start the track if you\'d like to.</p>';
				}
				$main .= '<form method="post" class="lld-form">';
				$main .= wp_nonce_field( 'lld_screen', 'lld_screen_nonce', true, false );
				$main .= '<input type="hidden" name="lld_start" value="1">';
				$main .= lld_button( array( 'label' => 'Continue' ) );
				$main .= '</form>';
				self::layout( 'Before you start', $main );
				return;
			}
		}
		self::layout( 'Before you start', self::screen_form( '' ) );
	}

	/**
	 * The self-harm response (FR2-38, SCR-08). Never shortened, hidden or skippable. The approved wording of
	 * screening-sleep-and-stress.md Response 1 (Owner_Questions 93 = B, 3 Nov 2026), all shown at once in the red banner;
	 * the helpline button and "Back to my dashboard" are the only actions, and no track is offered.
	 */
	private static function emergency_html() {
		$main  = '<h1>Before you start</h1>';
		$main .= '<div class="lld-safety" role="alert"><h2>Please get support now</h2>';
		$main .= '<p><strong>If you are in danger now, call your local emergency number</strong>, for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, or 000 in Australia.</p>';
		$main .= '<p>To find a free, confidential helpline in your country, by phone, text or chat:</p>';
		$main .= '<p>' . lld_button( array( 'label' => 'Find support in your country', 'href' => 'https://findahelpline.com' ) ) . '</p>';
		$main .= '<p>Thank you for telling us. You don\'t have to cope with these thoughts alone, and talking to someone can help. A doctor can also help you find the right support.</p>';
		$main .= '<p>We won\'t start Sleep and Stress today. Your account and everything else on Lifeline Daily are still here for you.</p>';
		$main .= '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Back to my dashboard</a></p></div>';
		return $main;
	}

	/**
	 * True when this sign-in session answered yes to self-harm: Sleep and Stress is not offered again in it.
	 *
	 * @param int $member_id Member number.
	 */
	public static function track_held( $member_id ) {
		return 'sleep-stress' === self::session_read( $member_id, 'lld_hold' );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function screen_passed( $member_id ) {
		if ( self::track_held( $member_id ) ) {
			return false;
		}
		$until = (int) self::session_read( $member_id, 'lld_pass' );
		return $until > time();
	}

	/**
	 * Mark the current sign-in session. Signing out ends the session and the mark with it.
	 *
	 * @param int        $member_id Member number.
	 * @param string     $key       lld_hold or lld_pass.
	 * @param mixed|null $value     Value, or null to clear.
	 */
	private static function session_mark( $member_id, $key, $value ) {
		$user  = get_current_user_id();
		$token = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		if ( $user > 0 && '' !== $token && class_exists( 'WP_Session_Tokens' ) ) {
			$manager = WP_Session_Tokens::get_instance( $user );
			$session = $manager->get( $token );
			if ( is_array( $session ) ) {
				if ( null === $value ) {
					unset( $session[ $key ] );
				} else {
					$session[ $key ] = $value;
				}
				$manager->update( $token, $session );
				return;
			}
		}
		$name = $key . '_' . md5( (int) $member_id . '|' . $token );
		if ( null === $value ) {
			delete_transient( $name );
		} else {
			set_transient( $name, $value, DAY_IN_SECONDS );
		}
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       lld_hold or lld_pass.
	 * @return mixed
	 */
	private static function session_read( $member_id, $key ) {
		$user  = get_current_user_id();
		$token = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		if ( $user > 0 && '' !== $token && class_exists( 'WP_Session_Tokens' ) ) {
			$session = WP_Session_Tokens::get_instance( $user )->get( $token );
			if ( is_array( $session ) ) {
				return isset( $session[ $key ] ) ? $session[ $key ] : null;
			}
		}
		$value = get_transient( $key . '_' . md5( (int) $member_id . '|' . $token ) );
		return false === $value ? null : $value;
	}

	/**
	 * Everything kept about a member's screening, for the FR2-38-AS-04 check: the marks on each sign-in
	 * session, any screening options or transients, and the member's rows in every lld_ table.
	 *
	 * @param int $member_id Member number.
	 * @return array<string,mixed>
	 */
	public static function screening_state( $member_id ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$user      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
		$sessions  = array();
		if ( $user > 0 && class_exists( 'WP_Session_Tokens' ) ) {
			foreach ( WP_Session_Tokens::get_instance( $user )->get_all() as $session ) {
				$marks = array();
				foreach ( (array) $session as $key => $value ) {
					if ( 0 === strpos( (string) $key, 'lld' ) ) {
						$marks[ $key ] = $value;
					}
				}
				$sessions[] = $marks;
			}
		}
		$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%lld\\_hold%' OR option_name LIKE '%lld\\_pass%' OR option_name LIKE '%lld\\_screen%'" );
		$meta    = $user > 0 ? $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user, $wpdb->esc_like( 'lld' ) . '%' ) ) : array();
		$rows    = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lld_' ) . '%' ) ) as $table ) {
			if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'member_id' ) );
			if ( $column ) {
				$rows[ $table ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE member_id = %d", $member_id ) );
			}
		}
		return array(
			'sessions' => $sessions,
			'options'  => array_values( array_map( 'strval', $options ) ),
			'usermeta' => array_values( array_map( 'strval', $meta ) ),
			'rows'     => $rows,
		);
	}

	private static function tracks() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		$error     = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_track_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_track_nonce'] ) ), 'lld_track' ) ) {
			$key   = isset( $_POST['lld_track'] ) ? sanitize_title( wp_unslash( $_POST['lld_track'] ) ) : '';
			$start = isset( $_POST['lld_start'] ) && 'tomorrow' === sanitize_key( wp_unslash( $_POST['lld_start'] ) ) ? 'tomorrow' : 'today';
			if ( 'sleep-stress' === $key && self::track_held( $member_id ) ) {
				wp_safe_redirect( home_url( '/members/screening/?track=sleep-stress' ) );
				exit;
			}
			if ( 'sleep-stress' === $key ) {
				wp_safe_redirect( home_url( '/members/screening/?track=sleep-stress' ) );
				exit;
			}
			$known = self::track_list();
			$title = isset( $known[ $key ] ) ? $known[ $key ] : $key;
			$real  = isset( $known[ $key ] ) || ( class_exists( 'LLD_Members_Programme' ) && LLD_Members_Programme::track_id( $key ) > 0 );
			if ( $real && LLD_Members_Safety::track_allowed( $member_id, $title ) ) {
				$started = self::enrol( $member_id, $key, $start );
				if ( true === $started ) {
					wp_safe_redirect( home_url( '/members/home/' ) );
					exit;
				}
				$error = is_wp_error( $started ) ? $started->get_error_message() : '';
			}
		}
		$main = '<h1>Start a track</h1>' . self::fail( $error ) . LLD_Members_Programme::notices_html( $member_id );
		foreach ( self::track_list() as $key => $title ) {
			if ( ! LLD_Members_Safety::track_allowed( $member_id, $title ) ) {
				$main .= LLD_Members_Safety::not_suitable( $member_id, $title . ' is not suitable for you.', home_url( '/members/home/' ), 'Back to today' );
				continue;
			}
			if ( 'sleep-stress' === $key && self::track_held( $member_id ) ) {
				continue;
			}
			$main .= LLD_Members_Programme::track_choice_html( $member_id, $key, $title );
		}
		self::layout( 'Tracks', $main );
	}

	private static function suggestions() {
		self::require_member();
		self::take_suggestion_post();
		$member_id = LLD_Members_Access::current_member_id();
		$main      = '<h1>Suggestions</h1>' . self::suggestion_block( $member_id );
		self::layout( 'Suggestions', $main );
	}

	private static function plan() {
		LLD_Members_Programme::plan_screen();
	}

	private static function meals() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( class_exists( 'LLD_Members_Billing' ) && ! LLD_Members_Billing::paid_open( $member_id ) ) {
			self::layout( 'Meal plan', '<h1>Meal plan</h1>' . lld_membership_lock( 'Meal plans are part of Premium.' ) );
			return;
		}
		LLD_Members_Premium::meals();
	}

	private static function workout() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( class_exists( 'LLD_Members_Billing' ) && ! LLD_Members_Billing::paid_open( $member_id ) ) {
			self::layout( 'Workout', '<h1>Workout</h1>' . lld_membership_lock( 'Workouts are part of Premium.' ) );
			return;
		}
		LLD_Members_Premium::workout();
	}

	private static function challenge() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( class_exists( 'LLD_Members_Billing' ) && ! LLD_Members_Billing::paid_open( $member_id ) ) {
			self::layout( 'Challenge', '<h1>Challenge</h1>' . lld_membership_lock( 'Challenges are part of Premium.' ) );
			return;
		}
		LLD_Members_Premium::challenge();
	}

	private static function badges() {
		self::require_member();
		LLD_Members_Premium::badges();
	}

	private static function diary() {
		self::require_member();
		LLD_Members_Premium::diary();
	}

	/**
	 * @param string   $heading Heading.
	 * @param string   $line    Lock sentence.
	 * @param string[] $types   Content types when Premium is open.
	 */
	private static function premium_surface( $heading, $line, $types ) {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		if ( class_exists( 'LLD_Members_Billing' ) && ! LLD_Members_Billing::paid_open( $member_id ) ) {
			self::layout( $heading, '<h1>' . esc_html( $heading ) . '</h1>' . lld_membership_lock( $line ) );
			return;
		}
		self::surface( $heading, $types );
	}

	/**
	 * @param string   $heading Heading.
	 * @param string[] $types   Post types.
	 */
	private static function surface( $heading, $types ) {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		$titles    = self::safe_titles( $member_id, $types );
		$main      = '<h1>' . esc_html( $heading ) . '</h1>';
		$main     .= $titles ? self::title_list( $titles, 0 ) : LLD_Members_Safety::empty_html( $member_id );
		self::layout( $heading, $main );
	}

	private static function read_article() {
		self::require_member();
		$member_id = LLD_Members_Access::current_member_id();
		$item_id   = (int) get_query_var( 'lld_item' );
		$post      = get_post( $item_id );
		$reviewed  = $post && 'post' === $post->post_type && 'publish' === $post->post_status && 'approved' === (string) get_post_meta( $item_id, 'lld_review_status', true );
		if ( ! $reviewed ) {
			self::layout( "This page isn't available", lld_state_not_allowed(), 404 );
			return;
		}
		$tags = wp_get_object_terms( $item_id, 'lld_tag', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}
		$item = array(
			'type'  => 'post',
			'title' => $post->post_title,
			'tags'  => $tags,
		);
		if ( ! LLD_Members_Safety::allows( $member_id, $item ) ) {
			self::unsuitable( $member_id );
			return;
		}
		if ( get_post_meta( $item_id, '_lld_premium', true ) && ! ( class_exists( 'LLD_Members_Billing' ) ? LLD_Members_Billing::paid_open( $member_id ) : 'free' !== LLD_Members_Access::access_state( $member_id ) ) ) {
			self::layout( 'Premium', lld_membership_lock( 'Premium includes this article.' ) );
			return;
		}
		self::remember_open( $member_id, 'p' . $item_id );
		$main  = '<h1>' . esc_html( $post->post_title ) . '</h1>';
		$main .= LLD_Members_Safety::cautions_html( $member_id, $item );
		$main .= '<div class="lld-content">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>';
		self::layout( $post->post_title, $main );
	}

	/**
	 * Profile reminder, and the owner notes when a member clock lands on the report date.
	 */
	public static function daily() {
		self::reminders();
		self::owner_reports();
		self::notify_live_goals();
		if ( class_exists( 'LLD_Members_Billing' ) ) {
			LLD_Members_Billing::daily();
		}
		if ( class_exists( 'LLD_Members_Premium' ) ) {
			LLD_Members_Premium::daily();
		}
		if ( class_exists( 'LLD_Members_Programme' ) ) {
			LLD_Members_Programme::dispatch_mail();
		}
	}

	/**
	 * Email members who asked for a goal that is now shown, and who agreed to emails.
	 */
	public static function notify_live_goals() {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'something_else' );
		$rows  = $wpdb->get_results( "SELECT id, member_id, text_ciphertext FROM {$table} WHERE told_when_live IS NULL" );
		if ( ! $rows ) {
			return;
		}
		$told = array();
		foreach ( LLD_Members_Content::member_goals() as $goal ) {
			foreach ( $rows as $row ) {
				if ( isset( $told[ (int) $row->id ] ) || ! is_string( $row->text_ciphertext ) || '' === $row->text_ciphertext ) {
					continue;
				}
				try {
					$text = LLD_Members_Crypto::decrypt( $row->text_ciphertext, 'something_else:' . $row->id );
				} catch ( RuntimeException $failed ) {
					continue;
				}
				if ( ! is_string( $text ) || false === stripos( $text, (string) $goal['title'] ) ) {
					continue;
				}
				if ( ! LLD_Members_Account::has_consent( (int) $row->member_id, 'marketing' ) ) {
					continue;
				}
				$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $row->member_id ) );
				if ( is_email( $email ) ) {
					LLD_Members_Mail::send_goal_live( $email, (string) $goal['title'] );
				}
				$told[ (int) $row->id ] = true;
				$wpdb->update(
					$table,
					array( 'told_when_live' => LLD_Members_Clock::now( (int) $row->member_id )->format( 'Y-m-d' ) ),
					array( 'id' => (int) $row->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	private static function reminders() {
		global $wpdb;
		$members = $wpdb->get_results( 'SELECT id, email, first_name, joined_on FROM ' . LLD_Members_Schema::table( 'member' ) );
		foreach ( $members as $member ) {
			$member_id = (int) $member->id;
			$joined    = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $member->joined_on, new DateTimeZone( 'UTC' ) );
			if ( ! $joined ) {
				continue;
			}
			// EM-14 and the health profile row are health data: never without health consent, and never again
			// after the member's health data or account was erased (the reminder is sent once only).
			if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) || self::health_erased( $member_id ) ) {
				continue;
			}
			$today   = LLD_Members_Clock::now( $member_id )->format( 'Y-m-d' );
			$due     = $joined->modify( '+30 days' )->format( 'Y-m-d' );
			$profile = self::profile_row( $member_id );
			if ( $today < $due || ( $profile && $profile->reminder_sent_on ) || self::profile_complete( $profile ) ) {
				continue;
			}
			if ( is_email( (string) $member->email ) ) {
				LLD_Members_Mail::send_profile_reminder( (string) $member->email, (string) $member->first_name );
			}
			if ( $profile ) {
				$wpdb->update(
					LLD_Members_Schema::table( 'health_profile' ),
					array( 'reminder_sent_on' => $today ),
					array( 'member_id' => $member_id ),
					array( '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					LLD_Members_Schema::table( 'health_profile' ),
					array(
						'member_id'            => $member_id,
						'conditions_answered'  => 'not_answered',
						'updated_on'           => $today,
						'reminder_sent_on'     => $today,
					),
					array( '%d', '%s', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function health_erased( $member_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . LLD_Members_Schema::table( 'erasure' ) . " WHERE member_number = %d AND what_erased IN ('health', 'account') LIMIT 1",
				$member_id
			)
		);
	}

	private static function owner_reports() {
		foreach ( self::report_dates() as $date ) {
			$when = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, new DateTimeZone( 'UTC' ) );
			if ( ! $when ) {
				continue;
			}
			if ( '1' === $when->format( 'N' ) && ! get_option( 'lld_gap_note_' . $date ) ) {
				$lines = array( 'Goals short of content:' );
				$gaps  = LLD_Members_Content::goal_gaps();
				$lines = array_merge( $lines, $gaps ? $gaps : array( 'None this week.' ) );
				LLD_Members_Mail::send_owner_note( 'Content gaps', implode( "\n", $lines ) );
				update_option( 'lld_gap_note_' . $date, '1', false );
			}
			if ( '01' === $when->format( 'd' ) && ! get_option( 'lld_theme_note_' . $when->format( 'Y-m' ) ) ) {
				global $wpdb;
				self::group_something_else();
				$rows  = $wpdb->get_results( 'SELECT theme, COUNT(*) AS n FROM ' . LLD_Members_Schema::table( 'something_else' ) . " WHERE theme IS NOT NULL AND theme <> '' GROUP BY theme ORDER BY theme ASC" );
				$lines = array( 'Something else themes:' );
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$lines[] = (string) $row->theme . ': ' . (int) $row->n;
					}
				} else {
					$lines[] = 'None this month.';
				}
				LLD_Members_Mail::send_owner_note( 'Something else themes', implode( "\n", $lines ) );
				update_option( 'lld_theme_note_' . $when->format( 'Y-m' ), '1', false );
			}
		}
	}

	/**
	 * The monthly grouping (C2 theme, F5): each request is given a theme, and only themes and counts leave this
	 * step. The text is decrypted here and never stored or sent in plain form.
	 *
	 * Until the AI grouping in decision D34 exists, a theme is the owner's own wording: the first approved goal
	 * name, or goal tag, that the request mentions. Anything else is counted as "Other".
	 */
	private static function group_something_else() {
		global $wpdb;
		$table  = LLD_Members_Schema::table( 'something_else' );
		$themes = array();
		foreach ( LLD_Members_Content::approved_goals() as $snap ) {
			$themes[] = trim( (string) $snap['title'] );
		}
		$tags = get_terms(
			array(
				'taxonomy'   => 'lld_tag',
				'hide_empty' => false,
				'meta_key'   => 'lld_kind',
				'meta_value' => 'goal',
				'fields'     => 'names',
			)
		);
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$themes[] = trim( str_replace( '-', ' ', (string) $tag ) );
			}
		}
		$themes = array_values( array_unique( array_filter( $themes ) ) );
		// Longer names first, so "Blood Sugar Balance" wins over "blood sugar".
		usort(
			$themes,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$rows = $wpdb->get_results( "SELECT id, text_ciphertext FROM {$table}" );
		foreach ( (array) $rows as $row ) {
			$theme = 'Other';
			try {
				$text = LLD_Members_Crypto::decrypt( (string) $row->text_ciphertext, 'something_else:' . (int) $row->id );
			} catch ( RuntimeException $failed ) {
				$text = '';
			}
			if ( is_string( $text ) && '' !== $text ) {
				foreach ( $themes as $name ) {
					if ( preg_match( '/(?<![\p{L}\p{N}])' . str_replace( ' ', '[\s-]+', preg_quote( $name, '/' ) ) . '(?![\p{L}\p{N}])/iu', $text ) ) {
						$theme = $name;
						break;
					}
				}
			}
			$wpdb->update( $table, array( 'theme' => $theme ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		}
	}

	/**
	 * @return string[]
	 */
	private static function report_dates() {
		$dates = array( gmdate( 'Y-m-d' ) );
		if ( ! lld_members_test_clock_allowed() ) {
			return $dates;
		}
		global $wpdb;
		$rows = $wpdb->get_col( 'SELECT frozen_at FROM ' . LLD_Members_Schema::table( 'test_clock' ) );
		foreach ( $rows as $raw ) {
			if ( is_string( $raw ) && strlen( $raw ) >= 10 ) {
				$dates[] = substr( $raw, 0, 10 );
			}
		}
		return array_values( array_unique( $dates ) );
	}

	/**
	 * @param int      $member_id Member number.
	 * @param string[] $keys      Goal keys, at most three.
	 */
	private static function replace_goals( $member_id, $keys ) {
		global $wpdb;
		$table = LLD_Members_Schema::table( 'goal_choice' );
		$wpdb->delete( $table, array( 'member_id' => $member_id ), array( '%d' ) );
		$order = 1;
		foreach ( $keys as $key ) {
			LLD_Members_Account::save_health(
				$member_id,
				array(
					'kind'       => 'goal',
					'goal_key'   => $key,
					'sort_order' => $order,
				)
			);
			++$order;
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function profile_from_post() {
		$imperial = ! empty( $_POST['lld_imperial'] );
		$fields   = array(
			'kind'      => 'profile',
			'confirmed' => ! empty( $_POST['lld_confirm'] ),
		);
		$choices  = array(
			'sex'            => array( 'lld_sex', self::sex_choices() ),
			'activity_level' => array( 'lld_activity', self::activity_choices() ),
			'diet_type'      => array( 'lld_diet', self::diet_choices() ),
			'ethnicity'      => array( 'lld_ethnicity', self::ethnicity_choices() ),
		);
		foreach ( $choices as $column => $choice ) {
			if ( ! isset( $_POST[ $choice[0] ] ) ) {
				continue;
			}
			$value             = self::posted_text( $choice[0] );
			$fields[ $column ] = isset( $choice[1][ $value ] ) ? $value : '';
		}
		if ( $imperial ) {
			$feet   = self::posted_number( 'lld_feet' );
			$inches = self::posted_number( 'lld_inches' );
			$pounds = self::posted_number( 'lld_pounds' );
			$waist  = self::posted_number( 'lld_waist_in' );
			if ( null !== $feet || null !== $inches ) {
				$fields['height_cm'] = round( ( (float) $feet * 30.48 ) + ( (float) $inches * 2.54 ), 1 );
			}
			if ( null !== $pounds ) {
				$fields['weight_kg'] = round( (float) $pounds * 0.45359237, 1 );
			}
			if ( null !== $waist ) {
				$fields['waist_cm'] = round( (float) $waist * 2.54, 1 );
			}
		} else {
			if ( null !== self::posted_number( 'lld_height' ) ) {
				$fields['height_cm'] = self::posted_number( 'lld_height' );
			}
			if ( null !== self::posted_number( 'lld_weight' ) ) {
				$fields['weight_kg'] = self::posted_number( 'lld_weight' );
			}
			if ( null !== self::posted_number( 'lld_waist' ) ) {
				$fields['waist_cm'] = self::posted_number( 'lld_waist' );
			}
		}
		$chosen = array();
		if ( isset( $_POST['lld_condition'] ) && is_array( $_POST['lld_condition'] ) ) {
			foreach ( wp_unslash( $_POST['lld_condition'] ) as $key ) {
				$key = sanitize_key( (string) $key );
				if ( 'none' === $key || 'prefer-not' === $key || isset( self::condition_choices()[ $key ] ) ) {
					$chosen[] = $key;
				}
			}
		}
		$chosen = array_values( array_unique( $chosen ) );
		if ( in_array( 'none', $chosen, true ) && count( $chosen ) > 1 ) {
			$fields['reject'] = 'None cannot be combined with a condition.';
		} elseif ( in_array( 'prefer-not', $chosen, true ) && count( $chosen ) > 1 ) {
			$fields['reject'] = 'Prefer not to answer cannot be combined with a condition.';
		} elseif ( in_array( 'prefer-not', $chosen, true ) ) {
			// Still not_answered, so both matrices treat the conditions as unknown; the list keeps the answer itself
			// so the form shows it again and it counts as answered (P3-36 A7, FR3-69 AS-06).
			$fields['conditions_answered'] = 'not_answered';
			$fields['conditions']          = wp_json_encode( array( 'prefer-not' ) );
		} elseif ( in_array( 'none', $chosen, true ) ) {
			$fields['conditions_answered'] = 'none';
			$fields['conditions']          = '';
		} elseif ( $chosen ) {
			$fields['conditions_answered'] = 'declared';
			$fields['conditions']          = wp_json_encode( $chosen );
		}
		return $fields;
	}

	/**
	 * True when this save holds health details but the Conditions question is unanswered, both in the post and in
	 * the stored profile, for a member the Stage 3 safety matrix is live for (P3-36 A7, FR3-69 AS-06). "None of
	 * these" and "Prefer not to answer" both count. Stage 2 members keep Stage 2's behaviour (FR2-25 AS-01, AS-03).
	 *
	 * @param int                 $member_id Member number.
	 * @param object|null         $profile   Stored profile row.
	 * @param array<string,mixed> $fields    profile_from_post().
	 */
	private static function conditions_needed( $member_id, $profile, array $fields ) {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) || ! method_exists( 'LLD_Members_Stage3_Safety', 'conditions_state_of' ) || ! LLD_Members_Stage3_Safety::active( $member_id, 'health_logging' ) ) {
			return false;
		}
		if ( isset( $fields['conditions_answered'] ) || 'missing' !== LLD_Members_Stage3_Safety::conditions_state_of( $profile ) ) {
			return false;
		}
		foreach ( array( 'sex', 'height_cm', 'weight_kg', 'waist_cm', 'activity_level', 'diet_type', 'ethnicity' ) as $key ) {
			if ( isset( $fields[ $key ] ) && '' !== (string) $fields[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the member came here from goal setting (?return=goal) and goal setting is on for them.
	 *
	 * @param int $member_id Member number.
	 */
	private static function returning_to_goal( $member_id ) {
		return isset( $_GET['return'] ) && 'goal' === $_GET['return'] && class_exists( 'LLD_Members_Features' ) && LLD_Members_Features::enabled( 'goals', $member_id ) && method_exists( 'LLD_Members_Stage3_Safety', 'conditions_state_of' );
	}

	/**
	 * @param string $name Field name.
	 */
	private static function posted_number( $name ) {
		if ( ! isset( $_POST[ $name ] ) ) {
			return null;
		}
		$raw = trim( (string) wp_unslash( $_POST[ $name ] ) );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * P3-36: whether the Stage 3 safety matrix hides BMI from this member (eating-disorder row, health_logging:
	 * dashboard_bmi). Waist-to-height goes with it: it is the same kind of body-size figure. With the matrix off for
	 * the member, nothing changes and Stage 2 stands.
	 *
	 * @param int $member_id Member number.
	 */
	public static function body_figures_hidden( $member_id ) {
		if ( ! class_exists( 'LLD_Members_Stage3_Safety' ) ) {
			return false;
		}
		$a = LLD_Members_Stage3_Safety::applies( (int) $member_id, 'health_logging' );
		return ! empty( $a['active'] ) && in_array( 'dashboard_bmi', $a['hidden'], true );
	}

	/**
	 * @param object|null $profile Profile row.
	 * @param bool        $no_figures Leave out BMI and waist-to-height (safety matrix).
	 */
	private static function stored_lines( $profile, $no_figures = false ) {
		if ( ! $profile || null === $profile->height_cm || '' === (string) $profile->height_cm ) {
			return '';
		}
		$html = '<p>Stored height: ' . esc_html( self::one_decimal( $profile->height_cm ) ) . ' cm</p>';
		if ( null !== $profile->weight_kg && '' !== (string) $profile->weight_kg ) {
			$html .= '<p>Stored weight: ' . esc_html( self::one_decimal( $profile->weight_kg ) ) . ' kg</p>';
			$metres = (float) $profile->height_cm / 100;
			if ( $metres > 0 && ! $no_figures ) {
				$html .= '<p>BMI: ' . esc_html( self::one_decimal( (float) $profile->weight_kg / ( $metres * $metres ) ) ) . '</p>';
			}
		}
		if ( null !== $profile->waist_cm && '' !== (string) $profile->waist_cm ) {
			$html .= '<p>Stored waist: ' . esc_html( self::one_decimal( $profile->waist_cm ) ) . ' cm</p>';
			if ( (float) $profile->height_cm > 0 && ! $no_figures ) {
				$html .= '<p>Waist-to-height: ' . esc_html( number_format( (float) $profile->waist_cm / (float) $profile->height_cm, 2, '.', '' ) ) . '</p>';
			}
		}
		return $html;
	}

	/**
	 * @param mixed $value Number.
	 */
	private static function one_decimal( $value ) {
		return number_format( (float) $value, 1, '.', '' );
	}

	/**
	 * @param object|null $profile Profile row.
	 * @param bool        $posted  Use the current request.
	 * @return string[]
	 */
	private static function selected_conditions( $profile, $posted ) {
		if ( $posted && isset( $_POST['lld_condition'] ) && is_array( $_POST['lld_condition'] ) ) {
			$out = array();
			foreach ( wp_unslash( $_POST['lld_condition'] ) as $key ) {
				$out[] = sanitize_key( (string) $key );
			}
			return $out;
		}
		if ( ! $profile ) {
			return array();
		}
		if ( 'none' === (string) $profile->conditions_answered ) {
			return array( 'none' );
		}
		$raw = json_decode( (string) $profile->conditions, true );
		return is_array( $raw ) ? array_map( 'strval', $raw ) : array();
	}

	/**
	 * @return array<string,string>
	 */
	private static function condition_choices() {
		return array(
			'kidney'              => 'Kidney disease',
			'pregnancy'           => 'Pregnancy or breastfeeding',
			'eating-disorder'     => 'Eating disorder, now or in the past',
			'type-2-diabetes'     => 'Type 2 diabetes',
			'prediabetes'         => 'Prediabetes',
			'high-blood-pressure' => 'High blood pressure',
			'high-cholesterol'    => 'High cholesterol',
		);
	}

	/**
	 * @param string     $id      Input id.
	 * @param string     $label   Label.
	 * @param string     $name    Field name.
	 * @param bool       $keep    Prefer the posted value after a failed save.
	 * @param string|null $stored Stored value when not keeping a post.
	 */
	private static function number_field( $id, $label, $name, $keep = false, $stored = null ) {
		$value = $keep ? self::posted_number( $name ) : null;
		if ( null === $value && null !== $stored && '' !== (string) $stored ) {
			$value = $stored;
		}
		$attr = null === $value || '' === (string) $value ? '' : ' value="' . esc_attr( (string) $value ) . '"';
		return '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="number" step="0.1" inputmode="decimal"' . $attr . '></p>';
	}

	/**
	 * Sex pills that drive the existing #lld_sex select (P3-36 D4).
	 *
	 * @param string $selected Current value.
	 */
	private static function sex_pills( $selected ) {
		$html = '<div class="lld-control" hidden><span class="lld-control-label" id="lld_sex_caption">Sex</span>';
		$html .= '<div class="lld-pill-row" data-lld-pills="lld_sex" role="group" aria-labelledby="lld_sex_caption">';
		foreach ( self::sex_choices() as $value => $label ) {
			$on    = (string) $selected === (string) $value;
			$html .= '<button type="button" data-lld-pill="' . esc_attr( $value ) . '"' . ( $on ? ' class="is-active"' : '' ) . ' aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		return $html . '</div></div>';
	}

	/**
	 * BMI and waist-to-height tiles when height is present (FR2-25 AS-04 / FR2-09 AS-03).
	 *
	 * @param object|null $profile Profile row.
	 * @param bool        $no_figures Leave out both tiles (safety matrix).
	 */
	private static function measure_tiles( $profile, $no_figures = false ) {
		if ( $no_figures || ! $profile || null === $profile->height_cm || '' === (string) $profile->height_cm ) {
			return '';
		}
		$html = '<div class="lld-measure-tiles">';
		if ( null !== $profile->weight_kg && '' !== (string) $profile->weight_kg ) {
			$metres = (float) $profile->height_cm / 100;
			if ( $metres > 0 ) {
				$html .= '<div class="lld-measure-tile"><span>BMI</span><strong>' . esc_html( self::one_decimal( (float) $profile->weight_kg / ( $metres * $metres ) ) ) . '</strong></div>';
			}
		}
		if ( null !== $profile->waist_cm && '' !== (string) $profile->waist_cm && (float) $profile->height_cm > 0 ) {
			$html .= '<div class="lld-measure-tile"><span>Waist-to-height</span><strong>' . esc_html( number_format( (float) $profile->waist_cm / (float) $profile->height_cm, 2, '.', '' ) ) . '</strong></div>';
		}
		return $html . '</div>';
	}

	/**
	 * A stored metric measurement as the form shows it, or null when there is none.
	 *
	 * @param object|null $profile Profile row.
	 * @param string      $column  height_cm|weight_kg|waist_cm.
	 * @return string|null
	 */
	private static function metric_part( $profile, $column ) {
		if ( ! $profile || ! isset( $profile->$column ) || '' === (string) $profile->$column ) {
			return null;
		}
		return self::one_decimal( $profile->$column );
	}

	/**
	 * Drops the measurements whose posted box still holds the value the form showed (P3-36 D4).
	 *
	 * @param array<string,mixed> $fields  Fields read from the post.
	 * @param object|null         $profile Stored profile.
	 * @return array<string,mixed>
	 */
	private static function without_unchanged( array $fields, $profile ) {
		if ( ! $profile ) {
			return $fields;
		}
		$same = static function ( $name, $shown ) {
			$posted = self::posted_number( $name );
			return null !== $posted && null !== $shown && self::one_decimal( $posted ) === self::one_decimal( $shown );
		};
		if ( ! empty( $_POST['lld_imperial'] ) ) {
			$checks = array(
				'height_cm' => $same( 'lld_feet', self::imperial_parts( $profile, 'feet' ) ) && $same( 'lld_inches', self::imperial_parts( $profile, 'inches' ) ),
				'weight_kg' => $same( 'lld_pounds', self::imperial_parts( $profile, 'pounds' ) ),
				'waist_cm'  => $same( 'lld_waist_in', self::imperial_parts( $profile, 'waist_in' ) ),
			);
		} else {
			$checks = array(
				'height_cm' => $same( 'lld_height', self::metric_part( $profile, 'height_cm' ) ),
				'weight_kg' => $same( 'lld_weight', self::metric_part( $profile, 'weight_kg' ) ),
				'waist_cm'  => $same( 'lld_waist', self::metric_part( $profile, 'waist_cm' ) ),
			);
		}
		foreach ( $checks as $column => $unchanged ) {
			if ( $unchanged ) {
				unset( $fields[ $column ] );
			}
		}
		return $fields;
	}

	/**
	 * Imperial display parts from a stored metric profile.
	 *
	 * @param object|null $profile Profile row.
	 * @param string      $part    feet|inches|pounds|waist_in.
	 * @return string|null
	 */
	private static function imperial_parts( $profile, $part ) {
		if ( ! $profile ) {
			return null;
		}
		if ( 'feet' === $part || 'inches' === $part ) {
			if ( null === $profile->height_cm || '' === (string) $profile->height_cm ) {
				return null;
			}
			$total = round( (float) $profile->height_cm / 2.54, 1 );
			$feet  = (int) floor( $total / 12 );
			$inch  = $total - ( $feet * 12 );
			return 'feet' === $part ? (string) $feet : self::one_decimal( $inch );
		}
		if ( 'pounds' === $part ) {
			if ( null === $profile->weight_kg || '' === (string) $profile->weight_kg ) {
				return null;
			}
			return self::one_decimal( (float) $profile->weight_kg / 0.45359237 );
		}
		if ( 'waist_in' === $part ) {
			if ( null === $profile->waist_cm || '' === (string) $profile->waist_cm ) {
				return null;
			}
			return self::one_decimal( (float) $profile->waist_cm / 2.54 );
		}
		return null;
	}

	/**
	 * @param object|null $profile Profile row.
	 */
	private static function profile_complete( $profile ) {
		if ( ! $profile || null === $profile->height_cm || '' === (string) $profile->height_cm ) {
			return false;
		}
		return in_array( (string) $profile->conditions_answered, array( 'none', 'declared' ), true );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function suggestion_block( $member_id ) {
		$html    = '';
		$consent = LLD_Members_Account::has_consent( $member_id, 'health' );
		if ( self::general_path( $member_id ) ) {
			$html .= '<p>You are on the general wellbeing path.</p>';
		}
		$items = self::pick( $member_id );
		if ( ! $items ) {
			return $html . LLD_Members_Safety::empty_html( $member_id );
		}
		foreach ( $items as $item ) {
			$html .= '<article class="lld-suggestion"><div class="lld-suggestion-meta"><p class="lld-kicker">Suggestion for you</p>';
			if ( ! empty( $item['approved_on'] ) ) {
				$html .= '<p class="lld-hint">' . esc_html( 'Reviewed by ' . self::reviewer_name( (int) ( $item['approved_by'] ?? 0 ) ) . ' on ' . self::nice_day( (string) $item['approved_on'] ) ) . '</p>';
			}
			$html .= '</div><h3><a href="' . esc_url( $item['href'] ) . '">' . esc_html( $item['title'] ) . '</a></h3>';
			$html .= '<p class="lld-reason">' . esc_html( $item['reason'] ) . '</p>';
			// "Not for me" is remembered in the suggestion history (C15), which needs health consent.
			if ( $consent ) {
				$html .= '<form method="post">';
				$html .= wp_nonce_field( 'lld_suggest', 'lld_suggest_nonce', true, false );
				$html .= '<input type="hidden" name="lld_hide" value="' . esc_attr( $item['key'] ) . '">';
				$html .= '<button type="submit">Not for me</button></form>';
			}
			$html .= '</article>';
		}
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<int,array{key:string,title:string,href:string,reason:string}>
	 */
	private static function pick( $member_id ) {
		$fresh = array();
		$stale = array();
		foreach ( self::matched_content( $member_id ) as $item ) {
			if ( self::is_hidden( $member_id, $item['key'] ) || ! LLD_Members_Safety::allows( $member_id, $item ) ) {
				continue;
			}
			if ( self::opened_recently( $member_id, $item['key'] ) ) {
				$item['opened'] = self::opened_on( $member_id, $item['key'] );
				$stale[]        = $item;
			} else {
				$fresh[] = $item;
			}
		}
		if ( ! $fresh && ! $stale ) {
			return array();
		}
		usort(
			$stale,
			static function ( $a, $b ) {
				return strcmp( (string) $a['opened'], (string) $b['opened'] );
			}
		);
		$tool      = self::tool_card( $member_id );
		$show_tool = $tool && ! self::is_hidden( $member_id, $tool['key'] ) && ! self::opened_recently( $member_id, $tool['key'] );
		$room      = $show_tool ? 5 : 6;
		$picked    = array_slice( $fresh, 0, $room );
		if ( count( $picked ) < $room ) {
			$picked = array_merge( $picked, array_slice( $stale, 0, $room - count( $picked ) ) );
		}
		if ( $show_tool ) {
			$picked[] = $tool;
		}
		$reason = self::general_path( $member_id ) ? 'General wellbeing' : 'Matches your goals';
		foreach ( $picked as $index => $item ) {
			if ( empty( $item['reason'] ) ) {
				$picked[ $index ]['reason'] = $reason;
			}
		}
		return array_slice( $picked, 0, 6 );
	}

	/**
	 * @param int $member_id Member number.
	 * @return array<int,array<string,mixed>>
	 */
	private static function matched_content( $member_id ) {
		$wanted = self::goal_tags( $member_id );
		if ( ! $wanted ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'lld_activity', 'lld_lesson', 'lld_recipe', 'lld_meal', 'lld_workout', 'lld_challenge' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$shown = LLD_Members_Content::shown( $post->ID );
			if ( ! is_array( $shown ) ) {
				continue;
			}
			$tags = isset( $shown['tags'] ) && is_array( $shown['tags'] ) ? $shown['tags'] : array();
			if ( ! array_intersect( $wanted, array_map( 'strval', $tags ) ) ) {
				continue;
			}
			if ( get_post_meta( $post->ID, '_lld_premium', true ) && ! ( class_exists( 'LLD_Members_Billing' ) ? LLD_Members_Billing::paid_open( $member_id ) : 'free' !== LLD_Members_Access::access_state( $member_id ) ) ) {
				continue;
			}
			$out[] = array(
				'key'    => 'c' . $post->ID,
				'title'  => (string) $shown['title'],
				'href'   => home_url( '/members/library/' . $post->ID . '/' ),
				'tags'   => $tags,
				'type'   => (string) ( $shown['type'] ?? '' ),
				'fields' => isset( $shown['fields'] ) && is_array( $shown['fields'] ) ? $shown['fields'] : array(),
				'approved_on' => (string) ( $shown['approved_on'] ?? '' ),
				'approved_by' => (int) ( $shown['approved_by'] ?? 0 ),
			);
		}
		$articles = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array(
					array(
						'taxonomy' => 'lld_tag',
						'field'    => 'slug',
						'terms'    => $wanted,
					),
				),
				'meta_query'     => array(
					array(
						'key'   => 'lld_review_status',
						'value' => 'approved',
					),
				),
			)
		);
		foreach ( $articles as $post ) {
			$tags = wp_get_object_terms( $post->ID, 'lld_tag', array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $tags ) ) {
				$tags = array();
			}
			if ( get_post_meta( $post->ID, '_lld_premium', true ) && ! ( class_exists( 'LLD_Members_Billing' ) ? LLD_Members_Billing::paid_open( $member_id ) : 'free' !== LLD_Members_Access::access_state( $member_id ) ) ) {
				continue;
			}
			$out[] = array(
				'key'   => 'p' . $post->ID,
				'title' => $post->post_title,
				'href'  => home_url( '/members/read/' . $post->ID . '/' ),
				'tags'  => $tags,
				'type'  => 'post',
			);
		}
		return $out;
	}

	/**
	 * @param int $member_id Member number.
	 * @return array{key:string,title:string,href:string,reason:string,tags:array<int,string>,type:string}|null
	 */
	private static function tool_card( $member_id ) {
		$card = array(
			'key'    => 'tool-protein',
			'title'  => 'Protein calculator',
			'href'   => home_url( '/tools/protein-requirement-tool.html' ),
			'reason' => 'A general wellbeing tool',
			'tags'   => array(),
			'type'   => 'tool',
		);
		return LLD_Members_Safety::allows( $member_id, $card ) ? $card : null;
	}

	/**
	 * @param int $member_id Member number.
	 * @return string[]
	 */
	private static function goal_tags( $member_id ) {
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT goal_key FROM ' . LLD_Members_Schema::table( 'goal_choice' ) . ' WHERE member_id = %d', $member_id ) );
		if ( ! $keys ) {
			return array();
		}
		$slugs = array();
		foreach ( LLD_Members_Content::approved_goals() as $snap ) {
			if ( ! in_array( sanitize_title( (string) $snap['title'] ), $keys, true ) ) {
				continue;
			}
			// A goal chosen before a condition was saved stops steering suggestions once its track is not offered.
			if ( ! LLD_Members_Safety::track_allowed( $member_id, (string) $snap['title'] ) ) {
				continue;
			}
			$tags  = isset( $snap['tags'] ) && is_array( $snap['tags'] ) ? $snap['tags'] : array();
			$slugs = array_merge( $slugs, array_map( 'strval', $tags ) );
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function general_path( $member_id ) {
		if ( ! LLD_Members_Account::has_consent( $member_id, 'health' ) ) {
			return true;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LLD_Members_Schema::table( 'goal_choice' ) . ' WHERE member_id = %d', $member_id ) );
		return $count < 1;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Item key.
	 */
	private static function is_hidden( $member_id, $key ) {
		global $wpdb;
		$hidden = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT hidden_on FROM ' . LLD_Members_Schema::table( 'suggestion' ) . ' WHERE member_id = %d AND item_key = %s ORDER BY id DESC LIMIT 1',
				$member_id,
				$key
			)
		);
		return is_string( $hidden ) && '' !== $hidden;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Item key.
	 */
	private static function opened_on( $member_id, $key ) {
		global $wpdb;
		$opened = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT last_opened_on FROM ' . LLD_Members_Schema::table( 'suggestion' ) . ' WHERE member_id = %d AND item_key = %s ORDER BY id DESC LIMIT 1',
				$member_id,
				$key
			)
		);
		return is_string( $opened ) ? $opened : '';
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Item key.
	 */
	private static function opened_recently( $member_id, $key ) {
		$opened = self::opened_on( $member_id, $key );
		if ( '' === $opened ) {
			return false;
		}
		$then  = DateTimeImmutable::createFromFormat( 'Y-m-d', $opened, new DateTimeZone( 'UTC' ) );
		$today = LLD_Members_Clock::now( $member_id );
		if ( ! $then ) {
			return false;
		}
		$days = (int) $then->diff( $today )->days;
		return $days <= 30 && $today >= $then;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Item key.
	 */
	public static function remember_open( $member_id, $key ) {
		// Suggestion history (C15) is health data.
		if ( ! LLD_Members_Account::has_consent( (int) $member_id, 'health' ) ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'suggestion' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND item_key = %s ORDER BY id DESC LIMIT 1", $member_id, $key ) );
		$date  = LLD_Members_Clock::now( $member_id )->format( 'Y-m-d' );
		if ( $id ) {
			$wpdb->update( $table, array( 'last_opened_on' => $date ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id'      => $member_id,
				'item_key'       => $key,
				'last_opened_on' => $date,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $key       Item key.
	 */
	private static function hide_item( $member_id, $key ) {
		$key = sanitize_text_field( $key );
		if ( '' === $key || strlen( $key ) > 64 || ! LLD_Members_Account::has_consent( (int) $member_id, 'health' ) ) {
			return;
		}
		global $wpdb;
		$table = LLD_Members_Schema::table( 'suggestion' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE member_id = %d AND item_key = %s ORDER BY id DESC LIMIT 1", $member_id, $key ) );
		$date  = LLD_Members_Clock::now( $member_id )->format( 'Y-m-d' );
		if ( $id ) {
			$wpdb->update( $table, array( 'hidden_on' => $date ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'member_id' => $member_id,
				'item_key'  => $key,
				'hidden_on' => $date,
			),
			array( '%d', '%s', '%s' )
		);
	}

	private static function take_suggestion_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['lld_hide'] ) ) {
			return;
		}
		if ( ! isset( $_POST['lld_suggest_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_suggest_nonce'] ) ), 'lld_suggest' ) ) {
			return;
		}
		self::hide_item( LLD_Members_Access::current_member_id(), (string) wp_unslash( $_POST['lld_hide'] ) );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : home_url( '/members/suggestions/' ) );
		exit;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function today_line( $member_id ) {
		return LLD_Members_Programme::today_html( $member_id );
	}

	/**
	 * @return array<string,string>
	 */
	private static function track_list() {
		return array(
			'healthy-weight' => 'Healthy Weight',
			'blood-sugar'    => 'Blood Sugar Balance',
			'fitness'        => 'Fitness and Strength',
			'sleep-stress'   => 'Sleep and Stress',
		);
	}

	/**
	 * A track change from another screen (settings): the same rules and event as the Tracks page.
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 * @return true|WP_Error
	 */
	public static function start_track( $member_id, $key ) {
		return self::enrol( $member_id, $key );
	}

	/**
	 * Start, switch, resume or repeat a track (SCR-25). The rules are in LLD_Members_Programme::start().
	 *
	 * @param int    $member_id Member number.
	 * @param string $key       Track key.
	 * @param string $start     today or tomorrow.
	 * @return true|WP_Error
	 */
	private static function enrol( $member_id, $key, $start = 'today' ) {
		if ( 'sleep-stress' === $key && self::track_held( $member_id ) ) {
			return new WP_Error( 'lld_track', 'Sleep and Stress is not available in this session.' );
		}
		global $wpdb;
		$table  = LLD_Members_Schema::table( 'enrolment' );
		$before = (string) $wpdb->get_var( $wpdb->prepare( "SELECT track_key FROM {$table} WHERE member_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $member_id ) );
		$result = LLD_Members_Programme::start( $member_id, $key, $start );
		// Growth event (C8): a new or different track was started.
		if ( true === $result && $before !== sanitize_title( (string) $key ) ) {
			do_action( 'lld_members_event', 'track_started', $member_id, array( 'track' => $key ) );
		}
		return $result;
	}

	/**
	 * @param int    $member_id Member number.
	 * @param string $state     Access state.
	 */
	private static function show_yearly( $member_id, $state ) {
		if ( 'free' === $state ) {
			return false;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT plan, started_on FROM ' . LLD_Members_Schema::table( 'subscription' ) . ' WHERE member_id = %d', $member_id ) );
		if ( ! $row || 'monthly' !== (string) $row->plan || ! $row->started_on ) {
			return false;
		}
		$started = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $row->started_on, new DateTimeZone( 'UTC' ) );
		if ( ! $started ) {
			return false;
		}
		return LLD_Members_Clock::now( $member_id ) >= $started->modify( '+1 month' );
	}

	/**
	 * @param int      $member_id Member number.
	 * @param string[] $types     Post types.
	 * @param int      $limit     Zero reads the whole library. A positive limit stops after that many safe titles.
	 * @return string[]
	 */
	private static function safe_titles( $member_id, $types, $limit = 0 ) {
		if ( $limit > 0 ) {
			return self::safe_titles_limited( $member_id, $types, $limit );
		}
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$shown = LLD_Members_Content::shown( $post->ID );
			if ( ! is_array( $shown ) || ! LLD_Members_Safety::allows( $member_id, $shown ) ) {
				continue;
			}
			$out[] = (string) $shown['title'];
		}
		return $out;
	}

	/**
	 * Approved titles only, in title order, stopping once the dashboard has enough.
	 *
	 * @param int      $member_id Member number.
	 * @param string[] $types     Post types.
	 * @param int      $limit     How many titles to keep.
	 * @return string[]
	 */
	private static function safe_titles_limited( $member_id, $types, $limit ) {
		global $wpdb;
		$types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
		if ( ! $types ) {
			return array();
		}
		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		$type_sql = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_sql = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_parent = 0 AND p.post_type IN ($type_sql) AND p.post_status IN ($status_sql) AND EXISTS ( SELECT 1 FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} m ON m.post_id = v.ID AND m.meta_key = '_lld_approved_on' AND m.meta_value <> '' WHERE v.post_parent = p.ID AND v.post_status = 'lld_version' ) ORDER BY p.post_title ASC LIMIT %d OFFSET %d";
		$out = array();
		$offset = 0;
		$batch = 24;
		while ( count( $out ) < $limit ) {
			$args = array_merge( $types, $statuses, array( $batch, $offset ) );
			$ids  = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$shown = LLD_Members_Content::shown( (int) $id );
				if ( ! is_array( $shown ) || ! LLD_Members_Safety::allows( $member_id, $shown ) ) {
					continue;
				}
				$out[] = (string) $shown['title'];
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
			if ( count( $ids ) < $batch ) {
				break;
			}
			$offset += $batch;
		}
		return $out;
	}

	/**
	 * @param string[] $titles Titles.
	 * @param int      $limit  Zero shows every title.
	 */
	private static function title_list( $titles, $limit ) {
		if ( ! $titles ) {
			return '';
		}
		if ( $limit > 0 ) {
			$titles = array_slice( $titles, 0, $limit );
		}
		$html = '<ul>';
		foreach ( $titles as $title ) {
			$html .= '<li>' . esc_html( $title ) . '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function unsuitable( $member_id ) {
		self::layout( 'Not suitable for you', LLD_Members_Safety::not_suitable( $member_id, 'This is not suitable for you.', home_url( '/members/home/' ), 'Back to today' ) );
	}

	/**
	 * The Sleep and Stress safety check (FR2-38, SCR-08) in the approved wording of
	 * 02 Content/Programme content/safety/screening-sleep-and-stress.md (Owner_Questions 93 = B, 3 Nov 2026), the same
	 * text the Stage 3 Sleep and Stress Check uses. The hint beside "Continue" shows while the button is disabled.
	 *
	 * @param string $error Error text.
	 */
	private static function screen_form( $error ) {
		$main  = '<h1>Before you start</h1>' . self::fail( $error );
		$main .= '<h2>A quick safety check</h2>';
		$main .= '<p>Before you start Sleep and Stress, we ask three short questions. They help us make sure this track is right for you today, and point you to the right support if it isn\'t.</p>';
		$main .= '<p>Your answers are used only on this screen. <strong>We don\'t store them.</strong> We\'ll ask again each time you start this track.</p>';
		$main .= '<form method="post" class="lld-form" data-lld-screen>';
		$main .= wp_nonce_field( 'lld_screen', 'lld_screen_nonce', true, false );
		$main .= self::yes_no( 'lld_harm', 'Have you had any thoughts of harming yourself or ending your life?' );
		$main .= self::yes_no( 'lld_panic', 'Do you have panic attacks?' );
		$main .= self::yes_no( 'lld_mood', 'Have you had low mood most days for 2 weeks or more?' );
		$main .= lld_button( array( 'label' => 'Continue' ) );
		if ( '' === $error ) {
			$main .= '<p class="lld-hint" data-lld-screen-hint hidden>Please answer all three questions to continue.</p>';
		}
		$main .= '</form>';
		return $main;
	}

	/**
	 * @param string $name  Field name.
	 * @param string $label Question.
	 */
	private static function yes_no( $name, $label ) {
		$html  = '<fieldset><legend>' . esc_html( $label ) . '</legend>';
		$html .= '<label class="lld-check"><input type="radio" name="' . esc_attr( $name ) . '" value="yes"> Yes</label>';
		$html .= '<label class="lld-check"><input type="radio" name="' . esc_attr( $name ) . '" value="no"> No</label>';
		$html .= '</fieldset>';
		return $html;
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function member_row( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'member' ) . ' WHERE id = %d', $member_id ) );
	}

	/**
	 * @param int $member_id Member number.
	 */
	private static function profile_row( $member_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LLD_Members_Schema::table( 'health_profile' ) . ' WHERE member_id = %d', $member_id ) );
	}

	/**
	 * @param string $text Error text.
	 */
	/**
	 * The clinical reviewer named on a suggestion card (UI2-02): the approving account, or the site.
	 *
	 * @param int $user_id Approving user.
	 */
	private static function reviewer_name( $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user && '' !== (string) $user->display_name ? (string) $user->display_name : 'the Lifeline Daily reviewer';
	}

	/**
	 * @param string $date Y-m-d.
	 */
	private static function nice_day( $date ) {
		$at = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, new DateTimeZone( 'UTC' ) );
		return $at ? $at->format( 'j F Y' ) : (string) $date;
	}

	private static function fail( $text ) {
		if ( '' === $text ) {
			return '';
		}
		return '<div class="lld-fail" role="alert"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * @param string $title Page title.
	 * @param string $main  Main HTML.
	 */
	private static function layout( $title, $main, $status = 200, $wide = false ) {
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

	private static function require_member() {
		$preview = class_exists( 'LLD_Members_Access' ) ? LLD_Members_Access::preview_state() : '';
		if ( in_array( $preview, array( 'free', 'premium' ), true ) && current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/' ) );
			exit;
		}
	}
}
