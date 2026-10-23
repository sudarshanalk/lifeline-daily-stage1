<?php
/* ============================================================================
 * ratelimit.php  —  request-side helpers shared by both endpoints (FR1-14)
 *   lld_client_ip($cfg): string              real client IP behind the CDN edge (DEC-35: trusted proxy + forwarded header)
 *   rateLimitOk($ip,$cfg,$bucket): bool       per-IP, per-endpoint throttle, flat-file, best-effort (DEC-16: 5 / 10 min)
 *   lld_log($tool,$outcome,$ip,$cfg): void    one SCH-07 line — ts, tool, outcome, daily-salted ip_hash — nothing else (DEC-20)
 *   lld_tool_active($slug,$cfg): bool          tools/status.json gate (FR1-15, DEC-10): false when the tool is not active or the file is unreadable (fail closed)
 * No payload field ever reaches this file's output.
 * ==========================================================================*/

function lld_client_ip(array $cfg): string {
  $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
  $ci = $cfg['client_ip'] ?? [];
  $trusted = array_values(array_filter((array)($ci['trusted_proxies'] ?? [])));
  if (!$trusted || !in_array($remote, $trusted, true)) return $remote;   // not behind a trusted edge: REMOTE_ADDR is the client
  $header = 'HTTP_' . strtoupper(str_replace('-', '_', (string)($ci['header'] ?? 'X-Forwarded-For')));
  $raw = (string)($_SERVER[$header] ?? '');
  if ($raw === '') return $remote;
  // rightmost address that is not one of our own proxies is the client the edge saw
  $hops = array_reverse(array_map('trim', explode(',', $raw)));
  foreach ($hops as $hop) {
    if (in_array($hop, $trusted, true)) continue;
    if (filter_var($hop, FILTER_VALIDATE_IP)) return $hop;
    break;
  }
  return $remote;
}

function rateLimitOk(string $ip, array $cfg, string $bucket = 'shared'): bool {
  $rl = $cfg['ratelimit'] ?? ['enabled' => false];
  if (empty($rl['enabled'])) return true;

  $dir = $cfg['state_dir'] ?? (__DIR__ . '/state');
  if (!is_dir($dir)) @mkdir($dir, 0700, true);

  $max    = (int)($rl['max'] ?? 5);
  $window = (int)($rl['window_sec'] ?? 600);
  $now    = time();
  $file   = $dir . '/rl_' . preg_replace('/[^a-z0-9_-]/i', '', $bucket) . '_' . md5($ip) . '.json';

  $hits = [];
  if (is_file($file)) {
    $raw  = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : [];
    if (is_array($data)) $hits = $data;
  }
  // keep only hits inside the window
  $hits = array_values(array_filter($hits, fn($t) => ($now - (int)$t) < $window));

  if (count($hits) >= $max) return false;

  $hits[] = $now;
  @file_put_contents($file, json_encode($hits), LOCK_EX);
  return true;
}

/** Daily salt: a configured secret (LLD_LOG_SALT) or a per-install random secret kept in state/, hashed with the UTC date. */
function lld_daily_salt(array $cfg): string {
  $secret = (string)($cfg['log_salt'] ?? '');
  if ($secret === '') {
    $dir = $cfg['state_dir'] ?? (__DIR__ . '/state');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/log_salt';
    $secret = is_file($file) ? (string)@file_get_contents($file) : '';
    if ($secret === '') { $secret = bin2hex(random_bytes(16)); @file_put_contents($file, $secret, LOCK_EX); }
  }
  $day = (string)($cfg['log_day'] ?? gmdate('Y-m-d'));   // log_day is a test hook (NFR1-16-AS-03); unset in production
  return hash('sha256', $secret . '|' . $day);
}

function lld_log(string $tool, string $outcome, string $ip, array $cfg): void {
  $line = json_encode([
    'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
    'tool'    => $tool,
    'outcome' => $outcome,
    'ip_hash' => hash('sha256', $ip . lld_daily_salt($cfg)),
  ], JSON_UNESCAPED_SLASHES);
  error_log($line);
}

function lld_tool_active(string $slug, array $cfg): bool {
  $path = $cfg['status_path'] ?? (__DIR__ . '/../status.json');
  $raw = is_readable($path) ? file_get_contents($path) : false;
  if ($raw === false) return false;                       // no readable switch → fail closed (DEC-10)
  $doc = json_decode($raw, true);
  return is_array($doc) && (($doc['tools'][$slug]['status'] ?? '') === 'active');
}
