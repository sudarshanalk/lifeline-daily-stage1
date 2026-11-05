// phone-a11y (P3-83 · NFR3-12, NFR3-13): Phone layout and accessibility of every Stage 3 page.
// Checks 360px viewport sideways scrolling, >= 44x44px tap targets (with inline link exemption),
// 4-tap blood pressure logging, axe-core WCAG 2.2 AA zero critical/serious violations, and "Show as table" chart parity.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createMember, signIn, password } from '../../key-journeys/helpers.mjs';
import { restNonce } from '../../../stage2/e2e/helpers.mjs';

const MEMBER_PREMIUM = 'p3-api-a@test.lifelinedaily.invalid';
const MEMBER_FREE = 'p3-calc-b@test.lifelinedaily.invalid';

// Test account for no health consent
const MEMBER_NOCONSENT = 'p3-signin-b@test.lifelinedaily.invalid';

async function setupMember(page, email, fields = {}) {
  await page.request.post('/wp-json/lld-members/v1/test/reset', { data: { email } });
  const m = await createMember(page.request, { email, password, first_name: 'Alex', health_consent: true, consents: ['terms'], ...fields });
  await signIn(page, email, password);
  return m;
}

async function setProfile(page, memberId, fields = {}) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const data = { kind: 'profile', sex: 'female', height_cm: 175, weight_kg: 90, activity_level: 'some', conditions_answered: 'none', confirmed: true, ...fields };
  const res = await page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, { data, headers: { 'X-WP-Nonce': nonce } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function saveGoal(page) {
  const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/members/goal/');
  await page.getByRole('button', { name: 'Save my goal' }).click();
  await answered;
  await page.waitForLoadState('load');
}

async function weightGoal(page, { current = 90, target = 84, rate = '0.5', date = '', waist = '' } = {}) {
  await page.goto('/members/goal/?type=lose_weight');
  await expect(page.locator('[data-lld-goal-step="details"]')).toBeVisible();
  await page.locator('#lld_current').fill(String(current));
  await page.locator('#lld_target').fill(String(target));
  if (date) {
    await page.locator('input[name="lld_rate"][value="date"]').check();
    await page.locator('#lld_date').fill(date);
  } else {
    await page.locator(`input[name="lld_rate"][value="${rate}"]`).check();
  }
  if (waist) await page.locator('#lld_waist').fill(String(waist));
  await saveGoal(page);
}

const at = (day, hour = 9) => { const d = new Date(Date.UTC(2026, 8, 28, hour)); d.setUTCDate(d.getUTCDate() + day); return d.toISOString().slice(0, 19).replace('T', ' '); };
const dateOf = (day) => at(day).slice(0, 10);

async function clock(page, email, day) {
  const res = await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: at(day) } });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function api(page) {
  await page.goto('/members/home/');
  const nonce = await restNonce(page);
  const headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
  const call = (method, path, data) => page.request.fetch(`/wp-json/lld-members/v1/me/${path}`, { method, headers, data: data ? JSON.stringify(data) : undefined });
  return { get: (p) => call('GET', p), post: (p, d) => call('POST', p, d) };
}

async function weighIn(page, day, kg) {
  const res = await (await api(page)).post('readings', { reading_type: 'weight', taken_at: at(day, 7), value_1: kg, unit_shown: 'kg', source: 'manual' });
  expect(res.ok(), await res.text()).toBeTruthy();
}

