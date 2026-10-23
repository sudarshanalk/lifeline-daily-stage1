// TC-FR1-11 Diabetes symptom escalation — e2e family: AS-01 (EMG-01/02 banner first, no % in headline, no #riskNum, "Risk
// estimate paused"), AS-03 (SYM-01/03 symptom banner before headline, % shown), AS-04 (SYM-02 no symptom banner),
// AS-05 (banner above the email card at 375 and 1440), AS-06 client layer (intercepted POST nulls riskPercent/riskBand).
// Mutation: MUT-05. AS-02 and the AS-06 server layer are in tests/php/TC-FR1-11.test.mjs.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const load = (f) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'diabetes', f), 'utf8')).fixtures;
const EMG = load('emg.json'); const SYM = load('sym.json');
const byId = (id) => [...EMG, ...SYM].find((f) => f.id === id);
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

async function run(page, fx) {
  await page.goto(`${srv.url}/tools/diabetes-risk-tool.html`);
  await fillDiabetesForm(page, fx.inputs); await submitDiabetes(page);
  await expect(page.locator('#results')).toBeVisible();
}

test('TC-FR1-11-AS-01', async ({ page }) => {
  for (const fx of EMG) {
    await run(page, fx);
    const banners = page.locator('[data-test=banners]');
    await expect(banners.locator('> :first-child')).toHaveClass(/\bemergency\b/);
    const headline = await page.locator('#headlineZone').innerText();
    expect(headline, `${fx.id}: no percentage`).not.toContain('%');
    expect(headline.toLowerCase(), `${fx.id}`).toContain('risk estimate paused'); // innerText is upper-cased by CSS
    await expect(page.locator('#riskNum'), `${fx.id}: no risk number`).toHaveCount(0);
    await expect(page.locator('#results .risk-num, #results .risk-band'), `${fx.id}: no risk figure or band element`).toHaveCount(0); // static band-explanation copy may mention percentages; the user's figure must not appear
  }
});

test('TC-FR1-11-AS-03', async ({ page }) => {
  for (const id of ['FX-DM-SYM-01', 'FX-DM-SYM-03']) {
    await run(page, byId(id));
    const order = await page.evaluate(() => { const b = document.querySelector('.banner.symptom'); const h = document.getElementById('headlineZone'); return b && (b.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING) ? 'banner-first' : 'missing-or-after'; });
    expect(order, id).toBe('banner-first');
    await expect(page.locator('#riskNum'), id).toBeVisible();
    await expect(page.locator('#riskNum'), id).toContainText('%');
    await expect(page.locator('.banner.emergency')).toHaveCount(0);
  }
});

test('TC-FR1-11-AS-04', async ({ page }) => {
  await run(page, byId('FX-DM-SYM-02')); // fatigue only: not a classic symptom
  await expect(page.locator('.banner.symptom')).toHaveCount(0);
  await expect(page.locator('.banner.emergency')).toHaveCount(0);
  await expect(page.locator('#riskNum')).toBeVisible();
});

test('TC-FR1-11-AS-05', async ({ page }) => {
  for (const width of [375, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    for (const id of ['FX-DM-EMG-01', 'FX-DM-SYM-01']) {
      await run(page, byId(id));
      const pos = await page.evaluate(() => { const b = document.querySelector('[data-test=banners] .banner'); const e = document.querySelector('[data-test=email-card]'); return { banner: b.getBoundingClientRect().top, email: e.getBoundingClientRect().top }; });
      expect(pos.banner, `${id} @${width}: banner above the email card`).toBeLessThan(pos.email);
    }
  }
});

test('TC-FR1-11-AS-06', async ({ page }) => {
  // client layer (DEC-09): the POST for EMG-01 carries emergency:true, riskPercent:null, riskBand:null
  const posts = [];
  await page.route('**/submit.php', async (route) => { posts.push(route.request().postDataJSON()); await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"ok"}' }); });
  await run(page, byId('FX-DM-EMG-01'));
  await page.locator('#email').fill('zq7sentinel@example.test');
  await page.locator('#consent').check();
  await page.locator('#emailBtn').click();
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].emergency).toBe(true);
  expect(posts[0].riskPercent).toBeNull();
  expect(posts[0].riskBand).toBeNull();
  expect(posts[0].tool).toBe('diabetes-risk');
});
