// TC-FR2-26 category hub block (S2-L4, SCR-44, handover section 5.2): the pure builders of lld-tools/category-hub.php run
// in PHP CLI without WordPress (the file returns before its WordPress wiring when add_action is undefined), fed with plain
// arrays. AS-05 is the test criterion that exists for the hub (each category's main guide links its articles and its
// track); AS-07 to AS-09 continue that file's numbering for the hero, the data defaults and the template placement.
import { execFileSync } from 'node:child_process';
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { ROOT } from '../lib/paths.mjs';

const TOOLS = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools');
const HUB_PHP = resolve(TOOLS, 'category-hub.php');
const categories = JSON.parse(readFileSync(resolve(TOOLS, 'data', 'categories.json'), 'utf8'));
const toolCards = JSON.parse(readFileSync(resolve(TOOLS, 'data', 'tool-cards.json'), 'utf8'));
const theme = readFileSync(resolve(TOOLS, 'assets', 'lld-theme.css'), 'utf8');

function call(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(HUB_PHP.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}
const dom = (html) => new JSDOM(`<!doctype html><body>${html}</body>`).window.document;

const post = (id, title, extra = {}) => ({ id, url: `https://example.test/${id}/`, title, excerpt: `Summary ${id}`, image: `<img class="lld-hub__img" src="https://example.test/${id}.jpg" alt="">`, reviewed: true, updated: 'October 2026', minutes: 5, ...extra });
const model = {
  category: { slug: 'gut-health', name: 'Gut Health' },
  flagship: post(10, 'How to Improve Your Gut Health: 8 Steps', { minutes: 8 }),
  trending: [post(11, 'Prebiotics and Probiotics'), post(12, 'Fermented Foods for Gut Health', { reviewed: false, image: '' })],
  tool: { slug: 'protein', name: 'Daily Protein Target', purpose: 'Find out how much protein you need each day.', cta_label: 'Find my target', url: 'https://example.test/health-tools/protein/', minutes: 2 },
  track: { slug: 'healthy-weight', title: 'Healthy Weight', url: 'https://example.test/members/join/' },
};

describe('TC-FR2-26 category hub block', () => {
  test('TC-FR2-26-AS-05', () => {
    // the category's main guide (flagship) links to its article, and the block links the category's track
    const d = dom(call('lld_hub_markup', model));
    const hub = d.querySelector('[data-test="category-hub"]');
    expect(hub.getAttribute('data-category')).toBe('gut-health');
    const flag = hub.querySelector('[data-test="hub-flagship"]');
    expect(flag.getAttribute('data-post-id')).toBe('10');
    expect(flag.querySelector('h2 a').getAttribute('href')).toBe('https://example.test/10/');
    expect(flag.querySelector('h2 a').textContent).toBe('How to Improve Your Gut Health: 8 Steps');
    expect(flag.querySelector('[data-test="hub-flagship-link"]').textContent).toMatch(/^Read full guide/);
    expect(flag.querySelector('[data-test="hub-flagship-link"]').getAttribute('href')).toBe('https://example.test/10/');
    const track = hub.querySelector('[data-test="hub-track"]');
    expect(track.getAttribute('data-track')).toBe('healthy-weight');
    expect(track.querySelector('h3').textContent).toBe('Ready to put these gut health guides into practice?');
    expect(track.textContent).toContain('Healthy Weight');
    const link = track.querySelector('[data-test="hub-track-link"]');
    expect(link.getAttribute('href')).toBe('https://example.test/members/join/');
    expect(link.textContent).toMatch(/^Start 7-day trial/);
    // the banner's price sits in the banner, never inside a calculator: the hub block carries no calculator result
    expect(hub.querySelector('.lld-tool-frame, [data-test="result"]')).toBeNull();
    // every category of the agreed tree names a tool card and a track that exist
    const slugs = new Set(toolCards.cards.map((c) => c.tool_slug));
    for (const t of categories.tree) {
      expect(t.hub, t.slug).toBeTruthy();
      expect(slugs.has(t.hub.tool), `${t.slug}: tool ${t.hub.tool}`).toBe(true);
      expect(Object.keys(categories.tracks), `${t.slug}: track ${t.hub.track}`).toContain(t.hub.track);
    }
    expect(Object.values(categories.tracks).sort()).toEqual(['Blood Sugar Balance', 'Fitness and Strength', 'Healthy Weight', 'Sleep and Stress']);
  });

  test('TC-FR2-26-AS-07', () => {
    // the magazine hero: flagship with its square image, "Medically reviewed" chip, title, excerpt and link; two trending
    // articles with a thumbnail after the text; the topic tool callout; every value escaped
    const html = call('lld_hub_markup', model);
    const d = dom(html);
    const flag = d.querySelector('[data-test="hub-flagship"]');
    expect(flag.querySelector('.lld-hub__flagship-media img.lld-hub__img').getAttribute('src')).toBe('https://example.test/10.jpg');
    expect(flag.querySelector('.lld-hub__badge').textContent).toBe('Flagship guide');
    expect(flag.querySelector('.lld-hub__time').textContent).toBe('8 min read');
    expect(flag.querySelector('[data-test="hub-reviewed"]').textContent).toBe('Medically reviewed');
    expect(flag.querySelector('.lld-hub__updated').textContent).toBe('Updated October 2026');
    expect(flag.querySelector('.lld-hub__excerpt').textContent).toBe('Summary 10');
    const trend = [...d.querySelectorAll('[data-test="hub-trending"]')];
    expect(trend.map((t) => t.getAttribute('data-post-id'))).toEqual(['11', '12']);
    expect(trend[0].querySelector('h3 a').textContent).toBe('Prebiotics and Probiotics');
    expect(trend[0].querySelector('.lld-hub__topic').textContent).toBe('Gut Health');
    const row = trend[0].querySelector('.lld-hub__trend-row');
    expect([...row.children].map((c) => c.className)).toEqual(['lld-hub__trend-text', 'lld-hub__thumb']);   // thumbnail on the right of the text
    expect(row.querySelector('.lld-hub__thumb img').getAttribute('src')).toBe('https://example.test/11.jpg');
    expect(trend[0].textContent).toContain('Medically reviewed');
    expect(trend[1].querySelector('.lld-hub__thumb')).toBeNull();                        // no featured image: no empty frame
    expect(trend[1].textContent).not.toContain('Medically reviewed');                     // the chip is never shown without a review byline
    const tool = d.querySelector('[data-test="hub-tool"]');
    expect(tool.getAttribute('data-tool')).toBe('protein');
    expect(tool.querySelector('h3').textContent).toBe('Daily Protein Target');
    expect(tool.querySelector('.lld-hub__cta').getAttribute('href')).toBe('https://example.test/health-tools/protein/');
    expect(tool.querySelector('.lld-hub__cta').textContent).toMatch(/^Find my target/);
    expect(tool.textContent).toContain('About 2 minutes');
    // heading order: the hero title is the page's h2, cards h3
    expect([...d.querySelectorAll('h1, h2, h3')].map((h) => h.tagName)).toEqual(['H2', 'H3', 'H3', 'H3', 'H3']);
    // no flagship (empty category): no hero at all; no tool, no track: nothing but the section
    const bare = dom(call('lld_hub_markup', { category: model.category, flagship: null, trending: [], tool: null, track: null }));
    expect(bare.querySelector('.lld-hub__hero, [data-test="hub-tool"], [data-test="hub-track"]')).toBeNull();
    // escaping: a title with markup and an unsafe link render as text and as no link
    const hostile = { ...model, flagship: post(10, '<img src=x onerror=alert(1)> "quoted" & co', { url: 'javascript:alert(1)', excerpt: '<b>bold</b>' }) };
    const out = call('lld_hub_markup', hostile);
    expect(out).not.toContain('<img src=x');
    expect(out).toContain('&lt;img src=x onerror=alert(1)&gt; &quot;quoted&quot; &amp; co');
    expect(out).toContain('&lt;b&gt;bold&lt;/b&gt;');
    expect(out).not.toContain('javascript:');
    // the styles: 7 and 5 of 12 columns, a 320 px square flagship image cropped to fit, 96 px square thumbnails, one column under 768 px
    expect(theme).toMatch(/\.lld-hub__flagship \{[^}]*grid-column: span 7/);
    expect(theme).toMatch(/\.lld-hub__trending \{[^}]*grid-column: span 5/);
    expect(theme).toMatch(/\.lld-hub__flagship-media \{[^}]*flex: 0 0 320px[^}]*aspect-ratio: 1 \/ 1/);
    expect(theme).toMatch(/\.lld-hub__flagship-media \.lld-hub__img[^{]*\{[^}]*object-fit: cover/);
    expect(theme).toMatch(/\.lld-hub__thumb \{[^}]*width: 96px; height: 96px/);
    expect(theme).toMatch(/@media \(max-width: 767px\) \{[^@]*\.lld-hub__hero \{ grid-template-columns: 1fr/);
    expect(theme).toMatch(/\.lld-hub__track \{[^}]*var\(--lld-t-track\)/);
  });

  test('TC-FR2-26-AS-08', () => {
    // data defaults (owner question 36): the named flagship wins; else the newest medically reviewed article; else the newest.
    // Trending: the named ids, else the next two newest, never the flagship twice.
    const posts = [
      { id: 1, slug: 'oldest-reviewed', date: '2024-01-01 00:00:00', reviewed: true },
      { id: 2, slug: 'newer-reviewed', date: '2025-06-01 00:00:00', reviewed: true },
      { id: 3, slug: 'newest-unreviewed', date: '2026-10-28 00:00:00', reviewed: false },
      { id: 4, slug: 'second-newest', date: '2026-10-01 00:00:00', reviewed: false },
    ];
    expect(call('lld_hub_pick', posts, { flagship: null, trending: [] })).toEqual({ flagship: 2, trending: [3, 4] });
    expect(call('lld_hub_pick', posts, { flagship: 'oldest-reviewed', trending: [] })).toEqual({ flagship: 1, trending: [3, 4] });
    expect(call('lld_hub_pick', posts, { flagship: 4, trending: [2, 4, 1] })).toEqual({ flagship: 4, trending: [2, 1] });
    expect(call('lld_hub_pick', posts, { flagship: 999, trending: [999] })).toEqual({ flagship: 2, trending: [3, 4] });   // unknown ids fall back
    expect(call('lld_hub_pick', posts.map((p) => ({ ...p, reviewed: false })), { flagship: null, trending: [] })).toEqual({ flagship: 3, trending: [4, 2] });
    expect(call('lld_hub_pick', [posts[0]], {})).toEqual({ flagship: 1, trending: [] });
    expect(call('lld_hub_pick', [], {})).toEqual({ flagship: null, trending: [] });
    // the hub entry: a top category's own; a child resolves to its parent's tool and track but chooses its own flagship
    const top = call('lld_hub_entry', categories, 'gut-health');
    expect(top).toMatchObject({ category: 'gut-health', name: 'Gut Health', tool: 'protein', track: 'healthy-weight', flagship: null, trending: [] });
    const child = call('lld_hub_entry', categories, 'healthy-eating');
    expect(child).toMatchObject({ category: 'weight-healthy-eating', name: 'Healthy Eating', top_name: 'Weight & Healthy Eating', tool: 'protein', track: 'healthy-weight', flagship: null });
    expect(call('lld_hub_entry', categories, 'featured')).toBeNull();   // hidden display categories get no hub
    expect(call('lld_hub_minutes', 0)).toBe(1); expect(call('lld_hub_minutes', 201)).toBe(2); expect(call('lld_hub_minutes', 1600)).toBe(8);
  });

  test('TC-FR2-26-AS-09', () => {
    // template placement: the block's Composer row goes directly above the row holding the article loop; applying twice
    // changes nothing more; revert removes it exactly; a template without a loop is refused ('')
    const tpl = '[tdc_zone type="tdc_content"][vc_row gap="10"][vc_column][tdb_breadcrumbs][/vc_column][/vc_row]'
      + '[vc_row full_width="stretch_row_1200 td-stretch-content" gap="10" el_class="td-custom-order"][vc_column width="1/4"][td_block_ad_box][/vc_column][vc_column width="1/2" el_class="td-order-1"][tdb_loop modules_on_row="x"][/vc_column][/vc_row]'
      + '[vc_row][vc_column][td_block_ad_box][/vc_column][/vc_row][/tdc_zone]';
    const once = call('lld_hub_template_insert', tpl);
    expect(once).not.toBe(tpl);
    const row = once.match(/\[vc_row[^\]]*el_class="lld-hub-row"\]\[vc_column\]\[lld_category_hub\]\[\/vc_column\]\[\/vc_row\]/);
    expect(row).toBeTruthy();
    expect(row[0]).toContain('row_hide_on_pagination="yes"');
    expect(once.indexOf('lld-hub-row')).toBeLessThan(once.indexOf('[tdb_loop'));
    expect(once.slice(once.indexOf('[/vc_row]', once.indexOf('lld-hub-row')) + 9)).toMatch(/^\[vc_row full_width="stretch_row_1200 td-stretch-content" gap="10" el_class="td-custom-order"\]/);
    expect(once.indexOf('[tdb_breadcrumbs')).toBeLessThan(once.indexOf('lld-hub-row'));
    expect((once.match(/\[lld_category_hub\]/g) || []).length).toBe(1);
    expect(call('lld_hub_template_insert', once)).toBe(once);
    expect(call('lld_hub_template_revert', once)).toBe(tpl);
    expect(call('lld_hub_template_revert', tpl)).toBe(tpl);
    expect(call('lld_hub_template_insert', '[tdc_zone][vc_row][vc_column][tdb_title][/vc_column][/vc_row][/tdc_zone]')).toBe('');
  });
});
