// Heart attack and stroke risk check page (P3-57, FR3-49): the page class's form, inputs and result, run in PHP CLI
// with the real engine. The live journey (sign in, check, save, history) is in tests/stage3/e2e/screens/calculators.spec.mjs.
import { expect, test } from 'vitest';
import { run, textOf, php } from './tools/calc-page-php.mjs';

const P = 'LLD_Members_Calc_Page_Heart_Risk';
const E = 'LLD_Members_Heart_Risk';
const base = { age: '55', sex: 'male', country: 'United Kingdom', smoke: 'yes', sbp: '150', diabetes: 'no', tc: '6.2', tcUnit: 'mmol/L', heightCm: '178', weightKg: '88', cvd: 'no', preg: 'no', eat: 'no' };
const signed = (key) => JSON.parse(php(`echo json_encode(${E}::wording(['${key}']));`))[key];

test('TC-FR3-49-AS-01 (page): the result shows the engine figure and band in the signed wording', () => {
  const out = run(P, E, base);
  expect(out.result.risk).toBe(17);
  const text = textOf(out.html);
  expect(text).toContain('Your estimated chance of a heart attack or stroke in the next 10 years is about 17 %');
  expect(text).toContain(signed('band.high'));
  expect(text).toContain('For comparison, 17 in 100 people like you would be expected');
  expect(text).toContain(signed('model.lab'));
  expect(out.html).toContain('data-lld-risk="17"');
  // Without a cholesterol value the page uses the "without a blood test" model.
  const nolab = run(P, E, { ...base, tc: '' });
  expect(nolab.inputs.tc).toBe('dontknow');
  expect(nolab.result.model).toBe('nolab');
  expect(textOf(nolab.html)).toContain(signed('model.nolab'));
});

test('TC-FR3-49-AS-02 (page): the result names the change that lowers the risk most, with the template choice made', () => {
  const out = run(P, E, base);
  const line = textOf(out.html.match(/<p class="lld-calc-whatif"[^>]*>[\s\S]*?<\/p>/)[0]);
  expect(line).toBe('The change that would lower your risk most: smoking. Stopping smoking would take it to about 11 %.');
  const bp = run(P, E, { ...base, smoke: 'no' });
  expect(textOf(bp.html.match(/<p class="lld-calc-whatif"[^>]*>[\s\S]*?<\/p>/)[0])).toBe('The change that would lower your risk most: blood pressure. Bringing your blood pressure under 140 would take it to about ' + bp.result.whatif[0].risk + ' %.');
  expect(bp.html).not.toMatch(/\{|\}|\|/); // no template braces or choices left on the page
});

test('TC-FR3-49-AS-03 (page): outside 40 to 74 the page shows the "not suitable" wording and no number', () => {
  for (const [age, key] of [['35', 'stops.under40'], ['80', 'stops.over74']]) {
    const out = run(P, E, { ...base, age });
    expect(out.result.stop).toBe(key.slice(6));
    expect(textOf(out.html)).toBe(signed(key));
    expect(out.html).not.toMatch(/\d+ %/);
    expect(out.saved).toBeNull(); // nothing to save
  }
  // A blood pressure at the urgent level puts the frozen alert first, with its link, before the result.
  const urgent = run(P, E, { ...base, sbp: '185' });
  expect(urgent.html.indexOf('lld-calc-urgent')).toBeLessThan(urgent.html.indexOf('lld-calc-headline'));
  expect(urgent.html).toContain('role="alert"');
  expect(urgent.html).toContain('<a href="https://example.test/safety/">When to seek urgent care</a>');
  // Pregnancy at 140 to 159: the same-day line, as a caution, before the stop (Owner_Questions 98).
  const preg = run(P, E, { ...base, preg: 'yes', sex: 'female', sbp: '150' });
  expect(preg.html).toContain('data-lld-key="bp-check.pregnancy.same-day"');
  expect(preg.html).toContain('role="status"');
  expect(preg.html).toContain('data-lld-stop="pregnancy"');
});

test('TC-FR3-55-AS-01 (heart risk page): the saved row is the headline, band and model, with the inputs', () => {
  const out = run(P, E, base);
  expect(out.saved).toMatchObject({ calculator: 'heart_risk', headline: '17', unit: '%', band: 'high', result: { risk: 17, model: 'lab', region: 'Western Europe' } });
  expect(out.saved.inputs.sbp).toBe(150);
});

test('Heart risk page: every label is plain, every error names its answer, and the form keeps the answers', () => {
  const out = run(P, E, base);
  expect(out.form).toContain('name="lld_c[age]" type="number" inputmode="decimal" value="55"');
  expect(out.form).toMatch(/id="lld-c-sex-male" name="lld_c\[sex\]" value="male" checked/);
  expect(out.form).toContain('<option value="United Kingdom" selected>');
  expect(run(P, E, { ...base, sex: '' }).error).toBe('Please check your sex.');
  expect(run(P, E, { ...base, country: '' }).error).toBe('Please check your country.');
  // A 0 % cell reads "less than 1 %" (CW-22), never "about 0 %".
  const zero = JSON.parse(php(`
    foreach ([40, 42] as $age) { foreach ([110, 125] as $sbp) { foreach ([3.5, 4.5] as $tc) {
      $v = ['age' => (string) $age, 'sex' => 'female', 'country' => 'Spain', 'smoke' => 'no', 'sbp' => (string) $sbp, 'diabetes' => 'no', 'tc' => (string) $tc, 'tcUnit' => 'mmol/L', 'cvd' => 'no', 'preg' => 'no', 'eat' => 'no'];
      $r = ${E}::compute(${P}::inputs($v, []));
      if (0 === ($r['risk'] ?? null)) { $r['wording'] = ${E}::wording($r['messages'], $r); echo json_encode(${P}::result($r, [])); exit; }
    } } }
    echo json_encode(null);
  `));
  expect(zero, 'a 0 % cell').not.toBeNull();
  expect(textOf(zero)).toContain('is less than 1 %');
  expect(textOf(zero)).toContain('fewer than 1 in 100');
  expect(textOf(zero)).not.toMatch(/about 0 %|\b0 in 100/);
});
