import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, signIn } from './helpers.mjs';

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
  const body = readFileSync(await download.path(), 'utf8');
  expect(body).toContain('red lentils');
  expect(body).toContain('spinach');
});

test('TC-FR2-32-AS-04', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'swap');
  await clock(request, member.email, '2026-05-04 12:00:00');
  await signIn(page, member.email, password);
  await saveHealth(page, member.id, { kind: 'profile', diet_type: 'vegetarian', confirmed: true });
  await startSlug(page, made.track);
  await page.goto('/members/meals/');
  await expect(page.locator('#lld_recipe')).toContainText(made.oat);
  await expect(page.locator('[data-lld-shopping]')).not.toContainText('oats');
  await page.locator('#lld_recipe').selectOption({ label: made.oat });
  await page.getByRole('button', { name: 'Swap' }).click();
  await expect(page.locator('[data-lld-shopping]')).toContainText('oats');
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
  await expect(page.locator('#lld-session')).toBeHidden();
  await page.getByRole('button', { name: "I'm ready" }).click();
  await expect(page.locator('#lld-phase')).toHaveText('exercise');
  await expect(page.locator('#lld-timer')).toHaveText('10');
  await page.getByRole('button', { name: 'Start' }).click();
  await page.clock.runFor(10000);
  await expect(page.locator('#lld-phase')).toHaveText('rest');
  await expect(page.locator('#lld-timer')).toHaveText('30');
  await page.clock.runFor(30000);
  await expect(page.locator('#lld-phase')).toHaveText('done');
});

test('TC-FR2-34-AS-04', async ({ page, request }) => {
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
});

test('TC-FR2-35-AS-02', async ({ page, request }) => {
  const made = await fixture(request);
  const member = await paid(request, 'qa');
  await clock(request, member.email, '2026-11-01 12:00:00');
  await signIn(page, member.email, password);
  await page.goto('/members/home/');
  await expect(page.getByText(`Next live Q&A ${made.qa}`)).toBeVisible();
  await expect(page.getByText("Facebook's own privacy terms apply")).toBeVisible();
});

test('TC-FR2-35-AS-03', async ({ page, request }) => {
  const email = emailFor('ended');
  await createMember(request, {
    email, password, first_name: 'End', role: 'member', consents: ['terms'], access_state: 'Premium ended', premium_ended_on: '2020-01-01',
  });
  await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  let hit = false;
  for (const message of list.messages || []) {
    const subject = String(message.Subject || '');
    if (!subject.includes('People to remove from the group')) continue;
    const full = await fetch(`${MAILPIT}/api/v1/message/${message.ID}`).then((res) => res.json());
    const body = `${full.Subject || ''}\n${full.Text || ''}`;
    if (body.includes(email)) hit = true;
  }
  expect(hit, 'weekly removal list').toBeTruthy();
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-group');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-group');
  const row = page.locator('li', { hasText: email });
  await expect(row).toContainText('overdue');
  await row.getByRole('button', { name: 'Record removal' }).click();
  await expect(page.locator('li', { hasText: email })).toContainText(/Removed on \d{4}-\d{2}-\d{2}/);
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
  const nonce = await page.locator('input[name="lld_diary_nonce"]').inputValue();
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
  const nonce = await page.locator('input[name="lld_diary_nonce"]').inputValue();
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
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  const scan = await request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: member.id, scope: 'account', sentinel } });
  expect((await scan.json()).problems).toEqual([]);
});

test('TC-FR2-37-AS-07', async ({ page, request }) => {
  const member = await paid(request, 'photo');
  const other = await paid(request, 'other');
  await signIn(page, member.email, password);
  await page.goto('/members/diary/');
  await page.locator('#lld_photo').setInputFiles(fileOf(`gif-${stamp()}.gif`, Buffer.from('GIF89a')));
  await page.getByRole('button', { name: 'Save meal' }).click();
  await expect(page.getByText('This file is too large or not a supported type')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles(fileOf(`big-${stamp()}.jpg`, Buffer.alloc(11 * 1024 * 1024, 1)));
  await page.getByRole('button', { name: 'Save meal' }).click();
  await expect(page.getByText('This file is too large or not a supported type')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles(fileOf(`ok-${stamp()}.png`, png));
  await page.getByRole('button', { name: 'Save meal' }).click();
  const href = await page.getByRole('link', { name: 'Photo' }).getAttribute('href');
  const own = await page.request.get(href);
  expect(own.status()).toBe(200);
  expect(own.headers()['content-type'] || '').toContain('image/jpeg');
  expect(await own.body()).not.toContain(Buffer.from('GPS'));
  await signIn(page, other.email, password);
  const refused = await page.request.get(href, { maxRedirects: 0 });
  expect(refused.status()).toBeGreaterThanOrEqual(400);
  await page.context().clearCookies();
  const signedOut = await page.request.get(href, { maxRedirects: 0 });
  expect(signedOut.status()).not.toBe(200);
});
