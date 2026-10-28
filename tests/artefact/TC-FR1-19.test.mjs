// TC-FR1-19 Staging and production release (process, hybrid, HG-03) — artefact half. Records come from
// tools/release/drill.mjs (evidence/FR1-19/artefacts/, evidence/NFR1-08/artefacts/) and the release record releases/<tag>.json.
// Fixtures: FX-PR-NRM-01 and FX-DM-QD-01 drive the browser half of the smoke (tests/e2e/TC-FR1-19.spec.mjs).
// AS-01 releases/<tag>.json validates SCH-10; every MUST requirement is at status ≥ verified and is covered by an evidence
//       bundle named in the record whose own assertions (TC-<ID>-AS-nn) are all `pass` with reviewer verdict PASS. Where
//       that is not so, the requirement must be listed in the record's signoffs.evidence_exceptions with the reason and the
//       ratified decision or signed gate it rests on (DEC-84 waived bundles and independent review in simple mode); the list
//       must equal the computed gaps exactly, so nothing is hidden and nothing stale is carried.
// AS-02 the restore drill record is dated within the 7 days before the tag and passed.
// AS-03 release smoke (hub, both landing pages, both tools' happy path, status.json, both mails to the sink) green: the
//       staging run before the tag, then production at +0 h, +1 h and +24 h after the deploy. The production runs exist
//       only after the deploy: until then the assertion is skipped with that reason (LLD_STRICT_GATES=1 makes it fail).
// AS-04 rollback drill on staging: the previous release restored with deploy/rollback.sh, smoke green, duration < RTO.
import { describe, expect, test } from 'vitest';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { validateFile } from '../lib/validate.mjs';
import { records } from '../lib/drill-records.mjs';

const strict = process.env.LLD_STRICT_GATES === '1';
const RANK = ['draft', 'ready', 'in_progress', 'review', 'blocked', 'verified', 'accepted', 'released'];
const relDir = resolve(ROOT, 'releases');
const relFile = existsSync(relDir) ? readdirSync(relDir).filter((f) => /^rc-.*\.json$/.test(f)).sort().pop() : null;
const rel = relFile ? JSON.parse(readFileSync(resolve(relDir, relFile), 'utf8')) : null;
const registry = JSON.parse(readFileSync(resolve(ROOT, 'registry', 'Stage1_Requirements_Registry_v1_2.json'), 'utf8'));
const MUST = registry.requirements.filter((r) => r.priority === 'MUST');
const register = readFileSync(resolve(ROOT, 'docs', '05_Decision_Register_v1_2.md'), 'utf8');
const tagDate = (tag) => Date.parse(tag.match(/^rc-(\d{4}-\d{2}-\d{2})/)[1] + 'T23:59:59Z');
const NO_RELEASE = 'no release record yet: releases/rc-*.json arrives with the release-record PR (CMP-12)';

/** requirement id -> reason its evidence does not meet AS-01, or null */
export function evidenceGaps(release) {
  const gaps = {};
  for (const r of MUST) {
    if (RANK.indexOf(r.status) < RANK.indexOf('verified')) { gaps[r.id] = `status ${r.status}`; continue; }
    const ev = (release.evidence_ids || []).find((id) => id.startsWith(`EV-${r.id}-`));
    if (!ev) { gaps[r.id] = 'no evidence bundle'; continue; }
    const [, d, t] = ev.match(/-(\d{8})-(\d{4})$/);
    const file = resolve(ROOT, 'evidence', r.id, `${d}-${t}.json`);
    if (!existsSync(file)) { gaps[r.id] = `bundle ${ev} missing`; continue; }
    const b = JSON.parse(readFileSync(file, 'utf8'));
    const own = b.results.filter((x) => x.assertion.startsWith(`TC-${r.id}-`));
    const notPass = own.filter((x) => x.outcome !== 'pass').map((x) => `${x.assertion} ${x.outcome}`);
    const absent = r.assertions.filter((a) => !own.some((x) => x.assertion === a));
    const problems = [...notPass, ...absent.map((a) => `${a} absent`), ...(b.reviewer?.verdict === 'PASS' ? [] : ['no reviewer PASS'])];
    if (problems.length) gaps[r.id] = problems.join('; ');
  }
  return gaps;
}

