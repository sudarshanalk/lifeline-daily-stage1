<?php
/* WP-CLI --require file for the test WordPress: point the must-use plugin directory at the harness-assembled one
 * (LLD_MU_DIR, see tests/lib/wp-stack.mjs) so `wp lld …` runs the repository's plugin against the snapshot database. */
$ov = getenv('LLD_OVERLAY'); if ($ov && !defined('LLD_TOOLS_DOCROOT')) define('LLD_TOOLS_DOCROOT', rtrim($ov, '/'));   // the plugin reads tools/status.json and the tool files from the overlay
$mu = getenv('LLD_MU_DIR');
if ($mu && !defined('WPMU_PLUGIN_DIR')) define('WPMU_PLUGIN_DIR', $mu);
// (outbound HTTP and cron are already disabled by the local wp-config)
