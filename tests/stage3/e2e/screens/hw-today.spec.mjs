// Healthy Weight on Today, the check-in and the focus blocks (P3-35, package P4; FR3-99, FR3-104, FR3-105, FR3-107),
// live on the CI WordPress with the programme (P2) and its approved content (P3) loaded. The member's clock is frozen
// and moved day by day, so "day 3", "week 9" and "two weeks before Ramadan" are real programme days.
import { test, expect } from '@playwright/test';
import { A, B, ID, at, clock, fresh, profile, submit, weightGoal, answer, pace, weighIn, CONFIDENT } from './hw-helpers.mjs';

/** Join Healthy Weight on day 0 of `start` with the confident answers (or others), at 0.5 kg a week. */
async function join(page, email, start, fields = {}, answers = CONFIDENT) {
  const m = await fresh(page, email, at(start, 0), fields);
  await profile(page, m.member_id);
  await weightGoal(page);
  await answer(page, answers);
  await pace(page, '0.5');
  return m;
}

/**
 * Weeks 1 to 8 as the member lives them: level 1 runs four weeks, then "Carry on to week 5" (Owner Question 161) starts
 * level 2 that day (day 30, after level 1's last day), and its four weeks end on day 57, so day 58 is week 9, when the
 * focus blocks open (FR3-104).
 */
async function toWeek9(page, email, start) {
  await clock(page, email, at(start, 30));
  await page.goto('/members/home/');
  const carry = page.locator('[data-lld-section="hw-carry-on"]').getByRole('button', { name: 'Carry on to week 5' });
  await expect(carry).toBeVisible();
  await submit(page, carry, '/members/home/');
  await expect(page.locator('[data-lld-section="hw-carry-on"]')).toHaveCount(0);
  await clock(page, email, at(start, 58));
}

/** The notes the signed rules can bring forward (R-HW-NOTE-01 to -05 and R-HW-WEEK-01), any week. */
const BROUGHT_FORWARD = ['HW-MN-W4-4', 'HW-MN-W8-1', 'HW-MN-W1-6', 'HW-MN-W2-6', 'HW-MN-W1-4', 'HW-MN-W7-4', 'HW-MN-W6-5', 'HW-MN-W3-3'];

