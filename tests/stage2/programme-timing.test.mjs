// S2-C5 unit checks of the programme rules that need no WordPress: T3 timing (local dates, time-zone changes,
// clock changes, active periods), the FR2-10 plan rules and the FR2-12 reminder window. Each case runs the plugin's
// own functions through the PHP command line, so the code under test is the code that ships.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const programme = resolve(plugin, 'includes/class-programme.php').replace(/\\/g, '/');
const source = readFileSync(programme, 'utf8');

/** Run PHP with the programme class loaded; the snippet echoes JSON. */
function php(snippet) {
  const code = `define('ABSPATH', __DIR__ . '/'); define('MINUTE_IN_SECONDS', 60); require '${programme}'; $P = 'LLD_Members_Programme'; ${snippet}`;
  return JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8' }));
}

const at = (utc) => `new DateTimeImmutable('${utc}', new DateTimeZone('UTC'))`;

test('T3 rule 1: the local date follows the member zone, including the nights the clocks change', () => {
  const rows = php(`echo json_encode([
    $P::zone_date(${at('2026-03-29 00:30:00')}, 'Europe/London'),
    $P::zone_date(${at('2026-03-28 23:30:00')}, 'Europe/London'),
    $P::zone_date(${at('2026-10-24 23:30:00')}, 'Europe/London'),
    $P::zone_date(${at('2026-10-25 23:30:00')}, 'Europe/London'),
    $P::zone_date(${at('2026-03-08 04:30:00')}, 'America/New_York'),
    $P::zone_date(${at('2026-11-01 04:30:00')}, 'America/New_York'),
    $P::zone_date(${at('2026-11-01 05:30:00')}, 'America/New_York'),
    $P::zone_date(${at('2026-01-01 10:00:00')}, 'Not/AZone'),
  ]);`);
  // 00:30 UTC on 29 March is 00:30 GMT; 23:30 UTC on 24 October is 00:30 BST on the 25th; 23:30 UTC on the 25th is GMT.
  expect(rows).toEqual(['2026-03-29', '2026-03-28', '2026-10-25', '2026-10-25', '2026-03-07', '2026-11-01', '2026-11-01', '2026-01-01']);
});

test('T3 rule 1: clock changes never add or remove a programme day', () => {
  const rows = php(`
    $p = [['first' => '2026-03-27', 'last' => '']];
    $q = [['first' => '2026-10-23', 'last' => '']];
    echo json_encode([
      $P::days_until($p, '2026-03-29'), $P::days_until($p, '2026-03-30'), $P::day_date($p, 3), $P::day_date($p, 28),
      $P::days_until($q, '2026-10-25'), $P::days_until($q, '2026-10-26'), $P::days_between('2026-03-28', '2026-03-30'),
      $P::days_between('2026-10-24', '2026-10-26'), $P::days_between('2026-11-03', '2026-11-01'),
    ]);`);
  expect(rows).toEqual([3, 4, '2026-03-29', '2026-04-23', 3, 4, 2, 2, -2]);
});

test('T3 rule 2: a time-zone change eastward applies from the next local date', () => {
  // London to Tokyo at 20:00 London on 2 November (05:00 on 3 November in Tokyo).
  const rows = php(`echo json_encode([
    $P::zone_date(${at('2026-11-02 20:00:00')}, 'Asia/Tokyo', 'Europe/London', '2026-11-02'),
    $P::zone_date(${at('2026-11-02 23:59:00')}, 'Asia/Tokyo', 'Europe/London', '2026-11-02'),
    $P::zone_date(${at('2026-11-03 00:00:00')}, 'Asia/Tokyo', 'Europe/London', '2026-11-02'),
    $P::zone_date(${at('2026-11-03 16:00:00')}, 'Asia/Tokyo', 'Europe/London', '2026-11-02'),
  ]);`);
  expect(rows).toEqual(['2026-11-02', '2026-11-02', '2026-11-03', '2026-11-04']);
});

test('T3 rule 2: a time-zone change westward never repeats a date', () => {
  // London to New York at 23:30 London on 2 November (18:30 in New York).
  const rows = php(`echo json_encode([
    $P::zone_date(${at('2026-11-02 23:30:00')}, 'America/New_York', 'Europe/London', '2026-11-02'),
    $P::zone_date(${at('2026-11-03 00:30:00')}, 'America/New_York', 'Europe/London', '2026-11-02'),
    $P::zone_date(${at('2026-11-03 04:59:00')}, 'America/New_York', 'Europe/London', '2026-11-02'),
    $P::zone_date(${at('2026-11-03 05:00:00')}, 'America/New_York', 'Europe/London', '2026-11-02'),
  ]);`);
  expect(rows).toEqual(['2026-11-02', '2026-11-02', '2026-11-02', '2026-11-03']);
});

