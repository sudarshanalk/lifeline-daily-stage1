// A small synthetic content bundle in the shape build_bundle.py writes for the Healthy Weight programme (P3-35,
// package P3), and the harness that "loads" it through the real loader checks and runs LLD_Members_HW_Content.
// No approved member text is in the repository: every title and body here is made up. Not a test file itself.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const root = resolve(here, '../../..');
export const STUBS = resolve(here, 'hw-content-stubs.php');
export const LOADER = resolve(root, 'site/public_html/wp-content/mu-plugins/lld-tools/content-load.php');
export const HW_CLASS = resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/stage3/class-content-hw.php');

export const URGENT = 'If you are struggling or feel unsafe, find urgent help in your country at findahelpline.com. If you are in danger now, call your local emergency number.';
export const SNORING = 'See a doctor if you have loud snoring with daytime sleepiness.';
const appr = (file) => ({ decision: 'd-test-' + file.replace(/[^a-z0-9]+/g, '-'), file, sha: '0123456789abcdef' });
const S3 = (item) => { const { key, type, ...rest } = item; return { key, type, stage: 3, ...rest }; };

/** One activity with the three versions and habit tags (FR3-103). */
function act(id, track, week, day, extra = {}) {
  return {
    key: 'act:' + id, type: 'lld_activity', title: `Step ${id}`, body: `Standard text of ${id}.\n\nEasier option: easier text of ${id}.`,
    tags: ['healthy-weight'],
    fields: { belongs: 'track', track_id: '@track:' + track, week_number: week, day_number: day, difficulty: 'standard', in_library: 0,
      link: '', habit_tags: ['meals'], easier: `Easier text of ${id}.`, stretch: `Stretch text of ${id}.`, alt_for: '', shown_under: [], ...extra },
    approval: appr('tracks/test.md'),
  };
}

function note(id, track, week, day, extra = {}) {
  return S3({
    key: 'note:' + id, type: 'lld_note', title: `Note ${id}`, body: `Made-up note body for ${id}.`, tags: ['healthy-weight'],
    fields: { note_key: id, track_id: '@track:' + track, week, day, try_this: `try a made-up thing for ${id}.`, support_wording: 0, ...extra },
    approval: appr('healthy-weight-programme/notes.md'),
  });
}

/** The bundle: level 1 (Stage 2 keys, new versions: no stage), one focus block, notes, questions, a guide, a step,
 *  a recipe with food rules, Keep It Off and the festival calendar. */
