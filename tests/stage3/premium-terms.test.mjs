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
function visit($method, $uri, $get = []) {
  $_SERVER['REQUEST_METHOD'] = $method; $_SERVER['REQUEST_URI'] = $uri; $_GET = $get;
  try { LLD_Members_Terms_Gate::gate(); } catch (Redirected $r) { return $r->getMessage(); }
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
});

test('TC-FR3-113-AS-03', () => {
  const r = run(`
    $out = [];
    foreach (['/health-tools/', '/health-tools/protein/', '/tools/protein-requirement-tool.html', '/articles/', '/some-article/', '/members/help/', '/pricing/',
      '/members/agreements/', '/members/sign-in/', '/members/confirm/', '/members/more/', '/members/data/', '/members/delete/', '/members/withdraw/', '/members/unsubscribe/', '/members/admin/'] as $p) {
      $out[$p] = visit('GET', $p);
    }
    $out['sign_out'] = visit('POST', '/members/more/');
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
  expect(r.other_redirect).toBe('https://stg.example.test/members/confirm/');
  expect(r.get).toBe(goals);
  // Stage 2's Agreements form posts back to its own address (no action), so the page to return to travels with it,
  // and the accepted step still redirects to the goal step, which the filter changes.
  const account = src('class-account.php');
  const agreements = account.slice(account.indexOf('private static function agreements() {'), account.indexOf('private static function goals() {'));
  expect(agreements).toContain("$main .= '<form method=\"post\" class=\"lld-form\">';");
  expect(agreements).toContain("wp_safe_redirect( home_url( '/members/goals/' ) );");
});
