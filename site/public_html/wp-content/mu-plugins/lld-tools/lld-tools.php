<?php
/**
 * Plugin Name: Lifeline Daily Tools
 * Description: Must-use plugin (DEC-22) for the Stage 1 health tools: hub cards and landing-page embeds driven by tools/status.json (FR1-04), with `wp lld` commands. Theme untouched.
 * Version: 1.0.0
 * Author: Lifeline Daily
 */
if (!defined('ABSPATH')) exit;

define('LLD_TOOLS_DIR', __DIR__);
define('LLD_TOOLS_VERSION', '1.0.0');

/* The document root that serves /tools/*: ABSPATH in production; the test harness overlays the repository's site/public_html
 * (LLD_TOOLS_DOCROOT defined by tests/lib/wp-router.php and wp-cli-env.php). */
function lld_tools_docroot(): string { return rtrim(defined('LLD_TOOLS_DOCROOT') ? LLD_TOOLS_DOCROOT : ABSPATH, '/\\'); }

/* ---------------------------------------------------------------- settings (DEC-34) ------------------------------- */
function lld_tools_settings(): array {
  $origin = untrailingslashit(home_url());
  $defaults = [
    'iframe_origin'    => $origin,                           // bare origin the tools post their height to (DEC-34)
    'tools_origin'     => $origin,                           // where /tools/*.html is served
    'status_path'      => lld_tools_docroot() . '/tools/status.json',   // server copy is the source of truth (DEC-10)
    'analytics_domain' => '',
    'purge_targets'    => [],                                // LiteSpeed purge targets on status change (DEC-31, FR1-15)
  ];
  $opt = get_option('lld_tools_settings', []);
  return array_merge($defaults, is_array($opt) ? $opt : []);
}

/* ---------------------------------------------------------------- status reader (SCH-08, DEC-24) ------------------- */
function lld_tools_status(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $path = lld_tools_settings()['status_path'];
  $raw = is_readable($path) ? file_get_contents($path) : '';
  $doc = $raw ? json_decode($raw, true) : null;
  $cache = is_array($doc) ? $doc : ['tools' => [], 'labels' => []];
  return $cache;
}
function lld_tools_tool_status(string $slug): string {
  $s = lld_tools_status();
  $st = $s['tools'][$slug]['status'] ?? 'active';
  return in_array($st, ['active', 'withdrawn', 'label_only'], true) ? $st : 'active';
}
function lld_tools_status_label(string $status): string {
  return ['active' => 'Available now', 'withdrawn' => 'Temporarily unavailable', 'label_only' => 'Planned'][$status] ?? 'Available now';
}
/* User-facing plugin copy (DEC-25, DEC-72): status.json carries a copy KEY in `message`; the text rendered is always this
 * approved copy (mirrors MESSAGES.unavailable.body in both tools / copy deck `unavailable.body`), never free text from the file. */
const LLD_TOOLS_MESSAGES = [
  'unavailable.body' => 'We have paused this tool while we check it. Nothing about your health is affected by the pause — please come back soon, or read on below.',
];
function lld_tools_message(?string $key): string {
  return LLD_TOOLS_MESSAGES['unavailable.body'];
}

/* ---------------------------------------------------------------- cards (SCH-04) ---------------------------------- */
function lld_tools_cards(): array {
  static $cards = null;
  if ($cards !== null) return $cards;
  $doc = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/tool-cards.json'), true);
  $cards = [];
  foreach (($doc['cards'] ?? []) as $c) $cards[$c['tool_slug']] = $c;
  return $cards;
}
function lld_tools_card(string $slug): ?array { return lld_tools_cards()[$slug] ?? null; }
function lld_tools_file(string $slug): string { return $slug === 'diabetes' ? 'diabetes-risk-tool.html' : 'protein-requirement-tool.html'; }

