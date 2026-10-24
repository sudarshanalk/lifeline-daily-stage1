// TC-NFR1-12 Browser and device support (DEC-18). AS-01 runs in every configured Playwright project — chromium (Desktop
// Chrome), webkit (Desktop Safari), firefox (Desktop Firefox), pixel5 (Pixel 5) and iphone12 (iPhone 12) — and completes
// FX-PR-NRM-01 and FX-DM-QD-01 on the standalone tools (htaccess emulator). The non-chromium projects exist only where their
// browsers are installed (tests/e2e/playwright.config.mjs); CI installs chromium only, so the full matrix runs locally.
// AS-02 (chromium project): at 320 px no horizontal scroll on the 6 pages — home, hub, both landing pages (local WordPress
// stack; homepage rows via `wp lld home apply`, restored with `home revert`) and both standalone tools.
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
  protein: { file: 'protein-requirement-tool.html', fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, form: '#form' },
  diabetes: { file: 'diabetes-risk-tool.html', fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, form: '#tool' },
};
test.describe.configure({ mode: 'serial' });
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

test('TC-NFR1-12-AS-01', async ({ page }, info) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.goto(`${srv.url}/tools/${t.file}`);
    await expect(page.locator(t.form), `${info.project.name}: ${slug} form`).toBeVisible();
    await t.fill(page, t.fixture.inputs); await t.submit(page);
    await expect(page.locator('#results'), `${info.project.name}: ${slug} result for ${t.fixture.id}`).toBeVisible();
    expect((await page.locator('#results').innerText()).trim().length, `${info.project.name}: ${slug} result has content`).toBeGreaterThan(20);
  }
  info.annotations.push({ type: 'project', description: info.project.name });
});

test('TC-NFR1-12-AS-02', async ({ page }, info) => {
  test.skip(info.project.name !== 'chromium', 'viewport check runs once, in the chromium project');
  const wp = available();
  test.skip(!wp.ok, wp.reason);
  test.setTimeout(600000);
  const stack = await startWpStack();
  let applied = false; const before = {};
  const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };   // restored afterwards, as TC-FR1-04 does
  try {
    if (!stack.external) for (const id of Object.values(PAGES)) before[id] = { content: stack.wp('post', 'get', String(id), '--field=post_content').stdout, slug: stack.wp('post', 'meta', 'get', String(id), 'lld_tool_slug').stdout.trim() };
    if (!stack.external) { const r = stack.wp('lld', 'home', 'apply'); if (r.status !== 0) throw new Error(r.stdout + r.stderr); applied = true; const p = stack.wp('lld', 'pages', 'apply'); if (p.status !== 0) throw new Error(p.stdout + p.stderr); }
    const IMPORT_ORIGIN = 'http://localhost:8080';
    if (!stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) }));
    await page.setViewportSize({ width: 320, height: 640 });
    const pages = [`${stack.url}/`, `${stack.url}/health-tools/`, `${stack.url}/health-tools/protein/`, `${stack.url}/health-tools/diabetes-risk-check/`, `${srv.url}/tools/${TOOLS.protein.file}`, `${srv.url}/tools/${TOOLS.diabetes.file}`];
    const over = [];
    for (const url of pages) {
      await page.goto(url, { waitUntil: 'load' });
      const m = await page.evaluate(() => ({ sw: document.scrollingElement.scrollWidth, iw: window.innerWidth }));
      if (m.sw > m.iw) over.push(`${url}: scrollWidth ${m.sw} > innerWidth ${m.iw}`);
    }
    expect(over, '320 px: no horizontal scroll on the 6 pages').toEqual([]);
  } finally {
    if (applied) stack.wp('lld', 'home', 'revert');
    for (const [id, b] of Object.entries(before)) { stack.wp('post', 'update', id, `--post_content=${b.content}`); b.slug ? stack.wp('post', 'meta', 'update', id, 'lld_tool_slug', b.slug) : stack.wp('post', 'meta', 'delete', id, 'lld_tool_slug'); }
    await stack.close();
  }
});
