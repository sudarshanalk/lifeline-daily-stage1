// P3-87 admin panel: rules checked in PHP CLI against a fake WordPress (no database), and source checks.
// The live journeys are in tests/stage3/e2e/admin/admin-panel.spec.mjs.
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const adminDir = resolve(plugin, 'includes/stage3/admin');
const path = (p) => JSON.stringify(resolve(plugin, p).split('\\').join('/'));
const php = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim());

const stubs = (cli) => `
define('ABSPATH', '/'); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
${cli ? "define('WP_CLI', true);" : ''}
class WP_Error { public $c; public $m; function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_text_field($s) { return trim((string) $s); } function sanitize_textarea_field($s) { return trim((string) $s); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_salt($s = '') { return 'salt'; }
function wp_get_current_user() { return (object) ['ID' => 0, 'user_login' => '']; } function get_current_user_id() { return 0; }
$GLOBALS['opts'] = [];
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function current_user_can($c) { return false; }
class FakeDb { public $rows = []; function insert($t, $r) { $this->rows[] = $r; return 1; } function prepare($q, ...$a) { return $q; } }
$GLOBALS['wpdb'] = new FakeDb();
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
require ${path('includes/stage3/class-features.php')};
require ${path('includes/stage3/admin/class-admin-log.php')};
require ${path('includes/stage3/admin/class-admin-security.php')};
`;

test('TC-FR3-74-AS-03 A change made with the command line appears in the feature\'s history and the activity log', () => {
  const out = php(`${stubs(true)}
    $r = LLD_Members_Features::set('barcode', 'off', '', 'Scanner bug');
    echo json_encode(['ok' => $r === true, 'history' => LLD_Members_Features::history('barcode'), 'log' => $GLOBALS['wpdb']->rows]);`);
  expect(out.ok).toBe(true);
  expect(out.history[0]).toMatchObject({ who: 'command line', from: 'off', to: 'off', note: 'Scanner bug' });
  expect(out.log[0]).toMatchObject({ action: 'feature_off', admin_user_id: 'cli', target: 'barcode', after_state: 'off', reason: 'Scanner bug' });
});

test('TC-FR3-74-AS-01 An emergency off holds while every feature is on (lld_features_all_on), and "everyone" clears it', () => {
  const out = php(`${stubs(true)}
    update_option('lld_features_all_on', 1);
    $a = LLD_Members_Features::state('barcode');
    LLD_Members_Features::set('barcode', 'off', '', 'bug');
    $b = LLD_Members_Features::state('barcode'); $b2 = LLD_Members_Features::enabled('barcode', 5);
    LLD_Members_Features::set('barcode', 'test_accounts', '', 'fixing');
    $c = LLD_Members_Features::state('barcode');
    LLD_Members_Features::set('barcode', 'everyone', 'Owner, 5 Nov 2026', 'fixed');
    $d = LLD_Members_Features::state('barcode');
    // Rows stored before this package (no override flag) are still ignored while every feature is on.
    $GLOBALS['opts']['lld_feature_switches']['food_tracker'] = ['state' => 'off'];
    echo json_encode([$a, $b, $b2, $c, $d, LLD_Members_Features::state('food_tracker')]);`);
  expect(out).toEqual(['everyone', 'off', false, 'test_accounts', 'everyone', 'everyone']);
});

test('TC-FR3-86-AS-02 A risky action is not logged (so it is refused) without a reason, outside the command line', () => {
  const out = php(`${stubs(false)}
    $r = [];
    foreach (LLD_Members_Admin_Log::NEED_REASON as $a) { $r[$a] = LLD_Members_Admin_Log::add($a, ['member' => 3]); }
    $r['with_reason'] = LLD_Members_Admin_Log::add('member_block', ['member' => 3, 'reason' => 'abuse']);
    $r['note'] = LLD_Members_Admin_Log::add('member_note', ['member' => 3]);
    echo json_encode($r);`);
  for (const action of ['member_delete', 'premium_grant', 'premium_grant_remove', 'membership_end', 'member_block', 'member_unblock', 'test_account_mark', 'test_account_unmark', 'feature_off', 'feature_on', 'helper_add', 'helper_remove']) {
    expect(out[action], action).toBe(false);
  }
  expect(out.with_reason).toBe(true);
  expect(out.note).toBe(true);
});

