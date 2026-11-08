// D2 / P3-60: the real Stripe connection behind the `stripe_api` switch. Run in PHP CLI with a fake transport, so no
// request leaves the machine and no key is needed: the tests read exactly what would be sent to Stripe.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const src = (name) => readFileSync(resolve(plugin, name), 'utf8');
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim();
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
// Fake keys, built from parts so the secret scanner does not mistake them for real ones.
const FAKE_KEY = ['sk', 'test', 'FAKEKEY123'].join('_');
const LIVE_KEY = ['sk', 'live', 'NOTALLOWED'].join('_');

// Fake WordPress: options, transients, a recorded HTTP transport answering from $GLOBALS['answers'], and a tiny wpdb.
const WEBHOOK = ['whsec', 'FAKEWEBHOOK123'].join('_');
// webhook: the signing secret; on() needs it as well as the key (a half-configured site stays off).
const stubs = (key = FAKE_KEY, webhook = WEBHOOK) => `
define('ABSPATH', '/'); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
${key ? `define('LLD_STRIPE_SECRET', '${key}');` : ''}
${webhook ? `define('LLD_STRIPE_WEBHOOK', '${webhook}');` : ''}
class WP_Error { public $code; public $message; public $data; function __construct($c = '', $m = '', $d = '') { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
$GLOBALS['opts'] = []; $GLOBALS['sent'] = []; $GLOBALS['answers'] = [];
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['opts']['_t_' . $k] ?? false; }
function set_transient($k, $v) { $GLOBALS['opts']['_t_' . $k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['opts']['_t_' . $k]); return true; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return trim((string) $s); }
function home_url($p = '') { return 'https://stg.example.test' . $p; }
function wp_generate_uuid4() { return 'uuid-1'; }
function add_action() {} function add_filter() {}
function lld_members_is_production() { return false; }
function wp_remote_request($url, $args) {
  $GLOBALS['sent'][] = ['url' => $url, 'args' => $args];
  foreach ($GLOBALS['answers'] as $match => $answer) { if (strpos($args['method'] . ' ' . $url, $match) !== false) { return $answer; } }
  return ['code' => 404, 'body' => json_encode(['error' => ['message' => 'No such thing', 'code' => 'resource_missing']])];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function ok($o) { return ['code' => 200, 'body' => json_encode($o)]; }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class FakeDb { public $users = 'wp_users'; public $customer = ''; function prepare($q, ...$a) { return $q; } function get_var($q) { return strpos($q, 'stripe_customer') !== false ? $this->customer : 'member@example.test'; } }
$GLOBALS['wpdb'] = new FakeDb();
class LLD_Members_Features { static $on = true; static function enabled($f, $m = 0) { return $f === 'stripe_api' && self::$on; } static function state($f) { return self::$on ? 'test_accounts' : 'off'; } }
class LLD_Members_Billing { static $sub = null; static $next = []; static function stripe_key($k) { $v = defined('LLD_STRIPE_SECRET') ? LLD_STRIPE_SECRET : ''; return (strpos($v, 'sk_live_') === 0) ? new WP_Error('lld_stripe_live', 'A live Stripe key is set outside production.') : $v; } static function subscription_for($m) { return self::$sub; } static function next_charge_for($m) { return self::$next; } }
require ${toPhpPath(resolve(plugin, 'includes/stage3/class-stripe-api.php'))};
$GLOBALS['answers']['GET https://api.stripe.com/v1/prices?lookup_keys%5B0%5D=lld_premium_monthly'] = ok(['data' => [['id' => 'price_M', 'unit_amount' => 999, 'currency' => 'usd', 'tax_behavior' => 'inclusive']]]);
$GLOBALS['answers']['GET https://api.stripe.com/v1/prices?lookup_keys%5B0%5D=lld_premium_yearly'] = ok(['data' => [['id' => 'price_Y', 'unit_amount' => 6999, 'currency' => 'usd', 'tax_behavior' => 'inclusive']]]);
$quote = ['plan' => 'monthly', 'country' => 'GB', 'currency' => 'usd', 'base' => 999, 'charged' => 999, 'tax' => 167, 'code' => ''];
`;

