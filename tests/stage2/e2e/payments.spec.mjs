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

// Test Criteria 4.4: forged notices are made by changing the signature of a real one.
async function notifySigned(request, event, { at = Math.floor(Date.now() / 1000), tamper = false } = {}) {
  const body = JSON.stringify(event);
  let v1 = createHmac('sha256', testSecret()).update(`${at}.${body}`).digest('hex');
  if (tamper) v1 = `${v1.slice(0, -1)}${v1.endsWith('0') ? '1' : '0'}`;
  return request.post('/wp-json/lld-members/v1/stripe/notice', {
    headers: { 'Stripe-Signature': `t=${at},v1=${v1}`, 'Content-Type': 'application/json' },
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

async function confirmRefund(page, orderId) {
  await page.locator('form', { hasText: `Order ${orderId}` }).getByRole('button', { name: 'Refund' }).click();
  await expect(page.locator(`[data-lld-refund-confirm="${orderId}"]`)).toBeVisible();
  await page.getByRole('button', { name: 'Confirm refund' }).click();
  await expect(page.locator('.notice', { hasText: 'Refunded' })).toBeVisible();
}

async function moneyLog(request) {
  const res = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'admin_log' } });
  expect(res.ok(), await res.text()).toBeTruthy();
  return (await res.json()).lines;
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
    expect(snap.orders[0].status).toBe('succeeded');
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
  // P3 order fields: the code's discount with its amount and currency; the renewal points to the first payment.
  expect(snap.orders[0].discount_code).toBe('LAUNCH');
  expect(Number(snap.orders[0].discount_amount)).toBe(399);
  expect(snap.orders[0].discount_currency).toBe('usd');
  expect(snap.orders[1].discount_amount).toBeNull();
  expect(Number(snap.orders[1].related_order_id)).toBe(Number(snap.orders[0].id));
  expect(snap.orders[0].related_order_id).toBeNull();
  expect(snap.orders.every((order) => order.stripe_invoice === null)).toBeTruthy();
  const lateEmail = emailFor('launch-late');
  await createMember(request, { email: lateEmail, password, first_name: 'Late', role: 'member' });
  await clock(request, lateEmail, '2026-07-02 12:00:00');
  await signIn(page, lateEmail, password);
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
  const lines = await moneyLog(request);
  expect(lines.some((row) => row.action === 'price_change' && row.detail.includes('Monthly US$7.99 to US$8.99'))).toBeTruthy();
  expect(lines.some((row) => row.action === 'code_created' && row.detail.startsWith('HALF, 50 %'))).toBeTruthy();

  await page.locator('input[name="lld_monthly"]').fill('0');
  await page.getByRole('button', { name: 'Save prices' }).click();
  await expect(page.getByText('needs the confirm box ticked')).toBeVisible();
  await expect(page.locator('input[name="lld_monthly"]')).toHaveValue('8.99');
  await page.locator('input[name="lld_new_code"]').fill('FREE');
  await page.locator('input[name="lld_percent"]').fill('100');
  await page.getByRole('button', { name: 'Save prices' }).click();
  await expect(page.getByText('needs the confirm box ticked')).toBeVisible();
  expect((await moneyLog(request)).some((row) => row.detail.startsWith('FREE,'))).toBeFalsy();
  const refused = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'prices', monthly: 0, yearly: 5999 } });
  expect(refused.status()).toBe(400);
  await page.locator('input[name="lld_new_code"]').fill('FREE');
  await page.locator('input[name="lld_percent"]').fill('100');
  await page.locator('input[name="lld_confirm_free"]').check();
  await page.getByRole('button', { name: 'Save prices' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  expect((await moneyLog(request)).some((row) => row.action === 'code_created' && row.detail.includes('(100 % confirmed)'))).toBeTruthy();

  const quoted = await pay(page, email, { plan: 'monthly', country: 'US', code: 'HALF' });
  expect(quoted.charge).toBe(450);
  expect(quoted.text).toContain('US$4.50');
  const fullEmail = emailFor('owner-price-full');
  await createMember(request, { email: fullEmail, password, first_name: 'Full', role: 'member' });
  await signIn(page, fullEmail, password);
  const full = await showPrice(page, { plan: 'monthly', country: 'US' });
  expect(full.text).toContain('US$8.99');
});

