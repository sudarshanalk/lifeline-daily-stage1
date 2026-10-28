// TC-FR1-07 Protein engine (v2, DEC-86) and verification — unit + oracle + ci families.
// Fixtures: FX-PR-NRM, FX-PR-AGE, FX-PR-BMI, FX-PR-CAP, FX-PR-UNIT, FX-PR-MEAL, FX-PR-INV. Mutation: MUT-07.
// The engine runs in jsdom exactly as the baseline runner does (tests/lib/tool-page.mjs). Characterisation values were
// recorded from the v2 engine by the oracle-runner workflow and prove "unchanged"; ORC-02 v2 (written from DEC-86 alone)
// is the correctness oracle.
import { beforeAll, describe, expect, test } from 'vitest';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { proteinRunner, frozenSources, frozenHashes } from '../lib/tool-page.mjs';
import { engineFreeze } from '../ci/engine-freeze.mjs';
import orc02 from '../../oracles/orc-02-protein-ref/results.json' with { type: 'json' };
import primarySources from '../../oracles/orc-02-protein-ref/primary-sources.json' with { type: 'json' };
import manifest from '../../manifests/protein.json' with { type: 'json' };

const SCOPE = ['FX-PR-NRM-*', 'FX-PR-AGE-*', 'FX-PR-BMI-*', 'FX-PR-CAP-*', 'FX-PR-UNIT-*', 'FX-PR-MEAL-*'];
const COMPARE_KEYS = ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal', 'basisKg', 'capped', 'sources'];
const orc = Object.fromEntries(orc02.results.map((r) => [r.fixture_id, r.expected]));
let run;
beforeAll(() => { run = proteinRunner(); });

