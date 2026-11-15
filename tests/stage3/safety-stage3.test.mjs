// Stage 3 safety matrix (P3-03; FR3-69, FR3-46, FR3-44, FR3-45, NFR3-14): the rows, the warning levels and the wording,
// run in PHP CLI with stubbed WordPress and Stage 2 classes; the wording is checked against the signed approval record
// (approvals/stage3/safety-matrix.json). The glucose rule R-SAFE-02 (fixture FX-DR-SAFE-02-01, pending) is tested here.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const src = (name) => readFileSync(stage3(name), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const approval = json('approvals/stage3/safety-matrix.json');
const bpApproval = json('approvals/stage3/bp-check.json'); // P3-58: the blood-pressure wording for logged readings too (P3-51)

// WordPress and Stage 2 stubs: switches on for member 7, off for member 8; the profile row is whatever the test sets.
const stubs = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
$GLOBALS['filters'] = [];
function add_action() {} function add_filter($h, $cb) { $GLOBALS['filters'][$h] = $cb; }
function apply_filters($h, $v, ...$a) { return isset($GLOBALS['af'][$h]) ? ($GLOBALS['af'][$h])($v, ...$a) : $v; } // a test may hook one filter
function wp_json_encode($v) { return json_encode($v); }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { public static $on = [7 => true, 8 => false]; static function enabled($f, $m = 0) { return !empty(self::$on[$m]); } static function state($f) { return 'test_accounts'; } }
class LLD_Members_Safety { public static $stage2 = []; static function situations($m) { return self::$stage2[$m] ?? ['none']; } }
class LLD_Members_Account { public static $consent = [7 => true, 8 => true]; static function has_consent($m, $t) { return !empty(self::$consent[$m]); } }
class FakeDb { public $profile = []; function prepare($q, ...$a) { return $a[0] ?? $q; } function get_row($m) { return $this->profile[$m] ?? null; } function get_var() { return null; } }
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(stage3('engine/class-rule-catalogue.php'))};
require ${toPhpPath(stage3('engine/class-decision-engine.php'))};
require ${toPhpPath(stage3('class-safety-stage3.php'))};
function profile($m, $conditions, $age = 40, $h = 170, $w = 70, $answered = 'answered') { $GLOBALS['wpdb']->profile[$m] = (object) ['conditions' => json_encode($conditions), 'conditions_answered' => $answered, 'age' => $age, 'height_cm' => $h, 'weight_kg' => $w]; }
`;
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const run = (code) => JSON.parse(php(`echo json_encode((function () { ${code} })());`));

test('NFR3-14 (wording): every signed string in the Stage 3 matrix hashes to the approval record', () => {
  const M = run('return LLD_Members_Stage3_Safety::MESSAGES;');
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  expect(approval.decisions_ratified).toEqual(['D3-36', 'D3-37', 'D3-38', 'D3-39', 'D3-40', 'D3-41', 'D3-42']);
  // The two new profile questions and their effect notes are the signed ones (D3-38).
  const Q = run('return LLD_Members_Stage3_Safety::PROFILE_QUESTIONS;');
  expect(Q['heart-rate-medicine']).toMatchObject({ question: 'profile.heart-rate-medicine.question', effect_note: 'profile.heart-rate-medicine.effect-note', answers: ['yes', 'no', 'not_sure'], situation: 'heart_rate_medicine' });
  expect(Q['heart-disease']).toMatchObject({ question: 'profile.known-heart-disease.question', effect_note: 'profile.known-heart-disease.effect-note', answers: ['yes', 'no'], situation: 'heart_disease' });
  // Stage 2's matrix is not edited by this package: its class is read, never changed, and the Stage 3 class never
  // redefines a Stage 2 row's content tags.
  expect(src('class-safety-stage3.php')).not.toMatch(/launch_rules|calorie-mention|portion-question/);
  expect(src('class-safety-stage3.php')).toMatch(/LLD_Members_Safety::situations\( \$member_id \)/);
});

test('TC-FR3-69-AS-01', () => {
  // For a test member with an eating-disorder flag, no calorie number, target, allowance or portion question appears on
  // any tracker, plan, recipe or dashboard screen: the matrix hides them all, and the rows the interface serves to the
  // screens carry no calorie or nutrient value.
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['eating-disorder']]; profile(7, ['eating-disorder']);
    $food = LLD_Members_Stage3_Safety::applies(7, 'food_tracker');
    $goals = LLD_Members_Stage3_Safety::applies(7, 'goals');
    $logging = LLD_Members_Stage3_Safety::applies(7, 'health_logging');
    $rows = LLD_Members_Stage3_Safety::api_rows([['id' => 1, 'entry_date' => '2026-11-10', 'meal' => 'lunch', 'grams' => 120, 'kcal' => 310, 'protein_g' => 12, 'carb_g' => 40, 'fat_g' => 9, 'fibre_g' => 4]], 'food-log', 7);
    $recipe = LLD_Members_Stage3_Safety::api_rows(['id' => 3, 'name' => 'Soup', 'servings' => 4, 'kcal_per_serving' => 220, 'protein_per_serving' => 8], 'recipes', 7);
    $plan = LLD_Members_Stage3_Safety::api_rows([['id' => 2, 'goal_id' => 1, 'calorie_target' => 1600, 'protein_g' => 80, 'activity_minutes_week' => 150]], 'plans', 7);
    $noEd = LLD_Members_Stage3_Safety::api_rows([['id' => 1, 'kcal' => 310]], 'food-log', 8);
    return compact('food', 'goals', 'logging', 'rows', 'recipe', 'plan', 'noEd');
  `);
  expect(r.food.active).toBe(true);
  expect(r.food.situations).toEqual(['eating_disorder']);
  for (const item of ['calories', 'targets', 'left_today', 'portion_questions', 'calorie_recipe_suggestions', 'nutrient_totals', 'weekly_nutrition_numbers', 'recipe_calories', 'barcode_calories']) expect(r.food.hidden, item).toContain(item);
  expect(r.food.notes).toEqual(['ed.food-tracker.diary-heading', 'ed.food-tracker.calorie-total']);
  expect(r.goals.refused).toEqual(expect.arrayContaining(['goal:lose_weight', 'goal:gain_weight', 'suggest:weight', 'plan:weight_loss', 'step:weigh_in']));
  expect(r.goals.notes).toContain('ed.weight-goal-screen');
  expect(r.logging.hidden).toEqual(expect.arrayContaining(['weigh_in_prompt', 'weight_trend_insight', 'dashboard_bmi']));
  expect(r.rows[0]).toEqual({ id: 1, entry_date: '2026-11-10', meal: 'lunch', grams: 120, kcal: null, protein_g: null, carb_g: null, fat_g: null, fibre_g: null });
  expect(r.recipe).toEqual({ id: 3, name: 'Soup', servings: 4, kcal_per_serving: null, protein_per_serving: null });
  expect(r.plan[0]).toEqual({ id: 2, goal_id: 1, calorie_target: null, protein_g: null, activity_minutes_week: 150 });
  expect(r.noEd[0].kcal).toBe(310); // a member the matrix is off for sees Stage 2 behaviour (nothing stripped)
  // The interface applies the rows filter on every list and single read, and on the saved calculator results
  // history (P5B, #204): class-api.php, one line each.
  const api = src('class-api.php');
  expect(api.match(/apply_filters\( 'lld_members_stage3_api_rows'/g)).toHaveLength(3);
  expect(api).toMatch(/apply_filters\( 'lld_members_stage3_api_rows', \$rows, \(string\) \$request\['type'\], \$member_id \)/);
  expect(api).toMatch(/apply_filters\( 'lld_members_stage3_api_rows', \$row, \(string\) \$request\['type'\], \$member_id \)/);
  expect(api).toMatch(/apply_filters\( 'lld_members_stage3_api_rows', \$view, 'calculator-results', \$member_id \)/);
  expect(src('loader.php')).toMatch(/LLD_Members_Stage3_Safety::boot\(\)/);
  expect(src('class-safety-stage3.php')).toMatch(/add_filter\( 'lld_members_stage3_api_rows'/);
});

