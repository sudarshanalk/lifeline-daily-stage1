// FR3-24 (P3-80): the judging logic of tools/seo/direct-answers.mjs over fixture HTML, no network. The staging run is
// documented in tools/seo/README.md.
import { expect, test } from 'vitest';
import { judgeArticle, judgeDirectAnswer, judgeSources, judgeFaq, parseHtml, parseSitemap, isPostSitemap } from '../../../tools/seo/lib.mjs';

const answer = '<div class="lld-answer" data-test="direct-answer" role="note"><p>Five food groups help keep blood sugar steady.</p></div>';
const sources = (links = ['https://example.org/a', 'https://example.org/b']) =>
  `<section class="lld-sources" data-test="sources"><h2>Sources</h2><ol>${links.map((l) => `<li><a href="${l}" target="_blank" rel="noopener">Source</a>. Publisher, checked <time datetime="2026-10-26">2026-10-26</time>.</li>`).join('')}</ol></section>`;
const faq = (qs) => `<section class="lld-faq" data-test="faq"><h2>Frequently asked questions</h2>${qs.map(([q, a]) => `<div class="lld-faq__item"><h3 class="lld-faq__q">${q}</h3><div class="lld-faq__a">${a}</div></div>`).join('')}</section>`;
const jsonld = (qs) => `<script type="application/ld+json">${JSON.stringify({ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: qs.map(([q, a]) => ({ '@type': 'Question', name: q, acceptedAnswer: { '@type': 'Answer', text: a } })) })}</script>`;
const page = ({ body = '', head = '', title = 'Article' } = {}) =>
  `<!doctype html><html><head><title>${title}</title>${head}</head><body><header><h2>Site menu</h2></header><div class="tdb_single_content"><div class="tdb-block-inner td-fix-index">${body}</div></div><footer></footer></body></html>`;
const QS = [['Which foods lower blood sugar?', 'No single food does.'], ['Do I need to cut out carbs?', 'No.']];

test('TC-FR3-24-AS-01', () => {
  // The direct-answer block in the first section (before the first h2 of the article body) passes.
  const ok = judgeDirectAnswer(parseHtml(page({ body: `${answer}<p>Intro.</p><h2>Why it matters</h2><p>…</p>` })));
  expect(ok.ok).toBe(true);
  expect(ok.first_section).toBe(true);
  expect(ok.words).toBeGreaterThan(0);
  // An h2 in the site header does not count: only the article body's headings do.
  expect(judgeDirectAnswer(parseHtml(page({ body: `<p>Lead.</p>${answer}<h2>Section</h2>` }))).ok).toBe(true);
  // No block at all fails with the markup named.
  const none = judgeDirectAnswer(parseHtml(page({ body: '<p>Intro.</p><h2>Section</h2><p>…</p>' })));
  expect(none.ok).toBe(false);
  expect(none.present).toBe(false);
  expect(none.reason).toMatch(/direct-answer/);
  // A block after the first h2 is not in the first section.
  const late = judgeDirectAnswer(parseHtml(page({ body: `<p>Intro.</p><h2>Section</h2>${answer}` })));
  expect(late.ok).toBe(false);
  expect(late.present).toBe(true);
  expect(late.reason).toMatch(/first section/);
  // An empty block fails.
  expect(judgeDirectAnswer(parseHtml(page({ body: '<div class="lld-answer" data-test="direct-answer"><p> </p></div><h2>S</h2>' }))).ok).toBe(false);
  // The classic Newspaper template (.td-post-content) is found too.
  const classic = `<html><body><h2>Menu</h2><div class="td-post-content">${answer}<h2>S</h2></div></body></html>`;
  expect(judgeDirectAnswer(parseHtml(classic)).ok).toBe(true);
  // The whole-article judgement carries the same result.
  expect(judgeArticle(page({ body: `${answer}<h2>S</h2>${sources()}` }), { sourceStatuses: { 'https://example.org/a': 200 } }).answer.ok).toBe(true);
});

