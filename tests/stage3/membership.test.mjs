// P3-60 to P3-63: launch prices, Free and Premium access, the membership card and pause. Run in PHP CLI against a
// fake WordPress, so no database or Stripe call is needed: the tests read the rules and the HTML the classes return.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const src = (name) => readFileSync(resolve(plugin, name), 'utf8');
const php = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim());
const path = (p) => JSON.stringify(resolve(plugin, p).split('\\').join('/'));
const FAKE_KEY = ['sk', 'test', 'FAKEKEY123'].join('_');
const WEBHOOK = ['whsec', 'FAKEWEBHOOK123'].join('_');

// Fake WordPress. $GLOBALS['premium'] is the Stage 2 answer; $GLOBALS['today'] the member's local date.
const stubs = `
define('ABSPATH', '/'); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
class WP_Error { public $code; public $message; public $data; function __construct($c = '', $m = '', $d = '') { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
class WP_REST_Response { public $data; public $status; function __construct($d, $s) { $this->data = $d; $this->status = $s; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } function esc_attr($s) { return esc_html($s); } function esc_url($s) { return (string) $s; }
function home_url($p = '') { return 'https://stg.example.test' . $p; }
function wp_nonce_field() { return '<input type="hidden" name="lld_sub_nonce">'; }
function lld_button($a) { return '<a class="lld-button" href="' . ($a['href'] ?? '') . '">' . $a['label'] . '</a>'; }
$GLOBALS['filters'] = [];
function add_filter($h, $cb, $p = 10, $n = 1) { $GLOBALS['filters'][$h][] = $cb; } function add_action($h, $cb, $p = 10, $n = 1) { add_filter($h, $cb); }
function apply_filters($h, $v, ...$a) { foreach ($GLOBALS['filters'][$h] ?? [] as $cb) { $v = $cb($v, ...$a); } return $v; }
function do_action($h, ...$a) { $GLOBALS['actions'][] = $h; }
$GLOBALS['opts'] = []; $GLOBALS['premium'] = true; $GLOBALS['today'] = '2027-03-01'; $GLOBALS['tiers'] = true;
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
class LLD_Members_Features { static $off = []; static function enabled($f, $m = 0) { return !in_array($f, self::$off, true); } }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Programme { static function local_today($m) { return $GLOBALS['today']; } }
class LLD_Members_Stripe { static function try_again_message() { return 'Please try again.'; } }
class LLD_Members_Stripe_API { static function on($m = 0) { return $GLOBALS['stripe_on'] ?? false; } }
class LLD_Members_Billing {
  static $sub = null; static $prices = ['monthly' => 999, 'yearly' => 6999];
  static function paid_state($m) { return $GLOBALS['premium']; }
  static function has_premium($m) { return (bool) apply_filters('lld_members_paid_open', self::paid_state($m), $m); }
  static function shown_prices() { return self::$prices; }
  static function subscription_for($m) { return self::$sub; }
}
class FakeDb { public $queries = []; public $pause = null; public $counts = ['workout' => 7, 'days' => 12];
  function prepare($q, ...$a) { return $q; }
  function get_row($q) { $this->queries[] = $q; return $this->pause ? (object) $this->pause : null; }
  function get_var($q) { $this->queries[] = $q; return strpos($q, "context_type = 'workout'") !== false ? $this->counts['workout'] : $this->counts['days']; }
  function update($t, $f, $w) { $this->queries[] = 'UPDATE ' . $t; if ($this->pause) { $this->pause = array_merge($this->pause, $f); } return 1; }
  public $insert_id = 0;
  function insert($t, $f) { $this->queries[] = 'INSERT ' . $t; $this->pause = $f + ['id' => 1]; $this->insert_id = 1; return 1; }
}
$GLOBALS['wpdb'] = new FakeDb();
require ${path('includes/stage3/class-access-tiers.php')};
require ${path('includes/stage3/class-membership.php')};
require ${path('includes/stage3/class-pause.php')};
LLD_Members_Access_Tiers::boot(); LLD_Members_Membership::boot(); LLD_Members_Pause::boot();
function sub($plan, $state = 'Premium', $ends = '2027-03-10', $locked = null) { return (object) ['plan' => $plan, 'access_state' => $state, 'period_ends_on' => $ends, 'pending_yearly_on' => null, 'locked_amount' => $locked, 'locked_currency' => 'usd', 'stripe_subscription' => 'sub_TEST1']; }
class Req { public $m; function __construct($m) { $this->m = $m; } function get_method() { return $this->m; } }
function refuse($type, $method, $m = 5) { return apply_filters('lld_members_stage3_api_refuse', null, $type, $m, new Req($method)); }
function fresh() { $r = new ReflectionProperty('LLD_Members_Pause', 'memo'); $r->setAccessible(true); $r->setValue(null, []); }
LLD_Members_Billing::$sub = sub('monthly');
`;

// --- P3-60 launch prices (FR3-62) -----------------------------------------------------------------------------

