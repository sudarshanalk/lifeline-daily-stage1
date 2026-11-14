// Healthy Weight screens (P3-35, package P4), checked without a browser: the screens run in PHP CLI on the signed engine
// (P1) with stand-ins for the parallel packages P2 (data) and P3 (approved content) answering from the fixture arrays in
// fixture.mjs. Checks that the screens show only approved wording (the Check's copy, the signed T-HW templates, the
// loader's questions), leave out what is not loaded, render safely with P2 and P3 missing, and keep the safety rules:
// no calorie number with an eating-disorder flag, nothing about weight or calories beside the drinking card, neutral
// BMI bands, at most two quick questions a day.
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { activities, blocksContent, planView, questions, todayView } from './fixture.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const screensDir = resolve(plugin, 'includes/stage3/screens');
const stubs = JSON.stringify(resolve(here, 'wp-stubs.php').split('\\').join('/'));
const ID = /\b(?:TC-)?N?FR\d-\d\d(?:-AS-\d\d)?\b/;
const read = (f) => readFileSync(resolve(screensDir, f), 'utf8');
const NEW = ['class-hw-screen-parts.php', 'class-hw-where-screen.php', 'class-hw-questions-screen.php', 'class-hw-plan-screen.php', 'class-hw-blocks-screen.php'];

/** Run PHP after the stubs; `fx` goes in on standard input; with `p2p3` the P2 and P3 stand-ins are defined. */
function php(code, fx = {}, p2p3 = true) {
  const env = { ...process.env, LLD_HW_STUBS: p2p3 ? '1' : '' };
  return execFileSync('php', ['-d', 'display_errors=stderr', '-r', `require ${stubs}; ${code}`], { input: JSON.stringify(fx), env, encoding: 'utf8', timeout: 60000 }).trim();
}
const run = (code, fx, p2p3) => JSON.parse(php(`echo json_encode((function () { ${code} })());`, fx, p2p3));
const base = { questions, blocks_content: blocksContent, activities };
const facts = (extra = {}) => ({ member_id: 7, age: 45, imperial: false, today: '2026-11-10', health: true, premium: false, sex: 'female', height_cm: 163, weight_kg: 80, waist_cm: null, activity: 'some', situations: [], ...extra });
const phpFacts = (f) => `json_decode(${JSON.stringify(JSON.stringify(f))}, true)`;
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&').replace(/\s+/g, ' ');

test('hw screens: the Check copy in CHECK_COPY is word for word copy/healthy-weight-messages.json', () => {
  const approved = JSON.parse(readFileSync(resolve(root, 'copy/healthy-weight-messages.json'), 'utf8'));
  const ours = run('return LLD_Members_Hw_Screen_Parts::CHECK_COPY;', {}, false);
  expect(Object.keys(ours).length).toBeGreaterThan(30);
  for (const [k, v] of Object.entries(ours)) expect(v, k).toBe(approved[k]);
});

test('hw screens: every signed template the screens name exists in the rule catalogue', () => {
  const keys = new Set();
  for (const f of [...NEW, 'class-today-screen.php', 'class-progress-page.php']) for (const m of read(f).matchAll(/'(hw\.T-HW-[A-Z0-9.\-a-z_]+|hw\.R-HW-ALC-02\.button)'/g)) if (!m[1].endsWith('.')) keys.add(m[1]);
  expect(keys.size).toBeGreaterThan(8);
  const missing = run(`$out = []; foreach (${JSON.stringify(JSON.stringify([...keys]))} ? json_decode(${JSON.stringify(JSON.stringify([...keys]))}, true) : [] as $k) { if (null === LLD_Members_Rule_Catalogue::text($k)) $out[] = $k; } return $out;`, {}, false);
  expect(missing).toEqual([]);
  // Prefix keys (".heading.", ".label.", ".reason.") are completed from the engine's values; each completion exists.
  for (const k of ['hw.T-HW-PACE-01.heading.gentle', 'hw.T-HW-PACE-01.heading.steady', 'hw.T-HW-PACE-01.heading.faster', 'hw.T-HW-NUM-04.label.5pct', 'hw.T-HW-NUM-04.label.10pct', 'hw.T-HW-NUM-04.label.healthy', 'hw.T-HW-NUM-04.label.goal']) {
    expect(run(`return LLD_Members_Rule_Catalogue::text(${JSON.stringify(k)});`, {}, false), k).toBeTruthy();
  }
});

test('hw screens: no requirement or test ID, no Google Analytics and no hand-written script or link tag in the screens', () => {
  for (const f of NEW) {
    const src = read(f);
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/[^\n]*/gm, '').replace(/\s\/\/ [^\n]*/g, '');
    for (const m of code.matchAll(/'([^'\\]*(?:\\.[^'\\]*)*)'/g)) expect(m[1], `${f}: ${m[1]}`).not.toMatch(ID);
    expect(src, f).not.toMatch(/gtag|googletagmanager|google-analytics|dataLayer/i);
    expect(src, f).not.toMatch(/<script\b|<link\b/);
  }
  const labels = run('return LLD_Members_Hw_Screen_Parts::LABELS;', {}, false);
  expect(Object.values(labels).join(' ')).not.toMatch(ID);
});

