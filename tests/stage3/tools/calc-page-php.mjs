// Runs a calculator page class (includes/stage3/screens/calculators/class-calc-page-*.php) in PHP CLI without
// WordPress: the few WordPress functions the pages use are stood in for, the engines and the safety layer are the real
// ones. Used by tests/stage3/calc-page-*.test.mjs.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const root = resolve(here, '../../..');
export const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = resolve(plugin, 'includes/stage3');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const STUBS = `
define('ABSPATH', '/');
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function home_url($p = '') { return 'https://example.test' . $p; }
function wp_nonce_field() { return '<input type="hidden" name="lld_calc3_nonce" value="n">'; }
function plugins_url($p = '') { return '/' . $p; }
function apply_filters($h, $v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
`;

/**
 * Run PHP with the stubs, every engine, the safety layer, the page shell and every page class loaded.
 *
 * @param {string} code PHP that echoes JSON.
 */
export function php(code) {
  const files = [
    resolve(stage3, 'class-safety-stage3.php'),
    ...['class-heart-risk.php', 'class-bp-check.php', 'class-metabolic.php', 'class-fitness-age.php', 'class-hr-zones.php', 'class-sleep-stress.php']
      .map((f) => resolve(stage3, 'calculators', f)),
    resolve(stage3, 'screens', 'class-calculator-pages.php'),
  ];
  const requires = files.map((f) => `require_once ${toPhpPath(f)};`).join(' ');
  return execFileSync('php', ['-d', 'display_errors=stderr', '-d', 'memory_limit=512M', '-r', `${STUBS} ${requires} ${code}`],
    { encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
}

/** JSON-encode a JS value as a PHP array literal source. */
export const arr = (v) => `json_decode(${JSON.stringify(JSON.stringify(v))}, true)`;

/**
 * A page's form for answers, its engine inputs, and the result HTML for those answers (engine run directly, as a
 * visitor: no profile).
 *
 * @param {string} cls    Page class, e.g. 'LLD_Members_Calc_Page_Heart_Risk'.
 * @param {string} engine Engine class.
 * @param {object} values Answers as the form posts them.
 * @param {object} ctx    Context override (member, facts, health, imperial, premium).
 */
export function run(cls, engine, values, ctx = {}) {
  const context = { member: 0, facts: null, health: false, imperial: false, premium: false, ...ctx };
  return JSON.parse(php(`
    $ctx = ${arr(context)};
    $values = ${arr(values)};
    $in = ${cls}::inputs($values, $ctx);
    $r = ${engine}::compute($in);
    $html = '';
    if (!isset($r['error'])) { $r['wording'] = ${engine}::wording($r['messages'] ?? [], $r); $html = ${cls}::result($r, $ctx); }
    echo json_encode(['inputs' => $in, 'result' => $r, 'html' => $html, 'form' => ${cls}::form($values, $ctx),
      'saved' => isset($r['error']) ? null : ${cls}::saved($r, $in), 'error' => isset($r['error']) ? ${cls}::error_text($r['error']) : null]);
  `));
}

/** Visible text of an HTML fragment (tags dropped, entities decoded, spaces collapsed). */
export const textOf = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\s+/g, ' ').trim();
