import { createHmac, randomBytes } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, signIn } from './helpers.mjs';
import { fillProteinForm, submitProtein } from '../../e2e/helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from '../../e2e/helpers/diabetes-form.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

function stamp() {
  return randomBytes(4).toString('hex');
}

function emailFor(label) {
  return `${label}-${stamp()}@test.lifelinedaily.invalid`;
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

async function readMember(request, email) {
  const res = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'read', email } });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function notify(request, event) {
  const body = JSON.stringify(event);
  const t = Math.floor(Date.now() / 1000);
  const v1 = createHmac('sha256', testSecret()).update(`${t}.${body}`).digest('hex');
  return request.post('/wp-json/lld-members/v1/stripe/notice', {
    headers: { 'Stripe-Signature': `t=${t},v1=${v1}`, 'Content-Type': 'application/json' },
    data: body,
  });
}

async function showPrice(page, { plan = 'monthly', country = 'US', code = '', ref = '' } = {}) {
  await page.goto(`/members/checkout/?plan=${plan}&lld_ref=${encodeURIComponent(ref)}`);
  await page.locator('#lld_country').selectOption(country);
  if (code) await page.locator('#lld_code').fill(code);
  await page.getByRole('button', { name: 'Show the price' }).click();
  const box = page.locator('[data-lld-charge]');
  await expect(box).toBeVisible();
  const href = await page.getByRole('link', { name: 'Continue to payment' }).getAttribute('href');
  return {
    charge: Number(await box.getAttribute('data-lld-charge')),
    shown: Number(await box.getAttribute('data-lld-shown')),
    tax: Number(await box.getAttribute('data-lld-tax')),
    currency: await box.getAttribute('data-lld-currency'),
    session: await box.getAttribute('data-lld-session'),
    href,
    text: await box.innerText(),
  };
}

async function payApi(request, email, options = {}) {
  const ready = await request.post('/wp-json/lld-members/v1/test/billing', {
    data: { action: 'session', email, plan: options.plan || 'monthly', country: options.country || 'US', code: options.code || '', ref: options.ref || '' },
  });
  expect(ready.ok(), await ready.text()).toBeTruthy();
  const quoted = await ready.json();
  const payment = `pi_${stamp()}`;
  const subscription = options.subscription || `sub_${stamp()}`;
  const res = await notify(request, {
    id: `evt_${payment}`,
    type: 'checkout.session.completed',
    data: {
      object: {
        id: quoted.session,
        amount_total: Number(quoted.charged),
        subscription,
        payment_intent: payment,
        email: options.billingEmail || email,
        fingerprint: options.fingerprint || '',
      },
    },
  });
  expect(res.ok(), await res.text()).toBeTruthy();
  return { ...quoted, charge: Number(quoted.charged), payment, subscription };
}

async function pay(page, email, options = {}) {
  await signIn(page, email, password);
  const quoted = await showPrice(page, options);
  const payment = `pi_${stamp()}`;
  const subscription = options.subscription || `sub_${stamp()}`;
  const res = await notify(page.request, {
    id: `evt_${payment}`,
    type: 'checkout.session.completed',
    data: {
      object: {
        id: quoted.session,
        amount_total: quoted.charge,
        subscription,
        payment_intent: payment,
        email: options.billingEmail || email,
        fingerprint: options.fingerprint || '',
      },
    },
  });
  expect(res.ok(), await res.text()).toBeTruthy();
  return { ...quoted, payment, subscription };
}

async function renew(request, subscription, amount, payment = `pi_${stamp()}`) {
  const res = await notify(request, {
    id: `evt_${payment}`,
    type: 'invoice.paid',
    data: { object: { id: `in_${payment}`, subscription, amount_paid: amount, payment_intent: payment } },
  });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function serveTools(page) {
  await page.route('**/tools/*.html', async (route) => {
    const name = route.request().url().includes('diabetes') ? 'diabetes-risk-tool.html' : 'protein-requirement-tool.html';
    const body = readFileSync(resolve(root, 'site/public_html/tools', name));
    await route.fulfill({ status: 200, contentType: 'text/html', body });
  });
  await page.route('**/tools/status.json', async (route) => {
    const body = readFileSync(resolve(root, 'site/public_html/tools/status.json'));
    await route.fulfill({ status: 200, contentType: 'application/json', headers: { 'cache-control': 'no-store' }, body });
  });
}

async function reports(request) {
  const res = await request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function refCode(page, email) {
  await signIn(page, email, password);
  await page.goto('/members/home/');
  const href = await page.getByRole('link', { name: /\/pricing\/\?ref=/ }).getAttribute('href');
  return new URL(href).searchParams.get('ref');
}

async function startTrack(page, slug) {
  await page.goto('/members/tracks/');
  const nonce = await page.locator('input[name="lld_track_nonce"]').first().inputValue();
  const res = await page.request.post('/members/tracks/', {
    form: { lld_track_nonce: nonce, lld_track: slug },
    maxRedirects: 0,
  });
  expect([200, 302, 303]).toContain(res.status());
}

test.beforeEach(async ({ request }) => {
  const res = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'reset' } });
  expect(res.ok(), await res.text()).toBeTruthy();
});

