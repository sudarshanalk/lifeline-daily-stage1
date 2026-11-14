<?php
/**
 * Goal setting screen (P3-31 · FR3-09 to FR3-11): /members/goal/.
 *
 * One main goal (and one extra) with a target and a date, the starting measurements that matter for it, and the
 * personal plan built from it. Takes the request at template_redirect priority 5 for members the `goals` switch
 * applies to; for everyone else the Stage 2 dispatcher answers as before (NFR3-06). Adds its own rewrite rule
 * through LLD_Members_Goal_Journey and edits no Stage 2 file.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Goal setting controller and renderer.
 */
class LLD_Members_Goal_Setting {

	const HANDLE = 'lld-members-stage3';

	/**
	 * Called by the Stage 3 screens loader. Hooks nothing while the switch is off for everyone.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'goals' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * Renders /members/goal/ for a signed-in member the switch applies to; otherwise returns to Stage 2.
	 */
	public static function maybe_render() {
		if ( 'goal' !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! class_exists( 'LLD_Members_Access' ) || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/goal/' ) ) ) );
			exit;
		}
		$member_id = (int) LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Features::enabled( 'goals', $member_id ) ) {
			return;
		}
		if ( class_exists( 'LLD_Members_Account' ) && ! LLD_Members_Account::has_consent( $member_id, 'terms' ) ) {
			wp_safe_redirect( home_url( '/members/agreements/' ) );
			exit;
		}
		self::render( $member_id );
		exit;
	}

	/**
	 * The screen.
	 *
	 * @param int $member_id Member number.
	 */
	public static function render( $member_id ) {
		$facts = LLD_Members_Goal_Journey::facts( $member_id );
		$role  = isset( $_GET['role'] ) && 'extra' === $_GET['role'] ? 'extra' : 'main';
		$error = '';
		$check = null;
		$post  = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['lld_goal3_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_goal3_nonce'] ) ), 'lld_goal3' );
		$main  = '';

		if ( ! $facts['health'] ) {
			// The general wellbeing path (FR3-09 AS-02): nothing health-related is stored.
			$main = '<h1>Your goal</h1>' . lld_health_consent_notice() . '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Go to Today</a></p>';
			self::output( 'Your goal', $main, $post ? 403 : 200 );
			return;
		}
		// P3-36 A7 (FR3-09 AS-08, FR3-69 AS-05): a member who has never answered the Conditions question is taken to
		// it; saving it there brings them back here to set the goal. Nothing is stored on the way.
		$conditions = in_array( 'unknown', $facts['situations'], true ) && method_exists( 'LLD_Members_Stage3_Safety', 'conditions_state' ) ? LLD_Members_Stage3_Safety::conditions_state( $member_id ) : '';
		if ( 'missing' === $conditions && ! $post && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_safe_redirect( LLD_Members_Stage3_Safety::conditions_link( 'goal', $role ) );
			exit;
		}
		$allowed = LLD_Members_Goal_Rules::allowed_types( $facts['situations'] );
		if ( ! $allowed ) {
			$line = 'missing' === $conditions ? LLD_Members_Stage3_Safety::CONDITIONS_WORDING['conditions.missing'] : LLD_Members_Goal_Journey::note_texts()['general_wellbeing_path'];
			$link = '' !== $conditions ? LLD_Members_Stage3_Safety::conditions_link( 'goal', $role ) : home_url( '/members/health/' );
			$main = '<h1>Your goal</h1>' . lld_info( $line );
			$main .= '<p>' . lld_button( array( 'label' => 'Answer the health questions', 'href' => $link ) ) . ' <a href="' . esc_url( home_url( '/members/home/' ) ) . '">Go to Today</a></p>';
			self::output( 'Your goal', $main, $post ? 403 : 200 );
			return;
		}

		$type = isset( $_REQUEST['type'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['type'] ) ) : '';
		if ( '' !== $type && ! isset( $allowed[ $type ] ) ) {
			$type = '';
		}
		$suggestion = null;
		if ( $post ) {
			$action = isset( $_POST['lld_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_action'] ) ) : '';
			if ( 'help' === $action ) {
				$suggestion = LLD_Members_Goal_Rules::suggest( $facts );
			} elseif ( 'save' === $action && '' !== $type ) {
				$id = LLD_Members_Goal_Journey::create_goal( $member_id, $role, $type, self::posted( $facts ) + array( 'facts' => $facts ) );
				if ( is_wp_error( $id ) ) {
					$error = $id->get_error_message();
					$data  = $id->get_error_data();
					$check = is_array( $data ) && isset( $data['code'] ) ? $data : null;
				} else {
					wp_safe_redirect( home_url( '/members/goal/?done=' . (int) $id ) );
					exit;
				}
			} elseif ( 'start_track' === $action ) {
				$key = isset( $_POST['lld_track'] ) ? sanitize_title( (string) wp_unslash( $_POST['lld_track'] ) ) : '';
				$got = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::start( $member_id, $key ) : false;
				if ( is_wp_error( $got ) ) {
					$error = $got->get_error_message();
				} else {
					wp_safe_redirect( home_url( '/members/goal/?done=' . (int) ( $_POST['lld_goal_id'] ?? 0 ) . '&started=1' ) );
					exit;
				}
			}
		}

		$done = isset( $_GET['done'] ) ? (int) $_GET['done'] : 0;
		if ( $done > 0 ) {
			$goal = LLD_Members_Stage3_Data::row( 'goals', $member_id, $done, LLD_Members_Goal_Journey::SEALED_GOAL );
			if ( $goal ) {
				self::output( 'Your plan', self::plan_html( $member_id, $goal, $facts, $error, ! empty( $_GET['started'] ) ) );
				return;
			}
		}

		$active_main = LLD_Members_Goal_Journey::active_goal( $member_id, 'main' );
		if ( 'main' === $role && $active_main && '' === $type ) {
			self::output( 'Your goal', self::summary_html( $member_id, $active_main, $facts ) );
			return;
		}
		if ( 'extra' === $role && ( ! $active_main || LLD_Members_Goal_Journey::active_goal( $member_id, 'extra' ) ) ) {
			$main = '<h1>Add a goal</h1>' . lld_info( LLD_Members_Goal_Rules::MESSAGES['third_goal'] ) . '<p><a href="' . esc_url( home_url( '/members/goal/' ) ) . '">Back to your goal</a></p>';
			self::output( 'Add a goal', $main );
			return;
		}
		if ( '' !== $type ) {
			self::output( 'Set your goal', self::details_html( $member_id, $type, $role, $facts, $error, $check ) );
			return;
		}
		self::output( 'Set your goal', self::chooser_html( $member_id, $role, $facts, $allowed, $suggestion ) );
	}

	// ---- Views -------------------------------------------------------------------------------------------------------

	/**
	 * Step 1: the safety wording first (FR3-09 AS-07), then the goal types and 'Help me choose' (AS-04).
	 *
	 * @param int        $member_id  Member number.
	 * @param string     $role       main or extra.
	 * @param array      $facts      facts().
	 * @param array      $allowed    Allowed types.
	 * @param array|null $suggestion suggest() answer.
	 */
	private static function chooser_html( $member_id, $role, array $facts, array $allowed, $suggestion ) {
		$html  = '<div class="lld-stage3-goal" data-lld-goal-step="type">';
		$html .= '<h1>' . ( 'extra' === $role ? 'Add an extra goal' : 'Set your main goal' ) . '</h1>';
		$html .= '<p class="lld-hint">' . esc_html( LLD_Members_Goal_Rules::MESSAGES['under_a_minute'] ) . ' A goal is measurable: a target and a date.</p>';
		$html .= self::safety_html( $facts );
		if ( $suggestion ) {
			$label = $allowed[ $suggestion['type'] ]['label'];
			$html .= '<section class="lld-card lld-goal-suggestion" data-lld-suggestion="' . esc_attr( $suggestion['type'] ) . '"><p class="lld-kicker">Our suggestion</p><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( $suggestion['reason'] ) . '</p>';
			$html .= '<p>' . lld_button( array( 'label' => 'Use this goal', 'href' => home_url( '/members/goal/?role=' . $role . '&type=' . $suggestion['type'] ) ) ) . '</p></section>';
		}
		$html .= '<form method="post" class="lld-form lld-card lld-form-card" action="' . esc_url( home_url( '/members/goal/?role=' . $role ) ) . '">';
		$html .= wp_nonce_field( 'lld_goal3', 'lld_goal3_nonce', true, false );
		$html .= '<fieldset class="lld-options"><legend>What do you want to work on?</legend>';
		foreach ( $allowed as $key => $type ) {
			$html .= '<div class="lld-option"><label><input type="radio" name="type" value="' . esc_attr( $key ) . '" required> ' . esc_html( $type['label'] ) . '</label></div>';
		}
		$html .= '</fieldset><div class="lld-row">';
		$html .= '<button class="lld-button" type="submit" name="lld_action" value="choose">Next</button> ';
		$html .= '<button class="lld-button lld-button-secondary" type="submit" name="lld_action" value="help" formnovalidate>Help me choose</button></div></form>';
		$html .= '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Back</a></p></div>';
		return $html;
	}

	/**
	 * Step 2: the target, the date and the starting measurements for one goal type (FR3-09, FR3-10).
	 *
	 * @param int        $member_id Member number.
	 * @param string     $type      Goal type.
	 * @param string     $role      main or extra.
	 * @param array      $facts     facts().
	 * @param string     $error     Error text.
	 * @param array|null $check     A refused weight_check() answer, with its offer.
	 */
	private static function details_html( $member_id, $type, $role, array $facts, $error, $check ) {
		$types = LLD_Members_Goal_Rules::types();
		$def   = $types[ $type ];
		$imp   = ! empty( $facts['imperial'] );
		$w     = $imp ? 'lb' : 'kg';
		$l     = $imp ? 'in' : 'cm';
		$p     = self::posted_raw();
		$html  = '<div class="lld-stage3-goal" data-lld-goal-step="details" data-lld-goal-type="' . esc_attr( $type ) . '">';
		$html .= '<h1>' . esc_html( $def['label'] ) . '</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert" data-lld-refused="' . esc_attr( $check ? (string) $check['code'] : 'error' ) . '"><p>' . esc_html( $error ) . '</p>';
			if ( $check && 'underweight' === $check['code'] ) {
				$html .= '<p>' . esc_html( LLD_Members_Goal_Journey::note_texts()['underweight_wording_once'] ) . '</p>';
			}
			if ( $check && ! empty( $check['offered'] ) ) {
				$html .= '<p class="lld-hint">The suggestion is filled in below.</p>';
			}
			$html .= '</div>';
		}
		$html .= '<form method="post" class="lld-form lld-card lld-form-card" action="' . esc_url( home_url( '/members/goal/?role=' . $role . '&type=' . $type ) ) . '">';
		$html .= wp_nonce_field( 'lld_goal3', 'lld_goal3_nonce', true, false );
		$html .= '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
		$offered = $check && ! empty( $check['offered'] ) ? $check['offered'] : array();
		switch ( $type ) {
			case 'lose_weight':
			case 'keep_weight':
				if ( null === $facts['height_cm'] ) {
					$html .= self::number( 'lld_height', 'Height in ' . ( $imp ? 'inches' : 'centimetres' ), $p['lld_height'] ?? '', 0.1 );
				}
				$current = $p['lld_current'] ?? ( null !== $facts['weight_kg'] ? LLD_Members_Goal_Rules::plain( $imp ? LLD_Members_Goal_Rules::round1( $facts['weight_kg'] / 0.45359237 ) : $facts['weight_kg'] ) : '' );
				$html   .= self::number( 'lld_current', 'Your weight now, in ' . ( $imp ? 'pounds' : 'kilograms' ), $current, 0.1 );
				if ( 'lose_weight' === $type ) {
					$target = isset( $offered['goal_kg'] ) ? LLD_Members_Goal_Rules::plain( $imp ? LLD_Members_Goal_Rules::round1( $offered['goal_kg'] / 0.45359237 ) : $offered['goal_kg'] ) : ( $p['lld_target'] ?? '' );
					$html  .= self::number( 'lld_target', 'Goal weight, in ' . ( $imp ? 'pounds' : 'kilograms' ), $target, 0.1 );
					$rates  = $check && ! empty( $check['rates'] ) ? $check['rates'] : self::rates_for( $facts );
					$chosen = isset( $offered['rate'] ) ? (float) $offered['rate'] : ( isset( $p['lld_rate'] ) && is_numeric( $p['lld_rate'] ) ? (float) $p['lld_rate'] : LLD_Members_Goal_Rules::DEFAULT_RATE );
					$html  .= '<fieldset class="lld-options"><legend>How fast?</legend>';
					foreach ( $rates as $r ) {
						$html .= '<div class="lld-option"><label><input type="radio" name="lld_rate" value="' . esc_attr( LLD_Members_Goal_Rules::plain( $r ) ) . '"' . ( abs( $r - $chosen ) < 0.001 ? ' checked' : '' ) . '> ' . esc_html( self::rate_label( $r, $imp ) . ( abs( $r - 0.5 ) < 0.001 ? ' (steady, the usual choice)' : '' ) ) . '</label></div>';
					}
					$html .= '<div class="lld-option"><label><input type="radio" name="lld_rate" value="date"' . ( isset( $offered['date'] ) || ( isset( $p['lld_rate'] ) && 'date' === $p['lld_rate'] ) ? ' checked' : '' ) . '> By a date instead</label></div></fieldset>';
					$html .= self::date( 'lld_date', 'Date to reach it (optional)', isset( $offered['date'] ) ? $offered['date'] : ( $p['lld_date'] ?? '' ) );
					$html .= '<p class="lld-hint">' . esc_html( LLD_Members_Goal_Rules::MESSAGES['plateau'] ) . '</p>';
				} else {
					$html .= '<p>' . esc_html( LLD_Members_Goal_Rules::MESSAGES['maintain'] ) . '</p>';
				}
				$html .= self::number( 'lld_waist', 'Waist in ' . ( $imp ? 'inches' : 'centimetres' ) . ' (optional, you can add it later)', $p['lld_waist'] ?? '', 0.1 );
				break;
			case 'lower_blood_pressure':
				$html .= '<p>' . esc_html( LLD_Members_Goal_Rules::MESSAGES['bp_default'] ) . ' ' . esc_html( LLD_Members_Goal_Rules::MESSAGES['check_doctor'] ) . '</p>';
				$html .= '<div class="lld-field-row">' . self::number( 'lld_current', 'Your reading now: top number (optional)', $p['lld_current'] ?? '', 1 ) . self::number( 'lld_current_2', 'Bottom number', $p['lld_current_2'] ?? '', 1 ) . '</div>';
				$src   = $p['lld_source'] ?? 'guideline_default';
				$html .= '<fieldset class="lld-options"><legend>Your target</legend>';
				$html .= '<div class="lld-option"><label><input type="radio" name="lld_source" value="guideline_default"' . ( 'doctor' !== $src ? ' checked' : '' ) . '> Below 135/85, the guideline level for home readings</label></div>';
				$html .= '<div class="lld-option"><label><input type="radio" name="lld_source" value="doctor"' . ( 'doctor' === $src ? ' checked' : '' ) . '> The target my doctor gave me</label></div></fieldset>';
				$html .= '<div class="lld-field-row">' . self::number( 'lld_target', "Doctor's target: top number", $p['lld_target'] ?? '', 1 ) . self::number( 'lld_target_2', 'Bottom number', $p['lld_target_2'] ?? '', 1 ) . '</div>';
				$html .= self::date( 'lld_date', 'Date to reach it (optional, 12 weeks if left blank)', $p['lld_date'] ?? '' );
				break;
			case 'blood_sugar':
				$html .= '<p>' . esc_html( LLD_Members_Goal_Rules::MESSAGES['check_doctor'] ) . ' Blood sugar targets come from your doctor or diabetes team.</p>';
				$html .= self::number( 'lld_current', 'Fasting glucose now, mmol/L (optional)', $p['lld_current'] ?? '', 0.1 );
				$html .= self::number( 'lld_target', "Your doctor's fasting glucose target, mmol/L", $p['lld_target'] ?? '', 0.1, true );
				$html .= self::date( 'lld_date', 'Date to reach it (optional, 12 weeks if left blank)', $p['lld_date'] ?? '' );
				break;
			case 'activity':
				$html .= self::number( 'lld_current', 'Minutes of activity in a usual week now (optional)', $p['lld_current'] ?? '', 5 );
				$html .= self::number( 'lld_target', 'Minutes a week to aim for', $p['lld_target'] ?? (string) LLD_Members_Goal_Rules::ACTIVITY_DEFAULT, 5, true );
				$html .= '<p class="lld-hint">150 minutes a week of brisk walking or similar is the level linked to the biggest health gains.</p>';
				$html .= self::date( 'lld_date', 'Date to reach it (optional, 8 weeks if left blank)', $p['lld_date'] ?? '' );
				break;
			case 'strength':
				$html .= self::number( 'lld_current', 'Push-ups you can do now (optional)', $p['lld_current'] ?? '', 1 );
				$html .= self::number( 'lld_target', 'Push-ups to aim for', $p['lld_target'] ?? '', 1, true );
				$html .= self::date( 'lld_date', 'Date to reach it (optional, 8 weeks if left blank)', $p['lld_date'] ?? '' );
				break;
			case 'sleep':
				$html .= self::number( 'lld_current', 'Hours of sleep on a usual night now (optional)', $p['lld_current'] ?? '', 0.5 );
				$html .= self::number( 'lld_target', 'Hours a night to aim for', $p['lld_target'] ?? '7.5', 0.5, true );
				$html .= '<p class="lld-hint">Most adults do best on 7 to 9 hours.</p>';
				$html .= self::date( 'lld_date', 'Date to reach it (optional, 8 weeks if left blank)', $p['lld_date'] ?? '' );
				break;
		}
		$html .= '<div class="lld-row"><button class="lld-button" type="submit" name="lld_action" value="save">Save my goal</button> ';
		$html .= '<a class="lld-button lld-button-secondary" href="' . esc_url( home_url( '/members/goal/?role=' . $role ) ) . '">Back</a></div></form></div>';
		return $html;
	}

	/**
	 * The plan built from the goal (FR3-11): the track, the daily targets, what to log and when.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $goal      Goal row.
	 * @param array  $facts     facts().
	 * @param string $error     Error text.
	 * @param bool   $started   A track was just started.
	 */
	private static function plan_html( $member_id, array $goal, array $facts, $error, $started ) {
		$types = LLD_Members_Goal_Rules::types();
		$def   = isset( $types[ $goal['goal_type'] ] ) ? $types[ $goal['goal_type'] ] : $types['activity'];
		$plan  = LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] );
		$html  = '<div class="lld-stage3-goal lld-stage3-plan" data-lld-goal-step="plan" data-lld-premium="' . ( $facts['premium'] ? 'yes' : 'no' ) . '">';
		$html .= '<h1>' . ( 'extra' === (string) $goal['role'] ? 'Your extra goal' : 'Your plan' ) . '</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $started ) {
			$html .= '<div class="lld-notice-box lld-state-saved" role="status"><p>Track started.</p></div>';
		}
		$html .= '<section class="lld-card" data-lld-section="goal"><p class="lld-kicker">' . ( 'extra' === (string) $goal['role'] ? 'Extra goal' : 'Main goal' ) . '</p><h2>' . esc_html( self::goal_line( $goal, $facts ) ) . '</h2>';
		if ( $plan && ! empty( $plan['schedule']['rate_kg_week'] ) && is_numeric( $goal['start_value'] ) ) {
			$milestone = LLD_Members_Goal_Rules::round1( 0.95 * (float) $goal['start_value'] ); // from the opened goal, never stored plain
			$html     .= '<p>About ' . esc_html( self::rate_label( (float) $plan['schedule']['rate_kg_week'], $facts['imperial'] ) ) . '. First milestone: ' . esc_html( LLD_Members_Goal_Rules::show_weight( $milestone, $facts['imperial'] ) ) . '.</p>';
			if ( is_numeric( $goal['target_value'] ) && (float) $goal['start_value'] - (float) $goal['target_value'] > 0.1 * (float) $goal['start_value'] ) {
				$html .= '<p>' . esc_html( LLD_Members_Goal_Rules::fill( 'over_10', array( 'milestone' => $milestone ) ) ) . '</p>';
			}
			$html .= '<p class="lld-hint">' . esc_html( LLD_Members_Goal_Rules::MESSAGES['plateau'] ) . '</p>';
		}
		if ( in_array( 'age_65_plus', $facts['situations'], true ) && 'weight' === (string) $goal['measure'] ) {
			$html .= '<p>' . esc_html( LLD_Members_Goal_Journey::note_texts()['muscle_note'] ) . '</p>';
		}
		$html .= '</section>';
		if ( 'extra' === (string) $goal['role'] ) {
			$html .= '<p>' . lld_button( array( 'label' => 'Go to Today', 'href' => home_url( '/members/home/' ) ) ) . '</p></div>';
			return $html;
		}

		// The track to start with (FR3-11 AS-01, AS-02): the suggested track, with the closest fit while it is prepared.
		$track = $plan && ! empty( $plan['schedule']['track'] ) ? (string) $plan['schedule']['track'] : '';
		$want  = (string) $def['plan_track'];
		$html .= '<section class="lld-card" data-lld-section="track" data-lld-track="' . esc_attr( $track ) . '" data-lld-track-suggested="' . esc_attr( $want ) . '"><p class="lld-kicker">Track to start with</p>';
		$html .= '<h2>' . esc_html( self::track_name( $want ) ) . '</h2>';
		if ( '' !== $track && class_exists( 'LLD_Members_Programme' ) ) {
			$title  = LLD_Members_Programme::track_title( $track );
			$active = LLD_Members_Programme::active( $member_id );
			if ( $track !== $want ) {
				$html .= '<p class="lld-hint">The ' . esc_html( self::track_name( $want ) ) . ' track is being prepared. Until then, ' . esc_html( $title ) . ' is the closest fit.</p>';
			}
			if ( $active && (string) $active->track_key === $track ) {
				$html .= '<p>You are on ' . esc_html( $title ) . ': ' . esc_html( LLD_Members_Programme::position_label( $member_id ) ) . '.</p>';
			} elseif ( LLD_Members_Programme::track_allowed( $member_id, $track ) ) {
				$html .= '<form method="post" class="lld-inline-form">' . wp_nonce_field( 'lld_goal3', 'lld_goal3_nonce', true, false );
				$html .= '<input type="hidden" name="lld_track" value="' . esc_attr( $track ) . '"><input type="hidden" name="lld_goal_id" value="' . (int) $goal['id'] . '">';
				$html .= '<button class="lld-button" type="submit" name="lld_action" value="start_track">Start ' . esc_html( $title ) . '</button></form>';
			} else {
				$html .= '<p>' . esc_html( $title ) . ' is not suitable for you, based on your health profile. <a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">See the other tracks</a></p>';
			}
		} else {
			$html .= '<p>This track is being prepared. <a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">See the tracks available now</a></p>';
		}
		$html .= '</section>';

		// Daily targets, meals and workouts: the full guided plan is Premium (FR3-11 AS-03).
		$html .= '<section class="lld-card" data-lld-section="targets">';
		if ( $facts['premium'] ) {
			$html .= '<h2>Daily targets</h2>';
			if ( $plan && is_numeric( $plan['calorie_target'] ) && ! in_array( 'eating_disorder', $facts['situations'], true ) ) {
				$html .= '<p class="lld-stat" data-lld-kcal="' . (int) $plan['calorie_target'] . '">About <strong>' . esc_html( number_format( (int) $plan['calorie_target'] ) ) . ' kcal</strong> a day</p><ul class="lld-plan-targets">';
				foreach ( array( 'protein_g' => 'Protein', 'carb_g' => 'Carbohydrate', 'fat_g' => 'Fat', 'fibre_g' => 'Fibre' ) as $k => $label ) {
					if ( is_numeric( $plan[ $k ] ) ) {
						$html .= '<li>' . esc_html( $label ) . ': ' . (int) $plan[ $k ] . ' g</li>';
					}
				}
				$html .= '</ul>';
			} elseif ( 'weight' === (string) $goal['measure'] && 'lose_weight' === (string) $goal['goal_type'] ) {
				$html .= '<p>Add your height, sex and activity level to your <a href="' . esc_url( home_url( '/members/health/' ) ) . '">health profile</a> and your daily calorie target appears here.</p>';
			}
			$html .= '<p>Activity: <strong>' . (int) ( $plan ? $plan['activity_minutes_week'] : LLD_Members_Goal_Rules::ACTIVITY_DEFAULT ) . ' minutes</strong> a week.</p>';
			$html .= '<p><a href="' . esc_url( home_url( '/members/meals/' ) ) . '">This week\'s meal plan</a> · <a href="' . esc_url( home_url( '/members/workout/' ) ) . '">Workouts</a></p>';
		} else {
			$html .= '<h2>Your progress</h2><p>You can follow your goal progress on Today and the progress page.</p>';
			$html .= lld_membership_lock( 'The full guided plan (daily targets, meal plans and workouts) is part of Premium.' );
		}
		$html .= '</section>';

		// What to log and when.
		$html .= '<section class="lld-card" data-lld-section="schedule"><h2>What to log</h2><ul>';
		$sched = $plan ? $plan['schedule'] : array();
		if ( 'weight' === (string) $goal['measure'] ) {
			$html .= '<li>Weigh in once a week, on ' . esc_html( self::weekday_name( isset( $sched['weigh_in_weekday'] ) ? $sched['weigh_in_weekday'] : 'mon' ) ) . 's, at the weekly check-in</li>';
		}
		if ( 'blood_pressure' === (string) $goal['measure'] ) {
			$html .= '<li>Blood pressure three times a week, sitting quietly for five minutes first</li>';
		}
		if ( 'glucose' === (string) $goal['measure'] ) {
			$html .= '<li>Fasting glucose as your diabetes team advised</li>';
		}
		if ( in_array( (string) $goal['measure'], array( 'activity_minutes', 'reps' ), true ) ) {
			$html .= '<li>Minutes of activity each day you move</li>';
		}
		if ( 'sleep_hours' === (string) $goal['measure'] ) {
			$html .= '<li>Hours slept each morning</li>';
		}
		$html .= '<li>Meals in the food diary, and the quick tiles for water and mood</li><li>The weekly check-in every week</li></ul></section>';
		$html .= '<p>' . lld_button( array( 'label' => 'Go to Today', 'href' => home_url( '/members/home/' ) ) ) . '</p></div>';
		return $html;
	}

	/**
	 * An existing main goal (the screen when a goal is already set).
	 *
	 * @param int   $member_id Member number.
	 * @param array $goal      Goal row.
	 * @param array $facts     facts().
	 */
	private static function summary_html( $member_id, array $goal, array $facts ) {
		$extra = LLD_Members_Goal_Journey::active_goal( $member_id, 'extra' );
		$html  = '<div class="lld-stage3-goal" data-lld-goal-step="summary"><h1>Your goal</h1>';
		$html .= '<section class="lld-card" data-lld-section="goal"><p class="lld-kicker">Main goal</p><h2>' . esc_html( self::goal_line( $goal, $facts ) ) . '</h2>';
		$html .= '<p><a href="' . esc_url( home_url( '/members/goal/?done=' . (int) $goal['id'] ) ) . '">See your plan</a></p></section>';
		if ( $extra ) {
			$html .= '<section class="lld-card" data-lld-section="extra"><p class="lld-kicker">Extra goal</p><h2>' . esc_html( self::goal_line( $extra, $facts ) ) . '</h2></section>';
		} else {
			$html .= '<p>' . lld_button( array( 'label' => 'Add an extra goal', 'href' => home_url( '/members/goal/?role=extra' ), 'variant' => 'secondary' ) ) . '</p>';
		}
		$html .= '<p>' . lld_button( array( 'label' => 'Go to Today', 'href' => home_url( '/members/home/' ) ) ) . '</p></div>';
		return $html;
	}

	/**
	 * The safety wording for the member's situations, shown before any goal (FR3-09 AS-07).
	 *
	 * @param array $facts facts().
	 */
	public static function safety_html( array $facts ) {
		$keys = LLD_Members_Goal_Rules::safety_notes( $facts['situations'] );
		if ( ! $keys ) {
			return '';
		}
		$texts = LLD_Members_Goal_Journey::note_texts();
		$html  = '<section class="lld-card lld-goal-safety" data-lld-safety="' . esc_attr( implode( ' ', $facts['situations'] ) ) . '" aria-labelledby="lld-goal-safety-h"><h2 id="lld-goal-safety-h">Before you choose</h2>';
		foreach ( $keys as $key ) {
			if ( ! empty( $texts[ $key ] ) ) {
				$html .= '<p>' . esc_html( $texts[ $key ] ) . '</p>';
			}
		}
		return $html . '</section>';
	}

	// ---- Helpers -----------------------------------------------------------------------------------------------------

	/**
	 * One line that says the goal: "Lose 6 kg by Monday 8 February 2027".
	 *
	 * @param array $goal  Goal row.
	 * @param array $facts facts().
	 */
	public static function goal_line( array $goal, array $facts ) {
		$imp  = ! empty( $facts['imperial'] );
		$date = $goal['target_date'] ? ' by ' . LLD_Members_Goal_Rules::nice_date( (string) $goal['target_date'] ) : '';
		switch ( (string) $goal['goal_type'] ) {
			case 'lose_weight':
				if ( is_numeric( $goal['start_value'] ) && is_numeric( $goal['target_value'] ) ) {
					return 'Lose ' . LLD_Members_Goal_Rules::show_weight( (float) $goal['start_value'] - (float) $goal['target_value'], $imp ) . ' to reach ' . LLD_Members_Goal_Rules::show_weight( $goal['target_value'], $imp ) . $date;
				}
				return 'Reach ' . LLD_Members_Goal_Rules::show_weight( $goal['target_value'], $imp ) . $date;
			case 'keep_weight':
				return 'Keep my weight steady at about ' . LLD_Members_Goal_Rules::show_weight( $goal['target_value'], $imp );
			case 'lower_blood_pressure':
				return 'Blood pressure below ' . (int) $goal['target_value'] . '/' . (int) ( isset( $goal['target_value_2'] ) && is_numeric( $goal['target_value_2'] ) ? $goal['target_value_2'] : LLD_Members_Goal_Rules::BP_DEFAULT[1] ) . $date;
			case 'blood_sugar':
				return 'Fasting glucose at or below ' . LLD_Members_Goal_Rules::plain( (float) $goal['target_value'] ) . ' mmol/L' . $date;
			case 'activity':
				return (int) $goal['target_value'] . ' minutes of activity a week' . $date;
			case 'strength':
				return (int) $goal['target_value'] . ' push-ups' . $date;
			case 'sleep':
				return LLD_Members_Goal_Rules::plain( (float) $goal['target_value'] ) . ' hours of sleep a night' . $date;
		}
		return 'Your goal';
	}

	/** The rates open before the goal weight is known: from the latest weight and age, else the full list. */
	private static function rates_for( array $facts ) {
		if ( null === $facts['weight_kg'] ) {
			return LLD_Members_Goal_Rules::RATES;
		}
		$cap = min( 1.0, LLD_Members_Goal_Rules::round2( 0.01 * (float) $facts['weight_kg'] ) );
		if ( in_array( 'age_65_plus', $facts['situations'], true ) ) {
			$cap = min( $cap, 0.5 );
		}
		$out = array();
		foreach ( LLD_Members_Goal_Rules::RATES as $r ) {
			if ( $r <= $cap + 1e-9 ) {
				$out[] = $r;
			}
		}
		return $out ? $out : array( 0.25 );
	}

	/**
	 * The posted numbers, converted to metric for create_goal().
	 *
	 * @param array $facts facts().
	 * @return array<string,mixed>
	 */
	private static function posted( array $facts ) {
		$imp  = ! empty( $facts['imperial'] );
		$p    = self::posted_raw();
		$type = isset( $p['type'] ) ? (string) $p['type'] : '';
		$num  = function ( $k ) use ( $p ) {
			return isset( $p[ $k ] ) && is_numeric( $p[ $k ] ) ? (float) $p[ $k ] : null;
		};
		$out = array( 'source' => isset( $p['lld_source'] ) ? (string) $p['lld_source'] : '' );
		if ( in_array( $type, array( 'lose_weight', 'keep_weight' ), true ) ) {
			$out['current']   = null !== $num( 'lld_current' ) ? LLD_Members_Goal_Rules::to_kg( $num( 'lld_current' ), $imp ) : null;
			$out['target']    = null !== $num( 'lld_target' ) ? LLD_Members_Goal_Rules::to_kg( $num( 'lld_target' ), $imp ) : null;
			$out['height_cm'] = null !== $num( 'lld_height' ) ? LLD_Members_Goal_Rules::to_cm( $num( 'lld_height' ), $imp ) : null;
			$out['waist_cm']  = null !== $num( 'lld_waist' ) ? LLD_Members_Goal_Rules::to_cm( $num( 'lld_waist' ), $imp ) : null;
			$out['unit_shown'] = $imp ? 'lb' : 'kg';
			$rate              = isset( $p['lld_rate'] ) ? (string) $p['lld_rate'] : '';
			if ( 'date' === $rate ) {
				$out['target_date'] = isset( $p['lld_date'] ) ? (string) $p['lld_date'] : '';
			} elseif ( is_numeric( $rate ) ) {
				$out['rate'] = (float) $rate;
			}
		} else {
			$out['current']   = $num( 'lld_current' );
			$out['current_2'] = $num( 'lld_current_2' );
			$out['target']    = 'lower_blood_pressure' === $type && 'doctor' !== $out['source'] ? null : $num( 'lld_target' );
			$out['target_2']  = $num( 'lld_target_2' );
			if ( ! empty( $p['lld_date'] ) ) {
				$out['target_date'] = (string) $p['lld_date'];
			}
		}
		return $out;
	}

	/** The posted fields, unslashed and trimmed. */
	private static function posted_raw() {
		$out = array();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return $out;
		}
		foreach ( array( 'type', 'lld_height', 'lld_current', 'lld_current_2', 'lld_target', 'lld_target_2', 'lld_waist', 'lld_rate', 'lld_date', 'lld_source' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$out[ $k ] = str_replace( ',', '.', trim( sanitize_text_field( (string) wp_unslash( $_POST[ $k ] ) ) ) );
			}
		}
		return $out;
	}

	private static function number( $id, $label, $value, $step, $required = false ) {
		return '<div class="lld-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" type="number" inputmode="' . ( $step >= 1 ? 'numeric' : 'decimal' ) . '" step="' . ( $step >= 1 ? esc_attr( (string) $step ) : 'any' ) . '" value="' . esc_attr( (string) $value ) . '"' . ( $required ? ' required' : '' ) . '></div>';
	}

	private static function date( $id, $label, $value ) {
		return '<div class="lld-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" type="date" value="' . esc_attr( (string) $value ) . '"></div>';
	}

	private static function track_name( $slug ) {
		$names = array( 'healthy-weight' => 'Healthy Weight', 'blood-sugar' => 'Blood Sugar Balance', 'fitness' => 'Fitness and Strength', 'sleep-stress' => 'Sleep and Stress', 'heart-health' => 'Heart Health', 'keep-it-off' => 'Keep It Off' );
		return isset( $names[ $slug ] ) ? $names[ $slug ] : ucwords( str_replace( '-', ' ', (string) $slug ) );
	}

	/** "0.5 kg a week", or "0.5 kg (about 1.1 lb) a week" for an imperial member: the rate stays the sheet's kg figure. */
	public static function rate_label( $rate, $imperial ) {
		$kg = LLD_Members_Goal_Rules::plain( (float) $rate ) . ' kg';
		return $imperial ? $kg . ' (about ' . LLD_Members_Goal_Rules::plain( LLD_Members_Goal_Rules::round1( (float) $rate / 0.45359237 ) ) . ' lb) a week' : $kg . ' a week';
	}

	public static function weekday_name( $key ) {
		$names = array( 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' );
		$k     = strtolower( substr( (string) $key, 0, 3 ) );
		return isset( $names[ $k ] ) ? $names[ $k ] : 'Monday';
	}

	/**
	 * Print the page in the Stage 2 layout with the Stage 3 stylesheet.
	 *
	 * @param string $title  Title.
	 * @param string $main   Main HTML.
	 * @param int    $status HTTP status.
	 */
	public static function output( $title, $main, $status = 200 ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		ob_start();
		lld_members_layout( array( 'title' => $title, 'main' => $main, 'signed_in' => true, 'status' => $status ) );
		$page = (string) ob_get_clean();
		ob_start();
		wp_print_styles( array( self::HANDLE ) );
		$styles = (string) ob_get_clean();
		$head   = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}
}
