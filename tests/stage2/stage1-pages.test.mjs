// Stage 1 pages stay visible (FR2-23, FR2-16): the plugin's own landing and disclaimer screens are only the fallback
// for a site without the real WordPress pages, and its sitemap leaves /sitemap.xml to Yoast SEO when Yoast's
// sitemaps are on. PHP CLI with small WordPress stubs; no WordPress or database.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { JSDOM } from 'jsdom';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const includes = resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes');
const path = (name) => JSON.stringify(resolve(includes, name).split(String.fromCharCode(92)).join('/'));

// Runs PHP with the plugin and billing classes loaded. `before` runs first (for example a Yoast stub);
// `body` echoes JSON.
function php(before, body) {
  const code = [
    "define('ABSPATH', '/');",
    "define('LLD_MEMBERS_FILE', '/plugin/lld-members.php');",
    "define('LLD_MEMBERS_VERSION', '0.1.0'); define('OBJECT', 'OBJECT');",
    '$GLOBALS["rules"] = array(); $GLOBALS["filters"] = array(); $GLOBALS["options"] = array(); $GLOBALS["flushed"] = 0; $GLOBALS["pages"] = array();',
    'function add_rewrite_rule( $regex, $query, $after = "bottom" ) { $GLOBALS["rules"][ $regex ] = $query; }',
    'function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["filters"][] = $hook; }',
    'function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["filters"][] = $hook; }',
    'function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS["options"] ) ? $GLOBALS["options"][ $name ] : $default; }',
    'function update_option( $name, $value, $autoload = null ) { $GLOBALS["options"][ $name ] = $value; return true; }',
    'function delete_option( $name ) { unset( $GLOBALS["options"][ $name ] ); return true; }',
    'function flush_rewrite_rules( $hard = true ) { $GLOBALS["flushed"]++; }',
    'function home_url( $p = "" ) { return "https://lld.test" . $p; }',
    'function esc_url( $u ) { return $u; }',
    'class WP_Post { public $ID; public $post_status; public function __construct( $id, $status ) { $this->ID = $id; $this->post_status = $status; } }',
    'function get_page_by_path( $p, $output = "OBJECT", $type = "page" ) { return $GLOBALS["pages"][ $p ] ?? null; }',
    before,
    `require ${path('class-billing.php')};`,
    `require ${path('class-plugin.php')};`,
    body,
  ].join(' ');
  return JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8' }));
}

const YOAST = "define('WPSEO_VERSION', '26.0'); class WPSEO_Options { public static $on = true; public static function get( $key, $default = null ) { return 'enable_xml_sitemap' === $key ? self::$on : $default; } }";
const RULES = 'LLD_Members_Plugin::rewrites(); echo json_encode( array( "rules" => $GLOBALS["rules"], "flushed" => $GLOBALS["flushed"], "version" => get_option( "lld_members_rewrites" ), "index" => LLD_Members_Plugin::yoast_index_entry( "" ) ) );';

test('TC-FR2-16-AS-03 with Yoast sitemaps on, /sitemap.xml is left to Yoast and the plugin sitemap is listed in its index', () => {
  const on = php(YOAST, `$GLOBALS["options"]["lld_members_rewrites"] = "10"; ${RULES}`);
  expect(Object.keys(on.rules)).not.toContain('^sitemap\\.xml$');
  expect(on.rules['^sitemap-lld-members\\.xml$']).toBe('index.php?lld_screen=sitemap');
  // Yoast's own rule for its sitemaps must not match the plugin's address.
  expect(/([^/]+?)-sitemap([0-9]+)?\.xml$/.test('sitemap-lld-members.xml')).toBe(false);
  expect(on.index).toBe('<sitemap><loc>https://lld.test/sitemap-lld-members.xml</loc></sitemap>\n');
  // Rules from the earlier set (version 10 claimed /sitemap.xml) are flushed once, and not again.
  expect(on.flushed).toBe(1);
  const again = php(YOAST, `$GLOBALS["options"]["lld_members_rewrites"] = "${on.version}"; ${RULES}`);
  expect(again.flushed).toBe(0);

  // Yoast active with its sitemaps switched off, or no Yoast (the CI site): the plugin serves /sitemap.xml as before.
  const off = php(YOAST, `WPSEO_Options::$on = false; $GLOBALS["options"]["lld_members_rewrites"] = "${on.version}"; ${RULES}`);
  expect(off.rules['^sitemap\\.xml$']).toBe('index.php?lld_screen=sitemap');
  expect(off.index).toBe('');
  expect(off.flushed).toBe(1);
  const none = php('', RULES);
  expect(none.rules['^sitemap\\.xml$']).toBe('index.php?lld_screen=sitemap');
  expect(none.rules['^sitemap-lld-members\\.xml$']).toBe('index.php?lld_screen=sitemap');
  expect(none.index).toBe('');

  // The index filter is hooked, and activation clears the stored version so the next request flushes the rules.
  const source = readFileSync(resolve(includes, 'class-plugin.php'), 'utf8');
  expect(source).toMatch(/add_filter\(\s*'wpseo_sitemap_index',\s*array\(\s*__CLASS__,\s*'yoast_index_entry'\s*\)\s*\)/);
  const activate = source.slice(source.indexOf('public static function activate()'), source.indexOf('public static function rewrites()'));
  expect(activate).toContain("delete_option( 'lld_members_rewrites' )");
});

