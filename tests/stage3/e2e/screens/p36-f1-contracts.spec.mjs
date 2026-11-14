import { test, expect } from '@playwright/test';
import { createMember, signIn, password, fixture, startTrack } from '../../key-journeys/helpers.mjs';
import { asOwner, restNonce, submitAndWait } from '../../../stage2/e2e/helpers.mjs';
const A = 'p36-erasure-lock@test.lifelinedaily.invalid';
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
test('TC-FR3-61-AS-03',async({page,browser})=>{
  const member=await make(page);
  const other=await browser.newContext({...test.info().project.use});
  try {
    const attempt=await other.newPage();await attempt.goto('/members/sign-in/');
    await attempt.locator('#lld_email').fill(A);await attempt.locator('#lld_password').fill('synthetic-wrong-password');
    await submitAndWait(attempt,attempt.getByRole('button',{name:'Sign in',exact:true}));
    const before=await page.request.post('/wp-json/lld-members/v1/test/search',{data:{q:A}});expect(before.ok()).toBeTruthy();expect((await before.json()).hits.some(h=>/sign_in_lock/.test(h))).toBe(true);
    await page.goto('/members/delete/');await page.locator('#lld_password').fill(password);await submitAndWait(page,page.getByRole('button',{name:'Delete my account',exact:true}));
    await expect(page.locator('main')).toContainText('Your account is closed.');
    await expect.poll(async()=>{const r=await page.request.post('/wp-json/lld-members/v1/test/search',{data:{q:A}});expect(r.ok()).toBeTruthy();return (await r.json()).hits;}).toEqual([]);
    const scan=await page.request.post('/wp-json/lld-members/v1/test/scan',{data:{member_id:member.member_id,scope:'account',email:A}});expect(scan.ok()).toBeTruthy();expect((await scan.json()).problems).toEqual([]);
  } finally {await other.close();}
});
