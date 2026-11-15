// P3-36 B2 follow-ups on the Log a reading page (/members/readings/), from the lead's review of #267:
// vigorous minutes count double in the weekly total the interface returns (as the engine counts them, FR3-41);
// readings are shown, defaulted and grouped on the member's own clock while they stay stored in UTC; and a reading
// is deleted only after the member confirms. Test values are made up; nothing here is a real person's data.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const plugin = resolve(here, '../../site/public_html/wp-content/plugins/lld-members');
const p = (x) => JSON.stringify(resolve(plugin, x).split('\\').join('/'));
const logging = readFileSync(resolve(plugin, 'assets/stage3-logging.js'), 'utf8');
const js = logging.slice(logging.indexOf('function rdEsc('), logging.indexOf('function boot()'));
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', `define('ABSPATH', '/'); ${code}`], { encoding: 'utf8', timeout: 60000 }).trim();

// The health log with a member in a set zone and a set UTC clock, and readings held in memory.
const healthLog = (zone, rows = [], now = '2026-11-09 10:00:00') => `
  $GLOBALS['ROWS'] = json_decode(base64_decode('${Buffer.from(JSON.stringify(rows)).toString('base64')}'), true);
  function lld_members_now_mysql($id = 0) { return '${now}'; }
  class LLD_Members_Programme { static function timezone($id) { return '${zone}'; } }
  class LLD_Members_Food_Log { static function premium($id) { return true; } }
  class LLD_Members_Stage3_Data { static function rows($t, $id) { return 'readings' === $t ? $GLOBALS['ROWS'] : array(); } }
  require ${p('includes/stage3/class-health-log.php')};`;

test('TC-FR3-41-AS-01 vigorous minutes count double toward the weekly 150, the same total the engine counts', () => {
  const rows = [
    { reading_type: 'activity', taken_at: '2026-11-02 09:00:00', value_1: 30, value_3: 3 }, // Hard: counts 60
    { reading_type: 'activity', taken_at: '2026-11-03 09:00:00', value_1: 20, value_3: 2 }, // Moderate: 20
    { reading_type: 'activity', taken_at: '2026-11-04 09:00:00', value_1: 10, value_3: 1 }, // Easy: 10, like moderate
    { reading_type: 'activity', taken_at: '2026-11-04 18:00:00', value_1: 15 }, // not rated: 15
  ];
  const out = JSON.parse(php(`${healthLog('UTC', rows)}
    require ${p('includes/stage3/engine/class-insights-runner.php')};
    $week = LLD_Members_Health_Log::view('activity-week', 7, array('week_start' => '2026-11-02'));
    $engine = 0.0;
    foreach ($GLOBALS['ROWS'] as $r) { $engine += LLD_Members_Insights_Runner::daily_value('activity', array(array('at' => 0, 'v1' => (float) $r['value_1'], 'v2' => null, 'v3' => isset($r['value_3']) ? (float) $r['value_3'] : null))); }
    echo json_encode(array('week' => $week, 'engine' => $engine));`));
  expect(out.week.minutes).toBe(105);
  expect(out.week.minutes).toBe(out.engine);
  expect(out.week.guide).toBe(150);
  expect(out.week.days['2026-11-02']).toBe(60);
  expect(out.week.days['2026-11-03']).toBe(20);
  expect(out.week.days['2026-11-04']).toBe(25);
});

