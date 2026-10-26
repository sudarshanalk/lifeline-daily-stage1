import { createHmac, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { billing } from './tools/billing-php.mjs';

// Values are made per run so no credential-looking literal is tracked (security scan).
const SECRET = `whsec_${randomBytes(12).toString('hex')}`;
const SHARED = randomBytes(12).toString('hex');
const key = (prefix) => `${prefix}_${randomBytes(6).toString('hex')}`;
const SK_LIVE = key('sk_live');
const PK_LIVE = key('pk_live');
const SK_TEST = key('sk_test');
const body = JSON.stringify({ id: 'evt_1', type: 'invoice.paid', data: { object: {} } });
const sign = (t, payload = body, secret = SECRET) => `t=${t},v1=${createHmac('sha256', secret).update(`${t}.${payload}`).digest('hex')}`;
const now = () => Math.floor(Date.now() / 1000);

test('the webhook secret is required on staging and production, with no test-secret fallback', () => {
  for (const env of ['staging', 'production']) {
    expect(billing('webhook_secret', [], { LLD_ENV: env, LLD_TEST_SECRET: SHARED })).toBe('');
    expect(billing('webhook_secret', [], { LLD_ENV: env, LLD_TEST_SECRET: SHARED, LLD_STRIPE_WEBHOOK: SECRET })).toBe(SECRET);
  }
  for (const env of ['ci', 'local']) {
    expect(billing('webhook_secret', [], { LLD_ENV: env, LLD_TEST_SECRET: SHARED })).toBe(SHARED);
  }
});

test('a staging notice signed with the test secret is rejected', () => {
  const header = sign(now(), body, SHARED);
  expect(billing('valid_signature', [body, header], { LLD_ENV: 'staging', LLD_TEST_SECRET: SHARED })).toBe(false);
  expect(billing('valid_signature', [body, header], { LLD_ENV: 'ci', LLD_TEST_SECRET: SHARED })).toBe(true);
});

test('TC-FR2-14-AS-07 a tampered signature and a stale timestamp are rejected', () => {
  const env = { LLD_ENV: 'staging', LLD_STRIPE_WEBHOOK: SECRET };
  const t = now();
  expect(billing('valid_signature', [body, sign(t)], env)).toBe(true);
  const good = sign(t);
  const last = good.slice(-1);
  const tampered = good.slice(0, -1) + (last === '0' ? '1' : '0');
  expect(billing('valid_signature', [body, tampered], env)).toBe(false);
  expect(billing('valid_signature', [body.replace('invoice.paid', 'invoice.payment_failed'), good], env)).toBe(false);
  expect(billing('valid_signature', [body, sign(t - 600)], env)).toBe(false);
  expect(billing('valid_signature', [body, sign(t + 600)], env)).toBe(false);
  expect(billing('valid_signature', [body, ''], env)).toBe(false);
});

test('live Stripe keys are refused unless LLD_ENV is production', () => {
  for (const env of ['ci', 'local', 'staging']) {
    expect(billing('stripe_key', ['secret'], { LLD_ENV: env, LLD_STRIPE_SECRET: SK_LIVE })).toEqual({ error: 'lld_stripe_live' });
    expect(billing('stripe_key', ['publishable'], { LLD_ENV: env, LLD_STRIPE_PUBLISHABLE: PK_LIVE })).toEqual({ error: 'lld_stripe_live' });
    expect(billing('live_keys_refused', [], { LLD_ENV: env, LLD_STRIPE_PUBLISHABLE: PK_LIVE })).toBe(true);
    expect(billing('stripe_key', ['secret'], { LLD_ENV: env, LLD_STRIPE_SECRET: SK_TEST })).toBe(SK_TEST);
    expect(billing('live_keys_refused', [], { LLD_ENV: env, LLD_STRIPE_SECRET: SK_TEST })).toBe(false);
  }
  expect(billing('live_keys_refused', [], { LLD_STRIPE_SECRET: SK_LIVE })).toBe(true);
  expect(billing('stripe_key', ['secret'], { LLD_ENV: 'production', LLD_STRIPE_SECRET: SK_LIVE })).toBe(SK_LIVE);
  expect(billing('live_keys_refused', [], { LLD_ENV: 'production', LLD_STRIPE_SECRET: SK_LIVE, LLD_STRIPE_PUBLISHABLE: PK_LIVE })).toBe(false);
});

const plugin = resolve(dirname(fileURLToPath(import.meta.url)), '../../site/public_html/wp-content/plugins/lld-members/includes');
const source = (name) => readFileSync(resolve(plugin, name), 'utf8');

test('TC-FR2-13-AS-04 the database update creates the Paid Memberships Pro levels without calling Stripe', () => {
  const schema = source('class-schema.php');
  expect(Number(schema.match(/const VERSION = (\d+);/)[1])).toBeGreaterThanOrEqual(3);
  expect(schema).toMatch(/LLD_Members_Billing::db_update\(\)/);
  const billing = source('class-billing.php');
  const sync = billing.slice(billing.indexOf('function sync_pmpro_levels'), billing.indexOf('function has_premium'));
  expect(sync).toContain('pmpro_membership_levels');
  expect(sync).toMatch(/'allow_signups'\s*=>\s*0/);
  expect(sync).not.toMatch(/api\.stripe\.com|wp_remote_/);
  expect(billing).toMatch(/'name'\s*=>\s*'Premium yearly'[\s\S]*'period'\s*=>\s*'Year'/);
  expect(billing).toMatch(/'name'\s*=>\s*'Premium monthly'[\s\S]*'period'\s*=>\s*'Month'/);
});
