<?php
/**
 * Food Tracker Screen (P3-36 B1 · FR3-26 to FR3-36): /members/food/.
 *
 * Renders the daily meal cards, macro/calorie summary, target editor,
 * add-food sheet (search, recent, favourites, my recipes, custom foods, barcode link),
 * weekly summary, recipes, dietary preferences, ED safe mode (F4),
 * kidney protein note (F5), and Free/Premium access gates (F6).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Food tracker page at /members/food/.
 */
class LLD_Members_Food_Screen {

	const HANDLE          = 'lld-members-stage3';
	const LOGGING_HANDLE  = 'lld-members-stage3-logging';
	const OPTION_REWRITE  = 'lld_members_stage3_food_rewrite';
	const REWRITE_VERSION = '2';

	/**
	 * Meal sections in display order.
	 *
	 * @var array<string,string>
	 */
	const MEALS = array(
		'breakfast' => 'Breakfast',
		'lunch'     => 'Lunch',
		'dinner'    => 'Dinner',
		'snacks'    => 'Snacks',
	);

	/**
	 * Allergens matching LLD_Members_Recipes::ALLERGENS exactly.
	 *
	 * @var array<string,string>
	 */
	const ALLERGENS = array(
		'nuts'      => 'Tree nuts',
		'peanuts'   => 'Peanuts',
		'gluten'    => 'Gluten',
		'milk'      => 'Milk',
		'egg'       => 'Eggs',
		'fish'      => 'Fish',
		'shellfish' => 'Shellfish',
		'soy'       => 'Soya',
		'sesame'    => 'Sesame',
		'celery'    => 'Celery',
		'mustard'   => 'Mustard',
		'sulphites' => 'Sulphites',
		'lupin'     => 'Lupin',
		'molluscs'  => 'Molluscs',
	);

	/**
	 * Hooks rewrite rules and template redirect when food_tracker is active.
	 */
	public static function boot() {
		if ( ! class_exists( 'LLD_Members_Features' ) || 'off' === LLD_Members_Features::state( 'food_tracker' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 4 );
	}

	/**
	 * Registers /members/food/ rewrite rule.
	 */
	public static function rewrite() {
		add_rewrite_rule( '^members/food/?$', 'index.php?lld_screen=food', 'top' );
		$rules = get_option( 'rewrite_rules' );
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VERSION || ( is_array( $rules ) && ! isset( $rules['^members/food/?$'] ) ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VERSION, false );
		}
	}

