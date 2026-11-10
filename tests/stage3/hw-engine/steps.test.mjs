// FR3-103 personalised activities, FR3-109 sleep and stress, FR3-110 alcohol (P3-35; R-HW-STEP-01 to -06,
// R-HW-ALC-01, -02, R-HW-SAFE-03, -05, R-HW-NUM-05): the week plans of levels 1 and 2 built from the approved
// activities (fixtures/healthy-weight-programme/track-content.json, IDs and habit tags only).
import { expect, test } from 'vitest';
import { call, one, replay, fixtureById, track, weeks } from './hw-harness.mjs';

const ok = (srcs) => {
  const rows = replay(srcs.map((s) => { const f = fixtureById(s); if (!f) throw new Error(`no fixture ${s}`); return f; }));
  expect(rows.flatMap((r) => r.problems)).toEqual([]);
  return rows.map((r) => r.result);
};
const NEUTRAL = { barrier: 'not_sure', confidence: 6, time: '10_30', work: 'days', sleep: '7_9', stress: 5, alcohol_freq: '1_2_days', alcohol_amount: '1_2', activity_level: 'some' };
const LIB = [{ id: 'LIB-TEST-MEALS-1', tags: ['meals'] }, { id: 'LIB-TEST-SNACK-1', tags: ['snacking'] }, { id: 'LIB-TEST-SNACK-2', tags: ['snacking'] }, { id: 'LIB-TEST-MIND-1', tags: ['mindset'] }, { id: 'LIB-TEST-SWAP-1', tags: ['drink-swap'] }];
const plan = (answers, library = LIB) => weeks({ answers, activities: '@track', library });

test('TC-FR3-103-AS-01', () => {
  // A member who drinks no sugary drinks is never given "Swap one sugary drink" (HW-W3-A1, the only drink-swap item),
  // nor a drink-swap item from the action library in its place.
  ok(['HWST-02a', 'HWST-02b']);
  for (const s of ['never', 'less_than_weekly']) {
    const w = plan({ ...NEUTRAL, sugary_drinks: s });
    expect(w.all_ids).not.toContain('HW-W3-A1');
    expect(w.all_ids).not.toContain('LIB-TEST-SWAP-1');
    expect(w.one_step_a_day).toBe(true);
  }
  expect(track.filter((a) => a.tags.includes('drink-swap')).map((a) => a.id)).toEqual(['HW-W3-A1']);
});

test('TC-FR3-103-AS-02', () => {
  // Biggest barrier evening snacking: an evening-eating activity first in week 3 (and "Notice your hunger" on day 3 of
  // week 1, fixture HWST-01a), with the T-HW-STEP-01 reason line.
  ok(['HWST-01a', 'HWST-01b']);
  const w = plan({ ...NEUTRAL, barrier: 'evening_snacking' });
  const d1 = w.plans[2].days[0];
  expect(track.find((a) => a.id === d1.id).tags).toContain('evening-eating');
  expect(d1.rule).toBe('R-HW-STEP-01');
  expect(d1.reason.text).toBe('Chosen first because you said evening snacking gets in the way.');
  // Member B (eating when stressed): "Pause before a snack" first in week 3 (the H1 note on the section 12 fixture).
  const [b] = ok(['MEMBER-2']);
  expect(b.week3_day1).toBe('HW-W3-A6');
});

test('TC-FR3-103-AS-03', () => {
  // 10 minutes a day: easier versions; confidence 8 that did over 80 % last week: a stretch version.
  ok(['HWST-03a', 'HWST-03b', 'HWST-03c']);
  const [short, confident] = call([
    ['week_plan', { week: 1, answers: { ...NEUTRAL, time: 'under_10' }, activities: '@track', library: LIB }],
    ['week_plan', { week: 3, answers: { ...NEUTRAL, confidence: 8 }, activities: '@track', library: LIB, history: { version: 'standard', done_pct: 85 } }],
  ]);
  expect(short.version).toBe('easier');
  expect(short.days.every((d) => d.version === 'easier')).toBe(true);
  expect(confident.version).toBe('stretch');
  // Week 1 with confidence 8 and over 30 minutes starts at stretch (member A); never above stretch.
  const [a] = ok(['MEMBER-1']);
  expect(a.week1_version).toBe('stretch');
});

