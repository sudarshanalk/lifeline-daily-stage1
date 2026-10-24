// TC-NFR1-05 Accessibility (WCAG 2.2 AA). The 11 states: home, hub, both landing pages (local WordPress stack, DEC-69; homepage
// rows via `wp lld home apply` and landing pages via `wp lld pages apply`, both restored), protein form, protein renal result
// (FX-PR-REN-01), protein normal result (FX-PR-NRM-01), diabetes form, diabetes emergency result (FX-DM-EMG-01), diabetes normal
// result (FX-DM-QD-01) and the unavailable state (status.json withdrawn). Scans wait for CSS animations to finish (the result
// cards fade in, and a mid-fade scan reports false contrast failures).
// AS-01 axe: 0 critical and 0 serious; AS-02 keyboard only: both tools completed with Tab/Enter/Space/typing, focus order =
// DOM order, no trap; AS-03 every input / option group labelled, errors and banners role=alert or aria-live=assertive (MUT-14
// turns this red); AS-04 computed contrast of band colours and banner text >= 4.5:1; AS-05 Lighthouse accessibility >= 95 on
// the 6 pages; AS-06 heading levels never skip.
import { test, expect, chromium } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const PR = { file: 'protein-requirement-tool.html', form: '#form', fill: fillProteinForm, submit: submitProtein };
const DM = { file: 'diabetes-risk-tool.html', form: '#tool', fill: fillDiabetesForm, submit: submitDiabetes };
const TOOL_STATES = [
  ['protein form', PR, null], ['protein renal result', PR, fx('protein', 'ren.json', 'FX-PR-REN-01')], ['protein normal result', PR, fx('protein', 'nrm.json', 'FX-PR-NRM-01')],
  ['diabetes form', DM, null], ['diabetes emergency result', DM, fx('diabetes', 'emg.json', 'FX-DM-EMG-01')], ['diabetes normal result', DM, fx('diabetes', 'qd.json', 'FX-DM-QD-01')],
];
const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
const WP_PATHS = ['/', '/health-tools/', '/health-tools/protein/', '/health-tools/diabetes-risk-check/'];
const a = available();
test.describe.configure({ mode: 'serial' });

