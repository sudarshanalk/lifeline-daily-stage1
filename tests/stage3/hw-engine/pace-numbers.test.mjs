// FR3-100 recommended pace, FR3-101 milestones, FR3-102 calorie counting (P3-35; R-HW-PACE-01, R-HW-SAFE-01, -02,
// R-HW-NUM-03, -04, R-HW-MOT-02), with the signed P3-55 and P3-56 calculators underneath.
import { expect, test } from 'vitest';
import { call, one, php, replay, fixtureById, fixtureFile } from './hw-harness.mjs';

const ok = (srcs) => {
  const rows = replay(srcs.map((s) => { const f = fixtureById(s); if (!f) throw new Error(`no fixture ${s}`); return f; }));
  expect(rows.flatMap((r) => r.problems)).toEqual([]);
  return rows.map((r) => r.result);
};

test('TC-FR3-100-AS-01', () => {
  // 20 kg to lose, 10 minutes a day, confidence 3: 0.25 kg a week with the matching date (80 weeks, 19 May 2028). The
  // requirement's "0.25 or 0.5" is 0.25 under the signed rule (any gentle condition gives 0.25).
  const [r] = ok(['HWP-02a']);
  expect(r.recommended).toBe(0.25);
  expect(r.because).toBe('Because you rated your confidence 3 out of 10 and you have less than 10 minutes on most days. A pace you can keep beats a fast start. You can change it.');
  expect(r.heading).toBe('We suggest a gentle pace: 0.25 kg a week');
});

test('TC-FR3-100-AS-02', () => {
  // Time, support and confidence 8: 0.75 kg a week can be chosen, never more than the safe limit (cap min(1, 1 %)).
  const [r80, r70] = call([
    ['pace', { confidence: 8, time: 'over_30', work: 'days', stress: 4, sleep: '7_9', age: 40, weight_kg: 80, chosen: 0.75 }],
    ['pace', { confidence: 8, time: 'over_30', work: 'days', stress: 4, sleep: '7_9', age: 40, weight_kg: 70, chosen: 0.75 }],
  ]);
  expect(r80.chosen).toBe(0.75);
  expect(r80.refused).toBe(false);
  expect(Math.max(...r80.paces)).toBeLessThanOrEqual(r80.cap);
  expect(r70.refused).toBe(true); // cap 0.70
  expect(r70.chosen).toBe(0.5);
  expect(r70.paces).not.toContain(0.75);
  ok(['HWS-02a', 'HWS-02b', 'HWS-01b']);
});

test('TC-FR3-100-AS-03', () => {
  // 80 kg, confidence 9, over 30 minutes, stress 4, sleep 7 to 9: 0.75; with stress 8: 0.25; with sleep 6 to 7: 0.5.
  const [b, c, d] = ok(['HWP-01b', 'HWP-01c', 'HWP-01d']);
  expect([b.recommended, c.recommended, d.recommended]).toEqual([0.75, 0.25, 0.5]);
  // The rest of the signed fixture table: HWP-01a, -01e (age 66), -01f (cap 0.70), -01g (all skipped).
  ok(['HWP-01a', 'HWP-01e', 'HWP-01f', 'HWP-01g']);
  // 1.0 kg a week is never recommended, for any answers.
  const all = call([['pace', { confidence: 10, time: 'over_30', work: 'days', stress: 1, sleep: 'over_9', age: 30, weight_kg: 150 }]]);
  expect(all[0].recommended).toBe(0.75);
});

test('TC-FR3-100-AS-04', () => {
  // A 20 kg loss at 0.75 kg a week from 6 November 2026: 27 weeks and 14 May 2027 (weeks rounded up, P3-56).
  const [r] = ok(['HWP-02b']);
  expect([r.weeks, r.date]).toEqual([27, '2027-05-14']);
});

