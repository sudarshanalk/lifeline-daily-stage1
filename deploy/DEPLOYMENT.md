# LLD overlay deployment

Only site/public_html is prepared and sent. Staging uses pushes to staging; production uses rc-* tags after the signed HG-03 and clinical approvals pass. Both workflows have manual read-only rehearsal. Production is never deployed by this setup task. No database dumps or patches are applied in this file-only phase (TASK_FOR_CODEX scope).

## Activation

GitHub Free private repositories cannot rely on protected environment reviewers. Repository secrets are used; the workflows do not claim an enforced human reviewer gate. Anyone able to modify workflows with write access could change these checks. Owner accepted Free-plan conventions; never put production credentials in GitHub until the owner accepts this specific deployment arrangement and DEC-46 is verified.

STAGING_DEPLOY_ENABLED and PROD_DEPLOY_ENABLED repository variables default to false. Enable staging only after dedicated LLD SSH access, verified host keys, server-owned config/status and a successful rehearsal are ready. Enable production only after signed release approvals, verified production configuration, a successful staging release and owner authorization. Missing/false variables skip delivery, not validation.

Secrets per prefix STAGING / PROD: SSH_HOST, SSH_PORT, SSH_USER, SSH_KEY, SSH_KNOWN_HOSTS, DOCROOT (for example STAGING_SSH_KEY). Staging URL is fixed to https://stg.lifelinedaily.com; production to https://lifelinedaily.com. Document roots are fixed relative to the SSH home: domains/lifelinedaily.com/public_html/stg and domains/lifelinedaily.com/public_html. No cross-project credential reuse. SSH_KNOWN_HOSTS must contain an independently verified host-key line; deployments use StrictHostKeyChecking=yes.

## Safeguards

- Local manifest/approval checks run before SSH. Missing/pending clinical approval is accepted only on staging; rejected or mismatched approval is refused. Production needs an rc tag, matching tool approvals and HG-03 signer/date/tag. The release record commit must be an ancestor with unchanged site/deploy/workflow content up to the tagged commit, allowing the later release-record commit without a self-referential commit hash.
- Source preparation admits only tools and lld-tools mu-plugin paths. config.php and status.json are excluded. Runtime directories deploy their .htaccess only. WordPress core, themes, ordinary plugins, uploads and the database stay server-owned.
- Origins are substituted in a temporary copy. Until FR1-21 supplies an origin placeholder, the prepared staging header adds its own hostname to frame-ancestors. Frozen tool source bytes are unchanged.
- Remote preflight checks fixed real paths, refuses managed symlinks and requires WordPress, rsync, WP-CLI, config.php and status.json. No credentials are printed.
- A private per-environment verified backup is made before writes. A server-side lock prevents overlapping changes. Backups exclude credentials, withdrawal state and runtime data. Last 10 successful-history backups retained only after smoke succeeds.
- Sync has NO --delete at the document root. Obsolete files require explicit reviewed removal; sparse overlays must never delete WordPress files.
- Managed .htaccess block updates are idempotent; other rules are preserved. WP-CLI LiteSpeed purge failures and smoke failures stop the run. Partial deployment is reported as failure; rollback is explicit, not automatic.
- --dry-run performs read-only preflight and rsync rehearsal only. No remote backup, lock, upload, cache purge or HTTP POST occurs.
- Smoke requires all five landing/tool routes plus hub HTML, denied private API paths, valid no-store status JSON, exact served transformed tool hashes and HTTP 400 for invalid submission JSON.

## Server prerequisites still required

Authorize the public key ~/.ssh/lifelinedaily_staging.pub in Hostinger; never send the private key in chat. Confirm host/port/user and paths. Provision staging config.php without live SMTP credentials and install the approved status template separately. Confirm WP-CLI and LiteSpeed purge are available. Staging config must use the test mail destination, not production SMTP. Live config and DEC-46 must be independently verified before enabling production.

## Verification and operation

CI deploy-dry-run uses a disposable localhost SSH server, stub WordPress command and real rsync; verifies no remote file changes or backup creation. Python regression tests cover path protections, header idempotency, backup/rollback, runtime preservation and production approval refusal. This does not prove Hostinger connectivity or real WordPress operation; first staging rehearsal and deployment remain required.

Run locally with documented environment variables: bash deploy/deploy.sh staging --dry-run. Real runs omit --dry-run. Successful runs emit deploy/log/<environment>.jsonl as an Actions artifact. Release records in Git are immutable; workflow success and artifacts provide deployed-at evidence rather than rewriting signed Git release records.

See runbooks/runbook-rollback.md. No automatic database migration is implemented by this phase.
