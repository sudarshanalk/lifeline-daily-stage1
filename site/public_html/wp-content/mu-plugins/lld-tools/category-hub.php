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

/** Default clinical mission descriptions per category slug when WordPress term description is empty. */
function lld_hub_default_description(string $slug, string $topSlug = ''): string {
  $map = [
    'weight-healthy-eating' => 'Evidence-based nutrition, protein targets, satiety strategies, and sustainable weight management guides — written in plain English and reviewed against clinical guidelines.',
    'healthy-eating' => 'Practical meal building, balanced plates, whole-food swaps, and everyday nutrition habits grounded in clinical dietary guidance.',
    'nutrition' => 'Clear, evidence-based guides to macronutrients, micronutrients, dietary fibre, and how food fuels long-term metabolic health.',
    'weight-management' => 'Sustainable, muscle-preserving approaches to healthy weight loss, waist-to-height targets, and appetite regulation without crash diets.',
    'blood-sugar-metabolic-health' => 'Practical guides to glucose balance, insulin sensitivity, HbA1c, and type 2 diabetes prevention — grounded in clinical risk models and everyday nutrition.',
    'diabetes-prevention' => 'Actionable steps to lower type 2 diabetes risk through meal timing, post-meal movement, fibre intake, and weight management.',
    'metabolic-health' => 'Evidence-based explanations of insulin resistance, metabolic flexibility, triglycerides, and how daily habits shape cardiometabolic risk.',
    'fitness-strength' => 'Progressive strength training, daily movement habits, mobility, and recovery protocols to preserve muscle mass and functional fitness at every age.',
    'exercise' => 'Structured cardio and conditioning routines designed for heart health, stamina, and consistent weekly energy expenditure.',
    'strength' => 'Resistance training fundamentals, progressive overload, and muscle protein synthesis guides for lifelong strength and bone density.',
    'movement' => 'Low-barrier daily walking, posture, joint mobility, and non-exercise activity habits that fit around a busy schedule.',
    'sleep-stress' => 'Circadian rhythm alignment, sleep hygiene, nervous-system regulation, and evidence-based stress recovery for restorative energy.',
    'sleep' => 'Clinical sleep architecture, evening wind-down routines, light exposure, and practical fixes for waking refreshed.',
    'stress' => 'Physiological stress management, cortisol regulation, breathwork, and realistic boundaries to protect physical and mental recovery.',
    'mental-wellbeing' => 'Evidence-informed guides on mood resilience, cognitive focus, social connection, and daily habits that support emotional balance.',
    'heart-health' => 'Clear, clinically reviewed guidance on blood pressure, ApoB and LDL cholesterol, vascular health, and cardiovascular risk reduction.',
    'blood-pressure' => 'Dietary sodium-potassium balance, aerobic conditioning, and home monitoring strategies for healthy blood pressure.',
    'cholesterol' => 'Understanding lipid panels, soluble fibre, dietary fats, and lifestyle interventions that improve cholesterol profiles.',
    'gut-health' => 'Microbiome diversity, prebiotic and fermented foods, digestive comfort, and the gut-brain axis explained without hype.',
    'healthy-ageing' => 'Longevity habits that protect muscle mass, cognitive sharpness, bone density, and independence across every decade of life.',
    'longevity' => 'Evidence-graded lifestyle habits linked to healthspan, cardiorespiratory fitness, and cellular resilience.',
    'sex-relationships' => 'Clinically reviewed, stigma-free guides to sexual health, hormonal changes, communication, and supportive relationships.',
    'health-conditions' => 'Plain-English overviews of common chronic conditions, symptom red flags, and evidence-based questions to bring to your clinician.',
    'other-conditions' => 'Practical, medically reviewed guides to managing everyday symptoms and understanding when to seek clinical care.',
  ];
  return $map[$slug] ?? $map[$topSlug] ?? 'Evidence-based health guides written in plain English and reviewed against clinical guidelines.';
}

/** Icon for a tool slug inside the Category Tool callout. */
function lld_hub_tool_icon(string $slug): string {
  return match ($slug) {
    'protein' => '🥩',
    'diabetes' => '🩺',
    'healthy-weight' => '⚖️',
    default => '🧭',
  };
}

