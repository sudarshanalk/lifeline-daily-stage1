// Fixes from the independent review of P3-35 (the Healthy Weight programme, package P2): the safety stop at any time,
// no calorie numbers for an eating-disorder member through the new food types, focus blocks that finish, "prefer not
// to say", the low-mood pause, the Premium plateau card, read-only views, the shared interface gate, the pace step, the
// notes' reasons on consent withdrawal, and the minor items. Made-up test members only.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';
import { plugin, run, src, stage3 } from './hw-data-harness.mjs';

/** Every number in a value, by path: a stopped programme shows none at all. */
function numbers(x) {
  const out = [];
  const walk = (v, p) => {
    if (typeof v === 'number') out.push(p);
    else if (v && typeof v === 'object') for (const [k, w] of Object.entries(v)) walk(w, `${p}.${k}`);
  };
  walk(x, '');
  return out;
}

/** The members' interface (class-api.php) on the harness, with a request stand-in. */
const API = `
  class WP_REST_Response { public $data; public $status; function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }
  class Fake_Request implements ArrayAccess {
    public $p; public $body; public $method;
    function __construct($p, $body = [], $method = 'POST') { $this->p = $p; $this->body = $body; $this->method = $method; }
    function get_json_params() { return $this->body; } function get_params() { return $this->body; } function get_method() { return $this->method; }
    function get_param($k) { return $this->p[$k] ?? ($this->body[$k] ?? null); }
    #[\\ReturnTypeWillChange] function offsetGet($k) { return $this->p[$k] ?? null; }
    function offsetExists($k): bool { return isset($this->p[$k]); }
    function offsetSet($k, $v): void { $this->p[$k] = $v; } function offsetUnset($k): void { unset($this->p[$k]); }
  }
  require ${JSON.stringify(stage3('class-api.php').split('\\').join('/'))};
`;

const WINE = `global $wpdb; $wpdb->insert(LLD_Members_Stage3_Schema::table('foods'), ['id' => 5, 'source' => 'usda', 'source_ref' => 'w1', 'name' => 'Wine, red', 'search_names' => 'wine red', 'kcal_100g' => 80, 'protein_100g' => 0.1, 'carb_100g' => 2, 'fat_100g' => 0, 'fibre_100g' => 0, 'alcohol_100g' => 10.6, 'updated_at' => '2026-11-01 00:00:00']);
  $wpdb->insert(LLD_Members_Stage3_Schema::table('foods'), ['id' => 6, 'source' => 'usda', 'source_ref' => 'c1', 'name' => 'Cola', 'search_names' => 'cola', 'kcal_100g' => 42, 'protein_100g' => 0, 'carb_100g' => 10.6, 'fat_100g' => 0, 'fibre_100g' => 0, 'alcohol_100g' => 0, 'updated_at' => '2026-11-01 00:00:00']);
  $wpdb->insert(LLD_Members_Stage3_Schema::table('foods'), ['id' => 7, 'source' => 'usda', 'source_ref' => 'r1', 'name' => 'Rice, boiled', 'search_names' => 'rice', 'kcal_100g' => 130, 'protein_100g' => 2.7, 'carb_100g' => 28, 'fat_100g' => 0.3, 'fibre_100g' => 0.4, 'alcohol_100g' => null, 'updated_at' => '2026-11-01 00:00:00']);`;
const WINE_ROW = "['id' => 5, 'kcal_100g' => 80, 'protein_100g' => 0.1, 'carb_100g' => 2, 'fat_100g' => 0, 'fibre_100g' => 0]";

// ---- B1: R-HW-SAFE-01 at any time --------------------------------------------------------------------------------

