// The owner's calculator decisions of 4 November 2026 (Owner_Questions 95 to 101; wording CW-20 to CW-22 in
// 02 Content/Programme content/calculators/stage3-calculator-wording-draft.md), run in PHP CLI without WordPress.
// The frozen fixtures and both re-run oracles are unchanged by these decisions; these tests cover the cases the
// fixtures do not reach.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const calc = resolve(plugin, 'includes/stage3/calculators');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const files = ['class-hr-zones.php', 'class-fitness-age.php', 'class-heart-risk.php', 'class-metabolic.php', 'class-bp-check.php'].map((f) => resolve(calc, f));
files.push(resolve(plugin, 'includes/stage3/class-safety-stage3.php'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-d', 'memory_limit=512M', '-r',
  `define('ABSPATH', '/'); ${files.map((f) => `require_once ${toPhpPath(f)};`).join(' ')} ${code}`],
{ encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
const run = (code) => JSON.parse(php(code));
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));

test('Owner_Questions 97: heart-rate zones never share an edge', () => {
  // Every age 18 to 99, resting pulse none or 40 to 100, and a measured maximum or none, both methods: each zone
  // starts above the zone below, never above its own top, and a bottom that rounding put on the zone below's top is
  // moved up by exactly 1 bpm (rounding as the engine's half_up).
  const out = run(`
    $bad = []; $moved = 0; $half = fn($x) => (int) floor(round($x, 9) + 0.5);
    foreach (range(18, 99) as $age) { foreach (array_merge([null], range(40, 100)) as $rhr) { foreach ([null, 150, 185, 220] as $measured) {
      if (null !== $measured && null !== $rhr && $measured - $rhr < 30) { continue; }
      $r = LLD_Members_HR_Zones::compute(['age' => $age, 'rhr' => $rhr, 'measuredHrMax' => $measured, 'heartMedicine' => 'no', 'heartCondition' => 'no', 'pregnant' => 'no']);
      if (!empty($r['stop']) || !empty($r['error'])) { continue; }
      if ($r['moderate'][0] <= $r['easy'][1] || $r['hard'][0] <= $r['moderate'][1]) { $bad[] = [$age, $rhr, $measured]; }
      foreach (['easy', 'moderate', 'hard'] as $z) { if ($r[$z][0] > $r[$z][1]) { $bad[] = [$age, $rhr, $measured, $z, 'inverted']; } }
      $max = $r['hrMax'];
      foreach (['moderate' => [40, 64], 'hard' => [60, 77]] as $z => $f) {
        $raw = null === $rhr ? $half($f[1] * $max / 100) : $half($rhr + $f[0] * ($max - $rhr) / 100);
        $below = 'moderate' === $z ? $r['easy'][1] : $r['moderate'][1];
        if ($raw <= $below) { $moved++; if ($r[$z][0] !== $below + 1) { $bad[] = [$age, $rhr, $measured, $z]; } }
      }
    } } }
    echo json_encode(['bad' => $bad, 'moved' => $moved]);
  `);
  expect(out.bad).toEqual([]);
  expect(out.moved).toBeGreaterThan(0); // the case the owner decided on does occur
});

test('Owner_Questions 95 and 96: the fitness-age floor shows the approved no-estimate wording (CW-21)', () => {
  const out = run(`
    $r = LLD_Members_Fitness_Age::compute(['age' => 99, 'sex' => 'female', 'waistCm' => 250, 'rhr' => 100, 'frequency' => 'never', 'intensity' => null, 'duration' => null, 'heartMedicine' => 'no', 'heartCondition' => 'no', 'pregnant' => 'no']);
    echo json_encode(['r' => $r, 'w' => LLD_Members_Fitness_Age::wording($r['messages'] ?? [])]);
  `);
  expect(out.r.stop).toBe('cannotEstimate');
  expect(out.r.messages).toEqual(['stops.cannotEstimate']);
  expect(out.w['stops.cannotEstimate']).toBe("We can't give a reliable fitness estimate from these answers. Your doctor or a supervised fitness test can give a better picture.");
});

