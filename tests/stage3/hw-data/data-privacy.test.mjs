// Healthy Weight programme data and privacy (P3-35, package P2): the six new tables and five new columns (data model
// v0.2, add-only, NFR3-04), sealing, health consent, "Download my data", deletion with the account or consent, and
// no answer in any email, analytics event or admin page (FR3-98, FR3-107, FR3-109, FR3-110).
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';
import { arr, run, src, stage3 } from './hw-data-harness.mjs';

const answers = { time: 'under_10', confidence: 3, sleep: '6_7', stress: 9, alcohol_freq: '3_4_days', alcohol_amount: '3_4', barrier: 'evening_snacking', work: 'days' };

test('P3-35 schema: the six tables with every data model field, the five columns, version 7, add-only', () => {
  const out = run(`
    global $wpdb;
    $cols = [];
    foreach (['profile_answers','goal_milestones','block_choices','festival_calendar','notes_shown','check_in_barriers','goals','plans','food_preferences'] as $t) $cols[$t] = $wpdb->tables['wp_lld_' . $t]['cols'];
    return ['cols' => $cols, 'version' => get_option('lld_members_stage3_schema_version'), 'health' => LLD_Members_Stage3_Schema::health_suffixes(), 'links' => LLD_Members_Stage3_Schema::member_links(), 'all' => LLD_Members_Stage3_Schema::suffixes()];
  `);
  expect(out.version).toBe(7);
  const has = (t, list) => { for (const c of list) expect(out.cols[t], `${t}.${c}`).toContain(c); };
  has('profile_answers', ['member_id', 'question_key', 'answer_ct', 'skipped', 'asked_in', 'created_at', 'updated_at']);
  has('goal_milestones', ['goal_id', 'kind', 'target_value_ct', 'reached_at', 'celebrated', 'recheck_done']);
  has('block_choices', ['member_id', 'suggested_block_id', 'rule_id', 'chosen_block_id', 'started_at', 'finished_at', 'festival_entry_id', 'paused_at', 'resumed_at', 'declined_festival', 'created_at']);
  has('festival_calendar', ['name', 'kind', 'starts_on', 'ends_on', 'expected', 'approved_on']);
  has('notes_shown', ['member_id', 'note_key', 'shown_on', 'reason', 'rule_id']);
  has('check_in_barriers', ['member_id', 'check_in_date', 'barrier_choices_ct', 'barrier_text_ct']);
  has('goals', ['recommended_rate', 'chosen_rate', 'pace_rule_id']);
  has('plans', ['eating_method']);
  has('food_preferences', ['food_rules_ct']);
  expect(out.cols.food_preferences).not.toContain('food_rules'); // sealed only, never a plain column
  for (const t of ['profile_answers', 'goal_milestones', 'block_choices', 'check_in_barriers']) expect(out.health).toContain(t);
  expect(out.health).not.toContain('festival_calendar');
  expect(out.links).not.toHaveProperty('festival_calendar');
  expect(out.links.notes_shown).toEqual(['member_id']);
  const schema = src('class-schema-stage3.php');
  expect(schema).not.toMatch(/DROP\s+(TABLE|COLUMN)|ALTER TABLE \S+ (DROP|MODIFY|CHANGE|RENAME)/i);
  for (const c of ["'goals', 'recommended_rate'", "'goals', 'chosen_rate'", "'goals', 'pace_rule_id'", "'plans', 'eating_method'", "'food_preferences', 'food_rules_ct'"]) expect(schema).toContain(`ensure_column( ${c}`);
});

test('TC-FR3-98-AS-04', () => {
  // A member who declines health consent is not asked, and nothing is stored: no answer, no join, no barrier.
  const out = run(`
    member(7, ['consent' => false]);
    $set = LLD_Members_HW_Answers::set(7, 'stress', 9);
    $join = LLD_Members_HW_Programme::join(7, 1, ['stress' => 9]);
    $bar = LLD_Members_HW_Programme::record_barrier(7, '2026-11-12', ['stress'], 'a busy week');
    return ['set' => $set->get_error_code(), 'join' => $join->get_error_code(), 'bar' => $bar->get_error_code(), 'all' => LLD_Members_HW_Answers::all(7),
      'rows' => count(raw('profile_answers')) + count(raw('check_in_barriers')) + count(raw('goal_milestones')) + count(raw('food_preferences')), 'today' => LLD_Members_HW_Programme::today(7)];
  `);
  expect(out).toMatchObject({ set: 'lld_hw_consent', join: 'lld_hw_consent', bar: 'lld_hw_consent', all: [], rows: 0, today: null });
  // The route refuses before reading the body.
  const api = src('class-api.php');
  const handler = api.slice(api.indexOf('public static function healthy_weight'));
  expect(handler.indexOf('has_health_consent')).toBeLessThan(handler.indexOf('get_json_params'));
});

