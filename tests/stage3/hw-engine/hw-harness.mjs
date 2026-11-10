// Shared harness for the Healthy Weight programme engine tests (P3-35). The pure PHP classes run in PHP CLI without
// WordPress, as tests/stage3/decision-engine.test.mjs runs the decision engine; the fixtures in
// fixtures/healthy-weight-programme/ are replayed through `replay()` (each fixture names the function it calls and the
// values it expects, as paths into the result). Not a test file itself.
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const root = resolve(here, '../../..');
export const stage3 = resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/stage3');
export const hwDir = resolve(stage3, 'healthy-weight');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const classes = ['engine/class-rule-catalogue.php', 'engine/class-decision-engine.php', 'calculators/class-calorie-target.php', 'calculators/class-goal-timeline.php',
  'healthy-weight/class-hw-where.php', 'healthy-weight/class-hw-festival.php', 'healthy-weight/class-hw-rules.php'];
const preamble = `define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400); ${classes.map((c) => `require ${toPhpPath(resolve(stage3, c))};`).join(' ')}`;
/** Run PHP code; `input` (a string) is on standard input, so long argument lists stay off the command line. */
export const php = (code, input = '') => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${preamble} ${code}`], { input, encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
export const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));

export const track = json('fixtures/healthy-weight-programme/track-content.json').activities;
/** The approved default order of the 56 mindset notes: HW-MN-Wn-k on programme day 7 × (n − 1) + k. */
export const defaultNotes = Array.from({ length: 56 }, (_, i) => `HW-MN-W${Math.floor(i / 7) + 1}-${(i % 7) + 1}`);

// PHP functions the fixtures may call, with how their arguments are passed.
const CALLS = {
  eligibility: 'LLD_Members_HW_Rules::eligibility($a)',
  pace: 'LLD_Members_HW_Rules::pace($a)',
  support_cards: 'LLD_Members_HW_Rules::support_cards($a)',
  drinks: 'LLD_Members_HW_Rules::drinks($a)',
  drink_shortcut: 'LLD_Members_HW_Rules::drink_shortcut($a)',
  target: 'LLD_Members_HW_Rules::target($a)',
  milestones: 'LLD_Members_HW_Rules::milestones($a)',
  milestone_reached: 'LLD_Members_HW_Rules::milestone_reached($a)',
  week_plan: 'LLD_Members_HW_Rules::week_plan($a)',
  layout: 'LLD_Members_HW_Rules::layout($a)',
  check_in: 'LLD_Members_HW_Rules::check_in($a)',
  note_for_day: 'LLD_Members_HW_Rules::note_for_day($a)',
  plateau: 'LLD_Members_HW_Rules::plateau($a)',
  suggest_block: 'LLD_Members_HW_Rules::suggest_block($a)',
  reminder_times: 'LLD_Members_HW_Rules::reminder_times($a)',
  qod: 'LLD_Members_HW_Rules::qod($a)',
  food_ok: 'LLD_Members_HW_Rules::food_ok($a["item"], $a["member"])',
  food_filter: 'LLD_Members_HW_Rules::food_filter($a["items"], $a["member"], isset($a["meal_type"]) ? $a["meal_type"] : null)',
  meal_timing: 'LLD_Members_HW_Rules::meal_timing($a)',
  motivation: 'LLD_Members_HW_Rules::motivation($a)',
  where_now: 'LLD_Members_HW_Where::where_now($a)',
  assess: 'LLD_Members_HW_Where::assess($a)',
  validate: 'LLD_Members_HW_Where::validate($a)',
  range: 'LLD_Members_HW_Where::range($a["height_cm"], !empty($a["adjusted"]))',
  background_group: "['group' => LLD_Members_HW_Where::background_group($a['ethnicity'], $a['answer'])]",
  schedule: 'LLD_Members_HW_Festival::schedule($a)',
  offer: 'LLD_Members_HW_Festival::offer($a)',
};

/** Replace the fixtures' placeholders: '@track' (the 56 activities), '@default_from:n' (the note order from day n). */
function expand(v) {
  if (v === '@track') return track;
  if (typeof v === 'string' && v.startsWith('@default_from:')) return defaultNotes.slice(Number(v.split(':')[1]) - 1);
  if (Array.isArray(v)) return v.map(expand);
  if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, expand(x)]));
  return v;
}

/** Run many [fn, args] pairs in one PHP process. */
export function call(pairs) {
  const known = pairs.map(([fn]) => { if (!CALLS[fn]) throw new Error(`no PHP call ${fn}`); return fn; });
  const body = Object.entries(CALLS).filter(([k]) => known.includes(k)).map(([k, expr]) => `case ${JSON.stringify(k)}: return ${expr};`).join(' ');
  const input = JSON.stringify(pairs.map(([fn, a]) => [fn, expand(a)]));
  return JSON.parse(php(`echo json_encode(array_map(function ($c) { $a = $c[1]; switch ($c[0]) { ${body} } }, json_decode(stream_get_contents(STDIN), true)));`, input));
}
export const one = (fn, args) => call([[fn, args]])[0];

/** A value at a dotted path ('days.2.id', 'milestones.length'); null for a missing one. */
export function at(obj, path) {
  let v = obj;
  for (const part of String(path).split('.')) {
    if (v === null || v === undefined) return undefined;
    if (part === 'length' && Array.isArray(v)) { v = v.length; continue; }
    v = Array.isArray(v) ? v[Number(part)] : v[part];
  }
  return v;
}

// ---- Composite runs (several calls), for the fixtures that describe a whole plan ------------------------------------

/** Weeks 1 to 8 of a plan, summarised as the step fixtures state it. */
export function weeks(args) {
  const plans = call(Array.from({ length: 8 }, (_, i) => ['week_plan', { ...args, week: i + 1 }]));
  const [drinks] = call([['drinks', args.answers]]);
  const by = (rule) => plans.filter((p) => p.days.some((d) => d.rule === rule)).map((p) => p.week);
  const alc = plans.flatMap((p) => p.days.filter((d) => d.rule === 'R-HW-ALC-01'));
  return {
    plans,
    sleep_weeks: by('R-HW-STEP-04'),
    stress_weeks: by('R-HW-STEP-05'),
    alcohol_weeks: plans.filter((p) => p.days.some((d) => d.rule === 'R-HW-ALC-01' && d.day === 5)).map((p) => p.week),
    alcohol_days: [...new Set(alc.map((d) => d.day))],
    alcohol_ids: [...new Set(alc.map((d) => d.id))],
    one_step_a_day: plans.every((p) => p.days.length === 7 && p.days.every((d) => typeof d.id === 'string' && d.id !== '')),
    walk_minutes: Object.fromEntries(plans.map((p) => [p.week, p.exercise.walk_minutes])),
    strength_per_week: Object.fromEntries(plans.map((p) => [p.week, p.exercise.strength_per_week])),
    support_card: drinks.support_card,
    all_ids: plans.flatMap((p) => p.days.map((d) => d.id)),
  };
}

/** Days 1 to n of the mindset notes, each day's result fed into the next (FR3-105, FR3-106). */
export function noteSequence({ start, days, answers = {}, extra = {} }) {
  let queue = defaultNotes.slice();
  let pending = extra.pending || [];
  const shown = {};
  const out = [];
  for (let d = 1; d <= days; d++) {
    const date = addDays(start, d - 1);
    const perDay = typeof extra.perDay === 'function' ? extra.perDay(d, date) : {};
    const r = one('note_for_day', { date, programme_day: d, queue, shown, pending, ...answers, ...perDay });
    out.push(r);
    if (r.note) shown[r.note] = date;
    queue = r.queue; pending = r.pending;
  }
  return { results: out, notes: out.map((r) => r.note) };
}

export function addDays(date, n) {
  const d = new Date(`${date}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10);
}

