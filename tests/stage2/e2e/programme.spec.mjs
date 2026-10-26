import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, editBody, signIn } from './helpers.mjs';

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
  await page.goto('/wp-admin/admin.php?page=lld-approvals');
  if (page.url().includes('pmpro-wizard')) {
    await page.goto('/wp-admin/admin.php?page=lld-approvals');
  }
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check', { force: true });
  await page.locator('tr', { hasText: title }).first().getByRole('button', { name: 'Approve' }).click({ force: true });
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
  await signIn(page, paidEmail, password);
  await page.goto('/members/track/?day=8');
  await expect(page.getByRole('heading', { name: title })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Upgrade' })).toHaveCount(0);
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
  const email = emailFor('measures');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  const expectOnly = async (present, absent) => {
    await page.goto('/members/check-in/');
    for (const id of present) await expect(page.locator(id)).toBeVisible();
    for (const id of absent) await expect(page.locator(id)).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText(/blood glucose/i);
  };
  await startSlug(page, 'healthy-weight');
  await expectOnly(['#lld_weight', '#lld_waist'], ['#lld_chair', '#lld_push', '#lld_activity', '#lld_strength', '#lld_sleep', '#lld_quality', '#lld_stress', '#lld_reflect_1']);
  await startSlug(page, 'blood-sugar');
  await expectOnly(['#lld_weight', '#lld_waist', '#lld_activity'], ['#lld_chair', '#lld_push', '#lld_strength', '#lld_sleep', '#lld_quality', '#lld_stress']);
  await startSlug(page, 'fitness');
  await expectOnly(['#lld_chair', '#lld_push', '#lld_activity', '#lld_strength'], ['#lld_weight', '#lld_waist', '#lld_sleep', '#lld_quality', '#lld_stress']);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="no"]').check();
  await page.locator('input[name="lld_panic"][value="no"]').check();
  await page.locator('input[name="lld_mood"][value="no"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expectOnly(['#lld_sleep', '#lld_quality', '#lld_stress', '#lld_reflect_1', '#lld_reflect_2', '#lld_reflect_3'], ['#lld_weight', '#lld_waist', '#lld_chair', '#lld_activity']);
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

test('TC-FR2-10-AS-04', async ({ page }) => {
  const mark = stamp();
  const made = await fixture(page.request, 'actions', mark);
  const email = emailFor('swap');
  await createMember(page.request, { email, password, first_name: 'Pat', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, made.track);
  await page.goto('/members/plan/');
  await page.getByRole('button', { name: 'Swap my plan' }).click();
  await expect(page.getByRole('button', { name: 'Swap my plan' })).toBeVisible();
  await page.getByRole('button', { name: 'Swap my plan' }).click();
  await expect(page.getByText('You can swap again next week.')).toBeVisible();
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
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await startSlug(page, thin.track);
  await page.goto('/members/plan/');
  await expect(page.locator('.lld-action')).toHaveCount(2);
  await expect(page.getByText('Fewer than 3 safe actions are available.')).toBeVisible();
  await page.goto('/members/home/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify(['pregnancy']) },
  });
  await startSlug(page, empty.track);
  await page.goto('/members/plan/');
  await expect(page.getByText('No safe actions are available this week.')).toBeVisible();
  await expect(page.locator('main')).not.toContainText(empty.items[0].title);
});

test('TC-FR2-11-AS-01', async ({ page }) => {
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
  await expect(page.getByText('You showed up. That is enough for this week.')).toBeVisible();
  await expect(page.getByText('Your review is not ready yet.')).toHaveCount(0);
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
  await signIn(page, london, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_timezone').fill('Europe/London');
  await page.locator('#lld_reminder_time').fill('07:30');
  await page.getByRole('checkbox', { name: 'Daily reminder' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await signIn(page, york, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_timezone').fill('America/New_York');
  await page.locator('#lld_reminder_time').fill('07:30');
  await page.getByRole('checkbox', { name: 'Daily reminder' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  const count = async (email) => (await mailFor(email)).filter((message) => (message.Subject || '').includes("Today's activity is ready")).length;
  await clock(page.request, london, '2026-01-15 07:29:00');
  await reports(page.request);
  expect(await count(london)).toBe(0);
  await clock(page.request, london, '2026-01-15 07:30:00');
  await reports(page.request);
  expect(await count(london)).toBe(1);
  await clock(page.request, london, '2026-01-15 07:39:00');
  await reports(page.request);
  expect(await count(london)).toBe(1);
  await clock(page.request, london, '2026-01-16 07:41:00');
  await reports(page.request);
  expect(await count(london)).toBe(1);
  await clock(page.request, london, '2026-03-29 06:30:00');
  await reports(page.request);
  expect(await count(london)).toBe(2);
  await clock(page.request, york, '2026-01-15 12:30:00');
  await reports(page.request);
  expect(await count(york)).toBe(1);
  await clock(page.request, york, '2026-03-08 11:30:00');
  await reports(page.request);
  expect(await count(york)).toBe(2);
  await clock(page.request, york, '2026-11-01 12:30:00');
  await reports(page.request);
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
  await startSlug(page, made.track);
  await reports(page.request);
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