test('Owner_Questions 95 and 100: the heart risk check has wording for "no single change" and a 0 % cell (CW-20, CW-22)', () => {
  // Searched over lab inputs in one region, so the cases are real chart cells, not invented ones.
  const out = run(`
    $zero = null; $nofall = null;
    foreach (['female', 'male'] as $sex) { foreach (['no', 'yes'] as $smoke) { foreach ([40, 47, 52, 57, 62, 67, 72] as $age) { foreach ([110, 130, 150, 170] as $sbp) { foreach ([3.5, 4.5, 5.5, 6.5, 7.5] as $tc) {
      $i = ['age' => $age, 'sex' => $sex, 'region' => 'Western Europe', 'smoke' => $smoke, 'sbp' => $sbp, 'diabetes' => 'no', 'tc' => $tc, 'tcUnit' => 'mmol/L', 'bmi' => 24, 'cvd' => 'no', 'preg' => 'no', 'eat' => 'no'];
      $r = LLD_Members_Heart_Risk::compute($i);
      if (null === $zero && 0 === ($r['risk'] ?? null)) { $zero = $r; }
      if (null === $nofall && in_array('whatif.noFall', $r['messages'] ?? [], true)) { $nofall = $r; }
    } } } } }
    echo json_encode(['zero' => $zero, 'zw' => $zero ? LLD_Members_Heart_Risk::wording($zero['messages']) : null,
      'nofall' => $nofall, 'nw' => $nofall ? LLD_Members_Heart_Risk::wording(['whatif.noFall']) : null,
      'one' => LLD_Members_Heart_Risk::compute(['age' => 55, 'sex' => 'male', 'region' => 'Western Europe', 'smoke' => 'no', 'sbp' => 130, 'diabetes' => 'no', 'tc' => 5.0, 'tcUnit' => 'mmol/L', 'bmi' => 26, 'cvd' => 'no', 'preg' => 'no', 'eat' => 'no'])]);
  `);
  expect(out.zero, 'a 0 % cell in Western Europe').not.toBeNull();
  expect(out.zero.messages).toContain('headline.under1');
  expect(out.zero.messages).toContain('comparison.under1');
  expect(out.zero.messages).not.toContain('headline');
  expect(out.zw['headline.under1']).toBe('Your estimated chance of a heart attack or stroke in the next 10 years is less than 1 %');
  expect(out.zw['comparison.under1']).toBe('For comparison, fewer than 1 in 100 people like you would be expected to have a heart attack or stroke in the next 10 years');
  // Any other figure keeps the signed headline and comparison.
  expect(out.one.risk).toBeGreaterThan(0);
  expect(out.one.messages).toContain('headline');
  expect(out.one.messages).toContain('comparison');
  expect(out.nofall, 'a "no single change" case in Western Europe').not.toBeNull();
  {
    expect(out.nofall.messages).toContain('whatif.noFall');
    expect(out.nw['whatif.noFall']).toBe('No single change would lower this estimate on the WHO chart right now. Not smoking, staying active and keeping your blood pressure and cholesterol in check still protect your heart over the years.');
  }
  expect(Object.values(run(`echo json_encode(LLD_Members_Heart_Risk::wording(['whatif.noFall', 'headline.under1', 'comparison.under1']));`)).every((t) => typeof t === 'string' && t.length > 0)).toBe(true);
});