test('TC-FR3-103-AS-04', () => {
  // Every activity in Healthy Weight levels 1 and 2 has at least one habit tag (the approved tags, 9 Nov 2026), and the
  // track content the engine reads has all 56, seven a week for eight weeks.
  expect(track).toHaveLength(56);
  for (const a of track) expect(a.tags.length, a.id).toBeGreaterThan(0);
  for (let w = 1; w <= 8; w++) expect(track.filter((a) => a.week === w).map((a) => a.day).sort()).toEqual([1, 2, 3, 4, 5, 6, 7]);
  // Easier and stretch versions exist for every activity in the approved content; the engine only names the version.
  const r = one('week_plan', { week: 2, answers: NEUTRAL, activities: '@track', library: LIB, history: { version: 'easier', done_pct: 30 } });
  expect(r.version).toBe('easier'); // never below easier
});

test('TC-FR3-103-AS-05', () => {
  // Each day of weeks 1 to 8 has exactly one activity, for every combination of extra steps; no item the safety rules
  // exclude appears (high blood pressure or heart disease: the steady walk HW2-W3-A6-ALT, never the interval walk).
  const combos = [
    NEUTRAL,
    { ...NEUTRAL, sleep: 'under_6', stress: 9, alcohol_freq: 'most_days', alcohol_amount: '5_plus', sugary_drinks: 'never', breakfast: 'most_days', breakfast_protein: true, eats_out: 'less_than_weekly', barrier: 'eating_out' },
    { ...NEUTRAL, alcohol_freq: 'never', barrier: 'alcohol' },
    { ...NEUTRAL, food_rules: ['halal'], alcohol_freq: '3_4_days', alcohol_amount: '3_4' },
  ];
  for (const c of combos) {
    const w = plan(c, [...LIB, { id: 'LIB-TEST-MEALS-2', tags: ['meals'] }, { id: 'LIB-TEST-MEALS-3', tags: ['meals'] }]);
    expect(w.one_step_a_day, JSON.stringify(c)).toBe(true);
    for (const p of w.plans) expect(p.days).toHaveLength(7);
  }
  const bp = one('week_plan', { week: 7, answers: { ...NEUTRAL, conditions: ['high_blood_pressure'] }, activities: '@track', library: LIB });
  expect(bp.days.map((d) => d.id)).toContain('HW2-W3-A6-ALT');
  expect(bp.days.map((d) => d.id)).not.toContain('HW2-W3-A6');
  expect(bp.exercise.moderate_only).toBe(true);
});

test('TC-FR3-109-AS-01', () => {
  // Sleep 6 to 7 hours: the sleep and appetite note in week 1, and one sleep step in each of weeks 1 to 8 (each in place
  // of an activity, with the T-HW-STEP-04 reason line).
  const [w] = ok(['HWST-04a']);
  for (const p of w.plans) {
    const s = p.days.filter((d) => d.rule === 'R-HW-STEP-04');
    expect(s).toHaveLength(1);
    expect(s[0].id).toBe(`HW-SL-0${p.week}`);
    expect(s[0].reason.text).toBe('Added because short sleep makes us hungrier.');
  }
  const [n] = ok(['HWNO-02a']);
  expect(n.notes).toContain('HW-MN-W1-6');
});

