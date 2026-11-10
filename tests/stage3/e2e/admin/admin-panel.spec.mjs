// P3-87 and P3-87b: the admin module in My account → Admin, live on the CI WordPress (FR3-71 to FR3-95, NFR3-19 to
// NFR3-24, FR5-30). The Owner signs in through the Stage 2 test route; the Support helper signs in for real at
// wp-login.php with the emailed code. Stripe is not connected in CI (no keys), so the Stripe side of pause, block and
// end is checked when the owner's Stripe test keys are on staging (L7); here the site's own state is checked.
import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createMember, asOwner, mailCode, leanAdmin, ADMIN } from '../../../stage2/e2e/helpers.mjs';
import { password } from '../../key-journeys/helpers.mjs';

const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const stamp = randomBytes(3).toString('hex');
const mail = (part) => `p87-${part}-${stamp}@test.lifelinedaily.invalid`;
const HELPER = mail('helper');
// A value no admin page may show (NFR3-19): stored as the member's sealed "something else" health answer.
const SENTINEL = `SENTINEL-HEALTH-${stamp}`;

const A = '/members/admin/';
const at = (page, args = '') => `${A}${page ? `${page}/` : ''}${args ? `?${args}` : ''}`;
const memberPage = (id) => at('members', `member=${id}`);
const PAGES = ['', 'members', 'tools', 'features', 'activity', 'helpers', 'payments', 'report', 'group', 'ai'];

async function admin(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/admin', { data });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function open(page, path) {
  await page.goto(path);
}

async function wpAdmin(page, path) {
  await leanAdmin(page);
  await page.goto(path, ADMIN);
  if (page.url().includes('pmpro-wizard')) await page.goto(path, ADMIN);
}

/** Open a collapsed action on the page by its title. */
async function action(page, title) {
  const summary = page.locator('details.lld-adm-action > summary', { hasText: new RegExp(`^${title}$`) }).first();
  await summary.click();
  return summary.locator('xpath=..');
}

const saved = (page) => page.locator('[data-lld-adm-notice]');
const refused = (page) => page.locator('[data-lld-adm-notice].is-error');

async function messagesTo(email) {
  const list = await fetch(`${MAILPIT}/api/v1/search?limit=1000&query=${encodeURIComponent(`to:"${email}"`)}`).then((r) => r.json());
  return list.messages || [];
}

async function waitForMail(email, subjectPart) {
  for (let i = 0; i < 20; i += 1) {
    const found = (await messagesTo(email)).find((m) => (m.Subject || '').includes(subjectPart));
    if (found) return found;
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`No "${subjectPart}" email for ${email}`);
}