test('TC-FR3-69-AS-01 (Healthy Weight food types): the drinks list, the repeat meal and a logged drink lose their numbers in the interface rows', () => {
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['eating-disorder'], 9 => ['kidney']]; profile(7, ['eating-disorder']); profile(9, ['kidney']); LLD_Members_Features::$on[9] = true; LLD_Members_Account::$consent[9] = true;
    $drinks = ['drinks' => [['key' => 'wine_175', 'label' => 'Glass of wine, 175 ml', 'ml' => 175, 'kind' => 'drink', 'food_id' => 5, 'kcal' => 140]]];
    $repeat = ['meal' => ['meal' => 'lunch', 'from_date' => '2026-11-10', 'items' => 2, 'kcal' => 390, 'times' => 2]];
    $drink = ['id' => 4, 'entry' => ['entry_date' => '2026-11-12', 'portion_label' => 'Glass of wine, 175 ml', 'kcal' => 140, 'protein_g' => 0.2, 'carb_g' => 3.5, 'fat_g' => 0, 'fibre_g' => 0]];
    $out = [];
    foreach ([7, 8, 9] as $m) $out[$m] = [LLD_Members_Stage3_Safety::api_rows($drinks, 'food-drinks', $m), LLD_Members_Stage3_Safety::api_rows($repeat, 'food-repeat', $m), LLD_Members_Stage3_Safety::api_rows($drink, 'food-drink', $m)];
    return $out;
  `);
  const [drinks, repeat, drink] = r[7];
  expect(drinks.drinks[0]).toMatchObject({ key: 'wine_175', label: 'Glass of wine, 175 ml', kcal: null, nutrition_hidden: true });
  expect(repeat.meal).toMatchObject({ meal: 'lunch', kcal: null, nutrition_hidden: true });
  expect(drink.entry).toMatchObject({ portion_label: 'Glass of wine, 175 ml', kcal: null, protein_g: null, carb_g: null, fat_g: null, fibre_g: null, nutrition_hidden: true });
  expect(r[8][0].drinks[0].kcal).toBe(140); // the matrix off: Stage 2 behaviour
  expect(r[9][2].entry).toMatchObject({ kcal: 140, protein_g: null }); // kidney disease: only protein goes
});

test('TC-FR3-69-AS-01 (a situation added by the filter): what lld_members_stage3_safety_situations adds hides the numbers in every food type and refuses the targets', () => {
  // The Healthy Weight Check's "yes" (LLD_Members_HW_Answers::safety_situations) arrives through this filter; member 7
  // has no Stage 2 eating-disorder flag.
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['none']]; profile(7, []);
    $before = LLD_Members_Stage3_Safety::situations(7);
    $GLOBALS['af']['lld_members_stage3_safety_situations'] = function ($s, $m) { return 7 === (int) $m ? array_merge($s, ['eating_disorder']) : $s; };
    $log = LLD_Members_Stage3_Safety::api_rows([['id' => 1, 'kcal' => 310, 'protein_g' => 12]], 'food-log', 7);
    $recent = LLD_Members_Stage3_Safety::api_rows([['id' => 1, 'kcal' => 310]], 'food-recent', 7);
    $search = LLD_Members_Stage3_Safety::api_rows(['enabled' => true, 'foods' => [['id' => 5, 'name' => 'Rice', 'kcal' => 130]]], 'food-search', 7);
    $barcode = LLD_Members_Stage3_Safety::api_rows(['food' => ['name' => 'Bar', 'per' => ['kcal' => 250]]], 'food-barcode', 7);
    $recipe = LLD_Members_Stage3_Safety::api_rows(['id' => 3, 'name' => 'Soup', 'kcal_per_serving' => 220], 'recipes', 7);
    $plan = LLD_Members_Stage3_Safety::api_rows([['id' => 2, 'calorie_target' => 1600]], 'plans', 7);
    $targets = LLD_Members_Stage3_Safety::api_refuse(null, 'targets', 7);
    return ['before' => $before, 'after' => LLD_Members_Stage3_Safety::situations(7), 'log' => $log[0]['kcal'], 'recent' => $recent[0]['kcal'], 'search' => $search['foods'][0]['kcal'],
      'barcode' => $barcode['food']['per']['kcal'], 'recipe' => $recipe['kcal_per_serving'], 'plan' => $plan[0]['calorie_target'], 'targets' => $targets ? $targets->status : null];
  `);
  expect(r.before).not.toContain('eating_disorder');
  expect(r.after).toContain('eating_disorder');
  expect(r).toMatchObject({ log: null, recent: null, search: null, barcode: null, recipe: null, plan: null, targets: 403 });
  expect(src('class-safety-stage3.php')).toContain("apply_filters( 'lld_members_stage3_safety_situations', $out, $member_id )");
});