test('TC-FR2-23-AS-02 a published Stage 1 page at a calculator or disclaimer address is served instead of the plugin screen', () => {
  const route = (pages, vars) => php('', `${pages} echo json_encode( LLD_Members_Plugin::stage1_pages( json_decode( '${JSON.stringify(vars)}', true ) ) );`);
  const all = '$GLOBALS["pages"] = array( "health-tools/protein" => new WP_Post( 6102, "publish" ), "health-tools/diabetes-risk-check" => new WP_Post( 6066, "publish" ), "health-tools/healthy-weight-check" => new WP_Post( 6540, "publish" ), "medical-disclaimer" => new WP_Post( 6140, "publish" ) );';
  expect(route(all, { lld_screen: 'landing', lld_track: 'protein' })).toEqual({ pagename: 'health-tools/protein' });
  expect(route(all, { lld_screen: 'landing', lld_track: 'diabetes' })).toEqual({ pagename: 'health-tools/diabetes-risk-check' });
  // The Healthy Weight Check's Stage 1 page (FR2-39, 6540 on staging) is served the same way.
  expect(route(all, { lld_screen: 'landing', lld_track: 'healthy-weight' })).toEqual({ pagename: 'health-tools/healthy-weight-check' });
  expect(route(all, { lld_screen: 'disclaimer' })).toEqual({ pagename: 'medical-disclaimer' });
  // The safety page has no Stage 1 page; other screens are untouched.
  expect(route(all, { lld_screen: 'safety' })).toEqual({ lld_screen: 'safety' });
  expect(route(all, { lld_screen: 'pricing' })).toEqual({ lld_screen: 'pricing' });
  // No page, or a page that is not published: the plugin's fallback screen answers.
  expect(route('', { lld_screen: 'landing', lld_track: 'protein' })).toEqual({ lld_screen: 'landing', lld_track: 'protein' });
  const draft = '$GLOBALS["pages"] = array( "medical-disclaimer" => new WP_Post( 6140, "draft" ) );';
  expect(route(draft, { lld_screen: 'disclaimer' })).toEqual({ lld_screen: 'disclaimer' });
  // The extras are added through the page content, and the save is handled before the page renders.
  const billing = readFileSync(resolve(includes, 'class-billing.php'), 'utf8');
  expect(billing).toMatch(/add_filter\(\s*'the_content',\s*array\(\s*__CLASS__,\s*'stage1_landing_content'\s*\)/);
  expect(billing).toMatch(/add_action\(\s*'template_redirect',\s*array\(\s*__CLASS__,\s*'stage1_landing_save'\s*\)/);
});

// The save listener that landing_extras() renders below the calculator, on the Stage 1 page and the fallback screen alike.
// The Stage 1 page embeds the tool from lld_tools_settings.tools_origin, and the tool's link script posts to its canonical
// origin. Only the calculator frame on the page counts, from the address it was loaded from (or the page's own).
const RENDER_STUBS = [
  'function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }',
  'function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }',
  'function wp_json_encode( $v ) { return json_encode( $v ); }',
  "function wp_nonce_field( $a, $n, $r = true, $e = true ) { return '<input type=\"hidden\" name=\"' . $n . '\" value=\"nonce\">'; }",
  "function lld_button( $args ) { return '<button type=\"submit\">' . $args['label'] . '</button>'; }",
].join(' ');

function extras(which) {
  return php(RENDER_STUBS, `$m = new ReflectionMethod( 'LLD_Members_Billing', 'landing_extras' ); echo json_encode( $m->invoke( null, '${which}', 7, true, false ) );`);
}

function landing(src, settings, which = 'protein') {
  const html = extras(which);
  const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
  const dom = new JSDOM(`<!doctype html><html><body><main><p data-lld-stage1-marker>Stage 1 content</p>
    <iframe class="lld-tool-frame" src="${src}"></iframe><iframe id="other"></iframe>${html.replace(/<script>[\s\S]*?<\/script>/, '')}</main></body></html>`, { url: 'https://stg.lld.test/health-tools/protein/', runScripts: 'outside-only' });
  if (settings) dom.window.lld_tools_settings = settings;
  dom.window.eval(script);
  const doc = dom.window.document;
  const tool = doc.querySelector('iframe.lld-tool-frame').contentWindow;
  const other = doc.getElementById('other').contentWindow;
  const form = doc.getElementById('lld-save-result');
  const onward = doc.querySelector('[data-lld-onward]');
  const send = (source, origin, data) => dom.window.dispatchEvent(new dom.window.MessageEvent('message', { source, origin, data }));
  return { form, onward, tool, other, send, doc };
}

const RESULT = { type: 'lld-calculator-result', calculator: 'protein', headline: '120 g', unit: 'of protein per day', band: 'Target range 100–140 g per day', version: 'a'.repeat(64) };

test('TC-FR2-23-AS-02 the save form below a Stage 1 page takes the result only from its calculator frame', () => {
  const page = landing('https://stg.lld.test/tools/protein-requirement-tool.html', { iframe_origin: 'https://stg.lld.test' });
  expect(page.doc.querySelector('[data-lld-track-link]').textContent).toBe('Fitness and Strength');
  page.send(page.other, 'https://stg.lld.test', RESULT);
  page.send(page.tool, 'https://elsewhere.test', RESULT);
  page.send(null, 'https://stg.lld.test', RESULT);
  page.send(page.tool, 'https://stg.lld.test', { ...RESULT, calculator: 'diabetes' });
  expect(page.form.hidden).toBe(true);
  page.send(page.tool, 'https://stg.lld.test', RESULT);
  expect(page.form.hidden).toBe(false);
  expect(page.form.querySelector('[name=lld_headline]').value).toBe('120 g');
  expect(page.form.querySelector('[name=lld_band]').value).toBe('Target range 100–140 g per day');
  // Withdrawn by any other sender: kept. Withdrawn by the tool: emptied and hidden.
  page.send(page.other, 'https://stg.lld.test', { type: 'lld-calculator-cleared', calculator: 'protein' });
  expect(page.form.hidden).toBe(false);
  page.send(page.tool, 'https://stg.lld.test', { type: 'lld-calculator-cleared', calculator: 'protein' });
  expect(page.form.hidden).toBe(true);
  for (const name of ['lld_headline', 'lld_unit', 'lld_band', 'lld_version']) expect(page.form.querySelector(`[name=${name}]`).value, name).toBe('');

  // A tool served from another origin (lld_tools_settings.tools_origin) is accepted from that origin, never a third one.
  const apart = landing('https://tools.lld.test/tools/protein-requirement-tool.html', { iframe_origin: 'https://tools.lld.test' });
  apart.send(apart.tool, 'https://elsewhere.test', RESULT);
  expect(apart.form.hidden).toBe(true);
  apart.send(apart.tool, 'https://tools.lld.test', RESULT);
  expect(apart.form.hidden).toBe(false);

  // The Healthy Weight Check (FR2-39): same listener; a result that allows no onward offer hides the track link and guide.
  const hw = landing('https://stg.lld.test/tools/healthy-weight-tool.html', { iframe_origin: 'https://stg.lld.test' }, 'healthy-weight');
  expect(hw.doc.querySelector('[data-lld-track-link]').textContent).toBe('Healthy Weight');
  const bmi = { type: 'lld-calculator-result', calculator: 'healthy-weight', headline: '23.5', unit: 'kg/m²', band: 'Healthy weight', version: 'b'.repeat(64), onward: true };
  hw.send(hw.other, 'https://stg.lld.test', bmi);
  expect(hw.form.hidden).toBe(true);
  hw.send(hw.tool, 'https://stg.lld.test', bmi);
  expect([hw.form.hidden, hw.onward.hidden]).toEqual([false, false]);
  hw.send(hw.tool, 'https://stg.lld.test', { ...bmi, band: 'Underweight', onward: false });
  expect([hw.form.hidden, hw.onward.hidden]).toEqual([false, true]);
  hw.send(hw.tool, 'https://stg.lld.test', { type: 'lld-calculator-cleared', calculator: 'healthy-weight', onward: false });
  expect([hw.form.hidden, hw.onward.hidden]).toEqual([true, true]);
});
