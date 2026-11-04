// Goal Weight Timeline (P3-56, FR3-48). Engine vs frozen fixtures; wording vs approval copy_hashes.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-goal-timeline.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const approval = json('approvals/stage3/goal-timeline.json');
const fixtureDir = resolve(root, 'fixtures/goal-timeline');
const allFixtures = readdirSync(fixtureDir)
  .filter((n) => n.endsWith('.json'))
  .flatMap((n) => json(`fixtures/goal-timeline/${n}`).fixtures);

const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Goal_Timeline::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
const close = (a, b) => {
  if (a === b) return true;
  if (typeof a === 'number' && typeof b === 'number') return Math.abs(a - b) < 0.001;
  return JSON.stringify(a) === JSON.stringify(b);
};

test('TC-FR3-48-AS-01', () => {
  const results = compute(allFixtures.map((f) => f.inputs));
  const problems = [];
  allFixtures.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) {
      if (!Object.prototype.hasOwnProperty.call(results[i], k) && want === null) continue;
      if (!close(results[i][k], want)) {
        problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
      }
    }
  });
  expect(problems).toEqual([]);
  // Independent Python reference (lives under tests/ until the lead lands ORC-07).
  const ref = execFileSync('python', ['-I', resolve(here, 'tools/goal-timeline-ref.py'), root], { encoding: 'utf8' });
  expect(ref).toMatch(/0 difference/);
});

test('TC-FR3-48-AS-02', () => {
  const r = compute([{ currentKg: 70, goalKg: 50, heightCm: 170, rateKgPerWeek: 0.5 }])[0];
  expect(r.refused).toMatch(/BMI 18\.5|below/);
  expect(r.offer.goalKg).toBe(53.5);
  expect(r.weeks).toBeNull();
});

test('TC-FR3-48-AS-03', () => {
  const over = compute([{ currentKg: 90, goalKg: 84, heightCm: 175, rateKgPerWeek: 1.0 }])[0];
  expect(over.refused).toMatch(/cap/);
  expect(over.capKgPerWeek).toBe(0.9);
  expect(over.offer.rateKgPerWeek).toBeLessThanOrEqual(0.9);
  const ok = compute([{ currentKg: 90, goalKg: 84, heightCm: 175, rateKgPerWeek: 0.5 }])[0];
  expect(ok.refused).toBeNull();
  expect(ok.weeks).toBe(12);
  // Cap never above 1.0 or 1 % of weight.
  const sweep = JSON.parse(php(`
    $bad = [];
    foreach ([50, 58, 60, 90, 100, 120] as $w) foreach ([0.25, 0.5, 0.75, 1.0, 1.5] as $rate) {
      $r = LLD_Members_Goal_Timeline::compute(['currentKg'=>$w,'goalKg'=>$w-6,'heightCm'=>175,'rateKgPerWeek'=>$rate,'age'=>40]);
      $cap = LLD_Members_Goal_Timeline::rate_cap($w, false);
      if ($r['stop'] || $r['maintain']) continue;
      if ($r['refused'] === null && $rate > $cap + 1e-9) $bad[] = "$w @$rate accepted above cap $cap";
      if ($cap > 1.0 + 1e-9 || $cap > 0.01 * $w + 0.005) $bad[] = "cap $cap for $w";
    }
    echo json_encode($bad);
  `));
  expect(sweep).toEqual([]);
});

test('NFR3-14 (wording): signed goal-timeline strings hash to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Goal_Timeline::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(approval.clinical_signoff.decision).toBe('approved');
  expect(approval.decisions_ratified).toEqual(['D3-07', 'D3-08', 'D3-09']);
});

test('P3-56 wiring: loader and API expose goal-timeline', () => {
  const loader = readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8');
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  expect(loader).toContain('class-goal-timeline.php');
  expect(api).toContain('goal-timeline');
  expect(api).toContain('LLD_Members_Goal_Timeline::compute');
});
