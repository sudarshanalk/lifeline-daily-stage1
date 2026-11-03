// The members' programming interface, live on the CI WordPress (NFR3-09, NFR3-10): a signed-in test member reads and
// writes every Stage 3 data type; a second member can never read the first's rows; and after the run no health value
// appears in caught mail or the server logs. The switches are at test_accounts in CI (tests/ci/stage2-wp.sh) and the
// two members' addresses are the listed test accounts, so ordinary Stage 2 members see nothing new.
import { existsSync, readFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { createMember, signIn, password } from './helpers.mjs';
import { restNonce } from '../../stage2/e2e/helpers.mjs';

const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const A = 'p3-api-a@test.lifelinedaily.invalid';
const B = 'p3-api-b@test.lifelinedaily.invalid';
// Sentinel values (fixtures/sentinel-values.json): unique numbers whose appearance in mail or logs is a leak.
const SENTINEL = { weightKg: 83.7, waistCm: 97.3 };
const DAY = '2026-11-01'; // clearly in the past: a reading more than 5 minutes ahead of the clock is refused

/** Minimal valid rows per writable type (class-api.php types()). */
const ROWS = {
  goals: { role: 'main', goal_type: 'lose_weight', measure: 'weight', unit: 'kg', start_value: SENTINEL.weightKg, target_value: 80 },
  plans: { goal_id: 1, calorie_target: 1800, valid_from: DAY, created_by: 'engine' },
  today: { step_date: DAY, action_type: 'log_reading', status: 'shown' },
  'custom-foods': { name: 'Sentinel oat bar', portion_g: 40, kcal: 160 },
  'food-log': { entry_date: DAY, meal: 'breakfast', food_id: 1, food_kind: 'custom', grams: 40, kcal: 160, source: 'search' },
  favourites: { food_id: 1, food_kind: 'custom' },
  targets: { kcal: 1800, protein_g: 84, set_by: 'member', valid_from: DAY },
  recipes: { name: 'Sentinel soup', servings: 2, ingredients: [{ name: 'lentils', grams: 100 }] },
  readings: { reading_type: 'waist', taken_at: `${DAY} 08:00:00`, value_1: SENTINEL.waistCm, unit_shown: 'cm', source: 'manual' },
  reminders: { reminder_type: 'log', channel: 'email', time_local: '08:00', days: 'mon,wed,fri' },
  shares: { include_health: false, content: 'A week of steady steps.' },
};
const READ_ONLY = ['insights', 'calculator-results'];

/** The Stage 2 health profile, saved through its own route as the Stage 2 tests do (tests/stage2/e2e/premium.spec.mjs). */
async function saveProfile(page, memberId) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const res = await page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, {
    data: { kind: 'profile', height_cm: 170, conditions_answered: 'none', confirmed: true },
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function api(page) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
  const call = (method, path, data) => page.request.fetch(`/wp-json/lld-members/v1/me/${path}`, { method, headers, data: data ? JSON.stringify(data) : undefined });
  return { get: (p) => call('GET', p), post: (p, d) => call('POST', p, d), del: (p) => call('DELETE', p) };
}

test.describe.serial('members interface', () => {
  const ids = {};

  test('TC-NFR3-09-AS-01 every Stage 3 data type is read and written by a signed-in test member', async ({ page }) => {
    const member = await createMember(page.request, { email: A, password, first_name: 'Sentinel', health_consent: true, access_state: 'Premium' });
    await signIn(page, A, password);
    // The member answers the health questions ("no conditions"): the Stage 3 safety matrix hides calorie targets and
    // other numbers from a member whose answers are unknown (P3-03 Part B, merged in #171), exactly as it should.
    await saveProfile(page, member.member_id);
    const me = await api(page);
    const features = await me.get('features');
    expect(features.ok(), await features.text()).toBeTruthy();
    for (const [type, row] of Object.entries(ROWS)) {
      // A food log entry stores calories worked out from the food, so it uses the custom food just created.
      let payload = row;
      if (type === 'food-log') {
        payload = { ...row, food_id: ids['custom-foods'], food_kind: 'custom' };
        delete payload.kcal;
      }
      const created = await me.post(type, payload);
      expect(created.status(), `${type}: ${await created.text()}`).toBe(201);
      const body = await created.json();
      expect(body.id, type).toBeGreaterThan(0);
      ids[type] = body.id;
      const one = await me.get(`${type}/${body.id}`);
      expect(one.status(), `${type} read`).toBe(200);
      const list = await me.get(type);
      expect(list.status(), `${type} list`).toBe(200);
      expect((await list.json()).rows.map((r) => r.id)).toContain(body.id);
    }
    for (const type of READ_ONLY) {
      const list = await me.get(type);
      expect(list.status(), `${type} list`).toBe(200);
      const refused = await me.post(type, {});
      expect([405, 422]).toContain(refused.status());
    }
    // A sealed value comes back as the number that was written, not a token.
    const reading = await (await me.get(`readings/${ids.readings}`)).json();
    expect(Number(reading.value_1)).toBe(SENTINEL.waistCm);
  });

  test('TC-NFR3-09-AS-02 a member can never read another member\'s data through the interface', async ({ page }) => {
    const second = await createMember(page.request, { email: B, password, first_name: 'Other', health_consent: true });
    await signIn(page, B, password);
    await saveProfile(page, second.member_id);
    const other = await api(page);
    for (const [type, id] of Object.entries(ids)) {
      const one = await other.get(`${type}/${id}`);
      expect(one.status(), `${type}/${id} as another member`).toBe(404);
      const list = await other.get(type);
      expect(list.status()).toBe(200);
      expect((await list.json()).rows.map((r) => r.id), `${type} list as another member`).not.toContain(id);
      const patched = await page.request.fetch(`/wp-json/lld-members/v1/me/${type}/${id}`, { method: 'PATCH', headers: { 'X-WP-Nonce': await restNonce(page), 'Content-Type': 'application/json' }, data: '{}' });
      expect([404, 405, 422]).toContain(patched.status());
      const deleted = await other.del(`${type}/${id}`);
      expect([404, 405]).toContain(deleted.status());
    }
    // The first member's rows are still there.
    await signIn(page, A, password);
    const me = await api(page);
    expect((await me.get(`readings/${ids.readings}`)).status()).toBe(200);
  });

  test('TC-NFR3-10-AS-02 no health value in caught mail or the server logs after the run', async ({ request }) => {
    const needles = [String(SENTINEL.weightKg), String(SENTINEL.waistCm), 'Sentinel oat bar', 'Sentinel soup'];
    for (const needle of needles) {
      const found = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`"${needle}"`)}`);
      expect(found.ok(), `Mailpit search ${needle}`).toBeTruthy();
      expect((await found.json()).messages_count, `caught mail mentioning ${needle}`).toBe(0);
    }
    // In CI the PHP built-in server log holds request lines only (no WP_DEBUG_LOG), so this part proves the values
    // never reach a URL; the mail search above is the stronger check.
    const temp = process.env.RUNNER_TEMP;
    for (const file of temp ? [`${temp}/php-server.log`, `${temp}/wp/wp-content/debug.log`] : []) {
      if (!existsSync(file)) continue;
      const text = readFileSync(file, 'utf8');
      for (const needle of needles) expect(text, `${file} mentions ${needle}`).not.toContain(needle);
    }
  });
});
