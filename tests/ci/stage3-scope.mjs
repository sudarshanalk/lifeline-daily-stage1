#!/usr/bin/env node
// stage3-scope (Stage 3 Build Method §1, §4): the guards that protect Stage 2 on every Stage 3 pull request.
//   --title "S3-P40: food search"   file scope for the package (NFR3-05): each package may touch its own tool's files
//                                   plus the declared Stage 2 files; a declared file prints "lead-review" so CI labels the PR.
//   --guards                        NFR3-02: no Stage 2 test edited, skipped or deleted without a recorded decision
//                                   (docs/stage3/stage2-test-changes.json); NFR3-04: the schema diff only adds.
//   --names                         every TC-FR3/NFR3 id used under tests/stage3 is a check in docs/stage3/03.
// Usage: node tests/ci/stage3-scope.mjs --title "S3-P10: foundations" [--base origin/staging] [--head HEAD]
//        node tests/ci/stage3-scope.mjs --guards [--base …] [--head …]
//        node tests/ci/stage3-scope.mjs --names
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';
import { globToRegex, changedFiles } from './component-scope.mjs';

// S3-Pnn (nn = two hex-ish characters as the tracker uses them: P3-10 … P3-5B … P3-95), or S3-Pnn-Pmm for a joined PR
export const TITLE_RE = /^S3-P([0-9][0-9A-B])(?:-P([0-9][0-9A-B]))?: \S/;
const matches = (file, globs) => globs.some((g) => globToRegex(g).test(file));

const PLUGIN = 'site/public_html/wp-content/plugins/lld-members';
const STAGE3 = `${PLUGIN}/includes/stage3`;

// Build Method Stage 3 §4: what each tool may write.
export const TOOL_FILES = {
  cursor: [`${STAGE3}/**`, 'tests/stage3/**'],
  antigravity: [`${STAGE3}/screens/**`, `${PLUGIN}/assets/stage3.css`, `${PLUGIN}/assets/stage3.js`, 'tests/stage3/look/**', 'tests/stage3/e2e/screens/**'],
  // tools/seo/**: the checking tools of P3-80 to P3-82 (direct answers, Search Console and speed), which the Codex brief
  // assigns as "tooling under tools/ and tests/"; the lead applies the content changes they report.
  codex: ['tests/stage3/**', 'tests/ci/**', '.github/workflows/**', 'tools/seo/**'],
  claude: ['.github/**', 'deploy/**', 'docs/**', '.cursor/**', 'AGENTS.md', 'CLAUDE.md', 'README.md', 'Makefile', 'package.json', 'package-lock.json',
    '.gitignore', '.gitattributes', `${STAGE3}/**`, `${PLUGIN}/assets/stage3.css`, `${PLUGIN}/assets/stage3.js`, 'tests/**', 'tools/**', 'content/**', 'reports/**',
    'site/public_html/wp-content/mu-plugins/lld-tools/**', 'site/public_html/wp-content/mu-plugins/lld-tools-loader.php', 'schemas/**', 'registry/**', 'design/**', 'fixtures/**',
    'approvals/**', 'oracles/**', 'manifests/**'],
};

// Which tools build which package (AGENTS.md "Stage 3 work by tool"; Build Method §2). A package not listed is the lead's.
export const PACKAGE_TOOLS = {
  11: ['codex'], 30: ['antigravity'], 31: ['cursor', 'antigravity'], 32: ['cursor', 'antigravity'], 33: ['antigravity'],
  40: ['cursor'], 41: ['cursor'], 42: ['cursor'], 43: ['cursor'], 44: ['antigravity'], 50: ['cursor'], 51: ['cursor'],
  // The calculator engines: P3-55 was built by the lead; P3-56 to P3-5B are delegated to Cursor in full (owner, 3 Nov 2026).
  55: ['cursor', 'claude'], 56: ['cursor'], 57: ['cursor'], 58: ['cursor'], 59: ['cursor'], '5A': ['cursor'], '5B': ['cursor'],
  80: ['codex'], 81: ['codex'], 82: ['codex'], 83: ['antigravity'], 85: ['cursor'], 86: ['cursor'],
};

