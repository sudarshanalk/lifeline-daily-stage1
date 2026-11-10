// TC-FR1-19 Staging and production release — the browser half of the smoke suite (AS-03: both tools' happy path) and of
// the rollback drill (AS-04: smoke green on the rolled-back release). Run by tools/release/drill.mjs smoke|rollback with
// LLD_DRILL_URL; skipped in CI, where tests/artefact/TC-FR1-19.test.mjs checks the recorded runs.
// Fixtures: FX-PR-NRM-01, FX-DM-QD-01.
import { test } from '@playwright/test';
import { toolsHappyPath } from '../lib/release-drill.mjs';

const base = process.env.LLD_DRILL_URL;

test('TC-FR1-19-AS-03', async ({ page }) => {
  test.skip(!base, 'runs inside the release smoke (LLD_DRILL_URL); recorded runs are asserted in tests/artefact/TC-FR1-19.test.mjs');
  await toolsHappyPath(page, base);
});
