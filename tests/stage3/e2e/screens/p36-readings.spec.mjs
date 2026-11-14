// B2 live contracts (Codex, #263). Labels follow the owner-approved wording (P3-36 wording log, 14 Nov 2026: W20, W24);
// clinical text comes only from the canonical server class.
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { fresh, api, rows, reading, acted, DAY, AT } from './p36-readings-helpers.mjs';
import { submitAndWait } from '../../../stage2/e2e/helpers.mjs';

const TYPES = {
  blood_pressure: { label: 'Blood pressure', values: [['Top number (systolic, mmHg)', 128], ['Bottom number (diastolic, mmHg)', 82], ['Pulse (bpm, optional)', 70]], value: '128' },
  weight: { label: 'Weight', values: [['Weight', 80]], value: '80' },
  waist: { label: 'Waist', values: [['Waist', 90]], value: '90' },
  activity: { label: 'Activity', values: [['Minutes', 30]], value: '30' },
  sleep: { label: 'Sleep', values: [['Bedtime', '23:00'], ['Wake time', '07:00']], value: '8' },
  water: { label: 'Water', values: [['Or enter amount', 250]], value: '250' },
  mood: { label: 'Mood', values: [], value: '2' },
  glucose: { label: /Blood sugar|Glucose/, values: [['Blood sugar', 5.6]], value: '5.6' },
};
// The form field with that label: the type's panel carries the type's name too (it is labelled by its tab).
const field = (page, label) => page.locator('input, select, textarea').and(page.getByLabel(label, { exact: true }));
async function choose(page, type) {
  const label = TYPES[type].label;
  const control = page.getByRole('tab', { name: label, exact: true })
    .or(page.getByRole('radio', { name: label, exact: true }))
    .or(page.getByRole('link', { name: label, exact: true }));
  await expect(control).toHaveCount(1);
  if (await control.getAttribute('type') === 'radio') await control.check(); else await control.click();
}
async function open(page, type = 'blood_pressure') {
  const response = await page.goto('/members/readings/');
  expect(response.status()).toBe(200);
  await expect(page.getByRole('heading', { name: /Log a reading|Readings/, exact: true }).first()).toBeVisible();
  await choose(page, type);
}
async function fill(page, type, overrides = {}) {
  await page.getByLabel('Date and time', { exact: true }).fill(`${DAY}T09:00`);
  for (const [label, value] of TYPES[type].values) await field(page, label).fill(String(overrides[label] ?? value));
  if (type === 'mood') {
    const mood = page.getByRole('radio', { name: /^2\b/ }).or(page.getByRole('button', { name: /^2\b/ }));
    if (await mood.getAttribute('type') === 'radio') await mood.check(); else await mood.click();
  }
}
async function save(page) {
  const response = await acted(page, 'readings', () => page.getByRole('button', { name: 'Save reading', exact: true }).click());
  return { response, body: await response.json() };
}
// The history table by its own name (its caption), never by position: the chart's data table is a table too.
const history = (page) => page.getByRole('table', { name: /^Recent .+ readings$/ });
const chartName = (type) => type === 'activity' ? 'Weekly activity' : `${type === 'glucose' ? 'Blood sugar' : TYPES[type].label} trend`;
const chart = (page, type) => page.getByRole('img', { name: chartName(type), exact: true });
const chartData = (page, type) => page.getByRole('table', { name: `${chartName(type)} data`, exact: true });
async function units(page, imperial) {
  await page.goto('/members/settings/?section=profile');
  await page.getByRole('checkbox', { name: /Show imperial units/i }).setChecked(imperial);
  await submitAndWait(page, page.getByRole('button', { name: 'Save profile', exact: true }));
}
function signedWarning(type, values, flags = []) {
  const path = fileURLToPath(new URL('../../../../site/public_html/wp-content/plugins/lld-members/includes/stage3/class-safety-stage3.php', import.meta.url)).replaceAll('\\', '/');
  const encoded = Buffer.from(JSON.stringify({ type, values, flags })).toString('base64');
  const code = `define('ABSPATH','/'); require ${JSON.stringify(path)}; $p=json_decode(base64_decode('${encoded}'),true); echo json_encode(LLD_Members_Stage3_Safety::warning_level($p['type'],$p['values'],$p['flags']));`;
  return JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8', timeout: 120000 }));
}