test('TC-FR3-109-AS-02', () => {
  // No plateau, no festival, barrier "not sure", stress under 7, sleep 6 to 7: "Sleep and weight" at week 9; stress 7 and
  // sleep 7 to 9: "Stress and emotional eating" (the signed R-HW-BLOCK-01 order).
  const [sleep, stress] = ok(['HWB-01c', 'HWB-01d']);
  expect(sleep.block).toBe('sleep-and-weight');
  expect(sleep.text).toBe('Suggested for you, because you usually sleep less than 7 hours.');
  expect(stress.block).toBe('stress-emotional-eating');
  expect(stress.text).toBe('Suggested for you, because you rated your stress 7 out of 10.');
});

test('TC-FR3-109-AS-03', () => {
  // Stress 8: 0.25 kg a week with the reason; another safe pace can still be chosen.
  const r = one('pace', { confidence: 9, time: 'over_30', work: 'days', stress: 8, sleep: '7_9', age: 45, weight_kg: 80, chosen: 0.5 });
  expect(r.recommended).toBe(0.25);
  expect(r.reasons.map((x) => x.key)).toEqual(['hw.T-HW-PACE-01.reason.stress']);
  expect(r.chosen).toBe(0.5);
  expect(r.refused).toBe(false);
});

test('TC-FR3-109-AS-04', () => {
  // Stress 9, or sleep under 6: the approved support wording, with the Sleep and stress check and the Sleep and Stress
  // track as the extra goal.
  const [s9, s6] = ok(['HWS-03a', 'HWS-03c']);
  for (const r of [s9, s6]) {
    expect(r.cards[0].text).toBe('Poor sleep and high stress are hard on anyone. If this goes on for more than two weeks, talk to your doctor. You can try the Sleep and stress check, or add Sleep and Stress as your extra goal. [Sleep and stress check] [Add Sleep and Stress] [Not now]');
  }
  ok(['HWS-03b']);
});

test('TC-FR3-109-AS-06', () => {
  // Stress 9 unchanged: the support wording again at the first and second check-ins, not at the third.
  const rows = call([1, 2, 3].map((n) => ['support_cards', { stress: 9, sleep: '7_9', occasion: 'check_in', check_in_number: n, unchanged: true }]));
  expect(rows.map((r) => r.cards.length)).toEqual([1, 1, 0]);
  const changed = one('support_cards', { stress: 6, sleep: '7_9', occasion: 'check_in', check_in_number: 1, unchanged: false });
  expect(changed.cards).toEqual([]);
});

test('TC-FR3-109-AS-07', () => {
  // Stress 8 and sleep under 6: a sleep step in weeks 1, 3, 5 and 7 and a stress step in weeks 2, 4, 6 and 8, and each
  // day still has exactly one step.
  const [w] = ok(['HWST-05b']);
  expect(w.one_step_a_day).toBe(true);
  for (const p of w.plans) expect(p.days.filter((d) => d.rule === 'R-HW-STEP-04' || d.rule === 'R-HW-STEP-05')).toHaveLength(1);
  ok(['HWST-05a', 'MEMBER-2']);
});

test('TC-FR3-109-AS-08', () => {
  // Sleep 7 to 9 and stress 5: none of these changes (no extra step, no support card, no brought-forward note, steady pace).
  const [w] = ok(['HWST-04b']);
  expect(w.sleep_weeks).toEqual([]);
  const [cards, pace] = call([['support_cards', { stress: 5, sleep: '7_9' }], ['pace', { confidence: 6, time: '10_30', work: 'days', stress: 5, sleep: '7_9', age: 45, weight_kg: 80 }]]);
  expect(cards.cards).toEqual([]);
  expect(pace.recommended).toBe(0.5);
  const n = one('note_for_day', { date: '2026-11-07', programme_day: 2, queue: ['HW-MN-W1-2'], shown: {}, stress: 5, sleep: '7_9' });
  expect(n).toMatchObject({ note: 'HW-MN-W1-2', rule: null });
});

