<?php
/**
 * Approved content loader (S2-L6; Data Model B16; owner, 31 Oct 2026: "Load as approved").
 *   `wp lld content load --file=<bundle.json> [--dry-run] [--backup-dir=<dir>] --user=<owner admin>` puts the owner-approved
 *   drafts into the lld-members content library. Each item becomes a working copy keyed by its bundle key, its fields'
 *   "@key" references become the linked items' post ids, and its version is approved in the name of the --user admin, with
 *   the approval-page decision and the draft's fingerprint recorded on the approved version. A second run changes only
 *   items whose content changed. `wp lld content verify --file=<bundle.json>` checks that members are shown every item.
 *   The bundle is built outside the repository from local/content-stage2 (scripts/content-bundle) and never committed.
 * The pure checks take plain arrays so they are tested without WordPress (tests/lead/content-load.test.mjs).
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

const LLD_CONTENT_TYPES = ['lld_goal', 'lld_track', 'lld_week', 'lld_lesson', 'lld_activity', 'lld_challenge', 'lld_recipe', 'lld_meal',
  'lld_workout', 'lld_rule', 'lld_badge', 'lld_matrix', 'lld_wording'];

/** Replace "@key" references with post ids: a value that is one reference becomes an int, references inside a longer
 *  string (an "@a,@b" list) become their ids. Keys not in $ids are added to $missing and left as they were. */
function lld_content_resolve($value, array $ids, array &$missing) {
  if (is_array($value)) {
    $out = [];
    foreach ($value as $k => $v) $out[$k] = lld_content_resolve($v, $ids, $missing);
    return $out;
  }
  if (!is_string($value) || strpos($value, '@') === false) return $value;
  if (preg_match('/^@([A-Za-z0-9:._-]+)$/', $value, $m)) {
    if (isset($ids[$m[1]])) return (int)$ids[$m[1]];
    $missing[] = $m[1];
    return $value;
  }
  return preg_replace_callback('/@([A-Za-z0-9:._-]+)/', function ($m) use ($ids, &$missing) {
    if (isset($ids[$m[1]])) return (string)(int)$ids[$m[1]];
    $missing[] = $m[1];
    return $m[0];
  }, $value);
}

/** Every "@key" a value refers to. */
function lld_content_refs($value): array {
  if (is_array($value)) return $value ? array_values(array_unique(array_merge(...array_map('lld_content_refs', array_values($value))))) : [];
  if (!is_string($value)) return [];
  preg_match_all('/@([A-Za-z0-9:._-]+)/', $value, $m);
  return array_values(array_unique($m[1]));
}

/** The NFR2-07 safety lines an item lacks, by the rules the lld-members approval screen applies before it lets the owner
 *  approve (LLD_Members_Content::approval_block): stress, mood and Sleep and Stress content links to urgent help, sleep
 *  content advises a doctor about loud snoring with daytime sleepiness, and Blood Sugar Balance content tells anyone on
 *  diabetes medication to talk to their doctor first. $track is the title of the track the item belongs to, if any. */
function lld_content_safety_missing(array $it, string $track = ''): array {
  $tags = array_map('strtolower', array_map('strval', (array)($it['tags'] ?? [])));
  $track = strtolower($track);
  $text = strtolower((string)($it['title'] ?? '') . ' ' . (string)($it['body'] ?? ''));
  $missing = [];
  if ((array_intersect($tags, ['stress', 'mood', 'sleep-stress']) || strpos($track, 'sleep') !== false || strpos($track, 'stress') !== false) && strpos($text, 'findahelpline.com') === false) $missing[] = 'the urgent-help link (findahelpline.com)';
  if ((array_intersect($tags, ['sleep', 'sleep-stress']) || strpos($track, 'sleep') !== false) && strpos($text, 'loud snoring with daytime sleepiness') === false) $missing[] = 'the loud snoring with daytime sleepiness advice';
  if ((in_array('blood-sugar', $tags, true) || strpos($track, 'blood sugar') !== false) && strpos($text, 'diabetes medication') === false) $missing[] = 'the diabetes medication note';
  return $missing;
}

