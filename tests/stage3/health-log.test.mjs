// Health logging (P3-50, FR3-38 to FR3-45). Warnings come only from warning_level(); never re-typed here.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const src = (name) => readFileSync(stage3(name), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const stubs = `
define('ABSPATH', '/');
class WP_REST_Response { public $data; public $status; function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Features { static function enabled($f, $m = 0) { return true; } }
class LLD_Members_Safety { static function situations($m) { return ['none']; } }
class LLD_Members_Account { static function has_consent($m, $t) { return true; } }
class FakeDb { public $profile = []; function prepare($q, ...$a) { return $a[0] ?? $q; } function get_row($m) { return $this->profile[$m] ?? (object)['conditions'=>'[]','conditions_answered'=>'answered','age'=>40,'height_cm'=>170,'weight_kg'=>70]; } function get_var() { return null; } }
$GLOBALS['wpdb'] = new FakeDb();
require ${toPhpPath(stage3('engine/class-rule-catalogue.php'))};
require ${toPhpPath(stage3('engine/class-decision-engine.php'))};
require ${toPhpPath(stage3('class-safety-stage3.php'))};
require ${toPhpPath(stage3('class-health-log.php'))};
`;
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs} ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const run = (code) => JSON.parse(php(`echo json_encode((function () { ${code} })());`));

test('P3-50 wiring: loader and API call Health_Log', () => {
  const loader = readFileSync(stage3('loader.php'), 'utf8');
  const api = src('class-api.php');
  expect(loader).toContain('class-health-log.php');
  expect(api).toContain('LLD_Members_Health_Log::for_member');
  expect(api).toContain('LLD_Members_Health_Log::limit_history');
  expect(api).toContain('LLD_Members_Health_Log::view');
  expect(api).toContain("do_action( 'lld_members_reading_saved'");
  expect(api).toContain('readings-trend');
  expect(api).toContain('readings-activity-week');
  expect(src('class-health-log.php')).toContain('warning_level');
  // Warning strings are never authored in the logging class.
  expect(src('class-health-log.php')).not.toMatch(/Very low blood sugar|Very high blood sugar/);
});

test('TC-FR3-38-AS-01 / AS-03 / AS-04 blood pressure list, range filter, impossible', () => {
  const out = run(`
    $rows = [
      ['id'=>1,'reading_type'=>'blood_pressure','taken_at'=>'2026-11-01 08:00:00','value_1'=>128,'value_2'=>82,'value_3'=>70],
      ['id'=>2,'reading_type'=>'blood_pressure','taken_at'=>'2026-11-05 08:00:00','value_1'=>130,'value_2'=>80,'value_3'=>72],
      ['id'=>3,'reading_type'=>'weight','taken_at'=>'2026-11-03 08:00:00','value_1'=>80],
    ];
    $filtered = LLD_Members_Health_Log::filter($rows, 'blood_pressure', '2026-11-01', '2026-11-03');
    $trend = LLD_Members_Health_Log::trend($rows, 'blood_pressure');
    $bad = LLD_Members_Stage3_Safety::warning_level('blood_pressure', ['value_1'=>400,'value_2'=>20,'value_3'=>70], []);
    $ok = LLD_Members_Stage3_Safety::warning_level('blood_pressure', ['value_1'=>128,'value_2'=>82,'value_3'=>70], []);
    return compact('filtered','trend','bad','ok');
  `);
  expect(out.filtered).toHaveLength(1);
  expect(out.filtered[0].value_1).toBe(128);
  expect(out.trend).toHaveLength(2);
  expect(out.trend[0].value_2).toBe(82);
  expect(out.ok.level).toBe('none');
  expect(out.bad.level).toBe('impossible');
  expect(out.bad).toEqual(run(`return LLD_Members_Stage3_Safety::warning_level('blood_pressure', ['value_1'=>400,'value_2'=>20], []);`));
});

test('TC-FR3-39-AS-01 weight kg stored, shows as 176.4 lb', () => {
  const out = run(`
    $norm = LLD_Members_Health_Log::normalise(['reading_type'=>'weight','value_1'=>80,'unit_shown'=>'kg','taken_at'=>'2026-11-03 08:00:00']);
    $lbIn = LLD_Members_Health_Log::normalise(['reading_type'=>'weight','value_1'=>176.4,'unit_shown'=>'lb','taken_at'=>'2026-11-03 08:00:00']);
    $show = LLD_Members_Health_Log::display_value('weight', $norm['value_1'], 'lb');
    $points = LLD_Members_Health_Log::trend([
      ['id'=>1,'reading_type'=>'weight','taken_at'=>'2026-11-01 08:00:00','value_1'=>80],
      ['id'=>2,'reading_type'=>'weight','taken_at'=>'2026-11-03 08:00:00','value_1'=>79.5],
    ], 'weight');
    return ['kg'=>$norm['value_1'],'fromLb'=>$lbIn['value_1'],'show'=>$show,'points'=>$points];
  `);
  expect(out.kg).toBe(80);
  expect(out.fromLb).toBeCloseTo(80, 1);
  expect(out.show).toBe(176.4);
  expect(out.points).toHaveLength(2);
});

test('TC-FR3-40-AS-01 waist cm and inches', () => {
  const out = run(`
    $cm = LLD_Members_Health_Log::normalise(['reading_type'=>'waist','value_1'=>90,'unit_shown'=>'cm']);
    $in = LLD_Members_Health_Log::normalise(['reading_type'=>'waist','value_1'=>35.4,'unit_shown'=>'in']);
    $show = LLD_Members_Health_Log::display_value('waist', 90, 'in');
    return ['cm'=>$cm['value_1'],'fromIn'=>$in['value_1'],'show'=>$show];
  `);
  expect(out.cm).toBe(90);
  expect(out.fromIn).toBeCloseTo(90, 0);
  expect(out.show).toBeCloseTo(35.4, 1);
});

test('TC-FR3-41-AS-01 / AS-03 activity week against 150-minute guide', () => {
  const out = run(`
    $rows = [
      ['reading_type'=>'activity','taken_at'=>'2026-11-02 09:00:00','value_1'=>30,'value_3'=>2,'detail'=>'brisk walk'],
      ['reading_type'=>'activity','taken_at'=>'2026-11-04 09:00:00','value_1'=>40,'value_3'=>2],
      ['reading_type'=>'activity','taken_at'=>'2026-11-09 09:00:00','value_1'=>99,'value_3'=>2],
    ];
    $week = LLD_Members_Health_Log::activity_week($rows, '2026-11-02');
    $badMonday = LLD_Members_Health_Log::activity_week($rows, '2026-11-03');
    return compact('week','badMonday');
  `);
  expect(out.week.minutes).toBe(70);
  expect(out.week.guide).toBe(150);
  expect(out.week.days['2026-11-02']).toBe(30);
  expect(out.week.days['2026-11-04']).toBe(40);
  expect(out.week.days['2026-11-09']).toBeUndefined();
  expect(out.badMonday.error).toMatch(/Monday/);
});

test('TC-FR3-42-AS-01 / AS-02 sleep hours across midnight', () => {
  const out = run(`
    $a = LLD_Members_Health_Log::sleep_hours(['bedtime'=>'23:00','taken_at'=>'2026-11-03 07:00:00']);
    $b = LLD_Members_Health_Log::normalise(['reading_type'=>'sleep','bedtime'=>'23:00','taken_at'=>'2026-11-03 07:00:00','value_3'=>4]);
    $accept = LLD_Members_Health_Log::accept_input(['reading_type'=>'sleep','bedtime'=>'23:30','taken_at'=>'2026-11-04 06:30:00']);
    return ['hours'=>$a,'norm'=>$b,'accept'=>$accept];
  `);
  expect(out.hours).toBe(8);
  expect(out.norm.value_1).toBe(8);
  expect(out.norm.value_2).toBe(23 * 60);
  expect(out.accept.value_1).toBe(7);
});

test('TC-FR3-43-AS-01 water glasses to ml and day total', () => {
  const out = run(`
    $one = LLD_Members_Health_Log::normalise(['reading_type'=>'water','value_1'=>1,'unit_shown'=>'glasses']);
    $two = LLD_Members_Health_Log::normalise(['reading_type'=>'water','value_1'=>2,'unit_shown'=>'glasses']);
    $rows = [
      ['reading_type'=>'water','taken_at'=>'2026-11-03 09:00:00','value_1'=>$one['value_1']],
      ['reading_type'=>'water','taken_at'=>'2026-11-03 12:00:00','value_1'=>$two['value_1']],
    ];
    $total = LLD_Members_Health_Log::water_day_ml($rows, '2026-11-03');
    $show = LLD_Members_Health_Log::display_value('water', $total, 'glasses');
    return ['one'=>$one['value_1'],'two'=>$two['value_1'],'total'=>$total,'glasses'=>$show];
  `);
  expect(out.one).toBe(250);
  expect(out.two).toBe(500);
  expect(out.total).toBe(750);
  expect(out.glasses).toBe(3);
});

test('TC-FR3-44-AS-01 mood list and trend', () => {
  const out = run(`
    $rows = [
      ['id'=>1,'reading_type'=>'mood','taken_at'=>'2026-11-01 20:00:00','value_1'=>2],
      ['id'=>2,'reading_type'=>'mood','taken_at'=>'2026-11-02 20:00:00','value_1'=>3],
    ];
    $trend = LLD_Members_Health_Log::trend($rows, 'mood');
    $warn = LLD_Members_Stage3_Safety::warning_level('mood', ['mood'=>2], []);
    return ['trend'=>$trend,'warn'=>$warn];
  `);
  expect(out.trend[0].value_1).toBe(2);
  expect(out.warn.level).toBe('none');
});

test('TC-FR3-45-AS-01 / AS-03 glucose mmol and mg/dL, urgent low', () => {
  const out = run(`
    $mmol = LLD_Members_Health_Log::normalise(['reading_type'=>'glucose','value_1'=>5.6,'unit_shown'=>'mmol/L']);
    $fromMg = LLD_Members_Health_Log::normalise(['reading_type'=>'glucose','value_1'=>101,'unit_shown'=>'mg/dL']);
    $show = LLD_Members_Health_Log::display_value('glucose', 5.6, 'mg/dL');
    $urgent = LLD_Members_Stage3_Safety::warning_level('glucose', ['mmol'=>2.5,'unit'=>'mmol/L'], []);
    return ['stored'=>$mmol['value_1'],'fromMg'=>$fromMg['value_1'],'show'=>$show,'urgent'=>$urgent];
  `);
  expect(out.stored).toBe(5.6);
  expect(out.fromMg).toBeCloseTo(5.6, 1);
  expect(out.show).toBe(101);
  expect(out.urgent.level).toBe('urgent');
  expect(out.urgent.message).toBe(run(`return LLD_Members_Stage3_Safety::warning_level('glucose', ['mmol'=>2.5,'unit'=>'mmol/L'], []);`).message);
  expect(out.urgent.emergency_numbers).toBe(true);
});

test('TC-FR3-38 history: Free 14 days, Premium all', () => {
  const out = run(`
    return [
      'open' => LLD_Members_Health_Log::history_open('2026-10-21 08:00:00', '2026-11-03', false),
      'edge' => LLD_Members_Health_Log::history_open('2026-10-21 08:00:00', '2026-11-03', false),
      'older' => LLD_Members_Health_Log::history_open('2026-10-20 08:00:00', '2026-11-03', false),
      'premium' => LLD_Members_Health_Log::history_open('2026-10-01 08:00:00', '2026-11-03', true),
      'limited' => count(LLD_Members_Health_Log::limit_history([
        ['taken_at'=>'2026-10-20 08:00:00','reading_type'=>'weight','value_1'=>80],
        ['taken_at'=>'2026-11-03 08:00:00','reading_type'=>'weight','value_1'=>79],
      ], false, '2026-11-03')),
    ];
  `);
  expect(out.open).toBe(true);
  expect(out.older).toBe(false);
  expect(out.premium).toBe(true);
  expect(out.limited).toBe(1);
});

test('TC-FR3-38-AS-04 (logging, D3-50): an impossible pulse is left out and the reading saved without it', () => {
  const out = run(`
    $left = LLD_Members_Health_Log::for_member(7, [
      'reading_type'=>'blood_pressure','taken_at'=>'2026-11-03 08:00:00','value_1'=>190,'value_2'=>125,'value_3'=>225
    ]);
    $kept = LLD_Members_Health_Log::for_member(7, [
      'reading_type'=>'blood_pressure','taken_at'=>'2026-11-03 08:00:00','value_1'=>128,'value_2'=>82,'value_3'=>125
    ]);
    return compact('left','kept');
  `);
  expect(out.left.error).toBeUndefined();
  expect(out.left.row.value_3).toBeNull();
  expect(out.left.row.urgent_shown).toBe(1); // the urgent alert still shows first
  expect(out.left.warning).toMatchObject({ level: 'urgent', key: 'bp-check.urgent.alert', pulse_left_out: true });
  expect(out.left.warning.pulse_message).toBe("We left out the pulse: 225 isn't possible for a resting pulse. Please check it and add it again.");
  expect(out.kept.row.value_3).toBe(125);
  expect(out.kept.warning.pulse_left_out).toBeUndefined();
});

test('for_member refuses impossible BP and sets urgent_shown', () => {
  const out = run(`
    $bad = LLD_Members_Health_Log::for_member(7, [
      'reading_type'=>'blood_pressure','taken_at'=>'2026-11-03 08:00:00','value_1'=>400,'value_2'=>20,'value_3'=>70
    ]);
    $urgent = LLD_Members_Health_Log::for_member(7, [
      'reading_type'=>'blood_pressure','taken_at'=>'2026-11-03 08:00:00','value_1'=>190,'value_2'=>125,'value_3'=>80
    ]);
    $ok = LLD_Members_Health_Log::for_member(7, [
      'reading_type'=>'blood_pressure','taken_at'=>'2026-11-03 08:00:00','value_1'=>128,'value_2'=>82,'value_3'=>70,'unit_shown'=>'kg'
    ]);
    return compact('bad','urgent','ok');
  `);
  expect(out.bad.error).toBeTruthy();
  expect(out.bad.warning.level).toBe('impossible');
  expect(out.urgent.row.urgent_shown).toBe(1);
  expect(out.urgent.warning.level).toBe('urgent');
  expect(out.ok.row.urgent_shown).toBe(0);
  expect(out.ok.warning.level).toBe('none');
});
