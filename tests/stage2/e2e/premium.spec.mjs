import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, mailCode, signIn } from './helpers.mjs';
import { GPS_MARK, gpsJpeg, hasGps } from '../tools/gps-jpeg.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

function stamp() {
  return randomBytes(4).toString('hex');
}

function emailFor(label) {
  return `${label}-${stamp()}@test.lifelinedaily.invalid`;
}

async function clock(request, email, utc) {
  const res = await request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function fixture(request) {
  const mark = stamp();
  const res = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'premium', stamp: mark } });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function paid(request, label, extra = {}) {
  const email = emailFor(label);
  const created = await createMember(request, {
    email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'], access_state: 'Premium', ...extra,
  });
  return { email, id: created.member_id };
}

async function saveHealth(page, memberId, data) {
  await page.goto('/members/home/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  const res = await page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, {
    data,
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function startSlug(page, slug) {
  await page.goto('/members/tracks/');
  const nonce = await page.locator('input[name="lld_track_nonce"]').first().inputValue();
  const res = await page.request.post('/members/tracks/', {
    form: { lld_track_nonce: nonce, lld_track: slug },
    maxRedirects: 0,
  });
  expect([200, 302, 303]).toContain(res.status());
}

async function startNamed(page, name) {
  await page.goto('/members/tracks/');
  await page.getByRole('button', { name }).click();
}

function fileOf(name, bytes) {
  const path = join(tmpdir(), name);
  writeFileSync(path, bytes);
  return path;
}

function unzip(buffer) {
  const files = {};
  let offset = 0;
  while (offset + 30 < buffer.length && buffer.readUInt32LE(offset) === 0x04034b50) {
    const method = buffer.readUInt16LE(offset + 8);
    const compressed = buffer.readUInt32LE(offset + 18);
    const nameLength = buffer.readUInt16LE(offset + 26);
    const extraLength = buffer.readUInt16LE(offset + 28);
    const name = buffer.slice(offset + 30, offset + 30 + nameLength).toString();
    const start = offset + 30 + nameLength + extraLength;
    const raw = buffer.slice(start, start + compressed);
    files[name] = method === 0 ? raw : inflateRawSync(raw);
    offset = start + compressed;
  }
  return files;
}

test.beforeEach(async ({ request }) => {
  await request.get('/members/group/');
});

test('TC-FR2-32-AS-01', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'meals');
  await clock(request, member.email, '2026-05-04 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', diet_type: 'vegetarian', confirmed: true });
  await startSlug(page, made.track);
  await page.goto('/members/meals/');
  await expect(page.locator('[data-lld-day]')).toHaveCount(7);
  await expect(page.locator('main')).toContainText(made.lentil);
  await expect(page.locator('main')).not.toContainText(made.tofu);

  // the meal week is the track week: a track started on a Wednesday has meal weeks from Wednesday to Tuesday
  const midweek = await paid(request, 'meals-wed');
  const plans = async () => (await (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email: midweek.email } })).json()).meal_plans;
  await clock(request, midweek.email, '2026-05-06 12:00:00');
  await signIn(page, midweek.email, password);
  await saveHealth(page, midweek.id, { kind: 'profile', diet_type: 'vegetarian', confirmed: true });
  await startSlug(page, made.track);
  await page.goto('/members/meals/');
  await expect(page.locator('[data-lld-week]')).toHaveText('Week 1, starting Wednesday 6 May');
  await expect(page.locator('[data-lld-day="1"]')).toHaveAttribute('data-lld-date', '2026-05-06');
  await expect(page.locator('[data-lld-day="7"]')).toHaveAttribute('data-lld-date', '2026-05-12');
  await page.locator('#lld_recipe_1_breakfast').selectOption({ label: made.oat });
  await page.locator('form[data-lld-swap="1-breakfast"]').getByRole('button', { name: 'Swap' }).click();
  expect(await plans()).toEqual([expect.objectContaining({ week_starting: '2026-05-06', template_version: `${made.meal}:v1` })]);

  // the owner edits and re-approves the plan: the week already given keeps its version and its swap
  await request.post('/wp-json/lld-members/v1/test/premium', { data: { revise: { id: made.meal, fields: { meals: JSON.stringify(Object.fromEntries([1, 2, 3, 4, 5, 6, 7].map((d) => [d, { breakfast: made.oat_id, lunch: made.oat_id, dinner: made.oat_id, snack: made.oat_id }]))) } } } });
  await clock(request, midweek.email, '2026-05-11 12:00:00');
  await page.goto('/members/meals/');
  await expect(page.locator('[data-lld-week]')).toHaveText('Week 1, starting Wednesday 6 May');
  await expect(page.locator('[data-lld-meal="1-breakfast"]')).toContainText(made.oat);
  await expect(page.locator('[data-lld-meal="2-lunch"]')).toContainText(made.lentil);
  expect(await plans()).toHaveLength(1);

  // track week 2 starts on day 8 with the version approved now
  await clock(request, midweek.email, '2026-05-13 12:00:00');
  await page.goto('/members/meals/');
  await expect(page.locator('[data-lld-week]')).toHaveText('Week 2, starting Wednesday 13 May');
  await expect(page.locator('[data-lld-meal="2-lunch"]')).toContainText(made.oat);
  await expect(page.locator('main')).not.toContainText(made.lentil);
  const both = await plans();
  expect(both.map((p) => p.week_starting)).toEqual(['2026-05-06', '2026-05-13']);
  expect(both[1].template_version).toBe(`${made.meal}:v2`);

  // no track and no enrolment: weeks count from the day the member joined
  const loose = { email: emailFor('meals-none') };
  const joined = (await createMember(request, { email: loose.email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'], access_state: 'Premium' })).joined_on;
  expect(joined).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  const later = new Date(`${joined}T12:00:00Z`);
  later.setUTCDate(later.getUTCDate() + 15);
  await clock(request, loose.email, `${later.toISOString().slice(0, 10)} 12:00:00`);
  await signIn(page, loose.email, password);
  await page.goto('/members/meals/');
  const third = new Date(`${joined}T12:00:00Z`);
  third.setUTCDate(third.getUTCDate() + 14);
  const label = third.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).replace(',', '');
  await expect(page.locator('[data-lld-week]')).toHaveText(`Week 3, starting ${label}`);
  const rows = await (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email: loose.email } })).json();
  for (const row of rows.meal_plans) expect(row.week_starting).toBe(third.toISOString().slice(0, 10));
});

