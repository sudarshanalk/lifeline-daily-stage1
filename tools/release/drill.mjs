#!/usr/bin/env node
// Release drills on staging (NFR1-08 restore drill, FR1-19 AS-04 rollback drill) and the release smoke (FR1-19 AS-03).
// Records land in evidence/<REQ>/artefacts/ as JSON; tests/artefact/TC-NFR1-08 and TC-FR1-19 assert on them.
//
//   node tools/release/drill.mjs backup   <staging|production>     full backup only (NFR1-08 AS-01), prints the manifest
//   node tools/release/drill.mjs restore  staging [--accept-drift]  backup -> clean restore -> checks (NFR1-08 AS-01..03)
//   node tools/release/drill.mjs rollback staging [--backup <id>]   overlay rollback to the previous deploy -> smoke -> roll forward (FR1-19 AS-04)
//   node tools/release/drill.mjs smoke    <staging|production> [--label +0h|+1h|+24h]   release smoke (FR1-19 AS-03)
//   node tools/release/drill.mjs history  <staging|production>     backup timestamps (NFR1-08 AS-05)
//
// Connection: the same variables as deploy/connection.sh (DEPLOY_HOST, DEPLOY_PORT, DEPLOY_USER, DEPLOY_KEY_FILE,
// DEPLOY_KNOWN_HOSTS_FILE); nothing about the host is kept in the repository. The restore and rollback drills refuse
// production. Needs bash, curl, ssh and a `python3` on PATH (deploy/smoke.sh and deploy/rollback.sh use it).
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync, mkdtempSync, cpSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(new URL('.', import.meta.url)), '..', '..');
const ENVS = {
  staging: { docroot: 'domains/stg.lifelinedaily.com/public_html',   // its own Hostinger website from 28 Oct 2026 (PR #131)
    url: 'https://stg.lifelinedaily.com' },
  production: { docroot: 'domains/lifelinedaily.com/public_html', url: 'https://lifelinedaily.com' },
};
const PAGES = ['/', '/health-tools/', '/health-tools/protein/', '/health-tools/diabetes-risk-check/', '/tools/protein-requirement-tool.html', '/tools/diabetes-risk-tool.html', '/members/join/', '/pricing/'];
// tables whose rows change on every request or cron tick (transients, caches, queues, counters): a difference between the
// backup and the moment of the restore in these is expected and is restored to the backup's state
const VOLATILE_TABLES = /^(wp_options|wp_litespeed_.*|wp_actionscheduler_.*|wp_wpforms_tasks_meta|wp_yoast_indexable.*|wp_yoast_seo_links|wp_pmpro_.*_log|wp_lld_.*_log|wp_lld_.*rate.*)$/;

const fx = (dir, file, id) => JSON.parse(readFileSync(join(ROOT, 'fixtures', dir, file), 'utf8')).fixtures.find((f) => f.id === id);
const stamp = (d = new Date()) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
const iso = (d = new Date()) => d.toISOString().replace(/\.\d{3}Z$/, 'Z');
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const log = (...a) => console.error('[drill]', ...a);

function conn(env) {
  const e = process.env;
  const need = ['DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER', 'DEPLOY_KEY_FILE', 'DEPLOY_KNOWN_HOSTS_FILE'];
  for (const k of need) if (!e[k]) throw new Error(`${k} is not set (see deploy/environments/${env}.env.example)`);
  if (!/^[a-zA-Z0-9.-]+$/.test(e.DEPLOY_HOST) || !/^[a-zA-Z0-9_-]+$/.test(e.DEPLOY_USER) || !/^\d+$/.test(e.DEPLOY_PORT)) throw new Error('invalid connection variables');
  return ['-p', e.DEPLOY_PORT, '-i', e.DEPLOY_KEY_FILE, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
    '-o', `UserKnownHostsFile=${e.DEPLOY_KNOWN_HOSTS_FILE}`, '-o', 'ConnectTimeout=15', `${e.DEPLOY_USER}@${e.DEPLOY_HOST}`];
}
function ssh(env, command, input) {
  const r = spawnSync('ssh', [...conn(env), command], { input, encoding: 'utf8', maxBuffer: 1 << 28 });
  if (r.status !== 0) throw new Error(`remote command failed (${r.status}): ${(r.stderr || '').trim().slice(-800)}`);
  return r.stdout;
}
/** run tools/release/backup.py remotely; returns its JSON */
function backupOp(env, op, id = '') {
  const script = readFileSync(join(ROOT, 'tools', 'release', 'backup.py'), 'utf8');
  return JSON.parse(ssh(env, `python3 - '${env}' "$HOME/${ENVS[env].docroot}" '${op}' '${id}'`, script));
}
const wp = (env, args) => ssh(env, `wp --path="$HOME/${ENVS[env].docroot}" ${args}`).trim();

