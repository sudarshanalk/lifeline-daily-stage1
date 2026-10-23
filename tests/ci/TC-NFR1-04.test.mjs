// TC-NFR1-04 Isolation of the two pipelines — ci family. Fixtures: FX-MAIL-01, FX-MAIL-02 (SAF-CROSS-01 lives in tests/saf).
// AS-01 the CI run for the latest commit touching api/mailer.php (CMP-06) contains the `saf` job with both the saf-protein and
//       saf-diabetes steps; AS-02 both steps succeeded on that run. The structural half (the workflow declares the job and
//       steps unconditionally for requirement PRs) is checked from the tree; the run half asks GitHub when `gh` is authenticated.
// AS-03 is SAF-CROSS-01 in tests/saf/saf-diabetes.test.mjs (both result e-mails delivered through the real endpoints).
import { describe, expect, test } from 'vitest';
import { execSync, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';

const WF = readFileSync(resolve(ROOT, '.github', 'workflows', 'ci-guards.yml'), 'utf8');
const repo = process.env.GITHUB_REPOSITORY || 'sudarshanalk/lifeline-daily-stage1';
const ghApi = (path, jq) => spawnSync('gh', ['api', path, ...(jq ? ['--jq', jq] : [])], { cwd: ROOT, encoding: 'utf8' });
const online = () => spawnSync('gh', ['auth', 'status'], { encoding: 'utf8' }).status === 0 || !!(process.env.GITHUB_TOKEN || process.env.GH_TOKEN);
/** The latest commit on this branch that touched the shared mailer (CMP-06), and the saf job GitHub ran for it (null when none yet). */
function safJobFor(sha) {
  const runs = ghApi(`repos/${repo}/actions/runs?head_sha=${sha}&per_page=20`, '[.workflow_runs[] | select(.name == "ci-guards")] | sort_by(.created_at) | last | .id');
  const runId = (runs.stdout || '').trim();
  if (!runId || runId === 'null') return null;
  const jobs = ghApi(`repos/${repo}/actions/runs/${runId}/jobs?per_page=100`, '.jobs[] | select(.name == "saf") | {conclusion, steps: [.steps[] | {name, conclusion}]}');
  return jobs.stdout ? JSON.parse(jobs.stdout) : null;
}
const mailerCommit = () => execSync('git log -1 --format=%H -- site/public_html/tools/api/mailer.php', { cwd: ROOT, encoding: 'utf8' }).trim();

describe('TC-NFR1-04 isolation of the two pipelines', () => {
  test('TC-NFR1-04-AS-01', (ctx) => {
    // structural: the saf job exists, runs both suites as named steps, and is conditioned only on the PR not being a SPEC PR
    const job = WF.split(/\n(?=  [a-z][a-z0-9-]*:\s*$)/m).find((b) => /^  saf:\s*$/m.test(b));
    expect(job, 'ci-guards.yml declares a saf job').toBeTruthy();
    expect(job).toMatch(/- name: saf-protein\s*\n\s*run: npx vitest run -c tests\/vitest\.config\.mjs tests\/saf\/saf-protein\.test\.mjs/);
    expect(job).toMatch(/- name: saf-diabetes\s*\n\s*run: npx vitest run -c tests\/vitest\.config\.mjs tests\/saf\/saf-diabetes\.test\.mjs/);
    expect((job.match(/^\s+if:/gm) || []).length, 'no path filter: every requirement PR runs both SAF suites').toBeLessThanOrEqual(1);
    if ((job.match(/^\s+if:/gm) || []).length === 1) expect(job).toMatch(/if: "!startsWith\(github\.event\.pull_request\.title \|\| '', 'SPEC: '\)"/);
    expect(WF).not.toMatch(/paths(-ignore)?:\s*\n/);
    // run: the latest commit touching the shared mailer carries a saf job with both steps
    if (!online()) { ctx.skip('gh is not authenticated: the structural half is the assertion offline'); return; }
    const sha = mailerCommit();
    const saf = safJobFor(sha);
    if (!saf) { ctx.skip(`GitHub has no ci-guards run yet for ${sha.slice(0, 7)} (the commit touching api/mailer.php on this branch)`); return; }
    expect(saf.steps.map((s) => s.name), `saf job steps on ${sha.slice(0, 7)}`).toEqual(expect.arrayContaining(['saf-protein', 'saf-diabetes']));
  });

  test('TC-NFR1-04-AS-02', (ctx) => {
    if (!online()) { ctx.skip('gh is not authenticated'); return; }
    const sha = mailerCommit();
    const saf = safJobFor(sha);
    if (!saf) { ctx.skip(`GitHub has no ci-guards run yet for ${sha.slice(0, 7)}`); return; }
    expect(saf.conclusion, `saf job on ${sha.slice(0, 7)}`).toBe('success');
    for (const name of ['saf-protein', 'saf-diabetes']) expect(saf.steps.find((s) => s.name === name)?.conclusion, `${name} step`).toBe('success');
  });

  test('TC-NFR1-04-AS-03', async () => {
    // SAF-CROSS-01 (tests/saf/saf-diabetes.test.mjs) is the mandatory form; this is the same check under the TC id:
    // after the shared mail layer changed, FX-MAIL-01 and FX-MAIL-02 are both delivered through the real endpoints.
    const { startHtaccessServer } = await import('../lib/htaccess-server.mjs');
    const { fixtures } = await import('../lib/fixtures.mjs');
    const { existsSync, readdirSync } = await import('node:fs');
    const srv = await startHtaccessServer();
    try {
      const outboxDir = resolve(srv.docroot, 'tools', 'api', 'outbox');
      const outbox = () => existsSync(outboxDir) ? readdirSync(outboxDir).filter((f) => !f.startsWith('.')) : [];
      for (const [id, path] of [['FX-MAIL-01', '/tools/submit-protein.php'], ['FX-MAIL-02', '/tools/submit.php']]) {
        const f = fixtures(id)[0];
        const before = outbox().length;
        const r = await fetch(srv.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': '10.4.0.2' }, body: JSON.stringify(f.inputs) });
        expect(r.status, `${id} delivered`).toBe(f.expected.status);
        expect(outbox().length - before, `${id}: exactly one message`).toBe(f.expected.mails);
      }
    } finally { await srv.close(); }
  }, 60000);
});
