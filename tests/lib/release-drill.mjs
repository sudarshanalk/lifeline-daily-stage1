// Shared by the release drill specs (tests/e2e/TC-NFR1-08.spec.mjs, TC-FR1-19.spec.mjs) and the artefact tests
// (tests/artefact/TC-NFR1-08.test.mjs, TC-FR1-19.test.mjs): the drill records written by tools/release/drill.mjs and the
// happy path of both tools against a deployed site (LLD_DRILL_URL), with the fixtures the TC pages name.
import { expect } from '@playwright/test';
import { fillProteinForm, submitProtein } from '../e2e/helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from '../e2e/helpers/diabetes-form.mjs';

import { fx } from './drill-records.mjs';

/** FX-PR-NRM-01 and FX-DM-QD-01 through the served tool pages in a browser; asserts the fixture's expected headline values */
export async function toolsHappyPath(page, base) {
  const NRM01 = fx('protein', 'nrm.json', 'FX-PR-NRM-01');
  const QD01 = fx('diabetes', 'qd.json', 'FX-DM-QD-01');
  await page.goto(`${base}/tools/protein-requirement-tool.html`);
  await expect(page.locator('#form')).toBeVisible();
  await fillProteinForm(page, NRM01.inputs); await submitProtein(page);
  await expect(page.locator('#results')).toBeVisible();
  expect(await page.locator('[data-test=headline]').innerText(), 'FX-PR-NRM-01 target').toMatch(new RegExp(`\\b${NRM01.expected.targetG}\\s?g\\b`, 'i'));
  expect(await page.locator('#results').innerText(), 'FX-PR-NRM-01 per meal').toMatch(new RegExp(`\\b${NRM01.expected.perMeal}\\s?g\\b`, 'i'));

  await page.goto(`${base}/tools/diabetes-risk-tool.html`);
  await expect(page.locator('#tool')).toBeVisible();
  await fillDiabetesForm(page, QD01.inputs); await submitDiabetes(page);
  await expect(page.locator('#results')).toBeVisible();
  const label = await page.locator('#headlineZone svg[aria-label]').first().getAttribute('aria-label');
  const shown = Number((label || '').match(/(\d+(?:\.\d+)?)/)?.[1]);
  expect(shown, `FX-DM-QD-01 risk shown (${label})`).toBe(QD01.expected.pctDisplay);
  return { protein: NRM01.expected.targetG, diabetes: shown };
}
