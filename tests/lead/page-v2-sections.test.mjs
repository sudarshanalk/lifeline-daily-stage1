// Public pages v2 (S2-L1): a tool page's own content is laid out into the design's cards by its h2[data-lld-section]
// headings (lld_page_v2_sections), the headings keep their attribute and text (lld_page_v2_h2), and the related-reading
// guides are the ones [lld_tool_related] chose (lld_page_v2_related_ids). Runs in PHP CLI without WordPress (page-v2.php
// wires its hooks only when add_filter exists).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vitest';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';

const DIR = resolve(ROOT, 'site/public_html/wp-content/mu-plugins/lld-tools');
const PHP = resolve(DIR, 'page-v2.php').replace(/\\/g, '/');
const run = (expr, input) => JSON.parse(execFileSync('php', ['-r',
  `define('ABSPATH', '/'); function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } require ${JSON.stringify(PHP)}; $in = stream_get_contents(STDIN); echo json_encode(${expr}, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`],
  { input, encoding: 'utf8' }));
const landing = JSON.parse(readFileSync(resolve(DIR, 'data/landing-pages.json'), 'utf8'));

// the page content as `wp lld pages apply` writes it (lld_tools_landing_content), before shortcodes run
const pageContent = (slug) => landing.section_keys.map((key, i) => {
  const page = landing.pages[slug];
  return `\n<h2 data-lld-section="${key}">${(page.headings || {})[key] || landing.headings[i]}</h2>\n${page.sections[key]}\n`;
}).join('').trim();

describe('public pages v2: tool page sections', () => {
  for (const slug of Object.keys(landing.pages)) {
    test(`every section of the ${slug} page is found, in order, with its own heading and text`, () => {
      const out = run('lld_page_v2_sections($in)', pageContent(slug));
      expect(out.intro).toBe('');
      expect(Object.keys(out.sections)).toEqual(landing.section_keys);
      landing.section_keys.forEach((key, i) => {
        expect(out.sections[key].heading).toBe((landing.pages[slug].headings || {})[key] || landing.headings[i]);
        expect(out.sections[key].body).toBe(landing.pages[slug].sections[key].trim());
      });
    });
  }

  test('content without section headings is kept whole, so the page shows it in one card', () => {
    const out = run('lld_page_v2_sections($in)', '<p>Edited page.</p><h2>Plain heading</h2><p>More.</p>');
    expect(out).toEqual({ intro: '<p>Edited page.</p><h2>Plain heading</h2><p>More.</p>', sections: [] });
  });

  test('text before the first section is kept as the intro, and a section with attributes is still found', () => {
    const out = run('lld_page_v2_sections($in)', '<p>Note.</p><h2 id="p" data-lld-section="purpose" class="x">Purpose</h2><p>A.</p>');
    expect(out.intro).toBe('<p>Note.</p>');
    expect(out.sections.purpose).toEqual({ h2: '<h2 id="p" data-lld-section="purpose" class="x">Purpose</h2>', heading: 'Purpose', body: '<p>A.</p>' });
  });

  test('the design classes are added to a heading without losing its section attribute or text', () => {
    expect(run('lld_page_v2_h2($in, "text-lg font-extrabold")', '<h2 data-lld-section="who">Who it is for</h2>'))
      .toBe('<h2 class="text-lg font-extrabold" data-lld-section="who">Who it is for</h2>');
    expect(run('lld_page_v2_h2($in, "text-lg")', '<h2 id="w" class="old" data-lld-section="who">Who</h2>'))
      .toBe('<h2 class="text-lg" id="w" data-lld-section="who">Who</h2>');
  });

  test('the related guides are the posts [lld_tool_related] listed, in its order, each once', () => {
    const rel = '<ul class="lld-related" data-test="related-reading"><li data-post-id="12"><a href="/a/">A</a></li><li data-post-id="7"><a href="/b/">B</a></li><li data-post-id="12">A</li></ul>';
    expect(run('lld_page_v2_related_ids($in)', rel)).toEqual([12, 7]);
    expect(run('lld_page_v2_related_ids($in)', '<p data-test="related-reading">More reading on this topic is coming soon.</p>')).toEqual([]);
  });
});
