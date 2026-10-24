// TC-FR1-05 Blog-to-tool and tool-to-blog linking — wpcli + crawl + e2e against the local WordPress stack (DEC-69 proposal).
// Fixtures: FX-WP-POST-01…05, 07…10 seeded for the run (removed afterwards; FX-WP-POST-06 belongs to FR1-18). DEC-13 rule data:
// the plugin's data/links.json. The automatic CTA is toggled in the harness copy of that file (never the repository) for AS-05.
import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { validateFile } from '../lib/validate.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const site = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures;
const fx = (id) => site.find((f) => f.id === id).inputs;
const cards = Object.fromEntries(JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards.map((c) => [c.tool_slug, c]));
const EXPECTED = { 'FX-WP-POST-01': 'protein', 'FX-WP-POST-02': 'diabetes', 'FX-WP-POST-03': 'none', 'FX-WP-POST-04': 'none', 'FX-WP-POST-07': 'diabetes', 'FX-WP-POST-09': 'diabetes', 'FX-WP-POST-10': 'diabetes' };
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let stack; const seeded = {}; let linkMap;
const ok = (r, what) => { if (r.status !== 0) throw new Error(`${what}: ${r.stdout}${r.stderr}`); return r.stdout.trim(); };
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });

// the landing pages carry Related reading only once `wp lld pages apply` has run; apply it for the run and restore the pages
// (content and the lld_tool_slug marker) afterwards, as TC-FR1-04 does
const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
const pagesBefore = {};
function applyPages(stack) {
  for (const id of Object.values(PAGES)) pagesBefore[id] = { content: stack.wp('post', 'get', String(id), '--field=post_content').stdout, slug: stack.wp('post', 'meta', 'get', String(id), 'lld_tool_slug').stdout.trim() };
  const r = stack.wp('lld', 'pages', 'apply'); if (r.status !== 0) throw new Error(r.stdout + r.stderr);
}
function restorePages(stack) {
  for (const [id, b] of Object.entries(pagesBefore)) {
    stack.wp('post', 'update', id, `--post_content=${b.content}`);
    b.slug ? stack.wp('post', 'meta', 'update', id, 'lld_tool_slug', b.slug) : stack.wp('post', 'meta', 'delete', id, 'lld_tool_slug');
  }
}
test.beforeAll(async () => {
  stack = await startWpStack();
  if (stack.external) return;
  applyPages(stack);
  const termId = (tax, slug) => stack.wp('term', 'list', tax, `--slug=${slug}`, '--field=term_id').stdout.trim();
  const seed = (id, inputs, extraMeta = {}) => {
    const pid = ok(stack.wp('post', 'create', '--post_type=post', `--post_status=${inputs.status}`, `--post_title=${id} (TC-FR1-05 seed)`,
      `--post_content=<p>Intro paragraph of ${id}.</p><h2>First section</h2><p>Body of ${id}; seeded by TC-FR1-05 and removed after the run.</p>`,
      `--post_category=${termId('category', inputs.category)}`, '--porcelain'), `seed ${id}`);
    if (inputs.tags) ok(stack.wp('post', 'term', 'set', pid, 'post_tag', ...inputs.tags), `${id} tags`);
    for (const [k, v] of Object.entries({ ...inputs, ...extraMeta })) if (k.startsWith('lld_')) ok(stack.wp('post', 'meta', 'update', pid, k, v), `${id} ${k}`);
    seeded[id] = pid;
  };
  for (const id of ['FX-WP-POST-01', 'FX-WP-POST-02', 'FX-WP-POST-03', 'FX-WP-POST-04', 'FX-WP-POST-07', 'FX-WP-POST-08', 'FX-WP-POST-09', 'FX-WP-POST-10']) seed(id, fx(id));
  seed('FX-WP-POST-05', fx('FX-WP-POST-05'), { lld_review_status: 'approved' });   // a draft that is otherwise eligible
  linkMap = JSON.parse(ok(stack.wp('lld', 'links', 'generate'), 'links generate'));
});
test.afterAll(async () => {
  if (stack && !stack.external) { restorePages(stack); for (const pid of Object.values(seeded)) stack.wp('post', 'delete', pid, '--force'); }
  await stack?.close();
});
const decisionOf = (pid) => linkMap.links.find((l) => String(l.wp_post_id) === String(pid))?.decision;

test('TC-FR1-05-AS-01', async ({ page }) => {
  test.setTimeout(1800000);   // crawls every published post through the single-threaded local server
  const dir = mkdtempSync(join(tmpdir(), 'lld-links-'));
  try {
    const f = join(dir, 'article-tool-links.json'); writeFileSync(f, JSON.stringify(linkMap));
    const v = validateFile('article-tool-link.schema.json', f); expect(v.ok, v.errors?.join('; ')).toBe(true);
  } finally { rmSync(dir, { recursive: true, force: true }); }
  const published = ok(stack.wp('post', 'list', '--post_type=post', '--post_author=1', '--post_status=publish', '--field=ID', '--format=csv'), 'post list').split(/\s+/).filter(Boolean);
  expect(linkMap.links.map((l) => String(l.wp_post_id)).sort(), 'link map covers every published post').toEqual([...published].sort());
  const mismatches = [];
  for (const pid of published) {
    await page.goto(`${stack.url}/?p=${pid}`, { waitUntil: 'domcontentloaded' });
    const has = (await page.locator('[data-test=tool-cta]').count()) > 0;
    const d = decisionOf(pid);
    if (has !== (d !== 'none')) mismatches.push(`${pid}: decision ${d}, CTA ${has}`);
    if (has) { const tool = await page.locator('[data-test=tool-cta]').first().getAttribute('data-tool'); if (tool !== d) mismatches.push(`${pid}: CTA ${tool} ≠ decision ${d}`); }
  }
  expect(mismatches, 'CTA present ⇔ link-map decision ≠ none, for every published post').toEqual([]);
  test.info().annotations.push({ type: 'crawl', description: `${published.length} published posts crawled` });
});

