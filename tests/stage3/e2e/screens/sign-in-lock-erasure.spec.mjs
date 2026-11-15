// P3-36 F1 (TC-FR3-61-AS-03), live on the CI WordPress: the journey test's sequence. A member gets a wrong password,
// the account is deleted, and then someone tries to sign in with that email again. The erasure checker
// (/test/scan, LLD_Members_Privacy::scan with the email) must find no sign-in lock row holding the email, both straight
// after the deletion and after the later attempts, which is when the journey test found the leftover row. The
// account is deleted by the real Stage 2 retention run (two years without signing in), as TC-NFR2-01-AS-03 does.
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { createMember } from '../../../stage2/e2e/helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const GONE = 'p3-f1-gone@test.lifelinedaily.invalid';
const stamp = (date) => date.toISOString().slice(0, 19).replace('T', ' ');

async function attempt(page, email, typed) {
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await page.context().clearCookies();
  await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(typed);
  await page.locator('#lld_password').press('Enter');
  await page.waitForLoadState('domcontentloaded');
}

async function scan(page, memberId) {
  const res = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: memberId, email: GONE } });
  expect(res.ok()).toBeTruthy();
  return res.json();
}

test('TC-FR3-61-AS-03 After an account is deleted, no table holds the member\'s email, including the sign-in lock records', async ({ page }) => {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: GONE } });
  const created = await createMember(page.request, { email: GONE, password, first_name: 'Idle', role: 'member', access_state: 'free' });
  // A wrong password while the account exists: the lock table counts it under the email.
  await attempt(page, GONE, `${password}-wrong`);
  expect((await scan(page, created.member_id)).records.sign_in_lock).toBe(1);

  // The account is deleted by the retention run: the warning, then the deletion 24 months after joining.
  const deleteOn = new Date(`${created.joined_on}T00:00:00Z`);
  deleteOn.setUTCMonth(deleteOn.getUTCMonth() + 24);
  const warn = new Date(deleteOn);
  warn.setUTCDate(warn.getUTCDate() - 30);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: GONE, utc: stamp(warn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: GONE, utc: stamp(deleteOn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const after = await scan(page, created.member_id);
  expect(after.user_id).toBe(0);
  expect(after.records.sign_in_lock).toBe(0);
  expect(after.problems.join(' ')).not.toContain('sign_in_lock');

  // "Can't sign in again": attempts with the deleted email are still refused, and still leave no row with the email.
  for (const typed of [password, `${password}-again`]) {
    await attempt(page, GONE, typed);
    await expect(page).toHaveURL(/\/members\/sign-in/);
  }
  const later = await scan(page, created.member_id);
  expect(later.records.sign_in_lock).toBe(0);
  expect(later.problems.join(' ')).not.toContain('sign_in_lock');
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: GONE } });
});
