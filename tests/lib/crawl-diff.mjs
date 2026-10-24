// Crawl and compare helpers for the preservation checks (TC-FR1-02, reused by TC-NFR1-07).
// Field extraction follows reports/as-is/crawl-before.json: title, meta description, robots, canonical and the first H1 are
// read from the raw HTML (entities are kept as written, tags inside the H1 are removed); whitespace is collapsed before any
// comparison. The word count is the number of whitespace-separated tokens of the body text with script, style, noscript,
// svg and template content removed; it is only ever compared between two crawls made with this same function.

/** GET `url` without following redirects; follows them hop by hop (max 10) and returns the chain. */
export async function fetchChain(url, { maxHops = 10, headers = {} } = {}) {
  const chain = []; let cur = url, res = null;
  for (let hop = 0; hop <= maxHops; hop++) {
    res = await fetch(cur, { redirect: 'manual', headers });
    chain.push({ url: cur, status: res.status, location: res.headers.get('location') });
    if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
      const next = new URL(res.headers.get('location'), cur).href;
      if (chain.some((c) => c.url === next)) { chain.push({ url: next, status: 'loop' }); break; }
      cur = next; await res.arrayBuffer().catch(() => {}); continue;
    }
    break;
  }
  const html = res && (res.headers.get('content-type') || '').includes('html') ? await res.text() : '';
  return { status: chain[0].status, finalStatus: chain[chain.length - 1].status, final_url: chain[chain.length - 1].url, chain, html, headers: res ? Object.fromEntries(res.headers) : {} };
}

const squash = (s) => (s == null ? null : String(s).replace(/\s+/g, ' ').trim());
const attr = (tag, name) => { const m = tag.match(new RegExp(`\\b${name}\\s*=\\s*("([^"]*)"|'([^']*)')`, 'i')); return m ? (m[2] ?? m[3]) : null; };
function metaContent(html, name) {
  for (const m of html.matchAll(/<meta\b[^>]*>/gi)) if ((attr(m[0], 'name') || '').toLowerCase() === name) return attr(m[0], 'content');
  return null;
}

/** The crawl-before.json fields of one HTML document. */
export function pageFields(html) {
  const title = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
  const h1 = html.match(/<h1\b[^>]*>([\s\S]*?)<\/h1>/i);
  let canonical = null;
  for (const m of html.matchAll(/<link\b[^>]*>/gi)) if ((attr(m[0], 'rel') || '').toLowerCase() === 'canonical') { canonical = attr(m[0], 'href'); break; }
  return {
    canonical,
    title: title ? squash(title[1]) : null,
    meta_description: squash(metaContent(html, 'description')),
    robots: squash(metaContent(html, 'robots')),
    h1: h1 ? squash(h1[1].replace(/<[^>]+>/g, '')) : null,   // tags removed without a separator, as in crawl-before.json
  };
}

export function wordCount(html) {
  const body = (html.match(/<body\b[^>]*>([\s\S]*)<\/body>/i) || [null, html])[1];
  const text = body.replace(/<(script|style|noscript|svg|template)\b[\s\S]*?<\/\1>/gi, ' ').replace(/<!--[\s\S]*?-->/g, ' ').replace(/<[^>]+>/g, ' ');
  return (text.match(/\S+/g) || []).length;
}

