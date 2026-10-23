// Load a tool page in jsdom (scripts run, network disabled) and expose the engine functions,
// the extracted source text of every frozen engine object, and the copy (MESSAGES) layer.
// Used by: engine-freeze, SAF suite, copy-hash, unit tests. Never writes anything.
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { toolPath, TOOL_FILES } from './paths.mjs';
import { sha256 } from './hash.mjs';

export function loadPage(slug, { html, url } = {}) {
  const source = html ?? readFileSync(toolPath(slug), 'utf8');
  const dom = new JSDOM(source, {
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    url: url ?? 'https://lifelinedaily.com/tools/' + TOOL_FILES[slug],
  });
  dom.window.fetch = () => Promise.reject(new Error('network disabled in tests'));
  OPEN.add(dom.window);
  return dom.window;
}
const OPEN = new Set();
/** Close every jsdom window opened so far (their timers would otherwise keep a plain node process alive). */
export function closeAll() { for (const w of OPEN) { try { w.close(); } catch {} } OPEN.clear(); }

/* ---------------------------------------------------------------------------------------
 * Frozen-object extraction (CLAUDE.md engine-freeze list + docs/04 §7).
 * Each entry: how the declaration starts. The body is a brace block or a parenthesised IIFE.
 * Extraction is string/comment aware so braces inside copy strings do not confuse it.
 * ------------------------------------------------------------------------------------- */
