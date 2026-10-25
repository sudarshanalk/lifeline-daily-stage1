import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, publishItem, signIn } from './helpers.mjs';
import { matrixRows } from '../tools/safety-matrix.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';

function stamp() {
  return randomBytes(4).toString('hex');
}

function emailFor(label) {
  return `${label}-${stamp()}@test.lifelinedaily.invalid`;
}

async function fixture(request, kind, mark, extra = {}) {
  const res = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind, stamp: mark, ...extra } });
  if (!res.ok()) throw new Error(`fixture ${kind} ${res.status()} ${await res.text()}`);
  return res.json();
}

async function nonce(page) {
  return page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
}

async function saveHealth(page, memberId, data) {
  await page.goto('/members/home/');
  const res = await page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, {
    data,
    headers: { 'X-WP-Nonce': await nonce(page) },
  });
  expect(res.status(), JSON.stringify(data)).toBe(200);
}

async function chooseGoal(page, title) {
  await page.goto('/members/goals/');
  await page.locator('label', { hasText: title }).locator('input').check();
  await page.getByRole('button', { name: 'Save goals' }).click();
  await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible();
}

async function mailBodies(subject) {
  const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
  const found = (list.messages || []).filter((message) => JSON.stringify(message).includes(subject));
  const bodies = [];
  for (const message of found) {
    const id = message.ID || message.Id || message.id;
    const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
    bodies.push(`${full.Subject || ''}\n${full.Text || ''}\n${JSON.stringify(full.To || full.To || '')}`);
  }
  return bodies;
}

async function countTo(address, subject) {
  const bodies = await mailBodies(subject);
  return bodies.filter((body) => body.includes(address)).length;
}

test('TC-FR2-05-AS-01', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('goals');
  const made = await fixture(page.request, 'chooser', mark);
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/goals/');
  const boxes = page.locator('[data-lld-goal]');
  await expect(boxes).toHaveCount(await boxes.count());
  expect(await boxes.count()).toBeGreaterThanOrEqual(4);
  await boxes.nth(0).check();
  await boxes.nth(1).check();
  await boxes.nth(2).check();
  await expect(boxes.nth(3)).toBeDisabled();
  await boxes.nth(3).evaluate((box) => { box.disabled = false; box.checked = true; });
  await page.getByRole('button', { name: 'Save goals' }).click();
  await expect(page.getByText('You can choose up to 3 goals')).toBeVisible();
  expect(made.visible.length).toBeGreaterThanOrEqual(4);
});

test('TC-FR2-05-AS-02', async ({ page }) => {
  const email = emailFor('skip');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/goals/');
  await page.getByRole('button', { name: 'Skip for now' }).click();
  await expect(page.getByRole('heading', { name: 'Today', exact: true })).toBeVisible();
  await expect(page.getByText('You are on the general wellbeing path.')).toBeVisible();
});

test('TC-FR2-05-AS-03', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('shown');
  const made = await fixture(page.request, 'chooser', mark);
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/goals/');
  await expect(page.getByText(made.hidden)).toHaveCount(0);
  await expect(page.getByText(made.track)).toBeVisible();
  await expect(page.getByText(made.visible[0])).toBeVisible();
});

test('TC-FR2-05-AS-05', async ({ page }) => {
  const allowed = emailFor('else-yes');
  const refused = emailFor('else-no');
  const phrase = `quiet walks ${stamp()}`;
  const blocked = `blocked-else-${stamp()}`;
  const yes = await createMember(page.request, { email: allowed, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, allowed, password);
  await page.goto('/members/goals/');
  await page.locator('#lld_else').fill(phrase);
  await page.getByRole('button', { name: 'Save goals' }).click();
  const journey = await page.request.get(`/wp-json/lld-members/v1/members/${yes.member_id}/journey`, {
    headers: { 'X-WP-Nonce': await nonce(page) },
  });
  expect((await journey.json()).something_else).toBe(phrase);

  const created = await createMember(page.request, { email: refused, password, first_name: 'No', consents: ['terms'] });
  await signIn(page, refused, password);
  await page.goto('/members/goals/');
  await expect(page.locator('#lld_else')).toHaveCount(0);
  await page.goto('/members/home/');
  const direct = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    data: { kind: 'something_else', text: blocked },
    headers: { 'X-WP-Nonce': await nonce(page) },
  });
  expect(direct.status()).toBe(403);
  const found = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: blocked } });
  expect((await found.json()).hits).toEqual([]);
});

