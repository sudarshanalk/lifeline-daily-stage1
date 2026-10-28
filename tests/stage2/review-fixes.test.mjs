import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const plugin = resolve(dirname(fileURLToPath(import.meta.url)), '../../site/public_html/wp-content/plugins/lld-members');
const read = (name) => readFileSync(resolve(plugin, name), 'utf8');

const auth = read('includes/class-auth.php');
const schema = read('includes/class-schema.php');
const integrity = read('includes/class-integrity.php');
const rest = read('includes/class-rest.php');
const erasure = read('includes/class-erasure.php');
const stripe = read('includes/class-stripe.php');
const js = read('assets/lld-members.js');

test('administrators need an email code and cannot use application passwords', () => {
  expect(auth).toMatch(/user_can\(\s*\$user,\s*'manage_options'\s*\)/);
  expect(auth).toMatch(/function application_passwords/);
});

test('lockout counts every email and covers WordPress sign-in', () => {
  expect(auth).toMatch(/function enforce_lock/);
  expect(auth).toMatch(/DUMMY_HASH/);
  expect(auth).toMatch(/record_failure\(\s*\$email\s*\)/);
  expect(auth).not.toMatch(/if\s*\(\s*\$user\s*\)\s*\{\s*self::record_failure/);
});

test('codes are single-use and resends are capped', () => {
  expect(auth).toMatch(/RESEND_LIMIT/);
  expect(auth).toMatch(/used_at IS NULL/);
  expect(auth).toMatch(/resend_count \+ 1/);
});

test('schema upgrades add the T1 guards', () => {
  expect(schema).toMatch(/UNIQUE KEY one_week \(enrolment_id, week_number\)/);
  expect(schema).toMatch(/UNIQUE KEY one_month \(member_id, month_key\)/);
  expect(schema).toMatch(/sort_order BETWEEN 1 AND 3/);
  expect(schema).toMatch(/Consent lines cannot be edited/);
  expect(schema).toMatch(/Active periods cannot overlap/);
  expect(schema).toMatch(/UNIQUE KEY friend_member_id/);
  expect(schema).toMatch(/A referral has exactly two rewards/);
  expect(schema).toMatch(/UNIQUE KEY stripe_event \(stripe_payment, kind\)/);
  expect(schema).not.toMatch(/UNIQUE KEY stripe_payment \(stripe_payment\)/);
});

test('integrity rules write through the database constraint', () => {
  expect(integrity).toMatch(/function insert_active_enrolment/);
  expect(integrity).toMatch(/function insert_active_workout/);
  expect(integrity).toMatch(/function create_referral_rewards/);
  expect(integrity).toMatch(/START TRANSACTION/);
  expect(integrity).not.toMatch(/SELECT COUNT\(\*\)/);
});

test('test routes require the ci or local secret', () => {
  expect(rest).toMatch(/lld_members_test_routes_open/);
  expect(rest).toMatch(/lld_members_test_request_allowed/);
});

test('erasure mail is marked only after send, and restore can import a file', () => {
  expect(erasure).toMatch(/if\s*\(\s*!\s*\$sent\s*\)/);
  expect(erasure).toMatch(/function import_file/);
  expect(erasure).toMatch(/function verify_stored/);
});

test('the Stripe outage option is ignored in production', () => {
  const body = stripe.slice(stripe.indexOf('function outage_forced'));
  expect(body.indexOf('lld_members_is_production')).toBeGreaterThan(-1);
  expect(body.indexOf('lld_members_is_production')).toBeLessThan(body.indexOf('lld_members_stripe_unreachable'));
});

test('the script has no credential-shaped password key', () => {
  expect(js).not.toMatch(/\bpassword\s*:/);
  expect(js).not.toMatch(/Show password/);
});
