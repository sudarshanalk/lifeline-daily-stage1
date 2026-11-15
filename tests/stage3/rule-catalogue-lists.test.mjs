// P3-36 A4: building Today printed "Array to string conversion" from the rule catalogue's fill(). The goal journey
// passes a stored insight row's values column ({"values": {...}, "text", "notes": [...], "template"}) to fill(), so
// the list-valued fields reached plain(). fill() now reads the gaps from the inner values and leaves list-valued
// fields out: the approved template is filled with the member's own numbers (TC-FR3-17-AS-04), with no warning, and
// no rule's outcome changes (the recorded engine fixtures in decision-engine.test.mjs are unchanged).
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const catalogue = resolve(plugin, 'includes/stage3/engine/class-rule-catalogue.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

/** Runs PHP with every notice and warning shown on stderr; returns the JSON printed and whatever PHP complained about. */
function php(code) {
  const out = spawnSync('php', ['-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(catalogue)}; echo json_encode((function () { ${code} })());`], { encoding: 'utf8', timeout: 120000 });
  return { result: JSON.parse(out.stdout.trim()), stderr: out.stderr };
}

test('TC-FR3-17-AS-04 (list-valued rule fields)', () => {
  const { result, stderr } = php(`
    $plain = ['done' => 40, 'target' => 150, 'suggest' => 20];
    // A stored insight row, as LLD_Members_Insights_Runner writes it and the goal journey reads it back.
    $stored = ['values' => $plain, 'text' => 'x', 'notes' => ['check_with_doctor_before_intense_exercise'], 'template' => 'rule.R-STEP-04'];
    return [
      'plain' => LLD_Members_Rule_Catalogue::fill('rule.R-STEP-04', $plain),
      'stored' => LLD_Members_Rule_Catalogue::fill('rule.R-STEP-04', $stored),
      // A list next to scalar gaps: the list is left out, the gaps are still filled.
      'list' => LLD_Members_Rule_Catalogue::fill('rule.R-STEP-04', $plain + ['offers' => ['later_date', 'different_meal_plan'], 'notes' => []]),
      // A list in the gap itself: the gap is left as it is rather than printing "Array".
      'list_gap' => LLD_Members_Rule_Catalogue::fill('template.T-GEN-01', ['n' => [14]]),
      'gen' => LLD_Members_Rule_Catalogue::fill('template.T-GEN-01', ['values' => ['n' => 14], 'notes' => [], 'template' => 'template.T-GEN-01']),
      'unknown' => LLD_Members_Rule_Catalogue::fill('rule.NOPE', $stored),
    ];
  `);
  const step = "You're at **40** of **150** minutes this week. A **20**-minute brisk walk today keeps you on track. [Done] [Skip]";
  expect(result.plain).toBe(step);
  expect(result.stored).toBe(step);
  expect(result.list).toBe(step);
  expect(result.list_gap).toBe("Keep logging: we'll start spotting patterns after {n} days");
  expect(result.gen).toBe("Keep logging: we'll start spotting patterns after 14 days");
  expect(result.unknown).toBeNull();
  expect(stderr).not.toMatch(/Array to string conversion|Warning|Notice|Deprecated/);
  expect(JSON.stringify(result)).not.toContain('Array');
  // The callers that pass a stored row: the goal journey (plan adjustment, Today step) and the Today insight cards.
  const today = readFileSync(resolve(plugin, 'includes/stage3/screens/class-today-screen.php'), 'utf8');
  expect(today).toContain("LLD_Members_Rule_Catalogue::fill( (string) $rules[ $name ]['template'], is_array( $values ) ? $values : array() )");
  const journey = readFileSync(resolve(plugin, 'includes/stage3/class-goal-journey.php'), 'utf8');
  expect(journey).toContain("$values = json_decode( (string) $ins['values'], true );");
  expect(journey).toContain('$text = (string) LLD_Members_Rule_Catalogue::fill( $template, $values );'); // step_text(); its raw-gap guard is in stored-rows-safety.test.mjs
});
