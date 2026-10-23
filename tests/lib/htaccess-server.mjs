// Local stand-in for the Apache/LiteSpeed front end so the http-family tests can exercise the real
// `.htaccess` directives in the repository without a web server on the build machine.
//
// It serves a temporary copy of site/public_html (so tests may toggle status.json or edit a tool page without
// touching the tree), interprets the directives we rely on — `Header [always] set|unset`, `<Files>`,
// `<FilesMatch>`, `<IfModule>` (always taken as present), `Require all denied|granted`, `Options -Indexes`,
// `FileETag` (MTime Size, Apache/LiteSpeed default) — and proxies *.php to PHP's built-in server rooted at the same
// copy with a generated `api/config.php` (transport log → temp outbox; never the real config).
// Scope: `.htaccess.lld` (the managed root block) applies to every path; `tools/.htaccess` to /tools/**;
// `tools/api/.htaccess` to /tools/api/**. Static files under /wp-content/mu-plugins/lld-tools/ are served as WordPress
// would; anything else answers 404 (WordPress pages are not emulated — tests/e2e/harness stands in for landing pages).
//
//   import { startHtaccessServer } from '../lib/htaccess-server.mjs';
//   const srv = await startHtaccessServer();   // { url, docroot, close() }
//   LLD_TOOLS_URL=<url> is what the http/e2e tests read; set it to a staging origin to run the same tests there.
import http from 'node:http';
import { spawn } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, extname, basename, dirname } from 'node:path';
import { ROOT } from './paths.mjs';

/* ----------------------------------------------------------------------------- directive parsing --- */
export function parseHtaccess(text) {
  // Returns a flat list of rules: { match: (fileName) => bool, kind, args }
  const rules = [];
  const scope = []; // stack of file matchers
  const matcher = () => { const snap = [...scope]; return (name) => snap.every((m) => m(name)); }; // snapshot the enclosing <Files*> scopes
  for (let raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    let m;
    if ((m = /^<Files\s+"?([^">]+)"?\s*>$/i.exec(line))) { const lit = m[1]; scope.push((n) => n === lit); continue; }
    if ((m = /^<FilesMatch\s+"?([^">]+)"?\s*>$/i.exec(line))) { const re = new RegExp(m[1]); scope.push((n) => re.test(n)); continue; }
    if (/^<\/(Files|FilesMatch)>$/i.test(line)) { scope.pop(); continue; }
    if (/^<IfModule\b/i.test(line) || /^<\/IfModule>$/i.test(line)) continue; // every module is "present"
    if (/^<IfModule\s+!/i.test(line)) continue;
    if ((m = /^Header\s+(always\s+)?(set|unset|append)\s+([\w-]+)(?:\s+"([^"]*)"|\s+(\S+))?/i.exec(line))) {
      rules.push({ match: matcher(), kind: 'header', always: !!m[1], op: m[2].toLowerCase(), name: m[3], value: m[4] ?? m[5] ?? '' });
      continue;
    }
    if ((m = /^Require\s+all\s+(denied|granted)$/i.exec(line))) { rules.push({ match: matcher(), kind: 'require', allow: m[1].toLowerCase() === 'granted' }); continue; }
    if (/^(Order|Deny|Allow)\b/i.test(line)) continue; // legacy mod_access twin of Require
    if ((m = /^Options\s+(.+)$/i.exec(line))) { rules.push({ match: matcher(), kind: 'options', value: m[1] }); continue; }
    if ((m = /^FileETag\s+(.+)$/i.exec(line))) { rules.push({ match: matcher(), kind: 'fileetag', value: m[1] }); continue; }
    if ((m = /^(CacheDisable|CacheEnable|CacheLookup|RewriteEngine|RewriteRule|RewriteCond|RewriteBase|ErrorDocument|AddType|ExpiresActive|ExpiresByType|ExpiresDefault)\b/i.exec(line))) { rules.push({ match: matcher(), kind: 'noop', directive: m[1] }); continue; }
    throw new Error(`htaccess emulator: unsupported directive: ${line}`);
  }
  return rules;
}

let ORIGIN = 'http://127.0.0.1';
/** deploy.sh substitutes __LLD_ORIGIN__ in *.html and .htaccess.lld (DEC-34); the emulator does the same with its own origin. */
const substitute = (text) => text.split('__LLD_ORIGIN__').join(ORIGIN);
function load(path) { return existsSync(path) ? parseHtaccess(substitute(readFileSync(path, 'utf8'))) : []; }

/** Rules applying to a URL path, most general first (root block, then /tools, then /tools/api). */
function rulesFor(docroot, urlPath) {
  const list = [...load(join(docroot, '.htaccess.lld')), ...load(join(docroot, '.htaccess'))];
  if (urlPath.startsWith('/tools/')) list.push(...load(join(docroot, 'tools', '.htaccess')));
  if (urlPath.startsWith('/tools/api/')) list.push(...load(join(docroot, 'tools', 'api', '.htaccess')));
  return list;
}

function applyHeaders(rules, fileName, res, { always }) {
  for (const r of rules) {
    if (r.kind !== 'header' || !r.match(fileName)) continue;
    if (always && !r.always) continue;      // on error responses only `Header always` applies (Apache semantics)
    if (r.op === 'set') res.setHeader(r.name, r.value);
    else if (r.op === 'unset') res.removeHeader(r.name);
    else if (r.op === 'append') res.setHeader(r.name, [res.getHeader(r.name), r.value].filter(Boolean).join(', '));
  }
}

