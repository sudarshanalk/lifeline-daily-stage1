// Healthy Weight joining screens (P3-35, package P4; FR3-97, FR3-98, FR3-100, FR3-102, FR3-109, FR3-110), live on the CI
// WordPress with the programme (P2) and its approved content (P3) loaded: where you are now, the eight questions, the
// pace and the plan, with the free support cards. The two members are the listed test accounts; the clock is frozen.
import { test, expect } from '@playwright/test';
import { A, B, ID, at, fresh, profile, submit, weightGoal, answer, pace, CONFIDENT } from './hw-helpers.mjs';

const DAY0 = '2026-09-28';

test.describe.serial('P3-35 where you are now (FR3-97)', () => {
  test('TC-FR3-97-AS-04 A member who says they are pregnant sees no BMI band and is shown the pregnancy safety wording', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id, { conditions_answered: 'answered', conditions: ['pregnancy'] });
    await page.goto('/members/goal/?hw=where');
    const stop = page.locator('[data-lld-hw-stop="pregnancy"]');
    await expect(stop).toBeVisible();
    await expect(stop).toContainText("This check isn't suitable right now");
    await expect(stop).toContainText('during pregnancy or while breastfeeding');
    await expect(page.locator('[data-lld-band], [data-lld-bmi]')).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText(/kg\/m|Obesity|Overweight|Healthy weight/);
    await expect(page.locator('main')).not.toContainText(ID);
  });

  test('TC-FR3-97-AS-05 Skipping waist shows the BMI result alone, and the waist result appears once waist is added', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id);
    await page.goto('/members/goal/?hw=where');
    const where = page.locator('[data-lld-section="hw-where"]');
    await expect(where).toHaveAttribute('data-lld-bmi', '30.1');
    await expect(where.locator('[data-lld-result="waist"]')).toHaveAttribute('data-lld-waist', 'none');
    await expect(where.locator('[data-lld-whtr]')).toHaveCount(0);
    await expect(where).toContainText('Add your waist above for a fuller picture');
    await page.locator('#lld_hw_waist').fill('98');
    await submit(page, page.getByRole('button', { name: 'See my result' }), '/members/goal/');
    await expect(where.locator('[data-lld-result="waist"]')).toHaveAttribute('data-lld-waist', 'shown');
    await expect(where.locator('[data-lld-whtr]')).toHaveText('0.60');
    await expect(where).toContainText('A healthy weight range for your height');
  });
});

test.describe.serial('P3-35 the eight questions (FR3-98)', () => {
  test('TC-FR3-98-AS-01 A new test member with health consent sees the eight questions after choosing a weight goal, each with its reason, and can finish in about 1 minute', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id);
    const started = Date.now();
    await page.goto('/members/goal/');
    await page.locator('input[name="type"][value="lose_weight"]').check();
    await page.getByRole('button', { name: 'Next' }).click();
    // Where you are now comes first, then the goal on the same step.
    const details = page.locator('[data-lld-goal-step="details"]');
    await expect(details.locator('[data-lld-section="hw-where"]')).toBeVisible();
    await page.locator('#lld_target').fill('60');
    await submit(page, page.getByRole('button', { name: 'Save my goal' }), '/members/goal/');
    await expect(page).toHaveURL(/hw=questions$/);
    const step = page.locator('[data-lld-goal-step="questions"]');
    await expect(step.getByRole('heading', { name: 'Eight quick questions' })).toBeVisible();
    for (const key of ['reason', 'barrier', 'time', 'work', 'confidence', 'sleep', 'stress', 'alcohol_freq']) {
      const q = step.locator(`[data-lld-question="${key}"]`);
      await expect(q, key).toBeVisible();
      await expect(q.locator('.lld-hint[id$="-why"]'), key).not.toBeEmpty();
    }
    await answer(page, CONFIDENT);
    expect(Date.now() - started).toBeLessThan(60000);
    await expect(page.locator('main')).not.toContainText(ID);
  });

  test('TC-FR3-98-AS-02 The sleep question offers exactly under 6 hours, 6 to 7, 7 to 9 and over 9, the stress question 1 to 10, and the alcohol question never, less than once a week, 1 to 2 days a week, 3 to 4 days and most days', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id);
    await weightGoal(page);
    const labels = (key) => page.locator(`[data-lld-question="${key}"] > fieldset label`).allInnerTexts();
    expect((await labels('sleep')).map((s) => s.trim())).toEqual(['Under 6 hours', '6 to 7 hours', '7 to 9 hours', 'Over 9 hours']);
    expect((await labels('stress')).map((s) => s.trim())).toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
    expect((await labels('alcohol_freq')).map((s) => s.trim())).toEqual(['Never', 'Less than once a week', '1 to 2 days a week', '3 to 4 days a week', 'Most days']);
  });

  test("TC-FR3-98-AS-07 Answering 'never' to alcohol skips the drinks-a-day tap; any other answer shows it, with pictures of standard drinks and the choices 1 to 2, 3 to 4 and 5 or more", async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id);
    await weightGoal(page);
    const amount = page.locator('[data-lld-question="alcohol_amount"]');
    await expect(amount).toBeHidden();
    await page.locator('input[name="lld_hw[alcohol_freq]"][value="never"]').check({ force: true });
    await expect(amount).toBeHidden();
    await page.locator('input[name="lld_hw[alcohol_freq]"][value="1_2_days"]').check({ force: true });
    await expect(amount).toBeVisible();
    await expect(amount.locator('[data-lld-drink-pictures="3"] svg')).toHaveCount(3);
    expect((await amount.locator('fieldset label').allInnerTexts()).map((s) => s.trim())).toEqual(['1 to 2', '3 to 4', '5 or more']);
    await page.locator('input[name="lld_hw[alcohol_freq]"][value="never"]').check({ force: true });
    await expect(amount).toBeHidden();
  });
});

