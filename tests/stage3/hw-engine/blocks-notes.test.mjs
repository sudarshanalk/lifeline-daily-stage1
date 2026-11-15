// FR3-104 focus blocks (R-HW-BLOCK-01 to -03, catalogue v1.3), FR3-105 and FR3-106 mindset notes (R-HW-NOTE-01 to -05),
// FR3-107 "What got in the way?" (R-HW-WEEK-01), FR3-108 plateau (R-HW-WEEK-02 on the signed R-ADJ-02).
import { expect, test } from 'vitest';
import { addDays, call, defaultNotes, noteSequence, one, php, replay, fixtureById, fixtureFile } from './hw-harness.mjs';

const ok = (srcs) => {
  const rows = replay(srcs.map((s) => { const f = fixtureById(s); if (!f) throw new Error(`no fixture ${s}`); return f; }));
  expect(rows.flatMap((r) => r.problems)).toEqual([]);
  return rows.map((r) => r.result);
};
const BLOCKS = ['evening-night-eating', 'stress-emotional-eating', 'eating-out-social-life', 'breaking-a-plateau', 'alcohol', 'sleep-and-weight', 'getting-stronger', 'festivals-and-fasting', 'shift-work'];

test('TC-FR3-104-AS-01', () => {
  // A member finishing week 8 short of the goal is offered a focus block with a one-line reason, and can choose another
  // (all nine are offered as choices); no block before week 9.
  const [a, , , , , , , , i] = ok(['HWB-01a', 'HWB-01b', 'HWB-01c', 'HWB-01d', 'HWB-01e', 'HWB-01f', 'HWB-01g', 'HWB-01h', 'HWB-01i']);
  expect(a.choices).toEqual(BLOCKS);
  expect(a.text).toMatch(/^Suggested for you, because .+\.$/);
  expect(i.block).toBeNull();
});

test('TC-FR3-104-AS-02', () => {
  // A member who snacks after dinner (barrier evening snacking) is suggested "Evening and night eating" first.
  const [a] = ok(['MEMBER-1']);
  expect(a.block).toBe('evening-night-eating');
  const r = one('suggest_block', { programme_week: 9, today: '2027-01-08', barrier: 'evening_snacking', stress: 8, sleep: 'under_6' });
  expect(r).toMatchObject({ block: 'evening-night-eating', step: 3, text: 'Suggested for you, because you said evening snacking gets in the way.' });
});

test('TC-FR3-104-AS-03', () => {
  // Festivals and fasting is offered before Ramadan only to members who keep that fast, 2 to 6 weeks before.
  const [no, far] = ok(['HWB-03g', 'HWB-03f']);
  expect(no.offered).toBe(false);
  expect(far.offered).toBe(false);
  const ramadan = { id: 'ramadan-2027-test', name: 'Ramadan', start: '2027-02-08' };
  const [keeps, early, declined] = call([
    ['offer', { festival: ramadan, today: '2027-01-01', programme_week: 9, keeps: true }],
    ['offer', { festival: ramadan, today: '2027-01-01', programme_week: 8, keeps: true }], // T1 = A: not in weeks 1 to 8
    ['offer', { festival: ramadan, today: '2027-01-08', programme_week: 10, keeps: true, declined: true }], // T3 = A
  ]);
  expect(keeps).toMatchObject({ offered: true, rule: 'R-HW-BLOCK-02', days: 38 });
  expect(early).toMatchObject({ offered: false, reason: 'weeks_1_to_8' });
  expect(declined).toMatchObject({ offered: false, reason: 'not_for_this_festival' });
  // In R-HW-BLOCK-01 only festivals in the member's own calendar are passed, so none means no festival suggestion.
  expect(one('suggest_block', { programme_week: 9, today: '2027-01-01', barrier: 'not_sure', festivals: [] }).block).toBe('getting-stronger');
  expect(one('suggest_block', { programme_week: 9, today: '2027-01-01', barrier: 'not_sure', festivals: [ramadan] }).block).toBe('festivals-and-fasting');
});

test('TC-FR3-104-AS-05', () => {
  // A member who reaches the goal during a block is offered Keep It Off.
  const [r] = ok(['HWB-01h']);
  expect(r.keep_it_off).toBe(true);
  expect(php("echo LLD_Members_Rule_Catalogue::text('hw.T-HW-MOT-02.goal');")).toMatch(/\[Start Keep It Off\]$/);
});

