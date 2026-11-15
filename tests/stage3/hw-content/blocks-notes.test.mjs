// Healthy Weight content loader (P3-35, package P3): focus blocks, mindset notes and their support wording, questions
// and the festival calendar, read back through LLD_Members_HW_Content from a synthetic bundle (hw-content-stubs.php).
import { describe, expect, test } from 'vitest';
import { URGENT, bundle, pure, run, withItem } from './hw-fixture.mjs';

describe('Focus blocks (FR3-104)', () => {
  test('TC-FR3-104-AS-04', () => {
    // A block has 4 weeks of approved content: theme, task, lesson, 7 daily activities and 7 notes a week, and an
    // unapproved item is never shown.
    const b = bundle();
    const block = run({ bundle: b, call: 'block', args: ['evening-night-eating'] }).result;
    expect(block.offer_rule_ids).toEqual(['R-HW-BLOCK-01']);
    expect(Object.keys(block.weeks)).toEqual(['1', '2', '3', '4']);
    for (const w of [1, 2, 3, 4]) {
      const week = block.weeks[w];
      expect(week.theme).toBe(`Block theme ${w}`);
      expect(week.task.title).toBe(`Block theme ${w}`);
      expect(week.lesson.title).toBe(`Block lesson ${w}`);
      expect(Object.keys(week.activities)).toEqual(['1', '2', '3', '4', '5', '6', '7']);
      expect(week.notes.map((n) => n.key)).toEqual([1, 2, 3, 4, 5, 6, 7].map((d) => `HW-FB01-MN-W${w}-${d}`));
    }
    expect(block.weeks[1].activities[1].meta.shown_under[0].when).toBe('shift_worker');
    expect(block.weeks[1].alts.map((a) => a.id)).toEqual(['HW-FB01-W1-A5-ALT']);
    expect(block.weeks[4].task_alt.fields.alt_for).toBe('HW-FB01-W4-T');
    const hidden = run({ bundle: b, unapproved: ['act:HW-FB01-W2-A3', 'lesson:evening-night-eating:3'], call: 'block', args: ['evening-night-eating'] }).result;
    expect(Object.keys(hidden.weeks[2].activities)).toEqual(['1', '2', '4', '5', '6', '7']);
    expect(hidden.weeks[3].lesson).toBeNull();
    // an unapproved block, a track that is not a block and an unknown key give nothing
    expect(run({ bundle: b, unapproved: ['track:evening-night-eating'], call: 'block', args: ['evening-night-eating'] }).result).toBeNull();
    expect(run({ bundle: b, call: 'block', args: ['healthy-weight'] }).result).toBeNull();
    expect(run({ bundle: b, call: 'block', args: ['no-such-block'] }).result).toBeNull();
    // the screens ask by content id (HW-FB01 to HW-FB09) and read its title
    expect(run({ bundle: b, call: 'block', args: ['HW-FB01'] }).result).toMatchObject({ key: 'evening-night-eating', content_id: 'HW-FB01', title: 'Test block' });
    expect(run({ bundle: b, call: 'block', args: ['HW-FB02'] }).result).toBeNull();
  });

  test('the approved milestone lines come back by their ids (HW-MS-03, HW-MS-03-older, HW-MS-04)', () => {
    const b = bundle();
    expect(run({ bundle: b, call: 'block', args: ['HW-MS-03'] }).result).toMatchObject({ text: 'A made-up line for hw-ms-03.' });
    expect(run({ bundle: b, call: 'block', args: ['HW-MS-03-older'] }).result).toMatchObject({ text: 'A made-up line for hw-ms-03-65-plus.' });
    expect(run({ bundle: b, call: 'block', args: ['HW-MS-04'] }).result).toMatchObject({ text: 'A made-up milestone line.' });
    expect(run({ bundle: b, unapproved: ['wording:hw-ms-04'], call: 'block', args: ['HW-MS-04'] }).result).toBeNull();
  });
});

