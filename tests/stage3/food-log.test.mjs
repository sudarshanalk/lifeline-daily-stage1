// Food logging (P3-41, FR3-27 to FR3-32). Made-up foods only: cooked rice is 130 kcal per 100 g in this fixture,
// so 150 g is 195 kcal. Nothing here is a USDA or Open Food Facts record.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(resolve(plugin, 'includes/stage3/calculators/class-calorie-target.php'))}; require ${toPhpPath(resolve(plugin, 'includes/stage3/class-food-log.php'))}; ${code}`], { encoding: 'utf8', timeout: 120000 }).trim();
const boot = `
$rice = ['name'=>'Cooked rice','kcal_100g'=>130,'protein_100g'=>2.7,'carb_100g'=>28.2,'fat_100g'=>0.3,'fibre_100g'=>0.4];
$apple = ['name'=>'Apple','kcal_100g'=>52,'protein_100g'=>0.3,'carb_100g'=>14,'fat_100g'=>0.2,'fibre_100g'=>2.4];
$yogurt = ['name'=>'Yogurt','kcal_100g'=>61,'protein_100g'=>3.5,'carb_100g'=>4.7,'fat_100g'=>3.3,'fibre_100g'=>0];
function ate($food, $grams, $meal, $date, $id, $at = null) {
  $n = LLD_Members_Food_Log::from_per_100($food, $grams);
  return ['id'=>$id,'name'=>$food['name'],'food_id'=>$id,'food_kind'=>'food','meal'=>$meal,'entry_date'=>$date,'grams'=>$n['grams'],'kcal'=>$n['kcal'],'protein_g'=>$n['protein_g'],'carb_g'=>$n['carb_g'],'fat_g'=>$n['fat_g'],'fibre_g'=>$n['fibre_g'],'source'=>'search','created_at'=>$at ?: ($date.' 12:00:00')];
}
`;
// PHP closures do not see the fixture variables, so a wrapped function is run in the same scope as $rice.
const run = (code) => {
  let body = code.trim();
  if (body.startsWith('(function () {')) {
    body = body.slice('(function () {'.length).replace(/\}\)\(\)\s*$/, '');
    body = body.replace(/return\s+([\s\S]*);\s*$/, 'echo json_encode($1);');
  } else {
    body = `echo json_encode(${body});`;
  }
  return JSON.parse(php(`${boot}\n${body}`));
};
const alarming = /\b(warning|danger|alarm|unhealthy|bad|risky|risk|stop|emergency|harm|careful)\b/i;

test('TC-FR3-27-AS-01', () => {
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  const loader = readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8');
  expect(loader).toContain('class-food-log.php');
  expect(api).toContain('LLD_Members_Food_Log::for_member');
  expect(api).toContain('LLD_Members_Food_Log::scale');
  expect(api).toContain('LLD_Members_Food_Log::limit_history');
  expect(api).toContain('LLD_Members_Food_Log::view');
  expect(api).toContain('LLD_Members_Food_Log::copy_into');
  expect(api).toContain('LLD_Members_Food_Log::target_from_result');
  const day = run(`(function () {
    $sent = ['entry_date'=>'2026-11-03','meal'=>'lunch','food_id'=>4,'food_kind'=>'food','grams'=>150,'kcal'=>1,'source'=>'search'];
    $saved = LLD_Members_Food_Log::apply_basis(['per_100g'=>$rice], $sent);
    $day = LLD_Members_Food_Log::day([$saved + ['name'=>'Cooked rice']], '2026-11-03', null);
    return ['saved'=>$saved,'lunch'=>$day['meals']['lunch'],'breakfast'=>$day['meals']['breakfast']];
  })()`);
  expect(day.saved.kcal).toBe(195);
  expect(day.saved.grams).toBe(150);
  expect(day.saved.protein_g).toBe(4.05);
  expect(day.saved.carb_g).toBe(42.3);
  expect(day.lunch).toHaveLength(1);
  expect(day.lunch[0].meal).toBe('lunch');
  expect(day.lunch[0].kcal).toBe(195);
  expect(day.breakfast).toEqual([]);
});