test('TC-FR2-13-AS-01', async ({ page }) => {
  await page.goto('/pricing/');
  const plans = page.locator('[data-lld-plan]');
  await expect(plans).toHaveCount(2);
  await expect(plans.nth(0)).toContainText('US$59.99 a year');
  await expect(plans.nth(0)).toContainText('best value');
  await expect(plans.nth(0)).toContainText('tax included');
  await expect(plans.nth(1)).toContainText('US$7.99 a month');
  await expect(plans.nth(1)).toContainText('tax included');
  const html = await page.locator('main').innerHTML();
  expect(html.indexOf('data-lld-plan="yearly"')).toBeLessThan(html.indexOf('data-lld-plan="monthly"'));
});

test('TC-FR2-13-AS-02', async ({ page }) => {
  const cases = [
    { country: 'GB', taxName: 'GB VAT', currency: 'gbp', sign: '£', tax: true },
    { country: 'DE', taxName: 'DE VAT', currency: 'eur', sign: '€', tax: true },
    { country: 'US', taxName: 'no sales tax', currency: 'usd', sign: 'US$', tax: false },
  ];
  for (const row of cases) {
    const email = emailFor(row.country.toLowerCase());
    await createMember(page.request, { email, password, first_name: 'Pay', role: 'member' });
    const quoted = await pay(page, email, { plan: 'monthly', country: row.country });
    expect(quoted.charge).toBe(quoted.shown);
    expect(quoted.currency).toBe(row.currency);
    expect(quoted.text).toContain(`${row.sign}7.99`);
    await expect(page.getByText(row.taxName)).toBeVisible();
    if (row.tax) expect(quoted.tax).toBeGreaterThan(0);
    else expect(quoted.tax).toBe(0);
    expect(quoted.tax).toBeLessThan(quoted.charge);
    const snap = await readMember(page.request, email);
    expect(Number(snap.orders[0].charged_amount)).toBe(quoted.charge);
    expect(Number(snap.orders[0].tax_amount)).toBe(quoted.tax);
  }
});

test('TC-FR2-13-AS-03', async ({ page, request }) => {
  const email = emailFor('launch');
  await createMember(request, { email, password, first_name: 'Launch', role: 'member' });
  await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'launch', on: '2026-06-01' } });
  await clock(request, email, '2026-06-01 12:00:00');
  const first = await pay(page, email, { plan: 'monthly', country: 'US', code: 'LAUNCH' });
  expect(first.charge).toBe(400);
  expect(first.text).toContain('US$4.00');
  await renew(request, first.subscription, 799);
  const snap = await readMember(request, email);
  expect(Number(snap.orders[1].charged_amount)).toBe(799);
  expect(snap.orders[1].discount_code).toBeFalsy();
  await clock(request, email, '2026-07-02 12:00:00');
  await signIn(page, email, password);
  const late = await showPrice(page, { plan: 'monthly', country: 'US', code: 'LAUNCH' });
  expect(late.charge).toBe(799);
  await expect(page.getByText('This code has ended.')).toBeVisible();
});