test('TC-FR3-62-AS-02 (session): the price and currency shown are Stripe\'s own, and the session charges exactly that', () => {
  const out = JSON.parse(php(`${stubs()}
    $r = [];
    $r['price_gb'] = LLD_Members_Stripe_API::plan_price(799, 'monthly', 7);          // Stage 2 price replaced by Stripe's
    $r['currency_gb'] = LLD_Members_Stripe_API::plan_currency('gbp', 'monthly', 7);   // the same currency in every country
    $r['price_y'] = LLD_Members_Stripe_API::plan_price(5999, 'yearly', 7);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/checkout/sessions'] = ok(['id' => 'cs_test_real', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_real']);
    $r['session'] = LLD_Members_Stripe_API::checkout_session(null, $quote, 7, '');
    $post = end($GLOBALS['sent']); parse_str($post['args']['body'], $form);
    $r['form'] = $form;
    $r['idem'] = $post['args']['headers']['Idempotency-Key'] ?? '';
    $r['version'] = $post['args']['headers']['Stripe-Version'];
    $wrong = $quote; $wrong['base'] = 799;                                              // a quote Stripe would not charge
    $r['mismatch'] = LLD_Members_Stripe_API::checkout_session(null, $wrong, 7, '')->message;
    echo json_encode($r);
  `));
  expect(out.price_gb).toBe(999);
  expect(out.currency_gb).toBe('usd');
  expect(out.price_y).toBe(6999);
  expect(out.session).toEqual({ id: 'cs_test_real', url: 'https://checkout.stripe.com/c/pay/cs_test_real' });
  expect(out.form.mode).toBe('subscription');
  expect(out.form.line_items[0]).toEqual({ price: 'price_M', quantity: '1' });
  expect(out.form.managed_payments.enabled).toBe('true');     // Stripe is the merchant of record
  expect(out.form.adaptive_pricing).toBeUndefined();            // unsupported with Managed Payments: never sent
  expect(out.form.client_reference_id).toBe('7');
  expect(out.form.metadata).toEqual({ member_id: '7', plan: 'monthly' });
  expect(out.form.customer_email).toBe('member@example.test');
  expect(out.form.success_url).toBe('https://stg.example.test/members/checkout/?result=back');
  expect(out.form.cancel_url).toBe('https://stg.example.test/members/checkout/?result=cancel');
  expect(out.idem).toMatch(/^lld-checkout-7-/);
  expect(out.version).toBe('2026-10-28.endive');
  expect(out.mismatch).toBe('The price changed. Please reload the page.');
});

test('TC-FR3-62-AS-03 (request): each plan opens a session for its own Stripe price; a discount code becomes a once-only coupon', () => {
  const out = JSON.parse(php(`${stubs()}
    $GLOBALS['answers']['POST https://api.stripe.com/v1/checkout/sessions'] = ok(['id' => 'cs_test_y', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_y']);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/coupons'] = ok(['id' => 'lld_launch50_499usd']);
    $yearly = ['plan' => 'yearly', 'country' => 'US', 'currency' => 'usd', 'base' => 6999, 'charged' => 6999, 'tax' => 0, 'code' => ''];
    LLD_Members_Stripe_API::checkout_session(null, $yearly, 7, '');
    $post = end($GLOBALS['sent']); parse_str($post['args']['body'], $y);
    $coded = $quote; $coded['code'] = 'LAUNCH50'; $coded['charged'] = 500;
    LLD_Members_Stripe_API::checkout_session(null, $coded, 7, '');
    $calls = array_map(function ($s) { return $s['args']['method'] . ' ' . strtok($s['url'], '?'); }, $GLOBALS['sent']);
    $coupon = null; foreach ($GLOBALS['sent'] as $s) { if (strpos($s['url'], '/v1/coupons') !== false && $s['args']['method'] === 'POST') { parse_str($s['args']['body'], $coupon); } }
    $post = end($GLOBALS['sent']); parse_str($post['args']['body'], $m);
    echo json_encode(['yearly_price' => $y['line_items'][0]['price'], 'coupon' => $coupon, 'discount' => $m['discounts'][0] ?? null, 'calls' => $calls]);
  `));
  expect(out.yearly_price).toBe('price_Y');
  expect(out.coupon).toEqual({ id: 'lld_launch50_499usd', name: 'LAUNCH50', amount_off: '499', currency: 'usd', duration: 'once' }); // exact cents, never a percentage
  expect(out.discount).toEqual({ coupon: 'lld_launch50_499usd' });
  expect(out.calls).toContain('GET https://api.stripe.com/v1/coupons/lld_launch50_499usd');
});

