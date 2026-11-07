<?php
/**
 * Public pages v2 (S2-L1; owner's new UI pack, 7 Nov 2026: `03 UI Designs/UI-Inventory/Latest UIs/` 04 Health Tools hub,
 * 05 to 07 tool pages, 08 search, 09 governance pages). Group 1 of the pack's restyle (Owner_Questions 153 and 158): the
 * pack gives the look only; every word, rule and safety text on these pages stays the approved one.
 *
 * One full-page template (`templates/page-v2.php`) with the site's one header and footer (parts/v2-header.php,
 * parts/v2-footer.php) renders, in the design's blocks:
 *   - hub     /health-tools/: the approved hero and footer cards, and the [lld_tools_hub] cards (markup unchanged, styled
 *             in lld-v2.src.css under .lld-v2-hub);
 *   - tool    the three Stage 1 tool pages (data/landing-pages.json): the page's own sections, found by their
 *             h2[data-lld-section], laid out as the design's cards around the embedded tool (FR1-21 frame unchanged);
 *   - legal   the medical disclaimer and urgent care, about, privacy, terms and contact pages: the page's own content beside
 *             the governance list;
 *   - search  the search results, with the matching tool's approved card.
 * Page content in the database is never changed (`wp lld pages apply` still writes it), so a page that is not in the
 * expected shape is shown whole in one card.
 * Switch off with `define('LLD_PAGES_V2', false)` in wp-config.php to go back to the Newspaper templates.
 */
if (!defined('ABSPATH')) exit;

/** The governance pages, in the order of their list. */
function lld_page_v2_legal_pages(): array {
  return [
    'medical-disclaimer' => 'Medical disclaimer and urgent care',
    'about-us' => 'About us',
    'privacy-policy' => 'Privacy policy',
    'terms-conditions' => 'Terms and conditions',
    'contact-us' => 'Contact us',
  ];
}

/** Which v2 page this request is: 'hub', 'tool', 'legal', 'search', or '' to leave it to the theme. */
function lld_page_v2_view(): string {
  static $view = null;
  if ($view !== null) return $view;
  $view = '';
  if (defined('LLD_PAGES_V2') && !LLD_PAGES_V2) return $view;
  if (!function_exists('lld_tools_front_view') || !lld_tools_front_view()) return $view;
  if ((function_exists('is_feed') && is_feed()) || (function_exists('is_embed') && is_embed())) return $view;
  if (function_exists('is_search') && is_search()) return $view = 'search';
  if (!function_exists('is_page') || !is_page()) return $view;
  $page = get_queried_object();
  if (!$page || empty($page->post_name)) return $view;
  $slug = (string)$page->post_name;
  $parent = $page->post_parent ? get_post((int)$page->post_parent) : null;
  if ($slug === 'health-tools' && !$parent) return $view = 'hub';
  if ($parent && $parent->post_name === 'health-tools' && lld_page_v2_tool_slug() !== '') return $view = 'tool';
  if (!$parent && isset(lld_page_v2_legal_pages()[$slug])) return $view = 'legal';
  return $view;
}

/** The Stage 1 tool (protein, diabetes, healthy-weight) whose landing page is being viewed, else ''. */
function lld_page_v2_tool_slug(): string {
  $page = function_exists('get_queried_object') ? get_queried_object() : null;
  if (!$page || empty($page->ID) || !function_exists('lld_tools_cards')) return '';
  $path = trailingslashit((string)wp_parse_url((string)get_permalink($page), PHP_URL_PATH));
  foreach (lld_tools_cards() as $slug => $c) {
    if (trailingslashit((string)wp_parse_url((string)$c['landing_url'], PHP_URL_PATH)) === $path) return (string)$slug;
  }
  return '';
}

/**
 * The sections of a tool page's rendered content, split at each h2[data-lld-section]: ['intro' => html before the first,
 * 'sections' => [key => ['h2' => the heading tag as rendered, 'heading' => its text, 'body' => html up to the next]]].
 * Pure (no WordPress), tested in tests/lead/page-v2-sections.test.mjs.
 */
