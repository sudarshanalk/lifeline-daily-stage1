// Fixture access by ID or glob (FX-PR-REN-*), from fixtures/<tool>/*.json (SCH-05).
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from './paths.mjs';

const DIRS = { PR: 'protein', DM: 'diabetes', WP: 'site', MAIL: 'mail' };
let cache = null;

function all() {
  if (cache) return cache;
  cache = [];
  for (const dir of Object.values(DIRS)) {
    for (const f of readdirSync(resolve(ROOT, 'fixtures', dir))) {
      if (!f.endsWith('.json')) continue;
      const doc = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, f), 'utf8'));
      if (!Array.isArray(doc.fixtures)) continue;
      for (const fx of doc.fixtures) cache.push({ ...fx, _file: `fixtures/${dir}/${f}`, _tool: doc.tool });
    }
  }
  return cache;
}

const toRegex = (pattern) => new RegExp('^' + pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');

/** Fixtures matching one id or glob pattern; throws if nothing matches (a typo must not pass silently). */
export function fixtures(pattern) {
  const re = toRegex(pattern);
  const hits = all().filter((fx) => re.test(fx.id));
  if (!hits.length) throw new Error(`no fixture matches ${pattern}`);
  return hits;
}
export function fixture(id) {
  const hits = fixtures(id);
  if (hits.length !== 1) throw new Error(`fixture id ${id} is not unique`);
  return hits[0];
}
export const fixtureIds = (pattern) => fixtures(pattern).map((f) => f.id);
