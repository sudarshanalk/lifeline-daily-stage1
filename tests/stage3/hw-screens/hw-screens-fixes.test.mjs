// Healthy Weight screens after the P3-35 review (package P4), without a browser: the SCR-26 stop at any time with no
// number, the Check's own eating-disorder question asked once in its approved wording, and "Your pace" while the pace
// is still to choose. Stand-ins for P2 and P3 answer from fixture arrays (wp-stubs.php); every value is made up.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { activities, blocksContent, planView, questions, todayView } from './fixture.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../..');
const stubs = JSON.stringify(resolve(here, 'wp-stubs.php').split('\\').join('/'));
function php(code, fx = {}) {
  return execFileSync('php', ['-d', 'display_errors=stderr', '-r', `require ${stubs}; ${code}`], { input: JSON.stringify(fx), env: { ...process.env, LLD_HW_STUBS: '1' }, encoding: 'utf8', timeout: 60000 }).trim();
}
const run = (code, fx) => JSON.parse(php(`echo json_encode((function () { ${code} })());`, fx));
const base = { questions, blocks_content: blocksContent, activities };
const facts = (extra = {}) => ({ member_id: 7, age: 45, imperial: false, today: '2026-11-10', health: true, premium: true, sex: 'female', height_cm: 163, weight_kg: 80, waist_cm: null, activity: 'some', situations: [], ...extra });
const phpJson = (x) => `json_decode(${JSON.stringify(JSON.stringify(x))}, true)`;
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&').replace(/\s+/g, ' ');
const copy = JSON.parse(readFileSync(resolve(root, 'copy/healthy-weight-messages.json'), 'utf8'));

/** What P2 returns once R-HW-SAFE-01 stops the programme (LLD_Members_HW_Programme::stopped_plan, ::stopped_today). */
const stop = (s, key, note) => ({ rule: 'R-HW-SAFE-01', stop: s, screen: 'SCR-26', note_key: key, note });
const stoppedPlan = (st) => ({ ...planView, where: { bmi: null, category: null, adjusted: false, range: null, waist: null }, tdee: null, target_kcal: null, pace: null, drinks: null, milestones: [], cards: [], reasons: [], milestone_reached: null, celebrated: [], stopped: true, stop: st });
const stoppedToday = (st) => ({ note: null, step: null, qod: [], drink_shortcut: { rule: 'R-HW-ALC-02', show: false, label: null, from: null }, repeat_meal: null, reason_line: null, carry_on: false, cards: [], stopped: true, stop: st });

test('TC-FR3-97-AS-04 (after joining): the plan, the pace step and Today show only the SCR-26 stop, with no number, for pregnancy or an eating disorder', () => {
  const cases = [
    ['pregnancy', stop('pregnancy', 'pregnancy.calorie-target', "We don't set calorie targets during pregnancy or breastfeeding. Your midwife can advise on eating well for you and your baby.")],
    ['eating', stop('eating', 'ed.weight-goal-screen', "Weight goals aren't offered, based on your health profile. Choose a goal around activity, sleep, blood pressure or how you feel.")],
  ];
  for (const [s, st] of cases) {
    // The facts carry no flag: the stop comes from P2 alone (reported after joining, or told on the Check's question).
    const r = run(`$f = ${phpJson(facts())}; $v = ${phpJson(stoppedPlan(st))}; $t = ${phpJson(stoppedToday(st))};
      return [LLD_Members_Hw_Plan_Screen::plan_html($v, $f), LLD_Members_Hw_Plan_Screen::pace_html($v, $f, '/x'), lld_test_call('LLD_Members_Today_Screen', 'hw_today_html', [7, $f, $t])];`, base);
    for (const html of r) {
      expect(html, s).toContain(`data-lld-hw-stop="${s}"`);
      expect(text(html), s).toContain(copy[`stops.${s}.title`]);
      expect(text(html), s).toContain(st.note);
      expect(text(html), s).not.toMatch(/\d/); // no number at all
      expect(html, s).not.toMatch(/data-lld-kcal|hw-milestones|name="lld_hw_pace"|data-lld-add-drink/);
    }
  }
});

