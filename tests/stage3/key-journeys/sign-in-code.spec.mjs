// Email codes at sign-up and sign-in (P3-21: FR3-03, FR3-04), live on the CI WordPress. The sign_in_code switch is at
// test_accounts there (tests/ci/stage2-wp.sh), so the code pages open with ?code=1 for the listed addresses and the
// Stage 2 suite keeps its confirmation link. Mailpit catches every code.
import { expect, test } from '@playwright/test';
import { createMember, password, signIn } from './helpers.mjs';

const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const A = 'p3-signin-a@test.lifelinedaily.invalid'; // sign-up with a code
const B = 'p3-signin-b@test.lifelinedaily.invalid'; // sign-in with a code
const C = 'p3-signin-c@test.lifelinedaily.invalid'; // the hourly limit

/** The newest code emailed to an address, with that message's id so a later code is told apart. */
async function latestCode(email, after = 0) {
  let found = null;
  await expect.poll(async () => {
    const messages = (await (await fetch(`${MAILPIT}/api/v1/messages?limit=50`)).json()).messages || [];
    const message = messages.find((row) => JSON.stringify(row.To || row).toLowerCase().includes(email) && new Date(row.Created).getTime() > after);
    if (!message) return false;
    const body = await (await fetch(`${MAILPIT}/api/v1/message/${message.ID}`)).json();
    const code = `${body.Text || ''}`.match(/\b(\d{6})\b/)?.[1] || '';
    if (!code) return false;
    found = { code, subject: body.Subject, text: body.Text || '', created: new Date(message.Created).getTime() };
    return true;
  }, { timeout: 20000 }).toBe(true);
  return found;
}

async function enterCode(page, code) {
  await page.locator('#lld_code').fill(code);
  await page.locator('#lld_code').press('Enter');
}

