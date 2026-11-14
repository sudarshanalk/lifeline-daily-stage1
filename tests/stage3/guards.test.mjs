// Stage 3 guards (P3-10): NFR3-02, NFR3-04, NFR3-05 on the scope module itself, with injected diffs so no git is needed.
import { expect, test } from 'vitest';
import { stage3Scope, stage3Guards, classify, unknownTestIds, DECLARED_STAGE2, PACKAGE_FILES } from '../ci/stage3-scope.mjs';
import { oracleGuard } from '../ci/oracle-guard.mjs';
import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PLUGIN = 'site/public_html/wp-content/plugins/lld-members';
const none = () => ({ removed: [], added: [] });
const text = () => 'test("TC-FR2-01-AS-01", () => {});';

test('TC-NFR3-02-AS-01', () => {
  // A Stage 2 test edited without a recorded decision is failed by the guard.
  const r = stage3Guards({ files: ['tests/stage2/accounts.test.mjs'], diffs: () => ({ removed: ['expect(a).toBe(1);'], added: ['expect(a).toBe(2);'] }), texts: text, decisions: [] });
  expect(r.ok).toBe(false);
  expect(r.problems[0]).toMatch(/changed without a decision/);
  // The scope check refuses the file too.
  const s = stage3Scope({ title: 'S3-P40: food search', files: ['tests/stage2/accounts.test.mjs'], decisions: [] });
  expect(s.ok).toBe(false);
  // With a recorded decision the same change passes, and is marked for the lead's review.
  const decided = [{ file: 'tests/stage2/accounts.test.mjs', decision: 'I-19', change: 'new prices' }];
  expect(stage3Guards({ files: ['tests/stage2/accounts.test.mjs'], diffs: () => ({ removed: ['a'], added: ['b'] }), texts: text, decisions: decided }).ok).toBe(true);
  const ok = stage3Scope({ title: 'S3-P40: food search', files: ['tests/stage2/accounts.test.mjs'], decisions: decided });
  expect(ok.ok).toBe(true);
  expect(ok.review).toEqual(['tests/stage2/accounts.test.mjs']);
  // A deleted Stage 2 test file is caught.
  expect(stage3Guards({ files: ['tests/stage2/accounts.test.mjs'], diffs: none, texts: () => '', decisions: [] }).problems[0]).toMatch(/deleted/);
});

test('TC-NFR3-02-AS-02', () => {
  // A Stage 2 test marked as skipped (or .only, .todo, xit) is failed by the guard even with a decision.
  const decided = [{ file: 'tests/stage2/accounts.test.mjs', decision: 'I-19', change: 'prices' }];
  for (const line of ["test.skip('TC-FR2-01-AS-01', () => {});", "it.only('x', () => {});", "xit('x', () => {});", "describe.skip('x', () => {});", "test.fixme('x', async () => {});"]) {
    const r = stage3Guards({ files: ['tests/stage2/accounts.test.mjs'], diffs: () => ({ removed: [], added: [line] }), texts: text, decisions: decided });
    expect(r.ok, line).toBe(false);
    expect(r.problems.join('\n')).toMatch(/skipped, limited or marked to-do/);
  }
});

test('TC-NFR3-04-AS-01', () => {
  // A database change that drops or renames a Stage 2 column is failed by the check.
  const schema = `${PLUGIN}/includes/class-schema.php`;
  const stage3 = `${PLUGIN}/includes/stage3/class-schema-stage3.php`;
  for (const line of ['ALTER TABLE x DROP COLUMN age', "$wpdb->query( \"ALTER TABLE {$table} MODIFY COLUMN age INT\" );", 'ALTER TABLE x CHANGE age years INT', 'RENAME TABLE a TO b', 'DROP TABLE IF EXISTS x', 'ALTER TABLE {$table} RENAME COLUMN age TO years']) {
    const r = stage3Guards({ files: [stage3], diffs: () => ({ removed: [], added: [line] }), texts: text, decisions: [] });
    expect(r.ok, line).toBe(false);
    expect(r.problems[0]).toMatch(/NFR3-04/);
  }
  // Removing a line of the Stage 2 schema (a column, a key) fails; adding an ensure_column passes.
  expect(stage3Guards({ files: [schema], diffs: () => ({ removed: ['\t\t\tage SMALLINT UNSIGNED NULL,'], added: [] }), texts: text }).ok).toBe(false);
  expect(stage3Guards({ files: [schema], diffs: () => ({ removed: [], added: ["\t\tself::ensure_column( 'calculator_result', 'engine_version', 'VARCHAR(32) NULL' );"] }), texts: text }).ok).toBe(true);
  expect(stage3Guards({ files: [stage3], diffs: () => ({ removed: [], added: ['\t\t\tcreated_at DATETIME NOT NULL,'] }), texts: text }).ok).toBe(true);
});

