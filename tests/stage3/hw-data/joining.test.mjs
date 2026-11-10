// Joining Healthy Weight and the plan screen (P3-35, package P2): the answers saved, the pace on the goal, the
// milestones stored, the target from the confirmed activity level, the agreed plan_view() and today() shapes, and Free
// against Premium (FR3-97 to FR3-102, FR3-99; owner decision Q163). P1 covers the engine's own arithmetic.
import { expect, test } from 'vitest';
import { arr, run } from './hw-data-harness.mjs';

test('TC-FR3-100-AS-06', () => {
  // The recommended pace, the chosen pace and the rule are saved with the goal, with the date the chosen pace gives.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    $v = LLD_Members_HW_Programme::join(7, $gid, ['time' => 'under_10', 'confidence' => 3, 'chosen_rate' => 0.5]);
    return ['v' => $v['pace'], 'goal' => raw('goals')[0], 'plan' => json_decode(raw('plans')[0]['log_schedule'], true)];
  `);
  expect(out.goal).toMatchObject({ recommended_rate: 0.25, chosen_rate: 0.5, pace_rule_id: 1021 });
  expect(out.v).toMatchObject({ recommended: 0.25, chosen: 0.5, rule: 'R-HW-PACE-01' });
  expect(out.plan.rate_kg_week).toBe(0.5);
  // 20 kg at 0.5 kg a week from 12 Nov 2026: 40 weeks (P3-56, weeks up).
  expect(out.goal.target_date).toBe('2027-08-19');
});

test('TC-FR3-100-AS-05', () => {
  // A pace above the safe limit is refused with the signed message key and the safe suggestion, and nothing is stored.
  const out = run(`
    member(7, ['age' => 70]);
    $gid = weight_goal(7);
    $e = LLD_Members_HW_Programme::join(7, $gid, ['stress' => 4, 'chosen_rate' => 1.0]);
    return ['code' => $e->get_error_code(), 'data' => $e->get_error_data(), 'answers' => count(raw('profile_answers')), 'goal' => raw('goals')[0], 'ms' => count(raw('goal_milestones'))];
  `);
  expect(out.code).toBe('lld_hw_pace');
  expect(out.data).toEqual({ refusal: 'goal-timeline:refusals.rate-cap', safe_pace: 0.5 });
  expect(out.answers).toBe(0);
  expect(out.ms).toBe(0);
  expect(out.goal.chosen_rate).toBeNull();
});

test('TC-FR3-101-AS-01', () => {
  // The milestones are stored sealed, in kg, with the data model's kinds: 76, 72, 66.2 and 60 kg.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    return ['raw' => raw('goal_milestones'), 'rows' => LLD_Members_Goal_Journey::milestone_rows(7, $gid), 'view' => LLD_Members_HW_Programme::plan_view(7)['milestones']];
  `);
  expect(out.raw.map((r) => r.kind)).toEqual(['five_percent', 'ten_percent', 'healthy_range', 'goal']);
  for (const r of out.raw) expect(r.target_value_ct).toMatch(/^ct:/);
  expect(out.rows.map((r) => r.target_value)).toEqual([76, 72, 66.2, 60]);
  expect(out.view.map((m) => [m.kind, m.kg])).toEqual([['5pct', 76], ['10pct', 72], ['healthy', 66.2], ['goal', 60]]);
  expect(Object.keys(out.view[0])).toEqual(['kind', 'kg', 'date']);
});

