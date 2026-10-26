import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, signIn } from './helpers.mjs';

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

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;

// UTC "Y-m-d H:i:s" a given number of milliseconds from now, for the test clock and restore floors.
function fromNow(ms) {
  return new Date(Date.now() + ms).toISOString().slice(0, 19).replace('T', ' ');
}

async function scanOf(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/scan', { data });
  expect(res.ok(), await res.text()).toBe(true);
  return res.json();
}

// Restore procedure (Data Model T6): close, re-apply the emailed erasure list, verify, reopen.
async function erasure(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/erasure', { data });
  expect(res.ok(), await res.text()).toBe(true);
  return res.json();
}

// The newest message to one address whose subject or text includes the given words.
async function mailTo(address, text) {
  let last = '';
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    const messages = (list.messages || []).filter((message) => JSON.stringify(message.To || []).toLowerCase().includes(address.toLowerCase()));
    messages.sort((a, b) => new Date(b.Created || 0) - new Date(a.Created || 0));
    for (const message of messages) {
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      last = `${full.Subject || ''}\n${full.Text || ''}`;
      if (last.includes(text)) return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No mail to ${address} with ${text}. Last: ${last.slice(0, 160)}`);
}

// The erasure-list email holding one member's line, with its recipients.
async function erasureMail(memberId, what) {
  const line = `\n${memberId} ${what} `;
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    for (const message of list.messages || []) {
      if (!String(message.Subject || '').includes('Erasure list')) continue;
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      const body = `${full.Subject || ''}\n${full.Text || ''}`;
      if (body.includes(line)) return { body, to: JSON.stringify(full.To || []) };
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No erasure list with ${line.trim()}`);
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
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible();
  await signIn(page, email, 'adult-pass');
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
  // The direct save route is a test helper: without the test secret it does not answer at all.
  const open = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    data: { kind: 'check_in' },
    headers: { 'X-WP-Nonce': nonce, 'X-LLD-Test-Secret': 'wrong' },
  });
  expect([401, 403]).toContain(open.status());
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
    quote: SENTINEL,
  });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_diary').setInputFiles(makeJpeg('diary.jpg', 2048));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // Before: the diary photo, answers, testimonial and consent are all there, so the check can fail.
  const before = await scanOf(page.request, { member_id: created.member_id, scope: 'health' });
  const found = before.problems.join(' ');
  for (const part of ['diary_meal', 'something_else', 'A diary photo remains.', 'A what-changed answer remains.', 'A testimonial remains.', 'Health consent is still on.']) {
    expect(found, part).toContain(part);
  }
  expect(before.diary_meals.length).toBe(1);
  expect(before.records.subscription).toBe(1);
  const photo = `/members/diary-photo/${created.member_id}/?meal=${before.diary_meals[0]}`;
  expect((await page.request.get(photo)).status()).toBe(200);

  await page.goto('/members/withdraw/');
  await expect(page.getByText('This deletes your goals, track progress, health profile, check-ins, food diary and diary photos, saved results and "what changed" survey answers.')).toBeVisible();
  await expect(page.getByText('Your subscription continues. To stop paying, cancel it separately.')).toBeVisible();
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  const body = await scanOf(page.request, { member_id: created.member_id, sentinel: SENTINEL, scope: 'health' });
  expect(body.problems, JSON.stringify(body)).toEqual([]);
  expect((await page.request.get(photo)).status()).toBe(404);
  expect(body.records.subscription).toBe(1);
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
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible();
  await signIn(page, email, 'adult-pass');
  await page.goto('/members/agreements/');
  await page.getByRole('checkbox', { name: /I accept the/ }).check();
  await page.getByRole('checkbox', { name: 'Allow Lifeline Daily to store my health information' }).check();
  await page.getByRole('checkbox', { name: 'Send me marketing emails' }).check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.goto('/members/settings/');
  const history = page.locator('.lld-consent-history li');
  await expect(page.locator('.lld-consent-history')).toContainText('terms given terms-2026-11');
  await expect(page.locator('.lld-consent-history')).toContainText('privacy given privacy-2026-11');
  await expect(page.locator('.lld-consent-history')).toContainText('health given');
  await expect(page.locator('.lld-consent-history')).toContainText('marketing given');
  const today = new Date().toISOString().slice(0, 10);
  const health = page.getByRole('checkbox', { name: 'Store my health information' });
  const marketing = page.getByRole('checkbox', { name: 'Marketing emails' });
  await expect(health).toBeChecked();
  await expect(marketing).toBeChecked();
  await expect(page.locator('#lld_consent_marketing_date')).toHaveText(`On since ${today}`);

  // Each later change, made with its switch on the profile page, adds one dated line.
  const change = async (expected) => {
    const lines = await history.count();
    await page.getByRole('button', { name: 'Save' }).click();
    await page.goto('/members/settings/');
    await expect(history).toHaveCount(lines + 1);
    await expect(history.last()).toContainText(expected);
    await expect(history.last()).toContainText(today);
  };
  await marketing.uncheck();
  await change('marketing withdrawn');
  await expect(marketing).not.toBeChecked();
  await expect(page.locator('#lld_consent_marketing_date')).toHaveText(`Off since ${today}`);
  await marketing.check();
  await change('marketing given');
  await expect(marketing).toBeChecked();

  // Switching health information off shows the warning first, and only Confirm withdraws it.
  const before = await history.count();
  await health.uncheck();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('heading', { name: 'Withdraw health consent' })).toBeVisible();
  await expect(page.getByText('This deletes your goals, track progress, health profile, check-ins, food diary and diary photos')).toBeVisible();
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  await page.goto('/members/settings/');
  await expect(history).toHaveCount(before + 1);
  await expect(history.last()).toContainText('health withdrawn');
  await expect(health).not.toBeChecked();
  await health.check();
  await change('health given');
  await expect(health).toBeChecked();
  await expect(page.locator('#lld_consent_health_date')).toHaveText(`On since ${today}`);
});

