// Mandatory safety regression — Healthy Weight Check (Stage 2 FR2-39). Runs on every PR, like saf-protein and saf-diabetes.
// Replays every fixture through the deployed page (jsdom) and requires the ORC-05 `expected` exactly: the reference was
// written from the requirement, so this proves the page is correct as well as unchanged.
import { beforeAll, describe, expect, test } from 'vitest';
import { fixtures } from '../lib/fixtures.mjs';
import { healthyWeightRunner } from '../lib/tool-page.mjs';

let run;
beforeAll(() => { run = healthyWeightRunner(); });

function replay(pattern, extra = () => {}) {
  const fxs = fixtures(pattern).filter((f) => f.oracle === 'clinical' && f.expected);
  expect(fxs.length, pattern).toBeGreaterThan(0);
  for (const f of fxs) {
    const { shown, ...got } = run(f.inputs);
    expect(got, `${f.id} ${f.purpose}`).toEqual(f.expected);
    extra(f, got, shown);
  }
}

describe('SAF healthy weight', () => {
  test('SAF-HW-01', () => {
    // Stops: under 18, pregnancy or breastfeeding (yes / not sure), eating disorder; no number is ever shown
    replay('FX-HW-SAF-*', (f, got, shown) => { if (got.stop) expect(shown, f.id).not.toMatch(/\d/); });
    replay('FX-HW-INV-01', (f, got) => expect(got.stop, f.id).toBe('under18'));
  });

  test('SAF-HW-02', () => {
    // Underweight and unintended weight loss never offer the track; underweight carries the eating-support line
    replay('FX-HW-SAF-*', (f, got, shown) => {
      if (got.stop) return;
      if (got.category === 'underweight' || got.weightLoss) expect(got.onward, f.id).toBe(false);
      if (got.category === 'underweight') expect(shown, f.id).toContain('findahelpline.com');
    });
  });

  test('SAF-HW-03', () => {
    // Every category boundary and rounding transition, both threshold sets, every background mapping
    replay('FX-HW-BMI-*');
    replay('FX-HW-RANGE-*');
    replay('FX-HW-UNIT-*');
  });

  test('SAF-HW-04', () => {
    // Waist-to-height ratio bands and the BMI-35 rule
    replay('FX-HW-WHTR-*', (f, got) => { if (got.waist && got.bmi >= 35) expect(got.waist.band, f.id).toBe('notShown'); });
  });

  test('SAF-HW-05', () => {
    // Invalid input never produces a result
    replay('FX-HW-INV-*', (f, got) => { if (f.expected.errors) expect(got.bmi, f.id).toBeUndefined(); });
  });
});
