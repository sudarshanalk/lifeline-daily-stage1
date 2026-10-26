import { randomBytes } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, openAdmin, publishItem, signIn, submitAndWait } from './helpers.mjs';
import { matrixRows, safetyTags } from '../tools/safety-matrix.mjs';

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

async function startSlug(page, slug) {
  await page.goto('/members/tracks/');
  const value = await page.locator('input[name="lld_track_nonce"]').first().inputValue();
  const res = await page.request.post('/members/tracks/', { form: { lld_track_nonce: value, lld_track: slug }, maxRedirects: 0 });
  expect([200, 302, 303]).toContain(res.status());
}

async function html(page, path) {
  const res = await page.request.get(path);
  return res.text();
}

function field(text, name) {
  const found = text.match(new RegExp(`name="${name}" value="([^"]+)"`));
  return found ? found[1] : '';
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
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await page.goto('/members/health/');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.locator('#lld_sex').selectOption('female');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.reload();
  await expect(page.locator('#lld_sex')).toHaveValue('female');
  // T1: "None" can't be combined with a condition through the REST route either.
  await page.goto('/members/home/');
  const headers = { 'X-WP-Nonce': await nonce(page) };
  for (const data of [
    { kind: 'profile', confirmed: true, conditions_answered: 'none', conditions: ['pregnancy'] },
    { kind: 'profile', confirmed: true, conditions_answered: 'declared', conditions: JSON.stringify(['none', 'kidney']) },
  ]) {
    const res = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, { data, headers });
    expect(res.status(), JSON.stringify(data)).toBe(400);
  }
  await page.goto('/members/health/');
  await expect(page.getByRole('checkbox', { name: 'Pregnancy or breastfeeding' })).not.toBeChecked();
  await expect(page.getByRole('checkbox', { name: 'Kidney disease' })).not.toBeChecked();
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
  test.setTimeout(900000);
  const mark = stamp();
  const email = emailFor('matrix');
  const made = await fixture(page.request, 'safety', mark);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'], access_state: 'Premium' });
  // 100 days after joining, so the 90-day rule earns the fixture's weight-loss badge where the matrix allows it.
  const later = new Date(`${created.joined_on}T12:00:00Z`);
  later.setUTCDate(later.getUTCDate() + 100);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: later.toISOString().slice(0, 19).replace('T', ' ') } });
  await signIn(page, email, password);
  await chooseGoal(page, made.goal);
  await startSlug(page, made.track);
  const surfaces = ['/members/home/', '/members/suggestions/', '/members/plan/', '/members/meals/', '/members/meals/?pdf=1', '/members/workout/', '/members/challenge/', '/members/badges/', '/members/library/', '/members/data/', '/members/tracks/', '/members/goals/'];
  const token = (tag) => `${tag} ${mark}`;
  const hwGoal = 'value="healthy-weight"> Healthy Weight</label>';
  for (const row of matrixRows) {
    if (row.profile) {
      await saveHealth(page, created.member_id, { kind: 'profile', confirmed: true, ...row.profile, conditions: JSON.stringify(row.profile.conditions || '') });
    }
    const hidden = new Set(row.hidden);
    const shown = safetyTags.filter((tag) => !hidden.has(tag));

    // Seen, listed or chosen: no excluded item on any member surface, download or option list.
    for (const path of surfaces) {
      const text = await html(page, path);
      for (const tag of row.hidden) expect(text, `${row.name} ${path}`).not.toContain(token(tag));
    }

    // Opened: direct links, article links and downloads of every excluded tag show "not suitable", with the row's note.
    for (const tag of row.hidden) {
      const items = made.tags[tag];
      for (const path of [
        `/members/library/${items.lld_activity.id}/`,
        `/members/library/${items.lld_workout.id}/`,
        `/members/library/${items.lld_meal.id}/`,
        `/members/library/${items.lld_challenge.id}/`,
        `/members/library/${items.lld_week.id}/`,
        `/members/library/${items.lld_recipe.id}/?download=1`,
        `/members/read/${items.post.id}/`,
      ]) {
        const text = await html(page, path);
        expect(text, `${row.name} ${path}`).toContain('Not suitable for you');
        expect(text, `${row.name} ${path}`).not.toContain(token(tag));
        expect(text, `${row.name} ${path}`).not.toMatch(/>\s*Upgrade\s*</);
        if (row.note) expect(text, `${row.name} ${path}`).toContain(row.note);
        if (row.helpline) expect(text, `${row.name} ${path}`).toContain(`href="${row.helpline}"`);
      }
      // Chosen through the real meal swap: posting an excluded recipe is refused.
      const meals = await html(page, '/members/meals/');
      const swap = field(meals, 'lld_swap_nonce');
      expect(swap, `${row.name} meal swap form`).not.toBe('');
      await page.request.post('/members/meals/', { form: { lld_swap_nonce: swap, lld_slot: '1-breakfast', lld_recipe: String(items.lld_recipe.id) } });
      expect(await html(page, '/members/meals/'), `${row.name} swap ${tag}`).not.toContain(token(tag));
    }

    // Chosen through the real plan swap: the new plan holds nothing excluded either.
    const plan = await html(page, '/members/plan/');
    const planNonce = field(plan, 'lld_plan_nonce');
    if (planNonce) await page.request.post('/members/plan/', { form: { lld_plan_nonce: planNonce, lld_swap: '1' } });
    const swapped = await html(page, '/members/plan/');
    for (const tag of row.hidden) expect(swapped, `${row.name} plan swap`).not.toContain(token(tag));

    // Nothing more is hidden than the row says.
    const library = await html(page, '/members/library/');
    for (const tag of shown) expect(library, `${row.name} keeps ${tag}`).toContain(`lld_activity ${token(tag)}`);
    if (shown.length) expect(await html(page, `/members/read/${made.tags[shown[0]].post.id}/`)).toContain(made.tags[shown[0]].post.title);

    if (row.name === 'none') {
      // Assign excluded content while it is allowed, so later rows prove it is removed once a condition is saved.
      const meals = await html(page, '/members/meals/');
      await page.request.post('/members/meals/', { form: { lld_swap_nonce: field(meals, 'lld_swap_nonce'), lld_slot: '1-breakfast', lld_recipe: String(made.tags['weight-loss'].lld_recipe.id) } });
      expect(await html(page, '/members/meals/')).toContain(token('weight-loss'));
      expect(await html(page, '/members/badges/')).toContain(made.badge.title);
      expect(await html(page, '/members/goals/')).toContain(hwGoal);
    }

    // Weight-loss badges, the Healthy Weight track and the goal that leads to it.
    const badges = await html(page, '/members/badges/');
    if (row.badges) expect(badges, `${row.name} badge`).not.toContain(made.badge.title);
    const tracks = await html(page, '/members/tracks/');
    const goals = await html(page, '/members/goals/');
    if (row.track) {
      expect(tracks).toContain('Healthy Weight is not suitable for you.');
      expect(tracks).not.toContain('Start Healthy Weight');
      expect(tracks).not.toMatch(/>\s*Upgrade\s*</);
      expect(tracks).toContain(row.note);
      if (row.helpline) expect(tracks).toContain(`href="${row.helpline}"`);
      expect(goals, `${row.name} goal picker`).not.toContain(hwGoal);
    }

    // Caution notes the row adds to content it still shows.
    const intense = await html(page, `/members/library/${made.tags['high-intensity'].lld_activity.id}/`);
    if (row.exercise) {
      expect(intense).toContain(row.exercise);
      expect(await html(page, '/members/workout/')).toContain(row.exercise);
    }
    if (row.intense) expect(intense).toContain(row.intense);
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

  // Without health consent: no EM-14 and no health profile row (C3 is health data).
  const quiet = emailFor('remind-none');
  const other = await createMember(page.request, { email: quiet, password, first_name: 'Ned', consents: ['terms'] });
  const later = new Date(`${other.joined_on}T12:00:00Z`);
  later.setUTCDate(later.getUTCDate() + 31);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: quiet, utc: later.toISOString().slice(0, 19).replace('T', ' ') } });
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect(await countTo(quiet, 'Complete your profile')).toBe(0);
  const state = await (await page.request.post('/wp-json/lld-members/v1/test/screen-state', { data: { email: quiet } })).json();
  const profileRows = Object.entries(state.rows).filter(([table]) => table.endsWith('lld_health_profile'));
  expect(profileRows.length).toBe(1);
  expect(profileRows[0][1]).toBe(0);
});