test('P3-35 "where you are now" asks the Check\'s eating-disorder question once, in its approved wording, and saves the answer', () => {
  const r = run(`
    $ask = LLD_Members_Hw_Where_Screen::fields_html(['ask_background' => false], [], ${phpJson(facts())});
    $answered = LLD_Members_Hw_Where_Screen::fields_html(['ask_background' => false], ['eating_disorder' => 'no'], ${phpJson(facts())});
    $flagged = LLD_Members_Hw_Where_Screen::fields_html(['ask_background' => false], [], ${phpJson(facts({ situations: ['eating_disorder'] }))});
    $_POST['lld_hw_eat'] = 'prefer'; $saved = LLD_Members_Hw_Where_Screen::save_eating(7);
    $_POST['lld_hw_eat'] = 'maybe'; $bad = LLD_Members_Hw_Where_Screen::save_eating(7);
    $where = LLD_Members_Hw_Where_Screen::where(7, ${phpJson(facts())}, ['eating_disorder' => 'yes']);
    return [$ask, $answered, $flagged, $saved, $bad, $GLOBALS['writes'], $where];`, base);
  const [ask, answered, flagged, saved, bad, writes, where] = r;
  expect(text(ask)).toContain(copy['q.eat']);
  expect(text(ask)).toContain(copy['q.eatWhy']);
  for (const [value, label] of JSON.parse(copy['options.eat'])) expect(ask).toContain(`<input type="radio" name="lld_hw_eat" value="${value}"> ${label}`);
  expect(answered).not.toContain('lld_hw_eat');
  expect(flagged).not.toContain('lld_hw_eat');
  expect(saved).toBe('prefer');
  expect(bad).toBeNull();
  expect(writes).toEqual([['set', 'eating_disorder', 'prefer']]);
  expect(where).toEqual({ stop: 'eating' }); // "yes" is the Check's stop, with no number
  // The goal step saves the answer before the goal: "yes" stops there with no weight goal saved.
  const gs = readFileSync(resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/stage3/screens/class-goal-setting.php'), 'utf8');
  expect(gs.indexOf("'yes' === LLD_Members_Hw_Where_Screen::save_eating( $member_id )")).toBeLessThan(gs.indexOf('LLD_Members_Goal_Journey::create_goal( $member_id, $role, $type'));
});

test('P3-35 the eating-disorder answer hides calorie numbers on the screens: "yes" and "prefer not to say"', () => {
  const r = run(`return [LLD_Members_Hw_Screen_Parts::ed(${phpJson(facts())}), LLD_Members_Hw_Plan_Screen::plan_html(${phpJson(planView)}, ${phpJson(facts())})];`, { ...base, answers: { eating_disorder: 'prefer' } });
  expect(r[0]).toBe(true);
  expect(r[1]).not.toContain('data-lld-kcal');
  expect(run(`return LLD_Members_Hw_Screen_Parts::ed(${phpJson(facts())});`, { ...base, answers: { eating_disorder: 'no' } })).toBe(false);
});

test('TC-FR3-100-AS-06 (pace not chosen): Today asks the member to finish choosing a pace, with the way back to the pace step', () => {
  const r = run(`return [LLD_Members_Today_Screen::hw_pace_pending_html(7)];`, { ...base, pending_pace: 12 });
  expect(r[0]).toContain('data-lld-pace="not-chosen"');
  expect(text(r[0])).toContain('Your pace');
  expect(r[0]).toContain('href="https://stg.example.test/members/goal/?done=12&amp;hw=pace"');
  expect(run(`return LLD_Members_Today_Screen::hw_pace_pending_html(7);`, base)).toBe('');
});

test('TC-FR3-100-AS-05 (prefer not to say, pace step): the refusal names 0.5 and the safe date at 0.5; other members keep their own cap', () => {
  const goal = { start_value: 80, target_value: 60 };
  const call = `return LLD_Members_Hw_Plan_Screen::refusal(${phpJson(planView)}, ${phpJson(facts())}, '2026-11-12', ${phpJson(goal)});`;
  expect(run(call, { ...base, answers: { eating_disorder: 'prefer' } })).toEqual({
    text: "That's faster than the safe rate of about 0.5 kg a week. At a safe rate you'd reach 60 kg by about Thursday 19 August 2027. Shall we use that date?", date: '2027-08-19', cap: 0.5,
  });
  expect(run(call, { ...base, answers: { eating_disorder: 'no' } })).toMatchObject({ date: '2027-05-06', cap: 0.8 });
});