/* ---------------------------------------------------------------- [lld_tools_hub] --------------------------------- */
function lld_tools_render_hub(array $atts = []): string {
  $atts = shortcode_atts(['compact' => '0'], $atts, 'lld_tools_hub');
  $compact = $atts['compact'] === '1';
  $out = '<section class="lld-tools-hub' . ($compact ? ' lld-tools-hub--compact' : '') . '" data-test="tools-hub"><ul class="lld-tools-hub__list">';
  if ($compact) $out = '<section class="lld-home-row lld-home-tools" data-test="tools-row"><h2 class="lld-home-row__title">Tools</h2>' . $out;
  foreach (lld_tools_cards() as $slug => $c) {
    $status = lld_tools_tool_status($slug);
    $out .= '<li class="lld-tool-card lld-tool-card--' . esc_attr($status) . '" data-tool-card data-tool="' . esc_attr($slug) . '" data-status="' . esc_attr($status) . '">';
    $out .= '<h3 class="lld-tool-card__name" data-test="card-name">' . esc_html($c['name']) . '</h3>';
    $out .= '<p class="lld-tool-card__purpose" data-test="card-purpose">' . esc_html($c['purpose']) . '</p>';
    $out .= '<span class="lld-tool-card__status" data-test="status-label">' . esc_html(lld_tools_status_label($status)) . '</span>';
    if (!$compact) {
      $out .= '<p class="lld-tool-card__time" data-test="card-time">About ' . (int)$c['time_minutes'] . ' minute' . ((int)$c['time_minutes'] === 1 ? '' : 's') . '</p>';
      $out .= '<ul class="lld-tool-card__inputs" data-test="card-inputs">';
      foreach ($c['inputs'] as $i) $out .= '<li>' . esc_html($i) . '</li>';
      $out .= '</ul>';
      $out .= '<p class="lld-tool-card__eligibility" data-test="card-eligibility">' . esc_html($c['eligibility']) . '</p>';
    }
    $out .= '<a class="lld-tool-card__cta" data-test="cta" href="' . esc_url($c['landing_url']) . '">' . esc_html($c['cta_label']) . '</a>';
    $post = get_post((int)$c['related_post_id']);
    if ($post && $post->post_status === 'publish') $out .= '<p class="lld-tool-card__related" data-test="card-related"><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></p>';
    $out .= '</li>';
  }
  // planned tools: label-only chips, never a link (DEC-24, DEC-26); the compact homepage row shows the two active cards only
  foreach (($compact ? [] : (lld_tools_status()['labels'] ?? [])) as $l) {
    if (($l['status'] ?? '') !== 'label_only') continue;
    $out .= '<li class="lld-tool-card lld-tool-card--label_only" data-tool-card data-tool="' . esc_attr($l['slug'] ?? '') . '" data-status="label_only">';
    $out .= '<h3 class="lld-tool-card__name" data-test="card-name">' . esc_html($l['label'] ?? '') . '</h3>';
    $out .= '<span class="lld-tool-card__status" data-test="status-label">' . esc_html(lld_tools_status_label('label_only')) . '</span></li>';
  }
  return $out . '</ul></section>' . ($compact ? '</section>' : '');
}
add_shortcode('lld_tools_hub', 'lld_tools_render_hub');

/* ---------------------------------------------------------------- [lld_tool slug=…] (embed, FR1-21 contract) ------- */
function lld_tools_render_tool(array $atts = []): string {
  $atts = shortcode_atts(['slug' => ''], $atts, 'lld_tool');
  $slug = $atts['slug']; $c = lld_tools_card($slug);
  if (!$c) return '';
  $s = lld_tools_settings();
  $status = lld_tools_tool_status($slug);
  if ($status !== 'active') {
    $text = lld_tools_message(lld_tools_status()['tools'][$slug]['message'] ?? null);
    $post = get_post((int)$c['related_post_id']);
    $out = '<div class="lld-tool-unavailable" data-test="unavailable" data-tool="' . esc_attr($slug) . '" role="status">';
    $out .= '<h2>' . esc_html($c['name']) . ': ' . esc_html(lld_tools_status_label($status)) . '</h2><p>' . esc_html($text) . '</p>';
    $out .= '<p><a href="' . esc_url(home_url('/health-tools/')) . '">See all health tools</a>';
    if ($post && $post->post_status === 'publish') $out .= ' · <a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a>';
    return $out . '</p></div>';
  }
  $src = $s['tools_origin'] . '/tools/' . lld_tools_file($slug);
  $out  = '<script>window.lld_tools_settings = ' . wp_json_encode(['iframe_origin' => $s['iframe_origin']]) . ';</script>';
  $out .= '<script src="' . esc_url(home_url('/wp-content/mu-plugins/lld-tools/assets/lld-tool-frame.js')) . '?v=' . LLD_TOOLS_VERSION . '"></script>';
  $out .= '<iframe class="lld-tool-frame" id="lld-tool-frame" title="' . esc_attr($c['name']) . '" src="' . esc_url($src) . '" loading="eager"></iframe>';
  $out .= '<noscript><p>This tool needs JavaScript. <a href="' . esc_url($src) . '">Open the tool directly</a>.</p></noscript>';
  return $out;
}
add_shortcode('lld_tool', 'lld_tools_render_tool');

