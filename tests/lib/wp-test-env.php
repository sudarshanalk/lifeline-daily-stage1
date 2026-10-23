<?php
/* Test-environment must-use plugin (harness only, never deployed): the production snapshot's hosting vendor plugins need the
 * Hostinger platform and fatal elsewhere; caching, SMTP and consent plugins would alter responses. Dropped from the active
 * list for the test WordPress. Placed next to the repository's lld-tools by tests/lib/wp-stack.mjs. */
add_filter('option_active_plugins', function ($plugins) {
  return array_values(array_filter((array)$plugins, function ($p) {
    return !preg_match('#^(hostinger|litespeed-cache|wp-mail-smtp|cookie-law-info|cookieyes|wordfence|jetpack)#', (string)$p);
  }));
});