async function get(url, init) {
  const t = Date.now();
  try {
    const r = await fetch(url, { redirect: 'manual', headers: { 'User-Agent': 'lld-release-drill' }, ...init });
    const body = Buffer.from(await r.arrayBuffer());
    return { url, status: r.status, ms: Date.now() - t, body, headers: r.headers };
  } catch (e) { return { url, status: 0, ms: Date.now() - t, error: String(e) }; }
}

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { cwd: opts.cwd || ROOT, encoding: 'utf8', env: { ...process.env, ...(opts.env || {}) }, maxBuffer: 1 << 26, shell: /\.cmd$/.test(cmd) });
  return { status: r.status, out: ((r.stdout || '') + (r.stderr || '')).trim() };
}

function smokeSh(env, cwd = ROOT) {
  const t = Date.now();
  const r = run('bash', ['deploy/smoke.sh', ENVS[env].url], { cwd, env: { DEPLOY_ORIGIN: ENVS[env].url } });
  return { ok: r.status === 0, duration_s: Math.round((Date.now() - t) / 100) / 10, output: r.out.split('\n') };
}

/** both tools' happy path in a real browser (tests/e2e/TC-NFR1-08.spec.mjs or TC-FR1-19.spec.mjs), JSON summary */
function browserChecks(spec, env, extraEnv) {
  const out = join(mkdtempSync(join(tmpdir(), 'lld-pw-')), 'pw.json');
  const r = run(process.platform === 'win32' ? 'npx.cmd' : 'npx', ['playwright', 'test', '-c', 'tests/e2e/playwright.config.mjs', `tests/e2e/${spec}`, '--project=chromium'],
    { env: { LLD_DRILL_URL: ENVS[env].url, LLD_PW_JSON: out, ...extraEnv } });
  let tests = [];
  try {
    const j = JSON.parse(readFileSync(out, 'utf8'));
    const walk = (s) => { for (const sp of s.specs || []) for (const t of sp.tests) tests.push({ title: sp.title, status: t.results.at(-1)?.status, error: t.results.at(-1)?.error?.message?.slice(0, 400) }); for (const c of s.suites || []) walk(c); };
    for (const s of j.suites) walk(s);
  } catch { /* reporter output missing: reported below */ }
  return { ok: r.status === 0 && tests.length > 0 && tests.every((t) => t.status === 'passed'), tests, exit: r.status, output_tail: r.out.split('\n').slice(-15) };
}

/** FX-MAIL-01 through the protein endpoint into a mail sink. Staging's tools/api/config.php uses the live SMTP transport
 *  (no Mailpit on the host), so for the duration of this one request the drill switches it to the `log` transport (the
 *  Mailpit stand-in, outbox outside the web root), then puts back the byte-identical original and checks its hash. */
