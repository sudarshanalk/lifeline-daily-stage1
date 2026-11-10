<?php
/**
 * Staging mail catcher (Build Method P0-4: "staging never emails real people").
 *   On staging (LLD_ENV 'staging') wp_mail never sends: each message is appended to
 *   <LLD_PRIVATE_DIR>/mail/<date>.jsonl, outside the web root, and wp_mail reports success so flows carry on.
 *   Inert everywhere else: production has no LLD_ENV, and local and CI use Mailpit (lld-members LLD_SMTP_HOST).
 *   `wp lld mail [--last=<n>]` prints the newest captured messages (sign-in codes for staging tests).
 *   One exception: when every recipient is on staging's LLD_STAGING_MAIL_ALLOW list (the admin's own inbox), the
 *   message is also delivered, subject prefixed "[Staging]", and logged with "delivered".
 * Second and third layers live in staging's wp-config.php: WPMS_DO_NOT_SEND, and WP Mail SMTP pointed at a closed
 * local port. The pure builders take plain values so they are tested without WordPress (tests/lead/staging-mail.test.mjs).
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

/** Only staging catches. Anything else (unset, production, local, ci, a typo) leaves wp_mail alone. */
function lld_mail_catching(?string $env): bool { return $env === 'staging'; }

/** A captured message may still be delivered when every recipient is on the allow-list (staging's wp-config
 *  LLD_STAGING_MAIL_ALLOW, comma-separated). The owner asked for this on 28 Oct 2026 so the admin account's sign-in
 *  codes (NFR2-02) reach its own inbox. Empty list, empty recipients, or any other recipient: not delivered. */
function lld_mail_allowed(array $to, string $allow): bool {
  $norm = fn($a) => strtolower(trim(preg_match('/<([^>]+)>/', (string)$a, $m) ? $m[1] : (string)$a));
  $list = array_values(array_filter(array_map($norm, explode(',', $allow)), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
  $to = array_values(array_filter(array_map($norm, $to), 'strlen'));
  if (!$list || !$to) return false;
  foreach ($to as $a) if (!in_array($a, $list, true)) return false;
  return true;
}

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
    $record = lld_mail_record($atts, gmdate('c'));
    // Allow-listed recipients only (the admin's own inbox): delivered with PHP mail(), not WP Mail SMTP, which staging
    // points at a closed port. Attachments are never sent. Everything else stays caught.
    if (lld_mail_allowed($record['to'], defined('LLD_STAGING_MAIL_ALLOW') ? (string)LLD_STAGING_MAIL_ALLOW : '') && function_exists('mail')) {
      $html = stripos((string)apply_filters('wp_mail_content_type', 'text/plain'), 'html') !== false || preg_match('/<(html|body|p|div|table)\b/i', $record['message']);
      $from = 'Lifeline Daily staging <no-reply@' . preg_replace('/^www\./', '', (string)parse_url(home_url(), PHP_URL_HOST)) . '>';
      $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: " . ($html ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
      $record['delivered'] = (bool)@mail(implode(', ', $record['to']), '[Staging] ' . $record['subject'], $record['message'], $headers);
    }
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
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
