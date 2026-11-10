// tools/seo/lib.mjs — the parsing and judging logic shared by direct-answers.mjs, search-console.mjs and speed.mjs
// (P3-80 to P3-82: FR3-24, FR3-25, NFR3-11). Everything here is pure: it takes strings and headers and returns
// judgements, so tests/stage3/seo/ can run it over fixture HTML and XML with no network. The network side (polite
// fetching, sitemap walking, the lab browser run) lives in the three tools.
import { JSDOM, VirtualConsole } from 'jsdom';

/* ------------------------------------------------------------------ HTML parsing ------------------------------- */

/** Parse an HTML string without jsdom's console noise (CSS it cannot parse, scripts are never run). */
export function parseHtml(html) {
  const vc = new VirtualConsole();
  vc.on('jsdomError', () => {});
  return new JSDOM(html, { virtualConsole: vc }).window.document;
}

/** Whitespace-normalised text. */
export const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();

/* ------------------------------------------------------------------ sitemaps ---------------------------------- */

/** Every <loc> of a sitemap index or a URL set. Returns { kind: 'index'|'urlset'|'unknown', locs: [] }. */
export function parseSitemap(xml) {
  const text = String(xml ?? '');
  const locs = [...text.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map((m) => decodeXml(m[1]));
  const kind = /<sitemapindex[\s>]/.test(text) ? 'index' : /<urlset[\s>]/.test(text) ? 'urlset' : 'unknown';
  return { kind, locs };
}

function decodeXml(s) {
  return s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
}

/**
 * Which sitemap URLs are article (post) pages. Yoast names the post sitemap post-sitemap.xml (or post-sitemap2.xml);
 * any sitemap whose file name starts with "post-sitemap" counts. Other sitemaps (pages, categories, the members'
 * sitemap) are not articles.
 */
export const isPostSitemap = (url) => /\/post-sitemap\d*\.xml$/i.test(String(url));

/* ------------------------------------------------------------------ FR3-24: direct answers, sources, FAQ ------ */

// What lld-tools/search.php emits ([lld_answer], [lld_faq]/[lld_q], [lld_sources]/[lld_source]).
export const MARKUP = {
  answer: '.lld-answer[data-test="direct-answer"], [data-test="direct-answer"], .lld-answer',
  faq: 'section.lld-faq[data-test="faq"], [data-test="faq"], section.lld-faq',
  faqQuestion: '.lld-faq__q',
  sources: 'section.lld-sources[data-test="sources"], [data-test="sources"], section.lld-sources',
  // The article body: Newspaper's cloud-template single content, then the classic template, then the page.
  content: ['.tdb_single_content .tdb-block-inner', '.tdb_single_content', '.td-post-content', 'article', 'main', 'body'],
};

/** The article body element of a page. */
export function contentRoot(doc) {
  for (const sel of MARKUP.content) {
    const el = doc.querySelector(sel);
    if (el) return el;
  }
  return doc.body;
}

/** The direct answer's word budget, as lld_search_aeo_row applies it (FR2-21 AS-01). */
export const ANSWER_MAX_WORDS = 60;

/** The first node of the article body that carries visible text (style/script/noscript and empty wrappers skipped). */
function firstTextElement(root) {
  const SKIP = new Set(['STYLE', 'SCRIPT', 'NOSCRIPT', 'TEMPLATE']);
  for (const node of root.childNodes) {
    if (node.nodeType === 3) { if (norm(node.textContent)) return node; continue; }
    if (node.nodeType !== 1 || SKIP.has(node.tagName)) continue;
    if (norm(node.textContent)) return node;
  }
  return null;
}

/**
 * TC-FR3-24-AS-01: the direct-answer block is the article body's first content, as the mu-plugin's own rule has it
 * (lld_search_aeo_row: nothing but whitespace before [lld_answer], 1 to 60 words). The first text-bearing node of the
 * body must be the block itself or a wrapper whose first text-bearing node is the block.
 */
export function judgeDirectAnswer(doc) {
  const root = contentRoot(doc);
  const answer = root.querySelector(MARKUP.answer) || doc.querySelector(MARKUP.answer);
  if (!answer) return { present: false, first_section: false, words: 0, text: null, ok: false, reason: 'no direct-answer block (.lld-answer[data-test="direct-answer"])' };
  const text = norm(answer.textContent);
  const words = text ? text.split(' ').length : 0;
  const inBody = root.contains(answer);
  let first = inBody ? firstTextElement(root) : null;
  while (first && first !== answer && first.nodeType === 1 && first.contains(answer)) first = firstTextElement(first);
  const firstSection = inBody && first === answer;
  const ok = firstSection && words > 0 && words <= ANSWER_MAX_WORDS;
  return {
    present: true, first_section: firstSection, words, text, ok,
    reason: ok ? null : words === 0 ? 'direct answer is empty' : !inBody ? 'direct answer is outside the article body' : !firstSection ? 'direct answer is not the first content of the article body' : `direct answer is ${words} words (over ${ANSWER_MAX_WORDS})`,
  };
}

/** The Sources section and its links (absolute http(s) only). */
export function extractSources(doc) {
  const section = doc.querySelector(MARKUP.sources);
  if (!section) return { present: false, links: [] };
  const links = [...section.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')).filter((h) => /^https?:\/\//i.test(h));
  return { present: true, links: [...new Set(links)] };
}

/**
 * TC-FR3-24-AS-02: a Sources section with at least one link that answers 2xx once its redirects are followed. `statuses`
 * maps href -> the final HTTP status (or a string such as 'error: redirect loop') as found by the tool; a 3xx reaching
 * the judge is an unresolved redirect and does not count. A link not in the map is treated as unchecked.
 */
export function judgeSources(doc, statuses = {}) {
  const { present, links } = extractSources(doc);
  if (!present) return { present: false, links: [], working: [], ok: false, reason: 'no Sources section (section.lld-sources[data-test="sources"])' };
  if (!links.length) return { present: true, links: [], working: [], ok: false, reason: 'Sources section has no links' };
  const working = links.filter((h) => isWorkingStatus(statuses[h]));
  const unchecked = links.filter((h) => statuses[h] === undefined);
  const ok = working.length > 0;
  return { present: true, links, working, unchecked, ok, reason: ok ? null : unchecked.length === links.length ? 'source links not checked' : 'no source link answers 2xx' };
}

export const isWorkingStatus = (s) => typeof s === 'number' && s >= 200 && s < 300;

/** The visible FAQ questions (the <h3 class="lld-faq__q"> headings), in order. */
export function extractFaqQuestions(doc) {
  const section = doc.querySelector(MARKUP.faq);
  if (!section) return { present: false, questions: [] };
  return { present: true, questions: [...section.querySelectorAll(MARKUP.faqQuestion)].map((h) => norm(h.textContent)).filter(Boolean) };
}

/** Every JSON-LD block on the page, parsed; unparseable ones are returned as { error }. Yoast's @graph is flattened. */
export function extractJsonLd(doc) {
  const out = [];
  for (const s of doc.querySelectorAll('script[type="application/ld+json"]')) {
    const raw = s.textContent || '';
    try {
      const data = JSON.parse(raw);
      const items = Array.isArray(data) ? data : data && Array.isArray(data['@graph']) ? data['@graph'] : [data];
      for (const it of items) out.push({ data: it });
    } catch (e) {
      out.push({ error: String(e.message || e), raw: raw.slice(0, 200) });
    }
  }
  return out;
}

const typeIs = (d, t) => d && (d['@type'] === t || (Array.isArray(d['@type']) && d['@type'].includes(t)));

/**
 * TC-FR3-24-AS-03: an article with a question-and-answer section carries FAQPage JSON-LD that parses and whose questions
 * match the visible ones (same set of question texts, whitespace-normalised, case-sensitive). An article without a FAQ
 * section is not required to carry FAQPage markup, but a FAQPage without a visible section is reported.
 */
export function judgeFaq(doc) {
  const { present, questions } = extractFaqQuestions(doc);
  const blocks = extractJsonLd(doc);
  const broken = blocks.filter((b) => b.error);
  const faqPages = blocks.filter((b) => b.data && typeIs(b.data, 'FAQPage'));
  const schemaQuestions = faqPages.flatMap((b) => (Array.isArray(b.data.mainEntity) ? b.data.mainEntity : []))
    .filter((q) => typeIs(q, 'Question')).map((q) => norm(q.name));
  const missingAnswers = faqPages.flatMap((b) => (Array.isArray(b.data.mainEntity) ? b.data.mainEntity : []))
    .filter((q) => typeIs(q, 'Question') && !(q.acceptedAnswer && norm(q.acceptedAnswer.text)));
  if (!present) {
    return { has_faq_section: false, visible: [], schema: schemaQuestions, applicable: false,
      ok: faqPages.length === 0, reason: faqPages.length ? 'FAQPage JSON-LD present but no visible FAQ section' : null };
  }
  if (!faqPages.length) {
    return { has_faq_section: true, visible: questions, schema: [], applicable: true, ok: false,
      reason: broken.length ? 'FAQPage JSON-LD does not parse: ' + broken[0].error : 'FAQ section has no FAQPage JSON-LD' };
  }
  const missing = questions.filter((q) => !schemaQuestions.includes(q));
  const extra = schemaQuestions.filter((q) => !questions.includes(q));
  const ok = !missing.length && !extra.length && !missingAnswers.length && questions.length > 0;
  return { has_faq_section: true, visible: questions, schema: schemaQuestions, applicable: true, ok, missing, extra,
    reason: ok ? null : missingAnswers.length ? 'a FAQPage question has no answer text' : `FAQPage questions do not match the visible ones (missing ${missing.length}, extra ${extra.length})` };
}

/** All three FR3-24 judgements for one article page. */
export function judgeArticle(html, { sourceStatuses = {} } = {}) {
  const doc = parseHtml(html);
  const title = norm(doc.querySelector('title')?.textContent);
  const answer = judgeDirectAnswer(doc);
  const sources = judgeSources(doc, sourceStatuses);
  const faq = judgeFaq(doc);
  return { title, answer, sources, faq, ok: Boolean(answer.ok && sources.ok && faq.ok) };
}

/* ------------------------------------------------------------------ FR3-25: redirects, indexability, robots ---- */

/** robots.txt rules for a user agent ('*' plus the named group). Returns { allow: [], disallow: [], sitemaps: [] }. */
export function parseRobots(text, agent = '*') {
  const groups = [];
  let cur = null;
  const sitemaps = [];
  for (const raw of String(text ?? '').split(/\r?\n/)) {
    const line = raw.replace(/#.*$/, '').trim();
    if (!line) continue;
    const m = line.match(/^([A-Za-z-]+)\s*:\s*(.*)$/);
    if (!m) continue;
    const key = m[1].toLowerCase();
    const val = m[2].trim();
    if (key === 'user-agent') {
      if (!cur || cur.rules.length) { cur = { agents: [], rules: [] }; groups.push(cur); }
      cur.agents.push(val.toLowerCase());
    } else if (key === 'sitemap') sitemaps.push(val);
    else if ((key === 'allow' || key === 'disallow') && cur) cur.rules.push({ type: key, path: val });
  }
  const a = agent.toLowerCase();
  const named = groups.filter((g) => g.agents.some((x) => x !== '*' && a.includes(x)));
  const chosen = named.length ? named : groups.filter((g) => g.agents.includes('*'));
  const rules = chosen.flatMap((g) => g.rules);
  return { allow: rules.filter((r) => r.type === 'allow').map((r) => r.path), disallow: rules.filter((r) => r.type === 'disallow').map((r) => r.path), sitemaps };
}

function robotsPattern(p) {
  const esc = p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
  return new RegExp('^' + (esc.endsWith('\\$') ? esc.slice(0, -2) + '$' : esc));
}

/** Google-style robots matching: the longest matching rule wins, Allow wins a tie. */
export function robotsAllows(rules, url) {
  const path = (() => { try { const u = new URL(url); return u.pathname + u.search; } catch { return String(url); } })();
  let best = null;
  for (const type of ['allow', 'disallow']) {
    for (const p of rules[type] || []) {
      if (p === '') continue;
      if (robotsPattern(p).test(path)) {
        const len = p.length;
        if (!best || len > best.len || (len === best.len && type === 'allow')) best = { type, len };
      }
    }
  }
  return !best || best.type === 'allow';
}

/** True when the rules shut the whole site ("Disallow: /" with no allow), as staging does on purpose. */
export const robotsClosesSite = (rules) => (rules.disallow || []).some((p) => p === '/') && !(rules.allow || []).length;

/**
 * The robots meta tag(s) and X-Robots-Tag header as lower-cased directives. The header's agent-prefixed form
 * ("googlebot: noindex") is recognised, as is "unavailable_after: <date>", which becomes "noindex" once the date has
 * passed (an unparseable date counts as passed). `now` is injectable for the tests.
 */
export function robotsDirectives(doc, headers = {}, now = Date.now()) {
  const out = [];
  const add = (value) => {
    for (let d of String(value || '').toLowerCase().split(',').map((s) => s.trim()).filter(Boolean)) {
      // "googlebot: noindex" or "bingbot: nofollow": drop the agent prefix (but not the value of unavailable_after).
      const agent = d.match(/^([a-z][a-z0-9_-]*)\s*:\s*(.+)$/);
      if (agent && agent[1] !== 'unavailable_after') d = agent[2].trim();
      const ua = d.match(/^unavailable_after\s*:\s*(.+)$/);
      if (ua) { const t = Date.parse(ua[1]); out.push(Number.isNaN(t) || t <= now ? 'noindex' : 'unavailable_after'); continue; }
      out.push(d);
    }
  };
  for (const m of doc.querySelectorAll('meta[name="robots"], meta[name="googlebot"], meta[name="bingbot"]')) add(m.getAttribute('content'));
  const h = headers['x-robots-tag'] ?? headers['X-Robots-Tag'];
  if (h) for (const part of String(h).split(/\r?\n/)) add(part);
  return out;
}

/** Whether a page's directives keep it out of the index. */
export const isNoindex = (directives) => directives.includes('noindex') || directives.includes('none');

/** TC-FR3-25-AS-02 for one page: 200, not noindex (meta or header), canonical (if any) points at itself, robots allows. */
export function judgeIndexable({ url, status, html = '', headers = {}, robots = null, robotsClosed = false }) {
  const problems = [];
  if (status !== 200) problems.push(`answers ${status}`);
  const doc = html ? parseHtml(html) : null;
  const directives = doc ? robotsDirectives(doc, headers) : robotsDirectives(parseHtml(''), headers);
  if (isNoindex(directives)) problems.push('noindex');
  if (doc) {
    const canon = doc.querySelector('link[rel="canonical"]')?.getAttribute('href');
    if (canon && stripSlash(canon) !== stripSlash(url)) problems.push(`canonical points elsewhere: ${canon}`);
  }
  let robotsOk = true;
  if (robots && !robotsClosed) robotsOk = robotsAllows(robots, url);
  if (!robotsOk) problems.push('disallowed by robots.txt');
  return { url, status, ok: problems.length === 0, problems, robots_site_closed: robotsClosed };
}

const stripSlash = (u) => String(u).replace(/\/+$/, '').replace(/^http:/, 'https:');

/**
 * TC-FR3-25-AS-01 for one old address. `chain` is the list of hops the tool followed: [{url, status, location,
 * html?, headers?}, …], the last hop being the page that was not a redirect. A pass is: the first hop is 301 or 308,
 * every redirect hop is 301 or 308, the chain is at most `maxHops` long, and the final page answers 200 and is not
 * noindex.
 */
export function judgeRedirect(chain, { maxHops = 3 } = {}) {
  if (!chain || !chain.length) return { ok: false, problems: ['not fetched'] };
  const problems = [];
  const first = chain[0];
  const redirects = chain.filter((h) => h.status >= 300 && h.status < 400);
  const last = chain[chain.length - 1];
  if (!(first.status === 301 || first.status === 308)) problems.push(first.status >= 300 && first.status < 400 ? `redirects with ${first.status}, not 301/308` : `answers ${first.status}, not a redirect`);
  for (const h of redirects.slice(1)) if (!(h.status === 301 || h.status === 308)) problems.push(`hop ${h.url} redirects with ${h.status}`);
  if (redirects.length > maxHops) problems.push(`${redirects.length} redirect hops (more than ${maxHops})`);
  if (last.status >= 300 && last.status < 400) problems.push('redirect chain did not end');
  else if (last.status !== 200) problems.push(`final page answers ${last.status}`);
  else {
    const directives = last.html !== undefined ? robotsDirectives(parseHtml(last.html), last.headers || {}) : robotsDirectives(parseHtml(''), last.headers || {});
    if (isNoindex(directives)) problems.push('final page is noindex');
  }
  return { ok: problems.length === 0, problems, hops: redirects.length, final: last.url, final_status: last.status };
}

/**
 * TC-FR3-25-AS-02 for the whole sitemap: the per-URL judgements plus the sitemaps that could not be read. A sitemap
 * that could not be read means part of the site was never checked, so it counts as failing.
 */
export function sitemapSection({ sitemaps = [], rows = [], problems = [], partial = false }) {
  const failingRows = rows.filter((r) => !r.ok).length;
  return { sitemaps, sitemap_problems: problems, checked: rows.length, failing_urls: failingRows, unreadable_sitemaps: problems.length,
    failing: failingRows + problems.length, partial, rows: rows.map((r) => ({ url: r.url, status: r.status, ok: r.ok, problems: r.problems })) };
}

/** One address per line; blank lines and # comments skipped; a bare path is joined to base. */
export function parseAddressList(text, base = '') {
  const out = [];
  for (const raw of String(text ?? '').split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const first = line.split(/[\s,]+/)[0];
    out.push(/^https?:\/\//i.test(first) ? first : joinUrl(base, first));
  }
  return [...new Set(out)];
}

export function joinUrl(base, path) {
  const b = String(base || '').replace(/\/+$/, '');
  const p = String(path || '');
  return b + (p.startsWith('/') ? p : '/' + p);
}

/**
 * TC-FR3-25-AS-03: the "crawled but not indexed" list with a recorded action per article. Accepts a CSV with a header
 * row (columns: slug or url, action, note or target) — the lead produces it from the Search Console report. Actions are
 * improved, merged, removed or kept (kept needs a note saying why). Returns rows and the problems found.
 */
export const NOT_INDEXED_ACTIONS = ['improved', 'merged', 'removed', 'kept'];

export function parseNotIndexedCsv(text) {
  const lines = String(text ?? '').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('#'));
  if (!lines.length) return { rows: [], problems: ['the list is empty'] };
  const header = splitCsv(lines[0]).map((h) => h.toLowerCase());
  const col = (names) => header.findIndex((h) => names.includes(h));
  const iSlug = col(['slug', 'url', 'address', 'page']);
  const iAction = col(['action', 'fate', 'status']);
  const iNote = col(['note', 'notes', 'target', 'reason', 'detail']);
  if (iSlug < 0 || iAction < 0) return { rows: [], problems: ['header must name a slug (or url) column and an action column'] };
  const rows = [];
  const problems = [];
  lines.slice(1).forEach((line, n) => {
    const cells = splitCsv(line);
    const row = { line: n + 2, slug: cells[iSlug] || '', action: (cells[iAction] || '').toLowerCase(), note: iNote >= 0 ? cells[iNote] || '' : '' };
    if (!row.slug) problems.push(`line ${row.line}: no slug`);
    if (!row.action) problems.push(`line ${row.line}: ${row.slug || '?'} has no recorded action`);
    else if (!NOT_INDEXED_ACTIONS.includes(row.action)) problems.push(`line ${row.line}: ${row.slug} has an unknown action "${row.action}" (use ${NOT_INDEXED_ACTIONS.join(', ')})`);
    else if ((row.action === 'kept' || row.action === 'merged') && !row.note) problems.push(`line ${row.line}: ${row.slug} is ${row.action} but has no note (${row.action === 'merged' ? 'the target' : 'the reason'})`);
    rows.push(row);
  });
  const dupes = rows.map((r) => r.slug).filter((s, i, a) => s && a.indexOf(s) !== i);
  for (const d of new Set(dupes)) problems.push(`${d} is listed more than once`);
  return { rows, problems };
}

function splitCsv(line) {
  const out = [];
  let cur = '';
  let q = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (q) {
      if (c === '"' && line[i + 1] === '"') { cur += '"'; i++; }
      else if (c === '"') q = false;
      else cur += c;
    } else if (c === '"') q = true;
    else if (c === ',') { out.push(cur.trim()); cur = ''; }
    else cur += c;
  }
  out.push(cur.trim());
  return out;
}

/* ------------------------------------------------------------------ NFR3-11: speed budgets ----------------------- */

/** TC-NFR3-11-AS-01 budgets: LCP 2.5 s, INP 200 ms (TBT stands in as the lab proxy when no interaction is measured), CLS 0.1. */
export const SPEED_BUDGETS = { lcp_ms: 2500, inp_ms: 200, tbt_ms: 200, cls: 0.1 };

/**
 * Judge one page's lab medians against the budgets. `m` may carry lcp_ms, cls, inp_ms and/or tbt_ms. INP is used when
 * measured; otherwise TBT stands in and the result says so (`responsiveness_metric`). A metric that is null fails, and
 * so does a page any of whose runs errored (`erroredRuns` > 0): a median over fewer runs than asked for is not a pass.
 */
export function judgeVitals(m, budgets = SPEED_BUDGETS, { erroredRuns = 0 } = {}) {
  const num = (x) => (typeof x === 'number' && Number.isFinite(x) ? x : null);
  const lcp = num(m?.lcp_ms);
  const cls = num(m?.cls);
  const inp = num(m?.inp_ms);
  const tbt = num(m?.tbt_ms);
  const useInp = inp !== null;
  const resp = useInp ? inp : tbt;
  const respBudget = useInp ? budgets.inp_ms : budgets.tbt_ms;
  const pass = {
    lcp: lcp !== null && lcp < budgets.lcp_ms,
    responsiveness: resp !== null && resp < respBudget,
    cls: cls !== null && cls < budgets.cls,
    runs: !(erroredRuns > 0),
  };
  return {
    pass, ok: pass.lcp && pass.responsiveness && pass.cls && pass.runs,
    responsiveness_metric: useInp ? 'INP' : 'TBT (lab proxy for INP: no interaction measured)',
    values: { lcp_ms: lcp, cls, inp_ms: inp, tbt_ms: tbt },
    errored_runs: erroredRuns,
  };
}

/* ------------------------------------------------------------------ Markdown ----------------------------------- */

export function mdTable(headers, rows) {
  const esc = (v) => String(v ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');
  return [`| ${headers.join(' | ')} |`, `|${headers.map(() => '---').join('|')}|`, ...rows.map((r) => `| ${r.map(esc).join(' | ')} |`)].join('\n');
}
