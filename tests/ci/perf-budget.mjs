#!/usr/bin/env node
// perf (docs/04 §1): a static performance budget for the tool pages until NFR1-06 adds Lighthouse (DEC-17): each tool
// page ≤ 120 KB on the wire, self-contained (no external scripts, stylesheets, fonts or images), viewport meta present.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { TOOL_FILES, toolPath } from '../lib/paths.mjs';

const BUDGET_BYTES = 120 * 1024;

export function perfBudget() {
  const problems = []; const report = {};
  for (const [slug, file] of Object.entries(TOOL_FILES)) {
    const html = readFileSync(toolPath(slug));
    const doc = new JSDOM(html.toString('utf8')).window.document;
    const external = [...doc.querySelectorAll('script[src], link[rel="stylesheet"][href], img[src], iframe[src]')].map((e) => e.getAttribute('src') || e.getAttribute('href')).filter((u) => /^(https?:)?\/\//.test(u));
    report[slug] = { bytes: html.length, external };
    if (html.length > BUDGET_BYTES) problems.push(`${file}: ${html.length} bytes > budget ${BUDGET_BYTES}`);
    if (external.length) problems.push(`${file}: external resources ${external.join(', ')}`);
    if (!doc.querySelector('meta[name="viewport"]')) problems.push(`${file}: no viewport meta`);
  }
  return { ok: problems.length === 0, problems, report };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const r = perfBudget();
  for (const p of r.problems) console.error('perf: ' + p);
  console.log(r.ok ? `perf: OK ${JSON.stringify(r.report)} (Lighthouse joins with NFR1-06)` : 'perf: FAIL');
  process.exit(r.ok ? 0 : 1);
}
