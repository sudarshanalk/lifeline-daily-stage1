// P3-36 C1 follow-up (FR3-07): the public v2 pages (homepage, categories, articles, Health Tools) show a signed-in member
// the same account menu as the member pages, in place of the plain "My account" link. The real v2 header template is run
// in PHP CLI with components.php loaded and small WordPress stand-ins (filters with priorities); stage3.js then runs in
// jsdom against the printed header. Visitors keep the header they had, and the page stays cacheable for them.
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
const css = readFileSync(resolve(plugin, 'assets/stage3-account.css'), 'utf8');
const p = (x) => JSON.stringify(x.split('\\').join('/'));

// Prints a public page: the body classes, the v2 header and what wp_footer() would print. who: 'member' | 'helper' | 'visitor'.
function publicPage({ who = 'member', page = 'home', admin = false } = {}) {
  const code = `
    define('ABSPATH', '/'); define('LLD_MEMBERS_FILE', '/plugin/lld-members.php'); define('LLD_MEMBERS_VERSION', '9');
    $GLOBALS['f'] = []; $GLOBALS['actions'] = [];
    function add_filter($h, $cb, $pr = 10) { $GLOBALS['f'][$h][$pr][] = $cb; }
    function apply_filters($h, $v) { $all = $GLOBALS['f'][$h] ?? []; ksort($all); foreach ($all as $cbs) foreach ($cbs as $cb) { $v = $cb($v); } return $v; }
    function do_action($h, ...$a) { $GLOBALS['actions'][] = $h; }
    function plugins_url($path, $file = '') { return 'https://example.test/wp-content/plugins/lld-members/' . $path; }
    function home_url($p = '') { return 'https://example.test' . $p; }
    function esc_url($s) { return $s; } function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function wp_json_encode($v) { return json_encode($v); }
    function wp_create_nonce($a) { return 'nonce-' . $a; }
    function is_user_logged_in() { return ${who === 'visitor' ? 'false' : 'true'}; }
    function current_user_can($c) { return ${admin ? 'true' : 'false'}; }
    $GLOBALS['printed'] = []; $GLOBALS['queued'] = [];
    function wp_register_style($h, $src) { $GLOBALS['src'][$h] = $src; }
    function wp_print_styles($hs) { foreach ($hs as $h) { if (empty($GLOBALS['printed'][$h . 'css'])) { $GLOBALS['printed'][$h . 'css'] = 1; echo '<link rel="stylesheet" id="' . $h . '-css" href="' . $GLOBALS['src'][$h] . '">'; } } }
    function wp_enqueue_script($h, $src, $deps = [], $ver = false, $foot = false) { $GLOBALS['queued'][$h] = [$src, $foot]; }
    class LLD_Members_Access { static function current_member_id() { return ${who === 'member' ? 7 : 0}; } static function premium_status($id) { return ['premium' => false, 'source' => 'free', 'until' => '']; } }
    class LLD_Members_Schema { static function table($t) { return 'wp_lld_' . $t; } }
    class FakeDb { function prepare($q, ...$a) { return $q; } function get_row($q) { return (object) ['first_name' => 'Alex', 'display_name' => 'Alex A']; } }
    $GLOBALS['wpdb'] = new FakeDb();
    require ${p(componentsPath)};
    echo '<body class="' . implode(' ', apply_filters('body_class', ['lld-v2'])) . '">';
    $lld_header = ['page' => ${JSON.stringify(page)}, 'current' => '']; $hu = null;
    include ${p(headerPath)};
    // wp_footer(): the footer scripts queued while the page was printed
    foreach ($GLOBALS['queued'] as $h => $q) { if ($q[1]) echo '<script id="' . $h . '-js" src="' . $q[0] . '"></script>'; }
    echo '</body><!--actions:' . implode(',', $GLOBALS['actions']) . ';donotcache:' . (defined('DONOTCACHEPAGE') ? 'yes' : 'no') . '-->';`;
  return execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 });
}

function browse(html, width = 1280) {
  const dom = new JSDOM(`<!doctype html><html><head></head>${html}</html>`, { runScripts: 'outside-only', url: 'https://example.test/' });
  Object.defineProperty(dom.window, 'innerWidth', { value: width, configurable: true });
  Object.defineProperty(dom.window.document, 'readyState', { value: 'complete', configurable: true });
  const submitted = [];
  dom.window.HTMLFormElement.prototype.submit = function () { submitted.push(this.id); };
  dom.window.eval(js);
  return { dom, doc: dom.window.document, submitted };
}

