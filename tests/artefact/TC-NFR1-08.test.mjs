// TC-NFR1-08 Backup and restore (DEC-19) — the ops assertions, checked on the records the drill writes
// (`node tools/release/drill.mjs restore staging` → evidence/NFR1-08/artefacts/drill-<backup-id>.json, and
// `drill.mjs history <env>` → backup-history-<env>-<stamp>.json). Fixtures: FX-PR-NRM-01 and FX-DM-QD-01 (both tools'
// happy path on the restored site, tests/e2e/TC-NFR1-08.spec.mjs inside the drill) and FX-MAIL-01 (posted by the drill
// into the mail sink).
// AS-01 the backup holds a database dump, wp-content and public_html/tools, with a SHA-256 manifest and inventory;
// AS-02 clean restore: tool hashes = manifest, 10 posts 200, FX-PR-NRM-01 and FX-DM-QD-01 pass, FX-MAIL-01 delivered;
// AS-03 restore duration < 4 h; AS-04 the retention configured in tools/release equals DEC-19 (and the pruning rule keeps
// 14 daily + 8 weekly); AS-05 backup timestamps over the last 7 days, max gap ≤ 24 h. AS-05 needs seven days of scheduled
// backups: until the history covers seven days it is skipped with that reason (LLD_STRICT_GATES=1 makes it fail).
import { describe, expect, test } from 'vitest';
import { readFileSync, mkdtempSync, mkdirSync, writeFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { ROOT } from '../lib/paths.mjs';
import { records } from '../lib/drill-records.mjs';

const strict = process.env.LLD_STRICT_GATES === '1';
const drill = records('NFR1-08', 'drill-')[0];
const HEX64 = /^[0-9a-f]{64}$/;
const python = () => ['python3', 'python'].find((c) => spawnSync(c, ['-c', 'import sys; sys.exit(0 if sys.version_info >= (3, 6) else 1)']).status === 0);

/** DEC-19 numbers from the register row itself */
function dec19() {
  const row = readFileSync(resolve(ROOT, 'docs', '05_Decision_Register_v1_2.md'), 'utf8').split('\n').find((l) => l.startsWith('| DEC-19 |'));
  const n = (re) => Number(row.match(re)?.[1]);
  return { rpo_hours: n(/RPO (\d+) h/), rto_hours: n(/RTO (\d+) h/), retain: { daily: n(/retain (\d+) daily/), weekly: n(/(\d+) weekly/) } };
}

describe('TC-NFR1-08 backup and restore', () => {
  test('TC-NFR1-08-AS-01', () => {
    expect(drill, 'a restore drill record exists in evidence/NFR1-08/artefacts').toBeTruthy();
    const b = drill.backup;
    expect(Object.keys(b.contents).sort()).toEqual(['database', 'inventory', 'public_html/tools', 'wp-content']);
    for (const name of [b.contents.database, b.contents['wp-content'], b.contents['public_html/tools'], b.contents.inventory]) {
      expect(b.archives[name]?.sha256, `${name} has a SHA-256 in the manifest`).toMatch(HEX64);
      expect(b.archives[name].bytes, `${name} is not empty`).toBeGreaterThan(0);
    }
    expect(b.inventory.files, 'per-file inventory').toBeGreaterThan(1000);
    expect(b.inventory.sha256).toMatch(HEX64);
    expect(b.tool_hashes.protein).toMatch(HEX64); expect(b.tool_hashes.diabetes).toMatch(HEX64);
    expect(b.database_tables, 'database dump covers the site tables').toBeGreaterThan(10);
    expect(b.verified, 'backup verified after writing (dump complete, archives readable)').toBe(true);
  });

  test('TC-NFR1-08-AS-02', () => {
    expect(drill).toBeTruthy();
    const c = drill.checks;
    expect(drill.restore.timings.database_imported_s, 'the database was dropped and imported from the dump').toBeGreaterThan(0);
    expect(drill.after_restore.files.equal, 'restored files equal the backup inventory').toBe(true);
    if ('database_differences_volatile_only' in drill.after_restore) expect(drill.after_restore.database_differences_volatile_only, `after restore only cache/queue tables differ (${drill.after_restore.database_differences})`).toBe(true);
    expect(c.tool_hashes_equal_manifest, 'tool hashes on disk = manifest').toBe(true);
    expect(c.served_tool_hashes_equal_manifest, 'tool hashes served = manifest').toBe(true);
    expect(c.posts.length, '10 sample posts').toBe(10);
    expect(c.posts.filter((p) => p.status !== 200), '10 posts return 200').toEqual([]);
    const fxRun = c.browser.tests.find((t) => t.title === 'TC-NFR1-08-AS-02');
    expect(fxRun?.status, 'FX-PR-NRM-01 and FX-DM-QD-01 pass on the restored site (tests/e2e/TC-NFR1-08.spec.mjs)').toBe('passed');
    expect(c.mail.delivered, 'FX-MAIL-01 delivered to the mail sink').toBe(true);
    expect(c.mail.config_restored, 'the sink switch left tools/api/config.php byte-identical').toBe(true);
    expect(c.members_schema_version, 'membership data restored on schema 3').toBe('3');
  });

  test('TC-NFR1-08-AS-03', () => {
    expect(drill).toBeTruthy();
    expect(dec19().rto_hours).toBe(4);
    expect(drill.restore_duration_s, 'restore duration logged').toBeGreaterThan(0);
    expect(drill.restore_duration_s, 'restore duration < RTO 4 h').toBeLessThan(dec19().rto_hours * 3600);
  });

  test('TC-NFR1-08-AS-04', () => {
    const want = dec19();
    expect(want).toEqual({ rpo_hours: 24, rto_hours: 4, retain: { daily: 14, weekly: 8 } });
    const cfg = JSON.parse(readFileSync(resolve(ROOT, 'tools', 'release', 'backup-policy.json'), 'utf8'));
    expect({ rpo_hours: cfg.rpo_hours, rto_hours: cfg.rto_hours, retain: cfg.retain }, 'tools/release/backup-policy.json = DEC-19').toEqual(want);
    const src = readFileSync(resolve(ROOT, 'tools', 'release', 'backup.py'), 'utf8');
    const policy = JSON.parse(src.match(/^POLICY = (\{.*\})/m)[1].replace(/'/g, '"'));
    expect(policy, 'tools/release/backup.py POLICY = DEC-19').toEqual(want);
    if (drill) expect(drill.backup.retention.kept, 'the drill backup ran the retention step').toContain(drill.backup_id);
    // the pruning rule itself: 70 days of backups, two a day → the newest of each of the last 14 days + of each of 8 ISO weeks
    const py = python(); expect(py, 'python 3 available').toBeTruthy();
    const dir = mkdtempSync(join(tmpdir(), 'lld-prune-'));
    const start = Date.UTC(2026, 8, 1);
    for (let i = 0; i < 140; i++) {
      const d = new Date(start + i * 12 * 3600 * 1000).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
      mkdirSync(join(dir, 'full-' + d)); writeFileSync(join(dir, 'full-' + d, 'manifest.json'), '{}');
    }
    const r = spawnSync(py, ['-I', '-B', '-c', `import importlib.util,sys,json; from pathlib import Path
s=importlib.util.spec_from_file_location('b', sys.argv[1]); b=importlib.util.module_from_spec(s); s.loader.exec_module(b)
print(json.dumps(b.prune(Path(sys.argv[2]))))`, resolve(ROOT, 'tools', 'release', 'backup.py'), dir], { encoding: 'utf8' });
    expect(r.status, r.stderr).toBe(0);
    const kept = readdirSync(dir).sort();
    const days = new Set(kept.map((k) => k.slice(5, 13)));
    expect(kept.length, 'one backup per kept day; weekly copies reach back 8 weeks').toBeLessThanOrEqual(want.retain.daily + want.retain.weekly);
    expect(days.size).toBe(kept.length);
    const newest14 = [...new Set(Array.from({ length: 140 }, (_, i) => new Date(start + i * 12 * 3600 * 1000).toISOString().slice(0, 10).replace(/-/g, '')))].slice(-14);
    for (const d of newest14) expect(days.has(d), `daily copy kept for ${d}`).toBe(true);
    const weeks = new Set(kept.map((k) => { const t = new Date(Date.UTC(+k.slice(5, 9), +k.slice(9, 11) - 1, +k.slice(11, 13))); const th = new Date(t); th.setUTCDate(t.getUTCDate() + 3 - ((t.getUTCDay() + 6) % 7)); return th.toISOString().slice(0, 10); }));
    expect(weeks.size, "8 ISO weeks covered").toBe(want.retain.weekly);
    for (const k of kept) expect(k > 'full-20260901', 'no backup older than the window kept').toBe(true);
  });

  test('TC-NFR1-08-AS-05', (ctx) => {
    const hist = records('NFR1-08', 'backup-history-')[0];
    const times = (hist?.backups || []).map((b) => Date.parse(b.created_at)).sort((a, b) => a - b);
    const end = hist ? Date.parse(hist.recorded_at) : Date.now();
    const window = times.filter((t) => t >= end - 7 * 86400e3);
    const covers = window.length > 0 && window[0] - (end - 7 * 86400e3) <= 24 * 3600e3;
    if (!covers && !strict) {
      expect(hist, 'a backup history record exists (drill.mjs history <env>)').toBeTruthy();
      return ctx.skip(`RPO window not yet observable: the backup history (${hist?.file}) starts ${hist?.backups?.[0]?.created_at ?? 'nowhere'}; DEC-19 needs daily backups over 7 days`);
    }
    expect(covers, 'the history reaches back 7 days').toBe(true);
    const gaps = [...window, end].map((t, i, a) => (i ? t - a[i - 1] : window[0] - (end - 7 * 86400e3)));
    expect(Math.max(...gaps) / 3600e3, 'max gap between backups over the last 7 days ≤ 24 h').toBeLessThanOrEqual(dec19().rpo_hours);
  });
});
