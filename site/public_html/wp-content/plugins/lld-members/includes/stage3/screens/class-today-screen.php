<?php
/**
 * Today screen, weekly check-in and member dashboard (P3-32, P3-33 · FR3-12 to FR3-15, FR3-18).
 *
 * For members the `today` switch applies to, /members/home/ becomes the coaching Today screen: one next step
 * chosen by the decision engine with Done and Skip, the weekly ring, quick-log tiles and goal progress; with the
 * `progress` switch on too, the dashboard sections follow (today's totals, latest readings, the current track and
 * week, the plan and the trends). /members/check-in/ becomes the weekly check-in that can adjust the plan once the
 * member has a Stage 3 goal. Both take the request at template_redirect priority 5; for everyone else the Stage 2
 * screens answer untouched (NFR3-06).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Today, check-in and dashboard.
 */
class LLD_Members_Today_Screen {

	const HANDLE = 'lld-members-stage3';

	/**
	 * Called by the Stage 3 screens loader. Hooks nothing while the switch is off for everyone.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'today' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * Home and check-in for members the switch applies to; everything else goes back to Stage 2.
	 */
	public static function maybe_render() {
		$screen = (string) get_query_var( 'lld_screen' );
		if ( ! in_array( $screen, array( 'home', 'check-in' ), true ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! class_exists( 'LLD_Members_Access' ) ) {
			return;
		}
		$member_id = (int) LLD_Members_Access::current_member_id();
		if ( $member_id < 1 || ! LLD_Members_Features::enabled( 'today', $member_id ) ) {
			return;
		}
		if ( '' !== LLD_Members_Access::preview_state() ) {
			return; // the owner's Free/Premium preview keeps the Stage 2 screens
		}
		$facts = LLD_Members_Goal_Journey::facts( $member_id );
		if ( 'check-in' === $screen ) {
			if ( ! LLD_Members_Goal_Journey::active_goal( $member_id, 'main' ) ) {
				return; // no Stage 3 goal yet: the Stage 2 track check-in stands
			}
			self::check_in( $member_id, $facts );
			exit;
		}
		// FR3-09 AS-01: a member with health consent sets a main goal before the dashboard.
		if ( LLD_Members_Features::enabled( 'goals', $member_id ) && LLD_Members_Goal_Journey::needs_goal( $member_id, $facts ) ) {
			wp_safe_redirect( home_url( '/members/goal/' ) );
			exit;
		}
		self::today( $member_id, $facts );
		exit;
	}

	// ---- Today (FR3-12, FR3-13, FR3-15) ------------------------------------------------------------------------------

	/**
	 * The Today screen, with the dashboard sections when the progress switch applies.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function today( $member_id, array $facts ) {
		$today  = (string) $facts['today'];
		$notice = '';
		$error  = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$was = self::ring( $member_id, $facts )['done']; // P3-36 D9: the count before, so the ring can show the change
			$got = self::today_post( $member_id, $facts );
			if ( is_wp_error( $got ) ) {
				$error = $got->get_error_message();
			} elseif ( is_string( $got ) && '' !== $got ) {
				wp_safe_redirect( home_url( '/members/home/?saved=' . rawurlencode( $got ) . '&ring_was=' . (int) $was . '&ring_on=' . rawurlencode( $today ) ) );
				exit;
			}
		}
		if ( isset( $_GET['saved'] ) ) {
			$saved  = sanitize_key( (string) wp_unslash( $_GET['saved'] ) );
			$lines  = array( 'done' => 'Done. Nice work.', 'skipped' => 'Skipped for today.', 'water' => 'Water logged.', 'mood' => 'Mood logged.', 'kept' => 'Your plan stays as it is.' );
			$notice = isset( $lines[ $saved ] ) ? $lines[ $saved ] : '';
		}
		$main_goal = LLD_Members_Goal_Journey::active_goal( $member_id, 'main' );
		if ( $main_goal && LLD_Members_Goal_Journey::check_reached( $member_id, $main_goal, $facts ) ) {
			$main_goal = null;
		}
		if ( ! $main_goal ) {
			$latest = LLD_Members_Goal_Journey::latest_main_goal( $member_id );
			if ( $latest && 'reached' === (string) $latest['status'] ) {
				self::output( 'Goal reached', self::reached_html( $member_id, $latest, $facts, $error ) );
				return;
			}
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new DateTimeZone( 'UTC' ) );
		$html = '<div class="lld-stage3-today lld-dash" data-lld-premium="' . ( $facts['premium'] ? 'yes' : 'no' ) . '"><h1>Today</h1>';
		$html .= '<p class="lld-kicker">' . esc_html( $date ? $date->format( 'l j F' ) : $today ) . ( '' !== $facts['name'] ? ' · Hello ' . esc_html( $facts['name'] ) : '' ) . '</p>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $notice ) {
			$html .= '<div class="lld-notice-box lld-state-saved" role="status"><p>' . esc_html( $notice ) . '</p></div>';
		}
		$html .= self::next_step_html( $member_id, $facts );
		$html .= '<div class="lld-dash-pair">' . self::ring_html( $member_id, $facts ) . self::tiles_html( $member_id, $facts ) . '</div>';
		$html .= self::goal_card_html( $member_id, $facts, $main_goal );
		$html .= self::insights_html( $member_id, $facts );
		if ( LLD_Members_Features::enabled( 'progress', $member_id ) ) {
			$html .= self::dashboard_html( $member_id, $facts, $main_goal );
		}
		$html .= '<section data-lld-section="membership" class="lld-card"><h2>Membership</h2><p class="lld-membership">Your membership is ' . esc_html( $facts['premium'] ? 'Premium' : 'Free' ) . '.</p>';
		if ( ! $facts['premium'] ) {
			$html .= '<p><a href="' . esc_url( home_url( '/pricing/' ) ) . '">See Premium</a></p>';
		}
		$html .= '<p><a href="' . esc_url( home_url( '/members/settings/' ) ) . '">Account</a></p></section></div>';
		self::output( 'Today', $html );
	}

	/**
	 * The Today posts: Done or Skip, a quick-log tile, or the Goal reached choices.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 * @return string|WP_Error|null The saved key to show, an error, or null when nothing was posted.
	 */
	private static function today_post( $member_id, array $facts ) {
		$today = (string) $facts['today'];
		if ( isset( $_POST['lld_today_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_today_nonce'] ) ), 'lld_today' ) ) {
			$status = isset( $_POST['lld_step'] ) && 'done' === $_POST['lld_step'] ? 'done' : 'skipped';
			$action = isset( $_POST['lld_action_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_action_type'] ) ) : 'activity';
			if ( ! in_array( $action, array( 'activity', 'log_reading', 'log_food', 'read', 'workout', 'check_in' ), true ) ) {
				$action = 'activity';
			}
			LLD_Members_Goal_Journey::mark_step( $member_id, $today, $status, $action );
			return $status;
		}
		if ( isset( $_POST['lld_quick_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_quick_nonce'] ) ), 'lld_quick' ) ) {
			if ( ! $facts['health'] ) {
				return new WP_Error( 'lld_quick', 'Health consent is needed before a log can be saved.' );
			}
			$kind  = isset( $_POST['lld_quick'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_quick'] ) ) : '';
			$value = isset( $_POST['lld_value'] ) ? (int) $_POST['lld_value'] : 1;
			$saved = LLD_Members_Goal_Journey::quick_log( $member_id, $kind, $value );
			if ( $saved['id'] < 1 ) {
				return new WP_Error( 'lld_quick', '' !== $saved['message'] ? $saved['message'] : 'Try again.' );
			}
			return $kind;
		}
		if ( isset( $_POST['lld_reached_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_reached_nonce'] ) ), 'lld_reached' ) ) {
			$choice  = isset( $_POST['lld_next'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_next'] ) ) : '';
			$reached = LLD_Members_Goal_Journey::latest_main_goal( $member_id );
			if ( ! $reached || 'reached' !== (string) $reached['status'] ) {
				return new WP_Error( 'lld_reached', 'There is no reached goal to continue from.' );
			}
			if ( 'keep' === $choice ) {
				$id = LLD_Members_Goal_Journey::keep_it_off( $member_id, $reached, $facts );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				wp_safe_redirect( home_url( '/members/goal/?done=' . (int) $id ) );
				exit;
			}
			if ( 'new' === $choice ) {
				LLD_Members_Stage3_Data::update( 'goals', $member_id, (int) $reached['id'], array( 'status' => 'replaced' ) );
				wp_safe_redirect( home_url( '/members/goal/' ) );
				exit;
			}
			return new WP_Error( 'lld_reached', 'Choose what comes next.' );
		}
		return null;
	}

	/**
	 * The Next step card (FR3-12 AS-01 to AS-03): the engine's step for today, or the plan's item.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function next_step_html( $member_id, array $facts ) {
		$today = (string) $facts['today'];
		$step  = $facts['health'] ? LLD_Members_Goal_Journey::today_step( $member_id, $today, true ) : null;
		$text  = $step ? LLD_Members_Goal_Journey::step_text( $member_id, $step, $today, $facts['situations'] ) : '';
		$type  = $step ? (string) $step['action_type'] : 'activity';
		$rule  = $step && ! empty( $step['rule_id'] ) && class_exists( 'LLD_Members_Rule_Catalogue' ) ? LLD_Members_Rule_Catalogue::name_for_id( (int) $step['rule_id'] ) : '';
		if ( '' === $text ) {
			$rule = ''; // the engine's step is not shown (safety matrix, or no stored numbers): the card is the plan's item
		}
		$status = $step ? (string) $step['status'] : 'shown';
		$source = $step ? ( '' !== $rule ? 'engine' : ( 'read' === $type ? 'generic' : 'plan' ) ) : 'plan';
		if ( '' === $text ) {
			// The plan's scheduled item: the track's activity of the day, else a log.
			$plan_card = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::today_card_html( $member_id ) : '';
			$text      = '' !== $plan_card ? "Today's activity from your track." : ( $facts['health'] ? 'One quick log today, water, a meal or your mood, keeps your week going.' : 'Read something from the library today, or start a track.' );
			$type      = '' !== $plan_card ? 'activity' : ( $facts['health'] ? 'log_food' : 'read' );
		}
		$links = array(
			'log_reading' => array( self::link_or( 'readings', $member_id, '/members/check-in/' ), 'Log a reading' ),
			'log_food'    => array( home_url( '/members/diary/' ), 'Open the diary' ),
			'check_in'    => array( home_url( '/members/check-in/' ), 'Start check-in' ),
			'read'        => array( home_url( '/members/library/' ), 'Open the library' ),
			'activity'    => array( home_url( '/members/track/' ), 'Open the activity' ),
			'workout'     => array( home_url( '/members/workout/' ), 'Open the workout' ),
		);
		$link  = isset( $links[ $type ] ) ? $links[ $type ] : $links['activity'];
		$html  = '<section class="lld-card lld-next-step" data-lld-section="next-step" data-lld-step-status="' . esc_attr( $status ) . '" data-lld-step-source="' . esc_attr( $source ) . '" data-lld-step-rule="' . esc_attr( $rule ) . '" data-lld-step-action="' . esc_attr( $type ) . '">';
		$html .= '<p class="lld-kicker">' . ( 'done' === $status ? 'Done today' : ( 'skipped' === $status ? 'Skipped today' : 'Next step' ) ) . '</p>';
		if ( 'shown' === $status ) {
			$html .= '<h2 class="lld-next-step-text">' . self::rich( $text ) . '</h2>';
			$html .= '<p class="lld-pill-actions"><a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a></p>';
			$html .= '<form method="post" class="lld-row">' . wp_nonce_field( 'lld_today', 'lld_today_nonce', true, false ) . '<input type="hidden" name="lld_action_type" value="' . esc_attr( $type ) . '">';
			$html .= '<button class="lld-button" type="submit" name="lld_step" value="done">Done</button> <button class="lld-button lld-button-secondary" type="submit" name="lld_step" value="skipped">Skip</button></form>';
		} else {
			$html .= '<p class="lld-next-step-text lld-muted">' . self::rich( $text ) . '</p>';
			// After Done, the next activity: the track's item of the day or a quick log (FR3-12 AS-02).
			$plan_card = class_exists( 'LLD_Members_Programme' ) ? LLD_Members_Programme::today_card_html( $member_id ) : '';
			$html     .= '<div class="lld-next-after" data-lld-section="next-activity"><p class="lld-kicker">Next activity</p>';
			if ( '' !== $plan_card && 'activity' !== $type ) {
				$html .= $plan_card;
			} else {
				$html .= '<p>Keep the day going: <a href="' . esc_url( self::link_or( 'food', $member_id, '/members/diary/' ) ) . '">log a meal</a>, a glass of water or your mood below.</p>';
			}
			$html .= '</div>';
		}
		return $html . '</section>';
	}

	/**
	 * This week's ring: days active of 7 (FR3-12 AS-02, AS-04).
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 * @return array{done:int}
	 */
	private static function ring( $member_id, array $facts ) {
		$goal = LLD_Members_Goal_Journey::active_goal( $member_id, 'main' );
		$plan = $goal ? LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] ) : null;
		return LLD_Members_Goal_Rules::weekly_ring( $facts['health'] ? LLD_Members_Goal_Journey::active_dates( $member_id, 14 ) : array(), (string) $facts['today'], $plan && $plan['valid_from'] ? (string) $plan['valid_from'] : null );
	}

	/**
	 * P3-36 D9: after Done or a quick log, the ring's count before it (carried by the redirect), or null. Only for the
	 * same day as the link (ring_on), so a link opened again later, or another day's, never shows a change.
	 *
	 * @param array $facts facts().
	 */
	private static function ring_was( array $facts ) {
		if ( ! isset( $_GET['saved'], $_GET['ring_was'], $_GET['ring_on'] ) || ! is_scalar( $_GET['saved'] ) || ! is_scalar( $_GET['ring_was'] ) || ! is_scalar( $_GET['ring_on'] ) || ! in_array( sanitize_key( (string) wp_unslash( $_GET['saved'] ) ), array( 'done', 'water', 'mood' ), true ) ) {
			return null;
		}
		if ( sanitize_text_field( wp_unslash( (string) $_GET['ring_on'] ) ) !== (string) $facts['today'] ) {
			return null;
		}
		$was = (int) $_GET['ring_was'];
		return $was >= 0 && $was <= 7 ? $was : null;
	}

	/**
	 * The weekly ring (FR3-12 AS-02, AS-04): active days this week, as an SVG ring with the count beside it.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function ring_html( $member_id, array $facts ) {
		$ring  = self::ring( $member_id, $facts );
		$pct   = (int) round( $ring['done'] / 7 * 100 );
		$dash  = 2 * M_PI * 42;
		$was   = self::ring_was( $facts );
		// "Up" only for the one day just added; "already counted" only when today really is among the active days.
		// Both need today among the active days, so a hand-made link can never say today counted when it did not.
		$today_on = null !== $was && $facts['health'] && in_array( (string) $facts['today'], LLD_Members_Goal_Journey::active_dates( $member_id, 14 ), true );
		$rose  = $today_on && $ring['done'] === $was + 1;
		$was   = $rose || ( $today_on && $ring['done'] === $was ) ? $was : null;
		$html  = '<section class="lld-card lld-ring-card' . ( $rose ? ' lld-ring-rose' : '' ) . '" data-lld-section="ring" data-lld-ring-done="' . (int) $ring['done'] . '" data-lld-ring-total="7"' . ( null !== $was ? ' data-lld-ring-was="' . (int) $was . '"' : '' ) . '><h2>This week</h2>';
		$html .= '<div class="lld-ring" role="img" aria-label="' . esc_attr( $ring['done'] . ' of 7 days active this week' ) . '"><svg viewBox="0 0 100 100" width="96" height="96" aria-hidden="true"><circle class="lld-ring-track" cx="50" cy="50" r="42" fill="none" stroke-width="10"/><circle class="lld-ring-fill" cx="50" cy="50" r="42" fill="none" stroke-width="10" stroke-dasharray="' . esc_attr( LLD_Members_Goal_Rules::plain( $dash ) ) . '" stroke-dashoffset="' . esc_attr( LLD_Members_Goal_Rules::plain( $dash * ( 1 - $ring['done'] / 7 ) ) ) . '"' . ( $rose ? ' style="--lld-ring-from:' . esc_attr( LLD_Members_Goal_Rules::plain( $dash * ( 1 - $was / 7 ) ) ) . ';--lld-ring-to:' . esc_attr( LLD_Members_Goal_Rules::plain( $dash * ( 1 - $ring['done'] / 7 ) ) ) . '"' : '' ) . ' transform="rotate(-90 50 50)"/></svg>';
		$html .= '<p class="lld-ring-count"><strong>' . (int) $ring['done'] . ' of 7</strong> days</p></div>';
		// P3-36 D9: say what changed, so the ring visibly answers Done (a day already counted stays counted once).
		if ( null !== $was ) {
			$html .= '<p class="lld-ring-change" role="status" data-lld-ring-change="' . ( $rose ? 'up' : 'same' ) . '">' . esc_html( $rose ? 'Today counts. You are now at ' . (int) $ring['done'] . ' of 7 days.' : 'Today was already counted: ' . (int) $ring['done'] . ' of 7 days.' ) . '</p>';
		}
		$html .= '<p class="lld-hint">A missed day lowers this count. Your long-term progress stays as it is.</p></section>';
		return $html;
	}

	/**
	 * Quick-log tiles (FR3-12): water, meals and mood.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function tiles_html( $member_id, array $facts ) {
		$html = '<section class="lld-card lld-tiles-card" data-lld-section="quick-log"><h2>Quick log</h2>';
		if ( ! $facts['health'] ) {
			return $html . '<p>' . esc_html( 'Quick logs need health consent.' ) . ' <a href="' . esc_url( home_url( '/members/health/' ) ) . '">Health profile</a></p></section>';
		}
		$water = LLD_Members_Goal_Journey::daily( $member_id, 'water' );
		$ml    = isset( $water[ $facts['today'] ] ) ? (float) $water[ $facts['today'] ] : 0.0;
		$mood  = LLD_Members_Goal_Journey::daily( $member_id, 'mood' );
		$html .= '<div class="lld-tiles">';
		$html .= '<form method="post" class="lld-tile" data-lld-tile="water">' . wp_nonce_field( 'lld_quick', 'lld_quick_nonce', true, false ) . '<input type="hidden" name="lld_quick" value="water"><input type="hidden" name="lld_value" value="1">';
		$html .= '<button type="submit" class="lld-tile-button">' . lld_members_topic_icon( 'droplet', 'blue' ) . '<span class="lld-tile-label">Water</span><span class="lld-tile-value">' . (int) round( $ml / LLD_Members_Goal_Journey::GLASS_ML ) . ' glasses</span><span class="lld-tile-action">+1 glass</span></button></form>';
		$html .= '<a class="lld-tile lld-tile-link" data-lld-tile="meals" href="' . esc_url( self::link_or( 'food', $member_id, '/members/diary/' ) ) . '">' . lld_members_topic_icon( 'leaf', 'teal' ) . '<span class="lld-tile-label">Meals</span><span class="lld-tile-action">Log a meal</span></a>';
		$html .= '<form method="post" class="lld-tile" data-lld-tile="mood">' . wp_nonce_field( 'lld_quick', 'lld_quick_nonce', true, false ) . '<input type="hidden" name="lld_quick" value="mood">' . lld_members_topic_icon( 'hearts', 'pink' ) . '<span class="lld-tile-label">Mood' . ( isset( $mood[ $facts['today'] ] ) ? ' · ' . esc_html( LLD_Members_Goal_Rules::plain( (float) $mood[ $facts['today'] ] ) ) . ' of 5' : '' ) . '</span><span class="lld-mood-row">';
		foreach ( array( 1 => 'Low', 2 => 'Down', 3 => 'OK', 4 => 'Good', 5 => 'Great' ) as $n => $label ) {
			$html .= '<button type="submit" name="lld_value" value="' . $n . '" class="lld-mood-button" aria-label="' . esc_attr( 'Mood ' . $n . ' of 5, ' . $label ) . '">' . $n . '</button>';
		}
		$html .= '</span></form></div></section>';
		return $html;
	}

	/**
	 * Goal progress (FR3-13): done of total, per cent, on track or behind, for Free and Premium alike.
	 *
	 * @param int        $member_id Member number.
	 * @param array      $facts     facts().
	 * @param array|null $goal      Main goal.
	 */
	private static function goal_card_html( $member_id, array $facts, $goal ) {
		$html = '<section class="lld-card" data-lld-section="goal-progress">';
		if ( ! $goal ) {
			$html .= '<h2>Your goal</h2>';
			if ( $facts['health'] && LLD_Members_Features::enabled( 'goals', $member_id ) ) {
				$html .= '<p>' . lld_button( array( 'label' => 'Set a goal', 'href' => home_url( '/members/goal/' ) ) ) . '</p>';
			} else {
				$html .= '<p>The articles, calculators and tracks are all here for you. <a href="' . esc_url( home_url( '/members/health/' ) ) . '">Health profile</a></p>';
			}
			return $html . '</section>';
		}
		$html .= self::progress_block( $member_id, $goal, $facts, 'Main goal' );
		$extra = LLD_Members_Goal_Journey::active_goal( $member_id, 'extra' );
		if ( $extra ) {
			$html .= self::progress_block( $member_id, $extra, $facts, 'Extra goal' );
		}
		$html .= '<p><a href="' . esc_url( home_url( '/members/progress/' ) ) . '">Progress</a> · <a href="' . esc_url( home_url( '/members/check-in/' ) ) . '">Weekly check-in</a>' . ( $extra ? '' : ' · <a href="' . esc_url( home_url( '/members/goal/?role=extra' ) ) . '">Add an extra goal</a>' ) . '</p>';
		return $html . '</section>';
	}

	/**
	 * One goal's progress lines.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $goal      Goal row.
	 * @param array  $facts     facts().
	 * @param string $kicker    Label.
	 */
	public static function progress_block( $member_id, array $goal, array $facts, $kicker ) {
		$p     = LLD_Members_Goal_Journey::progress( $member_id, $goal, $facts );
		$imp   = ! empty( $facts['imperial'] );
		$html  = '<div class="lld-goal-progress" data-lld-goal-progress data-lld-goal="' . (int) $goal['id'] . '" data-lld-goal-type="' . esc_attr( (string) $goal['goal_type'] ) . '" data-lld-trend="' . esc_attr( $p['trend']['status'] ) . '">';
		$html .= '<p class="lld-kicker">' . esc_html( $kicker ) . '</p><h2>' . esc_html( LLD_Members_Goal_Setting::goal_line( $goal, $facts ) ) . '</h2>';
		if ( $p['progress'] && null !== $p['progress']['pct'] ) {
			$unit  = self::unit_label( (string) $goal['measure'], $imp );
			$done  = 'weight' === (string) $goal['measure'] && $imp ? LLD_Members_Goal_Rules::round1( $p['progress']['done'] / 0.45359237 ) : $p['progress']['done'];
			$total = 'weight' === (string) $goal['measure'] && $imp ? LLD_Members_Goal_Rules::round1( $p['progress']['total'] / 0.45359237 ) : $p['progress']['total'];
			$html .= '<p class="lld-stat" data-lld-progress="' . esc_attr( LLD_Members_Goal_Rules::plain( $p['progress']['done'] ) . '/' . LLD_Members_Goal_Rules::plain( $p['progress']['total'] ) ) . '" data-lld-pct="' . (int) $p['progress']['pct'] . '"><strong>' . esc_html( LLD_Members_Goal_Rules::plain( $done ) . ' of ' . LLD_Members_Goal_Rules::plain( $total ) . ' ' . $unit ) . '</strong>, ' . (int) $p['progress']['pct'] . ' %</p>';
			$html .= lld_progress( (int) $p['progress']['pct'], 100 );
		} elseif ( null === $p['latest'] ) {
			$html .= '<p class="lld-hint">Add your first measurement and your progress appears here. <a href="' . esc_url( home_url( '/members/check-in/' ) ) . '">Log it now</a></p>';
		} elseif ( $p['progress'] && 'hold' === $p['direction'] ) {
			$html .= '<p>Now ' . esc_html( LLD_Members_Goal_Rules::show_weight( $p['latest'], $imp ) ) . ', ' . esc_html( ( $p['progress']['remaining'] >= 0 ? '+' : '' ) . LLD_Members_Goal_Rules::plain( $imp ? LLD_Members_Goal_Rules::round1( $p['progress']['remaining'] / 0.45359237 ) : $p['progress']['remaining'] ) . ( $imp ? ' lb' : ' kg' ) ) . ' from your goal weight.</p>';
		}
		$t = $p['trend'];
		if ( 'on_track' === $t['status'] ) {
			$html .= '<p class="lld-pill lld-pill-good">' . esc_html( LLD_Members_Goal_Rules::MESSAGES['on_track'] ) . '</p>';
		} elseif ( 'behind' === $t['status'] ) {
			$html .= '<p class="lld-pill lld-pill-behind">' . esc_html( LLD_Members_Goal_Rules::MESSAGES['behind'] ) . '</p>';
		} elseif ( 'waiting' === $t['status'] && 'weight' === (string) $goal['measure'] ) {
			$html .= '<p class="lld-hint">' . esc_html( LLD_Members_Goal_Rules::MESSAGES['trend_wait'] ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * The insights shown in the last 7 days (Premium; FR3-17), with the plan offers waiting at the check-in.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function insights_html( $member_id, array $facts ) {
		if ( ! $facts['health'] || ! class_exists( 'LLD_Members_Rule_Catalogue' ) ) {
			return '';
		}
		$html    = '';
		$pending = LLD_Members_Goal_Journey::pending_adjustments( $member_id, $facts['situations'] );
		if ( $pending ) {
			$html .= '<section class="lld-card" data-lld-section="adjustment"><h2>Your plan</h2><p>' . self::rich( $pending[0]['text'] ) . '</p><p>' . lld_button( array( 'label' => 'See the options', 'href' => home_url( '/members/check-in/' ) ) ) . '</p></section>';
		}
		if ( ! $facts['premium'] ) {
			return $html;
		}
		$rules = LLD_Members_Rule_Catalogue::rules();
		$items = array();
		foreach ( LLD_Members_Stage3_Data::rows( 'insights', $member_id, array( 'values' ), '', 50 ) as $ins ) {
			$name = LLD_Members_Rule_Catalogue::name_for_id( (int) $ins['rule_id'] );
			if ( '' === $name || ! empty( $ins['dismissed'] ) || ! in_array( $rules[ $name ]['class'], array( LLD_Members_Rule_Catalogue::CLASS_INSIGHT, LLD_Members_Rule_Catalogue::CLASS_SAFETY ), true ) ) {
				continue;
			}
			if ( LLD_Members_Goal_Rules::days_between( substr( (string) $ins['shown_at'], 0, 10 ), (string) $facts['today'] ) > 7 ) {
				continue;
			}
			// The safety matrix again at display time (FR3-69, P3-03 D4): the row was checked when it was written, and
			// the member may have declared an eating disorder, pregnancy or kidney disease since.
			if ( ! LLD_Members_Goal_Journey::rule_allowed( $facts['situations'], $name ) ) {
				continue;
			}
			$values  = json_decode( (string) $ins['values'], true );
			$items[] = array( 'rule' => $name, 'text' => (string) LLD_Members_Rule_Catalogue::fill( (string) $rules[ $name ]['template'], is_array( $values ) ? $values : array() ) );
			if ( count( $items ) >= 3 ) {
				break;
			}
		}
		if ( $items ) {
			$html .= '<section class="lld-card" data-lld-section="insights"><h2>Insights</h2>';
			foreach ( $items as $it ) {
				$html .= '<p class="lld-insight" data-lld-rule="' . esc_attr( $it['rule'] ) . '">' . self::rich( $it['text'] ) . '</p>';
			}
			$html .= '</section>';
		}
		return $html;
	}

	/**
	 * The Goal reached screen (FR3-15): a celebration, then Keep it off or Set a new goal.
	 *
	 * @param int    $member_id Member number.
	 * @param array  $goal      The reached goal.
	 * @param array  $facts     facts().
	 * @param string $error     Error text.
	 */
	private static function reached_html( $member_id, array $goal, array $facts, $error ) {
		$html  = '<div class="lld-stage3-today lld-goal-reached" data-lld-section="goal-reached"><h1>Goal reached</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		$html .= '<section class="lld-card"><p class="lld-kicker">Well done</p><h2>' . esc_html( "You've reached your goal: " . LLD_Members_Goal_Setting::goal_line( $goal, $facts ) ) . '</h2>';
		$html .= '<p>That took steady work, week after week. What comes next is up to you.</p>';
		$weight = 'weight' === (string) $goal['measure'];
		$html  .= '<form method="post" class="lld-row">' . wp_nonce_field( 'lld_reached', 'lld_reached_nonce', true, false );
		if ( $weight ) {
			$html .= '<button class="lld-button" type="submit" name="lld_next" value="keep">Keep it off</button> ';
		}
		$html .= '<button class="lld-button' . ( $weight ? ' lld-button-secondary' : '' ) . '" type="submit" name="lld_next" value="new">Set a new goal</button></form>';
		$html .= '<p class="lld-hint">' . ( $weight ? 'Keep it off holds your result with a goal to stay steady' . ( class_exists( 'LLD_Members_Programme' ) && LLD_Members_Programme::track_exists( 'keep-it-off' ) ? ' and the Keep It Off track' : '' ) . '. ' : '' ) . 'Set a new goal starts the goal screen again.</p></section></div>';
		return $html;
	}

	// ---- Dashboard (FR3-18) --------------------------------------------------------------------------------------------

	/**
	 * The dashboard sections: today's totals, the latest readings, the current track and week, the plan, the trends.
	 * Free members see the last 14 days with an invitation to upgrade (AS-03).
	 *
	 * @param int        $member_id Member number.
	 * @param array      $facts     facts().
	 * @param array|null $goal      Main goal.
	 */
	private static function dashboard_html( $member_id, array $facts, $goal ) {
		$today = (string) $facts['today'];
		$days  = $facts['premium'] ? 28 : 14;
		$html  = '<div class="lld-dash-pair">';
		// Today's totals from the food log (FR3-18 AS-01).
		$html .= '<section class="lld-card" data-lld-section="totals"><h2>Today\'s totals</h2>';
		if ( $facts['health'] && class_exists( 'LLD_Members_Food_Log' ) ) {
			$entries = array();
			foreach ( LLD_Members_Stage3_Data::rows( 'food_log', $member_id, array(), '', 500 ) as $e ) {
				if ( (string) $e['entry_date'] === $today && empty( $e['deleted_at'] ) ) {
					$entries[] = $e;
				}
			}
			$day    = LLD_Members_Food_Log::day( $entries, $today, null );
			$ed     = in_array( 'eating_disorder', $facts['situations'], true );
			$kcal   = isset( $day['totals']['kcal'] ) ? $day['totals']['kcal'] : 0;
			$meals  = 0;
			foreach ( (array) $day['meals'] as $list ) {
				$meals += count( (array) $list );
			}
			$html .= '<p data-lld-meals="' . (int) $meals . '">' . (int) $meals . ' ' . ( 1 === $meals ? 'item' : 'items' ) . ' logged today</p>';
			if ( ! $ed ) {
				$html .= '<p class="lld-stat" data-lld-kcal-today="' . esc_attr( LLD_Members_Goal_Rules::plain( (float) $kcal ) ) . '"><strong>' . esc_html( number_format( (float) $kcal ) ) . ' kcal</strong>' . ( isset( $day['totals']['protein_g'] ) ? ', protein ' . esc_html( LLD_Members_Goal_Rules::plain( LLD_Members_Goal_Rules::round1( (float) $day['totals']['protein_g'] ) ) ) . ' g' : '' ) . '</p>';
			}
		} else {
			$html .= '<p>Log meals in the food diary to see today\'s totals.</p>';
		}
		$html .= '<p><a href="' . esc_url( self::link_or( 'food', $member_id, '/members/diary/' ) ) . '">Food diary</a></p></section>';
		// Latest readings.
		$html .= '<section class="lld-card" data-lld-section="readings"><h2>Latest readings</h2><ul class="lld-readings">';
		$any   = false;
		foreach ( array( 'weight' => 'Weight', 'waist' => 'Waist', 'blood_pressure' => 'Blood pressure' ) as $type => $label ) {
			$daily = $facts['health'] ? LLD_Members_Goal_Journey::daily( $member_id, $type ) : array();
			if ( ! $daily ) {
				continue;
			}
			$any  = true;
			$date = (string) array_key_last( $daily );
			$v    = $daily[ $date ];
			$html .= '<li data-lld-reading="' . esc_attr( $type ) . '"><span>' . esc_html( $label ) . '</span> <strong>' . esc_html( self::show_value( $type, $v, $facts['imperial'] ) ) . '</strong> <small>' . esc_html( LLD_Members_Goal_Rules::nice_date( $date ) ) . '</small></li>';
		}
		$html .= $any ? '</ul>' : '</ul><p>No readings yet. <a href="' . esc_url( self::link_or( 'readings', $member_id, '/members/check-in/' ) ) . '">Log one</a></p>';
		$html .= '</section></div>';
		// The current track and week (AS-02), as the programme page says it.
		$html .= '<section class="lld-card" data-lld-section="track"><h2>Your track</h2>';
		if ( class_exists( 'LLD_Members_Programme' ) ) {
			$active = LLD_Members_Programme::active( $member_id );
			if ( $active ) {
				$html .= '<p class="lld-pill" data-lld-track-position>' . esc_html( LLD_Members_Programme::track_title( (string) $active->track_key ) . ' · ' . LLD_Members_Programme::position_label( $member_id ) ) . '</p>';
				$html .= '<p><a href="' . esc_url( home_url( '/members/track/' ) ) . '">Open this week</a></p>';
			} else {
				$html .= '<p>No track yet. <a href="' . esc_url( home_url( '/members/tracks/' ) ) . '">Start a track</a></p>';
			}
		}
		$html .= '</section>';
		// The plan (FR3-11).
		if ( $goal ) {
			$plan  = LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] );
			$html .= '<section class="lld-card" data-lld-section="plan"><h2>Your plan</h2>';
			if ( $plan ) {
				$html .= '<p>Version ' . (int) $plan['version'] . ', from ' . esc_html( LLD_Members_Goal_Rules::nice_date( (string) $plan['valid_from'] ) ) . '.</p><ul>';
				if ( $facts['premium'] && is_numeric( $plan['calorie_target'] ) && ! in_array( 'eating_disorder', $facts['situations'], true ) ) {
					$html .= '<li>About ' . esc_html( number_format( (int) $plan['calorie_target'] ) ) . ' kcal a day</li>';
				}
				$html .= '<li>' . (int) $plan['activity_minutes_week'] . ' minutes of activity a week</li></ul>';
			}
			$html .= '<p><a href="' . esc_url( home_url( '/members/goal/?done=' . (int) $goal['id'] ) ) . '">See the plan</a></p></section>';
		}
		// Trends, 14 days for Free (AS-03), 28 for Premium.
		$html .= '<section class="lld-card" data-lld-section="trends" data-lld-days="' . (int) $days . '"><h2>Trends</h2><p class="lld-hint">The last ' . (int) $days . ' days.</p>';
		$html .= self::trend_lines( $member_id, $facts, $days );
		if ( ! $facts['premium'] ) {
			$html .= lld_membership_lock( 'Premium shows your full history and your insights.' );
		}
		$html .= '<p><a href="' . esc_url( home_url( '/members/progress/' ) ) . '">Progress page</a></p></section>';
		return $html;
	}

	/**
	 * One line per trend: first, last and change over the window.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 * @param int   $days      Window.
	 */
	public static function trend_lines( $member_id, array $facts, $days ) {
		if ( ! $facts['health'] ) {
			return '<p>Trends need health consent.</p>';
		}
		$html = '<ul class="lld-trends">';
		$any  = false;
		foreach ( array( 'weight' => 'Weight', 'waist' => 'Waist', 'blood_pressure' => 'Blood pressure', 'activity' => 'Activity' ) as $type => $label ) {
			$daily = LLD_Members_Goal_Journey::daily( $member_id, $type );
			$t     = LLD_Members_Goal_Rules::trend( $daily, (string) $facts['today'], $days );
			if ( ! $t['rows'] ) {
				continue;
			}
			$any   = true;
			$html .= '<li data-lld-trend="' . esc_attr( $type ) . '" data-lld-points="' . count( $t['rows'] ) . '"><span>' . esc_html( $label ) . '</span> <strong>' . esc_html( self::show_value( $type, $t['last'], $facts['imperial'] ) ) . '</strong>';
			if ( count( $t['rows'] ) > 1 && null !== $t['change'] ) {
				$html .= ' <small>' . esc_html( ( $t['change'] > 0 ? '+' : '' ) . self::show_value( $type, $t['change'], $facts['imperial'] ) . ' over ' . count( $t['rows'] ) . ' days' ) . '</small>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>' . ( $any ? '' : '<p>Nothing logged in this window yet.</p>' );
	}

	// ---- Weekly check-in (FR3-14) --------------------------------------------------------------------------------------

	/**
	 * The weekly check-in: this week's measurements, the trend, and the engine's offers to adjust the plan.
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function check_in( $member_id, array $facts ) {
		$today = (string) $facts['today'];
		$goal  = LLD_Members_Goal_Journey::active_goal( $member_id, 'main' );
		$error = '';
		$notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( isset( $_POST['lld_checkin3_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_checkin3_nonce'] ) ), 'lld_checkin3' ) ) {
				$got = self::save_check_in( $member_id, $goal, $facts );
				if ( is_wp_error( $got ) ) {
					$error = $got->get_error_message();
				} else {
					wp_safe_redirect( home_url( '/members/check-in/?done=1' . ( '' !== $got ? '&level=' . rawurlencode( $got ) : '' ) ) );
					exit;
				}
			} elseif ( isset( $_POST['lld_adjust_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lld_adjust_nonce'] ) ), 'lld_adjust' ) ) {
				$choice = isset( $_POST['lld_choice'] ) ? sanitize_key( (string) wp_unslash( $_POST['lld_choice'] ) ) : '';
				$got    = LLD_Members_Goal_Journey::answer_adjustment( $member_id, isset( $_POST['lld_adjust'] ) ? (int) $_POST['lld_adjust'] : 0, $choice, $facts );
				if ( is_wp_error( $got ) ) {
					$error = $got->get_error_message();
				} else {
					wp_safe_redirect( home_url( '/members/check-in/?adjusted=' . ( 'keep' === $choice ? 'kept' : 'changed' ) ) );
					exit;
				}
			}
		}
		if ( isset( $_GET['done'] ) ) {
			$notice = 'Check-in saved.';
			if ( isset( $_GET['level'] ) && 'urgent' === $_GET['level'] ) {
				$notice = '';
			}
		} elseif ( isset( $_GET['adjusted'] ) ) {
			$notice = 'changed' === $_GET['adjusted'] ? 'Your plan has been adjusted.' : 'Your plan stays as it is.';
		}
		$html = '<div class="lld-stage3-today lld-stage3-checkin" data-lld-premium="' . ( $facts['premium'] ? 'yes' : 'no' ) . '"><h1>Weekly check-in</h1>';
		if ( '' !== $error ) {
			$html .= '<div class="lld-error-box" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $notice ) {
			$html .= '<div class="lld-notice-box lld-state-saved" role="status"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( isset( $_GET['level'] ) && in_array( $_GET['level'], array( 'urgent', 'check', 'caution' ), true ) ) {
			$html .= self::level_html( sanitize_key( (string) wp_unslash( $_GET['level'] ) ) );
		}
		$html .= '<section class="lld-card" data-lld-section="goal-progress">' . self::progress_block( $member_id, $goal, $facts, 'Main goal' );
		if ( ! $facts['premium'] ) {
			// FR3-14 AS-03: a Free member sees the check-in result; adjustments are Premium.
			$trend = LLD_Members_Goal_Journey::progress( $member_id, $goal, $facts );
			if ( 'behind' === $trend['trend']['status'] ) {
				$html .= lld_membership_lock( 'Premium offers an adjusted plan when you fall behind: a later date, an easier week or a different meal plan.' );
			}
		}
		$html .= '</section>';

		// The engine's offers (FR3-14 AS-01): accepted only by the member; adjustments are Premium (AS-03).
		foreach ( LLD_Members_Goal_Journey::pending_adjustments( $member_id, $facts['situations'] ) as $adj ) {
			$html .= '<section class="lld-card lld-adjustment" data-lld-section="adjustment" data-lld-rule="' . esc_attr( $adj['rule'] ) . '"><h2>Adjust the plan?</h2><p>' . self::rich( $adj['text'] ) . '</p>';
			$html .= '<form method="post" class="lld-row">' . wp_nonce_field( 'lld_adjust', 'lld_adjust_nonce', true, false ) . '<input type="hidden" name="lld_adjust" value="' . (int) $adj['id'] . '">';
			$labels = array( 'later_date' => 'Move my date', 'easier_activity_25pct_one_week' => 'Easier activity this week', 'different_meal_plan' => 'A different meal plan' );
			$values = array( 'later_date' => 'later_date', 'easier_activity_25pct_one_week' => 'easier_activity', 'different_meal_plan' => 'different_meal_plan' );
			if ( $facts['premium'] ) {
				foreach ( $adj['offers'] as $offer ) {
					if ( isset( $labels[ $offer ] ) ) {
						$html .= '<button class="lld-button" type="submit" name="lld_choice" value="' . esc_attr( $values[ $offer ] ) . '">' . esc_html( $labels[ $offer ] ) . '</button> ';
					}
				}
			}
			$html .= '<button class="lld-button lld-button-secondary" type="submit" name="lld_choice" value="keep">Keep my plan</button></form>';
			if ( ! $facts['premium'] ) {
				$html .= lld_membership_lock( 'Plan adjustments are part of Premium.' );
			}
			$html .= '</section>';
		}

		// This week's measurements for the goal.
		$measures = self::measures_for( $goal );
		$imp      = ! empty( $facts['imperial'] );
		$due      = LLD_Members_Goal_Journey::check_in_due( $member_id, $today );
		$html    .= '<section class="lld-card" data-lld-section="measurements" data-lld-check-in-due="' . ( $due ? 'yes' : 'no' ) . '"><h2>This week</h2>';
		$html    .= '<form method="post" class="lld-form">' . wp_nonce_field( 'lld_checkin3', 'lld_checkin3_nonce', true, false );
		foreach ( $measures as $m ) {
			if ( 'weight' === $m ) {
				$html .= '<div class="lld-field"><label for="lld_weight">Weight in ' . ( $imp ? 'pounds' : 'kilograms' ) . '</label><input id="lld_weight" name="lld_weight" type="number" inputmode="decimal" step="any"></div>';
			} elseif ( 'waist' === $m ) {
				$html .= '<div class="lld-field"><label for="lld_waist">Waist in ' . ( $imp ? 'inches' : 'centimetres' ) . '</label><input id="lld_waist" name="lld_waist" type="number" inputmode="decimal" step="any"></div>';
			} elseif ( 'blood_pressure' === $m ) {
				$html .= '<div class="lld-field-row"><div class="lld-field"><label for="lld_sys">Blood pressure: top number</label><input id="lld_sys" name="lld_sys" type="number" inputmode="numeric" step="1"></div><div class="lld-field"><label for="lld_dia">Bottom number</label><input id="lld_dia" name="lld_dia" type="number" inputmode="numeric" step="1"></div></div>';
			} elseif ( 'activity' === $m ) {
				$html .= '<div class="lld-field"><label for="lld_activity">Minutes of activity today</label><input id="lld_activity" name="lld_activity" type="number" inputmode="numeric" step="5"></div>';
			} elseif ( 'sleep' === $m ) {
				$html .= '<div class="lld-field"><label for="lld_sleep">Hours slept last night</label><input id="lld_sleep" name="lld_sleep" type="number" inputmode="decimal" step="any"></div>';
			} elseif ( 'glucose' === $m ) {
				$html .= '<div class="lld-field"><label for="lld_glucose">Fasting glucose, mmol/L</label><input id="lld_glucose" name="lld_glucose" type="number" inputmode="decimal" step="any"></div>';
			}
		}
		$html .= '<p class="lld-hint">Leave a box empty to skip it this week.</p>';
		$html .= '<button class="lld-button" type="submit">' . ( $due ? 'Finish check-in' : 'Save' ) . '</button></form></section>';

		// The Stage 2 track check-in, untouched, for members on a track.
		if ( class_exists( 'LLD_Members_Programme' ) && LLD_Members_Programme::active( $member_id ) ) {
			$html .= '<section class="lld-card" data-lld-section="track-check-in"><h2>Track check-in</h2>' . LLD_Members_Programme::check_in_html( $member_id ) . '</section>';
		}
		$html .= '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Back to Today</a></p></div>';
		self::output( 'Weekly check-in', $html );
	}

	/**
	 * Save this week's measurements as readings, mark the check-in done and run the engine at the check-in
	 * (R-ADJ rules), so an offer can be shown at once.
	 *
	 * @param int   $member_id Member number.
	 * @param array $goal      Main goal.
	 * @param array $facts     facts().
	 * @return string|WP_Error The highest warning level met, or ''.
	 */
	private static function save_check_in( $member_id, array $goal, array $facts ) {
		if ( ! $facts['health'] ) {
			return new WP_Error( 'lld_check', 'Health consent is needed first.' );
		}
		$imp   = ! empty( $facts['imperial'] );
		$num   = function ( $k ) {
			if ( ! isset( $_POST[ $k ] ) ) {
				return null;
			}
			$raw = str_replace( ',', '.', trim( sanitize_text_field( (string) wp_unslash( $_POST[ $k ] ) ) ) );
			return '' !== $raw && is_numeric( $raw ) ? (float) $raw : null;
		};
		$level = '';
		$rank  = array( '' => 0, 'none' => 0, 'caution' => 1, 'check' => 2, 'urgent' => 3 );
		$saved = array();
		if ( null !== $num( 'lld_weight' ) ) {
			$saved[] = LLD_Members_Goal_Journey::save_reading( $member_id, 'weight', array( 'value_1' => LLD_Members_Goal_Rules::to_kg( $num( 'lld_weight' ), $imp ) ), $imp ? 'lb' : 'kg', 'manual', $facts );
		}
		if ( null !== $num( 'lld_waist' ) ) {
			$saved[] = LLD_Members_Goal_Journey::save_reading( $member_id, 'waist', array( 'value_1' => LLD_Members_Goal_Rules::to_cm( $num( 'lld_waist' ), $imp ) ), $imp ? 'in' : 'cm' );
		}
		if ( null !== $num( 'lld_sys' ) && null !== $num( 'lld_dia' ) ) {
			if ( $num( 'lld_dia' ) >= $num( 'lld_sys' ) ) {
				return new WP_Error( 'lld_check', 'The bottom number must be lower than the top number.' );
			}
			$saved[] = LLD_Members_Goal_Journey::save_reading( $member_id, 'blood_pressure', array( 'value_1' => $num( 'lld_sys' ), 'value_2' => $num( 'lld_dia' ) ), '', 'manual', $facts );
		}
		if ( null !== $num( 'lld_activity' ) ) {
			$saved[] = LLD_Members_Goal_Journey::save_reading( $member_id, 'activity', array( 'value_1' => $num( 'lld_activity' ) ) );
		}
		if ( null !== $num( 'lld_sleep' ) ) {
			$saved[] = LLD_Members_Goal_Journey::save_reading( $member_id, 'sleep', array( 'value_1' => $num( 'lld_sleep' ) ) );
		}
		if ( null !== $num( 'lld_glucose' ) ) {
			$saved[] = LLD_Members_Goal_Journey::save_reading( $member_id, 'glucose', array( 'value_1' => $num( 'lld_glucose' ) ), 'mmol/L', 'manual', $facts );
		}
		foreach ( $saved as $s ) {
			if ( 'impossible' === $s['level'] ) {
				return new WP_Error( 'lld_check', 'Check the numbers and try again.' );
			}
			if ( $rank[ $s['level'] ] > $rank[ $level ] ) {
				$level = $s['level'];
			}
		}
		LLD_Members_Goal_Journey::record_check_in( $member_id, (string) $facts['today'] );
		if ( LLD_Members_Goal_Journey::check_reached( $member_id, $goal, $facts ) ) {
			wp_safe_redirect( home_url( '/members/home/' ) ); // the Goal reached screen
			exit;
		}
		if ( class_exists( 'LLD_Members_Insights_Runner' ) ) {
			LLD_Members_Insights_Runner::run_member( $member_id, (string) $facts['today'], array( 'at_check_in' => true ) );
		}
		return $level;
	}

	/** What the check-in asks for, from the goal's measure. */
	private static function measures_for( array $goal ) {
		$by = array(
			'weight'           => array( 'weight', 'waist', 'blood_pressure' ),
			'blood_pressure'   => array( 'blood_pressure', 'weight' ),
			'glucose'          => array( 'glucose', 'weight', 'waist' ),
			'activity_minutes' => array( 'activity', 'weight' ),
			'reps'             => array( 'activity', 'weight' ),
			'sleep_hours'      => array( 'sleep', 'weight' ),
		);
		$m  = (string) $goal['measure'];
		return isset( $by[ $m ] ) ? $by[ $m ] : array( 'weight' );
	}

	/** The matrix's warning after a reading: the frozen urgent-care wording for urgent, a gentler line otherwise. */
	private static function level_html( $level ) {
		if ( 'urgent' === $level ) {
			// The Stage 2 emergency wording (LLD_Members_Personal::emergency_html), verbatim; the numeric levels and
			// their own wording belong to P3-51.
			return lld_safety_banner( 'If you are in danger now, call your local emergency number', 'Examples: 999 UK, 911 US and Canada, 112 EU and India, 000 Australia. That reading needs checking today: contact your doctor or an urgent care service.', '<p><a href="https://findahelpline.com">Find support in your country</a></p>' );
		}
		return lld_caution( 'check' === $level ? 'Please check that reading. If it is right and unusual for you, talk to your doctor.' : 'That reading is a little outside the usual range. Keep logging and mention it to your doctor if it continues.' );
	}

	// ---- Helpers -----------------------------------------------------------------------------------------------------

	/**
	 * P3-36 B3: the new Food page or Log a reading page, when that page is built and on for the member: its screen class
	 * is loaded and its switch (food_tracker, health_logging) is enabled for them. '' otherwise.
	 *
	 * @param string $page      food or readings.
	 * @param int    $member_id Member number.
	 */
	public static function new_page( $page, $member_id ) {
		$pages = array(
			'food'     => array( 'LLD_Members_Food_Screen', 'food_tracker', '/members/food/' ),
			'readings' => array( 'LLD_Members_Readings_Screen', 'health_logging', '/members/readings/' ),
		);
		if ( ! isset( $pages[ $page ] ) || ! class_exists( $pages[ $page ][0] ) || ! class_exists( 'LLD_Members_Features' ) || ! LLD_Members_Features::enabled( $pages[ $page ][1], (int) $member_id ) ) {
			return '';
		}
		return home_url( $pages[ $page ][2] );
	}

	/**
	 * P3-36 B3: Today's link to that page, or the address it has always had while the page is not there.
	 *
	 * @param string $page      food or readings.
	 * @param int    $member_id Member number.
	 * @param string $fallback  Current address, for example /members/diary/.
	 */
	private static function link_or( $page, $member_id, $fallback ) {
		$url = self::new_page( $page, $member_id );
		return '' !== $url ? $url : home_url( $fallback );
	}

	/** A template's **bold** marks as <strong>, its [buttons] removed, everything else escaped. */
	public static function rich( $text ) {
		$text = preg_replace( '/\s*\[[^\]]+\]/', '', (string) $text );
		$safe = esc_html( trim( (string) $text ) );
		return preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe );
	}

	private static function unit_label( $measure, $imperial ) {
		$units = array( 'weight' => $imperial ? 'lb' : 'kg', 'waist' => $imperial ? 'in' : 'cm', 'blood_pressure' => 'mmHg', 'glucose' => 'mmol/L', 'activity_minutes' => 'minutes', 'sleep_hours' => 'hours', 'reps' => 'push-ups', 'hba1c' => '%' );
		return isset( $units[ $measure ] ) ? $units[ $measure ] : '';
	}

	/** A daily value as the member reads it. */
	public static function show_value( $type, $value, $imperial ) {
		if ( 'blood_pressure' === $type ) {
			return is_array( $value ) ? (int) round( $value[0] ) . '/' . (int) round( $value[1] ) : (int) round( (float) $value );
		}
		if ( 'weight' === $type ) {
			return LLD_Members_Goal_Rules::show_weight( $value, $imperial );
		}
		if ( 'waist' === $type ) {
			return LLD_Members_Goal_Rules::show_length( $value, $imperial );
		}
		if ( 'activity' === $type ) {
			return (int) round( (float) $value ) . ' min';
		}
		return LLD_Members_Goal_Rules::plain( LLD_Members_Goal_Rules::round1( (float) $value ) );
	}

	/**
	 * Print the page in the Stage 2 layout with the Stage 3 stylesheet.
	 *
	 * @param string $title Title.
	 * @param string $main  Main HTML.
	 */
	private static function output( $title, $main ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		ob_start();
		lld_members_layout( array( 'title' => $title, 'main' => $main, 'signed_in' => true, 'wide' => true ) );
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
