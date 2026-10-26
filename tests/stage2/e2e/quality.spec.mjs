import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { asOwner, createMember, mailCode, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';

async function clock(request, email, utc) {
  const res = await request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

function emailFor(label) {
  return `${label}-${randomBytes(4).toString('hex')}@test.lifelinedaily.invalid`;
}

async function overflow(page) {
  return page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const extra = document.documentElement.scrollWidth - width;
    const wide = [...document.querySelectorAll('body *')]
      .filter((el) => {
        const box = el.getBoundingClientRect();
        return box.width > 0 && box.right > width + 1;
      })
      .slice(0, 6)
      .map((el) => `${el.tagName}.${el.className}`);
    return { extra, wide };
  });
}

// A failing journey prints its error at once, so a run stopped by the job time limit still shows why.
test.afterEach(async ({}, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) console.log(`FAILED ${testInfo.title}: ${(testInfo.error && testInfo.error.message) || testInfo.status}`.slice(0, 1500));
});

test('TC-NFR2-03-AS-01', async ({ page, request }) => {
  const email = emailFor('fit');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  const pages = ['/members/sign-in/', '/members/join/', '/pricing/', '/programmes/healthy-weight/', '/members/help/'];
  for (const width of [360, 768, 1280]) {
    await page.setViewportSize({ width, height: 800 });
    for (const path of pages) {
      await page.goto(path);
      const fit = await overflow(page);
      expect(fit.extra, `${path} at ${width}`).toBeLessThanOrEqual(1);
      expect(fit.wide, `${path} at ${width}`).toEqual([]);
    }
  }
  await signIn(page, email, password);
  for (const width of [360, 768, 1280]) {
    await page.setViewportSize({ width, height: 800 });
    for (const path of ['/members/home/', '/members/diary/']) {
      await page.goto(path);
      const fit = await overflow(page);
      expect(fit.extra, `${path} at ${width}`).toBeLessThanOrEqual(1);
      expect(fit.wide, `${path} at ${width}`).toEqual([]);
    }
  }
});

test('TC-NFR2-03-AS-02', async ({ page, request }) => {
  const email = emailFor('engines');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  for (const size of [{ width: 360, height: 800 }, { width: 1280, height: 800 }]) {
    await page.setViewportSize(size);
    await page.goto('/members/home/');
    await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
    await page.goto('/members/diary/');
    await expect(page.getByRole('heading', { name: 'Food diary' })).toBeVisible();
    const fit = await overflow(page);
    expect(fit.extra).toBeLessThanOrEqual(1);
  }
});

test('TC-NFR2-04-AS-01', async ({ page, request }) => {
  const email = emailFor('axe');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  const open = ['/members/sign-in/', '/members/join/', '/pricing/', '/members/help/'];
  for (const path of open) {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).analyze();
    const bad = results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical');
    expect(bad.map((item) => item.id), path).toEqual([]);
  }
  await signIn(page, email, password);
  for (const path of ['/members/home/', '/members/diary/']) {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).analyze();
    const bad = results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical');
    expect(bad.map((item) => item.id), path).toEqual([]);
  }
});

test('TC-NFR2-04-AS-02', async ({ page, request }) => {
  const email = emailFor('keys');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await page.goto('/members/join/');
  let reached = false;
  for (let step = 0; step < 12; step += 1) {
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => {
      const el = document.activeElement;
      const box = el.getBoundingClientRect();
      const style = getComputedStyle(el);
      return {
        id: el.id,
        outline: style.outlineStyle,
        top: box.top,
        bottom: box.bottom,
        height: window.innerHeight,
      };
    });
    expect(focused.outline).not.toBe('none');
    expect(focused.top).toBeGreaterThanOrEqual(-1);
    expect(focused.bottom).toBeLessThanOrEqual(focused.height + 1);
    if (focused.id === 'lld_first') reached = true;
  }
  expect(reached).toBeTruthy();
  await page.goto('/members/sign-in/');
  for (let step = 0; step < 12 && await page.evaluate(() => document.activeElement.id) !== 'lld_email'; step += 1) {
    await page.keyboard.press('Tab');
  }
  await page.keyboard.type(email);
  await page.keyboard.press('Tab');
  await page.keyboard.type(password);
  for (let step = 0; step < 6; step += 1) {
    await page.keyboard.press('Tab');
    const label = await page.evaluate(() => (document.activeElement.innerText || '').trim());
    if (label === 'Sign in') {
      await page.keyboard.press('Enter');
      break;
    }
  }
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  for (let step = 0; step < 20; step += 1) {
    await page.keyboard.press('Tab');
    const name = await page.evaluate(() => document.activeElement.textContent || '');
    if (name.includes('Open food diary')) {
      await page.keyboard.press('Enter');
      break;
    }
  }
  await expect(page.getByRole('heading', { name: 'Food diary' })).toBeVisible();
});

