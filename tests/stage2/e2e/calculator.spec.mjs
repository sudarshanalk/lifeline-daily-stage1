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

  // Pregnancy and breastfeeding show one minimum and no range: they can still be saved, the unit line standing as the band.
  for (const stage of ['pregnant', 'breastfeeding']) {
    const fixture = JSON.parse(readFileSync(resolve(root, 'fixtures/protein/prg.json'), 'utf8')).fixtures.find((item) => item.inputs.preg === stage);
    await page.goto('/health-tools/protein/');
    const tool = page.frameLocator('.lld-tool-frame');
    await tool.locator('#age').waitFor();
    await fillProteinForm(tool, fixture.inputs);
    await submitProtein(tool);
    await expect(tool.getByRole('heading', { name: `${fixture.expected.targetG} g` })).toBeVisible();
    await save.click();
    await expect(page.getByText('Saved', { exact: true })).toBeVisible();
    const kept = await (await page.request.post('/wp-json/lld-members/v1/test/calculator', { data: { email: withConsent } })).json();
    expect(kept.headline, stage).toBe(`${fixture.expected.targetG} g`);
    expect(kept.band, stage).toBe('of protein per day (minimum)');
  }
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

// Staging and production have real WordPress pages at both calculator addresses and at /medical-disclaimer/ (Stage 1,
// signed off clinically). WordPress serves those pages; the plugin only adds the save form, the track link and the
// starter guide below the calculator page's own content, and nothing to the disclaimer. Its own screens are the
// fallback for a site without the pages, as checked above.
test('TC-FR2-23-AS-02 the Stage 1 calculator pages and disclaimer keep their own content, with the extras below the calculators', async ({ page, request }) => {
  test.setTimeout(240000);
  await serveTools(page);
  const stamp = `s1${randomBytes(3).toString('hex')}`;
  const fixture = (mode) => request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'stage1-pages', stamp, mode } });
  const made = await fixture('create');
  expect(made.ok(), await made.text()).toBeTruthy();
  const pages = await made.json();
  const save = page.getByRole('button', { name: 'Save this result to my profile' });
  // The page's own content comes first, then the extras, in document order.
  const below = (first, second) => page.evaluate(([a, b]) => {
    const x = document.querySelector(a);
    const y = document.querySelector(b);
    return !!x && !!y && !!(x.compareDocumentPosition(y) & Node.DOCUMENT_POSITION_FOLLOWING);
  }, [first, second]);
  const landings = [
    ['protein', '/health-tools/protein/', 'Fitness and Strength'],
    ['diabetes', '/health-tools/diabetes-risk-check/', 'Blood Sugar Balance'],
  ];
  try {
    // Signed out: each calculator page shows its own content and its tool, with the track link and guide below.
    for (const [key, path, track] of landings) {
      await page.goto(path);
      const marker = page.locator(`[data-lld-stage1-marker="${key}"]`);
      await expect(marker, key).toHaveText(pages[key].marker);
      await expect(page.locator('iframe.lld-tool-frame'), key).toHaveCount(1);
      await expect(page.locator('[data-lld-track-link]'), key).toContainText(track);
      await expect(page.locator('[data-lld-guide]'), key).toBeVisible();
      expect(await below(`[data-lld-stage1-marker="${key}"]`, 'iframe.lld-tool-frame'), key).toBe(true);
      expect(await below('iframe.lld-tool-frame', '[data-lld-track-link]'), key).toBe(true);
      expect(await below('[data-lld-track-link]', '[data-lld-guide]'), key).toBe(true);
      await expect(page.locator('#lld-save-result'), key).toHaveCount(0);
    }
    await page.goto('/health-tools/protein/');
    await proteinResult(page);
    await expect(save).toHaveCount(0);

    // The disclaimer page is the real page, with nothing added.
    await page.goto('/medical-disclaimer/');
    await expect(page.locator('[data-lld-stage1-marker="disclaimer"]')).toHaveText(pages.disclaimer.marker);
    await expect(page.getByText('Lifeline Daily is general information, not a diagnosis')).toHaveCount(0);
    await expect(page.locator('[data-lld-track-link], [data-lld-guide], #lld-save-result')).toHaveCount(0);

    // A member without health consent sees no save form.
    const without = emailFor('s1nocon');
    await createMember(request, { email: without, password, first_name: 'Pat', role: 'member', consents: ['terms'] });
    await signIn(page, without, password);
    await page.goto('/health-tools/protein/');
    await expect(page.locator('[data-lld-stage1-marker="protein"]')).toHaveText(pages.protein.marker);
    await proteinResult(page);
    await expect(save).toHaveCount(0);

    // A member with health consent saves from the real page: only the headline, unit, band and version are kept.
    const withConsent = emailFor('s1consent');
    await createMember(request, { email: withConsent, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
    await signIn(page, withConsent, password);
    await page.goto('/health-tools/protein/');
    await expect(page.locator('[data-lld-stage1-marker="protein"]')).toHaveText(pages.protein.marker);
    await proteinResult(page);
    await expect(save).toBeVisible();
    expect(await below('[data-lld-stage1-marker="protein"]', '#lld-save-result')).toBe(true);
    expect(await below('#lld-save-result', '[data-lld-track-link]')).toBe(true);
    await save.click();
    await expect(page.getByText('Saved', { exact: true })).toBeVisible();
    await expect(page.locator('[data-lld-stage1-marker="protein"]')).toHaveText(pages.protein.marker);
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

    // The diabetes page saves too.
    await page.goto('/health-tools/diabetes-risk-check/');
    await expect(page.locator('[data-lld-stage1-marker="diabetes"]')).toHaveText(pages.diabetes.marker);
    const frame = page.frameLocator('.lld-tool-frame');
    await frame.locator('#ht').waitFor();
    await fillDiabetesForm(frame, diabetes.inputs);
    await submitDiabetes(frame);
    await expect(frame.locator('#riskNum')).toHaveText(`${diabetes.expected.pctDisplay}%`);
    await expect(save).toBeVisible();
    await save.click();
    await expect(page.getByText('Saved', { exact: true })).toBeVisible();
    const kept = await (await page.request.post('/wp-json/lld-members/v1/test/calculator', { data: { email: withConsent } })).json();
    expect(kept.calculator).toBe('diabetes');
    expect(kept.headline).toBe(`${diabetes.expected.pctDisplay}%`);
    expect(kept.band).toBe('Low');
  } finally {
    const removed = await fixture('remove');
    expect(removed.ok(), await removed.text()).toBeTruthy();
  }
  // With the pages gone, the plugin's own screens answer again.
  await page.goto('/health-tools/protein/');
  await expect(page.getByRole('heading', { name: 'Daily Protein Target', level: 1 })).toBeVisible();
  await expect(page.locator('[data-lld-stage1-marker]')).toHaveCount(0);
  await expect(page.locator('[data-lld-track-link]')).toContainText('Fitness and Strength');
  await page.goto('/medical-disclaimer/');
  await expect(page.getByText('Lifeline Daily is general information, not a diagnosis')).toBeVisible();
});
