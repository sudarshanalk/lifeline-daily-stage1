// P3-36 C4, C5 and D8, checked on the real markup and styles (the browser checks are account-hub.spec.mjs at 360 px and
// the Stage 2 accounts.spec.mjs eye tests, which run unchanged):
// C4 the password eye sits inside the right end of the box, its accessible text kept for screen readers and tests;
// C5 on phones the Account tabs and the Admin sections are one row that scrolls sideways, with a fade, inside the page;
// D8 the Google "G" mark on Continue with Google, hidden from screen readers.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const read = (p) => readFileSync(resolve(plugin, p), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const base = read('assets/lld-members.css');
const account = read('assets/stage3-account.css');
const rule = (css, selector) => {
  const at = css.indexOf(`${selector} {`);
  return at < 0 ? '' : css.slice(at, css.indexOf('}', at) + 1);
};

test('TC-NFR3-12-AS-02 (C4 password eye inside the box, 44 px, name kept)', () => {
  // The shared field's markup is unchanged: the button's text is its name, swapped by lld-members.js.
  const html = execFileSync('php', ['-r', `define('ABSPATH', '/'); function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } function esc_html($s) { return esc_attr($s); } function esc_url($s) { return $s; } function home_url($p = '') { return $p; } function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
    require ${toPhpPath(resolve(plugin, 'includes/components.php'))}; echo lld_password_field(['id' => 'lld_password', 'label' => 'Password']);`], { encoding: 'utf8', timeout: 60000 });
  expect(html).toContain('<button type="button" class="lld-eye" aria-controls="lld_password" aria-pressed="false" data-lld-show="Show password" data-lld-hide="Hide password">Show password</button>');
  expect(read('assets/lld-members.js')).toContain('button.textContent = show ? button.getAttribute("data-lld-hide") : button.getAttribute("data-lld-show");');
  // Inside the right end of the box, 44 by 44, the text hidden from view only (not display:none, not aria-hidden).
  expect(rule(base, '.lld-password-row')).toContain('position: relative;');
  expect(rule(base, '.lld-password-row input')).toContain('padding-right: 52px;');
  const eye = rule(base, '.lld-eye');
  for (const d of ['position: absolute;', 'right: 2px;', 'width: 44px;', 'height: 44px;', 'font-size: 0;', 'background: transparent;']) expect(eye).toContain(d);
  expect(eye).not.toMatch(/display:\s*none|visibility:\s*hidden/);
  // An eye, and a struck-through eye while the password shows; a visible focus ring.
  expect(rule(base, '.lld-eye::before')).toMatch(/mask: url\("data:image\/svg\+xml/);
  expect(base).toMatch(/\.lld-eye\[aria-pressed="true"\]::before \{[^}]*mask-image: url\("data:image\/svg\+xml[^"]*M3 3l18 18/);
  expect(rule(base, '.lld-eye:focus-visible')).toContain('outline: 3px solid');
  // The v2 sign-in look (lld-v2.css) styles the old pill with !important; the more specific rules here win on those pages.
  expect(base).toMatch(/body\.lld-members-v2-form \.lld-password-row \.lld-eye \{[^}]*position: absolute !important;[^}]*font-size: 0 !important;/);
  // ...and the typed text stays clear of the eye there: one class stronger than lld-v2.css's
  // body.lld-members-v2-form .lld-main input[type=password] (0,3,2), which prints after this sheet.
  expect(base).toContain('body.lld-members-v2-form .lld-main .lld-password-row.lld-password-row input { padding-right: 52px !important;');
});

test('TC-NFR3-12-AS-01 (C5 one scrolling row for the Account tabs and Admin sections)', () => {
  const phone = account.slice(account.indexOf('@media (max-width: 760px)'));
  expect(phone).toMatch(/\.lld-account-nav \.lld-account-tabs,\s*\.lld-adm-body \.lld-adm-tabs \{[^}]*overflow-x: auto;[^}]*mask-image: linear-gradient\(to right, #000 calc\(100% - 32px\), transparent\);/);
  expect(phone).toMatch(/\.lld-account-nav \.lld-account-tabs \{[^}]*flex-wrap: nowrap;[^}]*max-width: 100%;/);
  expect(phone).toMatch(/\.lld-account-nav \.lld-account-tab \{[^}]*white-space: nowrap;/);
  // Focus rings stay visible inside the scrolling rows.
  expect(phone).toMatch(/\.lld-account-nav \.lld-account-tab:focus-visible,\s*\.lld-adm-body \.lld-adm-tabs a:focus-visible \{[^}]*outline-offset: -3px;/);
  // The phone row of Admin pages is named for what it is.
  expect(read('includes/stage3/admin/class-admin-front.php')).toContain('<nav class="lld-adm-tabs" aria-label="Admin sections">');
});

test('TC-FR3-01-AS-01 (D8 the Google mark on Continue with Google)', () => {
  const signIn = read('includes/stage3/signin/class-sign-in.php');
  expect(signIn).toMatch(/const GOOGLE_MARK = '<svg class="lld-provider-mark" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'/);
  for (const colour of ['#4285F4', '#34A853', '#FBBC05', '#EA4335']) expect(signIn).toContain(`fill="${colour}"`);
  expect(signIn).toContain("$mark  = 'google' === $provider ? self::GOOGLE_MARK : '';");
  // The link's name is still "Continue with Google" (the SVG is hidden from screen readers and has no title).
  expect(signIn).toContain("'\">' . $mark . 'Continue with ' . esc_html( LLD_Members_Identity_Provider::label( $provider ) ) . '</a> '");
  expect(rule(base, '.lld-provider-mark')).toContain('margin-right: 10px;');
});
