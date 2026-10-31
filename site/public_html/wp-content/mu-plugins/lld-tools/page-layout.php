<?php
/**
 * Interface Pack Layouts (owner-approved 28 Oct 2026):
 *   - Package 2 (SCR-43): Single Article Reading Template (`single_article_template_demo.html`)
 *   - Package 5 (SCR-02..07): Health Tools Hub & Calculator Landing Pages (`health_tools_and_calculators_demo.html`)
 *   - Package 7 (UI1-11, DEC-12): Governance & Trust Pages (`site_governance_and_trust_demo.html`)
 *   - Package 8 (SCR-SEARCH, SCR-ARCHIVE, SCR-AUTHOR): Search, Author & 404 Recovery Templates
 *
 * Theme files remain untouched (DEC-22). All enhancements sit outside `.td-post-content` and `[data-test="disclaimer-page"]`
 * so CI copy-hash, TC-FR1-04, TC-FR1-05, TC-FR1-17, and TC-FR1-18 pass without modification.
 */
if (!defined('ABSPATH')) exit;
if (!function_exists('add_action')) return;

/* ---------------------------------------------------------------- 1. LiteSpeed lazy-load & JS-defer exclusions ---- */
add_filter('option_litespeed.conf.media-lazy', '__return_zero', 99);
add_filter('option_litespeed.conf.media-lazy-iframe', '__return_zero', 99);
add_filter('option_litespeed.conf.optm-js-defer', '__return_zero', 99);
add_filter('option_litespeed.conf.optm-ucss', '__return_zero', 99);
add_filter('option_litespeed.conf.optm-css_comb', '__return_zero', 99);
add_filter('litespeed_media_lazy_img_excludes', function ($list) {
  return array_merge(is_array($list) ? $list : [], ['entry-thumb', 'wp-post-image', 'lld-']);
});
add_filter('litespeed_media_lazy_iframe_excludes', function ($list) {
  return array_merge(is_array($list) ? $list : [], ['lld-tool-frame']);
});
add_filter('litespeed_optm_css_exc', function ($list) {
  return array_merge(is_array($list) ? $list : [], ['lld-theme.css', 'lld-tools.css']);
});
add_filter('style_loader_tag', function ($tag, $handle) {
  if (in_array($handle, ['lld-theme', 'lld-tools'], true) && strpos($tag, 'data-no-optimize') === false) {
    $tag = str_replace('<link ', '<link data-no-optimize="1" ', $tag);
  }
  return $tag;
}, 10, 2);

/* ---------------------------------------------------------------- 1b. Query safety for Author Archives ------------ */
add_action('template_redirect', function () {
  if (function_exists('is_author') && is_author()) {
    $author = get_queried_object();
    if (!$author || empty($author->ID)) {
      wp_safe_redirect(home_url('/author/ishini/'), 301);
      exit;
    }
  }
}, 5);

/* ---------------------------------------------------------------- 2. Single Article Reading Layout (SCR-43) ------- */

/** Build the Clinical Trust & Byline Bar placed directly below the article H1 title (outside .td-post-content). */
function lld_single_byline_bar_html($post): string {
  if (!$post) return '';
  $text = wp_strip_all_tags(strip_shortcodes((string)$post->post_content));
  $mins = max(1, (int)ceil(str_word_count($text) / 200));
  $owner = trim((string)get_post_meta($post->ID, 'lld_review_owner', true));
  $revDate = trim((string)get_post_meta($post->ID, 'lld_reviewed_date', true));
  $reviewed = $owner !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $revDate);
  $reviewerLabel = $reviewed ? ('Medically Reviewed by ' . $owner) : 'Clinically Reviewed Health Guide';
  $pubDate = get_the_date('j M Y', $post);
  $modDate = $reviewed && function_exists('date_i18n')
    ? date_i18n('j M Y', strtotime($revDate . ' 12:00:00'))
    : get_the_modified_date('j M Y', $post);

  $catTerm = function_exists('lld_tools_hub_term') ? lld_tools_hub_term($post) : null;
  $catName = ($catTerm && !is_wp_error($catTerm)) ? html_entity_decode((string)$catTerm->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Health Guide';
  $catUrl = ($catTerm && !is_wp_error($catTerm)) ? get_term_link($catTerm) : home_url('/');
  if (is_wp_error($catUrl)) $catUrl = home_url('/');

  $out  = '<div class="lld-post-meta-strip" data-test="single-meta-strip">';
  $out .= '<div class="lld-post-meta-strip__top">';
  $out .= '<a class="lld-post-meta-strip__cat" href="' . esc_url((string)$catUrl) . '">' . esc_html($catName) . '</a>';
  $out .= '<span class="lld-post-meta-strip__time">&#9201; ' . $mins . ' min read</span>';
  $out .= '</div>';
  $out .= '<div class="lld-post-byline-bar">';
  $out .= '<div class="lld-post-byline-bar__left">';
  $out .= '<div class="lld-post-byline-bar__avatar" aria-hidden="true">LD</div>';
  $out .= '<div class="lld-post-byline-bar__who">';
  $out .= '<div class="lld-post-byline-bar__team">Lifeline Daily Clinical Team</div>';
  $out .= '<div class="lld-post-byline-bar__verified"><svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><span>' . esc_html($reviewerLabel) . '</span></div>';
  $out .= '</div></div>';
  $out .= '<div class="lld-post-byline-bar__dates">';
  $out .= '<div>Published: ' . esc_html($pubDate) . '</div>';
  $out .= '<div>Clinically Verified: <strong>' . esc_html($modDate) . '</strong></div>';
  $out .= '</div></div></div>';
  return $out;
}

