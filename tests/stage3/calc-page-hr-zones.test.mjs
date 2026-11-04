// Heart-rate training zones page (P3-5A part 2, FR3-53): the page class's form, inputs and result, run in PHP CLI
// with the real engine.
import { expect, test } from 'vitest';
import { run, textOf, php } from './tools/calc-page-php.mjs';

const P = 'LLD_Members_Calc_Page_HR_Zones';
const E = 'LLD_Members_HR_Zones';
const base = { age: '40', rhr: '60', measuredHrMax: '', heartMedicine: 'no', heartCondition: 'no', pregnant: 'no', exerciseHistory: '', bloodPressure: '' };
const signed = (key) => JSON.parse(php(`echo json_encode(${E}::wording(['${key}']));`))[key];
const zoneRow = (html, zone) => textOf(html.match(new RegExp(`<tr data-lld-zone="${zone}"[\\s\\S]*?</tr>`))[0]);

test('TC-FR3-53-AS-01 (page): the three zones show the engine bpm with the signed talk-test lines', () => {
  // HZ-01: heart-rate reserve.
  const out = run(P, E, base);
  expect(out.result.method).toBe('hrr');
  expect(zoneRow(out.html, 'easy')).toBe(`Easy 96–107 ${signed('talk.easy')}`);
  expect(zoneRow(out.html, 'moderate')).toBe(`Moderate 108–131 ${signed('talk.moderate')}`);
  expect(zoneRow(out.html, 'hard')).toBe(`Hard 132–167 ${signed('talk.hard')}`);
  const text = textOf(out.html);
  expect(text).toContain(signed('result.weekly'));
  expect(text).toContain(signed('result.hrmax').replace('{HRmax}', '180'));
  expect(text).toContain(signed('quoted.workoutSafety'));
  expect(text).not.toContain(signed('quoted.intenseExercise'));
  // HZ-02: % of maximum without a resting heart rate.
  const pct = run(P, E, { ...base, rhr: '' });
  expect(pct.inputs).not.toHaveProperty('rhr');
  expect(pct.result.method).toBe('pctHrMax');
  expect(zoneRow(pct.html, 'easy')).toContain('103–113');
  expect(zoneRow(pct.html, 'moderate')).toContain('115–137');
  expect(zoneRow(pct.html, 'hard')).toContain('139–171');
  // HZ-09: a measured maximum overrides the estimate.
  const measured = run(P, E, { ...base, measuredHrMax: '190' });
  expect(measured.result.hrMax).toBe(190);
  expect(zoneRow(measured.html, 'hard')).toContain('138–176');
  expect(textOf(measured.html)).toContain('about 190.');
  for (const o of [out, pct, measured]) expect(o.html).not.toMatch(/\{|\}|\|/);
});

test('TC-FR3-53-AS-02 (page): heart-rate-lowering medicine shows the signed talk-to-your-doctor wording and no zones', () => {
  for (const heartMedicine of ['yes', 'notsure']) {
    const out = run(P, E, { ...base, heartMedicine });
    expect(out.result.stop).toBe('medicine');
    expect(textOf(out.html)).toBe(signed('safety.medicine'));
    expect(out.html).toContain('data-lld-stop="medicine"');
    expect(out.html).not.toContain('lld-calc-zones');
    expect(out.html).not.toMatch(/\d+–\d+/);
    expect(out.saved).toBeNull();
  }
});

test('Heart-rate zones page: heart condition, pregnancy and under 18 stop with their signed wording and no zones', () => {
  for (const [values, stop, key] of [
    [{ heartCondition: 'yes' }, 'heartCondition', 'safety.heartCondition'],
    [{ pregnant: 'notsure' }, 'pregnancy', 'safety.pregnancy'],
    [{ age: '17' }, 'under18', 'safety.under18'],
  ]) {
    const out = run(P, E, { ...base, ...values });
    expect(out.result.stop).toBe(stop);
    expect(textOf(out.html)).toBe(signed(key));
    expect(out.html).toContain(`data-lld-key="${key}"`);
    expect(out.html).not.toContain('lld-calc-zones');
    expect(out.saved).toBeNull();
    expect(out.html).not.toMatch(/\{|\}|\|/);
  }
});