describe('Mindset notes (FR3-105)', () => {
  test('TC-FR3-105-AS-02', () => {
    // The 56 programme notes and the block's 28 load with approval records and come back in their approved order;
    // an unapproved note is never shown, and a note without its approval record cannot load.
    const b = bundle();
    expect(b.items.filter((it) => it.type === 'lld_note' && it.key.startsWith('note:HW-MN-'))).toHaveLength(56);
    expect(b.items.filter((it) => it.type === 'lld_note').every((it) => /^[0-9a-f]{16}$/.test(it.approval.sha))).toBe(true);
    const notes = run({ bundle: b, call: 'notes', args: ['healthy-weight'] }).result;
    expect(notes.map((n) => n.key)).toEqual(Array.from({ length: 56 }, (_, i) => `HW-MN-W${Math.floor(i / 7) + 1}-${(i % 7) + 1}`));
    expect(notes[0]).toMatchObject({ week: 1, day: 1, try_this: 'try a made-up thing for HW-MN-W1-1.' });
    expect(run({ bundle: b, call: 'notes', args: ['evening-night-eating'] }).result).toHaveLength(28);
    const without = run({ bundle: b, unapproved: ['note:HW-MN-W3-3'], call: 'notes', args: ['healthy-weight'] }).result;
    expect(without.map((n) => n.key)).not.toContain('HW-MN-W3-3');
    expect(without).toHaveLength(55);
    const unrecorded = withItem(b, 'note:HW-MN-W1-1', (it) => ({ ...it, approval: { decision: 'd-x' } }));
    expect(run({ bundle: unrecorded }).problems.join('\n')).toContain('(note:HW-MN-W1-1) has no approval decision and fingerprint');
    const noLine = withItem(b, 'note:HW-MN-W1-2', (it) => ({ ...it, fields: { ...it.fields, try_this: '' } }));
    expect(run({ bundle: noLine }).problems.join('\n')).toContain("(note:HW-MN-W1-2) is a note without its text and 'Try this today' line");
  });

  test('TC-FR3-105-AS-03', () => {
    // A note about stress or mood shows the approved support wording: the loader refuses one without it, as it does
    // every stress and mood item (NFR2-07), and the note says it carries it.
    const b = bundle();
    const note = run({ bundle: b, call: 'notes', args: ['healthy-weight'] }).result.find((n) => n.key === 'HW-MN-W4-4');
    expect(note.support_wording).toBe(true);
    expect(note.body).toContain(URGENT);
    const stripped = withItem(b, 'note:HW-MN-W4-4', (it) => ({ ...it, body: it.body.replace(URGENT, '').trim() }));
    expect(run({ bundle: stripped }).problems.join('\n')).toContain('(note:HW-MN-W4-4) lacks the urgent-help link (findahelpline.com) (NFR2-07)');
    const stress = withItem(b, 'note:HW-MN-W8-1', (it) => ({ ...it, tags: ['healthy-weight', 'stress'] }));
    expect(run({ bundle: stress }).problems.join('\n')).toContain('(note:HW-MN-W8-1) lacks the urgent-help link');
    // Every note with support wording carries the approved line for the screen (support_line, and block('urgent_help'));
    // the sleep note (tagged sleep, owner N4) carries neither the flag nor the line.
    const all = [...run({ bundle: b, call: 'notes', args: ['healthy-weight'] }).result, ...run({ bundle: b, call: 'notes', args: ['evening-night-eating'] }).result];
    for (const n of all) {
      if (n.support_wording) {
        expect(n.support, n.key).toBe(true);
        expect(n.support_line, n.key).toBe(URGENT);
        expect(n.body, n.key).toContain(URGENT);
      } else {
        expect(n.support_line, n.key).toBeNull();
        expect(n.body, n.key).not.toContain('findahelpline.com');
      }
    }
    const sleep = all.find((n) => n.key === 'HW-MN-W1-6');
    expect(sleep.tags).toContain('sleep');
    expect(sleep.support).toBe(false);
    expect(sleep.support_line).toBeNull();
    expect(run({ bundle: b, call: 'block', args: ['urgent_help'] }).result).toMatchObject({ text: URGENT, body: URGENT });
    expect(pure('LLD_Members_HW_Content::block', 'urgent_help').text).toBe(URGENT);
  });
});

