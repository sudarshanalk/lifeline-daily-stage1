<?php
/**
 * Staging mail catcher (Build Method P0-4: "staging never emails real people").
 *   On staging (LLD_ENV 'staging') wp_mail never sends: each message is appended to
 *   <LLD_PRIVATE_DIR>/mail/<date>.jsonl, outside the web root, and wp_mail reports success so flows carry on.
 *   Inert everywhere else: production has no LLD_ENV, and local and CI use Mailpit (lld-members LLD_SMTP_HOST).
 *   `wp lld mail [--last=<n>]` prints the newest captured messages (sign-in codes for staging tests).
 * Second and third layers live in staging's wp-config.php: WPMS_DO_NOT_SEND, and WP Mail SMTP pointed at a closed
 * local port. The pure builders take plain values so they are tested without WordPress (tests/lead/staging-mail.test.mjs).
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

/** Only staging catches. Anything else (unset, production, local, ci, a typo) leaves wp_mail alone. */
function lld_mail_catching(?string $env): bool { return $env === 'staging'; }

/** One captured message as a log record. Attachments are listed by file name only. */
function lld_mail_record(array $atts, string $time): array {
  $list = fn($v) => array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : explode(',', (string)$v)), 'strlen'));
  $headers = $atts['headers'] ?? [];
  $headers = is_array($headers) ? array_values(array_map('strval', $headers)) : array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$headers)), 'strlen'));
  return [
    'time' => $time,
    'to' => $list($atts['to'] ?? ''),
    'subject' => (string)($atts['subject'] ?? ''),
    'headers' => $headers,
    'attachments' => array_map('basename', $list($atts['attachments'] ?? [])),
    'message' => mb_substr((string)($atts['message'] ?? ''), 0, 65536),
  ];
}

/* ---------------------------------------------------------------- WordPress wiring -------------------------------- */
if (!function_exists('add_action')) return;

function lld_mail_dir(): string {
  $base = defined('LLD_PRIVATE_DIR') ? (string)LLD_PRIVATE_DIR : dirname(ABSPATH) . '/lld-private';
  return rtrim($base, '/\\') . '/mail';
}

if (lld_mail_catching(defined('LLD_ENV') ? (string)LLD_ENV : null)) {
  // first in line, so no other pre_wp_mail callback (a queue, a mailer) sees the message before it is caught
  add_filter('pre_wp_mail', function ($return, array $atts) {
    $dir = lld_mail_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . gmdate('Y-m-d') . '.jsonl';
    $line = json_encode(lld_mail_record($atts, gmdate('c')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) error_log('lld staging mail catcher: could not write ' . $file . ' (message still not sent)');
    else @chmod($file, 0600);
    return true;
  }, PHP_INT_MIN, 2);
}

if (defined('WP_CLI') && WP_CLI) {
  /** wp lld mail [--last=<n>]: the newest captured messages on staging, newest last. */
  WP_CLI::add_command('lld mail', function (array $args, array $assoc) {
    $n = max(1, (int)($assoc['last'] ?? 5));
    $files = glob(lld_mail_dir() . '/*.jsonl') ?: [];
    sort($files);
    $lines = [];
    foreach (array_reverse($files) as $f) {
      $lines = array_merge(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], $lines);
      if (count($lines) >= $n) break;
    }
    if (!$lines) WP_CLI::line('No captured mail in ' . lld_mail_dir() . (lld_mail_catching(defined('LLD_ENV') ? (string)LLD_ENV : null) ? '' : ' (catcher inactive: LLD_ENV is not staging)'));
    foreach (array_slice($lines, -$n) as $l) WP_CLI::line($l);
  });
}
