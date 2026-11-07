<?php
/**
 * Article page v2 (S2-L1; owner's new UI pack, 6 Nov 2026: `03 UI Designs/UI-Inventory/Latest UIs/lld_article_diabetes_style1.html`,
 * also the template the article audit writes to: Owner_Questions 140 to 142, audit check 46).
 *
 * Every published post is rendered by its own full-page template (`templates/article-v2.php`). The article's own HTML is
 * laid out into the design's blocks by lld_article_v2_parts():
 *   intro (before [lld_answer]) → Key Takeaways box ([lld_answer]) → key benchmarks card (div.lld-benchmarks with two
 *   p.lld-stat[data-value], data-source and p.lld-summary) → one card per H2 section (H2 data-short for the guide list,
 *   data-label for the tag; one p.lld-note per section; p.lld-closing in the last) → FAQ ([lld_faq]) → references
 *   ([lld_sources]). An article not yet in that shape still reads well: intro, then a card per H2.
 * What the site adds to every article stays: the tool link (FR1-05) where it is placed, the starter guide (lld-members) and
 * "More articles on this topic" (FR2-20) in the design's guide card, the FAQ data for search (SCR-43).
 * Post meta read (optional): lld_image_caption (else the featured image's caption), lld_faq_intro, lld_guide_label.
 * Switch off with `define('LLD_ARTICLE_V2', false)` in wp-config.php to go back to the Newspaper single template.
 */
if (!defined('ABSPATH')) exit;

/** True on a front-end view of a single post while the v2 article page is on. */
function lld_article_v2_active(): bool {
  if (defined('LLD_ARTICLE_V2') && !LLD_ARTICLE_V2) return false;
  if (!function_exists('lld_tools_front_view') || !lld_tools_front_view()) return false;
  if (!function_exists('is_singular') || !is_singular('post')) return false;
  return !(function_exists('is_embed') && is_embed());
}

/** Takes the first element matching $re out of $html; returns [match, rest]. */
function lld_article_v2_take(string $re, string $html): array {
  if (!preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) return ['', $html];
  return [$m[0][0], substr($html, 0, $m[0][1]) . substr($html, $m[0][1] + strlen($m[0][0]))];
}

/**
 * The article's rendered HTML (the_content, shortcodes run) split into the design's blocks. Pure: no WordPress calls, so
 * tests can feed it plain strings. Returns intro, answer, benchmarks {stats[], source, summary}, sections[] {id, title,
 * short, label, body, closing}, faq[] {q, a}, faq_title, sources (the <li> list), guide {html, track}, hub_link, byline.
 */
