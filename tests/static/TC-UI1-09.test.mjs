// TC-UI1-09 Visual consistency — lint family (AS-01): design/tokens.json exists and carries the SRS tokens; stylelint
// `color-no-hex` passes on the two tools' <style> blocks and the plugin CSS, where the only exemption is the single :root tokens
// block of each stylesheet (wrapped in stylelint-disable/enable color-no-hex); every token the tools use resolves.
import { describe, expect, test } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import stylelint from 'stylelint';
import { ROOT } from '../lib/paths.mjs';

const FILES = ['site/public_html/tools/protein-requirement-tool.html', 'site/public_html/tools/diabetes-risk-tool.html', 'site/public_html/wp-content/mu-plugins/lld-tools/assets/lld-tools.css', 'site/public_html/wp-content/mu-plugins/lld-tools/assets/lld-theme.css'].map((f) => resolve(ROOT, f));

describe('TC-UI1-09 visual consistency — lint', () => {
  test('TC-UI1-09-AS-01', async () => {
    const tokensPath = resolve(ROOT, 'design', 'tokens.json');
    expect(existsSync(tokensPath), 'design/tokens.json exists').toBe(true);
    const t = JSON.parse(readFileSync(tokensPath, 'utf8'));
    expect(t.brand).toMatchObject({ '--brand-green': '#3fd07f', '--accent': '#1f9d57', '--accent-dark': '#157a42' });
    expect(t.charcoal).toBe('#1c1c1c'); expect(t.radius).toBe('18px'); expect(t.shadow).toBeTruthy();
    expect(Object.keys(t.bands).sort()).toEqual(['--band-high', '--band-low', '--band-mod', '--band-vhigh']);
    // stylelint color-no-hex, HTML <style> parsed with postcss-html
    const r = await stylelint.lint({ files: FILES, config: { rules: { 'color-no-hex': true }, overrides: [{ files: ['**/*.html'], customSyntax: 'postcss-html' }] } });
    const problems = r.results.flatMap((x) => x.warnings.map((w) => `${x.source.split(/[\\/]/).pop()}:${w.line} ${w.text}`));
    expect(problems, 'no hard-coded hex outside the tokens block').toEqual([]);
    for (const f of FILES) {
      const src = readFileSync(f, 'utf8');
      const css = f.endsWith('.html') ? src.slice(src.indexOf('<style>'), src.indexOf('</style>')) : src;
      // exactly one exemption, and it wraps the :root tokens block only
      expect((css.match(/stylelint-disable color-no-hex/g) || []).length, `${f}: one exemption`).toBe(1);
      const from = css.indexOf('*/', css.indexOf('stylelint-disable color-no-hex')) + 2;
      const exempt = css.slice(from, css.lastIndexOf('/*', css.indexOf('stylelint-enable color-no-hex')));
      expect(exempt.replace(/\/\*[^]*?\*\//g, '').trim(), `${f}: the exemption wraps the :root tokens block only`).toMatch(/^:root\s*\{[^{}]*\}$/);
      // every var(--x) used in the stylesheet resolves to a token defined in its :root block
      const defined = new Set([...exempt.matchAll(/(--[\w-]+)\s*:/g)].map((m) => m[1]));
      const used = [...new Set([...css.matchAll(/var\((--[\w-]+)/g)].map((m) => m[1]))];
      const missing = used.filter((u) => !defined.has(u));
      expect(missing, `${f}: undefined tokens`).toEqual([]);
    }
  });
});
