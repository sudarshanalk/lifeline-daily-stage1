// Stage 3 screen tests for P3-44 (FR3-37): barcode scanning in the browser, live on the CI WordPress at /members/food/scan/.
// The barcode and food_tracker switches are at test_accounts in CI (tests/ci/stage2-wp.sh), which also imports one
// made-up packaged food: "CI test oat bar", barcode 2000000000015 (GS1 prefix 2, never a real product), 400 kcal per
// 100 g, a 40 g bar. The camera is a canvas with that barcode drawn on it, so the page's own reader runs on real frames.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password, emailFor } from '../../key-journeys/helpers.mjs';
import { restNonce } from '../../../stage2/e2e/helpers.mjs';

const A = 'p3-api-a@test.lifelinedaily.invalid';
const KNOWN = '2000000000015';
const UNKNOWN = '2000000000022';

/** A clean Premium test member (barcode scanning and adding your own product are Premium, FR3-63), signed in. */
async function fresh(page, email = A) {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  await createMember(page.request, { email, password, first_name: 'Alex', health_consent: true, consents: ['terms'], access_state: 'Premium' });
  await signIn(page, email, password);
}

async function foodLog(page) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const res = await page.request.get('/wp-json/lld-members/v1/me/food-log', { headers: { 'X-WP-Nonce': nonce } });
  expect(res.ok(), await res.text()).toBeTruthy();
  return (await res.json()).rows;
}

/** A camera that shows an EAN-13 barcode: bars drawn on a canvas, streamed as the video. The built-in detector is
 * removed, so the page's own reader (assets/stage3.js) does the reading, as on an iPhone. */
function fakeCamera(code) {
  return [({ code }) => {
    const L = ['3211', '2221', '2122', '1411', '1132', '1231', '1114', '1312', '1213', '3112'].map((s) => [...s].map(Number));
    const FIRST = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLL', 'LGLGGL', 'LGGLGL'];
    const bits = [];
    const put = (w, dark) => w.forEach((n, i) => { for (let k = 0; k < n; k++) bits.push((i % 2 === 0) === dark ? 1 : 0); });
    put([1, 1, 1], true);
    for (let i = 1; i <= 6; i++) put(FIRST[+code[0]][i - 1] === 'L' ? L[+code[i]] : [...L[+code[i]]].reverse(), false);
    put([1, 1, 1, 1, 1], false);
    for (let i = 7; i <= 12; i++) put(L[+code[i]], true);
    put([1, 1, 1], true);
    delete window.BarcodeDetector;
    let tick = 0;
    const draw = (canvas) => {
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = tick++ % 2 ? '#eee' : '#fff'; // a changing corner, so every browser sends new frames
      ctx.fillRect(0, 0, 4, 4);
      ctx.fillStyle = '#111';
      const px = 6, left = (canvas.width - bits.length * px) / 2;
      bits.forEach((b, i) => { if (b) ctx.fillRect(left + i * px, 120, px, 240); });
    };
    const fake = async () => {
      const canvas = document.createElement('canvas');
      canvas.width = 800;
      canvas.height = 480;
      canvas.style.cssText = 'position:fixed;left:-9999px;top:0';
      (document.body || document.documentElement).appendChild(canvas);
      draw(canvas);
      setInterval(() => draw(canvas), 100); // keep frames coming
      return canvas.captureStream(10);
    };
    // On the interface itself as well as the object, so the browser's own camera request is never reached.
    Object.defineProperty(MediaDevices.prototype, 'getUserMedia', { value: fake, configurable: true, writable: true });
    Object.defineProperty(navigator.mediaDevices, 'getUserMedia', { value: fake, configurable: true, writable: true });
  }, { code }];
}

