// TC-FR1-06 Clinical method provenance — families artefact + ci. Fixtures: approvals/*.json, manifests. Mutation: MUT-10.
// AS-03 (landing-page methodology deployed_version) is an e2e assertion: tests/e2e/TC-FR1-06.spec.mjs.
import { describe, expect, test } from 'vitest';
import { resolve } from 'node:path';
import approvalProtein from '../../approvals/protein.json' with { type: 'json' };
import approvalDiabetes from '../../approvals/diabetes.json' with { type: 'json' };
import manifestProtein from '../../manifests/protein.json' with { type: 'json' };
import manifestDiabetes from '../../manifests/diabetes.json' with { type: 'json' };
import { ROOT } from '../lib/paths.mjs';
import { validateFile } from '../lib/validate.mjs';
import { withMutation } from '../lib/mutate.mjs';
import { provenanceCheck } from './provenance-check.mjs';
import { engineFreeze } from './engine-freeze.mjs';

// the guards cover every guarded tool (FR2-39 adds the Healthy Weight Check), so the mutation sandboxes carry all of them
const TOOL_PATHS = ['site/public_html/tools/protein-requirement-tool.html', 'site/public_html/tools/diabetes-risk-tool.html', 'site/public_html/tools/healthy-weight-tool.html'];
const PROVENANCE_INPUTS = ['approvals/protein.json', 'approvals/diabetes.json', 'approvals/healthy-weight.json', 'manifests/protein.json', 'manifests/diabetes.json', 'manifests/healthy-weight.json', ...TOOL_PATHS];
const FREEZE_INPUTS = ['manifests/protein.json', 'manifests/diabetes.json', 'manifests/healthy-weight.json', 'registry/Stage1_Requirements_Registry_v1_2.json', ...TOOL_PATHS];

describe('TC-FR1-06 clinical method provenance', () => {
  test('TC-FR1-06-AS-01', () => {
    // approvals/protein.json and approvals/diabetes.json validate against SCH-03 (and copy.json against SCH-16)
    for (const slug of ['protein', 'diabetes']) {
      const r = validateFile('methodology-approval.schema.json', resolve(ROOT, 'approvals', `${slug}.json`));
      expect(r.errors, `approvals/${slug}.json`).toEqual([]);
      expect(r.ok).toBe(true);
    }
    expect(validateFile('copy-approval.schema.json', resolve(ROOT, 'approvals', 'copy.json')).errors).toEqual([]);
    // records exist from wave 1 with decision pending and point at the live manifest version
    for (const [approval, manifest] of [[approvalProtein, manifestProtein], [approvalDiabetes, manifestDiabetes]]) {
      expect(['pending', 'approved']).toContain(approval.clinical_signoff.decision); // compared regardless of sign-off state (SRS FR1-06)
      expect(approval.version_hash).toBe(manifest.versions.find((v) => v.is_live).version_hash);
      // the two per-source aggregates are mandatory; per-banner hashes (e.g. pregnancy, TC-FR1-09-AS-05) are added by later requirements
      expect(Object.keys(approval.copy_hashes)).toEqual(expect.arrayContaining([`messages.${approval.tool_slug}`, `templates.${approval.tool_slug}`]));
      for (const v of Object.values(approval.copy_hashes)) expect(v).toMatch(/^[0-9a-f]{64}$/);
    }
  });

  test('TC-FR1-06-AS-02', async () => {
    // provenance-check passes on the current tree (pending accepted on staging) …
    const clean = provenanceCheck({ root: ROOT, env: 'staging' });
    expect(clean.problems).toEqual([]);
    expect(clean.ok).toBe(true);
    // … and fails when the staged file hash ≠ approved hash (MUT-10: one hex digit of protein.json version_hash altered)
    await withMutation('MUT-10', (root) => {
      const r = provenanceCheck({ root, env: 'staging' });
      expect(r.ok).toBe(false);
      expect(r.problems.some((p) => /^protein: staged hash .* != approved version_hash/.test(p))).toBe(true);
    }, PROVENANCE_INPUTS);
    // production requires an approved sign-off; pending is rejected there
    const prod = provenanceCheck({ root: ROOT, env: 'production' });
    expect(prod.ok).toBe(false);
    expect(prod.problems.filter((p) => /^(protein|diabetes): .*not accepted for production/.test(p))).toHaveLength(2);
  });

  test('TC-FR1-06-AS-04', async () => {
    // engine-freeze passes on the current tree …
    const clean = engineFreeze({ root: ROOT });
    expect(clean.problems).toEqual([]);
    expect(clean.ok).toBe(true);
    expect(Object.keys(clean.compared.protein)).toEqual(expect.arrayContaining(['js:BANDS', 'js:DIET_MULT', 'js:PREG_GKG', 'js:computeProtein', 'js:clampMeal', 'js:cutoffs']));
    expect(Object.keys(clean.compared.diabetes)).toEqual(expect.arrayContaining(['js:ENGINE', 'js:bandFor', 'js:idrs', 'js:whtr', 'js:render.priority']));
    // … and fails on MUT-04 (Female s0), MUT-06 (bandFor 5.6→6.0) and MUT-07 (cap 2.0→2.5)
    const expected = { 'MUT-04': 'diabetes: js:ENGINE changed', 'MUT-06': 'diabetes: js:bandFor changed', 'MUT-07': 'protein: js:CEILING changed' };
    for (const [mut, msg] of Object.entries(expected)) {
      await withMutation(mut, (root) => {
        const r = engineFreeze({ root });
        expect(r.ok, mut).toBe(false);
        expect(r.problems.join('\n'), mut).toContain(msg);
        // a DEC row that is not ratified at HG-02 does not waive the freeze
        expect(engineFreeze({ root, dec: 'DEC-28' }).ok, `${mut} with unratified DEC`).toBe(false);
      }, FREEZE_INPUTS);
    }
  });
});
