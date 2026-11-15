// The synthetic Healthy Weight content the Stage 3 browser job loads into its throwaway WordPress (P3-35, package P4;
// tests/ci/stage3-hw-content.sh). It extends the P3 test bundle (tests/stage3/hw-content/hw-fixture.mjs) with what the
// hw-*.spec.mjs journeys reach: the 24 questions (HW-QW-01 to -24) with their keys, choices and reason lines, the nine
// focus blocks and a festival calendar with Ramadan in 2027 and 2028. No approved member text is in it: every
// question, reason, note and title is made up. The only real-looking words are the choice labels the test criteria
// themselves name (TC-FR3-98-AS-02 and AS-07) and festival names. Never loaded anywhere but a test site.
//
// Usage: node tests/stage3/e2e/screens/hw-test-bundle.mjs <out.json>
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { bundle as p3Bundle } from '../../hw-content/hw-fixture.mjs';

const appr = (file) => ({ decision: 'd-test-' + file.replace(/[^a-z0-9]+/g, '-'), file, sha: '0123456789abcdef' });
const S3 = (item) => ({ stage: 3, ...item });
const made = (n) => Array.from({ length: n }, (_, i) => `Test choice ${i + 1}`);

/** HW-QW id => [answer key, choice labels, extra fields]. The labels pair with the loader's values in order. */
const QUESTIONS = {
  'HW-QW-01': ['activity_level', made(4), { asked_in: 'where_now', rule_ids: ['R-HW-NUM-03', 'R-HW-STEP-06'] }],
  'HW-QW-02': ['bmi_background', made(12), { asked_in: 'where_now', rule_ids: ['R-HW-NUM-01'], follow_up: 'Follow-up after "Test choice 11": "A made-up follow-up question?" · Yes · No · Not sure.' }],
  'HW-QW-03': ['reason', made(7), { rule_ids: ['R-HW-MOT-01'], follow_up: 'For an event: "A made-up date question?"' }],
  'HW-QW-04': ['barrier', made(6), { rule_ids: ['R-HW-STEP-01'] }],
  'HW-QW-05': ['time', made(3), { rule_ids: ['R-HW-PACE-01'] }],
  'HW-QW-06': ['work', made(4), { rule_ids: ['R-HW-PACE-01'] }],
  'HW-QW-07': ['wake_time', [], { rule_ids: ['R-HW-REM-01'] }],
  'HW-QW-08': ['confidence', [], { rule_ids: ['R-HW-PACE-01'], answer: 'A 1 to 10 scale, labelled "1 = made-up low" and "10 = made-up high".' }],
  // TC-FR3-98-AS-02: the sleep and alcohol choices the test criteria name.
  'HW-QW-09': ['sleep', ['Under 6 hours', '6 to 7 hours', '7 to 9 hours', 'Over 9 hours'], { rule_ids: ['R-HW-SAFE-03'] }],
  'HW-QW-10': ['stress', [], { rule_ids: ['R-HW-SAFE-03'], answer: 'A 1 to 10 scale, labelled "1 = made-up calm" and "10 = made-up stressed".' }],
  'HW-QW-11': ['alcohol_freq', ['Never', 'Less than once a week', '1 to 2 days a week', '3 to 4 days a week', 'Most days'], { rule_ids: ['R-HW-SAFE-05'] }],
  // TC-FR3-98-AS-07: the drinks-a-time choices the test criteria name.
  'HW-QW-12': ['alcohol_amount', ['1 to 2', '3 to 4', '5 or more'], { rule_ids: ['R-HW-SAFE-05'], body: 'A made-up picture line.' }],
  'HW-QW-13': ['food_rules', made(13), { asked_in: 'today', rule_ids: ['R-HW-FOOD-01'] }],
  'HW-QW-14': ['festivals', ['Ramadan', ...made(24), 'Test none'], { asked_in: 'today', rule_ids: ['R-HW-FOOD-03'] }],
  'HW-QW-15': ['allergies', made(13), { asked_in: 'today', rule_ids: ['R-HW-FOOD-01'] }],
  'HW-QW-16': ['breakfast', made(3), { asked_in: 'today', rule_ids: ['R-HW-STEP-02'], follow_up: 'Follow-up after "Test choice 1": "A made-up protein question?" · Yes · No · Not sure.' }],
  'HW-QW-17': ['snack_time', made(6), { asked_in: 'today', rule_ids: ['R-HW-REM-01'] }],
  'HW-QW-18': ['sugary_drinks', made(5), { asked_in: 'today', rule_ids: ['R-HW-STEP-02'] }],
  'HW-QW-19': ['eats_out', made(3), { asked_in: 'today', rule_ids: ['R-HW-STEP-02'] }],
  'HW-QW-20': ['cooking_time', made(3), { asked_in: 'today', rule_ids: ['R-HW-FOOD-02'] }],
  'HW-QW-21': ['cooking_skill', made(3), { asked_in: 'today', rule_ids: ['R-HW-FOOD-02'] }],
  'HW-QW-22': ['budget', made(3), { asked_in: 'today', rule_ids: ['R-HW-FOOD-02'] }],
  'HW-QW-23': ['cuisines', made(7), { asked_in: 'today', rule_ids: ['R-HW-FOOD-02'] }],
  'HW-QW-24': ['enjoyment', made(9), { asked_in: 'today', rule_ids: ['R-HW-STEP-06'] }],
};

