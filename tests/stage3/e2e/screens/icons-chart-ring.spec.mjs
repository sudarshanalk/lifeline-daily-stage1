// P3-36 D6, D7 and D9, live on the CI WordPress (the Stage 3 browser job loads lld-tools, so member pages use the v2
// shell). D6: the More cards, Quick log tiles and track weeks carry the header's topic icons, hidden from screen readers.
// D7: each weekly bar's printed count equals the table's value. D9: after Done on a day not yet counted, the ring's
// data-lld-ring-done rises by one and says so; a day already counted stays counted once; no animation runs with
// reduced motion. The member is a listed test account with health consent and no Conditions answer, so Today opens
// without goal setting and its own clock is real time.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password, startTrack } from '../../key-journeys/helpers.mjs';

const B = 'p3-api-b@test.lifelinedaily.invalid';

async function fresh(page) {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: B } });
  await createMember(page.request, { email: B, password, first_name: 'Robin', health_consent: true, consents: ['terms'], access_state: 'Premium' });
  await signIn(page, B, password);
}

/** Day n of the journey on the member's own clock; day 0 is Monday 28 September 2026 (as goal-journey.spec.mjs). */
async function clock(page, day) {
  const d = new Date(Date.UTC(2026, 8, 28, 9));
  d.setUTCDate(d.getUTCDate() + day);
  const res = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: B, utc: d.toISOString().slice(0, 19).replace('T', ' ') } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

/** Every icon in the matched area is aria-hidden, and none adds an image or a name to the accessibility tree. */
async function iconsHidden(page, scope) {
  const icons = page.locator(`${scope} .lld-member-icon`);
  const count = await icons.count();
  for (let i = 0; i < count; i++) {
    await expect(icons.nth(i)).toHaveAttribute('aria-hidden', 'true');
    await expect(icons.nth(i).locator('svg')).toHaveAttribute('focusable', 'false');
  }
  await expect(page.locator(scope).getByRole('img')).toHaveCount(0);
  return count;
}

