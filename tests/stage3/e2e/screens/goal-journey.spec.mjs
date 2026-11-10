// Stage 3 screen tests for P3-31 to P3-33 (FR3-09 to FR3-16, FR3-18), live on the CI WordPress: the goal journey,
// Today, the weekly check-in, the progress page and the dashboard. The goals, today, progress, insights and
// health_logging switches are at test_accounts in CI (tests/ci/stage2-wp.sh) and the two members below are the
// listed test accounts, so ordinary Stage 2 members keep the Stage 2 screens. Every number is made up; the
// member's clock is frozen (LLD_TEST_CLOCK) so "day 3", "two weeks behind" and "14 days of logs" are real days.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password, fixture, startTrack } from '../../key-journeys/helpers.mjs';
import { restNonce } from '../../../stage2/e2e/helpers.mjs';

const A = 'p3-api-a@test.lifelinedaily.invalid'; // Premium in most journeys
const B = 'p3-api-b@test.lifelinedaily.invalid'; // Free in most journeys
const ID = /\b(?:TC-)?N?FR\d-\d\d/;

/** Day n of the journey as a UTC datetime; day 0 is Monday 28 September 2026 (Europe/London: the same date), in the past so
 * readings dated by day are never "in the future" for the members' interface. */
const at = (day, hour = 9) => { const d = new Date(Date.UTC(2026, 8, 28, hour)); d.setUTCDate(d.getUTCDate() + day); return d.toISOString().slice(0, 19).replace('T', ' '); };
const dateOf = (day) => at(day).slice(0, 10);
/** The date as the screens print it: "Monday 28 September 2026". */
const nice = (day) => new Date(`${dateOf(day)}T00:00:00Z`).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).replace(',', '');

async function clock(page, email, day) {
  const res = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: at(day) } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

/** A clean member: the account (and every Stage 3 row of it) is erased first, then made again and signed in. */
async function fresh(page, email, fields = {}) {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  const member = await createMember(page.request, { email, password, first_name: 'Alex', health_consent: true, consents: ['terms'], ...fields });
  await signIn(page, email, password);
  await clock(page, email, 0);
  return member;
}

/** The Stage 2 health profile through its own route: measurements and the answered conditions (the matrix reads them). */
async function profile(page, memberId, fields = {}) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const data = { kind: 'profile', sex: 'female', height_cm: 175, weight_kg: 90, activity_level: 'some', conditions_answered: 'none', confirmed: true, ...fields };
  const res = await page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, { data, headers: { 'X-WP-Nonce': nonce } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function api(page) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
  const call = (method, path, data) => page.request.fetch(`/wp-json/lld-members/v1/me/${path}`, { method, headers, data: data ? JSON.stringify(data) : undefined });
  return { get: (p) => call('GET', p), post: (p, d) => call('POST', p, d) };
}

async function rows(page, type) {
  const res = await (await api(page)).get(type);
  expect(res.ok(), await res.text()).toBeTruthy();
  const body = await res.json();
  return Array.isArray(body) ? body : body.rows || body.items || [];
}

/** A weigh-in on a given day through the members' interface (health_logging is at test_accounts in CI). */
async function weighIn(page, day, kg) {
  const res = await (await api(page)).post('readings', { reading_type: 'weight', taken_at: at(day, 7), value_1: kg, unit_shown: 'kg', source: 'manual' });
  expect(res.ok(), await res.text()).toBeTruthy();
}

/**
 * Press "Save my goal" and wait for the server's answer (the plan, or the form again with a refusal). Without the wait,
 * the next page.goto can cancel the form's POST in webkit before it reaches the server, so the goal is never saved.
 */
async function saveGoal(page) {
  const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/members/goal/');
  await page.getByRole('button', { name: 'Save my goal' }).click();
  await answered;
  await page.waitForLoadState('load');
}

/** Press a button of a joining step and wait for the server's answer, as saveGoal() does. */
async function step(page, button) {
  const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/members/goal/');
  await button.click();
  await answered;
  await page.waitForLoadState('load');
}

/**
 * The Healthy Weight joining steps after the goal is saved (P3-35; FR3-98, FR3-100; owner decisions of 6 Nov 2026):
 * the eight quick questions, all skipped here (every one can be skipped, FR3-98 AS-03; with no approved question loaded
 * the screen goes straight on to the pace), then the pace or a date instead (FR3-100). Lands on the plan, or stays on
 * the pace step when pace is null or a refusal is expected.
 */
async function joining(page, pace = '0.5', date = '', refused = '') {
  await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=(questions|pace)$/);
  if (/&hw=questions$/.test(page.url())) {
    await step(page, page.locator('[data-lld-goal-step="questions"] form').getByRole('button', { name: 'Next' }));
    await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=pace$/);
  }
  const paceStep = page.locator('[data-lld-goal-step="pace"]');
  await expect(paceStep).toBeVisible();
  if (pace === null && !date) return;
  if (date) { await paceStep.locator('input[name="lld_hw_pace"][value="date"]').check(); await page.locator('#lld_date').fill(date); } else { await paceStep.locator(`input[name="lld_hw_pace"][value="${pace}"]`).check(); }
  await step(page, paceStep.getByRole('button', { name: 'Next' }));
  if (refused) {
    await expect(page.locator('[data-lld-refused]')).toHaveAttribute('data-lld-refused', refused);
    return;
  }
  await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+$/);
}

