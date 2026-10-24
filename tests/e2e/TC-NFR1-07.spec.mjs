// TC-NFR1-07 SEO preservation — crawl + http on the local WordPress stack (DEC-69), on the same before/after states as
// TC-FR1-02 (tests/lib/stage1-states.mjs: before = pristine snapshot without Stage 1 code, after = repository overlay with
// pages, home and tidy applied; the whole database is dumped and restored). Fixtures: FX-WP-URLS (url-sample.json).
// AS-01 TC-FR1-02-AS-01 passes: the same rule (crawl-diff preservationProblems with DEC-82's handling of the four rebuilt
//       pages, plus the FR1-23 checks in tidyProblems); deferred with TC-FR1-02-AS-01 until DEC-82 is ratified.
// AS-02 the page sitemap lists the hub, both landing pages and /medical-disclaimer/; no sitemap lists /tools/*.html.
// AS-03 each /tools/*.html has a canonical link to its landing page (full URL) and no noindex (meta or X-Robots-Tag); the
//       landing pages carry no noindex. AS-04 each landing page's JSON-LD parses and validates structurally: every node has a
//       known schema.org @type, every {"@id"} reference resolves inside the graph, and the page node is a MedicalWebPage with
//       the landing page's url and a name (DEC-81). AS-05 no URL of the sample (plus the FR1-23 redirects) answers through a
//       redirect chain > 1 hop.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { available } from '../lib/wp-stack.mjs';
import { fetchChain, sitemapUrls, preservationProblems, tidyProblems } from '../lib/crawl-diff.mjs';
import { buildStates, PROD } from '../lib/stage1-states.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const SAMPLE = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'url-sample.json'), 'utf8')).urls;   // FX-WP-URLS
const BEFORE = JSON.parse(readFileSync(resolve(ROOT, 'reports', 'as-is', 'crawl-before.json'), 'utf8'));
const CARDS = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const TIDY = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures.find((f) => f.id === 'FX-WP-TIDY').inputs;
const TOOL_FILE = { protein: 'protein-requirement-tool.html', diabetes: 'diabetes-risk-tool.html' };
const DELETED = { 'womens-health': ['/category/wellness/womens-health/', '/category/wellness/'], 'healthy-living': ['/category/wellness/healthy-living/', '/category/wellness/'], stress: ['/category/wellness/stress/', '/category/wellness/'], uncategorised: ['/category/uncategorised/', '/'] };
const HIDDEN = Object.fromEntries(TIDY.hide_noindex.map((s) => [s, `/category/${s}/`]));
// schema.org types Yoast SEO emits on these pages, plus the two NFR1-07 names (https://schema.org/<Type> all exist)
const KNOWN_TYPES = new Set(['WebPage', 'MedicalWebPage', 'WebApplication', 'WebSite', 'Organization', 'Person', 'ImageObject', 'BreadcrumbList', 'ListItem', 'SearchAction', 'EntryPoint', 'PropertyValueSpecification', 'ReadAction', 'CollectionPage', 'Article']);
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let S;
test.beforeAll(async () => { test.setTimeout(3600000); S = await buildStates(SAMPLE); });
test.afterAll(async () => { await S?.restore(); });
const noindex = (r) => /noindex/i.test(r.headers['x-robots-tag'] || '') || /<meta[^>]+name=["']robots["'][^>]*content=["'][^"']*noindex/i.test(r.html);

test('TC-NFR1-07-AS-01', async () => {
  test.setTimeout(600000);
  expect(S.after.rows.length, 'the 30-URL sample').toBe(30);
  const problems = [...preservationProblems({ before: S.before.rows, after: S.after.rows, baselineRows: BEFORE.rows, origin: PROD }), ...await tidyProblems({ ...S.maps, origin: PROD, deleted: DELETED, hidden: HIDDEN })];
  expect(problems, 'TC-FR1-02-AS-01 rule holds').toEqual([]);
});

test('TC-NFR1-07-AS-02', async () => {
  const pages = await sitemapUrls(`${PROD}/page-sitemap.xml`, S.maps);
  const all = await sitemapUrls(`${PROD}/sitemap_index.xml`, S.maps);
  for (const p of ['/health-tools/', ...CARDS.map((c) => c.landing_url), '/medical-disclaimer/']) expect(pages, `page sitemap lists ${p}`).toContain(PROD + p);
  expect(all.filter((u) => /\/tools\/[^/]+\.html$/.test(u)), 'no sitemap lists /tools/*.html').toEqual([]);
});

test('TC-NFR1-07-AS-03', async () => {
  for (const c of CARDS) {
    const tool = await fetchChain(`${S.stack.url}/tools/${TOOL_FILE[c.tool_slug]}`);
    expect(tool.status, `${c.tool_slug}: tool page 200`).toBe(200);
    const canon = tool.html.match(/<link[^>]+rel=["']canonical["'][^>]*href=["']([^"']+)/i)?.[1];
    expect(canon && S.maps.toCanonical(canon), `${c.tool_slug}: canonical → landing page`).toBe(PROD + c.landing_url);
    expect(noindex(tool), `${c.tool_slug}: tool page not noindex`).toBe(false);
    const landing = await fetchChain(`${S.stack.url}${c.landing_url}`);
    expect(landing.status).toBe(200);
    expect(noindex(landing), `${c.tool_slug}: landing page not noindex`).toBe(false);
  }
});

test('TC-NFR1-07-AS-04', async () => {
  for (const c of CARDS) {
    const r = await fetchChain(`${S.stack.url}${c.landing_url}`);
    const blocks = [...r.html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)].map((m) => JSON.parse(m[1]));   // must parse
    expect(blocks.length, `${c.tool_slug}: JSON-LD present`).toBeGreaterThan(0);
    const nodes = []; const walk = (v) => { if (Array.isArray(v)) v.forEach(walk); else if (v && typeof v === 'object') { if (v['@type']) nodes.push(v); Object.values(v).forEach(walk); } };
    blocks.forEach(walk);
    const ids = new Set(nodes.map((n) => n['@id']).filter(Boolean));
    const refs = []; const collect = (v) => { if (Array.isArray(v)) v.forEach(collect); else if (v && typeof v === 'object') { if (Object.keys(v).length === 1 && v['@id']) refs.push(v['@id']); else Object.values(v).forEach(collect); } };
    blocks.forEach(collect);
    const problems = [];
    for (const n of nodes) for (const t of [].concat(n['@type'])) if (!KNOWN_TYPES.has(t)) problems.push(`unknown @type ${t}`);
    for (const id of refs) if (!ids.has(id)) problems.push(`unresolved @id ${id}`);
    const page = nodes.find((n) => [].concat(n['@type']).includes('MedicalWebPage') || [].concat(n['@type']).includes('WebApplication'));
    if (!page) problems.push(`no MedicalWebPage/WebApplication node (found ${[...new Set(nodes.flatMap((n) => [].concat(n['@type'])))].join(', ')})`);
    else {
      if (S.maps.toCanonical(String(page.url || '')) !== PROD + c.landing_url) problems.push(`page node url ${page.url}`);
      if (!String(page.name || '').trim()) problems.push('page node has no name');
    }
    expect(problems, `${c.tool_slug}: JSON-LD validates`).toEqual([]);
  }
});

test('TC-NFR1-07-AS-05', async () => {
  const long = S.after.rows.filter((r) => r.hops > 1).map((r) => r.chain.join(' → '));
  for (const [path] of Object.values(DELETED)) { const r = await fetchChain(S.maps.toLocal(PROD + path)); if (r.chain.length - 1 > 1) long.push(r.chain.map((c) => `${c.status} ${c.url}`).join(' → ')); }
  expect(long, 'no redirect chain > 1 hop').toEqual([]);
});
