// TC-FR1-10-AS-03 — the methodology panel carries the QDiabetes-2018 attribution and the licence statement from the approval record.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const approval = JSON.parse(readFileSync(resolve(here, '..', '..', 'approvals', 'diabetes.json'), 'utf8'));
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

test('TC-FR1-10-AS-03', async ({ page }) => {
  await page.goto(`${srv.url}/tools/diabetes-risk-tool.html`);
  // the methodology panel lives in the results section: complete a minimal result first (FX-DM-QD-02-like inputs), then open it
  await page.locator('button.tap[data-val="Female"]').click();
  await page.locator('button.tap[data-val="27"]').click();
  await page.locator('#ht').fill('170'); await page.locator('#wt').fill('75');
  await page.locator('button.tap[data-val="Indian"]').click().catch(() => {});
  await page.locator('#submitBtn').click();
  await expect(page.locator('#results')).toBeVisible();
  await page.locator('details.method summary').click(); // closed <details>; open it as a visitor would
  const panel = page.locator('#methodBody');
  await expect(panel).toBeVisible();
  const text = (await panel.innerText()).replace(/\s+/g, ' ');
  expect(text).toContain('QDiabetes-2018');
  // licence statement as recorded in approvals/diabetes.json.licence (algorithm © ClinRisk, LGPL-3.0; port is Lifeline Daily's)
  expect(text).toMatch(/ClinRisk/);
  expect(text).toMatch(/LGPL-3\.0|LGPL/);
  expect(text).toMatch(/Hippisley-Cox/);
  for (const key of ['ClinRisk', 'LGPL']) expect(approval.licence).toContain(key);
  // the statement is machine-identifiable copy (DEC-25 inventory key)
  await expect(page.locator('#methodBody [data-copy="method.attribution"]')).toHaveCount(1);
});
