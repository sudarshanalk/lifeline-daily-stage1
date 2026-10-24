#!/usr/bin/env node
// component-scope (docs/04 §1, §7): a PR for requirement <ID> may touch only the paths of that requirement's
// components (registry components[].paths), plus the harness paths every requirement needs. Conventions encoded here
// (adopted in wave 1, recorded in the wave-1 PRs):
//   * harness: tests/**, evidence/**, Makefile, registry/Stage1_Requirements_Registry_v1_2.json (status only — orchestrator)
//   * provenance refresh set (SRS FR1-06 "same PR" rule): approvals/{protein,diabetes,copy}.json, copy/inventory.json,
//     manifests/*.json, site/public_html/tools/status.json, tests/mutations/MUT-10.patch — allowed only when the diff also changes a tool page
//   * .gitattributes/.gitignore/README.md/CLAUDE.md are never in scope for a requirement branch
// Usage: node tests/ci/component-scope.mjs --req FR1-21 [--base origin/staging] [--head HEAD]   |   --spec for `SPEC: ...` PRs (DEC-55)
//        (--req defaults to the req/<ID> branch name or the PR title prefix in GITHUB_* env)
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';

const HARNESS = ['tests/**', 'evidence/**', 'Makefile', 'package.json', 'package-lock.json', 'registry/Stage1_Requirements_Registry_v1_2.json'];   // package.json: DEC-65
// approvals/copy.json and the copy deck (copy/*.json, UI1-11) carry the DEC-25 per-key copy hashes and texts: a MESSAGES/data-copy change on a tool page
// cannot pass copy-hash without refreshing them in the same PR (DEC-56 proposal, mirrors the SRS FR1-06 version_hash rule)
const PROVENANCE_SET = ['approvals/protein.json', 'approvals/diabetes.json', 'approvals/copy.json', 'copy/inventory.json', 'copy/protein-messages.json', 'copy/diabetes-messages.json', 'copy/templates.json', 'copy/inline.json', 'copy/pages.json', 'manifests/protein.json', 'manifests/diabetes.json', 'site/public_html/tools/status.json', 'tests/mutations/MUT-10.patch'];
// artefacts a requirement produces by its TC page although they live under CMP-12 (DEC-68 proposal): SCH-04 tool cards are FR1-04's
const EXTRA = { 'FR1-04': ['content/tool-cards.json'], 'UI1-02': ['content/tool-cards.json'], 'FR1-05': ['content/article-tool-links.json'], 'FR1-23': ['content/planned-categories.json'], 'UI1-09': ['design/tokens.json', '.stylelintrc.json'] };   // SCH-04 / SCH-05b artefacts (DEC-68 proposal)
const TOOL_PAGES = ['site/public_html/tools/protein-requirement-tool.html', 'site/public_html/tools/diabetes-risk-tool.html'];

/** Minimal glob → RegExp: `**` any depth (including none), `*` within one segment. Placeholders keep the two apart. */
export const globToRegex = (g) => new RegExp('^' + g.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*\*\//g, '\u0001').replace(/\*\*/g, '\u0002').replace(/\*/g, '[^/]*').replace(/\u0001/g, '(?:.*/)?').replace(/\u0002/g, '.*') + '$');
const matches = (file, globs) => globs.some((g) => globToRegex(g).test(file));

/** SPEC PRs (title `SPEC: ...`, DEC-55): specification, register/registry and schema changes with no requirement id. Allowed:
 *  docs/**, registry/** (any field except a requirement's `status`), schemas/**, the three shared fixture data files. */
export const SPEC_ALLOWED = ['docs/**', 'registry/**', 'schemas/**', 'fixtures/required-phrases.json', 'fixtures/forbidden-terms.json', 'fixtures/sentinel-values.json'];
export function specScope({ root = ROOT, files, base = 'origin/staging', head = 'HEAD' }) {
  const problems = [];
  for (const f of files) if (!matches(f, SPEC_ALLOWED)) problems.push(`${f} is outside the SPEC PR class (${SPEC_ALLOWED.join(', ')})`);
  if (files.includes('registry/Stage1_Requirements_Registry_v1_2.json')) {
    const load = (rev) => { try { return JSON.parse(execSync(`git show ${rev}:registry/Stage1_Requirements_Registry_v1_2.json`, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })); } catch { return null; } };
    const a = load(base), b = load(head);
    if (a && b) for (const r of b.requirements) { const prev = a.requirements.find((x) => x.id === r.id); if (prev && prev.status !== r.status) problems.push(`registry: ${r.id} status ${prev.status} -> ${r.status} is an orchestrator change, not a SPEC change`); }
  }
  return { ok: problems.length === 0, problems, allowed: SPEC_ALLOWED, components: ['SPEC'] };
}

