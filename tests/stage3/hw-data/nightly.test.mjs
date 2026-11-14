// The programme's nightly part, the check-in barrier, levels and focus blocks (P3-35, package P2): what is stored and
// wired around the P1 engine (FR3-101, FR3-104 to FR3-107, Q161, R-SAFE-04 low-mood pause).
import { expect, test } from 'vitest';
import { run, src } from './hw-data-harness.mjs';

test('TC-FR3-105-AS-01 (wiring): one note a day is stored, in the default order, never twice for a day', () => {
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    $keys = [];
    for ($d = 0; $d < 3; $d++) {
      $date = gmdate('Y-m-d', strtotime("2026-11-12 +$d days"));
      LLD_Members_Programme::$today = $date;
      LLD_Members_HW_Programme::nightly(7, $date);
      LLD_Members_HW_Programme::nightly(7, $date);
      $keys[] = LLD_Members_HW_Programme::today(7, $date)['note']['key'];
    }
    return ['keys' => $keys, 'rows' => raw('notes_shown')];
  `);
  expect(out.keys).toEqual(['HW-MN-W1-1', 'HW-MN-W1-2', 'HW-MN-W1-3']);
  expect(out.rows).toHaveLength(3);
  expect(out.rows.every((r) => r.reason === 'default' && r.rule_id === null)).toBe(true);
});

test('TC-FR3-106-AS-04', () => {
  // A low mood (R-SAFE-04 shown tonight) brings the kindness note with the support wording; for the 7 days after it no
  // milestone, plateau or other note rule runs, and never a weight-loss message.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ['stress' => 8]);
    foreach (['2026-11-06','2026-11-08','2026-11-10','2026-11-12'] as $d) weigh(7, $d, 75.5);
    $r = LLD_Members_HW_Programme::nightly(7, '2026-11-12', ['safety' => ['R-SAFE-04']]);
    $t = LLD_Members_HW_Programme::today(7, '2026-11-12');
    $v = LLD_Members_HW_Programme::plan_view(7);
    return ['r' => $r, 'note' => $t['note'], 'row' => raw('notes_shown')[0], 'ms' => raw('goal_milestones'), 'cards' => $v['cards']];
  `);
  expect(out.r).toEqual({ status: 'ran', note: true, low_mood_pause: true, milestone: false, plateau: false });
  expect(out.note).toMatchObject({ key: 'HW-MN-W4-4', support: true });
  expect(out.row).toMatchObject({ reason: 'rule', rule_id: 1061 });
  expect(out.ms.every((m) => m.reached_at === null)).toBe(true);
  expect(out.cards.map((c) => c.rule)).not.toContain('R-HW-MOT-02');
});

