import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { createMember, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const columns = JSON.parse(readFileSync(new URL('../personal-columns.json', import.meta.url), 'utf8'));
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const SENTINEL = 'SENTINEL-ELSE-5520';
const jpeg = Buffer.from(
  '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==',
  'base64'
);
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

function sized(name, bytes, extra = 0) {
  const path = join(tmpdir(), name);
  writeFileSync(path, Buffer.concat([bytes, Buffer.alloc(extra)]));
  return path;
}

function makeJpeg(name, targetBytes) {
  const path = join(tmpdir(), name);
  const script = join(tmpdir(), `lld-make-${name}.php`);
  writeFileSync(
    script,
    `<?php
$im = imagecreatetruecolor(16, 16);
imagefilledrectangle($im, 0, 0, 15, 15, imagecolorallocate($im, 20, 80, 40));
imagejpeg($im, ${JSON.stringify(path)}, 85);
$extra = ${Math.floor(targetBytes)} - filesize(${JSON.stringify(path)});
if ($extra > 0) {
  $handle = fopen(${JSON.stringify(path)}, 'ab');
  fwrite($handle, str_repeat("\\0", $extra));
  fclose($handle);
}
`
  );
  execFileSync('php', [script], { stdio: 'pipe' });
  return path;
}

async function reset(request, email) {
  expect((await request.post('/wp-json/lld-members/v1/test/reset', { data: { email } })).ok()).toBe(true);
}

async function mailMatching(text) {
  let last = '';
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    for (const message of list.messages || []) {
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      const to = JSON.stringify(full.To || full.to || '');
      last = `${full.Subject || ''}\n${full.Text || ''}`;
      if (last.includes(text) || to.toLowerCase().includes(String(text).toLowerCase())) return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`Mail not found: ${text}. Last: ${last.slice(0, 160)}`);
}

function linkFrom(body) {
  const found = body.match(/https?:\/\/\S+/);
  if (!found) throw new Error(body.slice(0, 160));
  return found[0].replace(/[).,>]+$/, '');
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

test('TC-FR2-02-AS-01', async ({ page }) => {
  const email = 'tm-03e@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', role: 'member', health_consent: true });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_first').fill('Samantha');
  await page.locator('#lld_last').fill('Lee');
  await page.locator('#lld_display').fill('Sammy');
  await page.locator('#lld_age').fill('41');
  await page.locator('#lld_timezone').fill('Europe/London');
  await page.getByRole('checkbox', { name: 'Show imperial units' }).check();
  await page.locator('#lld_goal').fill('sleep');
  await page.locator('#lld_track').fill('sleep');
  await page.locator('#lld_height').fill('170');
  await page.getByRole('checkbox', { name: 'Daily reminder' }).check();
  await page.locator('#lld_reminder_time').fill('07:30');
  await page.locator('#lld_password').fill('newer-pass');
  const next = 'sam-new@test.lifelinedaily.invalid';
  await reset(page.request, next);
  await page.locator('#lld_email').fill(next);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.reload();
  await expect(page.locator('#lld_first')).toHaveValue('Samantha');
  await expect(page.locator('#lld_display')).toHaveValue('Sammy');
  await expect(page.locator('#lld_timezone')).toHaveValue('Europe/London');
  await expect(page.locator('#lld_height')).toHaveValue('170');
  await expect(page.locator('#lld_goal')).toHaveValue('sleep');
  await expect(page.locator('#lld_track')).toHaveValue('sleep');
  await page.context().clearCookies();
  await signIn(page, email, 'newer-pass');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  const body = await mailMatching(next);
  await page.goto(linkFrom(body));
  await expect(page.getByText(`Your email is now ${next}`)).toBeVisible();
  await page.context().clearCookies();
  await signIn(page, next, 'newer-pass');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  expect(created.member_id).toBeGreaterThan(0);
});

test('TC-FR2-02-AS-02', async ({ page }) => {
  test.setTimeout(120000);
  const email = 'tm-03f@test.lifelinedaily.invalid';
  const other = 'tm-04f@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, other);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await createMember(page.request, { email: other, password, first_name: 'Tee', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(makeJpeg('photo-ok.jpg', 9.9 * 1024 * 1024));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.locator('img')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles(sized('photo.png', png, 0));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles(makeJpeg('photo-big.jpg', 10.1 * 1024 * 1024));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('10 MB or smaller')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles({ name: 'photo.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') });
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Use a JPG, PNG or HEIC')).toBeVisible();
  const origin = new URL(page.url()).origin;
  const anon = await page.context().browser().newContext();
  const signedOut = await anon.request.get(`${origin}/members/photo/${created.member_id}/`, { maxRedirects: 0 });
  expect(signedOut.status()).toBeGreaterThanOrEqual(300);
  await anon.close();
  await signIn(page, other, password);
  const refused = await page.request.get(`/members/photo/${created.member_id}/`, { maxRedirects: 0 });
  expect(refused.status()).toBeGreaterThanOrEqual(300);
  await signIn(page, email, password);
  const own = await page.request.get(`/members/photo/${created.member_id}/`);
  expect(own.ok()).toBe(true);
  expect(await own.text()).not.toContain('GPS');
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(sized('photo.heic', jpeg, 0));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
});