async function mailToSink(env, ids = ['FX-MAIL-01']) {
  if (env !== 'staging') throw new Error('mail sink check is staging-only');
  const cfg = `"$HOME/${ENVS[env].docroot}/tools/api/config.php"`;
  const swap = `python3 - ${cfg} <<'PY'
import hashlib, os, re, sys, shutil
p = sys.argv[1]; data = open(p, 'rb').read(); h = hashlib.sha256(data).hexdigest()
keep = os.path.expanduser('~/lld-backups/staging/config-php-before-mail-drill-' + h[:12] + '.php')
if not os.path.exists(keep):
    shutil.copy2(p, keep); os.chmod(keep, 0o600)
new, n = re.subn(rb"'transport'\\s*=>\\s*'smtp'", b"'transport'=>'log'", data, count=1)
if n != 1: sys.exit('transport line not found')
open(p + '.lld-tmp', 'wb').write(new); os.chmod(p + '.lld-tmp', os.stat(p).st_mode & 0o777); os.replace(p + '.lld-tmp', p)
print(h)
PY`;
  const before = ssh(env, swap).trim();
  let post, outbox, after;
  try {
    await sleep(10000);   // PHP opcache revalidates changed files after a few seconds
    const ENDPOINT = { 'FX-MAIL-01': '/tools/submit-protein.php', 'FX-MAIL-02': '/tools/submit.php' };
    post = [];
    for (const id of ids) {
      const r = await get(`${ENVS[env].url}${ENDPOINT[id]}`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: ENVS[env].url, 'User-Agent': 'lld-release-drill' }, body: JSON.stringify(fx('mail', 'mail.json', id).inputs) });
      let body = {}; try { body = JSON.parse(r.body.toString()); } catch {}
      post.push({ fixture: id, endpoint: ENDPOINT[id], status: r.status, response: body });
    }
    const to = fx('mail', 'mail.json', ids[0]).inputs.email;   // the fixtures' synthetic recipient (never written into this file: sentinel scan)
    if (!/^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$/.test(to)) throw new Error('unexpected fixture recipient');
    outbox = ssh(env, `python3 - '${to}' <<'PY'
import glob, json, os, sys, time
d = os.path.expanduser('~/lld-staging-runtime/outbox')
files = sorted(glob.glob(d + '/*'), key=os.path.getmtime)
new = [f for f in files if time.time() - os.path.getmtime(f) < 120]
out = []
for f in new:
    t = open(f, encoding='utf-8', errors='replace').read()
    out.append({'file': os.path.basename(f), 'to_sentinel': ('TO: ' + sys.argv[1]) in t, 'bytes': len(t)})
    os.unlink(f)   # sentinel-only test message, removed once read
print(json.dumps(out))
PY`);
  } finally {
    after = ssh(env, `python3 - ${cfg} <<'PY'
import hashlib, glob, os, shutil, sys
p = sys.argv[1]; keep = sorted(glob.glob(os.path.expanduser('~/lld-backups/staging/config-php-before-mail-drill-*.php')), key=os.path.getmtime)[-1]
shutil.copy2(keep, p + '.lld-tmp'); os.replace(p + '.lld-tmp', p)
print(hashlib.sha256(open(p, 'rb').read()).hexdigest())
PY`).trim();
  }
  const msgs = JSON.parse(outbox || '[]');
  return { fixtures: ids, posts: post, sink: 'tools mailer log transport (outbox outside the web root), the Mailpit stand-in', messages: msgs.length,
    delivered: post.every((p) => p.status === 200) && msgs.length === ids.length && msgs.every((m) => m.to_sentinel), config_restored: before === after, config_sha256_prefix: before.slice(0, 12) };
}

function record(req, name, doc) {
  const dir = join(ROOT, 'evidence', req, 'artefacts'); mkdirSync(dir, { recursive: true });
  const p = join(dir, name); writeFileSync(p, JSON.stringify(doc, null, 1) + '\n'); log('record', p); return p;
}
const commit = () => run('git', ['rev-parse', 'HEAD']).out;

async function restoreDrill(env, acceptDrift) {
  if (env !== 'staging') throw new Error('the restore drill runs on staging only');
  const started = new Date(); const id = 'full-' + stamp(started);
  log('backup', id);
  const manifest = backupOp(env, 'backup', id);
  const pre = backupOp(env, 'verify', id);
  const hardDrift = Object.keys(pre.database.differences).filter((t) => !VOLATILE_TABLES.test(t) || (t === 'wp_options' && pre.database.options_settings_equal === false));
  if ((hardDrift.length || !pre.files.equal) && !acceptDrift) throw new Error(`staging changed between backup and restore (tables ${hardDrift.join(', ') || '-'}; files ${JSON.stringify(pre.files).slice(0, 300)}): take a new backup or pass --accept-drift`);
  log('restore', id);
  const tRestore = Date.now();
  const restored = backupOp(env, 'restore', id);
  const post = backupOp(env, 'verify', id);
  const posts = wp(env, 'post list --post_type=post --post_status=publish --posts_per_page=10 --orderby=date --order=DESC --field=url --skip-themes').split(/\s+/).filter(Boolean);
  const postChecks = await Promise.all(posts.map(async (u) => { const r = await get(u); return { url: u.replace(ENVS[env].url, ''), status: r.status }; }));
  const pages = await Promise.all(PAGES.map(async (p) => { const r = await get(ENVS[env].url + p); return { path: p, status: r.status }; }));
  const served = {};
  for (const [slug, f] of [['protein', 'protein-requirement-tool.html'], ['diabetes', 'diabetes-risk-tool.html']]) served[slug] = sha((await get(`${ENVS[env].url}/tools/${f}`)).body || Buffer.alloc(0));
  const browser = browserChecks('TC-NFR1-08.spec.mjs', env, {});
  const mail = await mailToSink(env);
  const smoke = smokeSh(env);
  const finished = new Date();
  const doc = {
    kind: 'restore-drill', req: 'NFR1-08', env, backup_id: id, commit: commit(), started_at: iso(started), finished_at: iso(finished),
    restore_duration_s: Math.round((finished - tRestore) / 1000), rto_hours: manifest.policy.rto_hours,
    backup: { created_at: manifest.created_at, duration_s: manifest.duration_s, contents: manifest.contents, archives: manifest.archives, excluded: manifest.excluded,
      inventory: manifest.inventory, database_tables: manifest.database.tables, tool_hashes: manifest.tool_hashes, site: manifest.site, verified: manifest.verified, retention: manifest.retention },
    drift_before_restore: { tables: Object.keys(pre.database.differences), non_volatile: hardDrift, options_settings_equal: pre.database.options_settings_equal, files: pre.files },
    restore: restored,
    after_restore: { database_equal_to_backup: post.database.equal, database_differences: Object.keys(post.database.differences),
      database_differences_volatile_only: Object.keys(post.database.differences).every((t) => VOLATILE_TABLES.test(t)) && post.database.options_settings_equal !== false,
      options_settings_equal: post.database.options_settings_equal, files: post.files, tool_hashes: post.tool_hashes, site: post.site },
    checks: {
      tool_hashes_equal_manifest: post.tool_hashes.protein === manifest.tool_hashes.protein && post.tool_hashes.diabetes === manifest.tool_hashes.diabetes,
      served_tool_hashes_equal_manifest: served.protein === manifest.tool_hashes.protein && served.diabetes === manifest.tool_hashes.diabetes,
      posts: postChecks, posts_ok: postChecks.length === 10 && postChecks.every((p) => p.status === 200),
      pages, pages_ok: pages.every((p) => p.status === 200),
      browser, mail, smoke,
      members_schema_version: post.site.lld_members_schema_version,
    },
  };
  return record('NFR1-08', `drill-${id}.json`, doc);
}

