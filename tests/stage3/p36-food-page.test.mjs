// P3-36 B1: the Food page (/members/food/). Source checks for the states the page renders on the server and the
// rules its script must keep: health consent first, the safety matrix decides (never the page), no clinical
// sentence of the page's own, Premium through the interface's own upgrade answer, and no unescaped text in markup.
// The live behaviour is checked in a browser by tests/stage3/e2e/screens/p36-food.spec.mjs.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const php = readFileSync(resolve(plugin, 'includes/stage3/screens/class-food-screen.php'), 'utf8');
const js = readFileSync(resolve(plugin, 'assets/stage3-logging.js'), 'utf8');
const css = readFileSync(resolve(plugin, 'assets/stage3.css'), 'utf8');
const safety = readFileSync(resolve(plugin, 'includes/stage3/class-safety-stage3.php'), 'utf8');

const render = php.slice(php.indexOf('public static function render()'), php.indexOf('private static function upgrade_box'));
const build = php.slice(php.indexOf('public static function build_html'), php.indexOf('private static function render_summary_inner'));

test('TC-FR3-59-AS-01 without health consent the Food page shows the consent sentence and reads no diary', () => {
  const gate = render.indexOf('has_health_consent( $member_id )');
  expect(gate).toBeGreaterThan(-1);
  expect(gate).toBeLessThan(render.indexOf('self::build_html('));
  const refused = render.slice(gate, render.indexOf('self::build_html('));
  expect(refused).toContain("LLD_Members_Stage3_Consent::MESSAGES['needed']");
  expect(refused).toMatch(/self::output\([\s\S]*false\s*\);\s*exit;/); // no script, and the request ends there
  // Sign-in and the feature switch come before it; the page is never cached.
  expect(render.indexOf('/members/sign-in/')).toBeLessThan(gate);
  expect(render.indexOf("LLD_Members_Features::enabled( 'food_tracker', $member_id )")).toBeLessThan(gate);
  expect(render).toContain('nocache_headers()');
});

