// Requirements section 8.2 on the Premium items (TC-FR2-25-AS-05 safety-matrix runner, TC-FR2-32-AS-02, TC-FR2-34-AS-02):
// every matrix row against workouts, challenges and their daily actions, and meal-plan templates. Runs the real
// LLD_Members_Safety and LLD_Members_Premium in PHP CLI (tests/stage2/tools/premium-stubs.php).
import { describe, expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';
import { matrixRows } from './tools/safety-matrix.mjs';

const ADVICE = 'Warm up before you start. Stop if you feel pain. Check with your doctor if you need to.';
const NOTES = {
  unknown: 'Complete your health profile for more suggestions',
  kidney: 'Your kidney team should set your protein target',
  pregnancy: 'Talk to your midwife or doctor about weight and exercise during pregnancy',
  'eating-disorder': 'For support with eating, talk to your doctor',
  diabetes: 'Talk to your diabetes team',
  'blood-pressure': 'Talk to your doctor before starting intense exercise',
};

// Every row of the launch matrix, plus the second "unknown" case: health consent off.
const rows = [...matrixRows, { name: 'unknown, no consent', profile: { conditions_answered: 'none', conditions: '' }, consent: false, hidden: ['weight-loss', 'high-intensity'], visible: '' }];
// The launch rows exclude high-intensity for unknown and pregnancy; the shared rows name one tag each, so add it.
const extraHidden = { unknown: ['high-intensity'], pregnancy: ['high-intensity', 'fasting'], combined: ['high-intensity'] };

function world(row, items) {
  return { consent: row.consent !== false, profile: row.profile, items };
}

function workout(title, tags, week = 1) {
  return {
    type: 'lld_workout', title, tags,
    fields: { level: 'beginner', place: 'home', week_number: week, safety: '', sessions: `${title} move | 20 | 10` },
  };
}

function hiddenOf(row) {
  return [...new Set([...row.hidden, ...(extraHidden[row.name] || [])])];
}

describe('section 8.2 safety matrix: workouts', () => {
  for (const row of rows) {
    test(`workout, ${row.name}`, () => {
      for (const tag of hiddenOf(row)) {
        // the excluded plan comes first, so without the matrix it would be chosen
        const items = { 10: workout(`Tagged ${tag}`, [tag]), 11: workout('General safe', []) };
        const plan = premium(world(row, items), 'workout_plan', 7, 'beginner', 'home', 1);
        expect(plan, `${row.name} gets a plan`).not.toBeNull();
        expect(JSON.stringify(plan)).not.toContain(`Tagged ${tag}`);
        expect(JSON.stringify(plan)).toContain('General safe');
        // nothing safe left: the safe empty state (null)
        expect(premium(world(row, { 10: workout(`Tagged ${tag}`, [tag]) }), 'workout_plan', 7, 'beginner', 'home', 1)).toBeNull();
      }
      if (row.visible) {
        const plan = premium(world(row, { 10: workout(`Tagged ${row.visible}`, [row.visible]) }), 'workout_plan', 7, 'beginner', 'home', 1);
        expect(JSON.stringify(plan)).toContain(`Tagged ${row.visible}`);
      }
    });
  }

  test('workout advice: warm up, stop if in pain, check with a doctor, plus the matrix notes', () => {
    for (const row of rows) {
      const plan = premium(world(row, { 11: workout('General safe', []) }), 'workout_plan', 7, 'beginner', 'home', 1);
      expect(plan.safety[0]).toBe(ADVICE);
      const situations = row.consent === false ? ['unknown'] : (row.profile ? (row.profile.conditions || []) : ['unknown']);
      for (const key of situations) {
        const name = { 'type-2-diabetes': 'diabetes', 'high-blood-pressure': 'blood-pressure' }[key] || key;
        if (NOTES[name]) expect(plan.safety, `${row.name}: ${name} note`).toContain(NOTES[name]);
      }
    }
    const owner = workout('Owner advice', []);
    owner.fields.safety = 'Warm up for 5 minutes.';
    const plan = premium(world(matrixRows[1], { 11: owner }), 'workout_plan', 7, 'beginner', 'home', 1);
    expect(plan.safety).toEqual(['Warm up for 5 minutes.', ADVICE]);
  });

  test('workout advice: type 2 diabetes before exercise, and high blood pressure on high-intensity work', () => {
    const diabetes = { name: 'd', profile: { conditions_answered: 'declared', conditions: ['type-2-diabetes'] } };
    const plan = premium(world(diabetes, { 11: workout('General safe', []) }), 'workout_plan', 7, 'beginner', 'home', 1);
    expect(plan.safety).toContain('If you take insulin or medicine that can cause low blood sugar, check with your doctor.');
    const pressure = { name: 'bp', profile: { conditions_answered: 'declared', conditions: ['high-blood-pressure'] } };
    const intense = premium(world(pressure, { 11: workout('Hard', ['high-intensity']) }), 'workout_plan', 7, 'beginner', 'home', 1);
    expect(intense.safety).toContain('Check with your doctor first.');
    const gentle = premium(world(pressure, { 11: workout('Gentle', []) }), 'workout_plan', 7, 'beginner', 'home', 1);
    expect(gentle.safety).not.toContain('Check with your doctor first.');
  });
});

describe('section 8.2 safety matrix: challenges and their daily actions', () => {
  const challenge = (title, tags, activityIds = '') => ({ type: 'lld_challenge', title, tags, fields: { month: '2026-06', activity_ids: activityIds } });
  const activity = (title, tags) => ({ type: 'lld_activity', title, tags, fields: { belongs: 'challenge' } });

  for (const row of rows) {
    test(`challenge, ${row.name}`, () => {
      for (const tag of hiddenOf(row)) {
        // the newest challenge of the month wins, so the excluded one gets the higher id
        const items = { 20: challenge('General challenge', []), 21: challenge(`Tagged ${tag}`, [tag]) };
        const shown = premium(world(row, items), 'current_challenge', 7, '2026-06-10');
        expect(shown.title).toBe('General challenge');
        expect(premium(world(row, { 21: challenge(`Tagged ${tag}`, [tag]) }), 'current_challenge', 7, '2026-06-10')).toBeNull();

        const acts = { 31: activity('Day one safe', []), 32: activity(`Day two ${tag}`, [tag]), 33: activity('Day three safe', []) };
        const host = challenge('Host', [], '31,32,33');
        const days = premium(world(row, { ...acts, 20: host }), 'challenge_activities', 7, { ...host, parent_id: 20 });
        expect(days).toEqual(['Day one safe', null, 'Day three safe']);
      }
      if (row.visible) {
        const shown = premium(world(row, { 21: challenge(`Tagged ${row.visible}`, [row.visible]) }), 'current_challenge', 7, '2026-06-10');
        expect(shown.title).toBe(`Tagged ${row.visible}`);
      }
    });
  }
});

describe('section 8.2 safety matrix: meal-plan templates', () => {
  const template = (title, tags) => ({ type: 'lld_meal', title, tags, fields: { diet: '', week_number: 1, meals: '{}', swaps: '{}' } });

  for (const row of rows) {
    test(`meal-plan template, ${row.name}`, () => {
      for (const tag of hiddenOf(row)) {
        const items = { 40: template(`Tagged ${tag}`, [tag]), 41: template('General week', []) };
        expect(premium(world(row, items), 'matching_template', 7).title).toBe('General week');
        expect(premium(world(row, { 40: template(`Tagged ${tag}`, [tag]) }), 'matching_template', 7)).toBeNull();
      }
      if (row.visible) {
        expect(premium(world(row, { 40: template(`Tagged ${row.visible}`, [row.visible]) }), 'matching_template', 7).title).toBe(`Tagged ${row.visible}`);
      }
    });
  }
});

describe('section 8.2 safety matrix: meal plans and every swap list', () => {
  const recipe = (title, tags, ingredient) => ({ type: 'lld_recipe', title, tags, fields: { ingredients: `${ingredient} | 1 | g` } });

  for (const row of rows) {
    test(`meal plan and swaps, ${row.name}`, () => {
      for (const tag of hiddenOf(row)) {
        const days = {};
        for (let d = 1; d <= 7; d += 1) days[d] = { breakfast: 50, lunch: d === 2 ? 52 : 50, dinner: 50, snack: 50 };
        const items = {
          50: recipe('Safe porridge', [], 'oats'),
          51: recipe('Safe soup', [], 'leeks'),
          52: recipe(`Planned ${tag}`, [tag], 'tagged planned'),
          53: recipe(`Swap ${tag}`, [tag], 'tagged swap'),
          60: { type: 'lld_meal', title: 'Week', tags: [], fields: { diet: '', week_number: 1, meals: JSON.stringify(days), swaps: JSON.stringify({ dinner: [51, 53], '2-lunch': [51, 53] }) } },
        };
        const view = premium(world(row, items), 'meal_view', 7);
        const text = JSON.stringify(view);
        expect(text).not.toContain(`Swap ${tag}`);
        expect(text).not.toContain(`Planned ${tag}`);
        expect(text).not.toContain('tagged');
        // the excluded planned meal is left out; every other meal is there, each dinner can swap to the safe soup
        expect(view.days['2'].lunch).toBeUndefined();
        expect(Object.keys(view.days)).toHaveLength(7);
        for (let d = 1; d <= 7; d += 1) {
          expect(view.days[d].dinner.options.map((o) => o.title)).toEqual(['Safe soup']);
          expect(view.days[d].breakfast.options).toEqual([]);
        }
      }
    });
  }
});