for (const [id, type] of [['TC-FR3-38-AS-01', 'blood_pressure'], ['TC-FR3-40-AS-01', 'waist'],
  ['TC-FR3-41-AS-01', 'activity'], ['TC-FR3-42-AS-01', 'sleep'], ['TC-FR3-44-AS-01', 'mood']]) {
  test(id, async ({ page }) => {
    await fresh(page); await open(page, type); await fill(page, type);
    if (type === 'activity') await page.getByLabel('Or steps', { exact: true }).fill('6000');
    const { response, body } = await save(page); expect(response.ok()).toBeTruthy();
    if (type === 'activity') { expect(Number(body.value_1)).toBe(30); expect(Number(body.value_2)).toBe(6000); }
    await expect(history(page)).toContainText(TYPES[type].value);
    const stored = rows(await api(page, 'readings')).filter(r => r.reading_type === type);
    expect(stored).toHaveLength(1);
    if (type === 'blood_pressure') { expect(Number(stored[0].value_2)).toBe(82); expect(Number(stored[0].value_3)).toBe(70); }
    if (type === 'activity') {
      expect(Number(stored[0].value_1)).toBe(30);
      await expect(page.locator('main')).toContainText(/30.*150|150.*30/);
    }
    // Every chart has an accessible equivalent containing the logged value.
    await expect(chart(page, type)).toBeVisible();
    await expect(chartData(page, type)).toContainText(TYPES[type].value);
    if (type === 'mood') await expect(page.locator('main .lld-safety')).toHaveCount(0);
  });
}
for (const [id, type, v1, v2] of [['TC-FR3-38-AS-02', 'blood_pressure', 128, 130],
  ['TC-FR3-39-AS-02', 'weight', 80, 81], ['TC-FR3-40-AS-02', 'waist', 90, 91],
  ['TC-FR3-41-AS-02', 'activity', 30, 40], ['TC-FR3-42-AS-03', 'sleep', 8, 7],
  ['TC-FR3-43-AS-02', 'water', 250, 500], ['TC-FR3-44-AS-02', 'mood', 2, 3],
  ['TC-FR3-45-AS-02', 'glucose', 5.6, 5.8]]) {
  test(id, async ({ page }) => {
    await fresh(page);
    const entry = await reading(page, type, v1, type === 'blood_pressure' ? { value_2: 82, value_3: 70 } : type === 'sleep' ? { taken_at: `${DAY} 07:00:00`, value_2: 1380 } : {});
    await open(page, type);
    const row = history(page).getByRole('row').filter({ hasText: String(v1) });
    await row.getByRole('button', { name: 'Edit', exact: true }).click();
    const label = type === 'sleep' ? 'Bedtime' : TYPES[type].values[0]?.[0];
    if (type === 'mood') {
      const choice = page.getByRole('radio', { name: /^3\b/ }).or(page.getByRole('button', { name: /^3\b/ }));
      if (await choice.getAttribute('type') === 'radio') await choice.check(); else await choice.click();
    } else await field(page, label).fill(type === 'sleep' ? '00:00' : String(v2));
    const patched = await acted(page, `readings/${entry.id}`, () => page.getByRole('button', { name: /Save reading|Save changes/, exact: true }).click(), 'PATCH');
    expect(patched.ok(), await patched.text()).toBeTruthy();
    await expect(history(page)).toContainText(String(v2));
    await expect(chartData(page, type)).toContainText(String(v2));
    const saved = rows(await api(page, 'readings')).find(r => String(r.id) === String(entry.id));
    expect(Number(saved.value_1)).toBeCloseTo(v2, 1);
    page.once('dialog', dialog => dialog.accept());
    const removed = await acted(page, `readings/${entry.id}`, () => history(page).getByRole('row').filter({ hasText: String(v2) }).getByRole('button', { name: 'Delete', exact: true }).click(), 'DELETE');
    expect(removed.ok()).toBeTruthy(); expect(rows(await api(page, 'readings'))).toEqual([]);
    await expect(history(page).getByRole('row').filter({ hasText: String(v2) })).toHaveCount(0);
    await expect(chartData(page, type).getByRole('row').filter({ hasText: String(v2) })).toHaveCount(0);
  });
}
for (const id of ['TC-FR3-38-AS-03', 'TC-FR3-39-AS-03']) test(id, async ({ page }) => {
  await fresh(page); const type = id.includes('-38-') ? 'blood_pressure' : 'weight';
  await reading(page, type, type === 'weight' ? 80 : 128, { taken_at: '2026-11-01 09:00:00', ...(type === 'blood_pressure' ? { value_2: 82 } : {}) });
  await reading(page, type, type === 'weight' ? 81 : 130, type === 'blood_pressure' ? { value_2: 83 } : {});
  await open(page, type);
  await page.getByLabel(/^(?:From|Start date)$/).fill('2026-11-06');
  await page.getByLabel(/^(?:To|End date)$/).fill(DAY);
  await page.getByRole('button', { name: /Apply|Show history|Filter/, exact: true }).click();
  await expect(history(page)).toContainText(type === 'weight' ? '81' : '130');
  await expect(history(page)).not.toContainText(type === 'weight' ? '80' : '128');
  await expect(chartData(page, type)).toContainText(type === 'weight' ? '81' : '130');
  await expect(chartData(page, type)).not.toContainText(type === 'weight' ? '80' : '128');
});
test('TC-FR3-38-AS-04', async ({ page }) => {
  await fresh(page); await open(page); await fill(page, 'blood_pressure', { 'Top number (systolic, mmHg)': 400, 'Bottom number (diastolic, mmHg)': 20 });
  await page.getByRole('button', { name: 'Save reading', exact: true }).click();
  await expect(page.locator('main')).toContainText(/check.*number|not possible|isn't possible/i);
  expect(rows(await api(page, 'readings'))).toEqual([]);
});
test('TC-FR3-39-AS-01', async ({ page }) => {
  await fresh(page); await reading(page, 'weight', 80, { unit_shown: 'kg' }); await units(page, true); await open(page, 'weight');
  await expect(history(page)).toContainText('176.4'); await expect(history(page)).toContainText(/lb/);
});
test('TC-FR3-40-AS-03', async ({ page }) => {
  await fresh(page); await open(page, 'waist');
  const guide = page.getByRole('link', { name: /measure.*waist/i });
  await expect(guide).toBeVisible(); await expect(guide).not.toHaveAttribute('href', /^#|waist-guide$/);
  const reply = await page.request.get(new URL(await guide.getAttribute('href'), page.url()).href);
  expect(reply.ok()).toBeTruthy();
});
test('TC-FR3-41-AS-03', async ({ page }) => {
  await fresh(page); await reading(page, 'activity', 30); await open(page, 'activity');
  await expect(chart(page, 'activity')).toBeVisible();
  const figure = chart(page, 'activity').locator('xpath=ancestor::figure');
  await expect(figure).toContainText('150');
  await expect(chartData(page, 'activity')).toContainText('30');
});
test('TC-FR3-42-AS-02', async ({ page }) => {
  await fresh(page); await open(page, 'sleep'); await fill(page, 'sleep');
  const { response, body } = await save(page); expect(response.ok()).toBeTruthy();
  expect(String(body.taken_at).slice(0, 10)).toBe(DAY); expect(Number(body.value_1)).toBe(8);
  await expect(history(page)).toContainText('8');
});
test('TC-FR3-43-AS-01', async ({ page }) => {
  await fresh(page); await open(page, 'water');
  for (let n = 0; n < 2; n += 1) {
    const response = await acted(page, 'readings', () => page.getByRole('button', { name: /250 ml.*1 glass/ }).click());
    expect(response.ok()).toBeTruthy();
  }
  const stored = rows(await api(page, 'readings')).filter(r => r.reading_type === 'water');
  expect(stored).toHaveLength(2); expect(stored.reduce((n, r) => n + Number(r.value_1), 0)).toBe(500);
  await expect(page.locator('main')).toContainText(/500.*ml|2 glasses/);
});
test('TC-FR3-45-AS-01', async ({ page }) => {
  await fresh(page); await reading(page, 'glucose', 5.6, { unit_shown: 'mmol/L' }); await units(page, true); await open(page, 'glucose');
  await expect(history(page)).toContainText('101'); await expect(history(page)).toContainText('mg/dL');
});
for (const [id, type, values] of [['TC-FR3-45-AS-03', 'glucose', { value_1: 3.0 }],
  ['TC-FR3-46-AS-01', 'blood_pressure', { value_1: 185, value_2: 125 }],
  ['TC-FR3-46-AS-03', 'blood_pressure', { value_1: 185, value_2: 125 }]]) test(id, async ({ page }) => {
  for (const access_state of ['Free', 'Premium']) {
    await fresh(page, { access_state }); await open(page, type);
    await fill(page, type, type === 'glucose' ? { 'Blood sugar': values.value_1 } : { 'Top number (systolic, mmHg)': values.value_1, 'Bottom number (diastolic, mmHg)': values.value_2 });
    const { response, body } = await save(page); expect(response.ok()).toBeTruthy();
    const signed = signedWarning(type, values);
    expect(body.warning.message).toBe(signed.message);
    const alert = page.locator('main [role="alert"]').filter({ hasText: signed.message.split('\n')[0] });
    await expect(alert).toBeVisible();
    // Inner text must preserve every signed sentence; no heading or shortened prototype version is accepted.
    const expected = signed.message.replace('[When to seek urgent care]', 'When to seek urgent care');
    expect((await alert.innerText()).replace(/\s+/g, ' ').trim()).toBe(expected.replace(/\s+/g, ' ').trim());
    if (signed.message.includes('[When to seek urgent care]')) {
      const link = alert.getByRole('link', { name: 'When to seek urgent care', exact: true });
      await expect(link).toBeVisible(); await expect(link).toHaveAttribute('href', /^(?:https?:|\/)/);
    }
  }
});
test('TC-FR3-46-AS-02', async ({ page }) => {
  await fresh(page); await open(page); await fill(page, 'blood_pressure');
  const { body } = await save(page); expect(body.warning.level).toBe('none');
  await expect(page.locator('main .lld-safety')).toHaveCount(0);
});
test('TC-FR3-69-AS-05', async ({ page }) => {
  const member = await fresh(page, { profile: false });
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  await page.goto('/members/readings/');
  await expect(page.locator('main')).toContainText(/conditions/i);
  const link = page.locator('main').getByRole('link', { name: /health profile|conditions|health questions/i });
  await expect(link).toHaveAttribute('href', /\/members\/health\//);
  const refused = await page.request.post('/wp-json/lld-members/v1/me/readings', {
    headers: { 'X-WP-Nonce': nonce }, data: { reading_type: 'weight', value_1: 80, taken_at: AT, source: 'manual' },
  });
  expect(refused.status()).toBe(403); const refusal = await refused.json();
  expect(refusal.message).toMatch(/conditions/i); expect(refusal.link).toContain('/members/health/');
  await page.goto('/members/health/');
  const answered = await page.request.post(`/wp-json/lld-members/v1/members/${member.member_id}/health`, {
    headers: { 'X-WP-Nonce': await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content') },
    data: { kind: 'profile', conditions_answered: 'none', confirmed: true, sex: 'female', height_cm: 175, weight_kg: 90, activity_level: 'some' },
  });
  expect(answered.ok()).toBeTruthy(); expect(rows(await api(page, 'readings'))).toEqual([]);
});
test('TC-FR3-08-AS-02', async ({ page }) => {
  await fresh(page); await open(page);
  const empty = page.getByText(/No readings yet/i);
  await expect(empty).toBeVisible();
  const guide = page.getByRole('link', { name: /measur.*blood pressure|blood pressure.*guide/i });
  await expect(guide).toBeVisible(); await expect(guide).not.toHaveAttribute('href', /^#/);
  await expect(page.getByRole('button', { name: /first reading|record.*reading|log a reading/i })).toBeVisible();
});
test('TC-FR3-63-AS-02', async ({ page }) => {
  await fresh(page, { access_state: 'Free' });
  await reading(page, 'weight', 77, { taken_at: '2026-10-01 09:00:00' });
  await reading(page, 'weight', 80);
  await open(page, 'weight');
  await expect(history(page)).toContainText('80'); await expect(history(page)).not.toContainText(/\b77\b/);
  await expect(page.locator('main')).toContainText(/14 days/);
  await expect(page.getByRole('link', { name: /upgrade|premium/i }).first()).toHaveAttribute('href', /\/pricing\//);
});
