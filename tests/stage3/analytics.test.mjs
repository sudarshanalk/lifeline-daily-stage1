// P3-70 to P3-72 in PHP CLI with a fake WordPress: which pages may carry the Google tag, what the tag may be asked
// to send, that GA4 keeps Plausible off, and the Monday report's Stage 3 part, including Stripe's paid invoices and
// Google Analytics totals answered by a fake transport (no request leaves the machine, no real key is used).
import { execFileSync } from 'node:child_process';
import { generateKeyPairSync, createVerify } from 'node:crypto';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim();
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const stubs = ({ gaId = 'G-TEST1234', defines = '' } = {}) => `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); define('HOUR_IN_SECONDS', 3600);
${gaId ? `define('LLD_GA4_ID', '${gaId}');` : ''}
${defines}
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
$GLOBALS['opts'] = []; $GLOBALS['hooks'] = []; $GLOBALS['removed'] = []; $GLOBALS['posts'] = []; $GLOBALS['answers'] = [];
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_transient($k) { return $GLOBALS['opts']['_t_' . $k] ?? false; }
function set_transient($k, $v) { $GLOBALS['opts']['_t_' . $k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['opts']['_t_' . $k]); return true; }
function add_action($h, $c) { $GLOBALS['hooks'][] = $h; } function add_filter($h, $c) { $GLOBALS['hooks'][] = $h; }
function remove_action($h, $c) { $GLOBALS['removed'][] = [$h, is_array($c) ? implode('::', $c) : $c]; }
function lld_members_test_routes_open() { return false; }
function home_url($p = '') { return 'https://stg.example.test' . $p; }
function esc_url($s) { return $s; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $s)); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wp_date($f) { return gmdate($f); }
function wp_remote_post($url, $args) {
  $GLOBALS['posts'][] = ['url' => $url, 'args' => $args];
  foreach ($GLOBALS['answers'] as $match => $answer) { if (strpos($url, $match) !== false) { return $answer; } }
  return ['code' => 500, 'body' => ''];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
class LLD_Members_Access { static $id = 0; static function current_member_id() { return self::$id; } }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Growth { const REPORT_HOOK = 'lld_members_weekly_report'; const TRACKS = ['healthy-weight' => 'Healthy Weight', 'blood-sugar' => 'Blood Sugar Balance', 'fitness' => 'Fitness and Strength', 'sleep-stress' => 'Sleep and Stress']; }
class FakeDb {
  public $queries = []; public $updated = [];
  public $pauses = ['starts_at BETWEEN' => 2, 'created_at BETWEEN' => 3, 'ends_at IS NULL' => 4];
  public $premium = 5; public $members = 12;
  public $orders = [];
  function prepare($q, ...$a) { return $q . ' -- ' . implode(',', $a); }
  function get_var($q) { $this->queries[] = $q; if (strpos($q, 'subscription') !== false) return $this->premium; if (strpos($q, 'FROM wp_lld_member ') !== false) return $this->members; foreach ($this->pauses as $k => $v) { if (strpos($q, $k) !== false) return $v; } return 0; }
  function get_results($q) { $this->queries[] = $q; return $this->orders; }
  function update($t, $d, $w) { $this->updated[] = [$t, $d, $w]; return 1; }
}
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(resolve(plugin, 'includes/stage3/class-analytics.php'))};
require ${toPhpPath(resolve(plugin, 'includes/stage3/class-weekly-report-stage3.php'))};
require ${toPhpPath(resolve(plugin, 'includes/stage3/class-calculator-count.php'))};
`;