test('TC-FR2-13-AS-04 the plans are Paid Memberships Pro levels that follow the owner price', async ({ request }) => {
  const levels = async () => billingAction(request, { action: 'pmpro_levels' });
  let read = await levels();
  expect(read.named).toBe(2);
  expect(read.levels.yearly).toMatchObject({ name: 'Premium yearly', cycle_period: 'Year' });
  expect(read.levels.monthly).toMatchObject({ name: 'Premium monthly', cycle_period: 'Month' });
  expect(Number(read.levels.yearly.initial_payment)).toBe(59.99);
  expect(Number(read.levels.yearly.billing_amount)).toBe(59.99);
  expect(Number(read.levels.monthly.billing_amount)).toBe(7.99);
  expect(Number(read.levels.monthly.cycle_number)).toBe(1);
  expect(Number(read.levels.yearly.allow_signups)).toBe(0);
  const ids = { yearly: read.levels.yearly.id, monthly: read.levels.monthly.id };
  const update = await request.post('/wp-json/lld-members/v1/test/db-update', { data: {} });
  expect(update.ok(), await update.text()).toBeTruthy();
  expect((await update.json()).version).toBeGreaterThanOrEqual(3);
  read = await levels();
  expect(read.named).toBe(2);
  expect({ yearly: read.levels.yearly.id, monthly: read.levels.monthly.id }).toEqual(ids);
  await billingAction(request, { action: 'prices', monthly: 899, yearly: 6999 });
  read = await levels();
  expect(Number(read.levels.yearly.billing_amount)).toBe(69.99);
  expect(Number(read.levels.monthly.billing_amount)).toBe(8.99);
  expect(read.levels.yearly.id).toBe(ids.yearly);
});