test('TC-FR3-102-AS-08 (after joining): pregnancy or an eating disorder reported later stops the programme with no number anywhere', () => {
  const out = run(`
    member(7); member(8); member(9);
    $views = [];
    foreach ([7, 8, 9] as $m) { $g = weight_goal($m); LLD_Members_HW_Programme::join($m, $g, ['alcohol_freq' => '1_2_days', 'alcohol_amount' => '1_2', 'reason' => 'health']); }
    $before = LLD_Members_HW_Programme::plan_view(7)['target_kcal'];
    LLD_Members_Stage3_Safety::$situations[7] = ['pregnancy'];
    LLD_Members_Stage3_Safety::$situations[8] = ['eating_disorder'];
    LLD_Members_HW_Answers::set(9, 'eating_disorder', 'yes'); // the Check's own question, answered later
    $items = [['parent_id' => 1001, 'title' => 'Stage 2 item']];
    foreach ([7, 8, 9] as $m) {
      $views[$m] = ['plan' => LLD_Members_HW_Programme::plan_view($m), 'today' => LLD_Members_HW_Programme::today($m), 'blocks' => LLD_Members_HW_Programme::blocks($m),
        'api' => [LLD_Members_HW_Programme::view('hw-plan', $m), LLD_Members_HW_Programme::view('hw-today', $m), LLD_Members_HW_Programme::view('hw-blocks', $m)],
        'night' => LLD_Members_HW_Programme::nightly($m, '2026-11-12'), 'items' => LLD_Members_HW_Programme::day_items($items, $m, '2026-11-12'),
        'carry' => LLD_Members_HW_Programme::carry_on($m)->get_error_code(), 'block' => LLD_Members_HW_Programme::choose_block($m, 'getting-stronger')->get_error_code()];
    }
    return ['before' => $before, 'views' => $views, 'notes' => count(raw('notes_shown')), 'items' => $items];
  `);
  expect(out.before).toBeGreaterThan(1200); // numbers before the change
  for (const [m, stop] of [[7, 'pregnancy'], [8, 'eating'], [9, 'eating']]) {
    const v = out.views[m];
    expect(v.plan.stopped, `member ${m}`).toBe(true);
    expect(v.plan.stop).toMatchObject({ rule: 'R-HW-SAFE-01', stop, screen: 'SCR-26' });
    expect(v.today.stop).toMatchObject({ stop, screen: 'SCR-26' });
    expect(v.blocks.stop.stop).toBe(stop);
    for (const part of [v.plan, v.today, v.blocks, ...v.api]) expect(numbers(part), `member ${m}`).toEqual([]);
    expect(v.plan).toMatchObject({ target_kcal: null, tdee: null, pace: null, drinks: null, milestones: [], cards: [], reasons: [] });
    expect(v.today).toMatchObject({ note: null, step: null, qod: [], reason_line: null, cards: [] });
    expect(v.today.drink_shortcut.show).toBe(false);
    expect(v.night.status).toBe('stopped');
    expect(v.items).toEqual(out.items); // the Stage 2 day items untouched
    expect(v.carry).toBe('lld_hw_not_offered');
    expect(v.block).toBe('lld_hw_not_offered');
  }
  expect(out.views[7].plan.stop.note_key).toBe('pregnancy.calorie-target');
  expect(out.views[8].plan.stop.note_key).toBe('ed.weight-goal-screen');
  expect(out.notes).toBe(0); // nothing ran
});

test('P3-35 R-HW-NUM-03 takes the pregnancy answer: the signed calculator gives no target in pregnancy', () => {
  const out = run(`
    $b = ['sex' => 'F', 'age' => 30, 'height_cm' => 165, 'weight_kg' => 80, 'activity_level' => 'some', 'pace' => 0.5, 'eating_disorder' => 'no'];
    return ['no' => LLD_Members_HW_Rules::target($b), 'yes' => LLD_Members_HW_Rules::target($b + ['pregnant' => 'yes']), 'bf' => LLD_Members_HW_Rules::target($b + ['pregnant' => 'breastfeeding'])];
  `);
  expect(out.no.target).toBeGreaterThan(1200);
  expect(out.yes).toMatchObject({ target: null, stop: 'pregnancy' });
  expect(out.bf).toMatchObject({ target: null, stop: 'pregnancy' });
  expect(src('healthy-weight/class-hw-programme.php')).toMatch(/'pregnant'\s+=> \$a\['pregnant'\],/);
});

// ---- B2: no calorie numbers from the new food types ---------------------------------------------------------------

test('TC-FR3-69-AS-01 (Healthy Weight food types): food-drinks, food-repeat and POST food-drink carry no calorie number for an eating-disorder member', () => {
  const out = run(`
    ${WINE}
    ${API}
    member(7, ['situations' => ['eating_disorder']]); member(8);
    foreach ([7, 8] as $m) foreach (['2026-11-10', '2026-11-11'] as $d) LLD_Members_Stage3_Data::insert('food_log', $m, ['entry_date' => $d, 'meal' => 'lunch', 'food_id' => 7, 'food_kind' => 'food', 'grams' => 150, 'kcal' => 195, 'protein_g' => 4, 'carb_g' => 42, 'fat_g' => 0.5, 'fibre_g' => 0.6, 'source' => 'search']);
    $found = ['wine' => ${WINE_ROW}];
    $out = [];
    foreach ([7, 8] as $m) {
      LLD_Members_Access::$member = $m;
      $post = LLD_Members_Stage3_API::create(new Fake_Request(['type' => 'food-drink'], ['size' => 'wine_175', 'food_id' => 5, 'entry_date' => '2026-11-12']));
      $out[$m] = ['drinks' => LLD_Members_Food_Log::drinks_for($m, $found), 'repeat' => LLD_Members_Food_Log::view('repeat', $m, ['date' => '2026-11-12']), 'post' => [$post->status, $post->data]];
    }
    $stored = array_values(array_filter(raw('food_log'), function ($r) { return 7 === (int) $r['member_id'] && 'drink' === $r['source']; }));
    return ['out' => $out, 'stored' => $stored];
  `);
  const ed = out.out[7];
  expect(ed.drinks.length).toBeGreaterThan(0);
  for (const d of ed.drinks) expect(d).toMatchObject({ kcal: null, nutrition_hidden: true });
  expect(ed.repeat.meal).toMatchObject({ meal: 'lunch', kcal: null, nutrition_hidden: true });
  expect(ed.post[0]).toBe(201);
  expect(ed.post[1].entry).toMatchObject({ kcal: null, protein_g: null, carb_g: null, fat_g: null, fibre_g: null, nutrition_hidden: true });
  expect(Number(out.stored[0].kcal)).toBe(140); // stored with its numbers: it still counts against the day
  // Another member sees the numbers.
  expect(out.out[8].drinks.find((d) => d.key === 'wine_175').kcal).toBe(140);
  expect(out.out[8].repeat.meal.kcal).toBe(195);
  expect(out.out[8].post[1].entry.kcal).toBe(140);
  // The interface view reads drinks_for(), and the matrix strips the three types as well (TC-FR3-69 type list).
  const food = src('class-food-log.php');
  expect(food).toContain("return array( 'drinks' => self::drinks_for( $member_id ) );");
  expect(src('class-api.php')).toContain("LLD_Members_Stage3_Safety::api_rows( $result, 'food-drink', $member_id )");
});

