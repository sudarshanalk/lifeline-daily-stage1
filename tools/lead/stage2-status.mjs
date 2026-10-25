#!/usr/bin/env node
// stage2-status: the build status of every Stage 2 package and requirement, worked out from GitHub (pull requests titled
// S2-Cn / S2-Ln into staging, their checks, and the deploy-staging runs) and the Stage 2 documents (requirement list in
// docs/stage2/01, package-to-requirement map in docs/stage2/04 sections 5 and 6). Read-only. Used by the tracker sync.
// Usage: node tools/lead/stage2-status.mjs [--out <file.json>] [--repo owner/name] [--root <checkout with docs/stage2>] [--docs <folder with 01_ and 04_ Stage 2 documents>]
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const arg = (k, d) => { const i = process.argv.indexOf(k); return i > 0 ? process.argv[i + 1] : d; };
const ROOT = arg('--root') ? resolve(arg('--root')) : resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const REPO = arg('--repo', 'sudarshanalk/lifeline-daily-stage1');
const ORDER = ['Not started', 'In build', 'In review', 'On staging'];

/** Requirements: id, title and priority from the docs/stage2/01 table rows. */
export function requirements(doc) {
  const out = [];
  for (const m of doc.matchAll(/^\| ((?:FR2|NFR2|UI2)-\d{2}) \| \*\*(.+?)\*\*/gm)) {
    const t = m[2].replace(/\s*\(([^)]*)\)\.?\s*$/, '');
    const pr = (m[2].match(/\((Must|Should|Could)/) || [])[1] || '';
    out.push({ id: m[1], title: t.replace(/\.$/, ''), priority: pr });
  }
  return out;
}
/** Requirements built outside the C and L packages: FR2-24 (Stage 1 released) is Phase 0 task P0-5. */
export const EXTRA_PACKAGES = [{ id: 'P0-5', name: 'Stage 1 sign-off and release', owner: 'Claude', reqs: ['FR2-24'] }];

/** Expand "FR2-32 to FR2-37", "NFR2-03, NFR2-04", "UI2-01 to UI2-04" into ids. */
export function expand(text) {
  const ids = new Set();
  for (const m of text.matchAll(/((?:FR2|NFR2|UI2)-)(\d{2})(?:\s*to\s*(?:(?:FR2|NFR2|UI2)-)?(\d{2}))?/g)) {
    const a = +m[2], b = m[3] ? +m[3] : a;
    for (let i = a; i <= b; i++) ids.add(m[1] + String(i).padStart(2, '0'));
  }
  return [...ids];
}

/** Packages: id, name, owner and requirement ids from the build method tables. */
export function packages(doc) {
  const out = [];
  for (const m of doc.matchAll(/^\| \*\*([CL]\d+)\*\* \| ([^|]+?) \| ([^|]+?) \|/gm)) {
    out.push({ id: m[1], name: m[2].replace(/\s*\(.*\)\s*$/, '').trim(), owner: m[1][0] === 'C' ? 'Cursor' : 'Claude', reqs: expand(m[3]) });
  }
  return out;
}

const gh = (args) => JSON.parse(execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }));

/** The status of each package from its pull requests and the deploys after their merge. */
export function packageStatus(pkgs, prs, deploys) {
  const lastDeploy = deploys.filter((d) => d.conclusion === 'success').map((d) => d.createdAt).sort().pop() || '';
  const res = {};
  for (const p of pkgs) {
    const mine = prs.filter((x) => new RegExp(`^S2-${p.id}:`).test(x.title));
    let status = 'Not started', detail = '', link = '';
    const open = mine.filter((x) => x.state === 'OPEN');
    const merged = mine.filter((x) => x.mergedAt).sort((a, b) => a.mergedAt.localeCompare(b.mergedAt));
    if (merged.length) {
      const last = merged[merged.length - 1];
      const deployed = lastDeploy && lastDeploy >= last.mergedAt;
      status = deployed ? 'On staging' : 'In review';
      detail = deployed ? `Merged in #${last.number} and deployed to staging` : `Merged in #${last.number}, staging deploy pending`;
      link = last.url;
    }
    if (open.length) {
      const o = open[open.length - 1];
      const checks = (o.statusCheckRollup || []).map((c) => c.conclusion || c.state);
      const green = checks.length >= 20 && checks.every((c) => ['SUCCESS', 'SKIPPED', 'NEUTRAL'].includes(c));
      const failing = checks.some((c) => ['FAILURE', 'ERROR', 'CANCELLED', 'TIMED_OUT'].includes(c));
      const s = !o.isDraft && green ? 'In review' : 'In build';
      if (!merged.length || ORDER.indexOf(s) >= 0) {
        if (!merged.length) status = s;
        detail = (merged.length ? detail + '; ' : '') + `#${o.number} ${o.isDraft ? 'draft' : 'open'}${failing ? ', checks failing' : green ? ', checks green' : ', checks running'}`;
        link = link || o.url;
      }
    }
    res[p.id] = { id: p.id, name: p.name, owner: p.owner, status, detail, link, prs: mine.map((x) => x.number) };
  }
  return res;
}

/** A requirement is as far along as the least advanced package that builds it. */
export function requirementStatus(reqs, pkgs, pst) {
  return reqs.map((r) => {
    const by = pkgs.filter((p) => p.reqs.includes(r.id));
    const st = by.map((p) => pst[p.id].status);
    const status = st.length ? st.reduce((a, b) => (ORDER.indexOf(b) < ORDER.indexOf(a) ? b : a)) : 'Not started';
    return { ...r, packages: by.map((p) => p.id), status, detail: by.map((p) => `${p.id}: ${pst[p.id].status}`).join('; ') };
  });
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const DOCS = arg('--docs') ? resolve(arg('--docs')) : resolve(ROOT, 'docs/stage2');
  const reqs = requirements(readFileSync(resolve(DOCS, '01_Requirements_Stage2.md'), 'utf8'));
  const pkgs = [...packages(readFileSync(resolve(DOCS, '04_Build_Method_Stage2.md'), 'utf8')), ...EXTRA_PACKAGES];
  const prs = gh(['pr', 'list', '--repo', REPO, '--base', 'staging', '--state', 'all', '--limit', '200', '--search', 'S2- in:title', '--json', 'number,title,state,isDraft,mergedAt,url,statusCheckRollup']);
  const deploys = gh(['run', 'list', '--repo', REPO, '--workflow', 'deploy-staging', '--branch', 'staging', '--limit', '50', '--json', 'conclusion,createdAt,headSha']);
  const pst = packageStatus(pkgs, prs, deploys);
  const out = { generated: new Date().toISOString(), repo: REPO, statuses: ORDER, packages: pst, requirements: requirementStatus(reqs, pkgs, pst) };
  const json = JSON.stringify(out, null, 2);
  const file = arg('--out');
  if (file) { writeFileSync(file, json + '\n'); console.log(`stage2-status: ${Object.keys(pst).length} packages, ${out.requirements.length} requirements -> ${file}`); }
  else console.log(json);
}
