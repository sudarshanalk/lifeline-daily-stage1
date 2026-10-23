// TC-FR1-04 Tools hub and landing pages — e2e against the local WordPress test stack (tests/lib/wp-stack.mjs: production
// snapshot + MariaDB with the repository's lld-tools plugin; `wp lld pages apply` writes the hub/landing content) or, with
// LLD_WP_URL, a staging origin. Fixtures: status.json default (site/public_html/tools/status.json), content/tool-cards.json.
// Skips with the reason on runners without the snapshot (CI until OI-1).
import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const cards = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const HEADINGS = ['Purpose', 'Who it is for', 'What you will enter', 'Limitations', 'How it works', 'Privacy', 'Try the tool', 'Related reading'];
const ENGINE = { protein: /Daily Protein Target engine/i, diabetes: /QDiabetes-2018/ };
let stack; const original = {};
const PAGE_IDS = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };   // page slugs → ids in the snapshot database
test.beforeAll(async () => {
  const a = available();
  test.skip(!a.ok, a.reason);
  stack = await startWpStack();
  if (!stack.external) {
    // keep the snapshot database's page content and restore it afterwards, so every run starts from the as-is pages
    for (const [slug, id] of Object.entries(PAGE_IDS)) original[id] = stack.wp('post', 'get', String(id), '--field=post_content').stdout;
    if (!process.env.LLD_FR104_PRISTINE) { const r = stack.wp('lld', 'pages', 'apply'); if (r.status !== 0) throw new Error(r.stdout + r.stderr); }
  }
});
test.afterAll(async () => {
  if (stack && !stack.external) for (const [id, content] of Object.entries(original)) stack.wp('post', 'update', id, `--post_content=${content}`);
  await stack?.close();
});
const hub = () => `${stack.url}/health-tools/`;

test('TC-FR1-04-AS-01', async ({ page }) => {
  await page.goto(hub());
  await expect(page.locator('[data-test=tools-hub]')).toHaveCount(1);
  expect(await page.locator('[data-tool-card][data-status=active]').count(), 'exactly two active cards').toBe(2);
  expect(await page.locator('[data-tool-card]').count(), 'two tools plus any label-only chips').toBeGreaterThanOrEqual(2);
  expect(await page.locator('iframe').count(), 'the hub iframe is gone').toBe(0);
});

test('TC-FR1-04-AS-02', async ({ page, request }) => {
  await page.goto(hub());
  for (const c of cards) {
    const cta = page.locator(`[data-tool-card][data-tool="${c.tool_slug}"] [data-test=cta]`);
    const href = await cta.getAttribute('href');
    expect(href, `${c.tool_slug}: CTA href equals landing_url`).toBe(c.landing_url);
    const r = await request.get(stack.url + href);
    expect(r.status(), `${c.tool_slug}: ${href} → 200`).toBe(200);
  }
});

test('TC-FR1-04-AS-03', async ({ page }) => {
  await page.goto(hub());
  expect(await page.locator('[data-status=label_only] a').count(), 'no anchor inside a label-only chip').toBe(0);
  expect(await page.locator('[data-status=label_only]').count(), 'the planned chip from status.json labels[]').toBeGreaterThanOrEqual(1);
});

test('TC-FR1-04-AS-04', async ({ page }) => {
  for (const c of cards) {
    await page.goto(stack.url + c.landing_url);
    const h2 = await page.locator('article h2, .td-post-content h2, main h2, [data-lld-section]').evaluateAll((els) => els.filter((e) => e.tagName === 'H2').map((e) => e.textContent.trim()));
    expect(h2, `${c.tool_slug}: h2 sequence`).toEqual(HEADINGS);
  }
});

test('TC-FR1-04-AS-05', async ({ page }) => {
  for (const c of cards) {
    await page.goto(stack.url + c.landing_url);
    const how = page.locator('[data-lld-section=how]').locator('xpath=following-sibling::p[1]');
    const text = await how.innerText();
    expect(text, `${c.tool_slug}: engine named`).toMatch(ENGINE[c.tool_slug]);
    const version = await page.locator('[data-lld-deployed-version]').first().getAttribute('data-lld-deployed-version');
    expect(version, `${c.tool_slug}: 8-hex deployed version`).toMatch(/^[0-9a-f]{8}$/);
    // equals the manifest's live version (the served tool file is the repository's overlay in the local stack)
    const manifest = JSON.parse(readFileSync(resolve(ROOT, 'manifests', `${c.tool_slug}.json`), 'utf8'));
    const live = manifest.versions.find((v) => v.is_live);
    const file = readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', c.tool_slug === 'diabetes' ? 'diabetes-risk-tool.html' : 'protein-requirement-tool.html'));
    expect(createHash('sha256').update(file).digest('hex').slice(0, 8), 'overlay file = manifest live version').toBe(live.version_hash.slice(0, 8));
    if (!stack.external) expect(version, `${c.tool_slug}: deployed_version prefix equals the manifest`).toBe(live.version_hash.slice(0, 8));
  }
});

test('TC-FR1-04-AS-06', async ({ page }) => {
  await page.goto(hub());
  for (const c of cards) {
    const card = page.locator(`[data-tool-card][data-tool="${c.tool_slug}"]`);
    expect((await card.locator('[data-test=card-name]').innerText()).trim()).toBe(c.name);
    expect((await card.locator('[data-test=card-purpose]').innerText()).trim()).toBe(c.purpose);
    expect(c.purpose.length).toBeLessThanOrEqual(120);
    expect((await card.locator('[data-test=card-time]').innerText()).trim()).toBe(`About ${c.time_minutes} minutes`);
    expect(await card.locator('[data-test=card-inputs] li').allInnerTexts()).toEqual(c.inputs);
    expect((await card.locator('[data-test=card-eligibility]').innerText()).trim()).toBe(c.eligibility);
    expect((await card.locator('[data-test=status-label]').innerText()).trim()).toBe('Available now');
    expect((await card.locator('[data-test=cta]').innerText()).trim()).toBe(c.cta_label);
    expect(await card.locator('[data-test=card-related] a').count(), `${c.tool_slug}: one related article`).toBe(1);
  }
});