/** A member's whole joining picture (rule draft section 12). */
export function member({ body, answers, goal_kg: goal, today, snack_time: snack, wake_time: wake }) {
  const [pace] = call([['pace', { ...answers, age: body.age, weight_kg: body.weight_kg, start_kg: body.weight_kg, goal_kg: goal, today }]]);
  const p = pace.recommended;
  const [target, ms, cards, shortcut, rem, block] = call([
    ['target', { sex: body.sex, age: body.age, height_cm: body.height_cm, weight_kg: body.weight_kg, activity_level: answers.activity_level, pace: p }],
    ['milestones', { start_kg: body.weight_kg, goal_kg: goal, height_cm: body.height_cm, adjusted: false, pace: p, today }],
    ['support_cards', { ...answers, occasion: 'joining' }],
    ['drink_shortcut', { ...answers, time: '19:00' }],
    ['reminder_times', { work: answers.work, snack_time: snack, wake_time: wake }],
    ['suggest_block', { ...answers, programme_week: 9, today: addDays(today, 56) }],
  ]);
  const [drinks] = call([['drinks', { ...answers, target: target.target }]]);
  const w = weeks({ answers, activities: '@track', library: [] });
  const notes = noteSequence({ start: today, days: 7, answers: { sleep: answers.sleep, stress: answers.stress } });
  const moved = {};
  notes.results.forEach((r, i) => { if (r.rule) moved[String(i + 1)] = r.note; });
  const strengthWeeks = Object.entries(w.strength_per_week).filter(([, n]) => n > 0).map(([k]) => Number(k));
  return {
    pace, target, drinks, cards,
    milestones: ms.milestones.map((m) => [m.kg, m.weeks, m.date]),
    safe03: cards.cards.some((c) => c.rule === 'R-HW-SAFE-03'),
    shortcut: shortcut.show,
    week1_day3: w.plans[0].days[2].id,
    week3_day1: w.plans[2].days[0].id,
    week1_version: w.plans[0].version,
    sleep_weeks: w.sleep_weeks, stress_weeks: w.stress_weeks,
    walk_minutes: w.walk_minutes,
    strength_from_week: strengthWeeks.length ? strengthWeeks[0] : null,
    notes_week1: Object.keys(moved).length ? moved : 'default',
    reminders: rem.reminders.map((r) => r.time),
    block: block.block,
  };
}

