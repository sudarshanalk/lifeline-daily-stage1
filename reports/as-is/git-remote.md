# Lifeline Daily Git remote setup status

Date: 22 October 2026
Status: Repository and branches created; owner approved GitHub Free workflow without enforced protections; oracle identity pending.
Task: v1_2/CODEX_TASK_STAGING_AND_GIT.md

## Created and verified

Owner confirmed by the user: sudarshanalk.
Private repository: https://github.com/sudarshanalk/lifeline-daily-stage1
HTTPS Git remote: https://github.com/sudarshanalk/lifeline-daily-stage1.git
Default branch: staging
Additional branch: production

Both branches point to bootstrap commit:
b72ea966951ca24cfa3657b084f8e2edf884804e

The bootstrap contains GitHub's initial README only, not production website content. Git requires a commit before branch refs can exist. git ls-remote successfully listed both staging and production at the hash above. Repository API readback confirmed private=true and default_branch=staging.

No local build repository was initialized and no Claude Wave 0 files were pushed or overwritten. The build directory currently contains the setup reports. Claude should base Wave 0 on the remote bootstrap history and submit a PR rather than replace the remote history or force-push.

## Owner-approved GitHub Free workflow

On 22 October 2026 the owner instructed: "use the existing git hub features (no pro version)". This supersedes the task brief's requirement for enforced private branch and tag protection. Keep the repository private on the current plan; no upgrade or purchase is required for the agreed workflow.

The earlier protection request returned HTTP 403 with an upgrade requirement. API readback confirmed staging and production are unprotected. The rulesets API also returned HTTP 403; no rc-* tag ruleset exists. These are accepted limitations, not pending upgrade tasks.

Use the available repository, branch, pull-request and Actions features as follows:
- Develop on feature branches and submit pull requests to staging.
- Inspect real CI results before merging; fix failed checks first. Checks are workflow conventions, not enforced merge requirements.
- Obtain review where another reviewer is available. Do not represent self-review as independent approval.
- Promote reviewed staging changes to production through a pull request after applicable human release gates are satisfied.
- Do not force-push shared branches, delete staging or production, or move/delete published rc-* tags. GitHub does not enforce these conventions on this private repository's current plan.
- Keep clinical/release human gates separate from ordinary development checks to avoid the reviewed approval deadlock.
- Keep write access and automation permissions limited to those needed. A branch named production does not deploy the live site by itself; no deployment connection has been configured by this setup.

Claude's Wave 0 implementation should supply the real checks with these exact status contexts:
validate-artefacts, registry-integrity, provenance-check, oracle-guard, engine-freeze, unit, php-integration, e2e, a11y, security, perf, crawl, sentinel, assertion-map, component-scope.

The checks have not been implemented by this setup. Do not add dummy passing checks. Collaborators and automation with sufficient access can bypass the PR/check conventions; the owner has accepted use of the current plan after this limitation was explained.

## Secret status

The four empty placeholders requested in the brief were successfully created with zero-byte input and their names verified through gh secret list. They contain no working credentials.

| Name | Status |
|---|---|
| STAGING_SFTP_HOST | Created empty; actual value pending |
| STAGING_SFTP_USER | Created empty; actual value pending |
| STAGING_SFTP_KEY | Created empty; actual value pending |
| PLAUSIBLE_API_KEY | Created empty; actual value pending |
| ORACLE_RUNNER_TOKEN | Not created; dedicated identity/token pending |

This confirms GitHub CLI accepted empty placeholders. Workflows must treat them as unconfigured and fail closed until actual values are entered securely.

A token named lld-oracle-runner is not a distinct GitHub actor. A PAT authenticates as the account that issued it. Choose a dedicated machine account or GitHub App for the CI oracle identity, grant only necessary repository permissions, and enter its credential directly into repository secrets. Do not put token values in chat, files, reports or command arguments. The requested contents-write scope may need reassessment if that identity is also expected to open PRs.

## Remaining owner actions

1. No account upgrade is needed. Claude can proceed with Wave 0 using the agreed PR and CI workflow above.
2. Choose/provision the oracle identity and securely populate ORACLE_RUNNER_TOKEN.
3. Supply staging deployment values through the password manager, SSH agent or GitHub secrets UI after staging exists.
4. Populate PLAUSIBLE_API_KEY when the analytics account is ready.

## Acceptance status

- Private repository: PASS.
- staging default branch: PASS.
- production branch at common bootstrap: PASS.
- git ls-remote lists both branches: PASS.
- Four requested empty placeholders: PASS, not usable credentials.
- Branch protection and tag protection: OMITTED by explicit owner decision; PR/check/tag conventions apply.
- Oracle runner identity/token: PENDING.
- Overall Git deliverable: Repository foundation ready under the revised scope; oracle identity and working secret values remain pending.

References:
https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches
https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets

