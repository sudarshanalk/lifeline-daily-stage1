// P3-35 follow-ups: (1) Stage 3 Healthy Weight content takes its NFR2-07 lines from its own goal tags, not its track's
// title (owner, FB06-Q1 = A: sleep content carries no urgent-help line), while Stage 2 tracks keep the title rule;
// (2) a mindset note or programme question cannot lose its fields through the Stage 2 field form in wp-admin.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';
import { SNORING, URGENT, pure } from './hw-fixture.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const approval = { decision: 'd-test-fb06', file: 'healthy-weight-programme/fb06.md', sha: '0123456789abcdef' };
const S3 = (key, type, extra) => ({ key, type, stage: 3, title: 'T ' + key, body: 'Made-up text.', tags: ['healthy-weight'], fields: {}, approval, ...extra });
const block = S3('track:sleep-and-weight', 'lld_track', { title: 'Sleep and weight', slug: 'sleep-and-weight',
  fields: { status: 'live', track_kind: 'focus_block', offer_rule_ids: ['R-HW-BLOCK-01'], offer_window: '' } });
const inBlock = (key, type, tags, body, fields = {}) => S3(key, type, { tags, body, fields: { track_id: '@track:sleep-and-weight', week_number: 1, ...fields } });
const problems = (items) => pure('lld_content_check', { items });

describe('Healthy Weight safety lines from goal tags, not the track title (FB06-Q1)', () => {
  test('a Sleep and weight item tagged sleep needs the snoring line and no urgent-help line', () => {
    const sleepAct = inBlock('act:HW-FB06-W1-A1', 'lld_activity', ['healthy-weight', 'sleep'], 'Made-up step.\n\n' + SNORING, { day_number: 1 });
    const sleepNote = inBlock('note:HW-FB06-MN-W1-1', 'lld_note', ['healthy-weight', 'sleep'], 'Made-up note.\n\n' + SNORING,
      { note_key: 'HW-FB06-MN-W1-1', week: 1, day: 1, try_this: 'try it.', support_wording: 0 });
    const lesson = inBlock('lesson:sleep-and-weight:1', 'lld_lesson', ['healthy-weight', 'sleep'], '<p>Made-up lesson.</p><p>' + SNORING + '</p>');
    const untagged = inBlock('week:sleep-and-weight:1', 'lld_week', ['healthy-weight'], 'Made-up task.');
    expect(problems([block, sleepAct, sleepNote, lesson, untagged])).toEqual([]);
    // the snoring line is still required by the sleep tag
    const noSnore = { ...sleepAct, body: 'Made-up step.' };
    expect(problems([block, noSnore]).join('\n')).toContain('lacks the loud snoring with daytime sleepiness advice');
  });

  test('a Sleep and weight item tagged stress or mood needs the urgent-help line', () => {
    for (const tag of ['stress', 'mood']) {
      const bare = inBlock('act:HW-FB06-W3-A6', 'lld_activity', ['healthy-weight', tag], 'Made-up step.', { day_number: 6 });
      expect(problems([block, bare]).join('\n'), tag).toContain('(act:HW-FB06-W3-A6) lacks the urgent-help link (findahelpline.com)');
      expect(problems([block, { ...bare, body: 'Made-up step.\n\n' + URGENT }]), tag).toEqual([]);
    }
    // a Healthy Weight note under the programme track, and a sleep, stress or alcohol step, follow their tags too
    const step = S3('act:HW-SL-01', 'lld_activity', { tags: ['healthy-weight', 'sleep'], body: 'Made-up step.\n\n' + SNORING,
      fields: { track_id: '@track:sleep-and-weight' } });
    expect(problems([block, step])).toEqual([]);
  });

  test('Stage 2 tracks keep the title rule, and so does any item that is not Healthy Weight programme content', () => {
    const stage2 = { key: 'track:sleep-stress', type: 'lld_track', title: 'Sleep and Stress', slug: 'sleep-stress', body: 'B', tags: ['sleep-stress'], fields: {}, approval };
    const act = { key: 'act:SS-W1-A1', type: 'lld_activity', title: 'T', body: 'Made-up step.', tags: [], fields: { track_id: '@track:sleep-stress' }, approval };
    const out = problems([{ ...stage2, body: 'B ' + URGENT + ' ' + SNORING }, act]).join('\n');
    expect(out).toContain('(act:SS-W1-A1) lacks the urgent-help link');
    expect(out).toContain('(act:SS-W1-A1) lacks the loud snoring with daytime sleepiness advice');
    // a Stage 3 item under a Stage 3 track that is not a focus block keeps the title rule as well
    const s3track = { ...stage2, key: 'track:sleep-2', slug: 'sleep-2', stage: 3, body: 'B ' + URGENT + ' ' + SNORING };
    const s3act = { ...act, key: 'act:S2-W1-A1', stage: 3, fields: { track_id: '@track:sleep-2' } };
    expect(problems([s3track, s3act]).join('\n')).toContain('(act:S2-W1-A1) lacks the urgent-help link');
    // the same item without a stage, inside a focus block, is not treated as Stage 3 content
    expect(pure('lld_content_hw_tags_only', { key: 'note:x', type: 'lld_note' }, false)).toBe(false);
    expect(pure('lld_content_hw_tags_only', { key: 'act:HW-ST-02', type: 'lld_activity', stage: 3 }, false)).toBe(true);
    expect(pure('lld_content_hw_tags_only', { key: 'act:HW-W1-A1', type: 'lld_activity', stage: 3 }, false)).toBe(false);
  });
});

function form(input) {
  return JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', resolve(here, 'form-stubs.php')], { input: JSON.stringify(input), encoding: 'utf8' }));
}

describe('Notes and questions are not edited through the Stage 2 field form', () => {
  const question = { question_key: 'HW-QW-09', choices: ['Short', 'Long'], why: 'A made-up reason.', asked_in: 'joining', rule_ids: ['R-HW-STEP-04'], excluded_for: ['pregnancy'] };
  const note = { note_key: 'HW-MN-W1-1', track_id: 11, week: 1, day: 1, try_this: 'try it.', support_wording: 0 };
  const posted = { lld_content_nonce: 'ok', lld_fields: { why: 'changed' }, lld_tags: 'mood' };

  test('saving an lld_question or lld_note keeps every field and tag as loaded, and the form says why', () => {
    for (const [type, fields] of [['lld_question', question], ['lld_note', note]]) {
      const r = form({ type, fields, posted });
      expect(r.saved, type).toBeNull();
      expect(r.tags, type).toBeNull();
      expect(r.box, type).toContain('changed only by reloading the owner-approved content');
      expect(r.box, type).not.toContain('name="lld_fields');
    }
  });

  test('a Stage 2 type is saved by the form as before', () => {
    const r = form({ type: 'lld_week', fields: { track_id: 3, week_number: 1 }, posted: { ...posted, lld_fields: { track_id: '3', week_number: '2' } } });
    expect(r.saved).toEqual({ track_id: 3, week_number: 2 });
    expect(r.tags).toEqual(['mood']);
    expect(r.box).not.toContain('changed only by reloading');
  });
});
