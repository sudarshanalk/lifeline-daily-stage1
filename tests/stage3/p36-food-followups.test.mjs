// P3-36 follow-ups from the lead's review of #264 (Food page). The real interface, food-log and recipes classes run in
// PHP CLI with WordPress, the data layer, the safety matrix and the terms gate stubbed. Made-up rows only.
//   1. FR3-68: a Free member can delete their own custom food (adding and changing one stay Premium).
//   2. FR3-69: for an eating-disorder flag the interface leaves out amounts and "days on target", as the page does.
//   3. FR3-29 AS-03: the over-target sentence is the signed text, "{n} kcal over today".
//   4. FR3-35 AS-03: a recipe ingredient's amount can be changed in place.
//   5. FR3-113: the members' interface refuses a member who has not accepted the terms, as the screens do.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const src = (name) => readFileSync(stage3(name), 'utf8');
const js = readFileSync(resolve(plugin, 'assets/stage3-logging.js'), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

// Member 5 owns custom food 41, recipe 31 and food-log entry 61; any other id is someone else's (no row found).
// $GLOBALS['ed'] turns the eating-disorder flag on; $GLOBALS['needs_terms'] makes the terms gate name member 5.
const stubs = `
define('ABSPATH', '/');
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
class Req implements ArrayAccess {
  public $p; public $body; function __construct($p, $body = []) { $this->p = $p; $this->body = $body; }
  function offsetExists($k): bool { return isset($this->p[$k]); } function offsetGet($k): mixed { return $this->p[$k] ?? null; }
  function offsetSet($k, $v): void { $this->p[$k] = $v; } function offsetUnset($k): void { unset($this->p[$k]); }
  function get_json_params() { return $this->body; } function get_method() { return $this->p['method']; }
  function get_param($k) { return $this->p[$k] ?? null; } function get_query_params() { return []; }
}
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
function wp_json_encode($v) { return json_encode($v); } function home_url($p = '') { return 'https://stg.example.test' . $p; }
function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\\-]/', '', (string) $v)); }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { static function enabled($f, $m = 0) { return true; } }
class LLD_Members_Access {
  static function current_member_id() { return 5; } static function has_health_consent($m) { return true; }
  static function access_state($m) { return $GLOBALS['state']; }
}
class LLD_Members_Stage3_Safety {
  static function applies($m, $f) { return $GLOBALS['ed'] ? ['active' => true, 'hidden' => ['calories', 'targets'], 'notes' => []] : ['active' => false, 'hidden' => [], 'notes' => []]; }
  static function nutrition_hidden(array $a) { $all = !empty($a['active']) && in_array('calories', $a['hidden'], true); return ['all' => $all, 'protein' => $all]; }
}
class LLD_Members_Terms_Gate { static function member_needing_terms() { return $GLOBALS['needs_terms'] ? 5 : 0; } }
function own_row($t, $id) {
  if ('custom_foods' === $t && 41 === $id) { return ['id' => 41, 'member_id' => 5, 'name' => 'Fixture flapjack', 'portion_g' => 50, 'kcal' => 200, 'deleted_at' => null]; }
  if ('recipes' === $t && 31 === $id) { return ['id' => 31, 'member_id' => 5, 'name' => 'Fixture stew', 'deleted_at' => null]; }
  if ('food_log' === $t && 61 === $id) { return ['id' => 61, 'member_id' => 5, 'food_id' => 4, 'food_kind' => 'food', 'meal' => 'lunch', 'entry_date' => '2026-11-03', 'grams' => 150, 'portion_label' => '150 g', 'kcal' => null, 'deleted_at' => null]; }
  return null;
}
class LLD_Members_Stage3_Data {
  public static $deleted = []; public static $updated = []; public static $created = [];
  static function row($t, $m, $id, $sealed = []) { return 5 === (int) $m ? own_row($t, (int) $id) : null; }
  static function rows($t, $m, $sealed = [], $since = '', $limit = 500) { return 'food_log' === $t ? [own_row('food_log', 61)] : []; }
  static function soft_delete($t, $m, $id) { self::$deleted[] = [$t, (int) $m, (int) $id]; return true; }
  static function update($t, $m, $id, $f) { self::$updated[] = [$t, (int) $id]; return true; }
  static function insert($t, $m, $f, $sealed = []) { self::$created[] = $t; return 99; }
}
require ${toPhpPath(stage3('calculators/class-calorie-target.php'))};
require ${toPhpPath(stage3('class-food-log.php'))};
require ${toPhpPath(stage3('class-recipes.php'))};
require ${toPhpPath(stage3('class-api.php'))};
$GLOBALS['state'] = 'free'; $GLOBALS['ed'] = false; $GLOBALS['needs_terms'] = false;
function call($method, $type, $id = null, $body = ['name' => 'Renamed']) {
  LLD_Members_Stage3_Data::$deleted = []; LLD_Members_Stage3_Data::$updated = []; LLD_Members_Stage3_Data::$created = [];
  $p = ['type' => $type, 'method' => strtoupper($method)];
  if (null !== $id) { $p['id'] = $id; }
  $req = new Req($p, $body);
  $fn = ['delete' => 'delete', 'patch' => 'patch', 'post' => 'create', 'read' => 'read', 'list' => 'list_rows'][$method];
  $res = LLD_Members_Stage3_API::$fn($req);
  return ['status' => $res->status, 'data' => $res->data, 'deleted' => LLD_Members_Stage3_Data::$deleted, 'updated' => LLD_Members_Stage3_Data::$updated, 'created' => LLD_Members_Stage3_Data::$created];
}
function amounts($doc, $type) { $m = new ReflectionMethod('LLD_Members_Stage3_API', 'amounts_shown'); $m->setAccessible(true); return $m->invoke(null, $doc, $type, 5); }
`;
const run = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 }).trim());

