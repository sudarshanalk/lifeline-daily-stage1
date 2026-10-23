// TC-FR1-13 Guest access without account — e2e family. Fixtures: FX-PR-NRM-01, FX-DM-QD-01.
// AS-01 fresh incognito contexts complete both flows; AS-02 no cookie/storage key added and no stored value contains an
// input or result; AS-03 reload hides the result and empties every input; AS-04 location.search + hash carry no entered value.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const NRM01 = fx('protein', 'nrm.json', 'FX-PR-NRM-01');
const QD01 = fx('diabetes', 'qd.json', 'FX-DM-QD-01');
const FLOWS = [
  { slug: 'protein', file: 'protein-requirement-tool.html', fixture: NRM01, fill: fillProteinForm, submit: submitProtein, result: '#results', inputsSel: '#form input, #form select' },
  { slug: 'diabetes', file: 'diabetes-risk-tool.html', fixture: QD01, fill: fillDiabetesForm, submit: submitDiabetes, result: '#results', inputsSel: '#tool input, #tool select' },
];
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });

const snapshot = (page) => page.evaluate(() => ({ cookies: document.cookie, local: Object.fromEntries(Object.keys(localStorage).map((k) => [k, localStorage.getItem(k)])), session: Object.fromEntries(Object.keys(sessionStorage).map((k) => [k, sessionStorage.getItem(k)])) }));
const enteredValues = (inputs) => Object.values(inputs).flat().filter((v) => v !== null && v !== undefined && v !== '' && typeof v !== 'boolean').map(String).filter((v) => v.length > 1);

async function complete(browser, flow) {
  const context = await browser.newContext(); // fresh incognito-equivalent context: no cookies, no storage, no sign-in
  const page = await context.newPage();
  await page.goto(`${srv.url}/tools/${flow.file}`);
  const before = await snapshot(page);
  await flow.fill(page, flow.fixture.inputs); await flow.submit(page);
  await expect(page.locator(flow.result)).toBeVisible();
  return { context, page, before };
}

test('TC-FR1-13-AS-01', async ({ browser }) => {
  for (const flow of FLOWS) {
    const { context, page } = await complete(browser, flow);
    expect(await page.locator(flow.result).innerText(), `${flow.slug}: a result is shown without any sign-in`).toMatch(flow.slug === 'protein' ? /\d+\s?g\b/ : /\d\s?%/);
    expect(await page.locator('input[type=password], form[action*="login"], a[href*="login"], a[href*="sign"]').count(), `${flow.slug}: no sign-in affordance`).toBe(0);
    await context.close();
  }
});

test('TC-FR1-13-AS-02', async ({ browser }) => {
  for (const flow of FLOWS) {
    const { context, page, before } = await complete(browser, flow);
    const after = await snapshot(page);
    expect(after.cookies, `${flow.slug}: no cookie added by the flow`).toBe(before.cookies);
    const newLocal = Object.keys(after.local).filter((k) => !(k in before.local));
    const newSession = Object.keys(after.session).filter((k) => !(k in before.session));
    expect(newLocal, `${flow.slug}: no localStorage key added`).toEqual([]);
    expect(newSession, `${flow.slug}: no sessionStorage key added`).toEqual([]);
    const stored = JSON.stringify(after).toLowerCase();
    for (const v of enteredValues(flow.fixture.inputs)) expect(stored, `${flow.slug}: stored data contains entered value ${v}`).not.toContain(v.toLowerCase());
    const resultText = await page.locator(flow.result).innerText();
    const figure = (resultText.match(flow.slug === 'protein' ? /(\d+)\s?g\b/ : /(\d+\.\d)\s?%/) || [])[1];
    if (figure) expect(stored, `${flow.slug}: stored data contains the result ${figure}`).not.toContain(figure);
    await context.close();
  }
});

test('TC-FR1-13-AS-03', async ({ browser }) => {
  for (const flow of FLOWS) {
    const { context, page } = await complete(browser, flow);
    await page.reload();
    await expect(page.locator(flow.result)).toBeHidden();
    const filled = await page.evaluate((sel) => [...document.querySelectorAll(sel)].filter((el) => el.type === 'checkbox' || el.type === 'radio' ? el.checked : (el.tagName === 'SELECT' ? el.selectedIndex > 0 && el.value !== el.querySelector('option')?.value && false : el.value !== '')).map((el) => el.id || el.name), flow.inputsSel);
    expect(filled, `${flow.slug}: inputs empty after reload`).toEqual([]);
    // option buttons (sex, age band, …) must be unselected; the Metric/Imperial unit toggle is a display preference with a default, not an input
    expect(await page.locator('button.tap[aria-pressed="true"]:not(#allMetric):not(#allImperial), button.opt[aria-pressed="true"]').count(), `${flow.slug}: no option pre-selected after reload`).toBe(0);
    await context.close();
  }
});

test('TC-FR1-13-AS-04', async ({ browser }) => {
  for (const flow of FLOWS) {
    const { context, page } = await complete(browser, flow);
    const url = await page.evaluate(() => location.search + location.hash);
    expect(url, `${flow.slug}: no inputs or results in the URL`).toBe('');
    await context.close();
  }
});
