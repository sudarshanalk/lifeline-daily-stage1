/**
 * Ask every member route for someone else's id. Each one must be refused, with no sentinel text.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {{routes: {path: string}[], memberId: number, sentinel: string, nonce: string}} opts
 */
export async function refuseOtherMember(api, opts) {
  const failures = [];
  for (const route of opts.routes) {
    const url = route.path.replaceAll('{id}', String(opts.memberId));
    const res = await api.get(url, { headers: { 'X-WP-Nonce': opts.nonce } });
    const body = await res.text();
    if (res.status() < 400 || body.includes(opts.sentinel)) {
      failures.push({ url, status: res.status(), body: body.slice(0, 240) });
    }
  }
  return failures;
}

export const SENTINEL = 'SENTINEL-ELSE-5520';
