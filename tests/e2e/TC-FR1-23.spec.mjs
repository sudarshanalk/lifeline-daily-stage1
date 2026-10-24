// TC-FR1-23 Category tidy-up — wpcli + crawl against the local WordPress stack (DEC-69; the TC's staging precondition is OI-1,
// so the staging run is deferred). The run changes terms, options and menus, so the affected tables are dumped before and
// restored after (the shared local database is left as found). Fixtures: FX-WP-TIDY (DEC-39…42 dispositions), the baseline
// inventory reports/as-is/live_inventory_public_2026-10-23.json and footer menu reports/as-is/menu-footer.json.
// NEG-02 runs first (a post assigned only to a category the run would delete blocks it, nothing changes), then the apply
// (AS-01…05, AS-07), then NEG-01 (a second apply changes nothing).
import { test, expect } from '@playwright/test';
import { readFileSync, readdirSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const SITE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures;
const TIDY = SITE.find((f) => f.id === 'FX-WP-TIDY').inputs;
const URLS = SITE.find((f) => f.id === 'FX-WP-URLS').inputs;   // the SRS §13 sample rule: its 5 nav categories must still answer after the tidy-up
const INV = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'live_inventory_public_2026-10-23.json'), 'utf8').replace(/^\uFEFF/, ''));
const FOOTER = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'menu-footer.json'), 'utf8').replace(/^\uFEFF/, ''));
const DELETED = { 'womens-health': '/category/wellness/womens-health/', 'healthy-living': '/category/wellness/healthy-living/', stress: '/category/wellness/stress/', uncategorised: '/category/uncategorised/' };
const TABLES = ['wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_termmeta', 'wp_options', 'wp_posts', 'wp_postmeta'];
const a = available();
// each test loads several WordPress pages through the single-threaded local PHP server (5–10 s each): 300 s, not the 60 s default
test.describe.configure({ mode: 'serial', timeout: 300000 });
test.skip(!a.ok, a.reason);

const mariaBin = () => { const base = 'C:/Program Files'; const d = existsSync(base) ? readdirSync(base).find((n) => n.startsWith('MariaDB')) : null; return d ? `${base}/${d}/bin` : ''; };
const DB = process.env.LLD_WP_DB_NAME || 'lifelinedaily_local';
// the same database server the stack uses (tests/lib/wp-stack.mjs reads LLD_WP_DB_HOST the same way)
const [DB_HOST, DB_PORT] = (process.env.LLD_WP_DB_HOST || '127.0.0.1:3399').split(':');
const MYSQL_ARGS = ['-uroot', '-h', DB_HOST, '-P', DB_PORT || '3306'];
let stack, dumpDir, dumpFile, applied;
const ok = (r, what) => { if (r.status !== 0) throw new Error(`${what}: ${r.stdout}${r.stderr}`); return r.stdout.trim(); };
const IMPORT_ORIGIN = 'http://localhost:8080';
test.beforeEach(async ({ page }) => { if (stack && !stack.external) await page.route(`${IMPORT_ORIGIN}/**`, (r) => r.continue({ url: r.request().url().replace(IMPORT_ORIGIN, stack.url) })); });
test.beforeAll(async () => {
  stack = await startWpStack();
  if (stack.external) return;
  dumpDir = mkdtempSync(join(tmpdir(), 'lld-fr123-')); dumpFile = join(dumpDir, 'tables.sql');
  const r = spawnSync(`${mariaBin()}/mariadb-dump.exe`, [...MYSQL_ARGS, `--result-file=${dumpFile}`, DB, ...TABLES], { encoding: 'utf8' });
  if (r.status !== 0) throw new Error('table dump failed: ' + r.stderr);
});
test.afterAll(async () => {
  let err = null;
  if (stack && !stack.external && dumpFile) {
    // restore through stdin (no shell: the MariaDB path contains a space); keep the dump if the restore fails
    const r = spawnSync(`${mariaBin()}/mariadb.exe`, [...MYSQL_ARGS, DB], { input: readFileSync(dumpFile), maxBuffer: 1 << 30 });
    stack.wp('cache', 'flush');
    if (r.status === 0) rmSync(dumpDir, { recursive: true, force: true });
    else err = new Error(`table restore failed (dump kept at ${dumpFile}; restore by hand: mariadb ${MYSQL_ARGS.join(' ')} ${DB} < "${dumpFile}"): ${String(r.stderr).slice(0, 300)}`);
  }
  await stack?.close();
  if (err) throw err;
});
const termSlugs = () => ok(stack.wp('term', 'list', 'category', '--fields=slug', '--format=csv'), 'term list').split(/\r?\n/).slice(1).map((s) => s.trim()).filter(Boolean);
const postCats = () => JSON.parse(ok(stack.wp('eval', "$o=[]; foreach (get_posts(['post_type'=>'post','post_status'=>'any','numberposts'=>-1]) as $p) { $o[$p->ID] = wp_get_post_terms($p->ID,'category',['fields'=>'slugs']); } echo json_encode($o);"), 'post categories'));

