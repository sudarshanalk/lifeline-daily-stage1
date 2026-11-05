// Barcode scanning in the browser (P3-44, FR3-37). Made-up products only: the barcodes use the GS1 prefix 2, kept for
// in-store numbering, so none is a real product; 012345678905 is the standard UPC-A example number.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code) => JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); require ${toPhpPath(resolve(plugin, 'includes/stage3/class-barcode.php'))}; require ${toPhpPath(resolve(plugin, 'includes/stage3/class-safety-stage3.php'))}; ${boot} echo json_encode(${code});`], { encoding: 'utf8', timeout: 120000 }).trim());
const boot = `
$bar = ['id'=>7,'source'=>'off','name'=>'Test oat bar','brand'=>'Testbrand','barcode'=>'2000000000015','portions'=>'[{"name":"1 bar (40 g)","grams":40}]','kcal_100g'=>400,'protein_100g'=>10,'carb_100g'=>60,'fat_100g'=>12,'fibre_100g'=>8,'attribution'=>'Open Food Facts contributors, ODbL'];
$usda = ['id'=>3,'source'=>'usda','name'=>'Test oat bar (generic)','brand'=>'','barcode'=>'2000000000015','portions'=>'[]','kcal_100g'=>390,'protein_100g'=>null,'carb_100g'=>null,'fat_100g'=>null,'fibre_100g'=>null,'attribution'=>''];
$upca = ['id'=>9,'source'=>'off','name'=>'Test crackers','brand'=>'','barcode'=>'0012345678905','portions'=>'[]','kcal_100g'=>450,'protein_100g'=>9,'carb_100g'=>70,'fat_100g'=>15,'fibre_100g'=>3,'attribution'=>''];
$mine = ['id'=>21,'member_id'=>5,'name'=>'My test granola','barcode'=>'2000000000022','portion_g'=>45,'kcal'=>200,'protein_g'=>5,'carb_g'=>30,'fat_g'=>7,'fibre_g'=>4,'deleted_at'=>null];
$theirs = ['id'=>22,'member_id'=>6,'name'=>'Someone else','barcode'=>'2000000000039','portion_g'=>30,'kcal'=>100,'protein_g'=>1,'carb_g'=>1,'fat_g'=>1,'fibre_g'=>1,'deleted_at'=>null];
`;

// ---- The browser's reader (assets/stage3.js), run without a browser --------------------------------------------------
const script = readFileSync(resolve(plugin, 'assets/stage3.js'), 'utf8');
const sandbox = { document: { readyState: 'complete', querySelector: () => null, addEventListener() {} } };
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(script, sandbox);
const D = sandbox.LLDBarcodeDecoder;

