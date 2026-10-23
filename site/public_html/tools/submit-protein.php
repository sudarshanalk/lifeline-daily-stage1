<?php
/* ============================================================================
 * submit-protein.php  —  public entrypoint for the Daily Protein Target tool
 * ----------------------------------------------------------------------------
 * Deliberately SEPARATE from submit.php so the live diabetes pipeline is never
 * touched. Reuses api/config.php, api/mailer.php and api/ratelimit.php; has its
 * own templates. FR1-14: same request checks as submit.php (method, content-type,
 * body size, origin allow-list, DEC-16 rate limit, honeypot), 400 on validation,
 * range checks per Data Model §4.1, 502 on send failure, one SCH-07 log line
 * per outcome (DEC-20). Payload key names are frozen (NFR1-04).
 *
 * Upload to:  public_html/tools/submit-protein.php
 * ==========================================================================*/

header('Content-Type: application/json');

$cfg = require __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/mailer.php';
require_once __DIR__ . '/api/ratelimit.php';
$T = require __DIR__ . '/api/templates-protein.php';

function respond_protein(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

$ip = lld_client_ip($cfg);

/* ---- method + content-type + size ----------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond_protein(405, ['status' => 'error', 'errors' => ['Method not allowed.']]);
}
$ctype = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''))[0]));
if ($ctype !== 'application/json') {
  lld_log('protein', 'rejected_validation', $ip, $cfg);
  respond_protein(400, ['status' => 'error', 'errors' => ['Content-Type must be application/json.']]);
}
$maxBody = (int)($cfg['max_body_bytes'] ?? 8192);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
  lld_log('protein', 'rejected_validation', $ip, $cfg);
  respond_protein(413, ['status' => 'error', 'errors' => ['Request body too large.']]);
}

/* ---- origin allow-list ----------------------------------------------------- */
$allowed = $cfg['allowed_origins'] ?? [];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($allowed) && $origin !== '' && !in_array($origin, $allowed, true)) {
  lld_log('protein', 'rejected_validation', $ip, $cfg);
  respond_protein(403, ['status' => 'error', 'errors' => ['Origin not allowed.']]);
}

/* ---- rate limit (DEC-16: per IP per endpoint) ------------------------------ */
if (!rateLimitOk($ip, $cfg, 'protein')) {
  lld_log('protein', 'rejected_rate', $ip, $cfg);
  respond_protein(429, ['status' => 'rate_limited']);   // TC-FR1-14-AS-03 fixes the body
}

/* ---- parse ------------------------------------------------------------------ */
$raw = file_get_contents('php://input');
if (strlen($raw) > $maxBody) {
  lld_log('protein', 'rejected_validation', $ip, $cfg);
  respond_protein(413, ['status' => 'error', 'errors' => ['Request body too large.']]);
}
$p = json_decode($raw, true);
if (!is_array($p)) {
  lld_log('protein', 'rejected_validation', $ip, $cfg);
  respond_protein(400, ['status' => 'error', 'errors' => ['Malformed request.']]);
}

/* ---- honeypot: silent 200, no send, outcome-only log line ------------------- */
if (!empty($p['company'])) {
  lld_log('protein', 'honeypot', $ip, $cfg);
  respond_protein(200, ['status' => 'ok']);
}

/* ---- validation (Data Model §4.1) ------------------------------------------- */
$errors = [];
if (empty($p['consent']) || $p['consent'] !== true) $errors[] = 'Consent is required.';
$email = isset($p['email']) ? trim((string)$p['email']) : '';
if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (($p['tool'] ?? '') !== 'protein') $errors[] = 'Missing or wrong tool identifier.';
if (isset($p['firstName']) && !is_string($p['firstName'])) $errors[] = 'First name must be text.';
elseif (isset($p['firstName']) && mb_strlen($p['firstName']) > 60) $errors[] = 'First name is too long.';

$suppressed = !empty($p['suppressed']);

