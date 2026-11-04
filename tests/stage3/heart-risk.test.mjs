// Heart Attack and Stroke Risk Check (P3-57, FR3-49): the engine is run in PHP CLI without WordPress against the frozen
// fixtures and the independent Python oracle (ORC-08); the wording and the two WHO data files are checked against the
// signed approval record.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const calcDir = resolve(plugin, 'includes/stage3/calculators');
const engine = resolve(calcDir, 'class-heart-risk.php');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-d', 'memory_limit=512M', '-r', `define('ABSPATH', '/'); require ${toPhpPath(engine)}; ${code}`], { encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 }).trim();
const json = (p) => JSON.parse(readFileSync(resolve(root, p), 'utf8'));
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const fxDir = resolve(root, 'fixtures/heart-risk');
const fixtures = readdirSync(fxDir).filter((f) => f.endsWith('.json') && !f.startsWith('who2019-')).flatMap((f) => json(`fixtures/heart-risk/${f}`).fixtures);
const byId = Object.fromEntries(fixtures.map((f) => [f.id, f]));
const approval = json('approvals/stage3/heart-risk.json');
const oracle = json('oracles/orc-08-heart-risk/results.json');
const H = 'LLD_Members_Heart_Risk';

/** Run compute() over a list of inputs in one PHP call. */
const compute = (inputs) => JSON.parse(php(`echo json_encode(array_map(function ($i) { return ${H}::compute($i); }, json_decode(${JSON.stringify(JSON.stringify(inputs))}, true)));`));
/** The what-if list without the wording helper `under`, as the fixtures and the oracle record it. */
const bare = (r) => ({ ...r, whatif: (r.whatif || []).map(({ under, ...w }) => w) });
const base = byId['FX-HR-LAB-01'].inputs;