test('TC-FR3-62-AS-01: the pricing page shows US$9.99 a month and US$69.99 a year with the yearly saving, tax included', () => {
  const out = php(`${stubs}
    echo json_encode([
      'defaults' => apply_filters('lld_members_default_prices', ['yearly' => 5999, 'monthly' => 799]),
      'option' => apply_filters('lld_members_price_option', 'lld_prices'),
      'line' => LLD_Members_Membership::yearly_line(LLD_Members_Membership::LAUNCH),
      'monthly' => LLD_Members_Membership::money(999), 'yearly' => LLD_Members_Membership::money(6999),
      'saving' => LLD_Members_Membership::saving(999, 6999),
    ]);`);
  expect(out.defaults).toEqual({ monthly: 999, yearly: 6999 });
  expect(out.option).toBe('lld_prices_launch');
  expect(out.monthly).toBe('US$9.99');
  expect(out.yearly).toBe('US$69.99');
  expect(out.saving).toEqual({ cents: 4989, percent: 42, per_month: 583 });
  expect(out.line).toBe('tax included. That is US$5.83 a month, a saving of 42 % (US$49.89 a year).');
  // The page prints both prices through the filtered prices and marks each 'tax included'.
  const billing = src('includes/class-billing.php');
  expect(billing).toContain("LLD_Members_Membership::yearly_line( $prices )");
  // Both the pricing page and each track's sales page set $launch themselves and print the launch line through it.
  const body = (name) => billing.slice(billing.indexOf(`public static function ${name}(`), billing.indexOf('public static function', billing.indexOf(`public static function ${name}(`) + 10));
  for (const name of ['pricing', 'sales']) {
    expect(body(name)).toContain("$launch = class_exists( 'LLD_Members_Membership' ) && LLD_Members_Membership::launch_on();");
    expect(body(name)).toContain("$launch ? LLD_Members_Membership::yearly_line( $prices ) : 'tax included. That is US$5 a month, a saving of 37 %.'");
    expect(body(name)).not.toContain('tax included. That is US$5 a month, a saving of 37 %.</p>');
  }
  expect(billing).toMatch(/self::money\( \$prices\['monthly'\], 'usd' \) \. ' a month' \) \. '<\/p><p class="lld-plan-tax">tax included<\/p>'/);
});

test('TC-FR3-62-AS-01 (switch off): Stage 2 prices and wording are unchanged', () => {
  const out = php(`${stubs} LLD_Members_Features::$off = ['prices_launch'];
    echo json_encode(['defaults' => apply_filters('lld_members_default_prices', ['yearly' => 5999, 'monthly' => 799]), 'option' => apply_filters('lld_members_price_option', 'lld_prices')]);`);
  expect(out).toEqual({ defaults: { yearly: 5999, monthly: 799 }, option: 'lld_prices' });
  expect(src('includes/class-billing.php')).toContain("'tax included. That is US$5 a month, a saving of 37 %.'");
});

test('TC-FR3-62-AS-04: a member on an older price keeps it when the price for new members changes', () => {
  const out = php(`${stubs}
    LLD_Members_Billing::$prices = ['monthly' => 1299, 'yearly' => 8999];
    echo json_encode(['card' => LLD_Members_Membership::card(5, sub('monthly', 'Premium', '2027-03-10', 999))]);`);
  expect(out.card).toContain('data-lld-card-price="999"');
  expect(out.card).toContain('US$9.99 a month, tax included');
  // Stage 2 charges the locked amount at renewal, not the new price.
  expect(src('includes/class-billing.php')).toMatch(/\$base\s+= \( \$sub && \$sub->locked_amount \) \? \(int\) \$sub->locked_amount/);
});

// --- P3-61 Free and Premium access (FR3-63, FR3-68) -------------------------------------------------------------

test('TC-FR3-63-AS-01: a Free member can log food and health data on any day', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false; 
    echo json_encode([
      'food' => LLD_Members_Access_Tiers::allows(5, 'food_log'), 'health' => LLD_Members_Access_Tiers::allows(5, 'health_logging'),
      'food_api' => refuse('food-log', 'POST'), 'readings_api' => refuse('readings', 'POST'),
    ]);`);
  expect(out).toEqual({ food: true, health: true, food_api: null, readings_api: null });
});

test('TC-FR3-63-AS-02: a Free member sees the last 14 days on screen; stored rows and downloads keep everything', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false; $GLOBALS['today'] = '2027-03-20';
    $rows = [['id' => 1, 'taken_at' => '2027-03-06 23:00:00'], ['id' => 2, 'taken_at' => '2027-03-07 08:00:00'], ['id' => 3, 'taken_at' => '2027-03-20 07:00:00']];
    $free = apply_filters('lld_members_stage3_api_rows', $rows, 'readings', 5);
    $GLOBALS['premium'] = true; $premium = apply_filters('lld_members_stage3_api_rows', $rows, 'readings', 5);
    echo json_encode(['from' => LLD_Members_Access_Tiers::history_start('2027-03-20', false), 'free' => array_column($free, 'id'), 'premium' => array_column($premium, 'id'), 'writes' => count(array_filter($GLOBALS['wpdb']->queries, fn($q) => preg_match('/^(UPDATE|DELETE|INSERT)/', $q)))]);`);
  expect(out.from).toBe('2027-03-07');
  expect(out.free).toEqual([2, 3]);
  expect(out.premium).toEqual([1, 2, 3]);
  expect(out.writes).toBe(0);
  // The download is the Stage 2 export, which reads every row and never asks the access tiers.
  expect(src('includes/class-account.php')).not.toContain('Access_Tiers');
});