test('TC-FR2-13-AS-04', async ({ page, request }) => {
  const email = emailFor('owner-price');
  await createMember(request, { email, password, first_name: 'New', role: 'member' });
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-payments');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-payments');
  await page.locator('input[name="lld_monthly"]').fill('8.99');
  await page.locator('input[name="lld_new_code"]').fill('HALF');
  await page.locator('input[name="lld_percent"]').fill('50');
  await page.getByRole('button', { name: 'Save prices' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  const quoted = await pay(page, email, { plan: 'monthly', country: 'US', code: 'HALF' });
  expect(quoted.charge).toBe(450);
  expect(quoted.text).toContain('US$4.50');
  await signIn(page, email, password);
  const full = await showPrice(page, { plan: 'monthly', country: 'US' });
  expect(full.text).toContain('US$8.99');
});

test('TC-FR2-13-AS-05', async ({ page, request }) => {
  const yearly = emailFor('year');
  const monthly = emailFor('month');
  await createMember(request, { email: yearly, password, first_name: 'Year', role: 'member', access_state: 'Premium', plan: 'yearly' });
  await createMember(request, { email: monthly, password, first_name: 'Month', role: 'member', access_state: 'Premium', plan: 'monthly' });
  await signIn(page, yearly, password);
  await page.goto('/members/subscription/');
  await expect(page.getByText('Stage 3 app: included')).toBeVisible();
  const yearSnap = await readMember(request, yearly);
  expect(yearSnap.app).toBe('included');
  await signIn(page, monthly, password);
  await page.goto('/members/subscription/');
  await expect(page.getByText('Stage 3 app: not included')).toBeVisible();
  const monthSnap = await readMember(request, monthly);
  expect(monthSnap.app).toBe('not included');
});

test('TC-FR2-14-AS-01', async ({ page, request }) => {
  const email = emailFor('hosted');
  await createMember(request, { email, password, first_name: 'Host', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/checkout/?result=back');
  await expect(page.getByText("We're confirming your payment")).toBeVisible();
  const before = await readMember(request, email);
  expect(before.subscription).toBeFalsy();
  const quoted = await showPrice(page, { plan: 'yearly', country: 'US' });
  expect(quoted.href.startsWith('https://checkout.stripe.com/c/pay/')).toBeTruthy();
  const card = page.locator('input[autocomplete="cc-number"], input[name="cardnumber"], input[name="card_number"]');
  await expect(card).toHaveCount(0);
  await expect(page.getByText('We never see your card details.')).toBeVisible();
});

test('TC-FR2-14-AS-02', async ({ page, request }) => {
  const email = emailFor('gbp');
  await createMember(request, { email, password, first_name: 'Pound', role: 'member' });
  await signIn(page, email, password);
  const quoted = await showPrice(page, { plan: 'monthly', country: 'GB' });
  expect(quoted.currency).toBe('gbp');
  expect(quoted.text).toContain('£7.99');
  expect(quoted.href.startsWith('https://checkout.stripe.com/')).toBeTruthy();
  await expect(page.getByText('Apple Pay and Google Pay are offered by Stripe on devices that support them.')).toBeVisible();
});

test('TC-FR2-14-AS-03', async ({ page, request }) => {
  const monthly = emailFor('receipt');
  const yearly = emailFor('remind');
  await createMember(request, { email: monthly, password, first_name: 'Receipt', role: 'member' });
  await clock(request, monthly, '2026-08-01 12:00:00');
  const paid = await pay(page, monthly, { plan: 'monthly', country: 'US' });
  await renew(request, paid.subscription, 799);
  const messages = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const receipts = (messages.messages || []).filter((message) => JSON.stringify(message).includes(monthly) && JSON.stringify(message).includes('Your receipt'));
  expect(receipts.length).toBeGreaterThan(1);
  await createMember(request, { email: yearly, password, first_name: 'Remind', role: 'member', access_state: 'Premium', plan: 'yearly', period_ends_on: '2026-08-08' });
  await clock(request, yearly, '2026-08-01 12:00:00');
  await reports(request);
  const after = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const reminder = (after.messages || []).filter((message) => JSON.stringify(message).includes(yearly) && JSON.stringify(message).includes('Your plan renews in 7 days'));
  expect(reminder.length).toBeGreaterThan(0);
});

test('TC-FR2-14-AS-04', async ({ page, request }) => {
  const email = emailFor('retry');
  await createMember(request, {
    email, password, first_name: 'Retry', role: 'member', access_state: 'Premium', plan: 'monthly', stripe_subscription: 'sub_retry_' + stamp(), period_ends_on: '2026-12-01',
  });
  const snap = await readMember(request, email);
  const subscription = snap.subscription.stripe_subscription;
  await clock(request, email, '2026-06-01 12:00:00');
  const failed = await notify(request, {
    id: `evt_fail_${stamp()}`,
    type: 'invoice.payment_failed',
    data: { object: { subscription } },
  });
  expect(failed.ok()).toBeTruthy();
  await signIn(page, email, password);
  await page.goto('/members/subscription/');
  await expect(page.getByText('Update your card')).toBeVisible();
  await expect(page.getByText('Your membership is Premium.')).toBeVisible();
  await clock(request, email, '2026-06-08 12:00:00');
  await reports(request);
  await page.goto('/members/subscription/');
  await expect(page.getByText('Your membership is Premium ended.')).toBeVisible();
});

test('TC-FR2-14-AS-05', async ({ page, request }) => {
  const email = emailFor('cancel');
  const made = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'days', stamp: stamp() } });
  const track = (await made.json()).track;
  await createMember(request, { email, password, first_name: 'Cancel', role: 'member', access_state: 'Premium', plan: 'monthly', period_ends_on: '2026-11-01' });
  await clock(request, email, '2026-10-26 12:00:00');
  await signIn(page, email, password);
  await startTrack(page, track);
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('Part of Premium')).toHaveCount(0);
  await page.goto('/members/home/');
  await page.getByRole('link', { name: 'My subscription' }).click();
  await page.getByRole('link', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'Confirm cancellation' }).click();
  await expect(page.getByText(/Premium until/)).toBeVisible();
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('Part of Premium')).toHaveCount(0);
  await clock(request, email, '2026-11-02 12:00:00');
  await reports(request);
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
});

