// Rule catalogue version 1.3 (NFR3-17; P3-02 section 9, D3-58 and D3-59): the 36 Healthy Weight programme rules, their
// T-HW templates word for word against the approval record, and every signed fixture of
// fixtures/healthy-weight-programme/ replayed through the engine.
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';
import { hwDir, json, php, replay, root } from './hw-harness.mjs';

const approval = json('approvals/stage3/decision-rules.json');
const hwApproval = json('approvals/stage3/healthy-weight-programme.json');
const HW = JSON.parse(php('echo json_encode(LLD_Members_Rule_Catalogue::HW_TEMPLATES);'));
const RULES = JSON.parse(php('echo json_encode(LLD_Members_Rule_Catalogue::programme_rules());'));
const fixtureFiles = readdirSync(resolve(root, 'fixtures/healthy-weight-programme')).filter((n) => n.endsWith('.json'));
const allFixtures = fixtureFiles.flatMap((n) => json(`fixtures/healthy-weight-programme/${n}`).fixtures || []);

test('TC-NFR3-17-AS-01 (Healthy Weight programme fixtures)', () => {
  // Each R-HW rule is tested with the made-up test data of the signed documents: every fixture of
  // fixtures/healthy-weight-programme/ gives exactly its recorded result.
  expect(allFixtures.length).toBeGreaterThanOrEqual(120);
  const rows = replay(allFixtures);
  expect(rows.flatMap((r) => r.problems)).toEqual([]);
  // The fixture tables of the signed documents are all there: rule draft sections 2 to 12, HWB-03a to f, DRK-01 to 10.
  const ids = new Set(allFixtures.map((f) => f.inputs.source_id));
  const signed = ['HWS-01a', 'HWS-01b', 'HWS-02a', 'HWS-02b', 'HWS-03a', 'HWS-03b', 'HWS-03c', 'HWS-05a', 'HWS-05b', 'HWS-05c', 'HWS-05d', 'HWS-05e',
    'HWN-01a', 'HWN-01b', 'HWN-01c-1', 'HWN-01c-2', 'HWN-02a', 'HWN-02b', 'HWN-02c-1', 'HWN-03a', 'HWN-04a', 'HWN-05a', 'HWN-05b', 'HWN-05c', 'HWN-05d',
    'HWP-01a', 'HWP-01b', 'HWP-01c', 'HWP-01d', 'HWP-01e', 'HWP-01f', 'HWP-01g',
    'HWST-01a', 'HWST-01b', 'HWST-02a', 'HWST-02b', 'HWST-03a', 'HWST-03b', 'HWST-03c', 'HWST-04a', 'HWST-04b', 'HWST-05a', 'HWST-05b', 'HWST-06a', 'HWST-06b',
    'HWA-01a', 'HWA-01b', 'HWA-01c', 'HWA-02a', 'HWA-02b', 'HWA-02c', 'HWF-01a', 'HWF-01b', 'HWF-02a', 'HWF-03a',
    'HWNO-01a', 'HWNO-02a', 'HWNO-03a', 'HWNO-03b', 'HWNO-04a', 'HWNO-04b', 'HWR-01a', 'HWR-01b', 'HWM-01a',
    'HWW-01a', 'HWW-01b', 'HWW-02a', 'HWW-02b', 'HWW-02c', 'HWB-01a', 'HWB-01b', 'HWB-03a', 'HWB-03b', 'HWB-03c', 'HWB-03d', 'HWB-03e', 'HWB-03f', 'HWQ-01a',
    'MEMBER-1', 'MEMBER-2', ...Array.from({ length: 10 }, (_, i) => `DRK-${String(i + 1).padStart(2, '0')}`)];
  expect(signed.filter((s) => !ids.has(s))).toEqual([]);
  for (const f of allFixtures) {
    expect(f.oracle, f.id).toBe('clinical');
    expect(f.source_version, f.id).toMatch(/^[0-9a-f]{64}$/);
  }
});