/** The newest code email for this address since `after` (alerts to the same inbox, such as "new browser", are passed over). */
async function codeFor(email, after) {
  for (let i = 0; i < 20; i += 1) {
    const found = (await messagesTo(email))
      .filter((m) => /code/i.test(m.Subject || '') && new Date(m.Created) >= after)
      .sort((a, b) => new Date(b.Created) - new Date(a.Created))[0];
    if (found) {
      const full = await fetch(`${MAILPIT}/api/v1/message/${found.ID}`).then((r) => r.json());
      const code = (full.Text || '').match(/\b(\d{6})\b/);
      if (code) return code[1];
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`No code email for ${email}`);
}

/** Ask for and read a fresh code for a risky action (NFR3-20). */
async function freshCode(page, ownerEmail, scope = page) {
  const after = new Date(Date.now() - 2000);
  await scope.getByRole('button', { name: 'Email me a code' }).first().click();
  await expect(saved(page)).toContainText('We\'ve emailed you a 6-digit code');
  return codeFor(ownerEmail, after);
}

/**
 * Open wp-login.php and fill it. WordPress's own login script focuses the username box 200 ms after the page is
 * parsed, which can land after Playwright's "load" and in the middle of the password fill: the password is then typed
 * into the username box, the required password box stays empty and the browser never sends the form (WebKit, and
 * Chromium too, about once in 100 sign-ins). So wait for that focus first, and check both boxes before submitting.
 */
async function fillWpLogin(page, login, pass) {
  await page.goto('/wp-login.php');
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(login);
  await page.locator('#user_pass').fill(pass);
  await expect(page.locator('#user_login')).toHaveValue(login);
  await expect(page.locator('#user_pass')).toHaveValue(pass);
}

async function supportSignIn(page, context) {
  await admin(page.request, { op: 'clear_spam' });
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await context.clearCookies();
  const base = test.info().project.use.baseURL || 'http://127.0.0.1:8090';
  await context.addCookies([{ name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base }]);
  await fillWpLogin(page, HELPER, password);
  await page.locator('#wp-submit').click();
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  await page.locator('#lld_code').fill(await mailCode(HELPER));
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.waitForURL(/\/members\/admin\/$/);
}

/** The ids shown on the Members list. */
async function listed(page, query) {
  await open(page, at('members', query));
  return (await page.locator('.lld-adm-members .lld-adm-num').allTextContents()).map((t) => t.trim());
}

test.describe.serial('P3-87 admin module in My account', () => {
  let owner;
  let free;
  let paying;
  const freeEmail = mail('free');
  const payEmail = mail('pay');

  let savedBarcode;
  let savedHeart;
  test.beforeAll(async ({ request }) => {
    savedBarcode = await admin(request, { op: 'feature', feature: 'barcode' });
    savedHeart = await admin(request, { op: 'feature', feature: 'calc_heart_risk' });
    owner = await admin(request, { op: 'owner' });
    await admin(request, { op: 'clock', seconds: 0 });
    await admin(request, { op: 'helper', email: HELPER, password });
    free = await createMember(request, { email: freeEmail, password, first_name: `Frida${stamp}`, health_consent: true, sentinel: SENTINEL });
    const end = new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10);
    paying = await createMember(request, { email: payEmail, password, first_name: 'Pat', access_state: 'Premium', plan: 'monthly', period_ends_on: end, stripe_subscription: `sub_test_${stamp}` });
    await admin(request, { op: 'prepare', member: paying.member_id, customer: `cus_test${stamp}` });
  });

  test.afterAll(async ({ request }) => {
    await admin(request, { op: 'restore', feature: 'calc_heart_risk', row: savedHeart.row, emails: savedBarcode.emails });
    await admin(request, { op: 'restore', feature: 'barcode', row: savedBarcode.row, emails: savedBarcode.emails });
    await admin(request, { op: 'mailpage', off: true });
  });

  // ----- Two admin areas (FR3-72, FR3-87, FR3-90) -----

  test('TC-FR3-87-AS-01 The Owner\'s My account shows Admin with its nine pages', async ({ page }) => {
    await asOwner(page);
    await open(page, '/pricing/');
    // the old member header, or the v2 header when the Stage 1 mu-plugin is present (P3-60 pricing v2)
    await page.locator('.lld-header .lld-public-nav, header').getByRole('link', { name: 'Admin', exact: true }).filter({ visible: true }).first().click();
    await expect(page).toHaveURL(/\/members\/admin\/$/);
    const nav = page.locator('.lld-adm-side nav');
    for (const name of ['Overview', 'Members', 'Tools', 'Features', 'Activity log', 'Helpers', 'Payments', 'Weekly report', 'Group removals']) {
      await expect(nav.getByRole('link', { name, exact: true })).toHaveCount(1);
    }
  });

  test('TC-FR3-72-AS-01 The WordPress content menu lists the content screens and none of the moved pages', async ({ page }) => {
    await asOwner(page);
    await wpAdmin(page, '/wp-admin/admin.php?page=lld-approvals');
    const top = page.locator('#adminmenu li.toplevel_page_lld-approvals');
    await expect(top.locator('.wp-menu-name')).toHaveText('Lifeline Daily content');
    for (const name of ['Approvals', 'Safety matrix', 'Live Q&A', 'Testimonials', 'Preview']) {
      await expect(top.locator('.wp-submenu a', { hasText: new RegExp(`^${name}$`) })).toHaveCount(1);
    }
    for (const name of ['Overview', 'Members', 'Features', 'Activity log', 'Helpers', 'Payments', 'Weekly report', 'Group removals']) {
      await expect(top.locator('.wp-submenu a', { hasText: new RegExp(`^${name}$`) })).toHaveCount(0);
    }
    await expect(page.locator('#adminmenu li.toplevel_page_lld-payments, #adminmenu li.toplevel_page_lld-overview')).toHaveCount(0);
  });

  test('TC-FR3-72-AS-02 Each content screen still opens at its old address', async ({ page }) => {
    await asOwner(page);
    for (const slug of ['lld-approvals', 'lld-matrix', 'lld-qa', 'lld-quotes', 'lld-preview', 'lld-payments', 'lld-report', 'lld-group']) {
      const res = await page.goto(`/wp-admin/admin.php?page=${slug}`, ADMIN);
      expect(res.status(), slug).toBe(200);
      await expect(page.locator('#wpbody-content h1').first(), slug).toBeVisible();
      await expect(page.locator('body'), slug).not.toContainText(/not allowed to access this page|Cannot load/);
      expect(await page.locator('#wpbody-content .wrap > h1').count(), slug).toBeLessThanOrEqual(1);
    }
  });

  test('TC-FR3-72-AS-03 The old address of each moved page takes the Owner to the same page in My account', async ({ page }) => {
    await asOwner(page);
    for (const [slug, path] of [['lld-overview', ''], ['lld-members-admin', 'members/'], ['lld-features', 'features/'], ['lld-activity', 'activity/'], ['lld-helpers', 'helpers/']]) {
      await page.goto(`/wp-admin/admin.php?page=${slug}`, ADMIN);
      await expect(page, slug).toHaveURL(new RegExp(`/members/admin/${path}$`));
    }
    await page.goto(`/wp-admin/admin.php?page=lld-members-admin&member=${free.member_id}`, ADMIN);
    await expect(page).toHaveURL(new RegExp(`/members/admin/members/\\?member=${free.member_id}$`));
    for (const [path, h] of [['payments', 'Payments'], ['report', 'Weekly report'], ['group', 'Group removals']]) {
      await open(page, at(path));
      await expect(page.locator('.lld-adm-head h1')).toHaveText(h);
    }
  });

  test('TC-FR3-87-AS-04 The Owner opens the WordPress admin from Admin without signing in again, and comes back', async ({ page }) => {
    await asOwner(page);
    await open(page, A);
    await page.getByRole('link', { name: 'Content and site settings (WordPress)' }).click();
    await expect(page.locator('#adminmenu li.toplevel_page_lld-approvals')).toBeVisible();
    await page.locator('#adminmenu').getByRole('link', { name: 'Admin (in My account)' }).click();
    await expect(page).toHaveURL(/\/members\/admin\/$/);
  });

  test('TC-NFR3-20-AS-01 A Support account must enter an email code to sign in', async ({ page, context }) => {
    await supportSignIn(page, context);
    await expect(page.locator('.lld-adm-head h1')).toHaveText('Overview');
  });

  test('TC-FR3-87-AS-02 A Support account\'s Admin section shows only Overview and Members', async ({ page, context }) => {
    await supportSignIn(page, context);
    const items = await page.locator('.lld-adm-side nav a').allTextContents();
    expect(items.map((t) => t.trim())).toEqual(['Overview', 'Members']);
    await expect(page.getByRole('link', { name: 'Content and site settings (WordPress)' })).toHaveCount(0);
  });

  test('TC-FR3-90-AS-01 A Support account opening the WordPress admin is sent to My account → Admin', async ({ page, context }) => {
    await supportSignIn(page, context);
    for (const path of ['/wp-admin/', '/wp-admin/profile.php', '/wp-admin/edit.php']) {
      await page.goto(path, ADMIN);
      await expect(page, path).toHaveURL(/\/members\/admin\/$/);
    }
  });

  test('TC-FR3-90-AS-02 A Support account sees no WordPress admin bar on any page', async ({ page, context }) => {
    await supportSignIn(page, context);
    for (const path of ['/', A, at('members'), '/health-tools/']) {
      await open(page, path);
      await expect(page.locator('#wpadminbar'), path).toHaveCount(0);
    }
  });

  test('TC-FR3-73-AS-03 Support sees no payment counts and no activity log on the Overview', async ({ page, context }) => {
    await supportSignIn(page, context);
    for (const key of ['monthly', 'yearly', 'failed']) await expect(page.locator(`[data-count="${key}"]`)).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Recent admin actions' })).toHaveCount(0);
    await expect(page.locator('[data-count="total"]')).toBeVisible();
  });

  test('TC-FR3-71-AS-01 Support can search, open an account, resend a sign-in email, add a note, pause and resume', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, at('members', `q=${encodeURIComponent(payEmail)}`));
    await page.locator('.lld-adm-members a').first().click();
    await expect(page.locator('dl.lld-facts')).toContainText(payEmail);
    const resend = await action(page, 'Resend an account email');
    await resend.locator('#lld-kind').selectOption('sign_in_code');
    await resend.getByRole('button', { name: 'Resend' }).click();
    await expect(saved(page)).toContainText('Email sent.');
    await page.locator('#lld-note').fill('Asked about the renewal date by email.');
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(saved(page)).toContainText('Note added.');
    const pause = await action(page, 'Pause membership');
    await pause.locator('#lld-months').selectOption('1');
    await pause.getByRole('button', { name: 'Pause membership' }).click();
    await expect(saved(page)).toContainText('Membership paused.');
    await expect(page.locator('dl.lld-facts')).toContainText('Pause starts');
    const resume = await action(page, 'Resume membership');
    await resume.getByRole('button', { name: 'Resume membership' }).click();
    await expect(saved(page)).toContainText('Membership resumed.');
  });

  test('TC-FR3-71-AS-02 Support sees no Owner-only button, and a direct request is refused', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, memberPage(free.member_id));
    await expect(page.getByRole('heading', { name: 'Owner actions' })).toHaveCount(0);
    for (const name of ['Give free Premium', 'End membership', 'Mark as a test account', 'Block', 'Send the download link', 'Delete member', 'Make Premium test member']) {
      await expect(page.locator('details.lld-adm-action > summary', { hasText: new RegExp(`^${name}$`) })).toHaveCount(0);
    }
    await expect(page.getByRole('link', { name: /Stripe/ })).toHaveCount(0);
    // A direct post with a valid Support nonce is still refused on the server.
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    for (const doAction of ['grant', 'end', 'test', 'block', 'unblock', 'delete', 'download', 'ungrant', 'ptm', 'reset']) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: doAction, reason: 'try', ends_on: '2099-01-01', on: '1', email: freeEmail, typed: 'RESET' } });
      await open(page, memberPage(free.member_id));
      await expect(refused(page)).toContainText('Your role can\'t do that.');
    }
    const state = await admin(page.request, { op: 'member', member: free.member_id });
    expect(state.blocked).toBe(false);
    expect(state.grants).toBe(0);
  });

  test('TC-FR3-90-AS-03 Support can still add a note from My account, and an Owner-only request is refused', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, memberPage(free.member_id));
    await page.locator('#lld-note').fill(`Support note ${stamp}`);
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(saved(page)).toContainText('Note added.');
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    const res = await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: 'block', reason: 'try' } });
    expect(res.status()).toBe(302);
    expect((await admin(page.request, { op: 'member', member: free.member_id })).blocked).toBe(false);
  });

  test('TC-FR3-74-AS-05 Support cannot change any feature or the test-account list', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, at('features'));
    await expect(page.getByText('Only the Owner can change them.')).toBeVisible();
    await expect(page.locator('#lld-adm-t-barcode')).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Add' })).toHaveCount(0);
    const res = await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_feature', _lldnonce: '', key: 'barcode', reason: 'x' } });
    expect(res.status()).toBe(403);
    const state = await admin(page.request, { op: 'feature', feature: 'barcode' });
    expect(state.history.filter((h) => h.note === 'x')).toHaveLength(0);
  });

  test('TC-FR3-88-AS-05 (Support) Support sees the tool switches but cannot change them', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, at('tools'));
    await expect(page.locator('#lld-adm-t-calc_heart_risk')).toBeDisabled();
    await expect(page.locator('#lld-adm-t-protein')).toBeDisabled();
  });

  test('TC-FR3-71-AS-03 Only an Owner account can add or remove a Support helper', async ({ page, context }) => {
    await supportSignIn(page, context);
    expect((await page.request.get(at('helpers'))).status()).toBe(404);
    await asOwner(page);
    await open(page, at('helpers'));
    await expect(page.getByText(HELPER)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add helper' })).toBeVisible();
  });

  test('TC-FR5-30-AS-02 A Support account has no AI link, and the AI page is refused', async ({ page, context }) => {
    await supportSignIn(page, context);
    await expect(page.locator('.lld-adm-side').getByRole('link', { name: 'AI', exact: true })).toHaveCount(0);
    expect((await page.request.get(at('ai'))).status()).toBe(404);
  });

  test('TC-FR3-86-AS-04 Support sees only their own entries; the Owner\'s CSV matches the log', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, at('activity'));
    const whos = await page.locator('.lld-adm-log > li > span:nth-child(2) > span').allTextContents();
    expect(whos.length).toBeGreaterThan(0);
    for (const who of whos) expect(who.startsWith('Test helper')).toBe(true);
    await asOwner(page);
    await open(page, at('activity'));
    const href = await page.getByRole('link', { name: 'Download as CSV' }).getAttribute('href');
    const csv = await (await page.request.get(href)).text();
    const what = (await page.locator('.lld-adm-log > li strong').first().innerText()).split(' · ')[0];
    expect(csv.trim().split('\n')[1]).toContain(what);
  });

  // ----- Members (FR3-75 to FR3-85) -----

  test('TC-FR3-75-AS-01 Search by part of an email, a name and a member number', async ({ page }) => {
    await asOwner(page);
    for (const q of [`free-${stamp}`, `Frida${stamp}`, String(free.member_id)]) {
      expect(await listed(page, `q=${encodeURIComponent(q)}`)).toContain(`#${free.member_id}`);
    }
  });

  test('TC-FR3-75-AS-02 Each filter returns only matching members', async ({ page }) => {
    await asOwner(page);
    expect(await listed(page, `q=${stamp}&plan=premium`)).toEqual([`#${paying.member_id}`]);
    expect(await listed(page, `q=${stamp}&plan=free`)).toEqual([`#${free.member_id}`]);
    expect(await listed(page, `q=${stamp}&status=blocked`)).toEqual([]);
    expect(await listed(page, `q=${stamp}&test=yes`)).toEqual([]);
  });

  test('TC-FR3-76-AS-01 The member page shows each account detail', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const facts = page.locator('dl.lld-facts');
    for (const label of ['Email', 'Sign-in methods', 'Joined', 'Last sign-in', 'Plan', 'Status', 'Renewal date', 'Payment status', 'Test account', 'AI allowance']) {
      await expect(facts.locator('dt', { hasText: new RegExp(`^${label}$`) })).toHaveCount(1);
    }
    await expect(page.locator('.lld-adm-head h1')).toContainText('Pat');
    await expect(facts).toContainText(payEmail);
    await expect(facts).toContainText('Premium, monthly');
  });

  test('TC-FR3-76-AS-02 The member page shows none of the member\'s health data', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    expect(await page.content()).not.toContain(SENTINEL);
    const text = await page.locator('[data-lld-admin]').innerText();
    for (const word of ['Healthy Weight', 'Blood Sugar', 'diary entry', 'calculator result']) expect(text).not.toContain(word);
  });

  test('TC-FR3-76-AS-03 No admin page offers to sign in as, or view the site as, a member', async ({ page }) => {
    await asOwner(page);
    for (const path of [A, at('members'), memberPage(free.member_id)]) {
      await open(page, path);
      await expect(page.locator('[data-lld-admin]')).not.toContainText(/sign in as|log in as|switch to|view (the site )?as/i);
    }
  });

  test('TC-FR3-77-AS-01 Resend each email type: it is caught and logged', async ({ page, request }) => {
    await asOwner(page);
    const unconfirmed = await createMember(request, { email: mail('unconf'), password, first_name: 'Uma' });
    await admin(request, { op: 'prepare', member: unconfirmed.member_id, unconfirm: true });
    await open(page, memberPage(free.member_id));
    let box = await action(page, 'Resend an account email');
    await box.locator('#lld-kind').selectOption('welcome');
    await box.getByRole('button', { name: 'Resend' }).click();
    await expect(refused(page)).toContainText('has not chosen to get the welcome email');
    await admin(request, { op: 'prepare', member: free.member_id, welcome: true });
    for (const [kind, subject, who] of [['sign_in_code', 'sign-in code', free], ['welcome', 'Welcome', free], ['confirm', 'onfirm', unconfirmed]]) {
      await open(page, memberPage(who.member_id));
      box = await action(page, 'Resend an account email');
      await box.locator('#lld-kind').selectOption(kind);
      await box.getByRole('button', { name: 'Resend' }).click();
      await expect(saved(page)).toContainText('Email sent.');
      await waitForMail(who === free ? freeEmail : mail('unconf'), subject);
    }
    const log = await admin(request, { op: 'log', member: free.member_id });
    expect(log.rows.filter((r) => r.action === 'member_resend').map((r) => r.target)).toEqual(expect.arrayContaining(['sign_in_code', 'welcome']));
  });

  test('TC-FR3-78-AS-01 A note shows with who and when, and never to the member', async ({ page, context }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    await page.locator('#lld-note').fill(`Called about sign-in ${stamp}`);
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(page.locator('.lld-note').first()).toContainText(`Called about sign-in ${stamp}`);
    await expect(page.locator('.lld-note').first()).toContainText('UTC');
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await context.clearCookies();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    await signIn(page, freeEmail, password);
    for (const path of ['/members/home/', '/members/settings/']) {
      await page.goto(path);
      expect(await page.content()).not.toContain(`Called about sign-in ${stamp}`);
    }
  });

  test('TC-FR3-78-AS-02 The note box says: No health details in notes', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    await expect(page.locator('#lld-note-hint')).toContainText('No health details in notes');
    await expect(page.locator('#lld-note')).toHaveAttribute('aria-describedby', 'lld-note-hint');
  });

  test('TC-FR3-79-AS-01 Pause a monthly member for 1 month with the FR3-65 rules', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const box = await action(page, 'Pause membership');
    await box.locator('#lld-months').selectOption('1');
    await box.getByRole('button', { name: 'Pause membership' }).click();
    await expect(saved(page)).toContainText('Membership paused.');
    const end = new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10);
    await expect(page.locator('dl.lld-facts')).toContainText(`Pause starts ${end}`);
    const log = await admin(request, { op: 'log', member: paying.member_id });
    expect(log.rows.filter((r) => r.action === 'member_pause').length).toBeGreaterThanOrEqual(1);
  });

  test('TC-FR3-79-AS-02 Resume the member: the pause is gone and Premium stays', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const box = await action(page, 'Resume membership');
    await box.getByRole('button', { name: 'Resume membership' }).click();
    await expect(saved(page)).toContainText('Membership resumed.');
    await expect(page.locator('dl.lld-facts')).toContainText('Active');
    expect((await admin(request, { op: 'member', member: paying.member_id })).premium).toBe(true);
  });

  test('TC-FR3-80-AS-03 Giving or removing Premium is refused without a reason', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: 'grant', ends_on: '2099-01-01', reason: '' } });
    await open(page, memberPage(free.member_id));
    await expect(refused(page)).toContainText('Give a reason');
    expect((await admin(page.request, { op: 'member', member: free.member_id })).grants).toBe(0);
  });

  test('TC-FR3-80-AS-01 Give a Free member Premium until a date: Premium at once, no Stripe subscription', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const until = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    const box = await action(page, 'Give free Premium');
    await box.locator('#lld-grant-end').fill(until);
    await box.locator('#lld-grant-reason').fill('Competition prize');
    await box.getByRole('button', { name: 'Give free Premium' }).click();
    await expect(saved(page)).toContainText(`Free Premium given until ${until}.`);
    await expect(page.locator('dl.lld-facts')).toContainText('Premium (free, given by the Owner)');
    await expect(page.locator('dl.lld-facts')).toContainText('No payments');
    const granted = await admin(request, { op: 'member', member: free.member_id });
    expect(granted.premium).toBe(true);
    expect(granted.state).toBe('Premium');
  });

  test('TC-FR3-80-AS-02 On the end date the member returns to Free with nothing deleted', async ({ request }) => {
    await admin(request, { op: 'grant_end', member: free.member_id });
    const state = await admin(request, { op: 'member', member: free.member_id });
    expect(state.premium).toBe(false);
    expect(state.grants).toBe(1);
    expect(state.notes).toBeGreaterThan(0);
  });

  test('TC-FR3-81-AS-03 A paying member\'s page: the Stripe link for the Owner, no refund button for anyone', async ({ page, context }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    await expect(page.getByRole('link', { name: 'Open this member\'s customer page in Stripe' })).toHaveAttribute('href', new RegExp(`dashboard\\.stripe\\.com/(test/)?customers/cus_test${stamp}$`));
    await expect(page.getByRole('button', { name: /refund/i })).toHaveCount(0);
    await supportSignIn(page, context);
    await open(page, memberPage(paying.member_id));
    await expect(page.getByRole('link', { name: /Stripe/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /refund/i })).toHaveCount(0);
  });

  test('TC-FR3-81-AS-01 End at the end of the period: the member keeps Premium until then', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const box = await action(page, 'End membership');
    await box.locator('#lld-end-reason').fill('Member asked to cancel');
    await box.getByRole('button', { name: 'End membership' }).click();
    await expect(saved(page)).toContainText('Membership ends at the end of the paid period.');
    await expect(page.locator('dl.lld-facts')).toContainText('Cancelling at the end of the period');
    expect((await admin(request, { op: 'member', member: paying.member_id })).premium).toBe(true);
  });

  test('TC-FR3-81-AS-02 End now: Premium stops at once', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const box = await action(page, 'End membership');
    await box.locator('input[name="when"][value="now"]').check();
    await box.locator('#lld-end-reason').fill('Chargeback');
    await box.getByRole('button', { name: 'End membership' }).click();
    await expect(saved(page)).toContainText('Membership ended now.');
    expect((await admin(request, { op: 'member', member: paying.member_id })).premium).toBe(false);
  });

  test('TC-FR3-82-AS-01 Mark a member as a test account: labelled Test, out of the counts, on the Features list', async ({ page }) => {
    await asOwner(page);
    await open(page, A);
    const before = Number((await page.locator('[data-count="total"] strong').innerText()).replace(/,/g, ''));
    await open(page, memberPage(free.member_id));
    const box = await action(page, 'Mark as a test account');
    await box.locator('#lld-test-reason').fill('Staging check');
    await box.getByRole('button', { name: 'Mark as a test account' }).click();
    await expect(saved(page)).toContainText('Marked as a test account.');
    await expect(page.locator('.lld-adm-head .lld-adm-badge.is-test')).toHaveText('Test account');
    await open(page, A);
    expect(Number((await page.locator('[data-count="total"] strong').innerText()).replace(/,/g, ''))).toBe(before - 1);
    await open(page, at('features'));
    await expect(page.locator('#test-accounts')).toContainText(freeEmail);
  });

  test('TC-FR3-74-AS-04 An email added to the test-account list on the page is a test account', async ({ page }) => {
    await asOwner(page);
    expect(await listed(page, `q=${stamp}&test=yes`)).toEqual([`#${free.member_id}`]);
    await open(page, at('features'));
    await page.locator('#lld-ta-email').fill(payEmail);
    await page.locator('#lld-ta-reason').fill('Add the paying test member');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(saved(page)).toContainText('Test account added.');
    expect(await listed(page, `q=${stamp}&test=yes`)).toEqual([`#${paying.member_id}`, `#${free.member_id}`]);
  });

  // ----- Switches (FR3-74, FR3-88, FR3-89, FR3-94) -----

  test('TC-FR3-74-AS-01 Turn a feature off: the change appears in its history with the reason', async ({ page, request }) => {
    const now = await admin(request, { op: 'feature', feature: 'barcode' });
    await admin(request, { op: 'restore', feature: 'barcode', row: { state: 'everyone', approved_by: 'spec', changed_at: '', override: false }, emails: now.emails });
    await asOwner(page);
    await open(page, at('features'));
    const row = page.locator('#tool-barcode');
    await row.locator('summary', { hasText: 'Add a reason' }).click();
    await row.locator('input[name="reason"]').fill(`Scanner bug ${stamp}`);
    await row.locator('#lld-adm-t-barcode').uncheck();
    await expect(saved(page)).toContainText('Barcode scanning is off.');
    const state = await admin(request, { op: 'feature', feature: 'barcode' });
    expect(state.state).toBe('off');
    expect(state.history[0]).toMatchObject({ to: 'off', note: `Scanner bug ${stamp}` });
  });

  test('TC-FR3-74-AS-02 A toggle saves at once with or without a reason, shows Saved with Undo, and is recorded', async ({ page, request }) => {
    await asOwner(page);
    await open(page, at('features'));
    await page.locator('#lld-adm-t-barcode').check();
    await expect(saved(page)).toContainText('Saved · Barcode scanning is on.');
    await expect(saved(page).getByRole('button', { name: 'Undo' })).toBeVisible();
    const state = await admin(request, { op: 'feature', feature: 'barcode' });
    expect(state.state).toBe('everyone');
    expect(state.history[0]).toMatchObject({ from: 'off', to: 'everyone' });
    const log = await admin(request, { op: 'log', target: 'barcode' });
    expect(log.rows.at(-1)).toMatchObject({ action: 'feature_on', after_state: 'everyone' });
  });

  test('TC-FR3-74-AS-07 Undo straight after a change puts the feature back and records that too', async ({ page, request }) => {
    await asOwner(page);
    await open(page, at('features'));
    await page.locator('#lld-adm-t-barcode').uncheck();
    await expect(saved(page)).toContainText('Barcode scanning is off.');
    await saved(page).getByRole('button', { name: 'Undo' }).click();
    await expect(saved(page)).toContainText('Barcode scanning is on again.');
    const state = await admin(request, { op: 'feature', feature: 'barcode' });
    expect(state.state).toBe('everyone');
    expect(state.history[0]).toMatchObject({ to: 'everyone', note: 'Undo' });
  });

  test('TC-FR3-74-AS-06 The toggle offers only on and off: no "test accounts only" choice', async ({ page }) => {
    await asOwner(page);
    for (const path of [at('features'), at('tools')]) {
      await open(page, path);
      // The controls offer on and off only; the history may still record earlier command-line states.
      await expect(page.locator('[data-lld-admin]').getByRole('button', { name: /test accounts/i })).toHaveCount(0);
      await expect(page.locator('[data-lld-admin]').getByRole('radio')).toHaveCount(0);
      await expect(page.locator('[data-lld-admin] input[name="state"], [data-lld-admin] select[name="state"]')).toHaveCount(0);
    }
  });

  test('TC-FR3-94-AS-01 A switch change clears the page cache', async ({ page }) => {
    await asOwner(page);
    await open(page, at('features'));
    const nonce = await page.locator('#tool-barcode input[name="_lldnonce"]').inputValue();
    for (const on of ['', '1']) { // a real change each time: off, then back on
      const res = await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_feature', _lldnonce: nonce, key: 'barcode', on } });
      expect(res.status()).toBe(302);
      expect(res.headers()['x-litespeed-purge']).toBe('*');
    }
  });

  test('TC-FR3-88-AS-01 The Tools page lists the 11 tools with their state', async ({ page, request }) => {
    await asOwner(page);
    await open(page, at('tools'));
    for (const name of ['Daily Protein Target', 'Diabetes Risk Check', 'Healthy Weight Check', 'Heart Risk Check', 'Blood Pressure Check', 'Metabolic Health Check', 'Fitness Age', 'Heart Rate Zones', 'Sleep and Stress Check', 'Calorie Target', 'Goal Timeline']) {
      await expect(page.getByRole('switch', { name, exact: true })).toHaveCount(1);
    }
    const heart = await admin(request, { op: 'feature', feature: 'calc_heart_risk' });
    if (heart.state === 'off') await expect(page.locator('#lld-adm-t-calc_heart_risk')).not.toBeChecked();
    else await expect(page.locator('#lld-adm-t-calc_heart_risk')).toBeChecked();
  });

  test('TC-FR3-88-AS-04 Each tool\'s toggle offers only on and off, with the tool\'s plain name', async ({ page }) => {
    await asOwner(page);
    await open(page, at('tools'));
    const labels = await page.locator('.lld-adm-tools strong').allTextContents();
    for (const l of labels) expect(l).not.toMatch(/calc_|_/);
    // 3 standalone tools, the master switch, 8 calculators and 6 AI tools (Stage 5, shown off).
    expect(await page.locator('.lld-adm-tools input[type="checkbox"], .lld-adm-master input[type="checkbox"]').count()).toBe(18);
  });

  test('TC-FR3-88-AS-02 Turning one tool off removes its page for a test member, and the others keep working', async ({ page }) => {
    await asOwner(page);
    await open(page, at('tools'));
    // The Owner counts as a test account, so its own requests see what a test member sees.
    expect((await page.request.get('/health-tools/heart-risk-check/')).status()).toBe(200);
    await page.locator('#lld-adm-t-calc_heart_risk').uncheck();
    await expect(saved(page)).toContainText('Heart Risk Check is off.');
    expect((await page.request.get('/health-tools/heart-risk-check/')).status()).toBe(404);
    expect((await page.request.get('/health-tools/blood-pressure-check/')).status()).toBe(200);
    await open(page, at('tools'));
    await page.locator('#lld-adm-t-calc_heart_risk').check();
    await expect(saved(page)).toContainText('Heart Risk Check is on.');
  });

  test('TC-FR3-88-AS-03 Turning the master toggle off removes all eight calculators; the standalone tools are unaffected', async ({ page, request }) => {
    const master = await admin(request, { op: 'feature', feature: 'calculators_new' });
    await asOwner(page);
    await open(page, at('tools'));
    await page.locator('#lld-adm-t-calculators_new').uncheck();
    await expect(saved(page)).toContainText('All eight calculators is off.');
    await expect(page.locator('#lld-adm-t-calc_bp_check')).toBeDisabled();
    await expect(page.locator('#lld-adm-t-protein')).toBeEnabled();
    expect((await page.request.get('/health-tools/blood-pressure-check/')).status()).toBe(404);
    expect((await page.request.get('/health-tools/protein/')).status()).toBe(200);
    await admin(request, { op: 'restore', feature: 'calculators_new', row: master.row, emails: master.emails });
  });

  test('TC-FR3-88-AS-05 Every tool change is in its history and the activity log, with the reason when given', async ({ page, request }) => {
    const beforeRow = (await admin(request, { op: 'feature', feature: 'calc_heart_risk' })).row;
    await asOwner(page);
    await open(page, at('tools'));
    const row = page.locator('#tool-calc_heart_risk');
    await row.locator('summary', { hasText: 'Add a reason' }).click();
    await row.locator('input[name="reason"]').fill(`Wording check ${stamp}`);
    await row.locator('#lld-adm-t-calc_heart_risk').uncheck();
    await expect(saved(page)).toContainText('Heart Risk Check is off.');
    await page.locator('#tool-calc_heart_risk summary', { hasText: 'History' }).click();
    await expect(page.locator('#tool-calc_heart_risk .lld-adm-history')).toContainText(`Wording check ${stamp}`);
    const log = await admin(request, { op: 'log', target: 'calc_heart_risk' });
    expect(log.rows.at(-1)).toMatchObject({ action: 'feature_off', reason: `Wording check ${stamp}` });
    await saved(page).getByRole('button', { name: 'Undo' }).click();
    await expect(saved(page)).toContainText('Heart Risk Check is on again.');
    // Undo puts the switch back as it was, so it follows the calculators group again.
    const after = (await admin(request, { op: 'feature', feature: 'calc_heart_risk' })).row;
    expect(after?.state ?? null).toBe(beforeRow?.state ?? null);
  });

  test('TC-FR3-89-AS-01 With a standalone tool off, its page shows "not available"', async ({ page }) => {
    await asOwner(page);
    await open(page, at('tools'));
    await page.locator('#lld-adm-t-healthy-weight').uncheck();
    await expect(saved(page)).toContainText('Healthy Weight Check is off.');
    const status = await (await page.request.get('/tools/status.json')).json();
    expect(status.tools['healthy-weight'].status).toBe('withdrawn');
    await open(page, '/health-tools/');
    await expect(page.locator('body')).toContainText('Temporarily unavailable');
  });

  test('TC-FR3-89-AS-02 With it off, the tool itself shows the approved paused text and no form', async ({ page }) => {
    const status = await (await page.request.get('/tools/status.json')).json();
    expect(status.tools['healthy-weight'].status).toBe('withdrawn');
    // The Healthy Weight Check has no email step; its file reads the same switch and fails closed (FR1-15).
    await page.goto('/tools/healthy-weight-tool.html');
    await expect(page.locator('body')).toContainText('We have paused this tool while we check it.');
  });

  test('TC-FR3-89-AS-03 Turning it back on restores it, with the approved file unchanged', async ({ page }) => {
    await asOwner(page);
    await open(page, at('tools'));
    await page.locator('#lld-adm-t-healthy-weight').check();
    await expect(saved(page)).toContainText('Healthy Weight Check is on.');
    const status = await (await page.request.get('/tools/status.json')).json();
    expect(status.tools['healthy-weight'].status).toBe('active');
  });

  // ----- Premium test member, reset, staging emails (FR3-91 to FR3-93) -----

  test('TC-FR3-91-AS-01 A Premium test member has every Premium feature, with no Stripe charge or subscription', async ({ page, request }) => {
    globalThis.ptm = await createMember(request, { email: mail('ptm'), password, first_name: 'Tess', health_consent: true });
    await asOwner(page);
    await open(page, memberPage(globalThis.ptm.member_id));
    const box = await action(page, 'Make Premium test member');
    await box.locator('#lld-ptm-on-reason').fill('Testing tracks and guides');
    await box.getByRole('button', { name: 'Make Premium test member' }).click();
    await expect(saved(page)).toContainText('Premium test member on');
    await expect(page.locator('.lld-adm-head')).toContainText('Premium test member');
    await expect(page.locator('dl.lld-facts')).toContainText('free, no end date');
    await expect(page.locator('dl.lld-facts')).toContainText('No payments');
    const state = await admin(request, { op: 'member', member: globalThis.ptm.member_id });
    expect(state.premium).toBe(true);
    expect(state.state).toBe('Premium');
  });

  test('TC-FR3-91-AS-02 The Premium test member sees the same Premium screens as a paying member', async ({ browser }) => {
    const ctx = await browser.newContext();
    const tess = await ctx.newPage();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    await signIn(tess, mail('ptm'), password);
    await tess.goto('/members/home/');
    await expect(tess.locator('.lld-chip-badge')).toHaveText('PREMIUM');
    for (const path of ['/members/meals/', '/members/challenge/', '/members/badges/']) {
      const res = await tess.goto(path);
      expect(res.status(), path).toBe(200);
      await expect(tess.locator('main'), path).not.toContainText(/Upgrade to Premium|Premium members only/i);
    }
    await ctx.close();
  });

  test('TC-FR3-91-AS-03 The Premium test member is not counted, and is labelled Test on the Members page', async ({ page }) => {
    await asOwner(page);
    await open(page, at('members', `q=${encodeURIComponent(mail('ptm'))}`));
    await expect(page.locator('.lld-adm-members .lld-adm-badge.is-test')).toHaveText('Premium test');
    await open(page, A);
    const premium = Number((await page.locator('[data-count="premium"] strong').innerText()).replace(/,/g, ''));
    const tests = Number(await page.locator('[data-count="test"] strong').innerText());
    expect(tests).toBeGreaterThanOrEqual(1);
    await open(page, at('members', 'plan=premium&test=no'));
    expect(premium).toBe(await page.locator('.lld-adm-members > li').count());
  });

  test('TC-FR3-91-AS-04 Safety still applies: with an eating-disorder flag the Premium test member sees no calorie numbers', async ({ browser }) => {
    const ctx = await browser.newContext();
    const tess = await ctx.newPage();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    await signIn(tess, mail('ptm'), password);
    await tess.goto('/members/home/');
    const nonce = await tess.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
    const headers = { 'X-WP-Nonce': nonce };
    const profile = await tess.request.post(`/wp-json/lld-members/v1/members/${globalThis.ptm.member_id}/health`, { headers, data: { kind: 'profile', sex: 'female', height_cm: 170, weight_kg: 70, activity_level: 'some', conditions_answered: 'answered', conditions: ['eating-disorder'], confirmed: true } });
    expect(profile.ok(), await profile.text()).toBeTruthy();
    const res = await tess.request.post('/wp-json/lld-members/v1/me/calculate/calorie-target', { headers, data: { sex: 'female', age: 40, heightCm: 170, weightKg: 70, activity: 'some', goal: 'lose' } });
    expect(await res.text()).not.toMatch(/"(kcal|calories|target_kcal|targetKcal)"\s*:\s*\d/);
    await ctx.close();
  });

  test('TC-FR3-92-AS-02 A reset is refused for a non-test account, and without the reason or the typed word', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    const post = (member, extra) => page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(member), do: 'reset', ...extra } });
    const plain = await createMember(page.request, { email: mail('plain'), password, first_name: 'Pip' });
    await post(plain.member_id, { reason: 'r', typed: 'RESET' });
    await open(page, memberPage(plain.member_id));
    await expect(refused(page)).toContainText('Only a test account can be reset.');
    await post(globalThis.ptm.member_id, { reason: '', typed: 'RESET' });
    await open(page, memberPage(globalThis.ptm.member_id));
    await expect(refused(page)).toContainText('Give a reason');
    await post(globalThis.ptm.member_id, { reason: 'r', typed: 'reset' });
    await open(page, memberPage(globalThis.ptm.member_id));
    await expect(refused(page)).toContainText('Type RESET to confirm.');
  });

  test('TC-FR3-92-AS-01 Reset a test member: the account and its Premium test status stay; it starts again', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(globalThis.ptm.member_id));
    const box = await action(page, 'Reset to a clean start');
    await box.locator('#lld-reset-reason').fill('Walk through joining again');
    await box.locator('#lld-reset-typed').fill('RESET');
    await box.getByRole('button', { name: 'Reset' }).click();
    await expect(saved(page)).toContainText('Test member reset.');
    await expect(page.locator('.lld-adm-head')).toContainText('Premium test member');
    expect((await admin(request, { op: 'member', member: globalThis.ptm.member_id })).premium).toBe(true);
    const log = await admin(request, { op: 'log', member: globalThis.ptm.member_id });
    expect(log.rows.map((r) => r.action)).toContain('member_reset');
  });

  test('TC-FR3-91-AS-05 Returning to Free deletes nothing, and both changes are logged with the reason', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(globalThis.ptm.member_id));
    const box = await action(page, 'Return to Free');
    await box.locator('#lld-ptm-off-reason').fill('Testing finished');
    await box.getByRole('button', { name: 'Return to Free' }).click();
    await expect(saved(page)).toContainText('Returned to Free.');
    const state = await admin(request, { op: 'member', member: globalThis.ptm.member_id });
    expect(state.premium).toBe(false);
    expect(state.grants).toBe(1);
    const log = await admin(request, { op: 'log', member: globalThis.ptm.member_id });
    const rows = log.rows.filter((r) => ['premium_test_on', 'premium_test_off'].includes(r.action));
    expect(rows.map((r) => r.reason)).toEqual(['Testing tracks and guides', 'Testing finished']);
  });

  test('TC-FR3-91-AS-06 A 6th Premium test member is refused: the limit is 5', async ({ page, request }) => {
    await asOwner(page);
    const made = [];
    let message = '';
    for (let i = 0; i < 6 && !message; i += 1) {
      const m = await createMember(request, { email: mail(`ptm${i}`), password, first_name: `Lim${i}` });
      made.push(m);
      await open(page, memberPage(m.member_id));
      const box = await action(page, 'Make Premium test member');
      await box.locator('#lld-ptm-on-reason').fill('Limit test');
      await box.getByRole('button', { name: 'Make Premium test member' }).click();
      const note = await page.locator('[data-lld-adm-notice]').innerText();
      if (note.includes('at most 5')) message = note;
    }
    expect(message).toContain('There can be at most 5 Premium test members');
    for (const m of made) {
      await open(page, memberPage(m.member_id));
      if (await page.locator('details.lld-adm-action > summary', { hasText: /^Return to Free$/ }).count()) {
        const box = await action(page, 'Return to Free');
        await box.locator('#lld-ptm-off-reason').fill('Limit test done');
        await box.getByRole('button', { name: 'Return to Free' }).click();
        await expect(saved(page)).toContainText('Returned to Free.');
      }
    }
  });

  test('TC-FR3-93-AS-03 Without the staging setting the Staging emails page does not exist', async ({ page }) => {
    await asOwner(page);
    await admin(page.request, { op: 'mailpage', off: true });
    expect((await page.request.get(at('emails'))).status()).toBe(404);
    await open(page, A);
    await expect(page.locator('.lld-adm-side').getByRole('link', { name: 'Staging emails' })).toHaveCount(0);
  });

  test('TC-FR3-93-AS-01 On staging, the newest message on the Staging emails page holds the code just asked for', async ({ page }) => {
    await asOwner(page);
    await admin(page.request, { op: 'mailpage' });
    await open(page, at('helpers'));
    const code = await freshCode(page, owner.email);
    await open(page, at('emails'));
    await expect(page.locator('.lld-adm-mail > li').first().locator('[data-lld-code]')).toHaveText(code);
  });

  test('TC-FR3-93-AS-02 A Support account and a member get "not found" at the Staging emails page', async ({ page, context, browser }) => {
    await admin(page.request, { op: 'mailpage' });
    await supportSignIn(page, context);
    expect((await page.request.get(at('emails'))).status()).toBe(404);
    const ctx = await browser.newContext();
    const m = await ctx.newPage();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    await signIn(m, payEmail, password);
    expect((await m.request.get(at('emails'))).status()).toBe(404);
    await ctx.close();
    await admin(page.request, { op: 'mailpage', off: true });
  });

  test('TC-FR3-87-AS-03 Free and Premium members see no Admin link, and every Admin address is "not found" for them', async ({ browser }) => {
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    for (const email of [mail('plain'), payEmail]) {
      const ctx = await browser.newContext();
      const m = await ctx.newPage();
      await signIn(m, email, password);
      await m.goto('/members/home/');
      await expect(m.locator('.lld-nav-admin')).toHaveCount(0);
      await expect(m.getByRole('banner').getByRole('link', { name: 'Admin', exact: true })).toHaveCount(0); // the v2 header too
      for (const p of PAGES) expect((await m.request.get(at(p))).status(), `${email} ${p}`).toBe(404);
      await ctx.close();
    }
  });

  // ----- Editors (FR3-95) -----

  async function editorSignIn(page, context) {
    await page.request.post('/wp-json/lld-members/v1/test/logout');
    await context.clearCookies();
    const base = test.info().project.use.baseURL || 'http://127.0.0.1:8090';
    await context.addCookies([{ name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base }]);
    await fillWpLogin(page, globalThis.editor.login, password);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/);
  }

  test('TC-FR3-95-AS-01 An Editor can edit an article and a page', async ({ page, context, request }) => {
    globalThis.editor = await admin(request, { op: 'editor', email: mail('editor'), password });
    await editorSignIn(page, context);
    for (const path of [`/wp-admin/post.php?post=${globalThis.editor.article}&action=edit`, '/wp-admin/post-new.php?post_type=page']) {
      const res = await page.goto(path, ADMIN);
      expect(res.status(), path).toBe(200);
      await expect(page.locator('body'), path).not.toContainText(/not allowed|Sorry, you are not/);
    }
  });

  test('TC-FR3-95-AS-02 An Editor opening or saving a recipe is refused', async ({ page, context }) => {
    await editorSignIn(page, context);
    for (const path of ['/wp-admin/edit.php?post_type=lld_recipe', `/wp-admin/post.php?post=${globalThis.editor.recipe}&action=edit`]) {
      const res = await page.goto(path, ADMIN);
      expect(res.status() === 403 || /not allowed|Sorry, you are not/.test(await page.locator('body').innerText()), path).toBe(true);
    }
  });

  // ----- Deletion, log and limits -----

  test('TC-FR3-85-AS-01 Send the download link: caught by the mail catcher, and the download needs sign-in', async ({ page, browser }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const box = await action(page, 'Send the download link');
    await box.getByRole('button', { name: 'Send the download link' }).click();
    await expect(saved(page)).toContainText('Download link sent to the member.');
    await waitForMail(freeEmail, 'data download');
    const ctx = await browser.newContext();
    const anon = await ctx.newPage();
    await anon.goto('/members/data/');
    await expect(anon).toHaveURL(/sign-in/);
    await ctx.close();
  });

  test('TC-FR3-85-AS-02 No admin page or request returns the member\'s download file', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    expect(await page.content()).not.toMatch(/download\.zip|\.json"|lld-downloads/);
    await expect(page.locator('a[download]')).toHaveCount(0);
  });

  test('TC-FR3-83-AS-01 Block a signed-in member: next page load and interface request refused, sign-in refused', async ({ browser, page }) => {
    const memberContext = await browser.newContext();
    const member = await memberContext.newPage();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    const blockEmail = mail('block');
    const target = await createMember(member.request, { email: blockEmail, password, first_name: 'Bo' });
    await signIn(member, blockEmail, password);
    await member.goto('/members/home/');
    const nonce = await member.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
    expect((await member.request.get('/wp-json/lld-members/v1/me/features', { headers: { 'X-WP-Nonce': nonce } })).status()).toBe(200);
    await asOwner(page);
    await open(page, memberPage(target.member_id));
    const box = await action(page, 'Block');
    await box.locator('#lld-block-reason').fill('Abuse of support');
    await box.getByRole('button', { name: 'Block member' }).click();
    await expect(saved(page)).toContainText('Member blocked and signed out.');
    await member.goto('/members/home/');
    await expect(member).toHaveURL(/sign-in/);
    const api = await member.request.get('/wp-json/lld-members/v1/me/features', { headers: { 'X-WP-Nonce': nonce } });
    expect(api.status()).toBeGreaterThanOrEqual(400);
    await member.goto('/members/sign-in/');
    await member.locator('#lld_email').fill(blockEmail);
    await member.locator('#lld_password').fill(password);
    await member.locator('#lld_password').press('Enter');
    await member.goto('/members/home/');
    await expect(member).toHaveURL(/sign-in/);
    await memberContext.close();
    globalThis.blocked = { id: target.member_id, email: blockEmail };
  });

  test('TC-FR3-83-AS-03 Unblock: the member can sign in again', async ({ page, browser, request }) => {
    await asOwner(page);
    const adminMember = await createMember(request, { email: mail('adm'), password, first_name: 'Ada', role: 'owner_admin' });
    await open(page, memberPage(adminMember.member_id));
    let box = await action(page, 'Block');
    await box.locator('#lld-block-reason').fill('try');
    await box.getByRole('button', { name: 'Block member' }).click();
    await expect(refused(page)).toContainText("Admin accounts can't be blocked");
    await open(page, memberPage(globalThis.blocked.id));
    box = await action(page, 'Unblock');
    await box.locator('#lld-block-reason').fill('Resolved');
    await box.getByRole('button', { name: 'Unblock member' }).click();
    await expect(saved(page)).toContainText('Member unblocked.');
    const ctx = await browser.newContext();
    const member = await ctx.newPage();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    await signIn(member, globalThis.blocked.email, password);
    await member.goto('/members/home/');
    await expect(member).not.toHaveURL(/sign-in/);
    await ctx.close();
  });

  test('TC-FR3-84-AS-02 Deleting is refused without the typed email, the reason and a fresh code', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    for (const t of [{ email: '', reason: 'r', code: '123456' }, { email: freeEmail, reason: '', code: '123456' }, { email: freeEmail, reason: 'r', code: '' }]) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: 'delete', ...t } });
      const state = await admin(request, { op: 'member', member: free.member_id, email: freeEmail });
      expect(state.problems.length).toBeGreaterThan(0);
    }
  });

  test('TC-FR3-84-AS-01 Delete from Admin: the erasure checker finds nothing left', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const box = await action(page, 'Delete member');
    const code = await freshCode(page, owner.email, box);
    const again = await action(page, 'Delete member');
    await again.locator('#lld-delete-email').fill(freeEmail);
    await again.locator('#lld-delete-reason').fill('Member asked by email');
    await again.locator('#lld-delete-code').fill(code);
    await again.getByRole('button', { name: 'Delete for good' }).click();
    await expect(saved(page)).toContainText(`Member #${free.member_id} deleted.`);
    const state = await admin(request, { op: 'member', member: free.member_id, email: freeEmail });
    expect(state.problems).toEqual([]);
  });

  test('TC-NFR3-23-AS-01 After deletion: no note or grant, and log entries keep only the member number', async ({ request }) => {
    const state = await admin(request, { op: 'member', member: free.member_id, email: freeEmail });
    expect(state.notes).toBe(0);
    expect(state.grants).toBe(0);
    const log = await admin(request, { op: 'log', member: free.member_id });
    expect(log.rows.length).toBeGreaterThan(0);
    for (const row of log.rows) {
      expect(row.member_name).toBeNull();
      expect(row.member_email).toBeNull();
      expect(JSON.stringify(row)).not.toContain(freeEmail);
    }
  });

  test('TC-FR3-86-AS-05 After deletion the member\'s entries show the number and no name or email', async ({ page }) => {
    await asOwner(page);
    await open(page, at('activity', `q=${encodeURIComponent(`Member #${free.member_id}`)}`));
    expect(await page.locator('.lld-adm-log > li').count()).toBeGreaterThan(0);
    await expect(page.locator('[data-lld-admin]')).not.toContainText(freeEmail);
    await expect(page.locator('[data-lld-admin]')).not.toContainText(`Frida${stamp}`);
  });

  test('TC-FR3-86-AS-01 Each action leaves one entry with who, when, what, member, before, after and reason', async ({ request }) => {
    const log = await admin(request, { op: 'log', member: paying.member_id });
    const ended = log.rows.filter((r) => r.action === 'membership_end');
    expect(ended).toHaveLength(2);
    for (const row of ended) {
      expect(row.admin_user_id).toMatch(/^\d+$/);
      expect(row.created_at).toBeTruthy();
      expect(row.member_number).toBe(String(paying.member_id));
      expect(row.before_state).toBeTruthy();
      expect(row.after_state).toBeTruthy();
      expect(row.reason).toBeTruthy();
    }
    const notes = log.rows.filter((r) => r.action === 'member_note');
    expect(notes.length).toBeGreaterThan(0);
    for (const row of notes) expect(JSON.stringify(row)).not.toContain('renewal date');
  });

  test('TC-FR3-86-AS-02 Each risky action is refused without a reason', async ({ page, request }) => {
    await asOwner(page);
    const target = await createMember(request, { email: mail('risky'), password, first_name: 'Ria' });
    await open(page, memberPage(target.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    for (const doAction of ['grant', 'end', 'test', 'block', 'delete', 'ptm']) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(target.member_id), do: doAction, ends_on: '2099-01-01', on: '1', reason: '' } });
      await open(page, memberPage(target.member_id));
      await expect(refused(page)).toContainText('Give a reason');
    }
    const log = await admin(request, { op: 'log', member: target.member_id });
    expect(log.rows).toEqual([]);
  });

  test('TC-FR3-86-AS-03 No admin page, request or command edits or deletes a log entry', async ({ page }) => {
    await asOwner(page);
    await open(page, at('activity'));
    await expect(page.locator('[data-lld-admin]').getByRole('button', { name: /delete|edit|remove/i })).toHaveCount(0);
    await expect(page.locator('[data-lld-admin] form[method="post"]')).toHaveCount(0);
  });

  test('TC-FR3-86-AS-06 Entries older than 2 years are removed automatically', async ({ request }) => {
    const log = await admin(request, { op: 'log', target: 'barcode' });
    expect(log.rows.length).toBeGreaterThan(0);
    await admin(request, { op: 'age_log', target: 'barcode', days: 731 });
    await admin(request, { op: 'sweep' });
    const after = await admin(request, { op: 'log', target: 'barcode' });
    expect(after.rows.length).toBe(log.rows.length - 1);
  });

  test('TC-FR3-73-AS-01 With known test data, every count matches', async ({ page }) => {
    await asOwner(page);
    await open(page, A);
    const value = async (key) => Number((await page.locator(`[data-count="${key}"] strong`).innerText()).replace(/,/g, ''));
    const total = await value('total');
    expect(total).toBeGreaterThan(0);
    await open(page, at('members', 'status=blocked&test=no'));
    const blockedListed = await page.locator('.lld-adm-members > li').count();
    await open(page, A);
    await expect(page.locator('[data-count="paused"] .lld-adm-stat-sub')).toContainText(`blocked ${blockedListed}`);
  });

  test('TC-FR3-73-AS-02 The Overview contains no health values, goals or track names', async ({ page }) => {
    await asOwner(page);
    await open(page, A);
    const text = await page.locator('[data-lld-admin]').innerText();
    expect(text).not.toContain(SENTINEL);
    expect(text).not.toMatch(/Healthy Weight|Blood Sugar Balance|Fitness and Strength|Sleep and Stress|kg\b|mmol/);
  });

  test('TC-NFR3-19-AS-01 No admin page, the log or its CSV shows the seeded health values', async ({ page, request }) => {
    const seeded = await createMember(request, { email: mail('seed'), password, first_name: 'Sid', health_consent: true, sentinel: SENTINEL });
    await asOwner(page);
    for (const path of [...PAGES.map((p) => at(p)), memberPage(seeded.member_id)]) {
      await open(page, path);
      expect(await page.content(), path).not.toContain(SENTINEL);
    }
    await open(page, at('activity'));
    const href = await page.getByRole('link', { name: 'Download as CSV' }).getAttribute('href');
    expect(await (await page.request.get(href)).text()).not.toContain(SENTINEL);
  });

  test('TC-NFR3-20-AS-02 Deleting a member and adding or removing a helper each ask for a fresh code; a toggle does not', async ({ page }) => {
    await asOwner(page);
    await open(page, at('helpers'));
    await expect(page.locator('#lld-helper-code')).toHaveAttribute('required', '');
    const target = await createMember(page.request, { email: mail('code'), password, first_name: 'Cy' });
    await open(page, memberPage(target.member_id));
    await expect(page.locator('#lld-delete-code')).toHaveAttribute('required', '');
    await open(page, at('features'));
    await expect(page.locator('#tool-barcode input[name="code"]')).toHaveCount(0);
  });

  test('TC-NFR3-20-AS-03 Signed out after 30 minutes idle, and after 12 hours even when active', async ({ page, context, request }) => {
    await supportSignIn(page, context);
    await admin(request, { op: 'clock', seconds: 31 * 60 });
    await open(page, A);
    await expect(page).toHaveURL(/wp-login\.php/);
    await expect(page.getByText('You were signed out for safety')).toBeVisible();
    expect((await page.request.get(A, { maxRedirects: 0 })).status()).toBe(302);
    await admin(request, { op: 'clock', seconds: 0 });
    await supportSignIn(page, context);
    test.setTimeout(300000);
    for (let minutes = 25; minutes < 12 * 60; minutes += 25) {
      await admin(request, { op: 'clock', seconds: minutes * 60 });
      const res = await page.request.get(A, { maxRedirects: 0 });
      expect(res.status(), `signed out early at ${minutes} minutes`).toBe(200);
    }
    await admin(request, { op: 'clock', seconds: 12 * 3600 + 60 });
    await open(page, A);
    await expect(page).toHaveURL(/wp-login\.php/);
    await expect(page.getByText('You were signed out for safety')).toBeVisible();
    await admin(request, { op: 'clock', seconds: 0 });
  });

  test('TC-NFR3-21-AS-02 A new browser sign-in sends the Owner an alert, as do adding and removing a helper', async ({ page, context }) => {
    const before = (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('New browser')).length;
    await context.clearCookies();
    await supportSignIn(page, context);
    await expect.poll(async () => (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('New browser')).length).toBeGreaterThan(before);
    await asOwner(page);
    await open(page, at('helpers'));
    const code = await freshCode(page, owner.email);
    const second = mail('helper2');
    await page.locator('#lld-helper-name').fill('Second helper');
    await page.locator('#lld-helper-email').fill(second);
    await page.locator('#lld-helper-reason').fill('Holiday cover');
    await page.locator('#lld-helper-code').fill(code);
    await page.getByRole('button', { name: 'Add helper' }).click();
    await expect(saved(page)).toContainText('Helper added.');
    await waitForMail(owner.admin_email, 'A Support helper was added');
    const row = page.locator('.lld-adm-list > li', { hasText: second });
    await row.locator('details.lld-adm-action > summary', { hasText: /^Remove$/ }).click();
    const code2 = await freshCode(page, owner.email, row);
    const again = page.locator('.lld-adm-list > li', { hasText: second });
    await again.locator('details.lld-adm-action > summary', { hasText: /^Remove$/ }).click();
    await again.locator('input[name="reason"]').fill('Cover ended');
    await again.locator('input[name="code"]').fill(code2);
    await again.getByRole('button', { name: 'Remove helper' }).click();
    await expect(saved(page)).toContainText('Helper removed.');
    await waitForMail(owner.admin_email, 'A Support helper was removed');
  });

  test('TC-NFR3-20-AS-04 The 6th failed attempt is refused for 15 minutes and the Owner is emailed', async ({ page, context, request }) => {
    const before = (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('locked')).length;
    const base = test.info().project.use.baseURL || 'http://127.0.0.1:8090';
    await admin(request, { op: 'clear_spam' });
    await context.clearCookies();
    await context.addCookies([{ name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base }]);
    for (let i = 0; i < 5; i += 1) {
      await fillWpLogin(page, HELPER, `wrong-${i}`);
      await page.locator('#wp-submit').click();
      // Wait for the refusal, so the next attempt never cuts this one off (WebKit) and every failure is counted.
      await expect(page.locator('#login_error')).toBeVisible();
    }
    await fillWpLogin(page, HELPER, password);
    await page.locator('#wp-submit').click();
    await expect(page.locator('#login_error')).toContainText('Try again in 15 minutes');
    await expect.poll(async () => (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('locked')).length).toBeGreaterThan(before);
    await admin(request, { op: 'helper', email: HELPER, password });
  });

  test('TC-NFR3-21-AS-01 A 6th deletion within an hour is refused with when it is allowed again', async ({ page, request }) => {
    await asOwner(page);
    let refusedNote = '';
    for (let i = 0; i < 6 && !refusedNote; i += 1) {
      const t = await createMember(request, { email: mail(`del${i}`), password, first_name: 'Del' });
      await open(page, memberPage(t.member_id));
      const box = await action(page, 'Delete member');
      const code = await freshCode(page, owner.email, box);
      const again = await action(page, 'Delete member');
      await again.locator('#lld-delete-email').fill(mail(`del${i}`));
      await again.locator('#lld-delete-reason').fill('Limit test');
      await again.locator('#lld-delete-code').fill(code);
      await again.getByRole('button', { name: 'Delete for good' }).click();
      const note = await page.locator('[data-lld-adm-notice]').innerText();
      if (note.includes('in the last hour')) refusedNote = note;
    }
    expect(refusedNote).toMatch(/You can delete another at \d\d:\d\d UTC/);
  });

  // ----- Look and quality (NFR3-22, NFR3-24, FR5-30) -----

  test('TC-FR5-30-AS-01 The Owner\'s Admin lists AI, and the page shows each part', async ({ page }) => {
    await asOwner(page);
    await open(page, at('ai'));
    await expect(page.locator('.lld-adm-head h1')).toHaveText('AI');
    await expect(page.getByRole('heading', { name: 'AI tools arrive in Stage 5' })).toBeVisible();
    for (const part of ['Providers and keys', 'Privacy check for each provider', 'Models and prices', 'On and off', 'Test connection', 'Which AI does each job', 'Spending limits and alerts', 'Member allowances and use']) {
      await expect(page.locator('.lld-adm-ai-parts')).toContainText(part);
    }
  });

  test('TC-NFR3-24-AS-01 No admin page shows a code name as a label', async ({ page }) => {
    await asOwner(page);
    for (const p of PAGES) {
      await open(page, at(p));
      const labels = await page.locator('[data-lld-admin] :is(h1, h2, h3, strong, label, summary, a, button, dt)').allInnerTexts();
      for (const l of labels) expect(l, `${p}: ${l}`).not.toMatch(/\bcalc_|\b[a-z]+_[a-z_]+\b/);
    }
  });

  test('TC-NFR3-24-AS-02 Every admin page uses the site\'s design', async ({ page }) => {
    await asOwner(page);
    for (const p of PAGES) {
      await open(page, at(p));
      // the site header: the plugin's own (.lld-header) or, on v2 pages, the site's v2 header; both are the page banner
      await expect(page.getByRole('banner').first(), p).toBeVisible();
      const font = await page.locator('.lld-adm-head h1').evaluate((el) => getComputedStyle(el).fontFamily);
      expect(font, p).toMatch(/Plus Jakarta Sans/);
      expect(await page.locator('#adminmenu').count(), p).toBe(0);
    }
  });

  test('TC-NFR3-24-AS-03 Every action shows a message, and the long lists have a search box', async ({ page }) => {
    await asOwner(page);
    for (const p of ['members', 'activity']) {
      await open(page, at(p));
      await expect(page.locator('[data-lld-admin] input[type="search"]'), p).toHaveCount(1);
    }
    await open(page, at('features'));
    await page.locator('#lld-adm-t-barcode').uncheck();
    await expect(saved(page)).toBeVisible();
    await saved(page).getByRole('button', { name: 'Undo' }).click();
    await expect(saved(page)).toBeVisible();
  });

  test('TC-NFR3-24-AS-04 The everyday tasks can be done end to end: find a member, pause them, switch a tool', async ({ page }) => {
    await asOwner(page);
    await open(page, A);
    await page.locator('.lld-adm-side').getByRole('link', { name: 'Members' }).click();
    await page.locator('#lld-q').fill(payEmail);
    await page.getByRole('button', { name: 'Search' }).click();
    await page.locator('.lld-adm-members a').first().click();
    await expect(page.locator('dl.lld-facts')).toContainText(payEmail);
    await page.locator('.lld-adm-side').getByRole('link', { name: 'Tools' }).click();
    // the switch saves through the page's script, so wait until the Tools page has loaded before using it
    await page.waitForURL(/\/members\/admin\/tools\/$/, { waitUntil: 'load' });
    await page.locator('#lld-adm-t-calc_hr_zones').uncheck();
    await expect(saved(page)).toContainText('Heart Rate Zones is off.');
    await saved(page).getByRole('button', { name: 'Undo' }).click();
    await expect(saved(page)).toContainText('Heart Rate Zones is on again.');
  });

  test('TC-NFR3-24-AS-05 An accessibility scan of every admin page finds no serious problems', async ({ page }) => {
    await asOwner(page);
    const target = await createMember(page.request, { email: mail('axe'), password, first_name: 'Axe' });
    for (const path of [...PAGES.map((p) => at(p)), memberPage(target.member_id)]) {
      await open(page, path);
      const results = await new AxeBuilder({ page }).include('[data-lld-admin]').analyze();
      const bad = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
      expect(bad.map((v) => `${v.id}: ${v.nodes.length}`), path).toEqual([]);
    }
  });

  test('TC-NFR3-22-AS-01 At 360 px no admin page scrolls sideways, and axe finds no serious problems', async ({ page }) => {
    await asOwner(page);
    await page.setViewportSize({ width: 360, height: 780 });
    const target = await createMember(page.request, { email: mail('phone'), password, first_name: 'Phi' });
    for (const path of [...PAGES.map((p) => at(p)), memberPage(target.member_id)]) {
      await open(page, path);
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(wide, path).toBeLessThanOrEqual(0);
      const results = await new AxeBuilder({ page }).include('[data-lld-admin]').analyze();
      const bad = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
      expect(bad.map((v) => `${v.id}: ${v.nodes.length}`), path).toEqual([]);
    }
  });

  test('TC-NFR3-22-AS-02 No admin page asks Google Analytics, and each is noindex', async ({ page }) => {
    await asOwner(page);
    const google = [];
    page.on('request', (r) => { if (/google-analytics|googletagmanager/.test(r.url())) google.push(r.url()); });
    for (const p of PAGES) {
      const res = await page.goto(at(p));
      expect(res.headers()['x-robots-tag'] || '', p).toContain('noindex');
      await expect(page.locator('meta[name="robots"][content*="noindex"]')).toHaveCount(1);
    }
    expect(google).toEqual([]);
  });
});