// Stage 2 files any tool may touch additively; a change labels the PR for the lead's review (NFR3-05 AS-02).
export const DECLARED_STAGE2 = [
  `${PLUGIN}/lld-members.php`, `${PLUGIN}/includes/class-schema.php`, `${PLUGIN}/includes/class-plugin.php`, `${PLUGIN}/includes/class-screens.php`,
  `${PLUGIN}/includes/class-rest.php`, `${PLUGIN}/includes/class-erasure.php`, `${PLUGIN}/includes/class-privacy.php`, `${PLUGIN}/includes/class-cli.php`,
  `${PLUGIN}/includes/class-safety.php`, `${PLUGIN}/includes/class-access.php`, `${PLUGIN}/includes/components.php`, `${PLUGIN}/assets/lld-members.js`, `${PLUGIN}/assets/lld-members.css`,
  'tests/stage2/data-model-map.json', 'tests/stage2/personal-columns.json',
];
// Stage 2 payment files only the lead's packages may touch (D2, P3-60 to P3-63); the change labels the PR for review.
export const LEAD_STAGE2 = [`${PLUGIN}/includes/class-billing.php`, `${PLUGIN}/includes/class-stripe.php`];
const STAGE2_TESTS = ['tests/stage2/**'];
// The Stage 1 and 2 calculators and their approvals. Stage 3 sign-offs live in approvals/stage3/ (Build Method §7.4).
const PROTECTED = ['site/public_html/tools/**', 'approvals/*', 'manifests/**', 'copy/**'];
const SKIP_RE = /\b(test|it|describe)\.(skip|only|todo)\s*\(|\bx(it|test|describe)\s*\(|\btest\.fixme\s*\(/;

export function classify(title = '') {
  const m = title.match(TITLE_RE);
  if (!m) return null;
  const ids = [m[1], m[2]].filter(Boolean);
  return { ids, id: `S3-P${ids.join('-P')}` };
}

function toolsFor(ids) {
  // A package not listed is the lead's; a listed package stays within its tools' files, so a builder's PR cannot reach
  // the lead's deploy, workflow or document files. The lead joins branches under its own titles.
  const tools = new Set();
  for (const id of ids) for (const t of PACKAGE_TOOLS[id] || ['claude']) tools.add(t);
  return [...tools];
}

/** NFR3-05: file scope for one Stage 3 pull request. `review` lists declared Stage 2 files touched. */
export function stage3Scope({ title, files, decisions = loadDecisions() }) {
  const c = classify(title);
  if (!c) return { ok: false, problems: [`title "${title}" is not S3-Pnn: <name> (Build Method Stage 3 §2)`], review: [] };
  const problems = [];
  const review = [];
  const allowed = toolsFor(c.ids).flatMap((t) => TOOL_FILES[t]);
  for (const f of files) {
    if (matches(f, PROTECTED)) { problems.push(`${f} is a Stage 1 calculator, approval, manifest or copy file: owner decision needed`); continue; }
    if (DECLARED_STAGE2.includes(f)) { review.push(f); continue; }
    if (LEAD_STAGE2.includes(f) && toolsFor(c.ids).every((t) => t === 'claude')) { review.push(f); continue; }
    if (matches(f, STAGE2_TESTS)) {
      if (decisions.some((d) => d.file === f)) { review.push(f); continue; }
      problems.push(`${f} is a Stage 2 test: it may change only with a decision in docs/stage3/stage2-test-changes.json (NFR3-02)`);
      continue;
    }
    if (f.startsWith(`${PLUGIN}/`) && !f.startsWith(`${STAGE3}/`) && !allowed.some((g) => f === g)) {
      problems.push(`${f} is a Stage 2 plugin file that Stage 3 does not declare (NFR3-05); ask the lead`);
      continue;
    }
    if (!matches(f, allowed)) problems.push(`${f} is outside the files of ${c.id}'s tools (${toolsFor(c.ids).join(', ')}; Build Method Stage 3 §4)`);
  }
  return { ok: problems.length === 0, problems, review, package: c.id };
}

export function loadDecisions(root = ROOT) {
  const path = resolve(root, 'docs', 'stage3', 'stage2-test-changes.json');
  if (!existsSync(path)) return [];
  const data = JSON.parse(readFileSync(path, 'utf8'));
  return Array.isArray(data.changes) ? data.changes : [];
}

function git(args, root = ROOT) {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
}

/** Lines removed (-) and added (+) by the diff of one file, without the headers. */
export function diffLines(base, head, file, root = ROOT) {
  let out = '';
  try { out = git(['diff', '--no-color', '--unified=0', `${base}...${head}`, '--', file], root); } catch { out = ''; }
  const removed = [];
  const added = [];
  for (const line of out.split('\n')) {
    if (line.startsWith('---') || line.startsWith('+++')) continue;
    if (line.startsWith('-')) removed.push(line.slice(1));
    else if (line.startsWith('+')) added.push(line.slice(1));
  }
  return { removed, added };
}

function headText(head, file, root = ROOT) {
  try { return git(['show', `${head}:${file}`], root); } catch { return ''; }
}

/**
 * NFR3-02 and NFR3-04 on a diff. `diffs` maps file -> {removed, added}; `texts` maps file -> content at head
 * (both injectable so the unit tests need no git).
 */
export function stage3Guards({ files, diffs, texts, decisions = loadDecisions() }) {
  const problems = [];
  const schemaFiles = [`${PLUGIN}/includes/class-schema.php`, `${STAGE3}/class-schema-stage3.php`];
  for (const f of files) {
    const d = diffs(f);
    if (matches(f, STAGE2_TESTS)) {
      const text = texts(f);
      if (text === '' ) {
        if (!decisions.some((x) => x.file === f)) problems.push(`${f}: a Stage 2 test file was deleted without a decision (NFR3-02)`);
        continue;
      }
      if (!decisions.some((x) => x.file === f)) problems.push(`${f}: a Stage 2 test changed without a decision in docs/stage3/stage2-test-changes.json (NFR3-02)`);
      for (const line of d.added) if (SKIP_RE.test(line)) problems.push(`${f}: a Stage 2 test is skipped, limited or marked to-do (NFR3-02): ${line.trim()}`);
    }
    if (schemaFiles.includes(f)) {
      for (const line of d.added) {
        // SQL-shaped only: an upper-case verb followed by a table, column or name, so prose in a comment is not caught.
        if (/\b(DROP\s+(TABLE|COLUMN|INDEX)|ALTER\s+TABLE\s+\S+\s+(MODIFY|CHANGE|RENAME)|MODIFY\s+COLUMN|CHANGE\s+COLUMN|RENAME\s+(TABLE|COLUMN|TO))\b/.test(line)) {
          problems.push(`${f}: a database change that drops, changes or renames (NFR3-04): ${line.trim()}`);
        }
      }
      if (f === schemaFiles[0]) {
        for (const line of d.removed) if (line.trim() !== '') problems.push(`${f}: a line of the Stage 2 schema was removed (NFR3-04): ${line.trim()}`);
      }
    }
  }
  return { ok: problems.length === 0, problems };
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

/** Stage 3 test ids used under tests/stage3 that are not checks in docs/stage3/03 (Build Method §3 rule 3). */
export function unknownTestIds({ root = ROOT } = {}) {
  const doc = readFileSync(resolve(root, 'docs', 'stage3', '03_Test_Criteria_Stage3.md'), 'utf8');
  const known = new Set(doc.match(/TC-(?:FR3|NFR3)-\d{2}-AS-\d{2}/g) || []);
  const problems = [];
  for (const file of walk(resolve(root, 'tests', 'stage3'))) {
    const ids = readFileSync(file, 'utf8').match(/TC-(?:FR3|NFR3)-\d{2}-AS-\d{2}/g) || [];
    for (const id of new Set(ids)) if (!known.has(id)) problems.push(`${relative(root, file).replace(/\\/g, '/')}: ${id} is not a check in docs/stage3/03`);
  }
  return { ok: problems.length === 0, problems, known: known.size };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const arg = (k, d) => { const i = process.argv.indexOf(k); return i > 0 ? process.argv[i + 1] : d; };
  const base = arg('--base', 'origin/staging');
  const head = arg('--head', 'HEAD');
  let r;
  if (process.argv.includes('--names')) {
    r = unknownTestIds();
  } else if (process.argv.includes('--guards')) {
    const files = changedFiles(base, head);
    r = stage3Guards({ files, diffs: (f) => diffLines(base, head, f), texts: (f) => headText(head, f) });
  } else {
    r = stage3Scope({ title: arg('--title', process.env.PR_TITLE || ''), files: changedFiles(base, head) });
    for (const f of r.review) console.log('stage3-scope: lead-review ' + f);
  }
  for (const p of r.problems) console.log('stage3-scope: ' + p);
  console.log(r.ok ? `stage3-scope: OK${r.package ? ' (' + r.package + ')' : r.known !== undefined ? ` (${r.known} known checks)` : ''}` : 'stage3-scope: FAILED');
  process.exit(r.ok ? 0 : 1);
}