test('TC-NFR3-17-AS-02 (Healthy Weight templates)', () => {
  // Every T-HW template is the signed wording, hashed in the version 1.3 approval record; every rule names templates that
  // exist; fill() changes nothing but the braces.
  const problems = [];
  for (const [key, text] of Object.entries(HW)) {
    const got = createHash('sha256').update(text, 'utf8').digest('hex');
    if (approval.copy_hashes[key] !== got) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(approval.copy_hashes).filter((k) => k.startsWith('hw.')).sort()).toEqual(Object.keys(HW).sort());
  for (const [name, r] of Object.entries(RULES)) for (const t of r.templates) expect(HW[t], `${name} ${t}`).toBeDefined();
  // The templates pack wins over the rule draft where they differ (for example "a day" in T-HW-NUM-05, the Sleep and
  // stress check button on T-HW-SAFE-03, "Suggested for you, because" in T-HW-BLOCK-01).
  expect(HW['hw.T-HW-NUM-05']).toBe('Your {target} kcal a day includes drinks. Your usual week of drinks is about {weekly} kcal, roughly {daily} a day.');
  expect(HW['hw.T-HW-SAFE-03']).toContain('[Sleep and stress check]');
  expect(HW['hw.T-HW-BLOCK-01']).toBe('Suggested for you, because {reason}.');
  const filled = php("echo LLD_Members_Rule_Catalogue::fill('hw.T-HW-NUM-04', ['kg' => '76.0', 'label' => '5 % of your starting weight', 'date' => '18 December 2026', 'evil' => 'x']);");
  expect(filled).toBe('**76.0 kg** · 5 % of your starting weight · around 18 December 2026');
  // No template names a diagnosis or a medicine and none praises kilograms lost. "you have less than 10 minutes" is the
  // signed pace reason phrase about time, not a diagnosis, so the "you have" check reads "you have" + a condition word.
  for (const [key, text] of Object.entries(HW)) {
    expect(text, key).not.toMatch(/\b(diagnos|hypertension|depression|beta-blocker|insulin|metformin|statin)\b/i);
    expect(text, key).not.toMatch(/\byou have (?!less than 10 minutes)/i);
    expect(text, key).not.toMatch(/\b(kg|kilograms?|pounds?|lb)\b[^.]*\blost\b|\blost\b[^.]*\b(kg|kilograms?|pounds?)\b/i);
    expect(text, key).not.toMatch(/\b(stop|quit|give up|cut out)\b(?! suddenly)/i); // alcohol wording (question 16)
  }
});

test('TC-NFR3-17-AS-03 (catalogue version 1.3.1)', () => {
  // The catalogue is version 1.3.1 (D3-60); the 36 R-HW rules are a programme class outside the insight quota; the 23 rules of
  // sections 1 to 8 are unchanged; the approval record is the signed v1.3 one.
  expect(php('echo LLD_Members_Rule_Catalogue::VERSION;')).toBe('1.3.1');
  expect(Object.keys(RULES)).toHaveLength(36);
  const groups = {};
  for (const r of Object.values(RULES)) { groups[r.group] = (groups[r.group] || 0) + 1; expect(r.class).toBe('programme'); expect(r.counted_in_quota).toBe(false); }
  expect(groups).toEqual({ safety: 5, numbers: 5, pace: 1, steps: 6, alcohol: 2, food: 3, notes: 5, reminders: 1, motivation: 2, weekly: 2, blocks: 4 });
  expect(RULES['R-HW-BLOCK-03'].version).toBe('1.3');
  expect(Object.values(RULES).filter((r) => r.version === '1.3')).toHaveLength(1);
  expect(RULES['R-HW-ALC-02'].version).toBe('1.3.1');
  expect(Object.values(RULES).filter((r) => r.version === '1.3.1')).toHaveLength(1);
  const ids = Object.values(RULES).map((r) => r.id);
  expect(new Set(ids).size).toBe(36);
  const core = JSON.parse(php('echo json_encode(LLD_Members_Rule_Catalogue::rules());'));
  expect(Object.keys(core)).toHaveLength(23);
  for (const id of ids) expect(Object.values(core).map((r) => r.id)).not.toContain(id);
  // Safety is free and runs during the low-mood days; question 12's Premium personalisation.
  for (const [n, r] of Object.entries(RULES)) if (n.startsWith('R-HW-SAFE-')) expect(r).toMatchObject({ tier: 'free', low_mood: true, order: 1 });
  expect(RULES['R-HW-NUM-03'].low_mood).toBe(true);
  for (const n of ['R-HW-STEP-01', 'R-HW-STEP-02', 'R-HW-STEP-03', 'R-HW-STEP-04', 'R-HW-STEP-05', 'R-HW-NOTE-02', 'R-HW-BLOCK-01']) expect(RULES[n].tier).toBe('premium');
  for (const n of ['R-HW-NUM-04', 'R-HW-PACE-01', 'R-HW-MOT-01', 'R-HW-FOOD-01']) expect(RULES[n].tier).toBe('free');
  // The approval record: v1.3, D3-58 and D3-59, the SHA-256 of the signed v1.3 catalogue file (outside the repository).
  expect(approval.approval_id).toBe('APR-DECISION-RULES-V1-3-1-2026-11-10');
  expect(approval.decisions_ratified.slice(-3)).toEqual(['D3-58', 'D3-59', 'D3-60']);
  expect(approval.clinical_signoff).toMatchObject({ decision: 'approved', date: '2026-11-10' });
  expect(approval.clinical_signoff.name).toMatch(/sudarshanalk/);
  expect(approval.version_hash).toMatch(/^[0-9a-f]{64}$/);
  expect(approval.regulatory_statement).toMatch(/Frozen: any change needs a new decision/);
  expect(hwApproval).toMatchObject({ tool_slug: 'healthy-weight-programme', decisions_ratified: ['D3-54', 'D3-55', 'D3-56', 'D3-57', 'D3-58', 'D3-59', 'D3-60'] });
  expect(hwApproval.clinical_signoff.decision).toBe('approved');
  // The fixture folder carries its own sign-off record, as oracle-guard requires of a new Stage 3 fixture folder.
  const schema = json('schemas/fixture.schema.json');
  expect(schema.properties.tool.enum).toContain('healthy-weight-programme');
  for (const n of fixtureFiles) if (json(`fixtures/healthy-weight-programme/${n}`).fixtures) expect(json(`fixtures/healthy-weight-programme/${n}`).tool).toBe('healthy-weight-programme');
});

