// TC-FR1-03 Tools-first homepage — artefact half: the homepage transform (data/home.json, DEC-23) and the compact hub are checked
// through the real plugin with WordPress stubbed (no database): the Tools row follows the hero, the hero becomes desktop-only
// with a phone-only copy after the Tools row, the Learn row follows, the transform is idempotent, and NEG-01: removing the
// `[lld_tools_hub compact=1]` shortcode leaves no Tools row (AS-01/AS-05 would fail).
import { describe, expect, test } from 'vitest';
import { readFileSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { ROOT } from '../lib/paths.mjs';

const PLUGIN = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools');
const home = JSON.parse(readFileSync(resolve(PLUGIN, 'data', 'home.json'), 'utf8'));
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const HERO = '[vc_row full_width="stretch_row_1200 td-stretch-content" tdc_css="e30="][vc_column width="1/1"][td_flex_block_1 limit="4"][/vc_column][/vc_row]';
const REST = '[vc_row][vc_column][td_block_title custom_title="Latest"][/vc_column][/vc_row]';
const TEMPLATE = `[tdc_zone type="tdc_content"]${HERO}${REST}[/tdc_zone]`;

/** Run PHP against the real plugin with WordPress stubbed; `expr` is echoed. */
function php(expr, { pluginDir = PLUGIN } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'lld-home-'));
  try {
    writeFileSync(join(dir, 'status.json'), JSON.stringify(status));
    const d = dir.replace(/\\/g, '/');
    const code = `
      define('ABSPATH', '${d}/'); @mkdir(ABSPATH.'tools'); copy('${d}/status.json', ABSPATH.'tools/status.json');
      $GLOBALS['sc'] = []; function add_shortcode($t, $f) { $GLOBALS['sc'][$t] = $f; } function shortcode_atts($d, $a) { return array_merge($d, (array)$a); }
      function add_action() {} function wp_enqueue_style() {}
      function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } function esc_url($s) { return $s; }
      function home_url($p = '') { return 'https://example.test' . $p; } function untrailingslashit($s) { return rtrim($s, '/'); } function get_option($k, $d = null) { return $d; }
      function get_post($id) { return (object)['ID' => $id, 'post_status' => 'publish']; } function get_permalink($p) { return 'https://example.test/post/' . $p->ID . '/'; } function get_the_title($p) { return 'Post ' . $p->ID; }
      function wp_json_encode($v) { return json_encode($v); }
      require '${pluginDir.replace(/\\/g, '/')}/lld-tools.php';
      ${expr}`;
    const r = spawnSync('php', ['-r', code], { encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr || r.stdout);
    return r.stdout;
  } finally { rmSync(dir, { recursive: true, force: true }); }
}
const transform = (content, opts) => php(`echo lld_tools_home_content(${JSON.stringify(content)});`, opts);

describe('TC-FR1-03 tools-first homepage — artefacts', () => {
  test('TC-FR1-03-AS-07', () => {
    // DOM order of the Composer rows after the transform: hero (desktop-only) → Tools row → hero copy (phone-only) → Learn row → rest
    const out = transform(TEMPLATE);
    const rows = [...out.matchAll(/\[vc_row\b[^\]]*\]/g)].map((m) => m[0]);
    expect(rows.length).toBe(5);
    expect(rows[0]).toContain('el_class="lld-home-hero"');
    expect(rows[1]).toContain(`el_class="${home.marker_class}"`);
    expect(rows[2]).toContain('el_class="lld-home-hero-phone"');
    expect(rows[3]).toContain('el_class="lld-home-learn-row"');
    expect(out.indexOf(home.tools_shortcode), 'Tools row after the hero').toBeGreaterThan(out.indexOf('lld-home-hero"'));
    expect(out.indexOf(home.learn_shortcode), 'Learn row after the Tools row').toBeGreaterThan(out.indexOf(home.tools_shortcode));
    expect(out.endsWith(`${REST}[/tdc_zone]`), 'the rest of the template is untouched').toBe(true);
    expect(transform(out), 'idempotent').toBe(out);
    // the stylesheet makes the hero desktop-only and its copy phone-only
    const css = readFileSync(resolve(PLUGIN, 'assets', 'lld-tools.css'), 'utf8');
    expect(css).toMatch(/max-width:\s*767px[^}]*\.lld-home-hero\s*\{\s*display:\s*none/);
    expect(css).toMatch(/min-width:\s*768px[^}]*\.lld-home-hero-phone\s*\{\s*display:\s*none/);
  });

  test('TC-FR1-03-AS-05', () => {
    // the compact hub is the Tools row: h2 "Tools", exactly the two active cards, no planned chips; the Learn row heading is "Learn"
    const html = php(`echo lld_tools_render_hub(['compact' => '1']);`);
    expect(html).toMatch(/^<section class="lld-home-row lld-home-tools" data-test="tools-row"><h2 class="lld-home-row__title">Tools<\/h2>/);
    expect((html.match(/data-tool-card/g) || []).length).toBe(2);
    expect(html).not.toContain('label_only');
    expect(html.endsWith('</section></section>')).toBe(true);
    expect(home.headings_in_order).toEqual(['Tools', 'Learn']);
    const plugin = readFileSync(resolve(PLUGIN, 'lld-tools.php'), 'utf8');
    expect(plugin).toContain('data-test="learn-row"><h2 class="lld-home-row__title">Learn</h2>');
  });

  test('NEG-01', () => {
    // remove the shortcode from the transform → no Tools row in the template (AS-01 / AS-05 fail)
    const dir = mkdtempSync(join(tmpdir(), 'lld-neg-'));
    try {
      const { cpSync } = require('node:fs');
      cpSync(PLUGIN, join(dir, 'lld-tools'), { recursive: true });
      const h = JSON.parse(readFileSync(join(dir, 'lld-tools', 'data', 'home.json'), 'utf8'));
      h.tools_shortcode = ''; writeFileSync(join(dir, 'lld-tools', 'data', 'home.json'), JSON.stringify(h));
      const out = transform(TEMPLATE, { pluginDir: join(dir, 'lld-tools') });
      expect(out).not.toContain('[lld_tools_hub');
      expect(out, 'NEG-01: without the shortcode the template renders no Tools row').not.toContain('data-test="tools-row"');
    } finally { rmSync(dir, { recursive: true, force: true }); }
  });
});
