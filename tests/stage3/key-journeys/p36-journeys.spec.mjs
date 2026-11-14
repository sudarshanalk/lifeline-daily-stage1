// The 56 member journeys of the journey tests report (P3-36 T1), one test per scenario (p36-scenario-table.md).
// They run the site as it launches: the switches staging has on and CI leaves off are turned on for this spec and
// put back afterwards (p36-switches.mjs).
import { test, expect } from '@playwright/test';
import { fresh, api, rows, custom, logFood, reading, openFood, DAY, A } from './p36-fixtures.mjs';
import { fixture, startTrack } from './helpers.mjs';
import { register, confirm, mail, accept, logout, weightGoal, activityGoal, resetPassword,
  grant, downloadData, deleteAccount, pay, notice, password, signIn, createMember, asOwner, submitAndWait } from './p36-journey-support.mjs';

import { switchesOn, switchesBack } from './p36-switches.mjs';

const LAUNCH = ['terms_first', 'prices_launch', 'access_tiers', 'membership_card', 'pause'];
let switches;
test.beforeAll(async ({ request }) => { switches = await switchesOn(request, LAUNCH); });
test.afterAll(async ({ request }) => { await switchesBack(request, switches); });

function scenario(id, assertion, run) {
  test.describe(id, () => test(assertion, async (args) => {
    test.info().annotations.push({ type: 'scenario', description: id });
    await run(args);
  }));
}
async function today(page) { await fresh(page); await activityGoal(page); await page.goto('/members/home/'); }
async function track(page) { const made = await fixture(page.request, 'weeks'); await fresh(page); await activityGoal(page); await startTrack(page, made.track); return made; }