test('TC-FR3-63-AS-03: a Free member keeps goal progress and On track or Behind, but not long-term trends', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false;
    echo json_encode(['progress' => LLD_Members_Access_Tiers::allows(5, 'goal_progress'), 'status' => LLD_Members_Access_Tiers::allows(5, 'on_track'), 'trends' => LLD_Members_Access_Tiers::allows(5, 'trends')]);`);
  expect(out).toEqual({ progress: true, status: true, trends: false });
});

test('TC-FR3-63-AS-04: a Free member reaching a Premium feature sees a short explanation and an upgrade button', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false;
    $r = refuse('recipes', 'POST');
    echo json_encode(['prompt' => LLD_Members_Access_Tiers::upgrade_prompt('barcode'), 'api' => $r->data, 'status' => $r->status]);`);
  expect(out.prompt).toContain('Barcode scanning is part of Premium.');
  expect(out.prompt).toMatch(/<a class="lld-button" href="https:\/\/stg\.example\.test\/pricing\/">See Premium<\/a>/);
  expect(out.api).toMatchObject({ upgrade: true, feature: 'recipes', upgrade_url: 'https://stg.example.test/pricing/' });
  expect(out.api.message).toBe('Recipes in your food diary are part of Premium.');
});

test('TC-FR3-63-AS-05: a Premium member can use every Stage 3 feature', () => {
  const out = php(`${stubs}
    $all = array_map(fn($f) => LLD_Members_Access_Tiers::allows(5, $f), array_keys(LLD_Members_Access_Tiers::PREMIUM));
    echo json_encode(['all' => array_unique($all), 'api' => refuse('custom-foods', 'POST'), 'summary' => LLD_Members_Access_Tiers::summary(5)]);`);
  expect(out.all).toEqual([true]);
  expect(out.api).toBeNull();
  expect(out.summary).toMatchObject({ plan: 'premium', paused: false, history_days: null, reminders: null });
});

test('TC-FR3-63-AS-06: urgent-care warnings appear for Free and Premium members alike', () => {
  const out = php(`${stubs}
    $r = []; foreach ([true, false] as $p) { $GLOBALS['premium'] = $p; foreach (LLD_Members_Access_Tiers::SAFETY as $f) { $r[] = LLD_Members_Access_Tiers::allows(5, $f); } }
    echo json_encode(['all' => array_unique($r), 'overlap' => array_values(array_intersect(LLD_Members_Access_Tiers::SAFETY, array_keys(LLD_Members_Access_Tiers::PREMIUM)))]);`);
  expect(out.all).toEqual([true]);
  expect(out.overlap).toEqual([]);
});

test('TC-FR3-68-AS-01: on downgrade nothing is deleted, the first reminder runs and the other two are paused', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false;
    $rows = [['id' => 12, 'active' => 1, 'deleted_at' => null], ['id' => 9, 'active' => 1, 'deleted_at' => null], ['id' => 15, 'active' => 1, 'deleted_at' => null]];
    echo json_encode(['run' => LLD_Members_Access_Tiers::reminders_for(5, $rows), 'writes' => count($GLOBALS['wpdb']->queries)]);`);
  expect(out.run).toEqual([9]);
  expect(out.writes).toBe(0);
});

test('TC-FR3-68-AS-02: after downgrade, custom foods and recipes can be viewed but not added to a new day', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false;
    $r = [];
    foreach (['GET', 'POST', 'PATCH'] as $m) { foreach (['custom-foods', 'recipes'] as $t) { $x = refuse($t, $m); $r[$m . ' ' . $t] = $x ? $x->status : 200; } }
    // The route runs the request's own method: a GET carrying ?_method=POST is a POST, whatever the server saw.
    $_SERVER['REQUEST_METHOD'] = 'GET'; $x = refuse('custom-foods', 'POST'); $r['override'] = $x ? $x->status : 200;
    $x = apply_filters('lld_members_stage3_api_refuse', null, 'custom-foods', 5); $r['no request'] = $x ? $x->status : 200;
    echo json_encode($r);`);
  expect(out).toEqual({ 'GET custom-foods': 200, 'GET recipes': 200, 'POST custom-foods': 403, 'POST recipes': 403, 'PATCH custom-foods': 403, 'PATCH recipes': 403, override: 403, 'no request': 403 });
});

test('TC-FR3-68-AS-03: upgrading again brings back every custom food, recipe, reminder and saved result', () => {
  const out = php(`${stubs}
    $rem = [['id' => 9, 'active' => 1, 'deleted_at' => null], ['id' => 12, 'active' => 1, 'deleted_at' => null], ['id' => 15, 'active' => 1, 'deleted_at' => null]];
    $res = [['id' => 1, 'tool' => 'calorie-target', 'created_at' => '2027-01-01 10:00:00'], ['id' => 2, 'tool' => 'calorie-target', 'created_at' => '2027-02-01 10:00:00']];
    $GLOBALS['premium'] = false; $free = ['rem' => LLD_Members_Access_Tiers::reminders_for(5, $rem), 'res' => array_column(apply_filters('lld_members_stage3_api_rows', $res, 'calculator-results', 5), 'id')];
    $GLOBALS['premium'] = true;
    echo json_encode(['free' => $free, 'rem' => LLD_Members_Access_Tiers::reminders_for(5, $rem), 'res' => array_column(apply_filters('lld_members_stage3_api_rows', $res, 'calculator-results', 5), 'id'), 'add' => refuse('custom-foods', 'POST')]);`);
  expect(out.free).toEqual({ rem: [9], res: [2] });
  expect(out.rem).toEqual([9, 12, 15]);
  expect(out.res).toEqual([1, 2]);
  expect(out.add).toBeNull();
});

