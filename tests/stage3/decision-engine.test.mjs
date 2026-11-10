// Decision engine (P3-34; FR3-17, FR3-12, FR3-14, FR3-15, NFR3-17): the rule catalogue and the pure engine are run in
// PHP CLI without WordPress against the frozen fixtures (fixtures/decision-rules/), and the wording is checked against
// the signed approval record (approvals/stage3/decision-rules.json). The state builders below translate each fixture's
// inputs into the daily values the engine reads, as the runner would build them from the tables.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engineDir = resolve(plugin, 'includes/stage3/engine');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const preamble = `define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); require ${toPhpPath(resolve(engineDir, 'class-rule-catalogue.php'))}; require ${toPhpPath(resolve(engineDir, 'class-decision-engine.php'))};`;
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${preamble} ${code}`], { encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const rulesFx = json('fixtures/decision-rules/rules.json').fixtures;
const engineFx = json('fixtures/decision-rules/engine.json').fixtures;
const approval = json('approvals/stage3/decision-rules.json');

const D = '2026-11-16'; // a Monday, so the weigh-in fixtures (Monday/Monday) read as written
const day = (n) => { const d = new Date(Date.UTC(2026, 10, 16)); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const bp = (s) => s.split('/').map(Number);
const range = (from, to) => Array.from({ length: to - from + 1 }, (_, i) => from + i);

/** Run rule() for many (name, state) pairs in one PHP call. */
const runRules = (cases) => JSON.parse(php(`echo json_encode(array_map(function ($c) { return LLD_Members_Decision_Engine::rule($c[0], $c[1]); }, json_decode(${JSON.stringify(JSON.stringify(cases))}, true)));`));
/** Run evaluate() for many states in one PHP call. */
const evaluate = (states) => JSON.parse(php(`echo json_encode(array_map(function ($s) { return LLD_Members_Decision_Engine::evaluate($s); }, json_decode(${JSON.stringify(JSON.stringify(states))}, true)));`));
// Every signed string of the catalogue: the 23 rules' templates and, from version 1.2, the Healthy Weight programme's
// T-HW templates (section 9), all hashed in the one approval record.
const templates = () => JSON.parse(php('echo json_encode(array_merge(LLD_Members_Rule_Catalogue::TEMPLATES, LLD_Members_Rule_Catalogue::HW_TEMPLATES));'));

// ---- State builders: one per rule, from the fixture's inputs (NFR3-17 AS-01 "made-up test data at the boundary") -----

const builders = {
  'R-SAFE-01': (i) => {
    const daily = {};
    const fill = (w, from, to) => { for (const n of range(from, to).slice(0, w.days_with_data)) daily[day(n)] = bp(w.daily_mean); };
    fill(i['window_1_D-14_to_D-8'], -14, -8);
    fill(i['window_2_D-7_to_D-1'], -7, -1);
    const s = { today: D, situations: i.pregnancy ? ['pregnancy'] : [], daily: { blood_pressure: daily } };
    if (i.urgent_reading_in_window) { daily[day(-5)] = bp(i.urgent_reading); s.urgent_dates = [day(-5)]; }
    return s;
  },
  'R-SAFE-01p': (i) => ({ today: D, situations: i.pregnancy ? ['pregnancy'] : [], readings_today: { blood_pressure: [bp(i.reading_today)] } }),
  'R-SAFE-03': (i) => {
    const weight = {};
    for (const [k, v] of Object.entries(i.weight_kg)) weight[day(Number(k.replace('D', '')))] = v;
    return { today: D, goal: i.active_lose_weight_goal ? { goal_type: 'lose_weight', measure: 'weight', target_value: 70 } : null, daily: { weight } };
  },
  'R-SAFE-04': (i) => {
    const mood = {};
    for (const [k, v] of Object.entries(i.mood_daily)) if (v !== null) mood[day(Number(k.replace('D', '')))] = v;
    return { today: D, daily: { mood } };
  },
  'R-STEP-01': (i) => ({ today: D, goal: { goal_type: i.goal_type, measure: 'blood_pressure' }, daily: { blood_pressure: { [day(Number(i.last_bp_reading.replace('D', '')))]: [130, 80] } } }),
  'R-STEP-02': (i) => ({
    today: D, // a Monday
    situations: [i.eating_disorder ? 'eating_disorder' : null, i.pregnancy ? 'pregnancy' : null].filter(Boolean),
    plan: { started: day(-28), weigh_in_weekday: 0 },
    daily: { weight: { [day(-i.last_weight_reading_days_ago)]: 80 } },
  }),
  'R-STEP-04': (i) => {
    const started = day(1 - i.plan_week_day); // today is day n of the plan week
    const activity = {};
    if (i.activity_minutes_this_week > 0) activity[day(1 - i.plan_week_day)] = i.activity_minutes_this_week; // all on day 1 of the week
    return { today: D, plan: { started, activity_minutes_week: i.weekly_target_minutes }, daily: { activity } };
  },
  'R-ADJ-01': (i) => {
    const check_ins = Object.entries(i.check_ins).map(([k, c]) => ({ date: day(k === 'D' ? 0 : Number(k.replace('D', ''))), projected_late_days: c.projected_late_days, weigh_ins_28: typeof c.weigh_ins_in_28_days === 'number' ? c.weigh_ins_in_28_days : 3 }));
    return { today: D, premium: true, at_check_in: true, goal: { goal_type: i.goal_type, measure: 'weight', target_value: 75, target_date: day(60), rate_kg_week: 0.5 }, plan: { started: day(-56), calorie_target: 1700, cap_kg_week: 0.8 }, check_ins, daily: { weight: { [day(-1)]: 82 } } };
  },
  'R-ADJ-02': (i) => {
    const weight = {};
    const lo = i.min_kg ?? 85.0 - i.range_kg / 2; const hi = i.max_kg ?? lo + i.range_kg;
    range(0, i.weigh_ins - 1).forEach((k) => { weight[day(-1 - k * 4)] = k === 0 ? lo : k === 1 ? hi : lo + (hi - lo) / 2; });
    return { today: D, premium: true, at_check_in: true, goal: { goal_type: i.goal_type, measure: 'weight', target_value: 75, reached: i.goal_reached }, plan: { started: day(-56), calorie_target: 1700 }, daily: { weight } };
  },
  'R-ADJ-03': (i) => {
    const n = typeof i.weigh_ins_in_28_days === 'number' ? i.weigh_ins_in_28_days : 5;
    const weight = {};
    // a straight line losing slope kg a week, weekly weigh-ins: the earliest is the heaviest (90 kg)
    range(0, n - 1).forEach((k) => { weight[day(-1 - k * 7)] = Math.round((90 - (n - 1 - k) * i.slope_kg_per_week) * 1000) / 1000; });
    return { today: D, premium: true, goal: { goal_type: i.goal_type, measure: 'weight', target_value: 75 }, plan: { started: day(-56), calorie_target: 1700, cap_kg_week: i.cap_kg_per_week }, daily: { weight } };
  },
  'R-INS-01': (i) => {
    const paired = i.paired_days; const short = i.short_nights ?? 7; const long = i.long_nights ?? (paired - short);
    const diff = i.difference_mmHg ?? 6; const sysShort = i.mean_systolic_short ?? 132 + diff; const sysLong = i.mean_systolic_long ?? 132;
    const sleep = {}; const bpd = {};
    let n = 1;
    for (let k = 0; k < short; k++, n++) { sleep[day(-n)] = 5.5; bpd[day(-n)] = [sysShort, 86]; }
    for (let k = 0; k < long; k++, n++) { sleep[day(-n)] = 7.5; bpd[day(-n)] = [sysLong, 84]; }
    for (; n <= paired; n++) { sleep[day(-n)] = 6.5; bpd[day(-n)] = [135, 85]; } // 6 to < 7 h: paired but excluded from the comparison
    const s = { today: D, premium: true, situations: i.pregnancy ? ['pregnancy'] : [], daily: { sleep, blood_pressure: bpd } };
    if (i.last_shown_days_ago) s.shown = { 'R-INS-01': day(-i.last_shown_days_ago) };
    return s;
  },
  'R-INS-02': (i) => {
    const bpd = {};
    const now = i.last_28_day_average ? bp(i.last_28_day_average) : [141 + i.systolic_change, 88];
    const before = i.previous_28_day_average ? bp(i.previous_28_day_average) : [141, 90];
    const daysNow = i.days_with_data_one_window ?? 12; const daysBefore = 12;
    range(1, daysNow).forEach((n) => { bpd[day(-n)] = now; });
    range(29, 28 + daysBefore).forEach((n) => { bpd[day(-n)] = before; });
    return { today: D, premium: true, daily: { blood_pressure: bpd }, urgent_dates: i.urgent_readings ? [day(-3)] : [] };
  },
  'R-INS-03': (i) => {
    const mood = {}; const activity = {};
    const ma = i.mean_mood_active ?? 3.1 + i.difference; const mi = i.mean_mood_inactive ?? 3.1;
    let n = 1;
    for (let k = 0; k < i.active_days; k++, n++) { mood[day(-n)] = ma; activity[day(-n)] = 30; }
    for (let k = 0; k < i.inactive_days; k++, n++) { mood[day(-n)] = mi; }
    return { today: D, premium: true, daily: { mood, activity } };
  },
  'R-INS-04': (i) => {
    const sleep = {};
    const nights = i.nights_logged ?? 5;
    range(1, nights).forEach((n) => { sleep[day(-n)] = i.last_7_nights_mean_hours; });
    range(8, 7 + nights).forEach((n) => { sleep[day(-n)] = i.previous_7_nights_mean_hours; });
    return { today: D, premium: true, daily: { sleep } };
  },
  'R-INS-05': (i) => {
    const activity = {};
    const weeks = i.last_4_plan_weeks_moderate_equivalent_minutes; // oldest first; plan weeks start on the plan's weekday
    weeks.forEach((minutes, k) => { activity[day(-7 * (4 - k))] = minutes; });
    return { today: D, premium: true, plan: { started: day(-35) }, daily: { activity } };
  },
  'R-INS-06': (i) => nutrientState(i, 'fibre_g', i.fibre_target_g, 0.6),
  'R-INS-08': (i) => ({
    today: D, premium: true,
    situations: [i.eating_disorder ? 'eating_disorder' : null, i.pregnancy ? 'pregnancy' : null, i.bmi_under_18_5 ? 'bmi_under_18_5' : null].filter(Boolean),
    daily: { waist: { [day(-i.days_apart)]: i.waist_cm.start, [day(0)]: i.waist_cm.end }, weight: { [day(-i.days_apart)]: i.weight_kg.start, [day(0)]: i.weight_kg.end } },
  }),
};
function nutrientState(i, field, target, fraction) {
  const food = {};
  const n = i.days_with_2_plus_meals; const under = i.days_under_60_pct ?? i.days_under_80_pct ?? 0;
  range(1, n).forEach((k) => { food[day(-k)] = { meals: 2, [field]: k <= under ? target * fraction - 1 : target }; });
  food[day(0)] = { meals: 1, [field]: 0 }; // a day with one meal never counts (and today is outside the window)
  return { today: D, premium: true, situations: i.eating_disorder ? ['eating_disorder'] : [], targets: i.nutrition_target_set ? { fibre_g: 30, protein_g: 60, [field]: target } : null, food };
}
const templateKey = (t) => (t === 'T-SAFE-04s' ? 'rule.R-SAFE-04.today-step' : t === 'T-GEN-01' ? 'template.T-GEN-01' : 'rule.' + t.replace('T-', 'R-'));

test('TC-NFR3-17-AS-01', () => {
  // For every rule, its 'should fire' test data fires it and its 'should not fire' test data does not: every recorded
  // fixture in fixtures/decision-rules/rules.json, built into the engine's state and run through rule().
  const recorded = rulesFx.filter((f) => f.status === 'recorded');
  expect(recorded.length).toBe(56);
  const cases = recorded.map((f) => [f.inputs.rule, builders[f.inputs.rule](f.inputs)]);
  const results = runRules(cases);
  const problems = [];
  recorded.forEach((f, k) => {
    const r = results[k];
    if (r.fires !== f.expected.fires) problems.push(`${f.id}: fires ${r.fires} (${r.reason}) != ${f.expected.fires}`);
    if (f.expected.fires) {
      const rule = JSON.parse(php(`echo json_encode(LLD_Members_Rule_Catalogue::rule(${JSON.stringify(f.inputs.rule)}));`));
      if (rule.template !== templateKey(f.expected.template)) problems.push(`${f.id}: template ${rule.template}`);
      if (f.expected.cooldown_days !== undefined && rule.cooldown_days !== f.expected.cooldown_days) problems.push(`${f.id}: cooldown ${rule.cooldown_days} != ${f.expected.cooldown_days}`);
      if (rule.class !== f.expected.class) problems.push(`${f.id}: class ${rule.class} != ${f.expected.class}`);
      if (f.expected.priority !== undefined && rule.priority !== f.expected.priority) problems.push(`${f.id}: priority ${rule.priority}`);
      if (f.expected.today_step_template && rule.step_template !== templateKey(f.expected.today_step_template)) problems.push(`${f.id}: step template`);
      if (f.expected.suppresses_non_safety_output_days !== undefined && rule.suppresses_days !== f.expected.suppresses_non_safety_output_days) problems.push(`${f.id}: suppression days`);
      if (f.expected.counted_in_quota === false && rule.class === 'insight') problems.push(`${f.id}: counted in the quota`);
    }
  });
  expect(problems).toEqual([]);
  // The six fixtures recorded as pending (no boundary data in the catalogue v1): test data written here from each
  // rule's condition, for the owner to accept as fixtures. R-SAFE-02 is the save-time glucose rule (safety-stage3.test.mjs).
  const pending = rulesFx.filter((f) => f.status === 'pending').map((f) => f.inputs.rule).sort();
  expect(pending).toEqual(['R-INS-07', 'R-INS-09', 'R-INS-10', 'R-SAFE-02', 'R-STEP-03', 'R-STEP-05']);
  const plan = { started: day(-14) };
  const p = runRules([
    // R-STEP-03: 7 or more days since the last check-in (or the plan start) and not done this week.
    ['R-STEP-03', { today: D, plan, last_check_in: day(-7), check_in_done_this_week: false }],
    ['R-STEP-03', { today: D, plan, last_check_in: day(-5), check_in_done_this_week: false }],
    ['R-STEP-03', { today: D, plan, last_check_in: day(-7), check_in_done_this_week: true }],
    ['R-STEP-03', { today: D, plan: { started: day(-7) }, last_check_in: null, check_in_done_this_week: false }],
    // R-STEP-05: no reading or food entry of any type in D-3…D-1.
    ['R-STEP-05', { today: D, log_dates: [day(-4), day(0)] }],
    ['R-STEP-05', { today: D, log_dates: [day(-2)] }],
    // R-INS-07: as R-INS-06 for protein, < 80 % of the protein target on 5 or more of 7 days; silenced by kidney disease.
    ['R-INS-07', nutrientState({ days_with_2_plus_meals: 6, days_under_80_pct: 5, nutrition_target_set: true }, 'protein_g', 60, 0.8)],
    ['R-INS-07', nutrientState({ days_with_2_plus_meals: 7, days_under_80_pct: 4, nutrition_target_set: true }, 'protein_g', 60, 0.8)],
    ['R-INS-07', { ...nutrientState({ days_with_2_plus_meals: 6, days_under_80_pct: 5, nutrition_target_set: true }, 'protein_g', 60, 0.8), situations: ['kidney'] }],
    ['R-INS-07', { ...nutrientState({ days_with_2_plus_meals: 6, days_under_80_pct: 5, nutrition_target_set: true }, 'protein_g', 60, 0.8), situations: ['eating_disorder'] }],
    // R-INS-09: 14, 30, 60 or 100 consecutive days with at least one log, once per milestone.
    ['R-INS-09', { today: D, premium: true, log_dates: range(1, 14).map((n) => day(-n)) }],
    ['R-INS-09', { today: D, premium: true, log_dates: range(1, 13).map((n) => day(-n)) }],
    ['R-INS-09', { today: D, premium: true, log_dates: range(1, 14).map((n) => day(-n)), milestones_shown: [14] }],
    ['R-INS-09', { today: D, premium: true, log_dates: range(1, 30).map((n) => day(-n)), milestones_shown: [14] }],
    ['R-INS-09', { today: D, premium: true, log_dates: [...range(1, 10).map((n) => day(-n)), ...range(12, 20).map((n) => day(-n))] }],
    // R-INS-10: the goal measure's 7-day value meets the target (weight: 7-day mean <= target, D3-09); once per goal.
    ['R-INS-10', { today: D, goal: { goal_type: 'lose_weight', measure: 'weight', target_value: 75, unit: 'kg' }, daily: { weight: { [day(-1)]: 74.6, [day(-3)]: 74.9, [day(-5)]: 74.9 } } }],
    ['R-INS-10', { today: D, goal: { goal_type: 'lose_weight', measure: 'weight', target_value: 75, unit: 'kg' }, daily: { weight: { [day(-1)]: 74.6, [day(-3)]: 75.3, [day(-5)]: 75.3 } } }],
    ['R-INS-10', { today: D, goal: { goal_type: 'lose_weight', measure: 'weight', target_value: 75, unit: 'kg', reached: true }, daily: { weight: { [day(-1)]: 74.6, [day(-3)]: 74.9, [day(-5)]: 74.9 } } }],
    ['R-INS-10', { today: D, goal: { goal_type: 'lower_blood_pressure', measure: 'blood_pressure', target_value: 135, target_value_2: 85, unit: 'mmHg' }, daily: { blood_pressure: { [day(-1)]: [134, 84], [day(-2)]: [130, 80], [day(-4)]: [136, 86] } } }],
  ]);
  expect(p.map((r) => r.fires)).toEqual([true, false, false, true, true, false, true, false, false, false, true, false, false, true, false, true, false, false, true]);
  expect(p[8].reason).toBe('safety_check:kidney');
  // Boundaries compare raw values; rounding is for display only (review note): 1.96 % does not fire R-SAFE-03.
  const raw = runRules([
    ['R-SAFE-03', { today: D, daily: { weight: { [day(-8)]: 80.0, [day(-1)]: 78.43 } } }],
    ['R-SAFE-03', { today: D, daily: { weight: { [day(-8)]: 80.0, [day(-1)]: 78.4 } } }],
    ['R-INS-04', { today: D, premium: true, daily: { sleep: Object.fromEntries([...range(1, 5).map((n) => [day(-n), 5.98]), ...range(8, 12).map((n) => [day(-n), 6.52])]) } }],
    ['R-INS-04', { today: D, premium: true, daily: { sleep: Object.fromEntries([...range(1, 5).map((n) => [day(-n), 5.98]), ...range(8, 12).map((n) => [day(-n), 6.48])]) } }],
  ]);
  expect(raw.map((r) => r.fires)).toEqual([false, true, true, false]);
  expect(raw[1].values.pct).toBe(2);
  expect(raw[2].values).toEqual({ hours: 6, before: 6.5 });
  expect(p[10].values).toEqual({ days: 14 });
  expect(p[13].milestone).toBe(30);
  expect(p[15].values).toEqual({ goal: '75 kg' });
  expect(p[6].values).toEqual({ protein: 49, target: 60 });
  // Nutrient rules read the last 7 logged days, not the last 7 calendar days; a shown milestone covers every lower
  // one; R-INS-01 carries the on-medicine line for a member with high blood pressure (review should-fixes 5 to 7).
  const gaps = { today: D, premium: true, targets: { fibre_g: 30 }, food: Object.fromEntries([1, 2, 3, 4, 8, 9].map((n) => [day(-n), { meals: 2, fibre_g: 10 }])) };
  const q = runRules([
    ['R-INS-06', gaps],
    ['R-INS-06', { ...gaps, food: { ...gaps.food, [day(-15)]: { meals: 3, fibre_g: 30 }, [day(-16)]: { meals: 2, fibre_g: 30 } } }], // the 7th logged day is D-15; D-16 is outside
    ['R-INS-09', { today: D, premium: true, log_dates: range(1, 31).map((n) => day(-n)), milestones_shown: [30] }],
    ['R-INS-09', { today: D, premium: true, log_dates: range(1, 60).map((n) => day(-n)), milestones_shown: [14] }],
    ['R-INS-01', { ...fr3_17(14), situations: ['blood_pressure'] }],
    ['R-INS-01', fr3_17(14)],
  ]);
  expect(q[0]).toMatchObject({ fires: true, values: { fibre: 10, target: 30 } });
  expect(q[1]).toMatchObject({ fires: true, values: { fibre: 13, target: 30 } });
  expect(q[2]).toMatchObject({ fires: false, reason: 'milestone_already_shown' });
  expect(q[3].milestone).toBe(60);
  expect(q[4].notes).toEqual(['on_medicine_line']);
  expect(q[5].notes).toEqual([]);
});

test('TC-NFR3-17-AS-02', () => {
  // Every rule's message matches an approved template: every string in the catalogue hashes to the approval record,
  // every rule names a template that exists, and fill() changes nothing but the braces.
  const T = templates();
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = T[key] === undefined ? '(missing)' : createHash('sha256').update(T[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(T).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  const rules = JSON.parse(php('echo json_encode(LLD_Members_Rule_Catalogue::rules());'));
  expect(Object.keys(rules)).toHaveLength(23);
  for (const [name, r] of Object.entries(rules)) {
    if (name === 'R-SAFE-02') { expect(r.template).toBeNull(); continue; } // at save time, Part C wording (safety matrix)
    expect(T[r.template], name).toBeDefined();
  }
  expect(Object.values(rules).filter((r) => r.class === 'safety')).toHaveLength(5);
  expect(Object.values(rules).filter((r) => r.class === 'today_step')).toHaveLength(5);
  expect(Object.values(rules).filter((r) => r.class === 'plan_adjustment')).toHaveLength(3);
  expect(Object.values(rules).filter((r) => r.class === 'insight' || r.class === 'event')).toHaveLength(10);
  const filled = JSON.parse(php(`echo json_encode([LLD_Members_Rule_Catalogue::fill('rule.R-STEP-04', ['done' => 40, 'target' => 150, 'suggest' => 30, 'evil' => 'x']), LLD_Members_Rule_Catalogue::fill('rule.R-INS-03', ['mood_active' => 3.9, 'mood_rest' => 3.1]), LLD_Members_Rule_Catalogue::fill('rule.R-SAFE-03', ['pct' => 2.1, 'date' => '2026-11-08']), LLD_Members_Rule_Catalogue::fill('no.such', [])]);`));
  expect(filled[0]).toBe("You're at **40** of **150** minutes this week. A **30**-minute brisk walk today keeps you on track. [Done] [Skip]");
  expect(filled[1]).toBe('On days you were active, you rated your mood **3.9** out of 5 on average; on other days **3.1**. Movement seems to lift your mood. Even a 10-minute walk counts.');
  expect(filled[2]).toMatch(/^Your weight has gone down by \*\*2\.1 %\*\* since \*\*2026-11-08\*\* without a weight goal\./);
  expect(filled[3]).toBeNull();
  // No template names a diagnosis or a medicine, and none praises kilograms lost (catalogue section 1; FR3-16 AS-04).
  // (The T-HW programme templates have the same check, with their signed pace phrase, in tests/stage3/hw-engine/catalogue.test.mjs.)
  for (const [key, text] of Object.entries(T).filter(([k]) => !k.startsWith('hw.'))) {
    expect(text, key).not.toMatch(/\b(you have|diagnos|hypertension|depression|beta-blocker|insulin|metformin|statin)\b/i);
    expect(text, key).not.toMatch(/\b(kg|kilograms?|pounds?|lb)\b[^.]*\blost\b|\blost\b[^.]*\b(kg|kilograms?|pounds?)\b/i);
  }
});

test('TC-NFR3-17-AS-03', () => {
  // Changing an approved rule or its wording without a recorded decision fails CI: the approval record is signed and
  // frozen, the catalogue's strings are hashed against it (AS-02 above), the fixtures' expected values are protected by
  // oracle-guard and validated by validate-artefacts, and the engine holds no second copy of any wording.
  expect(approval.clinical_signoff.decision).toBe('approved');
  expect(approval.decisions_ratified).toEqual(['D3-43', 'D3-44', 'D3-45', 'D3-46', 'D3-47', 'D3-48', 'D3-49', 'D3-58', 'D3-59']); // D3-49: version 1.1, R-ADJ-03 for every calorie plan (Owner Q68); D3-58: v1.2, the Healthy Weight rules; D3-59: v1.3, R-HW-BLOCK-03
  expect(approval.regulatory_statement).toMatch(/Frozen: any change needs a new decision/);
  expect(approval.version_hash).toMatch(/^[0-9a-f]{64}$/); // SHA-256 of the signed catalogue file (outside the repository)
  const validator = readFileSync(resolve(root, 'tests/ci/validate-artefacts.py'), 'utf8');
  expect(validator).toMatch(/fixtures\/\*\/\*\.json/);
  expect(validator).toMatch(/approvals\/stage3\/\*\.json/);
  const guard = readFileSync(resolve(root, 'tests/ci/oracle-guard.mjs'), 'utf8');
  expect(guard).toMatch(/approvals\/stage3\/\$\{tool\}\.json/);
  const engineSrc = readFileSync(resolve(engineDir, 'class-decision-engine.php'), 'utf8');
  const runnerSrc = readFileSync(resolve(engineDir, 'class-insights-runner.php'), 'utf8');
  for (const text of Object.values(templates())) {
    expect(engineSrc).not.toContain(text.slice(0, 40));
    expect(runnerSrc).not.toContain(text.slice(0, 40));
  }
  expect(engineSrc).toMatch(/LLD_Members_Rule_Catalogue::fill\(/);
  // The pure engine makes no WordPress, database or network call (Build Method Stage 3 §3; NFR3-17).
  for (const f of ['class-decision-engine.php', 'class-rule-catalogue.php']) {
    expect(readFileSync(resolve(engineDir, f), 'utf8'), f).not.toMatch(/add_action|add_filter|apply_filters|\$wpdb|get_option|wp_remote|wp_mail|error_log|date\(|time\(\)/);
  }
});

// The FR3-17 member: 14 paired nights, 7 short with systolic 138 and 7 longer with 132 (difference 6).
const fr3_17 = (paired) => builders['R-INS-01']({ pregnancy: false, paired_days: paired, short_nights: Math.min(7, Math.floor(paired / 2)), long_nights: paired - Math.min(7, Math.floor(paired / 2)), mean_systolic_short: 138, mean_systolic_long: 132 });

test('TC-FR3-17-AS-01', () => {
  // For a test member with 14 nights of sleep paired with blood pressure readings that are 6 points higher after short
  // nights, the sleep and blood pressure insight is shown.
  const [r] = evaluate([{ ...fr3_17(14), premium: true }]);
  expect(r.insight.rule).toBe('R-INS-01');
  expect(r.insight.outcome).toBe('shown');
  expect(r.insight.values).toEqual({ bp_short: '138/86', bp_long: '132/84' });
  expect(r.insight.text).toBe('After nights under 6 hours, your blood pressure averaged **138/86**, compared with **132/84** after 7 hours or more. Better sleep may be helping your blood pressure. Week 1 of Sleep and Stress has three habits that help.');
  expect(r.insight.counted_in_quota).toBe(true);
  expect(r.safety).toEqual([]);
});

test('TC-FR3-17-AS-02', () => {
  // For a test member with only 10 paired nights, no such insight is shown.
  const [r, diff4, three] = evaluate([
    { ...fr3_17(10), premium: true },
    { ...builders['R-INS-01']({ pregnancy: false, paired_days: 14, short_nights: 7, long_nights: 7, difference_mmHg: 4 }), premium: true },
    { ...builders['R-INS-01']({ pregnancy: false, paired_days: 14, short_nights: 3, long_nights: 11, difference_mmHg: 6 }), premium: true },
  ]);
  // No sleep-and-blood-pressure insight for any of them (another rule may still hold on the same data).
  for (const x of [r, diff4, three]) {
    expect(x.insight?.rule).not.toBe('R-INS-01');
    expect(x.insights.map((o) => o.rule)).not.toContain('R-INS-01');
  }
  expect(r.rules['R-INS-01']).toEqual({ fires: false, reason: 'min_data', values: [], notes: [] });
  expect(diff4.rules['R-INS-01'].reason).toBe('difference_below_5');
  expect(three.rules['R-INS-01'].reason).toBe('min_data');
});

test('TC-FR3-17-AS-03', () => {
  // No member sees more than 3 insights in a week, and the same insight is not repeated within 4 weeks (ENG-02, ENG-03):
  // the night's decision for a qualifying R-INS-02, then a 40-night simulation with every night's result fed back.
  const qualifies = builders['R-INS-02']({ previous_28_day_average: '141/90', last_28_day_average: '135/88', days_with_data_each_window: '>= 12', urgent_readings: false });
  const [quota, nextDay, cooldown, after] = evaluate([
    { ...qualifies, today: D, insights_shown_dates: [day(-6), day(-4), day(-2)] },
    { ...qualifies, today: day(1), insights_shown_dates: [day(-6), day(-4), day(-2)] },
    { ...qualifies, today: D, shown: { 'R-INS-02': day(-20) } },
    { ...qualifies, today: day(8), shown: { 'R-INS-02': day(-20) } },
  ]);
  expect(quota.insight).toBeNull();
  expect(quota.insights[0]).toMatchObject({ rule: 'R-INS-02', outcome: 'quota' });
  expect(nextDay.insight.rule).toBe('R-INS-02'); // the D-6 one has left the 7-day window
  expect(cooldown.insights[0]).toMatchObject({ rule: 'R-INS-02', outcome: 'cooldown', cooldown_days: 28 });
  expect(after.insight.rule).toBe('R-INS-02');
  // Simulation: a member who qualifies for R-INS-01, R-INS-02, R-INS-03 and R-INS-04 every night for 40 nights. The
  // engine's own output is the only history: never more than 3 in any 7 days, never the same rule within 28 days.
  const base = { ...fr3_17(14), premium: true };
  const ins2 = qualifies.daily.blood_pressure; // R-INS-02 needs 12 days in each window; merge its blood pressure days under different dates
  const shown = []; const history = {};
  const sim = JSON.parse(php(`
    $base = json_decode(${JSON.stringify(JSON.stringify(base))}, true);
    $shown = []; $history = []; $log = [];
    for ($n = 0; $n < 40; $n++) {
      $today = LLD_Members_Decision_Engine::add_days('${D}', $n);
      // shift every dated input forward with the run date so the member keeps qualifying
      $s = $base; $s['today'] = $today; $s['daily'] = [];
      foreach ($base['daily'] as $type => $days) foreach ($days as $date => $v) $s['daily'][$type][LLD_Members_Decision_Engine::add_days($date, $n)] = $v;
      for ($i = 1; $i <= 56; $i++) { $d = LLD_Members_Decision_Engine::add_days($today, -$i); if (!isset($s['daily']['blood_pressure'][$d])) $s['daily']['blood_pressure'][$d] = $i <= 28 ? [135, 88] : [141, 90]; }
      for ($i = 1; $i <= 28; $i++) { $d = LLD_Members_Decision_Engine::add_days($today, -$i); $s['daily']['mood'][$d] = $i % 2 ? 3.9 : 3.1; if ($i % 2) $s['daily']['activity'][$d] = 30; }
      for ($i = 8; $i <= 14; $i++) $s['daily']['sleep'][LLD_Members_Decision_Engine::add_days($today, -$i)] = 7.0;
      for ($i = 1; $i <= 7; $i++) $s['daily']['sleep'][LLD_Members_Decision_Engine::add_days($today, -$i)] = 5.8;
      $s['shown'] = $history; $s['insights_shown_dates'] = $shown;
      $r = LLD_Members_Decision_Engine::evaluate($s);
      $qual = array_map(function ($o) { return $o['rule']; }, $r['insights']);
      if ($r['insight']) { $shown[] = $today; $history[$r['insight']['rule']] = $today; $log[] = [$today, $r['insight']['rule'], $qual]; } else $log[] = [$today, null, $qual];
    }
    echo json_encode($log);
  `));
  expect(sim).toHaveLength(40);
  const shownNights = sim.filter(([, rule]) => rule);
  expect(shownNights.length).toBeGreaterThanOrEqual(8);
  for (let k = 0; k < sim.length; k++) {
    const window = sim.slice(Math.max(0, k - 6), k + 1).filter(([, rule]) => rule);
    expect(window.length, `week ending ${sim[k][0]}`).toBeLessThanOrEqual(3);
  }
  const byRule = {};
  for (const [date, rule] of shownNights) {
    if (byRule[rule]) expect((Date.parse(date) - Date.parse(byRule[rule])) / 86400000, rule).toBeGreaterThanOrEqual(28);
    byRule[rule] = date;
  }
  expect(sim[0][1]).toBe('R-INS-01'); // priority (ENG-01)
  expect(sim[0][2].length).toBeGreaterThanOrEqual(3);
  expect(sim[1][1]).toBe('R-INS-02');
  expect(sim[2][1]).toBe('R-INS-03');
  expect(sim[3][1]).toBeNull(); // quota: 3 shown in the last 7 days
  expect(sim[7][1]).toBe('R-INS-04'); // the first night free of the quota
});

test('TC-FR3-17-AS-04', () => {
  // Every insight shown matches an approved template exactly, with the member's own numbers filled in (ENG-12): each
  // shown text equals its template with only the {gaps} replaced, for every rule that fires in the fixtures.
  const T = templates();
  const recorded = rulesFx.filter((f) => f.status === 'recorded' && f.expected.fires);
  const states = recorded.map((f) => ({ ...builders[f.inputs.rule](f.inputs), premium: true, at_check_in: true }));
  const results = evaluate(states);
  let checked = 0;
  results.forEach((r, k) => {
    const all = [...r.safety, ...(r.today_step && r.today_step.rule ? [r.today_step] : []), ...r.adjustments, ...r.insights, ...r.events];
    const o = all.find((x) => x.rule === recorded[k].inputs.rule);
    expect(o, recorded[k].id).toBeTruthy();
    const template = T[o.template];
    const pattern = '^' + template.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\{[a-z_]+\\\}/g, '(.+?)') + '$';
    expect(o.text, `${recorded[k].id}: ${o.text}`).toMatch(new RegExp(pattern));
    for (const gap of template.match(/\{[a-z_]+\}/g) || []) {
      expect(o.text, `${recorded[k].id} left ${gap}`).not.toContain(gap);
      const v = o.values[gap.slice(1, -1)];
      expect(v, `${recorded[k].id} ${gap}`).toBeDefined();
      expect(String(v)).toMatch(/^[0-9][0-9./ -]*$/); // numbers and dates only
    }
    checked++;
  });
  expect(checked).toBe(recorded.length);
});

test('TC-NFR3-17-AS-01 (engine level): precedence, suppression, the free member and the matrix at display time', () => {
  // ENG-01, ENG-04 to ENG-07 and ENG-11 from fixtures/decision-rules/engine.json.
  expect(engineFx.map((f) => f.id)).toEqual(range(1, 13).map((n) => `FX-DR-ENG-${String(n).padStart(2, '0')}`));
  const ins2 = builders['R-INS-02']({ previous_28_day_average: '134/82', last_28_day_average: '128/80', days_with_data_each_window: '>= 12', urgent_readings: false }); // below the R-SAFE-01 level
  const lowMood = builders['R-SAFE-04']({ mood_daily: { 'D-3': 2, 'D-2': 1, 'D-1': 2 } });
  const safe1 = builders['R-SAFE-01']({ pregnancy: false, 'window_1_D-14_to_D-8': { daily_mean: '136/84', days_with_data: 4 }, 'window_2_D-7_to_D-1': { daily_mean: '138/86', days_with_data: 3 }, urgent_reading_in_window: false });
  const merge = (a, b) => ({ ...a, ...b, daily: { ...a.daily, ...b.daily } });
  const [eng04, eng04b, eng05, eng06, eng07, stepLow] = evaluate([
    merge(merge(ins2, lowMood), { premium: true }),                                    // ENG-04: R-SAFE-04 fires on D, R-INS-02 qualifies
    { ...ins2, today: day(3), premium: true, shown: { 'R-SAFE-04': D }, daily: { blood_pressure: Object.fromEntries(Object.entries(ins2.daily.blood_pressure).map(([d, v]) => [day(3 + (Date.parse(d) - Date.parse(D)) / 86400000), v])) } }, // D+3
    { ...fr3_17(14), premium: false },                                                  // ENG-05: Free member, R-INS-01 qualifies
    { ...safe1, premium: false },                                                       // ENG-06: Free member, R-SAFE-01 qualifies
    { ...builders['R-INS-06']({ eating_disorder: true, nutrition_target_set: true, fibre_target_g: 30, days_with_2_plus_meals: 6, days_under_60_pct: 5 }), premium: true }, // ENG-07: ED flag
    { ...lowMood, today: day(5), shown: { 'R-SAFE-04': D }, plan: { started: day(-20), activity_minutes_week: 150 } }, // the gentle step while suppression lasts
  ]);
  expect(eng04.safety.map((o) => o.rule)).toEqual(['R-SAFE-04']);
  expect(eng04.safety[0].text).toMatch(/^It looks like the last few days have been hard\./);
  expect(eng04.suppressed).toBe(true);
  expect(eng04.insight).toBeNull();
  expect(eng04.insights[0]).toMatchObject({ rule: 'R-INS-02', outcome: 'suppressed' });
  expect(eng04.today_step).toMatchObject({ rule: 'R-SAFE-04', template: 'rule.R-SAFE-04.today-step', text: "A gentle day. If you'd like, take a 10-minute walk outside. [Done] [Skip]" });
  expect(eng04b.insight).toBeNull(); // D+3: still inside the 7 days
  expect(eng04b.insights[0].outcome).toBe('suppressed');
  expect(eng05.insight).toBeNull();
  expect(eng05.insights[0]).toMatchObject({ rule: 'R-INS-01', outcome: 'premium_required' });
  expect(eng06.safety[0]).toMatchObject({ rule: 'R-SAFE-01', outcome: 'shown', counted_in_quota: false });
  expect(eng07.insight).toBeNull();
  expect(eng07.rules['R-INS-06']).toMatchObject({ fires: false, reason: 'safety_check:eating_disorder' });
  expect(stepLow.today_step.rule).toBe('R-SAFE-04');
  // ENG-11: an insight already shown is not withdrawn when its data is later edited: the engine only ever adds,
  // and the cooldown reads the shown date, not the data.
  const [edited] = evaluate([{ today: day(1), premium: true, shown: { 'R-INS-02': D }, insights_shown_dates: [D], daily: { blood_pressure: { [day(-1)]: [141, 90] } } }]);
  expect(edited.rules['R-INS-02'].fires).toBe(false);
  expect(Object.keys(edited)).not.toContain('withdrawn');
  const engineSrc = readFileSync(resolve(engineDir, 'class-decision-engine.php'), 'utf8');
  expect(engineSrc).not.toMatch(/withdraw|dismiss/i);
  // ENG-13: the engine and the runner never send an email; the reminder emails are P3-85's.
  expect(engineSrc).not.toMatch(/wp_mail|LLD_Members_Mail/);
  expect(readFileSync(resolve(engineDir, 'class-insights-runner.php'), 'utf8')).not.toMatch(/wp_mail\(|LLD_Members_Mail|error_log\(|trigger_error\(|var_dump\(|print_r\(/);
});

test('TC-FR3-12-AS-03', () => {
  // For a test member with a blood pressure goal and no reading in 7 days, the Next step card suggests a blood pressure
  // check; one step a day in priority order; the plan's item or T-GEN-01 (at most once a week) when no rule holds.
  const bpGoal = { today: D, goal: { goal_type: 'lower_blood_pressure', measure: 'blood_pressure' }, plan: { started: day(-20), weigh_in_weekday: 0, activity_minutes_week: 150 }, daily: { blood_pressure: { [day(-8)]: [130, 80] } }, log_dates: [day(-8)] };
  const [due, recent, noPlan, genAgain, quiet] = evaluate([
    bpGoal,
    { ...bpGoal, daily: { blood_pressure: { [day(-7)]: [130, 80] } }, log_dates: [day(-1)] },
    { today: D, log_dates: [day(-1)] },
    { today: D, log_dates: [day(-1)], gen_shown: day(-3) },
    { today: D, plan: { started: day(-3) }, log_dates: [day(-1)] },
  ]);
  expect(due.today_step).toMatchObject({ rule: 'R-STEP-01', action: 'log_reading', text: 'Take your blood pressure today: sit quietly for 5 minutes, then take two readings a minute apart. [Log a reading]' });
  expect(recent.today_step.rule).toBe('R-STEP-02'); // Monday, no weight in 7 days: the next rule in priority order
  expect(noPlan.today_step).toMatchObject({ template: 'template.T-GEN-01', text: "Keep logging: we'll start spotting patterns after 14 days" });
  expect(genAgain.today_step).toBeNull();
  expect(quiet.today_step).toMatchObject({ source: 'plan' });
});

test('TC-FR3-14-AS-01', () => {
  // A Premium test member behind on a weight goal for 2 weeks is offered an adjusted plan with a later date (R-ADJ-01),
  // at the check-in only and never applied by the engine; the plateau offer (R-ADJ-02) never lowers calories (D3-45).
  const behind = builders['R-ADJ-01']({ goal_type: 'lose_weight', check_ins: { 'D-7': { projected_late_days: 21, weigh_ins_in_28_days: '>= 3' }, D: { projected_late_days: 21, weigh_ins_in_28_days: '>= 3' } } });
  const [offered, night, free] = evaluate([behind, { ...behind, at_check_in: false }, { ...behind, premium: false }]);
  expect(offered.adjustments[0]).toMatchObject({ rule: 'R-ADJ-01', outcome: 'offered', automatic: false });
  expect(offered.adjustments[0].values).toEqual({ goal: 75, projected: day(81), target: day(60), new_date: day(98) }); // 7 kg at the member's 0.5 kg rate: 14 weeks, never faster than the cap
  expect(offered.adjustments[0].extra.offers).toEqual(['later_date', 'easier_activity_25pct_one_week', 'different_meal_plan']);
  expect(night.adjustments[0].outcome).toBe('not_at_check_in');
  expect(free.adjustments[0].outcome).toBe('premium_required'); // TC-FR3-14-AS-03: the Free member sees the upgrade invitation instead
  const plateau = builders['R-ADJ-02']({ goal_type: 'lose_weight', goal_reached: false, window_days: 21, weigh_ins: 5, min_kg: 84.8, max_kg: 85.3, range_kg: 0.5 });
  const [p] = evaluate([plateau]);
  expect(p.adjustments[0]).toMatchObject({ rule: 'R-ADJ-02', outcome: 'offered' });
  expect(p.adjustments[0].extra.offers).toEqual(['log_every_meal_7_days', 'different_meal_plan']);
  expect(Object.keys(p.adjustments[0].values)).toEqual(['weight']); // no calorie number offered or applied
});

test('TC-FR3-14-AS-02', () => {
  // An adjusted plan never sets a weight loss faster than the safe rate: losing faster than 1.25 × the cap raises the
  // calorie target by 250 kcal automatically (R-ADJ-03, D3-46), with the doctor line when the 7-day rule triggered; a
  // rate is never raised (R-ADJ-01 uses min(member's rate, cap)).
  const fast = builders['R-ADJ-03']({ goal_type: 'lose_weight', cap_kg_per_week: 0.9, slope_kg_per_week: 1.2, weigh_ins_in_28_days: '>= 4' });
  const seven = { today: D, premium: true, goal: { goal_type: 'lose_weight', measure: 'weight', target_value: 75 }, plan: { started: day(-56), calorie_target: 1700, cap_kg_week: 0.9 }, daily: { weight: { [day(-8)]: 90, [day(-1)]: 87.9 } } };
  const [applied, sevenDay, over65] = evaluate([fast, seven, { ...fast, situations: ['age_65_plus'], plan: { started: day(-56), calorie_target: 1700 } }]);
  expect(applied.adjustments[0]).toMatchObject({ rule: 'R-ADJ-03', outcome: 'applied', automatic: true, cooldown_days: 14 });
  expect(applied.adjustments[0].values).toEqual({ cap: 0.9, kcal: 1950 });
  expect(applied.adjustments[0].text).toBe("You're losing weight faster than the safe rate of about **0.9 kg** a week. We've raised your daily target to **1950 kcal** so your body keeps muscle and energy. If you're not trying to lose this fast, or feel unwell, please see your doctor.");
  expect(applied.adjustments[0].notes).toEqual([]);
  expect(sevenDay.adjustments[0]).toMatchObject({ rule: 'R-ADJ-03', outcome: 'applied', notes: ['doctor_line'] }); // 2.3 % in 7 days
  expect(over65.adjustments[0].values.cap).toBe(0.5); // 65 and over: the 0.5 kg cap (P3-03 new row)
  // "any 7-day loss > 2 %" means any pair 6 to 8 days apart inside the 4 weeks, not only the latest reading.
  const earlier = { ...seven, daily: { weight: { [day(-20)]: 90, [day(-13)]: 87.5, [day(-6)]: 87.4, [day(-1)]: 87.3 } } };
  const [pair] = evaluate([earlier]);
  expect(pair.adjustments[0]).toMatchObject({ rule: 'R-ADJ-03', notes: ['doctor_line'] }); // 2.8 % between D-20 and D-13
  const behind = builders['R-ADJ-01']({ goal_type: 'lose_weight', check_ins: { 'D-7': { projected_late_days: 21 }, D: { projected_late_days: 21 } } });
  behind.goal.rate_kg_week = 1.5; // the member's own rate is above the cap: the new date uses the cap
  const [capped] = evaluate([behind]);
  expect(capped.adjustments[0].values.new_date).toBe(day(63)); // 7 kg / 0.8 cap = 9 weeks
});

test('TC-FR3-14-AS-02 (Owner Q68): the too-fast safety adjustment reaches every member with a calorie target', () => {
  // Catalogue v1.1 (D3-49, Owner_Questions 68 = B): R-ADJ-03 is a safety adjustment (FR3-69: safety is never paid for).
  // It applies to Free members too, R-SAFE-04 never suppresses it (the gentle wording T-ADJ-03g during the 7 low-mood
  // days), and an eating-disorder member gets the raise with no message and no calorie number. Test data ADJ-03-Q1
  // to Q5 of the change draft.
  const fast = builders['R-ADJ-03']({ goal_type: 'lose_weight', cap_kg_per_week: 0.9, slope_kg_per_week: 1.2, weigh_ins_in_28_days: '>= 4' });
  const lowMood = builders['R-SAFE-04']({ mood_daily: { 'D-3': 2, 'D-2': 1, 'D-1': 2 } });
  const gentle = "We've given your daily food target a small lift, so your body has a little more energy. There's nothing you need to do. If you feel unwell, please see your doctor.";
  const behind = builders['R-ADJ-01']({ goal_type: 'lose_weight', check_ins: { 'D-7': { projected_late_days: 21, weigh_ins_in_28_days: '>= 3' }, D: { projected_late_days: 21, weigh_ins_in_28_days: '>= 3' } } });
  const [free, moodToday, moodRecent, ed, noTarget, behindLow] = evaluate([
    { ...fast, premium: false },                                                                   // Q1
    { ...fast, daily: { ...fast.daily, ...lowMood.daily } },                                       // Q2: R-SAFE-04 fires today
    { ...fast, shown: { 'R-SAFE-04': day(-3) } },                                                  // Q3: R-SAFE-04 shown 3 days ago
    { ...fast, situations: ['eating_disorder'] },                                                  // Q4
    { ...fast, premium: false, plan: { started: day(-56), calorie_target: 0, cap_kg_week: 0.9 } }, // Q5
    { ...behind, shown: { 'R-SAFE-04': day(-3) } },                                                // R-ADJ-01 still suppressed
  ]);
  expect(free.adjustments[0]).toMatchObject({ rule: 'R-ADJ-03', outcome: 'applied', automatic: true, template: 'rule.R-ADJ-03', values: { cap: 0.9, kcal: 1950 } });
  expect(free.adjustments[0].text).toBe("You're losing weight faster than the safe rate of about **0.9 kg** a week. We've raised your daily target to **1950 kcal** so your body keeps muscle and energy. If you're not trying to lose this fast, or feel unwell, please see your doctor.");
  for (const r of [moodToday, moodRecent]) {
    expect(r.suppressed).toBe(true);
    expect(r.adjustments[0]).toMatchObject({ rule: 'R-ADJ-03', outcome: 'applied', template: 'rule.R-ADJ-03.gentle', text: gentle, notes: ['gentle_low_mood'] });
    expect(r.adjustments[0].values.kcal).toBe(1950); // the raise itself is unchanged; the wording carries no number
  }
  expect(moodToday.safety.map((o) => o.rule)).toEqual(['R-SAFE-04']);
  expect(ed.adjustments[0]).toMatchObject({ rule: 'R-ADJ-03', outcome: 'applied', template: null, text: null, notes: ['no_message_eating_disorder'] });
  expect(noTarget.rules['R-ADJ-03']).toMatchObject({ fires: false, reason: 'no_calorie_target' });
  expect(noTarget.adjustments).toEqual([]);
  expect(behindLow.adjustments[0]).toMatchObject({ rule: 'R-ADJ-01', outcome: 'suppressed' }); // the other adjustments keep the v1 rule
  // The gentle template has its own stored id (200 + the rule's), and the runner stores no insight for the ED case.
  expect(JSON.parse(php("echo json_encode(LLD_Members_Rule_Catalogue::template_ids()['rule.R-ADJ-03.gentle']);"))).toBe(223);
  expect(readFileSync(resolve(engineDir, 'class-insights-runner.php'), 'utf8')).toMatch(/null === \$o\['template'\] \? 0 : self::write_insight/);
});

test('TC-FR3-15-AS-01', () => {
  // A test member who reaches their goal weight sees a 'Goal reached' screen: the event fires once per goal, outside
  // the insight quota, with no kilograms-lost wording.
  const reached = { today: D, premium: false, goal: { goal_type: 'lose_weight', measure: 'weight', target_value: 75, unit: 'kg' }, daily: { weight: { [day(-1)]: 74.6, [day(-3)]: 74.9, [day(-5)]: 74.9 } }, insights_shown_dates: [day(-1), day(-2), day(-3)] };
  const [r] = evaluate([reached]);
  expect(r.events[0]).toMatchObject({ rule: 'R-INS-10', outcome: 'shown', counted_in_quota: false, text: "You've reached your goal: **75 kg**. [Keep it off] [Set a new goal]" });
  expect(r.insight).toBeNull();
  expect(r.rules['R-ADJ-02'].fires).toBe(false);
});

test('TC-FR3-46-AS-01 (engine): safety rules run for Free and Premium members alike and before everything else', () => {
  // FR3-69: safety is never paid for. The same state as a Free and as a Premium member gives the same safety output,
  // listed before the Today step and the insights, even when the weekly insight quota is used up.
  const safe1 = builders['R-SAFE-01']({ pregnancy: false, 'window_1_D-14_to_D-8': { daily_mean: '136/84', days_with_data: 4 }, 'window_2_D-7_to_D-1': { daily_mean: '138/86', days_with_data: 3 }, urgent_reading_in_window: false });
  const falling = builders['R-SAFE-03']({ active_lose_weight_goal: false, weight_kg: { 'D-8': 80.0, 'D-1': 78.3 } });
  const both = { ...safe1, daily: { ...safe1.daily, ...falling.daily }, insights_shown_dates: [day(-1), day(-2), day(-3)] };
  const [free, premium, pregnant] = evaluate([{ ...both, premium: false }, { ...both, premium: true }, { ...both, premium: true, situations: ['pregnancy'], readings_today: { blood_pressure: [[141, 88]] } }]);
  expect(free.safety.map((o) => [o.rule, o.outcome])).toEqual([['R-SAFE-01', 'shown'], ['R-SAFE-03', 'shown']]);
  expect(premium.safety).toEqual(free.safety);
  expect(free.safety[1].text).toBe('Your weight has gone down by **2.1 %** since **' + day(-8) + '** without a weight goal. Losing weight without trying can have many causes, and some need checking, so please make an appointment with your doctor.');
  expect(Object.keys(free)).toEqual(['date', 'version', 'rules', 'safety', 'suppressed', 'today_step', 'adjustments', 'insight', 'insights', 'events']);
  // In pregnancy R-SAFE-01 is replaced by R-SAFE-01p (the catalogue's safety-check column).
  expect(pregnant.safety.map((o) => o.rule)).toEqual(['R-SAFE-01p', 'R-SAFE-03']);
  expect(pregnant.rules['R-SAFE-01'].reason).toBe('safety_check:pregnancy');
});
