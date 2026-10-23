// TC-FR1-06-AS-03 — landing page methodology section displays deployed_version equal to the approval record.
// The methodology section is rendered by the lld-tools plugin on the WordPress landing pages (CMP-09, built in
// FR1-04/UI1-02). Until a WordPress origin that serves it is available (LLD_WP_URL), the assertion is skipped
// with that reason and recorded as `skipped` in the evidence bundle; it re-runs green once FR1-04 lands.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(new URL('.', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'), '..', '..');
const approvals = {
  protein: JSON.parse(readFileSync(resolve(ROOT, 'approvals', 'protein.json'), 'utf8')),
  diabetes: JSON.parse(readFileSync(resolve(ROOT, 'approvals', 'diabetes.json'), 'utf8')),
};
const LANDING = { protein: '/health-tools/protein/', diabetes: '/health-tools/diabetes-risk-check/' };

test('TC-FR1-06-AS-03', async ({ page }) => {
  test.skip(!process.env.LLD_WP_URL, 'needs a WordPress origin with the lld-tools methodology section (FR1-04); set LLD_WP_URL');
  for (const slug of ['protein', 'diabetes']) {
    await page.goto(process.env.LLD_WP_URL + LANDING[slug]);
    const shown = page.locator('[data-lld-deployed-version]');
    await expect(shown, `${slug} landing page methodology section`).toHaveCount(1);
    await expect(shown).toHaveAttribute('data-lld-deployed-version', approvals[slug].version_hash);
    await expect(shown).toContainText(approvals[slug].version_hash.slice(0, 8)); // 8-hex prefix contract shared with TC-FR1-04-AS-05
  }
});