test('TC-FR3-27-AS-02', () => {
  const out = run(`(function () {
    $riceEntry = ate($rice, 150, 'lunch', '2026-11-03', 1);
    $appleEntry = ate($apple, 100, 'snacks', '2026-11-03', 2);
    $before = LLD_Members_Food_Log::day([$riceEntry, $appleEntry], '2026-11-03', null);
    $rice['kcal_100g'] = 900;
    $edited = LLD_Members_Food_Log::scale($riceEntry, 200);
    $after = LLD_Members_Food_Log::day([$edited, $appleEntry], '2026-11-03', null);
    return ['before'=>$before['totals']['kcal'],'entry'=>$edited['kcal'],'after'=>$after['totals']['kcal'],'protein'=>$edited['protein_g']];
  })()`);
  expect(out.before).toBe(247);
  expect(out.entry).toBe(260);
  expect(out.protein).toBe(5.4);
  expect(out.after).toBe(312);
});

test('TC-FR3-27-AS-03', () => {
  const out = run(`(function () {
    $riceEntry = ate($rice, 150, 'lunch', '2026-11-03', 1);
    $appleEntry = ate($apple, 100, 'snacks', '2026-11-03', 2);
    $before = LLD_Members_Food_Log::day([$riceEntry, $appleEntry], '2026-11-03', null)['totals']['kcal'];
    $riceEntry['deleted_at'] = '2026-11-03 13:00:00';
    $after = LLD_Members_Food_Log::day([$riceEntry, $appleEntry], '2026-11-03', null);
    return ['before'=>$before,'after'=>$after['totals']['kcal'],'lunch'=>$after['meals']['lunch'],'snacks'=>$after['meals']['snacks']];
  })()`);
  expect(out.before).toBe(247);
  expect(out.after).toBe(52);
  expect(out.lunch).toEqual([]);
  expect(out.snacks).toHaveLength(1);
});

test('TC-FR3-27-AS-04', () => {
  const out = run(`(function () {
    $rows = [
      ate($apple, 100, 'breakfast', '2026-10-28', 1, '2026-10-28 08:00:00'),
      ate($rice, 150, 'lunch', '2026-10-28', 2, '2026-10-28 12:00:00'),
      ate($yogurt, 100, 'lunch', '2026-11-03', 3, '2026-11-03 12:00:00'),
    ];
    $past = LLD_Members_Food_Log::day($rows, '2026-10-28', null);
    return [
      'open' => LLD_Members_Food_Log::history_open('2026-10-28', '2026-11-03', false),
      'edge' => LLD_Members_Food_Log::history_open('2026-10-21', '2026-11-03', false),
      'older' => LLD_Members_Food_Log::history_open('2026-10-20', '2026-11-03', false),
      'premium' => LLD_Members_Food_Log::history_open('2026-10-20', '2026-11-03', true),
      'breakfast' => array_column($past['meals']['breakfast'], 'name'),
      'lunch' => array_column($past['meals']['lunch'], 'name'),
      'dinner' => $past['meals']['dinner'],
      'today_lunch' => array_column(LLD_Members_Food_Log::day($rows, '2026-11-03', null)['meals']['lunch'], 'name'),
      'locked' => LLD_Members_Food_Log::locked_day('2026-10-01')['message'],
    ];
  })()`);
  expect(out.open).toBe(true);
  expect(out.edge).toBe(true);
  expect(out.older).toBe(false);
  expect(out.premium).toBe(true);
  expect(out.breakfast).toEqual(['Apple']);
  expect(out.lunch).toEqual(['Cooked rice']);
  expect(out.dinner).toEqual([]);
  expect(out.today_lunch).toEqual(['Yogurt']);
  expect(out.locked).toContain('14 days');
  expect(out.locked).not.toMatch(alarming);
});