test('TC-FR3-102-AS-01', () => {
  // A Premium member who starts Healthy Weight with a weight goal has a calorie target (plan and food tracker) and
  // counts calories.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    $v = LLD_Members_HW_Programme::join(7, $gid, ['activity_level' => 'some']);
    $nt = raw('nutrition_targets');
    return ['v' => $v, 'nt' => end($nt), 'plan' => raw('plans')[0], 'day' => LLD_Members_Food_Log::target_for_day($nt, '2026-11-13')];
  `);
  expect(out.v.target_kcal).toBeGreaterThan(1200);
  expect(out.nt.kcal).toBe(out.v.target_kcal);
  expect(out.plan.calorie_target).toBe(out.v.target_kcal);
  expect(out.plan.eating_method).toBe('calorie_count');
  expect(out.day.kcal).toBe(out.v.target_kcal);
});

test('TC-FR3-102-AS-05', () => {
  // Changing the activity level from 'some' to 'active' (account hub) saves it to the health profile and changes the
  // target on Today and in the food tracker at once (CAL-03: 1,390 at some, 1,640 at active).
  const out = run(`
    member(7, ['age' => 45, 'height' => 160, 'weight' => 80]);
    $gid = weight_goal(7, 80, 65);
    $a = LLD_Members_HW_Programme::join(7, $gid, ['activity_level' => 'some', 'chosen_rate' => 0.5]);
    $r = LLD_Members_HW_Answers::set(7, 'activity_level', 'active', 'account_hub');
    $b = LLD_Members_HW_Programme::plan_view(7);
    $nt = raw('nutrition_targets');
    global $wpdb;
    return ['a' => $a['target_kcal'], 'b' => $b['target_kcal'], 'act' => $b['activity'], 'r' => $r, 'nt' => end($nt)['kcal'], 'profile' => $wpdb->get_var("SELECT activity_level FROM wp_lld_health_profile WHERE member_id = 7")];
  `);
  expect(out.a).toBe(1390);
  expect(out.b).toBe(1640);
  expect(out.nt).toBe(1640);
  expect(out.act).toBe('active');
  expect(out.profile).toBe('active');
});

test('TC-FR3-97-AS-08', () => {
  // A changed level is saved to the health profile in its own spelling: the programme's very_active is the profile's
  // very-active, which the calculator reads as "very active".
  const out = run(`
    member(7);
    LLD_Members_HW_Answers::set(7, 'activity_level', 'very_active');
    global $wpdb;
    $f = LLD_Members_Goal_Journey::facts(7);
    return ['profile' => $wpdb->get_var("SELECT activity_level FROM wp_lld_health_profile WHERE member_id = 7"), 'answer' => LLD_Members_HW_Answers::get(7, 'activity_level'),
      'target' => LLD_Members_Goal_Journey::calorie_targets($f, 'maintain', null), 'low' => LLD_Members_Goal_Journey::calorie_targets(array_merge($f, ['activity' => 'low']), 'maintain', null)];
  `);
  expect(out.profile).toBe('very-active');
  expect(out.answer).toBe('very_active');
  expect(out.target.calorie_target).toBeGreaterThan(out.low.calorie_target);
});

test('TC-FR3-99-AS-01', () => {
  // Starting Healthy Weight offers the question set; "Later" starts the track with none answered, and the Today screen
  // then asks two of them.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    return ['started' => LLD_Members_Programme::$started, 'answered' => LLD_Members_HW_Answers::qod_state(7), 'today' => LLD_Members_HW_Programme::today(7),
      'set' => array_column(LLD_Members_HW_Answers::questions('track_start'), 'key')];
  `);
  expect(out.started).toEqual([[7, 'healthy-weight']]);
  expect(out.answered).toEqual({ answered: [], known: [] });
  expect(out.today.qod.map((q) => q.key)).toEqual(['food_rules_allergies', 'eating_pattern']);
  expect(out.set).toEqual(['food_rules', 'fasts', 'allergies', 'breakfast', 'breakfast_protein', 'snack_time', 'sugary_drinks', 'eats_out', 'cooking_time', 'cooking_skill', 'budget', 'cuisines', 'enjoyment']);
});

test('TC-FR3-99-AS-03', () => {
  // Food rules and allergies already in the food preferences (FR3-36) are reused, so that question is not asked again.
  const out = run(`
    member(7);
    LLD_Members_Stage3_Data::insert('food_preferences', 7, ['allergies' => '["nuts"]', 'dislikes' => '[]']);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    return ['state' => LLD_Members_HW_Answers::qod_state(7), 'qod' => array_column(LLD_Members_HW_Programme::today(7)['qod'], 'key'), 'allergies' => LLD_Members_HW_Answers::get(7, 'allergies')];
  `);
  expect(out.state.known).toEqual(['food_rules_allergies']);
  expect(out.qod).toEqual(['eating_pattern', 'snack_time']);
  expect(out.allergies).toEqual(['nuts']);
});

