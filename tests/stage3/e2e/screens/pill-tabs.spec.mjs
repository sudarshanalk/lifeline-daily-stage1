// P3-36 D2 (UI audit 3.2), live on the CI WordPress with the v2 member shell (the Stage 3 browser job loads lld-tools):
// the Account tabs and stand-alone actions render as pills, not bold underlined links, at 360 and 1280 px, in Day and
// Night. Measured from computed styles, so the check fails if lld-v2.css's layered !important rules win again:
// rounded, padded, no underline, at least 44 px high, and the selected tab's text at least 4.5:1 against its fill.
import { test, expect } from '@playwright/test';
import { createMember, signIn, password } from '../../key-journeys/helpers.mjs';

const A = 'p3-api-a@test.lifelinedaily.invalid';

/** Style facts for each element matched, measured in the page. */
async function measure(page, selector) {
  return page.locator(selector).evaluateAll((els) => {
    const lum = (rgb) => {
      const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const contrast = (a, b) => {
      const [x, y] = [lum(a), lum(b)];
      return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    };
    return els.map((el) => {
      const s = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      const solid = !/rgba\(0, 0, 0, 0\)|transparent/.test(s.backgroundColor);
      return {
        text: el.textContent.trim(),
        radius: parseFloat(s.borderTopLeftRadius),
        padding: parseFloat(s.paddingLeft),
        underline: s.textDecorationLine.includes('underline'),
        height: r.height,
        top: Math.round(r.top),
        border: parseFloat(s.borderTopWidth),
        contrast: solid ? contrast(s.color, s.backgroundColor) : null,
      };
    });
  });
}

function expectPill(item, what) {
  expect(item.radius, `${what}: rounded`).toBeGreaterThanOrEqual(20);
  expect(item.padding, `${what}: padded`).toBeGreaterThanOrEqual(12);
  expect(item.underline, `${what}: no underline`).toBe(false);
  expect(item.height, `${what}: 44 px target`).toBeGreaterThanOrEqual(44);
  expect(item.border, `${what}: outlined`).toBeGreaterThan(0);
}

test.describe.serial('P3-36 D2 pill tabs and buttons', () => {
  test.beforeAll(async ({ request }) => {
    await createMember(request, { email: A, password, first_name: 'Alex', health_consent: true, consents: ['terms'] });
  });

  for (const width of [360, 1280]) {
    for (const mode of ['day', 'night']) {
      test(`TC-NFR3-12-AS-02 Account tabs and actions are 44 px pills at ${width} px, ${mode}; the selected tab reads at 4.5:1`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.addInitScript((m) => { try { localStorage.setItem('lld_circadian', m); } catch (e) { /* storage off */ } }, mode);
        await signIn(page, A, password);
        await page.goto('/members/settings/?section=security');
        await expect(page.locator('body')).toHaveClass(/lld-members-v2-app/);
        if (mode === 'night') await expect(page.locator('body')).toHaveClass(/mode-night/);

        // The five Account tabs, the selected one filled and readable.
        const tabs = await measure(page, '.lld-account-nav .lld-account-tab');
        expect(tabs).toHaveLength(5);
        tabs.forEach((t) => expectPill(t, `tab ${t.text}`));
        const selected = await measure(page, '.lld-account-nav .lld-account-tab[aria-current="page"]');
        expect(selected).toHaveLength(1);
        expect(selected[0].contrast, 'selected tab contrast').toBeGreaterThanOrEqual(4.5);
        // Every unselected tab is readable too (no white on white).
        for (const t of await measure(page, '.lld-account-nav .lld-account-tab:not([aria-current])')) {
          if (t.contrast !== null) expect(t.contrast, `tab ${t.text} contrast`).toBeGreaterThanOrEqual(4.5);
        }
        // Phones: one row that scrolls sideways (C5), and the page itself never scrolls sideways.
        if (width === 360) {
          expect(new Set(tabs.map((t) => t.top)).size).toBe(1);
          expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
        }

        // Two sample actions: Change / Set a password on Sign-in & security, and Manage billing on Membership.
        const security = await measure(page, 'a.lld-account-link');
        expect(security.length).toBeGreaterThan(0);
        expectPill(security[0], `action ${security[0].text}`);
        await page.goto('/members/settings/?section=membership');
        const billing = await measure(page, 'a.lld-account-link');
        expect(billing.length).toBeGreaterThan(0);
        expectPill(billing[0], `action ${billing[0].text}`);
        if (billing[0].contrast !== null) expect(billing[0].contrast).toBeGreaterThanOrEqual(4.5);
      });
    }
  }

  test('TC-NFR3-12-AS-01 Show as table on Progress is a pill, the page never scrolls sideways at 360 px, and running text stays underlined', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 900 });
    await signIn(page, A, password);
    for (const path of ['/members/progress/', '/members/settings/?section=security', '/members/subscription/']) {
      await page.goto(path);
      expect(await page.evaluate(() => document.documentElement.scrollWidth), path).toBeLessThanOrEqual(360);
    }
    await page.goto('/members/progress/');
    const toggle = await measure(page, '[data-lld-table-toggle]');
    expect(toggle.length).toBeGreaterThan(0);
    expectPill(toggle[0], 'Show as table');
    // Links inside sentences are never turned into pills.
    const inText = await page.locator('.lld-main p').evaluateAll((ps) => ps.flatMap((p) => [...p.querySelectorAll(':scope > a:not(.lld-button)')]
      .filter((a) => !p.classList.contains('lld-pill-actions') && p.textContent.trim().length > a.textContent.trim().length + 3)
      .map((a) => parseFloat(getComputedStyle(a).borderTopLeftRadius))));
    for (const radius of inText) expect(radius, 'a link inside a sentence is not turned into a pill').toBeLessThan(20);
  });

  test('TC-NFR3-12-AS-02 the focused tab shows a 3 px ring inside the scrolling row at 360 px', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 900 });
    await signIn(page, A, password);
    await page.goto('/members/settings/');
    const tab = page.locator('#tab-security');
    await tab.focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
    const read = () => page.evaluate(() => {
      const el = document.activeElement;
      const s = getComputedStyle(el);
      return { id: el.id, width: parseFloat(s.outlineWidth), style: s.outlineStyle, offset: parseFloat(s.outlineOffset) };
    });
    // The v2 shell animates the ring in, so read it once the transition has settled.
    await expect.poll(async () => (await read()).width, { timeout: 3000 }).toBeGreaterThanOrEqual(3);
    const ring = await read();
    expect(ring.id).toBe('tab-security');
    expect(ring.style).toBe('solid');
    expect(ring.offset).toBeLessThan(0); // inside the pill, so the scrolling row cannot clip it
  });

  test('TC-FR3-06-AS-01 the tabs keep their ids, class and aria-current with the pill style', async ({ page }) => {
    await signIn(page, A, password);
    await page.goto('/members/settings/?section=privacy');
    for (const id of ['profile', 'security', 'notifications', 'privacy', 'membership']) {
      await expect(page.locator(`#tab-${id}.lld-account-tab`)).toHaveCount(1);
    }
    await expect(page.locator('#tab-privacy')).toHaveAttribute('aria-current', 'page');
  });
});
