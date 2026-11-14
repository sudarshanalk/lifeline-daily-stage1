// P3-36 C1 (FR3-07): the account menu with Sign out on every member and Admin page, without WordPress.
// The member layout (components.php) is run in PHP CLI with small WordPress stand-ins, in the plugin's own header and in
// the site's v2 header (lld-tools); stage3.js is run in jsdom against the markup the layout prints.
// The live checks are tests/stage3/e2e/screens/account-menu.spec.mjs.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const componentsPath = resolve(plugin, 'includes/components.php');
const headerPath = resolve(root, 'site/public_html/wp-content/mu-plugins/lld-tools/templates/parts/v2-header.php');
const js = readFileSync(resolve(plugin, 'assets/stage3.js'), 'utf8');
const signIn = readFileSync(resolve(plugin, 'includes/stage3/signin/class-sign-in.php'), 'utf8');
const adminFront = readFileSync(resolve(plugin, 'includes/stage3/admin/class-admin-front.php'), 'utf8');
const growth = readFileSync(resolve(plugin, 'includes/class-growth.php'), 'utf8');
const p = (x) => JSON.stringify(x.split('\\').join('/'));

// Renders lld_members_layout() for one page. who: 'member' (member 7, Alex), 'helper' (signed in, no member record),
// 'visitor'. v2: the site's v2 shell around the page, with the real v2 header template.
function layout({ who = 'member', v2 = false, screen = 'home', account = false, admin = false } = {}) {
  const code = `
    define('ABSPATH', '/'); define('LLD_MEMBERS_FILE', '/plugin/lld-members.php'); define('LLD_MEMBERS_VERSION', '9');
    define('WP_CONTENT_DIR', '/nowhere');
    $GLOBALS['f'] = [];
    function add_filter($h, $cb) { $GLOBALS['f'][$h][] = $cb; }
    function remove_filter($h, $cb) { foreach ($GLOBALS['f'][$h] ?? [] as $i => $c) { if ($c === $cb) unset($GLOBALS['f'][$h][$i]); } }
    function apply_filters($h, $v) { foreach ($GLOBALS['f'][$h] ?? [] as $cb) { $v = $cb($v); } return $v; }
    function do_action($h) {}
    function status_header($s) {} function nocache_headers() {}
    function get_query_var($v) { return ${JSON.stringify(screen)}; }
    function plugins_url($path, $file = '') { return 'https://example.test/wp-content/plugins/lld-members/' . $path; }
    function content_url($p = '') { return 'https://example.test/wp-content/' . $p; }
    function home_url($p = '') { return 'https://example.test' . $p; }
    function esc_url($s) { return $s; } function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } function untrailingslashit($s) { return rtrim($s, '/'); }
    function wp_unslash($s) { return $s; } function wp_json_encode($v) { return json_encode($v); }
    function wp_create_nonce($a) { return 'abc123'; }
    function is_user_logged_in() { return ${who === 'visitor' ? 'false' : 'true'}; }
    function current_user_can($c) { return ${admin ? 'true' : 'false'}; }
    function wp_get_current_user() { return (object) ['display_name' => 'Sam Helper', 'user_login' => 'sam']; }
    $GLOBALS['printed'] = [];
    function wp_register_style($h, $src) { $GLOBALS['src'][$h] = $src; }
    function wp_register_script($h, $src) { $GLOBALS['src'][$h] = $src; }
    function wp_print_styles($hs) { foreach ($hs as $h) { if (empty($GLOBALS['printed'][$h . 'css'])) { $GLOBALS['printed'][$h . 'css'] = 1; echo '<link rel="stylesheet" id="' . $h . '-css" href="' . $GLOBALS['src'][$h] . '">'; } } }
    function wp_print_scripts($hs) { foreach ($hs as $h) { if (empty($GLOBALS['printed'][$h])) { $GLOBALS['printed'][$h] = 1; echo '<script id="' . $h . '-js" src="' . $GLOBALS['src'][$h] . '"></script>'; } } }
    class LLD_Members_Access { static function current_member_id() { return ${who === 'member' ? 7 : 0}; } static function access_state($id) { return 'Free'; } }
    class LLD_Members_Schema { static function table($t) { return 'wp_lld_' . $t; } }
    class FakeDb { function prepare($q, ...$a) { return $q; } function get_row($q) { return (object) ['first_name' => 'Alex', 'display_name' => 'Alex A']; } }
    $GLOBALS['wpdb'] = new FakeDb();
    ${v2 ? `function lld_v2_members_shell_on() { return true; }
    function lld_v2_members_shell($part) { if ($part !== 'header') return ''; $lld_header = ['page' => 'page', 'current' => '']; $hu = null; ob_start(); include ${p(headerPath)}; return (string) ob_get_clean(); }` : ''}
    require ${p(componentsPath)};
    ob_start(); lld_members_layout(['title' => 'T', 'main' => '<h1>Page</h1>', 'signed_in' => ${who === 'member' ? 'true' : 'false'}${account ? ", 'account' => true" : ''}]);
    echo ob_get_clean();`;
  return execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 });
}

