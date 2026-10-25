<?php
/**
 * Plugin Name: Lifeline Daily Members
 * Description: Stage 2 membership plugin: accounts, health journey, payments records, and member screens.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Author: Lifeline Daily
 * Text Domain: lld-members
 *
 * Member pages, premium content and member files are rendered only by this plugin.
 * Switching it off leaves the blog, calculators and landing pages on the normal WordPress theme.
 *
 * @package LLD_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LLD_MEMBERS_VERSION', '0.1.0' );
define( 'LLD_MEMBERS_FILE', __FILE__ );
define( 'LLD_MEMBERS_DIR', plugin_dir_path( __FILE__ ) );

require_once LLD_MEMBERS_DIR . 'includes/helpers.php';
require_once LLD_MEMBERS_DIR . 'includes/class-schema.php';
require_once LLD_MEMBERS_DIR . 'includes/class-crypto.php';
require_once LLD_MEMBERS_DIR . 'includes/class-clock.php';
require_once LLD_MEMBERS_DIR . 'includes/class-photos.php';
require_once LLD_MEMBERS_DIR . 'includes/class-integrity.php';
require_once LLD_MEMBERS_DIR . 'includes/class-erasure.php';
require_once LLD_MEMBERS_DIR . 'includes/class-privacy.php';
require_once LLD_MEMBERS_DIR . 'includes/class-access.php';
require_once LLD_MEMBERS_DIR . 'includes/class-mail.php';
require_once LLD_MEMBERS_DIR . 'includes/class-auth.php';
require_once LLD_MEMBERS_DIR . 'includes/class-account.php';
require_once LLD_MEMBERS_DIR . 'includes/class-content.php';
require_once LLD_MEMBERS_DIR . 'includes/class-safety.php';
require_once LLD_MEMBERS_DIR . 'includes/class-personal.php';
require_once LLD_MEMBERS_DIR . 'includes/class-programme.php';
require_once LLD_MEMBERS_DIR . 'includes/class-fixtures.php';
require_once LLD_MEMBERS_DIR . 'includes/class-stripe.php';
require_once LLD_MEMBERS_DIR . 'includes/components.php';
require_once LLD_MEMBERS_DIR . 'includes/class-screens.php';
require_once LLD_MEMBERS_DIR . 'includes/class-rest.php';
require_once LLD_MEMBERS_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'LLD_Members_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'LLD_Members_Plugin', 'boot' ) );
