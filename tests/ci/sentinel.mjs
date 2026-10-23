#!/usr/bin/env node
// sentinel (docs/04 §1, §7): privacy leak scan. Drives the three sentinel flows (protein result email, diabetes result
// email, diabetes emergency email) through the local stack with fixtures/sentinel-values.json, then greps everything a
// leak could land in — endpoint state files, server logs, every file under the served docroot except the mail outbox
// itself (the mail is the one place the values belong), the emulator's request log, and the repository tree — for the
// sentinel strings. Any hit is a failure. Wave-1 scope: endpoints via the .htaccess emulator (PHP log transport); the
// analytics (FR1-16) and WordPress flows join when those components exist.
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, resolve, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';
import { ROOT } from '../lib/paths.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

const sentinel = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'sentinel-values.json'), 'utf8'));
const NEEDLES = [sentinel.firstName, sentinel.email, String(sentinel.weightKg), String(sentinel.waistCm)].filter(Boolean);

function walk(dir, out = []) {
  for (const f of readdirSync(dir)) {
    const p = join(dir, f);
    if (['node_modules', '.git', 'test-results', 'playwright-report'].includes(f)) continue;
    if (statSync(p).isDirectory()) walk(p, out); else out.push(p);
  }
  return out;
}
const isMailOutbox = (p) => /[\\/]tools[\\/]api[\\/]outbox[\\/]/.test(p);
// fixtures are synthetic by design (SCH-05) and may carry the sentinel values as inputs; tests/docs/evidence describe them
const isAllowedRepoFile = (rel) => rel.startsWith('fixtures/') || rel.startsWith('tests/') || rel.startsWith('docs/'); // evidence/ is generated output: scanned

export async function sentinelScan() {
  const srv = await startHtaccessServer();
  const findings = [];
  try {
    const flows = [
      { name: 'protein result email', path: '/tools/submit-protein.php', body: { tool: 'protein', firstName: sentinel.firstName, email: sentinel.email, consent: true, suppressed: false, targetG: 95, lowG: 80, highG: 110, gPerKg: 1.2, perMeal: 32, meals: 3, sources: ['IOM RDA'], timestamp: new Date().toISOString(), company: '' } },
      { name: 'diabetes result email', path: '/tools/submit.php', body: { tool: 'diabetes-risk', firstName: sentinel.firstName, email: sentinel.email, consent: true, riskPercent: 4.2, riskBand: 'Low', bmi: 27.9, whtr: 0.56, idrsScore: 30, idrsTier: 'moderate', symptomatic: false, emergency: false, measurements: { heightCm: sentinel.heightCm, weightKg: Math.round(sentinel.weightKg), waistCm: Math.round(sentinel.waistCm) }, timestamp: new Date().toISOString(), company: '' } },
      { name: 'diabetes emergency email', path: '/tools/submit.php', body: { tool: 'diabetes-risk', firstName: sentinel.firstName, email: sentinel.email, consent: true, riskPercent: null, riskBand: null, bmi: 27.9, whtr: 0.56, idrsScore: 30, idrsTier: 'moderate', symptomatic: true, emergency: true, measurements: { heightCm: sentinel.heightCm, weightKg: Math.round(sentinel.weightKg), waistCm: Math.round(sentinel.waistCm) }, timestamp: new Date().toISOString(), company: '' } },
    ];
    const responses = [];
    for (const f of flows) {
      const r = await fetch(srv.url + f.path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com' }, body: JSON.stringify(f.body) });
      responses.push({ flow: f.name, status: r.status, text: await r.text(), location: r.headers.get('location') || '' });
    }
    // 1. nothing sentinel-like in response bodies other than a status word, nor in any response header/URL
    for (const r of responses) for (const n of NEEDLES) if (r.text.includes(n) || r.location.includes(n)) findings.push(`${r.flow}: response echoes sentinel '${n}'`);
    // 2. served docroot: state files, logs, anything except the mail outbox
    for (const p of walk(srv.docroot)) {
      if (isMailOutbox(p)) continue;
      const text = readFileSync(p, 'latin1');
      for (const n of NEEDLES) if (text.includes(n)) findings.push(`${relative(srv.docroot, p)} contains sentinel '${n}'`);
    }
    // 3. repository tree (committed or not): sentinel values may live only in the fixture file, tests and docs
    for (const p of walk(ROOT)) {
      const rel = relative(ROOT, p).replace(/\\/g, '/');
      if (isAllowedRepoFile(rel)) continue;
      let text; try { text = readFileSync(p, 'latin1'); } catch { continue; }
      for (const n of NEEDLES) if (text.includes(n)) findings.push(`repo ${rel} contains sentinel '${n}'`);
    }
    return { ok: findings.length === 0, findings, responses: responses.map((r) => ({ flow: r.flow, status: r.status })) };
  } finally { await srv.close(); }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const r = await sentinelScan();
  for (const f of r.findings) console.error('sentinel: LEAK ' + f);
  console.log(`sentinel: ${r.ok ? 'OK' : 'FAIL'} ${JSON.stringify(r.responses)}`);
  process.exit(r.ok ? 0 : 1);
}
