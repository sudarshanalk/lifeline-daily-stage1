// P3-36 D6, D7 and D9 at source level (the live checks are e2e/screens/icons-chart-ring.spec.mjs): the icon helper is
// decoration only and uses the header's icon set; the bar counts use the table's words; the ring keeps
// data-lld-ring-done as it was and carries the count before Done through the redirect.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const read = (p) => readFileSync(resolve(plugin, p), 'utf8');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const icon = (name, tone) => execFileSync('php', ['-r', `define('ABSPATH', '/'); function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } function esc_html($s) { return esc_attr($s); } function esc_url($s) { return $s; } function home_url($p = '') { return $p; } function add_action() {} function add_filter() {} function apply_filters($h, $v) { return $v; }
  require ${toPhpPath(resolve(plugin, 'includes/components.php'))}; echo lld_members_topic_icon(${JSON.stringify(name)}, ${JSON.stringify(tone)});`], { encoding: 'utf8', timeout: 60000 });

test('P3-36 D6: the topic icon is the header set, hidden from screen readers, with a known tone', () => {
  const header = readFileSync(resolve(plugin, '../../mu-plugins/lld-tools/templates/parts/v2-header.php'), 'utf8');
  const flame = icon('flame', 'coral');
  expect(flame).toMatch(/^<span class="lld-topic-icon lld-member-icon tone-coral" aria-hidden="true"><svg [^>]*focusable="false">/);
  // The same Tabler path as the header's.
  const path = flame.match(/<svg[^>]*>(.*)<\/svg>/)[1];
  expect(header).toContain(`'flame' => '${path}'`);
  // Unknown names and tones fall back, never printing what was asked for.
  expect(icon('nope', 'neon')).toContain('tone-stone');
  expect(icon('nope', 'neon')).toBe(icon('compass', 'stone'));
  expect(icon('flame', '"><script>')).not.toContain('<script>');
});

test('P3-36 D6: More, Quick log and the track weeks use it; the week tick rule is unchanged', () => {
  const growth = read('includes/class-growth.php');
  const more = growth.slice(growth.indexOf('public static function more() {'), growth.indexOf("$main .= '</ul>';", growth.indexOf('public static function more() {')));
  expect(more.match(/', '(?:flame|compass|hourglass|leaf|hearts|stethoscope|heart|weight)', '(?:coral|amber|teal|pink|stone|blue|rose)' \)/g).length).toBe(12);
  expect(more).toContain("lld_members_topic_icon( $item[3], $item[4] ) : '' ) . '<strong>' . esc_html( $item[0] ) . '</strong>");
  const today = read('includes/stage3/screens/class-today-screen.php');
  for (const [tile, name] of [['Water', 'droplet'], ['Meals', 'leaf'], ['Mood', 'hearts']]) {
    expect(today).toContain(`lld_members_topic_icon( '${name}',`);
    expect(today).toContain(`<span class="lld-tile-label">${tile}`);
  }
  const programme = read('includes/class-programme.php');
  expect(programme).toContain("lld_members_topic_icon( ...self::week_icon( (string) $row->track_key ) )");
  expect(programme).toContain('if ( $week * 7 <= $reached && 7 === $done ) {');
});

test('P3-36 D7: each bar prints its count as the table words it, and the guide key is the approved label', () => {
  const page = read('includes/stage3/screens/class-progress-page.php');
  expect(page).toContain(`<i class="lld-bar-value">' . esc_html( (int) $b['days'] . ' of 7' ) . '</i><span style="height:' . (int) round( $b['days'] / 7 * 100 ) . '%"></span>`);
  expect(page).toContain(`<td data-lld-days-value>' . (int) $b['days'] . ' of 7</td>`);
  expect(page).toContain('<p class="lld-bar-guide-key" aria-hidden="true"><i class="lld-bar-guide-swatch"></i>7-day guide</p>');
  const css = read('assets/stage3-account.css');
  expect(css).toMatch(/\.lld-week-chart \.lld-bar::before \{[^}]*grid-row: 2;[^}]*align-self: start;[^}]*border-top: 1\.5px dashed/);
  expect(css).toMatch(/\.lld-week-chart \.lld-bar-value \{[^}]*grid-row: 1;/);
});

test('P3-36 D9: the ring keeps data-lld-ring-done, carries the count before, and is still under reduced motion', () => {
  const today = read('includes/stage3/screens/class-today-screen.php');
  expect(today).toContain(`data-lld-ring-done="' . (int) $ring['done'] . '"`);
  expect(today).toContain("$was = self::ring( $member_id, $facts )['done'];");
  expect(today).toContain("'/members/home/?saved=' . rawurlencode( $got ) . '&ring_was=' . (int) $was . '&ring_on=' . rawurlencode( $today )");
  // Only the link's own day counts, "up" only for the one day just added, "already counted" only when it is.
  expect(today).toContain("sanitize_text_field( wp_unslash( (string) $_GET['ring_on'] ) ) !== (string) $facts['today']");
  expect(today).toContain("$rose  = $today_on && $ring['done'] === $was + 1;");
  expect(today).toContain("in_array( (string) $facts['today'], LLD_Members_Goal_Journey::active_dates( $member_id, 14 ), true )");
  const css = read('assets/stage3-account.css');
  expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\) \{\s*body\.lld-members-v2-app \.lld-ring-rose \.lld-ring-fill,\s*body\.lld-members-v2-app \.lld-ring-card\.lld-ring-rose \{\s*animation: none;/);
});
