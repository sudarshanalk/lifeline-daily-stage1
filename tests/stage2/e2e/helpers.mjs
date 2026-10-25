const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';

export async function createMember(request, fields) {
  const res = await request.post('/wp-json/lld-members/v1/test/member', { data: fields });
  if (!res.ok()) throw new Error(`create member ${res.status()} ${await res.text()}`);
  return res.json();
}

export async function signIn(page, email, password) {
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await page.context().clearCookies();
  await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

export async function restNonce(page) {
  return page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
}

export async function mailCode(email) {
  let last = '';
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    const messages = (list.messages || []).filter((message) => JSON.stringify(message).includes(email));
    messages.sort((a, b) => new Date(b.Created || b.created || 0) - new Date(a.Created || a.created || 0));
    if (messages.length) {
      const id = messages[0].ID || messages[0].Id || messages[0].id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      last = full.Text || full.HTML || '';
      const found = last.match(/\b(\d{6})\b/);
      if (found) return found[1];
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No sign-in code for ${email}. Last body: ${last.slice(0, 180)}`);
}
