// P3-36 D3: the diary's water stepper and hunger and energy tap rows, and the meal plan's swap list. Each control
// sets the Stage 2 form field it names (#lld_water, #lld_hunger, #lld_energy, #lld_recipe_<day>_<slot>), which stays
// in the form with the same name and limits, so the Stage 2 specs that fill those fields keep passing. The fields
// are sent visible and the controls hidden: with scripts off the Stage 2 form is what the member gets.
// The script is run here against a small stand-in for the page, so the behaviour is checked, not only the markup.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const premium = readFileSync(resolve(plugin, 'includes/class-premium.php'), 'utf8');
const js = readFileSync(resolve(plugin, 'assets/stage3-forms.js'), 'utf8');
const css = readFileSync(resolve(plugin, 'assets/stage3-forms.css'), 'utf8');

// A minimal element: attributes, classes, listeners, and the few lookups stage3-forms.js makes.
class El {
  constructor(attrs = {}, parent = null) {
    this.attrs = { ...attrs }; this.listeners = {}; this.classes = new Set(); this.parent = parent; this.children = [];
    this.value = attrs.value ?? ''; this.hidden = 'hidden' in attrs; this.tabIndex = 0; this.textContent = attrs.text ?? ''; this.id = attrs.id ?? ''; this.name = attrs.name ?? '';
    this.focused = false;
    if (parent) parent.children.push(this);
  }
  hasAttribute(n) { return n in this.attrs; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  setAttribute(n, v) { this.attrs[n] = String(v); }
  addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
  fire(t, event = {}) { const e = { prevented: false, target: this, preventDefault() { this.prevented = true; }, ...event }; (this.listeners[t] || []).forEach((f) => f(e)); (doc.listeners[t] || []).forEach((f) => f(e)); return e; }
  get classList() { const c = this.classes; return { add: (n) => c.add(n), toggle: (n, on) => (on ? c.add(n) : c.delete(n)), contains: (n) => c.has(n) }; }
  all() { return this.children.flatMap((k) => [k, ...k.all()]); }
  matches(sel) {
    if (sel === 'p') return this.attrs.tag === 'p';
    if (sel === '.lld-control') return this.attrs.class === 'lld-control';
    if (sel === 'button[type=submit]') return this.attrs.tag === 'button' && this.attrs.type === 'submit';
    if (sel === "select[name='lld_recipe']") return this.attrs.tag === 'select';
    const m = sel.match(/^(form)?\[([a-z-]+)\]$/);
    return !!m && m[2] in this.attrs && (!m[1] || this.attrs.tag === 'form');
  }
  closest(sel) { for (let e = this; e; e = e.parent) if (e.matches(sel)) return e; return null; }
  querySelectorAll(sel) { return this.all().filter((e) => e.matches(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  focus() { this.focused = true; }
  requestSubmit() { this.submitted = !this.fire('submit').prevented; }
}

let doc = { listeners: {} };
// state 'loading': the file came before the form (the meal plan), and sets up on DOMContentLoaded.
const boot = (build, state = 'complete') => {
  const body = new El({ tag: 'body' });
  const made = build(body);
  const document = doc = {
    readyState: state, listeners: {},
    addEventListener(t, f) { (this.listeners[t] ||= []).push(f); },
    querySelectorAll: (sel) => (sel === '.lld-file input[type=file]' ? [] : body.querySelectorAll(sel)),
    querySelector: (sel) => body.querySelector(sel),
    getElementById: (id) => body.all().find((e) => e.id === id) || null,
  };
  const window = {};
  vm.runInNewContext(js, { window, document });
  vm.runInNewContext(js, { window, document }); // carried twice on a page: binds once
  made.ready = () => (document.listeners.DOMContentLoaded || []).forEach((f) => f());
  return made;
};

const field = (body, id, value, extra = {}) => new El({ tag: 'input', id, value, ...extra }, new El({ tag: 'p' }, body));
const control = (body, attr, id) => { const outer = new El({ class: 'lld-control', hidden: '' }, body); return [outer, new El({ [attr]: id }, outer)]; };

test('TC-FR2-37-AS-06 (water stepper)', () => {
  const page = boot((body) => {
    const input = field(body, 'lld_water', '', { min: '0', max: '40' });
    const [outer, wrap] = control(body, 'data-lld-stepper', 'lld_water');
    const less = new El({ 'data-lld-step': '-1' }, wrap);
    const out = new El({ 'data-lld-stepper-value': '', text: '0' }, wrap);
    const more = new El({ 'data-lld-step': '1' }, wrap);
    return { input, outer, less, out, more };
  });
  // The control takes over; the field stays in the form, out of sight and out of the tab order.
  expect(page.outer.hidden).toBe(false);
  expect(page.input.parent.classList.contains('lld-visually-hidden')).toBe(true);
  expect(page.input.parent.getAttribute('aria-hidden')).toBe('true');
  expect(page.input.tabIndex).toBe(-1);
  // Nothing logged yet shows 0 and posts nothing until a button is used.
  expect([page.input.value, page.out.textContent]).toEqual(['', '0']);
  page.more.fire('click');
  expect([page.input.value, page.out.textContent]).toEqual(['1', '1']);
  page.less.fire('click'); page.less.fire('click');
  expect([page.input.value, page.out.textContent]).toEqual(['0', '0']);
  // A value set straight on the field (as the Stage 2 spec's fill does) is shown, and the limits hold.
  page.input.value = '40'; page.input.fire('input');
  expect(page.out.textContent).toBe('40');
  page.more.fire('click');
  expect(page.input.value).toBe('40');

  const form = premium.slice(premium.indexOf('data-lld-day-form'), premium.indexOf("lld_button( array( 'label' => 'Save today' ) )"));
  expect(form).toContain('<p><label for="lld_water">Glasses of water</label><input id="lld_water" name="lld_water" type="number" min="0" max="40" step="1" inputmode="numeric" value="');
  expect(form).toContain('<div class="lld-control" hidden><span class="lld-control-label" id="lld_water_caption">Glasses of water</span>');
  expect(form).toContain('<button type="button" data-lld-step="-1" aria-label="Remove a glass of water">&minus;</button>');
  expect(form).toContain('<button type="button" data-lld-step="1" aria-label="Add a glass of water">+</button>');
  expect(form).not.toContain('lld-visually-hidden');
  // The summary line the Stage 2 spec reads is unchanged.
  expect(premium).toContain("'<p data-lld-day-extras>Water: ' . esc_html( '' === $water ? 'not logged' : $water . ' glasses' ) . '. Hunger: ' . esc_html( '' === $hunger ? 'not logged' : $hunger . ' of 5' ) . '. Energy: '");
});

test('TC-FR2-37-AS-06 (hunger and energy taps)', () => {
  const page = boot((body) => {
    const input = field(body, 'lld_hunger', '2');
    const [outer, wrap] = control(body, 'data-lld-taps', 'lld_hunger');
    const taps = [1, 2, 3, 4, 5].map((n) => new El({ 'data-lld-tap': String(n) }, wrap));
    return { input, outer, taps };
  });
  const pressed = () => page.taps.map((b) => b.getAttribute('aria-pressed')).join(' ');
  expect(page.outer.hidden).toBe(false);
  expect(pressed()).toBe('false true false false false');
  page.taps[3].fire('click');
  expect(page.input.value).toBe('4');
  expect(pressed()).toBe('false false false true false');
  expect(page.taps[3].classList.contains('is-active')).toBe(true);
  // A second tap keeps the choice: an empty value would not be saved, so the row never shows a cleared state.
  page.taps[3].fire('click');
  expect(page.input.value).toBe('4');
  page.input.value = '1'; page.input.fire('input');
  expect(pressed()).toBe('true false false false false');

  const form = premium.slice(premium.indexOf('data-lld-day-form'), premium.indexOf("lld_button( array( 'label' => 'Save today' ) )"));
  expect(form).toContain("'<p><label for=\"' . $id . '\">' . $tap[0] . ' from 1 to 5</label><input id=\"' . $id . '\" name=\"' . $id . '\" type=\"number\" min=\"1\" max=\"5\" step=\"1\" inputmode=\"numeric\" value=\"'");
  expect(form).toContain("'hunger' => array( 'Hunger', $hunger ),");
  expect(form).toContain("'energy' => array( 'Energy', $energy ),");
  expect(form).toContain("aria-label=\"' . esc_attr( $tap[0] . ' ' . $n . ' of 5' ) . '\">' . $n . '</button>'");
  // Out-of-range posts are still ignored by the save.
  expect(premium).toContain("'hunger'        => array( 'lld_hunger', 1, 5 ),");
  expect(premium).toContain("'energy'        => array( 'lld_energy', 1, 5 ),");
  // 44 px targets, and a chosen button that reads in Day and Night.
  expect(css).toMatch(/\.lld-stepper button,\n\.lld-tap-row button,\n\.lld-pill-row button \{[^}]*min-width: 44px;\n\tmin-height: 44px;/);
  expect(css).toMatch(/\.lld-tap-row button\.is-active,\n\.lld-pill-row button\.is-active \{\n\tbackground: var\(--lld-ink, #1f2a37\);\n\tborder-color: var\(--lld-ink, #1f2a37\);\n\tcolor: var\(--lld-card, #fff\);/);
  expect(css).toContain('.lld-control[hidden],\n.lld-recipe-swap-list[hidden] {\n\tdisplay: none !important;');
});

test('TC-FR2-25-AS-01 (sex pills set the select, and a second tap leaves it blank)', () => {
  const page = boot((body) => {
    const input = field(body, 'lld_sex', '');
    const [outer, wrap] = control(body, 'data-lld-pills', 'lld_sex');
    const pills = ['female', 'male', 'prefer-not-to-say'].map((v) => new El({ 'data-lld-pill': v }, wrap));
    return { input, outer, pills };
  });
  expect(page.outer.hidden).toBe(false);
  page.pills[2].fire('click');
  expect(page.input.value).toBe('prefer-not-to-say');
  expect(page.pills.map((b) => b.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
  page.pills[2].fire('click');
  expect(page.input.value).toBe('');
  // selectOption on the select (the Stage 2 spec) is shown on the pills.
  page.input.value = 'female'; page.input.fire('change');
  expect(page.pills.map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
});

test('TC-FR2-32-AS-06 (swap list)', () => {
  const build = (body) => {
    const form = new El({ tag: 'form', 'data-lld-swap': '1-breakfast' }, body);
    const select = new El({ tag: 'select', id: 'lld_recipe_1_breakfast', name: 'lld_recipe', value: '11' }, new El({ tag: 'p' }, form));
    const list = new El({ 'data-lld-swap-panel': '', hidden: '' }, form);
    const picks = ['11', '12'].map((v) => new El({ 'data-lld-swap-pick': v }, list));
    const swap = new El({ tag: 'button', type: 'submit' }, form);
    return { form, select, list, picks, swap };
  };
  // Swap opens the list instead of saving the first alternative unseen; a tap on one saves it.
  let page = boot(build);
  expect(page.select.parent.classList.contains('lld-visually-hidden')).toBe(true);
  expect(page.swap.getAttribute('aria-expanded')).toBe('false');
  expect(page.swap.getAttribute('aria-controls')).toBe(page.list.id);
  expect(page.form.fire('submit').prevented).toBe(true);
  expect(page.list.hidden).toBe(false);
  expect(page.swap.getAttribute('aria-expanded')).toBe('true');
  expect(page.picks[0].focused).toBe(true);
  page.picks[1].fire('click');
  expect(page.select.value).toBe('12');
  expect(page.form.submitted).toBe(true);
  // Swap again closes an open list.
  page = boot(build);
  page.form.fire('submit'); page.form.fire('submit');
  expect(page.list.hidden).toBe(true);
  expect(page.swap.getAttribute('aria-expanded')).toBe('false');
  // The Stage 2 spec's route: choose on the select, then Swap saves that choice.
  page = boot(build);
  page.select.value = '12'; page.select.fire('change');
  expect(page.form.fire('submit').prevented).toBe(false);
  expect(page.list.hidden).toBe(true);
  // The same when the choice is made before the page has finished loading.
  page = boot(build, 'loading');
  expect(page.select.parent.classList.contains('lld-visually-hidden')).toBe(false);
  page.select.value = '12'; page.select.fire('change');
  page.ready();
  expect(page.select.parent.classList.contains('lld-visually-hidden')).toBe(true);
  expect(page.form.fire('submit').prevented).toBe(false);

  // The posted fields, the select's id and the Swap button are Stage 2's; the list offers the same alternatives.
  const swap = premium.slice(premium.indexOf("class=\"lld-form lld-recipe-swap\""), premium.indexOf("'label' => 'Swap'"));
  expect(swap).toContain('<input type="hidden" name="lld_slot" value="');
  expect(swap).toContain("'<p><label for=\"' . esc_attr( $field ) . '\">Swap Day ' . (int) $day . ' ' . esc_html( $slot ) . '</label> <select id=\"' . esc_attr( $field ) . '\" name=\"lld_recipe\">'");
  expect(swap).toContain('data-lld-swap-panel hidden aria-label="');
  expect(swap.match(/foreach \( \$recipe\['options'\] as \$option \)/g)).toHaveLength(2);
  expect(swap).toContain("'<li><button type=\"button\" data-lld-swap-pick=\"' . (int) $option['id'] . '\">' . esc_html( $option['title'] ) . '</button></li>'");
  expect(css).toMatch(/\.lld-recipe-swap-list button \{[^}]*min-height: 44px;/);
});
