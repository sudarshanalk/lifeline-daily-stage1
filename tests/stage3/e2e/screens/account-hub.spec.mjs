// Stage 3 screen tests for P3-30: account hub and desktop menu, live on the CI WordPress.
// The account_hub switch is at test_accounts in CI (tests/ci/stage2-wp.sh) and the member below is a listed test
// account, so ordinary Stage 2 members still get the Stage 2 settings screen.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password } from '../../key-journeys/helpers.mjs';

const A = 'p3-api-a@test.lifelinedaily.invalid';

async function hubMember(page, fields = {}) {
  await createMember(page.request, { email: A, password, first_name: 'Alex', ...fields });
  await signIn(page, A, password);
}

test.describe.serial('P3-30 account hub and desktop menu', () => {
  test('TC-FR3-06-AS-01 Open Account: the five sections are listed and each opens its own page', async ({ page }) => {
    await hubMember(page);
    await page.goto('/members/settings/');
    await expect(page.locator('.lld-stage3-account-hub')).toBeVisible();

    const nav = page.locator('nav.lld-account-nav');
    await expect(nav.locator('ul > li > a.lld-account-tab')).toHaveCount(5);
    await expect(nav.locator('[role="tablist"], [role="tab"]')).toHaveCount(0);
    await expect(page.locator('#tab-profile')).toHaveAttribute('aria-current', 'page');

    const sections = [
      ['security', 'Sign-in & security'],
      ['notifications', 'Notifications & reminders'],
      ['privacy', 'Privacy & consents'],
      ['membership', 'Membership'],
      ['profile', 'Profile'],
    ];
    for (const [id, heading] of sections) {
      await page.locator(`#tab-${id}`).click();
      await expect(page).toHaveURL(new RegExp(`section=${id}`));
      await expect(page.locator('.lld-account-content h2')).toHaveText(heading);
      await expect(page.locator(`#tab-${id}`)).toHaveAttribute('aria-current', 'page');
      await expect(page.locator('[aria-current="page"].lld-account-tab')).toHaveCount(1);
    }

    // No section shows a requirement or test ID to the member.
    for (const [id] of sections) {
      await page.goto(`/members/settings/?section=${id}`);
      await expect(page.locator('main')).not.toContainText(/\b(?:TC-)?N?FR\d-\d\d/);
    }

    // At 360 px the section strip is one row that scrolls sideways inside itself (P3-36 C5, member area build list):
    // every tab on the same line, the strip within the screen, and the page itself never scrolls sideways.
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto('/members/settings/');
    const strip = await page.locator('.lld-account-tabs').evaluate((el) => ({
      right: el.getBoundingClientRect().right,
      overflowX: getComputedStyle(el).overflowX,
      rows: new Set([...el.querySelectorAll('.lld-account-tab')].map((a) => Math.round(a.getBoundingClientRect().top))).size,
      page: document.documentElement.scrollWidth,
    }));
    expect(strip.rows).toBe(1);
    expect(strip.right).toBeLessThanOrEqual(360);
    expect(strip.overflowX).toBe('auto');
    expect(strip.page).toBeLessThanOrEqual(360);
  });

  test('TC-FR3-06-AS-02 Change the display name in Profile and press Save: only Profile is saved and a confirmation is shown', async ({ page }) => {
    await hubMember(page, { two_step: false });
    await page.goto('/members/settings/?section=profile');
    // The email is the editable Profile field; leaving it alone must leave it unchanged by the save.
    await expect(page.locator('#lld_email')).toBeEditable();
    const email = await page.locator('#lld_email').inputValue();
    expect(email).toContain('@');
    await page.locator('#lld_display').fill('Sam Updated');
    await page.getByRole('button', { name: 'Save profile' }).click();

    // Post-redirect-get: the save redirects, and the notice is shown from the query flag.
    await expect(page).toHaveURL(/\/members\/settings\/\?section=profile&saved=1$/);
    await expect(page.locator('.lld-notice-box')).toContainText('Profile saved.');
    await expect(page.locator('#lld_display')).toHaveValue('Sam Updated');
    await expect(page.locator('#lld_email')).toHaveValue(email);
    await expect(page.locator('#lld_email_display')).toHaveCount(0);
    // Only Profile was saved: two-step sign-in is still off.
    await page.goto('/members/settings/?section=security');
    await expect(page.locator('[data-lld-two-step]')).toHaveAttribute('data-lld-two-step', 'off');
  });

  test('TC-FR3-06-AS-03 Privacy and consents lists every consent the member has given, with the date, and each can be withdrawn', async ({ page }) => {
    await hubMember(page, { health_consent: true, consents: ['terms', 'health', 'marketing'] });
    await page.goto('/members/settings/?section=privacy');

    // All four consents Stage 2 records are listed, each with how to change it.
    await expect(page.locator('.lld-consent-item')).toHaveCount(4);
    expect(await page.locator('.lld-consent-item').evaluateAll((els) => els.map((el) => el.dataset.consentType))).toEqual(['terms', 'privacy', 'health', 'marketing']);
    await expect(page.locator('.lld-account-section .lld-muted').first()).not.toContainText('can be withdrawn');
    for (const type of ['terms', 'privacy']) {
      const item = page.locator(`.lld-consent-item[data-consent-type="${type}"]`);
      await expect(item).toContainText('Needed for your account');
      await expect(item.getByRole('link', { name: 'Delete my account' })).toHaveAttribute('href', /\/members\/delete\/$/);
    }
    const marketing = page.locator('.lld-consent-item[data-consent-type="marketing"]');
    await expect(marketing).toContainText('Marketing emails');
    await expect(marketing).toContainText(/Accepted on \d{4}-\d\d-\d\d/);
    await expect(marketing.getByRole('checkbox', { name: 'Marketing emails' })).toBeVisible();
    await expect(page.locator('a[href*="all=1"]')).toHaveCount(0);

    const health = page.locator('.lld-consent-item[data-consent-type="health"]');
    await expect(health).toContainText('Store my health information');
    await expect(health).toContainText(/Accepted on \d{4}-\d\d-\d\d/);
    await expect(page.locator('.lld-consent-item[data-consent-type="terms"]')).toContainText(/Accepted on \d{4}-\d\d-\d\d/);

    // Withdrawal goes through the Stage 2 screen, which shows what will be deleted before anything changes.
    const withdraw = health.getByRole('link', { name: 'Withdraw consent' });
    await expect(withdraw).toHaveAttribute('href', /\/members\/withdraw\/$/);
    await expect(page.locator('form input[name="lld_withdraw_health"]')).toHaveCount(0);
    await withdraw.click();
    await expect(page.getByRole('heading', { name: 'Withdraw health consent' })).toBeVisible();
    // Opening the screen withdraws nothing.
    await page.goto('/members/settings/?section=privacy');
    await expect(health).toContainText('Accepted on');

    // Download and delete links are 44 px targets.
    for (const name of ['Download my data', 'Delete my account']) {
      const box = await page.locator('.lld-account-links').getByRole('link', { name }).boundingBox();
      expect(box.height).toBeGreaterThanOrEqual(44);
      expect(box.width).toBeGreaterThanOrEqual(44);
    }
  });

  test('TC-FR3-06-AS-04 Open the old long settings address (?all=1) as a member: the matching section of Account opens instead', async ({ page }) => {
    await hubMember(page);
    await page.goto('/members/settings/?all=1');
    await expect(page).toHaveURL(/\/members\/settings\/\?section=profile$/);
    await expect(page.locator('.lld-stage3-account-hub')).toBeVisible();
    await expect(page.locator('#tab-profile')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: 'Profile and settings', exact: true })).toHaveCount(0);
    await page.goto('/members/settings/?all=1&section=security');
    await expect(page).toHaveURL(/section=security$/);
    await expect(page.locator('#tab-security')).toHaveAttribute('aria-current', 'page');
  });

  test('TC-FR3-07-AS-01 At 1280 pixels wide, click the name chip: a menu with the five links opens', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await hubMember(page);
    await page.goto('/members/settings/');
    await page.locator('.lld-chip').click();

    const menu = page.locator('#lld-desktop-user-menu');
    await expect(menu).toBeVisible();
    const items = menu.locator('[role="menuitem"]');
    await expect(items).toHaveText(['Profile', 'My subscription', 'My data', 'Help', 'Sign out']);
  });

  test('TC-FR3-07-AS-02 Each link goes to the right page, and Sign out signs the member out', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await hubMember(page);
    await page.goto('/members/settings/');
    await page.locator('.lld-chip').click();

    const menu = page.locator('#lld-desktop-user-menu');
    await expect(menu.getByRole('menuitem', { name: 'Profile' })).toHaveAttribute('href', /\/members\/settings\/\?section=profile$/);
    await expect(menu.getByRole('menuitem', { name: 'My subscription' })).toHaveAttribute('href', /section=membership$/);
    await expect(menu.getByRole('menuitem', { name: 'My data' })).toHaveAttribute('href', /section=privacy$/);
    await expect(menu.getByRole('menuitem', { name: 'Help' })).toHaveAttribute('href', /\/members\/help\/$/);
    // Sign out posts the hub's form to the Stage 2 sign-out (the More screen) with its nonce: never a link, never
    // a bare ?action=sign_out address and never WordPress's own logout.
    const out = menu.getByRole('menuitem', { name: 'Sign out' });
    expect(await out.evaluate((el) => el.tagName)).toBe('BUTTON');
    expect(await out.getAttribute('href')).toBeNull();
    const form = page.locator('form#lld-hub-sign-out');
    await expect(form).toHaveAttribute('method', 'post');
    await expect(form).toHaveAttribute('action', /\/members\/more\/$/);
    await expect(form.locator('input[name="lld_sign_out_nonce"]')).toHaveAttribute('value', /^[0-9a-f]+$/);
    await expect(page.locator('a[href*="action=sign_out"], a[href*="action=logout"]')).toHaveCount(0);

    await out.click();
    await page.waitForURL(/\/members\/sign-in\/\?signed_out=1$/);
    await page.goto('/members/settings/');
    await expect(page).toHaveURL(/\/members\/sign-in\//);
    await expect(page.locator('#lld_email')).toBeVisible();
  });

  test('TC-FR3-07-AS-03 The menu can be opened and used with the keyboard alone', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await hubMember(page);
    await page.goto('/members/settings/');
    const chip = page.locator('.lld-chip');
    await chip.focus();
    await page.keyboard.press('Enter');

    const menu = page.locator('#lld-desktop-user-menu');
    await expect(menu).toBeVisible();
    await expect(chip).toHaveAttribute('aria-expanded', 'true');
    const items = menu.locator('[role="menuitem"]');
    await expect(items.nth(0)).toBeFocused();

    await page.keyboard.press('ArrowDown');
    await expect(items.nth(1)).toBeFocused();
    // Keyboard focus is visibly marked.
    const outline = await items.nth(1).evaluate((el) => getComputedStyle(el).outlineStyle);
    expect(outline).not.toBe('none');

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(chip).toBeFocused();
  });
});