test('TC-FR3-28-AS-01', () => {
  const out = run(`(function () {
    $result = LLD_Members_Calorie_Target::compute(['sex'=>'F','age'=>45,'heightCm'=>165,'weightKg'=>70,'activity'=>'some','goal'=>'lose','rateKgWeek'=>0.5]);
    $row = LLD_Members_Food_Log::target_from_result($result['targetKcal'], null, '2026-11-03');
    return ['refused'=>$result['refused'],'target'=>$result['targetKcal'],'saved'=>$row['kcal'],'set_by'=>$row['set_by'],'error'=>isset($result['error'])];
  })()`);
  expect(out.error).toBe(false);
  expect(out.refused).toBe(false);
  expect(out.target).toBeGreaterThan(0);
  expect(out.saved).toBe(out.target);
  expect(out.set_by).toBe('calculator');
});

test('TC-FR3-28-AS-02', () => {
  const out = run(`(function () {
    $low = LLD_Members_Food_Log::own_target(1199, 1200, true);
    $ok = LLD_Members_Food_Log::own_target(1200, 1200, true);
    $male = LLD_Members_Food_Log::own_target(1499, 1500, true);
    $note = LLD_Members_Calorie_Target::MESSAGES['floor_note'];
    return ['low'=>$low,'ok'=>$ok,'male'=>$male,'note'=>$note];
  })()`);
  expect(out.low.saved).toBe(false);
  expect(out.low.message).toBe(out.note);
  expect(out.low.message).toContain('safe minimum');
  expect(out.ok.saved).toBe(true);
  expect(out.ok.kcal).toBe(1200);
  expect(out.male.saved).toBe(false);
});

test('TC-FR3-28-AS-03', () => {
  const out = run(`(function () {
    $row = LLD_Members_Food_Log::target_from_result(2000, 88, '2026-11-03');
    return ['protein'=>$row['protein_g'],'kcal'=>$row['kcal'],'from_calories'=> (int) round(2000 * 0.2 / 4)];
  })()`);
  expect(out.kcal).toBe(2000);
  expect(out.protein).toBe(88);
  expect(out.protein).not.toBe(out.from_calories);
});

test('TC-FR3-29-AS-01', () => {
  const out = run(`(function () {
    $rows = [ate($rice, 100, 'lunch', '2026-11-03', 1), ate($apple, 100, 'snacks', '2026-11-03', 2), ate($yogurt, 100, 'dinner', '2026-11-03', 3)];
    return LLD_Members_Food_Log::day($rows, '2026-11-03', null)['totals'];
  })()`);
  expect(out).toEqual({ kcal: 243, protein_g: 6.5, carb_g: 46.9, fat_g: 3.8, fibre_g: 2.8 });
});

test('TC-FR3-29-AS-02', () => {
  const out = run(`(function () {
    $rows = [ate($rice, 100, 'lunch', '2026-11-03', 1), ate($apple, 100, 'snacks', '2026-11-03', 2), ate($yogurt, 100, 'dinner', '2026-11-03', 3)];
    $target = ['kcal'=>1800,'protein_g'=>90,'carb_g'=>220,'fat_g'=>60,'fibre_g'=>30,'valid_from'=>'2026-11-01'];
    $day = LLD_Members_Food_Log::day($rows, '2026-11-03', LLD_Members_Food_Log::target_for_day([$target], '2026-11-03'));
    return ['left'=>$day['left'],'totals'=>$day['totals'],'over'=>$day['over_note']];
  })()`);
  expect(out.left.kcal).toBe(1557);
  expect(out.left.protein_g).toBe(83.5);
  expect(out.left.carb_g).toBe(173.1);
  expect(out.left.fat_g).toBe(56.2);
  expect(out.left.fibre_g).toBe(27.2);
  expect(out.left.kcal).toBe(out.totals ? 1800 - out.totals.kcal : null);
  expect(out.over).toBeNull();
});

