// TC-NFR1-01 Privacy and special-category data — artefact half. AS-01 privacy/data-flow.json validates SCH-12 and lists every key a
// static scan finds in both payload builders (the objects the tools POST) and both e-mail templates ({{tokens}}), plus the SCH-07
// log fields; AS-02 every `health` field goes only to the screen and/or the consented e-mail; AS-04 privacy/dpia-screening.md has
// no TODO and answers all nine ICO screening questions.
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT, toolPath } from '../lib/paths.mjs';
import { validateFile } from '../lib/validate.mjs';

const FLOW = resolve(ROOT, 'privacy', 'data-flow.json');
const flow = JSON.parse(readFileSync(FLOW, 'utf8'));
/** keys of the object literal assigned to `payload` in a tool page, nested objects as parent.child */
function payloadKeys(slug) {
  const src = readFileSync(toolPath(slug), 'utf8');
  const start = src.search(/(?:const|var|let)\s+payload\s*=\s*\{/);
  if (start < 0) throw new Error(`${slug}: no payload builder found`);
  let i = src.indexOf('{', start), depth = 0, j = i;
  for (; j < src.length; j++) { if (src[j] === '{') depth++; else if (src[j] === '}' && --depth === 0) break; }
  const body = src.slice(i + 1, j).replace(/\/\*[^]*?\*\//g, '').replace(/'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/g, '""');
  const keys = []; const stack = [];
  // a property key starts the body or follows `{` or `,` (so the `null :` inside `cond ? null : x` is not a key)
  for (const m of body.matchAll(/([{}])|(?<=(?:^|[{,])\s*)([A-Za-z_]\w*)\s*:/g)) {
    if (m[1] === '{') { stack.push(keys[keys.length - 1]?.split('.').pop()); continue; }
    if (m[1] === '}') { stack.pop(); continue; }
    const prefix = stack.filter(Boolean).join('.');
    keys.push(prefix ? `${prefix}.${m[2]}` : m[2]);
  }
  // a nested object's own key is a container, not a field
  return keys.filter((k) => !keys.some((o) => o.startsWith(k + '.')));
}
const templateTokens = (file) => [...new Set([...readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'api', file), 'utf8').replace(/\/\*[^]*?\*\//g, '').matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]))];

describe('TC-NFR1-01 privacy and special-category data — artefacts', () => {
  test('TC-NFR1-01-AS-01', () => {
    const v = validateFile('data-flow.schema.json', FLOW); expect(v.ok, v.errors?.join('; ')).toBe(true);
    const listed = (tool) => new Set(flow.fields.filter((x) => x.tool === tool).map((x) => x.key));
    const missing = [];
    for (const [tool, slug, tpl] of [['protein', 'protein', 'templates-protein.php'], ['diabetes', 'diabetes', 'templates.php']]) {
      const scan = [...payloadKeys(slug), ...templateTokens(tpl), 'log.ts', 'log.tool', 'log.outcome', 'log.ip_hash'];
      expect(scan.length, `${tool}: static scan found keys`).toBeGreaterThan(10);
      for (const k of scan) if (!listed(tool).has(k)) missing.push(`${tool}: ${k}`);
    }
    expect(missing, 'every scanned key is in the inventory').toEqual([]);
  });

  test('TC-NFR1-01-AS-02', () => {
    const health = flow.fields.filter((x) => x.classification === 'health');
    expect(health.length).toBeGreaterThan(10);
    const bad = health.filter((x) => x.destinations.some((d) => !['screen', 'smtp'].includes(d))).map((x) => `${x.tool}.${x.key}: ${x.destinations.join(',')}`);
    expect(bad, 'health fields only to screen / smtp').toEqual([]);
    for (const x of health) expect(x.article9_condition, `${x.tool}.${x.key}: Art. 9 condition`).toBeTruthy();
  });

  test('TC-NFR1-01-AS-04', () => {
    const md = readFileSync(resolve(ROOT, 'privacy', 'dpia-screening.md'), 'utf8');
    expect(md).not.toMatch(/TODO/i);
    const rows = [...md.matchAll(/^\|\s*(\d)\s*\|([^|]+)\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*$/gm)];
    expect(rows.map((r) => Number(r[1])), 'nine screening questions').toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9]);
    for (const r of rows) { expect(r[3], `Q${r[1]} answered`).toMatch(/^(Yes|No|Possibly)/); expect(r[4].length, `Q${r[1]} reason`).toBeGreaterThan(30); }
    expect(md).toMatch(/\*\*Outcome\.\*\*/);
  });
});
