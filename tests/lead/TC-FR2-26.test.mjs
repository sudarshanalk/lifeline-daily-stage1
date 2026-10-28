// TC-FR2-26 category restructure (S2-L2): data/categories.json against the agreed list, without WordPress.
// The live tree is checked on staging and production with `wp lld categories verify` (AS-01, AS-06); redirects by crawl (AS-02).
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';

const doc = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'data', 'categories.json'), 'utf8'));
const srs = readFileSync(resolve(ROOT, 'docs', 'stage2', '01_Requirements_Stage2.md'), 'utf8');

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
