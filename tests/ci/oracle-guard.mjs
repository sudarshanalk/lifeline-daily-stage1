#!/usr/bin/env node
// oracle-guard (docs/04 §1, §7; TC-NFR1-09-AS-03): `expected` values in fixtures/** may change only in a baseline or
// oracle commit (subject prefix FR1-01:, FR1-22: or ORC-0n:) authored by the oracle-runner bot, or in a commit signed by
// the clinical reviewer key (verified with `git verify-commit`). Any other change to an `expected` block fails.
// Usage: node tests/ci/oracle-guard.mjs [--base origin/staging] [--head HEAD] [--bot <login-or-email-regex>]
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';

const git = (cmd, cwd) => execSync(`git ${cmd}`, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });

/** Does the diff of a fixture file change anything inside an `expected` object? Compares parsed JSON per fixture id. */
function expectedChanged(base, head, file, cwd) {
  const load = (rev) => { try { return JSON.parse(git(`show ${rev}:${file}`, cwd)); } catch { return null; } };
  const a = load(base), b = load(head);
  if (!a || !b) return !!(a && b) || (a === null) !== (b === null); // added or removed file counts as a change
  const map = (d) => Object.fromEntries((d.fixtures || []).map((f) => [f.id, JSON.stringify(f.expected ?? null)]));
  const ma = map(a), mb = map(b);
  return Object.keys({ ...ma, ...mb }).filter((id) => ma[id] !== mb[id]);
}

export function oracleGuard({ root = ROOT, base = 'origin/staging', head = 'HEAD', botPattern = process.env.LLD_ORACLE_BOT || 'lld-oracle-runner|github-actions\\[bot\\]' } = {}) {
  const files = git(`diff --name-only ${base}...${head} -- fixtures`, root).split(/\r?\n/).filter((f) => f.endsWith('.json'));
  const problems = [];
  if (!files.length) return { ok: true, problems, files };
  const commits = git(`log --format=%H%x1f%an%x1f%ae%x1f%s ${base}..${head} -- fixtures`, root).trim().split('\n').filter(Boolean).map((l) => { const [sha, an, ae, s] = l.split('\x1f'); return { sha, an, ae, s }; });
  const botRe = new RegExp(botPattern, 'i');
  const signedByReviewer = (sha) => { try { git(`verify-commit ${sha}`, root); return true; } catch { return false; } };
  const privileged = commits.every((c) => ((/^(FR1-01|FR1-22|ORC-0[1-5]):/.test(c.s)) && (botRe.test(c.an) || botRe.test(c.ae))) || signedByReviewer(c.sha));
  // Stage 3 (NFR3-14): a calculator's fixture file is first written from the owner-signed method sheet, so a NEW file
  // under a Stage 3 tool folder may land in an S3-P pull request; the Stage 1 and 2 folders, and every later change to
  // an expected value anywhere, keep the rule above.
  // Only a tool folder the fixture schema names, and only when the tool's approval record approvals/stage3/<tool>.json
  // is in the head tree, so a fixture file never lands without its sign-off.
  const stage12 = new Set(['protein', 'diabetes', 'healthy-weight', 'site', 'mail']);
  const schemaTools = (() => { try { return JSON.parse(readFileSync(resolve(root, 'schemas', 'fixture.schema.json'), 'utf8')).properties.tool.enum; } catch { return []; } })();
  const inHead = (f) => { try { git(`cat-file -e ${head}:${f}`, root); return true; } catch { return false; } };
  const onBase = (f) => { try { git(`cat-file -e ${base}:${f}`, root); return true; } catch { return false; } };
  const isNewStage3File = (f) => {
    const tool = f.split('/')[1];
    return f.split('/').length === 3 && !stage12.has(tool) && schemaTools.includes(tool) && !onBase(f) && inHead(`approvals/stage3/${tool}.json`)
      && commits.every((c) => /^S3-P[0-9][0-9A-B](-P[0-9][0-9A-B])?: /.test(c.s));
  };
  for (const f of files) {
    if (isNewStage3File(f)) continue;
    const changed = expectedChanged(base, head, f, root);
    if (changed === true || (Array.isArray(changed) && changed.length)) {
      if (!privileged) problems.push(`${f}: expected changed for ${Array.isArray(changed) ? changed.join(', ') : 'file'} outside an oracle-runner/baseline commit or a reviewer-signed commit`);
    }
  }
  return { ok: problems.length === 0, problems, files, commits };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const opt = (k, d) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : d; };
  const r = oracleGuard({ base: opt('--base', 'origin/staging'), head: opt('--head', 'HEAD'), botPattern: opt('--bot', undefined) });
  for (const p of r.problems) console.error('oracle-guard: ' + p);
  console.log(r.ok ? `oracle-guard: OK (${r.files.length} fixture files changed, none alter expected without privilege)` : 'oracle-guard: FAIL');
  process.exit(r.ok ? 0 : 1);
}
