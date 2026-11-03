// Daily Calorie Target (P3-55, FR3-47, FR3-28): the engine is run in PHP CLI without WordPress against the frozen
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
const engine = resolve(plugin, 'includes/stage3/calculators/class-calorie-target.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const target = json('fixtures/calorie-target/target.json').fixtures;
const macros = json('fixtures/calorie-target/macros.json').fixtures;
const approval = json('approvals/stage3/calorie-target.json');
const oracle = json('oracles/orc-06-calorie-target/results.json');

/** Run compute() over a list of inputs in one PHP call. */
const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Calorie_Target::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
const close = (a, b) => typeof a === 'number' && typeof b === 'number' ? Math.abs(a - b) < 0.001 : JSON.stringify(a) === JSON.stringify(b);

test('TC-FR3-47-AS-01', () => {
  // Every result matches the approved reference fixtures: the engine against the frozen table, and the engine
  // against the oracle written independently from the sheet, for every fixture. A fixture value left null in the
  // sheet (CAL-19's intermediates, two macro fibre cells) is not asserted.
  const results = compute(target.map((f) => f.inputs));
  const problems = [];
  target.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) {
      if (want === null) continue;
      if (!close(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
    }
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    expect(orc, f.id).toBeTruthy();
    for (const [k, want] of Object.entries(orc.expected)) {
      if (!close(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != oracle ${JSON.stringify(want)}`);
    }
  });
  const mac = JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Calorie_Target::macros($i['targetKcal'], $i['proteinG']); }, json_decode(${JSON.stringify(JSON.stringify(macros.map((f) => f.inputs)))}, true)));`));
  macros.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) if (want !== null && !close(mac[i][k], want)) problems.push(`${f.id}.${k}: engine ${mac[i][k]} != fixture ${want}`);
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    for (const [k, want] of Object.entries(orc.expected)) if (!close(mac[i][k], want)) problems.push(`${f.id}.${k}: engine ${mac[i][k]} != oracle ${want}`);
  });
  expect(problems).toEqual([]);
  expect(oracle.oracle_id).toBe('ORC-06');
  expect(oracle.results).toHaveLength(target.length + macros.length);
});

test('TC-FR3-47-AS-02', () => {
  // No result is below the approved safe minimum: a sweep of adult answers never returns a loss target under the
  // floor (1,200 kcal for female and prefer-not-to-say, 1,500 for male), and a member's own target under the floor
  // is refused (FR3-28 AS-02).
  const out = JSON.parse(php(`
    $bad = []; $n = 0;
    foreach (['F', 'M', 'PNTS'] as $sex) foreach ([18, 30, 45, 64, 65, 80, 99] as $age) foreach ([120, 150, 175, 200, 230] as $h)
      foreach ([25, 45, 60, 80, 120, 200, 350] as $w) foreach (['low', 'some', 'active', 'very active'] as $act) foreach ([0.25, 0.5, 0.75, 1.0, 3.0] as $rate) {
        $r = LLD_Members_Calorie_Target::compute(['sex' => $sex, 'age' => $age, 'heightCm' => $h, 'weightKg' => $w, 'activity' => $act, 'goal' => 'lose', 'rateKgWeek' => $rate]);
        $n++;
        $floor = LLD_Members_Calorie_Target::floor_for($sex);
        if (isset($r['error']) || $r['stop'] !== null) { $bad[] = "$sex $age $h $w: unexpected stop/error"; continue; }
        // A loss target is never below the floor. When no loss is possible (TDEE at or under the floor, BMI under 18.5)
        // the sheet offers maintenance at round10(TDEE) instead, with no rate: that is not a loss target.
        if ($r['maintenanceOnly']) { if ($r['achievableRate'] !== 0 && $r['achievableRate'] !== null) $bad[] = "$sex $age $h $w: maintenance with a rate"; }
        elseif ($r['targetKcal'] < $floor) $bad[] = "$sex $age $h $w $act $rate: {$r['targetKcal']} < $floor";
        if ($r['offeredRate'] > 1.0 || $r['offeredRate'] > 0.01 * $w + 0.005 + 1e-9 || ($age >= 65 && $r['offeredRate'] > 0.5)) $bad[] = "$sex $age $w: rate {$r['offeredRate']} above the cap";
      }
    $own = LLD_Members_Calorie_Target::compute(['sex' => 'F', 'age' => 45, 'heightCm' => 160, 'weightKg' => 80, 'activity' => 'some', 'goal' => 'lose', 'rateKgWeek' => 0.5, 'memberTarget' => 1150]);
    $ownM = LLD_Members_Calorie_Target::compute(['sex' => 'M', 'age' => 45, 'heightCm' => 180, 'weightKg' => 90, 'activity' => 'some', 'goal' => 'lose', 'rateKgWeek' => 0.5, 'memberTarget' => 1450]);
    $ok = LLD_Members_Calorie_Target::compute(['sex' => 'M', 'age' => 45, 'heightCm' => 180, 'weightKg' => 90, 'activity' => 'some', 'goal' => 'lose', 'rateKgWeek' => 0.5, 'memberTarget' => 1500]);
    echo json_encode(['n' => $n, 'bad' => $bad, 'own' => $own['refused'], 'ownM' => $ownM['refused'], 'ok' => [$ok['refused'], $ok['targetKcal']], 'floors' => [LLD_Members_Calorie_Target::floor_for('female'), LLD_Members_Calorie_Target::floor_for('male'), LLD_Members_Calorie_Target::floor_for(''), LLD_Members_Calorie_Target::floor_for('prefer_not')]]);
  `));
  expect(out.n).toBeGreaterThan(4000);
  expect(out.bad).toEqual([]);
  expect(out.own).toBe(true);
  expect(out.ownM).toBe(true);
  expect(out.ok).toEqual([false, 1500]);
  expect(out.floors).toEqual([1200, 1500, 1200, 1200]);
});

