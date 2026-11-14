// P3-36 C2, A8 and B4 lead fixes: app connections on the account hub, one Library card per approved title,
// and the Notifications tab's reminder rules (hidden weigh-in, email only, the Free limit on updates).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const file = (rel) => resolve(plugin, rel);
const p = (x) => JSON.stringify(x.split('\\').join('/'));

const stubs = `
define('ABSPATH', '/');
function home_url($p = '/') { return 'https://example.test' . $p; }
function esc_url($u) { return $u; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function add_action() {}
function wp_nonce_field($action, $name) { return '<input type="hidden" id="' . $name . '" name="' . $name . '" value="n">'; }
function lld_button($a) { return '<button type="submit">' . $a['label'] . '</button>'; }
function lld_password_field($a) { return '<input type="password" id="' . $a['id'] . '">'; }
class LLD_Members_Access_Tiers {
  const FREE_REMINDERS = 1;
  static function running_reminders($rows, $all) { return []; }
  static function allows() { return false; }
}
`;
const run = (code, requires) =>
  JSON.parse(
    execFileSync('php', ['-d', 'display_errors=stderr', '-r', `${stubs}${requires.map((f) => `require ${p(file(f))};`).join('')} echo json_encode((function(){ ${code} })());`], {
      encoding: 'utf8',
      timeout: 120000,
    }).trim(),
  );

const REMINDERS = ['includes/stage3/class-reminders.php'];
const rows = `
  $rows = [
    ['id'=>1,'reminder_type'=>'log','channel'=>'email','time_local'=>'08:00:00','days'=>'Mon,Tue','active'=>1,'deleted_at'=>null],
    ['id'=>2,'reminder_type'=>'weigh_in','channel'=>'email','time_local'=>'07:30:00','days'=>'Mon','active'=>0,'deleted_at'=>null],
    ['id'=>3,'reminder_type'=>'hw_step','channel'=>'email','time_local'=>'09:00:00','days'=>'Mon','active'=>0,'deleted_at'=>null],
    ['id'=>4,'reminder_type'=>'check_in','channel'=>'browser','time_local'=>'18:00:00','days'=>'Sun','active'=>0,'deleted_at'=>null],
  ];
  $ed = ['eating_disorder'=>true,'hide_weight'=>true];
  $hide = ['eating_disorder'=>false,'hide_weight'=>true];
  $none = ['eating_disorder'=>false,'hide_weight'=>false];
  $post = fn(array $ids, array $on) => [
    'time' => array_fill_keys($ids, '08:15'),
    'days' => array_fill_keys($ids, ['Mon','Wed']),
    'active' => array_fill_keys($on, '1'),
  ];
`;

test('FR3-69 a weigh-in reminder is not listed, changed or added for a member the safety matrix hides weight from', () => {
  const out = run(
    `${rows}
    $ids = fn($list) => array_map(fn($r) => $r['id'], $list);
    return [
      'ed_ids' => $ids(LLD_Members_Reminders::hub_rows($rows, $ed)),
      'hide_ids' => $ids(LLD_Members_Reminders::hub_rows($rows, $hide)),
      'open_ids' => $ids(LLD_Members_Reminders::hub_rows($rows, $none)),
      'ed_html' => LLD_Members_Reminders::hub_markup(5, $rows, $ed, true),
      'hide_html' => LLD_Members_Reminders::hub_markup(5, $rows, $hide, true),
      'open_html' => LLD_Members_Reminders::hub_markup(5, $rows, $none, true),
      'ed_update' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 4], [1, 2]), $ed, true),
      'hide_update' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 4], [1, 2]), $hide, true),
      'ed_update_listed' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 4], [1]), $ed, true),
      'ed_add' => LLD_Members_Reminders::plan_hub_add(['reminder_type'=>'weigh_in','time_local'=>'08:00','days'=>['Mon']], [], $ed, true),
      'hide_add' => LLD_Members_Reminders::plan_hub_add(['reminder_type'=>'weigh_in','time_local'=>'08:00','days'=>['Mon']], [], $hide, true),
      'open_add' => LLD_Members_Reminders::plan_hub_add(['reminder_type'=>'weigh_in','time_local'=>'08:00','days'=>['Mon']], [], $none, true),
    ];`,
    REMINDERS,
  );
  expect(out.ed_ids).toEqual([1, 4]);
  expect(out.hide_ids).toEqual([1, 4]);
  expect(out.open_ids).toEqual([1, 2, 4]);
  for (const html of [out.ed_html, out.hide_html]) {
    expect(html).not.toMatch(/Weigh in/);
    expect(html).not.toMatch(/weigh_in/);
    expect(html).not.toMatch(/lld_reminder_active\[2\]/);
  }
  expect(out.open_html).toMatch(/lld_reminder_active\[2\]/);
  expect(out.open_html).toMatch(/<option value="weigh_in">Weigh in<\/option>/);
  // A crafted post that names the hidden row refuses the whole save: nothing is planned, so nothing is written.
  expect(out.ed_update).toEqual({ error: 'Not available.' });
  expect(out.hide_update).toEqual({ error: 'Not available.' });
  expect(Object.keys(out.ed_update_listed)).toEqual(['1', '4']);
  expect(out.ed_add).toEqual({ error: 'Not available.' });
  expect(out.hide_add).toEqual({ error: 'Not available.' });
  expect(out.open_add.reminder_type).toBe('weigh_in');
  // The sender drops hidden weigh-in rows before it works out what is due.
  const src = readFileSync(file(REMINDERS[0]), 'utf8');
  expect(src).toMatch(/private static function send_for_member[\s\S]{0,400}self::without_hidden\( \$list, self::hub_flags\( \$member_id \) \)/);
});

