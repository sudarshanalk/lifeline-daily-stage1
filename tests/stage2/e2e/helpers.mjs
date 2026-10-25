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

export async function asOwner(page) {
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await page.context().clearCookies();
  const res = await page.request.post('/wp-json/lld-members/v1/test/owner');
  if (!res.ok()) throw new Error(`owner sign-in ${res.status()} ${await res.text()}`);
}

async function openAdmin(page, path) {
  await page.goto(path);
  if (page.url().includes('pmpro-wizard')) {
    await page.goto(path);
  }
}

export async function publishItem(page, type, title, body, tags = '') {
  await openAdmin(page, `/wp-admin/post-new.php?post_type=${type}`);
  await page.locator('#title').fill(title, { force: true });
  await page.locator('#content').fill(body, { force: true });
  if (tags) await page.locator('#lld_tags').fill(tags, { force: true });
  await page.locator('#publish').click({ force: true });
  await page.waitForURL(/post=\d+/);
  return Number(new URL(page.url()).searchParams.get('post'));
}

export async function editBody(page, id, body) {
  await openAdmin(page, `/wp-admin/post.php?post=${id}&action=edit`);
  await page.locator('#content').fill(body, { force: true });
  await page.locator('#publish').click({ force: true });
  await page.locator('#message').waitFor();
}

export async function approveTitle(page, title) {
  await openAdmin(page, '/wp-admin/admin.php?page=lld-approvals');
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check', { force: true });
  const row = page.locator('tr', { hasText: title });
  const box = row.locator('input[type="checkbox"]');
  if (await box.count()) await box.check({ force: true });
  await row.getByRole('button', { name: 'Approve' }).click({ force: true });
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