test('TC-FR3-66-AS-03 (hooks): with a measurement ID the Plausible script is taken off; without one nothing is hooked', () => {
  const on = JSON.parse(php(`${stubs()} LLD_Members_Analytics::boot(); echo json_encode(['hooks' => $GLOBALS['hooks'], 'removed' => $GLOBALS['removed']]);`));
  expect(on.removed).toContainEqual(['wp_footer', 'LLD_Members_Growth::footer_script']);
  expect(on.hooks).toContain('lld_members_plausible_on');
  expect(on.hooks).toContain('lld_members_layout_foot');
  const off = JSON.parse(php(`${stubs({ gaId: '' })} LLD_Members_Analytics::boot(); echo json_encode(['hooks' => $GLOBALS['hooks'], 'removed' => $GLOBALS['removed'], 'html' => LLD_Members_Analytics::foot_html(true)]);`));
  expect(off.removed).toEqual([]);
  expect(off.hooks).toEqual(['rest_api_init']);
  expect(off.html).toBe('');
  // A malformed ID counts as none.
  expect(php(`${stubs({ gaId: 'UA-123<script>' })} echo LLD_Members_Analytics::measurement_id();`)).toBe('');
});

test('TC-NFR3-16-AS-01 (pages): only public pages, sign-up and checkout may carry the tag', () => {
  const cases = [
    ['/pricing/', 'pricing', true], ['/programmes/fitness/', 'sales', true], ['/health-tools/protein/', 'landing', true],
    ['/members/join/', 'join', true], ['/members/checkout/', 'checkout', true], ['/a-public-article/', '', true], ['/', '', true],
    ['/members/home/', 'home', false], ['/members/diary/', 'diary', false], ['/members/health/', 'health', false],
    ['/members/record/7/', 'record', false], ['/members/settings/', 'settings', false], ['/members/subscription/', 'subscription', false],
    ['/members/today/', '', false], ['/members/', '', false], ['/guide/confirm/', 'guide-confirm', false], ['/guide/confirm/', '', false],
    ['/tools/healthy-weight-tool.html', '', false], ['/wp-admin/', '', false], ['/wp-json/lld-members/v1/me/goals', '', false],
  ];
  const out = JSON.parse(php(`${stubs()} $r = []; foreach (json_decode(${JSON.stringify(JSON.stringify(cases))}, true) as $c) { $r[] = LLD_Members_Analytics::path_measured($c[0], $c[1]); } echo json_encode($r);`));
  cases.forEach(([path, screen, want], i) => expect(out[i], `${path} ${screen}`).toBe(want));
  // On a page that is not measured, the script still knows the ID (to change the choice) but is told not to load the tag.
  const config = php(`${stubs()} LLD_Members_Analytics::queue('begin_checkout', ['plan' => 'monthly']); $h = LLD_Members_Analytics::foot_html(false); preg_match('#<script id="lld-ga4" type="application/json">(.*?)</script>#', $h, $m); echo $m[1];`);
  expect(JSON.parse(config)).toMatchObject({ id: 'G-TEST1234', measured: false, events: [] });
});

test('TC-NFR3-16-AS-02 (events): only the allowlisted events and values are ever queued', () => {
  const out = JSON.parse(php(`${stubs()}
    LLD_Members_Analytics::queue('begin_checkout', ['plan' => 'monthly']);
    LLD_Members_Analytics::queue('begin_checkout', ['plan' => 'bmi-31']);
    LLD_Members_Analytics::queue('begin_checkout', ['plan' => 'yearly', 'member_id' => '42']);
    LLD_Members_Analytics::queue('track_started', ['track' => 'blood-sugar']);
    LLD_Members_Analytics::queue('calculator_used', ['calculator' => 'diabetes']);
    LLD_Members_Analytics::queue('sign_up', []);
    LLD_Members_Analytics::queue('sign_up', ['method' => 'someone@example.test']);
    $h = LLD_Members_Analytics::foot_html(true); preg_match('#<script id="lld-ga4" type="application/json">(.*?)</script>#', $h, $m); echo $m[1];`));
  expect(out.events).toEqual([{ name: 'begin_checkout', params: { plan: 'monthly' } }, { name: 'sign_up', params: [] }]);
  // A registration is kept for the member's next public page without any detail.
  const later = JSON.parse(php(`${stubs()} LLD_Members_Analytics::on_event('registered', 42, ['source' => 'x']); LLD_Members_Analytics::on_event('track_started', 42, ['track' => 'fitness']); echo json_encode($GLOBALS['opts']);`));
  expect(later).toEqual({ _t_lld_ga_events_42: [{ name: 'sign_up', params: [] }] });
});

