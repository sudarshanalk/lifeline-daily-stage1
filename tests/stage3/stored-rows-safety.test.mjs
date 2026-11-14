// P3-36 A4 (lead finish): the safety matrix at display time, and Today's step without raw gaps.
//
// A4 made stored insight rows show their real numbers. The matrix was asked only when a row was written, so a member
// who declared an eating disorder, pregnancy or kidney disease afterwards still read the stored card (fibre and protein
// grams, waist cm, weight kg). The Today screen, the plan offers and Today's step now ask the matrix again before
// showing a stored row (FR3-69, P3-03 D4). Run in PHP CLI with stubbed WordPress and stored rows; the rule catalogue,
// the safety matrix, the goal journey and the Today screen are the real classes.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const src = (name) => readFileSync(stage3(name), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const TODAY = '2026-11-14';
const stubs = `
define('ABSPATH', '/'); define('DAY_IN_SECONDS', 86400);
function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function home_url($p = '') { return 'https://example.test' . $p; }
function lld_button($a) { return '<a href="' . $a['href'] . '">' . $a['label'] . '</a>'; }
function wp_json_encode($v) { return json_encode($v); }
function lld_members_now_mysql($m = 0) { return '${TODAY} 08:00:00'; }
class LLD_Members_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Stage3_Schema { static function table($s) { return 'wp_lld_' . $s; } }
class LLD_Members_Programme { static function timezone($m) { return new DateTimeZone('UTC'); } }
class WP_Error { public $code; function __construct($c = '', $m = '') { $this->code = $c; } }
class LLD_Members_Safety { public static $stage2 = []; static function situations($m) { return self::$stage2[$m] ?? ['none']; } }
class LLD_Members_Account { static function has_consent($m, $t) { return true; } }
// Member 7 has no health profile (the matrix's "Unknown" row); profile() gives a member an answered one.
class FakeDb { public $profile = []; function prepare($q, ...$a) { return $a[0] ?? $q; } function get_var($q = '') { return null; } function get_row($m = '') { return $this->profile[$m] ?? null; } }
function profile($m, array $stage2) { LLD_Members_Safety::$stage2[$m] = $stage2 ?: ['none']; $GLOBALS['wpdb']->profile[$m] = (object) ['conditions' => json_encode([]), 'conditions_answered' => 'answered', 'age' => 40, 'height_cm' => 170, 'weight_kg' => 70]; }
$GLOBALS['wpdb'] = new FakeDb();
// The stored rows, as LLD_Members_Insights_Runner writes them: values is {"values": {...}, "text", "notes", "template"}.
class LLD_Members_Stage3_Data {
  public static $tables = []; public static $inserted = [];
  static function rows($suffix, $member_id, array $sealed = [], $since = '', $limit = 500) { return self::$tables[$suffix] ?? []; }
  static function row($suffix, $member_id, $id, array $sealed = []) { foreach (self::$tables[$suffix] ?? [] as $r) { if ((int) $r['id'] === (int) $id) { return $r; } } return null; }
  static function update($suffix, $member_id, $id, array $row) { self::$inserted[] = ['update:' . $suffix, $row, []]; return true; }
  static function insert($suffix, $member_id, array $row, array $sealed = []) { self::$inserted[] = [$suffix, $row, $sealed]; return count(self::$inserted); }
}
function insight($id, $rule, array $values) {
  $r = LLD_Members_Rule_Catalogue::rule($rule);
  return ['id' => $id, 'rule_id' => $r['id'], 'dismissed' => 0, 'shown_at' => '${TODAY} 08:00:00', 'values' => json_encode(['values' => $values, 'text' => 'x', 'notes' => [], 'template' => $r['template']])];
}
require ${toPhpPath(stage3('engine/class-rule-catalogue.php'))};
require ${toPhpPath(stage3('engine/class-decision-engine.php'))};
require ${toPhpPath(stage3('engine/class-insights-runner.php'))};
require ${toPhpPath(stage3('class-safety-stage3.php'))};
require ${toPhpPath(stage3('class-goal-rules.php'))};
require ${toPhpPath(stage3('class-goal-journey.php'))};
require ${toPhpPath(stage3('screens/class-today-screen.php'))};
function facts(array $situations, $premium = true) { return ['health' => true, 'premium' => $premium, 'today' => '${TODAY}', 'situations' => $situations]; }
function insights_html(array $situations) { $m = new ReflectionMethod('LLD_Members_Today_Screen', 'insights_html'); $m->setAccessible(true); return $m->invoke(null, 7, facts($situations)); }
`;
const php = (code) => {
  const out = execFileSync('php', ['-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-r', `${stubs} echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000, stdio: ['ignore', 'pipe', 'pipe'] });
  return JSON.parse(out.trim());
};

const STORED = `
  LLD_Members_Stage3_Data::$tables['insights'] = [
    insight(1, 'R-INS-06', ['fibre' => 14, 'target' => 30]),
    insight(2, 'R-INS-07', ['protein' => 48, 'target' => 90]),
    insight(3, 'R-INS-08', ['cm' => 4, 'date' => '1 October']),
    insight(4, 'R-ADJ-01', ['goal' => 70, 'projected' => '20 March', 'target' => '1 February', 'new_date' => '20 March']),
  ];
  LLD_Members_Stage3_Data::$tables['plan_adjustments'] = [
    ['id' => 9, 'plan_id' => 3, 'rule_id' => LLD_Members_Rule_Catalogue::rule('R-ADJ-01')['id'], 'accepted' => null, 'changes' => json_encode(['insight_id' => 4, 'template' => 'rule.R-ADJ-01', 'offers' => ['later_date', 'different_meal_plan']])],
  ];
`;

test('FR3-69: a stored insight is not shown on Today after the member declares an eating disorder, pregnancy or kidney disease', () => {
  const r = php(`${STORED}
    return ['none' => insights_html([]), 'eating_disorder' => insights_html(['eating_disorder']), 'pregnancy' => insights_html(['pregnancy']), 'kidney' => insights_html(['kidney']), 'unknown' => insights_html(['unknown'])];`);
  // With no situation, the stored rows show their real numbers (TC-FR3-17-AS-04) and the offer card is there.
  expect(r.none).toContain('data-lld-rule="R-INS-06"');
  expect(r.none).toContain('<strong>14 g</strong>');
  expect(r.none).toContain('<strong>48 g</strong>');
  expect(r.none).toContain('<strong>4 cm</strong>');
  expect(r.none).toContain('data-lld-section="adjustment"');
  expect(r.none).toContain('<strong>70 kg</strong>');
  expect(r.none).not.toMatch(/\{[a-z_]+\}/);
  // An eating disorder: no fibre, protein, waist or weight card, and no plan offer.
  expect(r.eating_disorder).toBe('');
  // Pregnancy: the weight-tagged rows and the offer go; nothing in grams, cm or kg is left.
  expect(r.pregnancy).not.toMatch(/R-INS-08|R-ADJ-01|data-lld-section="adjustment"|\d+ (g|cm|kg)\b/);
  // Kidney disease: no protein card; the fibre card stays.
  expect(r.kidney).not.toContain('R-INS-07');
  expect(r.kidney).not.toContain('48 g');
  expect(r.kidney).toContain('data-lld-rule="R-INS-06"');
  // The "Unknown" row of the matrix: no insights and no offers.
  expect(r.unknown).toBe('');
});

test('FR3-69: a stored plan offer is not listed after the member declares an eating disorder or pregnancy', () => {
  const r = php(`${STORED}
    $J = 'LLD_Members_Goal_Journey';
    return ['none' => $J::pending_adjustments(7, []), 'eating_disorder' => $J::pending_adjustments(7, ['eating_disorder']), 'pregnancy' => $J::pending_adjustments(7, ['pregnancy']), 'heart' => $J::pending_adjustments(7, ['heart_disease']),
      // With no situations passed, they are looked up: no profile row is the "Unknown" row, which has no adjustments.
      'looked_up' => $J::pending_adjustments(7)];`);
  expect(r.none).toHaveLength(1);
  expect(r.none[0]).toMatchObject({ id: 9, rule: 'R-ADJ-01', offers: ['later_date', 'different_meal_plan'] });
  expect(r.none[0].text).toContain('**70 kg**');
  expect(r.heart).toHaveLength(1);
  expect(r.eating_disorder).toEqual([]);
  expect(r.pregnancy).toEqual([]);
  expect(r.looked_up).toEqual([]);
  // Both screens that list the offers pass the member's situations.
  const today = src('screens/class-today-screen.php');
  expect(today.match(/pending_adjustments\( \$member_id, \$facts\['situations'\] \)/g)).toHaveLength(2);
  expect(today).not.toMatch(/pending_adjustments\( \$member_id \)/);
  expect(today).toContain("LLD_Members_Goal_Journey::rule_allowed( $facts['situations'], $name )");
});

test("Today's step (R-STEP-04): the stored minutes fill the approved text, and a raw gap never reaches a member", () => {
  const r = php(`
    $J = 'LLD_Members_Goal_Journey';
    $step = ['rule_id' => LLD_Members_Rule_Catalogue::rule('R-STEP-04')['id'], 'action_type' => 'activity', 'status' => 'shown'];
    $weigh = ['rule_id' => LLD_Members_Rule_Catalogue::rule('R-STEP-02')['id'], 'action_type' => 'log_reading', 'status' => 'shown'];
    $out = [];
    // A step row with no stored numbers (every row written before this fix): no text, so Today shows the plan's item.
    LLD_Members_Stage3_Data::$tables['insights'] = [];
    $out['no_values'] = $J::step_text(7, $step, '${TODAY}', []);
    // Yesterday's numbers are not today's.
    LLD_Members_Stage3_Data::$tables['insights'] = [['shown_at' => '2026-11-13 08:00:00'] + insight(5, 'R-STEP-04', ['done' => 10, 'target' => 150, 'suggest' => 30])];
    $out['yesterday'] = $J::step_text(7, $step, '${TODAY}', []);
    // The runner writes the step and its numbers together.
    LLD_Members_Stage3_Data::$tables['insights'] = [];
    $o = LLD_Members_Decision_Engine::evaluate(['today' => '${TODAY}', 'plan' => ['id' => 3, 'started' => '2026-11-09', 'activity_minutes_week' => 150], 'daily' => ['activity' => ['2026-11-10' => 40]]])['today_step'];
    $out['engine_step'] = [$o['rule'], $o['values']];
    $w = new ReflectionMethod('LLD_Members_Insights_Runner', 'write_today_step'); $w->setAccessible(true);
    $w->invoke(null, 7, '${TODAY}', $o);
    $out['inserted'] = array_map(function ($i) { return [$i[0], $i[1]['rule_id'] ?? null, $i[2]]; }, LLD_Members_Stage3_Data::$inserted);
    $row = LLD_Members_Stage3_Data::$inserted[0][1];
    LLD_Members_Stage3_Data::$tables['insights'] = [$row + ['id' => 6, 'dismissed' => 0]];
    $out['filled'] = $J::step_text(7, $step, '${TODAY}', []);
    // The step's numbers are not an insight card.
    $out['cards'] = insights_html([]);
    // A step with no numbers writes no insights row.
    LLD_Members_Stage3_Data::$inserted = [];
    $w->invoke(null, 7, '${TODAY}', ['rule' => 'R-STEP-02', 'id' => 12, 'class' => 'today_step', 'template' => 'rule.R-STEP-02', 'text' => 'x', 'values' => [], 'notes' => [], 'action' => 'log_reading']);
    $out['inserted_no_values'] = array_column(LLD_Members_Stage3_Data::$inserted, 0);
    // The safety matrix at display time: the weigh-in step is not shown once an eating disorder or pregnancy is declared.
    $out['weigh'] = [$J::step_text(7, $weigh, '${TODAY}', []), $J::step_text(7, $weigh, '${TODAY}', ['eating_disorder']), $J::step_text(7, $weigh, '${TODAY}', ['pregnancy'])];
    // The generic step (no rule) is unchanged.
    $out['generic'] = $J::step_text(7, ['rule_id' => null, 'action_type' => 'read'], '${TODAY}', []);
    return $out;`);
  expect(r.no_values).toBe('');
  expect(r.yesterday).toBe('');
  expect(r.engine_step).toEqual(['R-STEP-04', { done: 40, target: 150, suggest: 30 }]);
  // One sealed insights row under the step rule's id, then the today_steps row.
  expect(r.inserted).toEqual([['insights', 14, ['values']], ['today_steps', 14, []]]);
  expect(r.filled).toBe("You're at **40** of **150** minutes this week. A **30**-minute brisk walk today keeps you on track. [Done] [Skip]");
  expect(r.cards).toBe('');
  expect(r.inserted_no_values).toEqual(['today_steps']);
  expect(r.weigh[0]).toContain('Weigh-in day.');
  expect(r.weigh.slice(1)).toEqual(['', '']);
  expect(r.generic).toMatch(/^Keep logging: we'll start spotting patterns after \d+ days$/);
  // The step's stored numbers do not start a cooldown: the engine's state reads what was shown from the other classes only.
  const runner = src('engine/class-insights-runner.php');
  const shown = runner.slice(runner.indexOf("$state['shown']                = array();"), runner.indexOf("if ( 'R-INS-09' === $name )"));
  expect(shown).toMatch(/if \( LLD_Members_Rule_Catalogue::CLASS_STEP === \$ids\[ \$name \]\['class'\] \) \{\s*continue;/);
  // When the step has no text, the Next step card is the plan's item, not labelled with the rule.
  const today = src('screens/class-today-screen.php');
  expect(today).toMatch(/step_text\( \$member_id, \$step, \$today, \$facts\['situations'\] \)[\s\S]{0,400}if \( '' === \$text \) \{\s*\$rule = '';/);
});

test('FR3-69: the interface does not return a stored insight after the member declares an eating disorder or kidney disease', () => {
  const r = php(`${STORED}
    LLD_Members_Stage3_Data::$tables['insights'][] = insight(5, 'R-STEP-04', ['done' => 40, 'target' => 150, 'suggest' => 30]);
    $rows = LLD_Members_Stage3_Data::$tables['insights'];
    $S = 'LLD_Members_Stage3_Safety';
    $ids = function ($m) use ($rows, $S) { return array_column($S::api_rows($rows, 'insights', $m), 'id'); };
    profile(9, []); profile(10, ['eating-disorder']); profile(11, ['kidney']);
    return ['situations' => [$S::situations(9), $S::situations(10), $S::situations(11)], 'none' => $ids(9), 'eating_disorder' => $ids(10), 'kidney' => $ids(11), 'unknown' => $ids(7),
      'single_shown' => array_keys($S::api_rows($rows[1], 'insights', 9)), 'single_hidden' => array_keys($S::api_rows($rows[1], 'insights', 11)),
      'other_type' => count($S::api_rows($rows, 'readings', 10))];`);
  expect(r.situations[0]).toEqual([]);
  expect(r.situations[1]).toContain('eating_disorder');
  expect(r.situations[2]).toContain('kidney');
  // The Today step's numbers (id 5) are not an insight in any list.
  expect(r.none).toEqual([1, 2, 3, 4]);
  expect(r.eating_disorder).toEqual([]);
  expect(r.kidney).toEqual([1, 3, 4]);
  expect(r.unknown).toEqual([]);
  expect(r.single_shown).toContain('values');
  expect(r.single_hidden).toContain('id');
  expect(r.single_hidden).not.toContain('values');
  expect(r.other_type).toBe(5);
  // Both interface reads of a stored row go through the filter.
  expect(src('class-api.php').match(/apply_filters\( 'lld_members_stage3_api_rows', \$rows?, \(string\) \$request\['type'\], \$member_id \)/g)).toHaveLength(2);
});

test('FR3-69: a stored plan offer cannot be accepted after the member declares an eating disorder; keeping the plan stays open', () => {
  const r = php(`${STORED}
    $J = 'LLD_Members_Goal_Journey';
    $refused = $J::answer_adjustment(7, 9, 'later_date', facts(['eating_disorder']));
    $writes = count(LLD_Members_Stage3_Data::$inserted);
    $keep = $J::answer_adjustment(7, 9, 'keep', facts(['eating_disorder']));
    return ['refused' => $refused instanceof WP_Error ? $refused->code : $refused, 'writes' => $writes, 'keep' => $keep, 'kept' => LLD_Members_Stage3_Data::$inserted];`);
  expect(r.refused).toBe('lld_adjust');
  expect(r.writes).toBe(0);
  expect(r.keep).toBe(true);
  expect(r.kept).toEqual([['update:plan_adjustments', { accepted: 0 }, []]]);
});
