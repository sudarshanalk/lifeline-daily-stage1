// TC-UI1-01 Homepage hierarchy — e2e + visual against the local WordPress stack (DEC-69). The homepage rows are applied with
// `wp lld home apply` and reverted afterwards (byte-identical check, as TC-FR1-03). AS-01 re-runs TC-FR1-03 AS-01/02/03/05;
// AS-02 the Tools row holds exactly 2 cards at 320/375/768/1440 and the Learn row ≥ 4 articles; AS-03 the homepage's first
// screen at each width matches its baseline within 0.5 % (baselines are platform-specific, DEC-58, kept next to this spec
// instead of design/baselines/ — registry known deviation; a runner without baselines skips with the reason).
// Fixtures: FX-WP-POST-01 (fixtures/site/site.json) seeded dated today for AS-01's recent-post path, deleted afterwards.
import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const POST01 = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures.find((f) => f.id === 'FX-WP-POST-01').inputs;
const cards = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const WIDTHS = [320, 375, 768, 1440];
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let stack, frontId, originalHome, postId;
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });
test.beforeAll(async () => {
  stack = await startWpStack();
  if (stack.external) return;
  frontId = stack.wp('option', 'get', 'page_on_front').stdout.trim();
  originalHome = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
  const cat = stack.wp('term', 'list', 'category', `--slug=${POST01.category}`, '--field=term_id').stdout.trim();
  const r = stack.wp('post', 'create', '--post_type=post', '--post_author=1', `--post_status=${POST01.status}`, '--post_title=FX-WP-POST-01 recent post (UI1-01 seed)', '--post_content=<p>Seeded by TC-UI1-01; removed after the run.</p>', `--post_category=${cat}`, '--porcelain');
  if (r.status !== 0) throw new Error(r.stdout + r.stderr);
  postId = r.stdout.trim();
  const ap = stack.wp('lld', 'home', 'apply'); if (ap.status !== 0) throw new Error(ap.stdout + ap.stderr);
});
test.afterAll(async () => {
  if (stack && !stack.external) {
    if (postId) stack.wp('post', 'delete', postId, '--force');
    if (frontId) {
      const rv = stack.wp('lld', 'home', 'revert');
      const now = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
      if (rv.status !== 0 || now.trim() !== originalHome.trim()) { stack.wp('post', 'update', frontId, `--post_content=${originalHome}`); throw new Error('home revert did not restore the front page exactly'); }
    }
  }
  await stack?.close();
});
const home = () => `${stack.url}/`;

test('TC-UI1-01-AS-01', async ({ page }) => {
  test.setTimeout(300000);   // several page loads through the single-threaded local server
  // TC-FR1-03-AS-01: Tools row within 1.5 viewport heights at 375×812
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto(home());
  const box = await page.locator('[data-test=tools-row]').boundingBox();
  expect(box && box.y, 'AS-01 (FR1-03 AS-01): Tools row top ≤ 1218 px').toBeLessThanOrEqual(1218);
  // TC-FR1-03-AS-02: each landing page in ≤ 2 actions at 375 and 1440
  for (const width of [375, 1440]) for (const c of cards) {
    await page.setViewportSize({ width, height: 900 }); await page.goto(home());
    await page.locator(`[data-test=tools-row] [data-tool="${c.tool_slug}"] [data-test=cta]`).click();
    await expect(page, `AS-01 (FR1-03 AS-02): ${c.tool_slug} at ${width} in 1 action`).toHaveURL(new RegExp(`${c.landing_url.replace(/\//g, '\\/')}$`));
  }
  // TC-FR1-03-AS-03: a post from the last 30 days in ≤ 3 actions
  await page.setViewportSize({ width: 375, height: 812 }); await page.goto(home());
  await page.locator(`[data-test=learn-row] article[data-post-id="${postId}"] a`).click();
  await expect(page.locator('body'), 'AS-01 (FR1-03 AS-03): recent post in 1 action').toContainText('FX-WP-POST-01');
  // TC-FR1-03-AS-05: h2 "Tools" before "Learn"
  await page.goto(home());
  const h2 = await page.locator('h2').evaluateAll((els) => els.map((e) => e.textContent.trim()));
  expect(h2.indexOf('Tools'), 'AS-01 (FR1-03 AS-05): Tools before Learn').toBeGreaterThanOrEqual(0);
  expect(h2.indexOf('Tools')).toBeLessThan(h2.indexOf('Learn'));
});

test('TC-UI1-01-AS-02', async ({ page }) => {
  test.setTimeout(300000);   // several page loads through the single-threaded local server
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 }); await page.goto(home());
    await expect(page.locator('[data-test=tools-row] [data-tool-card]'), `${width}: exactly 2 tool cards`).toHaveCount(2);
    for (const c of await page.locator('[data-test=tools-row] [data-tool-card]').all()) await expect(c, `${width}: card visible`).toBeVisible();
    expect(await page.locator('[data-test=learn-row] article').count(), `${width}: Learn row has ≥ 4 articles`).toBeGreaterThanOrEqual(4);
  }
});

test('TC-UI1-01-AS-03', async ({ page }, testInfo) => {
  test.setTimeout(300000);
  const missing = WIDTHS.filter((w) => !existsSync(testInfo.snapshotPath(`home-${w}.png`)));
  test.skip(missing.length > 0 && ['missing', 'none'].includes(testInfo.config.updateSnapshots) && process.env.LLD_RECORD_BASELINES !== '1', `no ${process.platform} baselines recorded for widths ${missing.join(', ')} (record with LLD_RECORD_BASELINES=1 --update-snapshots on the reference runner)`);
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 }); await page.goto(home(), { waitUntil: 'networkidle' });
    // the seeded post's date and the Learn row's dates change with the run day: masked
    await expect(page).toHaveScreenshot(`home-${width}.png`, { maxDiffPixelRatio: 0.005, animations: 'disabled', caret: 'hide', mask: [page.locator('[data-test=learn-row] time'), page.locator(`[data-test=learn-row] article[data-post-id="${postId}"]`)] });
  }
});
