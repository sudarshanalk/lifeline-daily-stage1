// Stage 3 sign-in behaviour after the independent review of #183 (P3-20 to P3-22: FR3-04, NFR3-18), run in PHP CLI
// with stubbed WordPress: the code limits per address and per connection, replaying a used code, an unconfirmed
// member who proved the password keeping it, and the provider callback while the site is closed. The live journeys
// are tests/stage3/key-journeys/sign-in-code.spec.mjs and provider-sign-in.spec.mjs.
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const signin = resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/stage3/signin');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

// One email_codes table in memory: prepare() keeps the arguments, and the UPDATEs answer with rows changed,
// as $wpdb does, so the atomic try and use-once checks run as they do on MySQL.
const stubs = `
define('ABSPATH', '/'); define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600);
class WP_Error { public $code; public $message; function __construct($c, $m = '') { $this->code = $c; $this->message = $m; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_email($e) { return trim((string) $e); }
function wp_salt($s) { return 'ci-salt'; }
$GLOBALS['transients'] = [];
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
class LLD_Members_Stage3_Schema { static function table($t) { return 'wp_lld_' . $t; } }
class FakeDb {
  public $rows = []; public $stale = null; private $args = [];
  function prepare($q, ...$a) { $this->args = $a; return $q; }
  function get_var($q) { $e = $this->args[0]; $since = $this->args[1]; return count(array_filter($this->rows, fn($r) => $r['email'] === $e && $r['created_at'] > $since)); }
  function insert($t, $d) { $d['id'] = count($this->rows) + 1; $d['used_at'] = null; $this->rows[$d['id']] = $d; return 1; }
  function get_row($q) {
    if ($this->stale) return (object) $this->stale;
    [$e, $p] = $this->args; $found = null;
    foreach ($this->rows as $r) if ($r['email'] === $e && $r['purpose'] === $p && null === $r['used_at']) $found = $r;
    return $found ? (object) $found : null;
  }
  function query($q) {
    if (str_starts_with($q, 'DELETE')) return 0;
    $id = (int) end($this->args);
    if (str_contains($q, 'attempts = attempts + 1')) { $id = (int) $this->args[0]; $r = &$this->rows[$id]; if (null === $r['used_at'] && $r['attempts'] < $this->args[1]) { $r['attempts']++; return 1; } return 0; }
    if (str_contains($q, 'SET used_at')) { $r = &$this->rows[$id]; if (null === $r['used_at']) { $r['used_at'] = $this->args[0]; return 1; } return 0; }
    return 0;
  }
}
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(resolve(signin, 'class-email-codes.php'))};
`;
const run = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 }).trim());