test('TC-FR3-67-AS-02 (script): Consent Mode defaults to denied and the tag is only fetched inside start()', () => {
  const js = php(`${stubs()} echo LLD_Members_Analytics::script();`);
  expect(js).toContain('gtag("consent","default",{analytics_storage:"denied",ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied"})');
  const start = js.indexOf('function start(){');
  const fetch = js.indexOf('document.createElement("script")');
  expect(start).toBeGreaterThan(-1);
  expect(fetch).toBeGreaterThan(start);
  expect(js.indexOf('function forget(')).toBeGreaterThan(fetch);
  expect(js.match(/createElement\("script"\)/g)).toHaveLength(1);
  // The calculator's result message is never listened to.
  expect(js).not.toContain('lld-calculator-result');
});

const week = ['2026-10-26', '2026-11-01'];
const stage2Text = [`Weekly report for ${week[0]} to ${week[1]}`, 'Visits and calculator use: Plausible is not connected', 'Healthy Weight registrations 1', 'All registrations 3', 'Monthly recurring revenue 9.99'].join('\n');
const figures = { registrations: 3, tracks: { 'healthy-weight': { upgrades: 2, renewals: 1, cancellations: 1 }, fitness: { upgrades: 1, renewals: 0, cancellations: 0 }, none: { upgrades: 1, renewals: 2, cancellations: 1 } } };

test('TC-FR3-70-AS-01 (figures): sign-ups, upgrades, renewals, pauses, cancellations, revenue and members match the prepared week', () => {
  const text = php(`${stubs()}
    $GLOBALS['opts']['lld_calculator_counts'] = ['${week[0]}' => ['protein' => 3, 'healthy-weight' => 1], '2026-11-02' => ['protein' => 9]];
    $GLOBALS['wpdb']->orders = [(object) ['kind' => 'payment', 'charged_currency' => 'gbp', 'n' => 2, 'total' => 1998], (object) ['kind' => 'payment', 'charged_currency' => 'usd', 'n' => 1, 'total' => 6999], (object) ['kind' => 'refund', 'charged_currency' => 'usd', 'n' => 1, 'total' => 999]];
    echo LLD_Members_Stage3_Report::extend_text(${JSON.stringify(stage2Text)}, json_decode(${JSON.stringify(JSON.stringify(figures))}, true), ['${week[0]}', '${week[1]}'], null);`);
  const lines = text.split('\n');
  // Every Stage 2 line is still there, in order.
  expect(lines[0]).toBe(`Weekly report for ${week[0]} to ${week[1]}`);
  for (const line of ['Healthy Weight registrations 1', 'All registrations 3', 'Monthly recurring revenue 9.99']) expect(lines).toContain(line);
  expect(lines).toContain('Visits and calculator use: Google Analytics is not connected');
  expect(lines).not.toContain('Visits and calculator use: Plausible is not connected');
  expect(lines.slice(lines.indexOf('Totals for the week'))).toEqual([
    'Totals for the week', 'Sign-ups 3', 'Upgrades 4', 'Renewals 3', 'Pauses started 2', 'Pauses requested 3', 'Cancellations 2',
    'Payments GBP 19.98 (2)', 'Payments USD 69.99 (1)', 'Refunds USD 9.99 (1)', 'Stripe paid invoices: Stripe is not connected',
    'Members 12', 'Free members 7', 'Premium members 5', 'Members on pause 4',
    'Protein calculator page views not connected', 'Protein calculator completions 3',
    'Diabetes Risk Check page views not connected', 'Diabetes Risk Check completions 0',
    'Healthy Weight Check page views not connected', 'Healthy Weight Check completions 1',
  ]);
  // Counts come from the report week only.
  const queries = JSON.parse(php(`${stubs()} LLD_Members_Stage3_Report::extend_text('x', [], ['${week[0]}', '${week[1]}'], null); echo json_encode($GLOBALS['wpdb']->queries);`));
  expect(queries.find((q) => q.includes('FROM wp_lld_order'))).toContain(`${week[0]} 00:00:00,${week[1]} 23:59:59`);
  expect(queries.find((q) => q.includes('starts_at BETWEEN'))).toContain(`${week[0]},${week[1]}`);
});

