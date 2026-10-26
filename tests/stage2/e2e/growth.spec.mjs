import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const allowed = ['pageview', 'calculator_used', 'registered', 'track_started', 'week1_completed', 'upgraded', 'switched_to_yearly', 'starter_guide_requested', 'waitlist_joined'];

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
  return `${full.Subject || ''}\n${full.Text || ''}`;
}

async function mailTo(email) {
  const found = [];
  for (const message of await messages()) {
    const body = await bodyOf(message);
    if (body.includes(email)) found.push(body);
  }
  return found;
}

test.beforeEach(async ({ request }) => {
  await request.get('/coming-soon/fitness/');
});

test('TC-FR2-17-AS-01', async ({ request }) => {
  await growth(request, { action: 'reset' });
  await growth(request, {
    action: 'prepare',
    tracks: {
      'healthy-weight': { visits: 4, calculator: 2, registrations: 1, track_starts: 1, week1: 1, upgrades: 1, switches: 0, renewals: 1, cancellations: 1, reasons: 1 },
      'blood-sugar': { visits: 3, calculator: 1, registrations: 1, track_starts: 0, week1: 0, upgrades: 0, switches: 1, renewals: 0, cancellations: 0, reasons: 0 },
    },
  });
  const figures = await growth(request, { action: 'figures' });
  await growth(request, { action: 'report' });
  const mail = (await mailTo('Weekly report')).join('\n');
  expect(mail).toContain('Healthy Weight visits 4');
  expect(mail).toContain('Healthy Weight calculator use 2');
  expect(mail).toContain('Healthy Weight registrations 1');
  expect(mail).toContain('Healthy Weight track starts 1');
  expect(mail).toContain('Healthy Weight week 1 completions 1');
  expect(mail).toContain('Healthy Weight upgrades 1');
  expect(mail).toContain('Healthy Weight monthly-to-yearly switches 0');
  expect(mail).toContain('Healthy Weight renewals 1');
  expect(mail).toContain('Healthy Weight cancellations 1');
  expect(mail).toContain('Healthy Weight cancellation reasons 1');
  expect(mail).toContain('Blood Sugar Balance visits 3');
  expect(mail).toContain('Fitness and Strength visits 0');
  expect(mail).toContain('Sleep and Stress visits 0');
  expect(mail).toContain(`Monthly recurring revenue ${figures.mrr}`);
  expect(mail).toContain(`Active Premium members ${figures.active}`);
  expect(mail).toContain(`Yearly members ${figures.yearly}`);
  expect(mail).toContain(`Monthly members ${figures.monthly}`);
});

test('TC-FR2-17-AS-02', async ({ request }) => {
  await growth(request, { action: 'reset' });
  const before = await growth(request, { action: 'figures' });
  const email = emailFor('mrr');
  await createMember(request, { email, password, first_name: 'Pat', role: 'member', access_state: 'Premium', plan: 'monthly' });
  const after = await growth(request, { action: 'figures' });
  const cents = Math.round((Number(after.mrr) - Number(before.mrr)) * 100);
  expect(cents).toBeGreaterThanOrEqual(798);
  expect(cents).toBeLessThanOrEqual(800);
  expect(after.active - before.active).toBe(1);
  expect(after.monthly - before.monthly).toBe(1);
  const noted = await request.post('/wp-json/lld-members/v1/analytics', { data: { name: 'calculator_used', props: { calculator: 'protein' } } });
  expect(noted.status()).toBe(202);
  const figures = await growth(request, { action: 'figures' });
  expect(figures.tracks.fitness.calculator).toBeGreaterThanOrEqual(1);
  await growth(request, { action: 'report' });
  const mail = (await mailTo('Weekly report')).join('\n');
  expect(mail).toContain(`Monthly recurring revenue ${figures.mrr}`);
  expect(mail).toContain(`Fitness and Strength calculator use ${figures.tracks.fitness.calculator}`);
});

