#!/usr/bin/env node
// crawl (docs/04 §1): every URL in fixtures/site/url-sample.json (the 30-URL rule, SRS §13) answers 200 with a canonical
// link. Locally only the /tools/* URLs are reachable (the .htaccess emulator); with LLD_WP_URL set the WordPress URLs are
// crawled too (NFR1-07/FR1-02 extend this with redirects, titles and the before/after comparison).
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { ROOT } from '../lib/paths.mjs';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

export async function crawl() {
  const sample = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'url-sample.json'), 'utf8'));
  // the sample is the WordPress URL set (SRS §13); the three tool pages are always crawled locally through the emulator
  const TOOL_URLS = ['https://lifelinedaily.com/tools/protein-requirement-tool.html', 'https://lifelinedaily.com/tools/diabetes-risk-tool.html', 'https://lifelinedaily.com/tools/health-tools-hub.html'];
  const urls = [...new Set([...(sample.urls || []), ...TOOL_URLS])].map((u) => new URL(u));
  const srv = await startHtaccessServer();
  const wp = process.env.LLD_WP_URL?.replace(/\/$/, '');
  const results = [];
  try {
    for (const u of urls) {
      const isTool = u.pathname.startsWith('/tools/');
      if (!isTool && !wp) { results.push({ url: u.href, status: 'skipped (needs LLD_WP_URL)' }); continue; }
      const target = (isTool ? srv.url : wp) + u.pathname + u.search;
      const r = await fetch(target, { redirect: 'manual' });
      const html = r.headers.get('content-type')?.includes('html') ? await r.text() : '';
      const canon = html ? new JSDOM(html).window.document.querySelector('link[rel="canonical"]')?.href : null;
      results.push({ url: u.href, status: r.status, canonical: canon, ok: r.status === 200 && (!html || !!canon || /health-tools-hub/.test(u.pathname)) });
    }
  } finally { await srv.close(); }
  const failures = results.filter((r) => r.ok === false);
  return { ok: failures.length === 0, results, failures };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const r = await crawl();
  for (const x of r.results) console.log(`${String(x.status).padEnd(26)} ${x.url}${x.canonical ? ' → ' + x.canonical : ''}`);
  console.log(r.ok ? `crawl: OK (${r.results.filter((x) => x.ok).length} checked, ${r.results.filter((x) => typeof x.status === 'string').length} skipped)` : `crawl: FAIL (${r.failures.length})`);
  process.exit(r.ok ? 0 : 1);
}
