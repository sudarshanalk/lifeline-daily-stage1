// TC-NFR1-14 Email deliverability and sandbox — static + dns + php families (DEC-27). No fixtures.
// AS-01 the mail config reads SMTP from the environment, staging/CI point at Mailpit and no production SMTP host is tracked;
// AS-02 the sending domain publishes SPF, a resolvable DKIM selector and a DMARC policy (live DNS; skipped offline);
// AS-03 every e-mail template renders text + HTML, the HTML passes the e-mail lint, and subjects equal copy/templates.json.
import { describe, expect, test } from 'vitest';
import { execSync, spawnSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import dns from 'node:dns/promises';
import { JSDOM } from 'jsdom';
import { ROOT, TOOLS_DIR } from '../lib/paths.mjs';

const deck = JSON.parse(readFileSync(resolve(ROOT, 'copy', 'templates.json'), 'utf8'));
const DOMAIN = process.env.LLD_MAIL_DOMAIN || 'lifelinedaily.com';
const DKIM_SELECTORS = (process.env.LLD_DKIM_SELECTORS || 'default,hostingermail1,hostingermail2,titan1,google,selector1,selector2,mail').split(',');

/** Render one template through the real PHP code paths (render.php for diabetes, the protein endpoint's fill for protein). */
function renderTemplate(slug, key) {
  const php = slug === 'diabetes'
    ? `require '${TOOLS_DIR.replace(/\\/g, '/')}/api/render.php'; $p = json_decode($argv[1], true); echo json_encode(render($p));`
    : `$T = require '${TOOLS_DIR.replace(/\\/g, '/')}/api/templates-protein.php'; $tpl = $T['${key}']; $data = ['firstName'=>'there','disclaimer'=>$T['_shared']['disclaimer'],'year'=>date('Y'),'targetG'=>95,'perMeal'=>32,'meals'=>3,'gPerKg'=>1.2,'rangeBlock'=>'','sources'=>''];
       $fill = function (string $s) use ($data): string { return preg_replace_callback('/\\{\\{(\\w+)\\}\\}/', function ($m) use ($data) { return isset($data[$m[1]]) ? (string)$data[$m[1]] : ''; }, $s); };
       echo json_encode(['subject' => $fill($tpl['subject']), 'body' => $fill($tpl['body'])]);`;
  const payload = { low: { riskPercent: 3.1, riskBand: 'Low' }, moderate: { riskPercent: 7.5, riskBand: 'Moderate' }, high: { riskPercent: 14.2, riskBand: 'High' }, veryhigh: { riskPercent: 24.0, riskBand: 'Very high' }, symptomatic: { riskPercent: 7.5, riskBand: 'Moderate', symptomatic: true }, emergency: { emergency: true } }[key] || {};
  const r = spawnSync('php', ['-r', php, JSON.stringify({ firstName: 'there', bmi: 26.0, whtr: null, idrsScore: null, ...payload })], { encoding: 'utf8' });
  if (r.status !== 0) throw new Error(r.stderr);
  return JSON.parse(r.stdout);
}

/** Minimal e-mail HTML lint (no html-validate dependency in package.json — DEC proposal in the PR): well-formed per jsdom,
 *  no script/iframe/form/external stylesheet, every img has alt, no unfilled token, styles inline only. */
function lintEmailHtml(html) {
  const problems = [];
  const doc = new JSDOM(`<!doctype html><html><body>${html}</body></html>`).window.document;
  for (const tag of ['script', 'iframe', 'form', 'link', 'object', 'embed']) if (doc.querySelector(tag)) problems.push(`<${tag}> in e-mail`);
  for (const img of doc.querySelectorAll('img')) if (!img.getAttribute('alt')) problems.push('img without alt');
  if (/\{\{\w+\}\}/.test(html)) problems.push('unfilled {{token}}');
  if (/<style/i.test(html)) problems.push('<style> block (inline styles only)');
  // re-serialising and re-parsing must yield the same text: a crude unclosed-tag check
  const text1 = doc.body.textContent.replace(/\s+/g, ' ').trim();
  const text2 = new JSDOM(`<!doctype html><html><body>${doc.body.innerHTML}</body></html>`).window.document.body.textContent.replace(/\s+/g, ' ').trim();
  if (text1 !== text2) problems.push('markup not stable under re-parse (unclosed tag?)');
  return problems;
}

describe('TC-NFR1-14 e-mail deliverability and sandbox', () => {
  test('TC-NFR1-14-AS-01', () => {
    const cfg = readFileSync(resolve(TOOLS_DIR, 'api', 'config.example.php'), 'utf8');
    for (const v of ['LLD_MAIL_TRANSPORT', 'LLD_SMTP_HOST', 'LLD_SMTP_PORT', 'LLD_SMTP_USER', 'LLD_SMTP_PASS']) expect(cfg, `config reads ${v} from the environment`).toContain(`getenv('${v}')`);
    expect(cfg).toMatch(/'host'\s*=>\s*getenv\('LLD_SMTP_HOST'\) \?: ''/);
    // no production SMTP host, username or password literal anywhere in the tracked tree (the filled config.php is never committed)
    const tracked = execSync('git ls-files', { cwd: ROOT, encoding: 'utf8' }).split(/\r?\n/).filter((f) => f && !f.startsWith('node_modules/') && /\.(php|env|example|yml|yaml|json|md|sh|py|mjs)$/.test(f) && !f.includes('PHPMailer/'));
    const prodHost = /smtp\.(hostinger|titan|gmail|office365|sendgrid|mailgun|zoho)\.[a-z]+|smtp-relay|email-smtp\./i;
    for (const f of tracked) expect(readFileSync(resolve(ROOT, f), 'utf8'), `${f} names a production SMTP host`).not.toMatch(prodHost);
    expect(existsSync(resolve(TOOLS_DIR, 'api', 'config.php')), 'filled config.php is not in the repository').toBe(false);
    // staging / CI: the sandbox is Mailpit (DEC-27). The environment template either leaves SMTP unset (harness uses the log
    // transport) or points at Mailpit; the local stack and CI compose files name mailpit.
    const stagingEnv = readFileSync(resolve(ROOT, 'deploy', 'environments', 'staging.env.example'), 'utf8');
    const m = /LLD_SMTP_HOST=(\S+)/.exec(stagingEnv);
    if (m) expect(m[1], 'staging SMTP host is Mailpit').toMatch(/mailpit/i);
    const compose = ['docker-compose.yml', 'tools/local/local-stack.ps1'].filter((f) => existsSync(resolve(ROOT, f))).map((f) => readFileSync(resolve(ROOT, f), 'utf8')).join('\n');
    expect(compose, 'local stack / CI mail sink is Mailpit').toMatch(/mailpit/i);
    expect(readFileSync(resolve(ROOT, 'tests', 'lib', 'htaccess-server.mjs'), 'utf8'), 'php-integration harness never uses the real config').toMatch(/'transport' => '\$\{phpConfig\.transport \|\| 'log'\}'/);
  });

  test('TC-NFR1-14-AS-02', async (ctx) => {
    let spf;
    try { spf = (await dns.resolveTxt(DOMAIN)).map((r) => r.join('')); } catch (e) { ctx.skip(`DNS unavailable in this environment (${e.code}); run on a networked runner`); return; }
    const spfRecord = spf.find((t) => /^v=spf1/i.test(t));
    expect(spfRecord, `${DOMAIN} publishes SPF`).toBeTruthy();
    expect(spfRecord, 'SPF names the sending provider (include:/a/mx/ip4)').toMatch(/include:|\ba\b|\bmx\b|ip4:/);
    const dmarc = (await dns.resolveTxt(`_dmarc.${DOMAIN}`).catch(() => [])).map((r) => r.join('')).find((t) => /^v=DMARC1/i.test(t));
    expect(dmarc, `_dmarc.${DOMAIN} publishes a DMARC policy`).toBeTruthy();
    expect(dmarc).toMatch(/\bp=(none|quarantine|reject)/);
    let dkim = null;
    for (const sel of DKIM_SELECTORS) {
      const name = `${sel}._domainkey.${DOMAIN}`;
      const txt = (await dns.resolveTxt(name).catch(() => [])).map((r) => r.join('')).find((t) => /v=DKIM1|k=rsa|p=/i.test(t));
      const cname = txt ? null : await dns.resolveCname(name).catch(() => []);
      if (txt || (cname && cname.length)) { dkim = sel; break; }
    }
    expect(dkim, `a DKIM selector resolves for ${DOMAIN} (tried ${DKIM_SELECTORS.join(', ')}; set LLD_DKIM_SELECTORS when the provider's selector is known — DEC proposal)`).toBeTruthy();
  });

  test('TC-NFR1-14-AS-03', () => {
    const cases = [...['low', 'moderate', 'high', 'veryhigh', 'symptomatic', 'emergency'].map((k) => ['diabetes', k]), ['protein', 'protein_standard'], ['protein', 'protein_renal']];
    for (const [slug, key] of cases) {
      const { subject, body } = renderTemplate(slug, key);
      expect(subject, `${slug}/${key}: subject equals copy/templates.json`).toBe(deck[`${slug}.${key}.subject`]);
      const text = body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();   // the mailer's AltBody derivation
      expect(text.length, `${slug}/${key}: text part`).toBeGreaterThan(80);
      expect(body, `${slug}/${key}: HTML part`).toMatch(/<p|<div|<strong/);
      expect(lintEmailHtml(body), `${slug}/${key}: e-mail HTML lint`).toEqual([]);
    }
  });
});
