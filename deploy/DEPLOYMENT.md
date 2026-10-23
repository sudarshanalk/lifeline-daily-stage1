# Deployment — staging and production (brief for the automation set-up)

Scope: automate deployment of **only** `site/` (the deployable overlay) to Hostinger shared hosting, first to staging on every merge to `staging`, then to production from a release tag after the human release gate. WordPress core, themes, normal plugins, uploads and the database are never deployed from Git.

## What gets deployed

`site/public_html/` mirrors the server document root. Today it contains:

| Path | Deployed? | Notes |
|---|---|---|
| `tools/*.html`, `tools/*.php`, `tools/api/*.php`, `tools/api/PHPMailer/`, `tools/api/.htaccess` | yes | hash-frozen tool files and endpoints |
| `tools/api/config.example.php` | yes (as example only) | the real `config.php` lives on the server, created once from environment values; **never** overwritten or deleted by a deploy |
| `tools/api/outbox/`, `tools/api/state/` | `.htaccess` only | contents are runtime state; excluded both ways |
| `tools/status.json` | yes, with care | the withdrawal switch (DEC-10). Deploy copies it only if it changed in Git; an operator-set production value is preserved unless the release explicitly ships a new one (see "status.json rule") |
| `wp-content/mu-plugins/lld-tools/` | yes | the plugin (built in later waves) |
| `wp-content/mu-plugins/lld-tools-loader.php` | yes | mu-plugin loader (later) |
| root `.htaccess` | managed block only | deploy rewrites the text between `# BEGIN lld` and `# END lld`, never the rest of the file |

Excluded from every deploy: `api/config.php`, `api/outbox/*`, `api/state/*`, anything outside `site/`.

## Environments

| | Staging | Production |
|---|---|---|
| Trigger | push to `staging` (after PR merge) | tag `rc-*` pushed **and** `release-gate` check green (`approvals/hg-03.json` with `decision: approved` for that tag) |
| Host / user / key / docroot | secrets `STAGING_SSH_HOST`, `STAGING_SSH_PORT`, `STAGING_SSH_USER`, `STAGING_SSH_KEY`, `STAGING_DOCROOT` | `PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_KEY`, `PROD_DOCROOT` |
| Site URL (for smoke) | `STAGING_URL` | `https://lifelinedaily.com` |
| Approval | none (automatic) | GitHub Environment `production` with a required reviewer = the release owner, plus the `release-gate` job |
| Concurrency | one deploy at a time per environment (`concurrency: deploy-<env>`) | same |

Existing empty placeholders `STAGING_SFTP_HOST/USER/KEY` may be renamed to the `_SSH_` names above or kept; the workflows reference the names in this table. Never put secret values in files, PR text or chat.

## Deploy steps (identical for both environments; `deploy/deploy.sh`)

1. **Pre-flight**: verify the two tool-page hashes in `site/` equal the `version_hash` in `manifests/*.json` and that an approval record with `decision: approved` exists for them (`provenance-check`); abort otherwise. Production additionally requires the tag's release record (`releases/<tag>.json`, SCH-10).
2. **Backup**: on the server, `tar` the current `tools/` and `wp-content/mu-plugins/lld-tools*` into `~/lld-backups/<timestamp>-<sha>.tgz` (keep last 10). The DB is not touched, so no DB backup is needed for an overlay deploy.
3. **Sync**: `rsync -az --delete-after --exclude-from=deploy/rsync-exclude.txt site/public_html/ user@host:$DOCROOT/` over SSH with the deploy key. `--delete-after` is limited to the directories we own (`tools/`, `wp-content/mu-plugins/lld-tools/`) via `--filter` rules; it must never delete WordPress files.
4. **Managed `.htaccess` block**: replace the `# BEGIN lld … # END lld` block in `$DOCROOT/.htaccess` with `site/public_html/.htaccess.lld` content (headers, CSP, cache-control, `status.json` allow rule). Create the block if absent; leave everything else untouched.
5. **status.json rule**: if the server file exists and differs from Git, keep the server file and print a warning, unless the release record has `ships_status: true`.
6. **Cache purge**: `wp litespeed-purge all` via WP-CLI over SSH if WP-CLI is available on the host; otherwise call the LiteSpeed purge endpoint documented in the runbook. Without a purge the withdrawal switch can be invisible for up to 7 days (TTL 604800 s).
7. **Smoke**: HTTP 200 on `/health-tools/`, both landing pages, both `/tools/*.html`; `/tools/api/config.php` → 403; `/tools/status.json` → 200 with `Cache-Control: no-store`; both tool hashes served equal the manifests; `POST /tools/submit.php` with an invalid body → 400 (no mail sent).
8. **Record**: append `deploy/log/<env>.jsonl` with timestamp, sha/tag, hashes, smoke result; on production also update `releases/<tag>.json.deployed_at`.
9. **Rollback**: `deploy/rollback.sh <env> <timestamp>` restores the backup tarball from step 2 and re-runs steps 4, 6, 7. Target: under 5 minutes.

## Repository conventions (GitHub Free)
Branch protection and tag rulesets are not available on the current plan; the owner accepted this on 22 Oct 2026. Conventions: feature branches `req/<ID>` → PR into `staging`; no force-push to `staging`/`production`; `rc-*` tags are never moved; CI results are inspected before merge; the `production` Environment's required reviewer is the enforced gate.

## Non-negotiables

- CI never holds production write credentials except inside the `production` GitHub Environment, which requires the release owner's approval per run.
- No step edits WordPress core, theme files, normal plugins, uploads or the database.
- `api/config.php` is created **once per server by a human** from `config.example.php` with values from the hosting control panel; deploys never touch it.
- Production `transport` must be `smtp` and `bcc_owner` empty (DEC-21); the smoke step reads `/tools/status.json` only and cannot see config, so this is checked by the human at HG-03 and recorded in the release record.
- Nothing from `snapshot-production-*/` or `local/` is ever deployed or committed.

## Runbooks

- `deploy/runbooks/runbook-withdrawal.md` — toggling `status.json` on staging and production, purge, verification (FR1-15).
- `deploy/runbooks/runbook-rollback.md` — restoring a backup tarball.

## Status

Draft workflows are in `.github/workflows/` (`ci.yml`, `deploy-staging.yml`, `deploy-production.yml`) and `deploy/deploy.sh` is a working skeleton. They need: secret names created in GitHub, the `production` Environment with a required reviewer, the real host details, and a first manual run against staging. Owner notes on access are in `spec/OWNER_ACTIONS_STAGING.md`.
