// Approved content loader (S2-L6, Data Model B16): lld-tools/content-load.php builders, run in PHP CLI without WordPress.
// The live load (every bundle item approved and shown to members) is checked on staging with `wp lld content verify`.
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { ROOT } from '../lib/paths.mjs';

const PHP_FILE = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'content-load.php');

function call(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(PHP_FILE.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); $m = []; `
    + `$r = ${fn === 'lld_content_resolve' ? 'lld_content_resolve($a[0], $a[1], $m); $r = [$r, $m]' : `${fn}(...$a)`}; echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}

const approval = { decision: 'd-tracks-healthy-weight', file: 'tracks/healthy-weight.md', sha: '0123456789abcdef' };
const item = (key, type, extra = {}) => ({ key, type, title: key, body: 'Body', tags: [], fields: {}, approval, ...extra });

describe('approved content loader', () => {
  test('references become the linked post ids, in single values, lists and nested plans', () => {
    const ids = { 'track:healthy-weight': 11, 'recipe:R-001': 21, 'chact:D01': 31, 'chact:D02': 32 };
    const [out, missing] = call('lld_content_resolve', {
      track_id: '@track:healthy-weight', activity_ids: '@chact:D01,@chact:D02', week_number: 2, diet: 'everything',
      meals: { 1: { breakfast: '@recipe:R-001' } }, swaps: { '1-breakfast': ['@recipe:R-001'] },
    }, ids);
    expect(out).toEqual({ track_id: 11, activity_ids: '31,32', week_number: 2, diet: 'everything', meals: { 1: { breakfast: 21 } }, swaps: { '1-breakfast': [21] } });
    expect(missing).toEqual([]);
    // a reference to something not loaded is reported, never turned into 0
    const [kept, gone] = call('lld_content_resolve', { badge_id: '@badge:none', ids: '@chact:D01,@chact:none' }, ids);
    expect(kept).toEqual({ badge_id: '@badge:none', ids: '31,@chact:none' });
    expect(gone).toEqual(['badge:none', 'chact:none']);
  });

  test('a bundle loads only when every item is well formed, approved, and listed after what it links to', () => {
    const good = { items: [item('track:healthy-weight', 'lld_track', { tags: ['healthy-weight'] }), item('week:healthy-weight:1', 'lld_week', { fields: { track_id: '@track:healthy-weight' } })] };
    expect(call('lld_content_check', good)).toEqual([]);
    expect(call('lld_content_check', { items: [] })).toEqual(['the bundle has no items']);
    const problems = (items) => call('lld_content_check', { items }).join(' | ');
    expect(problems([item('week:x', 'lld_week', { fields: { track_id: '@track:later' } }), item('track:later', 'lld_track')])).toContain('refers to @track:later, which is not listed before it');
    expect(problems([item('a', 'lld_track'), item('a', 'lld_track')])).toContain('repeats a key');
    expect(problems([item('a', 'post')])).toContain('unknown type');
    expect(problems([item('a', 'lld_activity', { tags: ['Weight Loss'] })])).toContain('tag that is not a slug');
    expect(problems([item('a', 'lld_activity', { body: 'Breathe in and hold your breath for ten.' })])).toContain('breath-holding');
    expect(problems([item('a', 'lld_workout', { fields: { sessions: 'Plank | 20 | 15 | Hold your breath while you hold the plank' } })])).toContain('breath-holding');
    expect(problems([item('a', 'lld_activity', { tags: ['breath-holding'] })])).toContain('breath-holding tag');
    // approved safety advice against holding the breath loads word for word
    for (const body of ['Never hold your breath.', "Don't hold your breath: breathe out as you push.", 'Breathe steadily, without holding your breath. Avoid breath-holding.'])
      expect(call('lld_content_check', { items: [item('a', 'lld_activity', { body })] })).toEqual([]);
    // nothing loads without the owner's decision on the approval page and the fingerprint of the draft it approved
    expect(problems([item('a', 'lld_activity', { approval: {} })])).toContain('no approval decision and fingerprint');
    expect(problems([item('a', 'lld_activity', { approval: { decision: 'd-tracks-healthy-weight', sha: 'short' } })])).toContain('no approval decision and fingerprint');
  });

  test('a second run changes only the items whose content changed', () => {
    const a = item('track:healthy-weight', 'lld_track', { tags: ['healthy-weight', 'beginner'], fields: { status: 'live', measures: '' } });
    const b = item('lesson:healthy-weight:1', 'lld_lesson');
    const hash = call('lld_content_item_hash', a);
    expect(hash).toMatch(/^[0-9a-f]{16}$/);
    // tag order and field order do not count as a change; the text does
    expect(call('lld_content_item_hash', { ...a, tags: ['beginner', 'healthy-weight'], fields: { measures: '', status: 'live' } })).toBe(hash);
    expect(call('lld_content_item_hash', { ...a, body: 'Changed' })).not.toBe(hash);
    expect(call('lld_content_plan', { items: [a, b] }, {})).toEqual({ 'track:healthy-weight': 'create', 'lesson:healthy-weight:1': 'create' });
    expect(call('lld_content_plan', { items: [a, b] }, { 'track:healthy-weight': hash, 'lesson:healthy-weight:1': 'old' }))
      .toEqual({ 'track:healthy-weight': 'same', 'lesson:healthy-weight:1': 'update' });
  });
});
