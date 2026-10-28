#!/usr/bin/env node
// FR2-20 (package L3) — blog article SEO crawl for Lifeline Daily (Newspaper theme + Yoast SEO).
//
// Usage:
//   node tools/lead/seo-crawl.mjs --base https://lifelinedaily.com --out reports/stage2/l3/crawl-before.json
//   node tools/lead/seo-crawl.mjs --base https://stg.lifelinedaily.com --out reports/stage2/l3/crawl-after-stg.json
// Options:
//   --csv <file>        per-article CSV (default: <out without .json>-articles.csv)
//   --rate <n>          max requests per second to the crawled host (default 2)
//   --ua <string>       user agent (default lld-lead-audit)
//   --max-pages <n>     safety cap on crawled HTML pages (default 1500)
//   --extra <file>      JSON file: array of paths/URLs to also check as "retired" URLs (e.g. old slugs)
//   --redirects <file>  lld-tools redirects.json; its source paths are added to the retired checks
//                       (default: the repo copy in site/.../mu-plugins/lld-tools/data/redirects.json, if present)
//   --links <file>      lld-tools links.json (DEC-13 article -> calculator rule; default: repo copy)
//   --no-external       skip external link checks
//   --auth user:pass    HTTP basic auth for the crawled host (staging behind a password), optional
//
// Discovery: Yoast sitemap index (/sitemap_index.xml) + internal links found on crawled pages.
// Crawled as pages: internal URLs without a query string that are not files, feeds, admin or API paths.
// Every distinct internal link target is checked once (HEAD, GET fallback, redirects followed by hand so
// the chain is recorded). Every distinct external URL gets at most one GET (redirects followed by fetch).
// Only GET/HEAD requests are sent.

import { JSDOM, VirtualConsole } from 'jsdom';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const PLUGIN_DATA = path.join(REPO, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'data');

// ------------------------------------------------------------------ arguments
const argv = process.argv.slice(2);
const opt = (name, def) => { const i = argv.indexOf('--' + name); return i >= 0 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : def; };
const flag = (name) => argv.includes('--' + name);
const BASE = (opt('base') || '').replace(/\/+$/, '');
const OUT = opt('out');
if (!BASE || !OUT) { console.error('usage: node tools/lead/seo-crawl.mjs --base https://lifelinedaily.com --out <file.json> [--csv file] [--rate 2]'); process.exit(2); }
const CSV = opt('csv', OUT.replace(/\.json$/i, '') + '-articles.csv');
const RATE = Number(opt('rate', '2'));
const UA = opt('ua', 'lld-lead-audit');
const MAX_PAGES = Number(opt('max-pages', '1500'));
const NO_EXTERNAL = flag('no-external');
const AUTH = opt('auth') ? 'Basic ' + Buffer.from(opt('auth')).toString('base64') : null;
const BASE_URL = new URL(BASE + '/');
const HOST = BASE_URL.hostname.replace(/^www\./, '');
const readJson = (p) => { try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch { return null; } };
const LINKS_RULE = readJson(opt('links', path.join(PLUGIN_DATA, 'links.json'))) || {
  diabetes_tags: ['diabetes', 'type-2-diabetes', 'diabetes-prevention', 'diabetes-management', 'diabetes-diet', 'blood-sugar', 'insulin-resistance', 'glycaemic-index'],
  diabetes_categories: ['metabolic-health'],
  protein_categories: ['nutrition-for-healthy-living', 'healthy-eating-for-longevity', 'healthy-diet-plans-for-longevity', 'food-nutrition', 'fitness'],
};
const REDIRECTS = readJson(opt('redirects', path.join(PLUGIN_DATA, 'redirects.json')));

// ------------------------------------------------------------------ severities (FR2-20)
export const SEVERITY = {
  serious: {
    http_error: 'Page answers 4xx/5xx',
    broken_internal_link: 'Internal link target answers 4xx/5xx or fails',
    title_missing: 'No <title>',
    description_missing: 'Article has no meta description',
    description_duplicate: 'Article meta description is used by another indexable page',
    title_duplicate: 'Title is used by another indexable page',
    h1_missing: 'No H1',
    h1_multiple: 'More than one H1',
    noindex_article: 'Article is noindex (should be indexed)',
    canonical_mismatch: 'Canonical points to a different URL',
    internal_redirect_chain: 'Internal link goes through more than 1 redirect hop',
  },
  minor: {
    title_too_long: 'Title over 60 characters',
    title_too_short: 'Title under 30 characters',
    description_too_long: 'Meta description over 160 characters',
    description_too_short: 'Meta description under 70 characters',
    description_missing_nonarticle: 'Non-article page has no meta description',
    image_missing_alt: 'Image without non-empty alt text',
    external_link_broken: 'External link answers 404/410/5xx or the host does not resolve',
    orphan_article: 'Article has no in-content link from another article or page',
    calculator_link_missing: 'Article topic matches a calculator but the article does not link to it',
    author_missing: 'Health article shows no author',
    reviewer_missing: 'Health article shows no reviewer ("Reviewed by")',
    thin_content: 'Article body under 300 words',
  },
  info: {
    internal_link_redirect: 'Internal link goes through exactly 1 redirect (works, but should point at the final URL)',
    external_link_timeout: 'External link timed out (not counted as broken)',
    external_link_blocked: 'External host refused the check (401/403/429/999) — not verifiable by a bot',
    noindex_nonarticle: 'Non-article page is noindex',
    canonical_missing: 'No canonical link',
    sitemap_url_redirects: 'URL listed in the sitemap redirects',
    author_generic: 'Author shown is the site brand, not a named person',
  },
};
const sevOf = (type) => (SEVERITY.serious[type] ? 'serious' : SEVERITY.minor[type] ? 'minor' : 'info');