test('Owner_Questions 105: a what-if that lands on a 0 % cell reads "less than 1 %" (CW-24)', () => {
  const out = run(`
    $hit = null;
    foreach (['Southern Latin America', 'Western Europe', 'High-income Asia Pacific', 'Australasia'] as $region) { foreach ([40, 42, 47] as $age) { foreach ([110, 125, 135, 145] as $sbp) { foreach ([3.5, 4.5, 5.5] as $tc) { foreach (['no', 'yes'] as $smoke) {
      $r = LLD_Members_Heart_Risk::compute(['age' => $age, 'sex' => 'female', 'region' => $region, 'smoke' => $smoke, 'sbp' => $sbp, 'diabetes' => 'no', 'tc' => $tc, 'tcUnit' => 'mmol/L', 'bmi' => 24, 'cvd' => 'no', 'preg' => 'no', 'eat' => 'no']);
      if (null === $hit && !empty($r['whatif']) && 0 === $r['whatif'][0]['risk']) { $hit = $r; }
    } } } } }
    echo json_encode(['hit' => $hit, 'w' => LLD_Members_Heart_Risk::wording(['whatif.under1', 'whatif'])]);
  `);
  expect(out.hit, 'a what-if to a 0 % cell').not.toBeNull();
  expect(out.hit.messages).toContain('whatif.under1');
  expect(out.hit.messages).not.toContain('whatif');
  expect(out.w['whatif.under1']).toMatch(/ would take it to less than 1 %\.$/);
  expect(out.w['whatif.under1'].replace(' would take it to less than 1 %.', '')).toBe(out.w.whatif.replace(' would take it to about {new_risk} %.', ''));
});

test('Owner_Questions 98: in pregnancy, 140 to 159 shows the same-day line before the stop, in both checks', () => {
  const base = { age: 35, sex: 'female', region: 'Western Europe', smoke: 'no', diabetes: 'no', tc: 5.0, tcUnit: 'mmol/L', bmi: 24, cvd: 'no', eat: 'no' };
  const out = run(`
    $r = [];
    foreach ([[150, 'yes', 45], [140, 'notsure', 45], [139, 'yes', 45], [165, 'yes', 45], [150, 'no', 45], [150, 'yes', 30], [155, 'yes', 80]] as [$sbp, $preg, $age]) {
      $x = LLD_Members_Heart_Risk::compute(array_merge(json_decode(${JSON.stringify(JSON.stringify(base))}, true), ['sbp' => $sbp, 'preg' => $preg, 'age' => $age]));
      $r[] = ['messages' => $x['messages'], 'urgent' => $x['urgent'], 'stop' => $x['stop']];
    }
    $w = LLD_Members_Heart_Risk::wording(['bp-check.pregnancy.same-day']);
    echo json_encode(['r' => $r, 'w' => $w, 'bp' => LLD_Members_Stage3_Safety::BP_MESSAGES['pregnancy.same-day']]);
  `);
  const [p150, p140, p139, p165, notPregnant, under40, over74] = out.r;
  // The most common case, pregnant and under 40, and over 74: the same-day line comes before the age stop too.
  expect(under40).toEqual({ messages: ['bp-check.pregnancy.same-day', 'stops.under40'], urgent: false, stop: 'under40' });
  expect(over74).toEqual({ messages: ['bp-check.pregnancy.same-day', 'stops.over74'], urgent: false, stop: 'over74' });
  expect(p150).toEqual({ messages: ['bp-check.pregnancy.same-day', 'stops.pregnancy'], urgent: false, stop: 'pregnancy' });
  expect(p140.messages[0]).toBe('bp-check.pregnancy.same-day'); // "not sure" counts as pregnant
  expect(p139.messages).toEqual(['stops.pregnancy']);
  expect(p165.messages[0]).toBe('bp-check.pregnancy.urgent');
  expect(p165.urgent).toBe(true);
  expect(notPregnant.messages).not.toContain('bp-check.pregnancy.same-day');
  // The text is the safety matrix's own line, never retyped.
  expect(out.w['bp-check.pregnancy.same-day']).toBe(out.bp);
});

