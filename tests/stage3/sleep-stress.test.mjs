// Sleep and Stress Check (P3-5A part 3, FR3-54): the engine is run in PHP CLI without WordPress against the frozen
// fixtures and the independent Python oracle; the wording is checked against the signed approval record.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const engine = resolve(plugin, 'includes/stage3/calculators/class-sleep-stress.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const fixtures = json('fixtures/sleep-stress/sleep-stress.json').fixtures;
const approval = json('approvals/stage3/sleep-stress.json');
const oracle = json('oracles/orc-13-sleep-stress/results.json');
const phpArr = (v) => `json_decode(${JSON.stringify(JSON.stringify(v))}, true)`;

const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return LLD_Members_Sleep_Stress::compute($i); }, ${phpArr(inputs)}));`));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const calm = { q0: 'no', q1: 0, q2: 0, q3: 0, q4: 0, q5: 'no', q6: null, q7: 'no', q8: 8 };

test('TC-FR3-54-AS-01', () => {
  // Every score matches the official scoring for the reference answers: the frozen fixtures and the oracle written
  // independently from the sheet ("summary" is the sheet's prose, not a value).
  const results = compute(fixtures.map((f) => f.inputs));
  const problems = [];
  fixtures.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) {
      if (k === 'summary') continue;
      if (!same(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
    }
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    expect(orc, f.id).toBeTruthy();
    for (const [k, want] of Object.entries(orc.expected)) if (!same(results[i][k], want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != oracle ${JSON.stringify(want)}`);
  });
  expect(problems).toEqual([]);
  expect(oracle.oracle_id).toBe('ORC-13');
  expect(oracle.results).toHaveLength(fixtures.length);

  // Every combination of GAD-2 and PHQ-2 answers scores as published (sum 0-6, >= 3 worth attention); Q6 counts only
  // with Q5 Yes; under 7 hours scores 1.
  const grid = [];
  for (let a = 0; a <= 3; a++) for (let b = 0; b <= 3; b++) grid.push({ ...calm, q1: a, q2: b, q3: b, q4: a });
  compute(grid).forEach((r, i) => {
    const s = grid[i].q1 + grid[i].q2;
    expect([r.stressScore, r.moodScore]).toEqual([s, s]);
    expect(r.stressResult).toBe(s >= 3 ? 'result.stress.attention' : 'result.stress.usual');
    expect(r.moodResult).toBe(s >= 3 ? 'result.mood.attention' : null);
  });
  const [q6NoQ5, short, seven] = compute([{ ...calm, q6: 'yes' }, { ...calm, q8: 6.9 }, { ...calm, q8: 7 }]);
  expect([q6NoQ5.sleepCount, short.sleepCount, seven.sleepCount]).toEqual([0, 1, 0]);
  // Answers outside the scales are refused; Q6 is required once Q5 is Yes.
  const bad = compute([{ ...calm, q1: 4 }, { ...calm, q3: 1.5 }, { ...calm, q8: 17 }, { ...calm, q5: 'yes', q6: null }, { ...calm, q0: 'maybe' }, { ...calm, q7: [] }]);
  expect(bad.map((r) => r.error)).toEqual(['q1', 'q3', 'q8', 'q6', 'q0', 'q7']);
  // Yes / no answers read the same as true / false and "true" / "false"; "no" never counts as yes.
  const yn = compute([{ ...calm, q0: true }, { ...calm, q0: 'TRUE' }, { ...calm, q0: false }, { ...calm, q0: 'false' }, { ...calm, q5: true, q6: 'true', q7: 'yes' }, { ...calm, q5: 'no', q7: false }, { ...calm, q0: 1 }]);
  expect(yn.map((r) => r.response1 ?? r.error)).toEqual([true, true, false, false, false, false, 'q0']);
  expect([yn[4].sleepCount, yn[5].sleepCount]).toEqual([3, 0]);
});

