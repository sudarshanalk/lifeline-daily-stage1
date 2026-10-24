// TC-NFR1-07 SEO preservation — crawl + http on the local WordPress stack (DEC-69), after every Stage 1 database change the
// plugin owns (`wp lld pages apply`, `wp lld home apply`, `wp lld tidy apply --include-drafts`); the affected tables are
// dumped before and restored after. Fixtures: FX-WP-URLS (fixtures/site/url-sample.json).
// AS-01 TC-FR1-02-AS-01 passes: the same rule (tests/lib/crawl-diff.mjs preservationProblems, DEC-82 pages reported)
//       over a before/after crawl of the 30-URL sample.
// AS-02 the page sitemap lists the hub, both landing pages and /medical-disclaimer/; no sitemap lists /tools/*.html.
// AS-03 each /tools/*.html has a canonical link to its landing page and no noindex (meta or X-Robots-Tag); the landing
//       pages carry no noindex. AS-04 each landing page has JSON-LD that parses and declares @type MedicalWebPage or
//       WebApplication. AS-05 no URL of the sample (plus the FR1-23 redirects) answers through a redirect chain > 1 hop.
import { test, expect } from '@playwright/test';
import { readFileSync, readdirSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { crawlRow, fetchChain, sitemapUrls, preservationProblems } from '../lib/crawl-diff.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const SAMPLE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'url-sample.json'), 'utf8')).urls;   // FX-WP-URLS
const BEFORE = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'crawl-before.json'), 'utf8'));
const CARDS = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const PROD = 'https://lifelinedaily.com';
const TABLES = ['wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_termmeta', 'wp_options', 'wp_posts', 'wp_postmeta'];
const DB = process.env.LLD_WP_DB_NAME || 'lifelinedaily_local';
// the same database server the stack uses (tests/lib/wp-stack.mjs reads LLD_WP_DB_HOST the same way)
const [DB_HOST, DB_PORT] = (process.env.LLD_WP_DB_HOST || '127.0.0.1:3399').split(':');
const MYSQL_ARGS = ['-uroot', '-h', DB_HOST, '-P', DB_PORT || '3306'];
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

const mariaBin = () => { const base = 'C:/Program Files'; const d = existsSync(base) ? readdirSync(base).find((n) => n.startsWith('MariaDB')) : null; return d ? `${base}/${d}/bin` : ''; };
const ok = (r, what) => { if (r.status !== 0) throw new Error(`${what}: ${r.stdout}${r.stderr}`); return r.stdout.trim(); };
let stack, dumpDir, dumpFile, toLocal, toCanonical; const crawl = { before: [], after: [] };

test.beforeAll(async () => {
  test.setTimeout(1800000);
  stack = await startWpStack();
  if (stack.external) return;
  toLocal = (u) => u.replace(/^https?:\/\/(www\.)?lifelinedaily\.com/, stack.url).replace('http://localhost:8080', stack.url);
  toCanonical = (u) => u.replace(stack.url, PROD).replace('http://localhost:8080', PROD);
  if (stack.wp('post', 'meta', 'get', '6102', 'lld_tool_slug').stdout.trim() !== '') throw new Error('local database is not the pristine snapshot — reset it before TC-NFR1-07');
  dumpDir = mkdtempSync(join(tmpdir(), 'lld-nfr107-')); dumpFile = join(dumpDir, 'tables.sql');
  const d = spawnSync(`${mariaBin()}/mariadb-dump.exe`, [...MYSQL_ARGS, `--result-file=${dumpFile}`, DB, ...TABLES], { encoding: 'utf8' });
  if (d.status !== 0) throw new Error('table dump failed: ' + d.stderr);
  for (const u of SAMPLE) crawl.before.push(await crawlRow(u, { toLocal, toCanonical }));
  for (const cmd of [['pages', 'apply'], ['home', 'apply'], ['tidy', 'apply', '--include-drafts']]) ok(stack.wp('lld', ...cmd), `wp lld ${cmd.join(' ')}`);
  stack.wp('cache', 'flush');
  for (const u of SAMPLE) crawl.after.push(await crawlRow(u, { toLocal, toCanonical }));
});
test.afterAll(async () => {
  let err = null;
  if (stack && !stack.external && dumpFile) {
    const r = spawnSync(`${mariaBin()}/mariadb.exe`, [...MYSQL_ARGS, DB], { input: readFileSync(dumpFile), maxBuffer: 1 << 30 });
    stack.wp('cache', 'flush');
    if (r.status === 0) rmSync(dumpDir, { recursive: true, force: true }); else err = new Error(`table restore failed (dump kept at ${dumpFile}; restore by hand: mariadb ${MYSQL_ARGS.join(' ')} ${DB} < "${dumpFile}")`);
  }
  await stack?.close();
  if (err) throw err;
});

