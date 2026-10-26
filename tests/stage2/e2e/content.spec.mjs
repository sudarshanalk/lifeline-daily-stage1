import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { approveTitle, asOwner, createMember, editBody, openAdmin, publishItem, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');

function stamp() {
  return randomBytes(4).toString('hex');
}

async function fixture(request, kind, mark) {
  const res = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind, stamp: mark } });
  if (!res.ok()) throw new Error(`fixture ${kind} ${res.status()} ${await res.text()}`);
  return res.json();
}

test('TC-FR2-08-AS-03', async ({ page }) => {
  const email = `tm04-${stamp()}@example.test`;
  const mark = stamp();
  const title = `Owner edit ${mark}`;
  const first = `First approved wording ${mark}`;
  const next = `Owner approved the new wording ${mark}`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await asOwner(page);
  const id = await publishItem(page, 'lld_activity', title, first);
  await approveTitle(page, title);
  await expect(page.getByText('Approved. Members can now see this version.')).toBeVisible();
  await editBody(page, id, next);
  await approveTitle(page, title);
  await expect(page.getByText('Approved. Members can now see this version.')).toBeVisible();
  await signIn(page, email, password);
  await page.goto(`/members/library/${id}/`);
  await expect(page.getByText(next)).toBeVisible();
});

test('TC-FR2-08-AS-04', async ({ page }) => {
  test.setTimeout(300000);
  const email = `tm04-${stamp()}@example.test`;
  const mark = `CF-09-hidden-${stamp()}`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', consents: ['terms', 'health'], access_state: 'Premium' });
  await asOwner(page);
  const id = await publishItem(page, 'lld_activity', mark, `Unapproved body ${mark}`);
  const made = await fixture(page.request, 'unapproved', stamp());
  await signIn(page, email, password);
  const html = async (path) => (await page.request.get(path)).text();

  // Goals: an unapproved goal is never offered, and an approved goal keeps its approved name until a rename is approved.
  await page.goto('/members/goals/');
  await expect(page.locator('main')).not.toContainText(made.hidden);
  await expect(page.locator('main')).not.toContainText(made.waiting);
  await page.locator('label', { hasText: made.goal }).locator('input').check();
  await page.getByRole('button', { name: 'Save goals' }).click();
  await page.goto('/members/tracks/');
  const value = await page.locator('input[name="lld_track_nonce"]').first().inputValue();
  await page.request.post('/members/tracks/', { form: { lld_track_nonce: value, lld_track: made.track }, maxRedirects: 0 });

  // Every member surface, the plan and its swap, the meal plan and its swap, suggestions and downloads.
  const surfaces = ['/members/home/', '/members/suggestions/', '/members/plan/', '/members/meals/', '/members/meals/?pdf=1', '/members/workout/', '/members/challenge/', '/members/badges/', '/members/library/', '/members/data/', '/members/goals/', '/members/tracks/'];
  for (const path of surfaces) {
    const text = await html(path);
    expect(text, path).not.toContain(made.hidden);
    expect(text, path).not.toContain(mark);
  }
  const meals = await html('/members/meals/');
  const swap = (meals.match(/name="lld_swap_nonce" value="([^"]+)"/) || [])[1] || '';
  await page.request.post('/members/meals/', { form: { lld_swap_nonce: swap, lld_slot: '1-breakfast', lld_recipe: String(made.ids.lld_recipe) } });
  expect(await html('/members/meals/')).not.toContain(made.hidden);
  const plan = await html('/members/plan/');
  const planNonce = (plan.match(/name="lld_plan_nonce" value="([^"]+)"/) || [])[1] || '';
  if (planNonce) await page.request.post('/members/plan/', { form: { lld_plan_nonce: planNonce, lld_swap: '1' } });
  expect(await html('/members/plan/')).not.toContain(made.hidden);

  // Direct links and downloads of every unapproved item.
  // Unapproved items, an approved item moved to the bin, and an approved matrix row (owner data, not a library item).
  for (const item of [id, ...Object.values(made.ids), made.trashed, made.matrix]) {
    for (const path of [`/members/library/${item}/`, `/members/library/${item}/?download=1`]) {
      const res = await page.request.get(path);
      const text = await res.text();
      expect(res.status(), path).toBe(404);
      expect(text, path).toContain("This page isn't available");
      expect(text, path).not.toContain(made.hidden);
      expect(text, path).not.toContain(`Unapproved body ${mark}`);
    }
  }
  expect(await html(`/members/read/${made.article}/`)).not.toContain(made.hidden);
});

test('TC-FR2-08-AS-05', async ({ page }) => {
  const email = `tm04-${stamp()}@example.test`;
  const mark = stamp();
  const title = `CF-09 activity ${mark}`;
  const approved = `Approved activity text ${mark}`;
  const waiting = `Waiting edit text ${mark}`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await asOwner(page);
  const id = await publishItem(page, 'lld_activity', title, approved);
  await approveTitle(page, title);
  await editBody(page, id, waiting);
  await signIn(page, email, password);
  await page.goto(`/members/library/${id}/`);
  await expect(page.getByText(approved)).toBeVisible();
  await expect(page.getByText(waiting)).toHaveCount(0);
  await asOwner(page);
  await approveTitle(page, title);
  await signIn(page, email, password);
  await page.goto(`/members/library/${id}/`);
  await expect(page.getByText(waiting)).toBeVisible();
});

