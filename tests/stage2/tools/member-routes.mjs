/**
 * Member routes discovered from the live REST index and the live rewrite rules.
 *
 * @param {{routes?: object, lld_member_rewrites?: string[]}} index Namespace index.
 * @param {number} memberId Member number to place in each address.
 */
export function memberRoutes(index, memberId) {
  const id = String(memberId);
  const routes = [];
  for (const [path, config] of Object.entries(index.routes || {})) {
    if (!path.includes('/members/')) continue;
    const methods = config.methods || {};
    if (!methods.GET) continue;
    let url = path.startsWith('/wp-json') ? path : `/wp-json${path.startsWith('/') ? path : `/${path}`}`;
    url = url.replace(/\(\?P<id>[^)]+\)/g, id);
    if (url.includes('(?P<')) continue;
    routes.push({ path: url });
  }
  const targets = index.lld_member_rewrite_targets || {};
  for (const pattern of index.lld_member_rewrites || []) {
    if (!pattern.includes('([0-9]+)')) continue;
    // A number that is a content item (lld_item=..., such as /members/library/12/) is not a member
    // number, so asking for it says nothing about another member. Member numbers use lld_member.
    if (pattern in targets && !/[?&]lld_member=\$matches\[1\]/.test(targets[pattern])) continue;
    let url = `/${pattern.replace(/^\^/, '').replace(/\$$/, '')}`;
    url = url.replace(/\(\[0-9\]\+\)/g, id).replace(/\/\?/g, '/');
    routes.push({ path: url });
  }
  return routes;
}
