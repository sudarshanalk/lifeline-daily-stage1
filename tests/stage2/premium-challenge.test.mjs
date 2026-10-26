// FR2-33 (TC-FR2-33-AS-02): challenge days are marked done on the day or up to 2 days late (requirements 8.3), and
// the weekly review counts what was done of the actions available, never shaming a missed week.
import { expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';

const item = (n) => ({ title: `Act ${n}`, version_key: `${100 + n}:v1` });
const items = Array.from({ length: 28 }, (_, i) => item(i + 1));

test('TC-FR2-33-AS-02 a day can be marked on the day and up to 2 days late, never ahead or 3 days late', () => {
  const open = (day, current) => premium({}, 'tick_open', day, current);
  expect(open(5, 5)).toBe(true);
  expect(open(5, 7)).toBe(true);
  expect(open(5, 8)).toBe(false);
  expect(open(6, 5)).toBe(false);
  expect(open(28, 30)).toBe(true);
  expect(open(0, 1)).toBe(false);
  expect(open(29, 29)).toBe(false);
});

test('TC-FR2-33-AS-02 the weekly review appears for each week that has ended', () => {
  const review = (done, current, finished = false) => premium({}, 'challenge_review', items, done, current, finished);
  expect(review({}, 7)).toBe('');
  const week1 = review({ 1: true, 2: true, 3: true, 4: true, 5: true }, 8);
  expect(week1).toContain('<h3>Week 1 review</h3><p>5 of 7 actions done. A strong week. Keep it going.</p>');
  expect(week1).not.toContain('Week 2 review');
  expect(review({ 8: true, 9: true, 10: true }, 15)).toContain('<h3>Week 2 review</h3><p>3 of 7 actions done. Every action counts. Keep going at your own pace.</p>');
  // a missed week is never shamed
  const missed = review({}, 15);
  expect(missed).toContain('0 of 7 actions done. A new week is a fresh start. Pick one action to try.');
  expect(missed).not.toMatch(/fail|missed|only|disappoint|should have/i);
  // the last week on finishing suggests what to do next
  const last = review({}, 28, true);
  expect(last).toContain('Week 4 review');
  expect(last).toContain('What next:');
  expect(last).toContain('start a track');
});

test('TC-FR2-33-AS-02 actions the safety matrix removed are not counted as available', () => {
  const some = items.map((x, i) => (i < 7 && i % 2 ? null : x));
  const html = premium({}, 'challenge_review', some, { 1: true, 3: true, 5: true, 7: true }, 8, false);
  expect(html).toContain('4 of 4 actions done. A strong week.');
  const none = items.map((x, i) => (i < 7 ? null : x));
  expect(premium({}, 'challenge_review', none, {}, 8, false)).toContain('0 of 0 actions done. There were no actions for you this week.');
});
