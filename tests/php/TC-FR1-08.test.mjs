// TC-FR1-08 php family: AS-04 FX-MAIL-REN-01, FX-MAIL-REN-02 → renal template, subject+body match no digits+g; AS-07 server log lines for
// the run contain no numeric result and no email address. The PHP endpoint runs behind the .htaccess emulator with the
// log transport (outbox = Mailpit stand-in; CI/staging read Mailpit the same way via MAILPIT_URL when the stack exists).
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import sentinel from '../../fixtures/sentinel-values.json' with { type: 'json' };

const DIGITS_G = /\d+(\.\d+)?\s?(g\b|gram|g\/kg)/i;
let srv;
beforeAll(async () => { srv = await startHtaccessServer(); });
afterAll(async () => { await srv?.close(); });
const outboxDir = () => join(srv.docroot, 'tools', 'api', 'outbox');
const outbox = () => existsSync(outboxDir()) ? readdirSync(outboxDir()).filter((f) => !f.startsWith('.')).sort() : [];
const post = (body) => fetch(srv.url + '/tools/submit-protein.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com' }, body: JSON.stringify(body) });

describe('TC-FR1-08 renal suppression — server layer', () => {
  test('TC-FR1-08-AS-04', async () => {
    const ren = fixtures('FX-MAIL-REN-*');
    expect(ren.map((f) => f.id), 'the TC names both renal mail fixtures').toEqual(['FX-MAIL-REN-01', 'FX-MAIL-REN-02']);
    for (const f of ren) {
      const before = outbox();
      const r = await post(f.inputs);
      expect(r.status, f.id).toBe(f.expected.status);
      const after = outbox();
      expect(after.length - before.length, `${f.id}: exactly one message`).toBe(f.expected.mails);
      const mail = readFileSync(join(outboxDir(), after.filter((x) => !before.includes(x))[0]), 'utf8');
      // renal template: no digits followed by g/gram/g/kg anywhere in subject or body, even when numbers were forged (REN-02)
      expect(mail, `${f.id}: no digits+g`).not.toMatch(DIGITS_G);
      expect(mail, `${f.id}: renal template wording`).toMatch(/kidney/i);
      expect(mail, `${f.id}: not the standard template`).not.toMatch(/daily protein target is about/i);
      expect(mail).not.toContain('{{');
    }
  });

  test('TC-FR1-08-AS-07', async () => {
    // after the run above: server log lines contain no numeric result and no email address
    const logs = srv.phpLog().join('\n');
    expect(logs).not.toContain(sentinel.email);
    expect(logs).not.toMatch(/targetG|perMeal|gPerKg|\b(150|75|105)\s?g\b/);
    // state files (rate limit) hold no payload fields either
    const stateDir = join(srv.docroot, 'tools', 'api', 'state');
    for (const f of existsSync(stateDir) ? readdirSync(stateDir).filter((x) => !x.startsWith('.')) : []) {
      const text = readFileSync(join(stateDir, f), 'latin1');
      expect(text, f).not.toContain(sentinel.email); expect(text, f).not.toMatch(/targetG|"gPerKg"/);
    }
  });
});
