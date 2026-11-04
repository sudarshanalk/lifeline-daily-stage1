// Metabolic health check page (P3-59, FR3-51): the page class's form, inputs and result, run in PHP CLI with the real
// engine. Cases follow the signed sheet's fixture table (MS-nn).
import { expect, test } from 'vitest';
import { run, textOf, php, arr } from './tools/calc-page-php.mjs';

const P = 'LLD_Members_Calc_Page_Metabolic';
const E = 'LLD_Members_Metabolic';
// MS-01: male, White (Europid), waist 102, BP 135/88, TG 2.0, HDL 0.9, FPG 6.1 (mmol/L).
const ms01 = {
  age: '52', sex: 'male', background: 'White', pregnantOrBreastfeeding: 'no', waistCm: '102',
  bpSystolic: '135', bpDiastolic: '88', bpMedicine: 'no',
  triglycerides: '2.0', triglyceridesUnit: 'mmol/L', triglyceridesMedicine: 'no',
  hdl: '0.9', hdlUnit: 'mmol/L', hdlMedicine: 'no',
  fastingGlucose: '6.1', fastingGlucoseUnit: 'mmol/L', type2Diabetes: 'no', fastingGlucoseFasting: 'yes', eatingDisorder: 'no',
};
// MS-03: female, South Asian, waist 80, BP 128/84, TG 1.7, HDL 1.29, FPG 5.5.
const ms03 = { ...ms01, sex: 'female', background: 'Indian', waistCm: '80', bpSystolic: '128', bpDiastolic: '84', triglycerides: '1.7', hdl: '1.29', fastingGlucose: '5.5' };
const signed = (key) => JSON.parse(php(`echo json_encode(${E}::wording(['${key}']));`))[key];
const rows = (html) => [...html.matchAll(/<tr data-lld-component="([^"]+)" data-lld-status="([^"]+)">([\s\S]*?)<\/tr>/g)].map((m) => ({ c: m[1], s: m[2], text: textOf(m[3]) }));
const noTemplate = (html) => expect(html).not.toMatch(/\{|\}|\|/);

test('TC-FR3-51-AS-01 (page): a meets result shows the signed headline and body, and one row per component', () => {
  const out = run(P, E, ms01);
  expect(out.result.result).toBe('results.meets');
  const text = textOf(out.html);
  expect(text).toContain(signed('results.meets.headline'));
  expect(text).toContain(signed('results.meets.body'));
  expect(text).toContain(signed('about.definition'));
  expect(text).toContain(signed('lifestyle.waist'));
  expect(out.html).toContain('data-lld-outcome="met"');
  const r = rows(out.html);
  expect(r.map((x) => x.c)).toEqual(['waist', 'triglycerides', 'hdl', 'fastingGlucose', 'bloodPressure']);
  expect(r.map((x) => x.s)).toEqual(['met', 'met', 'met', 'met', 'met']);
  expect(r[1].text).toBe('Triglycerides 2 mmol/L (177 mg/dL) at or above 1.7 mmol/L Above the threshold');
  expect(r[2].text).toBe('HDL cholesterol 0.9 mmol/L (35 mg/dL) below 1.03 mmol/L Below the threshold');
  expect(r[4].text).toContain('135/88 mmHg');
  // The order: headline, then table, then the lifestyle lines, then the about line.
  expect(out.html.indexOf('lld-calc-headline')).toBeLessThan(out.html.indexOf('lld-calc-table'));
  expect(out.html.indexOf('lld-calc-table')).toBeLessThan(out.html.indexOf('data-lld-key="lifestyle.waist"'));
  expect(out.html.indexOf('data-lld-key="lifestyle.waist"')).toBeLessThan(out.html.indexOf('data-lld-key="about.definition"'));
  noTemplate(out.html);
  // MS-14: waist below the cut-off, {n} filled, and the "talk to your doctor" line with 2 raised.
  const ms14 = run(P, E, { ...ms01, waistCm: '90', bpSystolic: '131', bpDiastolic: '80', triglycerides: '1.8', hdl: '1.2', fastingGlucose: '5.0' });
  expect(ms14.result.result).toBe('results.notMet.waistBelow');
  expect(textOf(ms14.html)).toContain(signed('results.notMet.waistBelow.body').replace('{n}', '2'));
  expect(textOf(ms14.html)).toContain(signed('notes.talkToDoctor'));
  expect(ms14.html).toContain('data-lld-outcome="not met"');
  noTemplate(ms14.html);
  // MS-03: waist met, 1 criterion: "1 of 4 other results".
  const not = run(P, E, ms03);
  expect(textOf(not.html)).toContain('1 of 4 other results are above their thresholds.');
});

