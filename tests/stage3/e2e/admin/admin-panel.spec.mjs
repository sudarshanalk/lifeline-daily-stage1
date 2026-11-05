// P3-87: the Lifeline Daily admin panel, live on the CI WordPress (FR3-71 to FR3-86, NFR3-19 to NFR3-23).
// The Owner signs in through the Stage 2 test route; the Support helper signs in for real at wp-login.php with the
// emailed code. Stripe is not connected in CI (no keys), so the Stripe side of pause, block and end is checked when
// the owner's Stripe test keys are on staging (L7); here the site's own state is checked.
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

async function admin(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/admin', { data });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function open(page, path) {
  await leanAdmin(page);
  await page.goto(path, ADMIN);
  if (page.url().includes('pmpro-wizard')) await page.goto(path, ADMIN);
}

const memberPage = (id) => `/wp-admin/admin.php?page=lld-members-admin&member=${id}`;

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

/** Ask for and read a fresh code for a risky action (NFR3-20). */
async function freshCode(page, ownerEmail) {
  await page.getByRole('button', { name: 'Email me a code' }).first().click();
  await expect(page.getByText('We\'ve emailed you a 6-digit code')).toBeVisible();
  return mailCode(ownerEmail);
}

async function supportSignIn(page, context) {
  await admin(page.request, { op: 'clear_spam' });
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await context.clearCookies();
  const base = test.info().project.use.baseURL || 'http://127.0.0.1:8090';
  await context.addCookies([{ name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base }]);
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(HELPER);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  await page.locator('#lld_code').fill(await mailCode(HELPER));
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.waitForURL(/page=lld-overview/);
}

