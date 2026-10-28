// TC-NFR1-02 Clinical governance (process, hybrid, human gate HG-02). The assertions read the records the clinical reviewer
// signs; this repository never writes or simulates them. While all three HG-02 records are untouched (every decision still
// `pending`), each assertion first checks that the gate check correctly refuses the current records, then is skipped with the
// HG-02 reason. Once any HG-02 record carries a decision, or one is missing or unreadable, the assertions run strictly; so does
// LLD_STRICT_GATES=1 (the release-tag `clinical-gate` run and the red-before-green run).
// AS-01 approvals/{protein,diabetes}.json clinical_signoff.{name,date,decision} present, decision `approved` (+ copy deck);
// AS-02 0 DEC rows with ratify_at HG-02 and status outside {ratified, overridden};
// AS-03 a `clinical-gate` CI job exists on release tags (.github/workflows/release-gates.yml, DEC-80) and runs
// tests/ci/clinical-gate.mjs, which exits 0.
import { describe, expect, test } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { ROOT } from '../lib/paths.mjs';
import { signoffProblems, ratificationProblems, gateHeld } from '../ci/clinical-gate.mjs';

const strict = process.env.LLD_STRICT_GATES === '1' || gateHeld(ROOT);
// HG-02 covers the two Stage 1 tools; the Healthy Weight Check (FR2-39) has its own sign-off, required by the release gate
// itself (FR2-39 AS-13) and not by this Stage 1 governance check
const STAGE1 = ['protein', 'diabetes'];
const PENDING = 'HG-02 not yet held: approvals/{protein,diabetes,copy}.json decisions are pending (OI-8 reviewer to be appointed)';
const workflows = () => readdirSync(resolve(ROOT, '.github', 'workflows')).filter((f) => /\.ya?ml$/.test(f)).map((f) => ({ f, src: readFileSync(resolve(ROOT, '.github', 'workflows', f), 'utf8') }));
/** the `clinical-gate:` job block of a workflow, or null */
const jobBlock = (src) => { const m = src.match(/^ {2}clinical-gate:\s*\n((?: {4,}.*\n|\s*\n)*)/m); return m ? m[1] : null; };

describe('TC-NFR1-02 clinical governance', () => {
  test('TC-NFR1-02-AS-01', (ctx) => {
    const problems = signoffProblems(ROOT, STAGE1);
    if (!strict) {
      // the check is not vacuous: today's placeholder records are refused for the right reasons
      for (const slug of ['protein', 'diabetes']) {
        expect(problems, `${slug}: pending decision refused`).toContain(`${slug}: clinical_signoff.decision is 'pending', not 'approved'`);
        expect(problems.some((p) => p.startsWith(`${slug}: clinical_signoff.name is not a named reviewer`)), `${slug}: placeholder name refused`).toBe(true);
      }
      expect(problems, 'copy deck: pending decision refused').toContain("copy: decision is 'pending', not 'approved'");
      expect(problems.some((p) => p.startsWith('copy: reviewer is not a named reviewer')), 'copy deck: placeholder reviewer refused').toBe(true);
      return ctx.skip(PENDING);
    }
    expect(problems, 'clinical sign-off named, dated and approved').toEqual([]);
  });

  test('TC-NFR1-02-AS-02', (ctx) => {
    const problems = ratificationProblems(ROOT);
    const reg = JSON.parse(readFileSync(resolve(ROOT, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
    const due = reg.decisions.filter((d) => d.ratify_at === 'HG-02');
    expect(due.length, 'DEC rows due at HG-02').toBeGreaterThan(0);
    if (!strict) {
      expect(problems.length, 'every unratified HG-02 row is reported').toBe(due.filter((d) => !['ratified', 'overridden'].includes(d.status)).length);
      return ctx.skip(`${PENDING}; ${problems.length} DEC rows await ratification`);
    }
    expect(problems, 'HG-02 DEC rows ratified or overridden').toEqual([]);
  });

  test('TC-NFR1-02-AS-03', (ctx) => {
    const run = spawnSync(process.execPath, [resolve(ROOT, 'tests', 'ci', 'clinical-gate.mjs'), '--tools', STAGE1.join(',')], { cwd: ROOT, encoding: 'utf8' });
    const jobs = workflows().map((w) => ({ ...w, block: jobBlock(w.src) })).filter((w) => w.block);
    // the job definition is checked on every run; only its green result waits for HG-02
    expect(jobs.map((j) => j.f), 'exactly one clinical-gate job is defined').toHaveLength(1);
    const { f, src, block } = jobs[0];
    expect(src, 'triggered by release tags').toMatch(/tags:\s*\[?['"]?rc-\*/);
    expect(block, 'runs the gate check').toMatch(/node tests\/ci\/clinical-gate\.mjs/);
    if (!strict) {
      expect(run.status, `gate check refuses pending records\n${run.stderr}`).toBe(1);
      return ctx.skip(`${PENDING}; the clinical-gate job (${f}, rc-* tags) stays red until then`);
    }
    expect(run.status, `clinical-gate exits 0\n${run.stderr}`).toBe(0);
  });
});
