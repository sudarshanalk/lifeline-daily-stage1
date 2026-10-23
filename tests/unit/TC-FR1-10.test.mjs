// TC-FR1-10 Diabetes engine fidelity verification — oracle, unit, ci families. Fixtures: FX-DM-QD-01…74, FX-DM-QD-CLAMP-01…04.
// Mutation: MUT-04. ORC-01 (R QDiabetes 1.0-2) cannot run on this machine (Docker/WSL 2 pending, OI-4 — deferred to HG-02
// in approvals/hg-01.json): AS-01 runs the comparison when oracles/orc-01-qdiabetes-r/results.json exists and otherwise
// records the characterisation agreement and skips with that reason; AS-04 proves the gate mechanics in a sandbox.
import { beforeAll, describe, expect, test } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, writeFileSync, rmSync, mkdirSync, cpSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { ROOT, toolPath } from '../lib/paths.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { diabetesRunner } from '../lib/tool-page.mjs';
import { fidelityGate } from '../ci/fidelity-gate.mjs';
import { mutatedTree } from '../lib/mutate.mjs';
import approval from '../../approvals/diabetes.json' with { type: 'json' };
import pins from '../../oracles/orc-01-qdiabetes-r/pins.json' with { type: 'json' };
import hg01 from '../../approvals/hg-01.json' with { type: 'json' };

const ORC01 = resolve(ROOT, 'oracles', 'orc-01-qdiabetes-r', 'results.json');
let run;
beforeAll(() => { run = diabetesRunner(); });