async function rollbackDrill(env, chosen) {
  if (env !== 'staging') throw new Error('the rollback drill runs on staging only');
  const started = new Date();
  const listing = ssh(env, `ls -1 "$HOME/lld-backups/${env}/" | grep -E '^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}\\.tgz$' | sort`).trim().split('\n').map((f) => f.replace(/\.tgz$/, ''));
  // a deploy backup holds the overlay that was live before that deploy, i.e. the release of the deploy listed before it
  const at = chosen ? listing.indexOf(chosen) : listing.length - 1;
  if (at < 1) throw new Error('need the chosen deploy backup and the deploy before it in ~/lld-backups/' + env);
  const backupId = listing[at];
  const previous = listing[at - 1].split('-')[1];
  const lastRun = JSON.parse(run('gh', ['run', 'list', '--workflow', 'deploy-staging.yml', '--branch', 'staging', '--status', 'success', '--limit', '1', '--json', 'databaseId,headSha']).out)[0];
  const current = lastRun.headSha;   // the release staging runs now, redeployed by the roll forward
  const prevSha = run('git', ['rev-parse', previous]).out, curSha = run('git', ['rev-parse', current]).out;
  log('rollback', { backupId, previous: prevSha, current: curSha });
  // runbook step 1: the previous release's checkout for its expected smoke hashes, with the current reviewed rollback tooling
  const work = mkdtempSync(join(tmpdir(), 'lld-rollback-'));
  const arch = run('bash', ['-c', `git archive ${prevSha} site deploy | tar -x -C "${work.replace(/\\/g, '/')}"`]);
  if (arch.status !== 0) throw new Error('git archive failed: ' + arch.out);
  // the previous release's site/ and deploy/smoke.sh (its routes and expected hashes) with the current rollback tooling
  for (const f of ['rollback.sh', 'connection.sh', 'remote.py']) cpSync(join(ROOT, 'deploy', f), join(work, 'deploy', f));
  const before = smokeSh(env);
  const t0 = Date.now();
  const rb = run('bash', ['deploy/rollback.sh', env, backupId], { cwd: work, env: { DEPLOY_DOCROOT: ENVS[env].docroot, DEPLOY_URL: ENVS[env].url } });
  const rollbackSeconds = Math.round((Date.now() - t0) / 1000);
  const afterRollback = smokeSh(env, work);
  const browserPrev = browserChecks('TC-FR1-19.spec.mjs', env, { LLD_SMOKE_SITE_DIR: work });
  // roll forward: the reviewed staging pipeline redeploys the current staging commit (a re-run of its last successful deploy)
  const t1 = Date.now();
  // if another merge deployed staging meanwhile, staging is already forward: never redeploy an older commit over it
  const latest = JSON.parse(run('gh', ['run', 'list', '--workflow', 'deploy-staging.yml', '--branch', 'staging', '--limit', '1', '--json', 'databaseId,headSha']).out)[0];
  const superseded = latest.databaseId !== lastRun.databaseId;
  const rerun = superseded ? { status: 1 } : run('gh', ['run', 'rerun', String(lastRun.databaseId)]);
  let view = superseded ? { conclusion: 'superseded by run ' + latest.databaseId } : {};
  if (rerun.status === 0) {
    await sleep(20000);
    for (let i = 0; i < 60; i++) { view = JSON.parse(run('gh', ['run', 'view', String(lastRun.databaseId), '--json', 'status,conclusion']).out); if (view.status === 'completed') break; await sleep(10000); }
  }
  const watch = { status: view.conclusion === 'success' ? 0 : 1 };
  const forwardSeconds = Math.round((Date.now() - t1) / 1000);
  const afterForward = smokeSh(env);
  rmSync(work, { recursive: true, force: true });
  const finished = new Date();
  const doc = {
    kind: 'rollback-drill', req: 'FR1-19', assertion: 'TC-FR1-19-AS-04', env, started_at: iso(started), finished_at: iso(finished),
    previous_release: prevSha, current_release: curSha, backup_id: backupId,
    rollback_command: `bash deploy/rollback.sh ${env} ${backupId}`, rollback_exit: rb.status, rollback_output: rb.out.split('\n').slice(-12),
    rollback_duration_s: rollbackSeconds, rto_hours: 4, runbook_target_minutes: 5,
    smoke_before: before, smoke_after_rollback: afterRollback, browser_after_rollback: browserPrev,
    roll_forward: { method: superseded ? `deploy-staging run ${latest.databaseId} for ${latest.headSha}` : `gh run rerun ${lastRun.databaseId} (deploy-staging for ${lastRun.headSha})`, conclusion: view.conclusion, exit: watch.status, duration_s: forwardSeconds, smoke: afterForward },
  };
  return record('FR1-19', `rollback-drill-${stamp(started)}.json`, doc);
}

