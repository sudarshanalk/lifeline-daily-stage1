<?php
/**
 * Header menu (owner, 28 Oct 2026; Stage 2 screen design specification section 6, navigation map).
 *   `wp lld menu apply [--dry-run] [--backup-dir=<dir>]` rebuilds the header menu named in data/menu.json in place: the
 *   Newspaper header template pins its menu by ID, so the menu is rebuilt, never swapped. Every current item is saved to a
 *   JSON backup first. `wp lld menu verify` compares the live menu with the data. `wp lld menu restore --from=<file>`
 *   puts a backup back exactly.
 *   Items with the class lld-menu-members stay hidden while the lld-members plugin is inactive (the live site until the
 *   Stage 2 launch); lld-menu-signed-out and lld-menu-signed-in follow sign-in.
 * The pure builders take plain arrays so they are tested without WordPress (tests/lead/TC-FR2-26.test.mjs).
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

/** The flat plan, parents before children: kind (page|category|custom), ref (page path, category slug or url), title,
 *  parent (row index or null), classes. Children inherit their parent's classes. $tree is categories.json "tree". */
function lld_menu_plan(array $doc, array $tree): array {
  $rows = [];
  $add = function (array $it, ?int $parent, array $inherited) use (&$rows, $tree, &$add) {
    $classes = array_values(array_unique(array_merge($inherited, array_map('strval', (array)($it['classes'] ?? [])))));
    if (isset($it['page'])) $row = ['kind' => 'page', 'ref' => trim((string)$it['page'], '/')];
    elseif (isset($it['category'])) $row = ['kind' => 'category', 'ref' => (string)$it['category']];
    else $row = ['kind' => 'custom', 'ref' => (string)($it['url'] ?? '#')];
    $rows[] = $row + ['title' => (string)$it['title'], 'parent' => $parent, 'classes' => $classes];
    $me = count($rows) - 1;
    $kids = (array)($it['children'] ?? []);
    if (($it['children_from'] ?? '') === 'categories') foreach ($tree as $t) $kids[] = ['title' => (string)$t['name'], 'category' => (string)$t['slug']];
    $pass = array_values(array_diff($classes, ['lld-menu-cta']));
    foreach ($kids as $k) $add((array)$k, $me, $pass);
  };
  foreach ((array)($doc['items'] ?? []) as $it) $add((array)$it, null, []);
  return $rows;
}

/** Whether an item with these classes shows: membership items only while lld-members is active; signed-out items only to
 *  visitors; signed-in items only to signed-in people. */
function lld_menu_visible(array $classes, bool $members_on, bool $signed_in): bool {
  if (in_array('lld-menu-members', $classes, true) && !$members_on) return false;
  if (in_array('lld-menu-signed-out', $classes, true) && $signed_in) return false;
  if (in_array('lld-menu-signed-in', $classes, true) && !$signed_in) return false;
  return true;
}

/* ---------------------------------------------------------------- WordPress wiring -------------------------------- */
if (!function_exists('add_filter')) return;

function lld_menu_doc(): array { static $d = null; return $d ?? ($d = json_decode((string)@file_get_contents(LLD_TOOLS_DIR . '/data/menu.json'), true) ?: []); }

add_filter('wp_nav_menu_objects', function ($items) {
  $members_on = class_exists('LLD_Members_Plugin');
  $signed_in = is_user_logged_in();
  $out = [];
  foreach ((array)$items as $item) if (lld_menu_visible(array_values(array_filter((array)$item->classes, 'strlen')), $members_on, $signed_in)) $out[] = $item;
  return $out;
}, 20);

/** The WordPress menu-item fields for a plan row, or null when its page or category is missing. */
function lld_menu_resolve(array $row): ?array {
  if ($row['kind'] === 'page') {
    $p = get_page_by_path($row['ref'], OBJECT, 'page');
    if (!$p || $p->post_status !== 'publish') return null;
    return ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => (int)$p->ID];
  }
  if ($row['kind'] === 'category') {
    $t = get_term_by('slug', $row['ref'], 'category');
    if (!$t) return null;
    return ['menu-item-type' => 'taxonomy', 'menu-item-object' => 'category', 'menu-item-object-id' => (int)$t->term_id];
  }
  $url = $row['ref'] === '#' ? '#' : (str_starts_with($row['ref'], '/') ? home_url($row['ref']) : $row['ref']);
  return ['menu-item-type' => 'custom', 'menu-item-url' => $url];
}

