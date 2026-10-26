// SCR-15 (TC-FR2-32-AS-03): the shopping list is grouped by type of food. A recipe can name the group in a fourth
// column; otherwise the ingredient name decides, longest word first.
import { expect, test } from 'vitest';
import { premium } from './tools/premium-php.mjs';

const aisle = (name, given = '') => premium({}, 'aisle_of', name, given);

test('TC-FR2-32-AS-03 ingredients fall into their food groups', () => {
  expect(aisle('red lentils')).toBe('Beans, pulses, fish, eggs and meat');
  expect(aisle('butter beans')).toBe('Beans, pulses, fish, eggs and meat');
  expect(aisle('Free-range eggs')).toBe('Beans, pulses, fish, eggs and meat');
  expect(aisle('spinach')).toBe('Vegetables and fruit');
  expect(aisle('frozen peas')).toBe('Vegetables and fruit');
  expect(aisle('red pepper')).toBe('Vegetables and fruit');
  expect(aisle('oats')).toBe('Bread, rice, pasta and grains');
  expect(aisle('brown rice')).toBe('Bread, rice, pasta and grains');
  expect(aisle('Greek yoghurt')).toBe('Dairy and alternatives');
  expect(aisle('clear broth')).toBe('Tins, jars and dry goods');
  expect(aisle('peanut butter')).toBe('Tins, jars and dry goods');
  expect(aisle('black pepper')).toBe('Oils, herbs and spices');
  expect(aisle('olive oil')).toBe('Oils, herbs and spices');
  expect(aisle('baking powder')).toBe('Other');
  // the recipe's own group wins when it names one of the groups; anything else is ignored
  expect(aisle('spinach', 'dairy and alternatives')).toBe('Dairy and alternatives');
  expect(aisle('spinach', 'Freezer')).toBe('Vegetables and fruit');
});

test('TC-FR2-32-AS-03 groups come in shop order, with Other last and empty groups left out', () => {
  const grouped = premium({}, 'group_aisles', { 'oats, 40 g': 'Bread, rice, pasta and grains', 'baking powder': 'Other', 'spinach, 80 g': 'Vegetables and fruit', 'red lentils, 150 g': 'Beans, pulses, fish, eggs and meat' });
  expect(grouped).toEqual({
    'Beans, pulses, fish, eggs and meat': ['red lentils, 150 g'],
    'Vegetables and fruit': ['spinach, 80 g'],
    'Bread, rice, pasta and grains': ['oats, 40 g'],
    Other: ['baking powder'],
  });
});
