<?php
/**
 * Interface Pack Homepage (S2-L1 / FR1-03; prototype: lifeline_daily_ui_demo.html, owner-approved 28 Oct 2026).
 * Renders the 5-section responsive homepage over the Newspaper front-page template while keeping `lld_tools_home_content()`
 * and the database `post_content` byte-compatible with `wp lld home apply | revert` and TC-FR1-03:
 *   1. Split Hero (`[lld_home_hero]` inside `.lld-home-hero`, desktop 2-column mission + search + flagship article card;
 *      hidden at <=767px per DEC-23 so mobile visitors reach the calculators first).
 *   2. Clinical Health Calculators (`[lld_tools_hub compact=1]` inside `.lld-home-tools-row`, 3 columns desktop / 1 mobile).
 *   3. Stage 2 4-Week Programmes (`[lld_home_tracks]` inside `.lld-home-tracks-row`, 4 columns desktop / 1 mobile + Premium CTA).
 *   4. Curated Articles (`[lld_learn_row limit=6]` inside `.lld-home-learn-row`, 3 columns desktop / 1 mobile).
 *   5. Starter Guide Signup (`[lld_home_guide]` inside `.lld-home-guide-row`, SCR-42 weekly habit newsletter card).
 */
if (!defined('ABSPATH')) exit;

/** Resolve a Stage 2 member/programme path when `lld-members` is active, or fall back to `/health-tools/` in Stage 1-only harnesses. */
function lld_home_member_url(string $path): string {
  return home_url(class_exists('LLD_Members_Plugin') ? $path : '/health-tools/');
}