let srv, down, stack, frontId, originalHome; const before = {};
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeAll(async () => {
  srv = await startHtaccessServer();
  down = await startHtaccessServer({ statusJson: { ...status, tools: { ...status.tools, diabetes: { ...status.tools.diabetes, status: 'withdrawn', message: 'unavailable.diabetes', since: '2026-10-23T12:00:00Z' } } } });
  if (!a.ok) return;
  stack = await startWpStack();
  if (stack.external) return;
  for (const id of Object.values(PAGES)) before[id] = { content: stack.wp('post', 'get', String(id), '--field=post_content').stdout, slug: stack.wp('post', 'meta', 'get', String(id), 'lld_tool_slug').stdout.trim() };
  frontId = stack.wp('option', 'get', 'page_on_front').stdout.trim(); originalHome = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
  for (const cmd of [['pages', 'apply'], ['home', 'apply']]) { const r = stack.wp('lld', ...cmd); if (r.status !== 0) throw new Error(r.stdout + r.stderr); }
});
test.afterAll(async () => {
  if (stack && !stack.external) {
    for (const [id, b] of Object.entries(before)) { stack.wp('post', 'update', id, `--post_content=${b.content.replace(/\n$/, '')}`); b.slug ? stack.wp('post', 'meta', 'update', id, 'lld_tool_slug', b.slug) : stack.wp('post', 'meta', 'delete', id, 'lld_tool_slug'); }
    const rv = stack.wp('lld', 'home', 'revert');
    if (rv.status !== 0 || stack.wp('post', 'get', frontId, '--field=post_content').stdout.trim() !== originalHome.trim()) { stack.wp('post', 'update', frontId, `--post_content=${originalHome}`); throw new Error('home revert did not restore the front page exactly'); }
  }
  await stack?.close(); await srv?.close(); await down?.close();
});
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });
const settle = (page) => page.evaluate(() => Promise.all(document.getAnimations().map((x) => x.finished.catch(() => {}))));
async function toState(page, t, fixture) {
  await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
  if (fixture) { await t.fill(page, fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible(); }
  await settle(page);
}
/** Every state, as [label, async (page) => void] that leaves the page in that state. */
function states() {
  const out = TOOL_STATES.map(([label, t, f]) => [label, (page) => toState(page, t, f)]);
  out.push(['unavailable state', async (page) => { await page.goto(`${down.url}/tools/${DM.file}`); await expect(page.locator('[data-test=unavailable]')).toBeVisible(); await settle(page); }]);
  if (stack) for (const p of WP_PATHS) out.unshift([`wp ${p}`, async (page) => { await page.goto(stack.url + p, { waitUntil: 'load' }); await settle(page); }]);
  return out;
}

test('TC-NFR1-05-AS-01', async ({ page }) => {
  test.setTimeout(600000);
  const bad = []; let n = 0;
  for (const [label, go] of states()) {
    await go(page); n++;
    const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    for (const v of r.violations.filter((x) => ['critical', 'serious'].includes(x.impact))) bad.push(`${label}: ${v.impact} ${v.id} — ${v.nodes.slice(0, 5).map((x) => x.target.join(' ')).join(', ')}`);
  }
  expect(n, stack ? '11 states scanned' : `7 tool states scanned (${a.reason})`).toBe(stack ? 11 : 7);
  expect(bad, 'axe: 0 critical / 0 serious').toEqual([]);
});

/** A Page-like scope whose locator actions are performed with the keyboard only: Tab to the element, then Enter/Space/typing. */
function keyboardScope(page) {
  const tabTo = async (loc) => {
    const h = await loc.elementHandle();
    for (let i = 0; i < 600; i++) {
      if (await h.evaluate((el) => el === document.activeElement)) return;
      await page.keyboard.press('Tab');
    }
    throw new Error('element not reachable with Tab');
  };
  const wrap = (loc) => ({
    locator: (sel) => wrap(loc.locator(sel)),
    isVisible: () => loc.isVisible(),
    click: async () => { await tabTo(loc); await page.keyboard.press('Enter'); },
    check: async () => { await tabTo(loc); if (!(await loc.isChecked())) await page.keyboard.press('Space'); },
    fill: async (v) => { await tabTo(loc); await page.keyboard.press('Control+A'); await page.keyboard.type(String(v)); },
    selectOption: async (v) => {
      await tabTo(loc);
      const [cur, target] = await loc.evaluate((el, val) => [el.selectedIndex, [...el.options].findIndex((o) => o.value === val || o.label === val)], String(v));
      if (target < 0) throw new Error(`no option ${v}`);
      for (let i = 0; i < Math.abs(target - cur); i++) await page.keyboard.press(target > cur ? 'ArrowDown' : 'ArrowUp');
    },
  });
  return { locator: (sel) => wrap(page.locator(sel)) };
}

test('TC-NFR1-05-AS-02', async ({ page }) => {
  test.setTimeout(300000);
  for (const [t, f] of [[PR, fx('protein', 'nrm.json', 'FX-PR-NRM-01')], [DM, fx('diabetes', 'qd.json', 'FX-DM-QD-01')]]) {
    await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
    // focus order = DOM order, and Tab leaves the page again (no trap): one full traversal from the top
    const seq = [];
    for (let i = 0; i < 400; i++) {
      await page.keyboard.press('Tab');
      const idx = await page.evaluate(() => (document.activeElement && document.activeElement !== document.body) ? Array.prototype.indexOf.call(document.querySelectorAll('*'), document.activeElement) : -1);
      if (idx === -1 || (seq.length && idx <= seq[0] && seq.length > 3)) break;   // focus left the page or wrapped to the start
      seq.push(idx);
    }
    expect(seq.length, `${t.file}: focusable controls reached`).toBeGreaterThan(10);
    expect(seq.length, `${t.file}: Tab traversal ends (no focus trap)`).toBeLessThan(400);
    const back = seq.findIndex((v, i) => i > 0 && v < seq[i - 1]);
    expect(back, `${t.file}: focus order follows DOM order (first backward step at position ${back})`).toBe(-1);
    // keyboard-only completion
    await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
    const kb = keyboardScope(page);
    await t.fill(kb, f.inputs); await t.submit(kb);
    await expect(page.locator('#results'), `${t.file}: completed with the keyboard only`).toBeVisible();
  }
});

test('TC-NFR1-05-AS-03', async ({ page }) => {
  const bad = [];
  for (const [label, t, f] of TOOL_STATES.filter(([, , fxt]) => !fxt)) {
    await toState(page, t, null);
    // labels: axe's label rules plus a computed accessible name on every input, select and option group
    const r = await new AxeBuilder({ page }).withRules(['label', 'select-name', 'aria-input-field-name', 'aria-toggle-field-name']).analyze();
    for (const v of r.violations) bad.push(`${label}: ${v.id} ${v.nodes.map((x) => x.target.join(' ')).join(', ')}`);
    const unnamed = await page.locator('input:visible, select:visible, [role=group]:visible, [role=radiogroup]:visible').evaluateAll((els) => els.filter((el) => {
      if (el.type === 'hidden' || el.closest('[aria-hidden=true]')) return false;   // e.g. the honeypot (aria-hidden, tabindex -1) is not in the accessibility tree
      const lbl = el.labels && el.labels.length ? [...el.labels].map((l) => l.textContent).join(' ') : '';
      const named = (el.getAttribute('aria-label') || '') + (el.getAttribute('aria-labelledby') ? [...el.getAttribute('aria-labelledby').split(/\s+/)].map((id) => document.getElementById(id)?.textContent || '').join(' ') : '') + lbl + (el.getAttribute('title') || '');
      return !named.trim();
    }).map((el) => el.id || el.outerHTML.slice(0, 60)));
    for (const u of unnamed) bad.push(`${label}: no accessible name: ${u}`);
    // errors: submit the empty form and require role=alert / aria-live=assertive on every visible error
    await t.submit(page);
    const errs = await page.locator('[data-test=form-error]:visible, .err:visible, [data-test=error]:visible').evaluateAll((els) => els.filter((el) => el.textContent.trim()).map((el) => ({ id: el.id || el.getAttribute('data-err') || el.className, ok: el.getAttribute('role') === 'alert' || el.closest('[aria-live=assertive]') !== null })));
    if (!errs.length) bad.push(`${label}: submitting the empty form showed no error`);
    for (const e of errs) if (!e.ok) bad.push(`${label}: error ${e.id} lacks role=alert / aria-live=assertive`);
  }
  // banners: renal (protein) and emergency (diabetes) states
  for (const [label, t, f, sel] of [['protein renal result', PR, fx('protein', 'ren.json', 'FX-PR-REN-01'), '[data-test=banners] .banner'], ['diabetes emergency result', DM, fx('diabetes', 'emg.json', 'FX-DM-EMG-01'), '[data-test=banner-emergency]']]) {
    await toState(page, t, f);
    const banners = await page.locator(sel).evaluateAll((els) => els.map((el) => ({ test: el.getAttribute('data-test'), ok: el.getAttribute('role') === 'alert' || el.getAttribute('aria-live') === 'assertive' || el.closest('[aria-live=assertive]') !== null })));
    if (!banners.length) bad.push(`${label}: no banner rendered`);
    for (const b of banners) if (!b.ok) bad.push(`${label}: banner ${b.test} lacks role=alert / aria-live=assertive`);
  }
  expect(bad, 'labels, errors and banners').toEqual([]);
});

test('TC-NFR1-05-AS-04', async ({ page }) => {
  const contrast = (page, sel) => page.locator(sel).evaluateAll((els) => {
    const rgb = (s) => (s.match(/[\d.]+/g) || []).slice(0, 4).map(Number);
    const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((s, v, i) => s + v * [0.2126, 0.7152, 0.0722][i], 0);
    const bgOf = (el) => { for (let n = el; n; n = n.parentElement) { const c = rgb(getComputedStyle(n).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0)) return c.slice(0, 3); } return [255, 255, 255]; };
    return els.map((el) => { const fg = rgb(getComputedStyle(el).color).slice(0, 3); const bg = bgOf(el); const [x, y] = [lum(fg), lum(bg)]; return { cls: el.className || el.tagName, text: el.textContent.trim().slice(0, 30), ratio: (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05) }; });
  });
  const low = [];
  // every diabetes band colour as the result badge renders it (white text on the band background)
  await page.goto(`${srv.url}/tools/${DM.file}`); await expect(page.locator(DM.form)).toBeVisible();
  const bands = await page.evaluate(() => ['--band-low', '--band-mod', '--band-high', '--band-vhigh'].map((v) => { const el = document.createElement('span'); el.className = 'risk-band'; el.textContent = 'band'; el.style.background = `var(${v})`; document.body.appendChild(el); return v; }));
  for (const r of await contrast(page, 'body > span.risk-band')) if (r.ratio < 4.5) low.push(`diabetes band ${r.text} ${r.ratio.toFixed(2)}`);
  expect(bands.length).toBe(4);
  // banners and their text in the states that show them
  for (const [label, t, f] of [['protein renal result', PR, fx('protein', 'ren.json', 'FX-PR-REN-01')], ['diabetes emergency result', DM, fx('diabetes', 'emg.json', 'FX-DM-EMG-01')], ['diabetes normal result', DM, fx('diabetes', 'qd.json', 'FX-DM-QD-01')]]) {
    await toState(page, t, f);
    for (const r of await contrast(page, '[data-test=banners] .banner, [data-test=banners] .banner h3, [data-test=banners] .banner p, .risk-band')) if (r.ratio < 4.5) low.push(`${label}: ${r.cls} "${r.text}" ${r.ratio.toFixed(2)}`);
  }
  expect(low, 'band colours and banner text >= 4.5:1').toEqual([]);
});

