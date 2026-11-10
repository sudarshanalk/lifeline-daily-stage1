// P3-35 adds two content types and the Healthy Weight checks to the approved content loader (Owner Question 160:
// additions only). The Stage 2 types, the Stage 2 checks and the fingerprints of loaded Stage 2 items stay as they were.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { LOADER, pure, root } from './hw-fixture.mjs';

const STAGE2_TYPES = ['lld_goal', 'lld_track', 'lld_week', 'lld_lesson', 'lld_activity', 'lld_challenge', 'lld_recipe', 'lld_meal',
  'lld_workout', 'lld_rule', 'lld_badge', 'lld_matrix', 'lld_wording'];
const approval = { decision: 'd-tracks-healthy-weight', file: 'tracks/healthy-weight.md', sha: '0123456789abcdef' };
const item = (key, type, extra = {}) => ({ key, type, title: 'T ' + key, body: 'B', tags: [], fields: {}, approval, ...extra });

describe('Stage 2 content types and loader behave as before (P3-35)', () => {
  test('the loader keeps every Stage 2 type in its order and only adds lld_note and lld_question', () => {
    const src = readFileSync(LOADER, 'utf8');
    const list = src.match(/const LLD_CONTENT_TYPES = \[([^\]]+)\]/)[1].match(/'([a-z_]+)'/g).map((s) => s.slice(1, -1));
    expect(list.slice(0, STAGE2_TYPES.length)).toEqual(STAGE2_TYPES);
    expect(list.slice(STAGE2_TYPES.length)).toEqual(['lld_note', 'lld_question']);
  });

  test('LLD_Members_Content::types() keeps its 14 types and adds the two new ones at the end', () => {
    const src = readFileSync(resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/class-content.php'), 'utf8');
    const body = src.match(/public static function types\(\) \{\s*return array\(([\s\S]*?)\);/)[1];
    const types = body.match(/'([a-z_]+)'/g).map((s) => s.slice(1, -1));
    expect(types).toEqual([...STAGE2_TYPES, 'lld_qa', 'lld_note', 'lld_question']);
  });

  test('a Stage 2 bundle gets exactly the Stage 2 checks, with or without the Healthy Weight class', () => {
    const good = { items: [item('track:fitness', 'lld_track', { tags: ['fitness'] }), item('week:fitness:1', 'lld_week', { fields: { track_id: '@track:fitness' } })] };
    expect(pure('lld_content_check', good)).toEqual([]);
    expect(pure('lld_content_hw_problems', good)).toEqual([]);
    const bad = { items: [item('a', 'lld_activity', { tags: ['Not A Slug'] }), item('a', 'lld_nope'), item('b', 'lld_week', { fields: { track_id: '@track:missing' } })] };
    expect(pure('lld_content_check', bad)).toEqual([
      'item 1 (a) has a tag that is not a slug',
      'item 2 (a) repeats a key',
      'item 2 (a) has an unknown type',
      'item 3 (b) refers to @track:missing, which is not listed before it',
    ]);
    // an old-style Healthy Weight activity (no habit tags, no stage) is checked as before
    const old = { items: [item('track:healthy-weight', 'lld_track', { tags: ['healthy-weight'] }),
      item('act:HW-W2-A1', 'lld_activity', { body: 'Eat well without counting.', fields: { track_id: '@track:healthy-weight' } })] };
    expect(pure('lld_content_check', old)).toEqual([]);
  });

  test('the fingerprint of a Stage 2 item is unchanged, so a reload of unchanged Stage 2 items changes nothing', () => {
    const a = item('track:healthy-weight', 'lld_track', { tags: ['healthy-weight', 'beginner'], fields: { status: 'live', measures: '' } });
    const hash = pure('lld_content_item_hash', a);
    expect(hash).toMatch(/^[0-9a-f]{16}$/);
    // the fingerprint is the Stage 2 recipe: type, title, slug, body, sorted tags, sorted fields, approval
    const code = 'echo substr(hash("sha256", json_encode(["lld_track", "T track:healthy-weight", "", "B", ["beginner", "healthy-weight"], ["measures" => "", "status" => "live"], ' +
      '["decision" => "d-tracks-healthy-weight", "file" => "tracks/healthy-weight.md", "sha" => "0123456789abcdef"]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);';
    expect(pure('lld_content_plan', { items: [a] }, { 'track:healthy-weight': hash })).toEqual({ 'track:healthy-weight': 'same' });
    expect(hash).toBe(execPhp(code));
  });
});

function execPhp(code) {
  return execFileSync('php', ['-r', code], { encoding: 'utf8' }).trim();
}
