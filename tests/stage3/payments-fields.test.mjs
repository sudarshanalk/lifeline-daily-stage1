// P3-36 A3: on My account → Admin → Payments the six text inputs had no type and no class, so neither the shared field
// style nor Admin's own reached them and they read as plain text; the confirm box was a bare browser box. Each now has
// the field class with its label above and "US$" before the prices. Only classes and styles change: every name,
// value, rule and word of the Stage 2 form stays (payments.spec.mjs fills the same names).
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const billing = readFileSync(resolve(plugin, 'includes/class-billing.php'), 'utf8');
const css = readFileSync(resolve(plugin, 'assets/stage3-account.css'), 'utf8');
const form = billing.slice(billing.indexOf("echo '<form method=\"post\" class=\"lld-pay-form\">';"), billing.indexOf('Save prices</button></p></form>'));

test('TC-FR3-87-AS-01 (Payments fields are visible boxes)', () => {
  expect(form.length).toBeGreaterThan(0);
  // The six text inputs: each a text box with the field class, inside a label whose text sits above it.
  for (const [name, label] of [['lld_sale_path', 'Terms of sale address'], ['lld_sale_version', 'Terms of sale version'], ['lld_monthly', 'Monthly price'], ['lld_yearly', 'Yearly price'], ['lld_new_code', 'New code'], ['lld_percent', 'Percent off the first payment']]) {
    expect(form, name).toMatch(new RegExp(`<label class="lld-pay-field"><span class="lld-pay-label">${label}</span> (?:<span class="lld-pay-affix"><span class="lld-pay-prefix">US\\$</span> )?<input type="text" class="lld-pay-input"(?: inputmode="(?:decimal|numeric)")? name="${name}"`));
  }
  // "US$" before both prices, still part of the label, so each box keeps its name ("Monthly price US$").
  expect(form.match(/<span class="lld-pay-prefix">US\$<\/span>/g)).toHaveLength(2);
  expect(form).not.toMatch(/aria-hidden/);
  // The confirm box uses the shared tick-box row; its wording is unchanged.
  expect(form).toContain('<label class="lld-check lld-pay-check"><input type="checkbox" name="lld_confirm_free" value="1"> <span>I confirm a price of 0 or a 100 % code</span></label>');
  // Values and rules untouched: the prices still come from self::prices(), the code still defaults to 50 % off.
  expect(form).toContain("value=\"' . esc_attr( number_format( $prices['monthly'] / 100, 2, '.', '' ) ) . '\"");
  expect(form).toContain("value=\"' . esc_attr( number_format( $prices['yearly'] / 100, 2, '.', '' ) ) . '\"");
  expect(form).toContain('name="lld_percent" value="50"');
  // The styles: border and fill on each box, the label above it, and the prefix joined to the price box.
  expect(css).toMatch(/\.lld-pay-form \.lld-pay-input \{[^}]*border: 1\.5px solid var\(--lld-border[^}]*background: var\(--lld-card/);
  expect(css).toMatch(/\.lld-pay-form \.lld-pay-field \{[^}]*flex-direction: column;/);
  expect(css).toMatch(/\.lld-pay-form \.lld-pay-prefix \{[^}]*border-right: 0;/);
  expect(css).toMatch(/\.lld-pay-form \.lld-pay-check input \{[^}]*width: 20px;[^}]*height: 20px;/);
});
