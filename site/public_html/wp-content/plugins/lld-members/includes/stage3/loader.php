<?php
/**
 * Stage 3 loader. Every Stage 3 class lives in this folder (Build Method Stage 3 §3) and sits behind a
 * feature switch, so with every switch off the plugin behaves exactly as Stage 2 did (NFR3-06).
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-schema-stage3.php';
require_once __DIR__ . '/class-features.php';
require_once __DIR__ . '/screens/screens.php';
require_once __DIR__ . '/class-data.php';
require_once __DIR__ . '/class-erasure-stage3.php';
require_once __DIR__ . '/class-api.php';
require_once __DIR__ . '/class-food-log.php';
require_once __DIR__ . '/class-barcode.php';
require_once __DIR__ . '/class-recipes.php';
require_once __DIR__ . '/class-health-log.php';
require_once __DIR__ . '/calculators/class-calorie-target.php';
require_once __DIR__ . '/calculators/class-goal-timeline.php';
require_once __DIR__ . '/calculators/class-heart-risk.php';
require_once __DIR__ . '/calculators/class-bp-check.php';
require_once __DIR__ . '/calculators/class-metabolic.php';
require_once __DIR__ . '/calculators/class-fitness-age.php';
require_once __DIR__ . '/calculators/class-hr-zones.php';
require_once __DIR__ . '/calculators/class-sleep-stress.php';
require_once __DIR__ . '/class-saved-results.php';
require_once __DIR__ . '/class-reminders.php';
require_once __DIR__ . '/class-shares.php';
require_once __DIR__ . '/class-installable.php';
require_once __DIR__ . '/class-consent-stage3.php';
require_once __DIR__ . '/class-export-stage3.php';
require_once __DIR__ . '/class-content-stage3.php';
require_once __DIR__ . '/class-content-hw.php';
require_once __DIR__ . '/class-safety-stage3.php';
require_once __DIR__ . '/engine/class-rule-catalogue.php';
require_once __DIR__ . '/engine/class-decision-engine.php';
require_once __DIR__ . '/engine/class-insights-runner.php';
require_once __DIR__ . '/healthy-weight/class-hw-where.php';
require_once __DIR__ . '/healthy-weight/class-hw-festival.php';
require_once __DIR__ . '/healthy-weight/class-hw-rules.php';
require_once __DIR__ . '/healthy-weight/class-hw-answers.php';
require_once __DIR__ . '/healthy-weight/class-hw-programme.php';
require_once __DIR__ . '/class-stripe-api.php';
require_once __DIR__ . '/class-access-tiers.php';
require_once __DIR__ . '/class-membership.php';
require_once __DIR__ . '/class-terms-gate.php';
require_once __DIR__ . '/class-pause.php';
require_once __DIR__ . '/class-analytics.php';
require_once __DIR__ . '/class-weekly-report-stage3.php';
require_once __DIR__ . '/class-calculator-count.php';
require_once __DIR__ . '/signin/class-email-codes.php';
require_once __DIR__ . '/signin/class-identity-provider.php';
require_once __DIR__ . '/signin/class-sign-in.php';
require_once __DIR__ . '/class-goal-rules.php';
require_once __DIR__ . '/class-goal-journey.php';
require_once __DIR__ . '/admin/class-admin-log.php';
require_once __DIR__ . '/admin/class-admin-roles.php';
require_once __DIR__ . '/admin/class-admin-security.php';
require_once __DIR__ . '/admin/class-admin-members.php';
require_once __DIR__ . '/admin/class-admin-pages.php';
require_once __DIR__ . '/admin/class-admin-front.php';
require_once __DIR__ . '/admin/class-admin-test-routes.php';

/**
 * Hooks the Stage 3 layer into the Stage 2 plugin. Called from LLD_Members_Plugin::boot().
 */
function lld_members_stage3_boot() {
	LLD_Members_Features::boot();
	lld_members_stage3_screens_boot();
	LLD_Members_Stage3_Erasure::boot();
	LLD_Members_Stage3_Content::boot();
	LLD_Members_HW_Content::boot();
	LLD_Members_Stage3_Safety::boot();
	LLD_Members_Insights_Runner::boot();
	LLD_Members_Stripe_API::boot();
	LLD_Members_Access_Tiers::boot();
	LLD_Members_Membership::boot();
	LLD_Members_Terms_Gate::boot();
	LLD_Members_Pause::boot();
	LLD_Members_Analytics::boot();
	LLD_Members_Stage3_Report::boot();
	LLD_Members_Calculator_Count::boot();
	LLD_Members_Sign_In::boot();
	LLD_Members_Goal_Journey::boot();
	LLD_Members_HW_Programme::boot(); // P3-35: the day's step on a Healthy Weight track (lld_members_day_items)
	LLD_Members_Reminders::boot();
	LLD_Members_Shares::boot();
	LLD_Members_Installable::boot();
	LLD_Members_Stage3_Consent::boot();
	LLD_Members_Stage3_Export::boot();
	// The admin panel (P3-87): always on, since it is how a feature is turned off in an emergency (FR3-74).
	LLD_Members_Admin_Log::boot();
	LLD_Members_Admin_Roles::boot();
	LLD_Members_Admin_Security::boot();
	LLD_Members_Admin_Members::boot();
	LLD_Members_Admin_Pages::boot();
	// The admin pages in My account (P3-87b, owner 6 Nov 2026).
	LLD_Members_Admin_Front::boot();
	LLD_Members_Admin_Test_Routes::boot();
	add_action( 'rest_api_init', array( 'LLD_Members_Stage3_API', 'register' ) );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once __DIR__ . '/class-cli-stage3.php';
		LLD_Members_Stage3_CLI::register();
	}
}
