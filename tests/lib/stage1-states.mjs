// Before/after states of the whole site for the preservation checks (TC-FR1-02, TC-NFR1-07), on the local WordPress stack.
//  before: the production snapshot as it was — the pristine database (checked) served WITHOUT any Stage 1 code: the overlay is
//          a minimal directory (only the harness endpoint config), so no lld-tools plugin runs and /tools/* come from the
//          snapshot itself;
//  after:  the repository overlay (lld-tools plugin, tools) plus every Stage 1 database change the plugin owns:
//          `wp lld pages apply`, `wp lld home apply`, `wp lld tidy apply --include-drafts`.
// The whole database is dumped before anything changes and restored by restore(), so the shared database is left as found
// (if a run is killed, restore by hand from the kept dump: the error message prints the command).
import { mkdtempSync, mkdirSync, copyFileSync, readFileSync, rmSync, existsSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { ROOT } from './paths.mjs';
import { startWpStack } from './wp-stack.mjs';
import { crawlRow, sitemapUrls } from './crawl-diff.mjs';

export const PROD = 'https://lifelinedaily.com';
export const ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
const DB = process.env.LLD_WP_DB_NAME || 'lifelinedaily_local';
const [DB_HOST, DB_PORT] = (process.env.LLD_WP_DB_HOST || '127.0.0.1:3399').split(':');   // as tests/lib/wp-stack.mjs
const MYSQL_ARGS = ['-uroot', '-h', DB_HOST, '-P', DB_PORT || '3306'];
const mariaBin = () => { const base = 'C:/Program Files'; const d = existsSync(base) ? readdirSync(base).find((n) => n.startsWith('MariaDB')) : null; return d ? `${base}/${d}/bin` : ''; };
const ok = (r, what) => { if (r.status !== 0) throw new Error(`${what}: ${r.stdout}${r.stderr}`); return r.stdout.trim(); };
const mapper = (stack) => ({
  toLocal: (u) => u.replace(/^https?:\/\/(www\.)?lifelinedaily\.com/, stack.url).replace('http://localhost:8080', stack.url),
  toCanonical: (u) => u.replace(stack.url, PROD).replace('http://localhost:8080', PROD),
});
const roleState = (stack) => ({ roles: JSON.parse(ok(stack.wp('role', 'list', '--format=json'), 'role list')).map((r) => r.role).sort(), caps: Object.fromEntries(ROLES.map((r) => [r, ok(stack.wp('cap', 'list', r, '--format=json'), `cap list ${r}`)])) });

/** Builds both states. Returns { before, after, stack (the after stack, still running), maps, restore() }. */
export async function buildStates(sample) {
  // the minimal "production" overlay: only what the harness needs to start (the endpoint config template)
  const bare = mkdtempSync(join(tmpdir(), 'lld-bare-overlay-'));
  mkdirSync(join(bare, 'tools', 'api'), { recursive: true });
  copyFileSync(resolve(ROOT, 'site', 'public_html', 'tools', 'api', 'config.example.php'), join(bare, 'tools', 'api', 'config.example.php'));
  const before = { rows: [] }; const after = { rows: [] };
  let dumpDir = null, dumpFile = null, stack = null;
  const restore = async () => {
    let err = null;
    if (dumpFile) {
      const r = spawnSync(`${mariaBin()}/mariadb.exe`, [...MYSQL_ARGS, DB], { input: readFileSync(dumpFile), maxBuffer: 1 << 30 });
      if (stack) stack.wp('cache', 'flush');
      if (r.status === 0) rmSync(dumpDir, { recursive: true, force: true });
      else err = new Error(`database restore failed (dump kept at ${dumpFile}; restore by hand: mariadb ${MYSQL_ARGS.join(' ')} ${DB} < "${dumpFile}"): ${String(r.stderr).slice(0, 300)}`);
    }
    await stack?.close(); stack = null;
    rmSync(bare, { recursive: true, force: true });
    if (err) throw err;
  };
  let pre = await startWpStack({ overlay: bare });
  try {
    // the before state must be the production snapshot: no Stage 1 database change applied yet
    const front = ok(pre.wp('option', 'get', 'page_on_front'), 'front');
    const pristine = pre.wp('post', 'meta', 'get', '6102', 'lld_tool_slug').stdout.trim() === '' && !/lld-home|lld_learn_row|lld_tools_hub/.test(pre.wp('post', 'get', front, '--field=post_content').stdout) && pre.wp('term', 'list', 'category', '--slug=stress', '--field=term_id').stdout.trim() !== '';
    if (!pristine) throw new Error('local database is not the pristine snapshot (Stage 1 changes already applied) — reset it before the preservation checks');
    dumpDir = mkdtempSync(join(tmpdir(), 'lld-stage1-')); dumpFile = join(dumpDir, 'database.sql');
    const d = spawnSync(`${mariaBin()}/mariadb-dump.exe`, [...MYSQL_ARGS, `--result-file=${dumpFile}`, DB], { encoding: 'utf8' });
    if (d.status !== 0) throw new Error('database dump failed: ' + d.stderr);
    const m = mapper(pre);
    for (const u of sample) before.rows.push(await crawlRow(u, m));
    before.sitemap = await sitemapUrls(`${PROD}/sitemap_index.xml`, m);
    before.roles = roleState(pre);
  } finally { await pre.close(); pre = null; }
  try {
    stack = await startWpStack();
    for (const cmd of [['pages', 'apply'], ['home', 'apply'], ['tidy', 'apply', '--include-drafts']]) ok(stack.wp('lld', ...cmd), `wp lld ${cmd.join(' ')}`);
    stack.wp('cache', 'flush');
    const m = mapper(stack);
    for (const u of sample) after.rows.push(await crawlRow(u, m));
    after.sitemap = await sitemapUrls(`${PROD}/sitemap_index.xml`, m);
    after.roles = roleState(stack);
    return { before, after, stack, maps: m, restore };
  } catch (e) { await restore().catch(() => {}); throw e; }
}