test('TC-FR2-32-AS-02', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'preg');
  await clock(request, member.email, '2026-05-04 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, {
    kind: 'profile', diet_type: 'vegetarian', conditions_answered: 'declared', conditions: ['pregnancy'], confirmed: true,
  });
  await startSlug(page, made.track);
  await page.goto('/members/meals/');
  await expect(page.locator('main')).toContainText(made.lentil);
  await expect(page.locator('main')).not.toContainText(made.loss);

  // the week's own template is tagged weight-loss: a pregnant member gets the safe empty state, not that week
  const lean = await paid(request, 'preg-lean');
  await clock(request, lean.email, '2026-05-04 12:00:00');
  await signIn(page, lean.email, password);
  await saveHealth(page, lean.id, {
    kind: 'profile', diet_type: 'vegetarian', conditions_answered: 'declared', conditions: ['pregnancy'], confirmed: true,
  });
  await startSlug(page, made.lean);
  await page.goto('/members/meals/');
  await expect(page.locator('main')).not.toContainText(made.broth);
  await expect(page.locator('[data-lld-day]')).toHaveCount(0);
  await expect(page.getByText('Contact support if you need a plan that fits you.')).toBeVisible();
  const pdf = await page.request.get('/members/meals/?pdf=1');
  expect((await pdf.body()).toString('latin1')).not.toContain('clear broth');
});

test('TC-FR2-32-AS-03', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'shop');
  await clock(request, member.email, '2026-05-04 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', diet_type: 'vegetarian', confirmed: true });
  await startSlug(page, made.track);
  await page.goto('/members/meals/');
  await expect(page.getByRole('button', { name: 'Print' })).toBeVisible();
  await expect(page.locator('[data-lld-shopping]')).toContainText('red lentils');
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download PDF' }).click();
  const download = await downloadPromise;
  const body = readFileSync(await download.path()).toString('latin1');
  expect(body.startsWith('%PDF-1.4\n')).toBe(true);
  const xref = Number(body.match(/startxref\n(\d+)\n%%EOF\s*$/)[1]);
  expect(body.slice(xref, xref + 5)).toBe('xref\n');
  const count = Number(body.slice(xref).match(/^xref\n0 (\d+)\n/)[1]);
  const table = body.slice(xref).split('\n').slice(2, 2 + count);
  table.slice(1).forEach((entry, i) => {
    const offset = Number(entry.slice(0, 10));
    expect(body.slice(offset, offset + String(i + 1).length + 6)).toBe(`${i + 1} 0 obj`);
  });
  const lines = [...body.matchAll(/\(((?:\\.|[^\\)])*)\) Tj/g)].map((m) => m[1]);
  expect(lines).toContain('\\225 red lentils, 150 g');
  expect(lines).toContain('\\225 spinach, 80 g');
});

