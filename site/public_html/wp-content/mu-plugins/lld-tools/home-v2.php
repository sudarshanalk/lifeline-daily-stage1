<?php
/**
 * Homepage v2 (S2-L1; owner's new UI pack, 6 Nov 2026: `03 UI Designs/UI-Inventory/Latest UIs/lld_homepage_interactive_demo.html`).
 *
 * The front page only is rendered by its own full-page template (`templates/home-v2.php`): the new announcement bar with the
 * Day/Night switch, header with the "Your Health" menu, hero, calculators, 4-week tracks, the science, essential reads, the
 * starter guide and the new footer. Every other page keeps the Newspaper templates, the site header and the approved footer.
 *
 * Owner decisions (chat, 6 Nov 2026):
 *   - Whole design on the homepage only (header and footer included).
 *   - The calculator panel embeds the real, signed-off tools (the same auto-height embed as the /health-tools/ pages), so
 *     every number shown is the frozen engine's, after its safety questions. No clinical code or wording is copied here.
 *   - The reader count in the newsletter box is dropped.
 *
 * The database `post_content` of the front page is not touched, so `wp lld home apply | revert` and TC-FR1-03's content
 * checks keep working; the Interface Pack shortcodes stay registered for any page that still uses them.
 * Switch off with `define('LLD_HOME_V2', false)` in wp-config.php to go back to the Interface Pack homepage.
 */
if (!defined('ABSPATH')) exit;

/** True on a front-end view of the static front page while the v2 homepage is on. */
function lld_home_v2_active(): bool {
  if (defined('LLD_HOME_V2') && !LLD_HOME_V2) return false;
  if (!function_exists('lld_tools_front_view') || !lld_tools_front_view()) return false;
  if (!function_exists('is_front_page') || !is_front_page()) return false;
  return !(function_exists('is_paged') && is_paged());
}

/** Address of a v2 homepage asset, versioned by modification time like the other plugin assets. */
function lld_home_v2_asset(string $rel): string {
  $url = plugins_url('assets/' . $rel, LLD_TOOLS_DIR . '/lld-tools.php');
  return $url . '?ver=' . rawurlencode(function_exists('lld_tools_asset_version') ? lld_tools_asset_version('assets/' . $rel) : LLD_TOOLS_VERSION);
}

/** The three tools in the calculator panel, in tab order. Text is presentation only; the tools carry their own copy. */
function lld_home_v2_tools(): array {
  return [
    'protein' => [
      'tab' => 'Daily Protein Target', 'img' => 'calc-protein.jpg', 'alt' => 'Healthy fresh nutrition ingredients',
      'title' => 'Know your daily protein target.',
      'sub' => 'Preserve lean muscle, support healthy satiety, and pace your meals.',
      'url' => '/health-tools/protein/',
    ],
    'diabetes' => [
      'tab' => 'Diabetes Risk Check', 'img' => 'calc-diabetes.jpg', 'alt' => 'Blood sugar balance habits',
      'title' => 'Check your 10-year diabetes risk.',
      'sub' => 'See where you stand, and the everyday changes that lower it.',
      'url' => '/health-tools/diabetes-risk-check/',
    ],
    'healthy-weight' => [
      'tab' => 'Healthy Weight Check', 'img' => 'calc-weight.jpg', 'alt' => 'Balanced whole-food meal',
      'title' => 'Assess your waist-to-height ratio.',
      'sub' => 'See your BMI and waist-to-height ratio, and what they mean for you.',
      'url' => '/health-tools/healthy-weight-check/',
    ],
  ];
}

/**
 * One tool embed for a tab: the plugin's own [lld_tool] embed (FR1-21), with a unique frame id per tab. The first tab's
 * frame loads at once; the others carry data-src and load the first time their tab is opened, so the page stays light.
 */
function lld_home_v2_tool_frame(string $slug, bool $first): string {
  if (!function_exists('lld_tools_render_tool')) return '';
  $html = lld_tools_render_tool(['slug' => $slug]);
  if ($html === '') return '';
  $html = str_replace('id="lld-tool-frame"', 'id="lld-home-frame-' . esc_attr($slug) . '"', $html);
  if (!$first) {
    $html = (string)preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', $html);   // the frame script is already on the page
    $html = (string)preg_replace('/<iframe([^>]*?)\ssrc="([^"]+)"/', '<iframe$1 data-src="$2" src="about:blank"', $html, 1);
  }
  return $html;
}

/** Up to three newest published articles with a featured image, for "Essential Health Reads". */
function lld_home_v2_reads(): array {
  if (!class_exists('WP_Query')) return [];
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 12, 'orderby' => 'date', 'order' => 'DESC',
    'ignore_sticky_posts' => true, 'no_found_rows' => true]);
  $with = []; $without = [];
  foreach ($q->posts as $p) {
    if (function_exists('has_post_thumbnail') && has_post_thumbnail($p)) $with[] = $p; else $without[] = $p;
  }
  return array_slice(array_merge($with, $without), 0, 3);
}

/* ------------------------------------------------------------------ hooks ------------------------------------------ */
if (function_exists('add_filter')) {
  add_filter('template_include', function ($template) {
    return lld_home_v2_active() ? __DIR__ . '/templates/home-v2.php' : $template;
  }, 99);
}
if (function_exists('add_action')) {
  // The new page carries its own styles: the theme's and the Interface Pack's stylesheets and scripts stay off it.
  add_action('wp_enqueue_scripts', function () {
    if (!lld_home_v2_active()) return;
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
    wp_enqueue_style('lld-home-v2', lld_home_v2_asset('lld-home-v2.css'), [], null);
    wp_enqueue_script('lld-home-v2', lld_home_v2_asset('lld-home-v2.js'), [], null, true);
  }, 999);
  add_action('wp_head', function () {
    if (!lld_home_v2_active()) return;
    foreach (['instrument-serif-latin', 'plus-jakarta-sans-latin-var'] as $f) {
      echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url(plugins_url('assets/fonts/' . $f . '.woff2', LLD_TOOLS_DIR . '/lld-tools.php')) . '">' . "\n";
    }
    echo '<link rel="preload" as="image" href="' . esc_url(lld_home_v2_asset('home/hero-food.jpg')) . '" fetchpriority="high">' . "\n";
  }, 1);
  // The template prints its own footer, so the approved site footer is not added on this page.
  add_action('wp_footer', function () {
    if (lld_home_v2_active() && !defined('LLD_FOOTER_RENDERED')) define('LLD_FOOTER_RENDERED', true);
  }, 1);
}
