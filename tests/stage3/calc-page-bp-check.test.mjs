// Blood pressure reading check page (P3-58, FR3-50): the page class's form, inputs and result, run in PHP CLI with the
// real engine and safety layer. The 7-day average is run with readings supplied as the members' API supplies them.
import { expect, test } from 'vitest';
import { run, textOf, php, arr, plugin } from './tools/calc-page-php.mjs';

const P = 'LLD_Members_Calc_Page_BP_Check';
const E = 'LLD_Members_BP_Check';
const base = { mode: 'single', systolic: '142', diastolic: '91', pulse: '', age: '55', pregnant: 'no', medicine: 'no', dizzy: 'no' };
const signed = (key, result = {}) => JSON.parse(php(`echo json_encode(${E}::wording(['${key}'], ${arr(result)}));`))[key];
const noTemplate = (html) => expect(html).not.toMatch(/\{|\}|\|/);

/** The page result for a member's 7-day check, with the readings the members' API would add. */
function home(values, readings, today = '2026-11-04') {
  return JSON.parse(php(`
    $ctx = ['member' => 7, 'facts' => null, 'health' => true, 'imperial' => false, 'premium' => false];
    $in = ${P}::inputs(${arr(values)}, $ctx);
    $r = ${E}::compute(array_merge($in, ['today' => '${today}', 'readings' => ${arr(readings)}]));
    $r['wording'] = ${E}::wording($r['messages'], $r);
    echo json_encode(['inputs' => $in, 'result' => $r, 'html' => ${P}::result($r, $ctx), 'saved' => ${P}::saved($r, $in)]);
  `));
}

test('TC-FR3-50-AS-01 (page): a single reading shows its category headline and body in the signed words', () => {
  const out = run(P, E, base);
  expect(out.result.category).toBe('grade1');
  const text = textOf(out.html);
  expect(text).toContain('142/91 mmHg');
  expect(text).toContain(signed('category.grade1.headline'));
  expect(text).toContain(signed('category.grade1.body'));
  expect(out.html).toContain('data-lld-category="grade1"');
  noTemplate(out.html);
  // Each office category, and the low side of normal with its note.
  for (const [sys, dia, cat] of [['118', '76', 'normal'], ['134', '82', 'high-normal'], ['165', '95', 'grade2']]) {
    const r = run(P, E, { ...base, systolic: sys, diastolic: dia });
    expect(r.result.category).toBe(cat);
    expect(textOf(r.html)).toContain(signed(`category.${cat}.body`));
  }
  const low = run(P, E, { ...base, systolic: '86', diastolic: '58' });
  expect(textOf(low.html)).toContain(signed('category.normal-low.headline'));
  expect(textOf(low.html)).toContain(signed('category.normal-low.body'));
  expect(low.html.match(/data-lld-key="category\.normal-low\.body"/g)).toHaveLength(1);
});

