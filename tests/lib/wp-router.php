<?php
// Test router for PHP's built-in server over the production snapshot's WordPress document root (tools/local/local-stack.ps1
// data) with the repository's `site/public_html` overlaid: files that exist in the overlay are served from it, the must-use
// plugin directory is the repository's (WPMU_PLUGIN_DIR/URL), the tools backend folder is denied like production, and
// everything else goes to WordPress. The snapshot folder is never written. Env: LLD_OVERLAY (absolute path of site/public_html).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$overlay = rtrim(getenv('LLD_OVERLAY') ?: '', '/\\');
if ($path !== '/' && preg_match('#^/tools/api/#', $path)) { http_response_code(403); exit; }
if ($overlay !== '' && $path !== '/' && is_file($overlay . $path)) {
  $f = $overlay . $path;
  if (preg_match('/\.php$/', $f)) { chdir(dirname($f)); require $f; exit; }
  $types = ['html' => 'text/html; charset=utf-8', 'js' => 'application/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png'];
  $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
  header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
  if (in_array($ext, ['html', 'js', 'css'], true)) {
    // deploy.sh substitutes the per-environment origin (DEC-34); the harness substitutes the served origin the same way
    $origin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    echo str_replace('__LLD_ORIGIN__', $origin, file_get_contents($f)); exit;
  }
  readfile($f); exit;
}
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ($path !== '/' && is_file($file)) return false;
// outbound HTTP and cron are disabled by the local wp-config (WP_HTTP_BLOCK_EXTERNAL, DISABLE_WP_CRON). The router runs before
// wp-config, so defining them here as well made wp-config emit "already defined" warnings at the top of every page.
// LLD_MU_DIR (optional) is a harness-assembled mu-plugins dir: the repository's lld-tools plus a test-environment plugin that
// deactivates the hosting vendor plugins (they require the Hostinger platform and fatal elsewhere)
$ov = getenv('LLD_OVERLAY'); if ($ov && !defined('LLD_TOOLS_DOCROOT')) define('LLD_TOOLS_DOCROOT', rtrim($ov, '/'));   // the plugin reads tools/status.json and the tool files from the overlay
$mu = getenv('LLD_MU_DIR') ?: ($overlay !== '' ? $overlay . '/wp-content/mu-plugins' : '');
if ($mu !== '') {
  define('WPMU_PLUGIN_DIR', $mu);
  define('WPMU_PLUGIN_URL', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/wp-content/mu-plugins');
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
