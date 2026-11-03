import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, signIn } from './helpers.mjs';
test('TC-NFR3-03-AS-01 choose a goal', async ({ page }) => {
  const email = emailFor('goal');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms', 'health'] }); await signIn(page, email, password);
  await page.goto('/members/goals/'); const goal = page.locator('[data-lld-goal]').first(); await expect(goal).toBeVisible(); await goal.check();
  await page.getByRole('button', { name: 'Save goals', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible();
});
