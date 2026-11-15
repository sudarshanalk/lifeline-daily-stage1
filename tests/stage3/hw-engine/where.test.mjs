// FR3-97 "Where you are now" (P3-35; R-HW-NUM-01, -02, D3-54): the Healthy Weight Check engine ported to PHP
// (LLD_Members_HW_Where) against the Check's frozen fixtures and the signed P3-35 method sheet.
import { readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';
import { call, json, one, php, replay, fixtureById, fixtureFile, root } from './hw-harness.mjs';

const ok = (srcs) => {
  const rows = replay(srcs.map((s) => { const f = fixtureById(s); if (!f) throw new Error(`no fixture ${s}`); return f; }));
  expect(rows.flatMap((r) => r.problems)).toEqual([]);
  return rows.map((r) => r.result);
};

test('TC-FR3-97-AS-01', () => {
  // 163 cm, 80 kg, waist 98: BMI 30.1 (obesity), waist-to-height 0.60 (high risk), healthy range 49.1 to 66.2 kg.
  const [r] = ok(['HWN-01a']);
  expect(r).toMatchObject({ rule: 'R-HW-NUM-01', stop: null, bmi: 30.1, category: 'obese1', adjusted: false, waist: { ratio: 0.6, band: 'high' }, range: { lowKg: 49.1, highKg: 66.2 } });
  ok(['HWN-02a']);
  // The range line is the Check's approved wording (T2 = A), filled with the engine's numbers.
  const text = php(`echo LLD_Members_Rule_Catalogue::fill('hw.T-HW-NUM-02', ['low' => '49.1', 'high' => '66.2']);`);
  expect(text).toBe('A healthy weight range for your height: **49.1 to 66.2 kg**. A guide for your height, not a target. Health is about more than one number.');
});

test('TC-FR3-97-AS-02', () => {
  // The same member with a South Asian background in the health profile: the 23 and 27.5 cut-offs, range 49.1 to 60.9 kg.
  const [r] = ok(['HWN-01b']);
  expect(r.adjusted).toBe(true);
  expect(r.ask_background).toBe(false);
  expect(r.range).toMatchObject({ lowKg: 49.1, highKg: 60.9 });
  ok(['HWN-02b', 'HWN-01c-1', 'HWN-01c-2', 'HWN-01c-3']);
  // The lower cut-offs: 62 kg at 163 cm is overweight from 23, healthy under the standard bands.
});

test('TC-FR3-97-AS-03', () => {
  // Every BMI and waist-to-height result matches the Healthy Weight Check's engine on its frozen test cases: all 120
  // fixtures in fixtures/healthy-weight/ (BMI, waist, range, safety stops, units, invalid input) replay exactly.
  const dir = resolve(root, 'fixtures/healthy-weight');
  const fxs = readdirSync(dir).filter((n) => n.endsWith('.json')).flatMap((n) => json(`fixtures/healthy-weight/${n}`).fixtures);
  expect(fxs.length).toBe(120);
  const got = call(fxs.map((f) => ['validate', f.inputs]));
  const problems = [];
  fxs.forEach((f, i) => { if (JSON.stringify(got[i]) !== JSON.stringify(f.expected)) problems.push(`${f.id}: ${JSON.stringify(got[i])} != ${JSON.stringify(f.expected)}`); });
  expect(problems).toEqual([]);
  // The ten frozen range fixtures in particular (R-HW-NUM-02 HWN-02c), e.g. 162.5 cm -> 48.8 to 65.8 and 48.8 to 60.6 kg.
  ok(['HWN-02c-1', 'HWN-02c-2']);
  expect(fxs.filter((f) => f.id.startsWith('FX-HW-RANGE-'))).toHaveLength(10);
});

test('TC-FR3-97-AS-04', () => {
  // A member who says they are pregnant sees no BMI band: the pregnancy stop, with no number at all.
  const [preg, notsure, bf, u18] = call([
    ['where_now', { weight_kg: 80, height_cm: 163, waist_cm: 98, age: 30, pregnant: 'yes' }],
    ['where_now', { weight_kg: 80, height_cm: 163, age: 30, pregnant: 'notsure' }],
    ['where_now', { weight_kg: 80, height_cm: 163, age: 30, pregnant: 'breastfeeding' }],
    ['where_now', { weight_kg: 80, height_cm: 163, age: 17 }],
  ]);
  for (const r of [preg, notsure, bf]) {
    expect(r.stop).toBe('pregnancy');
    expect(r).not.toHaveProperty('bmi');
    expect(r).not.toHaveProperty('category');
    expect(r).not.toHaveProperty('range');
  }
  expect(u18.stop).toBe('under18');
  expect(u18).not.toHaveProperty('bmi');
  // R-HW-SAFE-01: and Healthy Weight itself is not offered, with no calorie target stored (fixture HWS-01a).
  ok(['HWS-01a']);
});

test('TC-FR3-97-AS-05', () => {
  // Skipping waist shows the BMI result alone; adding the waist adds the waist result.
  const [skip, added] = call([
    ['where_now', { weight_kg: 80, height_cm: 163, waist_cm: null, age: 45, ethnicity: 'Indian' }],
    ['where_now', { weight_kg: 80, height_cm: 163, waist_cm: 98, age: 45, ethnicity: 'Indian' }],
  ]);
  expect(skip.bmi).toBe(30.1);
  expect(skip.waist).toBeNull();
  expect(added.waist).toEqual({ ratio: 0.6, band: 'high' });
  // At BMI 35 or more the ratio is not shown (signed Appendix B rule).
  expect(one('where_now', { weight_kg: 95, height_cm: 163, waist_cm: 110, age: 45, ethnicity: 'Indian' }).waist.band).toBe('notShown');
});

test('TC-FR3-97-AS-06', () => {
  // Profile 'Other / mixed': the Check's background question is asked once; Middle Eastern gives the lower cut-offs;
  // the profile's own answer is an input only and never changed (the engine returns no profile value).
  ok(['BGD-01', 'BGD-02', 'BGD-03', 'BGD-04', 'BGD-05', 'BGD-06', 'BGD-07']);
  const profile = { weight_kg: 62, height_cm: 163, age: 45, ethnicity: 'Other' };
  const [ask, answered] = call([['where_now', profile], ['where_now', { ...profile, bmi_background: 'MiddleEastern' }]]);
  expect(ask.ask_background).toBe(true);
  expect(ask.group).toBe('standard'); // until answered
  expect(answered.ask_background).toBe(false);
  expect(answered.adjusted).toBe(true);
  expect(answered.category).toBe('overweight');
  expect(profile.ethnicity).toBe('Other');
  expect(Object.keys(answered)).not.toContain('ethnicity');
  // The fixture file carries the whole D3-54 table.
  expect(fixtureFile('where').filter((f) => f.inputs.fn === 'background_group')).toHaveLength(7);
});