/* ---------------------------------------------------------------- [lld_tool_version slug=…] (FR1-04 AS-05, FR1-06) */
/* The approved version of a tool is the SHA-256 of its repository file, which carries the `__LLD_ORIGIN__` placeholder; the
 * deploy (deploy/prepare.py, DEC-34) substitutes the environment origin before upload, so the deployed file is hashed with that
 * origin normalised back to the placeholder. In the harness the file is served unsubstituted and the two hashes coincide. */
function lld_tools_origin(): string {
  return defined('LLD_TOOLS_ORIGIN') ? (string)LLD_TOOLS_ORIGIN : untrailingslashit(home_url());
}
function lld_tools_deployed_version(string $slug): string {
  $file = lld_tools_docroot() . '/tools/' . lld_tools_file($slug);
  if (!is_readable($file)) return '';
  $raw = file_get_contents($file);
  $origin = lld_tools_origin();
  return hash('sha256', $origin !== '' ? str_replace($origin, '__LLD_ORIGIN__', $raw) : $raw);
}
function lld_tools_render_version(array $atts = []): string {
  $atts = shortcode_atts(['slug' => ''], $atts, 'lld_tool_version');
  $h = lld_tools_deployed_version($atts['slug']);
  return $h ? '<span data-lld-deployed-version="' . esc_attr(substr($h, 0, 8)) . '" title="' . esc_attr($h) . '">' . esc_html(substr($h, 0, 8)) . '</span>' : '<span data-lld-deployed-version="">unknown</span>';
}
add_shortcode('lld_tool_version', 'lld_tools_render_version');

/* ---------------------------------------------------------------- [lld_tool_related slug=…] ----------------------- */
function lld_tools_render_related(array $atts = []): string {
  $atts = shortcode_atts(['slug' => ''], $atts, 'lld_tool_related');
  $c = lld_tools_card($atts['slug']); if (!$c) return '';
  $post = get_post((int)$c['related_post_id']);
  if (!$post || $post->post_status !== 'publish') return '<p data-test="related-reading">More reading on this topic is coming soon.</p>';
  return '<p data-test="related-reading"><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></p>';
}
add_shortcode('lld_tool_related', 'lld_tools_render_related');

/* ---------------------------------------------------------------- [lld_tool_cta slug=… post_id=…] (FR1-05 extends) */
function lld_tools_render_cta(array $atts = []): string {
  $atts = shortcode_atts(['slug' => '', 'post_id' => ''], $atts, 'lld_tool_cta');
  $c = lld_tools_card($atts['slug']); if (!$c) return '';
  return '<p class="lld-tool-cta" data-test="tool-cta" data-tool="' . esc_attr($atts['slug']) . '"><a href="' . esc_url($c['landing_url']) . '">' . esc_html($c['cta_label']) . ': ' . esc_html($c['name']) . '</a></p>';
}
add_shortcode('lld_tool_cta', 'lld_tools_render_cta');

/* ---------------------------------------------------------------- [lld_learn_row limit=…] (FR1-03 "Learn" row) ---- */
function lld_tools_render_learn_row(array $atts = []): string {
  $atts = shortcode_atts(['limit' => '6'], $atts, 'lld_learn_row');
  $limit = max(4, min(12, (int)$atts['limit']));
  $out = '<section class="lld-home-row lld-home-learn" data-test="learn-row"><h2 class="lld-home-row__title">Learn</h2><div class="lld-home-learn__list">';
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
  foreach ($q->posts as $p) {
    $out .= '<article class="lld-home-learn__item" data-post-id="' . (int)$p->ID . '"><a href="' . esc_url(get_permalink($p)) . '"><h3>' . esc_html(get_the_title($p)) . '</h3></a>';
    $out .= '<time datetime="' . esc_attr(get_the_date('c', $p)) . '">' . esc_html(get_the_date('', $p)) . '</time></article>';
  }
  return $out . '</div></section>';
}
add_shortcode('lld_learn_row', 'lld_tools_render_learn_row');
if (function_exists('add_action')) add_action('wp_enqueue_scripts', function () {
  wp_enqueue_style('lld-tools', home_url('/wp-content/mu-plugins/lld-tools/assets/lld-tools.css'), [], LLD_TOOLS_VERSION);
});

