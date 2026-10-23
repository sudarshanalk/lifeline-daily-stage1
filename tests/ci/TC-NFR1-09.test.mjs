// TC-NFR1-09 Reproducible AI development — families ci + artefact. No fixtures.
import { describe, expect, test } from 'vitest';
import { execSync, spawnSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { validateFile } from '../lib/validate.mjs';
import { oracleGuard } from './oracle-guard.mjs';

const WORKFLOWS = ['ci.yml', 'ci-guards.yml'].map((f) => readFileSync(resolve(ROOT, '.github', 'workflows', f), 'utf8'));
const ci = WORKFLOWS.join('\n');
const loopDoc = readFileSync(resolve(ROOT, 'docs', '04_AI_Development_Loop_v1_2.md'), 'utf8');
/** Job ids declared across both workflows (each file parsed on its own, so an `on:` block never leaks ids). */
const jobIds = () => WORKFLOWS.flatMap((w) => [...(w.split(/\njobs:\n/)[1] || '').matchAll(/^  ([a-z][a-z0-9-]*):\s*$/gm)].map((x) => x[1]));
/** Jobs the docs/04 §1 table marks as running on PR (first column, backticked names; family cells may list several). */
const prJobsFromTable = () => {
  const rows = loopDoc.split('\n').filter((l) => /^\| `/.test(l) && /\| PR/.test(l));
  // backticked names in the Job column are job ids; a "(steps `a`, `b`)" parenthetical names steps inside a job
  return rows.flatMap((r) => [...r.split('|')[1].replace(/\(steps[^)]*\)/g, '').matchAll(/`([a-z0-9-]+)`/g)].map((m) => m[1]));
};

describe('TC-NFR1-09 reproducible AI development', () => {
  test('TC-NFR1-09-AS-01', () => {
    // PR title regex check job present and enforced; body must contain an evidence/ path
    expect(jobIds()).toContain('pr-title');
    const job = ci.split(/\n  pr-title:\n/)[1].split(/\n  [a-z][a-z0-9-]*:\n/)[0];
    // the grep pattern the job enforces (title "<ID>: <title>", trailing space required), not just the message text
    expect(job).toMatch(/grep -Eq '\^\(FR1\|NFR1\|UI1\)-\[0-9\]\{2\}: '/);
    expect(job).toMatch(/grep -Eq '\^SPEC: '/); // specification PRs (DEC-55) are the one other accepted class
    expect(job).toMatch(/evidence\//);
    expect(job).toMatch(/exit 1/);
    // the convention holds on the PR under test when GitHub gives us a title (CI), otherwise on the last merged PR titles
    const title = process.env.PR_TITLE;
    if (title) expect(title).toMatch(/^(FR1|NFR1|UI1)-\d{2}: /);
    // every requirement PR merged since HG-01 (#2 onward; #1 was the repository skeleton) came from a req/<ID> branch
    const merged = execSync('git log --merges --format=%s -n 20 origin/staging', { cwd: ROOT, encoding: 'utf8' }).split('\n').filter((l) => /^Merge pull request #(?!1\b)\d+ from sudarshanalk\/req\//.test(l));
    expect(merged.length).toBeGreaterThan(0);
    for (const m of merged) expect(m).toMatch(/req\/(FR1|NFR1|UI1)-\d{2}/);
  });

  test('TC-NFR1-09-AS-02', () => {
    // every job marked PR in the docs/04 §1 job table exists in ci.yml and ran on the last merged PR
    const required = prJobsFromTable();
    expect(required.length).toBeGreaterThan(10);
    const ids = jobIds();
    const missing = required.filter((j) => !ids.includes(j) && !(j === 'fidelity-gate' && ids.includes('fidelity-gate')));
    // fidelity-gate needs ORC-01 (Docker/OI-4) and staging SSH (OI-1): declared but conditional until then
    expect(missing, `jobs in the docs/04 table but not in ci.yml`).toEqual([]);
    // GitHub Free: no branch protection, so "required" is checked by inspecting the last merged PR: every PR-marked job
    // that the merged PR's OWN workflow files declared (both workflows, read from its head commit) must have a check-run
    // with conclusion success on that head. Strict — no warning path. Jobs the table names but that commit did not yet
    // declare are reported (they could not have run) and are covered by the structural check above for the current tree.
    const gh = spawnSync('gh', ['auth', 'status'], { encoding: 'utf8' });
    if (gh.status !== 0 && !process.env.GITHUB_TOKEN && !process.env.GH_TOKEN) return; // offline: the structural part above is the assertion
    // REST calls with an explicit repository: `gh pr list` needs a configured default repo, which an Actions checkout lacks
    const repo = process.env.GITHUB_REPOSITORY || 'sudarshanalk/lifeline-daily-stage1';
    const ghApi = (path, jq) => spawnSync('gh', ['api', path, ...(jq ? ['--jq', jq] : [])], { cwd: ROOT, encoding: 'utf8' });
    const merged = ghApi(`repos/${repo}/pulls?state=closed&base=staging&sort=updated&direction=desc&per_page=20`, '[.[] | select(.merged_at != null)] | sort_by(.merged_at) | last | {number, title, head: .head.sha}');
    expect(merged.status, merged.stderr).toBe(0);
    const pr = JSON.parse(merged.stdout || 'null');
    if (!pr) return;
    const head = pr.head; const prs = [pr];
    // make sure the merged PR's head commit is available locally (CI checkouts may be shallow / lack the branch)
    if (spawnSync('git', ['cat-file', '-e', head], { cwd: ROOT }).status !== 0) spawnSync('git', ['fetch', '-q', 'origin', head], { cwd: ROOT });
    const declared = new Set(); const specSkipped = new Set();
    for (const f of ['ci.yml', 'ci-guards.yml']) {
      const text = spawnSync('git', ['show', `${head}:.github/workflows/${f}`], { cwd: ROOT, encoding: 'utf8' });
      if (text.status !== 0) continue; // workflow did not exist at that commit
      for (const block of (text.stdout.split(/\njobs:\n/)[1] || '').split(/^(?=  [a-z][a-z0-9-]*:\s*$)/m)) {
        const m = block.match(/^  ([a-z][a-z0-9-]*):\s*$/m); if (!m) continue;
        declared.add(m[1]);
        // a job whose `if:` excludes SPEC PRs (DEC-55) is skipped by design when the merged PR is a SPEC PR
        if (/^\s+if:.*SPEC: /m.test(block)) specSkipped.add(m[1]);
      }
    }
    const isSpec = /^SPEC: /.test(pr.title || '');
    expect(declared.size, `workflow files of ${head} readable`).toBeGreaterThan(0);
    const api = ghApi(`repos/${repo}/commits/${head}/check-runs?per_page=100`, '.check_runs[] | [.name, .conclusion] | @tsv');
    expect(api.status, api.stderr).toBe(0);
    const byName = Object.fromEntries(api.stdout.split(/\r?\n/).filter(Boolean).map((l) => l.split('\t')));
    const mustHaveRun = required.filter((j) => declared.has(j));
    const notDeclared = required.filter((j) => !declared.has(j));
    if (notDeclared.length) console.info(`last merged PR #${prs[0].number} (${head.slice(0, 7)}) did not yet declare: ${notDeclared.join(', ')}`);
    expect(mustHaveRun.length).toBeGreaterThan(0);
    for (const j of mustHaveRun) {
      // SPEC PRs (DEC-55) legitimately skip the test-family and requirement-scope jobs; every other job must have succeeded
      if (isSpec && specSkipped.has(j)) { expect(byName[j], `SPEC PR #${prs[0].number}: job ${j}`).toMatch(/^(success|skipped)$/); continue; }
      expect(byName[j], `last merged PR #${prs[0].number}: job ${j}`).toBe('success');
    }
  });

  test('TC-NFR1-09-AS-03', () => {
    // a change to fixtures/protein/nrm.json#expected by a non-bot author fails oracle-guard
    const repo = mkdtempSync(join(tmpdir(), 'lld-guard-'));
    try {
      const git = (c) => execSync(`git ${c}`, { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
      git('init -q -b staging'); git('config user.email dev@example.test'); git('config user.name "Dev Person"'); git('config commit.gpgsign false');
      mkdirSync(join(repo, 'fixtures', 'protein'), { recursive: true });
      writeFileSync(join(repo, 'fixtures', 'protein', 'nrm.json'), readFileSync(resolve(ROOT, 'fixtures', 'protein', 'nrm.json')));
      git('add -A'); git('commit -q -m "FR1-01: baseline"');
      git('checkout -q -b req/FR1-07');
      const doc = JSON.parse(readFileSync(join(repo, 'fixtures', 'protein', 'nrm.json'), 'utf8'));
      doc.fixtures[0].expected.targetG = (doc.fixtures[0].expected.targetG || 0) + 1;
      writeFileSync(join(repo, 'fixtures', 'protein', 'nrm.json'), JSON.stringify(doc, null, 2));
      git('commit -q -am "FR1-07: tweak expected"');
      const r = oracleGuard({ root: repo, base: 'staging', head: 'HEAD' });
      expect(r.ok).toBe(false);
      expect(r.problems.join('\n')).toMatch(/nrm\.json: expected changed for FX-PR-NRM-01/);
      // the same change in an oracle-runner commit passes; a non-expected edit (notes) by anyone passes
      git('checkout -q staging'); git('checkout -q -b bot');
      writeFileSync(join(repo, 'fixtures', 'protein', 'nrm.json'), JSON.stringify(doc, null, 2));
      git('-c user.name=lld-oracle-runner -c user.email=bot@lifelinedaily.com commit -q -am "ORC-02: refresh expected"');
      expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(true);
      git('checkout -q staging'); git('checkout -q -b notes');
      const d2 = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'protein', 'nrm.json'), 'utf8')); d2.fixtures[0].purpose = 'renamed';
      writeFileSync(join(repo, 'fixtures', 'protein', 'nrm.json'), JSON.stringify(d2, null, 2));
      git('commit -q -am "FR1-07: purpose text"');
      expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(true);
    } finally { rmSync(repo, { recursive: true, force: true }); }
    // the job that enforces this rule is oracle-guard-expected (runs the module above); ci.yml's subject-only oracle-guard stays as a second line
    expect(jobIds()).toContain('oracle-guard-expected');
    const guardJob = ci.split(/\n  oracle-guard-expected:\n/)[1].split(/\n  [a-z][a-z0-9-]*:\n/)[0];
    expect(guardJob).toMatch(/node tests\/ci\/oracle-guard\.mjs/);
    expect(guardJob).toMatch(/fetch-depth: 0/);
  });

  test('TC-NFR1-09-AS-04', () => {
    // every evidence bundle validates SCH-06e and has commit and tool_hashes for both tools
    const dir = resolve(ROOT, 'evidence');
    const bundles = readdirSync(dir).flatMap((req) => { try { return readdirSync(join(dir, req)).filter((f) => f.endsWith('.json')).map((f) => join(dir, req, f)); } catch { return []; } });
    expect(bundles.length).toBeGreaterThan(0);
    for (const b of bundles) {
      expect(validateFile('evidence.schema.json', b).errors, b).toEqual([]);
      const e = JSON.parse(readFileSync(b, 'utf8'));
      expect(e.commit, b).toMatch(/^[0-9a-f]{7,40}$/);
      expect(e.tool_hashes.protein, b).toMatch(/^[0-9a-f]{64}$/);
      expect(e.tool_hashes.diabetes, b).toMatch(/^[0-9a-f]{64}$/);
      expect(execSync(`git cat-file -t ${e.commit}`, { cwd: ROOT, encoding: 'utf8' }).trim(), `${b} commit exists`).toBe('commit');
    }
    expect(jobIds()).toContain('evidence');
  });
});
