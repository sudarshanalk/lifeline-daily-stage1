// Health consent, download and deletion (P3-86, FR3-59 to FR3-61).
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const stage3 = (name) => resolve(plugin, `includes/stage3/${name}`);
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));

const stubs = `
define('ABSPATH', '/');
function add_filter() {}
function apply_filters($h, $v) { return $v; }
class LLD_Members_Access { static $consent = false; static function has_health_consent($m){ return self::$consent; } }
require ${toPhpPath(stage3('class-consent-stage3.php'))};
require ${toPhpPath(stage3('class-export-stage3.php'))};
`;

const php = (code) =>
  execFileSync('php', ['-d', 'display_errors=stderr', '-r', stubs + code], {
    encoding: 'utf8',
    timeout: 120000,
  }).trim();

const run = (code) => JSON.parse(php(`echo json_encode((function(){ ${code} })());`));

test('P3-86 wiring: loader, privacy download filters, Health_Log gate', () => {
  const loader = readFileSync(stage3('loader.php'), 'utf8');
  const privacy = readFileSync(resolve(plugin, 'includes/class-privacy.php'), 'utf8');
  const health = readFileSync(stage3('class-health-log.php'), 'utf8');
  const erasure = readFileSync(stage3('class-erasure-stage3.php'), 'utf8');
  expect(loader).toContain('class-consent-stage3.php');
  expect(loader).toContain('class-export-stage3.php');
  expect(loader).toContain('LLD_Members_Stage3_Export::boot');
  expect(privacy).toContain('lld_members_download_tables');
  expect(privacy).toContain('lld_members_download_rows');
  expect(health).toContain('LLD_Members_Stage3_Consent::gate_store');
  expect(erasure).toContain('lld_members_erase_account');
  expect(erasure).toContain('lld_members_withdraw_health');
});

test('TC-FR3-59-AS-01 / AS-02 without consent: asked and nothing saved', () => {
  const out = run(`
    LLD_Members_Access::$consent = false;
    $before = LLD_Members_Stage3_Consent::before_log(7);
    $gate = LLD_Members_Stage3_Consent::gate_store(7);
    LLD_Members_Access::$consent = true;
    $ok = LLD_Members_Stage3_Consent::gate_store(7);
    return compact('before','gate','ok');
  `);
  expect(out.before.allowed).toBe(false);
  expect(out.before.ask).toMatch(/agree to storing/i);
  expect(out.gate.error).toMatch(/consent/i);
  expect(out.gate.status).toBe(403);
  expect(out.ok).toBeNull();
});

test('Stage 2 download list is unchanged while consent_new is off (NFR3-01)', () => {
  const out = run(`
    class LLD_Members_Features { static function enabled($f, $m = 0){ return false; } }
    return LLD_Members_Stage3_Export::download_tables(['member','consent']);
  `);
  expect(out).toEqual(['member', 'consent']);
});

test('TC-FR3-60 download list includes food and health logs (and older history)', () => {
  const out = run(`
    $base = ['member','consent'];
    $all = LLD_Members_Stage3_Export::download_tables($base);
    return [
      'food' => in_array('food_log', $all, true),
      'readings' => in_array('readings', $all, true),
      'goals' => in_array('goals', $all, true),
      'shares' => in_array('shares', $all, true),
      'suffixes' => LLD_Members_Stage3_Export::download_suffixes(),
    ];
  `);
  expect(out.food).toBe(true);
  expect(out.readings).toBe(true);
  expect(out.goals).toBe(true);
  expect(out.shares).toBe(true);
  expect(out.suffixes).toContain('food_log');
  expect(out.suffixes).toContain('readings');
});

test('TC-FR3-61 account erase and withdraw purge Stage 3 health; shares covered', () => {
  const erasure = readFileSync(stage3('class-erasure-stage3.php'), 'utf8');
  const schema = readFileSync(stage3('class-schema-stage3.php'), 'utf8');
  // Account erase walks every Stage 3 suffix (including shares and food_log).
  expect(erasure).toContain('LLD_Members_Stage3_Schema::suffixes');
  expect(erasure).toContain('purge');
  expect(erasure).toContain('withdraw_health');
  expect(schema).toContain("'shares'");
  expect(schema).toContain("'food_log'");
  expect(schema).toContain("'readings'");
  // health_suffixes covers consent withdrawal (FR3-59 AS-03).
  expect(schema).toMatch(/function health_suffixes[\s\S]*'readings'/);
});
