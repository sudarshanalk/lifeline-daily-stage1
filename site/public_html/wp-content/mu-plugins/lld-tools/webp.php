<?php
/**
 * WebP copies of uploaded images (S2-L1, FR2-27 AS-05: "images are served as WebP").
 *   LiteSpeed Cache's WebP replacement (on in its settings) rewrites image addresses in pages to <image>.webp for
 *   browsers that accept WebP, but only where that file exists beside the original. Its own converter needs a
 *   QUIC.cloud account, so this module makes the copies on the server with WordPress's image editor:
 *   - whenever WordPress saves an image's metadata (upload, regenerated sizes, edits), for the file and each size;
 *   - for the existing library with `wp lld webp generate [--dry-run] [--limit=<n>] [--ids=<id,id>]`;
 *     `wp lld webp status` counts the files that have a current copy and those still due.
 *   Originals are never changed. A copy is kept only when it is smaller than its original; otherwise LiteSpeed keeps
 *   serving the original. Deleting an attachment deletes its copies.
 * The pure builders take plain values so they are tested without WordPress (tests/lead/webp.test.mjs).
 */
if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------- pure builders ----------------------------------- */

const LLD_WEBP_QUALITY = 82; // the same as LiteSpeed's image quality setting (img_optm-jpg_quality) on this site

/** LiteSpeed's naming: the original's whole file name plus ".webp" (photo.png -> photo.png.webp). */
function lld_webp_path(string $file): string { return $file . '.webp'; }

/** JPEG and PNG only: a GIF may be animated, and WebP, AVIF and SVG need no copy. */
function lld_webp_convertible(string $file): bool { return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true); }

/** Every image file of one attachment: the file WordPress serves, each size, and the original kept before scaling. */
function lld_webp_files(string $file, array $meta): array {
  $dir = dirname($file);
  $out = [$file];
  foreach ((array)($meta['sizes'] ?? []) as $s) if (is_array($s) && !empty($s['file'])) $out[] = $dir . '/' . basename((string)$s['file']);
  if (!empty($meta['original_image'])) $out[] = $dir . '/' . basename((string)$meta['original_image']);
  return array_values(array_unique(array_filter($out, 'lld_webp_convertible')));
}

/** A copy is worth keeping only when it is smaller than its original. */
function lld_webp_keep(int $original_bytes, int $webp_bytes): bool { return $webp_bytes > 0 && $webp_bytes < $original_bytes; }

/** A copy is due when there is none, or the original changed after it was made. */
function lld_webp_due(int $original_mtime, ?int $webp_mtime): bool { return $webp_mtime === null || $webp_mtime < $original_mtime; }

/** The theme's lazy thumbnails (Newspaper's <span data-img-url>) added to LiteSpeed's WebP attribute list, once. */
const LLD_WEBP_THEME_ATTRS = ['span.data-img-url'];
function lld_webp_attrs(array $attrs): array { return array_values(array_unique(array_merge($attrs, LLD_WEBP_THEME_ATTRS))); }

/* ---------------------------------------------------------------- WordPress wiring -------------------------------- */
if (!function_exists('add_action')) return;

/** One file's copy: 'made', 'current', 'due' (dry run), 'larger' (not kept), 'missing' or 'failed: <reason>'. */
function lld_webp_make(string $file, bool $dry = false): string {
  if (!is_file($file)) return 'missing';
  $dest = lld_webp_path($file);
  if (!lld_webp_due((int)filemtime($file), is_file($dest) ? (int)filemtime($dest) : null)) return 'current';
  if ($dry) return 'due';
  try {
    $editor = wp_get_image_editor($file);
    if (is_wp_error($editor)) return 'failed: ' . $editor->get_error_message();
    $editor->set_quality(LLD_WEBP_QUALITY);
    $saved = $editor->save($file . '.lld-tmp.webp', 'image/webp');
    if (is_wp_error($saved)) return 'failed: ' . $saved->get_error_message();
    $tmp = (string)$saved['path'];
    if (($saved['mime-type'] ?? '') !== 'image/webp') { @unlink($tmp); return 'failed: saved as ' . ($saved['mime-type'] ?? 'unknown'); }
    clearstatcache();
    if (!lld_webp_keep((int)filesize($file), (int)filesize($tmp))) { @unlink($tmp); if (is_file($dest)) @unlink($dest); return 'larger'; }
    if (!@rename($tmp, $dest)) { @unlink($tmp); return 'failed: could not write ' . basename($dest); }
    return 'made';
  } catch (\Throwable $e) {
    return 'failed: ' . $e->getMessage();
  }
}