test('TC-FR2-03-AS-06', async ({ browser, page }) => {
  // The staging backup restore itself is run before launch (NFR2-06 AS-01). Here the restore is
  // simulated: the pre-withdrawal "given" consent line and health data are put back, then the
  // T6 procedure re-applies the erasure list taken from the owner's email in Mailpit.
  const email = 'tm-04r@test.lifelinedaily.invalid';
  const mark = `SENTINEL-RESTORE-${randomBytes(3).toString('hex')}`;
  const health = { email, password, first_name: 'Rhea', role: 'member', health_consent: true, sentinel: mark, what_changed: mark, quote: mark };
  await reset(page.request, email);
  const since = fromNow(-5000);
  const created = await createMember(page.request, health);
  await signIn(page, email, password);
  await page.goto('/members/withdraw/');
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const { body: list } = await erasureMail(created.member_id, 'health');

  await createMember(page.request, health);
  const restored = (await scanOf(page.request, { member_id: created.member_id, scope: 'health' })).problems.join(' ');
  expect(restored).toContain('Health consent is still on.');
  expect(restored).toContain('something_else');

  const member = await browser.newContext();
  const memberPage = await member.newPage();
  await signIn(memberPage, email, password);
  await asOwner(page);
  try {
    await erasure(page.request, { action: 'close' });
    await memberPage.goto('/members/home/');
    await expect(memberPage.getByRole('heading', { name: 'Lifeline Daily is closed' })).toBeVisible();
    const done = await erasure(page.request, { action: 'reapply', list, since });
    expect(done.problems, JSON.stringify(done)).toEqual([]);
    expect(done.reapplied).toBeGreaterThanOrEqual(1);
    expect(done.closed).toBe(false);
  } finally {
    await erasure(page.request, { action: 'open' });
  }
  const after = await scanOf(page.request, { member_id: created.member_id, scope: 'health', sentinel: mark });
  expect(after.problems, JSON.stringify(after)).toEqual([]);
  await memberPage.goto('/members/settings/');
  await expect(memberPage.locator('.lld-consent-history li').last()).toContainText('health withdrawn');
  await member.close();
});

// Data Model section 11: what the download holds (A1 to A3, C1 to C16, P2, P3, P5, E2, F3, F4).
const DOWNLOAD_TABLES = [
  'member', 'email_pref', 'consent',
  'goal_choice', 'something_else', 'health_profile', 'calculator_result', 'enrolment', 'enrolment_period', 'challenge',
  'workout', 'workout_change', 'result', 'check_in', 'reflection', 'weekly_plan', 'weekly_review', 'diary_meal',
  'diary_day', 'meal_plan', 'suggestion', 'badge',
  'subscription', 'order', 'referral', 'reward', 'contact_request', 'support', 'survey',
];
// Never in the download: A4 security records, unsubscribe codes, the test clock, operations records.
const NEVER_DOWNLOADED = ['security_code', 'sign_in_lock', 'session', 'app_key', 'unsubscribe_code', 'test_clock', 'erasure', 'admin_log', 'fb_removal', 'contact', 'contact_consent', 'error_log', 'stripe_notice'];

