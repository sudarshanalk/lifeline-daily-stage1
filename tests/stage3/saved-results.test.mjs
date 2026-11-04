// Saved calculator results over time (P3-5B, FR3-55). Engine vs frozen fixtures; the three
// signed fragments that are in the repository vs the approval copy_hashes.
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-saved-results.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const approval = json('approvals/stage3/saved-results.json');
const allFixtures = readdirSync(resolve(root, 'fixtures/saved-results'))
  .filter((n) => n.endsWith('.json'))
  .flatMap((n) => json(`fixtures/saved-results/${n}`).fixtures);

const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Saved_Results::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
const close = (a, b) => {
  if (a === b) return true;
  if (typeof a === 'number' && typeof b === 'number') return Math.abs(a - b) < 0.001;
  return JSON.stringify(a) === JSON.stringify(b);
};

test('saved-results fixtures: every frozen expected value', () => {
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
});

test('TC-FR3-55-AS-01', () => {
  const saved = JSON.parse(php(`echo json_encode(LLD_Members_Saved_Results::stored_fields(array(
    'calculator' => 'diabetes_risk',
    'plan' => 'free',
    'health_consent' => true,
    'results' => array(array('result' => 8.2, 'unit' => '%', 'band' => 'Moderate', 'engine_version' => '1.4', 'age' => 55)),
  )));`));
  expect(saved.saved).toBe(true);
  expect(saved.headline).toBe('8.2');
  expect(saved.unit).toBe('%');
  expect(saved.band).toBe('Moderate');
  expect(saved.calculator_version).toBe('1.4');
  // Stage 1 keeps Stage 2 behaviour: the answers are not stored (D51).
  expect(saved.inputs).toBeNull();
  expect(JSON.stringify(saved)).not.toContain('55');

  const stage3 = JSON.parse(php(`echo json_encode(LLD_Members_Saved_Results::stored_fields(array(
    'calculator' => 'heart_risk',
    'plan' => 'free',
    'health_consent' => true,
    'results' => array(array('result' => 12.3, 'unit' => '%', 'model' => 'lab', 'age' => 55)),
  )));`));
  expect(stage3.saved).toBe(true);
  expect(stage3.headline).toBe('12.3');
  expect(stage3.unit).toBe('%');
  expect(stage3.band).toBe('High');
  expect(stage3.inputs).toMatchObject({ result: 12.3, model: 'lab', age: 55 });
  expect(stage3.headline).not.toContain('55');

  const refused = JSON.parse(php(`echo json_encode(LLD_Members_Saved_Results::stored_fields(array(
    'calculator' => 'heart_risk', 'plan' => 'free', 'health_consent' => false,
    'results' => array(array('result' => 12.3, 'unit' => '%')),
  )));`));
  expect(refused.saved).toBe(false);
  expect(refused.inputs).toBeNull();
});

test('TC-FR3-55-AS-02', () => {
  const fx = allFixtures.find((f) => f.id === 'FX-SR-01');
  const [row] = compute([fx.inputs]);
  expect(row.history).toBe(true);
  expect(row.comparison).toBe(true);
  expect(row.wording).toBe('Better: 2 points lower since 1 Feb');
  expect(row.shown_from).toBe('12 %');
  expect(row.shown_to).toBe('10 %');
  expect(row.band_from).toBe('High');
  expect(row.band_to).toBe('Moderate');
  // Both results are part of the comparison the Premium member is shown.
  expect(fx.inputs.results).toHaveLength(2);
});

test('TC-FR3-55-AS-03', () => {
  const fx = allFixtures.find((f) => f.id === 'FX-SR-04');
  const [row] = compute([fx.inputs]);
  expect(row.shown).toBe('9.8 %');
  expect(row.latest_only).toBe(true);
  expect(row.history).toBe(false);
  expect(row.comparison).toBe(false);
  expect(row.upgrade_line).toBe(true);
  // The earlier result is not in the line the Free member sees.
  expect(row.shown).not.toContain('12.3');
  expect(row.wording).toBeUndefined();
});

test('NFR3-14 (wording): signed saved-results fragments that are in the repository hash to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::MESSAGES);'));
  const problems = [];
  for (const [key, text] of Object.entries(M)) {
    const got = createHash('sha256').update(text, 'utf8').digest('hex');
    if (approval.copy_hashes[key] !== got) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(M['recheck.email']).toBe('a calculator re-check is due');
  expect(M['recheck.email']).not.toMatch(/\d|heart|calorie|weight|blood|fitness/i);
  expect(approval.clinical_signoff.decision).toBe('approved');
});

test('P3-5B wiring: loader, schema and the members interface keep saved results', () => {
  const loader = readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8');
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  const schema = readFileSync(resolve(plugin, 'includes/stage3/class-schema-stage3.php'), 'utf8');
  expect(loader).toContain('class-saved-results.php');
  expect(api).toContain('LLD_Members_Saved_Results::store');
  expect(api).toContain('LLD_Members_Saved_Results::api_list');
  expect(schema).toContain("ensure_column( 'calculator_result', 'inputs_ct'");
  // The clear columns stay the Stage 2 ones. Answers go only through the sealed column.
  const src = readFileSync(engine, 'utf8');
  expect(src).toContain('LLD_Members_Stage3_Data::seal');
  expect(src).not.toMatch(/error_log\(|wp_mail\(|var_dump\(/);
});