/**
 * Set a weight-loss goal in the signed Healthy Weight order (FR3-97, FR3-98, FR3-100): where you are now with the goal
 * (current, target, waist), then the quick questions, then the pace (kg a week) or a date instead, as the goal step
 * offered them before. Lands on the plan page; with `refused` it expects that refusal instead (of the goal step, or of
 * the date on the pace step); with pace null it stays on the pace step.
 */
async function weightGoal(page, { current = 90, target = 84, pace = '0.5', date = '', waist = '', refused = '' } = {}) {
  await page.goto('/members/goal/?type=lose_weight');
  await expect(page.locator('[data-lld-goal-step="details"]')).toBeVisible();
  await page.locator('#lld_current').fill(String(current));
  await page.locator('#lld_target').fill(String(target));
  if (waist) await page.locator('#lld_waist').fill(String(waist));
  await saveGoal(page);
  if (refused && !date) {
    await expect(page.locator('[data-lld-refused]')).toHaveAttribute('data-lld-refused', refused);
    return;
  }
  await joining(page, pace, date, refused);
}

/** The weekly check-in with this week's numbers; lands back on the check-in with the result. */
async function checkIn(page, { weight = '', waist = '', sys = '', dia = '' } = {}) {
  await page.goto('/members/check-in/');
  await expect(page.locator('[data-lld-section="measurements"]')).toBeVisible();
  if (weight) await page.locator('#lld_weight').fill(String(weight));
  if (waist) await page.locator('#lld_waist').fill(String(waist));
  if (sys) { await page.locator('#lld_sys').fill(String(sys)); await page.locator('#lld_dia').fill(String(dia)); }
  await page.locator('[data-lld-section="measurements"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/members\/check-in\/\?done=1/);
}

test.describe.serial('P3-31 goal setting, starting measurements and the plan', () => {
  test('TC-FR3-09-AS-01 A new test member with health consent cannot reach the dashboard without a main goal, and finishes in under a minute', async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/goal\/$/);
    const started = Date.now();
    await expect(page.locator('[data-lld-goal-step="type"]')).toBeVisible();
    await page.locator('input[name="type"][value="lose_weight"]').check();
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.locator('[data-lld-goal-step="details"]')).toBeVisible();
    await page.locator('#lld_target').fill('84');
    await page.getByRole('button', { name: 'Save my goal' }).click();
    // The signed order: the quick questions and the pace come before the plan (FR3-98, FR3-100), all inside the minute.
    await joining(page);
    await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+$/);
    await expect(page.locator('[data-lld-goal-step="plan"]')).toBeVisible();
    expect(Date.now() - started).toBeLessThan(60000);
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/home\/$/);
    await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText(ID);
  });

  test('TC-FR3-09-AS-02 A member who declines health consent reaches the dashboard on the general wellbeing path, with no goal or health data stored', async ({ page }) => {
    await fresh(page, B, { health_consent: false, consents: ['terms'] });
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/home\/$/);
    await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible();
    await expect(page.locator('[data-lld-section="goal-progress"]')).toContainText('articles, calculators and tracks');
    await page.goto('/members/goal/');
    await expect(page.locator('[data-lld-goal-step]')).toHaveCount(0);
    await expect(page.locator('main')).toContainText(/health/i);
    // Nothing health-related is stored: the interface answers "consent needed" for goals and readings alike.
    const a = await api(page);
    expect((await a.get('goals')).status()).toBe(403);
    expect((await a.get('readings')).status()).toBe(403);
    // A quick-log tile posts nothing without consent.
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="quick-log"]')).toContainText('health consent');
  });

  test('TC-FR3-09-AS-03 A Premium member who withdraws health consent keeps Premium access to non-personal features', async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    await page.goto('/members/withdraw/');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/home\/$/); // no goal gate: the general wellbeing path
    await expect(page.locator('[data-lld-section="membership"]')).toContainText('Your membership is Premium.');
    expect((await (await api(page)).get('goals')).status()).toBe(403);
  });

  test("TC-FR3-09-AS-04 'Help me choose' suggests a goal based on the member's answers", async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id, { weight_kg: 96 }); // BMI 31
    await page.goto('/members/goal/');
    await page.getByRole('button', { name: 'Help me choose' }).click();
    const card = page.locator('[data-lld-suggestion]');
    await expect(card).toHaveAttribute('data-lld-suggestion', 'lose_weight');
    await expect(card).toContainText('From your height and weight');
    await card.getByRole('link', { name: 'Use this goal' }).click();
    await expect(page.locator('[data-lld-goal-step="details"]')).toHaveAttribute('data-lld-goal-type', 'lose_weight');
    // With high blood pressure in the profile, the suggestion follows it.
    await profile(page, m.member_id, { conditions_answered: 'answered', conditions: ['high-blood-pressure'] });
    await page.goto('/members/goal/');
    await page.getByRole('button', { name: 'Help me choose' }).click();
    await expect(page.locator('[data-lld-suggestion]')).toHaveAttribute('data-lld-suggestion', 'lower_blood_pressure');
  });

  test('TC-FR3-09-AS-05 A weight goal below a healthy BMI, or faster than the safe rate, is refused with a gentle message and a safe suggestion', async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id);
    await weightGoal(page, { current: 70, target: 50, refused: 'bmi_floor' });
    const refused = page.locator('[data-lld-refused]');
    await expect(refused).toHaveAttribute('data-lld-refused', 'bmi_floor');
    await expect(refused).toContainText('A goal of 50 kg would put you below the healthy range for your height (BMI 16.3). The lowest weight in the healthy range for you is about 56.7 kg. Would you like to use that?');
    await expect(page.locator('#lld_target')).toHaveValue('56.7');
    expect((await rows(page, 'goals')).length).toBe(0); // the refused goal is not saved
    // The pace and the date are chosen on their own step now (FR3-100). It never offers a pace above the cap (1.0 is
    // missing at 90 kg); a date too soon is refused with the signed P3-56 wording and the safe date filled in, and the
    // goal's pace and date are not changed.
    await weightGoal(page, { current: 90, target: 84, pace: null });
    const paceStep = page.locator('[data-lld-goal-step="pace"]');
    await expect(paceStep.locator('input[name="lld_hw_pace"][value="1"]')).toHaveCount(0);
    // rows() reads the members' interface from Today, so the pace step is opened again after it.
    const paceUrl = page.url();
    const before = (await rows(page, 'goals')).find((g) => g.role === 'main');
    await page.goto(paceUrl);
    await paceStep.locator('input[name="lld_hw_pace"][value="date"]').check();
    await page.locator('#lld_date').fill(dateOf(28)); // 1.5 kg a week
    await step(page, paceStep.getByRole('button', { name: 'Next' }));
    await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=pace$/);
    await expect(page.locator('[data-lld-refused]')).toHaveAttribute('data-lld-refused', 'rate_cap');
    await expect(page.locator('[data-lld-refused]')).toContainText(`That's faster than the safe rate of about 0.9 kg a week. At a safe rate you'd reach 84 kg by about ${nice(49)}.`);
    await expect(page.locator('#lld_date')).toHaveValue(dateOf(49));
    let after = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect(after.chosen_rate ?? null).toBeNull();
    expect(after.target_date).toBe(before.target_date);
    // A faster pace sent anyway is refused the same way.
    await page.goto(paceUrl);
    await page.locator('[data-lld-goal-step="pace"] input[name="lld_hw_pace"]').first().evaluate((el) => { el.value = '1'; el.checked = true; });
    await step(page, page.locator('[data-lld-goal-step="pace"]').getByRole('button', { name: 'Next' }));
    await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=pace$/);
    await expect(page.locator('[data-lld-refused]')).toHaveAttribute('data-lld-refused', 'rate_cap');
    await expect(page.locator('[data-lld-goal-step="pace"] [role="alert"]')).toContainText(`That's faster than the safe rate of about 0.9 kg a week. At a safe rate you'd reach 84 kg by about ${nice(49)}.`);
    after = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect(after.chosen_rate ?? null).toBeNull();
    expect(after.target_date).toBe(before.target_date);
  });

  test('TC-FR3-09-AS-06 A member can add one extra goal but not a third', async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id);
    await weightGoal(page);
    await page.goto('/members/goal/?role=extra');
    await page.locator('input[name="type"][value="activity"]').check();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Save my goal' }).click();
    await expect(page.getByRole('heading', { name: 'Your extra goal' })).toBeVisible();
    await page.goto('/members/goal/?role=extra');
    await expect(page.locator('main')).toContainText('You already have a main goal and an extra goal.');
    await expect(page.locator('[data-lld-goal-step]')).toHaveCount(0);
    const goals = (await rows(page, 'goals')).filter((g) => g.status === 'active');
    expect(goals.map((g) => g.role).sort()).toEqual(['extra', 'main']);
  });

  test('TC-FR3-09-AS-07 A member who answers a pregnancy, eating-disorder or medicine safety question sees the safety wording before setting a goal', async ({ page }) => {
    const m = await fresh(page, B);
    await profile(page, m.member_id, { conditions_answered: 'answered', conditions: ['pregnancy'] });
    await page.goto('/members/goal/');
    const safety = page.locator('[data-lld-safety]');
    await expect(safety).toContainText('pregnancy', { ignoreCase: true });
    await expect(safety).toContainText('midwife');
    // The wording comes before the choices, and the weight-loss goal is not offered.
    const order = await page.evaluate(() => { const s = document.querySelector('[data-lld-safety]'); const f = document.querySelector('[data-lld-goal-step="type"] form'); return s.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_FOLLOWING; });
    expect(order).toBeTruthy();
    await expect(page.locator('input[name="type"][value="lose_weight"]')).toHaveCount(0);
    await expect(page.locator('input[name="type"][value="activity"]')).toBeVisible();
    // Eating disorder: the approved screen wording, no weight goals.
    await profile(page, m.member_id, { conditions_answered: 'answered', conditions: ['eating-disorder'] });
    await page.goto('/members/goal/');
    await expect(page.locator('[data-lld-safety]')).toContainText("Weight goals aren't offered, based on your health profile.");
    await expect(page.locator('input[name="type"][value="lose_weight"], input[name="type"][value="keep_weight"]')).toHaveCount(0);
    // Diabetes medicine: the check-with-your-doctor wording.
    await profile(page, m.member_id, { conditions_answered: 'answered', conditions: ['type-2-diabetes'] });
    await page.goto('/members/goal/');
    await expect(page.locator('[data-lld-safety]')).toContainText('Check with your doctor');
  });

  test('TC-FR3-10-AS-01 Set a weight-loss goal: the member is asked for starting weight and waist, and these are saved as the baseline', async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id);
    await page.goto('/members/goal/?type=lose_weight');
    await expect(page.locator('#lld_current')).toBeVisible();
    await expect(page.locator('#lld_waist')).toBeVisible();
    await weightGoal(page, { current: 90, target: 84, waist: 100 });
    const readings = await rows(page, 'readings');
    expect(readings.find((r) => r.reading_type === 'weight')?.value_1).toBe(90);
    expect(readings.find((r) => r.reading_type === 'waist')?.value_1).toBe(100);
    const goal = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect(goal.start_value).toBe(90);
    expect(goal.target_value).toBe(84);
  });

  test('TC-FR3-10-AS-02 Set a blood pressure goal: the member is asked for a starting reading', async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id);
    await page.goto('/members/goal/?type=lower_blood_pressure');
    await expect(page.locator('#lld_current')).toBeVisible();
    await expect(page.locator('#lld_current_2')).toBeVisible();
    await expect(page.locator('main')).toContainText('top number');
    await page.locator('#lld_current').fill('142');
    await page.locator('#lld_current_2').fill('88');
    await page.getByRole('button', { name: 'Save my goal' }).click();
    await expect(page.locator('[data-lld-goal-step="plan"]')).toBeVisible();
    const bp = (await rows(page, 'readings')).find((r) => r.reading_type === 'blood_pressure');
    expect([bp.value_1, bp.value_2]).toEqual([142, 88]);
    const goal = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect(goal.target_value).toBe(135);
    expect(goal.target_value_2).toBe(85);
    expect(goal.start_value_2).toBe(88);
    expect(goal.target_source).toBe('guideline_default');
  });

  test('TC-FR3-10-AS-03 A blood pressure goal holds both targets and is reached only when both 7-day averages are on target', async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id);
    // The doctor's target needs both numbers.
    await page.goto('/members/goal/?type=lower_blood_pressure');
    await page.locator('input[name="lld_source"][value="doctor"]').check();
    await page.locator('#lld_target').fill('130');
    await page.getByRole('button', { name: 'Save my goal' }).click();
    await expect(page.locator('[data-lld-goal-step="details"] [role="alert"]')).toContainText("Enter both numbers of your doctor's target.");
    await page.locator('input[name="lld_source"][value="doctor"]').check();
    await page.locator('#lld_target').fill('130');
    await page.locator('#lld_target_2').fill('80');
    await page.getByRole('button', { name: 'Save my goal' }).click();
    const plan = page.locator('[data-lld-goal-step="plan"]');
    await expect(plan).toBeVisible();
    await expect(plan.locator('[data-lld-section="goal"]')).toContainText('Blood pressure below 130/80');
    const goal = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect([goal.target_value, goal.target_value_2, goal.target_source]).toEqual([130, 80, 'doctor']);
    // A week with the top number on target and the bottom number above it: not reached.
    const me = await api(page);
    for (let d = 1; d <= 7; d++) {
      const res = await me.post('readings', { reading_type: 'blood_pressure', taken_at: at(d, 8), value_1: 126, value_2: 84, source: 'manual' });
      expect(res.ok(), await res.text()).toBeTruthy();
    }
    await clock(page, A, 7);
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="goal-reached"]')).toHaveCount(0);
    expect((await rows(page, 'goals')).find((g) => g.id === goal.id).status).toBe('active');
    // A week with both numbers at or below the doctor's 130/80: reached.
    for (let d = 8; d <= 14; d++) {
      const res = await me.post('readings', { reading_type: 'blood_pressure', taken_at: at(d, 8), value_1: 126, value_2: 78, source: 'manual' });
      expect(res.ok(), await res.text()).toBeTruthy();
    }
    await clock(page, A, 14);
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="goal-reached"]')).toBeVisible();
    expect((await rows(page, 'goals')).find((g) => g.id === goal.id).status).toBe('reached');
  });

  test('TC-FR3-10-AS-04 The member can skip a measurement and add it later; progress is shown once it exists', async ({ page }) => {
    const m = await fresh(page, A);
    await profile(page, m.member_id);
    await weightGoal(page); // no waist
    await page.goto('/members/progress/');
    await expect(page.locator('[data-lld-trend="waist"]')).toHaveAttribute('data-lld-points', '0');
    await checkIn(page, { waist: 98 });
    await page.goto('/members/progress/');
    await expect(page.locator('[data-lld-trend="waist"]')).toHaveAttribute('data-lld-points', '1');
    await expect(page.locator('[data-lld-trend="waist"] td[data-lld-value]').first()).toHaveText('98 cm');
  });

  test("TC-FR3-11-AS-01 A Premium member with the goal 'lose 6 kg in 16 weeks' gets a plan with the Healthy Weight track, a calorie target, a meal plan and a weekly weigh-in", async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page, { current: 90, target: 84, date: dateOf(112) });
    const plan = page.locator('[data-lld-goal-step="plan"]');
    await expect(plan).toHaveAttribute('data-lld-premium', 'yes');
    await expect(plan.locator('[data-lld-section="track"]')).toHaveAttribute('data-lld-track', 'healthy-weight');
    await expect(plan.locator('[data-lld-section="track"] h2')).toHaveText('Healthy Weight');
    // Female, 40, 175 cm, 90 kg, some activity: RMR 1632.75 × 1.375 = 2245 kcal. 6 kg in 16 weeks asks for 0.375 kg a week;
    // the signed calculator offers the nearest rate at or below it (0.25, deficit 275): 1970 kcal.
    await expect(plan.locator('[data-lld-kcal]')).toHaveAttribute('data-lld-kcal', '1970');
    await expect(plan.locator('[data-lld-section="targets"]')).toContainText("This week's meal plan");
    await expect(plan.locator('[data-lld-section="schedule"]')).toContainText('Weigh in once a week, on Mondays');
    await expect(plan.locator('[data-lld-section="goal"]')).toContainText(`Lose 6 kg to reach 84 kg by ${nice(112)}`);
  });

  test('TC-FR3-11-AS-02 A Premium member with a blood pressure goal is offered the Heart Health track and a blood pressure logging schedule', async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await page.goto('/members/goal/?type=lower_blood_pressure');
    await page.getByRole('button', { name: 'Save my goal' }).click();
    const plan = page.locator('[data-lld-goal-step="plan"]');
    await expect(plan.locator('[data-lld-section="track"]')).toHaveAttribute('data-lld-track-suggested', 'heart-health');
    await expect(plan.locator('[data-lld-section="track"] h2')).toHaveText('Heart Health');
    await expect(plan.locator('[data-lld-section="schedule"]')).toContainText('Blood pressure three times a week');
  });

  test('TC-FR3-11-AS-03 A Free member sees the suggested track and their goal progress, with an invitation to upgrade for the full plan', async ({ page }) => {
    const m = await fresh(page, B);
    await profile(page, m.member_id);
    await weightGoal(page);
    const plan = page.locator('[data-lld-goal-step="plan"]');
    await expect(plan).toHaveAttribute('data-lld-premium', 'no');
    await expect(plan.locator('[data-lld-section="track"] h2')).toHaveText('Healthy Weight');
    await expect(plan.locator('[data-lld-kcal]')).toHaveCount(0);
    await expect(plan.locator('[data-lld-section="targets"]')).toContainText('Premium');
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-goal-progress] [data-lld-progress]')).toHaveAttribute('data-lld-pct', '0');
  });
});

