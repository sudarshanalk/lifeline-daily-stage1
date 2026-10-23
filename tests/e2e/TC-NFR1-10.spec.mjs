// TC-NFR1-10 Error handling and observability — e2e (AS-01 endpoint error responses → distinct messages and a retry control;
// AS-02 status.json blocked → fail-closed notice with retry, no calculation; unblock + retry → the calculation works).
// Fixtures: FX-PR-NRM-01, FX-DM-QD-01 reach a result; the endpoint responses are injected at the network layer.
import { test, expect } from '@playwright/test';
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
const TOOLS = {
  protein: { file: 'protein-requirement-tool.html', fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, form: '#form', send: '#sendBtn', status: '#emailErr',
    expected: (code) => `(${code}===429?MESSAGES.emailRateLimited:${code}===503?MESSAGES.emailUnavailable:MESSAGES.emailFailed)` },
  diabetes: { file: 'diabetes-risk-tool.html', fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, form: '#tool', send: '#emailBtn', status: '#emailStatus',
    expected: (code) => `(${code}===429?MESSAGES.email.errorRateLimited:${code}===503?MESSAGES.email.errorUnavailable:MESSAGES.email.errorServer)` },
};
const BODY = { 400: { status: 'error', errors: ['bad'] }, 429: { status: 'rate_limited' }, 502: { status: 'error', message: 'Could not send email' }, 503: { status: 'unavailable' } };
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });
const result = async (page, t) => { await page.goto(`${srv.url}/tools/${t.file}`); await expect(page.locator(t.form)).toBeVisible(); await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible(); };

test('TC-NFR1-10-AS-01', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await result(page, t);
    const seen = new Set();
    for (const code of [400, 429, 502, 503]) {
      await page.route('**/submit*.php', (route) => route.fulfill({ status: code, contentType: 'application/json', body: JSON.stringify(BODY[code]) }));
      await page.locator('#email').fill(sentinel.email); await page.locator('#consent').check();
      const send = page.locator(t.send); await expect(send).toBeEnabled(); await send.click();
      const expected = await page.evaluate(t.expected(code));
      await expect(page.locator(t.status), `${slug} ${code}: specific message`).toHaveText(expected);
      seen.add(expected);
      await expect(send, `${slug} ${code}: retry control re-enabled`).toBeEnabled();
      await expect(send).toHaveText(/try again/i);
      await expect(page.locator('#results'), `${slug} ${code}: result still shown`).toBeVisible();
      await page.unroute('**/submit*.php');
    }
    expect(seen.size, `${slug}: failed, rate-limited and unavailable are distinct`).toBe(3);
  }
});

test('TC-NFR1-10-AS-02', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.route('**/status.json', (route) => route.abort('failed'));
    await page.goto(`${srv.url}/tools/${t.file}`);
    const gate = page.locator('[data-test=loading]');
    await expect(gate).toHaveAttribute('aria-busy', 'false');
    const notice = await page.evaluate(() => MESSAGES.unavailable.unknown);
    await expect(gate, `${slug}: can't-confirm notice`).toContainText(notice);
    await expect(gate.locator('[data-test=retry]')).toBeVisible();
    expect(await page.locator(`${t.form}:visible`).count(), `${slug}: form not shown`).toBe(0);
    expect(await page.locator('#results:visible').count(), `${slug}: no result`).toBe(0);
    expect(await page.evaluate(() => window.LLD_STATUS)).toBe('unknown');
    await page.unroute('**/status.json');
    await gate.locator('[data-test=retry]').click();
    await expect(page.locator(t.form), `${slug}: form after retry`).toBeVisible();
    await t.fill(page, t.fixture.inputs); await t.submit(page);
    await expect(page.locator('#results'), `${slug}: calculation works after retry`).toBeVisible();
  }
});