test('TC-FR3-29-AS-03', () => {
  const out = run(`(function () {
    $rows = [ate($rice, 100, 'lunch', '2026-11-03', 1), ate($apple, 100, 'snacks', '2026-11-03', 2), ate($yogurt, 100, 'dinner', '2026-11-03', 3)];
    $day = LLD_Members_Food_Log::day($rows, '2026-11-03', ['kcal'=>200,'protein_g'=>90,'carb_g'=>220,'fat_g'=>60,'fibre_g'=>30]);
    return ['over'=>$day['over']['kcal'],'left'=>$day['left']['kcal'],'note'=>$day['over_note'],'totals'=>$day['totals']['kcal']];
  })()`);
  expect(out.totals).toBe(243);
  expect(out.over).toBe(43);
  expect(out.left).toBe(0);
  expect(out.note).toBe('Over by 43 kcal today.');
  expect(out.note).not.toMatch(alarming);
});

test('TC-FR3-30-AS-01', () => {
  const out = run(`(function () {
    $custom = ['id'=>9,'member_id'=>7,'name'=>'Kitchen oat bowl','portion_g'=>50,'kcal'=>180,'protein_g'=>6,'carb_g'=>30,'fat_g'=>4,'fibre_g'=>3];
    $hits = LLD_Members_Food_Log::search([], [$custom], 7, 'oat');
    $logged = LLD_Members_Food_Log::from_portion($custom, 50);
    return ['hits'=>$hits,'logged'=>$logged,'free'=>LLD_Members_Food_Log::gate_custom(false),'paid'=>LLD_Members_Food_Log::gate_custom(true)];
  })()`);
  expect(out.hits).toEqual([{ id: 9, food_kind: 'custom', name: 'Kitchen oat bowl', member_id: 7 }]);
  expect(out.logged.kcal).toBe(180);
  expect(out.free).toBe('Custom foods are part of Premium.');
  expect(out.paid).toBeNull();
});

test('TC-FR3-30-AS-02', () => {
  const hits = run(`LLD_Members_Food_Log::search([], [['id'=>9,'member_id'=>7,'name'=>'Kitchen oat bowl']], 8, 'oat')`);
  expect(hits).toEqual([]);
});

test('TC-FR3-30-AS-03', () => {
  const out = run(`(function () {
    $custom = ['id'=>9,'member_id'=>7,'name'=>'Kitchen oat bowl','portion_g'=>50,'kcal'=>180,'protein_g'=>6,'carb_g'=>30,'fat_g'=>4,'fibre_g'=>3];
    $logged = LLD_Members_Food_Log::apply_basis(['per_portion'=>$custom], ['entry_date'=>'2026-11-03','meal'=>'breakfast','food_id'=>9,'food_kind'=>'custom','grams'=>50,'source'=>'search']);
    $custom['deleted_at'] = '2026-11-03 18:00:00';
    $hits = LLD_Members_Food_Log::search([], [$custom], 7, 'oat');
    $day = LLD_Members_Food_Log::day([$logged], '2026-11-03', null);
    return ['hits'=>$hits,'kcal'=>$day['totals']['kcal'],'stored'=>$logged['kcal']];
  })()`);
  expect(out.hits).toEqual([]);
  expect(out.stored).toBe(180);
  expect(out.kcal).toBe(180);
});

test('TC-FR3-31-AS-01', () => {
  const names = run(`(function () {
    $rows = [
      ate($apple, 100, 'breakfast', '2026-11-03', 2, '2026-11-03 08:00:00'),
      ate($rice, 150, 'lunch', '2026-11-03', 4, '2026-11-03 12:30:00'),
      ate($apple, 80, 'snacks', '2026-11-03', 2, '2026-11-03 15:00:00'),
    ];
    return array_column(LLD_Members_Food_Log::recent($rows), 'name');
  })()`);
  expect(names[0]).toBe('Apple');
  expect(names[1]).toBe('Cooked rice');
  expect(names).toHaveLength(2);
});

