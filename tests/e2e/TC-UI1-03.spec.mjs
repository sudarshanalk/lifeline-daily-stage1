// TC-UI1-03 Responsive layout — e2e at 320/375/768/1440. Six pages: home, hub, the two landing pages (local WordPress stack,
// DEC-69; homepage rows via `wp lld home apply` and landing pages via `wp lld pages apply`, both restored afterwards) and the two
// standalone tools (htaccess emulator). Form state = the page as loaded; result state = FX-PR-NRM-01 / FX-DM-QD-01 submitted
// (on a landing page, inside the embedded tool). AS-01 `document.scrollingElement.scrollWidth <= innerWidth` everywhere, and
// inside the embedded tool's own document; AS-02 the primary CTA (tool submit button; first card CTA on home and hub) is the
// element at its own centre after scrollIntoView; AS-03 no `[data-test=metrics]` child is clipped (scrollWidth > clientWidth).
import { test, expect } from '@playwright/test';
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
const TOOLS = {
  protein: { file: 'protein-requirement-tool.html', landing: '/health-tools/protein/', fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, form: '#form', cta: '#form button[type=submit]' },
  diabetes: { file: 'diabetes-risk-tool.html', landing: '/health-tools/diabetes-risk-check/', fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, form: '#tool', cta: '#submitBtn' },
};
const WIDTHS = [320, 375, 768, 1440];
const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
const a = available();
test.describe.configure({ mode: 'serial' });

let srv, stack; const before = {}; let frontId, originalHome;
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeAll(async () => {
  srv = await startHtaccessServer();
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
  await stack?.close(); await srv?.close();
});
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });
const overflow = (scope) => scope.evaluate(() => ({ sw: document.scrollingElement.scrollWidth, iw: window.innerWidth }));
const centreHit = (loc) => loc.evaluate((el) => { el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { ok: !!hit && (hit === el || el.contains(hit)), inView: r.top >= 0 && r.bottom <= window.innerHeight && r.left >= 0 && r.right <= window.innerWidth, hit: hit ? hit.tagName + '.' + hit.className : null }; });
const clipped = (scope) => scope.locator('[data-test=metrics]').evaluate((m) => [...m.querySelectorAll('*')].filter((c) => c.scrollWidth > c.clientWidth + 1 && getComputedStyle(c).overflowX !== 'visible').map((c) => `${c.tagName}.${c.className}: ${c.scrollWidth}>${c.clientWidth}`));

test('TC-UI1-03-AS-01', async ({ page }) => {
  test.setTimeout(900000);
  const over = [];
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 800 });
    for (const [slug, t] of Object.entries(TOOLS)) {
      await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
      let m = await overflow(page); if (m.sw > m.iw) over.push(`${width} ${slug} tool form: ${m.sw}>${m.iw}`);
      await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible();
      m = await overflow(page); if (m.sw > m.iw) over.push(`${width} ${slug} tool result: ${m.sw}>${m.iw}`);
    }
    if (!stack) continue;
    for (const path of ['/', '/health-tools/']) { await page.goto(stack.url + path, { waitUntil: 'load' }); const m = await overflow(page); if (m.sw > m.iw) over.push(`${width} ${path}: ${m.sw}>${m.iw}`); }
    for (const [slug, t] of Object.entries(TOOLS)) {
      await page.goto(stack.url + t.landing, { waitUntil: 'load' });
      let m = await overflow(page); if (m.sw > m.iw) over.push(`${width} ${t.landing} form: ${m.sw}>${m.iw}`);
      // the theme's sticky header can sit over a just-scrolled-to control inside the iframe; it ignores pointer events while the
      // spec fills the form (no change to layout or rendering, so the overflow measurements are unaffected)
      await page.addStyleTag({ content: '[role=banner], [role=banner] *, .td-header-template-wrap, .td-header-template-wrap *, .td-header-mobile-sticky-wrap, .td-header-mobile-sticky-wrap * { pointer-events: none !important; }' });
      const frame = page.frameLocator('iframe.lld-tool-frame');
      await expect(frame.locator(t.form)).toBeVisible();
      await t.fill(frame, t.fixture.inputs); await t.submit(frame); await expect(frame.locator('#results')).toBeVisible();
      m = await overflow(page); if (m.sw > m.iw) over.push(`${width} ${t.landing} result: ${m.sw}>${m.iw}`);
      const inner = await frame.locator('body').evaluate(() => ({ sw: document.scrollingElement.scrollWidth, iw: window.innerWidth }));
      if (inner.sw > inner.iw) over.push(`${width} ${t.landing} embedded tool result: ${inner.sw}>${inner.iw}`);
    }
  }
  expect(over, 'no horizontal overflow: 6 pages × 4 widths × {form, result}').toEqual([]);
  if (!stack) test.info().annotations.push({ type: 'scope', description: `WordPress pages not checked: ${a.reason}` });
});

test('TC-UI1-03-AS-02', async ({ page }) => {
  test.setTimeout(600000);
  const bad = [];
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 800 });
    for (const [slug, t] of Object.entries(TOOLS)) {
      await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
      const h = await centreHit(page.locator(t.cta)); if (!h.ok || !h.inView) bad.push(`${width} ${slug} submit: hit ${h.hit}, in view ${h.inView}`);
    }
    if (!stack) continue;
    for (const path of ['/', '/health-tools/']) {
      await page.goto(stack.url + path, { waitUntil: 'load' });
      const sel = path === '/' ? '[data-test=tools-row] [data-test=cta]' : '[data-test=tools-hub] [data-tool-card][data-status=active] [data-test=cta]';
      const h = await centreHit(page.locator(sel).first()); if (!h.ok || !h.inView) bad.push(`${width} ${path} card CTA: hit ${h.hit}, in view ${h.inView}`);
    }
  }
  expect(bad, 'primary CTA fully in view and not covered after scrollIntoView').toEqual([]);
});

test('TC-UI1-03-AS-03', async ({ page }) => {
  const bad = [];
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 800 });
    for (const [slug, t] of Object.entries(TOOLS)) {
      await page.goto(`${srv.url}/tools/${t.file}`); await t.fill(page, t.fixture.inputs); await t.submit(page);
      await expect(page.locator('[data-test=metrics]')).toBeVisible();
      for (const c of await clipped(page)) bad.push(`${width} ${slug}: ${c}`);
    }
  }
  expect(bad, '[data-test=metrics] children are not clipped').toEqual([]);
});
