// Runs the pure builders of lld-tools/search.php in PHP CLI (no WordPress): the file returns before its WordPress wiring
// when add_action is undefined. call(fn, ...args) returns the JSON-decoded result.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';

export const SEARCH_PHP = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'search.php');
export const SEARCH_CFG = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'data', 'search.json'), 'utf8'));

export function call(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(SEARCH_PHP.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  const out = execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' });
  return JSON.parse(out);
}
