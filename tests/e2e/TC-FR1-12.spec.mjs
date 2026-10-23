// TC-FR1-12 e2e: AS-01 (band label shown for filled BAND fixtures), AS-04 concordance note (CONC-01 yes, CONC-02 no),
// AS-05 NOWAIST-01 needWaist text and no IDRS/WHtR numbers, AS-06 methodology phrases, AS-08 source and version line.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const load = (f) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'diabetes', f), 'utf8')).fixtures;
const CONC = load('conc.json'); const BAND = load('band.json');
const phrases = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'required-phrases.json'), 'utf8'));
const manifest = JSON.parse(readFileSync(resolve(ROOT, 'manifests', 'diabetes.json'), 'utf8'));
const byId = (id) => CONC.find((f) => f.id === id);
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

async function run(page, fx) {
  await page.goto(`${srv.url}/tools/diabetes-risk-tool.html`);
  await fillDiabetesForm(page, fx.inputs); await submitDiabetes(page);
  await expect(page.locator('#results')).toBeVisible();
}

test('TC-FR1-12-AS-01', async ({ page }) => {
  const filled = BAND.filter((f) => typeof f.inputs.weightKg === 'number' && f.expected);
  test.skip(filled.length === 0, 'BAND inputs pending the ORC-01 weight search (OI-4) — the unit part records the deferral');
  for (const fx of filled) {
    await run(page, fx);
    const label = (await page.locator('.risk-band').innerText()).toLowerCase();
    expect(label, fx.id).toContain(fx.expected.band === 'veryHigh' ? 'very high' : fx.expected.band);
  }
});

test('TC-FR1-12-AS-04', async ({ page }) => {
  await run(page, byId('FX-DM-CONC-01'));
  const reconcile = await page.evaluate(() => MESSAGES.reconcile);
  await expect(page.locator('#secondaryZone .concord')).toBeVisible();
  expect(await page.locator('#secondaryZone .concord').innerText()).toContain(reconcile.replace(/<[^>]+>/g, '').slice(0, 40));
  await run(page, byId('FX-DM-CONC-02'));
  await expect(page.locator('#secondaryZone .concord')).toHaveCount(0);
});

test('TC-FR1-12-AS-05', async ({ page }) => {
  await run(page, byId('FX-DM-NOWAIST-01'));
  const sz = page.locator('#secondaryZone');
  expect(await sz.innerText()).toContain(await page.evaluate(() => MESSAGES.secondary.needWaist));
  await expect(sz.locator('.tile')).toHaveCount(0);
  expect(await sz.innerText(), 'no IDRS/WHtR numbers').not.toMatch(/\b0\.\d{2}\b|\b\d{2,3}\b/);
});

test('TC-FR1-12-AS-06', async ({ page }) => {
  await run(page, byId('FX-DM-CONC-02'));
  await page.locator('details.method summary').click();
  const text = (await page.locator('#methodBody').innerText()).replace(/\s+/g, ' ');
  // phrase presence is case-insensitive ("symptom" is satisfied by "Symptoms are handled separately")
  for (const [k, phrase] of Object.entries(phrases.methodology)) expect(text.toLowerCase(), `methodology.${k}`).toContain(phrase.toLowerCase());
  expect(text.toLowerCase()).toContain(phrases.notDiagnosis.toLowerCase());
});

test('TC-FR1-12-AS-08', async ({ page }) => {
  await run(page, byId('FX-DM-CONC-02'));
  await page.locator('details.method summary').click();
  const line = page.locator('[data-test=source-version]');
  await expect(line).toBeVisible();
  const text = (await line.innerText()).replace(/\s+/g, ' ');
  expect(text).toMatch(/^Source: QDiabetes-2018 Model A · version [0-9a-f]{8}$/);
  const live = manifest.versions.find((v) => v.is_live);
  expect(text.slice(-8), 'version = frozen js:ENGINE hash prefix').toBe(live.engine_hashes['js:ENGINE'].slice(0, 8));
});
