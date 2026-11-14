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
test('TC-NFR3-12-AS-01',async({page})=>{
  await make(page);await page.setViewportSize({width:360,height:800});
  for(const path of ['/members/settings/','/members/settings/?section=security','/members/more/']){await page.goto(path);expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);}
  await page.goto('/members/settings/');const ys=await page.locator('.lld-account-tab').evaluateAll(els=>els.map(el=>Math.round(el.getBoundingClientRect().top)));expect(new Set(ys).size).toBe(1);
  await asOwner(page);for(const width of [360,1440]){await page.setViewportSize({width,height:900});await page.goto('/members/admin/');expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);if(width===1440){const work=page.locator('.lld-adm-body');const box=await work.boundingBox();expect(box.width).toBeGreaterThan(1000);}}
});
test('TC-FR2-01-AS-02',async({page})=>{
  await page.goto('/members/sign-in/');const input=page.locator('#lld_password');const eye=page.getByRole('button',{name:'Show password',exact:true});
  await expect(input).toHaveAttribute('type','password');await eye.click();await expect(input).toHaveAttribute('type','text');await expect(page.getByRole('button',{name:'Hide password',exact:true})).toHaveAttribute('aria-pressed','true');
  const ib=await input.boundingBox(),eb=await page.getByRole('button',{name:'Hide password',exact:true}).boundingBox();expect(eb.x).toBeGreaterThanOrEqual(ib.x);expect(eb.x+eb.width).toBeLessThanOrEqual(ib.x+ib.width);
  await page.getByRole('button',{name:'Hide password',exact:true}).click();await expect(input).toHaveAttribute('type','password');
});
