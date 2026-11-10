// Food rules, fasting, drinks, the repeat meal and Keep It Off (P3-35, package P2; FR3-36, FR3-99, FR3-102, FR3-110,
// FR3-20). Made-up recipes and foods: names and numbers are test values, not the approved library.
import { expect, test } from 'vitest';
import { arr, run, src } from './hw-data-harness.mjs';

// R-number recipes with their approved-style food-rule tags (Food-Rule-Tags R-103 to R-176 shape).
const library = [
  { id: 1, key: 'R-901', name: 'Test pork stir-fry', kcal_per_serving: 420, allergens: ['soy'], food_rules: ['contains-pork', 'contains-onion-or-garlic', 'contains-grains-or-pulses'], diets: ['everything'], approved: true },
  { id: 2, key: 'R-902', name: 'Test prawn curry', kcal_per_serving: 380, allergens: ['shellfish'], food_rules: ['contains-shellfish', 'contains-onion-or-garlic'], diets: ['everything'], approved: true },
  { id: 3, key: 'R-903', name: 'Test chicken bake with cheese', kcal_per_serving: 450, allergens: ['milk'], food_rules: ['contains-poultry', 'contains-dairy', 'contains-meat-and-dairy', 'needs-halal-meat', 'needs-kosher-meat'], diets: ['everything'], approved: true },
  { id: 4, key: 'R-904', name: 'Test chicken traybake', kcal_per_serving: 400, allergens: [], food_rules: ['contains-poultry', 'needs-halal-meat', 'needs-kosher-meat'], diets: ['everything'], approved: true },
  { id: 5, key: 'R-118', name: 'Test vegetable frittata', kcal_per_serving: 300, allergens: ['egg'], food_rules: ['contains-egg', 'contains-onion-or-garlic', 'contains-grains-or-pulses'], diets: ['everything', 'vegetarian'], approved: true },
  { id: 6, key: 'R-905', name: 'Test buckwheat pancakes', kcal_per_serving: 350, allergens: ['buckwheat'], food_rules: [], diets: ['everything', 'vegetarian', 'vegan'], approved: true },
  { id: 7, key: 'R-906', name: 'Test sweet potato and peanut bowl', kcal_per_serving: 500, allergens: ['peanuts'], food_rules: [], diets: ['everything', 'vegetarian', 'vegan'], approved: true },
  { id: 8, key: 'R-907', name: 'Test unapproved salad', kcal_per_serving: 200, allergens: [], food_rules: [], diets: ['everything'], approved: false },
];
const suggest = (prefs, left = null) => run(`return LLD_Members_Recipes::suggestions(${arr(library)}, ${arr(prefs)}, ${left === null ? 'null' : left}, null, true);`);
const ids = (r) => r.recipes.map((x) => x.id);

test('TC-FR3-36-AS-04', () => {
  // A kosher member never sees pork, shellfish or meat with dairy, and sees the kosher meat line with the rest.
  const r = suggest({ allergies: [], dislikes: [], food_rules: ['kosher'] });
  expect(ids(r)).toEqual([4, 5, 6, 7]);
  expect(r.recipes.find((x) => x.id === 4).lines).toEqual(['Use kosher meat and chicken in these recipes.']);
  expect(r.recipes.find((x) => x.id === 6)).not.toHaveProperty('lines');
});

test('TC-FR3-36-AS-05', () => {
  // On a "no grains or pulses" fasting day only recipes without grains or pulses are suggested; on other days the rule
  // does not filter.
  const fasting = suggest({ allergies: [], dislikes: [], food_rules: ['no_grains_pulses_fasting_days'], fasting_day: true });
  expect(ids(fasting)).toEqual([2, 3, 4, 6, 7]);
  const usual = suggest({ allergies: [], dislikes: [], food_rules: ['no_grains_pulses_fasting_days'], fasting_day: false });
  expect(ids(usual)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  // The day is a fasting day when it falls in a fast the member keeps from the approved calendar.
  const out = run(`
    global $wpdb;
    $wpdb->insert('wp_lld_festival_calendar', ['name' => 'Test fast day', 'kind' => 'food_avoiding_fast', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'expected' => 0, 'approved_on' => '2026-11-10']);
    return [LLD_Members_HW_Programme::fasting_day(['1'], '2026-11-20'), LLD_Members_HW_Programme::fasting_day(['1'], '2026-11-21'), LLD_Members_HW_Programme::fasting_day([], '2026-11-20')];
  `);
  expect(out).toEqual([true, false, false]);
});

test('TC-FR3-36-AS-01 (food rules)', () => {
  // Allergies: buckwheat is on the allergen list; a peanut allergy never sees the peanut bowl; with a halal rule,
  // pork is left out and the vanilla and soy-sauce line shows once for the list.
  const r = suggest({ allergies: ['buckwheat', 'peanuts'], dislikes: [], food_rules: ['halal'] });
  expect(ids(r)).toEqual([2, 3, 4, 5]);
  expect(r.lines).toEqual(['Some vanilla extracts and soy sauces contain alcohol. Choose alcohol-free or halal-certified versions if you prefer.']);
  expect(r.recipes.find((x) => x.id === 4).lines).toEqual(['Use halal meat and chicken in these recipes.']);
  expect(src('class-recipes.php')).toMatch(/'molluscs', 'buckwheat' \)/);
});