/** The nine focus blocks (FR3-104), by slug; evening-night-eating is already in the P3 bundle with its four weeks. */
const BLOCKS = ['stress-emotional-eating', 'eating-out-social-life', 'breaking-a-plateau', 'alcohol', 'sleep-and-weight', 'getting-stronger', 'festivals-and-fasting', 'shift-work'];

export function bundle() {
  const b = p3Bundle();
  // The P3 bundle's two sample questions are replaced by the full set.
  const items = b.items.filter((it) => it.type !== 'lld_question');
  // Level 2 of weeks 5 to 8 (Owner Question 161: "Carry on to week 5" starts it).
  items.push(S3({ key: 'track:healthy-weight-2', type: 'lld_track', title: 'Test weight track level 2', slug: 'healthy-weight-2', body: 'Made-up track text.', tags: ['healthy-weight'],
    fields: { sales_page: '', starter_guide: '', measures: '', status: 'live' }, approval: appr('tracks/healthy-weight-2.md') }));
  for (const slug of BLOCKS) {
    items.push(S3({ key: 'track:' + slug, type: 'lld_track', title: 'Test block ' + slug, slug, body: 'Made-up block text.', tags: ['healthy-weight'],
      fields: { sales_page: '', starter_guide: '', measures: '', status: 'live', track_kind: 'focus_block', offer_rule_ids: ['R-HW-BLOCK-01'], offer_window: '' },
      approval: appr('healthy-weight-programme/blocks.md') }));
  }
  for (const [id, [key, choices, extra]] of Object.entries(QUESTIONS)) {
    const { body = '', ...fields } = extra;
    items.push(S3({ key: 'question:' + id, type: 'lld_question', title: `A made-up question for ${key}?`, body, tags: [],
      fields: { question_key: id, choices, why: `A made-up reason for asking about ${key}.`, asked_in: 'joining', excluded_for: ['pregnancy', 'breastfeeding', 'eating_disorder', 'no_health_consent', 'under_18'], ...fields },
      approval: appr('healthy-weight-programme/questions.md') }));
  }
  const festivals = [
    { key: 'CAL-RAM-2027', name: 'Ramadan', kind: 'dawn_to_sunset_fast', starts_on: '2027-02-08', ends_on: '2027-03-08', expected: false, approved_on: '2026-11-10', offers_block: true, tradition: '' },
    { key: 'CAL-RAM-2028', name: 'Ramadan', kind: 'dawn_to_sunset_fast', starts_on: '2028-01-28', ends_on: '2028-02-25', expected: true, approved_on: '2026-11-10', offers_block: true, tradition: '' },
    ...b.festivals,
  ];
  return { ...b, items, festivals };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const out = process.argv[2];
  if (!out) throw new Error('usage: node hw-test-bundle.mjs <out.json>');
  writeFileSync(out, JSON.stringify(bundle(), null, 2) + '\n');
  console.log(`hw-test-bundle: ${bundle().items.length} items, ${bundle().festivals.length} festival entries -> ${out}`);
}