test('TC-FR2-14-AS-06', async ({ page, request }) => {
  const email = emailFor('refund');
  await createMember(request, { email, password, first_name: 'Refund', role: 'member' });
  await clock(request, email, '2026-10-20 12:00:00');
  await pay(page, email, { plan: 'yearly', country: 'US' });
  const snap = await readMember(request, email);
  const orderId = snap.orders[0].id;
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-payments');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-payments');
  await page.locator('form', { hasText: `Order ${orderId}` }).getByRole('button', { name: 'Refund' }).click();
  await expect(page.locator('.notice', { hasText: 'Refunded' })).toBeVisible();
  const after = await readMember(request, email);
  expect(after.subscription.access_state).toBe('Refunded');
  expect(after.orders.some((order) => order.kind === 'refund')).toBeTruthy();
});

test('TC-FR2-14-AS-07', async ({ page, request }) => {
  const email = emailFor('notice');
  await createMember(request, { email, password, first_name: 'Notice', role: 'member' });
  await clock(request, email, '2026-10-01 12:00:00');
  const forged = await request.post('/wp-json/lld-members/v1/stripe/notice', {
    data: { id: `evt_forged_${stamp()}`, type: 'checkout.session.completed', data: { object: { id: 'cs_test_forged', amount_total: 5999 } } },
  });
  expect(forged.status()).toBe(400);
  expect((await readMember(request, email)).subscription).toBeFalsy();
  await signIn(page, email, password);
  const quoted = await showPrice(page, { plan: 'monthly', country: 'US' });
  const payment = `pi_${stamp()}`;
  const subscription = `sub_${stamp()}`;
  const event = {
    id: `evt_${payment}`,
    type: 'checkout.session.completed',
    data: { object: { id: quoted.session, amount_total: quoted.charge, subscription, payment_intent: payment, email } },
  };
  expect((await notify(request, event)).ok()).toBeTruthy();
  const replay = await notify(request, event);
  expect(replay.ok()).toBeTruthy();
  expect((await replay.json()).replay).toBe(true);
  expect((await readMember(request, email)).orders).toHaveLength(1);

  const held = emailFor('held');
  await createMember(request, { email: held, password, first_name: 'Held', role: 'member' });
  await signIn(page, held, password);
  const second = await showPrice(page, { plan: 'yearly', country: 'US' });
  const subId = `sub_${stamp()}`;
  const early = await notify(request, { id: `evt_del_${stamp()}`, type: 'customer.subscription.deleted', data: { object: { id: subId } } });
  expect(early.ok()).toBeTruthy();
  expect((await readMember(request, held)).subscription).toBeFalsy();
  const completed = await notify(request, {
    id: `evt_pay_${stamp()}`,
    type: 'checkout.session.completed',
    data: { object: { id: second.session, amount_total: second.charge, subscription: subId, payment_intent: `pi_${stamp()}`, email: held } },
  });
  expect(completed.ok()).toBeTruthy();
  const finalState = await readMember(request, held);
  expect(finalState.subscription.access_state).toBe('cancelled');
  expect(finalState.subscription.period_ends_on > '2026-10-01').toBeTruthy();

  const mismatch = emailFor('mismatch');
  await createMember(request, { email: mismatch, password, first_name: 'Mismatch', role: 'member' });
  await clock(request, mismatch, '2026-10-01 12:00:00');
  await signIn(page, mismatch, password);
  const bad = await showPrice(page, { plan: 'monthly', country: 'US' });
  const rejected = await notify(request, {
    id: `evt_bad_${stamp()}`,
    type: 'checkout.session.completed',
    data: { object: { id: bad.session, amount_total: 1, subscription: `sub_${stamp()}`, payment_intent: `pi_${stamp()}`, email: mismatch } },
  });
  expect(rejected.ok()).toBeTruthy();
  expect((await readMember(request, mismatch)).subscription).toBeFalsy();
  await clock(request, mismatch, '2026-10-02 12:00:00');
  await reports(request);
  const mail = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const flagged = (mail.messages || []).filter((message) => JSON.stringify(message).includes('A payment does not match a member'));
  expect(flagged.length).toBeGreaterThan(0);
});

test('TC-FR2-14-AS-08', async ({ page, request }) => {
  const email = emailFor('switch');
  const subscription = `sub_${stamp()}`;
  await createMember(request, {
    email, password, first_name: 'Switch', role: 'member', access_state: 'Premium', plan: 'monthly', period_ends_on: '2026-11-26', stripe_subscription: subscription,
  });
  await signIn(page, email, password);
  await page.goto('/members/subscription/');
  await page.getByRole('button', { name: 'Switch to yearly' }).click();
  await expect(page.getByText('Switch to yearly on 2026-11-26')).toBeVisible();
  await page.getByRole('button', { name: 'Undo' }).click();
  await expect(page.getByText('Switch to yearly on')).toHaveCount(0);
  await renew(request, subscription, 799);
  const snap = await readMember(request, email);
  expect(snap.subscription.plan).toBe('monthly');
});