test('TC-FR3-104-AS-06', () => {
  // A test 30-day fast from Monday 8 February 2027, accepted on 1 January 2027: week 3 starts on 8 February, its steps
  // repeat until 9 March, and week 4 runs 10 to 16 March (fixture HWB-03a); and the rest of R-HW-BLOCK-03's table.
  const [a] = ok(['HWB-03a']);
  expect(a.week3_from).toBe('2027-02-08');
  expect(a.days.filter((d) => d.kind === 'repeat').at(-1).date).toBe('2027-03-09');
  expect([a.week4_from, a.week4_to]).toEqual(['2027-03-10', '2027-03-16']);
  ok(['HWB-03b', 'HWB-03c', 'HWB-03d', 'HWB-03e', 'HWB-03f']);
  // Every day from the check-in to the end of week 4 has exactly one entry.
  const dates = a.days.map((d) => d.date);
  expect(new Set(dates).size).toBe(dates.length);
  for (let i = 1; i < dates.length; i++) expect(dates[i]).toBe(addDays(dates[i - 1], 1));
});

test('TC-FR3-104-AS-07', () => {
  // No plateau message while a member is in Festivals and fasting (T2 = A); the block's weeks 3 and 4 weigh-ins never count.
  const [r] = ok(['HWW-02f']);
  expect(r.text).toBeNull();
  const flat = { '2026-11-19': 80.1, '2026-11-14': 80.0, '2026-11-09': 80.2, '2026-11-04': 80.1 };
  const after = one('plateau', { today: '2026-11-20', weights: flat, goal_type: 'lose_weight', festival_weeks_3_4: [['2026-11-08', '2026-11-21']] });
  expect(after).toMatchObject({ fires: false, reason: 'min_data' });
});

test('TC-FR3-105-AS-01', () => {
  // Days 1 to 56: one note a day, from that week's theme (note k of week n on day 7 × (n − 1) + k).
  const { notes } = noteSequence({ start: '2026-11-06', days: 56 });
  expect(notes).toEqual(defaultNotes);
  notes.forEach((id, i) => expect(id.startsWith(`HW-MN-W${Math.floor(i / 7) + 1}-`)).toBe(true));
});

test('TC-FR3-105-AS-03', () => {
  // Notes about stress or mood carry the approved support wording (content question N4 as decided: four notes).
  const { results } = noteSequence({ start: '2026-11-06', days: 56 });
  const flagged = results.filter((r) => r.support_wording).map((r) => r.note);
  expect(flagged).toEqual(['HW-MN-W4-4', 'HW-MN-W8-1', 'HW-MN-W8-4', 'HW-MN-W8-5']);
});

test('TC-FR3-106-AS-01', () => {
  // No food logged for 3 days: the missed-log note the next day.
  const [fires, not] = ok(['HWNO-03a', 'HWNO-03b']);
  expect(fires.note).toBe('HW-MN-W2-6');
  expect(not.rule).toBeNull();
});

test('TC-FR3-106-AS-02', () => {
  // Weigh-ins that meet the plateau rule bring the plateau note.
  const [p] = ok(['HWW-02a']);
  expect(p.note).toBe('HW-MN-W7-4');
  const [n] = ok(['HWNO-05a']);
  expect(n.note).toBe('HW-MN-W7-4');
});

test('TC-FR3-106-AS-03', () => {
  // A weigh-in 0.4 kg above last week's brings "Weight goes up and down"; a rise of 0.2 kg does not; the line is 0.3.
  ok(['HWNO-04a', 'HWNO-04b']);
  const base = { date: '2026-11-20', programme_day: 15, queue: defaultNotes.slice(14), shown: {} };
  const [up4, up2, up3, ed] = call([
    ['note_for_day', { ...base, weigh_in_last_week: 80.0, weigh_in_this_week: 80.4 }],
    ['note_for_day', { ...base, weigh_in_last_week: 80.0, weigh_in_this_week: 80.2 }],
    ['note_for_day', { ...base, weigh_in_last_week: 80.0, weigh_in_this_week: 80.3 }],
    ['note_for_day', { ...base, weigh_in_last_week: 80.0, weigh_in_this_week: 80.4, eating_disorder: 'yes' }],
  ]);
  expect(up4.note).toBe('HW-MN-W1-4');
  expect(up2.rule).toBeNull();
  expect(up3.note).toBe('HW-MN-W1-4');
  expect(ed.rule).toBeNull(); // × eating-disorder flag
});

test('TC-FR3-106-AS-04', () => {
  // A low mood log brings the kindness note with the support wording, and never a weight message; for 7 days after
  // R-SAFE-04 it is the only note change (the weight note waits).
  const [r] = ok(['HWNO-01a']);
  expect(r).toMatchObject({ note: 'HW-MN-W4-4', support_wording: true });
  const paused = one('note_for_day', { date: '2026-11-21', programme_day: 16, queue: defaultNotes.slice(15), shown: { 'HW-MN-W4-4': '2026-11-20' }, safe04_date: '2026-11-20', weigh_in_last_week: 79.1, weigh_in_this_week: 79.6 });
  expect(paused.rule).toBeNull();
  expect(paused.note).toBe('HW-MN-W3-2');
});

