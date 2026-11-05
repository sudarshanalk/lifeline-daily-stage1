// P3-83 (NFR3-12, NFR3-13): the targets Antigravity's phone-a11y pass found in the lead's files, live on the CI WordPress.
// Skip link and phone chip (lld-members.css), the stand-alone links under the Stage 3 sign-in form (class-sign-in.php),
// the cookie banner's line height and "Cookie settings" link (class-analytics.php), and the real Health Tools hub, which
// the Stage 3 browser job now serves with the Stage 1 mu-plugin (tests/ci/stage2-wp.sh, LLD_CI_STAGE1_TOOLS=1).
import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, signIn } from './helpers.mjs';

const CALC_MEMBER = 'p3-calc-b@test.lifelinedaily.invalid'; // a listed test account: sees the Stage 3 calculator cards

test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 360, height: 800 } });

const box = async (locator) => { const b = await locator.boundingBox(); return { w: Math.round(b.width), h: Math.round(b.height) }; };

test('TC-NFR3-12-AS-02 lead files: the stand-alone links under the sign-in forms are 44 px tall', async ({ page }) => {
  // CI keeps sign_in_code at test_accounts, where the plain address is Stage 2's screen; ?everywhere=1 and ?code=1 open the
  // Stage 3 forms (staging and launch are all on, so members always see these)
  for (const path of ['/members/sign-in/?everywhere=1', '/members/sign-in/?code=1']) {
    await page.goto(path);
    const links = page.locator('.lld-link-row a');
    const count = await links.count();
    expect(count, `${path}: stand-alone links`).toBeGreaterThan(1);
    for (let i = 0; i < count; i++) {
      const link = links.nth(i);
      const { w, h } = await box(link);
      expect(h, `${path}: "${(await link.textContent()).trim()}" height`).toBeGreaterThanOrEqual(44);
      expect(w, `${path}: "${(await link.textContent()).trim()}" width`).toBeGreaterThanOrEqual(44);
    }
    await expect(page.getByRole('link', { name: 'Join free', exact: true }).last()).toBeVisible();
  }
});

test('TC-NFR3-12-AS-02 lead files: the skip link is 44 px tall when focused and hidden before', async ({ page }) => {
  await page.goto('/members/sign-in/');
  const skip = page.locator('.lld-skip').first();
  const before = await skip.boundingBox();
  expect(before.y + before.height, 'skip link off-screen until focused').toBeLessThanOrEqual(0);
  await skip.focus();
  const { h, w } = await box(skip);
  expect(h).toBeGreaterThanOrEqual(44);
  expect(w).toBeGreaterThanOrEqual(44);
  const top = (await skip.boundingBox()).y;
  expect(top, 'focused skip link on screen').toBeGreaterThanOrEqual(0);
});

test('TC-NFR3-12-AS-02 lead files: the member chip is at least 44 by 44 px at 360 px', async ({ page }) => {
  const email = emailFor('p83-chip');
  await createMember(page.request, { email, password, first_name: 'Alex', consents: ['terms'] });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  const chip = page.locator('.lld-chip').first();
  await expect(chip).toBeVisible();
  const { w, h } = await box(chip);
  expect(w).toBeGreaterThanOrEqual(44);
  expect(h).toBeGreaterThanOrEqual(44);
});

test('TC-NFR3-12-AS-02 lead files: cookie banner lines are 24 px and Cookie settings is 44 px tall', async ({ page }) => {
  // the banner shows only while a measurement ID is set (as in e2e/analytics/analytics.spec.mjs); cleared at the end
  const setId = async (id) => { const res = await page.request.post('/wp-json/lld-members/v1/test/analytics', { data: { id } }); expect(res.ok(), await res.text()).toBeTruthy(); };
  await page.route(/googletagmanager\.com\/gtag\/js/, (route) => route.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
  await setId('G-TEST000CI');
  try {
  await page.goto('/pricing/');
  const banner = page.getByRole('region', { name: 'Cookies on Lifeline Daily' });
  await expect(banner).toBeVisible();
  const text = banner.locator('.lld-consent-text');
  const lineHeight = await text.evaluate((el) => parseFloat(getComputedStyle(el).lineHeight));
  expect(lineHeight, 'banner text line height (Owner question 118)').toBeGreaterThanOrEqual(24);
  await expect(text.getByRole('link', { name: 'Privacy policy' })).toBeVisible();
  await banner.getByRole('button', { name: 'Reject', exact: true }).click();
  // the footer link shows on theme pages (the member layout has its own footer link); the hub page is one
  await page.goto('/health-tools/');
  const settings = page.locator('[data-lld-consent-footer] a');
  await expect(settings).toBeVisible();
  {
    const { h, w } = await box(settings);
    expect(h).toBeGreaterThanOrEqual(44);
    expect(w).toBeGreaterThanOrEqual(44);
  }
  } finally { await setId(''); }
});

test('TC-NFR3-12-AS-01 lead files: the real Health Tools hub, with the six Stage 3 cards for a test account, has no sideways scroll at 360 px', async ({ page }) => {
  const guest = await page.request.get('/health-tools/');
  expect(guest.status(), 'the hub page is served by the Stage 1 mu-plugin').toBe(200);
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: CALC_MEMBER } });
  await createMember(page.request, { email: CALC_MEMBER, password, first_name: 'Alex', consents: ['terms'] });
  await signIn(page, CALC_MEMBER, password);
  await page.goto('/health-tools/');
  const hub = page.locator('[data-test="tools-hub"]');
  await expect(hub).toBeVisible();
  for (const slug of ['heart-risk-check', 'blood-pressure-check', 'metabolic-health-check', 'fitness-age', 'heart-rate-zones', 'sleep-and-stress-check']) {
    await expect(hub.locator(`[data-tool="${slug}"]`), `Stage 3 card ${slug}`).toHaveCount(1);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});