/** One comparable line per item, for apply's "nothing to change" and for verify. */
function lld_menu_signature_live(int $menu_id): array {
  $items = (array)wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
  usort($items, fn($a, $b) => $a->menu_order <=> $b->menu_order);
  $pos = []; foreach ($items as $i => $it) $pos[(int)$it->ID] = $i;
  $sig = [];
  foreach ($items as $it) {
    $target = $it->type === 'custom' ? (string)$it->url : $it->object . ':' . (int)$it->object_id;
    $classes = array_values(array_filter((array)$it->classes, 'strlen')); sort($classes);
    $parent = (int)$it->menu_item_parent ? ($pos[(int)$it->menu_item_parent] ?? -1) : null;
    $sig[] = json_encode([html_entity_decode((string)$it->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $parent, $target, $classes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
  return $sig;
}

if (defined('WP_CLI') && WP_CLI) {
  /** wp lld menu apply [--dry-run] [--backup-dir=<dir>] | verify | restore --from=<file> */
  WP_CLI::add_command('lld menu', function (array $args, array $assoc) {
    $sub = $args[0] ?? 'apply';
    $doc = lld_menu_doc();
    $menu = wp_get_nav_menu_object((string)($doc['menu'] ?? ''));
    if (!$menu) WP_CLI::error("menu '" . ($doc['menu'] ?? '') . "' not found");
    $menu_id = (int)$menu->term_id;
    $tree = (array)(json_decode((string)@file_get_contents(LLD_TOOLS_DIR . '/data/categories.json'), true)['tree'] ?? []);

    // the planned rows, resolved; a missing page or category drops that row and its children
    $plan = lld_menu_plan($doc, $tree); $resolved = []; $skip = []; $sig = []; $index = [];
    foreach ($plan as $i => $row) {
      if ($row['parent'] !== null && isset($skip[$row['parent']])) { $skip[$i] = true; continue; }
      $r = lld_menu_resolve($row);
      if (!$r) { $skip[$i] = true; WP_CLI::warning("skipped '{$row['title']}': {$row['kind']} {$row['ref']} is missing or unpublished"); continue; }
      $index[$i] = count($resolved); $resolved[$i] = $r;
      $target = $r['menu-item-type'] === 'custom' ? $r['menu-item-url'] : $r['menu-item-object'] . ':' . $r['menu-item-object-id'];
      $classes = $row['classes']; sort($classes);
      $sig[] = json_encode([$row['title'], $row['parent'] === null ? null : $index[$row['parent']], $target, $classes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $live = lld_menu_signature_live($menu_id);

    if ($sub === 'verify') {
      if ($live === $sig) { WP_CLI::success("menu {$menu->name} ($menu_id) matches data/menu.json (" . count($sig) . ' items)'); return; }
      foreach (array_diff($sig, $live) as $s) WP_CLI::log("missing: $s");
      foreach (array_diff($live, $sig) as $s) WP_CLI::log("unexpected: $s");
      WP_CLI::error("menu {$menu->name} differs from data/menu.json");
    }

    $write = function (array $items) use ($menu_id): void {
      foreach ((array)wp_get_nav_menu_items($menu_id, ['post_status' => 'any']) as $old) wp_delete_post($old->ID, true);
      $ids = [];
      foreach (array_values($items) as $n => $it) {
        $parent = $it['parent'] === null ? 0 : ($ids[$it['parent']] ?? 0);
        $id = wp_update_nav_menu_item($menu_id, 0, $it['fields'] + ['menu-item-title' => $it['title'], 'menu-item-parent-id' => $parent, 'menu-item-position' => $n + 1, 'menu-item-classes' => implode(' ', $it['classes']), 'menu-item-status' => 'publish']);
        if (is_wp_error($id)) WP_CLI::error("could not add '{$it['title']}': " . $id->get_error_message());
        $ids[$it['key']] = (int)$id;
      }
    };

    if ($sub === 'restore') {
      $file = (string)($assoc['from'] ?? '');
      $b = json_decode((string)@file_get_contents($file), true);
      if (!is_array($b) || ($b['menu'] ?? '') !== $menu->name || !isset($b['items'])) WP_CLI::error("$file is not a backup of menu {$menu->name}");
      $items = [];
      foreach ($b['items'] as $it) {
        $fields = $it['type'] === 'custom' ? ['menu-item-type' => 'custom', 'menu-item-url' => $it['url']] : ['menu-item-type' => $it['type'], 'menu-item-object' => $it['object'], 'menu-item-object-id' => (int)$it['object_id']];
        $fields += ['menu-item-target' => (string)($it['target'] ?? ''), 'menu-item-attr-title' => (string)($it['attr_title'] ?? ''), 'menu-item-xfn' => (string)($it['xfn'] ?? ''), 'menu-item-description' => (string)($it['description'] ?? '')];
        $items[] = ['key' => (int)$it['id'], 'parent' => (int)$it['parent'] ?: null, 'title' => (string)$it['title'], 'classes' => (array)$it['classes'], 'fields' => $fields];
      }
      $write($items);
      WP_CLI::success('restored ' . count($items) . " items to menu {$menu->name} ($menu_id) from $file");
      return;
    }

    if ($sub !== 'apply') WP_CLI::error('usage: wp lld menu apply [--dry-run] [--backup-dir=<dir>] | verify | restore --from=<file>');
    if ($live === $sig) { WP_CLI::success("menu {$menu->name} ($menu_id) already matches data/menu.json: nothing to change"); return; }
    $dry = !empty($assoc['dry-run']);
    foreach ($plan as $i => $row) if (!isset($skip[$i])) WP_CLI::log(($dry ? 'would add: ' : 'add: ') . str_repeat('  ', $row['parent'] === null ? 0 : 1) . $row['title'] . ($row['classes'] ? ' [' . implode(' ', $row['classes']) . ']' : ''));
    if ($dry) { WP_CLI::success('dry run: ' . count($sig) . " items would replace the " . count($live) . " in menu {$menu->name} ($menu_id)"); return; }

    // backup of every current item, outside the web root
    $env = defined('LLD_ENV') && LLD_ENV === 'staging' ? 'staging' : 'production';
    $dir = rtrim((string)($assoc['backup-dir'] ?? ((getenv('HOME') ?: dirname(rtrim(ABSPATH, '/\\'), 3)) . "/lld-backups/$env")), '/\\');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) WP_CLI::error("cannot create backup folder $dir");
    $old = (array)wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
    usort($old, fn($a, $b) => $a->menu_order <=> $b->menu_order);
    $backup = ['menu' => $menu->name, 'menu_id' => $menu_id, 'saved_at' => gmdate('c'), 'items' => array_map(fn($it) => [
      'id' => (int)$it->ID, 'parent' => (int)$it->menu_item_parent, 'order' => (int)$it->menu_order, 'title' => html_entity_decode((string)$it->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
      'type' => (string)$it->type, 'object' => (string)$it->object, 'object_id' => (int)$it->object_id, 'url' => (string)$it->url,
      'classes' => array_values(array_filter((array)$it->classes, 'strlen')), 'target' => (string)$it->target, 'attr_title' => (string)$it->attr_title,
      'xfn' => (string)$it->xfn, 'description' => (string)$it->description], $old)];
    $file = "$dir/menu-{$menu->name}-" . gmdate('Ymd\THis\Z') . '.json';
    if (@file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) WP_CLI::error("could not write backup $file");
    @chmod($file, 0600);
    WP_CLI::log("backup: $file (" . count($old) . ' items); undo with: wp lld menu restore --from=' . $file);

    $items = [];
    foreach ($plan as $i => $row) if (!isset($skip[$i])) $items[] = ['key' => $i, 'parent' => $row['parent'], 'title' => $row['title'], 'classes' => $row['classes'], 'fields' => $resolved[$i]];
    $write($items);
    if (has_action('litespeed_purge_all')) do_action('litespeed_purge_all');
    lld_menu_signature_live($menu_id) === $sig ? WP_CLI::success("menu {$menu->name} ($menu_id) rebuilt: " . count($sig) . ' items') : WP_CLI::error('menu rebuilt but verify differs: run wp lld menu verify');
  });
}
