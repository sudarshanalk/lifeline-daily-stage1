// REST route gate (integration check). LLD_Members_Rest::register() stops at
// `if ( ! lld_members_test_routes_open() ) return;` on staging and production, so every route a live site needs
// must be registered above that line, and every test-only route below it. Reads the PHP source, no WordPress needed.
import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vitest';

const SOURCE = readFileSync(new URL('../../site/public_html/wp-content/plugins/lld-members/includes/class-rest.php', import.meta.url), 'utf8');

function registerBody() {
  const start = SOURCE.indexOf('public static function register()');
  expect(start, 'register() exists').toBeGreaterThan(-1);
  const next = SOURCE.indexOf('\n\tpublic static function ', start + 10);
  const nextPrivate = SOURCE.indexOf('\n\tprivate static function ', start + 10);
  const ends = [next, nextPrivate, SOURCE.indexOf('\n\t/**', start)].filter((n) => n > -1);
  return SOURCE.slice(start, Math.min(...ends));
}

const body = registerBody();
const gate = body.indexOf('if ( ! lld_members_test_routes_open() )');

// Every route path literal in register(), with its position. The /app/ routes made in a loop appear as "'/app/' . $x".
function routes() {
  const out = [];
  const re = /register_rest_route\(\s*'lld-members\/v1',\s*'([^']+)'(\s*\.\s*\$\w+)?/g;
  let m;
  while ((m = re.exec(body))) out.push({ path: m[1] + (m[2] ? '{loop}' : ''), at: m.index });
  return out;
}

describe('REST routes and the test-route gate', () => {
  test('the gate exists in register()', () => {
    expect(gate).toBeGreaterThan(-1);
    expect(routes().length).toBeGreaterThan(10);
  });

  test('the Stripe notice route and every /app/ route are registered above the gate', () => {
    const live = routes().filter((r) => r.path === '/stripe/notice' || r.path.startsWith('/app/'));
    expect(live.map((r) => r.path)).toContain('/stripe/notice');
    expect(live.some((r) => r.path.startsWith('/app/session'))).toBe(true);
    for (const r of live) expect(r.at, `${r.path} must be above the test-route gate`).toBeLessThan(gate);
  });

  test('test-only routes stay below the gate, including the direct health-record route (C2)', () => {
    const all = routes();
    const health = all.find((r) => r.path === '/members/(?P<id>\\d+)/health');
    expect(health, 'health route registered').toBeTruthy();
    expect(health.at).toBeGreaterThan(gate);
    for (const r of all.filter((x) => x.path.startsWith('/test/') || x.path === '/security/password-storage')) {
      expect(r.at, `${r.path} must be below the test-route gate`).toBeGreaterThan(gate);
    }
  });
});
