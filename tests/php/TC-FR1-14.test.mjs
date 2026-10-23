// TC-FR1-14 Result email path — php family. Fixtures: FX-MAIL-* (fixtures/mail/mail.json). The two endpoints run on PHP's
// built-in server behind the .htaccess emulator with the log transport (outbox = Mailpit stand-in; CI/staging read Mailpit
// the same way via MAILPIT_URL). Each test posts with its own forwarded client IP so the DEC-16 rate limit is per test.
// AS-05 (SMTP down) starts a second server whose config points SMTP at a closed local port. AS-06 scans every line the
// PHP server printed during the run (request log + error_log output, where the SCH-07 lines go).
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { templates } from '../lib/copy.mjs';
import { ROOT, TOOLS_DIR } from '../lib/paths.mjs';
import sentinel from '../../fixtures/sentinel-values.json' with { type: 'json' };
import logSchema from '../../schemas/endpoint-log.schema.json' with { type: 'json' };

let srv; let ipN = 0;
beforeAll(async () => { srv = await startHtaccessServer(); });
afterAll(async () => { await srv?.close(); });
const ENDPOINT = { protein: '/tools/submit-protein.php', 'diabetes-risk': '/tools/submit.php' };
const outboxDir = (s = srv) => join(s.docroot, 'tools', 'api', 'outbox');
const outbox = (s = srv) => existsSync(outboxDir(s)) ? readdirSync(outboxDir(s)).filter((f) => !f.startsWith('.')).sort() : [];
const nextIp = () => `10.14.${Math.floor(++ipN / 250)}.${(ipN % 250) + 1}`;
/** POST a fixture's inputs to its tool's endpoint; `_contentType` / `_repeat` are test directives, not payload keys. */
async function send(inputs, { ip = nextIp(), s = srv, contentType, origin = 'https://lifelinedaily.com' } = {}) {
  const { _contentType, _repeat, ...payload } = inputs;
  const before = outbox(s);
  const r = await fetch(s.url + (ENDPOINT[payload.tool] ?? ENDPOINT.protein), {
    method: 'POST', headers: { 'Content-Type': contentType || _contentType || 'application/json', Origin: origin, 'X-Forwarded-For': ip }, body: JSON.stringify(payload),
  });
  const text = await r.text(); let json = null; try { json = JSON.parse(text); } catch {}
  const fresh = outbox(s).filter((x) => !before.includes(x));
  return { status: r.status, json, text, mails: fresh.length, mail: fresh.length ? readFileSync(join(outboxDir(s), fresh[0]), 'utf8') : '' };
}
const subjectOf = (mail) => (/SUBJECT: (.*)/.exec(mail) || [])[1]?.trim();
const bodyOf = (mail) => mail.replace(/<!--[\s\S]*?-->/, '');
const T = { protein: templates('protein'), diabetes: templates('diabetes') };
/** Expected subject for a fixture's template name; `diabetes_standard` is the band template keyed from riskBand (Data Model §4.2). */
const subjectFor = (template, inputs) => {
  if (template.startsWith('protein_')) return T.protein[`${template}.subject`];
  const key = template === 'diabetes_standard' ? { Low: 'low', Moderate: 'moderate', High: 'high', 'Very high': 'veryhigh' }[inputs.riskBand] : template.replace('diabetes_', '');
  return T.diabetes[`${key}.subject`];
};
const jsonLogLines = (s = srv) => s.phpLog().map((l) => l.slice(l.indexOf('{'))).filter((l) => l.startsWith('{"ts"'));

