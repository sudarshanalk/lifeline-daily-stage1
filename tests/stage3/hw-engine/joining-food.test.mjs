// FR3-98 joining questions and FR3-99 getting-to-know-you questions (engine parts: R-HW-PACE-01 defaults, R-HW-REM-01,
// R-HW-QOD-01, R-HW-FOOD-03) and FR3-36 recipe suggestions through R-HW-FOOD-01 and the approved food-rule tags.
import { expect, test } from 'vitest';
import { call, one, php, replay, fixtureById } from './hw-harness.mjs';

const ok = (srcs) => {
  const rows = replay(srcs.map((s) => { const f = fixtureById(s); if (!f) throw new Error(`no fixture ${s}`); return f; }));
  expect(rows.flatMap((r) => r.problems)).toEqual([]);
  return rows.map((r) => r.result);
};

test('TC-FR3-98-AS-03', () => {
  // Skipping every question still leads to the pace step with the standard recommendation (0.5, the signed default).
  const [r] = ok(['HWP-01g']);
  expect(r.recommended).toBe(0.5);
  expect(r.because).toBe("Because it's a pace most people can keep. A pace you can keep beats a fast start. You can change it.");
  // and with every answer skipped no step, note, card or block rule guesses (a skipped answer is "not known").
  const [cards, drinks, block] = call([['support_cards', {}], ['drinks', {}], ['suggest_block', { programme_week: 9, today: '2027-01-08' }]]);
  expect(cards.cards).toEqual([]);
  expect(drinks).toMatchObject({ drinks: false, line: null, support_card: false });
  expect(block.block).toBe('getting-stronger');
});

test('TC-FR3-98-AS-08', () => {
  // A member whose food preferences say halal is treated as "never" for alcohol: no drink line, card, step or shortcut.
  const a = { food_rules: ['halal'], alcohol_freq: 'most_days', alcohol_amount: '5_plus' };
  const [d, s, c] = call([['drinks', a], ['drink_shortcut', { ...a, time: '20:00' }], ['support_cards', a]]);
  expect(d).toMatchObject({ drinks: false, line: null, support_card: false, alcohol_step: 'none' });
  expect(s.show).toBe(false);
  expect(c.cards).toEqual([]);
});

test('TC-FR3-98-AS-09', () => {
  // Shifts or nights: the optional wake-up question (asked once, until answered) and reminders after it; regular days:
  // reminders from 08:00. Every reminder says only the T-HW-REM-01 line.
  const [days, night, ask] = ok(['HWR-01a', 'HWR-01b', 'HWR-01c']);
  expect(days.reminders[0]).toEqual({ kind: 'step', time: '08:00' });
  expect(days.ask_wake_up).toBe(false);
  expect(night.reminders).toEqual([{ kind: 'step', time: '14:30' }]);
  expect(ask.ask_wake_up).toBe(true);
  for (const r of [days, night, ask]) expect(r.text).toBe('Your next step is ready on Lifeline Daily.');
  // No reminder between bedtime and wake-up for a night worker.
  const quiet = one('reminder_times', { work: 'nights', wake_time: '14:00', bedtime: '07:00', snack_time: '10:00' });
  expect(quiet.reminders.map((r) => r.kind)).toEqual(['step']);
});

test('TC-FR3-99-AS-02', () => {
  // With questions unanswered, at most 2 a day, in the signed order, until all are answered or skipped.
  const [day1] = ok(['HWQ-01a']);
  expect(day1.questions).toHaveLength(2);
  let answered = [];
  const seen = [];
  for (let d = 0; d < 10; d++) {
    const r = one('qod', { answered, known: [] });
    expect(r.questions.length).toBeLessThanOrEqual(2);
    if (!r.questions.length) break;
    seen.push(...r.questions);
    answered = [...answered, ...r.questions];
  }
  expect(seen).toEqual(['food_rules_allergies', 'eating_pattern', 'snack_time', 'sugary_drinks', 'eating_out', 'cooking_time_skill', 'food_budget', 'favourite_cuisines', 'activity_enjoyment']);
});

test('TC-FR3-99-AS-03', () => {
  // A question already answered in the health profile or food preferences is not asked again.
  const r = one('qod', { answered: [], known: ['food_rules_allergies'] });
  expect(r.questions).toEqual(['eating_pattern', 'snack_time']);
});

test('TC-FR3-99-AS-04', () => {
  // Every question the engine can ask has a signed rule in the catalogue; a question without one is never asked
  // ("stress eating" and "what they have tried before" have no signed rule).
  const q = JSON.parse(php('echo json_encode(LLD_Members_HW_Rules::QUESTIONS);'));
  const rules = JSON.parse(php('echo json_encode(LLD_Members_Rule_Catalogue::programme_rules());'));
  const asked = one('qod', { answered: [], known: [] }).rules;
  for (const [key, rule] of Object.entries(q)) {
    if (rule === null) { expect(asked).not.toHaveProperty(key); continue; }
    expect(rules[rule], `${key} -> ${rule}`).toBeDefined();
  }
  let answered = [];
  for (let d = 0; d < 10; d++) {
    const r = one('qod', { answered, known: [] });
    for (const k of r.questions) expect(q[k], k).not.toBeNull();
    answered = [...answered, ...r.questions];
  }
  expect(answered).not.toContain('stress_eating');
  expect(answered).not.toContain('tried_before');
});

