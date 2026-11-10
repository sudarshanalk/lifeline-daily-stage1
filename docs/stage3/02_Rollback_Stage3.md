# Stage 3 rollback (P3-13, NFR3-07)

Target: the previous version back on staging, with the key journeys passing, in under 15 minutes. Rehearsed on staging before the first Stage 3 feature is switched to `everyone`; the rehearsal's measured time is recorded at the end of this file.

## What a Stage 3 deploy changes

1. **Files** under the managed overlay (`site/public_html/`): `deploy/deploy.sh` backs them up to `~/lld-backups/<env>/<id>.tgz` before each deploy.
2. **Database:** `wp lld-members db-update` only adds tables and columns (NFR3-04). Stage 2 tables are never changed, so the previous plugin version runs against the newer database without a database restore.
3. **Feature switches** (`lld_feature_switches`): data, not code. They survive a rollback.

## The fast path (files only, about 5 minutes)

Because every Stage 3 database change is additive and every Stage 3 feature is behind a switch, the quickest rollback is usually one of these, in order:

1. **Switch the feature off** — no deploy at all: `wp lld-members feature <name> off`. Use this first when one feature misbehaves.
   - `content_new` (P3-73 to P3-77) is different in two ways. The 2027 monthly challenges sit behind it: with it still off on 1 January 2027, Stage 2 members see the empty challenge state (no January challenge exists today either), so the owner sets `content_new` to `everyone` before 1 January 2027. And switching it back off after a test account has used Stage 3 content leaves that member's enrolment naming a track `track_id()` no longer finds, so their programme screen shows an empty track until the switch is on again or the enrolment is stopped.
2. **Roll the files back** with the Stage 1 tooling: `bash deploy/rollback.sh staging <backup-id>` (the id is in the failed deploy's output; `deploy/runbooks/runbook-rollback.md` has the detail). It restores the managed files, purges LiteSpeed and runs the smoke checks. The database is untouched, and the previous plugin ignores the extra Stage 3 tables.
3. Run `bash deploy/key-journeys.sh https://stg.lifelinedaily.com` to confirm the Stage 2 key journeys answer.

## The full path (files and database, about 12 minutes)

Only when a Stage 3 change has written bad data into a Stage 2 table (it should never alter one):

1. Take a fresh backup first: `ssh … "cd domains/stg.lifelinedaily.com/public_html && wp db export ~/lld-backups/staging/pre-rollback-$(date -u +%Y%m%dT%H%M%SZ).sql"`.
2. Restore the pre-deploy database copy taken before the change (every database change on staging is preceded by a backup into `~/lld-backups/staging/`, per `CLAUDE.md`): `wp db import ~/lld-backups/staging/<file>.sql`. (`wp db query` fails on Hostinger; `wp db import` and `wp db export` work.)
3. Roll the files back as in the fast path, step 2.
4. Clear LiteSpeed's page cache with the one-off `X-LiteSpeed-Purge: *` response (deleted straight after), as in `CLAUDE.md`.
5. Run the smoke checks and `deploy/key-journeys.sh`.

Members who signed up between the backup and the restore lose their accounts in the full path; staging has only test members, and production is never rolled back this way without the owner's say-so.

## Rehearsals

| Date | Path | Measured time | Key journeys after | Notes |
|---|---|---|---|---|
| 3 Nov 2026 | fast | 36 s (restore and purge 13 s; smoke and key journeys by 36 s) | All passed | TC-NFR3-07-AS-01. Backup `20261103T061609Z-5e1226262d23` from the foundations deploy (#161). `rollback.sh` removed the Stage 3 files, kept the database (Stage 3 tables and the schema option stayed) and the Stage 2 pages answered unchanged; the `feature` CLI command and `/me/<type>` routes disappeared as expected. Stage 3 restored by re-running the `deploy-staging` run. On Windows, `smoke.sh` needs `python3` on the PATH (a shim to `python`). |
