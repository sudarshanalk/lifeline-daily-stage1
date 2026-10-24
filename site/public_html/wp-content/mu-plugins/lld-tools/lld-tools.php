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
  'review.byline' => 'Reviewed by %1$s on %2$s',
];
/** The approved unavailable text for a status.json `message` key (DEC-72): every key resolves to the approved body, never free text. */
function lld_tools_message(?string $key): string {
  return LLD_TOOLS_MESSAGES['unavailable.body'];
}

/* ---------------------------------------------------------------- review metadata (FR1-18, SCH-13) ---------------- */
function lld_tools_meta_doc(): array {
  static $doc = null;
  if ($doc === null) {
    $doc = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/meta.json'), true);
    if (!is_array($doc)) { trigger_error('lld-tools: data/meta.json is not valid JSON; SCH-13 meta not registered', E_USER_WARNING); $doc = ['meta' => []]; }
  }
  return $doc;
}
/** Sanitiser for one SCH-13 key: enum keys keep only allowed values, dates keep only YYYY-MM-DD, strings are plain text. */
function lld_tools_sanitize_meta(string $key, $value): string {
  foreach (lld_tools_meta_doc()['meta'] as $m) {
    if ($m['key'] !== $key) continue;
    $v = trim(wp_strip_all_tags((string)$value));
    if (isset($m['enum'])) return in_array($v, $m['enum'], true) ? $v : '';
    if (($m['type'] ?? '') === 'date') return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $d) && checkdate((int)$d[2], (int)$d[3], (int)$d[1])) ? $v : '';
    return mb_substr($v, 0, 200);
  }
  return '';
}
function lld_tools_register_meta(): void {
  foreach (lld_tools_meta_doc()['meta'] as $m) {
    foreach ($m['applies_to'] as $type) {
      register_post_meta($type, $m['key'], [
        'type' => 'string', 'single' => true, 'show_in_rest' => true,
        'sanitize_callback' => function ($value) use ($m) { return lld_tools_sanitize_meta($m['key'], $value); },
        'auth_callback' => function () { return current_user_can('edit_others_posts'); },   // editors and above (FR1-18)
      ]);
    }
  }
}
if (function_exists('add_action')) add_action('init', 'lld_tools_register_meta');
function lld_tools_review_approved($post): bool { return $post && get_post_meta($post->ID, 'lld_review_status', true) === 'approved'; }
/** "Reviewed by {owner} on {date}" for a post/page carrying both meta values, else ''. */
function lld_tools_review_byline($post): string {
  if (!$post) return '';
  $owner = (string)get_post_meta($post->ID, 'lld_review_owner', true); $date = (string)get_post_meta($post->ID, 'lld_reviewed_date', true);
  if ($owner === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
  $shown = function_exists('date_i18n') ? date_i18n('j F Y', strtotime($date . ' 12:00:00')) : $date;
  return '<p class="lld-review-byline" data-test="review-byline">' . sprintf(esc_html(LLD_TOOLS_MESSAGES['review.byline']), esc_html($owner), '<time datetime="' . esc_attr($date) . '">' . esc_html($shown) . '</time>') . '</p>';
}
if (function_exists('add_filter')) add_filter('the_content', function ($content) {
  if (!function_exists('is_singular') || !is_singular(['post', 'page']) || !in_the_loop() || !is_main_query()) return $content;
  return $content . lld_tools_review_byline(get_post());
}, 20);
/** WP-CLI does not check capabilities, so nothing stops `wp post update <id> --post_status=publish --user=<contributor>`.
 *  Under WP-CLI only, refuse a transition of a post or page to `publish` by a logged-in user lacking the publish capability
 *  (FR1-18: contributor drafts, editor publishes). wp-admin, REST and XML-RPC already enforce this themselves, and core's own
 *  published inserts (oembed_cache, cron, scheduled posts) are never touched. */
if (function_exists('add_filter') && defined('WP_CLI') && WP_CLI) add_filter('wp_insert_post_data', function ($data, $postarr) {
  if (($data['post_status'] ?? '') !== 'publish' || !in_array($data['post_type'] ?? '', ['post', 'page'], true) || get_current_user_id() === 0) return $data;
  if (!empty($postarr['ID']) && get_post_status((int)$postarr['ID']) === 'publish') return $data;
  $pto = get_post_type_object($data['post_type']);
  if ($pto && !current_user_can($pto->cap->publish_posts)) WP_CLI::error('Sorry, you are not allowed to publish this ' . $data['post_type'] . '. Contributors can save drafts; an editor publishes.');
  return $data;
}, 10, 2);

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
    $h = $compact ? 'h3' : 'h2';   // NFR1-05 AS-06: on the hub the cards follow the page h1; on the homepage they sit under the h2 "Tools"
    $out .= '<' . $h . ' class="lld-tool-card__name" data-test="card-name">' . esc_html($c['name']) . '</' . $h . '>';
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
    $out .= '<h2 class="lld-tool-card__name" data-test="card-name">' . esc_html($l['label'] ?? '') . '</h2>';   // planned chips appear on the hub only
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
  // candidates: the card's related article, then posts whose link-map decision is this tool (FR1-05); only published posts with
  // review status `approved` (FR1-18)
  $items = []; $limit = (int)(lld_tools_links_doc()['related_limit'] ?? 3);
  $candidates = [(int)$c['related_post_id']];
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true, 'ignore_sticky_posts' => true,
    'meta_query' => [['key' => 'lld_review_status', 'value' => 'approved']]]);
  foreach ($q->posts as $p) if (lld_tools_link_decision($p)['decision'] === $atts['slug']) $candidates[] = (int)$p->ID;
  foreach (array_unique(array_filter($candidates)) as $id) {
    $post = get_post($id);
    if (!$post || $post->post_status !== 'publish' || !lld_tools_review_approved($post)) continue;
    $items[] = '<li data-post-id="' . (int)$post->ID . '"><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a>' . lld_tools_review_byline($post) . '</li>';
    if (count($items) >= $limit) break;
  }
  if (!$items) return '<p data-test="related-reading">More reading on this topic is coming soon.</p>';
  return '<ul class="lld-related" data-test="related-reading">' . implode('', $items) . '</ul>';
}
add_shortcode('lld_tool_related', 'lld_tools_render_related');