test('TC-NFR1-05-AS-05', async ({}, testInfo) => {
  test.setTimeout(900000);
  const { default: lighthouse } = await import('lighthouse');
  const port = 9300 + testInfo.workerIndex;
  const browser = await chromium.launch({ args: [`--remote-debugging-port=${port}`] });
  const urls = [`${srv.url}/tools/${PR.file}`, `${srv.url}/tools/${DM.file}`, ...(stack ? WP_PATHS.map((p) => stack.url + p) : [])];
  const low = [];
  try {
    for (const url of urls) {
      const r = await lighthouse(url, { port, output: 'json', logLevel: 'error', onlyCategories: ['accessibility'], formFactor: 'desktop', screenEmulation: { disabled: true } });
      const score = Math.round((r.lhr.categories.accessibility.score || 0) * 100);
      testInfo.annotations.push({ type: 'lighthouse', description: `${url} accessibility ${score}` });
      if (score < 95) low.push(`${url}: ${score} (${Object.values(r.lhr.audits).filter((x) => x.score === 0 && x.details?.type === 'table').map((x) => x.id).join(', ')})`);
    }
  } finally { await browser.close(); }
  expect(urls.length, stack ? '6 pages' : `2 tool pages (${a.reason})`).toBe(stack ? 6 : 2);
  expect(low, 'Lighthouse accessibility >= 95').toEqual([]);
});