test('TC-FR3-56-AS-03 a Free member cannot turn on a second reminder from the Notifications tab; a Premium member can', () => {
  const out = run(
    `${rows}
    return [
      'free' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 4], [1, 4]), $none, false),
      'premium' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 4], [1, 4]), $none, true),
      'free_swap' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 4], [4]), $none, false),
      'free_edit' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 4], [1]), $none, false),
      'free_add' => LLD_Members_Reminders::plan_hub_add(['reminder_type'=>'log','time_local'=>'08:00','days'=>['Mon']], $rows, $none, false),
    ];`,
    REMINDERS,
  );
  expect(out.free).toEqual({ error: 'Free includes one reminder. Premium has as many as you like.' });
  expect(out.premium['4'].active).toBe(1);
  // Turning one off and another on in the same save stays within the limit.
  expect(out.free_swap['1'].active).toBe(0);
  expect(out.free_swap['4'].active).toBe(1);
  expect(out.free_edit['1']).toEqual({ time_local: '08:15:00', days: 'Mon,Wed', active: 1 });
  expect(out.free_add).toEqual({ error: 'Free includes one reminder. Premium has as many as you like.' });
});

test('P3-36 B4: reminders are email only, every row is checked before any is saved, and unknown types are never shown', () => {
  const out = run(
    `${rows}
    $bad = $post([1, 2, 4], [1]);
    $bad['days'][4] = [];
    $late = $post([1, 2, 4], [1]);
    $late['time'][4] = '25:00';
    return [
      'html' => LLD_Members_Reminders::hub_markup(5, $rows, $none, true),
      'add' => LLD_Members_Reminders::plan_hub_add(['reminder_type'=>'log','channel'=>'browser','time_local'=>'8:05','days'=>['Mon','Fri']], [], $none, true),
      'bad_type' => LLD_Members_Reminders::plan_hub_add(['reminder_type'=>'hw_step','time_local'=>'08:00','days'=>['Mon']], [], $none, true),
      'no_days' => LLD_Members_Reminders::plan_hub_update($rows, $bad, $none, true),
      'bad_time' => LLD_Members_Reminders::plan_hub_update($rows, $late, $none, true),
      'other_row' => LLD_Members_Reminders::plan_hub_update($rows, $post([1, 2, 3, 4], [1]), $none, true),
      'has_channels' => defined('LLD_Members_Reminders::HUB_CHANNELS'),
    ];`,
    REMINDERS,
  );
  expect(out.html).not.toMatch(/Channel|Browser|lld_reminder_channel/);
  expect(out.has_channels).toBe(false);
  expect(out.add.channel).toBe('email');
  expect(out.add.time_local).toBe('08:05:00');
  expect(out.add.days).toBe('Mon,Fri');
  expect(out.bad_type).toEqual({ error: 'Choose a reminder type.' });
  // One bad row refuses the whole save, so no earlier row is written.
  expect(out.no_days).toEqual({ error: 'Choose at least one day.' });
  expect(out.bad_time).toEqual({ error: 'Choose a time.' });
  // A row the tab does not list (here a Healthy Weight step reminder) cannot be changed through it.
  expect(out.other_row).toEqual({ error: 'Not available.' });
  expect(out.html).not.toMatch(/Hw step|hw_step|lld_reminder_active\[3\]/);
  expect(out.html).toMatch(/<p class="lld-reminder-title">Check in<\/p>/);
  // The two forms' nonce fields have their own ids.
  const ids = [...out.html.matchAll(/ id="([^"]+)"/g)].map((m) => m[1]);
  expect(ids).toContain('lld_reminders_nonce_list');
  expect(ids).toContain('lld_reminders_nonce_add');
  expect(new Set(ids).size).toBe(ids.length);
});

const CONTENT = ['includes/class-content.php'];