// ---- B3: focus blocks finish --------------------------------------------------------------------------------------

test('TC-FR3-104-AS-01 (blocks finish): after 28 days a block is finished, the next is suggested and can be chosen, and a finished block is not suggested again', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, ['barrier' => 'evening_snacking']);
    LLD_Members_Programme::$enrolment[7] = ['id' => 77, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-12-10'];
    LLD_Members_Programme::$today = '2027-01-08'; LLD_Members_Clock::$now = '2027-01-08 09:00:00';
    $first = LLD_Members_HW_Programme::blocks(7);
    LLD_Members_HW_Programme::choose_block(7, $first['suggested']['block']);
    LLD_Members_Programme::$today = '2027-02-04'; LLD_Members_Clock::$now = '2027-02-04 09:00:00';
    $last_day = LLD_Members_HW_Programme::blocks(7);
    $early = LLD_Members_HW_Programme::choose_block(7, 'getting-stronger');
    LLD_Members_Programme::$today = '2027-02-05'; LLD_Members_Clock::$now = '2027-02-05 09:00:00';
    $night = LLD_Members_HW_Programme::nightly(7, '2027-02-05');
    $after = LLD_Members_HW_Programme::blocks(7);
    $row1 = raw('block_choices')[0];
    $chose = LLD_Members_HW_Programme::choose_block(7, $after['suggested']['block']);
    return ['first' => $first['suggested']['block'], 'last_day' => $last_day, 'early' => $early->get_error_code(), 'after' => $after, 'row1' => $row1, 'chose' => $chose, 'rows' => raw('block_choices')];
  `);
  expect(out.first).toBe('evening-night-eating');
  expect(out.last_day).toMatchObject({ stage: 'block', current: { block: 'evening-night-eating', week: 4, day: 7 } });
  expect(out.early).toBe('lld_hw_block'); // not finished yet
  expect(out.row1.finished_at).toBe('2027-02-05 00:00:00'); // written on the member's own today
  expect(out.after).toMatchObject({ stage: 'between', current: null, due: true });
  expect(out.after.suggested.block).not.toBe('evening-night-eating');
  expect(out.chose.current).toMatchObject({ block: out.after.suggested.block, week: 1, day: 1 });
  expect(out.chose.stage).toBe('block');
  expect(out.rows).toHaveLength(2);
});

test('P3-35 blocks: a block paused by a festival block ends that many days later; the festival block closes after its week 4', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, []);
    LLD_Members_Programme::$enrolment[7] = ['id' => 77, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-11-01'];
    LLD_Members_Stage3_Data::insert('block_choices', 7, ['chosen_block_key' => 'getting-stronger', 'started_at' => '2027-01-01 09:00:00', 'paused_at' => '2027-01-10 00:00:00', 'resumed_at' => '2027-01-20 00:00:00']);
    $at = function ($d) { LLD_Members_Programme::$today = $d; LLD_Members_Clock::$now = "$d 09:00:00"; return LLD_Members_HW_Programme::blocks(7); };
    $resumed = $at('2027-01-20');
    $old_end = $at('2027-01-29'); // day 29 without the pause
    $end = $at('2027-02-08');     // 28 + 10 days after the start
    return ['resumed' => $resumed['current'], 'old_end' => [$old_end['stage'], $old_end['current']], 'end' => [$end['stage'], raw('block_choices')[0]['finished_at']]];
  `);
  expect(out.resumed).toMatchObject({ block: 'getting-stronger', week: 2, day: 3 }); // day 10 of the block, as on 9 Jan
  expect(out.old_end[0]).toBe('block');
  expect(out.end).toEqual(['between', '2027-02-08 00:00:00']);
  const p = src('healthy-weight/class-hw-programme.php');
  expect(p).toMatch(/LLD_Members_HW_Festival::BLOCK === \(string\) \$r\['chosen_block_key'\] && \$fest && ! empty\( \$fest\['to'\] \)/);
  expect(p).toContain("return LLD_Members_HW_Festival::add_days( (string) $fest['to'], 1 );");
});

// ---- S1: "prefer not to say" -------------------------------------------------------------------------------------

