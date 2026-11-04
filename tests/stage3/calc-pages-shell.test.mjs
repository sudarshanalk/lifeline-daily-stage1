// The calculator pages' shared parts (P3-57 to P3-5A screens): the six addresses, the analytics rule, the rewrite
// flush, the Free tier's saved-results view, and the saved rows the P3-5B comparison reads
// (LLD_Members_Saved_Results::comparable_row) for real sealed rows, not only the flat fixtures.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';
import { php, plugin } from './tools/calc-page-php.mjs';

const stage3 = resolve(plugin, 'includes/stage3');
const src = (f) => readFileSync(resolve(stage3, f), 'utf8');
const toPhp = (p) => JSON.stringify(p.split('\\').join('/'));

test('TC-FR3-55-AS-02 (pages): two saved results as the pages store them are compared, for every calculator', () => {
  const out = JSON.parse(php(`
    require_once ${toPhp(resolve(stage3, 'class-saved-results.php'))};
    if (!class_exists('WP_REST_Response')) { class WP_REST_Response { function __construct($d = null, $s = 200) {} } }
    require_once ${toPhp(resolve(stage3, 'class-api.php'))};
    $pairs = [
      'heart_risk'  => [['headline' => '17', 'result' => json_encode(['risk' => 17, 'band' => 'high', 'model' => 'lab', 'region' => 'Western Europe'])],
                        ['headline' => '11', 'result' => json_encode(['risk' => 11, 'band' => 'high', 'model' => 'lab', 'region' => 'Western Europe'])]],
      'bp_check'    => [['headline' => '142/91', 'result' => json_encode(['systolic' => 142, 'diastolic' => 91, 'category' => 'grade1'])],
                        ['headline' => '128/80', 'result' => json_encode(['systolic' => 128, 'diastolic' => 80, 'category' => 'normal'])]],
      'fitness_age' => [['headline' => '36.1', 'result' => json_encode(['vo2' => 36.1, 'fitness_age' => 66])],
                        ['headline' => '39.4', 'result' => json_encode(['vo2' => 39.4, 'fitness_age' => 58])]],
      'metabolic'   => [['headline' => '3', 'result' => json_encode(['outcome' => 'met', 'criteria_met' => 3])],
                        ['headline' => '1', 'result' => json_encode(['outcome' => 'not met', 'criteria_met' => 1])]],
    ];
    $out = [];
    foreach ($pairs as $calc => $rows) {
      $rows = array_map(fn($r) => LLD_Members_Saved_Results::comparable_row($r + ['calculator' => $calc, 'saved_at' => '2026-10-01 09:00:00']), $rows);
      $rows[1]['saved_at'] = '2026-11-04 09:00:00';
      $out[$calc] = LLD_Members_Saved_Results::compare(['calculator' => $calc, 'results' => $rows]);
    }
    echo json_encode($out);
  `));
  expect(out.heart_risk.wording).toBe('Better: 6 points lower since 1 Oct');
  expect(out.bp_check.wording).toBe('Better: 14/11 lower');
  expect(out.fitness_age.wording).toBe('Better: +3.3 points; fitness age 66 → 58');
  expect(out.metabolic.wording).toBe('Better: 3 → 1 of 4; no longer meets the definition');
  for (const c of Object.values(out)) expect(JSON.stringify(c)).not.toMatch(/NaN|null points/);
});

test('Calculator pages: six addresses, no Google Analytics, nothing stored unless saved', () => {
  const shell = src('screens/class-calculator-pages.php');
  for (const slug of ['heart-risk-check', 'blood-pressure-check', 'metabolic-health-check', 'fitness-age', 'heart-rate-zones', 'sleep-and-stress-check']) {
    expect(shell).toContain(`'${slug}'`);
  }
  // Their screen name is not a public analytics screen, so the tag never loads on a calculator page (NFR3-16).
  const analytics = src('class-analytics.php');
  expect(analytics.match(/const PUBLIC_SCREENS = array\(([^)]*)\)/)[1]).not.toMatch(/calc3/);
  expect(shell).toMatch(/const SCREEN\s+= 'calc3';/);
  // The page saves only through the members' API save path (gate, safety refusals, sealing).
  expect(shell).toMatch(/LLD_Members_Stage3_API::save_page_result\( \$payload \)/);
  expect(shell).not.toMatch(/\$wpdb|Stage3_Data::insert/);
});

