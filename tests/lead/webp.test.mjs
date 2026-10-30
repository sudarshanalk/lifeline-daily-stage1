// WebP copies (S2-L1, FR2-27 AS-05): lld-tools/webp.php builders, run in PHP CLI without WordPress.
// The live check (a browser that accepts WebP gets .webp images on staging; one that does not gets the originals)
// is recorded in the tracker, as TC-FR2-27-AS-05 is a crawl check.
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { ROOT } from '../lib/paths.mjs';

const PHP_FILE = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'webp.php');

function call(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(PHP_FILE.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}

describe('WebP copies of uploaded images', () => {
  test('a copy is named the way LiteSpeed looks for it: the whole original name plus .webp', () => {
    expect(call('lld_webp_path', '/up/2026/10/keto-flu.jpg')).toBe('/up/2026/10/keto-flu.jpg.webp');
    expect(call('lld_webp_path', '/up/2023/05/LLD-website-images-1.0-17-300x253.png')).toBe('/up/2023/05/LLD-website-images-1.0-17-300x253.png.webp');
  });

  test('only JPEG and PNG get copies; GIF, WebP, AVIF and SVG do not', () => {
    for (const f of ['a.jpg', 'a.JPEG', 'a.png', 'b.PNG']) expect(call('lld_webp_convertible', f)).toBe(true);
    for (const f of ['a.gif', 'a.webp', 'a.png.webp', 'a.avif', 'a.svg', 'a.pdf', 'noext']) expect(call('lld_webp_convertible', f)).toBe(false);
  });

  test('an attachment covers its file, each size and the original kept before scaling, once each', () => {
    const meta = {
      sizes: { thumbnail: { file: 'photo-150x150.jpg' }, medium: { file: 'photo-300x200.jpg' }, dup: { file: 'photo-300x200.jpg' }, odd: { file: '' }, gif: { file: 'photo.gif' } },
      original_image: 'photo.jpg',
    };
    expect(call('lld_webp_files', '/up/2026/10/photo-scaled.jpg', meta)).toEqual([
      '/up/2026/10/photo-scaled.jpg', '/up/2026/10/photo-150x150.jpg', '/up/2026/10/photo-300x200.jpg', '/up/2026/10/photo.jpg',
    ]);
    // no metadata (an import that never made sizes): just the file
    expect(call('lld_webp_files', '/up/a.png', {})).toEqual(['/up/a.png']);
    // a size name with a path in it stays in the attachment's folder
    expect(call('lld_webp_files', '/up/a.png', { sizes: { x: { file: '../../evil.png' } } })).toEqual(['/up/a.png', '/up/evil.png']);
    expect(call('lld_webp_files', '/up/anim.gif', {})).toEqual([]);
  });

  test('a copy is kept only when it is smaller than the original', () => {
    expect(call('lld_webp_keep', 100000, 40000)).toBe(true);
    expect(call('lld_webp_keep', 100000, 100000)).toBe(false);
    expect(call('lld_webp_keep', 100000, 120000)).toBe(false);
    expect(call('lld_webp_keep', 100000, 0)).toBe(false);
  });

  test('a copy is made when missing or older than its original, and left alone when current', () => {
    expect(call('lld_webp_due', 1000, null)).toBe(true);
    expect(call('lld_webp_due', 1000, 999)).toBe(true);
    expect(call('lld_webp_due', 1000, 1000)).toBe(false);
    expect(call('lld_webp_due', 1000, 2000)).toBe(false);
  });
});
