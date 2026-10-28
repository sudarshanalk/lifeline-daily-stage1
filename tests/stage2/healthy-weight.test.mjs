// FR2-39 Healthy Weight Check (Stage 2 Test Criteria, FR2-39 page). Drives the real calculator page in jsdom with the
// fixtures in fixtures/healthy-weight, whose expected values come from ORC-05 (an independent decimal reference written
// from the requirement). Browser journeys (save, onward links, landing page) are in tests/stage2/e2e/healthy-weight.spec.mjs.
import { describe, expect, test, beforeAll } from 'vitest';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, cpSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { ROOT, toolPath } from '../lib/paths.mjs';
import { fixtures } from '../lib/fixtures.mjs';
import { healthyWeightRunner, diabetesRunner, frozenHashes, messages, loadPage } from '../lib/tool-page.mjs';
import { engineFreeze } from '../ci/engine-freeze.mjs';
import { provenanceCheck } from '../ci/provenance-check.mjs';
import { signoffProblems } from '../ci/clinical-gate.mjs';
import { sha256 } from '../lib/hash.mjs';

const read = (rel) => readFileSync(resolve(ROOT, rel), 'utf8');
const HTML = readFileSync(toolPath('healthy-weight'), 'utf8');
const strip = ({ shown, ...rest }) => rest;
let run;
beforeAll(() => { run = healthyWeightRunner(); });

/** Replays fixtures and requires the ORC-05 value exactly; returns [fixture, result] pairs. */
function replay(pattern) {
  const fxs = fixtures(pattern);
  expect(fxs.length, pattern).toBeGreaterThan(0);
  return fxs.map((f) => { const got = run(f.inputs); expect(strip(got), `${f.id} ${f.purpose}`).toEqual(f.expected); return [f, got]; });
}

/** The page in jsdom with its status gate answered (active unless told otherwise) and a stub parent collecting messages. */
async function framed({ status = 'active', fail = false } = {}) {
  const dom = new JSDOM(HTML.split('__LLD_ORIGIN__').join('https://lld.test'), { url: 'https://lld.test/tools/healthy-weight-tool.html', runScripts: 'dangerously', resources: undefined, pretendToBeVisual: true,
    beforeParse(w) {
      w.fetch = (u) => fail ? Promise.reject(new Error('offline')) : Promise.resolve({ ok: true, json: () => Promise.resolve({ tools: { 'healthy-weight': { status, approved_version: 'a'.repeat(64) } } }) });
      const sent = []; w.__sent = sent;
      Object.defineProperty(w, 'parent', { configurable: true, value: { postMessage: (message, origin) => sent.push({ message, origin }) } });
    } });
  // the page's own <script src="lld-members-link.js"> is not fetched by jsdom here: load the real file into the page
  dom.window.eval(read('site/public_html/tools/lld-members-link.js'));
  await new Promise((r) => setTimeout(r, 30));
  return dom.window;
}
const calc = (w) => w.__sent.filter((s) => /^lld-calculator-/.test(s.message.type));
function fill(w, i) {
  const d = w.document, set = (id, v) => { d.getElementById(id).value = v == null ? '' : String(v); };
  set('age', i.age); set('ht', i.heightCm); set('wt', i.weightKg); set('waist', i.waistCm);
  for (const [g, v] of Object.entries({ preg: i.preg, eat: i.eat, bg: i.bg, mixed: i.mixed, loss: i.loss })) if (v) d.querySelector(`[data-tap="${g}"] [data-val="${v}"]`).click();
  d.getElementById('tool').dispatchEvent(new w.Event('submit', { cancelable: true }));
}
const flush = () => new Promise((r) => setTimeout(r, 30));
const BASE = { age: 40, preg: 'no', eat: 'no', heightCm: 170, weightKg: 70, bg: 'White', loss: 'no' };