const Lw = ['3211', '2221', '2122', '1411', '1132', '1231', '1114', '1312', '1213', '3112'].map((s) => [...s].map(Number));
const FIRST = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLL', 'LGLGGL', 'LGGLGL'];
const UPCE = ['EEEOOO', 'EEOEOO', 'EEOOEO', 'EEOOOE', 'EOEEOO', 'EOOEEO', 'EOOOEE', 'EOEOEO', 'EOEOOE', 'EOOEOE'];
// Bars as 1 (dark) and 0 (light) modules, with a quiet zone either side.
function modules(code) {
  const bits = [];
  const put = (widths, startDark) => widths.forEach((w, i) => { for (let k = 0; k < w; k++) bits.push((i % 2 === 0) === startDark ? 1 : 0); });
  const quiet = () => { for (let k = 0; k < 11; k++) bits.push(0); };
  const digitL = (d) => put(Lw[d], false);
  const digitG = (d) => put([...Lw[d]].reverse(), false);
  const digitR = (d) => put(Lw[d], true);
  quiet();
  if (code.length === 13) {
    put([1, 1, 1], true);
    for (let i = 1; i <= 6; i++) (FIRST[+code[0]][i - 1] === 'L' ? digitL : digitG)(+code[i]);
    put([1, 1, 1, 1, 1], false);
    for (let i = 7; i <= 12; i++) digitR(+code[i]);
    put([1, 1, 1], true);
  } else if (code.length === 8) {
    put([1, 1, 1], true);
    for (let i = 0; i < 4; i++) digitL(+code[i]);
    put([1, 1, 1, 1, 1], false);
    for (let i = 4; i < 8; i++) digitR(+code[i]);
    put([1, 1, 1], true);
  }
  quiet();
  return bits;
}
function upceModules(code) {
  const bits = [];
  const put = (widths, startDark) => widths.forEach((w, i) => { for (let k = 0; k < w; k++) bits.push((i % 2 === 0) === startDark ? 1 : 0); });
  for (let k = 0; k < 11; k++) bits.push(0);
  put([1, 1, 1], true);
  let parity = UPCE[+code[7]];
  if (code[0] === '1') parity = parity.replace(/[OE]/g, (c) => (c === 'O' ? 'E' : 'O'));
  for (let i = 1; i <= 6; i++) put(parity[i - 1] === 'O' ? Lw[+code[i]] : [...Lw[+code[i]]].reverse(), false);
  put([1, 1, 1, 1, 1, 1], false);
  for (let k = 0; k < 11; k++) bits.push(0);
  return bits;
}
// A camera-like picture: a fractional module width, blur, light falling off to one side, noise and an optional flip.
function picture(bits, { px = 2.7, height = 90, blur = 1, flip = false, light = 0.5, noise = 18, seed = 1 } = {}) {
  const width = Math.ceil(bits.length * px) + 40;
  let line = new Float64Array(width);
  for (let x = 0; x < width; x++) {
    const m = Math.floor((x - 20) / px);
    line[x] = m >= 0 && m < bits.length && bits[m] ? 35 : 225;
  }
  for (let b = 0; b < blur; b++) {
    const next = new Float64Array(width);
    for (let x = 0; x < width; x++) next[x] = (line[Math.max(0, x - 1)] + line[x] + line[Math.min(width - 1, x + 1)]) / 3;
    line = next;
  }
  if (flip) line.reverse();
  let s = seed;
  const rand = () => ((s = (s * 16807) % 2147483647) / 2147483647);
  const data = new Uint8ClampedArray(width * height * 4);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const v = line[x] * (1 - light * (x / width)) + (rand() - 0.5) * noise;
      const p = (y * width + x) * 4;
      data[p] = data[p + 1] = data[p + 2] = v;
      data[p + 3] = 255;
    }
  }
  return { data, width, height };
}
const read = (pic) => D.decodeImage(pic.data, pic.width, pic.height);

test('TC-FR3-37-AS-01 a barcode in the database: the product and its nutrition are shown, ready to log', () => {
  const r = php(`LLD_Members_Barcode::lookup([$usda, $bar, $upca], [$mine, $theirs], 5, '2000000000015')`);
  expect(r.found).toBe(true);
  expect(r.barcode).toBe('2000000000015');
  // The packaged-food source with full nutrition wins over a thinner row with the same barcode.
  expect(r.food).toMatchObject({ food_id: 7, food_kind: 'food', name: 'Test oat bar', brand: 'Testbrand', basis: 'per_100g', basis_grams: 100, default_grams: 40 });
  expect(r.food.per).toEqual({ kcal: 400, protein_g: 10, carb_g: 60, fat_g: 12, fibre_g: 8 });
  expect(r.food.portions).toEqual([{ name: '1 bar (40 g)', grams: 40 }, { name: '100 g', grams: 100 }]);
  expect(r.food.attribution).toContain('Open Food Facts');
  // What the screen logs: food-log takes food_id, food_kind and grams and works the nutrition out itself.
  expect(Number.isInteger(r.food.food_id) && r.food.food_id > 0).toBe(true);
});

