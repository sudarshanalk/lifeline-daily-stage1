// Helpers for the B2 "Log a reading" contracts (Codex's p36-contract-helpers.mjs, the readings part), kept in their
// own file so the Food page's pull request, which carries the shared helper, merges without a conflict.
import { expect } from '@playwright/test';
import { createMember, signIn, password } from '../../key-journeys/helpers.mjs';
import { restNonce } from '../../../stage2/e2e/helpers.mjs';

export const A = 'p3-api-a@test.lifelinedaily.invalid';
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
export async function reading(page, type, value_1, fields = {}) {
  return api(page, 'readings', 'POST', { reading_type: type, taken_at: AT, value_1, source: 'manual', ...fields });
}
export async function acted(page, endpoint, action, method = 'POST') {
  const pending = page.waitForResponse((r) => r.request().method() === method &&
    new URL(r.url()).pathname.endsWith(`/me/${endpoint}`));
  await action();
  return pending;
}