test('TC-FR3-100-AS-05 (prefer not to say): the Check\'s eating question reaches the engine: "prefer" limits the pace to 0.25 or 0.5, also by a date; "yes" stops', () => {
  const out = run(`
    member(7); member(8); member(9);
    foreach ([7, 8, 9] as $m) weight_goal($m);
    LLD_Members_HW_Answers::set(7, 'eating_disorder', 'prefer');
    LLD_Members_HW_Answers::set(8, 'eating_disorder', 'yes');
    $preview = LLD_Members_HW_Programme::plan_view(7);
    $fast = LLD_Members_HW_Programme::join(7, ['pace' => 0.75, 'today' => '2026-11-12']);
    $date = LLD_Members_HW_Programme::join(7, ['target_date' => '2027-06-30', 'today' => '2026-11-12']);
    $other = LLD_Members_HW_Programme::join(9, ['target_date' => '2027-06-30', 'today' => '2026-11-12']);
    $yes = LLD_Members_HW_Programme::join(8, ['pace' => 0.5, 'today' => '2026-11-12']);
    $ok = LLD_Members_HW_Programme::join(7, ['pace' => 0.5, 'today' => '2026-11-12']);
    $row = array_values(array_filter(raw('profile_answers'), function ($r) { return 7 === (int) $r['member_id'] && 'eating_disorder' === $r['question_key']; }))[0];
    return ['engine' => LLD_Members_HW_Answers::engine(7, LLD_Members_Goal_Journey::facts(7))['eating_disorder'], 'paces' => $preview['pace']['paces'],
      'fast' => [$fast->get_error_code(), $fast->get_error_data()], 'date' => [$date->get_error_code(), $date->get_error_data()['code']], 'other' => $other['pace']['chosen'],
      'yes' => [$yes->get_error_code(), $yes->get_error_data()], 'ok' => $ok['pace']['chosen'], 'row' => $row];
  `);
  expect(out.engine).toBe('pnts');
  expect(out.paces).toEqual([0.25, 0.5]);
  expect(out.fast).toEqual(['lld_hw_pace', { refusal: 'goal-timeline:refusals.rate-cap', safe_pace: 0.5 }]);
  expect(out.date).toEqual(['lld_hw_pace', 'rate_cap']); // 0.61 kg a week: inside the 0.8 cap, above the 0.5 limit
  expect(out.other).toBe(0.61); // the same date for a member who said no
  expect(out.yes).toEqual(['lld_hw_not_offered', { stop: 'eating', screen: 'SCR-26' }]);
  expect(out.ok).toBe(0.5);
  expect(out.row.answer_ct).toMatch(/^ct:/); // sealed
  expect(JSON.stringify(out.row)).not.toContain('prefer');
});

// ---- S2: the low-mood pause --------------------------------------------------------------------------------------

test('TC-FR3-106-AS-04 (the pause gates everything): in the 7 days after R-SAFE-04 no quick question, "Add a drink", reason line, step version, block suggestion or drinks line; safety, the target and the kindness note stay', () => {
  const out = run(`
    member(7); member(8);
    LLD_Members_Clock::$now = '2026-11-12 19:00:00';
    $ans = ['reason' => 'health', 'time' => 'under_10', 'confidence' => 3, 'stress' => 9, 'alcohol_freq' => '1_2_days', 'alcohol_amount' => '1_2'];
    foreach ([7, 8] as $m) { $g = weight_goal($m); LLD_Members_HW_Programme::join($m, $g, $ans); }
    LLD_Members_Stage3_Data::insert('insights', 7, ['rule_id' => 5, 'template_id' => 5, 'shown_at' => '2026-11-10 08:00:00', 'updated_at' => '2026-11-10 08:00:00']);
    $out = [];
    foreach ([7, 8] as $m) $out[$m] = ['today' => LLD_Members_HW_Programme::today($m), 'plan' => LLD_Members_HW_Programme::plan_view($m)];
    LLD_Members_Programme::$enrolment[7] = ['id' => 77, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-10-01'];
    LLD_Members_Programme::$enrolment[8] = ['id' => 88, 'track_key' => 'healthy-weight-2', 'status' => 'finished', 'first' => '2026-10-01'];
    LLD_Members_HW_Programme::flush();
    $out[7]['blocks'] = LLD_Members_HW_Programme::blocks(7); $out[8]['blocks'] = LLD_Members_HW_Programme::blocks(8);
    return $out;
  `);
  const [pause, usual] = [out[7], out[8]];
  expect(usual.today.qod.length).toBeGreaterThan(0);
  expect(usual.today.drink_shortcut.show).toBe(true);
  expect(usual.today.reason_line).toBeTruthy();
  expect(usual.today.step.version).toBe('easier');
  expect(usual.plan.reasons.map((r) => r.rule)).toContain('R-HW-NUM-05');
  expect(usual.blocks.suggested).not.toBeNull();
  expect(pause.today.qod).toEqual([]);
  expect(pause.today.drink_shortcut.show).toBe(false);
  expect(pause.today.reason_line).toBeNull();
  expect(pause.today.step.version).toBe('standard');
  expect(pause.plan.reasons.map((r) => r.rule)).not.toContain('R-HW-NUM-05');
  expect(pause.blocks.suggested).toBeNull();
  // Kept: the kindness note (R-HW-NOTE-01), the support card (R-HW-SAFE-03), the calorie target.
  expect(pause.today.note.key).toBe('HW-MN-W4-4');
  expect(pause.today.cards.map((c) => c.rule)).toContain('R-HW-SAFE-03');
  expect(pause.plan.target_kcal).toBe(usual.plan.target_kcal);
});

// ---- S3: the plateau card is Premium ----------------------------------------------------------------------------