test('NEG-02', async () => {
  // a post assigned only to `stress` (deleted by the run): the run must refuse and change nothing
  const stress = ok(stack.wp('term', 'list', 'category', '--slug=stress', '--field=term_id'), 'stress id');
  const pid = ok(stack.wp('post', 'create', '--post_type=post', '--post_author=1', '--post_status=publish', '--post_title=FR1-23 NEG-02 seed', `--post_category=${stress}`, '--porcelain'), 'seed');
  try {
    const before = termSlugs(); const defBefore = ok(stack.wp('option', 'get', 'default_category'), 'default');
    const r = stack.wp('lld', 'tidy', 'apply');
    expect(r.status, 'blocked run exits non-zero').not.toBe(0);
    expect(r.stdout + r.stderr).toMatch(/refusing to delete categories that still have posts: stress \(\d+:publish\)/);
    // --include-drafts never lets a live post through, and a dry run reports the same refusal without changing anything
    const withDrafts = stack.wp('lld', 'tidy', 'apply', '--include-drafts');
    expect(withDrafts.status, '--include-drafts does not bypass a live post').not.toBe(0);
    expect(withDrafts.stdout + withDrafts.stderr).toMatch(/refusing to delete categories that still have posts: stress/);
    const dry = stack.wp('lld', 'tidy', 'apply', '--dry-run', '--include-drafts');
    expect(dry.status, 'dry run exits with the real run verdict').not.toBe(0);
    expect(dry.stdout + dry.stderr).toMatch(/would: delete category stress/);
    expect(termSlugs(), 'no category deleted').toEqual(before);
    expect(ok(stack.wp('option', 'get', 'default_category'), 'default'), 'default unchanged').toBe(defBefore);
    expect(ok(stack.wp('post', 'term', 'list', pid, 'category', '--field=slug'), 'seed cats'), 'the post was not moved').toBe('stress');
  } finally { stack.wp('post', 'delete', pid, '--force'); }
});

test('TC-FR1-23-AS-01', async () => {
  // the snapshot has drafts in uncategorised/healthy-living: without --include-drafts the run refuses and names them; with it they move to the default category
  const snapshot = () => ({ slugs: termSlugs(), def: ok(stack.wp('option', 'get', 'default_category'), 'd'), menu: ok(stack.wp('menu', 'item', 'list', 'header_menu', '--fields=db_id', '--format=csv'), 'm') });
  const pristine = snapshot();
  const refused = stack.wp('lld', 'tidy', 'apply');
  expect(refused.status, 'drafts present: refused without --include-drafts').not.toBe(0);
  expect(refused.stdout + refused.stderr).toMatch(/drafts are assigned to categories that will be deleted/);
  expect(snapshot(), 'the refused run changed nothing (terms, default category, primary menu)').toEqual(pristine);
  // the documented staging order: a dry run with the same flags lists the plan, drafts included, and writes the planned file
  const planned = join(dumpDir, 'planned.json');
  const dry = stack.wp('lld', 'tidy', 'apply', '--dry-run', '--include-drafts', `--planned-out=${planned}`); ok(dry, 'dry run');
  expect(dry.stdout, 'dry run lists the draft moves').toMatch(/would: drafts in \S+ move to the default category: \d+/);
  for (const s of [...TIDY.delete_redirect_wellness, ...TIDY.delete_redirect_root]) expect(dry.stdout, `dry run lists ${s}`).toMatch(new RegExp(`would: delete category ${s} `));
  expect(snapshot(), 'the dry run changed nothing (terms, default category, primary menu)').toEqual(pristine);
  expect(JSON.parse(readFileSync(planned, 'utf8')).categories, '--planned-out writes the same list as content/planned-categories.json').toEqual(JSON.parse(readFileSync(resolve(ROOT, 'content', 'planned-categories.json'), 'utf8')).categories);
  const r = stack.wp('lld', 'tidy', 'apply', '--include-drafts'); ok(r, 'tidy apply'); applied = true;
  const slugs = termSlugs();
  for (const s of [...TIDY.delete_redirect_wellness, ...TIDY.delete_redirect_root]) expect(slugs, `${s} deleted`).not.toContain(s);
  for (const s of TIDY.hide_noindex) expect(slugs, `${s} kept`).toContain(s);
  for (const s of TIDY.keep) expect(slugs, `${s} kept`).toContain(s);
});

