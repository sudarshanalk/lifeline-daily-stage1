#!/usr/bin/env node
// tools/seo/search-console.mjs — P3-81 / FR3-25: the Search Console and Bing fixes, checked on a site.
//
//   TC-FR3-25-AS-01  --redirects <file>: each old address (one per line, taken by the lead from the Search Console and
//                    Bing reports) redirects with 301/308 to a page that answers 200 and is not noindex.
//   TC-FR3-25-AS-02  every URL in the sitemap index answers 200, is not noindex (meta or X-Robots-Tag), has no canonical
//                    pointing elsewhere and is allowed by robots.txt. A site whose robots.txt closes the whole site
//                    ("Disallow: /", as staging does on purpose) is reported as such and the robots rule is not applied.
//   TC-FR3-25-AS-03  --not-indexed <csv>: the "crawled but not indexed" list with a recorded action per article
//                    (columns slug,action,note; actions improved, merged, removed, kept). Checked for completeness only;
//                    with --base the "removed" and "merged" slugs are also fetched and expected to redirect or be gone.
// GET only, at most 2 requests in flight, 200 ms apart. Judging is in lib.mjs, unit-tested in tests/stage3/seo/.
//
// Usage: node tools/seo/search-console.mjs <base-url> --redirects <file> [--not-indexed <csv>] [--out report.json]
//        [--md report.md] [--skip-sitemap] [--auth user:pass]
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { parseSitemap, parseRobots, robotsClosesSite, judgeIndexable, judgeRedirect, parseAddressList, parseNotIndexedCsv, sitemapSection, mdTable, joinUrl } from './lib.mjs';
import { makeQueue, getOnce, getChain, basicAuth } from './fetch.mjs';

function parseArgs(argv) {
  const a = { base: null, redirects: null, notIndexed: null, out: null, md: null, sitemap: true, auth: null };
  for (let i = 0; i < argv.length; i++) {
    const v = argv[i];
    if (v === '--redirects') a.redirects = argv[++i];
    else if (v === '--not-indexed') a.notIndexed = argv[++i];
    else if (v === '--out') a.out = argv[++i];
    else if (v === '--md') a.md = argv[++i];
    else if (v === '--skip-sitemap') a.sitemap = false;
    else if (v === '--auth') a.auth = argv[++i];
    else if (v === '-h' || v === '--help') a.help = true;
    else if (!a.base) a.base = v.replace(/\/+$/, '');
  }
  return a;
}

/** Every page URL under the sitemap index (sub-sitemaps walked). */
export async function sitemapUrls(base, get) {
  const index = await get(joinUrl(base, '/sitemap_index.xml'));
  if (index.status !== 200) throw new Error(`sitemap index answers ${index.status}`);
  const top = parseSitemap(index.body);
  if (top.kind !== 'index') return { sitemaps: ['/sitemap_index.xml'], urls: top.locs, problems: [] };
  const urls = [];
  const problems = [];
  for (const m of top.locs) {
    const r = await get(m);
    if (r.status !== 200) { problems.push(`${m} answers ${r.status}`); continue; }
    urls.push(...parseSitemap(r.body).locs);
  }
  return { sitemaps: top.locs, urls: [...new Set(urls)], problems };
}

export async function run(args) {
  const queue = makeQueue({ concurrency: 2, gapMs: 200 });
  const auth = basicAuth(args.auth, args.base); // sent to the base host only, never to a host a redirect leads to
  const get = (u) => queue(() => getOnce(u, { auth }));
  const report = { kind: 'search-console', package: 'P3-81', requirement: 'FR3-25', base: args.base, generated_at: new Date().toISOString(), sections: {} };

  // robots.txt
  const robotsRes = await get(joinUrl(args.base, '/robots.txt'));
  const robots = robotsRes.status === 200 ? parseRobots(robotsRes.body, 'googlebot') : null;
  const robotsClosed = robots ? robotsClosesSite(robots) : false;
  report.robots = { status: robotsRes.status, site_closed: robotsClosed, note: robotsClosed ? 'robots.txt closes the whole site (expected on staging, which is hidden from search): the robots rule was not applied to the sitemap URLs' : null };

  // AS-01 redirects
  if (args.redirects) {
    const old = parseAddressList(readFileSync(args.redirects, 'utf8'), args.base);
    const rows = await Promise.all(old.map(async (u) => {
      const chain = await queue(() => getChain(u, { auth }));
      const j = judgeRedirect(chain);
      return { url: u, ...j, chain: chain.map((h) => ({ url: h.url, status: h.status, location: h.location })) };
    }));
    report.sections.redirects = { checked: rows.length, failing: rows.filter((r) => !r.ok).length, rows };
  }

  // AS-02 sitemap
  if (args.sitemap) {
    const { sitemaps, urls, problems } = await sitemapUrls(args.base, get);
    console.error(`${urls.length} sitemap URLs in ${sitemaps.length} sitemaps`);
    const rows = await Promise.all(urls.map(async (u) => {
      const r = await get(u);
      return judgeIndexable({ url: u, status: r.status, html: r.body, headers: r.headers, robots, robotsClosed });
    }));
    for (const p of problems) console.error(p + ': its URLs were not checked');
    report.sections.sitemap = sitemapSection({ sitemaps, rows, problems });
  }

  // AS-03 crawled but not indexed
  if (args.notIndexed) {
    const { rows, problems } = parseNotIndexedCsv(readFileSync(args.notIndexed, 'utf8'));
    const gone = rows.filter((r) => r.action === 'removed' || r.action === 'merged');
    const checks = await Promise.all(gone.map(async (r) => {
      const u = /^https?:\/\//i.test(r.slug) ? r.slug : joinUrl(args.base, '/' + r.slug.replace(/^\/+|\/+$/g, '') + '/');
      const chain = await queue(() => getChain(u, { auth }));
      const first = chain[0]?.status;
      const ok = r.action === 'merged' ? judgeRedirect(chain).ok : first === 410 || first === 404 || judgeRedirect(chain).ok;
      return { slug: r.slug, action: r.action, status: first, ok, problem: ok ? null : r.action === 'merged' ? 'a merged article must redirect permanently to its target' : 'a removed article must answer 410/404 or redirect' };
    }));
    report.sections.not_indexed = { rows: rows.length, list_problems: problems, live_checks: checks, failing: problems.length + checks.filter((c) => !c.ok).length };
  }

  const failing = Object.values(report.sections).reduce((n, s) => n + (s.failing || 0), 0);
  report.summary = {
    redirects_checked: report.sections.redirects?.checked ?? null, redirects_failing: report.sections.redirects?.failing ?? null,
    sitemap_urls: report.sections.sitemap?.checked ?? null, sitemap_failing: report.sections.sitemap?.failing ?? null,
    not_indexed_rows: report.sections.not_indexed?.rows ?? null, not_indexed_failing: report.sections.not_indexed?.failing ?? null,
    robots_site_closed: robotsClosed, all_ok: failing === 0,
  };
  return { report, markdown: toMarkdown(report) };
}