function lld_article_v2_parts(string $html): array {
  $out = ['intro' => '', 'answer' => '', 'benchmarks' => null, 'sections' => [], 'faq' => [], 'faq_title' => '', 'sources' => '',
    'guide' => null, 'hub_link' => '', 'byline' => ''];
  // what the site appends at the end
  [$g, $html] = lld_article_v2_take('#<section data-lld-guide>.*?</section>#is', $html);
  if ($g !== '') {
    $track = preg_match('#name="lld_track" value="([^"]*)"#', $g, $tm) ? $tm[1] : '';
    $out['guide'] = ['html' => $g, 'track' => $track];
  }
  [$out['hub_link'], $html] = lld_article_v2_take('#<p class="lld-hub-link"[^>]*>.*?</p>#is', $html);
  [$out['byline'], $html] = lld_article_v2_take('#<p class="lld-review-byline"[^>]*>.*?</p>#is', $html);
  [$faq, $html] = lld_article_v2_take('#<section class="lld-faq"[^>]*>.*?</section>#is', $html);
  if ($faq !== '') {
    $out['faq_title'] = preg_match('#<h2>(.*?)</h2>#is', $faq, $fm) ? trim(strip_tags($fm[1])) : '';
    preg_match_all('#<div class="lld-faq__item"><h3 class="lld-faq__q">(.*?)</h3><div class="lld-faq__a">(.*?)</div></div>#is', $faq, $qs, PREG_SET_ORDER);
    foreach ($qs as $q) $out['faq'][] = ['q' => $q[1], 'a' => $q[2]];
  }
  [$src, $html] = lld_article_v2_take('#<section class="lld-sources"[^>]*>.*?</section>#is', $html);
  // wpautop can leave <br> or empty <p> between the items; a list holds only its items
  if ($src !== '' && preg_match('#<ol>(.*)</ol>#is', $src, $sm)) $out['sources'] = trim((string)preg_replace('#(^|</li>)(?:\s|<br\s*/?>|<p>\s*</p>|</?p>)*(?=<li\b|$)#i', '$1', trim($sm[1])));

  // the main part: everything before the first H2 holds the intro, Key Takeaways and benchmarks
  $first = preg_match('#<h2\b#i', $html, $hm, PREG_OFFSET_CAPTURE) ? $hm[0][1] : strlen($html);
  $head = substr($html, 0, $first); $rest = substr($html, $first);
  [$out['answer'], $head] = lld_article_v2_take('#<div class="lld-answer"[^>]*>.*?</div>#is', $head);
  [$bm, $head] = lld_article_v2_take('#<div[^>]*class="[^"]*\blld-benchmarks\b[^"]*"[^>]*>.*?</div>#is', $head);
  if ($bm !== '') {
    preg_match_all('#<p[^>]*class="[^"]*\blld-stat\b[^"]*"[^>]*data-value="([^"]*)"[^>]*>(.*?)</p>#is', $bm, $st, PREG_SET_ORDER);
    $out['benchmarks'] = [
      'stats' => array_map(fn($s) => ['value' => html_entity_decode($s[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'line' => $s[2]], $st),
      'source' => preg_match('#data-source="([^"]*)"#', $bm, $bs) ? html_entity_decode($bs[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
      'summary' => preg_match('#<p[^>]*class="[^"]*\blld-summary\b[^"]*"[^>]*>(.*?)</p>#is', $bm, $bsm) ? $bsm[1] : '',
    ];
  }
  $out['intro'] = trim($head);

  // one card per H2
  $n = 0;
  foreach (preg_split('#(?=<h2\b)#i', $rest, -1, PREG_SPLIT_NO_EMPTY) as $sec) {
    if (!preg_match('#^<h2([^>]*)>(.*?)</h2>#is', $sec, $h)) { $out['intro'] .= $sec; continue; }
    $n++;
    $attr = $h[1];
    $id = preg_match('#\bid="([^"]+)"#', $attr, $im) ? $im[1] : 'section-' . $n;
    $title = trim($h[2]);
    $short = preg_match('#data-short="([^"]*)"#', $attr, $s1) ? html_entity_decode($s1[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    $label = preg_match('#data-label="([^"]*)"#', $attr, $l1) ? html_entity_decode($l1[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    $body = trim(substr($sec, strlen($h[0])));
    $out['sections'][] = ['id' => $id, 'title' => $title, 'short' => $short !== '' ? $short : trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
      'label' => $label, 'body' => $body, 'closing' => (bool)preg_match('#class="[^"]*\blld-closing\b#', $body)];
  }
  return $out;
}

/** Reading time in minutes at 200 words a minute (at least 1). */
function lld_article_v2_minutes(string $html): int {
  return max(1, (int)ceil(str_word_count(wp_strip_all_tags(strip_shortcodes($html))) / 200));
}

/* ------------------------------------------------------------------ hooks ------------------------------------------ */
if (function_exists('add_filter')) {
  add_filter('template_include', function ($template) {
    return lld_article_v2_active() ? __DIR__ . '/templates/article-v2.php' : $template;
  }, 99);
}
if (function_exists('add_action')) {
  add_action('wp_enqueue_scripts', function () {
    if (!lld_article_v2_active()) return;
    global $wp_styles, $wp_scripts;
    foreach ([$wp_styles, $wp_scripts] as $deps) {
      if (!$deps || empty($deps->queue)) continue;
      foreach ((array)$deps->queue as $h) {
        $src = isset($deps->registered[$h]) ? (string)$deps->registered[$h]->src : '';
        if (strpos($src, '/wp-content/themes/') !== false || in_array($h, ['lld-tools', 'lld-theme'], true)) {
          ($deps === $wp_styles) ? wp_dequeue_style($h) : wp_dequeue_script($h);
        }
      }
    }
    wp_enqueue_style('lld-v2', lld_home_v2_asset('lld-v2.css'), [], null);
    wp_enqueue_script('lld-home-v2', lld_home_v2_asset('lld-home-v2.js'), [], null, true);   // header menu, Day/Night, copy link
  }, 999);
  add_action('wp_head', function () {
    if (!lld_article_v2_active()) return;
    foreach (['instrument-serif-latin', 'plus-jakarta-sans-latin-var'] as $f) {
      echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url(plugins_url('assets/fonts/' . $f . '.woff2', LLD_TOOLS_DIR . '/lld-tools.php')) . '">' . "\n";
    }
  }, 1);
  add_action('wp_footer', function () {
    if (lld_article_v2_active() && !defined('LLD_FOOTER_RENDERED')) define('LLD_FOOTER_RENDERED', true);
  }, 1);
}
