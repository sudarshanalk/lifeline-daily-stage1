import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, editBody, openAdmin, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const banned = /diagnosis|diagnose|obese|obesity|hypertension|diabetic|abnormal|blood glucose/i;

function stamp() {
  return randomBytes(4).toString('hex');
}

function emailFor(label) {
  return `${label}-${stamp()}@test.lifelinedaily.invalid`;
}

async function fixture(request, kind, mark, extra = {}) {
  const res = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind, stamp: mark, ...extra } });
  if (!res.ok()) throw new Error(`fixture ${kind} ${res.status()} ${await res.text()}`);
  return res.json();
}

async function clock(request, email, utc) {
  const res = await request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc } });
  expect(res.ok()).toBeTruthy();
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

async function approveNewest(page, title) {
  await openAdmin(page, '/wp-admin/admin.php?page=lld-approvals');
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check', { force: true });
  // A real click: in WebKit a forced click on this page can report success without submitting the form.
  await page.locator('tr', { hasText: title }).first().getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Approved. Members can now see this version.')).toBeVisible();
}

async function reports(request) {
  const res = await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(res.ok()).toBeTruthy();
}

async function mailFor(email) {
  const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const found = (list.messages || []).filter((message) => JSON.stringify(message).includes(email));
  const full = [];
  for (const message of found) {
    const id = message.ID || message.Id || message.id;
    full.push(await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json()));
  }
  return full;
}

function bodyOf(message) {
  return `${message.Subject || ''}\n${message.Text || ''}`;
}

async function tick(request) {
  // The daily reminder is sent by the 5-minute scheduled event only: this checks the schedule, then runs that event.
  const res = await request.post('/wp-json/lld-members/v1/test/programme', { data: { tick: true } });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body).toEqual({ scheduled: true, schedule: 'lld_five_minutes', interval: 300 });
}

async function state(request, email) {
  const res = await request.post('/wp-json/lld-members/v1/test/programme', { data: { email, state: true } });
  expect(res.ok()).toBeTruthy();
  return res.json();
}

async function seed(request, email, week, done) {
  const res = await request.post('/wp-json/lld-members/v1/test/programme', { data: { email, seed: { week, done } } });
  expect(res.ok()).toBeTruthy();
  return res.json();
}

async function trackNonce(page) {
  await page.goto('/members/tracks/');
  return page.locator('input[name="lld_track_nonce"]').first().inputValue();
}

async function postTrack(page, nonce, slug, start = 'today') {
  const res = await page.request.post('/members/tracks/', { form: { lld_track_nonce: nonce, lld_track: slug, lld_start: start }, maxRedirects: 0 });
  expect([200, 302, 303]).toContain(res.status());
  return res.status() === 200 ? res.text() : '';
}

async function restNonce(page) {
  await page.goto('/members/home/');
  return page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
}

async function setZone(page, zone) {
  await page.goto('/members/settings/');
  await page.locator('#lld_timezone').fill(zone);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
}

async function planTitles(page) {
  await page.goto('/members/plan/');
  return page.locator('.lld-action h2').allInnerTexts();
}

test('TC-FR2-08-AS-02', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const title = made.items[7].title;
  const freeEmail = emailFor('dayfree');
  const paidEmail = emailFor('daypaid');
  await createMember(page.request, { email: freeEmail, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'free' });
  await createMember(page.request, { email: paidEmail, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  for (const email of [freeEmail, paidEmail]) {
    await clock(page.request, email, '2026-08-01 12:00:00');
    await signIn(page, email, password);
    await startSlug(page, made.track);
    await clock(page.request, email, '2026-08-08 12:00:00');
  }
  await signIn(page, freeEmail, password);
  await page.goto('/members/track/?day=8');
  await expect(page.getByRole('link', { name: 'Upgrade' })).toBeVisible();
  await expect(page.locator('main')).not.toContainText(title);
  // No other route opens day 8 for a free member: the library link, its download, or a posted tick.
  await page.goto(`/members/library/${made.items[7].id}/`);
  await expect(page.getByText('Part of Premium', { exact: true }).first()).toBeVisible();
  await expect(page.locator('main')).not.toContainText(title);
  const download = await page.request.get(`/members/library/${made.items[7].id}/?download=1`);
  expect(download.status()).toBe(403);
  expect(await download.text()).not.toContain(title);
  await page.goto('/members/track/?day=7');
  const markNonce = await page.locator('input[name="lld_mark_nonce"]').first().inputValue();
  const posted = await page.request.post('/members/track/?day=8', { form: { lld_mark_nonce: markNonce, lld_day: '8', lld_result: 'done', lld_item: String(made.items[7].id) }, maxRedirects: 0 });
  expect(posted.status()).toBe(403);
  expect(await posted.text()).not.toContain(title);
  expect((await state(page.request, freeEmail)).results.filter((row) => Number(row.programme_day) === 8)).toHaveLength(0);
  await signIn(page, paidEmail, password);
  await page.goto('/members/track/?day=8');
  await expect(page.getByRole('heading', { name: title })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Upgrade' })).toHaveCount(0);
  await page.goto(`/members/library/${made.items[7].id}/`);
  await expect(page.locator('main')).toContainText(title);
});

test('TC-FR2-08-AS-02 a Premium member cannot open or tick a future day by a direct link', async ({ page }) => {
  const made = await fixture(page.request, 'days', stamp());
  const email = emailFor('future');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-08-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await clock(page.request, email, '2026-08-03 12:00:00');
  await page.goto('/members/track/?day=3');
  await expect(page.getByRole('heading', { name: made.items[2].title })).toBeVisible();
  const markNonce = await page.locator('input[name="lld_mark_nonce"]').first().inputValue();
  for (const day of [4, 8, 20]) {
    await page.goto(`/members/track/?day=${day}`);
    await expect(page.getByText(new RegExp(`Day ${day} opens on`))).toBeVisible();
    for (const item of made.items) await expect(page.locator('main')).not.toContainText(item.title);
    await expect(page.getByText('Part of Premium')).toHaveCount(0);
  }
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('Day 8 opens on Saturday 8 August.')).toBeVisible();
  const posted = await page.request.post('/members/track/?day=8', { form: { lld_mark_nonce: markNonce, lld_day: '8', lld_result: 'done', lld_item: String(made.items[7].id) } });
  expect(await posted.text()).toContain('This day has not opened yet.');
  expect((await state(page.request, email)).results).toHaveLength(0);
  await clock(page.request, email, '2026-08-08 12:00:00');
  await page.goto('/members/track/?day=8');
  await expect(page.getByRole('heading', { name: made.items[7].title })).toBeVisible();
});

test('TC-FR2-08-AS-03', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('editlive');
  const updated = `Updated instructions ${mark}`;
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-08-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await asOwner(page);
  await editBody(page, made.items[0].id, updated);
  await approveNewest(page, made.items[0].title);
  await signIn(page, email, password);
  await page.goto('/members/track/?day=1');
  await expect(page.locator('main')).toContainText(updated);
});

