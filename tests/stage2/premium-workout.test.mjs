// FR2-34 workout plans (TC-FR2-34-AS-02): sessions with several exercises each, and the week moving on after
// 7 days (Data Model C7). Runs the real LLD_Members_Premium in PHP CLI (tests/stage2/tools/premium-stubs.php).
import { describe, expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';

describe('TC-FR2-34-AS-02 workout sessions and weeks', () => {
  test('TC-FR2-34-AS-02 a session runs every exercise in it, each with its time, rest and instructions', () => {
    const text = 'Session A\nSquat to chair | 10 | 30 | Sit down and stand up slowly.\nWall press | 10 | 20 | Push away from the wall.\n\r\nSession B\nMarch on the spot | 15 | 0';
    expect(premium({}, 'parse_sessions', text)).toEqual([
      { name: 'Session A', exercises: [
        { name: 'Squat to chair', seconds: 10, rest: 30, how: 'Sit down and stand up slowly.' },
        { name: 'Wall press', seconds: 10, rest: 20, how: 'Push away from the wall.' },
      ] },
      { name: 'Session B', exercises: [{ name: 'March on the spot', seconds: 15, rest: 0, how: '' }] },
    ]);
    // unnamed blocks separated by blank lines are sessions too
    expect(premium({}, 'parse_sessions', 'A | 5 | 5\nB | 5 | 5\n\nC | 5 | 5').map((s) => s.exercises.length)).toEqual([2, 1]);
    // the older form: one exercise per line, each its own session
    expect(premium({}, 'parse_sessions', 'Squat to chair | 10 | 30\nWall press | 10 | 20').map((s) => s.name)).toEqual(['Squat to chair', 'Wall press']);
    expect(premium({}, 'parse_sessions', 'Only a heading')).toEqual([]);
  });

  test('TC-FR2-34-AS-02 the week moves on after 7 days, never back, and not past the last approved week', () => {
    const row = (startedOn, currentWeek) => ({ __object: { id: 5, started_on: startedOn, current_week: currentWeek } });
    // local today is 2026-05-04 in the harness
    expect(premium({}, 'elapsed_week', 7, row('2026-05-04', 1))).toBe(1);
    expect(premium({}, 'elapsed_week', 7, row('2026-04-28', 1))).toBe(1);
    expect(premium({}, 'elapsed_week', 7, row('2026-04-27', 1))).toBe(2);
    expect(premium({}, 'elapsed_week', 7, row('2026-04-20', 1))).toBe(3);
    expect(premium({}, 'advance_week', row('2026-04-20', 1), 3, 4)).toBe(3);
    expect(premium({}, 'advance_week', row('2026-04-20', 3), 2, 4)).toBe(3);
    expect(premium({}, 'advance_week', row('2026-01-01', 1), 18, 4)).toBe(4);
  });

  test('TC-FR2-34-AS-02 each approved week of a level and version is offered, newest approval first', () => {
    const plan = (title, week, extra = {}) => ({ type: 'lld_workout', title, tags: [], fields: { level: 'beginner', place: 'home', week_number: week, safety: '', sessions: `${title} | 10 | 10`, ...extra } });
    const items = { 1: plan('W1', 1), 2: plan('W2', 2), 3: plan('W1 older', 1), 4: plan('Other', 1, { place: 'equipment' }), 5: plan('W4', 4) };
    const weeks = premium({ consent: true, profile: { conditions_answered: 'none', conditions: '' }, items }, 'workout_weeks', 7, 'beginner', 'home');
    expect(Object.keys(weeks)).toEqual(['1', '2', '4']);
    expect(weeks['1'].sessions[0].name).toBe('W1');
    expect(weeks['2'].version_key).toBe('2:v1');
  });
});
