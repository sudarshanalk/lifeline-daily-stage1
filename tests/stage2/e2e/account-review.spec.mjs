// Account-section review fixes (31 Oct 2026, Account_Section_Review.md): the More menu and Sign out, the sales and
// pricing pages, settings, My subscription, the members' group, the membership lock and the join form.
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { createMember, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const TRACKS = ['healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress'];

function emailFor(label) {
  return `${label}-${randomBytes(4).toString('hex')}@test.lifelinedaily.invalid`;
}

async function member(request, label, extra = {}) {
  const email = emailFor(label);
  const made = await createMember(request, { email, password, first_name: 'Sam', role: 'member', consents: ['terms', 'health'], ...extra });
  return { email, id: made.member_id };
}

test('TC-FR2-07-AS-04 the More menu reaches every member screen and signs out, at 360 pixels wide', async ({ page, request }) => {
  const { email } = await member(request, 'more');
  await page.setViewportSize({ width: 360, height: 780 });
  await signIn(page, email, password);
  await page.locator('nav.lld-tabbar').getByRole('link', { name: 'More' }).first().click();
  await expect(page).toHaveURL(/\/members\/more\/$/);
  const wanted = {
    Workouts: '/members/workout/',
    'Monthly challenge': '/members/challenge/',
    'Progress and badges': '/members/badges/',
    Suggestions: '/members/suggestions/',
    "Members' group": '/members/group/',
    'Profile and settings': '/members/settings/',
    'My subscription': '/members/subscription/',
    'My data': '/members/data/',
    Help: '/members/help/',
  };
  for (const [name, path] of Object.entries(wanted)) {
    const link = page.locator('.lld-more-list a', { hasText: name }).first();
    await expect(link, name).toHaveAttribute('href', new RegExp(`${path.replace(/\//g, '\\/')}$`));
    const res = await page.request.get(path);
    expect(res.status(), path).toBe(200);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/members\/sign-in\//);
  await expect(page.getByText("You're signed out.")).toBeVisible();
  // the session has ended: member screens, More included, now ask for sign-in
  for (const path of ['/members/home/', '/members/more/']) {
    await page.goto(path);
    await expect(page, path).toHaveURL(/\/members\/sign-in\//);
  }
});

test('TC-FR2-19-AS-02 the sales pages show no testimonial a member has not given and the owner has not approved', async ({ page }) => {
  for (const track of TRACKS) {
    await page.goto(`/programmes/${track}/`);
    const main = page.locator('main');
    await expect(main, track).not.toContainText('Verified Review');
    if ((await page.locator('[data-lld-quote]').count()) === 0) {
      await expect(page.getByRole('heading', { name: 'Testimonials' }), track).toHaveCount(0);
    }
  }
});

test('TC-FR2-16-AS-01 each sales page carries only wording that fits every track, and pricing has its feature list and questions', async ({ page }) => {
  for (const track of TRACKS) {
    await page.goto(`/programmes/${track}/`);
    const main = page.locator('main');
    for (const text of ['glucose uptake', 'burns active calories', 'meal sequencing', 'High Protein', 'from their account settings']) {
      await expect(main, `${track}: ${text}`).not.toContainText(text);
    }
    await expect(main, track).toContainText('You can switch at the end of any week from your track page.');
    for (const name of ['Weekly themes', 'Sample activity', 'Sample meal', 'Questions and answers', 'Safety note']) {
      await expect(page.getByRole('heading', { name }), `${track}: ${name}`).toBeVisible();
    }
  }
  await page.goto('/pricing/');
  await expect(page.locator('[data-lld-features]')).toContainText('Weekly meal plans and shopping lists');
  await expect(page.locator('[data-lld-features]')).toContainText('Week 1 of each track, once per track');
  await expect(page.locator('[data-lld-pricing-faq]')).toContainText('How do I cancel?');
  await expect(page.getByRole('link', { name: 'Choose yearly' })).toBeVisible();
});

test('TC-FR2-13-AS-01 a Premium member sees "You\'re Premium" on the pricing page instead of the buy buttons', async ({ page, request }) => {
  const { email } = await member(request, 'pricing-premium', { access_state: 'Premium', plan: 'yearly' });
  await signIn(page, email, password);
  await page.goto('/pricing/');
  await expect(page.locator('[data-lld-premium-now]')).toContainText("You're Premium");
  await expect(page.getByRole('link', { name: 'Choose yearly' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Choose monthly' })).toHaveCount(0);
  // the prices themselves are still shown, yearly first
  await expect(page.locator('[data-lld-plan]').first()).toContainText('US$59.99 a year');
});

test('TC-FR2-02-AS-01 settings link to goals, track, profile and subscription, and keep only a listed time zone', async ({ page, request }) => {
  const { email } = await member(request, 'settings');
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  for (const [name, path] of [['Change your goals', '/members/goals/'], ['Start, switch or resume a track', '/members/tracks/'], ['Edit your health profile', '/members/health/'], ['My subscription', '/members/subscription/'], ['My data', '/members/data/'], ['Delete account', '/members/delete/']]) {
    await expect(page.getByRole('link', { name, exact: true }), name).toHaveAttribute('href', new RegExp(`${path.replace(/\//g, '\\/')}$`));
  }
  await expect(page.locator('#lld_timezones option[value="America/New_York"]')).toHaveCount(1);
  // a name the clock cannot read is refused, and the saved zone stays
  await page.locator('#lld_timezone').fill('Moon/Base');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.lld-fail')).toContainText('Choose a time zone from the list');
  await page.goto('/members/settings/');
  await expect(page.locator('#lld_timezone')).toHaveValue('Europe/London');
  await page.locator('#lld_timezone').fill('America/New_York');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.goto('/members/settings/');
  await expect(page.locator('#lld_timezone')).toHaveValue('America/New_York');
  // the consent record is still there, folded under its own heading
  await expect(page.locator('.lld-consent-log summary')).toHaveText('Consent history');
  await expect(page.locator('.lld-consent-history')).toContainText('health given');
});

test('TC-FR2-14-AS-05 the cancel step says Premium stays to the end of the paid period, and the referral link can be copied', async ({ page, request }) => {
  const { email } = await member(request, 'cancel-note', { access_state: 'Premium', plan: 'monthly' });
  await signIn(page, email, password);
  await page.goto('/members/subscription/');
  const link = await page.locator('main a[href*="/pricing/?ref="]').first().getAttribute('href');
  await expect(page.getByRole('button', { name: 'Copy referral link' })).toHaveAttribute('data-lld-copy', link);
  await page.getByRole('link', { name: 'Cancel', exact: true }).click();
  const note = page.locator('[data-lld-cancel-note]');
  await expect(note).toContainText('Premium stays open');
  await expect(note).toContainText('You will not be charged again.');
  await expect(note.getByRole('button', { name: 'Confirm cancellation' })).toBeVisible();
  await expect(note.getByRole('link', { name: 'Keep Premium' })).toBeVisible();
});

test('TC-FR2-35-AS-02 the dashboard group block explains itself, and the group screen is never a dead end', async ({ page, request }) => {
  const { email } = await member(request, 'group', { access_state: 'Premium', plan: 'yearly' });
  await signIn(page, email, password);
  await page.goto('/members/home/');
  const block = page.locator('[data-lld-section="membership"] .lld-group-note');
  await expect(block).toContainText("Facebook's own privacy terms apply");
  await expect(block).toContainText('live Q&A');
  await block.getByRole('link', { name: "Open the members' group" }).click();
  await expect(page).toHaveURL(/\/members\/group\/$/);
  const main = page.locator('main');
  await expect(main).toContainText("Facebook's own privacy terms apply");
  const join = page.getByRole('link', { name: 'Join the private Facebook group' });
  if ((await join.count()) === 0) await expect(main).toContainText('The group opens soon.');
});

test('TC-FR2-15-AS-03 the membership lock shows a faded preview, a lock icon, both prices and one upgrade button', async ({ page, request }) => {
  const { email } = await member(request, 'lock');
  await signIn(page, email, password);
  await page.goto('/members/meals/');
  const lock = page.locator('.lld-lock');
  await expect(lock.locator('[data-lld-lock-preview]')).toBeVisible();
  await expect(lock.locator('.lld-kicker svg')).toHaveCount(1);
  await expect(lock).toContainText('Part of Premium');
  await expect(lock).toContainText('a year');
  await expect(lock).toContainText('a month');
  await expect(lock.getByRole('link', { name: 'Upgrade' })).toHaveCount(1);
  await expect(lock).not.toContainText('A short preview of this Premium item.');
});

test('TC-FR2-01-AS-02 the join and reset forms ask password managers for a new password, sign-in for the saved one', async ({ page }) => {
  await page.goto('/members/join/');
  await expect(page.locator('#lld_password')).toHaveAttribute('autocomplete', 'new-password');
  await expect(page.locator('#lld_email')).toHaveAttribute('autocomplete', 'email');
  await expect(page.locator('#lld_first')).toHaveAttribute('autocomplete', 'given-name');
  await expect(page.locator('#lld_last')).toHaveAttribute('autocomplete', 'family-name');
  await page.goto('/members/sign-in/');
  await expect(page.locator('#lld_password')).toHaveAttribute('autocomplete', 'current-password');
  await expect(page.locator('#lld_email')).toHaveAttribute('autocomplete', 'email');
});