// ---- 1. FR3-68: delete your own custom food ---------------------------------------------------------------------

test('TC-FR3-68-AS-02 (custom foods): a Free member can delete their own custom food, and still cannot change one', () => {
  const r = run(`
    $GLOBALS['state'] = 'free';
    $out = ['free_delete' => call('delete', 'custom-foods', 41), 'free_other' => call('delete', 'custom-foods', 77), 'free_patch' => call('patch', 'custom-foods', 41)];
    $GLOBALS['state'] = 'Premium';
    $out['premium_delete'] = call('delete', 'custom-foods', 41);
    return $out;
  `);
  expect(r.free_delete.status).toBe(200);
  expect(r.free_delete.data).toEqual({ deleted: 41 });
  expect(r.free_delete.deleted).toEqual([['custom_foods', 5, 41]]);
  // Only their own: another member's food is not found, and nothing is deleted.
  expect(r.free_other.status).toBe(404);
  expect(r.free_other.deleted).toEqual([]);
  // Changing a custom food stays Premium.
  expect(r.free_patch.status).toBe(403);
  expect(r.free_patch.data.message).toBe('Custom foods are part of Premium.');
  expect(r.free_patch.updated).toEqual([]);
  expect(r.premium_delete.status).toBe(200);
});

// ---- 2. FR3-69: no amounts and no days on target for an eating-disorder flag ------------------------------------

