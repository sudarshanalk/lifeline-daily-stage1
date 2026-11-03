// NFR3-11 (P3-82): the judging logic of tools/seo/speed.mjs over fixture medians, no browser. The staging lab run is
// documented in tools/seo/README.md. TC-NFR3-11-AS-02 (saving a log entry in under 1 second) needs a signed-in member
// and is P3-50's test, not this package's.
import { expect, test } from 'vitest';
import { judgeVitals, SPEED_BUDGETS } from '../../../tools/seo/lib.mjs';

test('TC-NFR3-11-AS-01', () => {
  expect(SPEED_BUDGETS).toEqual({ lcp_ms: 2500, inp_ms: 200, tbt_ms: 200, cls: 0.1 });
  // Within budget on all three, with INP measured, passes and is judged on INP.
  const ok = judgeVitals({ lcp_ms: 1800, cls: 0.02, inp_ms: 120, tbt_ms: 350 });
  expect(ok.ok).toBe(true);
  expect(ok.responsiveness_metric).toBe('INP');
  // Without a measured INP, TBT stands in and the judgement says so.
  const proxy = judgeVitals({ lcp_ms: 1800, cls: 0.02, inp_ms: null, tbt_ms: 150 });
  expect(proxy.ok).toBe(true);
  expect(proxy.responsiveness_metric).toMatch(/^TBT .*lab proxy for INP/);
  expect(judgeVitals({ lcp_ms: 1800, cls: 0.02, tbt_ms: 250 }).pass.responsiveness).toBe(false);
  // Each budget is a strict "under": the boundary values fail, just under passes.
  expect(judgeVitals({ lcp_ms: 2500, cls: 0.05, inp_ms: 100 }).pass).toEqual({ lcp: false, responsiveness: true, cls: true });
  expect(judgeVitals({ lcp_ms: 2499, cls: 0.1, inp_ms: 100 }).pass).toEqual({ lcp: true, responsiveness: true, cls: false });
  expect(judgeVitals({ lcp_ms: 2499, cls: 0.099, inp_ms: 200 }).pass).toEqual({ lcp: true, responsiveness: false, cls: true });
  expect(judgeVitals({ lcp_ms: 2499, cls: 0.099, inp_ms: 199 }).ok).toBe(true);
  // A metric that was not measured fails rather than passing by default.
  const missing = judgeVitals({ lcp_ms: null, cls: 0.01, inp_ms: 50 });
  expect(missing.ok).toBe(false);
  expect(missing.pass.lcp).toBe(false);
  expect(judgeVitals({}).ok).toBe(false);
  expect(judgeVitals({ lcp_ms: 'fast', cls: 0.01, inp_ms: 50 }).values.lcp_ms).toBeNull();
});
