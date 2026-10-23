<?php
/* ============================================================================
 * submit.php  —  public entrypoint (thin orchestrator, no business logic)
 * Reads the tool's JSON payload → validate → render → send → JSON status.
 * All real work lives in the single-responsibility modules under /api.
 * FR1-14: method, content-type, body size, origin allow-list, rate limit (DEC-16), honeypot, 400 on validation,
 * 502 on send failure; one SCH-07 log line per request outcome (DEC-20), nothing else logged.
 * ==========================================================================*/

declare(strict_types=1);

$cfg = require __DIR__ . '/api/config.php';
require __DIR__ . '/api/validate.php';
require __DIR__ . '/api/render.php';
require __DIR__ . '/api/mailer.php';
require __DIR__ . '/api/ratelimit.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

$ip = lld_client_ip($cfg);

// ---- method + content-type + size -----------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
$ctype = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''))[0]));
if ($ctype !== 'application/json') {
  lld_log('diabetes', 'rejected_validation', $ip, $cfg);
  respond(400, ['status' => 'error', 'message' => 'Content-Type must be application/json']);
}
$maxBody = (int)($cfg['max_body_bytes'] ?? 8192);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
  lld_log('diabetes', 'rejected_validation', $ip, $cfg);
  respond(413, ['status' => 'error', 'message' => 'Request body too large']);
}

// ---- origin ---------------------------------------------------------------
$allowed = $cfg['allowed_origins'] ?? [];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($allowed) && $origin !== '' && !in_array($origin, $allowed, true)) {
  lld_log('diabetes', 'rejected_validation', $ip, $cfg);
  respond(403, ['status' => 'error', 'message' => 'Origin not allowed']);
}

// ---- rate limit (DEC-16: per IP per endpoint) ------------------------------
if (!rateLimitOk($ip, $cfg, 'diabetes')) {
  lld_log('diabetes', 'rejected_rate', $ip, $cfg);
  respond(429, ['status' => 'rate_limited']);   // TC-FR1-14-AS-03 fixes the body
}

// ---- parse body -----------------------------------------------------------
$raw = file_get_contents('php://input');
if (strlen($raw) > $maxBody) {
  lld_log('diabetes', 'rejected_validation', $ip, $cfg);
  respond(413, ['status' => 'error', 'message' => 'Request body too large']);
}
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
  lld_log('diabetes', 'rejected_validation', $ip, $cfg);
  respond(400, ['status' => 'error', 'message' => 'Invalid request body']);
}

// ---- validate -------------------------------------------------------------
$errors = validate($payload);
if ($errors === ['__honeypot__']) {
  // Bot: pretend success, send nothing, log the outcome only (DEC-16).
  lld_log('diabetes', 'honeypot', $ip, $cfg);
  respond(200, ['status' => 'ok']);
}
if (!empty($errors)) {
  lld_log('diabetes', 'rejected_validation', $ip, $cfg);
  respond(400, ['status' => 'error', 'errors' => $errors]);
}

// ---- render + send --------------------------------------------------------
$msg = render($payload);
$res = sendMail((string)$payload['email'], $msg['subject'], $msg['body'], $cfg);

if (empty($res['ok'])) {
  lld_log('diabetes', 'smtp_error', $ip, $cfg);
  respond(502, ['status' => 'error', 'message' => 'Could not send email']);
}
lld_log('diabetes', 'sent', $ip, $cfg);
respond(200, ['status' => 'ok']);