test('TC-FR3-99-AS-05', () => {
  // (Engine part.) The plan is worked out from the current answers each time, so a changed answer changes the plan the
  // next time it is built: sugary drinks "daily" keeps "Swap one sugary drink", "never" leaves it out.
  const answers = { barrier: 'not_sure', confidence: 6, time: '10_30', work: 'days', sleep: '7_9', stress: 5, alcohol_freq: 'never' };
  const library = [{ id: 'LIB-TEST-SNACK-1', tags: ['snacking'] }];
  const [before, after] = call([
    ['week_plan', { week: 3, answers: { ...answers, sugary_drinks: 'daily' }, activities: '@track', library }],
    ['week_plan', { week: 3, answers: { ...answers, sugary_drinks: 'never' }, activities: '@track', library }],
  ]);
  expect(before.days.map((d) => d.id)).toContain('HW-W3-A1');
  expect(after.days.map((d) => d.id)).not.toContain('HW-W3-A1');
});

test('TC-FR3-102-AS-05', () => {
  // (Engine part.) Changing the activity level from "some" to "active" works the target out again at once.
  const body = { sex: 'F', age: 45, height_cm: 160, weight_kg: 80, pace: 0.5 };
  const [some, active] = call([['target', { ...body, activity_level: 'some' }], ['target', { ...body, activity_level: 'active' }]]);
  expect([some.target, active.target]).toEqual([1390, 1640]);
});

test('TC-FR3-99-AS-06', () => {
  // A member with an eating-disorder flag is not asked any question (Healthy Weight is not offered to them).
  expect(one('qod', { answered: [], known: [], eating_disorder: 'yes' }).questions).toEqual([]);
  expect(one('eligibility', { eating_disorder: 'yes', pregnant: 'no', age: 40 }).offered).toBe(false);
});

test('TC-FR3-99-AS-07', () => {
  // During a fast the member keeps, the fasting meal plan with the same calorie target, and the usual plan the day after
  // it ends; type 2 diabetes keeps the usual plan with the approved line.
  const [during, t2d, after] = ok(['HWF-03a', 'HWF-03b', 'HWF-03c']);
  expect(during).toMatchObject({ plan: 'fasting', target_changes: false, rule: 'R-HW-FOOD-03' });
  expect(t2d.line).toBe('If you are planning to fast, follow the plan you agreed with your doctor or diabetes team.');
  expect(after.plan).toBe('usual');
});

test('TC-FR3-36-AS-01 (R-HW-FOOD-01)', () => {
  // A member with a nut allergy is never shown a recipe that contains nuts (allergen list), and a halal member with a
  // peanut allergy never sees pork, alcohol or peanut (HWF-01a).
  const [nuts, halal] = ok(['TAG-04', 'HWF-01a']);
  expect(nuts.item_ids).not.toContain('R-124');
  expect(halal.item_ids).not.toContain('TEST-PEANUT-01');
  expect(halal.lines).toEqual(['Some vanilla extracts and soy sauces contain alcohol. Choose alcohol-free or halal-certified versions if you prefer.']);
  // Fewer than 5 items left: the empty state, never a non-matching item (HWF-01b).
  const [empty] = ok(['HWF-01b']);
  expect(empty).toMatchObject({ empty: true, item_ids: [] });
  // An unapproved item is never suggested.
  const u = one('food_ok', { item: { id: 'X', approved: false, diets: ['everything'], food_rules: [], allergens: [] }, member: { food_rules: [], allergies: [] } });
  expect(u.ok).toBe(false);
  ok(['HWF-02a']);
});

test('TC-FR3-36-AS-04', () => {
  // A kosher member is never shown a recipe tagged meat and dairy together, shellfish or pork (nor non-kosher fish), and
  // sees "Use kosher meat and chicken in these recipes." with the meat dishes that are kept.
  const [r] = ok(['TAG-01']);
  for (const id of ['R-110', 'R-164', 'R-151', 'TEST-PORK-01']) expect(r.item_ids).not.toContain(id);
  expect(r.item_lines['R-109']).toEqual(['Use kosher meat and chicken in these recipes.']);
  expect(r.item_lines['R-104']).toEqual([]); // no meat: no line
  expect(r.item_ids).toContain('R-132'); // fish with dairy kept (FT-Q1 = A)
});

test('TC-FR3-36-AS-05', () => {
  // On a "no grains or pulses" fasting day only recipes without grains or pulses are suggested; on other days they all are.
  const [fast, normal] = ok(['TAG-02', 'TAG-03']);
  expect(fast.item_ids).toEqual(['R-165', 'R-171', 'R-172', 'R-173', 'R-175', 'R-176']);
  expect(normal.item_ids).toHaveLength(9);
});
