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
test('TC-FR3-18-AS-01',async({page})=>{
  const m=await make(page,{health_consent:true,consents:['terms','health']});await page.goto('/members/health/');
  const p=await page.request.post('/wp-json/lld-members/v1/members/'+m.member_id+'/health',{headers:{'X-WP-Nonce':await restNonce(page)},data:{kind:'profile',confirmed:true,sex:'female',height_cm:175,weight_kg:90,activity_level:'some',conditions_answered:'none'}});expect(p.ok()).toBeTruthy();
  await call(page,'goals','POST',{role:'main',goal_type:'activity',measure:'activity_minutes',unit:'minutes',start_value:0,target_value:150,status:'active'});await page.goto('/members/home/');
  for(const [name,path] of [['Log one','/members/readings/'],['Log a meal','/members/food/']]){const link=page.locator('main').getByRole('link',{name,exact:true});await expect(link).toHaveAttribute('href',new RegExp(path+'$'));const navigation=page.waitForNavigation();await link.click();const response=await navigation;expect(response.status()).toBe(200);await expect(page).toHaveURL(new RegExp(path+'await page.goto('/members/home/');}
  await page.goto('/members/more/');for(const path of ['/members/food/','/members/readings/'])await expect(page.locator('main a[href$="'+path+'"]').first()).toBeVisible();
});
));await expect(page.getByRole('heading',{name:path.includes('readings')?/Log a reading|Readings/:/Food|My meals/,exact:true}).first()).toBeVisible();await page.goto('/members/home/');}
  await page.goto('/members/more/');for(const path of ['/members/food/','/members/readings/'])await expect(page.locator('main a[href$="'+path+'"]').first()).toBeVisible();
});