test('TC-FR3-110-AS-01', () => {
  // 1 to 2 days a week, 3 to 4 a time: about 680 kcal a week, roughly 100 a day at 130 kcal a drink; the calorie target is
  // the same as with "never" (drinks count against it like food).
  const [r] = ok(['HWN-05a']);
  expect(r.line.text).toBe('Your 1,400 kcal a day includes drinks. Your usual week of drinks is about 680 kcal, roughly 100 a day.');
  ok(['DRK-01', 'DRK-02', 'DRK-03', 'DRK-04', 'DRK-05', 'DRK-06', 'DRK-07', 'DRK-08', 'DRK-09', 'DRK-10', 'HWN-05b', 'HWN-05c']);
  const body = { sex: 'F', age: 45, height_cm: 163, weight_kg: 80, activity_level: 'some', pace: 0.5 };
  const [drinker, never] = call([['target', { ...body, alcohol_freq: '1_2_days', alcohol_amount: '3_4' }], ['target', { ...body, alcohol_freq: 'never' }]]);
  expect(drinker.target).toBe(never.target);
});

test('TC-FR3-110-AS-04', () => {
  // 3 to 4 days a week: one alcohol-free-day step in each of weeks 1 to 8 and, with no plateau, no festival, barrier
  // "not sure", stress under 7 and sleep 7+, "Alcohol" at week 9.
  const [w, block] = ok(['HWA-01a', 'HWB-01e']);
  expect(w.alcohol_weeks).toEqual([1, 2, 3, 4, 5, 6, 7, 8]);
  expect(block.text).toBe('Suggested for you, because of how often you drink.');
  // From week 3, the second alcohol-free day once last week's was done (the approved HW-AL-02).
  const wk3 = one('week_plan', { week: 3, answers: { ...NEUTRAL, alcohol_freq: '3_4_days', alcohol_amount: '1_2' }, activities: '@track', library: LIB, history: { alcohol_done: true } });
  expect(wk3.days[4]).toMatchObject({ id: 'HW-AL-02', rule: 'R-HW-ALC-01' });
});

test('TC-FR3-110-AS-05', () => {
  // "never", or food rules that exclude alcohol: no alcohol activity, note or shortcut in weeks 1 to 8.
  const alcoholIds = track.filter((a) => a.tags.includes('alcohol')).map((a) => a.id);
  expect(alcoholIds).toEqual(['HW2-W4-A4']);
  for (const a of [{ ...NEUTRAL, alcohol_freq: 'never' }, { ...NEUTRAL, alcohol_freq: '3_4_days', alcohol_amount: '3_4', food_rules: ['halal'] }]) {
    const w = plan(a, [...LIB, { id: 'LIB-TEST-ALC-1', tags: ['alcohol'] }]);
    expect(w.alcohol_weeks).toEqual([]);
    for (const id of [...alcoholIds, 'HW-AL-01', 'HW-AL-02', 'HW-AL-03', 'LIB-TEST-ALC-1']) expect(w.all_ids).not.toContain(id);
    expect(one('drink_shortcut', { ...a, time: '20:00' }).show).toBe(false);
    expect(one('drinks', a).line).toBeNull();
  }
  ok(['HWA-02b', 'HWA-01c']);
});

test('TC-FR3-110-AS-06', () => {
  // Most days, 3 to 4 a time (about 21 a week): the support card with the doctor wording, Free and Premium alike (the
  // rule has no tier: it is R-HW-SAFE-05), and no weight-loss message beside it.
  const [d] = ok(['HWS-05a']);
  expect(d.support_card).toBe(true);
  const c = one('support_cards', { alcohol_freq: 'most_days', alcohol_amount: '3_4', occasion: 'joining' });
  expect(c.cards.map((x) => x.rule)).toEqual(['R-HW-SAFE-05']);
  expect(c.cards[0].text).toMatch(/If you drink heavily every day, talk to your doctor before you cut down, because stopping suddenly can be dangerous\./);
  expect(c.cards[0].text).not.toMatch(/weight loss|lose weight|kcal|calorie/i);
  expect(c.no_weight_message).toBe(true);
});

