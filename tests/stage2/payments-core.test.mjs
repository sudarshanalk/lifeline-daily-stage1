import { createHmac, randomBytes } from 'node:crypto';
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