describe('TC-FR1-14 result email path — server layer', () => {
  test('TC-FR1-14-AS-01', async () => {
    for (const id of ['FX-MAIL-01', 'FX-MAIL-02']) {
      const f = fixtures(id)[0];
      const r = await send(f.inputs);
      expect(r.status, `${id} status`).toBe(200);
      expect(r.json, `${id} body`).toEqual({ status: 'ok' });
      expect(r.mails, `${id}: exactly one message`).toBe(1);
      expect(subjectOf(r.mail), `${id}: template ${f.expected.template} (Data Model §4 / SRS §1.4)`).toBe(subjectFor(f.expected.template, f.inputs));
      expect(bodyOf(r.mail)).not.toContain('{{');
    }
  });

  test('TC-FR1-14-AS-02', async () => {
    for (const id of ['FX-MAIL-NEG-01', 'FX-MAIL-NEG-02', 'FX-MAIL-NEG-05', 'FX-MAIL-NEG-07']) {
      const f = fixtures(id)[0];
      const r = await send(f.inputs);
      expect(r.status, `${id} → 400`).toBe(400);
      expect(r.json?.status, `${id}: JSON error body`).toBe('error');
      expect(r.mails, `${id}: no mail`).toBe(0);
    }
    const big = fixtures('FX-MAIL-NEG-04')[0];
    expect(JSON.stringify(big.inputs).length, 'NEG-04 is over 8 KB').toBeGreaterThan(8192);
    const rb = await send(big.inputs);
    expect([400, 413], 'NEG-04 oversize body').toContain(rb.status);
    expect(rb.mails).toBe(0);
    // honeypot (DEC-16): 200 {status:'ok'}, no mail, exactly one SCH-07 line with outcome honeypot for this request
    const hp = fixtures('FX-MAIL-NEG-03')[0];
    const linesBefore = jsonLogLines().length;
    const rh = await send(hp.inputs);
    expect(rh.status).toBe(200); expect(rh.json).toEqual({ status: 'ok' }); expect(rh.mails).toBe(0);
    const added = jsonLogLines().slice(linesBefore).map((l) => JSON.parse(l));
    expect(added.filter((l) => l.outcome === 'honeypot'), 'one honeypot log line').toHaveLength(1);
    expect(Object.keys(added[0]).sort()).toEqual(['ip_hash', 'outcome', 'tool', 'ts']);
  });

  test('TC-FR1-14-AS-03', async () => {
    const f = fixtures('FX-MAIL-RATE-01')[0];
    const ip = nextIp(); const statuses = []; let last;
    for (let i = 0; i < f.inputs._repeat; i++) { last = await send(f.inputs, { ip }); statuses.push(last.status); }
    expect(statuses).toEqual(f.expected.statuses);
    expect(last.json).toEqual({ status: 'rate_limited' });
    expect(last.mails).toBe(0);
  });

  test('TC-FR1-14-AS-04', async () => {
    const f = fixtures('FX-MAIL-NEG-06')[0];
    const r = await send(f.inputs);
    expect(r.status).toBe(200); expect(r.mails).toBe(1);
    const body = bodyOf(r.mail);
    expect(body).toContain('&lt;img');
    expect(body).not.toContain('<img');
    expect(r.text, 'response never echoes the payload unescaped').not.toContain('<img');
  });

  test('TC-FR1-14-AS-05', async () => {
    // SMTP transport pointed at a closed local port: the send fails and both endpoints answer 502, not 500
    const down = await startHtaccessServer({ phpConfig: { transport: 'smtp', smtpHost: '127.0.0.1', smtpPort: 9 } });
    try {
      for (const id of ['FX-MAIL-01', 'FX-MAIL-02']) {
        const r = await send(fixtures(id)[0].inputs, { s: down });
        expect(r.status, `${id}: SMTP down → 502`).toBe(502);
        expect(r.json?.status, id).toBe('error');
        expect(r.mails).toBe(0);
      }
      const lines = jsonLogLines(down).map((l) => JSON.parse(l));
      expect(lines.filter((l) => l.outcome === 'smtp_error')).toHaveLength(2);
    } finally { await down.close(); }
  }, 120000);

  test('TC-FR1-14-AS-06', async () => {
    // runs last in file order: every SCH-07 line of the run validates field by field, and no server log line leaks
    const lines = jsonLogLines();
    expect(lines.length, 'the run produced SCH-07 lines').toBeGreaterThan(10);
    for (const raw of lines) {
      const l = JSON.parse(raw);
      expect(Object.keys(l).sort(), raw).toEqual(['ip_hash', 'outcome', 'tool', 'ts']);
      expect(logSchema.properties.tool.enum, raw).toContain(l.tool);
      expect(logSchema.properties.outcome.enum, raw).toContain(l.outcome);
      expect(l.ip_hash, raw).toMatch(/^[0-9a-f]{64}$/);
      expect(Number.isNaN(Date.parse(l.ts)), raw).toBe(false);
    }
    const outcomes = new Set(lines.map((l) => JSON.parse(l).outcome));
    for (const o of ['sent', 'rejected_validation', 'rejected_rate', 'honeypot']) expect(outcomes, `outcome ${o} seen`).toContain(o);
    const symptomKeys = [...readFileSync(resolve(TOOLS_DIR, 'diabetes-risk-tool.html'), 'utf8').matchAll(/data-sym="([a-z]+)"/g)].map((m) => m[1]);
    expect(symptomKeys.length).toBeGreaterThan(3);
    const forbidden = ['@', sentinel.firstName, sentinel.email, String(sentinel.weightKg), String(sentinel.waistCm), ...symptomKeys];
    for (const line of srv.phpLog()) for (const bad of forbidden) expect(line, `server log leaks "${bad}"`).not.toContain(bad);
  });

  test('TC-FR1-14-AS-07', async () => {
    for (const id of ['FX-MAIL-REN-01', 'FX-MAIL-REN-02', 'FX-MAIL-EMG-01', 'FX-MAIL-SYM-01']) {
      const f = fixtures(id)[0];
      const r = await send(f.inputs);
      expect(r.status, id).toBe(200); expect(r.mails, id).toBe(1);
      expect(subjectOf(r.mail), `${id}: ${f.expected.template}`).toBe(subjectFor(f.expected.template, f.inputs));
      if (f.expected.no_digits_g) expect(bodyOf(r.mail), `${id}: no digits+g`).not.toMatch(/\d+(\.\d+)?\s?(g\b|gram|g\/kg)/i);
      if (f.expected.no_percent) expect(bodyOf(r.mail), `${id}: no percent`).not.toContain('%');
    }
  });

  test('TC-FR1-14-AS-08', () => {
    // no mailing-list or other outbound call: the only network client in the endpoint code is PHPMailer's SMTP transport.
    // (Container egress capture is the CI form of this check — DEC proposal in the PR; this is the static form.)
    const files = ['submit-protein.php', 'submit.php', 'api/mailer.php', 'api/ratelimit.php', 'api/render.php', 'api/validate.php', 'api/templates.php', 'api/templates-protein.php', 'api/config.example.php'];
    const egress = /curl_init|curl_exec|fsockopen|stream_socket_client|file_get_contents\(\s*['"]https?:|fopen\(\s*['"]https?:|new\s+SoapClient|mail\(/;
    for (const f of files) expect(readFileSync(resolve(TOOLS_DIR, f), 'utf8'), `${f} makes no outbound call`).not.toMatch(egress);
    expect(readFileSync(resolve(TOOLS_DIR, 'api', 'mailer.php'), 'utf8')).toMatch(/isSMTP\(\)/);
    expect(readFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'api', 'config.example.php'), 'utf8')).toMatch(/'bcc_owner'\s*=>\s*''/);
  });
});