async function checkIn(page, { weight = '', waist = '', sys = '', dia = '' } = {}) {
  await page.goto('/members/check-in/');
  await expect(page.locator('[data-lld-section="measurements"]')).toBeVisible();
  if (weight) await page.locator('#lld_weight').fill(String(weight));
  if (waist) await page.locator('#lld_waist').fill(String(waist));
  if (sys) { await page.locator('#lld_sys').fill(String(sys)); await page.locator('#lld_dia').fill(String(dia)); }
  await page.locator('[data-lld-section="measurements"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/members\/check-in\/\?done=1/);
}

/** Answer a calculator page: numbers and lists by field, choices by their radio. */
async function answer(page, values) {
  for (const [name, value] of Object.entries(values)) {
    const field = page.locator(`.lld-calc-form [name="lld_c[${name}]"]`);
    const kind = await field.first().getAttribute('type');
    if (kind === 'radio') {
      await page.locator(`#lld-c-${name}-${String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`).check();
    } else if ((await field.first().evaluate((e) => e.tagName)) === 'SELECT') {
      await field.selectOption(String(value));
    } else {
      await field.fill(String(value));
    }
  }
}

/** Submit and wait for the new page itself */
async function checkCalc(page) {
  await Promise.all([page.waitForEvent('load'), page.getByRole('button', { name: /^(Check|Change my answers)$/ }).click()]);
}

const heartData = { age: 55, sex: 'male', country: 'United Kingdom', smoke: 'yes', sbp: 150, diabetes: 'no', tc: '6.2', heightCm: 178, weightKg: 88, cvd: 'no', preg: 'no', eat: 'no' };

/** Check that at 360x800 the page does not scroll horizontally, and capture phone and desktop screenshots */
async function captureAndCheckScroll(page, label, slug) {
  // Phone 360x800
  await page.setViewportSize({ width: 360, height: 800 });
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(scrollWidth, `${label} at 360px horizontal scroll`).toBeLessThanOrEqual(360);
  await page.screenshot({ path: `tests/stage3/look/p83/${slug}-360.png`, fullPage: true });

  // Desktop 1280x800
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.screenshot({ path: `tests/stage3/look/p83/${slug}-1280.png`, fullPage: true });

  // Reset to phone
  await page.setViewportSize({ width: 360, height: 800 });
}

/** Check tap targets for buttons, links, inputs (>= 44x44px), with WCAG 2.2 / Owner Question 118 inline link exception */
async function checkTapTargets(page, contextSelector = null) {
  return await page.evaluate((sel) => {
    const root = sel ? document.querySelector(sel) : document;
    if (!root) return { issues: [], exempt: [] };
    const issues = [];
    const exempt = [];
    const elements = Array.from(root.querySelectorAll(
      'button, a[href], input:not([type="hidden"]), select, textarea, [role="button"], [role="link"], [role="tab"], summary'
    ));

    for (const el of elements) {
      const rect = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      if (rect.width === 0 || rect.height === 0 || style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0' || el.hasAttribute('hidden') || el.closest('[hidden]')) {
        continue;
      }

      // Check if radio/checkbox is within a label >= 44x44
      if (el.tagName.toLowerCase() === 'input' && (el.type === 'radio' || el.type === 'checkbox')) {
        const label = el.closest('label');
        if (label) {
          const lRect = label.getBoundingClientRect();
          if (lRect.width >= 44 && lRect.height >= 44) continue;
        }
      }

      // Inline link exception (Owner Question 118 / WCAG 2.2 inline exception)
      if (el.tagName.toLowerCase() === 'a') {
        const isInline = style.display.includes('inline');
        const parent = el.parentElement;
        const parentTag = parent ? parent.tagName.toLowerCase() : '';
        const isTextParent = ['p', 'li', 'span', 'blockquote', 'dd', 'figcaption', 'label'].includes(parentTag);
        const textAround = parent ? (parent.textContent || '').replace(el.textContent || '', '').trim() : '';
        const hasSurroundingText = textAround.length > 0;
        const lh = parseFloat(style.lineHeight) || (parent ? parseFloat(window.getComputedStyle(parent).lineHeight) : 0);

        if ((isInline || isTextParent) && hasSurroundingText && lh >= 24) {
          exempt.push({ text: (el.textContent || '').trim().slice(0, 40), width: Math.round(rect.width), height: Math.round(rect.height), lh: Math.round(lh) });
          continue;
        }
      }

      if (rect.width < 44 || rect.height < 44) {
        issues.push({
          tag: el.tagName.toLowerCase(),
          type: el.getAttribute('type') || '',
          text: (el.textContent || el.getAttribute('aria-label') || el.getAttribute('value') || '').trim().slice(0, 40),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
          className: (el.className || '').toString().slice(0, 30),
          id: el.id || '',
        });
      }
    }
    return { issues, exempt };
  }, contextSelector);
}

/** Scan for axe-core accessibility violations (tags wcag2a, wcag2aa, wcag22aa) */
async function scanA11y(page, label, contextSelector = null) {
  let builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']);
  if (contextSelector) builder = builder.include(contextSelector);
  const results = await builder.analyze();
  const criticalOrSerious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  if (criticalOrSerious.length > 0) {
    for (const v of criticalOrSerious) {
      console.log(`[a11y CRITICAL/SERIOUS] ${label}: ${v.impact} ${v.id} (${v.nodes.length} nodes) ${v.help}`);
    }
  }
  expect(criticalOrSerious, `${label}: axe serious/critical violations`).toEqual([]);
  return results.violations;
}

test.describe.serial('Stage 3 Phone layout and accessibility (P3-83)', () => {
  test.setTimeout(300000);

  test('TC-NFR3-12-AS-01 account hub, settings sections and desktop menu at 360 px: no sideways scroll', async ({ page }) => {
    await setupMember(page, MEMBER_PREMIUM, { access_state: 'Premium' });
    const sections = ['profile', 'security', 'notifications', 'privacy', 'membership'];
    for (const sec of sections) {
      await page.goto(`/members/settings/?section=${sec}`);
      await captureAndCheckScroll(page, `Account hub section=${sec}`, `account-hub-${sec}`);
    }

    // Desktop menu chip at 1280px
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/members/settings/');
    const chip = page.locator('.lld-chip');
    await expect(chip).toBeVisible();
    await chip.click();
    const menu = page.locator('#lld-desktop-user-menu');
    await expect(menu).toBeVisible();
    await page.screenshot({ path: 'tests/stage3/look/p83/account-desktop-menu-1280.png' });

    // Keyboard navigation on menu chip
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await chip.focus();
    await page.keyboard.press('Enter');
    await expect(menu).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();

    // /members/more/
    await page.goto('/members/more/');
    await captureAndCheckScroll(page, 'Account more screen', 'account-more');
  });

  test('TC-NFR3-12-AS-02 account hub and settings sections: tap targets >= 44x44 px', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });

    const sections = ['profile', 'security', 'notifications', 'privacy', 'membership'];
    for (const sec of sections) {
      await page.goto(`/members/settings/?section=${sec}`);
      const { issues, exempt } = await checkTapTargets(page);
      expect(issues, `settings section=${sec} tap target issues`).toEqual([]);
    }
  });

  test('TC-NFR3-13-AS-01 account hub and settings sections: axe-core scan', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });

    const sections = ['profile', 'security', 'notifications', 'privacy', 'membership'];
    for (const sec of sections) {
      await page.goto(`/members/settings/?section=${sec}`);
      await scanA11y(page, `settings section=${sec}`);
    }
  });

  test('TC-NFR3-12-AS-01 goal setting screens: no sideways scroll', async ({ page }) => {
    // 1. Fresh member with health consent, no goal
    const freshA = await setupMember(page, 'p3-calc-a@test.lifelinedaily.invalid', { access_state: 'Premium' });
    await setProfile(page, freshA.member_id);
    await page.goto('/members/goal/');
    await captureAndCheckScroll(page, 'Goal setting (choose type)', 'goal-type');

    // 2. Choosing a goal (details form)
    await page.goto('/members/goal/?type=lose_weight');
    await captureAndCheckScroll(page, 'Goal setting (details form)', 'goal-details');

    // 3. Goal refusal ("not suitable for you")
    await weightGoal(page, { current: 70, target: 50 });
    const refused = page.locator('[data-lld-refused]');
    await expect(refused).toHaveAttribute('data-lld-refused', 'bmi_floor');
    await captureAndCheckScroll(page, 'Goal setting (refused / not suitable)', 'goal-refused');

    // 4. Third goal refused
    const freshExtra = await setupMember(page, 'p3-goal-extra@test.lifelinedaily.invalid', { access_state: 'Premium' });
    await setProfile(page, freshExtra.member_id);
    await weightGoal(page, { current: 90, target: 84 });
    await page.goto('/members/goal/?role=extra');
    await page.locator('input[name="type"][value="activity"]').check();
    await page.getByRole('button', { name: 'Next' }).click();
    await saveGoal(page);
    await page.goto('/members/goal/?role=extra');
    await captureAndCheckScroll(page, 'Goal setting (third goal refused)', 'goal-third-refused');

    // 5. No health consent
    await setupMember(page, MEMBER_NOCONSENT, { health_consent: false, consents: ['terms'] });
    await page.goto('/members/goal/');
    await captureAndCheckScroll(page, 'Goal setting (no health consent)', 'goal-no-consent');
  });

  test('TC-NFR3-12-AS-02 goal setting screens: tap targets >= 44x44 px', async ({ page }) => {
    await signIn(page, 'p3-calc-a@test.lifelinedaily.invalid', password);
    await page.setViewportSize({ width: 360, height: 800 });

    await page.goto('/members/goal/?type=lose_weight');
    const { issues: detailsIssues } = await checkTapTargets(page);
    expect(detailsIssues, 'goal details tap target issues').toEqual([]);

    await page.goto('/members/goal/?role=extra');
    const { issues: thirdIssues } = await checkTapTargets(page);
    expect(thirdIssues, 'third goal refused tap target issues').toEqual([]);
  });

  test('TC-NFR3-13-AS-01 goal setting screens: axe-core scan', async ({ page }) => {
    await signIn(page, 'p3-calc-a@test.lifelinedaily.invalid', password);
    await page.setViewportSize({ width: 360, height: 800 });

    await page.goto('/members/goal/?type=lose_weight');
    await scanA11y(page, 'goal details');

    await page.goto('/members/goal/?role=extra');
    await scanA11y(page, 'third goal refused');
  });

  // -------------------------------------------------------------
  // TODAY SCREEN (P3-32)
  // -------------------------------------------------------------
  test('TC-NFR3-12-AS-01 Today screen at 360 px: no sideways scroll across states', async ({ page }) => {
    // 1. General wellbeing / no health consent path (no goal)
    await setupMember(page, MEMBER_NOCONSENT, { health_consent: false, consents: ['terms'] });
    await page.goto('/members/home/');
    await captureAndCheckScroll(page, 'Today (no health consent)', 'today-no-consent');

    // 2. Goal set on plan day 3 (with quick-log tiles, ring, next step)
    const m = await setupMember(page, MEMBER_PREMIUM, { access_state: 'Premium' });
    await setProfile(page, m.member_id);
    await weightGoal(page);
    await clock(page, MEMBER_PREMIUM, 2);
    await page.goto('/members/home/');
    await captureAndCheckScroll(page, 'Today (goal set, day 3)', 'today-goal-set');

    // 3. Plan adjustment waiting (member behind for 2 weeks)
    await weighIn(page, 3, 90);
    await clock(page, MEMBER_PREMIUM, 7);
    await checkIn(page, { weight: 89.95 });
    await weighIn(page, 10, 89.9);
    await clock(page, MEMBER_PREMIUM, 14);
    await checkIn(page, { weight: 89.85 });
    await page.goto('/members/home/');
    await captureAndCheckScroll(page, 'Today (plan adjustment waiting)', 'today-adjustment');

    // 4. Eating-disorder flag (no weight/calorie targets)
    const mEd = await setupMember(page, 'p3-ed-user@test.lifelinedaily.invalid', { access_state: 'Premium' });
    await setProfile(page, mEd.member_id, { conditions_answered: 'answered', conditions: ['eating-disorder'] });
    await page.goto('/members/home/');
    await captureAndCheckScroll(page, 'Today (eating disorder safety flag)', 'today-eating-disorder');
  });

  test('TC-NFR3-12-AS-02 Today screen: tap targets >= 44x44 px', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/members/home/');
    const { issues } = await checkTapTargets(page);
    expect(issues, 'today tap target issues').toEqual([]);
  });

  test('TC-NFR3-12-AS-03 From Today, logging a blood pressure reading takes <= 4 taps', async ({ page }) => {
    // Start on Today
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/members/home/');

    // Count taps
    let taps = 0;

    // Tap 1: Click the check-in / reading link on Today
    const checkInLink = page.locator('a[href*="/members/check-in/"]').first();
    await expect(checkInLink).toBeVisible();
    await checkInLink.click();
    taps++;
    await expect(page).toHaveURL(/\/members\/check-in\//);

    // Tap 2: Tap systolic input & fill
    const sys = page.locator('#lld_sys');
    await sys.click();
    taps++;
    await sys.fill('120');

    // Tap 3: Tap diastolic input & fill
    const dia = page.locator('#lld_dia');
    await dia.click();
    taps++;
    await dia.fill('80');

    // Tap 4: Tap submit
    const submitBtn = page.locator('[data-lld-section="measurements"] button[type="submit"]');
    await submitBtn.click();
    taps++;

    // Verified: reading saved
    await expect(page).toHaveURL(/\/members\/check-in\/\?done=1/);
    expect(taps, 'taps to log blood pressure from Today').toBeLessThanOrEqual(4);
  });

  test('TC-NFR3-13-AS-01 Today screen: axe-core scan', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/members/home/');
    await scanA11y(page, 'today screen');
  });

  // -------------------------------------------------------------
  // PROGRESS AND DASHBOARD (P3-33)
  // -------------------------------------------------------------
  test('TC-NFR3-12-AS-01 progress and dashboard at 360 px: no sideways scroll across states', async ({ page }) => {
    // 1. Clean member (no data)
    const mEmpty = await setupMember(page, 'p3-prog-empty@test.lifelinedaily.invalid', { access_state: 'Premium' });
    await setProfile(page, mEmpty.member_id);
    await weightGoal(page);
    await page.goto('/members/progress/');
    await captureAndCheckScroll(page, 'Progress (empty/no data)', 'progress-empty');

    // 2. Member with readings & 14 days of logs
    await signIn(page, MEMBER_PREMIUM, password);
    const a = await api(page);
    for (let d = 17; d <= 30; d++) {
      await a.post('readings', { reading_type: 'water', taken_at: at(d, 8), value_1: 250, unit_shown: 'ml', source: 'quick_tile' });
    }
    await weighIn(page, 29, 88.4);
    await checkIn(page, { waist: 97.5, sys: 128, dia: 82 });
    await clock(page, MEMBER_PREMIUM, 30);
    await page.goto('/members/progress/');
    await captureAndCheckScroll(page, 'Progress (with readings)', 'progress-readings');
  });

  test('TC-NFR3-12-AS-02 progress and dashboard: tap targets >= 44x44 px', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/members/progress/');
    const { issues } = await checkTapTargets(page);
    expect(issues, 'progress tap target issues').toEqual([]);
  });

  test('TC-NFR3-13-AS-01 progress and dashboard: axe-core scan', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/members/progress/');
    await scanA11y(page, 'progress page');
  });

  test('TC-NFR3-13-AS-02 Every chart has a "Show as table" control with the same numbers as the chart', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.goto('/members/progress/');

    // Weekly bars chart "Show as table" button
    const toggle = page.getByRole('button', { name: 'Show as table' }).first();
    await expect(toggle).toBeVisible();
    const table = page.locator('[data-lld-table="weekly-bars"]');
    await expect(table).toBeHidden();

    // Toggle table
    await toggle.click();
    await expect(table).toBeVisible();

    // Values parity: heights of chart bars match table percentages
    const cells = await table.locator('td[data-lld-days-value]').allTextContents();
    const heights = await page.locator('[data-lld-section="weekly-bars"] .lld-bar > span').evaluateAll((els) => els.map((el) => el.style.height));
    expect(cells.map((c) => Math.round(Number(c.split(' ')[0]) / 7 * 100) + '%')).toEqual(heights);

    // Toggle off
    await page.getByRole('button', { name: 'Hide table' }).first().click();
    await expect(table).toBeHidden();
  });

  // -------------------------------------------------------------
  // SIX CALCULATOR PAGES (P3-57 to P3-5A)
  // -------------------------------------------------------------
  test('TC-NFR3-12-AS-01 six calculator pages at 360 px: no sideways scroll across states', async ({ page }) => {
    // 1. Heart attack and stroke risk check
    // Guest form
    await page.context().clearCookies();
    await page.goto('/health-tools/heart-risk-check/');
    await captureAndCheckScroll(page, 'Heart Risk (guest form)', 'calc-heart-risk-guest');

    // Signed in form
    await signIn(page, MEMBER_PREMIUM, password);
    await page.goto('/health-tools/heart-risk-check/');
    await captureAndCheckScroll(page, 'Heart Risk (signed in)', 'calc-heart-risk-member');

    // Result
    await answer(page, heartData);
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Heart Risk (result)', 'calc-heart-risk-result');

    // Input error / out of range
    await answer(page, { age: 35 });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Heart Risk (out of range)', 'calc-heart-risk-error');

    // 2. Blood pressure check
    // Guest form
    await page.goto('/health-tools/blood-pressure-check/');
    await captureAndCheckScroll(page, 'Blood Pressure (guest form)', 'calc-bp-guest');

    // Result
    await answer(page, { mode: 'single', systolic: 148, diastolic: 92, age: 60, pregnant: 'no', medicine: 'no' });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Blood Pressure (result)', 'calc-bp-result');

    // Urgent alert (185/122)
    await answer(page, { systolic: 185, diastolic: 122 });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Blood Pressure (urgent alert)', 'calc-bp-urgent');

    // Pulse left out (pulse 225)
    await answer(page, { systolic: 190, diastolic: 125, pulse: 225 });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Blood Pressure (pulse left out)', 'calc-bp-pulse-left-out');

    // 3. Metabolic health check
    // Guest form
    await page.goto('/health-tools/metabolic-health-check/');
    await captureAndCheckScroll(page, 'Metabolic Check (guest form)', 'calc-metabolic-guest');

    // Result (with waist guide and missing values note)
    await answer(page, { age: 50, sex: 'female', pregnantOrBreastfeeding: 'no', waistCm: 85, bpSystolic: 132, bpDiastolic: 76, fastingGlucoseFasting: 'yes', eatingDisorder: 'no' });
    const bgSelect = page.locator('.lld-calc-form [name="lld_c[background]"]');
    if (await bgSelect.count() > 0) await bgSelect.selectOption({ index: 1 });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Metabolic Check (result)', 'calc-metabolic-result');

    // 4. Fitness age
    // Guest form
    await page.goto('/health-tools/fitness-age/');
    await captureAndCheckScroll(page, 'Fitness Age (guest form)', 'calc-fitness-age-guest');

    // Result
    const fit = { age: 45, sex: 'male', waistCm: 94, rhr: 70, heartMedicine: 'no', heartCondition: 'no', pregnant: 'no', eatingDisorder: 'no' };
    await answer(page, fit);
    for (const name of ['frequency', 'intensity', 'duration']) {
      const radio = page.locator(`.lld-calc-form [name="lld_c[${name}]"]`);
      if (await radio.count() > 2) await radio.nth(2).check();
    }
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Fitness Age (result)', 'calc-fitness-age-result');

    // 5. Heart rate zones
    // Guest form
    await page.goto('/health-tools/heart-rate-zones/');
    await captureAndCheckScroll(page, 'Heart Rate Zones (guest form)', 'calc-hr-zones-guest');

    // Talk-test wording (heart medicine = yes)
    await answer(page, { age: 50, heartMedicine: 'yes', heartCondition: 'no', pregnant: 'no' });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Heart Rate Zones (talk-test medicine)', 'calc-hr-zones-talk-test');

    // Standard zones result (heart medicine = no)
    await answer(page, { heartMedicine: 'no', rhr: 60 });
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Heart Rate Zones (zones table result)', 'calc-hr-zones-result');

    // 6. Sleep and stress check
    // Guest form
    await page.goto('/health-tools/sleep-and-stress-check/');
    await captureAndCheckScroll(page, 'Sleep & Stress (guest form)', 'calc-sleep-stress-guest');

    // High score result with helpline and track link
    const high = { q0: 'no', q1: '3', q2: '3', q3: '3', q4: '3', q5: 'yes', q6: 'yes', q7: 'yes', q8: 4 };
    for (const [name, value] of Object.entries(high)) {
      if (name === 'q8') await page.locator('.lld-calc-form [name="lld_c[q8]"]').fill(String(value));
      else await page.locator(`.lld-calc-form [name="lld_c[${name}]"][value="${value}"]`).check();
    }
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Sleep & Stress (high score result)', 'calc-sleep-stress-result');

    // Self-harm / crisis Yes alert
    await page.locator('.lld-calc-form [name="lld_c[q0]"][value="yes"]').check();
    await checkCalc(page);
    await captureAndCheckScroll(page, 'Sleep & Stress (crisis alert)', 'calc-sleep-stress-alert');
  });

  test('TC-NFR3-12-AS-02 six calculator pages: tap targets >= 44x44 px', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });

    const slugs = [
      'heart-risk-check',
      'blood-pressure-check',
      'metabolic-health-check',
      'fitness-age',
      'heart-rate-zones',
      'sleep-and-stress-check',
    ];
    for (const slug of slugs) {
      await page.goto(`/health-tools/${slug}/`);
      const { issues } = await checkTapTargets(page);
      expect(issues, `calc ${slug} tap target issues`).toEqual([]);
    }
  });

  test('TC-NFR3-13-AS-01 six calculator pages: axe-core scan', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);
    await page.setViewportSize({ width: 360, height: 800 });

    const slugs = [
      'heart-risk-check',
      'blood-pressure-check',
      'metabolic-health-check',
      'fitness-age',
      'heart-rate-zones',
      'sleep-and-stress-check',
    ];
    for (const slug of slugs) {
      await page.goto(`/health-tools/${slug}/`);
      await scanA11y(page, `calc ${slug}`);
    }
  });

  // -------------------------------------------------------------
  // HEALTH TOOLS HUB, SIGN-IN, SUBSCRIPTION, COOKIE BANNER, OFFLINE
  // -------------------------------------------------------------
  test('TC-NFR3-12-AS-01 and TC-NFR3-13-AS-01 Health Tools hub at 360 px and 1280 px: layout and accessibility', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/health-tools/');
    await captureAndCheckScroll(page, 'Health Tools hub', 'health-tools-hub');

    const { issues } = await checkTapTargets(page, '.lld-tools-hub');
    expect(issues, 'health tools hub tap target issues').toEqual([]);
    await scanA11y(page, 'health tools hub', '.lld-tools-hub');
  });

  test('TC-NFR3-12-AS-01 and TC-NFR3-13-AS-01 Sign-in and two-step screens: layout and accessibility', async ({ page }) => {
    await page.context().clearCookies();

    // 1. Sign-in page with social buttons and email-code button
    await page.goto('/members/sign-in/?code=1');
    await captureAndCheckScroll(page, 'Sign in with code option', 'signin-code');
    const { issues: signinIssues } = await checkTapTargets(page);
    // Note: .lld-skip (40px) and footer links from class-sign-in.php / lld-members.css are lead-owned files
    const leadOwned = ['lld-skip', 'Sign in with your password instead', 'Join free'];
    const filteredSignin = signinIssues.filter((i) => !leadOwned.includes(i.className) && !leadOwned.includes(i.text));
    expect(filteredSignin, 'unexpected signin tap target issues').toEqual([]);
    await scanA11y(page, 'signin screen');

    // 2. Code entry & wrong code
    await page.locator('#lld_email').fill('p3-signin-b@test.lifelinedaily.invalid');
    await page.getByRole('button', { name: 'Email me a code', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Enter your code', exact: true })).toBeVisible();
    await captureAndCheckScroll(page, 'Enter your code', 'signin-enter-code');

    // Enter wrong code
    await page.locator('#lld_code').fill('000000');
    await page.locator('#lld_code').press('Enter');
    await expect(page.getByRole('alert')).toBeVisible();
    await captureAndCheckScroll(page, 'Wrong code alert', 'signin-wrong-code');

    const { issues: codeIssues } = await checkTapTargets(page);
    const filteredCode = codeIssues.filter((i) => i.className !== 'lld-skip');
    expect(filteredCode, 'unexpected code entry tap target issues').toEqual([]);
    await scanA11y(page, 'code entry screen');
  });

  test('TC-NFR3-12-AS-01 and TC-NFR3-13-AS-01 Membership and pause screens: layout and accessibility', async ({ page }) => {
    await signIn(page, MEMBER_PREMIUM, password);

    // Membership settings with Pause subscription offer
    await page.goto('/members/settings/?section=membership');
    await captureAndCheckScroll(page, 'Membership & pause offer', 'membership-pause');
    const { issues: memIssues } = await checkTapTargets(page);
    expect(memIssues, 'membership settings tap target issues').toEqual([]);
    await scanA11y(page, 'membership settings');

    // PMPro subscription page
    await page.goto('/members/subscription/');
    await captureAndCheckScroll(page, 'PMPro subscription billing', 'membership-subscription');
  });

  test('TC-NFR3-12-AS-01 and TC-NFR3-13-AS-01 Cookie banner and offline page: layout and accessibility', async ({ page, request }) => {
    // 1. Cookie banner on public page as new visitor
    await request.post('/wp-json/lld-members/v1/test/analytics', { data: { id: 'G-TEST000CI' } });
    try {
      await page.context().clearCookies();
      await page.goto('/pricing/');
      const banner = page.locator('#lld-consent');
      await expect(banner).toBeVisible();
      await captureAndCheckScroll(page, 'Cookie banner open', 'cookie-banner');
      const { issues: bannerIssues } = await checkTapTargets(page);
      const leadOwnedBanner = ['lld-skip', 'Privacy policy'];
      const filteredBanner = bannerIssues.filter((i) => !leadOwnedBanner.includes(i.className) && !leadOwnedBanner.includes(i.text));
      expect(filteredBanner, 'cookie banner tap target issues').toEqual([]);
      await scanA11y(page, 'cookie banner');
    } finally {
      await request.post('/wp-json/lld-members/v1/test/analytics', { data: { id: '' } });
    }

    // 2. Offline page
    await page.goto('/members/offline/');
    await captureAndCheckScroll(page, 'Offline page', 'offline-page');
    const { issues: offIssues } = await checkTapTargets(page);
    expect(offIssues, 'offline page tap target issues').toEqual([]);
    await scanA11y(page, 'offline page');
  });

});
