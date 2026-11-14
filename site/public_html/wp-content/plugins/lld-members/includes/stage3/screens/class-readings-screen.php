<?php
/**
 * Log a reading screen (P3-36 B2 · FR3-38 to FR3-46): /members/readings/.
 *
 * Eight reading types (blood_pressure, weight, waist, activity, sleep, water, mood, glucose),
 * inline chart and table toggle, edit/delete, and server-returned warning and safety banners.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health readings screen controller and renderer.
 */
class LLD_Members_Readings_Screen {

	const HANDLE_CSS     = 'lld-members-stage3';
	const HANDLE_JS      = 'lld-members-stage3-logging';
	const OPTION_REWRITE = 'lld_members_readings_rw';
	const REWRITE_VER    = '1';

	/**
	 * The eight reading types accepted by /me/readings.
	 *
	 * @return array<string,string>
	 */
	public static function reading_types() {
		return array(
			'blood_pressure' => 'Blood pressure',
			'weight'         => 'Weight',
			'waist'          => 'Waist',
			'activity'       => 'Activity',
			'sleep'          => 'Sleep',
			'water'          => 'Water',
			'mood'           => 'Mood',
			'glucose'        => 'Blood sugar',
		);
	}

	/**
	 * Hook rewrite and template dispatcher when health_logging switch is not off.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'health_logging' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register_routes' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 );
	}

	/**
	 * Add lld_screen query var if missing.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		if ( ! in_array( 'lld_screen', $vars, true ) ) {
			$vars[] = 'lld_screen';
		}
		return $vars;
	}

	/**
	 * Register /members/readings/ rewrite rule and flush once when missing.
	 */
	public static function register_routes() {
		add_rewrite_rule( '^members/readings/?$', 'index.php?lld_screen=readings', 'top' );
		$rules = get_option( 'rewrite_rules' );
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VER || ( is_array( $rules ) && ! isset( $rules['^members/readings/?$'] ) ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VER, true );
		}
	}

	/**
	 * Intercept /members/readings/ when health_logging is enabled for this member.
	 */
	public static function maybe_render() {
		if ( 'readings' !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$signed_in = is_user_logged_in() && class_exists( 'LLD_Members_Access' ) && LLD_Members_Access::current_member_id() > 0;
		$member_id = $signed_in ? (int) LLD_Members_Access::current_member_id() : 0;
		if ( ! $signed_in ) {
			if ( ! LLD_Members_Features::enabled( 'health_logging', 0 ) ) {
				self::not_found();
				return;
			}
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/readings/' ) ) ) );
			exit;
		}
		if ( ! LLD_Members_Features::enabled( 'health_logging', $member_id ) ) {
			self::not_found();
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
	 * Send WordPress 404 when feature is off for the member.
	 */
	private static function not_found() {
		global $wp_query;
		if ( isset( $wp_query ) && is_object( $wp_query ) ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Build and print the Log a reading page.
	 *
	 * @param int $member_id Member number.
	 */
	public static function render( $member_id ) {
		$types      = self::reading_types();
		$consent    = class_exists( 'LLD_Members_Access' ) ? (bool) LLD_Members_Access::has_health_consent( $member_id ) : false;
		$safety     = class_exists( 'LLD_Members_Stage3_Safety' );
		$applies    = $safety ? LLD_Members_Stage3_Safety::applies( $member_id, 'health_logging' ) : array( 'active' => false, 'situations' => array(), 'hidden' => array(), 'refused' => array(), 'notes' => array() );
		$cond_state = $safety && method_exists( 'LLD_Members_Stage3_Safety', 'conditions_state' ) ? LLD_Members_Stage3_Safety::conditions_state( $member_id ) : 'answered';
		$cond_link  = $safety && method_exists( 'LLD_Members_Stage3_Safety', 'conditions_link' ) ? LLD_Members_Stage3_Safety::conditions_link() : home_url( '/members/health/' );
		$can_store  = $consent && ! in_array( 'store', $applies['refused'], true );
		$facts      = class_exists( 'LLD_Members_Goal_Journey' ) ? LLD_Members_Goal_Journey::facts( $member_id ) : array();
		$imperial   = ! empty( $facts['imperial'] );
		$today      = isset( $facts['today'] ) && $facts['today'] ? (string) $facts['today'] : ( class_exists( 'LLD_Members_Programme' ) ? (string) LLD_Members_Programme::local_today( $member_id ) : gmdate( 'Y-m-d' ) );
		$utc_zone   = new DateTimeZone( 'UTC' );
		$today_dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, $utc_zone );
		if ( $today_dt ) {
			$dow        = (int) $today_dt->format( 'N' );
			$week_start = $today_dt->modify( '-' . ( $dow - 1 ) . ' days' )->format( 'Y-m-d' );
		} else {
			$today_ts   = strtotime( $today . ' 12:00:00 UTC' );
			$dow        = $today_ts ? (int) gmdate( 'N', $today_ts ) : 1;
			$week_start = $today_ts ? gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $dow - 1 ) . ' days UTC' ) ) : $today;
		}
		if ( function_exists( 'lld_members_now_mysql' ) ) {
			$now_utc = str_replace( ' ', 'T', substr( (string) lld_members_now_mysql( $member_id ), 0, 16 ) );
		} elseif ( class_exists( 'LLD_Members_Clock' ) ) {
			$now_utc = LLD_Members_Clock::now( (int) $member_id )->setTimezone( $utc_zone )->format( 'Y-m-d\TH:i' );
		} else {
			$now_utc = $today . 'T09:00';
		}
		$utc_today = substr( $now_utc, 0, 10 );

		$raw_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( 'bp' === $raw_type ) {
			$raw_type = 'blood_pressure';
		}
		$initial_type = isset( $types[ $raw_type ] ) ? $raw_type : 'blood_pressure';

		$has_trends       = ! class_exists( 'LLD_Members_Access_Tiers' ) || LLD_Members_Access_Tiers::allows( $member_id, 'trends' );
		$has_full_history = ! class_exists( 'LLD_Members_Access_Tiers' ) || LLD_Members_Access_Tiers::allows( $member_id, 'full_history' );
		$trends_msg       = class_exists( 'LLD_Members_Access_Tiers' ) && isset( LLD_Members_Access_Tiers::PREMIUM['trends'] ) ? LLD_Members_Access_Tiers::PREMIUM['trends'] : 'Long-term trends and insights are part of Premium.';
		$history_msg      = class_exists( 'LLD_Members_Access_Tiers' ) && isset( LLD_Members_Access_Tiers::PREMIUM['full_history'] ) ? LLD_Members_Access_Tiers::PREMIUM['full_history'] : 'Free shows the last 14 days.';
		$insights_on      = class_exists( 'LLD_Members_Features' ) && LLD_Members_Features::enabled( 'insights', $member_id );

		$low_mood_html = '';
		if ( $consent && $insights_on && class_exists( 'LLD_Members_Stage3_Data' ) && class_exists( 'LLD_Members_Rule_Catalogue' ) ) {
			foreach ( LLD_Members_Stage3_Data::rows( 'insights', $member_id, array( 'values' ), '', 50 ) as $ins ) {
				$rule_name = LLD_Members_Rule_Catalogue::name_for_id( (int) ( $ins['rule_id'] ?? 0 ) );
				if ( 'R-SAFE-04' !== $rule_name || ! empty( $ins['dismissed'] ) ) {
					continue;
				}
				if ( class_exists( 'LLD_Members_Goal_Rules' ) && LLD_Members_Goal_Rules::days_between( substr( (string) ( $ins['shown_at'] ?? '' ), 0, 10 ), $today ) > 7 ) {
					continue;
				}
				$text = (string) LLD_Members_Rule_Catalogue::fill( 'rule.R-SAFE-04', array() );
				if ( '' !== $text ) {
					$low_mood_html = '<section class="lld-card" data-lld-section="insights"><p class="lld-insight" data-lld-rule="R-SAFE-04">' . self::format_insight_text( $text ) . '</p></section>';
				}
				break;
			}
		}

		$config = array(
			'apiBase'         => esc_url_raw( rest_url( 'lld-members/v1/me/' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'today'           => $today,
			'utcToday'        => $utc_today,
			'weekStart'       => $week_start,
			'nowLocal'        => $now_utc,
			'initialType'     => $initial_type,
			'canStore'        => $can_store,
			'hasConsent'      => $consent,
			'conditionsState' => $cond_state,
			'hasTrends'       => $has_trends,
			'hasFullHistory'  => $has_full_history,
			'insightsEnabled' => $insights_on,
			'imperial'        => $imperial,
			'links'           => array(
				'health'     => home_url( '/members/health/' ),
				'settings'   => home_url( '/members/settings/' ),
				'conditions' => $cond_link,
				'pricing'    => home_url( '/pricing/' ),
				'safety'     => home_url( '/safety/' ),
				'home'       => home_url( '/members/home/' ),
				'bpCheck'    => home_url( '/health-tools/blood-pressure-check/' ),
				'helpline'   => 'https://findahelpline.com',
			),
			'messages'        => array(
				'trendsUpgrade'      => $trends_msg,
				'historyUpgrade'     => $history_msg,
				'conditionsMissing'  => $safety ? LLD_Members_Stage3_Safety::CONDITIONS_WORDING['conditions.missing'] : 'Answer the Conditions question in your health profile first.',
				'conditionsRequired' => $safety ? LLD_Members_Stage3_Safety::CONDITIONS_WORDING['conditions.required'] : 'Answer the Conditions question to save these details.',
			),
		);

		$html  = '<div class="lld-stage3-readings" data-lld-readings data-lld-readings-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		$html .= '<div class="lld-readings-shell">';
		$html .= '<header class="lld-readings-header">';
		$html .= '<p class="lld-eyebrow">Member area</p>';
		$html .= '<h1>Log a reading</h1>';
		$html .= '<p class="lld-lead">Record your readings and view your history.</p>';
		$html .= '</header>';

		if ( ! $consent ) {
			$html .= '<div class="lld-readings-gate" data-lld-consent-gate>';
			$html .= function_exists( 'lld_health_consent_notice' ) ? lld_health_consent_notice() : '<div class="lld-caution" role="status"><p><strong>Health consent is needed first.</strong></p><p>Before you save health readings, please agree to storing that information in your account. <a href="' . esc_url( home_url( '/members/settings/' ) ) . '">Review health consent</a></p></div>';
			$html .= '</div>';
		} elseif ( ! $can_store ) {
			$cond_copy = 'missing' === $cond_state
				? ( $safety ? LLD_Members_Stage3_Safety::CONDITIONS_WORDING['conditions.missing'] : 'Answer the Conditions question in your health profile first.' )
				: ( $safety ? LLD_Members_Stage3_Safety::CONDITIONS_WORDING['conditions.required'] : 'Answer the Conditions question to save these details.' );
			$html     .= '<div class="lld-caution lld-readings-conditions-notice" role="status" data-lld-conditions-gate>';
			$html     .= '<p>' . esc_html( $cond_copy ) . '</p>';
			$html     .= '<p><a class="lld-pill-btn lld-btn-secondary" href="' . esc_url( $cond_link ) . '">Health profile</a></p>';
			$html     .= '</div>';
		}

		// Server-driven alerts rendered in place; focus moves here on response.
		$html .= '<div id="lld-readings-urgent" class="lld-readings-alert" role="alert" aria-live="assertive" tabindex="-1" data-lld-urgent hidden></div>';
		$html .= '<div id="lld-readings-caution" class="lld-caution lld-readings-alert" role="status" aria-live="polite" tabindex="-1" data-lld-caution hidden></div>';
		$html .= '<div id="lld-readings-pulse" class="lld-caution lld-readings-alert" role="status" aria-live="polite" tabindex="-1" data-lld-pulse-note hidden></div>';
		$html .= '<div id="lld-readings-error" class="lld-caution lld-readings-error" role="alert" tabindex="-1" data-lld-error hidden></div>';
		$html .= '<p class="lld-readings-status" role="status" aria-live="polite" tabindex="-1" data-lld-status hidden></p>';
		$html .= '<div data-lld-insights-slot>' . $low_mood_html . '</div>';

		// Type selector tabs (strictly the 8 live reading_type choices as real links for no-JS support).
		$html .= '<div class="lld-pill-tabs lld-reading-tabs" role="tablist" aria-label="Reading types" data-lld-type-tabs>';
		foreach ( $types as $slug => $label ) {
			$selected = ( $slug === $initial_type );
			$tab_id   = 'lld-rtab-' . $slug;
			$panel_id = 'lld-rpanel-' . $slug;
			$tab_url  = add_query_arg( 'type', $slug, home_url( '/members/readings/' ) );
			$html    .= '<a href="' . esc_url( $tab_url ) . '" class="lld-pill-tab" role="tab" id="' . esc_attr( $tab_id ) . '" aria-controls="' . esc_attr( $panel_id ) . '" aria-selected="' . ( $selected ? 'true' : 'false' ) . '" tabindex="' . ( $selected ? '0' : '-1' ) . '" data-lld-type-tab="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div>';

		// Form card.
		$html .= '<section class="lld-card lld-readings-form-card" aria-labelledby="lld-reading-form-title">';
		$html .= '<h2 id="lld-reading-form-title" data-lld-form-heading>' . esc_html( $types[ $initial_type ] ) . '</h2>';
		$html .= '<form id="lld-readings-form" class="lld-readings-form" data-lld-readings-form novalidate>';

		$html .= '<div class="lld-field">';
		$html .= '<label for="lld-r-taken-at">Date and time</label>';
		$html .= '<input id="lld-r-taken-at" name="taken_at" type="datetime-local" class="lld-input" value="' . esc_attr( $now_utc ) . '" data-lld-taken-at>';
		$html .= '</div>';

		// 1. Blood pressure
		$bp_hidden = 'blood_pressure' !== $initial_type ? ' hidden' : '';
		$html     .= '<div id="lld-rpanel-blood_pressure" role="tabpanel" aria-labelledby="lld-rtab-blood_pressure" data-lld-type-panel="blood_pressure"' . $bp_hidden . '>';
		$html     .= '<div class="lld-row-2">';
		$html     .= '<div class="lld-field"><label for="lld-bp-sys">Top number (systolic, mmHg)</label><label for="lld-bp-sys" class="lld-sr-only">Systolic (mmHg)</label><input id="lld-bp-sys" type="number" inputmode="numeric" step="1" class="lld-input" placeholder="120" data-lld-bp-sys></div>';
		$html     .= '<div class="lld-field"><label for="lld-bp-dia">Bottom number (diastolic, mmHg)</label><label for="lld-bp-dia" class="lld-sr-only">Diastolic (mmHg)</label><input id="lld-bp-dia" type="number" inputmode="numeric" step="1" class="lld-input" placeholder="80" data-lld-bp-dia></div>';
		$html     .= '</div>';
		$html     .= '<div class="lld-field"><label for="lld-bp-pulse">Pulse (bpm, optional)</label><input id="lld-bp-pulse" type="number" inputmode="numeric" step="1" class="lld-input" placeholder="72" data-lld-bp-pulse></div>';
		$html     .= '<div class="lld-field"><label class="lld-check-label" for="lld-bp-dizzy"><input id="lld-bp-dizzy" type="checkbox" data-lld-bp-dizzy> <span>I feel dizzy or faint</span></label></div>';
		$html     .= '<p class="lld-readings-inline-link"><a href="' . esc_url( home_url( '/health-tools/blood-pressure-check/' ) ) . '">Check single reading or 7-day average</a></p>';
		$html     .= '</div>';

		// 2. Weight
		$w_hidden = 'weight' !== $initial_type ? ' hidden' : '';
		$html    .= '<div id="lld-rpanel-weight" role="tabpanel" aria-labelledby="lld-rtab-weight" data-lld-type-panel="weight"' . $w_hidden . '>';
		$html    .= '<div class="lld-row-2">';
		$html    .= '<div class="lld-field"><label for="lld-w-val">Weight</label><input id="lld-w-val" type="number" inputmode="decimal" step="0.1" class="lld-input" placeholder="72.5" data-lld-w-val></div>';
		$html    .= '<div class="lld-field"><label for="lld-w-unit">Unit</label><select id="lld-w-unit" class="lld-input" data-lld-w-unit><option value="kg"' . ( ! $imperial ? ' selected' : '' ) . '>kg</option><option value="lb"' . ( $imperial ? ' selected' : '' ) . '>lb</option></select></div>';
		$html    .= '</div></div>';

		// 3. Waist
		$waist_hidden = 'waist' !== $initial_type ? ' hidden' : '';
		$html        .= '<div id="lld-rpanel-waist" role="tabpanel" aria-labelledby="lld-rtab-waist" data-lld-type-panel="waist"' . $waist_hidden . '>';
		$html        .= '<div class="lld-row-2">';
		$html        .= '<div class="lld-field"><label for="lld-waist-val">Waist</label><input id="lld-waist-val" type="number" inputmode="decimal" step="0.5" class="lld-input" placeholder="84" data-lld-waist-val></div>';
		$html        .= '<div class="lld-field"><label for="lld-waist-unit">Unit</label><select id="lld-waist-unit" class="lld-input" data-lld-waist-unit><option value="cm"' . ( ! $imperial ? ' selected' : '' ) . '>cm</option><option value="in"' . ( $imperial ? ' selected' : '' ) . '>in</option></select></div>';
		$html        .= '</div>';
		$html        .= '<p class="lld-readings-inline-link"><a href="' . esc_url( home_url( '/health-tools/healthy-weight-check/' ) ) . '">How to measure your waist</a></p>';
		$html        .= '</div>';

		// 4. Activity
		$act_hidden = 'activity' !== $initial_type ? ' hidden' : '';
		$html      .= '<div id="lld-rpanel-activity" role="tabpanel" aria-labelledby="lld-rtab-activity" data-lld-type-panel="activity"' . $act_hidden . '>';
		$html      .= '<div class="lld-activity-summary" data-lld-activity-week>';
		$html      .= '<div class="lld-activity-week-head"><p class="lld-activity-summary-text" data-lld-activity-week-text>Weekly total: 0 of 150 minutes</p>';
		$html      .= '<button type="button" class="lld-pill-btn lld-btn-secondary lld-btn-sm" aria-pressed="false" data-lld-act-view-toggle>Show as table</button></div>';
		$html      .= '<div class="lld-meter" role="progressbar" aria-label="Weekly activity minutes against 150-minute guide" aria-valuemin="0" aria-valuemax="150" aria-valuenow="0" data-lld-activity-meter><span class="lld-meter-fill" data-lld-activity-fill style="width:0%"></span></div>';
		$html      .= '<div class="lld-activity-chart-box" data-lld-activity-chart></div>';
		$html      .= '<div class="lld-activity-table-box" data-lld-activity-table hidden></div>';
		$html      .= '</div>';
		$html      .= '<div class="lld-row-2">';
		$html      .= '<div class="lld-field"><label for="lld-act-min">Minutes</label><input id="lld-act-min" type="number" inputmode="numeric" step="1" class="lld-input" placeholder="30" data-lld-act-min></div>';
		$html      .= '<div class="lld-field"><label for="lld-act-effort">Effort</label><select id="lld-act-effort" class="lld-input" data-lld-act-effort><option value="">Not rated</option><option value="1">Easy: you can sing</option><option value="2">Moderate: you can talk but not sing</option><option value="3">Hard: only a few words at a time</option></select></div>';
		$html      .= '</div>';
		$html      .= '<div class="lld-row-2">';
		$html      .= '<div class="lld-field"><label for="lld-act-steps">Or steps</label><input id="lld-act-steps" type="number" inputmode="numeric" step="1" class="lld-input" placeholder="6000" data-lld-act-steps></div>';
		$html      .= '<div class="lld-field"><label for="lld-act-detail">Activity type</label><input id="lld-act-detail" type="text" maxlength="200" class="lld-input" placeholder="Brisk walk" data-lld-act-detail></div>';
		$html      .= '</div></div>';

		// 5. Sleep
		$sleep_hidden = 'sleep' !== $initial_type ? ' hidden' : '';
		$html        .= '<div id="lld-rpanel-sleep" role="tabpanel" aria-labelledby="lld-rtab-sleep" data-lld-type-panel="sleep"' . $sleep_hidden . '>';
		$html        .= '<div class="lld-row-2">';
		$html        .= '<div class="lld-field"><label for="lld-sleep-bed">Bedtime</label><input id="lld-sleep-bed" type="time" class="lld-input" value="23:00" data-lld-sleep-bed></div>';
		$html        .= '<div class="lld-field"><label for="lld-sleep-wake">Wake time</label><input id="lld-sleep-wake" type="time" class="lld-input" value="07:00" data-lld-sleep-wake></div>';
		$html        .= '</div>';
		$html        .= '<div class="lld-row-2">';
		$html        .= '<div class="lld-field"><label for="lld-sleep-hours">Hours slept (optional)</label><input id="lld-sleep-hours" type="number" inputmode="decimal" step="0.1" class="lld-input" placeholder="8" data-lld-sleep-hours data-lld-auto="1" aria-describedby="lld-sleep-hint"></div>';
		$html        .= '<div class="lld-field"><label for="lld-sleep-rested">How rested (1 to 5)</label><select id="lld-sleep-rested" class="lld-input" data-lld-sleep-rested><option value="">Not rated</option><option value="1">1 &mdash; Very tired</option><option value="2">2 &mdash; Tired</option><option value="3">3 &mdash; OK</option><option value="4">4 &mdash; Rested</option><option value="5">5 &mdash; Very rested</option></select></div>';
		$html        .= '</div>';
		$html        .= '<p class="lld-hint" id="lld-sleep-hint">Calculated from bedtime and wake time, or enter hours slept directly.</p>';
		$html        .= '</div>';

		// 6. Water
		$water_hidden = 'water' !== $initial_type ? ' hidden' : '';
		$html        .= '<div id="lld-rpanel-water" role="tabpanel" aria-labelledby="lld-rtab-water" data-lld-type-panel="water"' . $water_hidden . '>';
		$html        .= '<p class="lld-water-total" data-lld-water-total>Today&#8217;s water total: 0 ml</p>';
		$html        .= '<div class="lld-water-quick" role="group" aria-label="Quick add water">';
		$html        .= '<button type="button" class="lld-pill-btn lld-btn-secondary" data-lld-water-quick="250">+250 ml (1 glass)</button>';
		$html        .= '<button type="button" class="lld-pill-btn lld-btn-secondary" data-lld-water-quick="500">+500 ml</button>';
		$html        .= '</div>';
		$html        .= '<div class="lld-row-2">';
		$html        .= '<div class="lld-field"><label for="lld-water-val">Or enter amount</label><input id="lld-water-val" type="number" inputmode="decimal" step="1" class="lld-input" value="250" data-lld-water-val></div>';
		$html        .= '<div class="lld-field"><label for="lld-water-unit">Unit</label><select id="lld-water-unit" class="lld-input" data-lld-water-unit><option value="ml">ml</option><option value="glasses">glasses</option></select></div>';
		$html        .= '</div></div>';

		// 7. Mood
		$mood_hidden = 'mood' !== $initial_type ? ' hidden' : '';
		$mood_labels = array(
			1 => 'Low',
			2 => 'Down',
			3 => 'OK',
			4 => 'Good',
			5 => 'Great',
		);
		$html       .= '<div id="lld-rpanel-mood" role="tabpanel" aria-labelledby="lld-rtab-mood" data-lld-type-panel="mood"' . $mood_hidden . '>';
		$html       .= '<div class="lld-field"><span id="lld-mood-label" class="lld-label">Mood</span>';
		$html       .= '<div class="lld-mood-row" role="group" aria-labelledby="lld-mood-label">';
		foreach ( $mood_labels as $score => $word ) {
			$html .= '<button type="button" class="lld-mood-button" aria-pressed="false" data-lld-mood-btn="' . esc_attr( (string) $score ) . '">' . esc_html( (string) $score ) . ' <span>' . esc_html( $word ) . '</span></button>';
		}
		$html .= '</div></div>';
		$html .= '<div class="lld-field"><label for="lld-mood-note">Note (optional)</label><textarea id="lld-mood-note" class="lld-input" rows="2" maxlength="240" data-lld-mood-note></textarea></div>';
		$html .= '</div>';

		// 8. Blood sugar (glucose)
		$glu_hidden = 'glucose' !== $initial_type ? ' hidden' : '';
		$html      .= '<div id="lld-rpanel-glucose" role="tabpanel" aria-labelledby="lld-rtab-glucose" data-lld-type-panel="glucose"' . $glu_hidden . '>';
		$html      .= '<div class="lld-row-2">';
		$html      .= '<div class="lld-field"><label for="lld-glu-val">Blood sugar</label><input id="lld-glu-val" type="number" inputmode="decimal" step="0.1" class="lld-input" placeholder="5.4" data-lld-glu-val></div>';
		$html      .= '<div class="lld-field"><label for="lld-glu-unit">Unit</label><select id="lld-glu-unit" class="lld-input" data-lld-glu-unit><option value="mmol/L"' . ( ! $imperial ? ' selected' : '' ) . '>mmol/L</option><option value="mg/dL"' . ( $imperial ? ' selected' : '' ) . '>mg/dL</option></select></div>';
		$html      .= '</div>';
		$html      .= '<div class="lld-field"><label for="lld-glu-timing">Timing</label><select id="lld-glu-timing" class="lld-input" data-lld-glu-timing><option value="before_meal">Before a meal</option><option value="after_meal">After a meal</option></select></div>';
		$html      .= '<fieldset class="lld-field lld-symptoms-group"><legend>Do you have any of these right now?</legend>';
		$symptoms   = array(
			'vomiting'       => 'Vomiting',
			'drowsiness'     => 'Drowsiness',
			'deep_breathing' => 'Deep breathing',
			'stomach_pain'   => 'Stomach pain',
		);
		foreach ( $symptoms as $s_key => $s_label ) {
			$s_id  = 'lld-glu-sym-' . $s_key;
			$html .= '<label class="lld-check-label" for="' . esc_attr( $s_id ) . '"><input id="' . esc_attr( $s_id ) . '" type="checkbox" value="' . esc_attr( $s_key ) . '" data-lld-glu-symptom> <span>' . esc_html( $s_label ) . '</span></label>';
		}
		$html .= '</fieldset></div>';

		$html .= '<div class="lld-readings-submit-row">';
		$html .= '<button type="submit" class="lld-pill-btn lld-btn-primary lld-readings-save-btn" data-lld-save-btn>Save reading</button>';
		$html .= '<button type="button" class="lld-pill-btn lld-btn-secondary" data-lld-cancel-edit hidden>Cancel</button>';
		$html .= '</div>';
		$html .= '</form></section>';

		// Empty state.
		$html .= '<section class="lld-card lld-readings-empty" data-lld-empty hidden>';
		$html .= '<div class="lld-empty-visual" aria-hidden="true">';
		$html .= '<svg class="lld-empty-icon" viewBox="0 0 64 64" width="56" height="56" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="32" cy="32" r="24"/><path d="M22 36l6-8 6 5 8-11"/><circle cx="42" cy="22" r="2.5" fill="currentColor"/></svg>';
		$html .= '</div>';
		$html .= '<h2>No readings logged yet</h2>';
		$html .= '<p data-lld-empty-text>No readings yet. Choose a type above to add your first.</p>';
		$html .= '<div class="lld-empty-actions"><button type="button" class="lld-pill-btn lld-btn-secondary" data-lld-focus-form>Record first reading</button>';
		$html .= '<a class="lld-btn-ghost" href="' . esc_url( home_url( '/health-tools/blood-pressure-check/' ) ) . '" data-lld-empty-bp-link' . ( 'blood_pressure' !== $initial_type ? ' hidden' : '' ) . '>How to measure blood pressure</a>';
		$html .= '</div></section>';

		// History & neutral trend chart / table.
		$html .= '<section class="lld-card lld-readings-history" data-lld-history-section>';
		$html .= '<div class="lld-history-head">';
		$html .= '<div><h2 data-lld-history-heading>Recent readings</h2>';
		if ( ! $has_full_history ) {
			$html .= '<p class="lld-hint lld-readings-tier-note">' . esc_html( $history_msg ) . ' <a href="' . esc_url( home_url( '/pricing/' ) ) . '">See Premium</a></p>';
		}
		$html .= '</div>';
		$html .= '<button type="button" class="lld-pill-btn lld-btn-secondary lld-btn-sm" aria-pressed="false" data-lld-view-toggle>Show as table</button>';
		$html .= '</div>';

		$html .= '<div class="lld-readings-filter" data-lld-history-filter>';
		$html .= '<div class="lld-field"><label for="lld-filter-from">From</label><input id="lld-filter-from" type="date" class="lld-input" data-lld-filter-from></div>';
		$html .= '<div class="lld-field"><label for="lld-filter-to">To</label><input id="lld-filter-to" type="date" class="lld-input" data-lld-filter-to></div>';
		$html .= '<div class="lld-readings-filter-actions"><button type="button" class="lld-pill-btn lld-btn-secondary lld-btn-sm" data-lld-filter-apply>Filter</button><button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-filter-reset>Reset</button></div>';
		$html .= '</div>';

		$html .= '<div class="lld-readings-table-box" data-lld-table-box></div>';
		$html .= '<div class="lld-readings-chart-box" data-lld-chart-box></div>';
		$html .= '<div class="lld-readings-act-sr" data-lld-act-sr-box></div>';
		$html .= '</section>';

		$html .= '<p class="lld-readings-back"><a href="' . esc_url( home_url( '/members/home/' ) ) . '">Back to Today</a></p>';
		$html .= '</div></div>';

		self::output( 'Log a reading', $html );
	}

	/**
	 * Format an insight line safely with helpline links and bold text.
	 *
	 * @param string $text Raw insight text.
	 * @return string
	 */
	private static function format_insight_text( $text ) {
		$safe = esc_html( (string) $text );
		$safe = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $safe );
		$safe = str_replace( 'findahelpline.com', '<a href="https://findahelpline.com" target="_blank" rel="noopener noreferrer">findahelpline.com</a>', $safe );
		return $safe;
	}

	/**
	 * Output wrapped in the member layout with Stage 3 stylesheet and logging script.
	 *
	 * @param string $title Page title.
	 * @param string $main  Main HTML.
	 */
	private static function output( $title, $main ) {
		wp_enqueue_style( self::HANDLE_CSS, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		wp_enqueue_script( self::HANDLE_JS, plugins_url( 'assets/stage3-logging.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		ob_start();
		lld_members_layout(
			array(
				'title'     => $title,
				'main'      => $main,
				'signed_in' => true,
				'wide'      => true,
			)
		);
		$page = (string) ob_get_clean();

		ob_start();
		wp_print_styles( array( self::HANDLE_CSS ) );
		$styles = (string) ob_get_clean();
		$head   = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles, $head, 0 );
		}

		ob_start();
		wp_print_scripts( array( self::HANDLE_JS ) );
		$scripts = (string) ob_get_clean();
		$end     = strrpos( $page, '</body>' );
		if ( false !== $end ) {
			$page = substr_replace( $page, $scripts, $end, 0 );
		}

		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
}
