import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { refuseOtherMember, SENTINEL } from '../tools/access-runner.mjs';
import { probeSignedOut } from '../tools/signed-out-prober.mjs';
import { createMember, mailCode, restNonce, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const tm03 = 'tm-03@test.lifelinedaily.invalid';
const tm04 = 'tm-04@test.lifelinedaily.invalid';
const tm20 = 'tm-20@test.lifelinedaily.invalid';
const tm02 = 'tm-02@test.lifelinedaily.invalid';

test.beforeAll(async ({ request }) => {
  await request.post('/wp-json/lld-members/v1/test/stripe', { data: { unreachable: false } });
});

test('TC-NFR2-02-AS-01', async ({ page }) => {
  const created = await createMember(page.request, {
    email: tm04,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    sentinel: SENTINEL,
    access_state: 'Premium',
  });
  await createMember(page.request, { email: tm03, password, first_name: 'Sam', role: 'member', health_consent: true });

  const stored = await page.request.get(`/wp-json/lld-members/v1/test/ciphertext?member_id=${created.member_id}`);
  expect(await stored.text()).not.toContain(SENTINEL);

  await signIn(page, tm04, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  const ownerNonce = await restNonce(page);
  const own = await page.request.get(`/wp-json/lld-members/v1/members/${created.member_id}/journey`, {
    headers: { 'X-WP-Nonce': ownerNonce },
  });
  expect(own.status()).toBe(200);
  expect(await own.text()).toContain(SENTINEL);

  await page.context().clearCookies();
  await signIn(page, tm03, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  const nonce = await restNonce(page);
  const catalog = await page.request.get('/wp-json/lld-members/v1/routes', { headers: { 'X-WP-Nonce': nonce } });
  expect(catalog.status()).toBe(200);
  const { routes } = await catalog.json();
  expect(routes.length).toBeGreaterThan(0);
  const failures = await refuseOtherMember(page.request, { routes, memberId: created.member_id, sentinel: SENTINEL, nonce });
  expect(failures).toEqual([]);

  await page.context().clearCookies();
  const signedOut = await probeSignedOut(page.request, routes.map((route) => route.path.replaceAll('{id}', String(created.member_id))));
  expect(signedOut).toEqual([]);
});

test('TC-NFR2-02-AS-02', async ({ request }) => {
  await createMember(request, { email: tm03, password, first_name: 'Sam', role: 'member' });
  const res = await request.get('/wp-json/lld-members/v1/security/password-storage');
  expect(res.status()).toBe(200);
  const body = await res.text();
  const report = JSON.parse(body);
  expect(report.all_hashed).toBe(true);
  expect(report.count).toBeGreaterThan(0);
  expect(body).not.toContain(password);
  for (const prefix of report.prefixes) {
    expect(prefix.startsWith('$')).toBe(true);
  }
});

test('TC-NFR2-02-AS-03', async ({ page, context }) => {
  await createMember(page.request, { email: tm20, password, first_name: 'Owner', role: 'owner_admin' });
  await createMember(page.request, { email: tm03, password, first_name: 'Sam', role: 'member', two_step: false });

  const base = test.info().project.use.baseURL || 'http://127.0.0.1:8090';
  await context.addCookies([{ name: 'wordpress_test_cookie', value: 'WP Cookie check', url: base }]);
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('tm-20');
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  await expect(page).not.toHaveURL(/wp-admin/);
  const adminCode = await mailCode(tm20);
  await page.locator('#lld_code').fill(adminCode);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();

  await context.clearCookies();
  await signIn(page, tm03, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.goto('/members/settings/');
  await page.getByRole('checkbox', { name: 'Email me a code when I sign in' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();

  await context.clearCookies();
  await signIn(page, tm03, password);
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  const memberCode = await mailCode(tm03);
  await page.locator('#lld_code').fill(memberCode);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
});

test('TC-NFR2-06-AS-03', async ({ page }) => {
  await createMember(page.request, { email: tm04, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await createMember(page.request, { email: tm02, password, first_name: 'Free', role: 'member', access_state: 'free' });
  const blocked = await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { unreachable: true } });
  expect(blocked.ok()).toBe(true);

  await signIn(page, tm04, password);
  await expect(page.getByText('Your membership is Premium.')).toBeVisible();
  await expect(page.getByText('try again shortly')).toHaveCount(0);

  await page.context().clearCookies();
  await signIn(page, tm02, password);
  await page.goto('/members/checkout/');
  await expect(page.getByText('try again shortly')).toBeVisible();

  await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { unreachable: false } });
});