test('TC-NFR3-06-AS-01 (stripe): with the switch off or no key, nothing goes to Stripe and the Stage 2 values stay', () => {
  const off = JSON.parse(php(`${stubs()}
    LLD_Members_Features::$on = false;
    $r = [
      'price' => LLD_Members_Stripe_API::plan_price(799, 'monthly', 7),
      'currency' => LLD_Members_Stripe_API::plan_currency('gbp', 'monthly', 7),
      'session' => LLD_Members_Stripe_API::checkout_session(null, $quote, 7, ''),
      'cancel' => LLD_Members_Stripe_API::cancel_at_period_end(true, 7, (object) ['stripe_subscription' => 'sub_1']),
      'delete' => LLD_Members_Stripe_API::cancel_member(null, 7, 'sub_1'),
      'refund' => LLD_Members_Stripe_API::refund('re_test_5', (object) ['id' => 5, 'member_id' => 7, 'stripe_payment' => 'pi_1']),
      'switch' => LLD_Members_Stripe_API::can_switch_yearly(true, 7),
      'sent' => count($GLOBALS['sent']),
    ];
    echo json_encode($r);
  `));
  expect(off).toEqual({ price: 799, currency: 'gbp', session: null, cancel: true, delete: null, refund: 're_test_5', switch: true, sent: 0 });
  const nokey = JSON.parse(php(`${stubs('')}
    echo json_encode(['on' => LLD_Members_Stripe_API::on(7), 'mode' => LLD_Members_Stripe_API::mode(), 'price' => LLD_Members_Stripe_API::plan_price(799, 'monthly', 7), 'sent' => count($GLOBALS['sent'])]);
  `));
  expect(nokey).toEqual({ on: false, mode: 'none', price: 799, sent: 0 });
});

test('Stripe stays off without the webhook signing secret: no session is opened and nothing is sent', () => {
  const out = JSON.parse(php(`${stubs(FAKE_KEY, '')}
    update_option('lld_feature_switches', ['stripe_api' => ['state' => 'everyone', 'approved_by' => 'test', 'changed_at' => '']]);
    $quote = ['plan' => 'monthly', 'base' => 999, 'charged' => 999, 'tax' => 0, 'currency' => 'usd', 'code' => ''];
    echo json_encode(['on' => LLD_Members_Stripe_API::on(7), 'session' => LLD_Members_Stripe_API::checkout_session(null, $quote, 7, ''), 'sent' => count($GLOBALS['sent'])]);
  `));
  expect(out).toEqual({ on: false, session: null, sent: 0 });
});

test('Stripe keys never leave the Authorization header, and a live key outside production is refused', () => {
  const out = JSON.parse(php(`${stubs()}
    $GLOBALS['answers']['POST https://api.stripe.com/v1/checkout/sessions'] = ['code' => 400, 'body' => json_encode(['error' => ['message' => 'Invalid request', 'code' => 'parameter_invalid']])];
    $err = LLD_Members_Stripe_API::checkout_session(null, $quote, 7, '');
    $leak = false; foreach ($GLOBALS['sent'] as $s) { if (strpos($s['url'] . ($s['args']['body'] ?? ''), 'FAKEKEY') !== false) { $leak = true; } }
    echo json_encode(['error' => $err->message, 'leak' => $leak, 'auth' => $GLOBALS['sent'][0]['args']['headers']['Authorization'] === 'Bearer ' . ${JSON.stringify(FAKE_KEY)}, 'mode' => LLD_Members_Stripe_API::mode()]);
  `));
  expect(out).toEqual({ error: 'Invalid request', leak: false, auth: true, mode: 'test' });
  const live = JSON.parse(php(`${stubs(LIVE_KEY)}
    echo json_encode(['on' => LLD_Members_Stripe_API::on(7), 'mode' => LLD_Members_Stripe_API::mode(), 'session' => LLD_Members_Stripe_API::checkout_session(null, $quote, 7, ''), 'sent' => count($GLOBALS['sent'])]);
  `));
  expect(live).toEqual({ on: false, mode: 'refused', session: null, sent: 0 });
});

