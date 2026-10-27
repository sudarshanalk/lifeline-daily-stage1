// Mandatory safety regression — protein (docs/03 §7). Runs on every PR.
// Until the wave-2 TC-FR1-07/08/09 assertions exist, each SAF step replays its fixtures through the deployed
// engine (jsdom, tests/lib/tool-page.mjs) and requires the characterisation `expected` to be reproduced exactly.
// This proves "unchanged since the 23 Oct 2026 baseline", never clinical correctness (docs/04 §9).
import { beforeAll, describe, expect, test } from 'vitest';
import { fixtures } from '../lib/fixtures.mjs';
import { proteinRunner } from '../lib/tool-page.mjs';

let run;
beforeAll(() => { run = proteinRunner(); });

function replay(patterns, extra = () => {}) {
  const fxs = patterns.flatMap((p) => fixtures(p));
  const recorded = fxs.filter((f) => f.oracle === 'characterisation' && f.expected);
  expect(recorded.length, 'fixtures with recorded characterisation values').toBeGreaterThan(0);
  for (const f of recorded) {
    const got = run(f.inputs);
    expect(got, f.id).toEqual(f.expected);
    extra(f, got);
  }
  return recorded;
}

describe('SAF protein', () => {
  test('SAF-PR-01', () => {
    // Renal yes (FX-PR-REN-01…04) and the clinical stops (FX-PR-LIV-01/02, DEC-86): suppressed, no numeric target anywhere
    replay(['FX-PR-REN-*', 'FX-PR-LIV-*'], (f, got) => {
      expect(got.suppressed, f.id).toBe(true);
      for (const k of ['targetG', 'lowG', 'highG', 'gPerKg', 'perMeal']) expect(got[k], `${f.id} ${k}`).toBeUndefined();
    });
  });
  test('SAF-PR-02', () => {
    // Renal unsure: FX-PR-UNS-01 — computed, flagged provisional (not suppressed)
    replay(['FX-PR-UNS-*'], (f, got) => { expect(got.suppressed, f.id).toBeFalsy(); expect(got.targetG, f.id).toBeGreaterThan(0); });
  });
  test('SAF-PR-03', () => {
    // Pregnancy / breastfeeding: FX-PR-PRG-01…05 — own WHO branch, no stacking
    replay(['FX-PR-PRG-*'], (f, got) => { expect(got.branch, f.id).toMatch(/preg|lact|breast/i); });
  });
  test('SAF-PR-04', () => {
    // Cap and basis weight: FX-PR-CAP-01…03, FX-PR-BMI-01…04 — never above 2.0 g/kg
    replay(['FX-PR-CAP-*', 'FX-PR-BMI-*'], (f, got) => { if (got.gPerKg != null) expect(got.gPerKg, f.id).toBeLessThanOrEqual(2.0); });
  });
});