test('TC-FR3-106-AS-05', () => {
  // The note it replaced appears the following day, and no note repeats within 30 days (member B's worked example).
  const [b] = ok(['HWNO-06a']);
  expect(b.notes.slice(1, 3)).toEqual(['HW-MN-W8-1', 'HW-MN-W1-2']);
  const { notes } = noteSequence({ start: '2026-11-06', days: 56, answers: { stress: 8, sleep: 'under_6' } });
  const last = {};
  notes.forEach((id, i) => { if (id && last[id] !== undefined) expect(i - last[id], id).toBeGreaterThanOrEqual(30); last[id] = i; });
  expect(notes.filter((x) => x === 'HW-MN-W8-1')).toHaveLength(2); // shown again in week 8 (content N3 = A), 30+ days on
});

test('TC-FR3-107-AS-01', () => {
  // Skipping the question still completes the check-in: no choice means no change to next week.
  const r = one('check_in', { choices: [] });
  expect(r).toMatchObject({ easier: false, notes: [], steps: [], replies: [] });
  const nothing = one('check_in', { choices: ['nothing'] });
  expect(nothing.texts).toEqual(['Good week. Your plan carries on as it is.']);
  ok(['HWW-01a', 'HWW-01b']);
});

test('TC-FR3-107-AS-02', () => {
  // "Cravings" two weeks running: the next week's plan has the cravings note and a planned-snack step, each time.
  const lib = [{ id: 'LIB-TEST-SNACK-1', tags: ['snacking'] }, { id: 'LIB-TEST-SNACK-2', tags: ['snacking'] }];
  const answers = { barrier: 'not_sure', confidence: 6, time: '10_30', work: 'days', sleep: '7_9', stress: 5, alcohol_freq: 'never' };
  const [w5, w6] = call([5, 6].map((w) => ['week_plan', { week: w, answers, activities: '@track', library: lib, history: { check_in: ['cravings'] } }]));
  for (const w of [w5, w6]) {
    expect(w.check_in.notes).toEqual(['HW-MN-W3-3']);
    expect(w.check_in.texts).toEqual(["Cravings rise and fall, like a wave. Next week includes a planned snack, so you're ready for them."]);
    expect(w.days.filter((d) => d.rule === 'R-HW-WEEK-01').map((d) => d.id)).toEqual(['LIB-TEST-SNACK-1']);
    expect(w.days).toHaveLength(7);
  }
  // The cravings note is brought forward next week through the notes rule.
  const n = one('note_for_day', { date: '2026-12-12', programme_day: 37, queue: defaultNotes.slice(36), shown: {}, pending: ['HW-MN-W3-3'] });
  expect(n).toMatchObject({ note: 'HW-MN-W3-3', rule: 'R-HW-WEEK-01', pending: [] });
});

test('TC-FR3-108-AS-01', () => {
  // 80.1, 80.0, 80.2 and 80.1 kg within 21 days: the plateau message and the block offer.
  const [r] = ok(['HWW-02d']);
  expect(r.text).toBe("Your weight has stayed about the same for 3 weeks. That's normal, and it doesn't mean the plan has stopped working. [Start 'Breaking a plateau'] [Keep my current plan]");
  expect(r.block).toBe('breaking-a-plateau');
  ok(['HWW-02a']);
});

test('TC-FR3-108-AS-02', () => {
  // Only 3 weigh-ins in 21 days, or a range of 0.6 kg: no plateau.
  const [range, three] = ok(['HWW-02b', 'HWW-02c']);
  expect(range.reason).toBe('range_above_0_5_kg');
  expect(three.reason).toBe('min_data');
});

test('TC-FR3-108-AS-03', () => {
  // A falling trend of 0.5 kg a week: no plateau.
  const weights = Object.fromEntries([0, 1, 2, 3].map((k) => [addDays('2026-11-19', -7 * k), 80 + 0.5 * k]));
  expect(one('plateau', { today: '2026-11-20', weights, goal_type: 'lose_weight' }).fires).toBe(false);
});

test('TC-FR3-108-AS-04', () => {
  // No suggested change sets a calorie target below the safe minimum: the plateau never changes the target at all; it
  // offers logging every meal or a meal-plan swap (signed R-ADJ-02, D3-45).
  const [r] = ok(['HWW-02d']);
  expect(r.calorie_change).toBeNull();
  expect(r.offers).toEqual(['log_every_meal_7_days', 'different_meal_plan']);
});

test('TC-FR3-108-AS-05', () => {
  // A member with an eating-disorder flag sees no plateau message (nor in pregnancy or below BMI 18.5, as signed).
  const [r] = ok(['HWW-02e']);
  expect(r.fires).toBe(false);
  const weights = { '2026-11-19': 80.1, '2026-11-14': 80.0, '2026-11-09': 80.2, '2026-11-04': 80.1 };
  for (const s of ['pregnancy', 'bmi_under_18_5']) expect(one('plateau', { today: '2026-11-20', weights, goal_type: 'lose_weight', situations: [s] }).fires).toBe(false);
  expect(fixtureFile('weekly').filter((f) => f.inputs.fn === 'plateau')).toHaveLength(6);
});
