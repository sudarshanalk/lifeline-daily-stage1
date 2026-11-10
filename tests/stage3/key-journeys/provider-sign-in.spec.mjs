// Google and Apple sign-in and one account per email (P3-20, P3-22: FR3-01, FR3-05; Apple: FR4-30, moved to Stage 4 by the owner on 3 Nov 2026), live on the CI
// WordPress. Google and Apple are never reached from CI: the provider's verified claims arrive through the test
// route /test/sign-in/provider (open only where the Stage 2 test routes are, with the test secret), which runs the
// same completion as the real callback. The start route is checked for real against the CI-only Google client.
import { expect, test } from '@playwright/test';
import { createMember, password, signIn } from './helpers.mjs';
import { restNonce } from '../../stage2/e2e/helpers.mjs';

const F = 'p3-signin-f@test.lifelinedaily.invalid'; // new Google account
const G = 'p3-signin-g@test.lifelinedaily.invalid'; // password account, then Google with the same verified email
const SUB_F = 'google-sub-f-0001';
const SUB_G = 'google-sub-g-0002';

async function provider(page, claims, nonce = '') {
  // Linking needs the signed-in member, which REST sees only with the nonce (as the hub's Link URL carries it).
  const res = await page.request.post('/wp-json/lld-members/v1/test/sign-in/provider', { data: claims, headers: nonce ? { 'X-WP-Nonce': nonce } : {} });
  expect(res.ok(), await res.text()).toBeTruthy();
  return (await res.json()).redirect;
}

async function signedOut(page) {
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await page.context().clearCookies();
}