test('TC-FR3-108-AS-01 (Premium, downgrade): the plateau card shows only to Premium; after a downgrade it goes, and the next run clears the state', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, []);
    foreach (['2026-10-27' => 80.1, '2026-11-01' => 80.2, '2026-11-06' => 80.0, '2026-11-11' => 80.1] as $d => $kg) weigh(7, $d, $kg);
    $night = LLD_Members_HW_Programme::nightly(7, '2026-11-12');
    $prem = array_column(LLD_Members_HW_Programme::plan_view(7)['cards'], 'rule');
    LLD_Members_Billing::$premium[7] = false;
    $free = array_column(LLD_Members_HW_Programme::plan_view(7)['cards'], 'rule');
    LLD_Members_Programme::$today = '2026-11-13';
    LLD_Members_HW_Programme::nightly(7, '2026-11-13');
    $state = json_decode(LLD_Members_Goal_Journey::plan(7, $g)['log_schedule'], true)['hw'];
    return ['night' => $night['plateau'], 'prem' => $prem, 'free' => $free, 'state' => $state['plateau']];
  `);
  expect(out.night).toBe(true);
  expect(out.prem).toContain('R-HW-WEEK-02');
  expect(out.free).not.toContain('R-HW-WEEK-02');
  expect(out.state).toBe(false);
});

// ---- S4: reading the views never writes ---------------------------------------------------------------------------

test('P3-35 read-only views: a GET or the interface with another date writes no note and changes no state; the member\'s own today does', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, []);
    foreach (['2026-11-14','2026-11-15','2026-11-16'] as $d) weigh(7, $d, 75.5); // a milestone a future view must not mark
    $snap = function () { return json_encode([raw('notes_shown'), raw('plans'), raw('goal_milestones'), raw('block_choices')]); };
    $before = $snap();
    LLD_Members_HW_Programme::view('hw-today', 7, ['date' => '2026-11-20']);
    LLD_Members_HW_Programme::view('hw-plan', 7, ['date' => '2026-11-20']);
    LLD_Members_HW_Programme::view('hw-blocks', 7, ['date' => '2026-11-20']);
    LLD_Members_HW_Programme::today(7, '2026-11-16');
    $night = LLD_Members_HW_Programme::nightly(7, '2026-11-20');
    $after = $snap();
    LLD_Members_HW_Programme::view('hw-today', 7);
    return ['same' => $before === $after, 'night' => $night['status'], 'own' => count(raw('notes_shown'))];
  `);
  expect(out.same).toBe(true);
  expect(out.night).toBe('not_today');
  expect(out.own).toBe(1);
});

// ---- S5: the shared gate ------------------------------------------------------------------------------------------

test('P3-35 interface: POST /me/healthy-weight/* goes through lld_members_stage3_api_refuse with type healthy-weight', () => {
  const out = run(`
    ${API}
    member(7);
    $g = weight_goal(7);
    LLD_Members_Access::$member = 7;
    $GLOBALS['seen'] = [];
    add_filter('lld_members_stage3_api_refuse', function ($r, $type, $m) { $GLOBALS['seen'][] = [$type, $m]; return 'healthy-weight' === $type ? new WP_REST_Response(['message' => 'Refused.'], 403) : $r; }, 10, 3);
    $r = LLD_Members_Stage3_API::healthy_weight(new Fake_Request(['action' => 'join'], ['goal_id' => $g, 'answers' => []]));
    $refused = [$r->status, $r->data, count(raw('profile_answers')), LLD_Members_HW_Programme::joined(7)];
    remove_all_filters('lld_members_stage3_api_refuse');
    $ok = LLD_Members_Stage3_API::healthy_weight(new Fake_Request(['action' => 'join'], ['goal_id' => $g, 'answers' => []]));
    return ['refused' => $refused, 'seen' => $GLOBALS['seen'], 'ok' => $ok->status, 'joined' => LLD_Members_HW_Programme::joined(7)];
  `);
  expect(out.refused).toEqual([403, { message: 'Refused.' }, 0, false]);
  expect(out.seen).toEqual([['healthy-weight', 7]]);
  expect(out.ok).toBe(201);
  expect(out.joined).toBe(true);
  const api = src('class-api.php');
  const handler = api.slice(api.indexOf('public static function healthy_weight'), api.indexOf('Feature, sign-in and consent checks shared'));
  expect(handler.indexOf('has_health_consent')).toBeLessThan(handler.indexOf("'lld_members_stage3_api_refuse', null, 'healthy-weight'"));
  expect(handler.indexOf("'lld_members_stage3_api_refuse', null, 'healthy-weight'")).toBeLessThan(handler.indexOf('switch'));
});

// ---- S6: abandoning at the pace step ----------------------------------------------------------------------------

