// TC-UI1-11 Copy deck freeze — ci + static families (DEC-25, HG-02). Shared phrase/term fixtures only.
// AS-01 copy/inventory.json lists every source; copy/*.json regenerate identically; every entry hash equals the approval records.
// AS-02 changing one MESSAGES string in a sandbox copy of the repo makes the copy-hash job fail.
// AS-03 required phrases present across copy files and templates; forbidden global terms absent everywhere; underweight terms
//       absent from the underweight banner and the result copy an underweight visitor can see.
import { describe, expect, test } from 'vitest';
import { readFileSync, mkdtempSync, cpSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { ROOT } from '../lib/paths.mjs';
import { sha256 } from '../lib/hash.mjs';
import { extractDeck, deckFiles, PAGES } from '../ci/copy-extract.mjs';
import { closeAll } from '../lib/tool-page.mjs';
import copyApproval from '../../approvals/copy.json' with { type: 'json' };
import phrases from '../../fixtures/required-phrases.json' with { type: 'json' };
import forbidden from '../../fixtures/forbidden-terms.json' with { type: 'json' };

const read = (rel) => JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
const node = (script, opts = {}) => spawnSync(process.execPath, [script, ...(opts.args || [])], { cwd: ROOT, encoding: 'utf8', env: { ...process.env, ...(opts.env || {}) } });

describe('TC-UI1-11 copy deck freeze', () => {
  test('TC-UI1-11-AS-01', async () => {
    const r = node(resolve(ROOT, 'tests', 'ci', 'copy-extract.mjs'), { args: ['--check'] });
    expect(r.stdout + r.stderr, 'copy/*.json regenerate identically').toMatch(/copy deck is current/);
    expect(r.status).toBe(0);
    const deck = await extractDeck({ pages: {} });
    const inventory = read('copy/inventory.json');
    expect(Object.keys(inventory.sources).sort(), 'every source class enumerated').toEqual(['inline', 'messages', 'pages', 'templates']);
    expect(inventory.sources.messages).toHaveLength(3);   // two Stage 1 tools and the Healthy Weight Check (FR2-39)
    expect(inventory.sources.templates).toHaveLength(2);
    expect(inventory.sources.pages, 'landing pages and the disclaimer page are listed').toHaveLength(Object.keys(PAGES).length);
    const keys = new Set(inventory.entries.map((e) => e.key));
    expect(keys.size, 'keys unique').toBe(inventory.entries.length);
    // the written copy files are the deck, entry by entry, and every entry hash equals the approval record
    const files = deckFiles(deck);
    for (const slug of ['protein', 'diabetes']) {
      const written = read(`copy/${slug}-messages.json`);
      expect(written).toEqual(files[`copy/${slug}-messages.json`]);
      for (const [k, text] of Object.entries(written)) {
        expect(keys.has(`messages.${slug}.${k}`), `inventory has messages.${slug}.${k}`).toBe(true);
        expect(copyApproval.messages[`${slug}.${k}`], `approved hash for messages.${slug}.${k}`).toBe(sha256(text));
      }
      const approval = read(`approvals/${slug}.json`);
      expect(approval.copy_hashes[`messages.${slug}`]).toBe(deck.copy.aggregate[slug][`messages.${slug}`]);
      expect(approval.copy_hashes[`templates.${slug}`]).toBe(deck.copy.aggregate[slug][`templates.${slug}`]);
    }
    const templates = read('copy/templates.json');
    expect(templates).toEqual(files['copy/templates.json']);
    for (const [k, text] of Object.entries(templates)) {
      expect(keys.has(`templates.${k}`), `inventory has templates.${k}`).toBe(true);
      expect(copyApproval.templates[k], `approved hash for templates.${k}`).toBe(sha256(text));
    }
    const inline = read('copy/inline.json');
    expect(inline).toEqual(files['copy/inline.json']);
    for (const [k, text] of Object.entries(inline)) { expect(keys.has(`inline.${k}`)).toBe(true); expect(copyApproval.inline[k], `approved hash for inline.${k}`).toBe(sha256(text)); }
    for (const e of inventory.entries) expect(e.source && e.locator, `${e.key} has source and locator`).toBeTruthy();
    expect(copyApproval.decision).toMatch(/^(pending|approved)$/);
    closeAll();
  });

  test('TC-UI1-11-AS-02', () => {
    // sandbox: a copy of the copy sources and approval records with one MESSAGES string changed → copy-hash exits 1 naming the key
    const sandbox = mkdtempSync(join(tmpdir(), 'lld-copy-'));
    try {
      for (const d of ['site/public_html/tools', 'approvals', 'copy']) cpSync(resolve(ROOT, d), join(sandbox, d), { recursive: true });
      const p = join(sandbox, 'site', 'public_html', 'tools', 'protein-requirement-tool.html');
      const html = readFileSync(p, 'utf8');
      const anchor = 'title: "We can\'t give you a protein target",';
      expect(html, 'anchor present in MESSAGES.renalStop.title').toContain(anchor);
      writeFileSync(p, html.replace(anchor, 'title: "We can\'t give you a protein target today",'));
      const r = node(resolve(ROOT, 'tests', 'ci', 'copy-hash.mjs'), { env: { LLD_ROOT: sandbox } });
      expect(r.status, r.stdout + r.stderr).toBe(1);
      expect(r.stderr).toMatch(/messages\.protein\.renalStop\.title: text changed/);
      expect(r.stdout).toMatch(/copy-hash: FAIL/);
    } finally { rmSync(sandbox, { recursive: true, force: true }); }
    // and the unchanged tree passes
    const ok = node(resolve(ROOT, 'tests', 'ci', 'copy-hash.mjs'));
    expect(ok.status, ok.stdout + ok.stderr).toBe(0);
  });

  test('TC-UI1-11-AS-03', async () => {
    const deck = await extractDeck({ pages: {} });
    const M = deck.copy.texts.messages, T = deck.copy.texts.templates;
    const all = {
      ...Object.fromEntries(Object.entries(M.protein).map(([k, v]) => [`messages.protein.${k}`, v])),
      ...Object.fromEntries(Object.entries(M.diabetes).map(([k, v]) => [`messages.diabetes.${k}`, v])),
      ...Object.fromEntries(Object.entries(T.protein).map(([k, v]) => [`templates.protein.${k}`, v])),
      ...Object.fromEntries(Object.entries(T.diabetes).map(([k, v]) => [`templates.diabetes.${k}`, v])),
      ...Object.fromEntries(Object.entries(deck.inline).map(([k, v]) => [`inline.${k}`, v])),
    };
    const plain = (s) => String(s).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').toLowerCase();
    const joined = Object.values(all).map(plain).join('\n');
    // required phrases present somewhere across the copy files and templates
    for (const [k, p] of Object.entries(phrases.methodology)) expect(joined, `methodology.${k} "${p}"`).toContain(p.toLowerCase());
    expect(joined, 'notDiagnosis').toContain(phrases.notDiagnosis.toLowerCase());
    expect(joined, 'urgentCare').toContain(phrases.urgentCare.toLowerCase());
    expect(joined, 'protein.scope').toContain(phrases.protein.scope.toLowerCase());
    // forbidden global terms absent everywhere
    for (const [k, v] of Object.entries(all)) for (const term of forbidden.global) expect(plain(v), `${k} contains "${term}"`).not.toContain(term.toLowerCase());
    // underweight terms absent from the underweight banner and from the result copy an underweight visitor sees
    // (interpretation, advice, eating-concern line, cross-link, disclaimer; the underweight e-mail template)
    const uw = Object.keys(all).filter((k) => /^messages\.protein\.(underweight|interp|advice|eatingConcern|crossLink|disclaimer)/.test(k) || /^templates\.protein\..*underweight/.test(k));
    expect(uw.length).toBeGreaterThan(5);
    for (const k of uw) for (const term of forbidden.underweight) expect(plain(all[k]), `${k} contains "${term}"`).not.toContain(term.toLowerCase());
    closeAll();
  });
});
