<?php
/* ============================================================================
 * validate.php  —  input validation only (no side effects)
 * validate(array $p): array   → returns a list of error strings ([] = valid)
 * Ranges follow Data Model §4.2 (FR1-14). Payload key names are frozen (NFR1-04).
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

  // Email must look like an email (RFC 5322 syntax via PHP's validator) and be a sane length.
  $email = isset($p['email']) ? trim((string)$p['email']) : '';
  if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
  }

  // Tool identifier (guards against stray posts).
  if (($p['tool'] ?? '') !== 'diabetes-risk') {
    $errors[] = 'Missing or wrong tool identifier.';
  }

  if (isset($p['firstName']) && !is_string($p['firstName'])) $errors[] = 'First name must be text.';
  elseif (isset($p['firstName']) && mb_strlen($p['firstName']) > 60) $errors[] = 'First name is too long.';

  // Risk fields: either a numeric percent, or an override flag must be set.
  $hasPercent = isset($p['riskPercent']) && is_numeric($p['riskPercent']);
  $isOverride = !empty($p['emergency']) || !empty($p['symptomatic']);
  if (!$hasPercent && !$isOverride) {
    $errors[] = 'Result payload is incomplete.';
  }

  // Sanity-bound the numbers (Data Model §4.2); an emergency payload's risk fields are ignored downstream (DEC-09)
  $num = function (string $key, float $min, float $max, bool $nullable) use ($p, &$errors): void {
    if (!array_key_exists($key, $p) || $p[$key] === null) { if (!$nullable) $errors[] = "$key is required."; return; }
    if (!is_numeric($p[$key]) || (float)$p[$key] < $min || (float)$p[$key] > $max) $errors[] = "$key out of range.";
  };
  if ($hasPercent) $num('riskPercent', 0, 100, true);
  $num('bmi', 10, 80, false);
  $num('whtr', 0.2, 1.5, true);
  $num('idrsScore', 0, 100, true);
  if (isset($p['idrsTier']) && $p['idrsTier'] !== null && !in_array($p['idrsTier'], ['low', 'moderate', 'high'], true)) $errors[] = 'idrsTier out of range.';
  if (isset($p['riskBand']) && $p['riskBand'] !== null && !in_array($p['riskBand'], ['Low', 'Moderate', 'High', 'Very high'], true)) $errors[] = 'riskBand out of range.';
  $m = $p['measurements'] ?? null;
  if ($m !== null) {
    if (!is_array($m)) $errors[] = 'measurements must be an object.';
    else {
      $mm = function (string $key, float $min, float $max, bool $nullable) use ($m, &$errors): void {
        if (!array_key_exists($key, $m) || $m[$key] === null) { if (!$nullable) $errors[] = "measurements.$key is required."; return; }
        if (!is_numeric($m[$key]) || (float)$m[$key] < $min || (float)$m[$key] > $max) $errors[] = "measurements.$key out of range.";
      };
      $mm('heightCm', 140, 210, false);
      $mm('weightKg', 40, 180, false);
      $mm('waistCm', 50, 200, true);
    }
  }

  return $errors;
}

/* ---------------------------------------------------------------------------- withdrawal switch (FR1-15, DEC-10) --
 * lld_tool_active($slug, $cfg): false when tools/status.json says the tool is not active, or the file is unreadable (fail closed).
 * The server's status.json next to the api folder is the source of truth; `status_path` in the config may override it. */
function lld_tool_active(string $slug, array $cfg): bool {
  $path = $cfg['status_path'] ?? (__DIR__ . '/../status.json');
  $raw = is_readable($path) ? file_get_contents($path) : false;
  if ($raw === false) return false;
  $doc = json_decode($raw, true);
  return is_array($doc) && (($doc['tools'][$slug]['status'] ?? '') === 'active');
}