test('TC-FR3-100-AS-06 (pace not chosen): the goal is saved with no rate, a member under 50 kg reaches the pace step, and Today asks to finish choosing', () => {
  const out = run(`
    member(7, ['height' => 150, 'weight' => 49]);
    $f = LLD_Members_Goal_Journey::facts(7);
    $old = LLD_Members_Goal_Journey::create_goal(7, 'main', 'lose_weight', ['current' => 49, 'target' => 45, 'rate' => 0.5, 'facts' => $f]); // the old hidden default
    $id = LLD_Members_Goal_Journey::create_goal(7, 'main', 'lose_weight', ['current' => 49, 'target' => 45, 'facts' => $f]);
    $goal = raw('goals')[0];
    $pending = LLD_Members_HW_Programme::pending_pace(7);
    $preview = LLD_Members_HW_Programme::plan_view(7);
    $today = LLD_Members_HW_Programme::today(7);
    $joined = LLD_Members_HW_Programme::join(7, ['pace' => 0.25, 'today' => '2026-11-12']);
    return ['old' => [$old->get_error_code(), $old->get_error_data()['code']], 'id' => $id, 'chosen' => $goal['chosen_rate'], 'pending' => $pending, 'paces' => $preview['pace']['paces'],
      'today' => $today, 'joined' => $joined['pace']['chosen'], 'after' => LLD_Members_HW_Programme::pending_pace(7)];
  `);
  expect(out.old).toEqual(['lld_goal_refused', 'rate_cap']); // what the goal step used to refuse
  expect(out.id).toBeGreaterThan(0);
  expect(out.chosen).toBeNull();
  expect(out.pending).toBe(out.id);
  expect(out.paces).toEqual([0.25]);
  expect(out.today).toBeNull(); // not joined yet
  expect(out.joined).toBe(0.25);
  expect(out.after).toBeNull();
  // The Healthy Weight goal form sends no rate; the plan page and Today show "Your pace" until it is chosen.
  const gs = readFileSync(resolve(plugin, 'includes/stage3/screens/class-goal-setting.php'), 'utf8');
  const hwForm = gs.slice(gs.indexOf("if ( 'lose_weight' === $type && $hw_on ) {"), gs.indexOf("} elseif ( 'lose_weight' === $type ) {"));
  expect(hwForm).not.toContain('lld_rate');
  expect(gs).toContain("'pending_pace', array( (int) $member_id )");
  expect(src('screens/class-today-screen.php')).toContain('self::hw_pace_pending_html( $member_id )');
});

// ---- S7: consent withdrawal -------------------------------------------------------------------------------------

test('TC-FR3-98-AS-05 (notes shown): withdrawing health consent keeps the notes shown and clears which rule chose them', () => {
  const out = run(`
    member(7); member(8);
    foreach ([7, 8] as $m) { $g = weight_goal($m); LLD_Members_HW_Programme::join($m, $g, ['stress' => 8]); LLD_Members_HW_Programme::nightly($m, '2026-11-12', ['safety' => ['R-SAFE-04']]); }
    $before = raw('notes_shown');
    LLD_Members_Stage3_Erasure::withdraw_health(7);
    return ['before' => $before, 'after' => raw('notes_shown')];
  `);
  const mine = (rows, m) => rows.filter((r) => Number(r.member_id) === m);
  expect(mine(out.before, 7)[0]).toMatchObject({ reason: 'rule', rule_id: 1061 });
  expect(mine(out.after, 7)).toHaveLength(1);
  expect(mine(out.after, 7)[0]).toMatchObject({ note_key: 'HW-MN-W4-4', rule_id: null, reason: null });
  expect(mine(out.after, 8)[0]).toMatchObject({ reason: 'rule', rule_id: 1061 }); // another member's rows stay
  expect(src('class-schema-stage3.php')).toContain('reason VARCHAR(8) NULL DEFAULT "default",');
});

// ---- Minor items --------------------------------------------------------------------------------------------------

test('P3-35 repeat meal: the default date is the member\'s local date, not the server\'s', () => {
  const out = run(`
    member(7);
    LLD_Members_Programme::$today = '2030-01-02';
    LLD_Members_Stage3_Data::insert('food_log', 7, ['entry_date' => '2030-01-01', 'meal' => 'dinner', 'food_id' => 7, 'food_kind' => 'food', 'grams' => 100, 'kcal' => 130, 'source' => 'search', 'created_at' => '2030-01-01 19:00:00']);
    return LLD_Members_Food_Log::view('repeat', 7, []);
  `);
  expect(out.meal).toMatchObject({ meal: 'dinner', from_date: '2030-01-01' });
});

test('TC-FR3-110-AS-02 (drinks only): a drink is logged only as a drink of its size; any alcoholic drink logged today hides "Add a drink"', () => {
  const out = run(`
    ${WINE}
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, ['alcohol_freq' => '1_2_days', 'alcohol_amount' => '1_2']);
    LLD_Members_Clock::$now = '2026-11-12 19:00:00';
    $refused = [LLD_Members_Food_Log::log_drink(7, 'wine_175', 6, '2026-11-12'), LLD_Members_Food_Log::log_drink(7, 'tonic', 5, '2026-11-12'), LLD_Members_Food_Log::log_drink(7, 'wine_175', 7, '2026-11-12'), LLD_Members_Food_Log::log_drink(7, 'wine_175', 99, '2026-11-12')];
    $before = LLD_Members_HW_Programme::today(7)['drink_shortcut']['show'];
    LLD_Members_Stage3_Data::insert('food_log', 7, ['entry_date' => '2026-11-12', 'meal' => 'dinner', 'food_id' => 5, 'food_kind' => 'food', 'grams' => 175, 'kcal' => 140, 'source' => 'search']);
    LLD_Members_HW_Programme::flush();
    $after = LLD_Members_HW_Programme::today(7)['drink_shortcut']['show'];
    $ok = LLD_Members_Food_Log::log_drink(7, 'wine_125', 5, '2026-11-13');
    $rice = [['entry_date' => '2026-11-12', 'food_id' => 7, 'food_kind' => 'food', 'source' => 'search']];
    return ['refused' => array_column($refused, 'error'), 'before' => $before, 'after' => $after, 'ok' => $ok['entry']['kcal'],
      'rice' => LLD_Members_Food_Log::drink_logged($rice, '2026-11-12', LLD_Members_Food_Log::alcoholic_food_ids($rice, '2026-11-12'))];
  `);
  expect(out.refused).toEqual(['Choose a drink.', 'Choose a drink.', 'Choose a drink.', 'Choose a drink.']);
  expect(out.before).toBe(true);
  expect(out.after).toBe(false);
  expect(out.ok).toBe(100);
  expect(out.rice).toBe(false);
});

