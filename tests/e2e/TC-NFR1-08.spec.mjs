// TC-NFR1-08 Backup and restore — the e2e half of AS-02, run by `node tools/release/drill.mjs restore staging` against the
// restored site (LLD_DRILL_URL). Fixtures: FX-PR-NRM-01 and FX-DM-QD-01 (both tools' happy path through the served pages);
// FX-MAIL-01 is posted by the drill itself into the mail sink and recorded with the other ops results. Without
// LLD_DRILL_URL (CI) the test skips: the drill record is checked by tests/artefact/TC-NFR1-08.test.mjs.
import { test } from '@playwright/test';
import { toolsHappyPath } from '../lib/release-drill.mjs';

const base = process.env.LLD_DRILL_URL;

test('TC-NFR1-08-AS-02', async ({ page }) => {
  test.skip(!base, 'runs inside the restore drill (LLD_DRILL_URL); the recorded drill is asserted in tests/artefact/TC-NFR1-08.test.mjs');
  await toolsHappyPath(page, base);
});
