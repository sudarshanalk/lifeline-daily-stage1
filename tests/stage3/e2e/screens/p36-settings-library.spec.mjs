// P3-36 X-3: ratified C2/A8 contracts; screen actions remain real, setup uses synthetic fixtures only.
import { test, expect } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { createMember, signIn, password, fixture } from '../../key-journeys/helpers.mjs';
import { asOwner, approveTitle, openAdmin, restNonce, submitAndWait } from '../../../stage2/e2e/helpers.mjs';

const EMAIL = 'p3-api-a@test.lifelinedaily.invalid';
async function fresh(page, health = false) {
  const reset = await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email: EMAIL } });
  expect(reset.ok(), await reset.text()).toBeTruthy();
  const m = await createMember(page.request, { email: EMAIL, password, first_name: 'Alex',
    access_state: 'Premium', health_consent: health, consents: health ? ['terms', 'health'] : ['terms'] });
  await signIn(page, EMAIL, password);
  return m;
}

test('TC-FR3-06-AS-04', async ({ page }) => {
  await fresh(page);
  for (const section of ['profile', 'security', 'notifications', 'privacy', 'membership']) {
    await page.goto(`/members/settings/?all=1&section=${section}`);
    await expect(page).toHaveURL(new RegExp(`/members/settings/\\?section=${section}$`));
    await expect(page.locator(`#tab-${section}`)).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('input[name="lld_settings_nonce"]')).toHaveCount(0);
    await expect(page.locator('.lld-account-content')).toBeVisible();
  }
  await page.goto('/members/settings/?all=1');
  await expect(page).toHaveURL(/\/members\/settings\/\?section=profile$/);
});

test('TC-FR3-06-AS-05', async ({ page }) => {
  const m = await fresh(page, true);
  await page.goto('/members/health/');
  const headers = { 'X-WP-Nonce': await restNonce(page) };
  const profile = await page.request.post(`/wp-json/lld-members/v1/members/${m.member_id}/health`, {
    headers, data: { kind: 'profile', conditions_answered: 'none', sex: 'female',
      height_cm: 177.8, weight_kg: 69.9, activity_level: 'some', confirmed: true },
  });
  expect(profile.ok(), await profile.text()).toBeTruthy();
  const goal = await page.request.post('/wp-json/lld-members/v1/me/goals', { headers,
    data: { role: 'main', goal_type: 'activity', measure: 'activity_minutes', unit: 'minutes',
      start_value: 0, target_value: 150, status: 'active' } });
  expect(goal.ok(), await goal.text()).toBeTruthy();
  await page.goto('/members/settings/?section=profile');
  await page.getByRole('checkbox', { name: /Show imperial units/i }).check();
  await submitAndWait(page, page.getByRole('button', { name: 'Save profile', exact: true }));
  await page.goto('/members/settings/?section=profile');
  await expect(page.getByRole('checkbox', { name: /Show imperial units/i })).toBeChecked();
  // B2/X-5 must additionally prove this preference on its new reading screen after the design is approved.
  test.info().annotations.push({ type: 'coverage', description: 'Existing health/check-in pages; reading UI extension belongs to B2/X-5' });
  for (const path of ['health', 'check-in']) {
    await page.goto(`/members/${path}/`);
    await expect(page).toHaveURL(new RegExp(`/members/${path}/$`));
    await expect(page.getByLabel(/weight.*pounds|weight.*\blb\b/i)).toBeVisible();
    if (path === 'health') {
      await expect(page.getByLabel('Height, feet', { exact: true })).toBeVisible();
      await expect(page.getByLabel('Height, inches', { exact: true })).toBeVisible();
    }
    // The page follows the account preference; it must not offer an independent units setting.
    await expect(page.getByRole('checkbox', { name: /imperial|metric|units/i })).toHaveCount(0);
    await expect(page.locator('select[name*="unit"]:visible, input[name="lld_imperial"]:visible')).toHaveCount(0);
  }
});

const search = (page) => page.getByRole('searchbox', { name: 'Search', exact: true });
const filters = (page) => page.getByRole('group', { name: 'Filter by type', exact: true });
const cards = (page) => page.locator('[data-lld-library-card]:visible');
const card = (page, title) => cards(page).filter({ has: page.getByText(title, { exact: true }) });

test('TC-FR3-111-AS-01', async ({ page }) => {
  const made = await fixture(page.request, 'days');
  await fresh(page);
  await page.goto('/members/library/');
  await expect(card(page, made.items[0].title)).toBeVisible();
  await expect(card(page, made.items[1].title)).toBeVisible();
  await search(page).fill(made.items[0].title.slice(0, -2));
  await expect(card(page, made.items[0].title)).toBeVisible();
  await expect(card(page, made.items[1].title)).toHaveCount(0);
  const titles = await cards(page).locator('.lld-library-card-title').allTextContents();
  expect(titles.length).toBeGreaterThan(0);
  for (const title of titles) expect(title.toLowerCase()).toContain((await search(page).inputValue()).toLowerCase());
  await search(page).fill('');
  await expect(card(page, made.items[1].title)).toBeVisible();
});

