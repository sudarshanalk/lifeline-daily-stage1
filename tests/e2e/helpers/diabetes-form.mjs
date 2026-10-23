// Playwright helper for the diabetes tool form (shared by FR1-10/11/12/13 e2e specs). Fills the form from a fixture's
// `inputs` object exactly as a visitor would: tap groups (data-tap/data-val), number inputs, checkboxes (data-fam/opt/sym).
const tap = (scope, group, val) => scope.locator(`[data-tap="${group}"] button.tap[data-val="${val}"]`).click();

/** `scope` is a Page or FrameLocator. */
export async function fillDiabetesForm(scope, i) {
  if (i.units === 'imperial') await scope.locator('#allImperial').click(); else await scope.locator('#allMetric').click().catch(() => {});
  if (i.sex) await tap(scope, 'sex', i.sex);
  if (i.ageBand != null) await tap(scope, 'ageBand', String(i.ageBand));
  if (i.heightCm != null) await scope.locator('#ht').fill(String(i.heightCm));
  if (i.weightKg != null) await scope.locator('#wt').fill(String(i.weightKg));
  if (i.waistCm != null) await scope.locator('#waist').fill(String(i.waistCm));
  if (i.ethn) await tap(scope, 'ethn', i.ethn);
  if (i.smoke) await tap(scope, 'smoke', i.smoke);
  if (i.activity) await tap(scope, 'activity', i.activity);
  for (const f of i.family || []) await scope.locator(`[data-fam="${f}"]`).check();
  // yes/no tap groups; gdm/pcos live in a women-only block that is hidden for Male, so only visible groups are tapped
  for (const k of ['htn', 'cvd', 'gdm', 'pcos']) if (i[k] === 1 || i[k] === 0) { const grp = scope.locator(`[data-tap="${k}"]`); if (await grp.isVisible()) await grp.locator(`button.tap[data-val="${i[k]}"]`).click(); }
  for (const k of ['learn', 'psy', 'ster', 'stat', 'apsy']) if (i[k] === 1) await scope.locator(`[data-opt="${k}"]`).check();
  if (i.hasSym) await tap(scope, 'hasSym', i.hasSym);
  for (const s of i.symptoms || []) await scope.locator(`[data-sym="${s}"]`).check();
  if (i.emergency) await scope.locator('#emergency').check();
}

export const submitDiabetes = (scope) => scope.locator('#submitBtn').click();
