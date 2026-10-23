// TC-FR1-03 Tools-first homepage — e2e + crawl against the local WordPress stack (snapshot + overlay plugin, DEC-69).
// `wp lld home apply` transforms the static front page (DEC-23); `wp lld home revert` restores it and the run checks the content
// is byte-identical to what it found. Fixtures: FX-WP-POST-01 (fixtures/site/site.json; seeded dated today, deleted afterwards).
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const POST01 = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures.find((f) => f.id === 'FX-WP-POST-01').inputs;
const cards = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const WIDTHS = { phone: { width: 375, height: 812 }, desktop: { width: 1440, height: 900 } };
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let stack, frontId, originalHome, postId;
test.beforeAll(async () => {
  stack = await startWpStack();
  if (!stack.external) {
    frontId = stack.wp('option', 'get', 'page_on_front').stdout.trim();
    originalHome = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
    // FX-WP-POST-01: a recent published post (dated now) with the fixture's category and review meta — seeded for the run
    const cat = stack.wp('term', 'list', 'category', `--slug=${POST01.category}`, '--field=term_id').stdout.trim();
    const r = stack.wp('post', 'create', '--post_type=post', '--post_author=1', `--post_status=${POST01.status}`, '--post_title=FX-WP-POST-01 recent post (test seed)', '--post_content=<p>Seeded by TC-FR1-03 (FX-WP-POST-01); removed after the run.</p>', `--post_category=${cat}`, '--porcelain');
    if (r.status !== 0) throw new Error(r.stdout + r.stderr);
    postId = r.stdout.trim();
    for (const [k, v] of Object.entries(POST01)) if (k.startsWith('lld_')) stack.wp('post', 'meta', 'update', postId, k, v);
    if (!process.env.LLD_FR103_PRISTINE) { const ap = stack.wp('lld', 'home', 'apply'); if (ap.status !== 0) throw new Error(ap.stdout + ap.stderr); }
  }
});
test.afterAll(async () => {
  if (stack && !stack.external) {
    if (postId) stack.wp('post', 'delete', postId, '--force');
    if (frontId && originalHome !== undefined) {
      const rv = stack.wp('lld', 'home', 'revert');
      const now = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
      if (rv.status !== 0 || now.trim() !== originalHome.trim()) {
        // fall back to the captured content, and fail loudly: a dirty homepage would poison the next run's pristine red
        stack.wp('post', 'update', frontId, `--post_content=${originalHome}`);
        throw new Error(`home revert did not restore the front page exactly (exit ${rv.status}): ${rv.stderr}`);
      }
    }
  }
  await stack?.close();
});
const home = () => `${stack.url}/`;
// The snapshot database still names the import origin (local-stack.ps1 wrote http://localhost:8080) in a few stored asset
// URLs; the test stack runs on its own port, so those requests are served from the live stack instead of failing. This keeps
// AS-06's console-error check strict (nothing is filtered) on the local stack; against LLD_WP_URL no mapping applies.
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeEach(async ({ page }) => {
  if (stack && !stack.external && stack.url !== IMPORT_ORIGIN) await page.route(`${IMPORT_ORIGIN}/**`, (route) => route.continue({ url: route.request().url().replace(IMPORT_ORIGIN, stack.url) }));
});

test('TC-FR1-03-AS-01', async ({ page }) => {
  await page.setViewportSize(WIDTHS.phone);
  await page.goto(home());
  expect(await page.evaluate(() => window.scrollY)).toBe(0);
  const box = await page.locator('[data-test=tools-row]').boundingBox();
  expect(box, 'Tools row rendered').toBeTruthy();
  expect(box.y, 'Tools row top within 1.5 viewport heights at 375×812').toBeLessThanOrEqual(1218);
});

test('TC-FR1-03-AS-02', async ({ page }) => {
  const paths = [];
  for (const [name, vp] of Object.entries(WIDTHS)) {
    for (const c of cards) {
      await page.setViewportSize(vp); await page.goto(home());
      const cta = page.locator(`[data-test=tools-row] [data-tool-card][data-tool="${c.tool_slug}"] [data-test=cta]`);
      await expect(cta, `${name}: ${c.tool_slug} CTA visible without scrolling actions`).toBeVisible();
      await cta.click();                                             // action 1 (DEC-30)
      await expect(page).toHaveURL(new RegExp(`${c.landing_url.replace(/\//g, '\\/')}$`));
      paths.push({ width: name, tool: c.tool_slug, actions: 1, path: ['/ → Tools row CTA', c.landing_url] });
    }
  }
  for (const p of paths) expect(p.actions, `${p.width} ${p.tool}: ≤ 2 actions`).toBeLessThanOrEqual(2);
  test.info().annotations.push({ type: 'click-paths', description: JSON.stringify(paths) });
});

test('TC-FR1-03-AS-03', async ({ page }) => {
  await page.setViewportSize(WIDTHS.phone); await page.goto(home());
  const recent = page.locator(`[data-test=learn-row] article[data-post-id="${postId}"] a`).first();
  await expect(recent, 'the recent post is in the Learn row').toBeVisible();
  const iso = await page.locator(`[data-test=learn-row] article[data-post-id="${postId}"] time`).getAttribute('datetime');
  expect((Date.now() - new Date(iso).getTime()) / 86400000, 'post date within 30 days').toBeLessThanOrEqual(30);
  await recent.click();                                              // action 1
  await expect(page.locator('body')).toContainText('FX-WP-POST-01');
  test.info().annotations.push({ type: 'click-path', description: '/ → Learn row article (1 action)' });
});

