// Earlier Stage 2 diary entries in the food tracker (P3-42, FR3-33). Made-up entries only: "homemade curry" is a
// fixture, and cooked rice is 130 kcal per 100 g in this fixture, so 150 g is 195 kcal.
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
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(stage3('calculators/class-calorie-target.php'))}; require ${toPhpPath(stage3('class-food-log.php'))}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const boot = `
$curry = ['id'=>41,'member_id'=>7,'local_date'=>'2026-10-30','meal'=>'dinner','entry_type'=>'description','recipe_version'=>null,'description_ciphertext'=>'CIPHER-NOT-READ-HERE','photo_path'=>'7/diary-20261030190000-abc123.jpg','vegetables'=>1,'protein_source'=>1,'sugary_drink'=>0,'portion'=>'normal','recorded_at'=>'2026-10-30 19:05:00'];
$snack = ['id'=>42,'member_id'=>7,'local_date'=>'2026-10-30','meal'=>'snack','entry_type'=>'planned','recipe_version'=>'oat-bars@v2','description_ciphertext'=>'','photo_path'=>null,'vegetables'=>0,'protein_source'=>null,'sugary_drink'=>0,'portion'=>'','recorded_at'=>'2026-10-30 16:00:00'];
$rice = ['name'=>'Cooked rice','kcal_100g'=>130,'protein_100g'=>2.7,'carb_100g'=>28.2,'fat_100g'=>0.3,'fibre_100g'=>0.4];
function ate($food, $grams, $meal, $date, $id) {
  $n = LLD_Members_Food_Log::from_per_100($food, $grams);
  return ['id'=>$id,'name'=>$food['name'],'food_id'=>$id,'food_kind'=>'food','meal'=>$meal,'entry_date'=>$date,'grams'=>$n['grams'],'kcal'=>$n['kcal'],'protein_g'=>$n['protein_g'],'carb_g'=>$n['carb_g'],'fat_g'=>$n['fat_g'],'fibre_g'=>$n['fibre_g'],'source'=>'search'];
}
$photo = 'https://stg.example.invalid/members/diary-photo/7/?meal=41';
`;
const run = (body) => JSON.parse(php(`${boot}\n${body}`));
const src = (name) => readFileSync(stage3(name), 'utf8');

// The same stubs as safety-stage3.test.mjs: switches on for member 7, and the profile row the test sets.
const safetyStubs = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; } function wp_json_encode($v) { return json_encode($v); }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { static function enabled($f, $m = 0) { return 7 === $m; } static function state($f) { return 'test_accounts'; } }
class LLD_Members_Safety { public static $stage2 = []; static function situations($m) { return self::$stage2[$m] ?? ['none']; } }
class LLD_Members_Account { static function has_consent($m, $t) { return true; } }
class FakeDb { public $profile = []; function prepare($q, ...$a) { return $a[0] ?? $q; } function get_row($m) { return $this->profile[$m] ?? null; } function get_var() { return null; } }
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(stage3('engine/class-rule-catalogue.php'))};
require ${toPhpPath(stage3('engine/class-decision-engine.php'))};
require ${toPhpPath(stage3('class-safety-stage3.php'))};
require ${toPhpPath(stage3('calculators/class-calorie-target.php'))};
require ${toPhpPath(stage3('class-food-log.php'))};
function profile($m, $conditions) { $GLOBALS['wpdb']->profile[$m] = (object) ['conditions' => json_encode($conditions), 'conditions_answered' => 'answered', 'age' => 40, 'height_cm' => 170, 'weight_kg' => 70]; }
`;
const safety = (body) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${safetyStubs}\n${boot.replace(/function ate[\s\S]*?\n}\n/, '')}\n${body}`], { encoding: 'utf8', timeout: 120000 }).trim());