// Calculators and tracks
const CALCULATORS = {
  protein: { name: 'Daily Protein Target', paths: ['/health-tools/protein/', '/tools/protein-requirement-tool.html'] },
  diabetes: { name: 'Diabetes Risk Check', paths: ['/health-tools/diabetes-risk-check/', '/tools/diabetes-risk-tool.html'] },
};
const TRACKS = [ // order breaks ties
  { name: 'Blood Sugar Balance', cats: ['metabolic-health'], words: ['diabet', 'blood sugar', 'glucose', 'insulin', 'glycaemic', 'glycemic', 'prediabet', 'a1c', 'metabolic syndrome', 'carb'] },
  { name: 'Sleep and Stress', cats: ['sleep-and-recovery', 'mental-health', 'stress'], words: ['sleep', 'insomnia', 'stress', 'anxiety', 'anxious', 'cortisol', 'mental health', 'depression', 'burnout', 'mindful', 'meditat', 'relax', 'calm', 'mood'] },
  { name: 'Fitness and Strength', cats: ['fitness'], words: ['fitness', 'exercise', 'workout', 'strength', 'muscle', 'yoga', 'walking', 'running', 'cardio', 'training', 'pilates', 'stretch', 'mobility', 'hiit'] },
  { name: 'Healthy Weight', cats: ['healthy-diet-plans-for-longevity'], words: ['weight', 'fat loss', 'belly fat', 'obesity', 'calorie', 'keto', 'fasting', 'appetite', 'diet plan', 'slim', 'bmi', 'waist'] },
];

// ------------------------------------------------------------------ http with per-host rate limit
const nextSlot = new Map();
async function slot(host) {
  const gap = host === HOST ? 1000 / RATE : 1000; // crawled host: RATE/s; any external host: 1/s
  for (;;) {
    const now = Date.now(); const t = nextSlot.get(host) || 0;
    if (t <= now) { nextSlot.set(host, now + gap); return; }
    await new Promise((r) => setTimeout(r, t - now));
  }
}
const hostOf = (u) => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return ''; } };
let requestCount = 0;
async function request(url, { method = 'GET', redirect = 'manual', timeout = 20000, body = false } = {}) {
  await slot(hostOf(url));
  requestCount++;
  const ctl = new AbortController(); const tm = setTimeout(() => ctl.abort(), timeout);
  const headers = { 'user-agent': UA, accept: body ? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' : '*/*' };
  if (AUTH && hostOf(url) === HOST) headers.authorization = AUTH;
  try {
    const res = await fetch(url, { method, redirect, signal: ctl.signal, headers });
    let text = null;
    if (body) text = await res.text(); else { try { await res.body?.cancel(); } catch { /* ignore */ } }
    return { status: res.status, headers: res.headers, location: res.headers.get('location'), url: res.url, text };
  } catch (e) {
    const timedOut = e.name === 'AbortError' || /abort|timeout/i.test(String(e.message));
    return { status: 0, error: timedOut ? 'timeout' : (e.cause?.code || e.message || 'error') };
  } finally { clearTimeout(tm); }
}
// Follow redirects by hand (internal), recording each hop.
async function fetchChain(url, { method = 'GET', body = false } = {}) {
  const chain = []; let cur = url;
  for (let i = 0; i < 10; i++) {
    const r = await request(cur, { method, body });
    if (r.status >= 300 && r.status < 400 && r.location) {
      const next = new URL(r.location, cur).href;
      chain.push({ url: cur, status: r.status, location: next });
      cur = next; continue;
    }
    return { final_url: cur, status: r.status, error: r.error || null, chain, res: r };
  }
  return { final_url: cur, status: 0, error: 'too many redirects', chain, res: null };
}