test('TC-FR3-98-AS-05', () => {
  // The answers are in "Download my data" (opened, as on screen) and are gone after the account is deleted.
  const out = run(`
    member(7); member(8);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ${arr(answers)});
    LLD_Members_HW_Answers::set(8, 'stress', 5);
    $tables = LLD_Members_Stage3_Export::download_tables(['member']);
    $rows = ['profile_answers' => raw('profile_answers'), 'goal_milestones' => raw('goal_milestones')];
    $rows['profile_answers'] = array_values(array_filter($rows['profile_answers'], function ($r) { return 7 === (int) $r['member_id']; }));
    $opened = LLD_Members_Stage3_Export::open_sealed($rows, 7);
    LLD_Members_Stage3_Erasure::erase_account(7);
    $left = [];
    foreach (['profile_answers','goal_milestones','notes_shown','block_choices','check_in_barriers','goals','plans','nutrition_targets'] as $t) $left[$t] = count(array_filter(raw($t), function ($r) { return 7 === (int) $r['member_id']; }));
    return ['tables' => $tables, 'opened' => $opened, 'left' => $left, 'other' => LLD_Members_HW_Answers::get(8, 'stress')];
  `);
  for (const t of ['profile_answers', 'goal_milestones', 'block_choices', 'notes_shown', 'check_in_barriers']) expect(out.tables).toContain(t);
  const stress = out.opened.profile_answers.find((r) => r.question_key === 'stress');
  expect(stress.answer).toBe('9');
  expect(stress).not.toHaveProperty('answer_ct');
  expect(out.opened.goal_milestones.map((m) => Number(m.target_value))).toEqual([76, 72, 66.2, 60]);
  expect(Object.values(out.left).every((n) => n === 0)).toBe(true);
  expect(out.other).toBe(5); // another member's answers stay
});

