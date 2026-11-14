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
test('TC-FR3-113-AS-01', async ({ page }) => {
  await make(page, { consents:[] }); await expect(page).toHaveURL(/\/members\/agreements\//);
});
test('TC-FR3-113-AS-02', async ({ page }) => {
  await make(page, { consents:[] }); await page.goto('/members/tracks/');
  await expect(page).toHaveURL(/\/members\/agreements\//); await expect(page.locator('input[name="lld_track_nonce"]')).toHaveCount(0);
});
test('TC-FR3-113-AS-03', async ({ page }) => {
  await make(page, { consents:[] });
  const article=await fixture(page.request,'article');
  const articleResponse=await page.goto('/?p='+article.id);expect(articleResponse.status()).toBe(200);await expect(page.getByRole('heading',{name:article.title,exact:true}).first()).toBeVisible();
  for (const path of ['/health-tools/', '/tools/protein-requirement-tool.html', '/members/help/']) {
    const response = await page.goto(path); expect(response.status()).toBe(200); expect(new URL(page.url()).pathname).not.toBe('/members/agreements/');
  }
});
test('TC-FR3-113-AS-04', async ({ page }) => {
  await make(page, { consents:[] }); await page.goto('/members/tracks/'); await expect(page).toHaveURL(/\/members\/agreements\//);
  await page.locator('input[name="lld_terms"]').check(); await submitAndWait(page, page.getByRole('button', { name:'Continue', exact:true }));
  await expect(page).toHaveURL(/\/members\/tracks\//);
});