test('TC-NFR3-18-AS-01', () => {
  // Codes come from random_int (the system's secure generator); none of the insecure generators appears in sign-in.
  for (const file of readdirSync(signin).filter((f) => f.endsWith('.php'))) {
    const src = readFileSync(resolve(signin, file), 'utf8');
    expect(src, file).not.toMatch(/\b(rand|mt_rand|lcg_value|uniqid|array_rand|shuffle|str_shuffle)\s*\(/);
  }
  const codes = run(`$out = []; for ($i = 0; $i < 4; $i++) { $_SERVER['REMOTE_ADDR'] = '192.0.2.' . $i; $out[] = LLD_Members_Email_Codes::issue("m$i@test.lifelinedaily.invalid", 'sign_in'); } return [$out, array_column($GLOBALS['wpdb']->rows, 'code_hash')];`);
  for (const c of codes[0]) expect(c).toMatch(/^\d{6}$/);
  // Only the hash is kept, never the code.
  codes[1].forEach((hash, i) => { expect(hash).toMatch(/^\$2y\$/); expect(hash).not.toContain(codes[0][i]); });
});

test('TC-NFR3-18-AS-02', () => {
  // A used code fails when replayed; and when two attempts read the same unused row at once, only one can use it.
  const r = run(`
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10'; $e = 'replay@test.lifelinedaily.invalid';
    $code = LLD_Members_Email_Codes::issue($e, 'sign_in');
    $first = LLD_Members_Email_Codes::verify($e, 'sign_in', $code);
    $again = LLD_Members_Email_Codes::verify($e, 'sign_in', $code);
    $code2 = LLD_Members_Email_Codes::issue($e, 'sign_in');
    $db = $GLOBALS['wpdb']; $db->stale = $db->rows[2];   // both attempts saw row 2 unused
    $a = LLD_Members_Email_Codes::verify($e, 'sign_in', $code2);
    $b = LLD_Members_Email_Codes::verify($e, 'sign_in', $code2);
    return [$first, $again->code, $a, is_wp_error($b) ? $b->code : 'accepted'];
  `);
  expect(r[0]).toBe(true);
  expect(r[1]).toBe('lld_code_wrong'); // the same answer as a wrong code: nothing says a code was ever there
  expect(r[2]).toBe(true);
  expect(['lld_code_used', 'lld_code_tries']).toContain(r[3]); // refused either way: the row is used, so no try can be counted
});

test('TC-NFR3-18-AS-03', () => {
  // Requests beyond the limits are refused with the approved message: five an hour for one address, thirty an hour
  // from one connection across any addresses (so the forms cannot flood other people's inboxes); tries beyond five.
  const r = run(`
    $_SERVER['REMOTE_ADDR'] = '192.0.2.20';
    $one = []; for ($i = 0; $i < 6; $i++) { $c = LLD_Members_Email_Codes::issue('same@test.lifelinedaily.invalid', 'sign_in'); $one[] = is_wp_error($c) ? $c->code : 'sent'; }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.21';
    $many = []; for ($i = 0; $i < 31; $i++) { $c = LLD_Members_Email_Codes::issue("other$i@test.lifelinedaily.invalid", 'sign_up'); $many[] = is_wp_error($c) ? $c->code : 'sent'; }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.22';
    $fresh = LLD_Members_Email_Codes::issue('fresh@test.lifelinedaily.invalid', 'sign_up');
    $limited = LLD_Members_Email_Codes::issue('same@test.lifelinedaily.invalid', 'sign_in');
    $e = 'tries@test.lifelinedaily.invalid'; $code = LLD_Members_Email_Codes::issue($e, 'sign_in');
    $wrong = $code === '000000' ? '111111' : '000000'; $tries = [];
    for ($i = 0; $i < 5; $i++) $tries[] = LLD_Members_Email_Codes::verify($e, 'sign_in', $wrong)->code;
    $right = LLD_Members_Email_Codes::verify($e, 'sign_in', $code);
    return [$one, $many, is_wp_error($fresh), $limited->message, $tries, $right->code, json_encode($GLOBALS['transients'])];
  `);
  expect(r[0]).toEqual(['sent', 'sent', 'sent', 'sent', 'sent', 'lld_code_limit']);
  expect(r[1].slice(0, 30).every((x) => x === 'sent')).toBe(true);
  expect(r[1][30]).toBe('lld_code_limit');
  expect(r[2]).toBe(false); // another connection is not held back
  expect(r[3]).toBe("You've asked for several codes in the last hour. Please wait a while and try again, or sign in with your password.");
  expect(r[4]).toEqual(['lld_code_wrong', 'lld_code_wrong', 'lld_code_wrong', 'lld_code_wrong', 'lld_code_tries']);
  expect(r[5]).toBe('lld_code_tries'); // after five wrong tries even the right code needs a new one
  expect(r[6]).not.toContain('192.0.2'); // the connection is kept only as a keyed hash
});

// The sign-in class, with just enough of WordPress and Stage 2 to run open_session() and callback().
const signInStubs = `
define('ABSPATH', '/'); define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['log'] = []; $GLOBALS['closed'] = false;
function get_option($k) { return 'lld_members_closed' === $k ? $GLOBALS['closed'] : false; }
function wp_set_password($p, $u) { $GLOBALS['log'][] = "password:$u"; }
function wp_generate_password() { return 'random'; }
function update_user_meta($u, $k, $v) { $GLOBALS['log'][] = "meta:$k"; }
function lld_members_now_mysql() { return '2026-11-03 21:00:00'; }
function home_url($p = '') { return 'https://stg.test' . $p; }
function nocache_headers() {}
function wp_safe_redirect($u) { $GLOBALS['log'][] = "redirect:$u"; }
function get_transient($k) { $GLOBALS['log'][] = 'transient-read'; return false; }
function delete_transient($k) {}

function is_ssl() { return true; }
class WP_Session_Tokens { static function get_instance($u) { return new self(); } function destroy_all() { $GLOBALS['log'][] = 'sessions-ended'; } }
class LLD_Members_Schema { static function table($t) { return 'wp_lld_' . $t; } }
class LLD_Members_Auth { static function start_session($u, $m, $r) { $GLOBALS['log'][] = "session:$u"; } }
class LLD_Members_Identity_Provider { const PROVIDERS = ['google', 'apple']; static function exchange() { $GLOBALS['log'][] = 'exchange'; } }
class FakeDb { function prepare($q, ...$a) { return $q; } function query($q) { return 1; } }
class FakeRequest implements ArrayAccess { public $p; function __construct($p) { $this->p = $p; } function get_param($k) { return $this->p[$k] ?? null; }
  function offsetExists($k): bool { return isset($this->p[$k]); } function offsetGet($k): mixed { return $this->p[$k] ?? null; } function offsetSet($k, $v): void {} function offsetUnset($k): void {} }
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(resolve(signin, 'class-sign-in.php'))};
register_shutdown_function(function () { echo json_encode($GLOBALS['log']); });
`;
const runSignIn = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${signInStubs} ${code}`], { encoding: 'utf8', timeout: 120000 }).trim());
const openSession = (passwordProved) => runSignIn(`
  $m = new ReflectionMethod('LLD_Members_Sign_In', 'open_session'); $m->setAccessible(true);
  $m->invoke(null, (object) ['id' => 5, 'user_id' => 42, 'email_confirmed_at' => null], false, ${passwordProved});`);

test('TC-FR3-04-AS-04 (unconfirmed member): a password proved before the code is kept', () => {
  // A Stage 2 member who never clicked the confirmation link signs in with the right password, then the emailed
  // code: the email is confirmed and the password still works next time. A code alone (whoever registered the
  // address chose that password) still replaces it, as before.
  expect(openSession('true')).toEqual(['session:42']);
  expect(openSession('false')).toEqual(['password:42', 'meta:lld_members_passwordless', 'sessions-ended', 'session:42']);
  expect(readFileSync(resolve(signin, 'class-sign-in.php'), 'utf8')).toMatch(/request_sign_in_code\( \(string\) \$member->email, \$remember, array\( 'password_proved' => true \) \)/);
});

test('TC-NFR3-06-AS-01 (site closed): a provider callback does not sign anyone in while the site is closed', () => {
  // Stage 2's closed state blocks every sign-in; a Google or Apple flow started before the site closed is refused
  // at the callback (its one-use state is still spent), before its code is exchanged or a session opened.
  const log = runSignIn(`$GLOBALS['closed'] = true; $_COOKIE = []; LLD_Members_Sign_In::callback(new FakeRequest(['provider' => 'google', 'state' => 'abc', 'code' => 'c']));`);
  expect(log).toEqual(['transient-read', 'redirect:https://stg.test/members/sign-in/?provider_error=unavailable']);
  const open = runSignIn(`$_COOKIE = []; LLD_Members_Sign_In::callback(new FakeRequest(['provider' => 'google', 'state' => 'abc', 'code' => 'c']));`);
  expect(open).toEqual(['transient-read', 'redirect:https://stg.test/members/sign-in/?provider_error=mismatch']); // open: the usual checks
});
