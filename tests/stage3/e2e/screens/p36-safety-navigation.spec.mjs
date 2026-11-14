// P3-36 X-2: expected A7/C1 behaviour. No pending markers: builders run these contracts before landing.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password, fixture, startTrack } from '../../key-journeys/helpers.mjs';
import { mailCode, restNonce, submitAndWait, leanAdmin } from '../../../stage2/e2e/helpers.mjs';

// Listed Stage 3 test account; workers are serial and reset it before each independent case.
const EMAIL = 'p3-api-a@test.lifelinedaily.invalid';
async function member(page, fields = {}) {
  const reset = await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: EMAIL } });
  expect(reset.ok(), await reset.text()).toBeTruthy();
  const created = await createMember(page.request, {
    email: EMAIL, password, first_name: 'Alex', consents: ['terms'], health_consent: false, ...fields,
  });
  await loginMember(page, fields.role === 'owner_admin');
  return created;
}

async function loginMember(page, owner = false) {
  if (owner) await leanAdmin(page);
  await signIn(page, EMAIL, password);
  if (owner) {
    await page.locator('#lld_code').fill(await mailCode(EMAIL));
    await submitAndWait(page, page.getByRole('button', { name: 'Continue', exact: true }));
    await expect(page.locator('#lld_code')).toHaveCount(0);
  }
}

async function openMenu(page) {
  const chip = page.locator('.lld-chip:visible');
  await expect(chip).toHaveCount(1);
  await chip.click();
  const menu = page.locator('#lld-desktop-user-menu');
  await expect(menu).toBeVisible();
  await expect(menu.getByRole('menuitem')).toHaveText(['Profile', 'My subscription', 'My data', 'Help', 'Sign out']);
  return menu;
}

