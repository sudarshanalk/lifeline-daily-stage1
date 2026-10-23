// TC-FR1-11 php family: AS-02 FX-MAIL-EMG-01 → diabetes_emergency template, no % in body, required urgentCare phrase;
// AS-06 server layer — the emergency template is used even when a forged riskPercent is supplied (DEC-09 second layer).
// The endpoint runs on PHP's built-in server behind the .htaccess emulator with the log transport (outbox = Mailpit stand-in).
import { afterAll, beforeAll, describe, expect, test } from 'vitest';
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import phrases from '../../fixtures/required-phrases.json' with { type: 'json' };

let srv;
beforeAll(async () => { srv = await startHtaccessServer(); });
afterAll(async () => { await srv?.close(); });
const outboxDir = () => join(srv.docroot, 'tools', 'api', 'outbox');
const outbox = () => existsSync(outboxDir()) ? readdirSync(outboxDir()).filter((f) => !f.startsWith('.')).sort() : [];
const post = (body) => fetch(srv.url + '/tools/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: 'https://lifelinedaily.com' }, body: JSON.stringify(body) });
const sendAndRead = async (body) => { const before = outbox(); const r = await post(body); const after = outbox(); const fresh = after.filter((x) => !before.includes(x)); return { status: r.status, mails: fresh.length, mail: fresh.length ? readFileSync(join(outboxDir(), fresh[0]), 'utf8') : '' }; };
const bodyOf = (mail) => mail.replace(/<!--[\s\S]*?-->/, ''); // strip the outbox header comment (TO/SUBJECT/TIME)

describe('TC-FR1-11 symptom escalation — server layer', () => {
  test('TC-FR1-11-AS-02', async () => {
    const f = fixtures('FX-MAIL-EMG-01')[0];
    const r = await sendAndRead(f.inputs);
    expect(r.status).toBe(f.expected.status); expect(r.mails).toBe(f.expected.mails);
    const body = bodyOf(r.mail);
    expect(r.mail, 'emergency template subject').toMatch(/SUBJECT: .*urgent care today/);
    expect(body, 'no percentage in the emergency email').not.toContain('%');
    expect(body, 'required urgent-care phrase').toContain(phrases.urgentCare);
    expect(body).not.toContain('{{');
  });

  test('TC-FR1-11-AS-06', async () => {
    // server layer: forged riskPercent/riskBand with emergency:true still selects the emergency template (FX-MAIL-EMG-01 carries 3.2 / Low)
    const f = fixtures('FX-MAIL-EMG-01')[0];
    expect(f.inputs.emergency).toBe(true); expect(typeof f.inputs.riskPercent).toBe('number');
    const r = await sendAndRead({ ...f.inputs, riskPercent: 42.0, riskBand: 'Very high' });
    expect(r.status).toBe(200); expect(r.mails).toBe(1);
    const body = bodyOf(r.mail);
    expect(r.mail).toMatch(/SUBJECT: .*urgent care today/);
    expect(body).not.toContain('42'); expect(body).not.toContain('%'); expect(body).not.toMatch(/Very high/);
    // and a symptomatic (non-emergency) payload gets the symptomatic template with the percentage present
    const s = fixtures('FX-MAIL-SYM-01')[0];
    const r2 = await sendAndRead(s.inputs);
    expect(r2.status).toBe(200); expect(r2.mail).toMatch(/SUBJECT: .*symptoms worth checking/i); expect(bodyOf(r2.mail)).toMatch(/4\.2\s?%/);
  });
});