test('Owner_Questions 99: territories take the WHO region of the part of the world they are in', () => {
  const names = {
    'R\u00e9union': 'Eastern Sub-Saharan Africa', RE: 'Eastern Sub-Saharan Africa', Mayotte: 'Eastern Sub-Saharan Africa',
    Aruba: 'Caribbean', 'Cura\u00e7ao': 'Caribbean', 'Cayman Islands': 'Caribbean', KY: 'Caribbean', Guadeloupe: 'Caribbean', Martinique: 'Caribbean',
    'French Guiana': 'Caribbean', Anguilla: 'Caribbean', 'Sint Maarten': 'Caribbean', 'Turks and Caicos Islands': 'Caribbean',
    'French Polynesia': 'Oceania', 'New Caledonia': 'Oceania', 'Cook Islands': 'Oceania', Niue: 'Oceania', PF: 'Oceania',
    'Falkland Islands': 'Southern Latin America', 'Saint Pierre and Miquelon': 'High-income North America', 'Christmas Island': 'Southeast Asia',
    // The appendix's own territories are unchanged, and so are the small states and the territories next to their country.
    'Puerto Rico': 'Caribbean', Guam: 'Oceania', Bermuda: 'Caribbean', Gibraltar: 'Western Europe', Jersey: 'Western Europe',
    'Hong Kong': 'East Asia', Liechtenstein: 'Western Europe', Kosovo: 'Central Europe', 'Norfolk Island': 'Australasia',
  };
  const got = run(`echo json_encode(array_map(fn($n) => LLD_Members_Heart_Risk::region_for_country($n), json_decode(${JSON.stringify(JSON.stringify(Object.keys(names)))}, true)));`);
  expect(Object.fromEntries(Object.keys(names).map((n, i) => [n, got[i]]))).toEqual(names);
  // The approval record says so too.
  expect(json('approvals/stage3/heart-risk.json').limitations.join(' ')).toMatch(/territory takes the region of the part of the world it is in/);
});

test('Owner_Questions 101: a non-fasting glucose of 11.1 or more gets its own diagnostic-range line', () => {
  const base = { age: null, sex: 'female', background: 'Europid', pregnantOrBreastfeeding: 'no', eatingDisorder: 'no', waistCm: 85, bpSystolic: 120, bpDiastolic: 80, bpMedicine: false, triglycerides: 1.0, triglyceridesUnit: 'mmol/L', hdl: 1.5, hdlUnit: 'mmol/L', hdlMedicine: false, type2Diabetes: false };
  const cases = [
    { fastingGlucose: 12.0, fastingGlucoseUnit: 'mmol/L', fastingGlucoseFasting: false },
    { fastingGlucose: 210, fastingGlucoseUnit: 'mg/dL', fastingGlucoseFasting: false },
    { fastingGlucose: 11.0, fastingGlucoseUnit: 'mmol/L', fastingGlucoseFasting: false },
    { fastingGlucose: 12.0, fastingGlucoseUnit: 'mmol/L', fastingGlucoseFasting: true },
    { fastingGlucose: 3.5, fastingGlucoseUnit: 'mmol/L', fastingGlucoseFasting: false },
  ].map((c) => ({ ...base, ...c }));
  const out = run(`
    $r = array_map(fn($i) => LLD_Members_Metabolic::compute($i), json_decode(${JSON.stringify(JSON.stringify(cases))}, true));
    echo json_encode(['flags' => array_map(fn($x) => $x['flags'], $r), 'criterion' => array_map(fn($x) => $x['criteria']['fastingGlucose'], $r),
      'w' => LLD_Members_Metabolic::wording(['flags.glucoseDiagnosticNonFasting'])]);
  `);
  expect(out.flags[0]).toContain('flags.glucoseDiagnosticNonFasting');
  expect(out.flags[1]).toContain('flags.glucoseDiagnosticNonFasting');
  expect(out.flags[2]).not.toContain('flags.glucoseDiagnosticNonFasting');
  expect(out.flags[3]).toContain('flags.glucoseDiagnostic'); // a fasting value keeps the signed line
  expect(out.flags[3]).not.toContain('flags.glucoseDiagnosticNonFasting');
  expect(out.flags[4]).not.toContain('flags.glucoseLow'); // the low note stays about a fasting result
  // A non-fasting value still never counts towards the criterion.
  expect(out.criterion.slice(0, 3)).toEqual(['unknown', 'unknown', 'unknown']);
  // Its wording is CW-23, approved by the owner on 4 Nov 2026 (Owner_Questions 104).
  expect(out.w['flags.glucoseDiagnosticNonFasting']).toBe("A glucose of 11.1 or more, even when you haven't fasted, is in the range doctors use to diagnose diabetes. Please see your doctor soon to have it checked.");
});

