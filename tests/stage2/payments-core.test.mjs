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

test('orders and referrals use the Data Model status names, and the database update renames older rows', () => {
  const billing = source('class-billing.php');
  expect(billing).not.toMatch(/'paid' ===|status = 'paid'"|=> 'paid'/);
  expect(billing).not.toMatch(/'status' => '(rewarded|limited|self|refunded)'/);
  expect(billing).toMatch(/SET status = 'succeeded' WHERE status = 'paid'/);
  expect(billing).toMatch(/SET status = 'qualified' WHERE status = 'rewarded'/);
  expect(billing).toMatch(/'not_qualified_' \. \$why/);
});

test('TC-FR2-13-AS-04 a code is checked against its plans and its valid-from and valid-to dates (P4)', () => {
  const billing = source('class-billing.php');
  const applies = billing.slice(billing.indexOf('function code_applies'), billing.indexOf('function find_code'));
  expect(applies).toMatch(/in_array\( \$plan, \$row\['plans'\], true \)/);
  expect(applies).toMatch(/valid_to/);
  expect(applies).toMatch(/valid_from/);
  expect(billing).not.toMatch(/'monthly' === \$plan && '' !== \$code/);
  for (const field of ['plans', 'valid_from', 'valid_to', 'times_used']) expect(billing).toContain(`'${field}'`);
});

test('TC-FR2-04-AS-03 erasing a member removes their card fingerprints, referral code, quotes, mismatch notes and retry windows', () => {
  const options = {
    lld_card_fp: [{ member_id: 7, fingerprint: 'fp_a' }, { member_id: 8, fingerprint: 'fp_b' }, { member_id: 7, fingerprint: 'fp_c' }],
    lld_ref_codes: { 7: 'r7', 8: 'r8' },
    lld_quotes: { cs_a: { member_id: 7, quote: {}, ref: '' }, cs_b: { member_id: 8, quote: {}, ref: '' } },
    lld_mismatches: [{ member_id: 7, flagged_on: '2026-01-01' }],
    lld_retries: { in_a: { member_id: 7, from: '2026-01-01' }, in_b: { member_id: 8, from: '2026-01-02' } },
  };
  const prelude = [
    `$GLOBALS['opts'] = json_decode( ${JSON.stringify(JSON.stringify(options))}, true );`,
    "function get_option( $name ) { return isset( $GLOBALS['opts'][ $name ] ) ? $GLOBALS['opts'][ $name ] : false; }",
    "function update_option( $name, $value ) { $GLOBALS['opts'][ $name ] = $value; return true; }",
  ].join(' ');
  const after = billing('erase_member', [7], { LLD_ENV: 'ci' }, { prelude, result: "$GLOBALS['opts']" });
  expect(after.lld_card_fp).toEqual([{ member_id: 8, fingerprint: 'fp_b' }]);
  expect(after.lld_ref_codes).toEqual({ 8: 'r8' });
  expect(Object.keys(after.lld_quotes)).toEqual(['cs_b']);
  expect(after.lld_mismatches).toEqual([]);
  expect(Object.keys(after.lld_retries)).toEqual(['in_b']);
  expect(readFileSync(resolve(plugin, 'class-erasure.php'), 'utf8')).toMatch(/do_action\( 'lld_members_erase_account', \$member_id \);/);
  expect(source('class-billing.php')).toMatch(/add_action\( 'lld_members_erase_account', array\( __CLASS__, 'erase_member' \) \)/);
});

test('payments add no column outside the Data Model: notices keep only the P6 fields, never the notice itself', () => {
  const frozen = JSON.parse(readFileSync(resolve(plugin, '../../../../../../tests/stage2/personal-columns.json'), 'utf8'));
  const schema = source('class-schema.php');
  for (const [, table, column] of schema.matchAll(/ensure_column\( '([a-z_]+)', '([a-z_]+)'/g)) {
    if (frozen[table]) expect(frozen[table], `${table}.${column}`).toContain(column);
  }
  const billing = source('class-billing.php');
  const notice = billing.slice(billing.indexOf('function rest_notice'));
  const insert = notice.slice(notice.indexOf('$wpdb->insert('), notice.indexOf('suppress_errors( $quiet )'));
  const stored = [...insert.matchAll(/'([a-z_]+)'\s*=>/g)].map((m) => m[1]);
  expect(stored.length).toBeGreaterThan(0);
  expect(stored.every((field) => frozen.stripe_notice.includes(field))).toBeTruthy();
  expect(billing).not.toMatch(/'payload'|next_attempt_at|not_qualified_why/);
});

test('TC-FR2-31-AS-02 checkout records the terms of sale as consent type sale, the type the account screens read', () => {
  const billing = source('class-billing.php');
  expect(billing).toMatch(/const SALE_CONSENT = 'sale';/);
  const checkout = billing.slice(billing.indexOf('public static function checkout'), billing.indexOf('public static function subscription'));
  expect(checkout).toMatch(/empty\( \$_POST\['lld_sale_terms'\] \)/);
  expect(checkout.indexOf('accept_terms_of_sale')).toBeLessThan(checkout.indexOf('store_quote'));
  expect(checkout).toMatch(/name="lld_sale_terms"/);
  expect(billing.slice(billing.indexOf('public static function pricing'), billing.indexOf('public static function sales'))).toMatch(/terms_of_sale_url\(\)/);
});
