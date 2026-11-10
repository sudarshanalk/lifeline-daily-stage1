// One value set per Healthy Weight question (P3-35): the approved content's answer values (LLD_Members_HW_Content::
// ANSWERS, package P3, which the screens offer) and the answers' own (LLD_Members_HW_Answers::KEYS, package P2) are the
// same, every choice is accepted end to end and reaches the engine as P1 reads it, and old stored values still read.
// Made-up test members only.
import { expect, test } from 'vitest';
import { run, stage3 } from './hw-data-harness.mjs';

const CONTENT = `require ${JSON.stringify(stage3('class-content-hw.php').split('\\').join('/'))};`;

/** Every question and follow-up of the approved content: {id, key, type, values}. */
const QUESTIONS = `
  $out = [];
  foreach (LLD_Members_HW_Content::ANSWERS as $id => $q) {
    $out[] = ['id' => $id, 'key' => $q['key'], 'type' => $q['type'], 'values' => $q['values']];
    if (isset($q['follow_up']) && ! isset($q['follow_up']['question'])) $out[] = ['id' => $id . ' follow-up', 'key' => $q['follow_up']['key'], 'type' => $q['follow_up']['type'], 'values' => $q['follow_up']['values']];
  }
`;

test('P3-35 answer values: the approved content and the answers hold the same value set for every question (CI guard)', () => {
  const out = run(`${CONTENT} ${QUESTIONS}
    $keys = [];
    foreach (LLD_Members_HW_Answers::KEYS as $k => $d) $keys[$k] = ['values' => $d['values'], 'choices' => $d['choices'] ?? null];
    return ['q' => $out, 'keys' => $keys, 'aliases' => LLD_Members_HW_Answers::ALIASES];
  `);
  const problems = [];
  for (const q of out.q) {
    const key = out.aliases[q.key] || q.key;
    const def = out.keys[key];
    if (!def) { problems.push(`${q.id}: ${q.key} is not an answer key`); continue; }
    if (key === 'fasts') continue; // the festival families come from the approved calendar itself (festival_families())
    if (q.type === 'choice') {
      if (JSON.stringify(def.values) !== JSON.stringify(q.values)) problems.push(`${q.id} ${key}: content ${JSON.stringify(q.values)} answers ${JSON.stringify(def.values)}`);
    } else if (q.type === 'multi') {
      if (def.values !== 'multi' || JSON.stringify(def.choices) !== JSON.stringify(q.values)) problems.push(`${q.id} ${key}: content ${JSON.stringify(q.values)} answers ${JSON.stringify(def.choices)}`);
    } else if (def.values !== q.type) {
      problems.push(`${q.id} ${key}: content type ${q.type}, answers ${def.values}`);
    }
  }
  expect(problems).toEqual([]);
  expect(out.q.length).toBeGreaterThan(25);
});

