import { createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, signIn } from './helpers.mjs';
import { fillProteinForm, submitProtein } from '../../e2e/helpers/protein-form.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const DOMAIN = 'ci.lifelinedaily.invalid';
const protein = JSON.parse(readFileSync(resolve(root, 'fixtures/protein/nrm.json'), 'utf8')).fixtures[0];
// Requirements section 8.5: event name and the only fields it may carry.
const allowlist = {
  pageview: [],
  calculator_used: ['calculator'],
  registered: [],
  track_started: ['track'],
  week1_completed: ['track'],
  upgraded: ['plan'],
  switched_to_yearly: [],
  starter_guide_requested: ['track'],
  waitlist_joined: ['track'],
};

function stamp() {
  return randomBytes(4).toString('hex');
}

function emailFor(label) {
  return `${label}-${stamp()}@test.lifelinedaily.invalid`;
}

function localise(href) {
  const url = new URL(href);
  const base = new URL(process.env.LLD_WP_URL || 'http://localhost:8090');
  url.protocol = base.protocol;
  url.host = base.host;
  return url.toString();
}

function testSecret() {
  if (process.env.LLD_TEST_SECRET) return process.env.LLD_TEST_SECRET;
  if (!process.env.RUNNER_TEMP) return '';
  const path = `${process.env.RUNNER_TEMP}/lld-test-secret`;
  return existsSync(path) ? readFileSync(path, 'utf8').trim() : '';
}

async function clock(request, email, utc) {
  const res = await request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function growth(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/growth', { data });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function messages() {
  const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  return list.messages || [];
}

async function bodyOf(message) {
  const full = await fetch(`${MAILPIT}/api/v1/message/${message.ID}`).then((res) => res.json());
  const to = (full.To || []).map((item) => item.Address).join(', ');
  return `To: ${to}\n${full.Subject || ''}\n${full.Text || ''}`;
}

/** Newest message to this address with this subject, with its headers. */
async function messageTo(email, subject) {
  for (const message of await messages()) {
    if (message.Subject !== subject || !(message.To || []).some((item) => item.Address === email)) continue;
    const full = await fetch(`${MAILPIT}/api/v1/message/${message.ID}`).then((res) => res.json());
    const headers = await fetch(`${MAILPIT}/api/v1/message/${message.ID}/headers`).then((res) => res.json());
    return { text: full.Text || '', headers };
  }
  return null;
}

async function askGuide(page, path, email) {
  await page.goto(path);
  await page.locator('#lld_guide_email').fill(email);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
  await expect(page.getByText('Check your email to confirm.')).toBeVisible();
}

async function mailTo(email) {
  const found = [];
  for (const message of await messages()) {
    const body = await bodyOf(message);
    if (body.includes(email)) found.push(body);
  }
  return found;
}

/** The newest Monday report in Mailpit, as plain text. */
async function latestReport() {
  const list = (await messages()).filter((message) => message.Subject === 'Weekly report');
  list.sort((a, b) => new Date(b.Created) - new Date(a.Created));
  expect(list.length).toBeGreaterThan(0);
  const full = await fetch(`${MAILPIT}/api/v1/message/${list[0].ID}`).then((res) => res.json());
  // Mail text lines end in CRLF.
  return (full.Text || '').replace(/\r\n/g, '\n');
}

/** A signed Stripe notice, as Stripe sends it to the webhook. */
async function notify(request, event) {
  const body = JSON.stringify(event);
  const t = Math.floor(Date.now() / 1000);
  const v1 = createHmac('sha256', testSecret()).update(`${t}.${body}`).digest('hex');
  const res = await request.post('/wp-json/lld-members/v1/stripe/notice', {
    headers: { 'Stripe-Signature': `t=${t},v1=${v1}`, 'Content-Type': 'application/json' },
    data: body,
  });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function readMember(request, email) {
  const res = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'read', email } });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