test('TC-FR2-25-AS-01', async ({ page }) => {
  const email = emailFor('profile');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/health/');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.locator('#lld_sex').selectOption('female');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.reload();
  await expect(page.locator('#lld_sex')).toHaveValue('female');
});

test('TC-FR2-25-AS-02', async ({ page }) => {
  const email = emailFor('check');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/health/');
  await page.locator('#lld_height').fill('300');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Please check this value')).toBeVisible();
  await expect(page.getByText('Stored height: 300.0 cm')).toHaveCount(0);
  await page.locator('#lld_weight').fill('5');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Please check this value')).toBeVisible();
  await expect(page.getByText('Stored weight: 5.0 kg')).toHaveCount(0);
});

test('TC-FR2-25-AS-03', async ({ page }) => {
  const email = emailFor('units');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/health/');
  await page.getByRole('checkbox', { name: 'Use imperial units' }).check();
  await page.locator('#lld_feet').fill('5');
  await page.locator('#lld_inches').fill('10');
  await page.locator('#lld_pounds').fill('154');
  await page.locator('#lld_waist_in').fill('32');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Stored height: 177.8 cm')).toBeVisible();
  await expect(page.getByText('Stored weight: 69.9 kg')).toBeVisible();
  await expect(page.getByText('Stored waist: 81.3 cm')).toBeVisible();
});

test('TC-FR2-25-AS-04', async ({ page }) => {
  const email = emailFor('nobody');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/health/');
  await expect(page.getByText('BMI')).toHaveCount(0);
  await expect(page.getByText('Waist-to-height')).toHaveCount(0);
  await page.goto('/members/home/');
  await expect(page.getByText('BMI')).toHaveCount(0);
  await expect(page.getByText('Waist-to-height')).toHaveCount(0);
  await page.goto('/members/check-in/');
  await expect(page.locator('#lld_height')).toBeVisible();
  await page.locator('#lld_height').fill('170');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Height is saved.')).toBeVisible();
  await page.goto('/members/check-in/');
  await expect(page.locator('#lld_height')).toHaveCount(0);
});

test('TC-FR2-25-AS-05', async ({ page }) => {
  test.setTimeout(300000);
  const mark = stamp();
  const email = emailFor('matrix');
  const made = await fixture(page.request, 'safety', mark);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  const surfaces = ['/members/home/', '/members/plan/', '/members/meals/', '/members/workout/', '/members/challenge/', '/members/badges/', '/members/suggestions/', '/members/library/'];
  for (const row of matrixRows) {
    if (row.profile) {
      await saveHealth(page, created.member_id, { kind: 'profile', confirmed: true, ...row.profile, conditions: JSON.stringify(row.profile.conditions || '') });
    }
    for (const tag of row.hidden) {
      const token = `${tag} ${mark}`;
      const id = made.tags[tag].lld_activity.id;
      for (const path of surfaces) {
        await page.goto(path);
        await expect(page.locator('main')).not.toContainText(token);
      }
      for (const path of [`/members/library/${id}/`, `/members/library/${id}/?swap=1`, `/members/library/${id}/?download=1`]) {
        await page.goto(path);
        await expect(page.getByRole('heading', { name: 'Not suitable for you' })).toBeVisible();
        await expect(page.locator('main')).not.toContainText(token);
        await expect(page.getByRole('link', { name: 'Upgrade' })).toHaveCount(0);
      }
    }
    if (row.visible) {
      await page.goto('/members/library/');
      await expect(page.locator('main')).toContainText(`${row.visible} ${mark}`);
    }
    if (row.track) {
      await page.goto('/members/tracks/');
      await expect(page.getByText('Healthy Weight is not suitable for you.')).toBeVisible();
      await expect(page.getByRole('link', { name: 'Upgrade' })).toHaveCount(0);
    }
  }
});

