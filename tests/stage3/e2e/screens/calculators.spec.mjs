// The six Stage 3 calculator pages (P3-57 to P3-5A screens; FR3-49 to FR3-55), live on the CI WordPress. The
// calculators_new switch is at test_accounts in CI (tests/ci/stage2-wp.sh), so the pages exist only for the listed
// test accounts; a visitor or an ordinary Stage 2 member gets "not found" (NFR3-06). Every number is made up.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password } from '../../key-journeys/helpers.mjs';
import { restNonce } from '../../../stage2/e2e/helpers.mjs';

const A = 'p3-calc-a@test.lifelinedaily.invalid'; // Premium
const B = 'p3-calc-b@test.lifelinedaily.invalid'; // Free
const LEFTOVER = /\{[a-zA-Z_ ]+\}|\s\|\s/; // a template placeholder or choice left on the page

async function fresh(page, email, fields = {}) {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  const member = await createMember(page.request, { email, password, first_name: 'Sam', health_consent: true, consents: ['terms'], ...fields });
  await signIn(page, email, password);
  return member;
}

/** Answer a page: numbers and lists by field, choices by their radio. */
async function answer(page, values) {
  for (const [name, value] of Object.entries(values)) {
    const field = page.locator(`.lld-calc-form [name="lld_c[${name}]"]`); // not the save form's hidden copies
    const kind = await field.first().getAttribute('type');
    if (kind === 'radio') await page.locator(`#lld-c-${name}-${String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`).check();
    else if ((await field.first().evaluate((e) => e.tagName)) === 'SELECT') await field.selectOption(String(value));
    else await field.fill(String(value));
  }
}

/** Submit and wait for the new page itself (the address only gains a #fragment, so the load state alone can be stale). */
async function check(page) {
  await Promise.all([page.waitForEvent('load'), page.getByRole('button', { name: /^(Check|Change my answers)$/ }).click()]);
}

async function save(page) {
  await Promise.all([page.waitForEvent('load'), page.getByRole('button', { name: 'Save this result' }).click()]);
}

const heart = { age: 55, sex: 'male', country: 'United Kingdom', smoke: 'yes', sbp: 150, diabetes: 'no', tc: '6.2', heightCm: 178, weightKg: 88, cvd: 'no', preg: 'no', eat: 'no' };

