// Live B1 contracts. Red until B1; no feature skips. Existing engine/legacy/barcode coverage is mapped in the README.
import { test, expect } from '@playwright/test';
import { fresh, api, rows, custom, logFood, openFood, addDialog, findFood, addThroughUi, acted, DAY, B } from './p36-contract-helpers.mjs';

test('TC-FR3-26-AS-01', async ({ page }) => {
  await fresh(page); await custom(page, 'Synthetic banana'); await openFood(page);
  const dialog = await addDialog(page);
  const started = Date.now();
  await dialog.getByRole('searchbox', { name: 'Search foods', exact: true }).fill('banana');
  const hit = dialog.locator('[data-lld-search-results] li').filter({ hasText: 'Synthetic banana' });
  await expect(hit).toBeVisible({ timeout: 1000 });
  expect(Date.now() - started).toBeLessThanOrEqual(1000);
  await expect(hit).toContainText(/kcal/);
  await expect(dialog.locator('[data-lld-search-results] li').first()).toContainText(/banana/i);
});
test('TC-FR3-26-AS-02', async ({ page }) => {
  await fresh(page); await openFood(page); const dialog = await addDialog(page);
  await dialog.getByRole('searchbox', { name: 'Search foods', exact: true }).fill('zzzxqnomatchp36');
  await expect(dialog.locator('[data-lld-search-results]')).toContainText(/no.*(match|food|result)/i);
  await dialog.getByRole('button', { name: /custom food/i }).click();
  await expect(dialog.getByLabel('Food name', { exact: true })).toBeVisible();
});
// Catalogue spelling aliases: existing tests/stage3/food-import tests exercise Food_Wp_Store.
// Custom-food SQL name search intentionally has no catalogue alias expansion.
test('TC-FR3-27-AS-01', async ({ page }) => {
  await fresh(page); await custom(page); await openFood(page);
  await addThroughUi(page, 'Synthetic cooked rice', 150);
  const lunch = page.locator('[data-lld-meal-items="lunch"]');
  await expect(lunch).toContainText('Synthetic cooked rice'); await expect(lunch).toContainText('150');
  await expect(lunch).toContainText('195 kcal');
  const saved = rows(await api(page, 'food-log'));
  expect(saved).toHaveLength(1); expect(Number(saved[0].grams)).toBe(150); expect(saved[0].meal).toBe('lunch');
});
test('TC-FR3-27-AS-02', async ({ page }) => {
  await fresh(page); const food = await custom(page); const entry = await logFood(page, food, { grams: 150 });
  await openFood(page); const row = page.locator(`[data-lld-item-id="${entry.id}"]`);
  await row.getByRole('button', { name: 'Edit', exact: true }).click();
  await row.locator('[data-lld-inline-edit] input').fill('200');
  const response = await acted(page, `food-log/${entry.id}`, () => row.locator('[data-lld-inline-edit] button[type="submit"]').click(), 'PATCH');
  expect(response.ok(), await response.text()).toBeTruthy();
  await expect(row).toContainText('260 kcal'); await expect(page.locator('[data-lld-food-summary]')).toContainText('260');
});
test('TC-FR3-27-AS-03', async ({ page }) => {
  await fresh(page); const entry = await logFood(page, await custom(page)); await openFood(page);
  const row = page.locator(`[data-lld-item-id="${entry.id}"]`);
  const response = await acted(page, `food-log/${entry.id}`, () => row.getByRole('button', { name: 'Remove', exact: true }).click(), 'DELETE');
  expect(response.ok()).toBeTruthy(); await expect(row).toHaveCount(0);
  expect(rows(await api(page, 'food-log'))).toEqual([]);
  await expect(page.locator('[data-lld-food-summary]')).toContainText('0 kcal');
});
test('TC-FR3-27-AS-04', async ({ page }) => {
  await fresh(page); const food = await custom(page);
  await logFood(page, food, { entry_date: '2026-11-06', meal: 'breakfast' }); await openFood(page);
  await page.locator('[data-lld-food-date-input]').fill('2026-11-06');
  await page.locator('[data-lld-food-date-input]').press('Tab');
  await expect(page.locator('[data-lld-meal-items="breakfast"]')).toContainText(food.name);
  await expect(page.locator('[data-lld-meal-items="lunch"]')).not.toContainText(food.name);
});
for (const [id, target, grams, expected] of [
  ['TC-FR3-29-AS-01', 2000, 100, 390], ['TC-FR3-29-AS-02', 2000, 100, 1610], ['TC-FR3-29-AS-03', 1200, 400, 360],
]) test(id, async ({ page }) => {
  await fresh(page); const food = await custom(page);
  await api(page, 'targets', 'POST', { valid_from: DAY, kcal: target, set_by: 'member' });
  for (const meal of ['breakfast', 'lunch', 'dinner']) await logFood(page, food, { meal, grams });
  await openFood(page); const summary = page.locator('[data-lld-food-summary]');
  const amount = summary.locator(id.endsWith('02') ? '.lld-food-left-note' : id.endsWith('03') ? '.lld-food-over-note' : '.lld-cal-total');
  expect((await amount.innerText()).replace(/[,\s]/g, '')).toContain(String(expected));
  if (id.endsWith('02')) await expect(summary).toContainText(/left/);
  if (id.endsWith('03')) { await expect(summary).toContainText(/over/i); await expect(summary).not.toContainText(/danger|failed|bad food/i); }
});
test('TC-FR3-30-AS-01', async ({ page }) => {
  await fresh(page); await openFood(page); const dialog = await addDialog(page);
  await dialog.getByRole('tab', { name: 'Custom food', exact: true }).click();
  const form = dialog.locator('[data-lld-custom-form]');
  await form.getByLabel('Food name', { exact: true }).fill('Synthetic homemade soup');
  await form.getByLabel('Serving size (g)', { exact: true }).fill('100');
  await form.getByLabel('Calories per serving (kcal)', { exact: true }).fill('80');
  const response = await acted(page, 'custom-foods', () => form.getByRole('button', { name: 'Save & add to meal', exact: true }).click());
  expect(response.ok(), await response.text()).toBeTruthy(); await expect(dialog).toBeHidden();
  const found = await findFood(page, 'Synthetic homemade soup'); await expect(found.hit).toBeVisible();
});
test('TC-FR3-30-AS-02', async ({ page }) => {
  await fresh(page); await custom(page, 'Private P36 soup');
  await fresh(page, { email: B }); await openFood(page); const dialog = await addDialog(page);
  await dialog.getByRole('searchbox', { name: 'Search foods', exact: true }).fill('Private P36 soup');
  await expect(dialog.locator('[data-lld-search-results]')).toContainText(/no.*(match|food|result)/i);
  expect(JSON.stringify(await api(page, 'food-search?q=Private%20P36%20soup'))).not.toContain('Private P36 soup');
});
test('TC-FR3-30-AS-03', async ({ page }) => {
  await fresh(page); const food = await custom(page); const entry = await logFood(page, food);
  await api(page, `custom-foods/${food.id}`, 'DELETE'); await openFood(page);
  await expect(page.locator(`[data-lld-item-id="${entry.id}"]`)).toContainText('130 kcal');
  expect(rows(await api(page, 'food-log'))).toHaveLength(1);
});
test('TC-FR3-31-AS-01', async ({ page }) => {
  await fresh(page); const old = await custom(page, 'Older food'); const recent = await custom(page, 'Most recent food');
  await logFood(page, old); await logFood(page, recent); await openFood(page);
  const dialog = await addDialog(page); await dialog.getByRole('tab', { name: 'Recent', exact: true }).click();
  await expect(dialog.locator('[data-lld-recent-results] li').first()).toContainText(recent.name);
});
test('TC-FR3-31-AS-02', async ({ page }) => {
  await fresh(page); const food = await custom(page); const entry = await logFood(page, food); await openFood(page);
  const toggle = page.locator(`[data-lld-item-id="${entry.id}"] [data-lld-fav-toggle]`);
  await toggle.click(); await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  const dialog = await addDialog(page, 'breakfast'); await dialog.getByRole('tab', { name: 'Favourites', exact: true }).click();
  const before = rows(await api(page, 'food-log')).length;
  await dialog.locator('[data-lld-favourites-results] li').filter({ hasText: food.name }).getByRole('button', { name: /add|log/i }).click();
  await expect.poll(async () => rows(await api(page, 'food-log')).length).toBe(before + 1);
});
for (const [id, whole] of [['TC-FR3-32-AS-01', false], ['TC-FR3-32-AS-02', true]]) test(id, async ({ page }) => {
  await fresh(page); const food = await custom(page);
  for (const meal of ['breakfast', 'lunch', 'dinner', 'snacks']) await logFood(page, food, { entry_date: '2026-11-06', meal });
  await openFood(page);
  const response = await acted(page, 'food-copy', () => page.locator(whole ? '[data-lld-action="copy-yesterday"]' : '[data-lld-copy-meal="breakfast"]').click());
  expect(response.ok(), await response.text()).toBeTruthy();
  const copied = rows(await api(page, 'food-log')).filter(r => r.entry_date === DAY);
  expect(copied).toHaveLength(whole ? 4 : 1);
  expect(copied.map(r => r.meal).sort()).toEqual(whole ? ['breakfast', 'dinner', 'lunch', 'snacks'] : ['breakfast']);
});
for (const id of ['TC-FR3-34-AS-01', 'TC-FR3-34-AS-02', 'TC-FR3-34-AS-03']) test(id, async ({ page }) => {
  await fresh(page); const food = await custom(page);
  await api(page, 'targets', 'POST', { valid_from: '2026-10-26', kcal: 1200, set_by: 'member' });
  for (let d = 0; d < 7; d += 1) { const day = new Date(Date.UTC(2026, 9, 26 + d)).toISOString().slice(0, 10); await logFood(page, food, { entry_date: day, grams: d === 6 ? 1000 : 100 }); }
  await openFood(page, '2026-11-01'); const week = await api(page, 'food-week?week_start=2026-10-26');
  const panel = page.locator('[data-lld-food-week-body]'); await expect(panel).toBeVisible();
  // API membership and approved-tip source are verified in food-recipes.test.mjs. This checks the actual rendered view.
  if (id.endsWith('03')) { expect(week.tip).toBeTruthy(); await expect(panel).toContainText(typeof week.tip === 'string' ? week.tip : week.tip.text); }
  else {
    const label = id.endsWith('01') ? /Average calories|Daily average/i : /Days on target/i;
    // Lead fix: the inner locator of a filter is relative to each candidate, so it must not start from the panel.
    const tile = panel.locator('.lld-macro-tile').filter({ has: page.getByText(label, { exact: true }) });
    await expect(tile).toHaveCount(1);
    await expect(tile).toContainText(id.endsWith('01') ? /297(?:[.,]1)?/ : /\b6\b/);
  }
});
test('TC-FR3-69-AS-01', async ({ page }) => {
  const member = await fresh(page);
  const food = await custom(page, 'Synthetic populated safety food');
  await logFood(page, food);
  await api(page, 'favourites', 'POST', { food_id: Number(food.id), food_kind: 'custom' });
  await api(page, 'recipes', 'POST', { name: 'Synthetic populated recipe', servings: 1,
    ingredients: [{ food_id: Number(food.id), food_kind: 'custom', grams: 100 }] });
  const changed = await page.request.post(`/wp-json/lld-members/v1/members/${member.member_id}/health`, {
    headers: { 'X-WP-Nonce': await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content') },
    data: { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: '["eating-disorder"]' },
  });
  expect(changed.ok()).toBeTruthy(); await openFood(page);
  const root = page.locator('[data-lld-food-root]');
  const assertSafe = async (scope) => {
    await expect(scope.locator('input[name="kcal"], input[name="portion_g"], input[name$="_g"], [data-lld-portion-grams]')).toHaveCount(0);
    await expect(scope).not.toContainText(/\b\d+(?:\.\d+)?\s*(?:kcal|calories|grams|g\b)|\b[PCF]\s*\d+(?:\.\d+)?g/i);
  };
  await expect(root).toContainText(food.name); await assertSafe(root);
  const dialog = await addDialog(page);
  for (const name of ['Search', 'Recent', 'Favourites', 'Custom food', 'My recipes']) {
    await dialog.getByRole('tab', { name, exact: true }).click();
    if (name === 'Search') { await dialog.getByRole('searchbox', { name: 'Search foods', exact: true }).fill(food.name); await expect(dialog.locator('[data-lld-search-results]')).toContainText(food.name); }
    if (name === 'Recent') await expect(dialog.locator('[data-lld-recent-results]')).toContainText(food.name);
    if (name === 'Favourites') await expect(dialog.locator('[data-lld-favourites-results]')).toContainText(food.name);
    if (name === 'My recipes') await expect(dialog.locator('[data-lld-modal-recipes]')).toContainText('Synthetic populated recipe');
    await assertSafe(dialog);
  }
});
test('TC-FR3-69-AS-03', async ({ page }) => {
  await fresh(page, { conditions: ['kidney'] });
  await api(page, 'targets', 'POST', { valid_from: DAY, kcal: 1800, protein_g: 77, set_by: 'member' });
  await openFood(page); await expect(page.locator('[data-lld-food-root]')).not.toContainText(/77\s*g/);
  await expect(page.locator('[data-lld-target-form] input[name="protein_g"]')).toHaveCount(0);
});

async function ingredientUi(page, food, grams = 100) {
  const form = page.locator('[data-lld-recipe-form]');
  await form.locator('[data-lld-recipe-ing-q]').fill(food.name);
  await form.locator('[data-lld-recipe-ing-g]').fill(String(grams));
  const hit = form.locator('[data-lld-recipe-ing-hits] li').filter({ hasText: food.name });
  await expect(hit).toHaveCount(1); await hit.locator('[data-lld-add-ing]').click();
}
async function ingredients(page) {
  const foods = [];
  for (const n of [1, 2, 3]) foods.push(await custom(page, `Synthetic ingredient ${n}`));
  return foods;
}
test('TC-FR3-35-AS-01', async ({ page }) => {
  await fresh(page); const foods = await ingredients(page); await openFood(page);
  const form = page.locator('[data-lld-recipe-form]');
  // A click that lands before the page's script has attached its handler opens nothing (seen once in WebKit): press
  // again while the form is still closed, and go on only when it is open.
  await expect(async () => {
    if (await form.isHidden()) await page.locator('[data-lld-toggle-recipe-form]').click();
    await expect(form).toBeVisible({ timeout: 1000 });
  }).toPass({ timeout: 15000 });
  await form.getByLabel('Recipe name', { exact: true }).fill('Synthetic three ingredient recipe');
  await form.getByLabel('Servings', { exact: true }).fill('4');
  for (const food of foods) await ingredientUi(page, food);
  const response = await acted(page, 'recipes', () => form.getByRole('button', { name: 'Save recipe', exact: true }).click());
  expect(response.ok(), await response.text()).toBeTruthy();
  const made = await response.json(); expect(Number(made.kcal_per_serving)).toBe(97.5);
  expect(Number(made.protein_per_serving)).toBeCloseTo(1.875, 2);
  await expect(page.locator('[data-lld-recipes-list]')).toContainText('Synthetic three ingredient recipe');
  await expect(page.locator('[data-lld-recipes-list]')).toContainText('98 kcal');
});
test('TC-FR3-35-AS-02', async ({ page }) => {
  await fresh(page); const foods = await ingredients(page);
  const made = await api(page, 'recipes', 'POST', { name: 'Synthetic serving recipe', servings: 4,
    ingredients: foods.map(food => ({ food_id: Number(food.id), food_kind: 'custom', grams: 100 })) });
  await openFood(page);
  const response = await acted(page, 'food-log', () => page.locator(`[data-lld-log-recipe="${made.id}"]`).first().click());
  expect(response.ok()).toBeTruthy(); const logged = await response.json();
  expect(Number(logged.grams)).toBe(1); expect(Number(logged.kcal)).toBe(97.5);
  await expect(page.locator(`[data-lld-item-id="${logged.id}"]`)).toContainText('Synthetic serving recipe');
});
test('TC-FR3-35-AS-03', async ({ page }) => {
  await fresh(page); const foods = await ingredients(page);
  const made = await api(page, 'recipes', 'POST', { name: 'Synthetic edited recipe', servings: 4,
    ingredients: foods.map(food => ({ food_id: Number(food.id), food_kind: 'custom', grams: 100 })) });
  await openFood(page); await page.locator(`[data-lld-edit-recipe="${made.id}"]`).click();
  await page.locator('[data-lld-rm-ing="0"]').click();
  await ingredientUi(page, foods[0], 200);
  const response = await acted(page, `recipes/${made.id}`, () => page.locator('[data-lld-recipe-submit]').click(), 'PATCH');
  expect(response.ok()).toBeTruthy(); const changed = await response.json();
  expect(Number(changed.kcal_per_serving)).toBe(130);
  await expect(page.locator('[data-lld-recipes-list]')).toContainText('130 kcal');
});
test('TC-FR3-63-AS-04', async ({ page }) => {
  await fresh(page, { access_state: 'Free' }); await openFood(page);
  const dialog = await addDialog(page);
  await dialog.getByRole('tab', { name: 'Custom food', exact: true }).click();
  const pane = dialog.locator('[data-lld-pane="custom"]');
  await expect(pane).toContainText(/Premium/);
  await expect(pane.getByRole('link', { name: /premium|upgrade/i })).toHaveAttribute('href', /\/pricing\//);
  await expect(pane.locator('[data-lld-custom-form]')).toHaveCount(0);
});
