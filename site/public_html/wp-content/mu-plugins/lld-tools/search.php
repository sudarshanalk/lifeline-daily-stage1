<?php
/**
 * Stage 2 search and AI-assistant work in lld-tools (lead packages S2-L4 and S2-L5).
 *   FR2-21: robots.txt with AI-assistant access and member paths excluded; a curated llms.txt; direct-answer box, FAQ section
 *           with FAQPage markup, and dated sources ([lld_answer], [lld_faq]/[lld_q], [lld_sources]/[lld_source]);
 *           `wp lld aeo report` checks articles against AS-01 and AS-05.
 *   FR2-22: IndexNow notices on publish, update and removal, each recorded in a log; the monthly search-check reminder email.
 * Settings: data/search.json. The pure builders below take plain arrays so they are tested without WordPress
 * (tests/lead/TC-FR2-21.test.mjs, TC-FR2-22.test.mjs); the WordPress wiring follows them.
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

/** robots.txt for the public site: member paths excluded for everyone, AI assistants named and allowed (FR2-21 AS-04). */
function lld_search_robots_text(array $cfg, string $sitemap): string {
  $paths = array_values(array_unique(array_map('strval', (array)($cfg['member_paths'] ?? []))));
  $deny = implode('', array_map(fn($p) => "Disallow: $p\n", $paths));
  $out = "# Lifeline Daily robots.txt (FR2-21). Public articles and tools may be read by search engines and AI assistants.\n";
  $out .= "# Member pages, member data and member files need a sign-in and are excluded.\n\n";
  $out .= "User-agent: *\n" . $deny . "\n";
  $agents = array_values(array_unique(array_map('strval', (array)($cfg['ai_agents'] ?? []))));
  if ($agents) $out .= implode('', array_map(fn($a) => "User-agent: $a\n", $agents)) . "Allow: /\n" . $deny . "\n";
  if ($sitemap !== '') $out .= "Sitemap: $sitemap\n";
  return $out;
}

/** Markdown-safe one-line text for llms.txt. */
function lld_search_md(string $s): string {
  $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  return str_replace(['[', ']'], ['(', ')'], $s);
}

/**
 * llms.txt (FR2-21 AS-03) from an explicit allowlist only: the site summary, the tools, published articles grouped by
 * category, and the named pages. Nothing else on the site (custom post types, theme records, member pages) can appear.
 * $site = [name, url]; $articles = [[title, url, description, section]]; $pages = [[title, url]].
 */
function lld_search_llms_text(array $cfg, array $site, array $articles, array $pages): string {
  $l = (array)($cfg['llms'] ?? []);
  $out = '# ' . lld_search_md((string)($l['title'] ?? $site['name'] ?? 'Website')) . "\n\n";
  $out .= '> ' . lld_search_md((string)($l['summary'] ?? '')) . "\n\n";
  foreach ((array)($l['notes'] ?? []) as $n) $out .= '- ' . lld_search_md((string)$n) . "\n";
  $out .= "\n## Health tools\n\n";
  foreach ((array)($l['tools'] ?? []) as $t) {
    $out .= '- [' . lld_search_md((string)$t['title']) . '](' . rtrim((string)$site['url'], '/') . (string)$t['path'] . '): ' . lld_search_md((string)($t['description'] ?? '')) . "\n";
  }
  $by = [];
  foreach ($articles as $a) $by[(string)($a['section'] ?? 'Articles')][] = $a;
  ksort($by, SORT_NATURAL | SORT_FLAG_CASE);
  foreach ($by as $section => $items) {
    $out .= "\n## " . lld_search_md($section) . "\n\n";
    foreach ($items as $a) {
      $d = lld_search_md((string)($a['description'] ?? ''));
      $out .= '- [' . lld_search_md((string)$a['title']) . '](' . (string)$a['url'] . ')' . ($d !== '' ? ": $d" : '') . "\n";
    }
  }
  if ($pages) {
    $out .= "\n## About this site\n\n";
    foreach ($pages as $p) $out .= '- [' . lld_search_md((string)$p['title']) . '](' . (string)$p['url'] . ")\n";
  }
  return $out;
}

