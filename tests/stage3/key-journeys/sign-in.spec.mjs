import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, signIn } from './helpers.mjs';
test('TC-NFR3-03-AS-01 sign in', async ({ page }) => {
  const email = emailFor('signin');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms'] });
  await signIn(page, email, password);
  await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible();
});
