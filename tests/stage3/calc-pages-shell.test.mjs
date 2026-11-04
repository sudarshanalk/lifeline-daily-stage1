// The calculator pages' shared parts (P3-57 to P3-5A screens): the six addresses, the analytics rule, and the saved
// rows the P3-5B comparison reads (class-api.php comparable_row) for real sealed rows, not only the flat fixtures.
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
      $rows = array_map(fn($r) => LLD_Members_Stage3_API::comparable_row($r + ['calculator' => $calc, 'saved_at' => '2026-10-01 09:00:00']), $rows);
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