test('TC-FR3-69-AS-02 (amounts): the interface leaves out portion amounts and days on target for an eating-disorder flag, as the Food page does', () => {
  const r = run(`
    $day = ['date' => '2026-11-03', 'meals' => ['breakfast' => [], 'lunch' => [['id' => 61, 'name' => 'Fixture rice', 'grams' => 150, 'portion_label' => '150 g', 'kcal' => null]], 'dinner' => [], 'snacks' => []]];
    $week = ['week_start' => '2026-11-02', 'logged_days' => 3, 'days_on_target' => 2, 'averages' => null, 'tip' => 'Fixture tip'];
    $recent = ['foods' => [['food_id' => 4, 'food_kind' => 'food', 'name' => 'Fixture rice', 'grams' => 150]]];
    $out = [];
    $GLOBALS['ed'] = true;
    $out['ed'] = ['day' => amounts($day, 'food-day'), 'week' => amounts($week, 'food-week'), 'recent' => amounts($recent, 'food-recent'), 'read' => call('read', 'food-log', 61), 'list' => call('list', 'food-log')];
    $GLOBALS['ed'] = false;
    $out['none'] = ['day' => amounts($day, 'food-day'), 'week' => amounts($week, 'food-week'), 'recent' => amounts($recent, 'food-recent'), 'read' => call('read', 'food-log', 61)];
    return $out;
  `);
  const lunch = r.ed.day.meals.lunch[0];
  expect(lunch.name).toBe('Fixture rice');
  expect(lunch).not.toHaveProperty('grams');
  expect(lunch).not.toHaveProperty('portion_label');
  expect(r.ed.week.days_on_target).toBeNull();
  expect(r.ed.week.logged_days).toBe(3);
  expect(r.ed.recent.foods[0]).not.toHaveProperty('grams');
  expect(r.ed.recent.foods[0].name).toBe('Fixture rice');
  expect(r.ed.read.status).toBe(200);
  expect(r.ed.read.data).not.toHaveProperty('grams');
  expect(r.ed.read.data).not.toHaveProperty('portion_label');
  expect(r.ed.list.status).toBe(200);
  expect(r.ed.list.data.rows[0]).not.toHaveProperty('grams');
  // No flag: everything as before.
  expect(r.none.day.meals.lunch[0].grams).toBe(150);
  expect(r.none.week.days_on_target).toBe(2);
  expect(r.none.recent.foods[0].grams).toBe(150);
  expect(r.none.read.data.grams).toBe(150);
  // The same check as the page: the page's eating-disorder mode is nutrition_hidden(...)['all'] on the food tracker.
  const screen = src('screens/class-food-screen.php');
  expect(screen).toContain("LLD_Members_Stage3_Safety::nutrition_hidden( $safety )");
  expect(screen).toContain("$ed_mode      = ! empty( $hidden['all'] );");
  const api = src('class-api.php');
  expect(api).toContain("LLD_Members_Stage3_Safety::nutrition_hidden( LLD_Members_Stage3_Safety::applies( $member_id, 'food_tracker' ) )");
  expect(api).toContain("$rows = self::amounts_shown( $rows, (string) $request['type'], $member_id );");
  // The page never reads an amount back in this mode: it logs with a fixed amount.
  expect(js).toContain("var g = state.edMode ? 100 : Number(els.portionGrams ? els.portionGrams.value : 100);");
});

// ---- 3. FR3-29 AS-03: the signed over-target sentence -----------------------------------------------------------

test('TC-FR3-29-AS-03 (signed wording): the over-target note is "{n} kcal over today" on the interface, the page and the script', () => {
  const r = run(`return ['note' => LLD_Members_Food_Log::day([['entry_date' => '2026-11-03', 'meal' => 'lunch', 'kcal' => 243, 'protein_g' => 1, 'carb_g' => 1, 'fat_g' => 1, 'fibre_g' => 1]], '2026-11-03', ['kcal' => 200])['over_note'], 'big' => LLD_Members_Food_Log::over_note(1250)];`);
  expect(r.note).toBe('43 kcal over today');
  expect(r.big).toBe('1250 kcal over today');
  // The same text the safety matrix signs (approvals/stage3/safety-matrix.json holds its hash).
  expect(src('class-safety-stage3.php')).toContain("'food-tracker.over-target'                => '{n} kcal over today',");
  expect(src('class-food-log.php')).toContain("const OVER_NOTE = '{n} kcal over today';");
  const screen = src('screens/class-food-screen.php');
  expect(screen).toContain('LLD_Members_Food_Log::over_note( round( (float) $over[\'kcal\'] ) )');
  for (const text of [screen, js, src('class-food-log.php')]) {
    expect(text).not.toContain('Over by');
  }
  expect(js).toContain("fmtNum(over.kcal) + ' kcal over today</span>'");
});