describe('FR2-39 Healthy Weight Check', () => {
  test('TC-FR2-39-AS-01', () => {
    replay('FX-HW-BMI-*');
    // the reference's own results file holds the same values the fixtures carry (ORC-05 wrote both)
    const orc = JSON.parse(read('oracles/orc-05-healthy-weight/results.json'));
    expect(orc.oracle_id).toBe('ORC-05');
    const byId = Object.fromEntries(orc.results.map((r) => [r.fixture_id, r.expected]));
    for (const f of fixtures('FX-HW-*')) expect(byId[f.id], f.id).toEqual(f.expected);
  });

  test('TC-FR2-39-AS-02', () => {
    const pairs = replay('FX-HW-WHTR-*');
    for (const [f] of pairs) {
      const page = loadPage('healthy-weight'); fill(page, { ...BASE, ...f.inputs });
      const zone = page.document.getElementById('waistZone').textContent;
      if (f.expected.bmi >= 35) expect(zone, f.id).not.toMatch(/\d\.\d\d/);
      else if (f.expected.waist) expect(zone, f.id).toContain(f.expected.waist.ratio.toFixed(2));
      page.close();
    }
    // agreement with the Diabetes Risk Check across the shared range (FX-DM-WHTR-01..04: 0.488, 0.500, 0.594, 0.600)
    const dm = diabetesRunner();
    const map = { healthy: 'healthy', raised: 'increased', high: 'high' };
    for (const f of fixtures('FX-DM-WHTR-*')) {
      const theirs = dm(f.inputs).whtr.band;
      const ours = run({ ...BASE, heightCm: f.inputs.heightCm, weightKg: f.inputs.weightKg, waistCm: f.inputs.waistCm }).waist.band;
      expect(ours, f.id).toBe(map[theirs]);
    }
  });

  test('TC-FR2-39-AS-03', () => {
    for (const [f, got] of replay('FX-HW-RANGE-*')) {
      const { lowKg, highKg, lowLb, highLb } = got.range;
      for (const w of [lowKg, highKg]) expect(run({ ...f.inputs, weightKg: w }).category, `${f.id} ${w} kg`).toBe('healthy');
      for (const lb of [lowLb, highLb]) expect(run({ ...f.inputs, weightKg: undefined, weightSt: Math.floor(lb / 14), weightLb: lb % 14 }).category, `${f.id} ${lb} lb`).toBe('healthy');
      expect(got.shown, f.id).toContain('A guide for your height, not a target');
    }
  });

  test('TC-FR2-39-AS-04', () => {
    for (const [f, got] of replay('FX-HW-UNIT-*')) {
      const i = f.inputs;
      const cm = i.heightFt != null ? i.heightFt * 30.48 + (i.heightIn || 0) * 2.54 : i.heightCm;
      const kg = i.weightSt != null ? i.weightSt * 6.35029318 + (i.weightLb || 0) * 0.45359237 : i.weightKg;
      const twin = run({ ...BASE, ...i, heightFt: undefined, heightIn: undefined, weightSt: undefined, weightLb: undefined, waistIn: undefined, heightCm: cm, weightKg: kg, waistCm: i.waistIn != null ? i.waistIn * 2.54 : i.waistCm });
      expect({ bmi: twin.bmi, category: twin.category, waist: twin.waist }, f.id).toEqual({ bmi: got.bmi, category: got.category, waist: got.waist });
    }
  });

  test('TC-FR2-39-AS-05', () => {
    const w = loadPage('healthy-weight');
    const d = w.document;
    for (const f of fixtures('FX-HW-INV-*').filter((x) => x.expected.errors)) {
      const got = run(f.inputs);
      expect(got.errors, f.id).toEqual(f.expected.errors);
    }
    // the page itself: summary at the top with a link per field, focus on it, every field error carries both unit systems
    d.getElementById('wt').value = '24';
    d.getElementById('tool').dispatchEvent(new w.Event('submit', { cancelable: true }));
    const sum = d.getElementById('errSummary');
    expect(sum.hidden).toBe(false);
    expect(d.activeElement).toBe(sum);
    expect(sum.querySelectorAll('a[href^="#err-"]').length).toBeGreaterThan(3);
    expect(d.getElementById('results').hidden).toBe(true);
    expect(d.getElementById('err-wt').textContent).toMatch(/kg.*st/);
    expect(d.getElementById('err-ht').textContent).toMatch(/cm.*ft/);
    // no waist is not an error: it shows the "add your waist" note
    const nw = run({ ...BASE });
    expect(nw.waist).toBeNull();
    expect(nw.shown).toContain('Add your waist above for a fuller picture');
  });

  test('TC-FR2-39-AS-06', () => {
    const msg = messages('healthy-weight');
    const r17 = run(fixtures('FX-HW-INV-01')[0].inputs);
    expect(r17.stop).toBe('under18');
    expect(r17.shown).toContain(msg['stops.under18.title']);
    expect(r17.shown).not.toMatch(/\d/);
    const r100 = run(fixtures('FX-HW-INV-02')[0].inputs);
    expect(r100.errors).toEqual(['age']);
    const w = loadPage('healthy-weight');
    w.document.getElementById('age').value = '100';
    w.document.getElementById('tool').dispatchEvent(new w.Event('submit', { cancelable: true }));
    expect(w.document.getElementById('err-age').textContent).toBe(msg['errors.ageRange']);
  });

  test('TC-FR2-39-AS-07', () => {
    for (const f of fixtures('FX-HW-SAF-*').filter((x) => /^pregnancy (yes|notsure)/.test(x.purpose))) {
      const got = run(f.inputs);
      expect(got.stop, f.id).toBe('pregnancy');
      expect(got.shown, f.id).not.toMatch(/\d/);
    }
    const d = loadPage('healthy-weight').document;
    expect(d.querySelector('[data-tap="sex"], #sex, [name="sex"]')).toBeNull();
    expect([...d.querySelectorAll('[data-tap="preg"] .tap')].map((b) => b.getAttribute('data-val'))).toEqual(['yes', 'no', 'notsure']);
    expect(d.getElementById('qPreg').closest('.field').hidden).toBe(false);   // never conditional on another answer
  });

  test('TC-FR2-39-AS-08', () => {
    const eat = fixtures('FX-HW-SAF-*').filter((x) => /^eating disorder yes/.test(x.purpose));
    expect(eat.map((f) => f.purpose.split(', ')[1])).toEqual(['low BMI', 'middle BMI', 'high BMI']);
    for (const f of eat) {
      const got = run(f.inputs);
      expect(got.stop, f.id).toBe('eating');
      expect(got.shown, f.id).toContain('findahelpline.com');
      expect(got.shown.replace('findahelpline.com', ''), f.id).not.toMatch(/\d/);
    }
  });

  test('TC-FR2-39-AS-09', () => {
    const w = loadPage('healthy-weight');
    const r = healthyWeightRunner();
    const got = r({ ...BASE, loss: 'yes' });
    expect(got.weightLoss).toBe(true);
    // banner: an alert placed before the headline in the result
    const page = loadPage('healthy-weight');
    fill(page, { ...BASE, loss: 'yes' });
    const banner = page.document.querySelector('#bannerZone .banner[role="alert"]');
    expect(banner?.textContent).toContain(messages('healthy-weight')['weightLoss.title']);
    expect(banner.compareDocumentPosition(page.document.getElementById('headlineZone')) & w.Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // every stop wins over every banner and over the other stops in the documented order
    expect(run(fixtures('FX-HW-SAF-15')[0].inputs).stop).toBe('eating');
    expect(run(fixtures('FX-HW-SAF-11')[0].inputs).stop).toBe('under18');
    expect(run(fixtures('FX-HW-SAF-12')[0].inputs).stop).toBe('pregnancy');
    expect(run(fixtures('FX-HW-SAF-13')[0].inputs).stop).toBe('eating');
  });

  test('TC-FR2-39-AS-10', () => {
    const msg = messages('healthy-weight');
    const forbidden = JSON.parse(read('fixtures/forbidden-terms.json'));
    const under = run(fixtures('FX-HW-SAF-16')[0].inputs);
    expect(under.notes).toContain('underweight');
    expect(under.shown).toContain(msg['meaning.underweight']);
    expect(under.shown).toContain('findahelpline.com');
    const terms = (forbidden.underweight || forbidden.underweightState || []).concat(['lose weight', 'deficit', 'burn', 'cut calories']);
    for (const t of terms) expect(under.shown.toLowerCase(), t).not.toContain(String(t).toLowerCase());
    for (const id of ['FX-HW-SAF-17', 'FX-HW-SAF-18']) expect(run(fixtures(id)[0].inputs).shown, id).toContain(msg['meaning.class3']);
    expect(run(fixtures('FX-HW-SAF-19')[0].inputs).shown).toContain(msg['meaning.older']);
    expect(run(fixtures('FX-HW-SAF-20')[0].inputs).shown).not.toContain(msg['meaning.older']);
  });

  test('TC-FR2-39-AS-11', () => {
    const msg = messages('healthy-weight');
    const banned = [/calorie/i, /kcal/i, /\blose \d/i, /target weight/i, /weight-loss target/i, /[$£€]\s?\d/, /premium/i, /upgrade/i, /subscribe/i, /diagnos(e|is) you/i, /you have (obesity|an eating disorder)/i];
    const states = fixtures('FX-HW-*').filter((f) => !f.expected.errors);
    expect(states.length).toBeGreaterThan(80);
    const required = ['method.title', 'disclaimer'];
    for (const f of states) {
      const page = loadPage('healthy-weight');
      fill(page, { ...BASE, ...f.inputs });
      if (f.inputs.heightFt != null || f.inputs.weightSt != null) continue;   // imperial states are covered by run() above
      const text = page.document.body.textContent;
      for (const re of banned) expect(text, `${f.id} ${re}`).not.toMatch(re);
      expect(text, f.id).toContain(msg.disclaimer);
      if (f.expected.stop) { page.close(); continue; }   // a stop shows its message and the disclaimer only, no method or numbers
      for (const k of required) expect(text, `${f.id} ${k}`).toContain(msg[k]);
      expect(text, f.id).toContain(msg['howWaist.title']);
      expect(page.document.querySelector('[data-test="source-version"]')?.textContent, f.id).toMatch(/NICE NG246/);
      expect(text, f.id).toContain('Sources: NICE NG246');
      page.close();
    }
  });

  test('TC-FR2-39-AS-12', () => {
    expect(engineFreeze().ok, engineFreeze().problems.join('\n')).toBe(true);
    const tmp = mkdtempSync(join(tmpdir(), 'lld-hw-'));
    try {
      for (const d of ['manifests', 'registry']) cpSync(resolve(ROOT, d), join(tmp, d), { recursive: true });
      mkdirSync(join(tmp, 'site', 'public_html', 'tools'), { recursive: true });
      cpSync(resolve(ROOT, 'site', 'public_html', 'tools'), join(tmp, 'site', 'public_html', 'tools'), { recursive: true });
      const page = join(tmp, 'site', 'public_html', 'tools', 'healthy-weight-tool.html');
      const mutate = (from, to, file = page) => { const src = readFileSync(file, 'utf8'); expect(src.includes(from), from).toBe(true); writeFileSync(file, src.replace(from, to)); const r = engineFreeze({ root: tmp }); writeFileSync(file, src); return r; };
      // arithmetic, a threshold and the safety order each break the frozen engine
      expect(mutate('weightKg * 10000 / (heightCm * heightCm)', 'weightKg * 10000 / (heightCm * heightCm) * 1.01').ok).toBe(false);
      expect(mutate('standard:[18.5,25,30,35,40]', 'standard:[18.5,26,30,35,40]').ok).toBe(false);
      expect(mutate("if (i.eat === 'yes') return { stop: 'eating' };", '').ok).toBe(false);
      // a safety message is wording: its fingerprint no longer matches the approved copy record
      const copy = JSON.parse(read('approvals/copy.json')).messages;
      const changed = HTML.replace('Please make an appointment with your doctor soon.', 'Please see a doctor if you like.');
      expect(sha256(messages('healthy-weight', changed)['weightLoss.body'])).not.toBe(copy['healthy-weight.weightLoss.body']);
      expect(sha256(messages('healthy-weight')['weightLoss.body'])).toBe(copy['healthy-weight.weightLoss.body']);
      // the two older tools' protections still fail on their own deliberate changes
      const protein = join(tmp, 'site', 'public_html', 'tools', 'protein-requirement-tool.html');
      const diabetes = join(tmp, 'site', 'public_html', 'tools', 'diabetes-risk-tool.html');
      expect(mutate('r<0.5?', 'r<0.55?', diabetes).ok).toBe(false);
      const pSrc = readFileSync(protein, 'utf8'); const m = pSrc.match(/healthyHigh:24\.9/); expect(m).toBeTruthy();
      expect(mutate('healthyHigh:24.9', 'healthyHigh:25.9', protein).ok).toBe(false);
      expect(frozenHashes('healthy-weight')['js:HW_ENGINE']).toBe(JSON.parse(read('manifests/healthy-weight.json')).versions.find((v) => v.is_live).engine_hashes['js:HW_ENGINE']);
    } finally { rmSync(tmp, { recursive: true, force: true }); }
  });

  test('TC-FR2-39-AS-13', async () => {
    const off = await framed({ status: 'withdrawn' });
    expect(off.document.getElementById('lldUnavailable').hidden).toBe(false);
    expect(off.document.getElementById('tool').hidden).toBe(true);
    expect(calc(off).filter((s) => s.message.type === 'lld-calculator-result')).toEqual([]);
    const down = await framed({ fail: true });
    expect(down.document.querySelector('[data-test="retry"]')).not.toBeNull();
    expect(down.document.getElementById('tool').hidden).toBe(true);
    // a page open when the tool is withdrawn clears any result it had offered, so it can no longer be saved
    const open = await framed();
    fill(open, BASE); await flush();
    expect(calc(open).at(-1).message.type).toBe('lld-calculator-result');
    open.LLD_STATUS = 'withdrawn'; open.document.dispatchEvent(new open.Event('lld-result-change')); await flush();
    expect(calc(open).at(-1).message.type).toBe('lld-calculator-cleared');
    // production needs the owner's named, dated approval of this exact version
    const prod = provenanceCheck({ env: 'production' });
    const approval = JSON.parse(read('approvals/healthy-weight.json'));
    if (approval.clinical_signoff.decision !== 'approved') {
      expect(prod.problems.join('\n')).toMatch(/healthy-weight: clinical_signoff\.decision/);
      expect(signoffProblems().join('\n')).toMatch(/healthy-weight:/);
    }
    expect(provenanceCheck({ env: 'staging' }).ok).toBe(true);
    const tmp = mkdtempSync(join(tmpdir(), 'lld-hw-gate-'));
    try {
      for (const d of ['approvals', 'manifests', 'registry']) cpSync(resolve(ROOT, d), join(tmp, d), { recursive: true });
      cpSync(resolve(ROOT, 'site', 'public_html', 'tools'), join(tmp, 'site', 'public_html', 'tools'), { recursive: true });
      for (const slug of ['protein', 'diabetes', 'healthy-weight']) {
        const p = join(tmp, 'approvals', `${slug}.json`); const a = JSON.parse(readFileSync(p, 'utf8'));
        a.clinical_signoff = { name: 'Owner (test)', date: '2026-10-28', decision: 'approved' }; writeFileSync(p, JSON.stringify(a));
      }
      expect(provenanceCheck({ root: tmp, env: 'production' }).ok).toBe(true);
      const p = join(tmp, 'approvals', 'healthy-weight.json'); const a = JSON.parse(readFileSync(p, 'utf8'));
      a.version_hash = 'f'.repeat(64); writeFileSync(p, JSON.stringify(a));   // approval for another version
      expect(provenanceCheck({ root: tmp, env: 'production' }).ok).toBe(false);
    } finally { rmSync(tmp, { recursive: true, force: true }); }
  });

  test('TC-FR2-39-AS-14', async () => {
    const sentinel = JSON.parse(read('fixtures/sentinel-values.json'));
    const weight = Number(sentinel.weight ?? sentinel.weightKg ?? 83.7), waist = Number(sentinel.waist ?? sentinel.waistCm ?? 97.3);
    const w = await framed();
    fill(w, { ...BASE, weightKg: weight, waistCm: waist, age: 47 }); await flush();
    const msg = calc(w).at(-1).message;
    expect(Object.keys(msg).sort()).toEqual(['band', 'calculator', 'headline', 'onward', 'type', 'unit', 'version']);
    expect(msg).toMatchObject({ type: 'lld-calculator-result', calculator: 'healthy-weight', unit: 'kg/m²', onward: true });
    expect(msg.headline).toBe(w.LAST.bmi.toFixed(1));
    expect(calc(w).at(-1).origin).toBe('https://lld.test');
    const everything = JSON.stringify(calc(w));
    for (const v of [String(weight), String(waist), '"47"', 'White', 'no"']) expect(everything, v).not.toContain(v);
    // any edit clears the result and the save with it
    const age = w.document.getElementById('age'); age.value = '48'; age.dispatchEvent(new w.Event('input', { bubbles: true })); await flush();
    expect(calc(w).at(-1).message).toEqual({ type: 'lld-calculator-cleared', calculator: 'healthy-weight' });
    expect(w.document.getElementById('results').hidden).toBe(true);
    // a stop offers nothing to save
    fill(w, { ...BASE, eat: 'yes' }); await flush();
    expect(calc(w).at(-1).message).toEqual({ type: 'lld-calculator-cleared', calculator: 'healthy-weight', onward: false });
    // nothing in the page writes to storage or sends anything except the status check
    expect(HTML).not.toMatch(/localStorage|sessionStorage|document\.cookie|XMLHttpRequest|sendBeacon/);
    expect((HTML.match(/fetch\(/g) || []).length).toBe(1);
    // the landing page listener checks the sending frame, the tool id and clears on "cleared" (lld-members)
    const billing = read('site/public_html/wp-content/plugins/lld-members/includes/class-billing.php');
    expect(billing).toContain('event.source!==frame.contentWindow');
    expect(billing).toContain('data.type==="lld-calculator-cleared"');
    expect(billing).toMatch(/'healthy-weight' === \$which/);
  });

  test('TC-FR2-39-AS-15', async () => {
    const cases = { underweight: { ...BASE, weightKg: 50 }, loss: { ...BASE, loss: 'yes' }, healthy: BASE };
    const seen = {};
    for (const [k, i] of Object.entries(cases)) { const w = await framed(); fill(w, i); await flush(); seen[k] = calc(w).at(-1).message.onward; }
    expect(seen).toEqual({ underweight: false, loss: false, healthy: true });
    for (const stop of [{ age: 17 }, { preg: 'yes' }, { preg: 'notsure' }, { eat: 'yes' }]) {
      const w = await framed(); fill(w, { ...BASE, ...stop }); await flush();
      expect(calc(w).at(-1).message, JSON.stringify(stop)).toEqual({ type: 'lld-calculator-cleared', calculator: 'healthy-weight', onward: false });
    }
    // members whose profile excludes the track never get the link (server side, section 8.2)
    const billing = read('site/public_html/wp-content/plugins/lld-members/includes/class-billing.php');
    expect(billing).toMatch(/LLD_Members_Safety::track_allowed\( \$member_id, \$label \)/);
  });

  test('TC-FR2-39-AS-16', () => {
    const cards = JSON.parse(read('site/public_html/wp-content/mu-plugins/lld-tools/data/tool-cards.json')).cards.map((c) => c.tool_slug);
    expect(cards).toEqual(['protein', 'diabetes', 'healthy-weight']);
    const tmp = mkdtempSync(join(tmpdir(), 'lld-hub-'));
    try {
      const status = { tools: { protein: { status: 'active' }, diabetes: { status: 'active' }, 'healthy-weight': { status: 'active' } },
        labels: [{ slug: 'healthy-weight', label: 'Healthy weight (planned)', status: 'label_only' }] };   // a server copy still carrying the old chip
      writeFileSync(join(tmp, 'status.json'), JSON.stringify(status));
      const html = renderHub(join(tmp, 'status.json'));
      const doc = new JSDOM(html).window.document;
      expect([...doc.querySelectorAll('[data-tool-card]')].map((c) => `${c.getAttribute('data-tool')}:${c.getAttribute('data-status')}`)).toEqual(['protein:active', 'diabetes:active', 'healthy-weight:active']);
      expect(html).not.toContain('Planned');
      // before its status entry is installed (production until the owner's sign-off) the new card is not listed at all
      delete status.tools['healthy-weight']; writeFileSync(join(tmp, 'status.json'), JSON.stringify(status));
      const before = new JSDOM(renderHub(join(tmp, 'status.json'))).window.document;
      expect([...before.querySelectorAll('[data-tool-card]')].map((c) => c.getAttribute('data-tool'))).toEqual(['protein', 'diabetes']);
    } finally { rmSync(tmp, { recursive: true, force: true }); }
  });
});

/** Render [lld_tools_hub] with PHP and the few WordPress functions it needs stubbed. */
function renderHub(statusPath) {
  const plugin = resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'lld-tools.php').replace(/\\/g, '/');
  const php = `<?php
define('ABSPATH', '/');
function home_url($p = '') { return 'https://lld.test' . $p; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function get_option($k, $d = false) { return $k === 'lld_tools_settings' ? ['status_path' => ${JSON.stringify(statusPath.replace(/\\/g, '/'))}] : $d; }
function shortcode_atts($d, $a) { return array_merge($d, (array)$a); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return (string)$s; }
function get_post($id) { return null; }
function add_shortcode() {}
function register_activation_hook() {}
require ${JSON.stringify(plugin)};
echo lld_tools_render_hub([]);`;
  const r = spawnSync('php', ['-d', 'display_errors=stderr'], { input: php, encoding: 'utf8' });
  if (r.status !== 0) throw new Error(r.stderr || r.stdout);
  return r.stdout;
}
