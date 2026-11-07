// Article page v2 (S2-L1): lld_article_v2_parts() lays an article's rendered HTML out into the design's blocks (intro, Key
// Takeaways, benchmarks card, one card per H2, FAQ, references, and what the site appends: guide form, hub link, byline).
// It runs in PHP CLI without WordPress (article-v2.php wires its hooks only when add_filter exists).
import { execFileSync } from 'node:child_process';
import { describe, expect, test } from 'vitest';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';

const PHP = resolve(ROOT, 'site/public_html/wp-content/mu-plugins/lld-tools/article-v2.php').replace(/\\/g, '/');
const parts = (html) => JSON.parse(execFileSync('php', ['-r',
  `define('ABSPATH', '/'); require ${JSON.stringify(PHP)}; echo json_encode(lld_article_v2_parts(stream_get_contents(STDIN)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`],
  { input: html, encoding: 'utf8' }));

// what the_content gives for an article written to the template (shortcodes already run, site additions appended)
const templated = [
  '<p>Diabetes builds slowly.</p><p>That window is your chance.</p>',
  '<div class="lld-answer" data-test="direct-answer" role="note"><p>Type 2 diabetes is not inevitable.</p></div>',
  '<div class="lld-benchmarks" data-source="Diabetes Prevention Program"><p class="lld-stat" data-value="-58% risk">Lower rate with lifestyle change</p>',
  '<p class="lld-stat" data-value="5 to 7%">Weight loss target</p><p class="lld-summary">Small steady shifts help.</p></div>',
  '<h2 id="whole-foods" data-short="Eat more whole foods" data-label="Nutrition">1. Eat More Whole Foods</h2><p>Whole foods help.</p>',
  '<p class="lld-tool-cta" data-test="tool-cta" data-tool="diabetes"><a href="/health-tools/diabetes-risk-check/">Check my risk: Diabetes Risk Check</a></p>',
  '<p class="lld-note"><strong>Evidence note:</strong> Mediterranean and DASH both help.</p>',
  '<h2 data-short="Move every day">2. Move Your Body</h2><p>Walk.</p><p>Lift.</p>',
  '<h2 data-short="Start small">Start Small and Build</h2><p>Pick one habit.</p><p class="lld-closing">Your actions decide.</p>',
  '<section class="lld-faq" data-test="faq"><h2>Frequently asked questions</h2>',
  '<div class="lld-faq__item"><h3 class="lld-faq__q">Can prediabetes be reversed?</h3><div class="lld-faq__a">Often, yes.</div></div>',
  '<div class="lld-faq__item"><h3 class="lld-faq__q">When should I get checked?</h3><div class="lld-faq__a">From 35 to 45.</div></div></section>',
  '<section class="lld-sources" data-test="sources"><h2>Sources</h2><ol><li><a href="https://example.test/a">Source A</a>. CDC.</li><li>Source B. NIH.</li></ol></section>',
  '<p class="lld-review-byline" data-test="review-byline">Reviewed by X on <time datetime="2026-10-01">1 October 2026</time></p>',
  '<p class="lld-hub-link" data-test="hub-link">More articles on this topic: <a href="/category/blood-sugar-metabolic-health/">Blood Sugar</a></p>',
  '<section data-lld-guide><h2>Get the free guide</h2><form method="post" class="lld-form"><input type="hidden" name="lld_track" value="blood-sugar"></form></section>',
].join('');

describe('article page v2: the article laid out into the design blocks', () => {
  test('an article written to the template fills every block, and the site additions are taken out of the text', () => {
    const p = parts(templated);
    expect(p.intro).toBe('<p>Diabetes builds slowly.</p><p>That window is your chance.</p>');
    expect(p.answer).toContain('Type 2 diabetes is not inevitable.');
    expect(p.benchmarks.source).toBe('Diabetes Prevention Program');
    expect(p.benchmarks.stats.map((s) => s.value)).toEqual(['-58% risk', '5 to 7%']);
    expect(p.benchmarks.summary).toBe('Small steady shifts help.');
    expect(p.sections.map((s) => [s.id, s.short, s.label, s.closing])).toEqual([
      ['whole-foods', 'Eat more whole foods', 'Nutrition', false],
      ['section-2', 'Move every day', '', false],
      ['section-3', 'Start small', '', true],
    ]);
    // the tool link and the note stay in their section
    expect(p.sections[0].body).toContain('lld-tool-cta');
    expect(p.sections[0].body).toContain('lld-note');
    expect(p.faq.map((f) => f.q)).toEqual(['Can prediabetes be reversed?', 'When should I get checked?']);
    expect(p.faq_title).toBe('Frequently asked questions');
    expect((p.sources.match(/<li/g) || []).length).toBe(2);
    expect(p.guide.track).toBe('blood-sugar');
    expect(p.hub_link).toContain('More articles on this topic');
    expect(p.byline).toContain('lld-review-byline');
    // nothing appended by the site is left inside the last section
    expect(p.sections[2].body).not.toMatch(/lld-faq|lld-sources|data-lld-guide|lld-hub-link|lld-review-byline/);
  });

  test('an article not yet in the template still reads: its opening as the intro, then a card per H2 named by its heading', () => {
    const p = parts('<p>Opening.</p><table><tr><td>Key Insight</td></tr></table><h2>What <em>is</em> fibre?</h2><p>Text.</p><h2>How much?</h2><p>More.</p>');
    expect(p.intro).toBe('<p>Opening.</p><table><tr><td>Key Insight</td></tr></table>');
    expect(p.answer).toBe('');
    expect(p.benchmarks).toBeNull();
    expect(p.sections.map((s) => s.short)).toEqual(['What is fibre?', 'How much?']);
    expect(p.faq).toEqual([]);
    expect(p.guide).toBeNull();
  });

  test('the references list holds only its items (line breaks WordPress adds between them are dropped)', () => {
    const p = parts('<p>x</p><section class="lld-sources" data-test="sources"><h2>Sources</h2><ol><li>A.</li><br />\n<li>B.</li><br>\n</ol></section>');
    expect(p.sources).toBe('<li>A.</li><li>B.</li>');
  });

  test('an article with no H2 keeps all of its text as the intro', () => {
    const p = parts('<p>One.</p><p>Two.</p>');
    expect(p.intro).toBe('<p>One.</p><p>Two.</p>');
    expect(p.sections).toEqual([]);
  });
});
