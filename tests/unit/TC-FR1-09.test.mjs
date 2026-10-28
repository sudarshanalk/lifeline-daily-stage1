// TC-FR1-09 Protein life-stage and clinical branches (v2, DEC-86) — unit + oracle families. Fixtures: FX-PR-PRG-01…05,
// FX-PR-LIV-01/02, FX-PR-UWT-01/02. Mutations: MUT-03, MUT-15. e2e parts (AS-02 stop message, AS-03, AS-05) are in
// tests/e2e/TC-FR1-09.spec.mjs.
import { beforeAll, describe, expect, test } from 'vitest';
import { fixtures } from '../lib/fixtures.mjs';
import { proteinRunner } from '../lib/tool-page.mjs';
import orc02 from '../../oracles/orc-02-protein-ref/results.json' with { type: 'json' };

const orc = Object.fromEntries(orc02.results.map((r) => [r.fixture_id, r.expected]));
const ADD = { pregnant: { 1: 1, 2: 9, 3: 31 }, breastfeeding: { under6: 19, '6plus': 12.5 } };   // WHO/FAO/UNU 2007, g a day
let run;
beforeAll(() => { run = proteinRunner(); });

describe('TC-FR1-09 life-stage and clinical branches', () => {
  test('TC-FR1-09-AS-01', () => {
    // PRG-01/02/03 trimesters and PRG-04/05 breastfeeding stages: 0.83 × basis weight + the WHO addition; own branch;
    // no activity, training or goal band (the inputs carry strength training, goal build or fat loss)
    const prg = fixtures('FX-PR-PRG-*');
    expect(prg.map((f) => f.id)).toEqual(['FX-PR-PRG-01', 'FX-PR-PRG-02', 'FX-PR-PRG-03', 'FX-PR-PRG-04', 'FX-PR-PRG-05']);
    for (const f of prg) {
      const got = run(f.inputs);
      const add = ADD[f.inputs.preg][f.inputs.preg === 'pregnant' ? f.inputs.trimester : f.inputs.babyAge];
      expect(add, `${f.id} stage`).toBeDefined();
      expect(got.branch, f.id).toBe('pregnancy');
      expect(got.targetG, `${f.id} target`).toBe(Math.round(0.83 * got.basisKg + add));
      expect(got.lowG, f.id).toBe(got.targetG); expect(got.highG, f.id).toBe(got.targetG);
      expect(got.capped, f.id).toBe(false);
      expect(got.sources, f.id).toHaveLength(1);
      expect(got.sources[0], f.id).toMatch(/WHO\/FAO\/UNU 2007/);
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
      const std = run({ ...f.inputs, preg: 'no' });
      expect(std.branch, `${f.id} without pregnancy`).toBe('standard');
      expect(std.targetG, `${f.id}: the standard bands would give a different figure`).not.toBe(got.targetG);
    }
  });

  test('TC-FR1-09-AS-02', () => {
    // LIV-01 (liver disease or recovery) and LIV-02 (clinician plan): no number, reason clinical / plan
    const reasons = { 'FX-PR-LIV-01': 'clinical', 'FX-PR-LIV-02': 'plan' };
    for (const f of fixtures('FX-PR-LIV-*')) {
      const got = run(f.inputs);
      expect(got.suppressed, f.id).toBe(true);
      expect(got.reason, f.id).toBe(reasons[f.id]);
      for (const k of ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal']) expect(got, `${f.id} has no ${k}`).not.toHaveProperty(k);
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
    }
  });

  test('TC-FR1-09-AS-04', () => {
    // ORC-02 agreement on all PRG (±1 g; gPerKg exact at 2 dp; branch equal) and the clinical stops
    for (const f of fixtures('FX-PR-PRG-*')) {
      const got = run(f.inputs); const ref = orc[f.id];
      expect(ref, `${f.id} ORC-02 row`).toBeDefined();
      for (const k of ['targetG', 'lowG', 'highG', 'perMeal']) expect(Math.abs(got[k] - ref[k]), `${f.id} ${k} ±1 g`).toBeLessThanOrEqual(1);
      expect(got.gPerKg.toFixed(2), `${f.id} gPerKg`).toBe(ref.gPerKg.toFixed(2));
      expect(ref.branch, `${f.id} branch`).toBe('pregnancy');
    }
    for (const f of fixtures('FX-PR-LIV-*')) { expect(orc[f.id]?.suppressed, f.id).toBe(true); expect(orc[f.id].reason, f.id).toBe(run(f.inputs).reason); }
  });
});