test('TC-FR3-37-AS-01 the same product under its other barcode forms (UPC-A, EAN-13, GTIN-14, UPC-E, spaces)', () => {
  const r = php(`[
    LLD_Members_Barcode::lookup([$upca], [], 5, '012345678905')['food']['food_id'] ?? null,
    LLD_Members_Barcode::lookup([$upca], [], 5, '0 012345 678905')['food']['food_id'] ?? null,
    LLD_Members_Barcode::lookup([$upca], [], 5, '00012345678905')['food']['food_id'] ?? null,
    LLD_Members_Barcode::upce_to_upca('01234565'),
    LLD_Members_Barcode::clean('2000000000016'),
    LLD_Members_Barcode::clean('12345'),
    LLD_Members_Barcode::check_ok('4006381333931'),
  ]`);
  expect(r.slice(0, 3)).toEqual([9, 9, 9]);
  expect(r[3]).toBe('012345000065');
  expect(r[4]).toBe(''); // wrong check digit
  expect(r[5]).toBe('');
  expect(r[6]).toBe(true);
  const bad = php(`LLD_Members_Barcode::lookup([$bar], [], 5, '2000000000016')`);
  expect(bad.status).toBe(400);
  expect(bad.error).toMatch(/barcode/i);
});

test('TC-FR3-37-AS-01 the reader in the browser reads EAN-13, UPC-A, EAN-8 and UPC-E from camera-like pictures', () => {
  expect(read(picture(modules('2000000000015')))).toBe('2000000000015');
  expect(read(picture(modules('0012345678905'), { px: 3.3, blur: 2, light: 0.6 }))).toBe('0012345678905');
  expect(read(picture(modules('4006381333931'), { flip: true, seed: 7 }))).toBe('4006381333931'); // upside down
  expect(read(picture(modules('96385074'), { px: 3.1 }))).toBe('96385074');
  expect(read(picture(upceModules('01234565'), { px: 3 }))).toBe('01234565');
  expect(D.upceToUpca('01234565')).toBe('012345000065');
  // Nothing is read from a picture with no barcode, or from bars whose check digit is wrong.
  expect(read(picture([], {}))).toBe(null);
  expect(read(picture(modules('2000000000016')))).toBe(null);
  expect(read(picture(modules('5901234123457'), { px: 4.5, blur: 4, light: 0.6, noise: 25 }))).toBe('5901234123457'); // a soft, noisy frame
  // Random bars are never read as a barcode.
  let s = 4242;
  const rnd = () => ((s = (s * 16807) % 2147483647) / 2147483647);
  let misreads = 0;
  for (let t = 0; t < 400; t++) {
    const bits = [];
    const n = 60 + Math.floor(rnd() * 80);
    for (let i = 0; i < n; i++) { const w = 1 + Math.floor(rnd() * 4); for (let k = 0; k < w; k++) bits.push(i % 2 ? 0 : 1); }
    if (read(picture(bits, { px: 2 + rnd() * 3, blur: Math.floor(rnd() * 3), seed: t + 1, noise: 25 }))) misreads++;
  }
  expect(misreads).toBe(0);
});

test("TC-FR3-37-AS-02 a barcode not in the database: the member is offered 'Add this product'", () => {
  const r = php(`LLD_Members_Barcode::lookup([$bar], [$theirs], 5, '2000000000039')`); // another member's product is never found
  expect(r.found).toBe(false);
  expect(r.barcode).toBe('2000000000039');
  expect(r.offer).toEqual({ label: 'Add this product', type: 'custom-foods', fields: { barcode: '2000000000039' } });
  expect(r.message).toMatch(/add it/i);
  // Once added, the member's own product is found by its barcode, before any shared food with the same number.
  const after = php(`LLD_Members_Barcode::lookup([$bar + ['barcode'=>'2000000000022']], [$mine], 5, '2000000000022')`);
  expect(after.found).toBe(true);
  expect(after.food).toMatchObject({ food_id: 21, food_kind: 'custom', name: 'My test granola', basis: 'per_portion', basis_grams: 45, default_grams: 45 });
  expect(after.food.per.kcal).toBe(200);
  const deleted = php(`LLD_Members_Barcode::lookup([], [array_merge($mine, ['deleted_at'=>'2026-11-01 00:00:00'])], 5, '2000000000022')['found']`);
  expect(deleted).toBe(false);
  // The members' API takes the barcode on custom foods, and the screen's form posts it there.
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  expect(api).toMatch(/'custom-foods'[\s\S]{0,600}'barcode'\s*=>\s*'text\[32\]'/);
  expect(script).toContain("call('POST', 'custom-foods', body)");
  expect(script).toContain('barcode: code');
});