test('TC-FR2-15-AS-01', async ({ page, request }) => {
  const mark = stamp();
  const made = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'days', stamp: mark } });
  const fixture = await made.json();
  const title = fixture.items[7].title;
  const people = [
    { state: 'free', plan: 'free', day8: false, meals: false },
    { state: 'Premium', plan: 'yearly', day8: true, meals: true },
    { state: 'cancelled', plan: 'yearly', day8: true, meals: true, period_ends_on: '2027-01-01' },
    { state: 'Premium ended', plan: 'yearly', day8: false, meals: false },
    { state: 'Refunded', plan: 'yearly', day8: false, meals: false },
  ];
  for (const person of people) {
    const email = emailFor(person.state.replace(/\s/g, ''));
    await createMember(request, {
      email, password, first_name: 'State', role: 'member', access_state: person.state, plan: person.plan, period_ends_on: person.period_ends_on || null,
    });
    // Day 8 must be reached before anyone can open it (T3): start on 1 August, look on 8 August.
    await clock(request, email, '2026-08-01 12:00:00');
    await signIn(page, email, password);
    await startTrack(page, fixture.track);
    await clock(request, email, '2026-08-08 12:00:00');
    await page.goto('/members/track/?day=8');
    if (person.day8) await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    else await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await page.goto('/members/meals/');
    if (person.meals) await expect(page.getByText('Part of Premium')).toHaveCount(0);
    else await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await page.goto('/members/workout/');
    if (person.meals) await expect(page.getByText('Part of Premium')).toHaveCount(0);
    else await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await page.goto('/members/challenge/');
    if (person.meals) await expect(page.getByText('Part of Premium')).toHaveCount(0);
    else await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
  }
});

test('TC-FR2-15-AS-02', async ({ page, request }) => {
  const email = emailFor('unlock');
  await createMember(request, { email, password, first_name: 'Unlock', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/meals/');
  await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
  const started = Date.now();
  await pay(page, email, { plan: 'yearly', country: 'US' });
  await page.goto('/members/meals/');
  await expect(page.getByText('Part of Premium')).toHaveCount(0);
  expect(Date.now() - started).toBeLessThan(60000);
});

test('TC-FR2-15-AS-03', async ({ page, request }) => {
  const email = emailFor('preview');
  const made = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'days', stamp: stamp() } });
  const fixture = await made.json();
  const title = fixture.items[7].title;
  await createMember(request, { email, password, first_name: 'Preview', role: 'member' });
  await signIn(page, email, password);
  await startTrack(page, fixture.track);
  await page.goto('/members/track/?day=8');
  await expect(page.getByText('A short preview of this Premium item.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Upgrade' })).toBeVisible();
  await expect(page.getByText(title)).toHaveCount(0);
});

test('TC-FR2-15-AS-04', async ({ page }) => {
  await serveTools(page);
  await page.context().clearCookies();
  for (const path of ['/tools/protein-requirement-tool.html', '/tools/diabetes-risk-tool.html', '/safety/', '/medical-disclaimer/']) {
    const response = await page.goto(path);
    expect(response.status(), path).toBeLessThan(400);
  }
  await expect(page.getByRole('heading', { name: 'Medical disclaimer' })).toBeVisible();
});

test('TC-FR2-15-AS-05', async ({ page, request }) => {
  const mark = stamp();
  const days = await (await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'days', stamp: mark } })).json();
  async function midWeek(email, how) {
    await createMember(request, { email, password, first_name: 'Week', role: 'member' });
    await clock(request, email, '2026-06-01 12:00:00');
    await signIn(page, email, password);
    await startTrack(page, days.track);
    if (how === 'refund') {
      await clock(request, email, '2026-06-10 12:00:00');
      await pay(page, email, { plan: 'yearly', country: 'US' });
      const orderId = (await readMember(request, email)).orders[0].id;
      await asOwner(page);
      await page.goto('/wp-admin/admin.php?page=lld-payments');
      if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-payments');
      await page.locator('form', { hasText: `Order ${orderId}` }).getByRole('button', { name: 'Refund' }).click();
    } else {
      await pay(page, email, { plan: 'monthly', country: 'US' });
      const snap = await readMember(request, email);
      await request.post('/wp-json/lld-members/v1/test/member', {
        data: { email, password, first_name: 'Week', role: 'member', access_state: 'cancelled', plan: 'monthly', period_ends_on: '2026-06-10', stripe_subscription: snap.subscription.stripe_subscription },
      });
      await clock(request, email, '2026-06-11 12:00:00');
      await reports(request);
    }
    await signIn(page, email, password);
    await page.goto('/members/meals/');
    await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await page.goto('/members/track/?day=10');
    await expect(page.getByText('Part of Premium')).toHaveCount(0);
    await page.goto('/members/track/?day=15');
    await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await clock(request, email, '2026-06-15 12:00:00');
    await page.goto('/members/track/?day=15');
    await expect(page.getByText('Part of Premium', { exact: true })).toBeVisible();
    await page.goto('/members/track/?day=10');
    await expect(page.getByText('Part of Premium')).toHaveCount(0);
  }
  await midWeek(emailFor('expired'), 'expired');
  await midWeek(emailFor('refunded'), 'refund');
});