test('TC-FR3-50-AS-02 (page): the urgent alert comes first, never dismissible, with its link; pregnancy shows no category', () => {
  const urgent = run(P, E, { ...base, systolic: '185', diastolic: '100' });
  expect(urgent.result.urgent).toBe(true);
  expect(urgent.html.indexOf('data-lld-key="bp-check.urgent.alert"')).toBe(urgent.html.indexOf('data-lld-key'));
  expect(urgent.html.indexOf('lld-calc-urgent')).toBeLessThan(urgent.html.indexOf('lld-calc-headline'));
  expect(urgent.html).toContain('role="alert"');
  expect(urgent.html).toContain('<a href="https://example.test/safety/">When to seek urgent care</a>');
  expect(textOf(urgent.html)).toContain('Your reading is very high.');
  // Pregnancy: the urgent and same-day lines, or the neutral line, and no category.
  const pu = run(P, E, { ...base, pregnant: 'yes', systolic: '162', diastolic: '100' });
  expect(pu.html).toContain('data-lld-key="bp-check.pregnancy.urgent"');
  expect(pu.html).toContain('role="alert"');
  const ps = run(P, E, { ...base, pregnant: 'notsure', systolic: '145', diastolic: '85' });
  expect(ps.html).toContain('data-lld-key="bp-check.pregnancy.same-day"');
  expect(ps.html).toContain('role="status"');
  const pn = run(P, E, { ...base, pregnant: 'yes', systolic: '118', diastolic: '74' });
  expect(textOf(pn.html)).toContain(signed('pregnancy.neutral'));
  for (const r of [pu, ps, pn]) {
    expect(r.result.category).toBeNull();
    expect(r.html).not.toMatch(/data-lld-key="category\./);
    expect(r.saved).toBeNull(); // no category to save in pregnancy (D3-19)
  }
  // The dizzy tick on a low reading puts its line first.
  const dizzy = run(P, E, { ...base, systolic: '86', diastolic: '58', dizzy: 'yes' });
  expect(dizzy.html.indexOf('data-lld-key="bp-check.low.dizzy"')).toBeLessThan(dizzy.html.indexOf('lld-calc-headline'));
  expect(textOf(dizzy.html)).toContain(signed('low.dizzy'));
});

test('TC-FR3-50-AS-03 (page): the 7-day average shows the average, the readings used, or the 3-day message', () => {
  const values = { ...base, mode: '7-day', systolic: '', diastolic: '' };
  const readings = [
    { date: '2026-11-01', systolic: 138, diastolic: 86 }, { date: '2026-11-02', systolic: 140, diastolic: 88 },
    { date: '2026-11-02', systolic: 136, diastolic: 84 }, { date: '2026-11-04', systolic: 134, diastolic: 82 },
  ];
  const out = home(values, readings);
  expect(out.inputs).not.toHaveProperty('systolic');
  expect(out.result.category).toBe('above');
  const text = textOf(out.html);
  expect(text).toContain(`${out.result.average.systolic}/${out.result.average.diastolic} mmHg`);
  expect(text).toContain('7-day average of 4 readings on 3 days.');
  expect(text).toContain(signed('home.above.headline'));
  expect(text).toContain(signed('home.above.body'));
  expect(text).toContain('2026-11-02: 136/84 mmHg');
  noTemplate(out.html);
  expect(out.saved).toMatchObject({ calculator: 'bp_check', headline: `${out.result.average.systolic}/${out.result.average.diastolic}`, unit: 'mmHg', band: 'above' });
  // Fewer than 3 days: the message, no average and nothing to save.
  const few = home(values, readings.slice(0, 2));
  expect(textOf(few.html)).toContain(signed('home.min-days'));
  expect(few.html).not.toContain('data-lld-average');
  expect(few.saved).toBeNull();
  // A visitor is not offered the 7-day average, and the API's refusal reads plainly.
  expect(run(P, E, base).form).not.toContain('lld_c[mode]');
  expect(run(P, E, base, { member: 7 }).form).toContain('value="7-day"');
  expect(JSON.parse(php(`echo json_encode(${P}::error_text('mode'));`))).toBe('Please check the type of check. The 7-day average is for signed-in members.');
});

test('Blood pressure page: an impossible reading is refused with no number and nothing to save', () => {
  const out = run(P, E, { ...base, systolic: '400', diastolic: '20' });
  expect(out.result.refused).toBe(true);
  expect(textOf(out.html)).toBe(signed('refused.impossible'));
  expect(out.html).toContain('data-lld-stop="refused"');
  expect(out.html).not.toMatch(/400|mmHg|data-lld-category/);
  expect(out.saved).toBeNull();
});

test('Blood pressure page: the notes (pulse with its choice made, 80 and over, medicine) and no template left', () => {
  const out = run(P, E, { ...base, pulse: '130', age: '82', medicine: 'yes' });
  const text = textOf(out.html);
  expect(text).toContain('Your pulse is high for a resting reading.');
  expect(text).toContain(signed('age80.note'));
  expect(text).toContain(signed('medicine.note'));
  noTemplate(out.html);
  const slow = run(P, E, { ...base, pulse: '35' });
  expect(textOf(slow.html)).toContain('Your pulse is low for a resting reading.');
  noTemplate(slow.html);
});

test('TC-FR3-55-AS-01 (blood pressure page): the saved row is the two numbers, mmHg and the category, with the inputs', () => {
  const out = run(P, E, base);
  expect(out.saved).toMatchObject({ calculator: 'bp_check', headline: '142/91', unit: 'mmHg', band: 'grade1', result: { systolic: 142, diastolic: 91, category: 'grade1', mode: 'single' } });
  expect(out.saved.inputs.systolic).toBe(142);
  // The row compares as P3-5B expects.
  const saved = JSON.stringify(`${plugin}/includes/stage3/class-saved-results.php`.split('\\').join('/'));
  const cmp = JSON.parse(php(`require_once ${saved}; echo json_encode(LLD_Members_Saved_Results::compare(['calculator' => 'bp_check', 'plan' => 'premium', 'results' => [${arr(out.saved.result)}, ['systolic' => 128, 'diastolic' => 80, 'unit' => 'mmHg']]]));`));
  expect(cmp.comparable).toBe(true);
  expect(cmp.wording).toBe('Better: 14/11 lower');
  expect(cmp.category_from).toBe('Grade 1');
});

test('Blood pressure page: every error names its answer, a blank pregnancy answer is asked for, and the form keeps the answers', () => {
  expect(run(P, E, { ...base, systolic: '' }).error).toBe('Please check the top number.');
  expect(run(P, E, { ...base, diastolic: 'x' }).error).toBe('Please check the bottom number.');
  expect(run(P, E, { ...base, pulse: 'fast' }).error).toBe('Please check the pulse.');
  expect(run(P, E, { ...base, age: '12' }).error).toBe('Please check your age.');
  expect(run(P, E, { ...base, pregnant: '' }).error).toBe('Please check the pregnancy answer.');
  expect(run(P, E, { ...base, medicine: 'maybe' }).error).toBe('Please check the blood pressure medicine answer.');
  expect(run(P, E, { ...base, dizzy: 'maybe' }).error).toBe('Please check the dizzy or faint answer.');
  const out = run(P, E, { ...base, dizzy: 'yes' });
  expect(out.form).toContain('name="lld_c[systolic]" type="number" inputmode="decimal" value="142"');
  expect(out.form).toMatch(/id="lld-c-pregnant-no" name="lld_c\[pregnant\]" value="no" checked/);
  expect(out.form).toMatch(/value="yes" checked data-lld-tick="dizzy"/);
  // Pre-fill: age and the member's own pregnancy answer only.
  const d = JSON.parse(php(`echo json_encode(${P}::defaults(['member' => 7, 'facts' => ['age' => 61, 'situations' => ['pregnancy']]]));`));
  expect(d).toMatchObject({ age: '61', pregnant: 'yes', medicine: '', dizzy: '' });
  expect(d).not.toHaveProperty('systolic');
});
