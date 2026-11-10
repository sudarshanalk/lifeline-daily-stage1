<?php
/**
 * Progress page (P3-33 · FR3-16): /members/progress/.
 *
 * Streaks, weekly bars and badge pictures with "Show as table" kept for accessibility, and the health trends
 * (weight, waist, blood pressure) next to activity. Badges reward habits, never kilograms lost (AS-04). Takes the
 * request at template_redirect priority 5 for members the `progress` switch applies to; the address exists only
 * through LLD_Members_Goal_Journey's rewrite rule, so with the switch off nothing changes (NFR3-06).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Progress page controller and renderer.
 */
class LLD_Members_Progress_Page {

	const HANDLE = 'lld-members-stage3';

	/**
	 * Called by the Stage 3 screens loader. Hooks nothing while the switch is off for everyone.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'progress' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * Renders /members/progress/ for a signed-in member the switch applies to.
	 */
	public static function maybe_render() {
		if ( 'progress' !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! class_exists( 'LLD_Members_Access' ) || LLD_Members_Access::current_member_id() < 1 ) {
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/progress/' ) ) ) );
			exit;
		}
		$member_id = (int) LLD_Members_Access::current_member_id();
		if ( ! LLD_Members_Features::enabled( 'progress', $member_id ) || '' !== LLD_Members_Access::preview_state() ) {
			return;
		}
		self::render( $member_id );
		exit;
	}

