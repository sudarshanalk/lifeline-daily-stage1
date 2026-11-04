// Fitness age page (P3-5A part 1, FR3-52): the page class's form, inputs and result, run in PHP CLI with the real
// engine. The live journey (sign in, check, save, history) is in tests/stage3/e2e/screens/calculators.spec.mjs.
import { expect, test } from 'vitest';
import { run, textOf, php, arr } from './tools/calc-page-php.mjs';

const P = 'LLD_Members_Calc_Page_Fitness_Age';
const E = 'LLD_Members_Fitness_Age';
// FA-03: man, 45, waist 105, resting 78, less than once a week: VO2 36.1, fitness age 66.
const base = { age: '45', sex: 'male', waistCm: '105', rhr: '78', frequency: 'less than once a week', intensity: '', duration: '', heartMedicine: 'no', heartCondition: 'no', pregnant: 'no' };
// FA-01: the reference man.
const ref = { ...base, waistCm: '94', rhr: '70', frequency: '2–3 times a week', intensity: 'sweating, heavier breathing', duration: '30–60 min' };
const signed = (key) => JSON.parse(php(`echo json_encode(${E}::wording(['${key}']));`))[key];
const noTemplate = (html) => expect(html).not.toMatch(/\{|\}|\|/);

test('TC-FR3-52-AS-01 (page): the result shows the fixture VO2 and fitness age in the signed wording, with the levers filled', () => {
  const out = run(P, E, base);
  expect(out.result.vo2).toBe(36.1);
  expect(out.result.fitnessAge).toBe(66);
  const text = textOf(out.html);
  expect(text).toContain('Your estimated fitness level (VO₂max) is about 36.1 mL/kg/min');
  expect(text).toContain("That's typical of a 66-year-old");
  expect(text).toContain("Going from less than once a week to 'almost every day, sweating, 30–60 minutes' would raise your estimate by about " + out.result.fill.delta + ' points');
  expect(text).toContain('Regular aerobic exercise lowers resting pulse; each 10 bpm lower adds about 1.6 points');
  expect(text).toContain('Each 5 cm off the waist adds about 1.8 points');
  expect(text).toContain(signed('always.estimate'));
  // The Stage 2 workout safety line comes before any exercise suggestion (sheet 1.5).
  expect(out.html.indexOf('data-lld-key="quoted.workoutSafety"')).toBeGreaterThan(-1);
  expect(out.html.indexOf('data-lld-key="quoted.workoutSafety"')).toBeLessThan(out.html.indexOf('data-lld-key="improve.activity"'));
  expect(out.html).toContain('data-lld-vo2="36.1"');
  noTemplate(out.html);
  // The reference man (FA-01) is 45; the full activity answers fill {current}.
  const r = run(P, E, ref);
  expect(r.result.fitnessAge).toBe(45);
  expect(textOf(r.html)).toContain('Going from 2–3 times a week, sweating, heavier breathing, 30–60 min to');
  noTemplate(r.html);
});

test('TC-FR3-52-AS-01 (page): clamped ages show the signed clamped lines, and pulse notes come first', () => {
  const low = run(P, E, { ...base, age: '30', sex: 'female', waistCm: '72', rhr: '55', frequency: 'almost every day', intensity: 'near exhaustion', duration: 'over 1 hour' });
  expect(low.result.clamped).toBe('low');
  expect(textOf(low.html)).toContain('Better than typical for any age');
  expect(textOf(low.html)).not.toContain("That's typical of");
  expect(low.html).not.toContain('data-lld-key="improve.activity"'); // already at the target
  noTemplate(low.html);
  const fast = run(P, E, { ...ref, sex: 'female', waistCm: '80', rhr: '105' });
  expect(fast.result.fitnessAge).toBe(61);
  expect(fast.html).toContain('data-lld-key="safety.rhrOver100"');
  expect(fast.html.indexOf('safety.rhrOver100')).toBeLessThan(fast.html.indexOf('lld-calc-headline'));
});

test('TC-FR3-52-AS-01 (page): a stop shows its signed wording and no number', () => {
  const cases = [
    [{ ...ref, heartMedicine: 'yes' }, 'medicine', 'safety.medicine'],
    [{ ...ref, heartMedicine: 'notsure' }, 'medicine', 'safety.medicine'],
    [{ ...ref, heartCondition: 'yes' }, 'heartCondition', 'safety.heartCondition'],
    [{ ...ref, sex: 'female', pregnant: 'notsure' }, 'pregnancy', 'safety.pregnancy'],
    [{ ...ref, sex: 'pnts' }, 'sexNotSaid', 'safety.sexNotSaid'],
    [{ ...ref, age: '16' }, 'under18', 'safety.under18'],
  ];
  for (const [values, stop, key] of cases) {
    const out = run(P, E, values);
    expect(out.result.stop).toBe(stop);
    expect(textOf(out.html)).toBe(signed(key));
    expect(out.html).toContain(`data-lld-stop="${stop}"`);
    expect(textOf(out.html)).not.toMatch(/mL\/kg\/min|\d/);
    expect(out.html).not.toContain('lld-calc-headline');
    expect(out.saved).toBeNull();
  }
});