test('TC-FR3-55-AS-02 (pages, list): the list view compares real sealed rows and returns them unchanged', () => {
  const out = JSON.parse(php(`
    require_once ${toPhp(resolve(stage3, 'class-saved-results.php'))};
    $rows = [
      ['calculator' => 'bp_check', 'headline' => '128/80', 'unit' => 'mmHg', 'band' => 'normal', 'saved_at' => '2026-11-04 09:00:00', 'result' => json_encode(['systolic' => 128, 'diastolic' => 80])],
      ['calculator' => 'bp_check', 'headline' => '142/91', 'unit' => 'mmHg', 'band' => 'grade1', 'saved_at' => '2026-10-01 09:00:00', 'result' => json_encode(['systolic' => 142, 'diastolic' => 91])],
    ];
    $view = LLD_Members_Saved_Results::present_list($rows, 'premium', true, []);
    echo json_encode(['wording' => $view['comparison']['wording'] ?? null, 'first' => $view['rows'][0]]);
  `));
  expect(out.wording).toBe('Better: 14/11 lower');
  expect(typeof out.first.result).toBe('string'); // the sealed JSON as it was: no lifted fields, no number
  expect(out.first.systolic).toBeUndefined();
});

test('TC-FR3-55-AS-03 (pages, Free tier): the access tiers keep the saved-results view, with its upgrade line', () => {
  const tiers = src('class-access-tiers.php');
  expect(tiers).toMatch(/if \( isset\( \$rows\['rows'\] \) && is_array\( \$rows\['rows'\] \) \) \{\s*\$rows\['rows'\] = self::latest_results\( \$rows\['rows'\] \);\s*return \$rows;/);
  const out = JSON.parse(php(`
    require_once ${toPhp(resolve(stage3, 'class-access-tiers.php'))};
    $view = ['rows' => [['calculator' => 'heart_risk', 'id' => 2, 'created_at' => '2026-11-04 09:00:00'], ['calculator' => 'heart_risk', 'id' => 1, 'created_at' => '2026-10-01 09:00:00']], 'latest_only' => true, 'upgrade_text' => 'Upgrade to see your history and how it has changed'];
    $r = new ReflectionMethod('LLD_Members_Access_Tiers', 'latest_results');
    $limited = $view; $limited['rows'] = $r->invoke(null, $view['rows']);
    echo json_encode($limited);
  `));
  expect(out.upgrade_text).toBe('Upgrade to see your history and how it has changed');
  expect(out.rows).toHaveLength(1);
  expect(out.rows[0].id).toBe(2);
});

test('Calculator pages: the rewrite flush runs at the end of init, and again when the rule is missing', () => {
  const shell = src('screens/class-calculator-pages.php');
  expect(shell).toMatch(/add_action\( 'init', array\( __CLASS__, 'rewrites' \), 20 \);/);
  expect(shell).toMatch(/add_action\( 'init', array\( __CLASS__, 'flush_if_needed' \), 999 \);/);
  expect(shell).toMatch(/! isset\( \$stored\[ self::rule\(\) \] \)/);
  expect(shell).not.toMatch(/function rewrites\(\) \{[^}]*flush_rewrite_rules/);
  // Never cached, and a stale form says so.
  expect(shell).toMatch(/do_action\( 'litespeed_control_set_nocache', 'lld calculator page' \);/);
  expect(shell).toMatch(/if \( \$post && ! \$valid \) \{\s*\$error = self::UI\['expired'\];/);
});
