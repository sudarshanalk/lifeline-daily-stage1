// FR2-32 meal weeks (TC-FR2-32-AS-01): the meal week follows the track week (requirements section 8.3), a member
// past the last approved week gets the approved weeks in turn, a member with no track gets the general plans, and
// a week once given keeps its template version (Data Model C14). Real LLD_Members_Premium in PHP CLI.
import { describe, expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';

const world = (items) => ({ consent: true, profile: { conditions_answered: 'none', conditions: '' }, items });
const template = (title, week, extra = {}) => ({ type: 'lld_meal', title, tags: [], fields: { diet: '', week_number: week, meals: '{}', swaps: '{}', ...extra } });

describe('TC-FR2-32-AS-01 meal weeks', () => {
  test('TC-FR2-32-AS-01 weeks are 7 days from the first day, not calendar weeks', () => {
    // 2026-05-06 is a Wednesday: week 1 runs Wednesday to Tuesday
    expect(premium({}, 'week_from', 3, '2026-05-06', '2026-05-06')).toEqual({ track: 3, week: 1, starting: '2026-05-06' });
    expect(premium({}, 'week_from', 3, '2026-05-06', '2026-05-12')).toEqual({ track: 3, week: 1, starting: '2026-05-06' });
    expect(premium({}, 'week_from', 3, '2026-05-06', '2026-05-13')).toEqual({ track: 3, week: 2, starting: '2026-05-13' });
    expect(premium({}, 'week_from', 0, '2026-04-01', '2026-05-04')).toEqual({ track: 0, week: 5, starting: '2026-04-29' });
    // a missing or future anchor starts today
    expect(premium({}, 'week_from', 0, '', '2026-05-04')).toEqual({ track: 0, week: 1, starting: '2026-05-04' });
    expect(premium({}, 'week_from', 0, '2026-10-26', '2026-05-04')).toEqual({ track: 0, week: 1, starting: '2026-05-04' });
  });

  test('TC-FR2-32-AS-01 past the last approved week, the approved weeks come round in turn', () => {
    const items = { 1: template('W1', 1), 2: template('W2', 2), 3: template('W3', 3), 4: template('W4', 4) };
    const pick = (week) => premium(world(items), 'matching_template', 7, { track: 0, week, starting: '2026-05-04' }).title;
    expect([1, 2, 3, 4, 5, 6, 8, 9].map(pick)).toEqual(['W1', 'W2', 'W3', 'W4', 'W1', 'W2', 'W4', 'W1']);
  });

  test('TC-FR2-32-AS-01 no track: the general plans (no track set) come first', () => {
    const items = { 1: template('Track plan', 1, { track_id: 55 }), 2: template('General plan', 1) };
    expect(premium(world(items), 'matching_template', 7, { track: 0, week: 1, starting: '2026-05-04' }).title).toBe('General plan');
    expect(premium(world(items), 'matching_template', 7, { track: 55, week: 1, starting: '2026-05-04' }).title).toBe('Track plan');
    // with no general plan at all, any plan rather than none
    expect(premium(world({ 1: template('Track plan', 1, { track_id: 55 }) }), 'matching_template', 7, { track: 0, week: 1, starting: '2026-05-04' }).title).toBe('Track plan');
  });
});

describe('TC-FR2-32-AS-01 template version given (Data Model C14)', () => {
  const live = { ...template('Plan v3', 1), version: 3, versions: {
    2: { ...template('Plan v2', 1), approved_on: '2026-05-01' },
    1: { ...template('Plan v1 never approved', 1), approved_on: '' },
  } };

  test('TC-FR2-32-AS-01 the stored version is used, not the newer approval', () => {
    expect(premium(world({ 10: live }), 'exact_version', 'lld_meal', '10:v2')).toMatchObject({ title: 'Plan v2', parent_id: 10, version: 2, type: 'lld_meal' });
    expect(premium(world({ 10: live }), 'exact_version', 'lld_meal', '10:v3')).toMatchObject({ title: 'Plan v3', version: 3 });
  });

  test('TC-FR2-32-AS-01 an unapproved, missing, withdrawn or other-type version is not used', () => {
    expect(premium(world({ 10: live }), 'exact_version', 'lld_meal', '10:v1')).toBeNull();
    expect(premium(world({ 10: live }), 'exact_version', 'lld_meal', '10:v9')).toBeNull();
    expect(premium(world({}), 'exact_version', 'lld_meal', '10:v2')).toBeNull();
    expect(premium(world({ 10: live }), 'exact_version', 'lld_challenge', '10:v2')).toBeNull();
    expect(premium(world({ 10: live }), 'exact_version', 'lld_meal', 'nonsense')).toBeNull();
    // older rows that hold only the parent id get the live version
    expect(premium(world({ 10: live }), 'exact_version', 'lld_meal', '10')).toMatchObject({ title: 'Plan v3' });
  });
});
