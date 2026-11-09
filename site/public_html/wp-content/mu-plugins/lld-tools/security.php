<?php
/**
 * C1 staging security checks (L7, 9 Nov 2026): small hardening for every WordPress response. Static files keep the
 * headers of the managed .htaccess block (NFR1-03, NFR1-15); nothing here changes them.
 *
 * - X-Frame-Options SAMEORIGIN: WordPress pages may be framed only by this site (clickjacking). The calculators'
 *   own .html pages are static files and keep their frame-ancestors rule from .htaccess.lld.
 * - No X-Powered-By (the PHP version).
 * - Visitors cannot list the site's user accounts through the REST interface (/wp/v2/users) or find an account by
 *   number (?author=N); signed-in editors keep both. Author pages by name stay (the Editorial Board design).
 */
if (!defined('ABSPATH')) exit;

/** True for a request whose user may list accounts (an editor or admin signed in). */
function lld_security_can_list_users(): bool {
  return function_exists('current_user_can') && current_user_can('list_users');
}

if (function_exists('add_action')) {
  add_action('send_headers', function () {
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    if (function_exists('header_remove')) header_remove('X-Powered-By');
  });
  // ?author=<number> for a visitor: not found, rather than a redirect that names the account.
  add_action('parse_request', function ($wp) {
    if (lld_security_can_list_users() || !isset($wp->query_vars['author']) || !is_numeric($wp->query_vars['author'])) return;
    unset($wp->query_vars['author']);
    $wp->query_vars['error'] = '404';
  });
}
if (function_exists('add_filter')) {
  add_filter('rest_endpoints', function ($endpoints) {
    if (lld_security_can_list_users()) return $endpoints;
    foreach (array_keys((array)$endpoints) as $route) {
      if (preg_match('#^/wp/v2/users(?:$|/\(\?P<id>)#', (string)$route)) unset($endpoints[$route]);
    }
    return $endpoints;
  });
}
