// Stage 3 screens loader (P3-30 onwards): each class-<name>.php under includes/stage3/screens/ is required and its
// LLD_Members_<Name>::boot() called by convention, so Antigravity's screen packages need no edit to the lead's loader.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const stage3 = resolve(here, '../../site/public_html/wp-content/plugins/lld-members/includes/stage3');
const php = (code) => execFileSync('php', ['-d', 'display_errors=stderr', '-r', code], { encoding: 'utf8', timeout: 60000 }).trim();
const p = (x) => JSON.stringify(x.split('\\').join('/'));

test('screens loader: every class-<name>.php is required and its LLD_Members_<Name>::boot() called, nothing else', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lld-screens-'));
  try {
    const screens = join(dir, 'screens');
    mkdirSync(screens);
    copyFileSync(join(stage3, 'screens', 'screens.php'), join(screens, 'screens.php'));
    writeFileSync(join(screens, 'class-account-hub.php'), "<?php class LLD_Members_Account_Hub { static function boot() { $GLOBALS['booted'][] = 'hub'; } }\n");
    writeFileSync(join(screens, 'class-goal-setting.php'), "<?php class LLD_Members_Goal_Setting { static function boot() { $GLOBALS['booted'][] = 'goal'; } }\n");
    writeFileSync(join(screens, 'class-no-boot.php'), "<?php class LLD_Members_No_Boot {}\n"); // no boot(): skipped, no error
    writeFileSync(join(screens, 'helper.php'), "<?php $GLOBALS['booted'][] = 'helper-loaded';\n"); // not class-*.php: never loaded
    const out = php(`define('ABSPATH', '/'); $GLOBALS['booted'] = []; require ${p(join(screens, 'screens.php'))};
      lld_members_stage3_screens_boot(); lld_members_stage3_screens_boot();
      echo json_encode(['classes' => lld_members_stage3_screens(), 'booted' => $GLOBALS['booted']]);`);
    const r = JSON.parse(out);
    expect(r.classes).toEqual(['LLD_Members_Account_Hub', 'LLD_Members_Goal_Setting', 'LLD_Members_No_Boot']);
    expect(r.booted).toEqual(['hub', 'goal', 'hub', 'goal']); // boot() runs per call; the files are required once
  } finally { rmSync(dir, { recursive: true, force: true }); }
  // The real folder: today it holds the account hub (P3-30) only. Its boot() hooks nothing while the switch is off.
  expect(php(`define('ABSPATH', '/'); class LLD_Members_Features { static function state($f) { return 'off'; } }
    function add_action() { echo 'HOOKED'; } require ${p(join(stage3, 'screens', 'screens.php'))};
    lld_members_stage3_screens_boot(); echo json_encode(lld_members_stage3_screens());`)).toBe('["LLD_Members_Account_Hub"]');
  // The lead's loader requires it and calls it right after the switches boot, and still registers no route itself.
  const loader = readFileSync(join(stage3, 'loader.php'), 'utf8');
  expect(loader).toMatch(/require_once __DIR__ \. '\/screens\/screens\.php';/);
  expect(loader.indexOf('LLD_Members_Features::boot();')).toBeLessThan(loader.indexOf('lld_members_stage3_screens_boot();'));
  expect(loader).not.toMatch(/add_rewrite_rule|template_redirect|add_shortcode/);
});
