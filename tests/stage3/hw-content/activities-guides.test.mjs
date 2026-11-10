// Healthy Weight content loader (P3-35, package P3): the "Counting made easy" guide, the calorie rewording, activity
// versions and habit tags. A synthetic bundle goes through the real loader checks into a stand-in library, and
// LLD_Members_HW_Content reads it back (hw-content-stubs.php).
import { describe, expect, test } from 'vitest';
import { bundle, pure, run, withItem } from './hw-fixture.mjs';

describe('Counting made easy and the calorie rewording (FR3-102, FR3-20)', () => {
  test('TC-FR3-102-AS-02', () => {
    // The guide loads as a Healthy Weight guide for weeks 1 and 2 with its approval record, and only as approved.
    const b = bundle();
    const guide = b.items.find((it) => it.key === 'guide:counting-made-easy');
    expect(guide.fields.weeks_shown).toEqual([1, 2]);
    expect(guide.approval.decision).toMatch(/^d-[a-z0-9-]+$/);
    expect(guide.approval.sha).toMatch(/^[0-9a-f]{16}$/);
    const r = run({ bundle: b });
    expect(r.problems).toEqual([]);
    expect(r.ids['guide:counting-made-easy']).toBeGreaterThan(r.ids['track:healthy-weight']);
    // without its approval record the bundle cannot load
    const bad = withItem(b, 'guide:counting-made-easy', (it) => ({ ...it, approval: {} }));
    expect(run({ bundle: bad }).problems.join('\n')).toContain('(guide:counting-made-easy) has no approval decision and fingerprint');
  });

  test('TC-FR3-102-AS-07', () => {
    // A new Healthy Weight lesson or activity that says calorie numbers never appear is refused; the bundle's own
    // items say no such thing.
    const b = bundle();
    expect(pure('LLD_Members_HW_Content::content_problems', b.items)).toEqual([]);
    for (const [key, text] of [['act:HW-W1-A2', 'Eat well without counting.'], ['lesson:evening-night-eating:2', '<p>You will not count anything.</p>'],
      ['note:HW-MN-W2-1', 'There are no calorie numbers here.']]) {
      const changed = withItem(b, key, (it) => ({ ...it, body: text }));
      expect(run({ bundle: changed }).problems.join('\n')).toContain(`(${key}) says calorie numbers never appear`);
    }
    // an older Stage 2 item without the new fields is not this check's business (it is replaced, not reloaded)
    const old = { key: 'act:HW-W1-A2', type: 'lld_activity', title: 'Old', body: 'Eat well without counting.', tags: [], fields: {}, approval: {} };
    expect(pure('LLD_Members_HW_Content::content_problems', [old])).toEqual([]);
  });

  test('TC-FR3-20-AS-03', () => {
    // No approved Keep It Off item says calorie numbers never appear.
    const b = bundle();
    expect(run({ bundle: b }).problems).toEqual([]);
    const changed = withItem(b, 'act:KO-W1-A1', (it) => ({ ...it, body: 'No calorie counting, ever.' }));
    expect(run({ bundle: changed }).problems.join('\n')).toContain('(act:KO-W1-A1) says calorie numbers never appear');
  });
});

describe('Activity versions and habit tags (FR3-103)', () => {
  test('TC-FR3-103-AS-04', () => {
    // Every level 1 activity has at least one habit tag and approved easier and stretch versions, read back by
    // draft id, bundle key or post id; the lines shown under a step come with when they are shown.
    const b = bundle();
    const ids = b.items.filter((it) => /^act:HW2?-W\d-A\d$/.test(it.key)).map((it) => it.key.slice(4));
    expect(ids).toHaveLength(7);
    for (const id of ids) {
      const meta = run({ bundle: b, call: 'activity_meta', args: [id] }).result;
      expect(meta.habit_tags.length, id).toBeGreaterThan(0);
      expect(meta.easier, id).not.toBe('');
      expect(meta.stretch, id).not.toBe('');
    }
    const byKey = run({ bundle: b, call: 'activity_meta', args: ['act:HW-W1-A4'] }).result;
    expect(byKey).toMatchObject({ id: 'HW-W1-A4', title: 'Step HW-W1-A4' });
    expect(byKey.shown_under).toEqual([{ text: 'A made-up line under the step.', when: 'always' }]);
    const r = run({ bundle: b });
    expect(run({ bundle: b, call: 'activity_meta', args: [r.ids['act:HW-W1-A4']] }).result).toEqual(byKey);
    // a block -ALT item names the activity it stands in for
    expect(run({ bundle: b, call: 'activity_meta', args: ['HW-FB01-W1-A5-ALT'] }).result.alt_for).toBe('HW-FB01-W1-A5');
    // an unapproved activity gives nothing; a habit tag off the approved list is refused at load
    expect(run({ bundle: b, unapproved: ['act:HW-W1-A3'], call: 'activity_meta', args: ['HW-W1-A3'] }).result).toBeNull();
    const bad = withItem(b, 'act:HW-W1-A5', (it) => ({ ...it, fields: { ...it.fields, habit_tags: ['made-up-habit'] } }));
    expect(run({ bundle: bad }).problems.join('\n')).toContain('(act:HW-W1-A5) has a habit tag that is not on the approved list');
    const noWhen = withItem(b, 'act:HW-W1-A4', (it) => ({ ...it, fields: { ...it.fields, shown_under: [{ text: 'x', when: 'sometimes' }] } }));
    expect(run({ bundle: noWhen }).problems.join('\n')).toContain('line under the step without its wording or when it is shown');
  });
});
