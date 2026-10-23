// P-A0 step 2: record characterisation outputs from the DEPLOYED engines.
// Loads each tool page in jsdom (scripts run, no network), calls the engine functions
// that the page defines, and fills `expected` for fixtures with oracle "characterisation".
// It never edits fixtures whose oracle is "clinical" or "none".
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { JSDOM } from 'jsdom';

const ROOT = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const TOOLS = `${ROOT}/public_html/tools`;
const sha = (p) => createHash('sha256').update(readFileSync(p)).digest('hex');

function loadPage(file) {
  const html = readFileSync(`${TOOLS}/${file}`, 'utf8');
  const dom = new JSDOM(html, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://lifelinedaily.com/tools/' + file });
  dom.window.fetch = () => Promise.reject(new Error('network disabled in characterisation'));
  return dom.window;
}

// ---------------- protein -----------------------------------------------------------------
function proteinRunner() {
  const w = loadPage('protein-requirement-tool.html');
  const compute = w.eval('computeProtein');
  const CONV = { IN_CM: 2.54, LB_KG: 0.45359237, ST_KG: 6.35029318 };
  return (inp) => {
    // unit handling as the page does: canonical cm/kg
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

// ---------------- diabetes ----------------------------------------------------------------
function diabetesRunner() {
  const w = loadPage('diabetes-risk-tool.html');
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

// ---------------- main ---------------------------------------------------------------------
const runners = { protein: proteinRunner(), diabetes: diabetesRunner() };
const versions = { protein: sha(`${TOOLS}/protein-requirement-tool.html`), diabetes: sha(`${TOOLS}/diabetes-risk-tool.html`) };
let filled = 0, skipped = 0, errors = 0;
for (const tool of ['protein', 'diabetes']) {
  for (const f of readdirSync(`${ROOT}/fixtures/${tool}`)) {
    const p = `${ROOT}/fixtures/${tool}/${f}`;
    const doc = JSON.parse(readFileSync(p, 'utf8'));
    for (const fx of doc.fixtures) {
      if (fx.oracle !== 'characterisation') { skipped++; continue; }
      if (typeof fx.inputs.weightKg === 'string') { skipped++; continue; } // BAND fixtures: inputs assigned by ORC-01 runner
      try {
        fx.expected = runners[tool](fx.inputs);
        fx.source_version = versions[tool];
        fx.status = 'recorded';
        filled++;
      } catch (e) { fx.expected = { error: true, exception: String(e.message) }; errors++; }
    }
    writeFileSync(p, JSON.stringify(doc, null, 2) + '\n');
  }
}
console.log(JSON.stringify({ filled, skipped, errors, versions }, null, 2));
// The tool pages start timers (height reporting); exit explicitly so the run terminates.
process.exit(errors ? 1 : 0);
