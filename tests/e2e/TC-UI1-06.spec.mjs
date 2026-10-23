// TC-UI1-06 Email consent interface — e2e. No fixtures beyond FX-PR-NRM-01 / FX-DM-QD-01 to reach a result.
// AS-01 #consent unchecked on load, no other checkbox in the email card, label text hash = approved copy deck (explicit
//       health-data consent with a Privacy Policy link, DEC-21/DEC-36); AS-02 sending blocked until a valid e-mail and consent;
// AS-03 skipping e-mail leaves #results complete; AS-04 no pre-ticked checkbox anywhere in either tool on load.
import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fillProteinForm, submitProtein } from './helpers/protein-form.mjs';
import { fillDiabetesForm, submitDiabetes } from './helpers/diabetes-form.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const fx = (dir, file, id) => JSON.parse(readFileSync(resolve(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const sentinel = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'sentinel-values.json'), 'utf8'));
const copyApproval = JSON.parse(readFileSync(resolve(ROOT, 'approvals', 'copy.json'), 'utf8'));
const sha256 = (s) => createHash('sha256').update(s, 'utf8').digest('hex');
const TOOLS = {
  protein: { file: 'protein-requirement-tool.html', fixture: fx('protein', 'nrm.json', 'FX-PR-NRM-01'), fill: fillProteinForm, submit: submitProtein, card: '#emailCard', send: '#sendBtn', status: '#emailErr' },
  diabetes: { file: 'diabetes-risk-tool.html', fixture: fx('diabetes', 'qd.json', 'FX-DM-QD-01'), fill: fillDiabetesForm, submit: submitDiabetes, card: '.email-box', send: '#emailBtn', status: '#emailStatus' },
};
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer(); });
test.afterAll(async () => { await srv?.close(); });
const open = async (page, t) => { await page.goto(`${srv.url}/tools/${t.file}`); };
const result = async (page, t) => { await t.fill(page, t.fixture.inputs); await t.submit(page); await expect(page.locator('#results')).toBeVisible(); };

test('TC-UI1-06-AS-01', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await open(page, t);
    await expect(page.locator('#consent'), `${slug}: consent unchecked on load`).not.toBeChecked();
    expect(await page.locator(`${t.card} input[type=checkbox]`).count(), `${slug}: exactly one checkbox in the email card (no marketing box)`).toBe(1);
    // label text equals the approved copy deck: protein's consent is an inline [data-copy] string, diabetes' is MESSAGES.email.consent
    // (rendered into #consentLabel when the result shows, so the card is read after a result)
    await result(page, t);
    await expect(page.locator('#consent'), `${slug}: still unchecked when the card appears`).not.toBeChecked();
    const label = page.locator('label.consent');
    const text = await label.evaluate((el) => el.textContent.replace(/\s+/g, ' ').trim()); // the card is hidden until a result shows
    expect(text.toLowerCase(), `${slug}: explicit health-data consent`).toMatch(/health/);
    expect(text, `${slug}: screening, not advice`).toMatch(/not medical advice/i);
    const link = label.locator('a');
    await expect(link, `${slug}: Privacy Policy link`).toHaveText(/privacy policy/i);
    expect(await link.getAttribute('href'), `${slug}: link target`).toMatch(/\/privacy-policy\/$/);
    if (slug === 'protein') {
      const deckText = await page.locator('[data-copy="consent"]').evaluate((el) => el.textContent.replace(/\s+/g, ' ').trim());
      expect(copyApproval.inline['protein.consent'], 'protein consent hash approved (copy deck)').toBe(sha256(deckText));
    } else {
      // the served page has __LLD_ORIGIN__ substituted in the Privacy Policy href; the deck hashes the authored string
      const raw = await page.evaluate(() => MESSAGES.email.consent);
      expect(copyApproval.messages['diabetes.email.consent'], 'diabetes consent hash approved (copy deck)').toBe(sha256(raw.split(srv.url).join('__LLD_ORIGIN__')));
      expect(await page.locator('#consentLabel').innerHTML()).toBe(raw);
    }
  }
});

test('TC-UI1-06-AS-02', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    let posts = 0;
    await page.route('**/submit*.php', (route) => { posts++; route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"ok"}' }); });
    await open(page, t); await result(page, t);
    const send = page.locator(t.send);
    // nothing entered: blocked (disabled button or validation message), no request
    if (await send.isEnabled()) { await send.click(); await expect(page.locator(t.status)).toBeVisible(); await expect(page.locator(t.status)).not.toHaveText(''); }
    expect(posts, `${slug}: no request without e-mail/consent`).toBe(0);
    // valid e-mail, no consent: still blocked
    await page.locator('#email').fill(sentinel.email);
    if (await send.isEnabled()) { await send.click(); await expect(page.locator(t.status)).not.toHaveText(''); }
    expect(posts, `${slug}: no request without consent`).toBe(0);
    // consent, invalid e-mail: still blocked
    await page.locator('#consent').check(); await page.locator('#email').fill('not-an-email');
    if (await send.isEnabled()) { await send.click(); await expect(page.locator(t.status)).not.toHaveText(''); }
    expect(posts, `${slug}: no request with an invalid e-mail`).toBe(0);
    // both valid: the request goes out
    await page.locator('#email').fill(sentinel.email);
    await expect(send).toBeEnabled(); await send.click();
    await expect.poll(() => posts, { message: `${slug}: request sent once e-mail and consent are valid` }).toBe(1);
    await page.unroute('**/submit*.php');
  }
});

test('TC-UI1-06-AS-03', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await open(page, t); await result(page, t);
    // the visitor never touches the e-mail card: the result is complete on screen
    for (const hook of ['headline', 'metrics', 'advice', 'methodology', 'disclaimer']) await expect(page.locator(`#results [data-test=${hook}]`), `${slug}: ${hook} shown`).toBeVisible();
    expect((await page.locator('#results [data-test=headline]').innerText()).trim().length, `${slug}: headline has content`).toBeGreaterThan(3);
    await expect(page.locator('#consent')).not.toBeChecked();
    await expect(page.locator('#email')).toHaveValue('');
  }
});

test('TC-UI1-06-AS-04', async ({ page }) => {
  for (const [slug, t] of Object.entries(TOOLS)) {
    await open(page, t);
    expect(await page.locator('input[type=checkbox]:checked').count(), `${slug}: no pre-ticked checkbox on load`).toBe(0);
    await result(page, t);
    expect(await page.locator(`${t.card} input[type=checkbox]:checked`).count(), `${slug}: none ticked when the email card appears`).toBe(0);
  }
});