test('TC-NFR3-17-AS-03 (every R-HW rule has a tier)', () => {
  // Every R-HW rule says whether it is Free or Premium: rule question 12, and Owner Question 163 (10 Nov 2026) for the six
  // it did not name. R-HW-WEEK-01 is split: the question free, the changes to next week Premium, the illness wording free.
  for (const [n, r] of Object.entries(RULES)) expect(['free', 'premium', 'split'], n).toContain(r.tier);
  expect(Object.entries(RULES).filter(([, r]) => r.tier === 'split').map(([n]) => n)).toEqual(['R-HW-WEEK-01']);
  expect(RULES['R-HW-WEEK-01'].tiers).toEqual({ question: 'free', next_week: 'premium', illness_wording: 'free' });
  const q163 = { 'R-HW-STEP-06': 'premium', 'R-HW-ALC-02': 'free', 'R-HW-FOOD-03': 'premium', 'R-HW-REM-01': 'free', 'R-HW-QOD-01': 'free' };
  for (const [n, t] of Object.entries(q163)) expect(RULES[n].tier, n).toBe(t);
  // The safety limits inside the Premium rules stay free: the exercise limits are R-HW-SAFE-04, the fasting lines safety.
  expect(RULES['R-HW-SAFE-04'].tier).toBe('free');
});

test('TC-NFR3-17-AS-03 (pure engine, no second copy of the wording)', () => {
  // The Healthy Weight classes make no WordPress, database, network or clock call, and hold no copy of a signed
  // template: they name templates by key and fill them through the catalogue.
  for (const f of ['class-hw-where.php', 'class-hw-rules.php', 'class-hw-festival.php']) {
    const src = readFileSync(resolve(hwDir, f), 'utf8');
    expect(src, f).not.toMatch(/add_action|add_filter|apply_filters|\$wpdb|get_option|get_user|wp_remote|wp_mail|error_log|\bdate\(|\btime\(\)|current_time|sanitize_/);
    for (const text of Object.values(HW)) if (text.length > 25) expect(src, f).not.toContain(text.slice(0, 40));
  }
  expect(readFileSync(resolve(hwDir, 'class-hw-rules.php'), 'utf8')).toMatch(/LLD_Members_Rule_Catalogue::fill\(/);
  // The Healthy Weight Check itself is untouched (engine-freeze guards it; this checks the port did not edit it).
  const page = readFileSync(resolve(root, 'site/public_html/tools/healthy-weight-tool.html'), 'utf8');
  expect(createHash('sha256').update(page, 'utf8').digest('hex')).toBe(json('approvals/healthy-weight.json').version_hash);
});