// --- P3-62 membership card (FR3-64) -----------------------------------------------------------------------------

test('TC-FR3-64-AS-01: a yearly member\'s card shows the yearly plan, price, renewal date and saving', () => {
  const out = php(`${stubs} echo json_encode(['card' => LLD_Members_Membership::card(5, sub('yearly', 'Premium', '2027-11-03', 6999))]);`);
  expect(out.card).toContain('Yearly Premium');
  expect(out.card).toContain('US$69.99 a year, tax included');
  expect(out.card).toContain('data-lld-card-renews="2027-11-03">Renews on 3 November 2027');
  expect(out.card).toContain('You save US$49.89 a year compared with paying monthly.');
  expect(out.card).toContain('What Premium includes');
  expect(out.card).toContain('href="https://stg.example.test/members/subscription/#lld-plan-actions">Manage my plan');
});

test('TC-FR3-64-AS-02: the card shows the member\'s own counts of completed workouts and logged days', () => {
  const out = php(`${stubs} echo json_encode(['card' => LLD_Members_Membership::card(5, sub('monthly')), 'q' => $GLOBALS['wpdb']->queries]);`);
  expect(out.card).toContain('data-workouts="7" data-days="12">Workouts completed: 7. Days logged: 12.');
  // The workout count is the Stage 2 progress page's own "workout sessions done" query, for this member only.
  const stage2 = src('includes/class-premium.php');
  expect(stage2).toContain("WHERE member_id = %d AND result = 'done' GROUP BY context_type");
  const workouts = out.q.find((q) => q.includes("context_type = 'workout'"));
  expect(workouts).toContain("WHERE member_id = %d AND context_type = 'workout' AND result = 'done'");
  const days = out.q.find((q) => q.includes('COUNT(DISTINCT d)'));
  for (const table of ['wp_lld_diary_meal', 'wp_lld_food_log', 'wp_lld_readings']) expect(days).toContain(table);
});

test('TC-FR3-64-AS-03: a Free member sees an upgrade card instead', () => {
  const out = php(`${stubs} $GLOBALS['premium'] = false;
    echo json_encode(['none' => apply_filters('lld_members_subscription_top', '', 5, null), 'free' => LLD_Members_Membership::card(5, sub('monthly', 'free'))]);`);
  for (const html of [out.none, out.free]) {
    expect(html).toContain('data-lld-membership="free"');
    expect(html).toContain('US$9.99 a month or US$69.99 a year, tax included. Yearly saves US$49.89.');
    expect(html).toContain('See Premium');
    expect(html).not.toContain('Your membership');
  }
});

// --- P3-63 pause (FR3-65) ---------------------------------------------------------------------------------------

test('TC-FR3-65-AS-01: renewing on 10 March, a 1-month pause keeps Premium to 10 March, no charge then, next charge 10 April', () => {
  const out = php(`${stubs}
    LLD_Members_Billing::$sub = sub('monthly'); $GLOBALS['today'] = '2027-03-01';
    $started = LLD_Members_Pause::start(5, 1); $scheduled = $GLOBALS['wpdb']->pause; fresh();
    $before = LLD_Members_Billing::has_premium(5); $card = LLD_Members_Membership::card(5, LLD_Members_Billing::$sub); $section = LLD_Members_Pause::section(5, LLD_Members_Billing::$sub);
    $GLOBALS['today'] = '2027-03-09'; fresh(); $on9 = LLD_Members_Billing::has_premium(5);
    $GLOBALS['today'] = '2027-03-10'; fresh(); $on10 = LLD_Members_Billing::has_premium(5);
    echo json_encode(['started' => $started, 'pause' => $scheduled, 'now' => $GLOBALS['wpdb']->pause['status'], 'before' => $before, 'on9' => $on9, 'on10' => $on10, 'card' => $card, 'section' => $section, 'months' => [LLD_Members_Pause::add_months('2027-01-31', 1), LLD_Members_Pause::add_months('2027-11-30', 3)]]);`);
  expect(out.started).toBe(true);
  expect(out.pause).toMatchObject({ months: 1, starts_at: '2027-03-10', ends_at: '2027-04-10', status: 'scheduled', subscription_ref: 'sub_TEST1' });
  expect([out.before, out.on9, out.on10, out.now]).toEqual([true, true, false, 'active']);
  expect(out.card).toContain('data-lld-card-renews="2027-04-10">Renews on 10 April 2027');
  expect(out.section).toContain('Premium continues to 10 March 2027. Your plan is then paused for 1 month, with no charge.');
  expect(out.section).toContain('your next payment is on 10 April 2027');
  expect(out.months).toEqual(['2027-02-28', '2028-02-29']);
});

