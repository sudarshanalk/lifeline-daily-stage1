// TC-NFR1-01 Privacy and special-category data — AS-03 (= TC-FR1-16-AS-01's privacy property, which holds whether or not
// analytics is installed): during the three sentinel flows (FX-PR-REN-01, FX-DM-EMG-01, FX-DM-QD-01 with the measurements,
// name and e-mail replaced by fixtures/sentinel-values.json), no request to any host other than the site origin contains a
// sentinel value in its URL, headers or body. The e-mail step is exercised; its request goes to the site's own endpoint.
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
const S = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'sentinel-values.json'), 'utf8'));
const SENTINELS = [S.firstName, S.email, String(S.weightKg), String(S.waistCm), String(S.heightCm), encodeURIComponent(S.email)];
// the diabetes payload rounds measurements (83.7 -> 84, 97.3 -> 97): rounded forms count when they appear as a value
const ROUNDED = [S.weightKg, S.waistCm, S.heightCm].map((n) => String(Math.round(n)));
// every key the inventory classes as health (privacy/data-flow.json), by its last path segment, e.g. measurements.weightKg -> weightKg
const HEALTH_KEYS = [...new Set(JSON.parse(readFileSync(resolve(ROOT, 'privacy', 'data-flow.json'), 'utf8')).fields.filter((x) => x.classification === 'health').map((x) => x.key.split('.').pop()))];
const leaksIn = (blob) => [
  ...SENTINELS.filter((v) => blob.includes(v)),
  ...ROUNDED.filter((v) => new RegExp(`[=:"']${v}(?![0-9])`).test(blob)).map((v) => `${v} (rounded)`),
  ...HEALTH_KEYS.filter((k) => new RegExp(`["?&]${k}["=]`).test(blob)).map((k) => `key ${k}`),
];
const FLOWS = [
  ['FX-PR-REN-01', 'protein-requirement-tool.html', '#form', { ...fx('protein', 'ren.json', 'FX-PR-REN-01').inputs, units: 'metric', heightCm: S.heightCm, weightKg: S.weightKg }, fillProteinForm, submitProtein, '#sendBtn'],
  ['FX-DM-EMG-01', 'diabetes-risk-tool.html', '#tool', { ...fx('diabetes', 'emg.json', 'FX-DM-EMG-01').inputs, heightCm: S.heightCm, weightKg: S.weightKg, waistCm: S.waistCm }, fillDiabetesForm, submitDiabetes, '#emailBtn'],
  ['FX-DM-QD-01', 'diabetes-risk-tool.html', '#tool', { ...fx('diabetes', 'qd.json', 'FX-DM-QD-01').inputs, heightCm: S.heightCm, weightKg: S.weightKg, waistCm: S.waistCm }, fillDiabetesForm, submitDiabetes, '#emailBtn'],
];
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

test('TC-NFR1-01-AS-03', async ({ page }) => {
  // the emulator serves the page from a random local port, which the endpoint's allowed_origins does not list: present the
  // production origin on the e-mail posts, as a visitor on lifelinedaily.com would (the payload is untouched)
  await page.route(/submit(-protein)?\.php$/, (r) => r.continue({ headers: { ...r.request().headers(), origin: 'https://lifelinedaily.com' } }));
  const origin = new URL(srv.url).origin;
  const offsite = []; const mailed = []; let sameOrigin = 0, mailPosts = 0;
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (u.origin === origin || u.protocol === 'data:' || u.protocol === 'blob:') { sameOrigin++; if (/submit(-protein)?\.php$/.test(u.pathname)) mailPosts++; return; }
    const blob = [req.url(), JSON.stringify(req.headers()), req.postData() || ''].join('\n');
    offsite.push({ url: req.url(), leaks: leaksIn(blob) });
  });
  for (const [id, file, form, inputs, fill, submit, send] of FLOWS) {
    await page.goto(`${srv.url}/tools/${file}`); await expect(page.locator(form)).toBeVisible();
    await fill(page, inputs); await submit(page); await expect(page.locator('#results'), `${id}: result`).toBeVisible();
    // the e-mail step where the result offers it (the renal-suppressed protein result shows no e-mail card)
    if (await page.locator('#email').isVisible()) {
      const nameField = page.locator(file.startsWith('protein') ? '#fname' : '#firstName');
      if (await nameField.isVisible()) await nameField.fill(S.firstName);
      await page.locator('#email').fill(S.email); await page.locator('#consent').check();
      // wait for the endpoint's answer and the on-screen outcome, so anything fired on success is captured before the next flow
      const answered = page.waitForResponse((r) => /submit(-protein)?\.php$/.test(new URL(r.url()).pathname));
      await page.locator(send).click(); const res = await answered;
      expect(res.ok(), `${id}: e-mail request answered (${res.status()} ${await res.text().catch(() => "")})`).toBe(true);
      await expect(page.locator(file.startsWith('protein') ? '#emailOk' : '#emailStatus.ok'), `${id}: sent shown`).toBeVisible();
      await page.waitForTimeout(1000); mailed.push(id);
    }
    await page.waitForLoadState('networkidle');
  }
  expect(mailed.length, 'the e-mail step ran in at least one flow').toBeGreaterThan(0);
  expect(mailPosts, 'each e-mail step posted to the site endpoint').toBe(mailed.length);
  expect(offsite.filter((r) => r.leaks.length), 'no sentinel value, rounded measurement or health key in any request to another host').toEqual([]);
  // the scanner itself is not vacuous: it flags a payload like the ones the tools send
  expect(leaksIn(JSON.stringify({ weightKg: Math.round(S.weightKg) })).length, 'scanner catches a rounded health value under its key').toBeGreaterThan(0);
  test.info().annotations.push({ type: 'requests', description: `e-mail step in ${mailed.join(', ')}; ${sameOrigin} same-origin requests, ${offsite.length} to other hosts (${[...new Set(offsite.map((r) => new URL(r.url).host))].join(', ') || 'none'})` });
});
