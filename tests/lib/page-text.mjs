// Normalised visible text of an HTML fragment, used for copy hashes of WordPress page content (TC-FR1-17-AS-04).
// Tags become spaces, entities are decoded, WordPress's typographic substitutions (wptexturize) are folded back to plain
// characters, and whitespace is collapsed — so the plugin's source content and the rendered page give the same text.
import { createHash } from 'node:crypto';
const ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', ndash: '–', mdash: '—', hellip: '…', rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“' };
export function pageText(html) {
  return String(html)
    .replace(/<(script|style)\b[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
    .replace(/&([a-z]+);/gi, (m, n) => ENT[n.toLowerCase()] ?? m)
    .replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/ /g, ' ')
    .replace(/\s+/g, ' ').trim();
}
export const pageTextHash = (html) => createHash('sha256').update(pageText(html)).digest('hex');