test('TC-FR2-17-AS-03', async ({ page, request }) => {
  await growth(request, { action: 'reset' });
  const source = `facebook-post-${stamp()}`;
  const email = emailFor('source');
  await page.goto(`/?utm_source=${source}`);
  await createMember(page.request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms'], access_state: 'Premium', plan: 'monthly' });
  await growth(page.request, { action: 'renew', email });
  await growth(page.request, { action: 'cancel', email });
  await growth(page.request, { action: 'report' });
  const mail = (await mailTo('Weekly report')).join('\n');
  expect(mail).toContain(`Source ${source} sign-ups 1`);
  expect(mail).toContain(`Source ${source} renewals 1`);
  expect(mail).toContain(`Source ${source} cancellations 1`);
  expect(mail).not.toContain(`Source ${source} sign-ups 2`);
});

test('TC-FR2-17-AS-04', async ({ page, request }) => {
  const email = emailFor('analytics');
  const created = await createMember(request, { email, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  const seen = [];
  page.on('request', (req) => {
    if (req.url().includes('plausible.io')) seen.push({ url: req.url(), body: req.postData() || '' });
  });
  await signIn(page, email, password);
  await page.goto('/members/home/');
  await page.goto('/health-tools/protein/');
  await page.goto('/members/settings/');
  expect(seen.length).toBeGreaterThan(0);
  for (const hit of seen) {
    expect(hit.url).not.toContain(email);
    expect(hit.url).not.toContain('Pat');
    expect(hit.body).not.toContain(email);
    expect(hit.body).not.toContain('Pat');
    expect(hit.body).not.toContain(String(created.member_id));
    const payload = JSON.parse(hit.body);
    expect(allowed).toContain(payload.name);
    expect(JSON.stringify(payload.props || {})).not.toMatch(/@/);
  }
});

test('TC-FR2-18-AS-01', async ({ page }) => {
  const email = emailFor('guide');
  await page.goto('/health-tools/protein/');
  await page.locator('#lld_guide_email').fill(email);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
  await expect(page.getByText('Check your email to confirm.')).toBeVisible();
  const first = await mailTo(email);
  expect(first.join('\n')).toContain('Confirm your starter guide');
  expect(first.join('\n')).not.toContain('Your starter guide');
  const link = localise(first.join('\n').match(/https?:\/\/\S*guide\/confirm\/\S+/)[0]);
  await page.goto(link);
  await expect(page.getByRole('heading', { name: 'Confirmed' })).toBeVisible();
  const second = await mailTo(email);
  expect(second.join('\n')).toContain('Your starter guide');
});

test('TC-FR2-18-AS-02', async ({ page }) => {
  const email = emailFor('unsub');
  await page.goto('/health-tools/protein/');
  await page.locator('#lld_guide_email').fill(email);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
  const first = await mailTo(email);
  const link = localise(first.join('\n').match(/https?:\/\/\S*guide\/confirm\/\S+/)[0]);
  await page.goto(link);
  const guide = (await mailTo(email)).join('\n');
  const unsub = localise(guide.match(/https?:\/\/\S*guide\/unsubscribe\/\S+/)[0]);
  await page.goto(unsub);
  await expect(page.getByRole('heading', { name: 'Unsubscribed' })).toBeVisible();
  const before = (await messages()).length;
  await page.goto('/health-tools/protein/');
  await page.locator('#lld_guide_email').fill(email);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
  expect((await messages()).length).toBe(before);
  await page.goto(unsub);
  await expect(page.getByRole('heading', { name: 'Already unsubscribed' })).toBeVisible();
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
  const changed = `Private change ${stamp()}`;
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
  await page.locator('#lld_changed').fill(changed);
  await page.locator('#lld_country').fill('GB');
  await page.getByRole('checkbox', { name: 'You may quote my first name and country' }).check();
  await page.getByRole('button', { name: 'Send survey' }).click();
  await page.goto('/testimonials/');
  await expect(page.locator('main')).not.toContainText(quote);
  await page.goto('/programmes/healthy-weight/');
  await expect(page.locator('main')).not.toContainText(quote);
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-quotes');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-quotes');
  await page.locator('li', { hasText: name }).getByRole('button', { name: 'Approve' }).click();
  await page.goto('/testimonials/');
  const block = page.locator('[data-lld-quote]', { hasText: quote });
  await expect(block).toContainText(`${name}, GB`);
  await expect(block).not.toContainText(changed);
  await expect(block).not.toContainText('Member');
  await page.goto('/programmes/healthy-weight/');
  await expect(page.locator('[data-lld-quote]', { hasText: quote })).toBeVisible();
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
  await page.goto('/health-tools/protein/');
  await page.locator('#lld_guide_email').fill(both);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
  await page.goto('/health-tools/diabetes-risk-check/');
  await page.locator('#lld_guide_email').fill(both);
  await page.getByRole('button', { name: 'Send me the guide' }).click();
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