test('TC-FR2-16-AS-01', async ({ page }) => {
  await page.goto('/pricing/');
  await expect(page.locator('[data-lld-plan="yearly"]')).toContainText('best value');
  const pages = [
    ['/programmes/healthy-weight/', 'Healthy Weight'],
    ['/programmes/blood-sugar/', 'Blood Sugar Balance'],
    ['/programmes/fitness/', 'Fitness and Strength'],
    ['/programmes/sleep-stress/', 'Sleep and Stress'],
  ];
  for (const [path, title] of pages) {
    await page.goto(path);
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
    for (const heading of ['Who it is for', 'Who it is not for', 'Weekly themes', 'Sample activity', 'Sample meal', 'Testimonials', 'Questions and answers', 'Safety note']) {
      await expect(page.getByRole('heading', { name: heading })).toBeVisible();
    }
    await expect(page.getByText('tax included')).toBeVisible();
    await expect(page.getByText('Cancel any time')).toBeVisible();
  }
});

test('TC-FR2-16-AS-02', async ({ page }) => {
  await serveTools(page);
  const protein = {
    units: 'metric', age: 40, sex: 'male', preg: 'no', heightCm: 175, weightKg: 75, ethnicity: 'White', activity: 'moderate', resistance: '0', goal: 'maintain', diet: 'omnivore', meals: 3, kidney: 'no', liver: 'no',
  };
  const diabetes = {
    units: 'metric', sex: 'Male', ageBand: 27, heightCm: 170, weightKg: 75, ethn: 'Indian', smoke: 'Non', family: [], htn: 0, cvd: 0, gdm: 0, pcos: 0, learn: 0, psy: 0, ster: 0, stat: 0, apsy: 0, activity: 'moderate', hasSym: 'no', symptoms: [], emergency: false,
  };
  await page.goto('/health-tools/protein/');
  await expect(page.locator('[data-lld-track-link]')).toContainText('Fitness and Strength');
  const proteinFrame = page.frameLocator('iframe');
  await fillProteinForm(proteinFrame, protein);
  await submitProtein(proteinFrame);
  const proteinResult = proteinFrame.locator('#results');
  await expect(proteinResult).toBeVisible();
  await expect(proteinResult).not.toContainText(/US\$|£|€|Upgrade|best value/i);
  await page.goto('/health-tools/diabetes-risk-check/');
  await expect(page.locator('[data-lld-track-link]')).toContainText('Blood Sugar Balance');
  const diabetesFrame = page.frameLocator('iframe');
  await fillDiabetesForm(diabetesFrame, diabetes);
  await submitDiabetes(diabetesFrame);
  const diabetesResult = diabetesFrame.locator('#results');
  await expect(diabetesResult).toBeVisible();
  await expect(diabetesResult).not.toContainText(/US\$|£|€|Upgrade|best value/i);
});

test('TC-FR2-16-AS-03', async ({ page, request }) => {
  const expected = [
    ['/programmes/healthy-weight/', 'Healthy Weight', 'four-week Healthy Weight'],
    ['/programmes/blood-sugar/', 'Blood Sugar Balance', 'Blood Sugar Balance track'],
    ['/programmes/fitness/', 'Fitness and Strength', 'Fitness and Strength track'],
    ['/programmes/sleep-stress/', 'Sleep and Stress', 'Sleep and Stress track'],
  ];
  for (const [path, title, description] of expected) {
    await page.goto(path);
    await expect(page).toHaveTitle(title);
    const meta = await page.locator('meta[name="description"]').getAttribute('content');
    expect(meta).toContain(description);
  }
  const sitemap = await request.get('/sitemap.xml');
  expect(sitemap.ok()).toBeTruthy();
  const xml = await sitemap.text();
  for (const [path] of expected) expect(xml).toContain(path);
  expect(xml).toContain('/pricing/');
});

