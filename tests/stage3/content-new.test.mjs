// Stage 3 content behind the content_new switch (P3-73 to P3-77; FR3-19 to FR3-23; NFR3-06). The real switch, content
// filter, safety matrix and Premium class run in PHP CLI (tests/stage3/tools/content-new-stubs.php) over a content
// library the test supplies; the approved content loader's pure checks run from the mu-plugin source.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const STUBS = resolve(here, 'tools/content-new-stubs.php');
const LOADER = resolve(here, '../../site/public_html/wp-content/mu-plugins/lld-tools/content-load.php');
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');

const TESTER = 7;   // a member whose email the owner listed as a test account
const MEMBER = 8;   // an ordinary member
const WORLD = { test_emails: ['tester@example.test'], emails: { 7: 'tester@example.test', 8: 'ordinary@example.test' } };

function run(world, call, ...args) {
  const input = JSON.stringify({ ...WORLD, switch: 'off', member: MEMBER, admin: false, in_admin: false, ...world, call, args });
  return JSON.parse(execFileSync('php', [STUBS], { input, encoding: 'utf8', timeout: 60000 }));
}

function loader(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(LOADER.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}

// The Stage 2 library as loaded today, and the Stage 3 items the P3-73 to P3-77 bundle adds (stage 3).
const stage2 = {
  11: { type: 'lld_track', title: 'Healthy Weight', slug: 'healthy-weight', tags: ['healthy-weight'], fields: { status: 'live' } },
  12: { type: 'lld_track', title: 'Sleep and Stress', slug: 'sleep-stress', tags: ['sleep-stress'], fields: { status: 'live' } },
  21: { type: 'lld_goal', title: 'Healthy Weight', tags: ['healthy-weight'], fields: { kind: 'track goal', track_id: 11, sort_order: 1 } },
  22: { type: 'lld_goal', title: 'Sleep and Stress', tags: ['sleep-stress'], fields: { kind: 'track goal', track_id: 12, sort_order: 4 } },
  31: { type: 'lld_wording', title: 'Reflection: winding down', tags: [], fields: { kind: 'reflection-1' } },
  41: { type: 'lld_badge', title: 'Fibre finisher', tags: [], fields: { picture: 'fibre-finisher', rule: 'challenge-finish-20', weight_loss: 0 } },
  51: { type: 'lld_challenge', title: '28-day fibre boost', tags: [], fields: { month: '2026-12', badge_id: 41, activity_ids: '' } },
};
const MONTHS = ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10', '2027-11'];
const stage3 = {
  13: { type: 'lld_track', title: 'Heart Health', slug: 'heart-health', tags: ['heart-health'], fields: { status: 'live' }, stage: 3 },
  14: { type: 'lld_track', title: 'Keep It Off', slug: 'keep-it-off', tags: ['keep-it-off'], fields: { status: 'live' }, stage: 3 },
  23: { type: 'lld_goal', title: 'Heart Health', tags: ['heart-health'], fields: { kind: 'track goal', track_id: 13, sort_order: 5 }, stage: 3 },
  32: { type: 'lld_wording', title: 'Please get medical help now', tags: [], fields: { kind: 'screening-hh-chest-pain' }, stage: 3 },
  33: { type: 'lld_wording', title: 'Goal step introduction', tags: [], fields: { kind: 'gm-01' }, stage: 3 },
};
MONTHS.forEach((month, i) => {
  stage3[60 + i] = { type: 'lld_badge', title: `Badge ${month}`, tags: [], fields: { picture: 'b', rule: 'challenge-finish-20', weight_loss: 0 }, stage: 3 };
  stage3[80 + i] = { type: 'lld_challenge', title: `Challenge ${month}`, tags: [], fields: { month, badge_id: 60 + i, activity_ids: '' }, stage: 3 };
});
const both = { ...stage2, ...stage3 };

const TYPES = ['lld_track', 'lld_goal', 'lld_challenge', 'lld_badge', 'lld_wording'];
/** What every Stage 2 listing query returns for this member: the goal choice and programmes page (lld_goal, lld_track),
 *  the monthly challenge (lld_challenge, lld_badge) and the wording look-ups (lld_wording), as class-content,
 *  class-programme and class-premium query them. */
function listings(world) {
  const out = {};
  for (const type of TYPES) out[type] = run(world, 'get_posts', { post_type: type, post_status: ['publish', 'draft', 'pending', 'private'], post_parent: 0, posts_per_page: -1 });
  out.track_by_slug = run(world, 'get_posts', { post_type: 'lld_track', name: 'heart-health', post_status: ['publish', 'draft', 'pending', 'private'], posts_per_page: 1 });
  out.challenge_dec = run(world, 'current_challenge', world.member ?? MEMBER, '2026-12-10');
  out.challenge_jan = run(world, 'current_challenge', world.member ?? MEMBER, '2027-01-10');
  out.next_after_dec = run(world, 'next_challenge', world.member ?? MEMBER, '2026-12-10');
  return out;
}

test('TC-NFR3-06-AS-01 with the switch off, every Stage 2 listing is byte-identical to the library before Stage 3 content was loaded', () => {
  const before = listings({ items: stage2 });
  const after = listings({ items: both });
  expect(JSON.stringify(after)).toBe(JSON.stringify(before));
  // and the December 2026 challenge is still the one a Stage 2 member gets
  expect(after.challenge_dec.title).toBe('28-day fibre boost');
  expect(after.challenge_jan).toBeNull();
  expect(after.next_after_dec).toBeNull();
  // the same when the switch is on for test accounts only: an ordinary member sees no change
  const testAccounts = listings({ items: both, switch: 'test_accounts', member: MEMBER });
  expect(JSON.stringify(testAccounts)).toBe(JSON.stringify(before));
});

test('TC-NFR3-06-AS-02 content switched on for test accounts is visible to a test account and the owner, not to an ordinary member', () => {
  const titles = (world) => run(world, 'get_posts', { post_type: 'lld_track', post_status: ['publish'], post_parent: 0, posts_per_page: -1 }).map((p) => p.title);
  expect(titles({ items: both, switch: 'test_accounts', member: MEMBER })).toEqual(['Healthy Weight', 'Sleep and Stress']);
  expect(titles({ items: both, switch: 'test_accounts', member: TESTER })).toEqual(['Healthy Weight', 'Sleep and Stress', 'Heart Health', 'Keep It Off']);
  expect(titles({ items: both, switch: 'test_accounts', member: 0, admin: true })).toEqual(['Healthy Weight', 'Sleep and Stress', 'Heart Health', 'Keep It Off']);
  expect(titles({ items: both, switch: 'everyone', member: MEMBER })).toEqual(['Healthy Weight', 'Sleep and Stress', 'Heart Health', 'Keep It Off']);
  // the switch is in the catalogue and off by default
  const features = readFileSync(resolve(plugin, 'includes/stage3/class-features.php'), 'utf8');
  expect(features).toMatch(/'content_new'\s*=> 'P3-73 to P3-77: new tracks, challenges and wording'/);
  expect(readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8')).toMatch(/LLD_Members_Stage3_Content::boot\(\)/);
});

test('TC-FR3-19-AS-03 no Stage 3 track, goal or wording reaches a member the switch does not allow, and nothing unapproved loads', () => {
  const ids = (type, world) => run(world, 'get_posts', { post_type: type, post_status: ['publish', 'draft', 'private'], post_parent: 0, posts_per_page: -1, fields: 'ids' });
  for (const world of [{ items: both }, { items: both, switch: 'test_accounts', member: MEMBER }]) {
    expect(ids('lld_track', world)).toEqual([11, 12]);
    expect(ids('lld_goal', world)).toEqual([21, 22]);
    expect(ids('lld_wording', world)).toEqual([31]);
    expect(run(world, 'get_posts', { post_type: 'lld_track', name: 'heart-health', post_status: ['publish'], posts_per_page: 1 })).toEqual([]);
  }
  const tester = { items: both, switch: 'test_accounts', member: TESTER };
  expect(ids('lld_track', tester)).toEqual([11, 12, 13, 14]);
  expect(ids('lld_goal', tester)).toEqual([21, 22, 23]);
  expect(ids('lld_wording', tester)).toEqual([31, 32, 33]);
  // the owner's admin screens and the loader (lld_all_stages) always see every stage, whatever the switch
  expect(ids('lld_track', { items: both, in_admin: true, admin: true })).toEqual([11, 12, 13, 14]);
  expect(run({ items: both }, 'get_posts', { post_type: 'lld_track', post_status: ['publish'], posts_per_page: -1, fields: 'ids', lld_all_stages: true })).toEqual([11, 12, 13, 14]);
  // the loader refuses a Stage 3 item without the owner's approval record, and any stage other than 3
  const approval = { decision: 'd-tracks-heart-health', file: 'tracks/heart-health.md', sha: '0123456789abcdef' };
  const item = (key, type, extra = {}) => ({ key, type, title: key, body: 'Body', tags: [], fields: {}, approval, ...extra });
  expect(loader('lld_content_check', { items: [item('track:heart-health', 'lld_track', { stage: 3, tags: ['heart-health'] })] })).toEqual([]);
  expect(loader('lld_content_check', { items: [item('track:heart-health', 'lld_track', { stage: 3, approval: {} })] }).join(' ')).toContain('no approval decision and fingerprint');
  expect(loader('lld_content_check', { items: [item('track:x', 'lld_track', { stage: 2 })] }).join(' ')).toContain('stage that is not 3');
  expect(loader('lld_content_check', { items: [item('track:x', 'lld_track', { stage: '3' })] }).join(' ')).toContain('stage that is not 3');
  // a Stage 2 item's fingerprint is what it was (no stage in it); a Stage 3 item's includes its stage
  const s2 = item('track:healthy-weight', 'lld_track');
  expect(loader('lld_content_item_hash', s2)).toBe(loader('lld_content_item_hash', { ...s2, stage: undefined }));
  expect(loader('lld_content_item_hash', { ...s2, stage: 3 })).not.toBe(loader('lld_content_item_hash', s2));
  expect(loader('lld_content_plan', { items: [s2, item('track:heart-health', 'lld_track', { stage: 3 })] }, { 'track:healthy-weight': loader('lld_content_item_hash', s2) }))
    .toEqual({ 'track:healthy-weight': 'same', 'track:heart-health': 'create' });
});

test('TC-FR3-23-AS-01 for each month of the year the challenge page shows that month\'s approved challenge when the switch allows the member', () => {
  const tester = { items: both, switch: 'test_accounts', member: TESTER };
  expect(run(tester, 'current_challenge', TESTER, '2026-12-15').title).toBe('28-day fibre boost');
  for (const month of MONTHS) expect(run(tester, 'current_challenge', TESTER, `${month}-15`).title).toBe(`Challenge ${month}`);
  expect(run(tester, 'next_challenge', TESTER, '2026-12-15').title).toBe('Challenge 2027-01');
  expect(run(tester, 'next_challenge', TESTER, '2027-11-15')).toBeNull();
  // every member, once the owner switches the content on for everyone
  for (const month of ['2027-03', '2027-11']) expect(run({ items: both, switch: 'everyone', member: MEMBER }, 'current_challenge', MEMBER, `${month}-01`).title).toBe(`Challenge ${month}`);
});

test('TC-FR3-23-AS-02 a 2027 challenge is shown to nobody the switch does not allow, and December 2026 stays as it is', () => {
  for (const world of [{ items: both }, { items: both, switch: 'test_accounts', member: MEMBER }]) {
    for (const month of MONTHS) expect(run(world, 'current_challenge', MEMBER, `${month}-15`)).toBeNull();
    expect(run(world, 'current_challenge', MEMBER, '2026-12-15').title).toBe('28-day fibre boost');
    expect(run(world, 'next_challenge', MEMBER, '2026-12-15')).toBeNull();
  }
});

test('TC-FR3-23-AS-02 (query shape) the exclusion is added only to member-facing library queries and keeps what the query asked for', () => {
  const vars = (q, world = { items: both }) => run(world, 'filter_vars', q);
  const notExists = { key: '_lld_stage', compare: 'NOT EXISTS' };
  expect(vars({ post_type: 'lld_challenge' }).meta_query).toEqual([notExists]);
  expect(vars({ post_type: ['lld_goal', 'lld_track'], meta_key: '_lld_source_key' }).meta_query).toEqual([notExists]);
  expect(vars({ post_type: 'lld_wording', meta_query: [{ key: 'x', value: '1' }] }).meta_query).toEqual({ relation: 'AND', 0: [{ key: 'x', value: '1' }], 1: notExists });
  // untouched: other post types, version rows, the admin screens, the loader, and when the switch allows the member
  expect(vars({ post_type: 'post' })).toEqual({ post_type: 'post' });
  expect(vars({ post_type: 'lld_track', post_status: 'lld_version', post_parent: 13 }).meta_query).toBeUndefined();
  expect(vars({ post_type: 'lld_track' }, { items: both, in_admin: true }).meta_query).toBeUndefined();
  expect(vars({ post_type: 'lld_track', lld_all_stages: true }).meta_query).toBeUndefined();
  expect(vars({ post_type: 'lld_track' }, { items: both, switch: 'everyone' }).meta_query).toBeUndefined();
  expect(vars({ post_type: 'lld_track' }, { items: both, switch: 'test_accounts', member: TESTER }).meta_query).toBeUndefined();
  expect(vars({ post_type: 'lld_track' }, { items: both, switch: 'test_accounts', member: MEMBER }).meta_query).toEqual([notExists]);
});