describe('TC-FR1-07 protein engine (v2) and verification', () => {
  test('TC-FR1-07-AS-01', () => {
    // engine output equals the characterisation expected values for every in-scope fixture
    const fxs = SCOPE.flatMap((p) => fixtures(p)).filter((f) => f.oracle === 'characterisation');
    expect(fxs.length).toBe(32);
    for (const f of fxs) {
      const got = run(f.inputs);
      for (const k of COMPARE_KEYS) expect(got[k], `${f.id} ${k}`).toEqual(f.expected[k]);
      expect(got, `${f.id} full output`).toEqual(f.expected);
    }
  });

  test('TC-FR1-07-AS-02', () => {
    // ORC-02 agreement: ±1 g on targetG/lowG/highG/perMeal, gPerKg exact at 2 dp; any disagreement is a blocker (DEC row)
    const fxs = SCOPE.flatMap((p) => fixtures(p)).filter((f) => f.oracle === 'characterisation');
    const record = [];
    const disagreements = [];
    for (const f of fxs) {
      const got = run(f.inputs); const ref = orc[f.id];
      expect(ref, `${f.id} has an ORC-02 row`).toBeDefined();
      const within = Math.abs(got.targetG - ref.targetG) <= 1 && Math.abs(got.lowG - ref.lowG) <= 1 && Math.abs(got.highG - ref.highG) <= 1 && Math.abs(got.perMeal - ref.perMeal) <= 1 && got.gPerKg.toFixed(2) === ref.gPerKg.toFixed(2) && !!got.suppressed === !!ref.suppressed;
      if (!within) disagreements.push({ fixture: f.id, js: got, orc02: ref });
      record.push({ fixture: f.id, agree: within, js: { targetG: got.targetG, lowG: got.lowG, highG: got.highG, perMeal: got.perMeal, gPerKg: got.gPerKg }, orc02: { targetG: ref.targetG, lowG: ref.lowG, highG: ref.highG, perMeal: ref.perMeal, gPerKg: ref.gPerKg } });
    }
    mkdirSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts'), { recursive: true });
    writeFileSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts', 'orc02-comparison.json'), JSON.stringify({ tolerance: '±1 g; gPerKg exact 2 dp', record, disagreements }, null, 2) + '\n');
    expect(disagreements, 'ORC-02 disagreement → blocker + DEC row').toEqual([]);
  });

  test('TC-FR1-07-AS-04', () => {
    // imperial twins give the same numeric outputs as their metric originals
    const twins = { 'FX-PR-UNIT-01': 'FX-PR-NRM-01', 'FX-PR-UNIT-02': 'FX-PR-BMI-02', 'FX-PR-UNIT-03': 'FX-PR-NRM-01' };
    for (const [imp, met] of Object.entries(twins)) {
      const a = run(fixtures(imp)[0].inputs), b = run(fixtures(met)[0].inputs);
      for (const k of ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal', 'basisKg', 'bmi']) expect(a[k], `${imp} vs ${met} ${k}`).toBeCloseTo(b[k], 0);
      expect(a.sources).toEqual(b.sources); expect(a.capped).toBe(b.capped);
    }
  });

  test('TC-FR1-07-AS-05', () => {
    // frozen object hashes equal the manifest engine_hashes (same check as the engine-freeze CI job)
    const r = engineFreeze({ root: ROOT });
    expect(r.problems).toEqual([]);
    const live = manifest.versions.find((v) => v.is_live);
    for (const [k, h] of Object.entries(frozenHashes('protein'))) expect(live.engine_hashes[k], k).toBe(h);
  });

  test('TC-FR1-07-AS-06', () => {
    // basis weight: actual up to the BMI 24.9 weight W, then W + 0.4 × excess, for everyone; ethnicity never changes it;
    // the target never falls as weight rises
    const W = (cm) => 24.9 * (cm / 100) ** 2;
    const i1 = fixtures('FX-PR-BMI-01')[0].inputs, i2 = fixtures('FX-PR-BMI-02')[0].inputs;
    expect(run(i1).basisKg).toBeCloseTo(i1.weightKg, 3);
    expect(Math.abs(run(i2).basisKg - (W(i2.heightCm) + 0.4 * (i2.weightKg - W(i2.heightCm))))).toBeLessThanOrEqual(0.1);
    const b3 = run(fixtures('FX-PR-BMI-03')[0].inputs), b4 = run(fixtures('FX-PR-BMI-04')[0].inputs);
    expect(b3.basisKg).toBe(b4.basisKg);
    expect(b3.targetG).toBe(b4.targetG);
    const base = fixtures('FX-PR-NRM-01')[0].inputs;
    for (const heightCm of [150, 170, 190]) {
      let prev = 0;
      for (let weightKg = 40; weightKg <= 200; weightKg += 0.5) {
        const t = run({ ...base, heightCm, weightKg }).targetG;
        expect(t, `${heightCm} cm, ${weightKg} kg`).toBeGreaterThanOrEqual(prev);
        prev = t;
      }
    }
  });

  test('TC-FR1-07-AS-07', () => {
    // per meal = daily target ÷ meals, with no 20–40 g clamp (MEAL-01 falls below 20 g, MEAL-02 rises above 40 g)
    for (const f of fixtures('FX-PR-MEAL-*')) {
      const got = run(f.inputs);
      expect(Math.abs(got.perMeal - got.targetG / f.inputs.meals), f.id).toBeLessThanOrEqual(0.5 + 1 / f.inputs.meals);
    }
    expect(run(fixtures('FX-PR-MEAL-01')[0].inputs).perMeal).toBeLessThan(20);
    expect(run(fixtures('FX-PR-MEAL-02')[0].inputs).perMeal).toBeGreaterThan(40);
  });

  test('TC-FR1-07-AS-08', () => {
    // every BANDS row (lo, hi, src) lies inside the range of a primary source it cites; a row that does not fails
    const src = frozenSources('protein').BANDS;
    const rows = [...src.matchAll(/^\s*(\w+):\s*\{lo:([\d.]+),\s*hi:([\d.]+),\s*src:'([^']*)'/gm)].map((m) => ({ key: m[1], lo: +m[2], hi: +m[3], src: m[4] }));
    expect(rows.length).toBe(8);
    const sources = primarySources.sources.flatMap((s) => [s, ...(s.sub_ranges || []).map((r) => ({ ...s, ...r }))]).filter((s) => s.g_per_kg_lo != null);
    const KEYS = [...new Set(primarySources.sources.map((s) => s.key))];
    const cites = (text) => KEYS.filter((k) => text.includes(k) || (k === 'OPTIMen' && /Bhasin/.test(text)));
    const report = rows.map((row) => {
      const cited = cites(row.src);
      const derivable = sources.some((s) => cited.includes(s.key) && row.lo >= s.g_per_kg_lo - 1e-9 && row.hi <= s.g_per_kg_hi + 1e-9);
      return { ...row, cited, derivable };
    });
    mkdirSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts'), { recursive: true });
    writeFileSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts', 'band-table-check.json'), JSON.stringify({ ceiling: primarySources.ceiling_g_per_kg, rows: report }, null, 2) + '\n');
    for (const r of report) {
      expect(r.cited.length, `${r.key} cites a primary source`).toBeGreaterThan(0);
      expect(r.derivable, `${r.key} ${r.lo}-${r.hi} lies inside a cited source (${r.src})`).toBe(true);
      expect(r.hi, `${r.key} within the 2.0 ceiling`).toBeLessThanOrEqual(primarySources.ceiling_g_per_kg);
    }
  });
});
