// P3-36 A7: the Conditions question is required before health details are saved (FR3-69 AS-06), logging and goal
// refusals name the missing question and link to it (FR3-69 AS-05), and goal setting takes the member to the
// question and back (FR3-09 AS-08). Run in PHP CLI with stubbed WordPress and Stage 2 classes; the real Stage 2
// health profile screen (class-personal.php), the real Stage 3 matrix, health log and goal-setting screen.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const inc = (name) => resolve(plugin, `includes/${name}`);
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

// Member 7: the Stage 3 switches are on (a test account on CI, everyone on staging). Member 8: a Stage 2 member.
const stubs = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
class Redirected extends Exception {}
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
class WP_Error { public $m; function __construct($c, $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } function get_error_data() { return null; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function home_url($p = '') { return 'https://stg.example' . $p; }
function wp_safe_redirect($u) { throw new Redirected($u); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } function esc_attr($s) { return esc_html($s); } function esc_url($s) { return (string) $s; }
function sanitize_text_field($s) { return trim((string) $s); } function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\\-]/i', '', (string) $s)); }
function sanitize_title($s) { return sanitize_key($s); }
function wp_unslash($v) { return $v; } function wp_verify_nonce() { return true; } function wp_nonce_field() { return ''; }
function selected($a, $b, $e = true) { return (string) $a === (string) $b ? ' selected' : ''; }
function current_user_can() { return false; } function is_user_logged_in() { return true; } function get_query_var() { return ''; } function get_option() { return false; }
function lld_state_saved() { return '<p>Saved</p>'; } function lld_caution($t) { return '<p class="caution">' . $t . '</p>'; }
function lld_info($t) { return '<div class="info">' . esc_html($t) . '</div>'; } function lld_health_consent_notice() { return '<p>consent</p>'; }
function lld_button($a) { return isset($a['href']) ? '<a class="lld-button" href="' . esc_url($a['href']) . '">' . esc_html($a['label']) . '</a>' : '<button>' . esc_html($a['label'] ?? 'Continue') . '</button>'; }
function lld_members_layout($a) { $GLOBALS['page'] = $a; }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { public static $on = [7 => true, 8 => false]; static function enabled($f, $m = 0) { return !empty(self::$on[$m]); } static function state($f) { return 'test_accounts'; } }
class LLD_Members_Safety { static function situations($m) { return ['unknown']; } }
class LLD_Members_Access { public static $me = 7; static function preview_state() { return ''; } static function current_member_id() { return self::$me; } }
class LLD_Members_Account {
  public static $saved = [];
  static function has_consent($m, $t) { return true; }
  static function save_health($m, $f) { self::$saved[] = $f; $GLOBALS['wpdb']->store($m, $f); return true; }
}
class FakeDb {
  public $profile = [];
  function prepare($q, ...$a) { return [$q, $a[0] ?? 0]; }
  function store($m, $f) {
    $row = $this->profile[$m] ?? (object) ['member_id' => $m, 'sex' => null, 'height_cm' => null, 'weight_kg' => null, 'waist_cm' => null, 'activity_level' => null, 'diet_type' => null, 'ethnicity' => null, 'conditions_answered' => 'not_answered', 'conditions' => null, 'age' => 40];
    foreach (['sex', 'height_cm', 'weight_kg', 'waist_cm', 'activity_level', 'diet_type', 'ethnicity', 'conditions_answered', 'conditions'] as $k) { if (array_key_exists($k, $f)) { $row->$k = ('' === $f[$k] || null === $f[$k]) ? null : $f[$k]; } }
    $this->profile[$m] = $row;
  }
  function get_row($p) { [$q, $m] = $p; if (strpos($q, 'health_profile') !== false) { return $this->profile[$m] ?? null; } return strpos($q, 'wp_lld_member ') !== false || strpos($q, 'wp_lld_member WHERE') !== false ? (object) ['units' => $GLOBALS['units'] ?? 'metric'] : null; }
  function get_results() { return []; } function get_var() { return null; } function update() { return 1; }
}
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(inc('stage3/engine/class-rule-catalogue.php'))};
require ${toPhpPath(inc('stage3/engine/class-decision-engine.php'))};
require ${toPhpPath(inc('stage3/class-safety-stage3.php'))};
require ${toPhpPath(inc('stage3/class-health-log.php'))};
require ${toPhpPath(inc('class-personal.php'))};
require ${toPhpPath(inc('stage3/class-api.php'))};
function health_post($post, $get = []) {
  $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + ['lld_health_nonce' => 'n']; $_GET = $get; $GLOBALS['page'] = null;
  try { LLD_Members_Personal::screen('health'); } catch (Redirected $r) { return ['redirect' => $r->getMessage()]; }
  return ['html' => $GLOBALS['page']['main']];
}
function health_get($get = []) {
  $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = $get; $GLOBALS['page'] = null;
  LLD_Members_Personal::screen('health');
  return $GLOBALS['page']['main'];
}
`;
const php = (code, extra = '') => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} ${extra} ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const run = (code, extra = '') => JSON.parse(php(`echo json_encode((function () { ${code} })());`, extra));

// The goal-setting screen, with the goal journey's facts and rules stubbed: unknown situations until the profile has
// an answered Conditions question, then none. create_goal records what it was asked to save.
const goal = `
class LLD_Members_Goal_Journey {
  public static $created = [];
  static function facts($m) { $s = LLD_Members_Stage3_Safety::conditions_state_of($GLOBALS['wpdb']->profile[$m] ?? null); return ['health' => true, 'situations' => 'answered' === $s ? [] : ['unknown'], 'imperial' => false]; }
  static function note_texts() { return ['general_wellbeing_path' => "Answer the health questions in your health profile first, and we'll set a goal together. Until then the articles, calculators and tracks are all here for you."]; }
  static function create_goal($m, $role, $type, $in) { self::$created[] = [$m, $role, $type]; return 41; }
  static function active_goal() { return null; }
}
define('LLD_MEMBERS_FILE', '/x'); define('LLD_MEMBERS_VERSION', '1');
function wp_enqueue_style() {} function plugins_url($p = '') { return $p; } function wp_print_styles() {}
require ${toPhpPath(inc('stage3/class-goal-rules.php'))};
require ${toPhpPath(inc('stage3/screens/class-goal-setting.php'))};
function goal_open($method, $post = [], $get = []) {
  $_SERVER['REQUEST_METHOD'] = $method; $_POST = $post; $_GET = $get; $_REQUEST = $post + $get;
  try { LLD_Members_Goal_Setting::render(7); } catch (Redirected $r) { return ['redirect' => $r->getMessage()]; }
  return ['none' => true];
}
`;
// The screen's own output() calls lld_members_layout, so the page is read from $GLOBALS['page'] after render.
const goalRun = (code) => run(code, goal);

const link = 'https://stg.example/members/health/#lld-conditions';
const goalLink = 'https://stg.example/members/health/?return=goal#lld-conditions';

test('TC-FR3-69-AS-06', () => {
  const r = run(`
    $out = [];
    // Health details with no Conditions answer: not saved, the error names the question, the form keeps the values.
    $out['blocked'] = health_post(['lld_height' => '170', 'lld_weight' => '70']);
    $out['blocked_saves'] = count(LLD_Members_Account::$saved);
    $out['sex_only'] = health_post(['lld_sex' => 'female']);
    $out['sex_saves'] = count(LLD_Members_Account::$saved);
    // An empty save holds no health details: it still succeeds (FR2-25 AS-01).
    $out['empty'] = health_post([]);
    // "Prefer not to answer" counts, and is shown again ticked; the matrix still reads the conditions as unknown.
    $out['prefer'] = health_post(['lld_height' => '170', 'lld_condition' => ['prefer-not']]);
    $out['prefer_row'] = $GLOBALS['wpdb']->profile[7];
    $out['prefer_state'] = LLD_Members_Stage3_Safety::conditions_state(7);
    $out['prefer_situations'] = LLD_Members_Stage3_Safety::situations(7);
    $out['prefer_form'] = health_get();
    // Once answered, later saves need no new tick.
    $out['later'] = health_post(['lld_weight' => '71']);
    // "None of these" counts too (a fresh member).
    LLD_Members_Access::$me = 9; LLD_Members_Features::$on[9] = true;
    $out['none'] = health_post(['lld_height' => '170', 'lld_condition' => ['none']]);
    $out['none_row'] = $GLOBALS['wpdb']->profile[9];
    // A Stage 2 member (switches off) keeps Stage 2's behaviour: each field saves alone (FR2-25 AS-01, AS-03).
    LLD_Members_Access::$me = 8;
    $out['stage2'] = health_post(['lld_height' => '170']);
    $out['stage2_row'] = $GLOBALS['wpdb']->profile[8];
    return $out;
  `);
  const required = 'Answer the Conditions question to save these details. None of these and Prefer not to answer both count.';
  expect(r.blocked.html).toContain(`<div class="lld-fail" role="alert"><p>${required}</p></div>`);
  expect(r.blocked.html).not.toContain('<p>Saved</p>');
  expect(r.blocked.html).toContain('<fieldset id="lld-conditions"><legend>Conditions</legend>');
  expect(r.blocked.html).toMatch(/id="lld_height" name="lld_height"[^>]*value="170"/);
  expect(r.blocked_saves).toBe(0);
  expect(r.sex_only.html).toContain(required);
  expect(r.sex_saves).toBe(0);
  expect(r.empty.html).toContain('<p>Saved</p>');
  expect(r.prefer.html).toContain('<p>Saved</p>');
  expect(r.prefer_row).toMatchObject({ height_cm: 170, conditions_answered: 'not_answered', conditions: '["prefer-not"]' });
  expect(r.prefer_state).toBe('prefer_not');
  expect(r.prefer_situations).toEqual(['unknown']);
  expect(r.prefer_form).toContain('value="prefer-not" checked');
  expect(r.later.html).toContain('<p>Saved</p>');
  expect(r.none.html).toContain('<p>Saved</p>');
  expect(r.none_row).toMatchObject({ height_cm: 170, conditions_answered: 'none' });
  expect(r.stage2.html).toContain('<p>Saved</p>');
  expect(r.stage2.html).not.toContain(required);
  expect(r.stage2_row).toMatchObject({ height_cm: 170, conditions_answered: 'not_answered' });
});

test('TC-FR3-69-AS-05', () => {
  const r = goalRun(`
    $out = [];
    // Never answered: logging a reading is refused (403, nothing stored), naming the question with a link to it.
    $out['missing'] = LLD_Members_Health_Log::for_member(7, ['reading_type' => 'weight', 'value_1' => 70, 'unit' => 'kg', 'taken_at' => '2026-11-14 08:00:00']);
    // "Prefer not to answer": still refused by the matrix (unknown conditions never store), with the link.
    $GLOBALS['wpdb']->store(7, ['conditions_answered' => 'not_answered', 'conditions' => '["prefer-not"]']);
    $out['prefer'] = LLD_Members_Health_Log::for_member(7, ['reading_type' => 'weight', 'value_1' => 70, 'unit' => 'kg', 'taken_at' => '2026-11-14 08:00:00']);
    // Answered "None of these": the matrix lets the reading through.
    $GLOBALS['wpdb']->store(7, ['conditions_answered' => 'none', 'conditions' => null]);
    $out['answered'] = LLD_Members_Health_Log::for_member(7, ['reading_type' => 'weight', 'value_1' => 70, 'unit' => 'kg', 'taken_at' => '2026-11-14 08:00:00']);
    // Setting a goal without the answer (a posted goal): refused, nothing created, the question named and linked.
    unset($GLOBALS['wpdb']->profile[7]);
    goal_open('POST', ['lld_goal3_nonce' => 'n', 'lld_action' => 'save', 'type' => 'lose_weight']);
    $out['goal_page'] = $GLOBALS['page'];
    $out['created'] = LLD_Members_Goal_Journey::$created;
    // The interface's refusal carries the question and the link in its 403 body.
    $m = new ReflectionMethod('LLD_Members_Stage3_API', 'refuse'); $m->setAccessible(true);
    $res = $m->invoke(null, $out['missing']['status'], $out['missing']['error'], array_intersect_key($out['missing'], ['question' => 1, 'link' => 1]));
    $out['rest'] = [$res->status, $res->data];
    return $out;
  `);
  expect(r.rest).toEqual([403, { message: 'Answer the "Conditions" question in your health profile first.', question: 'conditions', link }]);
  expect(r.missing).toEqual({ error: 'Answer the "Conditions" question in your health profile first.', status: 403, question: 'conditions', link });
  expect(r.prefer).toEqual({ error: 'Not available.', status: 403, question: 'conditions', link });
  expect(r.answered.error).toBeUndefined();
  expect(r.goal_page.status).toBe(403);
  expect(r.goal_page.main).toContain('Answer the &quot;Conditions&quot; question in your health profile first.');
  expect(r.goal_page.main).toContain(`href="${goalLink}"`);
  expect(r.created).toEqual([]);
  // The interface passes the question and the link on, and refuses before any insert (nothing stored).
  const api = readFileSync(inc('stage3/class-api.php'), 'utf8');
  const create = api.slice(api.indexOf("if ( 'readings' === $def['table'] ) {\n\t\t\t$extras"), api.indexOf('LLD_Members_Stage3_Data::insert( $def'));
  expect(create).toContain("self::refuse( isset( $filled['status'] ) ? (int) $filled['status'] : 400, $filled['error'], array_intersect_key( $filled, array( 'question' => 1, 'link' => 1 ) ) )");
  expect(api).toMatch(/private static function refuse\( \$status, \$message, array \$extra = array\(\) \) \{\s*\/\/[^\n]*\n\s*return new WP_REST_Response\( array_merge\( array\( 'message' => \$message \), \$extra \), \$status \);/);
});

// P3-36 (left over from A7): POST /me/goals refuses a goal while the Conditions answer is missing, before any insert.
test('TC-FR3-69-AS-05 (goals interface)', () => {
  const r = run(`
    class FakeReq { public $m; function __construct($m) { $this->m = $m; } function get_method() { return $this->m; } }
    $out = [];
    unset($GLOBALS['wpdb']->profile[7]);
    LLD_Members_Stage3_API::$request = new FakeReq('POST');
    $res = LLD_Members_Stage3_Safety::api_refuse(null, 'goals', 7);
    $out['missing'] = $res ? [$res->status, $res->data] : null;
    LLD_Members_Stage3_API::$request = new FakeReq('PATCH');
    $out['patch'] = (bool) LLD_Members_Stage3_Safety::api_refuse(null, 'goals', 7);
    // Reading and deleting goals stay open.
    LLD_Members_Stage3_API::$request = new FakeReq('GET');
    $out['get'] = LLD_Members_Stage3_Safety::api_refuse(null, 'goals', 7);
    LLD_Members_Stage3_API::$request = new FakeReq('DELETE');
    $out['delete'] = LLD_Members_Stage3_Safety::api_refuse(null, 'goals', 7);
    // Answered ("None of these") or "Prefer not to answer": the goal is accepted, as on the goal-setting screen.
    LLD_Members_Stage3_API::$request = new FakeReq('POST');
    $GLOBALS['wpdb']->store(7, ['conditions_answered' => 'none', 'conditions' => null]);
    $out['answered'] = LLD_Members_Stage3_Safety::api_refuse(null, 'goals', 7);
    $GLOBALS['wpdb']->store(7, ['conditions_answered' => 'not_answered', 'conditions' => '["prefer-not"]']);
    $out['prefer'] = LLD_Members_Stage3_Safety::api_refuse(null, 'goals', 7);
    return $out;
  `);
  expect(r.missing).toEqual([403, { message: 'Answer the "Conditions" question in your health profile first.', question: 'conditions', link: goalLink }]);
  expect(r.patch).toBe(true);
  expect(r.get).toBeNull();
  expect(r.delete).toBeNull();
  expect(r.answered).toBeNull();
  expect(r.prefer).toBeNull();
  // The members' interface applies this filter in gate(), which create() runs before LLD_Members_Stage3_Data::insert.
  const api = readFileSync(inc('stage3/class-api.php'), 'utf8');
  const create = api.slice(api.indexOf('public static function create( $request ) {'), api.indexOf('LLD_Members_Stage3_Data::insert( $def'));
  expect(create).toContain('$gate = self::gate( $request );');
  const gate = api.slice(api.indexOf('private static function gate( $request ) {'), api.indexOf('public static function calculate('));
  expect(gate.indexOf('self::$request = $request;')).toBeGreaterThan(-1);
  expect(gate.indexOf("apply_filters( 'lld_members_stage3_api_refuse'")).toBeGreaterThan(gate.indexOf('self::$request = $request;'));
});

test('TC-FR3-09-AS-08', () => {
  const r = goalRun(`
    $out = [];
    // Opening goal setting without the answer takes the member to the question.
    $out['open'] = goal_open('GET');
    // There, the approved goal-path line explains why; saving the answer returns to goal setting.
    $out['health'] = health_get(['return' => 'goal']);
    $out['back'] = health_post(['lld_height' => '170', 'lld_condition' => ['none']], ['return' => 'goal']);
    $out['real_situations'] = LLD_Members_Stage3_Safety::situations(7);
    $out['health_after'] = health_get(['return' => 'goal']);
    // Back on goal setting the goal is saved.
    $out['open_again'] = goal_open('GET');
    $out['saved'] = goal_open('POST', ['lld_goal3_nonce' => 'n', 'lld_action' => 'save', 'type' => 'lose_weight']);
    $out['created'] = LLD_Members_Goal_Journey::$created;
    // "Prefer not to answer" is an answer: no redirect loop; the goal page shows the goal path and the link instead.
    LLD_Members_Access::$me = 7; $GLOBALS['wpdb']->store(7, ['conditions_answered' => 'not_answered', 'conditions' => '["prefer-not"]']);
    $out['prefer_open'] = goal_open('GET');
    // An extra goal keeps its role there and back; Prefer not to answer also returns to goal setting.
    unset($GLOBALS['wpdb']->profile[7]);
    $out['extra_open'] = goal_open('GET', [], ['role' => 'extra']);
    $out['extra_back'] = health_post(['lld_height' => '170', 'lld_condition' => ['prefer-not']], ['return' => 'goal', 'role' => 'extra']);
    $GLOBALS['wpdb']->store(7, ['conditions_answered' => 'not_answered', 'conditions' => '["prefer-not"]']);
    goal_open('GET');
    $out['prefer_page'] = $GLOBALS['page'];
    return $out;
  `);
  expect(r.open).toEqual({ redirect: goalLink });
  expect(r.health).toContain("Answer the health questions in your health profile first, and we&#039;ll set a goal together.");
  expect(r.back).toEqual({ redirect: 'https://stg.example/members/goal/' });
  expect(r.real_situations).not.toContain('unknown');
  expect(r.health_after).not.toContain("we&#039;ll set a goal together");
  expect(r.extra_open).toEqual({ redirect: 'https://stg.example/members/health/?return=goal&role=extra#lld-conditions' });
  expect(r.extra_back).toEqual({ redirect: 'https://stg.example/members/goal/?role=extra' });
  expect(r.open_again.redirect).toBeUndefined();
  expect(r.saved).toEqual({ redirect: 'https://stg.example/members/goal/?done=41' });
  expect(r.created).toEqual([[7, 'main', 'lose_weight']]);
  expect(r.prefer_open.redirect).toBeUndefined();
  expect(r.prefer_page.main).toContain("Answer the health questions in your health profile first");
  expect(r.prefer_page.main).toContain(`href="${goalLink}"`);
});

test('P3-36 A7 wording: the new sentences are marked as pending approval and the signed matrix is unchanged', () => {
  const s = readFileSync(inc('stage3/class-safety-stage3.php'), 'utf8');
  expect(s).toContain("NEW WORDING, PENDING THE OWNER'S APPROVAL (P3-36 A7)");
  const w = run('return LLD_Members_Stage3_Safety::CONDITIONS_WORDING;');
  expect(Object.keys(w)).toEqual(['conditions.missing', 'conditions.required']);
  const m = run('return array_keys(LLD_Members_Stage3_Safety::MESSAGES);');
  expect(m.some((k) => k.startsWith('conditions.'))).toBe(false);
  // The unknown row still refuses storing (never weakened).
  const rows = run("return LLD_Members_Stage3_Safety::rows()['unknown']['health_logging'];");
  expect(rows.refused).toContain('store');
});

// P3-36 D4: with the account hub on, the stored measurements are the values in the boxes (in Account's units),
// BMI and waist-to-height are tiles, and a box still holding the stored value is not saved again.
test('TC-FR3-06-AS-05', () => {
  const r = run(`
    $out = [];
    $GLOBALS['wpdb']->store(7, ['height_cm' => 170, 'weight_kg' => 70, 'waist_cm' => 85, 'conditions_answered' => 'none']);
    $out['metric'] = health_get();
    health_post(['lld_height' => '170.0', 'lld_weight' => '70.0', 'lld_waist' => '85.0', 'lld_diet' => 'vegetarian']);
    $out['unchanged'] = end(LLD_Members_Account::$saved);
    health_post(['lld_height' => '170.0', 'lld_weight' => '71', 'lld_waist' => '85.0']);
    $out['changed'] = end(LLD_Members_Account::$saved);
    $GLOBALS['wpdb']->store(7, ['weight_kg' => 70]);
    $GLOBALS['units'] = 'imperial';
    $out['imperial'] = health_get();
    health_post(['lld_imperial' => '1', 'lld_feet' => '5', 'lld_inches' => '6.9', 'lld_pounds' => '154.3', 'lld_waist_in' => '33.5']);
    $out['imperial_unchanged'] = end(LLD_Members_Account::$saved);
    $out['imperial_row'] = clone $GLOBALS['wpdb']->profile[7];
    health_post(['lld_imperial' => '1', 'lld_feet' => '5', 'lld_inches' => '7', 'lld_pounds' => '154.3', 'lld_waist_in' => '33.5']);
    $out['imperial_changed'] = end(LLD_Members_Account::$saved);
    // 182.8 cm is 71.97 in: shown as 6 ft 0.0 in, never 5 ft 12.0 in.
    $GLOBALS['wpdb']->store(7, ['height_cm' => 182.8]);
    $out['edge'] = health_get();
    // A Stage 2 member (hub off) keeps the Stage 2 form: "Stored ..." lines, empty boxes, its own units checkbox.
    $GLOBALS['units'] = 'metric';
    LLD_Members_Access::$me = 8;
    $GLOBALS['wpdb']->store(8, ['height_cm' => 170, 'weight_kg' => 70]);
    $out['stage2'] = health_get();
    return $out;
  `);
  const box = (id, value) => `id="${id}" name="${id}" type="number" step="0.1" inputmode="decimal"${value === null ? '>' : ` value="${value}">`}`;
  expect(r.metric).toContain(box('lld_height', '170.0'));
  expect(r.metric).toContain(box('lld_weight', '70.0'));
  expect(r.metric).toContain(box('lld_waist', '85.0'));
  expect(r.metric).not.toContain('Stored height');
  expect(r.metric).not.toContain('name="lld_imperial"');
  // The tiles carry the two figures the page already worked out, and no judgement of them.
  const tiles = r.metric.match(/<div class="lld-measure-tiles">.*?<\/div><\/div>/)[0];
  expect(tiles).toBe('<div class="lld-measure-tiles"><div class="lld-measure-tile"><span>BMI</span><strong>24.2</strong></div><div class="lld-measure-tile"><span>Waist-to-height</span><strong>0.50</strong></div></div>');
  expect(r.unchanged).toMatchObject({ diet_type: 'vegetarian' });
  for (const key of ['height_cm', 'weight_kg', 'waist_cm']) expect(r.unchanged, key).not.toHaveProperty(key);
  expect(r.changed).toMatchObject({ weight_kg: 71 });
  expect(r.changed).not.toHaveProperty('height_cm');
  expect(r.imperial).toContain('<input type="hidden" name="lld_imperial" value="1">');
  expect(r.imperial).not.toContain('type="checkbox" name="lld_imperial"');
  expect(r.imperial).toContain('<div data-lld-imperial-fields>');
  expect(r.imperial).toContain('<div data-lld-metric-fields hidden>');
  expect(r.imperial).toContain(box('lld_feet', '5'));
  expect(r.imperial).toContain(box('lld_inches', '6.9'));
  expect(r.imperial).toContain(box('lld_pounds', '154.3'));
  expect(r.imperial).toContain(box('lld_waist_in', '33.5'));
  // Saving the imperial boxes as shown does not shift the stored metric values.
  for (const key of ['height_cm', 'weight_kg', 'waist_cm']) expect(r.imperial_unchanged, key).not.toHaveProperty(key);
  expect(r.imperial_row).toMatchObject({ height_cm: 170, weight_kg: 70, waist_cm: 85 });
  expect(r.imperial_changed).toMatchObject({ height_cm: 170.2 });
  expect(r.imperial_changed).not.toHaveProperty('weight_kg');
  expect(r.edge).toContain(box('lld_feet', '6'));
  expect(r.edge).toContain(box('lld_inches', '0.0'));
  expect(r.stage2).toContain('<p>Stored height: 170.0 cm</p><p>Stored weight: 70.0 kg</p><p>BMI: 24.2</p>');
  expect(r.stage2).not.toContain('lld-measure-tile');
  expect(r.stage2).toContain(box('lld_height', null));
  expect(r.stage2).toContain('<input type="checkbox" name="lld_imperial" value="1" data-lld-imperial> Use imperial units');
  expect(r.stage2).toContain('<div data-lld-metric-fields>');
});

// P3-36 D4 with A7: the stored values in the boxes neither bypass the Conditions rule nor trip it on their own.
test('TC-FR3-69-AS-06 (stored values in the boxes)', () => {
  const r = run(`
    $out = [];
    // A profile from before the rule: a height, and no Conditions answer.
    $GLOBALS['wpdb']->store(7, ['height_cm' => 170]);
    $out['same'] = health_post(['lld_height' => '170.0']);
    $out['same_saved'] = end(LLD_Members_Account::$saved);
    $before = count(LLD_Members_Account::$saved);
    $out['new_value'] = health_post(['lld_height' => '170.0', 'lld_weight' => '70']);
    $out['sex'] = health_post(['lld_height' => '170.0', 'lld_sex' => 'female']);
    $out['blocked_saves'] = count(LLD_Members_Account::$saved) - $before;
    $out['answered'] = health_post(['lld_height' => '170.0', 'lld_weight' => '70', 'lld_condition' => ['none']]);
    $out['row'] = $GLOBALS['wpdb']->profile[7];
    return $out;
  `);
  const required = 'Answer the Conditions question to save these details. None of these and Prefer not to answer both count.';
  expect(r.same.html).toContain('<p>Saved</p>');
  expect(r.same_saved).not.toHaveProperty('height_cm');
  expect(r.new_value.html).toContain(required);
  expect(r.new_value.html).toMatch(/id="lld_weight" name="lld_weight"[^>]*value="70"/);
  expect(r.sex.html).toContain(required);
  expect(r.blocked_saves).toBe(0);
  expect(r.answered.html).toContain('<p>Saved</p>');
  expect(r.row).toMatchObject({ height_cm: 170, weight_kg: 70, conditions_answered: 'none' });
});

// P3-36 D4: sex is the three stored choices as tap buttons that set #lld_sex. The select is sent visible and the
// buttons hidden, so the form works with scripts off; stage3-forms.js swaps them.
test('TC-FR2-25-AS-01 (sex pills)', () => {
  const r = run(`
    $GLOBALS['wpdb']->store(7, ['sex' => 'male', 'conditions_answered' => 'none']);
    return ['html' => health_get()];
  `);
  expect(r.html).toContain('<p><label for="lld_sex">Sex</label><select id="lld_sex" name="lld_sex">');
  expect(r.html).toContain('<option value="male" selected>Male</option>');
  expect(r.html).toContain('<div class="lld-control" hidden><span class="lld-control-label" id="lld_sex_caption">Sex</span><div class="lld-pill-row" data-lld-pills="lld_sex" role="group" aria-labelledby="lld_sex_caption">');
  const pills = [...r.html.matchAll(/<button type="button" data-lld-pill="([^"]+)"( class="is-active")? aria-pressed="(true|false)">([^<]+)<\/button>/g)].map((m) => [m[1], m[3], m[4]]);
  expect(pills).toEqual([['female', 'false', 'Female'], ['male', 'true', 'Male'], ['prefer-not-to-say', 'false', 'Prefer not to say']]);
  expect(r.html).not.toContain('lld-visually-hidden');
});
