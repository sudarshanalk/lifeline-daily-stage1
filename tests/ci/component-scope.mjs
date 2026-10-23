#!/usr/bin/env node
// component-scope (docs/04 §1, §7): a PR for requirement <ID> may touch only the paths of that requirement's
// components (registry components[].paths), plus the harness paths every requirement needs. Conventions encoded here
// (adopted in wave 1, recorded in the wave-1 PRs):
//   * harness: tests/**, evidence/**, Makefile, registry/Stage1_Requirements_Registry_v1_2.json (status only — orchestrator)
//   * provenance refresh set (SRS FR1-06 "same PR" rule): approvals/{protein,diabetes}.json, manifests/*.json,
//     site/public_html/tools/status.json, tests/mutations/MUT-10.patch — allowed only when the diff also changes a tool page
//   * .gitattributes/.gitignore/README.md/CLAUDE.md are never in scope for a requirement branch
// Usage: node tests/ci/component-scope.mjs --req FR1-21 [--base origin/staging] [--head HEAD]
//        (--req defaults to the req/<ID> branch name or the PR title prefix in GITHUB_* env)
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';

const HARNESS = ['tests/**', 'evidence/**', 'Makefile', 'registry/Stage1_Requirements_Registry_v1_2.json'];
const PROVENANCE_SET = ['approvals/protein.json', 'approvals/diabetes.json', 'manifests/protein.json', 'manifests/diabetes.json', 'site/public_html/tools/status.json', 'tests/mutations/MUT-10.patch'];
const TOOL_PAGES = ['site/public_html/tools/protein-requirement-tool.html', 'site/public_html/tools/diabetes-risk-tool.html'];

export const globToRegex = (g) => new RegExp('^' + g.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*\*\//g, '(?:.*/)?').replace(/\*\*/g, '.*').replace(/\*/g, '[^/]*') + '$');
const matches = (file, globs) => globs.some((g) => globToRegex(g).test(file));

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
    if (matches(f, allowed) || matches(f, HARNESS)) continue;
    if (touchesToolPage && PROVENANCE_SET.includes(f)) continue;
    problems.push(`${f} is outside ${req}'s components (${entry.components.join(', ')})`);
  }
  return { ok: problems.length === 0, problems, allowed, components: entry.components };
}

export function changedFiles(base, head, root = ROOT) {
  return execSync(`git diff --name-only ${base}...${head}`, { cwd: root, encoding: 'utf8' }).split(/\r?\n/).filter(Boolean);
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
  const req = opt('--req') || inferReq();
  if (!req) { console.error('component-scope: cannot infer requirement id (use --req or a req/<ID> branch)'); process.exit(2); }
  const files = changedFiles(opt('--base', 'origin/staging'), opt('--head', 'HEAD'));
  const r = componentScope({ req, files });
  for (const p of r.problems) console.error('component-scope: ' + p);
  console.log(r.ok ? `component-scope: OK (${req}: ${files.length} files within ${r.components.join(', ')} + harness)` : 'component-scope: FAIL');
  process.exit(r.ok ? 0 : 1);
}