async function loggedOut(page) {
  await expect(page).toHaveURL(/\/members\/sign-in\//);
  await page.goto('/members/settings/');
  await expect(page).toHaveURL(/\/members\/sign-in\//);
  await expect(page.locator('#lld_email')).toBeVisible();
}

async function readyMember(page, owner = false) {
  const m = await member(page, { health_consent: true, consents: ['terms', 'health'],
    access_state: 'Premium', ...(owner ? { role: 'owner_admin' } : {}) });
  await page.goto('/members/health/');
  const headers = { 'X-WP-Nonce': await restNonce(page) };
  const profile = await page.request.post(`/wp-json/lld-members/v1/members/${m.member_id}/health`, {
    headers, data: { kind: 'profile', sex: 'female', height_cm: 175, weight_kg: 90,
      activity_level: 'some', conditions_answered: 'none', confirmed: true },
  });
  expect(profile.ok(), await profile.text()).toBeTruthy();
  const goal = await page.request.post('/wp-json/lld-members/v1/me/goals', { headers,
    data: { role: 'main', goal_type: 'activity', measure: 'activity_minutes', unit: 'minutes',
      start_value: 0, target_value: 150, status: 'active' } });
  expect(goal.ok(), await goal.text()).toBeTruthy();
  return m;
}

// One sign-in and one sign-out for each page, so the member pages and the Admin pages are two tests.
test('TC-FR3-07-AS-04 every member page', async ({ page }) => {
  test.setTimeout(900000);
  // Resource downloads, POST-only mutations and public authentication screens have no member layout.
  // Food/readings are covered by X-4/X-5 once those routes exist.
  const memberPaths = ['home', 'settings', 'health', 'goal', 'progress', 'check-in', 'tracks', 'meals',
    'diary', 'library', 'subscription', 'data', 'help', 'more', 'delete', 'withdraw',
    'suggestions', 'screening', 'plan', 'workout', 'challenge', 'badges', 'group', 'track', 'review'];
  await page.setViewportSize({ width: 1280, height: 900 });
  const made = await fixture(page.request, 'days');
  await readyMember(page);
  await startTrack(page, made.track);
  for (const path of [...memberPaths.map((p) => `/members/${p}/`),
    ...['security', 'notifications', 'privacy', 'membership'].map((p) => `/members/settings/?section=${p}`),
    `/members/library/${made.items[0].id}/`]) {
    await test.step(`Member ${path}`, async () => {
      await loginMember(page);
      const response = await page.goto(path);
      expect(response.status()).toBe(200);
      expect(new URL(page.url()).pathname + new URL(page.url()).search).toBe(path);
      await expect(page.locator('main h1')).toBeVisible();
      await (await openMenu(page)).getByRole('menuitem', { name: 'Sign out', exact: true }).click();
      await loggedOut(page);
    });
  }
});

test('TC-FR3-07-AS-04 every My account Admin page', async ({ page }) => {
  test.setTimeout(900000);
  const adminPaths = ['', 'members/', 'tools/', 'features/', 'activity/', 'helpers/', 'payments/',
    'report/', 'group/', 'emails/', 'ai/'];
  await page.setViewportSize({ width: 1280, height: 900 });
  const target = await createMember(page.request, { email: 'p36-menu-target@test.lifelinedaily.invalid', password, first_name: 'Target', consents: ['terms'] });
  await readyMember(page, true);
  const mailPage = await page.request.post('/wp-json/lld-members/v1/test/admin', { data: { op: 'mailpage' } });
  expect(mailPage.ok(), await mailPage.text()).toBeTruthy();
  for (const path of [...adminPaths.map((p) => `/members/admin/${p}`),
    `/members/admin/members/?member=${target.member_id}`]) {
    await test.step(`Owner ${path}`, async () => {
      await loginMember(page, true);
      const response = await page.goto(path);
      expect(response.status()).toBe(200);
      expect(new URL(page.url()).pathname + new URL(page.url()).search).toBe(path);
      await expect(page.locator('main h1')).toBeVisible();
      const protectedHeading = await page.locator('main h1').innerText();
      await (await openMenu(page)).getByRole('menuitem', { name: 'Sign out', exact: true }).click();
      await loggedOut(page);
      // Signed out, an Admin address goes to the WordPress sign-in (class-admin-front.php), never to the page.
      await page.goto(path);
      const landed = new URL(page.url());
      expect(landed.pathname).toMatch(/\/wp-login\.php$/);
      expect(landed.searchParams.get('redirect_to') || '').toContain('/members/admin/');
      await expect(page.locator('#user_login')).toBeVisible();
      await expect(page.getByRole('heading', { name: protectedHeading, exact: true })).toHaveCount(0);
    });
  }
});

test('TC-FR3-07-AS-05', async ({ page }) => {
  test.slow(); // two accounts, one with the Owner's code step
  await page.setViewportSize({ width: 360, height: 800 });
  for (const owner of [false, true]) {
    await readyMember(page, owner);
    await page.goto(owner ? '/members/admin/' : '/members/settings/');
    const header = page.locator('header').first();
    const toggle = header.getByRole('button', { name: /menu/i }).first();
    await expect(toggle).toBeVisible();
    await toggle.click();
    const out = header.getByRole('button', { name: 'Sign out', exact: true });
    await expect(out).toBeVisible();
    const box = await out.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(360);
    await out.click();
    await loggedOut(page);
  }
});

test('TC-FR3-07-AS-06', async ({ page, browser }) => {
  await member(page);
  // Independent browser storage for a second real sign-in; no shared cookie copying.
  const otherContext = await browser.newContext({ ...test.info().project.use });
  try {
    const other = await otherContext.newPage();
    await signIn(other, EMAIL, password);
    await other.goto('/members/settings/?section=security');
    await expect(other.locator('.lld-account-content')).toBeVisible();
    await page.goto('/members/settings/?section=security');
    const security = page.locator('.lld-account-content');
    await expect(security.getByRole('button', { name: 'Sign out everywhere', exact: true })).toBeVisible();
    await security.getByRole('button', { name: 'Sign out of this device', exact: true }).click();
    await loggedOut(page);
    await other.reload();
    await expect(other).toHaveURL(/\/members\/settings\/\?section=security/);
    await expect(other.locator('.lld-account-content')).toBeVisible();
  } finally {
    await otherContext.close();
  }
});

async function healthFields(page) {
  await page.locator('#lld_sex').selectOption('female');
  await page.locator('#lld_height').fill('175');
  await page.locator('#lld_weight').fill('90');
  await page.locator('#lld_activity').selectOption('some');
  await page.locator('#lld_confirm').check();
}

// The "Saved" line of a saved form (it also holds an Undo button, so its text is not "Saved" alone).
const saved = (page) => page.locator('main [role="status"]').filter({ hasText: 'Saved' });

test('TC-FR3-69-AS-06', async ({ page }) => {
  test.slow(); // two fresh members, each saving twice
  for (const answer of ['None of these', 'Prefer not to answer']) {
    await test.step(answer, async () => {
      await member(page, { health_consent: true, consents: ['terms', 'health'] });
      await page.goto('/members/health/');
      await healthFields(page);
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(saved(page)).toHaveCount(0);
      await expect(page.locator('main').getByRole('alert')).toContainText('Answer the Conditions question to save these details.');
      // Reload clears unsaved post values: a rejected submission must not persist the measurements.
      await page.goto('/members/health/');
      // With the account hub on, the stored height is shown in its box (P3-36 D4, #256), not as a "Stored height" line.
      await expect(page.locator('#lld_height')).not.toHaveValue(/^175(\.0+)?$/);
      await healthFields(page);
      await page.getByRole('checkbox', { name: answer, exact: true }).check();
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(saved(page)).toBeVisible();
      await page.goto('/members/health/');
      await expect(page.getByRole('checkbox', { name: answer, exact: true })).toBeChecked();
      await expect(page.locator('#lld_height')).toHaveValue(/^175(\.0+)?$/);
    });
  }
});

test('TC-FR3-69-AS-05', async ({ page }) => {
  await member(page, { health_consent: true, consents: ['terms', 'health'] });
  // The member's clock is frozen on the reading's date: a Free member's 14-day history limit could otherwise hide a
  // wrongly stored reading from the list checked at the end.
  const frozen = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: EMAIL, utc: '2026-09-28 09:00:00' } });
  expect(frozen.ok(), await frozen.text()).toBeTruthy();
  await page.goto('/members/health/');
  const headers = { 'X-WP-Nonce': await restNonce(page) };
  const response = await page.request.post('/wp-json/lld-members/v1/me/readings', {
    headers, data: { taken_at: '2026-09-28 09:00:00', reading_type: 'weight', value_1: 90, unit_shown: 'kg', source: 'manual' },
  });
  expect(response.status()).toBe(403);
  const refusal = await response.json();
  expect(refusal.message).toBe('Answer the Conditions question in your health profile first.');
  expect(refusal.question).toBe('conditions');
  expect(refusal.link).toMatch(/\/members\/health\/#lld-conditions$/);
  // Setting a goal: a member sets one on the goal screen (#252 refuses there; the form itself is never shown without
  // the answer, so the post carries no form nonce). The page names the question and links to it; nothing is stored.
  const goalRefusal = await page.request.post('/members/goal/?type=lose_weight', { maxRedirects: 0,
    form: { lld_action: 'save', type: 'lose_weight', lld_target: '84' } });
  expect([200, 403]).toContain(goalRefusal.status());
  const refusedGoal = await goalRefusal.text();
  expect(refusedGoal).toContain('Answer the Conditions question in your health profile first.');
  expect(refusedGoal).toMatch(/href="[^"]*\/members\/health\/\?return=goal#lld-conditions"/);
  // Opening goal setting lands on the missing question itself.
  await page.goto('/members/goal/?type=lose_weight');
  await expect(page).toHaveURL(/\/members\/health\/\?return=goal#lld-conditions$/);
  await expect(page.getByRole('group', { name: 'Conditions', exact: true })).toBeVisible();
  // Answering exposes the lists, letting the test check no refused reading/goal was stored.
  await healthFields(page);
  await page.getByRole('checkbox', { name: 'None of these', exact: true }).check();
  await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
  await page.goto('/members/health/');
  const nonce = await restNonce(page);
  for (const type of ['readings', 'goals']) {
    const result = await page.request.get(`/wp-json/lld-members/v1/me/${type}`, { headers: { 'X-WP-Nonce': nonce } });
    expect(result.ok(), await result.text()).toBeTruthy();
    const body = await result.json();
    expect(body.rows).toEqual([]);
  }
});

test('TC-FR3-09-AS-08', async ({ page }) => {
  await member(page, { health_consent: true, consents: ['terms', 'health'] });
  await page.goto('/members/goal/?type=lose_weight');
  await expect(page).toHaveURL(/\/members\/health\//);
  await expect(page.getByRole('group', { name: 'Conditions', exact: true })).toBeVisible();
  await healthFields(page);
  await page.getByRole('checkbox', { name: 'None of these', exact: true }).check();
  await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
  await expect(page).toHaveURL(/\/members\/goal\//);
  await page.goto('/members/goal/?type=lose_weight');
  await page.locator('#lld_target').fill('84');
  await submitAndWait(page, page.getByRole('button', { name: 'Save my goal', exact: true }));
  // The signed Healthy Weight order (P3-35; FR3-98, FR3-100): the quick questions (skipped) and the pace come before the plan.
  await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=(questions|pace)$/);
  if (/&hw=questions$/.test(page.url())) {
    await submitAndWait(page, page.locator('[data-lld-goal-step="questions"] form').getByRole('button', { name: 'Next' }));
  }
  const pace = page.locator('[data-lld-goal-step="pace"]');
  await expect(pace).toBeVisible();
  await pace.locator('input[name="lld_hw_pace"][value="0.5"]').check();
  await submitAndWait(page, pace.getByRole('button', { name: 'Next' }));
  await expect(page.locator('[data-lld-goal-step="plan"]')).toBeVisible();
  await page.goto('/members/goal/');
  await expect(page.locator('main')).toContainText('84');
});