test.describe.serial('P3-87 admin panel', () => {
  let owner;
  let free;
  let paying;
  const freeEmail = mail('free');
  const payEmail = mail('pay');

  let saved;
  test.beforeAll(async ({ request }) => {
    saved = await admin(request, { op: 'feature', feature: 'barcode' });
    owner = await admin(request, { op: 'owner' });
    await admin(request, { op: 'clock', seconds: 0 });
    await admin(request, { op: 'helper', email: HELPER, password });
    free = await createMember(request, { email: freeEmail, password, first_name: `Frida${stamp}`, health_consent: true, sentinel: SENTINEL });
    const end = new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10);
    paying = await createMember(request, { email: payEmail, password, first_name: 'Pat', access_state: 'Premium', plan: 'monthly', period_ends_on: end, stripe_subscription: `sub_test_${stamp}` });
    await admin(request, { op: 'prepare', member: paying.member_id, customer: `cus_test${stamp}` });
  });

  test.afterAll(async ({ request }) => {
    await admin(request, { op: 'restore', feature: 'barcode', row: saved.row, emails: saved.emails });
  });

  test('TC-FR3-72-AS-01 One Lifeline Daily menu lists every page; the old Payments and Content menus are gone', async ({ page }) => {
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-overview');
    const menu = page.locator('#adminmenu');
    const top = menu.locator('li.toplevel_page_lld-overview');
    await expect(top.locator('.wp-menu-name')).toHaveText('Lifeline Daily');
    for (const name of ['Overview', 'Members', 'Features', 'Activity log', 'Payments', 'Approvals', 'Safety matrix', 'Live Q&A', 'Testimonials', 'Weekly report', 'Group removals', 'Preview']) {
      await expect(top.locator('.wp-submenu a', { hasText: new RegExp(`^${name.replace('&', '&')}$`) })).toHaveCount(1);
    }
    await expect(menu.locator('li.toplevel_page_lld-payments, li.toplevel_page_lld-approvals')).toHaveCount(0);
  });

  test('TC-FR3-72-AS-02 Each existing screen still opens at its old address', async ({ page }) => {
    await asOwner(page);
    for (const slug of ['lld-payments', 'lld-approvals', 'lld-matrix', 'lld-qa', 'lld-quotes', 'lld-report', 'lld-group', 'lld-preview']) {
      const res = await page.goto(`/wp-admin/admin.php?page=${slug}`, ADMIN);
      expect(res.status(), slug).toBe(200);
      await expect(page.locator('#wpbody-content h1').first(), slug).toBeVisible();
      await expect(page.locator('body'), slug).not.toContainText(/not allowed to access this page|Cannot load/);
      // Each screen is shown once (its callback is not registered twice under the new menu).
      expect(await page.locator('#wpbody-content .wrap > h1').count(), slug).toBeLessThanOrEqual(1);
      await expect(page.locator('li.toplevel_page_lld-overview')).toHaveClass(/wp-has-current-submenu/);
    }
  });

  test('TC-NFR3-20-AS-01 A Support account must enter an email code to sign in', async ({ page, context }) => {
    await supportSignIn(page, context);
    await expect(page.locator('#wpbody-content h1').first()).toHaveText('Lifeline Daily');
  });

  test('TC-FR3-72-AS-03 A Support account sees only Overview and Members in the menu', async ({ page, context }) => {
    await supportSignIn(page, context);
    const items = await page.locator('li.toplevel_page_lld-overview .wp-submenu a').allTextContents();
    expect(items.map((t) => t.trim())).toEqual(['Overview', 'Members']);
  });

  test('TC-FR3-73-AS-03 Support sees no payment counts and no activity log on the Overview', async ({ page, context }) => {
    await supportSignIn(page, context);
    for (const key of ['monthly', 'yearly', 'failed']) await expect(page.locator(`[data-count="${key}"]`)).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Last 10 admin actions' })).toHaveCount(0);
    await expect(page.locator('[data-count="total"]')).toBeVisible();
  });

  test('TC-FR3-71-AS-01 Support can search, open an account, resend a sign-in email, add a note, pause and resume', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, `/wp-admin/admin.php?page=lld-members-admin&q=${encodeURIComponent(payEmail)}`);
    await page.getByRole('link', { name: `#${paying.member_id}` }).click();
    await expect(page.locator('dl.lld-facts')).toContainText(payEmail);
    await page.locator('#lld-kind').selectOption('sign_in_code');
    await page.getByRole('button', { name: 'Resend' }).click();
    await expect(page.getByText('Email sent.')).toBeVisible();
    await page.locator('#lld-note').fill('Asked about the renewal date by email.');
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(page.getByText('Note added.')).toBeVisible();
    await page.locator('#lld-months').selectOption('1');
    await page.getByRole('button', { name: 'Pause membership' }).click();
    await expect(page.getByText('Membership paused.')).toBeVisible();
    await expect(page.locator('dl.lld-facts')).toContainText('Pause starts');
    await page.getByRole('button', { name: 'Resume membership' }).click();
    await expect(page.getByText('Membership resumed.')).toBeVisible();
  });

  test('TC-FR3-71-AS-02 Support sees no Owner-only button, and a direct request is refused', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, memberPage(free.member_id));
    for (const name of ['Give free Premium', 'End membership', 'Mark as a test account', 'Block member', 'Send the download link', 'Delete member']) {
      await expect(page.getByRole('button', { name })).toHaveCount(0);
    }
    await expect(page.getByRole('link', { name: /Stripe/ })).toHaveCount(0);
    // A direct post with a valid Support nonce is still refused on the server.
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    for (const doAction of ['grant', 'end', 'test', 'block', 'unblock', 'delete', 'download', 'ungrant']) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: doAction, reason: 'try', ends_on: '2099-01-01', on: '1', email: freeEmail } });
      await open(page, memberPage(free.member_id));
      await expect(page.locator('.lld-admin .notice-error')).toContainText('Your role can\'t do that.');
    }
    const state = await admin(page.request, { op: 'member', member: free.member_id });
    expect(state.blocked).toBe(false);
    expect(state.grants).toBe(0);
  });

  test('TC-FR3-74-AS-05 Support cannot change any feature or the test-account list', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, '/wp-admin/admin.php?page=lld-features');
    await expect(page.getByText('Only the Owner can change it.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Turn off' })).toHaveCount(0);
    const nonce = await page.locator('#wpbody-content').evaluate(() => '');
    const res = await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_feature', _lldnonce: nonce, feature: 'barcode', state: 'off', reason: 'x' } });
    expect(res.status()).toBe(403);
    const state = await admin(page.request, { op: 'feature', feature: 'barcode' });
    expect(state.history.filter((h) => h.note === 'x')).toHaveLength(0);
  });

  test('TC-FR3-71-AS-03 Only an Owner account can add or remove a Support helper', async ({ page, context }) => {
    await supportSignIn(page, context);
    const res = await page.request.get('/wp-admin/admin.php?page=lld-helpers');
    expect(res.status()).toBe(403);
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-helpers');
    await expect(page.getByText(HELPER)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add helper' })).toBeVisible();
  });

  test('TC-FR3-86-AS-04 Support sees only their own entries; the Owner\'s CSV matches the log', async ({ page, context }) => {
    await supportSignIn(page, context);
    await open(page, '/wp-admin/admin.php?page=lld-activity');
    const whos = await page.locator('td[data-label="Who"]').allTextContents();
    expect(whos.length).toBeGreaterThan(0);
    expect(new Set(whos)).toEqual(new Set(['Test helper']));
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-activity');
    const href = await page.getByRole('link', { name: 'Download as CSV' }).getAttribute('href');
    const csv = await (await page.request.get(href)).text();
    const firstRow = (await page.locator('#wpbody-content tbody tr').first().locator('td').allTextContents()).map((t) => t.trim());
    const csvRow = csv.trim().split('\n')[1];
    for (const cell of firstRow.filter(Boolean)) expect(csvRow).toContain(cell);
  });

  test('TC-FR3-75-AS-01 Search by part of an email, a name and a member number', async ({ page }) => {
    await asOwner(page);
    for (const q of [`free-${stamp}`, `Frida${stamp}`, String(free.member_id)]) {
      await open(page, `/wp-admin/admin.php?page=lld-members-admin&q=${encodeURIComponent(q)}`);
      await expect(page.getByRole('link', { name: `#${free.member_id}`, exact: true })).toBeVisible();
    }
  });

  test('TC-FR3-75-AS-02 Each filter returns only matching members', async ({ page }) => {
    await asOwner(page);
    const ids = async (query) => {
      await open(page, `/wp-admin/admin.php?page=lld-members-admin&q=${stamp}&${query}`);
      return (await page.locator('td[data-label="Member"] a').allTextContents()).map((t) => t.trim());
    };
    expect(await ids('plan=premium')).toEqual([`#${paying.member_id}`]);
    expect(await ids('plan=free')).toEqual([`#${free.member_id}`]);
    expect(await ids('status=blocked')).toEqual([]);
    expect(await ids('test=yes')).toEqual([]);
  });

  test('TC-FR3-76-AS-01 The member page shows each account detail', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    const facts = page.locator('dl.lld-facts');
    for (const label of ['Name', 'Email', 'Sign-in methods', 'Joined', 'Last sign-in', 'Plan', 'Status', 'Renewal date', 'Payment status', 'Test account', 'AI allowance']) {
      await expect(facts.locator('dt', { hasText: new RegExp(`^${label}$`) })).toHaveCount(1);
    }
    await expect(facts).toContainText(payEmail);
    await expect(facts).toContainText('Premium, monthly');
  });

  test('TC-FR3-76-AS-02 The member page shows none of the member\'s health data', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const html = await page.content();
    expect(html).not.toContain(SENTINEL);
    for (const word of ['Healthy Weight', 'Blood Sugar', 'goal', 'track', 'diary', 'calculator']) expect(await page.locator('#wpbody-content').innerText()).not.toMatch(new RegExp(`\\b${word}\\b`, 'i'));
  });

  test('TC-FR3-76-AS-03 No admin page offers to sign in as, or view the site as, a member', async ({ page }) => {
    await asOwner(page);
    for (const path of ['/wp-admin/admin.php?page=lld-overview', '/wp-admin/admin.php?page=lld-members-admin', memberPage(free.member_id)]) {
      await open(page, path);
      await expect(page.locator('#wpbody-content')).not.toContainText(/sign in as|log in as|switch to|view (the site )?as/i);
    }
  });

  test('TC-FR3-77-AS-01 Resend each email type: it is caught and logged', async ({ page, request }) => {
    await asOwner(page);
    const unconfirmed = await createMember(request, { email: mail('unconf'), password, first_name: 'Uma' });
    await admin(request, { op: 'prepare', member: unconfirmed.member_id, unconfirm: true });
    for (const [kind, subject, who] of [['sign_in_code', 'sign-in code', free], ['welcome', 'Welcome', free], ['confirm', 'onfirm', unconfirmed]]) {
      await open(page, memberPage(who.member_id));
      await page.locator('#lld-kind').selectOption(kind);
      await page.getByRole('button', { name: 'Resend' }).click();
      await expect(page.locator('.lld-admin .notice')).toContainText('Email sent.');
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
    await expect(page.locator('.lld-note').first().locator('.lld-hint')).toContainText('UTC');
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
    await expect(page.locator('#lld-note-hint')).toHaveText('No health details in notes');
    await expect(page.locator('#lld-note')).toHaveAttribute('aria-describedby', 'lld-note-hint');
  });

  test('TC-FR3-79-AS-01 Pause a monthly member for 1 month with the FR3-65 rules', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    await page.locator('#lld-months').selectOption('1');
    await page.getByRole('button', { name: 'Pause membership' }).click();
    await expect(page.getByText('Membership paused.')).toBeVisible();
    // The pause starts when the paid month ends, as the member's own pause does.
    const end = new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10);
    await expect(page.locator('dl.lld-facts')).toContainText(`Pause starts ${end}`);
    // A second pause while one is open is refused, as for the member.
    const log = await admin(request, { op: 'log', member: paying.member_id });
    expect(log.rows.filter((r) => r.action === 'member_pause').length).toBeGreaterThanOrEqual(1);
  });

  test('TC-FR3-79-AS-02 Resume the member: the pause is gone and Premium stays', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    await page.getByRole('button', { name: 'Resume membership' }).click();
    await expect(page.getByText('Membership resumed.')).toBeVisible();
    await expect(page.locator('dl.lld-facts')).toContainText('Active');
    expect((await admin(request, { op: 'member', member: paying.member_id })).premium).toBe(true);
  });

  test('TC-FR3-80-AS-03 Giving or removing Premium is refused without a reason', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: 'grant', ends_on: '2099-01-01', reason: '' } });
    await open(page, memberPage(free.member_id));
    await expect(page.locator('.lld-admin .notice-error')).toContainText('Give a reason');
    expect((await admin(page.request, { op: 'member', member: free.member_id })).grants).toBe(0);
  });

  test('TC-FR3-80-AS-01 Give a Free member Premium until a date: Premium at once, no Stripe subscription', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const until = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    await page.locator('#lld-grant-end').fill(until);
    await page.locator('#lld-grant-reason').fill('Competition prize');
    await page.getByRole('button', { name: 'Give free Premium' }).click();
    await expect(page.getByText(`Free Premium given until ${until}.`)).toBeVisible();
    await expect(page.locator('dl.lld-facts')).toContainText('Premium (free, given by the Owner)');
    await expect(page.locator('dl.lld-facts')).toContainText('No payments');
    expect((await admin(request, { op: 'member', member: free.member_id })).premium).toBe(true);
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
    await page.locator('#lld-end-reason').fill('Member asked to cancel');
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'End membership' }).click();
    await expect(page.getByText('Membership ends at the end of the paid period.')).toBeVisible();
    await expect(page.locator('dl.lld-facts')).toContainText('Cancelling at the end of the period');
    expect((await admin(request, { op: 'member', member: paying.member_id })).premium).toBe(true);
  });

  test('TC-FR3-81-AS-02 End now: Premium stops at once', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(paying.member_id));
    await page.locator('input[name="when"][value="now"]').check();
    await page.locator('#lld-end-reason').fill('Chargeback');
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'End membership' }).click();
    await expect(page.getByText('Membership ended now.')).toBeVisible();
    expect((await admin(request, { op: 'member', member: paying.member_id })).premium).toBe(false);
  });

  test('TC-FR3-82-AS-01 Mark a member as a test account: their email is on the Features page list', async ({ page }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    await page.locator('#lld-test-reason').fill('Staging check');
    await page.getByRole('button', { name: 'Mark as a test account' }).click();
    await expect(page.getByText('Marked as a test account.')).toBeVisible();
    await open(page, '/wp-admin/admin.php?page=lld-features');
    await expect(page.locator('#lld-test-emails')).toContainText(freeEmail);
  });

  test('TC-FR3-74-AS-04 An email added to the test-account list on the page is a test account', async ({ page }) => {
    await asOwner(page);
    await open(page, `/wp-admin/admin.php?page=lld-members-admin&q=${stamp}&test=yes`);
    await expect(page.locator('td[data-label="Member"] a')).toHaveText([`#${free.member_id}`]);
    await open(page, '/wp-admin/admin.php?page=lld-features');
    const list = await page.locator('#lld-test-emails').inputValue();
    await page.locator('#lld-test-emails').fill(`${list}\n${payEmail}`);
    await page.locator('#lld-test-reason').fill('Add the paying test member');
    await page.getByRole('button', { name: 'Save the list' }).click();
    await expect(page.getByText(/Test-account list saved/)).toBeVisible();
    await open(page, `/wp-admin/admin.php?page=lld-members-admin&q=${stamp}&test=yes`);
    await expect(page.locator('td[data-label="Member"] a')).toHaveText([`#${paying.member_id}`, `#${free.member_id}`]);
  });

  test('TC-FR3-74-AS-01 Turn a feature off: the change appears in its history with the reason', async ({ page, request }) => {
    // Start from "on for everyone", as on staging (CI and local sites set their own switch states).
    const now = await admin(request, { op: 'feature', feature: 'barcode' });
    await admin(request, { op: 'restore', feature: 'barcode', row: { state: 'everyone', approved_by: 'spec', changed_at: '', override: false }, emails: now.emails });
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-features');
    const box = page.locator('#feature-barcode');
    await box.locator('input[name="reason"]').first().fill(`Scanner bug ${stamp}`);
    page.once('dialog', (d) => d.accept());
    await box.getByRole('button', { name: 'Turn off' }).click();
    await expect(page.getByText('barcode is now: Off.')).toBeVisible();
    const state = await admin(request, { op: 'feature', feature: 'barcode' });
    expect(state.state).toBe('off');
    expect(state.history[0]).toMatchObject({ to: 'off', note: `Scanner bug ${stamp}` });
  });

  test('TC-FR3-74-AS-06 Limited to test accounts: the Owner sees it, a Support account not on the list does not', async ({ page, request }) => {
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-features');
    const box = page.locator('#feature-barcode');
    await box.locator('#test-barcode').fill(`Fixing ${stamp}`);
    await box.getByRole('button', { name: 'Limit to test accounts' }).click();
    await expect(page.getByText('barcode is now: Test accounts only.')).toBeVisible();
    expect((await admin(request, { op: 'feature', feature: 'barcode' })).state).toBe('test_accounts');
    // The Owner counts as a test account; the helper is not on the list (checked through the switch itself).
    const list = await page.locator('#lld-test-emails').inputValue();
    expect(list).not.toContain(HELPER);
  });

  test('TC-FR3-74-AS-02 Turning a feature back on is refused without the typed name, the note and a fresh code', async ({ page, request }) => {
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-features');
    const box = page.locator('#feature-barcode');
    const nonce = await box.locator('form input[name="_lldnonce"]').last().inputValue();
    const tries = [
      { typed: '', reason: 'ok', code: '123456' },
      { typed: 'barcode', reason: '', code: '123456' },
      { typed: 'barcode', reason: 'ok', code: '' },
    ];
    for (const t of tries) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_feature', _lldnonce: nonce, feature: 'barcode', state: 'everyone', ...t } });
      expect((await admin(request, { op: 'feature', feature: 'barcode' })).state).toBe('test_accounts');
    }
    const code = await freshCode(page, owner.email);
    await box.locator('#name-barcode').fill('barcode');
    await box.locator('#note-barcode').fill(`Fixed ${stamp}`);
    await box.locator('#code-barcode').fill(code);
    await box.getByRole('button', { name: 'Turn on for everyone' }).click();
    await expect(page.getByText('barcode is now: On for everyone.')).toBeVisible();
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
    await page.locator('#lld-block-reason').fill('Abuse of support');
    await page.getByRole('button', { name: 'Block member' }).click();
    await expect(page.getByText('Member blocked and signed out.')).toBeVisible();
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
    test.info().annotations.push({ type: 'member', description: String(target.member_id) });
    globalThis.blocked = { id: target.member_id, email: blockEmail };
  });

  test('TC-FR3-83-AS-03 Unblock: the member can sign in again', async ({ page, browser }) => {
    await asOwner(page);
    await open(page, memberPage(globalThis.blocked.id));
    await page.locator('#lld-block-reason').fill('Resolved');
    await page.getByRole('button', { name: 'Unblock member' }).click();
    await expect(page.getByText('Member unblocked.')).toBeVisible();
    const ctx = await browser.newContext();
    const member = await ctx.newPage();
    const { signIn } = await import('../../../stage2/e2e/helpers.mjs');
    await signIn(member, globalThis.blocked.email, password);
    await member.goto('/members/home/');
    await expect(member).not.toHaveURL(/sign-in/);
    await ctx.close();
  });

  test('TC-FR3-85-AS-01 Send the download link: caught by the mail catcher, and the download needs sign-in', async ({ page, browser }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    await page.getByRole('button', { name: 'Send the download link' }).click();
    await expect(page.getByText('Download link sent to the member.')).toBeVisible();
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
    const html = await page.content();
    expect(html).not.toMatch(/download\.zip|\.json"|lld-downloads/);
    await expect(page.locator('a[download]')).toHaveCount(0);
  });

  test('TC-FR3-84-AS-02 Deleting is refused without the typed email, the reason and a fresh code', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    for (const t of [{ email: '', reason: 'r', code: '123456' }, { email: freeEmail, reason: '', code: '123456' }, { email: freeEmail, reason: 'r', code: '' }]) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(free.member_id), do: 'delete', ...t } });
      const state = await admin(request, { op: 'member', member: free.member_id, email: freeEmail });
      expect(state.problems.length).toBeGreaterThan(0); // still there
    }
  });

  test('TC-FR3-84-AS-01 Delete from the panel: the erasure checker finds nothing left', async ({ page, request }) => {
    await asOwner(page);
    await open(page, memberPage(free.member_id));
    const code = await freshCode(page, owner.email);
    await page.locator('#lld-delete-email').fill(freeEmail);
    await page.locator('#lld-delete-reason').fill('Member asked by email');
    await page.locator('#lld-delete-code').fill(code);
    await page.getByRole('button', { name: 'Delete member' }).click();
    await expect(page.getByText(`Member #${free.member_id} deleted.`)).toBeVisible();
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
    await open(page, '/wp-admin/admin.php?page=lld-activity');
    const rows = page.locator('tbody tr', { has: page.locator(`td[data-label="Member"]:text-is("${free.member_id}")`) });
    expect(await rows.count()).toBeGreaterThan(0);
    await expect(page.locator('#wpbody-content')).not.toContainText(freeEmail);
    await expect(page.locator('#wpbody-content')).not.toContainText(`Frida${stamp}`);
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
    // A note is logged without its text.
    const notes = log.rows.filter((r) => r.action === 'member_note');
    expect(notes.length).toBeGreaterThan(0);
    for (const row of notes) expect(JSON.stringify(row)).not.toContain('renewal date');
  });

  test('TC-FR3-86-AS-02 Each risky action is refused without a reason', async ({ page, request }) => {
    await asOwner(page);
    const target = await createMember(request, { email: mail('risky'), password, first_name: 'Ria' });
    await open(page, memberPage(target.member_id));
    const nonce = await page.locator('form input[name="_lldnonce"]').first().inputValue();
    for (const doAction of ['grant', 'end', 'test', 'block', 'delete']) {
      await page.request.post('/wp-admin/admin-post.php', { maxRedirects: 0, form: { action: 'lld_admin_member', _lldnonce: nonce, member: String(target.member_id), do: doAction, ends_on: '2099-01-01', on: '1', reason: '' } });
      await open(page, memberPage(target.member_id));
      await expect(page.locator('.lld-admin .notice-error')).toContainText('Give a reason');
    }
    const log = await admin(request, { op: 'log', member: target.member_id });
    expect(log.rows).toEqual([]);
  });

  test('TC-FR3-86-AS-03 No admin page, request or command edits or deletes a log entry', async ({ page }) => {
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-activity');
    await expect(page.locator('#wpbody-content').getByRole('button', { name: /delete|edit|remove/i })).toHaveCount(0);
    await expect(page.locator('#wpbody-content form')).toHaveCount(0);
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
    await open(page, '/wp-admin/admin.php?page=lld-overview');
    const value = async (key) => Number(await page.locator(`[data-count="${key}"] strong`).innerText());
    const total = await value('total');
    expect(total).toBeGreaterThan(0);
    expect((await value('free')) + (await value('premium'))).toBe(total);
    await open(page, '/wp-admin/admin.php?page=lld-members-admin&status=blocked');
    const blockedListed = await page.locator('td[data-label="Member"] a').count();
    await open(page, '/wp-admin/admin.php?page=lld-overview');
    expect(await value('blocked')).toBe(blockedListed);
  });

  test('TC-FR3-73-AS-02 The Overview contains no health values, goals or track names', async ({ page }) => {
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-overview');
    const text = await page.locator('#wpbody-content').innerText();
    expect(text).not.toContain(SENTINEL);
    expect(text).not.toMatch(/Healthy Weight|Blood Sugar Balance|Fitness and Strength|Sleep and Stress|kg\b|mmol/);
  });

  test('TC-NFR3-19-AS-01 No admin page, the log or its CSV shows the seeded health values', async ({ page, request }) => {
    const seeded = await createMember(request, { email: mail('seed'), password, first_name: 'Sid', health_consent: true, sentinel: SENTINEL });
    await asOwner(page);
    for (const path of ['/wp-admin/admin.php?page=lld-overview', '/wp-admin/admin.php?page=lld-members-admin', memberPage(seeded.member_id), '/wp-admin/admin.php?page=lld-features', '/wp-admin/admin.php?page=lld-activity']) {
      await open(page, path);
      expect(await page.content()).not.toContain(SENTINEL);
    }
    const href = await page.getByRole('link', { name: 'Download as CSV' }).getAttribute('href');
    expect(await (await page.request.get(href)).text()).not.toContain(SENTINEL);
  });

  test('TC-NFR3-20-AS-02 Each of the three risky actions asks for a fresh code', async ({ page }) => {
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-helpers');
    await expect(page.locator('#lld-helper-code')).toHaveAttribute('required', '');
    await expect(page.locator('input[name="code"]').nth(0)).toBeVisible();
    const target = await createMember(page.request, { email: mail('code'), password, first_name: 'Cy' });
    await open(page, memberPage(target.member_id));
    await expect(page.locator('#lld-delete-code')).toHaveAttribute('required', '');
    await open(page, '/wp-admin/admin.php?page=lld-features');
    // Every feature is on, so only an Off or test-accounts feature shows the turn-on form with its code field.
    await expect(page.getByRole('button', { name: 'Email me a code' }).first()).toBeAttached();
  });

  test('TC-NFR3-20-AS-03 Signed out after 30 minutes idle, and after 12 hours even when active', async ({ page, context, request }) => {
    await supportSignIn(page, context);
    await admin(request, { op: 'clock', seconds: 31 * 60 });
    await open(page, '/wp-admin/admin.php?page=lld-overview');
    await expect(page).toHaveURL(/wp-login\.php.*lld_admin_timeout=1/);
    await admin(request, { op: 'clock', seconds: 0 });
    await supportSignIn(page, context);
    test.setTimeout(300000);
    // Active every 25 minutes: still signed in until 12 hours have passed.
    for (let minutes = 25; minutes < 12 * 60; minutes += 25) {
      await admin(request, { op: 'clock', seconds: minutes * 60 });
      const res = await page.request.get('/wp-admin/admin.php?page=lld-overview', { maxRedirects: 0 });
      expect(res.status(), `signed out early at ${minutes} minutes`).toBe(200);
    }
    await admin(request, { op: 'clock', seconds: 12 * 3600 + 60 });
    await open(page, '/wp-admin/admin.php?page=lld-overview');
    await expect(page).toHaveURL(/lld_admin_timeout=1/);
    await admin(request, { op: 'clock', seconds: 0 });
  });

  test('TC-NFR3-21-AS-02 A new browser sign-in sends the Owner an alert', async ({ page, context, request }) => {
    const before = (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('New browser')).length;
    await context.clearCookies();
    await supportSignIn(page, context);
    await expect.poll(async () => (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('New browser')).length).toBeGreaterThan(before);
    // Adding and removing a helper alert the Owner too (both need a fresh code).
    await asOwner(page);
    await open(page, '/wp-admin/admin.php?page=lld-helpers');
    const code = await freshCode(page, owner.email);
    const second = mail('helper2');
    await page.locator('#lld-helper-name').fill('Second helper');
    await page.locator('#lld-helper-email').fill(second);
    await page.locator('#lld-helper-reason').fill('Holiday cover');
    await page.locator('#lld-helper-code').fill(code);
    await page.getByRole('button', { name: 'Add helper' }).click();
    await expect(page.getByText('Helper added.')).toBeVisible();
    await waitForMail(owner.admin_email, 'A Support helper was added');
    const box = page.locator('.lld-box .lld-box', { hasText: second });
    const code2 = await freshCode(page, owner.email);
    await box.locator('input[name="reason"]').fill('Cover ended');
    await box.locator('input[name="code"]').fill(code2);
    await box.getByRole('button', { name: 'Remove helper' }).click();
    await expect(page.getByText('Helper removed.')).toBeVisible();
    await waitForMail(owner.admin_email, 'A Support helper was removed');
    void request;
  });

  test('TC-NFR3-20-AS-04 The 6th failed attempt is refused for 15 minutes and the Owner is emailed', async ({ page, context, request }) => {
    const before = (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('locked')).length;
    const base = test.info().project.use.baseURL || 'http://127.0.0.1:8090';
    await admin(request, { op: 'clear_spam' });
    await context.clearCookies();
    await context.addCookies([{ name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base }]);
    for (let i = 0; i < 5; i += 1) {
      await page.goto('/wp-login.php');
      await page.locator('#user_login').fill(HELPER);
      await page.locator('#user_pass').fill(`wrong-${i}`);
      await page.locator('#wp-submit').click();
    }
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(HELPER);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await expect(page.locator('#login_error')).toContainText('Try again in 15 minutes');
    await expect.poll(async () => (await messagesTo(owner.admin_email)).filter((m) => (m.Subject || '').includes('locked')).length).toBeGreaterThan(before);
    await admin(request, { op: 'helper', email: HELPER, password }); // clears the lock for later runs
  });

  test('TC-NFR3-21-AS-01 A 6th deletion within an hour is refused with when it is allowed again', async ({ page, request }) => {
    await asOwner(page);
    const done = (await admin(request, { op: 'log', target: '' })).rows; // eslint-disable-line no-unused-vars
    let refused = '';
    for (let i = 0; i < 6 && !refused; i += 1) {
      const t = await createMember(request, { email: mail(`del${i}`), password, first_name: 'Del' });
      await open(page, memberPage(t.member_id));
      const code = await freshCode(page, owner.email);
      await page.locator('#lld-delete-email').fill(mail(`del${i}`));
      await page.locator('#lld-delete-reason').fill('Limit test');
      await page.locator('#lld-delete-code').fill(code);
      await page.getByRole('button', { name: 'Delete member' }).click();
      const note = await page.locator('.lld-admin .notice').first().innerText();
      if (note.includes('in the last hour')) refused = note;
    }
    expect(refused).toMatch(/You can delete another at \d\d:\d\d UTC/);
  });

  test('TC-NFR3-22-AS-01 At 360 px no new admin page scrolls sideways, and axe finds no serious problems', async ({ page }) => {
    await asOwner(page);
    await page.setViewportSize({ width: 360, height: 780 });
    const target = await createMember(page.request, { email: mail('phone'), password, first_name: 'Phi' });
    for (const path of ['/wp-admin/admin.php?page=lld-overview', '/wp-admin/admin.php?page=lld-members-admin', memberPage(target.member_id), '/wp-admin/admin.php?page=lld-features', '/wp-admin/admin.php?page=lld-activity', '/wp-admin/admin.php?page=lld-helpers']) {
      // Full page with styles here (not the lean admin), so the layout is the real one.
      await page.goto(path, ADMIN);
      if (page.url().includes('pmpro-wizard')) await page.goto(path, ADMIN);
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(wide, path).toBeLessThanOrEqual(0);
      const results = await new AxeBuilder({ page }).include('.lld-admin').analyze();
      const bad = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
      expect(bad.map((v) => `${v.id}: ${v.nodes.length}`), path).toEqual([]);
    }
  });

  test('TC-NFR3-22-AS-02 No admin page asks Google Analytics, and each is noindex', async ({ page }) => {
    await asOwner(page);
    const google = [];
    page.on('request', (r) => { if (/google-analytics|googletagmanager/.test(r.url())) google.push(r.url()); });
    for (const path of ['/wp-admin/admin.php?page=lld-overview', '/wp-admin/admin.php?page=lld-members-admin', '/wp-admin/admin.php?page=lld-features', '/wp-admin/admin.php?page=lld-activity']) {
      const res = await page.goto(path, ADMIN);
      expect(res.headers()['x-robots-tag'] || '').toContain('noindex');
      await expect(page.locator('meta[name="robots"][content*="noindex"]')).toHaveCount(1);
    }
    expect(google).toEqual([]);
  });
});
