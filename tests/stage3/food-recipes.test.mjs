// Weekly nutrition summary, member recipes and recipe suggestions (P3-43, FR3-34 to FR3-36).
// Made-up foods only: lentils 116 kcal / 100 g in this fixture.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const src = (name) => readFileSync(stage3(name), 'utf8');

const boot = `
define('ABSPATH', '/');
function wp_json_encode($v) { return json_encode($v); }
function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\\-]/', '', (string)$v)); }
require ${toPhpPath(stage3('calculators/class-calorie-target.php'))};
require ${toPhpPath(stage3('class-food-log.php'))};
require ${toPhpPath(stage3('class-recipes.php'))};
$lentil = ['name'=>'Lentils','kcal_100g'=>116,'protein_100g'=>9,'carb_100g'=>20,'fat_100g'=>0.4,'fibre_100g'=>8];
$spinach = ['name'=>'Spinach','kcal_100g'=>23,'protein_100g'=>2.9,'carb_100g'=>3.6,'fat_100g'=>0.4,'fibre_100g'=>2.2];
$oil = ['name'=>'Olive oil','kcal_100g'=>884,'protein_100g'=>0,'carb_100g'=>0,'fat_100g'=>100,'fibre_100g'=>0];
function line($food, $grams) {
  $n = LLD_Members_Food_Log::from_per_100($food, $grams);
  return array_merge(['name'=>$food['name'],'grams'=>$n['grams']], $n);
}
function ate($kcal, $date, $id) {
  return ['id'=>$id,'name'=>'Fixture meal','food_id'=>$id,'food_kind'=>'food','meal'=>'lunch','entry_date'=>$date,'grams'=>100,'kcal'=>$kcal,'protein_g'=>10,'carb_g'=>20,'fat_g'=>5,'fibre_g'=>3,'source'=>'search'];
}
`;
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${boot}\n${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const run = (body) => JSON.parse(php(body));

test('TC-FR3-34-AS-01', () => {
  // Seven logged days Mon 2 Nov to Sun 8 Nov 2026: 1400, 1500, 1600, 1700, 1800, 1900, 2000 → average 1700.
  const out = run(`
    $entries = [];
    $kcals = [1400,1500,1600,1700,1800,1900,2000];
    foreach ($kcals as $i => $k) {
      $d = (new DateTimeImmutable('2026-11-02'))->modify('+'.$i.' days')->format('Y-m-d');
      $entries[] = ate($k, $d, $i + 1);
    }
    $week = LLD_Members_Recipes::week($entries, [['kcal'=>2000,'valid_from'=>'2026-11-01']], '2026-11-02', true);
    echo json_encode($week);
  `);
  expect(out.logged_days).toBe(7);
  expect(out.averages.kcal).toBe(1700);
  expect(out.week_end).toBe('2026-11-08');
  const free = run(`echo json_encode(LLD_Members_Recipes::week([], [], '2026-11-02', false));`);
  expect(free.error).toMatch(/Premium/);
  expect(src('class-api.php')).toContain("'computed' => 'week'");
  expect(src('loader.php')).toContain('class-recipes.php');
});

test('TC-FR3-34-AS-02', () => {
  // Target 1800: days at 1700, 1800, 1900, 1600, 2000, 1750, 1800 → on target when kcal <= 1800 → 5 days.
  const out = run(`
    $kcals = [1700,1800,1900,1600,2000,1750,1800];
    $entries = [];
    foreach ($kcals as $i => $k) {
      $d = (new DateTimeImmutable('2026-11-02'))->modify('+'.$i.' days')->format('Y-m-d');
      $entries[] = ate($k, $d, $i + 1);
    }
    echo json_encode(LLD_Members_Recipes::week($entries, [['kcal'=>1800,'valid_from'=>'2026-11-01']], '2026-11-02', true));
  `);
  expect(out.days_on_target).toBe(5);
});

test('TC-FR3-34-AS-03', () => {
  const out = run(`
    echo json_encode([
      'tips' => LLD_Members_Recipes::TIPS,
      'a' => LLD_Members_Recipes::tip_for('2026-11-02'),
      'b' => LLD_Members_Recipes::tip_for('2026-11-09'),
      'week' => LLD_Members_Recipes::week([ate(1500,'2026-11-02',1)], [['kcal'=>1800,'valid_from'=>'2026-11-01']], '2026-11-02', true),
    ]);
  `);
  expect(out.tips.length).toBeGreaterThanOrEqual(3);
  expect(out.tips).toContain(out.a);
  expect(out.tips).toContain(out.week.tip);
  expect(out.a).toBe(out.week.tip);
  // Same week always returns the same tip; a different week may differ.
  expect(out.tips).toContain(out.b);
  for (const tip of out.tips) {
    expect(tip).not.toMatch(/\b(warning|danger|alarm|unhealthy|bad|risky|risk|stop|emergency|harm|careful)\b/i);
  }
});

test('TC-FR3-35-AS-01', () => {
  // 3 ingredients, 4 servings: 116 + 23 + 88.4 = 227.4 → per serving 56.85 → 56.9 kcal.
  const out = run(`
    $lines = [line($lentil, 100), line($spinach, 100), line($oil, 10)];
    $nuts = LLD_Members_Recipes::nutrition_for($lines, 4);
    echo json_encode(['nuts'=>$nuts,'lines'=>$lines]);
  `);
  expect(out.lines[0].kcal).toBe(116);
  expect(out.lines[1].kcal).toBe(23);
  expect(out.lines[2].kcal).toBe(88.4);
  expect(out.nuts.kcal_per_serving).toBe(56.9);
  expect(out.nuts.protein_per_serving).toBe(2.98);
  expect(out.nuts.servings).toBe(4);
});

test('TC-FR3-35-AS-02', () => {
  const out = run(`
    $recipe = ['name'=>'Lentil bowl','kcal_per_serving'=>56.9,'protein_per_serving'=>3,'carb_per_serving'=>5.9,'fat_per_serving'=>2.52,'fibre_per_serving'=>2.55];
    $basis = LLD_Members_Recipes::serving_basis($recipe, 1);
    $logged = LLD_Members_Food_Log::apply_basis($basis, ['entry_date'=>'2026-11-03','meal'=>'lunch','food_id'=>9,'food_kind'=>'recipe','grams'=>1,'source'=>'recipe']);
    $day = LLD_Members_Food_Log::day([$logged], '2026-11-03', null);
    echo json_encode(['logged'=>$logged,'day'=>$day,'basis'=>$basis]);
  `);
  expect(out.logged.kcal).toBe(56.9);
  expect(out.logged.protein_g).toBe(3);
  expect(out.logged.food_kind).toBe('recipe');
  expect(out.logged.grams).toBe(1);
  expect(out.day.totals.kcal).toBe(56.9);
  expect(out.day.meals.lunch).toHaveLength(1);
});

test('TC-FR3-35-AS-03', () => {
  const out = run(`
    $before = LLD_Members_Recipes::nutrition_for([line($lentil, 100), line($spinach, 100), line($oil, 10)], 4);
    $after = LLD_Members_Recipes::nutrition_for([line($lentil, 200), line($spinach, 100), line($oil, 10)], 4);
    echo json_encode(['before'=>$before,'after'=>$after]);
  `);
  expect(out.before.kcal_per_serving).toBe(56.9);
  expect(out.after.kcal_per_serving).toBe(85.9); // (232+23+88.4)/4 = 343.4/4 = 85.85 → 85.9
  expect(out.after.kcal_per_serving).toBeGreaterThan(out.before.kcal_per_serving);
  expect(src('class-api.php')).toContain('LLD_Members_Recipes::for_member');
  expect(src('class-api.php')).toContain('LLD_Members_Recipes::gate_premium');
});

test('TC-FR3-36-AS-01', () => {
  const out = run(`
    $library = [
      ['id'=>1,'name'=>'Nut roast','kcal_per_serving'=>450,'allergens'=>['nuts'],'suits_goals'=>[],'approved'=>true],
      ['id'=>2,'name'=>'Bean stew','kcal_per_serving'=>380,'allergens'=>[],'suits_goals'=>['lose_weight'],'approved'=>true],
      ['id'=>3,'name'=>'Peanut sauce noodles','kcal_per_serving'=>500,'allergens'=>['peanuts'],'suits_goals'=>[],'approved'=>true],
    ];
    $prefs = ['allergies'=>['nuts'],'dislikes'=>[]];
    echo json_encode(LLD_Members_Recipes::suggestions($library, $prefs, 600, 'lose_weight', true));
  `);
  const names = out.recipes.map((r) => r.name);
  expect(names).toContain('Bean stew');
  expect(names).not.toContain('Nut roast');
  expect(names).toContain('Peanut sauce noodles'); // peanuts ≠ nuts in the allergen list
  const peanutAllergy = run(`
    echo json_encode(LLD_Members_Recipes::suggestions(
      [['id'=>3,'name'=>'Peanut sauce noodles','kcal_per_serving'=>500,'allergens'=>['peanuts'],'suits_goals'=>[],'approved'=>true]],
      ['allergies'=>['peanuts'],'dislikes'=>[]], 600, null, true
    ));
  `);
  expect(peanutAllergy.recipes).toEqual([]);
});

test('TC-FR3-36-AS-02', () => {
  const out = run(`
    $library = [
      ['id'=>1,'name'=>'Light salad','kcal_per_serving'=>220,'allergens'=>[],'suits_goals'=>[],'approved'=>true],
      ['id'=>2,'name'=>'Hearty pasta','kcal_per_serving'=>650,'allergens'=>[],'suits_goals'=>[],'approved'=>true],
      ['id'=>3,'name'=>'Exact fill','kcal_per_serving'=>400,'allergens'=>[],'suits_goals'=>[],'approved'=>true],
    ];
    echo json_encode(LLD_Members_Recipes::suggestions($library, ['allergies'=>[],'dislikes'=>[]], 400, null, true));
  `);
  const kcals = out.recipes.map((r) => r.kcal_per_serving);
  expect(kcals.every((k) => k <= 400)).toBe(true);
  expect(out.recipes.map((r) => r.name).sort()).toEqual(['Exact fill', 'Light salad']);
});

test('TC-FR3-36-AS-03', () => {
  const out = run(`
    $library = [
      ['id'=>1,'name'=>'Approved stew','kcal_per_serving'=>300,'allergens'=>[],'suits_goals'=>[],'approved'=>true],
      ['id'=>2,'name'=>'Draft AI bowl','kcal_per_serving'=>280,'allergens'=>[],'suits_goals'=>[],'approved'=>false],
      ['id'=>3,'name'=>'Missing tags','kcal_per_serving'=>250,'suits_goals'=>[],'approved'=>true],
    ];
    $got = LLD_Members_Recipes::suggestions($library, ['allergies'=>[],'dislikes'=>[]], 500, null, true);
    echo json_encode($got);
  `);
  expect(out.recipes).toHaveLength(1);
  expect(out.recipes[0].name).toBe('Approved stew');
  expect(out.recipes[0].approved).toBe(true);
  expect(out.recipes[0].source).toBe('approved_library');
  // The suggestion path only filters an approved library; it never invents a recipe.
  const recipesPhp = src('class-recipes.php');
  expect(recipesPhp).toContain("source'           => 'approved_library'");
  expect(recipesPhp).toContain('LLD_Members_Content::shown');
  expect(recipesPhp).not.toMatch(/openai|chatgpt|anthropic|generate.?recipe|ai_recipe/i);
});