/** Category badge label for a post on the homepage (Yoast primary or top category, else first category, else 'Health Guide'). */
function lld_home_post_category_label($p): string {
  if (!$p || !function_exists('wp_get_post_terms') || !function_exists('lld_tools_hub_term')) return 'Health Guide';
  $t = lld_tools_hub_term($p);
  if ($t && !empty($t->name)) return html_entity_decode((string)$t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  if (function_exists('get_the_category')) {
    $cats = get_the_category($p->ID);
    if (!empty($cats[0]->name)) return html_entity_decode((string)$cats[0]->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
  return 'Health Guide';
}

/** Reading time in minutes for a post (200 wpm, minimum 3 min for feature guides when content is short in stubs). */
function lld_home_post_minutes($p): int {
  if (!$p || empty($p->post_content)) return 5;
  $raw = function_exists('strip_shortcodes') ? strip_shortcodes((string)$p->post_content) : (string)$p->post_content;
  $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($raw) : strip_tags($raw);
  $words = str_word_count($text);
  return $words > 0 ? max(3, (int)ceil($words / 200)) : 5;
}

/** Short 2-line excerpt for a post card on the homepage. */
function lld_home_post_excerpt($p, int $words = 22): string {
  if (!$p || !function_exists('wp_trim_words')) return '';
  $raw = (function_exists('has_excerpt') && has_excerpt($p) && !empty($p->post_excerpt))
    ? (string)$p->post_excerpt
    : (function_exists('strip_shortcodes') ? strip_shortcodes((string)($p->post_content ?? '')) : (string)($p->post_content ?? ''));
  $text = trim(wp_strip_all_tags($raw));
  if ($text === '') return '';
  return html_entity_decode(wp_trim_words($text, $words, '…'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Meta line for a Learn article card: "{n} min read • Medically Reviewed" or "Evidence Reviewed". */
function lld_home_post_meta_label($p): string {
  $mins = lld_home_post_minutes($p);
  $rev = (function_exists('lld_tools_review_byline') && $p && !empty($p->post_content) && lld_tools_review_byline($p) !== '') ? 'Medically Reviewed' : 'Evidence Reviewed';
  return $mins . ' min read • ' . $rev;
}

/** Pick the flagship post for the Hero right-hand featured story card (flagship protein guide 6341 when present, else newest reviewed). */
function lld_home_pick_hero_post() {
  if (function_exists('get_post') && function_exists('has_post_thumbnail')) {
    $flag = get_post(6341);
    if ($flag && ($flag->post_status ?? '') === 'publish' && has_post_thumbnail($flag)) return $flag;
  }
  if (!class_exists('WP_Query')) return null;
  $q = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 8,
    'orderby' => 'date',
    'order' => 'DESC',
    'ignore_sticky_posts' => true,
    'no_found_rows' => true,
  ]);
  if (empty($q->posts)) return null;
  foreach ($q->posts as $p) {
    if (function_exists('has_post_thumbnail') && has_post_thumbnail($p) && function_exists('lld_tools_review_byline') && lld_tools_review_byline($p) !== '') {
      return $p;
    }
  }
  foreach ($q->posts as $p) {
    if (function_exists('has_post_thumbnail') && has_post_thumbnail($p)) return $p;
  }
  return $q->posts[0];
}

/** [lld_home_hero]: Section 1 — 2-Column Split Hero (Mission + Search + Popular Pills on left, Flagship Story Card on right). */
function lld_home_render_hero(): string {
  $p = lld_home_pick_hero_post();
  $postUrl = $p ? get_permalink($p) : home_url('/health-tools/');
  $title = $p ? html_entity_decode(get_the_title($p), ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'The High-Protein Foods Worth Eating More Of (And Why They Work)';
  $excerpt = $p ? lld_home_post_excerpt($p, 26) : '';
  if ($excerpt === '') {
    $excerpt = 'How optimising your daily protein distribution protects muscle mass, supports metabolic health, and helps steady blood sugar levels without extreme dieting.';
  }
  $catName = $p ? lld_home_post_category_label($p) : 'Nutrition & Longevity';
  $mins = $p ? lld_home_post_minutes($p) : 5;
  $updated = ($p && function_exists('get_the_modified_date')) ? (string)get_the_modified_date('M Y', $p) : 'Oct 2026';
  if ($updated === '') $updated = 'Oct 2026';
  $img = ($p && function_exists('has_post_thumbnail') && has_post_thumbnail($p))
    ? get_the_post_thumbnail($p, 'medium_large', ['class' => 'lld-pack-hero__img', 'loading' => 'eager', 'data-no-lazy' => '1', 'decoding' => 'async', 'alt' => ''])
    : '<span class="lld-pack-hero__noimg" aria-hidden="true"></span>';

  $out  = '<section class="lld-pack-hero" data-test="home-hero">';
  $out .= '<div class="lld-pack-hero__grid">';

  // Left column (7 of 12 on desktop): Mission badge, headline, lead, search bar, popular pills
  $out .= '<div class="lld-pack-hero__left">';
  $out .= '<div class="lld-pack-hero__badge"><span class="lld-pack-hero__dot" aria-hidden="true"></span>Evidence-Based Health, Simplified</div>';
  $out .= '<h2 class="lld-pack-hero__title">Start small. Change big. <br><span class="lld-pack-hero__accent">Live healthier</span> every day.</h2>';
  $out .= '<p class="lld-pack-hero__lead">Clinically reviewed guidance, accurate health calculators, and structured 4-week habits — without fads or medical jargon.</p>';

  $out .= '<form role="search" method="get" action="' . esc_url(home_url('/')) . '" class="lld-pack-hero__search">';
  $out .= '<label for="lld-hero-search" class="lld-sr-only">Search health topics, calculators, and articles</label>';
  $out .= '<span class="lld-pack-hero__search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg></span>';
  $out .= '<input id="lld-hero-search" type="search" name="s" placeholder="Search protein, blood sugar, longevity..." aria-label="Search health topics, calculators, and articles" class="lld-pack-hero__search-input">';
  $out .= '<button type="submit" class="lld-pack-hero__search-btn">Search</button>';
  $out .= '</form>';

  $out .= '<div class="lld-pack-hero__popular">';
  $out .= '<span class="lld-pack-hero__popular-label">Popular:</span>';
  $out .= '<a class="lld-pack-hero__pill" href="' . esc_url(home_url('/health-tools/protein/')) . '">Protein Target</a>';
  $out .= '<a class="lld-pack-hero__pill" href="' . esc_url(home_url('/health-tools/diabetes-risk-check/')) . '">Diabetes Risk Check</a>';
  $out .= '<a class="lld-pack-hero__pill" href="' . esc_url(lld_home_member_url('/programmes/healthy-weight/')) . '">4-Week Tracks</a>';
  $out .= '</div>';
  $out .= '</div>';

  // Right column (5 of 12 on desktop): Hero Featured Story Card
  $out .= '<div class="lld-pack-hero__right">';
  $out .= '<div class="lld-pack-hero__card">';
  $out .= '<a class="lld-pack-hero__card-link" href="' . esc_url($postUrl) . '">';
  $out .= '<div class="lld-pack-hero__media">' . $img;
  $out .= '<span class="lld-pack-hero__cat">' . esc_html($catName) . '</span>';
  $out .= '<span class="lld-pack-hero__time"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' . (int)$mins . ' min read</span>';
  $out .= '</div>';
  $out .= '<div class="lld-pack-hero__card-body">';
  $out .= '<div class="lld-pack-hero__meta"><span class="lld-pack-hero__reviewed"><svg viewBox="0 0 20 20" width="12" height="12" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Clinically Reviewed</span><span aria-hidden="true">&bull;</span><span>Updated ' . esc_html($updated) . '</span></div>';
  $out .= '<h3 class="lld-pack-hero__card-title">' . esc_html($title) . '</h3>';
  $out .= '<p class="lld-pack-hero__card-excerpt">' . esc_html($excerpt) . '</p>';
  $out .= '<div class="lld-pack-hero__card-foot"><span class="lld-pack-hero__read">Read full article <span aria-hidden="true">&rarr;</span></span><span class="lld-pack-hero__by">By Editorial Team</span></div>';
  $out .= '</div></a></div></div>';

  $out .= '</div></section>';
  return $out;
}
if (function_exists('add_shortcode')) add_shortcode('lld_home_hero', 'lld_home_render_hero');

/** [lld_home_tracks]: Section 3 — 4-Week Programmes Grid (4 Tracks + Premium Trial Callout Banner). */
function lld_home_render_tracks(): string {
  $tracks = [
    [
      'slug' => 'healthy-weight',
      'tone' => 'green',
      'icon' => '&#129367;',
      'title' => 'Healthy Weight',
      'copy' => 'Sustainable fullness, daily protein targets, and mindful portion rhythms.',
      'url' => lld_home_member_url('/programmes/healthy-weight/'),
    ],
    [
      'slug' => 'blood-sugar',
      'tone' => 'blue',
      'icon' => '&#129656;',
      'title' => 'Blood Sugar Balance',
      'copy' => 'Post-meal walks, carb sequencing, and smoothing glycaemic spikes.',
      'url' => lld_home_member_url('/programmes/blood-sugar/'),
    ],
    [
      'slug' => 'fitness',
      'tone' => 'amber',
      'icon' => '&#128170;',
      'title' => 'Fitness & Strength',
      'copy' => 'Progressive home resistance, joint mobility, and functional longevity strength.',
      'url' => lld_home_member_url('/programmes/fitness/'),
    ],
    [
      'slug' => 'sleep-stress',
      'tone' => 'purple',
      'icon' => '&#127769;',
      'title' => 'Sleep & Stress',
      'copy' => 'Circadian light exposure, calming wind-down rituals, and restorative deep sleep.',
      'url' => lld_home_member_url('/programmes/sleep-stress/'),
    ],
  ];

  $out  = '<section id="tracks-section" class="lld-pack-tracks" data-test="tracks-row">';
  $out .= '<div class="lld-pack-tracks__head">';
  $out .= '<span class="lld-pack-tracks__badge">Stage 2 Programmes</span>';
  $out .= '<h2 class="lld-pack-tracks__title">Four structured tracks. Real habit change.</h2>';
  $out .= '<p class="lld-pack-tracks__lead">Step-by-step daily micro-habits designed by clinicians, complete with meal plans and safe weekly workouts.</p>';
  $out .= '</div>';

  $out .= '<div class="lld-pack-tracks__grid">';
  foreach ($tracks as $t) {
    $out .= '<a class="lld-pack-tracks__card lld-pack-tracks__card--' . esc_attr($t['tone']) . '" data-track="' . esc_attr($t['slug']) . '" href="' . esc_url($t['url']) . '">';
    $out .= '<span class="lld-pack-tracks__icon" aria-hidden="true">' . $t['icon'] . '</span>';
    $out .= '<div class="lld-pack-tracks__body">';
    $out .= '<span class="lld-pack-tracks__tag">4-Week Track</span>';
    $out .= '<h3 class="lld-pack-tracks__name">' . esc_html($t['title']) . '</h3>';
    $out .= '<p class="lld-pack-tracks__copy">' . esc_html($t['copy']) . '</p>';
    $out .= '<span class="lld-pack-tracks__more">Explore track <span aria-hidden="true">&rarr;</span></span>';
    $out .= '</div></a>';
  }
  $out .= '</div>';

  $out .= '<div class="lld-pack-tracks__callout">';
  $out .= '<div class="lld-pack-tracks__callout-text">';
  $out .= '<div class="lld-pack-tracks__callout-title">Join Lifeline Daily Premium</div>';
  $out .= '<p class="lld-pack-tracks__callout-copy">Access all 4 tracks, recipe vault, workout plans, and daily logs for <strong>$7.99/mo</strong>.</p>';
  $out .= '</div>';
  $out .= '<a class="lld-pack-tracks__callout-btn" href="' . esc_url(lld_home_member_url('/members/join/')) . '">Start 7-day free trial</a>';
  $out .= '</div>';

  $out .= '</section>';
  return $out;
}
if (function_exists('add_shortcode')) add_shortcode('lld_home_tracks', 'lld_home_render_tracks');

/** [lld_home_guide]: Section 5 — Starter Guide Signup / Lead Magnet (SCR-42). */
function lld_home_render_guide(): string {
  $joinUrl = lld_home_member_url('/members/join/');
  $out  = '<section id="starter-guide" class="lld-pack-guide" data-test="guide-row">';
  $out .= '<div class="lld-pack-guide__card">';
  $out .= '<div class="lld-pack-guide__inner">';
  $out .= '<span class="lld-pack-guide__badge">Free Weekly Habit Newsletter</span>';
  $out .= '<h3 class="lld-pack-guide__title">Get the 4-Week Healthy Habit Starter Guide</h3>';
  $out .= '<p class="lld-pack-guide__lead">Join 12,000+ readers. One practical nutrition insight, one workout prompt, and one science-backed longevity habit every Sunday.</p>';
  $out .= '<form class="lld-pack-guide__form" action="' . esc_url($joinUrl) . '" method="get">';
  $out .= '<label for="lld-guide-email" class="lld-sr-only">Enter your email address</label>';
  $out .= '<input id="lld-guide-email" type="email" name="email" required placeholder="Enter your email address" aria-label="Enter your email address" class="lld-pack-guide__input">';
  $out .= '<button type="submit" class="lld-pack-guide__btn">Send my free guide</button>';
  $out .= '</form>';
  $out .= '<p class="lld-pack-guide__note"><span aria-hidden="true">&#128274;</span> Zero spam. Medical privacy respected. Unsubscribe at any time.</p>';
  $out .= '</div></div></section>';
  return $out;
}
if (function_exists('add_shortcode')) add_shortcode('lld_home_guide', 'lld_home_render_guide');

/**
 * Runtime front-page Composer shortcode transform:
 * Keeps database `post_content` untouched for `wp lld home apply | revert` and TC-FR1-03 unit tests, while rendering the
 * complete 5-section Interface Pack homepage on front-end views of the static front page.
 */
function lld_home_pack_transform_runtime(string $content): string {
  if (strpos($content, 'lld-home-tools-row') === false || strpos($content, '[lld_home_hero]') !== false) {
    return $content;
  }
  $rowAttrs = 'full_width="stretch_row_1200 td-stretch-content"';
  // 1. Replace the first Composer row (.lld-home-hero) with the Split Hero shortcode
  $content = (string)preg_replace(
    '/\[vc_row\b[^\]]*\blld-home-hero\b[^\]]*\].*?\[\/vc_row\]/s',
    '[vc_row el_class="lld-home-hero" ' . $rowAttrs . '][vc_column width="1/1"][lld_home_hero][/vc_column][/vc_row]',
    $content,
    1
  );
  // 2. Insert the 4-Week Programmes row right before the Learn row
  $tracksRow = '[vc_row ' . $rowAttrs . ' el_class="lld-home-tracks-row"][vc_column width="1/1"][lld_home_tracks][/vc_column][/vc_row]';
  $content = (string)preg_replace(
    '/(\[vc_row\b[^\]]*\blld-home-learn-row\b[^\]]*\])/',
    $tracksRow . '$1',
    $content,
    1
  );
  // 3. Replace legacy Newspaper rows after the Learn row with the Starter Guide Signup row
  $guideRow = '[vc_row ' . $rowAttrs . ' el_class="lld-home-guide-row"][vc_column width="1/1"][lld_home_guide][/vc_column][/vc_row]';
  if (preg_match('/\[vc_row\b[^\]]*\blld-home-learn-row\b[^\]]*\].*?\[\/vc_row\]/s', $content, $m, PREG_OFFSET_CAPTURE)) {
    $endOfLearn = $m[0][1] + strlen($m[0][0]);
    $tail = substr($content, $endOfLearn);
    $zoneClose = strrpos($tail, '[/tdc_zone]');
    $content = substr($content, 0, $endOfLearn) . $guideRow . ($zoneClose !== false ? substr($tail, $zoneClose) : '');
  }
  return $content;
}

if (function_exists('add_action')) {
  add_action('wp', function () {
    if (!function_exists('lld_tools_front_view') || !lld_tools_front_view() || !function_exists('is_front_page') || !is_front_page()) return;
    $p = function_exists('get_post') ? get_post() : null;
    if ($p && isset($p->post_content)) {
      $p->post_content = lld_home_pack_transform_runtime((string)$p->post_content);
    }
  });
}
if (function_exists('add_filter')) {
  add_filter('the_content', function ($content) {
    if (!function_exists('lld_tools_front_view') || !lld_tools_front_view() || !function_exists('is_front_page') || !is_front_page()) return $content;
    return lld_home_pack_transform_runtime((string)$content);
  }, 5);
}