test('TC-NFR3-05-AS-01', () => {
  // A pull request that changes an undeclared Stage 2 file is failed by the scope guard.
  const r = stage3Scope({ title: 'S3-P40: food search', files: [`${PLUGIN}/includes/class-billing.php`], decisions: [] });
  expect(r.ok).toBe(false);
  expect(r.problems[0]).toMatch(/Stage 2 plugin file that Stage 3 does not declare/);
  // Cursor's package may not touch the lead's deploy files or the calculators either.
  expect(stage3Scope({ title: 'S3-P40: food search', files: ['deploy/deploy.sh'] }).ok).toBe(false);
  expect(stage3Scope({ title: 'S3-P40: food search', files: ['site/public_html/tools/protein-requirement-tool.html'] }).ok).toBe(false);
  // Its own files pass.
  expect(stage3Scope({ title: 'S3-P40: food search', files: [`${PLUGIN}/includes/stage3/class-food.php`, 'tests/stage3/food.test.mjs'] }).ok).toBe(true);
  // A bad title is refused; a joined title is accepted.
  expect(classify('S2-C1: x')).toBeNull();
  expect(classify('S3-P40-P43: food tracker').id).toBe('S3-P40-P43');
  expect(classify('S3-P5B: saved results').ids).toEqual(['5B']);
});

test('TC-NFR3-05-AS-02', () => {
  // A pull request that changes a declared Stage 2 file passes and is listed for the lead-review label.
  const file = `${PLUGIN}/includes/class-plugin.php`;
  expect(DECLARED_STAGE2).toContain(file);
  const r = stage3Scope({ title: 'S3-P30: account hub', files: [file, `${PLUGIN}/includes/stage3/screens/hub.php`] });
  expect(r.ok).toBe(true);
  expect(r.review).toEqual([file]);
  // The Stage 2 payment files are open to the lead's payment packages only, and still labelled for review.
  const billing = `${PLUGIN}/includes/class-billing.php`;
  const lead = stage3Scope({ title: 'S3-P60: real Stripe checkout', files: [billing] });
  expect(lead.ok).toBe(true);
  expect(lead.review).toEqual([billing]);
  expect(stage3Scope({ title: 'S3-P40: food search', files: [billing] }).ok).toBe(false);
  // An owner decision opens one named Stage 2 file to one lead package only (Owner_Questions 93 = B: the Sleep and
  // Stress screening wording in class-personal.php, S3-P03), still labelled for review; every other package is refused.
  const personal = `${PLUGIN}/includes/class-personal.php`;
  const decided = stage3Scope({ title: 'S3-P03: Sleep and Stress screening wording', files: [personal] });
  expect(decided.ok).toBe(true);
  expect(decided.review).toEqual([personal]);
  for (const title of ['S3-P40: food search', 'S3-P30: account hub', 'S3-P60: real Stripe checkout', 'S3-P03-P40: joined']) {
    expect(stage3Scope({ title, files: [personal] }).ok, title).toBe(false);
  }
});

test('TC-NFR3-05-AS-02 (P3-35): both Stage 2 files the P3-35 decision opens are listed for lead review, even when a merged branch brought the change', () => {
  // Owner Question 160: additions to class-content.php and class-programme.php. changedFiles() leaves out what a merged
  // branch brought in; the full diff (`all`) still names the decision's files for the lead-review label.
  const content = `${PLUGIN}/includes/class-content.php`;
  const programme = `${PLUGIN}/includes/class-programme.php`;
  const r = stage3Scope({ title: 'S3-P35: Healthy Weight programme', files: [content], all: [content, programme] });
  expect(r.ok).toBe(true);
  expect(r.review).toEqual([content, programme]);
  // Another package gets no review line for them (and is refused if it changes them itself).
  expect(stage3Scope({ title: 'S3-P40: food search', files: [], all: [content, programme] }).review).toEqual([]);
  expect(stage3Scope({ title: 'S3-P40: food search', files: [programme] }).ok).toBe(false);
});