test('TC-NFR2-05-AS-01', async ({ page, request, browserName }) => {
  const email = emailFor('speed');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  if (browserName === 'chromium') {
    const client = await page.context().newCDPSession(page);
    await client.send('Network.enable');
    await client.send('Network.emulateNetworkConditions', {
      offline: false,
      downloadThroughput: (1.6 * 1024 * 1024) / 8,
      uploadThroughput: (750 * 1024) / 8,
      latency: 150,
    });
  }
  const samples = [];
  for (let run = 0; run < 5; run += 1) {
    const started = Date.now();
    await page.goto('/members/home/', { waitUntil: 'load' });
    await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
    samples.push(Date.now() - started);
  }
  samples.sort((a, b) => a - b);
  expect(samples[2]).toBeLessThanOrEqual(2000);
});

test('TC-NFR2-08-AS-02', async ({ page, request }) => {
  const freeEmail = emailFor('free');
  const paidEmail = emailFor('paid');
  const free = await createMember(request, { email: freeEmail, password, first_name: 'Pat', role: 'member', consents: ['terms'] });
  await createMember(request, { email: paidEmail, password, first_name: 'Pat', role: 'member', consents: ['terms'], access_state: 'Premium' });
  await signIn(page, freeEmail, password);
  await page.goto('/members/home/');
  await expect(page.getByText('Your membership is Free.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'what Premium adds' })).toBeVisible();
  await signIn(page, paidEmail, password);
  await page.goto('/members/home/');
  await expect(page.getByText('Your membership is Premium.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'what Premium adds' })).toHaveCount(0);
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-preview');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-preview');
  await page.getByRole('button', { name: 'Preview as a free member' }).click();
  await expect(page.getByText('Your membership is Free.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'what Premium adds' })).toBeVisible();
  const banner = page.locator('[data-lld-preview]');
  await expect(banner).toContainText('you are seeing what a free member sees');
  await expect(banner.getByRole('link', { name: 'Exit preview' })).toBeVisible();
  // The banner switches straight to another state.
  await banner.getByRole('link', { name: 'See a Premium member' }).click();
  await expect(page.getByText('Your membership is Premium.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'what Premium adds' })).toHaveCount(0);
  // Preview changes only the owner's own view: a free member still reads as free to the owner.
  const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
  const looked = await page.request.get(`/wp-json/lld-members/v1/members/${free.member_id}`, { headers: { 'X-WP-Nonce': nonce } });
  expect(looked.ok(), await looked.text()).toBeTruthy();
  expect((await looked.json()).access_state).toBe('free');
  await page.goto('/wp-admin/admin.php?page=lld-preview');
  await page.getByRole('button', { name: 'Preview as a visitor' }).click();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await page.locator('[data-lld-preview]').getByRole('link', { name: 'Exit preview' }).click();
  await expect(page.getByRole('heading', { name: 'Preview', exact: true })).toBeVisible();
  await page.goto('/members/sign-in/');
  await expect(page.locator('[data-lld-preview]')).toHaveCount(0);
});

async function appSession(request, data) {
  return request.post('/wp-json/lld-members/v1/app/session', { data });
}

function appHeaders(creds) {
  return { 'X-LLD-App': Buffer.from(`${creds.login}:${creds.password}`).toString('base64') };
}

function utcAfter(days) {
  return new Date(Date.now() + days * 86400000).toISOString().slice(0, 19).replace('T', ' ');
}

test('TC-NFR2-09-AS-01', async ({ page, request }) => {
  const email = emailFor('app');
  const other = emailFor('other');
  const name = `Nia${randomBytes(3).toString('hex')}`;
  const installation = `install-${randomBytes(6).toString('hex')}`;
  const mine = await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'], access_state: 'Premium', plan: 'yearly' });
  const theirs = await createMember(request, { email: other, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  expect((await appSession(request, { email, password })).status()).toBe(400);
  const session = await appSession(request, { installation, email, password });
  expect(session.ok(), await session.text()).toBeTruthy();
  const creds = await session.json();
  expect(creds.expires_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  let headers = appHeaders(creds);
  const me = await request.get('/wp-json/lld-members/v1/app/me', { headers });
  expect(me.ok(), await me.text()).toBeTruthy();
  const body = await me.json();
  expect(Object.keys(body).sort()).toEqual(['diary', 'goals', 'plan', 'profile', 'progress', 'track']);
  expect(body.profile.first_name).toBe('Pat');
  expect(body.plan).toBe('yearly');
  expect(body.progress).toMatch(/\d+ of 28 done/);
  expect(JSON.stringify(body)).not.toContain(other);
  const saved = await request.post('/wp-json/lld-members/v1/app/profile', { headers, data: { display_name: name } });
  expect(saved.ok(), await saved.text()).toBeTruthy();
  expect((await saved.json()).profile.display_name).toBe(name);
  const denied = await request.get(`/wp-json/lld-members/v1/app/members/${theirs.member_id}`, { headers });
  expect(denied.status()).toBe(403);
  for (const path of ['orders', 'premium', 'rewards', 'role']) {
    const blocked = await request.post(`/wp-json/lld-members/v1/app/${path}`, { headers, data: {} });
    expect(blocked.status(), path).toBe(403);
  }
  expect(mine.member_id).not.toBe(theirs.member_id);

  // The key works only on the app routes: WordPress's own API refuses it, by the app header or by Basic auth.
  const basic = { Authorization: `Basic ${Buffer.from(`${creds.login}:${creds.password}`).toString('base64')}` };
  for (const [label, sent] of [['app header', headers], ['basic', basic]]) {
    const read = await request.get('/wp-json/wp/v2/users/me', { headers: sent });
    expect([401, 403], label).toContain(read.status());
    // Without a signed-in user WordPress answers 401, 403 or 404 (no user "me"); never a change.
    const change = await request.post('/wp-json/wp/v2/users/me', { headers: sent, data: { email: emailFor('taken'), password: `${password}-from-app` } });
    expect([401, 403, 404], label).toContain(change.status());
    const keys = await request.post(`/wp-json/wp/v2/users/${mine.user_id}/application-passwords`, { headers: sent, data: { name: 'more' } });
    expect([401, 403], label).toContain(keys.status());
  }
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).ok()).toBeTruthy();

  // A wrong password gets no key; one installation holds one key, replaced when it signs in again.
  expect((await appSession(request, { installation, email, password: `${password}x` })).status()).toBe(401);
  const again = await (await appSession(request, { installation, email, password })).json();
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).status()).toBe(401);
  headers = appHeaders(again);
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).ok()).toBeTruthy();

  // Keys expire after 90 days; signing in again renews.
  await clock(request, email, utcAfter(91));
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).status()).toBe(401);
  await clock(request, email, utcAfter(0));
  headers = appHeaders(await (await appSession(request, { installation, email, password })).json());
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).ok()).toBeTruthy();

  // The member withdraws the key from their profile.
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('[data-lld-app-keys]').getByRole('button', { name: 'Withdraw' }).click();
  await expect(page.getByText('App connection withdrawn.')).toBeVisible();
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).status()).toBe(401);

  // A password change ends every app key.
  headers = appHeaders(await (await appSession(request, { installation, email, password })).json());
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).ok()).toBeTruthy();
  await page.goto('/members/settings/');
  await page.locator('#lld_password').fill(`${password}-new`);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers })).status()).toBe(401);

  // Sign-in protections apply to the app: the two-step code, then the pause after repeated failures.
  const coded = emailFor('appcode');
  await createMember(request, { email: coded, password, first_name: 'Pat', role: 'member', consents: ['terms'], two_step: true });
  const step = await appSession(request, { installation, email: coded, password });
  expect(step.status(), await step.text()).toBe(202);
  const pending = await step.json();
  expect(pending.step).toBe('code');
  expect(pending).not.toHaveProperty('password');
  const code = await mailCode(coded);
  expect((await appSession(request, { installation, pending: pending.pending, code: code === '000000' ? '111111' : '000000' })).status()).toBe(401);
  const finished = await appSession(request, { installation, pending: pending.pending, code });
  expect(finished.ok(), await finished.text()).toBeTruthy();
  expect((await request.get('/wp-json/lld-members/v1/app/me', { headers: appHeaders(await finished.json()) })).ok()).toBeTruthy();
  const paused = emailFor('applock');
  await createMember(request, { email: paused, password, first_name: 'Pat', role: 'member', consents: ['terms'] });
  for (let attempt = 0; attempt < 5; attempt += 1) {
    expect((await appSession(request, { installation, email: paused, password: `wrong-${attempt}-password` })).status()).toBe(401);
  }
  expect((await appSession(request, { installation, email: paused, password })).status()).toBe(429);
});

