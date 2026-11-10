// The six Stage 3 calculator pages on the Health Tools hub (Owner_Questions 107, approved 4 Nov 2026): the lld-members
// plugin adds their cards through lld_tools_hub_more_cards, on the hub only and only while the pages exist for the
// visitor. The Stage 1 cards, the homepage row and status.json are unchanged.
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { expect, test } from 'vitest';
import { plugin, root } from './tools/calc-page-php.mjs';

const toPhp = (p) => JSON.stringify(p.split('\\').join('/'));
const TOOLS = resolve(root, 'site/public_html/wp-content/mu-plugins/lld-tools/lld-tools.php');
const STAGE3 = resolve(plugin, 'includes/stage3');
const SLUGS = ['heart-risk-check', 'blood-pressure-check', 'metabolic-health-check', 'fitness-age', 'heart-rate-zones', 'sleep-and-stress-check'];

/** Render the hub (or the homepage row) with lld-tools and, when withPlugin, the calculator pages hooked in. */
function render({ withPlugin = true, enabled = true, compact = false, closed = false } = {}) {
  const tmp = mkdtempSync(join(tmpdir(), 'lld-hub3-'));
  try {
    const status = join(tmp, 'status.json');
    writeFileSync(status, JSON.stringify({ tools: { protein: { status: 'active' }, diabetes: { status: 'active' }, 'healthy-weight': { status: 'active' } }, labels: [] }));
    const hooked = withPlugin ? `
class LLD_Members_Features { static function state($f) { return 'on'; } static function enabled($f, $m = 0) { return ${enabled ? 'true' : 'false'}; } }
$GLOBALS['lld_filters'] = [];
function add_action() {}
function current_user_can($c) { return false; }
function add_filter($h, $cb) { $GLOBALS['lld_filters'][$h][] = $cb; }
function apply_filters($h, $v) { foreach ($GLOBALS['lld_filters'][$h] ?? [] as $cb) $v = call_user_func($cb, $v); return $v; }
require_once ${toPhp(resolve(STAGE3, 'class-safety-stage3.php'))};
foreach (['class-heart-risk.php', 'class-bp-check.php', 'class-metabolic.php', 'class-fitness-age.php', 'class-hr-zones.php', 'class-sleep-stress.php'] as $f) require_once ${toPhp(resolve(STAGE3, 'calculators'))} . '/' . $f;
require_once ${toPhp(resolve(STAGE3, 'screens/class-calculator-pages.php'))};
LLD_Members_Calculator_Pages::boot();` : '';
    const code = `<?php
define('ABSPATH', '/');
function home_url($p = '') { return 'https://lld.test' . $p; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function get_option($k, $d = false) { if ($k === 'lld_members_closed') return ${closed ? 'true' : 'false'}; return $k === 'lld_tools_settings' ? ['status_path' => ${toPhp(status)}] : $d; }
function shortcode_atts($d, $a) { return array_merge($d, (array)$a); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return (string)$s; }
function get_post($id) { return null; }
function add_shortcode() {}
function register_activation_hook() {}
${hooked}
require ${toPhp(TOOLS)};
echo lld_tools_render_hub(['compact' => '${compact ? '1' : '0'}']);`;
    const r = spawnSync('php', ['-d', 'display_errors=stderr'], { input: code, encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr || r.stdout);
    return new JSDOM(r.stdout).window.document;
  } finally { rmSync(tmp, { recursive: true, force: true }); }
}
const cardsOf = (doc) => [...doc.querySelectorAll('[data-tool-card]')].map((c) => `${c.getAttribute('data-tool')}:${c.getAttribute('data-status')}`);

test('TC-FR3-49-AS-01 (hub): the six calculator pages are on the Health Tools hub after the Stage 1 cards', () => {
  const doc = render();
  expect(cardsOf(doc)).toEqual(['protein:active', 'diabetes:active', 'healthy-weight:active', ...SLUGS.map((s) => `${s}:active`)]);
  for (const slug of SLUGS) {
    const card = doc.querySelector(`[data-tool-card][data-tool="${slug}"]`);
    expect(card.querySelector('[data-test=cta]').getAttribute('href')).toBe(`/health-tools/${slug}/`);
    expect(card.querySelector('h2[data-test=card-name]').textContent.trim().length).toBeGreaterThan(3);
    expect(card.querySelector('[data-test=card-purpose]').textContent.trim().length).toBeGreaterThan(20);
    expect(card.querySelectorAll('[data-test=card-inputs] li').length).toBeGreaterThan(0);
    expect(card.querySelector('[data-test=card-eligibility]').textContent).toMatch(/Adults/);
    expect(card.querySelector('[data-test=status-label]').textContent.trim()).toBe(doc.querySelector('[data-tool="protein"] [data-test=status-label]').textContent.trim());
  }
  // Name and purpose are the pages' approved title and introduction.
  const heart = doc.querySelector('[data-tool="heart-risk-check"]');
  expect(heart.querySelector('[data-test=card-name]').textContent).toBe('Heart attack and stroke risk check');
  expect(heart.querySelector('[data-test=card-purpose]').textContent).toBe('Estimates your chance of a heart attack or stroke in the next 10 years, and which change would lower it most.');
});

test('TC-FR3-49-AS-01 (hub, unchanged): switched off, without the plugin, on the homepage row, or with the site closed, the Stage 1 cards only', () => {
  const stage1 = ['protein:active', 'diabetes:active', 'healthy-weight:active'];
  expect(cardsOf(render({ enabled: false }))).toEqual(stage1);
  expect(cardsOf(render({ withPlugin: false }))).toEqual(stage1);
  expect(cardsOf(render({ compact: true }))).toEqual(stage1);
  expect(cardsOf(render({ closed: true })), 'site closed: the pages do not render').toEqual(stage1);
  // The Stage 1 card data is untouched (TC-FR1-04, TC-FR2-39-AS-16).
  const data = JSON.parse(readFileSync(resolve(root, 'site/public_html/wp-content/mu-plugins/lld-tools/data/tool-cards.json'), 'utf8'));
  expect(data.cards.map((c) => c.tool_slug)).toEqual(['protein', 'diabetes', 'healthy-weight']);
});
