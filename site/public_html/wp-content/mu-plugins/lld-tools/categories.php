<?php
/**
 * Category restructure (lead package S2-L2, FR2-26), driven by data/categories.json.
 *   wp lld categories apply [--dry-run] [--assign-posts]   build the agreed tree, adopt or merge old terms, set the default
 *                                                         category, delete retired terms, mark display categories noindex.
 *                                                         --assign-posts also moves each post to its proposed category
 *                                                         (owner-approved list only on production).
 *   wp lld categories verify [--format=…]                  the tree as it stands against the agreed list (AS-01, AS-06).
 * Old category addresses 301 to the new ones whenever WordPress would answer 404 (AS-02). Article addresses never change.
 * A real run checks first and stops before any change if a term it must delete would still hold posts.
 */
if (!defined('ABSPATH')) exit;
if (!function_exists('add_action')) return;

function lld_categories_doc(): array { static $d = null; return $d ?? ($d = json_decode((string)@file_get_contents(LLD_TOOLS_DIR . '/data/categories.json'), true) ?: []); }

/* Old category archives 301 to the new tree (AS-02); only when WordPress has nothing at that address. */
add_action('template_redirect', function () {
  if (!is_404()) return;
  $path = trailingslashit((string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
  $to = (string)(lld_categories_doc()['redirects'][$path] ?? '');
  if ($to !== '' && $to[0] === '/') { wp_safe_redirect(home_url($to), 301, 'lld-tools'); exit; }
}, 11);

/** Flat list of tree nodes: [slug => [name, parent_slug|null]]. */
function lld_categories_nodes(array $doc): array {
  $n = [];
  foreach ((array)($doc['tree'] ?? []) as $t) {
    $n[$t['slug']] = [$t['name'], null];
    foreach ((array)($t['children'] ?? []) as $c) $n[$c['slug']] = [$c['name'], $t['slug']];
  }
  return $n;
}

/** The tree as it stands, compared with the agreed list (AS-01) and the hidden display categories (AS-06). */
function lld_categories_verify(): array {
  $doc = lld_categories_doc(); $nodes = lld_categories_nodes($doc); $hidden = (array)($doc['hidden'] ?? []); $problems = [];
  foreach ($nodes as $slug => [$name, $parent]) {
    $t = get_term_by('slug', $slug, 'category');
    if (!$t) { $problems[] = "missing: $name ($slug)"; continue; }
    if (html_entity_decode($t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $name) $problems[] = "name of $slug is '{$t->name}', expected '$name'";
    $p = $t->parent ? get_term((int)$t->parent, 'category') : null;
    if (($p && !is_wp_error($p) ? $p->slug : null) !== $parent) $problems[] = "parent of $slug is " . ($p && !is_wp_error($p) ? $p->slug : 'none') . ', expected ' . ($parent ?? 'none');
  }
  foreach ((array)get_terms(['taxonomy' => 'category', 'hide_empty' => false]) as $t) {
    if (isset($nodes[$t->slug])) continue;
    if (in_array($t->slug, $hidden, true)) { if (get_term_meta($t->term_id, 'lld_noindex', true) !== '1') $problems[] = "hidden category {$t->slug} is not noindex"; continue; }
    $problems[] = "extra category: {$t->name} ({$t->slug}, {$t->count} posts)";
  }
  return $problems;
}

if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('lld categories', function (array $args, array $assoc) {
    $sub = $args[0] ?? 'apply';
    if ($sub === 'verify') {
      $p = lld_categories_verify();
      foreach ($p as $x) WP_CLI::log($x);
      $p ? WP_CLI::error(count($p) . ' difference(s) from the agreed tree') : WP_CLI::success('category tree matches the agreed list; display categories are noindex');
      return;
    }
    if ($sub !== 'apply') WP_CLI::error('usage: wp lld categories apply [--dry-run] [--assign-posts] | verify');
    $doc = lld_categories_doc(); $nodes = lld_categories_nodes($doc); $dry = !empty($assoc['dry-run']); $assign = !empty($assoc['assign-posts']);
    if (!$nodes) WP_CLI::error('data/categories.json has no tree');
    $get = fn(string $slug) => get_term_by('slug', $slug, 'category') ?: null;
    $hidden = (array)($doc['hidden'] ?? []); $posts = $assign ? (array)($doc['posts'] ?? []) : [];
    $log = []; $note = function (string $s) use (&$log, $dry) { $log[] = $s; WP_CLI::log(($dry ? 'would: ' : '') . $s); };

    // 0. pre-flight: a term to delete must end up empty (posts moved by a merge or, with --assign-posts, by the post list)
    $blocked = [];
    foreach ((array)($doc['terms'] ?? []) as $op) {
      if ($op['op'] !== 'delete' || !($t = $get($op['from']))) continue;
      foreach (array_map('intval', (array)get_objects_in_term($t->term_id, 'category')) as $oid) {
        $st = get_post_status($oid); if ($st === false || $st === 'trash') continue;
        if (!isset($posts[(string)$oid])) $blocked[$op['from']][] = "$oid:$st";
      }
    }
    if ($blocked) {
      $msg = 'categories to delete still hold posts: ' . implode('; ', array_map(fn($k, $v) => "$k (" . implode(', ', $v) . ')', array_keys($blocked), $blocked)) . ' — re-run with --assign-posts (approved list) or move them first';
      if (!$dry) WP_CLI::error($msg);
      WP_CLI::warning($msg);
    }

    // 1. adopt existing terms as tree terms (keeps term ID and posts); parents are set in step 2
    $adopted = [];   // tree slug => adopted term, so a dry run does not also report a create
    foreach ((array)($doc['terms'] ?? []) as $op) {
      if ($op['op'] !== 'adopt' || !isset($nodes[$op['as']])) continue;
      $t = $get($op['from']) ?: $get($op['as']); if (!$t) { WP_CLI::warning("term {$op['from']} not found"); continue; }
      $name = $nodes[$op['as']][0]; $adopted[$op['as']] = $t;
      if ($t->slug !== $op['as'] || html_entity_decode($t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $name) {
        $note("adopt {$t->slug} ({$t->term_id}) as '$name' ({$op['as']})");
        if (!$dry) { $r = wp_update_term($t->term_id, 'category', ['name' => $name, 'slug' => $op['as']]); if (is_wp_error($r)) WP_CLI::error($r->get_error_message()); }
      }
    }
    // 2. every tree term exists with its name and parent (tops first, so children can find them)
    foreach ([true, false] as $tops) foreach ($nodes as $slug => [$name, $parent]) {
      if (($parent === null) !== $tops) continue;
      $pid = 0;
      if ($parent !== null) { $pt = $get($parent); $pid = $pt ? (int)$pt->term_id : 0; if (!$pt && !$dry) WP_CLI::error("parent $parent missing"); }
      $t = $get($slug) ?: ($dry ? ($adopted[$slug] ?? null) : null);
      if (!$t) { $note("create '$name' ($slug)" . ($parent ? " under $parent" : '')); if (!$dry) { $r = wp_insert_term($name, 'category', ['slug' => $slug, 'parent' => $pid]); if (is_wp_error($r)) WP_CLI::error($r->get_error_message()); } continue; }
      if ((int)$t->parent !== $pid && !($dry && $parent !== null && !$get($parent))) { $note("move $slug under " . ($parent ?? 'top level')); if (!$dry) wp_update_term($t->term_id, 'category', ['parent' => $pid]); }
    }
    // 3. merges: posts of the old term move to its tree term, then the old term goes
    foreach ((array)($doc['terms'] ?? []) as $op) {
      if ($op['op'] !== 'merge' || !($f = $get($op['from']))) continue;
      $into = $get((string)$op['into']); if (!$into && !$dry) WP_CLI::error("merge target {$op['into']} missing");
      $ids = array_map('intval', (array)get_objects_in_term($f->term_id, 'category'));
      $note("merge {$f->slug} ({$f->term_id}, " . count($ids) . " posts) into {$op['into']}, then delete it");
      if ($dry) continue;
      foreach ($ids as $oid) { $cur = array_diff(wp_get_post_categories($oid), [$f->term_id]); wp_set_post_categories($oid, array_values(array_unique(array_merge($cur, [(int)$into->term_id])))); }
      wp_delete_term($f->term_id, 'category');
    }
    // 4. posts to their proposed category, keeping any display categories they carry
    $moved = 0;
    foreach ($posts as $pid => $slug) {
      $pid = (int)$pid; if (get_post_status($pid) === false) continue;
      $t = $get((string)$slug); if (!$t && !$dry) { WP_CLI::warning("post $pid: category $slug missing"); continue; }
      $cur = wp_get_post_categories($pid);
      $keep = array_values(array_filter($cur, function ($id) use ($hidden) { $x = get_term((int)$id, 'category'); return $x && !is_wp_error($x) && in_array($x->slug, $hidden, true); }));
      $want = $t ? array_values(array_unique(array_merge([(int)$t->term_id], $keep))) : $keep;
      sort($cur); $w = $want; sort($w);
      if ($cur === $w) continue;
      $moved++;
      if (!$dry) { wp_set_post_categories($pid, $want); if ($t) update_post_meta($pid, '_yoast_wpseo_primary_category', (int)$t->term_id); }
    }
    if ($assign) $note("$moved post(s) moved to their proposed category");
    // 5. default category (WordPress refuses to delete the current default)
    $def = $get((string)($doc['default_category'] ?? ''));
    if ($def && (int)get_option('default_category') !== (int)$def->term_id) { $note("default category -> {$def->slug}"); if (!$dry) update_option('default_category', (int)$def->term_id); }
    elseif (!$def && $dry) $note('default category -> ' . ($doc['default_category'] ?? '?') . ' (after creation)');
    // 6. retired terms
    foreach ((array)($doc['terms'] ?? []) as $op) {
      if ($op['op'] !== 'delete' || !($t = $get($op['from']))) continue;
      $note("delete {$t->slug} ({$t->term_id})");
      if (!$dry) { $r = wp_delete_term($t->term_id, 'category'); if (is_wp_error($r) || $r === false) WP_CLI::error("could not delete {$t->slug}"); }
    }
    // 7. display categories: kept, noindex (lld-tools robots filters read lld_noindex; Yoast reads its taxonomy meta)
    $ym = get_option('wpseo_taxonomy_meta', []); $ym = is_array($ym) ? $ym : [];
    foreach ($hidden as $slug) {
      $t = $get($slug); if (!$t) continue;
      if (get_term_meta($t->term_id, 'lld_noindex', true) !== '1' || ($ym['category'][$t->term_id]['wpseo_noindex'] ?? '') !== 'noindex') {
        $note("noindex display category $slug");
        if (!$dry) { update_term_meta($t->term_id, 'lld_noindex', '1'); $ym['category'][$t->term_id]['wpseo_noindex'] = 'noindex'; }
      }
    }
    if (!$dry) update_option('wpseo_taxonomy_meta', $ym);
    if (!$dry) { clean_term_cache([], 'category'); if (has_action('litespeed_purge_all')) do_action('litespeed_purge_all'); }
    WP_CLI::success($log ? count($log) . ' change(s)' . ($dry ? ' (dry run)' : '') : 'nothing to change');
    if (!$dry) foreach (lld_categories_verify() as $p) WP_CLI::warning($p);
  });
}
