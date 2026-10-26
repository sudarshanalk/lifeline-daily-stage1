import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(resolve(root, rel), 'utf8');
const growth = read('site/public_html/wp-content/plugins/lld-members/includes/class-growth.php');
const rest = read('site/public_html/wp-content/plugins/lld-members/includes/class-rest.php');

/** Section 8.5 allowlist from the requirements: event name to allowed field names. */
function documentAllowlist() {
  const doc = read('docs/stage2/01_Requirements_Stage2.md');
  const table = doc.slice(doc.indexOf('**Analytics allowlist (Plausible).**'), doc.indexOf('## 9. Sign-offs'));
  const out = {};
  for (const line of table.split('\n')) {
    const cells = line.split('|').map((cell) => cell.trim());
    if (cells.length < 4 || cells[1] === 'Event' || /^-+$/.test(cells[1])) continue;
    const name = cells[1] === 'Page view' ? 'pageview' : cells[1];
    if (!/^[a-z_0-9]+$/.test(name)) continue;
    const fields = cells[2];
    let allowed = [];
    if (name === 'pageview' || fields === 'none') allowed = [];
    else if (/track name/.test(fields)) allowed = ['track'];
    else allowed = [fields.split(':')[0].trim()];
    out[name] = allowed;
  }
  return out;
}

/** The plugin's allowlist() array. */
function pluginAllowlist() {
  const body = growth.slice(growth.indexOf('private static function allowlist()'));
  const block = body.slice(body.indexOf('return array('), body.indexOf(');\n\t}'));
  const out = {};
  for (const match of block.matchAll(/'([a-z_0-9]+)'\s*=>\s*array\(([^)]*)\)/g)) {
    out[match[1]] = [...match[2].matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
  }
  return out;
}

test('TC-FR2-17-AS-04', () => {
  const documented = documentAllowlist();
  expect(Object.keys(documented).sort()).toEqual(['calculator_used', 'pageview', 'registered', 'starter_guide_requested', 'switched_to_yearly', 'track_started', 'upgraded', 'waitlist_joined', 'week1_completed']);
  expect(pluginAllowlist()).toEqual(documented);
  // The Events API needs a top-level name, url and domain; nothing is sent while no domain is configured.
  expect(growth).toMatch(/var body=\{name:name,url:clean\(location\.href\),domain:cfg\.domain\}/);
  expect(growth).toMatch(/LLD_PLAUSIBLE_DOMAIN/);
  expect(growth).toMatch(/if \( '' === \$domain \) \{\s*return '';/);
  // No public route counts anything sent by a browser.
  expect(rest).not.toMatch(/'\/analytics'/);
  expect(growth).not.toMatch(/function rest_analytics/);
});
