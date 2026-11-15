// P3-36 C5 follow-up (#266): on phones the Account tabs and the Admin sections are one row that scrolls sideways, and
// stage3.js scrolls that row (sideways only) so the current tab starts in view. jsdom has no layout, so each row's and
// tab's sizes are set by hand; the page itself must never be scrolled.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const js = readFileSync(resolve(here, '../../site/public_html/wp-content/plugins/lld-members/assets/stage3.js'), 'utf8');

const ACCOUNT = ['profile', 'goals', 'security', 'notifications', 'privacy', 'membership'];

function accountTabs(current) {
  return '<main class="lld-main"><nav class="lld-account-nav" aria-label="Account sections"><ul class="lld-account-tabs">'
    + ACCOUNT.map((s) => `<li><a href="/members/settings/?section=${s}" id="tab-${s}" class="lld-account-tab${s === current ? ' is-active" aria-current="page' : ''}">${s}</a></li>`).join('')
    + '</ul></nav></main>';
}

function adminTabs(currentIndex) {
  const items = Array.from({ length: 11 }, (_, i) => `<li><a href="/members/admin/p${i}/"${i === currentIndex ? ' aria-current="page"' : ''}>Page ${i}</a></li>`).join('');
  return `<div class="lld-adm" data-lld-admin><div class="lld-adm-body"><nav class="lld-adm-tabs" aria-label="Admin sections"><ul>${items}</ul></nav></div></div>`;
}

// Lays the row out as a phone would: a 360 px window, the row `width` wide, each tab `tab` px wide with 8 px gaps.
function layOut(dom, rowSel, tabSel, { width = 328, tab = 120, gap = 8, left = 16 } = {}) {
  const w = dom.window;
  const row = w.document.querySelector(rowSel);
  const tabs = [...row.querySelectorAll(tabSel)];
  const total = tabs.length * tab + (tabs.length - 1) * gap;
  let scrollLeft = 0;
  Object.defineProperty(row, 'clientWidth', { value: width, configurable: true });
  Object.defineProperty(row, 'scrollWidth', { value: Math.max(width, total), configurable: true });
  Object.defineProperty(row, 'scrollLeft', {
    configurable: true,
    get: () => scrollLeft,
    set: (v) => { scrollLeft = Math.max(0, Math.min(Math.max(width, total) - width, v)); },
  });
  const box = (x, wd) => ({ left: x, right: x + wd, width: wd, top: 200, bottom: 244, height: 44, x, y: 200 });
  row.getBoundingClientRect = () => box(left, width);
  tabs.forEach((t, i) => { t.getBoundingClientRect = () => box(left + i * (tab + gap) - scrollLeft, tab); });
  return { row, tabs, total };
}

function run(html, rowSel, tabSel, size) {
  const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { runScripts: 'outside-only', url: 'https://example.test/members/settings/' });
  Object.defineProperty(dom.window, 'innerWidth', { value: 360, configurable: true });
  Object.defineProperty(dom.window.document, 'readyState', { value: 'complete', configurable: true });
  const laid = layOut(dom, rowSel, tabSel, size);
  const pageScrolls = [];
  dom.window.scrollTo = (...a) => pageScrolls.push(a);
  dom.window.scrollBy = (...a) => pageScrolls.push(a);
  dom.window.HTMLElement.prototype.scrollIntoView = function () { pageScrolls.push(['scrollIntoView', this.id]); };
  dom.window.eval(js);
  return { dom, ...laid, pageScrolls };
}

test('TC-NFR3-12-AS-01 (C5 follow-up: the current Account tab or Admin section starts in view on a phone)', () => {
  // Membership is the last of six Account tabs, off to the right of a 328 px row: the row scrolls so it is in the middle.
  const acc = run(accountTabs('membership'), '.lld-account-nav .lld-account-tabs', '.lld-account-tab');
  const cur = acc.dom.window.document.getElementById('tab-membership').getBoundingClientRect();
  const rowBox = acc.row.getBoundingClientRect();
  expect(acc.row.scrollLeft).toBeGreaterThan(0);
  expect(cur.left).toBeGreaterThanOrEqual(rowBox.left);
  expect(cur.right).toBeLessThanOrEqual(rowBox.right);
  // the last tab: the row is scrolled to its end (never past it)
  expect(acc.row.scrollLeft).toBe(acc.total - 328);
  // only the row moved: the page was not scrolled and scrollIntoView (which can move the page) was not used
  expect(acc.pageScrolls).toEqual([]);

  // A middle tab is centred in the row.
  const mid = run(accountTabs('notifications'), '.lld-account-nav .lld-account-tabs', '.lld-account-tab');
  const box = mid.dom.window.document.getElementById('tab-notifications').getBoundingClientRect();
  const rb = mid.row.getBoundingClientRect();
  expect(Math.abs((box.left + box.width / 2) - (rb.left + rb.width / 2))).toBeLessThanOrEqual(1);

  // The first tab is already in view: the row stays at the start.
  expect(run(accountTabs('profile'), '.lld-account-nav .lld-account-tabs', '.lld-account-tab').row.scrollLeft).toBe(0);

  // Admin: the eleven sections in one row; the 10th (aria-current) comes into view.
  const adm = run(adminTabs(9), '.lld-adm-tabs', 'a');
  const a = adm.tabs[9].getBoundingClientRect();
  const ab = adm.row.getBoundingClientRect();
  expect(adm.row.scrollLeft).toBeGreaterThan(0);
  expect(a.left).toBeGreaterThanOrEqual(ab.left);
  expect(a.right).toBeLessThanOrEqual(ab.right);
  expect(adm.pageScrolls).toEqual([]);

  // A row that fits (a desktop, or few tabs) is not touched.
  const wide = run(accountTabs('membership'), '.lld-account-nav .lld-account-tabs', '.lld-account-tab', { width: 1200 });
  expect(wide.row.scrollLeft).toBe(0);
  expect(wide.row.hasAttribute('data-lld-tab-scrolled')).toBe(false);

  // Once the visitor has scrolled the row, the later pass (window load) leaves it where they put it.
  const moved = run(accountTabs('membership'), '.lld-account-nav .lld-account-tabs', '.lld-account-tab');
  moved.row.scrollLeft = 10;
  moved.dom.window.dispatchEvent(new moved.dom.window.Event('load'));
  expect(moved.row.scrollLeft).toBe(10);

  // The code sets the row's own scrollLeft and never calls scrollIntoView or scrolls the window.
  const part = js.slice(js.indexOf('the current tab could start off screen'));
  expect(part).toContain('row.scrollLeft = ');
  expect(part).not.toMatch(/scrollIntoView|window\.scroll(To|By)?\(/);
});
