// P3-72 on the CI WordPress: the Monday report caught by Mailpit carries the Stage 3 totals (FR3-70). The money,
// pause and Google Analytics figures for a prepared week are checked line by line in tests/stage3/weekly-report.test.mjs;
// here the week is built through the site itself (sign-up, checkout, cancellation) and the real email is read.
import { createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { createMember, password } from '../../key-journeys/helpers.mjs';

const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const stamp = () => randomBytes(4).toString('hex');
const sleep = (ms) => new Promise((done) => setTimeout(done, ms));

function testSecret() {
  if (process.env.LLD_TEST_SECRET) return process.env.LLD_TEST_SECRET;
  const path = `${process.env.RUNNER_TEMP}/lld-test-secret`;
  return existsSync(path) ? readFileSync(path, 'utf8').trim() : '';
}

async function growth(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/growth', { data });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function notify(request, event) {
  const body = JSON.stringify(event);
  const t = Math.floor(Date.now() / 1000);
  const v1 = createHmac('sha256', testSecret()).update(`${t}.${body}`).digest('hex');
  const res = await request.post('/wp-json/lld-members/v1/stripe/notice', { headers: { 'Stripe-Signature': `t=${t},v1=${v1}`, 'Content-Type': 'application/json' }, data: body });
  expect(res.ok(), await res.text()).toBeTruthy();
  return res.json();
}

async function checkout(request, email, subscription) {
  const ready = await request.post('/wp-json/lld-members/v1/test/billing', { data: { action: 'session', sale_terms: true, email, plan: 'monthly', country: 'US' } });
  expect(ready.ok(), await ready.text()).toBeTruthy();
  const quoted = await ready.json();
  const payment = `pi_${stamp()}`;
  await notify(request, { id: `evt_${payment}`, type: 'checkout.session.completed', data: { object: { id: quoted.session, amount_total: Number(quoted.charged), subscription, payment_intent: payment, email } } });
}

async function latestReport(after) {
  for (let i = 0; i < 30; i++) {
    const list = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('subject:"Weekly report"')}&limit=50`).then((res) => res.json());
    const found = (list.messages || []).filter((m) => m.Subject === 'Weekly report' && new Date(m.Created) >= after);
    if (found.length) {
      found.sort((a, b) => new Date(b.Created) - new Date(a.Created));
      const full = await fetch(`${MAILPIT}/api/v1/message/${found[0].ID}`).then((res) => res.json());
      return full.Text || '';
    }
    await sleep(500);
  }
  return '';
}

test('TC-FR3-70-AS-01', async ({ request }) => {
  await growth(request, { action: 'reset' });
  const upgrader = `p372-up-${stamp()}@test.lifelinedaily.invalid`;
  const leaver = `p372-out-${stamp()}@test.lifelinedaily.invalid`;
  await createMember(request, { email: upgrader, password, first_name: 'Synthetic', consents: ['terms'] });
  await createMember(request, { email: leaver, password, first_name: 'Synthetic', consents: ['terms'] });
  await checkout(request, upgrader, `sub_${stamp()}`);
  const gone = `sub_${stamp()}`;
  await checkout(request, leaver, gone);
  await notify(request, { id: `evt_del_${gone}`, type: 'customer.subscription.deleted', data: { object: { id: gone, cancellation_details: { feedback: 'unused' } } } });
  const figures = await growth(request, { action: 'figures' });
  const sum = (field) => Object.values(figures.tracks).reduce((n, row) => n + Number(row[field] || 0), 0);
  const after = new Date(Date.now() - 2000);
  const run = await growth(request, { action: 'monday' });
  expect(run.weekday).toBe('Monday 07:00');
  const mail = (await latestReport(after)).split(/\r?\n/);
  expect(mail[0]).toBe(`Weekly report for ${run.week[0]} to ${run.week[1]}`);
  for (const line of [
    'Totals for the week',
    `Sign-ups ${figures.registrations}`,
    `Upgrades ${sum('upgrades')}`,
    `Renewals ${sum('renewals')}`,
    `Cancellations ${sum('cancellations')}`,
    'Pauses started 0',
    'Stripe paid invoices: Stripe is not connected',
    `Premium members ${figures.active}`,
  ]) expect(mail, line).toContain(line);
  expect(sum('upgrades')).toBe(2);
  expect(sum('cancellations')).toBe(1);
  // Without Plausible, the visits line now names Google Analytics.
  expect(mail).toContain('Visits and calculator use: Google Analytics is not connected');
  const members = Number((mail.find((l) => l.startsWith('Members ')) || '').split(' ')[1]);
  const free = Number((mail.find((l) => l.startsWith('Free members ')) || '').split(' ')[2]);
  expect(members).toBeGreaterThanOrEqual(2);
  expect(free).toBe(members - figures.active);
});

test('TC-FR3-70-AS-02', async ({ request }) => {
  await growth(request, { action: 'reset' });
  const email = `p372-private-${stamp()}@test.lifelinedaily.invalid`;
  await createMember(request, { email, password, first_name: 'Sentinelname', last_name: 'Sentinelsurname', consents: ['terms', 'health'] });
  await checkout(request, email, `sub_${stamp()}`);
  const after = new Date(Date.now() - 2000);
  await growth(request, { action: 'monday' });
  const mail = await latestReport(after);
  expect(mail).toContain('Totals for the week');
  for (const banned of [email, 'Sentinelname', 'Sentinelsurname', '@']) expect(mail).not.toContain(banned);
  // Every Stage 3 line is a label and a count or an amount.
  const part = mail.split(/\r?\n/).slice(mail.split(/\r?\n/).indexOf('Totals for the week') + 1);
  for (const line of part) expect(line).toMatch(/^[A-Za-z :-]+( [A-Z]{3} \d+\.\d{2} \(\d+\)| \d+| none|: Stripe is not connected)$/);
});
