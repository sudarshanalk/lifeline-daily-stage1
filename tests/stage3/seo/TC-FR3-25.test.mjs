// FR3-25 (P3-81): the judging logic of tools/seo/search-console.mjs over fixture hops, HTML, robots.txt and the
// crawled-but-not-indexed CSV, no network. The staging run is documented in tools/seo/README.md.
import { expect, test } from 'vitest';
import { judgeRedirect, judgeIndexable, parseRobots, robotsAllows, robotsClosesSite, robotsDirectives, parseAddressList, parseNotIndexedCsv, sitemapSection, parseHtml } from '../../../tools/seo/lib.mjs';
import { getChain, getOnce, basicAuth, authAppliesTo } from '../../../tools/seo/fetch.mjs';

const live = (url, extra = '') => ({ url, status: 200, headers: {}, html: `<html><head><link rel="canonical" href="${url}">${extra}</head><body>ok</body></html>` });

/** A fake fetch: `routes` maps URL -> { status, headers }, recording every request's URL and authorization header. */
function fakeFetch(routes, log = []) {
  return async (url, init) => {
    log.push({ url, authorization: init.headers.authorization || null });
    const r = routes[url] || { status: 404 };
    return { status: r.status, headers: new Map(Object.entries(r.headers || {})), text: async () => r.body || '', arrayBuffer: async () => new ArrayBuffer(0) };
  };
}

test('TC-FR3-25-AS-01', async () => {
  // The staging password (--auth) goes only to the base host: an old address that redirects off-site must not carry
  // the credentials to the other host, nor must a sitemap <loc> on another host.
  const auth = basicAuth('lead:secret', 'https://stg.test');
  expect(auth).toEqual({ value: 'Basic ' + Buffer.from('lead:secret').toString('base64'), host: 'stg.test' });
  expect(authAppliesTo(auth, 'https://stg.test/old/')).toBe(true);
  expect(authAppliesTo(auth, 'https://STG.test/old/')).toBe(true);
  expect(authAppliesTo(auth, 'https://www.stg.test/old/')).toBe(false);
  expect(authAppliesTo(auth, 'https://other.example/')).toBe(false);
  expect(authAppliesTo(null, 'https://stg.test/')).toBe(false);
  const log = [];
  const fetchImpl = fakeFetch({ 'https://stg.test/old/': { status: 301, headers: { location: 'https://other.example/new/' } }, 'https://other.example/new/': { status: 200, headers: { 'content-type': 'text/html' } } }, log);
  const offsite = await getChain('https://stg.test/old/', { auth, fetchImpl });
  expect(offsite.map((h) => h.status)).toEqual([301, 200]);
  expect(log).toEqual([{ url: 'https://stg.test/old/', authorization: auth.value }, { url: 'https://other.example/new/', authorization: null }]);
  const log2 = [];
  await getOnce('https://cdn.example/post-sitemap.xml', { auth, fetchImpl: fakeFetch({}, log2) });
  expect(log2[0].authorization).toBeNull();
  // Without --auth nothing is sent anywhere.
  const log3 = [];
  await getOnce('https://stg.test/', { fetchImpl: fakeFetch({}, log3) });
  expect(log3[0].authorization).toBeNull();
  // A looping old address ends the chain with an error hop, which the judge fails rather than mistaking for a page.
  const loop = fakeFetch({ 'https://stg.test/a/': { status: 301, headers: { location: '/b/' } }, 'https://stg.test/b/': { status: 301, headers: { location: '/a/' } } });
  const looped = judgeRedirect(await getChain('https://stg.test/a/', { fetchImpl: loop }));
  expect(looped.ok).toBe(false);
  expect(looped.problems).toContain('final page answers 0');

  // 301 straight to a live, indexable page passes; 308 too.
  expect(judgeRedirect([{ url: 'https://s.test/old/', status: 301, location: '/new/' }, live('https://s.test/new/')]).ok).toBe(true);
  expect(judgeRedirect([{ url: 'https://s.test/old/', status: 308, location: '/new/' }, live('https://s.test/new/')]).ok).toBe(true);
  // A temporary redirect is not permanent.
  const temp = judgeRedirect([{ url: 'https://s.test/old/', status: 302, location: '/new/' }, live('https://s.test/new/')]);
  expect(temp.ok).toBe(false);
  expect(temp.problems[0]).toMatch(/302, not 301\/308/);
  // No redirect at all (404, 410, 200) fails.
  expect(judgeRedirect([{ url: 'https://s.test/old/', status: 404 }]).problems[0]).toMatch(/answers 404, not a redirect/);
  expect(judgeRedirect([live('https://s.test/old/')]).ok).toBe(false);
  // The final page must answer 200 and not be noindex.
  expect(judgeRedirect([{ url: 'https://s.test/old/', status: 301, location: '/gone/' }, { url: 'https://s.test/gone/', status: 404, html: '' }]).problems[0]).toMatch(/final page answers 404/);
  expect(judgeRedirect([{ url: 'https://s.test/old/', status: 301, location: '/n/' }, live('https://s.test/n/', '<meta name="robots" content="noindex, follow">')]).problems[0]).toMatch(/noindex/);
  expect(judgeRedirect([{ url: 'https://s.test/old/', status: 301, location: '/n/' }, { url: 'https://s.test/n/', status: 200, headers: { 'x-robots-tag': 'noindex' }, html: '<html></html>' }]).problems[0]).toMatch(/noindex/);
  // A chain of permanent hops passes up to three hops; a chain that never ends fails.
  const chain = [{ url: 'a', status: 301, location: 'b' }, { url: 'b', status: 301, location: 'c' }, live('c')];
  expect(judgeRedirect(chain).ok).toBe(true);
  expect(judgeRedirect(chain).hops).toBe(2);
  expect(judgeRedirect([{ url: 'a', status: 301, location: 'b' }, { url: 'b', status: 301, location: 'a' }]).problems).toContain('redirect chain did not end');
  // The address list: one per line, comments and blanks skipped, paths joined to the base.
  expect(parseAddressList('# old addresses\n/atkins-diet-guide/\n\nhttps://s.test/the-top-7-health-benefits-of-cycling/\n/atkins-diet-guide/\n', 'https://s.test')).toEqual(['https://s.test/atkins-diet-guide/', 'https://s.test/the-top-7-health-benefits-of-cycling/']);
});

