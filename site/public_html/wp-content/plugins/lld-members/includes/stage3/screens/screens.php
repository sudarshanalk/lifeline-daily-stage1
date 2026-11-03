<?php
/**
 * Stage 3 screens (Antigravity's packages, Build Method Stage 3 §4): every `class-<name>.php` in this folder defines
 * the class `LLD_Members_<Name>` (dashes to underscores, each word capitalised: class-account-hub.php →
 * LLD_Members_Account_Hub) with a static `boot()`. The loader requires each file and calls `boot()`; the class itself
 * checks its feature switch before it registers a route, hook, asset or menu item, so with every switch off nothing
 * changes for a Stage 2 member (NFR3-06). Adding a screen needs no edit to the loader.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The screen classes found in this folder, in file-name order.
 *
 * @return string[] Class names.
 */
function lld_members_stage3_screens() {
	static $classes = null;
	if ( null !== $classes ) {
		return $classes;
	}
	$classes = array();
	$files   = glob( __DIR__ . '/class-*.php' );
	$files   = is_array( $files ) ? $files : array();
	sort( $files );
	foreach ( $files as $file ) {
		require_once $file;
		$words     = explode( '-', substr( basename( $file, '.php' ), 6 ) );
		$classes[] = 'LLD_Members_' . implode( '_', array_map( 'ucfirst', $words ) );
	}
	return $classes;
}

/**
 * Boot every screen class that exists and has a static boot(). Called from lld_members_stage3_boot().
 */
function lld_members_stage3_screens_boot() {
	foreach ( lld_members_stage3_screens() as $class ) {
		if ( class_exists( $class, false ) && is_callable( array( $class, 'boot' ) ) ) {
			call_user_func( array( $class, 'boot' ) );
		}
	}
}
