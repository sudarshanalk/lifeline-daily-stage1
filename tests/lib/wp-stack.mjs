// Local WordPress test stack for the CMP-08/CMP-09 requirements (FR1-04, FR1-15, UI1-02, …): PHP's built-in server over the
// production snapshot's document root (tools/local/local-stack.ps1 data, imported into the local MariaDB) with the
// repository's `site/public_html` overlaid by tests/lib/wp-router.php and the must-use plugin directory replaced by a
// harness-assembled copy (the repository's lld-tools + wp-test-env.php). The snapshot folder is never written; page content
// changes go to the local database only (`wp lld pages apply`).
//   LLD_WP_SNAPSHOT   document root of the snapshot (default C:\MyProjects\lifeline-daily\snapshot-production-2026-10-23\public_html)
//   LLD_WP_CLI        wp-cli.phar (default C:\MyProjects\lifeline-daily\local\bin\wp-cli.phar)
//   LLD_WP_DB_HOST    MariaDB host:port (default 127.0.0.1:3399, started by local-stack.ps1 start)
//   LLD_WP_URL        when set, an already-running WordPress (staging, OI-1): the stack is not started and `wp` is unavailable
// `available()` says whether the stack can run here; CI runners without the snapshot skip the dependent tests with that reason.
import { spawn, spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, rmSync, writeFileSync, readFileSync, openSync, closeSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import http from 'node:http';
import { ROOT } from './paths.mjs';

const SNAPSHOT = process.env.LLD_WP_SNAPSHOT || 'C:/MyProjects/lifeline-daily/snapshot-production-2026-10-23/public_html';
const WP_CLI = process.env.LLD_WP_CLI || 'C:/MyProjects/lifeline-daily/local/bin/wp-cli.phar';
const DB_HOST = process.env.LLD_WP_DB_HOST || '127.0.0.1:3399';
const LOCAL_STACK = resolve(ROOT, 'tools', 'local', 'local-stack.ps1');

export function available() {
  if (process.env.LLD_WP_URL) return { ok: true, reason: 'LLD_WP_URL' };
  if (!existsSync(SNAPSHOT) || !existsSync(join(SNAPSHOT, 'wp-config.php'))) return { ok: false, reason: `WordPress snapshot not present at ${SNAPSHOT} (local stack, OI-4) — set LLD_WP_URL for a staging origin (OI-1)` };
  if (!existsSync(WP_CLI)) return { ok: false, reason: `wp-cli not present at ${WP_CLI}` };
  if (spawnSync('php', ['-v'], { encoding: 'utf8' }).status !== 0) return { ok: false, reason: 'php not on PATH' };
  return { ok: true, reason: 'local snapshot' };
}

const freePort = () => new Promise((ok) => { const s = http.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => ok(p)); }); });
const get = (url, ms = 120000) => new Promise((ok, bad) => { const r = http.get(url, (res) => { let b = ''; res.on('data', (d) => (b += d)); res.on('end', () => ok({ status: res.statusCode, body: b })); }); r.setTimeout(ms, () => { r.destroy(new Error('timeout')); }); r.on('error', bad); });

function ensureDb() {
  const [host, port] = DB_HOST.split(':');
  const probe = spawnSync('php', ['-r', `echo @mysqli_connect("${host}", "root", "", "", ${port || 3306}) ? "up" : "down";`], { encoding: 'utf8' });
  if ((probe.stdout || '').trim() === 'up') return;
  if (process.platform === 'win32' && existsSync(LOCAL_STACK)) spawnSync('powershell', ['-NoProfile', '-File', LOCAL_STACK, 'start'], { encoding: 'utf8', timeout: 90000 });
}

/** Assemble the harness mu-plugins dir: the repository's lld-tools (from `overlay`) plus the test-environment plugin. */
function assembleMu(overlay) {
  const dir = mkdtempSync(join(tmpdir(), 'lld-mu-'));
  cpSync(join(overlay, 'wp-content', 'mu-plugins', 'lld-tools'), join(dir, 'lld-tools'), { recursive: true });
  cpSync(join(overlay, 'wp-content', 'mu-plugins', 'lld-tools-loader.php'), join(dir, 'lld-tools-loader.php'));
  writeFileSync(join(dir, 'zz-lld-test-env.php'), readFileSync(resolve(ROOT, 'tests', 'lib', 'wp-test-env.php')));
  return dir;
}

/* DEC-73: the local stack (snapshot docroot + one MariaDB database) is a shared singleton. Every startWpStack() holds an
 * exclusive lock file for its lifetime so concurrent runs from other worktrees or reviewer sessions wait instead of
 * corrupting each other's page content. A lock whose holder process no longer exists is taken over. */