test('P3-36 opens the build list Stage 2 files and new assets to package 36 only', () => {
  // Owner, 14 Nov 2026: the member area build list (P3-36_Combined_Plan.md). Package 36 is the lead's, so each named
  // Stage 2 file passes and is listed for the lead's review; other packages are still refused.
  const named = ['class-account.php', 'class-content.php', 'class-programme.php', 'class-premium.php', 'class-personal.php', 'class-auth.php', 'class-growth.php'].map((f) => `${PLUGIN}/includes/${f}`);
  const r = stage3Scope({ title: 'S3-P36: C2 one settings area', files: [...named, `${PLUGIN}/includes/stage3/screens/class-account-hub.php`], decisions: [] });
  expect(r.ok, r.problems.join(' | ')).toBe(true);
  expect(r.review).toEqual(named);
  for (const f of named) expect(stage3Scope({ title: 'S3-P40: food search', files: [f], decisions: [] }).ok, f).toBe(false);
  // Each tool's own stylesheet or script may be added by package 36, and by no other package.
  for (const f of PACKAGE_FILES[36]) {
    expect(stage3Scope({ title: 'S3-P36: B1 food page', files: [f], decisions: [] }).ok, f).toBe(true);
    expect(stage3Scope({ title: 'S3-P40: food search', files: [f], decisions: [] }).ok, f).toBe(false);
  }
  // An undeclared Stage 2 file is still refused, and the frozen calculators stay closed.
  expect(stage3Scope({ title: 'S3-P36: A8 library', files: [`${PLUGIN}/includes/class-photos.php`], decisions: [] }).ok).toBe(false);
  expect(stage3Scope({ title: 'S3-P36: A8 library', files: ['site/public_html/tools/protein-requirement-tool.html'], decisions: [] }).ok).toBe(false);
  expect(stage3Scope({ title: 'S3-P36: A8 library', files: [`${PLUGIN}/assets/stage3-other.js`], decisions: [] }).ok).toBe(false);
  // A title joined with a builder's package keeps that builder's limits: no Stage 2 files and no new assets.
  expect(stage3Scope({ title: 'S3-P36-P40: joined', files: [named[0]], decisions: [] }).ok).toBe(false);
  expect(stage3Scope({ title: 'S3-P36-P40: joined', files: [PACKAGE_FILES[36][0]], decisions: [] }).ok).toBe(false);
});

test('every Stage 3 test id in tests/stage3 is a check in docs/stage3/03', () => {
  const r = unknownTestIds();
  expect(r.problems).toEqual([]);
  expect(r.known).toBeGreaterThan(200);
});