/* ---------------------------------------------------------------- homepage (FR1-03, DEC-23): Composer template transform */
function lld_tools_home_doc(): array { return json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/home.json'), true) ?: []; }
/** Insert the Tools row (compact hub) and the Learn row directly after the first Composer row (the hero), which becomes
 *  desktop-only; a phone-only copy of the hero follows the Tools row so mobile loses nothing (DEC-23: Tools directly below the
 *  header on mobile, below the hero on desktop). Idempotent: content already carrying the marker row is returned unchanged. */
function lld_tools_home_content(string $content): string {
  $doc = lld_tools_home_doc();
  $marker = $doc['marker_class'] ?? 'lld-home-tools-row';
  if (strpos($content, $marker) !== false) return $content;
  if (!preg_match('/\[vc_row\b[^\]]*\].*?\[\/vc_row\]/s', $content, $m, PREG_OFFSET_CAPTURE)) return $content;
  [$hero, $pos] = $m[0];
  $withClass = function (string $row, string $cls): string {
    return preg_match('/^\[vc_row\b[^\]]*\bel_class="([^"]*)"/', $row)
      ? preg_replace('/^(\[vc_row\b[^\]]*\bel_class=")([^"]*)"/', '$1$2 ' . $cls . '"', $row, 1)
      : preg_replace('/^\[vc_row\b/', '[vc_row el_class="' . $cls . '"', $row, 1);
  };
  $rowAttrs = $doc['row_attrs'] ?? 'full_width="stretch_row_1200 td-stretch-content"';
  $tools = '[vc_row ' . $rowAttrs . ' el_class="' . $marker . '"][vc_column width="1/1"]' . ($doc['tools_shortcode'] ?? '[lld_tools_hub compact=1]') . '[/vc_column][/vc_row]';
  $learn = '[vc_row ' . $rowAttrs . ' el_class="lld-home-learn-row"][vc_column width="1/1"]' . ($doc['learn_shortcode'] ?? '[lld_learn_row limit=6]') . '[/vc_column][/vc_row]';
  $new = $withClass($hero, 'lld-home-hero') . $tools . $withClass($hero, 'lld-home-hero-phone') . $learn;
  return substr($content, 0, $pos) . $new . substr($content, $pos + strlen($hero));
}

/* ---------------------------------------------------------------- landing pages (CMP-09 content, applied by WP-CLI) */
function lld_tools_landing_content(string $slug): string {
  $doc = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/landing-pages.json'), true);
  $page = $doc['pages'][$slug] ?? null; if (!$page) return '';
  $out = '';
  foreach ($doc['section_keys'] as $i => $key) {
    $out .= "\n<h2 data-lld-section=\"" . esc_attr($key) . "\">" . esc_html($doc['headings'][$i]) . "</h2>\n" . $page['sections'][$key] . "\n";
  }
  return trim($out);
}
function lld_tools_hub_content(): string {
  $doc = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/landing-pages.json'), true);
  return $doc['hub']['content'] ?? '[lld_tools_hub]';
}

/* ---------------------------------------------------------------- WP-CLI: wp lld … ------------------------------- */
if (defined('WP_CLI') && WP_CLI) {
  class LLD_Tools_CLI {
    /** Write the hub and landing-page content (idempotent). --dry-run prints the diff summary only. */
    public function pages(array $args, array $assoc) {
      $sub = $args[0] ?? 'apply';
      if ($sub !== 'apply') WP_CLI::error("unknown subcommand '$sub' (use: pages apply [--dry-run])");
      $doc = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/landing-pages.json'), true);
      $targets = [[$doc['hub']['slug'], lld_tools_hub_content()]];
      foreach ($doc['pages'] as $slug => $p) $targets[] = [$p['slug'], lld_tools_landing_content($slug)];
      // the homepage (FR1-03): the static front page's Composer template, transformed in place
      $front = get_option('show_on_front') === 'page' ? get_post((int)get_option('page_on_front')) : null;
      if ($front) $targets[] = ['home:' . $front->ID, lld_tools_home_content((string)$front->post_content)];
      else WP_CLI::warning('no static front page (show_on_front != page) — homepage rows skipped');
      foreach ($targets as [$pageSlug, $content]) {
        $page = strpos($pageSlug, 'home:') === 0 ? $front : get_page_by_path($pageSlug, OBJECT, 'page');
        if (!$page) { WP_CLI::warning("page '$pageSlug' not found — skipped"); continue; }
        $changed = trim((string)$page->post_content) !== trim($content);
        if (!$changed) { WP_CLI::log("$pageSlug: unchanged"); continue; }
        if (!empty($assoc['dry-run'])) { WP_CLI::log("$pageSlug: would update (" . strlen($content) . " bytes)"); continue; }
        $r = wp_update_post(['ID' => $page->ID, 'post_content' => $content], true);
        if (is_wp_error($r)) WP_CLI::error("$pageSlug: " . $r->get_error_message());
        WP_CLI::log("$pageSlug: updated (post " . $page->ID . ")");
      }
      WP_CLI::success('pages applied');
    }
    /** wp lld status get [<slug>] | status set <slug> <active|withdrawn> [--version=<sha256>] [--message=<copy key>]
     *  FR1-15 (DEC-10, DEC-24, DEC-31, DEC-71): the only writer of tools/status.json. `active` requires --version equal to the
     *  approved version — the SHA-256 of the deployed tool file with the environment origin normalised back to __LLD_ORIGIN__
     *  (= approvals/<slug>.json version_hash, which CI's provenance-check gated); there is no override. Otherwise the command
     *  exits non-zero and the file is untouched. Writes atomically, then purges the configured LiteSpeed targets. */
    public function status(array $args, array $assoc) {
      $sub = $args[0] ?? 'get';
      $s = lld_tools_status();
      if ($sub === 'get') { WP_CLI::print_value(!empty($args[1]) ? ($s['tools'][$args[1]] ?? null) : $s, ['format' => 'json']); return; }
      if ($sub !== 'set') WP_CLI::error("unknown subcommand '$sub' (use: status get [<slug>] | status set <slug> <status>)");
      $slug = $args[1] ?? ''; $status = $args[2] ?? '';
      if (!lld_tools_card($slug)) WP_CLI::error("unknown tool '$slug'");
      if (!in_array($status, ['active', 'withdrawn'], true)) WP_CLI::error("status must be active or withdrawn (DEC-24)");
      $path = lld_tools_settings()['status_path'];
      $raw = is_readable($path) ? file_get_contents($path) : false;
      $doc = $raw ? json_decode($raw, true) : null;
      if (!is_array($doc) || !isset($doc['tools'])) WP_CLI::error("cannot read $path");
      $entry = $doc['tools'][$slug] ?? ['status' => 'active', 'approved_version' => '', 'message' => null, 'since' => null];
      if ($status === 'active') {
        $version = (string)($assoc['version'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $version)) WP_CLI::error('--version=<sha256 of the approved tool file> is required to set active');
        $deployed = lld_tools_deployed_version($slug);
        if ($version !== $deployed) WP_CLI::error("version $version is not an approved version of the deployed tool file (approved hash of the deployed file: " . ($deployed ?: 'unreadable') . "); only an approved, deployed version can be activated (DEC-10, DEC-71)");
        $entry['status'] = 'active'; $entry['approved_version'] = $version; $entry['message'] = null; $entry['since'] = gmdate('Y-m-d\TH:i:s\Z');
      } else {
        $entry['status'] = 'withdrawn'; $entry['message'] = isset($assoc['message']) ? (string)$assoc['message'] : 'unavailable.' . $slug; $entry['since'] = gmdate('Y-m-d\TH:i:s\Z');
      }
      $doc['tools'][$slug] = $entry; $doc['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
      $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
      $tmp = $path . '.tmp';
      if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) { @unlink($tmp); WP_CLI::error("could not write $path"); }
      WP_CLI::log("$slug: $status (since " . $entry['since'] . ")");
      // purge (DEC-31): the hub and landing pages plus any configured targets; LiteSpeed when present, otherwise a logged manual step
      $targets = array_merge([home_url('/health-tools/')], array_map(fn($c) => home_url($c['landing_url']), array_values(lld_tools_cards())), (array)lld_tools_settings()['purge_targets']);
      if (has_action('litespeed_purge_url') || class_exists('\LiteSpeed\Purge')) { foreach ($targets as $t) do_action('litespeed_purge_url', $t); WP_CLI::log('purged: ' . implode(', ', $targets)); }
      else WP_CLI::warning('LiteSpeed Cache not active here: purge ' . implode(', ', $targets) . ' manually (runbook)');
      WP_CLI::success("status.json updated");
    }
  }
  WP_CLI::add_command('lld', 'LLD_Tools_CLI');
}