export function bundle() {
  const items = [
    { key: 'track:healthy-weight', type: 'lld_track', title: 'Test weight track', slug: 'healthy-weight', body: 'Made-up track text.', tags: ['healthy-weight'],
      fields: { sales_page: '', starter_guide: '', measures: '', status: 'live' }, approval: appr('tracks/healthy-weight.md') },
    S3({ key: 'track:keep-it-off', type: 'lld_track', title: 'Test keep track', slug: 'keep-it-off', body: 'Made-up track text.', tags: ['keep-it-off'],
      fields: { sales_page: '', starter_guide: '', measures: '', status: 'live' }, approval: appr('tracks/keep-it-off.md') }),
    S3({ key: 'track:evening-night-eating', type: 'lld_track', title: 'Test block', slug: 'evening-night-eating', body: 'Made-up block text.', tags: ['healthy-weight'],
      fields: { sales_page: '', starter_guide: '', measures: '', status: 'live', track_kind: 'focus_block', offer_rule_ids: ['R-HW-BLOCK-01'], offer_window: '' },
      approval: appr('healthy-weight-programme/fb01.md') }),
    { key: 'recipe:R-103', type: 'lld_recipe', title: 'Test recipe', body: 'Ready in 10 minutes. Serves 2.', tags: ['vegetarian'],
      fields: { servings: 2, meal_type: 'dinner', diet: 'everything, vegetarian', tracks: 'healthy-weight', ingredients: [{ name: 'beans', amount: '100', unit: 'g' }],
        food_rules: ['contains-grains-or-pulses'], allergens: ['gluten'] }, approval: appr('meals/meals.json') },
  ];
  for (let w = 1; w <= 4; w++) {
    items.push(S3({ key: `week:evening-night-eating:${w}`, type: 'lld_week', title: `Block theme ${w}`, body: `Task ${w}.`, tags: ['healthy-weight'],
      fields: { track_id: '@track:evening-night-eating', week_number: w }, approval: appr('healthy-weight-programme/fb01.md') }));
    items.push(S3({ key: `lesson:evening-night-eating:${w}`, type: 'lld_lesson', title: `Block lesson ${w}`, body: `<p>Lesson ${w}.</p>`, tags: ['healthy-weight'],
      fields: { track_id: '@track:evening-night-eating', week_number: w, video_link: '' }, approval: appr('healthy-weight-programme/fb01.md') }));
  }
  items.push(S3({ key: 'week:evening-night-eating:4:alt', type: 'lld_week', title: 'Block theme 4', body: 'Alternative task.', tags: ['healthy-weight'],
    fields: { track_id: '@track:evening-night-eating', week_number: 4, alt_for: 'HW-FB01-W4-T' }, approval: appr('healthy-weight-programme/fb01.md') }));
  items.push(S3({ key: 'guide:counting-made-easy', type: 'lld_lesson', title: 'Test counting guide', body: '<p>Made-up guide text.</p>',
    tags: ['healthy-weight', 'calorie-mention'], fields: { track_id: '@track:healthy-weight', week_number: 0, video_link: '', guide_key: 'counting-made-easy', weeks_shown: [1, 2] },
    approval: appr('healthy-weight-programme/cme.md') }));
  // Level 1: Stage 2 keys loaded as new approved versions (Owner Question 161), so no stage.
  for (let d = 1; d <= 7; d++) items.push(act(`HW-W1-A${d}`, 'healthy-weight', 1, d, d === 4 ? { shown_under: [{ text: 'A made-up line under the step.', when: 'always' }] } : {}));
  for (let w = 1; w <= 4; w++) for (let d = 1; d <= 7; d++) items.push(S3(act(`HW-FB01-W${w}-A${d}`, 'evening-night-eating', w, d,
    d === 1 ? { shown_under: [{ text: 'For shift and night workers: a made-up line.', when: 'shift_worker' }], habit_tags: ['evening-eating', 'tracking'] } : {})));
  items.push(S3(act('HW-FB01-W1-A5-ALT', 'evening-night-eating', 1, 0, { belongs: 'library', alt_for: 'HW-FB01-W1-A5' })));
  items.push(S3({ ...act('HW-SL-01', 'healthy-weight', 1, 0, { belongs: 'library', in_library: 1, habit_tags: ['sleep'] }) }));
  items.push(S3({ key: 'act:KO-W1-A1', type: 'lld_activity', title: 'Test keep step', body: 'Made-up keep step.', tags: ['keep-it-off'],
    fields: { belongs: 'track', track_id: '@track:keep-it-off', week_number: 1, day_number: 1, difficulty: 'standard', in_library: 0, link: '' }, approval: appr('tracks/keep-it-off.md') }));
  for (const kind of ['hw-ms-03', 'hw-ms-03-65-plus']) items.push(S3({ key: 'wording:' + kind, type: 'lld_wording', title: 'Test milestone ' + kind,
    body: 'A made-up line for ' + kind + '.', tags: [], fields: { kind }, approval: appr('healthy-weight-programme/ms.md') }));
  items.push(S3({ key: 'wording:hw-ms-04', type: 'lld_wording', title: 'Test milestone', body: 'A made-up milestone line.', tags: [], fields: { kind: 'hw-ms-04' },
    approval: appr('healthy-weight-programme/ms.md') }));
  for (let w = 1; w <= 8; w++) for (let d = 1; d <= 7; d++) {
    const support = w === 4 && d === 4;
    items.push(note(`HW-MN-W${w}-${d}`, 'healthy-weight', w, d, support ? { support_wording: 1 } : {}));
    if (support) { items[items.length - 1].tags = ['healthy-weight', 'mood']; items[items.length - 1].body += '\n\n' + URGENT; }
    // a sleep note (owner, N4): tagged sleep, so it carries the snoring line and no support wording
    if (w === 1 && d === 6) { items[items.length - 1].tags = ['healthy-weight', 'sleep']; items[items.length - 1].body += '\n\n' + SNORING; }
  }
  for (let w = 1; w <= 4; w++) for (let d = 1; d <= 7; d++) items.push(note(`HW-FB01-MN-W${w}-${d}`, 'evening-night-eating', w, d));
  items.push(S3({ key: 'question:HW-QW-09', type: 'lld_question', title: 'A made-up sleep question?', body: '', tags: [],
    fields: { question_key: 'HW-QW-09', choices: ['Short', 'Long'], why: 'A made-up reason.', asked_in: 'joining', rule_ids: ['R-HW-STEP-04'],
      excluded_for: ['pregnancy', 'breastfeeding', 'eating_disorder', 'no_health_consent', 'under_18'] }, approval: appr('healthy-weight-programme/qw.md') }));
  items.push(S3({ key: 'question:HW-QW-01', type: 'lld_question', title: 'A made-up activity question?', body: 'A made-up line shown with it.', tags: [],
    fields: { question_key: 'HW-QW-01', choices: ['Little', 'Lots'], why: 'Another made-up reason.', asked_in: 'where_now', rule_ids: ['R-HW-NUM-03'],
      excluded_for: ['pregnancy'] }, approval: appr('healthy-weight-programme/qw.md') }));
  const festivals = [
    { key: 'CAL-TEST-2027', name: 'Test fast', kind: 'dawn_to_sunset_fast', starts_on: '2027-02-08', ends_on: '2027-03-08', expected: true, approved_on: '2026-11-10', offers_block: true, tradition: '' },
    { key: 'CAL-ONE-20270103', name: 'Test day', kind: 'food_avoiding_fast', starts_on: '2027-01-03', ends_on: '2027-01-03', expected: false, approved_on: '2026-11-10', offers_block: false, tradition: 'smarta' },
  ];
  return { generated: '2026-11-10T00:00:00Z', approval_page: 'https://example.test/approvals', items, festivals };
}

/** Load a bundle through the stand-in library and call one LLD_Members_HW_Content method. */
export function run(input) {
  const out = execFileSync('php', ['-d', 'display_errors=stderr', STUBS], { input: JSON.stringify({ unapproved: [], table: false, ...input }), encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 });
  return JSON.parse(out);
}

/** Call a pure loader or HW-class function directly (both files required, no WordPress). */
export function pure(fn, ...args) {
  const p = (f) => JSON.stringify(f.split('\\').join('/'));
  const code = `define('ABSPATH', '/'); require ${p(HW_CLASS)}; require ${p(LOADER)}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
}

/** The bundle with one item changed (found by key). */
export function withItem(b, key, change) {
  return { ...b, items: b.items.map((it) => (it.key === key ? change(structuredClone(it)) : it)) };
}