test('TC-FR1-23-AS-02', async ({ request }) => {
  for (const [slug, path] of Object.entries(DELETED)) {
    const r = await request.get(stack.url + path, { maxRedirects: 0 });
    expect(r.status(), `${path}: 301`).toBe(301);
    const want = TIDY.delete_redirect_root.includes(slug) ? `${stack.url}/` : `${stack.url}/category/wellness/`;
    expect(r.headers().location, `${path}: target`).toBe(want);
  }
  // FX-WP-URLS: the five primary-nav category archives (rule: "${URLS.rule}") still answer 200 after the run
  expect(URLS.rule).toMatch(/5 nav categories/);
  for (const path of ['/category/fitness/', '/category/food-nutrition/', '/category/health-conditions/', '/category/wellness/', '/category/the-report/']) expect((await request.get(stack.url + path, { maxRedirects: 0 })).status(), `${path}: 200 (FX-WP-URLS nav category)`).toBe(200);
  const rep = await request.get(`${stack.url}/category/the-report/`, { maxRedirects: 0 });
  expect(rep.status(), 'the-report archive 200').toBe(200);
  const body = await rep.text();
  const metas = [...body.matchAll(/<meta\b[^>]*name=["']robots["'][^>]*>/gi)].map((m) => (m[0].match(/content=["']([^"']*)/i) || [])[1] || '');
  expect(metas.length, 'the-report archive has a robots meta').toBeGreaterThan(0);
  for (const c of metas) {
    const tokens = c.split(',').map((t) => t.trim().toLowerCase());
    expect(tokens, `robots meta "${c}" says noindex`).toContain('noindex');
    expect(tokens, `robots meta "${c}" does not also say index`).not.toContain('index');
  }
  expect(rep.headers()['x-robots-tag'] || '', 'X-Robots-Tag noindex').toMatch(/(^|,\s*)noindex/i);
});

test('TC-FR1-23-AS-03', async () => {
  const items = JSON.parse(ok(stack.wp('menu', 'item', 'list', 'header_menu', '--fields=db_id,title,url', '--format=json'), 'menu items'));
  expect(items.length, 'primary menu still has its other items').toBeGreaterThan(5);
  expect(items.filter((i) => /\/the-report\/?$/.test(i.url)), 'no primary menu item links to the-report').toEqual([]);
});

test('TC-FR1-23-AS-04', async () => {
  const idToSlug = Object.fromEntries(INV.categories.map((c) => [c.id, c.slug]));
  const now = postCats();
  const diffs = [];
  for (const p of INV.posts) {
    const want = p.categories.map((id) => idToSlug[id]).sort(); const got = (now[p.id] || []).slice().sort();
    if (JSON.stringify(want) !== JSON.stringify(got)) diffs.push(`${p.id}: inventory ${want.join(',')} now ${got.join(',')}`);
  }
  expect(INV.posts.length, 'baseline inventory posts').toBeGreaterThan(100);
  expect(diffs, 'every post keeps exactly its baseline categories').toEqual([]);
});

test('TC-FR1-23-AS-05', async () => {
  const wellness = ok(stack.wp('term', 'list', 'category', `--slug=${TIDY.default_category}`, '--field=term_id'), 'wellness id');
  expect(ok(stack.wp('option', 'get', 'default_category'), 'default_category')).toBe(wellness);
});

test('TC-FR1-23-AS-07', async ({ page }) => {
  // footer anchors on / equal the baseline footer anchor set (DEC-43): the footer menu items and their rendered links
  const items = JSON.parse(ok(stack.wp('menu', 'item', 'list', 'footer-menu', '--fields=title,url', '--format=json'), 'footer items'));
  const norm = (u) => u.replace(/^https?:\/\/[^/]+/, '');
  expect(items.map((i) => [i.title, norm(i.url)]), 'footer menu = baseline').toEqual(FOOTER.map((i) => [i.title, norm(i.link)]));
  await page.goto(`${stack.url}/`, { waitUntil: 'load' });
  // the rendered footer menu (wp_nav_menu of footer-menu: ul#menu-footer-menu, as on production) equals the baseline set exactly
  const lists = await page.locator('ul[id^="menu-footer-menu"]').evaluateAll((uls) => uls.map((ul) => [...ul.querySelectorAll('a[href]')].map((a) => [a.textContent.trim(), a.getAttribute('href')])));
  expect(lists.length, 'footer menu rendered on /').toBeGreaterThan(0);
  const decode = (t) => t.replace(/&#0?38;|&amp;/g, '&');
  const want = FOOTER.map((f) => [decode(f.title), norm(f.link)]).sort();
  for (const l of lists) expect(l.map(([t, h]) => [t, norm(h)]).sort(), 'rendered footer anchors = baseline set').toEqual(want);
});

test('NEG-01', async () => {
  const before = { slugs: termSlugs(), def: ok(stack.wp('option', 'get', 'default_category'), 'd'), menu: ok(stack.wp('menu', 'item', 'list', 'header_menu', '--fields=db_id', '--format=csv'), 'm') };
  const r = stack.wp('lld', 'tidy', 'apply', '--include-drafts'); ok(r, 'second apply');
  expect(r.stdout + r.stderr, 'second run reports nothing to change').toMatch(/nothing to change/);
  expect({ slugs: termSlugs(), def: ok(stack.wp('option', 'get', 'default_category'), 'd'), menu: ok(stack.wp('menu', 'item', 'list', 'header_menu', '--fields=db_id', '--format=csv'), 'm') }, 'idempotent').toEqual(before);
});