test('TC-FR2-02-AS-03', async ({ page }) => {
  const email = 'tm-03g@test.lifelinedaily.invalid';
  const next = 'tm-03g-new@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, next);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_email').fill(next);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText(`Check ${next} to confirm`)).toBeVisible();
  await page.context().clearCookies();
  await signIn(page, email, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.goto(linkFrom(await mailMatching(next)));
  await page.context().clearCookies();
  await signIn(page, next, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await signIn(page, email, password);
  await expect(page.getByText("Those details don't match our records.")).toBeVisible();
});

test('TC-FR2-02-AS-04', async ({ page }) => {
  const free = 'tm-03h@test.lifelinedaily.invalid';
  const paid = 'tm-04h@test.lifelinedaily.invalid';
  await reset(page.request, free);
  await reset(page.request, paid);
  await createMember(page.request, { email: free, password, first_name: 'Sam', role: 'member', access_state: 'free' });
  await createMember(page.request, { email: paid, password, first_name: 'Tee', role: 'member', access_state: 'Premium', period_ends_on: '2027-02-01' });
  await signIn(page, free, password);
  await page.goto('/members/settings/');
  await expect(page.getByText('Joined on')).toBeVisible();
  await expect(page.getByText('not applicable')).toBeVisible();
  await signIn(page, paid, password);
  await page.goto('/members/settings/');
  await expect(page.getByText('2027-02-01')).toBeVisible();
});

test('TC-FR2-02-AS-05', async ({ page }) => {
  const email = 'tm-03i@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_age').fill('17');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Age cannot be under 18.')).toBeVisible();
  const later = new Date(`${created.joined_on}T00:00:00Z`);
  later.setUTCFullYear(later.getUTCFullYear() + 1);
  later.setUTCDate(later.getUTCDate() + 1);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: later.toISOString().slice(0, 19).replace('T', ' ') } });
  await page.reload();
  await expect(page.getByText('Age: 41')).toBeVisible();
});

test('TC-FR2-03-AS-01', async ({ page }) => {
  const email = 'tm-01e@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Tina');
  await page.locator('#lld_last').fill('One');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill('adult-pass');
  await page.locator('#lld_age').fill('30');
  await page.getByRole('button', { name: 'Create account' }).click();
  await page.goto(linkFrom(await mailMatching(email)));
  await page.goto('/members/goals/');
  await expect(page.getByRole('heading', { name: 'Agreements' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Choose up to 3 goals' })).toHaveCount(0);
});

test('TC-FR2-03-AS-02', async ({ page }) => {
  const email = 'tm-02b@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/agreements/');
  await expect(page.locator('input[name="lld_health"]')).not.toBeChecked();
  await expect(page.locator('input[name="lld_marketing"]')).not.toBeChecked();
});

test('TC-FR2-03-AS-03', async ({ page }) => {
  const email = 'tm-02@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: 'No', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/goals/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  for (const kind of ['goal', 'profile', 'check_in', 'diary', 'result']) {
    const res = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
      data: { kind, goal_key: 'sleep', sort_order: 1, height_cm: 170 },
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(res.status(), kind).toBe(403);
  }
  await page.goto('/members/settings/');
  await page.locator('#lld_first').fill('Nope');
  await page.getByRole('button', { name: 'Save' }).click();
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, sentinel: SENTINEL } });
  const problems = (await scan.json()).problems.join(' ');
  expect(problems).not.toContain('goal_choice');
  expect(problems).not.toContain('health_profile');
  expect(problems).not.toContain('check_in');
  expect(problems).not.toContain('diary');
  expect(problems).not.toContain('calculator_result');
});

test('TC-FR2-03-AS-04', async ({ page }) => {
  const email = 'tm-04@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    sentinel: SENTINEL,
    access_state: 'Premium',
    what_changed: SENTINEL,
  });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_diary').setInputFiles(makeJpeg('diary.jpg', 2048));
  await page.getByRole('button', { name: 'Save' }).click();
  await page.goto('/members/withdraw/');
  await expect(page.getByText('Your subscription continues. To stop paying, cancel it separately.')).toBeVisible();
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, sentinel: SENTINEL, scope: 'health' } });
  const body = await scan.json();
  expect(body.problems, JSON.stringify(body)).toEqual([]);
  await page.goto('/members/settings/');
  await expect(page.getByText('Membership: Premium')).toBeVisible();
});