/** Same-origin media URLs (wp-content/uploads images) referenced by a page, in document order. */
export function mediaUrls(html, base) {
  const out = [];
  for (const m of html.matchAll(/<img\b[^>]*>/gi)) {
    for (const a of ['src', 'data-src']) { const v = attr(m[0], a); if (v && /\/wp-content\/uploads\//.test(v)) { try { out.push(new URL(v, base).href); } catch {} } }
  }
  return [...new Set(out)];
}

/** One crawl row in crawl-before.json shape (+ chain and media). `toCanonical` maps a local URL to its production form. */
export async function crawlRow(url, { toLocal = (u) => u, toCanonical = (u) => u } = {}) {
  const r = await fetchChain(toLocal(url));
  const f = r.html ? pageFields(r.html) : { canonical: null, title: null, meta_description: null, robots: null, h1: null };
  return {
    url, status: r.status, final_url: toCanonical(r.final_url), final_status: r.finalStatus,
    canonical: f.canonical ? toCanonical(f.canonical) : null, title: f.title, meta_description: f.meta_description, robots: f.robots, h1: f.h1,
    word_count: r.html ? wordCount(r.html) : 0, hops: r.chain.length - 1, chain: r.chain.map((c) => `${c.status} ${toCanonical(c.url)}`),
    media: r.html ? mediaUrls(r.html, r.final_url).map(toCanonical) : [], x_robots_tag: r.headers['x-robots-tag'] || null,
  };
}

export const IDENTITY_FIELDS = ['status', 'final_url', 'canonical', 'title', 'meta_description', 'robots', 'h1'];

/** Differences between a before row and an after row: identity fields equal (whitespace-collapsed) and, when
 *  `words` is set, the body word count within ±tolerance (relative). Returns a list of human-readable problems. */
export function diffRow(before, after, { words = true, tolerance = 0.02, fields = IDENTITY_FIELDS } = {}) {
  const problems = [];
  for (const k of fields) {
    const a = k === 'status' ? before[k] : squash(before[k]); const b = k === 'status' ? after[k] : squash(after[k]);
    if (a !== b) problems.push(`${after.url}: ${k} ${JSON.stringify(a)} -> ${JSON.stringify(b)}`);
  }
  if (words && before.word_count > 0) {
    const d = Math.abs(after.word_count - before.word_count) / before.word_count;
    if (d > tolerance) problems.push(`${after.url}: word count ${before.word_count} -> ${after.word_count} (${(d * 100).toFixed(1)} % > ${tolerance * 100} %)`);
  }
  return problems;
}

/** URLs listed by a Yoast-style sitemap index (and its child sitemaps), as an array. */
export async function sitemapUrls(indexUrl, { toLocal = (u) => u, toCanonical = (u) => u } = {}) {
  const seen = new Set(); const urls = new Set(); const queue = [indexUrl];
  while (queue.length) {
    const u = queue.shift(); if (seen.has(u)) continue; seen.add(u);
    const res = await fetch(toLocal(u)); if (!res.ok) continue;
    const xml = await res.text();
    const isIndex = /<sitemapindex\b/i.test(xml);
    for (const m of xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/gi)) { const loc = toCanonical(m[1].replace(/&amp;/g, '&')); if (isIndex) queue.push(loc); else urls.add(loc); }
  }
  return [...urls];
}

/** The four CMP-09 pages Stage 1 rebuilds by design (home Tools/Learn rows FR1-03, hub FR1-04, landing pages FR1-04/FR1-17):
 *  every identity field is still compared; their word count is reported, not asserted (DEC-82, deferred until ratified). */
export const CHANGED_BY_DESIGN_PATHS = ['/', '/health-tools/', '/health-tools/protein/', '/health-tools/diabetes-risk-check/'];

/** TC-FR1-02-AS-01's rule over the 30-URL sample: identity fields equal the production crawl (`baselineRows`, where a row
 *  exists) and the same-method before-crawl; word count within ±2 % of the before-crawl except the pages above. */
export function preservationProblems({ before, after, baselineRows, origin = 'https://lifelinedaily.com' }) {
  const exempt = new Set(CHANGED_BY_DESIGN_PATHS.map((p) => origin + p));
  const problems = [];
  for (const row of after) {
    const local = before.find((b) => b.url === row.url);
    const prod = baselineRows.find((b) => b.url === row.url);
    if (prod) problems.push(...diffRow(prod, row, { words: false }).map((p) => `vs crawl-before.json: ${p}`));
    if (!local) { problems.push(`${row.url}: no before-crawl row`); continue; }
    problems.push(...diffRow(local, row, { words: !exempt.has(row.url), tolerance: 0.02 }).map((p) => `vs before-crawl: ${p}`));
  }
  return problems;
}