// ---- 4. FR3-35 AS-03: change an ingredient's amount in place ----------------------------------------------------

test('TC-FR3-35-AS-03 (in place): an ingredient amount on the member\'s recipe is changed in place and saved through the recipe update route', () => {
  const editor = js.slice(js.indexOf('function renderRecipeIngredients()'), js.indexOf('function resetRecipeEditor()'));
  // Each ingredient has Edit (not in eating-disorder mode, where no amount is shown) next to Remove.
  expect(editor).toContain('data-lld-edit-ing="');
  expect(editor).toContain("state.edMode\n\t\t\t\t\t\t? ''");
  // Editing shows the amount box with Save and Cancel; plain labels only.
  expect(editor).toContain('data-lld-ing-grams="');
  expect(editor).toContain('">Save</button>');
  expect(editor).toContain('">Cancel</button>');
  expect(editor).toContain('min="0.1" max="5000"');
  // Saving scales the ingredient's nutrients with the new amount and keeps it in the list.
  expect(editor).toContain('var fac = old > 0 ? g / old : 1;');
  expect(editor).toContain('ing.grams = g;');
  // The recipe is then saved with the existing PATCH /me/recipes/{id} (it works the values out again from the foods).
  expect(js).toContain("var path = isEdit ? 'recipes/' + state.editingRecipeId : 'recipes';");
  expect(js).toContain("var method = isEdit ? 'PATCH' : 'POST';");
  expect(js).toContain('saveIngredientAmount(state.editingIngIdx);');
  const api = src('class-api.php');
  expect(api).toContain('$filled = LLD_Members_Recipes::for_member( $member_id, $merged );');
  // No new wording beyond the button labels: every other string in the new code was already on the page.
  expect(editor).not.toMatch(/calorie|healthy|safe|risk/i);
});

// ---- 5. FR3-113: terms first in the members' interface ----------------------------------------------------------

test('TC-FR3-113-AS-02 (members\' interface): a member who has not accepted the terms is refused by the /me/ routes, and can still delete their own items', () => {
  const r = run(`
    $GLOBALS['state'] = 'Premium';
    $GLOBALS['needs_terms'] = true;
    $out = ['list' => call('list', 'food-log'), 'read' => call('read', 'food-log', 61), 'create' => call('post', 'custom-foods', null, ['name' => 'Fixture bar', 'portion_g' => 40]), 'patch' => call('patch', 'recipes', 31), 'delete' => call('delete', 'recipes', 31)];
    $GLOBALS['needs_terms'] = false;
    $out['accepted'] = call('read', 'food-log', 61);
    return $out;
  `);
  for (const key of ['list', 'read', 'create', 'patch']) {
    expect(r[key].status).toBe(403);
    expect(r[key].data.code).toBe('lld_terms_required');
    expect(r[key].data.link).toBe('https://stg.example.test/members/agreements/');
  }
  expect(r.create.created).toEqual([]);
  expect(r.patch.updated).toEqual([]);
  // A member can always delete their own data (FR3-68; export and erasure are their own pages, left open by the gate).
  expect(r.delete.status).toBe(200);
  expect(r.delete.deleted).toEqual([['recipes', 5, 31]]);
  expect(r.accepted.status).toBe(200);
  // The same rule as the screens, and the calculators route checks it too.
  const api = src('class-api.php');
  expect(api).toContain('LLD_Members_Terms_Gate::member_needing_terms()');
  expect(api).toContain("$terms = self::terms_refusal( $member_id, 'POST' );");
  expect(api).toContain("$terms = self::terms_refusal( $member_id, self::method( $request ) );");
  // What is open to the member (/me/features, /me/access) is not behind the terms.
  const features = api.slice(api.indexOf('public static function features()'), api.indexOf('private static function gate('));
  expect(features).not.toContain('terms_refusal');
});
