// P3-72, owner answer 86: completed calculations are counted on the site itself for the Monday report. A result
// adds one to that calculator's weekly total, once per page visit; the request carries the calculator name only and
// no cookie, and nothing reaches Google (works with or without the measurement ID, before any cookie choice).
import { expect, test } from '@playwright/test';
import { serveHealthyWeightTool } from '../../key-journeys/helpers.mjs';

async function counts(request, reset = false) {
  const res = await request.post('/wp-json/lld-members/v1/test/calculator-count', { data: { reset } });
  expect(res.ok(), await res.text()).toBeTruthy();
  return (await res.json()).counts;
}

async function complete(frame, weight) {
  await frame.locator('#age').fill('47'); await frame.locator('#ht').fill('171'); await frame.locator('#wt').fill(weight);
  for (const [group, value] of [['preg', 'no'], ['eat', 'no'], ['bg', 'White'], ['loss', 'no']]) await frame.locator(`[data-tap="${group}"] [data-val="${value}"]`).click();
  await frame.locator('#submitBtn').click();
}

test('TC-FR3-70-AS-01 (completions)', async ({ page, request }) => {
  expect(await counts(request, true)).toEqual({ protein: 0, diabetes: 0, 'healthy-weight': 0 });
  const sent = [];
  const google = [];
  page.on('request', (req) => {
    if (req.url().includes('/calculator-count')) sent.push({ body: req.postData(), headers: req.headers() });
    if (/(googletagmanager|google-analytics)\.com/.test(req.url())) google.push(req.url());
  });
  await serveHealthyWeightTool(page);
  await page.goto('/health-tools/healthy-weight-check/');
  const frame = page.frameLocator('.lld-tool-frame');
  await complete(frame, '83');
  await expect(frame.locator('[data-test="bmi"]')).toContainText('28.4');
  await expect.poll(async () => (await counts(request))['healthy-weight']).toBe(1);
  // A second result in the same visit is not counted again.
  await frame.locator('#wt').fill('70');
  await frame.locator('#submitBtn').click();
  await expect(frame.locator('[data-test="bmi"]')).toContainText('23.9');
  await page.waitForTimeout(800);
  expect((await counts(request))['healthy-weight']).toBe(1);
  // A new visit counts again.
  await page.reload();
  await complete(page.frameLocator('.lld-tool-frame'), '83');
  await expect.poll(async () => (await counts(request))['healthy-weight']).toBe(2);
  expect(await counts(request)).toEqual({ protein: 0, diabetes: 0, 'healthy-weight': 2 });
  // Exactly what was sent: the calculator name, no cookie, no result and no answers.
  expect(sent).toHaveLength(2);
  for (const req of sent) {
    expect(JSON.parse(req.body)).toEqual({ calculator: 'healthy-weight' });
    expect(req.headers.cookie).toBeUndefined();
  }
  expect(google).toEqual([]);
});

test('TC-NFR3-16-AS-02 (completions)', async ({ request }) => {
  await counts(request, true);
  const base = new URL(process.env.LLD_WP_URL || 'http://127.0.0.1:8090').origin;
  // Another site cannot add to the count, and an unknown calculator is not counted.
  const foreign = await request.post('/wp-json/lld-members/v1/calculator-count', { headers: { Origin: 'https://evil.example' }, data: { calculator: 'protein' } });
  expect(foreign.status()).toBe(403);
  const unknown = await request.post('/wp-json/lld-members/v1/calculator-count', { headers: { Origin: base }, data: { calculator: 'bmi-31.4' } });
  expect((await unknown.json()).counted).toBe(false);
  const ok = await request.post('/wp-json/lld-members/v1/calculator-count', { headers: { Origin: base }, data: { calculator: 'protein', headline: '96 g', weight: 83 } });
  expect((await ok.json()).counted).toBe(true);
  expect(await counts(request)).toEqual({ protein: 1, diabetes: 0, 'healthy-weight': 0 });
});