test('Cancel, account deletion and refunds go to Stripe first and report Stripe\'s answer', () => {
  const out = JSON.parse(php(`${stubs()}
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'cancel_at_period_end' => true]);
    $GLOBALS['answers']['DELETE https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'status' => 'canceled']);
    $GLOBALS['answers']['GET https://api.stripe.com/v1/invoice_payments?invoice=in_1'] = ok(['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_9']]]]);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/refunds'] = ok(['id' => 're_real_1']);
    $r = [];
    $r['cancel'] = LLD_Members_Stripe_API::cancel_at_period_end(true, 7, (object) ['stripe_subscription' => 'sub_1']);
    $post = end($GLOBALS['sent']); parse_str($post['args']['body'], $c); $r['cancel_form'] = $c;
    $r['delete'] = LLD_Members_Stripe_API::cancel_member(null, 7, 'sub_1');
    $r['gone'] = LLD_Members_Stripe_API::cancel_member(null, 7, 'sub_missing');               // already ended at Stripe
    $r['refund'] = LLD_Members_Stripe_API::refund('re_test_5', (object) ['id' => 5, 'member_id' => 7, 'stripe_payment' => 'in_1']);
    $post = end($GLOBALS['sent']); parse_str($post['args']['body'], $f); $r['refund_form'] = $f;
    $r['no_ref'] = LLD_Members_Stripe_API::refund('re_test_6', (object) ['id' => 6, 'member_id' => 7, 'stripe_payment' => 'cs_test_x'])->message;
    $r['pi'] = LLD_Members_Stripe_API::payment_ref('cs_test_real', ['object' => 'checkout.session', 'invoice' => 'in_1']);
    $r['pi_kept'] = LLD_Members_Stripe_API::payment_ref('pi_2', ['invoice' => 'in_1']);
    $r['switch'] = LLD_Members_Stripe_API::can_switch_yearly(true, 7);
    echo json_encode($r);
  `));
  expect(out.cancel).toBe(true);
  expect(out.cancel_form).toEqual({ cancel_at_period_end: 'true' });
  expect(out.delete).toBe(true);
  expect(out.gone).toBe(true);
  expect(out.refund).toBe('re_real_1');
  expect(out.refund_form).toEqual({ payment_intent: 'pi_9' });
  expect(out.no_ref).toMatch(/Refund it in the Stripe Dashboard/);
  expect(out.pi).toBe('pi_9');
  expect(out.pi_kept).toBe('pi_2');
  expect(out.switch).toBe(false); // no Stripe subscription to schedule the switch on, so the button is hidden
});