const LOCK = join(tmpdir(), 'lld-wp-stack.lock');
const alive = (pid) => { try { process.kill(pid, 0); return true; } catch (e) { return e.code === 'EPERM'; } };
async function acquireLock(timeoutMs = Number(process.env.LLD_WP_LOCK_TIMEOUT_MS || 45 * 60000)) {
  const t0 = Date.now();
  for (;;) {
    try { const fd = openSync(LOCK, 'wx'); writeFileSync(fd, JSON.stringify({ pid: process.pid, cwd: process.cwd(), at: new Date().toISOString() })); closeSync(fd); break; }
    catch (e) {
      if (e.code !== 'EEXIST') throw e;
      let holder = null; try { holder = JSON.parse(readFileSync(LOCK, 'utf8')); } catch {}
      if (holder && holder.pid === process.pid) break;                       // re-entrant within one process
      if (!holder || !alive(holder.pid)) { try { unlinkSync(LOCK); } catch {} continue; }   // stale: holder gone
      if (Date.now() - t0 > timeoutMs) throw new Error(`local WordPress stack busy: locked by pid ${holder.pid} (${holder.cwd}) since ${holder.at} — DEC-73`);
      await new Promise((r) => setTimeout(r, 2000));
    }
  }
  let released = false;
  const release = () => { if (released) return; released = true; try { const h = JSON.parse(readFileSync(LOCK, 'utf8')); if (h.pid === process.pid) unlinkSync(LOCK); } catch {} };
  process.once('exit', release);
  return release;
}

export async function startWpStack({ overlay = resolve(ROOT, 'site', 'public_html') } = {}) {
  if (process.env.LLD_WP_URL) return { url: process.env.LLD_WP_URL.replace(/\/$/, ''), external: true, wp: () => { throw new Error('wp-cli is not available against LLD_WP_URL'); }, close: async () => {} };
  const a = available(); if (!a.ok) throw new Error(a.reason);
  const releaseLock = await acquireLock();
  try { ensureDb(); } catch (e) { releaseLock(); throw e; }
  // the overlay is served from a temp copy: `wp lld status set` writes tools/status.json there, the endpoints get a generated
  // config.php (log transport, outbox in the copy), and the repository's site/ stays untouched
  const overlayCopy = mkdtempSync(join(tmpdir(), 'lld-overlay-'));
  cpSync(overlay, overlayCopy, { recursive: true });
  const cfg = readFileSync(join(overlayCopy, 'tools', 'api', 'config.example.php'), 'utf8').replace(/'transport'\s*=>\s*[^,]+,/, "'transport' => 'log',").replace(/'allowed_origins'\s*=>\s*\[[^\]]*\]/, "'allowed_origins' => []");
  writeFileSync(join(overlayCopy, 'tools', 'api', 'config.php'), cfg);
  overlay = overlayCopy;
  const mu = assembleMu(overlay);
  const port = await freePort();
  const url = `http://localhost:${port}`;
  const env = { ...process.env, LLD_LOCAL_DB_NAME: process.env.LLD_WP_DB_NAME || 'lifelinedaily_local', LLD_LOCAL_DB_USER: 'root', LLD_LOCAL_DB_PASSWORD: '', LLD_LOCAL_DB_HOST: DB_HOST, LLD_LOCAL_URL: url, LLD_OVERLAY: overlay, LLD_MU_DIR: mu };
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', SNAPSHOT, resolve(ROOT, 'tests', 'lib', 'wp-router.php')], { env, stdio: ['ignore', 'pipe', 'pipe'] });
  const log = []; const collect = () => { let rest = ''; return (d) => { const parts = (rest + String(d)).split(/\r?\n/); rest = parts.pop(); log.push(...parts.filter(Boolean)); }; };
  php.stdout.on('data', collect()); php.stderr.on('data', collect());
  // WordPress needs the local URL in the database (local-stack.ps1 replace wrote http://localhost:8080); rewrite home/siteurl for this port
  const wp = (...args) => {
    const r = spawnSync('php', [WP_CLI, `--path=${SNAPSHOT}`, `--require=${resolve(ROOT, 'tests', 'lib', 'wp-cli-env.php')}`, `--url=${url}`, ...args], { env, encoding: 'utf8', timeout: 180000 });
    return { status: r.status, stdout: r.stdout || '', stderr: r.stderr || '' };
  };
  const opt = (k) => wp('option', 'get', k).stdout.trim();
  const original = { home: opt('home'), siteurl: opt('siteurl') };
  if (original.home !== url) { wp('option', 'update', 'home', url); wp('option', 'update', 'siteurl', url); }
  // wait for the first page (cold start can take a minute)
  const t0 = Date.now(); let ready = false;
  while (Date.now() - t0 < 180000) { try { const r = await get(url + '/', 150000); if (r.status === 200) { ready = true; break; } } catch {} await new Promise((r) => setTimeout(r, 1000)); }
  if (!ready) { php.kill(); releaseLock(); throw new Error('test WordPress did not answer: ' + log.slice(-5).join(' | ')); }
  return {
    url, external: false, wp, snapshot: SNAPSHOT, muDir: mu, overlayDir: overlay, statusPath: join(overlay, 'tools', 'status.json'), outboxDir: join(overlay, 'tools', 'api', 'outbox'), log: () => [...log],
    close: async () => { try { if (original.home && original.home !== url) { wp('option', 'update', 'home', original.home); wp('option', 'update', 'siteurl', original.siteurl); } } catch {} php.kill(); rmSync(mu, { recursive: true, force: true }); rmSync(overlay, { recursive: true, force: true }); releaseLock(); },
  };
}