test('TC-FR3-99-AS-05', () => {
  // Changing an answer in the account hub changes the plan from the next day: the barrier moves its step to day 3.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ['barrier' => 'not_sure']);
    LLD_Members_Programme::$today = '2026-11-14';
    $before = LLD_Members_HW_Programme::today(7, '2026-11-14')['step'];
    LLD_Members_HW_Answers::set(7, 'barrier', 'evening_snacking', 'account_hub');
    $after = LLD_Members_HW_Programme::today(7, '2026-11-14')['step'];
    return compact('before', 'after');
  `);
  expect(out.before.activity_key).toBe('HW-W1-A3');
  expect(out.after.reason).toBe('Chosen first because you said evening snacking gets in the way.');
  expect(out.after.activity_key).not.toBe(out.before.activity_key);
});

test('TC-FR3-98-AS-08', () => {
  // A member whose food preferences say halal is not asked about alcohol: "never" is recorded for every rule, so there
  // is no drinks line, card, step or shortcut, whatever was answered before.
  const out = run(`
    member(7);
    LLD_Members_HW_Answers::set(7, 'food_rules', ['halal']);
    $gid = weight_goal(7);
    $v = LLD_Members_HW_Programme::join(7, $gid, ['alcohol_freq' => 'most_days', 'alcohol_amount' => '5_plus']);
    LLD_Members_Clock::$now = '2026-11-12 20:00:00';
    return ['engine' => LLD_Members_HW_Answers::engine(7), 'v' => $v, 't' => LLD_Members_HW_Programme::today(7)];
  `);
  expect(out.engine.alcohol_freq).toBe('never');
  expect(out.v.drinks).toBeNull();
  expect(out.v.cards).toEqual([]);
  expect(out.t.drink_shortcut.show).toBe(false);
});

test('TC-FR3-98-AS-07', () => {
  // The drinks-a-time answer takes only 1 to 2, 3 to 4 or 5 or more; with "never" it is not needed and the join goes on.
  const out = run(`
    member(7);
    $bad = LLD_Members_HW_Answers::set(7, 'alcohol_amount', '10');
    $gid = weight_goal(7);
    $v = LLD_Members_HW_Programme::join(7, $gid, ['alcohol_freq' => 'never']);
    return ['bad' => $bad->get_error_code(), 'choices' => array_values(array_filter(LLD_Members_HW_Answers::questions('joining'), function ($q) { return 'alcohol_amount' === $q['key']; }))[0]['choices'], 'drinks' => $v['drinks']];
  `);
  expect(out.bad).toBe('lld_hw_answer');
  expect(out.choices).toEqual(['1_2', '3_4', '5_plus']);
  expect(out.drinks).toBeNull();
});

test('TC-FR3-110-AS-01', () => {
  // The plan shows the drinks of a usual week (1 to 2 days, 3 to 4 a time: about 680 kcal a week, roughly 100 a day),
  // and the calorie target is the same as with "never".
  const out = run(`
    member(7); member(8);
    $g7 = weight_goal(7); $g8 = weight_goal(8);
    $a = LLD_Members_HW_Programme::join(7, $g7, ['alcohol_freq' => '1_2_days', 'alcohol_amount' => '3_4']);
    $b = LLD_Members_HW_Programme::join(8, $g8, ['alcohol_freq' => 'never']);
    return compact('a', 'b');
  `);
  expect(out.a.drinks).toEqual({ weekly: 680, daily: 100 });
  expect(out.a.target_kcal).toBe(out.b.target_kcal);
  expect(out.a.reasons.find((r) => r.rule === 'R-HW-NUM-05').text).toContain('about 680 kcal, roughly 100 a day');
});

test('TC-FR3-102-AS-08', () => {
  // A member with an eating-disorder flag (added after joining) sees no calorie number on the plan or Today.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ['alcohol_freq' => '3_4_days', 'alcohol_amount' => '3_4']);
    LLD_Members_Stage3_Safety::$situations[7] = ['eating_disorder'];
    return ['v' => LLD_Members_HW_Programme::plan_view(7), 't' => LLD_Members_HW_Programme::today(7)];
  `);
  expect(out.v).toMatchObject({ tdee: null, target_kcal: null, drinks: null, milestones: [] });
  expect(JSON.stringify(out.v.reasons)).not.toMatch(/kcal/);
  expect(out.t.repeat_meal).toBeNull();
  expect(out.t.reason_line).toBeNull();
  expect(out.t.drink_shortcut.show).toBe(false);
});