test('TC-FR3-69-AS-01 eating-disorder flag: the state comes from the safety matrix, and every number is left out of the page', () => {
  expect(build).toContain("LLD_Members_Stage3_Safety::applies( $member_id, 'food_tracker' )");
  expect(build).toContain("apply_filters( 'lld_members_stage3_api_rows', $day, 'food-day', $member_id )"); // the day goes through the matrix
  expect(build).toContain("LLD_Members_Stage3_Safety::MESSAGES['ed.food-tracker.diary-heading']");
  expect(build).toContain("LLD_Members_Stage3_Safety::MESSAGES['ed.food-tracker.calorie-total']");
  // Each part that prints or asks a number sits inside a "not eating-disorder" branch (or the targets branch, which
  // the matrix hides for the flag): the summary, meal subtotals, the weekly summary, suggestions, and every gram or
  // calorie field of the dialog and the recipe form.
  const guarded = (needle) => {
    const at = build.indexOf(needle);
    expect(at, needle).toBeGreaterThan(-1);
    const before = build.slice(0, at);
    const open = Math.max(before.lastIndexOf('if ( ! $ed_mode )'), before.lastIndexOf('if ( ! $hide_targets )'));
    expect(open, needle).toBeGreaterThan(-1);
    // no endif closes that branch between it and the needle (template ifs and loops opened against those closed)
    const between = before.slice(before.lastIndexOf('<?php', open));
    const opens = (between.match(/(?:if|foreach) \([^\n]*\) : \?>/g) || []).length;
    const closes = (between.match(/endif;|endforeach;/g) || []).length;
    expect(opens, needle).toBeGreaterThan(closes);
  };
  ['data-lld-food-summary-body', 'data-lld-meal-kcal', 'data-lld-food-week-body', 'data-lld-suggestions-list', 'data-lld-portion-grams', 'name="portion_g"', 'name="kcal" min="0"', 'data-lld-recipe-ing-g', 'data-lld-target-form'].forEach(guarded);
  expect(build).toMatch(/\$hide_targets\s*=\s*\$ed_mode \|\|/);
  // A logged item prints its amount and numbers only outside the flag, and Edit (which asks for grams) too.
  const item = php.slice(php.indexOf('private static function render_logged_item'), php.indexOf('private static function render_earlier_item'));
  expect(item.indexOf('if ( ! $ed_mode )')).toBeLessThan(item.indexOf("' kcal'"));
  expect(item).toContain('if ( ! $ed_mode && $id > 0 )');
  // The script takes the flag from the page's settings and never decides it from the data.
  expect(js).toContain('edMode: !!cfg.edMode');
  expect(js).not.toMatch(/state\.edMode\s*=/);
  // If the state changes while the page is open, the page is drawn again by the server: nothing is kept from before.
  expect(js).toMatch(/!!res\.data\.note !== state\.edMode\) \{\s*window\.location\.assign\(/);
  expect(js).not.toMatch(/localStorage|sessionStorage|indexedDB/);
});

test('TC-FR3-69-AS-03 kidney disease: no protein target field, and no kidney sentence written by the page', () => {
  expect(build).toMatch(/if \( ! \$kidney_mode \) : \?>\s*<label class="lld-food-field">\s*<span>Protein \(g\)<\/span>/);
  expect(build).toContain("LLD_Members_Calorie_Target::QUOTED['kidney']"); // the signed sentence, or nothing
  for (const source of [php, js]) expect(source).not.toMatch(/kidney team or dietitian/i);
  // Totals and targets are printed only when the interface returned them: no zero stands in for a hidden value.
  expect(php).toContain("if ( ! isset( $totals[ $key ] ) || ! is_numeric( $totals[ $key ] ) ) {");
  expect(js).toContain("if (!has(totals, m.key)) {");
});

test('TC-FR3-69-AS-01 the page writes no clinical or judgement wording of its own', () => {
  const words = /\b(balanced|healthy|on track|well done|great job|too much|too many|unhealthy|bad food|cheat|guilt|calmly|verified|recommended)\b/i;
  const visible = (source) => source.replace(/\/\*[\s\S]*?\*\/|^\s*(?:\/\/|\*).*$/gm, '');
  expect(visible(php)).not.toMatch(words);
  expect(visible(js)).not.toMatch(words);
  // The pregnancy notice is the signed string, read from the matrix.
  expect(build).toContain("LLD_Members_Stage3_Safety::MESSAGES['pregnancy.calorie-target']");
  expect(safety).toContain("'pregnancy.calorie-target'");
  // No calorie floor or other threshold in the page: the interface refuses with its own message.
  expect(build).toContain('name="kcal" min="1" max="10000"');
});

test('TC-FR3-63-AS-04 Free member: the short explanation and the upgrade button come from the access rules, and lead to /pricing/', () => {
  expect(php).toContain('LLD_Members_Access_Tiers::upgrade_prompt( $feature )');
  for (const feature of ['barcode', 'copy_meals', 'own_targets', 'custom_foods', 'recipes', 'weekly_summary']) {
    expect(build).toContain(`LLD_Members_Access_Tiers::allows( $member_id, '${feature}' )`);
    expect(build).toContain(`self::upgrade_box( '${feature}'`);
  }
  expect(php).not.toContain('/members/upgrade/');
  expect(js).not.toContain('/members/upgrade/');
  expect(js).toContain('res.data.upgrade_url) || cfg.upgradeUrl');
  // Nothing is unlocked in the browser: the script only shows or hides, and every write is a call to the interface.
  expect(js).not.toMatch(/features\.[a-z_]+\s*=[^=]/);
  expect(js).not.toMatch(/cfg\.premium\s*=[^=]/);
});

test('NFR3-09 Food page script: every call carries the REST nonce, and text from the food database or a member is escaped', () => {
  expect(build).toContain("wp_create_nonce( 'wp_rest' )");
  expect(js).toContain("'X-WP-Nonce': cfg.nonce || ''");
  expect((js.match(/fetch\(/g) || []).length).toBe(1); // one way out, through apiRequest
  expect(js).not.toMatch(/insertAdjacentHTML|outerHTML|document\.write|eval\(/);
  // Names, brands, messages and labels that arrive from the server are never put into markup without esc().
  const risky = /\+\s*(?:[a-z]+\.)?(?:name|brand|source_name|attribution|message|description|portion|portion_label|tip|title|over_note|trimmed|desc|baseName)\s*(?:\+|;)/g;
  const hits = js.match(risky) || [];
  expect(hits).toEqual([]);
  expect(js).toMatch(/s\.indexOf\('\/\/'\) !== 0/); // no protocol-relative link from a reply
});

test('NFR3-12 Food page styles are scoped to the page, with the member area focus outline', () => {
  const block = css.slice(css.indexOf('/* ================= FOOD TRACKER SCREEN'));
  const selectors = block.replace(/\/\*[\s\S]*?\*\//g, '').split('\n').map((l) => l.trim()).filter((l) => /[{,]$/.test(l) && !l.startsWith('@'));
  expect(selectors.length).toBeGreaterThan(100);
  for (const selector of selectors) expect(selector, selector).toMatch(/^(?:body\.mode-night )?\.lld-stage3-food\b/);
  expect(block).toMatch(/\.lld-stage3-food button:focus-visible[\s\S]*?outline: 3px solid/);
  expect(block).toContain('prefers-reduced-motion: reduce');
  expect(block).not.toContain('!important;\n}\n\nbody'); // nothing reaches outside the page
});
