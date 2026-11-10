// Owner decision Q160 (10 Nov 2026): includes/class-programme.php gets additions only, one new filter for the day's
// step. These tests run the real Stage 2 class: with no filter attached the day's items are exactly as before, and
// the Healthy Weight callback changes nothing outside a Healthy Weight track day (P3-35, package P2).
import { execFileSync } from 'node:child_process';
import { expect, test } from 'vitest';
import { pluginSrc, run } from './hw-data-harness.mjs';
import { resolve } from 'node:path';
import { plugin } from './hw-data-harness.mjs';

const programme = resolve(plugin, 'includes/class-programme.php').split('\\').join('/');

// The Stage 2 class on its own: one track (post 11) with day 3 holding two activities, day 4 holding one the safety
// matrix leaves out, and two action-library items.
const stage2 = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `
define('ABSPATH', '/');
$GLOBALS['f'] = [];
function has_filter($h) { return !empty($GLOBALS['f'][$h]); }
function apply_filters($h, $v, ...$a) { foreach ($GLOBALS['f'][$h] ?? [] as $cb) { $v = $cb($v, ...$a); } return $v; }
function get_posts($args) {
  if ('lld_track' === $args['post_type']) return [(object) ['ID' => 11]];
  return array_map(function ($id) { return (object) ['ID' => $id]; }, [301, 302, 303, 304, 305]);
}
class LLD_Members_Content { static function shown($id) {
  $f = [301 => ['day_number' => 3], 302 => ['day_number' => 3], 303 => ['day_number' => 4], 304 => ['in_library' => 1], 305 => ['in_library' => 1]][$id];
  return ['parent_id' => $id, 'title' => "Item $id", 'fields' => $f + ['track_id' => 11]];
} }
class LLD_Members_Safety { static function allows($m, $item) { return 303 !== $item['parent_id']; } }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class Fake { function prepare($s, ...$a) { return $s; } function get_results($s) { return [(object) ['id' => 1, 'first_on' => '2026-11-10', 'last_on' => null]]; } }
$GLOBALS['wpdb'] = new Fake();
require ${JSON.stringify(programme)};
$m = new ReflectionMethod('LLD_Members_Programme', 'day_items'); $m->setAccessible(true);
$row = (object) ['track_key' => 'healthy-weight', 'id' => 5];
$day = function ($d) use ($m, $row) { $r = $m->invoke(null, 7, $row, $d); return ['ids' => array_column($r['items'], 'parent_id'), 'replaced' => $r['replaced']]; };
echo json_encode((function () use ($day) { ${code} })());
`], { encoding: 'utf8', timeout: 120000 }));

test('P3-35 (Q160): with no filter attached the Stage 2 day items are unchanged', () => {
  const out = stage2(`return ['d3' => $day(3), 'd4' => $day(4), 'd5' => $day(5)];`);
  expect(out.d3).toEqual({ ids: [301, 302], replaced: false });
  expect(out.d4).toEqual({ ids: [305], replaced: true }); // the matrix removed the day's item: a library item stands in
  expect(out.d5).toEqual({ ids: [], replaced: false });
});

test('P3-35 (Q160): a filter gets the safe items, the member and the day\'s date; a bad answer changes nothing', () => {
  const out = stage2(`
    $seen = [];
    $GLOBALS['f']['lld_members_day_items'][] = function ($items, $member, $date) use (&$seen) { $seen[] = [array_column($items, 'parent_id'), $member, $date]; return [$items[1]]; };
    $one = $day(3);
    $GLOBALS['f']['lld_members_day_items'] = [function () { return 'not a list'; }];
    $bad = $day(3);
    return compact('one', 'bad', 'seen');
  `);
  expect(out.seen).toEqual([[[301, 302], 7, '2026-11-12']]);
  expect(out.one).toEqual({ ids: [302], replaced: false });
  expect(out.bad).toEqual({ ids: [301, 302], replaced: false });
});