test('Owner_Questions 85: the scheduled Monday run sends no email; the test route still runs the report', () => {
  const src = readFileSync(resolve(plugin, 'includes/stage3/class-weekly-report-stage3.php'), 'utf8');
  expect(src).toMatch(/add_action\( LLD_Members_Growth::REPORT_HOOK, array\( __CLASS__, 'quiet_scheduled_run' \), 1 \)/);
  expect(src).toMatch(/wp_doing_cron\(\)/);
  expect(src).toMatch(/add_filter\( 'pre_wp_mail', array\( __CLASS__, 'skip_report_mail' \), 10, 2 \)/);
  expect(src).toMatch(/add_action\( LLD_Members_Growth::REPORT_HOOK, array\( __CLASS__, 'end_quiet_run' \), 99 \)/);
  expect(src).toMatch(/add_filter\( 'wp_mail', array\( __CLASS__, 'drop_report_recipients' \), 20 \)/);
  // On staging the mail catcher is a pre_wp_mail callback that runs first and forwards allow-listed mail; with no
  // recipients left it has no one to forward the report to (lld_mail_allowed is false for an empty list).
  const catcher = execFileSync('php', ['-d', 'display_errors=stderr', '-r', `
    define('ABSPATH', '/'); function add_filter() {} function add_action() {}
    require ${toPhpPath(resolve(root, 'site/public_html/wp-content/mu-plugins/lld-tools/staging-mail.php'))};
    echo json_encode([lld_mail_allowed([], 'owner@example.test'), lld_mail_allowed(['owner@example.test'], 'owner@example.test')]);
  `], { encoding: 'utf8' }).trim();
  expect(JSON.parse(catcher)).toEqual([false, true]);
  // Run the callbacks in PHP with stand-ins for WordPress: only the report email is held back, only in cron.
  const out = execFileSync('php', ['-d', 'display_errors=stderr', '-r', `
    define('ABSPATH', '/'); $GLOBALS['cron'] = true; $GLOBALS['filters'] = [];
    function wp_doing_cron() { return $GLOBALS['cron']; }
    function add_filter($h, $cb) { $GLOBALS['filters'][$h] = $cb; }
    function remove_filter($h) { unset($GLOBALS['filters'][$h]); }
    function add_action() {}
    class LLD_Members_Growth { const REPORT_HOOK = 'lld_members_weekly_report'; }
    require ${toPhpPath(resolve(plugin, 'includes/stage3/class-weekly-report-stage3.php'))};
    LLD_Members_Stage3_Report::quiet_scheduled_run();
    $on = isset($GLOBALS['filters']['pre_wp_mail']);
    $report = LLD_Members_Stage3_Report::skip_report_mail(null, ['subject' => 'Weekly report']);
    $other = LLD_Members_Stage3_Report::skip_report_mail(null, ['subject' => 'Your Lifeline Daily sign-in code']);
    $to = LLD_Members_Stage3_Report::drop_report_recipients(['to' => 'owner@example.test', 'subject' => 'Weekly report'])['to'];
    $otherTo = LLD_Members_Stage3_Report::drop_report_recipients(['to' => 'm@example.test', 'subject' => 'Your Lifeline Daily sign-in code'])['to'];
    $both = isset($GLOBALS['filters']['wp_mail']);
    LLD_Members_Stage3_Report::end_quiet_run(); $off = !isset($GLOBALS['filters']['pre_wp_mail']) && !isset($GLOBALS['filters']['wp_mail']);
    $GLOBALS['cron'] = false; LLD_Members_Stage3_Report::quiet_scheduled_run(); $route = !isset($GLOBALS['filters']['pre_wp_mail']) && !isset($GLOBALS['filters']['wp_mail']);
    echo json_encode([$on, $report, $other, $to, $otherTo, $both, $off, $route]);
  `], { encoding: 'utf8' }).trim();
  expect(JSON.parse(out)).toEqual([true, true, null, [], 'm@example.test', true, true, true]);
});