test('TC-FR3-70-AS-01 (Stripe): with the real Stripe connected, the week\'s paid invoices are summed per currency across pages', () => {
  const text = php(`${stubs()}
    class LLD_Members_Stripe_API { static $calls = []; static function on($m) { return true; }
      static function request($method, $path, $params) { self::$calls[] = $params;
        if (empty($params['starting_after'])) { return ['data' => [['id' => 'in_1', 'currency' => 'usd', 'amount_paid' => 999], ['id' => 'in_2', 'currency' => 'gbp', 'amount_paid' => 799]], 'has_more' => true]; }
        return ['data' => [['id' => 'in_3', 'currency' => 'usd', 'amount_paid' => 6999], ['id' => 'in_4', 'currency' => 'usd', 'amount_paid' => 0]], 'has_more' => false]; } }
    $t = LLD_Members_Stage3_Report::extend_text('x', [], ['${week[0]}', '${week[1]}'], null);
    echo json_encode(['text' => $t, 'calls' => LLD_Members_Stripe_API::$calls]);`);
  const out = JSON.parse(text);
  expect(out.text.split('\n')).toEqual(expect.arrayContaining(['Stripe paid invoices GBP 7.99 (1)', 'Stripe paid invoices USD 79.98 (2)']));
  expect(out.calls).toHaveLength(2);
  expect(out.calls[0]).toMatchObject({ status: 'paid', created: { gte: Date.UTC(2026, 9, 26) / 1000, lt: Date.UTC(2026, 10, 2) / 1000 } });
  expect(out.calls[1].starting_after).toBe('in_2');
});

