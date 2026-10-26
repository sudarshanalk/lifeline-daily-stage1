import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { createMember, signIn } from './helpers.mjs';
import { fillProteinForm, submitProtein } from '../../e2e/helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from '../../e2e/helpers/diabetes-form.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const protein = JSON.parse(readFileSync(resolve(root, 'fixtures/protein/nrm.json'), 'utf8')).fixtures[0];
const diabetes = JSON.parse(readFileSync(resolve(root, 'fixtures/diabetes/qd.json'), 'utf8')).fixtures[0];

function emailFor(label) {
  return `${label}-${randomBytes(4).toString('hex')}@test.lifelinedaily.invalid`;
}

async function serveTools(page) {
  const origin = new URL(process.env.LLD_WP_URL || 'http://localhost:8090').origin;
  await page.route('**/tools/**', async (route) => {
    const url = route.request().url();
    const file = url.includes('lld-members-link.js')
      ? 'lld-members-link.js'
      : url.includes('status.json')
        ? 'status.json'
        : url.includes('diabetes')
          ? 'diabetes-risk-tool.html'
          : 'protein-requirement-tool.html';
    let body = readFileSync(resolve(root, 'site/public_html/tools', file));
    if (file.endsWith('.html')) body = Buffer.from(body.toString('utf8').split('__LLD_ORIGIN__').join(origin));
    const type = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.json') ? 'application/json' : 'text/html';
    await route.fulfill({ status: 200, contentType: type, body });
  });
}

async function proteinResult(page) {
  const frame = page.frameLocator('.lld-tool-frame');
  await frame.locator('#age').waitFor();
  await fillProteinForm(frame, protein.inputs);
  await submitProtein(frame);
  await expect(frame.getByRole('heading', { name: `${protein.expected.targetG} g` })).toBeVisible();
}

// A failing journey prints its error at once, so a run stopped by the job time limit still shows why.
test.afterEach(async ({}, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) console.log(`FAILED ${testInfo.title}: ${(testInfo.error && testInfo.error.message) || testInfo.status}`.slice(0, 1500));
});

test('TC-FR2-23-AS-02', async ({ page, request }) => {
  await serveTools(page);
  await page.goto('/health-tools/protein/');
  await proteinResult(page);
  await expect(page.getByRole('button', { name: 'Save this result to my profile' })).toHaveCount(0);

  const without = emailFor('nocon');
  await createMember(request, { email: without, password, first_name: 'Pat', role: 'member', consents: ['terms'] });
  await signIn(page, without, password);
  await page.goto('/health-tools/protein/');
  await proteinResult(page);
  await expect(page.getByRole('button', { name: 'Save this result to my profile' })).toHaveCount(0);

  const withConsent = emailFor('consent');
  await createMember(request, { email: withConsent, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await signIn(page, withConsent, password);
  await page.goto('/health-tools/protein/');
  await proteinResult(page);
  const save = page.getByRole('button', { name: 'Save this result to my profile' });
  await expect(save).toBeVisible();
  await save.click();
  await expect(page.getByText('Saved', { exact: true })).toBeVisible();
  const stored = await page.request.post('/wp-json/lld-members/v1/test/calculator', { data: { email: withConsent } });
  expect(stored.ok(), await stored.text()).toBeTruthy();
  const row = await stored.json();
  expect(Object.keys(row).sort()).toEqual(['band', 'calculator', 'calculator_version', 'headline', 'unit']);
  expect(row.calculator).toBe('protein');
  expect(row.headline).toBe(`${protein.expected.targetG} g`);
  expect(row.band).toContain(String(protein.expected.lowG));
  expect(row.band).toContain(String(protein.expected.highG));
  expect(row.calculator_version).toHaveLength(64);
  expect(JSON.stringify(row)).not.toContain(withConsent);
  expect(JSON.stringify(row)).not.toContain('omnivore');

  // A result the tool withdraws (kidney disease stops the number) can no longer be saved.
  await page.goto('/health-tools/protein/');
  await proteinResult(page);
  await expect(save).toBeVisible();
  const frame = page.frameLocator('.lld-tool-frame');
  await frame.locator('#restart').click();
  await frame.locator('#kidney button[data-val="yes"]').click();
  await submitProtein(frame);
  await expect(frame.locator('[data-test="renal-stop"]')).toBeVisible();
  await expect(save).toBeHidden();
  await expect(page.locator('#lld-save-result input[name="lld_headline"]')).toHaveValue('');
  await expect(page.locator('#lld-save-result input[name="lld_band"]')).toHaveValue('');
});

test('TC-FR2-23-AS-03', async ({ page }) => {
  await serveTools(page);
  await page.goto('/health-tools/protein/');
  await proteinResult(page);
  await expect(page.getByRole('button', { name: 'Save this result to my profile' })).toHaveCount(0);
  await page.goto('/health-tools/diabetes-risk-check/');
  const frame = page.frameLocator('.lld-tool-frame');
  await frame.locator('#ht').waitFor();
  await fillDiabetesForm(frame, diabetes.inputs);
  await submitDiabetes(frame);
  await expect(frame.locator('#riskNum')).toHaveText(`${diabetes.expected.pctDisplay}%`);
  await expect(frame.locator('.risk-band')).toContainText('Low');
  await expect(page.getByRole('button', { name: 'Save this result to my profile' })).toHaveCount(0);
});