/* ---------------------------------------------------------------- [lld_tool_cta slug=… post_id=…] (FR1-05 extends) */
function lld_tools_render_cta(array $atts = []): string {
  $atts = shortcode_atts(['slug' => '', 'post_id' => ''], $atts, 'lld_tool_cta');
  $c = lld_tools_card($atts['slug']); if (!$c) return '';
  $href = $c['landing_url'];
  if ((int)$atts['post_id'] > 0) $href .= '?' . http_build_query(array_merge(lld_tools_links_doc()['utm'] ?? ['utm_source' => 'article'], ['utm_content' => (int)$atts['post_id']]));
  return '<p class="lld-tool-cta" data-test="tool-cta" data-tool="' . esc_attr($atts['slug']) . '"><a href="' . esc_url($href) . '">' . esc_html($c['cta_label']) . ': ' . esc_html($c['name']) . '</a></p>';
}
add_shortcode('lld_tool_cta', 'lld_tools_render_cta');

/* ---------------------------------------------------------------- [lld_learn_row limit=…] (FR1-03 "Learn" row) ---- */
function lld_tools_render_learn_row(array $atts = []): string {
  $atts = shortcode_atts(['limit' => '6'], $atts, 'lld_learn_row');
  $limit = max(1, min(12, (int)$atts['limit']));
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
  wp_enqueue_style('lld-tools', plugins_url('assets/lld-tools.css', __FILE__), [], LLD_TOOLS_VERSION);
});