test('TC-FR3-70-AS-01 (Google Analytics): visits and calculator page views come from GA totals through a read-only service account', () => {
  const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  const dir = mkdtempSync(join(tmpdir(), 'lld-ga-'));
  const keyFile = join(dir, 'sa.json');
  writeFileSync(keyFile, JSON.stringify({ client_email: 'report@example-project.iam.gserviceaccount.com', private_key: privateKey.export({ type: 'pkcs8', format: 'pem' }) }));
  const rows = [['/programmes/healthy-weight/', 4], ['/coming-soon/healthy-weight', 1], ['/health-tools/diabetes-risk-check/', 6], ['/health-tools/protein/', 3], ['/programmes/sleep-stress/', 2], ['/an-article/', 9]];
  const out = JSON.parse(php(`${stubs({ defines: `define('LLD_GA4_PROPERTY', '562156874'); define('LLD_GA4_KEY_FILE', ${toPhpPath(keyFile)});` })}
    $GLOBALS['answers']['oauth2.googleapis.com/token'] = ['code' => 200, 'body' => json_encode(['access_token' => implode('.', ['ya29', 'fake'])])];
    $GLOBALS['answers']['analyticsdata.googleapis.com'] = ['code' => 200, 'body' => json_encode(['rows' => array_map(function ($r) { return ['dimensionValues' => [['value' => $r[0]]], 'metricValues' => [['value' => (string) $r[1]]]]; }, json_decode(${JSON.stringify(JSON.stringify(rows))}, true))])];
    $ga = LLD_Members_Stage3_Report::ga_figures('${week[0]}', '${week[1]}');
    echo json_encode(['ga' => $ga, 'text' => LLD_Members_Stage3_Report::extend_text(${JSON.stringify(stage2Text)}, [], ['${week[0]}', '${week[1]}'], $ga), 'posts' => $GLOBALS['posts']]);`));
  expect(out.ga.total).toBe(25);
  const lines = out.text.split('\n');
  for (const line of ['Protein calculator page views 3', 'Diabetes Risk Check page views 6', 'Healthy Weight Check page views 0', 'Visits and calculator page views from Google Analytics', 'All page views 25', 'Healthy Weight visits 5', 'Blood Sugar Balance visits 6', 'Blood Sugar Balance calculator page views 6', 'Fitness and Strength visits 3', 'Fitness and Strength calculator page views 3', 'Sleep and Stress visits 2', 'Healthy Weight calculator page views 0']) expect(lines).toContain(line);
  // The token request is a JWT signed by the service account for the read-only scope, and the report asks for the week.
  const [token, report] = out.posts;
  expect(token.url).toBe('https://oauth2.googleapis.com/token');
  const [h, c, s] = token.args.body.assertion.split('.');
  const claims = JSON.parse(Buffer.from(c, 'base64url').toString());
  expect(claims).toMatchObject({ iss: 'report@example-project.iam.gserviceaccount.com', scope: 'https://www.googleapis.com/auth/analytics.readonly', aud: 'https://oauth2.googleapis.com/token' });
  expect(createVerify('RSA-SHA256').update(`${h}.${c}`).verify(publicKey, Buffer.from(s, 'base64url'))).toBe(true);
  expect(report.url).toBe('https://analyticsdata.googleapis.com/v1beta/properties/562156874:runReport');
  expect(report.args.headers.Authorization).toBe('Bearer ya29.fake');
  expect(JSON.parse(report.args.body).dateRanges).toEqual([{ startDate: week[0], endDate: week[1] }]);
  // Without the property or key file there is no request at all.
  const none = JSON.parse(php(`${stubs()} echo json_encode(['ga' => LLD_Members_Stage3_Report::ga_figures('a', 'b'), 'posts' => $GLOBALS['posts']]);`));
  expect(none).toEqual({ ga: null, posts: [] });
});

test('TC-FR3-70-AS-02 (figures): the Stage 3 part holds labels and counts only, and only the one report email is changed', () => {
  const out = JSON.parse(php(`${stubs()}
    $GLOBALS['wpdb']->orders = [(object) ['kind' => 'payment', 'charged_currency' => 'usd', 'n' => 1, 'total' => 999]];
    $other = LLD_Members_Stage3_Report::extend_mail(['to' => 'owner@example.test', 'subject' => 'Weekly report', 'message' => 'x']);
    $r = new ReflectionProperty('LLD_Members_Stage3_Report', 'week'); $r->setAccessible(true);
    $range = LLD_Members_Stage3_Report::report_week();
    $r->setValue(null, ['range' => $range, 'figures' => ['registrations' => 1, 'tracks' => []]]);
    $notReport = LLD_Members_Stage3_Report::extend_mail(['to' => 'someone@example.test', 'subject' => 'Your code', 'message' => 'Weekly report for ' . $range[0] . ' to ' . $range[1]]);
    $report = LLD_Members_Stage3_Report::extend_mail(['to' => 'owner@example.test', 'subject' => 'Weekly report', 'message' => 'Weekly report for ' . $range[0] . ' to ' . $range[1]]);
    $again = LLD_Members_Stage3_Report::extend_mail(['to' => 'owner@example.test', 'subject' => 'Weekly report', 'message' => 'Weekly report for ' . $range[0] . ' to ' . $range[1]]);
    echo json_encode(['other' => $other, 'not' => $notReport, 'report' => $report, 'again' => $again, 'updated' => $GLOBALS['wpdb']->updated, 'range' => $range]);`));
  expect(out.other.message).toBe('x');
  expect(out.not.message).not.toContain('Totals');
  expect(out.again.message).not.toContain('Totals');
  const lines = out.report.message.split('\n');
  const part = lines.slice(lines.indexOf('Totals for the week') + 1);
  expect(part.length).toBeGreaterThan(10);
  for (const line of part) expect(line).toMatch(/^[A-Za-z :-]+( [A-Z]{3} \d+\.\d{2} \(\d+\)| \d+| none| not connected|: Stripe is not connected)$/);
  // The kept copy of the week's report is the full text.
  expect(out.updated).toEqual([['wp_lld_weekly_report', { totals: out.report.message }, { week_starting: out.range[0] }]]);
});

