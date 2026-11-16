// Stage 3 journey personas: browser check (S3-P91). Run after seed-stage3-test-personas.php.
//
// Signs in as each seeded persona through the real sign-in form, opens every main member screen in Chromium and
// WebKit at desktop and 360 px phone width, and fails a screen on: a server error, a PHP error or warning in the
// page, a JavaScript error, a wrong redirect, missing expected content, or sideways scrolling on the phone.
// Then opens the health-tools hub and every calculator page it links to. Exit code 1 on any failure; the full
// report goes to test-results/persona-audit/report.json and failure screenshots next to it.
//
//   LLD_PERSONA_FILE=~/lld-personas.json LLD_WP_URL=https://stg.lifelinedaily.com node tools/local/verify-test-personas.mjs
//
// LLD_PERSONA_FILE is the file the seeder wrote (emails and the generated password). It is never printed.
import { chromium, webkit } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const BASE = (process.env.LLD_WP_URL || 'http://localhost:8090').replace(/\/$/, '');
const FILE = (process.env.LLD_PERSONA_FILE || join(homedir(), 'lld-personas.json')).replace(/^~(?=[\\/])/, homedir());
const OUT = 'test-results/persona-audit';
const BROWSERS = (process.env.LLD_BROWSERS || 'chromium,webkit').split(',');
// Local only: the Windows junction to lld-tools gives one script a broken URL. Never set this on staging.
const IGNORE_JS = process.env.LLD_IGNORE_JS ? new RegExp(process.env.LLD_IGNORE_JS) : null;
const SERVER_ERROR = /Fatal error|Parse error|Warning:|Notice:|Deprecated:|There has been a critical error|Uncaught /;

const seed = JSON.parse(readFileSync(FILE, 'utf8'));
mkdirSync(OUT, { recursive: true });

