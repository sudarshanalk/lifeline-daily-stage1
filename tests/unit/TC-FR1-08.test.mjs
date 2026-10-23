// TC-FR1-08 Protein renal suppression — unit family (AS-01). Fixtures: FX-PR-REN-01…04, FX-PR-UNS-01. Mutations: MUT-01, MUT-02.
// e2e assertions (AS-02, AS-03, AS-05, AS-06) are in tests/e2e/TC-FR1-08.spec.mjs; php assertions (AS-04, AS-07) in tests/php/TC-FR1-08.test.mjs.
import { beforeAll, describe, expect, test } from 'vitest';
import { fixtures } from '../lib/fixtures.mjs';
import { proteinRunner } from '../lib/tool-page.mjs';

let run;
beforeAll(() => { run = proteinRunner(); });

describe('TC-FR1-08 protein renal suppression', () => {
  test('TC-FR1-08-AS-01', () => {
    // REN-01…04 → suppressed === true, reason 'renal', no numeric keys at all
    const ren = fixtures('FX-PR-REN-*');
    expect(ren.map((f) => f.id)).toEqual(['FX-PR-REN-01', 'FX-PR-REN-02', 'FX-PR-REN-03', 'FX-PR-REN-04']);
    for (const f of ren) {
      const got = run(f.inputs);
      expect(got.suppressed, f.id).toBe(true);
      expect(got.reason, f.id).toBe('renal');
      for (const k of ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal']) expect(got, `${f.id} has no ${k}`).not.toHaveProperty(k);
      // bmi (an input-derived value, not a protein figure) is the only number the suppressed result may carry
      expect(Object.keys(got).filter((k) => typeof got[k] === 'number'), `${f.id}: numeric keys`).toEqual(['bmi']);
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
    }
    // kidney unsure is NOT suppressed (computed, flagged provisional) — the two states must differ
    const uns = run(fixtures('FX-PR-UNS-01')[0].inputs);
    expect(uns.suppressed).toBeFalsy();
    expect(uns.targetG).toBeGreaterThan(0);
  });
});
