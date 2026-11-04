// Metabolic Health Check (P3-59, FR3-51, FR3-45): the engine is run in PHP CLI without WordPress against the frozen
// fixtures and the independent Python oracle; the wording is checked against the signed approval records.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-metabolic.php');
const safety = resolve(plugin, 'includes/stage3/class-safety-stage3.php');
const bpCheck = resolve(plugin, 'includes/stage3/calculators/class-bp-check.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; require ${toPhpPath(safety)}; require ${toPhpPath(bpCheck)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const files = ['criteria-counts', 'partial', 'safety', 'units'];
const fixtures = files.flatMap((f) => json(`fixtures/metabolic/${f}.json`).fixtures);
const byId = Object.fromEntries(fixtures.map((f) => [f.id, f]));
const approval = json('approvals/stage3/metabolic.json');
const bpApproval = json('approvals/stage3/bp-check.json');
const oracle = json('oracles/orc-10-metabolic/results.json');

/** Run compute() over a list of inputs in one PHP call. */
const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Metabolic::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const sha = (s) => createHash('sha256').update(s, 'utf8').digest('hex');
const base = byId['FX-MET-CRIT-01'].inputs;

test('TC-FR3-51-AS-01', () => {
  // Every result matches the approved reference fixtures (all 20 rows of the sheet's table), and the engine matches
  // the oracle written independently from the sheet for every fixture.
  expect(fixtures).toHaveLength(20);
  const results = compute(fixtures.map((f) => f.inputs));
  const problems = [];
  fixtures.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) {
      if (!(k in results[i]) || !same(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
    }
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    if (!orc) { problems.push(`${f.id}: no oracle result`); return; }
    for (const [k, want] of Object.entries(orc.expected)) {
      if (!same(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != oracle ${JSON.stringify(want)}`);
    }
  });
  expect(problems).toEqual([]);
  expect(oracle.oracle_id).toBe('ORC-10');
  expect(oracle.results).toHaveLength(fixtures.length);
  expect(oracle.source_hash).toBe(createHash('sha256').update(readFileSync(resolve(root, 'oracles/orc-10-metabolic/met_ref.py'))).digest('hex'));
  // The overall wording follows the result: the stop alone, or urgent wording first, then headline and body, and the
  // "other definitions" line last.
  const [meets, preg, urgent] = compute([base, byId['FX-MET-SAFE-03'].inputs, byId['FX-MET-SAFE-02'].inputs]);
  expect(meets.messages.slice(0, 2)).toEqual(['results.meets.headline', 'results.meets.body']);
  expect(meets.messages.at(-1)).toBe('about.definition');
  expect(preg.messages).toEqual(['stops.pregnancy']);
  expect(urgent.messages.slice(0, 3)).toEqual(['flags.bpUrgent', 'results.notMet.headline', 'results.notMet.body']);
  // Waist cut-offs by background (sheet section 1): men 90 cm for the listed groups, 94 cm otherwise; women 80 cm.
  const cut = compute([
    ...['White', 'Europid', 'Indian', 'Pakistani', 'Bangladeshi', 'OtherSouthAsian', 'Chinese', 'Japanese', 'Ethnic South and Central American', 'BlackAfrican', 'Sub-Saharan African', 'MiddleEastern', 'Mixed', 'NotSay', 'OtherAsian', 'BlackCaribbean']
      .map((background) => ({ ...base, background })),
    { ...base, sex: 'female', background: 'Europid' }, { ...base, sex: 'female', background: 'Chinese' },
  ]).map((r) => [r.waistCutoffCm, r.flags.includes('notes.background')]);
  expect(cut).toEqual([[94, false], [94, false], [90, false], [90, false], [90, false], [90, false], [90, false], [90, false], [90, false], [94, false], [94, false], [94, false], [94, true], [94, true], [94, true], [94, true], [80, false], [80, false]]);
  // With a waist below the cut-off, "talk to your doctor" shows from 2 raised results, as sheet section 3 says (owner,
  // 3 Nov 2026, Owner_Questions 90; MS-14 re-derived by ORC-10): not at 1, at 2 (MS-14) and at 4 (MS-06), and not
  // with central obesity, where the "meets" body already asks the member to share the results with their doctor.
  const below = byId['FX-MET-CRIT-08'].inputs;
  const [one, two, four, centralTwo] = compute([
    { ...below, triglycerides: 1.0 }, below, byId['FX-MET-CRIT-04'].inputs, { ...below, background: 'Indian' },
  ]);
  const doctor = (r) => [r.count, r.flags.includes('notes.talkToDoctor'), r.messages.includes('notes.talkToDoctor')];
  expect([doctor(one), doctor(two), doctor(four)]).toEqual([[1, false, false], [2, true, true], [4, true, true]]);
  expect([centralTwo.central, centralTwo.result, doctor(centralTwo)]).toEqual([true, 'results.meets', [2, false, false]]);
  expect(Number(php('echo LLD_Members_Metabolic::TALK_TO_DOCTOR_MIN;'))).toBe(2);
});

test('TC-FR3-51-AS-02', () => {
  // mmol/L and mg/dL give the same answer: MS-03 and MS-04 (the same person) agree on everything that decides the
  // result, and each unit's own IDF threshold is used with no conversion before comparison (150 mg/dL counts; 1.69
  // mmol/L does not; 149 does not; 39 and 100 do).
  const [mmol, mg, mgEdge] = compute([byId['FX-MET-UNIT-01'].inputs, byId['FX-MET-UNIT-02'].inputs, byId['FX-MET-UNIT-03'].inputs]);
  for (const k of ['central', 'criteria', 'count', 'result', 'missing', 'flags']) expect(mg[k], k).toEqual(mmol[k]);
  expect(mgEdge.criteria).toEqual({ triglycerides: 'not-met', hdl: 'met', bloodPressure: 'not-met', fastingGlucose: 'met' });
  // Paired boundary values in both units, for both sexes.
  const pairs = [
    ['triglycerides', 1.7, 150, 'met'], ['triglycerides', 1.69, 149, 'not-met'],
    ['fastingGlucose', 5.6, 100, 'met'], ['fastingGlucose', 5.59, 99, 'not-met'],
  ];
  const male = { ...base, triglycerides: 1.0, hdl: 1.5, fastingGlucose: 5.0 };
  const cases = [];
  for (const [field, mm, mgv] of pairs) cases.push({ ...male, [field]: mm, [`${field}Unit`]: 'mmol/L' }, { ...male, [field]: mgv, [`${field}Unit`]: 'mg/dL' });
  for (const [sex, mm, mgv] of [['male', 1.03, 40], ['male', 1.02, 39], ['female', 1.29, 50], ['female', 1.28, 49], ['prefer-not-to-say', 1.28, 49]]) {
    cases.push({ ...male, sex, hdl: mm, hdlUnit: 'mmol/L' }, { ...male, sex, hdl: mgv, hdlUnit: 'mg/dL' });
  }
  const r = compute(cases);
  const got = r.map((x, i) => x.criteria[['triglycerides', 'triglycerides', 'fastingGlucose', 'fastingGlucose'][Math.floor(i / 2)] || 'hdl']);
  expect(got).toEqual(['met', 'met', 'not-met', 'not-met', 'met', 'met', 'not-met', 'not-met', 'not-met', 'not-met', 'met', 'met', 'not-met', 'not-met', 'met', 'met', 'met', 'met']);
  // Display conversions (sheet 1.2): the value entered and its conversion in brackets, never used to decide.
  const tg = mmol.components.find((c) => c.component === 'triglycerides');
  expect(tg).toMatchObject({ value: 1.7, unit: 'mmol/L', threshold: 1.7, converted: { value: 151, unit: 'mg/dL' } });
  const g = mg.components.find((c) => c.component === 'fastingGlucose');
  expect(g).toMatchObject({ value: 99, unit: 'mg/dL', threshold: 100, converted: { value: 5.5, unit: 'mmol/L' } });
  // Waist in inches is converted to cm (1 in = 2.54 cm) before the cut-off: 37 in = 93.98 cm < 94; 37.01 in >= 94.
  const [w37, w3701] = compute([{ ...base, waistCm: null, waistIn: 37 }, { ...base, waistCm: null, waistIn: 37.01 }]);
  expect([w37.central, w3701.central]).toEqual([false, true]);
  // The converted waist is returned to 2 dp, so the value shown is the one compared (the sheet gives no rounding):
  // 37 in shows as 93.98 cm, below 94, never as 94.0.
  expect([w37.waistCm, w3701.waistCm]).toEqual([93.98, 94.01]);
  // A value without a unit, out of range, or not a number is refused.
  const bad = compute([{ ...base, triglyceridesUnit: null }, { ...base, hdl: 0.1 }, { ...base, fastingGlucose: 700, fastingGlucoseUnit: 'mg/dL' }, { ...base, waistCm: 30 }, { ...base, sex: 'x' }, { ...base, bpSystolic: null, bpDiastolic: null }, { ...base, hdl: [] }]);
  expect(bad.map((x) => x.error)).toEqual(['triglyceridesUnit', 'hdl', 'fastingGlucose', 'waist', 'sex', 'bloodPressure', 'hdl']);
});

test('TC-FR3-51-AS-03', () => {
  // Missing lab values give a partial result that says which values are missing (MS-07, MS-08), and no partial when
  // two criteria can no longer be reached (MS-12) or are already met.
  const [p1, p2, p3] = compute(['FX-MET-PART-01', 'FX-MET-PART-02', 'FX-MET-PART-03'].map((id) => byId[id].inputs));
  for (const p of [p1, p2]) {
    expect(p.result).toBe('results.partial');
    expect(p.missing).toEqual(['triglycerides', 'hdl', 'fastingGlucose']);
    expect(p.placeholders['missing list']).toBe('triglycerides, HDL cholesterol and fasting glucose');
    expect(p.messages.slice(0, 2)).toEqual(['results.partial.headline', 'results.partial.body']);
  }
  expect(p3.result).toBe('results.notMet');
  expect(p3.missing).toEqual([]);
  expect(p3.components.find((c) => c.component === 'fastingGlucose').status).toBe('unknown');
  const [one, met, dontKnow, notFasting] = compute([
    { ...base, waistCm: 100, bpSystolic: 120, bpDiastolic: 70, triglycerides: 2.0, hdl: 1.5, fastingGlucose: null, fastingGlucoseUnit: null },
    { ...base, triglycerides: null, triglyceridesUnit: null },
    { ...base, bpSystolic: 120, bpDiastolic: 70, triglycerides: 1.0, hdl: "don't know", hdlUnit: null, fastingGlucose: 'unknown', fastingGlucoseUnit: null },
    { ...base, bpSystolic: 120, bpDiastolic: 70, triglycerides: 2.0, hdl: 1.5, fastingGlucoseFasting: false },
  ]);
  expect([one.result, one.missing, one.placeholders['missing list']]).toEqual(['results.partial', ['fastingGlucose'], 'fasting glucose']);
  expect([met.result, met.missing, met.criteria.triglycerides]).toEqual(['results.meets', [], 'unknown']);
  expect([dontKnow.result, dontKnow.missing]).toEqual(['results.partial', ['hdl', 'fastingGlucose']]);
  // A non-fasting glucose is not used: it counts as missing and the "use a fasting result" note is given.
  expect([notFasting.result, notFasting.missing, notFasting.notes]).toEqual(['results.partial', ['fastingGlucose'], ['notes.useFasting']]);
  // Treatment counts as met even with the value unknown.
  const [tgRx] = compute([{ ...base, bpSystolic: 120, bpDiastolic: 70, triglycerides: null, triglyceridesUnit: null, triglyceridesMedicine: true, hdl: 1.5, fastingGlucose: 6.0 }]);
  expect([tgRx.criteria.triglycerides, tgRx.count, tgRx.result]).toEqual(['met', 2, 'results.meets']);
});

test('FR3-51 safety (sheet section 4): stops, urgent wording and flags', () => {
  const [young, preg, notSure, bpU, diaU, gDiag, gHigh, gLow, gUrgLow, mgLow, tgHigh, t2d, eating, pnts] = compute([
    { ...base, age: 17 }, { ...base, pregnantOrBreastfeeding: 'yes' }, { ...base, pregnantOrBreastfeeding: 'not-sure' },
    { ...base, bpSystolic: 180, bpDiastolic: 90 }, { ...base, bpSystolic: 150, bpDiastolic: 120 },
    { ...base, fastingGlucose: 200, fastingGlucoseUnit: 'mg/dL' }, { ...base, fastingGlucose: 15 },
    { ...base, fastingGlucose: 3.8 }, { ...base, fastingGlucose: 2.9 }, { ...base, fastingGlucose: 53, fastingGlucoseUnit: 'mg/dL' },
    { ...base, triglycerides: 885, triglyceridesUnit: 'mg/dL' }, { ...base, fastingGlucose: null, fastingGlucoseUnit: null, type2Diabetes: true },
    { ...base, eatingDisorder: 'yes' }, { ...base, sex: 'prefer-not-to-say', waistCm: 80 },
  ]);
  expect([young.stop, young.messages, young.count]).toEqual(['stops.under18', ['stops.under18'], null]);
  // The urgent wording comes before anything else, a stop included (P3-03 A1, D5), whatever the age; in pregnancy
  // (yes or not sure) the safety matrix's pregnancy levels and wording apply.
  const [pregBp, pregGlu, notSureBp, sameDay, pregNormal, youngBp, youngGlu, rounded, roundedDown] = compute([
    { ...base, pregnantOrBreastfeeding: 'yes', bpSystolic: 190, bpDiastolic: 125 },
    { ...base, pregnantOrBreastfeeding: 'yes', fastingGlucose: 2.5 },
    { ...base, pregnantOrBreastfeeding: 'not-sure', bpSystolic: 160, bpDiastolic: 80 },
    { ...base, pregnantOrBreastfeeding: 'yes', bpSystolic: 145, bpDiastolic: 80 },
    { ...base, pregnantOrBreastfeeding: 'yes', bpSystolic: 135, bpDiastolic: 85 },
    { ...base, age: 16, bpSystolic: 185, bpDiastolic: 90 },
    { ...base, age: 16, fastingGlucose: 16 },
    { ...base, bpSystolic: 179.5, bpDiastolic: 90 },
    { ...base, bpSystolic: 129.4, bpDiastolic: 84.4 },
  ]);
  expect(pregBp.messages).toEqual(['flags.bpUrgentPregnancy', 'stops.pregnancy']);
  expect([pregGlu.messages, pregGlu.glucoseUrgent]).toEqual([['flags.glucoseUrgent', 'stops.pregnancy'], 'low']);
  expect(notSureBp.messages).toEqual(['flags.bpUrgentPregnancy', 'stops.pregnancy']);
  expect(sameDay.messages).toEqual(['flags.bpSameDayPregnancy', 'stops.pregnancy']);
  expect([pregNormal.messages, pregNormal.flags]).toEqual([['stops.pregnancy'], []]);
  expect(youngBp.messages).toEqual(['flags.bpUrgent', 'stops.under18']);
  expect([youngGlu.messages, youngGlu.glucoseUrgent]).toEqual([['flags.glucoseUrgent', 'stops.under18'], 'high']);
  const textOf = JSON.parse(php(`echo json_encode([LLD_Members_Metabolic::wording(['flags.bpUrgentPregnancy', 'stops.pregnancy']), LLD_Members_Metabolic::wording(['flags.glucoseUrgent'], ['glucoseUrgent' => 'low'])]);`));
  expect(textOf[0]['flags.bpUrgentPregnancy']).toMatch(/160\/110/);
  expect(textOf[0]['stops.pregnancy']).toMatch(/midwife/);
  expect(textOf[1]['flags.glucoseUrgent']).toMatch(/15 g of fast sugar/);
  // Blood pressure is rounded to whole mmHg, half-up, before every level (as the heart-risk check does).
  expect([rounded.flags.includes('flags.bpUrgent'), rounded.components.find((c) => c.component === 'bloodPressure').value]).toEqual([true, { systolic: 180, diastolic: 90 }]);
  expect([roundedDown.criteria.bloodPressure, roundedDown.components.find((c) => c.component === 'bloodPressure').value]).toEqual(['not-met', { systolic: 129, diastolic: 84 }]);
  // A reading that isn't possible is refused as the Blood Pressure Check refuses it (the safety matrix's ranges):
  // no result, the BP check's signed "check the numbers" line, and an urgent glucose still first.
  const [gap, high, low, gapGlu] = compute([
    { ...base, bpSystolic: 130, bpDiastolic: 125 }, { ...base, bpSystolic: 261, bpDiastolic: 100 }, { ...base, bpSystolic: 120, bpDiastolic: 29 },
    { ...base, bpSystolic: 130, bpDiastolic: 125, fastingGlucose: 2.5, pregnantOrBreastfeeding: 'yes' },
  ]);
  for (const r of [gap, high, low]) expect([r.refused, r.result, r.criteria, r.messages]).toEqual([true, null, null, ['refused.impossible']]);
  expect([gapGlu.refused, gapGlu.stop, gapGlu.messages]).toEqual([true, null, ['flags.glucoseUrgent', 'refused.impossible']]);
  expect(gap.refusedField).toBe('bloodPressure');
  // A glucose the safety matrix treats as possible but outside the sheet's input range (2-30 mmol/L, 36-540 mg/dL)
  // still shows the urgent wording first, with no metabolic result (refused, the field named, not a 422 error).
  // Only a value the matrix calls impossible (below 1 or above 35 mmol/L, mg/dL / 18.016 to 1 dp) is an error.
  const outRange = compute([
    { ...base, fastingGlucose: 1.8 }, { ...base, fastingGlucose: 32, fastingGlucoseUnit: 'mg/dL' },
    { ...base, fastingGlucose: 33 }, { ...base, fastingGlucose: 580, fastingGlucoseUnit: 'mg/dL' },
    { ...base, fastingGlucose: 1.8, pregnantOrBreastfeeding: 'yes' },
  ]);
  expect(outRange.map((r) => [r.refused, r.refusedField, r.result, r.criteria, r.messages, r.glucoseUrgent])).toEqual([
    [true, 'fastingGlucose', null, null, ['flags.glucoseUrgent'], 'low'],
    [true, 'fastingGlucose', null, null, ['flags.glucoseUrgent'], 'low'],
    [true, 'fastingGlucose', null, null, ['flags.glucoseUrgent'], 'high'],
    [true, 'fastingGlucose', null, null, ['flags.glucoseUrgent'], 'high'],
    [false, null, null, null, ['flags.glucoseUrgent', 'stops.pregnancy'], 'low'],
  ]);
  const lowText = JSON.parse(php("echo json_encode(LLD_Members_Metabolic::wording(['flags.glucoseUrgent'], ['glucoseUrgent' => 'low']));"));
  expect(lowText['flags.glucoseUrgent']).toMatch(/15 g of fast sugar/);
  expect(compute([{ ...base, fastingGlucose: 0.9 }, { ...base, fastingGlucose: 17, fastingGlucoseUnit: 'mg/dL' }, { ...base, fastingGlucose: 36 }]).map((r) => r.error))
    .toEqual(['fastingGlucose', 'fastingGlucose', 'fastingGlucose']);
  // Blood pressure has no such gap: the sheet's input range is the safety matrix's possible range (60-260 / 30-160).
  expect(JSON.parse(php("echo json_encode(LLD_Members_Stage3_Safety::LEVELS['blood_pressure']['impossible']);"))).toMatchObject({ sys: [60, 260], dia: [30, 160] });
  for (const r of [preg, notSure]) expect([r.stop, r.messages, r.criteria, r.result]).toEqual(['stops.pregnancy', ['stops.pregnancy'], null, null]);
  for (const r of [bpU, diaU]) { expect(r.flags).toContain('flags.bpUrgent'); expect(r.messages[0]).toBe('flags.bpUrgent'); }
  expect(gDiag.flags).toEqual(['flags.glucoseDiagnostic']);
  expect([gHigh.flags, gHigh.glucoseUrgent, gHigh.messages[0]]).toEqual([['flags.glucoseUrgent'], 'high', 'flags.glucoseUrgent']);
  expect(gLow.flags).toEqual(['flags.glucoseLow']);
  expect([gUrgLow.flags, gUrgLow.glucoseUrgent]).toEqual([['flags.glucoseUrgent'], 'low']);
  expect([mgLow.flags, mgLow.glucoseUrgent]).toEqual([['flags.glucoseUrgent'], 'low']);
  expect(tgHigh.flags).toEqual(['flags.triglyceridesHigh']);
  expect([t2d.criteria.fastingGlucose, t2d.notes]).toEqual(['met', ['notes.diabetes']]);
  // An eating-disorder answer runs the check but hides the weight-loss suggestion (D3-25).
  expect([eating.result, eating.lifestyleWaistShown]).toEqual(['results.meets', false]);
  expect(eating.messages).not.toContain('lifestyle.waist');
  expect(eating.messages).toContain('lifestyle.activitySleep');
  expect(compute([base])[0].messages).toContain('lifestyle.waist');
  // "Prefer not to say" uses the 80 cm waist and the female HDL threshold, with the note (D3-23).
  expect([pnts.central, pnts.waistCutoffCm, pnts.flags]).toEqual([true, 80, ['notes.sex']]);
  // The member's profile applies as well as the page answers (apply_profile).
  const prof = JSON.parse(php(`echo json_encode(LLD_Members_Metabolic::apply_profile(['sex' => 'female'], ['pregnancy', 'eating_disorder', 'diabetes', 'kidney']));`));
  expect(prof).toMatchObject({ pregnantOrBreastfeeding: 'yes', eatingDisorder: 'yes', type2Diabetes: true, kidneyDisease: true });
  const none = JSON.parse(php(`echo json_encode(LLD_Members_Metabolic::apply_profile(['eatingDisorder' => 'yes'], []));`));
  expect(none).toEqual({ eatingDisorder: 'yes' });
  // One yes/no parser for every tick: "no", "false", false, 0 and "0" are no; "yes", "true", true, 1 and "YES" are
  // yes; anything else is refused. Review example: bpMedicine "no" with BP 120/70 is not met with 1 criterion.
  const tickBase = { ...base, waistCm: 100, bpSystolic: 120, bpDiastolic: 70, triglycerides: 1.0, hdl: 1.2, fastingGlucose: 5.0 };
  const fields = { bpMedicine: 'bloodPressure', triglyceridesMedicine: 'triglycerides', hdlMedicine: 'hdl', type2Diabetes: 'fastingGlucose' };
  for (const [field, crit] of Object.entries(fields)) {
    for (const r of compute(['no', 'false', false, 0, '0', null].map((v) => ({ ...tickBase, [field]: v })))) expect(r.criteria[crit], `${field} no`).toBe('not-met');
    for (const r of compute(['yes', 'true', true, 1, 'YES'].map((v) => ({ ...tickBase, [field]: v })))) expect(r.criteria[crit], `${field} yes`).toBe('met');
    expect(compute([{ ...tickBase, [field]: 'maybe' }])[0].error).toBe(field);
  }
  const [bpNo] = compute([{ ...tickBase, triglycerides: 2.0, bpMedicine: 'no' }]);
  expect([bpNo.result, bpNo.count]).toEqual(['results.notMet', 1]);
  // fastingGlucoseFasting: "no", "false", false and 0 mean not fasting (the value isn't used); left out means fasting.
  for (const r of compute(['no', 'false', false, 0].map((v) => ({ ...tickBase, fastingGlucose: 6.0, fastingGlucoseFasting: v })))) {
    expect([r.criteria.fastingGlucose, r.notes]).toEqual(['unknown', ['notes.useFasting']]);
  }
  for (const r of compute([undefined, null, 'yes', 'true', true, 1].map((v) => ({ ...tickBase, fastingGlucose: 6.0, fastingGlucoseFasting: v })))) {
    expect([r.criteria.fastingGlucose, r.notes]).toEqual(['met', []]);
  }
  expect(compute([{ ...tickBase, fastingGlucoseFasting: 'maybe' }])[0].error).toBe('fastingGlucoseFasting');
  // Pregnancy answers read true/false the same way.
  expect(compute([{ ...tickBase, pregnantOrBreastfeeding: 'false' }, { ...tickBase, pregnantOrBreastfeeding: true }]).map((r) => r.stop)).toEqual([null, 'stops.pregnancy']);
  // Nothing about price or medicine changes: the engine has no hooks, database or upsell.
  const src = readFileSync(engine, 'utf8');
  expect(src).not.toMatch(/add_action|add_filter|\$wpdb|get_option|wp_remote|premium|price/i);
});

test('NFR3-14 (wording): every signed string in the engine hashes to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Metabolic::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : sha(M[key]);
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  // Wording quoted from other signed tools is taken from them, never retyped: the Blood Pressure Check's signed text
  // (P3-58, hashed in its approval record) and the glucose urgent wording (P3-03 safety matrix), low or high.
  const B = JSON.parse(php('echo json_encode(LLD_Members_BP_Check::signed_text());'));
  const T = JSON.parse(php('echo json_encode(LLD_Members_Metabolic::BP_TEXT);'));
  const bpw = JSON.parse(php('echo json_encode(LLD_Members_Metabolic::wording(array_keys(LLD_Members_Metabolic::BP_TEXT)));'));
  const Q = {};
  for (const [key, from] of Object.entries(T)) {
    expect(bpw[key], key).toBe(B[from]);
    expect(sha(bpw[key]), key).toBe(bpApproval.copy_hashes[from]);
    Q[key] = bpw[key];
  }
  expect(Object.keys(T).sort()).toEqual(['flags.bpSameDayPregnancy', 'flags.bpUrgent', 'flags.bpUrgentPregnancy', 'refused.impossible']);
  expect(readFileSync(engine, 'utf8')).not.toMatch(/Your reading is very high|QUOTED/);
  const S = JSON.parse(php('echo json_encode(LLD_Members_Stage3_Safety::MESSAGES);'));
  const w = JSON.parse(php(`echo json_encode([
    LLD_Members_Metabolic::wording(['flags.glucoseUrgent', 'results.meets.headline', 'flags.bpUrgent', 'notes.talkToDoctor'], ['glucoseUrgent' => 'low']),
    LLD_Members_Metabolic::wording(['flags.glucoseUrgent'], ['glucoseUrgent' => 'high']),
  ]);`));
  expect(w[0]).toEqual({ 'flags.glucoseUrgent': S['glucose.urgent-low'], 'results.meets.headline': M['results.meets.headline'], 'flags.bpUrgent': Q['flags.bpUrgent'] });
  expect(w[1]).toEqual({ 'flags.glucoseUrgent': S['glucose.urgent-high'] });
  // Every key a fixture result shows is either signed here, quoted, or a content-library key (described in the
  // sheet but not quoted, so it carries no text in the engine).
  const library = new Set(['notes.talkToDoctor', 'notes.sex', 'notes.background', 'notes.useFasting', 'stops.under18', 'lifestyle.activitySleep', 'lifestyle.triglycerides', 'lifestyle.hdl', 'lifestyle.bloodPressure', 'lifestyle.fastingGlucose']);
  const keys = new Set(compute(fixtures.map((f) => f.inputs)).flatMap((r) => r.messages));
  for (const k of keys) expect(k in M || k in Q || k === 'flags.glucoseUrgent' || library.has(k), k).toBe(true);
  // The engine is reached through the members' calculate route by its tool slug.
  expect(readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8')).toMatch(/'metabolic'\s+=> 'LLD_Members_Metabolic'/);
});