test('TC-FR2-29-AS-01', async ({ page, request }) => {
  test.setTimeout(180000);
  const yearly = emailFor('ref-year');
  const friend = emailFor('ref-friend');
  await createMember(request, { email: yearly, password, first_name: 'Refer', role: 'member' });
  await createMember(request, { email: friend, password, first_name: 'Friend', role: 'member' });
  await clock(request, yearly, '2026-05-01 12:00:00');
  await clock(request, friend, '2026-05-01 12:00:00');
  const ref = await refCode(page, yearly);
  expect(ref).toBeTruthy();
  await payApi(request, yearly, { plan: 'yearly', country: 'US' });
  await payApi(request, friend, { plan: 'yearly', country: 'US', ref });
  await clock(request, friend, '2026-05-20 12:00:00');
  await reports(request);
  const yearSnap = await readMember(request, yearly);
  expect(yearSnap.next.charged).toBe(5219);
  await signIn(page, yearly, password);
  await page.goto('/members/subscription/');
  await expect(page.getByText('Next payment US$52.19')).toBeVisible();

  const monthly = emailFor('ref-month');
  const pal = emailFor('ref-pal');
  await createMember(request, { email: monthly, password, first_name: 'Month', role: 'member' });
  await createMember(request, { email: pal, password, first_name: 'Pal', role: 'member' });
  await clock(request, monthly, '2026-05-01 12:00:00');
  await clock(request, pal, '2026-05-01 12:00:00');
  const monthRef = await refCode(page, monthly);
  const monthPay = await payApi(request, monthly, { plan: 'monthly', country: 'US' });
  await payApi(request, pal, { plan: 'monthly', country: 'US', ref: monthRef });
  await clock(request, pal, '2026-05-20 12:00:00');
  await reports(request);
  expect((await readMember(request, monthly)).next.charged).toBe(0);
  await renew(request, monthPay.subscription, 0);
  expect((await readMember(request, monthly)).next.charged).toBe(799);

  const twice = emailFor('ref-twice');
  await createMember(request, { email: twice, password, first_name: 'Twice', role: 'member' });
  await clock(request, twice, '2026-05-01 12:00:00');
  const twiceRef = await refCode(page, twice);
  const twicePay = await payApi(request, twice, { plan: 'monthly', country: 'US' });
  for (let i = 0; i < 2; i += 1) {
    const other = emailFor(`twice-${i}`);
    await createMember(request, { email: other, password, first_name: 'Other', role: 'member' });
    await clock(request, other, '2026-05-01 12:00:00');
    await payApi(request, other, { plan: 'monthly', country: 'US', ref: twiceRef });
    await clock(request, other, '2026-05-20 12:00:00');
  }
  await reports(request);
  expect((await readMember(request, twice)).next.charged).toBe(0);
  await renew(request, twicePay.subscription, 0);
  expect((await readMember(request, twice)).next.charged).toBe(0);
  await renew(request, twicePay.subscription, 0);
  expect((await readMember(request, twice)).next.charged).toBe(799);

  const coded = emailFor('ref-code');
  const codedFriend = emailFor('ref-coded-friend');
  await createMember(request, { email: coded, password, first_name: 'Code', role: 'member' });
  await createMember(request, { email: codedFriend, password, first_name: 'Coded', role: 'member' });
  await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'launch', on: '2026-05-01' } });
  await clock(request, coded, '2026-05-01 12:00:00');
  await clock(request, codedFriend, '2026-05-01 12:00:00');
  const codedRef = await refCode(page, coded);
  await payApi(request, coded, { plan: 'monthly', country: 'US' });
  const codedPay = await payApi(request, codedFriend, { plan: 'monthly', country: 'US', ref: codedRef, code: 'LAUNCH' });
  expect(codedPay.charge).toBe(400);
  await clock(request, codedFriend, '2026-05-20 12:00:00');
  await reports(request);
  const codedSnap = await readMember(request, codedFriend);
  expect(Number(codedSnap.orders[0].charged_amount)).toBe(400);
  expect(codedSnap.orders[0].discount_code).toBe('LAUNCH');
  expect(codedSnap.rewards.some((reward) => reward.status === 'pending')).toBeTruthy();
  expect(codedSnap.next.charged).toBe(0);

  const locked = emailFor('lock');
  await createMember(request, { email: locked, password, first_name: 'Lock', role: 'member' });
  await clock(request, locked, '2026-05-01 12:00:00');
  await payApi(request, locked, { plan: 'yearly', country: 'US' });
  await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'prices', yearly: 6999, monthly: 799 } });
  expect((await readMember(request, locked)).next.charged).toBe(5999);
  const rejoined = emailFor('rejoin');
  await createMember(request, { email: rejoined, password, first_name: 'Rejoin', role: 'member' });
  await signIn(page, rejoined, password);
  const fresh = await showPrice(page, { plan: 'yearly', country: 'US' });
  expect(fresh.text).toContain('US$69.99');
});