test.describe.serial('P3-32 Today, goal progress, check-in and goal reached', () => {
  test('TC-FR3-12-AS-01 Sign in as a test member on plan day 3: the Today screen shows one Next step card, with Done and Skip', async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    await clock(page, A, 2);
    await page.goto('/members/home/');
    const card = page.locator('[data-lld-section="next-step"]');
    await expect(card).toHaveCount(1);
    await expect(card).toHaveAttribute('data-lld-step-status', 'shown');
    await expect(card.getByRole('button', { name: 'Done', exact: true })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Skip', exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText(ID);
  });

  test('TC-FR3-12-AS-02 Press Done: the card changes to the next activity and the weekly ring goes up by one day', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/members/home/');
    const before = Number(await page.locator('[data-lld-section="ring"]').getAttribute('data-lld-ring-done'));
    await page.locator('[data-lld-section="next-step"]').getByRole('button', { name: 'Done', exact: true }).click();
    await expect(page).toHaveURL(/saved=done/);
    await expect(page.locator('[data-lld-section="next-step"]')).toHaveAttribute('data-lld-step-status', 'done');
    await expect(page.locator('[data-lld-section="next-activity"]')).toBeVisible();
    expect(Number(await page.locator('[data-lld-section="ring"]').getAttribute('data-lld-ring-done'))).toBe(before + 1);
  });

  test('TC-FR3-12-AS-04 Leave one day without activity: the weekly ring shows the lower count and long-term progress is unchanged', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/members/home/');
    const ringBefore = Number(await page.locator('[data-lld-section="ring"]').getAttribute('data-lld-ring-done'));
    const pctBefore = await page.locator('[data-lld-goal-progress] [data-lld-progress]').getAttribute('data-lld-pct');
    await clock(page, A, 3); // a day passes with nothing logged
    await page.goto('/members/home/');
    const ring = Number(await page.locator('[data-lld-section="ring"]').getAttribute('data-lld-ring-done'));
    expect(ring).toBe(ringBefore); // the missed day adds nothing; the count is lower than the days elapsed
    expect(ring).toBeLessThan(4);
    await expect(page.locator('[data-lld-goal-progress] [data-lld-progress]')).toHaveAttribute('data-lld-pct', pctBefore);
  });

  test('TC-FR3-12-AS-03 For a test member with a blood pressure goal and no reading in 7 days, the Next step card suggests a blood pressure check', async ({ page }) => {
    const m = await fresh(page, B);
    await profile(page, m.member_id);
    await page.goto('/members/goal/?type=lower_blood_pressure');
    await saveGoal(page); // no starting reading
    await expect(page.locator('[data-lld-goal-step="plan"]')).toBeVisible();
    await clock(page, B, 8);
    await page.goto('/members/home/');
    const card = page.locator('[data-lld-section="next-step"]');
    await expect(card).toHaveAttribute('data-lld-step-rule', 'R-STEP-01');
    await expect(card).toContainText('Take your blood pressure today');
  });

  test("TC-FR3-13-AS-01 A test member who started at 90 kg with a goal of 84 kg and now weighs 86.8 kg sees '3.2 of 6 kg' and 53%", async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    await clock(page, A, 7);
    await checkIn(page, { weight: 86.8 });
    await page.goto('/members/home/');
    const stat = page.locator('[data-lld-goal-progress] [data-lld-progress]');
    await expect(stat).toHaveAttribute('data-lld-progress', '3.2/6');
    await expect(stat).toHaveAttribute('data-lld-pct', '53');
    await expect(stat).toContainText('3.2 of 6 kg, 53 %');
  });

  test("TC-FR3-13-AS-02 A member whose trend will reach the goal by the date sees 'On track'; one whose trend will not sees 'Behind'", async ({ page }) => {
    // A: 0.5 kg a week towards 84 by day 84, losing 0.5 a week → on track.
    await signIn(page, A, password);
    await weighIn(page, 0, 90);
    await weighIn(page, 7, 89.5);
    await clock(page, A, 14);
    await checkIn(page, { weight: 89 });
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-goal-progress]')).toHaveAttribute('data-lld-trend', 'on_track');
    await expect(page.locator('[data-lld-goal-progress]')).toContainText('On track');
    // B: 0.75 kg a week promised (8 weeks), barely moving → behind.
    const b = await fresh(page, B);
    await profile(page, b.member_id);
    await weightGoal(page, { pace: '0.75' });
    await weighIn(page, 3, 90);
    await weighIn(page, 7, 89.95);
    await clock(page, B, 14);
    await checkIn(page, { weight: 89.9 });
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-goal-progress]')).toHaveAttribute('data-lld-trend', 'behind');
    await expect(page.locator('[data-lld-goal-progress]')).toContainText('Behind');
  });

  test('TC-FR3-13-AS-03 Free and Premium test members both see goal progress', async ({ page }) => {
    await signIn(page, B, password); // Free
    await page.goto('/members/home/');
    await expect(page.locator('.lld-stage3-today')).toHaveAttribute('data-lld-premium', 'no');
    await expect(page.locator('[data-lld-goal-progress] [data-lld-progress]')).toBeVisible();
    await signIn(page, A, password); // Premium
    await page.goto('/members/home/');
    await expect(page.locator('.lld-stage3-today')).toHaveAttribute('data-lld-premium', 'yes');
    await expect(page.locator('[data-lld-goal-progress] [data-lld-progress]')).toBeVisible();
  });

  test('TC-FR3-14-AS-03 A Free test member sees the check-in result with an invitation to upgrade for adjustments', async ({ page }) => {
    await signIn(page, B, password); // behind for two check-ins, Free
    await page.goto('/members/check-in/');
    await expect(page.locator('[data-lld-section="goal-progress"]')).toContainText('Behind');
    await expect(page.locator('[data-lld-section="goal-progress"]')).toContainText('Premium offers an adjusted plan');
    await expect(page.locator('[data-lld-section="adjustment"] button[name="lld_choice"][value="later_date"]')).toHaveCount(0);
  });

  test('TC-FR3-14-AS-01 A Premium test member behind on a weight goal for 2 weeks is offered an adjusted plan with a later date or changed targets', async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page, { pace: '0.75' }); // 8 weeks: day 56
    await weighIn(page, 3, 90);
    await clock(page, A, 7);
    await checkIn(page, { weight: 89.95 });
    await weighIn(page, 10, 89.9);
    await clock(page, A, 14);
    await checkIn(page, { weight: 89.85 });
    // The plateau offer (R-ADJ-02) may sit beside it; the behind-two-weeks offer is the one under test.
    const offer = page.locator('[data-lld-section="adjustment"][data-lld-rule="R-ADJ-01"]');
    await expect(offer).toBeVisible();
    await expect(offer).toContainText('Would you like to move your date');
    await expect(offer.getByRole('button', { name: 'Move my date' })).toBeVisible();
    await expect(offer.getByRole('button', { name: 'Keep my plan' })).toBeVisible();
    const before = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect(before.target_date).toBe(dateOf(56));
    await page.goto('/members/check-in/');
    await page.locator('[data-lld-section="adjustment"][data-lld-rule="R-ADJ-01"]').getByRole('button', { name: 'Move my date' }).click();
    await expect(page).toHaveURL(/adjusted=changed/);
    const after = (await rows(page, 'goals')).find((g) => g.role === 'main');
    expect(after.target_date > before.target_date).toBe(true);
    const plans = (await rows(page, 'plans')).sort((x, y) => x.version - y.version);
    expect(plans.at(-1).version).toBe(2);
    expect(plans.at(-1).created_by).toBe('member');
  });

  test('TC-FR3-14-AS-02 An adjusted plan never sets a calorie target below the safe minimum or a weight loss faster than the safe rate', async ({ page }) => {
    await signIn(page, A, password);
    const plans = (await rows(page, 'plans')).sort((x, y) => x.version - y.version);
    const latest = plans.at(-1);
    expect(latest.calorie_target).toBeGreaterThanOrEqual(1200); // the female floor (D3-02)
    const schedule = typeof latest.log_schedule === 'string' ? JSON.parse(latest.log_schedule) : latest.log_schedule;
    expect(schedule.rate_kg_week).toBeLessThanOrEqual(0.9); // the cap at 90 kg (D3-07)
    expect(schedule.rate_kg_week).toBeLessThanOrEqual(0.75); // never above the member's own choice
  });

  test("TC-FR3-15-AS-01 A test member who reaches their goal weight sees a 'Goal reached' screen", async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    for (let d = 20; d <= 26; d++) await weighIn(page, d, 84);
    await clock(page, A, 26);
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="goal-reached"]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Goal reached' })).toBeVisible();
  });

  test("TC-FR3-15-AS-02 The screen offers 'Keep it off' and 'Set a new goal', and each starts the right next step", async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/members/home/');
    await expect(page.getByRole('button', { name: 'Keep it off' })).toBeVisible();
    await page.getByRole('button', { name: 'Set a new goal' }).click();
    await expect(page).toHaveURL(/\/members\/goal\/$/);
    await expect(page.locator('[data-lld-goal-step="type"]')).toBeVisible();
    // The other way: a reached goal on B, then Keep it off → a goal to stay steady.
    const b = await fresh(page, B);
    await profile(page, b.member_id);
    await weightGoal(page);
    for (let d = 20; d <= 26; d++) await weighIn(page, d, 84);
    await clock(page, B, 26);
    await page.goto('/members/home/');
    await page.getByRole('button', { name: 'Keep it off' }).click();
    await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+$/);
    await expect(page.locator('[data-lld-section="goal"]')).toContainText('Keep my weight steady at about 84 kg');
    const goals = await rows(page, 'goals');
    const kept = goals.find((g) => g.status === 'active' && g.role === 'main');
    expect(kept, JSON.stringify(goals.map((g) => [g.id, g.role, g.goal_type, g.status]))).toBeDefined();
    expect(kept.goal_type).toBe('keep_weight');
    expect(goals.some((g) => g.status === 'reached')).toBe(true);
  });
});