scenario('UC1-S1', 'TC-NFR3-03-AS-01', async ({ page }) => {
  const response = await page.goto('/'); expect(response.status()).toBe(200); await expect(page.locator('main')).toBeVisible();
});
scenario('UC1-S2', 'TC-NFR3-03-AS-01', async ({ page }) => {
  await page.goto('/health-tools/'); await expect(page.locator('body')).toContainText('Daily Protein Target');
  await expect(page.locator('body')).toContainText(/Diabetes Risk/);
});
scenario('UC1-S3', 'TC-FR3-62-AS-01', async ({ page }) => {
  await page.goto('/pricing/'); await expect(page.locator('main')).toContainText('9.99');
  await expect(page.locator('main')).toContainText('69.99'); await expect(page.locator('main')).toContainText(/tax included/i);
});
scenario('UC1-S4', 'TC-NFR3-03-AS-01', async ({ page }) => {
  const response = await page.goto('/tools/protein-requirement-tool.html'); expect(response.ok()).toBeTruthy();
  await expect(page.locator('body')).toContainText(/Daily Protein Target/i);
  await expect(page.locator('input[type="number"]').first()).toBeVisible();
});
scenario('UC2-S1', 'TC-FR2-01-AS-01', async ({ page }) => { await register(page); });
scenario('UC2-S2', 'TC-FR2-01-AS-01', async ({ page }) => {
  const email = await register(page); const message = await mail(email, 'Confirm');
  expect(message.Text || message.HTML).toMatch(/\/members\/confirm\/\?token=/);
});
scenario('UC2-S3', 'TC-FR2-01-AS-01', async ({ page }) => { const email = await register(page); await confirm(page, email); });
scenario('UC2-S4', 'TC-FR2-03-AS-01', async ({ page }) => {
  const email = await register(page); await confirm(page, email); await signIn(page, email, password);
  await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
});
scenario('UC2-S5', 'TC-FR2-03-AS-01', async ({ page }) => {
  const email = await register(page); await confirm(page, email); await signIn(page, email, password); await accept(page);
  await expect(page).toHaveURL(/\/members\/(?:goals?|health)\//);
});
scenario('UC2-S6', 'TC-FR3-113-AS-01', async ({ page }) => {
  const reset = await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: 'p36-unagreed@test.lifelinedaily.invalid' } }); expect(reset.ok()).toBeTruthy();
  await createMember(page.request, { email: 'p36-unagreed@test.lifelinedaily.invalid', password, first_name: 'Synthetic', consents: [] });
  await signIn(page, 'p36-unagreed@test.lifelinedaily.invalid', password);
  await expect(page).toHaveURL(/\/members\/agreements\//);
});
scenario('UC3-S1', 'TC-FR3-04-AS-04', async ({ page }) => {
  await fresh(page); await logout(page); await signIn(page, A, password); await page.goto('/members/settings/');
  await expect(page.locator('.lld-stage3-account-hub')).toBeVisible();
});
scenario('UC3-S2', 'TC-FR3-07-AS-02', async ({ page }) => {
  await fresh(page); await page.goto('/members/more/');
  // Wait for the sign-out post to finish: opening the next page at once can cancel it (WebKit).
  await submitAndWait(page, page.locator('main').getByRole('button', { name: 'Sign out', exact: true }));
  await page.goto('/members/settings/'); await expect(page).toHaveURL(/\/members\/sign-in\//);
});
scenario('UC3-S3', 'TC-FR2-01-AS-03', async ({ page }) => {
  await fresh(page); await logout(page); await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(A); await page.locator('#lld_password').fill('synthetic-wrong-password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText("Those details don't match our records.");
});
scenario('UC3-S4', 'TC-FR3-04-AS-01', async ({ page }) => {
  const email = 'p3-signin-b@test.lifelinedaily.invalid';
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms'] });
  await logout(page); await page.goto('/members/sign-in/?code=1'); await page.locator('#lld_email').fill(email);
  const sent = Date.now();
  await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
  const message = await mail(email, 'sign-in code', sent); const code = String(message.Text).match(/\b\d{6}\b/)?.[0];
  expect(code).toBeTruthy(); await page.locator('#lld_code').fill(code);
  await submitAndWait(page, page.getByRole('button', { name: 'Continue', exact: true }));
  await page.goto('/members/settings/'); await expect(page.locator('.lld-stage3-account-hub')).toBeVisible();
});
scenario('UC3-S5', 'TC-NFR3-03-AS-01', async ({ page }) => {
  await fresh(page); const next = await resetPassword(page, A); await signIn(page, A, next);
  await page.goto('/members/settings/'); await expect(page.locator('.lld-stage3-account-hub')).toBeVisible();
});
scenario('UC3-S6', 'TC-NFR3-03-AS-01', async ({ page }) => {
  await fresh(page); await resetPassword(page, A); await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(A); await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click(); await expect(page.getByRole('alert')).toBeVisible();
});
scenario('UC3-S7', 'TC-FR3-01-AS-01', async ({ page }) => {
  await logout(page); await page.goto('/members/sign-in/?code=1');
  const button = page.getByRole('link', { name: 'Continue with Google', exact: true }); await expect(button).toBeVisible();
  const start = await page.request.get(await button.getAttribute('href'), { maxRedirects: 0 });
  expect(start.status()).toBe(302); expect(start.headers().location).toContain('accounts.google.com');
  // CI provider stand-in, never a claim that a real Google account was tested.
  const email = 'p3-signin-f@test.lifelinedaily.invalid';
  const reset = await page.request.post('/wp-json/lld-members/v1/test/reset', {data:{email}}); expect(reset.ok()).toBeTruthy();
  try {
  const result = await page.request.post('/wp-json/lld-members/v1/test/sign-in/provider', { data: {
    provider: 'google', sub: email, email, email_verified: true, first_name: 'Synthetic', last_name: 'Member',
  } });
  expect(result.ok()).toBeTruthy(); await page.goto((await result.json()).redirect);
  await page.locator('#lld_age').fill('34'); await page.getByRole('button', { name: 'Create account', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
  } finally { await page.request.post('/wp-json/lld-members/v1/test/reset',{data:{email}}); }
});
scenario('UC4-S1', 'TC-FR3-69-AS-06', async ({ page }) => {
  await fresh(page, { profile: false }); await page.goto('/members/health/');
  await page.locator('#lld_height').fill('175'); await page.locator('#lld_weight').fill('90');
  await page.locator('#lld_confirm').check(); await page.getByRole('checkbox', { name: 'None of these', exact: true }).check();
  await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
  // The "Saved" line also holds an Undo button, so its text is not "Saved" alone.
  await expect(page.locator('main [role="status"]').filter({ hasText: 'Saved' })).toBeVisible();
});
scenario('UC4-S2', 'TC-FR3-09-AS-08', async ({ page }) => {
  await fresh(page, { profile: false }); await page.goto('/members/goal/');
  await expect(page).toHaveURL(/\/members\/health\//); await expect(page.getByRole('group', { name: 'Conditions' })).toBeVisible();
});
scenario('UC4-S3', 'TC-FR3-10-AS-01', async ({ page }) => { await weightGoal(page); await expect(page.locator('main')).toContainText('84'); });
scenario('UC4-S4', 'TC-FR3-11-AS-01', async ({ page }) => {
  await weightGoal(page); await page.goto('/members/goal/');
  await expect(page.locator('main')).toContainText('84'); await expect(page.getByRole('link', { name: /Today/ })).toBeVisible();
});
scenario('UC4-S5', 'TC-FR3-13-AS-03', async ({ page }) => {
  await weightGoal(page); await page.goto('/members/progress/'); await expect(page.locator('[data-lld-goal-progress]')).toBeVisible();
});
scenario('UC5-S1', 'TC-FR3-12-AS-02', async ({ page }) => {
  await track(page); await page.goto('/members/home/');
  const ring = page.locator('[data-lld-section="ring"]'); const before = Number(await ring.getAttribute('data-lld-ring-done'));
  await submitAndWait(page, page.locator('[data-lld-section="next-step"]').getByRole('button', { name: 'Done', exact: true }));
  await expect(page.locator('[data-lld-section="next-step"]')).toHaveAttribute('data-lld-step-status', 'done');
  expect(Number(await ring.getAttribute('data-lld-ring-done'))).toBe(before + 1);
});
scenario('UC5-S2', 'TC-FR3-43-AS-01', async ({ page }) => {
  await today(page); await page.getByRole('button', { name: /Water.*\+1 glass/ }).click();
  await expect.poll(async () => rows(await api(page, 'readings')).filter(r => r.reading_type === 'water').reduce((n, r) => n + Number(r.value_1), 0)).toBe(250);
});
scenario('UC5-S3', 'TC-FR3-18-AS-01', async ({ page }) => {
  await today(page);
  for (const [name, path] of [['Log one', '/members/readings/'], ['Log a meal', '/members/food/']]) {
    // "Log a meal" is the action line of Quick log's Meals tile (the link's full name is "Meals Log a meal").
    const link = name === 'Log a meal' ? page.locator('main a[data-lld-tile="meals"]').filter({ hasText: name }) : page.locator('main').getByRole('link', { name, exact: true });
    await expect(link).toHaveAttribute('href', new RegExp(`${path}$`));
    await link.click(); await expect(page).toHaveURL(new RegExp(`${path}$`)); await page.goto('/members/home/');
  }
});
scenario('UC6-S1', 'TC-FR3-38-AS-01', async ({ page }) => {
  await fresh(page); await page.goto('/members/readings/');
  await expect(page.getByRole('heading', { name: /Log a reading|Readings/, exact: true })).toBeVisible();
  await expect(page.getByRole('tab', { name: 'Blood pressure', exact: true }).or(page.getByRole('radio', { name: 'Blood pressure', exact: true })).or(page.getByRole('link', { name: 'Blood pressure', exact: true }))).toBeVisible();
});
scenario('UC6-S2', 'TC-FR3-69-AS-05', async ({ page }) => {
  await fresh(page, { profile: false });
  const response = await page.request.post('/wp-json/lld-members/v1/me/readings', { headers: { 'X-WP-Nonce': await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content') }, data: { reading_type: 'weight', taken_at: `${DAY} 09:00:00`, value_1: 90 } });
  expect(response.status()).toBe(403); const body = await response.json(); expect(body.message).toMatch(/conditions/i); expect(body.link).toContain('/members/health/');
});
scenario('UC6-S3', 'TC-FR3-38-AS-01', async ({ page }) => {
  await fresh(page); await reading(page, 'weight', 92, { unit_shown: 'kg' });
  await reading(page, 'blood_pressure', 128, { value_2: 82 }); await reading(page, 'blood_pressure', 130, { value_2: 84 });
  await reading(page, 'glucose', 5.6, { unit_shown: 'mmol/L' }); expect(rows(await api(page, 'readings'))).toHaveLength(4);
});
scenario('UC6-S4', 'TC-FR3-46-AS-01', async ({ page }) => {
  await fresh(page); const saved = await reading(page, 'blood_pressure', 186, { value_2: 122 });
  expect(saved.warning.level).toBe('urgent'); expect(saved.warning.message).toBeTruthy(); expect(Number(saved.urgent_shown)).toBe(1);
});
scenario('UC6-S5', 'TC-FR3-18-AS-01', async ({ page }) => {
  await fresh(page); await activityGoal(page); await reading(page, 'weight', 92, { unit_shown: 'kg' }); await reading(page, 'blood_pressure', 128, { value_2: 82 });
  await page.goto('/members/home/'); await expect(page.locator('main')).toContainText('92'); await expect(page.locator('main')).toContainText('128/82');
});
scenario('UC7-S1', 'TC-FR2-37-AS-01', async ({ page }) => {
  await fresh(page); await page.goto('/members/diary/');
  for(let n=0;n<6;n++) await page.getByRole('button',{name:'Add a glass of water',exact:true}).click();
  await page.getByRole('button',{name:'Hunger 3 of 5',exact:true}).click();
  await page.getByRole('button',{name:'Energy 3 of 5',exact:true}).click();
  await submitAndWait(page,page.getByRole('button',{name:'Save today',exact:true}));
  await page.reload(); await expect(page.locator('[data-lld-day-extras]')).toContainText('Water: 6 glasses');
  await expect(page.locator('[data-lld-day-extras]')).toContainText('Hunger: 3 of 5');
  await expect(page.locator('#lld_water')).toHaveValue('6');
});
scenario('UC7-S2', 'TC-FR3-26-AS-01', async ({ page }) => {
  await fresh(page); await openFood(page); await page.locator('[data-lld-open-add="breakfast"]').click();
  await expect(page.getByRole('searchbox', { name: 'Search foods', exact: true })).toBeVisible();
});
scenario('UC7-S3', 'TC-FR3-26-AS-01', async ({ page }) => {
  await fresh(page); await custom(page, 'Synthetic banana', { kcal: 89 });
  expect(JSON.stringify(await api(page, 'food-search?q=banana'))).toContain('Synthetic banana');
});
scenario('UC7-S4', 'TC-FR3-27-AS-01', async ({ page }) => {
  await fresh(page); await activityGoal(page); const banana = await custom(page, 'Synthetic banana', { kcal: 89, protein_g: 1.1 });
  const saved = await logFood(page, banana, { grams: 120, meal: 'breakfast' }); expect(Number(saved.kcal)).toBeCloseTo(106.8, 1);
  await page.goto('/members/home/'); await expect(page.locator('main')).toContainText('107');
});
scenario('UC7-S5', 'TC-FR3-63-AS-04', async ({ page }) => {
  await fresh(page, { access_state: 'Free' }); await page.goto('/members/food/scan/');
  await expect(page.locator('main')).toContainText('Part of Premium'); await expect(page.getByRole('link', { name: /Premium|Upgrade/i }).first()).toHaveAttribute('href', /\/pricing\//);
});
scenario('UC8-S1', 'TC-NFR3-03-AS-01', async ({ page }) => {
  await track(page); await page.goto('/members/track/'); await expect(page.locator('main')).toContainText('0 of 28 done');
});
scenario('UC8-S2', 'TC-FR3-112-AS-02', async ({ page }) => {
  await track(page); await page.goto('/members/track/'); await expect(page.locator('main')).toContainText('0 of 28 done');
  await expect(page.locator('main')).not.toContainText('Completed');
});
scenario('UC8-S3', 'TC-FR3-112-AS-03', async ({ page }) => {
  await track(page); const response = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: A, utc: '2026-12-07 12:00:00' } }); expect(response.ok()).toBeTruthy();
  await page.goto('/members/track/'); await expect(page.locator('main')).toContainText('0 of 28 done'); await expect(page.locator('main')).not.toContainText('Completed');
});
scenario('UC9-S1', 'TC-FR3-14-AS-03', async ({ page }) => {
  await weightGoal(page, { access_state: 'Free' }); await page.goto('/members/check-in/'); await page.locator('#lld_weight').fill('88.6');
  await submitAndWait(page, page.locator('[data-lld-section="measurements"] button[type="submit"]'));
  await expect(page.locator('main')).toContainText(/Check-in saved/i); await expect(page.locator('main')).toContainText('1.4');
});
scenario('UC10-S1', 'TC-FR3-63-AS-04', async ({ page }) => {
  await fresh(page, { access_state: 'Free' });
  for (const path of ['meals', 'workout', 'challenge', 'badges']) { await page.goto(`/members/${path}/`); await expect(page.locator('main')).toContainText('Part of Premium'); }
});
scenario('UC10-S2', 'TC-FR3-62-AS-03', async ({ page }) => {
  await fresh(page, { access_state: 'Free' }); await page.goto('/members/checkout/?plan=monthly');
  await expect(page.locator('#lld_country')).toBeVisible(); await expect(page.locator('#lld_code')).toBeVisible();
  await expect(page.locator('#lld_sale_terms')).not.toBeChecked();
});
scenario('UC10-S3', 'TC-FR3-62-AS-03', async ({ page }) => {
  await fresh(page, { access_state: 'Free' }); const paid = await pay(page); expect(paid.amount).toBe(999);
  await page.goto('/members/subscription/'); await expect(page.locator('main')).toContainText(/Premium/);
  test.info().annotations.push({ type: 'provider', description: 'CI signed Stripe stand-in; real-card acceptance remains external' });
});
scenario('UC10-S4', 'TC-FR3-80-AS-01', async ({ page }) => {
  const made = await fixture(page.request, 'premium'); const member = await fresh(page, { access_state: 'Free' }); await activityGoal(page); await startTrack(page, made.track);
  await grant(page, member); await signIn(page, A, password);
  await page.goto('/members/meals/'); await expect(page).toHaveURL(/\/members\/meals\//); await expect(page.locator('main')).toContainText(made.lentil);
  await expect(page.locator('main .lld-lock')).toHaveCount(0);
  await page.goto('/members/workout/?level=beginner&place=home'); await expect(page).toHaveURL(/\/members\/workout\//);
  await expect(page.locator('main')).toContainText(made.exercise); await expect(page.locator('main .lld-lock')).toHaveCount(0);
});
scenario('UC10-S5', 'TC-FR3-64-AS-04', async ({ page }) => {
  const member = await fresh(page, { access_state: 'Free' }); const until = await grant(page, member); await signIn(page, A, password);
  for (const path of ['/members/settings/?section=membership', '/members/subscription/']) {
    await page.goto(path); await expect(page.locator('.lld-chip')).toContainText(/Premium/i);
    await expect(page.locator('main')).toContainText(/Premium/); await expect(page.locator('main')).not.toContainText('Go Premium');
    const date=new Date(until+'T00:00:00Z'); const formats=[until,...['long','short'].map(month=>date.toLocaleDateString('en-GB',{day:'numeric',month,year:'numeric',timeZone:'UTC'}))];
    const text=await page.locator('main').innerText(); expect(formats.some(v=>text.includes(v))).toBe(true);
  }
});
scenario('UC10-S6', 'TC-FR3-65-AS-06', async ({ page }) => {
  await fresh(page, { access_state: 'Free' }); const paid = await pay(page);
  await notice(page.request, { id: `evt_renew_${paid.subscription}`, type: 'invoice.paid', data: { object: { id: `in_${paid.subscription}`, subscription: paid.subscription, amount_paid: paid.amount, payment_intent: `pi_renew_${paid.subscription}` } } });
  await page.goto('/members/subscription/?confirm=1'); await expect(page.getByRole('button', { name: 'Pause for 1 month', exact: true })).toBeVisible();
  await submitAndWait(page, page.getByRole('button', { name: 'Pause for 1 month', exact: true }));
  await expect(page.locator('main')).toContainText(/pause/i);
  await page.goto('/members/subscription/?confirm=1'); await submitAndWait(page, page.getByRole('button', { name: 'Confirm cancellation', exact: true }));
  await expect(page.locator('main')).toContainText(/cancel/i);
});
scenario('UC11-S1', 'TC-FR3-06-AS-02', async ({ page }) => {
  await fresh(page); await page.goto('/members/settings/?section=profile'); await page.locator('#lld_display').fill('Synthetic changed');
  await submitAndWait(page, page.getByRole('button', { name: 'Save profile', exact: true })); await page.reload(); await expect(page.locator('#lld_display')).toHaveValue('Synthetic changed');
});
scenario('UC11-S2', 'TC-FR3-60-AS-01', async ({ page }) => {
  await fresh(page); const r=await reading(page,'weight',80);const f=await logFood(page,await custom(page));
  const data=await downloadData(page);const tables=data.tables||data;
  const exportedReading=tables.readings.find(row=>String(row.id)===String(r.id));
  const exportedFood=tables.food_log.find(row=>String(row.id)===String(f.id));
  expect(exportedReading).toBeTruthy();expect(Number(exportedReading.value_1)).toBe(80);
  expect(exportedFood).toBeTruthy();expect(Number(exportedFood.grams)).toBe(Number(f.grams));expect(Number(exportedFood.kcal)).toBe(Number(f.kcal));
});
scenario('UC11-S3', 'TC-FR2-30-AS-01', async ({ page }) => {
  await fresh(page); await page.goto('/members/help/'); await page.locator('#lld_message').fill('Synthetic account question with no health details');
  await submitAndWait(page, page.getByRole('button', { name: /Send/, exact: false })); await expect(page.locator('main')).toContainText(/sent/i);
});
scenario('UC11-S4', 'TC-FR3-56-AS-04', async ({ page }) => {
  await fresh(page); await page.goto('/members/settings/?section=notifications');
  const add=page.locator('.lld-reminders-add');await add.locator('#lld_reminder_type').selectOption('log');await add.locator('input[type="time"]').fill('08:00');
  await submitAndWait(page,add.getByRole('button',{name:'Save',exact:true}));await page.reload();
  await expect(page.locator('.lld-reminder-row input[type="time"]')).toHaveValue('08:00');
  await page.locator('.lld-reminder-row input[type="checkbox"]').first().uncheck();
  await submitAndWait(page,page.locator('.lld-reminders-list').getByRole('button',{name:'Save',exact:true}));
  await page.reload();await expect(page.locator('.lld-reminder-row input[type="checkbox"]').first()).not.toBeChecked();
  expect(Number(rows(await api(page,'reminders'))[0].active)).toBe(0);
});
scenario('UC11-S5', 'TC-FR2-04-AS-02', async ({ page }) => {
  await fresh(page); await page.goto('/members/delete/'); await page.locator('#lld_password').fill('synthetic-wrong-password');
  await submitAndWait(page, page.getByRole('button', { name: 'Delete my account', exact: true }));
  await expect(page.locator('main')).not.toContainText('Your account is closed.'); await deleteAccount(page);
  await page.goto('/members/settings/'); await expect(page).toHaveURL(/\/members\/sign-in\//);
});
scenario('UC11-S6', 'TC-FR3-61-AS-01', async ({ page }) => {
  const member = await fresh(page); await reading(page, 'weight', 80); await logFood(page, await custom(page)); await deleteAccount(page);
  await expect.poll(async () => { const response = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: member.member_id, scope: 'account', email: A } }); expect(response.ok()).toBeTruthy(); return (await response.json()).problems; }).toEqual([]);
});
scenario('UC12-S1', 'TC-FR3-75-AS-01', async ({ page }) => {
  const member = await fresh(page); await asOwner(page); await page.goto(`/members/admin/members/?q=${encodeURIComponent(A)}`);
  await expect(page.getByRole('link', { name: `#${member.member_id}`, exact: true })).toBeVisible();
});
scenario('UC12-S2', 'TC-FR3-80-AS-01', async ({ page }) => {
  const member = await fresh(page, { access_state: 'Free' }); await grant(page, member);
  const result = await page.request.post('/wp-json/lld-members/v1/test/admin', { data: { op: 'member', member: member.member_id } });
  expect(result.ok()).toBeTruthy(); const state = await result.json(); expect(state.premium).toBe(true); expect(state.grants).toBe(1);
});
scenario('UC12-S3', 'TC-FR3-80-AS-03', async ({ page }) => {
  const member = await fresh(page, { access_state: 'Free' }); await grant(page, member);
  const detail = page.locator('details.lld-adm-action').filter({ hasText: /Remove free Premium/ }); await detail.locator('summary').click();
  await detail.locator('textarea, input[name="reason"]').fill('Synthetic grant removal');
  await submitAndWait(page, detail.getByRole('button', { name: 'Remove free Premium', exact: true }));
  const result = await page.request.post('/wp-json/lld-members/v1/test/admin', { data: { op: 'member', member: member.member_id } }); expect((await result.json()).premium).toBe(false);
});
for (const [id, on, assertion] of [['UC12-S4', false, 'TC-FR3-89-AS-01'], ['UC12-S5', true, 'TC-FR3-89-AS-03']]) scenario(id, assertion, async ({ page }) => {
  await asOwner(page);await page.goto('/members/admin/tools/');const toggle=page.locator('#lld-adm-t-healthy-weight');const original=await toggle.isChecked();
  const status=async()=>{const r=await page.request.get('/tools/status.json');expect(r.ok()).toBeTruthy();return (await r.json()).tools['healthy-weight'].status;};
  try {
    await toggle.setChecked(!on);await expect.poll(status).toBe(on?'withdrawn':'active');
    await toggle.setChecked(on);await expect.poll(status).toBe(on?'active':'withdrawn');
  } finally {
    await page.goto('/members/admin/tools/');await page.locator('#lld-adm-t-healthy-weight').setChecked(original);
    await expect.poll(status).toBe(original?'active':'withdrawn');
  }
});
