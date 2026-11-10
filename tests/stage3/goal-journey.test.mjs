// Goal journey (P3-31 to P3-33; FR3-09 to FR3-16, FR3-18): the pure rules are run in PHP CLI without WordPress
// against the owner-signed Goal Weight Timeline sheet (04 Health Tools/Stage 3/P3-56, 3 Nov 2026) and the Stage 3
// safety matrix; the screens and the data class are checked at source for the Stage 3 rules (switch first, matrix
// second, no Stage 2 edits, no requirement IDs shown to members). The live journeys are tests/stage3/e2e/screens/.
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = resolve(plugin, 'includes/stage3');
const p = (x) => JSON.stringify(x.split('\\').join('/'));
const preamble = `define('ABSPATH', '/'); require ${p(resolve(stage3, 'class-goal-rules.php'))};`;
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${preamble} ${code}`], { encoding: 'utf8', timeout: 120000, maxBuffer: 16 * 1024 * 1024 }).trim();
const call = (method, ...args) => JSON.parse(php(`echo json_encode(LLD_Members_Goal_Rules::${method}(...json_decode(${JSON.stringify(JSON.stringify(args))}, true)));`));
const src = (rel) => readFileSync(resolve(plugin, rel), 'utf8');
const ID = /\b(?:TC-)?N?FR\d-\d\d(?:-AS-\d\d)?\b/;

const D = '2026-11-16'; // a Monday
const day = (n) => { const d = new Date(Date.UTC(2026, 10, 16)); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const check = (input) => call('weight_check', { today: D, situations: [], ...input });

// ---- The signed fixture table (sheet section 6) ------------------------------------------------------------------

const TABLE = [
  ['TL-01', { current_kg: 90, goal_kg: 84, height_cm: 175, rate: 0.5 }, { ok: true, weeks: 12, goal_bmi: 27.4, cap: 0.9, milestone5: 85.5, deficit: 550 }],
  ['TL-02', { current_kg: 90, goal_kg: 84, height_cm: 175, rate: 1.0 }, { ok: false, code: 'rate_cap', offered: { rate: 0.75, weeks: 8 } }],
  ['TL-03', { current_kg: 100, goal_kg: 80, height_cm: 170, rate: 0.75 }, { ok: true, weeks: 27, goal_bmi: 27.7, cap: 1, milestone5: 95, deficit: 825, note: 'over_10' }],
  ['TL-04', { current_kg: 60, goal_kg: 52, height_cm: 165, rate: 0.5 }, { ok: true, weeks: 16, goal_bmi: 19.1, cap: 0.6, milestone5: 57, deficit: 550, note: 'over_10' }],
  ['TL-05', { current_kg: 70, goal_kg: 50, height_cm: 170, rate: 0.5 }, { ok: false, code: 'bmi_floor', offered: { goal_kg: 53.5 } }],
  ['TL-06', { current_kg: 58, goal_kg: 55, height_cm: 170, rate: 1.0 }, { ok: false, code: 'rate_cap', offered: { rate: 0.5, weeks: 6 } }],
  ['TL-07', { current_kg: 120, goal_kg: 100, height_cm: 180, rate: 1.0 }, { ok: true, weeks: 20, goal_bmi: 30.9, cap: 1, milestone5: 114, deficit: 1100, note: 'over_10' }],
  ['TL-08', { current_kg: 80, goal_kg: 79.6, height_cm: 175, rate: 0.5 }, { ok: true, weeks: 1, goal_bmi: 26, cap: 0.8, milestone5: 76, deficit: 550 }],
  ['TL-09', { current_kg: 65, goal_kg: 60, height_cm: 165, rate: 0.25 }, { ok: true, weeks: 20, goal_bmi: 22, cap: 0.65, milestone5: 61.8, deficit: 275 }],
  ['TL-10', { current_kg: 64.0, goal_kg: 53.5, height_cm: 170, rate: 0.5 }, { ok: true, weeks: 21, goal_bmi: 18.5, cap: 0.64, milestone5: 60.8, deficit: 550 }],
  ['TL-11', { current_kg: 90, goal_kg: 84, height_cm: 175, target_date: day(56) }, { ok: true, weeks: 8, rate: 0.75, deficit: 825 }],
  ['TL-12', { current_kg: 90, goal_kg: 84, height_cm: 175, target_date: day(84) }, { ok: true, weeks: 12, rate: 0.5, deficit: 550 }],
  ['TL-13', { current_kg: 90, goal_kg: 84, height_cm: 175, target_date: day(28) }, { ok: false, code: 'rate_cap', offered: { weeks: 7, date: day(49) } }],
  ['TL-14', { current_kg: 90, goal_kg: 90, height_cm: 175 }, { ok: true, maintain: true }],
  ['TL-15', { current_kg: 50, goal_kg: 48, height_cm: 170, rate: 0.5 }, { ok: false, code: 'underweight' }],
  ['TL-16', { current_kg: 90, goal_kg: 84, height_cm: 175, rate: 0.5, situations: ['pregnancy'] }, { ok: false, code: 'pregnancy' }],
  ['TL-17', { current_kg: 90, goal_kg: 84, height_cm: 175, rate: 0.75, situations: ['age_65_plus'] }, { ok: false, code: 'rate_cap', offered: { rate: 0.5, weeks: 12 } }],
  ['TL-18', { current_kg: 90, goal_kg: 84, height_cm: 175, rate: 0.5, situations: ['eating_disorder'] }, { ok: false, code: 'eating_disorder' }],
];

test('goal rules: every row of the signed Goal Weight Timeline fixture table (P3-56, 3 Nov 2026)', () => {
  for (const [id, input, want] of TABLE) {
    const got = check(input);
    expect(got.ok, id).toBe(want.ok);
    for (const key of ['code', 'weeks', 'goal_bmi', 'cap', 'milestone5', 'deficit', 'rate', 'maintain']) {
      if (key in want) expect(got[key], `${id} ${key}`).toBe(want[key]);
    }
    if (want.offered) expect(got.offered, `${id} offered`).toMatchObject(want.offered);
    if (want.note) expect(got.notes, `${id} note`).toContain(want.note);
    if (want.ok && !want.maintain) expect(got.target_date, `${id} date`).toBe(input.target_date || day(7 * got.weeks));
  }
});

test('TC-FR3-09-AS-05', () => {
  // A weight goal below a healthy BMI is refused with a gentle message and a safe suggestion.
  const low = check({ current_kg: 70, goal_kg: 50, height_cm: 170, rate: 0.5 });
  expect(low.ok).toBe(false);
  expect(low.code).toBe('bmi_floor');
  expect(low.message).toBe('A goal of 50 kg would put you below the healthy range for your height (BMI 17.3). The lowest weight in the healthy range for you is about 53.5 kg. Would you like to use that?');
  // An imperial member reads the same refusal in pounds; the limits themselves stay in kg.
  expect(check({ current_kg: 70, goal_kg: 50, height_cm: 170, rate: 0.5, imperial: true }).message).toBe('A goal of 110.2 lb would put you below the healthy range for your height (BMI 17.3). The lowest weight in the healthy range for you is about 117.9 lb. Would you like to use that?');
  expect(low.offered).toEqual({ goal_kg: 53.5 });
  // Faster than the safe rate is refused with the earliest safe date.
  const fast = check({ current_kg: 90, goal_kg: 84, height_cm: 175, rate: 1.0 });
  expect(fast.ok).toBe(false);
  expect(fast.code).toBe('rate_cap');
  expect(fast.message).toMatch(/^That's faster than the safe rate of about 0\.9 kg a week\. At a safe rate you'd reach 84 kg by about Monday 11 January 2027\. Shall we use that date\?$/);
  expect(fast.offered).toMatchObject({ rate: 0.75, weeks: 8, date: day(56) });
  // The accepted goal's date is never earlier than the safe-rate date (data model: Goal.target_date).
  const ok = check({ current_kg: 90, goal_kg: 84, height_cm: 175, rate: 0.5 });
  expect(ok.target_date >= day(7 * 7)).toBe(true);
  // A date keeps the member's own day: 44 days is 7 weeks on the sheet (6 ÷ 7 = 0.86, under the cap), and the date stands.
  const chosen = check({ current_kg: 90, goal_kg: 84, height_cm: 175, target_date: day(44) });
  expect(chosen).toMatchObject({ ok: true, weeks: 7, rate: 0.86, target_date: day(44) });
  expect(check({ current_kg: 90, goal_kg: 84, height_cm: 175, target_date: day(0) })).toMatchObject({ ok: false, code: 'invalid' });
});

test('TC-FR3-09-AS-04', () => {
  // 'Help me choose' suggests a goal from the member's answers, never one the matrix refuses.
  expect(call('suggest', { situations: ['blood_pressure'], bmi: 31 })).toMatchObject({ type: 'lower_blood_pressure' });
  expect(call('suggest', { situations: ['diabetes'], bmi: 24 })).toMatchObject({ type: 'blood_sugar' });
  expect(call('suggest', { situations: [], bmi: 28 })).toMatchObject({ type: 'lose_weight' });
  expect(call('suggest', { situations: ['eating_disorder'], bmi: 28 }).type).not.toBe('lose_weight');
  expect(call('suggest', { situations: [], bmi: 22, stage2_goals: ['sleep-stress'] })).toMatchObject({ type: 'sleep' });
  expect(call('suggest', { situations: [], bmi: 22 })).toMatchObject({ type: 'activity' });
  expect(call('suggest', { situations: ['unknown'] })).toBeNull();
  for (const s of [[], ['diabetes'], ['pregnancy'], ['age_65_plus']]) expect(call('suggest', { situations: s, bmi: 27 }).reason).not.toMatch(ID);
});

test('TC-FR3-09-AS-07', () => {
  // The safety wording comes before the goal types, and the matrix's refusals hold (P3-03 Part B, goals column).
  expect(call('safety_notes', ['pregnancy'])).toEqual(['midwife_note', 'bp_goal_midwife_sets_this']);
  expect(call('safety_notes', ['eating_disorder'])).toEqual(['ed.weight-goal-screen']);
  expect(call('safety_notes', ['diabetes'])).toEqual(['check_with_your_doctor', 'medicine_note']);
  expect(call('safety_notes', ['heart_rate_medicine', 'heart_disease'])).toEqual(['activity_goals_by_minutes', 'ask_cardiac_rehab_team']);
  expect(Object.keys(call('allowed_types', ['pregnancy']))).not.toContain('lose_weight');
  expect(Object.keys(call('allowed_types', ['eating_disorder']))).toEqual(['lower_blood_pressure', 'blood_sugar', 'activity', 'strength', 'sleep']);
  expect(Object.keys(call('allowed_types', ['bmi_under_18_5']))).not.toContain('lose_weight');
  expect(Object.keys(call('allowed_types', ['unknown']))).toEqual([]);
  expect(Object.keys(call('allowed_types', []))).toEqual(['lose_weight', 'keep_weight', 'lower_blood_pressure', 'blood_sugar', 'activity', 'strength', 'sleep']);
  // The screen prints the notes before the chooser form.
  const screen = src('includes/stage3/screens/class-goal-setting.php');
  expect(screen.indexOf('self::safety_html( $facts )')).toBeLessThan(screen.indexOf('<fieldset class="lld-options"><legend>What do you want to work on?'));
});

test('TC-FR3-13-AS-01', () => {
  // Start 90, goal 84, now 86.8: "3.2 of 6 kg, 53 %" (sheet fixture); never negative; 100 at the goal.
  expect(call('progress', 90, 84, 86.8)).toEqual({ done: 3.2, total: 6, pct: 53, remaining: 2.8 });
  expect(call('progress', 90, 84, 90.4)).toEqual({ done: 0, total: 6, pct: 0, remaining: 6 });
  expect(call('progress', 90, 84, 83.5)).toEqual({ done: 6, total: 6, pct: 100, remaining: 0 });
  expect(call('progress', 60, 150, 105, 'up')).toEqual({ done: 45, total: 90, pct: 50, remaining: 45 });
  expect(call('progress', null, 84, 86.8)).toBeNull();
});

test('TC-FR3-13-AS-02', () => {
  // A trend that reaches the goal by the date (plus 7 days) is On track; one that will not is Behind.
  const points = {};
  for (let n = -27; n <= 0; n += 7) points[day(n)] = 90 - (n + 27) / 7 * 0.5; // 0.5 kg a week, now 88.5
  expect(call('on_track', points, 84, day(70), D, 'down').status).toBe('on_track'); // 9 weeks needed, 10 allowed
  expect(call('on_track', points, 84, day(49), D, 'down').status).toBe('behind'); // 9 weeks needed, 7 allowed
  expect(call('on_track', points, 84, day(56), D, 'down').status).toBe('on_track'); // the 7-day grace
  // Fewer than 3 weigh-ins: no verdict yet, with the sheet's waiting line on the screen.
  const two = { [day(-7)]: 90, [D]: 89.5 };
  expect(call('on_track', two, 84, day(70), D, 'down')).toMatchObject({ status: 'waiting', points: 2 });
  expect(src('includes/stage3/screens/class-today-screen.php')).toContain("MESSAGES['trend_wait']");
  // A flat or rising trend is Behind; at the goal it is reached.
  const flat = { [day(-14)]: 90, [day(-7)]: 90, [D]: 90.1 };
  expect(call('on_track', flat, 84, day(70), D, 'down').status).toBe('behind');
  const there = { [day(-14)]: 85, [day(-7)]: 84.5, [D]: 84 };
  expect(call('on_track', there, 84, day(70), D, 'down').status).toBe('reached');
});

test('TC-FR3-14-AS-02', () => {
  // An adjusted plan never raises the rate above the cap, never moves the date earlier than a safe one.
  expect(call('later_date', 88, 84, 0.5, 0.9, D)).toEqual({ rate: 0.5, weeks: 8, date: day(56) });
  expect(call('later_date', 88, 84, 1.5, 0.88, D)).toEqual({ rate: 0.88, weeks: 5, date: day(35) });
  expect(call('later_date', 84, 84, 0.5, 0.9, D).weeks).toBe(1);
  // The easier activity offer: three quarters, never under 60 minutes.
  expect(call('easier_activity', 150)).toBe(113);
  expect(call('easier_activity', 60)).toBe(60);
  // The calorie floor holds on every plan version (the data class clamps to the signed floor).
  const journey = src('includes/stage3/class-goal-journey.php');
  expect(journey).toMatch(/max\( \(int\) \$copy\['calorie_target'\], \(int\) LLD_Members_Calorie_Target::floor_for/);
  expect(journey).toMatch(/if \( empty\( \$facts\['premium'\] \) \) \{\s*return new WP_Error\( 'lld_premium'/);
});

test('TC-FR3-15-AS-01', () => {
  // Goal reached on the 7-day mean (D3-09), not on a single dip.
  const dip = { [day(-3)]: 85, [day(-2)]: 84.8, [day(-1)]: 85.1, [D]: 83.9 };
  expect(call('reached', dip, 84, D, 'down')).toBe(false);
  const held = { [day(-6)]: 84.1, [day(-4)]: 84, [day(-2)]: 83.9, [D]: 84 };
  expect(call('reached', held, 84, D, 'down')).toBe(true);
  expect(call('reached', { [day(-10)]: 83 }, 84, D, 'down')).toBe(false); // outside the window
  expect(call('reached', { [D]: 150 }, 150, D, 'up')).toBe(true);
});

test('TC-FR3-10-AS-03', () => {
  // Owner decision Q79 = B (3 Nov 2026): a blood pressure goal holds both targets and is reached only when the 7-day
  // means of both numbers are at or below them. Daily values are [top, bottom], as the members' readings give them.
  const week = (top, bottom) => Object.fromEntries([-6, -4, -2, 0].map((n) => [day(n), [top, bottom]]));
  expect(call('bp_reached', week(128, 82), 135, 85, D)).toBe(true);
  expect(call('bp_reached', week(128, 88), 135, 85, D)).toBe(false); // top on target, bottom not
  expect(call('bp_reached', week(138, 80), 135, 85, D)).toBe(false); // bottom on target, top not
  expect(call('bp_reached', week(129, 79), 130, 80, D)).toBe(true); // the doctor's 130/80
  expect(call('bp_reached', week(129, 81), 130, 80, D)).toBe(false);
  expect(call('bp_reached', week(135, 85), 135, 85, D)).toBe(true); // "at or below"
  expect(call('bp_reached', week(128, 84), 135, null, D)).toBe(true); // an older goal without a bottom target: 85
  expect(call('bp_reached', week(128, 86), 135, null, D)).toBe(false);
  expect(call('bp_reached', { [day(-10)]: [120, 70] }, 135, 85, D)).toBe(false); // outside the window
  expect(call('bp_reached', { [D]: 128 }, 135, 85, D)).toBe(false); // no bottom number: not yet reached
  // The goal stores both targets, sealed, and the goal-reached check uses both.
  const journey = src('includes/stage3/class-goal-journey.php');
  expect(journey).toMatch(/const SEALED_GOAL\s*= array\( 'start_value', 'target_value', 'start_value_2', 'target_value_2' \)/);
  expect(journey).toMatch(/\$row\['target_value_2'\] = \$dia;/);
  expect(journey).toMatch(/\$dia = \(float\) LLD_Members_Goal_Rules::BP_DEFAULT\[1\];/);
  expect(journey).toMatch(/LLD_Members_Goal_Rules::bp_reached\( \$points, \(float\) \$goal\['target_value'\]/);
  const api = src('includes/stage3/class-api.php');
  expect(api).toMatch(/'sealed'  => array\( 'start_value', 'target_value', 'start_value_2', 'target_value_2' \)/);
  // The nightly engine gets the bottom target too, so R-INS-10 checks both numbers the same way.
  const runner = src('includes/stage3/engine/class-insights-runner.php');
  expect(runner).toMatch(/\$target_2 = is_numeric\( \$goal\['target_value_2'\] \)/);
});

test('TC-FR3-12-AS-04', () => {
  // A missed day lowers the weekly count and touches nothing else.
  const full = [day(0), day(1), day(2)];
  expect(call('weekly_ring', full, day(2))).toMatchObject({ done: 3, total: 7, start: D });
  expect(call('weekly_ring', full, day(3))).toMatchObject({ done: 3, total: 7 }); // day 3 missed
  expect(call('weekly_ring', [...full, day(4)], day(4))).toMatchObject({ done: 4, total: 7 });
  // The week starts on the plan's start weekday when there is a plan.
  expect(call('weekly_ring', [day(-1), day(0)], day(0), day(-8))).toMatchObject({ done: 2, start: day(-1) });
  // Progress is a different number: it comes from the readings, not the ring (sheet progress wording).
  expect(call('progress', 90, 84, 86.8).pct).toBe(53);
});

test('TC-FR3-16-AS-01', () => {
  // 14 days of logs: weekly bars for the last 4 weeks and the current streak.
  const dates = []; for (let n = -13; n <= 0; n++) dates.push(day(n));
  expect(call('streak', dates, D)).toBe(14);
  expect(call('streak', dates.slice(0, -1), D)).toBe(13); // today not yet logged: the streak runs to yesterday
  expect(call('streak', dates.slice(0, -2), D)).toBe(0); // a missed yesterday pauses it
  const bars = call('weekly_bars', dates, D, 4);
  expect(bars.map((b) => b.days)).toEqual([0, 6, 7, 1]);
  expect(bars.map((b) => b.label)).toEqual(['3 weeks ago', '2 weeks ago', 'Last week', 'This week']);
});

test('TC-FR3-16-AS-03', () => {
  // The trend window holds the logged values as logged, oldest first, with the change.
  const pts = { [day(-20)]: 91, [day(-10)]: 90.2, [day(-3)]: 89.6, [D]: 89.3 };
  const t = call('trend', pts, D, 14);
  expect(t.rows.map((r) => r.value)).toEqual([90.2, 89.6, 89.3]);
  expect(t.change).toBe(-0.9);
  expect(call('trend', pts, D, 28).rows).toHaveLength(4);
});

test('TC-FR3-16-AS-04', () => {
  // No badge name or text mentions kilograms or pounds lost.
  expect(call('badge_text_allowed', 'Fibre boost finisher')).toBe(true);
  expect(call('badge_text_allowed', '28 days of logging')).toBe(true);
  for (const bad of ['Lost 5 kg', '10 pounds down', '2 lb lighter', 'Kilos gone']) expect(call('badge_text_allowed', bad), bad).toBe(false);
  const page = src('includes/stage3/screens/class-progress-page.php');
  expect(page).toContain('LLD_Members_Goal_Rules::badge_text_allowed( $title )');
  expect(page).toContain('Badges reward habits, never the amount of weight lost.');
});

// ---- Screens and data: the Stage 3 rules at source -------------------------------------------------------------

const SCREENS = ['class-goal-setting.php', 'class-today-screen.php', 'class-progress-page.php'];

test('goal journey: every screen checks its switch in boot() and enabled() for the member, and hooks template_redirect at 5', () => {
  const switches = { 'class-goal-setting.php': 'goals', 'class-today-screen.php': 'today', 'class-progress-page.php': 'progress' };
  for (const file of SCREENS) {
    const s = src(`includes/stage3/screens/${file}`);
    const sw = switches[file];
    expect(s, file).toMatch(new RegExp(`'off' === LLD_Members_Features::state\\( '${sw}' \\)`));
    expect(s, file).toMatch(new RegExp(`LLD_Members_Features::enabled\\( '${sw}', \\$member_id \\)`));
    expect(s, file).toContain("add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 5 )");
    expect(s, file).not.toMatch(/add_rewrite_rule/);
    expect(s, file).not.toMatch(/<link\b|<script\b/);
    expect(s, file).toMatch(/wp_enqueue_style\(/);
  }
  // The loader's convention derives the class names from the file names.
  const files = readdirSync(resolve(stage3, 'screens')).filter((f) => f.startsWith('class-'));
  for (const f of SCREENS) expect(files).toContain(f);
  const run = (sw, state) => JSON.parse(php(`$GLOBALS['hooks'] = [];
    class LLD_Members_Features { static function state($f) { return $f === ${JSON.stringify(sw)} ? ${JSON.stringify(state)} : 'off'; } }
    function add_action($h, $cb, $prio = 10) { $GLOBALS['hooks'][] = [$h, $prio]; }
    foreach (${JSON.stringify(SCREENS.map((f) => resolve(stage3, 'screens', f).split('\\').join('/')))} as $f) { require $f; }
    LLD_Members_Goal_Setting::boot(); LLD_Members_Today_Screen::boot(); LLD_Members_Progress_Page::boot(); echo json_encode($GLOBALS['hooks']);`));
  expect(run('goals', 'off')).toEqual([]);
  expect(run('goals', 'test_accounts')).toEqual([['template_redirect', 5]]);
  expect(run('today', 'everyone')).toEqual([['template_redirect', 5]]);
  expect(run('progress', 'everyone')).toEqual([['template_redirect', 5]]);
});

test('goal journey: the data class hooks nothing with every switch off, and its rewrites are its own', () => {
  const j = src('includes/stage3/class-goal-journey.php');
  expect(j).toMatch(/foreach \( array\( 'goals', 'today', 'progress' \) as \$feature \)/);
  expect(j).toContain("add_rewrite_rule( '^members/goal/?$', 'index.php?lld_screen=goal', 'top' )");
  expect(j).toContain("add_rewrite_rule( '^members/progress/?$', 'index.php?lld_screen=progress', 'top' )");
  const hooks = (state) => JSON.parse(php(`define('DAY_IN_SECONDS', 86400); $GLOBALS['hooks'] = [];
    class LLD_Members_Features { static function state($f) { return ${JSON.stringify(state)}; } }
    function add_action($h, $cb, $prio = 10) { $GLOBALS['hooks'][] = [$h, $prio]; }
    function add_filter($h, $cb, $prio = 10, $n = 1) { $GLOBALS['hooks'][] = [$h, $prio]; }
    require ${p(resolve(stage3, 'class-goal-journey.php'))}; LLD_Members_Goal_Journey::boot(); echo json_encode($GLOBALS['hooks']);`));
  expect(hooks('off')).toEqual([]);
  expect(hooks('test_accounts').map((h) => h[0])).toEqual(['init', 'lld_members_stage3_check_ins', 'lld_members_stage3_last_check_in', 'lld_members_stage3_check_in_done_this_week', 'lld_members_stage3_latest_weight']);
  // Health values go through the sealed data class, never a plain insert.
  expect(j).not.toMatch(/\$wpdb->insert\(|\bINSERT\b/);
  expect(j).toContain("LLD_Members_Stage3_Data::insert( 'goals', $member_id, $row, self::SEALED_GOAL )");
  expect(j).toContain("LLD_Members_Stage3_Data::insert( 'readings', $member_id, $row, self::SEALED_READING )");
  // Order on every answer: the switch, then the matrix (situations), then access (Premium), then the data.
  expect(j).toContain("LLD_Members_Goal_Rules::allowed_types( $facts['situations'] )");
  const loader = src('includes/stage3/loader.php');
  expect(loader).toContain("require_once __DIR__ . '/class-goal-rules.php';");
  expect(loader).toContain("require_once __DIR__ . '/class-goal-journey.php';");
  expect(loader).toContain('LLD_Members_Goal_Journey::boot();');
});

test('goal journey: no requirement or test ID in any member-facing string', () => {
  for (const file of [...SCREENS.map((f) => `screens/${f}`), 'class-goal-journey.php', 'class-goal-rules.php']) {
    const s = src(`includes/stage3/${file}`).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const strings = [...s.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1]).filter((t) => /[A-Za-z]{3,}\s[A-Za-z]{2,}/.test(t));
    for (const t of strings) expect(t, `${file}: ${t}`).not.toMatch(ID);
  }
});

test('goal journey: the member-facing wording is the signed sheet\'s where the sheet has it', () => {
  const m = JSON.parse(php('echo json_encode(LLD_Members_Goal_Rules::MESSAGES);'));
  expect(m.rate_cap).toBe("That's faster than the safe rate of about {cap} kg a week. At a safe rate you'd reach {goal} by about {safe_date}. Shall we use that date?");
  expect(m.bmi_floor).toBe('A goal of {goal} would put you below the healthy range for your height (BMI {bmi}). The lowest weight in the healthy range for you is about {lowest}. Would you like to use that?');
  expect(m.over_10).toBe('Losing more than 10 % is a big change. Setting a first goal of 5 to 10 % ({milestone} kg) and then a new one works well for most people.');
  expect(m.plateau).toBe("Weight loss isn't a straight line: expect plateaus and water-weight swings. We check your real trend every week and adjust.");
  expect(m.pregnancy_stop).toBe("Weight-loss goals aren't advised during pregnancy or breastfeeding. Your midwife or doctor can advise.");
  expect(m.trend_wait).toBe("Keep weighing in; we'll show your trend after 3 weigh-ins.");
  expect(m.on_track).toBe('On track');
  expect(m.behind).toBe('Behind');
});