test('TC-UI2-01-AS-01', async ({ page, request }) => {
  const email = emailFor('snap');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await page.goto('/members/home/');
  await expect(page.getByRole('heading')).toHaveText([
    'Today',
    'Hello Pat',
    'Activity',
    "This week's plan",
    'Meals',
    'Food diary',
    'Progress',
    'Suggestions',
    'Membership',
  ]);
  await page.goto('/pricing/');
  await expect(page.getByRole('heading')).toHaveText(['Pricing']);
});

test('TC-UI2-02-AS-01', async ({ page, request }) => {
  const email = emailFor('order');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'], access_state: 'Premium' });
  await signIn(page, email, password);
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/members/home/');
  const order = await page.locator('[data-lld-section]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-lld-section')));
  expect(order).toEqual(['greeting', 'today', 'plan', 'meals', 'diary', 'progress', 'suggestions', 'membership']);
  await expect(page.getByText(/\d+ of 28 done/)).toBeVisible();
});

test('TC-UI2-03-AS-01', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 640 });
  await page.goto('/pricing/');
  const html = await page.locator('main').innerHTML();
  expect(html.indexOf('data-lld-plan="yearly"')).toBeLessThan(html.indexOf('data-lld-plan="monthly"'));
  for (const text of ['US$59.99 a year', "What's included", 'How to cancel']) {
    const box = await page.getByText(text).boundingBox();
    expect(box, text).toBeTruthy();
    expect(box.y + box.height, text).toBeLessThanOrEqual(640);
  }
});