test('P3-35 (Q160): the change to class-programme.php is the one guarded filter call, and nothing else changed', () => {
  const s = pluginSrc('includes/class-programme.php');
  const body = s.slice(s.indexOf('private static function day_items('), s.indexOf('private static function week_item('));
  // The original lines are all still there, in order.
  const original = [
    '$track = self::track_id( (string) $row->track_key );',
    '$items = self::activities( $track, $day, $member_id );',
    "if ( $items || ! self::activities( $track, $day, $member_id, false ) ) {",
    "return array( 'items' => $items, 'replaced' => false );",
    '$library = self::activities( $track, 0, $member_id );',
    "return array( 'items' => array( $library[ ( $day - 1 ) % count( $library ) ] ), 'replaced' => true );",
  ];
  let at = -1;
  for (const line of original) { const i = body.indexOf(line); expect(i, line).toBeGreaterThan(at); at = i; }
  expect(s.match(/apply_filters\(/g)).toHaveLength(1);
  expect(body).toMatch(/if \( function_exists\( 'has_filter' \) && has_filter\( 'lld_members_day_items' \) \) \{/);
});

test('TC-FR3-103-AS-05 (wiring)', () => {
  // The Healthy Weight callback: on a Healthy Weight track day the item is the programme's step (one a day); an item the
  // safety matrix leaves out, the goals switch off, another track or a member who has not joined keep the Stage 2 items.
  const out = run(`
    member(7); member(8);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ['barrier' => 'evening_snacking']);
    $orig = [['parent_id' => 1003, 'title' => 'HW-W1-A3']];
    $hw = LLD_Members_HW_Programme::day_items($orig, 7, '2026-11-14');
    LLD_Members_Safety::$blocked = [(int) $hw[0]['parent_id']];
    $unsafe = LLD_Members_HW_Programme::day_items($orig, 7, '2026-11-14');
    LLD_Members_Safety::$blocked = [];
    LLD_Members_Features::$off = ['goals'];
    $off = LLD_Members_HW_Programme::day_items($orig, 7, '2026-11-14');
    LLD_Members_Features::$off = [];
    $other = LLD_Members_HW_Programme::day_items($orig, 8, '2026-11-14');
    LLD_Members_Programme::$enrolment[7]['track_key'] = 'fitness';
    $fit = LLD_Members_HW_Programme::day_items($orig, 7, '2026-11-14');
    return compact('hw', 'unsafe', 'off', 'other', 'fit', 'orig');
  `);
  expect(out.hw).toHaveLength(1);
  expect(out.hw[0].hw_step).toMatchObject({ rule: 'R-HW-STEP-01', version: 'standard' });
  expect(out.hw[0].title).toBe(out.hw[0].hw_step.activity_key);
  expect(out.hw[0].title).not.toBe('HW-W1-A3');
  for (const k of ['unsafe', 'off', 'other', 'fit']) expect(out[k], k).toEqual(out.orig);
});

test('P3-35 content shapes (package P3): the activity id, an -ALT item standing in, block weeks and question wording', () => {
  // LLD_Members_HW_Content as package P3 built it: activity_meta() gives the draft id as `id` and an -ALT item its
  // `alt_for`; block() gives weeks: {n: {activities: day => activity}}; question() gives title and why.
  const out = run(`
    class LLD_Members_HW_Content {
      static function activity_meta($pid) {
        if (5000 === (int) $pid) return ['id' => 'HW-W2-A4-ALT', 'habit_tags' => ['activity'], 'alt_for' => 'HW-W2-A4'];
        $d = (int) $pid % 1000; $w = (int) ceil($d / 7); $k = (($d - 1) % 7) + 1;
        return ['id' => ((int) $pid >= 2000 ? 'HW2' : 'HW') . "-W$w-A$k", 'habit_tags' => ['activity'], 'alt_for' => ''];
      }
      static function block($key) { return ['key' => $key, 'weeks' => [1 => ['activities' => [1 => ['id' => 'HW-FB07-W1-A1'], 2 => ['id' => 'HW-FB07-W1-A2']]]]]; }
      static function question($key) { return 'food_rules_allergies' === $key ? ['title' => 'Do you follow any of these food rules?', 'why' => 'So your meal plans and recipes only include food you eat.', 'choices' => ['Halal']] : null; }
      static function questions() { return []; }
      static function notes($t) { return []; }
      static function festivals() { return []; }
    }
    LLD_Members_Programme::$acts[] = ['id' => 'HW-W2-A4-ALT', 'week' => 2, 'day' => 4, 'tags' => ['activity'], 'pid' => 5000];
    member(7, ['situations' => ['blood_pressure']]);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    $w2 = LLD_Members_HW_Programme::today(7, '2026-11-22')['step']; // week 2, day 4
    LLD_Members_Programme::$enrolment[7] = ['id' => 77, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-10-01'];
    LLD_Members_Clock::$now = '2027-01-08 09:00:00';
    LLD_Members_HW_Programme::choose_block(7, 'getting-stronger', '2027-01-08');
    $block = LLD_Members_HW_Programme::today(7, '2027-01-09')['step'];
    $q = LLD_Members_HW_Answers::questions('track_start');
    return ['w2' => $w2, 'block' => $block, 'qod' => LLD_Members_HW_Programme::today(7, '2026-11-12')['qod'][0], 'q' => $q[0]];
  `);
  expect(out.w2.activity_key).toBe('HW-W2-A4-ALT'); // high blood pressure: the steady-walk alternative (R-HW-SAFE-04)
  expect(out.block.activity_key).toBe('HW-FB07-W1-A2');
  expect(out.qod).toMatchObject({ key: 'food_rules_allergies', wording: 'Do you follow any of these food rules?', reason: 'So your meal plans and recipes only include food you eat.' });
  expect(out.q.key).toBe('food_rules');
});
