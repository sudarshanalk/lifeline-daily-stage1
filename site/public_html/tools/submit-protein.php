<?php
/* ============================================================================
 * submit-protein.php  —  public entrypoint for the Daily Protein Target tool
 * ----------------------------------------------------------------------------
 * Deliberately SEPARATE from submit.php so the live diabetes pipeline is never
 * touched. Reuses api/config.php and api/mailer.php; has its own templates.
 *
 * Upload to:  public_html/tools/submit-protein.php
 * ==========================================================================*/

header('Content-Type: application/json');

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/mailer.php';
$T = require __DIR__ . '/api/templates-protein.php';

$raw = file_get_contents('php://input');
$p   = json_decode($raw, true);

if (!is_array($p)) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'errors' => ['Malformed request.']]);
  exit;
}

/* ---- honeypot: silent 200, no send ---------------------------------------- */
if (!empty($p['company'])) { echo json_encode(['status' => 'ok']); exit; }

/* ---- validation ----------------------------------------------------------- */
$errors = [];
if (empty($p['consent']) || $p['consent'] !== true) $errors[] = 'Consent is required.';
$email = isset($p['email']) ? trim((string)$p['email']) : '';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (($p['tool'] ?? '') !== 'protein') $errors[] = 'Missing or wrong tool identifier.';

$suppressed = !empty($p['suppressed']);

// A non-suppressed result MUST carry a numeric target. A suppressed one must NOT.
if (!$suppressed) {
  if (!isset($p['targetG']) || !is_numeric($p['targetG'])) $errors[] = 'Result payload is incomplete.';
  elseif ((float)$p['targetG'] < 20 || (float)$p['targetG'] > 400) $errors[] = 'Protein target out of range.';
}

if ($errors) {
  http_response_code(422);
  echo json_encode(['status' => 'error', 'errors' => $errors]);
  exit;
}

/* ---- template selection ---------------------------------------------------
 * SAFETY: the renal branch is chosen FIRST and unconditionally. A user with
 * kidney disease must never receive an email containing a g/day figure — the
 * on-screen suppression is worthless if the email undoes it.
 * -------------------------------------------------------------------------- */
$key = $suppressed ? 'protein_renal' : 'protein_standard';
$tpl = $T[$key];

/* ---- token data ------------------------------------------------------------ */
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
try {
  $ok = sendMail($email, $subject, $body);
  if (!$ok) throw new Exception('send failed');
  echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'errors' => ['Could not send the email.']]);
}