test('TC-FR3-25-AS-02', () => {
  const robots = parseRobots('User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /members/\nSitemap: https://s.test/sitemap_index.xml\n', 'googlebot');
  expect(robots.sitemaps).toEqual(['https://s.test/sitemap_index.xml']);
  expect(robotsAllows(robots, 'https://s.test/an-article/')).toBe(true);
  expect(robotsAllows(robots, 'https://s.test/members/today/')).toBe(false);
  expect(robotsAllows(robots, 'https://s.test/wp-admin/admin-ajax.php')).toBe(true); // the longer Allow wins
  // A page that answers 200, is not noindex, canonical to itself and allowed passes.
  const ok = judgeIndexable({ ...live('https://s.test/a/'), robots });
  expect(ok.ok).toBe(true);
  // Each way of being unindexable is named.
  expect(judgeIndexable({ url: 'https://s.test/a/', status: 404, html: '', robots }).problems).toEqual(['answers 404']);
  expect(judgeIndexable({ ...live('https://s.test/a/', '<meta name="robots" content="noindex,follow">'), robots }).problems).toEqual(['noindex']);
  expect(judgeIndexable({ ...live('https://s.test/a/'), headers: { 'x-robots-tag': 'noindex, nofollow' }, robots }).problems).toEqual(['noindex']);
  expect(judgeIndexable({ ...live('https://s.test/members/today/'), robots }).problems).toEqual(['disallowed by robots.txt']);
  const canon = judgeIndexable({ url: 'https://s.test/a/', status: 200, html: '<html><head><link rel="canonical" href="https://s.test/b/"></head></html>', robots });
  expect(canon.problems[0]).toMatch(/canonical points elsewhere/);
  // Staging closes the whole site in robots.txt on purpose: that is reported, and the robots rule is not applied.
  const closed = parseRobots('User-agent: *\nDisallow: /\n');
  expect(robotsClosesSite(closed)).toBe(true);
  expect(robotsClosesSite(robots)).toBe(false);
  const stg = judgeIndexable({ ...live('https://stg.test/a/'), robots: closed, robotsClosed: true });
  expect(stg.ok).toBe(true);
  expect(stg.robots_site_closed).toBe(true);
  expect(judgeIndexable({ ...live('https://stg.test/a/'), robots: closed, robotsClosed: false }).problems).toEqual(['disallowed by robots.txt']);
  // The X-Robots-Tag header's agent-prefixed form and unavailable_after are recognised.
  const doc = parseHtml('<html></html>');
  expect(robotsDirectives(doc, { 'x-robots-tag': 'googlebot: noindex, nofollow' })).toEqual(['noindex', 'nofollow']);
  expect(robotsDirectives(doc, { 'x-robots-tag': 'bingbot: none' })).toEqual(['none']);
  const now = Date.parse('2026-11-03T00:00:00Z');
  expect(robotsDirectives(doc, { 'x-robots-tag': 'unavailable_after: 25 Jun 2020 15:00:00 PST' }, now)).toEqual(['noindex']);
  expect(robotsDirectives(doc, { 'x-robots-tag': 'googlebot: unavailable_after: 2030-01-01' }, now)).toEqual(['unavailable_after']);
  expect(robotsDirectives(doc, { 'x-robots-tag': 'unavailable_after: not a date' }, now)).toEqual(['noindex']);
  expect(robotsDirectives(parseHtml('<html><head><meta name="googlebot" content="unavailable_after: 2020-01-01"></head></html>'), {}, now)).toEqual(['noindex']);
  expect(judgeIndexable({ ...live('https://s.test/a/'), headers: { 'x-robots-tag': 'googlebot: noindex' }, robots }).problems).toEqual(['noindex']);
  expect(judgeIndexable({ ...live('https://s.test/a/'), headers: { 'x-robots-tag': 'unavailable_after: 2020-01-01' }, robots }).problems).toEqual(['noindex']);
  // A sub-sitemap that could not be read means part of the site was never checked: the section fails even when every
  // URL that was checked passes.
  const rows = [judgeIndexable({ ...live('https://s.test/a/'), robots }), judgeIndexable({ ...live('https://s.test/b/'), robots })];
  const clean = sitemapSection({ sitemaps: ['https://s.test/post-sitemap.xml'], rows, problems: [] });
  expect(clean).toMatchObject({ checked: 2, failing_urls: 0, unreadable_sitemaps: 0, failing: 0, partial: false });
  const unreadable = sitemapSection({ sitemaps: ['https://s.test/post-sitemap.xml', 'https://s.test/page-sitemap.xml'], rows, problems: ['https://s.test/page-sitemap.xml answers 500'] });
  expect(unreadable.failing).toBe(1);
  expect(unreadable.unreadable_sitemaps).toBe(1);
  expect(unreadable.failing_urls).toBe(0);
  expect(sitemapSection({ rows: [rows[0], { url: 'https://s.test/c/', status: 404, ok: false, problems: ['answers 404'] }], problems: ['x answers 0'] }).failing).toBe(2);
});

