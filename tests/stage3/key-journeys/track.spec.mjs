import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, signIn, startTrack } from './helpers.mjs';
test('TC-NFR3-03-AS-01 start a track', async ({ page }) => {
  const email = emailFor('track');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms', 'health'], access_state: 'Premium' }); await signIn(page, email, password);
  await startTrack(page); await page.goto('/members/track/?day=1'); await expect(page.getByRole('button', { name: 'Done', exact: true })).toBeVisible();
});