/** Checkout through Stripe: the quote the checkout page stores, then Stripe's checkout.session.completed notice. */
async function checkout(request, email, plan, subscription) {
  const ready = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'session', email, plan, country: 'US' } });
  expect(ready.ok(), await ready.text()).toBeTruthy();
  const quoted = await ready.json();
  const payment = `pi_${stamp()}`;
  await notify(request, {
    id: `evt_${payment}`,
    type: 'checkout.session.completed',
    data: { object: { id: quoted.session, amount_total: Number(quoted.charged), subscription, payment_intent: payment, email } },
  });
  return quoted;
}

async function renew(request, email, subscription, payment = `pi_${stamp()}`) {
  const amount = (await readMember(request, email)).next.charged;
  return notify(request, {
    id: `evt_${payment}`,
    type: 'invoice.paid',
    data: { object: { id: `in_${payment}`, subscription, amount_paid: amount, payment_intent: payment } },
  });
}

async function cancel(request, subscription, feedback) {
  return notify(request, {
    id: `evt_del_${subscription}`,
    type: 'customer.subscription.deleted',
    data: { object: { id: subscription, cancellation_details: { feedback, comment: 'Private words that must not be kept' } } },
  });
}

/** Registration through the join form. */
async function join(page, email) {
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Robin');
  await page.locator('#lld_last').fill('Joiner');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(password);
  await page.locator('#lld_age').fill('34');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
}

async function startTrack(page, slug) {
  await page.goto('/members/tracks/');
  const nonce = await page.locator('input[name="lld_track_nonce"]').first().inputValue();
  const res = await page.request.post('/members/tracks/', { form: { lld_track_nonce: nonce, lld_track: slug }, maxRedirects: 0 });
  expect([200, 302, 303]).toContain(res.status());
}

async function serveTools(page) {
  const origin = new URL(process.env.LLD_WP_URL || 'http://localhost:8090').origin;
  await page.route('**/tools/**', async (route) => {
    const url = route.request().url();
    const file = url.includes('lld-members-link.js') ? 'lld-members-link.js' : url.includes('status.json') ? 'status.json' : url.includes('diabetes') ? 'diabetes-risk-tool.html' : 'protein-requirement-tool.html';
    let body = readFileSync(resolve(root, 'site/public_html/tools', file));
    if (file.endsWith('.html')) body = Buffer.from(body.toString('utf8').split('__LLD_ORIGIN__').join(origin));
    const type = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.json') ? 'application/json' : 'text/html';
    await route.fulfill({ status: 200, contentType: type, body });
  });
}