test('TC-FR2-38-AS-01', async ({ browser, page }) => {
  const email = emailFor('harm');
  await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  const emergency = async (target) => {
    await expect(target.getByText('If you are in danger now, call your local emergency number')).toBeVisible();
    for (const number of ['999', '911', '112', '000']) await expect(target.getByText(number, { exact: false })).toBeVisible();
    await expect(target.getByRole('link', { name: 'Find support in your country' })).toHaveAttribute('href', 'https://findahelpline.com');
    await expect(target.getByRole('button', { name: 'Continue' })).toHaveCount(0);
    await expect(target.getByRole('link', { name: /Start/ })).toHaveCount(0);
  };
  const noOffer = async (target) => {
    await target.goto('/members/tracks/');
    await expect(target.getByRole('link', { name: 'Start Sleep and Stress' })).toHaveCount(0);
    await expect(target.getByRole('button', { name: 'Start Sleep and Stress' })).toHaveCount(0);
    await target.goto('/members/screening/?track=sleep-stress');
    await emergency(target);
    await expect(target.getByText('Are you currently having thoughts of harming yourself?')).toHaveCount(0);
    await target.goto('/members/home/');
    await expect(target.getByText('Sleep and Stress. Week 1')).toHaveCount(0);
  };

  // Yes to self-harm alone, with the other questions left blank: the emergency message, not "answer all three".
  await signIn(page, email, password);
  await page.goto('/members/screening/?track=sleep-stress');
  await page.locator('input[name="lld_harm"][value="yes"]').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await emergency(page);
  await expect(page.getByText('Answer all three questions before continuing.')).toHaveCount(0);
  await noOffer(page);
  // Posting the start straight away is refused in this session.
  const value = await page.request.get('/members/screening/?track=sleep-stress');
  expect(await value.text()).not.toContain('name="lld_screen_nonce"');

  // Yes to all three, in a new session: the same response overrides the others.
  const other = await browser.newContext();
  const second = await other.newPage();
  await signIn(second, email, password);
  await second.goto('/members/screening/?track=sleep-stress');
  await second.locator('input[name="lld_harm"][value="yes"]').check();
  await second.locator('input[name="lld_panic"][value="yes"]').check();
  await second.locator('input[name="lld_mood"][value="yes"]').check();
  await second.getByRole('button', { name: 'Continue' }).click();
  await emergency(second);
  await expect(second.getByText('Panic attacks can have a physical cause')).toHaveCount(0);
  await noOffer(second);
  await other.close();
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
  const state = async () => {
    const res = await page.request.post('/wp-json/lld-members/v1/test/screen-state', { data: { email } });
    expect(res.ok(), await res.text()).toBeTruthy();
    return res.json();
  };
  let before = null;
  const answer = async (harm, panic, mood) => {
    await signIn(page, email, password);
    await page.goto('/members/screening/?track=sleep-stress');
    before = await state();
    await page.locator(`input[name="lld_harm"][value="${harm}"]`).check();
    await page.locator(`input[name="lld_panic"][value="${panic}"]`).check();
    await page.locator(`input[name="lld_mood"][value="${mood}"]`).check();
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('heading', { name: 'Before you start' })).toBeVisible();
    return state();
  };
  // What is kept is the same whatever was answered: only a session mark that names no answer.
  const allowed = (marks) => {
    for (const [key, value] of Object.entries(marks)) {
      if (key === 'lld_pass') expect(typeof value).toBe('number');
      else if (key === 'lld_hold') expect(value).toBe('sleep-stress');
      else throw new Error(`unexpected session mark ${key}`);
    }
  };
  for (const [harm, panic, mood] of [['no', 'yes', 'no'], ['no', 'no', 'yes'], ['no', 'yes', 'yes'], ['yes', 'no', 'no'], ['yes', 'yes', 'yes']]) {
    const after = await answer(harm, panic, mood);
    expect(after.rows, `${harm}/${panic}/${mood} rows`).toEqual(before.rows);
    expect(after.options, `${harm}/${panic}/${mood} options`).toEqual(before.options);
    expect(after.usermeta, `${harm}/${panic}/${mood} user meta`).toEqual(before.usermeta);
    for (const marks of after.sessions) allowed(marks);
  }
  for (const needle of ['lld_harm', 'lld_panic', 'lld_mood', 'harming yourself']) {
    const found = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: needle } });
    const body = await found.json();
    expect(body.hits, needle).toEqual([]);
    expect(body.log, needle).toBeFalsy();
  }
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
  await submitAndWait(page, page.getByRole('button', { name: 'Continue' }));
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
  // B2: one managed tag list. A typed tag that is not in it is refused, so no new tag is created.
  const loose = `free-text-${mark}`;
  const looseId = await publishItem(page, 'lld_lesson', `Loose tag ${mark}`, 'A short lesson.', loose);
  await expect(page.getByText(`Not saved: ${loose} is not in the tag list.`)).toBeVisible();
  await openAdmin(page, `/wp-admin/post.php?post=${looseId}&action=edit`);
  await expect(page.locator('#lld_tags')).toHaveValue('');
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

  // Without health consent, opening an item or "not for me" writes no suggestion history (C15).
  const plain = emailFor('hide-none');
  await createMember(page.request, { email: plain, password, first_name: 'Ned', consents: ['terms'] });
  await signIn(page, plain, password);
  await page.goto('/members/suggestions/');
  await expect(page.getByRole('button', { name: 'Not for me' })).toHaveCount(0);
  await page.goto(`/members/library/${made.a.id}/`);
  await expect(page.getByRole('heading', { name: made.a.title })).toBeVisible();
  const state = await (await page.request.post('/wp-json/lld-members/v1/test/screen-state', { data: { email: plain } })).json();
  const history = Object.entries(state.rows).filter(([table]) => table.endsWith('lld_suggestion'));
  expect(history.length).toBe(1);
  expect(history[0][1]).toBe(0);
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
  // The empty state is the pregnancy row's message from requirements section 8.2.
  await expect(page.getByText('Talk to your midwife or doctor about weight and exercise during pregnancy')).toBeVisible();
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