describe('P3-36 C1 follow-up: the account menu on the public pages', { timeout: 120000 }, () => {
  test('TC-FR3-07-AS-04', () => {
    // A signed-in member on the homepage, a category, an article and Health Tools: the chip with its menu, not "My account".
    for (const page of ['home', 'category', 'article', 'tools']) {
      const html = publicPage({ page });
      const doc = new JSDOM(html).window.document;
      const banner = doc.querySelector('header');
      expect(banner.querySelectorAll('.lld-v2-account.lld-chip-wrap .lld-chip'), page).toHaveLength(1);
      expect([...banner.querySelectorAll('a')].map((a) => a.textContent.trim()), page).not.toContain('My account');
      expect(doc.querySelector('.lld-chip .lld-chip-name').textContent).toBe('Alex');
      // the same sign-out form as the member pages: a POST to the Stage 2 sign-out with its nonce, carrying the links
      const form = doc.querySelector('form#lld-sign-out');
      expect(form.getAttribute('method')).toBe('post');
      expect(form.getAttribute('action')).toBe('https://example.test/members/more/');
      expect(form.querySelector('input[name="lld_sign_out_nonce"]').getAttribute('value')).toBe('nonce-lld_sign_out');
      expect(Object.keys(JSON.parse(form.getAttribute('data-lld-menu')))).toEqual(['profile', 'subscription', 'data', 'help']);
      expect(html).not.toMatch(/action=logout|wp-login\.php/);
      // the menu's stylesheet once, stage3.js once in the foot, and the body class its v2 header styles key on
      expect(doc.querySelectorAll('link#lld-members-stage3-account-css')).toHaveLength(1);
      expect(doc.querySelectorAll('script#lld-members-stage3-js')).toHaveLength(1);
      expect(doc.querySelector('script#lld-members-stage3-js').getAttribute('src')).toMatch(/assets\/stage3\.js$/);
      expect(doc.body.classList.contains('lld-v2-account-menu')).toBe(true);
      // never cached: the page holds the member's name and a nonce
      expect(html).toMatch(/actions:[^;]*litespeed_control_set_nocache/);
      expect(html).toContain('donotcache:yes');
    }

    // stage3.js builds the same menu on a public page, and Sign out submits the form.
    const { doc, submitted } = browse(publicPage({ page: 'article' }));
    const chip = doc.querySelector('.lld-chip');
    chip.click();
    const menu = doc.getElementById('lld-desktop-user-menu');
    expect(menu.hidden).toBe(false);
    expect([...menu.querySelectorAll('[role="menuitem"]')].map((m) => m.textContent)).toEqual(['Profile', 'My subscription', 'My data', 'Help', 'Sign out']);
    [...menu.querySelectorAll('[role="menuitem"]')].pop().click();
    expect(submitted).toEqual(['lld-sign-out']);

    // The v2 header styles for the chip and its menu apply on the public pages too (same rules as the member pages).
    expect(css).toContain('body:is(.lld-members-v2, .lld-v2-account-menu) .lld-v2-account .lld-chip {');
    expect(css).toContain('body.mode-night:is(.lld-members-v2, .lld-v2-account-menu) .lld-desktop-menu {');
    expect(css).not.toMatch(/body\.lld-members-v2 \.lld-v2-account/);
  });

  test('TC-FR3-07-AS-05', () => {
    // At 360 px the public header's phone menu holds Sign out, posting the same form; one Sign out button only.
    const doc = new JSDOM(publicPage({ page: 'home' })).window.document;
    const out = doc.querySelectorAll('#mega-menu button.lld-v2-menu-signout');
    expect(out).toHaveLength(1);
    expect(out[0].textContent).toBe('Sign out');
    expect(out[0].getAttribute('form')).toBe('lld-sign-out');
    expect(out[0].closest('.md\\:hidden')).not.toBeNull();
    expect(doc.querySelectorAll('button[form="lld-sign-out"]')).toHaveLength(1);
    // On a phone the chip stays a link to Account (the menu has Sign out).
    const ph = browse(publicPage({ page: 'home' }), 360);
    const ev = new ph.dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    ph.doc.querySelector('.lld-chip').dispatchEvent(ev);
    expect(ev.defaultPrevented).toBe(false);
  });

  test('TC-FR3-07-AS-04 (visitors and accounts without a member record keep today\'s header)', () => {
    // A visitor: Sign In and Join Free, no chip, form, script or stylesheet, and the page stays cacheable.
    const out = publicPage({ who: 'visitor', page: 'home' });
    expect(out).toContain('Join Free');
    expect(out).toContain('Sign In');
    expect(out).not.toMatch(/id="lld-sign-out"|lld-chip|stage3\.js|stage3-account\.css|lld-v2-account-menu|Sign out/);
    expect(out).not.toContain('litespeed_control_set_nocache');
    expect(out).toContain('donotcache:no');
    // A signed-in helper without a member record keeps "My account" and Admin, as before.
    const helper = publicPage({ who: 'helper', page: 'tools', admin: true });
    const hd = new JSDOM(helper).window.document;
    expect([...hd.querySelectorAll('header a')].map((a) => a.textContent.trim())).toEqual(expect.arrayContaining(['My account', 'Admin']));
    expect(helper).not.toMatch(/id="lld-sign-out"|lld-chip|Sign out|lld-v2-account-menu/);
    expect(helper).toContain('donotcache:no');
  });
});
