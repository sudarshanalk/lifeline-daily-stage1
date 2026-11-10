// Fitness Age (P3-5A part 1, FR3-52): the engine is run in PHP CLI without WordPress against the frozen fixtures and
// the independent Python oracle; the wording is checked against the signed approval record.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-fitness-age.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const fixtures = json('fixtures/fitness-age/fitness-age.json').fixtures;
const approval = json('approvals/stage3/fitness-age.json');
const oracle = json('oracles/orc-11-fitness-age/results.json');

const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Fitness_Age::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
const close = (a, b) => typeof a === 'number' && typeof b === 'number' ? Math.abs(a - b) < 0.001 : JSON.stringify(a) === JSON.stringify(b);
const base = { age: 45, sex: 'male', waistCm: 94, rhr: 70, frequency: '2–3 times a week', intensity: 'sweating, heavier breathing', duration: '30–60 min', heartMedicine: 'no', heartCondition: 'no', pregnant: 'no' };

test('TC-FR3-52-AS-01', () => {
  // Every result matches the approved reference fixtures, and the oracle written independently from the sheet, for
  // every fixture. The imperial twin (FA-11) also gives the same result from inches alone.
  const results = compute(fixtures.map((f) => f.inputs));
  const problems = [];
  fixtures.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) if (!close(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    expect(orc, f.id).toBeTruthy();
    for (const [k, want] of Object.entries(orc.expected)) if (!close(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != oracle ${JSON.stringify(want)}`);
  });
  expect(problems).toEqual([]);
  expect(oracle.oracle_id).toBe('ORC-11');
  expect(oracle.results).toHaveLength(fixtures.length);
  const twin = fixtures.find((f) => f.inputs.waistIn !== undefined);
  const { waistCm, ...inchesOnly } = twin.inputs;
  const [fromInches] = compute([inchesOnly]);
  expect([fromInches.vo2, fromInches.fitnessAge]).toEqual([twin.expected.vo2, twin.expected.fitnessAge]);

  // The shown wording follows the result: the clamped fixtures say "better than typical for any age", a high clamp
  // the doctor line, and every result ends with the estimate line after the workout safety line.
  const clamped = results[fixtures.findIndex((f) => f.expected.clamped === 'low')];
  expect(clamped.messages).toContain('result.clampedLow');
  expect(clamped.messages).not.toContain('result.fitnessAge');
  const [high] = compute([{ ...base, sex: 'female', age: 80, waistCm: 120, rhr: 95, frequency: 'never' }]);
  expect(high.clamped).toBe('high');
  expect(high.fitnessAge).toBe(90);
  expect(high.messages).toContain('result.clampedHigh');
  for (const r of results.filter((x) => x.stop === null)) {
    expect(r.messages.at(-1)).toBe('always.estimate');
    expect(r.messages.indexOf('quoted.workoutSafety')).toBeLessThan(r.messages.indexOf('improve.rhr'));
  }
  // Out-of-range and non-scalar answers are refused without a PHP notice.
  const bad = compute([{ ...base, waistCm: 30 }, { ...base, rhr: 130 }, { ...base, frequency: 'sometimes' }, { ...base, sex: ['m'] }, { ...base, age: 120 }]);
  expect(bad.map((r) => r.error)).toEqual(['waist', 'rhr', 'frequency', 'sex', 'age']);

  // Below a plausible VO2 (10 mL/kg/min, shown 1 dp) no number is given: stop cannotEstimate, no signed wording yet.
  const [floor, justAbove] = compute([
    { ...base, sex: 'female', age: 99, waistCm: 250, rhr: 120, frequency: 'never', intensity: null, duration: null },
    { ...base, sex: 'female', age: 70, waistCm: 120, rhr: 100, frequency: 'never', intensity: null, duration: null },
  ]);
  expect(floor.stop).toBe('cannotEstimate');
  for (const k of ['vo2', 'fitnessAge', 'fitnessAgeRaw', 'paIndex']) expect(floor[k]).toBeNull();
  expect(floor.messages).toEqual(['stops.cannotEstimate']);
  // Its wording is CW-21, approved by the owner on 4 Nov 2026 (Owner_Questions 95, 96).
  expect(JSON.parse(php("echo json_encode(LLD_Members_Fitness_Age::wording(['stops.cannotEstimate']));"))).toEqual({ 'stops.cannotEstimate': "We can't give a reliable fitness estimate from these answers. Your doctor or a supervised fitness test can give a better picture." });
  expect(justAbove.stop).toBeNull();
  expect(justAbove.vo2).toBeGreaterThanOrEqual(10);
});

test('TC-FR3-52-AS-02', () => {
  // For a Premium member with a saved check, the result shows the change since the last check, on the shown values.
  const [up, down, none] = compute([
    { ...base, previousVo2: 40.2, previousDate: '2026-10-01' },
    { ...base, previousVo2: 43.3, previousDate: '2026-10-01' },
    base,
  ]);
  expect(up.change).toBeCloseTo(2.1, 5);
  expect(up.fill['+2.1']).toBe('+2.1');
  expect(up.fill.date).toBe('2026-10-01');
  expect(up.messages).toContain('premium.change');
  expect(down.change).toBeCloseTo(-1.0, 5);
  expect(down.fill['+2.1']).toBe('−1.0');
  expect(none.change).toBeNull();
  expect(none.messages).not.toContain('premium.change');
});

test('TC-FR3-52-AS-01 (safety): stops, the profile and the eating-disorder flag', () => {
  // Medicine yes / not sure, a heart condition, pregnancy, under 18 and "prefer not to say" give no fitness age; an
  // eating-disorder answer or profile flag hides the waist lever; the RHR notes show with the result (sheet 1.5).
  const out = compute([
    { ...base, heartMedicine: 'not sure' }, { ...base, heartCondition: 'yes' }, { ...base, sex: 'female', pregnant: 'notsure' },
    { ...base, age: 17 }, { ...base, sex: 'prefer not to say' }, { ...base, eatingDisorder: 'yes' }, { ...base, rhr: 38 }, base,
  ]);
  expect(out.slice(0, 5).map((r) => r.stop)).toEqual(['medicine', 'heartCondition', 'pregnancy', 'under18', 'sexNotSaid']);
  for (const r of out.slice(0, 5)) for (const k of ['vo2', 'fitnessAge', 'fitnessAgeRaw', 'paIndex']) expect(r[k]).toBeNull();
  expect(out[0].messages).toEqual(['safety.medicine']);
  expect(out[1].messages).toEqual(['safety.heartCondition']);
  expect(out[5].waistLeverHidden).toBe(true);
  expect(out[5].messages).not.toContain('improve.waist');
  expect(out[5].improve.waistPer5).toBeNull();
  expect(out[6].notes).toContain('safety.rhrUnder40');
  expect(out[6].messages).toContain('safety.rhrUnder40');
  expect(out[7].messages).toContain('improve.waist');
  // The member's health profile applies as well as the answers on the page, and never clears a page "yes".
  const prof = JSON.parse(php(`echo json_encode([
    LLD_Members_Fitness_Age::compute(LLD_Members_Fitness_Age::apply_profile(${phpArr(base)}, ['heart_rate_medicine'])),
    LLD_Members_Fitness_Age::compute(LLD_Members_Fitness_Age::apply_profile(${phpArr(base)}, ['heart_disease'])),
    LLD_Members_Fitness_Age::compute(LLD_Members_Fitness_Age::apply_profile(${phpArr(base)}, ['pregnancy'])),
    LLD_Members_Fitness_Age::compute(LLD_Members_Fitness_Age::apply_profile(${phpArr(base)}, ['eating_disorder'])),
    LLD_Members_Fitness_Age::compute(LLD_Members_Fitness_Age::apply_profile(${phpArr({ ...base, heartMedicine: 'yes' })}, [])),
  ]);`));
  expect(prof.map((r) => r.stop)).toEqual(['medicine', 'heartCondition', 'pregnancy', null, 'medicine']);
  expect(prof[3].waistLeverHidden).toBe(true);
  // The three safety answers are required: a missing one is refused, never read as "no".
  const missing = compute(['heartMedicine', 'heartCondition', 'pregnant'].map((k) => { const i = { ...base }; delete i[k]; return i; }));
  expect(missing.map((r) => r.error)).toEqual(['heartMedicine', 'heartCondition', 'pregnant']);
  // Yes / no answers read the same as true / false and "true" / "false"; "no" never counts as yes.
  const yn = compute([
    { ...base, heartMedicine: true }, { ...base, heartMedicine: 'TRUE' }, { ...base, heartMedicine: false }, { ...base, heartMedicine: 'false' },
    { ...base, eatingDisorder: 'no' }, { ...base, eatingDisorder: false }, { ...base, eatingDisorder: 'true' }, { ...base, pregnant: 1 },
  ]);
  expect(yn.map((r) => r.stop ?? r.error ?? null)).toEqual(['medicine', 'medicine', null, null, null, null, null, 'pregnant']);
  expect(yn.slice(4, 7).map((r) => r.waistLeverHidden)).toEqual([false, false, true]);
});

test('NFR3-14 (fitness age wording): every signed string hashes to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Fitness_Age::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  // Quoted Stage 2 wording is byte-identical to the built Stage 2 constant.
  const Q = JSON.parse(php('echo json_encode(LLD_Members_Fitness_Age::QUOTED);'));
  expect(readFileSync(resolve(plugin, 'includes/class-premium.php'), 'utf8')).toContain(`const WORKOUT_ADVICE = '${Q.workoutSafety}';`);
  // Every key a result returns has signed text, so members see only approved wording.
  const results = compute([...fixtures.map((f) => f.inputs), { ...base, previousVo2: 40 }]);
  const W = JSON.parse(php(`echo json_encode(LLD_Members_Fitness_Age::wording(${phpArr([...new Set(results.flatMap((r) => r.messages))])}));`));
  for (const r of results) for (const k of r.messages) expect(W[k], k).toBeTruthy();
  expect(results.some((r) => r.stop === 'cannotEstimate')).toBe(false); // no frozen fixture is below the floor
  // The stops and the 18-19 note carry the approved words CW-13 to CW-16 (3 November 2026).
  const cw = compute([{ ...base, age: 17 }, { ...base, pregnant: 'yes' }, { ...base, pregnant: 'notsure' }, { ...base, sex: 'prefer not to say' }, { ...base, age: 19 }, { ...base, age: 20 }]);
  expect(cw.slice(0, 4).map((r) => [r.stop, r.messages])).toEqual([
    ['under18', ['safety.under18']], ['pregnancy', ['safety.pregnancy']], ['pregnancy', ['safety.pregnancy']], ['sexNotSaid', ['safety.sexNotSaid']],
  ]);
  expect([cw[4].messages.includes('note.age18to19'), cw[5].messages.includes('note.age18to19')]).toEqual([true, false]);
  const CW = JSON.parse(php(`echo json_encode(LLD_Members_Fitness_Age::wording(${phpArr(['safety.under18', 'safety.pregnancy', 'safety.sexNotSaid', 'note.age18to19'])}));`));
  for (const k of ['safety.under18', 'safety.pregnancy', 'safety.sexNotSaid', 'note.age18to19']) expect(CW[k], k).toBe(M[k]);
  // The engine is pure: no hooks, no database.
  expect(readFileSync(engine, 'utf8')).not.toMatch(/add_action|add_filter|\$wpdb|get_option|wp_remote/);
});

/** A PHP array literal from JSON. */
function phpArr(v) {
  return `json_decode(${JSON.stringify(JSON.stringify(v))}, true)`;
}
