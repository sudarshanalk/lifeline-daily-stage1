// TC-FR1-09 e2e: AS-02 clinical banner visible for LIV-01/02; AS-03 underweight banner for UWT-01/02 and no forbidden
// deficit language in the result; AS-05 pregnancy banner text hash equals approvals/protein.json.copy_hashes.pregnancy.
import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const load = (f) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'protein', f), 'utf8')).fixtures;
const PRG = load('prg.json'); const LIV = load('liv.json'); const UWT = load('uwt.json');
const forbidden = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'forbidden-terms.json'), 'utf8'));
const approval = JSON.parse(readFileSync(resolve(ROOT, 'approvals', 'protein.json'), 'utf8'));
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

async function run(page, fx) {
  await page.goto(`${srv.url}/tools/protein-requirement-tool.html`);
  await fillProteinForm(page, fx.inputs); await submitProtein(page);
  await expect(page.locator('[data-test=result]')).toBeVisible();
}

test('TC-FR1-09-AS-02', async ({ page }) => {
  for (const fx of LIV) {
    await run(page, fx);
    const banner = page.locator('[data-test=banner-clinical]');
    await expect(banner, fx.id).toBeVisible();
    await expect(banner).toHaveAttribute('role', 'alert');
    expect(await banner.innerText()).toContain(await page.evaluate(() => MESSAGES.clinical.title));
    expect(await page.locator('#methodology').evaluate((el) => el.textContent), `${fx.id}: ESPEN source listed`).toMatch(/ESPEN/); // inside a collapsed <details>
  }
});

test('TC-FR1-09-AS-03', async ({ page }) => {
  for (const fx of UWT) {
    await run(page, fx);
    const banner = page.locator('[data-test=banner-underweight]');
    await expect(banner, fx.id).toBeVisible();
    expect(await banner.innerText()).toContain(await page.evaluate(() => MESSAGES.underweight.title));
    const text = (await page.locator('[data-test=result]').innerText()).toLowerCase();
    for (const term of [...forbidden.underweight, ...forbidden.global]) expect(text, `${fx.id}: forbidden term "${term}"`).not.toContain(term.toLowerCase());
    expect(text, `${fx.id}: eatingConcern wording present`).toContain((await page.evaluate(() => MESSAGES.eatingConcern)).toLowerCase().slice(0, 40));
  }
});

test('TC-FR1-09-AS-05', async ({ page }) => {
  // SHA-256 of the extracted pregnancy banner text (title + body as authored in MESSAGES) equals the approved hash
  expect(approval.copy_hashes.pregnancy, 'approvals/protein.json.copy_hashes.pregnancy present').toMatch(/^[0-9a-f]{64}$/);
  for (const fx of PRG) {
    await run(page, fx);
    const banner = page.locator('[data-test=banner-pregnancy]');
    await expect(banner, fx.id).toBeVisible();
    const authored = await page.evaluate(() => MESSAGES.pregnancy.title + '\n' + MESSAGES.pregnancy.body);
    expect(createHash('sha256').update(authored).digest('hex'), `${fx.id}: pregnancy copy hash`).toBe(approval.copy_hashes.pregnancy);
    expect(await banner.innerText(), `${fx.id}: banner shows the approved title`).toContain(await page.evaluate(() => MESSAGES.pregnancy.title));
    expect(await page.locator('[data-test=result]').innerText(), `${fx.id}: RDA intensity shown`).toMatch(fx.inputs.preg === 'pregnant' ? /1\.1\s?g\/kg/ : /1\.3\s?g\/kg/);
  }
});
