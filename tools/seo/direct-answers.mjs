#!/usr/bin/env node
// tools/seo/direct-answers.mjs — P3-80 / FR3-24: direct answers, sources and FAQ markup on every published article.
//
// Reads the site's sitemap index, takes every article in the post sitemap(s), fetches each page once (GET only, at
// most 2 requests in flight, 200 ms apart) and reports per article:
//   TC-FR3-24-AS-01  a direct-answer block (.lld-answer[data-test="direct-answer"]) in the first section
//   TC-FR3-24-AS-02  a Sources section with at least one link that answers 2xx/3xx
//   TC-FR3-24-AS-03  when it has a FAQ section, FAQPage JSON-LD that parses and matches the visible questions
// The judging itself is in lib.mjs and is unit-tested in tests/stage3/seo/.
//
// Usage: node tools/seo/direct-answers.mjs <base-url> [--out report.json] [--md report.md] [--limit n] [--no-links]
//        [--auth user:pass]
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { parseSitemap, isPostSitemap, judgeArticle, extractSources, parseHtml, mdTable, joinUrl } from './lib.mjs';
import { makeQueue, getOnce, linkStatus } from './fetch.mjs';

function parseArgs(argv) {
  const a = { base: null, out: null, md: null, limit: 0, links: true, auth: null };
  for (let i = 0; i < argv.length; i++) {
    const v = argv[i];
    if (v === '--out') a.out = argv[++i];
    else if (v === '--md') a.md = argv[++i];
    else if (v === '--limit') a.limit = parseInt(argv[++i], 10) || 0;
    else if (v === '--no-links') a.links = false;
    else if (v === '--auth') a.auth = 'Basic ' + Buffer.from(argv[++i]).toString('base64');
    else if (v === '-h' || v === '--help') a.help = true;
    else if (!a.base) a.base = v.replace(/\/+$/, '');
  }
  return a;
}

/** Every article URL listed by the post sitemap(s) of a Yoast sitemap index. */
export async function articleUrls(base, get) {
  const index = await get(joinUrl(base, '/sitemap_index.xml'));
  if (index.status !== 200) throw new Error(`sitemap index answers ${index.status}`);
  const { kind, locs } = parseSitemap(index.body);
  const maps = kind === 'index' ? locs.filter(isPostSitemap) : [joinUrl(base, '/sitemap_index.xml')];
  const urls = [];
  for (const m of maps) {
    const r = await get(m);
    if (r.status !== 200) { console.error(`${m} answers ${r.status}`); continue; }
    urls.push(...parseSitemap(r.body).locs);
  }
  return { sitemaps: maps, urls: [...new Set(urls)] };
}

export async function run(args) {
  const queue = makeQueue({ concurrency: 2, gapMs: 200 });
  const get = (u) => queue(() => getOnce(u, { auth: args.auth }));
  const { sitemaps, urls: all } = await articleUrls(args.base, get);
  const urls = args.limit ? all.slice(0, args.limit) : all;
  console.error(`${all.length} articles in ${sitemaps.join(', ')}${args.limit ? `; checking ${urls.length}` : ''}`);
  const linkCache = new Map();
  const results = [];
  let n = 0;
  await Promise.all(urls.map((url) => get(url).then(async (r) => {
    n++;
    if (r.status !== 200) { results.push({ url, status: r.status, ok: false, error: `page answers ${r.status}` }); return; }
    const sourceStatuses = {};
    if (args.links) {
      const { links } = extractSources(parseHtml(r.body));
      for (const l of links) {
        if (!linkCache.has(l)) linkCache.set(l, queue(() => linkStatus(l)));
        sourceStatuses[l] = await linkCache.get(l);
      }
    }
    const j = judgeArticle(r.body, { sourceStatuses });
    results.push({ url, status: 200, ...j });
    if (n % 10 === 0) console.error(`… ${n}/${urls.length}`);
  })));
  results.sort((a, b) => a.url.localeCompare(b.url));
  const fails = {
    answer: results.filter((r) => !r.answer?.ok),
    sources: results.filter((r) => !r.sources?.ok),
    faq: results.filter((r) => r.faq && r.faq.applicable && !r.faq.ok),
    faq_orphan: results.filter((r) => r.faq && !r.faq.applicable && !r.faq.ok),
    page: results.filter((r) => r.status !== 200),
  };
  const summary = {
    articles: results.length,
    with_faq_section: results.filter((r) => r.faq?.applicable).length,
    missing_direct_answer: fails.answer.length,
    missing_working_sources: fails.sources.length,
    invalid_faq: fails.faq.length,
    faq_markup_without_section: fails.faq_orphan.length,
    pages_not_200: fails.page.length,
    all_ok: results.every((r) => r.ok),
  };
  const report = { kind: 'direct-answers', package: 'P3-80', requirement: 'FR3-24', base: args.base, generated_at: new Date().toISOString(),
    sitemaps, links_checked: args.links, summary, results };
  return { report, markdown: toMarkdown(report, fails) };
}

