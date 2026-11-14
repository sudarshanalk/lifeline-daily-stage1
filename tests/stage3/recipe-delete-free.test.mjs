// P3-36 (FR3-68): a member can always delete their own item. A Free member (after a downgrade) can delete their own
// recipe through the members' interface, while adding or changing one stays Premium. The real interface, food-log and
// recipes classes in PHP CLI, with WordPress and the data layer stubbed.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

// Member 5 owns recipe 31 (kept with deleted_at); recipe 99 belongs to someone else, so the data layer finds no row.
const stubs = `
define('ABSPATH', '/');
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
class Req implements ArrayAccess {
  public $p; public $body; function __construct($p, $body = []) { $this->p = $p; $this->body = $body; }
  function offsetExists($k): bool { return isset($this->p[$k]); } function offsetGet($k): mixed { return $this->p[$k] ?? null; }
  function offsetSet($k, $v): void { $this->p[$k] = $v; } function offsetUnset($k): void { unset($this->p[$k]); }
  function get_json_params() { return $this->body; } function get_method() { return $this->p['method']; }
}
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { static function enabled($f, $m = 0) { return true; } }
class LLD_Members_Access {
  static function current_member_id() { return 5; } static function has_health_consent($m) { return true; }
  static function access_state($m) { return $GLOBALS['state']; }
}
class LLD_Members_Stage3_Data {
  public static $deleted = []; public static $updated = [];
  static function row($t, $m, $id, $sealed = []) { return 'recipes' === $t && 5 === (int) $m && 31 === (int) $id ? ['id' => 31, 'member_id' => 5, 'name' => 'Fixture stew', 'deleted_at' => null] : null; }
  static function soft_delete($t, $m, $id) { self::$deleted[] = [$t, (int) $m, (int) $id]; return true; }
  static function update($t, $m, $id, $f) { self::$updated[] = [$t, (int) $id]; return true; }
}
require ${toPhpPath(stage3('calculators/class-calorie-target.php'))};
require ${toPhpPath(stage3('class-food-log.php'))};
require ${toPhpPath(stage3('class-recipes.php'))};
require ${toPhpPath(stage3('class-api.php'))};
function call($method, $id) {
  LLD_Members_Stage3_Data::$deleted = []; LLD_Members_Stage3_Data::$updated = [];
  $req = new Req(['type' => 'recipes', 'id' => $id, 'method' => strtoupper($method)], ['name' => 'Renamed']);
  $res = 'delete' === $method ? LLD_Members_Stage3_API::delete($req) : LLD_Members_Stage3_API::patch($req);
  return ['status' => $res->status, 'data' => $res->data, 'deleted' => LLD_Members_Stage3_Data::$deleted, 'updated' => LLD_Members_Stage3_Data::$updated];
}
`;
const run = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 }).trim());

test('TC-FR3-68-AS-02: a Free member can delete their own recipe, and still cannot change one', () => {
  const r = run(`
    $GLOBALS['state'] = 'free';
    $out = ['free_delete' => call('delete', 31), 'free_other' => call('delete', 99), 'free_patch' => call('patch', 31)];
    $GLOBALS['state'] = 'Premium';
    $out['premium_delete'] = call('delete', 31);
    return $out;
  `);
  // Their own recipe: deleted (kept as a deleted row, as every Stage 3 delete is), not refused as "part of Premium".
  expect(r.free_delete.status).toBe(200);
  expect(r.free_delete.data).toEqual({ deleted: 31 });
  expect(r.free_delete.deleted).toEqual([['recipes', 5, 31]]);
  // Only their own: another member's recipe is not found, and nothing is deleted.
  expect(r.free_other.status).toBe(404);
  expect(r.free_other.deleted).toEqual([]);
  // Changing a recipe stays Premium (FR3-68: viewed, not added or changed).
  expect(r.free_patch.status).toBe(403);
  expect(r.free_patch.updated).toEqual([]);
  expect(r.premium_delete.status).toBe(200);
  // The access rules agree: reading and deleting are open to a Free member, adding and changing are not.
  const tiers = readFileSync(stage3('class-access-tiers.php'), 'utf8');
  expect(tiers).toContain("if ( in_array( $method, array( 'GET', 'HEAD', 'DELETE' ), true ) ) {");
});
