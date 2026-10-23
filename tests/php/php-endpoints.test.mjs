// php-integration family (NFR1-09 harness): the two endpoints run on PHP's built-in server behind the .htaccess emulator
// with a generated config (transport `log` → the outbox directory stands in for Mailpit; nothing leaves the machine).
// Wave-1 scope is the harness itself; the clinical/email assertions (TC-FR1-08-AS-04…, TC-FR1-14-*) attach here in
// waves 2–3 and read the outbox the same way Mailpit's API would be read (MAILPIT_URL) in CI.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import sentinel from '../../fixtures/sentinel-values.json' with { type: 'json' };

let srv;
beforeAll(async () => { srv = await startHtaccessServer(); });
afterAll(async () => { await srv?.close(); });
const outbox = () => { const d = join(srv.docroot, 'tools', 'api', 'outbox'); return existsSync(d) ? readdirSync(d).filter((f) => !f.startsWith('.')) : []; };
const post = (path, body, headers = {}) => fetch(srv.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', ...headers }, body: typeof body === 'string' ? body : JSON.stringify(body) });

describe('SUPPORT-NFR1-09 php-integration harness', () => {
  test('SUPPORT-NFR1-09-PHP-01 endpoints answer through the stack and never expose the backend folder', async () => {
    expect((await fetch(srv.url + '/tools/api/config.php')).status).toBe(403);
    expect((await fetch(srv.url + '/tools/api/mailer.php')).status).toBe(403);
    expect((await fetch(srv.url + '/tools/submit.php')).status).toBe(405);           // GET not allowed
    expect((await post('/tools/submit.php', 'not json')).status).toBe(400);
    expect((await post('/tools/submit-protein.php', 'not json')).status).toBe(400);
  });

  test('SUPPORT-NFR1-09-PHP-02 honeypot is a silent 200 with no mail', async () => {
    const before = outbox().length;
    const r = await post('/tools/submit-protein.php', { tool: 'protein', email: sentinel.email, consent: true, company: 'bot', targetG: 90 });
    expect(r.status).toBe(200);
    expect((await r.json()).status).toBe('ok');
    expect(outbox().length).toBe(before);
  });

  test('SUPPORT-NFR1-09-PHP-03 a valid protein payload produces exactly one message in the sink', async () => {
    const before = outbox().length;
    const r = await post('/tools/submit-protein.php', { tool: 'protein', firstName: sentinel.firstName, email: sentinel.email, consent: true, suppressed: false, targetG: 95, lowG: 80, highG: 110, gPerKg: 1.2, perMeal: 32, meals: 3, sources: ['IOM RDA'], timestamp: new Date().toISOString(), company: '' });
    expect(r.status, await r.clone().text()).toBe(200);
    const files = outbox();
    expect(files.length).toBe(before + 1);
    const mail = readFileSync(join(srv.docroot, 'tools', 'api', 'outbox', files.at(-1)), 'utf8');
    expect(mail).toContain(sentinel.email);
    expect(mail).toMatch(/protein target/i);
  });
});