// A non-suppressed result MUST carry a numeric target in range. A suppressed one's numbers are never read (renal safety).
$num = function (string $key, float $min, float $max, bool $nullable) use ($p, &$errors): void {
  if (!array_key_exists($key, $p) || $p[$key] === null) { if (!$nullable) $errors[] = "$key is required."; return; }
  if (!is_numeric($p[$key]) || (float)$p[$key] < $min || (float)$p[$key] > $max) $errors[] = "$key out of range.";
};
if (!$suppressed) {
  if (!isset($p['targetG']) || !is_numeric($p['targetG'])) $errors[] = 'Result payload is incomplete.';
  else $num('targetG', 20, 400, false);
  $num('lowG', 20, 400, true);
  $num('highG', 20, 600, true);   // a 2.0 g/kg band on a heavy basis weight can exceed the 400 g target ceiling
  $num('gPerKg', 0.5, 3.0, true);
  $num('perMeal', 20, 40, true);
}
$num('meals', 2, 5, true);
if (isset($p['sources']) && $p['sources'] !== null) {
  if (!is_array($p['sources']) || count($p['sources']) > 20) $errors[] = 'sources out of range.';
  else foreach ($p['sources'] as $s) if (!is_string($s) || mb_strlen($s) > 160) { $errors[] = 'sources out of range.'; break; }
}

if ($errors) {
  lld_log('protein', 'rejected_validation', $ip, $cfg);
  respond_protein(400, ['status' => 'error', 'errors' => $errors]);
}

/* ---- template selection ---------------------------------------------------
 * SAFETY: the renal branch is chosen FIRST and unconditionally. A user with
 * kidney disease must never receive an email containing a g/day figure — the
 * on-screen suppression is worthless if the email undoes it.
 * -------------------------------------------------------------------------- */
$key = $suppressed ? 'protein_renal' : 'protein_standard';
$tpl = $T[$key];

/* ---- token data (every interpolated value is escaped) ---------------------- */
$firstName = trim((string)($p['firstName'] ?? ''));
$data = [
  'firstName'  => $firstName !== '' ? htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : 'there',
  'disclaimer' => $T['_shared']['disclaimer'],
  'year'       => date('Y'),
];

if (!$suppressed) {
  $range = (isset($p['lowG'], $p['highG']) && $p['lowG'] != $p['highG'])
    ? '<p>Your working range is <strong>' . (int)$p['lowG'] . '–' . (int)$p['highG'] .
      ' g per day</strong>. Anywhere in that range is fine — it is a target, not a tightrope.</p>'
    : '';

  $sources = '';
  if (!empty($p['sources']) && is_array($p['sources'])) {
    $items = '';
    foreach ($p['sources'] as $s) {
      $items .= '<li>' . htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $sources = '<p style="font-size:13px;color:#666">Guidelines applied to you:</p><ul style="font-size:13px;color:#666">' . $items . '</ul>';
  }

  $data += [
    'targetG'    => (int)$p['targetG'],
    'perMeal'    => isset($p['perMeal']) ? (int)$p['perMeal'] : 0,
    'meals'      => isset($p['meals'])   ? (int)$p['meals']   : 3,
    'gPerKg'     => isset($p['gPerKg'])  ? (float)$p['gPerKg'] : 0,
    'rangeBlock' => $range,
    'sources'    => $sources,
  ];
}

/* ---- render ---------------------------------------------------------------- */
$fill = function (string $s) use ($data): string {
  return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($data) {
    return isset($data[$m[1]]) ? (string)$data[$m[1]] : '';
  }, $s);
};

$subject = $fill($tpl['subject']);
$body    = $fill($tpl['body']) . '<hr style="border:0;border-top:1px solid #e4e6e5;margin:24px 0">'
         . '<p style="font-size:12px;color:#888">' . $T['_shared']['disclaimer'] . '</p>';

/* ---- send ------------------------------------------------------------------ */
$res = sendMail($email, $subject, $body, $cfg);
if (empty($res['ok'])) {
  lld_log('protein', 'smtp_error', $ip, $cfg);
  respond_protein(502, ['status' => 'error', 'errors' => ['Could not send the email.']]);
}
lld_log('protein', 'sent', $ip, $cfg);
respond_protein(200, ['status' => 'ok']);