test.describe.serial('P3-36 D6 D7 D9 icons, chart numbers and week ring', () => {
  test('TC-NFR3-12-AS-01 D6 icons on More, Quick log and the track weeks are decoration only', async ({ page }) => {
    await fresh(page);
    await page.goto('/members/more/');
    expect(await iconsHidden(page, '.lld-more-list')).toBeGreaterThanOrEqual(10);
    // Card names are unchanged by the icon.
    await expect(page.getByRole('link', { name: /^Workouts/ })).toBeVisible();
    await page.goto('/members/home/');
    expect(await iconsHidden(page, '[data-lld-section="quick-log"]')).toBe(3);
    await expect(page.locator('[data-lld-tile="water"] button')).toHaveAccessibleName(/^Water/);
    await startTrack(page, 'fitness');
    await page.goto('/members/track/');
    expect(await iconsHidden(page, '.lld-weeks')).toBe(4);
    await expect(page.locator('.lld-week h3').first()).toHaveText(/^Week 1/);
  });

  test("TC-FR3-16-AS-02 D7 each weekly bar's count equals the table's value, with the 7-day guide", async ({ page }) => {
    await fresh(page);
    // Real counts: a full week (7 days logged) and 4 days of the next, through Today's water tile on the member's own
    // test clock. Day 0 is Monday 28 September 2026, as in goal-journey.spec.mjs.
    for (const day of [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]) {
      await clock(page, day);
      await page.goto('/members/home/');
      await page.locator('[data-lld-tile="water"] button').click();
      await expect(page).toHaveURL(/saved=water/);
    }
    await page.goto('/members/progress/');
    const printed = await page.locator('[data-lld-section="weekly-bars"] .lld-bar-value').allTextContents();
    const table = await page.locator('[data-lld-table="weekly-bars"] td[data-lld-days-value]').allTextContents();
    expect(printed.length).toBe(4);
    expect(printed).toEqual(table);
    expect(table).toContain('7 of 7');
    expect(table).toContain('4 of 7');
    // Bars stay proportional (7 days is the full bar area) and the guide key reads as approved.
    const heights = await page.locator('[data-lld-section="weekly-bars"] .lld-bar > span').evaluateAll((els) => els.map((el) => el.style.height));
    expect(heights).toEqual(table.map((c) => `${Math.round((Number(c.split(' ')[0]) / 7) * 100)}%`));
    await expect(page.locator('.lld-bar-guide-key')).toHaveText('7-day guide');
    // The guide is the top of the bar area: the 7-of-7 bar reaches it, the 4-of-7 bar stays below it, and every
    // printed count ends above it, so the line never crosses a label.
    const geometry = await page.locator('[data-lld-section="weekly-bars"] .lld-bar').evaluateAll((bars) => bars.map((bar) => ({
      days: Number(bar.dataset.lldBarDays),
      valueBottom: bar.querySelector('.lld-bar-value').getBoundingClientRect().bottom,
      barTop: bar.querySelector(':scope > span').getBoundingClientRect().top,
      barHeight: bar.querySelector(':scope > span').getBoundingClientRect().height,
    })));
    const full = geometry.find((g) => g.days === 7);
    const four = geometry.find((g) => g.days === 4);
    const guide = full.barTop;
    for (const g of geometry) expect(g.valueBottom, `${g.days} of 7 label`).toBeLessThanOrEqual(guide + 0.5);
    expect(four.barTop).toBeGreaterThan(guide + 10);
    expect(Math.abs(four.barHeight / full.barHeight - 4 / 7)).toBeLessThan(0.03);
    await page.getByRole('button', { name: 'Show as table' }).first().click();
    await expect(page.locator('[data-lld-table="weekly-bars"]')).toBeVisible();
  });

  test('TC-FR3-12-AS-02 D9 Done on a day not yet counted raises the ring by one and says so; a counted day stays once', async ({ page }) => {
    await fresh(page);
    await page.goto('/members/home/');
    const ring = page.locator('[data-lld-section="ring"]');
    const before = Number(await ring.getAttribute('data-lld-ring-done'));
    await page.locator('[data-lld-section="next-step"]').getByRole('button', { name: 'Done', exact: true }).click();
    await expect(page).toHaveURL(/saved=done/);
    await expect(ring).toHaveAttribute('data-lld-ring-done', String(before + 1));
    await expect(ring).toHaveAttribute('data-lld-ring-was', String(before));
    await expect(ring.locator('[data-lld-ring-change="up"]')).toHaveText(`Today counts. You are now at ${before + 1} of 7 days.`);
    await expect(ring).toHaveClass(/lld-ring-rose/);
    expect(await ring.locator('.lld-ring-fill').evaluate((el) => getComputedStyle(el).animationName)).toBe('lld-ring-grow');
    // A quick log on the same day: already counted, so the count stays and the ring says so.
    await page.locator('[data-lld-tile="water"] button').click();
    await expect(page).toHaveURL(/saved=water/);
    await expect(ring).toHaveAttribute('data-lld-ring-done', String(before + 1));
    await expect(ring.locator('[data-lld-ring-change="same"]')).toHaveText(`Today was already counted: ${before + 1} of 7 days.`);
    await expect(ring).not.toHaveClass(/lld-ring-rose/);
    // A hand-made link with a count that was never true changes nothing: the ring shows the server's count, no message.
    const on = new URL(page.url()).searchParams.get('ring_on');
    for (const was of [before + 3, before - 1, 9]) {
      await page.goto(`/members/home/?saved=done&ring_was=${was}&ring_on=${on}`);
      await expect(ring).toHaveAttribute('data-lld-ring-done', String(before + 1));
      await expect(ring.locator('[data-lld-ring-change]')).toHaveCount(0);
      await expect(ring).not.toHaveClass(/lld-ring-rose/);
    }
    // Another day's link says nothing either.
    await page.goto(`/members/home/?saved=done&ring_was=${before}&ring_on=2000-01-01`);
    await expect(ring.locator('[data-lld-ring-change]')).toHaveCount(0);
  });

  test('TC-NFR3-12-AS-01 D9 no ring animation with reduced motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await fresh(page);
    await page.goto('/members/home/');
    await page.locator('[data-lld-section="next-step"]').getByRole('button', { name: 'Done', exact: true }).click();
    const ring = page.locator('[data-lld-section="ring"]');
    await expect(ring).toHaveClass(/lld-ring-rose/);
    expect(await ring.locator('.lld-ring-fill').evaluate((el) => getComputedStyle(el).animationName)).toBe('none');
    expect(await ring.evaluate((el) => getComputedStyle(el).animationName)).toBe('none');
  });
});
