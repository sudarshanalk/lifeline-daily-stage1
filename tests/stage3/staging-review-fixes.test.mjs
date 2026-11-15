// P3-36 staging section review (15 Nov 2026): homepage calculator CTA, Search submit, /members/ redirect, terms URL.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
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

test('Bare /members/ goes to Today from a Stage 3 redirect; Stage 2 rewrite rules are left as built', () => {
  const plugin = read('site/public_html/wp-content/plugins/lld-members/includes/class-plugin.php');
  expect(plugin).not.toMatch(/add_rewrite_rule\(\s*'\^members\/\?\$'/);
  const root = read('site/public_html/wp-content/plugins/lld-members/includes/stage3/class-members-root.php');
  expect(root).toMatch(/wp_safe_redirect\( home_url\( '\/members\/home\/' \), 302 \)/);
  expect(read('site/public_html/wp-content/plugins/lld-members/includes/stage3/loader.php')).toMatch(/LLD_Members_Members_Root::boot\(\)/);
});

test('Bare /members/ path check: only the root, with or without a slash, under the home path', () => {
  const out = execFileSync('php', ['-r', `define('ABSPATH','/'); function wp_parse_url($u,$c){return parse_url($u,$c);} require ${JSON.stringify(resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/stage3/class-members-root.php'))};
    $c = [['/members/',''],['/members',''],['/members/?x=1',''],['/members/home/',''],['/membership/',''],['/',''],['/blog/members/','/blog'],['/members/','/blog']];
    echo json_encode(array_map(fn($r)=>LLD_Members_Members_Root::is_root($r[0],$r[1]),$c));`], { encoding: 'utf8' });
  expect(JSON.parse(out)).toEqual([true, true, true, false, false, false, true, false]);
});

test('Terms of sale page is one of the v2 governance pages', () => {
  expect(read('site/public_html/wp-content/mu-plugins/lld-tools/page-v2.php')).toMatch(/'terms-of-sale' => 'Terms of sale'/);
});

test('Member-shell footer Terms of membership uses the canonical /terms-conditions/ URL', () => {
  const components = read('site/public_html/wp-content/plugins/lld-members/includes/components.php');
  expect(components).toContain("home_url( '/terms-conditions/' ) ) . '\">Terms of membership");
  expect(components).not.toContain("home_url( '/terms/' ) ) . '\">Terms of membership");
});
