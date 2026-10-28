// Staging mail catcher (Build Method P0-4): lld-tools/staging-mail.php builders, run in PHP CLI without WordPress.
// The live check (a test send on staging lands in the private log and nothing leaves) is recorded in the tracker.
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { ROOT } from '../lib/paths.mjs';

const PHP_FILE = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'staging-mail.php');

function call(fn, ...args) {
  const code = `define('ABSPATH', '/'); require ${JSON.stringify(PHP_FILE.replace(/\\/g, '/'))}; $a = json_decode(stream_get_contents(STDIN), true); echo json_encode(${fn}(...$a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`;
  return JSON.parse(execFileSync('php', ['-r', code], { input: JSON.stringify(args), encoding: 'utf8' }));
}

describe('staging mail catcher', () => {
  test('only staging catches; production, local, ci, unset and typos are left alone', () => {
    expect(call('lld_mail_catching', 'staging')).toBe(true);
    for (const env of ['production', 'local', 'ci', 'Staging', 'stg', '', null]) expect(call('lld_mail_catching', env)).toBe(false);
  });

  test('a captured message keeps recipients, subject, headers and body; attachments by name only', () => {
    const r = call('lld_mail_record', { to: 'a@example.com, b@example.com', subject: 'Code', message: 'Your code is 123456.', headers: "From: X <x@example.com>\nReply-To: y@example.com", attachments: ['/home/u/private/photo.jpg'] }, '2026-10-26T10:00:00+00:00');
    expect(r).toEqual({ time: '2026-10-26T10:00:00+00:00', to: ['a@example.com', 'b@example.com'], subject: 'Code', headers: ['From: X <x@example.com>', 'Reply-To: y@example.com'], attachments: ['photo.jpg'], message: 'Your code is 123456.' });
    expect(call('lld_mail_record', { to: ['c@example.com'], subject: 'S', message: 'M' }, 't').to).toEqual(['c@example.com']);
  });

  test('only messages whose every recipient is on the allow-list are delivered', () => {
    const allow = 'owner@example.com, Admin <admin@example.com>';
    expect(call('lld_mail_allowed', ['owner@example.com'], allow)).toBe(true);
    expect(call('lld_mail_allowed', ['OWNER@Example.com'], allow)).toBe(true);
    expect(call('lld_mail_allowed', ['Owner <owner@example.com>', 'admin@example.com'], allow)).toBe(true);
    // a real member on the copied database, mixed in or alone, is never emailed
    expect(call('lld_mail_allowed', ['owner@example.com', 'member@example.com'], allow)).toBe(false);
    expect(call('lld_mail_allowed', ['member@example.com'], allow)).toBe(false);
    // no list, an empty list, a malformed list or no recipients: nothing is delivered
    for (const list of ['', ' , ', 'not-an-address']) expect(call('lld_mail_allowed', ['owner@example.com'], list)).toBe(false);
    expect(call('lld_mail_allowed', [], allow)).toBe(false);
    expect(call('lld_mail_allowed', [''], allow)).toBe(false);
  });
});
