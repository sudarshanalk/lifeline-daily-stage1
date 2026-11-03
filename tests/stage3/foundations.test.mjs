// Stage 3 foundations (P3-12 to P3-15): the switches, the sealed data layer, the interface and the erasure hooks, read
// from the plugin source and run in PHP CLI without WordPress where no database is needed.
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const src = (name) => readFileSync(resolve(plugin, name), 'utf8');
const stage3 = (name) => src(`includes/stage3/${name}`);
const php = (code) => execFileSync('php', ['-n', '-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8' }).trim();
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const stubs = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); define('HOUR_IN_SECONDS', 3600);
class WP_Error { public $code; public $message; function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; } function get_error_message() { return $this->message; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
$GLOBALS['opts'] = []; $GLOBALS['admin'] = false;
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function current_user_can() { return $GLOBALS['admin']; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
function lld_members_now_mysql() { return '2026-11-02 12:00:00'; }
function lld_members_private_dir() { return sys_get_temp_dir() . '/lld-stage3-test'; }
function lld_members_is_production() { return false; }
require ${toPhpPath(resolve(plugin, 'includes/class-crypto.php'))};
require ${toPhpPath(resolve(plugin, 'includes/stage3/class-features.php'))};
require ${toPhpPath(resolve(plugin, 'includes/stage3/class-data.php'))};
`;

test('TC-NFR3-06-AS-02', () => {
  // A feature switched on for test accounts only is not visible to an ordinary member account.
  const out = php(`${stubs}
    class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
    class LLD_Members_Access { static function own_member_id() { return 0; } }
    class FakeDb { public $emails = [7 => 'tester@example.test', 8 => 'ordinary@example.test']; function prepare($q, $id) { return $id; } function get_var($id) { return $this->emails[$id] ?? null; } }
    $GLOBALS['wpdb'] = new FakeDb();
    update_option('lld_test_account_emails', ['Tester@example.test']);
    $r = [];
    $r[] = LLD_Members_Features::enabled('food_tracker', 7) ? 'on' : 'off';            // default: off for everyone
    LLD_Members_Features::set('food_tracker', 'test_accounts');
    $r[] = LLD_Members_Features::enabled('food_tracker', 7) ? 'on' : 'off';            // test account sees it
    $r[] = LLD_Members_Features::enabled('food_tracker', 8) ? 'on' : 'off';            // ordinary member does not
    $r[] = LLD_Members_Features::enabled('food_tracker', 0) ? 'on' : 'off';            // visitor does not
    $e = LLD_Members_Features::set('food_tracker', 'everyone');                         // everyone needs the owner's approval
    $r[] = is_wp_error($e) ? 'refused' : 'allowed';
    LLD_Members_Features::set('food_tracker', 'everyone', 'Owner, 2 Nov 2026');
    $r[] = LLD_Members_Features::enabled('food_tracker', 8) ? 'on' : 'off';
    $r[] = LLD_Members_Features::enabled('no_such_feature', 8) ? 'on' : 'off';         // unknown names are always off
    $r[] = LLD_Members_Features::all()['food_tracker']['approved_by'];
    echo implode(',', $r);
  `);
  expect(out).toBe('off,on,off,off,refused,on,off,Owner, 2 Nov 2026');
});

test('TC-NFR3-06-AS-01 (source): every Stage 3 class is loaded through the loader and acts only behind a switch', () => {
  // With all switches off the plugin registers only its own routes and hooks; no screen, rewrite or filter changes Stage 2.
  const loader = stage3('loader.php');
  expect(loader).toMatch(/require_once __DIR__ \. '\/class-features\.php'/);
  expect(src('lld-members.php')).toMatch(/includes\/stage3\/loader\.php/);
  expect(src('includes/class-plugin.php')).toMatch(/lld_members_stage3_boot\(\)/);
  expect(loader).not.toMatch(/add_rewrite_rule|template_redirect|add_shortcode/);
  const api = stage3('class-api.php');
  const gate = api.slice(api.indexOf('private static function gate'), api.indexOf('public static function list_rows'));
  expect(gate).toMatch(/LLD_Members_Features::enabled\( \$def\['feature'\], \$member_id \)/);
  expect(gate.indexOf('Features::enabled')).toBeLessThan(gate.indexOf('$member_id < 1'));
  expect(gate).toMatch(/refuse\( 404/);
});

test('TC-NFR3-10-AS-01', () => {
  // Health values are sealed with the site key and bound to the member: the stored token holds no plain value,
  // and a token moved to another member's row does not open.
  const out = php(`${stubs}
    $a = LLD_Members_Stage3_Data::seal(7, '142.5');
    $r = [];
    $r[] = strpos($a, '142') === false ? 'hidden' : 'plain';
    $r[] = LLD_Members_Stage3_Data::open(7, $a);
    $r[] = LLD_Members_Stage3_Data::open(8, $a) === null ? 'other-member-refused' : 'leak';
    $r[] = LLD_Members_Stage3_Data::seal(7, '') === null ? 'empty-null' : 'x';
    $r[] = LLD_Members_Stage3_Data::open(7, 'not-a-token') === null ? 'bad-null' : 'x';
    echo implode(',', $r);
  `);
  expect(out).toBe('hidden,142.5,other-member-refused,empty-null,bad-null');
  // The readings, goals and insights tables keep only ciphertext columns for health values.
  const schema = stage3('class-schema-stage3.php');
  const table = (name) => schema.slice(schema.indexOf(`self::create( '${name}'`), schema.indexOf("' );", schema.indexOf(`self::create( '${name}'`)));
  expect(table('readings')).toMatch(/value_1_ct TEXT/);
  expect(table('readings')).not.toMatch(/\bvalue_1 (DECIMAL|INT|SMALLINT|FLOAT)/);
  expect(table('goals')).toMatch(/target_value_ct TEXT/);
  expect(table('insights')).toMatch(/values_ct TEXT/);
  // Nothing in the Stage 3 layer writes a value to a log or an error.
  for (const file of ['class-data.php', 'class-api.php', 'class-erasure-stage3.php']) {
    expect(stage3(file), file).not.toMatch(/error_log\(|trigger_error\(|wp_mail\(|var_dump\(|print_r\(/);
  }
});

test('TC-NFR3-09-AS-02 (source): the interface has no member number in any address and scopes every query to the caller', () => {
  const api = stage3('class-api.php');
  const routes = [...api.matchAll(/register_rest_route\(\s*'lld-members\/v1',\s*'([^']+)'/g)].map((m) => m[1]);
  expect(routes.length).toBeGreaterThan(0);
  for (const r of routes) expect(r, r).toMatch(/^\/me\//);
  for (const r of routes) expect(r, r).not.toMatch(/member|<id>\d+\)\/|members\//);
  expect(api).toMatch(/LLD_Members_Access::current_member_id\(\)/);
  const data = stage3('class-data.php');
  for (const fn of ['update', 'row', 'purge']) {
    const body = data.slice(data.indexOf(`public static function ${fn}(`));
    expect(body.slice(0, body.indexOf('\n\t}')), fn).toMatch(/member_id/);
  }
  expect(data).toMatch(/WHERE id = %d AND member_id = %d/);
  expect(data).toMatch(/'id'\s+=> \$id,\s+'member_id' => \$member_id,/);
});

test('TC-NFR3-09-AS-01 (source): every Stage 3 data type is served by the interface', () => {
  const api = stage3('class-api.php');
  const types = api.slice(api.indexOf('public static function types'), api.indexOf('public static function reading_ranges'));
  for (const table of ['goals', 'plans', 'today_steps', 'insights', 'custom_foods', 'food_log', 'food_favourites', 'nutrition_targets', 'recipes', 'readings', 'calculator_result', 'reminders', 'shares']) {
    expect(types, table).toMatch(new RegExp(`'table'\\s+=> '${table}'`));
  }
  // Every table the schema creates is either served, or named here as not a member record (identities and email
  // codes are sign-in records, pauses are payment records: P3-20 to P3-22 and P3-63 add their own routes).
  const schema = stage3('class-schema-stage3.php');
  const created = [...schema.matchAll(/self::create\( '(\w+)'/g)].map((m) => m[1]);
  const notServed = ['identities', 'email_codes', 'goal_baselines', 'plan_adjustments', 'foods', 'pauses'];
  for (const t of created) if (!notServed.includes(t)) expect(types, t).toMatch(new RegExp(`'table'\\s+=> '${t}'`));
  // Every reading type in the data model has its ranges.
  for (const t of ['blood_pressure', 'weight', 'waist', 'activity', 'sleep', 'water', 'mood', 'glucose']) expect(api).toMatch(new RegExp(`'${t}'\\s+=> array\\( 'value_1'`));
});

test('TC-NFR3-04-AS-02 (source): the Stage 3 schema only creates and adds, and Stage 2 tables keep every column', () => {
  const schema = stage3('class-schema-stage3.php');
  expect(schema).toMatch(/CREATE TABLE IF NOT EXISTS/);
  expect(schema).not.toMatch(/DROP\s+(TABLE|COLUMN)/i);
  expect(schema).not.toMatch(/\b(MODIFY|CHANGE|RENAME)\s+/i);
  expect(schema).toMatch(/ALTER TABLE \{\$table\} ADD COLUMN/);
  // Table names follow the data model v0.2 (wp_lld_<suffix>), all unique.
  const suffixes = [...schema.slice(schema.indexOf('function suffixes')).matchAll(/^\s*'(\w+)',$/gm)].map((m) => m[1]);
  const created = [...schema.matchAll(/self::create\( '(\w+)'/g)].map((m) => m[1]);
  expect(created.sort()).toEqual([...suffixes].sort());
  expect(new Set(suffixes).size).toBe(suffixes.length);
  // The Stage 2 schema still runs first and is unchanged apart from the Stage 3 call.
  const s2 = src('includes/class-schema.php');
  expect(s2).toMatch(/LLD_Members_Stage3_Schema::update\(\)/);
  expect(s2.indexOf('LLD_Members_Billing::db_update()')).toBeLessThan(s2.indexOf('LLD_Members_Stage3_Schema::update()'));
  // None of the Stage 3 suffixes collides with a Stage 2 table.
  const s2suffixes = [...s2.slice(s2.indexOf('function all_suffixes')).matchAll(/^\s*'(\w+)',$/gm)].map((m) => m[1]);
  for (const s of suffixes) expect(s2suffixes, s).not.toContain(s);
});

test('P3-15: the erasure checker covers the payment data kept in wp_options and the Stage 3 tables', () => {
  const privacy = src('includes/class-privacy.php');
  expect(privacy).toMatch(/LLD_Members_Billing::member_options\( \$member_id \)/);
  expect(privacy).toMatch(/wp_options still holds/);
  expect(privacy).toMatch(/apply_filters\( 'lld_members_member_links', \$links \)/);
  expect(privacy).toMatch(/apply_filters\( 'lld_members_known_tables', \$known \)/);
  expect(privacy).toMatch(/apply_filters\( 'lld_members_health_tables'/);
  expect(src('includes/class-erasure.php')).toMatch(/do_action\( 'lld_members_withdraw_health', \$member_id \)/);
  const erasure = stage3('class-erasure-stage3.php');
  expect(erasure).toMatch(/add_action\( 'lld_members_erase_account'/);
  expect(erasure).toMatch(/add_action\( 'lld_members_withdraw_health'/);
  expect(erasure).toMatch(/add_filter\( 'lld_members_member_links'/);
  expect(erasure).toMatch(/add_filter\( 'lld_members_known_tables'/);
  // The member-options counter the checker reads still exists in billing with every store it erases.
  const billing = src('includes/class-billing.php');
  for (const key of ['card_fingerprints', 'referral_code', 'quotes', 'mismatches', 'retries']) expect(billing).toMatch(new RegExp(`'${key}'\\s+=>`));
});

test('TC-NFR3-03-AS-02 (source): the key journeys run after each staging deploy and in CI', () => {
  expect(readFileSync(resolve(here, '../../deploy/deploy.sh'), 'utf8')).toMatch(/bash deploy\/key-journeys\.sh "\$EXPECTED_URL"/);
  const journeys = readFileSync(resolve(here, '../../deploy/key-journeys.sh'), 'utf8');
  for (const path of ['/members/join/', '/members/sign-in/', '/members/goals/', '/programmes/', '/members/library/', '/members/diary/', '/health-tools/healthy-weight-check/', '/members/settings/', '/members/data/']) {
    expect(journeys, path).toContain(path);
  }
  const ci = readFileSync(resolve(here, '../../.github/workflows/ci-guards.yml'), 'utf8');
  expect(ci).toMatch(/stage3-guards:/);
  expect(ci).toMatch(/tests\/stage3 --passWithNoTests/);
  expect(existsSync(resolve(here, 'key-journeys'))).toBe(true);
});
