// TC-FR1-07 Protein engine preservation and verification — unit + oracle + ci families.
// Fixtures: FX-PR-NRM, FX-PR-AGE, FX-PR-BMI, FX-PR-CAP, FX-PR-UNIT, FX-PR-MEAL, FX-PR-INV. Mutation: MUT-07.
// Engine runs in jsdom exactly as the baseline runner did (tests/lib/tool-page.mjs). Characterisation values prove
// "unchanged"; ORC-02 (sealed reference) is the correctness oracle; DEC-44 known deviations compared to characterisation only.
import { beforeAll, describe, expect, test } from 'vitest';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { proteinRunner, frozenSources, frozenHashes } from '../lib/tool-page.mjs';
import { engineFreeze } from '../ci/engine-freeze.mjs';
import orc02 from '../../oracles/orc-02-protein-ref/results.json' with { type: 'json' };
import primarySources from '../../oracles/orc-02-protein-ref/primary-sources.json' with { type: 'json' };
import registry from '../../registry/Stage1_Requirements_Registry_v1_2.json' with { type: 'json' };
import manifest from '../../manifests/protein.json' with { type: 'json' };

const SCOPE = ['FX-PR-NRM-*', 'FX-PR-AGE-*', 'FX-PR-BMI-*', 'FX-PR-CAP-*', 'FX-PR-UNIT-*', 'FX-PR-MEAL-*'];
const COMPARE_KEYS = ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal', 'basisKg', 'capped', 'sources'];
const orc = Object.fromEntries(orc02.results.map((r) => [r.fixture_id, r.expected]));
const knownDeviations = registry.requirements.find((r) => r.id === 'FR1-07').known_deviations; // { "DEC-44": [ids] }
const deviationDec = (id) => Object.entries(knownDeviations).find(([, ids]) => ids.includes(id))?.[0] || null;
let run;
beforeAll(() => { run = proteinRunner(); });

