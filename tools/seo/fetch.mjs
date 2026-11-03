// tools/seo/fetch.mjs — polite GET-only fetching shared by the SEO tools: at most `concurrency` requests in flight,
// `gapMs` apart, one user agent, redirects followed by hand so each hop is recorded. Nothing here ever sends anything
// but GET (and HEAD for external source links, with a GET fallback).

const UA = 'lld-seo-check (Lifeline Daily lead; GET only)';

export function makeQueue({ concurrency = 2, gapMs = 200 } = {}) {
  let active = 0;
  let last = 0;
  const waiting = [];
  const next = () => {
    if (active >= concurrency || !waiting.length) return;
    const now = Date.now();
    const wait = Math.max(0, last + gapMs - now);
    active++;
    last = now + wait;
    const { fn, resolve, reject } = waiting.shift();
    setTimeout(() => fn().then(resolve, reject).finally(() => { active--; next(); }), wait);
  };
  return (fn) => new Promise((resolve, reject) => { waiting.push({ fn, resolve, reject }); next(); });
}

const headersObj = (h) => { const o = {}; h.forEach((v, k) => { o[k.toLowerCase()] = v; }); return o; };

/** One GET without following redirects. Returns { url, status, headers, html|text, location }. */
export async function getOnce(url, { timeoutMs = 30000, method = 'GET', auth = null } = {}) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const headers = { 'user-agent': UA, accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' };
    if (auth) headers.authorization = auth;
    const r = await fetch(url, { redirect: 'manual', signal: ctrl.signal, method, headers });
    const hs = headersObj(r.headers);
    const type = hs['content-type'] || '';
    const body = method === 'HEAD' ? '' : /text|xml|json/.test(type) ? await r.text() : (await r.arrayBuffer(), '');
    return { url, status: r.status, headers: hs, body, location: hs.location || null, content_type: type };
  } catch (e) {
    return { url, status: 0, headers: {}, body: '', location: null, error: String(e.cause?.message || e.message || e).slice(0, 200) };
  } finally { clearTimeout(t); }
}

/** Follow redirects by hand (max `maxHops`), returning every hop. */
export async function getChain(url, { maxHops = 5, ...opts } = {}) {
  const chain = [];
  let cur = url;
  for (let i = 0; i <= maxHops; i++) {
    const r = await getOnce(cur, opts);
    chain.push({ url: cur, status: r.status, location: r.location, headers: r.headers, html: r.body, error: r.error });
    if (!(r.status >= 300 && r.status < 400 && r.location)) break;
    cur = new URL(r.location, cur).href;
  }
  return chain;
}

/** External link status for the Sources check: HEAD, then GET if HEAD is refused, redirects followed. */
export async function linkStatus(url, opts = {}) {
  const probe = async (method) => {
    let cur = url;
    for (let i = 0; i < 5; i++) {
      const r = await getOnce(cur, { ...opts, method, timeoutMs: 20000 });
      if (r.status >= 300 && r.status < 400 && r.location) { cur = new URL(r.location, cur).href; continue; }
      return r;
    }
    return { status: 310 };
  };
  const h = await probe('HEAD');
  if (h.status >= 200 && h.status < 400) return h.status;
  const g = await probe('GET');
  return g.status || `error: ${g.error || 'no response'}`;
}
