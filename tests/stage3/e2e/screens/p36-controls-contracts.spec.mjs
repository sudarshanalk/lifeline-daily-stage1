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
test('TC-NFR3-12-AS-02',async({page})=>{
  await make(page,{health_consent:true,consents:['terms','health']});await page.setViewportSize({width:360,height:800});await page.goto('/members/diary/');
  const more=page.getByRole('button',{name:'One more glass of water',exact:true});await expect(more).toBeVisible();await more.click();
  const hunger=page.getByRole('button',{name:'Hunger 3 of 5',exact:true});await hunger.click();await expect(hunger).toHaveAttribute('aria-pressed','true');
  const energy=page.getByRole('button',{name:'Energy 4 of 5',exact:true});await energy.click();await expect(energy).toHaveAttribute('aria-pressed','true');
  for(const button of [more,hunger,energy]){const b=await button.boundingBox();expect(b.width).toBeGreaterThanOrEqual(44);expect(b.height).toBeGreaterThanOrEqual(44);}
  await submitAndWait(page,page.getByRole('button',{name:'Save today',exact:true}));await page.reload();
  await expect(page.locator('[data-lld-day-extras]')).toContainText(/Water: 1 glasses?/);await expect(page.locator('[data-lld-day-extras]')).toContainText('Hunger: 3 of 5');
});
test('TC-FR2-25-AS-01',async({page})=>{
  const m=await make(page,{health_consent:true,consents:['terms','health']});await page.goto('/members/health/');
  await page.getByRole('checkbox',{name:'None of these',exact:true}).check();
  await submitAndWait(page,page.getByRole('button',{name:'Save',exact:true}));await expect(page.getByText('Saved',{exact:true})).toBeVisible();
  await page.goto('/members/health/');await expect(page.locator('#lld_height')).toHaveValue('');await expect(page.locator('#lld_weight')).toHaveValue('');
  const response=await page.request.post('/wp-json/lld-members/v1/members/'+m.member_id+'/health',{headers:{'X-WP-Nonce':await restNonce(page)},data:{kind:'profile',confirmed:true,sex:'female',height_cm:175,weight_kg:90,activity_level:'some',conditions_answered:'none'}});expect(response.ok()).toBeTruthy();
  await page.reload();expect(Number(await page.locator('#lld_height').inputValue())).toBe(175);expect(Number(await page.locator('#lld_weight').inputValue())).toBe(90);
  const sex=page.getByRole('group',{name:'Sex',exact:true});await expect(sex.getByRole('button')).toHaveCount(3);
  await sex.getByRole('button',{name:'Prefer not to say',exact:true}).click();await page.locator('#lld_confirm').check();
  await submitAndWait(page,page.getByRole('button',{name:'Save',exact:true}));await page.reload();await expect(sex.getByRole('button',{name:'Prefer not to say',exact:true})).toHaveAttribute('aria-pressed','true');
});
test('TC-FR2-32-AS-01',async({page})=>{
  const made=await fixture(page.request,'premium');await make(page,{health_consent:true,consents:['terms','health'],access_state:'Premium'});await startTrack(page,made.track);await page.goto('/members/meals/');
  await expect(page.locator('[data-lld-day]')).toHaveCount(7);const meal=page.locator('[data-lld-meal="1-breakfast"]');await expect(meal).toContainText(made.lentil);
  await meal.getByRole('button',{name:'Swap',exact:true}).click();const option=meal.locator('[data-lld-swap-panel]').getByRole('button',{name:made.oat,exact:true});await expect(option).toBeVisible();
  await submitAndWait(page,option);await expect(page.locator('[data-lld-meal="1-breakfast"]')).toContainText(made.oat);await page.reload();await expect(page.locator('[data-lld-meal="1-breakfast"]')).toContainText(made.oat);
});