describe('TC-FR1-10 diabetes engine fidelity verification', () => {
  test('TC-FR1-10-AS-01', (ctx) => {
    // every QD fixture: abs(js.pct − orc01.pct) ≤ 0.05 unrounded; displayed 1-dp value = round(js.pct, 1)
    const qd = fixtures('FX-DM-QD-*').filter((f) => !f.id.includes('CLAMP'));
    expect(qd.length).toBe(74);
    for (const f of qd) {
      const got = run(f.inputs);
      expect(got.pct, `${f.id} characterisation (unchanged)`).toBe(f.expected.pct);
      expect(got.pctDisplay, `${f.id} displayed 1 dp`).toBe(+got.pct.toFixed(1));
    }
    if (!existsSync(ORC01)) {
      expect(hg01.deferred_items.some((d) => /ORC-01/.test(d))).toBe(true);
      return ctx.skip('ORC-01 results pending (Docker/WSL 2, OI-4; deferred to HG-02 in approvals/hg-01.json) — characterisation agreement recorded, correctness not yet claimed');
    }
    const orc = JSON.parse(readFileSync(ORC01, 'utf8'));
    const ref = Object.fromEntries(orc.results.map((r) => [r.fixture_id, r.expected?.pct]));
    for (const f of qd) {
      expect(typeof ref[f.id], `${f.id} ORC-01 row`).toBe('number');
      expect(Math.abs(run(f.inputs).pct - ref[f.id]), `${f.id} |js − R| ≤ 0.05 pp`).toBeLessThanOrEqual(0.05);
    }
  });

  test('TC-FR1-10-AS-02', () => {
    // BMI clamp: CLAMP-01/02 (BMI 19) equal the output at BMI 20; CLAMP-03/04 (BMI 41) equal BMI 40, same sex/age
    for (const f of fixtures('FX-DM-QD-CLAMP-*')) {
      const got = run(f.inputs);
      expect(got, `${f.id} characterisation`).toEqual(f.expected);
      const bmi = f.inputs.weightKg / (f.inputs.heightCm / 100) ** 2;
      const clampedBmi = bmi < 20 ? 20 : 40;
      const atBoundary = run({ ...f.inputs, weightKg: +(clampedBmi * (f.inputs.heightCm / 100) ** 2).toFixed(6) });
      expect(bmi < 20 || bmi > 40, `${f.id} is outside 20–40`).toBe(true);
      expect(got.pct, `${f.id} equals the pct at BMI ${clampedBmi}`).toBeCloseTo(atBoundary.pct, 5);
    }
  });

  test('TC-FR1-10-AS-04', () => {
    // sandbox: MUT-04 (Female s0 → 0.980) makes fidelity-gate write STAGING status.json diabetes withdrawn with message key
    // unavailable.diabetes and emit an alert; the job has no production credential (env inspection)
    for (const k of ['PROD_SSH_HOST', 'PROD_SSH_KEY', 'PROD_SSH_USER', 'PROD_DOCROOT']) expect(process.env[k], `${k} must not be set`).toBeUndefined();
    // a synthetic ORC-01 file from the current (unmutated) engine stands in for the R results until OI-4 — the mechanics are what AS-04 tests
    const sandbox = mkdtempSync(join(tmpdir(), 'lld-fidelity-'));
    try {
      const qd = fixtures('FX-DM-QD-*').filter((f) => !f.id.includes('CLAMP'));
      const orc = { oracle_id: 'ORC-01', implementation: 'sandbox stand-in (engine output, unmutated)', versions: { R: 'n/a' }, run_at: new Date().toISOString(), results: qd.map((f) => ({ fixture_id: f.id, expected: { pct: run(f.inputs).pct } })) };
      const orcPath = join(sandbox, 'results.json'); writeFileSync(orcPath, JSON.stringify(orc));
      const statusPath = join(sandbox, 'status.json'); cpSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), statusPath);
      // unmutated engine: no action, status untouched
      const clean = fidelityGate({ root: ROOT, orcPath, statusPath });
      expect(clean.ok).toBe(true); expect(clean.action).toBe('none');
      expect(JSON.parse(readFileSync(statusPath, 'utf8')).tools.diabetes.status).toBe('active');
      // MUT-04 applied in a temp tree → disagreement → staging status withdrawn + alert + exit 1
      const mutRoot = mutatedTree('MUT-04', ['fixtures/diabetes', 'manifests/diabetes.json']);
      try {
        const errors = []; const orig = console.error; console.error = (m) => errors.push(String(m));
        let r; try { r = fidelityGate({ root: mutRoot, orcPath, statusPath }); } finally { console.error = orig; }
        expect(r.ok).toBe(false);
        expect(r.action).toBe(`withdrawn:${statusPath}`);
        expect(r.problems.length).toBeGreaterThan(0);
        expect(errors.join('\n')).toMatch(/ALERT fidelity-gate/);
        const st = JSON.parse(readFileSync(statusPath, 'utf8'));
        expect(st.tools.diabetes.status).toBe('withdrawn');
        expect(st.tools.diabetes.message).toBe('unavailable.diabetes');
        expect(st.tools.diabetes.since).toMatch(/^\d{4}-\d{2}-\d{2}T/);
        expect(st.tools.protein.status).toBe('active'); // other tool unaffected
      } finally { rmSync(mutRoot, { recursive: true, force: true }); }
      // the real status.json template in the tree is untouched
      expect(JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8')).tools.diabetes.status).toBe('active');
    } finally { rmSync(sandbox, { recursive: true, force: true }); }
    // a production credential in the environment makes the gate refuse to run at all
    process.env.PROD_SSH_HOST = 'x'; try { expect(fidelityGate({ root: ROOT }).action).toBe('refused'); } finally { delete process.env.PROD_SSH_HOST; }
  });

  test('TC-FR1-10-AS-05', (ctx) => {
    // ORC-01 results pin the tarball SHA-256, package 1.0-2, R version, AGPL-3 / research-use disclaimer
    expect(pins.version).toBe('1.0-2'); expect(pins.licence).toBe('AGPL-3');
    expect(approval.licence).toMatch(/AGPL-3/); expect(approval.licence).toMatch(/research-use/);
    if (!existsSync(ORC01)) return ctx.skip('ORC-01 results pending (OI-4); pins.json present with null tarball_sha256/image_digest until the first run');
    const r = JSON.parse(readFileSync(ORC01, 'utf8'));
    expect(r.versions.tarball_sha256).toMatch(/^[0-9a-f]{64}$/); expect(r.versions.QDiabetes).toBe('1.0-2'); expect(r.versions.R).toMatch(/^4\./);
    expect(r.implementation + ' ' + (r.versions.licence || '')).toMatch(/AGPL-3/); expect(r.implementation).toMatch(/research-use/);
  });
});