test('TC-FR3-69-AS-02', () => {
  // For the same member, requests for calorie data through the programming interface are refused: the nutrition
  // targets type answers 403 for an eating-disorder member, before any row is read, and the engine never fires a
  // calorie-, weight- or portion-tagged rule for them.
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['eating-disorder']]; profile(7, ['eating-disorder']);
    $refused = LLD_Members_Stage3_Safety::api_refuse(null, 'targets', 7);
    $readings = LLD_Members_Stage3_Safety::api_refuse(null, 'readings', 7);
    $off = LLD_Members_Stage3_Safety::api_refuse(null, 'targets', 8);
    $rules = [];
    foreach (['R-INS-06', 'R-INS-07', 'R-INS-08', 'R-ADJ-01', 'R-ADJ-02', 'R-STEP-02', 'R-INS-01', 'R-SAFE-03', 'R-SAFE-04'] as $n) $rules[$n] = LLD_Members_Stage3_Safety::allows_rule(['eating_disorder'], $n);
    return ['refused' => $refused ? [$refused->status, $refused->data] : null, 'readings' => $readings, 'off' => $off, 'rules' => $rules];
  `);
  expect(r.refused).toEqual([403, { message: 'Not available.' }]);
  expect(r.readings).toBeNull();
  expect(r.off).toBeNull();
  expect(r.rules).toEqual({ 'R-INS-06': false, 'R-INS-07': false, 'R-INS-08': false, 'R-ADJ-01': false, 'R-ADJ-02': false, 'R-STEP-02': false, 'R-INS-01': true, 'R-SAFE-03': true, 'R-SAFE-04': true });
  // The gate applies the refusal after consent and before the data (safety first, access second, data third).
  const api = src('class-api.php');
  const gate = api.slice(api.indexOf('private static function gate'), api.indexOf('public static function list_rows'));
  expect(gate).toMatch(/apply_filters\( 'lld_members_stage3_api_refuse', null, \$type, \$member_id \)/);
  expect(gate.indexOf('has_health_consent')).toBeLessThan(gate.indexOf('lld_members_stage3_api_refuse'));
  expect(gate.indexOf('lld_members_stage3_api_refuse')).toBeLessThan(gate.indexOf('return array( $member_id, $def )'));
});

test('TC-FR3-69-AS-03', () => {
  // For a test member with kidney disease, no protein target appears anywhere: hidden in the food tracker, stripped
  // from the targets and plan rows the interface serves, the protein rule R-INS-07 silenced, the calorie calculator's
  // protein line hidden; calories, carbohydrate, fat and fibre stay.
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['kidney']]; profile(7, ['kidney']);
    $food = LLD_Members_Stage3_Safety::applies(7, 'food_tracker');
    $targets = LLD_Members_Stage3_Safety::api_rows([['id' => 1, 'kcal' => 1800, 'protein_g' => 90, 'carb_g' => 200, 'fat_g' => 60, 'fibre_g' => 30]], 'targets', 7);
    $plan = LLD_Members_Stage3_Safety::api_rows([['id' => 2, 'calorie_target' => 1800, 'protein_g' => 90, 'fibre_g' => 30]], 'plans', 7);
    $refused = LLD_Members_Stage3_Safety::api_refuse(null, 'targets', 7);
    $engine = LLD_Members_Decision_Engine::rule('R-INS-07', ['today' => '2026-11-16', 'situations' => ['kidney'], 'targets' => ['protein_g' => 60]]);
    $fibre = LLD_Members_Stage3_Safety::allows_rule(['kidney'], 'R-INS-06');
    $calc = LLD_Members_Stage3_Safety::applies(7, 'calculators_new');
    return compact('food', 'targets', 'plan', 'refused', 'engine', 'fibre', 'calc');
  `);
  expect(r.food.hidden).toEqual(['protein_target']);
  expect(r.food.notes).toEqual(['stage1_kidney_message']);
  expect(r.targets[0]).toEqual({ id: 1, kcal: 1800, protein_g: null, carb_g: 200, fat_g: 60, fibre_g: 30 });
  expect(r.plan[0]).toEqual({ id: 2, calorie_target: 1800, protein_g: null, fibre_g: 30 });
  expect(r.refused).toBeNull(); // the targets type itself stays (calories, carbohydrate, fat and fibre are shown)
  expect(r.engine).toMatchObject({ fires: false, reason: 'safety_check:kidney' });
  expect(r.fibre).toBe(true);
  expect(r.calc.hidden).toEqual(['calorie-target:protein_line']);
});