test('T3 rules 3 and 5: the day count runs over active periods and continues after a resume', () => {
  const rows = php(`
    $p = [['first' => '2026-11-02', 'last' => '2026-11-15'], ['first' => '2026-12-01', 'last' => '']];
    echo json_encode([
      $P::days_until($p, '2026-11-15'), $P::days_until($p, '2026-11-30'), $P::days_until($p, '2026-12-01'),
      $P::day_date($p, 14), $P::day_date($p, 15), $P::day_date($p, 28), $P::days_until($p, '2026-11-01'),
      $P::position_parts(15), $P::position_parts(28),
    ]);`);
  expect(rows).toEqual([14, 14, 15, '2026-11-15', '2026-12-01', '2026-12-14', 0, { programme: 15, week: 3, day: 1 }, { programme: 28, week: 4, day: 7 }]);
});

test('T3: activity day numbers 1 to 7 with a later week number count within that week', () => {
  const rows = php(`echo json_encode([
    $P::item_day(['day_number' => 10, 'week_number' => 2]), $P::item_day(['day_number' => 3, 'week_number' => 2]),
    $P::item_day(['day_number' => 3, 'week_number' => 1]), $P::item_day(['day_number' => 0]),
  ]);`);
  expect(rows).toEqual([10, 10, 3, 0]);
});

test('TC-FR2-12-AS-01 the reminder window is 10 minutes from the chosen local time, on clock-change days too', () => {
  const rows = php(`echo json_encode([
    $P::reminder_window(${at('2026-01-15 07:29:59')}, 'Europe/London', '07:30'),
    $P::reminder_window(${at('2026-01-15 07:30:00')}, 'Europe/London', '07:30'),
    $P::reminder_window(${at('2026-01-15 07:39:59')}, 'Europe/London', '07:30:00'),
    $P::reminder_window(${at('2026-01-15 07:40:00')}, 'Europe/London', '07:30'),
    $P::reminder_window(${at('2026-03-29 06:30:00')}, 'Europe/London', '07:30'),
    $P::reminder_window(${at('2026-03-29 07:30:00')}, 'Europe/London', '07:30'),
    $P::reminder_window(${at('2026-10-25 07:30:00')}, 'Europe/London', '07:30'),
    $P::reminder_window(${at('2026-03-08 11:30:00')}, 'America/New_York', '07:30'),
    $P::reminder_window(${at('2026-11-01 12:30:00')}, 'America/New_York', '07:30'),
    $P::reminder_window(${at('2026-11-01 11:30:00')}, 'America/New_York', '07:30'),
    $P::reminder_window(${at('2026-03-29 01:30:00')}, 'Europe/London', '01:30'),
  ]);`);
  // 01:30 does not exist in London on 29 March 2026; the reminder goes at 02:30 BST (01:30 UTC).
  expect(rows).toEqual([false, true, true, false, true, false, true, true, true, false, true]);
});

const library = `
  $items = [];
  $id = 1;
  foreach (['easier' => 4, 'standard' => 4, 'stretch' => 2] as $diff => $n) {
    for ($i = 1; $i <= $n; $i++) { $items[] = ['parent_id' => $id++, 'version' => 1, 'title' => ucfirst($diff) . " $i", 'fields' => ['difficulty' => $diff]]; }
  }
`;

test('TC-FR2-10-AS-02 49 % is easier, 50 % and 80 % the same, 81 % one stretch with the same count (unit)', () => {
  const rows = php(`${library}
    $out = [];
    foreach ([49, 49.99, 50, 80, 80.01, 81] as $pct) { $b = $P::select($items, $pct, 'standard', 0); $out[] = [$b['level'], array_column($b['actions'], 'title')]; }
    echo json_encode($out);`);
  expect(rows.map((row) => row[0])).toEqual(['easier', 'easier', 'same', 'same', 'stretch', 'stretch']);
  expect(rows[0][1].every((title) => title.startsWith('Easier'))).toBe(true);
  expect(rows[2][1].every((title) => title.startsWith('Standard'))).toBe(true);
  expect(rows[5][1]).toHaveLength(rows[2][1].length);
  expect(rows[5][1].filter((title) => title.startsWith('Stretch'))).toHaveLength(1);
  expect(rows[5][1].filter((title) => title.startsWith('Standard'))).toHaveLength(rows[2][1].length - 1);
});

test('TC-FR2-10-AS-02 the same level keeps last week\'s easier band (unit)', () => {
  const rows = php(`${library} $b = $P::select($items, 60, 'easier', 0); echo json_encode([$b['level'], array_column($b['actions'], 'title')]);`);
  expect(rows[0]).toBe('same');
  expect(rows[1].every((title) => title.startsWith('Easier'))).toBe(true);
});

