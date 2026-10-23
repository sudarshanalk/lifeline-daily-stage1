// a11y (docs/04 §1): axe-core over both tool pages (form state and result state) through the local stack. Until NFR1-05
// defines the full acceptance (WCAG 2.2 AA on every state, keyboard traversal, role=alert), this job fails only on
// violations outside tests/e2e/a11y-baseline.json (the as-is state) so regressions are caught from wave 1.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

const baseline = JSON.parse(readFileSync(new URL('./a11y-baseline.json', import.meta.url), 'utf8')).accepted;
const scan = async (page, label) => {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze();
  for (const v of results.violations) console.log(`[a11y] ${label}: ${v.impact} ${v.id} (${v.nodes.length} nodes) ${v.help}`);
  // regression gate: any violation not in the 23 Oct 2026 baseline fails; NFR1-05 clears the baseline itself
  const unexpected = results.violations.filter((v) => !(baseline[label] || []).includes(v.id));
  expect(unexpected.map((v) => `${v.impact} ${v.id}: ${v.help}`), `${label}: axe violations outside the baseline`).toEqual([]);
  return results.violations.length;
};

test('SUPPORT-NFR1-09-A11Y-01 protein tool: form and result states have no axe violations beyond the baseline', async ({ page }) => {
  await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
  await scan(page, 'protein form');
  await page.fill('#age', '35'); await page.click('#sex button[data-val="female"]'); await page.fill('#hVal', '165'); await page.fill('#wVal', '60');
  await page.click('#eth button[data-val="WhiteNA"]'); await page.click('#act button[data-val="moderate"]'); await page.click('#res button[data-val="0"]');
  await page.click('#goal button[data-val="maintain"]'); await page.click('#diet button[data-val="omnivore"]'); await page.selectOption('#meals', '3');
  await page.click('#kidney button[data-val="no"]'); await page.click('#liver button[data-val="no"]'); await page.click('#form button.cta');
  await expect(page.locator('#results')).toBeVisible();
  await scan(page, 'protein result');
});

test('SUPPORT-NFR1-09-A11Y-02 diabetes tool: form state has no axe violations beyond the baseline', async ({ page }) => {
  await page.goto(`${srv.url}/tools/diabetes-risk-tool.html`);
  await scan(page, 'diabetes form');
});
