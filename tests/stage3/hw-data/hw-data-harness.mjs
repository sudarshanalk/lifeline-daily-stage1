// Shared harness for the Healthy Weight data, wiring and food tests (P3-35, package P2). Not a test file.
// Each run is one PHP process: hw-stubs.php stands in for WordPress, the database (in memory) and the Stage 2 plugin;
// the real Stage 3 classes run on top. `world()` makes test members; every member and value is made up.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const root = resolve(here, '../../..');
export const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
export const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
export const src = (name) => readFileSync(stage3(name), 'utf8');
export const pluginSrc = (name) => readFileSync(resolve(plugin, name), 'utf8');
const p = (x) => JSON.stringify(x.split('\\').join('/'));

const CLASSES = ['class-schema-stage3.php', 'class-data.php', 'engine/class-rule-catalogue.php', 'engine/class-decision-engine.php',
  'calculators/class-calorie-target.php', 'calculators/class-goal-timeline.php', 'class-goal-rules.php', 'class-goal-journey.php',
  'healthy-weight/class-hw-where.php', 'healthy-weight/class-hw-festival.php', 'healthy-weight/class-hw-rules.php',
  'healthy-weight/class-hw-answers.php', 'healthy-weight/class-hw-programme.php', 'class-food-log.php', 'class-recipes.php',
  'class-reminders.php', 'class-export-stage3.php', 'class-erasure-stage3.php'];

const track = JSON.parse(readFileSync(resolve(root, 'fixtures/healthy-weight-programme/track-content.json'), 'utf8')).activities;

// The Stage 2 tables these classes read, with the columns they read; then the Stage 3 schema creates its own.
const BOOT = `
require ${p(resolve(here, 'hw-stubs.php'))};
${CLASSES.map((c) => `require ${p(stage3(c))};`).join('\n')}
global $wpdb;
$wpdb->create('wp_lld_member', ['id','email','age','units','timezone','first_name','display_name']);
$wpdb->create('wp_lld_health_profile', ['id','member_id','sex','height_cm','weight_kg','waist_cm','activity_level','diet_type','ethnicity','conditions_answered','conditions']);
$wpdb->create('wp_lld_goal_choice', ['id','member_id','goal_key','sort_order']);
$wpdb->create('wp_lld_calculator_result', ['id','member_id','calculator','headline']);
$wpdb->create('wp_lld_check_in', ['id','member_id','local_date']);
$wpdb->create('wp_lld_admin_log', ['id']);
LLD_Members_Stage3_Schema::update();
LLD_Members_Programme::$acts = json_decode(${JSON.stringify(JSON.stringify(track))}, true);
/** A made-up member: health consent, a profile, Premium by default. */
function member($id, array $o = []) {
  global $wpdb;
  $o = array_merge(['age' => 45, 'sex' => 'F', 'height' => 163, 'weight' => 80, 'waist' => 98, 'activity' => 'some', 'consent' => true, 'premium' => true, 'situations' => ['none'], 'ethnicity' => null], $o);
  $wpdb->insert('wp_lld_member', ['id' => $id, 'email' => "member$id@example.test", 'age' => $o['age'], 'units' => 'metric', 'timezone' => 'UTC', 'first_name' => 'Test', 'display_name' => '']);
  $wpdb->insert('wp_lld_health_profile', ['member_id' => $id, 'sex' => $o['sex'], 'height_cm' => $o['height'], 'weight_kg' => $o['weight'], 'waist_cm' => $o['waist'], 'activity_level' => $o['activity'], 'ethnicity' => $o['ethnicity'], 'conditions_answered' => 'none']);
  LLD_Members_Access::$consent[$id] = $o['consent'];
  LLD_Members_Billing::$premium[$id] = $o['premium'];
  LLD_Members_Stage3_Safety::$situations[$id] = $o['situations'];
}
/** A main lose_weight goal with its version 1 plan, as goal setting leaves it. */
function weight_goal($id, $start = 80, $target = 60, $rate = 0.5) {
  $gid = LLD_Members_Stage3_Data::insert('goals', $id, ['role' => 'main', 'goal_type' => 'lose_weight', 'measure' => 'weight', 'unit' => 'kg', 'start_value' => $start, 'target_value' => $target, 'target_date' => '2027-12-31', 'status' => 'active', 'created_at' => '2026-11-06 09:00:00'], LLD_Members_Goal_Journey::SEALED_GOAL);
  $goal = LLD_Members_Stage3_Data::row('goals', $id, $gid, LLD_Members_Goal_Journey::SEALED_GOAL);
  LLD_Members_Goal_Journey::build_plan($id, $goal, LLD_Members_Goal_Journey::facts($id), ['rate' => $rate]);
  return $gid;
}
function weigh($id, $date, $kg) {
  LLD_Members_Stage3_Data::insert('readings', $id, ['reading_type' => 'weight', 'taken_at' => "$date 07:00:00", 'value_1' => $kg, 'source' => 'manual'], LLD_Members_Goal_Journey::SEALED_READING);
  $cache = new ReflectionProperty('LLD_Members_Goal_Journey', 'daily_cache'); $cache->setAccessible(true); $cache->setValue(null, []); // as save_reading() does
}
/** Every row of a table, as stored (sealed columns still sealed). */
function raw($suffix) { global $wpdb; return array_values($wpdb->tables['wp_lld_' . $suffix]['rows']); }
`;

/** Run PHP code after the boot; the code echoes JSON. */
export function php(code) {
  return execFileSync('php', ['-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', '-r', `${BOOT}\n${code}`], { encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
}
/** Run PHP code that returns a value; the value comes back parsed. */
export const run = (code) => JSON.parse(php(`echo json_encode((function () { ${code} })());`));
/** A JavaScript value as a PHP expression. */
export const arr = (o) => `json_decode(${JSON.stringify(JSON.stringify(o))}, true)`;