// ---- Projections for result shapes that the fixtures describe more simply ------------------------------------------

function project(fn, args, r) {
  if (fn === 'food_filter') {
    const ids = r.items.map((i) => i.id);
    const all = args.items.map((i) => i.id);
    return { ...r, item_ids: ids, item_lines: Object.fromEntries(r.items.map((i) => [i.id, i.lines])), _all: all };
  }
  if (fn === 'week_plan') return { ...r, ids: r.days.map((d) => d.id) };
  if (fn === 'schedule') {
    const segs = [];
    for (const d of r.days) {
      const last = segs[segs.length - 1];
      const key = `${d.kind}|${d.block}|${d.week}|${d.repeat ?? 0}`;
      if (last && last._key === key) last.to = d.date; else segs.push({ _key: key, kind: d.kind, block: d.block, week: d.week, from: d.date, to: d.date });
    }
    const clean = segs.map(({ _key, ...s }) => s);
    const byDate = Object.fromEntries(r.days.map((d) => [d.date, d]));
    const w3 = r.days.filter((d) => d.kind === 'festival' && d.week === 3).map((d) => d.step);
    const shortDays = r.shortened ? r.days.filter((d) => d.kind === 'festival' && d.week <= 2) : [];
    const allSteps = Array.from({ length: 2 }, (_, w) => Array.from({ length: 7 }, (_, k) => `W${w + 1}-A${k + 1}`)).flat();
    return {
      ...r,
      segments: clean,
      segments_from: new Proxy({}, { get: (_, date) => clean.filter((s) => s.from >= date) }),
      week3_steps: w3,
      repeat_alt_only: r.days.filter((d) => d.kind === 'repeat').every((d) => !['W3-A2', 'W3-A3', 'W3-A4'].some((x) => d.step === `HW-FB08-${x}`)),
      first_step: r.days.find((d) => d.kind === 'festival')?.step,
      day_of: Object.fromEntries(r.days.map((d) => [d.date, d.step])),
      day_kind: Object.fromEntries(r.days.map((d) => [d.date, d.kind])),
      shortened_steps: shortDays.map((d) => d.step),
      shortened_missing: allSteps.map((s) => `HW-FB08-${s}`).filter((s) => !shortDays.some((d) => d.step === s || d.step === `${s}-ALT`)),
      notes: shortDays.map((d) => d.note),
      task_days: Object.fromEntries(shortDays.filter((d) => d.task).map((d) => [d.date, d.task])),
      _byDate: byDate,
    };
  }
  return r;
}

/** Check one fixture's expected values against a result; returns a list of problems. */
export function check(f, r) {
  const problems = [];
  for (const [path, want] of Object.entries(f.expected)) {
    if (path === 'excludes') { for (const id of want) if (r.item_ids.includes(id)) problems.push(`${f.id}: ${id} should be left out`); continue; }
    if (path === 'includes') { for (const id of want) if (!r.item_ids.includes(id)) problems.push(`${f.id}: ${id} should be kept`); continue; }
    if (path === 'not_in_plan') { for (const id of want) if (r.ids.includes(id)) problems.push(`${f.id}: ${id} should not be in the plan`); continue; }
    const got = path.startsWith('segments_from.') ? r.segments_from[path.slice('segments_from.'.length)] : at(r, path);
    if (JSON.stringify(got) !== JSON.stringify(want)) problems.push(`${f.id} ${path}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`);
  }
  return problems;
}

/** Run every fixture of one or more files (or the given fixtures) and return {fixture, result, problems}. */
export function replay(fixtures) {
  const php1 = fixtures.filter((f) => CALLS[f.inputs.fn]);
  const results = php1.length ? call(php1.map((f) => [f.inputs.fn, f.inputs.args])) : [];
  const out = [];
  let k = 0;
  for (const f of fixtures) {
    let r;
    if (CALLS[f.inputs.fn]) r = project(f.inputs.fn, expand(f.inputs.args), results[k++]);
    else if (f.inputs.fn === 'weeks') r = weeks(f.inputs.args);
    else if (f.inputs.fn === 'note_sequence') r = noteSequence(f.inputs.args);
    else if (f.inputs.fn === 'member') r = member(f.inputs.args);
    else throw new Error(`${f.id}: unknown fn ${f.inputs.fn}`);
    out.push({ fixture: f, result: r, problems: check(f, r) });
  }
  return out;
}

export const fixtureFile = (name) => json(`fixtures/healthy-weight-programme/${name}.json`).fixtures;
export const fixtureById = (src) => readdirSync(resolve(root, 'fixtures/healthy-weight-programme')).filter((n) => n.endsWith('.json'))
  .flatMap((n) => json(`fixtures/healthy-weight-programme/${n}`).fixtures || []).find((f) => f.inputs.source_id === src);
