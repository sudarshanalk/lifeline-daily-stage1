// tools/seo/fetch.mjs — polite GET-only fetching shared by the SEO tools: at most `concurrency` requests in flight,
// `gapMs` apart, one user agent, redirects followed by hand so each hop is recorded. Nothing here ever sends anything
// but GET (and HEAD for external source links, with a GET fallback). An Authorization header (staging behind a
// password) is sent only to the host it was given for, never to a host a redirect leads to.
// `fetchImpl` can replace the global fetch so the unit tests run without a network.

const UA = 'lld-seo-check (Lifeline Daily lead; GET only)';
export const MAX_HOPS = 5;

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

/** An auth option: { value: 'Basic …', host: 'stg.example.com' }, built from user:pass and the base URL. */
export function basicAuth(userPass, baseUrl) {
  if (!userPass) return null;
  return { value: 'Basic ' + Buffer.from(userPass).toString('base64'), host: new URL(baseUrl).hostname.toLowerCase() };
}

const headersObj = (h) => { const o = {}; h.forEach((v, k) => { o[k.toLowerCase()] = v; }); return o; };

/** True when `auth` may be sent to `url`: same hostname as the base it was given for. */
export function authAppliesTo(auth, url) {
  if (!auth || !auth.value || !auth.host) return false;
  try { return new URL(url).hostname.toLowerCase() === auth.host; } catch { return false; }
}

/** One GET without following redirects. Returns { url, status, headers, body, location }. Network failure: status 0. */
export async function getOnce(url, { timeoutMs = 30000, method = 'GET', auth = null, fetchImpl = globalThis.fetch } = {}) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const headers = { 'user-agent': UA, accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' };
    if (authAppliesTo(auth, url)) headers.authorization = auth.value;
    const r = await fetchImpl(url, { redirect: 'manual', signal: ctrl.signal, method, headers });
    const hs = headersObj(r.headers);
    const type = hs['content-type'] || '';
    const body = method === 'HEAD' ? '' : /text|xml|json/.test(type) ? await r.text() : (await r.arrayBuffer(), '');
    return { url, status: r.status, headers: hs, body, location: hs.location || null, content_type: type };
  } catch (e) {
    return { url, status: 0, headers: {}, body: '', location: null, error: String(e.cause?.message || e.message || e).slice(0, 200) };
  } finally { clearTimeout(t); }
}

/** Follow redirects by hand (at most MAX_HOPS redirects), returning every hop. A loop or an over-long chain ends with
 *  a hop of status 0 and error 'redirect loop', so no judge mistakes it for a resolved page. */
export async function getChain(url, { maxHops = MAX_HOPS, ...opts } = {}) {
  const chain = [];
  const seen = new Set();
  let cur = url;
  for (let i = 0; ; i++) {
    if (seen.has(cur) || i > maxHops) { chain.push({ url: cur, status: 0, location: null, headers: {}, html: '', error: 'redirect loop' }); break; }
    seen.add(cur);
    const r = await getOnce(cur, opts);
    chain.push({ url: cur, status: r.status, location: r.location, headers: r.headers, html: r.body, error: r.error });
    if (!(r.status >= 300 && r.status < 400 && r.location)) break;
    cur = new URL(r.location, cur).href;
  }
  return chain;
}

/** External link status for the Sources check: HEAD, then GET if HEAD is refused, redirects followed. Returns the final
 *  HTTP status (a number), or 'error: …' for no response, a redirect loop or a redirect with no Location. */
export async function linkStatus(url, opts = {}) {
  const probe = async (method) => {
    const chain = await getChain(url, { ...opts, method, timeoutMs: 20000 });
    const last = chain[chain.length - 1];
    if (last.error) return { status: 0, error: last.error };
    if (last.status >= 300 && last.status < 400) return { status: 0, error: 'redirect with no Location' };
    return last;
  };
  const h = await probe('HEAD');
  if (h.status >= 200 && h.status < 300) return h.status;
  const g = await probe('GET');
  return g.status || `error: ${g.error || 'no response'}`;
}