function lld_page_v2_sections(string $html): array {
  $out = ['intro' => '', 'sections' => []];
  if (!preg_match_all('#<h2\b[^>]*\bdata-lld-section="([a-z]+)"[^>]*>(.*?)</h2>#is', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
    $out['intro'] = $html;
    return $out;
  }
  $out['intro'] = substr($html, 0, $m[0][0][1]);
  foreach ($m as $i => $h) {
    $start = $h[0][1] + strlen($h[0][0]);
    $end = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($html);
    $out['sections'][$h[1][0]] = ['h2' => $h[0][0], 'heading' => trim(html_entity_decode(strip_tags($h[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'body' => trim(substr($html, $start, $end - $start))];
  }
  return $out;
}

/** The h2 of a section with the design's classes added (its data-lld-section and text kept as rendered). */
function lld_page_v2_h2(string $h2, string $classes): string {
  return (string)preg_replace('#^<h2\b#i', '<h2 class="' . esc_attr($classes) . '"', preg_replace('#^<h2\b([^>]*?)\sclass="[^"]*"#i', '<h2$1', $h2), 1);
}

/** The related-reading posts a tool page lists ([lld_tool_related] keeps choosing them): their ids, in order. */
function lld_page_v2_related_ids(string $html): array {
  preg_match_all('#<li\b[^>]*\bdata-post-id="(\d+)"#i', $html, $m);
  return array_values(array_unique(array_map('intval', $m[1] ?? [])));
}

/**
 * The site's v2 look for a lld-members screen (P3-60 pricing first; owner's UI pack, Owner_Questions 153): the plugin's
 * page shell (lld_members_layout with 'v2' => true) asks for each part here and keeps its own markup, wording and
 * scripts in between. Parts: 'head' (stylesheet and fonts), 'body' (Day/Night before first paint), 'header', 'footer'
 * (column titles as text: one heading per screen), 'scripts' (the header's menu and Day/Night). Off with LLD_PAGES_V2.
 */
function lld_v2_members_shell_on(): bool {
  return !(defined('LLD_PAGES_V2') && !LLD_PAGES_V2) && function_exists('lld_home_v2_asset');
}
function lld_v2_members_shell(string $part): string {
  if (!lld_v2_members_shell_on()) return '';
  switch ($part) {
    case 'head':
      $out = '<link rel="stylesheet" href="' . esc_url(lld_home_v2_asset('lld-v2.css')) . '">';
      foreach (['instrument-serif-latin', 'plus-jakarta-sans-latin-var'] as $f) {
        $out .= '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url(plugins_url('assets/fonts/' . $f . '.woff2', LLD_TOOLS_DIR . '/lld-tools.php')) . '">';
      }
      return $out;
    case 'body':
      return "<script>try{if(localStorage.getItem('lld_circadian')==='night')document.body.classList.add('mode-night');}catch(e){}</script>";
    case 'header':
      $lld_header = ['page' => 'page', 'current' => ''];
      ob_start(); include __DIR__ . '/templates/parts/v2-header.php'; return (string)ob_get_clean();
    case 'footer':
      $lld_footer_plain = true;
      ob_start(); include __DIR__ . '/templates/parts/v2-footer.php'; return (string)ob_get_clean();
    case 'scripts':
      return '<script src="' . esc_url(lld_home_v2_asset('lld-home-v2.js')) . '"></script>';
  }
  return '';
}

/* ------------------------------------------------------------------ hooks ------------------------------------------ */
if (function_exists('add_filter')) {
  add_filter('template_include', function ($template) {
    return lld_page_v2_view() !== '' ? __DIR__ . '/templates/page-v2.php' : $template;
  }, 99);
}
if (function_exists('add_action')) {
  // The page carries its own styles: the theme's and the Interface Pack's stylesheets and scripts stay off it. Plugin
  // styles stay (the contact form's own, the cookie banner's).
  add_action('wp_enqueue_scripts', function () {
    if (lld_page_v2_view() === '') return;
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
    wp_enqueue_script('lld-home-v2', lld_home_v2_asset('lld-home-v2.js'), [], null, true);   // the shared header's menu and Day/Night
  }, 999);
  add_action('wp_head', function () {
    if (lld_page_v2_view() === '') return;
    foreach (['instrument-serif-latin', 'plus-jakarta-sans-latin-var'] as $f) {
      echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url(plugins_url('assets/fonts/' . $f . '.woff2', LLD_TOOLS_DIR . '/lld-tools.php')) . '">' . "\n";
    }
  }, 1);
  // The template prints its own footer, so the approved site footer is not added again.
  add_action('wp_footer', function () {
    if (lld_page_v2_view() !== '' && !defined('LLD_FOOTER_RENDERED')) define('LLD_FOOTER_RENDERED', true);
  }, 1);
}
