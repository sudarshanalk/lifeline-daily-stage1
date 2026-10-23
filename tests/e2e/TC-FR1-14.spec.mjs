// TC-FR1-14 e2e part of AS-05: when the endpoint answers 502 (SMTP down), each tool shows its MESSAGES failure copy and the
// calculator result stays visible. Fixtures: FX-PR-NRM-01, FX-DM-QD-01 drive the result; the 502 is injected at the network layer.
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
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

test('TC-FR1-14-AS-05', async ({ page }) => {
  for (const slug of ['protein', 'diabetes']) {
    await page.route('**/submit*.php', (route) => route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({ status: 'error', message: 'Could not send email' }) }));
    await page.goto(`${srv.url}/tools/${slug === 'protein' ? 'protein-requirement-tool.html' : 'diabetes-risk-tool.html'}`);
    if (slug === 'protein') { await fillProteinForm(page, fx('protein', 'nrm.json', 'FX-PR-NRM-01').inputs); await submitProtein(page); } else { await fillDiabetesForm(page, fx('diabetes', 'qd.json', 'FX-DM-QD-01').inputs); await submitDiabetes(page); }
    await expect(page.locator('#results')).toBeVisible();
    await page.locator('#email').fill(sentinel.email);
    await page.locator('#consent').check();
    await page.locator(slug === 'protein' ? '#sendBtn' : '#emailBtn').click();
    const expected = await page.evaluate((s) => (s === 'protein' ? MESSAGES.emailFailed : MESSAGES.email.errorServer), slug);
    const status = page.locator(slug === 'protein' ? '#emailErr' : '#emailStatus');
    await expect(status, `${slug}: failure copy shown`).toHaveText(expected);
    await expect(page.locator('#results'), `${slug}: result still visible`).toBeVisible();
    await expect(page.locator('#results [data-test=headline]')).toBeVisible();
    await page.unroute('**/submit*.php');
  }
});