/** What each screen must show for a persona with a goal. Text checks are deliberately about structure, not wording. */
function screensFor(p) {
  if (!p.goal) {
    // No health profile yet, so the matrix knows nothing: the general wellbeing dashboard, no goal prompt (needs_goal).
    return [{ path: '/members/home/', expectPath: /\/members\/home\//, heading: /^Today$/, why: 'fresh member sees the general dashboard' }];
  }
  // The seeded history must reach the screens: these lines appear only when the page cannot read it
  // (for example when the web server and WP-CLI use different data keys).
  const forbid = [/No readings yet/, /\b0 minutes of activity a week/, /\bReach by\b/];
  if (p.track) forbid.push(/No track yet/);
  const list = [
    { path: '/members/home/', expectPath: /\/members\/home\//, heading: /^(Today|Goal reached)$/, forbid, why: 'Today on the dashboard, with the seeded history' },
    { path: '/members/goal/', expectPath: /\/members\/goal/, why: 'goal page' },
    { path: '/members/progress/', expectPath: /\/members\/progress\//, why: 'progress page' },
    { path: '/members/diary/', expectPath: /\/members\/diary\//, why: 'food tracker' },
    { path: '/members/health/', expectPath: /\/members\/health\//, why: 'health log' },
    { path: '/members/settings/', expectPath: /\/members\/settings\//, why: 'settings' },
    { path: '/members/subscription/', expectPath: /\/members\/subscription\//, text: p.premium ? /Premium/ : /Free|Premium/, why: 'plan shown' },
  ];
  if (p.track) list.push({ path: '/members/track/', expectPath: /\/members\/tracks?\//, why: 'track page' });
  return list;
}

const results = [];
const record = (r) => { results.push(r); console.log(`  [${r.pass ? 'PASS' : 'FAIL'}] ${r.browser} ${r.width}px ${r.who} ${r.what}${r.pass ? '' : ' -> ' + r.problems.join('; ')}`); };

/** Open a page and collect every kind of failure on it. */
async function visit(page, url, check, label) {
  const problems = [];
  const jsErrors = [];
  const bad = [];
  const keep = (t) => { if (!IGNORE_JS || !IGNORE_JS.test(t)) jsErrors.push(t); };
  const onError = (e) => keep(e.message);
  const onConsole = (m) => { if (m.type() === 'error') keep(`${m.text()} ${m.location()?.url || ''}`); };
  const onResponse = (r) => { if (r.status() >= 500 && r.url().startsWith(BASE)) bad.push(`${r.status()} ${r.url().replace(BASE, '')}`); };
  page.on('pageerror', onError); page.on('console', onConsole); page.on('response', onResponse);
  let res;
  try {
    res = await page.goto(url, { waitUntil: 'load', timeout: 45000 });
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  } catch (e) { problems.push(`did not load: ${e.message.split('\n')[0]}`); }
  if (res && res.status() >= 400) problems.push(`HTTP ${res.status()}`);
  const body = await page.locator('body').innerText().catch(() => '');
  if (SERVER_ERROR.test(body)) problems.push(`server error text: "${body.match(SERVER_ERROR)[0]}"`);
  if (check.expectPath && !check.expectPath.test(new URL(page.url()).pathname)) problems.push(`ended at ${new URL(page.url()).pathname}`);
  if (check.heading && !(await page.getByRole('heading', { name: check.heading }).count())) problems.push(`no heading ${check.heading}`);
  if (check.text && !check.text.test(body)) problems.push(`missing ${check.text}`);
  for (const f of check.forbid || []) if (f.test(body)) problems.push(`shows "${body.match(f)[0]}"`);
  if (check.notSignIn && /\/members\/sign-in\//.test(page.url())) problems.push('signed out');
  const width = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
  if (width[0] > width[1] + 1) problems.push(`sideways scroll ${width[0]}px > ${width[1]}px`);
  problems.push(...bad.map((b) => `server ${b}`), ...jsErrors.slice(0, 3).map((e) => `JS: ${e.slice(0, 160)}`));
  page.off('pageerror', onError); page.off('console', onConsole); page.off('response', onResponse);
  if (problems.length) await page.screenshot({ path: join(OUT, `${label}.png`), fullPage: true }).catch(() => {});
  return problems;
}

async function signIn(page, email) {
  await page.goto(`${BASE}/members/sign-in/`, { waitUntil: 'load' });
  // The sign-in page may offer an email code first; the password field is the one the CI specs use.
  const pass = page.locator('#lld_password');
  if (!(await pass.count())) {
    const link = page.getByRole('link', { name: /password/i }).or(page.getByRole('button', { name: /password/i })).first();
    if (await link.count()) await link.click();
  }
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(seed.password);
  await page.locator('#lld_password').press('Enter');
  await page.waitForURL((u) => !u.pathname.includes('/members/sign-in'), { timeout: 20000 });
}

for (const name of BROWSERS) {
  const engine = name === 'webkit' ? webkit : chromium;
  const browser = await engine.launch({ headless: true });
  console.log(`\n== ${name}`);
  for (const p of seed.personas) {
    for (const width of [1280, 360]) {
      const context = await browser.newContext({ viewport: { width, height: 800 } });
      const page = await context.newPage();
      const base = { browser: name, width, who: p.name };
      try {
        await signIn(page, p.email);
        record({ ...base, what: 'sign in', pass: true, problems: [] });
      } catch (e) {
        await page.screenshot({ path: join(OUT, `${name}-${width}-${p.key}-signin.png`) }).catch(() => {});
        record({ ...base, what: 'sign in', pass: false, problems: [e.message.split('\n')[0]] });
        await context.close();
        continue;
      }
      for (const s of screensFor(p)) {
        const label = `${name}-${width}-${p.key}${s.path.replace(/\//g, '-')}`.replace(/-+$/, '');
        const problems = await visit(page, BASE + s.path, { ...s, notSignIn: true }, label);
        record({ ...base, what: `${s.path} (${s.why})`, pass: !problems.length, problems });
      }
      await context.close();
    }
  }

  // Public calculators: whatever the hub links to, so new calculators are covered without editing this list.
  const context = await browser.newContext({ viewport: { width: 360, height: 800 } });
  const page = await context.newPage();
  const hubProblems = await visit(page, `${BASE}/health-tools/`, {}, `${name}-hub`);
  record({ browser: name, width: 360, who: 'public', what: '/health-tools/', pass: !hubProblems.length, problems: hubProblems });
  const links = [...new Set(await page.locator('a[href*="/health-tools/"]').evaluateAll((as) => as.map((a) => a.href.split('#')[0].split('?')[0])))]
    .filter((h) => h.startsWith(BASE) && h.replace(BASE, '') !== '/health-tools/');
  if (!links.length) record({ browser: name, width: 360, who: 'public', what: 'calculator links on the hub', pass: false, problems: ['none found'] });
  for (const href of links) {
    const path = href.replace(BASE, '');
    const problems = await visit(page, href, {}, `${name}-calc${path.replace(/\//g, '-')}`);
    record({ browser: name, width: 360, who: 'public', what: path, pass: !problems.length, problems });
  }
  await context.close();
  await browser.close();
}

const failed = results.filter((r) => !r.pass);
writeFileSync(join(OUT, 'report.json'), JSON.stringify({ base: BASE, ran_at: new Date().toISOString(), passed: results.length - failed.length, failed: failed.length, results }, null, 2));
console.log(`\n== ${results.length - failed.length} passed, ${failed.length} failed. Report: ${OUT}/report.json`);
process.exit(failed.length ? 1 : 0);
