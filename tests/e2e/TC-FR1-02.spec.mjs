// TC-FR1-02 Preserve WordPress and Newspaper — final crawl diff on the local WordPress stack (DEC-69). The TC's precondition
// is staging after all Stage 1 merges; staging receives only the file overlay (deploy/DEPLOYMENT.md: no database changes are
// deployed), so the "after" state is built here: the run starts from the pristine production snapshot (checked), crawls it
// ("before", same method as "after"), applies every Stage 1 database change the plugin owns — `wp lld pages apply` (hub,
// landing pages, /medical-disclaimer/), `wp lld home apply` (FR1-03), `wp lld tidy apply --include-drafts` (FR1-23) — and
// crawls again. The affected tables are dumped before and restored after, so the shared database is left as found.
// Fixtures: FX-WP-URLS (fixtures/site/url-sample.json, the 30-URL sample) and reports/as-is/crawl-before.json (the
// production crawl of 2026-10-23), FX-WP-TIDY (the FR1-23 set).
// AS-01 for the 30 URLs: status, final URL, canonical, title, meta description, robots and H1 equal crawl-before.json
//       (origin mapped) and the same-method before-crawl; body word count within ±2 % of the before-crawl. FR1-23 set: the
//       four deleted archives answer 301 to their DEC targets; `the-report` answers 200 with noindex. The four CMP-09 pages
//       Stage 1 rebuilds (home, hub, two landing pages) keep every identity field; their word count is reported (DEC-82).
// AS-02 the sitemap URL set after ⊇ before minus the FR1-23 set. AS-03 no theme file is in, or ever was in, the overlay,
//       and the deploy refuses theme paths. AS-04 20 media URLs from the before-crawl answer 200 after. AS-05 `wp role list`
//       and `wp cap list` for the five core roles equal the before state. NEG-01 a 404 injected into the sample fails AS-01.
import { test, expect } from '@playwright/test';
import { readFileSync, readdirSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { crawlRow, fetchChain, sitemapUrls, preservationProblems, CHANGED_BY_DESIGN_PATHS } from '../lib/crawl-diff.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const SAMPLE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'url-sample.json'), 'utf8')).urls;   // FX-WP-URLS
const SITE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures;
const URLS_FX = SITE.find((f) => f.id === 'FX-WP-URLS');
const TIDY = SITE.find((f) => f.id === 'FX-WP-TIDY').inputs;
const BEFORE = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'crawl-before.json'), 'utf8'));
const PROD = 'https://lifelinedaily.com';
// the FR1-23 set: deleted archives with their DEC targets (DEC-39…41) and the hidden, noindexed archive (DEC-42)
const DELETED = { 'womens-health': ['/category/wellness/womens-health/', '/category/wellness/'], 'healthy-living': ['/category/wellness/healthy-living/', '/category/wellness/'], stress: ['/category/wellness/stress/', '/category/wellness/'], uncategorised: ['/category/uncategorised/', '/'] };
const HIDDEN = Object.fromEntries(TIDY.hide_noindex.map((s) => [s, `/category/${s}/`]));
const ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
const CHANGED_BY_DESIGN = new Set(CHANGED_BY_DESIGN_PATHS.map((p) => PROD + p));   // DEC-82: identity checked, word count reported
const TABLES = ['wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_termmeta', 'wp_options', 'wp_posts', 'wp_postmeta'];
const DB = process.env.LLD_WP_DB_NAME || 'lifelinedaily_local';
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

const mariaBin = () => { const base = 'C:/Program Files'; const d = existsSync(base) ? readdirSync(base).find((n) => n.startsWith('MariaDB')) : null; return d ? `${base}/${d}/bin` : ''; };
const ok = (r, what) => { if (r.status !== 0) throw new Error(`${what}: ${r.stdout}${r.stderr}`); return r.stdout.trim(); };
let stack, dumpDir, dumpFile, toLocal, toCanonical;
const crawl = { before: [], after: [] }; const state = {};
const roleState = () => ({ roles: JSON.parse(ok(stack.wp('role', 'list', '--format=json'), 'role list')).map((r) => r.role).sort(), caps: Object.fromEntries(ROLES.map((r) => [r, ok(stack.wp('cap', 'list', r, '--format=json'), `cap list ${r}`)])) });