test('Stage 2 billing reaches Stripe only through the Stage 3 filters, and reads both invoice shapes', () => {
  const billing = src('includes/class-billing.php');
  for (const hook of ['lld_members_checkout_session', 'lld_members_plan_price', 'lld_members_plan_currency', 'lld_members_stripe_cancel', 'lld_members_stripe_refund', 'lld_members_payment_ref', 'lld_members_can_switch_yearly']) {
    expect(billing).toContain(`'${hook}'`);
  }
  expect(billing).toContain("$object['parent']['subscription_details']['subscription']");
  expect(billing).not.toMatch(/wp_remote_(request|post|get)\(/); // no direct Stripe call from Stage 2 code
  expect(src('includes/class-stripe.php')).toContain("'lld_members_stripe_cancel_member'");
  expect(src('includes/stage3/loader.php')).toContain('LLD_Members_Stripe_API::boot()');
  expect(src('includes/stage3/class-features.php')).toContain("'stripe_api'");
});

test('TC-FR2-14-AS-09: with real Stripe, monthly to yearly is scheduled from the next renewal with nothing charged now, and Undo releases it', () => {
  const out = JSON.parse(php(`${stubs()}
    LLD_Members_Billing::$sub = (object) ['stripe_subscription' => 'sub_1', 'plan' => 'monthly'];
    $ends = gmmktime(14, 0, 0, 3, 10, 2027);
    $GLOBALS['answers']['GET https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'schedule' => null, 'items' => ['data' => [['price' => ['id' => 'price_M'], 'current_period_end' => $ends]]]]);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscription_schedules/sub_sched_1'] = ok(['id' => 'sub_sched_1']);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscription_schedules'] = ok(['id' => 'sub_sched_1', 'status' => 'active', 'phases' => [['start_date' => $ends - 2419200]]]);
    $r = ['can' => LLD_Members_Stripe_API::can_switch_yearly(true, 7), 'done' => LLD_Members_Stripe_API::switch_plan(true, 7, 'yearly')];
    $forms = []; foreach ($GLOBALS['sent'] as $s) { if ($s['args']['method'] === 'POST') { parse_str($s['args']['body'], $f); $forms[strtok($s['url'], '?')] = $f; } }
    $r['create'] = $forms['https://api.stripe.com/v1/subscription_schedules'] ?? null;
    $r['phases'] = $forms['https://api.stripe.com/v1/subscription_schedules/sub_sched_1'] ?? null;
    $r['ends'] = $ends;
    $r['subscription_changed'] = isset($forms['https://api.stripe.com/v1/subscriptions/sub_1']);
    // Undo: the schedule is released, leaving the monthly subscription as it was.
    $GLOBALS['sent'] = [];
    $GLOBALS['answers'] = ['GET https://api.stripe.com/v1/subscriptions/sub_1' => ok(['id' => 'sub_1', 'schedule' => 'sub_sched_1']), 'POST https://api.stripe.com/v1/subscription_schedules/sub_sched_1/release' => ok(['id' => 'sub_sched_1', 'status' => 'released'])];
    $r['undo'] = LLD_Members_Stripe_API::switch_plan(true, 7, 'undo');
    $r['undo_calls'] = array_map(function ($s) { return $s['args']['method'] . ' ' . strtok($s['url'], '?'); }, $GLOBALS['sent']);
    // A refusal from Stripe comes back as an error, so the site queues nothing.
    $GLOBALS['answers'] = ['GET https://api.stripe.com/v1/subscriptions/sub_1' => ['code' => 500, 'body' => json_encode(['error' => ['message' => 'Try later']])]];
    $r['refused'] = is_wp_error(LLD_Members_Stripe_API::switch_plan(true, 7, 'yearly'));
    // Switch off: the Stage 2 answer stands and nothing is sent.
    LLD_Members_Features::$on = false; $GLOBALS['sent'] = [];
    $r['off'] = LLD_Members_Stripe_API::switch_plan(true, 7, 'yearly'); $r['off_sent'] = count($GLOBALS['sent']);
    echo json_encode($r);
  `));
  expect(out.can).toBe(true);
  expect(out.done).toBe(true);
  expect(out.create).toEqual({ from_subscription: 'sub_1' });
  expect(out.phases.end_behavior).toBe('release');
  expect(out.phases.phases[0]).toMatchObject({ end_date: String(out.ends), proration_behavior: 'none', items: [{ price: 'price_M', quantity: '1' }] });
  expect(out.phases.phases[1]).toMatchObject({ iterations: '1', proration_behavior: 'none', items: [{ price: 'price_Y', quantity: '1' }], metadata: { plan: 'yearly', member_id: '7' } });
  expect(out.subscription_changed).toBe(false); // the price is never changed on the subscription itself (that would charge at once)
  expect(out.undo).toBe(true);
  expect(out.undo_calls).toEqual(['GET https://api.stripe.com/v1/subscriptions/sub_1', 'POST https://api.stripe.com/v1/subscription_schedules/sub_sched_1/release']);
  expect(out.refused).toBe(true);
  expect(out.off).toBe(true);
  expect(out.off_sent).toBe(0);
});

test('TC-FR2-29-AS-06: with real Stripe, a waiting reward goes on the next invoice as a once-only discount of exactly the reward', () => {
  const out = JSON.parse(php(`${stubs()}
    LLD_Members_Billing::$sub = (object) ['stripe_subscription' => 'sub_1', 'plan' => 'yearly'];
    LLD_Members_Billing::$next = ['charged' => 6089, 'currency' => 'usd', 'reward_id' => 4, 'base' => 6999];
    $GLOBALS['answers']['GET https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'discounts' => []]);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/coupons'] = ok(['id' => 'lld_reward_910usd']);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1']);
    LLD_Members_Stripe_API::reward_ready(7);
    $forms = []; foreach ($GLOBALS['sent'] as $s) { if ($s['args']['method'] === 'POST') { parse_str($s['args']['body'], $f); $forms[strtok($s['url'], '?')] = $f; } }
    $r = ['coupon' => $forms['https://api.stripe.com/v1/coupons'] ?? null, 'sub' => $forms['https://api.stripe.com/v1/subscriptions/sub_1'] ?? null];
    // A discount already waiting at Stripe: nothing more is added.
    $GLOBALS['sent'] = []; $GLOBALS['answers']['GET https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'discounts' => ['di_1']]);
    LLD_Members_Stripe_API::reward_ready(7);
    $r['second_posts'] = count(array_filter($GLOBALS['sent'], function ($s) { return $s['args']['method'] === 'POST'; }));
    $r['month_free'] = LLD_Members_Stripe_API::reward_off(['charged' => 0, 'base' => 999, 'reward_id' => 2]);
    $r['none'] = LLD_Members_Stripe_API::reward_off(['charged' => 999, 'base' => 999, 'reward_id' => 0]);
    echo json_encode($r);
  `));
  expect(out.coupon).toEqual({ id: 'lld_reward_910usd', name: 'REWARD', amount_off: '910', currency: 'usd', duration: 'once' });
  expect(out.sub).toEqual({ discounts: [{ coupon: 'lld_reward_910usd' }] });
  expect(out.second_posts).toBe(0);
  expect(out.month_free).toBe(999);
  expect(out.none).toBe(0);
});

test('TC-FR2-14-AS-09 (review): a switch after an undo makes a new schedule, a released one is refused, a waiting reward rides on the yearly phase, and cancelling releases the schedule first', () => {
  const out = JSON.parse(php(`${stubs()}
    LLD_Members_Billing::$sub = (object) ['stripe_subscription' => 'sub_1', 'plan' => 'monthly', 'pending_yearly_on' => '2027-03-10'];
    LLD_Members_Billing::$next = ['charged' => 870, 'currency' => 'usd', 'reward_id' => 4, 'base' => 999];
    $ends = gmmktime(14, 0, 0, 3, 10, 2027);
    $GLOBALS['answers']['GET https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'schedule' => null, 'discounts' => ['di_old'], 'items' => ['data' => [['price' => ['id' => 'price_M'], 'current_period_end' => $ends]]]]);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscription_schedules/sub_sched_2'] = ok(['id' => 'sub_sched_2']);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscription_schedules'] = ok(['id' => 'sub_sched_2', 'status' => 'not_started', 'phases' => [['start_date' => $ends - 2419200]]]);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/coupons'] = ok(['id' => 'lld_reward_910usd']);
    $GLOBALS['answers']['DELETE https://api.stripe.com/v1/subscriptions/sub_1/discount'] = ok(['deleted' => true]);
    $r = ['done' => LLD_Members_Stripe_API::switch_plan(true, 7, 'yearly')];
    $keys = []; $forms = []; $calls = [];
    foreach ($GLOBALS['sent'] as $s) { $calls[] = $s['args']['method'] . ' ' . strtok($s['url'], '?'); if ($s['args']['method'] === 'POST') { parse_str($s['args']['body'], $f); $forms[strtok($s['url'], '?')] = $f; $keys[] = $s['args']['headers']['Idempotency-Key'] ?? ''; } }
    $r['phase2_discount'] = $forms['https://api.stripe.com/v1/subscription_schedules/sub_sched_2']['phases'][1]['discounts'] ?? null;
    $r['coupon'] = $forms['https://api.stripe.com/v1/coupons'] ?? null;
    $r['old_removed'] = in_array('DELETE https://api.stripe.com/v1/subscriptions/sub_1/discount', $calls, true);
    $r['keys'] = $keys;
    // Stripe hands back a schedule that is no longer live (e.g. released): refused, so the site queues nothing.
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscription_schedules'] = ok(['id' => 'sub_sched_2', 'status' => 'released', 'phases' => [['start_date' => $ends - 2419200]]]);
    $r['released_refused'] = is_wp_error(LLD_Members_Stripe_API::switch_plan(true, 7, 'yearly'));
    // Cancelling with a switch queued: the schedule is released, then the subscription cancels at period end.
    $GLOBALS['sent'] = [];
    $GLOBALS['answers'] = ['GET https://api.stripe.com/v1/subscriptions/sub_1' => ok(['id' => 'sub_1', 'schedule' => ['id' => 'sub_sched_2']]), 'POST https://api.stripe.com/v1/subscription_schedules/sub_sched_2/release' => ok(['id' => 'sub_sched_2']), 'POST https://api.stripe.com/v1/subscriptions/sub_1' => ok(['id' => 'sub_1'])];
    $r['cancel'] = LLD_Members_Stripe_API::cancel_at_period_end(true, 7, (object) ['stripe_subscription' => 'sub_1']);
    $r['cancel_calls'] = array_map(function ($s) { return $s['args']['method'] . ' ' . strtok($s['url'], '?'); }, $GLOBALS['sent']);
    echo json_encode($r);
  `));
  expect(out.done).toBe(true);
  expect(out.phase2_discount).toEqual([{ coupon: 'lld_reward_910usd' }]);   // 13 % of US$69.99, as the site expects
  expect(out.coupon).toMatchObject({ amount_off: '910', currency: 'usd', duration: 'once' });
  expect(out.old_removed).toBe(true);                                        // the monthly-form coupon comes off
  expect(out.keys.every((k) => /uuid-1$/.test(k) || /^lld-coupon-/.test(k))).toBe(true); // fresh keys, never a replay of an earlier switch
  expect(out.released_refused).toBe(true);
  expect(out.cancel).toBe(true);
  expect(out.cancel_calls).toEqual(['GET https://api.stripe.com/v1/subscriptions/sub_1', 'POST https://api.stripe.com/v1/subscription_schedules/sub_sched_2/release', 'POST https://api.stripe.com/v1/subscriptions/sub_1']);
});

test('TC-FR2-29-AS-06 (review): with a switch queued, a waiting reward is put on the yearly phase of the schedule if it is missing there', () => {
  const out = JSON.parse(php(`${stubs()}
    LLD_Members_Billing::$sub = (object) ['stripe_subscription' => 'sub_1', 'plan' => 'monthly', 'pending_yearly_on' => '2027-03-10'];
    LLD_Members_Billing::$next = ['charged' => 870, 'currency' => 'usd', 'reward_id' => 4, 'base' => 999];
    $ends = gmmktime(14, 0, 0, 3, 10, 2027);
    $GLOBALS['answers']['GET https://api.stripe.com/v1/subscriptions/sub_1'] = ok(['id' => 'sub_1', 'schedule' => 'sub_sched_3', 'items' => ['data' => [['price' => ['id' => 'price_M'], 'current_period_end' => $ends]]]]);
    $GLOBALS['answers']['GET https://api.stripe.com/v1/subscription_schedules/sub_sched_3'] = ok(['id' => 'sub_sched_3', 'status' => 'active', 'phases' => [['start_date' => $ends - 2419200], ['items' => []]]]);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/coupons'] = ok(['id' => 'lld_reward_910usd']);
    $GLOBALS['answers']['POST https://api.stripe.com/v1/subscription_schedules/sub_sched_3'] = ok(['id' => 'sub_sched_3']);
    LLD_Members_Stripe_API::reward_ready(7);
    $phases = null; $subPost = false;
    foreach ($GLOBALS['sent'] as $s) { if ($s['args']['method'] === 'POST' && strtok($s['url'], '?') === 'https://api.stripe.com/v1/subscription_schedules/sub_sched_3') { parse_str($s['args']['body'], $phases); } if ($s['args']['method'] === 'POST' && strtok($s['url'], '?') === 'https://api.stripe.com/v1/subscriptions/sub_1') { $subPost = true; } }
    echo json_encode(['discount' => $phases['phases'][1]['discounts'] ?? null, 'sub_post' => $subPost, 'off' => LLD_Members_Stripe_API::yearly_reward_off(6999)]);
  `));
  expect(out.discount).toEqual([{ coupon: 'lld_reward_910usd' }]);
  expect(out.sub_post).toBe(false);   // never on the subscription itself while the schedule manages it
  expect(out.off).toBe(910);
});