async function releaseSmoke(env, label) {
  const started = new Date();
  const smoke = smokeSh(env);
  const pages = await Promise.all(PAGES.map(async (p) => { const r = await get(ENVS[env].url + p); return { path: p, status: r.status }; }));
  const browser = browserChecks('TC-FR1-19.spec.mjs', env, {});
  const mail = env === 'staging' ? await mailToSink(env, ['FX-MAIL-01', 'FX-MAIL-02']) : null;   // production: the release owner sends both mails to the seeded inbox (see the runbook step in the release record)
  const doc = { kind: 'release-smoke', req: 'FR1-19', assertion: 'TC-FR1-19-AS-03', env, label, commit: commit(), started_at: iso(started), finished_at: iso(), smoke, pages, browser, mail,
    ok: smoke.ok && pages.every((p) => p.status === 200) && browser.ok && (mail ? mail.delivered && mail.config_restored : null) };
  return record('FR1-19', `smoke-${env}-${(label || 'adhoc').replace(/[^0-9a-z]/gi, '')}-${stamp(started)}.json`, doc);
}

const [op, env, ...rest] = process.argv.slice(2);
if (!ENVS[env]) { console.error('usage: drill.mjs backup|restore|rollback|smoke|history <staging|production>'); process.exit(2); }
try {
  if (op === 'backup') console.log(JSON.stringify(backupOp(env, 'backup', 'full-' + stamp()), null, 1));
  else if (op === 'history') { const h = backupOp(env, 'history'); console.log(record('NFR1-08', `backup-history-${env}-${stamp()}.json`, { kind: 'backup-history', req: 'NFR1-08', assertion: 'TC-NFR1-08-AS-05', recorded_at: iso(), ...h })); }
  else if (op === 'restore') console.log(await restoreDrill(env, rest.includes('--accept-drift')));
  else if (op === 'rollback') { const i = rest.indexOf('--backup'); console.log(await rollbackDrill(env, i >= 0 ? rest[i + 1] : null)); }
  else if (op === 'smoke') { const i = rest.indexOf('--label'); console.log(await releaseSmoke(env, i >= 0 ? rest[i + 1] : null)); }
  else { console.error('unknown operation ' + op); process.exit(2); }
} catch (e) { console.error('[drill] refused: ' + e.message); process.exit(1); }