test('TC-FR3-54-AS-02', () => {
  // A high score shows the approved support wording and the help-line information; a self-harm Yes shows Stage 2
  // Response 1 alone, with no scores and no track offer, whatever else was answered (even incomplete answers).
  const results = compute(fixtures.map((f) => f.inputs));
  const Q = JSON.parse(php('echo json_encode(LLD_Members_Sleep_Stress::QUOTED);'));
  results.forEach((r, i) => {
    const id = fixtures[i].id;
    if (r.response1) {
      expect(r.messages, id).toEqual(['quoted.response1']);
      expect(r.toSave, id).toBeNull();
      return;
    }
    const high = r.stressScore >= 3 || r.moodScore >= 3 || r.sleepCount >= 3;
    expect(r.messages.includes('quoted.helpFooter'), id).toBe(high);
    if (r.stressScore >= 3) expect(r.messages, id).toContain('result.stress.doctor');
    if (r.moodScore >= 3) expect(r.messages, id).toContain('quoted.response3');
    if (r.sleepCount >= 3) expect(r.messages, id).toContain('result.sleep.insomnia');
    // Only the scores are offered for saving (D3-30); the answers are never in the result to keep.
    expect(Object.keys(r.toSave).sort(), id).toEqual(['moodScore', 'sleepCount', 'stressScore']);
  });
  // The reused Stage 2 safety text is pinned byte for byte. Source: 02 Content/Programme content/safety/
  // screening-sleep-and-stress.md (outside the repository, so CI cannot read it): each value is that file's quoted
  // block under "Response 1.", "Response 3." and "Urgent-help link on every stress and mood page", with only the
  // leading "> " of each line removed and the lines joined with a line feed. Not in the approval's copy_hashes.
  const pinned = {
    response1: '8a2e6b49f71ff20206ace1da08820903f114c4cd635943920d0989c0ee3b8de1',
    response3: '307b97faaf5ed8c1b3d0e0b004987a428464ea856379dd03fe634dd1a12061b4',
    helpFooter: '8ed4248443499b2bfd2f7d13e900e550795d94fa74c25533b78535e07c3cf4fe',
  };
  expect(Object.keys(Q).sort()).toEqual(Object.keys(pinned).sort());
  for (const [k, h] of Object.entries(pinned)) expect(createHash('sha256').update(Q[k], 'utf8').digest('hex'), k).toBe(h);
  const [harmOnly] = compute([{ q0: 'yes' }]);
  expect(harmOnly.response1).toBe(true);
  expect(harmOnly.trackOffer).toBe(false);
  expect([harmOnly.stressScore, harmOnly.moodScore, harmOnly.sleepCount]).toEqual([null, null, null]);
  for (const phrase of ['local emergency number', '999 in the UK', '911 in the US and Canada', '112 in the EU and India', '000 in Australia', 'findahelpline.com']) {
    expect(Q.response1).toContain(phrase);
    expect(Q.helpFooter).toContain(phrase.replace('or 000', '000'));
  }
  expect(Q.response3).toMatch(/findahelpline\.com/);
  // No diagnosis, no sleep-medicine suggestion, no breath-holding anywhere in the wording (sheet 3.4, D36).
  const M = JSON.parse(php('echo json_encode(LLD_Members_Sleep_Stress::MESSAGES);'));
  const all = [...Object.values(M), ...Object.values(Q)].join('\n');
  expect(all).not.toMatch(/you have (anxiety|insomnia|depression)|sleeping pill|melatonin|hold your breath|breath-hold/i);
});

test('NFR3-14 (sleep and stress wording): every signed string hashes to the approval record', () => {
  const M = JSON.parse(php('echo json_encode(LLD_Members_Sleep_Stress::MESSAGES);'));
  const problems = [];
  for (const [key, hash] of Object.entries(approval.copy_hashes)) {
    const got = M[key] === undefined ? '(missing)' : createHash('sha256').update(M[key], 'utf8').digest('hex');
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(Object.keys(approval.copy_hashes).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  // Every key a result returns, and every question, has signed text.
  const results = compute(fixtures.map((f) => f.inputs));
  const keys = [...new Set(results.flatMap((r) => r.messages))];
  const QS = JSON.parse(php('echo json_encode(LLD_Members_Sleep_Stress::QUESTIONS);'));
  const W = JSON.parse(php(`echo json_encode(LLD_Members_Sleep_Stress::wording(${phpArr([...keys, ...QS])}));`));
  for (const k of [...keys, ...QS]) expect(W[k], k).toBeTruthy();
  // The short-sleep note (CW-19, approved 3 November 2026) shows exactly when the engine sets it, after the insomnia line.
  for (const r of results.filter((x) => x.messages.includes('result.sleep.needs') || x.shortSleepNote)) {
    expect(r.messages.includes('result.sleep.shortSleep')).toBe(r.shortSleepNote);
    if (r.shortSleepNote) expect(r.messages.indexOf('result.sleep.shortSleep')).toBe(r.messages.indexOf('result.sleep.insomnia') + 1);
  }
  expect(results.filter((r) => r.shortSleepNote).length).toBeGreaterThan(0);
  // The engine is pure and stores or logs nothing (the answers are never stored, D3-30).
  expect(readFileSync(engine, 'utf8')).not.toMatch(/add_action|add_filter|\$wpdb|get_option|wp_remote|error_log|update_option|set_transient/);
});