test('the test id check reads three-digit requirement numbers (FR3-100 and above)', () => {
  // The unknown id is built from parts so the --names scan of tests/stage3 does not see it in this file.
  const unknown = ['TC', 'FR3', '111', 'AS', '01'].join('-');
  const unknownNfr = ['TC', 'NFR3', '100', 'AS', '01'].join('-');
  const root = mkdtempSync(join(tmpdir(), 'lld-s3-ids-'));
  try {
    mkdirSync(join(root, 'docs', 'stage3'), { recursive: true });
    mkdirSync(join(root, 'tests', 'stage3'), { recursive: true });
    writeFileSync(join(root, 'docs', 'stage3', '03_Test_Criteria_Stage3.md'), '| TC-FR3-100-AS-01 | known |\n| TC-FR3-09-AS-01 | known |\n');
    writeFileSync(join(root, 'tests', 'stage3', 'known.test.mjs'), "test('TC-FR3-100-AS-01', () => {}); test('TC-FR3-09-AS-01', () => {});\n");
    const ok = unknownTestIds({ root });
    expect(ok.problems).toEqual([]);
    expect(ok.known).toBe(2);
    writeFileSync(join(root, 'tests', 'stage3', 'unknown.test.mjs'), `test('${unknown}', () => {}); test('${unknownNfr}', () => {});\n`);
    const bad = unknownTestIds({ root });
    expect(bad.ok).toBe(false);
    expect(bad.problems).toEqual([
      `tests/stage3/unknown.test.mjs: ${unknown} is not a check in docs/stage3/03`,
      `tests/stage3/unknown.test.mjs: ${unknownNfr} is not a check in docs/stage3/03`,
    ]);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("NFR3-14 (guard): a Stage 3 calculator's first fixtures come from its signed sheet; later changes and the Stage 1 folders stay guarded", () => {
  // The lead may add approvals/stage3/<tool>.json and fixtures/<tool>/ in an S3-P pull request; the Stage 1 approvals stay protected.
  const lead = stage3Scope({ title: 'S3-P02-P03: sign-offs', files: ['approvals/stage3/heart-risk.json', 'fixtures/heart-risk/lab.json', 'schemas/fixture.schema.json'], decisions: [] });
  expect(lead.ok).toBe(true);
  expect(stage3Scope({ title: 'S3-P02-P03: sign-offs', files: ['approvals/healthy-weight.json'], decisions: [] }).ok).toBe(false);
  expect(stage3Scope({ title: 'S3-P40: food search', files: ['approvals/stage3/heart-risk.json'], decisions: [] }).ok).toBe(false);
  // oracle-guard: a new fixture file under a Stage 3 tool folder passes in an S3-P commit; editing its expected afterwards fails;
  // a new file under a Stage 1 folder fails even in an S3-P commit.
  const repo = mkdtempSync(join(tmpdir(), 'lld-s3-oracle-'));
  try {
    const git = (c) => execSync(`git ${c}`, { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    git('init -q -b staging'); git('config user.email dev@example.test'); git('config user.name "Dev Person"'); git('config commit.gpgsign false');
    writeFileSync(join(repo, 'README'), 'x'); git('add -A'); git('commit -q -m "start"');
    const fx = (v) => JSON.stringify({ group: 'LAB', tool: 'heart-risk', fixtures: [{ id: 'FX-HR-LAB-01', inputs: { a: 1 }, expected: { risk: v }, oracle: 'clinical', status: 'recorded' }] });
    mkdirSync(join(repo, 'schemas'), { recursive: true });
    writeFileSync(join(repo, 'schemas', 'fixture.schema.json'), JSON.stringify({ properties: { tool: { enum: ['protein', 'heart-risk'] } } }));
    git('add -A'); git('commit -q -m "schema"');
    git('checkout -q -b s3'); mkdirSync(join(repo, 'fixtures', 'heart-risk'), { recursive: true });
    writeFileSync(join(repo, 'fixtures', 'heart-risk', 'lab.json'), fx(7)); git('add -A'); git('commit -q -m "S3-P02-P03: sign-offs"');
    // ... refused without the tool's approval record, accepted with it
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(false);
    mkdirSync(join(repo, 'approvals', 'stage3'), { recursive: true });
    writeFileSync(join(repo, 'approvals', 'stage3', 'heart-risk.json'), '{}'); git('add -A'); git('commit -q -m "S3-P02-P03: approval"');
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(true);
    // ... and never for a folder the fixture schema does not name
    git('checkout -q -b unknown'); mkdirSync(join(repo, 'fixtures', 'foo'), { recursive: true });
    writeFileSync(join(repo, 'fixtures', 'foo', 'x.json'), fx(1).replace('heart-risk', 'foo')); git('add -A'); git('commit -q -m "S3-P12: unknown tool"');
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(false);
    git('checkout -q s3');
    git('checkout -q staging'); git('merge -q s3'); git('checkout -q -b later');
    writeFileSync(join(repo, 'fixtures', 'heart-risk', 'lab.json'), fx(8)); git('commit -q -am "S3-P57: engine"');
    const r = oracleGuard({ root: repo, base: 'staging', head: 'HEAD' });
    expect(r.ok).toBe(false);
    // ... unless the tool's oracle at head derived exactly that value for that fixture id (the oracle decides, not the author)
    mkdirSync(join(repo, 'oracles', 'orc-08-heart-risk'), { recursive: true });
    const script = 'print(8)\n';
    writeFileSync(join(repo, 'oracles', 'orc-08-heart-risk', 'hr_ref.py'), script);
    writeFileSync(join(repo, 'oracles', 'orc-08-heart-risk', 'results.json'), JSON.stringify({ source_hash: 'not-the-script', results: [{ fixture_id: 'FX-HR-LAB-01', expected: { risk: 8 } }] }));
    git('add -A'); git('commit -q -m "S3-P57: oracle"');
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(false); // results.json not from the committed script
    writeFileSync(join(repo, 'oracles', 'orc-08-heart-risk', 'results.json'), JSON.stringify({ source_hash: createHash('sha256').update(script, 'utf8').digest('hex'), results: [{ fixture_id: 'FX-HR-LAB-01', expected: { risk: 8 } }] }));
    git('commit -q -am "S3-P57: oracle hash"');
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(true);
    writeFileSync(join(repo, 'fixtures', 'heart-risk', 'lab.json'), fx(9)); git('commit -q -am "S3-P57: not the oracle value"');
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(false);
    git('checkout -q -- .');
    expect(r.problems.join('\n')).toMatch(/lab\.json: expected changed for FX-HR-LAB-01/);
    git('checkout -q staging'); git('checkout -q -b s1'); mkdirSync(join(repo, 'fixtures', 'protein'), { recursive: true });
    writeFileSync(join(repo, 'fixtures', 'protein', 'new.json'), fx(1).replace('heart-risk', 'protein')); git('add -A'); git('commit -q -m "S3-P02: not allowed"');
    expect(oracleGuard({ root: repo, base: 'staging', head: 'HEAD' }).ok).toBe(false);
  } finally { rmSync(repo, { recursive: true, force: true }); }
});