test('Fitness age page: answers far outside the model give the no-estimate wording and no number', () => {
  const out = run(P, E, { age: '99', sex: 'female', waistCm: '250', rhr: '100', frequency: 'never', heartMedicine: 'no', heartCondition: 'no', pregnant: 'no' });
  expect(out.result.stop).toBe('cannotEstimate');
  expect(textOf(out.html)).toBe(signed('stops.cannotEstimate'));
  expect(textOf(out.html)).not.toMatch(/\d/);
  expect(out.html).not.toContain('lld-calc-headline');
  expect(out.saved).toBeNull();
});

test('TC-FR3-52-AS-02 (page): a signed-in Premium member sees the change since the last saved check', () => {
  const html = JSON.parse(php(`
    class LLD_Members_Stage3_API { public static $asked = 0; public static function page_saved_results($c) { self::$asked++;
      return ['rows' => [['calculator' => $c, 'headline' => 34.0, 'saved_at' => '2026-10-01 09:00:00', 'result' => json_encode(['vo2' => 34.0, 'fitness_age' => 74])]]]; } }
    $values = ${arr(base)};
    $prem = ${P}::inputs($values, ['member' => 7, 'premium' => true, 'facts' => null, 'health' => true, 'imperial' => false]);
    $free = ${P}::inputs($values, ['member' => 7, 'premium' => false, 'facts' => null, 'health' => true, 'imperial' => false]);
    $visitor = ${P}::inputs($values, ['member' => 0, 'premium' => true, 'facts' => null, 'health' => false, 'imperial' => false]);
    $r = ${E}::compute($prem); $r['wording'] = ${E}::wording($r['messages'], $r);
    $f = ${E}::compute($free); $f['wording'] = ${E}::wording($f['messages'], $f);
    echo json_encode(['prem' => $prem, 'free' => $free, 'visitor' => $visitor, 'html' => ${P}::result($r, []), 'freeHtml' => ${P}::result($f, []),
      'saved' => ${P}::saved($r, $prem), 'asked' => LLD_Members_Stage3_API::$asked]);
  `));
  expect(html.prem.previousVo2).toBe(34);
  expect(html.prem.previousDate).toBe('1 Oct 2026');
  expect(html.free.previousVo2).toBeUndefined();
  expect(html.visitor.previousVo2).toBeUndefined();
  expect(html.asked).toBe(1); // looked up once per request
  expect(textOf(html.html)).toContain('+2.1 points since 1 Oct 2026');
  expect(html.html).toContain('data-lld-key="premium.change"');
  expect(html.freeHtml).not.toContain('premium.change');
  expect(html.saved.inputs.previousVo2).toBeUndefined(); // the previous result is not sealed into the new row
  noTemplate(html.html);
});

test('TC-FR3-55-AS-01 (fitness age page): the saved row is the VO2 and fitness age, with the inputs', () => {
  const out = run(P, E, base);
  expect(out.saved).toMatchObject({ calculator: 'fitness_age', headline: '36.1', unit: 'mL/kg/min', band: 'fitness age 66', calculator_version: '1.0', result: { vo2: 36.1, fitness_age: 66, clamped: null } });
  expect(out.saved.inputs.rhr).toBe(78);
});

test('Fitness age page: every error names its answer, the form keeps the answers, and the hint is the signed one', () => {
  expect(run(P, E, { ...base, sex: '' }).error).toBe('Please check your sex.');
  expect(run(P, E, { ...base, waistCm: '' }).error).toBe('Please check your waist.');
  expect(run(P, E, { ...base, rhr: '20' }).error).toBe('Please check your resting heart rate.');
  expect(run(P, E, { ...base, frequency: '' }).error).toBe('Please check how often you exercise.');
  expect(run(P, E, { ...base, frequency: 'once a week' }).error).toBe('Please check how hard you exercise.');
  expect(run(P, E, { ...base, frequency: 'once a week', intensity: 'near exhaustion' }).error).toBe('Please check how long you exercise each time.');
  expect(run(P, E, { ...base, heartMedicine: '' }).error).toBe('Please check the heart medicine answer.');
  expect(run(P, E, { ...base, heartCondition: '' }).error).toBe('Please check the heart condition answer.');
  expect(run(P, E, { ...base, pregnant: '' }).error).toBe('Please check the pregnancy answer.');
  expect(run(P, E, { ...base, age: '' }).error).toBe('Please check your age.');
  const out = run(P, E, ref);
  expect(out.form).toContain('name="lld_c[age]" type="number" inputmode="decimal" value="45"');
  expect(out.form).toMatch(/name="lld_c\[frequency\]" value="2–3 times a week" checked/);
  expect(out.form).toMatch(/name="lld_c\[heartMedicine\]" value="no" checked/);
  expect(textOf(out.form)).toContain('Taken sitting quietly for 5 minutes, before caffeine');
  expect(out.form).not.toMatch(/\{|\}|\|/);
  // Pre-fill only from the member's facts: age, sex and waist; never a safety answer.
  const d = JSON.parse(php(`echo json_encode(${P}::defaults(['member' => 3, 'facts' => ['age' => 52, 'sex' => 'female', 'waist_cm' => 81.4, 'situations' => ['pregnancy']], 'health' => true, 'imperial' => false, 'premium' => false]));`));
  expect(d).toEqual({ heartMedicine: '', heartCondition: '', pregnant: '', age: '52', sex: 'female', waistCm: '81' });
});