test('TC-UI2-04-AS-01', async ({ page, request }) => {
  const subject = `Layout check ${randomBytes(3).toString('hex')}`;
  const sent = await request.post('/wp-json/lld-members/v1/test/mail', { data: { subject } });
  expect(sent.ok(), await sent.text()).toBeTruthy();
  const guest = emailFor('layout');
  await page.goto('/coming-soon/blood-sugar/');
  await page.locator('#lld_guide_email').fill(guest);
  await page.getByRole('button', { name: 'Join the waitlist' }).click();
  const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const found = [
    (list.messages || []).find((item) => JSON.stringify(item).includes(subject)),
    (list.messages || []).find((item) => JSON.stringify(item).includes(guest)),
  ];
  for (const message of found) {
    expect(message).toBeTruthy();
    const full = await fetch(`${MAILPIT}/api/v1/message/${message.ID}`).then((res) => res.json());
    const html = full.HTML || '';
    expect(html).toContain('max-width:600px');
    expect(html).toContain('alt="Lifeline Daily"');
    // The logo travels inside the email, so it shows without loading anything remote.
    expect(html).toContain('src="cid:lld-logo"');
    expect((full.Inline || []).map((part) => part.ContentID)).toContain('lld-logo');
    expect(html).not.toMatch(/<script/i);
    expect(html).not.toMatch(/<img[^>]+src="https?:/i);
    expect(html).not.toMatch(/<link[^>]+href=/i);
    expect(html).not.toContain('width:601');
    expect(full.Text || '').not.toContain('<');
  }
  // One button for the main link; the unsubscribe link stays a plain link.
  const confirm = await fetch(`${MAILPIT}/api/v1/message/${found[1].ID}`).then((res) => res.json());
  const buttons = [...(confirm.HTML || '').matchAll(/<a [^>]*display:inline-block[^>]*>([^<]*)<\/a>/g)];
  expect(buttons.map((match) => match[1])).toEqual(['Confirm your starter guide']);
  expect(confirm.HTML).toMatch(/Unsubscribe: <a href="[^"]*guide\/unsubscribe/);
});