// Runs stage3.js in jsdom on a page; width: the window's innerWidth.
function browse(html, width = 1280) {
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://example.test/members/home/' });
  Object.defineProperty(dom.window, 'innerWidth', { value: width, configurable: true });
  // the page has loaded (stage3.js runs at the foot of the page)
  Object.defineProperty(dom.window.document, 'readyState', { value: 'complete', configurable: true });
  const submitted = [];
  dom.window.HTMLFormElement.prototype.submit = function () { submitted.push(this.id); };
  dom.window.eval(js);
  return { dom, doc: dom.window.document, submitted };
}

const key = (dom, el, k) => el.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: k, bubbles: true }));

// Each case runs PHP once; on Windows that is a second or more per run.
describe('P3-36 C1 account menu', { timeout: 120000 }, () => {
  test('TC-FR3-07-AS-04', () => {
    // Every signed-in member page, in the plugin header and in the v2 header, prints the sign-out form with the menu's
    // links and loads stage3.js, so the chip's menu is built there and not only on Account.
    for (const v2 of [false, true]) {
      for (const screen of ['home', 'diary', 'more', 'help', 'agreements']) {
        const html = layout({ v2, screen });
        const doc = new JSDOM(html).window.document;
        expect(doc.querySelectorAll('.lld-chip'), `${screen} v2=${v2}: one chip`).toHaveLength(1);
        const form = doc.querySelector('form#lld-sign-out');
        expect(form, screen).not.toBeNull();
        expect(form.getAttribute('method')).toBe('post');
        expect(form.getAttribute('action')).toBe('https://example.test/members/more/');
        expect(form.querySelector('input[name="lld_sign_out_nonce"]').getAttribute('value')).toBe('abc123');
        expect(JSON.parse(form.getAttribute('data-lld-menu'))).toEqual({
          profile: 'https://example.test/members/settings/?section=profile',
          subscription: 'https://example.test/members/settings/?section=membership',
          data: 'https://example.test/members/settings/?section=privacy',
          help: 'https://example.test/members/help/',
        });
        expect(form.getAttribute('data-lld-sign-out')).toBe('lld-sign-out');
        expect(doc.querySelectorAll('script#lld-members-stage3-js')).toHaveLength(1);
        expect(doc.querySelectorAll('link#lld-members-stage3-account-css')).toHaveLength(1);
        expect(doc.querySelector('link#lld-members-stage3-account-css').getAttribute('href')).toMatch(/assets\/stage3-account\.css$/);
        // never a logout link
        expect(html).not.toMatch(/action=sign_out|action=logout|wp-login\.php/);
      }
    }
    // In the v2 header the chip takes the place of "My account", and the member bar under it keeps only the links.
    const v2 = new JSDOM(layout({ v2: true, screen: 'home' })).window.document;
    expect(v2.querySelector('header .lld-v2-account.lld-chip-wrap .lld-chip')).not.toBeNull();
    expect([...v2.querySelectorAll('header a')].map((a) => a.textContent.trim())).not.toContain('My account');
    expect(v2.querySelector('.lld-member-bar .lld-chip')).toBeNull();
    expect(v2.querySelector('.lld-member-bar .lld-member-nav-list')).not.toBeNull();
    expect(v2.body.classList.contains('lld-has-account-menu')).toBe(true);

    // A visitor gets none of it, and the v2 header keeps its own buttons.
    const out = layout({ who: 'visitor', v2: true, screen: 'sign-in' });
    expect(out).not.toMatch(/id="lld-sign-out"|lld-chip|stage3\.js|stage3-account\.css|lld-has-account-menu/);
    expect(out).toContain('Join Free');

    // An Admin page: the Admin shell asks for the account menu, also for a helper without a member record, who gets
    // a chip with the account's name (no badge) and a menu of Help and Sign out.
    expect(adminFront).toMatch(/lld_members_layout\( array\( 'title' => \$title, 'main' => \$main, 'signed_in' => \$member, 'wide' => true, 'account' => true \) \)/);
    for (const v2Shell of [false, true]) {
      const doc = new JSDOM(layout({ who: 'helper', account: true, admin: true, v2: v2Shell, screen: 'admin3' })).window.document;
      expect(doc.querySelectorAll('.lld-chip')).toHaveLength(1);
      expect(doc.querySelector('.lld-chip .lld-chip-name').textContent).toBe('Sam');
      expect(doc.querySelector('.lld-chip .lld-chip-badge')).toBeNull();
      expect(JSON.parse(doc.querySelector('form#lld-sign-out').getAttribute('data-lld-menu'))).toEqual({ help: 'https://example.test/members/help/' });
    }
    // A member on an Admin page gets the member's own menu.
    const owner = new JSDOM(layout({ who: 'member', account: true, admin: true, screen: 'admin3' })).window.document;
    expect(Object.keys(JSON.parse(owner.querySelector('form#lld-sign-out').getAttribute('data-lld-menu')))).toEqual(['profile', 'subscription', 'data', 'help']);
    // The Stage 2 sign-out runs before the member check, so a helper without a member record is signed out too.
    const more = growth.slice(growth.indexOf('public static function more()'), growth.indexOf('// The More list'));
    expect(more.indexOf("'lld_sign_out'")).toBeGreaterThan(-1);
    expect(more.indexOf("'lld_sign_out'")).toBeLessThan(more.indexOf('LLD_Members_Personal::require_page();'));

    // stage3.js builds the same menu from that form on any page, and Sign out submits it.
    for (const v2Shell of [false, true]) {
      const { dom, doc, submitted } = browse(layout({ v2: v2Shell, screen: 'diary' }));
      const chip = doc.querySelector('.lld-chip');
      chip.click();
      const menu = doc.getElementById('lld-desktop-user-menu');
      expect(menu.hidden).toBe(false);
      expect(chip.getAttribute('aria-expanded')).toBe('true');
      expect([...menu.querySelectorAll('[role="menuitem"]')].map((m) => m.textContent)).toEqual(['Profile', 'My subscription', 'My data', 'Help', 'Sign out']);
      expect(menu.querySelector('a[role="menuitem"]').getAttribute('href')).toBe('https://example.test/members/settings/?section=profile');
      const signOutItem = [...menu.querySelectorAll('[role="menuitem"]')].pop();
      expect(signOutItem.tagName).toBe('BUTTON');
      signOutItem.click();
      expect(submitted).toEqual(['lld-sign-out']);
      expect(menu.parentNode.classList.contains('lld-chip-wrap')).toBe(true);
      void dom;
    }
    // On Account the hub's own form (#lld-hub-sign-out) drives the menu, as before.
    const hubPage = layout({ screen: 'settings' }).replace('<h1>Page</h1>', '<div class="lld-stage3-account-hub" data-lld-menu="{&quot;profile&quot;:&quot;/p&quot;,&quot;subscription&quot;:&quot;/s&quot;,&quot;data&quot;:&quot;/d&quot;,&quot;help&quot;:&quot;/h&quot;}" data-lld-sign-out="lld-hub-sign-out"><form id="lld-hub-sign-out" hidden></form></div>');
    const hub = browse(hubPage);
    hub.doc.querySelector('.lld-chip').click();
    [...hub.doc.querySelectorAll('#lld-desktop-user-menu [role="menuitem"]')].pop().click();
    expect(hub.submitted).toEqual(['lld-hub-sign-out']);
    const helper = browse(layout({ who: 'helper', account: true, admin: true, screen: 'admin3' }));
    helper.doc.querySelector('.lld-chip').click();
    expect([...helper.doc.querySelectorAll('#lld-desktop-user-menu [role="menuitem"]')].map((m) => m.textContent)).toEqual(['Help', 'Sign out']);
  });

  test('TC-FR3-07-AS-05', () => {
    // At 360 px the header's menu holds Sign out, posting the same form: the plugin header's ☰ panel and the v2
    // header's phone menu.
    const plain = new JSDOM(layout({ screen: 'home' })).window.document;
    const burger = plain.querySelector('details.lld-burger .lld-burger-panel');
    const out = burger.querySelector('button.lld-burger-signout-button');
    expect(out.textContent).toBe('Sign out');
    expect(out.getAttribute('type')).toBe('submit');
    expect(out.getAttribute('form')).toBe('lld-sign-out');

    const v2 = new JSDOM(layout({ v2: true, screen: 'home' })).window.document;
    const phone = v2.querySelector('#mega-menu button.lld-v2-menu-signout');
    expect(phone.textContent).toBe('Sign out');
    expect(phone.getAttribute('form')).toBe('lld-sign-out');
    expect(phone.closest('.md\\:hidden')).not.toBeNull();
    // one Sign out button per menu, and none for a visitor
    expect(v2.querySelectorAll('button[form="lld-sign-out"]')).toHaveLength(1);
    expect(layout({ who: 'visitor', v2: true, screen: 'sign-in' })).not.toContain('Sign out');
    expect(layout({ who: 'visitor', screen: 'sign-in' })).not.toContain('Sign out');

    // On a phone the chip stays a link to Account (the ☰ menu has Sign out): stage3.js opens no menu under 768 px.
    const { doc } = browse(layout({ v2: true, screen: 'home' }), 360);
    const chip = doc.querySelector('.lld-chip');
    const ev = new doc.defaultView.MouseEvent('click', { bubbles: true, cancelable: true });
    chip.dispatchEvent(ev);
    expect(ev.defaultPrevented).toBe(false);
    expect(doc.getElementById('lld-desktop-user-menu').hidden).toBe(true);

    // The empty member-bar band goes on phones once the chip is in the header.
    const css = readFileSync(resolve(plugin, 'assets/stage3-account.css'), 'utf8');
    expect(css).toMatch(/@media \(max-width: 767px\)[\s\S]*body\.lld-has-account-menu \.lld-member-bar \{ display: none !important; \}/);
  });

  test('TC-FR3-07-AS-06', () => {
    // Sign-in & security: "Sign out of this device" (the Stage 2 sign-out with its nonce, which ends this browser's
    // session only) comes before "Sign out everywhere", in the same Sessions section.
    const body = signIn.slice(signIn.indexOf('public static function security_html'), signIn.indexOf('/* ---------- routes ---------- */'));
    const device = body.indexOf('Sign out of this device');
    const everywhere = body.indexOf('Sign out everywhere');
    expect(device).toBeGreaterThan(body.indexOf('<h3>Sessions</h3>'));
    expect(device).toBeLessThan(everywhere);
    expect(body).toMatch(/<form method="post" action="' \. esc_url\( home_url\( '\/members\/more\/' \) \) \. '" class="lld-inline-form lld-sign-out-device">/);
    expect(body).toMatch(/name="lld_sign_out_nonce" value="' \. esc_attr\( wp_create_nonce\( 'lld_sign_out' \) \) \. '"/);
    // The everywhere form and its sentence are unchanged.
    expect(body).toContain('<p class="lld-muted">Sign out of this website and the app on every device.</p>');
    expect(body).toMatch(/wp_nonce_field\( 'lld_s3_signout_all', 'lld_s3_signout_all_nonce', true, false \)/);
    // The Stage 2 sign-out ends only the current browser's session (wp_logout), unlike end_all_sessions.
    const signOutFn = growth.slice(growth.indexOf('private static function sign_out('), growth.indexOf('private static function own_quote_html'));
    expect(signOutFn).toContain('wp_logout();');
    expect(signOutFn).not.toMatch(/destroy_all|WP_Session_Tokens|end_all_sessions/);
  });

  test('TC-FR3-07-AS-03', () => {
    // The menu built on any member page works with the keyboard alone: Enter or Space opens it on the first item,
    // arrows move, Escape closes it and gives focus back to the chip.
    for (const k of ['Enter', ' ']) {
      const { dom, doc } = browse(layout({ v2: true, screen: 'meals' }));
      const chip = doc.querySelector('.lld-chip');
      chip.focus();
      key(dom, chip, k);
      const menu = doc.getElementById('lld-desktop-user-menu');
      const items = [...menu.querySelectorAll('[role="menuitem"]')];
      expect(menu.hidden, k).toBe(false);
      expect(doc.activeElement).toBe(items[0]);
      key(dom, items[0], 'ArrowDown');
      expect(doc.activeElement).toBe(items[1]);
      key(dom, items[1], 'ArrowUp');
      key(dom, items[0], 'ArrowUp');
      expect(doc.activeElement).toBe(items[items.length - 1]);
      key(dom, doc.activeElement, 'Escape');
      expect(menu.hidden).toBe(true);
      expect(chip.getAttribute('aria-expanded')).toBe('false');
      expect(doc.activeElement).toBe(chip);
      expect(chip.getAttribute('aria-haspopup')).toBe('true');
      expect(chip.getAttribute('aria-controls')).toBe('lld-desktop-user-menu');
    }
    // Loaded twice (a screen that prints the script itself), the menu is still built and toggled once.
    const { dom, doc } = browse(layout({ screen: 'home' }));
    dom.window.eval(js);
    doc.querySelector('.lld-chip').click();
    expect(doc.querySelectorAll('#lld-desktop-user-menu')).toHaveLength(1);
    expect(doc.getElementById('lld-desktop-user-menu').hidden).toBe(false);
  });
});
