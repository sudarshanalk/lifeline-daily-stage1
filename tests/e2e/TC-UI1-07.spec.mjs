// TC-UI1-07 Navigation and educational links — crawl + e2e on the local WordPress stack (DEC-69; homepage rows, landing pages and
// the disclaimer page applied and restored). AS-01 a same-origin crawl from / to depth 3: no status >= 400 and no redirect loop
// (each redirect chain is followed hop by hop); AS-02 each landing page's breadcrumb reads Home › Health Tools › {tool} and every
// crumb link answers 200; AS-03 every link in the unavailable states (the withdrawn landing-page card and the withdrawn tool page)
// answers 200.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const cards = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let stack, frontId, originalHome, createdDisclaimer = null; const before = {};
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeAll(async () => {
  stack = await startWpStack();
  if (stack.external) return;
  for (const id of Object.values(PAGES)) before[id] = { content: stack.wp('post', 'get', String(id), '--field=post_content').stdout, slug: stack.wp('post', 'meta', 'get', String(id), 'lld_tool_slug').stdout.trim() };
  frontId = stack.wp('option', 'get', 'page_on_front').stdout.trim(); originalHome = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
  const existed = stack.wp('post', 'list', '--post_type=page', '--name=medical-disclaimer', '--post_status=any', '--field=ID').stdout.trim();
  for (const cmd of [['pages', 'apply'], ['home', 'apply']]) { const r = stack.wp('lld', ...cmd); if (r.status !== 0) throw new Error(r.stdout + r.stderr); }
  if (!existed) createdDisclaimer = stack.wp('post', 'list', '--post_type=page', '--name=medical-disclaimer', '--field=ID').stdout.trim();
});
test.afterAll(async () => {
  let err = null;
  if (stack && !stack.external) {
    for (const [id, b] of Object.entries(before)) { stack.wp('post', 'update', id, `--post_content=${b.content.replace(/\n$/, '')}`); b.slug ? stack.wp('post', 'meta', 'update', id, 'lld_tool_slug', b.slug) : stack.wp('post', 'meta', 'delete', id, 'lld_tool_slug'); }
    if (createdDisclaimer) stack.wp('post', 'delete', createdDisclaimer, '--force');
    stack.wp('lld', 'status', 'set', 'diabetes', 'active', `--version=${status.tools.diabetes.approved_version}`);
    const rv = stack.wp('lld', 'home', 'revert');
    if (rv.status !== 0 || stack.wp('post', 'get', frontId, '--field=post_content').stdout.trim() !== originalHome.trim()) { stack.wp('post', 'update', frontId, `--post_content=${originalHome}`); err = new Error('home revert did not restore the front page exactly'); }
  }
  await stack?.close();
  if (err) throw err;
});
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });
const norm = (u, base) => { try { const x = new URL(u.replace(IMPORT_ORIGIN, stack.url), base); x.hash = ''; return x.href; } catch { return null; } };

test('TC-UI1-07-AS-01', async ({ request }) => {
  test.setTimeout(3600000);
  const origin = new URL(stack.url).origin;
  const seen = new Map(); const queue = [[`${stack.url}/`, 0]]; const bad = [], loops = [];
  const skip = (u) => /\/wp-(admin|login|json)|\/feed\/?$|[?&](replytocom|s)=|\/xmlrpc\.php|\.(jpe?g|png|gif|webp|svg|css|js|pdf|xml|ico|woff2?)(\?|$)/i.test(u);
  while (queue.length) {
    const [url, depth] = queue.shift();
    if (seen.has(url)) continue;
    // follow redirects hop by hop: a revisit inside one chain is a loop
    let cur = url, chain = [], res = null;
    for (let hop = 0; hop < 10; hop++) {
      if (chain.includes(cur)) { loops.push(`${url}: ${chain.join(' → ')} → ${cur}`); res = null; break; }
      chain.push(cur);
      res = await request.get(cur, { maxRedirects: 0, failOnStatusCode: false });
      if (res.status() >= 300 && res.status() < 400 && res.headers().location) { cur = norm(res.headers().location, cur); if (!cur || !cur.startsWith(origin)) break; continue; }
      break;
    }
    seen.set(url, res ? res.status() : 'loop');
    if (res && res.status() >= 400) bad.push(`${res.status()} ${url}`);
    if (!res || depth >= 3 || !(res.headers()['content-type'] || '').includes('text/html')) continue;
    const html = await res.text();
    for (const m of html.matchAll(/<a\b[^>]*\bhref=["']([^"'#]+)/gi)) {
      const u = norm(m[1], cur);
      if (u && u.startsWith(origin) && !skip(u) && !seen.has(u)) queue.push([u, depth + 1]);
    }
  }
  test.info().annotations.push({ type: 'crawl', description: `${seen.size} same-origin URLs crawled to depth 3` });
  expect(seen.size, 'crawl reached the site').toBeGreaterThan(50);
  expect(bad, 'no status >= 400').toEqual([]);
  expect(loops, 'no redirect loops').toEqual([]);
});

test('TC-UI1-07-AS-02', async ({ page, request }) => {
  for (const c of cards) {
    await page.goto(stack.url + c.landing_url, { waitUntil: 'load' });
    const crumbs = page.locator('.entry-crumbs').first();
    await expect(crumbs, `${c.tool_slug}: breadcrumb`).toBeVisible();
    const parts = await crumbs.locator('a, .td-bred-no-url-last, .entry-crumb').evaluateAll((els) => els.map((e) => ({ text: e.textContent.trim(), href: e.tagName === 'A' ? e.getAttribute('href') : null })).filter((p) => p.text));
    const texts = [...new Set(parts.map((p) => p.text.toLowerCase()))];
    expect(texts, `${c.tool_slug}: Home › Health Tools › ${c.name}`).toEqual(['home', 'health tools', c.name.toLowerCase()]);
    for (const p of parts.filter((x) => x.href)) expect((await request.get(new URL(p.href, stack.url).href)).status(), `${c.tool_slug}: crumb ${p.text}`).toBe(200);
  }
});

test('TC-UI1-07-AS-03', async ({ page, request }) => {
  const r = stack.wp('lld', 'status', 'set', 'diabetes', 'withdrawn'); if (r.status !== 0) throw new Error(r.stdout + r.stderr);
  const links = new Set();
  for (const url of [`${stack.url}/health-tools/diabetes-risk-check/`, `${stack.url}/tools/diabetes-risk-tool.html`]) {
    await page.goto(url, { waitUntil: 'load' });
    await expect(page.locator('[data-test=unavailable]')).toBeVisible();
    for (const h of await page.locator('[data-test=unavailable] a').evaluateAll((as) => as.map((x) => x.href))) links.add(h.replace(IMPORT_ORIGIN, stack.url));
  }
  expect(links.size, 'unavailable states carry links').toBeGreaterThanOrEqual(2);
  for (const h of links) expect((await request.get(h)).status(), `${h} → 200`).toBe(200);
});
