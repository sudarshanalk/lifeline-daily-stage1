// D3-50 (P3-58 version 1.1) through the members' interface, the goal journey's quick save and the metabolic page's
// 7-day average: an impossible pulse is left out, never refused or stored, and the urgent alert is never lost.
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';
import { php as pagePhp, arr, textOf } from './tools/calc-page-php.mjs';
import { plugin } from './tools/calc-page-php.mjs';

const stage3 = resolve(plugin, 'includes/stage3');
const toPhp = (p) => JSON.stringify(p.split(String.fromCharCode(92)).join('/'));
const api = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/');
  if (!class_exists('WP_REST_Response')) { class WP_REST_Response { public $d; public $s; function __construct($d = null, $s = 200) { $this->d = $d; $this->s = $s; } } }
  require ${toPhp(resolve(stage3, 'class-safety-stage3.php'))}; require ${toPhp(resolve(stage3, 'class-health-log.php'))}; require ${toPhp(resolve(stage3, 'class-api.php'))};
  $v = new ReflectionMethod('LLD_Members_Stage3_API', 'validate'); $v->setAccessible(true);
  $check = function ($p) use ($v) { $r = $v->invoke(null, LLD_Members_Stage3_API::types()['readings'], ['reading_type' => 'blood_pressure', 'taken_at' => '2026-11-01 08:00:00', 'value_1' => 190, 'value_2' => 125, 'value_3' => $p], true); return $r instanceof WP_REST_Response ? ['refused' => $r->d] : $r; };
  ${code}`], { encoding: 'utf8' }).trim());

test('TC-FR3-38-AS-04 (members\' interface, D3-50): an impossible pulse reaches the save to be left out, never refused', () => {
  const out = api(`echo json_encode([$check(225), $check(-5), $check(70), $check('fast')]);`);
  expect(out[0]).toMatchObject({ value_3: 225 });
  expect(out[1]).toMatchObject({ value_3: -5 });
  expect(out[2]).toMatchObject({ value_3: 70 });
  expect(out[3]).toEqual({ refused: { message: 'Check the value of value_3.' } }); // not a number at all is still asked for
  // The safety layer then leaves it out, whole beats half-up as the calculator rounds: 220.4 is a possible pulse.
  const w = api(`$w = fn($p) => LLD_Members_Stage3_Safety::warning_level('blood_pressure', ['value_1' => 128, 'value_2' => 82, 'value_3' => $p]);
    echo json_encode([$w(-5), $w(220.4), $w(220.6), $w(29.6)]);`);
  expect(w[0]).toMatchObject({ level: 'none', pulse_left_out: true, pulse_typed: '-5' });
  expect(w[1].pulse_left_out).toBeUndefined();
  expect(w[2]).toMatchObject({ pulse_left_out: true, pulse_typed: '220.6' });
  expect(w[3].pulse_left_out).toBeUndefined();
});

test('TC-FR3-51-AS-01 (page, 7-day average): a reading in the window at the urgent level shows the urgent alert first', () => {
  const stub = (urgent) => `if (!class_exists('LLD_Members_Stage3_API')) { class LLD_Members_Stage3_API { static $u;
    static function run_page_calculator($t, $p, $m) { return ['average_shown' => true, 'average' => ['systolic' => 150, 'diastolic' => 92], 'urgent' => self::$u]; } } }
    LLD_Members_Stage3_API::$u = ${urgent ? 'true' : 'false'};`;
  const values = { age: '52', sex: 'male', background: 'White', pregnantOrBreastfeeding: 'no', waistCm: '102', bpAverage: 'yes', bpMedicine: 'no',
    triglycerides: '2.0', triglyceridesUnit: 'mmol/L', triglyceridesMedicine: 'no', hdl: '0.9', hdlUnit: 'mmol/L', hdlMedicine: 'no',
    fastingGlucose: '6.1', fastingGlucoseUnit: 'mmol/L', type2Diabetes: 'no', fastingGlucoseFasting: 'yes', eatingDisorder: 'no' };
  const ctx = { member: 7, facts: null, health: true, imperial: false, premium: false };
  const page = (urgent) => JSON.parse(pagePhp(`${stub(urgent)}
    $in = LLD_Members_Calc_Page_Metabolic::inputs(${arr(values)}, ${arr(ctx)});
    $r = LLD_Members_Metabolic::compute($in); $r['wording'] = LLD_Members_Metabolic::wording($r['messages'] ?? [], $r);
    echo json_encode(LLD_Members_Calc_Page_Metabolic::result($r, ${arr(ctx)}));`));
  const urgent = page(true);
  expect(textOf(urgent).startsWith('Your reading is very high.')).toBe(true);
  expect(urgent).toContain('role="alert"');
  expect(textOf(page(false))).not.toContain('Your reading is very high.');
});
