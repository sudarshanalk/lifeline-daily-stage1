<?php
/* ============================================================================
 * ratelimit.php  —  simple per-IP throttle (flat-file), best-effort
 * rateLimitOk($ip,$cfg): bool   → false when the IP is over its allowance
 * ==========================================================================*/

function rateLimitOk(string $ip, array $cfg): bool {
  $rl = $cfg['ratelimit'] ?? ['enabled' => false];
  if (empty($rl['enabled'])) return true;

  $dir = $cfg['state_dir'] ?? (__DIR__ . '/state');
  if (!is_dir($dir)) @mkdir($dir, 0700, true);

  $max    = (int)($rl['max'] ?? 8);
  $window = (int)($rl['window_sec'] ?? 3600);
  $now    = time();
  $file   = $dir . '/rl_' . md5($ip) . '.json';

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
