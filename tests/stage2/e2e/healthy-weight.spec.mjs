// FR2-39 Healthy Weight Check on its landing page (lld-members route /health-tools/healthy-weight-check/): saving the
// result, the onward track link and guide, the page itself, and phone widths. The calculator's own rules are unit-tested
// in tests/stage2/healthy-weight.test.mjs against the ORC-05 fixtures.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createMember, signIn } from './helpers.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const LANDING = '/health-tools/healthy-weight-check/';
const healthy = { age: 40, preg: 'no', eat: 'no', heightCm: 170, weightKg: 68, bg: 'White', loss: 'no' };

const emailFor = (label) => `${label}-${randomBytes(4).toString('hex')}@test.lifelinedaily.invalid`;

/** Serve the repository's tool files (status active) under /tools/ on the test site. */
async function serveTool(page) {
  const origin = new URL(process.env.LLD_WP_URL || 'http://localhost:8090').origin;
  await page.route('**/tools/**', async (route) => {
    const url = route.request().url();
    const file = url.includes('lld-members-link.js') ? 'lld-members-link.js' : url.includes('status.json') ? 'status.json' : 'healthy-weight-tool.html';
    let body = readFileSync(resolve(root, 'site/public_html/tools', file));
    if (file.endsWith('.html')) body = Buffer.from(body.toString('utf8').split('__LLD_ORIGIN__').join(origin));
    await route.fulfill({ status: 200, contentType: file.endsWith('.js') ? 'text/javascript' : file.endsWith('.json') ? 'application/json' : 'text/html', body });
  });
}

async function check(page, i) {
  const frame = page.frameLocator('.lld-tool-frame');
  await frame.locator('#age').waitFor();
  await frame.locator('#age').fill(String(i.age));
  await frame.locator('#ht').fill(String(i.heightCm));
  await frame.locator('#wt').fill(String(i.weightKg));
  if (i.waistCm) await frame.locator('#waist').fill(String(i.waistCm));
  for (const [group, value] of [['preg', i.preg], ['eat', i.eat], ['bg', i.bg], ['loss', i.loss]]) await frame.locator(`[data-tap="${group}"] [data-val="${value}"]`).click();
  await frame.locator('#submitBtn').click();
  return frame;
}

test.afterEach(async ({}, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) console.log(`FAILED ${testInfo.title}: ${(testInfo.error && testInfo.error.message) || testInfo.status}`.slice(0, 1500));
});