test('TC-FR3-99-AS-01 (every choice end to end): each choice the screens offer is accepted and reaches the engine as P1 reads it', () => {
  const out = run(`${CONTENT} ${QUESTIONS}
    member(7);
    $refused = [];
    foreach ($out as $q) {
      $key = LLD_Members_HW_Answers::ALIASES[$q['key']] ?? $q['key'];
      if ('fasts' === $key || ! isset(LLD_Members_HW_Answers::KEYS[$key])) continue;
      $vals = $q['values'] ?: ('time' === $q['type'] ? ['07:30'] : ('date' === $q['type'] ? ['2027-05-01'] : [5]));
      foreach ($vals as $v) {
        $r = LLD_Members_HW_Answers::set(7, $q['key'], 'multi' === $q['type'] ? [$v] : $v);
        if (true !== $r) $refused[] = $q['id'] . ' ' . json_encode($v);
      }
    }
    $set = function ($k, $v) { LLD_Members_HW_Answers::set(7, $k, $v); return LLD_Members_HW_Answers::engine(7, LLD_Members_Goal_Journey::facts(7)); };
    $item = function ($o) { return $o + ['id' => 1, 'approved' => true, 'diets' => ['everything', 'vegetarian'], 'allergens' => [], 'food_rules' => []]; };
    $e = $set('allergies', ['egg', 'nuts', 'soy']);
    $allergy = [LLD_Members_HW_Rules::food_ok($item(['allergens' => ['egg']]), $e)['ok'], LLD_Members_HW_Rules::food_ok($item(['allergens' => ['tree nuts']]), $e)['ok'], LLD_Members_HW_Rules::food_ok($item(['allergens' => ['soya']]), $e)['ok'], LLD_Members_HW_Rules::food_ok($item(['allergens' => ['gluten']]), $e)['ok']];
    $e = $set('work', 'days'); $e = $set('snack_time', '20:00');
    $rem = LLD_Members_HW_Rules::reminder_times($e)['reminders'];
    $e = $set('snack_time', 'none');
    $none_snack = [$e['snack_time'], LLD_Members_HW_Rules::reminder_times($e)['reminders']];
    $e = $set('food_rules', ['jain']);
    $jain = [LLD_Members_HW_Rules::food_ok($item(['food_rules' => ['contains-root-vegetables']]), $e)['ok'], $e['alcohol_freq']];
    $e = $set('food_rules', ['no_grains_pulses_fasting_days']);
    $grains = $item(['food_rules' => ['contains-grains-or-pulses']]);
    $fasting = [LLD_Members_HW_Rules::food_ok($grains, $e + ['fasting_day' => true])['ok'], LLD_Members_HW_Rules::food_ok($grains, $e + ['fasting_day' => false])['ok']];
    $none = $set('food_rules', ['halal', 'none'])['food_rules'];
    $e = $set('cuisines', ['south-asian']);
    $five = []; for ($i = 1; $i <= 5; $i++) $five[] = $item(['id' => $i, 'cuisine' => 1 === $i ? 'south-asian' : 'chinese']);
    $cuisine = LLD_Members_HW_Rules::food_filter($five, $e)['items'][0]['reason'];
    $e = $set('sugary_drinks', 'weekly');
    return compact('refused', 'allergy', 'rem', 'none_snack', 'jain', 'fasting', 'none', 'cuisine') + ['sugary' => $e['sugary_drinks'], 'protein' => $set('breakfast_protein', 'not_sure')['breakfast_protein']];
  `);
  expect(out.refused).toEqual([]);
  expect(out.allergy).toEqual([false, false, false, true]); // the recipes' "egg", "tree nuts" and "soya" are left out
  expect(out.rem).toEqual([{ kind: 'step', time: '08:00' }, { kind: 'snack', time: '19:30' }]); // 30 minutes before 20:00
  expect(out.none_snack).toEqual([null, [{ kind: 'step', time: '08:00' }]]);
  expect(out.jain).toEqual([false, 'never']);
  expect(out.fasting).toEqual([false, true]); // only on a fasting day
  expect(out.none).toEqual([]); // "None of these" clears the others
  expect(out.cuisine).toContain('south-asian'); // R-HW-FOOD-02 matched the recipes' cuisine name
  expect(out.sugary).toBe('weekly');
  expect(out.protein).toBe(false);
});

test('P3-35 answer values: "Something else" gives no Today line; values stored before the sets were joined still read as they meant', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, ['reason' => 'other']);
    $line = LLD_Members_HW_Programme::today(7)['reason_line'];
    LLD_Members_HW_Answers::set(7, 'reason', 'health');
    $health = LLD_Members_HW_Programme::today(7)['reason_line'];
    // Old values, written straight to the store as an earlier build did, and given again through a form.
    foreach (['snack_time' => 'after_evening_meal', 'cooking_skill' => 'starting', 'enjoyment' => ['yoga'], 'cuisines' => ['south_asian'], 'breakfast_protein' => 'notsure', 'sugary_drinks' => '1_3_days'] as $k => $v)
      LLD_Members_Stage3_Data::insert('profile_answers', 7, ['question_key' => $k, 'answer' => json_encode($v), 'skipped' => 0, 'asked_in' => 'track_start'], LLD_Members_HW_Answers::SEALED);
    LLD_Members_HW_Answers::forget(7, 'reason'); // clears the request cache
    $old = LLD_Members_HW_Answers::all(7);
    $given = [LLD_Members_HW_Answers::set(7, 'reason', 'something_else'), LLD_Members_HW_Answers::get(7, 'reason'), LLD_Members_HW_Answers::set(7, 'allergies', ['eggs', 'tree nuts', 'soya']), LLD_Members_HW_Answers::get(7, 'allergies')];
    return ['line' => $line, 'health' => $health, 'old' => $old, 'given' => $given, 'engine' => LLD_Members_HW_Answers::engine(7)['snack_time']];
  `);
  expect(out.line).toBeNull();
  expect(out.health).toContain('For your health');
  expect(out.old).toMatchObject({ snack_time: '20:00', cooking_skill: 'beginner', enjoyment: ['yoga_stretching'], cuisines: ['south-asian'], breakfast_protein: 'not_sure', sugary_drinks: 'weekly' });
  expect(out.engine).toBe('20:00');
  expect(out.given).toEqual([true, 'other', true, ['egg', 'nuts', 'soy']]);
});