test('TC-FR2-32-AS-04', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'swap');
  await clock(request, member.email, '2026-05-04 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', diet_type: 'vegetarian', confirmed: true });
  await startSlug(page, made.track);
  await page.goto('/members/meals/');
  // every meal of the week has a Swap control or the no-safe-swap state
  const meals = page.locator('[data-lld-meal]');
  await expect(meals).toHaveCount(28);
  for (let i = 0; i < 28; i += 1) {
    const meal = meals.nth(i);
    expect(await meal.locator('form[data-lld-swap] select').count() + await meal.getByText('No safe swap for this meal.').count()).toBe(1);
  }
  const breakfast = page.locator('#lld_recipe_1_breakfast');
  await expect(breakfast).toContainText(made.oat);
  await expect(breakfast).not.toContainText(made.loss);
  await expect(page.locator('[data-lld-shopping]')).not.toContainText('oats');
  await breakfast.selectOption({ label: made.oat });
  await page.locator('form[data-lld-swap="1-breakfast"]').getByRole('button', { name: 'Swap' }).click();
  await expect(page.locator('[data-lld-shopping]')).toContainText('oats');
  await expect(page.locator('[data-lld-meal="1-breakfast"]')).toContainText(made.oat);

  // a meal other than breakfast: Day 3 dinner
  const dinner = page.locator('#lld_recipe_3_dinner');
  await expect(dinner).toContainText(made.bean);
  await dinner.selectOption({ label: made.bean });
  await page.locator('form[data-lld-swap="3-dinner"]').getByRole('button', { name: 'Swap' }).click();
  await expect(page.locator('[data-lld-meal="3-dinner"]')).toContainText(made.bean);
  await expect(page.locator('[data-lld-meal="2-dinner"]')).toContainText(made.lentil);
  await expect(page.locator('[data-lld-shopping]')).toContainText('butter beans');
  await page.reload();
  await expect(page.locator('[data-lld-meal="3-dinner"]')).toContainText(made.bean);
  await expect(page.locator('[data-lld-meal="1-breakfast"]')).toContainText(made.oat);

  // the slot is checked, and the recipe must be one that slot offers
  const nonce = await page.locator('form[data-lld-swap="1-breakfast"] input[name="lld_swap_nonce"]').inputValue();
  for (const form of [
    { lld_slot: '2-breakfast', lld_recipe: String(made.bean_id) },
    { lld_slot: '1-breakfast', lld_recipe: String(made.bean_id) },
    { lld_slot: '9-brunch', lld_recipe: String(made.oat_id) },
  ]) {
    const res = await page.request.post('/members/meals/', { form: { lld_swap_nonce: nonce, ...form }, maxRedirects: 0 });
    expect(res.status()).toBeLessThan(400);
  }
  await page.reload();
  await expect(page.locator('[data-lld-meal="2-breakfast"]')).toContainText(made.lentil);
  await expect(page.locator('[data-lld-meal="1-breakfast"]')).toContainText(made.oat);
});

test('TC-FR2-32-AS-06', async ({ page, request }) => {
  const made = await fixture(request);
  const email = emailFor('freemeal');
  await createMember(request, { email, password, first_name: 'Sam', role: 'member', consents: ['terms', 'health'], access_state: 'free' });
  await signIn(page, email, password);
  await page.goto('/members/meals/');
  await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Upgrade' })).toBeVisible();
  await expect(page.locator('main')).not.toContainText(made.lentil);
});

test('TC-FR2-33-AS-02', async ({ page, request }) => {
  const made = await fixture(request);
  const alone = await paid(request, 'chal-alone');
  const tracked = await paid(request, 'chal-track');
  await clock(request, alone.email, '2026-06-01 12:00:00');
  await clock(request, tracked.email, '2026-06-01 12:00:00');
  await signIn(page, tracked.email, password);
  await startNamed(page, 'Start Fitness and Strength');
  for (const email of [alone.email, tracked.email]) {
    await signIn(page, email, password);
    await page.goto('/members/challenge/');
    await page.getByRole('button', { name: 'Join' }).click();
    await clock(request, email, '2026-06-28 12:00:00');
    await page.goto('/members/challenge/');
    await expect(page.getByText(made.badge)).toBeVisible();
  }

  // joined mid-month: the challenge carries on into the next month and finishes on its own day 28
  const mid = await paid(request, 'chal-mid');
  await clock(request, mid.email, '2026-06-20 12:00:00');
  await signIn(page, mid.email, password);
  await page.goto('/members/challenge/');
  await page.getByRole('button', { name: 'Join' }).click();
  await clock(request, mid.email, '2026-07-05 12:00:00');
  await page.goto('/members/challenge/');
  await expect(page.getByRole('heading', { name: made.challenge })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Join' })).toHaveCount(0);
  await expect(page.getByText(made.actions[15], { exact: true })).toBeVisible();
  await expect(page.getByText(made.badge)).toHaveCount(0);
  await clock(request, mid.email, '2026-07-17 12:00:00');
  await page.goto('/members/challenge/');
  await expect(page.getByRole('heading', { name: made.challenge })).toBeVisible();
  await expect(page.getByText(made.badge)).toBeVisible();
});

test('TC-FR2-33-AS-03', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'hidden');
  await clock(request, member.email, '2026-06-01 12:00:00');
  await signIn(page, member.email, password);
  await page.goto('/members/challenge/');
  await expect(page.getByRole('heading', { name: made.challenge })).toBeVisible();
  await expect(page.locator('main')).not.toContainText(made.hidden);
});

test('TC-FR2-33-AS-04', async ({ page, request }) => {
  const made = await fixture(request);
  const email = emailFor('noconsent');
  const created = await createMember(request, { email, password, first_name: 'Sam', role: 'member', consents: ['terms'], access_state: 'Premium' });
  await clock(request, email, '2026-06-01 12:00:00');
  await signIn(page, email, password);
  for (let day = 1; day <= 28; day += 1) {
    await page.goto(`/members/challenge/?day=${day}`);
    await expect(page.getByText(made.actions[day - 1])).toBeVisible();
  }
  await expect(page.getByText("progress isn't saved")).toBeVisible();
  const scan = await request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, scope: 'health' } });
  expect((await scan.json()).problems).toEqual([]);
});