/** Number of words in an HTML fragment (the 60-word rule for the direct answer, FR2-21 AS-01). */
function lld_search_word_count(string $html): int {
  $text = trim(html_entity_decode(strip_tags(preg_replace('/\[[^\]]*\]/', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
}

/** Attributes of one shortcode tag, e.g. title="…" url='…'. */
function lld_search_atts(string $s): array {
  preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $s, $m, PREG_SET_ORDER);
  $out = [];
  foreach ($m as $x) $out[strtolower($x[1])] = $x[2] !== '' ? $x[2] : ($x[3] ?? '');
  return $out;
}

/** What an article's source text carries for FR2-21 AS-01 (direct answer) and AS-05 (dated sources). */
function lld_search_aeo_row(string $content): array {
  $answer = preg_match('/\[lld_answer\](.*?)\[\/lld_answer\]/s', $content, $m) ? $m[1] : null;
  $words = $answer === null ? 0 : lld_search_word_count($answer);
  $faq = preg_match_all('/\[lld_q\s[^\]]*\]/', $content);
  preg_match_all('/\[lld_source\s([^\]]*)\]/', $content, $sm);
  $dated = 0;
  foreach ($sm[1] as $a) { $at = lld_search_atts($a); if (preg_match('/^\d{4}(-\d{2}){0,2}$/', $at['published'] ?? '') || preg_match('/^\d{4}-\d{2}-\d{2}$/', $at['checked'] ?? '')) $dated++; }
  $sources = count($sm[1]);
  $answer_first = $answer !== null && trim(strip_tags(substr($content, 0, (int)strpos($content, '[lld_answer]')))) === '';
  return ['has_answer' => $answer !== null, 'answer_words' => $words, 'answer_ok' => $answer !== null && $words > 0 && $words <= 60 && $answer_first,
          'faq_questions' => (int)$faq, 'sources' => $sources, 'sources_dated' => $dated, 'sources_ok' => $sources > 0 && $dated === $sources];
}

/** FAQPage JSON-LD for the questions shown on a page (FR2-21 AS-02). */
function lld_search_faq_jsonld(array $pairs, string $url): array {
  return ['@context' => 'https://schema.org', '@type' => 'FAQPage', '@id' => $url . '#faq',
          'mainEntity' => array_map(fn($p) => ['@type' => 'Question', 'name' => (string)$p[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string)$p[1]]], array_values($pairs))];
}

/** IndexNow request body (FR2-22 AS-03): one host, its key and key file, the changed addresses on that host only. */
function lld_search_indexnow_body(string $host, string $key, string $key_location, array $urls): array {
  $own = array_values(array_unique(array_filter(array_map('strval', $urls), fn($u) => strcasecmp((string)parse_url($u, PHP_URL_HOST), $host) === 0)));
  return ['host' => $host, 'key' => $key, 'keyLocation' => $key_location, 'urlList' => $own];
}

/** The monthly reminder (FR2-22 AS-04): plain text, no personal or health data, total time under 30 minutes. */
function lld_search_checklist_email(array $cfg, string $site_url, string $month): array {
  $c = (array)($cfg['checklist'] ?? []); $items = (array)($c['items'] ?? []);
  $total = array_sum(array_map(fn($i) => (int)($i['minutes'] ?? 0), $items));
  $body = "Your monthly search check for $site_url ($month). It takes about $total minutes.\n\n";
  foreach (array_values($items) as $n => $i) $body .= ($n + 1) . '. ' . (string)$i['task'] . ' (' . (int)$i['minutes'] . " min)\n";
  $body .= "\nReply to this email with anything you would like the lead to fix.\n";
  return ['subject' => (string)($c['subject'] ?? 'Monthly search check'), 'body' => $body, 'minutes' => $total];
}

/* ---------------------------------------------------------------- WordPress wiring -------------------------------- */
if (!function_exists('add_action')) return;

function lld_search_cfg(): array { static $d = null; return $d ?? ($d = json_decode((string)@file_get_contents(LLD_TOOLS_DIR . '/data/search.json'), true) ?: []); }
/** Notices leave the site only from the live, public site; staging and local log them as skipped. */
function lld_search_is_live(): bool {
  $host = strtolower((string)parse_url(home_url(), PHP_URL_HOST));
  return (string)get_option('blog_public') === '1' && in_array($host, array_map('strtolower', (array)(lld_search_cfg()['live_hosts'] ?? [])), true);
}
function lld_search_sitemap_url(): string {
  if (defined('WPSEO_VERSION')) return home_url('/sitemap_index.xml');
  return function_exists('get_sitemap_url') ? (string)get_sitemap_url('index') : '';
}

/* robots.txt: replaces the generated text on the public site only; a non-public site keeps WordPress's "Disallow: /". */
add_filter('robots_txt', function ($output, $public) {
  if (!$public) return $output;
  return lld_search_robots_text(lld_search_cfg(), lld_search_sitemap_url());
}, PHP_INT_MAX, 2);

function lld_search_llms_build(): string {
  $cfg = lld_search_cfg(); $max = (int)($cfg['llms']['max_articles'] ?? 500);
  $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => $max, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true]);
  $articles = [];
  foreach ($q->posts as $p) {
    if (get_post_meta($p->ID, '_yoast_wpseo_meta-robots-noindex', true) === '1') continue;
    $cat = null;
    if (class_exists('WPSEO_Primary_Term')) { $id = (new WPSEO_Primary_Term('category', $p->ID))->get_primary_term(); if ($id) $cat = get_term((int)$id, 'category'); if ($cat && !is_wp_error($cat) && get_term_meta($cat->term_id, 'lld_noindex', true) === '1') $cat = null; }
    if (!$cat || is_wp_error($cat)) {
      foreach ((array)get_the_category($p->ID) as $c) { if (get_term_meta($c->term_id, 'lld_noindex', true) === '1') continue; $cat = $c; break; }
    }
    if ($cat && !is_wp_error($cat) && $cat->parent) { $top = get_term((int)$cat->parent, 'category'); if ($top && !is_wp_error($top)) $cat = $top; }
    $desc = (string)get_post_meta($p->ID, '_yoast_wpseo_metadesc', true);
    if ($desc === '') $desc = wp_trim_words(wp_strip_all_tags((string)$p->post_excerpt), 30, '…');
    $articles[] = ['title' => get_the_title($p), 'url' => get_permalink($p), 'description' => $desc, 'section' => $cat && !is_wp_error($cat) ? html_entity_decode($cat->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Articles'];
  }
  $pages = [];
  foreach ((array)($cfg['llms']['pages'] ?? []) as $slug) {
    $pg = get_page_by_path((string)$slug, OBJECT, 'page');
    if ($pg && $pg->post_status === 'publish' && $pg->post_password === '') $pages[] = ['title' => get_the_title($pg), 'url' => get_permalink($pg)];
  }
  return lld_search_llms_text($cfg, ['name' => get_bloginfo('name'), 'url' => home_url('/')], $articles, $pages);
}

/* /llms.txt and the IndexNow key file are answered before WordPress routes the request. A static llms.txt in the document
 * root is served by the web server first, so `wp lld search setup` removes the generated one. */
add_action('init', function () {
  if (!isset($_SERVER['REQUEST_URI']) || (defined('WP_CLI') && WP_CLI)) return;
  $path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if ($path === '/llms.txt') {
    status_header(200); header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex');
    echo lld_search_llms_build(); exit;
  }
  $key = (string)get_option('lld_indexnow_key');
  if ($key !== '' && $path === '/' . $key . '.txt') {
    status_header(200); header('Content-Type: text/plain; charset=utf-8'); echo $key; exit;
  }
}, 0);

/* ---- [lld_answer], [lld_faq]/[lld_q], [lld_sources]/[lld_source] (SCR-43) ---- */
function lld_search_clean(string $html): string { return trim(preg_replace(['#^\s*</p>#', '#<p>\s*$#', '#<p>\s*</p>#', '#<br\s*/?>\s*(?=<(li|div|/ol|/div))#'], '', $html)); }
add_shortcode('lld_answer', function ($atts, $content = '') {
  return '<div class="lld-answer" data-test="direct-answer" role="note"><p>' . wp_kses_post(lld_search_clean(do_shortcode((string)$content))) . '</p></div>';
});
$GLOBALS['lld_search_faq'] = [];
add_shortcode('lld_faq', function ($atts, $content = '') {
  $a = shortcode_atts(['title' => 'Frequently asked questions'], $atts, 'lld_faq');
  return '<section class="lld-faq" data-test="faq"><h2>' . esc_html($a['title']) . '</h2>' . lld_search_clean(do_shortcode((string)$content)) . '</section>';
});
add_shortcode('lld_q', function ($atts, $content = '') {
  $a = shortcode_atts(['q' => ''], $atts, 'lld_q');
  $answer = wp_kses_post(lld_search_clean(do_shortcode((string)$content)));
  if ($a['q'] !== '' && $answer !== '') $GLOBALS['lld_search_faq'][] = [$a['q'], trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($answer)))];
  return '<div class="lld-faq__item"><h3 class="lld-faq__q">' . esc_html($a['q']) . '</h3><div class="lld-faq__a">' . $answer . '</div></div>';
});
add_shortcode('lld_sources', function ($atts, $content = '') {
  $a = shortcode_atts(['title' => 'Sources'], $atts, 'lld_sources');
  return '<section class="lld-sources" data-test="sources"><h2>' . esc_html($a['title']) . '</h2><ol>' . lld_search_clean(do_shortcode((string)$content)) . '</ol></section>';
});
add_shortcode('lld_source', function ($atts) {
  $a = shortcode_atts(['title' => '', 'url' => '', 'publisher' => '', 'published' => '', 'checked' => ''], $atts, 'lld_source');
  $t = $a['url'] !== '' ? '<a href="' . esc_url($a['url']) . '" rel="noopener">' . esc_html($a['title']) . '</a>' : esc_html($a['title']);
  $bits = array_filter([$a['publisher'] !== '' ? esc_html($a['publisher']) : '', $a['published'] !== '' ? 'published <time datetime="' . esc_attr($a['published']) . '">' . esc_html($a['published']) . '</time>' : '', $a['checked'] !== '' ? 'checked <time datetime="' . esc_attr($a['checked']) . '">' . esc_html($a['checked']) . '</time>' : '']);
  return '<li>' . $t . ($bits ? '. ' . implode(', ', $bits) . '.' : '') . '</li>';
});
add_action('wp_footer', function () {
  $pairs = $GLOBALS['lld_search_faq'] ?? [];
  if (!$pairs || !is_singular()) return;
  echo '<script type="application/ld+json">' . wp_json_encode(lld_search_faq_jsonld($pairs, (string)get_permalink()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});

/* ---- IndexNow (FR2-22 AS-03) ---- */
function lld_indexnow_key(): string {
  $k = (string)get_option('lld_indexnow_key');
  if (!preg_match('/^[a-f0-9]{32}$/', $k)) { $k = bin2hex(random_bytes(16)); update_option('lld_indexnow_key', $k, true); }
  return $k;
}
function lld_indexnow_log(array $entry): void {
  $log = (array)get_option('lld_indexnow_log', []);
  array_unshift($log, ['time' => gmdate('Y-m-d\TH:i:s\Z')] + $entry);
  update_option('lld_indexnow_log', array_slice($log, 0, (int)(lld_search_cfg()['indexnow']['log_size'] ?? 300)), false);
}
/** Send (or, off the live site, log as skipped) one IndexNow notice for $urls. Returns the log entry. */
function lld_indexnow_send(array $urls, string $why, bool $dry = false): array {
  $host = (string)parse_url(home_url(), PHP_URL_HOST); $key = lld_indexnow_key();
  $body = lld_search_indexnow_body($host, $key, home_url('/' . $key . '.txt'), $urls);
  $entry = ['why' => $why, 'urls' => $body['urlList']];
  if (!$body['urlList']) $entry += ['result' => 'skipped', 'reason' => 'no addresses on this host'];
  elseif ($dry) $entry += ['result' => 'dry-run'];
  elseif (!lld_search_is_live()) $entry += ['result' => 'skipped', 'reason' => 'not the live public site'];
  else {
    $r = wp_remote_post((string)(lld_search_cfg()['indexnow']['endpoint'] ?? 'https://api.indexnow.org/indexnow'), ['timeout' => 8, 'headers' => ['Content-Type' => 'application/json; charset=utf-8'], 'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES)]);
    if (is_wp_error($r)) $entry += ['result' => 'error', 'reason' => $r->get_error_message()];
    else { $code = (int)wp_remote_retrieve_response_code($r); $entry += ['result' => in_array($code, [200, 202], true) ? 'sent' : 'error', 'http' => $code]; }
  }
  if (!$dry) lld_indexnow_log($entry);
  return $entry;
}
$GLOBALS['lld_indexnow_queue'] = [];
add_action('transition_post_status', function ($new, $old, $post) {
  if (!in_array($post->post_type, (array)(lld_search_cfg()['indexnow']['post_types'] ?? ['post', 'page']), true)) return;
  if (wp_is_post_revision($post) || wp_is_post_autosave($post) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
  if ($new === 'publish') $GLOBALS['lld_indexnow_queue'][get_permalink($post)] = $old === 'publish' ? 'updated' : 'published';
  elseif ($old === 'publish') {
    $path = $post->post_type === 'page' ? get_page_uri($post) : $post->post_name;
    if ($path) $GLOBALS['lld_indexnow_queue'][home_url('/' . trim((string)$path, '/') . '/')] = 'removed';
  }
}, 10, 3);
add_action('shutdown', function () {
  $q = $GLOBALS['lld_indexnow_queue'] ?? []; if (!$q) return;
  $GLOBALS['lld_indexnow_queue'] = [];
  lld_indexnow_send(array_keys($q), implode(', ', array_unique(array_values($q))));
});

/* ---- monthly search-check reminder (FR2-22 AS-04): a daily check sends once per calendar month, live site only ---- */
add_action('init', function () { if (!wp_next_scheduled('lld_seo_checklist_daily')) wp_schedule_event(time() + 3600, 'daily', 'lld_seo_checklist_daily'); });
function lld_search_checklist_send(string $to, bool $dry = false): array {
  $month = gmdate('Y-m'); $mail = lld_search_checklist_email(lld_search_cfg(), home_url('/'), $month);
  if ($dry) return $mail + ['to' => $to, 'result' => 'dry-run'];
  $ok = wp_mail($to, $mail['subject'], $mail['body']);
  $state = (array)get_option('lld_seo_checklist_state', []);
  $log = (array)($state['log'] ?? []); array_unshift($log, ['time' => gmdate('Y-m-d\TH:i:s\Z'), 'month' => $month, 'result' => $ok ? 'sent' : 'error']);
  update_option('lld_seo_checklist_state', ['last_month' => $ok ? $month : ($state['last_month'] ?? ''), 'log' => array_slice($log, 0, 36)], false);
  return $mail + ['to' => $to, 'result' => $ok ? 'sent' : 'error'];
}
function lld_search_owner_email(): string { $e = (string)get_option('lld_owner_email'); return is_email($e) ? $e : (string)get_option('admin_email'); }
add_action('lld_seo_checklist_daily', function () {
  if (!lld_search_is_live()) return;
  $state = (array)get_option('lld_seo_checklist_state', []);
  if (($state['last_month'] ?? '') === gmdate('Y-m')) return;
  lld_search_checklist_send(lld_search_owner_email());
});

/* ---------------------------------------------------------------- WP-CLI ------------------------------------------- */
if (defined('WP_CLI') && WP_CLI) {
  /** wp lld search setup [--dry-run]: hand robots.txt, llms.txt and IndexNow to lld-tools. Turns off the Yoast and Hostinger
   *  llms.txt generators and Yoast's IndexNow, and removes a generated static llms.txt from the document root. */
  WP_CLI::add_command('lld search setup', function (array $args, array $assoc) {
    $dry = !empty($assoc['dry-run']); $do = [];
    $y = get_option('wpseo');
    if (is_array($y)) foreach (['enable_llms_txt', 'enable_index_now'] as $k) if (!empty($y[$k])) { $do[] = "wpseo.$k -> false"; $y[$k] = false; }
    $h = get_option('hostinger_tools');
    if (is_array($h) && !empty($h['enable_llms_txt'])) { $do[] = 'hostinger_tools.enable_llms_txt -> false'; $h['enable_llms_txt'] = false; }
    $file = rtrim(ABSPATH, '/\\') . '/llms.txt';
    $generated = is_file($file) && preg_match('/Generated by (Yoast SEO|Hostinger Tools Plugin)/', (string)file_get_contents($file));
    if ($generated) $do[] = "remove generated $file";
    elseif (is_file($file)) WP_CLI::warning("$file exists and was not made by Yoast or Hostinger: left in place, so /llms.txt keeps serving it");
    foreach ($do as $d) WP_CLI::log(($dry ? 'would: ' : '') . $d);
    if (!$dry) {
      if (is_array($y)) update_option('wpseo', $y);
      if (is_array($h)) update_option('hostinger_tools', $h);
      if ($generated && !unlink($file)) WP_CLI::error("could not remove $file");
      lld_indexnow_key();
      if (has_action('litespeed_purge_url')) { do_action('litespeed_purge_url', home_url('/llms.txt')); do_action('litespeed_purge_url', home_url('/robots.txt')); }
    }
    WP_CLI::success($do ? count($do) . ' change(s)' . ($dry ? ' (dry run)' : '') : 'nothing to change');
  });
  /** wp lld search robots|llms: print the generated text. */
  WP_CLI::add_command('lld search robots', function () { WP_CLI::line(rtrim(apply_filters('robots_txt', '', (string)get_option('blog_public') === '1'))); });
  WP_CLI::add_command('lld search llms', function () { WP_CLI::line(rtrim(lld_search_llms_build())); });
  /** wp lld aeo report [--format=table|json|csv] [--only-failing]: direct answer (AS-01) and dated sources (AS-05) per published article. */
  WP_CLI::add_command('lld aeo report', function (array $args, array $assoc) {
    $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true]);
    $rows = [];
    foreach ($q->posts as $p) {
      $r = ['id' => $p->ID, 'title' => get_the_title($p)] + lld_search_aeo_row((string)$p->post_content);
      if (!empty($assoc['only-failing']) && $r['answer_ok'] && $r['sources_ok']) continue;
      foreach (['has_answer', 'answer_ok', 'sources_ok'] as $b) $r[$b] = $r[$b] ? 'yes' : 'no';
      $rows[] = $r;
    }
    WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['id', 'title', 'has_answer', 'answer_words', 'answer_ok', 'faq_questions', 'sources', 'sources_dated', 'sources_ok']);
  });
  /** wp lld indexnow log [--format=…] | key | ping <url>… [--dry-run] */
  WP_CLI::add_command('lld indexnow', function (array $args, array $assoc) {
    $sub = array_shift($args) ?: 'log';
    if ($sub === 'key') { WP_CLI::line(lld_indexnow_key()); return; }
    if ($sub === 'ping') {
      if (!$args) WP_CLI::error('usage: wp lld indexnow ping <url>... [--dry-run]');
      $e = lld_indexnow_send($args, 'manual', !empty($assoc['dry-run']));
      WP_CLI::line(wp_json_encode($e, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); return;
    }
    if ($sub !== 'log') WP_CLI::error('usage: wp lld indexnow log|key|ping');
    $rows = array_map(fn($e) => ['time' => $e['time'] ?? '', 'why' => $e['why'] ?? '', 'result' => $e['result'] ?? '', 'http' => $e['http'] ?? '', 'reason' => $e['reason'] ?? '', 'urls' => implode(' ', (array)($e['urls'] ?? []))], (array)get_option('lld_indexnow_log', []));
    WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['time', 'why', 'result', 'http', 'reason', 'urls']);
  });
  /** wp lld checklist send [--to=<email>] [--dry-run] | log: the monthly search-check reminder (FR2-22 AS-04). */
  WP_CLI::add_command('lld checklist', function (array $args, array $assoc) {
    $sub = $args[0] ?? 'send';
    if ($sub === 'log') { WP_CLI\Utils\format_items($assoc['format'] ?? 'table', (array)(get_option('lld_seo_checklist_state', [])['log'] ?? []), ['time', 'month', 'result']); return; }
    if ($sub !== 'send') WP_CLI::error('usage: wp lld checklist send [--to=<email>] [--dry-run] | log');
    $to = (string)($assoc['to'] ?? lld_search_owner_email());
    if (!is_email($to)) WP_CLI::error('not an email address');
    $r = lld_search_checklist_send($to, !empty($assoc['dry-run']));
    WP_CLI::line("To: {$r['to']}\nSubject: {$r['subject']}\n\n{$r['body']}");
    $r['result'] === 'error' ? WP_CLI::error('wp_mail failed') : WP_CLI::success($r['result'] . " ({$r['minutes']} minutes)");
  });
}