test('P3-35 shapes: plan_view() and today() keep the keys agreed with package P4', () => {
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ['reason' => 'health', 'alcohol_freq' => '1_2_days', 'alcohol_amount' => '1_2']);
    return ['v' => LLD_Members_HW_Programme::plan_view(7), 't' => LLD_Members_HW_Programme::today(7), 'none' => LLD_Members_HW_Programme::plan_view(9)];
  `);
  expect(Object.keys(out.v)).toEqual(['where', 'activity', 'tdee', 'target_kcal', 'pace', 'drinks', 'milestones', 'cards', 'reasons', 'milestone_reached', 'celebrated']);
  expect(Object.keys(out.v.where)).toEqual(['bmi', 'category', 'adjusted', 'range', 'waist']);
  expect(Object.keys(out.v.pace)).toEqual(['recommended', 'chosen', 'rule', 'reasons', 'paces']);
  expect(out.v.pace.paces).toEqual([0.25, 0.5, 0.75]);
  expect(Object.keys(out.v.drinks)).toEqual(['weekly', 'daily']);
  for (const r of out.v.reasons) expect(Object.keys(r)).toEqual(['rule', 'key', 'text']);
  expect(Object.keys(out.t)).toEqual(['note', 'step', 'qod', 'drink_shortcut', 'repeat_meal', 'reason_line', 'carry_on', 'cards']);
  expect(Object.keys(out.t.note)).toEqual(['key', 'title', 'body', 'try_this', 'support']);
  expect(Object.keys(out.t.step)).toEqual(['activity_key', 'version', 'reason']);
  expect(out.t.qod.length).toBeLessThanOrEqual(2);
  expect(out.t.reason_line).toBe('For your health: 40 weeks to go.');
  expect(out.v.where).toMatchObject({ bmi: 30.1, category: 'obese1', adjusted: false });
  expect(out.none).toBeNull();
});

test('P3-35 tiers: a Free member gets the track order in the standard version; Premium is personalised (Q163)', () => {
  const answers = { time: 'under_10', confidence: 3, barrier: 'evening_snacking', sleep: '6_7' };
  const out = run(`
    member(7, ['premium' => false]); member(8);
    $g7 = weight_goal(7); $g8 = weight_goal(8);
    LLD_Members_HW_Programme::join(7, $g7, ${arr(answers)});
    LLD_Members_HW_Programme::join(8, $g8, ${arr(answers)});
    LLD_Members_Programme::$today = '2026-11-14';
    return ['free' => LLD_Members_HW_Programme::today(7, '2026-11-14'), 'prem' => LLD_Members_HW_Programme::today(8, '2026-11-14'), 'fv' => LLD_Members_HW_Programme::plan_view(7)];
  `);
  expect(out.free.step).toEqual({ activity_key: 'HW-W1-A3', version: 'standard', reason: null });
  expect(out.prem.step.version).toBe('easier');
  expect(out.prem.step.reason).toContain('evening snacking');
  expect(out.free.qod.length).toBe(2); // quick questions are Free
  expect(out.fv.pace.recommended).toBe(0.25); // pace, numbers and milestones are Free
  expect(out.fv.milestones.length).toBe(4);
});

test('P3-35 package P4 calls: join({pace, today}), record_barrier($choices, $today), choose_block($slug, $today), carry_on($today), repeat_meal', () => {
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Answers::set(7, 'stress', 8);
    $j = LLD_Members_HW_Programme::join(7, ['pace' => 0.5, 'today' => '2026-11-12']);
    $b = LLD_Members_HW_Programme::record_barrier(7, ['time'], '2026-11-19');
    $c = LLD_Members_HW_Programme::choose_block(7, 'getting-stronger', '2026-11-19');
    $k = LLD_Members_HW_Programme::carry_on(7, '2026-11-19');
    $r = LLD_Members_HW_Programme::repeat_meal(7, '2026-11-19');
    return ['goal' => raw('goals')[0], 'j' => $j['pace'], 'b' => $b, 'bar' => raw('check_in_barriers')[0]['check_in_date'], 'c' => $c->get_error_code(), 'k' => $k->get_error_code(), 'r' => $r->get_error_code(), 'blocks' => LLD_Members_HW_Programme::blocks(7, '2026-11-19')];
  `);
  expect(out.goal).toMatchObject({ recommended_rate: 0.25, chosen_rate: 0.5 });
  expect(out.j.chosen).toBe(0.5);
  expect(out.b.saved).toBe(true);
  expect(out.bar).toBe('2026-11-19');
  expect(out.c).toBe('lld_hw_block'); // focus blocks start after week 8
  expect(out.k).toBe('lld_hw_carry_on');
  expect(out.r).toBe('lld_hw_repeat');
  expect(out.blocks).toMatchObject({ due: false, keep_it_off: false });
});