test('TC-FR2-25-AS-06', async ({ page }) => {
  const email = emailFor('remind');
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  const due = new Date(`${created.joined_on}T12:00:00Z`);
  due.setUTCDate(due.getUTCDate() + 30);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: due.toISOString().slice(0, 19).replace('T', ' ') } });
  const before = await countTo(email, 'Complete your profile');
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  await expect.poll(async () => countTo(email, 'Complete your profile')).toBe(before + 1);
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(await countTo(email, 'Complete your profile')).toBe(before + 1);
});

test('TC-FR2-38-AS-01', async ({ page }) => {
  const email = emailFor('harm');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="yes"]').check();
  await page.locator('input[name="lld_panic"][value="yes"]').check();
  await page.locator('input[name="lld_mood"][value="yes"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('If you are in danger now, call your local emergency number')).toBeVisible();
  await expect(page.getByText('999')).toBeVisible();
  await expect(page.getByText('911')).toBeVisible();
  await expect(page.getByText('112')).toBeVisible();
  await expect(page.getByText('000')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Find support in your country' })).toHaveAttribute('href', 'https://findahelpline.com');
  await expect(page.getByRole('button', { name: 'Continue' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /Start/ })).toHaveCount(0);
  await page.goto('/members/home/');
  await expect(page.getByText('Week 1, day 1')).toHaveCount(0);
});

test('TC-FR2-38-AS-02', async ({ page }) => {
  const email = emailFor('panic');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="no"]').check();
  await page.locator('input[name="lld_panic"][value="yes"]').check();
  await page.locator('input[name="lld_mood"][value="no"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Panic attacks can have a physical cause. See a doctor before you start this track.')).toBeVisible();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Sleep and Stress. Week 1, day 1.')).toBeVisible();
});

test('TC-FR2-38-AS-03', async ({ page }) => {
  const email = emailFor('mood');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="no"]').check();
  await page.locator('input[name="lld_panic"][value="no"]').check();
  await page.locator('input[name="lld_mood"][value="yes"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText("Low mood that lasts this long is worth a doctor's visit. See a doctor, then come back and continue.")).toBeVisible();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Sleep and Stress. Week 1, day 1.')).toBeVisible();
});

test('TC-FR2-38-AS-04', async ({ page }) => {
  const email = emailFor('store');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="yes"]').check();
  await page.locator('input[name="lld_panic"][value="no"]').check();
  await page.locator('input[name="lld_mood"][value="no"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('link', { name: 'Find support in your country' })).toHaveAttribute('href', 'https://findahelpline.com');
  const found = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: 'harming yourself' } });
  const body = await found.json();
  expect(body.hits).toEqual([]);
  expect(body.log).toBeFalsy();
});

test('TC-FR2-38-AS-05', async ({ browser, page }) => {
  const email = emailFor('again');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="no"]').check();
  await page.locator('input[name="lld_panic"][value="no"]').check();
  await page.locator('input[name="lld_mood"][value="no"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.goto('/members/screening/?track=sleep-stress');
  await expect(page.getByText('Are you currently having thoughts of harming yourself?')).toBeVisible();
  const other = await browser.newContext();
  const otherPage = await other.newPage();
  await signIn(otherPage, email, password);
  await otherPage.goto('/members/screening/?track=sleep-stress');
  await expect(otherPage.getByText('Are you currently having thoughts of harming yourself?')).toBeVisible();
  await other.close();
});

test('TC-FR2-38-AS-06', async ({ page }) => {
  const mark = stamp();
  await asOwner(page);
  await publishItem(page, 'lld_lesson', `Holding ${mark}`, 'A short lesson.', 'breath-holding');
  await expect(page.getByText('Breath-holding is not allowed.')).toBeVisible();
  const found = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: 'breath-holding' } });
  expect((await found.json()).hits).toEqual([]);
});

test('TC-FR2-06-AS-01', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('six');
  const made = await fixture(page.request, 'pool', mark);
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await chooseGoal(page, made.goal);
  await page.goto('/members/suggestions/');
  await expect(page.locator('.lld-suggestion')).toHaveCount(6);
  await expect(page.locator('.lld-reason')).toHaveCount(6);
  await expect(page.getByRole('link', { name: 'Protein calculator' })).toBeVisible();
  await expect(page.getByText('A general wellbeing tool')).toBeVisible();
});