test('TC-FR2-03-AS-05', async ({ page }) => {
  const email = 'tm-03j@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Sam');
  await page.locator('#lld_last').fill('Lee');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill('adult-pass');
  await page.locator('#lld_age').fill('40');
  await page.getByRole('button', { name: 'Create account' }).click();
  await page.goto(linkFrom(await mailMatching(email)));
  await page.goto('/members/agreements/');
  await page.getByRole('checkbox', { name: /I accept the/ }).check();
  await page.getByRole('checkbox', { name: 'Allow Lifeline Daily to store my health information' }).check();
  await page.getByRole('checkbox', { name: 'Send me marketing emails' }).check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.goto('/members/settings/');
  await expect(page.locator('.lld-consent-history')).toContainText('terms given terms-2026-11');
  await expect(page.locator('.lld-consent-history')).toContainText('privacy given privacy-2026-11');
  await expect(page.locator('.lld-consent-history')).toContainText('health given');
  await expect(page.locator('.lld-consent-history')).toContainText('marketing given');
});

test('TC-FR2-04-AS-01', async ({ page }) => {
  const email = 'tm-04a@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', health_consent: true, sentinel: SENTINEL });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(makeJpeg('export.jpg', 2048));
  await page.getByRole('button', { name: 'Save' }).click();
  await page.goto('/members/data/');
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download all my data' }).click();
  const download = await downloadPromise;
  const file = join(tmpdir(), 'lld-data.zip');
  await download.saveAs(file);
  const packed = unzip(readFileSync(file));
  expect(packed['summary.html'].toString()).toContain('Your data');
  const data = JSON.parse(packed['data.json'].toString());
  expect(JSON.stringify(data)).toContain(SENTINEL);
  expect(JSON.stringify(data)).not.toContain('$wp$');
  expect(JSON.stringify(packed)).not.toContain('data.key');
  expect(Object.keys(packed).some((name) => name.startsWith('photos/'))).toBe(true);
  const stores = await page.request.get('/wp-json/lld-members/v1/test/stores');
  expect((await stores.json()).download_files).toBe(0);
  expect(created.member_id).toBeGreaterThan(0);
});

test('TC-FR2-04-AS-02', async ({ page }) => {
  const email = 'tm-04b@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium', stripe_subscription: 'sub_test_c2' });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  const stripe = await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: {} });
  expect((await stripe.json()).last_cancel.ok).toBe(true);
});

test('TC-FR2-04-AS-03', async ({ page }) => {
  const email = 'tm-04c@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    sentinel: SENTINEL,
    referral: true,
  });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { member_id: created.member_id, utc: '2027-10-26 00:00:00' } });
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, sentinel: SENTINEL, email } });
  const body = await scan.json();
  expect(body.problems, JSON.stringify(body)).toEqual([]);
  expect(body.user_id).toBe(0);
});

test('TC-FR2-04-AS-04', async ({ page }) => {
  const email = 'tm-04d@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { cancel_fail: true } });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText("We're finishing cancelling your subscription")).toBeVisible();
  await expect(mailMatching('Stripe cancellation failed')).resolves.toContain('Stripe cancellation failed');
  await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { cancel_fail: false } });
});

test('TC-FR2-04-AS-06', async ({ page }) => {
  const email = 'tm-04e@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(mailMatching('Your account is closed')).resolves.toContain('Tee');
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  await expect(mailMatching('erasure list')).resolves.toContain('account');
});

test('TC-NFR2-01-AS-01', async ({ page }) => {
  const stores = await page.request.get('/wp-json/lld-members/v1/test/stores');
  const body = await stores.json();
  expect(body.extra_tables).toEqual([]);
  expect(body.extra_files).toEqual([]);
  const liveNames = Object.keys(body.columns).sort();
  const frozenNames = Object.keys(columns).sort();
  expect(liveNames).toEqual(frozenNames);
  for (const name of frozenNames) {
    expect(body.columns[name].slice().sort(), name).toEqual(columns[name].slice().sort());
  }
});

test('TC-NFR2-01-AS-03', async ({ page }) => {
  const gone = 'tm-19@test.lifelinedaily.invalid';
  const kept = 'tm-19b@test.lifelinedaily.invalid';
  await reset(page.request, gone);
  await reset(page.request, kept);
  const created = await createMember(page.request, { email: gone, password, first_name: 'Idle', role: 'member', access_state: 'free' });
  await createMember(page.request, { email: kept, password, first_name: 'Back', role: 'member', access_state: 'free' });
  const deleteOn = new Date(`${created.joined_on}T00:00:00Z`);
  deleteOn.setUTCMonth(deleteOn.getUTCMonth() + 24);
  const warn = new Date(deleteOn);
  warn.setUTCDate(warn.getUTCDate() - 30);
  const stamp = (date) => date.toISOString().slice(0, 19).replace('T', ' ');
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: gone, utc: stamp(warn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  await expect(mailMatching('Sign in to keep your account')).resolves.toContain('due to be deleted');
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: gone, utc: stamp(deleteOn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, email: gone } });
  expect((await scan.json()).user_id).toBe(0);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: kept, utc: stamp(warn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  await signIn(page, kept, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: kept, utc: stamp(deleteOn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const keptScan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { email: kept } });
  expect((await keptScan.json()).user_id).toBeGreaterThan(0);
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
});