test('TC-FR3-51-AS-02 (page): mmol/L and mg/dL give the same answer, and the table shows the value as entered with its conversion', () => {
  const mmol = run(P, E, ms03);
  const mg = run(P, E, { ...ms03, triglycerides: '150', triglyceridesUnit: 'mg/dL', hdl: '50', hdlUnit: 'mg/dL', fastingGlucose: '99', fastingGlucoseUnit: 'mg/dL' });
  expect(mg.inputs.triglyceridesUnit).toBe('mg/dL');
  expect(mg.result.result).toBe(mmol.result.result);
  expect(mg.result.count).toBe(mmol.result.count);
  expect(mg.result.criteria).toEqual(mmol.result.criteria);
  expect(rows(mg.html).map((x) => x.s)).toEqual(rows(mmol.html).map((x) => x.s));
  expect(textOf(mg.html.match(/<p class="lld-calc-figure"[\s\S]*?<\/p>/)[0])).toBe(textOf(mmol.html.match(/<p class="lld-calc-figure"[\s\S]*?<\/p>/)[0]));
  expect(rows(mg.html)[1].text).toBe('Triglycerides 150 mg/dL (1.69 mmol/L) at or above 150 mg/dL Above the threshold');
  expect(rows(mg.html)[2].text).toBe('HDL cholesterol 50 mg/dL (1.29 mmol/L) below 50 mg/dL Within range');
  noTemplate(mg.html);
});

test('TC-FR3-51-AS-03 (page): missing blood values give the partial result naming them, and the rows say "Not provided"', () => {
  // MS-07: female, Chinese, waist 85, BP 118/76, no blood results.
  const out = run(P, E, { ...ms01, sex: 'female', background: 'Chinese', waistCm: '85', bpSystolic: '118', bpDiastolic: '76', triglycerides: '', hdl: '', fastingGlucose: '' });
  expect(out.inputs.triglycerides).toBeNull();
  expect(out.result.result).toBe('results.partial');
  const text = textOf(out.html);
  expect(text).toContain('Partial result: triglycerides, HDL cholesterol and fasting glucose needed');
  expect(text).toContain("We can't complete the check without triglycerides, HDL cholesterol and fasting glucose. Ask your doctor");
  expect(out.html).toContain('data-lld-outcome="partial"');
  const r = rows(out.html);
  expect(r.slice(1, 4).map((x) => x.s)).toEqual(['unknown', 'unknown', 'unknown']);
  expect(r[2].text).toBe('HDL cholesterol - below 1.29 mmol/L (50 mg/dL) Not provided');
  noTemplate(out.html);
  // Only fasting glucose missing, with one criterion met: the one missing value is named alone.
  const one = run(P, E, { ...ms01, triglycerides: '1.0', hdl: '1.5', fastingGlucose: '', bpSystolic: '140' });
  expect(textOf(one.html)).toContain('Partial result: fasting glucose needed');
  noTemplate(one.html);
});

test('Metabolic page: urgent wording comes first as the alert, and a stop shows its wording and no table', () => {
  // MS-16: BP 185/122 puts the frozen urgent wording first, then the result.
  const urgent = run(P, E, { ...ms03, sex: 'female', background: 'White', waistCm: '85', bpSystolic: '185', bpDiastolic: '122', triglycerides: '1.0', hdl: '1.5', fastingGlucose: '5.0' });
  expect(urgent.html.startsWith('<div class="lld-safety lld-calc-urgent" role="alert" data-lld-key="bp-check.urgent.alert">')).toBe(true);
  expect(urgent.html.indexOf('lld-calc-urgent')).toBeLessThan(urgent.html.indexOf('lld-calc-headline'));
  expect(urgent.html).toContain('<a href="https://example.test/safety/">When to seek urgent care</a>');
  noTemplate(urgent.html);
  // MS-19: glucose 2.8 gives the low glucose urgent wording first.
  const low = run(P, E, { ...ms03, background: 'White', waistCm: '85', bpSystolic: '120', bpDiastolic: '80', triglycerides: '1.0', hdl: '1.5', fastingGlucose: '2.8' });
  expect(low.html).toContain('data-lld-key="glucose.urgent-low"');
  expect(low.html.indexOf('glucose.urgent-low')).toBeLessThan(low.html.indexOf('lld-calc-headline'));
  noTemplate(low.html);
  // MS-15: fasting glucose 12 is a caution (diagnostic range), not the alert.
  const diag = run(P, E, { ...ms03, background: 'White', waistCm: '85', bpSystolic: '120', bpDiastolic: '80', triglycerides: '1.0', hdl: '1.5', fastingGlucose: '12' });
  expect(diag.html).toContain('data-lld-key="flags.glucoseDiagnostic"');
  expect(diag.html).not.toContain('role="alert"');
  expect(textOf(diag.html)).toContain(signed('flags.glucoseDiagnostic'));
  // MS-17: pregnant stops the check: its wording only, no table, no count, nothing to save.
  const preg = run(P, E, { ...ms03, pregnantOrBreastfeeding: 'yes' });
  expect(preg.result.stop).toBe('stops.pregnancy');
  expect(textOf(preg.html)).toBe(signed('stops.pregnancy'));
  expect(preg.html).not.toContain('lld-calc-table');
  expect(preg.html).not.toMatch(/\d of 4/);
  expect(preg.saved).toBeNull();
  // Under 18 stops too.
  const young = run(P, E, { ...ms01, age: '16' });
  expect(textOf(young.html)).toBe(signed('stops.under18'));
  expect(young.saved).toBeNull();
  // Pregnant with a raised blood pressure: the pregnancy safety line comes before the stop.
  const pregBp = run(P, E, { ...ms03, pregnantOrBreastfeeding: 'yes', bpSystolic: '150', bpDiastolic: '95' });
  expect(pregBp.html).toMatch(/data-lld-key="bp-check\.pregnancy\.(same-day|urgent)"/);
  expect(pregBp.html.indexOf('bp-check.pregnancy')).toBeLessThan(pregBp.html.indexOf('data-lld-stop="pregnancy"'));
});

