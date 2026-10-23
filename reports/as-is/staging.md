# Lifeline Daily staging setup status

Date: 22 October 2026
Status: User confirmed Hostinger is signed in and no staging clone exists. Browser runtime is still unavailable, so no staging clone has been created or verified by Codex.
Task: v1_2/CODEX_TASK_STAGING_AND_GIT.md

## Verified locally

The project initially had no build directory and was not a Git working tree. This reports directory was created solely for setup status; no competing repository was initialized.
Browser automation failed before connecting with MXC CreateProcessSecurityEnvironment HRESULT 0x80070003. A fresh retry after the user confirmed the signed-in Hostinger browser failed with the same error. This is a local tool/runtime failure, not a Hostinger authentication result.

Next route: restore the browser-control runtime (for example, restart Codex and retry this chat), or have the owner create stage1 via Websites > Manage > WordPress > Staging and provide the assigned URL plus a configured staging SSH/SFTP connection. Do not use Hostinger Publish to push staging back to production.

## Required access and choices

- Authenticated Hostinger hPanel access for lifelinedaily.com, with permission to create stage1 through WordPress Staging; verify that the hosting plan supports staging.
- If browser access remains unavailable: owner-created staging clone and a configured SSH/SFTP connection with non-secret host, port, username and staging document-root path. Use the password manager or SSH agent for secrets, not chat or repository files.
- Owner's password manager choice for the staging admin login.
- Reachable sandbox SMTP service and the method Hostinger supports for providing LLD_SMTP_HOST, LLD_SMTP_PORT, LLD_SMTP_USER and LLD_SMTP_PASS outside the repository. A local Mailpit container is not automatically reachable from shared hosting.
- Read-only production tools access, or a sanitized snapshot route. A raw config.php may contain production secrets: inspect/redact before any snapshot is written into build/vendor/live-snapshot. Do not describe a redacted file's hash as the original byte hash.

## Execution checklist

1. Create stage1 through Hostinger's staging feature. Record assigned URL and filesystem root; save admin credentials only in owner's password manager.
2. Disable search indexing on staging and verify robots.txt disallows all. Inspect inherited mail configuration before exercising any form.
3. Export actual plugin names, versions and statuses to wp-plugins-staging.json. Do not fabricate an empty inventory.
4. Record actual LiteSpeed page-cache, lazy-load and exclusions in litespeed-settings.md.
5. Record actual CookieYes categories, defaults and banner wording in cookieyes-settings.md.
6. Verify both tool HTML files, submit.php, submit-protein.php and api/ exist in the staging tools directory. Copy from production read-only only if missing.
7. Remove production credentials from staging tool mail configuration; configure reachable sandbox SMTP through supported environment/host settings. Fail closed when sandbox settings are absent.
8. Verify homepage, both landing pages and direct tool URLs load, and any mail test reaches only the sandbox.
9. Record staging differences and redact any sensitive fields in reports.

## Deliverable status

- Staging URL: not assigned or verified.
- Hostinger staging clone: pending.
- Indexing/robots checks: pending.
- Plugin inventory: pending.
- LiteSpeed settings: pending.
- CookieYes settings: pending.
- Tool-directory parity: pending.
- Sandbox SMTP: pending.
- Staging SSH/SFTP: pending.
- Production read-only snapshot and hashes: pending.
- Production writes: none performed.

Hostinger documentation: https://support.hostinger.com/en/articles/5720286-how-to-create-a-wordpress-staging-environment
