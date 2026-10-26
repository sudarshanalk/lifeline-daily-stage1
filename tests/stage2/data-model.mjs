// The Data Model (docs/stage2/02_Data_Model_Stage2.md) as the reference for TC-NFR2-01-AS-01:
// every table, column and file store the plugin keeps must be tied to a field the Data Model names.
// data-model-map.json gives, for each column, the Data Model section and a phrase from it; this
// module reads the document itself and reports any column or store whose phrase it does not hold.
import { readFileSync } from 'node:fs';

export const MAP = JSON.parse(readFileSync(new URL('./data-model-map.json', import.meta.url), 'utf8'));
export const DOC = readFileSync(new URL('../../docs/stage2/02_Data_Model_Stage2.md', import.meta.url), 'utf8');

/** Text of one Data Model section: a "### A1." style heading block, or a table row such as "| **F1." or "| S2 |". */
export function sectionText(key, doc = DOC) {
  const lines = doc.split(/\r?\n/);
  const level = (line) => (line.match(/^(#+) /) || ['', ''])[1].length;
  const start = lines.findIndex((line) => level(line) > 0 && line.replace(/^#+ /, '').startsWith(`${key}. `));
  if (start >= 0) {
    const depth = level(lines[start]);
    let end = lines.findIndex((line, i) => i > start && level(line) > 0 && level(line) <= depth);
    if (end < 0) end = lines.length;
    return lines.slice(start, end).join('\n');
  }
  return lines.find((line) => line.startsWith(`| **${key}. `) || line.startsWith(`| ${key} |`)) || '';
}

/** Where a column is described: { section, phrase } or { nonPersonal: reason }. */
export function columnSource(table, column, map = MAP) {
  const entry = map.tables[table];
  const value = entry && entry.columns[column];
  if (value === undefined) return null;
  if (value === '#id') return map.idPhrase;
  if (value === '#link') return map.linkPhrase;
  if (value === '#nonpersonal') return { nonPersonal: map.nonPersonal[`${table}.${column}`] || '' };
  if (typeof value === 'string') return { section: entry.section, phrase: value };
  return value;
}

/** Problems with the map against the document: missing sections, phrases or reasons. Empty when all hold. */
export function mapProblems(map = MAP, doc = DOC) {
  const problems = [];
  const holds = (where, label) => {
    const text = sectionText(where.section, doc);
    if (!text) problems.push(`${label}: Data Model section ${where.section} not found`);
    else if (!text.toLowerCase().includes(String(where.phrase).toLowerCase())) problems.push(`${label}: "${where.phrase}" is not in Data Model ${where.section}`);
  };
  for (const [table, entry] of Object.entries(map.tables)) {
    for (const column of Object.keys(entry.columns)) {
      const source = columnSource(table, column, map);
      if (source.nonPersonal !== undefined) {
        if (!source.nonPersonal) problems.push(`${table}.${column}: marked non-personal without a reason`);
        continue;
      }
      holds(source, `${table}.${column}`);
    }
  }
  for (const [store, where] of Object.entries(map.stores)) holds(where, `file store ${store}`);
  return problems;
}

/** Table columns as created by class-schema.php, read from its source. */
export function schemaColumns(php) {
  const tables = {};
  for (const match of php.matchAll(/self::create\(\s*'(\w+)',\s*'([\s\S]*?)'\s*\);/g)) {
    tables[match[1]] = match[2]
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line) => line && !/^(PRIMARY|UNIQUE|KEY)\b/.test(line))
      .map((line) => line.split(/\s+/)[0].replace(/`/g, ''));
  }
  return tables;
}

/** Differences between live (or created) columns and the map, table by table. Empty when they agree. */
export function columnDifferences(columns, map = MAP) {
  const problems = [];
  const names = new Set([...Object.keys(columns), ...Object.keys(map.tables)]);
  for (const table of [...names].sort()) {
    if (!map.tables[table]) {
      problems.push(`table ${table} is not in the Data Model map`);
      continue;
    }
    if (!columns[table]) {
      problems.push(`table ${table} is in the map but not in the database`);
      continue;
    }
    const mapped = Object.keys(map.tables[table].columns);
    for (const column of columns[table]) if (!mapped.includes(column)) problems.push(`${table}.${column} is not in the Data Model map`);
    for (const column of mapped) if (!columns[table].includes(column)) problems.push(`${table}.${column} is in the map but not in the database`);
  }
  return problems;
}
