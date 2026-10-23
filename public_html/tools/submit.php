<?php
/* ============================================================================
 * submit.php  —  public entrypoint (thin orchestrator, no business logic)
 * Reads the tool's JSON payload → validate → render → send → JSON status.
 * All real work lives in the single-responsibility modules under /api.
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

// ---- method + origin ------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
$allowed = $cfg['allowed_origins'] ?? [];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($allowed) && $origin !== '' && !in_array($origin, $allowed, true)) {
  respond(403, ['status' => 'error', 'message' => 'Origin not allowed']);
}

// ---- rate limit -----------------------------------------------------------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!rateLimitOk($ip, $cfg)) {
  respond(429, ['status' => 'error', 'message' => 'Too many requests, please try later']);
}

// ---- parse body -----------------------------------------------------------
$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
  respond(400, ['status' => 'error', 'message' => 'Invalid request body']);
}

// ---- validate -------------------------------------------------------------
$errors = validate($payload);
if ($errors === ['__honeypot__']) {
  // Bot: pretend success, send nothing.
  respond(200, ['status' => 'ok']);
}
if (!empty($errors)) {
  respond(422, ['status' => 'error', 'errors' => $errors]);
}

// ---- render + send --------------------------------------------------------
$msg = render($payload);
$res = sendMail((string)$payload['email'], $msg['subject'], $msg['body'], $cfg);

if (!$res['ok']) {
  respond(502, ['status' => 'error', 'message' => 'Could not send email']);
}
respond(200, ['status' => 'ok']);