test('TC-FR3-111-AS-02', async ({ page }) => {
  const made = await fixture(page.request, 'days');
  const premium = await fixture(page.request, 'premium');
  await fresh(page);
  await page.goto('/members/library/');
  await expect(card(page, made.items[0].title)).toBeVisible();
  await filters(page).getByRole('button', { name: 'Recipes', exact: true }).click();
  await expect(card(page, premium.lentil)).toBeVisible();
  await expect(card(page, premium.challenge)).toHaveCount(0);
  for (const type of await cards(page).evaluateAll((els) => els.map((el) => el.dataset.lldType))) {
    expect(type).toBe('lld_recipe');
  }
  await expect(card(page, made.items[0].title)).toHaveCount(0);
  await filters(page).getByRole('button', { name: 'All', exact: true }).click();
  await expect(card(page, premium.lentil)).toBeVisible();
  await expect(card(page, premium.challenge)).toBeVisible();
  await expect(card(page, made.items[0].title)).toBeVisible();
  expect(new Set(await cards(page).evaluateAll((els) => els.map((el) => el.dataset.lldType))).size).toBeGreaterThan(1);
});

test('TC-FR3-111-AS-03', async ({ page }) => {
  const stamp = randomBytes(4).toString('hex');
  const first = await fixture(page.request, 'days', `p36-a-${stamp}`);
  const second = await fixture(page.request, 'days', `p36-b-${stamp}`);
  // Keep different track metadata, but give the second activity the same approved title through the owner's UI.
  await asOwner(page);
  await openAdmin(page, `/wp-admin/post.php?post=${second.items[0].id}&action=edit`);
  await page.locator('#title').fill(first.items[0].title);
  await submitAndWait(page, page.locator('#publish'));
  await approveTitle(page, first.items[0].title);
  await fresh(page);
  await page.goto('/members/library/');
  const merged = card(page, first.items[0].title);
  await expect(merged).toHaveCount(1);
  await expect(merged).toContainText(`Days p36-a-${stamp}`);
  await expect(merged).toContainText(`Days p36-b-${stamp}`);
});

test('TC-FR3-111-AS-04', async ({ page }) => {
  const draft = await fixture(page.request, 'draft');
  const approved = await fixture(page.request, 'days');
  // Trashing withdraws an approved item; it must disappear even though an approved snapshot exists.
  await asOwner(page);
  await openAdmin(page, `/wp-admin/post.php?post=${approved.items[0].id}&action=edit`);
  await submitAndWait(page, page.getByRole('link', { name: 'Move to Trash', exact: true }));
  await fresh(page);
  await page.goto('/members/library/');
  // Prove the control fixture is visible before testing exclusions.
  await expect(card(page, approved.items[1].title)).toBeVisible();
  for (const type of ['All', 'Recipes', 'Workout plans', 'Challenges', 'Lessons']) {
    await filters(page).getByRole('button', { name: type, exact: true }).click();
    for (const term of ['', draft.title, approved.items[0].title]) {
      await search(page).fill(term);
      await expect(page.locator('main').getByText(draft.title, { exact: true })).toHaveCount(0);
      await expect(page.locator('main').getByText(approved.items[0].title, { exact: true })).toHaveCount(0);
    }
  }
});

test('TC-FR3-111-AS-05', async ({ page }) => {
  const made = await fixture(page.request, 'days');
  const premium = await fixture(page.request, 'premium');
  await fresh(page);
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/members/library/');
  // Reach search through normal keyboard tabbing, rather than programmatically focusing the control.
  for (let i = 0; i < 35 && !(await search(page).evaluate((el) => el === document.activeElement)); i += 1) {
    await page.keyboard.press('Tab');
  }
  await expect(search(page)).toBeFocused();
  await page.keyboard.type(made.items[0].title);
  await expect(card(page, made.items[0].title)).toBeVisible();
  await expect(card(page, made.items[1].title)).toHaveCount(0);
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.press('Backspace');
  const recipe = filters(page).getByRole('button', { name: 'Recipes', exact: true });
  for (let i = 0; i < 12 && !(await recipe.evaluate((el) => el === document.activeElement)); i += 1) {
    await page.keyboard.press('Tab');
  }
  await expect(recipe).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(recipe).toHaveAttribute('aria-pressed', 'true');
  await expect(cards(page).first()).toBeVisible();
  await expect(card(page, made.items[0].title)).toHaveCount(0);
  const width = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: innerWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.client);
});