test('TC-FR2-06-AS-02', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('opened');
  const made = await fixture(page.request, 'pool', mark);
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: '2026-04-01 12:00:00' } });
  await chooseGoal(page, made.goal);
  await page.goto(`/members/library/${made.items[0].id}/`);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: '2026-04-11 12:00:00' } });
  await page.goto('/members/suggestions/');
  await expect(page.locator('main')).not.toContainText(made.items[0].title);
  await page.goto(`/members/library/${made.items[1].id}/`);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: '2026-04-12 12:00:00' } });
  const unwanted = made.items.slice(2, 6).map((item) => item.title);
  for (let n = 1; n <= 5; n += 1) unwanted.push(`${made.goal} article ${n}`);
  for (let n = 0; n < unwanted.length; n += 1) {
    await page.goto('/members/suggestions/');
    const text = await page.locator('main').innerText();
    const title = unwanted.find((item) => text.includes(item));
    if (!title) break;
    await page.locator('.lld-suggestion', { hasText: title }).getByRole('button', { name: 'Not for me' }).click();
  }
  await page.goto('/members/suggestions/');
  const text = await page.locator('main').innerText();
  expect(text.indexOf(made.items[0].title)).toBeGreaterThan(-1);
  expect(text.indexOf(made.items[0].title)).toBeLessThan(text.indexOf(made.items[1].title));
});

test('TC-FR2-06-AS-03', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('hide');
  const made = await fixture(page.request, 'pair', mark);
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await chooseGoal(page, made.goal);
  await page.goto('/members/suggestions/');
  await page.locator('.lld-suggestion', { hasText: made.a.title }).getByRole('button', { name: 'Not for me' }).click();
  await expect(page.getByText(made.a.title)).toHaveCount(0);
  await expect(page.getByText(made.b.title)).toBeVisible();
});

test('TC-FR2-06-AS-04', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('empty');
  const made = await fixture(page.request, 'blocked', mark);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await chooseGoal(page, made.goal);
  await saveHealth(page, created.member_id, { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify(['pregnancy']) });
  await page.goto('/members/suggestions/');
  await expect(page.getByText(made.title)).toHaveCount(0);
  await expect(page.getByText('Nothing suitable is available right now.')).toBeVisible();
  await page.goto('/members/home/');
  await expect(page.getByText(made.title)).toHaveCount(0);
});

test('TC-FR2-06-AS-05', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const email = emailFor('draft');
  const made = await fixture(page.request, 'pool', mark);
  const draft = await fixture(page.request, 'article', mark, { tag: made.tag });
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await chooseGoal(page, made.goal);
  await page.goto('/members/suggestions/');
  await expect(page.getByText(draft.title)).toHaveCount(0);
  await expect(page.getByText(made.items[0].title)).toBeVisible();
});

test('TC-FR2-06-AS-06', async ({ page }) => {
  const mark = stamp();
  const email = emailFor('report');
  const theme = `plaza report ${mark}`;
  const gap = await fixture(page.request, 'gap', mark);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await saveHealth(page, created.member_id, { kind: 'something_else', text: theme });
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: '2027-11-01 09:00:00' } });
  const gapsBefore = (await mailBodies('Content gaps')).length;
  const themesBefore = (await mailBodies('Something else themes')).length;
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  await expect.poll(async () => (await mailBodies('Content gaps')).length).toBeGreaterThan(gapsBefore);
  await expect.poll(async () => (await mailBodies('Something else themes')).length).toBeGreaterThan(themesBefore);
  expect((await mailBodies('Content gaps')).some((body) => body.includes(gap.title))).toBeTruthy();
  expect((await mailBodies('Something else themes')).some((body) => body.includes('plaza report'))).toBeTruthy();
  const gapsOnce = (await mailBodies('Content gaps')).length;
  const themesOnce = (await mailBodies('Something else themes')).length;
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect((await mailBodies('Content gaps')).length).toBe(gapsOnce);
  expect((await mailBodies('Something else themes')).length).toBe(themesOnce);
});

