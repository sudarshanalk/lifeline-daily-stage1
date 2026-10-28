// FR2-35 / SCR-22: the private group's link comes from LLD_FACEBOOK_GROUP_URL in wp-config.php (owner input).
// While it is not set, or is not a facebook.com https address, no link is shown at all.
import { expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';

const link = (value) => premium(value === undefined ? {} : { defines: { LLD_FACEBOOK_GROUP_URL: value } }, 'group_link');

test('TC-FR2-35-AS-02 the group link is the configured facebook.com address, or nothing', () => {
  expect(link(undefined)).toBe('');
  expect(link('')).toBe('');
  expect(link('https://www.facebook.com/groups/lifelinedaily')).toBe('https://www.facebook.com/groups/lifelinedaily');
  expect(link('  https://facebook.com/groups/1234  ')).toBe('https://facebook.com/groups/1234');
  expect(link('http://www.facebook.com/groups/lifelinedaily')).toBe('');
  expect(link('https://facebook.com.example.net/groups/x')).toBe('');
  expect(link('https://example.com/?facebook.com')).toBe('');
  expect(link('javascript:alert(1)')).toBe('');
});