test('hw screens: with P2 and P3 missing, nothing Healthy Weight is shown and nothing breaks', () => {
  const r = run(`
    $f = ${phpFacts(facts())};
    return [
      'flow' => LLD_Members_Goal_Setting::hw_flow(),
      'questions' => LLD_Members_Hw_Questions_Screen::joining_questions([]),
      'qod' => LLD_Members_Hw_Questions_Screen::qod_html(['eating_pattern']),
      'plan' => LLD_Members_Hw_Screen_Parts::plan_view(7, '2026-11-10'),
      'today' => LLD_Members_Hw_Screen_Parts::today_view(7, '2026-11-10'),
      'blocks' => LLD_Members_Hw_Blocks_Screen::data(7, '2026-11-10'),
      'set' => LLD_Members_Hw_Screen_Parts::set_answer(7, 'time', 'under_10'),
      'where' => LLD_Members_Hw_Where_Screen::fields_html(['ask_background' => false], [], $f),
    ];`, {}, false);
  expect(r).toEqual({ flow: false, questions: [], qod: '', plan: null, today: null, blocks: null, set: false, where: '' });
});

test('TC-FR3-97-AS-04 a pregnant member sees no BMI band and the pregnancy wording (source)', () => {
  const html = run(`$f = ${phpFacts(facts({ situations: ['pregnancy'] }))}; $w = LLD_Members_Hw_Where_Screen::where(7, $f, []); return LLD_Members_Hw_Where_Screen::result_html($w, []) . LLD_Members_Hw_Where_Screen::fields_html($w, [], $f);`, base);
  expect(html).toContain('data-lld-hw-stop="pregnancy"');
  expect(text(html)).toContain("This check isn't suitable right now");
  expect(text(html)).toContain('during pregnancy or while breastfeeding');
  expect(html).not.toMatch(/data-lld-bmi|data-lld-band|kg\/m|Obesity|Overweight|Healthy weight/);
  expect(html).not.toContain('lld_hw[activity_level]');
});