// ------------------------------------------------------------------ url helpers
const isInternal = (u) => { try { const x = new URL(u); return /^https?:$/.test(x.protocol) && x.hostname.replace(/^www\./, '') === HOST; } catch { return false; } };
function norm(href, baseHref) {
  try {
    const u = new URL(href, baseHref);
    if (!/^https?:$/.test(u.protocol)) return null;
    u.hash = '';
    return u.href;
  } catch { return null; }
}
const SKIP_CHECK = /\/(wp-admin|wp-login\.php|xmlrpc\.php|wp-json)(\/|$|\?)|[?&](replytocom|share)=/i;
const FILE_EXT = /\.(jpe?g|png|gif|webp|avif|svg|pdf|zip|mp4|mp3|webm|css|js|xml|txt|ico|woff2?|ttf|json|html?)$/i;
function crawlable(u) {
  if (!isInternal(u)) return false;
  const x = new URL(u);
  if (x.search) return false;
  if (SKIP_CHECK.test(u)) return false;
  if (/\/(feed|comments\/feed|embed|trackback|amp)\/?$/.test(x.pathname)) return false;
  if (/^\/wp-(content|includes)\//.test(x.pathname)) return false;
  if (FILE_EXT.test(x.pathname)) return false;
  return true;
}
// Normalise an internal URL to the crawled scheme/host so http:// or www. links match crawled pages.
const canonicalKey = (u) => { try { const x = new URL(u); x.hash = ''; return (x.hostname.replace(/^www\./, '') + x.pathname.replace(/\/+$/, '/') + x.search).toLowerCase(); } catch { return u; } };

// ------------------------------------------------------------------ sitemap discovery
async function sitemapUrls() {
  const out = []; const idx = await request(BASE + '/sitemap_index.xml', { body: true });
  if (idx.status !== 200 || !idx.text) return { urls: out, sitemaps: [], error: 'sitemap index status ' + idx.status };
  const locs = (xml) => [...xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map((m) => m[1].replace(/&amp;/g, '&'));
  const maps = locs(idx.text); const sitemaps = [];
  for (const sm of maps) {
    const r = await request(sm, { body: true });
    const list = r.status === 200 && r.text ? locs(r.text) : [];
    sitemaps.push({ sitemap: sm, status: r.status, count: list.length });
    for (const u of list) out.push({ url: u, sitemap: sm.split('/').pop() });
  }
  return { urls: out, sitemaps };
}

// ------------------------------------------------------------------ page parsing
const vc = new VirtualConsole(); // swallow CSS parse noise
const CHROME = '.td-header-template-wrap, .td-footer-template-wrap, header, footer, nav, .td-mobile-container, .td-menu-background, .td-search-background, #td-mobile-nav';
function wordCount(el) {
  if (!el) return 0;
  const c = el.cloneNode(true);
  c.querySelectorAll('script, style, noscript, svg, template, .lld-review-byline').forEach((n) => n.remove());
  return (c.textContent.match(/[\p{L}\p{N}][\p{L}\p{N}'’\-]*/gu) || []).length;
}
function jsonldTypes(doc) {
  const types = new Set(); const nodes = [];
  for (const s of doc.querySelectorAll('script[type="application/ld+json"]')) {
    let j; try { j = JSON.parse(s.textContent); } catch { continue; }
    const walk = (o) => {
      if (Array.isArray(o)) return o.forEach(walk);
      if (o && typeof o === 'object') {
        if (o['@type']) { [].concat(o['@type']).forEach((t) => types.add(String(t))); nodes.push(o); }
        Object.values(o).forEach(walk);
      }
    };
    walk(j);
  }
  return { types: [...types].sort(), nodes };
}
function pageType(bodyClass) {
  const c = ' ' + bodyClass + ' ';
  if (/ single-post /.test(c)) return 'post';
  if (/ home /.test(c) || / page-template-default home /.test(c)) return 'home';
  if (/ page /.test(c) || / page-id-\d+ /.test(c)) return 'page';
  if (/ category /.test(c)) return 'category';
  if (/ tag /.test(c)) return 'tag';
  if (/ author /.test(c)) return 'author';
  if (/ search /.test(c)) return 'search';
  if (/ error404 /.test(c)) return '404';
  if (/ archive /.test(c)) return 'archive';
  return 'other';
}
const lastSeg = (href) => { try { return new URL(href).pathname.replace(/\/+$/, '').split('/').pop(); } catch { return ''; } };

function parsePage(html, finalUrl, headers) {
  const dom = new JSDOM(html, { url: finalUrl, virtualConsole: vc });
  const doc = dom.window.document;
  try {
    const bodyClass = doc.body?.className || '';
    const type = pageType(bodyClass);
    const paged = / paged /.test(' ' + bodyClass + ' ') || /\/page\/\d+\/?$/.test(new URL(finalUrl).pathname);
    const title = (doc.querySelector('head > title')?.textContent || '').trim().replace(/\s+/g, ' ');
    const desc = doc.querySelector('meta[name="description"]')?.getAttribute('content');
    const robots = doc.querySelector('meta[name="robots"]')?.getAttribute('content') || null;
    const xrt = headers?.get?.('x-robots-tag') || null;
    const canonical = doc.querySelector('link[rel="canonical"]')?.getAttribute('href') || null;
    const h1s = [...doc.querySelectorAll('h1')].map((h) => h.textContent.trim().replace(/\s+/g, ' ').slice(0, 140));
    const { types, nodes } = jsonldTypes(doc);

    // content root
    let content = doc.querySelector('.tdb_single_content .tdb-block-inner') || doc.querySelector('.td-post-content') || doc.querySelector('.entry-content');
    if (!content && (type === 'page' || type === 'home')) content = doc.querySelector('.tdc-content-wrap') || doc.querySelector('main');
    const inChrome = (el) => !!el.closest(CHROME);

    // links
    const internal = new Map(); const external = new Map();
    for (const a of doc.querySelectorAll('a[href]')) {
      const raw = a.getAttribute('href').trim();
      if (!raw || raw.startsWith('#') || /^(mailto|tel|javascript|sms|whatsapp):/i.test(raw)) continue;
      const u = norm(raw, finalUrl); if (!u) continue;
      const inContent = !!(content && content.contains(a));
      const zone = inContent ? 'content' : inChrome(a) ? 'chrome' : 'body';
      const m = isInternal(u) ? internal : external;
      const prev = m.get(u);
      if (!prev) m.set(u, { href: u, in_content: inContent, zones: [zone], text: a.textContent.trim().replace(/\s+/g, ' ').slice(0, 80) });
      else { prev.in_content ||= inContent; if (!prev.zones.includes(zone)) prev.zones.push(zone); }
    }

    // images (content area for articles; everything outside header/footer otherwise)
    // posts: article body + featured image (theme author avatars and ad spots are not article images)
    const imgRoots = type === 'post' && content ? [content, ...doc.querySelectorAll('.tdb_single_featured_image')] : [doc.body];
    const seen = new Set(); const images = [];
    for (const img of imgRoots.flatMap((r) => (r ? [...r.querySelectorAll('img')] : []))) {
      if (img.closest('noscript') || (imgRoots[0] === doc.body && inChrome(img))) continue;
      const src = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || img.getAttribute('src') || '';
      if (!src || src.startsWith('data:')) continue;
      const key = norm(src, finalUrl) || src; if (seen.has(key)) continue; seen.add(key);
      const alt = img.getAttribute('alt');
      images.push({ src: key, alt: alt === null ? null : alt.trim(), has_alt: !!(alt && alt.trim()) });
    }

    // author and reviewer
    let author = (doc.querySelector('.tdb-author-name')?.textContent || doc.querySelector('.td-post-author-name a')?.textContent || '').trim() || null;
    let authorSource = author ? 'theme byline' : null;
    if (!author) { const m = doc.querySelector('meta[name="author"]')?.getAttribute('content'); if (m) { author = m.trim(); authorSource = 'meta author'; } }
    let reviewer = false; let reviewerSource = null;
    if (doc.querySelector('.lld-review-byline, [data-test="review-byline"]')) { reviewer = true; reviewerSource = 'lld review byline'; }
    else {
      const scope = (content || doc.body)?.cloneNode(true);
      scope?.querySelectorAll('script, style').forEach((n) => n.remove());
      const metaArea = [...doc.querySelectorAll('.tdb-post-meta, .tdb_single_author, .td-post-header, .td-module-meta-info')].map((e) => e.textContent).join(' ');
      if (/\b(medically\s+)?reviewed\s+by\b|\bfact[- ]checked\s+by\b/i.test((scope?.textContent || '') + ' ' + metaArea)) { reviewer = true; reviewerSource = 'text "Reviewed by"'; }
      else if (nodes.some((n) => n.reviewedBy)) { reviewer = true; reviewerSource = 'JSON-LD reviewedBy'; }
    }

    // dates
    const meta = (p) => doc.querySelector(`meta[property="${p}"]`)?.getAttribute('content') || null;
    const art = nodes.find((n) => [].concat(n['@type']).some((t) => /Article|BlogPosting|WebPage/.test(t)) && (n.datePublished || n.dateModified));
    const published = meta('article:published_time') || art?.datePublished || null;
    const modified = meta('article:modified_time') || art?.dateModified || null;

    // taxonomy
    const categories = [...new Set([...doc.querySelectorAll('.tdb_single_categories a[href], .tdb-category a[href]')].map((a) => lastSeg(norm(a.getAttribute('href'), finalUrl))).filter(Boolean))];
    const tags = [...new Set([...doc.querySelectorAll('.tdb-tags a[href], .td-tags a[href]')].map((a) => lastSeg(norm(a.getAttribute('href'), finalUrl))).filter(Boolean))];
    const articleSection = [...new Set(nodes.flatMap((n) => [].concat(n.articleSection || [])))];
    const keywords = [...new Set(nodes.flatMap((n) => [].concat(n.keywords || [])))];

    return {
      type, paged, title, desc: desc === null || desc === undefined ? null : desc.trim(), robots, xrt, canonical, h1s, types,
      word_count: wordCount(type === 'post' ? content : content || doc.body), has_content_root: !!content,
      video_embeds: content ? content.querySelectorAll('iframe[src*="youtube"], iframe[data-litespeed-src*="youtube"], iframe[data-src*="youtube"], video').length : 0,
      internal: [...internal.values()], external: [...external.values()], images,
      author, authorSource, reviewer, reviewerSource, published, modified, categories, tags, articleSection, keywords,
    };
  } finally { dom.window.close(); }
}

// ------------------------------------------------------------------ matching
function matchCalculator(p) {
  const tags = p.tags || []; const cats = p.categories || [];
  if (tags.some((t) => (LINKS_RULE.diabetes_tags || []).includes(t))) return 'diabetes';
  if (cats.some((c) => (LINKS_RULE.diabetes_categories || []).includes(c))) return 'diabetes';
  if (cats.some((c) => (LINKS_RULE.protein_categories || []).includes(c))) return 'protein';
  return null;
}
function matchTrack(p) {
  const titleText = (p.title + ' ' + lastSeg(p.final_url).replace(/-/g, ' ')).toLowerCase();
  const taxText = [...(p.tags || []), ...(p.keywords || []), ...(p.articleSection || [])].join(' ').toLowerCase().replace(/-/g, ' ');
  let best = null; let bestScore = 0;
  for (const t of TRACKS) {
    let s = 0;
    if ((p.categories || []).some((c) => t.cats.includes(c))) s += 3;
    for (const w of t.words) { if (titleText.includes(w)) s += 2; if (taxText.includes(w)) s += 1; }
    if (s > bestScore) { best = t.name; bestScore = s; }
  }
  return bestScore >= 2 ? best : 'none';
}

// ------------------------------------------------------------------ pool helper
async function pool(items, n, fn) { let i = 0; await Promise.all(Array.from({ length: Math.min(n, items.length) }, async () => { while (i < items.length) { const k = i++; await fn(items[k], k); } })); }

// ------------------------------------------------------------------ main
async function main() {
  const t0 = Date.now();
  console.error(`[seo-crawl] base ${BASE} rate ${RATE}/s ua ${UA}`);
  const sm = await sitemapUrls();
  console.error(`[seo-crawl] sitemap: ${sm.urls.length} URLs in ${sm.sitemaps.length} sitemaps`);

  const queue = []; const known = new Map(); // key -> {url, sources:Set, sitemap}
  const enqueue = (u, source, sitemap) => {
    const k = canonicalKey(u);
    let e = known.get(k);
    if (!e) { e = { url: u, sources: new Set(), sitemap: null }; known.set(k, e); queue.push(e); }
    e.sources.add(source); if (sitemap) e.sitemap = sitemap;
  };
  enqueue(BASE + '/', 'base');
  for (const { url, sitemap } of sm.urls) if (isInternal(url)) enqueue(url, 'sitemap', sitemap);

  // retired / legacy URLs to check
  const retiredInputs = [];
  if (REDIRECTS?.redirects) for (const [from, to] of Object.entries(REDIRECTS.redirects)) retiredInputs.push({ path: from, expected: typeof to === 'string' ? to : to.to, origin: 'redirects.json' });
  const extraFile = opt('extra'); if (extraFile) for (const p of readJson(extraFile) || []) retiredInputs.push({ path: p, expected: null, origin: 'extra' });

  const pages = []; const byKey = new Map(); // final-url key -> page
  const reqKey = new Map(); // requested url key -> page
  let qi = 0; let active = 0;
  const crawlOne = async (e) => {
    const r = await fetchChain(e.url, { body: true });
    const ctype = r.res?.headers?.get?.('content-type') || '';
    const pg = {
      url: e.url, discovered_by: [...e.sources], sitemap: e.sitemap, status: r.status, error: r.error,
      final_url: r.final_url, redirect_chain: r.chain.map((c) => ({ url: c.url, status: c.status })),
    };
    reqKey.set(canonicalKey(e.url), pg);
    if (r.status === 200 && /html/i.test(ctype) && r.res.text) {
      if (r.chain.length && byKey.has(canonicalKey(r.final_url))) { pg.duplicate_of = byKey.get(canonicalKey(r.final_url)).url; pages.push(pg); return; }
      const x = parsePage(r.res.text, r.final_url, r.res.headers);
      Object.assign(pg, {
        type: x.type, paged: x.paged, title: x.title, title_length: [...x.title].length,
        meta_description: x.desc, description_length: x.desc ? [...x.desc].length : 0,
        canonical: x.canonical, robots: x.robots, x_robots_tag: x.xrt,
        noindex: /noindex/i.test((x.robots || '') + ' ' + (x.xrt || '')),
        h1_count: x.h1s.length, h1: x.h1s, word_count: x.word_count, has_content_root: x.has_content_root, video_embeds: x.video_embeds,
        author: x.author, author_source: x.authorSource, reviewer_shown: x.reviewer, reviewer_source: x.reviewerSource,
        published: x.published, modified: x.modified, jsonld_types: x.types,
        categories: x.categories, tags: x.tags, article_section: x.articleSection, keywords: x.keywords,
        images_total: x.images.length, images_missing_alt: x.images.filter((i) => !i.has_alt).map((i) => i.src),
        internal_links_out: x.internal, external_links_out: x.external,
      });
      byKey.set(canonicalKey(r.final_url), pg);
      for (const l of x.internal) if (crawlable(l.href) && known.size < MAX_PAGES) enqueue(l.href, 'link');
    } else if (r.status === 200) { pg.non_html = ctype; }
    pages.push(pg);
  };
  // crawl (small pool; the rate limiter enforces RATE/s)
  const workers = Array.from({ length: 3 }, async () => {
    for (;;) {
      if (qi >= queue.length) { await new Promise((r) => setTimeout(r, 300)); if (qi >= queue.length && active === 0) return; continue; }
      const e = queue[qi++]; active++;
      try { await crawlOne(e); } catch (err) { pages.push({ url: e.url, status: 0, error: 'crawl error: ' + err.message }); } finally { active--; }
      if (pages.length % 25 === 0) console.error(`[seo-crawl] pages ${pages.length}/${queue.length} requests ${requestCount}`);
    }
  });
  await Promise.all(workers);
  console.error(`[seo-crawl] crawled ${pages.length} URLs`);

  // ---------------- internal link targets
  const targets = new Map(); // href -> result
  for (const p of pages) for (const l of p.internal_links_out || []) if (!SKIP_CHECK.test(l.href)) targets.set(l.href, null);
  const targetList = [...targets.keys()];
  await pool(targetList, 3, async (href) => {
    const known1 = reqKey.get(canonicalKey(href));
    if (known1 && canonicalKey(known1.url) === canonicalKey(href) && new URL(known1.url).protocol === new URL(href).protocol && new URL(known1.url).hostname === new URL(href).hostname) {
      targets.set(href, { status: known1.status, final_url: known1.final_url, hops: (known1.redirect_chain || []).length, chain: known1.redirect_chain || [], error: known1.error || null, via: 'crawl' });
      return;
    }
    let r = await fetchChain(href, { method: 'HEAD' });
    if (r.status === 0 || r.status === 405 || r.status === 403 || r.status === 501 || r.status >= 500) { const g = await fetchChain(href, { method: 'GET' }); r = g; r.via = 'GET'; }
    targets.set(href, { status: r.status, final_url: r.final_url, hops: r.chain.length, chain: r.chain.map((c) => ({ url: c.url, status: c.status })), error: r.error, via: r.via || 'HEAD' });
  });
  console.error(`[seo-crawl] checked ${targetList.length} internal link targets`);

  // ---------------- external links
  const externals = new Map();
  if (!NO_EXTERNAL) {
    for (const p of pages) for (const l of p.external_links_out || []) if (p.type === 'post' || p.type === 'page' || p.type === 'home') externals.set(l.href, null);
    const extList = [...externals.keys()];
    await pool(extList, 6, async (href) => {
      const r = await request(href, { method: 'GET', redirect: 'follow', timeout: 15000 });
      let outcome;
      if (r.error === 'timeout') outcome = 'timeout';
      else if (r.status === 0) outcome = /ENOTFOUND|EAI_AGAIN|ECONNREFUSED|CERT|SSL|ECONNRESET/i.test(r.error || '') ? 'broken' : 'error';
      else if ([401, 403, 429, 999].includes(r.status)) outcome = 'blocked';
      else if (r.status === 404 || r.status === 410 || r.status >= 500) outcome = 'broken';
      else if (r.status >= 400) outcome = 'client_error';
      else outcome = 'ok';
      externals.set(href, { status: r.status, final_url: r.url || null, error: r.error || null, outcome });
    });
    console.error(`[seo-crawl] checked ${extList.length} external URLs`);
  }

  // ---------------- retired URL checks
  const retired = [];
  for (const ri of retiredInputs) {
    const u = norm(ri.path, BASE + '/');
    const r = await fetchChain(u, { method: 'HEAD' });
    const ok = (r.chain.length > 0 && r.status >= 200 && r.status < 400) || r.status === 410;
    retired.push({ url: u, origin: ri.origin, expected_target: ri.expected, status: r.status, final_url: r.final_url, chain: r.chain.map((c) => ({ url: c.url, status: c.status })), ok });
  }

  // ---------------- inbound links / orphans
  const html = pages.filter((p) => p.title !== undefined && p.status === 200);
  const articles = html.filter((p) => p.type === 'post');
  const resolve = (href) => { const t = targets.get(href); return canonicalKey(t?.final_url || href); };
  const inAny = new Map(); const inContent = new Map();
  for (const a of articles) { inAny.set(canonicalKey(a.final_url), new Set()); inContent.set(canonicalKey(a.final_url), new Set()); }
  const LISTING = new Set(['category', 'tag', 'author', 'archive', 'search']);
  for (const src of html) {
    if (LISTING.has(src.type) || src.paged) continue;
    const sk = canonicalKey(src.final_url);
    for (const l of src.internal_links_out || []) {
      const k = resolve(l.href); if (k === sk || !inAny.has(k)) continue;
      inAny.get(k).add(src.final_url);
      if (l.in_content && (src.type === 'post' || src.type === 'page')) inContent.get(k).add(src.final_url);
    }
  }

  // ---------------- issues
  const add = (p, type, detail) => { (p.issues ||= []).push({ severity: sevOf(type), type, detail: detail ?? null }); };
  const indexable = html.filter((p) => !p.noindex && !p.paged && !p.duplicate_of);
  const dupMap = (key) => { const m = new Map(); for (const p of indexable) { const v = p[key]; if (!v) continue; const k = v.toLowerCase(); (m.get(k) || m.set(k, []).get(k)).push(p.final_url); } return m; };
  const titles = dupMap('title'); const descs = dupMap('meta_description');

  for (const p of pages) {
    if (p.status >= 400 || p.status === 0) {
      if (p.discovered_by.some((s) => s !== 'link')) add(p, 'http_error', `${p.status || p.error}`);
      continue;
    }
    if (p.discovered_by?.includes('sitemap') && p.redirect_chain?.length) add(p, 'sitemap_url_redirects', p.final_url);
    if (p.title === undefined || p.duplicate_of) continue;
    const isArticle = p.type === 'post';
    if (!p.title) add(p, 'title_missing');
    else {
      if (p.title_length > 60) add(p, 'title_too_long', `${p.title_length} chars`);
      if (p.title_length < 30) add(p, 'title_too_short', `${p.title_length} chars`);
      const d = titles.get(p.title.toLowerCase()); if (!p.noindex && !p.paged && d && d.length > 1) add(p, 'title_duplicate', d.filter((u) => u !== p.final_url).join(' '));
    }
    if (!p.meta_description) add(p, isArticle ? 'description_missing' : 'description_missing_nonarticle');
    else {
      if (p.description_length > 160) add(p, 'description_too_long', `${p.description_length} chars`);
      if (p.description_length < 70) add(p, 'description_too_short', `${p.description_length} chars`);
      const d = descs.get(p.meta_description.toLowerCase());
      if (isArticle && !p.noindex && d && d.length > 1) add(p, 'description_duplicate', d.filter((u) => u !== p.final_url).join(' '));
    }
    if (p.h1_count === 0) add(p, 'h1_missing');
    if (p.h1_count > 1) add(p, 'h1_multiple', `${p.h1_count} H1`);
    if (p.noindex) add(p, isArticle ? 'noindex_article' : 'noindex_nonarticle', p.robots || p.x_robots_tag);
    if (!p.canonical) add(p, 'canonical_missing');
    else if (canonicalKey(norm(p.canonical, p.final_url)) !== canonicalKey(p.final_url)) add(p, 'canonical_mismatch', p.canonical);

    for (const l of p.internal_links_out || []) {
      const t = targets.get(l.href); if (!t) continue;
      l.status = t.status; l.final_url = t.final_url; l.hops = t.hops;
      if (t.status >= 400 || t.status === 0) add(p, 'broken_internal_link', `${l.href} -> ${t.status || t.error}${l.in_content ? '' : ' (outside article body)'}`);
      else if (t.hops > 1) add(p, 'internal_redirect_chain', `${l.href} (${t.hops} hops) -> ${t.final_url}`);
      else if (t.hops === 1) add(p, 'internal_link_redirect', `${l.href} -> ${t.final_url}`);
    }
    for (const l of p.external_links_out || []) {
      const t = externals.get(l.href); if (!t) continue;
      l.status = t.status; l.outcome = t.outcome;
      if (t.outcome === 'broken') add(p, 'external_link_broken', `${l.href} -> ${t.status || t.error}`);
      else if (t.outcome === 'timeout') add(p, 'external_link_timeout', l.href);
      else if (t.outcome === 'blocked') add(p, 'external_link_blocked', `${l.href} -> ${t.status}`);
    }
    if (isArticle) {
      if (p.images_missing_alt.length) add(p, 'image_missing_alt', `${p.images_missing_alt.length} of ${p.images_total}`);
      const k = canonicalKey(p.final_url);
      p.inbound_any = inAny.get(k)?.size || 0; p.inbound_content = inContent.get(k)?.size || 0;
      if (p.inbound_content === 0) add(p, 'orphan_article', p.inbound_any === 0 ? 'no inbound links at all' : `no in-content links (${p.inbound_any} other links)`);
      p.matched_calculator = matchCalculator(p); p.matched_track = matchTrack(p);
      const contentLinks = (p.internal_links_out || []).filter((l) => l.in_content).map((l) => new URL(l.final_url || l.href).pathname);
      p.calculator_links = Object.entries(CALCULATORS).filter(([, c]) => contentLinks.some((pa) => c.paths.includes(pa))).map(([k]) => k);
      if (p.matched_calculator && !p.calculator_links.includes(p.matched_calculator)) add(p, 'calculator_link_missing', CALCULATORS[p.matched_calculator].name);
      // every article on this site is health content (YMYL): author + reviewer required
      if (!p.author) add(p, 'author_missing'); else if (/^lifeline daily$/i.test(p.author)) add(p, 'author_generic', p.author);
      if (!p.reviewer_shown) add(p, 'reviewer_missing');
      if (p.word_count < 300) add(p, 'thin_content', `${p.word_count} words${p.video_embeds ? ', video embed only' : ''}`);
    } else if (p.images_missing_alt?.length && (p.type === 'page' || p.type === 'home')) add(p, 'image_missing_alt', `${p.images_missing_alt.length} of ${p.images_total}`);
  }

  // ---------------- totals
  const allIssues = pages.flatMap((p) => (p.issues || []).map((i) => ({ ...i, url: p.final_url || p.url, page_type: p.type || null })));
  const bySev = { serious: 0, minor: 0, info: 0 }; const byType = {};
  for (const i of allIssues) { bySev[i.severity]++; byType[i.type] = (byType[i.type] || 0) + 1; }
  const artWith = (sev) => articles.filter((a) => (a.issues || []).some((i) => i.severity === sev)).length;
  const brokenTargets = [...targets.entries()].filter(([, t]) => t && (t.status >= 400 || t.status === 0));
  const report = {
    crawled: new Date().toISOString(), base: BASE, tool: 'tools/lead/seo-crawl.mjs', requirement: 'FR2-20', user_agent: UA, rate_per_second: RATE,
    duration_seconds: Math.round((Date.now() - t0) / 1000), requests: requestCount,
    severity_definitions: SEVERITY,
    rules: {
      article: 'WordPress post (body class single-post). Every article is treated as a health article (whole site is health content).',
      orphan: 'inbound links counted from crawled non-listing pages (category, tag, author, archive and paginated pages and the sitemap excluded). inbound_any = any link anywhere on the source page; inbound_content = link inside the article/page body. orphan_article (minor) = inbound_content 0.',
      calculator_match: 'DEC-13 rule from lld-tools data/links.json (tag rule, then diabetes categories, then protein categories); per-post lld_tool_override meta is not visible from outside and is ignored.',
      calculator_link: 'link inside the article body to the calculator page (or tool HTML), after redirects.',
      track_match: 'keyword score on title/slug (2), tags/keywords/sections (1) and category (3); best track with score >= 2, else none.',
      reviewer: 'lld-tools review byline (.lld-review-byline), or "Reviewed by"/"Fact-checked by" text in the body or post meta, or JSON-LD reviewedBy.',
      external: 'one GET per distinct external URL linked from posts/pages; 404/410/5xx/DNS failure = broken; 401/403/429/999 = blocked (info); timeout = info.',
    },
    sitemaps: sm.sitemaps,
    totals: {
      urls_crawled: pages.length, html_pages: html.length,
      by_type: html.reduce((m, p) => ((m[p.type + (p.paged ? ' (paged)' : '')] = (m[p.type + (p.paged ? ' (paged)' : '')] || 0) + 1), m), {}),
      articles: articles.length,
      articles_with_serious: artWith('serious'), articles_with_minor: artWith('minor'),
      articles_with_minor_pct: articles.length ? Math.round((artWith('minor') / articles.length) * 1000) / 10 : 0,
      issues_by_severity: bySev, issues_by_type: byType,
      internal_link_targets: targetList.length, broken_internal_targets: brokenTargets.length,
      external_urls: externals.size,
      external_by_outcome: [...externals.values()].reduce((m, e) => ((m[e.outcome] = (m[e.outcome] || 0) + 1), m), {}),
      orphans_no_links_at_all: articles.filter((a) => a.inbound_any === 0).length,
      orphans_no_in_content_links: articles.filter((a) => a.inbound_content === 0).length,
      retired_checked: retired.length, retired_ok: retired.filter((r) => r.ok).length,
    },
    broken_internal_links: pages.flatMap((p) => (p.internal_links_out || []).filter((l) => l.status >= 400 || l.status === 0).map((l) => ({ source: p.final_url, target: l.href, status: l.status, in_content: l.in_content, text: l.text }))),
    retired_checks: retired,
    internal_link_targets: Object.fromEntries(targets),
    external_links: Object.fromEntries(externals),
    issues: allIssues,
    pages,
  };
  fs.mkdirSync(path.dirname(path.resolve(OUT)), { recursive: true });
  // author archive slugs on this site are derived from the account e-mail address: never write them out
  const redact = (txt) => txt.replace(/\/author\/[^/"\s?#]+/g, '/author/[redacted]');
  fs.writeFileSync(OUT, redact(JSON.stringify(report, null, 1)));

  // CSV: one row per article
  const q = (s) => '"' + String(s ?? '').replace(/"/g, '""') + '"';
  const rows = [['url', 'title', 'matched_track', 'matched_calculator', 'serious', 'minor', 'issues'].join(',')];
  for (const a of articles.sort((x, y) => x.final_url.localeCompare(y.final_url))) {
    const iss = (a.issues || []).filter((i) => i.severity !== 'info');
    rows.push([q(a.final_url), q(a.title), q(a.matched_track), q(a.matched_calculator || ''), iss.filter((i) => i.severity === 'serious').length, iss.filter((i) => i.severity === 'minor').length,
      q(iss.map((i) => `${i.severity}:${i.type}${i.detail ? ' (' + i.detail + ')' : ''}`).join('; '))].join(','));
  }
  fs.writeFileSync(CSV, '﻿' + redact(rows.join('\r\n')) + '\r\n');
  console.error(`[seo-crawl] done in ${report.duration_seconds}s, ${requestCount} requests -> ${OUT}, ${CSV}`);
  console.log(JSON.stringify(report.totals, null, 1));
}

main().catch((e) => { console.error(e); process.exit(1); });
