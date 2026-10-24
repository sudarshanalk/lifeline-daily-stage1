// TC-UI1-09 Visual consistency — visual family (AS-02): 24 snapshots (6 pages × 320/375/768/1440) within 0.5 % of their
// baselines. Pages: home, hub and both landing pages (local WordPress stack, DEC-69; homepage rows via `wp lld home apply` and
// landing pages via `wp lld pages apply`, both restored) and both standalone tools (htaccess emulator). The baselines are
// CANDIDATES recorded by the implementer on win32 (DEC-58), kept next to this spec; the SRS asks for baselines approved at
// the human gate, so AS-02 is recorded as deferred until HG-02 signs the set (proposed DEC-77) while the comparison runs.
import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const WIDTHS = [320, 375, 768, 1440];
const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let srv, stack, frontId, originalHome; const before = {};
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeAll(async () => {
  srv = await startHtaccessServer();
  stack = await startWpStack();
  if (stack.external) return;
  for (const id of Object.values(PAGES)) before[id] = { content: stack.wp('post', 'get', String(id), '--field=post_content').stdout, slug: stack.wp('post', 'meta', 'get', String(id), 'lld_tool_slug').stdout.trim() };
  frontId = stack.wp('option', 'get', 'page_on_front').stdout.trim(); originalHome = stack.wp('post', 'get', frontId, '--field=post_content').stdout;
  for (const cmd of [['pages', 'apply'], ['home', 'apply']]) { const r = stack.wp('lld', ...cmd); if (r.status !== 0) throw new Error(r.stdout + r.stderr); }
});
test.afterAll(async () => {
  let err = null;
  if (stack && !stack.external) {
    for (const [id, b] of Object.entries(before)) { stack.wp('post', 'update', id, `--post_content=${b.content.replace(/\n$/, '')}`); b.slug ? stack.wp('post', 'meta', 'update', id, 'lld_tool_slug', b.slug) : stack.wp('post', 'meta', 'delete', id, 'lld_tool_slug'); }
    const rv = stack.wp('lld', 'home', 'revert');
    if (rv.status !== 0 || stack.wp('post', 'get', frontId, '--field=post_content').stdout.trim() !== originalHome.trim()) { stack.wp('post', 'update', frontId, `--post_content=${originalHome}`); err = new Error('home revert did not restore the front page exactly'); }
  }
  await stack?.close(); await srv?.close();
  if (err) throw err;
});
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });

test('TC-UI1-09-AS-02', async ({ page }, testInfo) => {
  test.setTimeout(900000);
  const shots = [];
  for (const w of WIDTHS) for (const [name, url] of [['home', () => `${stack.url}/`], ['hub', () => `${stack.url}/health-tools/`], ['landing-protein', () => `${stack.url}/health-tools/protein/`], ['landing-diabetes', () => `${stack.url}/health-tools/diabetes-risk-check/`], ['tool-protein', () => `${srv.url}/tools/protein-requirement-tool.html`], ['tool-diabetes', () => `${srv.url}/tools/diabetes-risk-tool.html`]]) shots.push({ file: `${name}-${w}.png`, w, url });
  expect(shots.length, '24 snapshots').toBe(24);
  const missing = shots.filter((s) => !existsSync(testInfo.snapshotPath(s.file))).map((s) => s.file);
  test.skip(missing.length > 0 && ['missing', 'none'].includes(testInfo.config.updateSnapshots) && process.env.LLD_RECORD_BASELINES !== '1', `no ${process.platform} baselines for ${missing.length} snapshots (record with LLD_RECORD_BASELINES=1 --update-snapshots on the reference runner)`);
  for (const s of shots) {
    await page.setViewportSize({ width: s.w, height: 900 }); await page.goto(s.url(), { waitUntil: 'networkidle' });
    await page.evaluate(() => Promise.all(document.getAnimations().map((x) => x.finished.catch(() => {}))));
    // first screen; dates in the homepage Learn row change with the run day and are masked
    await expect(page, s.file).toHaveScreenshot(s.file, { maxDiffPixelRatio: 0.005, animations: 'disabled', caret: 'hide', mask: [page.locator('[data-test=learn-row] time'), page.locator('.td-post-date')] });
  }
});