test.describe.serial('calculator pages', () => {
  test('TC-NFR3-06-AS-01 (calculator pages): a visitor and an ordinary member do not see the pages while the switch is at test_accounts', async ({ page }) => {
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    const res = await page.goto('/health-tools/heart-risk-check/');
    expect(res.status()).toBe(404);
    // An ordinary signed-in member (not a listed test account) gets the same.
    const ordinary = `p357-ordinary-${Date.now()}@test.lifelinedaily.invalid`;
    await createMember(page.request, { email: ordinary, password, first_name: 'Robin', health_consent: true, consents: ['terms'] });
    await signIn(page, ordinary, password);
    for (const slug of ['heart-risk-check', 'blood-pressure-check', 'sleep-and-stress-check']) {
      expect((await page.goto(`/health-tools/${slug}/`)).status(), slug).toBe(404);
    }
  });

  test('TC-FR3-49-AS-02 (screen): the heart risk page shows the figure and the change that lowers it most', async ({ page }) => {
    await fresh(page, A, { access_state: 'Premium' });
    const res = await page.goto('/health-tools/heart-risk-check/');
    expect(res.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Heart attack and stroke risk check' })).toBeVisible();
    await answer(page, heart);
    await check(page);
    const result = page.locator('.lld-calc-result');
    // "17 %" is kept on one line with a no-break space, so the checks accept either space.
    await expect(result).toContainText(/Your estimated chance of a heart attack or stroke in the next 10 years is about 17\s%/);
    await expect(result).toContainText(/The change that would lower your risk most: smoking\. Stopping smoking would take it to about 11\s%\./);
    expect(await result.innerText()).not.toMatch(LEFTOVER);
    // Out of range: the "not suitable" wording and no number (TC-FR3-49-AS-03).
    await answer(page, { age: 35 });
    await check(page);
    await expect(page.locator('[data-lld-stop="under40"]')).toContainText("This check isn't suitable under 40.");
    await expect(page.locator('.lld-calc-headline')).toHaveCount(0);
  });

  test('TC-FR3-55-AS-02 (screen): a Premium member saves two heart risk results and sees both with the change', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/health-tools/heart-risk-check/');
    await answer(page, heart);
    await check(page);
    await save(page);
    await expect(page.locator('[data-lld-saved="yes"]')).toBeVisible();
    await answer(page, { ...heart, smoke: 'no' });
    await check(page);
    await save(page);
    const history = page.locator('.lld-calc-history');
    await expect(history).toHaveAttribute('data-lld-history', 'premium');
    await expect(history.locator('li')).toHaveCount(2);
    await expect(history.locator('[data-lld-change]')).toContainText('points lower');
    await expect(history.locator('[data-lld-upgrade]')).toHaveCount(0);
  });

  test('TC-FR3-55-AS-03 (screen): a Free member sees only the latest saved result, with the invitation to upgrade', async ({ page }) => {
    await fresh(page, B, { access_state: 'free' });
    await page.goto('/health-tools/heart-risk-check/');
    for (const smoke of ['yes', 'no']) {
      await answer(page, { ...heart, smoke });
      await check(page);
      await save(page);
    }
    const history = page.locator('.lld-calc-history');
    await expect(history).toHaveAttribute('data-lld-history', 'free');
    await expect(history.locator('li')).toHaveCount(1);
    await expect(history.locator('[data-lld-upgrade]')).toContainText('Upgrade to see your history');
    await expect(history.locator('[data-lld-change]')).toHaveCount(0);
  });

  test('TC-FR3-50-AS-02 (screen): a dangerous blood pressure shows the urgent-care wording first', async ({ page }) => {
    await signIn(page, B, password);
    await page.goto('/health-tools/blood-pressure-check/');
    await answer(page, { mode: 'single', systolic: 185, diastolic: 122, age: 60, pregnant: 'no', medicine: 'no' });
    await check(page);
    const alert = page.locator('.lld-calc-result [role="alert"]').first();
    await expect(alert).toContainText('Your reading is very high.');
    await expect(alert.getByRole('link', { name: 'When to seek urgent care' })).toBeVisible();
    await answer(page, { systolic: 148, diastolic: 92 });
    await check(page);
    await expect(page.locator('.lld-calc-result')).toContainText('High (grade 1)');
  });

  test('TC-FR3-38-AS-04 (screen, D3-50): an impossible pulse is left out and the reading still checked, urgent first', async ({ page }) => {
    await signIn(page, B, password);
    await page.goto('/health-tools/blood-pressure-check/');
    await answer(page, { mode: 'single', systolic: 190, diastolic: 125, pulse: 225, age: 60, pregnant: 'no', medicine: 'no' });
    await check(page);
    const result = page.locator('.lld-calc-result');
    await expect(result.locator('[role="alert"]').first()).toContainText('Your reading is very high.');
    await expect(result).toContainText("We left out the pulse: 225 isn't possible for a resting pulse. Please check it and add it again.");
    await expect(result).not.toContainText("that reading isn't possible");
    const text = await result.innerText();
    expect(text.indexOf('Your reading is very high.')).toBeLessThan(text.indexOf('We left out the pulse'));
  });

  test('TC-FR3-50-AS-03 (screen): the 7-day average uses the member\'s own logged readings', async ({ page }) => {
    // A member who has answered the health questions ("no conditions"): the safety matrix refuses readings for a
    // member whose answers are unknown.
    const member = await fresh(page, B, { access_state: 'free' });
    await page.goto('/members/home/');
    const nonce = await restNonce(page);
    const profile = await page.request.post(`/wp-json/lld-members/v1/members/${member.member_id}/health`, {
      data: { kind: 'profile', sex: 'female', height_cm: 165, weight_kg: 70, activity_level: 'some', conditions_answered: 'none', confirmed: true },
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(profile.ok(), await profile.text()).toBeTruthy();
    const today = new Date();
    for (let d = 0; d < 3; d += 1) {
      const day = new Date(today.getTime() - (d + 1) * 86400000).toISOString().slice(0, 10);
      const res = await page.request.post('/wp-json/lld-members/v1/me/readings', {
        data: { reading_type: 'blood_pressure', taken_at: `${day} 08:00:00`, value_1: 138, value_2: 86, source: 'manual' },
        headers: { 'X-WP-Nonce': nonce },
      });
      expect(res.ok(), await res.text()).toBeTruthy();
    }
    await page.goto('/health-tools/blood-pressure-check/');
    await page.locator('.lld-calc-form [name="lld_c[mode]"][value="7-day"]').check();
    await answer(page, { age: 60, pregnant: 'no', medicine: 'no' });
    await check(page);
    const result = page.locator('.lld-calc-result');
    await expect(result).toContainText('138/86');
    await expect(result).toContainText('3 days');
  });

  test('TC-FR3-51-AS-03 (screen): the metabolic check names the missing values', async ({ page }) => {
    await signIn(page, B, password);
    await page.goto('/health-tools/metabolic-health-check/');
    await answer(page, { age: 50, sex: 'female', pregnantOrBreastfeeding: 'no', waistCm: 85, bpSystolic: 132, bpDiastolic: 76, fastingGlucoseFasting: 'yes', eatingDisorder: 'no' });
    const background = page.locator('.lld-calc-form [name="lld_c[background]"]');
    await background.selectOption({ index: 1 });
    await check(page);
    const result = page.locator('.lld-calc-result');
    await expect(result).toContainText('triglycerides');
    await expect(result).toContainText('fasting glucose');
    expect(await result.innerText()).not.toMatch(LEFTOVER);
  });

  test('TC-FR3-52-AS-02 (screen): a Premium member sees the change in fitness since the last saved check', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/health-tools/fitness-age/');
    const fit = { age: 45, sex: 'male', waistCm: 94, rhr: 70, heartMedicine: 'no', heartCondition: 'no', pregnant: 'no', eatingDisorder: 'no' };
    await answer(page, fit);
    for (const name of ['frequency', 'intensity', 'duration']) await page.locator(`.lld-calc-form [name="lld_c[${name}]"]`).nth(2).check();
    await check(page);
    await expect(page.locator('.lld-calc-result')).toContainText('Your estimated fitness level');
    await save(page);
    await answer(page, { ...fit, rhr: 60 });
    await check(page);
    await expect(page.locator('.lld-calc-result')).toContainText('points since');
  });

  test('TC-FR3-53-AS-02 (screen): heart-rate-lowering medicine shows the talk-test wording instead of zones', async ({ page }) => {
    await signIn(page, B, password);
    await page.goto('/health-tools/heart-rate-zones/');
    await answer(page, { age: 50, heartMedicine: 'yes', heartCondition: 'no', pregnant: 'no' });
    await check(page);
    const result = page.locator('.lld-calc-result');
    await expect(result).toContainText("heart-rate zones don't apply");
    await expect(result.locator('table')).toHaveCount(0);
    await answer(page, { heartMedicine: 'no', rhr: 60 });
    await check(page);
    await expect(result.locator('table')).toContainText('You can talk but not sing');
  });

  test('TC-FR3-54-AS-02 (screen): a high score shows the support wording and the help line; a self-harm Yes shows only Response 1', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/health-tools/sleep-and-stress-check/');
    const high = { q0: 'no', q1: '3', q2: '3', q3: '3', q4: '3', q5: 'yes', q6: 'yes', q7: 'yes', q8: 4 };
    for (const [name, value] of Object.entries(high)) {
      if (name === 'q8') await page.locator('.lld-calc-form [name="lld_c[q8]"]').fill(String(value));
      else await page.locator(`.lld-calc-form [name="lld_c[${name}]"][value="${value}"]`).check();
    }
    await check(page);
    const result = page.locator('.lld-calc-result');
    await expect(result).toContainText('findahelpline.com');
    await expect(page.getByRole('button', { name: 'Save this result' })).toBeVisible();
    // D3-52: the track link sits below the result, never inside it, with no price.
    await expect(result.locator('a[href*="/programmes/sleep-stress/"]')).toHaveCount(0);
    await expect(page.locator('[data-lld-onward="sleep-stress"] a')).toHaveText('The Sleep and Stress track');
    await expect(result).not.toContainText('You can still start the track');
    await page.locator('.lld-calc-form [name="lld_c[q0]"][value="yes"]').check();
    await check(page);
    await expect(result.locator('[role="alert"]')).toBeVisible();
    await expect(result).not.toContainText('Stress and worry');
    await expect(page.getByRole('button', { name: 'Save this result' })).toHaveCount(0);
    await expect(page.locator('.lld-calc-history')).toHaveCount(0);
  });
});
