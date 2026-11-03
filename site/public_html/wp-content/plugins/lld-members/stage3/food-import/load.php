<?php
/**
 * Loads the Stage 3 food importer. The Stage 2 plugin does not include this file.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