test('TC-FR2-04-AS-01', async ({ page }) => {
  const email = 'tm-04a@test.lifelinedaily.invalid';
  const referrer = 'tm-04a-ref@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, referrer);
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    consents: ['marketing'],
    sentinel: SENTINEL,
    what_changed: 'Walking more',
    quote: 'Kinder to myself',
    referral: true,
    access_state: 'Premium',
    stripe_subscription: 'sub_test_c2a',
  });
  await createMember(page.request, { email: referrer, password, first_name: 'Ria', role: 'member', referral: true, friend_member_id: created.member_id });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(makeJpeg('export.jpg', 2048));
  await page.locator('#lld_diary').setInputFiles(makeJpeg('export-diary.jpg', 2048));
  await page.getByRole('checkbox', { name: 'Weekly review' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // Records the download must leave out exist too: a reset code, a session and a test clock.
  await page.goto('/members/reset/');
  await page.locator('#lld_email').fill(email);
  await page.getByRole('button', { name: 'Send link' }).click();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: fromNow(0) } });
  const db = await scanOf(page.request, { member_id: created.member_id, email });
  for (const name of ['security_code', 'session', 'test_clock']) expect(db.records[name], name).toBeGreaterThan(0);

  await page.goto('/members/data/');
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download all my data' }).click();
  const download = await downloadPromise;
  const file = join(tmpdir(), 'lld-data.zip');
  await download.saveAs(file);
  const packed = unzip(readFileSync(file));
  const data = JSON.parse(packed['data.json'].toString());
  expect(data.version).toBe(1);
  expect(data.member_number).toBe(created.member_id);
  // Table by table against the database, using the erasure checker's list.
  expect(Object.keys(data.tables).sort()).toEqual(DOWNLOAD_TABLES.slice().sort());
  for (const name of DOWNLOAD_TABLES) {
    expect(data.tables[name].length, name).toBe(db.records[name]);
  }
  for (const name of NEVER_DOWNLOADED) expect(data.tables[name], name).toBeUndefined();
  for (const [name, least] of Object.entries({ member: 1, email_pref: 1, consent: 2, something_else: 1, survey: 1, referral: 2, reward: 2, subscription: 1, diary_meal: 1 })) {
    expect(data.tables[name].length, name).toBeGreaterThanOrEqual(least);
  }
  expect(data.tables.referral.some((row) => Number(row.friend_member_id) === created.member_id)).toBe(true);
  // Encrypted text is readable, and no ciphertext, password, code or key is included.
  expect(data.tables.something_else[0].text).toBe(SENTINEL);
  expect(data.tables.survey[0].quote_text).toBe('Kinder to myself');
  const everything = Object.values(packed).map((part) => part.toString('latin1')).join('\n');
  for (const secret of ['text_ciphertext', 'description_ciphertext', '$wp$', '$P$', '$2y$', 'code_hash', 'token_hash', 'key_hash', 'user_pass', 'data.key']) {
    expect(everything, secret).not.toContain(secret);
  }
  // The profile photo and the diary photo are included.
  expect(Object.keys(packed)).toContain('photos/profile.jpg');
  expect(Object.keys(packed).some((name) => name.startsWith('photos/diary-'))).toBe(true);
  expect(data.photos.length).toBe(2);
  // A readable summary of the same records.
  const summary = packed['summary.html'].toString();
  for (const part of ['Your data', 'Consent history', 'Food diary meals', 'Referral rewards', 'Tee', SENTINEL, 'photos/profile.jpg']) {
    expect(summary, part).toContain(part);
  }
  const stores = await page.request.get('/wp-json/lld-members/v1/test/stores');
  expect((await stores.json()).download_files).toBe(0);
  await reset(page.request, referrer);
});

test('TC-FR2-04-AS-02', async ({ page }) => {
  const email = 'tm-04b@test.lifelinedaily.invalid';
  const ref = `sub_test_c2b_${randomBytes(4).toString('hex')}`;
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium', stripe_subscription: ref });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill('not-the-password');
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText("Those details don't match our records.")).toBeVisible();
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  // No Stripe call is made yet (it comes with the Stripe package), so the page must not claim billing
  // stopped, and the owner is alerted with the subscription reference to cancel it by hand.
  test.info().annotations.push({ type: 'pending', description: 'The Stripe cancellation call itself arrives with the Stripe package.' });
  await expect(page.getByText("We're finishing cancelling your subscription")).toBeVisible();
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  const stripe = await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: {} });
  const last = (await stripe.json()).last_cancel;
  expect(last.ok).toBe(false);
  expect(last.subscription).toBe(ref);
  await expect(mailMatching(ref)).resolves.toContain('Stripe cancellation failed');
});

