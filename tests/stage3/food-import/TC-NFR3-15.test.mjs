import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { food } from './food-php.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../..');
const importer = resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes/stage3/food-import');
const loader = resolve(root, 'site/public_html/wp-content/plugins/lld-members/lld-members.php');

const basic = {
  fdcId: 900001,
  description: 'Sample root vegetable, raw',
  dataType: 'Foundation',
  foodPortions: [{ portionDescription: '1 piece', gramWeight: 80 }],
  foodNutrients: [
    { nutrient: { id: 1008, unitName: 'kcal' }, amount: 40 },
    { nutrient: { id: 1003, unitName: 'g' }, amount: 1 },
    { nutrient: { id: 1093, unitName: 'mg' }, amount: 400 },
  ],
};

const fromKj = {
  fdcId: 900002,
  description: 'Sample leaf, raw',
  dataType: 'SR Legacy',
  foodNutrients: [{ nutrientId: 1008, value: 418.4, unitName: 'kJ' }],
};

const branded = {
  fdcId: 900099,
  description: 'Sample cola',
  dataType: 'Branded',
  gtinUpc: '1111111111111',
  brandOwner: 'Example Brand',
  foodNutrients: [{ nutrient: { id: 1008, unitName: 'kcal' }, amount: 40 }],
};

const tooRich = {
  fdcId: 900098,
  description: 'Sample impossible energy',
  dataType: 'Foundation',
  foodNutrients: [{ nutrientId: 1008, value: 950, unitName: 'kcal' }],
};

const noEnergy = {
  fdcId: 900097,
  description: 'Sample unnamed nutrient',
  dataType: 'Foundation',
  foodNutrients: [{ nutrient: { id: 1003, unitName: 'g' }, amount: 1 }],
};

const oatBar = {
  code: '00012345678905',
  product_name: 'Example oat bar',
  brands: 'Example Brand, Other',
  nutriments: {
    'energy-kcal_100g': 400,
    proteins_100g: 10,
    carbohydrates_100g: 50,
    fat_100g: 15,
    fiber_100g: 6,
    salt_100g: 0.4,
  },
  serving_size: '40 g',
  serving_quantity: 40,
};

function valueKeys(row, fields) {
  return fields.filter((field) => Object.prototype.hasOwnProperty.call(row, field));
}

test('TC-NFR3-15-AS-01 every imported food and every search hit shows its data source', () => {
  const usda = food({
    switch: 'test_accounts',
    source: 'usda',
    records: [basic, fromKj],
    query: 'sample',
  });
  const off = food({
    switch: 'test_accounts',
    source: 'off',
    records: [oatBar],
    query: 'oat',
  });

  expect(usda.rows.length).toBeGreaterThan(0);
  expect(off.rows.length).toBe(1);
  for (const row of usda.rows) {
    expect(row.source).toBe('usda');
    expect(row.source_ref).not.toBe('');
  }
  expect(off.rows[0].source).toBe('off');
  expect(off.rows[0].source_ref).toBe('00012345678905');

  expect(usda.search.foods.length).toBeGreaterThan(0);
  expect(off.search.foods.length).toBe(1);
  for (const hit of usda.search.foods) {
    expect(hit.source).toBe('usda');
    expect(hit.source_name).toBe('USDA FoodData Central');
  }
  expect(off.search.foods[0].source).toBe('off');
  expect(off.search.foods[0].source_name).toBe('Open Food Facts');
});

test('TC-NFR3-15-AS-02 source licence terms are recorded and Open Food Facts attribution is kept', () => {
  const imported = food({
    switch: 'everyone',
    source: 'off',
    records: [oatBar],
    query: 'oat',
  });
  const licences = imported.licences;

  expect(licences.usda.licence).toMatch(/public domain/);
  expect(licences.usda.attribution_required).toBe(false);
  expect(licences.usda.url).toBe('https://fdc.nal.usda.gov/');
  expect(licences.off.licence).toMatch(/ODbL/);
  expect(licences.off.attribution_required).toBe(true);
  expect(licences.off.url).toBe('https://world.openfoodfacts.org/terms-of-use');
  expect(licences.off.attribution.length).toBeGreaterThan(0);

  expect(imported.rows[0].attribution).toBe(licences.off.attribution);
  expect(imported.search.foods[0].attribution).toBe(licences.off.attribution);

  const usda = food({ switch: 'test_accounts', source: 'usda', records: [basic] });
  expect(usda.rows[0].source).toBe('usda');
  expect(usda.licences.usda.attribution_required).toBe(false);
});

