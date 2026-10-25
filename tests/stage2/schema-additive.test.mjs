import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const schema = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '../../site/public_html/wp-content/plugins/lld-members/includes/class-schema.php'),
  'utf8'
);

test('database update only adds tables', () => {
  expect(schema).toMatch(/CREATE TABLE IF NOT EXISTS/);
  expect(schema).not.toMatch(/DROP\s+(TABLE|COLUMN)/i);
  expect(schema).not.toMatch(/\b(MODIFY|CHANGE)\s+/i);
});