	/**
	 * The page.
	 *
	 * @param int $member_id Member number.
	 */
	public static function render( $member_id ) {
		$facts = LLD_Members_Goal_Journey::facts( $member_id );
		$today = (string) $facts['today'];
		$days  = $facts['premium'] ? 28 : 14;
		$goal  = LLD_Members_Goal_Journey::active_goal( $member_id, 'main' );
		$plan  = $goal ? LLD_Members_Goal_Journey::plan( $member_id, (int) $goal['id'] ) : null;
		$dates = $facts['health'] ? LLD_Members_Goal_Journey::active_dates( $member_id, 42 ) : array();
		$streak = LLD_Members_Goal_Rules::streak( $dates, $today );
		$bars   = LLD_Members_Goal_Rules::weekly_bars( $dates, $today, 4, $plan && $plan['valid_from'] ? (string) $plan['valid_from'] : null );

		$html  = '<div class="lld-stage3-progress lld-dash" data-lld-premium="' . ( $facts['premium'] ? 'yes' : 'no' ) . '" data-lld-days="' . (int) $days . '"><h1>Progress</h1>';
		$html .= '<section class="lld-card" data-lld-section="streak" data-lld-streak="' . (int) $streak . '"><h2>Streak</h2>';
		$html .= '<p class="lld-stat"><strong>' . (int) $streak . '</strong> ' . ( 1 === $streak ? 'day' : 'days' ) . ' in a row</p>';
		$html .= '<p class="lld-hint">A day counts when you log something or mark a step done. Missing a day pauses the streak; it never takes your progress away.</p></section>';
		$html .= self::hw_milestones_html( $member_id, $facts );

		// Weekly bars with the same numbers as a table (AS-01, AS-02). The table is the accessible form of the chart.
		$summary = 'Active days a week, the last 4 weeks';
		$html   .= '<section class="lld-card" data-lld-section="weekly-bars"><h2>Weekly activity</h2><figure class="lld-chart lld-week-chart"><figcaption>' . esc_html( $summary ) . '</figcaption><div class="lld-bars" aria-hidden="true">';
		foreach ( $bars as $b ) {
			$html .= '<div class="lld-bar"><span style="height:' . (int) round( $b['days'] / 7 * 100 ) . '%"></span><b>' . esc_html( $b['label'] ) . '</b></div>';
		}
		$html .= '</div><button type="button" class="lld-text-button" data-lld-table-toggle aria-expanded="false">Show as table</button>';
		$html .= '<table class="lld-chart-table" hidden data-lld-table="weekly-bars"><caption>' . esc_html( $summary ) . '</caption><thead><tr><th scope="col">Week</th><th scope="col">Active days</th></tr></thead><tbody>';
		foreach ( $bars as $b ) {
			$html .= '<tr><td>' . esc_html( $b['label'] ) . '</td><td data-lld-days-value>' . (int) $b['days'] . ' of 7</td></tr>';
		}
		$html .= '</tbody></table></figure></section>';

		// Badges (AS-04): the Stage 2 badges earned, names checked for weight words.
		$html .= '<section class="lld-card" data-lld-section="badges"><h2>Badges</h2>';
		$badges = self::badges( $member_id );
		if ( $badges ) {
			$html .= '<ul class="lld-badge-list">';
			foreach ( $badges as $b ) {
				$html .= '<li class="lld-badge-item" data-lld-badge="' . esc_attr( $b['key'] ) . '"><span class="lld-badge-picture" aria-hidden="true">★</span><span>' . esc_html( $b['title'] ) . '</span><small>' . esc_html( LLD_Members_Goal_Rules::nice_date( $b['earned_on'] ) ) . '</small></li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p>Badges come from healthy habits: a challenge finished, a week of logging. None yet.</p>';
		}
		$html .= '<p class="lld-hint">Badges reward habits, never the amount of weight lost.</p></section>';

		// Health trends next to activity (AS-03).
		$html .= '<section class="lld-card" data-lld-section="trends" data-lld-days="' . (int) $days . '"><h2>Health trends</h2><p class="lld-hint">The last ' . (int) $days . ' days, with activity alongside.</p>';
		if ( $facts['health'] ) {
			foreach ( array( 'weight' => 'Weight', 'waist' => 'Waist', 'blood_pressure' => 'Blood pressure', 'activity' => 'Activity' ) as $type => $label ) {
				$html .= self::trend_html( $member_id, $type, $label, $facts, $days );
			}
		} else {
			$html .= '<p>Trends need health consent.</p>';
		}
		if ( ! $facts['premium'] ) {
			$html .= lld_membership_lock( 'Premium shows your full history and your insights.' );
		}
		$html .= '</section>';
		$html .= '<p><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Back to Today</a></p></div>';
		self::output( 'Progress', $html );
	}

	/**
	 * One trend: a small line picture and the same values as a table, always shown.
	 *
	 * @param int    $member_id Member number.
	 * @param string $type      Reading type.
	 * @param string $label     Label.
	 * @param array  $facts     facts().
	 * @param int    $days      Window.
	 */
	private static function trend_html( $member_id, $type, $label, array $facts, $days ) {
		$t = LLD_Members_Goal_Rules::trend( LLD_Members_Goal_Journey::daily( $member_id, $type ), (string) $facts['today'], $days );
		if ( ! $t['rows'] ) {
			return '<div class="lld-trend" data-lld-trend="' . esc_attr( $type ) . '" data-lld-points="0"><h3>' . esc_html( $label ) . '</h3><p class="lld-hint">Nothing logged in this window.</p></div>';
		}
		$values = array();
		foreach ( $t['rows'] as $r ) {
			$values[] = is_array( $r['value'] ) ? (float) $r['value'][0] : (float) $r['value'];
		}
		$min   = min( $values );
		$max   = max( $values );
		$span  = $max - $min > 0 ? $max - $min : 1;
		$n     = count( $values );
		$pts   = array();
		foreach ( $values as $i => $v ) {
			$x     = $n > 1 ? $i / ( $n - 1 ) * 100 : 50;
			$y     = 30 - ( $v - $min ) / $span * 26;
			$pts[] = LLD_Members_Goal_Rules::plain( LLD_Members_Goal_Rules::round1( $x ) ) . ',' . LLD_Members_Goal_Rules::plain( LLD_Members_Goal_Rules::round1( $y ) );
		}
		$html  = '<div class="lld-trend" data-lld-trend="' . esc_attr( $type ) . '" data-lld-points="' . $n . '"><h3>' . esc_html( $label ) . '</h3>';
		$html .= '<svg class="lld-sparkline" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke-width="2" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
		$html .= '<table class="lld-trend-table"><caption>' . esc_html( $label . ', the last ' . $days . ' days' ) . '</caption><thead><tr><th scope="col">Date</th><th scope="col">Value</th></tr></thead><tbody>';
		foreach ( array_reverse( $t['rows'] ) as $r ) {
			$html .= '<tr><td>' . esc_html( LLD_Members_Goal_Rules::nice_date( $r['date'] ) ) . '</td><td data-lld-value>' . esc_html( LLD_Members_Today_Screen::show_value( $type, $r['value'], $facts['imperial'] ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * Healthy Weight milestones (P3-35 · FR3-101, R-HW-NUM-04 and R-HW-MOT-02): the celebration of the furthest milestone
	 * reached, in the signed T-HW-MOT-02 line followed by its approved health line (HW-MS-03, its 65-and-over variant, or
	 * HW-MS-04), then the milestones list. Never with an eating-disorder flag; no badge names kilograms (AS-04).
	 *
	 * @param int   $member_id Member number.
	 * @param array $facts     facts().
	 */
	private static function hw_milestones_html( $member_id, array $facts ) {
		if ( ! class_exists( 'LLD_Members_Hw_Screen_Parts' ) || empty( $facts['health'] ) || LLD_Members_Hw_Screen_Parts::ed( $facts ) ) {
			return '';
		}
		$c    = 'LLD_Members_Hw_Screen_Parts';
		$view = $c::plan_view( $member_id, (string) $facts['today'] );
		if ( ! $view || empty( $view['milestones'] ) || ! is_array( $view['milestones'] ) ) {
			return '';
		}
		$html = self::celebration_html( self::reached( $member_id, $view, $facts ), $facts );
		$list = LLD_Members_Hw_Plan_Screen::milestones_html( $view['milestones'] );
		if ( '' !== $list ) {
			$html .= '<section class="lld-card" data-lld-section="hw-milestones"><h2>' . esc_html( $c::LABELS['milestones_title'] ) . '</h2>' . $list . '</section>';
		}
		return $html;
	}

	/**
	 * The milestone reached: the view's own, else the signed R-HW-MOT-02 on the 7-day mean weight (D3-09).
	 *
	 * @param int   $member_id Member number.
	 * @param array $view      plan_view().
	 * @param array $facts     facts().
	 * @return array|null {kind, text}
	 */
	private static function reached( $member_id, array $view, array $facts ) {
		if ( isset( $view['milestone_reached'] ) && is_array( $view['milestone_reached'] ) ) {
			return ! empty( $view['milestone_reached']['kind'] ) ? $view['milestone_reached'] : null;
		}
		if ( ! class_exists( 'LLD_Members_HW_Rules' ) ) {
			return null;
		}
		$daily = LLD_Members_Goal_Journey::daily( $member_id, 'weight' );
		$sum   = 0.0;
		$n     = 0;
		foreach ( (array) $daily as $date => $kg ) {
			if ( is_numeric( $kg ) && LLD_Members_Goal_Rules::days_between( (string) $date, (string) $facts['today'] ) < 7 && LLD_Members_Goal_Rules::days_between( (string) $date, (string) $facts['today'] ) >= 0 ) {
				$sum += (float) $kg;
				++$n;
			}
		}
		$r = LLD_Members_HW_Rules::milestone_reached(
			array(
				'milestones'      => $view['milestones'],
				'mean7'           => $n ? $sum / $n : null,
				'celebrated'      => isset( $view['celebrated'] ) ? (array) $view['celebrated'] : array(),
				'eating_disorder' => 'no',
			)
		);
		return ! empty( $r['kind'] ) ? $r : null;
	}

	/**
	 * The celebration: the signed line, its approved health line, and Start Keep It Off at the goal.
	 *
	 * @param array|null $reached reached().
	 * @param array      $facts   facts().
	 */
	private static function celebration_html( $reached, array $facts ) {
		if ( ! $reached ) {
			return '';
		}
		$c    = 'LLD_Members_Hw_Screen_Parts';
		$kind = (string) $reached['kind'];
		$text = $c::text_of( $reached );
		if ( '' === $text ) {
			return '';
		}
		$lines = array(
			'healthy' => isset( $facts['age'] ) && (int) $facts['age'] >= 65 ? 'HW-MS-03-older' : 'HW-MS-03',
			'goal'    => 'HW-MS-04',
		);
		$html = '<section class="lld-card lld-hw-celebrate" data-lld-section="hw-celebrate" data-lld-milestone="' . esc_attr( $kind ) . '"><p>' . $c::rich( $text ) . '</p>';
		if ( isset( $lines[ $kind ] ) ) {
			$health = $c::block_text( $lines[ $kind ] );
			if ( '' !== $health ) {
				$html .= '<p data-lld-health-line>' . esc_html( $health ) . '</p>';
			}
		}
		if ( 'goal' === $kind ) {
			$html .= LLD_Members_Hw_Blocks_Screen::keep_it_off_html( $facts );
		}
		return $html . '</section>';
	}

	/**
	 * Badges earned (the Stage 2 badge table), with their names checked (AS-04).
	 *
	 * @param int $member_id Member number.
	 * @return array<int,array{key:string,title:string,earned_on:string}>
	 */
	private static function badges( $member_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT badge_key, challenge_key, earned_on FROM ' . LLD_Members_Schema::table( 'badge' ) . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$title = ucfirst( str_replace( array( '-', '_' ), ' ', (string) $r['badge_key'] ) );
			if ( ! LLD_Members_Goal_Rules::badge_text_allowed( $title ) ) {
				continue;
			}
			$out[] = array( 'key' => (string) $r['badge_key'], 'title' => $title, 'earned_on' => (string) $r['earned_on'] );
		}
		return $out;
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
