// R-HW-REM-01 reminders and the members' interface routes for the Healthy Weight programme (P3-35, package P2).
import { expect, test } from 'vitest';
import { pluginSrc, run, src } from './hw-data-harness.mjs';

test('TC-FR3-98-AS-09 (wiring)', () => {
  // Regular days: the step reminder at 08:00 and, for an evening snacker, 30 minutes before; a night worker's step
  // reminder follows the wake-up time; without one, nothing is made and the wake-up question is asked. Existing
  // reminders move when the answers change; none is made unless the member asked for reminders.
  const out = run(`
    member(7); member(8); member(9);
    $a = LLD_Members_Reminders::sync_hw(7, ['work' => 'days', 'snack_time' => 'after 9 pm'], true);
    $none = LLD_Members_Reminders::sync_hw(8, ['work' => 'days'], false);
    $ask = LLD_Members_Reminders::sync_hw(9, ['work' => 'nights'], true);
    LLD_Members_HW_Answers::set(9, 'work', 'nights');
    LLD_Members_HW_Answers::set(9, 'wake_time', '14:00');
    $made = LLD_Members_Reminders::sync_hw(9, LLD_Members_HW_Answers::engine(9), true);
    LLD_Members_HW_Answers::set(9, 'wake_time', '15:00');
    $by = function ($m) { $o = []; foreach (raw('reminders') as $r) if ((int) $r['member_id'] === $m) $o[$r['reminder_type']] = [$r['time_local'], (int) $r['active']]; return $o; };
    return ['a' => $a, 'r7' => $by(7), 'r8' => $by(8), 'ask' => $ask, 'r9' => $by(9)];
  `);
  expect(out.r7).toEqual({ hw_step: ['08:00:00', 1], hw_snack: ['20:30:00', 1] });
  expect(out.r8).toEqual([]); // an empty PHP array
  expect(out.ask.ask_wake_up).toBe(true);
  expect(out.r9).toEqual({ hw_step: ['15:30:00', 1] });
});

test('P3-35 reminders: every Healthy Weight reminder says only the T-HW-REM-01 line, with no health detail', () => {
  const out = run(`
    return [LLD_Members_Reminders::email_copy('hw_step', 'Test', 'https://stg.example.test/members/home/'), LLD_Members_Reminders::email_copy('hw_snack', '', 'https://stg.example.test/members/home/')];
  `);
  for (const m of out) {
    expect(m.body).toContain('Your next step is ready on Lifeline Daily.');
    expect(m.body.split('\n').filter((l) => l && !/^Hi |^https:|^— Lifeline Daily$/.test(l))).toEqual(['Your next step is ready on Lifeline Daily.']);
    expect(run(`return LLD_Members_Reminders::email_is_safe(${JSON.stringify(m.body)});`)).toBe(true);
  }
});

test('P3-35 interface: routes for join, answers, barrier, block choice and carry-on; read views; health consent first', () => {
  const api = src('class-api.php');
  expect(api).toContain("'/me/healthy-weight/(?P<action>join|answers|barrier|block|carry-on)'");
  for (const t of ['hw-answers', 'hw-milestones', 'hw-block-choices', 'hw-notes-shown', 'check-in-barriers', 'festival-calendar', 'hw-plan', 'hw-today', 'hw-blocks', 'hw-questions', 'food-drinks', 'food-repeat', 'food-drink']) expect(api, t).toContain(`'${t}'`);
  expect(api).toContain('LLD_Members_HW_Programme::view( $def[\'computed\'], $member_id, $query )');
  const handler = api.slice(api.indexOf('public static function healthy_weight'), api.indexOf('Feature, sign-in and consent checks shared'));
  expect(handler).toMatch(/LLD_Members_Features::enabled\( 'goals', \$member_id \)/);
  expect(handler.indexOf('has_health_consent')).toBeLessThan(handler.indexOf('switch'));
  expect(handler).not.toMatch(/error_log|wp_mail/);
  // The written types are read-only through the generic route: they are written only through the checked actions.
  for (const t of ['hw-answers', 'hw-milestones', 'hw-block-choices', 'hw-notes-shown', 'check-in-barriers']) {
    const def = api.slice(api.indexOf(`'${t}'`), api.indexOf('),', api.indexOf(`'${t}'`)));
    expect(def, t).toContain("'write'   => false");
  }
  const loader = src('loader.php');
  expect(loader).toContain("require_once __DIR__ . '/healthy-weight/class-hw-answers.php';");
  expect(loader).toContain("require_once __DIR__ . '/healthy-weight/class-hw-programme.php';");
  expect(loader).toContain('LLD_Members_HW_Programme::boot();');
  expect(pluginSrc('includes/class-programme.php')).toContain("apply_filters( 'lld_members_day_items'");
});

test('P3-35 interface views: hw-plan, hw-today, hw-questions and hw-festivals for a member', () => {
  const out = run(`
    member(7);
    $none = LLD_Members_HW_Programme::view('hw-plan', 7);
    $gid = weight_goal(7);
    $preview = LLD_Members_HW_Programme::view('hw-plan', 7);
    $today_before = LLD_Members_HW_Programme::view('hw-today', 7);
    LLD_Members_HW_Programme::join(7, $gid, ['stress' => 4]);
    return ['before' => $none, 'preview' => $preview, 'today_before' => $today_before, 'notes' => count(raw('notes_shown')), 'plan' => array_keys(LLD_Members_HW_Programme::view('hw-plan', 7)), 'today' => array_keys(LLD_Members_HW_Programme::view('hw-today', 7)),
      'q' => LLD_Members_HW_Programme::view('hw-questions', 7), 'f' => LLD_Members_HW_Programme::view('hw-festivals', 7), 'x' => LLD_Members_HW_Programme::view('nope', 7)];
  `);
  expect(out.before).toEqual({ joined: false }); // no weight goal yet
  // With a weight goal but before joining, the plan previews the pace and milestones for the pace step (FR3-100)
  // and writes nothing; Today waits for the join.
  expect(out.preview.pace).toMatchObject({ recommended: 0.5, chosen: 0.5, paces: [0.25, 0.5, 0.75] });
  expect(out.preview.milestones.map((m) => m.kg)).toEqual([76, 72, 66.2, 60]);
  expect(out.today_before).toEqual({ joined: false });
  expect(out.plan).toContain('target_kcal');
  expect(out.today).toContain('note');
  expect(out.q.answered).toEqual(expect.arrayContaining(['stress', 'activity_level']));
  expect(out.q.questions.every((q) => q.rules.length > 0)).toBe(true); // TC-FR3-99-AS-04: no question without a rule
  expect(out.f).toEqual({ festivals: [] });
  expect(out.x).toEqual({ error: 'Not available.' });
});
