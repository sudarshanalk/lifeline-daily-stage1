// P3-36 A7 follow-up (TC-FR3-69-AS-06): the Stage 2 interface route POST members/{id}/health stores "Prefer not to
// answer" as the health profile screen does: conditions_answered stays not_answered (both safety matrices read the
// conditions as unknown) with ["prefer-not"] kept, so it counts as an answer. Before, an app could store "prefer_not" as
// the answered state with an empty list, which the matrix read as answered with no conditions. Every other answer is
// stored as before. Run in PHP CLI against the real LLD_Members_Account::rest_health with a fake database.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const stubs = `
define('ABSPATH', '/'); define('ARRAY_A', 'ARRAY_A');
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
class Req implements ArrayAccess { public $body; function __construct($b) { $this->body = $b; } function get_json_params() { return $this->body; }
  function offsetExists($k): bool { return 'id' === $k; } function offsetGet($k): mixed { return 7; } function offsetSet($k, $v): void {} function offsetUnset($k): void {} }
function is_wp_error($v) { return $v instanceof WP_Error; } function wp_json_encode($v) { return json_encode($v); }
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; } function sanitize_title($s) { return $s; }
function lld_members_now_mysql($m = 0) { return '2026-11-14 10:00:00'; }
class LLD_Members_Clock { static function now($m = 0) { return new DateTimeImmutable('2026-11-14 10:00:00'); } }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Access { static function can_read($m) { return true; } }
class FakeDb { public $saved = null;
  function prepare($q, ...$a) { return $q; }
  function get_var($q) { return strpos($q, 'consent') !== false ? 'given' : null; }
  function get_row($q, $o = null) { return null; }
  function replace($t, $row) { $this->saved = $row; return 1; }
}
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(resolve(plugin, 'includes/class-account.php'))};
function post($body) { $GLOBALS['wpdb']->saved = null; $r = LLD_Members_Account::rest_health(new Req(['kind' => 'profile'] + $body)); $s = $GLOBALS['wpdb']->saved;
  return ['status' => $r->status, 'message' => $r->data['message'] ?? null, 'answered' => $s ? $s['conditions_answered'] : null, 'conditions' => $s ? $s['conditions'] : null]; }
`;
const run = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 }).trim());

test('TC-FR3-69-AS-06 (the health interface route stores Prefer not to answer as the screen does)', () => {
  const r = run(`return [
    'state' => post(['conditions_answered' => 'prefer_not', 'conditions' => []]),
    'dash' => post(['conditions_answered' => 'prefer-not']),
    'listed' => post(['conditions_answered' => 'not_answered', 'conditions' => ['prefer-not']]),
    'declared' => post(['conditions_answered' => 'declared', 'conditions' => ['prefer-not']]),
    'mixed' => post(['conditions_answered' => 'declared', 'conditions' => ['prefer-not', 'type-2-diabetes']]),
    'none' => post(['conditions_answered' => 'none', 'conditions' => []]),
    'condition' => post(['conditions_answered' => 'declared', 'conditions' => ['high-blood-pressure']]),
    'none_mixed' => post(['conditions_answered' => 'none', 'conditions' => ['type-2-diabetes']]),
  ];`);
  for (const k of ['state', 'dash', 'listed', 'declared']) {
    expect(r[k], k).toEqual({ status: 200, message: null, answered: 'not_answered', conditions: '["prefer-not"]' });
  }
  expect(r.mixed).toEqual({ status: 400, message: 'Prefer not to answer cannot be combined with a condition.', answered: null, conditions: null });
  // Unchanged: None, a declared condition, and None with a condition.
  expect(r.none).toMatchObject({ status: 200, answered: 'none' });
  expect(r.condition).toEqual({ status: 200, message: null, answered: 'declared', conditions: '["high-blood-pressure"]' });
  expect(r.none_mixed).toEqual({ status: 400, message: 'None cannot be combined with a condition.', answered: null, conditions: null });
});
