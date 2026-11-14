import { expect } from '@playwright/test';
import { createMember, signIn, password } from './helpers.mjs';
import { restNonce } from '../../stage2/e2e/helpers.mjs';

export const A = 'p3-api-a@test.lifelinedaily.invalid';
export const B = 'p3-api-b@test.lifelinedaily.invalid';
export const DAY = '2026-11-07';
export const AT = `${DAY} 09:00:00`;

export async function fresh(page, { email = A, conditions = [], access_state = 'Premium', health = true, profile = true } = {}) {
  const reset = await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  expect(reset.ok(), await reset.text()).toBeTruthy();
  const member = await createMember(page.request, { email, password, first_name: 'Alex',
    health_consent: health, consents: health ? ['terms', 'health'] : ['terms'], access_state });
  await signIn(page, email, password);
  const clock = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: `${DAY} 12:00:00` } });
  expect(clock.ok(), await clock.text()).toBeTruthy();
  await page.goto('/members/health/');
  if (health && profile) {
    const response = await page.request.post(`/wp-json/lld-members/v1/members/${member.member_id}/health`, {
      headers: { 'X-WP-Nonce': await restNonce(page) }, data: { kind: 'profile', confirmed: true,
        sex: 'female', height_cm: 175, weight_kg: 90, activity_level: 'some',
        conditions_answered: conditions.length ? 'declared' : 'none', conditions: JSON.stringify(conditions) },
    });
    expect(response.ok(), await response.text()).toBeTruthy();
  }
  return member;
}

export async function api(page, path, method = 'GET', data) {
  const response = await page.request.fetch(`/wp-json/lld-members/v1/me/${path}`, {
    method, headers: { 'X-WP-Nonce': await restNonce(page) }, ...(data === undefined ? {} : { data }),
  });
  expect(response.ok(), `${method} ${path}: ${await response.text()}`).toBeTruthy();
  return response.json();
}
export const rows = (body) => Array.isArray(body) ? body : body.rows || body.items || [];
export async function custom(page, name = 'Synthetic cooked rice', overrides = {}) {
  return api(page, 'custom-foods', 'POST', { name, portion_g: 100, kcal: 130,
    protein_g: 2.5, carb_g: 28, fat_g: 0.3, fibre_g: 0.4, ...overrides });
}
export async function logFood(page, food, fields = {}) {
  return api(page, 'food-log', 'POST', { entry_date: DAY, meal: 'lunch', food_id: Number(food.id),
    food_kind: 'custom', grams: 100, source: 'search', ...fields });
}
export async function reading(page, type, value_1, fields = {}) {
  return api(page, 'readings', 'POST', { reading_type: type, taken_at: AT, value_1, source: 'manual', ...fields });
}
export async function acted(page, endpoint, action, method = 'POST') {
  const pending = page.waitForResponse((r) => r.request().method() === method &&
    new URL(r.url()).pathname.endsWith(`/me/${endpoint}`));
  await action();
  return pending;
}
export async function openFood(page, date = DAY) {
  const response = await page.goto(`/members/food/?date=${date}`);
  expect(response.status()).toBe(200);
  await expect(page.getByRole('heading', { name: /Food|My meals/, exact: true }).first()).toBeVisible();
}
export async function addDialog(page, meal = 'lunch') {
  await page.locator(`[data-lld-open-add="${meal}"]`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  return dialog;
}
export async function findFood(page, name, meal = 'lunch') {
  const dialog = await addDialog(page, meal);
  await dialog.getByRole('searchbox', { name: 'Search foods', exact: true }).fill(name);
  const hit = dialog.locator('[data-lld-search-results] li').filter({ hasText: name });
  await expect(hit).toHaveCount(1);
  return { dialog, hit };
}
export async function addThroughUi(page, name, grams, meal = 'lunch') {
  const { dialog, hit } = await findFood(page, name, meal);
  await hit.locator('[data-lld-pick-food]').click();
  await dialog.getByLabel('Amount (g)', { exact: true }).fill(String(grams));
  const response = await acted(page, 'food-log', () => dialog.getByRole('button', { name: 'Add to meal', exact: true }).click());
  expect(response.ok(), await response.text()).toBeTruthy();
  await expect(dialog).toBeHidden();
}