/* ---------------------------------------------------------------------------------- the server --- */
export async function startHtaccessServer({ port = 0, overlay = resolve(ROOT, 'site', 'public_html'), phpPort = 0, harnessDir = null } = {}) {
  const docroot = mkdtempSync(join(tmpdir(), 'lld-docroot-'));
  cpSync(overlay, docroot, { recursive: true });
  // test config for the PHP endpoints: log transport into the temp copy, rate limit on, local origins allowed
  const cfg = readFileSync(join(docroot, 'tools', 'api', 'config.example.php'), 'utf8')
    .replace(/'transport'\s*=>\s*[^,]+,/, "'transport' => 'log',")
    .replace(/'allowed_origins'\s*=>\s*\[[^\]]*\]/, "'allowed_origins' => ['https://lifelinedaily.com', 'https://www.lifelinedaily.com', 'http://localhost:8080']");
  writeFileSync(join(docroot, 'tools', 'api', 'config.php'), cfg);
  if (!existsSync(join(docroot, 'tools', 'status.json'))) writeFileSync(join(docroot, 'tools', 'status.json'), '{}');

  // PHP built-in server for *.php under /tools (deny rules are enforced here, not in PHP)
  const php = spawn('php', ['-S', `127.0.0.1:${phpPort || 0}`, '-t', docroot], { stdio: ['ignore', 'pipe', 'pipe'] });
  const phpOrigin = await new Promise((ok, bad) => {
    const t = setTimeout(() => bad(new Error('php -S did not start')), 15000);
    const onData = (d) => { const m = /(https?:\/\/[\d.]+:\d+)/.exec(String(d)); if (m) { clearTimeout(t); ok(m[1]); } };
    php.stdout.on('data', onData); php.stderr.on('data', onData);
    php.on('exit', (c) => bad(new Error('php exited ' + c)));
  });

  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    const urlPath = decodeURIComponent(url.pathname);
    const fileName = basename(urlPath);
    const rules = rulesFor(docroot, urlPath);
    const finish = (status, body, type) => {
      applyHeaders(rules, fileName, res, { always: status >= 400 });
      res.statusCode = status; if (type) res.setHeader('Content-Type', type); res.end(body);
    };
    if (harnessDir && urlPath.startsWith('/__harness/')) {
      const hp = join(harnessDir, urlPath.slice('/__harness/'.length));
      if (!existsSync(hp) || statSync(hp).isDirectory()) return finish(404, 'no harness file', 'text/plain');
      // harness pages stand in for WordPress pages: they get the managed root block's headers, like every page on the host
      applyHeaders(rules, basename(hp), res, { always: false });
      res.setHeader('Content-Type', extname(hp) === '.js' ? 'application/javascript' : 'text/html; charset=utf-8');
      return res.end(substitute(readFileSync(hp, 'utf8')));
    }
    const staticPrefixes = ['/tools/', '/wp-content/mu-plugins/lld-tools/'];
    if (!staticPrefixes.some((p) => urlPath.startsWith(p))) return finish(404, 'not emulated (WordPress)', 'text/plain');
    // access control (Require) — last applicable wins, like Apache's merge order
    let allowed = true;
    for (const r of rules) if (r.kind === 'require' && r.match(fileName)) allowed = r.allow;
    if (!allowed) return finish(403, 'Forbidden', 'text/html');
    const fsPath = join(docroot, urlPath);
    if (!existsSync(fsPath)) return finish(404, 'Not Found', 'text/html');
    if (statSync(fsPath).isDirectory()) {
      const noIndex = rules.some((r) => r.kind === 'options' && /-Indexes/i.test(r.value));
      return finish(noIndex ? 403 : 200, noIndex ? 'Forbidden' : 'index', 'text/html');
    }
    if (extname(fsPath) === '.php') {
      // proxy to PHP, overlay .htaccess headers on the response
      const chunks = []; for await (const c of req) chunks.push(c);
      const r = await fetch(phpOrigin + urlPath + url.search, { method: req.method, headers: { ...req.headers, host: '127.0.0.1' }, body: ['GET', 'HEAD'].includes(req.method) ? undefined : Buffer.concat(chunks), redirect: 'manual' });
      for (const [k, v] of r.headers) if (!['content-length', 'transfer-encoding', 'connection'].includes(k)) res.setHeader(k, v);
      applyHeaders(rules, fileName, res, { always: r.status >= 400 });
      res.statusCode = r.status; return res.end(Buffer.from(await r.arrayBuffer()));
    }
    // static file with Apache-style ETag (MTime Size) and conditional GET
    const st = statSync(fsPath);
    const etag = `"${st.size.toString(16)}-${Math.floor(st.mtimeMs).toString(16)}"`;
    const types = { '.html': 'text/html; charset=utf-8', '.json': 'application/json', '.css': 'text/css', '.js': 'application/javascript', '.txt': 'text/plain' };
    res.setHeader('ETag', etag); res.setHeader('Last-Modified', st.mtime.toUTCString());
    if (req.headers['if-none-match'] === etag) return finish(304, '');
    const body = ['.html', '.js'].includes(extname(fsPath)) ? Buffer.from(substitute(readFileSync(fsPath, 'utf8'))) : readFileSync(fsPath);
    return finish(200, body, types[extname(fsPath)] || 'application/octet-stream');
  });
  await new Promise((ok) => server.listen(port, '127.0.0.1', ok));
  const url = `http://127.0.0.1:${server.address().port}`;
  ORIGIN = url;
  return {
    url, docroot, phpOrigin,
    close: () => new Promise((ok) => { php.kill(); server.close(() => { rmSync(docroot, { recursive: true, force: true }); ok(); }); }),
  };
}

if (process.argv[1] && resolve(process.argv[1]) === (await import('node:url')).fileURLToPath(import.meta.url)) {
  const s = await startHtaccessServer({ port: Number(process.env.PORT || 8089) });
  console.log(`htaccess emulator serving ${s.docroot} at ${s.url} (php: ${s.phpOrigin}); Ctrl+C to stop`);
}
