// TC-FR1-02 Preserve WordPress and Newspaper — final crawl diff on the local WordPress stack (DEC-69). The TC's precondition
// is staging after all Stage 1 merges; staging receives only the file overlay (deploy/DEPLOYMENT.md: no database changes are
// deployed), so both states are built here by tests/lib/stage1-states.mjs: "before" is the pristine production snapshot
// served without any Stage 1 code (a bare overlay: no lld-tools plugin, the snapshot's own /tools/*); "after" is the
// repository overlay with every Stage 1 database change the plugin owns applied (pages, home, tidy --include-drafts). The
// whole database is dumped first and restored after. Fixtures: FX-WP-URLS (fixtures/site/url-sample.json, the 30-URL sample)
// and reports/as-is/crawl-before.json (the production crawl of 2026-10-23; it has 29 rows — the sample's
// /prebiotics-and-probiotics-why-your-gut-needs-both/ is compared with the before-crawl only), FX-WP-TIDY (the FR1-23 set).
// AS-01 for the 30 URLs: status, final URL, canonical, title, meta description, robots and H1 equal crawl-before.json
//       (origin mapped) and the before-crawl; body word count within ±2 % of the before-crawl. The four CMP-09 pages Stage 1
//       rebuilds by design (home, hub, two landing pages) keep every identity field; their word count is reported, not
//       asserted (DEC-82, so the assertion is deferred in tests/lib/deferred.json until it is ratified). FR1-23 set: the four
//       deleted archives answer one 301 to their DEC targets; `the-report` answers 200 with noindex.
// AS-02 the sitemap URL set after ⊇ before minus the FR1-23 set (every sitemap must answer). AS-03 no theme file is in, or
//       ever was in, the overlay, and the deploy refuses theme paths (there is no origin/baseline ref: the root commit is the
//       baseline). AS-04 20 media URLs from the before-crawl, taken across pages, answer 200 without a redirect. AS-05
//       `wp role list` and `wp cap list` for the five core roles equal the before state. NEG-01 a 404 injected into the
//       sample fails AS-01.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { available } from '../lib/wp-stack.mjs';
import { crawlRow, preservationProblems, tidyProblems, pickMedia, diffRow, CHANGED_BY_DESIGN_PATHS } from '../lib/crawl-diff.mjs';
import { buildStates, PROD, ROLES } from '../lib/stage1-states.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const SAMPLE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'url-sample.json'), 'utf8')).urls;   // FX-WP-URLS
const SITE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures;
const URLS_FX = SITE.find((f) => f.id === 'FX-WP-URLS');
const TIDY = SITE.find((f) => f.id === 'FX-WP-TIDY').inputs;
const BEFORE = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'crawl-before.json'), 'utf8'));
// the FR1-23 set: deleted archives with their DEC targets (DEC-39…41) and the hidden, noindexed archive (DEC-42)
const DELETED = { 'womens-health': ['/category/wellness/womens-health/', '/category/wellness/'], 'healthy-living': ['/category/wellness/healthy-living/', '/category/wellness/'], stress: ['/category/wellness/stress/', '/category/wellness/'], uncategorised: ['/category/uncategorised/', '/'] };
const HIDDEN = Object.fromEntries(TIDY.hide_noindex.map((s) => [s, `/category/${s}/`]));
const CHANGED_BY_DESIGN = new Set(CHANGED_BY_DESIGN_PATHS.map((p) => PROD + p));
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let S;   // { before, after, stack, maps, restore }
test.beforeAll(async () => { test.setTimeout(3600000); S = await buildStates(SAMPLE); });
test.afterAll(async () => { await S?.restore(); });

/** AS-01's rule over a set of after rows; returns the problems (empty = pass). */
function as01Problems(after) {
  expect(after.length, 'the 30-URL sample').toBe(30);
  return preservationProblems({ before: S.before.rows, after, baselineRows: BEFORE.rows, origin: PROD });
}

