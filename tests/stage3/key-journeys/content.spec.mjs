import { expect, test } from '@playwright/test';
import { createMember, emailFor, fixture, password, signIn } from './helpers.mjs';
test('TC-NFR3-03-AS-01 open approved content', async ({ page }) => {
  const made = await fixture(page.request, 'days'); const email = emailFor('content');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms'], access_state: 'Premium' }); await signIn(page, email, password);
  await page.goto(`/members/library/${made.items[0].id}/`); await expect(page.locator('main')).toContainText(made.items[0].title);
});
