// Heart-Rate Training Zones (P3-5A part 2, FR3-53): the engine is run in PHP CLI without WordPress against the frozen
// fixtures and the independent Python oracle; the wording is checked against the signed approval record.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-hr-zones.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const fixtures = json('fixtures/hr-zones/hr-zones.json').fixtures;
const approval = json('approvals/stage3/hr-zones.json');
const oracle = json('oracles/orc-12-hr-zones/results.json');
const phpArr = (v) => `json_decode(${JSON.stringify(JSON.stringify(v))}, true)`;

const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_HR_Zones::compute($i); }, ${phpArr(inputs)}));`));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const base = { age: 40, rhr: 60, measuredHrMax: null, heartMedicine: 'no', heartCondition: 'no', pregnant: 'no' };

test('TC-FR3-53-AS-01', () => {
  // Every result matches the approved reference fixtures and the oracle written independently from the sheet.
  const results = compute(fixtures.map((f) => f.inputs));
  const problems = [];
  fixtures.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) if (!same(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    expect(orc, f.id).toBeTruthy();
    for (const [k, want] of Object.entries(orc.expected)) if (!same(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != oracle ${JSON.stringify(want)}`);
  });
  expect(problems).toEqual([]);
  expect(oracle.oracle_id).toBe('ORC-12');
  expect(oracle.results).toHaveLength(fixtures.length);

  // A sweep: bounds never go down from Easy to Hard (with a small reserve, adjacent bounds can meet), Tanaka is exact at every age, and every result carries the
  // talk-test lines, the "10 to 12 beats" line and the workout safety line.
  const sweep = JSON.parse(php(`
    $bad = [];
    for ($age = 18; $age <= 99; $age++) {
      $t = LLD_Members_HR_Zones::tanaka($age); $exact = 208 - 0.7 * $age;
      if (abs($t - $exact) > 0.5 + 1e-9) $bad[] = "tanaka $age";
      foreach ([null, 30, 50, 70, 90, 120] as $rhr) {
        $r = LLD_Members_HR_Zones::compute(['age' => $age, 'rhr' => $rhr]);
        if ($r['stop'] !== null) { if ($rhr === null || $t - $rhr >= 30) $bad[] = "$age $rhr stop"; continue; }
        if (!($r['easy'][0] <= $r['easy'][1] && $r['easy'][1] <= $r['moderate'][0] && $r['moderate'][0] <= $r['moderate'][1] && $r['moderate'][1] <= $r['hard'][0] && $r['hard'][0] <= $r['hard'][1] && $r['hard'][1] <= $r['hrMax'])) $bad[] = "$age $rhr order";
        if (array_slice($r['messages'], 0, 5) !== ['talk.easy', 'talk.moderate', 'talk.hard', 'result.weekly', 'result.hrmax'] || end($r['messages']) !== 'quoted.workoutSafety') $bad[] = "$age $rhr messages";
      }
    }
    echo json_encode($bad);
  `));
  expect(sweep).toEqual([]);
  // Out-of-range and non-numeric answers are refused.
  const bad = compute([{ ...base, rhr: 20 }, { ...base, rhr: null, measuredHrMax: 230 }, { ...base, rhr: 'fast' }, { ...base, age: 'x' }, { ...base, heartMedicine: ['yes'] }]);
  expect(bad.map((r) => r.error)).toEqual(['rhr', 'measuredHrMax', 'rhr', 'age', 'heartMedicine']);
});

test('TC-FR3-53-AS-02', () => {
  // A member who says they take heart-rate-lowering medicine (yes or not sure, on the page or in the health profile)
  // is shown the "talk to your doctor" talk-test wording instead of zones.
  const prof = JSON.parse(php(`echo json_encode([
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, heartMedicine: 'yes' })}),
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, heartMedicine: 'not_sure' })}),
    LLD_Members_HR_Zones::compute(LLD_Members_HR_Zones::apply_profile(${phpArr(base)}, ['heart_rate_medicine'])),
    LLD_Members_HR_Zones::compute(LLD_Members_HR_Zones::apply_profile(${phpArr({ ...base, measuredHrMax: 190 })}, ['heart_rate_medicine', 'age_65_plus'])),
  ]);`));
  const M = JSON.parse(php('echo json_encode(LLD_Members_HR_Zones::MESSAGES);'));
  for (const r of prof) {
    expect(r.stop).toBe('medicine');
    expect(r.messages).toEqual(['safety.medicine']);
    for (const k of ['hrMax', 'easy', 'moderate', 'hard']) expect(r[k]).toBeNull();
  }
  expect(M['safety.medicine']).toMatch(/Talk to your doctor about exercise, and use the talk test instead/);
});

test('TC-FR3-53-AS-02 (safety): heart condition, pregnancy, under 18 and the intense-exercise note', () => {
  const out = JSON.parse(php(`echo json_encode([
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, heartCondition: 'yes' })}),
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, pregnant: 'notsure' })}),
    LLD_Members_HR_Zones::compute(LLD_Members_HR_Zones::apply_profile(${phpArr(base)}, ['heart_disease'])),
    LLD_Members_HR_Zones::compute(LLD_Members_HR_Zones::apply_profile(${phpArr(base)}, ['pregnancy'])),
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, age: 17, pregnant: 'yes' })}),
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, age: 78, exerciseHistory: 'no' })}),
    LLD_Members_HR_Zones::compute(LLD_Members_HR_Zones::apply_profile(${phpArr(base)}, ['blood_pressure'])),
    LLD_Members_HR_Zones::compute(${phpArr({ ...base, age: 78, exerciseHistory: 'yes' })}),
  ]);`));
  expect(out.slice(0, 5).map((r) => r.stop)).toEqual(['heartCondition', 'pregnancy', 'heartCondition', 'pregnancy', 'under18']);
  expect(out[0].messages).toEqual(['safety.heartCondition']);
  expect(out[1].messages).toEqual(['safety.pregnancy']);
  for (const r of out.slice(0, 5)) expect(r.easy).toBeNull();
  expect(out[5].notes).toEqual(['over75NoHistory']);
  expect(out[6].notes).toEqual(['bloodPressure']);
  for (const r of out.slice(5, 7)) expect(r.messages).toContain('quoted.intenseExercise');
  expect(out[7].messages).not.toContain('quoted.intenseExercise');
});

test('NFR3-14 (heart-rate zones wording): every signed string hashes to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_HR_Zones::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  // Quoted Stage 2 wording is byte-identical to the built Stage 2 text.
  const Q = JSON.parse(php('echo json_encode(LLD_Members_HR_Zones::QUOTED);'));
  expect(readFileSync(resolve(plugin, 'includes/class-premium.php'), 'utf8')).toContain(`const WORKOUT_ADVICE = '${Q.workoutSafety}';`);
  expect(readFileSync(resolve(plugin, 'includes/class-safety.php'), 'utf8')).toContain(`'${Q.intenseExercise}'`);
  // Every key a result returns has signed text.
  const results = compute([...fixtures.map((f) => f.inputs), { ...base, age: 80, exerciseHistory: 'no' }]);
  const W = JSON.parse(php(`echo json_encode(LLD_Members_HR_Zones::wording(${phpArr([...new Set(results.flatMap((r) => r.messages))])}));`));
  for (const r of results) for (const k of r.messages) expect(W[k], k).toBeTruthy();
  expect(readFileSync(engine, 'utf8')).not.toMatch(/add_action|add_filter|\$wpdb|get_option|wp_remote/);
});