test.beforeAll(async () => {
  test.setTimeout(1800000);
  stack = await startWpStack();
  if (stack.external) return;
  toLocal = (u) => u.replace(/^https?:\/\/(www\.)?lifelinedaily\.com/, stack.url).replace('http://localhost:8080', stack.url);
  toCanonical = (u) => u.replace(stack.url, PROD).replace('http://localhost:8080', PROD);
  // the before state must be the production snapshot: no Stage 1 database change applied yet
  const landing = stack.wp('post', 'meta', 'get', '6102', 'lld_tool_slug'); const front = ok(stack.wp('option', 'get', 'page_on_front'), 'front');
  const pristine = landing.stdout.trim() === '' && !/lld-home|lld_learn_row|lld_tools_hub/.test(stack.wp('post', 'get', front, '--field=post_content').stdout) && stack.wp('term', 'list', 'category', '--slug=stress', '--field=term_id').stdout.trim() !== '';
  if (!pristine) throw new Error('local database is not the pristine snapshot (Stage 1 changes already applied) — reset it before TC-FR1-02');
  dumpDir = mkdtempSync(join(tmpdir(), 'lld-fr102-')); dumpFile = join(dumpDir, 'tables.sql');
  const d = spawnSync(`${mariaBin()}/mariadb-dump.exe`, ['-uroot', '-P', '3399', '-h', '127.0.0.1', `--result-file=${dumpFile}`, DB, ...TABLES], { encoding: 'utf8' });
  if (d.status !== 0) throw new Error('table dump failed: ' + d.stderr);
  // before
  for (const u of SAMPLE) crawl.before.push(await crawlRow(u, { toLocal, toCanonical }));
  state.sitemapBefore = await sitemapUrls(`${PROD}/sitemap_index.xml`, { toLocal, toCanonical });
  state.rolesBefore = roleState();
  // after: every Stage 1 database change the plugin owns
  for (const cmd of [['pages', 'apply'], ['home', 'apply'], ['tidy', 'apply', '--include-drafts']]) ok(stack.wp('lld', ...cmd), `wp lld ${cmd.join(' ')}`);
  stack.wp('cache', 'flush');
  for (const u of SAMPLE) crawl.after.push(await crawlRow(u, { toLocal, toCanonical }));
  state.sitemapAfter = await sitemapUrls(`${PROD}/sitemap_index.xml`, { toLocal, toCanonical });
  state.rolesAfter = roleState();
});
test.afterAll(async () => {
  let err = null;
  if (stack && !stack.external && dumpFile) {
    const r = spawnSync(`${mariaBin()}/mariadb.exe`, ['-uroot', '-P', '3399', '-h', '127.0.0.1', DB], { input: readFileSync(dumpFile), maxBuffer: 1 << 30 });
    stack.wp('cache', 'flush');
    if (r.status === 0) rmSync(dumpDir, { recursive: true, force: true }); else err = new Error(`table restore failed (dump kept at ${dumpFile}): ${String(r.stderr).slice(0, 300)}`);
  }
  await stack?.close();
  if (err) throw err;
});

/** AS-01's rule over a set of after rows; returns the problems (empty = pass). */
function as01Problems(after) {
  expect(after.length, 'the 30-URL sample').toBe(30);
  return preservationProblems({ before: crawl.before, after, baselineRows: BEFORE.rows, origin: PROD });
}

