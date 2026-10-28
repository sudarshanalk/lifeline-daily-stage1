// TC-FR2-26 category restructure (S2-L2): data/categories.json against the agreed list, without WordPress.
// The live tree is checked on staging and production with `wp lld categories verify` (AS-01, AS-06); redirects by crawl (AS-02).
import { execFileSync } from 'node:child_process';
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';

const doc = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'data', 'categories.json'), 'utf8'));
const srs = readFileSync(resolve(ROOT, 'docs', 'stage2', '01_Requirements_Stage2.md'), 'utf8');
const menuDoc = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'data', 'menu.json'), 'utf8'));
const MENU_PHP = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'menu.php');
// runs the pure menu builders in PHP CLI (the file returns before its WordPress wiring when add_filter is undefined)
function menuCall(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(MENU_PHP.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}

// The FR2-26 list, read from the requirement text itself so the data cannot drift from it.
function agreedTree() {
  const row = srs.split('\n').find((l) => l.startsWith('| FR2-26 |'));
  const list = row.match(/\*\*New categories \(subcategories in brackets\):\*\* (.*?)\. \*\*/)[1];
  return list.split(/;\s*/).map((part) => {
    const m = part.match(/^([^(]+?)(?:\s*\(([^)]*)\))?(?:,.*)?$/);
    return { name: m[1].trim(), children: m[2] ? m[2].split(/,\s*/).map((s) => s.trim()) : [] };
  });
}

describe('TC-FR2-26 category restructure data', () => {
  test('TC-FR2-26-AS-01', () => {
    // the tree in the data is the agreed list exactly: same top categories, same subcategories, same order
    const agreed = agreedTree();
    expect(agreed.length).toBe(9);
    expect(doc.tree.map((t) => ({ name: t.name, children: t.children.map((c) => c.name) }))).toEqual(agreed);
    const slugs = doc.tree.flatMap((t) => [t.slug, ...t.children.map((c) => c.slug)]);
    expect(new Set(slugs).size).toBe(slugs.length);
    for (const s of slugs) expect(s).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/);
    expect(slugs).toContain(doc.default_category);

    // the header menu's Articles dropdown is this same list of top categories, in order (owner, 28 Oct 2026)
    const plan = menuCall('lld_menu_plan', menuDoc, doc.tree);
    const top = plan.filter((r) => r.parent === null).map((r) => r.title);
    expect(top).toEqual(['Health Tools', 'Articles', 'Programmes', 'Pricing', 'Sign in', 'Join free', 'My account']);
    const childrenOf = (title) => plan.filter((r) => r.parent === plan.findIndex((p) => p.title === title && p.parent === null));
    expect(childrenOf('Articles').map((r) => [r.kind, r.title])).toEqual(agreed.map((a) => ['category', a.name]));
    expect(childrenOf('Articles').map((r) => r.ref)).toEqual(doc.tree.map((t) => t.slug));
    expect(childrenOf('Health Tools').map((r) => r.kind)).toEqual(['page', 'page', 'page']);
    // no title in capitals only, and every membership address is marked so it stays hidden while membership is off
    for (const r of plan) expect(r.title, r.title).not.toMatch(/^[^a-z]*[A-Z]{3,}[^a-z]*$/);
    for (const r of plan) if (r.kind === 'custom' && /^\/(members|pricing|programmes)\//.test(r.ref)) expect(r.classes, r.title).toContain('lld-menu-members');
    const shown = (members, signedIn) => plan.filter((r) => menuCall('lld_menu_visible', r.classes, members, signedIn)).map((r) => r.title);
    expect(shown(false, false)).toEqual(['Health Tools', ...childrenOf('Health Tools').map((r) => r.title), 'Articles', ...agreed.map((a) => a.name)]);
    expect(shown(true, false)).toContain('Join free'); expect(shown(true, false)).not.toContain('My account');
    expect(shown(true, true)).toContain('My account'); expect(shown(true, true)).not.toContain('Sign in');
  });

  test('TC-FR2-26-AS-02', () => {
    // every old category address redirects to an address of the new tree (or the tools hub), never to itself
    const newPaths = new Set(doc.tree.flatMap((t) => [`/category/${t.slug}/`, ...t.children.map((c) => `/category/${t.slug}/${c.slug}/`)]));
    newPaths.add('/health-tools/');
    expect(Object.keys(doc.redirects).length).toBeGreaterThan(10);
    for (const [from, to] of Object.entries(doc.redirects)) {
      expect(from).toMatch(/^\/category\/.+\/$/); expect(to).not.toBe(from);
      expect(newPaths.has(to), `${from} -> ${to}`).toBe(true);
    }
    // the retired report category and the old top categories are covered
    for (const p of ['/category/the-report/', '/category/wellness/', '/category/food-nutrition/', '/category/fitness/']) expect(doc.redirects[p]).toBeTruthy();
  });

  test('TC-FR2-26-AS-06', () => {
    // Featured, Trending and Video stay as hidden display categories (noindex is applied by the command)
    expect(doc.hidden).toEqual(['featured', 'trending', 'video']);
    const ops = Object.fromEntries(doc.terms.map((o) => [o.from, o.op]));
    for (const h of doc.hidden) expect(ops[h]).toBeUndefined();
    expect(ops['the-report']).toBe('delete');
  });
});