test('TC-FR3-98-AS-06', () => {
  // No email, analytics event or admin page contains an answer: stored sealed, the reminders say only the T-HW-REM-01
  // line, and no analytics, admin or email code reads the programme's tables.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, ['stress' => 9, 'sleep' => 'under_6', 'work' => 'days', 'reminders_on' => true]);
    $mail = [];
    foreach (raw('reminders') as $r) $mail[] = LLD_Members_Reminders::email_copy($r['reminder_type'], 'Test', 'https://stg.example.test/members/home/');
    return ['answers' => raw('profile_answers'), 'mail' => $mail];
  `);
  for (const r of out.answers) {
    expect(r.answer_ct).toMatch(/^ct:/);
    expect(JSON.stringify(r)).not.toMatch(/under_6|"9"/);
  }
  expect(out.mail.length).toBeGreaterThan(0);
  for (const m of out.mail) {
    expect(m.body).toContain('Your next step is ready on Lifeline Daily.');
    expect(m.body).not.toMatch(/stress|sleep|weight|kg|calorie/i);
  }
  const readers = ['class-analytics.php', 'class-weekly-report-stage3.php', ...readdirSync(stage3('admin')).map((f) => `admin/${f}`)];
  for (const f of readers) expect(readFileSync(stage3(f), 'utf8'), f).not.toMatch(/profile_answers|check_in_barriers|goal_milestones|block_choices|notes_shown|LLD_Members_HW_/);
  for (const f of ['healthy-weight/class-hw-answers.php', 'healthy-weight/class-hw-programme.php']) expect(src(f), f).not.toMatch(/wp_mail|error_log|gtag|LLD_Members_Analytics/);
});

test('TC-FR3-107-AS-03', () => {
  // "What got in the way?" is sealed, in "Download my data", deleted with consent, and in no email or analytics event.
  const out = run(`
    member(7);
    $gid = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $gid, []);
    $r = LLD_Members_HW_Programme::record_barrier(7, '2026-11-19', ['cravings', 'stress'], 'late shifts at work');
    $stored = raw('check_in_barriers');
    $opened = LLD_Members_Stage3_Export::open_sealed(['check_in_barriers' => $stored], 7);
    LLD_Members_Stage3_Erasure::withdraw_health(7);
    return ['r' => $r, 'stored' => $stored, 'opened' => $opened['check_in_barriers'][0], 'after' => count(raw('check_in_barriers')), 'in' => LLD_Members_Stage3_Export::download_suffixes()];
  `);
  expect(out.r.saved).toBe(true);
  expect(JSON.stringify(out.stored)).not.toMatch(/cravings|late shifts/);
  expect(JSON.parse(out.opened.barrier_choices)).toEqual(['cravings', 'stress']);
  expect(out.opened.barrier_text).toBe('late shifts at work');
  expect(out.in).toContain('check_in_barriers');
  expect(out.after).toBe(0);
  expect(src('class-analytics.php')).not.toMatch(/barrier/i);
});

test('TC-FR3-109-AS-09', () => {
  // The sleep and stress answers are in "Download my data", sealed at rest, and gone when consent is withdrawn.
  const out = run(`
    member(7);
    LLD_Members_HW_Answers::set(7, 'sleep', 'under_6');
    LLD_Members_HW_Answers::set(7, 'stress', 8);
    $opened = LLD_Members_Stage3_Export::open_sealed(['profile_answers' => raw('profile_answers')], 7);
    LLD_Members_Stage3_Erasure::withdraw_health(7);
    return ['opened' => array_column($opened['profile_answers'], 'answer', 'question_key'), 'after' => count(raw('profile_answers'))];
  `);
  expect(out.opened).toEqual({ sleep: '"under_6"', stress: '8' });
  expect(out.after).toBe(0);
});

test('P3-35 answers: values checked against the signed choices; skipping is stored as skipped; forget', () => {
  const out = run(`
    member(7);
    $bad = LLD_Members_HW_Answers::set(7, 'sleep', '5_hours');
    $scale = LLD_Members_HW_Answers::set(7, 'stress', 11);
    LLD_Members_HW_Answers::set(7, 'barrier', null);
    LLD_Members_HW_Answers::set(7, 'food_rules', ['halal', 'no_grains_pulses_fasting_days']);
    LLD_Members_HW_Answers::set(7, 'allergies', ['buckwheat']);
    $all = LLD_Members_HW_Answers::details(7);
    $plain = LLD_Members_HW_Answers::all(7);
    $grp = LLD_Members_HW_Answers::skip(7, 'eating_pattern');
    $n = LLD_Members_HW_Answers::forget(7);
    return ['bad' => $bad->get_error_code(), 'scale' => $scale->get_error_code(), 'all' => $all, 'plain' => $plain, 'grp' => $grp, 'n' => $n, 'after' => LLD_Members_HW_Answers::all(7), 'engine' => LLD_Members_HW_Answers::engine(7)];
  `);
  expect(out.bad).toBe('lld_hw_answer');
  expect(out.scale).toBe('lld_hw_answer');
  expect(out.all.barrier).toMatchObject({ value: null, skipped: true });
  expect(out.all.food_rules.value).toEqual(['halal', 'no_grains_pulses_fasting_days']);
  expect(out.all.allergies.value).toEqual(['buckwheat']);
  expect(out.plain).toMatchObject({ barrier: null, food_rules: ['halal', 'no_grains_pulses_fasting_days'], allergies: ['buckwheat'] });
  expect(out.grp).toBe(true); // a quick-question group skips each of its keys (breakfast, breakfast_protein)
  expect(out.n).toBe(3);
  expect(out.after).not.toHaveProperty('barrier');
  expect(out.after.food_rules).toEqual(['halal', 'no_grains_pulses_fasting_days']); // the food preferences keep theirs
  expect(out.engine.food_rules).toEqual(['halal', 'no_grains_pulses_fasting']);
});

test('P3-35 food rules sealed: a fast kept and halal or kosher are never stored in plain text, and are downloaded and erased', () => {
  // Food rules and fasts can show religion (special category data): sealed like every other health value, readable by
  // the engine and the recipe filter, in "Download my data", and gone with the account or health consent.
  const out = run(`
    member(7); member(8);
    LLD_Members_HW_Answers::set(7, 'food_rules', ['kosher']);
    LLD_Members_HW_Answers::set(7, 'allergies', ['sesame']);
    global $wpdb;
    $wpdb->insert('wp_lld_festival_calendar', ['name' => 'Test fast', 'kind' => 'one_day_fast', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'expected' => 0, 'approved_on' => '2026-11-10']);
    LLD_Members_HW_Answers::set(7, 'fasts', ['1']);
    LLD_Members_HW_Answers::set(8, 'food_rules', ['halal']);
    $stored = raw('food_preferences');
    $mine = array_values(array_filter($stored, function ($r) { return 7 === (int) $r['member_id']; }))[0];
    $prefs = new ReflectionMethod('LLD_Members_Recipes', 'prefs_of'); $prefs->setAccessible(true);
    $opened = LLD_Members_Stage3_Export::open_sealed(['food_preferences' => [$mine]], 7);
    $wrong = LLD_Members_Stage3_Data::open(8, $mine['food_rules_ct']);
    $out = ['stored' => $stored, 'engine' => LLD_Members_HW_Answers::engine(7), 'prefs' => $prefs->invoke(null, 7, '2026-11-20'), 'opened' => $opened['food_preferences'][0], 'wrong' => $wrong,
      'in' => in_array('food_preferences', LLD_Members_Stage3_Export::download_suffixes(), true)];
    LLD_Members_Stage3_Erasure::withdraw_health(7);
    LLD_Members_Stage3_Erasure::erase_account(8);
    $out['after'] = count(raw('food_preferences'));
    return $out;
  `);
  for (const r of out.stored) {
    expect(r).not.toHaveProperty('food_rules');
    expect(r.food_rules_ct).toMatch(/^ct:/);
    expect(JSON.stringify(r)).not.toMatch(/kosher|halal|fast:/);
  }
  expect(out.engine.food_rules).toEqual(['kosher']);
  expect(out.engine.fasts).toEqual(['1']);
  expect(out.prefs).toMatchObject({ food_rules: ['kosher', 'fast:1'], allergies: ['sesame'], fasting_day: true });
  expect(JSON.parse(out.opened.food_rules)).toEqual(['kosher', 'fast:1']);
  expect(out.opened).not.toHaveProperty('food_rules_ct');
  expect(out.wrong).toBeNull(); // another member's key never opens it
  expect(out.in).toBe(true);
  expect(out.after).toBe(0);
});