test('TC-FR2-34-AS-02', async ({ page, request }) => {
  await page.clock.install();
  await fixture(request);
  const member = await paid(request, 'timer');
  await signIn(page, member.email, password);
  await page.goto('/members/workout/?level=beginner&place=home&session=1');
  await expect(page.getByText('Warm up for 5 minutes. Stop if you feel pain.')).toBeVisible();
  await expect(page.getByText('Warm up before you start. Stop if you feel pain. Check with your doctor if you need to.')).toBeVisible();
  // no profile yet: the matrix note for an unknown situation comes with the advice
  await expect(page.locator('#lld-safety')).toContainText('Complete your health profile for more suggestions');
  await expect(page.locator('#lld-session')).toBeHidden();
  await page.getByRole('button', { name: "I'm ready" }).click();
  await expect(page.locator('#lld-safety')).toBeHidden();
  await expect(page.locator('#lld-exercise')).toHaveText('Squat to chair');
  await expect(page.locator('#lld-how')).toHaveText('Sit down and stand up slowly.');
  await expect(page.locator('#lld-phase')).toHaveText('exercise');
  await expect(page.locator('#lld-timer')).toHaveText('10');
  await page.getByRole('button', { name: 'Start' }).click();
  await page.clock.runFor(10000);
  await expect(page.locator('#lld-phase')).toHaveText('rest');
  await expect(page.locator('#lld-timer')).toHaveText('30');
  await page.clock.runFor(30000);
  // the session's second exercise follows its own time and rest
  await expect(page.locator('#lld-exercise')).toHaveText('Wall press');
  await expect(page.locator('#lld-phase')).toHaveText('exercise');
  await expect(page.locator('#lld-timer')).toHaveText('10');
  await page.getByRole('button', { name: 'Pause' }).click();
  await page.clock.runFor(5000);
  await expect(page.locator('#lld-timer')).toHaveText('10');
  await page.getByRole('button', { name: 'Start' }).click();
  await page.clock.runFor(10000);
  await expect(page.locator('#lld-phase')).toHaveText('rest');
  await expect(page.locator('#lld-timer')).toHaveText('20');
  await page.clock.runFor(20000);
  await expect(page.locator('#lld-phase')).toHaveText('done');

  // type 2 diabetes: the section 8.2 line before exercise
  const diabetic = await paid(request, 'timer-t2');
  await signIn(page, diabetic.email, password);
  await saveHealth(page, diabetic.id, { kind: 'profile', conditions_answered: 'declared', conditions: ['type-2-diabetes'], confirmed: true });
  await page.goto('/members/workout/?level=beginner&place=home&session=1');
  await expect(page.locator('#lld-safety')).toContainText('If you take insulin or medicine that can cause low blood sugar, check with your doctor.');
});

test('TC-FR2-34-AS-04', async ({ page, request }) => {
  test.setTimeout(240000);
  await fixture(request);
  const email = emailFor('nowork');
  const created = await createMember(request, { email, password, first_name: 'Sam', role: 'member', consents: ['terms'], access_state: 'Premium' });
  await signIn(page, email, password);
  for (const session of [1, 2]) {
    await page.goto(`/members/workout/?level=beginner&place=home&session=${session}`);
    await page.getByRole('button', { name: "I'm ready" }).click();
    await page.getByRole('button', { name: 'Done' }).click();
  }
  await expect(page.getByText("progress isn't saved")).toBeVisible();
  const scan = await request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, scope: 'health' } });
  expect((await scan.json()).problems).toEqual([]);
  const none = await (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email } })).json();
  expect(none).toMatchObject({ result: 0, workout: 0, plan: null });

  // control: with health consent the same steps are stored, per week and session, and the plan progresses
  const member = await paid(request, 'work-saved');
  await clock(request, member.email, '2026-05-04 12:00:00');
  await signIn(page, member.email, password);
  const rows = async () => (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email: member.email } })).json();
  await page.goto('/members/workout/?level=beginner&place=home');
  await page.getByRole('button', { name: 'Choose this plan' }).click();
  expect((await rows()).plan).toMatchObject({ level: 'beginner', version_key: 'home', current_week: '1' });
  await page.goto('/members/workout/');
  await expect(page.getByRole('heading', { name: 'Week 1' })).toBeVisible();
  await expect(page.locator('#lld_level')).toHaveValue('beginner');
  for (const session of [1, 2]) {
    await page.goto(`/members/workout/?session=${session}`);
    await page.getByRole('button', { name: "I'm ready" }).click();
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByText('Session saved.')).toBeVisible();
  }
  await page.goto('/members/workout/?session=1');
  await page.getByRole('button', { name: "I'm ready" }).click();
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('This session is already saved for week 1.')).toBeVisible();
  expect((await rows()).results.map((r) => r.programme_day)).toEqual(['1', '1']);

  // 7 days on, the plan moves to week 2 by itself, and week 2's session 1 is a new result
  await clock(request, member.email, '2026-05-11 12:00:00');
  await page.goto('/members/workout/');
  await expect(page.getByRole('heading', { name: 'Week 2' })).toBeVisible();
  expect((await rows()).plan.current_week).toBe('2');
  await page.goto('/members/workout/?session=1');
  await expect(page.locator('#lld-timer')).toHaveText('20');
  await page.getByRole('button', { name: "I'm ready" }).click();
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('Session saved.')).toBeVisible();
  const saved = await rows();
  expect(saved.results).toHaveLength(3);
  expect(saved.results[2].programme_day).toBe('2');
  expect(new Set(saved.results.map((r) => `${r.item_version}@${r.programme_day}`)).size).toBe(3);
  for (const r of saved.results) expect(r.item_version).toMatch(/^\d+:v\d+:s[12]$/);

  // "Move up a level" moves up: intermediate from week 1, recorded as a change
  await page.goto('/members/workout/');
  await page.getByRole('button', { name: 'Move up a level' }).click();
  await expect(page.locator('#lld_level')).toHaveValue('intermediate');
  await expect(page.getByRole('heading', { name: 'Week 1' })).toBeVisible();
  const moved = await rows();
  expect(moved.plan).toMatchObject({ level: 'intermediate', version_key: 'home', current_week: '1' });
  expect(moved.changes).toBe(1);
  await page.goto('/members/workout/');
  await expect(page.locator('#lld_level')).toHaveValue('intermediate');
});