test('TC-FR2-08-AS-04', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'draft', mark);
  const email = emailFor('draft');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  for (const path of ['/members/home/', '/members/plan/', '/members/suggestions/', '/members/track/']) {
    await page.goto(path);
    await expect(page.locator('main')).not.toContainText(made.title);
  }
  const download = await page.request.get(`/members/library/${made.id}/?download=1`);
  expect(download.status()).toBeGreaterThanOrEqual(400);
  expect(await download.text()).not.toContain(made.title);
});

test('TC-FR2-08-AS-05', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('version');
  const updated = `Replacement instructions ${mark}`;
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-08-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await page.goto('/members/track/?day=1');
  await expect(page.locator('main')).toContainText(made.items[0].body);
  await asOwner(page);
  await editBody(page, made.items[0].id, updated);
  await signIn(page, email, password);
  await page.goto('/members/track/?day=1');
  await expect(page.locator('main')).toContainText(made.items[0].body);
  await expect(page.locator('main')).not.toContainText(updated);
  await asOwner(page);
  await approveNewest(page, made.items[0].title);
  await signIn(page, email, password);
  await page.goto('/members/track/?day=1');
  await expect(page.locator('main')).toContainText(updated);
});

test('TC-FR2-09-AS-01', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('late');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-08-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await clock(page.request, email, '2026-08-03 12:00:00');
  await page.goto('/members/track/?day=1');
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await clock(page.request, email, '2026-08-04 12:00:00');
  await page.goto('/members/track/?day=1');
  await expect(page.getByText('This activity can no longer be marked.')).toBeVisible();
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('This activity can no longer be marked.')).toBeVisible();
  await expect(page.getByText('Saved')).toHaveCount(0);
});

test('TC-FR2-09-AS-02', async ({ page }) => {
  test.setTimeout(240000);
  // One member per track: a track can only be switched at the end of a week (T3 rule 5).
  const expectOnly = async (present, absent) => {
    await page.goto('/members/check-in/');
    for (const id of present) await expect(page.locator(id)).toBeVisible();
    for (const id of absent) await expect(page.locator(id)).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText(/blood glucose/i);
  };
  const fresh = async (label) => {
    const email = emailFor(label);
    await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
    await signIn(page, email, password);
    return email;
  };
  await fresh('measures-hw');
  await startSlug(page, 'healthy-weight');
  await expectOnly(['#lld_weight', '#lld_waist'], ['#lld_chair', '#lld_push', '#lld_activity', '#lld_strength', '#lld_sleep', '#lld_quality', '#lld_stress', '#lld_reflect_1']);
  await fresh('measures-bs');
  await startSlug(page, 'blood-sugar');
  await expectOnly(['#lld_weight', '#lld_waist', '#lld_activity'], ['#lld_chair', '#lld_push', '#lld_strength', '#lld_sleep', '#lld_quality', '#lld_stress', '#lld_reflect_1']);
  await fresh('measures-fs');
  await startSlug(page, 'fitness');
  await expectOnly(['#lld_chair', '#lld_push', '#lld_activity', '#lld_strength'], ['#lld_weight', '#lld_waist', '#lld_sleep', '#lld_quality', '#lld_stress', '#lld_reflect_1']);
  await fresh('measures-ss');
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="no"]').check();
  await page.locator('input[name="lld_panic"][value="no"]').check();
  await page.locator('input[name="lld_mood"][value="no"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expectOnly(['#lld_sleep', '#lld_quality', '#lld_stress', '#lld_reflect_1', '#lld_reflect_2', '#lld_reflect_3'], ['#lld_weight', '#lld_waist', '#lld_chair', '#lld_activity', '#lld_push', '#lld_strength']);
});

test('FR2-09 Sleep and Stress reflections are labelled personal reflection and stored with their question version (C10)', async ({ page }) => {
  const email = emailFor('reflect');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-08-01 12:00:00');
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  for (const name of ['lld_harm', 'lld_panic', 'lld_mood']) await page.locator(`input[name="${name}"][value="no"]`).check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.goto('/members/check-in/');
  const reflection = page.locator('fieldset.lld-reflection');
  await expect(reflection.locator('legend')).toHaveText('Personal reflection');
  await expect(reflection).toContainText('a personal reflection, not a validated measure');
  await expect(page.locator('#lld_reflect_1')).not.toHaveAttribute('inputmode', /.+/);
  await page.locator('#lld_sleep').fill('7.5');
  await page.locator('#lld_quality').fill('3');
  await page.locator('#lld_stress').fill('2');
  await page.locator('#lld_reflect_1').fill('A walk after dinner');
  await page.locator('#lld_reflect_3').fill('An earlier night');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  const saved = await state(page.request, email);
  expect(saved.reflections).toHaveLength(1);
  expect(saved.reflections[0].when_key).toBe('start');
  const answers = JSON.parse(saved.reflections[0].answers);
  expect(answers.map((row) => row.answer)).toEqual(['A walk after dinner', 'An earlier night']);
  for (const row of answers) expect(row.question).toMatch(/^(w\d+v\d+|builtin-[123])$/);
  // Weeks 2 and 3 do not ask; week 4 asks again for the end reflection.
  await clock(page.request, email, '2026-08-08 12:00:00');
  await page.goto('/members/check-in/');
  await expect(page.locator('#lld_reflect_1')).toHaveCount(0);
  await clock(page.request, email, '2026-08-22 12:00:00');
  await page.goto('/members/check-in/');
  await expect(page.locator('#lld_reflect_1')).toBeVisible();
});

test('TC-FR2-09-AS-03', async ({ page }) => {
  const email = emailFor('bmi');
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  const cases = [
    [170, 70, 85],
    [180, 80, 108],
    [160, 64, 80],
    [150, 60, 90],
    [175, 70, 87.5],
    [165, 55, 99],
    [182, 81, 91],
    [190, 100, 114],
    [168, 62, 84],
    [172, 68, 86],
  ];
  expect(cases.some((row) => (row[2] / row[0]).toFixed(2) === '0.50')).toBeTruthy();
  expect(cases.some((row) => (row[2] / row[0]).toFixed(2) === '0.60')).toBeTruthy();
  await page.goto('/members/home/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  for (const [height, weight, waist] of cases) {
    const saved = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
      headers: { 'X-WP-Nonce': nonce },
      data: { kind: 'profile', confirmed: true, height_cm: height, weight_kg: weight, waist_cm: waist, conditions_answered: 'none' },
    });
    expect(saved.status()).toBe(200);
    await page.goto('/members/health/');
    const bmi = (weight / ((height / 100) ** 2)).toFixed(1);
    const ratio = (waist / height).toFixed(2);
    await expect(page.getByText(`BMI: ${bmi}`)).toBeVisible();
    await expect(page.getByText(`Waist-to-height: ${ratio}`)).toBeVisible();
  }
});

