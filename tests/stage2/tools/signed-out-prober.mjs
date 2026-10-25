/**
 * Requests member addresses with no session. A hit is a 200 response that shows member data.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {string[]} urls
 */
export async function probeSignedOut(api, urls) {
  const open = [];
  for (const url of urls) {
    const res = await api.get(url, { maxRedirects: 0 });
    const body = await res.text();
    const status = res.status();
    const type = res.headers()['content-type'] || '';
    const memberText = /Your membership is|something_else|SENTINEL-|Your record|access_state/.test(body);
    const memberJson = type.includes('json') && /"(email|access_state|something_else)"/.test(body);
    if (status >= 200 && status < 300 && (memberText || memberJson)) {
      open.push({ url, status, body: body.slice(0, 180) });
    }
  }
  return open;
}
