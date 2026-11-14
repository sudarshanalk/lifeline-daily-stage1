// P3-36 B2: the Log a reading page (/members/readings/). The page is rendered by PHP here, with the real safety
// class, for the states it prints on the server (Conditions answer missing, "Prefer not to answer", no health
// consent, Free), and its script is checked for the rules it must keep: it prints the warning the interface returns
// and holds no safety text, level or link of its own. The live behaviour is in e2e/screens/p36-readings.spec.mjs.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const p = (x) => JSON.stringify(resolve(plugin, x).split('\\').join('/'));
const source = readFileSync(resolve(plugin, 'includes/stage3/screens/class-readings-screen.php'), 'utf8');
const logging = readFileSync(resolve(plugin, 'assets/stage3-logging.js'), 'utf8');
const js = logging.slice(logging.indexOf('function rdEsc('), logging.indexOf('function boot()'));
const answered = { conditions_answered: 'none', conditions: '[]', height_cm: 175, weight_kg: 90, age: 40 };

/** The page as PHP prints it, for one member state. */
function page(state = {}) {
  const s = Buffer.from(JSON.stringify({ consent: true, row: answered, premium: true, insights: [], ...state })).toString('base64');
  const code = `define('ABSPATH', '/'); define('LLD_MEMBERS_FILE', __FILE__); define('LLD_MEMBERS_VERSION', 't');
    $GLOBALS['S'] = json_decode(base64_decode('${s}'), true);
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } function esc_attr($t) { return esc_html($t); }
    function esc_url($u) { return (string) $u; } function esc_url_raw($u) { return (string) $u; }
    function home_url($x = '') { return 'https://example.test' . $x; } function rest_url($x = '') { return 'https://example.test/wp-json/' . $x; }
    function wp_create_nonce($a) { return 'nonce'; } function wp_json_encode($v) { return json_encode($v); }
    function add_query_arg($k, $v, $u) { return $u . '?' . $k . '=' . $v; } function sanitize_key($k) { return (string) $k; } function wp_unslash($v) { return $v; }
    function apply_filters($n, $v) { return $v; } function plugins_url($x) { return '/' . $x; }
    function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_print_styles() {} function wp_print_scripts() {}
    function lld_members_now_mysql($id = 0) { return '2026-11-07 12:00:00'; }
    function lld_health_consent_notice() { return '<article class="lld-consent-note">CONSENT-NOTICE</article>'; }
    function lld_members_layout($a) { echo $a['main']; }
    class W { function prepare($q) { return $q; } function get_row($q) { return $GLOBALS['S']['row'] ? (object) $GLOBALS['S']['row'] : null; } }
    $GLOBALS['wpdb'] = new W();
    class LLD_Members_Schema { static function table($t) { return $t; } }
    class LLD_Members_Account { static function has_consent($id, $k) { return (bool) $GLOBALS['S']['consent']; } }
    class LLD_Members_Access { static function has_health_consent($id) { return (bool) $GLOBALS['S']['consent']; } }
    class LLD_Members_Features { static function enabled($f, $id = 0) { return true; } static function state($f) { return 'on'; } }
    class LLD_Members_Food_Log { static function premium($id) { return (bool) $GLOBALS['S']['premium']; } }
    class LLD_Members_Billing { static function has_premium($id) { return (bool) $GLOBALS['S']['premium']; } }
    class LLD_Members_Stage3_Data { static function rows($t) { return 'insights' === $t ? $GLOBALS['S']['insights'] : array(); } }
    class LLD_Members_Goal_Journey { static function facts($id) { return array('today' => '2026-11-07', 'imperial' => false); } }
    class LLD_Members_Goal_Rules { static function days_between($a, $b) { return (int) round((strtotime($b) - strtotime($a)) / 86400); } }
    require ${p('includes/stage3/class-safety-stage3.php')}; require ${p('includes/stage3/class-access-tiers.php')};
    require ${p('includes/stage3/engine/class-rule-catalogue.php')}; require ${p('includes/stage3/screens/class-readings-screen.php')};
    LLD_Members_Readings_Screen::render(7);`;
  return execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 });
}
const constant = (name) => JSON.parse(execFileSync('php', ['-r', `define('ABSPATH', '/'); require ${p('includes/stage3/class-safety-stage3.php')}; require ${p('includes/stage3/class-access-tiers.php')}; require ${p('includes/stage3/engine/class-rule-catalogue.php')}; echo json_encode(${name});`], { encoding: 'utf8', timeout: 60000 }));
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&').replace(/\s+/g, ' ');
const config = (html) => JSON.parse(html.match(/data-lld-readings-config="([^"]*)"/)[1].replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&'));
const notice = (html) => html.match(/<div class="lld-caution lld-readings-conditions-notice"[^>]*>([\s\S]*?)<\/div>/)[1];