test('TC-NFR2-07-AS-01', async ({ page }) => {
  const mark = stamp();
  const title = `Current version ${mark}`;
  const approved = `Shown approved text ${mark}`;
  const waiting = `Unapproved edit ${mark}`;
  await asOwner(page);
  const id = await publishItem(page, 'lld_activity', title, approved);
  await approveTitle(page, title);
  await editBody(page, id, waiting);
  const broken = await fixture(page.request, 'tampered', mark);
  const linked = await fixture(page.request, 'linked', mark);
  const audit = await page.request.get('/wp-json/lld-members/v1/test/content-audit');
  expect(audit.ok()).toBeTruthy();
  const body = await audit.json();

  // A good record: shown, approved by someone on a date, and the waiting edit has a different fingerprint.
  expect(body.problems.filter((line) => line.startsWith(`${id}:`))).toEqual([]);
  const shown = body.shown.find((item) => item.id === id);
  expect(shown.approved_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(shown.approved_by).toBeGreaterThan(0);
  expect(shown.fingerprint).not.toBe('');
  const pending = body.waiting.filter((item) => item.id === id);
  expect(pending.length).toBeGreaterThan(0);
  expect(pending.some((item) => item.fingerprint === shown.fingerprint)).toBeFalsy();

  // Broken records: approved text changed afterwards, and an approval naming nobody. Both are reported and neither is shown.
  for (const item of [broken.changed, broken.unsigned, broken.rehashed]) {
    expect(body.problems.some((line) => line.startsWith(`${item.id}:`)), `${item.title} reported`).toBeTruthy();
    expect(body.shown.find((row) => row.id === item.id), `${item.title} not shown`).toBeUndefined();
  }
  const email = `tm04-${stamp()}@example.test`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await signIn(page, email, password);
  // B16: a plan's record holds the approved version of its recipe. An unapproved recipe edit leaves the plan alone;
  // approving it gives the plan a new version to approve, while members keep the approved plan.
  const [start, edited, relinked] = linked.versions;
  expect(edited).toBe(start);
  expect(relinked).toBe(start + 1);
  const plan = body.shown.find((row) => row.id === linked.meal);
  expect(plan, 'linked plan still shown').toBeTruthy();
  const planWaiting = body.waiting.filter((row) => row.id === linked.meal);
  expect(planWaiting.length).toBeGreaterThan(0);
  expect(planWaiting.some((row) => row.fingerprint === plan.fingerprint)).toBeFalsy();

  for (const item of [broken.changed, broken.unsigned, broken.rehashed]) {
    await page.goto(`/members/library/${item.id}/`);
    await expect(page.getByRole('heading', { name: "This page isn't available" })).toBeVisible();
  }
  await page.goto('/members/library/');
  await expect(page.locator('main')).not.toContainText(broken.changed.text);
  await expect(page.locator('main')).not.toContainText(broken.rehashed.text);
  await expect(page.locator('main')).not.toContainText(broken.unsigned.title);
});

test('TC-NFR2-07-AS-02', async ({ page }) => {
  const email = `tm04-${stamp()}@example.test`;
  const mark = stamp();
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await asOwner(page);
  const stress = await publishItem(
    page,
    'lld_lesson',
    `Stress and mood ${mark}`,
    `If you need urgent help, use https://findahelpline.com for local support. ${mark}`,
    'stress'
  );
  await approveTitle(page, `Stress and mood ${mark}`);
  const sleep = await publishItem(
    page,
    'lld_lesson',
    `Sleep note ${mark}`,
    `See a doctor about loud snoring with daytime sleepiness. ${mark}`,
    'sleep'
  );
  await approveTitle(page, `Sleep note ${mark}`);
  const sugar = await publishItem(
    page,
    'lld_lesson',
    `Blood sugar note ${mark}`,
    `Anyone on diabetes medication should talk to their doctor first. ${mark}`,
    'blood-sugar'
  );
  await approveTitle(page, `Blood sugar note ${mark}`);
  const blocked = `Stress missing link ${mark}`;
  await publishItem(page, 'lld_lesson', blocked, `A stress page with no urgent link. ${mark}`, 'stress');
  await openAdmin(page, '/wp-admin/admin.php?page=lld-approvals');
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check');
  const row = page.locator('tr', { hasText: blocked });
  await row.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Add an urgent-help link (findahelpline.com) before approving.')).toBeVisible();
  await signIn(page, email, password);
  await page.goto(`/members/library/${stress}/`);
  await expect(page.getByText('findahelpline.com')).toBeVisible();
  await page.goto(`/members/library/${sleep}/`);
  await expect(page.getByText('loud snoring with daytime sleepiness')).toBeVisible();
  await page.goto(`/members/library/${sugar}/`);
  await expect(page.getByText('diabetes medication')).toBeVisible();
  await page.goto('/members/library/');
  await expect(page.getByText(blocked)).toHaveCount(0);
});

test('TC-NFR2-07-AS-03', async ({ page }) => {
  const mark = stamp();
  const title = `Wording check ${mark}`;
  await asOwner(page);
  await publishItem(page, 'lld_activity', title, `This cures a cold and treats a headache. It reverses ageing and is clinically   proven. ${mark}`);
  await openAdmin(page, '/wp-admin/admin.php?page=lld-approvals');
  const row = page.locator('tr', { hasText: title });
  await expect(row.locator('.lld-wording-flags')).toContainText('cures');
  await expect(row.locator('.lld-wording-flags')).toContainText('treats');
  await expect(row.locator('.lld-wording-flags')).toContainText('reverses');
  await expect(row.locator('.lld-wording-flags')).toContainText('clinically proven');
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check');
  await row.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Review the flagged wording before approving.')).toBeVisible();
});
