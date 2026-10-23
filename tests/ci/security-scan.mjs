#!/usr/bin/env node
// security (docs/04 §1): quick scan until NFR1-03 lands its full page — (1) the backend folder, credentials, outbox and
// state are 403 through the real .htaccess rules; (2) the security headers of DEC-35 are served; (3) no filled config.php,
// private key or credential-looking literal is tracked in Git (gitleaks runs beside this in CI).
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT } from '../lib/paths.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

export async function securityScan() {
  const problems = [];
  const srv = await startHtaccessServer();
  try {
    for (const p of ['/tools/api/config.php', '/tools/api/config.example.php', '/tools/api/mailer.php', '/tools/api/outbox/', '/tools/api/state/', '/tools/api/PHPMailer/PHPMailer.php', '/tools/api/.htaccess', '/tools/']) {
      const r = await fetch(srv.url + p);
      if (r.status !== 403) problems.push(`${p} answered ${r.status}, expected 403`);
    }
    const r = await fetch(srv.url + '/tools/protein-requirement-tool.html');
    for (const [h, re] of [['strict-transport-security', /max-age=31536000/], ['x-content-type-options', /nosniff/], ['referrer-policy', /strict-origin-when-cross-origin/], ['permissions-policy', /camera=\(\)/], ['content-security-policy', /frame-ancestors/]]) {
      if (!re.test(r.headers.get(h) || '')) problems.push(`tool page missing ${h}`);
    }
  } finally { await srv.close(); }
  const tracked = execSync('git ls-files', { cwd: ROOT, encoding: 'utf8' }).split(/\r?\n/).filter(Boolean);
  for (const f of tracked) {
    if (/tools\/api\/config\.php$/.test(f)) problems.push(`${f} is tracked (credentials file)`);
    if (/\.(pem|key|p12|pfx)$/.test(f) || /id_(rsa|ed25519)/.test(f)) problems.push(`${f} looks like a private key`);
    if (/\.(php|js|mjs|json|yml|yaml|env|sh|ps1)$/.test(f) && !f.startsWith('node_modules')) {
      const text = readFileSync(resolve(ROOT, f), 'utf8');
      const m = text.match(/(password|passwd|secret|api[_-]?key|token)['"]?\s*(=>|=|:)\s*['"][^'"$\{][^'"]{7,}['"]/i);
      if (m && !/example|placeholder|getenv|\$\{|<redacted>/i.test(m[0])) problems.push(`${f}: credential-looking literal "${m[0].slice(0, 40)}…"`);
    }
  }
  return { ok: problems.length === 0, problems };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const r = await securityScan();
  for (const p of r.problems) console.error('security: ' + p);
  console.log(r.ok ? 'security: OK (quick scan; NFR1-03 adds the full page)' : 'security: FAIL');
  process.exit(r.ok ? 0 : 1);
}
