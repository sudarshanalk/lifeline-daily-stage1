// P3-36 staging section review (15 Nov 2026): homepage calculator CTA, Search submit, /members/ redirect, terms URL.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';

const root = resolve(import.meta.dirname, '../..');
const read = (p) => readFileSync(resolve(root, p), 'utf8');

test('Homepage calculator link says View all calculators (not a fixed count of 3)', () => {
  const home = read('site/public_html/wp-content/mu-plugins/lld-tools/templates/home-v2.php');
  expect(home).toMatch(/View all calculators →/);
  expect(home).not.toMatch(/View all 3 tools/);
});

test('Homepage and Your Health search forms expose a type=submit Search control', () => {
  const home = read('site/public_html/wp-content/mu-plugins/lld-tools/templates/home-v2.php');
  const header = read('site/public_html/wp-content/mu-plugins/lld-tools/templates/parts/v2-header.php');
  expect(home).toMatch(/id="lld-home-search-form"/);
  expect(home).toMatch(/type="submit"[^>]*form="lld-home-search-form"/);
  expect(header).toMatch(/id="lld-menu-search-form"/);
  expect(header).toMatch(/type="submit"[^>]*form="lld-menu-search-form"/);
});

test('Bare /members/ rewrites to the Today screen (same as /members/home/)', () => {
  const plugin = read('site/public_html/wp-content/plugins/lld-members/includes/class-plugin.php');
  expect(plugin).toMatch(/add_rewrite_rule\(\s*'?\^members\/\?\$'?/);
  expect(plugin).toMatch(/rewrite_version\(\)[\s\S]*return '13-/);
});

test('Member-shell footer Terms of membership uses the canonical /terms-conditions/ URL', () => {
  const components = read('site/public_html/wp-content/plugins/lld-members/includes/components.php');
  expect(components).toContain("home_url( '/terms-conditions/' ) ) . '\">Terms of membership");
  expect(components).not.toContain("home_url( '/terms/' ) ) . '\">Terms of membership");
});