test('TC-FR3-69-AS-04', () => {
  // Add the eating-disorder flag to a member who already has a weight-loss plan: the rules apply at once. The matrix
  // reads the profile on every call (no cache), the calorie target vanishes from the plan rows the next read serves, the
  // engine's calorie and weight rules are silenced that very night (ENG-07), and the runner checks the matrix again at
  // display time. Offering the safe plan itself is the goal package's screen (P3-31).
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['none']]; profile(7, []);
    $before = LLD_Members_Stage3_Safety::api_rows([['id' => 2, 'calorie_target' => 1600]], 'plans', 7);
    $flagsBefore = LLD_Members_Stage3_Safety::situations(7);
    $ruleBefore = LLD_Members_Stage3_Safety::allows_rule($flagsBefore, 'R-ADJ-02');
    LLD_Members_Safety::$stage2 = [7 => ['eating-disorder']]; profile(7, ['eating-disorder']); // the profile is saved at 10:00
    $after = LLD_Members_Stage3_Safety::api_rows([['id' => 2, 'calorie_target' => 1600]], 'plans', 7);
    $flagsAfter = LLD_Members_Stage3_Safety::situations(7);
    $ruleAfter = LLD_Members_Stage3_Safety::allows_rule($flagsAfter, 'R-ADJ-02');
    $goals = LLD_Members_Stage3_Safety::applies(7, 'goals');
    return compact('before', 'flagsBefore', 'ruleBefore', 'after', 'flagsAfter', 'ruleAfter', 'goals');
  `);
  expect(r.before[0].calorie_target).toBe(1600);
  expect(r.flagsBefore).toEqual([]);
  expect(r.ruleBefore).toBe(true);
  expect(r.after[0].calorie_target).toBeNull();
  expect(r.flagsAfter).toEqual(['eating_disorder']);
  expect(r.ruleAfter).toBe(false);
  expect(r.goals.refused).toContain('plan:weight_loss');
  const safety = src('class-safety-stage3.php');
  expect(safety).not.toMatch(/wp_cache|transient|static \$cache|self::\$cache/);
  const runner = src('engine/class-insights-runner.php');
  expect(runner.indexOf('LLD_Members_Decision_Engine::evaluate( $state )')).toBeLessThan(runner.indexOf('$situations = LLD_Members_Stage3_Safety::situations( $member_id )'));
  expect(runner.match(/LLD_Members_Stage3_Safety::allows_rule\( \$situations/g).length).toBeGreaterThanOrEqual(4);
});

test('TC-FR3-69-AS-01 (Unknown row): a consented member who has not answered the conditions gets safety only', () => {
  // P3-03 Part B row 1: no insights, no Today step from logs, no adjustments for a member without health consent, without
  // a profile row, or with conditions not answered; the safety rules still run. The runner withholds every non-safety
  // write for them (review must-fix 1).
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['unknown']];
    profile(7, [], 40, 170, 70, 'not_answered');
    $unanswered = LLD_Members_Stage3_Safety::situations(7);
    $rules = [];
    foreach (['R-SAFE-01', 'R-SAFE-03', 'R-SAFE-04', 'R-STEP-01', 'R-STEP-05', 'R-ADJ-03', 'R-INS-01', 'R-INS-09', 'R-INS-10'] as $n) $rules[$n] = LLD_Members_Stage3_Safety::allows_rule($unanswered, $n);
    unset($GLOBALS['wpdb']->profile[7]);
    $noRow = LLD_Members_Stage3_Safety::situations(7);
    LLD_Members_Safety::$stage2 = [7 => ['diabetes']]; profile(7, ['type-2-diabetes']); LLD_Members_Account::$consent[7] = false;
    $noConsent = LLD_Members_Stage3_Safety::situations(7);
    LLD_Members_Account::$consent[7] = true;
    $row = LLD_Members_Stage3_Safety::applies(7, 'insights', ['situations' => ['unknown']]);
    return compact('unanswered', 'rules', 'noRow', 'noConsent', 'row');
  `);
  expect(r.unanswered).toEqual(['unknown']);
  expect(r.rules).toEqual({ 'R-SAFE-01': true, 'R-SAFE-03': true, 'R-SAFE-04': true, 'R-STEP-01': false, 'R-STEP-05': false, 'R-ADJ-03': false, 'R-INS-01': false, 'R-INS-09': false, 'R-INS-10': false });
  expect(r.noRow).toEqual(['unknown']);
  expect(r.noConsent).toEqual(['unknown']);
  expect(r.row.refused).toEqual(['insights', 'today_step_from_logs']);
  const runner = src('engine/class-insights-runner.php');
  const run_member = runner.slice(runner.indexOf('public static function run_member('), runner.indexOf('public static function low_mood_active('));
  expect(run_member).toMatch(/in_array\( 'unknown', \$situations, true \)[\s\S]*'safety_only_unknown'[\s\S]*return \$summary;/);
  expect(run_member.indexOf("'safety_only_unknown'")).toBeLessThan(run_member.indexOf('write_today_step'));
});

test('TC-FR3-69-AS-01 (new rows): the two new questions apply when they are the only conditions', () => {
  // The Stage 2 list knows no key for the new questions and answers "unknown" for a member whose only condition is one
  // of them; the Stage 3 matrix reads the profile row itself, so known heart disease, heart-rate medicine ("Yes" or
  // "Not sure"), age 65 and over and BMI under 18.5 all apply (review must-fix 2).
  const r = run(`
    LLD_Members_Safety::$stage2 = [7 => ['unknown']];
    profile(7, ['heart-disease']);
    $heart = LLD_Members_Stage3_Safety::situations(7);
    profile(7, ['heart-rate-medicine:not_sure']);
    $notSure = LLD_Members_Stage3_Safety::situations(7);
    profile(7, ['heart-rate-medicine'], 70, 180, 55);
    $three = LLD_Members_Stage3_Safety::situations(7);
    profile(7, [], 66, 170, 70, 'none');
    $none = LLD_Members_Stage3_Safety::situations(7);
    $calc = LLD_Members_Stage3_Safety::applies(7, 'calculators_new', ['situations' => $heart]);
    return compact('heart', 'notSure', 'three', 'none', 'calc');
  `);
  expect(r.heart).toEqual(['heart_disease']);
  expect(r.notSure).toEqual(['heart_rate_medicine']);
  expect(r.three).toEqual(['heart_rate_medicine', 'age_65_plus', 'bmi_under_18_5']);
  expect(r.none).toEqual(['age_65_plus']);
  expect(r.calc.refused).toEqual(['heart-risk', 'fitness-age', 'hr-zones']);
});