test('TC-FR3-69-AS-05 Conditions answer missing: the page shows the sentence and link the interface returns, and offers no store', () => {
  const wording = constant('LLD_Members_Stage3_Safety::CONDITIONS_WORDING');
  const html = page({ row: null });
  expect(text(notice(html)).replace(/&quot;/g, '"').trim()).toBe(`${wording['conditions.missing']} Health profile`);
  expect(notice(html)).toContain('href="https://example.test/members/health/#lld-conditions"');
  expect(config(html).canStore).toBe(false);
  // "Prefer not to answer" is still unknown to the matrix: the interface's own refusal, not a sentence telling the
  // member that their answer counts.
  const prefer = page({ row: { ...answered, conditions_answered: 'not_answered', conditions: '["prefer-not"]' } });
  expect(text(notice(prefer)).trim()).toBe('Not available. Health profile');
  expect(text(prefer)).not.toContain(wording['conditions.required']);
  // An answered member sees neither.
  expect(page()).not.toContain('lld-readings-conditions-notice');
  expect(config(page()).canStore).toBe(true);
});

test('TC-FR3-59-AS-01 without health consent the page shows the consent notice and its script reads and stores nothing', () => {
  const html = page({ consent: false });
  expect(html).toContain('CONSENT-NOTICE');
  expect(html).not.toContain('lld-readings-conditions-notice');
  expect(config(html)).toMatchObject({ hasConsent: false, canStore: false });
  const load = js.slice(js.indexOf('async function loadHistory(type)'), js.indexOf('function switchType'));
  expect(load.indexOf('if (!cfg.canStore || !cfg.hasConsent) {')).toBeGreaterThan(-1);
  expect(load.indexOf('if (!cfg.canStore || !cfg.hasConsent) {')).toBeLessThan(load.indexOf('rdRequest('));
  // Sign-in, the switch and the agreements come before the page; it is never cached (lld_members_layout sends no-store).
  const gate = source.slice(source.indexOf('public static function maybe_render'), source.indexOf('private static function not_found'));
  expect(gate.indexOf('/members/sign-in/')).toBeGreaterThan(-1);
  expect(gate.indexOf('/members/sign-in/')).toBeLessThan(gate.indexOf('self::render('));
  expect(gate.indexOf("LLD_Members_Features::enabled( 'health_logging', $member_id )")).toBeLessThan(gate.indexOf('self::render('));
  expect(source).toContain('lld_members_layout(');
});