test('TC-FR2-09-AS-04', async ({ page }) => {
  const email = emailFor('chair');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, 'fitness');
  for (let n = 0; n < 3; n += 1) {
    await page.goto('/members/check-in/');
    const html = await page.locator('main').innerHTML();
    expect(html.indexOf('sturdy chair')).toBeGreaterThan(-1);
    expect(html.indexOf('sturdy chair')).toBeLessThan(html.indexOf('id="lld_chair"'));
    await expect(page.getByText('Skip this test if you have balance problems')).toBeVisible();
  }
  // A skipped test is stored as skipped for safety (C9), not as a zero.
  await page.locator('input[name="lld_skip_chair"]').check();
  await page.locator('#lld_push').fill('12');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  const saved = await state(page.request, email);
  expect(saved.check_ins).toHaveLength(1);
  expect(saved.check_ins[0].tests_skipped).toBe('chair');
  expect(saved.check_ins[0].chair_stands).toBeNull();
  expect(Number(saved.check_ins[0].push_ups)).toBe(12);
});

test('FR2-09 check-in values are range-checked before saving', async ({ page }) => {
  const email = emailFor('range');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, 'fitness');
  await page.goto('/members/check-in/');
  await page.locator('#lld_strength').fill('99');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await expect(page.getByText('Strength sessions: this value is outside the range we can save.')).toBeVisible();
  await page.goto('/members/check-in/');
  await page.locator('#lld_push').fill('ten');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await expect(page.getByText('Push-ups: enter a number.')).toBeVisible();
  await page.goto('/members/check-in/');
  await page.locator('#lld_push').fill('180');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await expect(page.getByText(/Please check this value: Push-ups/)).toBeVisible();
  expect((await state(page.request, email)).check_ins).toHaveLength(0);
  await page.goto('/members/check-in/');
  await page.locator('#lld_push').fill('180');
  await page.getByRole('checkbox', { name: "I've checked these values" }).check();
  await page.getByRole('button', { name: 'Save check-in' }).click();
  expect(Number((await state(page.request, email)).check_ins[0].push_ups)).toBe(180);
});

test('TC-FR2-09-AS-05', async ({ page }) => {
  const email = emailFor('chart');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-08-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, 'healthy-weight');
  await page.goto('/members/check-in/');
  await page.locator('#lld_weight').fill('80');
  await page.locator('#lld_waist').fill('90');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await expect(page.getByText('This is your starting point.')).toBeVisible();
  await clock(page.request, email, '2026-08-08 12:00:00');
  await page.goto('/members/check-in/');
  await page.locator('#lld_weight').fill('78');
  await page.locator('#lld_waist').fill('88');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  await expect(page.getByText('Change since week 1.')).toBeVisible();
  // Each measure has its own chart of the change since week 1, with the same numbers in a table.
  await expect(page.locator('figcaption', { hasText: 'Weight down 2 kg since week 1' })).toBeVisible();
  await expect(page.locator('figcaption', { hasText: 'Waist down 2 cm since week 1' })).toBeVisible();
  const weight = page.locator('figure.lld-chart', { hasText: 'Weight down 2 kg since week 1' });
  await expect(weight.getByRole('button', { name: 'Show as table' })).toBeVisible();
  await expect(weight.locator('tbody tr').nth(0).locator('td')).toHaveText(['Week 1', '80 kg', '0']);
  await expect(weight.locator('tbody tr').nth(1).locator('td')).toHaveText(['Week 2', '78 kg', '-2']);
  await expect(page.getByText('Guidance: aim to keep your waist under half your height.')).toBeVisible();
  expect(await page.locator('main').innerText()).not.toMatch(banned);
});

test('TC-FR2-09-AS-06', async ({ page }) => {
  const email = emailFor('glucose');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  const catalogue = await page.request.post('/wp-json/lld-members/v1/test/programme', { data: { catalogue: true, email } });
  expect(catalogue.ok()).toBeTruthy();
  expect(JSON.stringify(await catalogue.json())).not.toMatch(/blood glucose/i);
  for (const path of ['/members/home/', '/members/check-in/', '/members/health/', '/members/plan/', '/members/track/']) {
    await page.goto(path);
    await expect(page.locator('body')).not.toContainText(/blood glucose/i);
  }
});

test('TC-FR2-09-AS-07', async ({ page }) => {
  const email = emailFor('nocheck');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms'] });
  await signIn(page, email, password);
  await page.goto('/members/check-in/');
  await expect(page.getByText('Health profile')).toBeVisible();
  await expect(page.locator('#lld_weight')).toHaveCount(0);
  const posted = await page.request.post('/members/check-in/', { form: { lld_weight: '73.7' }, maxRedirects: 0 });
  expect(posted.status()).toBe(403);
  await page.goto('/members/check-in/');
  await expect(page.locator('#lld_weight')).toHaveCount(0);
});

test('TC-FR2-10-AS-01', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'actions', mark);
  const res = await page.request.post('/wp-json/lld-members/v1/test/programme', {
    data: { track: made.track, pcts: [0, 10, 49, 50, 60, 80, 81, 90, 100, 25] },
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.rows).toHaveLength(10);
  for (const row of body.rows) {
    expect(row.count).toBeGreaterThanOrEqual(3);
    expect(row.count).toBeLessThanOrEqual(5);
  }
});

test('TC-FR2-10-AS-02', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'actions', mark);
  const res = await page.request.post('/wp-json/lld-members/v1/test/programme', {
    data: { track: made.track, pcts: [49, 50, 80, 81] },
  });
  const body = await res.json();
  expect(body.rows.map((row) => row.level)).toEqual(['easier', 'same', 'same', 'stretch']);
  const same = body.rows[1].count;
  expect(body.rows[3].count).toBe(same);
  expect(body.rows[0].titles.some((title) => title.startsWith('Easier'))).toBeTruthy();
  expect(body.rows[3].titles.some((title) => title.startsWith('Stretch'))).toBeTruthy();
});

test('TC-FR2-10-AS-03', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'actions', mark);
  const email = emailFor('sameplan');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, made.track);
  const before = await page.request.post('/wp-json/lld-members/v1/test/programme', { data: { email } });
  await page.goto('/members/check-in/');
  await page.getByRole('button', { name: 'Save check-in' }).click();
  const after = await page.request.post('/wp-json/lld-members/v1/test/programme', { data: { email } });
  expect(await after.json()).toEqual(await before.json());
});

test('TC-FR2-10-AS-01 every plan built from real completion has 3 to 5 actions', async ({ page }) => {
  test.setTimeout(300000);
  const made = await fixture(page.request, 'weeks', stamp(), { week1: 20 });
  const email = emailFor('tenweeks');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-07-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  const plans = [];
  for (const done of [0, 3, 9, 10, 14, 16, 17, 18, 20, 7]) {
    const seeded = await seed(page.request, email, 1, done);
    expect(seeded).toMatchObject({ done, available: 20 });
    const res = await page.request.post('/wp-json/lld-members/v1/test/programme', { data: { email, plan_week: 2 } });
    const plan = await res.json();
    expect(plan.completion).toMatchObject({ done, available: 20 });
    plans.push(plan);
  }
  expect(plans).toHaveLength(10);
  for (const plan of plans) {
    expect(plan.titles.length).toBeGreaterThanOrEqual(3);
    expect(plan.titles.length).toBeLessThanOrEqual(5);
  }
  expect(plans.map((plan) => plan.level)).toEqual(['easier', 'easier', 'easier', 'same', 'same', 'same', 'stretch', 'stretch', 'stretch', 'easier']);
});