function toMarkdown(report, fails) {
  const s = report.summary;
  const lines = [`# FR3-24 direct answers, sources and FAQ markup — ${report.base}`, '', `Generated ${report.generated_at} by tools/seo/direct-answers.mjs. Articles from ${report.sitemaps.join(', ')}.`, '',
    mdTable(['Check', 'Articles', 'Failing'], [
      ['TC-FR3-24-AS-01 direct answer in the first section', s.articles, s.missing_direct_answer],
      ['TC-FR3-24-AS-02 Sources section with a working link', s.articles, s.missing_working_sources + (report.links_checked ? '' : ' (links not checked)')],
      ['TC-FR3-24-AS-03 valid FAQPage markup (articles with a FAQ section)', s.with_faq_section, s.invalid_faq],
      ['FAQPage markup with no visible FAQ section', s.articles, s.faq_markup_without_section],
      ['Page does not answer 200', s.articles, s.pages_not_200],
    ]), '', `Overall: ${s.all_ok ? 'all articles pass' : 'failures listed below'}.`, ''];
  const section = (title, rows, reason) => {
    if (!rows.length) return;
    lines.push(`## ${title} (${rows.length})`, '', mdTable(['Article', 'Why'], rows.map((r) => [r.url, reason(r)])), '');
  };
  section('No direct answer in the first section', fails.answer, (r) => r.error || r.answer.reason);
  section('No Sources section with a working link', fails.sources, (r) => r.error || r.sources.reason + (r.sources.links?.length ? ` (${r.sources.links.length} links)` : ''));
  section('FAQ section without valid FAQPage markup', fails.faq, (r) => r.faq.reason);
  section('FAQPage markup without a visible FAQ section', fails.faq_orphan, (r) => r.faq.reason);
  section('Pages not answering 200', fails.page, (r) => r.error);
  return lines.join('\n') + '\n';
}

if (process.argv[1] && /direct-answers\.mjs$/.test(process.argv[1].replace(/\\/g, '/'))) {
  const args = parseArgs(process.argv.slice(2));
  if (args.help || !args.base) { console.error('Usage: node tools/seo/direct-answers.mjs <base-url> [--out report.json] [--md report.md] [--limit n] [--no-links] [--auth user:pass]'); process.exit(args.help ? 0 : 2); }
  const { report, markdown } = await run(args);
  if (args.out) { mkdirSync(dirname(args.out), { recursive: true }); writeFileSync(args.out, JSON.stringify(report, null, 2) + '\n'); console.error('wrote ' + args.out); }
  const md = args.md || (args.out ? args.out.replace(/\.json$/i, '') + '.md' : null);
  if (md) { writeFileSync(md, markdown); console.error('wrote ' + md); } else process.stdout.write(markdown);
  console.error(JSON.stringify(report.summary));
  process.exit(report.summary.all_ok ? 0 : 1);
}
