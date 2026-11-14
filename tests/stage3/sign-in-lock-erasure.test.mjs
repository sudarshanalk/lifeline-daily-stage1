// P3-36 F1 (TC-FR3-61-AS-03): after an account is deleted, no table holds the member's email, including the sign-in
// lock records. The journey test found one lock row keeping a deleted member's email: the "can't sign in again" attempt
// after deletion counted a failure for that email and stored it in plain text. Failed sign-ins for an email with no
// account are now counted under a keyed hash, so they still lock, but the address is never stored; erasure removes both
// forms. Run in PHP CLI against the real LLD_Members_Auth with an in-memory member and sign_in_lock table.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const inc = (name) => resolve(plugin, `includes/${name}`);
const src = (name) => readFileSync(inc(name), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const EMAIL = 'f1-deleted@test.lifelinedaily.invalid';

const stubs = `
define('ABSPATH', '/');
class WP_Error { public $code; function __construct($c = '', $m = '') { $this->code = $c; } }
class WP_User {}
function add_action() {} function add_filter() {} function sanitize_email($e) { return trim((string) $e); }
function get_user_by($f, $v) { return $GLOBALS['users'][$v] ?? false; }
function wp_check_password($p, $h, $id = 0) { return 'right' === $p; }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Clock { static function now($id = 0) { return new DateTimeImmutable('2026-11-14 10:00:00'); } }
class LLD_Members_Erasure { static function email_hash($e) { return hash_hmac('sha256', strtolower(trim((string) $e)), 'test-key'); } }
class FakeDb {
  public $members = []; public $locks = [];
  function prepare($q, ...$a) { return [$q, $a]; }
  function get_var($p) { [$q, $a] = $p; if (strpos($q, 'wp_lld_member ') !== false || strpos($q, 'wp_lld_member WHERE') !== false) { return $this->members[$a[0]] ?? null; } return null; }
  function get_row($p) { [$q, $a] = $p; return isset($this->locks[$a[0]]) ? (object) $this->locks[$a[0]] : null; }
  function replace($t, $row) { $this->locks[$row['email']] = $row; return 1; }
  function delete($t, $where) { unset($this->locks[$where['email']]); return 1; }
}
$GLOBALS['wpdb'] = new FakeDb(); $GLOBALS['users'] = [];
require ${toPhpPath(inc('class-auth.php'))};
`;
const run = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 }).trim());

test('TC-FR3-61-AS-03', () => {
  const r = run(`
    $email = '${EMAIL}';
    $out = [];
    // A member with an account: a wrong password is counted under the email, as in Stage 2.
    $GLOBALS['wpdb']->members[$email] = 7; $GLOBALS['users'][$email] = new WP_User();
    LLD_Members_Auth::check_password($email, 'wrong');
    $out['member_keys'] = array_keys($GLOBALS['wpdb']->locks);
    // The account is deleted: erasure removes every lock row the email can be under (LLD_Members_Erasure, F1).
    foreach (LLD_Members_Auth::lock_keys($email) as $k) { $GLOBALS['wpdb']->delete('wp_lld_sign_in_lock', ['email' => $k]); }
    unset($GLOBALS['wpdb']->members[$email], $GLOBALS['users'][$email]);
    $out['after_delete'] = $GLOBALS['wpdb']->locks;
    // "Can't sign in again": five attempts with the deleted email. They are still counted and still lock sign-in...
    for ($i = 0; $i < 5; $i++) { $last = LLD_Members_Auth::check_password($email, 'right'); }
    $out['locked'] = LLD_Members_Auth::check_password($email, 'right')->code;
    // ...but no lock row holds the address.
    $out['dump'] = json_encode($GLOBALS['wpdb']->locks);
    $out['keys'] = array_keys($GLOBALS['wpdb']->locks);
    $out['hash_key'] = 'h:' . LLD_Members_Erasure::email_hash($email);
    // Erasing (or a test reset) again clears the hashed row too.
    foreach (LLD_Members_Auth::lock_keys($email) as $k) { $GLOBALS['wpdb']->delete('wp_lld_sign_in_lock', ['email' => $k]); }
    $out['cleared'] = $GLOBALS['wpdb']->locks;
    return $out;
  `);
  expect(r.member_keys).toEqual([EMAIL]);
  expect(r.after_delete).toEqual([]);
  expect(r.locked).toBe('lld_locked');
  expect(r.dump).not.toContain(EMAIL);
  expect(r.dump).not.toContain('@');
  expect(r.keys).toEqual([r.hash_key]);
  expect(r.cleared).toEqual([]);

  // Erasure deletes the lock row under both keys before the member row goes, and the erasure checker
  // (LLD_Members_Privacy::member_links) still lists sign_in_lock by email, so a row with the address is reported.
  const erasure = src('class-erasure.php');
  const del = erasure.slice(erasure.indexOf('public static function delete_account('), erasure.indexOf('public static function withdraw_health('));
  expect(del).toContain("foreach ( LLD_Members_Auth::lock_keys( (string) $member->email ) as $lock_key ) {");
  expect(del.indexOf('LLD_Members_Auth::lock_keys')).toBeLessThan(del.indexOf("$wpdb->delete( LLD_Members_Schema::table( 'member' )"));
  expect(src('class-privacy.php')).toContain("'sign_in_lock'     => array( 'email' ),");
  // The test reset route clears both forms, so specs that lock an unknown email start clean.
  expect(src('class-rest.php')).toContain('foreach ( LLD_Members_Auth::lock_keys( $email ) as $lock_key ) {');
  // The hashed key fits the column (VARCHAR(190)).
  expect(r.hash_key.length).toBeLessThanOrEqual(190);
});