test('TC-FR3-46-AS-01', () => {
  // Record a blood pressure of 185/125: the urgent-care wording is shown at once, for Free and Premium test members
  // alike. warning_level() takes no membership and no member: the same answer for everyone, including the interface.
  const r = run(`
    $w = fn($t, $v, $f = []) => LLD_Members_Stage3_Safety::warning_level($t, $v, $f);
    return [
      $w('blood_pressure', ['value_1' => 185, 'value_2' => 125]),
      $w('blood_pressure', ['sys' => 180, 'dia' => 80]),
      $w('blood_pressure', ['sys' => 150, 'dia' => 120]),
      $w('blood_pressure', ['sys' => 165, 'dia' => 95], ['pregnancy']),
      $w('blood_pressure', ['sys' => 142, 'dia' => 92], ['pregnancy' => true]),
      $w('blood_pressure', ['sys' => 142, 'dia' => 92]),
      $w('blood_pressure', ['sys' => 85, 'dia' => 55]),
      $w('blood_pressure', ['sys' => 120, 'dia' => 80, 'pulse' => 130]),
      $w('blood_pressure', ['sys' => 120, 'dia' => 115]),
      $w('blood_pressure', ['sys' => 300, 'dia' => 80]),
      $w('blood_pressure', ['sys' => 85, 'dia' => 55], ['dizzy' => true]),
      $w('blood_pressure', ['sys' => 120, 'dia' => 80, 'pulse' => 35]),
      $w('blood_pressure', ['sys' => 120, 'dia' => 80, 'pulse' => 230]),
      LLD_Members_Stage3_Safety::BP_MESSAGES,
      $w('blood_pressure', ['sys' => 85, 'dia' => 55], ['pregnancy' => true]),
      $w('blood_pressure', ['sys' => 85, 'dia' => 55], ['pregnancy' => true, 'dizzy' => true]),
      $w('blood_pressure', ['dia' => 80]),
    ];
  `);
  const BP = r[13];
  // The signed P3-58 alert, before anything else, with its own example emergency numbers (P3-58 §4.1, D3-16).
  expect(r[0]).toEqual({ level: 'urgent', key: 'bp-check.urgent.alert', message: BP['urgent.alert'], emergency_numbers: false });
  expect(r[0].message.split('\n')[0]).toBe('Your reading is very high.');
  expect(r[1]).toMatchObject({ level: 'urgent', key: 'bp-check.urgent.alert' });
  expect(r[2]).toMatchObject({ level: 'urgent', key: 'bp-check.urgent.alert' });
  // Pregnancy: 160 or 110 is the pregnancy urgent line, 140 or 90 the same-day line (P3-58 §4.2, D3-19), as the calculator.
  expect(r[3]).toEqual({ level: 'urgent', key: 'bp-check.pregnancy.urgent', message: BP['pregnancy.urgent'], emergency_numbers: true });
  expect(r[4]).toEqual({ level: 'caution', key: 'bp-check.pregnancy.same-day', message: BP['pregnancy.same-day'], emergency_numbers: false });
  expect(r[5]).toEqual({ level: 'none', key: null, message: null, emergency_numbers: false }); // 142/92, not pregnant: the category is the calculator's
  expect(r[6]).toMatchObject({ level: 'caution', key: 'bp-check.category.normal-low.body', message: BP['category.normal-low.body'] });
  expect(r[7]).toMatchObject({ level: 'caution', key: 'bp-check.pulse.note', message: BP['pulse.note'].replace('{high|low}', 'high') });
  expect(r[8]).toMatchObject({ level: 'impossible', key: 'bp-check.refused.impossible', message: BP['refused.impossible'] }); // the bottom number not 10 below the top
  expect(r[9]).toMatchObject({ level: 'impossible', key: 'bp-check.refused.impossible' });
  expect(r[10]).toEqual({ level: 'caution', key: 'bp-check.low.dizzy', message: BP['low.dizzy'], emergency_numbers: true }); // D3-17
  expect(r[11]).toMatchObject({ level: 'caution', message: BP['pulse.note'].replace('{high|low}', 'low') }); // D3-20
  // A pulse outside 30-220 is left out and the reading checked without it (P3-58 v1.1 §4.4, D3-50): 120/80 is no warning.
  expect(r[12]).toMatchObject({ level: 'none', key: null, pulse_left_out: true, pulse_typed: '230', pulse_message: "We left out the pulse: 230 isn't possible for a resting pulse. Please check it and add it again." });
  // No category wording in pregnancy (D3-19): a low reading gives no note, but feeling dizzy still gets its line.
  expect(r[14]).toEqual({ level: 'none', key: null, message: null, emergency_numbers: false });
  expect(r[15]).toMatchObject({ level: 'caution', key: 'bp-check.low.dizzy', emergency_numbers: true });
  expect(r[16]).toMatchObject({ level: 'impossible', key: 'bp-check.refused.impossible', message: BP['refused.impossible'] }); // no top number
  // Safety is never paid for: nothing in the matrix or the engine asks about Premium.
  expect(src('class-safety-stage3.php')).not.toMatch(/has_premium|LLD_Members_Billing|LLD_Members_Access::/);
  const engine = src('engine/class-decision-engine.php');
  const safetyBlock = engine.slice(engine.indexOf('// 1. Safety rules'), engine.indexOf('// 2. Today step'));
  expect(safetyBlock).not.toMatch(/premium/);
});

test('TC-FR3-46-AS-02', () => {
  // Record a normal reading: no warning is shown, for every reading type.
  const r = run(`
    $w = fn($t, $v, $f = []) => LLD_Members_Stage3_Safety::warning_level($t, $v, $f);
    return [$w('blood_pressure', ['sys' => 122, 'dia' => 78, 'pulse' => 68]), $w('glucose', ['mmol' => 5.5]), $w('weight', ['kg' => 72.4]), $w('waist', ['cm' => 88]), $w('sleep', ['hours' => 7.5, 'avg_7_nights' => 7.1]), $w('mood', ['mood' => 4]), $w('activity', ['minutes' => 30, 'steps' => 6000]), $w('water', ['ml' => 250, 'day_total_ml' => 1750])];
  `);
  for (const x of r) expect(x).toEqual({ level: 'none', key: null, message: null, emergency_numbers: false });
});