test('TC-FR3-70-AS-01 (completions): each calculator result adds one to the weekly total of that calculator, and nothing else is kept', () => {
  const out = JSON.parse(php(`${stubs()}
    $r = [];
    $r[] = LLD_Members_Calculator_Count::add('protein', '2026-10-28');
    $r[] = LLD_Members_Calculator_Count::add('protein', '2026-11-01');
    $r[] = LLD_Members_Calculator_Count::add('healthy-weight', '2026-10-26');
    $r[] = LLD_Members_Calculator_Count::add('diabetes', '2026-11-02');
    $r[] = LLD_Members_Calculator_Count::add('bmi-31', '2026-10-28');
    $r[] = LLD_Members_Calculator_Count::add('', '2026-10-28');
    echo json_encode(['added' => $r, 'week' => LLD_Members_Calculator_Count::week('2026-10-26'), 'next' => LLD_Members_Calculator_Count::week('2026-11-02'), 'stored' => $GLOBALS['opts']['lld_calculator_counts']]);`));
  expect(out.added).toEqual([true, true, true, true, false, false]);
  expect(out.week).toEqual({ protein: 2, diabetes: 0, 'healthy-weight': 1 });
  expect(out.next).toEqual({ protein: 0, diabetes: 1, 'healthy-weight': 0 });
  // Stored: week start, calculator and a number. Nothing else.
  expect(out.stored).toEqual({ '2026-11-02': { diabetes: 1 }, '2026-10-26': { protein: 2, 'healthy-weight': 1 } });
  // Only 12 weeks are kept.
  const kept = JSON.parse(php(`${stubs()} for ($i = 0; $i < 15; $i++) { LLD_Members_Calculator_Count::add('protein', gmdate('Y-m-d', strtotime('2026-01-05 12:00 UTC +' . (7 * $i) . ' days'))); } echo json_encode(array_keys($GLOBALS['opts']['lld_calculator_counts']));`));
  expect(kept).toHaveLength(12);
  expect(kept[0]).toBe('2026-04-13');
  // Only the site's own pages count.
  const same = JSON.parse(php(`${stubs()} echo json_encode([
    LLD_Members_Calculator_Count::same_site('https://stg.example.test', ''),
    LLD_Members_Calculator_Count::same_site('', 'https://stg.example.test/health-tools/protein/'),
    LLD_Members_Calculator_Count::same_site('https://evil.example', 'https://stg.example.test/'),
    LLD_Members_Calculator_Count::same_site('http://stg.example.test', ''),
    LLD_Members_Calculator_Count::same_site('', ''),
  ]);`));
  expect(same).toEqual([true, true, false, false, false]);
});

test('TC-NFR3-16-AS-02 (completions): the page sends only the calculator name, without cookies, and never to Google', () => {
  const js = php(`${stubs()} echo LLD_Members_Calculator_Count::script('https://stg.example.test/wp-json/lld-members/v1/calculator-count');`);
  expect(js).toContain('body:JSON.stringify({calculator:which})');
  expect(js).toContain('credentials:"omit"');
  expect(js).toContain('if(e.origin!==location.origin)return;');
  expect(js).toContain('done[which]=1');
  for (const banned of ['headline', 'band', 'unit', 'gtag', 'dataLayer', 'google', 'cookie']) expect(js).not.toContain(banned);
});
