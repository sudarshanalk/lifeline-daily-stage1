// P3-36 C1 (FR3-07): the account menu with Sign out on every member page and every My account → Admin page, live on
// the CI WordPress. The account hub's own menu checks (AS-01 to AS-03) stay in account-hub.spec.mjs.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password, emailFor } from '../../key-journeys/helpers.mjs';
import { asOwner } from '../../../stage2/e2e/helpers.mjs';

const MEMBER_PAGES = ['/members/home/', '/members/diary/', '/members/meals/', '/members/more/', '/members/help/', '/members/subscription/'];

// The account hub (Sign-in & security) is at test_accounts in CI (tests/ci/stage2-wp.sh): AS-06 uses a listed account.
const HUB = 'p3-api-a@test.lifelinedaily.invalid';

async function member(page, part) {
  const email = part === 'hub' ? HUB : emailFor(part);
  await createMember(page.request, { email, password, first_name: 'Alex', consents: ['terms'] });
  await signIn(page, email, password);
  return email;
}

async function openMenu(page) {
  const chip = page.locator('.lld-chip');
  await expect(chip).toHaveCount(1);
  await chip.click();
  const menu = page.locator('#lld-desktop-user-menu');
  await expect(menu).toBeVisible();
  return menu;
}

test.describe('P3-36 C1 account menu and sign out', () => {
  test('TC-FR3-07-AS-04 On every member page and every Admin page the chip opens the same menu, and Sign out there signs out', async ({ page }) => {
    test.slow(); // eight pages and two sign-ins in one test
    await page.setViewportSize({ width: 1280, height: 800 });
    await member(page, 'c1-menu');
    for (const path of MEMBER_PAGES) {
      await page.goto(path);
      const menu = await openMenu(page);
      await expect(menu.locator('[role="menuitem"]'), path).toHaveText(['Profile', 'My subscription', 'My data', 'Help', 'Sign out']);
      await expect(menu.getByRole('menuitem', { name: 'Profile' }), path).toHaveAttribute('href', /\/members\/settings\/\?section=profile$/);
      // the v2 header shows the chip in place of "My account"
      await expect(page.getByRole('banner').getByRole('link', { name: 'My account', exact: true }), path).toHaveCount(0);
      await page.keyboard.press('Escape');
    }
    // Sign out from a page other than Account.
    await page.goto('/members/diary/');
    const menu = await openMenu(page);
    await menu.getByRole('menuitem', { name: 'Sign out' }).click();
    await page.waitForURL(/\/members\/sign-in\/\?signed_out=1$/);
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/sign-in\//);

    // My account → Admin: the Owner's chip opens the menu there too, and Sign out signs the Owner out.
    await asOwner(page);
    for (const path of ['/members/admin/', '/members/admin/members/']) {
      await page.goto(path);
      const adminMenu = await openMenu(page);
      await expect(adminMenu.getByRole('menuitem', { name: 'Help' }), path).toBeVisible();
      await expect(adminMenu.getByRole('menuitem', { name: 'Sign out' }), path).toBeVisible();
      await page.keyboard.press('Escape');
    }
    await (await openMenu(page)).getByRole('menuitem', { name: 'Sign out' }).click();
    await page.waitForURL(/\/members\/sign-in\/\?signed_out=1$/);
    await page.goto('/members/admin/');
    await expect(page).not.toHaveURL(/\/members\/admin\/$/);
  });

  test('TC-FR3-07-AS-05 At 360 pixels wide, the menu in the header holds Sign out', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await member(page, 'c1-phone');
    await page.goto('/members/home/');
    const chip = page.locator('.lld-chip');
    await expect(chip).toBeVisible();
    const box = await chip.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);
    // the header's menu button: the v2 header's, or the plugin header's ☰
    const v2 = page.locator('#nav-mobile-btn');
    if (await v2.count()) {
      await v2.click();
    } else {
      await page.locator('details.lld-burger summary').click();
    }
    const out = page.getByRole('banner').getByRole('button', { name: 'Sign out', exact: true });
    await expect(out).toBeVisible();
    const target = await out.boundingBox();
    expect(target.height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await out.click();
    await page.waitForURL(/\/members\/sign-in\/\?signed_out=1$/);
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/sign-in\//);
  });

  test('TC-FR3-07-AS-06 Sign-in and security offers signing out of this device only, next to signing out everywhere', async ({ page, browser }) => {
    const email = await member(page, 'hub');
    const other = await browser.newContext();
    const otherPage = await other.newPage();
    await signIn(otherPage, email, password);
    await otherPage.goto('/members/home/');
    await expect(otherPage).toHaveURL(/\/members\/home\//);

    await page.goto('/members/settings/?section=security');
    const device = page.getByRole('button', { name: 'Sign out of this device', exact: true });
    const everywhere = page.getByRole('button', { name: 'Sign out everywhere', exact: true });
    await expect(device).toBeVisible();
    await expect(everywhere).toBeVisible();
    expect((await device.boundingBox()).y).toBeLessThan((await everywhere.boundingBox()).y);
    await device.click();
    await page.waitForURL(/\/members\/sign-in\/\?signed_out=1$/);
    await page.goto('/members/home/');
    await expect(page).toHaveURL(/\/members\/sign-in\//);
    // the other browser is still signed in
    await otherPage.goto('/members/home/');
    await expect(otherPage).toHaveURL(/\/members\/home\//);
    await other.close();
  });
});