test('TC-NFR1-05-AS-06', async ({ page }) => {
  test.setTimeout(600000);
  const bad = [], theme = [];
  for (const [label, go] of states()) {
    await go(page);
    // every visible heading; those inside the Newspaper theme's own sidebar/blocks (.td_block_wrap, frozen CMP-01) are reported
    // separately — registry known deviation + DEC-78 blocker — while every other heading must never skip a level
    const hs = await page.locator('h1:visible, h2:visible, h3:visible, h4:visible, h5:visible, h6:visible').evaluateAll((els) => els.map((e) => ({ l: Number(e.tagName[1]), theme: !!e.closest('.td_block_wrap') && !e.closest('.lld-tools-hub, .lld-home-row, [data-lld-section]'), t: e.textContent.trim().slice(0, 30) })));
    const own = hs.filter((h) => !h.theme);
    for (let i = 1; i < own.length; i++) if (own[i].l > own[i - 1].l + 1) { bad.push(`${label}: h${own[i - 1].l} → h${own[i].l} ("${own[i].t}")`); break; }
    for (let i = 1; i < hs.length; i++) if (hs[i].theme && hs[i].l > hs[i - 1].l + 1) { theme.push(`${label}: theme block "${hs[i].t}" h${hs[i].l} after h${hs[i - 1].l}`); break; }
  }
  if (theme.length) test.info().annotations.push({ type: 'theme-headings (DEC-78)', description: theme.join(' | ') });
  expect(bad, 'heading levels never skip (plugin, tool and page content)').toEqual([]);
});
