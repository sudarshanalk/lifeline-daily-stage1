// Playwright helpers for the protein tool form (shared by FR1-07/08/09/13 e2e specs).
// Fills the form from a fixture's `inputs` object exactly as a visitor would (unit toggle, option buttons, number inputs).
const ETH_LABEL = { White: 'WhiteNA', 'White / not stated': 'WhiteNA', Indian: 'Indian', Pakistani: 'Pakistani', Bangladeshi: 'Bangladeshi', 'Other Asian': 'OtherAsian', Chinese: 'Chinese', 'Middle Eastern': 'MiddleEastern', 'Black Caribbean': 'BlackCaribbean', 'Black African': 'BlackAfrican', Other: 'Other' };

/** `scope` is a Page or FrameLocator. */
export async function fillProteinForm(scope, inputs) {
  const click = async (sel) => scope.locator(sel).click();
  if (inputs.units === 'imperial') {
    await click('#tImperial');
    if (inputs.heightFt != null) { await scope.locator('#hVal').fill(String(inputs.heightFt)); await scope.locator('#hVal2').fill(String(inputs.heightIn ?? 0)); }
    if (inputs.weightSt != null) { await scope.locator('#wVal').fill(String(inputs.weightSt)); await scope.locator('#wVal2').fill(String(inputs.weightLb ?? 0)); }
    else if (inputs.weightLb != null) { await scope.locator('#wUnit').selectOption('lb').catch(() => {}); await scope.locator('#wVal').fill(String(inputs.weightLb)); }
  } else {
    await click('#tMetric');
    if (inputs.heightM != null) { await scope.locator('#hUnit').selectOption('m'); await scope.locator('#hVal').fill(String(inputs.heightM)); }
    else if (inputs.heightCm != null) await scope.locator('#hVal').fill(String(inputs.heightCm));
    if (inputs.weightKg != null) await scope.locator('#wVal').fill(String(inputs.weightKg));
  }
  if (inputs.age != null) await scope.locator('#age').fill(String(inputs.age));
  if (inputs.sex) await click(`#sex button[data-val="${inputs.sex}"]`);
  if (inputs.preg && inputs.preg !== 'no') await click(`#preg button[data-val="${inputs.preg}"]`);
  if (inputs.preg === 'pregnant' && inputs.trimester) await click(`#trim button[data-val="${inputs.trimester}"]`);
  if (inputs.preg === 'breastfeeding' && inputs.babyAge) await click(`#baby button[data-val="${inputs.babyAge}"]`);
  if (inputs.ethnicity) await click(`#eth button[data-val="${ETH_LABEL[inputs.ethnicity] || inputs.ethnicity}"]`);
  if (inputs.activity) await click(`#act button[data-val="${inputs.activity}"]`);
  if (inputs.resistance != null) await click(`#res button[data-val="${inputs.resistance}"]`);
  if (inputs.goal) await click(`#goal button[data-val="${inputs.goal}"]`);
  if (inputs.diet) await click(`#diet button[data-val="${inputs.diet}"]`);
  if (inputs.meals != null) await scope.locator('#meals').selectOption(String(inputs.meals));
  if (inputs.kidney) await click(`#kidney button[data-val="${inputs.kidney}"]`);
  if (inputs.liver) await click(`#liver button[data-val="${inputs.liver}"]`);
  if (inputs.plan) await click(`#plan button[data-val="${inputs.plan}"]`);
}

export const submitProtein = (scope) => scope.locator('#form button.cta').click();
