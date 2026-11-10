// TC-FR2-21 answer-engine and AI-assistant optimisation (S2-L4): the lld-tools/search.php builders, without WordPress.
// The live halves (Rich Results Test on real articles, signed-out probes of the member plugin) run on staging (L7).
import { describe, expect, test } from 'vitest';
import { call, SEARCH_CFG } from './search-php.mjs';

const words = (n) => Array.from({ length: n }, (_, i) => `w${i}`).join(' ');

describe('TC-FR2-21 AEO and GEO', () => {
  test('TC-FR2-21-AS-01', () => {
    // a direct answer of 60 words or fewer, first in the article, passes; 61 words, or an answer after other text, fails
    const ok = call('lld_search_aeo_row', `[lld_answer]<strong>Protein</strong> ${words(59)}[/lld_answer]<p>More text.</p>`);
    expect(ok).toMatchObject({ has_answer: true, answer_words: 60, answer_ok: true });
    expect(call('lld_search_aeo_row', `[lld_answer]${words(61)}[/lld_answer]`).answer_ok).toBe(false);
    expect(call('lld_search_aeo_row', `<p>Intro first.</p>[lld_answer]${words(20)}[/lld_answer]`).answer_ok).toBe(false);
    expect(call('lld_search_aeo_row', '<p>No answer box.</p>')).toMatchObject({ has_answer: false, answer_ok: false });
  });

  test('TC-FR2-21-AS-02', () => {
    // FAQPage markup: every shown question is a Question with an acceptedAnswer Answer (the Rich Results Test runs on staging)
    const ld = call('lld_search_faq_jsonld', [['How much protein do I need?', 'Most adults need about 0.8 g per kg a day.'], ['Is more better?', 'Not always.']], 'https://lifelinedaily.com/protein-guide/');
    expect(ld).toMatchObject({ '@context': 'https://schema.org', '@type': 'FAQPage', '@id': 'https://lifelinedaily.com/protein-guide/#faq' });
    expect(ld.mainEntity).toHaveLength(2);
    for (const q of ld.mainEntity) {
      expect(q['@type']).toBe('Question'); expect(q.name).toBeTruthy();
      expect(q.acceptedAnswer).toMatchObject({ '@type': 'Answer' }); expect(q.acceptedAnswer.text).toBeTruthy();
    }
  });

  test('TC-FR2-21-AS-03', () => {
    // llms.txt lists only the summary, the tools, the given articles and pages; markdown-breaking characters are neutralised
    const txt = call('lld_search_llms_text', SEARCH_CFG, { name: 'Lifeline Daily', url: 'https://lifelinedaily.com/' },
      [{ title: 'Gut [health] basics', url: 'https://lifelinedaily.com/gut/', description: '<b>Fibre</b> and you', section: 'Gut Health' }],
      [{ title: 'About Us', url: 'https://lifelinedaily.com/about-us/' }]);
    expect(txt.startsWith('# Lifeline Daily\n\n> ')).toBe(true);
    expect(txt).toContain('## Health tools');
    expect(txt).toContain('[Daily Protein Target](https://lifelinedaily.com/health-tools/protein/)');
    expect(txt).toContain('[Diabetes Risk Check](https://lifelinedaily.com/health-tools/diabetes-risk-check/)');
    expect(txt).toContain('## Gut Health\n\n- [Gut (health) basics](https://lifelinedaily.com/gut/): Fibre and you');
    expect(txt).toContain('## About this site\n\n- [About Us](https://lifelinedaily.com/about-us/)');
    // the site describes itself with its official profiles (owner, 28 Oct 2026, O-4)
    expect(txt).toContain('## Official profiles\n\n- [Facebook](https://www.facebook.com/lifelineofhealth)\n- [X](https://x.com/LifelineDaily)\n'
      + '- [Pinterest](https://uk.pinterest.com/lifelinedaily7/)\n- [YouTube](https://www.youtube.com/@LifelineDaily)\n'
      + '- [Instagram](https://www.instagram.com/life.l.daily/)\n');
    // never an email address: the generated file it replaces listed theme lead records
    expect(txt).not.toMatch(/[a-z0-9._-]+@[a-z0-9-]+\.[a-z]{2,}/i);

    // Yoast describes the same site identity: an organisation with those profiles; the logo is left alone
    const before = { titles: { company_or_person: 'person', company_name: 'Lifeline Daily', company_logo_id: 2719, website_name: 'Lifeline Daily' },
      social: { facebook_site: '', twitter_site: '', other_social_urls: [], opengraph: true } };
    const plan = call('lld_search_identity_plan', SEARCH_CFG, before.titles, before.social);
    expect(plan.titles).toMatchObject({ company_or_person: 'company', company_name: 'Lifeline Daily', company_logo_id: 2719, website_name: 'Lifeline Daily' });
    expect(plan.social).toMatchObject({ facebook_site: 'https://www.facebook.com/lifelineofhealth', twitter_site: 'LifelineDaily', opengraph: true });
    expect(plan.social.other_social_urls).toEqual(['https://uk.pinterest.com/lifelinedaily7/', 'https://www.youtube.com/@LifelineDaily', 'https://www.instagram.com/life.l.daily/']);
    expect(plan.changes).toContain('wpseo_titles.company_or_person -> company');
    // a second run changes nothing
    expect(call('lld_search_identity_plan', SEARCH_CFG, plan.titles, plan.social).changes).toEqual([]);
    // an address that is not https, or that would break markdown, is never used
    const bad = { ...SEARCH_CFG, identity: { organisation: 'Lifeline Daily', profiles: [{ name: 'A', url: 'http://example.com/a' }, { name: 'B', url: 'https://example.com/b)(c' }] } };
    expect(call('lld_search_identity_plan', bad, before.titles, before.social).social.other_social_urls).toEqual([]);
    expect(call('lld_search_llms_text', bad, { name: 'Lifeline Daily', url: 'https://lifelinedaily.com/' }, [], [])).not.toContain('## Official profiles');
  });

  test('TC-FR2-21-AS-04', () => {
    // robots.txt: every member path is excluded for all crawlers and for the named AI assistants, which may read the rest
    const txt = call('lld_search_robots_text', SEARCH_CFG, 'https://lifelinedaily.com/sitemap_index.xml');
    const groups = txt.split(/\n\n/).filter((g) => /User-agent:/.test(g)).map((g) => g + '\n');
    const star = groups.find((g) => /^User-agent: \*$/m.test(g));
    const ai = groups.find((g) => /User-agent: GPTBot/.test(g));
    expect(SEARCH_CFG.member_paths.length).toBeGreaterThan(5);
    for (const p of SEARCH_CFG.member_paths) { expect(star).toContain(`Disallow: ${p}\n`); expect(ai).toContain(`Disallow: ${p}\n`); }
    for (const a of ['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'OAI-SearchBot']) expect(ai).toContain(`User-agent: ${a}\n`);
    expect(ai).toContain('Allow: /\n');
    expect(star).not.toMatch(/^Disallow: \/$/m);
    expect(txt).toContain('Sitemap: https://lifelinedaily.com/sitemap_index.xml');
  });

  test('TC-FR2-21-AS-05', () => {
    // a sources section counts only when every source carries a date (published or checked)
    const dated = call('lld_search_aeo_row', '[lld_sources][lld_source title="Protein" url="https://www.who.int/" published="2024-03"][lld_source title="NHS" checked="2026-10-25"][/lld_sources]');
    expect(dated).toMatchObject({ sources: 2, sources_dated: 2, sources_ok: true });
    expect(call('lld_search_aeo_row', '[lld_sources][lld_source title="Undated" url="https://example.org/"][/lld_sources]').sources_ok).toBe(false);
    expect(call('lld_search_aeo_row', '<p>No sources.</p>').sources_ok).toBe(false);
    // each source link opens in a new tab, like every other link in an article
    expect(call('lld_search_source_link', 'https://www.who.int/', 'Protein')).toBe('<a href="https://www.who.int/" target="_blank" rel="noopener">Protein</a>');
  });
});
