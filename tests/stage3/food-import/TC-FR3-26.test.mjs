import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test } from 'vitest';
import { food } from './food-php.mjs';

const banana = {
  fdcId: 900001,
  description: 'Bananas, raw',
  dataType: 'Foundation',
  foodPortions: [{ portionDescription: '1 medium', gramWeight: 150 }],
  foodNutrients: [
    { nutrient: { id: 1008, unitName: 'kcal' }, amount: 80 },
    { nutrient: { id: 1003, unitName: 'g' }, amount: 1 },
    { nutrient: { id: 1005, unitName: 'g' }, amount: 20 },
    { nutrient: { id: 1004, unitName: 'g' }, amount: 0 },
    { nutrient: { id: 1079, unitName: 'g' }, amount: 2 },
  ],
};

const bananaBread = {
  fdcId: 900002,
  description: 'Banana bread, sliced',
  dataType: 'SR Legacy',
  foodNutrients: [{ nutrient: { id: 1008, unitName: 'kcal' }, amount: 300 }],
};

const apple = {
  fdcId: 900003,
  description: 'Apple, raw',
  dataType: 'Foundation',
  foodNutrients: [{ nutrient: { id: 1008, unitName: 'kcal' }, amount: 50 }],
};

test('TC-FR3-26-AS-01 search banana returns bananas first, with calories for the portion, within 1 second', () => {
  const records = [apple, bananaBread, banana];
  for (let i = 0; i < 300; i += 1) {
    records.push({
      fdcId: 800000 + i,
      description: `Sample stew ${i}`,
      dataType: 'Foundation',
      foodNutrients: [{ nutrientId: 1008, value: 100, unitName: 'KCAL' }],
    });
  }
  const result = food({
    switch: 'test_accounts',
    source: 'usda',
    records,
    query: 'banana',
  });
  const hits = result.search.foods;

  expect(result.search.enabled).toBe(true);
  expect(result.elapsed_ms).toBeLessThan(1000);
  expect(hits[0].name).toBe('Bananas, raw');
  expect(hits[0].source).toBe('usda');
  expect(hits[0].portion).toEqual({ name: '1 medium', grams: 150 });
  expect(hits[0].kcal).toBe(120);
  expect(hits[0].protein_g).toBe(1.5);
  expect(hits[0].carb_g).toBe(30);
  expect(hits[0].fat_g).toBe(0);
  expect(hits[0].fibre_g).toBe(3);
  expect(hits[1].name).toBe('Banana bread, sliced');
  expect(hits.every((hit) => hit.name.toLowerCase().includes('banana'))).toBe(true);
  expect(hits.some((hit) => hit.name === 'Apple, raw')).toBe(false);
});

test('TC-FR3-26-AS-03 search matches British and American spellings such as yoghurt and yogurt', () => {
  const records = [
    {
      fdcId: 900010,
      description: 'Yogurt, plain',
      dataType: 'SR Legacy',
      foodNutrients: [{ nutrient: { id: 1008, unitName: 'kcal' }, amount: 60 }],
    },
    {
      fdcId: 900011,
      description: 'Yoghurt, plain',
      dataType: 'Foundation',
      foodNutrients: [{ nutrient: { id: 1008, unitName: 'kcal' }, amount: 70 }],
    },
    {
      fdcId: 900012,
      description: 'Frozen yoghurt bar',
      dataType: 'Foundation',
      foodNutrients: [{ nutrient: { id: 1008, unitName: 'kcal' }, amount: 90 }],
    },
  ];
  const byYoghurt = food({ switch: 'test_accounts', source: 'usda', records, query: 'yoghurt' });
  const byYogurt = food({ switch: 'test_accounts', source: 'usda', records, query: 'yogurt' });
  const stored = byYogurt.rows.find((row) => row.name === 'Yogurt, plain');

  expect(stored.search_names.some((name) => name.includes('yoghurt'))).toBe(true);
  expect(byYoghurt.search.foods.map((hit) => hit.name)).toEqual(expect.arrayContaining(['Yogurt, plain', 'Yoghurt, plain', 'Frozen yoghurt bar']));
  expect(byYogurt.search.foods.map((hit) => hit.name)).toEqual(expect.arrayContaining(['Yogurt, plain', 'Yoghurt, plain']));
  expect(byYogurt.search.foods[0].kcal).toBe(60);
  expect(byYogurt.search.foods[0].source_name).toBe('USDA FoodData Central');

  const dir = mkdtempSync(join(tmpdir(), 'lld-food-'));
  const path = join(dir, 'foods.jsonl');
  try {
    writeFileSync(path, `${JSON.stringify(records[0])}\n${JSON.stringify(records[1])}\n`, 'utf8');
    const fromFile = food({ switch: 'test_accounts', source: 'usda', file: path, query: 'yoghurt' });
    expect(fromFile.import.written).toBe(2);
    expect(fromFile.search.foods.map((hit) => hit.name)).toEqual(expect.arrayContaining(['Yogurt, plain', 'Yoghurt, plain']));
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
