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
// P3-36 B3 (from #275, repaired by the lead: line 19 had a corrupted RegExp and a duplicated closing fragment).
// Today's "Log one" and "Log a meal" must open a page that exists. The Food page (#264) and the Log a reading page
// (#267) are merged: the links go there when the page is on for the member, and keep their old addresses when it is off.
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const screen = (file) => existsSync(fileURLToPath(new URL('../../../../site/public_html/wp-content/plugins/lld-members/includes/stage3/screens/' + file, import.meta.url)));
const built = { food: screen('class-food-screen.php'), readings: screen('class-readings-screen.php') };
const LINKS = [['Log one', '/members/readings/', '/members/check-in/', 'readings'], ['Log a meal', '/members/food/', '/members/diary/', 'food']];
// "Log a meal" is the action line of Quick log's Meals tile (the link's full name is "Meals Log a meal").
const linkFor = (page, name) => name === 'Log a meal' ? page.locator('main a[data-lld-tile="meals"]').filter({ hasText: name }) : page.locator('main').getByRole('link', { name, exact: true });
const end = (path) => new RegExp(path.replace(/\//g, '\/') + '$');
async function activityGoalMember(page) {
  const m=await make(page,{health_consent:true,consents:['terms','health']});await page.goto('/members/health/');
  const p=await page.request.post('/wp-json/lld-members/v1/members/'+m.member_id+'/health',{headers:{'X-WP-Nonce':await restNonce(page)},data:{kind:'profile',confirmed:true,sex:'female',height_cm:175,weight_kg:90,activity_level:'some',conditions_answered:'none'}});expect(p.ok()).toBeTruthy();
  await call(page,'goals','POST',{role:'main',goal_type:'activity',measure:'activity_minutes',unit:'minutes',start_value:0,target_value:150,status:'active'});await page.goto('/members/home/');
}
async function follow(page, name, path) {
  const link=linkFor(page,name);await expect(link).toHaveAttribute('href',end(path));
  const navigation=page.waitForNavigation();await link.click();const response=await navigation;expect(response.status()).toBe(200);await expect(page).toHaveURL(end(path));
}
// Whichever address a link carries, it opens (never a 404), and More does not list a page that is not there.
test('TC-FR3-18-AS-01 Today links open a page that exists',async({page})=>{
  await activityGoalMember(page);
  for(const [name,next,old,key] of LINKS){
    const href=await linkFor(page,name).getAttribute('href');
    // The new page, or the old one while the page is off for this member.
    await follow(page,name,built[key]&&end(next).test(href)?next:old);await page.goto('/members/home/');
  }
  await page.goto('/members/more/');
  for(const [,next,,key] of LINKS)if(!built[key])await expect(page.locator('main a[href$="'+next+'"]')).toHaveCount(0);
});
// #275's original assertion, unchanged in what it checks. Active since #264 (Food page) and #267 (Log a reading) merged.
test('TC-FR3-18-AS-01 Today and More link to the Food page and Log a reading',async({page})=>{
  await activityGoalMember(page);
  for(const [name,path] of LINKS){await follow(page,name,path);await expect(page.getByRole('heading',{name:path.includes('readings')?/Log a reading|Readings/:/Food|My meals/,exact:true}).first()).toBeVisible();await page.goto('/members/home/');}
  await page.goto('/members/more/');for(const path of ['/members/food/','/members/readings/'])await expect(page.locator('main a[href$="'+path+'"]').first()).toBeVisible();
});
