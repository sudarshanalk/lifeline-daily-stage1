// TC-FR1-15 Safe withdrawal and rollback — e2e + wpcli against the local WordPress test stack (tests/lib/wp-stack.mjs; the
// overlay is a temp copy, so `wp lld status set` writes a throw-away tools/status.json). Fixtures: status.json variants,
// FX-PR-NRM-01, FX-DM-QD-01. Skips with the reason on runners without the snapshot (CI until OI-1); with LLD_WP_URL the
// WP-CLI steps cannot run and AS-01/02/03 skip (staging drill is the owner's, OI-1).
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const NRM01 = fx('protein', 'nrm.json', 'FX-PR-NRM-01'); const QD01 = fx('diabetes', 'qd.json', 'FX-DM-QD-01');
const DECK = JSON.parse(readFileSync(resolve(ROOT, 'copy', 'diabetes-messages.json'), 'utf8'));
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const PAGE_IDS = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
let stack; const original = {};
test.beforeAll(async () => {
  const a = available(); test.skip(!a.ok, a.reason);
  stack = await startWpStack();
  test.skip(stack.external, 'WP-CLI withdrawal steps need the local stack; the staging drill is the owner\'s (OI-1)');
  for (const id of Object.values(PAGE_IDS)) original[id] = stack.wp('post', 'get', String(id), '--field=post_content').stdout;
  const r = stack.wp('lld', 'pages', 'apply'); if (r.status !== 0) throw new Error(r.stdout + r.stderr);
});
test.afterAll(async () => {
  if (stack && !stack.external) for (const [id, content] of Object.entries(original)) stack.wp('post', 'update', id, `--post_content=${content}`);
  await stack?.close();
});
test.describe.configure({ mode: 'serial' });
const read = () => JSON.parse(readFileSync(stack.statusPath, 'utf8'));
const post = (path, body) => fetch(stack.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Forwarded-For': '10.15.0.1' }, body: JSON.stringify(body) });

test('TC-FR1-15-AS-01', async ({ page }) => {
  const t0 = Date.now();
  const r = stack.wp('lld', 'status', 'set', 'diabetes', 'withdrawn', '--message=unavailable.diabetes');
  expect(r.status, r.stdout + r.stderr).toBe(0);
  expect(read().tools.diabetes.status).toBe('withdrawn');
  // hub card: withdrawn label
  await page.goto(`${stack.url}/health-tools/`);
  const card = page.locator('[data-tool-card][data-tool="diabetes"]');
  await expect(card).toHaveAttribute('data-status', 'withdrawn');
  await expect(card.locator('[data-test=status-label]')).toHaveText('Temporarily unavailable');
  // landing page: no iframe, unavailable card
  await page.goto(`${stack.url}/health-tools/diabetes-risk-check/`);
  expect(await page.locator('iframe').count(), 'no iframe on the withdrawn landing page').toBe(0);
  await expect(page.locator('[data-test=unavailable]')).toBeVisible();
  // direct tool URL: unavailable state, no form
  await page.goto(`${stack.url}/tools/diabetes-risk-tool.html`);
  await expect(page.locator('[data-test=unavailable]')).toBeVisible();
  expect(await page.locator('form:visible').count(), 'no form').toBe(0);
  // endpoint: 503, no mail
  const r503 = await post('/tools/submit.php', fx('mail', 'mail.json', 'FX-MAIL-02').inputs);
  expect(r503.status).toBe(503); expect((await r503.json()).status).toBe('withdrawn');
  // protein unchanged: FX-PR-NRM-01 passes
  await page.goto(`${stack.url}/tools/protein-requirement-tool.html`);
  await expect(page.locator('#form')).toBeVisible();
  await fillProteinForm(page, NRM01.inputs); await submitProtein(page);
  await expect(page.locator('#results')).toBeVisible();
  expect(Date.now() - t0, 'visible within 60 s').toBeLessThan(60000);
});

test('TC-FR1-15-AS-02', async ({ page }) => {
  const r = stack.wp('lld', 'status', 'set', 'diabetes', 'active', `--version=${status.tools.diabetes.approved_version}`);
  expect(r.status, r.stdout + r.stderr).toBe(0);
  expect(read().tools.diabetes.status).toBe('active');
  await page.goto(`${stack.url}/health-tools/`);
  await expect(page.locator('[data-tool-card][data-tool="diabetes"]')).toHaveAttribute('data-status', 'active');
  await page.goto(`${stack.url}/health-tools/diabetes-risk-check/`);
  expect(await page.locator('iframe.lld-tool-frame').count()).toBe(1);
  await page.goto(`${stack.url}/tools/diabetes-risk-tool.html`);
  await expect(page.locator('#tool')).toBeVisible();
  await fillDiabetesForm(page, QD01.inputs); await submitDiabetes(page);
  await expect(page.locator('#results')).toBeVisible();
  const ok = await post('/tools/submit.php', fx('mail', 'mail.json', 'FX-MAIL-02').inputs);
  expect(ok.status).toBe(200);
});

test('TC-FR1-15-AS-03', async () => {
  const before = readFileSync(stack.statusPath);
  const r = stack.wp('lld', 'status', 'set', 'diabetes', 'active', '--version=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
  expect(r.status, 'unapproved version is refused').not.toBe(0);
  expect(r.stdout + r.stderr).toMatch(/not an approved version/);
  expect(Buffer.compare(readFileSync(stack.statusPath), before), 'status.json byte-identical').toBe(0);
  const bad = stack.wp('lld', 'status', 'set', 'diabetes', 'active');
  expect(bad.status, 'active without --version is refused').not.toBe(0);
  expect(Buffer.compare(readFileSync(stack.statusPath), before)).toBe(0);
});

test('TC-FR1-15-AS-04', async ({ page, request }) => {
  stack.wp('lld', 'status', 'set', 'diabetes', 'withdrawn');
  try {
    for (const url of [`${stack.url}/health-tools/diabetes-risk-check/`, `${stack.url}/tools/diabetes-risk-tool.html`]) {
      await page.goto(url);
      await expect(page.locator('[data-test=unavailable]')).toBeVisible();
      // the card renders approved copy for the status.json message KEY, never the key or free text (DEC-72)
      expect((await page.locator('[data-test=unavailable] p').first().innerText()).trim(), `${url}: approved unavailable copy`).toBe(DECK['unavailable.body']);
      const links = page.locator('[data-test=unavailable] a');
      const hrefs = await links.evaluateAll((as) => as.map((a) => a.href));
      expect(hrefs.some((h) => /\/health-tools\/$/.test(h)), `${url}: hub link`).toBe(true);
      const article = hrefs.find((h) => !/\/health-tools\/$/.test(h));
      expect(article, `${url}: one article link`).toBeTruthy();
      for (const h of [hrefs.find((x) => /\/health-tools\/$/.test(x)), article]) expect((await request.get(h)).status(), h).toBe(200);
    }
  } finally { stack.wp('lld', 'status', 'set', 'diabetes', 'active', `--version=${status.tools.diabetes.approved_version}`); }
});
