import { test, expect } from '@playwright/test';
import { createMember, signIn, password, fixture, startTrack } from '../../key-journeys/helpers.mjs';
import { asOwner, restNonce, submitAndWait } from '../../../stage2/e2e/helpers.mjs';
const A = 'p3-api-a@test.lifelinedaily.invalid';
async function make(page, fields = {}) {
  const reset = await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: A } }); expect(reset.ok()).toBeTruthy();
  const member = await createMember(page.request, { email: A, password, first_name: 'Alex', consents: ['terms'], ...fields });
  await signIn(page, A, password); return member;
}
async function call(page, path, method = 'GET', data) {
  const response = await page.request.fetch('/wp-json/lld-members/v1/me/' + path, { method, headers: { 'X-WP-Nonce': await restNonce(page) }, ...(data === undefined ? {} : { data }) });
  expect(response.ok(), await response.text()).toBeTruthy(); return response.json();
}
async function clock(page, utc) { const r = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: A, utc } }); expect(r.ok()).toBeTruthy(); }
async function prepareTrack(page) {
  const made = await fixture(page.request, 'weeks'); await make(page, { health_consent:true, access_state:'Premium', consents:['terms','health'] });
  await clock(page, '2026-10-01 12:00:00'); await startTrack(page, made.track); return made;
}
async function doneDay(page, day) {
  const date = new Date(Date.UTC(2026,9,day)).toISOString().slice(0,10); await clock(page, date + ' 12:00:00');
  await page.goto('/members/track/?day=' + day); await submitAndWait(page, page.getByRole('button', { name:'Done', exact:true }).first());
}
test('TC-FR3-112-AS-01', async ({ page }) => {
  test.slow(); // seven days, each a clock change, a page and a form post
  await prepareTrack(page); for (let d=1;d<=7;d++) await doneDay(page,d); await clock(page,'2026-10-08 12:00:00');
  await page.goto('/members/track/'); const week=page.locator('.lld-week').first(); await expect(week).toContainText('Completed');
  await expect(week.locator('.lld-week-num')).toHaveText('✓'); await expect(week).toContainText('7 of 7 days done');
});
test('TC-FR3-112-AS-02', async ({ page }) => {
  await prepareTrack(page); for (let d=1;d<=3;d++) await doneDay(page,d); await clock(page,'2026-10-08 12:00:00');
  await page.goto('/members/track/'); const week=page.locator('.lld-week').first(); await expect(week).toContainText('3 of 7 days done');
  await expect(week).not.toContainText('Completed'); await expect(week.locator('.lld-week-num')).not.toHaveText('✓');
});
test('TC-FR3-112-AS-03', async ({ page }) => {
  await prepareTrack(page); await clock(page,'2026-11-01 12:00:00'); await page.goto('/members/track/');
  await expect(page.locator('main')).toContainText('0 of 28 done'); await expect(page.locator('.lld-week')).toHaveCount(4);
  for (const week of await page.locator('.lld-week').all()) { await expect(week).not.toContainText('Completed'); await expect(week).toContainText('0 of 7 days done'); await expect(week.locator('.lld-week-num')).not.toHaveText('✓'); }
});
