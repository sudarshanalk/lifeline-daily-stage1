// TC-FR2-32-AS-03: the shopping-list PDF is a well-formed file. Built by LLD_Members_Premium::shopping_pdf
// (no library) and checked here by parsing its structure: header, objects at their cross-reference offsets,
// trailer, page tree, stream lengths, one text line per item, page breaks and WinAnsi encoding.
import { describe, expect, test } from 'vitest';
import { premiumRaw } from './tools/premium-php.mjs';

const latin = (buf) => buf.toString('latin1');

/** Decode a PDF literal string body (between the brackets) into bytes. */
function literal(body) {
  const out = [];
  for (let i = 0; i < body.length; i += 1) {
    const c = body[i];
    if (c !== '\\') { out.push(c.charCodeAt(0)); continue; }
    const n = body[i + 1];
    if (/[0-7]/.test(n)) {
      const oct = body.slice(i + 1, i + 4).match(/^[0-7]{1,3}/)[0];
      out.push(parseInt(oct, 8));
      i += oct.length;
    } else {
      out.push({ n: 10, r: 13, t: 9, b: 8, f: 12 }[n] ?? n.charCodeAt(0));
      i += 1;
    }
  }
  return Buffer.from(out);
}

// Windows-1252 decode for the bytes a WinAnsi Helvetica shows.
const CP1252 = { 0x80: '€', 0x91: '‘', 0x92: '’', 0x93: '“', 0x94: '”', 0x95: '•', 0x96: '–', 0x97: '—' };
const winAnsi = (bytes) => [...bytes].map((b) => CP1252[b] ?? String.fromCharCode(b)).join('');

function parse(buf) {
  const text = latin(buf);
  expect(text.startsWith('%PDF-1.4\n')).toBe(true);
  expect(text.trimEnd().endsWith('%%EOF')).toBe(true);
  const startxref = Number(text.match(/startxref\n(\d+)\n%%EOF\s*$/)[1]);
  expect(text.slice(startxref, startxref + 5)).toBe('xref\n');
  const head = text.slice(startxref).match(/^xref\n0 (\d+)\n/);
  const size = Number(head[1]);
  const table = text.slice(startxref + head[0].length, startxref + head[0].length + size * 20);
  const entries = table.match(/.{20}/gs);
  expect(entries).toHaveLength(size);
  expect(entries[0]).toBe('0000000000 65535 f \n');
  const objects = {};
  for (let id = 1; id < size; id += 1) {
    expect(entries[id]).toMatch(/^\d{10} 00000 n \n$/);
    const offset = Number(entries[id].slice(0, 10));
    expect(text.slice(offset, offset + `${id} 0 obj\n`.length), `object ${id} at its offset`).toBe(`${id} 0 obj\n`);
    const end = text.indexOf('\nendobj\n', offset);
    objects[id] = text.slice(offset + `${id} 0 obj\n`.length, end);
  }
  const trailer = text.slice(startxref + head[0].length + size * 20);
  expect(trailer).toMatch(new RegExp(`^trailer << /Size ${size} /Root 1 0 R >>`));
  expect(objects[1]).toBe('<< /Type /Catalog /Pages 2 0 R >>');
  const pagesObj = objects[2];
  const kids = [...pagesObj.match(/\/Kids \[([^\]]*)\]/)[1].matchAll(/(\d+) 0 R/g)].map((m) => Number(m[1]));
  expect(Number(pagesObj.match(/\/Count (\d+)/)[1])).toBe(kids.length);
  const pages = kids.map((id) => {
    expect(objects[id]).toMatch(/^<< \/Type \/Page \/Parent 2 0 R /);
    const fontRef = Number(objects[id].match(/\/F1 (\d+) 0 R/)[1]);
    expect(objects[fontRef]).toContain('/BaseFont /Helvetica');
    expect(objects[fontRef]).toContain('/Encoding /WinAnsiEncoding');
    const content = objects[Number(objects[id].match(/\/Contents (\d+) 0 R/)[1])];
    const length = Number(content.match(/^<< \/Length (\d+) >>\nstream\n/)[1]);
    const data = content.slice(content.indexOf('stream\n') + 7, content.lastIndexOf('\nendstream'));
    expect(data.length, 'stream /Length').toBe(length);
    const lines = [...data.matchAll(/BT \/F1 (\d+) Tf (\d+) (\d+) Td \(((?:\\.|[^\\)])*)\) Tj ET\n/g)];
    expect(lines.map((m) => m[0]).join('')).toBe(data);
    return lines.map((m) => ({ size: Number(m[1]), y: Number(m[3]), text: winAnsi(literal(m[4])) }));
  });
  return { pages, size };
}

describe('TC-FR2-32-AS-03 shopping-list PDF', () => {
  test('TC-FR2-32-AS-03 the PDF parses: offsets, pages, one line per item, WinAnsi text', () => {
    const items = [];
    for (let i = 1; i <= 95; i += 1) items.push(`item ${i}, ${i * 10} g`);
    items.push('Crème fraîche, 200 ml');
    items.push('Jalapeño – “mild” (fresh) \\ chopped, €2');
    items.push(`${'Very long ingredient name that keeps going '.repeat(4)}end`);
    items.push('Unicode outside WinAnsi: 丁');
    const { pages } = parse(premiumRaw({}, 'shopping_pdf', 'Shopping list', items));

    expect(pages.length, 'page breaks').toBeGreaterThanOrEqual(3);
    expect(pages[0][0]).toMatchObject({ size: 16, text: 'Shopping list' });
    for (const page of pages) {
      for (const line of page) expect(line.y).toBeGreaterThanOrEqual(56);
      const ys = page.map((l) => l.y);
      expect(new Set(ys).size, 'no two lines at the same height').toBe(ys.length);
    }
    const lines = pages.flat().slice(1).map((l) => l.text);
    // every item starts its own line, in order
    const starts = lines.filter((l) => l.startsWith('• ')).map((l) => l.slice(2));
    expect(starts).toHaveLength(items.length);
    for (let i = 0; i < 95; i += 1) expect(starts[i]).toBe(items[i]);
    expect(starts[95]).toBe('Crème fraîche, 200 ml');
    expect(starts[96]).toBe('Jalapeño – “mild” (fresh) \\ chopped, €2');
    expect(starts[98]).toBe('Unicode outside WinAnsi: ?');
    // the long item wraps onto indented lines that rebuild it
    const at = lines.findIndex((l) => l.startsWith('• Very long'));
    const wrapped = [lines[at].slice(2)];
    for (let j = at + 1; lines[j] && lines[j].startsWith('  '); j += 1) wrapped.push(lines[j].trim());
    expect(wrapped.length).toBeGreaterThan(1);
    expect(wrapped.join(' ')).toBe(items[97]);
    for (const line of lines) expect(line.length).toBeLessThanOrEqual(110);
  });

  test('TC-FR2-32-AS-03 an empty list still gives a valid one-page PDF', () => {
    const { pages } = parse(premiumRaw({}, 'shopping_pdf', 'Shopping list', []));
    expect(pages).toHaveLength(1);
    expect(pages[0].map((l) => l.text)).toEqual(['Shopping list', '• Nothing on the list this week.']);
  });
});