test('TC-NFR3-15-AS-03 switching food source changes food values only', () => {
  const usda = food({
    switch: 'test_accounts',
    source: 'usda',
    records: [basic, fromKj, branded, tooRich, noEnergy],
    replace: [{
      ...basic,
      foodNutrients: [
        { nutrient: { id: 1008, unitName: 'kcal' }, amount: 55 },
        { nutrient: { id: 1093, unitName: 'mg' }, amount: 400 },
      ],
    }],
    query: 'vegetable',
  });
  const off = food({
    switch: 'test_accounts',
    source: 'off',
    records: [oatBar, { code: '555', product_name: 'Example water', nutriments: { 'energy-kcal_100g': 0 }, serving_size: '250 ml', serving_quantity: 250, serving_quantity_unit: 'ml' }],
    query: 'oat',
  });
  const licensed = food({
    switch: 'test_accounts',
    source: 'licensed',
    register_licensed: true,
    records: [{ id: 'LIC-1', name: 'Licensed sample loaf', kcal_100g: 250, portions: [{ name: '1 slice', grams: 30 }] }],
    query: 'loaf',
  });
  const junkSwitch = food({ switch: 'yes', source: 'usda', records: [basic] });
  const offSwitch = food({
    source: 'usda',
    records: [basic, oatBar],
    query: 'sample',
  });
  const schema = food({ call: 'schema' });
  const wrapped = food({
    call: 'decode',
    document: JSON.stringify({ FoundationFoods: [{ fdcId: 42, description: 'Wrapped sample' }] }),
  });
  const product = food({
    call: 'decode',
    document: JSON.stringify({ product: { code: '999', product_name: 'Wrapped bar' } }),
  });

  expect(usda.import.written).toBe(2);
  expect(usda.import.skipped).toBe(3);
  expect(usda.import.replaced.updated).toBe(1);
  expect(usda.rows).toHaveLength(2);
  const vegetable = usda.rows.find((row) => row.source_ref === '900001');
  const leaf = usda.rows.find((row) => row.source_ref === '900002');
  expect(vegetable.kcal_100g).toBe(55);
  expect(vegetable.salt_100g).toBe(1);
  expect(leaf.kcal_100g).toBe(100);
  expect(valueKeys(vegetable, usda.fields)).toEqual(usda.fields);
  expect(valueKeys(off.rows[0], usda.fields)).toEqual(usda.fields);
  expect(valueKeys(licensed.rows[0], usda.fields)).toEqual(usda.fields);
  expect(Object.keys(usda.search.foods[0])).toEqual(usda.result_fields);
  expect(Object.keys(off.search.foods[0])).toEqual(usda.result_fields);
  expect(Object.keys(licensed.search.foods[0])).toEqual(usda.result_fields);
  expect(vegetable.kcal_100g).not.toBe(off.rows[0].kcal_100g);
  expect(off.rows[0].brand).toBe('Example Brand');
  expect(off.rows[0].barcode).toBe('00012345678905');
  expect(off.rows[0].portions[0]).toEqual({ name: '40 g', grams: 40 });
  expect(off.rows[0].portions.some((portion) => portion.name === '100 g' && portion.grams === 100)).toBe(true);
  expect(off.search.foods[0].kcal).toBe(160);
  expect(off.import.written).toBe(2);
  expect(off.rows[1].portions).toEqual([{ name: '100 g', grams: 100 }]);

  expect(junkSwitch.switch).toBe('off');
  expect(junkSwitch.import.written).toBe(0);
  expect(offSwitch.switch).toBe('off');
  expect(offSwitch.import.reason).toBe('off');
  expect(offSwitch.import.written).toBe(0);
  expect(offSwitch.rows).toEqual([]);
  expect(offSwitch.search).toEqual({ enabled: false, foods: [] });
  expect(offSwitch.side).toEqual(offSwitch.side_before);
  expect(usda.side).toEqual(usda.side_before);
  expect(off.side).toEqual(off.side_before);
  expect(licensed.side).toEqual(licensed.side_before);

  expect(schema.table).toBe('wp_lld_foods');
  expect(schema.sql).toMatch(/^CREATE TABLE IF NOT EXISTS wp_lld_foods \(/);
  expect(schema.sql).not.toMatch(/DROP|RENAME|MODIFY|CHANGE/i);
  for (const column of ['source', 'source_ref', 'name', 'brand', 'barcode', 'portions', 'kcal_100g', 'protein_100g', 'carb_100g', 'sugar_100g', 'fat_100g', 'satfat_100g', 'fibre_100g', 'salt_100g', 'alcohol_100g', 'search_names', 'attribution', 'updated_at']) {
    expect(schema.columns).toContain(column);
  }
  expect(schema.sql).not.toMatch(/lld_(?!foods)/);

  expect(wrapped).toEqual([{ fdcId: 42, description: 'Wrapped sample' }]);
  expect(product).toEqual([{ code: '999', product_name: 'Wrapped bar' }]);

  const plugin = readFileSync(loader, 'utf8');
  expect(plugin).not.toContain('food-import');
  expect(plugin).not.toContain('stage3');
  for (const name of readdirSync(importer)) {
    if (!name.endsWith('.php')) continue;
    const text = readFileSync(resolve(importer, name), 'utf8');
    expect(text).not.toMatch(/DROP\s+(TABLE|COLUMN)/i);
    expect(text).not.toMatch(/RENAME\s+TABLE/i);
    expect(text).not.toMatch(/\bMODIFY\b/);
    expect(text).not.toMatch(/CHANGE\s+COLUMN/i);
    expect(text).not.toMatch(/diary_meal|diary_day|lld_diary/);
  }
});