/** Copies for every file of one attachment, keyed by file. */
function lld_webp_attachment(int $id, ?array $meta = null, bool $dry = false): array {
  $file = get_attached_file($id);
  if (!$file) return [];
  $meta = $meta ?? wp_get_attachment_metadata($id);
  $out = [];
  foreach (lld_webp_files($file, is_array($meta) ? $meta : []) as $f) $out[$f] = lld_webp_make($f, $dry);
  return $out;
}

// Runs on upload (once per size as WordPress adds them), on regenerated sizes and on edits; files already current are skipped.
add_filter('wp_update_attachment_metadata', function ($meta, $id) {
  if (is_array($meta) && wp_attachment_is_image($id)) lld_webp_attachment((int)$id, $meta);
  return $meta;
}, 20, 2);

// LiteSpeed only swaps the attributes in its list; the theme's list and related-article thumbnails are background images
// whose address is in <span data-img-url>. Forced in memory on each request, so the saved setting stays as it is.
add_action('litespeed_init', function () {
  $attrs = apply_filters('litespeed_conf', 'img_optm-webp_attr');
  if (is_array($attrs) && $attrs !== lld_webp_attrs($attrs)) do_action('litespeed_conf_force', 'img_optm-webp_attr', lld_webp_attrs($attrs));
});

add_action('delete_attachment', function ($id) {
  $file = get_attached_file((int)$id);
  if (!$file) return;
  $meta = wp_get_attachment_metadata((int)$id);
  foreach (lld_webp_files($file, is_array($meta) ? $meta : []) as $f) if (is_file(lld_webp_path($f))) @unlink(lld_webp_path($f));
});

if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('lld webp', function (array $args, array $assoc) {
    $sub = $args[0] ?? 'status';
    if (!in_array($sub, ['status', 'generate'], true)) WP_CLI::error("unknown subcommand '$sub' (use: webp status | webp generate [--dry-run] [--limit=<n>] [--ids=<id,id>])");
    $dry = $sub === 'status' || !empty($assoc['dry-run']);
    $q = ['post_type' => 'attachment', 'post_status' => 'any', 'post_mime_type' => ['image/jpeg', 'image/png'], 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC'];
    if (!empty($assoc['ids'])) $q['post__in'] = array_map('intval', explode(',', (string)$assoc['ids']));
    $ids = get_posts($q);
    $limit = max(0, (int)($assoc['limit'] ?? 0));
    $count = [];
    $worked = 0;
    foreach ($ids as $id) {
      $made = false;
      foreach (lld_webp_attachment((int)$id, null, $dry) as $f => $s) {
        $k = strpos($s, 'failed') === 0 ? 'failed' : $s;
        $count[$k] = ($count[$k] ?? 0) + 1;
        if ($k === 'failed') WP_CLI::warning("attachment $id, " . basename($f) . ": $s");
        if ($s === 'made' || $s === 'due') $made = true;
      }
      if ($made && $limit && ++$worked >= $limit) break;
    }
    ksort($count);
    WP_CLI::log(count($ids) . ' image attachments; files: ' . ($count ? implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($count), $count)) : 'none'));
    WP_CLI::success($sub === 'status' ? 'status' : ($dry ? 'dry run, nothing written' : 'copies generated'));
  });
}