test('TC-FR2-35-AS-02', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'qa');
  await clock(request, member.email, '2026-11-01 12:00:00');
  await signIn(page, member.email, password);
  await page.goto('/members/home/');
  await expect(page.getByText(`Next live Q&A ${made.qa}`)).toBeVisible();
  await expect(page.getByText("Facebook's own privacy terms apply")).toBeVisible();
  // the group page: the notice and the Q&A date; no placeholder link while LLD_FACEBOOK_GROUP_URL is not set
  await page.goto('/members/group/');
  await expect(page.getByText(`Next live Q&A ${made.qa}`)).toBeVisible();
  await expect(page.getByText("Facebook's own privacy terms apply")).toBeVisible();
  await expect(page.getByRole('link', { name: 'Join the private Facebook group' })).toHaveCount(0);
  await expect(page.locator('main a[href*="facebook.com"]')).toHaveCount(0);
});

test('TC-FR2-35-AS-03', async ({ page, request }) => {
  const email = emailFor('ended');
  await createMember(request, {
    email, password, first_name: 'End', role: 'member', consents: ['terms'], access_state: 'Premium ended', premium_ended_on: '2020-01-01',
  });
  const lists = async () => {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    let hits = 0;
    for (const message of list.messages || []) {
      const subject = String(message.Subject || '');
      if (!subject.includes('People to remove from the group')) continue;
      const full = await fetch(`${MAILPIT}/api/v1/message/${message.ID}`).then((res) => res.json());
      const body = `${full.Subject || ''}\n${full.Text || ''}`;
      if (body.includes(email)) hits += 1;
    }
    return hits;
  };
  const removals = async () => (await (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email } })).json()).removals;
  await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(await lists(), 'weekly removal list').toBe(1);
  await asOwner(page);
  const open = async () => {
    await page.goto('/wp-admin/admin.php?page=lld-group');
    if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-group');
  };
  await open();
  const row = page.locator('li', { hasText: email });
  await expect(row).toContainText('overdue');
  await row.getByRole('button', { name: 'Record removal' }).click();
  await expect(page.locator('li', { hasText: email })).toContainText(/Removed on \d{4}-\d{2}-\d{2}/);

  // the daily check still sees the same ended subscription: the recorded end is not added again
  await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(await removals()).toHaveLength(1);

  // a second Premium end is tracked for removal on its own row, on the next list, and recorded separately
  await request.post('/wp-json/lld-members/v1/test/premium', { data: { email, note_ended: '2021-03-01' } });
  await request.post('/wp-json/lld-members/v1/test/premium', { data: { email, note_ended: '2021-03-01' } });
  const both = await removals();
  expect(both.map((r) => r.premium_ended_on)).toEqual(['2020-01-01', '2021-03-01']);
  expect(both[0].removed_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(both[1].removed_on).toBeNull();
  await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(await lists(), 'the second end is on the next list').toBe(2);
  await asOwner(page);
  await open();
  const again = page.locator('li', { hasText: email }).filter({ hasText: 'ended 2021-03-01' });
  await expect(again).toContainText('overdue');
  await again.getByRole('button', { name: 'Record removal' }).click();
  await expect(page.locator('li', { hasText: email }).filter({ hasText: 'ended 2021-03-01' })).toContainText(/Removed on \d{4}-\d{2}-\d{2}/);
  const done = await removals();
  expect(done.every((r) => /^\d{4}-\d{2}-\d{2}$/.test(r.removed_on))).toBe(true);
  expect(done[0].removed_on).toBe(both[0].removed_on);
});

