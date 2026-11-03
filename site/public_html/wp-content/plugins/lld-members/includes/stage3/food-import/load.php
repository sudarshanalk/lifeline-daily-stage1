<?php
/**
 * Loads the Stage 3 food importer (P3-40). The Stage 3 loader includes this file only when a command
 * or a later package needs it, so the Stage 2 plugin never carries it on an ordinary request.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-schema-stage3.php';
require_once dirname( __DIR__ ) . '/class-features.php';
require_once __DIR__ . '/class-food-licence.php';
require_once __DIR__ . '/class-food-row.php';
require_once __DIR__ . '/class-food-source.php';
require_once __DIR__ . '/class-food-usda.php';
require_once __DIR__ . '/class-food-off.php';
require_once __DIR__ . '/class-food-sources.php';
require_once __DIR__ . '/class-food-switch.php';
require_once __DIR__ . '/class-food-store.php';
require_once __DIR__ . '/class-food-schema.php';
require_once __DIR__ . '/class-food-wp-store.php';
require_once __DIR__ . '/class-food-search.php';
require_once __DIR__ . '/class-food-import.php';
