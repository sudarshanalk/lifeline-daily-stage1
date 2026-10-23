// TC-FR1-08 e2e: AS-02 no gram figure in the result for REN-01…04 and renalStop.title shown; AS-03 intercepted POST has
// suppressed:true, null numeric keys, sources []; AS-05 no request other than submit-protein.php / same-origin assets carries
// the sentinel numeric values; AS-06 UNS-01 renalUnsure banner has role=alert, precedes the result, numeric result present.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const load = (f) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'protein', f), 'utf8')).fixtures;
const REN = load('ren.json'); const UNS = load('uns.json');
const sentinel = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'sentinel-values.json'), 'utf8'));
const GRAMS = /\d+(\.\d+)?\s?(g\b|gram|g\/kg)/i;
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

const renalTitle = async (page) => page.evaluate(() => MESSAGES.renalStop.title);
const renalUnsureTitle = async (page) => page.evaluate(() => MESSAGES.renalUnsure.title);

test('TC-FR1-08-AS-02', async ({ page }) => {
  for (const f of REN) {
    await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
    await fillProteinForm(page, f.inputs); await submitProtein(page);
    const result = page.locator('[data-test=result]');
    await expect(result).toBeVisible();
    const text = await result.innerText();
    expect(text, `${f.id}: no gram figure anywhere in the result`).not.toMatch(GRAMS);
    expect(text, `${f.id}: renalStop title shown`).toContain(await renalTitle(page));
    await expect(page.locator('[data-test=renal-stop]'), `${f.id}`).toBeVisible();
    await expect(page.locator('#emailCard')).toBeHidden(); // no email card for a suppressed result (nothing to send)
  }
});

test('TC-FR1-08-AS-03', async ({ page }) => {
  // intercept the client POST: suppressed:true, numeric keys all null, sources []
  const posts = [];
  await page.route('**/submit-protein.php', async (route) => { posts.push(route.request().postDataJSON()); await route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"ok"}' }); });
  await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
  await fillProteinForm(page, REN[0].inputs); await submitProtein(page);
  await expect(page.locator('[data-test=result]')).toBeVisible();
  // the email card is hidden for suppressed results; drive the sender directly to prove the payload layer (DEC-09 twin)
  await page.evaluate(async (email) => { document.getElementById('emailCard').classList.remove('hidden'); document.getElementById('email').value = email; document.getElementById('consent').checked = true; document.getElementById('sendBtn').click(); }, sentinel.email);
  await expect.poll(() => posts.length).toBe(1);
  const p = posts[0];
  expect(p.suppressed).toBe(true);
  for (const k of ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal']) expect(p[k], k).toBeNull();
  expect(p.sources).toEqual([]);
  expect(p.tool).toBe('protein');
});

test('TC-FR1-08-AS-05', async ({ page }) => {
  // during the REN-01 flow with sentinel inputs, no request other than submit-protein.php (and same-origin assets) carries the sentinel numeric values
  const requests = [];
  page.on('request', (r) => requests.push({ url: r.url(), body: r.postData() || '' }));
  await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
  const inputs = { ...REN[0].inputs, weightKg: sentinel.weightKg, heightCm: sentinel.heightCm };
  await fillProteinForm(page, inputs); await submitProtein(page);
  await expect(page.locator('[data-test=result]')).toBeVisible();
  await page.evaluate(async (email) => { document.getElementById('emailCard').classList.remove('hidden'); document.getElementById('email').value = email; document.getElementById('consent').checked = true; document.getElementById('sendBtn').click(); }, sentinel.email);
  await page.waitForTimeout(800);
  const needles = [String(sentinel.weightKg), String(sentinel.heightCm)];
  const offenders = requests.filter((r) => !/\/submit-protein\.php$/.test(r.url) && !r.url.startsWith(srv.url + '/tools/')).filter((r) => needles.some((n) => r.url.includes(n) || r.body.includes(n)));
  expect(offenders, 'third-party or non-endpoint requests carrying sentinel inputs').toEqual([]);
  // the endpoint request itself carries no numeric result (suppressed) — only the sentinel email the user typed
  const endpoint = requests.find((r) => /\/submit-protein\.php$/.test(r.url));
  expect(endpoint).toBeDefined();
  expect(endpoint.body).not.toMatch(/"targetG":\s*\d/);
  expect(endpoint.body).not.toContain(String(sentinel.weightKg));
});

test('TC-FR1-08-AS-06', async ({ page }) => {
  await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
  await fillProteinForm(page, UNS[0].inputs); await submitProtein(page);
  const banner = page.locator('[data-test=renal-unsure]');
  await expect(banner).toBeVisible();
  await expect(banner).toHaveAttribute('role', 'alert');
  expect(await banner.innerText()).toContain(await renalUnsureTitle(page));
  // DOM order: banner before the headline/metrics
  const order = await page.evaluate(() => { const b = document.querySelector('[data-test=renal-unsure]'); const h = document.getElementById('headlineBlock'); return b.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING ? 'banner-first' : 'result-first'; });
  expect(order).toBe('banner-first');
  // numeric result present (not suppressed)
  expect(await page.locator('#headlineBlock').innerText()).toMatch(GRAMS);
});