test('TC-FR2-14-AS-03 orders are stamped in UTC, and the refund window uses the member local date', async ({ page, request }) => {
  const email = emailFor('utc');
  await createMember(request, { email, password, first_name: 'Utc', role: 'member' });
  // Europe/London is UTC+1 in July: 23:30 UTC is already 2 July there.
  await clock(request, email, '2026-07-01 23:30:00');
  await payApi(request, email, { plan: 'yearly', country: 'US' });
  let snap = await readMember(request, email);
  expect(snap.orders[0].ordered_at).toBe('2026-07-01 23:30:00');
  // 14 local days after 2 July is 16 July: still refundable at 16 July 22:30 UTC (23:30 local), not on 17 July local.
  await clock(request, email, '2026-07-16 22:30:00');
  await asOwner(page);
  await page.goto('/wp-admin/admin.php?page=lld-payments');
  if (page.url().includes('pmpro-wizard')) await page.goto('/wp-admin/admin.php?page=lld-payments');
  await confirmRefund(page, snap.orders[0].id);
  snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('Refunded');
  const refund = snap.orders.find((order) => order.kind === 'refund');
  expect(refund.ordered_at).toBe('2026-07-16 22:30:00');
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
  const ask = page.locator(`[data-lld-refund-confirm="${orderId}"]`);
  await expect(ask).toBeVisible();
  await expect(ask).toContainText('US$59.99');
  await expect(ask).toContainText(email);
  const waiting = await readMember(request, email);
  expect(waiting.subscription.access_state).toBe('Premium');
  expect(waiting.orders.some((order) => order.kind === 'refund')).toBeFalsy();
  await page.getByRole('button', { name: 'Confirm refund' }).click();
  await expect(page.locator('.notice', { hasText: 'Refunded' })).toBeVisible();
  const after = await readMember(request, email);
  expect(after.subscription.access_state).toBe('Refunded');
  const line = (await moneyLog(request)).find((row) => row.action === 'refund' && row.detail.includes(`Order ${orderId} `));
  expect(line).toBeTruthy();
  expect(Number(line.member_number)).toBe(Number(after.subscription.member_id));
  expect(line.member_email).toBe(email);
  expect(Number(line.money_related)).toBe(1);
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
  const real = await showPrice(page, { plan: 'monthly', country: 'US' });
  const genuine = {
    id: `evt_tampered_${stamp()}`,
    type: 'checkout.session.completed',
    data: { object: { id: real.session, amount_total: real.charge, subscription: `sub_${stamp()}`, payment_intent: `pi_${stamp()}`, email } },
  };
  const tampered = await notifySigned(request, genuine, { tamper: true });
  expect(tampered.status()).toBe(400);
  const stale = await notifySigned(request, genuine, { at: Math.floor(Date.now() / 1000) - 600 });
  expect(stale.status()).toBe(400);
  expect((await readMember(request, email)).subscription).toBeFalsy();
  expect((await noticeRow(request, genuine.id))).toBeFalsy();
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
  expect(finalState.subscription.access_state).toBe('Premium ended');
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

async function billingAction(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/billing', { data });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function noticeRow(request, ref, action = 'notice') {
  return (await billingAction(request, { action, ref })).notice;
}

const utcMs = (mysql) => Date.parse(`${mysql.replace(' ', 'T')}Z`);

test('TC-FR2-14-AS-07 a notice that fails is kept, retried and applied once', async ({ request }) => {
  const email = emailFor('kept');
  await createMember(request, { email, password, first_name: 'Kept', role: 'member' });
  await clock(request, email, '2026-10-01 12:00:00');
  const quoted = await billingAction(request, { action: 'session', email, plan: 'monthly', country: 'US' });
  const payment = `pi_${stamp()}`;
  const event = {
    id: `evt_${payment}`,
    type: 'checkout.session.completed',
    data: { object: { id: quoted.session, amount_total: Number(quoted.charged), subscription: `sub_${stamp()}`, payment_intent: payment, email } },
  };
  await billingAction(request, { action: 'fail_notice', ref: event.id });
  const first = await notify(request, event);
  expect(first.ok()).toBeTruthy();
  expect((await first.json()).state).toBe('retrying');
  let row = await noticeRow(request, event.id);
  expect(row.processing_state).toBe('retrying');
  expect(Number(row.attempts)).toBe(1);
  expect(Number(row.has_payload)).toBe(1);
  const gap = utcMs(row.next_attempt_at) - Date.now();
  expect(gap).toBeGreaterThan(55 * 60000);
  expect(gap).toBeLessThan(65 * 60000);
  const before = await readMember(request, email);
  expect(before.subscription).toBeFalsy();
  expect(before.orders).toHaveLength(0);

  const repeated = await notify(request, event);
  expect((await repeated.json()).replay).toBe(true);
  expect((await readMember(request, email)).orders).toHaveLength(0);

  await billingAction(request, { action: 'fail_notice', ref: '' });
  row = await noticeRow(request, event.id, 'retry_notice');
  expect(row.processing_state).toBe('processed');
  expect(Number(row.attempts)).toBe(2);
  expect(Number(row.has_payload)).toBe(0);
  const after = await readMember(request, email);
  expect(after.subscription.access_state).toBe('Premium');
  expect(after.orders).toHaveLength(1);

  row = await noticeRow(request, event.id, 'retry_notice');
  expect(row.processing_state).toBe('processed');
  expect(Number(row.attempts)).toBe(2);
  expect((await readMember(request, email)).orders).toHaveLength(1);
});

test('TC-FR2-14-AS-07 a notice that keeps failing is retried 5 times over 24 hours, then flagged to the owner', async ({ request }) => {
  const event = { id: `evt_stuck_${stamp()}`, type: 'invoice.paid', data: { object: { id: `in_${stamp()}`, subscription: `sub_${stamp()}`, amount_paid: 799 } } };
  await billingAction(request, { action: 'fail_notice', ref: event.id });
  expect((await notify(request, event)).ok()).toBeTruthy();
  const hours = [1, 2, 4, 8, 9];
  let row = await noticeRow(request, event.id);
  for (let retry = 0; retry < 5; retry += 1) {
    expect(row.processing_state).toBe('retrying');
    expect(Number(row.attempts)).toBe(retry + 1);
    const gap = (utcMs(row.next_attempt_at) - Date.now()) / 3600000;
    expect(Math.abs(gap - hours[retry])).toBeLessThan(0.1);
    row = await noticeRow(request, event.id, 'retry_notice');
  }
  expect(hours.reduce((a, b) => a + b, 0)).toBe(24);
  expect(row.processing_state).toBe('failed');
  expect(Number(row.attempts)).toBe(6);
  expect(row.last_error).toBeTruthy();
  row = await noticeRow(request, event.id, 'retry_notice');
  expect(Number(row.attempts)).toBe(6);
  const mail = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const flagged = (mail.messages || []).filter((message) => JSON.stringify(message).includes('A Stripe notice could not be processed'));
  expect(flagged.length).toBeGreaterThan(0);
  await billingAction(request, { action: 'fail_notice', ref: '' });
});

test('TC-FR2-14-AS-04 the 7-day window starts at the first failure of the invoice', async ({ request }) => {
  const email = emailFor('window');
  const subscription = `sub_window_${stamp()}`;
  await createMember(request, {
    email, password, first_name: 'Window', role: 'member', access_state: 'Premium', plan: 'monthly', stripe_subscription: subscription, period_ends_on: '2026-12-01',
  });
  const invoice = `in_${stamp()}`;
  const fail = () => notify(request, { id: `evt_fail_${stamp()}`, type: 'invoice.payment_failed', data: { object: { id: invoice, subscription } } });
  await clock(request, email, '2026-06-01 12:00:00');
  expect((await fail()).ok()).toBeTruthy();
  await clock(request, email, '2026-06-05 12:00:00');
  expect((await fail()).ok()).toBeTruthy();
  await clock(request, email, '2026-06-07 12:00:00');
  await reports(request);
  expect((await readMember(request, email)).subscription.access_state).toBe('Premium');
  await clock(request, email, '2026-06-08 12:00:00');
  await reports(request);
  const ended = await readMember(request, email);
  expect(ended.subscription.access_state).toBe('Premium ended');
  // Each failed attempt is an order (P3): kind failed_attempt, status failed.
  const attempts = ended.orders.filter((order) => order.kind === 'failed_attempt');
  expect(attempts).toHaveLength(2);
  expect(attempts.every((order) => order.status === 'failed' && Number(order.charged_amount) === 799)).toBeTruthy();
});

test('TC-FR2-14-AS-04 a failed attempt points to the payment it belongs to and is not a receipt', async ({ page, request }) => {
  const email = emailFor('attempt');
  await createMember(request, { email, password, first_name: 'Attempt', role: 'member' });
  await clock(request, email, '2026-06-01 12:00:00');
  const paid = await payApi(request, email, { plan: 'monthly', country: 'US' });
  await clock(request, email, '2026-07-01 12:00:00');
  const res = await notify(request, {
    id: `evt_fail_${stamp()}`, type: 'invoice.payment_failed', data: { object: { id: `in_${stamp()}`, subscription: paid.subscription, amount_due: 799, charge: `ch_${stamp()}` } },
  });
  expect(res.ok()).toBeTruthy();
  const snap = await readMember(request, email);
  const payment = snap.orders.find((order) => order.kind === 'payment');
  const attempt = snap.orders.find((order) => order.kind === 'failed_attempt');
  expect(attempt.status).toBe('failed');
  expect(Number(attempt.related_order_id)).toBe(Number(payment.id));
  expect(Number(attempt.charged_amount)).toBe(799);
  expect(attempt.stripe_payment).toMatch(/^ch_/);
  expect(attempt.ordered_at).toBe('2026-07-01 12:00:00');
  expect(payment.status).toBe('succeeded');
  await signIn(page, email, password);
  await page.goto('/members/subscription/');
  await expect(page.getByText('failed_attempt')).toHaveCount(0);
  await expect(page.getByText('Update your card')).toBeVisible();
});

test('TC-FR2-14-AS-07 a subscription ended at Stripe ends Premium', async ({ request }) => {
  const email = emailFor('ended');
  const subscription = `sub_ended_${stamp()}`;
  await createMember(request, {
    email, password, first_name: 'Ended', role: 'member', access_state: 'Premium', plan: 'monthly', stripe_subscription: subscription, period_ends_on: '2026-07-01',
  });
  await clock(request, email, '2026-07-01 12:00:00');
  const ended = await notify(request, { id: `evt_del_${stamp()}`, type: 'customer.subscription.deleted', data: { object: { id: subscription, status: 'canceled' } } });
  expect(ended.ok()).toBeTruthy();
  const snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('Premium ended');
  expect(snap.subscription.premium_ended_why).toBe('expired');
});

test('TC-FR2-14-AS-03 the first invoice of a subscription adds no second order or receipt', async ({ page, request }) => {
  const email = emailFor('first-invoice');
  await createMember(request, { email, password, first_name: 'First', role: 'member' });
  await clock(request, email, '2026-08-01 12:00:00');
  const paid = await payApi(request, email, { plan: 'monthly', country: 'US' });
  const res = await notify(request, {
    id: `evt_${stamp()}`,
    type: 'invoice.paid',
    data: { object: { id: `in_${stamp()}`, subscription: paid.subscription, amount_paid: paid.charge, payment_intent: paid.payment, billing_reason: 'subscription_create' } },
  });
  expect(res.ok(), await res.text()).toBeTruthy();
  const snap = await readMember(request, email);
  expect(snap.orders).toHaveLength(1);
  expect(snap.orders[0].kind).toBe('payment');
  const messages = await fetch(`${MAILPIT}/api/v1/messages`).then((r) => r.json());
  const receipts = (messages.messages || []).filter((message) => JSON.stringify(message).includes(email) && JSON.stringify(message).includes('Your receipt'));
  expect(receipts).toHaveLength(1);
  await renew(request, paid.subscription, 799);
  expect((await readMember(request, email)).orders).toHaveLength(2);
});

test('TC-FR2-14-AS-05 a subscription update from Stripe sets cancel at period end, resume and the period end', async ({ request }) => {
  const email = emailFor('updated');
  const subscription = `sub_upd_${stamp()}`;
  await createMember(request, {
    email, password, first_name: 'Updated', role: 'member', access_state: 'Premium', plan: 'monthly', stripe_subscription: subscription, period_ends_on: '2026-09-01',
  });
  await clock(request, email, '2026-08-15 12:00:00');
  const periodEnd = Math.floor(Date.parse('2026-09-15T12:00:00Z') / 1000);
  const update = (object) => notify(request, { id: `evt_upd_${stamp()}`, type: 'customer.subscription.updated', data: { object: { id: subscription, ...object } } });
  expect((await update({ status: 'active', cancel_at_period_end: true, current_period_end: periodEnd })).ok()).toBeTruthy();
  let snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('cancelled');
  expect(snap.subscription.stripe_state).toBe('cancel_at_period_end');
  expect(snap.subscription.period_ends_on).toBe('2026-09-15');
  expect((await update({ status: 'active', cancel_at_period_end: false })).ok()).toBeTruthy();
  snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('Premium');
  expect(snap.subscription.stripe_state).toBe('active');
  expect((await update({ status: 'past_due', cancel_at_period_end: false })).ok()).toBeTruthy();
  snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('Premium');
  expect(snap.subscription.stripe_state).toBe('past_due');
  expect((await update({ status: 'unpaid', cancel_at_period_end: false })).ok()).toBeTruthy();
  snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('Premium ended');
  expect((await update({ status: 'active', cancel_at_period_end: false })).ok()).toBeTruthy();
  expect((await readMember(request, email)).subscription.access_state).toBe('Premium ended');
});

test('TC-FR2-14-AS-06 a refund made at Stripe is recorded once and ends Premium', async ({ request }) => {
  const email = emailFor('stripe-refund');
  await createMember(request, { email, password, first_name: 'Charge', role: 'member' });
  await clock(request, email, '2026-10-20 12:00:00');
  const paid = await payApi(request, email, { plan: 'yearly', country: 'US' });
  const partial = await notify(request, {
    id: `evt_part_${stamp()}`, type: 'charge.refunded', data: { object: { id: `ch_${stamp()}`, payment_intent: paid.payment, refunded: false, amount_refunded: 100 } },
  });
  expect(partial.ok()).toBeTruthy();
  expect((await readMember(request, email)).subscription.access_state).toBe('Premium');
  const charge = { id: `ch_${stamp()}`, payment_intent: paid.payment, refunded: true, amount_refunded: paid.charge, refunds: { data: [{ id: `re_${stamp()}` }] } };
  expect((await notify(request, { id: `evt_ref_${stamp()}`, type: 'charge.refunded', data: { object: charge } })).ok()).toBeTruthy();
  expect((await notify(request, { id: `evt_ref_${stamp()}`, type: 'charge.refunded', data: { object: charge } })).ok()).toBeTruthy();
  const snap = await readMember(request, email);
  expect(snap.subscription.access_state).toBe('Refunded');
  expect(snap.orders.filter((order) => order.kind === 'refund')).toHaveLength(1);
  expect(snap.orders.find((order) => order.kind === 'payment').status).toBe('refunded');
  expect(snap.orders.find((order) => order.kind === 'refund').status).toBe('succeeded');
  expect(Number(snap.orders.find((order) => order.kind === 'refund').related_order_id)).toBe(Number(snap.orders.find((order) => order.kind === 'payment').id));
});

test('TC-FR2-14-AS-01 a member who already has Premium cannot start a second checkout', async ({ page, request }) => {
  const email = emailFor('twice-pay');
  await createMember(request, { email, password, first_name: 'Twice', role: 'member' });
  await clock(request, email, '2026-10-01 12:00:00');
  const paid = await pay(page, email, { plan: 'monthly', country: 'US' });
  await page.goto('/members/checkout/?plan=yearly');
  await expect(page.getByText('You already have Premium.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Show the price' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Continue to payment' })).toHaveCount(0);
  const api = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'session', email, plan: 'yearly', country: 'US' } });
  expect(api.status()).toBe(409);

  const cancelled = emailFor('twice-cancelled');
  await createMember(request, { email: cancelled, password, first_name: 'Still', role: 'member', access_state: 'cancelled', plan: 'monthly', period_ends_on: '2026-12-01' });
  await clock(request, cancelled, '2026-10-01 12:00:00');
  await signIn(page, cancelled, password);
  await page.goto('/members/checkout/');
  await expect(page.getByText('You already have Premium.')).toBeVisible();

  // A second payment that still reaches us keeps the first subscription and is flagged to the owner.
  const free = emailFor('twice-late');
  await createMember(request, { email: free, password, first_name: 'Late', role: 'member' });
  await clock(request, free, '2026-10-01 12:00:00');
  const quote = await billingAction(request, { action: 'session', email: free, plan: 'monthly', country: 'US' });
  const first = await payApi(request, free, { plan: 'monthly', country: 'US' });
  const res = await notify(request, {
    id: `evt_${stamp()}`,
    type: 'checkout.session.completed',
    data: { object: { id: quote.session, amount_total: Number(quote.charged), subscription: `sub_${stamp()}`, payment_intent: `pi_${stamp()}`, email: free } },
  });
  expect(res.ok()).toBeTruthy();
  const snap = await readMember(request, free);
  expect(snap.subscription.stripe_subscription).toBe(first.subscription);
  expect(snap.orders).toHaveLength(2);
  expect(paid.subscription).toBeTruthy();
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
    await signIn(page, email, password);
    await startTrack(page, fixture.track);
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
      await confirmRefund(page, orderId);
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
  const rewarded = (await readMember(request, monthly)).orders.find((order) => order.kind === 'renewal');
  expect(rewarded.discount_code).toMatch(/^referral-reward-\d+$/);
  expect(Number(rewarded.discount_amount)).toBe(799);
  expect(rewarded.discount_currency).toBe('usd');
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
  // Data Model P5 names: waiting, qualified, or not qualified with the reason.
  expect(snap.referrals.filter((row) => row.status === 'qualified')).toHaveLength(3);
  expect(snap.referrals.filter((row) => row.status === 'not_qualified' && row.not_qualified_why === 'limit')).toHaveLength(1);

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
  await confirmRefund(page, orderId);
  await clock(request, back, '2026-04-20 12:00:00');
  await reports(request);
  const giverSnap = await readMember(request, giver);
  const giverId = Number(giverSnap.subscription.member_id);
  expect((await moneyLog(request)).some((row) => row.action === 'reward_withdrawn' && Number(row.member_number) === giverId)).toBeTruthy();
  expect(giverSnap.referrals.some((row) => row.status === 'not_qualified' && row.not_qualified_why === 'refunded')).toBeTruthy();
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
  expect(snap.referrals.filter((row) => row.status === 'not_qualified' && row.not_qualified_why === 'self')).toHaveLength(2);
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
