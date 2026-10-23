// TC-FR1-09 Protein life-stage and safety branches — unit + oracle families. Fixtures: FX-PR-PRG-01…04, FX-PR-LIV-01/02,
// FX-PR-UWT-01/02. Mutation: MUT-03. e2e parts (AS-02 banner, AS-03, AS-05) are in tests/e2e/TC-FR1-09.spec.mjs.
import { beforeAll, describe, expect, test } from 'vitest';
import { fixtures } from '../lib/fixtures.mjs';
import { proteinRunner } from '../lib/tool-page.mjs';
import orc02 from '../../oracles/orc-02-protein-ref/results.json' with { type: 'json' };

const orc = Object.fromEntries(orc02.results.map((r) => [r.fixture_id, r.expected]));
let run;
beforeAll(() => { run = proteinRunner(); });

describe('TC-FR1-09 life-stage and safety branches', () => {
  test('TC-FR1-09-AS-01', () => {
    // PRG-01/02 gPerKg 1.1 (pregnant), PRG-03/04 1.3 (lactation); branch pregnancy; dietMult 1.0; capped false; one source —
    // the RDA branch returns before activity/training/goal bands and never stacks the diet multiplier
    const prg = fixtures('FX-PR-PRG-*');
    expect(prg.map((f) => f.id)).toEqual(['FX-PR-PRG-01', 'FX-PR-PRG-02', 'FX-PR-PRG-03', 'FX-PR-PRG-04']);
    for (const f of prg) {
      const got = run(f.inputs);
      expect(got.gPerKg, f.id).toBe(f.inputs.preg === 'pregnant' ? 1.1 : 1.3);
      expect(got.branch, f.id).toBe('pregnancy');
      expect(got.dietMult, f.id).toBe(1.0);
      expect(got.capped, f.id).toBe(false);
      expect(got.sources, f.id).toHaveLength(1);
      expect(got.sources[0], f.id).toMatch(f.inputs.preg === 'pregnant' ? /pregnancy RDA 1\.1/ : /lactation RDA 1\.3/);
      expect(got.targetG, `${f.id} target = RDA × entered weight`).toBe(Math.round(got.gPerKg * f.inputs.weightKg));
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
    }
    // the same inputs without pregnancy take the standard path (resistance 5+ / vegan / fat loss would otherwise dominate)
    for (const f of prg) { const std = run({ ...f.inputs, preg: 'no' }); expect(std.branch).toBe('standard'); expect(std.gPerKg).not.toBe(got_or(f)); }
    function got_or(f) { return f.inputs.preg === 'pregnant' ? 1.1 : 1.3; }
  });

  test('TC-FR1-09-AS-02', () => {
    // LIV-01 (liver yes) and LIV-02 (goal recovery): sources include the ESPEN 1.2–1.5 illness/recovery band
    for (const f of fixtures('FX-PR-LIV-*')) {
      const got = run(f.inputs);
      expect(got.sources.join(' | '), f.id).toMatch(/ESPEN/);
      expect(got.gPerKg, `${f.id} midpoint of ESPEN 1.2–1.5`).toBe(1.35);
      expect(got.interpKey, f.id).toBe('recovery');
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
    }
  });

  test('TC-FR1-09-AS-04', () => {
    // ORC-02 agreement on all PRG/LIV/UWT (±1 g; gPerKg exact at 2 dp; branch/suppressed equal)
    for (const f of [...fixtures('FX-PR-PRG-*'), ...fixtures('FX-PR-LIV-*'), ...fixtures('FX-PR-UWT-*')]) {
      const got = run(f.inputs); const ref = orc[f.id];
      expect(ref, `${f.id} ORC-02 row`).toBeDefined();
      for (const k of ['targetG', 'lowG', 'highG', 'perMeal']) expect(Math.abs(got[k] - ref[k]), `${f.id} ${k} ±1 g`).toBeLessThanOrEqual(1);
      expect(got.gPerKg.toFixed(2), `${f.id} gPerKg`).toBe(ref.gPerKg.toFixed(2));
      expect(got.suppressed, f.id).toBe(ref.suppressed);
      expect(got.branch === 'pregnancy', `${f.id} branch`).toBe(ref.branch === 'pregnancy');
    }
  });
});
