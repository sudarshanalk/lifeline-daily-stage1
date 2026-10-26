import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { createMember, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const memberPass = 'adult-pass';
const columns = JSON.parse(readFileSync(new URL('../personal-columns.json', import.meta.url), 'utf8'));
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';

const jpeg = Buffer.from(
  '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==',
  'base64'
);

function fileOf(name, size) {
  const path = join(tmpdir(), name);
  const body = Buffer.concat([jpeg, Buffer.alloc(Math.max(0, size - jpeg.length))]);
  writeFileSync(path, body);
  return path;
}

async function reset(request, email) {
  const res = await request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  expect(res.ok()).toBe(true);
}

async function mailBody(email, includes) {
  let last = '';
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    const messages = (list.messages || []).filter((message) => JSON.stringify(message).includes(email));
    messages.sort((a, b) => new Date(b.Created || b.created || 0) - new Date(a.Created || a.created || 0));
    for (const message of messages) {
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      last = `${full.Subject || ''}\n${full.Text || full.HTML || ''}`;
      if (!includes || last.includes(includes)) return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No mail for ${email}. Last: ${last.slice(0, 180)}`);
}

function linkFrom(body) {
  const found = body.match(/https?:\/\/\S+/);
  if (!found) throw new Error(`No link in ${body.slice(0, 180)}`);
  return found[0].replace(/[).,>]+$/, '');
}

async function mailCount(email, includes) {
  const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  let count = 0;
  for (const message of list.messages || []) {
    if (!JSON.stringify(message.To || []).includes(email)) continue;
    const id = message.ID || message.Id || message.id;
    const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
    if (`${full.Subject || ''}\n${full.Text || ''}`.includes(includes)) count += 1;
  }
  return count;
}

// UTC "Y-m-d H:i:s" a given number of milliseconds from now, for the per-member test clock.
function fromNow(ms) {
  return new Date(Date.now() + ms).toISOString().slice(0, 19).replace('T', ' ');
}

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;

async function register(page, email) {
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Tina');
  await page.locator('#lld_last').fill('One');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(memberPass);
  await page.locator('#lld_age').fill('30');
  await page.getByRole('button', { name: 'Create account' }).click();
}

// One sign-in attempt on the members page, without waiting to leave it.
async function trySignIn(page, email, pass) {
  await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(pass);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

// WordPress's own sign-in form. Returns whether WordPress set a signed-in cookie.
async function wpLogin(request, email, pass) {
  const res = await request.post('/wp-login.php', {
    form: { log: email, pwd: pass, 'wp-submit': 'Log In', redirect_to: '/members/home/' },
    maxRedirects: 0,
  });
  const cookies = res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value).join('\n');
  return { signedIn: /wordpress_logged_in_[^=]+=[^;+\s]/.test(cookies), body: await res.text() };
}

async function setClock(request, email, utc) {
  const res = await request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc } });
  expect(res.ok(), await res.text()).toBe(true);
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

test('TC-FR2-01-AS-01', async ({ page }) => {
  const email = 'tm-01@test.lifelinedaily.invalid';
  const unknown = 'nobody-tm01@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await register(page, email);
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
  expect(page.url()).not.toContain('tm-01');
  // Refused before confirming: the members page, and WordPress's own form.
  await trySignIn(page, email, memberPass);
  await expect(page.getByText('Confirm your email before you sign in.')).toBeVisible();
  expect((await wpLogin(page.request, email, memberPass)).signedIn).toBe(false);
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

  const body = await mailBody(email, 'Confirm your Lifeline Daily email');
  await page.goto(linkFrom(body));
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible();
  // The link confirms the email but never signs anyone in.
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await page.goto(linkFrom(body));
  await expect(page.getByText('Already confirmed')).toBeVisible();

  // Resend gives one reply for every address, and sends nothing to a confirmed account.
  const before = await mailCount(email, 'Confirm your Lifeline Daily email');
  const replies = [];
  for (const address of [email, unknown]) {
    await page.goto('/members/confirm/');
    await page.locator('#lld_email').fill(address);
    await page.getByRole('button', { name: 'Resend' }).click();
    replies.push((await page.locator('.lld-info, .lld-fail').allInnerTexts()).join(' | '));
  }
  expect(replies[0]).toContain('If this email is waiting to be confirmed');
  expect(replies[1]).toBe(replies[0]);
  await new Promise((resolve) => setTimeout(resolve, 1500));
  expect(await mailCount(email, 'Confirm your Lifeline Daily email')).toBe(before);

  // After confirming, the password signs in, on both forms.
  expect((await wpLogin(page.request, email, memberPass)).signedIn).toBe(true);
  await page.context().clearCookies();
  await trySignIn(page, email, memberPass);
  await expect(page.getByRole('heading', { name: 'Sign in' })).toHaveCount(0);

  // A fresh link is refused 25 hours later.
  await reset(page.request, email);
  await register(page, email);
  const fresh = await mailBody(email, 'expires in 24 hours');
  await setClock(page.request, email, fromNow(25 * HOUR));
  await page.goto(linkFrom(fresh));
  await expect(page.getByText('That link has expired.')).toBeVisible();
});

test('TC-FR2-01-AS-02', async ({ page }) => {
  const email = 'tm-01b@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await page.goto('/members/join/');
  await page.locator('#lld_password').fill('123456789');
  await page.locator('#lld_age').fill('30');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_first').fill('Tina');
  await page.locator('#lld_last').fill('One');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByText('Use at least 10 characters.')).toBeVisible();
  await page.locator('#lld_password').fill(memberPass);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
  for (const target of ['/members/join/', '/members/sign-in/']) {
    await page.goto(target);
    const eye = page.locator('.lld-eye').first();
    await eye.click();
    await expect(eye).toHaveAttribute('aria-pressed', 'true');
    await expect(eye).toHaveText('Hide password');
  }
  await page.goto('/members/reset/');
  await page.locator('#lld_email').fill(email);
  await page.getByRole('button', { name: 'Send link' }).click();
  await page.goto(linkFrom(await mailBody(email, 'Reset your Lifeline Daily password')));
  const resetEye = page.locator('.lld-eye');
  await resetEye.click();
  await expect(resetEye).toHaveAttribute('aria-pressed', 'true');
  await expect(resetEye).toHaveText('Hide password');
  const body = await mailBody(email, 'Confirm your Lifeline Daily email');
  await page.goto(linkFrom(body));
  await page.getByRole('link', { name: 'Continue' }).click();
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(memberPass);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Agreements' })).toBeVisible();
  await page.getByRole('checkbox', { name: /I accept the/ }).check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.goto('/members/settings/');
  const change = page.locator('.lld-eye');
  await change.click();
  await expect(change).toHaveAttribute('aria-pressed', 'true');
  await expect(change).toHaveText('Hide password');
});

test('TC-FR2-01-AS-03', async ({ page }) => {
  const email = 'tm-03@test.lifelinedaily.invalid';
  const unknown = 'nobody-c2@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(unknown);
  await page.locator('#lld_password').fill('wrong-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  const unknownMessage = await page.locator('.lld-fail').innerText();
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill('wrong-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('.lld-fail')).toHaveText(unknownMessage);
  for (let i = 0; i < 4; i += 1) {
    await page.locator('#lld_password').fill('wrong-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
  }
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Sign-in is paused')).toBeVisible();
  await setClock(page.request, email, '2027-06-01 12:00:00');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
});

test('TC-FR2-01-AS-04', async ({ browser, page }) => {
  const email = 'tm-03b@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  const other = await browser.newContext();
  const otherPage = await other.newPage();
  await signIn(otherPage, email, password);
  await expect(otherPage.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.goto('/members/reset/');
  await page.locator('#lld_email').fill(email);
  await page.getByRole('button', { name: 'Send link' }).click();
  await expect(page.getByText("If an account exists, we've sent a link")).toBeVisible();
  await page.locator('#lld_email').fill('missing-c2@test.lifelinedaily.invalid');
  await page.getByRole('button', { name: 'Send link' }).click();
  await expect(page.getByText("If an account exists, we've sent a link")).toBeVisible();
  const body = await mailBody(email, 'Reset your Lifeline Daily password');
  const url = linkFrom(body);
  await page.goto(url);
  await page.locator('#lld_password').fill(memberPass);
  await page.getByRole('button', { name: 'Save password' }).click();
  await expect(page.getByText('Other devices have been signed out.')).toBeVisible();
  await page.goto(url);
  await expect(page.getByText(/expired|Already confirmed/)).toBeVisible();
  await reset(page.request, 'tm-03c@test.lifelinedaily.invalid');
  await createMember(page.request, { email: 'tm-03c@test.lifelinedaily.invalid', password, first_name: 'Sam', role: 'member' });
  await page.goto('/members/reset/');
  await page.locator('#lld_email').fill('tm-03c@test.lifelinedaily.invalid');
  await page.getByRole('button', { name: 'Send link' }).click();
  const later = await mailBody('tm-03c@test.lifelinedaily.invalid', 'expires in 60 minutes');
  await setClock(page.request, 'tm-03c@test.lifelinedaily.invalid', '2028-01-01 00:00:00');
  await page.goto(linkFrom(later));
  await expect(page.getByText('That link has expired.')).toBeVisible();
  await otherPage.goto('/members/home/');
  await expect(otherPage.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await other.close();
});

test('TC-FR2-01-AS-05', async ({ page }) => {
  const young = 'tm-01c@test.lifelinedaily.invalid';
  const adult = 'tm-01d@test.lifelinedaily.invalid';
  await reset(page.request, young);
  await reset(page.request, adult);
  await page.goto('/members/join/');
  await expect(page.getByText('Lifeline Daily relies on the age you give us. Content and results are based on it.')).toBeVisible();
  await page.locator('#lld_first').fill('Young');
  await page.locator('#lld_last').fill('One');
  await page.locator('#lld_email').fill(young);
  await page.locator('#lld_password').fill(memberPass);
  await page.locator('#lld_age').fill('17');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByText('Membership is for adults.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Free calculators' })).toBeVisible();
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { email: young } });
  expect((await scan.json()).user_id).toBe(0);
  await page.locator('#lld_email').fill(adult);
  await page.locator('#lld_password').fill(memberPass);
  await page.locator('#lld_age').fill('18');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
});

test('TC-FR2-01-AS-06', async ({ browser, page }) => {
  const email = 'tm-03d@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await setClock(page.request, email, '2026-01-01 09:00:00');
  await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(password);
  await page.getByRole('checkbox', { name: 'Remember me for 30 days' }).check();
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await setClock(page.request, email, '2026-01-30 09:00:00');
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await setClock(page.request, email, '2026-01-31 09:01:00');
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  const brief = await browser.newContext();
  const briefPage = await brief.newPage();
  await briefPage.goto('/members/sign-in/');
  await briefPage.locator('#lld_email').fill(email);
  await briefPage.locator('#lld_password').fill(password);
  await briefPage.getByRole('button', { name: 'Sign in' }).click();
  await expect(briefPage.getByRole('heading', { name: 'Today' })).toBeVisible();
  const closed = await browser.newContext();
  const again = await closed.newPage();
  await again.goto('/members/home/');
  await expect(again.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await brief.close();
  await closed.close();
});
