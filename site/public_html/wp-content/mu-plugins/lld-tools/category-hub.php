<?php
/**
 * Category hub block (lead package S2-L4, FR2-26, screen SCR-44; Interface Pack handover section 5.2, prototype package 3,
 * owner-approved 28 Oct 2026). `[lld_category_hub]` renders, for the category being viewed:
 *   1. the magazine hero: the flagship guide (7 of 12 columns, 1:1 featured image, "Medically reviewed" chip, title,
 *      excerpt, "Read full guide") beside two trending articles (5 of 12 columns, 96 px square thumbnails);
 *   2. the topic tool callout linking to the category's calculator (data/tool-cards.json);
 *   3. the Stage 2 track banner (dark green card, "Start 7-day trial" to /members/join/), shown while lld-members is active.
 * Per-category choices live in data/categories.json → tree[].hub {flagship, trending, tool, track} (owner question 36):
 * flagship defaults to the newest article whose review byline says it is medically reviewed, trending to the next two newest.
 *   wp lld hub apply [--dry-run] [--template=<id>] [--backup-dir=<dir>] | revert
 * places the block in a Composer row directly above the article loop of the Newspaper Cloud Library category template, after
 * saving the template to ~/lld-backups/<env>/. Theme files stay untouched (DEC-22).
 * The pure builders take plain arrays so they are tested without WordPress (tests/lead/TC-FR2-26-hub.test.mjs).
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- escaping outside WordPress ---------------------- */
/* Under WordPress these exist before any mu-plugin loads; the PHP CLI tests get the same contract. */
if (!function_exists('esc_html')) { function esc_html($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($s): string { $s = trim((string)$s); return preg_match('~^(https?:)?/|^#~i', $s) ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : ''; } }

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

/** The hub entry for a category slug: a top category's own `hub`, a child's parent's `hub`, with every key present. */
function lld_hub_entry(array $doc, string $slug): ?array {
  foreach ((array)($doc['tree'] ?? []) as $t) {
    $mine = (string)$t['slug'] === $slug; $child = null;
    foreach ((array)($t['children'] ?? []) as $c) if ((string)$c['slug'] === $slug) $child = $c;
    if (!$mine && !$child) continue;
    $hub = (array)($t['hub'] ?? []);
    return [
      'category' => (string)$t['slug'], 'name' => (string)($child['name'] ?? $t['name']), 'top_name' => (string)$t['name'],
      'flagship' => $mine ? ($hub['flagship'] ?? null) : null,          // a child's flagship is chosen from its own posts
      'trending' => $mine ? array_values((array)($hub['trending'] ?? [])) : [],
      'tool' => (string)($hub['tool'] ?? ''), 'track' => (string)($hub['track'] ?? ''),
    ];
  }
  return null;
}

/** Which posts the hero shows. $posts: [{id, slug, date (sortable), reviewed (bool)}] in any order. Returns ['flagship' =>
 *  id|null, 'trending' => [id, id]]: the named flagship (id or slug) when present, else the newest medically reviewed post,
 *  else the newest; the named trending ids when present, else the next two newest. Nothing is listed twice. */
function lld_hub_pick(array $posts, array $hub): array {
  $byId = []; foreach ($posts as $p) $byId[(int)$p['id']] = $p;
  usort($posts, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']) ?: ((int)$b['id'] <=> (int)$a['id']));
  $want = $hub['flagship'] ?? null; $flag = null;
  if ($want !== null && $want !== '') foreach ($posts as $p) if ((string)$p['id'] === (string)$want || (string)($p['slug'] ?? '') === (string)$want) { $flag = (int)$p['id']; break; }
  if ($flag === null) foreach ($posts as $p) if (!empty($p['reviewed'])) { $flag = (int)$p['id']; break; }
  if ($flag === null && $posts) $flag = (int)$posts[0]['id'];
  $trend = [];
  foreach ((array)($hub['trending'] ?? []) as $id) if (isset($byId[(int)$id]) && (int)$id !== $flag && !in_array((int)$id, $trend, true)) $trend[] = (int)$id;
  foreach ($posts as $p) { if (count($trend) >= 2) break; $id = (int)$p['id']; if ($id !== $flag && !in_array($id, $trend, true)) $trend[] = $id; }
  return ['flagship' => $flag, 'trending' => array_slice($trend, 0, 2)];
}

/** "n min read" from a word count (200 words a minute, never under 1). */
function lld_hub_minutes(int $words): int { return max(1, (int)ceil($words / 200)); }

/** The block's HTML from plain values. $m: category {slug, name}; flagship {id, url, title, excerpt, image (html|''), reviewed
 *  (bool), updated (text), minutes}; trending [{id, url, title, excerpt, image, reviewed, minutes}]; tool {slug, name, purpose,
 *  cta_label, url, minutes}|null; track {slug, title, url}|null. Every value is escaped here; `image` is the only HTML
 *  passed through (built by get_the_post_thumbnail). */
function lld_hub_markup(array $m): string {
  $cat = (array)($m['category'] ?? []); $f = $m['flagship'] ?? null;
  $out = '<section class="lld-hub" data-test="category-hub" data-category="' . esc_attr($cat['slug'] ?? '') . '">';
  if ($f) {
    $out .= '<div class="lld-hub__hero">';
    // left: the flagship guide (7 of 12)
    $out .= '<article class="lld-hub__flagship" data-test="hub-flagship" data-post-id="' . (int)$f['id'] . '">';
    $out .= '<a class="lld-hub__flagship-media" href="' . esc_url($f['url']) . '" tabindex="-1" aria-hidden="true">' . ($f['image'] !== '' ? $f['image'] : '<span class="lld-hub__noimg"></span>');
    $out .= '<span class="lld-hub__badge">Flagship guide</span><span class="lld-hub__time">' . (int)$f['minutes'] . ' min read</span></a>';
    $out .= '<div class="lld-hub__flagship-body"><p class="lld-hub__meta">';
    if (!empty($f['reviewed'])) $out .= '<span class="lld-hub__chip" data-test="hub-reviewed">Medically reviewed</span>';
    if (($f['updated'] ?? '') !== '') $out .= '<span class="lld-hub__updated">Updated ' . esc_html($f['updated']) . '</span>';
    $out .= '</p><h2 class="lld-hub__title"><a href="' . esc_url($f['url']) . '">' . esc_html($f['title']) . '</a></h2>';
    if (($f['excerpt'] ?? '') !== '') $out .= '<p class="lld-hub__excerpt">' . esc_html($f['excerpt']) . '</p>';
    $out .= '<p class="lld-hub__more"><a href="' . esc_url($f['url']) . '" data-test="hub-flagship-link">Read full guide <span aria-hidden="true">&rarr;</span></a></p></div></article>';
    // right: two trending articles (5 of 12)
    $out .= '<div class="lld-hub__trending">';
    foreach ((array)($m['trending'] ?? []) as $t) {
      $out .= '<article class="lld-hub__trend" data-test="hub-trending" data-post-id="' . (int)$t['id'] . '">';
      $out .= '<p class="lld-hub__meta"><span class="lld-hub__topic">' . esc_html($cat['name'] ?? '') . '</span><span class="lld-hub__updated">' . (int)$t['minutes'] . ' min read</span></p>';
      $out .= '<div class="lld-hub__trend-row"><div class="lld-hub__trend-text"><h3 class="lld-hub__trend-title"><a href="' . esc_url($t['url']) . '">' . esc_html($t['title']) . '</a></h3>';
      if (($t['excerpt'] ?? '') !== '') $out .= '<p class="lld-hub__excerpt">' . esc_html($t['excerpt']) . '</p>';
      $out .= '</div>';
      if (($t['image'] ?? '') !== '') $out .= '<a class="lld-hub__thumb" href="' . esc_url($t['url']) . '" tabindex="-1" aria-hidden="true">' . $t['image'] . '</a>';
      $out .= '</div><p class="lld-hub__more"><a href="' . esc_url($t['url']) . '">Read guide <span aria-hidden="true">&rarr;</span></a>';
      if (!empty($t['reviewed'])) $out .= '<span class="lld-hub__updated">Medically reviewed</span>';
      $out .= '</p></article>';
    }
    $out .= '</div></div>';
  }
  // the topic tool callout
  $tool = $m['tool'] ?? null;
  if ($tool) {
    $out .= '<aside class="lld-hub__tool" data-test="hub-tool" data-tool="' . esc_attr($tool['slug']) . '" aria-labelledby="lld-hub-tool-title">';
    $out .= '<div class="lld-hub__tool-text"><p class="lld-hub__meta"><span class="lld-hub__chip">Category tool</span><span class="lld-hub__updated">About ' . (int)$tool['minutes'] . ' minute' . ((int)$tool['minutes'] === 1 ? '' : 's') . '</span></p>';
    $out .= '<h3 class="lld-hub__tool-title" id="lld-hub-tool-title">' . esc_html($tool['name']) . '</h3><p class="lld-hub__tool-purpose">' . esc_html($tool['purpose']) . '</p></div>';
    $out .= '<a class="lld-hub__cta" href="' . esc_url($tool['url']) . '">' . esc_html($tool['cta_label']) . ' <span aria-hidden="true">&rarr;</span></a></aside>';
  }
  // the Stage 2 track banner (prototype copy; week 1 of every track is free, D3)
  $track = $m['track'] ?? null;
  if ($track) {
    $out .= '<aside class="lld-hub__track" data-test="hub-track" data-track="' . esc_attr($track['slug']) . '" aria-labelledby="lld-hub-track-title">';
    $out .= '<div class="lld-hub__track-text"><span class="lld-hub__chip lld-hub__chip--dark">4-week programme</span>';
    $out .= '<h3 class="lld-hub__track-title" id="lld-hub-track-title">Ready to put these ' . esc_html(mb_strtolower((string)($cat['name'] ?? ''))) . ' guides into practice?</h3>';
    $out .= '<p class="lld-hub__track-copy">Join the 4-week <strong>' . esc_html($track['title']) . '</strong> track: step-by-step daily habits, meal pacing and a weekly review. Week 1 is free, then Premium is US$7.99 a month.</p></div>';
    $out .= '<a class="lld-hub__cta" href="' . esc_url($track['url']) . '" data-test="hub-track-link">Start 7-day trial <span aria-hidden="true">&rarr;</span></a></aside>';
  }
  return $out . '</section>';
}

const LLD_HUB_ROW_CLASS = 'lld-hub-row';
/** Place the block's Composer row directly before the row that holds the article loop ([tdb_loop …]). Idempotent: content
 *  already carrying the row is returned unchanged. '' when the template has no loop row (the caller warns). */
function lld_hub_template_insert(string $content): string {
  if (strpos($content, LLD_HUB_ROW_CLASS) !== false) return $content;
  $loop = strpos($content, '[tdb_loop');
  if ($loop === false) return '';
  $start = strrpos(substr($content, 0, $loop), '[vc_row');
  if ($start === false) return '';
  $row = '[vc_row full_width="stretch_row_1200 td-stretch-content" gap="10" row_hide_on_pagination="yes" el_class="' . LLD_HUB_ROW_CLASS . '"][vc_column][lld_category_hub][/vc_column][/vc_row]';
  return substr($content, 0, $start) . $row . substr($content, $start);
}
/** Undo lld_hub_template_insert(): drop the hub row. */
function lld_hub_template_revert(string $content): string {
  return (string)preg_replace('/\[vc_row\b[^\]]*\bel_class="' . LLD_HUB_ROW_CLASS . '"[^\]]*\]\[vc_column\]\[lld_category_hub\]\[\/vc_column\]\[\/vc_row\]/', '', $content, 1);
}

/* ---------------------------------------------------------------- WordPress wiring -------------------------------- */
if (!function_exists('add_action')) return;

/** The hub's view of a post: plain values for lld_hub_markup(). */
function lld_hub_post_view($p, string $size, bool $eager): array {
  $text = wp_strip_all_tags(strip_shortcodes((string)$p->post_content));
  $excerpt = has_excerpt($p) ? wp_strip_all_tags((string)$p->post_excerpt) : wp_trim_words($text, 32, '…');
  $img = has_post_thumbnail($p) ? get_the_post_thumbnail($p, $size, ['class' => 'lld-hub__img', 'loading' => $eager ? 'eager' : 'lazy', 'decoding' => 'async', 'alt' => '']) : '';
  return ['id' => (int)$p->ID, 'url' => get_permalink($p), 'title' => html_entity_decode(get_the_title($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'excerpt' => html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'image' => $img, 'reviewed' => lld_tools_review_byline($p) !== '', 'updated' => get_the_modified_date('F Y', $p), 'minutes' => lld_hub_minutes(str_word_count($text))];
}

/** The tool callout for a card slug, or null when the tool is not shown (same rule as the Health Tools hub: Stage 1 tools
 *  always, a later tool only once its status entry is installed; and only while active). */
function lld_hub_tool_view(string $slug): ?array {
  $c = $slug !== '' ? lld_tools_card($slug) : null; if (!$c) return null;
  if (!in_array($slug, ['protein', 'diabetes'], true) && !isset(lld_tools_status()['tools'][$slug])) return null;
  if (lld_tools_tool_status($slug) !== 'active') return null;
  return ['slug' => $slug, 'name' => (string)$c['name'], 'purpose' => (string)$c['purpose'], 'cta_label' => (string)$c['cta_label'], 'url' => home_url((string)$c['landing_url']), 'minutes' => (int)$c['time_minutes']];
}

/** The track banner for a track key, or null while lld-members is inactive (the live site until the Stage 2 launch). */
function lld_hub_track_view(string $key): ?array {
  if ($key === '' || !class_exists('LLD_Members_Plugin')) return null;
  $titles = class_exists('LLD_Members_Programme') && method_exists('LLD_Members_Programme', 'track_titles') ? (array)LLD_Members_Programme::track_titles() : (array)(lld_categories_doc()['tracks'] ?? []);
  if (empty($titles[$key])) return null;
  return ['slug' => $key, 'title' => (string)$titles[$key], 'url' => home_url('/members/join/')];
}

function lld_hub_render(array $atts = []): string {
  $atts = shortcode_atts(['category' => ''], $atts, 'lld_category_hub');
  if ($atts['category'] !== '') $term = get_term_by('slug', $atts['category'], 'category');
  else { if (!is_category() || is_paged()) return ''; $term = get_queried_object(); }
  if (!$term || is_wp_error($term) || empty($term->term_id)) return '';
  $hub = lld_hub_entry(lld_categories_doc(), (string)$term->slug);
  if (!$hub) return '';   // not a category of the agreed tree (hidden display categories, retired terms)
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'cat' => (int)$term->term_id, 'posts_per_page' => 12, 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
  $posts = $q->posts; $byId = [];
  foreach ($posts as $p) $byId[(int)$p->ID] = $p;
  // a named flagship older than the twelve newest is still the flagship
  $want = $hub['flagship'];
  if ($want !== null && $want !== '') {
    $fp = is_numeric($want) ? get_post((int)$want) : get_page_by_path((string)$want, OBJECT, 'post');
    if ($fp && $fp->post_status === 'publish' && !isset($byId[(int)$fp->ID])) { $posts[] = $fp; $byId[(int)$fp->ID] = $fp; }
  }
  $plain = array_map(fn($p) => ['id' => (int)$p->ID, 'slug' => (string)$p->post_name, 'date' => (string)$p->post_date_gmt, 'reviewed' => lld_tools_review_byline($p) !== ''], $posts);
  $pick = lld_hub_pick($plain, $hub);
  $m = ['category' => ['slug' => (string)$term->slug, 'name' => html_entity_decode((string)$term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')],
    'flagship' => $pick['flagship'] ? lld_hub_post_view($byId[$pick['flagship']], 'medium_large', true) : null,
    'trending' => array_map(fn($id) => lld_hub_post_view($byId[$id], 'medium', false), $pick['trending']),
    'tool' => lld_hub_tool_view($hub['tool']), 'track' => lld_hub_track_view($hub['track'])];
  return lld_hub_markup($m);
}
add_shortcode('lld_category_hub', 'lld_hub_render');

if (defined('WP_CLI') && WP_CLI) {
  /** wp lld hub apply [--dry-run] [--template=<id>] [--backup-dir=<dir>] | revert: the block's row above the article loop of
   *  the Newspaper category template (the theme's tdb_category_template setting, or --template). The template is saved to
   *  <backup-dir>/hub-template-<id>-<time>.json first; apply keeps the untouched content in _lld_hub_original and revert
   *  restores it verbatim when present. Both post_content and the Composer's tdc_content copy change together. */
  WP_CLI::add_command('lld hub', function (array $args, array $assoc) {
    $sub = $args[0] ?? 'apply';
    if (!in_array($sub, ['apply', 'revert'], true)) WP_CLI::error('usage: wp lld hub apply [--dry-run] [--template=<id>] [--backup-dir=<dir>] | revert');
    $id = (int)($assoc['template'] ?? 0);
    if (!$id) { $opt = get_option('td_011'); $id = (int)preg_replace('/\D/', '', (string)(is_array($opt) ? ($opt['tdb_category_template'] ?? '') : '')); }
    $tpl = $id ? get_post($id) : null;
    if (!$tpl || $tpl->post_type !== 'tdb_templates') WP_CLI::error('no Newspaper category template found (tdb_category_template unset); pass --template=<id>');
    $before = (string)$tpl->post_content; $meta = (string)get_post_meta($tpl->ID, 'tdc_content', true);
    $saved = (string)get_post_meta($tpl->ID, '_lld_hub_original', true);
    if ($sub === 'apply') { $after = lld_hub_template_insert($before); $afterMeta = $meta !== '' ? lld_hub_template_insert($meta) : ''; }
    else { $after = ($saved !== '' && strpos($before, LLD_HUB_ROW_CLASS) !== false) ? $saved : lld_hub_template_revert($before); $afterMeta = $meta !== '' ? lld_hub_template_revert($meta) : ''; }
    if ($after === '') WP_CLI::error("template {$tpl->ID} ({$tpl->post_title}) has no article loop row ([tdb_loop]) to place the hub above — nothing changed");
    if (trim($after) === trim($before) && trim($afterMeta) === trim($meta)) { WP_CLI::log("hub: unchanged (template {$tpl->ID})"); WP_CLI::success("hub $sub"); return; }
    if (!empty($assoc['dry-run'])) { WP_CLI::log("hub: would $sub on template {$tpl->ID} ({$tpl->post_title})" . ($meta !== '' ? ' and its tdc_content copy' : '')); WP_CLI::success("hub $sub (dry run)"); return; }
    // backup outside the web root, as wp lld menu apply does
    $env = defined('LLD_ENV') && LLD_ENV === 'staging' ? 'staging' : 'production';
    $dir = rtrim((string)($assoc['backup-dir'] ?? ((getenv('HOME') ?: dirname(rtrim(ABSPATH, '/\\'), 3)) . "/lld-backups/$env")), '/\\');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) WP_CLI::error("cannot create backup folder $dir");
    $file = "$dir/hub-template-{$tpl->ID}-" . gmdate('Ymd\THis\Z') . '.json';
    $backup = ['template_id' => (int)$tpl->ID, 'title' => $tpl->post_title, 'saved_at' => gmdate('c'), 'post_content' => $before, 'tdc_content' => $meta];
    if (@file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) WP_CLI::error("could not write backup $file");
    @chmod($file, 0600);
    WP_CLI::log("backup: $file");
    if (function_exists('kses_remove_filters')) kses_remove_filters();
    if ($sub === 'apply' && $saved === '') update_post_meta($tpl->ID, '_lld_hub_original', wp_slash($before));
    $r = wp_update_post(wp_slash(['ID' => $tpl->ID, 'post_content' => $after]), true);
    if (is_wp_error($r)) WP_CLI::error('hub: ' . $r->get_error_message());
    if ($meta !== '') update_post_meta($tpl->ID, 'tdc_content', wp_slash($afterMeta));
    if ($sub === 'revert') delete_post_meta($tpl->ID, '_lld_hub_original');
    if (has_action('litespeed_purge_all')) do_action('litespeed_purge_all');
    WP_CLI::success("hub $sub (template {$tpl->ID}); undo with: wp lld hub revert");
  });
}
