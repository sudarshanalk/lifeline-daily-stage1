// TC-FR1-07-AS-03 — invalid fixtures FX-PR-INV-01…08: `#err` visible with a specific message, `#results` stays hidden.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const inv = JSON.parse(readFileSync(resolve(here, '..', '..', 'fixtures', 'protein', 'inv.json'), 'utf8')).fixtures;
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

test('TC-FR1-07-AS-03', async ({ page }) => {
  expect(inv.map((f) => f.id)).toEqual(['FX-PR-INV-01', 'FX-PR-INV-02', 'FX-PR-INV-03', 'FX-PR-INV-04', 'FX-PR-INV-05', 'FX-PR-INV-06', 'FX-PR-INV-07', 'FX-PR-INV-08']);
  for (const f of inv) {
    await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
    await fillProteinForm(page, f.inputs);
    await submitProtein(page);
    const err = page.locator('#err');
    await expect(err, `${f.id} (${f.purpose}): error shown`).toBeVisible();
    const text = (await err.innerText()).trim();
    expect(text.length, `${f.id}: specific message`).toBeGreaterThan(10);
    expect(text, `${f.id}: names the offending field or range`).toMatch(/age|height|weight|sex|ethnicity|activity|resistance|aiming|eat|kidney|liver|between|Select|Enter|Answer/i);
    await expect(page.locator('#results'), `${f.id}: no result`).toBeHidden();
    expect(await page.locator('#results').innerText()).not.toMatch(/\d+\s?g\b/);
  }
});