test('TC-FR3-24-AS-02', () => {
  const doc = (links) => parseHtml(page({ body: `${answer}<h2>S</h2>${sources(links)}` }));
  // One working link (2xx or 3xx) is enough.
  expect(judgeSources(doc(), { 'https://example.org/a': 404, 'https://example.org/b': 301 }).ok).toBe(true);
  expect(judgeSources(doc(), { 'https://example.org/a': 200, 'https://example.org/b': 'error: timeout' }).working).toEqual(['https://example.org/a']);
  // Every link broken fails.
  const broken = judgeSources(doc(), { 'https://example.org/a': 404, 'https://example.org/b': 500 });
  expect(broken.ok).toBe(false);
  expect(broken.reason).toMatch(/no source link answers/);
  // No Sources section, or a section with no links, fails.
  expect(judgeSources(parseHtml(page({ body: `${answer}<h2>S</h2>` })), {}).reason).toMatch(/no Sources section/);
  expect(judgeSources(parseHtml(page({ body: '<section class="lld-sources" data-test="sources"><h2>Sources</h2><ol><li>A book, 2020.</li></ol></section>' })), {}).reason).toMatch(/no links/);
  // Unchecked links are reported as such, not as working.
  const unchecked = judgeSources(doc(), {});
  expect(unchecked.ok).toBe(false);
  expect(unchecked.unchecked.length).toBe(2);
  // Only absolute http(s) links count; relative and mailto links are ignored.
  expect(judgeSources(parseHtml(page({ body: sources(['/about/', 'mailto:x@example.org', 'https://example.org/c']) })), { 'https://example.org/c': 200 }).links).toEqual(['https://example.org/c']);
});

test('TC-FR3-24-AS-03', () => {
  // A FAQ section whose FAQPage JSON-LD parses and matches the visible questions passes.
  const good = judgeFaq(parseHtml(page({ body: `${answer}<h2>S</h2>${faq(QS)}`, head: jsonld(QS) })));
  expect(good.ok).toBe(true);
  expect(good.applicable).toBe(true);
  expect(good.visible).toEqual(QS.map((q) => q[0]));
  // Yoast's @graph wrapper and whitespace differences are tolerated.
  const graph = `<script type="application/ld+json">${JSON.stringify({ '@context': 'https://schema.org', '@graph': [{ '@type': 'WebPage' }, { '@type': 'FAQPage', mainEntity: QS.map(([q, a]) => ({ '@type': 'Question', name: '  ' + q + '\n', acceptedAnswer: { '@type': 'Answer', text: a } })) }] })}</script>`;
  expect(judgeFaq(parseHtml(page({ body: faq(QS), head: graph }))).ok).toBe(true);
  // A FAQ section with no FAQPage markup fails.
  const missing = judgeFaq(parseHtml(page({ body: faq(QS) })));
  expect(missing.ok).toBe(false);
  expect(missing.reason).toMatch(/no FAQPage/);
  // JSON-LD that does not parse fails and says so.
  const broken = judgeFaq(parseHtml(page({ body: faq(QS), head: '<script type="application/ld+json">{"@type": "FAQPage", "mainEntity": [</script>' })));
  expect(broken.ok).toBe(false);
  expect(broken.reason).toMatch(/does not parse/);
  // Questions that differ from the visible ones fail (missing one, extra one).
  const mismatch = judgeFaq(parseHtml(page({ body: faq(QS), head: jsonld([QS[0], ['Is keto safe?', 'Ask your doctor.']]) })));
  expect(mismatch.ok).toBe(false);
  expect(mismatch.missing).toEqual(['Do I need to cut out carbs?']);
  expect(mismatch.extra).toEqual(['Is keto safe?']);
  // A question without answer text fails.
  expect(judgeFaq(parseHtml(page({ body: faq(QS), head: `<script type="application/ld+json">${JSON.stringify({ '@type': 'FAQPage', mainEntity: QS.map(([q]) => ({ '@type': 'Question', name: q })) })}</script>` }))).reason).toMatch(/no answer text/);
  // An article with no FAQ section needs no markup and passes; FAQPage markup with no visible section is reported.
  expect(judgeFaq(parseHtml(page({ body: answer }))).ok).toBe(true);
  expect(judgeFaq(parseHtml(page({ body: answer }))).applicable).toBe(false);
  const orphan = judgeFaq(parseHtml(page({ body: answer, head: jsonld(QS) })));
  expect(orphan.ok).toBe(false);
  expect(orphan.reason).toMatch(/no visible FAQ section/);
  // The whole-article judgement needs all three checks; sitemap parsing picks the post sitemaps.
  const whole = judgeArticle(page({ body: `${answer}<h2>S</h2>${faq(QS)}${sources()}`, head: jsonld(QS) }), { sourceStatuses: { 'https://example.org/a': 200 } });
  expect(whole.ok).toBe(true);
  const idx = parseSitemap('<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://s.test/post-sitemap.xml</loc></sitemap><sitemap><loc>https://s.test/page-sitemap.xml</loc></sitemap></sitemapindex>');
  expect(idx.kind).toBe('index');
  expect(idx.locs.filter(isPostSitemap)).toEqual(['https://s.test/post-sitemap.xml']);
  expect(parseSitemap('<urlset><url><loc>https://s.test/a/</loc></url><url><loc>https://s.test/b/?x=1&amp;y=2</loc></url></urlset>').locs).toEqual(['https://s.test/a/', 'https://s.test/b/?x=1&y=2']);
});