test('TC-FR3-110-AS-07', () => {
  // Less than once a week but 5 or more a time: the support card too.
  const [d] = ok(['HWS-05b']);
  expect(d.support_card).toBe(true);
  expect(one('support_cards', { alcohol_freq: 'less_than_weekly', alcohol_amount: '5_plus' }).cards.map((x) => x.rule)).toEqual(['R-HW-SAFE-05']);
});

test('TC-FR3-110-AS-08', () => {
  // 3 to 4 days, 3 to 4 a time (about 12 a week): the card; 1 to 2 days, 3 to 4 a time (about 5 a week): no card.
  const [c, e] = ok(['HWS-05c', 'HWS-05e']);
  expect([c.support_card, e.support_card]).toEqual([true, false]);
  ok(['HWS-05d']);
});

test('TC-FR3-110-AS-09', () => {
  // Most days, 5 or more a time: "Have one drink fewer on one day this week", never an alcohol-free-day step (not even
  // the track's own HW2-W4-A4, H5 = A), with the support card shown first.
  const [w] = ok(['HWA-01b']);
  expect(w.alcohol_ids).toEqual(['HW-AL-03']);
  for (const id of ['HW-AL-01', 'HW-AL-02', 'HW2-W4-A4']) expect(w.all_ids).not.toContain(id);
  const p = w.plans[0].days.find((d) => d.rule === 'R-HW-ALC-01');
  expect(p.reason.text).toBe('Added because drinks add calories and can lead to more snacking and poorer sleep.');
  const title = one('drinks', { alcohol_freq: 'most_days', alcohol_amount: '5_plus' });
  expect(title.alcohol_step).toBe('one_fewer');
  expect(w.support_card).toBe(true);
});

test('TC-FR3-110-AS-10', () => {
  // An eating-disorder flag: no drink calorie figure.
  const [r] = ok(['HWN-05d']);
  expect(r.line).toBeNull();
  expect(one('drink_shortcut', { alcohol_freq: '1_2_days', time: '20:00', eating_disorder: 'yes' }).show).toBe(false);
});

test('TC-FR3-110-AS-02', () => {
  // (Engine part.) The evening "Add a drink" shortcut, which opens the food tracker's drinks so a drink counts against the
  // day's target: from 18:00; for shift and night workers from 10 hours after their wake-up time (D3-60, v1.3.1), or
  // from 18:00 without one; hidden once a drink is logged.
  ok(['HWA-02a', 'HWA-02c', 'HWA-02d', 'HWA-02e', 'HWA-02f', 'HWA-02g']);
  const [early, shift, before, wrap, logged] = call([
    ['drink_shortcut', { alcohol_freq: '1_2_days', time: '17:59', work: 'days' }],
    ['drink_shortcut', { alcohol_freq: '1_2_days', time: '03:00', work: 'nights', wake_time: '14:00' }],
    ['drink_shortcut', { alcohol_freq: '1_2_days', time: '13:59', work: 'nights', wake_time: '14:00' }],
    ['drink_shortcut', { alcohol_freq: '1_2_days', time: '14:00', work: 'nights', wake_time: '14:00' }],
    ['drink_shortcut', { alcohol_freq: '1_2_days', time: '03:00', work: 'nights', wake_time: '14:00', drink_logged_today: true }],
  ]);
  expect(early.show).toBe(false);
  expect(shift).toMatchObject({ show: true, from: '00:00' });
  expect(before.show).toBe(true); // still their evening: shown until the next wake-up
  expect(wrap.show).toBe(false); // a new day starts when they wake
  expect(logged.show).toBe(false);
  // The day worker's wake-up time does not move it: 18:00.
  expect(one('drink_shortcut', { alcohol_freq: '1_2_days', time: '17:00', work: 'days', wake_time: '06:00' })).toMatchObject({ show: false, from: '18:00' });
});