describe('Questions and the festival calendar (FR3-97 to FR3-99, FR3-104)', () => {
  test('questions come back by answer key or id, in order, with answer values the engine reads', () => {
    const b = bundle();
    const all = run({ bundle: b, call: 'questions' }).result;
    expect(all.map((q) => q.id_key)).toEqual(['HW-QW-01', 'HW-QW-09']);
    const q = run({ bundle: b, call: 'question', args: ['sleep'] }).result;
    expect(q).toMatchObject({ key: 'sleep', id_key: 'HW-QW-09', text: 'A made-up sleep question?', why: 'A made-up reason.', type: 'choice',
      choices: [{ value: 'under_6', label: 'Short' }, { value: '6_7', label: 'Long' }], follow_up: null, asked_in: 'joining', rule_ids: ['R-HW-STEP-04'] });
    expect(q.excluded_for).toContain('eating_disorder');
    expect(run({ bundle: b, call: 'question', args: ['HW-QW-09'] }).result).toEqual(q);
    expect(run({ bundle: b, call: 'question', args: ['activity_level'] }).result).toMatchObject({ note: 'A made-up line shown with it.', choices: [{ value: 'low' }, { value: 'some' }] });
    expect(run({ bundle: b, call: 'question', args: ['HW-QW-99'] }).result).toBeNull();
    expect(run({ bundle: b, unapproved: ['question:HW-QW-09'], call: 'question', args: ['sleep'] }).result).toBeNull();
    // the values are the engine's (LLD_Members_HW_Rules class comment), and a scale's ends and a follow-up come from the
    // approved wording
    const view = (fields, title = 'Q?') => pure('LLD_Members_HW_Content::question_view', { title, body: '', fields }, 1);
    expect(view({ question_key: 'HW-QW-05', choices: ['a', 'b', 'c'] }).choices.map((c) => c.value)).toEqual(['under_10', '10_30', 'over_30']);
    expect(view({ question_key: 'HW-QW-06', choices: ['a', 'b', 'c', 'd'] }).choices.map((c) => c.value)).toEqual(['days', 'shifts', 'nights', 'irregular']);
    expect(view({ question_key: 'HW-QW-12', choices: ['a', 'b', 'c'] }).choices.map((c) => c.value)).toEqual(['1_2', '3_4', '5_plus']);
    expect(view({ question_key: 'HW-QW-08', choices: [], answer: 'a 1 to 10 scale, labelled "1 = not at all sure" and "10 = very sure".' }))
      .toMatchObject({ key: 'confidence', type: 'scale', min: 1, max: 10, low: 'not at all sure', high: 'very sure' });
    expect(view({ question_key: 'HW-QW-16', choices: ['a', 'b', 'c'], follow_up: 'Follow-up after "Most days": "A made-up follow-up?" · Yes · No · Not sure. More.' }).follow_up)
      .toEqual({ key: 'breakfast_protein', when: ['most_days'], text: 'A made-up follow-up?', type: 'choice',
        choices: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }, { value: 'not_sure', label: 'Not sure' }] });
    const ruleless = withItem(b, 'question:HW-QW-01', (it) => ({ ...it, fields: { ...it.fields, rule_ids: [] } }));
    expect(run({ bundle: ruleless }).problems.join('\n')).toContain('(question:HW-QW-01) is a question with no signed rule');
  });

  test('the festival calendar is checked, written to the table package P2 creates, and read back for the engine', () => {
    const b = bundle();
    const cols = ['id', 'festival_key', 'name', 'kind', 'starts_on', 'ends_on', 'expected', 'approved_on', 'offers_block', 'tradition'];
    const r = run({ bundle: b, table: { columns: cols }, call: 'load_festivals', args: [b.festivals] });
    expect(r.result).toMatchObject({ written: 2, created: 2, updated: 0, skipped: '', problems: [] });
    expect(r.rows[0]).toMatchObject({ festival_key: 'CAL-TEST-2027', kind: 'dawn_to_sunset_fast', starts_on: '2027-02-08', expected: 1, offers_block: 1 });
    // no table yet: nothing written, and festivals() is empty
    expect(run({ bundle: b, call: 'load_festivals', args: [b.festivals] }).result.skipped).toContain('does not exist yet');
    expect(run({ bundle: b, call: 'festivals' }).result).toEqual([]);
    // a bad entry stops the bundle and the write
    const bad = [{ ...b.festivals[0], kind: 'party', ends_on: '2027-01-01' }];
    const problems = pure('LLD_Members_HW_Content::festival_problems', bad);
    expect(problems).toEqual(expect.arrayContaining(['festival 1 (CAL-TEST-2027) has an unknown kind', 'festival 1 (CAL-TEST-2027) ends before it starts']));
    expect(run({ bundle: { ...b, festivals: bad } }).problems.join('\n')).toContain('has an unknown kind');
    expect(run({ bundle: b, table: { columns: cols }, call: 'load_festivals', args: [bad] }).result.written).toBe(0);
    // read back in date order, with the engine's {id, start} (LLD_Members_HW_Festival::offer)
    expect(pure('LLD_Members_HW_Content::festival_view', { id: 3, festival_key: 'CAL-TEST-2027', name: 'Test fast', starts_on: '2027-02-08', ends_on: '2027-03-08', expected: '1', approved_on: '2026-11-10', offers_block: '1' }))
      .toMatchObject({ id: 'CAL-TEST-2027', start: '2027-02-08', end: '2027-03-08', expected: true, offers_block: true });
    expect(pure('LLD_Members_HW_Content::festival_row', b.festivals[1], ['id', 'name', 'starts_on', 'expected'], 'entry_key'))
      .toEqual({ entry_key: 'CAL-ONE-20270103', name: 'Test day', starts_on: '2027-01-03', expected: 0 });
  });
});
