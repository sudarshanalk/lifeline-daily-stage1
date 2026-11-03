// P3-70 and P3-71 on the CI WordPress: Google Analytics 4 behind the cookie banner (FR3-66, FR3-67, NFR3-16).
// The measurement ID is set through the test route for this file only. The Google tag is answered by a stub, so no
// request leaves the machine; every request to a Google analytics host is recorded, and window.dataLayer shows
// exactly what the page asked the tag to send.
import { expect, test } from '@playwright/test';
import { createMember, emailFor, password, serveHealthyWeightTool, signIn } from '../../key-journeys/helpers.mjs';

const GA_ID = 'G-TEST000CI';
const GOOGLE = /(googletagmanager\.com|google-analytics\.com|analytics\.google\.com)/;

async function setId(request, id) {
  const res = await request.post('/wp-json/lld-members/v1/test/analytics', { data: { id } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function watch(page) {
  const seen = [];
  page.on('request', (req) => { if (GOOGLE.test(req.url())) seen.push(req.url()); });
  await page.route(/googletagmanager\.com\/gtag\/js/, (route) => route.fulfill({ status: 200, contentType: 'text/javascript', body: 'window.__lldTagLoaded=true;' }));
  return seen;
}

const layer = (page) => page.evaluate(() => (window.dataLayer || []).map((a) => JSON.parse(JSON.stringify(Array.from(a)))));
const events = async (page) => (await layer(page)).filter((a) => a[0] === 'event').map((a) => ({ name: a[1], params: a[2] || {} }));
const gaCookies = async (page) => (await page.context().cookies()).filter((c) => c.name === '_ga' || c.name.startsWith('_ga_') || c.name === '_gid');
const bannerOf = (page) => page.getByRole('region', { name: 'Cookies on Lifeline Daily' });
async function consent(page, value) {
  const url = new URL(process.env.LLD_WP_URL || 'http://127.0.0.1:8090');
  await page.context().addCookies([{ name: 'lld_consent', value, domain: url.hostname, path: '/' }]);
}

test.describe.configure({ mode: 'serial' });
test.beforeAll(async ({ request }) => { await setId(request, GA_ID); });
test.afterAll(async ({ request }) => {
  await setId(request, '');
  await request.post('/wp-json/lld-members/v1/test/growth', { data: { action: 'domain', domain: '' } });
});

test('TC-FR3-67-AS-01', async ({ page }) => {
  await watch(page);
  await page.goto('/pricing/');
  const banner = bannerOf(page);
  await expect(banner).toBeVisible();
  const accept = await banner.getByRole('button', { name: 'Accept', exact: true }).boundingBox();
  const reject = await banner.getByRole('button', { name: 'Reject', exact: true }).boundingBox();
  expect(Math.round(accept.width)).toBe(Math.round(reject.width));
  expect(Math.round(accept.height)).toBe(Math.round(reject.height));
  const style = (name) => banner.getByRole('button', { name, exact: true }).evaluate((b) => { const s = getComputedStyle(b); return [s.fontSize, s.fontWeight, s.backgroundColor, s.color].join('|'); });
  expect(await style('Accept')).toBe(await style('Reject'));
});

test('TC-FR3-67-AS-02', async ({ page }) => {
  const seen = await watch(page);
  for (const path of ['/pricing/', '/', '/programmes/']) {
    await page.goto(path);
    await expect(bannerOf(page)).toBeVisible();
  }
  await page.waitForTimeout(500);
  expect(seen).toEqual([]);
  expect(await events(page)).toEqual([]);
  // Consent Mode starts denied for everything.
  const defaults = (await layer(page)).find((a) => a[0] === 'consent' && a[1] === 'default');
  expect(defaults[2]).toEqual({ analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
});

test('TC-FR3-67-AS-03', async ({ page }) => {
  const seen = await watch(page);
  await page.goto('/pricing/');
  await page.getByRole('button', { name: 'Reject', exact: true }).click();
  await expect(bannerOf(page)).toBeHidden();
  await page.goto('/');
  await page.goto('/pricing/');
  await expect(bannerOf(page)).toBeHidden();
  await page.waitForTimeout(500);
  expect(seen).toEqual([]);
  expect(await gaCookies(page)).toEqual([]);
  expect((await page.context().cookies()).find((c) => c.name === 'lld_consent').value).toBe('denied');
});

test('TC-FR3-67-AS-04', async ({ page }) => {
  const seen = await watch(page);
  await page.goto('/pricing/');
  await page.getByRole('button', { name: 'Reject', exact: true }).click();
  await page.locator('.lld-footer').getByRole('link', { name: 'Cookie settings' }).click();
  const banner = bannerOf(page);
  await expect(banner).toBeVisible();
  await banner.getByRole('button', { name: 'Accept', exact: true }).click();
  await expect.poll(() => seen.some((u) => u.includes(`gtag/js?id=${GA_ID}`))).toBe(true);
  expect((await page.context().cookies()).find((c) => c.name === 'lld_consent').value).toBe('granted');
  // Back to Reject: the page reloads without the tag, and the Google cookies are removed.
  await page.context().addCookies([{ name: '_ga', value: 'GA1.1.1.1', url: new URL('/', page.url()).href }]);
  await page.locator('.lld-footer').getByRole('link', { name: 'Cookie settings' }).click();
  await Promise.all([page.waitForEvent('load'), banner.getByRole('button', { name: 'Reject', exact: true }).click()]);
  await page.waitForTimeout(500);
  const before = seen.length;
  await page.goto('/pricing/');
  await page.waitForTimeout(500);
  expect(seen.length).toBe(before);
  expect(await gaCookies(page)).toEqual([]);
  // Theme pages carry the same way back to the choice.
  await page.goto('/');
  await page.getByRole('link', { name: 'Cookie settings' }).first().click();
  await expect(banner).toBeVisible();
});

test('TC-FR3-66-AS-01', async ({ page }) => {
  const seen = await watch(page);
  await page.goto('/pricing/?utm_source=newsletter&email=someone%40example.test');
  await page.getByRole('button', { name: 'Accept', exact: true }).click();
  await expect.poll(() => seen.some((u) => u.includes(`gtag/js?id=${GA_ID}`))).toBe(true);
  const views = (await events(page)).filter((e) => e.name === 'page_view');
  expect(views).toHaveLength(1);
  expect(new URL(views[0].params.page_location).pathname).toBe('/pricing/');
  expect(views[0].params.page_location).toContain('utm_source=newsletter');
  expect(views[0].params.page_location).not.toContain('email');
  const config = (await layer(page)).find((a) => a[0] === 'config');
  expect(config[1]).toBe(GA_ID);
  expect(config[2]).toMatchObject({ send_page_view: false, allow_google_signals: false, allow_ad_personalization_signals: false });
});

test('TC-FR3-66-AS-02', async ({ page }) => {
  const email = emailFor('ga-checkout');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms'] });
  await signIn(page, email, password);
  await consent(page, 'granted');
  await watch(page);
  await page.goto('/members/checkout/?plan=monthly');
  const checkout = (await events(page)).filter((e) => e.name === 'begin_checkout');
  expect(checkout).toEqual([{ name: 'begin_checkout', params: { plan: 'monthly' } }]);
  const sent = JSON.stringify(await layer(page));
  expect(sent).not.toContain(email);
  expect(sent).not.toContain('Synthetic');
});

test('TC-FR3-66-AS-03', async ({ page, request }) => {
  const seen = [];
  page.on('request', (req) => { if (req.url().includes('plausible.io')) seen.push(req.url()); });
  await page.route('https://plausible.io/**', (route) => route.fulfill({ status: 202, body: 'ok' }));
  await watch(page);
  // Even with a Plausible domain configured, GA4 keeps the Plausible script off every page.
  await request.post('/wp-json/lld-members/v1/test/growth', { data: { action: 'domain', domain: 'stg.example.test' } });
  await consent(page, 'granted');
  for (const path of ['/', '/pricing/', '/programmes/', '/health-tools/protein/']) {
    await page.goto(path);
    expect(await page.locator('#lld-analytics').count(), path).toBe(0);
  }
  await page.waitForTimeout(500);
  expect(seen).toEqual([]);
});

test('TC-FR3-66-AS-04', async ({ page }) => {
  const seen = await watch(page);
  await serveHealthyWeightTool(page);
  await consent(page, 'granted');
  await page.goto('/health-tools/healthy-weight-check/');
  await expect.poll(() => seen.some((u) => u.includes('gtag/js'))).toBe(true);
  const before = await layer(page);
  const requests = seen.length;
  const frame = page.frameLocator('.lld-tool-frame');
  await frame.locator('#age').fill('47'); await frame.locator('#ht').fill('171'); await frame.locator('#wt').fill('83');
  for (const [group, value] of [['preg', 'no'], ['eat', 'no'], ['bg', 'White'], ['loss', 'no']]) await frame.locator(`[data-tap="${group}"] [data-val="${value}"]`).click();
  await frame.locator('#submitBtn').click();
  await expect(frame.locator('[data-test="bmi"]')).toContainText('28.4');
  await page.waitForTimeout(800);
  // Moving to the result adds nothing: no page view, no event, no request.
  expect(await layer(page)).toEqual(before);
  expect(seen.length).toBe(requests);
  const sent = JSON.stringify(before);
  for (const value of ['47', '171', '83', '28.4']) expect(sent).not.toContain(`"${value}"`);
  // The calculator itself never carries the tag.
  expect(await frame.locator('script[src*="googletagmanager"]').count()).toBe(0);
});

test('TC-NFR3-16-AS-01', async ({ page }) => {
  const email = emailFor('ga-member');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await consent(page, 'granted');
  const seen = await watch(page);
  for (const path of ['/members/home/', '/members/settings/', '/members/diary/', '/members/health/', '/members/goals/', '/members/subscription/', '/members/data/']) {
    await page.goto(path);
    const config = page.locator('#lld-ga4');
    if (await config.count()) expect(JSON.parse(await config.textContent()).measured, path).toBe(false);
  }
  await page.waitForTimeout(500);
  expect(seen).toEqual([]);
});

test('TC-NFR3-16-AS-02', async ({ page }) => {
  const email = emailFor('ga-events');
  await createMember(page.request, { email, password, first_name: 'Synthetic', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await consent(page, 'granted');
  await watch(page);
  const all = [];
  for (const path of ['/', '/pricing/', '/programmes/healthy-weight/', '/health-tools/protein/', '/members/checkout/?plan=yearly']) {
    await page.goto(path);
    all.push(...(await layer(page)));
  }
  const sentEvents = all.filter((a) => a[0] === 'event');
  expect(sentEvents.length).toBeGreaterThan(0);
  const allowed = { page_view: ['page_location'], sign_up: [], begin_checkout: ['plan'] };
  for (const [, name, params = {}] of sentEvents) {
    expect(Object.keys(allowed), name).toContain(name);
    for (const key of Object.keys(params)) expect(allowed[name], `${name}.${key}`).toContain(key);
  }
  const sent = JSON.stringify(all);
  for (const banned of [email, 'Synthetic', 'member_id', 'user_id', 'bmi', 'glucose']) expect(sent).not.toContain(banned);
});
