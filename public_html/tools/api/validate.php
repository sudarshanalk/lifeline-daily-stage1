<?php
/* ============================================================================
 * validate.php  —  input validation only (no side effects)
 * validate(array $p): array   → returns a list of error strings ([] = valid)
 * ==========================================================================*/

function validate(array $p): array {
  $errors = [];

  // Honeypot: real users never fill the hidden "company" field.
  if (!empty($p['company'])) {
    // Signal a silent drop — caller returns 200 without sending.
    return ['__honeypot__'];
  }

  // Consent must be explicitly true.
  if (empty($p['consent']) || $p['consent'] !== true) {
    $errors[] = 'Consent is required before we can email a result.';
  }

  // Email must look like an email.
  $email = isset($p['email']) ? trim((string)$p['email']) : '';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
  }

  // Tool identifier present (guards against stray posts).
  if (($p['tool'] ?? '') === '') {
    $errors[] = 'Missing tool identifier.';
  }

  // Risk fields: either a numeric percent, or an override flag must be set.
  $hasPercent = isset($p['riskPercent']) && is_numeric($p['riskPercent']);
  $isOverride = !empty($p['emergency']) || !empty($p['symptomatic']);
  if (!$hasPercent && !$isOverride) {
    $errors[] = 'Result payload is incomplete.';
  }

  // Sanity-bound the percent if present.
  if ($hasPercent) {
    $rp = (float)$p['riskPercent'];
    if ($rp < 0 || $rp > 100) $errors[] = 'Risk percentage out of range.';
  }

  return $errors;
}