test('TC-FR2-29-AS-02', async ({ page, request }) => {
  const referrer = emailFor('limit');
  await createMember(request, { email: referrer, password, first_name: 'Limit', role: 'member' });
  await clock(request, referrer, '2026-03-01 12:00:00');
  const ref = await refCode(page, referrer);
  await pay(page, referrer, { plan: 'monthly', country: 'US' });
  for (let i = 0; i < 4; i += 1) {
    const other = emailFor(`limit-${i}`);
    await createMember(request, { email: other, password, first_name: 'Four', role: 'member' });
    await clock(request, other, '2026-03-01 12:00:00');
    await pay(page, other, { plan: 'monthly', country: 'US', ref });
    await clock(request, other, '2026-03-20 12:00:00');
  }
  await reports(request);
  const snap = await readMember(request, referrer);
  expect(snap.referrals.filter((row) => row.status === 'rewarded')).toHaveLength(3);
  expect(snap.referrals.filter((row) => row.status === 'limited')).toHaveLength(1);

  const giver = emailFor('give');
  const back = emailFor('back');
  await createMember(request, { email: giver, password, first_name: 'Give', role: 'member' });
  await createMember(request, { email: back, password, first_name: 'Back', role: 'member' });
  await clock(request, giver, '2026-04-01 12:00:00');
  await clock(request, back, '2026-04-01 12:00:00');
  const giverRef = await refCode(page, giver);
  await pay(page, giver, { plan: 'monthly', country: 'US' });
  await pay(page, back, { plan: 'monthly', country: 'US', ref: giverRef });
  const orderId = (await readMember(request, back)).orders[0].id;
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-payments');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-payments');
  await page.locator('form', { hasText: `Order ${orderId}` }).getByRole('button', { name: 'Refund' }).click();
  await clock(request, back, '2026-04-20 12:00:00');
  await reports(request);
  const giverSnap = await readMember(request, giver);
  expect(giverSnap.referrals.some((row) => row.status === 'refunded')).toBeTruthy();
  expect(giverSnap.rewards).toHaveLength(0);
});

test('TC-FR2-29-AS-03', async ({ page, request }) => {
  const referrer = emailFor('self');
  const byEmail = emailFor('self-email');
  const byCard = emailFor('self-card');
  await createMember(request, { email: referrer, password, first_name: 'Self', role: 'member' });
  await createMember(request, { email: byEmail, password, first_name: 'Mail', role: 'member' });
  await createMember(request, { email: byCard, password, first_name: 'Card', role: 'member' });
  await clock(request, referrer, '2026-02-01 12:00:00');
  const ref = await refCode(page, referrer);
  await pay(page, referrer, { plan: 'monthly', country: 'US', fingerprint: 'fp_same' });
  await pay(page, byEmail, { plan: 'monthly', country: 'US', ref, billingEmail: referrer });
  await pay(page, byCard, { plan: 'monthly', country: 'US', ref, fingerprint: 'fp_same' });
  await clock(request, byEmail, '2026-02-20 12:00:00');
  await clock(request, byCard, '2026-02-20 12:00:00');
  await reports(request);
  const snap = await readMember(request, referrer);
  expect(snap.referrals.filter((row) => row.self_referral === 'email' || row.self_referral === 'card')).toHaveLength(2);
  expect(snap.rewards).toHaveLength(0);
});

test('TC-FR2-29-AS-04', async ({ page, request }) => {
  const referrer = emailFor('retry-ref');
  const friend = emailFor('retry-friend');
  await createMember(request, { email: referrer, password, first_name: 'Retry', role: 'member' });
  await createMember(request, { email: friend, password, first_name: 'Once', role: 'member' });
  await clock(request, referrer, '2026-01-01 12:00:00');
  await clock(request, friend, '2026-01-01 12:00:00');
  const ref = await refCode(page, referrer);
  await pay(page, referrer, { plan: 'monthly', country: 'US' });
  await pay(page, friend, { plan: 'monthly', country: 'US', ref });
  await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'stop_reward' } });
  await clock(request, friend, '2026-01-20 12:00:00');
  await reports(request);
  const halfway = await readMember(request, referrer);
  expect(halfway.rewards).toHaveLength(1);
  expect((await readMember(request, friend)).rewards).toHaveLength(0);
  await reports(request);
  expect((await readMember(request, referrer)).rewards).toHaveLength(1);
  expect((await readMember(request, friend)).rewards).toHaveLength(1);
});

test('TC-FR2-29-AS-05', async ({ page, request }) => {
  const referrer = emailFor('gbp-ref');
  const friend = emailFor('gbp-friend');
  await createMember(request, { email: referrer, password, first_name: 'Pound', role: 'member' });
  await createMember(request, { email: friend, password, first_name: 'Friend', role: 'member' });
  await clock(request, referrer, '2026-05-01 12:00:00');
  await clock(request, friend, '2026-05-01 12:00:00');
  const ref = await refCode(page, referrer);
  await pay(page, referrer, { plan: 'yearly', country: 'GB' });
  await pay(page, friend, { plan: 'yearly', country: 'GB', ref });
  await clock(request, friend, '2026-05-20 12:00:00');
  await reports(request);
  const snap = await readMember(request, referrer);
  expect(snap.next.charged).toBe(5219);
  expect(snap.next.currency).toBe('gbp');
  await signIn(page, referrer, password);
  await page.goto('/members/subscription/');
  await expect(page.getByText('Next payment £52.19')).toBeVisible();
});

test('TC-NFR2-10-AS-01', async () => {
  const pipeline = readFileSync(resolve(root, '.github/workflows/ci-guards.yml'), 'utf8');
  expect(pipeline).toContain('saf-protein');
  expect(pipeline).toContain('saf-diabetes');
  expect(pipeline).toContain('tests/stage2/e2e/playwright.config.mjs');
});
