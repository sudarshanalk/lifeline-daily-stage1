// TC-UI1-02 Tool cards and landing pages — e2e on the local WordPress test stack (tests/lib/wp-stack.mjs) or, with LLD_WP_URL,
// a staging origin. AS-01 rendered card fields equal content/tool-cards.json; AS-02 each CTA opens the landing page whose
// iframe src ends with the tool's filename; AS-03 the status label is the status.json mapping (active → "Available now",
// withdrawn → "Temporarily unavailable" via `wp lld status set`, label_only → "Planned" from labels[]).
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const cards = JSON.parse(readFileSync(resolve(ROOT, 'content', 'tool-cards.json'), 'utf8')).cards;
const status = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'status.json'), 'utf8'));
const FILE = { protein: 'protein-requirement-tool.html', diabetes: 'diabetes-risk-tool.html' };
const LABEL = { active: 'Available now', withdrawn: 'Temporarily unavailable', label_only: 'Planned' };
const PAGE_IDS = { 'health-tools': 6107, protein: 6102, 'diabetes-risk-check': 6066 };
let stack; const original = {};
test.beforeAll(async () => {
  const a = available(); test.skip(!a.ok, a.reason);
  stack = await startWpStack();
  if (!stack.external) {
    for (const id of Object.values(PAGE_IDS)) original[id] = stack.wp('post', 'get', String(id), '--field=post_content').stdout;
    const r = stack.wp('lld', 'pages', 'apply'); if (r.status !== 0) throw new Error(r.stdout + r.stderr);
  }
});
test.afterAll(async () => {
  if (stack && !stack.external) for (const [id, content] of Object.entries(original)) stack.wp('post', 'update', id, `--post_content=${content}`);
  await stack?.close();
});
test.describe.configure({ mode: 'serial' });

test('TC-UI1-02-AS-01', async ({ page }) => {
  await page.goto(`${stack.url}/health-tools/`);
  for (const c of cards) {
    const card = page.locator(`[data-tool-card][data-tool="${c.tool_slug}"]`);
    await expect(card).toHaveCount(1);
    expect((await card.locator('[data-test=card-name]').innerText()).trim(), `${c.tool_slug}: name`).toBe(c.name);
    expect((await card.locator('[data-test=card-purpose]').innerText()).trim(), `${c.tool_slug}: purpose`).toBe(c.purpose);
    expect(c.purpose.length, `${c.tool_slug}: purpose ≤ 120 chars`).toBeLessThanOrEqual(120);
    expect((await card.locator('[data-test=card-time]').innerText()).trim(), `${c.tool_slug}: time`).toBe(`About ${c.time_minutes} minutes`);
    expect(await card.locator('[data-test=card-inputs] li').allInnerTexts(), `${c.tool_slug}: inputs`).toEqual(c.inputs);
    expect((await card.locator('[data-test=card-eligibility]').innerText()).trim(), `${c.tool_slug}: eligibility`).toBe(c.eligibility);
    expect((await card.locator('[data-test=status-label]').innerText()).trim(), `${c.tool_slug}: status label`).toBe(LABEL[status.tools[c.tool_slug].status]);
    expect((await card.locator('[data-test=cta]').innerText()).trim(), `${c.tool_slug}: CTA`).toBe(c.cta_label);
    const related = card.locator('[data-test=card-related] a');
    await expect(related, `${c.tool_slug}: related article`).toHaveCount(1);
    expect((await related.innerText()).trim().length).toBeGreaterThan(3);
  }
});

test('TC-UI1-02-AS-02', async ({ page }) => {
  for (const c of cards) {
    await page.goto(`${stack.url}/health-tools/`);
    await page.locator(`[data-tool-card][data-tool="${c.tool_slug}"] [data-test=cta]`).click();
    await expect(page).toHaveURL(new RegExp(c.landing_url.replace(/\//g, '\\/') + '$'));
    const src = await page.locator('iframe.lld-tool-frame').first().getAttribute('src');
    expect(src, `${c.tool_slug}: landing page embeds the right tool`).toMatch(new RegExp(`/tools/${FILE[c.tool_slug]}$`));
  }
});

test('TC-UI1-02-AS-03', async ({ page }) => {
  await page.goto(`${stack.url}/health-tools/`);
  for (const c of cards) expect((await page.locator(`[data-tool-card][data-tool="${c.tool_slug}"] [data-test=status-label]`).innerText()).trim()).toBe(LABEL.active);
  for (const l of status.labels.filter((l) => l.status === 'label_only')) {
    const chip = page.locator(`[data-tool-card][data-tool="${l.slug}"][data-status=label_only]`);
    await expect(chip).toHaveCount(1);
    expect((await chip.locator('[data-test=status-label]').innerText()).trim()).toBe(LABEL.label_only);
    expect(await chip.locator('a').count(), 'planned chip has no link').toBe(0);
  }
  test.skip(stack.external, 'withdrawn label needs the WP-CLI switch (local stack)');
  expect(stack.wp('lld', 'status', 'set', 'protein', 'withdrawn').status).toBe(0);
  try {
    await page.goto(`${stack.url}/health-tools/`);
    const card = page.locator('[data-tool-card][data-tool="protein"]');
    await expect(card).toHaveAttribute('data-status', 'withdrawn');
    expect((await card.locator('[data-test=status-label]').innerText()).trim()).toBe(LABEL.withdrawn);
    expect((await page.locator('[data-tool-card][data-tool="diabetes"] [data-test=status-label]').innerText()).trim(), 'the other tool unaffected').toBe(LABEL.active);
  } finally { expect(stack.wp('lld', 'status', 'set', 'protein', 'active', `--version=${status.tools.protein.approved_version}`).status).toBe(0); }
});
