import { expect, test } from '@playwright/test';
import { serveHealthyWeightTool } from './helpers.mjs';
test('TC-NFR3-03-AS-01 Healthy Weight Check', async ({ page }) => {
  await serveHealthyWeightTool(page); await page.goto('/health-tools/healthy-weight-check/'); const frame = page.frameLocator('.lld-tool-frame');
  await frame.locator('#age').fill('40'); await frame.locator('#ht').fill('170'); await frame.locator('#wt').fill('68');
  for (const [group, value] of [['preg', 'no'], ['eat', 'no'], ['bg', 'White'], ['loss', 'no']]) await frame.locator(`[data-tap="${group}"] [data-val="${value}"]`).click();
  await frame.locator('#submitBtn').click(); await expect(frame.locator('[data-test="bmi"]')).toContainText('23.5');
});