test('TC-FR2-04-AS-03', async ({ page }) => {
  const email = 'tm-04c@test.lifelinedaily.invalid';
  const referrer = 'tm-04c-ref@test.lifelinedaily.invalid';
  const mark = `SENTINEL-GONE-${randomBytes(3).toString('hex')}`;
  await reset(page.request, email);
  await reset(page.request, referrer);
  // The member refers a friend, and was referred by another member: four rewards, two of them theirs.
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    sentinel: mark,
    what_changed: mark,
    referral: true,
  });
  const other = await createMember(page.request, { email: referrer, password, first_name: 'Ria', role: 'member', referral: true, friend_member_id: created.member_id });
  const friend = 800000 + created.member_id;
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(makeJpeg('gone-profile.jpg', 2048));
  await page.locator('#lld_diary').setInputFiles(makeJpeg('gone-diary.jpg', 2048));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // A data download that is never collected still holds the member's data.
  await page.goto('/members/data/');
  const dataNonce = await page.locator('input[name="lld_data_nonce"]').getAttribute('value');
  const made = await page.request.post('/members/data/', { form: { lld_data_nonce: dataNonce }, maxRedirects: 0 });
  expect(made.status()).toBe(302);
  expect((await (await page.request.get('/wp-json/lld-members/v1/test/stores')).json()).download_files).toBeGreaterThan(0);
  // Before: the checker finds the records, photos and sentinel, so an empty answer later means something.
  const before = (await scanOf(page.request, { member_id: created.member_id, sentinel: mark, email })).problems.join(' ');
  for (const part of ['member still has 1', 'referral still has 2', 'reward still has 2', 'survey still has', 'diary_meal still has', 'Photo files remain.', 'A data download remains.', 'Sentinel remains']) {
    expect(before, part).toContain(part);
  }

  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { member_id: created.member_id, utc: fromNow(24 * HOUR) } });
  const body = await scanOf(page.request, { member_id: created.member_id, sentinel: mark, email });
  expect(body.problems, JSON.stringify(body)).toEqual([]);
  expect(body.user_id).toBe(0);
  const search = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: mark } });
  expect(await search.json()).toEqual({ hits: [], log: false });
  // The other people keep their own reward and their referral, without this member's number.
  for (const id of [other.member_id, friend]) {
    const kept = await scanOf(page.request, { member_id: id });
    expect(kept.records.reward, String(id)).toBe(1);
    expect(kept.records.referral, String(id)).toBe(1);
  }
  await reset(page.request, referrer);
});

test('TC-FR2-04-AS-04', async ({ page }) => {
  const email = 'tm-04d@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const ref = `sub_test_c2d_${randomBytes(4).toString('hex')}`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium', stripe_subscription: ref });
  await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { cancel_fail: true } });
  try {
    await signIn(page, email, password);
    await page.goto('/members/delete/');
    await page.locator('#lld_password').fill(password);
    await page.getByRole('button', { name: 'Delete my account' }).click();
    await expect(page.getByText("We're finishing cancelling your subscription")).toBeVisible();
    const alert = await mailMatching(ref);
    expect(alert).toContain('Stripe cancellation failed');
    expect(alert).toContain('within 1 working day');
  } finally {
    await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { cancel_fail: false } });
  }
});

test('TC-FR2-04-AS-05', async ({ browser, page }) => {
  // As TC-FR2-03-AS-06, the restore is simulated: the deleted account is put back with the same
  // email (under a new member number, so the scrambled-email match is what finds it).
  const email = 'tm-04s@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const since = fromNow(-5000);
  const created = await createMember(page.request, { email, password, first_name: 'Sol', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const { body: list } = await erasureMail(created.member_id, 'account');

  const back = await createMember(page.request, { email, password, first_name: 'Sol', role: 'member' });
  expect((await scanOf(page.request, { email })).user_id).toBeGreaterThan(0);
  const member = await browser.newContext();
  const memberPage = await member.newPage();
  await signIn(memberPage, email, password);
  await asOwner(page);
  try {
    await erasure(page.request, { action: 'close' });
    await memberPage.goto('/members/home/');
    await expect(memberPage.getByRole('heading', { name: 'Lifeline Daily is closed' })).toBeVisible();
    const done = await erasure(page.request, { action: 'reapply', list, since });
    expect(done.problems, JSON.stringify(done)).toEqual([]);
    expect(done.closed).toBe(false);
  } finally {
    await erasure(page.request, { action: 'open' });
  }
  const gone = await scanOf(page.request, { member_id: back.member_id, email });
  expect(gone.problems, JSON.stringify(gone)).toEqual([]);
  expect(gone.user_id).toBe(0);
  await memberPage.goto('/members/home/');
  await expect(memberPage.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await member.close();
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