test('TC-FR3-111-AS-03', () => {
  const out = run(
    `$items = [
      ['id'=>11,'type'=>'lld_activity','title'=>'A 15-minute brisk walk','length'=>'Easy','track_id'=>100,'track'=>'Healthy Weight'],
      ['id'=>12,'type'=>'lld_activity','title'=>'A 15-Minute Brisk Walk','length'=>'Easy','track_id'=>200,'track'=>'Fitness and Strength'],
      ['id'=>13,'type'=>'lld_activity','title'=>'A 15-minute brisk walk','length'=>'Easy','track_id'=>300,'track'=>''],
      ['id'=>14,'type'=>'lld_lesson','title'=>'A 15-minute brisk walk','length'=>'','track_id'=>0,'track'=>''],
      ['id'=>15,'type'=>'lld_recipe','title'=>'Crème fraîche ÉCLAIR','length'=>'2 servings','track_id'=>0,'track'=>''],
      ['id'=>16,'type'=>'lld_recipe','title'=>'crème fraîche éclair','length'=>'2 servings','track_id'=>0,'track'=>''],
    ];
    return [
      'none' => LLD_Members_Content::library_merge($items, 0),
      'active' => LLD_Members_Content::library_merge($items, 200),
    ];`,
    CONTENT,
  );
  // One card per title within a type, naming both tracks; a track without an approved name is not named.
  expect(out.none.map((c) => [c.type, c.id])).toEqual([['lld_activity', 11], ['lld_lesson', 14], ['lld_recipe', 15]]);
  expect(out.none[0].tracks).toEqual(['Healthy Weight', 'Fitness and Strength']);
  expect(out.none[0].title).toBe('A 15-minute brisk walk');
  // The card opens the item on the member's active track.
  expect(out.active[0].id).toBe(12);
  expect(out.active[0].tracks).toEqual(['Healthy Weight', 'Fitness and Strength']);
});

test('TC-FR3-111-AS-04', () => {
  const src = readFileSync(file(CONTENT[0]), 'utf8');
  const start = src.indexOf('private static function library_cards');
  const end = src.indexOf('private static function library_length');
  expect(start).toBeGreaterThan(0);
  expect(end).toBeGreaterThan(start);
  const cards = src.slice(start, end);
  // Every item and every track name comes from its approved snapshot; one without is left out.
  expect(cards).toMatch(/\$shown = self::shown\( \$post->ID \);\s+if \( ! \$shown \) \{\s+continue;/);
  expect(cards).toMatch(/if \( '' === \$title \) \{\s+continue;/);
  expect(cards).toMatch(/\$track\s+= self::shown\( \$track_id \);/);
  // The working copy's title is never read: not the post's own, and not a track's.
  expect(cards).not.toMatch(/post_title|get_the_title/);
  // Safety still filters each item.
  expect(cards).toMatch(/LLD_Members_Safety::allows\( \$member_id, \$shown \)/);
  // The no-match line is announced, and the active chip is readable in Day and Night (ink on card, both remapped).
  const css = readFileSync(file('assets/stage3-forms.css'), 'utf8');
  expect(css).toMatch(/\.lld-chip-filter\.is-active \{\s+background: var\(--lld-ink, #1f2a37\);\s+border-color: var\(--lld-ink, #1f2a37\);\s+color: var\(--lld-card, #fff\);/);
  expect(css).not.toMatch(/background: currentColor/);
  expect(src).toMatch(/role="status" aria-live="polite" data-lld-library-status data-lld-library-none="No matching items\."/);
});

test('P3-36 C2: app connections and their Withdraw are on Sign-in & security, and the long page\'s guards are kept', () => {
  const hubFile = 'includes/stage3/screens/class-account-hub.php';
  const html = run(
    `class LLD_Members_Quality { static function app_keys_html($id) { return '<section data-lld-app-keys data-member="' . $id . '"><h2>App connections</h2></section>'; } }
    $m = new ReflectionMethod('LLD_Members_Account_Hub', 'section_security');
    $m->setAccessible(true);
    return $m->invoke(null, (object) ['id' => 7, 'role' => 'member', 'two_step' => '0']);`,
    [hubFile],
  );
  expect(html).toContain('<section data-lld-app-keys data-member="7"><h2>App connections</h2></section>');
  const hub = readFileSync(file(hubFile), 'utf8');
  // /members/settings/?lld_app=withdrawn (the Stage 2 redirect after Withdraw) opens Sign-in & security, where the note shows.
  expect(hub).toMatch(/\$default = isset\( \$_GET\['lld_app'\] \) \? 'security' : 'profile';/);
  // A photo above post_max_size arrives empty: the hub says so with the long form's sentence.
  expect(hub).toMatch(/if \( self::post_too_big\(\) \) \{[\s\S]{0,200}\$error = 'Photos must be 10 MB or smaller\.';/);
  // Carried over from the long page: Terms of sale, the consent history and the joining date.
  expect(hub).toMatch(/'sale'\s+=> 'Terms of sale'/);
  expect(hub).toMatch(/<summary>Consent history<\/summary>/);
  expect(hub).toMatch(/<p>Joined on ' \. esc_html\( \(string\) \$row->joined_on \)/);
  expect(hub).not.toMatch(/lld_email_display/);
});