test('TC-FR3-38-AS-03 readings stored in UTC are listed, charted and totalled on the member\'s own calendar day', () => {
  // Pacific/Auckland is UTC+13 in November: 2026-11-08 20:30 UTC is 09:30 on 2026-11-09 there.
  const rows = [
    { id: 1, reading_type: 'activity', taken_at: '2026-11-08 20:30:00', value_1: 30, value_3: 2 },
    { id: 2, reading_type: 'water', taken_at: '2026-11-08 20:30:00', value_1: 250 },
    { id: 3, reading_type: 'water', taken_at: '2026-11-09 08:00:00', value_1: 500 },
    { id: 4, reading_type: 'weight', taken_at: '2026-11-08 20:30:00', value_1: 80 },
  ];
  const out = JSON.parse(php(`${healthLog('Pacific/Auckland', rows, '2026-11-09 08:30:00')}
    $z = LLD_Members_Health_Log::zone_for(7);
    echo json_encode(array(
      'zone'   => $z->getName(),
      'day'    => LLD_Members_Health_Log::local_date('2026-11-08 20:30:00', $z),
      'utcDay' => LLD_Members_Health_Log::local_date('2026-11-08 20:30:00'),
      'week'   => LLD_Members_Health_Log::view('activity-week', 7, array('week_start' => '2026-11-09')),
      'trend'  => LLD_Members_Health_Log::view('trend', 7, array('type' => 'weight')),
      'list'   => LLD_Members_Health_Log::view('list', 7, array('type' => 'weight', 'from' => '2026-11-09', 'to' => '2026-11-09')),
      'before' => LLD_Members_Health_Log::view('list', 7, array('type' => 'weight', 'to' => '2026-11-08')),
      'water'  => LLD_Members_Health_Log::water_day_ml($GLOBALS['ROWS'], '2026-11-09', $z),
    ));`));
  expect(out.zone).toBe('Pacific/Auckland');
  expect(out.day).toBe('2026-11-09');
  expect(out.utcDay).toBe('2026-11-08'); // without a zone the stored date is used as it is (callers unchanged)
  expect(out.week.days['2026-11-09']).toBe(30); // Monday 9 November in Auckland, not Sunday 8 November in UTC
  expect(out.week.minutes).toBe(30);
  expect(out.trend.points.map((pt) => pt.date)).toEqual(['2026-11-09']);
  expect(out.list.rows.map((r) => r.id)).toEqual([4]);
  expect(out.list.rows[0].taken_at).toBe('2026-11-08 20:30:00'); // stored UTC is returned as it is: no data changes
  expect(out.before.rows).toEqual([]);
  expect(out.water).toBe(750);

  // The page defaults the time to the member's clock and hands the script the zone to show and send times in.
  const page = php(`
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } function esc_attr($t) { return esc_html($t); }
    function esc_url($u) { return (string) $u; } function esc_url_raw($u) { return (string) $u; }
    function home_url($x = '') { return 'https://example.test' . $x; } function rest_url($x = '') { return 'https://example.test/wp-json/' . $x; }
    function wp_create_nonce($a) { return 'nonce'; } function wp_json_encode($v) { return json_encode($v); }
    function add_query_arg($k, $v, $u) { return $u . '?' . $k . '=' . $v; } function sanitize_key($k) { return (string) $k; } function wp_unslash($v) { return $v; }
    function plugins_url($x) { return '/' . $x; } define('LLD_MEMBERS_FILE', __FILE__); define('LLD_MEMBERS_VERSION', 't');
    function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_print_styles() {} function wp_print_scripts() {}
    function lld_members_layout($a) { echo $a['main']; }
    class LLD_Members_Access { static function has_health_consent($id) { return true; } }
    class LLD_Members_Features { static function enabled($f, $id = 0) { return false; } }
    class LLD_Members_Goal_Journey { static function facts($id) { return array('today' => '2026-11-09', 'imperial' => false); } }
    ${healthLog('Pacific/Auckland', [], '2026-11-08 20:30:00')}
    require ${p('includes/stage3/screens/class-readings-screen.php')};
    LLD_Members_Readings_Screen::render(7);`);
  const config = JSON.parse(page.match(/data-lld-readings-config="([^"]*)"/)[1].replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&'));
  expect(config.timezone).toBe('Pacific/Auckland');
  expect(config.nowLocal).toBe('2026-11-09T09:30');
  expect(config.today).toBe('2026-11-09');
  expect(config.weekStart).toBe('2026-11-09');
  expect(config).not.toHaveProperty('utcToday');
  expect(page).toContain('value="2026-11-09T09:30" data-lld-taken-at');

  // The script turns stored UTC into the member's time to show and edit, and the typed local time back into UTC.
  const zone = new Function(`${js.slice(js.indexOf('const RD_AT'), js.indexOf('function rdComputeSleepHours'))}
    return { rdUtcToLocal, rdLocalToUtc, rdDisplayDateTime };`)();
  expect(zone.rdUtcToLocal('2026-11-08 20:30:00', 'Pacific/Auckland')).toBe('2026-11-09 09:30:00');
  expect(zone.rdLocalToUtc('2026-11-09 09:30:00', 'Pacific/Auckland')).toBe('2026-11-08 20:30:00');
  expect(zone.rdLocalToUtc('2026-07-01 08:00:00', 'America/New_York')).toBe('2026-07-01 12:00:00'); // summer time
  expect(zone.rdLocalToUtc('2026-11-09 08:00:00', 'America/New_York')).toBe('2026-11-09 13:00:00');
  expect(zone.rdUtcToLocal('2026-11-09 13:00:00', 'UTC')).toBe('2026-11-09 13:00:00');
  expect(zone.rdDisplayDateTime('2026-11-08 20:30:00', 'Pacific/Auckland')).toBe('2026-11-09 \u00b7 09:30');
  // Every place the page sends a time converts it; every place it shows or groups one reads it on the member's clock.
  const read = js.slice(js.indexOf('function initReadings('));
  expect(read).toContain('built.payload.taken_at = rdLocalToUtc(built.payload.taken_at, cfg.timezone);');
  expect(read).toContain("taken_at: rdLocalToUtc(rdFormatTakenAt(els.takenAt ? els.takenAt.value : '', cfg.today), cfg.timezone),");
  expect(read).toContain('rdDisplayDateTime(r.taken_at, cfg.timezone)');
  expect(read).toContain('rdUtcToLocal(row.taken_at, cfg.timezone)');
  expect(read).not.toContain('utcToday');
});