	/**
	 * Resolves the member's local today date string (Y-m-d).
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function local_today( $member_id ) {
		if ( class_exists( 'LLD_Members_Programme' ) && method_exists( 'LLD_Members_Programme', 'local_today' ) ) {
			return (string) LLD_Members_Programme::local_today( (int) $member_id );
		}
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Renders /members/food/ for a signed-in member.
	 */
	public static function render() {
		if ( 'food' !== get_query_var( 'lld_screen' ) ) {
			return;
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! class_exists( 'LLD_Members_Access' ) || ! function_exists( 'lld_members_layout' ) ) {
			return;
		}
		if ( get_option( 'lld_members_closed' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$member_id = is_user_logged_in() ? (int) LLD_Members_Access::current_member_id() : 0;
		if ( $member_id > 0 && '' !== LLD_Members_Access::preview_state() ) {
			return; // the owner's Free/Premium preview keeps the Stage 2 screens, as Today does
		}
		if ( $member_id <= 0 ) {
			wp_safe_redirect( home_url( '/members/sign-in/?redirect_to=' . rawurlencode( home_url( '/members/food/' ) ) ) );
			exit;
		}
		if ( ! class_exists( 'LLD_Members_Features' ) || ! LLD_Members_Features::enabled( 'food_tracker', $member_id ) ) {
			self::output(
				'Food tracker',
				'<div class="lld-stage3-food"><div class="lld-page-head"><h1>Food</h1><p class="lld-hint">Food tracking is not turned on for your account yet.</p></div><p><a class="lld-btn-ghost" href="' . esc_url( home_url( '/members/home/' ) ) . '">Back to Today</a></p></div>',
				false
			);
			exit;
		}
		// Health consent first (FR3-59): without it nothing is read or stored, so the page shows the interface's own
		// refusal sentence and the way to the consent, and loads no diary and no script.
		if ( ! LLD_Members_Access::has_health_consent( $member_id ) ) {
			$needed = class_exists( 'LLD_Members_Stage3_Consent' ) ? LLD_Members_Stage3_Consent::MESSAGES['needed'] : 'Health consent is needed first.';
			self::output(
				'Food',
				'<div class="lld-stage3-food" data-lld-food-consent="no"><div class="lld-page-head"><h1>Food</h1></div><div class="lld-notice-box" role="status"><p>' . esc_html( $needed ) . '</p><p>Adding food to your diary needs your health consent. You can give it in Privacy &amp; consents.</p><p><a class="lld-btn-ghost" href="' . esc_url( home_url( '/members/settings/?section=privacy' ) ) . '">Privacy &amp; consents</a></p></div></div>',
				false
			);
			exit;
		}

		$today = self::local_today( $member_id );
		$date  = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = $today;
		}

		$html = self::build_html( $member_id, $date );
		self::output( 'Food', $html, true );
		exit;
	}

	/**
	 * Returns rendered upgrade prompt HTML when a feature is gated, or empty string if allowed.
	 *
	 * @param string $feature Feature key from LLD_Members_Access_Tiers::PREMIUM.
	 * @param string $fallback Fallback copy if helper is unavailable.
	 * @return string
	 */
	private static function upgrade_box( $feature, $fallback ) {
		if ( class_exists( 'LLD_Members_Access_Tiers' ) && method_exists( 'LLD_Members_Access_Tiers', 'upgrade_prompt' ) ) {
			return (string) LLD_Members_Access_Tiers::upgrade_prompt( $feature );
		}
		return '<div class="lld-card lld-upgrade" data-lld-upgrade="' . esc_attr( $feature ) . '"><p>' . esc_html( $fallback ) . '</p><a class="lld-pill-btn" href="' . esc_url( home_url( '/pricing/' ) ) . '">See Premium</a></div>';
	}

	/**
	 * Builds the Food tracker page HTML with server-rendered initial state.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $date      Active date Y-m-d.
	 * @return string
	 */
	public static function build_html( $member_id, $date ) {
		$today        = self::local_today( $member_id );
		$yesterday_ts = strtotime( $date . ' -1 day' );
		$yesterday    = false !== $yesterday_ts ? gmdate( 'Y-m-d', $yesterday_ts ) : $date;

		$safety       = class_exists( 'LLD_Members_Stage3_Safety' ) ? LLD_Members_Stage3_Safety::applies( $member_id, 'food_tracker' ) : array();
		$hidden       = class_exists( 'LLD_Members_Stage3_Safety' ) ? LLD_Members_Stage3_Safety::nutrition_hidden( $safety ) : array( 'all' => false, 'protein' => false );
		$hidden_keys  = isset( $safety['hidden'] ) && is_array( $safety['hidden'] ) ? $safety['hidden'] : array();
		$situations   = isset( $safety['situations'] ) && is_array( $safety['situations'] ) ? $safety['situations'] : array();
		$ed_mode      = ! empty( $hidden['all'] );
		$kidney_mode  = ! empty( $hidden['protein'] );
		$hide_targets = $ed_mode || in_array( 'targets', $hidden_keys, true ) || in_array( 'calorie_target', $hidden_keys, true );
		$pregnant     = in_array( 'pregnancy', $situations, true );
		$premium      = class_exists( 'LLD_Members_Food_Log' ) ? LLD_Members_Food_Log::premium( $member_id ) : false;

		$tier_on  = class_exists( 'LLD_Members_Access_Tiers' );
		$features = array(
			'barcode'        => $tier_on ? LLD_Members_Access_Tiers::allows( $member_id, 'barcode' ) : $premium,
			'copy_meals'     => $tier_on ? LLD_Members_Access_Tiers::allows( $member_id, 'copy_meals' ) : $premium,
			'own_targets'    => $tier_on ? LLD_Members_Access_Tiers::allows( $member_id, 'own_targets' ) : $premium,
			'custom_foods'   => $tier_on ? LLD_Members_Access_Tiers::allows( $member_id, 'custom_foods' ) : $premium,
			'recipes'        => $tier_on ? LLD_Members_Access_Tiers::allows( $member_id, 'recipes' ) : $premium,
			'weekly_summary' => $tier_on ? LLD_Members_Access_Tiers::allows( $member_id, 'weekly_summary' ) : $premium,
		);

		$day = class_exists( 'LLD_Members_Food_Log' )
			? LLD_Members_Food_Log::view( 'day', $member_id, array( 'date' => $date ) )
			: array( 'date' => $date, 'meals' => array(), 'earlier' => array(), 'totals' => null, 'target' => null );
		$day = apply_filters( 'lld_members_stage3_api_rows', $day, 'food-day', $member_id );
		if ( is_array( $day ) ) {
			$day['pregnant'] = $pregnant;
		}

		// Eating-disorder flag: the interface keeps each entry's amount (it is needed to store the entry), but the page
		// asks no portion question and shows no amount, so the amounts are not put into the page at all (FR3-69 AS-01).
		$page_day = $day;
		if ( $ed_mode && is_array( $page_day ) && isset( $page_day['meals'] ) && is_array( $page_day['meals'] ) ) {
			foreach ( $page_day['meals'] as $meal_key => $entries ) {
				foreach ( is_array( $entries ) ? $entries : array() as $i => $entry ) {
					if ( is_array( $entry ) ) {
						unset( $page_day['meals'][ $meal_key ][ $i ]['grams'], $page_day['meals'][ $meal_key ][ $i ]['portion_label'] );
					}
				}
			}
		}

		$config = array(
			'api'         => esc_url_raw( rest_url( 'lld-members/v1/me' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'date'        => $date,
			'today'       => $today,
			'premium'     => (bool) $premium,
			'features'    => $features,
			'edMode'      => $ed_mode,
			'kidneyMode'  => $kidney_mode,
			'hideTargets' => $hide_targets,
			'pregnant'    => $pregnant,
			'scanUrl'     => esc_url_raw( home_url( '/members/food/scan/' ) ),
			'upgradeUrl'  => esc_url_raw( home_url( '/pricing/' ) ),
			'todayUrl'    => esc_url_raw( home_url( '/members/home/' ) ),
			'initialDay'  => $page_day,
		);

		$locked = ! empty( $day['locked'] );

		ob_start();
		?>
		<div class="lld-stage3-food"
			data-lld-food-root
			data-lld-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">

			<div class="lld-page-head lld-food-head">
				<div class="lld-food-head-top">
					<div>
						<h1><?php echo $ed_mode ? esc_html( LLD_Members_Stage3_Safety::MESSAGES['ed.food-tracker.diary-heading'] ) : 'Food'; ?></h1>
						<p class="lld-hint">
							<?php
							echo $ed_mode
								? esc_html( LLD_Members_Stage3_Safety::MESSAGES['ed.food-tracker.calorie-total'] )
								: 'Log what you eat and drink, meal by meal.';
							?>
						</p>
					</div>
					<div class="lld-food-head-actions">
						<?php if ( $features['barcode'] ) : ?>
							<a class="lld-btn-ghost" href="<?php echo esc_url( home_url( '/members/food/scan/' ) ); ?>">Scan barcode</a>
						<?php else : ?>
							<button type="button" class="lld-btn-ghost" data-lld-toggle-upgrade="barcode" aria-expanded="false" aria-controls="lld-upgrade-barcode">Scan barcode</button>
						<?php endif; ?>
						<?php if ( $features['copy_meals'] ) : ?>
							<button type="button" class="lld-btn-ghost" data-lld-action="copy-yesterday">Copy yesterday</button>
						<?php else : ?>
							<button type="button" class="lld-btn-ghost" data-lld-toggle-upgrade="copy" aria-expanded="false" aria-controls="lld-upgrade-copy">Copy meals</button>
						<?php endif; ?>
						<?php if ( ! $hide_targets ) : ?>
							<button type="button" class="lld-btn-ghost" data-lld-action="toggle-targets" aria-expanded="false" aria-controls="lld-food-targets-panel">Edit targets</button>
						<?php endif; ?>
					</div>
				</div>

				<div class="lld-food-datebar" aria-label="Choose day">
					<div class="lld-food-date-controls">
						<button type="button" class="lld-btn-ghost lld-food-nav-btn" data-lld-date-shift="-1" aria-label="Previous day">&larr; Previous day</button>
						<label class="lld-food-date-field" for="lld-food-date-input">
							<span class="lld-sr">Selected date</span>
							<input id="lld-food-date-input" type="date" value="<?php echo esc_attr( $date ); ?>" data-lld-food-date-input />
						</label>
						<button type="button" class="lld-btn-ghost lld-food-nav-btn" data-lld-date-shift="1" aria-label="Next day">Next day &rarr;</button>
						<button type="button" class="lld-btn-ghost lld-food-nav-btn" data-lld-date-today>Today</button>
					</div>
					<?php if ( $features['copy_meals'] ) : ?>
						<div class="lld-food-copy-bar">
							<label class="lld-food-field-inline" for="lld-food-copy-date">
								<span>Copy from</span>
								<input id="lld-food-copy-date" type="date" value="<?php echo esc_attr( $yesterday ); ?>" data-lld-copy-date />
							</label>
							<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-action="copy-date">Copy day</button>
						</div>
					<?php endif; ?>
					<div class="lld-food-status" role="status" aria-live="polite" data-lld-food-status></div>
				</div>

				<?php if ( ! $features['barcode'] ) : ?>
					<div id="lld-upgrade-barcode" class="lld-food-upgrade-slot" data-lld-upgrade-box="barcode" hidden>
						<?php echo self::upgrade_box( 'barcode', 'Barcode scanning is part of Premium.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
					</div>
				<?php endif; ?>

				<?php if ( ! $features['copy_meals'] ) : ?>
					<div id="lld-upgrade-copy" class="lld-food-upgrade-slot" data-lld-upgrade-box="copy" hidden>
						<?php echo self::upgrade_box( 'copy_meals', 'Copying meals between days is part of Premium.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! $hide_targets ) : ?>
				<section class="lld-card lld-food-targets-panel" id="lld-food-targets-panel" data-lld-targets-panel hidden>
					<div class="lld-food-card-head">
						<h2>Daily nutrition target</h2>
						<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-action="close-targets">Close</button>
					</div>
					<?php // Kidney disease: the signed sentence from the calorie target sheet, or nothing. The page writes none of its own. ?>
					<?php if ( $kidney_mode && class_exists( 'LLD_Members_Calorie_Target' ) && isset( LLD_Members_Calorie_Target::QUOTED['kidney'] ) ) : ?>
						<p class="lld-food-calm-note"><?php echo esc_html( LLD_Members_Calorie_Target::QUOTED['kidney'] ); ?></p>
					<?php endif; ?>
					<?php if ( $features['own_targets'] ) : ?>
						<p class="lld-field-help">Updates apply from the selected day onwards.</p>
						<form class="lld-food-target-form" data-lld-target-form>
							<div class="lld-food-target-grid">
								<label class="lld-food-field">
									<span>Calories (kcal)</span>
									<input type="number" name="kcal" min="1" max="10000" step="1" required />
								</label>
								<?php if ( ! $kidney_mode ) : ?>
									<label class="lld-food-field">
										<span>Protein (g)</span>
										<input type="number" name="protein_g" min="0" max="1000" step="1" />
									</label>
								<?php endif; ?>
								<label class="lld-food-field">
									<span>Carbs (g)</span>
									<input type="number" name="carb_g" min="0" max="2000" step="1" />
								</label>
								<label class="lld-food-field">
									<span>Fat (g)</span>
									<input type="number" name="fat_g" min="0" max="1000" step="1" />
								</label>
								<label class="lld-food-field">
									<span>Fibre (g)</span>
									<input type="number" name="fibre_g" min="0" max="300" step="1" />
								</label>
							</div>
							<div class="lld-food-form-actions">
								<button type="submit" class="lld-pill-btn">Save target</button>
								<div class="lld-food-inline-msg" data-lld-target-msg role="status" aria-live="polite"></div>
							</div>
						</form>
					<?php else : ?>
						<?php echo self::upgrade_box( 'own_targets', 'Setting your own targets is part of Premium.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<section class="lld-card lld-food-locked" data-lld-food-locked<?php echo $locked ? '' : ' hidden'; ?>>
				<p data-lld-food-locked-msg><?php echo esc_html( ! empty( $day['message'] ) ? (string) $day['message'] : 'Free shows the last 14 days. Premium shows your full history.' ); ?></p>
				<p><a class="lld-pill-btn" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">See Premium</a></p>
			</section>

			<?php if ( ! $ed_mode ) : ?>
				<section class="lld-card lld-food-summary" data-lld-food-summary<?php echo $locked ? ' hidden' : ''; ?>>
					<?php if ( $pregnant ) : ?>
						<p class="lld-food-calm-note"><?php echo esc_html( LLD_Members_Stage3_Safety::MESSAGES['pregnancy.calorie-target'] ); ?></p>
					<?php endif; ?>
					<div class="lld-food-summary-grid" data-lld-food-summary-body>
						<?php echo self::render_summary_inner( $day, $kidney_mode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
					</div>
				</section>
			<?php endif; ?>

			<div class="lld-food-meals" data-lld-food-meals<?php echo $locked ? ' hidden' : ''; ?>>
				<?php foreach ( self::MEALS as $meal_key => $meal_label ) : ?>
					<?php
					$items     = isset( $day['meals'][ $meal_key ] ) && is_array( $day['meals'][ $meal_key ] ) ? $day['meals'][ $meal_key ] : array();
					$earlier   = isset( $day['earlier'][ $meal_key ] ) && is_array( $day['earlier'][ $meal_key ] ) ? $day['earlier'][ $meal_key ] : array();
					$meal_kcal = 0;
					$meal_full = true; // a subtotal is shown only when every item came back with its calories
					foreach ( $items as $it ) {
						if ( isset( $it['kcal'] ) && is_numeric( $it['kcal'] ) ) {
							$meal_kcal += (float) $it['kcal'];
						} else {
							$meal_full = false;
						}
					}
					?>
					<section class="lld-card lld-meal-card" data-lld-meal="<?php echo esc_attr( $meal_key ); ?>">
						<header class="lld-meal-head">
							<div class="lld-meal-title">
								<h2><?php echo esc_html( $meal_label ); ?></h2>
								<?php if ( ! $ed_mode ) : ?>
									<span class="lld-meal-kcal" data-lld-meal-kcal="<?php echo esc_attr( $meal_key ); ?>"<?php echo $meal_full ? '' : ' hidden'; ?>><?php echo $meal_full ? esc_html( (string) round( $meal_kcal ) ) . ' kcal' : ''; ?></span>
								<?php endif; ?>
							</div>
							<div class="lld-meal-actions">
								<?php if ( $features['copy_meals'] ) : ?>
									<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-copy-meal="<?php echo esc_attr( $meal_key ); ?>">Copy <?php echo esc_html( strtolower( $meal_label ) ); ?></button>
								<?php endif; ?>
								<button type="button" class="lld-pill-btn lld-btn-sm" data-lld-open-add="<?php echo esc_attr( $meal_key ); ?>">+ Add food</button>
							</div>
						</header>

						<ul class="lld-food-items" data-lld-meal-items="<?php echo esc_attr( $meal_key ); ?>">
							<?php if ( empty( $items ) && empty( $earlier ) ) : ?>
								<li class="lld-food-empty">Nothing logged for <?php echo esc_html( strtolower( $meal_label ) ); ?> yet.</li>
							<?php endif; ?>
							<?php foreach ( $items as $item ) : ?>
								<?php echo self::render_logged_item( $item, $ed_mode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
							<?php endforeach; ?>
						</ul>

						<?php if ( ! empty( $earlier ) ) : ?>
							<ul class="lld-food-earlier" data-lld-meal-earlier="<?php echo esc_attr( $meal_key ); ?>">
								<?php foreach ( $earlier as $entry ) : ?>
									<?php echo self::render_earlier_item( $entry, (string) ( $day['earlier_label'] ?? 'Earlier diary entry, nutrition unknown' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<ul class="lld-food-earlier" data-lld-meal-earlier="<?php echo esc_attr( $meal_key ); ?>" hidden></ul>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>

			<div class="lld-food-earlier-day" data-lld-earlier-day hidden></div>

			<?php if ( ! $ed_mode ) : ?>
				<section class="lld-card lld-food-week" data-lld-food-week<?php echo $locked ? ' hidden' : ''; ?>>
					<div class="lld-food-card-head">
						<h2>Weekly nutrition summary</h2>
						<span class="lld-field-help" data-lld-week-range></span>
					</div>
					<div data-lld-food-week-body>
						<?php if ( $features['weekly_summary'] ) : ?>
							<p class="lld-food-empty">Loading this week&#39;s summary...</p>
						<?php else : ?>
							<?php echo self::upgrade_box( 'weekly_summary', 'The weekly food summary is part of Premium.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<div class="lld-food-lower-grid">
				<section class="lld-card lld-food-recipes-card" data-lld-my-recipes>
					<div class="lld-food-card-head">
						<h2>My recipes</h2>
						<?php if ( $features['recipes'] ) : ?>
							<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-toggle-recipe-form aria-expanded="false" aria-controls="lld-recipe-form">Create recipe</button>
						<?php endif; ?>
					</div>
					<?php if ( $features['recipes'] ) : ?>
						<form class="lld-recipe-form" id="lld-recipe-form" data-lld-recipe-form hidden>
							<div class="lld-food-target-grid">
								<label class="lld-food-field">
									<span>Recipe name</span>
									<input type="text" name="name" maxlength="255" required placeholder="e.g. Lentil &amp; spinach bowl" />
								</label>
								<?php if ( ! $ed_mode ) : ?>
									<label class="lld-food-field">
										<span>Servings</span>
										<input type="number" name="servings" min="1" max="50" step="1" value="2" required />
									</label>
								<?php else : ?>
									<input type="hidden" name="servings" value="1" />
								<?php endif; ?>
							</div>
							<div class="lld-recipe-ing-search">
								<div class="lld-food-field">
									<label for="lld-recipe-ing-q">Add ingredient from food database</label>
									<div class="lld-inline-row">
										<input id="lld-recipe-ing-q" type="search" placeholder="Search ingredient (2+ letters)..." data-lld-recipe-ing-q />
										<?php if ( ! $ed_mode ) : ?>
											<label class="lld-food-field-inline" for="lld-recipe-ing-g">
												<span class="lld-sr">Amount in grams</span>
												<input id="lld-recipe-ing-g" type="number" min="1" max="5000" step="1" value="100" class="lld-gram-input" data-lld-recipe-ing-g />
											</label>
										<?php endif; ?>
									</div>
								</div>
								<ul class="lld-search-list lld-compact-list" data-lld-recipe-ing-hits></ul>
							</div>
							<ul class="lld-recipe-picked" data-lld-recipe-picked></ul>
							<div class="lld-food-form-actions">
								<button type="submit" class="lld-pill-btn" data-lld-recipe-submit>Save recipe</button>
								<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-cancel-recipe-edit hidden>Cancel edit</button>
								<div class="lld-food-inline-msg" data-lld-recipe-msg role="status" aria-live="polite"></div>
							</div>
						</form>
					<?php else : ?>
						<?php echo self::upgrade_box( 'recipes', 'Recipes in your food diary are part of Premium.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
					<?php endif; ?>
					<div data-lld-recipes-list>
						<p class="lld-food-empty">Loading recipes...</p>
					</div>
				</section>

				<?php if ( ! $ed_mode ) : ?>
				<section class="lld-card lld-food-suggestions-card" data-lld-suggestions>
					<div class="lld-food-card-head">
						<h2>Suggested recipes</h2>
						<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-toggle-prefs aria-expanded="false" aria-controls="lld-prefs-form">Allergies &amp; dislikes</button>
					</div>
					<form class="lld-prefs-form" id="lld-prefs-form" data-lld-prefs-form hidden>
						<p class="lld-field-help">Suggestions exclude anything matching your allergies or disliked ingredients.</p>
						<fieldset class="lld-allergen-grid">
							<legend>Allergies to avoid</legend>
							<?php foreach ( self::ALLERGENS as $akey => $alabel ) : ?>
								<label class="lld-check-pill">
									<input type="checkbox" name="allergies[]" value="<?php echo esc_attr( $akey ); ?>" />
									<span><?php echo esc_html( $alabel ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<label class="lld-food-field">
							<span>Disliked foods (comma separated)</span>
							<input type="text" name="dislikes" placeholder="e.g. mushrooms, olives" />
						</label>
						<div class="lld-food-form-actions">
							<button type="submit" class="lld-pill-btn lld-btn-sm">Save preferences</button>
							<div class="lld-food-inline-msg" data-lld-prefs-msg role="status" aria-live="polite"></div>
						</div>
					</form>
					<div data-lld-suggestions-list>
						<p class="lld-food-empty">Loading recipe suggestions...</p>
					</div>
				</section>
				<?php endif; ?>
			</div>

			<div class="lld-food-modal-backdrop" data-lld-add-modal hidden>
				<div class="lld-food-modal" role="dialog" aria-modal="true" aria-labelledby="lld-add-food-title">
					<header class="lld-food-modal-head">
						<div>
							<p class="lld-kicker">Add to log</p>
							<h2 id="lld-add-food-title">Add food</h2>
						</div>
						<div class="lld-food-modal-head-right">
							<?php if ( $features['barcode'] ) : ?>
								<a class="lld-btn-ghost lld-btn-sm" href="<?php echo esc_url( home_url( '/members/food/scan/' ) ); ?>">Scan barcode</a>
							<?php else : ?>
								<a class="lld-btn-ghost lld-btn-sm" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Barcode (Premium)</a>
							<?php endif; ?>
							<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-close-modal aria-label="Close">Close</button>
						</div>
					</header>

					<div class="lld-food-modal-meal">
						<label class="lld-food-field-inline" for="lld-modal-meal">
							<span>Meal</span>
							<select id="lld-modal-meal" data-lld-modal-meal>
								<?php foreach ( self::MEALS as $meal_key => $meal_label ) : ?>
									<option value="<?php echo esc_attr( $meal_key ); ?>"><?php echo esc_html( $meal_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div class="lld-pill-tabs lld-modal-tabs" role="tablist" aria-label="Food sources">
						<button type="button" role="tab" aria-selected="true" class="lld-tab is-active" data-lld-modal-tab="search">Search</button>
						<button type="button" role="tab" aria-selected="false" class="lld-tab" data-lld-modal-tab="recent">Recent</button>
						<button type="button" role="tab" aria-selected="false" class="lld-tab" data-lld-modal-tab="favourites">Favourites</button>
						<button type="button" role="tab" aria-selected="false" class="lld-tab" data-lld-modal-tab="recipes">My recipes</button>
						<button type="button" role="tab" aria-selected="false" class="lld-tab" data-lld-modal-tab="custom">Custom food</button>
					</div>

					<div class="lld-food-inline-msg" data-lld-modal-msg role="status" aria-live="polite"></div>

					<div class="lld-modal-pane" data-lld-pane="search">
						<div class="lld-search-box">
							<label class="lld-food-field" for="lld-food-search-input">
								<span class="lld-sr">Search foods</span>
								<input id="lld-food-search-input" type="search" placeholder="Search foods, e.g. oats, chicken breast, banana..." data-lld-search-input autocomplete="off" />
							</label>
						</div>
						<div class="lld-portion-picker" data-lld-portion-picker hidden>
							<div class="lld-portion-head">
								<strong data-lld-picked-name></strong>
								<span class="lld-source-badge" data-lld-picked-source></span>
							</div>
							<p class="lld-field-help" data-lld-picked-attribution></p>
							<?php if ( ! $ed_mode ) : ?>
								<div class="lld-portion-presets" data-lld-portion-presets></div>
								<div class="lld-portion-controls">
									<label class="lld-food-field">
										<span>Amount (g)</span>
										<input type="number" min="0.1" max="5000" step="0.5" value="100" data-lld-portion-grams />
									</label>
									<div class="lld-portion-preview" data-lld-portion-preview></div>
								</div>
							<?php endif; ?>
							<div class="lld-food-form-actions">
								<button type="button" class="lld-pill-btn" data-lld-confirm-log>Add to meal</button>
								<button type="button" class="lld-btn-ghost" data-lld-cancel-pick>Back to results</button>
							</div>
						</div>
						<ul class="lld-search-list" data-lld-search-results>
							<li class="lld-food-empty">Type at least two letters to search.</li>
						</ul>
					</div>

					<div class="lld-modal-pane" data-lld-pane="recent" hidden>
						<ul class="lld-search-list" data-lld-recent-results>
							<li class="lld-food-empty">Loading recent foods...</li>
						</ul>
					</div>

					<div class="lld-modal-pane" data-lld-pane="favourites" hidden>
						<ul class="lld-search-list" data-lld-favourites-results>
							<li class="lld-food-empty">Loading favourites...</li>
						</ul>
					</div>

					<div class="lld-modal-pane" data-lld-pane="recipes" hidden>
						<ul class="lld-search-list" data-lld-modal-recipes>
							<li class="lld-food-empty">Loading recipes...</li>
						</ul>
					</div>

					<div class="lld-modal-pane" data-lld-pane="custom" hidden>
						<?php if ( $features['custom_foods'] ) : ?>
							<form class="lld-custom-food-form" data-lld-custom-form>
								<div class="lld-food-target-grid">
									<label class="lld-food-field">
										<span>Food name</span>
										<input type="text" name="name" maxlength="255" required placeholder="e.g. Homemade soup" />
									</label>
									<?php if ( ! $ed_mode ) : ?>
										<label class="lld-food-field">
											<span>Serving size (g)</span>
											<input type="number" name="portion_g" min="0.1" max="5000" step="0.5" value="100" required />
										</label>
										<label class="lld-food-field">
											<span>Calories per serving (kcal)</span>
											<input type="number" name="kcal" min="0" max="9000" step="1" />
										</label>
										<label class="lld-food-field">
											<span>Protein (g)</span>
											<input type="number" name="protein_g" min="0" max="1000" step="0.5" />
										</label>
										<label class="lld-food-field">
											<span>Carbs (g)</span>
											<input type="number" name="carb_g" min="0" max="2000" step="0.5" />
										</label>
										<label class="lld-food-field">
											<span>Fat (g)</span>
											<input type="number" name="fat_g" min="0" max="1000" step="0.5" />
										</label>
										<label class="lld-food-field">
											<span>Fibre (g)</span>
											<input type="number" name="fibre_g" min="0" max="300" step="0.5" />
										</label>
									<?php endif; ?>
								</div>
								<div class="lld-food-form-actions">
									<button type="submit" class="lld-pill-btn">Save &amp; add to meal</button>
								</div>
							</form>
						<?php else : ?>
							<?php echo self::upgrade_box( 'custom_foods', 'Adding your own foods is part of Premium.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders initial Day Summary HTML for non-ED members.
	 *
	 * @param array<string,mixed> $day         Food day payload.
	 * @param bool                $kidney_mode Kidney mode flag.
	 * @return string
	 */
	private static function render_summary_inner( array $day, $kidney_mode ) {
		$totals = isset( $day['totals'] ) && is_array( $day['totals'] ) ? $day['totals'] : null;
		if ( null === $totals ) {
			return '';
		}
		// Only what the interface returned is printed: a total it left out (hidden by the safety matrix, or unknown
		// because a logged food has no value for it) gets no tile and no zero.
		$has_kcal = isset( $totals['kcal'] ) && is_numeric( $totals['kcal'] );
		$target   = isset( $day['target'] ) && is_array( $day['target'] ) ? $day['target'] : null;
		$left     = isset( $day['left'] ) && is_array( $day['left'] ) ? $day['left'] : null;
		$over     = isset( $day['over'] ) && is_array( $day['over'] ) ? $day['over'] : null;
		$kcal     = round( (float) ( $totals['kcal'] ?? 0 ) );
		$tgt_kcal = ( $target && null !== ( $target['kcal'] ?? null ) ) ? round( (float) $target['kcal'] ) : null;
		$is_over  = ! empty( $day['over_note'] ) || ( null !== $tgt_kcal && $kcal > $tgt_kcal );
		$pct      = ( $tgt_kcal && $tgt_kcal > 0 ) ? min( 100, round( ( $kcal / $tgt_kcal ) * 100 ) ) : 0;

		ob_start();
		$pregnant = ! empty( $day['pregnant'] );
		?>
		<?php if ( $has_kcal ) : ?>
		<div class="lld-food-cal-block">
			<div class="lld-food-cal-numbers">
				<div>
					<span class="lld-stat-label">Calories today</span>
					<strong class="lld-cal-total"><?php echo esc_html( number_format_i18n( $kcal ) ); ?> kcal</strong>
					<?php if ( null !== $tgt_kcal ) : ?>
						<span class="lld-cal-target">of <?php echo esc_html( number_format_i18n( $tgt_kcal ) ); ?> kcal target</span>
					<?php endif; ?>
				</div>
				<div class="lld-food-cal-state">
					<?php if ( ! empty( $day['over_note'] ) ) : ?>
						<span class="lld-food-over-note"><?php echo esc_html( (string) $day['over_note'] ); ?></span>
					<?php elseif ( $over && ! empty( $over['kcal'] ) ) : ?>
						<span class="lld-food-over-note">Over by <?php echo esc_html( (string) round( (float) $over['kcal'] ) ); ?> kcal today.</span>
					<?php elseif ( $left && null !== ( $left['kcal'] ?? null ) ) : ?>
						<span class="lld-food-left-note"><?php echo esc_html( number_format_i18n( round( (float) $left['kcal'] ) ) ); ?> kcal left</span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( null !== $tgt_kcal ) : ?>
				<div class="lld-food-cal-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $kcal ); ?>" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $tgt_kcal ); ?>">
					<span class="lld-food-cal-fill<?php echo $is_over ? ' is-over' : ''; ?>" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></span>
				</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<div class="lld-food-macros">
			<?php
			$macros = array(
				'protein_g' => 'Protein',
				'carb_g'    => 'Carbs',
				'fat_g'     => 'Fat',
				'fibre_g'   => 'Fibre',
			);
			foreach ( $macros as $key => $label ) :
				if ( ! isset( $totals[ $key ] ) || ! is_numeric( $totals[ $key ] ) ) {
					continue;
				}
				$val     = round( (float) $totals[ $key ], 1 );
				$tgt_val = ( $target && isset( $target[ $key ] ) && null !== $target[ $key ] ) ? round( (float) $target[ $key ] ) : null;
				?>
				<div class="lld-macro-tile">
					<span class="lld-macro-label"><?php echo esc_html( $label ); ?></span>
					<strong class="lld-macro-val"><?php echo esc_html( (string) $val ); ?> g</strong>
					<?php if ( null !== $tgt_val ) : ?>
						<?php if ( $over && ! empty( $over[ $key ] ) ) : ?>
							<span class="lld-macro-sub lld-macro-over">Target <?php echo esc_html( (string) $tgt_val ); ?> g · <?php echo esc_html( (string) round( (float) $over[ $key ] ) ); ?> g over</span>
						<?php elseif ( $left && isset( $left[ $key ] ) && null !== $left[ $key ] ) : ?>
							<span class="lld-macro-sub">of <?php echo esc_html( (string) $tgt_val ); ?> g · <?php echo esc_html( (string) round( (float) $left[ $key ] ) ); ?> g left</span>
						<?php else : ?>
							<span class="lld-macro-sub">Target <?php echo esc_html( (string) $tgt_val ); ?> g</span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders a logged Stage 3 food item row.
	 *
	 * @param array<string,mixed> $item    Food log entry.
	 * @param bool                $ed_mode ED safe mode flag.
	 * @return string
	 */
	private static function render_logged_item( array $item, $ed_mode ) {
		$id        = (int) ( $item['id'] ?? 0 );
		$food_id   = (int) ( $item['food_id'] ?? 0 );
		$food_kind = (string) ( $item['food_kind'] ?? 'food' );
		$name      = (string) ( $item['name'] ?? '' );
		$grams     = isset( $item['grams'] ) && is_numeric( $item['grams'] ) ? (float) $item['grams'] : 0;
		$unit_sfx  = 'recipe' === $food_kind ? ( 1.0 === (float) $grams ? ' serving' : ' servings' ) : ' g';
		$label     = ! empty( $item['portion_label'] ) ? (string) $item['portion_label'] : ( $grams > 0 ? round( $grams, 1 ) . $unit_sfx : '' );
		$kcal      = isset( $item['kcal'] ) && is_numeric( $item['kcal'] ) ? round( (float) $item['kcal'] ) : null;

		ob_start();
		?>
		<li class="lld-food-item" data-lld-item-id="<?php echo esc_attr( (string) $id ); ?>">
			<div class="lld-food-item-main">
				<strong class="lld-food-item-name"><?php echo esc_html( $name ); ?></strong>
				<?php if ( ! $ed_mode ) : ?>
					<span class="lld-food-item-meta">
						<?php
						$parts = array();
						if ( '' !== $label ) {
							$parts[] = $label;
						}
						if ( null !== $kcal ) {
							$parts[] = $kcal . ' kcal';
						}
						foreach ( array( 'P' => 'protein_g', 'C' => 'carb_g', 'F' => 'fat_g', 'Fib' => 'fibre_g' ) as $abbr => $k ) {
							if ( isset( $item[ $k ] ) && is_numeric( $item[ $k ] ) ) {
								$parts[] = $abbr . ' ' . round( (float) $item[ $k ], 1 ) . 'g';
							}
						}
						echo esc_html( implode( ' · ', $parts ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="lld-food-item-actions">
				<?php if ( $food_id > 0 ) : ?>
					<button type="button" class="lld-btn-ghost lld-btn-sm lld-food-fav-btn" data-lld-fav-toggle data-lld-fav-kind="<?php echo esc_attr( $food_kind ); ?>" data-lld-fav-id="<?php echo esc_attr( (string) $food_id ); ?>" data-lld-fav-name="<?php echo esc_attr( $name ); ?>" aria-pressed="false" aria-label="Save <?php echo esc_attr( $name ); ?> as favourite">&#9734;</button>
				<?php endif; ?>
				<?php if ( ! $ed_mode && $id > 0 ) : ?>
					<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-edit-item="<?php echo esc_attr( (string) $id ); ?>" data-lld-grams="<?php echo esc_attr( (string) $grams ); ?>" data-lld-food-kind="<?php echo esc_attr( $food_kind ); ?>">Edit</button>
				<?php endif; ?>
				<?php if ( $id > 0 ) : ?>
					<button type="button" class="lld-btn-ghost lld-btn-sm" data-lld-delete-item="<?php echo esc_attr( (string) $id ); ?>">Remove</button>
				<?php endif; ?>
			</div>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders a read-only Stage 2 earlier diary entry without numbers.
	 *
	 * @param array<string,mixed> $entry Stage 2 diary entry.
	 * @param string              $label Badge label.
	 * @return string
	 */
	private static function render_earlier_item( array $entry, $label ) {
		$desc = (string) ( $entry['description'] ?? '' );
		if ( '' === $desc && ! empty( $entry['planned'] ) ) {
			$desc = 'Planned meal';
		}
		ob_start();
		?>
		<li class="lld-food-earlier-item">
			<div class="lld-food-item-main">
				<span class="lld-earlier-badge"><?php echo esc_html( $label ); ?></span>
				<strong><?php echo esc_html( '' !== $desc ? $desc : 'Earlier diary entry' ); ?></strong>
				<?php if ( ! empty( $entry['portion'] ) ) : ?>
					<span class="lld-food-item-meta">Portion: <?php echo esc_html( (string) $entry['portion'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $entry['photo'] ) ) : ?>
				<a class="lld-btn-ghost lld-btn-sm" href="<?php echo esc_url( (string) $entry['photo'] ); ?>">Photo</a>
			<?php endif; ?>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Wraps the page content inside the Lifeline Daily shell and injects Stage 3 assets.
	 *
	 * @param string $title  Page title.
	 * @param string $main   Main HTML content.
	 * @param bool   $script Whether to print stage3-logging.js.
	 */
	private static function output( $title, $main, $script ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/stage3.css', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION );
		if ( $script ) {
			wp_enqueue_script( self::LOGGING_HANDLE, plugins_url( 'assets/stage3-logging.js', LLD_MEMBERS_FILE ), array(), LLD_MEMBERS_VERSION, true );
		}
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
		wp_print_styles( array( self::HANDLE ) );
		$styles = (string) ob_get_clean();
		$head   = strpos( $page, '</head>' );
		if ( false !== $head ) {
			$page = substr_replace( $page, $styles . '</head>', $head, 7 );
		}
		if ( $script ) {
			ob_start();
			wp_print_scripts( array( self::LOGGING_HANDLE ) );
			$scripts = (string) ob_get_clean();
			$body    = strrpos( $page, '</body>' );
			if ( false !== $body ) {
				$page = substr_replace( $page, $scripts . '</body>', $body, 7 );
			}
		}
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shell handles its own escaping.
	}
}