test('TC-FR3-25-AS-03', () => {
  // The lead's CSV of the "crawled but not indexed" articles: every row needs a recorded action.
  const good = parseNotIndexedCsv([
    'slug,action,note',
    'ways-to-reduce-belly-fat,improved,direct answer and sources added',
    'foods-to-reduce-belly-fat,merged,ways-to-reduce-belly-fat',
    'signs-keto-is-working,removed,',
    '"benefits-of-regular-exercise",kept,"hub article, already has links"',
  ].join('\n'));
  expect(good.problems).toEqual([]);
  expect(good.rows.length).toBe(4);
  expect(good.rows.map((r) => r.action)).toEqual(['improved', 'merged', 'removed', 'kept']);
  expect(good.rows[3].note).toBe('hub article, already has links');
  // A row without an action, an unknown action, a merge without a target, a kept article without a reason, and a
  // slug listed twice are all reported with their line.
  const bad = parseNotIndexedCsv([
    'Slug,Action,Note',
    'a-article,,',
    'b-article,rewrite,',
    'c-article,merged,',
    'd-article,kept,',
    'a-article,improved,x',
  ].join('\n'));
  expect(bad.problems).toEqual([
    'line 2: a-article has no recorded action',
    'line 3: b-article has an unknown action "rewrite" (use improved, merged, removed, kept)',
    'line 4: c-article is merged but has no note (the target)',
    'line 5: d-article is kept but has no note (the reason)',
    'a-article is listed more than once',
  ]);
  // A list with no usable header, or nothing in it, is refused rather than passed.
  expect(parseNotIndexedCsv('').problems).toEqual(['the list is empty']);
  expect(parseNotIndexedCsv('page,fate\nx,improved').problems).toEqual([]); // url/page and fate/status are accepted names
  expect(parseNotIndexedCsv('name,thing\nx,y').problems[0]).toMatch(/header must name/);
});
