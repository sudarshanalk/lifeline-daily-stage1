#!/usr/bin/env node
// Re-records the characterisation fixtures of one tool from its engine after an HG-02-approved engine change (DEC-86).
// Inputs come from tests/baseline/<tool>-cases.json; expected values come from the engine through the same runner the
// tests use (tests/lib/tool-page.mjs). Only the oracle-runner workflow (.github/workflows/rebaseline.yml) commits the
// result, as github-actions[bot] with an `FR1-01:` subject, which is the privileged path oracle-guard accepts.
// Usage: node tests/baseline/rebaseline.mjs --tool protein
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { ROOT, TOOL_FILES } from '../lib/paths.mjs';
import { proteinRunner } from '../lib/tool-page.mjs';

const RUNNERS = { protein: proteinRunner };
const args = process.argv.slice(2);
const tool = args[args.indexOf('--tool') + 1];
if (!RUNNERS[tool]) { console.error(`rebaseline: unknown tool '${tool}' (${Object.keys(RUNNERS).join(', ')})`); process.exit(2); }

const cases = JSON.parse(readFileSync(resolve(ROOT, 'tests', 'baseline', `${tool}-cases.json`), 'utf8'));
const page = resolve(ROOT, 'site', 'public_html', 'tools', TOOL_FILES[tool]);
const version = createHash('sha256').update(readFileSync(page)).digest('hex');
const run = RUNNERS[tool]();
mkdirSync(resolve(ROOT, 'fixtures', tool), { recursive: true });

let n = 0;
for (const [file, doc] of Object.entries(cases.files)) {
  const fixtures = doc.fixtures.map((c) => {
    const recorded = c.oracle === 'characterisation';
    n++;
    return { id: c.id, purpose: c.purpose, inputs: c.inputs, expected: run(c.inputs), oracle: c.oracle,
      source_version: recorded ? version : null, reviewer: null, status: recorded ? 'recorded' : 'pending' };
  });
  writeFileSync(resolve(ROOT, 'fixtures', tool, file), JSON.stringify({ ...doc, fixtures }, null, 2) + '\n');
}
console.log(`rebaseline: ${n} ${tool} fixtures recorded from ${TOOL_FILES[tool]} (sha256 ${version.slice(0, 12)})`);
process.exit(0);   // the tool page keeps timers running inside jsdom