/* ---------------------------------------------------------------- article ↔ tool links (FR1-05, DEC-13) ------------ */
function lld_tools_links_doc(): array { static $d = null; return $d ?? ($d = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/links.json'), true) ?: []); }
/** DEC-13: override meta, else tag rule (diabetes), else category rule (diabetes, then protein), else none. */
function lld_tools_link_decision($post): array {
  $doc = lld_tools_links_doc();
  $cats = wp_get_post_terms($post->ID, 'category', ['fields' => 'slugs']); $tags = wp_get_post_terms($post->ID, 'post_tag', ['fields' => 'slugs']);
  $cats = is_wp_error($cats) ? [] : $cats; $tags = is_wp_error($tags) ? [] : $tags;
  $override = (string)get_post_meta($post->ID, 'lld_tool_override', true);
  if (in_array($override, ['protein', 'diabetes', 'none'], true)) $decision = [$override, 'override'];
  elseif (array_intersect($tags, $doc['diabetes_tags'] ?? [])) $decision = ['diabetes', 'rule'];
  elseif (array_intersect($cats, $doc['diabetes_categories'] ?? [])) $decision = ['diabetes', 'rule'];
  elseif (array_intersect($cats, $doc['protein_categories'] ?? [])) $decision = ['protein', 'rule'];
  else $decision = ['none', 'rule'];
  $st = (string)get_post_meta($post->ID, 'lld_review_status', true);
  return ['wp_post_id' => (int)$post->ID, 'decision' => $decision[0], 'source' => $decision[1], 'categories' => array_values($cats), 'tags' => array_values($tags), 'review_status' => $st === '' ? null : $st];
}
/** Automatic CTA on qualifying published posts: after the first </h2>, else at the end. The article text itself is untouched. */
if (function_exists('add_filter')) add_filter('the_content', function ($content) {
  if (empty(lld_tools_links_doc()['auto_cta']) || !function_exists('is_singular') || !is_singular('post') || !in_the_loop() || !is_main_query()) return $content;
  $post = get_post(); if (!$post || $post->post_status !== 'publish') return $content;
  $d = lld_tools_link_decision($post); if ($d['decision'] === 'none') return $content;
  $cta = lld_tools_render_cta(['slug' => $d['decision'], 'post_id' => $post->ID]);
  $i = stripos($content, '</h2>');
  return $i === false ? $content . $cta : substr($content, 0, $i + 5) . $cta . substr($content, $i + 5);
}, 15);

/* ---------------------------------------------------------------- homepage (FR1-03, DEC-23): Composer template transform */
function lld_tools_home_doc(): array { return json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/home.json'), true) ?: []; }
/** Insert the Tools row (compact hub) and the Learn row directly after the first Composer row (the hero) and make the hero
 *  desktop-only (DEC-23: Tools directly below the header on mobile, directly after the hero on desktop). Nothing is duplicated:
 *  on phones the hero is hidden and the Learn row carries the latest posts. Idempotent: content already carrying the marker row
 *  is returned unchanged. Returns '' when the template has no Composer row (the caller warns). */
function lld_tools_home_content(string $content): string {
  $doc = lld_tools_home_doc();
  $marker = $doc['marker_class'] ?? 'lld-home-tools-row';
  if (strpos($content, $marker) !== false) return $content;
  if (!preg_match('/\[vc_row\b[^\]]*\].*?\[\/vc_row\]/s', $content, $m, PREG_OFFSET_CAPTURE)) return '';
  [$hero, $pos] = $m[0];
  $withClass = function (string $row, string $cls): string {
    return preg_match('/^\[vc_row\b[^\]]*\bel_class="([^"]*)"/', $row)
      ? preg_replace('/^(\[vc_row\b[^\]]*\bel_class=")([^"]*)"/', '$1$2 ' . $cls . '"', $row, 1)
      : preg_replace('/^\[vc_row\b/', '[vc_row el_class="' . $cls . '"', $row, 1);
  };
  $rowAttrs = $doc['row_attrs'] ?? 'full_width="stretch_row_1200 td-stretch-content"';
  $tools = '[vc_row ' . $rowAttrs . ' el_class="' . $marker . '"][vc_column width="1/1"]' . ($doc['tools_shortcode'] ?? '[lld_tools_hub compact=1]') . '[/vc_column][/vc_row]';
  $learn = '[vc_row ' . $rowAttrs . ' el_class="lld-home-learn-row"][vc_column width="1/1"]' . ($doc['learn_shortcode'] ?? '[lld_learn_row limit=6]') . '[/vc_column][/vc_row]';
  $new = $withClass($hero, 'lld-home-hero') . $tools . $learn;
  return substr($content, 0, $pos) . $new . substr($content, $pos + strlen($hero));
}
/** Undo lld_tools_home_content(): drop the Tools and Learn rows and the hero class (`wp lld home revert`). */
function lld_tools_home_revert(string $content): string {
  $doc = lld_tools_home_doc(); $marker = $doc['marker_class'] ?? 'lld-home-tools-row';
  if (strpos($content, $marker) === false) return $content;   // never transformed: nothing to undo
  foreach ([$marker, 'lld-home-learn-row'] as $cls) $content = preg_replace('/\[vc_row\b[^\]]*\bel_class="' . preg_quote($cls, '/') . '"[^\]]*\].*?\[\/vc_row\]/s', '', $content);
  $content = preg_replace('/(\[vc_row\b[^\]]*\bel_class=")lld-home-hero"/', '$1"', $content, 1);   // class added where none existed
  $content = preg_replace('/(\[vc_row\b[^\]]*\bel_class="[^"]*?) lld-home-hero"/', '$1"', $content, 1);   // class appended to an existing one
  return preg_replace('/\[vc_row el_class=""/', '[vc_row', $content, 1);
}

/* ---------------------------------------------------------------- category tidy-up (FR1-23, DEC-39…43) ------------ */
function lld_tools_tidy_doc(): array { static $d = null; return $d ?? ($d = json_decode((string)file_get_contents(LLD_TOOLS_DIR . '/data/tidy.json'), true) ?: []); }
/** Deleted category archives answer 301 to their DEC targets (they 404 once the term is gone). */
if (function_exists('add_action')) add_action('template_redirect', function () {
  if (!is_404()) return;
  $path = trailingslashit((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
  foreach ((lld_tools_tidy_doc()['delete'] ?? []) as $d) if ($path === $d['path']) { wp_redirect(home_url($d['redirect']), 301, 'lld-tools'); exit; }
});
/** Hidden archives (term meta lld_noindex = 1, set by tidy apply) are noindex: robots meta and X-Robots-Tag. */
function lld_tools_is_noindex_archive(): bool {
  if (!function_exists('is_category') || !is_category()) return false;
  $t = get_queried_object();
  return $t && !empty($t->term_id) && get_term_meta($t->term_id, 'lld_noindex', true) === '1';
}
if (function_exists('add_filter')) add_filter('wp_robots', function ($robots) { if (lld_tools_is_noindex_archive()) { $robots['noindex'] = true; $robots['follow'] = true; unset($robots['index']); } return $robots; }, 99);
if (function_exists('add_action')) add_action('template_redirect', function () { if (lld_tools_is_noindex_archive() && !headers_sent()) header('X-Robots-Tag: noindex, follow', true); }, 1);
/* Yoast SEO merges its own robots values into wp_robots at PHP_INT_MAX - 10 (its `index` would win over the core filter
 * above): set the directive in Yoast's own array too, so the robots meta says noindex whichever plugin renders it. */
if (function_exists('add_filter')) add_filter('wpseo_robots_array', function ($robots) { if (is_array($robots) && lld_tools_is_noindex_archive()) { $robots['index'] = 'noindex'; $robots['follow'] = 'follow'; } return $robots; }, 99);

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
      if (function_exists('kses_remove_filters')) kses_remove_filters();   // repository-owned content: save it verbatim
      // FR1-17 (DEC-12): the Medical Disclaimer & Urgent Care page is plugin-owned; created when missing
      if (!empty($doc['disclaimer'])) {
        $dp = $doc['disclaimer'];
        if (!get_page_by_path($dp['slug'], OBJECT, 'page') && empty($assoc['dry-run'])) {
          $id = wp_insert_post(wp_slash(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $dp['title'], 'post_name' => $dp['slug'], 'post_content' => $dp['content']]), true);
          if (is_wp_error($id)) WP_CLI::error('medical-disclaimer: ' . $id->get_error_message());
          WP_CLI::log("{$dp['slug']}: created (post $id)");
        }
        $targets[] = [$dp['slug'], $dp['content']];
      }
      foreach ($targets as [$pageSlug, $content]) {
        $page = get_page_by_path($pageSlug, OBJECT, 'page');
        if (!$page) { WP_CLI::warning("page '$pageSlug' not found — skipped"); continue; }
        $changed = trim((string)$page->post_content) !== trim($content);
        if (!$changed) { WP_CLI::log("$pageSlug: unchanged"); continue; }
        if (!empty($assoc['dry-run'])) { WP_CLI::log("$pageSlug: would update (" . strlen($content) . " bytes)"); continue; }
        $r = wp_update_post(wp_slash(['ID' => $page->ID, 'post_content' => $content]), true);
        if (is_wp_error($r)) WP_CLI::error("$pageSlug: " . $r->get_error_message());
        WP_CLI::log("$pageSlug: updated (post " . $page->ID . ")");
      }
      // landing pages carry lld_tool_slug (SCH-13: "marks landing pages")
      foreach ($doc['pages'] as $slug => $p) {
        $page = get_page_by_path($p['slug'], OBJECT, 'page');
        if ($page && empty($assoc['dry-run']) && get_post_meta($page->ID, 'lld_tool_slug', true) !== $slug) update_post_meta($page->ID, 'lld_tool_slug', $slug);
      }
      WP_CLI::success('pages applied');
    }
    /** wp lld home apply [--dry-run] | home revert: the homepage rows (FR1-03, DEC-23) on the static front page's Composer
     *  template. Separate from `pages apply` so landing-page work never changes the homepage. */
    public function home(array $args, array $assoc) {
      $sub = $args[0] ?? 'apply';
      if (!in_array($sub, ['apply', 'revert'], true)) WP_CLI::error("unknown subcommand '$sub' (use: home apply [--dry-run] | home revert)");
      if (get_option('show_on_front') !== 'page' || !($front = get_post((int)get_option('page_on_front')))) WP_CLI::error('no static front page (show_on_front != page)');
      $before = (string)$front->post_content;
      // apply keeps the untouched template in _lld_home_original; revert restores it verbatim when present (exact by construction)
      $saved = (string)get_post_meta($front->ID, '_lld_home_original', true);
      if ($sub === 'apply') $after = lld_tools_home_content($before);
      else $after = ($saved !== '' && strpos($before, lld_tools_home_doc()['marker_class'] ?? 'lld-home-tools-row') !== false) ? $saved : lld_tools_home_revert($before);
      if ($after === '') WP_CLI::error('the front page has no Composer row ([vc_row]) to place the Tools row after — nothing changed');
      if (trim($after) === trim($before)) { WP_CLI::log("home: unchanged (post {$front->ID})"); WP_CLI::success("home $sub"); return; }
      if (!empty($assoc['dry-run'])) { WP_CLI::log("home: would update (post {$front->ID})"); return; }
      if (function_exists('kses_remove_filters')) kses_remove_filters();
      if ($sub === 'apply' && $saved === '') update_post_meta($front->ID, '_lld_home_original', wp_slash($before));
      $r = wp_update_post(wp_slash(['ID' => $front->ID, 'post_content' => $after]), true);
      if (is_wp_error($r)) WP_CLI::error('home: ' . $r->get_error_message());
      if ($sub === 'revert') delete_post_meta($front->ID, '_lld_home_original');
      WP_CLI::success("home $sub (post {$front->ID})");
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
  /** wp lld review-report [--format=json|table]: posts and pages whose next review date has passed or whose review status is
   *  not `approved` (FR1-18). Each row: id, type, status, title, owner, next_review_date, review_status, reasons. */
  WP_CLI::add_command('lld review-report', function (array $args, array $assoc) {
    $today = current_time('Y-m-d'); $rows = [];   // the site's own date (Europe/London), not UTC
    $q = new WP_Query(['post_type' => ['post', 'page'], 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'], 'posts_per_page' => -1, 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC']);
    foreach ($q->posts as $p) {
      $next = (string)get_post_meta($p->ID, 'lld_next_review_date', true); $st = (string)get_post_meta($p->ID, 'lld_review_status', true);
      $reasons = [];
      if ($next !== '' && $next < $today) $reasons[] = 'review overdue';
      if ($st === '') $reasons[] = 'not reviewed'; elseif ($st !== 'approved') $reasons[] = 'status ' . $st;
      if (!$reasons) continue;
      $rows[] = ['id' => $p->ID, 'type' => $p->post_type, 'status' => $p->post_status, 'title' => get_the_title($p), 'owner' => (string)get_post_meta($p->ID, 'lld_review_owner', true), 'next_review_date' => $next, 'review_status' => $st, 'reasons' => implode('; ', $reasons)];
    }
    WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['id', 'type', 'status', 'title', 'owner', 'next_review_date', 'review_status', 'reasons']);
  });
  /** wp lld tidy apply [--dry-run] [--include-drafts] [--planned-out=<file>]: the FR1-23 editorial category changes, scripted and re-runnable.
   *  Refuses (exit 1, nothing changed) while any object is still assigned to a category it would delete (no post silently
   *  changes category). A second run changes nothing. */
  WP_CLI::add_command('lld tidy', function (array $args, array $assoc) {
    if (($args[0] ?? 'apply') !== 'apply') WP_CLI::error('usage: wp lld tidy apply [--dry-run] [--include-drafts] [--planned-out=<file>]');
    $doc = lld_tools_tidy_doc(); $dry = !empty($assoc['dry-run']); $changes = [];
    $wellness = get_term_by('slug', $doc['default_category'], 'category');
    if (!$wellness) WP_CLI::error("default category '{$doc['default_category']}' not found");
    // 1. pre-flight: no published / scheduled / private / pending post may still be assigned to a category that will be deleted
    //    (FR1-23: no post changes its categories). Drafts are listed; they move to the default category only with
    //    --include-drafts (WordPress reassigns a post left without a category), so the operator decides (DEC-79 proposal).
    $blocked = []; $drafts = [];
    foreach ($doc['delete'] as $d) {
      $t = get_term_by('slug', $d['slug'], 'category'); if (!$t) continue;
      foreach (array_map('intval', (array)get_objects_in_term($t->term_id, 'category')) as $oid) {
        $st = get_post_status($oid); if ($st === false || $st === 'trash') continue;
        if (in_array($st, ['draft', 'auto-draft'], true)) $drafts[$d['slug']][] = $oid; else $blocked[$d['slug']][] = $oid . ':' . $st;
      }
    }
    $fmt = function ($m) { return implode('; ', array_map(function ($k, $v) { return $k . ' (' . implode(', ', $v) . ')'; }, array_keys($m), $m)); };
    $refusals = [];
    if ($blocked) $refusals[] = 'refusing to delete categories that still have posts: ' . $fmt($blocked) . ' — reassign them by hand first (FR1-23: no post changes its categories)';
    if ($drafts && empty($assoc['include-drafts'])) $refusals[] = 'drafts are assigned to categories that will be deleted: ' . $fmt($drafts) . ' — re-run with --include-drafts to let them move to the default category, or reassign them first';
    // a real run stops here; a dry run prints the whole plan (drafts included) and then exits with the real run's verdict
    if ($refusals && !$dry) WP_CLI::error(implode("
", $refusals));
    foreach ($drafts as $slug => $ids) $changes[] = "drafts in $slug move to the default category" . (empty($assoc['include-drafts']) ? ' (only with --include-drafts)' : '') . ': ' . implode(', ', $ids);
    // 2. default category first (WordPress refuses to delete the current default)
    if ((int)get_option('default_category') !== (int)$wellness->term_id) { $changes[] = "default_category -> {$doc['default_category']} ({$wellness->term_id})"; if (!$dry) update_option('default_category', (int)$wellness->term_id); }
    // 3. delete the empty categories
    foreach ($doc['delete'] as $d) {
      $t = get_term_by('slug', $d['slug'], 'category'); if (!$t) continue;
      $changes[] = "delete category {$d['slug']} ({$t->term_id}) -> 301 {$d['path']} => {$d['redirect']} ({$d['dec']})";
      if (!$dry) { $r = wp_delete_term($t->term_id, 'category'); if (is_wp_error($r) || $r === false) WP_CLI::error("could not delete {$d['slug']}: " . (is_wp_error($r) ? $r->get_error_message() : 'refused')); }
    }
    // 4. hidden archives: out of the primary menu, noindex, term kept
    $menu = wp_get_nav_menu_object($doc['primary_menu']);
    foreach ($doc['hide_noindex'] as $h) {
      $t = get_term_by('slug', $h['slug'], 'category'); if (!$t) { WP_CLI::warning("category {$h['slug']} not found"); continue; }
      if ($menu) foreach ((array)wp_get_nav_menu_items($menu->term_id) as $item) {
        $hit = ($item->object === 'category' && (int)$item->object_id === (int)$t->term_id) || preg_match('#/' . preg_quote($h['slug'], '#') . '/?$#', (string)$item->url);
        if ($hit) { $changes[] = "remove menu item {$item->ID} ({$item->title}) from {$doc['primary_menu']}"; if (!$dry) wp_delete_post($item->ID, true); }
      }
      if (get_term_meta($t->term_id, 'lld_noindex', true) !== '1') { $changes[] = "noindex category {$h['slug']} ({$h['dec']})"; if (!$dry) update_term_meta($t->term_id, 'lld_noindex', '1'); }
    }
    if (!empty($assoc['planned-out'])) {
      file_put_contents($assoc['planned-out'], json_encode(['removed_at' => gmdate('Y-m-d'), 'categories' => $doc['planned']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
      WP_CLI::log('planned categories written to ' . $assoc['planned-out']);
    }
    foreach ($changes as $c) WP_CLI::log(($dry ? 'would: ' : '') . $c);
    if ($refusals) WP_CLI::error('dry run: the real run would refuse — ' . implode("
", $refusals));
    WP_CLI::success($changes ? count($changes) . ' change(s)' . ($dry ? ' (dry run)' : '') : 'nothing to change');
  });
  /** wp lld links generate [--output=<file>]: the SCH-05b link map for every published post (FR1-05, DEC-13). */
  WP_CLI::add_command('lld links generate', function (array $args, array $assoc) {
    $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true]);
    $doc = ['generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'rule_version' => (string)(lld_tools_links_doc()['rule_version'] ?? 'DEC-13'), 'links' => array_map('lld_tools_link_decision', $q->posts)];
    $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (!empty($assoc['output'])) { file_put_contents($assoc['output'], $json); WP_CLI::success(count($doc['links']) . ' links written to ' . $assoc['output']); }
    else WP_CLI::line(rtrim($json));
  });
  /** wp lld meta list: the registered SCH-13 meta keys as {meta:[{key, applies_to, type, enum?, sanitized}]} (TC-FR1-18-AS-05). */
  WP_CLI::add_command('lld meta list', function () {
    $out = ['meta' => []];
    foreach (lld_tools_meta_doc()['meta'] as $m) {
      $applies = [];
      foreach (['post', 'page'] as $type) { $reg = get_registered_meta_keys('post', $type); if (isset($reg[$m['key']]) && !empty($reg[$m['key']]['sanitize_callback'])) $applies[] = $type; }
      $row = ['key' => $m['key'], 'applies_to' => $applies, 'type' => $m['type']];
      if (isset($m['enum'])) $row['enum'] = $m['enum'];
      $out['meta'][] = $row;
    }
    WP_CLI::line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  });
  WP_CLI::add_command('lld', 'LLD_Tools_CLI');
}