test('TC-FR3-101-AS-03', () => {
  // Reaching 76 kg (7-day mean) marks the 5 % milestone reached; the celebration shows once, then the re-check
  // questions are offered.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    foreach (['2026-11-10','2026-11-11','2026-11-12'] as $d) weigh(7, $d, 75.8);
    $r = LLD_Members_HW_Programme::nightly(7, '2026-11-12');
    $first = LLD_Members_HW_Programme::plan_view(7)['cards'];
    $second = LLD_Members_HW_Programme::plan_view(7)['cards'];
    return ['r' => $r, 'first' => $first, 'second' => $second, 'ms' => LLD_Members_Goal_Journey::milestone_rows(7, $gid), 'q' => LLD_Members_HW_Programme::view('hw-questions', 7)['recheck']];
  `);
  expect(out.r.milestone).toBe(true);
  const card = out.first.find((c) => c.rule === 'R-HW-MOT-02');
  expect(card.key).toBe('hw.T-HW-MOT-02.5pct');
  expect(card.text).toContain('76.0 kg');
  expect(out.second.find((c) => c.rule === 'R-HW-MOT-02')).toBeUndefined();
  expect(out.ms[0]).toMatchObject({ kind: 'five_percent', celebrated: 1 });
  expect(out.ms[0].reached_at).not.toBeNull();
  expect(out.ms[1].reached_at).toBeNull();
  expect(out.q).toEqual(['time', 'work', 'stress']);
});

test('TC-FR3-107-AS-01 (wiring)', () => {
  // Skipping the question stores nothing and still completes; a Free member gets the illness wording only, Premium the
  // replies, and the check-in's notes are brought forward for Premium (R-HW-WEEK-01).
  const out = run(`
    member(7, ['premium' => false]); member(8);
    $g7 = weight_goal(7); $g8 = weight_goal(8);
    LLD_Members_HW_Programme::join(7, $g7, []); LLD_Members_HW_Programme::join(8, $g8, []);
    $skip = LLD_Members_HW_Programme::record_barrier(7, '2026-11-19', [], '');
    $free = LLD_Members_HW_Programme::record_barrier(7, '2026-11-19', ['illness', 'family'], '');
    $prem = LLD_Members_HW_Programme::record_barrier(8, '2026-11-19', ['family'], '');
    $bad  = LLD_Members_HW_Programme::record_barrier(8, '2026-11-19', ['boredom'], '');
    $state = json_decode(LLD_Members_Goal_Journey::plan(8, $g8)['log_schedule'], true)['hw'];
    return compact('skip', 'free', 'prem') + ['bad' => $bad->get_error_code(), 'pending' => $state['pending'], 'rows' => count(raw('check_in_barriers'))];
  `);
  expect(out.skip).toEqual({ saved: false, replies: [] });
  expect(out.free.replies).toEqual(["We hope you're feeling better. Next week's steps are easier, and your plan won't change because of this week."]);
  expect(out.prem.replies[0]).toContain('Family life');
  expect(out.bad).toBe('lld_hw_barrier');
  expect(out.pending).toEqual(['HW-MN-W6-5']);
  expect(out.rows).toBe(2);
});

test('P3-35 levels: "Carry on to week 5" opens at the end of week 4 and starts level 2 (Q161 = A)', () => {
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    $early = LLD_Members_HW_Programme::carry_on(7);
    LLD_Members_Programme::$today = '2026-12-09'; // day 28 of level 1
    LLD_Members_Goal_Journey::facts(7);
    $b = LLD_Members_HW_Programme::blocks(7, '2026-12-09');
    $go = LLD_Members_HW_Programme::carry_on(7);
    LLD_Members_Programme::$today = '2026-12-10';
    $w5 = LLD_Members_HW_Programme::blocks(7, '2026-12-10');
    return ['early' => $early->get_error_code(), 'b' => $b, 'started' => LLD_Members_Programme::$started, 'w5' => $w5, 'step' => LLD_Members_HW_Programme::today(7, '2026-12-10')['step']];
  `);
  expect(out.early).toBe('lld_hw_carry_on');
  expect(out.b).toMatchObject({ stage: 'level', programme_week: 4, carry_on: true });
  expect(out.started).toEqual([[7, 'healthy-weight'], [7, 'healthy-weight-2']]);
  expect(out.w5).toMatchObject({ stage: 'level', programme_week: 5, carry_on: false });
  expect(out.step.activity_key).toBe('HW2-W1-A1');
});

test('TC-FR3-104-AS-01 (wiring)', () => {
  // After week 8 a Premium member is offered a block with a one-line reason and may choose any; the choice is stored
  // with the suggestion and its rule. Free members cannot start a block.
  const out = run(`
    member(7); member(8, ['premium' => false]);
    $g7 = weight_goal(7); $g8 = weight_goal(8);
    LLD_Members_HW_Programme::join(7, $g7, ['barrier' => 'evening_snacking']);
    LLD_Members_HW_Programme::join(8, $g8, []);
    LLD_Members_Programme::$enrolment[7] = ['id' => 77, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-12-10'];
    LLD_Members_Programme::$today = '2027-01-08';
    LLD_Members_Clock::$now = '2027-01-08 09:00:00';
    $b = LLD_Members_HW_Programme::blocks(7, '2027-01-08');
    $c = LLD_Members_HW_Programme::choose_block(7, 'getting-stronger', null, '2027-01-08');
    $free = LLD_Members_HW_Programme::choose_block(8, 'getting-stronger', null, '2027-01-08');
    return ['b' => $b, 'c' => $c, 'free' => $free->get_error_code(), 'row' => raw('block_choices')[0]];
  `);
  expect(out.b.stage).toBe('between');
  expect(out.b.suggested).toMatchObject({ block: 'evening-night-eating', reason: 'you said evening snacking gets in the way' });
  expect(out.b.choices).toHaveLength(9);
  expect(out.c.current).toMatchObject({ block: 'getting-stronger', week: 1, day: 1 });
  expect(out.c.stage).toBe('block');
  expect(out.row).toMatchObject({ suggested_block_key: 'evening-night-eating', chosen_block_key: 'getting-stronger', rule_id: 1101, declined_festival: null });
  expect(out.free).toBe('lld_premium');
});