test.describe.serial('P3-35 Today (FR3-99, FR3-105)', () => {
  test('TC-FR3-99-AS-02 With questions unanswered, the Today screen shows at most 2 a day until all are answered or skipped', async ({ page }) => {
    await join(page, A, '2026-09-28', { access_state: 'Premium' });
    await clock(page, A, at('2026-09-28', 1));
    await page.goto('/members/home/');
    const qod = page.locator('[data-lld-section="hw-qod"]');
    await expect(qod).toBeVisible();
    await expect(qod.getByRole('heading', { name: 'Quick question' })).toBeVisible();
    const forms = qod.locator('form');
    expect(await forms.count()).toBeLessThanOrEqual(2);
    // Skipping one still never shows more than two.
    await submit(page, forms.first().getByRole('button', { name: 'Skip' }), '/members/home/');
    expect(await page.locator('[data-lld-section="hw-qod"] form').count()).toBeLessThanOrEqual(2);
    await expect(page.locator('main')).not.toContainText(ID);
  });

  test("TC-FR3-105-AS-01 On each of days 1 to 56 a Premium test member sees one note on the Today screen, with that week's theme", async ({ page }) => {
    test.setTimeout(15 * 60000); // 56 programme days, each one its own Today page
    await join(page, A, '2026-08-03', { access_state: 'Premium' });
    for (let day = 1; day <= 56; day++) {
      await clock(page, A, at('2026-08-03', day - 1));
      await page.goto('/members/home/');
      // Week 4 runs straight into week 5 with one tap (Owner Question 161).
      const carry = page.locator('[data-lld-section="hw-carry-on"]').getByRole('button', { name: 'Carry on to week 5' });
      if (day === 28) { await expect(carry).toBeVisible(); await submit(page, carry, '/members/home/'); }
      const note = page.locator('[data-lld-section="hw-note"]');
      await expect(note, `day ${day}`).toHaveCount(1);
      await expect(note.locator('h2'), `day ${day}`).not.toBeEmpty();
      const key = await note.getAttribute('data-lld-note');
      const week = Number((key.match(/^HW-MN-W(\d)-\d$/) || [])[1]);
      // The default order is that week's notes; a signed rule may bring a named note forward.
      if (!BROUGHT_FORWARD.includes(key)) expect(week, `day ${day}: ${key}`).toBe(Math.ceil(day / 7));
    }
  });

  test('TC-FR3-105-AS-04 A note can be read in full on a 360-pixel-wide screen with no sideways scrolling', async ({ page }) => {
    await join(page, A, '2026-09-28', { access_state: 'Premium' });
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto('/members/home/');
    const note = page.locator('[data-lld-section="hw-note"]');
    await expect(note).toBeVisible();
    const box = await note.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(360);
    expect(await note.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  });
});

test.describe.serial('P3-35 the weekly check-in (FR3-107)', () => {
  test('TC-FR3-107-AS-01 The check-in shows the question, and skipping it still completes the check-in', async ({ page }) => {
    await join(page, A, '2026-09-28', { access_state: 'Premium' });
    await clock(page, A, at('2026-09-28', 7));
    await page.goto('/members/check-in/');
    const way = page.locator('[data-lld-section="hw-way"]');
    await expect(way).toBeVisible();
    await expect(way.locator('legend')).toHaveText('What got in the way?');
    expect((await way.locator('label').allInnerTexts()).map((s) => s.trim())).toEqual(['Time or work', 'Family', 'Cravings', 'Stress', 'Social events', 'Illness', 'Nothing']);
    // Skipped: the check-in is saved all the same.
    await submit(page, page.locator('[data-lld-section="measurements"] button[type="submit"]'), '/members/check-in/');
    await expect(page).toHaveURL(/\/members\/check-in\/\?done=1$/);
    await expect(page.locator('[role="status"]')).toContainText('Check-in saved.');
    // Answered: the signed reply follows.
    await clock(page, A, at('2026-09-28', 14));
    await page.goto('/members/check-in/');
    await page.locator('input[name="lld_hw_way[]"][value="family"]').check();
    await submit(page, page.locator('[data-lld-section="measurements"] button[type="submit"]'), '/members/check-in/');
    await expect(page.locator('[data-lld-section="hw-way-replies"]')).toContainText("Family life can make it harder. Next week's note has ideas for getting the people you live with on side.");
  });
});

test.describe.serial('P3-35 focus blocks (FR3-104)', () => {
  test('TC-FR3-104-AS-01 A member finishing week 8 short of their goal is offered a focus block with a one-line reason, and can choose another', async ({ page }) => {
    await join(page, A, '2026-08-03', { access_state: 'Premium' }, { ...CONFIDENT, stress: '8' });
    await toWeek9(page, A, '2026-08-03');
    await page.goto('/members/goal/?hw=blocks');
    const step = page.locator('[data-lld-goal-step="blocks"]');
    await expect(step.getByRole('heading', { name: 'Choose your next four weeks' })).toBeVisible();
    const suggested = await step.getAttribute('data-lld-suggested');
    expect(suggested).not.toBe('');
    await expect(step.locator(`[data-lld-block="${suggested}"] [data-lld-because]`)).toHaveText(/^Suggested for you, because .+\.$/);
    const other = step.locator(`input[name="lld_hw_block"]:not([value="${suggested}"])`).first();
    await other.check();
    await submit(page, step.getByRole('button', { name: 'Start this block' }), '/members/goal/');
    await expect(page).toHaveURL(/\/members\/home\/$/);
  });

  test("TC-FR3-104-AS-03 'Festivals and fasting' is offered before Ramadan only to members who said they keep that fast", async ({ page }) => {
    // Week 9 on 20 January 2027, nearly three weeks before Ramadan 2027 (8 February).
    for (const [email, keeps] of [[A, true], [B, false]]) {
      await join(page, email, '2026-11-23', { access_state: 'Premium' });
      if (keeps) {
        // The fasts question is one of the quick questions on Today (HW-QW-14).
        for (let day = 1; day <= 14; day++) {
          await clock(page, email, at('2026-11-23', day));
          await page.goto('/members/home/');
          const ramadan = page.locator('[data-lld-section="hw-qod"] label', { hasText: 'Ramadan' }).first();
          if (await ramadan.count()) {
            await ramadan.locator('input').check();
            await submit(page, ramadan.locator('xpath=ancestor::form').getByRole('button', { name: 'Next' }), '/members/home/');
            break;
          }
          const form = page.locator('[data-lld-section="hw-qod"] form').first();
          if (await form.count()) await submit(page, form.getByRole('button', { name: 'Skip' }), '/members/home/');
        }
      }
      await toWeek9(page, email, '2026-11-23');
      await page.goto('/members/goal/?hw=blocks');
      const suggested = await page.locator('[data-lld-goal-step="blocks"]').getAttribute('data-lld-suggested');
      if (keeps) expect(suggested, email).toBe('festivals-and-fasting');
      else expect(suggested, email).not.toBe('festivals-and-fasting');
    }
  });

  test('TC-FR3-104-AS-05 A member who reaches their goal during a block is offered Keep It Off', async ({ page }) => {
    await join(page, A, '2026-07-06', { access_state: 'Premium' });
    await toWeek9(page, A, '2026-07-06');
    await page.goto('/members/goal/?hw=blocks');
    await submit(page, page.getByRole('button', { name: 'Start this block' }), '/members/goal/');
    // The goal weight held for a week inside the block; Today sees the goal reached (FR3-15).
    for (let day = 59; day <= 65; day++) await weighIn(page, at('2026-07-06', day, 7), 60);
    await clock(page, A, at('2026-07-06', 65));
    await page.goto('/members/home/');
    await page.goto('/members/goal/?hw=blocks');
    const keep = page.locator('[data-lld-section="hw-keep-it-off"]');
    await expect(keep.getByRole('button', { name: 'Start Keep It Off' })).toBeVisible();
  });
});