test('P3-35 festival calendar rows carry created_at and updated_at, and an update keeps the first created_at', () => {
  const out = run(`
    require ${JSON.stringify(stage3('class-content-hw.php').split('\\').join('/'))};
    $e = ['key' => 'CAL-TEST-2027', 'name' => 'Test fast', 'kind' => 'dawn_to_sunset_fast', 'starts_on' => '2027-02-08', 'ends_on' => '2027-03-08', 'expected' => false, 'approved_on' => '2026-11-10'];
    $cols = ['id', 'festival_key', 'name', 'kind', 'starts_on', 'ends_on', 'expected', 'approved_on', 'created_at', 'updated_at'];
    return ['row' => LLD_Members_HW_Content::festival_row($e, $cols, 'festival_key', '2026-11-10 09:00:00'), 'none' => LLD_Members_HW_Content::festival_row($e, ['id', 'name'], '', '2026-11-10 09:00:00')];
  `);
  expect(out.row).toMatchObject({ festival_key: 'CAL-TEST-2027', created_at: '2026-11-10 09:00:00', updated_at: '2026-11-10 09:00:00' });
  expect(out.none).toEqual({ name: 'Test fast' });
  expect(src('class-content-hw.php')).toMatch(/if \( \$found \) \{\s*unset\( \$row\['created_at'\] \);/);
});

test('TC-FR3-98-AS-08 (every tier): a member who never drinks, or whose food rules exclude alcohol, gets no alcohol activity, Free or Premium', () => {
  const out = run(`
    $cases = [7 => [false, ['halal']], 8 => [true, ['halal']], 9 => [false, ['jain']], 10 => [false, []], 11 => [false, null]];
    $out = [];
    foreach ($cases as $m => $c) {
      member($m, ['premium' => $c[0]]);
      $g = weight_goal($m);
      LLD_Members_HW_Programme::join($m, $g, 11 === $m ? ['alcohol_freq' => 'never'] : (10 === $m ? ['alcohol_freq' => '1_2_days', 'alcohol_amount' => '1_2'] : []));
      if ($c[1]) LLD_Members_HW_Answers::set($m, 'food_rules', $c[1]);
      LLD_Members_Programme::$enrolment[$m] = ['id' => 100 + $m, 'track_key' => 'healthy-weight-2', 'status' => 'active', 'first' => '2026-11-01'];
    }
    LLD_Members_Programme::$today = '2026-11-25'; // level 2 day 25: week 8, day 4 is the track's alcohol item HW2-W4-A4
    $items = [['parent_id' => 2025, 'title' => 'HW2-W4-A4']];
    foreach (array_keys($cases) as $m) { LLD_Members_HW_Programme::flush(); $out[$m] = ['step' => LLD_Members_HW_Programme::today($m, '2026-11-25')['step'], 'items' => LLD_Members_HW_Programme::day_items($items, $m, '2026-11-25')]; }
    return $out;
  `);
  for (const m of [7, 8, 9, 11]) {
    expect(out[m].step?.activity_key ?? null, `member ${m}`).not.toBe('HW2-W4-A4');
    expect(out[m].items.map((i) => i.title), `member ${m}`).not.toContain('HW2-W4-A4');
  }
  // A Free member who drinks keeps the track's own alcohol item.
  expect(out[10].step.activity_key).toBe('HW2-W4-A4');
});

test('P3-35 food rules: Jain is a real choice (HW-QW-13), excludes alcohol like halal, filters recipes as approved; no dead value is left', () => {
  const out = run(`
    require ${JSON.stringify(stage3('class-content-hw.php').split('\\').join('/'))};
    member(7);
    $set = LLD_Members_HW_Answers::set(7, 'food_rules', ['jain']);
    $dead = LLD_Members_HW_Answers::clean('food_rules', ['no_alcohol']);
    $veg = ['id' => 1, 'approved' => true, 'diets' => ['everything', 'vegetarian'], 'allergens' => [], 'food_rules' => []];
    return ['set' => $set, 'dead' => $dead instanceof WP_Error, 'engine' => LLD_Members_HW_Answers::engine(7), 'qw13' => LLD_Members_HW_Content::ANSWERS['HW-QW-13']['values'],
      'ok' => LLD_Members_HW_Rules::food_ok($veg, ['food_rules' => ['jain']])['ok'],
      'root' => LLD_Members_HW_Rules::food_ok(['food_rules' => ['contains-root-vegetables']] + $veg, ['food_rules' => ['jain']])['reasons'],
      'meat' => LLD_Members_HW_Rules::food_ok(['diets' => ['everything']] + $veg, ['food_rules' => ['jain']])['reasons'],
      'lines' => LLD_Members_HW_Rules::food_filter(array_fill(0, 5, $veg), ['food_rules' => ['jain']])['lines'],
      'rules' => LLD_Members_HW_Rules::NO_ALCOHOL_RULES];
  `);
  expect(out.set).toBe(true);
  expect(out.dead).toBe(true);
  expect(out.engine.food_rules).toEqual(['jain']);
  expect(out.engine.alcohol_freq).toBe('never');
  expect(out.rules).toEqual(['halal', 'jain']);
  expect(out.ok).toBe(true);
  expect(out.root).toEqual(['jain:contains-root-vegetables']);
  expect(out.meat).toEqual(['jain:diet']);
  expect(out.lines).toEqual(['Practice varies, so check each recipe against your own rules. Leave out any optional honey or root vegetables. Meal times, seasonal rules and how long food is kept are up to you.',
    'Some vanilla extracts and soy sauces contain alcohol. Choose alcohol-free or halal-certified versions if you prefer.']);
  for (const f of ['healthy-weight/class-hw-answers.php', 'healthy-weight/class-hw-rules.php', 'screens/class-hw-screen-parts.php']) expect(src(f), f).not.toContain("'no_alcohol'");
  expect(src('class-content-hw.php')).toMatch(/'HW-QW-13' => array\( 'key' => 'food_rules', 'type' => 'multi', 'values' => array\([^)]*'jain'/);
});

test('TC-FR3-98-AS-09 (shift workers\' sleep): with a wake-up time, bedtime is 16 hours after waking, so no snack reminder falls in the main sleep', () => {
  const out = run(`
    $r = function ($in) { return array_column(LLD_Members_HW_Rules::reminder_times($in)['reminders'], 'time', 'kind'); };
    return ['b' => $r(['work' => 'nights', 'wake_time' => '14:00']), 'b_late' => $r(['work' => 'nights', 'wake_time' => '14:00', 'snack_time' => 'after 9 pm']),
      'early' => $r(['work' => 'nights', 'wake_time' => '04:00', 'snack_time' => 'after 9 pm']), 'own' => $r(['work' => 'shifts', 'wake_time' => '04:00', 'snack_time' => 'after 9 pm', 'bedtime' => '22:00'])];
  `);
  expect(out.b).toEqual({ step: '14:30' }); // member B: nights, wakes at 14:00
  expect(out.b_late).toEqual({ step: '14:30', snack: '20:30' }); // awake 14:00 to 06:00
  expect(out.early).toEqual({ step: '04:30' }); // asleep from 20:00 to 04:00: no 20:30 snack reminder
  expect(out.own).toEqual({ step: '04:30', snack: '20:30' }); // the member's own bedtime is kept
});

test('P3-35 join: joining again with the same goal resets nothing; after a new goal it starts fresh', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, ['stress' => 4]);
    LLD_Members_HW_Programme::nightly(7, '2026-11-12');
    $state = json_decode(LLD_Members_Goal_Journey::plan(7, $g)['log_schedule'], true)['hw'];
    LLD_Members_Programme::$today = '2026-11-14';
    $again = LLD_Members_HW_Programme::join(7, $g, ['stress' => 4]);
    $same = json_decode(LLD_Members_Goal_Journey::plan(7, $g)['log_schedule'], true)['hw'];
    LLD_Members_Stage3_Data::update('goals', 7, $g, ['status' => 'replaced']);
    $g2 = weight_goal(7, 79, 65);
    $fresh = LLD_Members_HW_Programme::join(7, $g2, []);
    $new = json_decode(LLD_Members_Goal_Journey::plan(7, $g2)['log_schedule'], true)['hw'];
    return ['again' => is_array($again) && isset($again['target_kcal']), 'same' => $same === $state, 'started' => LLD_Members_Programme::$started, 'new' => $new['joined'], 'queue' => $new['queue'][0]];
  `);
  expect(out.again).toBe(true);
  expect(out.same).toBe(true);
  expect(out.started).toEqual([[7, 'healthy-weight'], [7, 'healthy-weight']]); // once a goal
  expect(out.new).toBe('2026-11-14');
  expect(out.queue).toBe('HW-MN-W1-1');
});

test('P3-35 day items: one context a request and member for a Stage 2 programme page', () => {
  const out = run(`
    member(7);
    $g = weight_goal(7);
    LLD_Members_HW_Programme::join(7, $g, []);
    LLD_Members_HW_Programme::flush();
    global $wpdb;
    $n = count($wpdb->log);
    for ($d = 1; $d <= 28; $d++) LLD_Members_HW_Programme::day_items([['parent_id' => 1000 + $d]], 7, gmdate('Y-m-d', strtotime('2026-11-12 +' . ($d - 1) . ' days')));
    $facts = count(array_filter(array_slice($wpdb->log, $n), function ($q) { return false !== strpos($q, 'FROM wp_lld_health_profile'); }));
    return ['facts' => $facts];
  `);
  expect(out.facts).toBeLessThanOrEqual(2); // was one facts() read a day (28)
});