test('TC-FR3-47-AS-03', () => {
  // A member who is pregnant or under 18 is shown the safety wording instead of a weight-loss target: the stop
  // wording alone, no number of any kind, whatever the other answers.
  const base = { sex: 'F', age: 30, heightCm: 165, weightKg: 70, activity: 'some', goal: 'lose', rateKgWeek: 0.5 };
  const [preg, notSure, young, eating, fine] = compute([
    { ...base, pregnant: 'yes' }, { ...base, pregnant: 'notsure' }, { ...base, age: 17 }, { ...base, eatingDisorder: 'yes' }, base,
  ]);
  for (const [r, stop] of [[preg, 'pregnancy'], [notSure, 'pregnancy'], [young, 'under18'], [eating, 'eating']]) {
    expect(r.stop).toBe(stop);
    expect(r.messages).toEqual([`stop.${stop}`]);
    for (const k of ['rmr', 'tdee', 'raw', 'targetKcal', 'achievableRate', 'bmi']) expect(r[k], `${stop} ${k}`).toBeNull();
  }
  const M = JSON.parse(php('echo json_encode(LLD_Members_Calorie_Target::MESSAGES);'));
  expect(M['stop.pregnancy']).toMatch(/midwife or doctor/);
  expect(M['stop.under18']).toMatch(/for adults/);
  expect(M['stop.eating']).toMatch(/findahelpline\.com/);
  expect(fine.stop).toBeNull();
  expect(fine.targetKcal).toBeGreaterThanOrEqual(1200);
});

test('TC-FR3-28-AS-02 (source and engine): a calorie target below the safe minimum is refused, not saved', () => {
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  const create = api.slice(api.indexOf('public static function create('), api.indexOf('public static function patch('));
  expect(create).toMatch(/'nutrition_targets' === \$def\['table'\][\s\S]*kcal_floor\( \$member_id \)/);
  expect(create).toMatch(/refuse\( 422, LLD_Members_Calorie_Target::MESSAGES\['floor_note'\] \)/);
  expect(create.indexOf('kcal_floor')).toBeLessThan(create.indexOf('LLD_Members_Stage3_Data::insert'));
  // The calculator route sits behind calculators_new and needs a signed-in member; "save" needs health consent.
  const calc = api.slice(api.indexOf('public static function calculate('), api.indexOf('private static function protein_target('));
  expect(calc).toMatch(/LLD_Members_Features::enabled\( 'calculators_new', \$member_id \)/);
  expect(calc.indexOf("enabled( 'calculators_new'")).toBeLessThan(calc.indexOf('$member_id < 1'));
  expect(calc).toMatch(/has_health_consent\( \$member_id \)/);
  expect(calc).toMatch(/'set_by'\s+=> 'calculator'/);
  expect(api).toMatch(/\/me\/calculate\/\(\?P<tool>\[a-z-\]\+\)/);
  // The engine never computes protein (D3-06): macros take it as given and leave carbohydrate open without it.
  const m = JSON.parse(php("echo json_encode([LLD_Members_Calorie_Target::macros(1800, 84), LLD_Members_Calorie_Target::macros(1800, null)]);"));
  expect(m[0]).toEqual({ proteinG: 84, fatG: 60, carbG: 231, fibreG: 30 });
  expect(m[1]).toEqual({ proteinG: null, fatG: 60, carbG: null, fibreG: 30 });
});

test('NFR3-14 (wording): every signed string in the engine hashes to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Calorie_Target::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  // The engine is loaded through the Stage 3 loader and does nothing on its own (no hooks, no database).
  const src = readFileSync(engine, 'utf8');
  expect(src).not.toMatch(/add_action|add_filter|\$wpdb|get_option|wp_remote/);
  expect(readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8')).toMatch(/calculators\/class-calorie-target\.php/);
});