test('TC-NFR3-20-AS-03 A session ends after 30 minutes idle and after 12 hours, and a helper session without a code ends at once', () => {
  const out = php(`${stubs(false)}
    $s = ['started' => 0, 'last' => 0, 'code' => true];
    echo json_encode([
      LLD_Members_Admin_Security::end_reason($s, 29 * 60, true),
      LLD_Members_Admin_Security::end_reason($s, 30 * 60, true),
      LLD_Members_Admin_Security::end_reason(['started' => 0, 'last' => 11 * 3600 + 59 * 60, 'code' => true], 11 * 3600 + 59 * 60 + 30, false),
      LLD_Members_Admin_Security::end_reason(['started' => 0, 'last' => 12 * 3600 - 60, 'code' => true], 12 * 3600, false),
      LLD_Members_Admin_Security::end_reason(['started' => 0, 'last' => 0, 'code' => false], 60, true),
      LLD_Members_Admin_Security::end_reason(null, 60, true),
      LLD_Members_Admin_Security::end_reason(null, 60, false),
    ]);`);
  expect(out).toEqual(['', 'idle', '', 'max', 'no_code', 'no_code', '']);
});

test('TC-FR3-86-AS-04 The CSV cells cannot run as spreadsheet formulas', () => {
  const out = php(`${stubs(false)}
    function get_userdata($id) { return (object) ['display_name' => 'Owner']; }
    $row = (object) ['id' => 1, 'created_at' => '2026-11-05 10:00:00', 'admin_user_id' => '1', 'action' => 'member_block', 'member_number' => 7, 'target' => '=HYPERLINK("x")', 'before_state' => '', 'after_state' => '', 'reason' => '+1'];
    echo json_encode(LLD_Members_Admin_Log::csv_row($row));`);
  expect(out[5]).toBe(`'=HYPERLINK("x")`);
  expect(out[8]).toBe("'+1");
  expect(out[2]).toBe('Owner');
  expect(out[3]).toBe('Blocked a member');
});

test('TC-NFR3-19-AS-02 No admin request reads a health, food, goal or calculator table', () => {
  const health = ['health_profile', 'something_else', 'goal_choice', 'calculator_result', 'enrolment', 'enrolment_period', 'check_in', 'reflection', 'weekly_plan', 'weekly_review', 'diary_meal', 'diary_day', 'meal_plan', 'result', 'workout', 'survey', 'goals', 'goal_baselines', 'plans', 'plan_adjustments', 'today_steps', 'insights', 'food_log', 'custom_foods', 'food_favourites', 'nutrition_targets', 'recipes', 'food_preferences', 'readings', 'shares', 'reminders'];
  for (const file of readdirSync(adminDir).filter((f) => f.endsWith('.php') && f !== 'class-admin-test-routes.php')) {
    const source = readFileSync(resolve(adminDir, file), 'utf8');
    for (const table of health) {
      expect(source, `${file} reads ${table}`).not.toMatch(new RegExp(`table\\(\\s*'${table}'\\s*\\)`));
    }
    expect(source, `${file} opens sealed values`).not.toMatch(/LLD_Members_Stage3_Data::(open|unseal|get)|LLD_Members_Crypto::decrypt/);
  }
  // The tables the Members page declares it reads are account tables only.
  const members = readFileSync(resolve(adminDir, 'class-admin-members.php'), 'utf8');
  const reads = [...members.matchAll(/table\(\s*'(\w+)'\s*\)/g)].map((m) => m[1]);
  for (const t of reads) expect(['member', 'subscription', 'identities', 'pauses', 'member_notes', 'premium_grants', 'admin_log', 'session', 'app_key', 'security_code', 'email_pref'], t).toContain(t);
});

test('TC-FR3-76-AS-03 No admin code offers to sign in as a member', () => {
  for (const file of readdirSync(adminDir).filter((f) => f.endsWith('.php'))) {
    // Code and page text only: comments may say that there is no such thing.
    const source = readFileSync(resolve(adminDir, file), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    expect(source, file).not.toMatch(/wp_set_auth_cookie|switch_to_user|Sign in as|View as member|Log in as/i);
  }
});

test('TC-NFR3-23-AS-01 Notes and grants go with the account; admin-panel log entries stay with the number only', () => {
  const schema = readFileSync(resolve(plugin, 'includes/stage3/class-schema-stage3.php'), 'utf8');
  expect(schema).toMatch(/'member_notes',\s*\n\s*'premium_grants',/);
  const erasure = readFileSync(resolve(plugin, 'includes/class-erasure.php'), 'utf8');
  expect(erasure).toContain("AND admin_user_id IS NULL");
  expect(erasure).toContain('SET member_name = NULL, member_email = NULL WHERE member_number = %d AND admin_user_id IS NOT NULL');
  const privacy = readFileSync(resolve(plugin, 'includes/class-privacy.php'), 'utf8');
  expect(privacy).toContain('An admin activity entry still names the member.');
});
