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
  await page.locator('#lld_password').press('Enter');
  await page.waitForURL((url) => !url.pathname.includes('/members/sign-in'));
}

export async function asOwner(page) {
  await page.request.post('/wp-json/lld-members/v1/test/logout');
  await page.context().clearCookies();
  const res = await page.request.post('/wp-json/lld-members/v1/test/owner');
  if (!res.ok()) throw new Error(`owner sign-in ${res.status()} ${await res.text()}`);
}

// Owner steps in wp-admin: the edit and approval screens are plain HTML forms, so the tests fetch only the document.
// In WebKit the admin page's scripts, styles and background requests (heartbeat, compression test, emoji loader)
// open many parallel connections, and the single-process CI server (php -S) stalls on one of them, so the next
// navigation never completes. Member pages are never touched: only requests made by a /wp-admin/ document are cut.
// Buttons are clicked for real, never forced: in WebKit a forced click on these pages can report success without
// submitting the form.
export const ADMIN = { waitUntil: 'domcontentloaded' };
const lean = new WeakSet();

async function leanAdmin(page) {
  if (lean.has(page)) return;
  lean.add(page);
  await page.route('**/*', (route) => {
    const request = route.request();
    let from = '';
    try {
      from = request.frame().url();
    } catch {
      from = '';
    }
    if (request.resourceType() !== 'document' && from.includes('/wp-admin/')) return route.abort();
    return route.continue();
  });
}

export async function openAdmin(page, path) {
  await leanAdmin(page);
  await page.goto(path, ADMIN);
  if (page.url().includes('pmpro-wizard')) {
    await page.goto(path, ADMIN);
  }
}

export async function publishItem(page, type, title, body, tags = '') {
  await openAdmin(page, `/wp-admin/post-new.php?post_type=${type}`);
  await page.locator('#title').fill(title, { force: true });
  await page.locator('#content').fill(body, { force: true });
  if (tags) await page.locator('#lld_tags').fill(tags, { force: true });
  await page.locator('#publish').click();
  await page.waitForURL(/post=\d+/, ADMIN);
  return Number(new URL(page.url()).searchParams.get('post'));
}

export async function editBody(page, id, body) {
  await openAdmin(page, `/wp-admin/post.php?post=${id}&action=edit`);
  await page.locator('#content').fill(body, { force: true });
  await page.locator('#publish').click();
  await page.locator('#message').waitFor();
}

export async function approveTitle(page, title) {
  await openAdmin(page, '/wp-admin/admin.php?page=lld-approvals');
  const qual = page.locator('#lld_qualifications');
  if (await qual.count()) await qual.fill('Test clinical record for the automated check', { force: true });
  const row = page.locator('tr', { hasText: title });
  const box = row.locator('input[type="checkbox"]');
  if (await box.count()) await box.check({ force: true });
  await row.getByRole('button', { name: 'Approve' }).click();
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
