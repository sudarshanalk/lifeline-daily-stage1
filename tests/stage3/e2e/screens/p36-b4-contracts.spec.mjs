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
async function addReminder(page, type='log') {
  const form=page.locator('.lld-reminders-add'); await form.locator('#lld_reminder_type').selectOption(type); await form.locator('input[type="time"]').fill('08:00');
  await submitAndWait(page,form.getByRole('button',{name:'Save',exact:true}));
}
test('TC-FR3-56-AS-01', async ({ page }) => {
  await make(page); await page.goto('/members/settings/?section=notifications'); await addReminder(page);
  const body=await call(page,'reminders'); const records=body.rows||body; expect(records).toHaveLength(1); expect(records[0].time_local.slice(0,5)).toBe('08:00'); expect(records[0].channel).toBe('email');
  await page.reload(); await expect(page.locator('.lld-reminder-row input[type="time"]')).toHaveValue('08:00');
  test.info().annotations.push({type:'coverage',description:'UI saves dispatcher configuration; dispatch/mail content covered by existing reminders.test.mjs'});
});
test('TC-FR3-56-AS-03', async ({ page }) => {
  for (const access_state of ['Free','Premium']) {
    await make(page,{access_state}); await page.goto('/members/settings/?section=notifications'); await addReminder(page); await addReminder(page,'workout');
    const body=await call(page,'reminders'); expect((body.rows||body).length).toBe(access_state==='Free'?1:2);
    if(access_state==='Free') await expect(page.locator('.lld-reminders-hub')).toContainText(/Premium/);
  }
});
test('TC-FR3-56-AS-04', async ({ page }) => {
  await make(page); await page.goto('/members/settings/?section=notifications'); await addReminder(page);
  await page.locator('.lld-reminder-row input[type="checkbox"]').first().uncheck();
  await submitAndWait(page,page.locator('.lld-reminders-list').getByRole('button',{name:'Save',exact:true}));
  await page.reload(); await expect(page.locator('.lld-reminder-row input[type="checkbox"]').first()).not.toBeChecked();
  const body=await call(page,'reminders'); expect(Number((body.rows||body)[0].active)).toBe(0);
});
test('TC-FR3-69-AS-01', async ({ page }) => {
  const member=await make(page,{health_consent:true,consents:['terms','health']}); await page.goto('/members/health/');
  const r=await page.request.post('/wp-json/lld-members/v1/members/'+member.member_id+'/health',{headers:{'X-WP-Nonce':await restNonce(page)},data:{kind:'profile',confirmed:true,conditions_answered:'declared',conditions:'["eating-disorder"]'}}); expect(r.ok()).toBeTruthy();
  await page.goto('/members/settings/?section=notifications'); await expect(page.locator('#lld_reminder_type option[value="weigh_in"]')).toHaveCount(0);
  await expect(page.locator('[data-lld-reminders] option').filter({hasText:'Browser'})).toHaveCount(0);
});
