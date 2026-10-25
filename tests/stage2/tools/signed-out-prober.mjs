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
    const showsMember = /Your membership is|something_else|SENTINEL-/.test(body);
    if (showsMember || (res.status() === 200 && /Your record/.test(body))) {
      open.push({ url, status: res.status() });
    }
  }
  return open;
}