test('TC-FR2-36-AS-01', async ({ page, request }) => {
  const member = await paid(request, 'month');
  await clock(request, member.email, '2026-05-01 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', height_cm: 170, confirmed: true });
  await startNamed(page, 'Start Fitness and Strength');
  await clock(request, member.email, '2026-05-28 12:00:00');
  await page.goto('/members/check-in/');
  await expect(page.getByRole('heading', { name: 'Monthly check-in' })).toBeVisible();
  await expect(page.getByRole('checkbox', { name: 'Chair stands in 30 seconds' })).toBeChecked();
  await page.getByRole('checkbox', { name: 'Chair stands in 30 seconds' }).uncheck();
  await page.getByRole('button', { name: 'Save monthly check-in' }).click();
  await expect(page.getByText('Saved measures:')).not.toContainText('chair');
});

test('TC-FR2-36-AS-02', async ({ page, request }) => {
  const member = await paid(request, 'chart');
  await clock(request, member.email, '2026-05-01 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', height_cm: 170, confirmed: true });
  await startNamed(page, 'Start Fitness and Strength');
  await clock(request, member.email, '2026-05-28 12:00:00');
  for (const when of ['2026-05-28', '2026-06-15', '2026-07-15', '2026-08-15']) {
    await clock(request, member.email, `${when} 12:00:00`);
    await page.goto('/members/check-in/');
    await page.getByRole('button', { name: 'Save monthly check-in' }).click();
  }
  await page.goto('/members/badges/');
  const chart = page.locator('[data-lld-chart]');
  for (const month of ['2026-05', '2026-06', '2026-07', '2026-08']) {
    await expect(chart).toContainText(month);
  }
});

test('TC-FR2-36-AS-03', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'badge');
  await clock(request, member.email, '2026-05-01 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', height_cm: 170, conditions_answered: 'none', confirmed: true });
  await startNamed(page, 'Start Healthy Weight');
  await page.goto('/members/check-in/');
  await page.locator('#lld_weight').fill('80');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await clock(request, member.email, '2026-05-08 12:00:00');
  await page.goto('/members/check-in/');
  await page.locator('#lld_weight').fill('74');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await clock(request, member.email, '2028-06-01 12:00:00');
  await page.goto('/members/badges/');
  await expect(page.getByText(made.weight)).toBeVisible();
  await expect(page.getByText(made.active)).toBeVisible();

  for (const condition of ['pregnancy', 'eating-disorder']) {
    const blocked = await paid(request, condition);
    await clock(request, blocked.email, '2026-05-01 12:00:00');
    await signIn(page, blocked.email, password);
    await saveHealth(page, blocked.id, {
      kind: 'profile', height_cm: 170, conditions_answered: 'declared', conditions: [condition], confirmed: true,
    });
    await startNamed(page, 'Start Fitness and Strength');
    await clock(request, blocked.email, '2026-05-28 12:00:00');
    await page.goto('/members/check-in/');
    await page.getByRole('checkbox', { name: 'Weight in kilograms' }).check();
    await page.locator('#lld_weight').fill('80');
    await page.getByRole('button', { name: 'Save monthly check-in' }).click();
    await clock(request, blocked.email, '2026-06-15 12:00:00');
    await page.goto('/members/check-in/');
    await page.locator('#lld_weight').fill('74');
    await page.getByRole('button', { name: 'Save monthly check-in' }).click();
    await page.goto('/members/badges/');
    await expect(page.locator('main')).not.toContainText(made.weight);
    const rows = await request.post('/wp-json/lld-members/v1/test/premium', { data: { email: blocked.email } });
    expect((await rows.json()).badges).toEqual([]);
  }
});

test('TC-FR2-37-AS-01', async ({ page, request }) => {
  const made = await fixture(request);
  const premium = await paid(request, 'planned');
  await clock(request, premium.email, '2026-05-04 12:00:00');
  await signIn(page, premium.email, password);
  await saveHealth(page, premium.id, { kind: 'profile', diet_type: 'vegetarian', confirmed: true });
  await startSlug(page, made.track);
  await page.goto('/members/diary/');
  await page.getByRole('button', { name: 'I ate the planned meal' }).click();
  await expect(page.getByText('Breakfast: Logged')).toBeVisible();

  const email = emailFor('describe');
  await createMember(request, { email, password, first_name: 'Sam', role: 'member', consents: ['terms', 'health'], access_state: 'free' });
  await clock(request, email, '2026-05-04 12:00:00');
  await signIn(page, email, password);
  await startNamed(page, 'Start Healthy Weight');
  await page.goto('/members/diary/');
  await expect(page.getByRole('button', { name: 'I ate the planned meal' })).toHaveCount(0);
  await page.locator('#lld_description').fill('Chicken salad');
  await page.getByRole('button', { name: 'Save meal' }).click();
  await expect(page.getByText('Chicken salad')).toBeVisible();
  // "ate the planned meal" posted directly by a free member is not a planned entry
  const nonce = await page.locator('input[name="lld_diary_nonce"]').first().inputValue();
  await page.request.post('/members/diary/', { form: { lld_diary_nonce: nonce, lld_meal: 'lunch', lld_planned: '1' }, maxRedirects: 0 });
  await page.reload();
  await expect(page.getByText('Lunch: Add')).toBeVisible();
  await expect(page.getByText('Lunch: Logged')).toHaveCount(0);
});

