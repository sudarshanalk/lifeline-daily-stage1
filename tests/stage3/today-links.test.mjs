// P3-36 B3: Today's links go to the new Food page and Log a reading page once those pages are built and on for the
// member, and keep their old addresses (the Stage 2 diary, the weekly check-in) until then, so this can merge before or
// after the two pages (#264, #267). More lists the two pages under the same conditions. Labels are unchanged: only the
// addresses move (Cursor's wording pull request changes labels on the same lines).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const inc = (name) => resolve(plugin, `includes/${name}`);
const src = (name) => readFileSync(inc(name), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

// $GLOBALS['on'] lists the switches on for member 5; $classes the page screens that are loaded.
const run = (classes, on) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `
  define('ABSPATH', '/');
  function home_url($p = '') { return 'https://stg.example.test' . $p; } function add_action() {} function add_filter() {}
  class LLD_Members_Features { static function enabled($f, $m = 0) { return 5 === (int) $m && in_array($f, ${JSON.stringify(on).replace(/"/g, "'").replace(/^\[/, '[').replace(/\]$/, ']')}, true); } }
  ${classes.map((c) => `class ${c} {}`).join(' ')}
  require ${toPhpPath(inc('stage3/screens/class-today-screen.php'))};
  $link = new ReflectionMethod('LLD_Members_Today_Screen', 'link_or'); $link->setAccessible(true);
  echo json_encode([
    'food' => $link->invoke(null, 'food', 5, '/members/diary/'),
    'readings' => $link->invoke(null, 'readings', 5, '/members/check-in/'),
    'new_food' => LLD_Members_Today_Screen::new_page('food', 5),
    'new_readings' => LLD_Members_Today_Screen::new_page('readings', 5),
    'other_member' => LLD_Members_Today_Screen::new_page('food', 6),
    'unknown' => LLD_Members_Today_Screen::new_page('nope', 5),
  ]);`], { encoding: 'utf8', timeout: 60000 }).trim());

const BOTH = ['LLD_Members_Food_Screen', 'LLD_Members_Readings_Screen'];

test('P3-36 B3: Today links to the Food page and Log a reading when both are built and on', () => {
  const r = run(BOTH, ['food_tracker', 'health_logging']);
  expect(r.food).toBe('https://stg.example.test/members/food/');
  expect(r.readings).toBe('https://stg.example.test/members/readings/');
  expect(r.new_food).toBe(r.food);
  expect(r.new_readings).toBe(r.readings);
  // Another member with the switches off keeps the old pages.
  expect(r.other_member).toBe('');
  expect(r.unknown).toBe('');
});

test('P3-36 B3: each link falls back to its old address while its page is not built, or is off for the member', () => {
  const notBuilt = run([], ['food_tracker', 'health_logging']);
  expect(notBuilt.food).toBe('https://stg.example.test/members/diary/');
  expect(notBuilt.readings).toBe('https://stg.example.test/members/check-in/');
  expect(notBuilt.new_food).toBe('');
  // One page built (Food, #264 merged first): only its links move.
  const foodOnly = run(['LLD_Members_Food_Screen'], ['food_tracker', 'health_logging']);
  expect(foodOnly.food).toBe('https://stg.example.test/members/food/');
  expect(foodOnly.readings).toBe('https://stg.example.test/members/check-in/');
  // Built but switched off for the member.
  const off = run(BOTH, []);
  expect(off.food).toBe('https://stg.example.test/members/diary/');
  expect(off.readings).toBe('https://stg.example.test/members/check-in/');
  const readingsOff = run(BOTH, ['food_tracker']);
  expect(readingsOff.readings).toBe('https://stg.example.test/members/check-in/');
  expect(readingsOff.food).toBe('https://stg.example.test/members/food/');
});

test('P3-36 B3: the five Today links use it, with their labels unchanged, and More lists the two pages', () => {
  const today = src('stage3/screens/class-today-screen.php');
  const food = "self::link_or( 'food', $member_id, '/members/diary/' )";
  const readings = "self::link_or( 'readings', $member_id, '/members/check-in/' )";
  // Addresses only, so the labels on these lines can change (Cursor's wording pull request) without this test:
  // Next step "Log a reading"; the "log a meal" sentence; the Meals tile; Today's totals "Food diary"; "Log one".
  expect(today).toContain(`'log_reading' => array( ${readings},`);
  expect(today.split(`esc_url( ${food} )`).length - 1).toBe(3);
  expect(today.split(`esc_url( ${readings} )`).length - 1).toBe(1);
  expect(today).toContain(`data-lld-tile="meals" href="' . esc_url( ${food} ) . '"`);
  // Not moved: the "Log something" step (it stays on the diary until the labels and targets are settled), the weekly
  // check-in links, and insights_html().
  expect(today).toMatch(/'log_food'\s+=> array\( home_url\( '\/members\/diary\/' \),/);
  expect(today).toMatch(/'check_in'\s+=> array\( home_url\( '\/members\/check-in\/' \),/);
  const insights = today.slice(today.indexOf('private static function insights_html('), today.indexOf('private static function reached_html('));
  expect(insights).not.toContain('link_or');

  // More: the two cards, first, under the same conditions, with the pages' own titles.
  const growth = src('class-growth.php');
  const more = growth.slice(growth.indexOf('public static function more() {'), growth.indexOf("$main = '<h1>More</h1>"));
  expect(more).toContain("if ( '' !== LLD_Members_Today_Screen::new_page( 'food', $member_id ) ) {");
  expect(more).toContain("$new[] = array( 'Food & meals', '/members/food/', 'Log what you eat and drink, meal by meal.' );");
  expect(more).toContain("if ( '' !== LLD_Members_Today_Screen::new_page( 'readings', $member_id ) ) {");
  expect(more).toContain("$new[] = array( 'Log a reading', '/members/readings/', 'Record your readings and view your history.' );");
  expect(more).toContain('$items = array_merge( $new, $items );');
});
