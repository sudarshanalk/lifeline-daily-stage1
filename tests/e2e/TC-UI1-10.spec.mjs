// TC-UI1-10 Iframe embed behaviour — e2e half (AS-01 on the rendered embed, AS-02 height follows the form → result
// transition). The embed under test is the landing-page double tests/e2e/harness/landing.html (the exact markup the
// lld-tools shortcode renders, README) and, when LLD_WP_URL points at a WordPress origin with the FR1-04 pages, the real
// landing pages. Fixtures: FX-PR-NRM-01, FX-DM-QD-01.
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
const TOOLS = {
  protein: { fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, file: 'protein-requirement-tool.html' },
  diabetes: { fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, file: 'diabetes-risk-tool.html' },
};
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer({ harnessDir: resolve(ROOT, 'tests', 'e2e', 'harness') }); });
test.afterAll(async () => { await srv?.close(); });
const landingUrls = (slug) => [`${srv.url}/__harness/landing.html?tool=${slug}`, ...(process.env.LLD_WP_URL ? [`${process.env.LLD_WP_URL.replace(/\/$/, '')}/health-tools/${slug === 'protein' ? 'protein' : 'diabetes-risk-check'}/`] : [])];

test('TC-UI1-10-AS-01', async ({ page }) => {
  for (const slug of Object.keys(TOOLS)) for (const url of landingUrls(slug)) {
    await page.goto(url);
    const frame = page.locator('iframe.lld-tool-frame, iframe#lld-tool-frame').first();
    await expect(frame, `${url}: embed present`).toHaveCount(1);
    expect((await frame.getAttribute('title') || '').trim().length, `${url}: iframe has a title`).toBeGreaterThan(3);
    expect(await frame.getAttribute('loading'), `${url}: tool embed is above the fold, never lazy`).not.toBe('lazy');
    expect((await frame.getAttribute('allow')) || '', `${url}: allow attribute absent or empty`).toBe('');
    expect(await frame.getAttribute('src'), `${url}: src is the tool page`).toMatch(new RegExp(`/tools/${TOOLS[slug].file}$`));
    expect(await frame.getAttribute('data-litespeed-src'), 'not rewritten by lazy-load (DEC-31)').toBeNull();
  }
});

test('TC-UI1-10-AS-02', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await page.goto(`${srv.url}/__harness/landing.html?tool=${slug}`);
    const iframe = page.locator('iframe.lld-tool-frame').first();
    const frame = page.frameLocator('iframe.lld-tool-frame').first();
    await expect(frame.locator('body')).toBeVisible();
    // the first height message sizes the frame to the form; record it, then submit
    await expect.poll(async () => (await iframe.getAttribute('data-lld-sized')) !== null, { timeout: 5000 }).toBe(true);
    const formHeight = parseFloat(await iframe.evaluate((el) => el.style.height));
    await t.fill(frame, t.fixture.inputs);
    await t.submit(frame);
    await expect(frame.locator('#results')).toBeVisible();
    const tVisible = Date.now();
    // within 500 ms of the result appearing the iframe height equals the content height ± 20
    let matchedAt = null; let last = '';
    for (let i = 0; i < 120 && matchedAt === null; i++) {
      const h = parseFloat(await iframe.evaluate((el) => el.style.height));
      const content = await frame.locator('html').evaluate((el) => Math.ceil(el.getBoundingClientRect().height));
      last = `frame ${h} vs content ${content}`;
      if (Math.abs(h - content) <= 20) matchedAt = Date.now(); else await page.waitForTimeout(25);
    }
    expect(matchedAt, `${slug}: iframe height followed the content (${last})`).not.toBeNull();
    expect(matchedAt - tVisible, `${slug}: resized within 500 ms of the result`).toBeLessThanOrEqual(500);
    const finalHeight = parseFloat(await iframe.evaluate((el) => el.style.height));
    expect(finalHeight, `${slug}: the height changed with the transition (grow or shrink)`).not.toBe(formHeight);
  }
});