test('TC-FR3-65-AS-01 (Stripe): collection is paused, voiding the 10 March renewal and resuming before the 10 April one', () => {
  const out = php(`
    define('ABSPATH', '/'); define('HOUR_IN_SECONDS', 3600); define('LLD_STRIPE_SECRET', '${FAKE_KEY}'); define('LLD_STRIPE_WEBHOOK', '${WEBHOOK}');
    class WP_Error { public $message; function __construct($c = '', $m = '', $d = '') { $this->message = $m; } function get_error_message() { return $this->message; } }
    function is_wp_error($t) { return $t instanceof WP_Error; } function sanitize_text_field($s) { return (string) $s; } function add_filter() {} function add_action() {}
    function get_transient() { return false; } function set_transient() { return true; }
    $GLOBALS['sent'] = [];
    function wp_remote_request($url, $args) { $GLOBALS['sent'][] = [$args['method'] . ' ' . $url, $args['body'] ?? '']; return ['code' => 200, 'body' => json_encode(['id' => 'sub_TEST1', 'current_period_end' => gmmktime(14, 0, 0, 3, 10, 2027)])]; }
    function wp_remote_retrieve_response_code($r) { return $r['code']; } function wp_remote_retrieve_body($r) { return $r['body']; }
    class LLD_Members_Billing { static function stripe_key($k) { return LLD_STRIPE_SECRET; } }
    require ${path('includes/stage3/class-stripe-api.php')};
    $ok = LLD_Members_Stripe_API::pause('sub_TEST1', 1);
    echo json_encode(['ok' => $ok, 'sent' => $GLOBALS['sent'], 'resumes' => gmdate('Y-m-d H:i', LLD_Members_Stripe_API::resumes_at(gmmktime(14, 0, 0, 3, 10, 2027), 1)), 'jan31' => gmdate('Y-m-d H:i', LLD_Members_Stripe_API::resumes_at(gmmktime(9, 30, 0, 1, 31, 2027), 1))]);`);
  expect(out.ok).toBe(true);
  expect(out.resumes).toBe('2027-04-10 13:00');
  expect(out.jan31).toBe('2027-02-28 08:30');
  const [get, post] = out.sent;
  expect(get[0]).toBe('GET https://api.stripe.com/v1/subscriptions/sub_TEST1');
  expect(post[0]).toBe('POST https://api.stripe.com/v1/subscriptions/sub_TEST1');
  const body = new URLSearchParams(post[1]);
  expect(body.get('pause_collection[behavior]')).toBe('void');
  expect(Number(body.get('pause_collection[resumes_at]'))).toBe(Date.UTC(2027, 3, 10, 13, 0) / 1000);
});

