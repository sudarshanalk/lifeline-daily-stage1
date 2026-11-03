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
require_once __DIR__ . '/calculators/class-calorie-target.php';
require_once __DIR__ . '/class-content-stage3.php';
require_once __DIR__ . '/class-safety-stage3.php';
require_once __DIR__ . '/engine/class-rule-catalogue.php';
require_once __DIR__ . '/engine/class-decision-engine.php';
require_once __DIR__ . '/engine/class-insights-runner.php';
require_once __DIR__ . '/class-stripe-api.php';
require_once __DIR__ . '/class-access-tiers.php';
require_once __DIR__ . '/class-membership.php';
require_once __DIR__ . '/class-pause.php';

/**
 * Hooks the Stage 3 layer into the Stage 2 plugin. Called from LLD_Members_Plugin::boot().
 */
function lld_members_stage3_boot() {
	LLD_Members_Features::boot();
	lld_members_stage3_screens_boot();
	LLD_Members_Stage3_Erasure::boot();
	LLD_Members_Stage3_Content::boot();
	LLD_Members_Stage3_Safety::boot();
	LLD_Members_Insights_Runner::boot();
	LLD_Members_Stripe_API::boot();
	LLD_Members_Access_Tiers::boot();
	LLD_Members_Membership::boot();
	LLD_Members_Pause::boot();
	add_action( 'rest_api_init', array( 'LLD_Members_Stage3_API', 'register' ) );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once __DIR__ . '/class-cli-stage3.php';
		LLD_Members_Stage3_CLI::register();
	}
}
