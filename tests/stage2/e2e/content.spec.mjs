import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { approveTitle, asOwner, createMember, editBody, publishItem, signIn } from './helpers.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');

test.beforeEach(async ({}, testInfo) => {
  testInfo.setTimeout(180000);
});

function stamp() {
  return randomBytes(4).toString('hex');
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
  const email = `tm04-${stamp()}@example.test`;
  const mark = `CF-09-hidden-${stamp()}`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium' });
  await asOwner(page);
  const id = await publishItem(page, 'lld_activity', mark, `Unapproved body ${mark}`);
  await signIn(page, email, password);
  await page.goto('/members/library/');
  await expect(page.getByText(mark)).toHaveCount(0);
  await page.goto(`/members/library/${id}/`);
  await expect(page.getByRole('heading', { name: "This page isn't available" })).toBeVisible();
  await expect(page.getByText(`Unapproved body ${mark}`)).toHaveCount(0);
  await page.goto('/members/home/');
  await expect(page.getByText(mark)).toHaveCount(0);
  await page.goto('/members/data/');
  await expect(page.getByText(mark)).toHaveCount(0);
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
  const audit = await page.request.get('/wp-json/lld-members/v1/test/content-audit');
  expect(audit.ok()).toBeTruthy();
  const body = await audit.json();
  expect(body.problems).toEqual([]);
  const shown = body.shown.find((item) => item.id === id);
  expect(shown.approved_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(shown.fingerprint).not.toBe('');
  const pending = body.waiting.filter((item) => item.id === id);
  expect(pending.length).toBeGreaterThan(0);
  expect(pending.some((item) => item.fingerprint === shown.fingerprint)).toBeFalsy();
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
  await page.goto('/wp-admin/admin.php?page=lld-approvals');
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
  await publishItem(page, 'lld_activity', title, `This cures a cold and treats a headache. ${mark}`);
  await page.goto('/wp-admin/admin.php?page=lld-approvals');
  const row = page.locator('tr', { hasText: title });
  await expect(row.locator('.lld-wording-flags')).toContainText('cures');
  await expect(row.locator('.lld-wording-flags')).toContainText('treats');
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check');
  await row.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Review the flagged wording before approving.')).toBeVisible();
});