test('TC-FR2-39-AS-14', async ({ page, request }) => {
  await serveTool(page);
  await page.goto(LANDING);
  let frame = await check(page, healthy);
  await expect(frame.locator('[data-test="bmi"]')).toContainText('23.5');
  await expect(page.getByRole('button', { name: 'Save this result to my profile' })).toHaveCount(0);   // signed out

  const without = emailFor('hw-nocon');
  await createMember(request, { email: without, password, first_name: 'Pat', role: 'member', consents: ['terms'] });
  await signIn(page, without, password);
  await page.goto(LANDING);
  await check(page, healthy);
  await expect(page.getByRole('button', { name: 'Save this result to my profile' })).toHaveCount(0);

  const member = emailFor('hw-consent');
  await createMember(request, { email: member, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await signIn(page, member, password);
  await page.goto(LANDING);
  frame = await check(page, healthy);
  const save = page.getByRole('button', { name: 'Save this result to my profile' });
  await expect(save).toBeVisible();
  // an edit clears the result, and the save goes with it
  await frame.locator('#wt').fill('69');
  await expect(save).toBeHidden();
  await expect(page.locator('#lld-save-result input[name="lld_headline"]')).toHaveValue('');
  await frame.locator('#submitBtn').click();
  await expect(save).toBeVisible();
  await save.click();
  await expect(page.getByText('Saved', { exact: true })).toBeVisible();
  const row = await (await page.request.post('/wp-json/lld-members/v1/test/calculator', { data: { email: member } })).json();
  expect(Object.keys(row).sort()).toEqual(['band', 'calculator', 'calculator_version', 'headline', 'unit']);
  expect(row).toMatchObject({ calculator: 'healthy-weight', headline: '23.9', unit: 'kg/m²', band: 'Healthy weight' });
  expect(JSON.stringify(row)).not.toMatch(/White|"no"|\b170\b|\b69\b/);

  // a stop offers nothing to save; a message from another frame is ignored
  await page.goto(LANDING);
  frame = await check(page, { ...healthy, eat: 'yes' });
  await expect(frame.getByText('findahelpline.com')).toBeVisible();
  await expect(save).toBeHidden();
  await page.evaluate(() => {
    const fake = document.createElement('iframe'); document.body.appendChild(fake);
    fake.contentWindow.eval("parent.postMessage({type:'lld-calculator-result',calculator:'healthy-weight',headline:'99.9',unit:'kg/m²',band:'Healthy weight',version:'x',onward:true}, '*')");
  });
  await page.waitForTimeout(300);
  await expect(save).toBeHidden();
});

test('TC-FR2-39-AS-15', async ({ page, request }) => {
  await serveTool(page);
  const link = page.locator('[data-lld-onward]');
  for (const [name, inputs, shown] of [
    ['healthy', healthy, true],
    ['underweight', { ...healthy, weightKg: 50 }, false],
    ['weight loss', { ...healthy, loss: 'yes' }, false],
    ['pregnancy', { ...healthy, preg: 'notsure' }, false],
    ['eating', { ...healthy, eat: 'yes' }, false],
    ['under 18', { ...healthy, age: 17 }, false],
  ]) {
    await page.goto(LANDING);
    await expect(link, `${name}: before a result`).toBeVisible();
    await check(page, inputs);
    if (shown) await expect(link, name).toBeVisible(); else await expect(link, name).toBeHidden();
  }
  // a member whose profile excludes the Healthy Weight track never gets the offer (requirements section 8.2)
  const pregnant = emailFor('hw-preg');
  const created = await createMember(request, { email: pregnant, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
  await signIn(page, pregnant, password);
  await page.goto('/members/home/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  const saved = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, { data: { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify(['pregnancy']) }, headers: { 'X-WP-Nonce': nonce } });
  expect(saved.status()).toBe(200);
  await page.goto(LANDING);
  await expect(page.locator('[data-lld-track-link]')).toHaveCount(0);
  await expect(page.locator('a[href$="/programmes/healthy-weight/"]')).toHaveCount(0);
});

test('TC-FR2-39-AS-17', async ({ page }) => {
  await serveTool(page);
  const res = await page.goto(LANDING);
  expect(res.status()).toBe(200);
  await expect(page).toHaveTitle(/Healthy Weight Check/);
  await expect(page.locator('h1')).toHaveText('Healthy Weight Check');
  await expect(page.locator('iframe.lld-tool-frame')).toHaveCount(1);
  await expect(page.locator('iframe.lld-tool-frame')).toHaveAttribute('src', /\/tools\/healthy-weight-tool\.html$/);
  const canonical = page.locator('link[rel="canonical"]');
  if (await canonical.count()) expect(await canonical.first().getAttribute('href')).toContain(LANDING);
  const description = await page.locator('meta[name="description"]').getAttribute('content');
  expect(description).toMatch(/BMI/);
  await expect(page.locator('a[href$="/programmes/healthy-weight/"]')).toHaveCount(1);
  const sitemap = await page.request.get('/sitemap.xml');
  expect(sitemap.ok()).toBeTruthy();
  expect(await sitemap.text()).toContain(LANDING);
});

test('TC-FR2-39-AS-18', async ({ page }) => {
  await serveTool(page);
  for (const width of [320, 360, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/tools/healthy-weight-tool.html');
    await page.locator('#age').waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `${width}px: no sideways scrolling`).toBeLessThanOrEqual(0);
  }
  // keyboard only: errors take focus, then a full result is reachable without a mouse
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/tools/healthy-weight-tool.html');
  await page.locator('#submitBtn').focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#errSummary')).toBeFocused();
  await page.locator('#age').focus(); await page.keyboard.type('40');
  for (const [group, value] of [['preg', 'no'], ['eat', 'no'], ['bg', 'White'], ['loss', 'no']]) { await page.locator(`[data-tap="${group}"] [data-val="${value}"]`).focus(); await page.keyboard.press('Enter'); }
  await page.locator('#ht').focus(); await page.keyboard.type('170');
  await page.locator('#wt').focus(); await page.keyboard.type('68');
  await page.locator('#submitBtn').focus(); await page.keyboard.press('Enter');
  await expect(page.locator('#results')).toBeFocused();
  await expect(page.locator('[data-test="category"]')).toHaveText('Healthy weight');
  const scan = await new AxeBuilder({ page }).analyze();
  expect(scan.violations.filter((v) => ['serious', 'critical'].includes(v.impact)).map((v) => v.id)).toEqual([]);
});

// Staging and production have a real WordPress page at the landing address (created by `wp lld pages apply`, page 6540 on
// staging). WordPress serves it, and the plugin adds the save form, the track link and the guide below its own content,
// as for the other two calculators; the plugin's screen above is the fallback for a site without the page.
test('TC-FR2-39-AS-14 the Stage 1 Healthy Weight page keeps its own content, with the save, track link and guide below it', async ({ page, request }) => {
  test.setTimeout(180000);
  await serveTool(page);
  const stamp = `hw${randomBytes(3).toString('hex')}`;
  const fixture = (mode) => request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind: 'stage1-pages', stamp, mode } });
  const made = await fixture('create');
  expect(made.ok(), await made.text()).toBeTruthy();
  const pages = await made.json();
  const marker = page.locator('[data-lld-stage1-marker="healthy-weight"]');
  const save = page.getByRole('button', { name: 'Save this result to my profile' });
  const below = (first, second) => page.evaluate(([a, b]) => {
    const x = document.querySelector(a);
    const y = document.querySelector(b);
    return !!x && !!y && !!(x.compareDocumentPosition(y) & Node.DOCUMENT_POSITION_FOLLOWING);
  }, [first, second]);
  try {
    await page.goto(LANDING);
    await expect(marker).toHaveText(pages['healthy-weight'].marker);
    await expect(page.locator('iframe.lld-tool-frame')).toHaveCount(1);
    await expect(page.locator('[data-lld-track-link]')).toContainText('Healthy Weight');
    await expect(page.locator('[data-lld-guide]')).toBeVisible();
    expect(await below('[data-lld-stage1-marker="healthy-weight"]', 'iframe.lld-tool-frame')).toBe(true);
    expect(await below('iframe.lld-tool-frame', '[data-lld-onward]')).toBe(true);
    // An underweight result hides the onward offer on the real page too.
    await check(page, { ...healthy, weightKg: 50 });
    await expect(page.locator('[data-lld-onward]')).toBeHidden();
    await expect(save).toHaveCount(0);

    const member = emailFor('hw-s1');
    await createMember(request, { email: member, password, first_name: 'Pat', role: 'member', consents: ['terms', 'health'] });
    await signIn(page, member, password);
    await page.goto(LANDING);
    await expect(marker).toHaveText(pages['healthy-weight'].marker);
    await check(page, healthy);
    await expect(save).toBeVisible();
    await expect(page.locator('[data-lld-onward]')).toBeVisible();
    await save.click();
    await expect(page.getByText('Saved', { exact: true })).toBeVisible();
    await expect(marker).toHaveText(pages['healthy-weight'].marker);
    const row = await (await page.request.post('/wp-json/lld-members/v1/test/calculator', { data: { email: member } })).json();
    expect(row).toMatchObject({ calculator: 'healthy-weight', headline: '23.5', unit: 'kg/m²', band: 'Healthy weight' });
  } finally {
    const removed = await fixture('remove');
    expect(removed.ok(), await removed.text()).toBeTruthy();
  }
  // With the page gone, the plugin's own screen answers again.
  await page.goto(LANDING);
  await expect(page.getByRole('heading', { name: 'Healthy Weight Check', level: 1 })).toBeVisible();
  await expect(page.locator('[data-lld-stage1-marker]')).toHaveCount(0);
});