test('TC-FR2-37-AS-02', async ({ page, request }) => {
  const member = await paid(request, 'nocal');
  await signIn(page, member.email, password);
  await page.goto('/members/diary/');
  const html = await page.locator('main').innerText();
  expect(html).not.toMatch(/calorie|kcal/i);
});

test('TC-FR2-37-AS-03', async ({ page, request }) => {
  const member = await paid(request, 'ed');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, {
    kind: 'profile', conditions_answered: 'declared', conditions: ['eating-disorder'], confirmed: true,
  });
  await page.goto('/members/diary/');
  await expect(page.locator('#lld_portion')).toHaveCount(0);
  const nonce = await page.locator('input[name="lld_diary_nonce"]').first().inputValue();
  const refused = await page.request.post('/members/diary/', {
    form: { lld_diary_nonce: nonce, lld_meal: 'lunch', lld_description: 'Hidden portion note', lld_portion: 'large' },
    maxRedirects: 0,
  });
  expect(refused.status()).toBeGreaterThanOrEqual(400);
  await page.goto('/members/diary/');
  await expect(page.locator('main')).not.toContainText('Hidden portion note');
});

test('TC-FR2-37-AS-04', async ({ page, request }) => {
  const member = await paid(request, 'sum');
  await signIn(page, member.email, password);
  await page.goto('/members/diary/');
  const nonce = await page.locator('input[name="lld_diary_nonce"]').first().inputValue();
  const plan = [
    ['2026-06-01', 'breakfast', '1'], ['2026-06-01', 'lunch', ''],
    ['2026-06-02', 'breakfast', '1'], ['2026-06-02', 'lunch', ''],
    ['2026-06-03', 'breakfast', '1'], ['2026-06-03', 'lunch', ''],
    ['2026-06-04', 'breakfast', '1'], ['2026-06-04', 'lunch', ''],
    ['2026-06-05', 'breakfast', '1'], ['2026-06-05', 'lunch', '1'],
    ['2026-06-06', 'breakfast', '1'], ['2026-06-06', 'lunch', '1'],
    ['2026-06-07', 'breakfast', '1'], ['2026-06-07', 'lunch', ''],
  ];
  for (const [day, meal, veg] of plan) {
    await clock(request, member.email, `${day} 12:00:00`);
    const form = { lld_diary_nonce: nonce, lld_meal: meal, lld_description: meal };
    if (veg) form.lld_vegetables = veg;
    const saved = await page.request.post('/members/diary/', { form, maxRedirects: 0 });
    expect(saved.status()).toBeLessThan(400);
  }
  await page.goto('/members/diary/');
  await expect(page.locator('[data-lld-summary]')).toHaveText('Vegetables at 9 of 14 meals');
});

