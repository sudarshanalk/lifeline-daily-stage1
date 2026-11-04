// Saved calculator results over time (P3-5B, FR3-55). Engine vs frozen fixtures; wording vs approval copy_hashes.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/class-saved-results.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) =>
  execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], {
    encoding: 'utf8',
    timeout: 120000,
  }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const approval = json('approvals/stage3/saved-results.json');
const fixtureDir = resolve(root, 'fixtures/saved-results');
const allFixtures = readdirSync(fixtureDir)
  .filter((n) => n.endsWith('.json'))
  .flatMap((n) => json(`fixtures/saved-results/${n}`).fixtures);

const compare = (inputs) =>
  JSON.parse(
    php(
      `echo json_encode(array_map(function ($i) { return LLD_Members_Saved_Results::compare($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`
    )
  );
const recheck = (inputs) =>
  JSON.parse(
    php(
      `echo json_encode(array_map(function ($i) { return LLD_Members_Saved_Results::recheck($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`
    )
  );
const present = (rows, plan, consent = true, flags = {}) =>
  JSON.parse(
    php(
      `echo json_encode(LLD_Members_Saved_Results::present_list(json_decode(${JSON.stringify(JSON.stringify(rows))}, true), ${JSON.stringify(plan)}, ${consent ? 'true' : 'false'}, json_decode(${JSON.stringify(JSON.stringify(flags))}, true)));`
    )
  );

const changeFixtures = allFixtures.filter((f) => f.id.startsWith('FX-SR-0') || f.id === 'FX-SR-10' || f.id === 'FX-SR-11');
const savingFixtures = allFixtures.filter((f) => ['FX-SR-04', 'FX-SR-14', 'FX-SR-15'].includes(f.id));
const recheckFixtures = allFixtures.filter((f) => ['FX-SR-12', 'FX-SR-13'].includes(f.id));

test('TC-FR3-55-AS-01 Free member with health consent can save (save offered)', () => {
  expect(JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::save_offered(true));'))).toBe(true);
  expect(JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::save_offered(false));'))).toBe(false);
  const fx = savingFixtures.find((f) => f.id === 'FX-SR-14');
  expect(present([], 'free', fx.inputs.health_consent).save_offered).toBe(false);
});

test('TC-FR3-55-AS-02 Premium: two heart risk results show the change between them', () => {
  const fx = allFixtures.find((f) => f.id === 'FX-SR-01');
  const [got] = compare([fx.inputs]);
  expect(got.comparable).toBe(true);
  expect(got.wording).toBe(fx.expected.wording);
  expect(got.band_from).toBe(fx.expected.band_from);
  expect(got.band_to).toBe(fx.expected.band_to);
  expect(got.shown_from).toBe(fx.expected.shown_from);
  expect(got.shown_to).toBe(fx.expected.shown_to);
});

test('TC-FR3-55-AS-03 Free: latest only plus upgrade invitation', () => {
  const fx = savingFixtures.find((f) => f.id === 'FX-SR-04');
  const rows = fx.inputs.results
    .map((r, i) => ({
      id: i + 1,
      calculator: fx.inputs.calculator,
      result: r.result,
      unit: r.unit,
      headline: String(r.result),
      saved_at: `2026-0${i + 2}-01 12:00:00`,
    }))
    .reverse(); // newest first
  const view = present(rows, 'free', true);
  expect(view.latest_only).toBe(true);
  expect(view.history).toBe(false);
  expect(view.upgrade_line).toBe(true);
  expect(view.shown).toBe(fx.expected.shown);
  expect(view.rows).toHaveLength(1);
});

test('change fixtures FX-SR-01..11 match the signed sheet', () => {
  const problems = [];
  const changeIds = ['FX-SR-01', 'FX-SR-02', 'FX-SR-03', 'FX-SR-05', 'FX-SR-06', 'FX-SR-07', 'FX-SR-08', 'FX-SR-09', 'FX-SR-10', 'FX-SR-11'];
  const fixtures = allFixtures.filter((f) => changeIds.includes(f.id));
  const results = compare(fixtures.map((f) => f.inputs));
  fixtures.forEach((f, i) => {
    const got = results[i];
    for (const [k, want] of Object.entries(f.expected)) {
      if (k === 'earlier_version_note') {
        if (!got.earlier_version_note || !String(got.earlier_version_note).toLowerCase().includes('earlier version')) {
          problems.push(`${f.id}.${k}: ${JSON.stringify(got.earlier_version_note)}`);
        }
        continue;
      }
      if (got[k] !== want) {
        problems.push(`${f.id}.${k}: engine ${JSON.stringify(got[k])} != fixture ${JSON.stringify(want)}`);
      }
    }
  });
  expect(problems).toEqual([]);
});

test('recheck fixtures FX-SR-12 / FX-SR-13', () => {
  const results = recheck(recheckFixtures.map((f) => f.inputs));
  const problems = [];
  recheckFixtures.forEach((f, i) => {
    const got = results[i];
    for (const [k, want] of Object.entries(f.expected)) {
      if (got[k] !== want) {
        problems.push(`${f.id}.${k}: engine ${JSON.stringify(got[k])} != fixture ${JSON.stringify(want)}`);
      }
    }
    if (f.id === 'FX-SR-12') {
      expect(got.email_text).toBe(approval.copy_hashes['recheck.email'] ? 'a calculator re-check is due' : got.email_text);
      expect(got.email_text).toBe('a calculator re-check is due');
    }
  });
  expect(problems).toEqual([]);
});

test('FX-SR-15 eating-disorder hides calorie_target and API refuses', () => {
  const fx = savingFixtures.find((f) => f.id === 'FX-SR-15');
  const rows = [{ id: 1, calculator: 'calorie_target', result: 1800, unit: 'kcal', headline: '1800', band: 'goal' }];
  const view = present(rows, 'premium', true, { eating_disorder: true });
  expect(view.rows).toHaveLength(0);
  expect(JSON.parse(php(`echo json_encode(LLD_Members_Saved_Results::api_refuses('calorie_target', ['eating_disorder'=>true]));`))).toBe(true);
  expect(JSON.parse(php(`echo json_encode(LLD_Members_Saved_Results::is_hidden('calorie_target', ['eating_disorder'=>true]));`))).toBe(true);
  expect(fx.expected.hidden_everywhere).toBe(true);
});

test('NFR3-14 (wording): signed saved-results strings hash to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  expect(approval.decisions_ratified).toEqual(['D3-33', 'D3-34', 'D3-35']);
});

test('P3-5B wiring: loader, schema columns, API write path', () => {
  const loader = readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8');
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  const schema = readFileSync(resolve(plugin, 'includes/stage3/class-schema-stage3.php'), 'utf8');
  expect(loader).toContain('class-saved-results.php');
  expect(api).toContain('LLD_Members_Saved_Results');
  expect(api).toContain("list_calculator_results");
  expect(api).toMatch(/'calculator-results'[\s\S]*?'write'\s*=>\s*true/);
  expect(schema).toContain("ensure_column( 'calculator_result', 'inputs_ct'");
  expect(schema).toContain("ensure_column( 'calculator_result', 'result_ct'");
  expect(JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::stores_inputs("heart_risk"));'))).toBe(true);
  expect(JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::stores_inputs("protein"));'))).toBe(false);
  expect(JSON.parse(php('echo json_encode(LLD_Members_Saved_Results::stores_inputs("healthy_weight"));'))).toBe(false);
});