test('TC-FR2-10-AS-02 the stored week-2 plan uses last week\'s completion: 49 %, 50 %, 80 % and 81 %', async ({ page }) => {
  test.setTimeout(600000);
  const made = await fixture(page.request, 'weeks', stamp(), { week1: 100 });
  const seen = [];
  for (const done of [49, 50, 80, 81]) {
    const email = emailFor(`level${done}`);
    await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
    await clock(page.request, email, '2026-07-01 12:00:00');
    await signIn(page, email, password);
    await startSlug(page, made.track);
    await page.goto('/members/plan/');
    await expect(page.getByText('This first plan starts at the usual level.', { exact: false })).toBeVisible();
    expect(await seed(page.request, email, 1, done)).toMatchObject({ done, available: 100, pct: done });
    await clock(page.request, email, '2026-07-08 12:00:00');
    const titles = await planTitles(page);
    await expect(page.getByText(`Last week you did ${done} of 100 activities`, { exact: false })).toBeVisible();
    const stored = (await state(page.request, email)).plans.find((plan) => Number(plan.week_number) === 2);
    expect(stored).toMatchObject({ level: done < 50 ? 'easier' : done > 80 ? 'stretch' : 'same', rule_version: '1' });
    expect(Number(stored.completion_done)).toBe(done);
    expect(Number(stored.completion_available)).toBe(100);
    expect(Number(stored.completion_pct)).toBe(done);
    seen.push({ done, titles, level: stored.level });
  }
  expect(seen.map((row) => row.level)).toEqual(['easier', 'same', 'same', 'stretch']);
  expect(seen[0].titles.every((title) => title.startsWith('Easier'))).toBeTruthy();
  expect(seen[3].titles).toHaveLength(seen[1].titles.length);
  expect(seen[3].titles.filter((title) => title.startsWith('Stretch'))).toHaveLength(1);
});

test('TC-FR2-10-AS-03 a missing check-in does not change the stored plan', async ({ page }) => {
  test.setTimeout(300000);
  const made = await fixture(page.request, 'weeks', stamp(), { week1: 10 });
  const plans = [];
  for (const checkIn of [true, false]) {
    const email = emailFor(checkIn ? 'withcheck' : 'nocheck');
    await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
    await clock(page.request, email, '2026-07-01 12:00:00');
    await signIn(page, email, password);
    await startSlug(page, made.track);
    await seed(page.request, email, 1, 9);
    if (checkIn) {
      await page.goto('/members/check-in/');
      await page.getByRole('button', { name: 'Save check-in' }).click();
      expect((await state(page.request, email)).check_ins).toHaveLength(1);
    }
    await clock(page.request, email, '2026-07-08 12:00:00');
    const titles = await planTitles(page);
    const stored = (await state(page.request, email)).plans.find((plan) => Number(plan.week_number) === 2);
    plans.push({ titles, level: stored.level });
  }
  expect(plans[0].level).toBe('stretch');
  expect(plans[0]).toEqual(plans[1]);
});

test('TC-FR2-10-AS-04', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'actions', mark);
  const email = emailFor('swap');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, made.track);
  const before = await planTitles(page);
  await page.getByRole('button', { name: 'Swap my plan' }).click();
  await expect(page.getByRole('button', { name: 'Swap my plan' })).toBeVisible();
  const after = await page.locator('.lld-action h2').allInnerTexts();
  expect(after).not.toEqual(before);
  expect(after).toHaveLength(before.length);
  // The swapped plan is fixed for the week.
  expect(await planTitles(page)).toEqual(after);
  await page.getByRole('button', { name: 'Swap my plan' }).click();
  await expect(page.getByText('You can swap again next week.')).toBeVisible();
  expect(await planTitles(page)).toEqual(after);
  expect(Number((await state(page.request, email)).plans[0].swapped)).toBe(1);
});

test('TC-FR2-10-AS-05', async ({ page }) => {
  const email = emailFor('noplan');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'free' });
  await signIn(page, email, password);
  await page.goto('/members/plan/');
  await expect(page.locator('.lld-action')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Upgrade' })).toBeVisible();
  const posted = await page.request.post('/members/plan/', { form: { lld_swap: '1' }, maxRedirects: 0 });
  expect(posted.status()).toBe(403);
});

test('TC-FR2-10-AS-06', async ({ page }) => {
  const mark = stamp();
  const thin = await fixture(page.request, 'actions', mark, { mode: 'thin' });
  const empty = await fixture(page.request, 'actions', `${mark}x`, { mode: 'empty' });
  const email = emailFor('few');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, thin.track);
  await page.goto('/members/plan/');
  await expect(page.locator('.lld-action')).toHaveCount(2);
  await expect(page.getByText('Fewer than 3 safe actions are available.')).toBeVisible();
  // With only 2 safe actions there is nothing to swap in, and the swap is not used up.
  await page.getByRole('button', { name: 'Swap my plan' }).click();
  await expect(page.getByText('There is no other safe action to swap in this week.')).toBeVisible();
  expect(Number((await state(page.request, email)).plans[0].swapped)).toBe(0);
  // A second member, because a track can only be switched at the end of a week (T3 rule 5).
  const other = emailFor('none');
  const created = await createMember(page.request, { email: other, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, other, password);
  const nonce = await restNonce(page);
  await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify(['pregnancy']) },
  });
  await startSlug(page, empty.track);
  await page.goto('/members/plan/');
  await expect(page.getByText('No safe actions are available this week.')).toBeVisible();
  await expect(page.locator('main')).not.toContainText(empty.items[0].title);
});

test('TC-FR2-25-AS-05 a stored weekly plan drops actions the newly saved condition excludes', async ({ page }) => {
  const made = await fixture(page.request, 'actions', stamp(), { mode: 'empty' });
  const email = emailFor('planpreg');
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  // With no conditions declared nothing is excluded, so the stored plan holds the three weight-loss actions.
  let res = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    headers: { 'X-WP-Nonce': await restNonce(page) },
    data: { kind: 'profile', confirmed: true, conditions_answered: 'none' },
  });
  expect(res.status()).toBe(200);
  await startSlug(page, made.track);
  expect((await planTitles(page)).sort()).toEqual(made.items.map((item) => item.title).sort());
  res = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    headers: { 'X-WP-Nonce': await restNonce(page) },
    data: { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify(['pregnancy']) },
  });
  expect(res.status()).toBe(200);
  await page.goto('/members/plan/');
  await expect(page.getByText('No safe actions are available this week.')).toBeVisible();
  for (const item of made.items) await expect(page.locator('main')).not.toContainText(item.title);
  await page.goto('/members/home/');
  for (const item of made.items) await expect(page.locator('[data-lld-section="plan"]')).not.toContainText(item.title);
});

