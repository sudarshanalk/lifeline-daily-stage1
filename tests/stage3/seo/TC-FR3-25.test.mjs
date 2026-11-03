// FR3-25 (P3-81): the judging logic of tools/seo/search-console.mjs over fixture hops, HTML, robots.txt and the
// crawled-but-not-indexed CSV, no network. The staging run is documented in tools/seo/README.md.
import { expect, test } from 'vitest';
import { judgeRedirect, judgeIndexable, parseRobots, robotsAllows, robotsClosesSite, parseAddressList, parseNotIndexedCsv } from '../../../tools/seo/lib.mjs';

const live = (url, extra = '') => ({ url, status: 200, headers: {}, html: `<html><head><link rel="canonical" href="${url}">${extra}</head><body>ok</body></html>` });

test('TC-FR3-25-AS-01', () => {
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
