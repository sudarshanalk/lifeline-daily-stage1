// TC-FR1-17 Policies, education and safe navigation — e2e. Tool states on the htaccess emulator; WordPress states on the local
// stack (DEC-69) with `wp lld pages apply` (landing pages + the new /medical-disclaimer/ page), restored afterwards and the
// created disclaimer page deleted. Fixtures: FX-DM-EMG-01, FX-PR-NRM-01 (+ FX-DM-QD-01 for the diabetes result zone);
// fixtures/required-phrases.json (notDiagnosis, urgentCare). AS-04 compares the disclaimer page's main content hash with
// approvals/copy.json pages['medical-disclaimer'], which HG-02 records when it approves the copy (DEC-36).
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const PHRASES = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'required-phrases.json'), 'utf8'));
const FOOTER = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'menu-footer.json'), 'utf8').replace(/^﻿/, ''));
const APPROVALS = JSON.parse(readFileSync(resolve(ROOT, 'approvals', 'copy.json'), 'utf8'));
const FIVE = ['/privacy-policy/', '/terms-conditions/', '/about-us/', '/contact-us/', '/medical-disclaimer/'];   // DEC-12
const TOOLS = {
  protein: { file: 'protein-requirement-tool.html', landing: '/health-tools/protein/', fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, form: '#form' },
  diabetes: { file: 'diabetes-risk-tool.html', landing: '/health-tools/diabetes-risk-check/', fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, form: '#tool' },
};
const PAGES = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
const a = available();
test.describe.configure({ mode: 'serial' });

let srv, stack; const before = {}; let createdDisclaimer = null;
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeAll(async () => {
  srv = await startHtaccessServer();
  if (!a.ok) return;
  stack = await startWpStack();
  if (stack.external) return;
  for (const id of Object.values(PAGES)) before[id] = { content: stack.wp('post', 'get', String(id), '--field=post_content').stdout, slug: stack.wp('post', 'meta', 'get', String(id), 'lld_tool_slug').stdout.trim() };
  const existed = stack.wp('post', 'list', '--post_type=page', '--name=medical-disclaimer', '--post_status=any', '--field=ID').stdout.trim();
  const r = stack.wp('lld', 'pages', 'apply'); if (r.status !== 0) throw new Error(r.stdout + r.stderr);
  if (!existed) createdDisclaimer = stack.wp('post', 'list', '--post_type=page', '--name=medical-disclaimer', '--field=ID').stdout.trim();
});
test.afterAll(async () => {
  if (stack && !stack.external) {
    for (const [id, b] of Object.entries(before)) { stack.wp('post', 'update', id, `--post_content=${b.content.replace(/\n$/, '')}`); b.slug ? stack.wp('post', 'meta', 'update', id, 'lld_tool_slug', b.slug) : stack.wp('post', 'meta', 'delete', id, 'lld_tool_slug'); }
    if (createdDisclaimer) stack.wp('post', 'delete', createdDisclaimer, '--force');
  }
  await stack?.close(); await srv?.close();
});
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });
const path = (h) => { try { return new URL(h, 'http://x').pathname + (new URL(h, 'http://x').hash || ''); } catch { return h; } };
/** Tab through the page (entering the embedded tool when it has focus) and collect the link paths focus reaches. */
async function tabbedLinks(page, max = 500) {
  const seen = new Set();
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const h = await page.evaluate(() => { let el = document.activeElement; while (el && el.tagName === 'IFRAME') { try { el = el.contentDocument.activeElement; } catch { break; } } return el && el.tagName === 'A' ? el.getAttribute('href') : null; });
    if (h) seen.add(h);
  }
  return [...seen].map(path);
}