/** The problems that stop a bundle loading: shape, unknown types, repeated keys, references to keys not listed before the
 *  item (the library fingerprints a link by the linked item's approved version, so it must be approved first), tags
 *  that are not slugs, breath-holding (D36), a missing NFR2-07 safety line, and an item without the approval-page
 *  decision it was approved under. */
function lld_content_check(array $bundle): array {
  $problems = [];
  if (!isset($bundle['items']) || !is_array($bundle['items']) || !$bundle['items']) return ['the bundle has no items'];
  $seen = []; $titles = [];
  foreach ($bundle['items'] as $i => $it) {
    $where = 'item ' . ($i + 1) . (isset($it['key']) ? ' (' . $it['key'] . ')' : '');
    if (!is_array($it)) { $problems[] = "$where is not an object"; continue; }
    $key = (string)($it['key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9:._-]{1,190}$/', $key)) $problems[] = "$where has no valid key";
    elseif (isset($seen[$key])) $problems[] = "$where repeats a key";
    if (!in_array($it['type'] ?? '', LLD_CONTENT_TYPES, true)) $problems[] = "$where has an unknown type";
    if (trim((string)($it['title'] ?? '')) === '') $problems[] = "$where has no title";
    if (isset($it['fields']) && !is_array($it['fields'])) $problems[] = "$where has fields that are not an object";
    foreach (lld_content_refs($it['fields'] ?? []) as $ref) if (!isset($seen[$ref])) $problems[] = "$where refers to @$ref, which is not listed before it";
    foreach ((array)($it['tags'] ?? []) as $tag) if (!is_string($tag) || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $tag)) $problems[] = "$where has a tag that is not a slug";
    if (preg_grep('/breath/', array_map('strval', (array)($it['tags'] ?? [])))) $problems[] = "$where has a breath-holding tag";
    // safety advice such as "never hold your breath" stays exactly as the owner approved it; an instruction to hold it is refused
    $text = strtolower((string)($it['title'] ?? '') . ' ' . (string)($it['body'] ?? '') . ' ' . json_encode($it['fields'] ?? [], JSON_UNESCAPED_UNICODE));
    if (preg_match('/(?<!never |not |n\'t |do not |without |avoid |no )(breath[- ]?holding|hold(ing)? your breath)/', $text)) $problems[] = "$where mentions breath-holding";
    $track_ref = (string)($it['fields']['track_id'] ?? '');
    $track = preg_match('/^@([A-Za-z0-9:._-]+)$/', $track_ref, $m) ? (string)($titles[$m[1]] ?? '') : '';
    foreach (lld_content_safety_missing($it, $track) as $line) $problems[] = "$where lacks $line (NFR2-07)";
    $ap = (array)($it['approval'] ?? []);
    if (!preg_match('/^d-[a-z0-9-]+$/', (string)($ap['decision'] ?? '')) || !preg_match('/^[0-9a-f]{16}$/', (string)($ap['sha'] ?? ''))) $problems[] = "$where has no approval decision and fingerprint";
    if ($key !== '') { $seen[$key] = true; $titles[$key] = (string)($it['title'] ?? ''); }
  }
  return $problems;
}