test.describe.serial('P3-44 barcode scanning in the browser', () => {
  test.beforeEach(({ page }) => {
    page.on('console', (m) => { if (m.text().startsWith('lld-scan')) console.log(`[${test.info().project.name}] ${m.text()}`); });
  });

  test('TC-FR3-37-AS-01 Scan a test barcode that is in the database: the product and its nutrition are shown, ready to log', async ({ page }) => {
    await fresh(page);
    await page.addInitScript(...fakeCamera(KNOWN));
    await page.goto('/members/food/scan/');
    await expect(page.locator('h1')).toHaveText('Scan a barcode');
    await page.getByRole('button', { name: 'Scan with the camera' }).click();

    const card = page.locator('[data-lld-scan-result]');
    await expect(card.locator('[data-lld-product-name]')).toHaveText('CI test oat bar', { timeout: 20000 });
    await expect(page.locator('[data-lld-scan]')).toHaveAttribute('data-lld-barcode', KNOWN);
    await expect(page.locator('[data-lld-scan-view]')).toBeHidden(); // the camera stops once the barcode is read
    await expect(card.locator('#lld-scan-grams')).toHaveValue('40');
    await expect(card.locator('[data-lld-nutrient="kcal"] td')).toHaveText('160 kcal');
    await expect(card.locator('[data-lld-nutrient="protein_g"] td')).toHaveText('4 g');
    // A different amount changes the numbers at once.
    await card.locator('#lld-scan-portion').selectOption('100');
    await expect(card.locator('[data-lld-nutrient="kcal"] td')).toHaveText('400 kcal');
    await card.locator('#lld-scan-grams').fill('40');

    await card.locator('#lld-scan-meal').selectOption('lunch');
    await card.getByRole('button', { name: 'Add to my food diary' }).click();
    await expect(card.locator('[data-lld-log-status]')).toHaveText('Added to your food diary.');
    const rows = await foodLog(page);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ meal: 'lunch', food_kind: 'food', source: 'barcode' });
    expect(Number(rows[0].grams)).toBe(40);
    expect(Number(rows[0].kcal)).toBe(160);

    // The same product found by typing its number, spaces and all.
    await page.goto('/members/food/scan/');
    await page.locator('#lld-barcode-number').fill('2 000000 000015');
    await page.getByRole('button', { name: 'Find' }).click();
    await expect(page.locator('[data-lld-product-name]')).toHaveText('CI test oat bar');
    // A number with a wrong check digit is caught before any lookup.
    await page.locator('#lld-barcode-number').fill('2000000000016');
    await page.getByRole('button', { name: 'Find' }).click();
    await expect(page.locator('[data-lld-scan-status]')).toContainText('whole barcode');
  });

  test("TC-FR3-37-AS-02 Scan a test barcode that is not in the database: the member is offered 'Add this product'", async ({ page }) => {
    await fresh(page);
    await page.addInitScript(...fakeCamera(UNKNOWN));
    await page.goto('/members/food/scan/');
    await page.getByRole('button', { name: 'Scan with the camera' }).click();
    const card = page.locator('[data-lld-scan-result]');
    await expect(card.locator(`[data-lld-not-found="${UNKNOWN}"]`)).toBeVisible({ timeout: 20000 });
    await card.getByRole('button', { name: 'Add this product' }).click();
    await card.getByLabel('Product name').fill('CI test granola');
    await card.getByLabel('Portion size in grams').fill('45');
    await card.getByLabel('Calories (kcal) in that portion').fill('200');
    await card.getByLabel('Protein (g)').fill('5');
    await card.getByRole('button', { name: 'Save this product' }).click();
    // Saved with its barcode, and found by it straight away, ready to log.
    await expect(card.locator('[data-lld-product-name]')).toHaveText('CI test granola');
    await expect(card.locator('[data-lld-nutrient="kcal"] td')).toHaveText('200 kcal');
    await card.getByRole('button', { name: 'Add to my food diary' }).click();
    await expect(card.locator('[data-lld-log-status]')).toHaveText('Added to your food diary.');
    const rows = await foodLog(page);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ food_kind: 'custom', source: 'barcode' });
    expect(Number(rows[0].kcal)).toBe(200);

    // The next scan of the same barcode finds the member's product.
    await page.goto('/members/food/scan/');
    await page.locator('#lld-barcode-number').fill(UNKNOWN);
    await page.getByRole('button', { name: 'Find' }).click();
    await expect(page.locator('[data-lld-product-name]')).toHaveText('CI test granola');
  });

  test('TC-FR3-37-AS-03 If the member refuses camera access, typed search still works and a short message explains why scanning is unavailable', async ({ page }) => {
    await fresh(page);
    await page.addInitScript(() => {
      const refuse = async () => { throw new DOMException('Permission denied', 'NotAllowedError'); };
      Object.defineProperty(MediaDevices.prototype, 'getUserMedia', { value: refuse, configurable: true, writable: true });
      Object.defineProperty(navigator.mediaDevices, 'getUserMedia', { value: refuse, configurable: true, writable: true });
    });
    await page.goto('/members/food/scan/');
    await page.getByRole('button', { name: 'Scan with the camera' }).click();
    await expect(page.locator('[data-lld-scan]')).toHaveAttribute('data-lld-camera', 'refused');
    await expect(page.locator('[data-lld-scan-status]')).toHaveText("Scanning needs the camera, and camera access wasn't allowed. You can type the barcode number or search for the food instead.");
    await expect(page.getByRole('button', { name: 'Scan with the camera' })).toBeHidden();
    await expect(page.locator('#lld-barcode-number')).toBeFocused();

    // Typed search works.
    await page.locator('#lld-food-query').fill('CI test oat');
    await page.getByRole('button', { name: 'Search' }).click();
    const hit = page.locator('[data-lld-food-results] button', { hasText: 'CI test oat bar' });
    await expect(hit).toBeVisible();
    await hit.click();
    await expect(page.locator('[data-lld-product-name]')).toHaveText('CI test oat bar');
    await page.getByRole('button', { name: 'Add to my food diary' }).click();
    await expect(page.locator('[data-lld-log-status]')).toHaveText('Added to your food diary.');
    const rows = await foodLog(page);
    expect(rows[0]).toMatchObject({ source: 'search' });

    // Typing the barcode number works too, and the page shows no requirement or test ID to the member.
    await page.goto('/members/food/scan/');
    await page.locator('#lld-barcode-number').fill(KNOWN);
    await page.getByRole('button', { name: 'Find' }).click();
    await expect(page.locator('[data-lld-product-name]')).toHaveText('CI test oat bar');
    await expect(page.locator('main')).not.toContainText(/\b(?:TC-)?N?FR\d-\d\d/);

    // At phone width nothing scrolls sideways.
    await page.setViewportSize({ width: 360, height: 780 });
    const wide = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(wide).toBeLessThanOrEqual(360);
  });

  test('TC-NFR3-06-AS-02 the scan screen and the barcode lookup are not there for an ordinary member', async ({ page }) => {
    const email = emailFor('scan');
    await createMember(page.request, { email, password, first_name: 'Sam', health_consent: true, consents: ['terms'] });
    await signIn(page, email, password);
    const res0 = await page.goto('/members/food/scan/');
    expect(res0.status()).toBe(404);
    await expect(page.locator('[data-lld-scan]')).toHaveCount(0);
    await page.goto('/members/home/');
    const nonce = await restNonce(page);
    const res = await page.request.get(`/wp-json/lld-members/v1/me/food-barcode?code=${KNOWN}`, { headers: { 'X-WP-Nonce': nonce } });
    expect(res.status()).toBe(404);
    // Signed out, the address is not there either: no sign-in redirect that would show it exists.
    await page.context().clearCookies();
    const out = await page.goto('/members/food/scan/');
    expect(out.status()).toBe(404);
    expect(new URL(page.url()).pathname).toBe('/members/food/scan/');
  });
});