test.describe.serial('email codes', () => {
  test('TC-FR3-03-AS-01', async ({ page }) => {
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    await page.goto('/members/join/?code=1');
    await expect(page.getByRole('heading', { name: 'Create account', exact: true })).toBeVisible();
    await page.locator('#lld_first').fill('Synthetic'); await page.locator('#lld_last').fill('Member');
    await page.locator('#lld_email').fill(A); await page.locator('#lld_password').fill(password); await page.locator('#lld_age').fill('30');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Check your email', exact: true })).toBeVisible();
    await expect(page.getByText(`We've emailed a 6-digit code to ${A}`)).toBeVisible();
    const mail = await latestCode(A);
    expect(mail.subject).toBe('Your Lifeline Daily sign-up code');
    expect(mail.text).toContain('no account will be created');
    // No account exists until the code is right (EM-22's promise): the password typed at sign-up does not sign in yet.
    await page.goto('/members/sign-in/');
    await page.locator('#lld_email').fill(A); await page.locator('#lld_password').fill(password);
    await page.locator('#lld_password').press('Enter');
    await expect(page.getByRole('alert')).toContainText("Those details don't match our records.");
  });

  test('TC-FR3-03-AS-03', async ({ page }) => {
    // Five wrong codes void the code, and a new one must be asked for.
    const D = 'p3-signin-d@test.lifelinedaily.invalid';
    await page.goto('/members/join/?code=1');
    await page.locator('#lld_first').fill('Synthetic'); await page.locator('#lld_last').fill('Member');
    await page.locator('#lld_email').fill(D); await page.locator('#lld_password').fill(password); await page.locator('#lld_age').fill('30');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Check your email', exact: true })).toBeVisible();
    const mail = await latestCode(D);
    const wrong = mail.code === '000000' ? '111111' : '000000';
    for (let i = 1; i <= 4; i++) {
      await enterCode(page, wrong);
      await expect(page.getByRole('alert')).toContainText('That code does not match');
    }
    await enterCode(page, wrong);
    await expect(page.getByRole('alert')).toContainText('Too many wrong tries. Ask for a new code.');
    // The right code is void now too.
    await enterCode(page, mail.code);
    await expect(page.getByRole('alert')).toContainText('Too many wrong tries');
    // A new code works and signs the member in, straight to the Agreements step (AS-02).
    await page.getByRole('button', { name: 'Send a new code', exact: true }).click();
    await expect(page.getByText('We emailed a new code.')).toBeVisible();
    const fresh = await latestCode(D, mail.created);
    expect(fresh.code).not.toBe(mail.code);
    await enterCode(page, fresh.code);
    await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
  });

  test('TC-FR3-03-AS-02', async ({ page }) => {
    const E = 'p3-signin-e@test.lifelinedaily.invalid';
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    await page.goto('/members/join/?code=1');
    await page.locator('#lld_first').fill('Synthetic'); await page.locator('#lld_last').fill('Member');
    await page.locator('#lld_email').fill(E); await page.locator('#lld_password').fill(password); await page.locator('#lld_age').fill('30');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    const mail = await latestCode(E);
    await enterCode(page, mail.code);
    await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
    // Signed in, email confirmed, no second sign-in: the member home opens.
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/home\//);
    // The password typed at sign-up works too (FR3-04 AS-04 for a code-created account).
    await signIn(page, E, password);
    await expect(page).toHaveURL(/\/members\/home\//);
  });

  test('TC-FR3-04-AS-01', async ({ page }) => {
    await createMember(page.request, { email: B, password, first_name: 'Synthetic', consents: ['terms'] });
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    await page.goto('/members/sign-in/?code=1');
    await expect(page.getByRole('heading', { name: 'Sign in', exact: true })).toBeVisible();
    await page.locator('#lld_email').fill(B);
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Enter your code', exact: true })).toBeVisible();
    const mail = await latestCode(B);
    expect(mail.subject).toBe('Your Lifeline Daily sign-in code');
    expect(mail.text).not.toMatch(/weight|kg|glucose|blood/i);
    await enterCode(page, mail.code);
    await expect(page).toHaveURL(/\/members\/home\//);
  });

  test('TC-FR3-04-AS-02', async ({ page }) => {
    // The same code a second time: refused (it was used), and a code older than its life is refused the same way (AS-04).
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    const before = Date.now();
    await page.goto('/members/sign-in/?code=1');
    await page.locator('#lld_email').fill(B);
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    const mail = await latestCode(B, before - 1000);
    await enterCode(page, mail.code);
    await expect(page).toHaveURL(/\/members\/home\//);
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    await page.goto('/members/sign-in/?code=1');
    await page.locator('#lld_email').fill(B);
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Enter your code', exact: true })).toBeVisible();
    await enterCode(page, mail.code);
    await expect(page.getByRole('alert')).toContainText('That code does not match');
    await expect(page).toHaveURL(/\/members\/sign-in\//);
  });

  test('TC-FR3-04-AS-03', async ({ page }) => {
    await createMember(page.request, { email: C, password, first_name: 'Synthetic', consents: ['terms'] });
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await page.context().clearCookies();
    for (let i = 1; i <= 5; i++) {
      await page.goto('/members/sign-in/?change=1');
      await page.goto('/members/sign-in/?code=1');
      await page.locator('#lld_email').fill(C);
      await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
      await expect(page.getByRole('heading', { name: 'Enter your code', exact: true })).toBeVisible();
    }
    await page.goto('/members/sign-in/?change=1');
    await page.goto('/members/sign-in/?code=1');
    await page.locator('#lld_email').fill(C);
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText("You've asked for several codes in the last hour. Please wait a while and try again, or sign in with your password.");
  });

  test('TC-FR3-04-AS-04', async ({ page }) => {
    // Password sign-in still works for the same accounts, on the Stage 3 page and through the Stage 2 helper.
    await signIn(page, B, password);
    await expect(page).toHaveURL(/\/members\/home\//);
    await signIn(page, C, password);
    await expect(page).toHaveURL(/\/members\/home\//);
  });
});
