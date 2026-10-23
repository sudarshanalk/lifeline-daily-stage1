// Mandatory safety regression — diabetes (docs/03 §7). Runs on every PR.
// Replays fixtures through the deployed engine (jsdom) and requires the characterisation `expected` exactly.
// Proves "unchanged since the 23 Oct 2026 baseline", never clinical correctness (docs/04 §9).
import { beforeAll, describe, expect, test } from 'vitest';
import { fixtures } from '../lib/fixtures.mjs';
import { diabetesRunner } from '../lib/tool-page.mjs';

let run;
beforeAll(() => { run = diabetesRunner(); });

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

describe('SAF diabetes', () => {
  test('SAF-DM-01', () => {
    // Emergency: FX-DM-EMG-01/02 — emergency flag set and the on-screen score suppressed
    replay(['FX-DM-EMG-*'], (f, got) => { expect(got.emergency, f.id).toBe(true); expect(got.scoreSuppressedOnScreen, f.id).toBe(true); });
  });
  test('SAF-DM-02', () => {
    // Classic symptoms: FX-DM-SYM-01…03 — symptomatic flag follows the classic-symptom rule
    replay(['FX-DM-SYM-*'], (f, got) => { expect(typeof got.symptomatic, f.id).toBe('boolean'); });
  });
  test('SAF-DM-03', () => {
    // Band boundaries: FX-DM-BAND-01…12 are clinical fixtures filled by ORC-01 (pending Docker, OI-4);
    // until then the boundary rule is checked on the recorded QD fixtures: band must follow the unrounded pct (DEC-04)
    const bands = fixtures('FX-DM-BAND-*');
    const filled = bands.filter((f) => f.expected);
    for (const f of filled) expect(run(f.inputs), f.id).toEqual(f.expected);
    replay(['FX-DM-QD-*', 'FX-DM-QD-CLAMP-*'], (f, got) => {
      const want = got.pct < 5.6 ? 'low' : got.pct < 10 ? 'moderate' : got.pct < 20 ? 'high' : 'veryHigh';
      expect(got.band, f.id).toBe(want);
    });
  });
  test('SAF-DM-04', () => {
    // Secondary indicators: IDRS, WHtR, CONC, NOWAIST
    replay(['FX-DM-IDRS-*', 'FX-DM-WHTR-*', 'FX-DM-CONC-*', 'FX-DM-NOWAIST-*'], (f, got) => {
      if (f.inputs.waistCm) { expect(got.idrs, f.id).toBeDefined(); expect(got.whtr, f.id).toBeDefined(); }
      else { expect(got.idrs, f.id).toBeUndefined(); expect(got.whtr, f.id).toBeUndefined(); }
    });
  });
  test.skip('SAF-CROSS-01', () => {
    // Shared change isolation (TC-NFR1-04-AS-01…03) needs the PHP endpoint harness — wave 3 (FR1-14/NFR1-04)
  });
  test.skip('SAF-WITHDRAW-01', () => {
    // Withdrawal (TC-FR1-15-AS-01/02) needs the status.json reader in tools and endpoints — wave 4 (FR1-15)
  });
});