test('TC-FR1-05-AS-02', async ({ page }) => {
  test.setTimeout(300000);   // 11 article page loads through the single-threaded local PHP server; 60 s was too tight under load
  for (const [id, want] of Object.entries(EXPECTED)) {
    expect(decisionOf(seeded[id]), `${id}: link-map decision`).toBe(want);
    await page.goto(`${stack.url}/?p=${seeded[id]}`);
    const cta = page.locator('[data-test=tool-cta]');
    if (want === 'none') await expect(cta, `${id}: no CTA`).toHaveCount(0);
    else await expect(cta, `${id}: ${want} CTA`).toHaveAttribute('data-tool', want);
  }
  expect(linkMap.links.find((l) => String(l.wp_post_id) === seeded['FX-WP-POST-04']).source, 'override recorded as the source').toBe('override');
});

test('TC-FR1-05-AS-03', async ({ page, request }) => {
  for (const id of ['FX-WP-POST-01', 'FX-WP-POST-02']) {
    await page.goto(`${stack.url}/?p=${seeded[id]}`);
    const href = await page.locator('[data-test=tool-cta] a').getAttribute('href');
    const want = cards[EXPECTED[id]].landing_url + `?utm_source=article&utm_content=${seeded[id]}`;
    expect(href, `${id}: CTA href`).toBe(want);
    expect((await request.get(new URL(href, stack.url).href)).status(), `${id}: CTA target`).toBe(200);
  }
});

test('TC-FR1-05-AS-04', async ({ page }) => {
  for (const slug of ['protein', 'diabetes']) {
    await page.goto(stack.url + cards[slug].landing_url);
    const related = page.locator('[data-test=related-reading]');
    await expect(related).toBeVisible();
    for (const id of ['FX-WP-POST-05', 'FX-WP-POST-08']) expect(await related.locator(`[data-post-id="${seeded[id]}"]`).count(), `${slug}: ${id} excluded`).toBe(0);
    expect(await related.locator('li').count(), `${slug}: at most 3`).toBeLessThanOrEqual(3);
  }
  // positive control: the approved protein post (FX-WP-POST-01) is listed on the protein page
  await page.goto(stack.url + cards.protein.landing_url);
  await expect(page.locator(`[data-test=related-reading] [data-post-id="${seeded['FX-WP-POST-01']}"]`)).toHaveCount(1);
});

test('TC-FR1-05-AS-05', async ({ page }) => {
  const linksPath = join(stack.muDir, 'lld-tools', 'data', 'links.json');
  const original = readFileSync(linksPath, 'utf8');
  const bodyHash = async (pid, stripCta) => {
    await page.goto(`${stack.url}/?p=${pid}`);
    const html = await page.locator('.td-post-content, .entry-content, article .post-content').first().evaluate((el, strip) => {
      const c = el.cloneNode(true);
      if (strip) c.querySelectorAll('[data-test=tool-cta]').forEach((n) => n.remove());
      c.querySelectorAll('[data-test=review-byline]').forEach((n) => n.remove());
      return c.innerHTML.replace(/\s+/g, ' ').trim();
    }, stripCta);
    return createHash('sha256').update(html).digest('hex');
  };
  try {
    const doc = JSON.parse(original); doc.auto_cta = false; writeFileSync(linksPath, JSON.stringify(doc));
    const before = {}; for (const id of ['FX-WP-POST-01', 'FX-WP-POST-02']) before[id] = await bodyHash(seeded[id], false);
    writeFileSync(linksPath, original);
    for (const id of ['FX-WP-POST-01', 'FX-WP-POST-02']) expect(await bodyHash(seeded[id], true), `${id}: content without the CTA is unchanged`).toBe(before[id]);
  } finally { writeFileSync(linksPath, original); }
});

test('TC-FR1-05-AS-06', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  for (const id of ['FX-WP-POST-01', 'FX-WP-POST-02']) {
    await page.goto(`${stack.url}/?p=${seeded[id]}`);
    const cta = page.locator('[data-test=tool-cta]');
    await expect(cta).toBeVisible();
    const box = await cta.boundingBox();
    expect(box.x + box.width, `${id}: CTA within 320 px`).toBeLessThanOrEqual(320);
    expect(await cta.evaluate((el) => el.scrollWidth <= el.clientWidth), `${id}: CTA does not overflow`).toBe(true);
  }
});

test('NEG-01', async ({ page }) => {
  // publish FX-WP-POST-05 without a review status → not in Related reading until approved
  const pid = seeded['FX-WP-POST-05'];
  ok(stack.wp('post', 'meta', 'delete', pid, 'lld_review_status'), 'clear status');
  ok(stack.wp('post', 'update', pid, '--post_status=publish'), 'publish');
  await page.goto(stack.url + cards.protein.landing_url);
  await expect(page.locator(`[data-test=related-reading] [data-post-id="${pid}"]`), 'published but unreviewed: excluded').toHaveCount(0);
  ok(stack.wp('post', 'meta', 'update', pid, 'lld_review_status', 'approved'), 'approve');
  await page.goto(stack.url + cards.protein.landing_url);
  await expect(page.locator(`[data-test=related-reading] [data-post-id="${pid}"]`), 'approved: listed').toHaveCount(1);
});
