import { randomBytes } from 'node:crypto';
import { expect } from '@playwright/test';
import { createMember, signIn } from '../../stage2/e2e/helpers.mjs';

export const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
export const emailFor = (part) => `p311-${part}-${randomBytes(4).toString('hex')}@test.lifelinedaily.invalid`;
export { createMember, signIn };

export async function fixture(request, kind, stamp = randomBytes(4).toString('hex')) {
  const response = await request.post('/wp-json/lld-members/v1/test/fixture', { data: { kind, stamp } });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function startTrack(page, slug = 'healthy-weight') {
  await page.goto('/members/tracks/');
  const nonce = await page.locator('input[name="lld_track_nonce"]').first().inputValue();
  const response = await page.request.post('/members/tracks/', { form: { lld_track_nonce: nonce, lld_track: slug }, maxRedirects: 0 });
  expect([200, 302, 303]).toContain(response.status());
}

export async function serveHealthyWeightTool(page) {
  const { readFileSync } = await import('node:fs');
  const { resolve, dirname } = await import('node:path');
  const { fileURLToPath } = await import('node:url');
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
  const origin = new URL(process.env.LLD_WP_URL || 'http://127.0.0.1:8090').origin;
  await page.route('**/tools/**', async (route) => {
    const url = route.request().url();
    const file = url.includes('lld-members-link.js') ? 'lld-members-link.js' : url.includes('status.json') ? 'status.json' : 'healthy-weight-tool.html';
    let body = readFileSync(resolve(root, 'site/public_html/tools', file));
    if (file.endsWith('.html')) body = Buffer.from(body.toString('utf8').replaceAll('__LLD_ORIGIN__', origin));
    await route.fulfill({ status: 200, contentType: file.endsWith('.js') ? 'text/javascript' : file.endsWith('.json') ? 'application/json' : 'text/html', body });
  });
}