test('TC-FR2-11-AS-01', async ({ page }) => {
  test.setTimeout(240000);
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('reviewday');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await clock(page.request, email, '2026-05-06 12:00:00');
  await page.goto('/members/review/');
  await expect(page.getByText('Your review is not ready yet.')).toBeVisible();
  await clock(page.request, email, '2026-05-07 12:00:00');
  await page.goto('/members/review/');
  await expect(page.getByRole('heading', { name: 'Week 1 review' })).toBeVisible();
  await expect(page.getByText('You showed up. That is enough for this week.')).toBeVisible();
  await expect(page.getByText('Your review is not ready yet.')).toHaveCount(0);
  // Every later week: not on day 6 of the week, then on day 7.
  for (const [week, before, on] of [[2, '2026-05-13', '2026-05-14'], [3, '2026-05-20', '2026-05-21'], [4, '2026-05-27', '2026-05-28']]) {
    await clock(page.request, email, `${before} 12:00:00`);
    await page.goto('/members/review/');
    await expect(page.getByRole('heading', { name: `Week ${week} review` })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: `Week ${week - 1} review` })).toBeVisible();
    await clock(page.request, email, `${on} 12:00:00`);
    await page.goto('/members/review/');
    await expect(page.getByRole('heading', { name: `Week ${week} review` })).toBeVisible();
    await expect(page.getByText('You showed up. That is enough for this week.')).toBeVisible();
  }
  const reviews = (await state(page.request, email)).reviews;
  expect(reviews.map((row) => [Number(row.week_number), row.ready_on])).toEqual([[1, '2026-05-07'], [2, '2026-05-14'], [3, '2026-05-21'], [4, '2026-05-28']]);
});

test('TC-FR2-11-AS-01 the review counts the week it covers, and C12 is written for every member', async ({ page }) => {
  test.setTimeout(180000);
  const made = await fixture(page.request, 'days', stamp());
  const email = emailFor('weekcount');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await page.goto('/members/track/?day=1');
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await clock(page.request, email, '2026-05-07 12:00:00');
  await page.goto('/members/review/');
  await expect(page.getByText('You completed 1 of 7 activities this week.')).toBeVisible();
  await expect(page.getByText('No check-in this week.')).toBeVisible();
  await clock(page.request, email, '2026-05-14 12:00:00');
  await page.goto('/members/review/');
  await expect(page.getByText('You completed 0 of 1 activities this week.')).toBeVisible();
  await expect(page.getByText('Some weeks are busier than others. Next week is a fresh start.')).toBeVisible();
  // A free member who never opens the review, with no review emails chosen, still has the C12 record.
  const free = emailFor('freereview');
  await createMember(page.request, { email: free, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'free' });
  await clock(page.request, free, '2026-05-01 12:00:00');
  await signIn(page, free, password);
  await startSlug(page, made.track);
  await clock(page.request, free, '2026-05-07 12:00:00');
  await reports(page.request);
  const reviews = (await state(page.request, free)).reviews;
  expect(reviews.map((row) => [Number(row.week_number), row.ready_on])).toEqual([[1, '2026-05-07']]);
  expect((await mailFor(free)).filter((message) => (message.Subject || '').includes('weekly review'))).toHaveLength(0);
  await page.goto('/members/review/');
  await expect(page.getByText('Here is a short review of your week.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Upgrade' })).toBeVisible();
});