test('TC-FR3-38-AS-02 deleting a reading asks first, and keeping it sends nothing', async () => {
  const handler = js.slice(js.indexOf("const delBtn = ev.target.closest('[data-lld-delete-row]');"), js.indexOf('syncSleepAuto();\n\t\tswitchType'));
  expect(handler).toContain("window.confirm('Delete this reading?')");
  expect(handler.indexOf('window.confirm(')).toBeLessThan(handler.indexOf("method: 'DELETE'"));
  // Plain UI words only: nothing clinical, legal or about health in the question.
  expect(handler.match(/window\.confirm\('([^']*)'\)/)[1]).toBe('Delete this reading?');

  // Run the handler: cancelling sends no request; confirming sends one DELETE for that reading.
  const run = async (answer) => {
    const calls = [];
    const window = { confirm: () => answer };
    const body = handler.slice(0, handler.lastIndexOf('}'));
    const fn = new Function('ev', 'window', 'rdRequest', 'cfg', 'state', 'els', 'clearFeedback', 'renderServerError', 'cancelEditRow', 'checkLowMoodSignal', 'loadHistory',
      `return (async function () { ${body} })();`);
    const btn = { getAttribute: () => '42' };
    await fn({ target: { closest: (sel) => (sel === '[data-lld-delete-row]' ? btn : null) } }, window,
      async (cfg, path, opts) => { calls.push([path, opts && opts.method]); return { ok: true, data: {} }; },
      {}, { type: 'weight', editingId: null }, { status: null }, () => {}, () => {}, () => {}, async () => {}, async () => {});
    return calls;
  };
  expect(await run(false)).toEqual([]);
  expect(await run(true)).toEqual([['readings/42', 'DELETE']]);
});