test('TC-NFR1-07-AS-01', async () => {
  expect(crawl.after.length, 'the 30-URL sample').toBe(30);
  // the same rule as TC-FR1-02-AS-01 (tests/lib/crawl-diff.mjs), including its DEC-82 handling of the four rebuilt pages
  expect(preservationProblems({ before: crawl.before, after: crawl.after, baselineRows: BEFORE.rows, origin: PROD }), 'TC-FR1-02-AS-01 rule holds').toEqual([]);
});

test('TC-NFR1-07-AS-02', async () => {
  const pages = await sitemapUrls(`${PROD}/page-sitemap.xml`, { toLocal, toCanonical });
  const all = await sitemapUrls(`${PROD}/sitemap_index.xml`, { toLocal, toCanonical });
  for (const p of ['/health-tools/', ...CARDS.map((c) => c.landing_url), '/medical-disclaimer/']) expect(pages, `page sitemap lists ${p}`).toContain(PROD + p);
  expect(all.filter((u) => /\/tools\/[^/]+\.html$/.test(u)), 'no sitemap lists /tools/*.html').toEqual([]);
});

test('TC-NFR1-07-AS-03', async () => {
  const noindex = (r) => /noindex/i.test(r.headers['x-robots-tag'] || '') || /<meta[^>]+name=["']robots["'][^>]*content=["'][^"']*noindex/i.test(r.html);
  for (const c of CARDS) {
    const tool = await fetchChain(`${stack.url}/tools/${c.tool_file || (c.tool_slug === 'protein' ? 'protein-requirement-tool.html' : 'diabetes-risk-tool.html')}`);
    expect(tool.status, `${c.tool_slug}: tool page 200`).toBe(200);
    const canon = tool.html.match(/<link[^>]+rel=["']canonical["'][^>]*href=["']([^"']+)/i)?.[1];
    expect(canon && toCanonical(canon).replace(/^https?:\/\/[^/]+/, ''), `${c.tool_slug}: canonical → landing page`).toBe(c.landing_url);
    expect(noindex(tool), `${c.tool_slug}: tool page not noindex`).toBe(false);
    const landing = await fetchChain(`${stack.url}${c.landing_url}`);
    expect(landing.status).toBe(200);
    expect(noindex(landing), `${c.tool_slug}: landing page not noindex`).toBe(false);
  }
});

test('TC-NFR1-07-AS-04', async () => {
  for (const c of CARDS) {
    const r = await fetchChain(`${stack.url}${c.landing_url}`);
    const types = [];
    for (const m of r.html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) {
      const doc = JSON.parse(m[1]);   // must parse
      const nodes = [doc, ...(doc['@graph'] || [])].flat();
      for (const n of nodes) types.push(...[].concat(n['@type'] || []));
    }
    expect(types.some((t) => ['MedicalWebPage', 'WebApplication'].includes(t)), `${c.tool_slug}: JSON-LD @type MedicalWebPage or WebApplication (found ${types.join(', ') || 'none'})`).toBe(true);
  }
});

test('TC-NFR1-07-AS-05', async () => {
  const long = crawl.after.filter((r) => r.hops > 1).map((r) => r.chain.join(' → '));
  for (const p of ['/category/wellness/womens-health/', '/category/wellness/healthy-living/', '/category/wellness/stress/', '/category/uncategorised/']) {
    const r = await fetchChain(toLocal(PROD + p)); if (r.chain.length - 1 > 1) long.push(r.chain.map((c) => `${c.status} ${c.url}`).join(' → '));
  }
  expect(long, 'no redirect chain > 1 hop').toEqual([]);
});