test.describe.serial('P3-35 pace and plan (FR3-100, FR3-102)', () => {
  test('TC-FR3-100-AS-02 A test member with time, support and confidence 8 out of 10 can choose 0.75 kg a week, but never more than their safe limit', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0), { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    await answer(page, CONFIDENT);
    const step = page.locator('[data-lld-section="hw-pace"]');
    await expect(step.getByRole('heading')).toHaveText('You could go a little faster: 0.75 kg a week');
    // The paces offered (the last choice, "By a date instead", is a date, not a pace).
    const offered = await step.locator('input[name="lld_hw_pace"]:not([value="date"])').evaluateAll((els) => els.map((e) => Number(e.value)));
    expect(offered).toContain(0.75);
    expect(Math.max(...offered)).toBeLessThanOrEqual(0.8); // the safe limit: 1 % of 80 kg a week
    // A pace above the limit, posted by hand, is refused.
    await step.locator('input[name="lld_hw_pace"]').first().evaluate((e) => { e.value = '1'; e.checked = true; });
    await submit(page, step.getByRole('button', { name: 'Next' }), '/members/goal/');
    await expect(page.locator('[role="alert"]')).toContainText("That's faster than the safe rate");
    await pace(page, '0.75');
  });

  test("TC-FR3-102-AS-04 The plan shows the 'Your body uses about {tdee} kcal a day at your activity level' line with the engine's energy figure for the chosen level", async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0), { access_state: 'Premium' });
    await profile(page, m.member_id);
    await weightGoal(page);
    await answer(page, CONFIDENT);
    await pace(page, '0.5');
    const plan = page.locator('[data-lld-section="hw-plan"]');
    await expect(plan.getByRole('heading', { name: "Here's your plan" })).toBeVisible();
    await expect(plan.locator('[data-lld-why-number]')).toHaveText(/^Your body uses about \d{1,2},\d{3} kcal a day at your activity level\./);
    await expect(plan.locator('[data-lld-kcal]')).toContainText(/About \d{1,2},\d{3} kcal a day/);
  });

  test('TC-FR3-102-AS-08 A member with an eating-disorder flag sees no calorie number on any Healthy Weight or Today screen', async ({ page }) => {
    const m = await fresh(page, B, at(DAY0, 0));
    await profile(page, m.member_id, { conditions_answered: 'answered', conditions: ['eating-disorder'] });
    for (const path of ['/members/goal/?hw=where', '/members/goal/', '/members/home/', '/members/progress/']) {
      await page.goto(path);
      await expect(page.locator('main'), path).not.toContainText(/kcal|calorie/i);
      await expect(page.locator('[data-lld-kcal], [data-lld-kcal-today]'), path).toHaveCount(0);
    }
  });
});

test.describe.serial('P3-35 support cards (FR3-109, FR3-110)', () => {
  test('TC-FR3-109-AS-04 A member with stress 9, or sleep under 6 hours, sees the approved support wording and an offer of the Sleep and Stress track as the extra goal', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id);
    await weightGoal(page);
    await answer(page, { ...CONFIDENT, stress: '9' });
    await pace(page, '0.25');
    const card = page.locator('[data-lld-card="R-HW-SAFE-03"]');
    await expect(card).toContainText('Poor sleep and high stress are hard on anyone. If this goes on for more than two weeks, talk to your doctor.');
    await expect(card.getByRole('link', { name: 'Sleep and stress check' })).toBeVisible();
    await expect(card.getByRole('link', { name: 'Add Sleep and Stress' })).toBeVisible();
    await card.getByRole('button', { name: 'Not now' }).click();
    await expect(card).toBeHidden();
  });

  test('TC-FR3-109-AS-05 Accepting that offer starts the Sleep and Stress safety questions (FR2-38) before the track', async ({ page }) => {
    const m = await fresh(page, A, at(DAY0, 0));
    await profile(page, m.member_id);
    await weightGoal(page);
    await answer(page, { ...CONFIDENT, sleep: 'under_6' });
    await pace(page, '0.25');
    await page.locator('[data-lld-card="R-HW-SAFE-03"]').getByRole('link', { name: 'Add Sleep and Stress' }).click();
    await expect(page).toHaveURL(/\/members\/screening\/\?track=sleep-stress$/);
    await expect(page.getByRole('heading', { name: 'A quick safety check' })).toBeVisible();
  });

  test('TC-FR3-110-AS-06 A member who drinks most days, 3 to 4 drinks a time (about 21 a week), sees the support card with the doctor wording on both Free and Premium, and no weight-loss message beside it', async ({ page }) => {
    for (const [email, state] of [[B, 'Free'], [A, 'Premium']]) {
      const m = await fresh(page, email, at(DAY0, 0), { access_state: state });
      await profile(page, m.member_id);
      await weightGoal(page);
      await answer(page, { ...CONFIDENT, alcohol_freq: 'most_days', alcohol_amount: '3_4' });
      await pace(page, '0.5');
      const card = page.locator('[data-lld-card="R-HW-SAFE-05"]');
      await expect(card, state).toContainText('If you drink heavily every day, talk to your doctor before you cut down, because stopping suddenly can be dangerous.');
      const help = card.getByRole('link', { name: 'Where to get help' });
      await expect(help).toHaveAttribute('href', 'https://findahelpline.com');
      await expect(help).toHaveAttribute('target', '_blank');
      await expect(card).not.toContainText(/\bkg\b|kcal|calorie|lose|loss/i);
      await expect(page.locator('[data-lld-section="hw-plan"]'), state).not.toContainText(/kcal|lose|loss/i);
    }
  });
});
