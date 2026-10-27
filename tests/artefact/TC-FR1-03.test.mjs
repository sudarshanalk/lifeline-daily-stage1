// TC-FR1-03 Tools-first homepage — artefact half: the homepage transform (data/home.json, `wp lld home apply|revert`, DEC-23)
// and the rendered homepage are checked through the real plugin with WordPress stubbed (no database), so these run on CI:
// AS-07 row order (hero desktop-only → Tools row → Learn row; nothing duplicated), idempotence and an exact revert; AS-05 the
// rendered homepage's h2 sequence has "Tools" before "Learn" with exactly the two active cards; NEG-01 the same rendering with
// the `[lld_tools_hub compact=1]` shortcode removed has no Tools row and fails the AS-05 check.
import { describe, expect, test } from 'vitest';
import { readFileSync, mkdtempSync, writeFileSync, rmSync, cpSync } from 'node:fs';
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

/** Run PHP against the real plugin with WordPress stubbed (shortcodes expand through the plugin's own callbacks). */
function php(expr, { pluginDir = PLUGIN } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'lld-home-'));
  try {
    writeFileSync(join(dir, 'status.json'), JSON.stringify(status));
    const d = dir.replace(/\\/g, '/');
    const code = `
      define('ABSPATH', '${d}/'); @mkdir(ABSPATH.'tools'); copy('${d}/status.json', ABSPATH.'tools/status.json');
      $GLOBALS['sc'] = []; function add_shortcode($t, $f) { $GLOBALS['sc'][$t] = $f; } function shortcode_atts($d, $a) { return array_merge($d, (array)$a); }
      function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } function esc_url($s) { return $s; }
      function home_url($p = '') { return 'https://example.test' . $p; } function untrailingslashit($s) { return rtrim($s, '/'); } function get_option($k, $d = null) { return $d; }
      function get_post($id) { return (object)['ID' => $id, 'post_status' => 'publish']; } function get_permalink($p) { return 'https://example.test/post/' . $p->ID . '/'; } function get_the_title($p) { return 'Post ' . $p->ID; }
      function get_the_date($f = '', $p = null) { return $f === 'c' ? '2026-10-20T09:00:00+00:00' : '20 October 2026'; } function wp_json_encode($v) { return json_encode($v); }
      class WP_Query { public $posts; function __construct($a) { $this->posts = array_map(fn($i) => (object)['ID' => 9000 + $i, 'post_status' => 'publish'], range(1, $a['posts_per_page'])); } }
      /* minimal do_shortcode: [name a="b" c=d] → registered callback; Composer wrappers ([vc_row] …) become plain divs */
      function render_home($c) {
        $c = preg_replace_callback('/\\[(lld_[a-z_]+)([^\\]]*)\\]/', function ($m) { preg_match_all('/(\\w+)="?([^"\\s\\]]*)"?/', $m[2], $kv, PREG_SET_ORDER); $atts = []; foreach ($kv as $x) $atts[$x[1]] = $x[2]; return isset($GLOBALS['sc'][$m[1]]) ? call_user_func($GLOBALS['sc'][$m[1]], $atts) : ''; }, $c);
        $c = preg_replace('/\\[vc_row\\b([^\\]]*)\\]/', '<div class="vc_row">', $c); $c = str_replace('[/vc_row]', '</div>', $c);
        return preg_replace('/\\[\\/?[a-z_]+[^\\]]*\\]/', '', $c);
      }
      require '${pluginDir.replace(/\\/g, '/')}/lld-tools.php';
      ${expr}`;
    const r = spawnSync('php', ['-r', code], { encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr || r.stdout);
    return r.stdout;
  } finally { rmSync(dir, { recursive: true, force: true }); }
}
const transform = (content, opts) => php(`echo lld_tools_home_content(${JSON.stringify(content)});`, opts);
const revert = (content) => php(`echo lld_tools_home_revert(${JSON.stringify(content)});`);
const renderHome = (content, opts) => php(`echo render_home(${JSON.stringify(content)});`, opts);
/** The AS-05 check applied to rendered HTML: a Tools row exists and the h2 sequence has "Tools" before "Learn". */
const as05 = (html) => {
  const h2 = [...html.matchAll(/<h2[^>]*>([^<]*)<\/h2>/g)].map((m) => m[1].trim());
  return html.includes('data-test="tools-row"') && h2.includes('Tools') && h2.includes('Learn') && h2.indexOf('Tools') < h2.indexOf('Learn');
};