test('TC-FR1-03-AS-04', async ({ page }) => {
  await page.setViewportSize(WIDTHS.desktop); await page.goto(home());
  expect(await page.locator('form[role=search], form.td-search-form, form input[name=s], #td-header-search').count(), 'search form present').toBeGreaterThan(0);
  // post links on a results page, minus those on a no-results page (header, footer and sidebar blocks appear on both)
  const postLinks = async (q) => {
    const r = await page.goto(`${stack.url}/?s=${encodeURIComponent(q)}`); expect(r.status()).toBe(200);
    return new Set(await page.locator('a[href]').evaluateAll((as) => as.map((x) => x.href.split('#')[0])));
  };
  const control = await postLinks('zqxv-no-such-term-7741');
  const results = [...(await postLinks('protein'))].filter((h) => !control.has(h) && !/[?&]s=|\/page\/\d+/.test(h));
  expect(results.length, '?s=protein returns at least one result beyond the no-results page').toBeGreaterThanOrEqual(1);
  test.info().annotations.push({ type: 'search', description: `${results.length} result links for "protein", e.g. ${results.slice(0, 3).join(', ')}` });
});

test('TC-FR1-03-AS-05', async ({ page }) => {
  await page.setViewportSize(WIDTHS.desktop); await page.goto(home());
  const h2 = await page.locator('h2').evaluateAll((els) => els.map((e) => e.textContent.trim()));
  expect(h2).toContain('Tools'); expect(h2).toContain('Learn');
  expect(h2.indexOf('Tools'), 'Tools before Learn').toBeLessThan(h2.indexOf('Learn'));
});

test('TC-FR1-03-AS-06', async ({ page, request }) => {
  test.setTimeout(900000);   // the crawl requests every same-origin link on /; the local PHP server answers one request at a time
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.setViewportSize(WIDTHS.desktop); await page.goto(home(), { waitUntil: 'networkidle' });
  expect(errors, 'no console errors on /').toEqual([]);
  const origin = new URL(stack.url).origin;
  // every same-origin link, fragment stripped (external links are crawled on staging, OI-1 — registry known deviation)
  const hrefs = [...new Set(await page.locator('a[href]').evaluateAll((as) => as.map((x) => x.href.split('#')[0])))].filter((h) => h.startsWith(origin));
  expect(hrefs.length).toBeGreaterThan(10);
  const bad = [];
  for (const h of hrefs) { const r = await request.get(h, { maxRedirects: 5 }); if (r.status() >= 400) bad.push(`${r.status()} ${h}`); }
  expect(bad, 'same-origin links on / with status ≥ 400').toEqual([]);
  test.info().annotations.push({ type: 'crawl', description: `${hrefs.length} same-origin links checked; external links not crawled on the local stack` });
});

test('TC-FR1-03-AS-07', async ({ page }) => {
  // the Composer rows carry the classes the homepage transform sets (data/home.json): hero (desktop-only), tools, hero copy (phone-only), learn
  await page.setViewportSize(WIDTHS.phone); await page.goto(home());
  const firstVisibleRow = await page.evaluate(() => {
    const header = document.querySelector('header, .td-header-wrap, .td-header-template-wrap');
    const rows = [...document.querySelectorAll('.vc_row')].filter((r) => !r.parentElement.closest('.vc_row') && getComputedStyle(r).display !== 'none' && r.getBoundingClientRect().height > 0);   // top-level Composer rows (not the tdc_zone wrapper)
    const afterHeader = header ? rows.filter((r) => !header.contains(r) && (header.compareDocumentPosition(r) & Node.DOCUMENT_POSITION_FOLLOWING)) : rows;   // rows of the header template itself are not content rows
    const first = afterHeader[0];
    return first ? { hasTools: !!first.querySelector('[data-test=tools-row]'), cls: first.className } : null;
  });
  expect(firstVisibleRow, 'a content row after the header').toBeTruthy();
  expect(firstVisibleRow.hasTools, `375 px: first row after header is the Tools row (got ${firstVisibleRow?.cls})`).toBe(true);
  await page.setViewportSize(WIDTHS.desktop); await page.goto(home());
  const prev = await page.evaluate(() => {
    // Composer wraps each row, so "previous sibling" is the previous top-level row in document order (header rows excluded)
    const header = document.querySelector('header, .td-header-wrap, .td-header-template-wrap');
    const rows = [...document.querySelectorAll('.vc_row')].filter((r) => !r.parentElement.closest('.vc_row') && !(header && header.contains(r)));
    const i = rows.findIndex((r) => r.querySelector('[data-test=tools-row]'));
    const p = i > 0 ? rows[i - 1] : null;
    return p ? { cls: p.className, visible: getComputedStyle(p).display !== 'none' && p.getBoundingClientRect().height > 0 } : null;
  });
  expect(prev, '1440 px: previous sibling of the Tools row').toBeTruthy();
  expect(prev.cls, 'previous sibling is the hero block').toMatch(/lld-home-hero(?!-phone)/);
  expect(prev.visible).toBe(true);
});
