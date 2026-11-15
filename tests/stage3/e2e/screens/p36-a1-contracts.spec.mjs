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
test('TC-FR3-64-AS-04', async ({ page }) => {
  const member = await make(page, { access_state: 'Free' }); await asOwner(page);
  await page.goto('/members/admin/members/?member=' + member.member_id);
  const summary = page.locator('details.lld-adm-action > summary').filter({ hasText: /^Give free Premium$/ }); await summary.click();
  const form = summary.locator('xpath=..'); const until = new Date(Date.now() + 30 * 86400000).toISOString().slice(0,10);
  await form.locator('#lld-grant-end').fill(until); await form.locator('#lld-grant-reason').fill('Synthetic contract grant');
  await submitAndWait(page, form.getByRole('button', { name: 'Give free Premium', exact: true }));
  await expect(page.locator('[data-lld-adm-notice]')).toContainText('Free Premium given');
  await signIn(page, A, password);
  const nice = new Date(until + 'T00:00:00Z').toLocaleDateString('en-GB', { day:'numeric', month:'long', year:'numeric', timeZone:'UTC' });
  for (const path of ['/members/settings/?section=membership', '/members/subscription/']) {
    await page.goto(path); await expect(page.locator('.lld-chip')).toContainText(/Premium/i);
    const main = page.locator('main'); await expect(main).toContainText(/Premium/);
    expect((await main.innerText()).includes(until) || (await main.innerText()).includes(nice)).toBeTruthy();
    await expect(main.getByRole('link', { name: /Go Premium|Upgrade|Buy Premium/i })).toHaveCount(0);
  }
  const state = await page.request.post('/wp-json/lld-members/v1/test/billing', { data: { action:'read', email:A } });
  expect(state.ok()).toBeTruthy(); const billing = await state.json(); expect(JSON.stringify(billing)).not.toMatch(/sub_p36_|pi_p36_/);
});