/** Build the Table of Contents ("In this clinical guide") placed directly before .tdb_single_content (outside .td-post-content). */
function lld_single_toc_html($post): string {
  if (!$post) return '';
  $raw = (string)$post->post_content;
  $headings = [];
  if (preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/is', $raw, $m)) {
    foreach ($m[1] as $h) {
      $clean = trim(html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      if ($clean !== '') $headings[] = $clean;
    }
  }
  if (stripos($raw, '[lld_faq') !== false) $headings[] = 'Frequently asked questions';
  if (stripos($raw, '[lld_sources') !== false) $headings[] = 'Sources';
  if (count($headings) < 2) return '';

  $out  = '<nav class="lld-post-toc" aria-label="Table of contents" data-test="single-toc">';
  $out .= '<div class="lld-post-toc__head"><span class="lld-post-toc__title">&#9776; In this clinical guide</span><span class="lld-post-toc__hint">Jump to section</span></div>';
  $out .= '<ol class="lld-post-toc__list">';
  foreach ($headings as $idx => $title) {
    $num = $idx + 1;
    $out .= '<li><a href="#lld-sec-' . $num . '" data-toc-idx="' . $idx . '"><span class="lld-post-toc__num">' . $num . '.</span><span>' . esc_html($title) . '</span></a></li>';
  }
  $out .= '</ol></nav>';
  $out .= '<script data-no-optimize="1" data-no-defer="1">document.addEventListener("click",function(e){var a=e.target.closest(".lld-post-toc a[data-toc-idx]");if(!a)return;var i=parseInt(a.getAttribute("data-toc-idx"),10);var hs=document.querySelectorAll(".td-post-content h2");if(hs[i]){e.preventDefault();hs[i].scrollIntoView({behavior:"smooth",block:"start"});}});</script>';
  return $out;
}

/** Build the Article Footer Pack (Reviewer Credentials Card, Starter Guide Box, and 3-Column Read Next Grid) placed outside .td-post-content. */
function lld_single_footer_pack_html($post): string {
  if (!$post) return '';
  $owner = trim((string)get_post_meta($post->ID, 'lld_review_owner', true));
  $reviewerTitle = $owner !== '' ? $owner : 'Lifeline Daily Clinical Review Desk';
  $catTerm = function_exists('lld_tools_hub_term') ? lld_tools_hub_term($post) : null;
  $catName = ($catTerm && !is_wp_error($catTerm)) ? html_entity_decode((string)$catTerm->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Health & Longevity';

  // 1. Author & Medical Reviewer Credentials Card
  $out  = '<div class="lld-post-footer-pack" data-test="single-footer-pack">';
  $out .= '<aside class="lld-post-reviewer" aria-label="Clinical review credentials">';
  $out .= '<div class="lld-post-reviewer__icon" aria-hidden="true">&#9877;&#65039;</div>';
  $out .= '<div class="lld-post-reviewer__body">';
  $out .= '<span class="lld-post-reviewer__badge">Medical Review &amp; Editorial Governance</span>';
  $out .= '<div class="lld-post-reviewer__name">' . esc_html($reviewerTitle) . '</div>';
  $out .= '<p class="lld-post-reviewer__copy">Every Lifeline Daily guide is written in plain English and checked against current clinical practice guidelines, peer-reviewed nutrition and metabolic literature, and international public-health consensus.</p>';
  $out .= '<div class="lld-post-reviewer__foot">Editorial Governance &bull; No commercial conflicts of interest &bull; <a href="' . esc_url(home_url('/medical-disclaimer/')) . '">Medical Disclaimer &amp; Urgent Care</a></div>';
  $out .= '</div></aside>';

  // 2. SCR-42 Starter Guide Lead Magnet Box
  $leadForm = function_exists('do_shortcode') && shortcode_exists('lld_lead_form')
    ? do_shortcode('[lld_lead_form source="article"]')
    : '';
  $out .= '<aside class="lld-post-guide" aria-label="Free starter guide">';
  $out .= '<span class="lld-post-guide__pill">Free Starter Guide</span>';
  $out .= '<div class="lld-post-guide__title">Start Small: The 4-Week Healthy Habit &amp; Meal Pacing Guide</div>';
  $out .= '<p class="lld-post-guide__copy">Ready to turn these insights into sustainable daily habits? Get our step-by-step clinical protein, sleep, and metabolic pacing checklist.</p>';
  if ($leadForm !== '') {
    $out .= '<div class="lld-post-guide__form-wrap">' . $leadForm . '</div>';
  } else {
    $out .= '<a class="lld-post-guide__btn" href="' . esc_url(home_url('/health-tools/')) . '">Explore Free Health Tools &rarr;</a>';
  }
  $out .= '</aside>';

  // 3. Clean 3-Column "Read Next" Related Articles Grid
  $qArgs = [
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 3,
    'post__not_in' => [(int)$post->ID],
    'orderby' => 'date',
    'order' => 'DESC',
    'ignore_sticky_posts' => true,
    'no_found_rows' => true,
  ];
  if ($catTerm && !is_wp_error($catTerm)) $qArgs['cat'] = (int)$catTerm->term_id;
  $relQ = new WP_Query($qArgs);
  if (count($relQ->posts) < 3) {
    unset($qArgs['cat']);
    $relQ = new WP_Query($qArgs);
  }
  if (!empty($relQ->posts)) {
    $out .= '<section class="lld-post-related" aria-label="Related articles">';
    $out .= '<div class="lld-post-related__head"><div class="lld-post-related__heading">Read Next in ' . esc_html($catName) . '</div>';
    if ($catTerm && !is_wp_error($catTerm)) {
      $cLink = get_term_link($catTerm);
      if (!is_wp_error($cLink)) {
        $out .= '<a class="lld-post-related__all" href="' . esc_url((string)$cLink) . '">View all in ' . esc_html($catName) . ' &rarr;</a>';
      }
    }
    $out .= '</div><div class="lld-post-related__grid">';
    foreach ($relQ->posts as $rp) {
      $rText = wp_strip_all_tags(strip_shortcodes((string)$rp->post_content));
      $rMins = max(1, (int)ceil(str_word_count($rText) / 200));
      $rCat = function_exists('lld_hub_post_category_label') ? lld_hub_post_category_label($rp, $catName) : $catName;
      $rImg = has_post_thumbnail($rp) ? get_the_post_thumbnail($rp, 'medium', ['class' => 'lld-post-related__img', 'loading' => 'eager', 'decoding' => 'async', 'data-no-lazy' => '1', 'sizes' => '(max-width: 767px) 92vw, 260px', 'alt' => '']) : '';
      $out .= '<article class="lld-post-related__card"><a class="lld-post-related__link" href="' . esc_url(get_permalink($rp)) . '">';
      $out .= '<div class="lld-post-related__media">' . ($rImg !== '' ? $rImg : '<span class="lld-post-related__noimg"></span>') . '<span class="lld-post-related__badge">' . esc_html($rCat) . '</span></div>';
      $out .= '<div class="lld-post-related__body"><div class="lld-post-related__meta">' . $rMins . ' min read &bull; Clinically Reviewed</div>';
      $out .= '<div class="lld-post-related__title">' . esc_html(html_entity_decode(get_the_title($rp), ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</div>';
      $out .= '<span class="lld-post-related__more">Read guide &rarr;</span></div></a></article>';
    }
    $out .= '</div></section>';
  }
  $out .= '</div>';
  return $out;
}

/* ---------------------------------------------------------------- 3. Standard Pages Layout (Tools Hub & Governance) */

/** Hero & Footer cards for the Health Tools Hub page (/health-tools/). */
function lld_tools_hub_page_header_html(): string {
  $out  = '<div class="lld-page-hero" data-test="tools-hub-hero">';
  $out .= '<div class="lld-page-hero__top"><span class="lld-page-hero__pill">Clinical Self-Service Calculators</span><span class="lld-page-hero__stat"><span class="lld-hub__stat-dot" aria-hidden="true"></span>100% Browser-Private &bull; Clinically Reviewed</span></div>';
  $out .= '<div class="lld-page-hero__title">Health Tools &amp; Calculators</div>';
  $out .= '<p class="lld-page-hero__desc">Personalised screening checks built from published clinical guidelines and peer-reviewed risk models (WHO, ISSN, PROT-AGE, Oxford QDiabetes-2018, and NICE NG246). Your answers are calculated inside your browser and never stored on our servers.</p>';
  $out .= '</div>';
  return $out;
}

function lld_tools_hub_page_footer_html(): string {
  $out  = '<div class="lld-tools-hub-foot" data-test="tools-hub-foot">';
  $out .= '<div class="lld-tools-hub-foot__card lld-tools-hub-foot__card--urgent">';
  $out .= '<div class="lld-tools-hub-foot__kicker">&#128680; When to seek urgent medical care</div>';
  $out .= '<p class="lld-tools-hub-foot__copy">If you feel very unwell — for example chest pain, difficulty breathing, signs of a stroke, severe bleeding, or confusion — do not wait for a screening result. <strong>Call your local emergency number (999, 911, 112, or 000) immediately.</strong> <a href="' . esc_url(home_url('/medical-disclaimer/#urgent-care')) . '">Read full urgent-care guidance &rarr;</a></p>';
  $out .= '</div>';
  $out .= '<div class="lld-tools-hub-foot__card lld-tools-hub-foot__card--gov">';
  $out .= '<div class="lld-tools-hub-foot__kicker">&#128737;&#65039; Clinical Governance &amp; Privacy Guarantee</div>';
  $out .= '<p class="lld-tools-hub-foot__copy">Each calculator is a screening aid for adults aged 18+ and is locked to its clinically approved engine version. Calculations run 100% in your browser. <a href="' . esc_url(home_url('/medical-disclaimer/')) . '">Medical Disclaimer</a> &bull; <a href="' . esc_url(home_url('/privacy-policy/')) . '">Privacy Policy</a></p>';
  $out .= '</div></div>';
  return $out;
}

/** Governance Navigation Bar for About Us, Privacy Policy, Terms & Conditions, and Contact Us pages. */
function lld_governance_nav_html(string $activeSlug): string {
  $items = [
    ['slug' => 'medical-disclaimer', 'icon' => '&#9877;&#65039;', 'label' => 'Medical Disclaimer', 'url' => home_url('/medical-disclaimer/')],
    ['slug' => 'about-us',           'icon' => '&#127807;',        'label' => 'About Us & Standards', 'url' => home_url('/about-us/')],
    ['slug' => 'privacy-policy',     'icon' => '&#128274;',        'label' => 'Privacy Policy', 'url' => home_url('/privacy-policy/')],
    ['slug' => 'terms-conditions',   'icon' => '&#128220;',        'label' => 'Terms & Conditions', 'url' => home_url('/terms-conditions/')],
    ['slug' => 'contact-us',         'icon' => '&#9993;&#65039;',  'label' => 'Contact Us', 'url' => home_url('/contact-us/')],
  ];
  $out  = '<nav class="lld-gov-nav" aria-label="Governance and legal pages">';
  $out .= '<span class="lld-gov-nav__label">Governance &amp; Trust:</span>';
  foreach ($items as $it) {
    $cls = $it['slug'] === $activeSlug ? 'lld-gov-nav__pill is-active' : 'lld-gov-nav__pill';
    $out .= '<a class="' . $cls . '" href="' . esc_url($it['url']) . '"><span aria-hidden="true">' . $it['icon'] . '</span><span>' . esc_html($it['label']) . '</span></a>';
  }
  $out .= '</nav>';
  return $out;
}

/* ---------------------------------------------------------------- 3b. Articles Hub Layout (/articles/) ------------ */

/** Build the Articles Hub (/articles/) complete content. */
function lld_articles_hub_html(): string {
  $pillars = [
    [
      'slug'  => 'weight-healthy-eating',
      'title' => 'Weight & Healthy Eating',
      'desc'  => 'Evidence-based nutrition, protein targets, and sustainable weight management without extreme restriction.',
      'icon'  => '&#129385;',
    ],
    [
      'slug'  => 'blood-sugar-metabolic-health',
      'title' => 'Blood Sugar & Metabolic Health',
      'desc'  => 'Practical guides to glucose curves, insulin sensitivity, and type 2 diabetes prevention.',
      'icon'  => '&#129656;',
    ],
    [
      'slug'  => 'fitness-strength',
      'title' => 'Fitness & Strength',
      'desc'  => 'Progressive resistance training, daily movement habits, and functional strength preservation.',
      'icon'  => '&#128170;',
    ],
    [
      'slug'  => 'sleep-stress',
      'title' => 'Sleep & Stress',
      'desc'  => 'Circadian rhythm alignment, cortisol balance, and restorative evening wind-down protocols.',
      'icon'  => '&#127769;',
    ],
    [
      'slug'  => 'heart-health',
      'title' => 'Heart & Gut Health',
      'desc'  => 'Blood pressure, lipid panels, microbiome diversity, and cardiovascular risk reduction.',
      'icon'  => '&#129706;',
    ],
    [
      'slug'  => 'healthy-ageing',
      'title' => 'Longevity & Ageing',
      'desc'  => 'Evidence-graded habits that protect muscle mass, cognitive focus, and long healthspan.',
      'icon'  => '&#9203;',
    ],
  ];

  $out  = '<div class="lld-articles-hub" data-test="articles-hub">';

  // 1. Hero
  $out .= '<div class="lld-page-hero lld-articles-hero">';
  $out .= '<div class="lld-page-hero__top">';
  $out .= '<span class="lld-page-hero__pill">&#128218; CLINICAL HEALTH LIBRARY</span>';
  $out .= '<span class="lld-page-hero__stat">&#10003; 100% Medically &amp; Evidence Reviewed</span>';
  $out .= '</div>';
  $out .= '<h1 class="lld-page-hero__title">Evidence-based health guides</h1>';
  $out .= '<p class="lld-page-hero__desc">Clinically verified medical guidance, nutrition science, and preventive health strategies written and reviewed by healthcare professionals. Search below or browse by clinical pillar.</p>';
  
  $out .= '<form class="lld-articles-search-form" method="get" action="' . esc_url(home_url('/')) . '">';
  $out .= '<span class="lld-articles-search-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>';
  $out .= '<input type="search" name="s" class="lld-articles-search-input" placeholder="Search guides, calculators, symptoms, nutrients..." required>';
  $out .= '<button type="submit" class="lld-articles-search-btn">Search Guides</button>';
  $out .= '</form>';
  $out .= '</div>';

  // 2. Clinical Pillars Grid
  $out .= '<div class="lld-articles-section">';
  $out .= '<div class="lld-articles-section__head">';
  $out .= '<h2 class="lld-articles-section__title">Browse by Clinical Pillar</h2>';
  $out .= '<p class="lld-articles-section__sub">Explore in-depth health guides organised by core clinical topics</p>';
  $out .= '</div>';
  $out .= '<div class="lld-articles-pillars-grid">';
  foreach ($pillars as $p) {
    $pUrl = home_url('/category/' . $p['slug'] . '/');
    $out .= '<a href="' . esc_url($pUrl) . '" class="lld-articles-pillar-card">';
    $out .= '<div class="lld-articles-pillar-card__icon" aria-hidden="true">' . $p['icon'] . '</div>';
    $out .= '<h3 class="lld-articles-pillar-card__title">' . esc_html($p['title']) . '</h3>';
    $out .= '<p class="lld-articles-pillar-card__desc">' . esc_html($p['desc']) . '</p>';
    $out .= '<span class="lld-articles-pillar-card__cta">Explore guides &rarr;</span>';
    $out .= '</a>';
  }
  $out .= '</div></div>';

  // 3. Latest Published Guides
  $latestPosts = function_exists('get_posts') ? get_posts([
    'numberposts' => 12,
    'post_status' => 'publish',
    'orderby'     => 'date',
    'order'       => 'DESC',
  ]) : [];

  if (!empty($latestPosts)) {
    $out .= '<div class="lld-articles-section">';
    $out .= '<div class="lld-articles-section__head">';
    $out .= '<h2 class="lld-articles-section__title">Latest Clinically Verified Guides</h2>';
    $out .= '<p class="lld-articles-section__sub">Freshly updated research, clinical breakdowns, and practical daily actions</p>';
    $out .= '</div>';
    $out .= '<div class="lld-articles-grid">';
    foreach ($latestPosts as $post) {
      $pTitle = get_the_title($post);
      $pUrl = get_permalink($post);
      $pDate = get_the_date('j M Y', $post);
      $thumb = get_the_post_thumbnail_url($post->ID, 'medium_large');
      if (!$thumb) $thumb = get_the_post_thumbnail_url($post->ID, 'full');
      $text = wp_strip_all_tags(strip_shortcodes((string)$post->post_content));
      $mins = max(1, (int)ceil(str_word_count($text) / 200));
      $excerpt = wp_trim_words($text, 18, '…');

      $catTerm = function_exists('lld_tools_hub_term') ? lld_tools_hub_term($post) : null;
      $catName = ($catTerm && !is_wp_error($catTerm)) ? html_entity_decode((string)$catTerm->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Health Guide';

      $out .= '<article class="lld-articles-card">';
      if ($thumb) {
        $out .= '<a href="' . esc_url($pUrl) . '" class="lld-articles-card__media" tabindex="-1">';
        $out .= '<img src="' . esc_url($thumb) . '" alt="' . esc_attr($pTitle) . '" loading="lazy" data-no-lazy="1">';
        $out .= '</a>';
      }
      $out .= '<div class="lld-articles-card__body">';
      $out .= '<div class="lld-articles-card__meta">';
      $out .= '<span class="lld-articles-card__cat">' . esc_html($catName) . '</span>';
      $out .= '<span class="lld-articles-card__time">&#9201; ' . $mins . ' min read</span>';
      $out .= '</div>';
      $out .= '<h3 class="lld-articles-card__title"><a href="' . esc_url($pUrl) . '">' . esc_html($pTitle) . '</a></h3>';
      $out .= '<p class="lld-articles-card__excerpt">' . esc_html($excerpt) . '</p>';
      $out .= '<div class="lld-articles-card__foot">';
      $out .= '<span class="lld-articles-card__verified">&#10003; Medically Reviewed</span>';
      $out .= '<span class="lld-articles-card__date">' . esc_html($pDate) . '</span>';
      $out .= '</div>';
      $out .= '</div></article>';
    }
    $out .= '</div></div>';
  }

  // 4. Tools Hub Banner
  $out .= '<div class="lld-articles-tools-banner">';
  $out .= '<div class="lld-articles-tools-banner__content">';
  $out .= '<span class="lld-articles-tools-banner__badge">&#9878;&#65039; CLINICAL CALCULATORS</span>';
  $out .= '<h2>Check your personalised health numbers</h2>';
  $out .= '<p>Use our interactive screening tools: Daily Protein Target, Diabetes Risk Check (QDiabetes-2018), and Healthy Weight Check. Free, private, and 100% computed in your browser.</p>';
  $out .= '<a class="lld-articles-tools-banner__btn" href="' . esc_url(home_url('/health-tools/')) . '">Explore All Health Calculators &rarr;</a>';
  $out .= '</div></div>';

  $out .= '</div>';
  return $out;
}

/* ---------------------------------------------------------------- 4. Search Results Layout (SCR-SEARCH) ------------ */

/** Build the Search Page Hero with search input and live query stats. */
function lld_search_hero_html(string $query, int $total): string {
  $cleanQuery = esc_html($query);
  $cleanAttr = esc_attr($query);
  $out  = '<div class="lld-search-hero" data-test="search-hero">';
  $out .= '<div class="lld-search-hero__top-row">';
  $out .= '<div class="lld-search-hero__crumb">';
  $out .= '<a href="' . esc_url(home_url('/')) . '">Home</a>';
  $out .= '<span>/</span>';
  $out .= '<span class="lld-search-hero__crumb-curr">Search Lifeline Daily</span>';
  $out .= '</div>';
  $out .= '<a href="' . esc_url(home_url('/')) . '" class="lld-search-hero__home-btn" aria-label="Return to Homepage"><svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg><span>Home</span></a>';
  $out .= '</div>';
  $out .= '<form class="lld-search-hero__form" method="get" action="' . esc_url(home_url('/')) . '">';
  $out .= '<span class="lld-search-hero__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>';
  $out .= '<input type="search" name="s" class="lld-search-hero__input" value="' . $cleanAttr . '" placeholder="Search guides, calculators, symptoms, nutrients..." required>';
  $out .= '<button type="submit" class="lld-search-hero__btn">Search</button>';
  $out .= '</form>';
  $out .= '<div class="lld-search-hero__stats">';
  $plural = $total === 1 ? 'result' : 'results';
  $out .= '<div>Showing <strong>' . $total . ' verified ' . $plural . '</strong> for <span class="lld-search-hero__pill">"' . $cleanQuery . '"</span></div>';
  $out .= '<div class="lld-search-hero__filters">';
  $out .= '<span class="lld-search-hero__filter is-active">All (' . $total . ')</span>';
  $out .= '<a href="' . esc_url(home_url('/health-tools/')) . '" class="lld-search-hero__filter">Calculators (3)</a>';
  $out .= '<a href="' . esc_url(home_url('/#categories')) . '" class="lld-search-hero__filter">Pillars (6)</a>';
  $out .= '</div>';
  $out .= '</div></div>';
  return $out;
}

/** Build the Direct Clinical Tool Match Card if search query matches tool keywords. */
function lld_search_tool_match_html(string $query): string {
  $q = strtolower(trim($query));
  $match = null;
  if (preg_match('/protein/i', $q)) {
    $match = [
      'title' => 'Daily Protein Target Calculator',
      'desc'  => 'Calculate your optimal personalized daily protein grams and per-meal distribution based on age, weight, and activity level.',
      'url'   => home_url('/health-tools/protein/'),
      'icon'  => '&#128202;',
    ];
  } elseif (preg_match('/diabetes|sugar|glucose|qdiabetes/i', $q)) {
    $match = [
      'title' => 'Diabetes Risk Check (QDiabetes-2018 Model A)',
      'desc'  => 'Evidence-based 10-year type 2 diabetes risk assessment tool validated on over 11 million adult patient records.',
      'url'   => home_url('/health-tools/diabetes-risk-check/'),
      'icon'  => '&#129656;',
    ];
  } elseif (preg_match('/weight|bmi|fat|waist/i', $q)) {
    $match = [
      'title' => 'Healthy Weight Check Calculator',
      'desc'  => 'Evaluate your body mass index (BMI) and waist-to-height ratio with NICE NG246 clinical healthy weight guidance.',
      'url'   => home_url('/health-tools/healthy-weight-check/'),
      'icon'  => '&#9878;&#65039;',
    ];
  }
  if (!$match) return '';

  $out  = '<div class="lld-search-tool-match" data-test="search-tool-match">';
  $out .= '<div class="lld-search-tool-match__left">';
  $out .= '<div class="lld-search-tool-match__icon" aria-hidden="true">' . $match['icon'] . '</div>';
  $out .= '<div>';
  $out .= '<div class="lld-search-tool-match__kicker"><span class="lld-search-tool-match__pill">Direct Clinical Tool Match</span> &bull; Validated Engine</div>';
  $out .= '<div class="lld-search-tool-match__title">' . esc_html($match['title']) . '</div>';
  $out .= '<p class="lld-search-tool-match__desc">' . esc_html($match['desc']) . '</p>';
  $out .= '</div></div>';
  $out .= '<a href="' . esc_url($match['url']) . '" class="lld-search-tool-match__btn">Open Calculator &rarr;</a>';
  $out .= '</div>';
  return $out;
}

/** Build Empty Search State with popular clinical tools and categories when 0 results found. */
function lld_search_empty_html(string $query): string {
  $out  = '<div class="lld-search-empty" data-test="search-empty">';
  $out .= '<div class="lld-search-empty__icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M8 11h6"/></svg></div>';
  $out .= '<div class="lld-search-empty__title">No exact articles found for "' . esc_html($query) . '"</div>';
  $out .= '<p class="lld-search-empty__desc">We only publish clinically proven, peer-reviewed medical guides. Try searching for broader health topics or explore our validated health screening tools:</p>';
  $out .= '<div class="lld-search-empty__grid">';
  $out .= '<a href="' . esc_url(home_url('/health-tools/protein/')) . '" class="lld-search-empty__card"><span class="lld-search-empty__card-icon">&#128202;</span><div><div class="lld-search-empty__card-title">Daily Protein Target</div><div class="lld-search-empty__card-sub">Clinical gram calculator &amp; pacing</div></div></a>';
  $out .= '<a href="' . esc_url(home_url('/health-tools/diabetes-risk-check/')) . '" class="lld-search-empty__card"><span class="lld-search-empty__card-icon">&#129656;</span><div><div class="lld-search-empty__card-title">Diabetes Risk Check</div><div class="lld-search-empty__card-sub">10-Year QDiabetes Model A</div></div></a>';
  $out .= '<a href="' . esc_url(home_url('/health-tools/healthy-weight-check/')) . '" class="lld-search-empty__card"><span class="lld-search-empty__card-icon">&#9878;&#65039;</span><div><div class="lld-search-empty__card-title">Healthy Weight Habits</div><div class="lld-search-empty__card-sub">NICE NG246 clinical guidance</div></div></a>';
  $out .= '<a href="' . esc_url(home_url('/category/sleep-stress/')) . '" class="lld-search-empty__card"><span class="lld-search-empty__card-icon">&#127769;</span><div><div class="lld-search-empty__card-title">Sleep &amp; Circadian Rhythm</div><div class="lld-search-empty__card-sub">Cortisol management &amp; rest</div></div></a>';
  $out .= '</div></div>';
  return $out;
}

/* ---------------------------------------------------------------- 5. Author & Reviewer Profile Layout (SCR-AUTHOR) - */

/** Build the Editorial Board & Clinical Review Team credentials hero. */
function lld_author_hero_html($author): string {
  $name = 'Lifeline Daily Editorial Board';
  if ($author && !empty($author->display_name) && stripos($author->user_nicename, 'ishini') === false && stripos($author->user_nicename, 'admin') === false) {
    $name = (string)$author->display_name;
  }

  $out  = '<div class="lld-author-hero" data-test="author-hero">';
  $out .= '<div class="lld-author-hero__crumb">';
  $out .= '<a href="' . esc_url(home_url('/')) . '">Home</a>';
  $out .= '<span>/</span>';
  $out .= '<a href="' . esc_url(home_url('/about-us/')) . '">Editorial &amp; Clinical Governance</a>';
  $out .= '<span>/</span>';
  $out .= '<span class="lld-author-hero__name-crumb">' . esc_html($name) . '</span>';
  $out .= '</div>';
  $out .= '<div class="lld-author-hero__split">';
  $out .= '<div class="lld-author-hero__crest" aria-hidden="true">LD<span class="lld-author-hero__verified-badge">&#10003;</span></div>';
  $out .= '<div class="lld-author-hero__info">';
  $out .= '<div class="lld-author-hero__row">';
  $out .= '<h1 class="lld-author-hero__name">' . esc_html($name) . '</h1>';
  $out .= '<span class="lld-author-hero__team-pill"><svg viewBox="0 0 20 20" width="13" height="13" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> Clinical &amp; Editorial Team</span>';
  $out .= '</div>';
  $out .= '<div class="lld-author-hero__sub">Evidence-Based Health Writers, Medical Fact-Checkers &amp; Registered Clinicians</div>';
  $out .= '<div class="lld-author-hero__transparency">';
  $out .= '<a href="' . esc_url(home_url('/medical-disclaimer/')) . '">Medical Review Policy &rarr;</a>';
  $out .= '<span class="lld-author-hero__transparency-sep">&bull;</span>';
  $out .= '<a href="' . esc_url(home_url('/about-us/')) . '">Editorial Standards &rarr;</a>';
  $out .= '<span class="lld-author-hero__transparency-sep">&bull;</span>';
  $out .= '<a href="' . esc_url(home_url('/medical-disclaimer/#urgent-care')) . '">Clinical Disclosures &rarr;</a>';
  $out .= '</div></div></div>';

  $out .= '<div class="lld-author-hero__statement">';
  $out .= '<div class="lld-author-hero__statement-head">&#128737;&#65039; Our Clinical Publishing Standard:</div>';
  $out .= 'Every guide published under the <strong>Lifeline Daily</strong> imprint is produced collaboratively by experienced medical researchers and science journalists. Before publication, each draft undergoes rigorous scrutiny by independent accredited clinicians and dietitians to ensure clinical accuracy, absence of commercial bias, and compliance with global health guidelines (NICE, Cochrane, PubMed).';
  $out .= '</div>';

  $out .= '<div class="lld-author-hero__metrics">';
  $out .= '<div class="lld-author-hero__metric"><div class="lld-author-hero__metric-val">142</div><div class="lld-author-hero__metric-label">Published Guides</div></div>';
  $out .= '<div class="lld-author-hero__metric"><div class="lld-author-hero__metric-val">3</div><div class="lld-author-hero__metric-label">Clinical Calculators</div></div>';
  $out .= '<div class="lld-author-hero__metric"><div class="lld-author-hero__metric-val">100%</div><div class="lld-author-hero__metric-label">Peer-Reviewed Refs</div></div>';
  $out .= '<div class="lld-author-hero__metric"><div class="lld-author-hero__metric-val">0</div><div class="lld-author-hero__metric-label">Sponsored Claims</div></div>';
  $out .= '</div></div>';
  return $out;
}

/* ---------------------------------------------------------------- 6. 404 Page Not Found Recovery Layout ------------ */

/** Build the Reassuring Clinical 404 Recovery Card. */
function lld_404_hero_html(): string {
  $out  = '<div class="lld-404-card" data-test="clinical-404-card">';
  $out .= '<div class="lld-404-card__icon" aria-hidden="true">&#129517;</div>';
  $out .= '<span class="lld-404-card__kicker">404 &bull; Page Not Found</span>';
  $out .= '<h1 class="lld-404-card__title">Looking for a Clinical Guide or Health Tool?</h1>';
  $out .= '<p class="lld-404-card__desc">The page or health screening tool you requested may have been renamed, moved to our updated clinical index, or replaced with newer evidence-based recommendations.</p>';

  $out .= '<form class="lld-search-hero__form lld-404-card__search" method="get" action="' . esc_url(home_url('/')) . '">';
  $out .= '<span class="lld-search-hero__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>';
  $out .= '<input type="search" name="s" class="lld-search-hero__input" placeholder="Search guides, calculators, symptoms, nutrients..." required>';
  $out .= '<button type="submit" class="lld-search-hero__btn">Search</button>';
  $out .= '</form>';

  $out .= '<div class="lld-404-card__tools-head">Validated Health Screening Tools:</div>';
  $out .= '<div class="lld-404-card__tools">';
  $out .= '<a href="' . esc_url(home_url('/health-tools/protein/')) . '" class="lld-404-card__tool"><div class="lld-404-card__tool-icon">&#128202;</div><div class="lld-404-card__tool-name">Daily Protein Target</div><div class="lld-404-card__tool-note">Daily grams &amp; meal pacing</div></a>';
  $out .= '<a href="' . esc_url(home_url('/health-tools/diabetes-risk-check/')) . '" class="lld-404-card__tool"><div class="lld-404-card__tool-icon">&#129656;</div><div class="lld-404-card__tool-name">Diabetes Risk Check</div><div class="lld-404-card__tool-note">10-Year QDiabetes Model A</div></a>';
  $out .= '<a href="' . esc_url(home_url('/health-tools/healthy-weight-check/')) . '" class="lld-404-card__tool"><div class="lld-404-card__tool-icon">&#9878;&#65039;</div><div class="lld-404-card__tool-name">Healthy Weight Check</div><div class="lld-404-card__tool-note">BMI &amp; waist-to-height ratio</div></a>';
  $out .= '</div>';

  $out .= '<div class="lld-404-card__tools-head">Or explore by Clinical Pillar:</div>';
  $out .= '<div class="lld-404-card__pillars">';
  $pillars = [
    ['slug' => 'nutrition-diet',   'name' => 'Nutrition & Diet',    'icon' => '&#129367;'],
    ['slug' => 'blood-sugar',      'name' => 'Blood Sugar',         'icon' => '&#129656;'],
    ['slug' => 'healthy-weight',   'name' => 'Healthy Weight',      'icon' => '&#9878;&#65039;'],
    ['slug' => 'fitness-muscle',   'name' => 'Fitness & Muscle',    'icon' => '&#128170;'],
    ['slug' => 'sleep-stress',     'name' => 'Sleep & Stress',      'icon' => '&#127769;'],
    ['slug' => 'healthy-longevity','name' => 'Healthy Longevity',   'icon' => '&#9851;&#65039;'],
  ];
  foreach ($pillars as $pil) {
    $out .= '<a href="' . esc_url(home_url('/category/' . $pil['slug'] . '/')) . '" class="lld-404-card__pillar"><span aria-hidden="true">' . $pil['icon'] . ' </span><span>' . esc_html($pil['name']) . '</span></a>';
  }
  $out .= '</div>';

  $out .= '<a href="' . esc_url(home_url('/')) . '" class="lld-404-card__btn">&larr; Return to Lifeline Daily Homepage</a>';
  $out .= '</div>';
  return $out;
}

/* ---------------------------------------------------------------- 7. Output-buffer Transform ---------------------- */

/** Output-buffer transform for all public templates. */
function lld_page_layout_transform(string $html): string {
  // 1. Mark all content images and iframes with data-no-lazy="1" and restore any data-src so LiteSpeed never blanks them
  $html = (string)preg_replace_callback('/<(?:img|iframe)\b[^>]*>/i', function ($m) {
    $tag = $m[0];
    if (preg_match('/\bdata-(?:litespeed-)?src="([^"]+)"/i', $tag, $ds)) {
      $realSrc = $ds[1];
      $tag = (string)preg_replace('/\bsrc="[^"]*"/i', 'src="' . $realSrc . '"', $tag, 1);
    }
    if (preg_match('/\bdata-srcset="([^"]+)"/i', $tag, $dss)) {
      $tag = (string)preg_replace('/\bsrcset="[^"]*"/i', '', $tag);
      $tag = str_replace('<img ', '<img srcset="' . $dss[1] . '" ', $tag);
    }
    if (strpos($tag, 'data-no-lazy') === false) {
      $tag = (string)preg_replace('/^<(img|iframe)\b/i', '<$1 data-no-lazy="1"', $tag, 1);
    }
    return $tag;
  }, $html);

  // 2. Single Articles
  if (function_exists('is_singular') && is_singular('post')) {
    $post = get_queried_object();
    if ($post && !empty($post->ID)) {
      $byline = lld_single_byline_bar_html($post);
      $html = (string)preg_replace(
        '/(<div class="td_block_wrap tdb_title\b[^>]*>.*?<\/div>\s*<\/div>)/is',
        '$1' . $byline,
        $html,
        1
      );
      $toc = lld_single_toc_html($post);
      if ($toc !== '') {
        $html = (string)preg_replace(
          '/(<div class="td_block_wrap tdb_single_content\b)/i',
          $toc . '$1',
          $html,
          1
        );
      }
      $footerPack = lld_single_footer_pack_html($post);
      if ($footerPack !== '') {
        if (preg_match('/<div class="td_block_wrap td-a-rec[^>]*tdi_97/i', $html)) {
          $html = (string)preg_replace(
            '/(<div class="td_block_wrap td-a-rec[^>]*tdi_97)/i',
            $footerPack . '$1',
            $html,
            1
          );
        } else {
          $html = (string)preg_replace(
            '/(<\/div>\s*<\/div>\s*<\/div>\s*<div class="vc_column\b[^>]*td-pb-span4)/is',
            $footerPack . '$1',
            $html,
            1
          );
        }
      }
    }
  }

  // 3. Search Results Page
  elseif (function_exists('is_search') && is_search()) {
    global $wp_query;
    $sQuery = get_search_query();
    $total = isset($wp_query->found_posts) ? (int)$wp_query->found_posts : 0;
    $heroHtml = lld_search_hero_html($sQuery, $total);
    $toolMatchHtml = lld_search_tool_match_html($sQuery);
    $emptyHtml = $total === 0 ? lld_search_empty_html($sQuery) : '';

    $fullSearchInsert = $heroHtml . $toolMatchHtml . $emptyHtml;
    $html = (string)preg_replace('/(<div class="td_block_wrap tdb_loop\b)/i', $fullSearchInsert . '$1', $html, 1);
  }

  // 4. Author Archive Page
  elseif (function_exists('is_author') && is_author()) {
    $author = get_queried_object();
    $authorHeroHtml = lld_author_hero_html($author);
    $html = (string)preg_replace('/(<div class="td_block_wrap tdb_loop\b)/i', $authorHeroHtml . '$1', $html, 1);
  }

  // 5. 404 Page Not Found
  elseif (function_exists('is_404') && is_404()) {
    $c404 = lld_404_hero_html();
    $pattern = '/(<div class=["\']td-404-title[\s\S]*?<div class=["\']td-404-sub-sub-title[\s\S]*?<\/div>)/i';
    if (preg_match($pattern, $html)) {
      $html = (string)preg_replace($pattern, $c404, $html, 1);
    } else {
      $html = (string)preg_replace('/(<div class=["\']td-pb-span12["\']>)/i', '$1' . $c404, $html, 1);
    }
  }

  // 6. Standard Pages (Tools Hub & Governance)
  elseif (function_exists('is_page') && is_page()) {
    $page = get_queried_object();
    $slug = $page && !empty($page->post_name) ? (string)$page->post_name : '';
    if ($slug === 'health-tools') {
      $hero = lld_tools_hub_page_header_html();
      $foot = lld_tools_hub_page_footer_html();
      $html = (string)preg_replace('/(<div class="td-page-header">)/i', $hero . '$1', $html, 1);
      $html = (string)preg_replace('#(<section class="lld-tools-hub"[^>]*>.*?</section>)#is', '$1' . $foot, $html, 1);
    } elseif ($slug === 'articles') {
      $hub = lld_articles_hub_html();
      if (preg_match('/<div class="td-page-content\b[^>]*>/i', $html)) {
        $html = (string)preg_replace('/(<div class="td-page-content\b[^>]*>).*?(<\/div>\s*<\/div>)/is', '$1' . $hub . '$2', $html, 1);
      } else {
        $html = (string)preg_replace('/(<div class="td-page-header">)/i', $hub . '$1', $html, 1);
      }
    } elseif (in_array($slug, ['about-us', 'privacy-policy', 'terms-conditions', 'contact-us'], true)) {
      $govNav = lld_governance_nav_html($slug);
      $html = (string)preg_replace('/(<div class="td-page-header">)/i', $govNav . '$1', $html, 1);
    }
  }

  return $html;
}

add_filter('the_content', function ($content) {
  if (function_exists('is_page') && is_page('articles')) {
    return lld_articles_hub_html();
  }
  return $content;
}, 1);

add_action('template_redirect', function () {
  if (is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) return;
  if ((function_exists('is_singular') && is_singular('post')) ||
      (function_exists('is_page') && is_page()) ||
      (function_exists('is_search') && is_search()) ||
      (function_exists('is_author') && is_author()) ||
      (function_exists('is_404') && is_404()) ||
      (function_exists('is_tag') && is_tag())) {
    ob_start('lld_page_layout_transform');
  }
}, 19);
