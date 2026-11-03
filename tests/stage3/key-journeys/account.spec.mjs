import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, signIn } from './helpers.mjs';
test('TC-NFR3-03-AS-01 account and privacy pages', async ({ page }) => {
  const email = emailFor('account'); await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms', 'health'] }); await signIn(page, email, password);
  for (const [path, heading] of [['settings', 'Profile and settings'], ['data', 'My data'], ['withdraw', 'Withdraw health consent'], ['delete', 'Delete account']]) {
    const response = await page.goto(`/members/${path}/`); expect(response.status()).toBe(200); await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
  }
});
