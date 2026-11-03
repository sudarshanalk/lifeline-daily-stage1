import { expect, test } from '@playwright/test';
import { emailFor, password } from './helpers.mjs';

async function confirmationLink(email) {
  const mailpit = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
  let link = '';
  await expect.poll(async () => {
    const messages = (await (await fetch(`${mailpit}/api/v1/messages`)).json()).messages || [];
    const message = messages.find((row) => JSON.stringify(row).includes(email));
    if (!message) return false;
    const id = message.ID || message.Id || message.id;
    const body = await (await fetch(`${mailpit}/api/v1/message/${id}`)).json();
    link = `${body.Text || body.HTML || ''}`.match(/https?:\/\/\S+\/members\/confirm\/\?token=[^\s<]+/)?.[0] || '';
    return Boolean(link);
  }, { timeout: 20000 }).toBe(true);
  return link.replace(/[).,>]+$/, '');
}

test('TC-NFR3-03-AS-01 sign up', async ({ page }) => {
  const email = emailFor('signup');
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Synthetic'); await page.locator('#lld_last').fill('Member');
  await page.locator('#lld_email').fill(email); await page.locator('#lld_password').fill(password); await page.locator('#lld_age').fill('30');
  await page.getByRole('button', { name: 'Create account', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Check your email', exact: true })).toBeVisible();
  await page.goto(await confirmationLink(email));
  await expect(page.getByRole('heading', { name: 'Email confirmed', exact: true })).toBeVisible();
});