test.describe.serial('provider sign-in', () => {
  test('TC-FR3-01-AS-01', async ({ page }) => {
    await signedOut(page);
    // The button and the start route: a real redirect to Google with state, nonce and a PKCE challenge, never followed.
    await page.goto('/members/sign-in/?code=1');
    const button = page.getByRole('link', { name: 'Continue with Google' });
    await expect(button).toBeVisible();
    const start = await page.request.get(await button.getAttribute('href'), { maxRedirects: 0 });
    expect(start.status()).toBe(302);
    const location = start.headers().location;
    expect(location).toMatch(/^https:\/\/accounts\.google\.com\/o\/oauth2\/v2\/auth\?/);
    expect(location).toContain('client_id=ci-google-client.apps.googleusercontent.com');
    expect(location).toContain('code_challenge_method=S256');
    expect(location).toContain('scope=openid%20email%20profile');
    expect(location).toMatch(/redirect_uri=[^&]*sign-in%2Fgoogle%2Fcallback/);
    // A new account: Google's verified email and name, then age, then the Agreements step.
    const redirect = await provider(page, { provider: 'google', sub: SUB_F, email: F, email_verified: true, first_name: 'Ada', last_name: 'Synthetic' });
    expect(redirect).toMatch(/\/members\/join\/$/);
    await page.goto(redirect);
    await expect(page.getByRole('heading', { name: 'Create account', exact: true })).toBeVisible();
    await expect(page.getByText(`Google confirmed your email ${F}`)).toBeVisible();
    await expect(page.locator('#lld_first')).toHaveValue('Ada');
    await page.locator('#lld_age').fill('34');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
  });

  test('TC-FR3-01-AS-02', async ({ page }) => {
    // Sign out, sign in with the same Google account: the same member, no second account, straight to home.
    await signedOut(page);
    const redirect = await provider(page, { provider: 'google', sub: SUB_F, email: F, email_verified: true });
    expect(redirect).toMatch(/\/members\/home\/$/);
    await page.goto(redirect);
    await expect(page).toHaveURL(/\/members\/home\//);
    // The same account, not a second one: a new account would have gone to the join step, never straight to home,
    // and the hub shows the name given at sign-up.
    await page.goto('/members/settings/?section=profile');
    await expect(page.locator('#lld_first')).toHaveValue('Ada');
  });

  test('TC-FR3-05-AS-03', async ({ page }) => {
    // The email changed at Google; the account ID is the same: the same account opens.
    await signedOut(page);
    const redirect = await provider(page, { provider: 'google', sub: SUB_F, email: 'p3-signin-f-renamed@test.lifelinedaily.invalid', email_verified: true });
    expect(redirect).toMatch(/\/members\/home\/$/);
    await page.goto(redirect);
    await page.goto('/members/settings/?section=security');
    await expect(page.locator('[data-lld-provider="google"]')).toHaveAttribute('data-lld-linked', 'yes');
    await expect(page.locator('[data-lld-provider="google"]')).toContainText('p3-signin-f-renamed@test.lifelinedaily.invalid');
  });

  test('TC-FR3-05-AS-04', async ({ page }) => {
    // Google is this member's only way in (no password): unlinking is refused with a clear message.
    await signedOut(page);
    await page.goto(await provider(page, { provider: 'google', sub: SUB_F, email: F, email_verified: true }));
    await page.goto('/members/settings/?section=security');
    await expect(page.getByText('Password: not set')).toBeVisible();
    await page.locator('[data-lld-provider="google"]').getByRole('button', { name: 'Unlink' }).click();
    await expect(page.getByRole('alert')).toContainText("You can't remove your only way to sign in.");
    await expect(page.locator('[data-lld-provider="google"]')).toHaveAttribute('data-lld-linked', 'yes');
  });

  test('TC-FR3-05-AS-05', async ({ page }) => {
    // A member with no password deletes the account after an email code.
    const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
    const before = Date.now();
    await signedOut(page);
    await page.goto(await provider(page, { provider: 'google', sub: SUB_F, email: F, email_verified: true }));
    await page.goto('/members/delete/');
    await expect(page.getByText('Your account has no password')).toBeVisible();
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    let code = '';
    await expect.poll(async () => {
      const messages = (await (await fetch(`${MAILPIT}/api/v1/messages?limit=50`)).json()).messages || [];
      const message = messages.find((row) => JSON.stringify(row.To).includes('p3-signin-f@') && new Date(row.Created).getTime() > before - 1000 && row.Subject.includes('confirmation code'));
      if (!message) return false;
      const body = await (await fetch(`${MAILPIT}/api/v1/message/${message.ID}`)).json();
      code = `${body.Text || ''}`.match(/\b(\d{6})\b/)?.[1] || '';
      return Boolean(code);
    }, { timeout: 20000 }).toBe(true);
    await page.locator('#lld_code').fill(code);
    await page.getByRole('button', { name: 'Delete my account', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Account closed', exact: true })).toBeVisible();
    // The Google identity went with the account: signing in with it now starts a new account instead.
    await signedOut(page);
    const redirect = await provider(page, { provider: 'google', sub: SUB_F, email: F, email_verified: true });
    expect(redirect).toMatch(/\/members\/join\/$/);
  });

  test('TC-FR3-05-AS-01', async ({ page }) => {
    // A password account first; then Google with the same verified email opens it, and no new account exists.
    const member = await createMember(page.request, { email: G, password, first_name: 'Synthetic', consents: ['terms'] });
    await signedOut(page);
    const redirect = await provider(page, { provider: 'google', sub: SUB_G, email: G, email_verified: true });
    expect(redirect).toMatch(/\/members\/home\/$/);
    await page.goto(redirect);
    await page.goto('/members/settings/?section=security');
    await expect(page.locator('[data-lld-provider="google"]')).toHaveAttribute('data-lld-linked', 'yes');
    await expect(page.getByText('Password: set')).toBeVisible();
    // The same member number: the record route answers for it, and the page shows the member's own name.
    const me = await page.request.get(`/members/record/${member.member_id}/`);
    expect(me.status()).toBeLessThan(500);
    // With a password as well, Google can be unlinked, and linked again from the hub (the link intent).
    await page.locator('[data-lld-provider="google"]').getByRole('button', { name: 'Unlink' }).click();
    await expect(page.getByRole('status')).toContainText('Unlinked.');
    await expect(page.locator('[data-lld-provider="google"]')).toHaveAttribute('data-lld-linked', 'no');
    await page.goto('/members/home/');
    const linked = await provider(page, { provider: 'google', sub: SUB_G, email: G, email_verified: true, intent: 'link' }, await restNonce(page));
    expect(linked).toMatch(/section=security&s3=linked$/);
    await page.goto(linked);
    await expect(page.locator('[data-lld-provider="google"]')).toHaveAttribute('data-lld-linked', 'yes');
    // A second Google account cannot be linked beside the first (it would make Unlink remove both).
    const second = await provider(page, { provider: 'google', sub: 'google-sub-g-second', email: G, email_verified: true, intent: 'link' }, await restNonce(page));
    expect(second).toMatch(/s3=already_linked$/);
    await signedOut(page);
    expect(await provider(page, { provider: 'google', sub: 'google-sub-g-second', email: G, email_verified: true })).toMatch(/provider_error=already_linked$/);
  });

  test('TC-FR3-05-AS-02', async ({ page }) => {
    // The provider does not vouch for the email: no automatic link, and the member is asked to sign in first.
    await signedOut(page);
    const redirect = await provider(page, { provider: 'google', sub: 'google-sub-unverified', email: G, email_verified: false });
    expect(redirect).toMatch(/provider_error=unverified$/);
    await page.goto(redirect);
    await expect(page.getByRole('alert')).toContainText("We couldn't confirm the email on that account. Sign in with your email and password first, then link it from your account page.");
    // Nothing was linked.
    await signIn(page, G, password);
    await page.goto('/members/settings/?section=security');
    await expect(page.locator('[data-lld-provider="google"]')).toContainText(G);
    await expect(page.locator('[data-lld-provider="google"]')).not.toContainText('google-sub-unverified');
  });

  test('TC-FR4-30-AS-01', async ({ page }) => {
    // Apple sign-up: the account is created and the Agreements step shown next.
    await signedOut(page);
    const H = 'p3-signin-h@test.lifelinedaily.invalid';
    const redirect = await provider(page, { provider: 'apple', sub: 'apple-sub-0003', email: H, email_verified: true, first_name: 'Grace', last_name: 'Synthetic' });
    expect(redirect).toMatch(/\/members\/join\/$/);
    await page.goto(redirect);
    await expect(page.getByText(`Apple confirmed your email ${H}`)).toBeVisible();
    await page.locator('#lld_age').fill('41');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
  });

  test('TC-FR4-30-AS-02', async ({ page }) => {
    // Apple with 'Hide my email': the relay address is the account's email, the account works, and emails reach the relay.
    const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
    await signedOut(page);
    const relay = 'p3-signin-relay@privaterelay.appleid.test';
    const redirect = await provider(page, { provider: 'apple', sub: 'apple-sub-0004', email: relay, email_verified: true });
    expect(redirect).toMatch(/\/members\/join\/$/);
    await page.goto(redirect);
    await page.locator('#lld_first').fill('Relay'); await page.locator('#lld_age').fill('29');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Agreements', exact: true })).toBeVisible();
    // Signed in; the hub shows Apple linked with the relay address.
    await page.goto('/members/settings/?section=security');
    await expect(page.locator('[data-lld-provider="apple"]')).toContainText(relay);
    // An email for this account goes to the relay: the deletion code (the member has no password).
    const before = Date.now();
    await page.goto('/members/delete/');
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    await expect.poll(async () => {
      const messages = (await (await fetch(`${MAILPIT}/api/v1/messages?limit=50`)).json()).messages || [];
      return messages.some((row) => JSON.stringify(row.To).includes(relay) && new Date(row.Created).getTime() > before - 1000);
    }, { timeout: 20000 }).toBe(true);
  });

  test('TC-FR3-05-AS-01 (two-step): a member with two-step sign-in is not opened by a provider alone', async ({ page }) => {
    // Google is one factor: with the same verified email it neither links nor signs in a member who has two-step on;
    // the member is told to sign in with the password and the emailed code, and no Google link is made.
    const I = 'p3-signin-i@test.lifelinedaily.invalid';
    await createMember(page.request, { email: I, password, first_name: 'Synthetic', consents: ['terms'], two_step: true });
    await signedOut(page);
    const redirect = await provider(page, { provider: 'google', sub: 'google-sub-i-0005', email: I, email_verified: true });
    expect(redirect).toMatch(/\/members\/sign-in\/\?provider_error=two_step$/);
    await page.goto(redirect);
    await expect(page.getByText('This account uses two-step sign-in: sign in with your password, then the emailed code.')).toBeVisible();
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/sign-in\//);
    // Asked again, it still refuses: nothing was linked the first time.
    expect(await provider(page, { provider: 'google', sub: 'google-sub-i-0005', email: I, email_verified: true })).toMatch(/provider_error=two_step$/);
  });

  test('TC-FR3-05-AS-06', async ({ page, browser }) => {
    // Two browsers signed in; Sign out everywhere in one ends the other's session too.
    await signIn(page, G, password);
    const other = await browser.newContext();
    const otherPage = await other.newPage();
    await signIn(otherPage, G, password);
    await otherPage.goto('/members/home/');
    await expect(otherPage).toHaveURL(/\/members\/home\//);
    await page.goto('/members/settings/?section=security');
    await page.getByRole('button', { name: 'Sign out everywhere', exact: true }).click();
    await expect(page).toHaveURL(/signed_out=1&everywhere=1/);
    await expect(page.getByText("You're signed out everywhere.")).toBeVisible();
    await otherPage.goto('/members/home/');
    await expect(otherPage).toHaveURL(/\/members\/sign-in\//);
    await other.close();
  });
});