test('TC-FR3-31-AS-02', () => {
  const out = run(`(function () {
    $earlier = ate($rice, 150, 'lunch', '2026-11-02', 4, '2026-11-02 12:00:00');
    $favourite = ['food_id'=>4,'food_kind'=>'food','name'=>'Cooked rice'];
    $tap = LLD_Members_Food_Log::log_favourite($favourite, [$earlier], '2026-11-03', 'breakfast');
    $day = LLD_Members_Food_Log::day([$tap], '2026-11-03', null);
    return ['favourite'=>$favourite,'tap'=>$tap,'breakfast'=>$day['meals']['breakfast'][0],'totals'=>$day['totals']['kcal']];
  })()`);
  expect(out.favourite.name).toBe('Cooked rice');
  expect(out.tap.source).toBe('favourite');
  expect(out.tap.grams).toBe(150);
  expect(out.tap.kcal).toBe(195);
  expect(out.breakfast.meal).toBe('breakfast');
  expect(out.totals).toBe(195);
});

test('TC-FR3-32-AS-01', () => {
  const out = run(`(function () {
    $rows = [
      ate($rice, 150, 'breakfast', '2026-11-02', 1, '2026-11-02 08:00:00'),
      ate($yogurt, 100, 'breakfast', '2026-11-02', 2, '2026-11-02 08:05:00'),
      ate($apple, 100, 'lunch', '2026-11-02', 3, '2026-11-02 12:00:00'),
    ];
    $free = LLD_Members_Food_Log::copied($rows, '2026-11-02', '2026-11-03', 'breakfast', false);
    $copied = LLD_Members_Food_Log::copied($rows, '2026-11-02', '2026-11-03', 'breakfast', true);
    $day = LLD_Members_Food_Log::day($copied['entries'], '2026-11-03', null);
    return ['free'=>$free,'meals'=>array_column($day['meals']['breakfast'], 'name'),'lunch'=>$day['meals']['lunch'],'kcal'=>array_column($day['meals']['breakfast'], 'kcal')];
  })()`);
  expect(out.free.error).toBe('Copying a meal or a day is part of Premium.');
  expect(out.meals).toEqual(['Cooked rice', 'Yogurt']);
  expect(out.lunch).toEqual([]);
  expect(out.kcal).toEqual([195, 61]);
});

test('TC-FR3-32-AS-02', () => {
  const out = run(`(function () {
    $rows = [
      ate($rice, 150, 'breakfast', '2026-11-02', 1),
      ate($apple, 100, 'lunch', '2026-11-02', 2),
      ate($yogurt, 100, 'dinner', '2026-11-02', 3),
      ate($apple, 50, 'snacks', '2026-11-02', 4),
    ];
    $original = LLD_Members_Food_Log::day($rows, '2026-11-02', null);
    $copied = LLD_Members_Food_Log::copied($rows, '2026-11-02', '2026-11-03', null, true);
    $day = LLD_Members_Food_Log::day($copied['entries'], '2026-11-03', null);
    $names = [];
    foreach (['breakfast','lunch','dinner','snacks'] as $meal) $names[$meal] = array_column($day['meals'][$meal], 'name');
    return ['from'=>$original['totals'],'to'=>$day['totals'],'names'=>$names,'sources'=>array_column($copied['entries'], 'source')];
  })()`);
  expect(out.to).toEqual(out.from);
  expect(out.names).toEqual({
    breakfast: ['Cooked rice'],
    lunch: ['Apple'],
    dinner: ['Yogurt'],
    snacks: ['Apple'],
  });
  expect(out.sources).toEqual(['copy', 'copy', 'copy', 'copy']);
});