test('TC-FR2-06-AS-07', async ({ page }) => {
  test.setTimeout(180000);
  const mark = stamp();
  const title = `GoalLive ${mark}`;
  const asked = emailFor('asked');
  const quiet = emailFor('quiet');
  const yes = await createMember(page.request, { email: asked, password, first_name: 'Sam', consents: ['terms', 'health', 'marketing'] });
  const no = await createMember(page.request, { email: quiet, password, first_name: 'No', consents: ['terms', 'health'] });
  await signIn(page, asked, password);
  await saveHealth(page, yes.member_id, { kind: 'something_else', text: title });
  await signIn(page, quiet, password);
  await saveHealth(page, no.member_id, { kind: 'something_else', text: title });
  await fixture(page.request, 'live', mark, { title });
  const beforeYes = await countTo(asked, 'A goal you asked for is now available');
  const beforeNo = await countTo(quiet, 'A goal you asked for is now available');
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: { notify: true } });
  await expect.poll(async () => countTo(asked, 'A goal you asked for is now available')).toBe(beforeYes + 1);
  expect(await countTo(quiet, 'A goal you asked for is now available')).toBe(beforeNo);
});

test('TC-FR2-07-AS-01', async ({ page }) => {
  const email = emailFor('order');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/home/');
  const names = await page.locator('[data-lld-section]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-lld-section')));
  expect(names).toEqual(['greeting', 'today', 'plan', 'meals', 'diary', 'progress', 'suggestions', 'membership']);
  await expect(page.locator('[data-lld-section="greeting"]')).toContainText('Sam');
  await expect(page.locator('[data-lld-section="greeting"] svg, [data-lld-section="greeting"] img')).toHaveCount(1);
  await expect(page.getByText("Today's activity")).toBeVisible();
  await expect(page.getByRole('heading', { name: "This week's plan" })).toBeVisible();
  await expect(page.getByText("Today's meals")).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Food diary' })).toBeVisible();
  await expect(page.getByText('0 of 28 done')).toBeVisible();
  await expect(page.getByText('Your membership is Free.')).toBeVisible();
});

test('TC-FR2-07-AS-02', async ({ page }) => {
  const freeEmail = emailFor('free');
  const paidEmail = emailFor('paid');
  await createMember(page.request, { email: freeEmail, password, first_name: 'Sam', consents: ['terms'] });
  await signIn(page, freeEmail, password);
  await page.goto('/members/home/');
  const html = await page.content();
  expect(html.indexOf('what Premium adds')).toBeGreaterThan(html.indexOf('data-lld-section="progress"'));
  expect(html.indexOf('data-lld-section="suggestions"')).toBeGreaterThan(html.indexOf('what Premium adds'));
  await createMember(page.request, { email: paidEmail, password, first_name: 'Pat', consents: ['terms'], access_state: 'Premium' });
  await signIn(page, paidEmail, password);
  await page.goto('/members/home/');
  await expect(page.getByText('what Premium adds')).toHaveCount(0);
});

test('TC-FR2-07-AS-03', async ({ page }) => {
  const monthly = emailFor('month');
  const yearly = emailFor('year');
  await createMember(page.request, { email: monthly, password, first_name: 'Mo', consents: ['terms'], access_state: 'Premium', plan: 'monthly', started_on: '2026-01-01' });
  await signIn(page, monthly, password);
  await page.goto('/members/home/');
  await expect(page.getByText('Switch to yearly')).toBeVisible();
  await createMember(page.request, { email: yearly, password, first_name: 'Ye', consents: ['terms'], access_state: 'Premium', plan: 'yearly', started_on: '2026-01-01' });
  await signIn(page, yearly, password);
  await page.goto('/members/home/');
  await expect(page.getByText('Switch to yearly')).toHaveCount(0);
});

test('TC-FR2-07-AS-04', async ({ page }) => {
  const email = emailFor('phone');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/members/home/');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  expect(overflow).toBeFalsy();
  await page.getByRole('link', { name: 'See more' }).click();
  await expect(page).toHaveURL(/\/members\/suggestions\/?/);
  await page.goto('/members/home/');
  await page.getByRole('link', { name: 'Open food diary' }).click();
  await expect(page.getByRole('heading', { name: 'Food diary' })).toBeVisible();
});
