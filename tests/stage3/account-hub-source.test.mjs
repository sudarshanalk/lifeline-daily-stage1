// P3-30 account hub review fixes: Stage 2 is untouched, the hub is switch-gated at template_redirect, consent and
// sign-in changes go through the Stage 2 paths, and members never see a requirement or test ID.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = 'site/public_html/wp-content/plugins/lld-members';
const hubPath = resolve(root, plugin, 'includes/stage3/screens/class-account-hub.php');
const jsPath = resolve(root, plugin, 'assets/stage3.js');
const hub = readFileSync(hubPath, 'utf8');
const js = readFileSync(jsPath, 'utf8');
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim();
const p = (x) => JSON.stringify(x.split('\\').join('/'));
const ID = /\b(?:TC-)?N?FR\d-\d\d(?:-AS-\d\d)?\b/;

// Git blob ids of the two Stage 2 files at 9bb6218, the staging commit before #169.
const STAGE2 = {
  'includes/class-plugin.php': 'd097ac698e7a04fba583fd3e195f12ab39f34fe8',
  'includes/class-screens.php': 'b9bd8b63be53c546a5eb66e146d5236d28d3ed74',
};
const blob = (buf) => createHash('sha1').update(Buffer.concat([Buffer.from(`blob ${buf.length}\0`), buf])).digest('hex');

test('account hub: the Stage 2 class-plugin.php and class-screens.php are byte-identical to 9bb6218', () => {
  for (const [file, id] of Object.entries(STAGE2)) {
    expect(blob(readFileSync(resolve(root, plugin, file))), file).toBe(id);
  }
  expect(readFileSync(resolve(root, plugin, 'includes/class-plugin.php'), 'utf8')).not.toMatch(/members\/account/);
});

test('account hub: boot() hooks template_redirect at priority 5 only when the switch could apply, and checks enabled( \'account_hub\' )', () => {
  expect(hub).toMatch(/public static function boot\(\)/);
  expect(hub).toMatch(/add_action\( 'template_redirect', array\( __CLASS__, 'maybe_render' \), 5 \)/);
  expect(hub).toMatch(/LLD_Members_Features::enabled\( 'account_hub', \$member_id \)/);
  expect(hub).not.toMatch(/add_rewrite_rule/);
  const run = (state) => JSON.parse(php(`define('ABSPATH', '/'); $GLOBALS['hooks'] = [];
    class LLD_Members_Features { static function state($f) { return ${JSON.stringify(state)}; } }
    function add_action($h, $cb, $prio = 10) { $GLOBALS['hooks'][] = [$h, $prio]; }
    require ${p(hubPath)}; LLD_Members_Account_Hub::boot(); echo json_encode($GLOBALS['hooks']);`));
  expect(run('off')).toEqual([]);
  expect(run('test_accounts')).toEqual([['template_redirect', 5]]);
  expect(run('everyone')).toEqual([['template_redirect', 5]]);
  // Any screen other than settings is left to Stage 2 without output.
  expect(php(`define('ABSPATH', '/'); function get_query_var($v) { return 'home'; }
    require ${p(hubPath)}; LLD_Members_Account_Hub::maybe_render(); echo 'returned';`)).toBe('returned');
});

test('account hub: assets are enqueued through WordPress, never hand-written tags', () => {
  expect(hub).toMatch(/wp_enqueue_style\(/);
  expect(hub).toMatch(/wp_enqueue_script\(/);
  expect(hub).not.toMatch(/<link\b/);
  expect(hub).not.toMatch(/<script\b/);
});

test('account hub: password and two-step go through Account helpers; health consent and sign-out stay Stage 2', () => {
  // P3-36 C2: Profile, security, notifications and marketing save here via LLD_Members_Account::save_hub_*.
  // The hub itself never writes the password, two-step flag or consent rows.
  expect(hub).not.toMatch(/wp_set_password|wp_set_auth_cookie/);
  expect(hub).not.toMatch(/'two_step'\s*=>/);
  expect(hub).not.toMatch(/\$wpdb->insert|\bINSERT\b/i);
  expect(hub).toMatch(/LLD_Members_Account::save_hub_profile/);
  expect(hub).toMatch(/LLD_Members_Account::save_hub_security/);
  expect(hub).toMatch(/LLD_Members_Account::save_hub_notifications/);
  expect(hub).toMatch(/LLD_Members_Account::save_hub_marketing/);
  expect(hub).toMatch(/lld_security_nonce|lld_privacy_nonce|lld_notifications_nonce|lld_profile_nonce/);
  expect(hub).not.toMatch(/lld_members_withdraw_health/);
  expect(hub).toMatch(/home_url\( '\/members\/withdraw\/' \)/);
  // Sign out is a POST form to the Stage 2 sign-out (the More screen) with its nonce, never a logout link.
  expect(hub).toMatch(/<form method="post" action="' \. esc_url\( home_url\( '\/members\/more\/' \) \) \. '" id="lld-hub-sign-out"/);
  expect(hub).toMatch(/wp_nonce_field\( 'lld_sign_out', 'lld_sign_out_nonce', true, false \)/);
  expect(hub).toMatch(/data-lld-sign-out="lld-hub-sign-out"/);
  expect(js).toMatch(/signOut\.submit\(\)/);
  for (const src of [hub, js]) {
    expect(src).not.toMatch(/action=sign_out/);
    expect(src).not.toMatch(/wp_logout_url|wp-login\.php\?action=logout/);
  }
  // No fixed price is shown: prices come from Stage 2 billing, not the hub.
  expect(hub).not.toMatch(/US\$\d/);
  // Pause still has no save; the old placeholder notif nonce names stay unused.
  expect(hub).not.toMatch(/lld_pause_nonce|lld_notif_nonce/);
});

test('account hub: no requirement or test ID in any member-facing string', () => {
  const strings = JSON.parse(php(`$out = []; foreach (token_get_all(file_get_contents(${p(hubPath)})) as $t) {
    if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $out[] = $t[1]; } }
    echo json_encode($out);`));
  expect(strings.length).toBeGreaterThan(50);
  expect(strings.filter((s) => ID.test(s))).toEqual([]);
  const jsCode = js.replace(/\/\*[\s\S]*?\*\//g, '').split('\n').filter((l) => !l.trim().startsWith('//')).join('\n');
  const jsStrings = jsCode.match(/'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"/g) || [];
  expect(jsStrings.filter((s) => ID.test(s))).toEqual([]);
});

test('account hub: the blood pressure empty state is the owner-approved ST-HL-02 wording', () => {
  const html = php(`define('ABSPATH', '/');
    function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); } function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function esc_url($s) { return $s; } function home_url($p = '') { return 'https://example.test' . $p; }
    require ${p(hubPath)}; echo LLD_Members_Account_Hub::empty_state('blood_pressure');`);
  expect(html).toContain('<h2>No readings yet</h2>');
  expect(html).toContain('A good reading takes a few minutes of preparation. Sit quietly for 5 minutes, feet flat, back supported, arm resting at heart level. Don\'t talk during the reading. Take two readings a minute apart and record the second. Try the same time each day.');
  expect(html).toMatch(/>Record my first reading<\/a>/);
  expect(html).toMatch(/>How to measure blood pressure at home<\/a>/);
  expect(html).not.toMatch(ID);
});
