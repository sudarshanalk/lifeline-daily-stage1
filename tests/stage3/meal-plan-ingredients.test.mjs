// Meal plans and the shopping list read a recipe's ingredients as the content library saves them: a list of rows
// (name, amount, unit), as class-content.php stores the owner's lines. Found on staging on 5 Nov 2026 in the owner's
// P3-90 test checkout: every real recipe printed "Array to string conversion" warnings and "Array" for its ingredients,
// because the Stage 2 meal-plan code read only the text form that its test fixtures use. Both forms are read here.
// A new file, so no Stage 2 test changes (NFR3-02); it runs the real Premium class through the Stage 2 PHP stubs.
import { expect, test } from 'vitest';
import { premium } from '../stage2/tools/premium-php.mjs';

const world = (ingredients) => ({
  consent: true,
  profile: { conditions_answered: true, conditions: [] },
  items: { 7: { type: 'lld_recipe', title: 'Groundnuts and banana', fields: { ingredients, servings: '2', diet: 'vegetarian' } } },
});

test('TC-FR2-32-AS-03 recipe ingredients saved as rows reach the meal plan and shopping list', () => {
  const recipe = premium(world([
    { name: 'bananas, firm and just ripe', amount: '2', unit: 'piece' },
    { name: 'roasted unsalted peanuts', amount: '40', unit: 'g' },
    { name: 'black pepper', amount: '', unit: '' },
    { name: '', amount: '1', unit: 'g' },
  ]), 'safe_recipe', 1, 7);
  expect(recipe.ingredients).toEqual(['bananas, firm and just ripe, 2 piece', 'roasted unsalted peanuts, 40 g', 'black pepper']);
  expect(recipe.aisles).toEqual({
    'bananas, firm and just ripe, 2 piece': 'Vegetables and fruit',
    'roasted unsalted peanuts, 40 g': 'Tins, jars and dry goods',
    'black pepper': 'Oils, herbs and spices',
  });
});

test('TC-FR2-32-AS-03 recipe ingredients written as text lines still read the same', () => {
  const recipe = premium(world('red lentils | 150 | g\nspinach | 80 | g | Dairy and alternatives\n\n'), 'safe_recipe', 1, 7);
  expect(recipe.ingredients).toEqual(['red lentils, 150 g', 'spinach, 80 g']);
  expect(recipe.aisles).toEqual({ 'red lentils, 150 g': 'Beans, pulses, fish, eggs and meat', 'spinach, 80 g': 'Dairy and alternatives' });
});