test('TC-FR3-36-AS-02', () => {
  // Every suggested recipe fits within the calories left, with the food rules applied too.
  const r = suggest({ allergies: [], dislikes: [], food_rules: ['vegetarian'] }, 320);
  expect(ids(r)).toEqual([5]);
  for (const x of r.recipes) expect(x.kcal_per_serving).toBeLessThanOrEqual(320);
});

test('TC-FR3-36-AS-03', () => {
  // Every suggested recipe is in the approved library; the Parmesan line shows under R-118 (FT-Q4 = A).
  const r = suggest({ allergies: [], dislikes: [], food_rules: ['vegetarian'] });
  expect(ids(r)).toEqual([5, 6, 7]);
  expect(r.recipes.every((x) => x.approved && x.source === 'approved_library')).toBe(true);
  expect(r.recipes.find((x) => x.id === 5).lines).toEqual(['Choose a vegetarian hard cheese if you prefer.']);
  // A member with no food rule and no allergy gets the Stage 3 list exactly as before.
  const plain = suggest({ allergies: [], dislikes: [] });
  expect(ids(plain)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  expect(plain).not.toHaveProperty('lines');
});

test('TC-FR3-99-AS-07', () => {
  // During a dawn-to-sunset fast the member keeps, the meal plan switches to the fasting plan with the same target and
  // back the day after; type 2 diabetes keeps the usual plan with the approved line; the switch is Premium.
  const out = run(`
    global $wpdb;
    $wpdb->insert('wp_lld_festival_calendar', ['name' => 'Test fast', 'kind' => 'dawn_to_sunset_fast', 'starts_on' => '2027-02-08', 'ends_on' => '2027-03-09', 'expected' => 0, 'approved_on' => '2026-11-10']);
    member(7); member(8, ['situations' => ['diabetes']]); member(9, ['premium' => false]);
    foreach ([7, 8, 9] as $m) LLD_Members_HW_Answers::set($m, 'fasts', ['1']);
    return ['in' => LLD_Members_Recipes::meal_plan_for(7, '2027-03-09'), 'after' => LLD_Members_Recipes::meal_plan_for(7, '2027-03-10'),
      't2d' => LLD_Members_Recipes::meal_plan_for(8, '2027-02-10'), 'free' => LLD_Members_Recipes::meal_plan_for(9, '2027-02-10')];
  `);
  expect(out.in).toMatchObject({ rule: 'R-HW-FOOD-03', plan: 'fasting', fast: '1', target_changes: false, line: null });
  expect(out.after.plan).toBe('usual');
  expect(out.t2d).toMatchObject({ plan: 'usual', line: 'If you are planning to fast, follow the plan you agreed with your doctor or diabetes team.' });
  expect(out.free.plan).toBe('usual');
});

test('TC-FR3-110-AS-03', () => {
  // The food tracker offers wine, beer, cider and spirits in standard sizes, with mixers as separate items; the
  // calories come from the food database's own entry (made-up figures here), never a number in the code.
  const out = run(`
    $foods = ['wine' => ['id' => 1, 'kcal_100g' => 80, 'protein_100g' => 0, 'carb_100g' => 2, 'fat_100g' => 0, 'fibre_100g' => 0],
      'beer' => ['id' => 2, 'kcal_100g' => 40, 'protein_100g' => 0, 'carb_100g' => 3, 'fat_100g' => 0, 'fibre_100g' => 0],
      'cider' => ['id' => 3, 'kcal_100g' => 45, 'protein_100g' => 0, 'carb_100g' => 4, 'fat_100g' => 0, 'fibre_100g' => 0],
      'spirits' => ['id' => 4, 'kcal_100g' => 220, 'protein_100g' => 0, 'carb_100g' => 0, 'fat_100g' => 0, 'fibre_100g' => 0],
      'tonic water' => ['id' => 5, 'kcal_100g' => 30, 'protein_100g' => 0, 'carb_100g' => 8, 'fat_100g' => 0, 'fibre_100g' => 0]];
    return ['menu' => LLD_Members_Food_Log::drinks_menu($foods), 'none' => LLD_Members_Food_Log::drinks_menu([])];
  `);
  const by = Object.fromEntries(out.menu.map((d) => [d.key, d]));
  expect(Object.keys(by)).toEqual(['wine_125', 'wine_175', 'beer_bottle', 'beer_pint', 'cider_bottle', 'cider_pint', 'spirit', 'tonic']);
  expect(by.wine_175).toMatchObject({ label: 'Glass of wine, 175 ml', ml: 175, kind: 'drink', kcal: 140 });
  expect(by.beer_pint).toMatchObject({ ml: 568, kcal: 227.2 });
  expect(by.spirit).toMatchObject({ ml: 25, kcal: 55 });
  expect(by.tonic.kind).toBe('mixer');
  expect(out.none).toEqual([]);
});

test('TC-FR3-110-AS-02', () => {
  // A drink logged from the shortcut counts against that day's calorie target, and hides the shortcut for the day.
  const out = run(`
    $wine = ['id' => 1, 'kcal_100g' => 80, 'protein_100g' => 0, 'carb_100g' => 2, 'fat_100g' => 0, 'fibre_100g' => 0];
    $e = LLD_Members_Food_Log::drink_entry($wine, 'wine_175', '2026-11-12');
    $e['id'] = 1;
    $day = LLD_Members_Food_Log::day([$e], '2026-11-12', ['kcal' => 1500]);
    return ['e' => $e, 'day' => $day, 'logged' => LLD_Members_Food_Log::drink_logged([$e], '2026-11-12'), 'other' => LLD_Members_Food_Log::drink_logged([$e], '2026-11-13')];
  `);
  expect(out.e).toMatchObject({ meal: 'snacks', grams: 175, kcal: 140, source: 'drink', portion_label: 'Glass of wine, 175 ml' });
  expect(out.day.totals.kcal).toBe(140);
  expect(out.day.left.kcal).toBe(1360);
  expect(out.logged).toBe(true);
  expect(out.other).toBe(false);
});

test('TC-FR3-102-AS-06', () => {
  // A meal logged yesterday can be logged again from Today with one tap (copied as stored); a meal repeated on several
  // days comes first.
  const out = run(`
    $e = function ($id, $date, $meal, $food, $kcal) { return ['id' => $id, 'entry_date' => $date, 'meal' => $meal, 'food_id' => $food, 'food_kind' => 'food', 'grams' => 100, 'kcal' => $kcal, 'source' => 'search', 'created_at' => "$date 08:00:00"]; };
    $yesterday = [$e(1, '2026-11-11', 'lunch', 10, 300), $e(2, '2026-11-11', 'lunch', 11, 150)];
    $often = array_merge($yesterday, [$e(3, '2026-11-05', 'breakfast', 20, 250), $e(4, '2026-11-07', 'breakfast', 20, 250), $e(5, '2026-11-09', 'breakfast', 20, 250)]);
    $one = LLD_Members_Food_Log::repeat_meal($yesterday, '2026-11-12');
    $copy = LLD_Members_Food_Log::copied($yesterday, $one['from_date'], '2026-11-12', $one['meal'], true);
    return ['one' => $one, 'often' => LLD_Members_Food_Log::repeat_meal($often, '2026-11-12'), 'copy' => $copy, 'none' => LLD_Members_Food_Log::repeat_meal([], '2026-11-12')];
  `);
  expect(out.one).toEqual({ meal: 'lunch', from_date: '2026-11-11', items: 2, kcal: 450, times: 1 });
  expect(out.often).toMatchObject({ meal: 'breakfast', from_date: '2026-11-09', times: 3 });
  expect(out.copy.entries.map((x) => [x.entry_date, x.kcal, x.source])).toEqual([['2026-11-12', 300, 'copy'], ['2026-11-12', 150, 'copy']]);
  expect(out.none).toBeNull();
});

test('TC-FR3-20-AS-03: Keep It Off keeps a target as a guide at the P3-55 maintain level, no deficit', () => {
  // A member who reaches the goal keeps a target as a guide at the P3-55 maintain level (no deficit): their daily use
  // rounded to 10.
  const out = run(`
    member(7, ['age' => 45, 'height' => 160, 'weight' => 80, 'activity' => 'some']);
    $gid = weight_goal(7, 80, 75);
    LLD_Members_HW_Programme::join(7, $gid, ['activity_level' => 'some']);
    $f = LLD_Members_Goal_Journey::facts(7);
    $calc = LLD_Members_Calorie_Target::compute(['sex' => 'F', 'age' => 45, 'heightCm' => 160, 'weightKg' => 80, 'activity' => 'some', 'goal' => 'maintain', 'rateKgWeek' => 0.5, 'pregnant' => 'no', 'eatingDisorder' => 'no']);
    LLD_Members_Stage3_Data::update('goals', 7, $gid, ['status' => 'reached']);
    $v = LLD_Members_HW_Programme::plan_view(7);
    $kio = LLD_Members_Goal_Journey::keep_it_off(7, LLD_Members_Stage3_Data::row('goals', 7, $gid, LLD_Members_Goal_Journey::SEALED_GOAL), $f);
    $nt = raw('nutrition_targets');
    return ['v' => $v['target_kcal'], 'tdee' => $calc['tdee'], 'target' => $calc['targetKcal'], 'kio' => is_int($kio), 'nt' => end($nt)['kcal'], 'lose' => $nt[0]['kcal']];
  `);
  const rounded = Math.round(out.tdee / 10) * 10;
  expect(out.target).toBe(rounded);
  expect(out.v).toBe(rounded);
  expect(out.kio).toBe(true);
  expect(out.nt).toBe(rounded);
  expect(out.lose).toBeLessThan(rounded);
});