test('TC-FR2-37-AS-05', async ({ page, request }) => {
  const email = emailFor('reopen');
  await createMember(request, { email, password, first_name: 'Sam', role: 'member', consents: ['terms', 'health'], access_state: 'free' });
  await clock(request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await startNamed(page, 'Start Healthy Weight');
  await page.goto('/members/diary/');
  await page.locator('#lld_description').fill('First week soup');
  await page.getByRole('button', { name: 'Save meal' }).click();
  await clock(request, email, '2026-05-08 12:00:00');
  await page.goto('/members/diary/');
  await expect(page.getByText('This diary is read-only.')).toBeVisible();
  await expect(page.getByText('First week soup')).toBeVisible();
  await startNamed(page, 'Start Fitness and Strength');
  await page.goto('/members/diary/');
  await expect(page.getByText('You can add meals.')).toBeVisible();
  await expect(page.getByText('First week soup')).toBeVisible();
});

test('TC-FR2-37-AS-06', async ({ page, request }) => {
  const sentinel = `DIARY${stamp()}`;
  const member = await paid(request, 'leak');
  await signIn(page, member.email, password);
  await page.goto('/members/diary/');
  await page.locator('#lld_description').fill(sentinel);
  await page.getByRole('button', { name: 'Save meal' }).click();
  // the day's water, hunger and energy are saved to the daily entry (Data Model C13)
  await page.locator('#lld_water').fill('6');
  await page.locator('#lld_hunger').fill('2');
  await page.locator('#lld_energy').fill('4');
  await page.getByRole('button', { name: 'Save today' }).click();
  await expect(page.locator('[data-lld-day-extras]')).toHaveText('Water: 6 glasses. Hunger: 2 of 5. Energy: 4 of 5.');
  await page.locator('#lld_water').fill('7');
  await page.getByRole('button', { name: 'Save today' }).click();
  await expect(page.locator('[data-lld-day-extras]')).toHaveText('Water: 7 glasses. Hunger: 2 of 5. Energy: 4 of 5.');
  const dayNonce = await page.locator('input[name="lld_diary_nonce"]').first().inputValue();
  await page.request.post('/members/diary/', { form: { lld_diary_nonce: dayNonce, lld_day_save: '1', lld_hunger: '9', lld_energy: '0' }, maxRedirects: 0 });
  await page.reload();
  await expect(page.locator('[data-lld-day-extras]')).toHaveText('Water: 7 glasses. Hunger: 2 of 5. Energy: 4 of 5.');
  const days = (await (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email: member.email } })).json()).days;
  expect(days).toHaveLength(1);
  expect(days[0]).toMatchObject({ water_glasses: '7', hunger: '2', energy: '4' });
  await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  const search = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(sentinel)}`);
  if (search.ok) {
    const found = await search.json();
    expect(found.messages || []).toEqual([]);
  }
  await page.goto('/members/data/');
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download all my data' }).click();
  const download = await downloadPromise;
  const packed = unzip(readFileSync(await download.path()));
  expect(packed['data.json'].toString()).toContain(sentinel);
  expect(JSON.parse(packed['data.json'].toString()).diary_day).toEqual([expect.objectContaining({ water_glasses: '7', hunger: '2', energy: '4' })]);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  const scan = await request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: member.id, scope: 'account', sentinel } });
  expect((await scan.json()).problems).toEqual([]);
});

test('TC-FR2-37-AS-07', async ({ page, request }) => {
  const member = await paid(request, 'photo');
  const other = await paid(request, 'other');
  const ownerEmail = emailFor('owner');
  await createMember(request, { email: ownerEmail, password, first_name: 'Owner', role: 'owner_admin' });
  const rows = async () => (await request.post('/wp-json/lld-members/v1/test/premium', { data: { email: member.email } })).json();
  await signIn(page, member.email, password);
  await page.goto('/members/diary/');
  await page.locator('#lld_photo').setInputFiles(fileOf(`gif-${stamp()}.gif`, Buffer.from('GIF89a')));
  await page.getByRole('button', { name: 'Save meal' }).click();
  await expect(page.getByText('This file is too large or not a supported type')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles(fileOf(`big-${stamp()}.jpg`, Buffer.alloc(11 * 1024 * 1024, 1)));
  await page.getByRole('button', { name: 'Save meal' }).click();
  await expect(page.getByText('This file is too large or not a supported type')).toBeVisible();

  // a JPEG with GPS location in its EXIF data is stored without it
  const located = gpsJpeg();
  expect(hasGps(located)).toBe(true);
  expect(located.includes(Buffer.from(GPS_MARK))).toBe(true);
  await page.locator('#lld_photo').setInputFiles(fileOf(`gps-${stamp()}.jpg`, located));
  await page.getByRole('button', { name: 'Save meal' }).click();
  const href = await page.getByRole('link', { name: 'Photo' }).getAttribute('href');
  const own = await page.request.get(href);
  expect(own.status()).toBe(200);
  expect(own.headers()['content-type'] || '').toContain('image/jpeg');
  const stored = await own.body();
  expect(stored.subarray(0, 2)).toEqual(Buffer.from([0xff, 0xd8]));
  expect(hasGps(stored)).toBe(false);
  expect(stored.includes(Buffer.from(GPS_MARK))).toBe(false);
  expect(stored).not.toContain(Buffer.from('GPS'));
  const first = (await rows()).photos;
  expect(first).toHaveLength(1);

  // replacing the photo deletes the old file
  await page.locator('#lld_photo').setInputFiles(fileOf(`ok-${stamp()}.png`, png));
  await page.getByRole('button', { name: 'Save meal' }).click();
  await expect(page.getByRole('link', { name: 'Photo' })).toHaveCount(1);
  const second = (await rows()).photos;
  expect(second).toHaveLength(1);
  expect(second[0]).not.toBe(first[0]);

  // only its member: not another member, not an administrator, not signed out
  await signIn(page, other.email, password);
  const refused = await page.request.get(href, { maxRedirects: 0 });
  expect(refused.status()).toBeGreaterThanOrEqual(400);
  await signIn(page, ownerEmail, password);
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  await page.locator('#lld_code').fill(await mailCode(ownerEmail));
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.waitForURL((url) => !url.pathname.includes('/members/sign-in'));
  const admin = await page.request.get(href, { maxRedirects: 0 });
  expect(admin.status()).toBeGreaterThanOrEqual(400);
  expect(admin.headers()['content-type'] || '').not.toContain('image/');
  await page.context().clearCookies();
  const signedOut = await page.request.get(href, { maxRedirects: 0 });
  expect(signedOut.status()).not.toBe(200);

  // undo removes the entry and deletes its photo file
  await signIn(page, member.email, password);
  await page.goto('/members/diary/');
  await page.locator('[data-lld-entry]').getByRole('button', { name: 'Undo' }).click();
  await expect(page.getByRole('link', { name: 'Photo' })).toHaveCount(0);
  expect((await rows()).photos).toEqual([]);
  expect((await page.request.get(href)).status()).toBe(404);
});
