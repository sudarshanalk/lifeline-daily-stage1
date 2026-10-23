# Codex task: set up automatic deployment to staging and production

Repository: `C:\MyProjects\lifeline-daily\lifeline-daily-stage1` (GitHub `sudarshanalk/lifeline-daily-stage1`, default branch `staging`). Read `deploy/DEPLOYMENT.md` first; it is the specification. This file is the work list. Delete this file in your final PR.

## Facts you need
- Only `site/public_html/` is deployed, by `deploy/deploy.sh` over SSH/rsync. WordPress itself is never deployed.
- `tools/status.json`, `tools/api/config.php`, `api/outbox/`, `api/state/` are never written by a deploy (`deploy/rsync-exclude.txt`).
- Tool HTML and `.htaccess.lld` contain the placeholder `__LLD_ORIGIN__`, substituted from `DEPLOY_ORIGIN` at deploy time (the placeholder is not yet in the HTML; FR1-21 adds it. Until then substitution is a no-op).
- GitHub Free: no branch protection. The enforced production gate is the `production` GitHub Environment with a required reviewer, plus the `release-gate` job.
- Owner has signed HG-01 (`approvals/hg-01.json`). Production config verification (DEC-46) is still open and must be confirmed before the first production deploy.
- Hostinger shared hosting; SSH key pair for staging was generated on the build machine (`~/.ssh/lifelinedaily_staging`, public key must be added in hPanel by the owner). Staging URL and SSH details are owner inputs OI-1 in `docs/05_Decision_Register_v1_2.md`; if they are not yet available, do everything that does not need them and stop with a clear list.

## Work list
1. **Secrets and environments** (`gh` CLI, already authenticated): create empty-then-filled secrets `STAGING_SSH_HOST`, `STAGING_SSH_PORT`, `STAGING_SSH_USER`, `STAGING_SSH_KEY`, `STAGING_DOCROOT`, `STAGING_URL`, and `PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_KEY`, `PROD_DOCROOT`. Remove the older `STAGING_SFTP_*` placeholders. Create GitHub Environments `staging` (no reviewers) and `production` (required reviewer: the repository owner). Never put a secret value in a file, PR, log or chat.
2. **Workflows**: finalise `.github/workflows/deploy-staging.yml` and `deploy-production.yml` (currently marked DRAFT): install `rsync` on the runner, write the key from the secret, run `deploy/deploy.sh`, upload `deploy/log/*.jsonl` as an artefact, fail the job on smoke failure. Add `workflow_dispatch` with a `dry_run` input that passes `--dry-run`.
3. **deploy.sh hardening**: make the pre-flight approval check accept `clinical_signoff.decision: pending` for `staging` and require `approved` for `production` (DEC: approval records exist as pending from wave 1). Make the `.htaccess` managed-block step idempotent and tested with a local fixture file. Make `smoke.sh` return non-zero on any FAIL (already) and print the served vs expected hash on mismatch.
4. **Rollback**: implement `deploy/rollback.sh <env> <backup-name>` per `deploy/runbooks/runbook-rollback.md`.
5. **First staging run**: once OI-1 is available, run `deploy/deploy.sh staging --dry-run`, then a real run via `workflow_dispatch`, then `deploy/smoke.sh <staging url>`. Record the result in `deploy/log/staging.jsonl` and in the PR.
6. **CI**: in `.github/workflows/ci.yml`, keep the existing jobs and add a `deploy-dry-run` job on pull requests that runs `deploy/deploy.sh staging --dry-run` against a throwaway local SSH target (Docker `linuxserver/openssh-server` or an `ssh` to `localhost` on the runner) so the script is exercised on every PR without touching Hostinger.
7. **PR**: one pull request `req/NFR1-09: deployment automation` into `staging` with: what was configured (names only), the first staging run result, and what remains for production (DEC-46 verification, `production` environment reviewer confirmation).

## Do not
- Do not deploy to production. Do not touch WordPress files, the database, `config.php`, `status.json`.
- Do not edit `docs/`, `registry/`, `schemas/`, `fixtures/`, `site/public_html/tools/*.html` or any PHP under `site/`.
- Do not create new handover documents; report in the PR description.