test('TC-FR1-17-AS-01', async ({ page, request }) => {
  test.setTimeout(900000);
  const missing = [];
  // standalone tools: the policy footer carries all five links and Tab reaches each; the result zone reaches disclaimer + privacy
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
    const footer = await page.locator('[data-test=tool-footer] a').evaluateAll((as) => as.map((x) => x.getAttribute('href')));
    for (const p of FIVE) if (!footer.map(path).includes(p)) missing.push(`${slug} tool footer: ${p}`);
    await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible();
    await page.locator('[data-test=disclaimer]').scrollIntoViewIfNeeded();
    const zone = await page.locator('[data-test=disclaimer] a').evaluateAll((as) => as.map((x) => x.getAttribute('href')));
    for (const p of ['/medical-disclaimer/', '/privacy-policy/']) if (!zone.map(path).includes(p)) missing.push(`${slug} result zone: ${p}`);
    await page.locator('body').focus?.();
    const reached = await tabbedLinks(page, 300);
    for (const p of FIVE) if (!reached.includes(p)) missing.push(`${slug} tool page: Tab does not reach ${p}`);
  }
  if (stack) {
    for (const p of FIVE) expect((await request.get(stack.url + p)).status(), `${p} → 200`).toBe(200);
    for (const [slug, t] of Object.entries(TOOLS)) {
      await page.goto(stack.url + t.landing, { waitUntil: 'load' });
      const reached = await tabbedLinks(page, 500);
      for (const p of FIVE) if (!reached.includes(p)) missing.push(`${t.landing}: Tab does not reach ${p}`);
    }
    // the WordPress footer is unchanged: the four baseline links, no disclaimer link (DEC-43)
    const items = JSON.parse(stack.wp('menu', 'item', 'list', 'footer-menu', '--fields=title,url', '--format=json').stdout);
    expect(items.map((i) => [i.title, path(i.url)]), 'footer menu = baseline').toEqual(FOOTER.map((i) => [i.title, path(i.link)]));
    await page.goto(`${stack.url}/`, { waitUntil: 'load' });
    const footerLinks = await page.locator('footer a, .td-footer-template-wrap a, .td-footer-wrapper a').evaluateAll((as) => as.map((x) => x.getAttribute('href')));
    expect(footerLinks.map(path).filter((h) => h.startsWith('/medical-disclaimer/')), 'no disclaimer link in the site footer').toEqual([]);
  } else test.info().annotations.push({ type: 'scope', description: `WordPress states not run: ${a.reason}` });
  expect(missing, 'five DEC-12 links reachable').toEqual([]);
});

test('TC-FR1-17-AS-02', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.goto(`${srv.url}/tools/${t.file}`); await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible();
    const zone = page.locator('[data-test=disclaimer]');
    await expect(zone, `${slug}: notDiagnosis phrase`).toContainText(PHRASES.notDiagnosis);
    expect((await zone.locator('a').evaluateAll((as) => as.map((x) => x.getAttribute('href')))).map(path), `${slug}: disclaimer link`).toContain('/medical-disclaimer/');
  }
});

test('TC-FR1-17-AS-03', async ({ page, request }) => {
  const t = TOOLS.diabetes;
  await page.goto(`${srv.url}/tools/${t.file}`); await t.fill(page, fx('diabetes', 'emg.json', 'FX-DM-EMG-01').inputs); await t.submit(page);
  await expect(page.locator('#results')).toBeVisible();
  await expect(page.locator('#results'), 'EMG-01: urgentCare phrase').toContainText(PHRASES.urgentCare);
  expect((await page.locator('#results a').evaluateAll((as) => as.map((x) => x.getAttribute('href')))).map(path), 'EMG-01: link to the urgent-care anchor').toContain('/medical-disclaimer/#urgent-care');
  if (stack) {
    const html = await (await request.get(`${stack.url}/medical-disclaimer/`)).text();
    expect(html, 'urgent-care anchor').toMatch(/id="urgent-care"/);
    for (const n of ['999', '111', '1990', '112']) expect(html, `disclaimer lists ${n}`).toMatch(new RegExp(`\\b${n}\\b`));
    expect(html).toMatch(/local emergency number/);
  }
});

test('TC-FR1-17-AS-04', async ({ page }) => {
  test.skip(!stack, a.reason);
  const approved = APPROVALS.pages?.['medical-disclaimer'];
  test.skip(!approved, 'approvals/copy.json has no pages["medical-disclaimer"] hash yet: HG-02 records it when it approves the disclaimer copy (DEC-36)');
  await page.goto(`${stack.url}/medical-disclaimer/`, { waitUntil: 'load' });
  const main = await page.locator('.td-post-content, .entry-content, article').first().evaluate((el) => el.innerText.replace(/\s+/g, ' ').trim());
  expect(createHash('sha256').update(main).digest('hex'), 'disclaimer main content = approved hash').toBe(approved.hash || approved);
});
