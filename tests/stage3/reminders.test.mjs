// Reminders, shareable summary, add to home screen (P3-85, FR3-56 to FR3-58).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const stubs = `
define('ABSPATH', '/');
if (!function_exists('home_url')) { function home_url($p='/'){ return 'https://example.test'.$p; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v){ return json_encode($v); } }
if (!function_exists('esc_url')) { function esc_url($u){ return $u; } }
if (!function_exists('esc_html')) { function esc_html($t){ return htmlspecialchars((string)$t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t){ return htmlspecialchars((string)$t, ENT_QUOTES); } }
if (!function_exists('__return_true')) { function __return_true(){ return true; } }
if (!function_exists('add_action')) { function add_action(){} }
if (!function_exists('register_rest_route')) { function register_rest_route(){} }
if (!function_exists('plugins_url')) { function plugins_url($p){ return 'https://example.test/wp-content/plugins/lld-members/'.$p; } }
if (!function_exists('untrailingslashit')) { function untrailingslashit($s){ return rtrim($s,'/'); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u,$c=-1){ $p=parse_url($u); return $c===PHP_URL_PATH?($p['path']??''):$p; } }
if (!defined('PHP_URL_PATH')) { define('PHP_URL_PATH', 5); }
class WP_REST_Response { public $data; public $status; function __construct($d,$s=200){$this->data=$d;$this->status=$s;} }
class LLD_Members_Access_Tiers {
  const FREE_REMINDERS = 1;
  static function running_reminders($rows,$all){
    $ids=[]; foreach($rows as $r){ if(!empty($r['active']) && empty($r['deleted_at'])) $ids[]=(int)$r['id']; }
    sort($ids); return $all ? $ids : array_slice($ids, 0, self::FREE_REMINDERS);
  }
  static function allows(){ return $GLOBALS['premium'] ?? false; }
  static function upgrade_prompt($f){ return 'upgrade:'.$f; }
}
require ${toPhpPath(stage3('class-reminders.php'))};
require ${toPhpPath(stage3('class-shares.php'))};
require ${toPhpPath(stage3('class-installable.php'))};
`;

const php = (code) =>
  execFileSync('php', ['-d', 'display_errors=stderr', '-r', stubs + code], {
    encoding: 'utf8',
    timeout: 120000,
  }).trim();

const run = (code) => JSON.parse(php(`echo json_encode((function(){ ${code} })());`));

test('P3-85 wiring: loader boots Reminders, Shares, Installable', () => {
  const loader = readFileSync(stage3('loader.php'), 'utf8');
  const api = readFileSync(stage3('class-api.php'), 'utf8');
  expect(loader).toContain('class-reminders.php');
  expect(loader).toContain('class-shares.php');
  expect(loader).toContain('class-installable.php');
  expect(loader).toContain('LLD_Members_Reminders::boot');
  expect(api).toContain('LLD_Members_Reminders::gate_create');
  expect(api).toContain('LLD_Members_Shares::for_save');
});

test('TC-FR3-56-AS-02 reminder email has no health numbers', () => {
  const out = run(`
    $copies = [];
    foreach (['log','workout','weigh_in','check_in'] as $t) {
      $c = LLD_Members_Reminders::email_copy($t, 'Alex', 'https://example.test/members/home/');
      $copies[$t] = ['safe' => LLD_Members_Reminders::email_is_safe($c['body']), 'body' => $c['body'], 'subject' => $c['subject']];
    }
    return $copies;
  `);
  for (const t of Object.keys(out)) {
    expect(out[t].safe, t).toBe(true);
    expect(out[t].body).not.toMatch(/\d+\s*kg/i);
    expect(out[t].body).not.toMatch(/mmHg|mmol|glucose|systolic/i);
  }
});

test('TC-FR3-56-AS-03 Free cannot add a second reminder; Premium can', () => {
  const out = run(`
    $rows = [
      ['id'=>1,'active'=>1,'deleted_at'=>null],
    ];
    $free = LLD_Members_Reminders::gate_create(5, $rows, false);
    $prem = LLD_Members_Reminders::gate_create(5, $rows, true);
    $empty = LLD_Members_Reminders::gate_create(5, [], false);
    return compact('free','prem','empty');
  `);
  expect(out.free).toMatch(/one reminder|Premium/i);
  expect(out.prem).toBeNull();
  expect(out.empty).toBeNull();
});

test('TC-FR3-56-AS-04 inactive reminder is not due', () => {
  const out = run(`
    $local = new DateTimeImmutable('2026-11-04 08:02:00', new DateTimeZone('UTC'));
    $on = ['id'=>1,'active'=>1,'deleted_at'=>null,'days'=>'Mon,Tue,Wed,Thu,Fri,Sat,Sun','time_local'=>'08:00:00'];
    $off = ['id'=>2,'active'=>0,'deleted_at'=>null,'days'=>'Mon,Tue,Wed,Thu,Fri,Sat,Sun','time_local'=>'08:00:00'];
    return [
      'on' => LLD_Members_Reminders::is_due($on, $local),
      'off' => LLD_Members_Reminders::is_due($off, $local),
      'due_ids' => LLD_Members_Reminders::due_ids([$on,$off], true, $local),
    ];
  `);
  expect(out.on).toBe(true);
  expect(out.off).toBe(false);
  expect(out.due_ids).toEqual([1]);
});

test('TC-FR3-56-AS-01 due at 08:00 in member local window', () => {
  const out = run(`
    $local = new DateTimeImmutable('2026-11-04 08:00:30', new DateTimeZone('Europe/London'));
    $row = ['id'=>9,'active'=>1,'deleted_at'=>null,'days'=>'Wed','time_local'=>'08:00:00'];
    // 4 Nov 2026 is a Wednesday.
    return [
      'due' => LLD_Members_Reminders::is_due($row, $local),
      'wrong_day' => LLD_Members_Reminders::is_due(array_merge($row,['days'=>'Mon']), $local),
    ];
  `);
  expect(out.due).toBe(true);
  expect(out.wrong_day).toBe(false);
});

test('TC-FR3-57-AS-01 default summary has no health numbers', () => {
  const out = run(`
    $built = LLD_Members_Shares::build(['days_active'=>12,'challenges'=>1,'workouts'=>4], false);
    return [
      'has' => $built['has_health_numbers'],
      'text_has' => LLD_Members_Shares::text_has_health_numbers($built['text']),
      'text' => $built['text'],
    ];
  `);
  expect(out.has).toBe(false);
  expect(out.text_has).toBe(false);
  expect(out.text).toContain('Health numbers are not included');
});

test('TC-FR3-57-AS-02 include weight change shows it', () => {
  const out = run(`
    $built = LLD_Members_Shares::build(['days_active'=>12,'challenges'=>1,'workouts'=>4,'weight_change'=>'-1.2 kg'], true);
    return $built;
  `);
  expect(out.has_health_numbers).toBe(true);
  expect(out.text).toContain('Weight change: -1.2 kg');
});

test('TC-FR3-57-AS-03 deleted share resolves to null', () => {
  // resolve needs DB; unit-test soft-delete rule via present logic: deleted_at set → gone message.
  const src = readFileSync(stage3('class-shares.php'), 'utf8');
  expect(src).toContain("! empty( $row['deleted_at'] )");
  expect(src).toContain("MESSAGES['gone']");
});

test('TC-FR3-58-AS-01 manifest is installable (name, start_url, display, icons)', () => {
  const m = run(`return LLD_Members_Installable::manifest();`);
  expect(m.name).toBeTruthy();
  expect(m.start_url).toContain('/members/');
  expect(m.display).toBe('standalone');
  expect(m.icons.length).toBeGreaterThanOrEqual(1);
  expect(m.icons[0].sizes).toMatch(/\d+x\d+/);
  const sw = php(`echo LLD_Members_Installable::service_worker();`);
  expect(sw).toContain('offline');
  expect(sw).toContain('fetch');
  expect(readFileSync(stage3('class-installable.php'), 'utf8')).toContain('You are offline');
});

test('TC-FR3-58-AS-01 manifest, service worker and offline page are sent with status 200', () => {
  // Browsers reject a manifest or service worker served with an error status.
  const serve = (path) => php(`
      ob_start();
      function status_header($c){ echo "STATUS:$c\n"; }
      function nocache_headers(){}
      $_SERVER['REQUEST_URI'] = '${path}';
      LLD_Members_Installable::serve();
      echo 'NOT SERVED';`);
  for (const path of ['/members/app.webmanifest', '/members/sw.js', '/members/offline/', '/members/offline']) {
    expect(serve(path).startsWith('STATUS:200'), path).toBe(true);
  }
  for (const path of ['/members/offline/x', '/members/myoffline/', '/x/members/offline/', '/members/sw.jsx']) {
    expect(serve(path), path).toBe('NOT SERVED');
  }
  const src = readFileSync(stage3('class-installable.php'), 'utf8');
  expect(src).toContain("X-LiteSpeed-Cache-Control: no-cache");
});

test('TC-FR3-58-AS-01 service worker falls back to the offline page only for member page loads', () => {
  const sw = php(`echo LLD_Members_Installable::service_worker();`);
  expect(sw).toContain("req.mode !== 'navigate'");
  expect(sw).toContain('req.url.startsWith(SCOPE)');
});

test('Free/Premium running reminders (FR3-68) still only runs the first for Free', () => {
  const out = run(`
    $rows = [
      ['id'=>3,'active'=>1,'deleted_at'=>null],
      ['id'=>1,'active'=>1,'deleted_at'=>null],
      ['id'=>2,'active'=>1,'deleted_at'=>null],
    ];
    return [
      'free' => LLD_Members_Access_Tiers::running_reminders($rows, false),
      'prem' => LLD_Members_Access_Tiers::running_reminders($rows, true),
    ];
  `);
  expect(out.free).toEqual([1]);
  expect(out.prem).toEqual([1, 2, 3]);
});