test('TC-FR1-02-AS-01', async () => {
  test.setTimeout(600000);
  test.info().annotations.push({ type: 'sample', description: `${URLS_FX.id}: ${SAMPLE.length} URLs (${S.before.rows.filter((b) => BEFORE.rows.some((r) => r.url === b.url)).length} with a crawl-before.json row)` });
  for (const r of S.after.rows) test.info().annotations.push({ type: CHANGED_BY_DESIGN.has(r.url) ? 'changed-by-design' : 'row', description: `${r.status} ${r.url} words ${S.before.rows.find((b) => b.url === r.url).word_count} -> ${r.word_count}` });
  // the strict ±2 % result for the four pages, so a rejected DEC-82 shows what would fail
  const strict = S.after.rows.filter((r) => CHANGED_BY_DESIGN.has(r.url)).flatMap((r) => diffRow(S.before.rows.find((b) => b.url === r.url), r, { words: true, fields: [] }));
  test.info().annotations.push({ type: 'DEC-82 strict', description: strict.length ? strict.join(' | ') : 'the four pages are within ±2 % too' });
  const problems = [...as01Problems(S.after.rows), ...await tidyProblems({ ...S.maps, origin: PROD, deleted: DELETED, hidden: HIDDEN })];
  expect(problems, 'the sample is preserved and the FR1-23 set behaves as decided').toEqual([]);
});

test('NEG-01', async () => {
  // a 404 injected into the sample: AS-01's rule must fail on it
  const missing = await crawlRow(`${PROD}/fr1-02-neg-01-not-a-page/`, S.maps);
  expect(missing.status, 'the injected URL is a real 404').toBe(404);
  const injected = S.after.rows.map((r, i) => (i === 7 ? { ...missing, url: r.url } : r));
  expect(as01Problems(injected).some((p) => p.includes(S.after.rows[7].url) && p.includes('status')), 'AS-01 reports the injected 404').toBe(true);
});

test('TC-FR1-02-AS-02', async () => {
  const removed = new Set([...Object.values(DELETED).map(([p]) => PROD + p), ...Object.values(HIDDEN).map((p) => PROD + p)]);
  expect(S.before.sitemap.length, 'the before sitemap lists the site').toBeGreaterThan(50);
  const missing = S.before.sitemap.filter((u) => !removed.has(u) && !S.after.sitemap.includes(u));
  test.info().annotations.push({ type: 'sitemap', description: `${S.before.sitemap.length} URLs before, ${S.after.sitemap.length} after` });
  expect(missing, 'sitemap after ⊇ before minus the FR1-23 set').toEqual([]);
});

test('TC-FR1-02-AS-03', async () => {
  const root = spawnSync('git', ['rev-list', '--max-parents=0', 'HEAD'], { cwd: ROOT, encoding: 'utf8' }).stdout.trim().split(/\s+/)[0];
  const diff = spawnSync('git', ['diff', '--stat', root, 'HEAD', '--', 'site/public_html/wp-content/themes/', 'wp-content/themes/'], { cwd: ROOT, encoding: 'utf8' });
  expect(diff.status).toBe(0); expect(diff.stdout.trim(), 'git diff --stat <baseline> -- wp-content/themes/ is empty').toBe('');
  const ever = spawnSync('git', ['log', '--all', '--oneline', '--', 'site/public_html/wp-content/themes', 'wp-content/themes'], { cwd: ROOT, encoding: 'utf8' });
  expect(ever.stdout.trim(), 'no theme path ever committed').toBe('');
  const owned = spawnSync('python', ['-I', '-c', "import importlib.util,sys;s=importlib.util.spec_from_file_location('r',sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);print(m.owned('wp-content/themes/Newspaper/style.css'), m.owned('wp-content/themes/Newspaper/functions.php'))", resolve(ROOT, 'deploy', 'remote.py')], { encoding: 'utf8' });
  expect(owned.stdout.trim(), 'the deploy refuses theme paths').toBe('False False');
});

test('TC-FR1-02-AS-04', async () => {
  const media = pickMedia(S.before.rows, 20);
  expect(media.length, '20 media URLs from the before-crawl').toBe(20);
  const bad = [];
  for (const m of media) { const r = await fetch(S.maps.toLocal(m), { redirect: 'manual' }); if (r.status !== 200) bad.push(`${r.status} ${m}`); await r.arrayBuffer().catch(() => {}); }
  expect(bad, 'media URLs answer 200 after, without a redirect').toEqual([]);
});

test('TC-FR1-02-AS-05', async () => {
  expect(S.before.roles.roles, 'the five core roles exist').toEqual(expect.arrayContaining(ROLES));
  expect(S.after.roles, 'wp role list and wp cap list for the five roles are unchanged').toEqual(S.before.roles);
  test.info().annotations.push({ type: 'roles', description: S.before.roles.roles.join(', ') });
});
