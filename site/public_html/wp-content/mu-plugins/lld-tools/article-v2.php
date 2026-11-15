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
 * The whole element (with everything nested inside it) that starts at the first tag matching $openRe, found with a depth
 * count of its own tag name; returns [element, html without it]. Unbalanced HTML gives ['', $html].
 */
function lld_article_v2_take_el(string $openRe, string $html): array {
  if (!preg_match($openRe, $html, $m, PREG_OFFSET_CAPTURE) || !preg_match('#^<([a-z0-9]+)#i', $m[0][0], $tn)) return ['', $html];
  $tag = strtolower($tn[1]); $start = $m[0][1]; $depth = 0;
  if (!preg_match_all('#<(/?)' . $tag . '\b[^>]*>#i', $html, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $start)) return ['', $html];
  foreach ($all as $t) {
    $depth += $t[1][0] === '/' ? -1 : 1;
    if ($depth === 0) {
      $end = $t[0][1] + strlen($t[0][0]);
      return [substr($html, $start, $end - $start), substr($html, 0, $start) . substr($html, $end)];
    }
  }
  return ['', $html];
}

/** The inside of an element taken by lld_article_v2_take_el(). */
function lld_article_v2_inner(string $el): string {
  return (string)preg_replace(['#^<[^>]+>#', '#</[a-z0-9]+>$#i'], '', $el);
}

/**
 * The article's rendered HTML (the_content, shortcodes run) split into the design's blocks. Pure: no WordPress calls, so
 * tests can feed it plain strings. Returns intro, answer, benchmarks {stats[], source, summary}, sections[] {id, title,
 * short, label, body, closing}, faq[] {q, a}, faq_title, faq_note, sources (the <li> list), guide {html, track}, hub_link,
 * byline.
 */