// Every message that mentions the needle, however many other messages Mailpit holds.
async function mailSearch(needle) {
  const found = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(needle)}&limit=1000`).then((res) => res.json());
  const out = [];
  for (const message of found.messages || []) {
    const id = message.ID || message.Id || message.id;
    const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
    out.push({ subject: full.Subject || '', text: full.Text || '' });
  }
  return out;
}

// The 1st of a month that is also a Monday, in a year no earlier run used, so the once-per-date notes are fresh.
function reportDate() {
  for (;;) {
    const day = new Date(Date.UTC(2100 + Math.floor(Math.random() * 800), Math.floor(Math.random() * 12), 1));
    if (day.getUTCDay() === 1) return day.toISOString().slice(0, 10);
  }
}

test('TC-FR2-06-AS-06', async ({ page }) => {
  const mark = stamp();
  const email = emailFor('report');
  const gap = await fixture(page.request, 'gap', mark);
  const secret = `plaza-${mark}`;
  const created = await createMember(page.request, { email, password, first_name: 'Sam', consents: ['terms', 'health'] });
  await signIn(page, email, password);
  await saveHealth(page, created.member_id, { kind: 'something_else', text: `Help with ${gap.title.toLowerCase()} please ${secret}` });
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: `${reportDate()} 09:00:00` } });
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  const gaps = async () => (await mailSearch(gap.title)).filter((mail) => mail.subject === 'Content gaps');
  const themes = async () => (await mailSearch(gap.title)).filter((mail) => mail.subject === 'Something else themes');
  // The weekly note names the goal that is short of content.
  await expect.poll(async () => (await gaps()).length).toBeGreaterThan(0);
  // The monthly note holds themes and counts only (data model F5): the request is counted under the goal it
  // mentions, and none of the member's own words are in it.
  await expect.poll(async () => (await themes()).length).toBeGreaterThan(0);
  const sent = await themes();
  expect(sent.some((mail) => mail.text.includes(`${gap.title}: 1`))).toBeTruthy();
  expect((await mailSearch(secret)).length).toBe(0);
  // Each note goes once per date.
  const gapsOnce = (await gaps()).length;
  const themesOnce = sent.length;
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  expect((await gaps()).length).toBe(gapsOnce);
  expect((await themes()).length).toBe(themesOnce);
  const found = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: secret } });
  expect((await found.json()).hits).toEqual([]);
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
  // The daily job sends it: no separate notify call.
  await page.request.post('/wp-json/lld-members/v1/test/reports', { data: {} });
  await expect.poll(async () => countTo(asked, 'A goal you asked for is now available')).toBe(beforeYes + 1);
  expect(await countTo(quiet, 'A goal you asked for is now available')).toBe(beforeNo);
  // EM-15 does not name the goal: it came from the member's own "Something else" text.
  const sent = (await mailBodies('A goal you asked for is now available')).filter((text) => text.includes(asked));
  expect(sent.length).toBeGreaterThan(0);
  for (const text of sent) expect(text).not.toContain(title);
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