test('TC-FR3-100-AS-05', () => {
  // A pace above the safe limit is refused with the gentle signed P3-56 message and the safe suggestion.
  const r = one('pace', { weight_kg: 60, age: 45, chosen: 1.0 }); // cap 0.60
  expect(r.refused).toBe(true);
  expect(r.refusal).toBe('goal-timeline:refusals.rate-cap');
  expect(r.safe_pace).toBe(0.5);
  const message = php("echo LLD_Members_Goal_Timeline::MESSAGES['refusals.rate-cap'];");
  expect(message).toMatch(/^That's faster than the safe rate of about \{cap\} kg a week\./);
  expect(message).not.toMatch(/faster results/i);
});

test('TC-FR3-100-AS-06', () => {
  // The recommended pace, the chosen pace and the rule that decided are returned together, for saving with the goal.
  const r = one('pace', { confidence: 6, time: '10_30', work: 'days', stress: 5, sleep: '7_9', age: 45, weight_kg: 80, chosen: 0.25 });
  expect(r).toMatchObject({ rule: 'R-HW-PACE-01', recommended: 0.5, chosen: 0.25, step: 'steady' });
  // No wording such as "faster results" in any pace text.
  const texts = php("echo json_encode(array_filter(LLD_Members_Rule_Catalogue::HW_TEMPLATES, function ($k) { return strpos($k, 'PACE') !== false; }, ARRAY_FILTER_USE_KEY));");
  expect(texts).not.toMatch(/faster results/i);
});

test('TC-FR3-101-AS-01', () => {
  // 163 cm, 80 kg, goal 60: milestones 76, 72, 66.2 and 60 kg (members A and B, with their dates).
  const [a, b] = ok(['MEMBER-1', 'MEMBER-2']);
  expect(a.milestones.map((m) => m[0])).toEqual([76, 72, 66.2, 60]);
  expect(b.milestones.map((m) => m[0])).toEqual([76, 72, 66.2, 60]);
  const r = one('milestones', { start_kg: 80, goal_kg: 60, height_cm: 163, adjusted: false, pace: 0.75, today: '2026-11-06' });
  expect(r.milestones[0].text).toBe('**76.0 kg** · 5 % of your starting weight · around 18 December 2026');
  expect(r.milestones[3].text).toBe('**60.0 kg** · Your goal · around 14 May 2027');
  ok(['HWN-04a']); // 70 kg to 66 kg: the 10 % milestone is past the goal and left out
});

test('TC-FR3-101-AS-02', () => {
  // A goal above the top of the healthy range has no healthy-range milestone.
  const [r] = ok(['HWN-04b']);
  expect(r.milestones.map((m) => m.kind)).not.toContain('healthy');
});

test('TC-FR3-101-AS-03', () => {
  // Reaching 76 kg (7-day mean, D3-09) shows the 5 % celebration once, followed by the re-check questions.
  const ms = one('milestones', { start_kg: 80, goal_kg: 60, height_cm: 163, adjusted: false, pace: 0.5, today: '2026-11-06' }).milestones;
  const [first, again, before, ed] = call([
    ['milestone_reached', { milestones: ms, mean7: 76.0, celebrated: [] }],
    ['milestone_reached', { milestones: ms, mean7: 75.8, celebrated: ['5pct'] }],
    ['milestone_reached', { milestones: ms, mean7: 76.1, celebrated: [] }],
    ['milestone_reached', { milestones: ms, mean7: 76.0, celebrated: [], eating_disorder: 'yes' }],
  ]);
  expect(first.kind).toBe('5pct');
  expect(first.text).toBe("You've reached your first milestone: 76.0 kg. Losing 5 % of your weight can lower blood pressure and blood sugar, so this is a real health change.");
  expect(first.reask).toEqual(['time', 'work', 'stress']);
  expect(again.kind).toBeNull();
  expect(before.kind).toBeNull();
  expect(ed.kind).toBeNull();
});

test('TC-FR3-101-AS-04', () => {
  // No badge or message names the amount of weight lost: no T-HW template says kilograms "lost".
  const T = JSON.parse(php('echo json_encode(LLD_Members_Rule_Catalogue::HW_TEMPLATES);'));
  for (const [k, t] of Object.entries(T)) expect(t, k).not.toMatch(/\blost\b|\blose \{kg\}/i);
  for (const k of ['5pct', '10pct', 'healthy', 'goal']) expect(T[`hw.T-HW-MOT-02.${k}`]).toBeDefined();
});

test('TC-FR3-102-AS-03', () => {
  // P3-55 CAL-03 (female, 45, 160 cm, 80 kg, 0.5 kg a week): 1,390 kcal at "some", 1,640 at "active", the 1,200 minimum
  // at "low" (the same signed calculator as the ORC-06 oracle).
  const [some, active, low] = ok(['HWN-03a', 'HWN-03b', 'HWN-03c']);
  expect([some.target, active.target, low.target]).toEqual([1390, 1640, 1200]);
  ok(['MEMBER-1', 'MEMBER-2']); // members A (1,400) and B (1,440)
  expect(fixtureFile('numbers').filter((f) => f.inputs.fn === 'target')).toHaveLength(3);
});

test('TC-FR3-102-AS-04', () => {
  // "Your body uses about {tdee} kcal a day at your activity level": the engine's energy figure for the chosen level,
  // to the nearest 10 (member A 2,220.76 -> about 2,220; member B 1,719.30 -> about 1,720).
  const levels = ['low', 'some', 'active', 'very_active'];
  const rows = call(levels.map((l) => ['target', { sex: 'F', age: 45, height_cm: 163, weight_kg: 80, activity_level: l, pace: 0.5 }]));
  const factors = { low: 1.2, some: 1.375, active: 1.55, very_active: 1.725 };
  rows.forEach((r, i) => {
    expect(r.tdee).toBeCloseTo(1432.75 * factors[levels[i]], 2);
    expect(r.tdee_shown).toBe(Math.floor(r.tdee / 10 + 0.5) * 10);
  });
  expect(rows[2].tdee_shown).toBe(2220);
  expect(rows[0].tdee_shown).toBe(1720);
});

test('TC-FR3-102-AS-08', () => {
  // A member with an eating-disorder flag cannot start Healthy Weight and gets no calorie number from the programme.
  const [elig, target, drinks] = call([
    ['eligibility', { eating_disorder: 'yes', age: 40, pregnant: 'no' }],
    ['target', { sex: 'F', age: 45, height_cm: 160, weight_kg: 80, activity_level: 'some', pace: 0.5, eating_disorder: 'yes' }],
    ['drinks', { alcohol_freq: '1_2_days', alcohol_amount: '3_4', eating_disorder: 'yes', target: 1400 }],
  ]);
  expect(elig).toMatchObject({ offered: false, stop: 'eating', calorie_target_stored: false });
  expect(target.target).toBeNull();
  expect(drinks.line).toBeNull();
});