test.describe.serial('P3-33 progress page and dashboard', () => {
  test('TC-FR3-16-AS-01 A test member with 14 days of logs sees weekly bars for the last 4 weeks and their current streak', async ({ page }) => {
    const m = await fresh(page, A, { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    const a = await api(page);
    for (let d = 17; d <= 30; d++) {
      const res = await a.post('readings', { reading_type: 'water', taken_at: at(d, 8), value_1: 250, unit_shown: 'ml', source: 'quick_tile' });
      expect(res.ok(), await res.text()).toBeTruthy();
    }
    await clock(page, A, 30);
    await page.goto('/members/progress/');
    await expect(page.locator('[data-lld-section="streak"]')).toHaveAttribute('data-lld-streak', '14');
    await expect(page.locator('[data-lld-section="weekly-bars"] .lld-bar')).toHaveCount(4);
    await expect(page.locator('[data-lld-section="weekly-bars"] table tbody tr')).toHaveCount(4);
    await expect(page.locator('main')).not.toContainText(ID);
  });

  test("TC-FR3-16-AS-02 'Show as table' shows the same numbers as the chart", async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/members/progress/');
    const table = page.locator('[data-lld-table="weekly-bars"]');
    await expect(table).toBeHidden();
    await page.getByRole('button', { name: 'Show as table' }).first().click();
    await expect(table).toBeVisible();
    const cells = await table.locator('td[data-lld-days-value]').allTextContents();
    const heights = await page.locator('[data-lld-section="weekly-bars"] .lld-bar > span').evaluateAll((els) => els.map((el) => el.style.height));
    expect(cells.map((c) => Math.round(Number(c.split(' ')[0]) / 7 * 100) + '%')).toEqual(heights);
    // Days 17 to 30, weeks from the plan's Monday start: days 14 to 20 hold 4, 21 to 27 all 7, this week (28 to 30) 3.
    expect(cells).toEqual(['0 of 7', '4 of 7', '7 of 7', '3 of 7']);
  });

  test("TC-FR3-16-AS-03 The weight, waist and blood pressure trends match the member's logged values", async ({ page }) => {
    await signIn(page, A, password);
    await weighIn(page, 29, 88.4);
    await checkIn(page, { waist: 97.5, sys: 128, dia: 82 });
    await page.goto('/members/progress/');
    await expect(page.locator('[data-lld-trend="weight"] td[data-lld-value]').first()).toHaveText('88.4 kg');
    await expect(page.locator('[data-lld-trend="waist"] td[data-lld-value]').first()).toHaveText('97.5 cm');
    await expect(page.locator('[data-lld-trend="blood_pressure"] td[data-lld-value]').first()).toHaveText('128/82');
    await expect(page.locator('[data-lld-trend="activity"]')).toBeVisible(); // activity sits alongside
  });

  test('TC-FR3-16-AS-04 No badge name or text mentions kilograms or pounds lost', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/members/progress/');
    const badges = await page.locator('[data-lld-section="badges"]').innerText();
    expect(badges).not.toMatch(/\b(kg|kilograms?|kilos?|lbs?|pounds?)\b/i);
    expect(badges).toContain('never the amount of weight lost');
  });

  test('TC-FR3-18-AS-01 A test member with food, weight and blood pressure logs sees goal progress, today\'s totals and the latest readings on the dashboard', async ({ page }) => {
    await signIn(page, A, password);
    const a = await api(page);
    const food = await a.post('custom-foods', { name: 'Sentinel oat bar', portion_g: 40, kcal: 160, protein_g: 5 });
    expect(food.ok(), await food.text()).toBeTruthy();
    const foodId = (await food.json()).id;
    const log = await a.post('food-log', { entry_date: dateOf(30), meal: 'breakfast', food_id: foodId, food_kind: 'custom', grams: 40, source: 'search' });
    expect(log.ok(), await log.text()).toBeTruthy();
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-goal-progress] [data-lld-progress]')).toBeVisible();
    await expect(page.locator('[data-lld-section="totals"] [data-lld-meals]')).toHaveAttribute('data-lld-meals', '1');
    await expect(page.locator('[data-lld-section="totals"] [data-lld-kcal-today]')).toHaveAttribute('data-lld-kcal-today', '160');
    await expect(page.locator('[data-lld-reading="weight"]')).toContainText('88.4 kg');
    await expect(page.locator('[data-lld-reading="blood_pressure"]')).toContainText('128/82');
  });

  test('TC-FR3-18-AS-02 The dashboard shows the current track and week, matching the programme page', async ({ page }) => {
    await signIn(page, A, password);
    const made = await fixture(page.request, 'days');
    await startTrack(page, made.track);
    await page.goto('/members/home/');
    const position = (await page.locator('[data-lld-track-position]').innerText()).split('·').pop().trim();
    expect(position).toMatch(/^Week \d, day \d$/);
    await page.goto('/members/track/');
    await expect(page.locator('main')).toContainText(position);
  });

  test('TC-FR3-18-AS-03 A Free test member sees the last 14 days only, with an invitation to upgrade for full history and insights', async ({ page }) => {
    const b = await fresh(page, B);
    await profile(page, b.member_id);
    await weightGoal(page);
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="trends"]')).toHaveAttribute('data-lld-days', '14');
    await expect(page.locator('[data-lld-section="trends"]')).toContainText('Premium shows your full history and your insights.');
    await page.goto('/members/progress/');
    await expect(page.locator('.lld-stage3-progress')).toHaveAttribute('data-lld-days', '14');
    await signIn(page, A, password); // Premium
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="trends"]')).toHaveAttribute('data-lld-days', '28');
  });
});
