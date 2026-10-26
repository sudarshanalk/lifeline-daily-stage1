import { readFileSync } from 'node:fs';
import { expect, test } from 'vitest';
import { MAP, columnDifferences, mapProblems, schemaColumns, sectionText } from './data-model.mjs';

const schema = readFileSync(new URL('../../site/public_html/wp-content/plugins/lld-members/includes/class-schema.php', import.meta.url), 'utf8');

test('every mapped column and file store is named in its Data Model section', () => {
  expect(mapProblems()).toEqual([]);
});

test('the map has the same tables and columns as the schema creates', () => {
  const created = schemaColumns(schema);
  expect(Object.keys(created).length).toBeGreaterThan(40);
  expect(columnDifferences(created)).toEqual([]);
  const suffixes = [...schema.slice(schema.indexOf('function all_suffixes')).matchAll(/^\s*'(\w+)',$/gm)].map((m) => m[1]);
  expect(suffixes.slice(0, Object.keys(MAP.tables).length).sort()).toEqual(Object.keys(MAP.tables).sort());
});

test('the checks can fail: an unknown phrase, an unmapped column and a missing reason are reported', () => {
  const broken = JSON.parse(JSON.stringify(MAP));
  broken.tables.member.columns.age = 'shoe size';
  broken.tables.security_code.columns.attempts = '#nonpersonal';
  expect(mapProblems(broken)).toEqual([
    'member.age: "shoe size" is not in Data Model A1',
    'security_code.attempts: marked non-personal without a reason',
  ]);
  expect(columnDifferences({ member: [...Object.keys(MAP.tables.member.columns), 'shoe_size'] }, { tables: { member: MAP.tables.member } })).toEqual([
    'member.shoe_size is not in the Data Model map',
  ]);
  expect(sectionText('A1')).toContain('Member account');
  expect(sectionText('F1')).toContain('Erasure list');
  expect(sectionText('S2')).toContain('Photos are never public');
});