/** The block's HTML from plain values. $m: category {slug, name}; flagship {id, url, title, excerpt, image (html|''), reviewed
 *  (bool), updated (text), minutes}; trending [{id, url, title, excerpt, image, reviewed, minutes}]; tool {slug, name, purpose,
 *  cta_label, url, minutes}|null; track {slug, title, url}|null; optional runtime keys header {...} and articles [...].
 *  Every value is escaped here; `image` is the only HTML passed through (built by get_the_post_thumbnail). */
function lld_hub_markup(array $m): string {
  $cat = (array)($m['category'] ?? []); $f = $m['flagship'] ?? null;
  $out = '<section class="lld-hub" data-test="category-hub" data-category="' . esc_attr($cat['slug'] ?? '') . '">';
  // optional runtime Category Intro Header (rendered on live WordPress category archives)
  $hdr = $m['header'] ?? null;
  if (is_array($hdr) && !empty($hdr)) {
    $total = (int)($hdr['total_count'] ?? 0);
    $out .= '<header class="lld-hub__intro" data-test="hub-intro">';
    $out .= '<div class="lld-hub__header-top">';
    $out .= '<nav class="lld-hub__crumbs" aria-label="Breadcrumb"><a href="' . esc_url($hdr['home_url'] ?? '/') . '">Home</a>';
    if (!empty($hdr['parent_name']) && !empty($hdr['parent_url'])) {
      $out .= '<span class="lld-hub__crumb-sep" aria-hidden="true">&rsaquo;</span><a href="' . esc_url($hdr['parent_url']) . '">' . esc_html($hdr['parent_name']) . '</a>';
    }
    $out .= '<span class="lld-hub__crumb-sep" aria-hidden="true">&rsaquo;</span><span class="lld-hub__crumb-current" aria-current="page">' . esc_html($cat['name'] ?? '') . '</span></nav>';
    $out .= '<span class="lld-hub__header-stat"><span class="lld-hub__stat-dot" aria-hidden="true"></span>' . $total . ' Guide' . ($total === 1 ? '' : 's') . ' &bull; Clinically Reviewed</span>';
    $out .= '</div>';
    $out .= '<h1 class="lld-hub__h1">' . esc_html($cat['name'] ?? '') . '</h1>';
    if (!empty($hdr['description'])) {
      $out .= '<p class="lld-hub__desc">' . esc_html($hdr['description']) . '</p>';
    }
    if (!empty($hdr['pills']) && is_array($hdr['pills'])) {
      $out .= '<div class="lld-hub__pills" role="navigation" aria-label="Category sub-topics">';
      $out .= '<span class="lld-hub__pills-label">' . esc_html($hdr['pills_label'] ?? 'Sub-Topics:') . '</span>';
      foreach ($hdr['pills'] as $pill) {
        $active = !empty($pill['active']) ? ' is-active' : '';
        $aria = !empty($pill['active']) ? ' aria-current="page"' : '';
        $out .= '<a class="lld-hub__pill' . $active . '" href="' . esc_url($pill['url'] ?? '#') . '"' . $aria . '>' . esc_html($pill['label'] ?? '') . '</a>';
      }
      $out .= '</div>';
    }
    $out .= '</header>';
  }
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
      $topicLabel = (string)($t['category_label'] ?? $cat['name'] ?? '');
      $out .= '<article class="lld-hub__trend" data-test="hub-trending" data-post-id="' . (int)$t['id'] . '">';
      $out .= '<p class="lld-hub__meta"><span class="lld-hub__topic">' . esc_html($topicLabel) . '</span><span class="lld-hub__updated">' . (int)$t['minutes'] . ' min read</span></p>';
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
    $icon = lld_hub_tool_icon((string)($tool['slug'] ?? ''));
    $out .= '<aside class="lld-hub__tool" data-test="hub-tool" data-tool="' . esc_attr($tool['slug']) . '" aria-labelledby="lld-hub-tool-title">';
    $out .= '<div class="lld-hub__tool-icon" aria-hidden="true">' . $icon . '</div>';
    $out .= '<div class="lld-hub__tool-text"><p class="lld-hub__meta"><span class="lld-hub__chip">Category tool</span><span class="lld-hub__updated">About ' . (int)$tool['minutes'] . ' minute' . ((int)$tool['minutes'] === 1 ? '' : 's') . '</span></p>';
    $out .= '<h3 class="lld-hub__tool-title" id="lld-hub-tool-title">' . esc_html($tool['name']) . '</h3><p class="lld-hub__tool-purpose">' . esc_html($tool['purpose']) . '</p></div>';
    $out .= '<a class="lld-hub__cta" href="' . esc_url($tool['url']) . '">' . esc_html($tool['cta_label']) . ' <span aria-hidden="true">&rarr;</span></a></aside>';
  }
  // optional runtime 3-Column Latest Guides Grid (rendered on live WordPress category archives)
  $articles = $m['articles'] ?? null;
  if (is_array($articles) && !empty($articles)) {
    $shownCount = count($articles);
    $totalGuides = (int)($m['total_count'] ?? $shownCount);
    if ($totalGuides < $shownCount) $totalGuides = $shownCount;
    $out .= '<div class="lld-hub__grid-wrap" data-test="hub-articles">';
    $out .= '<div class="lld-hub__grid-head">';
    $out .= '<h2 class="lld-hub__grid-heading">Latest Guides in ' . esc_html($cat['name'] ?? '') . '</h2>';
    $out .= '<span class="lld-hub__grid-count">Showing ' . $shownCount . ' of ' . $totalGuides . ' guide' . ($totalGuides === 1 ? '' : 's') . '</span>';
    $out .= '</div>';
    $out .= '<div class="lld-hub__grid">';
    foreach ($articles as $a) {
      $badge = (string)($a['category_label'] ?? $cat['name'] ?? '');
      $out .= '<article class="lld-hub__card" data-post-id="' . (int)$a['id'] . '">';
      $out .= '<a class="lld-hub__card-media" href="' . esc_url($a['url']) . '" tabindex="-1" aria-hidden="true">';
      $out .= ($a['image'] ?? '') !== '' ? $a['image'] : '<span class="lld-hub__noimg"></span>';
      if ($badge !== '') $out .= '<span class="lld-hub__card-cat">' . esc_html($badge) . '</span>';
      $out .= '</a>';
      $out .= '<div class="lld-hub__card-body">';
      $out .= '<div class="lld-hub__card-meta"><span>' . (int)$a['minutes'] . ' min read</span>';
      if (!empty($a['reviewed'])) {
        $out .= '<span>&bull;</span><span class="lld-hub__card-reviewed">Medically Reviewed</span>';
      } elseif (($a['updated'] ?? '') !== '') {
        $out .= '<span>&bull;</span><span>Updated ' . esc_html($a['updated']) . '</span>';
      }
      $out .= '</div>';
      $out .= '<h3 class="lld-hub__card-title"><a href="' . esc_url($a['url']) . '">' . esc_html($a['title']) . '</a></h3>';
      if (($a['excerpt'] ?? '') !== '') $out .= '<p class="lld-hub__card-excerpt">' . esc_html($a['excerpt']) . '</p>';
      $out .= '<div class="lld-hub__card-foot"><a class="lld-hub__card-link" href="' . esc_url($a['url']) . '">Read guide <span aria-hidden="true">&rarr;</span></a></div>';
      $out .= '</div></article>';
    }
    $out .= '</div></div>';
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

/** Primary or specific child category label for a post inside a category hub. */
function lld_hub_post_category_label($p, string $fallback = ''): string {
  if (!function_exists('wp_get_post_terms')) return $fallback;
  $hidden = (array)(lld_categories_doc()['hidden'] ?? ['featured', 'trending', 'video']);
  $terms = wp_get_post_terms((int)$p->ID, 'category');
  if (is_wp_error($terms) || empty($terms)) return $fallback;
  // Prefer a subcategory (parent > 0) so e.g. Sleep & Stress shows 'Sleep', 'Stress', or 'Mental Wellbeing'
  foreach ($terms as $t) {
    if (!in_array((string)$t->slug, $hidden, true) && (int)$t->parent > 0) {
      return html_entity_decode((string)$t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
  }
  foreach ($terms as $t) {
    if (!in_array((string)$t->slug, $hidden, true)) {
      return html_entity_decode((string)$t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
  }
  return $fallback;
}

/** The hub's view of a post: plain values for lld_hub_markup(). */
function lld_hub_post_view($p, string $size, bool $eager, string $fallbackCat = '', string $sizesAttr = ''): array {
  $text = wp_strip_all_tags(strip_shortcodes((string)$p->post_content));
  $excerpt = has_excerpt($p) ? wp_strip_all_tags((string)$p->post_excerpt) : wp_trim_words($text, 32, '…');
  $attr = ['class' => 'lld-hub__img', 'loading' => $eager ? 'eager' : 'lazy', 'decoding' => 'async', 'data-no-lazy' => '1', 'alt' => ''];
  if ($sizesAttr !== '') $attr['sizes'] = $sizesAttr;
  $img = has_post_thumbnail($p) ? get_the_post_thumbnail($p, $size, $attr) : '';
  return ['id' => (int)$p->ID, 'url' => get_permalink($p), 'title' => html_entity_decode(get_the_title($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'excerpt' => html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'image' => $img, 'reviewed' => lld_tools_review_byline($p) !== '', 'updated' => get_the_modified_date('F Y', $p), 'minutes' => lld_hub_minutes(str_word_count($text)),
    'category_label' => lld_hub_post_category_label($p, $fallbackCat)];
}

/** Build the Category Intro Header model (breadcrumbs, total count, clinical description, and sub-topic filter pills). */
function lld_hub_header_view($term, array $hub, int $totalCount): array {
  $doc = lld_categories_doc();
  $slug = (string)$term->slug;
  $topSlug = (string)($hub['category'] ?? $slug);
  $isChild = $slug !== $topSlug;
  $topTerm = $isChild ? get_term_by('slug', $topSlug, 'category') : $term;
  $rawDesc = trim(wp_strip_all_tags((string)($term->description ?? '')));
  $desc = $rawDesc !== '' ? html_entity_decode($rawDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8') : lld_hub_default_description($slug, $topSlug);

  // Find tree entry for topSlug to build sub-topic pills
  $children = [];
  foreach ((array)($doc['tree'] ?? []) as $t) {
    if ((string)$t['slug'] === $topSlug) {
      $children = (array)($t['children'] ?? []);
      break;
    }
  }

  $pills = [];
  $pillsLabel = 'Sub-Topics:';
  if (!empty($children)) {
    // Count total in parent category (including children)
    $parentTotal = $totalCount;
    if ($isChild && $topTerm && !is_wp_error($topTerm)) {
      $pq = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'cat' => (int)$topTerm->term_id, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false]);
      $parentTotal = (int)$pq->found_posts;
    }
    $topUrl = ($topTerm && !is_wp_error($topTerm)) ? get_term_link($topTerm) : home_url("/category/$topSlug/");
    $pills[] = [
      'label' => 'All (' . $parentTotal . ')',
      'url' => is_wp_error($topUrl) ? home_url("/category/$topSlug/") : (string)$topUrl,
      'active' => !$isChild,
    ];
    foreach ($children as $c) {
      $ct = get_term_by('slug', (string)$c['slug'], 'category');
      if (!$ct || is_wp_error($ct)) continue;
      $cUrl = get_term_link($ct);
      $pills[] = [
        'label' => html_entity_decode((string)$c['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' (' . (int)$ct->count . ')',
        'url' => is_wp_error($cUrl) ? '#' : (string)$cUrl,
        'active' => $slug === (string)$c['slug'],
      ];
    }
  } else {
    // Top category with no subcategories (e.g. Gut Health, Sex & Relationships): show All (N) + related top categories
    $pillsLabel = 'Explore Topics:';
    $selfUrl = get_term_link($term);
    $pills[] = [
      'label' => 'All ' . html_entity_decode((string)$term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' (' . $totalCount . ')',
      'url' => is_wp_error($selfUrl) ? '#' : (string)$selfUrl,
      'active' => true,
    ];
    foreach (array_slice((array)($doc['tree'] ?? []), 0, 5) as $t) {
      if ((string)$t['slug'] === $slug) continue;
      $st = get_term_by('slug', (string)$t['slug'], 'category');
      $sUrl = ($st && !is_wp_error($st)) ? get_term_link($st) : home_url('/category/' . $t['slug'] . '/');
      $pills[] = [
        'label' => (string)$t['name'],
        'url' => is_wp_error($sUrl) ? '#' : (string)$sUrl,
        'active' => false,
      ];
    }
  }

  $parentName = '';
  $parentUrl = '';
  if ($isChild && $topTerm && !is_wp_error($topTerm)) {
    $parentName = html_entity_decode((string)$topTerm->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pLink = get_term_link($topTerm);
    $parentUrl = is_wp_error($pLink) ? home_url("/category/$topSlug/") : (string)$pLink;
  }

  return [
    'home_url' => home_url('/'),
    'parent_name' => $parentName,
    'parent_url' => $parentUrl,
    'total_count' => $totalCount,
    'description' => $desc,
    'pills_label' => $pillsLabel,
    'pills' => $pills,
  ];
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
  else {
    // the Cloud Library renders its template inside its own query, so read the archive from the main query itself
    $q = ($GLOBALS['wp_the_query'] ?? null) instanceof WP_Query ? $GLOBALS['wp_the_query'] : ($GLOBALS['wp_query'] ?? null);
    if (!$q || !$q->is_category() || $q->is_paged()) return '';
    $term = $q->get_queried_object();
  }
  if (!$term || is_wp_error($term) || empty($term->term_id)) return '';
  $hub = lld_hub_entry(lld_categories_doc(), (string)$term->slug);
  if (!$hub) return '';   // not a category of the agreed tree (hidden display categories, retired terms)
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'cat' => (int)$term->term_id, 'posts_per_page' => 15, 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'no_found_rows' => false]);
  $posts = $q->posts; $byId = [];
  $totalCount = max(count($posts), (int)$q->found_posts);
  foreach ($posts as $p) $byId[(int)$p->ID] = $p;
  // a named flagship older than the newest batch is still the flagship
  $want = $hub['flagship'];
  if ($want !== null && $want !== '') {
    $fp = is_numeric($want) ? get_post((int)$want) : get_page_by_path((string)$want, OBJECT, 'post');
    if ($fp && $fp->post_status === 'publish' && !isset($byId[(int)$fp->ID])) { $posts[] = $fp; $byId[(int)$fp->ID] = $fp; }
  }
  $catName = html_entity_decode((string)$term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $plain = array_map(fn($p) => ['id' => (int)$p->ID, 'slug' => (string)$p->post_name, 'date' => (string)$p->post_date_gmt, 'reviewed' => lld_tools_review_byline($p) !== ''], $posts);
  $pick = lld_hub_pick($plain, $hub);

  // Build the 3-column Latest Guides Grid (exclude hero posts when there are > 4 posts, else show all so grid is rich)
  $heroIds = array_filter(array_merge([$pick['flagship']], (array)$pick['trending']));
  $gridPosts = [];
  if (count($posts) > 4) {
    foreach ($posts as $p) {
      if (!in_array((int)$p->ID, $heroIds, true)) $gridPosts[] = $p;
      if (count($gridPosts) >= 9) break;
    }
  } else {
    $gridPosts = array_slice($posts, 0, 9);
  }
  $articles = array_map(fn($p) => lld_hub_post_view($p, 'medium', true, $catName, '(max-width: 767px) 92vw, 300px'), $gridPosts);

  $m = [
    'category' => ['slug' => (string)$term->slug, 'name' => $catName],
    'header' => lld_hub_header_view($term, $hub, $totalCount),
    'flagship' => $pick['flagship'] ? lld_hub_post_view($byId[$pick['flagship']], 'medium_large', true, $catName, '(max-width: 767px) 92vw, 320px') : null,
    'trending' => array_map(fn($id) => lld_hub_post_view($byId[$id], 'thumbnail', true, $catName, '96px'), $pick['trending']),
    'tool' => lld_hub_tool_view($hub['tool']),
    'articles' => $articles,
    'total_count' => $totalCount,
    'track' => lld_hub_track_view($hub['track']),
  ];
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