test('TC-FR2-10-AS-01 every generated week has 3 to 5 actions, for any completion and pool size (unit)', () => {
  const rows = php(`${library}
    $out = [];
    foreach ([3, 4, 5, 7, 10] as $size) {
      $pool = array_slice($items, 10 - $size);
      foreach ([null, 0, 10, 49, 50, 65, 80, 81, 95, 100] as $w => $pct) { $out[] = count($P::select($pool, $pct, $w % 2 ? 'easier' : 'standard', $w)['actions']); }
    }
    echo json_encode($out);`);
  expect(rows).toHaveLength(50);
  for (const count of rows) {
    expect(count).toBeGreaterThanOrEqual(3);
    expect(count).toBeLessThanOrEqual(5);
  }
});

test('TC-FR2-10-AS-06 two safe actions give both with the note; none gives the empty message (unit)', () => {
  const rows = php(`${library}
    $two = $P::select(array_slice($items, 0, 2), 90, 'standard', 0);
    $none = $P::select([], 90, 'standard', 0);
    echo json_encode([count($two['actions']), $two['note'], count($none['actions']), $none['note']]);`);
  expect(rows).toEqual([2, 'Fewer than 3 safe actions are available.', 0, 'No safe actions are available this week.']);
});

test('FR2-10 swaps: a swap avoids the current actions where the pool allows, and keeps the count (unit)', () => {
  const rows = php(`${library}
    $first = $P::select($items, 60, 'standard', 0);
    $now = array_column($first['actions'], 'parent_id');
    $swap = $P::select($items, null, 'standard', 1, $now, $first['level']);
    $small = array_slice($items, 3, 5);
    $sfirst = $P::select($small, 60, 'standard', 0);
    $sswap = $P::select($small, null, 'standard', 1, array_column($sfirst['actions'], 'parent_id'), 'same');
    $tiny = array_slice($items, 4, 3);
    $tfirst = $P::select($tiny, 60, 'standard', 0);
    $tswap = $P::select($tiny, null, 'standard', 1, array_column($tfirst['actions'], 'parent_id'), 'same');
    echo json_encode([
      count(array_intersect($now, array_column($swap['actions'], 'parent_id'))), count($swap['actions']), count($first['actions']),
      count(array_diff(array_column($sswap['actions'], 'parent_id'), array_column($sfirst['actions'], 'parent_id'))), count($sswap['actions']),
      count(array_diff(array_column($tswap['actions'], 'parent_id'), array_column($tfirst['actions'], 'parent_id'))),
    ]);`);
  // A full pool swaps every action; a pool of 5 (one spare) swaps one in; a pool of exactly 3 has no alternative.
  expect(rows).toEqual([0, 4, 4, 1, 4, 0]);
});

test('FR2-12: programme emails carry a notice and a link only, with no health words or numbers', () => {
  const bodies = php(`echo json_encode(array_map(fn($k) => $P::mail_body($k, 'https://example.test/members/home/', 'https://example.test/members/unsubscribe/?code=abc&kind=' . $k), array_keys($P::mail_kinds())));`);
  expect(bodies).toHaveLength(5);
  for (const body of bodies) {
    expect(body.replace(/https?:\/\/\S+/g, '')).not.toMatch(/\d/);
    expect(body).not.toMatch(/weight|waist|bmi|glucose|diagnosis|sleep|stress|pregnan|kg|cm\b/i);
  }
});

test('FR2-12-AS-01 the reminder runs on a 5-minute recurring event, not the daily one', () => {
  expect(source).toMatch(/const TICK_SECONDS\s*=\s*300;/);
  expect(source).toMatch(/wp_schedule_event\(\s*time\(\)\s*\+\s*60,\s*self::TICK_SCHEDULE,\s*self::TICK_HOOK\s*\)/);
  expect(source).toMatch(/add_action\(\s*self::TICK_HOOK,\s*array\(\s*__CLASS__,\s*'tick'\s*\)\s*\)/);
  const tick = source.slice(source.indexOf('public static function tick()'), source.indexOf('public static function dispatch_mail()'));
  expect(tick).toContain('reminder_due');
  const daily = source.slice(source.indexOf('public static function dispatch_mail()'), source.indexOf('private static function maybe_nudge('));
  expect(daily).not.toContain('daily_reminder');
  const plugin = readFileSync(resolve(here, '../../site/public_html/wp-content/plugins/lld-members/includes/class-plugin.php'), 'utf8');
  expect(plugin).toContain('LLD_Members_Programme::boot();');
});

test('FR2-11 and FR2-12: every scheduled send is claimed before it is sent', () => {
  expect(source).toMatch(/INSERT IGNORE INTO \{\$table\} \(member_id, claim_key, sent_on\)/);
  expect(source).toMatch(/WHERE member_id = %d AND nudges_sent = %d/);
  expect(source).not.toMatch(/update_option\(\s*'lld_mail_/);
  const send = source.slice(source.indexOf('private static function mail_if_chosen('), source.indexOf('public static function tick()'));
  expect(send.indexOf('self::claim(')).toBeGreaterThan(-1);
  expect(send.indexOf('self::claim(')).toBeLessThan(send.indexOf('self::send_kind('));
});