test('TC-FR3-49-AS-01', () => {
  // Every result matches the approved reference fixtures (lab, non-lab, the 21-region sweep, stops, what-ifs): the
  // engine against the frozen table, and the engine against the oracle written independently from the sheet.
  const results = compute(fixtures.map((f) => f.inputs)).map(bare);
  const problems = [];
  fixtures.forEach((f, i) => {
    for (const [k, want] of Object.entries(f.expected)) {
      if (JSON.stringify(results[i][k]) !== JSON.stringify(want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != fixture ${JSON.stringify(want)}`);
    }
    const orc = oracle.results.find((r) => r.fixture_id === f.id);
    expect(orc, f.id).toBeTruthy();
    for (const [k, want] of Object.entries(orc.expected)) {
      if (JSON.stringify(results[i][k]) !== JSON.stringify(want)) problems.push(`${f.id}.${k}: engine ${JSON.stringify(results[i][k])} != oracle ${JSON.stringify(want)}`);
    }
  });
  expect(problems).toEqual([]);
  expect(fixtures).toHaveLength(48);
  expect(oracle.oracle_id).toBe('ORC-08');
  expect(oracle.results).toHaveLength(fixtures.length);
  expect(oracle.source_hash).toBe(sha(readFileSync(resolve(root, 'oracles/orc-08-heart-risk/hr_ref.py'))));
  expect(fixtures.filter((f) => f.id.startsWith('FX-HR-REG-')).map((f) => f.inputs.region).sort()).toEqual(JSON.parse(php(`echo json_encode(${H}::regions());`)).sort());

  // mg/dL is converted at 38.67 unrounded and banded on the 1-dp value (sheet section 6; Owner_Questions 63):
  // 193 mg/dL = 4.991 -> 5.0 -> HR-01's cell (7 %); 154 mg/dL = 3.982 -> 4.0 -> 4-4.9; 153 = 3.957 -> 4.0 too;
  // 150 = 3.879 -> 3.9 -> <4. Out of range is refused.
  const mg = (tc) => ({ ...base, tc, tcUnit: 'mg/dL' });
  const [m193, m194, m154, m150, mmol49, low, high, unit] = compute([mg(193), mg(194), mg(154), mg(150), { ...base, tc: 4.95 }, mg(76), mg(465), { ...base, tcUnit: 'g/L' }]);
  expect([m193.tcMmolL, m193.cell.tc, m193.risk]).toEqual([5, '5-5.9', 7]);
  expect([m194.tcMmolL, m194.cell.tc]).toEqual([5, '5-5.9']);
  expect([m154.tcMmolL, m154.cell.tc]).toEqual([4, '4-4.9']);
  expect([m150.tcMmolL, m150.cell.tc]).toEqual([3.9, '<4']);
  expect([mmol49.tcMmolL, mmol49.cell.tc]).toEqual([5, '5-5.9']); // 4.95 rounds half-up to 5.0
  expect([low.error, high.error, unit.error]).toEqual(['tc', 'tc', 'tc']);

  // Cell boundaries: SBP 119/120, 139/140, 179/180 (urgent from 180); BMI 24.95 -> 25.0; age 44/45; 40 and 74 compute.
  const nolab = byId['FX-HR-NOLAB-12'].inputs;
  const b = compute([{ ...base, sbp: 119 }, { ...base, sbp: 120 }, { ...base, sbp: 139 }, { ...base, sbp: 140 }, { ...base, sbp: 179 }, { ...base, sbp: 180 }, { ...base, sbp: 179.5 },
    { ...nolab, bmi: 24.95 }, { ...nolab, bmi: 24.94 }, { ...nolab, bmi: 19.99 }, { ...base, age: 44 }, { ...base, age: 45 }, { ...base, age: 40 }, { ...base, age: 74 }]);
  expect(b.slice(0, 7).map((r) => r.cell.sbp)).toEqual(['<120', '120-139', '120-139', '140-159', '160-179', '>=180', '>=180']);
  expect(b.slice(0, 7).map((r) => r.urgent)).toEqual([false, false, false, false, false, true, true]);
  expect(b.slice(7, 10).map((r) => [r.bmiShown, r.cell.bmi])).toEqual([[25, '25-29'], [24.9, '20-24'], [20, '20-24']]);
  expect(b.slice(10).map((r) => r.cell.age)).toEqual(['40-44', '45-49', '40-44', '70-74']);

  // The urgent level: SBP 180 or more puts the safety layer's urgent key first, before the result, which is still
  // shown below it (HR-14, 66 %).
  const [urgent] = compute([byId['FX-HR-LAB-14'].inputs]);
  expect(urgent.messages[0]).toBe('bp-check.urgent.alert');
  expect(urgent.urgentKey).toBe('bp-check.urgent.alert');
  expect(urgent.messages[1]).toBe('headline');
  expect(urgent.risk).toBe(66);

  // Countries: other names, accents, straight and curly apostrophes, case and spacing, ISO codes, and the site's own
  // extension (territories take the region of the country they belong to; left-out small states their neighbour's).
  const names = {
    'United States': 'High-income North America', USA: 'High-income North America', 'u.s.a.': 'High-income North America', US: 'High-income North America',
    Eswatini: 'Southern Sub-Saharan Africa', 'North Macedonia': 'Central Europe', Czechia: 'Central Europe', 'T\u00fcrkiye': 'North Africa and Middle East',
    Turkiye: 'North Africa and Middle East', Vietnam: 'Southeast Asia', 'C\u00f4te d\u2019Ivoire': 'Western Sub-Saharan Africa', "C\u00f4te d'Ivoire": 'Western Sub-Saharan Africa',
    "cote d'ivoire": 'Western Sub-Saharan Africa', 'Hong Kong': 'East Asia', Macao: 'East Asia', Macau: 'East Asia', '  united   kingdom ': 'Western Europe', UK: 'Western Europe',
    'Timor-Leste': 'Southeast Asia', 'S\u00e3o Tom\u00e9 and Pr\u00edncipe': 'Western Sub-Saharan Africa', 'St Lucia': 'Caribbean', 'The Gambia': 'Western Sub-Saharan Africa',
    'Trinidad & Tobago': 'Caribbean', 'Cura\u00e7ao': 'Western Europe', 'R\u00e9union': 'Western Europe', 'Puerto Rico': 'Caribbean', Liechtenstein: 'Western Europe', Kosovo: 'Central Europe',
    GB: 'Western Europe', DE: 'Western Europe', gb: 'Western Europe',
  };
  const got = JSON.parse(php(`echo json_encode(array_map(fn($n) => ${H}::region_for_country($n), json_decode(${JSON.stringify(JSON.stringify(Object.keys(names)))}, true)));`));
  expect(Object.fromEntries(Object.keys(names).map((n, i) => [n, got[i]]))).toEqual(names);
  expect(compute([{ ...base, country: 'United States' }])[0]).toMatchObject({ region: 'High-income North America', risk: 9 });
  // The site's own country lists: the billing page's GB, DE and US, and every country PHP's time-zone database names
  // (the time-zone country a signed-in member's page is pre-filled from) resolves to a chart region; Antarctica, which
  // has no country, is the only exception.
  const tz = JSON.parse(php(`
    $codes = []; foreach (DateTimeZone::listIdentifiers() as $id) { $l = (new DateTimeZone($id))->getLocation(); if ($l && '??' !== $l['country_code']) $codes[$l['country_code']] = 1; }
    $bad = []; foreach (array_keys($codes) as $c) { $r = ${H}::region_for_country($c); if ('AQ' !== $c && !in_array($r, ${H}::regions(), true)) $bad[] = $c; }
    foreach (['GB', 'DE', 'US'] as $c) if (null === ${H}::region_for_country($c)) $bad[] = "billing $c";
    echo json_encode(['n' => count($codes), 'bad' => $bad]);
  `));
  expect(tz.n).toBeGreaterThan(240);
  expect(tz.bad).toEqual([]);

  // Every country in the region map resolves to one of the 21 chart regions, and every region has its 1,750 cells.
  const map = JSON.parse(php(`
    $doc = json_decode(file_get_contents(${toPhpPath(resolve(calcDir, 'data/who2019-country-to-region.json'))}), true);
    $bad = []; foreach ($doc['countries'] as $name => $row) { if (${H}::region_for_country($name) !== $row['gbd_region'] || !in_array($row['gbd_region'], ${H}::regions(), true)) $bad[] = $name; }
    echo json_encode(['n' => count($doc['countries']), 'bad' => $bad, 'regions' => count(${H}::regions()), 'uk' => ${H}::region_for_country('united kingdom'), 'none' => ${H}::region_for_country('Atlantis')]);
  `));
  expect(map).toEqual({ n: 195, bad: [], regions: 21, uk: 'Western Europe', none: null });
  const cells = json('fixtures/heart-risk/who2019-chart-cells.json');
  let count = 0;
  const walk = (n) => (typeof n === 'number' ? count++ : Object.values(n).forEach(walk));
  walk(cells.charts);
  expect(count).toBe(44100);
});

test('TC-FR3-49-AS-02', () => {
  // The result names the factor whose change lowers the risk most: the adjacent cell per factor, ranked by the fall,
  // ties smoking, blood pressure, cholesterol, weight; the weight what-if is hidden with an eating-disorder answer or
  // profile flag; diabetes is never a what-if.
  const [hr15, hr16, hr22] = compute(['FX-HR-WHATIF-15', 'FX-HR-WHATIF-16', 'FX-HR-WHATIF-22'].map((id) => byId[id].inputs));
  expect([hr15.first, hr16.first, hr22.first]).toEqual(['smoking', 'blood-pressure', 'smoking']);
  expect(hr15.whatif.map((w) => [w.factor, w.under])).toEqual([['smoking', null], ['blood-pressure', 160], ['cholesterol', 7]]);
  expect(hr15.fill).toMatchObject({ risk: '26', n: '26', region: 'Western Europe', factor: 'smoking', new_risk: '16' });
  expect(hr16.fill).toMatchObject({ factor: 'blood-pressure', 'band top': '160', new_risk: '12' });
  expect(hr16.whatif.some((w) => w.factor === 'smoking')).toBe(false);
  expect(hr22.weightWhatifHidden).toBe(true);
  expect(hr22.whatif.some((w) => w.factor === 'weight')).toBe(false);
  expect(hr22.messages).toContain('whatif');
  // A move that leaves the printed figure unchanged is never offered (review of PR #191): UK man 42, non-smoker,
  // SBP 150, TC 3.5 is 2 %, and the band below 140-159 is 2 % too. With no factor left that lowers it and a factor
  // not in its lowest band, neither signed line is true: whatif.noFall (no wording until the owner signs it).
  const [flat] = compute([{ ...base, age: 42, sbp: 150, tc: 3.5 }]);
  expect(flat.risk).toBe(2);
  expect(flat.whatif).toEqual([]);
  expect(flat.first).toBeNull();
  expect(flat.messages).toContain('whatif.noFall');
  expect(flat.messages).not.toContain('whatif.none');
  expect(flat.messages).not.toContain('whatif');
  expect(JSON.parse(php(`echo json_encode(${H}::wording(['whatif.noFall']));`))).toEqual({ 'whatif.noFall': null });
  // The same answers without the eating-disorder answer show the BMI what-if (25-29 -> 21 %, the cell the fixture
  // notes); "prefer not to say" hides it too; the member's profile flag hides it whatever the page said.
  const prof = JSON.parse(php(`echo json_encode(${H}::apply_profile(json_decode(${JSON.stringify(JSON.stringify({ ...byId['FX-HR-WHATIF-22'].inputs, eat: 'no' }))}, true), ['eating_disorder']));`));
  const [open, pnts, flagged] = compute([{ ...byId['FX-HR-WHATIF-22'].inputs, eat: 'no' }, { ...byId['FX-HR-WHATIF-22'].inputs, eat: 'pnts' }, prof]);
  expect(open.whatif.find((w) => w.factor === 'weight')).toEqual({ factor: 'weight', to: '25-29', risk: 21, fall: 3, under: 30 });
  expect(pnts.weightWhatifHidden).toBe(true);
  expect(flagged.weightWhatifHidden).toBe(true);

  // Nothing to change: non-smoker in the lowest SBP and cholesterol bands -> whatif.none; diabetes adds its lines.
  const [none, diab, hiddenOnly] = compute([
    { ...base, sbp: 110, tc: 3.5 },
    { ...base, diabetes: 'yes' },
    { ...byId['FX-HR-NOLAB-12'].inputs, smoke: 'no', sbp: 110, eat: 'yes' },
  ]);
  expect(none.whatif).toEqual([]);
  expect(none.first).toBeNull();
  expect(none.messages).toContain('whatif.none');
  expect(diab.messages).toEqual(expect.arrayContaining(['whatif.diabetes', 'notes.diabetes']));
  expect(diab.whatif.some((w) => w.factor === 'diabetes')).toBe(false);
  // Only the hidden weight factor could move: no "already in the lowest bands" line, which would not be true.
  expect(hiddenOnly.messages).not.toContain('whatif.none');
  expect(hiddenOnly.messages).not.toContain('whatif');
  expect(hiddenOnly.messages).not.toContain('whatif.noFall');

  // A sweep over every region, chart and band: the first factor always has the largest fall, ties go in the signed
  // order, every offered fall is above 0 (so first.fall > 0 whenever first is set), a factor is left out only when
  // its move would not lower the figure, and exactly one what-if line (or none) is shown.
  const sweep = JSON.parse(php(`
    $bad = []; $n = 0; $flat = 0; $tie = ['smoking' => 0, 'blood-pressure' => 1, 'cholesterol' => 2, 'weight' => 3];
    foreach (${H}::regions() as $region) foreach (['male', 'female'] as $sex) foreach (['yes', 'no'] as $smoke) foreach ([42, 57, 72] as $age)
      foreach ([110, 130, 150, 170, 190] as $sbp) foreach ([3.5, 4.5, 5.5, 6.5, 7.5, null] as $tc) {
        $r = ${H}::compute(['age' => $age, 'sex' => $sex, 'region' => $region, 'smoke' => $smoke, 'sbp' => $sbp, 'diabetes' => 'no', 'tc' => $tc, 'bmi' => 33, 'cvd' => 'no', 'preg' => 'no', 'eat' => 'no']);
        $n++;
        $w = $r['whatif'];
        for ($i = 1; $i < count($w); $i++) {
          if ($w[$i]['fall'] > $w[$i - 1]['fall'] || ($w[$i]['fall'] === $w[$i - 1]['fall'] && $tie[$w[$i]['factor']] < $tie[$w[$i - 1]['factor']])) $bad[] = "$region $sex $smoke $age $sbp $tc: order";
        }
        foreach ($w as $x) { if ($x['fall'] <= 0 || $x['fall'] !== $r['risk'] - $x['risk']) $bad[] = "$region $sex $smoke $age $sbp $tc: fall"; }
        if ($r['first'] !== null && $w[0]['fall'] <= 0) $bad[] = "$region $sex $smoke $age $sbp $tc: first.fall";
        if ($smoke === 'yes' && !in_array('smoking', array_column($w, 'factor'), true)) { $ns = ${H}::compute(['smoke' => 'no'] + ['age' => $age, 'sex' => $sex, 'region' => $region, 'sbp' => $sbp, 'diabetes' => 'no', 'tc' => $tc, 'bmi' => 33, 'cvd' => 'no', 'preg' => 'no']); if ($ns['risk'] < $r['risk']) $bad[] = "$region $sex $age $sbp $tc: smoking dropped"; }
        if ($smoke === 'no' && in_array('smoking', array_column($w, 'factor'), true)) $bad[] = "$region: smoking offered to a non-smoker";
        if ($sbp < 120 && in_array('blood-pressure', array_column($w, 'factor'), true)) $bad[] = "$region $sbp: bp below 120";
        if ($r['first'] !== ($w ? $w[0]['factor'] : null)) $bad[] = "$region: first";
        $lines = array_values(array_intersect($r['messages'], ['whatif', 'whatif.none', 'whatif.noFall']));
        $lowest = $smoke === 'no' && $sbp < 120 && null !== $tc && $tc < 4; // BMI 33 is never the lowest band
        $want = $w ? ['whatif'] : ($lowest ? ['whatif.none'] : ['whatif.noFall']);
        if ($lines !== $want) $bad[] = "$region $sex $smoke $age $sbp $tc: lines " . implode(',', $lines);
        if (!$w) $flat++;
      }
    echo json_encode(['n' => $n, 'flat' => $flat, 'bad' => array_slice($bad, 0, 20)]);
  `));
  expect(sweep.n).toBe(21 * 2 * 2 * 3 * 5 * 6);
  expect(sweep.bad).toEqual([]);
  expect(sweep.flat).toBeGreaterThan(0); // the sweep does reach results with no what-if
});

test('TC-FR3-49-AS-03', () => {
  // Inputs outside the method's valid age range show a clear 'not suitable' message and no number; so do existing
  // cardiovascular disease, pregnancy or breastfeeding (yes or not sure, also from the profile), and "prefer not to
  // say" sex (D3-11: cannot calculate).
  const stopIds = ['FX-HR-STOP-17', 'FX-HR-STOP-18', 'FX-HR-STOP-19', 'FX-HR-STOP-21'];
  const profiled = JSON.parse(php(`echo json_encode([${H}::apply_profile(json_decode(${JSON.stringify(JSON.stringify(base))}, true), ['heart_disease']), ${H}::apply_profile(json_decode(${JSON.stringify(JSON.stringify(base))}, true), ['pregnancy']), ${H}::apply_profile(json_decode(${JSON.stringify(JSON.stringify({ ...base, diabetes: undefined }))}, true), ['diabetes']), ${H}::apply_profile(json_decode(${JSON.stringify(JSON.stringify(base))}, true), ['diabetes'])]);`));
  const runs = compute([...stopIds.map((id) => byId[id].inputs), { ...base, age: 18 }, { ...base, age: 99 }, { ...base, preg: 'yes' }, { ...base, sex: 'prefer not to say' }, { ...base, sex: 'pnts', age: 30 }, profiled[0], profiled[1]]);
  const stops = ['under40', 'over74', 'cvd', 'pregnancy', 'under40', 'over74', 'pregnancy', 'sex', 'under40', 'cvd', 'pregnancy'];
  runs.forEach((r, i) => {
    expect(r.stop, String(i)).toBe(stops[i]);
    expect(r.messages).toEqual([`stops.${stops[i]}`]);
    for (const k of ['risk', 'band', 'model', 'cell', 'region', 'first']) expect(r[k], `${i} ${k}`).toBeNull();
    expect(r.whatif).toEqual([]);
  });
  const W = JSON.parse(php(`echo json_encode(${H}::wording(['stops.under40', 'stops.over74', 'stops.cvd', 'stops.pregnancy', 'stops.sex']));`));
  expect(W['stops.under40']).toMatch(/^This check isn't suitable under 40\./);
  expect(W['stops.over74']).toMatch(/^This check isn't suitable over 74\./);
  expect(W['stops.cvd']).toMatch(/haven't had a heart attack, stroke or heart surgery/);
  // The sheet describes these two stops but the approval record holds no signed words for them: no text is shown
  // until the owner signs it.
  expect([W['stops.pregnancy'], W['stops.sex']]).toEqual([null, null]);
  // Diabetes is pre-filled from the profile only when the page left it unanswered.
  expect([profiled[2].diabetes, profiled[3].diabetes]).toEqual(['yes', 'no']);
  // The urgent level is never lost to a stop (safety matrix A1, D5): the safety layer's key comes first, then the
  // stop; pregnancy ("yes" or "not sure") uses the pregnancy threshold (160) and its own urgent line.
  const urgentStops = compute([{ ...base, age: 30, sbp: 200 }, { ...base, age: 80, sbp: 200 }, { ...base, cvd: 'yes', sbp: 220 }, { ...base, preg: 'yes', sbp: 190 },
    { ...base, sex: 'pnts', sbp: 190 }, { ...base, preg: 'not sure', sbp: 165 }, { ...base, preg: 'yes', sbp: 159 }, { ...base, age: 30, sbp: 179 }]);
  expect(urgentStops.map((r) => [r.stop, r.urgent, r.messages])).toEqual([
    ['under40', true, ['bp-check.urgent.alert', 'stops.under40']], ['over74', true, ['bp-check.urgent.alert', 'stops.over74']],
    ['cvd', true, ['bp-check.urgent.alert', 'stops.cvd']], ['pregnancy', true, ['bp-check.pregnancy.urgent', 'stops.pregnancy']],
    ['sex', true, ['bp-check.urgent.alert', 'stops.sex']], ['pregnancy', true, ['bp-check.pregnancy.urgent', 'stops.pregnancy']],
    ['pregnancy', false, ['stops.pregnancy']], ['under40', false, ['stops.under40']],
  ]);
  for (const r of urgentStops) for (const k of ['risk', 'band', 'model', 'first']) expect(r[k]).toBeNull();
  const UW = JSON.parse(php(`echo json_encode(${H}::wording(['bp-check.urgent.alert', 'bp-check.pregnancy.urgent']));`));
  const bpHashes = json('approvals/stage3/bp-check.json').copy_hashes;
  expect(sha(Buffer.from(UW['bp-check.urgent.alert'], 'utf8'))).toBe(bpHashes['urgent.alert']);
  expect(sha(Buffer.from(UW['bp-check.pregnancy.urgent'], 'utf8'))).toBe(bpHashes['pregnancy.urgent']);
  // The two safety answers are required: missing is refused, never taken as "no".
  const { cvd, preg, ...noSafety } = base;
  const missing = compute([{ ...noSafety, preg }, { ...noSafety, cvd }, { ...base, cvd: null }, { ...base, preg: '' }]);
  expect(missing.map((r) => r.error)).toEqual(['cvd', 'preg', 'cvd', 'preg']);
  // Every yes / no answer takes "yes" / "no", true / false and "true" / "false" alike, in any case; "no" never counts
  // as yes; anything else is refused.
  const spell = compute([
    { ...base, cvd: true }, { ...base, cvd: 'true' }, { ...base, cvd: 'YES' }, { ...base, cvd: false }, { ...base, cvd: 'false' }, { ...base, cvd: 'No' },
    { ...base, preg: true }, { ...base, preg: 'false' }, { ...base, smoke: true }, { ...base, smoke: 'false' }, { ...base, diabetes: 'true' }, { ...base, diabetes: false },
    { ...base, eat: true, tc: null }, { ...base, cvd: 'maybe' }, { ...base, smoke: 1 },
  ]);
  expect(spell.map((r) => r.error || r.stop || 'result')).toEqual(['cvd', 'cvd', 'cvd', 'result', 'result', 'result', 'pregnancy', 'result', 'result', 'result', 'result', 'result', 'result', 'cvd', 'smoke']);
  expect([spell[8].cell.smoking, spell[9].cell.smoking, spell[10].cell.diabetes, spell[11].cell.diabetes]).toEqual(['smoker', 'non-smoker', 'yes', 'no']);
  // Outside the page's accepted range (18 to 99), or not a whole number, is refused as bad input.
  const errs = compute([{ ...base, age: 17 }, { ...base, age: 100 }, { ...base, age: 55.5 }, { ...base, sex: 'x' }, { ...base, sbp: 79 }, { ...base, sbp: 251 }, { ...base, country: 'Atlantis' }, { ...base, country: undefined, region: 'Mars' }, { ...base, tc: null, bmi: null }, { ...base, tc: null, bmi: 14 }, { ...base, smoke: 'sometimes' }, { ...base, cvd: ['yes'] }]);
  expect(errs.map((r) => r.error)).toEqual(['age', 'age', 'age', 'sex', 'sbp', 'sbp', 'country', 'region', 'bmi', 'bmi', 'smoke', 'cvd']);
  // The reading is checked before the stops, so the urgent level can always be decided.
  expect(compute([{ ...base, age: 30, sbp: undefined }])[0].error).toBe('sbp');
});

test('TC-FR3-46-AS-03 (heart risk): the urgent level and its wording come from the safety layer, never retyped', () => {
  const src = readFileSync(engine, 'utf8');
  expect(src).not.toMatch(/Your reading is very high|call your local emergency number|maternity unit/i);
  expect(src).not.toMatch(/URGENT_SBP|\$sbp >= \d/);
  expect(src).toMatch(/warning_level' \), 'blood_pressure'/);
  const W = JSON.parse(php(`echo json_encode(${H}::wording(['bp-check.urgent.alert', 'headline']));`));
  expect(Object.keys(W)).toEqual(['bp-check.urgent.alert', 'headline']);
  // Loaded with the Blood Pressure Reading Check, as on the site, the text is the same signed alert.
  const bp = resolve(calcDir, 'class-bp-check.php');
  const bpText = JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(bp)}; require ${toPhpPath(engine)}; echo json_encode(${H}::wording(['bp-check.urgent.alert'])['bp-check.urgent.alert']);`], { encoding: 'utf8' }));
  expect(sha(Buffer.from(bpText, 'utf8'))).toBe(json('approvals/stage3/bp-check.json').copy_hashes['urgent.alert']);
  // The safety layer and the engine agree on every systolic the page accepts, pregnant or not.
  const agree = JSON.parse(php(`
    $bad = [];
    foreach ([false, true] as $p) for ($s = 80; $s <= 250; $s++) {
      $r = ${H}::compute(['age' => 55, 'sex' => 'male', 'country' => 'United Kingdom', 'smoke' => 'no', 'sbp' => $s, 'diabetes' => 'no', 'tc' => 5, 'cvd' => 'no', 'preg' => $p ? 'yes' : 'no']);
      $w = LLD_Members_Stage3_Safety::warning_level('blood_pressure', ['sys' => $s, 'dia' => 60], ['pregnancy' => $p]); // loaded by the engine
      if ($r['urgent'] !== ('urgent' === $w['level']) || ($r['urgent'] && $r['messages'][0] !== $w['key'])) $bad[] = ($p ? 'p' : '') . $s;
      if ($r['urgent'] !== ($s >= ($p ? 160 : 180))) $bad[] = "threshold $s";
    }
    echo json_encode($bad);
  `));
  expect(agree).toEqual([]);
});

test('TC-NFR3-14-AS-02 (heart risk): every signed string and both WHO data files hash to the approval record', () => {
  const M = JSON.parse(php(`echo json_encode(${H}::MESSAGES);`));
  const problems = [];
  const wording = Object.entries(approval.copy_hashes).filter(([k]) => !k.startsWith('data.'));
  for (const [key, hash] of wording) {
    const got = M[key] === undefined ? '(missing)' : sha(Buffer.from(M[key], 'utf8'));
    if (got !== hash) problems.push(`${key}: ${got}`);
  }
  expect(problems).toEqual([]);
  expect(Object.keys(M).sort()).toEqual(wording.map(([k]) => k).sort());
  expect(approval.clinical_signoff.decision).toBe('approved');
  expect(approval.oracle_report).toBe('oracles/orc-08-heart-risk/results.json');
  // The engine's tables are byte-identical to the frozen fixtures and hash to copy_hashes.data.*.
  for (const name of ['who2019-chart-cells', 'who2019-country-to-region']) {
    const shipped = readFileSync(resolve(calcDir, 'data', `${name}.json`));
    const frozen = readFileSync(resolve(fxDir, `${name}.json`));
    expect(Buffer.compare(shipped, frozen), name).toBe(0);
    expect(sha(shipped), name).toBe(approval.copy_hashes[`data.${name}`]);
  }
  // Every key a result can show is signed, an urgent key of the safety layer, or one of the three still unsigned
  // (the pregnancy and "prefer not to say" stops and whatif.noFall, which show no text).
  const keys = new Set(compute([...fixtures.map((f) => f.inputs), { ...base, sex: 'pnts' }, { ...base, age: 42, sbp: 150, tc: 3.5 }]).flatMap((r) => r.messages));
  for (const k of keys) expect(k in M || ['bp-check.urgent.alert', 'bp-check.pregnancy.urgent', 'stops.pregnancy', 'stops.sex', 'whatif.noFall'].includes(k), k).toBe(true);
  // No medicine advice: "statin" never appears in the wording.
  expect(Object.values(M).join(' ')).not.toMatch(/statin/i);
});