test('TC-FR1-02-AS-01', async () => {
  test.info().annotations.push({ type: 'sample', description: `${URLS_FX.id}: ${SAMPLE.length} URLs (${crawl.before.filter((b) => BEFORE.rows.some((r) => r.url === b.url)).length} with a crawl-before.json row)` });
  const problems = as01Problems(crawl.after);
  for (const r of crawl.after) test.info().annotations.push({ type: CHANGED_BY_DESIGN.has(r.url) ? 'changed-by-design' : 'row', description: `${r.status} ${r.url} words ${crawl.before.find((b) => b.url === r.url).word_count} -> ${r.word_count}` });
  // the FR1-23 set
  for (const [slug, [path, target]] of Object.entries(DELETED)) {
    const r = await fetchChain(toLocal(PROD + path));
    if (r.status !== 301) problems.push(`${slug}: ${path} answered ${r.status}, not 301`);
    else if (toCanonical(new URL(r.chain[0].location, toLocal(PROD + path)).href) !== PROD + target) problems.push(`${slug}: ${path} -> ${r.chain[0].location}, not ${target}`);
  }
  for (const [slug, path] of Object.entries(HIDDEN)) {
    const r = await fetchChain(toLocal(PROD + path));
    const noindex = /noindex/i.test(r.headers['x-robots-tag'] || '') || /<meta[^>]+name=["']robots["'][^>]+noindex/i.test(r.html);
    if (r.status !== 200 || !noindex) problems.push(`${slug}: ${path} answered ${r.status}${noindex ? '' : ' without noindex'}`);
  }
  expect(problems, 'the sample is preserved and the FR1-23 set behaves as decided').toEqual([]);
});

test('NEG-01', async () => {
  // a 404 injected into the sample: AS-01's rule must fail on it
  const missing = await crawlRow(`${PROD}/fr1-02-neg-01-not-a-page/`, { toLocal, toCanonical });
  expect(missing.status, 'the injected URL is a real 404').toBe(404);
  const injected = crawl.after.map((r, i) => (i === 7 ? { ...missing, url: r.url } : r));
  const problems = as01Problems(injected);
  expect(problems.some((p) => p.includes(crawl.after[7].url) && p.includes('status')), 'AS-01 reports the injected 404').toBe(true);
});

test('TC-FR1-02-AS-02', async () => {
  const removed = new Set([...Object.values(DELETED).map(([p]) => PROD + p), ...Object.values(HIDDEN).map((p) => PROD + p)]);
  expect(state.sitemapBefore.length, 'the before sitemap lists the site').toBeGreaterThan(50);
  const missing = state.sitemapBefore.filter((u) => !removed.has(u) && !state.sitemapAfter.includes(u));
  test.info().annotations.push({ type: 'sitemap', description: `${state.sitemapBefore.length} URLs before, ${state.sitemapAfter.length} after` });
  expect(missing, 'sitemap after ⊇ before minus the FR1-23 set').toEqual([]);
});

test('TC-FR1-02-AS-03', async () => {
  // the repository has no `origin/baseline` ref: the baseline is the repository's root commit; the overlay is the only thing
  // deployed, and theme paths can neither be in it nor be deployed
  const root = spawnSync('git', ['rev-list', '--max-parents=0', 'HEAD'], { cwd: ROOT, encoding: 'utf8' }).stdout.trim().split(/\s+/)[0];
  const diff = spawnSync('git', ['diff', '--stat', root, 'HEAD', '--', 'site/public_html/wp-content/themes/', 'wp-content/themes/'], { cwd: ROOT, encoding: 'utf8' });
  expect(diff.status).toBe(0); expect(diff.stdout.trim(), 'git diff --stat <baseline> -- wp-content/themes/ is empty').toBe('');
  const ever = spawnSync('git', ['log', '--all', '--oneline', '--', 'site/public_html/wp-content/themes', 'wp-content/themes'], { cwd: ROOT, encoding: 'utf8' });
  expect(ever.stdout.trim(), 'no theme path ever committed').toBe('');
  const owned = spawnSync('python', ['-I', '-c', "import importlib.util,sys;s=importlib.util.spec_from_file_location('r',sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);print(m.owned('wp-content/themes/Newspaper/style.css'), m.owned('wp-content/themes/Newspaper/functions.php'))", resolve(ROOT, 'deploy', 'remote.py')], { encoding: 'utf8' });
  expect(owned.stdout.trim(), 'the deploy refuses theme paths').toBe('False False');
});

test('TC-FR1-02-AS-04', async () => {
  const media = [...new Set(crawl.before.flatMap((r) => r.media))].slice(0, 20);
  expect(media.length, '20 media URLs from the before-crawl').toBe(20);
  const bad = [];
  for (const m of media) { const r = await fetch(toLocal(m), { method: 'GET' }); if (r.status !== 200) bad.push(`${r.status} ${m}`); await r.arrayBuffer().catch(() => {}); }
  expect(bad, 'media URLs answer 200 after').toEqual([]);
});

test('TC-FR1-02-AS-05', async () => {
  expect(state.rolesBefore.roles, 'the five core roles exist').toEqual(expect.arrayContaining(ROLES));
  expect(state.rolesAfter, 'wp role list and wp cap list for the five roles are unchanged').toEqual(state.rolesBefore);
  test.info().annotations.push({ type: 'roles', description: state.rolesBefore.roles.join(', ') });
});
