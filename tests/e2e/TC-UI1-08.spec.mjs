// TC-UI1-08 Loading, unavailable and error states — e2e + a11y. AS-01 a slow status.json shows [data-test=loading][aria-busy=true]
// until it loads; AS-02 withdrawn: h2, the copy-deck unavailable message, hub + article links, no form; AS-03 e-mail network
// failure → #emailErr text and #sendBtn re-enabled saying "try again" (diabetes: #emailStatus/#emailBtn); AS-04 axe clean on the
// loading, withdrawn and error states. Fixtures: FX-PR-NRM-01, FX-DM-QD-01 to reach a result.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const sentinel = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'sentinel-values.json'), 'utf8'));
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const deck = { protein: JSON.parse(readFileSync(resolve(ROOT, 'copy', 'protein-messages.json'), 'utf8')), diabetes: JSON.parse(readFileSync(resolve(ROOT, 'copy', 'diabetes-messages.json'), 'utf8')) };
const baseline = JSON.parse(readFileSync(resolve(ROOT, 'tests', 'e2e', 'a11y-baseline.json'), 'utf8')).accepted;
const TOOLS = {
  protein: { file: 'protein-requirement-tool.html', fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, form: '#form', send: '#sendBtn', status: '#emailErr', baseline: 'protein form' },
  diabetes: { file: 'diabetes-risk-tool.html', fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, form: '#tool', send: '#emailBtn', status: '#emailStatus', baseline: 'diabetes form' },
};
const withdrawnAll = { ...status, tools: Object.fromEntries(Object.entries(status.tools).map(([k, v]) => [k, { ...v, status: 'withdrawn', message: `unavailable.${k}`, since: '2026-10-23T12:00:00Z' }])) };
let srv, down;
test.beforeAll(async () => { srv = await startHtaccessServer(); down = await startHtaccessServer({ statusJson: withdrawnAll }); });
test.afterAll(async () => { await srv?.close(); await down?.close(); });
const axeClean = async (page, label, tolerated) => {
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  const unexpected = r.violations.filter((v) => !(tolerated || []).includes(v.id));
  expect(unexpected.map((v) => `${v.impact} ${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`), `${label}: axe violations`).toEqual([]);
};

test('TC-UI1-08-AS-01', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.route('**/status.json', async (route) => { await new Promise((r) => setTimeout(r, 2000)); try { await route.continue(); } catch {} });
    await page.goto(`${srv.url}/tools/${t.file}`);
    const loading = page.locator('[data-test=loading][aria-busy=true]');
    await expect(loading, `${slug}: loading indicator while status.json is pending`).toBeVisible();
    expect(await page.locator(`${t.form}:visible`).count()).toBe(0);
    await expect(page.locator(t.form), `${slug}: form after load`).toBeVisible({ timeout: 10000 });
    await expect(page.locator('[data-test=loading][aria-busy=true]'), `${slug}: indicator removed after load`).toHaveCount(0);
    await expect(page.locator('[data-test=loading]')).toBeHidden();
    await page.unroute('**/status.json');
  }
});

test('TC-UI1-08-AS-02', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.goto(`${down.url}/tools/${t.file}`);
    const un = page.locator('[data-test=unavailable]');
    await expect(un).toBeVisible();
    await expect(un.locator('h2')).toHaveCount(1);
    expect((await un.locator('p').first().innerText()).trim(), `${slug}: message = copy deck unavailable body`).toBe(deck[slug]['unavailable.body']);
    const hrefs = await un.locator('a').evaluateAll((as) => as.map((a) => a.href));
    expect(hrefs.some((h) => /\/health-tools\/$/.test(h)), `${slug}: hub link`).toBe(true);
    expect(hrefs.filter((h) => !/\/health-tools\/$/.test(h)).length, `${slug}: one article link`).toBe(1);
    expect(await page.locator('form:visible').count(), `${slug}: no form`).toBe(0);
  }
});

test('TC-UI1-08-AS-03', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
    await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible();
    await page.route('**/submit*.php', (route) => route.abort('connectionfailed'));
    await page.locator('#email').fill(sentinel.email); await page.locator('#consent').check();
    await page.locator(t.send).click();
    await expect(page.locator(t.status), `${slug}: error text`).not.toHaveText('');
    await expect(page.locator(t.status)).toHaveText(await page.evaluate((s) => (s === 'protein' ? MESSAGES.emailFailed : MESSAGES.email.errorServer), slug));
    await expect(page.locator(t.send), `${slug}: send re-enabled`).toBeEnabled();
    await expect(page.locator(t.send)).toHaveText(/try again/i);
    await page.unroute('**/submit*.php');
  }
});

test('TC-UI1-08-AS-04', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    // loading state (held open by a slow status.json)
    await page.route('**/status.json', async (route) => { await new Promise((r) => setTimeout(r, 4000)); try { await route.continue(); } catch {} });
    await page.goto(`${srv.url}/tools/${t.file}`);
    await expect(page.locator('[data-test=loading][aria-busy=true]')).toBeVisible();
    await axeClean(page, `${slug} loading`, baseline[t.baseline]);
    await expect(page.locator(t.form)).toBeVisible({ timeout: 15000 });
    await page.unroute('**/status.json');
    // withdrawn state
    await page.goto(`${down.url}/tools/${t.file}`); await expect(page.locator('[data-test=unavailable]')).toBeVisible();
    await axeClean(page, `${slug} withdrawn`, baseline[t.baseline]);
    // error state
    await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible();
    await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible();
    await page.route('**/submit*.php', (route) => route.abort('connectionfailed'));
    await page.locator('#email').fill(sentinel.email); await page.locator('#consent').check(); await page.locator(t.send).click();
    await expect(page.locator(t.status)).not.toHaveText('');
    await axeClean(page, `${slug} error`, [...(baseline[t.baseline] || []), ...(baseline[`${slug} result`] || [])]);
    await page.unroute('**/submit*.php');
  }
});