describe('TC-FR1-19 staging and production release', () => {
  test('TC-FR1-19-AS-01', (ctx) => {
    if (!rel) { if (strict) throw new Error(NO_RELEASE); return ctx.skip(NO_RELEASE); }
    const v = validateFile('release-record.schema.json', resolve(relDir, relFile));
    expect(v.ok, v.errors.join('; ')).toBe(true);
    expect(rel.tag, 'record named after its tag').toBe(relFile.replace(/\.json$/, ''));
    expect(MUST.filter((r) => RANK.indexOf(r.status) < RANK.indexOf('verified')).map((r) => `${r.id} ${r.status}`), 'every MUST at least verified').toEqual([]);
    const gaps = evidenceGaps(rel);
    const exceptions = rel.signoffs?.evidence_exceptions || {};
    expect(Object.keys(exceptions).sort(), 'evidence exceptions = the computed gaps, no more and no less').toEqual(Object.keys(gaps).sort());
    for (const [id, x] of Object.entries(exceptions)) {
      expect(x.reason, `${id}: reason`).toBeTruthy();
      expect(x.basis?.length, `${id}: basis`).toBeGreaterThan(0);
      for (const b of x.basis) {
        if (/^DEC-\d{2}$/.test(b)) expect(register, `${id}: ${b} is a row of the register`).toMatch(new RegExp(`^\\| ${b} \\|`, 'm'));
        else if (/^HG-0[1-3]$/.test(b)) {
          const g = JSON.parse(readFileSync(resolve(ROOT, 'approvals', `hg-${b.slice(3)}.json`), 'utf8'));
          expect(g.decision, `${id}: ${b} signed`).toBe('approved');
        } else throw new Error(`${id}: basis ${b} is neither a DEC row nor a human gate`);
      }
    }
    for (const id of rel.approval_ids) expect(existsSync(resolve(ROOT, id)), `approval record ${id} exists`).toBe(true);
  });

  test('TC-FR1-19-AS-02', (ctx) => {
    const drill = records('NFR1-08', 'drill-')[0];
    expect(drill, 'a restore drill record exists').toBeTruthy();
    expect(drill.checks.posts.every((p) => p.status === 200) && drill.checks.mail.delivered && drill.after_restore.files.equal, 'the drill passed').toBe(true);
    if (!rel) { if (strict) throw new Error(NO_RELEASE); return ctx.skip(NO_RELEASE); }
    const age = tagDate(rel.tag) - Date.parse(drill.finished_at);
    expect(age, 'drill is not after the tag date').toBeGreaterThanOrEqual(0);
    expect(age / 86400e3, `restore drill ${drill.backup_id} dated within 7 days of ${rel.tag}`).toBeLessThanOrEqual(7);
    expect(rel.restore_drill?.record, 'the release record names the drill').toBe(drill.file);
  });

  test('TC-FR1-19-AS-03', (ctx) => {
    const ok = (r) => r && r.smoke.ok && r.pages.every((p) => p.status === 200) && r.browser.ok && r.browser.tests.some((t) => t.title === 'TC-FR1-19-AS-03' && t.status === 'passed');
    const stg = records('FR1-19', 'smoke-staging-').find((r) => r.ok);
    expect(ok(stg), 'staging release smoke green before the tag').toBe(true);
    expect(stg.mail.delivered && stg.mail.fixtures.length === 2 && stg.mail.config_restored, 'both mails reached the staging sink').toBe(true);
    const prod = ['0h', '1h', '24h'].map((l) => records('FR1-19', `smoke-production-${l}-`).find((r) => r.ok));
    if (prod.some((r) => !r) && !strict) return ctx.skip('production smoke at +0 h, +1 h and +24 h runs after the deploy (node tools/release/drill.mjs smoke production --label +0h|+1h|+24h)');
    for (const [i, r] of prod.entries()) {
      expect(ok(r), `production smoke ${['+0 h', '+1 h', '+24 h'][i]}`).toBe(true);
      expect(r.mail_confirmed_by, 'both mails confirmed in the seeded inbox by the release owner').toBeTruthy();
    }
  });

  test('TC-FR1-19-AS-04', () => {
    const d = records('FR1-19', 'rollback-drill-')[0];
    expect(d, 'a rollback drill record exists').toBeTruthy();
    expect(d.env).toBe('staging');
    expect(d.previous_release).toMatch(/^[0-9a-f]{40}$/);
    expect(d.previous_release, 'rolled back to an earlier release').not.toBe(d.current_release);
    expect(d.rollback_command).toBe(`bash deploy/rollback.sh staging ${d.backup_id}`);
    expect(d.rollback_exit, 'deploy/rollback.sh succeeded').toBe(0);
    expect(d.smoke_after_rollback.ok, 'smoke green on the previous release').toBe(true);
    expect(d.browser_after_rollback.ok, 'both tools complete on the previous release').toBe(true);
    expect(d.rollback_duration_s, 'duration recorded').toBeGreaterThan(0);
    expect(d.rollback_duration_s, 'duration < RTO (4 h, DEC-19)').toBeLessThan(d.rto_hours * 3600);
    expect(d.roll_forward.exit === 0 && d.roll_forward.smoke.ok, 'staging rolled forward to the current release, smoke green').toBe(true);
  });
});
