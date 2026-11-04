// Blood Pressure Reading Check (P3-58, FR3-50; warning levels FR3-38, FR3-46): the engine is run in PHP CLI without
// WordPress against the frozen fixtures and the independent Python oracle; its warning levels are checked against the
// safety matrix's warning_level() (P3-51), and its wording against the signed approval record.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-bp-check.php');
const safety = resolve(plugin, 'includes/stage3/class-safety-stage3.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
// Only the engine is required: it loads the safety matrix itself on first use when run on its own (the tests that
// call the matrix directly load it through the engine first).
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; LLD_Members_BP_Check::signed_text(); ${code}`], { encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const fixtures = readdirSync(resolve(root, 'fixtures/bp-check')).filter((f) => f.endsWith('.json')).sort()
  .flatMap((f) => json(`fixtures/bp-check/${f}`).fixtures);
const approval = json('approvals/stage3/bp-check.json');
const oracle = json('oracles/orc-09-bp-check/results.json');

/** Run compute() over a list of inputs in one PHP call. */
const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_BP_Check::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
const decimals = (n) => (String(n).split('.')[1] || '').length;
/** A recorded number is matched at the precision it was recorded to (the fixtures give 84.714 and 129.14). */
const same = (got, want) => {
  if (typeof want === 'number' && typeof got === 'number') return Number(got.toFixed(decimals(want))) === want;
  if (want && typeof want === 'object' && !Array.isArray(want)) return got && typeof got === 'object' && Object.keys(want).every((k) => same(got[k], want[k])) && Object.keys(got).length === Object.keys(want).length;
  return JSON.stringify(got) === JSON.stringify(want);
};
/** The engine's value for a fixture's expected field. */
const field = (r, k) => (k === 'wording_keys' ? r.messages : k === 'daily_mean_day1' ? (r.daily_means[0] && { systolic: r.daily_means[0].systolic, diastolic: r.daily_means[0].diastolic }) : r[k]);

test('TC-FR3-50-AS-01', () => {
  // Each category boundary gives the approved category in the reference fixtures: the engine against every frozen
  // fixture (BP-01 to BP-34), and against the oracle written independently from the sheet for every fixture. The one
  // value the sheet leaves unstated (BP-32's average, "also shown") is checked as shown, not by value.
  const results = compute(fixtures.map((f) => f.inputs));
  const problems = [];
  fixtures.forEach((f, i) => {
    const r = results[i];
    if (r.error) { problems.push(`${f.id}: error ${r.error}`); return; }
    for (const [k, want] of Object.entries(f.expected)) {
      if (k === 'average' && want === null && f.expected.average_shown) {
        if (!r.average) problems.push(`${f.id}.average: not shown`);
        continue;
      }
      if (!same(field(r, k), want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(field(r, k))} != fixture ${JSON.stringify(want)}`);
    }
    const orc = oracle.results.find((o) => o.fixture_id === f.id);
    if (!orc) { problems.push(`${f.id}: no oracle result`); return; }
    for (const [k, want] of Object.entries(orc.expected)) {
      if (!same(field(r, k), want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(field(r, k))} != oracle ${JSON.stringify(want)}`);
    }
  });
  expect(problems).toEqual([]);
  expect(fixtures).toHaveLength(40); // the 34 signed cases and BP-35 to BP-40 (version 1.1, D3-50)
  expect(oracle.oracle_id).toBe('ORC-09');
  expect(oracle.results.map((o) => o.fixture_id).sort()).toEqual(fixtures.map((f) => f.id).sort());
  const script = readFileSync(resolve(root, 'oracles/orc-09-bp-check/bp_ref.py'));
  expect(oracle.source_hash).toBe(createHash('sha256').update(script).digest('hex'));
  // Boundaries on both numbers, one step either side, give the ISH 2020 category (the higher number decides).
  const cat = JSON.parse(php(`echo json_encode([
    LLD_Members_BP_Check::office_category(129, 84), LLD_Members_BP_Check::office_category(130, 60), LLD_Members_BP_Check::office_category(100, 85),
    LLD_Members_BP_Check::office_category(139, 89), LLD_Members_BP_Check::office_category(140, 70), LLD_Members_BP_Check::office_category(120, 90),
    LLD_Members_BP_Check::office_category(159, 99), LLD_Members_BP_Check::office_category(160, 70), LLD_Members_BP_Check::office_category(120, 100),
    LLD_Members_BP_Check::home_category(134, 84), LLD_Members_BP_Check::home_category(135, 70), LLD_Members_BP_Check::home_category(120, 85),
    LLD_Members_BP_Check::home_category(154, 94), LLD_Members_BP_Check::home_category(155, 70), LLD_Members_BP_Check::home_category(120, 95),
  ]);`));
  expect(cat).toEqual(['normal', 'high-normal', 'high-normal', 'high-normal', 'grade1', 'grade1', 'grade1', 'grade2', 'grade2',
    'within', 'above', 'above', 'above', 'well-above', 'well-above']);
});

test('TC-FR3-50-AS-02', () => {
  // A dangerous reading shows the urgent-care wording, before anything else: a single reading at 180/120 or more,
  // any one reading in the 7 days at that level (whatever the average), and in pregnancy 160/110 or more. The text is
  // the signed alert served by the safety matrix, for every member alike (nothing here asks about Premium).
  const day = (d, s, di) => ({ date: `2026-11-0${d}`, systolic: s, diastolic: di });
  const week = [day(1, 120, 78), day(2, 122, 80), day(3, 186, 100)];
  const [one, home, homeFew, preg, pregHome] = compute([
    { mode: 'single', pregnant: 'no', systolic: 185, diastolic: 125, pulse: 130, age: 85, medicine: 'yes' },
    { mode: '7-day', pregnant: 'no', today: '2026-11-03', readings: week },
    { mode: '7-day', pregnant: 'no', today: '2026-11-03', readings: [day(2, 120, 80), day(3, 181, 95)] },
    { mode: 'single', pregnant: 'notsure', systolic: 158, diastolic: 112 },
    { mode: '7-day', pregnant: 'yes', today: '2026-11-03', readings: [...week.slice(0, 2), day(3, 162, 96)] },
  ]);
  expect(one.messages[0]).toBe('urgent.alert');
  expect(one.messages).toEqual(['urgent.alert', 'category.grade2.headline', 'category.grade2.body', 'pulse.note', 'age80.note', 'medicine.note']);
  expect(one).toMatchObject({ urgent: true, warning_level: 'urgent', category: 'grade2' });
  expect(home).toMatchObject({ urgent: true, warning_level: 'urgent', messages: ['urgent.alert'], average_shown: true, average: { systolic: 143, diastolic: 86 } });
  expect(homeFew).toMatchObject({ urgent: true, messages: ['urgent.alert', 'home.min-days'], average: null });
  expect(preg).toMatchObject({ urgent: true, warning_level: 'pregnancy_urgent', category: null, messages: ['pregnancy.urgent'] });
  expect(pregHome).toMatchObject({ urgent: true, warning_level: 'pregnancy_urgent', category: null, messages: ['pregnancy.urgent'] });
  const W = JSON.parse(php(`echo json_encode([LLD_Members_BP_Check::wording(['urgent.alert', 'pulse.note'], ['pulse_direction' => 'high']), LLD_Members_Stage3_Safety::BP_MESSAGES, LLD_Members_BP_Check::wording(['pulse.note'], ['pulse_direction' => 'low'])]);`));
  expect(W[0]['urgent.alert']).toBe(W[1]['urgent.alert']);
  expect(W[0]['urgent.alert'].split('\n')[0]).toBe('Your reading is very high.');
  expect(W[0]['pulse.note']).toBe(W[1]['pulse.note'].replace('{high|low}', 'high'));
  expect(W[2]['pulse.note']).toBe(W[1]['pulse.note'].replace('{high|low}', 'low'));
  expect(readFileSync(engine, 'utf8')).not.toMatch(/has_premium|is_premium|LLD_Members_Billing|LLD_Members_Access::/);
  // Readings and the pulse are whole numbers, half-up, before any level or category (as the heart risk check):
  // 179.5 is 180 and urgent, 179.4 is not; 89.5/59.5 is 90/60 with no low note; a pulse of 120.5 is 121.
  const r5 = (d, s, di) => ({ date: `2026-11-0${d}`, systolic: s, diastolic: di });
  const [up, down, low, pulseUp, pulseIn, homeUp] = compute([
    { mode: 'single', systolic: 179.5, diastolic: 100 }, { mode: 'single', systolic: 179.4, diastolic: 119.4 },
    { mode: 'single', systolic: 89.5, diastolic: 59.5 }, { mode: 'single', systolic: 120, diastolic: 80, pulse: 120.5 },
    { mode: 'single', systolic: 120, diastolic: 80, pulse: 120.4 },
    { mode: '7-day', today: '2026-11-03', readings: [r5(1, 120, 80), r5(2, 120, 80), r5(3, 179.5, 80)] },
  ]);
  expect(up).toMatchObject({ systolic: 180, diastolic: 100, urgent: true, warning_level: 'urgent' });
  expect(down).toMatchObject({ systolic: 179, diastolic: 119, urgent: false, category: 'grade2' });
  expect(low).toMatchObject({ systolic: 90, diastolic: 60, warning_level: 'none', messages: ['category.normal.headline', 'category.normal.body'] });
  expect(pulseUp).toMatchObject({ pulse: 121, warning_level: 'pulse_high' });
  expect(pulseIn).toMatchObject({ pulse: 120, warning_level: 'none' });
  expect(homeUp).toMatchObject({ urgent: true, messages: ['urgent.alert'] });
  expect(homeUp.used[2].systolic).toBe(180);
  // Yes/no answers read the same in every spelling, and "no" or "false" never counts as yes; anything else is refused.
  const base = { mode: 'single', systolic: 85, diastolic: 55 };
  const yesNo = compute([
    ...[true, 'yes', 'true', 'TRUE', 1, '1'].map((v) => ({ ...base, dizzy: v, medicine: v })),
    ...[false, 'no', 'false', 'FALSE', 0, '0', null, ''].map((v) => ({ ...base, dizzy: v, medicine: v })),
    ...[true, 'yes', 'true', 'not sure', 'notsure'].map((v) => ({ ...base, pregnant: v })),
    ...[false, 'no', 'false', null].map((v) => ({ ...base, pregnant: v })),
    { ...base, dizzy: 'maybe' }, { ...base, medicine: 'sometimes' }, { ...base, pregnant: 'perhaps' }, { ...base, dizzy: [] },
  ]);
  for (const r of yesNo.slice(0, 6)) expect(r.messages).toEqual(['category.normal-low.headline', 'category.normal-low.body', 'low.dizzy', 'medicine.note']);
  for (const r of yesNo.slice(6, 14)) expect(r.messages).toEqual(['category.normal-low.headline', 'category.normal-low.body']);
  for (const r of yesNo.slice(14, 19)) expect(r).toMatchObject({ category: null, messages: ['pregnancy.neutral'] });
  for (const r of yesNo.slice(19, 23)) expect(r.category).toBe('normal');
  expect(yesNo.slice(23).map((r) => r.error)).toEqual(['dizzy', 'medicine', 'pregnant', 'dizzy']);
  const prof = JSON.parse(php(`echo json_encode([LLD_Members_BP_Check::apply_profile(['pregnant' => false], ['pregnancy']), LLD_Members_BP_Check::apply_profile(['pregnant' => 'not sure'], ['pregnancy'])]);`));
  expect(prof).toEqual([{ pregnant: 'yes' }, { pregnant: 'not sure' }]);
});

test('TC-FR3-50-AS-03', () => {
  // The 7-day average uses the member's logged readings: only the 7 calendar days ending today, daily means first (a
  // day with several readings counts once), at least 3 days, rounded half-up, with the readings it used listed; a
  // reading that isn't possible is left out. The member's profile puts the check in pregnancy mode.
  const r = (date, s, d) => ({ date, systolic: s, diastolic: d });
  const [win, few, odd, bad] = compute([
    { mode: '7-day', pregnant: 'no', today: '2026-11-03', readings: [r('2026-10-27', 170, 105), r('2026-10-28', 130, 80), r('2026-10-28', 140, 90), r('2026-10-30', 120, 76), r('2026-11-03', 124, 78), r('2026-11-04', 200, 110)] },
    { mode: '7-day', pregnant: 'no', today: '2026-11-03', readings: [r('2026-10-20', 130, 80), r('2026-11-01', 130, 80), r('2026-11-02', 130, 80)] },
    { mode: '7-day', pregnant: 'no', today: '2026-11-03', readings: [r('2026-11-01', 400, 20), r('2026-11-01', 120, 80), r('2026-11-02', 122, 80), r('2026-11-03', 124, 80)] },
    { mode: '7-day', pregnant: 'no', today: '2026-11-03', readings: [{ date: 'yesterday', systolic: 120, diastolic: 80 }] },
  ]);
  // 26 Oct and 4 Nov are outside; 28 Oct averages to 135/85 first; (135 + 120 + 124) / 3 = 126.33, (85 + 76 + 78) / 3 = 79.67.
  expect(win).toMatchObject({ days: 3, average: { systolic: 126, diastolic: 80 }, category: 'within', urgent: false, messages: ['home.within.headline', 'home.within.body'] });
  expect(win.used.map((u) => u.date)).toEqual(['2026-10-28', '2026-10-28', '2026-10-30', '2026-11-03']);
  expect(win.daily_means[0]).toEqual({ date: '2026-10-28', systolic: 135, diastolic: 85, readings: 2 });
  expect(few).toMatchObject({ days: 2, average: null, category: null, messages: ['home.min-days'] });
  expect(odd).toMatchObject({ days: 3, skipped: 1, average: { systolic: 122, diastolic: 80 } });
  expect(bad.error).toBe('readings');
  const p = JSON.parse(php(`echo json_encode([
    LLD_Members_BP_Check::apply_profile(['pregnant' => 'no'], ['pregnancy']),
    LLD_Members_BP_Check::apply_profile(['pregnant' => 'notsure'], ['pregnancy', 'age_65_plus']),
    LLD_Members_BP_Check::apply_profile(['pregnant' => 'no'], ['age_65_plus']),
    LLD_Members_BP_Check::compute(LLD_Members_BP_Check::apply_profile(['mode' => 'single', 'pregnant' => 'no', 'systolic' => 141, 'diastolic' => 91], ['pregnancy']))['messages'],
  ]);`));
  expect(p).toEqual([{ pregnant: 'yes' }, { pregnant: 'notsure' }, { pregnant: 'no' }, ['pregnancy.same-day']]);
  // The members' route runs the engine through apply_profile(), compute() and wording() (P3-57 to P3-5A route).
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  expect(api).toMatch(/'bp-check'\s+=> 'LLD_Members_BP_Check'/);
  // The engine accepts at most MAX_READINGS (200) readings, so a direct call can't send a huge array.
  const many = (n) => Array.from({ length: n }, (_, k) => r(`2026-11-0${1 + (k % 3)}`, 120, 80));
  const [cap, over] = compute([{ mode: '7-day', today: '2026-11-03', readings: many(200) }, { mode: '7-day', today: '2026-11-03', readings: many(201) }]);
  expect(cap).toMatchObject({ days: 3, average: { systolic: 120, diastolic: 80 } });
  expect(over.error).toBe('readings');
  // The members' route reads the member's own readings and ignores what the page sent: calculate_engine() and its
  // helper run in PHP CLI with the database, clock, zone, consent and seal stubbed (the real query runs on a site).
  const route = (zone, consent) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', '-r', `
    define('ABSPATH', '/'); define('ARRAY_A', 'ARRAY_A');
    class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
    function wp_timezone() { return new DateTimeZone('Europe/London'); }
    class LLD_Members_Programme { static function timezone($m) { return ${JSON.stringify(zone)}; } }
    class LLD_Members_Clock { static function now($m = 0) { return new DateTimeImmutable('2026-11-04 03:30:00', new DateTimeZone('UTC')); } }
    class LLD_Members_Access { static function has_health_consent($m) { return ${consent ? 'true' : 'false'}; } }
    class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld3_' . $s; } }
    class LLD_Members_Stage3_Data { static function open($m, $t) { return is_string($t) && 0 === strpos($t, "m$m:") ? substr($t, strlen("m$m:")) : null; } }
    class Fake_Wpdb { public $args = null; public $sql = '';
      function prepare($sql, ...$a) { $this->sql = $sql; $this->args = $a; return $sql; }
      function get_results($q, $o) { return [
        ['taken_at' => '2026-11-04 02:00:00', 'value_1_ct' => 'm7:150.4', 'value_2_ct' => 'm7:95'],
        ['taken_at' => '2026-11-01 12:00:00', 'value_1_ct' => 'm7:140', 'value_2_ct' => 'm7:90'],
        ['taken_at' => '2026-10-28 05:00:00', 'value_1_ct' => 'm7:130', 'value_2_ct' => 'm7:85'],
        ['taken_at' => '2026-11-02 12:00:00', 'value_1_ct' => 'm8:200', 'value_2_ct' => 'm8:130'],
      ]; } }
    $wpdb = new Fake_Wpdb();
    require ${toPhpPath(resolve(plugin, 'includes/stage3/class-api.php'))};
    require ${toPhpPath(engine)};
    $run = new ReflectionMethod('LLD_Members_Stage3_API', 'calculate_engine'); $run->setAccessible(true);
    $res = $run->invoke(null, 'LLD_Members_BP_Check', ['mode' => '7-day', 'today' => '2030-01-01', 'readings' => array_fill(0, 5, ['date' => '2029-12-31', 'systolic' => 190, 'diastolic' => 125])], 7);
    echo json_encode(['status' => $res->status, 'data' => $res->data, 'sql' => $wpdb->sql, 'args' => $wpdb->args]);
  `], { encoding: 'utf8', timeout: 120000 }));
  const ny = route('America/New_York', true);
  // 03:30 UTC on 4 Nov is 22:30 on 3 Nov in New York (EST): the 7 days run from 28 Oct (EDT, 04:00 UTC) to 3 Nov.
  expect(ny.status).toBe(200);
  expect(ny.args).toEqual([7, 'blood_pressure', '2026-10-28 04:00:00', '2026-11-04 05:00:00', 200]);
  expect(ny.sql).toMatch(/reading_type = %s AND deleted_at IS NULL AND taken_at >= %s AND taken_at < %s ORDER BY taken_at DESC, id DESC LIMIT %d/);
  expect(ny.data.used.map((u) => u.date)).toEqual(['2026-11-03', '2026-11-01', '2026-10-28']); // another member's seal does not open
  expect(ny.data).toMatchObject({ days: 3, urgent: false, average: { systolic: 140, diastolic: 90 }, category: 'above' });
  expect(ny.data.wording['home.above.headline']).toBe('Above the home threshold');
  // No zone in force: the site's zone (Europe/London, already 4 Nov). No health consent: nothing is read.
  const site = route('', true);
  expect(site.args.slice(2, 4)).toEqual(['2026-10-29 00:00:00', '2026-11-05 00:00:00']);
  const none = route('Europe/London', false);
  expect(none.sql).toBe('');
  expect(none.data).toMatchObject({ days: 0, average: null, messages: ['home.min-days'] });
});

test('TC-FR3-38-AS-04', () => {
  // A reading that isn't possible (400/20; the bottom number not 10 below the top; out of range) is refused with the
  // message to check the numbers, and no category. A missing number is a form error. Since version 1.1 (D3-50) a
  // pulse outside 30-220 no longer refuses the reading: TC-FR3-38-AS-04 (pulse left out) below.
  const out = compute([
    { mode: 'single', systolic: 400, diastolic: 20 }, { mode: 'single', systolic: 130, diastolic: 125 },
    { mode: 'single', systolic: 59, diastolic: 40 }, { mode: 'single', systolic: 140, diastolic: 161 },
    { mode: 'single', systolic: 400, diastolic: 20, pulse: 230 }, { mode: 'single', systolic: 130, diastolic: 125, pulse: 29 },
    { mode: 'single', systolic: 400, diastolic: 20, pregnant: 'yes' },
    { mode: 'single', diastolic: 80 }, { mode: 'single', systolic: 120, diastolic: 'x' }, { mode: 'weekly', systolic: 120, diastolic: 80 },
    { mode: 'single', systolic: 120, diastolic: 80, pregnant: 'maybe' }, { mode: 'single', systolic: 120, diastolic: 80, age: 17 },
  ]);
  for (const r of out.slice(0, 7)) expect(r).toMatchObject({ refused: true, category: null, urgent: false, warning_level: 'refused', messages: ['refused.impossible'] });
  expect(out.slice(7).map((r) => r.error)).toEqual(['systolic', 'diastolic', 'mode', 'pregnant', 'age']);
  const text = JSON.parse(php(`echo json_encode(LLD_Members_BP_Check::wording(['refused.impossible']));`));
  expect(text['refused.impossible']).toBe("Please check the numbers: that reading isn't possible. The top number is usually 30 to 60 higher than the bottom one.");
});

test('TC-FR3-38-AS-04 (pulse left out, D3-50)', () => {
  // Version 1.1: a pulse outside 30-220 never refuses the reading. The two numbers are checked as usual (the urgent
  // alert first), the pulse is left out (no note, not kept), and the pulse line comes after the result with the number typed.
  const out = compute([
    { mode: 'single', systolic: 120, diastolic: 80, pulse: 230 }, { mode: 'single', systolic: 120, diastolic: 80, pulse: 29 },
    { mode: 'single', systolic: 190, diastolic: 125, pulse: 225 }, { mode: 'single', systolic: 128, diastolic: 82, pulse: 220 },
  ]);
  for (const r of out.slice(0, 3)) {
    expect(r).toMatchObject({ refused: false, pulse: null, pulse_left_out: true, pulse_direction: null });
    expect(r.messages.at(-1)).toBe('pulse.left-out');
    expect(r.messages).not.toContain('pulse.note');
  }
  expect(out[0]).toMatchObject({ category: 'normal', pulse_typed: '230' });
  expect(out[1].pulse_typed).toBe('29');
  expect(out[2]).toMatchObject({ urgent: true, warning_level: 'urgent' });
  expect(out[2].messages[0]).toBe('urgent.alert');
  expect(out[3]).toMatchObject({ pulse: 220, pulse_left_out: false, pulse_direction: 'high' }); // 220 is still possible
  const text = JSON.parse(php(`echo json_encode(LLD_Members_BP_Check::wording(['pulse.left-out'], ['pulse_typed' => '230']));`));
  expect(text['pulse.left-out']).toBe("We left out the pulse: 230 isn't possible for a resting pulse. Please check it and add it again.");
});

test('TC-FR3-46-AS-01', () => {
  // The calculator gives the same warning as the logging screen: over a sweep of readings, pulses, pregnancy and the
  // dizzy tick, every single-reading result carries warning_level()'s own answer, refuses exactly what it refuses,
  // is urgent exactly when it is, shows its wording key (urgent first) and names the same level. 185/125 is urgent.
  const out = JSON.parse(php(`
    $labels = ['bp-check.urgent.alert' => 'urgent', 'bp-check.pregnancy.urgent' => 'pregnancy_urgent', 'bp-check.pregnancy.same-day' => 'pregnancy_same_day',
      'bp-check.low.dizzy' => 'low_dizzy', 'bp-check.category.normal-low.body' => 'low_note', 'bp-check.refused.impossible' => 'refused'];
    $bad = []; $n = 0;
    $sys = array_merge(range(50, 270, 10), [59, 60, 89, 90, 129, 130, 139, 140, 159, 160, 179, 180, 260, 261]);
    $dia = array_merge(range(20, 170, 10), [29, 30, 59, 60, 84, 85, 89, 90, 99, 100, 109, 110, 119, 120, 160, 161]);
    foreach ($sys as $s) foreach ($dia as $d) foreach ([null, 29, 39, 40, 70, 120, 121, 221] as $p) foreach (['no', 'yes', 'notsure'] as $preg) foreach ([false, true] as $dizzy) {
      $in = ['mode' => 'single', 'systolic' => $s, 'diastolic' => $d, 'pregnant' => $preg, 'dizzy' => $dizzy];
      $v = ['sys' => $s, 'dia' => $d];
      if ($p !== null) { $in['pulse'] = $p; $v['pulse'] = $p; }
      $w = LLD_Members_Stage3_Safety::warning_level('blood_pressure', $v, ['pregnancy' => $preg !== 'no', 'dizzy' => $dizzy]);
      $r = LLD_Members_BP_Check::compute($in);
      $n++;
      $id = "$s/$d p$p $preg" . ($dizzy ? ' dizzy' : '');
      if ($r['safety'] !== $w) $bad[] = "$id: safety result differs";
      if ($r['refused'] !== ('impossible' === $w['level'])) $bad[] = "$id: refused";
      if ($r['urgent'] !== ('urgent' === $w['level'])) $bad[] = "$id: urgent";
      if ($w['key'] !== null) {
        $key = substr($w['key'], 9);
        if (!in_array($key, $r['messages'], true)) $bad[] = "$id: $key not shown";
        if ('urgent' === $w['level'] && $r['messages'][0] !== $key) $bad[] = "$id: urgent not first";
        $want = 'bp-check.pulse.note' === $w['key'] ? 'pulse_' . ($p > 120 ? 'high' : 'low') : $labels[$w['key']];
        if ($r['warning_level'] !== $want) $bad[] = "$id: level {$r['warning_level']} != $want";
        $text = LLD_Members_BP_Check::wording([$key], $r)[$key];
        if ($text !== $w['message']) $bad[] = "$id: wording differs";
      } elseif (!in_array($r['warning_level'], ['none', 'pregnancy_neutral'], true)) $bad[] = "$id: level {$r['warning_level']} with no warning";
      if ($preg !== 'no' && !$r['refused'] && $r['category'] !== null) $bad[] = "$id: category in pregnancy";
    }
    echo json_encode(['n' => $n, 'bad' => array_slice($bad, 0, 20), 'count' => count($bad)]);
  `));
  expect(out.n).toBeGreaterThan(30000);
  expect(out.bad).toEqual([]);
  const [urgent, normal] = compute([{ mode: 'single', pregnant: 'no', systolic: 185, diastolic: 125 }, { mode: 'single', pregnant: 'no', systolic: 118, diastolic: 76 }]);
  expect(urgent.messages[0]).toBe('urgent.alert');
  expect(normal).toMatchObject({ warning_level: 'none', urgent: false, messages: ['category.normal.headline', 'category.normal.body'] });
  // One copy of the levels: the engine holds no urgent, pregnancy, low or pulse threshold of its own.
  const src = readFileSync(engine, 'utf8');
  expect(src).toMatch(/LLD_Members_Stage3_Safety/);
  expect(src).not.toMatch(/\b(180|120|110|40|30|220|260)\b/);
});

test('TC-FR3-46-AS-03', () => {
  // The wording shown matches the frozen approved text exactly: every key in the approval record hashes to the text
  // the calculator serves, the calculator's own lines here and the warning-level lines from the safety matrix, never
  // re-typed in the engine. The record is signed.
  const T = JSON.parse(php('echo json_encode(LLD_Members_BP_Check::signed_text());'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = T[key] === undefined ? '(missing)' : createHash('sha256').update(T[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(T).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  const own = JSON.parse(php('echo json_encode(array_keys(LLD_Members_BP_Check::MESSAGES));'));
  const matrix = JSON.parse(php('echo json_encode(array_keys(LLD_Members_Stage3_Safety::BP_MESSAGES));'));
  expect(own.filter((k) => matrix.includes(k))).toEqual([]);
  const src = readFileSync(engine, 'utf8');
  for (const k of matrix) expect(src, k).not.toContain(T[k].slice(0, 30));
  // Every wording key any fixture result shows is signed.
  for (const r of compute(fixtures.map((f) => f.inputs))) for (const k of r.messages) expect(T[k], k).toBeTypeOf('string');
  expect(approval.tool_slug).toBe('bp-check');
  expect(approval.clinical_signoff.decision).toBe('approved');
  expect(approval.oracle_report).toBe('oracles/orc-09-bp-check/results.json');
  // The engine is pure: no hooks, no database, no options; the safety file it reads is loaded standalone.
  expect(src).not.toMatch(/add_action|add_filter|\$wpdb|get_option|wp_remote/);
  expect(readFileSync(safety, 'utf8')).toMatch(/const BP_MESSAGES/);
});