test('TC-FR3-37-AS-03 camera refused: typed search still works and a short message explains why scanning is unavailable', () => {
  const words = php('LLD_Members_Barcode::MESSAGES');
  expect(words.camera_refused).toMatch(/camera access wasn't allowed/);
  expect(words.camera_refused).toMatch(/type the barcode number or search/);
  expect(words.camera_refused.length).toBeLessThan(160);
  expect(words.camera_missing).toMatch(/search for the food/);
  // The script tells a refusal from a missing camera, and never hides the typed forms.
  expect(script).toMatch(/NotAllowedError/);
  expect(script).toContain('words.camera_refused');
  const screen = readFileSync(resolve(plugin, 'includes/stage3/screens/class-barcode-scan.php'), 'utf8');
  expect(screen).toContain('data-lld-food-search-form');
  expect(screen).toContain('data-lld-barcode-form');
  // The search form is outside the Premium branch, so a Free member (and a member with no camera) has it too.
  expect(screen.indexOf('data-lld-food-search-form')).toBeGreaterThan(screen.indexOf('lld_membership_lock'));
});

test('TC-FR3-63-AS-04 barcode scanning is Premium: a Free member gets the explanation and an upgrade link, never an error', () => {
  const tiers = readFileSync(resolve(plugin, 'includes/stage3/class-access-tiers.php'), 'utf8');
  expect(tiers).toMatch(/PREMIUM_TYPES = array\(\s*'food-barcode' => 'barcode'/);
  expect(tiers).toContain("'barcode'            => 'Barcode scanning is part of Premium.'");
  const api = readFileSync(resolve(plugin, 'includes/stage3/class-api.php'), 'utf8');
  expect(api).toMatch(/'food-barcode'\s*=>\s*array\(\s*'table'\s*=>\s*'foods',\s*'feature'\s*=>\s*'barcode'/);
  expect(api).toContain('LLD_Members_Barcode::view( $member_id, $query )');
  const loader = readFileSync(resolve(plugin, 'includes/stage3/loader.php'), 'utf8');
  expect(loader).toContain("class-barcode.php");
});

test('TC-FR3-69-AS-02 eating-disorder flag: a scanned product comes back with no calorie or nutrient numbers', () => {
  const r = php(`LLD_Members_Stage3_Safety::barcode_shown(LLD_Members_Barcode::lookup([$bar], [], 5, '2000000000015'), LLD_Members_Stage3_Safety::combine(['eating_disorder'], 'food_tracker'))`);
  expect(r.found).toBe(true);
  expect(r.food.name).toBe('Test oat bar');
  expect(Object.values(r.food.per).every((v) => v === null)).toBe(true);
  expect(r.nutrition_hidden).toBe(true);
  expect(r.note).toBe("This diary keeps a simple record of what you eat. It doesn't count calories.");
  // Not found: "Add this product" then asks for no calories.
  const nf = php(`LLD_Members_Stage3_Safety::barcode_shown(LLD_Members_Barcode::lookup([], [], 5, '2000000000039'), LLD_Members_Stage3_Safety::combine(['eating_disorder'], 'food_tracker'))`);
  expect(nf.nutrition_hidden).toBe(true);
  expect(script).toMatch(/if \(!hidden\) fields = fields\.concat/);
  // Nobody else loses anything.
  const plain = php(`LLD_Members_Stage3_Safety::barcode_shown(LLD_Members_Barcode::lookup([$bar], [], 5, '2000000000015'), LLD_Members_Stage3_Safety::combine([], 'food_tracker'))`);
  expect(plain.food.per.kcal).toBe(400);
  expect(plain.nutrition_hidden).toBeUndefined();
});

test('TC-FR3-69-AS-03 kidney disease: a scanned product shows no protein figure', () => {
  const r = php(`LLD_Members_Stage3_Safety::barcode_shown(LLD_Members_Barcode::lookup([$bar], [], 5, '2000000000015'), LLD_Members_Stage3_Safety::combine(['kidney'], 'food_tracker'))`);
  expect(r.food.per.protein_g).toBe(null);
  expect(r.food.per.kcal).toBe(400);
});
