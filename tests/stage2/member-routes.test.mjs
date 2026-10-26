import { expect, test } from 'vitest';
import { memberRoutes } from './tools/member-routes.mjs';

const rewrites = {
  '^members/record/([0-9]+)/?$': 'index.php?lld_screen=record&lld_member=$matches[1]',
  '^members/photo/([0-9]+)/?$': 'index.php?lld_screen=photo&lld_member=$matches[1]',
  '^members/diary-photo/([0-9]+)/?$': 'index.php?lld_screen=diary-photo&lld_member=$matches[1]',
  '^members/library/([0-9]+)/?$': 'index.php?lld_screen=library&lld_item=$matches[1]',
  '^members/read/([0-9]+)/?$': 'index.php?lld_screen=read&lld_item=$matches[1]',
  '^members/home/?$': 'index.php?lld_screen=home',
};
const index = {
  routes: {
    '/lld-members/v1/members/(?P<id>\\d+)': { methods: { GET: true } },
    '/lld-members/v1/members/(?P<id>\\d+)/journey': { methods: { GET: true } },
    '/lld-members/v1/routes': { methods: { GET: true } },
  },
  lld_member_rewrites: Object.keys(rewrites),
  lld_member_rewrite_targets: rewrites,
};

test('the other-member probe keeps every member-number address and leaves out content-item numbers', () => {
  expect(memberRoutes(index, 12).map((route) => route.path)).toEqual([
    '/wp-json/lld-members/v1/members/12',
    '/wp-json/lld-members/v1/members/12/journey',
    '/members/record/12/',
    '/members/photo/12/',
    '/members/diary-photo/12/',
  ]);
});

test('without the targets (an older plugin) every numbered address is still probed', () => {
  const paths = memberRoutes({ ...index, lld_member_rewrite_targets: undefined }, 12).map((route) => route.path);
  expect(paths).toContain('/members/library/12/');
  expect(paths).toContain('/members/record/12/');
});