describe('TC-FR1-07 protein engine preservation and verification', () => {
  test('TC-FR1-07-AS-01', () => {
    // engine output equals the characterisation expected values for every in-scope fixture
    const fxs = SCOPE.flatMap((p) => fixtures(p)).filter((f) => f.oracle === 'characterisation');
    expect(fxs.length).toBeGreaterThanOrEqual(27);
    for (const f of fxs) {
      const got = run(f.inputs);
      for (const k of COMPARE_KEYS) expect(got[k], `${f.id} ${k}`).toEqual(f.expected[k]);
      expect(got, `${f.id} full output`).toEqual(f.expected);
    }
  });

  test('TC-FR1-07-AS-02', () => {
    // ORC-02 agreement: ±1 g on targetG/lowG/highG/perMeal, gPerKg exact at 2 dp; DEC-44 known deviations compared to
    // characterisation only and recorded as such; any other disagreement is a blocker (DEC row) → test fails
    const fxs = SCOPE.flatMap((p) => fixtures(p)).filter((f) => f.oracle === 'characterisation');
    const record = [];
    const disagreements = [];
    for (const f of fxs) {
      const got = run(f.inputs); const ref = orc[f.id];
      expect(ref, `${f.id} has an ORC-02 row`).toBeDefined();
      const dec = deviationDec(f.id);
      const within = Math.abs(got.targetG - ref.targetG) <= 1 && Math.abs(got.lowG - ref.lowG) <= 1 && Math.abs(got.highG - ref.highG) <= 1 && Math.abs(got.perMeal - ref.perMeal) <= 1 && got.gPerKg.toFixed(2) === ref.gPerKg.toFixed(2) && got.suppressed === ref.suppressed;
      if (dec) {
        for (const k of COMPARE_KEYS) expect(got[k], `${f.id} (${dec}) vs characterisation ${k}`).toEqual(f.expected[k]);
        record.push({ fixture: f.id, oracle: 'characterisation', known_deviation: dec, orc02: { targetG: ref.targetG, gPerKg: ref.gPerKg }, js: { targetG: got.targetG, gPerKg: got.gPerKg } });
      } else {
        if (!within) disagreements.push({ fixture: f.id, js: got, orc02: ref });
        record.push({ fixture: f.id, oracle: 'clinical', agree: within });
      }
    }
    mkdirSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts'), { recursive: true });
    writeFileSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts', 'orc02-comparison.json'), JSON.stringify({ generated: new Date().toISOString(), tolerance: '±1 g; gPerKg exact 2 dp', record, disagreements }, null, 2) + '\n');
    expect(disagreements, 'ORC-02 disagreement outside known deviations → blocker + DEC row').toEqual([]);
    expect(record.filter((r) => r.known_deviation).map((r) => r.fixture).sort()).toEqual(['FX-PR-CAP-01', 'FX-PR-CAP-02']);
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
    // basis weight: BMI-01 actual weight 91.5; BMI-02 adjusted = ideal + 0.4 × excess (76.3 + 0.4×15.7 ± 0.1); BMI-03 Asian 22.9 bound
    const b1 = run(fixtures('FX-PR-BMI-01')[0].inputs), b2 = run(fixtures('FX-PR-BMI-02')[0].inputs), b3 = run(fixtures('FX-PR-BMI-03')[0].inputs);
    expect(b1.basisKg).toBeCloseTo(91.5, 1);
    expect(Math.abs(b2.basisKg - (76.3 + 0.4 * 15.7))).toBeLessThanOrEqual(0.1);
    const i3 = fixtures('FX-PR-BMI-03')[0].inputs; const hM = i3.heightCm / 100;
    const upper = 22.9 * hM * hM;
    expect(b3.basisKg).toBeCloseTo(upper + 0.4 * (i3.weightKg - upper), 1);
    expect(b3.basisNote).not.toBe('your current weight');
  });

  test('TC-FR1-07-AS-07', () => {
    // per-meal clamp 20–40 g
    expect(run(fixtures('FX-PR-MEAL-01')[0].inputs).perMeal).toBe(20);
    expect(run(fixtures('FX-PR-MEAL-02')[0].inputs).perMeal).toBe(40);
    const m3 = fixtures('FX-PR-MEAL-03')[0]; expect(run(m3.inputs).perMeal).toBe(m3.expected.perMeal);
  });

  test('TC-FR1-07-AS-08', () => {
    // every BANDS row (lo, hi, src) is consistent with the primary-source table (reference §2); rows whose figures are not
    // derivable from a listed source are reported for HG-02 (DEC-03), not silently accepted
    const src = frozenSources('protein').BANDS;
    const rows = [...src.matchAll(/^\s*(\w+):\s*\{lo:([\d.]+),\s*hi:([\d.]+),\s*src:'([^']*)'/gm)].map((m) => ({ key: m[1], lo: +m[2], hi: +m[3], src: m[4] }));
    expect(rows.length).toBe(12);
    const sources = primarySources.sources.flatMap((s) => [s, ...(s.sub_ranges || []).map((r) => ({ ...s, ...r }))]).filter((s) => s.g_per_kg_lo != null);
    const nameOf = (s) => (s.guideline.match(/IOM|WHO|PROT-AGE|ESPEN|ISSN|ACSM/) || [''])[0];
    const report = rows.map((row) => {
      const cited = [...new Set((row.src.match(/IOM|WHO|PROT-AGE|ESPEN|ISSN|ACSM/g) || []))];
      const candidates = sources.filter((s) => cited.includes(nameOf(s)));
      const derivable = candidates.some((s) => row.lo >= s.g_per_kg_lo - 1e-9 && row.hi <= s.g_per_kg_hi + 1e-9);
      return { ...row, cited, derivable, note: derivable ? null : 'figures not derivable from the cited primary source(s) — for HG-02 (DEC-03)' };
    });
    mkdirSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts'), { recursive: true });
    writeFileSync(resolve(ROOT, 'evidence', 'FR1-07', 'artefacts', 'band-table-check.json'), JSON.stringify({ generated: new Date().toISOString(), ceiling: primarySources.ceiling_g_per_kg, rows: report }, null, 2) + '\n');
    for (const r of report) expect(r.cited.length, `${r.key} cites a primary source`).toBeGreaterThan(0);
    for (const r of report) expect(r.hi, `${r.key} within the 2.0 ceiling`).toBeLessThanOrEqual(primarySources.ceiling_g_per_kg);
    const notDerivable = report.filter((r) => !r.derivable).map((r) => `${r.key} ${r.lo}-${r.hi} (${r.src})`);
    console.info(`BANDS rows reported for HG-02 (not derivable from the cited source figures): ${notDerivable.join('; ') || 'none'}`);
    // the report itself is the deliverable; the band table is signed at HG-02 (DEC-03) — rows that cite no source or exceed the ceiling fail above
  });
});
