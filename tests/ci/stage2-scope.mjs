#!/usr/bin/env node
// stage2-scope (Stage 2 Build Method §2, §4, §7): pull-request classes and file ownership for Stage 2.
//   S2-Cn: <name>  Cursor package n (1-10): only the membership plugin and its tests; C9 also the calculator link script,
//                  its include in the two tool pages and the provenance refresh that a tool-page change needs.
//   S2-Ln: <name>  Lead (Claude) package n (0-8; L0 = Phase 0 set-up): everything Claude owns, never Cursor's paths and
//                  never the Stage 1 calculators, approvals, manifests or copy deck (those need an owner decision).
// Also checks that every Stage 2 test id used under tests/stage2 (TC-FR2/NFR2/UI2-nn-AS-nn) exists in docs/stage2/03.
// Usage: node tests/ci/stage2-scope.mjs --title "S2-C1: Foundations" [--base origin/staging] [--head HEAD]
//        node tests/ci/stage2-scope.mjs --names
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';
import { globToRegex, changedFiles } from './component-scope.mjs';

export const TITLE_RE = /^S2-(C(?:[1-9]|10)|L[0-8]): \S/;
const matches = (file, globs) => globs.some((g) => globToRegex(g).test(file));

const CURSOR = ['site/public_html/wp-content/plugins/lld-members/**', 'tests/stage2/**'];
const TOOL_PAGES = ['site/public_html/tools/protein-requirement-tool.html', 'site/public_html/tools/diabetes-risk-tool.html'];
// C9 (FR2-23): one additive script plus its include; a tool-page change must refresh hashes in the same PR (FR1-06 rule)
const C9_EXTRA = [...TOOL_PAGES, 'site/public_html/tools/lld-members-link.js', 'approvals/protein.json', 'approvals/diabetes.json', 'approvals/copy.json', 'copy/inventory.json', 'manifests/protein.json', 'manifests/diabetes.json', 'tests/mutations/MUT-10.patch'];
const PROTECTED = ['site/public_html/tools/**', 'approvals/**', 'manifests/**', 'copy/**'];
const LEAD = ['.github/**', 'deploy/**', 'docs/**', '.cursor/**', 'site/public_html/wp-content/mu-plugins/lld-tools/**', 'site/public_html/wp-content/mu-plugins/lld-tools-loader.php',
  'tests/**', 'tools/**', 'content/**', 'reports/**', 'registry/**', 'schemas/**', 'fixtures/**', 'design/**', 'Makefile', 'package.json', 'package-lock.json', 'README.md', 'CLAUDE.md', '.gitignore', '.gitattributes'];

export function classify(title = '') {
  const m = title.match(TITLE_RE);
  if (!m) return null;
  return { kind: m[1][0], n: Number(m[1].slice(1)), id: `S2-${m[1]}` };
}

export function stage2Scope({ title, files }) {
  const c = classify(title);
  if (!c) return { ok: false, problems: [`title "${title}" is not S2-Cn: (n 1-10) or S2-Ln: (n 0-8)`] };
  const problems = [];
  for (const f of files) {
    if (c.kind === 'C') {
      if (matches(f, CURSOR) || (c.n === 9 && C9_EXTRA.includes(f))) continue;
      problems.push(`${f} is outside Cursor's files for ${c.id} (Build Method §4)`);
    } else {
      if (matches(f, CURSOR)) { problems.push(`${f} belongs to Cursor (Build Method §4); ask in the pull request instead`); continue; }
      if (matches(f, PROTECTED)) { problems.push(`${f} is a Stage 1 calculator, approval, manifest or copy file: owner decision needed`); continue; }
      if (!matches(f, LEAD)) problems.push(`${f} is outside the lead's files (Build Method §4)`);
    }
  }
  return { ok: problems.length === 0, problems, package: c.id };
}

function walk(dir, out = []) {
  if (!existsSync(dir)) return out;
  for (const f of readdirSync(dir)) {
    const p = join(dir, f);
    if (f === 'node_modules') continue;
    if (statSync(p).isDirectory()) walk(p, out); else if (/\.(m?js|ts|php)$/.test(f)) out.push(p);
  }
  return out;
}

/** Stage 2 test ids used under tests/stage2 that are not checks in docs/stage2/03 (Build Method §7 rule 4). */
export function unknownTestIds({ root = ROOT } = {}) {
  const doc = readFileSync(resolve(root, 'docs', 'stage2', '03_Test_Criteria_Stage2.md'), 'utf8');
  const known = new Set(doc.match(/TC-(?:FR2|NFR2|UI2)-\d{2}-AS-\d{2}/g) || []);
  const problems = [];
  for (const file of walk(resolve(root, 'tests', 'stage2'))) {
    const ids = readFileSync(file, 'utf8').match(/TC-(?:FR2|NFR2|UI2)-\d{2}-AS-\d{2}/g) || [];
    for (const id of new Set(ids)) if (!known.has(id)) problems.push(`${relative(root, file).replace(/\\/g, '/')}: ${id} is not a check in docs/stage2/03`);
  }
  return { ok: problems.length === 0, problems, known: known.size };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const arg = (k, d) => { const i = process.argv.indexOf(k); return i > 0 ? process.argv[i + 1] : d; };
  const r = process.argv.includes('--names')
    ? unknownTestIds()
    : stage2Scope({ title: arg('--title', process.env.PR_TITLE || ''), files: changedFiles(arg('--base', 'origin/staging'), arg('--head', 'HEAD')) });
  for (const p of r.problems) console.log('stage2-scope: ' + p);
  console.log(r.ok ? `stage2-scope: OK${r.package ? ' (' + r.package + ')' : ` (${r.known} known checks)`}` : 'stage2-scope: FAILED');
  process.exit(r.ok ? 0 : 1);
}
