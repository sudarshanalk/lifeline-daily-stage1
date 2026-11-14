import { expect } from '@playwright/test';
import { createHmac, randomBytes } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { inflateRawSync } from 'node:zlib';
import { password, emailFor, signIn, createMember } from './helpers.mjs';
import { asOwner, submitAndWait } from '../../stage2/e2e/helpers.mjs';
import { fresh, api, DAY, A } from './p36-fixtures.mjs';
export { password, emailFor, signIn, createMember, asOwner, submitAndWait };

// The newest caught email to this address that holds `contains`. `after` (a time in ms, taken just before the step
// that sends it) leaves out an older email of the same kind, so a journey never reads the previous one's link or code.
export async function mail(email, contains = '', after = 0) {
  const host = process.env.MAILPIT_URL || 'http://127.0.0.1:8025'; let body;
  await expect.poll(async () => {
    const list = await fetch(`${host}/api/v1/messages?limit=1000`).then(r => r.json());
    const messages = (list.messages || []).filter(m => JSON.stringify(m.To || []).includes(email) && new Date(m.Created).getTime() >= after - 2000)
      .sort((a, b) => new Date(b.Created) - new Date(a.Created));
    for (const m of messages) {
      const candidate = await fetch(`${host}/api/v1/message/${m.ID || m.Id || m.id}`).then(r => r.json());
      if (`${candidate.Subject}\n${candidate.Text || candidate.HTML}`.includes(contains)) { body = candidate; return true; }
    }
    return false;
  }, { timeout: 20000 }).toBe(true);
  return body;
}
export async function register(page) {
  const email = emailFor('p36-join');
  await page.goto('/members/join/');
  for (const [selector, value] of [['#lld_first', 'Synthetic'], ['#lld_last', 'Member'], ['#lld_email', email], ['#lld_password', password], ['#lld_age', '30']]) await page.locator(selector).fill(value);
  await submitAndWait(page, page.getByRole('button', { name: 'Create account', exact: true }));
  await expect(page.getByRole('heading', { name: 'Check your email', exact: true })).toBeVisible();
  return email;
}
export async function confirm(page, email) {
  const body = await mail(email, 'Confirm');
  const link = String(body.Text || body.HTML).match(/https?:\/\/[^\s<]+\/members\/confirm\/\?token=[^\s<]+/)?.[0];
  expect(link).toBeTruthy(); await page.goto(link.replace(/[).,>]+$/, ''));
  await expect(page.getByRole('heading', { name: 'Email confirmed', exact: true })).toBeVisible();
}
export async function accept(page) {
  await page.goto('/members/agreements/');
  await page.locator('input[name="lld_terms"]').check();
  await page.locator('input[name="lld_health"]').check();
  await submitAndWait(page, page.getByRole('button', { name: 'Continue', exact: true }));
  expect(new URL(page.url()).pathname).not.toBe('/members/agreements/');
}
export async function logout(page) {
  await page.request.post('/wp-json/lld-members/v1/test/logout'); await page.context().clearCookies();
}
export async function activityGoal(page) {
  return api(page, 'goals', 'POST', { role: 'main', goal_type: 'activity', measure: 'activity_minutes', unit: 'minutes', start_value: 0, target_value: 150, status: 'active' });
}
export async function weightGoal(page, options = {}) {
  const m = await fresh(page, options);
  await page.goto('/members/goal/?type=lose_weight');
  await page.locator('#lld_current').fill('90'); await page.locator('#lld_target').fill('84');
  await page.locator('input[name="lld_rate"][value="0.5"]').check();
  await submitAndWait(page, page.getByRole('button', { name: 'Save my goal', exact: true }));
  await expect(page.locator('[data-lld-goal-step="plan"]')).toBeVisible(); return m;
}
export async function resetPassword(page, email) {
  await logout(page); await page.goto('/members/reset/'); await page.locator('#lld_email').fill(email);
  const sent = Date.now();
  await submitAndWait(page, page.getByRole('button', { name: 'Send link', exact: true }));
  const body = await mail(email, 'password', sent);
  const link = String(body.Text || body.HTML).match(/https?:\/\/[^\s<]+\/members\/reset\/\?token=[^\s<]+/)?.[0];
  expect(link).toBeTruthy(); await page.goto(link.replace(/[).,>]+$/, ''));
  const next = `${password}-changed`; await page.locator('#lld_password').fill(next);
  await submitAndWait(page, page.getByRole('button', { name: 'Save password', exact: true }));
  await expect(page.getByRole('heading', { name: 'Password changed', exact: true })).toBeVisible(); return next;
}
export async function grant(page, member, days = 30) {
  await asOwner(page); await page.goto(`/members/admin/members/?member=${member.member_id}`);
  const summary = page.locator('details.lld-adm-action > summary').filter({ hasText: /^Give free Premium$/ });
  await summary.click(); const box = summary.locator('xpath=..');
  const until = new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
  await box.locator('#lld-grant-end').fill(until); await box.locator('#lld-grant-reason').fill('Synthetic journey grant');
  await submitAndWait(page, box.getByRole('button', { name: 'Give free Premium', exact: true }));
  await expect(page.locator('[data-lld-adm-notice]')).toContainText('Free Premium given'); return until;
}
export function unzip(buffer) {
  const entries = {};
  for (let at = 0; at + 46 <= buffer.length; at += 1) {
    if (buffer.readUInt32LE(at) !== 0x02014b50) continue;
    const method = buffer.readUInt16LE(at + 10), size = buffer.readUInt32LE(at + 20);
    const n = buffer.readUInt16LE(at + 28), extra = buffer.readUInt16LE(at + 30), comment = buffer.readUInt16LE(at + 32);
    const name = buffer.subarray(at + 46, at + 46 + n).toString(); const offset = buffer.readUInt32LE(at + 42);
    const start = offset + 30 + buffer.readUInt16LE(offset + 26) + buffer.readUInt16LE(offset + 28);
    const packed = buffer.subarray(start, start + size);
    entries[name] = method === 8 ? inflateRawSync(packed) : packed; at += 45 + n + extra + comment;
  }
  return entries;
}
export async function downloadData(page) {
  await page.goto('/members/data/'); const pending = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download all my data', exact: true }).click();
  const download = await pending; const entries = unzip(readFileSync(await download.path()));
  expect(entries['data.json']).toBeTruthy(); return JSON.parse(entries['data.json'].toString());
}
export async function deleteAccount(page) {
  await page.goto('/members/delete/'); await page.locator('#lld_password').fill(password);
  await submitAndWait(page, page.getByRole('button', { name: 'Delete my account', exact: true }));
  await expect(page.locator('main')).toContainText('Your account is closed.');
}
function secret() {
  if (process.env.LLD_TEST_SECRET) return process.env.LLD_TEST_SECRET;
  const file = process.env.RUNNER_TEMP && `${process.env.RUNNER_TEMP}/lld-test-secret`;
  return file && existsSync(file) ? readFileSync(file, 'utf8').trim() : '';
}
export async function notice(request, event) {
  const body = JSON.stringify(event), t = Math.floor(Date.now() / 1000);
  const signature = createHmac('sha256', secret()).update(`${t}.${body}`).digest('hex');
  const response = await request.post('/wp-json/lld-members/v1/stripe/notice', { data: body,
    headers: { 'Stripe-Signature': `t=${t},v1=${signature}`, 'Content-Type': 'application/json' } });
  expect(response.ok(), await response.text()).toBeTruthy();
}
export async function pay(page) {
  await page.goto('/members/checkout/?plan=monthly'); await page.locator('#lld_country').selectOption('US');
  await page.locator('#lld_sale_terms').check(); await submitAndWait(page, page.getByRole('button', { name: 'Show the price', exact: true }));
  const box = page.locator('[data-lld-charge]'); await expect(box).toBeVisible();
  const session = await box.getAttribute('data-lld-session'), amount = Number(await box.getAttribute('data-lld-charge'));
  const stamp = randomBytes(4).toString('hex'), subscription = `sub_p36_${stamp}`;
  await notice(page.request, { id: `evt_p36_${stamp}`, type: 'checkout.session.completed',
    data: { object: { id: session, amount_total: amount, subscription, payment_intent: `pi_p36_${stamp}`, email: A } } });
  return { subscription, amount };
}
