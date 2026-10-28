// TC-FR1-04 Tools hub and landing pages — artefact half: the plugin's card data equals content/tool-cards.json (SCH-04, AS-06
// source of truth), the landing copy declares the eight sections in order (AS-04), the "How it works" copy names the engine
// and carries the deployed-version shortcode (AS-05), and NEG-01: a third active card in a sandbox status.json/data makes the
// hub render three active cards (which AS-01 then fails).
import { describe, expect, test } from 'vitest';
import { readFileSync, mkdtempSync, writeFileSync, rmSync, cpSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { ROOT } from '../lib/paths.mjs';
import { validateFile } from '../lib/validate.mjs';
import { fixtures } from '../lib/fixtures.mjs';

const PLUGIN = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools');
const cards = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8'));
const landing = JSON.parse(readFileSync(resolve(PLUGIN, 'data', 'landing-pages.json'), 'utf8'));

/** Render the hub through the real plugin with WordPress stubbed (no database): returns the HTML. */
function renderHub(pluginDir, statusJson) {
  const dir = mkdtempSync(join(tmpdir(), 'lld-hub-'));
  try {
    writeFileSync(join(dir, 'status.json'), JSON.stringify(statusJson));
    const php = `
      define('ABSPATH', '${dir.replace(/\\/g, '/')}/'); @mkdir(ABSPATH.'tools'); copy('${dir.replace(/\\/g, '/')}/status.json', ABSPATH.'tools/status.json');
      $GLOBALS['sc'] = []; function add_shortcode($t, $f) { $GLOBALS['sc'][$t] = $f; } function shortcode_atts($d, $a) { return array_merge($d, (array)$a); }
      function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } function esc_url($s) { return $s; }
      function home_url($p = '') { return 'https://example.test' . $p; } function untrailingslashit($s) { return rtrim($s, '/'); } function get_option($k, $d = null) { return $d; }
      function get_post($id) { return (object)['ID' => $id, 'post_status' => 'publish']; } function get_permalink($p) { return 'https://example.test/post/' . $p->ID . '/'; } function get_the_title($p) { return 'Post ' . $p->ID; }
      function wp_json_encode($v) { return json_encode($v); }
      require '${pluginDir.replace(/\\/g, '/')}/lld-tools.php';
      echo lld_tools_render_hub([]);`;
    const r = spawnSync('php', ['-r', php], { encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr);
    return r.stdout;
  } finally { rmSync(dir, { recursive: true, force: true }); }
}
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));

describe('TC-FR1-04 tools hub and landing pages — artefacts', () => {
  test('TC-FR1-04-AS-06', () => {
    expect(validateFile('tool-card.schema.json', resolve(ROOT, 'content', 'tool-cards.json')).ok).toBe(true);
    // the landing URLs are two of the eight pages in the FX-WP-URLS sample rule (SRS §13)
    const sample = fixtures('FX-WP-URLS')[0]; expect(sample.inputs.rule).toMatch(/8 pages/);
    for (const c of cards.cards) expect(c.landing_url, `${c.tool_slug}: landing page URL`).toMatch(/^\/health-tools\/[a-z-]+\/$/);
    expect(JSON.parse(readFileSync(resolve(PLUGIN, 'data', 'tool-cards.json'), 'utf8')), 'the plugin ships the same card data as content/tool-cards.json').toEqual(cards);
    const html = renderHub(PLUGIN, status);
    for (const c of cards.cards) {
      expect(html).toContain(`data-tool="${c.tool_slug}" data-status="active"`);
      for (const text of [c.name, c.purpose, c.eligibility, c.cta_label, c.landing_url, ...c.inputs]) expect(html, `${c.tool_slug}: ${text.slice(0, 30)}`).toContain(text.replace(/&/g, '&amp;'));
      expect(html).toContain(`About ${c.time_minutes} minutes`);
    }
    expect((html.match(/data-status="active"/g) || []).length).toBe(cards.cards.length);   // every card active (FR2-39 adds the third)
    expect((html.match(/data-status="label_only"/g) || []).length).toBe(status.labels.filter((l) => l.status === 'label_only').length);
    expect(html).not.toMatch(/data-status="label_only"[^]*?<a /);
  });

  test('TC-FR1-04-AS-04', () => {
    expect(landing.headings).toEqual(['Purpose', 'Who it is for', 'What you will enter', 'Try the tool', 'Limitations', 'How it works', 'Privacy', 'Related reading']);
    for (const [slug, p] of Object.entries(landing.pages)) {
      expect(Object.keys(p.sections), `${slug}: eight sections in order`).toEqual(landing.section_keys);
      expect(p.sections.try).toBe(`[lld_tool slug="${slug}"]`);
    }
  });

  test('TC-FR1-04-AS-05', () => {
    for (const [slug, p] of Object.entries(landing.pages)) {
      expect(p.sections.how, `${slug}: engine named`).toMatch({ diabetes: /QDiabetes-2018 \(Model A\)/, protein: /Daily Protein Target engine/, 'healthy-weight': /NICE guideline NG246/ }[slug]);
      expect(p.sections.how, `${slug}: deployed version shortcode`).toContain(`[lld_tool_version slug="${slug}"]`);
    }
  });

  test('NEG-01', () => {
    // sandbox: one more active card than the data holds → one more active card rendered (AS-01's exact count fails)
    const sandbox = mkdtempSync(join(tmpdir(), 'lld-plugin-'));
    try {
      cpSync(PLUGIN, join(sandbox, 'lld-tools'), { recursive: true });
      const data = JSON.parse(readFileSync(join(sandbox, 'lld-tools', 'data', 'tool-cards.json'), 'utf8'));
      data.cards.push({ ...data.cards[0], tool_slug: 'weight', name: 'Healthy Weight Check', landing_url: '/health-tools/weight/' });
      writeFileSync(join(sandbox, 'lld-tools', 'data', 'tool-cards.json'), JSON.stringify(data));
      const html = renderHub(join(sandbox, 'lld-tools'), { ...status, tools: { ...status.tools, weight: { status: 'active' } } });
      expect((html.match(/data-status="active"/g) || []).length).toBe(data.cards.length);
    } finally { rmSync(sandbox, { recursive: true, force: true }); }
  });
});