test('TC-FR2-11-AS-02', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('reviewmail');
  const sentinel = `SENTINELREVIEW${mark}`;
  await createMember(page.request, { email, password, first_name: sentinel, consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_display').fill(sentinel);
  await page.getByRole('checkbox', { name: 'Weekly review' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await startSlug(page, made.track);
  await clock(page.request, email, '2026-05-07 12:00:00');
  await reports(page.request);
  const messages = (await mailFor(email)).filter((message) => (message.Subject || '').includes('weekly review'));
  expect(messages.length).toBe(1);
  const text = bodyOf(messages[0]);
  expect(text).toContain('Your weekly review is ready.');
  expect(text).toContain('/members/review/');
  expect(text).not.toContain(sentinel);
  const stripped = text.replace(/https?:\/\/[^/]+/g, '');
  expect(stripped).not.toMatch(/\d/);
  expect(text).not.toMatch(/weight|waist|bmi|glucose|diagnosis|sleep|stress/i);
});

test('TC-FR2-11-AS-03', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('week4');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await clock(page.request, email, '2026-05-28 12:00:00');
  await page.goto('/members/review/');
  await expect(page.getByText("Try the next track or this month's challenge.")).toBeVisible();
});

test('TC-FR2-12-AS-01', async ({ page }) => {
  test.setTimeout(180000);
  const london = emailFor('london');
  const york = emailFor('york');
  await createMember(page.request, { email: london, password, first_name: 'Lou', consents: ['terms'] });
  await createMember(page.request, { email: york, password, first_name: 'Nia', consents: ['terms'] });
  await clock(page.request, london, '2026-01-14 12:00:00');
  await clock(page.request, york, '2026-01-14 12:00:00');
  await signIn(page, london, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_timezone').fill('Europe/London');
  await page.locator('#lld_reminder_time').fill('07:30');
  await page.getByRole('checkbox', { name: 'Daily reminder' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await signIn(page, york, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_timezone').fill('America/New_York');
  await page.locator('#lld_reminder_time').fill('07:30');
  await page.getByRole('checkbox', { name: 'Daily reminder' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  const count = async (email) => (await mailFor(email)).filter((message) => (message.Subject || '').includes("Today's activity is ready")).length;
  await clock(page.request, london, '2026-01-15 07:29:00');
  await tick(page.request);
  expect(await count(london)).toBe(0);
  await clock(page.request, london, '2026-01-15 07:30:00');
  // The daily job never sends the reminder; only the 5-minute event does.
  await reports(page.request);
  expect(await count(london)).toBe(0);
  await tick(page.request);
  expect(await count(london)).toBe(1);
  await clock(page.request, london, '2026-01-15 07:35:00');
  await tick(page.request);
  await clock(page.request, london, '2026-01-15 07:39:00');
  await tick(page.request);
  expect(await count(london)).toBe(1);
  await clock(page.request, london, '2026-01-16 07:41:00');
  await tick(page.request);
  expect(await count(london)).toBe(1);
  // 29 March 2026: the clocks go forward in London. 07:30 BST is 06:30 UTC.
  await clock(page.request, london, '2026-03-29 06:30:00');
  await tick(page.request);
  expect(await count(london)).toBe(2);
  // 25 October 2026: the clocks go back. 07:30 GMT is 07:30 UTC; 06:30 UTC is still too early.
  await clock(page.request, london, '2026-10-25 06:30:00');
  await tick(page.request);
  expect(await count(london)).toBe(2);
  await clock(page.request, london, '2026-10-25 07:30:00');
  await tick(page.request);
  expect(await count(london)).toBe(3);
  await clock(page.request, york, '2026-01-15 12:30:00');
  await tick(page.request);
  expect(await count(york)).toBe(1);
  await clock(page.request, york, '2026-03-08 11:30:00');
  await tick(page.request);
  expect(await count(york)).toBe(2);
  await clock(page.request, york, '2026-11-01 12:30:00');
  await tick(page.request);
  expect(await count(york)).toBe(3);
});

test('TC-FR2-12-AS-02', async ({ page }) => {
  const email = emailFor('nudge');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms'] });
  await clock(page.request, email, '2026-04-01 12:00:00');
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.getByRole('checkbox', { name: 'Nudges' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  const count = async () => (await mailFor(email)).filter((message) => (message.Subject || '').includes('Pick up where you left off')).length;
  await clock(page.request, email, '2026-04-05 12:00:00');
  await reports(page.request);
  expect(await count()).toBe(0);
  await clock(page.request, email, '2026-04-06 12:00:00');
  await reports(page.request);
  expect(await count()).toBe(1);
  await clock(page.request, email, '2026-04-11 12:00:00');
  await reports(page.request);
  expect(await count()).toBe(2);
  await clock(page.request, email, '2026-04-16 12:00:00');
  await reports(page.request);
  expect(await count()).toBe(2);
  await clock(page.request, email, '2026-04-16 13:00:00');
  await signIn(page, email, password);
  await clock(page.request, email, '2026-04-21 13:00:00');
  await reports(page.request);
  expect(await count()).toBe(3);
});

test('TC-FR2-12-AS-03', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const made = await fixture(page.request, 'days', mark);
  const email = emailFor('unsub');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-02-01 07:30:00');
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_timezone').fill('Europe/London');
  await page.locator('#lld_reminder_time').fill('07:30');
  for (const name of ['Welcome', 'Daily reminder', 'Weekly review', 'End of track', 'Nudges']) {
    await page.getByRole('checkbox', { name }).check();
  }
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await startSlug(page, made.track);
  await reports(page.request);
  await tick(page.request);
  await clock(page.request, email, '2026-02-06 12:00:00');
  await reports(page.request);
  await clock(page.request, email, '2026-02-07 12:00:00');
  await reports(page.request);
  await clock(page.request, email, '2026-02-11 12:00:00');
  await reports(page.request);
  await clock(page.request, email, '2026-02-28 12:00:00');
  await reports(page.request);
  const messages = await mailFor(email);
  const kinds = ['welcome', 'daily_reminder', 'weekly_review', 'nudges', 'end_of_track'];
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await page.context().clearCookies();
  // A code that does not exist, or one past its expiry date, never says "Unsubscribed".
  await page.goto('/members/unsubscribe/?code=nosuchcode&kind=welcome');
  await expect(page.getByRole('heading', { name: 'Link expired' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Unsubscribed' })).toHaveCount(0);
  const welcome = messages.find((item) => bodyOf(item).includes('kind=welcome'));
  const first = bodyOf(welcome).match(/\/members\/unsubscribe\/\?code=[a-z]+&kind=[a-z_]+/);
  // Programme emails carry the one-click unsubscribe headers (RFC 8058).
  const headers = await fetch(`${MAILPIT}/api/v1/message/${welcome.ID}/headers`).then((res) => res.json());
  expect(headers['List-Unsubscribe'][0]).toContain(first[0]);
  expect(headers['List-Unsubscribe-Post']).toEqual(['List-Unsubscribe=One-Click']);
  await clock(page.request, email, '2027-06-01 12:00:00');
  await page.goto(first[0]);
  await expect(page.getByRole('heading', { name: 'Link expired' })).toBeVisible();
  await clock(page.request, email, '2026-02-28 12:00:00');
  for (const kind of kinds) {
    const message = messages.find((item) => bodyOf(item).includes(`kind=${kind}`));
    expect(message, kind).toBeTruthy();
    const link = bodyOf(message).match(/\/members\/unsubscribe\/\?code=[a-z]+&kind=[a-z_]+/);
    expect(link, kind).toBeTruthy();
    // Opening the link asks first; the button (or a mail app's one-click POST) unsubscribes.
    await page.goto(link[0]);
    await expect(page.getByRole('heading', { name: 'Stop these emails?' })).toBeVisible();
    await page.getByRole('button', { name: 'Unsubscribe' }).click();
    await expect(page.getByRole('heading', { name: 'Unsubscribed' })).toBeVisible();
    await page.goto(link[0]);
    await expect(page.getByRole('heading', { name: 'Already unsubscribed' })).toBeVisible();
  }
});

test('TC-FR2-12-AS-04', async ({ page }) => {
  const mark = stamp();
  const email = emailFor('leak');
  const sentinel = `SENTINELEMAIL${mark}`;
  const created = await createMember(page.request, { email, password, first_name: sentinel, consents: ['terms', 'health'], health_consent: true, sentinel });
  await signIn(page, email, password);
  await page.goto('/members/home/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  const saved = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { kind: 'profile', confirmed: true, height_cm: 181, weight_kg: 77.7, conditions_answered: 'none' },
  });
  expect(saved.status()).toBe(200);
  const catalogue = await page.request.post('/wp-json/lld-members/v1/test/programme', { data: { email } });
  const packed = JSON.stringify(await catalogue.json());
  expect(packed).not.toContain(sentinel);
  expect(packed).not.toContain('77.7');
  expect(packed).not.toMatch(/weight|waist|bmi|glucose|diagnosis/i);
});

test('TC-FR2-12-AS-05', async ({ page }) => {
  test.setTimeout(180000);
  const email = emailFor('optout');
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/home/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { kind: 'profile', confirmed: true, height_cm: 170, conditions_answered: 'none' },
  });
  const before = (await mailFor(email)).length;
  const start = new Date('2026-09-01T12:00:00Z');
  for (let day = 0; day < 30; day += 1) {
    const when = new Date(start.getTime() + day * 86400000);
    const utc = when.toISOString().slice(0, 19).replace('T', ' ');
    await clock(page.request, email, utc);
    await reports(page.request);
  }
  expect((await mailFor(email)).length).toBe(before);
});

test('T3 rule 5: switching only at the end of a week, resuming where it stopped, finishing at day 28 and repeating', async ({ page }) => {
  test.setTimeout(300000);
  const a = await fixture(page.request, 'days', stamp());
  const b = await fixture(page.request, 'days', stamp());
  const email = emailFor('switch');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, email, '2026-09-01 12:00:00');
  await signIn(page, email, password);
  const nonce = await trackNonce(page);
  await postTrack(page, nonce, a.track);
  await clock(page.request, email, '2026-09-03 12:00:00');
  expect(await postTrack(page, nonce, b.track)).toContain('You can switch at the end of this week on Monday 7 September.');
  await page.goto('/members/tracks/');
  await expect(page.getByText('You can switch at the end of this week on Monday 7 September.').first()).toBeVisible();
  expect((await state(page.request, email)).enrolments).toHaveLength(1);
  await clock(page.request, email, '2026-09-07 12:00:00');
  await page.goto('/members/tracks/');
  await expect(page.getByText(/is kept, and you can resume it later/).first()).toBeVisible();
  await postTrack(page, nonce, b.track);
  let saved = await state(page.request, email);
  expect(saved.enrolments).toHaveLength(2);
  expect(saved.enrolments[0]).toMatchObject({ track: a.track, status: 'set_aside', attempt: 1, day: 7 });
  expect(saved.enrolments[0].periods.map((p) => [p.first, p.last])).toEqual([['2026-09-01', '2026-09-07']]);
  expect(saved.enrolments[1]).toMatchObject({ track: b.track, status: 'active', day: 0 });
  expect(saved.enrolments[1].periods.map((p) => [p.first, p.last])).toEqual([['2026-09-08', '']]);
  // Day 7 of the track set aside today can still be ticked.
  await page.goto(`/members/track/?day=7&enrolment=${saved.enrolments[0].id}`);
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await clock(page.request, email, '2026-09-14 12:00:00');
  await page.goto('/members/tracks/');
  await expect(page.getByText(/is kept, and you can resume it later/).first()).toBeVisible();
  await postTrack(page, nonce, a.track);
  saved = await state(page.request, email);
  expect(saved.enrolments[0]).toMatchObject({ status: 'active', attempt: 1 });
  expect(saved.enrolments[0].periods.map((p) => [p.first, p.last])).toEqual([['2026-09-01', '2026-09-07'], ['2026-09-15', '']]);
  expect(saved.enrolments[1]).toMatchObject({ status: 'set_aside', day: 7 });
  await clock(page.request, email, '2026-09-15 12:00:00');
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('Welcome back, day 8.')).toBeVisible();
  await expect(page.getByRole('heading', { name: a.items[7].title })).toBeVisible();
  // Day 28 finishes the track on that local date, whatever was skipped.
  await clock(page.request, email, '2026-10-05 12:00:00');
  saved = await state(page.request, email);
  expect(saved.enrolments[0]).toMatchObject({ status: 'finished', finished_on: '2026-10-05', day: 28 });
  expect(saved.enrolments[0].periods.at(-1).last).toBe('2026-10-05');
  await page.goto('/members/track/');
  await expect(page.getByText('Finished on Monday 5 October.')).toBeVisible();
  await clock(page.request, email, '2026-10-06 12:00:00');
  await postTrack(page, nonce, a.track);
  saved = await state(page.request, email);
  expect(saved.enrolments).toHaveLength(3);
  expect(saved.enrolments[2]).toMatchObject({ track: a.track, attempt: 2, status: 'active', day: 1 });
  expect(saved.enrolments[0]).toMatchObject({ status: 'finished', attempt: 1 });
});

test('Section 8.3: the free week is once per track; a free member can move to another track at once', async ({ page }) => {
  test.setTimeout(240000);
  const a = await fixture(page.request, 'days', stamp());
  const b = await fixture(page.request, 'days', stamp());
  const email = emailFor('freeweek');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'free' });
  await clock(page.request, email, '2026-09-01 12:00:00');
  await signIn(page, email, password);
  const nonce = await trackNonce(page);
  await postTrack(page, nonce, a.track);
  await clock(page.request, email, '2026-09-10 12:00:00');
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
  let saved = await state(page.request, email);
  expect(saved.enrolments[0]).toMatchObject({ status: 'active', free_week_used: 1, day: 10 });
  await page.goto('/members/home/');
  await expect(page.getByText('Week 1 is complete. Premium opens the rest of this track.')).toBeVisible();
  await expect(page.locator('[data-lld-section="today"]')).toContainText('Day 10 is part of Premium.');
  // Locked in week 2, the member need not wait for the end of the week to use another track's free week.
  await postTrack(page, nonce, b.track);
  saved = await state(page.request, email);
  expect(saved.enrolments[0]).toMatchObject({ status: 'set_aside', day: 9 });
  expect(saved.enrolments[0].periods.map((p) => [p.first, p.last])).toEqual([['2026-09-01', '2026-09-09']]);
  expect(saved.enrolments[1]).toMatchObject({ track: b.track, status: 'active', day: 1 });
  await clock(page.request, email, '2026-09-16 12:00:00');
  expect(await postTrack(page, nonce, a.track)).toContain('is used. Premium continues this track.');
  expect((await state(page.request, email)).enrolments[1]).toMatchObject({ status: 'active', day: 7 });
  // With Premium the same track carries on day by day.
  await createMember(page.request, { email, password, first_name: 'Sam', access_state: 'Premium' });
  await clock(page.request, email, '2026-09-18 12:00:00');
  await signIn(page, email, password);
  await page.goto('/members/track/?day=9');
  await expect(page.getByText('Part of Premium')).toHaveCount(0);
  expect((await state(page.request, email)).enrolments[1]).toMatchObject({ status: 'active', day: 9 });
});

test('TC-FR2-15-AS-05 programme side: Premium ended or refunded mid-week keeps the current week only, then check-ins and ticks are read-only', async ({ page }) => {
  test.setTimeout(300000);
  const made = await fixture(page.request, 'days', stamp());
  for (const [label, access] of [['ended', 'Premium ended'], ['refunded', 'Refunded'], ['pastdue', 'past_due']]) {
    const email = emailFor(label);
    await createMember(page.request, { email, password, first_name: 'Week', consents: ['terms', 'health'], access_state: 'Premium' });
    await clock(page.request, email, '2026-09-01 12:00:00');
    await signIn(page, email, password);
    await startSlug(page, made.track);
    await clock(page.request, email, '2026-09-03 12:00:00');
    await createMember(page.request, { email, password, first_name: 'Week', access_state: access, premium_ended_on: access === 'past_due' ? null : '2026-09-03' });
    await clock(page.request, email, '2026-09-05 12:00:00');
    await signIn(page, email, password);
    await page.goto('/members/track/?day=5');
    await expect(page.getByRole('heading', { name: made.items[4].title })).toBeVisible();
    await page.goto('/members/track/?day=8');
    await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText(made.items[7].title);
    if (access !== 'past_due') {
      await page.goto('/members/home/');
      await expect(page.getByText('Your current week stays open until Monday 7 September.')).toBeVisible();
    }
    await page.goto('/members/track/?day=5');
    const markNonce = await page.locator('input[name="lld_mark_nonce"]').first().inputValue();
    await page.goto('/members/check-in/');
    const checkNonce = await page.locator('input[name="lld_measures_nonce"]').first().inputValue();
    await page.getByRole('button', { name: 'Save check-in' }).click();
    expect((await state(page.request, email)).check_ins).toHaveLength(1);
    await clock(page.request, email, '2026-09-08 12:00:00');
    await page.goto('/members/track/?day=8');
    await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await page.goto('/members/track/?day=6');
    await expect(page.getByRole('heading', { name: made.items[5].title })).toBeVisible();
    const tick7 = await page.request.post('/members/track/?day=7', { form: { lld_mark_nonce: markNonce, lld_day: '7', lld_result: 'done', lld_item: String(made.items[6].id) } });
    expect(await tick7.text()).toContain('This activity can no longer be marked.');
    await page.request.post('/members/check-in/', { form: { lld_measures_nonce: checkNonce } });
    const saved = await state(page.request, email);
    expect(saved.check_ins).toHaveLength(1);
    expect(saved.results).toHaveLength(0);
    if (access === 'past_due') {
      // An unknown access state is not Premium: the free-week rule applies and day 8 stays locked.
      expect(saved.enrolments[0]).toMatchObject({ status: 'active', day: 8 });
    } else {
      expect(saved.enrolments[0]).toMatchObject({ status: 'stopped', stopped_why: 'premium_ended', last_open_day: '2026-09-07', day: 7 });
      expect(saved.enrolments[0].periods.map((p) => [p.first, p.last])).toEqual([['2026-09-01', '2026-09-07']]);
    }
  }
});

test('T3 rule 2: a new time zone applies from the next local date, eastward and westward', async ({ page }) => {
  test.setTimeout(240000);
  const made = await fixture(page.request, 'days', stamp());
  const east = emailFor('east');
  await createMember(page.request, { email: east, password, first_name: 'Eve', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, east, '2026-11-01 12:00:00');
  await signIn(page, east, password);
  await startSlug(page, made.track);
  // 20:00 in London on 2 November is 05:00 on 3 November in Tokyo.
  await clock(page.request, east, '2026-11-02 20:00:00');
  await setZone(page, 'Asia/Tokyo');
  let saved = await state(page.request, east);
  expect(saved).toMatchObject({ today: '2026-11-02', zone: 'Europe/London' });
  expect(saved.enrolments[0].day).toBe(2);
  await clock(page.request, east, '2026-11-02 23:30:00');
  expect((await state(page.request, east)).today).toBe('2026-11-02');
  await clock(page.request, east, '2026-11-03 00:30:00');
  saved = await state(page.request, east);
  expect(saved).toMatchObject({ today: '2026-11-03', zone: 'Asia/Tokyo' });
  expect(saved.enrolments[0].day).toBe(3);

  const west = emailFor('west');
  await createMember(page.request, { email: west, password, first_name: 'Wes', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, west, '2026-11-01 12:00:00');
  await signIn(page, west, password);
  await startSlug(page, made.track);
  // 23:30 in London on 2 November is 18:30 in New York. Midnight in London must not move the day on twice.
  await clock(page.request, west, '2026-11-02 23:30:00');
  await setZone(page, 'America/New_York');
  expect((await state(page.request, west)).today).toBe('2026-11-02');
  await clock(page.request, west, '2026-11-03 00:30:00');
  saved = await state(page.request, west);
  expect(saved).toMatchObject({ today: '2026-11-02', zone: 'America/New_York' });
  expect(saved.enrolments[0].day).toBe(2);
  await clock(page.request, west, '2026-11-03 05:30:00');
  saved = await state(page.request, west);
  expect(saved.today).toBe('2026-11-03');
  expect(saved.enrolments[0].day).toBe(3);
});

test('T3 rule 1: days follow the local calendar through clock changes, with the test clock', async ({ page }) => {
  test.setTimeout(180000);
  const made = await fixture(page.request, 'days', stamp());
  const spring = emailFor('spring');
  await createMember(page.request, { email: spring, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, spring, '2026-03-27 12:00:00');
  await signIn(page, spring, password);
  await startSlug(page, made.track);
  // 23:30 UTC on 29 March is 00:30 BST on 30 March: day 4 in London.
  await clock(page.request, spring, '2026-03-29 23:30:00');
  expect((await state(page.request, spring)).enrolments[0].day).toBe(4);
  await page.goto('/members/track/?day=4');
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  const results = (await state(page.request, spring)).results;
  expect(results.map((row) => [Number(row.programme_day), row.local_date])).toEqual([[4, '2026-03-30']]);
  await page.goto('/members/track/?day=5');
  await expect(page.getByText('Day 5 opens on Tuesday 31 March.')).toBeVisible();

  const autumn = emailFor('autumn');
  await createMember(page.request, { email: autumn, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(page.request, autumn, '2026-10-23 12:00:00');
  await signIn(page, autumn, password);
  await startSlug(page, made.track);
  // 23:30 UTC on 24 October is 00:30 BST on the 25th; the 25th lasts 25 hours and is still one day.
  for (const [utc, day] of [['2026-10-24 22:30:00', 2], ['2026-10-24 23:30:00', 3], ['2026-10-25 23:30:00', 3], ['2026-10-26 00:30:00', 4]]) {
    await clock(page.request, autumn, utc);
    expect((await state(page.request, autumn)).enrolments[0].day, utc).toBe(day);
  }
});

test('TC-FR2-25-AS-05 programme: an active Healthy Weight track ends when pregnancy or an eating disorder is saved', async ({ page }) => {
  test.setTimeout(180000);
  const rows = [
    ['pregnancy', 'Talk to your midwife or doctor about weight and exercise during pregnancy', ''],
    ['eating-disorder', 'For support with eating, talk to your doctor', 'https://findahelpline.com'],
  ];
  for (const [condition, note, helpline] of rows) {
    const email = emailFor(`hw${condition}`);
    const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
    await signIn(page, email, password);
    await startSlug(page, 'healthy-weight');
    expect((await state(page.request, email)).enrolments[0]).toMatchObject({ track: 'healthy-weight', status: 'active' });
    const res = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
      headers: { 'X-WP-Nonce': await restNonce(page) },
      data: { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify([condition]) },
    });
    expect(res.status()).toBe(200);
    await page.goto('/members/home/');
    const today = page.locator('[data-lld-section="today"]');
    await expect(today).toContainText('Healthy Weight is no longer offered to you, so it has stopped.');
    await expect(today).toContainText(note);
    if (helpline) await expect(today.locator(`a[href="${helpline}"]`)).toHaveCount(1);
    await expect(today.getByRole('link', { name: 'Upgrade' })).toHaveCount(0);
    const saved = await state(page.request, email);
    expect(saved.enrolments[0]).toMatchObject({ status: 'stopped', stopped_why: 'not_suitable' });
    // Told once, on the next visit.
    await page.goto('/members/home/');
    await expect(page.locator('[data-lld-section="today"]')).not.toContainText('no longer offered');
    await page.goto('/members/track/?day=1');
    await expect(page.getByRole('heading', { name: 'Not suitable for you' })).toBeVisible();
    await page.goto('/members/plan/');
    await expect(page.getByText('Start a track to get a plan.')).toBeVisible();
  }
});
