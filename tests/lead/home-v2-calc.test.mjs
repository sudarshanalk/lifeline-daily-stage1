// Homepage v2 quick-check card (S2-L1): its numbers must be the signed-off engines' own. The card's script
// (assets/lld-home-calc.js) is generated from the frozen tool pages by tools/home-v2/build-home-calc.mjs; these checks
// fail if the file was edited by hand or if a tool's engine changed without rebuilding it.
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';
import { ROOT } from '../lib/paths.mjs';
import { build, OUT } from '../../tools/home-v2/build-home-calc.mjs';

const TOOLS = resolve(ROOT, 'site/public_html/tools');
function load(src) { const c = {}; vm.createContext(c); vm.runInContext(src, c); return c; }
/** The engine block of a tool page, run on its own exactly as the page has it. */
function toolEngine(file, start, end, expose) {
  const s = readFileSync(resolve(TOOLS, file), 'utf8').replace(/\r\n/g, '\n');
  const a = s.indexOf(start), b = s.indexOf(end, a);
  return load(s.slice(a, b + end.length).replace(/^const /gm, 'var ') + '\n' + expose);
}

describe('homepage v2 quick-check card uses the signed-off engines', () => {
  test('lld-home-calc.js is exactly what the build script makes from the current tool pages', () => {
    expect(readFileSync(OUT, 'utf8')).toBe(build());
  });

  const home = load(readFileSync(OUT, 'utf8')).LLD_HOME_CALC;

  test('protein: the card gives the tool engine\'s target and range for every height, weight and activity it offers', () => {
    const tool = toolEngine('protein-requirement-tool.html', 'const BANDS = {', 'interpKey: winner.key, capped, older\n  };\n}', 'this.f = computeProtein;').f;
    for (const activity of ['sedentary', 'light', 'moderate', 'vigorous']) {
      for (let h = 140; h <= 210; h += 7) for (let w = 40; w <= 160; w += 6) {
        const s = { heightCm: h, weightKg: w, age: 30, activity, resistance: 'no', goal: 'maintain', preg: 'no', kidney: 'no', liver: 'no', plan: 'no', meals: 3 };
        const a = home.computeProtein(s), b = tool(s);
        expect([a.targetG, a.lowG, a.highG, a.gPerKg]).toEqual([b.targetG, b.lowG, b.highG, b.gPerKg]);
      }
    }
    // the design's example: 72 kg, 175 cm, moderately active
    expect(home.computeProtein({ heightCm: 175, weightKg: 72, age: 30, activity: 'moderate', resistance: 'no', goal: 'maintain', preg: 'no', kidney: 'no', liver: 'no', plan: 'no', meals: 3 }))
      .toMatchObject({ targetG: 86, lowG: 72, highG: 101, gPerKg: 1.2 });
  });

  test('diabetes: the card gives the tool engine\'s 10-year risk and band for every sex, age and BMI it offers', () => {
    const t = toolEngine('diabetes-risk-tool.html', 'var ENGINE = (function(){', '})();', 'this.e = ENGINE;');
    const s = readFileSync(resolve(TOOLS, 'diabetes-risk-tool.html'), 'utf8');
    const band = load(s.slice(s.indexOf('function bandFor(p){'), s.indexOf('}', s.indexOf('function bandFor(p){')) + 1) + '\nthis.b = bandFor;').b;
    for (const sex of ['Female', 'Male']) for (let age = 25; age <= 84; age += 3) for (let bmi = 18; bmi <= 45; bmi += 3) {
      const i = { sex, age, bmi, ethn: 'WhiteNA', smoke: 'Non', tds: 0 };
      const a = home.QDR2018A(i), b = t.e.QDR2018A(i);
      expect(a).toBe(b);
      expect(home.bandFor(a)).toBe(band(b));
    }
  });

  test('healthy weight: the card gives the tool engine\'s waist-to-height ratio and band', () => {
    const t = toolEngine('healthy-weight-tool.html', 'var HW_ENGINE = (function(){', '})();', 'this.e = HW_ENGINE;').e;
    for (let waist = 50; waist <= 150; waist += 1) for (let h = 140; h <= 210; h += 5) {
      const a = home.HW.roundHalfUp(waist / h, 2), b = t.roundHalfUp(waist / h, 2);
      expect(a).toBe(b);
      expect(home.HW.waistBand(a, 0)).toBe(t.waistBand(b, 0));
    }
  });
});
