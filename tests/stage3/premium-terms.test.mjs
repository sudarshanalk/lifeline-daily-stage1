// P3-36 A1 (TC-FR3-64-AS-04): one answer to "is this member Premium, and why", used by the chip, Account → Membership
// and My subscription, so free Premium given by the Owner shows as Premium with its end date and no offer to buy.
// P3-36 A6 (TC-FR3-113-AS-01 to AS-04): a member who has not accepted the terms goes to Agreements before any member
// page, calculators, articles and Help stay open, and accepting returns the member to the page they were going to.
// Run in PHP CLI with stubbed WordPress and Stage 2 classes; the real Access, Membership and terms-gate classes.
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

// Member 5 throughout. $GLOBALS['sub'] is the stored subscription row, $GLOBALS['grant'] the Owner's running grant,
// $GLOBALS['open'] the Stage 2 "Premium open now" answer, $GLOBALS['pause'] a Stage 3 pause.
const stubs = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
class Redirected extends Exception {}
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } function esc_attr($s) { return esc_html($s); } function esc_url($s) { return (string) $s; }
function home_url($p = '') { return 'https://stg.example.test' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function add_query_arg($k, $v, $u) { return $u . (strpos($u, '?') === false ? '?' : '&') . $k . '=' . $v; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function wp_unslash($v) { return $v; } function nocache_headers() {}
function wp_safe_redirect($u) { throw new Redirected($u); }
function add_filter() {} function add_action() {} function apply_filters($h, $v) { return $v; }
function lld_button($a) { return '<a class="lld-button" href="' . ($a['href'] ?? '') . '">' . $a['label'] . '</a>'; }
$GLOBALS['logged_in'] = true; $GLOBALS['admin'] = false; $GLOBALS['screen'] = '';
function is_user_logged_in() { return $GLOBALS['logged_in']; } function current_user_can($c) { return $GLOBALS['admin']; }
function get_current_user_id() { return $GLOBALS['logged_in'] ? 9 : 0; } function get_query_var($k) { return $GLOBALS['screen']; }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { static $on = true; static function enabled($f, $m = 0) { return self::$on; } }
class LLD_Members_Billing { static function has_premium($m) { return $GLOBALS['open']; } static function shown_prices() { return ['monthly' => 999, 'yearly' => 6999]; } }
class LLD_Members_Pause { static function open($m) { return $GLOBALS['pause']; } static function day($d) { return gmdate('j F Y', strtotime($d . ' 00:00:00 UTC')); } }
class LLD_Members_Admin_Members { const NO_END = '2099-12-31'; static function active_grant($m) { return $GLOBALS['grant']; } }
class LLD_Members_Account { static $terms = false; static function has_consent($m, $t) { return 'terms' === $t && self::$terms; } }
class FakeDb {
  function prepare($q, ...$a) { return $q; }
  function get_var($q) { if (strpos($q, 'email_confirmed_at') !== false) { return $GLOBALS['confirmed']; } if (strpos($q, 'SELECT id FROM wp_lld_member') !== false) { return 5; } if (strpos($q, 'access_state FROM') !== false) { return $GLOBALS['sub'] ? $GLOBALS['sub']->access_state : null; } return 3; }
  function get_row($q) { return strpos($q, 'wp_lld_subscription') !== false ? $GLOBALS['sub'] : null; }
}
$GLOBALS['wpdb'] = new FakeDb();
$GLOBALS['open'] = false; $GLOBALS['grant'] = null; $GLOBALS['pause'] = null; $GLOBALS['sub'] = null; $GLOBALS['confirmed'] = '2026-11-01 10:00:00';
require ${toPhpPath(inc('class-access.php'))};
require ${toPhpPath(inc('stage3/class-membership.php'))};
require ${toPhpPath(inc('stage3/class-terms-gate.php'))};
function sub($state, $plan = 'monthly', $ends = '2027-03-10') { return (object) ['plan' => $plan, 'access_state' => $state, 'period_ends_on' => $ends, 'pending_yearly_on' => null, 'locked_amount' => null, 'locked_currency' => 'usd']; }
function grant($ends) { return (object) ['ends_at' => $ends . ' 00:00:00', 'starts_at' => '2026-11-01 00:00:00']; }
function sanitize_text_field($s) { return trim((string) $s); } function wp_verify_nonce($n, $a) { return 'good' === $n && 'lld_sign_out' === $a; }
// The screen WordPress would hand the dispatchers for this address: lld_screen in the query string wins (a public
// query var), then the plugin's rewrite rules, also under /index.php/ (path-info permalinks).
function screen_for($uri) {
  parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
  if (isset($q['lld_screen'])) { return (string) $q['lld_screen']; }
  $path = preg_replace('#^index\\.php/#', '', trim((string) parse_url($uri, PHP_URL_PATH), '/'));
  if (preg_match('#^members/admin(?:/[a-z-]+)?$#', $path)) { return 'admin3'; }
  if ('members/food/scan' === $path) { return 'food-scan'; }
  if (preg_match('#^members/(read|record|photo|diary-photo)/[0-9]+$#', $path, $m)) { return $m[1]; }
  if (preg_match('#^members/([a-z-]+)$#', $path, $m)) { return $m[1]; }
  if (in_array($path, ['pricing', 'programmes', 'safety'], true)) { return $path; }
  if (preg_match('#^programmes/[a-z-]+$#', $path)) { return 'sales'; }
  if (preg_match('#^health-tools/(protein|diabetes-risk-check|healthy-weight-check)$#', $path)) { return 'landing'; }
  if (preg_match('#^health-tools/[a-z-]+$#', $path)) { return 'calc3'; }
  return '';
}
function visit($method, $uri, $get = [], $post = []) {
  $_SERVER['REQUEST_METHOD'] = $method; $_SERVER['REQUEST_URI'] = $uri; $_GET = $get; $_POST = $post; $GLOBALS['screen'] = screen_for($uri);
  try { LLD_Members_Terms_Gate::gate(); } catch (Redirected $r) { return $r->getMessage(); } finally { $GLOBALS['screen'] = ''; $_POST = []; }
  return 'opens';
}
`;
const run = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 }).trim());

// ---- A1: one Premium status --------------------------------------------------------------------------------------

test('TC-FR3-64-AS-04', () => {
  const r = run(`
    $out = [];
    // Free Premium from the Owner, with no paid subscription behind it (the reported case: the stored state is Free).
    $GLOBALS['open'] = true; $GLOBALS['grant'] = grant('2026-12-14'); $GLOBALS['sub'] = sub('free');
    $out['grant'] = LLD_Members_Access::premium_status(5);
    $out['line'] = LLD_Members_Access::grant_line($out['grant']);
    $out['card'] = LLD_Members_Membership::card(5, $GLOBALS['sub']);
    // No subscription row at all: still the grant.
    $GLOBALS['sub'] = null;
    $out['no_row'] = LLD_Members_Access::premium_status(5)['source'];
    // A Premium test member's grant has no end date.
    $GLOBALS['grant'] = grant('2099-12-31');
    $out['no_end'] = LLD_Members_Access::grant_line(LLD_Members_Access::premium_status(5));
    // A paying member who also has a grant is a paying member.
    $GLOBALS['grant'] = grant('2026-12-14'); $GLOBALS['sub'] = sub('Premium');
    $out['paid_and_grant'] = LLD_Members_Access::premium_status(5);
    $GLOBALS['grant'] = null;
    $out['paid'] = LLD_Members_Access::premium_status(5);
    // Paused: still Premium (the chip has always said so), not open.
    $GLOBALS['open'] = false; $GLOBALS['pause'] = (object) ['status' => 'active', 'ends_at' => '2027-01-01'];
    $out['paused'] = LLD_Members_Access::premium_status(5);
    // Paused, but the paid Premium has since ended or been refunded: Free, as the membership card says.
    $GLOBALS['sub'] = sub('premium ended');
    $out['paused_ended'] = LLD_Members_Access::premium_status(5);
    $GLOBALS['sub'] = sub('refunded');
    $out['paused_refunded'] = LLD_Members_Access::premium_status(5)['premium'];
    // Free: no grant, nothing open.
    $GLOBALS['pause'] = null; $GLOBALS['sub'] = sub('free');
    $out['free'] = LLD_Members_Access::premium_status(5);
    $out['free_card'] = LLD_Members_Membership::card(5, $GLOBALS['sub']);
    return $out;
  `);
  expect(r.grant).toEqual({ premium: true, source: 'grant', until: '2026-12-14' });
  expect(r.line).toBe('Your membership is Premium, given free until 14 December 2026.');
  expect(r.card).toContain('data-lld-membership="grant"');
  expect(r.card).toContain('Your membership is Premium, given free until 14 December 2026.');
  expect(r.card).not.toMatch(/Go Premium|See Premium|a month|a year|Manage my plan/);
  // The same date the Owner chose and Admin shows for this grant ("Until 2026-12-14", "Free Premium given until
  // 2026-12-14"): both read the grant's stored end date, so the member and the Owner never see different days.
  expect(src('stage3/admin/class-admin-front.php')).toContain("'<p>Until ' . esc_html( substr( (string) $grant->ends_at, 0, 10 ) ) . '</p>'");
  expect(src('class-access.php')).toContain("$until = substr( (string) $grant->ends_at, 0, 10 );");
  expect(r.no_row).toBe('grant');
  expect(r.no_end).toBe('Your membership is Premium, given free with no end date.');
  expect(r.paid_and_grant).toEqual({ premium: true, source: 'paid', until: '' });
  expect(r.paid).toEqual({ premium: true, source: 'paid', until: '' });
  expect(r.paused).toEqual({ premium: true, source: 'paused', until: '' });
  expect(r.paused_ended).toEqual({ premium: false, source: 'free', until: '' });
  expect(r.paused_refunded).toBe(false);
  expect(r.free).toEqual({ premium: false, source: 'free', until: '' });
  expect(r.free_card).toContain('Go Premium');

  // My subscription (Stage 2 page): the grant line in place of "Your membership is Free" and "See pricing", and no
  // Cancel or Switch to yearly, since nothing is paid; every other member's page is as before.
  const billing = src('class-billing.php');
  const page = billing.slice(billing.indexOf("$main = '<h1>My subscription</h1>';"), billing.indexOf("LLD_Members_Personal::render( 'My subscription', $main );"));
  expect(page).toContain("$status = LLD_Members_Access::premium_status( $member_id );");
  expect(page.indexOf("if ( 'grant' === $status['source'] ) {")).toBeLessThan(page.indexOf("$main .= '<p>Your membership is Free.</p>';"));
  expect(page).toContain("LLD_Members_Access::grant_line( $status )");
  // Said once: the Stage 3 card carries the line when it is on, the Stage 2 paragraph only when it is off.
  expect(page).toContain("if ( ! ( class_exists( 'LLD_Members_Features' ) && LLD_Members_Features::enabled( 'membership_card', $member_id ) ) ) {");
  expect(page).toContain("if ( 'grant' !== $status['source'] && self::paid_state( $member_id )");
  expect(page).toContain("if ( 'grant' !== $status['source'] && $switching && $sub");
  // The chip reads the same helper; Account → Membership reads has_premium() and the filtered access state, which a
  // grant already makes Premium (LLD_Members_Admin_Members::grant_open, grant_state).
  const chip = src('components.php');
  const body = chip.slice(chip.indexOf('function lld_members_chip('), chip.indexOf('$initial', chip.indexOf('function lld_members_chip(')));
  expect(body).toContain("$status  = LLD_Members_Access::premium_status( $member_id );");
  expect(body).toContain("$premium = $status['premium'];");
  expect(src('stage3/admin/class-admin-members.php')).toContain("add_filter( 'lld_members_access_state', array( __CLASS__, 'grant_state' ), 10, 2 );");
  // Account → Membership: "Premium" with the grant line and its end date, and no pause box for a grant.
  const hub = src('stage3/screens/class-account-hub.php');
  const membership = hub.slice(hub.indexOf('private static function section_membership('), hub.indexOf('public static function empty_state('));
  expect(membership).toContain("$grant   = $status && 'grant' === $status['source'];");
  expect(membership).toContain("esc_html( $grant ? 'Premium' : $state )");
  expect(membership).toContain("esc_html( LLD_Members_Access::grant_line( $status ) )");
  expect(membership).toContain('if ( $is_prem && ! $grant ) {');
});

// ---- A6: terms before the member area ------------------------------------------------------------------------------

test('TC-FR3-113-AS-01', () => {
  const r = run(`
    return [
      'today' => visit('GET', '/members/home/'),
      'members' => visit('GET', '/members/'),
      'accepted' => (function () { LLD_Members_Account::$terms = true; $v = visit('GET', '/members/home/'); LLD_Members_Account::$terms = false; return $v; })(),
      // Unconfirmed: Stage 2 sends the member to the email step first; the gate stays out of the way.
      'unconfirmed' => (function () { $GLOBALS['confirmed'] = null; $v = visit('GET', '/members/home/'); $GLOBALS['confirmed'] = '2026-11-01 10:00:00'; return $v; })(),
      // The owner and helpers run the site; the switch off leaves Stage 2 exactly as it was.
      'owner' => (function () { $GLOBALS['admin'] = true; $v = visit('GET', '/members/home/'); $GLOBALS['admin'] = false; return $v; })(),
      'switch_off' => (function () { LLD_Members_Features::$on = false; $v = visit('GET', '/members/home/'); LLD_Members_Features::$on = true; return $v; })(),
      'visitor' => (function () { $GLOBALS['logged_in'] = false; $v = visit('GET', '/members/home/'); $GLOBALS['logged_in'] = true; return $v; })(),
    ];
  `);
  expect(r.today).toBe('https://stg.example.test/members/agreements/?redirect_to=%2Fmembers%2Fhome%2F');
  expect(r.members).toBe('https://stg.example.test/members/agreements/?redirect_to=%2Fmembers%2F');
  expect(r.accepted).toBe('opens');
  expect(r.unconfirmed).toBe('opens');
  expect(r.owner).toBe('opens');
  expect(r.switch_off).toBe('opens');
  expect(r.visitor).toBe('opens');
  // Before the screens are dispatched, so no member page is built first.
  const gate = src('stage3/class-terms-gate.php');
  expect(gate).toContain("add_action( 'template_redirect', array( __CLASS__, 'gate' ), 1 );");
  expect(src('class-plugin.php')).toContain("add_action( 'template_redirect', array( 'LLD_Members_Screens', 'dispatch' ) );");
  expect(src('stage3/loader.php')).toContain('LLD_Members_Terms_Gate::boot();');
});

test('TC-FR3-113-AS-02', () => {
  const r = run(`return [
    'tracks' => visit('GET', '/members/tracks/'),
    'start' => visit('POST', '/members/tracks/'),
    'track' => visit('GET', '/members/track/?week=2'),
    'goal' => visit('GET', '/members/goal/'),
    'settings' => visit('GET', '/members/settings/?section=membership'),
  ];`);
  expect(r.tracks).toBe('https://stg.example.test/members/agreements/?redirect_to=%2Fmembers%2Ftracks%2F');
  // Starting a track is a POST to the same page: refused the same way, before the screen runs.
  expect(r.start).toBe(r.tracks);
  expect(r.track).toBe('https://stg.example.test/members/agreements/?redirect_to=%2Fmembers%2Ftrack%2F%3Fweek%3D2');
  expect(r.goal).toContain('/members/agreements/?redirect_to=');
  expect(r.settings).toContain('/members/agreements/?redirect_to=');

  // The screens are dispatched on the public lld_screen query var, so the gate decides from it, not from the
  // address: neither /?lld_screen=tracks nor /index.php/members/tracks/ reaches the screen, opening or starting.
  const by = run(`return [
    'query' => visit('GET', '/?lld_screen=tracks'),
    'query_start' => visit('POST', '/?lld_screen=tracks'),
    'query_on_public' => visit('GET', '/pricing/?lld_screen=tracks'),
    'query_on_open' => visit('GET', '/members/help/?lld_screen=tracks'),
    'path_info' => visit('GET', '/index.php/members/tracks/'),
    'path_info_start' => visit('POST', '/index.php/members/tracks/'),
    'path_info_goal' => visit('GET', '/index.php/members/goal/'),
    // A screen added later is a member page until it is listed as public.
    'new_screen' => visit('GET', '/?lld_screen=something-new'),
    // The other way round: a public screen asked for on a member address is the public page the dispatcher shows.
    'public_on_member' => visit('GET', '/members/tracks/?lld_screen=pricing'),
    // The More list is a member page; only its sign-out form (a POST with the sign-out nonce) is open.
    'more' => visit('GET', '/members/more/'),
    'more_query' => visit('GET', '/?lld_screen=more'),
    'more_post' => visit('POST', '/members/more/'),
    'more_bad_nonce' => visit('POST', '/members/more/', [], ['lld_sign_out_nonce' => 'bad']),
    'sign_out' => visit('POST', '/members/more/', [], ['lld_sign_out_nonce' => 'good']),
  ];`);
  const agreements = 'https://stg.example.test/members/agreements/';
  // No /members/ address to return to, so the member goes on from Agreements as a new member does.
  for (const k of ['query', 'query_start', 'query_on_public', 'path_info', 'path_info_start', 'path_info_goal', 'new_screen', 'more_query']) expect(by[k], k).toBe(agreements);
  expect(by.query_on_open).toBe(agreements);
  expect(by.public_on_member).toBe('opens');
  expect(by.more).toBe(`${agreements}?redirect_to=%2Fmembers%2Fmore%2F`);
  expect(by.more_post).toBe(by.more);
  expect(by.more_bad_nonce).toBe(by.more);
  expect(by.sign_out).toBe('opens');

  // Every screen the plugin registers outside /members/ is listed as public, and nothing under /members/ is: a
  // member screen can never be opened by being left off a list. The gate reads the same query var as the dispatchers.
  const gate = src('stage3/class-terms-gate.php');
  const list = (name) => [...gate.match(new RegExp(`const ${name}\\s*=\\s*array\\(([^)]*)\\)`))[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
  const open = list('OPEN_SCREENS');
  const pub = list('PUBLIC_SCREENS');
  const rules = ['class-plugin.php', 'stage3/class-goal-journey.php', 'stage3/screens/class-barcode-scan.php']
    .flatMap((f) => [...src(f).matchAll(/add_rewrite_rule\(\s*'\^([^']+)',\s*'index\.php\?lld_screen=([a-z0-9-]+|\$matches\[1\])/g)])
    .flatMap((m) => (m[2].startsWith('$') ? m[1].match(/\(([^)]+)\)/)[1].split('|') : [m[2]]).map((screen) => ({ member: m[1].startsWith('members/'), screen })));
  expect(rules.length).toBeGreaterThan(50);
  for (const r of rules) {
    if (r.member) expect(pub, r.screen).not.toContain(r.screen);
    else expect(pub, r.screen).toContain(r.screen);
  }
  // The two screens whose rules are built from constants: the calculators (public) and My account → Admin (open).
  expect(src('stage3/screens/class-calculator-pages.php')).toMatch(/const SCREEN\s*=\s*'calc3';/);
  expect(src('stage3/admin/class-admin-front.php')).toMatch(/const SCREEN\s*=\s*'admin3';/);
  // The open list is exactly this: adding a screen to it later fails here until it is looked at.
  expect([...open].sort()).toEqual(['admin3', 'agreements', 'confirm', 'data', 'delete', 'email', 'help', 'join', 'reset', 'sign-in', 'two-step', 'unsubscribe', 'withdraw']);
  expect(pub).toContain('calc3');
  expect(open).toContain('admin3');
  for (const s of ['home', 'tracks', 'track', 'goal', 'goals', 'settings', 'health', 'diary', 'more', 'subscription', 'checkout', 'food-scan', 'progress']) {
    expect(open, s).not.toContain(s);
    expect(pub, s).not.toContain(s);
  }
  expect(gate).toContain("$screen = get_query_var( 'lld_screen' );");
  expect(src('class-screens.php')).toContain("$screen = get_query_var( 'lld_screen' );");
});

test('TC-FR3-113-AS-03', () => {
  const r = run(`
    $out = [];
    foreach (['/health-tools/', '/health-tools/protein/', '/tools/protein-requirement-tool.html', '/articles/', '/some-article/', '/members/help/', '/pricing/',
      '/members/agreements/', '/members/sign-in/', '/members/confirm/', '/members/data/', '/members/delete/', '/members/withdraw/', '/members/unsubscribe/', '/members/admin/'] as $p) {
      $out[$p] = visit('GET', $p);
    }
    foreach (['/health-tools/calorie-target/', '/programmes/', '/programmes/healthy-weight/', '/safety/', '/index.php/members/help/', '/?lld_screen=help'] as $p) {
      $out[$p] = visit('GET', $p);
    }
    // A member can always sign out: the sign-out form posts to the More screen with its nonce.
    $out['sign_out'] = visit('POST', '/members/more/', [], ['lld_sign_out_nonce' => 'good']);
    return $out;
  `);
  for (const [path, result] of Object.entries(r)) expect(result, path).toBe('opens');
});

test('TC-FR3-113-AS-04', () => {
  const r = run(`
    $after = function ($target) { $_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['screen'] = 'agreements'; $_GET = null === $target ? [] : ['redirect_to' => $target]; return LLD_Members_Terms_Gate::after_accepting(home_url('/members/goals/')); };
    $out = [];
    // Not yet accepted (the form came back with an error): Stage 2's redirect is left alone.
    $out['not_accepted'] = $after('/members/tracks/');
    LLD_Members_Account::$terms = true;
    $out['tracks'] = $after('/members/tracks/');
    $out['query'] = $after('/members/track/?week=2');
    // A new member from sign-up carries nothing, so goes on to the goal step as in Stage 2 (FR2-03 AS-01).
    $out['new_member'] = $after(null);
    // Never off the site or to a page the gate does not cover.
    $out['other_site'] = $after('https://example.com/members/home/');
    $out['protocol_relative'] = $after('//example.com/members/home/');
    $out['public'] = $after('/pricing/');
    $out['backslash'] = $after('/members/\\\\example.com');
    // No '..' part, plain or encoded, so the address cannot climb out of the member area.
    $out['dots'] = $after('/members/../wp-admin/');
    $out['dots_deep'] = $after('/members/tracks/../../wp-login.php');
    $out['dots_encoded'] = $after('/members/%2e%2e/pricing/');
    $out['backslash_encoded'] = $after('/members/..%5c..%5cwp-admin/');
    $out['dots_in_name'] =$after('/members/track/?note=a..b');
    // Only the "accepted" redirect to the goal step is changed.
    $out['other_redirect'] = LLD_Members_Terms_Gate::after_accepting(home_url('/members/confirm/'));
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $out['get'] = LLD_Members_Terms_Gate::after_accepting(home_url('/members/goals/'));
    return $out;
  `);
  const goals = 'https://stg.example.test/members/goals/';
  expect(r.not_accepted).toBe(goals);
  expect(r.tracks).toBe('https://stg.example.test/members/tracks/');
  expect(r.query).toBe('https://stg.example.test/members/track/?week=2');
  expect(r.new_member).toBe(goals);
  expect(r.other_site).toBe(goals);
  expect(r.protocol_relative).toBe(goals);
  expect(r.public).toBe(goals);
  expect(r.backslash).toBe(goals);
  expect(r.dots).toBe(goals);
  expect(r.dots_deep).toBe(goals);
  expect(r.dots_encoded).toBe(goals);
  expect(r.backslash_encoded).toBe(goals);
  expect(r.dots_in_name).toBe('https://stg.example.test/members/track/?note=a..b');
  expect(r.other_redirect).toBe('https://stg.example.test/members/confirm/');
  expect(r.get).toBe(goals);
  // Stage 2's Agreements form posts back to its own address (no action), so the page to return to travels with it,
  // and the accepted step still redirects to the goal step, which the filter changes.
  const account = src('class-account.php');
  const agreements = account.slice(account.indexOf('private static function agreements() {'), account.indexOf('private static function goals() {'));
  expect(agreements).toContain("$main .= '<form method=\"post\" class=\"lld-form\">';");
  expect(agreements).toContain("wp_safe_redirect( home_url( '/members/goals/' ) );");
});

// The Agreements step itself (Stage 2's own function, run as it is): a member the gate sends there who already gave
// health or marketing consent keeps it. The boxes arrive ticked as the consents stand, so accepting the terms sends
// them back and nothing is recorded as withdrawn; a new member's form and records are exactly Stage 2's.
test('TC-FR3-113-AS-04: accepting the terms keeps the consents a member already gave', () => {
  const account = src('class-account.php');
  const fn = account.slice(account.indexOf('private static function agreements() {'), account.indexOf('private static function goals() {')).replace('private static function agreements()', 'public static function agreements()');
  const php = `
define('ABSPATH', '/');
class Redirected extends Exception {}
function home_url($p = '') { return 'https://stg.example.test' . $p; } function esc_url($s) { return (string) $s; }
function wp_unslash($v) { return $v; } function sanitize_text_field($s) { return trim((string) $s); }
function wp_verify_nonce($n, $a) { return 'n' === $n && 'lld_agree' === $a; } function wp_nonce_field() { return '<input type="hidden" name="lld_agree_nonce" value="n">'; }
function wp_safe_redirect($u) { throw new Redirected($u); }
function lld_steps() { return ''; } function lld_button($a) { return '<button>' . $a['label'] . '</button>'; }
class LLD_Members_Access { static function current_member_id() { return 5; } }
class Agreements {
  const TERMS = 't1'; const PRIVACY = 'p1';
  public static $consents = []; public static $added = []; public static $page = '';
  static function require_confirmed() {} static function error_box($e) { return $e ? '<p class="error">' . $e . '</p>' : ''; }
  static function layout($t, $main, $in) { self::$page = $main; }
  static function has_consent($m, $type) { return 'given' === (self::$consents[$type] ?? ''); }
  static function add_consent($m, $type, $kind, $v) { self::$consents[$type] = $kind; self::$added[] = $type . ':' . $kind; }
  ${fn}
}
// Opens the form, then posts it back as a browser would: every ticked box, plus the terms box the member ticks.
function accept($consents) {
  Agreements::$consents = $consents; Agreements::$added = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
  Agreements::agreements(); $form = Agreements::$page;
  preg_match_all('/<input type="checkbox" name="([a-z_]+)" value="1"( checked)?>/', $form, $m, PREG_SET_ORDER);
  $post = ['lld_agree_nonce' => 'n', 'lld_terms' => '1']; $ticked = [];
  foreach ($m as $box) { if (!empty($box[2])) { $post[$box[1]] = '1'; $ticked[] = $box[1]; } }
  $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; $to = '';
  try { Agreements::agreements(); } catch (Redirected $r) { $to = $r->getMessage(); }
  return ['boxes' => count($m), 'ticked' => $ticked, 'added' => Agreements::$added, 'now' => Agreements::$consents, 'to' => $to];
}
echo json_encode([
  'new' => accept([]),
  'both' => accept(['health' => 'given', 'marketing' => 'given']),
  'health' => accept(['health' => 'given', 'marketing' => 'withdrawn']),
  'marketing' => accept(['marketing' => 'given']),
]);`;
  const r = JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', php], { encoding: 'utf8', timeout: 120000 }).trim());
  // A new member: nothing ticked, and what Stage 2 records is unchanged (FR2-03; Screen Design principle 8).
  expect(r.new.boxes).toBe(3);
  expect(r.new.ticked).toEqual([]);
  expect(r.new.added).toEqual(['terms:given', 'privacy:given', 'health:withdrawn', 'marketing:withdrawn']);
  expect(r.new.to).toBe('https://stg.example.test/members/goals/');
  // An existing member with both consents: both boxes ticked as they stand, and both still given after accepting.
  expect(r.both.ticked).toEqual(['lld_health', 'lld_marketing']);
  expect(r.both.added).toEqual(['terms:given', 'privacy:given', 'health:given', 'marketing:given']);
  expect(r.both.now).toMatchObject({ terms: 'given', health: 'given', marketing: 'given' });
  // Each box follows its own consent; a withdrawn or never-given one is not ticked. The terms box is never ticked.
  expect(r.health.ticked).toEqual(['lld_health']);
  expect(r.health.now).toMatchObject({ health: 'given', marketing: 'withdrawn' });
  expect(r.marketing.ticked).toEqual(['lld_marketing']);
  expect(r.marketing.now).toMatchObject({ health: 'withdrawn', marketing: 'given' });
});