function lld_article_v2_parts(string $html): array {
  $out = ['intro' => '', 'answer' => '', 'benchmarks' => null, 'sections' => [], 'faq' => [], 'faq_title' => '', 'faq_note' => '',
    'sources' => '', 'guide' => null, 'hub_link' => '', 'byline' => ''];
  $rescued = '';   // a tool link (FR1-05) the site placed inside a block that the page draws itself
  $rescue = function (string $block) use (&$rescued): void {
    if (preg_match_all('#<p class="lld-tool-cta"[^>]*>.*?</p>#is', $block, $cm)) $rescued .= implode('', $cm[0]);
  };

  // what the site appends at the end
  [$g, $html] = lld_article_v2_take_el('#<section data-lld-guide\b[^>]*>#i', $html);
  if ($g !== '') {
    $rescue($g);
    $out['guide'] = ['html' => $g, 'track' => preg_match('#name="lld_track" value="([^"]*)"#', $g, $tm) ? $tm[1] : ''];
  }
  [$out['hub_link'], $html] = lld_article_v2_take('#<p class="lld-hub-link"[^>]*>.*?</p>#is', $html);
  [$out['byline'], $html] = lld_article_v2_take('#<p class="lld-review-byline"[^>]*>.*?</p>#is', $html);
  [$faq, $html] = lld_article_v2_take_el('#<section class="lld-faq"[^>]*>#i', $html);
  if ($faq !== '') {
    $rescue($faq);
    $inner = lld_article_v2_inner($faq);
    if (preg_match('#<h2[^>]*>(.*?)</h2>#is', $inner, $fm)) { $out['faq_title'] = trim(strip_tags($fm[1])); $inner = str_replace($fm[0], '', $inner); }
    while (true) {
      [$item, $inner] = lld_article_v2_take_el('#<div class="lld-faq__item"[^>]*>#i', $inner);
      if ($item === '') break;
      $q = preg_match('#<h3 class="lld-faq__q">(.*?)</h3>#is', $item, $qm) ? $qm[1] : '';
      [$a] = lld_article_v2_take_el('#<div class="lld-faq__a"[^>]*>#i', $item);
      $out['faq'][] = ['q' => $q, 'a' => lld_article_v2_inner($a)];
    }
    // anything the writer put inside [lld_faq] besides the questions (an intro line) stays with the FAQ
    $note = preg_replace('#<p class="lld-tool-cta"[^>]*>.*?</p>#is', '', $inner);
    if (trim(strip_tags((string)$note)) !== '') $out['faq_note'] = trim((string)$note);
  }
  [$src, $html] = lld_article_v2_take_el('#<section class="lld-sources"[^>]*>#i', $html);
  if ($src !== '') {
    $rescue($src);
    // wpautop can leave <br> or empty <p> between the items; a list holds only its items
    if (preg_match('#<ol>(.*)</ol>#is', $src, $sm)) $out['sources'] = trim((string)preg_replace('#(^|</li>)(?:\s|<br\s*/?>|<p>\s*</p>|</?p>)*(?=<li\b|$)#i', '$1', trim($sm[1])));
  }

  // old "Key Insight" boxes are tables with a heading inside a cell: they become the design's note box, so the split at
  // each H2 never cuts a table in two (about half the articles written before the template have one)
  $html = (string)preg_replace_callback('#<table\b[^>]*>(?:(?!</table>).)*?<h[2-6]\b.*?</table>#is', function ($m) {
    $c = (string)preg_replace('#</?(table|tbody|thead|tfoot|tr|td|th|colgroup|col)\b[^>]*>#i', '', $m[0]);
    $c = (string)preg_replace('#<h[2-6][^>]*>(.*?)</h[2-6]>#is', '<p><strong>$1</strong></p>', $c);
    return '<div class="lld-note lld-note--insight">' . trim($c) . '</div>';
  }, $html);

  // the main part: everything before the first H2 holds the intro, Key Takeaways and benchmarks
  $first = preg_match('#<h2\b#i', $html, $hm, PREG_OFFSET_CAPTURE) ? $hm[0][1] : strlen($html);
  $head = substr($html, 0, $first); $rest = substr($html, $first);
  [$answer, $head] = lld_article_v2_take_el('#<div class="lld-answer"[^>]*>#i', $head);
  $out['answer'] = $answer;
  [$bm, $head2] = lld_article_v2_take_el('#<div[^>]*class="[^"]*\blld-benchmarks\b[^"]*"[^>]*>#i', $head);
  if ($bm !== '') {
    preg_match_all('#<p[^>]*class="[^"]*\blld-stat\b[^"]*"[^>]*data-value="([^"]*)"[^>]*>(.*?)</p>#is', $bm, $st, PREG_SET_ORDER);
    if ($st) {   // a card with no numbers stays in the text as it was written
      $head = $head2;
      $out['benchmarks'] = [
        'stats' => array_map(fn($s) => ['value' => html_entity_decode($s[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'line' => $s[2]], $st),
        'source' => preg_match('#data-source="([^"]*)"#', $bm, $bs) ? html_entity_decode($bs[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
        'summary' => preg_match('#<p[^>]*class="[^"]*\blld-summary\b[^"]*"[^>]*>(.*?)</p>#is', $bm, $bsm) ? $bsm[1] : '',
      ];
    }
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
  if ($rescued !== '') {
    if ($out['sections']) $out['sections'][count($out['sections']) - 1]['body'] .= $rescued; else $out['intro'] .= $rescued;
  }
  return $out;
}

/**
 * Key Takeaways: the first sentence of its first paragraph in bold (owner, 7 Nov 2026). The sentence ends at . ! or ?
 * (with any closing quote or bracket) followed by the paragraph's end or a space and a capital, a digit or an opening
 * quote; numbers and common abbreviations do not end it. Only 30 words or fewer, before any tag; else left as written.
 */
function lld_article_v2_lead_bold(string $answer): string {
  if (!preg_match('#<p\b[^>]*>\s*#i', $answer, $m, PREG_OFFSET_CAPTURE)) return $answer;
  $start = $m[0][1] + strlen($m[0][0]);
  $rest = substr($answer, $start);
  $lt = strpos($rest, '<');
  $text = $lt === false ? $rest : substr($rest, 0, $lt);   // the bold part never crosses a tag
  $abbr = ['e.g', 'i.e', 'cf', 'vs', 'etc', 'approx', 'dr', 'mr', 'mrs', 'ms', 'prof', 'st', 'no', 'fig', 'u.s', 'u.k', 'a.m', 'p.m'];
  $len = strlen($text);
  for ($i = 0; $i < $len; $i++) {
    $c = $text[$i];
    if ($c !== '.' && $c !== '!' && $c !== '?') continue;
    if ($c === '.') {
      // a number like 2.5, or an abbreviation such as e.g. or Dr.
      if ($i + 1 < $len && ctype_digit($text[$i + 1])) continue;
      if (preg_match('#([A-Za-z]+(?:\.[A-Za-z]+)*)$#', substr($text, 0, $i), $wm) && in_array(strtolower($wm[1]), $abbr, true)) continue;
    }
    // the sentence may close with quotes or brackets, written as characters or as entities
    $j = $i + 1;
    while ($j < $len && preg_match('#^(?:["\')\]’”»]|&\#8217;|&\#8221;|&rsquo;|&rdquo;|&quot;)#u', substr($text, $j), $qm)) $j += strlen($qm[0]);
    $end = $j;
    $tail = substr($text, $end);
    if ($lt === false ? trim($tail) === '' : trim($tail) === '') { $first = substr($text, 0, $end); break; }
    // then a space (also a no-break space) and the start of the next sentence: a capital, a digit or an opening quote
    if (preg_match('#^(?:\s|&nbsp;|\x{00A0})+(?:["\'(\[‘“«]|&\#8216;|&\#8220;|&lsquo;|&ldquo;)?[\p{Lu}0-9]#u', $tail)) { $first = substr($text, 0, $end); break; }
  }
  if (!isset($first) || strlen(trim($first)) < 3) return $answer;
  if (count(preg_split('#\s+#u', trim(html_entity_decode(strip_tags($first), ENT_QUOTES | ENT_HTML5, 'UTF-8')))) > 30) return $answer;
  return substr($answer, 0, $start) . '<strong class="lld-lead">' . $first . '</strong>' . substr($answer, $start + strlen($first));
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
        if (strpos($src, '/wp-content/themes/') !== false || strpos($src, '/wp-content/plugins/td-') !== false || in_array($h, ['lld-tools', 'lld-theme'], true)) {
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