/** A stable fingerprint of what the bundle says about one item, so a second run skips items that did not change. */
function lld_content_item_hash(array $it): string {
  $tags = array_map('strval', (array)($it['tags'] ?? []));
  sort($tags);
  $sorted = function ($v) use (&$sorted) { if (!is_array($v)) return $v; if (array_keys($v) !== range(0, count($v) - 1)) ksort($v); return array_map($sorted, $v); };
  return substr(hash('sha256', json_encode([(string)$it['type'], (string)$it['title'], (string)($it['slug'] ?? ''), (string)($it['body'] ?? ''), $tags,
    $sorted((array)($it['fields'] ?? [])), (array)($it['approval'] ?? [])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
}

/** create, update or same for each item, given the stored hash of every key already loaded. */
function lld_content_plan(array $bundle, array $loaded): array {
  $plan = [];
  foreach ($bundle['items'] as $it) {
    $key = (string)$it['key'];
    $plan[$key] = !isset($loaded[$key]) ? 'create' : ($loaded[$key] === lld_content_item_hash($it) ? 'same' : 'update');
  }
  return $plan;
}

if (!function_exists('add_filter')) return;

if (defined('WP_CLI') && WP_CLI) {
  /** wp lld content load --file=<bundle> [--dry-run] [--backup-dir=<dir>] | verify --file=<bundle> */
  WP_CLI::add_command('lld content', function (array $args, array $assoc) {
    $sub = $args[0] ?? '';
    if (!in_array($sub, ['load', 'verify'], true)) WP_CLI::error('usage: wp lld content load --file=<bundle.json> [--dry-run] [--backup-dir=<dir>] --user=<owner admin> | verify --file=<bundle.json>');
    if (!class_exists('LLD_Members_Content')) WP_CLI::error('the lld-members plugin is not active');
    $file = (string)($assoc['file'] ?? '');
    $bundle = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($bundle)) WP_CLI::error("cannot read a bundle from '$file'");
    if ($problems = lld_content_check($bundle)) WP_CLI::error("the bundle cannot load:\n- " . implode("\n- ", array_slice($problems, 0, 40)));

    // what is already loaded: bundle key => working copy
    $rows = get_posts(['post_type' => LLD_CONTENT_TYPES, 'post_status' => ['publish', 'draft', 'pending', 'private'], 'post_parent' => 0,
      'posts_per_page' => -1, 'meta_key' => '_lld_source_key']);
    $existing = []; $loaded = [];
    foreach ($rows as $p) { $k = (string)get_post_meta($p->ID, '_lld_source_key', true); $existing[$k] = (int)$p->ID; $loaded[$k] = (string)get_post_meta($p->ID, '_lld_bundle_hash', true); }

    if ($sub === 'verify') {
      $bad = [];
      foreach ($bundle['items'] as $it) {
        $id = $existing[$it['key']] ?? 0;
        if (!$id) { $bad[] = $it['key'] . ': not loaded'; continue; }
        $shown = LLD_Members_Content::shown($id);
        if (!is_array($shown)) $bad[] = $it['key'] . ": members are not shown it (#$id)";
        elseif ($loaded[$it['key']] !== lld_content_item_hash($it)) $bad[] = $it['key'] . ": the library differs from the bundle (#$id)";
      }
      $bad ? WP_CLI::error(count($bad) . " of " . count($bundle['items']) . " items fail:\n- " . implode("\n- ", array_slice($bad, 0, 40))) : WP_CLI::success(count($bundle['items']) . ' items loaded, approved and shown to members');
      return;
    }

    $plan = lld_content_plan($bundle, $loaded);
    $by = [];
    foreach ($bundle['items'] as $it) $by[$it['type']][$plan[$it['key']]] = ($by[$it['type']][$plan[$it['key']]] ?? 0) + 1;
    foreach ($by as $type => $n) WP_CLI::log(sprintf('%-14s %s', $type, implode(', ', array_map(fn($a, $c) => "$c $a", array_keys($n), $n))));
    $changes = count(array_filter($plan, fn($a) => $a !== 'same'));
    if (!empty($assoc['dry-run'])) { WP_CLI::success("dry run: $changes of " . count($plan) . ' items would be created or updated'); return; }
    if (!current_user_can('manage_options')) WP_CLI::error('run it as the owner admin with --user=<login>: the approval records name who approved');
    $missing_tags = [];
    foreach ($bundle['items'] as $it) foreach ((array)($it['tags'] ?? []) as $t) if (!term_exists($t, 'lld_tag')) $missing_tags[$t] = true;
    if ($missing_tags) WP_CLI::error('these tags are not in the managed tag list: ' . implode(', ', array_keys($missing_tags)));
    if (!$changes) { WP_CLI::success('the library already matches the bundle: nothing to change'); return; }

    // backup of every library item the bundle touches, outside the web root
    $env = defined('LLD_ENV') && LLD_ENV === 'staging' ? 'staging' : 'production';
    $dir = rtrim((string)($assoc['backup-dir'] ?? ((getenv('HOME') ?: dirname(rtrim(ABSPATH, '/\\'), 3)) . "/lld-backups/$env")), '/\\');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) WP_CLI::error("cannot create backup folder $dir");
    $saved = [];
    foreach ($existing as $k => $id) $saved[] = ['key' => $k, 'id' => $id, 'type' => get_post_type($id), 'title' => get_the_title($id),
      'body' => (string)get_post_field('post_content', $id, 'raw'), 'fields' => get_post_meta($id, '_lld_fields', true),
      'tags' => wp_get_object_terms($id, 'lld_tag', ['fields' => 'slugs']), 'hash' => $loaded[$k]];
    $bfile = "$dir/content-library-" . gmdate('Ymd\THis\Z') . '.json';
    if (@file_put_contents($bfile, json_encode(['saved_at' => gmdate('c'), 'items' => $saved], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) WP_CLI::error("could not write backup $bfile");
    @chmod($bfile, 0600);
    WP_CLI::log("backup: $bfile (" . count($saved) . ' items already loaded)');

    $ids = $existing; $done = 0; $page = (string)($bundle['approval_page'] ?? '');
    foreach ($bundle['items'] as $it) {
      $key = (string)$it['key'];
      if ($plan[$key] === 'same') {
        // an earlier run that stopped part-way may have left the item waiting
        if (!is_array(LLD_Members_Content::shown($ids[$key]))) LLD_Members_Content::approve_now($ids[$key]);
        continue;
      }
      $missing = [];
      $fields = lld_content_resolve((array)($it['fields'] ?? []), $ids, $missing);
      if ($missing) WP_CLI::error("$key: unresolved " . implode(', ', $missing) . " (stopped after $done changes; run it again to finish)");
      $post = ['post_type' => (string)$it['type'], 'post_status' => 'publish', 'post_title' => (string)$it['title'], 'post_content' => (string)($it['body'] ?? ''),
        'meta_input' => ['_lld_fields' => $fields, '_lld_source_key' => $key], 'tax_input' => ['lld_tag' => array_values((array)($it['tags'] ?? []))]];
      if (!empty($it['slug'])) $post['post_name'] = (string)$it['slug'];
      if (isset($ids[$key])) $post['ID'] = $ids[$key];
      $id = isset($post['ID']) ? wp_update_post(wp_slash($post), true) : wp_insert_post(wp_slash($post), true);
      if (is_wp_error($id) || !$id) WP_CLI::error("$key: " . (is_wp_error($id) ? $id->get_error_message() : 'not saved') . " (stopped after $done changes)");
      $id = (int)$id;
      wp_set_object_terms($id, array_values((array)($it['tags'] ?? [])), 'lld_tag');
      $version = LLD_Members_Content::approve_now($id);
      if (!$version || !is_array(LLD_Members_Content::shown($id))) WP_CLI::error("$key: the approved version is not shown to members (#$id); check the approvals screen");
      $ap = (array)$it['approval'];
      update_post_meta($version, '_lld_approval_source', trim("$page {$ap['decision']} " . ($ap['file'] ?? '') . " sha256:{$ap['sha']}"));
      update_post_meta($id, '_lld_bundle_hash', lld_content_item_hash($it));
      $ids[$key] = $id; $done++;
      if ($done % 50 === 0) WP_CLI::log("$done changes saved");
    }
    if (has_action('litespeed_purge_all')) do_action('litespeed_purge_all');
    WP_CLI::success("$done items created or updated and approved as " . wp_get_current_user()->user_login . '; check with: wp lld content verify --file=' . $file);
  });
}
