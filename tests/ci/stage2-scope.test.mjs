// stage2-scope: Stage 2 pull-request classes and file ownership (Stage 2 Build Method §2, §4, §7).
import { describe, expect, test } from 'vitest';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, copyFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { classify, stage2Scope, unknownTestIds } from './stage2-scope.mjs';

const PLUGIN = 'site/public_html/wp-content/plugins/lld-members/lld-members.php';
const PAGE = 'site/public_html/tools/diabetes-risk-tool.html';

describe('stage2-scope', () => {
  test('titles: S2-C1..C10 and S2-L0..L8 only', () => {
    expect(classify('S2-C1: Foundations')).toMatchObject({ kind: 'C', n: 1 });
    expect(classify('S2-C10: Quality')).toMatchObject({ kind: 'C', n: 10 });
    expect(classify('S2-L0: Phase 0')).toMatchObject({ kind: 'L', n: 0 });
    expect(classify('S2-C2-C10: Packages')).toMatchObject({ kind: 'C', n: 2, to: 10 });
    for (const t of ['S2-C0: x', 'S2-C11: x', 'S2-L9: x', 'S2-C1 x', 'FR2-01: x', 'S2-C1: ', 'S2-C5-C3: x', 'S2-C2-C11: x']) expect(classify(t), t).toBeNull();
  });

  test('Cursor packages stay in the plugin and tests/stage2', () => {
    expect(stage2Scope({ title: 'S2-C1: Foundations', files: [PLUGIN, 'tests/stage2/TC-FR2-01.test.mjs'] }).ok).toBe(true);
    for (const f of [PAGE, 'deploy/deploy.sh', '.github/workflows/ci.yml', 'docs/stage2/02_Data_Model_Stage2.md', 'site/public_html/wp-content/mu-plugins/lld-tools/x.php', 'package.json']) {
      expect(stage2Scope({ title: 'S2-C2: Accounts', files: [f] }).ok, f).toBe(false);
    }
  });

  test('only C9 may add the calculator link script and touch the tool pages', () => {
    const files = [PAGE, 'site/public_html/tools/lld-members-link.js', 'manifests/diabetes.json'];
    expect(stage2Scope({ title: 'S2-C9: Calculator link', files }).ok).toBe(true);
    expect(stage2Scope({ title: 'S2-C5: Programme', files }).ok).toBe(false);
    expect(stage2Scope({ title: 'S2-C2-C10: Packages', files }).ok).toBe(true);    // a range that includes C9
    expect(stage2Scope({ title: 'S2-C2-C8: Packages', files }).ok).toBe(false);    // a range without C9
    expect(stage2Scope({ title: 'S2-C9: Calculator link', files: ['site/public_html/tools/api/submit.php'] }).ok).toBe(false);
  });

  test('lead packages never touch Cursor files or the Stage 1 calculators', () => {
    expect(stage2Scope({ title: 'S2-L0: Phase 0', files: ['deploy/remote.py', '.github/workflows/ci-guards.yml', 'tests/ci/x.mjs', '.cursor/rules/stage2.mdc', 'tools/local/stage2-local.ps1'] }).ok).toBe(true);
    for (const f of [PLUGIN, 'tests/stage2/tools/leak.mjs', PAGE, 'approvals/diabetes.json', 'copy/inventory.json', 'snapshot/x']) {
      expect(stage2Scope({ title: 'S2-L3: SEO', files: [f] }).ok, f).toBe(false);
    }
  });

  test('test ids under tests/stage2 must be checks in docs/stage2/03', () => {
    const root = mkdtempSync(join(tmpdir(), 'lld-s2-'));
    try {
      mkdirSync(join(root, 'docs', 'stage2'), { recursive: true });
      copyFileSync(resolve(ROOT, 'docs', 'stage2', '03_Test_Criteria_Stage2.md'), join(root, 'docs', 'stage2', '03_Test_Criteria_Stage2.md'));
      mkdirSync(join(root, 'tests', 'stage2'), { recursive: true });
      writeFileSync(join(root, 'tests', 'stage2', 'a.test.mjs'), "test('TC-FR2-01-AS-01', () => {});\n");
      const good = unknownTestIds({ root });
      expect(good.ok).toBe(true);
      expect(good.known).toBeGreaterThan(200);
      writeFileSync(join(root, 'tests', 'stage2', 'b.test.mjs'), "test('TC-FR2-01-AS-99', () => {});\n");
      const bad = unknownTestIds({ root });
      expect(bad.ok).toBe(false);
      expect(bad.problems.join('\n')).toMatch(/b\.test\.mjs: TC-FR2-01-AS-99/);
    } finally { rmSync(root, { recursive: true, force: true }); }
  });
});