describe('TC-FR1-03 tools-first homepage — artefacts', () => {
  test('TC-FR1-03-AS-07', () => {
    const out = transform(TEMPLATE);
    const rows = [...out.matchAll(/\[vc_row\b[^\]]*\]/g)].map((m) => m[0]);
    expect(rows.length, 'hero, Tools, Learn, rest — nothing duplicated').toBe(4);
    expect(rows[0]).toContain('el_class="lld-home-hero"');
    expect(rows[1]).toContain(`el_class="${home.marker_class}"`);
    expect(rows[2]).toContain('el_class="lld-home-learn-row"');
    expect(out.split('td_flex_block_1').length - 1, 'the hero block appears once').toBe(1);
    expect(out.endsWith(`${REST}[/tdc_zone]`), 'the rest of the template is untouched').toBe(true);
    expect(transform(out), 'idempotent').toBe(out);
    expect(revert(out), 'revert restores the original template exactly').toBe(TEMPLATE);
    expect(transform('[tdc_zone]<p>no rows</p>[/tdc_zone]'), 'no Composer row: empty result, the CLI refuses').toBe('');
    // the stylesheet hides the hero on phones (so the Tools row is the first content row there) and nothing else
    const css = readFileSync(resolve(PLUGIN, 'assets', 'lld-tools.css'), 'utf8');
    expect(css).toMatch(/max-width:\s*767px[^}]*\.lld-home-hero\s*\{\s*display:\s*none/);
    expect(css).not.toContain('lld-home-hero-phone');
  });

  test('TC-FR1-03-AS-05', () => {
    const html = renderHome(transform(TEMPLATE));
    expect(as05(html), 'rendered homepage: Tools row, h2 "Tools" before "Learn"').toBe(true);
    const tools = html.slice(html.indexOf('data-test="tools-row"'), html.indexOf('data-test="learn-row"'));
    // every active card (two in Stage 1; the Healthy Weight Check joins once installed, FR2-39 / D49)
    const active = JSON.parse(readFileSync(resolve(PLUGIN, 'data', 'tool-cards.json'), 'utf8')).cards.filter((c) => status.tools[c.tool_slug]?.status === 'active').length;
    expect((tools.match(/data-tool-card/g) || []).length, 'exactly the active cards').toBe(active);
    expect(tools).not.toContain('label_only');
    expect((html.slice(html.indexOf('data-test="learn-row"')).match(/<article /g) || []).length, 'Learn row lists the latest posts').toBe(6);
  });

  test('NEG-01', () => {
    // remove the shortcode from the transform → the rendered homepage has no Tools row and fails the AS-05 check (AS-01 has no row to measure)
    const dir = mkdtempSync(join(tmpdir(), 'lld-neg-'));
    try {
      cpSync(PLUGIN, join(dir, 'lld-tools'), { recursive: true });
      const h = JSON.parse(readFileSync(join(dir, 'lld-tools', 'data', 'home.json'), 'utf8'));
      h.tools_shortcode = ''; writeFileSync(join(dir, 'lld-tools', 'data', 'home.json'), JSON.stringify(h));
      const html = renderHome(transform(TEMPLATE, { pluginDir: join(dir, 'lld-tools') }), { pluginDir: join(dir, 'lld-tools') });
      expect(html).not.toContain('data-test="tools-row"');
      expect(as05(html), 'NEG-01: AS-05 fails without the shortcode').toBe(false);
      expect(as05(renderHome(transform(TEMPLATE))), 'control: AS-05 passes with it').toBe(true);
    } finally { rmSync(dir, { recursive: true, force: true }); }
  });
});