function toMarkdown(report) {
  const s = report.summary;
  const L = [`# FR3-25 Search Console and Bing fixes — ${report.base}`, '', `Generated ${report.generated_at} by tools/seo/search-console.mjs.`, ''];
  if (report.robots.note) L.push(`Note: ${report.robots.note}.`, '');
  L.push(mdTable(['Check', 'Checked', 'Failing'], [
    ['TC-FR3-25-AS-01 old addresses redirect 301/308 to a live, indexable page', s.redirects_checked ?? 'not run', s.redirects_failing ?? '—'],
    ['TC-FR3-25-AS-02 sitemap URLs answer 200 and are indexable', s.sitemap_urls ?? 'not run', s.sitemap_failing ?? '—'],
    ['TC-FR3-25-AS-03 crawled-but-not-indexed list has an action per article', s.not_indexed_rows ?? 'not run', s.not_indexed_failing ?? '—'],
  ]), '', `Overall: ${s.all_ok ? 'all checks pass' : 'failures listed below'}.`, '');
  const r = report.sections.redirects;
  if (r && r.failing) L.push(`## Old addresses not redirecting correctly (${r.failing})`, '', mdTable(['Old address', 'Chain', 'Why'], r.rows.filter((x) => !x.ok).map((x) => [x.url, x.chain.map((h) => h.status).join(' → '), x.problems.join('; ')])), '');
  const m = report.sections.sitemap;
  if (m && m.failing_urls) L.push(`## Sitemap URLs not indexable (${m.failing_urls})`, '', mdTable(['URL', 'Status', 'Why'], m.rows.filter((x) => !x.ok).map((x) => [x.url, x.status, x.problems.join('; ')])), '');
  if (m && m.sitemap_problems.length) L.push(`## Sitemaps that could not be read (${m.sitemap_problems.length}; their URLs were not checked, so the run fails)`, '', ...m.sitemap_problems.map((p) => `- ${p}`), '');
  const n = report.sections.not_indexed;
  if (n && n.failing) {
    L.push(`## Crawled-but-not-indexed list problems (${n.failing})`, '');
    for (const p of n.list_problems) L.push(`- ${p}`);
    const bad = n.live_checks.filter((c) => !c.ok);
    if (bad.length) L.push('', mdTable(['Slug', 'Action', 'Status', 'Why'], bad.map((c) => [c.slug, c.action, c.status, c.problem])));
    L.push('');
  }
  return L.join('\n') + '\n';
}

if (process.argv[1] && /search-console\.mjs$/.test(process.argv[1].replace(/\\/g, '/'))) {
  const args = parseArgs(process.argv.slice(2));
  if (args.help || !args.base || (!args.redirects && !args.notIndexed && !args.sitemap)) {
    console.error('Usage: node tools/seo/search-console.mjs <base-url> --redirects <file> [--not-indexed <csv>] [--out report.json] [--md report.md] [--skip-sitemap] [--auth user:pass]');
    process.exit(args.help ? 0 : 2);
  }
  const { report, markdown } = await run(args);
  if (args.out) { mkdirSync(dirname(args.out), { recursive: true }); writeFileSync(args.out, JSON.stringify(report, null, 2) + '\n'); console.error('wrote ' + args.out); }
  const md = args.md || (args.out ? args.out.replace(/\.json$/i, '') + '.md' : null);
  if (md) { writeFileSync(md, markdown); console.error('wrote ' + md); } else process.stdout.write(markdown);
  console.error(JSON.stringify(report.summary));
  process.exit(report.summary.all_ok ? 0 : 1);
}