test('TC-FR3-33-AS-01', () => {
  const out = run(`
    $entry = LLD_Members_Food_Log::earlier_entry($curry, 'homemade curry', $photo);
    $day = LLD_Members_Food_Log::with_earlier(LLD_Members_Food_Log::day([], '2026-10-30', null), [$entry, LLD_Members_Food_Log::earlier_entry($snack, '', null)], LLD_Members_Food_Log::earlier_extras(['water_glasses'=>'6','hunger'=>'3','energy'=>null]));
    $other = LLD_Members_Food_Log::with_earlier(LLD_Members_Food_Log::day([], '2026-10-31', null), [$entry], null);
    echo json_encode(['entry'=>$entry,'day'=>$day,'other'=>$other]);
  `);
  expect(out.entry).toMatchObject({
    kind: 'earlier', id: 41, label: 'Earlier diary entry, nutrition unknown', nutrition: 'unknown', read_only: true,
    entry_date: '2026-10-30', meal: 'dinner', description: 'homemade curry', portion: 'normal',
    photo: 'https://stg.example.invalid/members/diary-photo/7/?meal=41',
  });
  expect(out.day.earlier.dinner).toHaveLength(1);
  expect(out.day.earlier.dinner[0].description).toBe('homemade curry');
  expect(out.day.earlier.snacks.map((e) => e.id)).toEqual([42]);
  expect(out.day.earlier.breakfast).toEqual([]);
  expect(out.day.earlier.lunch).toEqual([]);
  expect(out.day.meals.dinner).toEqual([]);
  expect(out.day.earlier_label).toBe('Earlier diary entry, nutrition unknown');
  expect(out.day.earlier_day).toEqual({ water_glasses: 6, hunger: 3, energy: null });
  expect(Object.values(out.other.earlier).flat()).toEqual([]);
  // The day view reads the member's own Stage 2 rows, and the photo link is the Stage 2 one, which checks the owner.
  const food = src('class-food-log.php');
  expect(food).toMatch(/self::earlier_stored\( \$member_id, \$date \)/);
  expect(food).toContain("home_url( '/members/diary-photo/' . (int) $member_id . '/?meal=' . (int) $row['id'] )");
  expect(food).toMatch(/WHERE member_id = %d AND local_date = %s ORDER BY id ASC', \$member_id, \$date/);
  // A member with an eating-disorder flag is not shown the portion of an earlier entry (FR3-69).
  const ed = safety(`
    LLD_Members_Safety::$stage2 = [7 => ['eating-disorder']]; profile(7, ['eating-disorder']);
    $day = LLD_Members_Food_Log::with_earlier(LLD_Members_Food_Log::day([], '2026-10-30', null), [LLD_Members_Food_Log::earlier_entry($curry, 'homemade curry', $photo)], null);
    echo json_encode(LLD_Members_Stage3_Safety::api_rows($day, 'food-day', 7));
  `);
  expect(ed.earlier.dinner[0].portion).toBeNull();
  expect(ed.earlier.dinner[0].description).toBe('homemade curry');
  expect(ed.earlier.dinner[0].photo).toBe('https://stg.example.invalid/members/diary-photo/7/?meal=41');
});

test('TC-FR3-33-AS-02', () => {
  const out = run(`
    $lunch = ate($rice, 150, 'lunch', '2026-10-30', 1);
    $target = ['kcal'=>1800,'protein_g'=>90,'carb_g'=>220,'fat_g'=>60,'fibre_g'=>30,'valid_from'=>'2026-10-01'];
    $plain = LLD_Members_Food_Log::day([$lunch], '2026-10-30', $target);
    $lunchEarlier = $curry; $lunchEarlier['id'] = 43; $lunchEarlier['meal'] = 'lunch';
    $with = LLD_Members_Food_Log::with_earlier($plain, [LLD_Members_Food_Log::earlier_entry($curry, 'homemade curry', $photo), LLD_Members_Food_Log::earlier_entry($lunchEarlier, 'leftover curry', null), LLD_Members_Food_Log::earlier_entry($snack, '', null)], null);
    $onlyEarlier = LLD_Members_Food_Log::with_earlier(LLD_Members_Food_Log::day([], '2026-10-30', $target), [LLD_Members_Food_Log::earlier_entry($curry, 'homemade curry', $photo)], null);
    echo json_encode(['plain'=>$plain,'with'=>$with,'only'=>$onlyEarlier]);
  `);
  expect(out.with.totals).toEqual(out.plain.totals);
  expect(out.with.totals.kcal).toBe(195);
  expect(out.with.left).toEqual(out.plain.left);
  expect(out.with.left.kcal).toBe(1605);
  expect(out.with.over).toEqual(out.plain.over);
  expect(out.with.meals).toEqual(out.plain.meals);
  expect(out.with.meals.lunch).toHaveLength(1);
  expect(out.only.totals).toEqual({ kcal: 0, protein_g: 0, carb_g: 0, fat_g: 0, fibre_g: 0 });
  expect(out.only.left.kcal).toBe(1800);
  for (const entry of Object.values(out.with.earlier).flat()) {
    for (const field of ['kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g', 'grams']) expect(entry, field).not.toHaveProperty(field);
  }
});

test('TC-FR3-33-AS-03', () => {
  const out = run(`
    $before = [$curry, $snack];
    $copy = $before;
    $entries = [LLD_Members_Food_Log::earlier_entry($copy[0], 'homemade curry', $photo), LLD_Members_Food_Log::earlier_entry($copy[1], '', null)];
    LLD_Members_Food_Log::with_earlier(LLD_Members_Food_Log::day([], '2026-10-30', null), $entries, null);
    echo json_encode(['before'=>$before,'after'=>$copy,'entries'=>$entries]);
  `);
  // Field by field: every Stage 2 column of every row is the same after the tracker has read it.
  for (const [i, row] of out.before.entries()) {
    for (const [field, value] of Object.entries(row)) expect(out.after[i][field], `row ${i} ${field}`).toStrictEqual(value);
  }
  // Every field the member gave in Stage 2 is shown as it was saved: meal, date, description, photo, portion,
  // quick checks, planned meal and recipe, and when it was recorded. Nothing is converted to a number.
  expect(out.entries[0]).toMatchObject({ entry_date: '2026-10-30', meal: 'dinner', entry_type: 'description', planned: false, recipe_version: null, description: 'homemade curry', portion: 'normal', photo: 'https://stg.example.invalid/members/diary-photo/7/?meal=41', checks: { vegetables: true, protein_source: true, sugary_drink: false }, recorded_at: '2026-10-30 19:05:00' });
  expect(out.entries[1]).toMatchObject({ meal: 'snacks', entry_type: 'planned', planned: true, recipe_version: 'oat-bars@v2', description: '', portion: null, photo: null, checks: { vegetables: false, protein_source: null, sugary_drink: false } });
  // The tracker only reads the Stage 2 diary tables: no statement in Stage 3 writes to them.
  const stage3Files = ['class-food-log.php', 'class-api.php', 'class-data.php', 'class-schema-stage3.php', 'class-safety-stage3.php'];
  for (const file of stage3Files) {
    const text = src(file);
    expect(text, file).not.toMatch(/(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[^;]*diary_(meal|day)/i);
    expect(text, file).not.toMatch(/->(insert|update|delete|replace)\(\s*[^,]*diary_(meal|day)/);
  }
  // "Download my data" keeps every Stage 2 diary meal and day, with the description readable and the photos included.
  const account = readFileSync(resolve(plugin, 'includes/class-account.php'), 'utf8');
  expect(account).toContain("'diary_meal'     => array( 'description_ciphertext', 'description' )");
  expect(account).toContain("'diary_meal'        => 'Food diary meals'");
  expect(account).toContain("'diary_day'         => 'Food diary days'");
});

test('TC-FR3-33-AS-04', () => {
  const out = run(`
    $once = [LLD_Members_Food_Log::earlier_entry($curry, 'homemade curry', $photo), LLD_Members_Food_Log::earlier_entry($snack, '', null)];
    $first = LLD_Members_Food_Log::with_earlier(LLD_Members_Food_Log::day([], '2026-10-30', null), $once, null);
    $again = LLD_Members_Food_Log::with_earlier($first, array_merge($once, $once), null);
    echo json_encode(['first'=>$first,'again'=>$again]);
  `);
  const ids = (day) => Object.values(day.earlier).flat().map((e) => e.id).sort();
  expect(ids(out.first)).toEqual([41, 42]);
  expect(ids(out.again)).toEqual([41, 42]);
  // Nothing is copied into the new diary, so switching Stage 3 off and on has nothing to copy twice.
  const food = src('class-food-log.php');
  const earlier = food.slice(food.indexOf('public static function earlier_entry'), food.indexOf('Foods this member can log'));
  expect(earlier).not.toContain('LLD_Members_Stage3_Data::insert');
  expect(earlier).not.toMatch(/->insert\(|->update\(|->query\(/);
  expect(src('class-schema-stage3.php')).not.toMatch(/diary_meal|diary_day/);
});
