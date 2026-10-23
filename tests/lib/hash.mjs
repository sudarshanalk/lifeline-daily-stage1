import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

export const sha256 = (data) => createHash('sha256').update(data).digest('hex');
export const sha256File = (path) => sha256(readFileSync(path));
/** Stable JSON (sorted keys) so hashes do not depend on insertion order. */
export function canonical(value) {
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  if (value && typeof value === 'object') {
    return '{' + Object.keys(value).sort().map((k) => JSON.stringify(k) + ':' + canonical(value[k])).join(',') + '}';
  }
  return JSON.stringify(value);
}
