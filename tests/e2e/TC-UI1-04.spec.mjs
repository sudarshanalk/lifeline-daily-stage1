// TC-UI1-04 Calculator input clarity — a11y + e2e. Fixtures: FX-PR-INV-07/08, FX-DM-INV-01/04/05 (AS-02); FX-PR-INV-03/04 (AS-04).
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const load = (dir, f) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, f), 'utf8')).fixtures;
const PR_INV = load('protein', 'inv.json'); const DM_INV = load('diabetes', 'inv.json');
const pr = (id) => PR_INV.find((f) => f.id === id); const dm = (id) => DM_INV.find((f) => f.id === id);
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });
const TOOLS = { protein: 'protein-requirement-tool.html', diabetes: 'diabetes-risk-tool.html' };

test('TC-UI1-04-AS-01', async ({ page }) => {
  for (const [slug, file] of Object.entries(TOOLS)) {
    await page.goto(`${srv.url}/tools/${file}`);
    if (slug === 'protein') await page.locator('#sex button[data-val="female"]').click(); // reveal the conditional pregnancy group too
    if (slug === 'diabetes') await page.locator('[data-tap="hasSym"] button.tap[data-val="yes"]').click();
    // axe label rules over the whole form
    const axe = await new AxeBuilder({ page }).withRules(['label', 'select-name', 'aria-input-field-name', 'aria-toggle-field-name', 'label-title-only']).analyze();
    expect(axe.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`), `${slug}: axe label violations`).toEqual([]);
    // every visible input/select/group has a non-empty computed accessible name
    const unnamed = await page.evaluate(() => {
      const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
      const name = (el) => (el.getAttribute('aria-label') || '').trim() || [...(el.getAttribute('aria-labelledby') || '').split(/\s+/)].filter(Boolean).map((id) => document.getElementById(id)?.textContent?.trim() || '').join(' ').trim() || (el.id && document.querySelector(`label[for="${el.id}"]`)?.textContent?.trim()) || (el.closest('label')?.textContent?.trim()) || '';
      return [...document.querySelectorAll('input:not([type=hidden]):not([aria-hidden=true]), select, [role=group]')].filter(visible).filter((el) => !name(el)).map((el) => el.tagName + '#' + (el.id || el.className));
    });
    expect(unnamed, `${slug}: controls without an accessible name`).toEqual([]);
    // unit text adjacent to each numeric measurement input (a unit select or unit label in the same row/field)
    const noUnit = await page.evaluate(() => [...document.querySelectorAll('input[type=number]')].filter((el) => el.offsetParent !== null).filter((el) => { const scope = el.closest('.row, .field, .measure, .split, .measure-row, .input-row') || el.parentElement; const t = (scope?.textContent || '') + ' ' + [...scope.querySelectorAll('select option')].map((o) => o.textContent).join(' ') + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.placeholder || ''); return !/\b(cm|m|ft|in|inches|kg|lb|st|stones|years?|age|meals?)\b/i.test(t); }).map((el) => el.id));
    expect(noUnit, `${slug}: numeric inputs without adjacent unit text`).toEqual([]);
  }
});

test('TC-UI1-04-AS-02', async ({ page }) => {
  // protein: sex missing (INV-07), kidney missing (INV-08)
  for (const id of ['FX-PR-INV-07', 'FX-PR-INV-08']) {
    await page.goto(`${srv.url}/tools/${TOOLS.protein}`);
    await fillProteinForm(page, pr(id).inputs); await submitProtein(page);
    const err = page.locator('#err');
    await expect(err, id).toBeVisible();
    await expect(err).toHaveAttribute('role', 'alert');
    expect(await err.innerText(), `${id}: specific error`).toMatch(id.endsWith('07') ? /sex/i : /kidney/i);
    await expect(page.locator('#results')).toBeHidden();
  }
  // diabetes: sex (INV-01), ethnicity (INV-04), age band (INV-05) missing
  const field = { 'FX-DM-INV-01': 'sex', 'FX-DM-INV-04': 'ethn', 'FX-DM-INV-05': 'ageBand' };
  for (const [id, key] of Object.entries(field)) {
    await page.goto(`${srv.url}/tools/${TOOLS.diabetes}`);
    const inputs = { ...dm(id).inputs }; for (const k of Object.keys(inputs)) if (inputs[k] === null) delete inputs[k];
    await fillDiabetesForm(page, inputs); await submitDiabetes(page);
    const err = page.locator(`[data-err="${key}"]`);
    await expect(err, id).toBeVisible();
    await expect(err).toHaveAttribute('role', 'alert');
    expect((await err.innerText()).length, `${id}: specific error text`).toBeGreaterThan(8);
    await expect(page.locator('#results')).toBeHidden();
  }
});

test('TC-UI1-04-AS-03', async ({ page }) => {
  await page.goto(`${srv.url}/tools/${TOOLS.protein}`);
  await expect(page.locator('#pregWrap')).toBeHidden();
  await page.locator('#sex button[data-val="female"]').click();
  await expect(page.locator('#pregWrap')).toBeVisible();
  await page.locator('#preg button[data-val="pregnant"]').click();
  await page.locator('#sex button[data-val="male"]').click();
  await expect(page.locator('#pregWrap')).toBeHidden();
  expect(await page.evaluate(() => state.preg)).toBe('no');
  await page.goto(`${srv.url}/tools/${TOOLS.diabetes}`);
  await expect(page.locator('#symList')).toBeHidden();
  await page.locator('[data-tap="hasSym"] button.tap[data-val="yes"]').click();
  await expect(page.locator('#symList')).toBeVisible();
  await page.locator('[data-tap="hasSym"] button.tap[data-val="no"]').click();
  await expect(page.locator('#symList')).toBeHidden();
  await expect(page.locator('#whtrOut')).toHaveText('—');
  await page.locator('#ht').fill('170'); await page.locator('#waist').fill('85');
  await expect(page.locator('#whtrOut')).toHaveText('0.50');
});

test('TC-UI1-04-AS-04', async ({ page }) => {
  for (const id of ['FX-PR-INV-03', 'FX-PR-INV-04']) {
    await page.goto(`${srv.url}/tools/${TOOLS.protein}`);
    await fillProteinForm(page, pr(id).inputs); await submitProtein(page);
    const text = await page.locator('#err').innerText();
    expect(text, id).toContain('140 cm');
    expect(text, id).toContain('4ft 7in');
    await expect(page.locator('#results')).toBeHidden();
  }
});
