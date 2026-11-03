import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, signIn, startTrack } from './helpers.mjs';
test('TC-NFR3-03-AS-01 food diary', async ({ page }) => {
  const email = emailFor('diary');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms', 'health'], access_state: 'Premium' }); await signIn(page, email, password); await startTrack(page);
  await page.goto('/members/diary/'); await page.locator('#lld_description').fill('SENTINEL-DIARY-7731'); await page.getByRole('button', { name: 'Save meal', exact: true }).click();
  await expect(page.locator('main')).toContainText('SENTINEL-DIARY-7731');
});
