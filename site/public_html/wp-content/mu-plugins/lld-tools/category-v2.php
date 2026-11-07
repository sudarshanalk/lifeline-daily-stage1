<?php
/**
 * Category page v2 (S2-L1; owner's new UI pack, 6 Nov 2026: `03 UI Designs/UI-Inventory/Latest UIs/lld_category_weight_eating.html`).
 *
 * Every category of the agreed tree (data/categories.json → tree, top categories and their children) is rendered by its own
 * full-page template (`templates/category-v2.php`): the design's header with search and Day/Night, masthead with breadcrumb,
 * guide count and sub-topic pills, three featured guides, the category's tool banner, the latest guides grid with paging,
 * the 4-week track banner, the urgent-care strip and the design's footer with the starter guide.
 *
 * Content comes from the existing category hub (category-hub.php): the flagship and trending choice (owner question 36), the
 * tool and track per category, the description and the sub-topic pills. Hidden display categories keep the theme.
 * Page 1 shows 12 guides (3 featured + 9 in the grid); later pages show 12 in the grid, through the main query, so
 * /category/<slug>/page/<n>/ keeps answering.
 * Switch off with `define('LLD_CATEGORY_V2', false)` in wp-config.php to go back to the Newspaper category template.
 */
if (!defined('ABSPATH')) exit;

const LLD_CATEGORY_V2_PER_PAGE = 12;

/** The hub entry of the category being viewed when the v2 page is on for it, else null. */
function lld_category_v2_hub(): ?array {
  if (defined('LLD_CATEGORY_V2') && !LLD_CATEGORY_V2) return null;
  if (!function_exists('lld_tools_front_view') || !lld_tools_front_view()) return null;
  if (!function_exists('is_category') || !is_category() || (function_exists('is_feed') && is_feed())) return null;
  $term = get_queried_object();
  if (!$term || empty($term->slug) || !function_exists('lld_hub_entry') || !function_exists('lld_categories_doc')) return null;
  return lld_hub_entry(lld_categories_doc(), (string)$term->slug);
}

/** A post as the v2 cards show it (reuses the hub's view: title, excerpt, image, minutes, updated, category label). */
function lld_category_v2_card($p, string $size, string $fallbackCat, bool $eager = false): array {
  $v = lld_hub_post_view($p, $size, $eager, $fallbackCat);
  $attr = ['class' => 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500', 'loading' => $eager ? 'eager' : 'lazy', 'decoding' => 'async', 'alt' => ''];
  $v['image'] = has_post_thumbnail($p) ? get_the_post_thumbnail($p, $size, $attr) : '';
  return $v;
}

/* ------------------------------------------------------------------ hooks ------------------------------------------ */
if (function_exists('add_action')) {
  // 12 guides a page on v2 category pages, so the grid and its paging come from the main query.
  add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query() || !$q->is_category() || $q->is_feed()) return;
    if (defined('LLD_CATEGORY_V2') && !LLD_CATEGORY_V2) return;
    $q->set('posts_per_page', LLD_CATEGORY_V2_PER_PAGE);
    $q->set('ignore_sticky_posts', true);
  });
  add_filter('template_include', function ($template) {
    return lld_category_v2_hub() ? __DIR__ . '/templates/category-v2.php' : $template;
  }, 99);
  add_action('wp_enqueue_scripts', function () {
    if (!lld_category_v2_hub()) return;
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
    wp_enqueue_script('lld-category-v2', lld_home_v2_asset('lld-category-v2.js'), [], null, true);
  }, 999);
  add_action('wp_head', function () {
    if (!lld_category_v2_hub()) return;
    foreach (['instrument-serif-latin', 'plus-jakarta-sans-latin-var'] as $f) {
      echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url(plugins_url('assets/fonts/' . $f . '.woff2', LLD_TOOLS_DIR . '/lld-tools.php')) . '">' . "\n";
    }
  }, 1);
  add_action('wp_footer', function () {
    if (lld_category_v2_hub() && !defined('LLD_FOOTER_RENDERED')) define('LLD_FOOTER_RENDERED', true);
  }, 1);
}
