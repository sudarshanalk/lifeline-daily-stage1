// FR2-36 / SCR-21 (TC-FR2-36-AS-02): charts cover every month since joining, not only months with a check-in,
// and every check-in measure the member recorded (Data Model C9), each with "Show as table" giving the same numbers.
import { expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';

const row = (local_date, values = {}) => ({ local_date, weight_kg: null, waist_cm: null, activity_minutes: null, chair_stands: null, push_ups: null, strength_sessions: null, sleep_hours: null, sleep_quality: null, stress_level: null, ...values });
const months = (html) => [...html.matchAll(/data-lld-month="([^"]+)"/g)].map((m) => m[1]);

test('TC-FR2-36-AS-02 every month in range is listed, with or without a check-in', () => {
  const html = premium({}, 'progress_chart', [row('2026-05-28', { weight_kg: '80.0' }), row('2026-08-15', { weight_kg: '78.5' })], '2026-05-01', '2026-10-02');
  expect(months(html)).toEqual(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10']);
  expect(html).toContain('<tr data-lld-month="2026-06"><th scope="row">2026-06</th><td>No check-in</td><td>—</td></tr>');
  expect(html).toContain('<tr data-lld-month="2026-08"><th scope="row">2026-08</th><td>Yes</td><td>78.5</td></tr>');
});

test('TC-FR2-36-AS-02 the range reaches back to a check-in before the join date, and on to the last one', () => {
  const html = premium({}, 'progress_chart', [row('2026-03-10'), row('2026-12-01')], '2026-05-20', '2026-06-01');
  expect(months(html)[0]).toBe('2026-03');
  expect(months(html).at(-1)).toBe('2026-12');
  expect(months(html)).toHaveLength(10);
});

test('TC-FR2-36-AS-02 each recorded measure gets a chart and a table column; the last value in a month counts', () => {
  const rows = [
    row('2026-05-07', { waist_cm: '90.0', chair_stands: '9' }),
    row('2026-05-28', { waist_cm: '88.5', sleep_quality: '3' }),
    row('2026-06-28', { stress_level: '2', chair_stands: '12' }),
  ];
  const html = premium({}, 'progress_chart', rows, '2026-05-01', '2026-06-30');
  const charts = [...html.matchAll(/data-lld-measure="([^"]+)"/g)].map((m) => m[1]);
  expect(charts).toEqual(['waist_cm', 'chair_stands', 'sleep_quality', 'stress_level']);
  expect(html).not.toContain('Weight in kilograms');
  expect(html).toContain('<th scope="col">Waist in centimetres</th><th scope="col">Chair stands in 30 seconds</th><th scope="col">Sleep quality from 1 to 5</th><th scope="col">Stress level from 1 to 5</th>');
  expect(html).toContain('<tr data-lld-month="2026-05"><th scope="row">2026-05</th><td>Yes</td><td>88.5</td><td>9</td><td>3</td><td>—</td></tr>');
  expect(html).toContain('aria-label="Chair stands in 30 seconds by month: 2026-05 9, 2026-06 12"');
  expect(html).toContain('<summary>Show as table</summary>');
});

test('TC-FR2-36-AS-02 no check-ins yet: the empty state, and the months so far', () => {
  const html = premium({}, 'progress_chart', [], '2026-05-01', '2026-06-10');
  expect(html).toContain('No history yet');
  expect(months(html)).toEqual(['2026-05', '2026-06']);
  expect(html).not.toContain('<figure');
});
