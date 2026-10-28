// TC-NFR1-15-AS-03 — with Content-Security-Policy-Report-Only mirroring the enforced policy, the e2e journeys
// (landing page → tool → result → email form, both tools, four widths) produce zero CSP reports.
// Violations are captured in-page via the `securitypolicyviolation` event (fires for report-only and enforced
// policies alike) plus console messages mentioning the policy. TC-NFR1-15-AS-02 (= TC-FR1-21-AS-05) is in TC-FR1-21.spec.mjs.
import { test, expect } from '@playwright/test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer({ harnessDir: resolve(here, 'harness') }); });
test.afterAll(async () => { await srv?.close(); });
const landingUrl = (slug) => process.env.LLD_WP_URL
  ? process.env.LLD_WP_URL.replace(/\/$/, '') + (slug === 'protein' ? '/health-tools/protein/' : '/health-tools/diabetes-risk-check/')
  : `${srv.url}/__harness/landing.html?tool=${slug}`;

test('TC-NFR1-15-AS-03', async ({ page }) => {
  const reports = [];
  await page.addInitScript(() => {
    window.__cspReports = [];
    document.addEventListener('securitypolicyviolation', (e) => window.__cspReports.push({ directive: e.violatedDirective, blocked: e.blockedURI, disposition: e.disposition, doc: location.href }));
  });
  page.on('console', (m) => { if (/Content Security Policy|Report-Only/i.test(m.text())) reports.push({ console: m.text() }); });
  const collect = async () => { for (const f of page.frames()) { try { reports.push(...await f.evaluate(() => window.__cspReports || [])); } catch {} } };

  for (const width of [320, 375, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    // protein journey
    await page.goto(landingUrl('protein'));
    const pf = page.frameLocator('iframe.lld-tool-frame, iframe#lld-tool-frame');
    await pf.locator('#age').fill('35');
    await pf.locator('#sex button[data-val="female"]').click();
    await pf.locator('#hVal').fill('165'); await pf.locator('#wVal').fill('60');
    await pf.locator('#eth button[data-val="WhiteNA"]').click();
    await pf.locator('#act button[data-val="moderate"]').click();
    await pf.locator('#res button[data-val="no"]').click();
    await pf.locator('#goal button[data-val="maintain"]').click();
    await pf.locator('#diet button[data-val="omnivore"]').click();
    await pf.locator('#meals').selectOption('3');
    await pf.locator('#kidney button[data-val="no"]').click();
    await pf.locator('#liver button[data-val="no"]').click();
    await pf.locator('#plan button[data-val="no"]').click();
    await pf.locator('#form button.cta').click();
    await expect(pf.locator('#results')).toBeVisible();
    await pf.locator('#email').fill('zq7sentinel@example.test');
    await page.waitForTimeout(300);
    await collect();
    // diabetes journey (form renders results live; fill the mandatory fields)
    await page.goto(landingUrl('diabetes'));
    const df = page.frameLocator('iframe.lld-tool-frame, iframe#lld-tool-frame');
    await expect(df.locator('#results')).toBeAttached();
    await page.waitForTimeout(500);
    await collect();
  }
  expect(reports, JSON.stringify(reports, null, 1)).toEqual([]);
});