test('Heart-rate zones page: a measured maximum less than 30 above resting is refused with the signed wording', () => {
  const out = run(P, E, { ...base, measuredHrMax: '85' });
  expect(out.result.stop).toBe('refused');
  expect(textOf(out.html)).toBe(signed('refused.reserve'));
  expect(out.html).toContain('data-lld-stop="refused"');
  expect(out.html).not.toContain('lld-calc-zones');
  expect(out.saved).toBeNull();
});

test('Heart-rate zones page: over 75 with no exercise history, or high blood pressure, shows the quoted note first', () => {
  const old = run(P, E, { ...base, age: '80', rhr: '65', exerciseHistory: 'no' });
  expect(old.result.notes).toEqual(['over75NoHistory']);
  expect(old.html.indexOf(`data-lld-key="quoted.intenseExercise"`)).toBeGreaterThan(-1);
  expect(old.html.indexOf('quoted.intenseExercise')).toBeLessThan(old.html.indexOf('lld-calc-zones'));
  expect(textOf(old.html)).toContain(signed('quoted.intenseExercise'));
  expect(zoneRow(old.html, 'easy')).toContain('91–99'); // HZ-06
  const bp = run(P, E, { ...base, bloodPressure: 'yes' });
  expect(bp.result.notes).toEqual(['bloodPressure']);
  expect(textOf(bp.html).split(signed('quoted.intenseExercise')).length).toBe(2); // shown once
  // Over 75 with exercise history: no note.
  expect(run(P, E, { ...base, age: '80', exerciseHistory: 'yes' }).result.notes).toEqual([]);
});

test('Heart-rate zones page: every error names its answer, and a blank safety answer is refused, never read as no', () => {
  expect(run(P, E, { ...base, age: '' }).error).toBe('Please check your age.');
  expect(run(P, E, { ...base, rhr: '150' }).error).toBe('Please check your resting heart rate.');
  expect(run(P, E, { ...base, rhr: 'abc' }).error).toBe('Please check your resting heart rate.');
  expect(run(P, E, { ...base, rhr: '', measuredHrMax: '240' }).error).toBe('Please check your measured maximum heart rate.');
  expect(run(P, E, { ...base, heartMedicine: '' }).error).toBe('Please check the heart medicine answer.');
  expect(run(P, E, { ...base, heartCondition: '' }).error).toBe('Please check the heart condition answer.');
  expect(run(P, E, { ...base, pregnant: '' }).error).toBe('Please check the pregnancy answer.');
  for (const f of ['exerciseHistory', 'bloodPressure']) {
    expect(php(`echo ${P}::error_text('${f}');`)).toMatch(/^Please check the .+ answer\.$/);
  }
});

test('Heart-rate zones page: the form keeps the answers, pre-fills only age and profile answers, and the saved row holds the ranges', () => {
  const out = run(P, E, base);
  expect(out.form).toContain('name="lld_c[age]" type="number" inputmode="decimal" value="40"');
  expect(out.form).toMatch(/id="lld-c-heartMedicine-no" name="lld_c\[heartMedicine\]" value="no" checked/);
  const d = JSON.parse(php(`echo json_encode(${P}::defaults(['member' => 1, 'facts' => ['age' => 52, 'situations' => ['heart_rate_medicine']]]));`));
  expect(d).toMatchObject({ age: '52', heartMedicine: 'yes', heartCondition: '', pregnant: '', bloodPressure: '' });
  expect(d).not.toHaveProperty('rhr');
  const visitor = JSON.parse(php(`echo json_encode(${P}::defaults(['member' => 0, 'facts' => null]));`));
  expect(visitor).not.toHaveProperty('age');
  expect(out.saved).toMatchObject({ calculator: 'hr_zones', headline: '180', unit: 'bpm', band: 'Easy 96–107, Moderate 108–131, Hard 132–167', result: { hrMax: 180, method: 'hrr', easy: [96, 107] } });
});
