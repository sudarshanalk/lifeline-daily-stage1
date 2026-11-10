// Shared steps for the Healthy Weight screen specs (P3-35, package P4), as goal-journey.spec.mjs does them: a clean test
// member (p3-api-a or p3-api-b, the listed test accounts), a frozen member clock, the health profile through its own
// route, and the joining journey through the screens. Every number is made up. Not a spec itself.
import { expect } from '@playwright/test';
import { createMember, signIn, password } from '../../key-journeys/helpers.mjs';
import { restNonce } from '../../../stage2/e2e/helpers.mjs';

export const A = 'p3-api-a@test.lifelinedaily.invalid';
export const B = 'p3-api-b@test.lifelinedaily.invalid';
export const ID = /\b(?:TC-)?N?FR\d-\d\d/;

/** Day n from a start date, as a UTC datetime the test clock takes. */
export const at = (start, day, hour = 9) => { const d = new Date(`${start}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + day); d.setUTCHours(hour); return d.toISOString().slice(0, 19).replace('T', ' '); };

export async function clock(page, email, utc) {
  const res = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

/** A clean member with health consent, signed in, its clock frozen at `utc`. */
export async function fresh(page, email, utc, fields = {}) {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  const member = await createMember(page.request, { email, password, first_name: 'Sam', health_consent: true, consents: ['terms'], ...fields });
  await signIn(page, email, password);
  await clock(page, email, utc);
  return member;
}

/** The Stage 2 health profile: the prototype's example member (woman, 163 cm, 80 kg) unless told otherwise. */
export async function profile(page, memberId, fields = {}) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const data = { kind: 'profile', sex: 'female', height_cm: 163, weight_kg: 80, activity_level: 'some', conditions_answered: 'none', confirmed: true, ...fields };
  const res = await page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, { data, headers: { 'X-WP-Nonce': nonce } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

/** A weigh-in through the members' interface. */
export async function weighIn(page, utc, kg) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const res = await page.request.post('/wp-json/lld-members/v1/me/readings', { data: { reading_type: 'weight', taken_at: utc, value_1: kg, unit_shown: 'kg', source: 'manual' }, headers: { 'X-WP-Nonce': nonce } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

/** Post a form and wait for the server's answer, so webkit never cancels the post with the next navigation. */
export async function submit(page, button, path) {
  const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === path);
  await button.click();
  await answered;
  await page.waitForLoadState('load');
}

/** The weight goal with "where you are now" first: current 80 kg, goal 60 kg. Lands on the eight questions. */
export async function weightGoal(page, { current = 80, target = 60 } = {}) {
  await page.goto('/members/goal/?type=lose_weight');
  const details = page.locator('[data-lld-goal-step="details"]');
  await expect(details).toBeVisible();
  await expect(details.locator('[data-lld-section="hw-where"]')).toBeVisible();
  await page.locator('#lld_current').fill(String(current));
  await page.locator('#lld_target').fill(String(target));
  await submit(page, page.getByRole('button', { name: 'Save my goal' }), '/members/goal/');
  await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=questions$/);
}

/** Answer the eight questions (answer key => value); a question left out is skipped. Lands on the pace. */
export async function answer(page, answers) {
  const form = page.locator('[data-lld-goal-step="questions"] form');
  for (const [key, value] of Object.entries(answers)) {
    if (key === 'wake_time') { await form.locator(`input[name="lld_hw[${key}]"]`).fill(value); continue; }
    await form.locator(`input[name="lld_hw[${key}]"][value="${value}"]`).check({ force: true });
  }
  await submit(page, form.getByRole('button', { name: 'Next' }), '/members/goal/');
  await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+&hw=pace$/);
}

/** Choose a pace on the pace step. Lands on the plan. */
export async function pace(page, value) {
  const step = page.locator('[data-lld-goal-step="pace"]');
  await step.locator(`input[name="lld_hw_pace"][value="${value}"]`).check();
  await submit(page, step.getByRole('button', { name: 'Next' }), '/members/goal/');
  await expect(page).toHaveURL(/\/members\/goal\/\?done=\d+$/);
}

/** Every Healthy Weight answer of a member who feels confident, has time and sleeps well (pace 0.75 kg a week). */
export const CONFIDENT = { reason: 'health', barrier: 'evening_snacking', time: 'over_30', work: 'days', confidence: '8', sleep: '7_9', stress: '3', alcohol_freq: 'never' };