test('TC-FR3-97-AS-05 skipping the waist shows the BMI alone; the waist result appears once it is added (source)', () => {
  const without = run(`$f = ${phpFacts(facts())}; return LLD_Members_Hw_Where_Screen::result_html(LLD_Members_Hw_Where_Screen::where(7, $f, []), []);`, base);
  expect(without).toContain('data-lld-bmi="30.1"');
  expect(text(without)).toContain('Obesity (class 1)');
  expect(without).toContain('data-lld-waist="none"');
  expect(text(without)).toContain('Add your waist above for a fuller picture');
  expect(without).not.toContain('data-lld-whtr');
  const withWaist = run(`$f = ${phpFacts(facts({ waist_cm: 98 }))}; return LLD_Members_Hw_Where_Screen::result_html(LLD_Members_Hw_Where_Screen::where(7, $f, []), []);`, base);
  expect(withWaist).toContain('data-lld-waist="shown"');
  expect(withWaist).toMatch(/data-lld-whtr><strong>0\.60<\/strong>/);
  expect(text(withWaist)).toContain('Your waist is well over half your height');
  // The range in T-HW-NUM-02's words, and the bands in one neutral style.
  expect(text(withWaist).replace(/ \./g, '.')).toContain('A healthy weight range for your height: 49.1 to 66.2 kg. A guide for your height, not a target. Health is about more than one number.');
  const css = readFileSync(resolve(plugin, 'assets/stage3.css'), 'utf8');
  const band = css.match(/\.lld-hw-band \{[^}]+\}/)[0];
  expect(band).not.toMatch(/#(?:f3bcbb|9fd5b6|e3f4ec|fdeeee|c0392b|157a42|0f5c31)|red|green|mint/i);
  expect(withWaist).not.toMatch(/lld-hw-band[^"]*(?:obese|healthy|over|high)/);
});

test('hw screens: the background question shows only when the profile does not set the bands, with the signed reason line', () => {
  const ask = run(`$f = ${phpFacts(facts())}; $w = LLD_Members_Hw_Where_Screen::where(7, $f, []); return [$w['ask_background'], LLD_Members_Hw_Where_Screen::fields_html($w, [], $f)];`, base);
  expect(ask[0]).toBe(true);
  expect(text(ask[1])).toContain('Your family background');
  expect(text(ask[1])).toContain('so this sets which ranges we use');
  expect((ask[1].match(/name="lld_hw_bg"/g) || []).length).toBe(12);
  expect(ask[1]).toContain('data-lld-show-when="lld_hw_bg:Mixed"');
  const answered = run(`$f = ${phpFacts(facts())}; $a = ['bmi_background' => 'Indian']; $w = LLD_Members_Hw_Where_Screen::where(7, $f, $a); return [$w['ask_background'], $w['adjusted'], LLD_Members_Hw_Where_Screen::fields_html($w, $a, $f)];`, base);
  expect(answered[0]).toBe(false);
  expect(answered[1]).toBe(true);
  expect(answered[2]).not.toContain('lld_hw_bg');
  expect(text(answered[2])).toContain('How active are you in a normal week?');
});

test('TC-FR3-98-AS-01 the eight questions follow the goal in order, each with its reason line (source)', () => {
  const r = run(`$f = ${phpFacts(facts())}; return [array_keys(LLD_Members_Hw_Questions_Screen::joining_questions([])), LLD_Members_Hw_Questions_Screen::html(7, $f, 'joining', '/x', '')];`, base);
  expect(r[0]).toEqual(['reason', 'barrier', 'time', 'work', 'wake_time', 'confidence', 'sleep', 'stress', 'alcohol_freq', 'alcohol_amount']);
  const html = r[1];
  expect(text(html)).toContain('Eight quick questions');
  for (const k of ['reason', 'barrier', 'time', 'work', 'wake_time', 'confidence', 'sleep', 'stress', 'alcohol_freq', 'alcohol_amount']) {
    expect(html).toContain(`data-lld-question="${k}"`);
    expect(text(html)).toContain(questions[k].why.replace(/'/g, "'"));
  }
  // Each reason line is tied to its question for screen readers.
  expect((html.match(/aria-describedby="lld-hw-[a-z-]+-why"/g) || []).length).toBe(10);
  // The goal setting puts where you are now first, then the goal, then the questions, then the pace.
  const goal = read('class-goal-setting.php');
  expect(goal).toMatch(/LLD_Members_Hw_Where_Screen::result_html\( \$where, \$answers \) \. LLD_Members_Hw_Where_Screen::fields_html/);
  expect(goal).toMatch(/'&hw=questions'/);
  expect(goal).toMatch(/\$base \. '&hw=pace'/);
});

test('TC-FR3-98-AS-02 the sleep, stress and alcohol questions offer exactly the approved choices, in order (source)', () => {
  const html = run(`$f = ${phpFacts(facts())}; return LLD_Members_Hw_Questions_Screen::html(7, $f, 'joining', '/x', '');`, base);
  const values = (name) => [...html.matchAll(new RegExp(`name="lld_hw\\[${name}\\]" value="([^"]+)"`, 'g'))].map((m) => m[1]);
  expect(values('sleep')).toEqual(['under_6', '6_7', '7_9', 'over_9']);
  expect(values('stress')).toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
  expect(values('alcohol_freq')).toEqual(['never', 'less_than_weekly', '1_2_days', '3_4_days', 'most_days']);
  expect(html).not.toMatch(/name="lld_hw\[stress\]" value="\d+" checked/); // starts unset
});

test('TC-FR3-98-AS-07 the drinks-a-time tap shows only after an answer other than never, with three pictured drinks (source)', () => {
  const html = run(`$f = ${phpFacts(facts())}; return LLD_Members_Hw_Questions_Screen::html(7, $f, 'joining', '/x', '');`, base);
  expect(html).toContain('data-lld-question="alcohol_amount" data-lld-show-when="lld_hw[alcohol_freq]:!never"');
  expect(html).toContain('data-lld-drink-pictures="3"');
  expect((html.match(/<svg/g) || []).length).toBe(3);
  expect([...html.matchAll(/name="lld_hw\[alcohol_amount\]" value="([^"]+)"/g)].map((m) => m[1])).toEqual(['1_2', '3_4', '5_plus']);
  expect(html).toContain('data-lld-question="wake_time" data-lld-show-when="lld_hw[work]:shifts,nights,irregular"');
  // Saving: "never" drops the drinks-a-time answer and a regular day drops the wake-up time.
  const writes = run(`$_POST['lld_hw'] = ['alcohol_freq' => 'never', 'alcohol_amount' => '3_4', 'work' => 'days', 'wake_time' => '06:00', 'stress' => '11', 'sleep' => '7_9', 'reason' => 'wedding', 'event_date' => '2027-05-01'];
    LLD_Members_Hw_Questions_Screen::save(7); return $GLOBALS['writes'];`, base);
  expect(writes).toEqual([['set', 'reason', 'wedding'], ['set', 'event_date', '2027-05-01'], ['set', 'work', 'days'], ['set', 'sleep', '7_9'], ['set', 'alcohol_freq', 'never']]);
  // The JavaScript hides and disables a follow-up until its answer is chosen.
  const js = readFileSync(resolve(plugin, 'assets/stage3.js'), 'utf8');
  expect(js).toMatch(/data-lld-show-when/);
  expect(js).toMatch(/box\.hidden = !show/);
});

test('hw screens: the alcohol questions and the "Drinking" barrier are skipped for halal food rules, and never is recorded', () => {
  const fx = { ...base, answers: { food_rules: ['halal'] } };
  const r = run(`$f = ${phpFacts(facts())}; $_POST['lld_hw'] = ['barrier' => 'alcohol', 'time' => 'under_10'];
    $html = LLD_Members_Hw_Questions_Screen::html(7, $f, 'joining', '/x', ''); LLD_Members_Hw_Questions_Screen::save(7);
    return [$html, $GLOBALS['writes']];`, fx);
  expect(r[0]).not.toContain('data-lld-question="alcohol_freq"');
  expect(r[0]).not.toContain('data-lld-question="alcohol_amount"');
  expect(r[0]).not.toContain('value="alcohol"');
  expect(r[1]).toEqual([['set', 'time', 'under_10'], ['set', 'alcohol_freq', 'never']]);
});

test('hw screens: a question that is not loaded is left out; with none loaded the joining goes straight to the pace', () => {
  const some = { ...base, questions: { time: questions.time, sleep: questions.sleep, alcohol_amount: questions.alcohol_amount } };
  expect(run('return array_keys(LLD_Members_Hw_Questions_Screen::joining_questions([]));', some)).toEqual(['time', 'sleep']);
  expect(run('return LLD_Members_Hw_Questions_Screen::joining_questions([]);', { ...base, questions: {} })).toEqual([]);
  expect(read('class-goal-setting.php')).toMatch(/if \( ! LLD_Members_Hw_Questions_Screen::joining_questions\( \$parts::answers\( \$member_id \) \) \) \{\s+wp_safe_redirect\( home_url\( \$base \. '&hw=pace' \) \);/);
});

test('TC-FR3-99-AS-02 Today shows at most two quick questions a day (source)', () => {
  const html = run(`return LLD_Members_Hw_Questions_Screen::qod_html(['eating_pattern', 'snack_time', 'sugary_drinks']);`, base);
  expect(html).toContain('data-lld-qod="2"');
  expect((html.match(/class="lld-form lld-hw-qod-form"/g) || []).length).toBe(2);
  expect(text(html)).toContain('Quick question');
  expect(html).not.toContain('sugary_drinks');
  expect(html).toContain('value="skip"');
});

test('TC-FR3-100-AS-02 a confident member with time can choose 0.75 kg a week, never more than the safe limit (source)', () => {
  const r = run(`$f = ${phpFacts(facts())}; $v = json_decode(${JSON.stringify(JSON.stringify(planView))}, true);
    $html = LLD_Members_Hw_Plan_Screen::pace_html($v, $f, '/x');
    $goal = ['start_value' => 80, 'target_value' => 60];
    $_POST['lld_hw_pace'] = '1'; $refused = LLD_Members_Hw_Plan_Screen::save_pace(7, $v, $f, '2026-11-10', $goal);
    $again = LLD_Members_Hw_Plan_Screen::pace_html($v, $f, '/x', $refused);
    $_POST['lld_hw_pace'] = '0.75'; $ok = LLD_Members_Hw_Plan_Screen::save_pace(7, $v, $f, '2026-11-10', $goal);
    return [$html, is_wp_error($refused) ? [$refused->get_error_message(), $refused->get_error_data()] : 'saved', $ok, $GLOBALS['writes'], $again];`, base);
  const [html, [refused, data], ok, writes, again] = r;
  expect(text(html)).toContain('You could go a little faster: 0.75 kg a week');
  expect(text(html)).toContain('Because you feel confident, have time and are sleeping well. A pace you can keep beats a fast start. You can change it.');
  expect([...html.matchAll(/name="lld_hw_pace" value="([^"]+)"/g)].map((m) => m[1])).toEqual(['0.25', '0.5', '0.75', 'date']);
  expect(html).toMatch(/value="0\.75" checked/);
  // The signed P3-56 refusal: {cap} is the member's rate_cap() (1 % of 80 kg), {safe date} the goal reached at that cap.
  expect(refused).toBe("That's faster than the safe rate of about 0.8 kg a week. At a safe rate you'd reach 60 kg by about Tuesday 4 May 2027. Shall we use that date?");
  expect(data).toEqual({ code: 'rate_cap', date: '2027-05-04', by_date: false });
  expect(again).toContain('data-lld-refused="rate_cap"');
  expect(again).toMatch(/id="lld_date" name="lld_date" type="date" value="2027-05-04"/);
  // "Or pick a date instead" (FR3-100): the goal screen's own choice and date field.
  expect(html).toMatch(/name="lld_hw_pace" value="date"> By a date instead/);
  expect(html).toContain('<label for="lld_date">Date to reach it (optional)</label>');
  expect(ok).toBe(true);
  expect(writes).toEqual([['join', { pace: 0.75, today: '2026-11-10' }]]);
  // The milestones in T-HW-NUM-04's words.
  expect(text(html)).toContain('66.2 kg · Into the healthy range · around 23 February 2027');
  // A date instead goes to P2 with the date; a date the signed timeline refuses comes back as the same refusal.
  const d = run(`$f = ${phpFacts(facts())}; $v = json_decode(${JSON.stringify(JSON.stringify(planView))}, true); $goal = ['start_value' => 80, 'target_value' => 60];
    $_POST['lld_hw_pace'] = 'date'; $_POST['lld_date'] = '2027-01-01'; $r = LLD_Members_Hw_Plan_Screen::save_pace(7, $v, $f, '2026-11-10', $goal);
    return [$GLOBALS['writes'], $r->get_error_data(), LLD_Members_Hw_Plan_Screen::pace_html($v, $f, '/x', $r)];`, { ...base, join_refuses_date: true });
  expect(d[0]).toEqual([['join', { target_date: '2027-01-01', today: '2026-11-10' }]]);
  expect(d[1]).toEqual({ code: 'rate_cap', date: '2027-05-04', by_date: true });
  expect(d[2]).toMatch(/name="lld_hw_pace" value="date" checked/);
  expect(d[2]).toMatch(/value="2027-05-04"/);
  expect(text(html)).toContain('60.0 kg · Your goal · around 13 April 2027');
});

test('TC-FR3-102-AS-04 the plan shows the signed "Your body uses about {tdee} kcal a day" line with the engine\'s figure (source)', () => {
  const html = run(`$f = ${phpFacts(facts())}; return LLD_Members_Hw_Plan_Screen::plan_html(json_decode(${JSON.stringify(JSON.stringify(planView))}, true), $f);`, base);
  expect(text(html)).toContain("Here's your plan");
  expect(text(html)).toContain('About 1,310 kcal a day');
  expect(text(html)).toContain('Your body uses about 2,130 kcal a day at your activity level. A deficit of about 825 kcal a day gives a loss of about 0.75 kg a week.');
  expect(text(html)).toContain('Your 1,310 kcal a day includes drinks. Your usual week of drinks is about 290 kcal, roughly 40 a day.');
  expect(text(html)).toContain('Why your plan looks like this');
  expect(text(html)).toContain('Added because short sleep makes us hungrier.');
});

test('TC-FR3-102-AS-08 with an eating-disorder flag no calorie number appears on the plan or the Today parts (source)', () => {
  const ed = facts({ situations: ['eating_disorder'] });
  const r = run(`$f = ${phpFacts(ed)}; $v = json_decode(${JSON.stringify(JSON.stringify(planView))}, true);
    return [LLD_Members_Hw_Plan_Screen::plan_html($v, $f), LLD_Members_Hw_Plan_Screen::pace_html($v, $f, '/x'), lld_test_call('LLD_Members_Today_Screen', 'hw_today_html', [7, $f, json_decode(${JSON.stringify(JSON.stringify(todayView))}, true)])];`, { ...base, today: todayView });
  for (const html of r) {
    expect(html).not.toMatch(/kcal|calorie/i);
    expect(html).not.toContain('data-lld-kcal');
  }
  expect(r[2]).not.toContain('data-lld-add-drink');
  expect(r[1]).not.toContain('hw-milestones');
});

test('TC-FR3-104-AS-01 after week 8 the suggested block comes first with its one-line reason, and another can be chosen (source)', () => {
  const blocks = { due: true, suggested: { block: 'stress-emotional-eating', reason: { template: 'hw.T-HW-BLOCK-01.reason.stress', values: { n: 8 } } }, choices: ['evening-night-eating', 'stress-emotional-eating', 'getting-stronger', 'alcohol'] };
  const r = run(`$f = ${phpFacts(facts())}; $d = LLD_Members_Hw_Blocks_Screen::data(7, '2026-11-10');
    $html = LLD_Members_Hw_Blocks_Screen::html($d, $f);
    $_POST['lld_hw_block'] = 'getting-stronger'; LLD_Members_Hw_Blocks_Screen::save(7, $d, '2026-11-10');
    $_POST['lld_hw_block'] = 'not-a-block'; $bad = LLD_Members_Hw_Blocks_Screen::save(7, $d, '2026-11-10');
    return [$d, $html, $GLOBALS['writes'], $bad];`, { ...base, blocks });
  const [d, html, writes, bad] = r;
  expect(d.choices.map((c) => c.slug)).toEqual(['stress-emotional-eating', 'evening-night-eating', 'getting-stronger']); // alcohol: no approved content loaded
  expect(d.because).toBe('Suggested for you, because you rated your stress 8 out of 10.');
  expect(html).toMatch(/data-lld-block="stress-emotional-eating"><label><input type="radio" name="lld_hw_block" value="stress-emotional-eating" checked aria-describedby="lld-hw-block-why"/);
  expect(text(html)).toContain('Choose your next four weeks');
  expect(writes).toEqual([['choose_block', 'getting-stronger']]);
  expect(bad).toBe(null);
});

test('TC-FR3-104-AS-05 a member who reaches the goal during a block is offered Keep It Off (source)', () => {
  const html = run(`$f = ${phpFacts(facts())}; return LLD_Members_Hw_Blocks_Screen::html(LLD_Members_Hw_Blocks_Screen::data(7, '2026-11-10'), $f);`, { ...base, blocks: { keep_it_off: true, choices: [] } });
  expect(html).toContain('data-lld-section="hw-keep-it-off"');
  expect(html).toMatch(/<button class="lld-button" type="submit" name="lld_next" value="keep">Start Keep It Off<\/button>/);
  expect(html).toContain('name="lld_reached_nonce"');
});

test('TC-FR3-105-AS-01 Today shows the day\'s note with its "Try this today" line, and the urgent-help line for support wording (source)', () => {
  const r = run(`$f = ${phpFacts(facts())}; $t = json_decode(${JSON.stringify(JSON.stringify(todayView))}, true);
    $plain = lld_test_call('LLD_Members_Today_Screen', 'hw_today_html', [7, $f, $t]);
    $t['note']['support'] = true; $support = lld_test_call('LLD_Members_Today_Screen', 'hw_today_html', [7, $f, $t]);
    return [$plain, $support];`, { ...base, today: todayView });
  const [plain, support] = r;
  expect(plain).toContain('data-lld-note="HW-MN-W1-3"');
  expect(text(plain)).toContain('One-minute note Small beats big');
  expect((plain.match(/<section class="lld-card lld-hw-note"[\s\S]*?<\/section>/)[0].match(/<p>/g) || []).length).toBe(2);
  expect(text(plain)).toContain('Try this today: shrink one goal');
  expect(plain).not.toContain('data-lld-urgent-help');
  expect(support).toContain('data-lld-urgent-help');
  expect(support).toMatch(/find urgent help in your country at <a href="https:\/\/findahelpline\.com" target="_blank" rel="noopener">findahelpline\.com<\/a>/);
  // The evening "Add a drink" (R-HW-ALC-02) and the food card.
  expect(plain).toMatch(/data-lld-add-drink>Add a drink</);
});

test('TC-FR3-105-AS-04 the note wraps on a 360-pixel screen: no fixed widths, long words break (source)', () => {
  const css = readFileSync(resolve(plugin, 'assets/stage3.css'), 'utf8');
  expect(css).toMatch(/\.lld-hw-note,\s*\.lld-hw-note p \{\s*overflow-wrap: anywhere;/);
  const from = css.indexOf('HEALTHY WEIGHT PROGRAMME');
  const next = css.indexOf('/* =================', from); // the Healthy Weight block only, up to the next screen's block
  const hw = css.slice(from, next > -1 ? next : undefined);
  expect(hw).not.toMatch(/(?:^|[^-])width:\s*\d{3,}px/m);
  expect(hw).not.toMatch(/white-space:\s*nowrap/);
});

test('TC-FR3-107-AS-01 the check-in asks "What got in the way?" with the seven signed choices, optional, and shows the signed replies (source)', () => {
  const r = run(`$html = lld_test_call('LLD_Members_Today_Screen', 'hw_way_html', [['goal_type' => 'lose_weight']]);
    $other = lld_test_call('LLD_Members_Today_Screen', 'hw_way_html', [['goal_type' => 'sleep']]);
    $_GET['way'] = 'family,illness,nothing'; $replies = lld_test_call('LLD_Members_Today_Screen', 'hw_way_replies_html', []);
    $_POST['lld_hw_way'] = ['stress', 'bogus', 'time']; $posted = lld_test_call('LLD_Members_Today_Screen', 'hw_way_posted', []);
    return [$html, $other, $replies, $posted];`, base);
  const [html, other, replies, posted] = r;
  expect(text(html)).toContain('What got in the way?');
  expect([...html.matchAll(/name="lld_hw_way\[\]" value="([^"]+)"> ([^<]+)</g)].map((m) => [m[1], m[2]])).toEqual([['time', 'Time or work'], ['family', 'Family'], ['cravings', 'Cravings'], ['stress', 'Stress'], ['social', 'Social events'], ['illness', 'Illness'], ['nothing', 'Nothing']]);
  expect(html).not.toMatch(/required/);
  expect(other).toBe('');
  expect(text(replies)).toContain("Family life can make it harder. Next week's note has ideas for getting the people you live with on side.");
  expect(text(replies)).toContain("We hope you're feeling better.");
  expect(text(replies)).not.toContain('Good week.'); // the first two replies only
  expect(posted).toEqual(['time', 'stress']);
  // Skipping it still completes the check-in: the barrier is recorded only when something was chosen.
  expect(read('class-today-screen.php')).toMatch(/\$way = self::hw_way_posted\(\);\s+if \( \$way && class_exists/);
});

test('TC-FR3-109-AS-04 stress 9 or short sleep: the signed support card with its three buttons (source)', () => {
  const view = { ...planView, cards: [{ rule: 'R-HW-SAFE-03', template: 'hw.T-HW-SAFE-03', text: 'Poor sleep and high stress are hard on anyone. If this goes on for more than two weeks, talk to your doctor. You can try the Sleep and stress check, or add Sleep and Stress as your extra goal. [Sleep and stress check] [Add Sleep and Stress] [Not now]' }] };
  const html = run(`$f = ${phpFacts(facts())}; return LLD_Members_Hw_Plan_Screen::plan_html(json_decode(${JSON.stringify(JSON.stringify(view))}, true), $f);`, base);
  const card = html.match(/<section class="lld-card lld-hw-support"[\s\S]*?<\/section>/)[0];
  expect(card).toContain('data-lld-card="R-HW-SAFE-03"');
  expect(text(card)).toContain('If this goes on for more than two weeks, talk to your doctor.');
  expect(text(card)).not.toContain('[');
  expect(card).toMatch(/href="https:\/\/stg\.example\.test\/health-tools\/sleep-and-stress-check\/" data-lld-card-action="sleep-check">Sleep and stress check</);
  expect(card).toMatch(/data-lld-card-action="add-sleep-stress">Add Sleep and Stress</);
  expect(card).toMatch(/data-lld-hw-dismiss>Not now</);
});

test('TC-FR3-109-AS-05 "Add Sleep and Stress" opens the Sleep and Stress safety questions before the track (source)', () => {
  const html = run(`return LLD_Members_Hw_Screen_Parts::cards_html([['rule' => 'R-HW-SAFE-03', 'template' => 'hw.T-HW-SAFE-03']]);`, base);
  expect(html).toMatch(/href="https:\/\/stg\.example\.test\/members\/screening\/\?track=sleep-stress" data-lld-card-action="add-sleep-stress"/);
});

test('TC-FR3-110-AS-06 heavy drinking: the drinking card with the doctor wording, and no weight or calorie message beside it (source)', () => {
  const view = { ...planView, cards: [{ rule: 'R-HW-SAFE-05', template: 'hw.T-HW-SAFE-05', text: null }] };
  for (const premium of [false, true]) {
    const html = run(`$f = ${phpFacts(facts({ premium }))}; return LLD_Members_Hw_Plan_Screen::plan_html(json_decode(${JSON.stringify(JSON.stringify(view))}, true), $f);`, base);
    const card = html.match(/<section class="lld-card lld-hw-support"[\s\S]*?<\/section>/)[0];
    expect(text(card)).toContain('If you drink heavily every day, talk to your doctor before you cut down, because stopping suddenly can be dangerous.');
    expect(card).toMatch(/<a class="lld-button lld-button-secondary" href="https:\/\/findahelpline\.com" target="_blank" rel="noopener" data-lld-help-link>Where to get help<\/a>/);
    expect(card).toMatch(/data-lld-hw-dismiss>Not now</);
    expect(html).not.toMatch(/kcal|calorie|data-lld-milestone|lose|loss/i);
  }
});

test('hw screens: the milestone celebration uses the signed line and its approved health line; badges never show kilograms', () => {
  const r = run(`$f = ${phpFacts(facts({ age: 70 }))};
    $healthy = lld_test_call('LLD_Members_Progress_Page', 'celebration_html', [['kind' => 'healthy', 'template' => 'hw.T-HW-MOT-02.healthy', 'text' => LLD_Members_Rule_Catalogue::fill('hw.T-HW-MOT-02.healthy', [])], $f]);
    $goal = lld_test_call('LLD_Members_Progress_Page', 'celebration_html', [['kind' => 'goal', 'text' => LLD_Members_Rule_Catalogue::fill('hw.T-HW-MOT-02.goal', ['kg' => '60.0'])], $f]);
    return [$healthy, $goal];`, base);
  expect(text(r[0])).toContain("You're now in the healthy weight range for your height.");
  expect(text(r[0])).toContain('At 65 and over, keeping your muscle strength and staying active matter more than a number.');
  expect(text(r[1])).toContain("You've reached your goal of 60.0 kg. Next, we'll help you keep it there.");
  expect(text(r[1])).toContain('Keeping your weight steady from here helps you hold on to the health benefits');
  expect(r[1]).toMatch(/value="keep">Start Keep It Off</);
  expect(text(r[1])).not.toContain('[');
  // No new badge is made from a milestone: the badge list stays the Stage 2 habit badges, names checked.
  expect(read('class-progress-page.php')).not.toMatch(/badge[^\n]*(?:kg|milestone)/i);
});

test('hw screens: Today puts the approved step title and its signed reason line in the next step, and the reason on Today', () => {
  const r = run(`$t = json_decode(${JSON.stringify(JSON.stringify(todayView))}, true); return [LLD_Members_Hw_Screen_Parts::activity($t['step']['activity_key']), LLD_Members_Hw_Screen_Parts::text_of($t['step']['reason']), LLD_Members_Hw_Screen_Parts::text_of($t['reason_line'])];`, base);
  expect(r[0].title).toBe('Notice your hunger');
  expect(r[1]).toBe('Chosen first because you said evening snacking gets in the way.');
  expect(r[2]).toBe('For your health: 20 weeks to go.');
  const today = read('class-today-screen.php');
  expect(today).toMatch(/if \( '' === \$rule && is_array\( \$hw \)/); // a signed engine rule's step keeps precedence
  expect(today).toMatch(/data-lld-step-reason/);
});

test('hw screens: the account hub lists the answers in the approved wording and links to changing them', () => {
  const hub = read('class-account-hub.php');
  expect(hub).toMatch(/LLD_Members_Goal_Setting::hw_flow\(\) \|\| ! LLD_Members_Account::has_consent\( \$member_id, 'health' \)/);
  expect(hub).toMatch(/home_url\( '\/members\/goal\/\?hw=answers' \)/);
  expect(hub).toMatch(/array_merge\( array\( 'activity_level' \), LLD_Members_Hw_Questions_Screen::JOINING \)/);
  // The edit screen saves the questions and the activity level through P2 only.
  const writes = run(`$f = ${phpFacts(facts())}; $_POST['lld_hw'] = ['activity_level' => 'active', 'time' => 'over_30']; LLD_Members_Hw_Questions_Screen::save(7); LLD_Members_Hw_Where_Screen::save_fields(7); return $GLOBALS['writes'];`, base);
  expect(writes).toEqual([['set', 'time', 'over_30'], ['set', 'activity_level', 'active']]);
});

test('hw screens: every new screen file is one class with no boot(), escapes its output and writes only through P2', () => {
  for (const f of NEW) {
    const src = read(f);
    expect(src, f).not.toMatch(/function boot\(/);
    expect(src, f).not.toMatch(/\$wpdb->(?:insert|update|delete|query)|\bINSERT\b|\bUPDATE\b/);
    expect(src, f).not.toMatch(/add_action|add_filter|add_rewrite_rule/);
  }
  expect(readdirSync(screensDir).filter((f) => f.startsWith('class-hw-')).sort()).toEqual(NEW.slice().sort());
});

test('hw screens: the plateau and goal cards from the plan view keep their own signed buttons', () => {
  const html = run(`return LLD_Members_Hw_Screen_Parts::cards_html([
    ['rule' => 'R-HW-WEEK-02', 'template' => 'hw.T-HW-WEEK-02', 'text' => LLD_Members_Rule_Catalogue::text('hw.T-HW-WEEK-02')],
    ['rule' => 'R-HW-MOT-02', 'template' => 'hw.T-HW-MOT-02.goal', 'text' => LLD_Members_Rule_Catalogue::fill('hw.T-HW-MOT-02.goal', ['kg' => '60.0'])]]);`, base);
  // Start 'Breaking a plateau' chooses that block; Keep my current plan closes the card; Start Keep It Off is the
  // Goal reached choice. None of them is mistaken for "Not now".
  expect(html).toMatch(/name="lld_hw_block" value="breaking-a-plateau"><button class="lld-button" type="submit" name="lld_action" value="hw_block">Start &#0?39;Breaking a plateau&#0?39;<\/button>/);
  expect(html).toMatch(/data-lld-hw-dismiss>Keep my current plan</);
  expect(html).toMatch(/value="keep">Start Keep It Off<\/button>/);
  expect((html.match(/data-lld-hw-dismiss/g) || []).length).toBe(1);
});

test('hw screens: a follow-up that is one of the eight is shown once, and the quick questions asked together share one card', () => {
  // The loader nests the wake-up time under work and the drinks a time under alcohol; the screen shows each once, in
  // its own place, so no id or field appears twice.
  const nested = { ...base, questions: { ...questions,
    work: { ...questions.work, follow_up: { key: 'wake_time', text: 'Nested wake-up?', type: 'time', when: ['shifts'] } },
    alcohol_freq: { ...questions.alcohol_freq, follow_up: { key: 'alcohol_amount', text: 'Nested amount?', choices: [{ value: '1_2', label: '1 to 2' }], when: ['most_days'] } },
    food_rules: { text: 'A made-up food rules question?', why: 'A made-up reason.', multi: true, choices: [{ value: 'halal', label: 'Test rule' }] },
    festivals: { text: 'A made-up fasts question?', why: 'A made-up reason.', multi: true, choices: [{ value: 'CAL-RAM', label: 'Ramadan' }] },
    allergies: { text: 'A made-up allergies question?', why: 'A made-up reason.', multi: true, choices: [{ value: 'gluten', label: 'Test allergen' }] } } };
  const r = run(`$f = ${phpFacts(facts())};
    $html = LLD_Members_Hw_Questions_Screen::html(7, $f, 'joining', '/x', '');
    $qod = LLD_Members_Hw_Questions_Screen::qod_html([['key' => 'food_rules_allergies'], ['key' => 'snack_time'], ['key' => 'sugary_drinks']]);
    $_POST['lld_hw_qod_key'] = 'food_rules_allergies'; $_POST['lld_hw'] = ['festivals' => ['CAL-RAM']];
    $saved = LLD_Members_Hw_Questions_Screen::save_qod(7);
    return [$html, $qod, $saved, $GLOBALS['writes']];`, nested);
  const [html, qod, saved, writes] = r;
  expect((html.match(/id="lld-hw-wake-time"/g) || []).length).toBe(1);
  expect((html.match(/data-lld-question="alcohol_amount"/g) || []).length).toBe(1);
  expect(html).not.toContain('Nested');
  const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]);
  expect(ids.length).toBe(new Set(ids).size);
  // food_rules_allergies is one quick question of three approved questions; two quick questions a day in all.
  expect(qod).toContain('data-lld-qod="2"');
  expect(qod).toMatch(/data-lld-qod-key="food_rules_allergies"[\s\S]*data-lld-question="food_rules"[\s\S]*data-lld-question="festivals"[\s\S]*data-lld-question="allergies"/);
  expect(qod).not.toContain('sugary_drinks');
  expect(saved).toBe(true);
  expect(writes).toEqual([['set', 'festivals', ['CAL-RAM']]]);
  // P2 names the group by its first question (food_rules): the same three questions come together.
  const byFirst = run(`return LLD_Members_Hw_Questions_Screen::qod_html([['key' => 'food_rules']]);`, nested);
  expect(byFirst).toMatch(/data-lld-qod-key="food_rules"[\s\S]*data-lld-question="festivals"[\s\S]*data-lld-question="allergies"/);
});

test('hw screens: the track check-in on the weekly check-in page gets its own field ids, so no id is used twice', () => {
  const r = run(`return lld_test_call('LLD_Members_Today_Screen', 'track_ids', ['<p><label for="lld_weight">W</label><input id="lld_weight" name="lld_weight" aria-describedby="lld_weight-hint"></p><input id="lld_track_nonce" name="lld_track_nonce" data-x="lld_weight">']);`, base);
  expect(r).toBe('<p><label for="lld-track-weight">W</label><input id="lld-track-weight" name="lld_weight" aria-describedby="lld-track-weight-hint"></p><input id="lld-track-track_nonce" name="lld_track_nonce" data-x="lld_weight">');
});