test('Metabolic page: the eating-disorder answer hides the weight-loss line, and a non-fasting glucose is not used', () => {
  const ed = run(P, E, { ...ms01, eatingDisorder: 'yes' });
  expect(ed.result.result).toBe('results.meets');
  expect(ed.html).not.toContain('data-lld-key="lifestyle.waist"');
  expect(textOf(ed.html)).toContain(signed('lifestyle.activitySleep'));
  const nf = run(P, E, { ...ms01, fastingGlucoseFasting: 'no' });
  expect(textOf(nf.html)).toContain(signed('notes.useFasting'));
  expect(rows(nf.html)[3].text).toContain('Not used: not a fasting result');
  // Medicine ticks count the criterion as met and say why.
  const med = run(P, E, { ...ms01, bpSystolic: '', bpDiastolic: '', bpMedicine: 'yes', hdlMedicine: 'yes', type2Diabetes: 'yes' });
  const r = rows(med.html);
  expect(r[4].text).toBe('Blood pressure - at or above 130 (top) or 85 (bottom) mmHg Counted as above: you take medicine for this');
  expect(r[3].text).toContain('Counted as above: type 2 diabetes');
  expect(textOf(med.html)).toContain(signed('notes.diabetes'));
  noTemplate(med.html);
});

test('TC-FR3-55-AS-01 (metabolic page): the saved row is the outcome, the count of criteria met and each component status', () => {
  const out = run(P, E, ms01);
  expect(out.saved).toMatchObject({
    calculator: 'metabolic', headline: '4', unit: 'of 4', band: 'met', calculator_version: '1.0',
    result: { outcome: 'met', criteria_met: 4, components: { waist: 'met', triglycerides: 'met', hdl: 'met', fastingGlucose: 'met', bloodPressure: 'met' } },
  });
  expect(out.saved.inputs.waistCm).toBe(102);
  const partial = run(P, E, { ...ms01, sex: 'female', waistCm: '85', bpSystolic: '118', bpDiastolic: '76', triglycerides: '', hdl: '', fastingGlucose: '' });
  expect(partial.saved.result).toMatchObject({ outcome: 'partial', criteria_met: 0 });
  expect(run(P, E, ms03).saved.band).toBe('not met');
});

test('Metabolic page: every error names its answer, the form keeps the answers, and the profile pre-fills only what it holds', () => {
  expect(run(P, E, { ...ms01, sex: '' }).error).toBe('Please check your sex.');
  expect(run(P, E, { ...ms01, waistCm: '' }).error).toBe('Please check your waist measurement.');
  expect(run(P, E, { ...ms01, bpSystolic: '', bpDiastolic: '' }).error).toBe('Please check the blood pressure (both numbers, or tick the medicine box).');
  expect(run(P, E, { ...ms01, bpDiastolic: '' }).error).toBe('Please check the blood pressure (both numbers, or tick the medicine box).');
  expect(run(P, E, { ...ms01, hdl: '50' }).error).toBe('Please check the HDL cholesterol.');
  expect(run(P, E, { ...ms01, triglycerides: '30' }).error).toBe('Please check the triglycerides.');
  expect(run(P, E, { ...ms01, age: '120' }).error).toBe('Please check your age.');
  const out = run(P, E, ms01);
  expect(out.form).toContain('name="lld_c[waistCm]" type="number" inputmode="decimal" value="102"');
  expect(out.form).toMatch(/id="lld-c-sex-male" name="lld_c\[sex\]" value="male" checked/);
  expect(out.form).toContain('<option value="White" selected>');
  expect(out.form).toContain('<input type="hidden" name="lld_c[bpMedicine]" value="no"><label');
  noTemplate(out.form);
  const d = JSON.parse(php(`echo json_encode(${P}::defaults(${arr({ member: 7, facts: { age: 48, sex: 'female', waist_cm: 84.5, situations: ['diabetes'], imperial: false } })}));`));
  expect(d).toMatchObject({ age: '48', sex: 'female', waistCm: '84.5', type2Diabetes: 'yes', eatingDisorder: 'no' });
  expect(d.triglycerides).toBeUndefined();
  expect(d.bpSystolic).toBeUndefined();
  expect(d.background).toBeUndefined();
  const visitor = JSON.parse(php(`echo json_encode(${P}::defaults(['member' => 0, 'facts' => null]));`));
  expect(visitor.age).toBeUndefined();
});