test('TC-FR3-46-AS-03', () => {
  // The wording shown matches the frozen approved text exactly: the blood-pressure lines are P3-58's signed text, each
  // hashing to approvals/stage3/bp-check.json (the urgent alert of FR3-46 among them); every other message is the
  // matrix's own signed text; the levels are the signed ones (Part C) and are constants, not options.
  const L = run('return LLD_Members_Stage3_Safety::LEVELS;');
  expect(L.blood_pressure).toMatchObject({ impossible: { sys: [60, 260], dia: [30, 160], gap: 10 }, urgent: { sys: 180, dia: 120 }, urgent_pregnancy: { sys: 160, dia: 110 }, same_day_pregnancy: { sys: 140, dia: 90 }, low: { sys: 90, dia: 60 }, pulse: [40, 120], pulse_impossible: [30, 220] });
  const BP = run('return LLD_Members_Stage3_Safety::BP_MESSAGES;');
  expect(Object.keys(BP).sort()).toEqual(['category.normal-low.body', 'low.dizzy', 'pregnancy.same-day', 'pregnancy.urgent', 'pulse.left-out', 'pulse.note', 'refused.impossible', 'urgent.alert']);
  for (const [key, text] of Object.entries(BP)) {
    expect(bpApproval.copy_hashes[key], key).toMatch(/^[0-9a-f]{64}$/);
    expect(createHash('sha256').update(text, 'utf8').digest('hex'), key).toBe(bpApproval.copy_hashes[key]);
  }
  expect(bpApproval.clinical_signoff.decision).toBe('approved');
  expect(L.glucose).toMatchObject({ urgent_low: 3, urgent_high: 15, urgent_high_symptoms: 13.9, caution_low: [3, 3.9], caution_high: [11.1, 14.9], impossible: [1, 35] });
  expect(L.weight).toMatchObject({ impossible: [25, 350], check_change_pct: 10, check_hours: 24, bmi_under: 18.5 });
  expect(L.sleep).toMatchObject({ impossible: [0, 16], short_average_7_nights: 5 });
  expect(L.activity).toMatchObject({ impossible: [1, 600], steps_max: 100000, vigorous_day_check: 300 });
  expect(L.water).toMatchObject({ impossible: [50, 5000], day_caution_ml: 5000 });
  const safety = src('class-safety-stage3.php');
  expect(safety).not.toMatch(/get_option|apply_filters\( 'lld_members_stage3_levels/);
  expect(safety).not.toMatch(/Call your local emergency number \(999/); // the Stage 1 footer line is not re-typed here
  const r = run(`return [LLD_Members_Stage3_Safety::warning_level('glucose', ['mmol' => 2.8])['message'], LLD_Members_Stage3_Safety::warning_level('water', ['ml' => 300, 'day_total_ml' => 5200])['message']];`);
  expect(r[0]).toBe("Very low blood sugar. Take 15 g of fast sugar now (glucose tablets, sugary drink, juice) and re-test in 15 minutes. If you can't swallow safely, are confused or don't improve, someone should call your local emergency number.");
  expect(r[1]).toBe("that's a lot of water; if you're unusually thirsty, see your doctor");
});

test('TC-FR3-45-AS-03', () => {
  // Impossible values are refused with "check the numbers"; a weight more than 10 % from the previous reading within
  // 24 hours asks "check the number" without refusing (D3-40); an activity entry over 600 minutes is refused.
  const r = run(`
    $w = fn($t, $v, $f = []) => LLD_Members_Stage3_Safety::warning_level($t, $v, $f);
    return [$w('weight', ['kg' => 20]), $w('weight', ['kg' => 80, 'previous' => ['kg' => 70, 'hours_ago' => 5]]), $w('weight', ['kg' => 80, 'previous' => ['kg' => 70, 'hours_ago' => 30]]), $w('weight', ['kg' => 48], ['bmi_under_18_5']), $w('waist', ['cm' => 260]), $w('sleep', ['hours' => 17]), $w('sleep', ['hours' => 4, 'avg_7_nights' => 4.8]), $w('mood', ['mood' => 6]), $w('mood', ['mood' => 1], ['self_harm' => true]), $w('activity', ['minutes' => 650]), $w('activity', ['minutes' => 60, 'vigorous_minutes_day' => 320]), $w('activity', ['minutes' => 30, 'steps' => 120000]), $w('water', ['ml' => 6000]), $w('nonsense', ['value_1' => 1])];
  `);
  expect(r.map((x) => x.level)).toEqual(['impossible', 'check', 'none', 'caution', 'impossible', 'impossible', 'caution', 'impossible', 'urgent', 'impossible', 'check', 'impossible', 'impossible', 'impossible']);
  expect(r[3].key).toBe('healthy_weight_check.underweight');
  expect(r[6].key).toBe('sleep.short_average');
  expect(r[8].key).toBe('stage2_response_1');
});

test('TC-NFR3-17-AS-01 (R-SAFE-02, fixture FX-DR-SAFE-02-01): glucose warning levels at save time', () => {
  // The catalogue gives R-SAFE-02 no boundary data (fixture pending): test data from Part C and the sign-off scenarios
  // S6 to S8, for the owner to accept. Urgent below 3.0 with the fast-sugar line; 15.0 or more symptom-free urgent
  // (owner's choice, D3-39); 13.9 with a ticked symptom urgent; caution 3.0 to 3.9 and 11.1 to 14.9; impossible
  // outside 1 to 35 mmol/L; mg/dL converted at 18.016.
  const fx = json('fixtures/decision-rules/rules.json').fixtures.find((f) => f.id === 'FX-DR-SAFE-02-01');
  expect(fx.status).toBe('pending');
  const r = run(`
    $g = fn($v, $f = []) => LLD_Members_Stage3_Safety::warning_level('glucose', $v, $f);
    return [
      $g(['mmol' => 2.8]), $g(['mmol' => 2.9]), $g(['mmol' => 3.0]), $g(['mmol' => 3.5]), $g(['mmol' => 3.9]), $g(['mmol' => 4.0]), $g(['mmol' => 5.5]), $g(['mmol' => 11.0]),
      $g(['mmol' => 11.1]), $g(['mmol' => 12.0]), $g(['mmol' => 14.9]), $g(['mmol' => 15.0]), $g(['mmol' => 15.2]),
      $g(['mmol' => 13.9], ['symptoms' => ['vomiting']]), $g(['mmol' => 13.9], ['symptoms' => []]), $g(['mmol' => 13.8], ['symptoms' => ['drowsiness']]), $g(['mmol' => 14.0], ['symptoms' => ['headache']]),
      $g(['mmol' => 0.9]), $g(['mmol' => 35.1]), $g(['value_1' => 270, 'unit' => 'mg/dL']), $g(['value_1' => 54, 'unit' => 'mg/dL']), $g(['value_1' => 53, 'unit' => 'mg/dL']), $g(['value_1' => 100, 'unit' => 'mg/dL']),
    ];
  `);
  const levels = r.map((x) => `${x.level}:${x.key ?? '-'}`);
  expect(levels).toEqual([
    'urgent:glucose.urgent-low', 'urgent:glucose.urgent-low', 'caution:glucose.caution-low', 'caution:glucose.caution-low', 'caution:glucose.caution-low', 'none:-', 'none:-', 'none:-',
    'caution:glucose.caution-high', 'caution:glucose.caution-high', 'caution:glucose.caution-high', 'urgent:glucose.urgent-high', 'urgent:glucose.urgent-high',
    'urgent:glucose.urgent-high', 'caution:glucose.caution-high', 'caution:glucose.caution-high', 'caution:glucose.caution-high',
    'impossible:check_the_number', 'impossible:check_the_number', 'urgent:glucose.urgent-high', 'caution:glucose.caution-low', 'urgent:glucose.urgent-low', 'none:-',
  ]);
  expect(r[0].emergency_numbers).toBe(true);
  expect(r[11].emergency_numbers).toBe(true);
  expect(r[9].emergency_numbers).toBe(false);
  expect(r[12].message).toBe('Very high blood sugar. If you feel unwell, vomit, breathe fast or feel drowsy, get medical help today.');
  // The engine itself never evaluates glucose (no "in range" wording, I-12): R-SAFE-02 is answered at save time only.
  const e = run(`return [LLD_Members_Decision_Engine::rule('R-SAFE-02', ['today' => '2026-11-16', 'daily' => ['glucose' => ['2026-11-15' => 20]]]), LLD_Members_Rule_Catalogue::rule('R-SAFE-02')['template']];`);
  expect(e[0]).toMatchObject({ fires: false, reason: 'at_save_time' });
  expect(e[1]).toBeNull();
  expect(src('engine/class-decision-engine.php')).not.toMatch(/'glucose'\]\[/);
});

test('TC-FR3-44-AS-03', () => {
  // Several very low moods in a row show the owner-approved support message with help-line information: R-SAFE-04
  // fires on three consecutive logged days at 2 or under (or a PHQ-2 of 3 or more), free, with the help-line text, and
  // the low-mood row then silences every other insight for 7 days.
  const r = run(`
    $s = ['today' => '2026-11-16', 'premium' => false, 'daily' => ['mood' => ['2026-11-13' => 2, '2026-11-14' => 1, '2026-11-15' => 2]]];
    $out = LLD_Members_Decision_Engine::evaluate($s);
    $phq = LLD_Members_Decision_Engine::rule('R-SAFE-04', ['today' => '2026-11-16', 'phq2_today' => 3]);
    $phq2 = LLD_Members_Decision_Engine::rule('R-SAFE-04', ['today' => '2026-11-16', 'phq2_today' => 2]);
    $todayRun = LLD_Members_Decision_Engine::rule('R-SAFE-04', ['today' => '2026-11-16', 'daily' => ['mood' => ['2026-11-14' => 2, '2026-11-15' => 2, '2026-11-16' => 1.5]]]);
    $rounded = LLD_Members_Decision_Engine::rule('R-SAFE-04', ['today' => '2026-11-16', 'daily' => ['mood' => ['2026-11-13' => 2, '2026-11-14' => 2.1, '2026-11-15' => 2]]]);
    LLD_Members_Safety::$stage2 = [7 => ['none']]; profile(7, []);
    $row = LLD_Members_Stage3_Safety::applies(7, 'insights', ['situations' => ['low_mood']]);
    $share = LLD_Members_Stage3_Safety::applies(7, 'reminders', ['situations' => ['low_mood']]);
    return compact('out', 'phq', 'phq2', 'todayRun', 'rounded', 'row', 'share');
  `);
  expect(r.out.safety[0]).toMatchObject({ rule: 'R-SAFE-04', outcome: 'shown', suppresses_days: 7, cooldown_days: 7 });
  expect(r.out.safety[0].text).toMatch(/findahelpline\.com/);
  expect(r.out.safety[0].text).toMatch(/\*\*If you are in danger now, call your local emergency number\*\*/);
  expect(r.out.suppressed).toBe(true);
  expect(r.out.today_step.text).toBe("A gentle day. If you'd like, take a 10-minute walk outside. [Done] [Skip]");
  expect(r.phq.fires).toBe(true);
  expect(r.phq2.fires).toBe(false);
  expect(r.todayRun.fires).toBe(true); // the run may end today
  expect(r.rounded.fires).toBe(false); // 2.1 is above 2 (the rounded daily mean is what is compared)
  expect(r.row.refused).toContain('all_non_safety_insights_7_days');
  expect(r.share.hidden).toContain('share_prompt_this_week');
  // The matrix's own low-mood line keeps the FR3-44 support message as a placeholder for P3-77 (approval limitation).
  expect(run('return LLD_Members_Stage3_Safety::MESSAGES["low-mood-signal"];')).toContain('[the FR3-44 support message]');
});

test('TC-NFR3-06-AS-01 (safety matrix and engine): switched off, Stage 2 members see no change', () => {
  // With the health_logging and insights switches off for a member, applies() answers "off" with nothing hidden, the
  // interface filters change nothing, no refusal is raised, and the runner does nothing; the nightly cron event is
  // registered only while the insights switch is not off.
  const r = run(`
    LLD_Members_Safety::$stage2 = [8 => ['eating-disorder']]; profile(8, ['eating-disorder']);
    return [LLD_Members_Stage3_Safety::applies(8, 'food_tracker'), LLD_Members_Stage3_Safety::api_rows([['kcal' => 500]], 'food-log', 8), LLD_Members_Stage3_Safety::api_refuse(null, 'targets', 8), LLD_Members_Stage3_Safety::active(8, 'insights')];
  `);
  expect(r[0]).toEqual({ active: false, situations: [], hidden: [], refused: [], notes: [] });
  expect(r[1]).toEqual([{ kcal: 500 }]);
  expect(r[2]).toBeNull();
  expect(r[3]).toBe(false);
  const runner = src('engine/class-insights-runner.php');
  const boot = runner.slice(runner.indexOf('public static function boot()'), runner.indexOf('public static function run_due()'));
  expect(boot).toMatch(/'off' !== LLD_Members_Features::state\( 'insights' \)/);
  expect(boot).toMatch(/\$on && ! wp_next_scheduled\( self::HOOK \)[\s\S]*wp_schedule_event/);
  expect(boot).toMatch(/! \$on && wp_next_scheduled\( self::HOOK \)[\s\S]*wp_unschedule_event/);
  const run_member = runner.slice(runner.indexOf('public static function run_member('), runner.indexOf('public static function low_mood_active('));
  expect(run_member).toMatch(/if \( ! \$safety_on && ! \$engine_on \) \{\s*return array\( 'status' => 'off'/);
  expect(run_member).toMatch(/has_health_consent\( \$member_id \)/);
  expect(runner).toMatch(/add_action\( self::SAVED_HOOK, array\( __CLASS__, 'on_reading_saved' \), 10, 2 \)/);
  expect(runner).toMatch(/const SAVED_HOOK = 'lld_members_reading_saved'/);
  // The sealed values are opened only in memory: the runner stores numbers sealed and prints none (NFR3-10, NFR3-16).
  expect(runner).toMatch(/array\( 'values' \)\s*\);/);
  const cli = src('class-cli-stage3.php');
  const insights = cli.slice(cli.indexOf('public function insights('));
  expect(insights).not.toMatch(/\['values'\]|\['text'\]/);
  expect(cli).toMatch(/'off' === LLD_Members_Features::state\( 'insights' \)[\s\S]*WP_CLI::error/);
  for (const f of ['class-safety-stage3.php', 'engine/class-insights-runner.php', 'engine/class-decision-engine.php', 'engine/class-rule-catalogue.php']) {
    expect(src(f), f).not.toMatch(/error_log\(|trigger_error\(|wp_mail\(|var_dump\(|print_r\(/);
  }
});

test('TC-NFR3-17-AS-01 (daily values and local days: ENG-08 to ENG-10)', () => {
  // Two readings on one day count once as their mean; the 7-day average of daily means is shown whole (137/87); a
  // night's sleep belongs to the wake-up day; a reading saved at 23:50 local on D-1 in UTC+10 counts on D-1.
  const code = `
    define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
    function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
    require ${toPhpPath(stage3('engine/class-rule-catalogue.php'))}; require ${toPhpPath(stage3('engine/class-decision-engine.php'))}; require ${toPhpPath(stage3('engine/class-insights-runner.php'))};
    $R = 'LLD_Members_Insights_Runner';
    $day1 = $R::daily_value('blood_pressure', [['at' => 1, 'v1' => 150, 'v2' => 95, 'v3' => null], ['at' => 2, 'v1' => 130, 'v2' => 85, 'v3' => null]]);
    $state = ['today' => '2026-11-16', 'daily' => ['blood_pressure' => ['2026-11-15' => $day1, '2026-11-14' => [136, 86], '2026-11-13' => [136, 86]]]];
    $r = LLD_Members_Decision_Engine::rule('R-SAFE-01', ['today' => '2026-11-16', 'daily' => ['blood_pressure' => ['2026-11-15' => $day1, '2026-11-14' => [136, 86], '2026-11-13' => [136, 86], '2026-11-08' => $day1, '2026-11-07' => [136, 86], '2026-11-06' => [136, 86]]]]);
    echo json_encode([
      'day1' => $day1,
      'text' => $r['values'],
      'weight' => $R::daily_value('weight', [['at' => 2, 'v1' => 80.2, 'v2' => null, 'v3' => null], ['at' => 1, 'v1' => 80.6, 'v2' => null, 'v3' => null]]),
      'sleep' => $R::daily_value('sleep', [['at' => 1, 'v1' => 1.5, 'v2' => null, 'v3' => null], ['at' => 2, 'v1' => 5.5, 'v2' => null, 'v3' => null]]),
      'activity' => $R::daily_value('activity', [['at' => 1, 'v1' => 20, 'v2' => null, 'v3' => 3], ['at' => 2, 'v1' => 10, 'v2' => null, 'v3' => 1]]),
      'mood' => $R::daily_value('mood', [['at' => 1, 'v1' => 2, 'v2' => null, 'v3' => null], ['at' => 2, 'v1' => 3, 'v2' => null, 'v3' => null], ['at' => 3, 'v1' => 3, 'v2' => null, 'v3' => null]]),
      'wake' => $R::local_date('2026-11-16 06:30:00', 'UTC'),
      'brisbane' => $R::local_date('2026-11-15 13:50:00', 'Australia/Brisbane'),
      'utc' => $R::local_date('2026-11-15 13:50:00', 'UTC'),
      'weekday' => [$R::weekday_key('Monday'), $R::weekday_key('sun'), $R::weekday_key(3), $R::weekday_key(null)],
      'rate' => [$R::rate_one_step_lower(1.0), $R::rate_one_step_lower(0.75), $R::rate_one_step_lower(0.5), $R::rate_one_step_lower(0.25), $R::rate_one_step_lower(0.9)],
      'build_state_private' => !(new ReflectionMethod($R, 'build_state'))->isPublic(),
    ]);
  `;
  const r = JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim());
  expect(r.day1).toEqual([140, 90]);
  expect(r.text).toEqual({ sys: 137, dia: 87 }); // (140 + 136 + 136) / 3 = 137.33 in each window: shown 137/87
  expect(r.weight).toBe(80.2); // the last reading of the day
  expect(r.sleep).toBe(7);
  expect(r.activity).toBe(50); // vigorous counts double
  expect(r.mood).toBe(2.7); // mean to 1 dp
  expect(r.wake).toBe('2026-11-16');
  expect(r.brisbane).toBe('2026-11-15'); // 23:50 local on D-1
  expect(r.utc).toBe('2026-11-15');
  expect(r.weekday).toEqual([0, 6, 3, null]);
  expect(r.rate).toEqual([0.75, 0.5, 0.25, 0.25, 0.75]); // R-ADJ-03 lowers the rate one step with the target
  expect(r.build_state_private).toBe(true); // the opened health values never leave the runner
  const runner = src('engine/class-insights-runner.php');
  expect(runner).toMatch(/rate_one_step_lower\( \(float\) \$schedule\['rate_kg_week'\] \)/);
  expect(runner).toMatch(/=== \$result\['date'\] \) \{\s*\$summary\['outcomes'\]\[ \$o\['rule'\] \] = 'shown_today'/); // one safety row a day (R-SAFE-01p)
});