export function componentScope({ root = ROOT, req, files }) {
  const registry = JSON.parse(readFileSync(resolve(root, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
  const entry = registry.requirements.find((r) => r.id === req);
  if (!entry) return { ok: false, problems: [`unknown requirement ${req}`], allowed: [] };
  const allowed = [];
  for (const cid of entry.components) {
    const c = registry.components.find((x) => x.id === cid);
    if (c) allowed.push(...c.paths);
  }
  const touchesToolPage = files.some((f) => TOOL_PAGES.includes(f));
  const problems = [];
  for (const f of files) {
    if (matches(f, allowed) || matches(f, HARNESS) || (EXTRA[req] || []).includes(f)) continue;
    if (touchesToolPage && PROVENANCE_SET.includes(f)) continue;
    problems.push(`${f} is outside ${req}'s components (${entry.components.join(', ')})`);
  }
  return { ok: problems.length === 0, problems, allowed, components: entry.components };
}

/** Files the PR changes relative to its base. A requirement branch may merge another requirement's branch to build on it
 *  (e.g. UI1-04 on top of both tool stacks): files whose head blob is byte-identical to the same path in a merged-in parent
 *  were brought in by that merge, not changed by this PR, so they are excluded (DEC-57 proposal: merge-parent exclusion). */
export function changedFiles(base, head, root = ROOT) {
  const git = (cmd) => execSync(`git ${cmd}`, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  const files = git(`diff --name-only ${base}...${head}`).split(/\r?\n/).filter(Boolean);
  const merges = git(`rev-list --merges ${base}..${head}`).split(/\r?\n/).filter(Boolean);
  const parents = merges.flatMap((m) => git(`log -1 --format=%P ${m}`).split(' ').slice(1)); // %P avoids `^2`, which cmd.exe eats
  if (parents.length === 0) return files;
  const blob = (rev, f) => { try { return git(`rev-parse ${rev}:${f}`); } catch { return null; } };
  return files.filter((f) => { const h = blob(head, f); return !(h && parents.some((p) => blob(p, f) === h)); });
}

export function inferReq() {
  const fromBranch = (process.env.GITHUB_HEAD_REF || execSync('git rev-parse --abbrev-ref HEAD', { encoding: 'utf8' }).trim()).match(/^req\/((?:FR1|NFR1|UI1)-\d{2})/);
  if (fromBranch) return fromBranch[1];
  const fromTitle = (process.env.PR_TITLE || '').match(/^((?:FR1|NFR1|UI1)-\d{2}):/);
  return fromTitle ? fromTitle[1] : null;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (k, d) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : d; };
  const files = changedFiles(opt('--base', 'origin/staging'), opt('--head', 'HEAD'));
  const spec = args.includes('--spec');
  const req = spec ? 'SPEC' : (opt('--req') || inferReq());
  if (!req) { console.error('component-scope: cannot infer requirement id (use --req, --spec, or a req/<ID> branch)'); process.exit(2); }
  const r = spec ? specScope({ files, base: opt('--base', 'origin/staging'), head: opt('--head', 'HEAD') }) : componentScope({ req, files });
  for (const p of r.problems) console.error('component-scope: ' + p);
  console.log(r.ok ? `component-scope: OK (${req}: ${files.length} files within ${r.components.join(', ')} + harness)` : 'component-scope: FAIL');
  process.exit(r.ok ? 0 : 1);
}