export const FROZEN = {
  protein: {
    BANDS: /const BANDS\s*=\s*\{/,
    CEILING: /const CEILING\s*=.*?;/,
    DIET_MULT: /const DIET_MULT\s*=\s*\{/,
    PREG_GKG: /const PREG_GKG\s*=\s*\{/,
    cutoffs: /function cutoffs\s*\([^)]*\)\s*\{/,
    computeProtein: /function computeProtein\s*\([^)]*\)\s*\{/,
    clampMeal: /function clampMeal\s*\([^)]*\)\s*\{/,
  },
  diabetes: {
    ENGINE: /var ENGINE\s*=\s*\(/,
    bandFor: /function bandFor\s*\([^)]*\)\s*\{/,
    idrs: /function idrs\s*\([^)]*\)\s*\{/,
    whtr: /function whtr\s*\([^)]*\)\s*\{/,
    'render.priority': 'priority',
  },
};

const PAIRS = { '{': '}', '(': ')', '[': ']' };

/** Index just past the bracket that closes the one at `open`. Skips strings and comments. */
function matchBracket(src, open) {
  const stack = [src[open]];
  let i = open + 1;
  while (i < src.length && stack.length) {
    const c = src[i], n = src[i + 1];
    if (c === '/' && n === '/') { i = src.indexOf('\n', i); if (i < 0) break; continue; }
    if (c === '/' && n === '*') { i = src.indexOf('*/', i + 2) + 2; continue; }
    if (c === "'" || c === '"' || c === '`') {
      let j = i + 1;
      while (j < src.length && src[j] !== c) { if (src[j] === '\\') j++; j++; }
      i = j + 1; continue;
    }
    if (PAIRS[c]) stack.push(c);
    else if (c === PAIRS[stack[stack.length - 1]]) stack.pop();
    i++;
  }
  if (stack.length) throw new Error('unbalanced brackets');
  return i;
}

/** Copy of `src` with every comment and string-literal body replaced by spaces (same length, same offsets),
 *  so declaration searches cannot be fooled by an original copy hidden in a comment or string. */
export function maskCommentsAndStrings(src) {
  let out = '';
  let i = 0;
  while (i < src.length) {
    const c = src[i], n = src[i + 1];
    if (c === '/' && n === '/') { let j = src.indexOf('\n', i); if (j < 0) j = src.length; out += ' '.repeat(j - i); i = j; continue; }
    if (c === '/' && n === '*') { let j = src.indexOf('*/', i + 2); j = j < 0 ? src.length : j + 2; out += ' '.repeat(j - i); i = j; continue; }
    if (c === "'" || c === '"' || c === '`') {
      let j = i + 1;
      while (j < src.length && src[j] !== c) { if (src[j] === '\\') j++; j++; }
      out += c + ' '.repeat(Math.max(0, j - i - 1)) + (j < src.length ? c : ''); i = j + 1; continue;
    }
    out += c; i++;
  }
  return out;
}

/** Source text of one declaration from its start to the end of the matched block (plus a trailing `;`).
 *  The declaration must occur exactly once in the code (comments and strings masked). */
export function extractDeclaration(src, startRe) {
  const masked = maskCommentsAndStrings(src);
  const all = [...masked.matchAll(new RegExp(startRe.source, startRe.flags.includes('g') ? startRe.flags : startRe.flags + 'g'))];
  if (all.length !== 1) throw new Error(`frozen object ${startRe} must occur exactly once in code, found ${all.length}`);
  const m = all[0];
  const start = m.index;
  if (m[0].endsWith(';')) return m[0];
  const open = start + m[0].length - 1;
  let end = matchBracket(src, open);
  while (src[end] === '(') end = matchBracket(src, end); // IIFE call `(...)()`
  if (src[end] === ';') end++;
  return src.slice(start, end);
}

/** The emergency/symptom priority logic inside render(): control flow only. String literals are
 *  blanked so markup/copy edits (e.g. adding role="alert") are not engine changes, while removing
 *  `suppress=true` or reordering the two branches is. */
export function extractRenderPriority(src) {
  const render = extractDeclaration(src, /function render\s*\([^)]*\)\s*\{/);
  const lines = render.split('\n').filter((l) => /r\.sym\.(emergency|classic)/.test(l) || /suppress\s*=/.test(l));
  return lines.map((l) => l.replace(/(['"`])(?:\\.|(?!\1).)*\1/g, '""').replace(/\s+/g, ' ').trim()).join('\n');
}

/** { name: sourceText } for every frozen object of the tool, from the given HTML (default: repo file). */
export function frozenSources(slug, html) {
  const src = html ?? readFileSync(toolPath(slug), 'utf8');
  const out = {};
  for (const [name, how] of Object.entries(FROZEN[slug])) {
    out[name] = how === 'priority' ? extractRenderPriority(src) : extractDeclaration(src, how);
  }
  return out;
}

/** { "js:<name>": sha256 } — the keys recorded in manifests/<slug>.json engine_hashes. */
export function frozenHashes(slug, html) {
  return Object.fromEntries(Object.entries(frozenSources(slug, html)).map(([k, v]) => ['js:' + k, sha256(v)]));
}

/* ------------------------------------------------------------------------------------------
 * Engine runners: the same input→output mapping the baseline runner used (tools/characterise.mjs),
 * so SAF/characterisation tests compare like with like.
 * ---------------------------------------------------------------------------------------- */
export function proteinRunner(html) {
  const w = loadPage('protein', { html });
  const compute = w.eval('computeProtein');
  const CONV = { IN_CM: 2.54, LB_KG: 0.45359237, ST_KG: 6.35029318 };
  return (inp) => {
    let heightCm = inp.heightCm, weightKg = inp.weightKg;
    if (inp.heightM != null) heightCm = inp.heightM * 100;
    if (inp.heightFt != null) heightCm = inp.heightFt * 30.48 + (inp.heightIn || 0) * CONV.IN_CM;
    if (inp.weightSt != null) weightKg = inp.weightSt * CONV.ST_KG + (inp.weightLb || 0) * CONV.LB_KG;
    else if (inp.weightLb != null && inp.weightKg == null) weightKg = inp.weightLb * CONV.LB_KG;
    const invalid = !(inp.age >= 18 && inp.age <= 99) || !(heightCm >= 140 && heightCm <= 210) || !(weightKg >= 35 && weightKg <= 250) || !inp.sex || !inp.kidney;
    if (invalid) return { error: true };
    const ethMap = { White: 'White', Indian: 'Indian', Pakistani: 'Pakistani', Bangladeshi: 'Bangladeshi', 'Other Asian': 'OtherAsian', Chinese: 'Chinese', 'Black Caribbean': 'BlackCaribbean', 'Black African': 'BlackAfrican', Other: 'Other' };
    const r = compute({ age: inp.age, sex: inp.sex, preg: inp.preg || 'no', heightCm, weightKg, ethnicity: ethMap[inp.ethnicity] || inp.ethnicity, activity: inp.activity, resistance: String(inp.resistance), goal: inp.goal, diet: inp.diet, meals: Number(inp.meals), kidney: inp.kidney, liver: inp.liver || 'no' });
    const out = {};
    for (const k of ['suppressed', 'reason', 'branch', 'targetG', 'lowG', 'highG', 'gPerKg', 'basisKg', 'basisNote', 'perMeal', 'sources', 'capped', 'older', 'dietMult', 'interpKey', 'bmi']) if (r[k] !== undefined) out[k] = typeof r[k] === 'number' ? +r[k].toFixed(3) : r[k];
    return out;
  };
}

export function diabetesRunner(html) {
  const w = loadPage('diabetes', { html });
  const ENGINE = w.eval('ENGINE');
  const bandFor = w.eval('bandFor');
  return (inp) => {
    const heightCm = inp.heightCm, weightKg = inp.weightKg;
    const invalid = !inp.sex || !inp.ageBand || !inp.ethn || !(heightCm >= 140 && heightCm <= 210) || !(weightKg >= 40 && weightKg <= 180) || typeof weightKg !== 'number';
    if (invalid) return { error: true };
    const bmi = weightKg / Math.pow(heightCm / 100, 2);
    const fam = Array.isArray(inp.family) ? inp.family : [];
    const qin = { sex: inp.sex, age: inp.ageBand, bmi, ethn: inp.ethn, smoke: inp.smoke || 'Non', tds: 0, fhdm: fam.length ? 1 : 0, htn: inp.htn || 0, cvd: inp.cvd || 0, gdm: inp.gdm || 0, pcos: inp.pcos || 0, learn: inp.learn || 0, psy: inp.psy || 0, ster: inp.ster || 0, stat: inp.stat || 0, apsy: inp.apsy || 0 };
    const pct = ENGINE.QDR2018A(qin);
    const out = { pct: +pct.toFixed(6), pctDisplay: +pct.toFixed(1), band: bandFor(pct), bmi: +bmi.toFixed(2) };
    if (inp.waistCm) {
      const parents = fam.filter((x) => x === 'mother' || x === 'father').length;
      const family = parents === 2 ? 'both' : parents === 1 ? 'one' : 'none';
      out.idrs = ENGINE.idrs({ age: inp.ageBand, sex: inp.sex, waistCm: inp.waistCm, activity: inp.activity || 'moderate', family });
      out.whtr = ENGINE.whtr(inp.waistCm, heightCm);
      out.whtr.ratio = +out.whtr.ratio.toFixed(4);
    }
    const syms = inp.symptoms || [];
    out.symptomatic = inp.hasSym === 'yes' && syms.some((s) => ['thirst', 'polyuria', 'wtloss'].includes(s));
    out.emergency = !!inp.emergency;
    if (out.emergency) out.scoreSuppressedOnScreen = true;
    return out;
  };
}

/** Flattened MESSAGES copy layer: { "scope": "text", "renalStop.title": "text", ... }. */
export function messages(slug, html) {
  const w = loadPage(slug, { html });
  const obj = w.eval('MESSAGES');
  const out = {};
  const walk = (v, prefix) => {
    if (v && typeof v === 'object' && !Array.isArray(v)) for (const [k, x] of Object.entries(v)) walk(x, prefix ? `${prefix}.${k}` : k);
    else out[prefix] = Array.isArray(v) ? JSON.stringify(v) : String(v);
  };
  walk(obj, '');
  w.close(); OPEN.delete(w);
  return out;
}