test('TC-FR3-65-AS-02: during the pause the member has Free access and none of their data is deleted', () => {
  const out = php(`${stubs}
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 2, 'starts_at' => '2027-03-10', 'ends_at' => '2027-05-10', 'status' => 'active', 'subscription_ref' => 'sub_TEST1'];
    $GLOBALS['today'] = '2027-04-02';
    echo json_encode(['premium' => LLD_Members_Billing::has_premium(5), 'stage2' => LLD_Members_Billing::paid_state(5), 'tiers' => LLD_Members_Access_Tiers::summary(5), 'q' => $GLOBALS['wpdb']->queries]);`);
  expect(out.premium).toBe(false);
  expect(out.stage2).toBe(true);
  expect(out.tiers).toMatchObject({ plan: 'free', paused: true, history_days: 14, reminders: 1 });
  expect(out.q.filter((q) => /DELETE/i.test(q))).toEqual([]);
  expect(src('includes/stage3/class-pause.php')).not.toMatch(/->delete\(|DELETE FROM/);
});

test('TC-FR3-65-AS-03: at the end of the pause Premium returns, charged at the member\'s original price', () => {
  const out = php(`${stubs}
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 1, 'starts_at' => '2027-03-10', 'ends_at' => '2027-04-10', 'status' => 'active', 'subscription_ref' => 'sub_TEST1'];
    $GLOBALS['today'] = '2027-04-10';
    LLD_Members_Billing::$prices = ['monthly' => 1299, 'yearly' => 8999];
    echo json_encode(['premium' => LLD_Members_Billing::has_premium(5), 'status' => $GLOBALS['wpdb']->pause['status'], 'card' => LLD_Members_Membership::card(5, sub('monthly', 'Premium', '2027-04-10', 999))]);`);
  expect(out.premium).toBe(true);
  expect(out.status).toBe('ended');
  expect(out.card).toContain('data-lld-card-price="999"');
  // The Stripe pause sends no price, so the subscription keeps its own.
  const pause = src('includes/stage3/class-stripe-api.php').split('public static function pause(')[1].split('public static function resumes_at(')[0];
  expect(pause).not.toMatch(/price|items\[/);
});

test('TC-FR3-65-AS-04: a failed payment at the end of the pause follows the Stage 2 failed-payment retries', () => {
  const out = php(`${stubs}
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 1, 'starts_at' => '2027-03-10', 'ends_at' => '2027-04-10', 'status' => 'active', 'subscription_ref' => 'sub_TEST1'];
    $GLOBALS['today'] = '2027-04-11';
    $grace = LLD_Members_Billing::has_premium(5); fresh();
    $GLOBALS['premium'] = false;
    echo json_encode(['grace' => $grace, 'ended' => LLD_Members_Billing::has_premium(5)]);`);
  // After the pause the Stage 2 answer passes through unchanged: open in the retry grace period, closed when it ends.
  expect(out).toEqual({ grace: true, ended: false });
  const billing = src('includes/class-billing.php');
  expect(billing).toContain("'invoice.payment_failed' === $type");
  expect(billing).toContain('self::mark_retry( (int) $row->member_id');
  expect(src('includes/stage3/class-pause.php')).not.toMatch(/payment_failed|mark_retry/);
});

test('TC-FR3-65-AS-05: a yearly member is not offered a pause', () => {
  const out = php(`${stubs}
    $y = sub('yearly', 'Premium', '2027-11-03');
    echo json_encode(['may' => LLD_Members_Pause::may_pause($y, true, null), 'offer' => LLD_Members_Pause::cancel_offer('', 5, $y), 'section' => LLD_Members_Pause::section(5, $y), 'start' => (LLD_Members_Billing::$sub = $y) && is_wp_error(LLD_Members_Pause::start(5, 1))]);`);
  expect(out).toEqual({ may: false, offer: '', section: '', start: true });
});

test('TC-FR3-65-AS-06: starting to cancel shows the pause option before the final cancel button', () => {
  const out = php(`${stubs} echo json_encode(['offer' => LLD_Members_Pause::cancel_offer('', 5, sub('monthly'))]);`);
  expect(out.offer).toContain('Pause instead?');
  for (const m of [1, 2, 3]) expect(out.offer).toContain(`value="pause_${m}"`);
  const billing = src('includes/class-billing.php');
  const offer = billing.indexOf("apply_filters( 'lld_members_cancel_offer'");
  expect(offer).toBeGreaterThan(billing.indexOf('<h2>Cancel Premium?</h2>'));
  expect(offer).toBeLessThan(billing.indexOf('value="cancel">Confirm cancellation</button>'));
});

// --- Review fixes: pause against the rest of billing --------------------------------------------------------------

test('TC-FR3-65-AS-02 (no second subscription): a paused member cannot start a second checkout', () => {
  const out = php(`${stubs}
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 2, 'starts_at' => '2027-03-10', 'ends_at' => '2027-05-10', 'status' => 'active', 'subscription_ref' => 'sub_TEST1'];
    $GLOBALS['today'] = '2027-04-01';
    echo json_encode(['premium' => LLD_Members_Billing::has_premium(5), 'subscribed' => LLD_Members_Billing::paid_state(5)]);`);
  expect(out).toEqual({ premium: false, subscribed: true });
  // The three Stage 2 double-subscription guards (checkout page, checkout route, paid twice) ask paid_state, not has_premium.
  const billing = src('includes/class-billing.php');
  expect(billing.match(/\$member_id > 0 && self::paid_state\( \$member_id \)/g)).toHaveLength(3);
  expect(billing).not.toMatch(/if \( self::has_premium\( \$member_id \)/);
});

test('TC-FR3-65-AS-03 (new subscription): an old pause never closes a later subscription', () => {
  const out = php(`${stubs}
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 2, 'starts_at' => '2027-03-10', 'ends_at' => '2027-05-10', 'status' => 'active', 'subscription_ref' => 'sub_OLD'];
    $GLOBALS['today'] = '2027-04-01';
    $new = LLD_Members_Billing::has_premium(5);
    $GLOBALS['wpdb']->pause['subscription_ref'] = 'sub_TEST1'; fresh();
    echo json_encode(['new' => $new, 'same' => LLD_Members_Billing::has_premium(5)]);`);
  expect(out).toEqual({ new: true, same: false });
});

test('TC-FR3-65-AS-05 (switch to yearly): no switch to yearly while a pause is open, and no pause in arrears', () => {
  const out = php(`${stubs}
    $none = apply_filters('lld_members_can_switch_yearly', true, 5);
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 1, 'starts_at' => '2027-03-10', 'ends_at' => '2027-04-10', 'status' => 'scheduled', 'subscription_ref' => 'sub_TEST1'];
    fresh(); $scheduled = apply_filters('lld_members_can_switch_yearly', true, 5);
    $GLOBALS['today'] = '2027-04-10'; fresh(); $after = apply_filters('lld_members_can_switch_yearly', true, 5);
    $s = sub('monthly'); $s->stripe_state = 'past_due';
    echo json_encode(['none' => $none, 'scheduled' => $scheduled, 'after' => $after, 'past_due' => LLD_Members_Pause::may_pause($s, true, null)]);`);
  expect(out).toEqual({ none: true, scheduled: false, after: true, past_due: false });
});

test('TC-FR3-65-AS-06 (cancel while paused): Premium does not return, and no free day after the pause', () => {
  const out = php(`${stubs}
    // Stripe moved the paid period to the end of the voided month while collection was paused.
    LLD_Members_Billing::$sub = sub('monthly', 'cancelled', '2027-04-10');
    $GLOBALS['wpdb']->pause = ['id' => 1, 'member_id' => 5, 'months' => 1, 'starts_at' => '2027-03-10', 'ends_at' => '2027-04-10', 'status' => 'active', 'subscription_ref' => 'sub_TEST1'];
    $r = [];
    foreach (['2027-03-09', '2027-03-20', '2027-04-10', '2027-04-11'] as $d) { $GLOBALS['today'] = $d; fresh(); $r[$d] = LLD_Members_Billing::has_premium(5); }
    $GLOBALS['today'] = '2027-03-20'; $GLOBALS['wpdb']->pause['status'] = 'active'; fresh();
    $offer = LLD_Members_Pause::cancel_offer('', 5, LLD_Members_Billing::$sub); $card = LLD_Members_Membership::card(5, LLD_Members_Billing::$sub);
    // Paid again after the pause, then cancelled: Premium to the end of that paid month, as in Stage 2.
    LLD_Members_Billing::$sub = sub('monthly', 'cancelled', '2027-05-10'); $GLOBALS['today'] = '2027-04-20'; fresh();
    echo json_encode(['days' => $r, 'offer' => $offer, 'card' => $card, 'paid_again' => LLD_Members_Billing::has_premium(5)]);`);
  expect(out.days).toEqual({ '2027-03-09': true, '2027-03-20': false, '2027-04-10': false, '2027-04-11': false });
  expect(out.offer).toContain('If you cancel, you keep Free access, Premium does not come back and you are not charged again.');
  expect(out.card).toContain('Paused, and cancelled: Premium will not return after the pause.');
  expect(out.paid_again).toBe(true);
});

test('TC-FR3-65-AS-01 (pause, undo, pause again): each pause reaches Stripe, with no reused request key', () => {
  const out = php(`
    define('ABSPATH', '/'); define('HOUR_IN_SECONDS', 3600); define('LLD_STRIPE_SECRET', '${FAKE_KEY}'); define('LLD_STRIPE_WEBHOOK', '${WEBHOOK}');
    class WP_Error { public $message; function __construct($c = '', $m = '', $d = '') { $this->message = $m; } function get_error_message() { return $this->message; } }
    function is_wp_error($t) { return $t instanceof WP_Error; } function sanitize_text_field($s) { return (string) $s; } function add_filter() {} function add_action() {}
    $GLOBALS['sent'] = [];
    function wp_remote_request($url, $args) { $GLOBALS['sent'][] = [$args['method'], $args['body'] ?? '', $args['headers']['Idempotency-Key'] ?? '']; return ['code' => 200, 'body' => json_encode(['id' => 'sub_TEST1', 'current_period_end' => gmmktime(14, 0, 0, 3, 10, 2027)])]; }
    function wp_remote_retrieve_response_code($r) { return $r['code']; } function wp_remote_retrieve_body($r) { return $r['body']; }
    class LLD_Members_Billing { static function stripe_key($k) { return LLD_STRIPE_SECRET; } }
    require ${path('includes/stage3/class-stripe-api.php')};
    LLD_Members_Stripe_API::pause('sub_TEST1', 1); LLD_Members_Stripe_API::unpause('sub_TEST1'); LLD_Members_Stripe_API::pause('sub_TEST1', 1);
    echo json_encode(array_values(array_filter($GLOBALS['sent'], fn($s) => $s[0] === 'POST')));`);
  expect(out).toHaveLength(3);
  expect(out.map((s) => s[2])).toEqual(['', '', '']);
  expect(new URLSearchParams(out[1][1]).get('pause_collection')).toBe('');
  expect(new URLSearchParams(out[2][1]).get('pause_collection[behavior]')).toBe('void');
});

// --- Managed Payments wording and the pause emails (P3-90 review, Owner question 70) ------------------------------

test('TC-FR3-62-AS-05: the pricing page shows the line Prices in US$; your bank or Stripe may convert', () => {
  const out = php(`${stubs}
    $on = apply_filters('lld_members_pricing_note', '', LLD_Members_Membership::LAUNCH);
    LLD_Members_Features::$off = ['prices_launch'];
    echo json_encode(['on' => $on, 'off' => apply_filters('lld_members_pricing_note', '', LLD_Members_Membership::LAUNCH)]);`);
  expect(out.on).toContain('Prices in US$; your bank or Stripe may convert.');
  expect(out.off).toBe('');
  // The note sits on the pricing page itself, under the two plans.
  const billing = src('includes/class-billing.php');
  const pricing = billing.slice(billing.indexOf('public static function pricing('), billing.indexOf('private static function pricing_features('));
  expect(pricing).toContain("apply_filters( 'lld_members_pricing_note', '', $prices )");
});

test('TC-FR3-62-AS-06: with Managed Payments the payment email says the receipt comes from Onelink, without the words Your receipt', () => {
  const out = php(`${stubs}
    $stage2 = apply_filters('lld_members_receipt_mail', ['subject' => 'Your receipt', 'body' => 'Your receipt. Amount US$9.99, tax included. Date 2027-03-10.'], 5, 'US$9.99', '2027-03-10');
    $GLOBALS['stripe_on'] = true;
    $managed = apply_filters('lld_members_receipt_mail', ['subject' => 'Your receipt', 'body' => 'Your receipt. Amount US$9.99, tax included. Date 2027-03-10.'], 5, 'US$9.99', '2027-03-10');
    echo json_encode(['stage2' => $stage2, 'managed' => $managed]);`);
  expect(out.stage2.subject).toBe('Your receipt');
  expect(out.managed.subject).toBe('Your Premium payment is confirmed');
  expect(out.managed.body).toContain('The receipt comes in a separate email from Onelink');
  expect(out.managed.body).toContain('US$9.99, tax included, on 10 March 2027');
  expect(`${out.managed.subject} ${out.managed.body}`.toLowerCase()).not.toContain('your receipt');
  // Both Stage 2 payment emails (first payment and renewal) go through the one filtered sender.
  const billing = src('includes/class-billing.php');
  expect(billing.match(/self::receipt_mail\( \$member_id, /g)).toHaveLength(2);
  expect(billing).not.toMatch(/mail_member\( \$member_id, 'Your receipt'/);
});

const mailStubs = `${stubs}
class MailDb extends FakeDb { public $claims = []; public $rows = [];
  function get_row($q) { if (strpos($q, 'first_name FROM') !== false) { return (object) ['email' => 'member@example.test', 'first_name' => 'Sam']; } return parent::get_row($q); }
  function get_results($q) { return $this->pause ? [(object) $this->pause] : []; }
  function query($q) { return 0; }
}
$GLOBALS['wpdb'] = new MailDb(); $GLOBALS['sent'] = [];
function is_email($e) { return strpos((string) $e, '@') !== false; }
function wp_mail($to, $s, $b) { $GLOBALS['sent'][] = $s; return true; }
// The claim: the first INSERT IGNORE for a key inserts one row, later ones none.
$GLOBALS['wpdb'] = new class extends MailDb { function prepare($q, ...$a) { return $q . '|' . implode('|', $a); }
  function query($q) { if (strpos($q, 'INSERT IGNORE') === 0) { $k = explode('|', $q)[2]; if (isset($this->claims[$k])) { return 0; } $this->claims[$k] = 1; return 1; } return 0; } };
`;

test('TC-FR3-65-AS-01 (emails): EM-31 when the pause starts, EM-32 seven days before it ends, EM-33 after the first payment, each once', () => {
  const out = php(`${mailStubs}
    $GLOBALS['wpdb']->pause = ['id' => 7, 'member_id' => 5, 'months' => 1, 'starts_at' => '2027-03-10', 'ends_at' => '2027-04-10', 'status' => 'scheduled', 'subscription_ref' => 'sub_TEST1'];
    $log = [];
    foreach (['2027-03-09', '2027-03-10', '2027-03-11', '2027-04-02', '2027-04-03', '2027-04-04'] as $d) { $GLOBALS['today'] = $d; $GLOBALS['sent'] = []; LLD_Members_Pause::daily_mail(); $log[$d] = $GLOBALS['sent']; }
    $GLOBALS['today'] = '2027-04-10'; $GLOBALS['sent'] = [];
    LLD_Members_Pause::on_event('upgraded', 5, []); LLD_Members_Pause::on_event('renewed', 5, []); LLD_Members_Pause::on_event('renewed', 5, []);
    $log['renewed'] = $GLOBALS['sent'];
    $copy = LLD_Members_Pause::mail_copy('started', 'Sam', (object) $GLOBALS['wpdb']->pause, 'US$7.99');
    echo json_encode(['log' => $log, 'copy' => $copy]);`);
  expect(out.log).toEqual({
    '2027-03-09': [],
    '2027-03-10': ['Your Premium pause has started'],
    '2027-03-11': [],
    '2027-04-02': [],
    '2027-04-03': ['Your Premium returns on 10 April 2027'],
    '2027-04-04': [],
    renewed: ['Welcome back to Premium'],
  });
  // The member's own price, and nothing about their health.
  expect(out.copy.body).toContain('Hi Sam,');
  expect(out.copy.body).toContain('until 10 April 2027');
  expect(out.copy.body).toContain('at your current price of US$7.99 a month, tax included');
  expect(out.copy.body).toContain('Nothing is deleted.');
});

test('TC-FR3-65-AS-06 (emails): no pause email once the plan is cancelled or the pause belongs to an older subscription', () => {
  const out = php(`${mailStubs}
    $GLOBALS['wpdb']->pause = ['id' => 8, 'member_id' => 5, 'months' => 1, 'starts_at' => '2027-03-10', 'ends_at' => '2027-04-10', 'status' => 'active', 'subscription_ref' => 'sub_TEST1'];
    LLD_Members_Billing::$sub = sub('monthly', 'cancelled', '2027-04-10');
    $GLOBALS['today'] = '2027-04-05'; LLD_Members_Pause::daily_mail();
    $cancelled = $GLOBALS['sent'];
    LLD_Members_Billing::$sub = sub('monthly'); LLD_Members_Billing::$sub->stripe_subscription = 'sub_NEW';
    LLD_Members_Pause::daily_mail(); $GLOBALS['today'] = '2027-04-10'; LLD_Members_Pause::on_event('renewed', 5, []);
    echo json_encode(['cancelled' => $cancelled, 'other' => $GLOBALS['sent']]);`);
  expect(out).toEqual({ cancelled: [], other: [] });
});
