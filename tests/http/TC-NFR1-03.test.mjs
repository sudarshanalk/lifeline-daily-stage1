// TC-NFR1-03 Security — sec + http + php families (DEC-15, DEC-16, DEC-28, DEC-35). Fixtures: FX-MAIL-NEG-06, FX-MAIL-RATE-01.
// AS-01 no secret-looking literal in the served HTML/JS (gitleaks runs as the CI `security` job; here when installed);
// AS-02 OWASP ZAP baseline and AS-03 WPScan need the Docker stack / a WordPress target (OI-4 / OI-1) — skipped with reason;
// AS-04 security headers on the served pages; AS-05 the NEG-06 payload is never echoed unescaped in a response or an e-mail;
// AS-06 the backend folder and endpoints' sources answer 403; AS-07 the DEC-16 rate limit (TC-FR1-14-AS-03) holds.
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { ROOT } from '../lib/paths.mjs';

let srv;
beforeAll(async () => { srv = await startHtaccessServer({ harnessDir: resolve(ROOT, 'tests', 'e2e', 'harness') }); });
afterAll(async () => { await srv?.close(); });
const PAGES = ['/tools/protein-requirement-tool.html', '/tools/diabetes-risk-tool.html', '/tools/health-tools-hub.html', '/wp-content/mu-plugins/lld-tools/assets/lld-tool-frame.js'];
const HARNESS_PAGES = ['/__harness/landing.html'];
const outboxDir = () => join(srv.docroot, 'tools', 'api', 'outbox');
const outbox = () => existsSync(outboxDir()) ? readdirSync(outboxDir()).filter((f) => !f.startsWith('.')).sort() : [];
const post = (path, body, ip, extra = {}) => fetch(srv.url + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com', 'X-Forwarded-For': ip, ...extra }, body: JSON.stringify(body) });

describe('TC-NFR1-03 security', () => {
  test('TC-NFR1-03-AS-01', async () => {
    const re = /smtp|passw|apikey|api_key|secret/i;
    for (const p of PAGES) {
      const r = await fetch(srv.url + p); expect(r.status, p).toBe(200);
      const text = await r.text();
      const hits = [...text.matchAll(/.{0,40}(smtp|passw|apikey|api_key|secret).{0,40}/gi)].map((m) => m[0].trim());
      expect(hits, `${p}: secret-looking literals`).toEqual([]);
      expect(re.test(text), p).toBe(false);
    }
    // gitleaks: the CI `security` job runs it on every PR; locally only when the binary is installed
    const gl = spawnSync('gitleaks', ['detect', '--source', ROOT, '--no-banner', '--redact', '--exit-code', '2'], { encoding: 'utf8' });
    if (gl.error) console.info('gitleaks not installed locally — the CI security job is the assertion'); else expect(gl.status, gl.stdout + gl.stderr).toBe(0);
    // never a filled config or key file in the tree
    expect(existsSync(resolve(ROOT, 'site', 'public_html', 'tools', 'api', 'config.php'))).toBe(false);
  });

  test('TC-NFR1-03-AS-02', (ctx) => {
    ctx.skip('OWASP ZAP baseline needs the Docker stack (OI-4); runs as the CI security job once the stack exists — DEC proposal in the NFR1-03 PR');
  });

  test('TC-NFR1-03-AS-03', (ctx) => {
    ctx.skip('WPScan needs the WordPress staging target (OI-1); recorded as a deferral in evidence');
  });

  test('TC-NFR1-03-AS-04', async () => {
    // the six URLs of the TC are home, hub, two landing pages and the two tool pages; the WordPress four are measured on the
    // harness landing double until a staging origin exists (OI-1) — every served page carries the root .htaccess.lld headers
    const urls = [...PAGES.filter((p) => p.endsWith('.html')), ...HARNESS_PAGES];
    for (const p of urls) {
      const r = await fetch(srv.url + p); expect(r.status, p).toBe(200);
      const h = (n) => r.headers.get(n) || '';
      expect(h('strict-transport-security'), `${p}: HSTS`).toMatch(/max-age=31536000/);
      expect(h('content-security-policy'), `${p}: CSP`).toMatch(/frame-(src|ancestors)/);
      expect(h('x-content-type-options'), `${p}: nosniff`).toBe('nosniff');
      expect(h('referrer-policy'), `${p}: Referrer-Policy`).toBe('strict-origin-when-cross-origin');
      expect(h('permissions-policy'), `${p}: Permissions-Policy`).toMatch(/camera=\(\)/);
    }
  });

  test('TC-NFR1-03-AS-05', async () => {
    const neg = fixtures('FX-MAIL-NEG-06')[0];
    const diabetesShape = { ...fixtures('FX-MAIL-02')[0].inputs, firstName: neg.inputs.firstName };
    for (const [path, body] of [['/tools/submit-protein.php', neg.inputs], ['/tools/submit.php', diabetesShape]]) {
      const before = outbox();
      const r = await post(path, body, '10.3.0.1');
      const text = await r.text();
      expect(r.status, path).toBe(200);
      expect(text, `${path}: response never echoes the payload unescaped`).not.toContain('<img');
      const fresh = outbox().filter((x) => !before.includes(x));
      expect(fresh.length, `${path}: one e-mail`).toBe(1);
      const mail = readFileSync(join(outboxDir(), fresh[0]), 'utf8');
      expect(mail, `${path}: e-mail escapes the payload`).not.toContain('<img');
      expect(mail).toContain('&lt;img');
    }
    // and the validation-error path never echoes raw input either
    const bad = await post('/tools/submit-protein.php', { ...neg.inputs, email: '<img src=x onerror=alert(1)>@x' }, '10.3.0.2');
    expect(await bad.text()).not.toContain('<img');
  });

  test('TC-NFR1-03-AS-06', async () => {
    for (const p of ['/tools/', '/tools/api/config.php', '/tools/api/mailer.php', '/tools/api/templates.php', '/tools/api/templates-protein.php', '/tools/api/ratelimit.php', '/tools/api/validate.php', '/tools/api/render.php', '/tools/api/', '/tools/api/outbox/', '/tools/api/state/']) {
      const r = await fetch(srv.url + p);
      expect(r.status, `${p} → 403`).toBe(403);
    }
  });

  test('TC-NFR1-03-AS-07', async () => {
    // TC-FR1-14-AS-03: five accepted, the sixth rate-limited, per IP per endpoint (DEC-16)
    const f = fixtures('FX-MAIL-RATE-01')[0];
    const { _repeat, ...body } = f.inputs;
    const statuses = [];
    for (let i = 0; i < _repeat; i++) statuses.push((await post('/tools/submit-protein.php', body, '10.3.9.9')).status);
    expect(statuses).toEqual(f.expected.statuses);
    expect((await post('/tools/submit.php', fixtures('FX-MAIL-02')[0].inputs, '10.3.9.9')).status, 'the other endpoint has its own bucket').toBe(200);
  });
});