/** A Premium member answers the week 4 survey and allows a quote. */
async function quoteFrom(page, request, name, quote) {
  const email = emailFor('quote');
  await createMember(request, { email, password, first_name: name, role: 'member', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await page.goto('/members/tracks/');
  await page.getByRole('button', { name: 'Start Healthy Weight' }).click();
  await clock(request, email, '2026-05-22 12:00:00');
  await page.goto('/members/check-in/');
  await page.locator('#lld_score').fill('9');
  await page.locator('#lld_open').fill(quote);
  await page.locator('#lld_changed').fill(`Private change ${stamp()}`);
  await page.locator('#lld_country').fill('GB');
  await page.getByRole('checkbox', { name: 'You may quote my first name and country' }).check();
  await page.getByRole('button', { name: 'Send survey' }).click();
  return email;
}

async function openQuotes(page) {
  await page.goto('/wp-admin/admin.php?page=lld-quotes');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-quotes');
}

// A failing journey prints its error at once, so a run stopped by the job time limit still shows why.
test.afterEach(async ({}, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) console.log(`FAILED ${testInfo.title}: ${(testInfo.error && testInfo.error.message) || testInfo.status}`.slice(0, 1500));
});

test.beforeEach(async ({ request }) => {
  await request.get('/coming-soon/fitness/');
});

// Tests that configure a Plausible domain leave none behind, so later journeys send nothing to plausible.io.
test.afterEach(async ({ request }) => {
  await growth(request, { action: 'domain', domain: '' });
});

test('TC-FR2-17-AS-01', async ({ page, request }) => {
  await growth(request, { action: 'reset' });
  await growth(request, {
    action: 'plausible',
    domain: DOMAIN,
    pages: [['/programmes/healthy-weight/', 4], ['/coming-soon/blood-sugar/', 3], ['/health-tools/protein/', 2], ['/members/home/', 9]],
    calculator: [['protein', 2], ['diabetes', 1]],
  });
  // A visitor registers through the join form.
  await join(page, emailFor('joined'));
  // A member starts Healthy Weight, finishes week 1, upgrades, switches to yearly at the renewal, then cancels.
  const email = emailFor('week');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await clock(request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await startTrack(page, 'healthy-weight');
  await clock(request, email, '2026-05-08 12:00:00');
  await page.goto('/members/home/');
  await page.goto('/members/home/');
  const subscription = `sub_${stamp()}`;
  await checkout(request, email, 'monthly', subscription);
  await page.goto('/members/subscription/');
  await page.getByRole('button', { name: 'Switch to yearly' }).click();
  const pending = (await readMember(request, email)).subscription.pending_yearly_on;
  expect(pending).toBeTruthy();
  await clock(request, email, `${pending} 12:00:00`);
  await renew(request, email, subscription);
  expect((await readMember(request, email)).subscription.plan).toBe('yearly');
  await cancel(request, subscription, 'too_expensive');
  const figures = await growth(request, { action: 'figures' });
  // The Monday schedule is real, and running it sends the report.
  const run = await growth(request, { action: 'monday' });
  expect(run.weekday).toBe('Monday 07:00');
  const mail = await latestReport();
  expect(mail).toContain(`Weekly report for ${run.week[0]} to ${run.week[1]}`);
  for (const line of [
    'Healthy Weight visits 4',
    'Blood Sugar Balance visits 3',
    'Fitness and Strength visits 2',
    'Sleep and Stress visits 0',
    'Healthy Weight calculator use 0',
    'Blood Sugar Balance calculator use 1',
    'Fitness and Strength calculator use 2',
    'Healthy Weight registrations 1',
    'Healthy Weight track starts 1',
    'Healthy Weight week 1 completions 1',
    'Healthy Weight upgrades 1',
    'Healthy Weight monthly-to-yearly switches 1',
    'Healthy Weight renewals 1',
    'Healthy Weight cancellations 1',
    'Healthy Weight cancellation reasons too_expensive 1',
    'Blood Sugar Balance registrations 0',
    'Blood Sugar Balance track starts 0',
    'Fitness and Strength upgrades 0',
    'Sleep and Stress cancellation reasons none',
    'All registrations 1',
    `Monthly recurring revenue ${figures.mrr}`,
    `Active Premium members ${figures.active}`,
    `Yearly members ${figures.yearly}`,
    `Monthly members ${figures.monthly}`,
  ]) {
    expect(mail.split('\n'), line).toContain(line);
  }
  expect(mail).not.toContain('Private words');
  // A second run in the same week does not send the report again.
  const before = (await messages()).filter((message) => message.Subject === 'Weekly report').length;
  await growth(request, { action: 'monday' });
  expect((await messages()).filter((message) => message.Subject === 'Weekly report').length).toBe(before);
});

test('TC-FR2-17-AS-02', async ({ request }) => {
  await growth(request, { action: 'reset' });
  await growth(request, {
    action: 'plausible',
    domain: DOMAIN,
    pages: [['/programmes/sleep-stress/', 5], ['/coming-soon/sleep-stress/', 2], ['/health-tools/diabetes-risk-check/', 6]],
    calculator: [['diabetes', 4], ['protein', 3]],
  });
  const before = await growth(request, { action: 'figures' });
  const email = emailFor('mrr');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms'] });
  await checkout(request, email, 'monthly', `sub_${stamp()}`);
  const after = await growth(request, { action: 'figures' });
  const cents = Math.round((Number(after.mrr) - Number(before.mrr)) * 100);
  expect(cents).toBeGreaterThanOrEqual(798);
  expect(cents).toBeLessThanOrEqual(800);
  expect(after.active - before.active).toBe(1);
  expect(after.monthly - before.monthly).toBe(1);
  expect(after.tracks.none.upgrades).toBe(1);
  const run = await growth(request, { action: 'monday' });
  // The report asked Plausible for exactly the report week, and shows Plausible's figures.
  expect(run.queries).toHaveLength(2);
  for (const query of run.queries) {
    expect(query.site_id).toBe(DOMAIN);
    expect(query.date_range).toEqual(run.week);
  }
  expect(run.queries.map((query) => query.dimensions[0]).sort()).toEqual(['event:page', 'event:props:calculator']);
  const mail = (await latestReport()).split('\n');
  expect(mail).toContain('Sleep and Stress visits 7');
  expect(mail).toContain('Blood Sugar Balance visits 6');
  expect(mail).toContain('Blood Sugar Balance calculator use 4');
  expect(mail).toContain('Fitness and Strength calculator use 3');
  expect(mail).toContain(`Monthly recurring revenue ${after.mrr}`);
  expect(mail).toContain(`Active Premium members ${after.active}`);
  expect(mail).toContain(`Monthly members ${after.monthly}`);
  expect(mail).toContain('No track yet upgrades 1');
});

test('TC-FR2-17-AS-03', async ({ page, request }) => {
  await growth(request, { action: 'reset' });
  const source = `facebook-post-${stamp()}`;
  const other = `newsletter-${stamp()}`;
  const email = emailFor('source');
  await page.context().clearCookies();
  await page.goto(`/?utm_source=${source}`);
  await page.goto(`/pricing/?utm_source=${other}`);
  await join(page, email);
  const subscription = `sub_${stamp()}`;
  await checkout(request, email, 'monthly', subscription);
  await clock(request, email, '2027-01-15 12:00:00');
  const payment = `pi_${stamp()}`;
  await renew(request, email, subscription, payment);
  // Stripe sends the same notice again: it is a replay and credits nothing twice.
  expect((await renew(request, email, subscription, payment)).replay).toBe(true);
  await cancel(request, subscription, 'unused');
  expect((await cancel(request, subscription, 'unused')).replay).toBe(true);
  await growth(request, { action: 'monday' });
  const mail = (await latestReport()).split('\n');
  expect(mail).toContain(`Source ${source} sign-ups 1`);
  expect(mail).toContain(`Source ${source} renewals 1`);
  expect(mail).toContain(`Source ${source} cancellations 1`);
  expect(mail.join('\n')).not.toContain(`Source ${other}`);
  expect(mail).toContain('All registrations 1');
  expect(mail).toContain('No track yet cancellation reasons unused 1');
});

test('TC-FR2-17-AS-04', async ({ page, request }) => {
  await growth(request, { action: 'domain', domain: DOMAIN });
  try {
    const seen = [];
    await page.route('https://plausible.io/**', async (route) => {
      seen.push({ url: route.request().url(), method: route.request().method(), body: route.request().postData() || '' });
      await route.fulfill({ status: 202, body: 'ok' });
    });
    await serveTools(page);
    const email = emailFor('analytics');
    const created = await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
    // TM-01: a visitor arrives by a tagged link, uses a calculator, asks for a guide and joins a waitlist.
    await page.context().clearCookies();
    await page.goto(`/programmes/blood-sugar/?utm_source=facebook&utm_medium=social&utm_campaign=launch&email=${encodeURIComponent(email)}&name=Pat`);
    await page.goto('/health-tools/protein/');
    const frame = page.frameLocator('.lld-tool-frame');
    await frame.locator('#age').waitFor();
    await fillProteinForm(frame, protein.inputs);
    await submitProtein(frame);
    await expect(frame.getByRole('heading', { name: `${protein.expected.targetG} g` })).toBeVisible();
    await page.locator('#lld_guide_email').fill(emailFor('visitor'));
    await page.getByRole('button', { name: 'Send me the guide' }).click();
    await expect(page.getByText('Check your email to confirm.')).toBeVisible();
    await page.goto('/coming-soon/sleep-stress/');
    await page.locator('#lld_guide_email').fill(emailFor('wait'));
    await page.getByRole('button', { name: 'Join the waitlist' }).click();
    // TM-03: a signed-in member starts a track and opens member pages, including one with their member number.
    await signIn(page, email, password);
    await startTrack(page, 'fitness');
    await page.goto('/members/home/');
    await page.goto(`/members/record/${created.member_id}/`);
    await page.goto('/members/settings/');
    await expect.poll(() => [...new Set(seen.map((hit) => JSON.parse(hit.body).name))].sort())
      .toEqual(['calculator_used', 'pageview', 'starter_guide_requested', 'track_started', 'waitlist_joined']);
    for (const hit of seen) {
      expect(hit.url).toBe('https://plausible.io/api/event');
      expect(hit.method).toBe('POST');
      const payload = JSON.parse(hit.body);
      expect(Object.keys(payload).filter((key) => key !== 'props').sort()).toEqual(['domain', 'name', 'url']);
      expect(payload.domain).toBe(DOMAIN);
      expect(Object.keys(allowlist)).toContain(payload.name);
      expect(Object.keys(payload.props || {}).sort()).toEqual([...allowlist[payload.name]].sort());
      for (const value of Object.values(payload.props || {})) expect(value).toMatch(/^[a-z0-9-]+$/);
      const address = new URL(payload.url);
      for (const key of address.searchParams.keys()) expect(['utm_source', 'utm_medium', 'utm_campaign']).toContain(key);
      expect(address.pathname.split('/')).not.toContain(String(created.member_id));
      for (const text of [hit.url, hit.body]) {
        expect(text).not.toContain(email);
        expect(text).not.toContain(encodeURIComponent(email));
        expect(text).not.toContain('Pat');
        expect(text).not.toMatch(/@/);
      }
    }
    const payloads = seen.map((hit) => JSON.parse(hit.body));
    const tagged = payloads.find((payload) => payload.url.includes('/programmes/blood-sugar/'));
    expect(tagged.url).toMatch(/\/programmes\/blood-sugar\/\?utm_source=facebook&utm_medium=social&utm_campaign=launch$/);
    expect(payloads.find((payload) => payload.name === 'track_started').props).toEqual({ track: 'fitness' });
    expect(payloads.find((payload) => payload.name === 'calculator_used').props).toEqual({ calculator: 'protein' });
    expect(payloads.filter((payload) => payload.name === 'calculator_used')).toHaveLength(1);
  } finally {
    await growth(request, { action: 'domain', domain: '' });
  }
  // With no Plausible domain configured, nothing is sent.
  const quiet = [];
  await page.route('https://plausible.io/**', async (route) => {
    quiet.push(route.request().url());
    await route.fulfill({ status: 202, body: 'ok' });
  });
  await page.goto('/pricing/');
  await page.goto('/coming-soon/fitness/');
  expect(quiet).toEqual([]);
});

test('TC-FR2-18-AS-01', async ({ page }) => {
  const email = emailFor('guide');
  await askGuide(page, '/health-tools/protein/', email);
  const first = await mailTo(email);
  expect(first.join('\n')).toContain('Confirm your starter guide');
  expect(first.join('\n')).not.toContain('Your starter guide');
  const link = localise(first.join('\n').match(/https?:\/\/\S*guide\/confirm\/\S+/)[0]);
  // Links name the contact by a signed token, never by the email address.
  expect(link).not.toContain(email);
  expect(link).not.toContain(encodeURIComponent(email));
  expect(link).not.toContain('%40');
  await page.goto(link.replace(/c=(\d+)-/, (all, id) => `c=${Number(id) + 1}-`));
  await expect(page.getByRole('heading', { name: 'Link expired' })).toBeVisible();
  await page.goto(link);
  await expect(page.getByRole('heading', { name: 'Confirmed' })).toBeVisible();
  const second = await mailTo(email);
  expect(second.join('\n')).toContain('Your starter guide');
  expect(second.join('\n')).toContain('/programmes/fitness/');
  // A confirmed contact who asks for another guide gets it straight away, with no second confirmation.
  await askGuide(page, '/health-tools/diabetes-risk-check/', email);
  await expect.poll(async () => (await mailTo(email)).filter((body) => body.includes('/programmes/blood-sugar/')).length).toBe(1);
  expect((await mailTo(email)).filter((body) => body.includes('Confirm your starter guide'))).toHaveLength(1);
});

test('TC-FR2-18-AS-02', async ({ page, request }) => {
  const email = emailFor('unsub');
  await askGuide(page, '/health-tools/protein/', email);
  const link = localise((await mailTo(email)).join('\n').match(/https?:\/\/\S*guide\/confirm\/\S+/)[0]);
  await page.goto(link);
  const guide = await messageTo(email, 'Your starter guide');
  expect(guide).toBeTruthy();
  const unsub = localise(guide.text.match(/https?:\/\/\S*guide\/unsubscribe\/\S+/)[0]);
  expect(unsub).not.toContain('%40');
  // Every list email carries one-click unsubscribe headers pointing at the same link.
  expect(guide.headers['List-Unsubscribe'][0]).toContain('/guide/unsubscribe/');
  expect(guide.headers['List-Unsubscribe-Post']).toEqual(['List-Unsubscribe=One-Click']);
  // A tampered link never claims to unsubscribe.
  await page.goto(unsub.replace(/-([a-f0-9]{32})$/, (all, sig) => `-${sig.split('').reverse().join('')}`));
  await expect(page.getByRole('heading', { name: 'Link expired' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Unsubscribed' })).toHaveCount(0);
  // Opening the link only asks; the button unsubscribes.
  await page.goto(unsub);
  await expect(page.getByRole('heading', { name: 'Stop these emails?' })).toBeVisible();
  const kept = await request.post('/wp-json/lld-members/v1/test/growth', { data: { action: 'contact', email } });
  expect((await kept.json()).exists).toBe(true);
  await page.getByRole('button', { name: 'Unsubscribe' }).click();
  await expect(page.getByRole('heading', { name: 'Unsubscribed' })).toBeVisible();
  const before = (await messages()).length;
  await page.goto('/health-tools/protein/');
  await page.locator('#lld_guide_email').fill(email);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
  expect((await messages()).length).toBe(before);
  await page.goto(unsub);
  await expect(page.getByRole('heading', { name: 'Already unsubscribed' })).toBeVisible();

  // One-click unsubscribe from a mail app: a POST to the List-Unsubscribe address.
  const other = emailFor('oneclick');
  await askGuide(page, '/health-tools/protein/', other);
  await page.goto(localise((await mailTo(other)).join('\n').match(/https?:\/\/\S*guide\/confirm\/\S+/)[0]));
  const header = (await messageTo(other, 'Your starter guide')).headers['List-Unsubscribe'][0].replace(/^<|>$/g, '');
  const oneClick = await request.post(localise(header), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, data: 'List-Unsubscribe=One-Click' });
  expect(oneClick.ok()).toBeTruthy();
  await page.goto(localise(header));
  await expect(page.getByRole('heading', { name: 'Already unsubscribed' })).toBeVisible();

  // DM-7: unsubscribed and unconfirmed contacts are deleted after 30 days, not before.
  const pending = emailFor('unconfirmed');
  await askGuide(page, '/health-tools/protein/', pending);
  const exists = async (who) => (await (await request.post('/wp-json/lld-members/v1/test/growth', { data: { action: 'contact', email: who } })).json()).exists;
  await request.post('/wp-json/lld-members/v1/test/growth', { data: { action: 'sweep', days: 29 } });
  for (const who of [email, other, pending]) expect(await exists(who), who).toBe(true);
  await request.post('/wp-json/lld-members/v1/test/growth', { data: { action: 'sweep', days: 31 } });
  for (const who of [email, other, pending]) expect(await exists(who), who).toBe(false);
});

test('TC-FR2-18-AS-03', async ({ page, request }) => {
  const made = await growth(request, { action: 'article', track: 'healthy-weight' });
  await page.goto(localise(made.url));
  await expect(page.getByRole('heading', { name: 'Get the free guide' })).toBeVisible();
  await page.goto('/health-tools/protein/');
  await expect(page.getByRole('heading', { name: 'Get the free guide' })).toBeVisible();
  await page.goto('/health-tools/diabetes-risk-check/');
  await expect(page.getByRole('heading', { name: 'Get the free guide' })).toBeVisible();
});

test('TC-FR2-19-AS-01', async ({ page, request }) => {
  const email = emailFor('survey');
  const created = await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'], access_state: 'Premium' });
  await clock(request, email, '2026-05-01 12:00:00');
  await signIn(page, email, password);
  await page.goto('/members/tracks/');
  await page.getByRole('button', { name: 'Start Healthy Weight' }).click();
  await clock(request, email, '2026-05-07 12:00:00');
  await page.goto('/members/check-in/');
  await expect(page.getByRole('heading', { name: 'Week 1 survey' })).toBeVisible();
  await expect(page.getByLabel('How likely are you to recommend Lifeline Daily, from 0 to 10?')).toBeVisible();
  await clock(request, email, '2026-05-22 12:00:00');
  await page.goto('/members/check-in/');
  await expect(page.getByRole('heading', { name: 'Week 4 survey' })).toBeVisible();
  await expect(page.getByLabel('What changed for you?')).toBeVisible();
  expect(created.member_id).toBeGreaterThan(0);
});

test('TC-FR2-19-AS-02', async ({ page, request }) => {
  const name = `Nia${stamp()}`;
  const quote = `Walks helped ${stamp()}`;
  const email = await quoteFrom(page, request, name, quote);
  await page.goto('/testimonials/');
  await expect(page.locator('main')).not.toContainText(quote);
  await page.goto('/programmes/healthy-weight/');
  await expect(page.locator('main')).not.toContainText(quote);
  await asOwner(page);
  await openQuotes(page);
  const row = page.locator('li', { hasText: name });
  // The owner reads the exact words before approving (FR2-19-AS-03 is checked against them).
  await expect(row).toContainText(quote);
  await row.getByRole('button', { name: 'Approve' }).click();
  // Wait for the approval to be saved before leaving the page (WebKit otherwise cancels the post).
  await expect(page.getByText('Approved.')).toBeVisible();
  await page.goto('/testimonials/');
  const block = page.locator('[data-lld-quote]', { hasText: quote });
  await expect(block).toContainText(`${name}, GB`);
  await expect(block).not.toContainText('Private change');
  await expect(block).not.toContainText('Member');
  await page.goto('/programmes/healthy-weight/');
  await expect(page.locator('[data-lld-quote]', { hasText: quote })).toBeVisible();
  // The member withdraws permission (Data Model F4): the quote comes down.
  await signIn(page, email, password);
  await page.goto('/members/more/');
  await expect(page.getByText(quote)).toBeVisible();
  await page.getByRole('button', { name: 'Withdraw permission to quote me' }).click();
  await expect(page.getByText('Permission withdrawn.')).toBeVisible();
  await page.goto('/testimonials/');
  await expect(page.locator('main')).not.toContainText(quote);
  // A quote the owner rejects is never shown.
  const otherName = `Ola${stamp()}`;
  const other = `Felt stronger ${stamp()}`;
  await quoteFrom(page, request, otherName, other);
  await asOwner(page);
  await openQuotes(page);
  const otherRow = page.locator('li', { hasText: otherName });
  await expect(otherRow).toContainText(other);
  await otherRow.getByRole('button', { name: 'Reject' }).click();
  await expect(page.locator('li', { hasText: otherName })).toHaveCount(0);
  await page.goto('/testimonials/');
  await expect(page.locator('main')).not.toContainText(other);
});

test('TC-FR2-28-AS-01', async ({ page }) => {
  for (const track of ['healthy-weight', 'blood-sugar', 'fitness', 'sleep-stress']) {
    const email = emailFor(track);
    await page.goto(`/coming-soon/${track}/`);
    await expect(page.getByRole('heading', { name: /coming soon/ })).toBeVisible();
    await page.locator('#lld_guide_email').fill(email);
    await page.getByRole('button', { name: 'Join the waitlist' }).click();
    await expect(page.getByText('Check your email to confirm.')).toBeVisible();
  }
});

test('TC-FR2-28-AS-02', async ({ page, request }) => {
  async function join(track) {
    const email = emailFor(track);
    await page.goto(`/coming-soon/${track}/`);
    await page.locator('#lld_guide_email').fill(email);
    await page.getByRole('button', { name: 'Join the waitlist' }).click();
    await expect(page.getByText('Check your email to confirm.')).toBeVisible();
    return email;
  }
  const confirmed = await join('healthy-weight');
  const unconfirmed = await join('blood-sugar');
  const later = await join('fitness');
  const both = await join('sleep-stress');
  for (const email of [confirmed, later, both]) {
    const link = localise((await mailTo(email)).join('\n').match(/https?:\/\/\S*guide\/confirm\/\S+/)[0]);
    await page.goto(link);
  }
  const unsub = localise((await mailTo(later)).join('\n').match(/https?:\/\/\S*guide\/unsubscribe\/\S+/)[0]);
  await page.goto(unsub);
  await page.getByRole('button', { name: 'Unsubscribe' }).click();
  await expect(page.getByRole('heading', { name: 'Unsubscribed' })).toBeVisible();
  await askGuide(page, '/health-tools/protein/', both);
  await askGuide(page, '/health-tools/diabetes-risk-check/', both);
  await growth(request, { action: 'launch' });
  const launched = [];
  for (const message of await messages()) {
    const body = await bodyOf(message);
    if (body.includes('Lifeline Daily is open')) launched.push(body);
  }
  const text = launched.join('\n');
  expect(text).toContain('50% off the first month');
  expect(launched.filter((body) => body.includes(both))).toHaveLength(1);
  expect(text).toContain(confirmed);
  expect(text).not.toContain(unconfirmed);
  expect(text).not.toContain(later);
});

test('TC-FR2-30-AS-01', async ({ page, request }) => {
  const email = emailFor('help');
  const note = `Need a hand ${stamp()}`;
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms'], access_state: 'Premium', plan: 'yearly' });
  await signIn(page, email, password);
  await page.goto('/members/help/');
  await page.locator('#lld_message').fill(note);
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page.getByText('Message sent.')).toBeVisible();
  const signedIn = (await mailTo(note)).join('\n');
  expect(signedIn).toContain('Plan: Premium');
  await page.context().clearCookies();
  const guest = `Guest note ${stamp()}`;
  await page.goto('/members/help/');
  await page.locator('#lld_name').fill('Ada');
  await page.locator('#lld_email').fill(emailFor('guest'));
  await page.locator('#lld_message').fill(guest);
  await page.getByRole('button', { name: 'Send message' }).click();
  const signedOut = (await mailTo(guest)).join('\n');
  expect(signedOut).toContain('Plan: none');
});

test('TC-FR2-30-AS-02', async ({ page }) => {
  await page.goto('/members/help/');
  for (const heading of ['Billing', 'Cancelling', 'Refunds', 'Referrals', 'Data']) {
    await expect(page.getByRole('heading', { name: heading })).toBeVisible();
  }
  await expect(page.getByText('We aim to reply within 2 working days')).toBeVisible();
});