test('TC-FR3-46-AS-03 the page and its script hold no safety wording: the banner is the message the interface returns', () => {
  const signed = { ...constant('LLD_Members_Stage3_Safety::MESSAGES'), ...constant('LLD_Members_Stage3_Safety::BP_MESSAGES') };
  const html = text(page());
  for (const [key, message] of Object.entries(signed)) {
    if (/^(reminder\.|ed\.food-tracker\.diary-heading|food-tracker\.)/.test(key)) continue; // short labels of other screens
    const first = message.split(/\n|(?<=[.?!]) /)[0];
    expect(js, key).not.toContain(first);
    expect(source, key).not.toContain(first);
    expect(html, key).not.toContain(first);
  }
  // The urgent banner: each line of warning.message, escaped, with only the signed link token made a link.
  const urgent = js.slice(js.indexOf('function renderUrgentWarning'), js.indexOf('function renderServerError'));
  expect(urgent).toContain("String(warning.message || '')");
  expect(urgent).toContain("'<p>' + formatSafetyText(line) + '</p>'");
  expect(urgent).toContain("classList.add('lld-safety')");
  const format = js.slice(js.indexOf('function formatSafetyText'), js.indexOf('function formatInsightText'));
  expect(format).toContain('rdEsc(rawText)');
  expect(format.match(/\.replace\(/g)).toHaveLength(1);
  expect(format).toContain('When to seek urgent care');
  expect(js).not.toMatch(/findahelpline/); // no help-line link of the page's own
  // The banner places are empty until a save answers, and carry no heading.
  expect(page()).toMatch(/<div id="lld-readings-urgent"[^>]*role="alert"[^>]*hidden><\/div>/);
  expect(page()).toMatch(/<div id="lld-readings-caution"[^>]*hidden><\/div>/);
});

test('TC-FR3-46-AS-01 nothing clinical is decided in the browser: no level compared, no band word, no plan check before the banner', () => {
  const levels = constant('LLD_Members_Stage3_Safety::LEVELS');
  // No warning level of the matrix is compared in the script (the unit factors and the glass size are display only).
  const numbers = [levels.blood_pressure.urgent.sys, levels.blood_pressure.urgent.dia, levels.blood_pressure.urgent_pregnancy.sys,
    levels.blood_pressure.same_day_pregnancy.sys, levels.glucose.urgent_high, levels.glucose.urgent_high_symptoms,
    levels.glucose.caution_high[0], levels.water.day_caution_ml];
  for (const n of numbers) expect(js, String(n)).not.toMatch(new RegExp(`[<>]=?\\s*${String(n).replace('.', '\\.')}\\b`));
  expect(js).not.toMatch(/\b(?:normal|elevated|hypertension|healthy range|too high|too low|in range)\b/i);
  // The banner branch reads only the reply's level and message, never the member's plan.
  const handle = js.slice(js.indexOf('function handleSaveWarning'), js.indexOf('function activeUnitFor'));
  expect(handle).toContain("warning.level === 'urgent'");
  expect(handle).not.toMatch(/hasTrends|hasFullHistory|premium/i);
  // No input carries a limit of its own: the interface refuses impossible numbers with its own sentence.
  expect(page()).not.toMatch(/<input[^>]*\s(?:min|max)=/);
});

test('TC-FR3-45-AS-03 glucose symptoms and effort use the owner-approved labels, and the symptom keys are the matrix\'s own', () => {
  const html = page();
  expect(html).toContain('<legend>Do you have any of these right now?</legend>');
  const keys = constant('LLD_Members_Stage3_Safety::GLUCOSE_SYMPTOMS');
  expect([...html.matchAll(/value="([a-z_]+)" data-lld-glu-symptom/g)].map((m) => m[1])).toEqual(keys);
  for (const label of ['Vomiting', 'Drowsiness', 'Deep breathing', 'Stomach pain', 'I feel dizzy or faint']) expect(html).toContain(`<span>${label}</span>`);
  for (const option of ['<option value="1">Easy: you can sing</option>', '<option value="2">Moderate: you can talk but not sing</option>', '<option value="3">Hard: only a few words at a time</option>']) expect(html).toContain(option);
  expect(html).toContain('Top number (systolic, mmHg)');
  expect(html).toContain('Bottom number (diastolic, mmHg)');
});

test('TC-FR3-63-AS-02 Free: the 14-day line is the access rule\'s own sentence, with the link to the plans', () => {
  const premium = constant('LLD_Members_Access_Tiers::PREMIUM');
  const free = page({ premium: false });
  expect(text(free)).toContain(`${premium.full_history} See Premium`);
  expect(free).toContain('href="https://example.test/pricing/"');
  expect(config(free)).toMatchObject({ hasFullHistory: false, hasTrends: false });
  expect(source).toContain('LLD_Members_Food_Log::premium( $member_id )'); // the rule the interface limits the lists by
  expect(config(free).messages.trendsUpgrade).toBe(premium.trends);
  expect(text(page())).not.toContain(premium.full_history);
});

test('TC-FR3-44-AS-03 low mood: no banner of the page\'s own; the engine\'s insight is printed with the catalogue text unchanged', () => {
  const template = constant('LLD_Members_Rule_Catalogue::TEMPLATES')['rule.R-SAFE-04'];
  const rule = constant("LLD_Members_Rule_Catalogue::rule('R-SAFE-04')");
  const shown = (fields) => page({ insights: [{ rule_id: rule.id, dismissed: 0, shown_at: '2026-11-06 10:00:00', values: '{}', ...fields }] });
  const html = shown({});
  const card = html.match(/<p class="lld-insight" data-lld-rule="R-SAFE-04">([\s\S]*?)<\/p>/)[1];
  expect(card).not.toContain('<a ');
  expect(text(card).trim()).toBe(template.replace(/\*\*/g, ''));
  expect(config(html).lowMoodRuleId).toBe(rule.id);
  // Not shown when dismissed, older than 7 days, or absent; and no urgent box is printed for mood.
  expect(shown({ dismissed: 1 })).not.toContain('data-lld-rule="R-SAFE-04"');
  expect(shown({ shown_at: '2026-10-20 10:00:00' })).not.toContain('data-lld-rule="R-SAFE-04"');
  expect(page()).not.toContain('data-lld-rule="R-SAFE-04"');
  expect(html).not.toContain('class="lld-safety');
  expect(js).toContain('Number(cfg.lowMoodRuleId || 0)');
});

test('TC-FR3-08-AS-01 the empty state has a picture, the approved line and one action button; every reading type has a panel', () => {
  const html = page();
  const empty = html.match(/<section class="lld-card lld-readings-empty"[\s\S]*?<\/section>/)[0];
  expect(empty).toContain('<svg');
  expect(text(empty)).toContain('No readings yet. Choose a type above to add your first.');
  expect(empty.match(/<button /g)).toHaveLength(1);
  expect([...html.matchAll(/data-lld-type-panel="([a-z_]+)"/g)].map((m) => m[1])).toEqual(['blood_pressure', 'weight', 'waist', 'activity', 'sleep', 'water', 'mood', 'glucose']);
});

test('TC-FR3-38-AS-03 the history and trend lists ask for one reading type and a date range, and the interface reads its own route from the address', () => {
  const load = js.slice(js.indexOf('async function loadHistory(type)'), js.indexOf('function switchType'));
  expect(load).toContain("'type=' + encodeURIComponent(type)");
  expect(load).toContain("'&from=' + encodeURIComponent(state.from)");
  expect(load).toContain("'&to=' + encodeURIComponent(state.to)");
  expect(load).toContain('resTrend.data.points.slice().sort('); // the chart runs oldest to newest, however the rows are listed
  // A reply for a type or range the member has left is dropped before it reaches the list, the chart or Edit.
  expect(load.match(/if \(stale\(\)\) return;/g)).toHaveLength(2);
  // ?type=weight is a query value: WordPress hands it back for $request['type'] before the route's own {type}, and
  // every list answered "Not available." gate() moves it to its own name first, and query_type() reads it there.
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  const gate = api.slice(api.indexOf('private static function gate('), api.indexOf('self::$request = $request;'));
  expect(gate.indexOf('self::keep_query_type( $request );')).toBeGreaterThan(-1);
  expect(gate.indexOf('self::keep_query_type( $request );')).toBeLessThan(gate.indexOf("$request['type']"));
  const out = execFileSync('php', ['-r', `define('ABSPATH', '/');
    class WP_REST_Request implements ArrayAccess { public $url = array(); public $query = array();
      function get_query_params() { return $this->query; } function set_query_params($q) { $this->query = $q; }
      function offsetGet($k): mixed { return $this->query[$k] ?? $this->url[$k] ?? null; } function offsetExists($k): bool { return true; } function offsetSet($k, $v): void {} function offsetUnset($k): void {} }
    require ${p('includes/stage3/class-api.php')};
    $r = new WP_REST_Request(); $r->url = array('type' => 'readings'); $r->query = array('type' => 'weight', 'from' => '2026-11-06');
    $keep = new ReflectionMethod('LLD_Members_Stage3_API', 'keep_query_type'); $keep->setAccessible(true);
    $query = new ReflectionMethod('LLD_Members_Stage3_API', 'query_type'); $query->setAccessible(true);
    $before = $r['type']; $keep->invoke(null, $r); $keep->invoke(null, $r);
    echo json_encode(array($before, $r['type'], $query->invoke(null, $r), $r->query['from']));`], { encoding: 'utf8', timeout: 60000 });
  expect(JSON.parse(out)).toEqual(['weight', 'readings', 'weight', '2026-11-06']);
});