test('TC-FR3-104-AS-06 (wiring)', () => {
  // Choosing Festivals and fasting for a test 30-day fast (8 Feb to 9 Mar 2027, accepted 1 Jan 2027) stores the
  // R-HW-BLOCK-03 day map: week 3 from 8 Feb, repeated to 9 Mar, week 4 from 10 to 16 Mar; Today follows it.
  const out = run(`
    global $wpdb;
    $wpdb->insert('wp_lld_festival_calendar', ['name' => 'Test fast', 'kind' => 'dawn_to_sunset_fast', 'starts_on' => '2027-02-08', 'ends_on' => '2027-03-09', 'expected' => 0, 'approved_on' => '2026-11-10']);
    $wpdb->insert('wp_lld_festival_calendar', ['name' => 'Not approved', 'kind' => 'one_day_festival', 'starts_on' => '2027-02-01', 'ends_on' => '2027-02-01', 'expected' => 0, 'approved_on' => null]);
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    LLD_Members_HW_Answers::set(7, 'fasts', ['1']);
    $bad = LLD_Members_HW_Answers::set(7, 'fasts', ['2']);
    LLD_Members_Programme::$enrolment[7] = ['id' => 77, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-11-01'];
    LLD_Members_Programme::$today = '2027-01-01';
    $c = LLD_Members_HW_Programme::choose_block(7, 'festivals-and-fasting', '1', '2027-01-01');
    $s = json_decode(LLD_Members_Goal_Journey::plan(7, $gid)['log_schedule'], true)['hw']['festival'];
    return ['c' => $c, 'f' => [$s['days']['2027-02-08'], $s['days']['2027-03-09'], $s['days']['2027-03-10'], $s['days']['2027-03-16']], 'to' => $s['to'],
      'step' => LLD_Members_HW_Programme::today(7, '2027-02-08')['step'], 'bad' => $bad->get_error_code(), 'fest' => LLD_Members_HW_Programme::festivals()];
  `);
  expect(out.fest.map((f) => f.name)).toEqual(['Test fast']);
  expect(out.bad).toBe('lld_hw_answer');
  expect(out.f[0]).toMatchObject({ kind: 'festival', week: 3 });
  expect(out.f[1].kind).toBe('repeat');
  expect(out.f[2]).toMatchObject({ kind: 'festival', week: 4 });
  expect(out.to).toBe('2027-03-16');
  expect(out.c.festival).toMatchObject({ entry: 1, to: '2027-03-16' });
  expect(out.step.activity_key).toBe(out.f[0].step);
});

test('P3-35 nightly: the engine runner calls it after its own run and passes its summary', () => {
  const runner = src('engine/class-insights-runner.php');
  expect(runner).toMatch(/if \( ! \$dry && class_exists\( 'LLD_Members_HW_Programme' \) \) \{\s*\$summary\['healthy_weight'\] = LLD_Members_HW_Programme::nightly\( \$member_id, \$result\['date'\], \$summary \);/);
  const out = run(`
    member(7);
    return [LLD_Members_HW_Programme::nightly(7, '2026-11-12'), LLD_Members_HW_Programme::today(7)];
  `);
  expect(out).toEqual([{ status: 'not_joined', note: false, low_mood_pause: false, milestone: false, plateau: false }, null]);
});
