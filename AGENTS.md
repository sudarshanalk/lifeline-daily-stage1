# Lifeline Daily — guide for coding agents (repository copy)

Shared guide for every AI agent working in this repository: Claude, Cursor, Antigravity, Codex and any other. Read it before any task. It mirrors the project-level `AGENTS.md` in `C:\MyProjects\lifeline-daily\` (which also covers the folders outside the repository) and is updated with it. Last updated 2 November 2026.

Lifeline Daily (lifelinedaily.com) is a health information website on WordPress, with the Newspaper theme and Yoast SEO, hosted by Hostinger. The owner is also the site's clinical and privacy reviewer. The audience is global and English-speaking, so never assume a country.

## The stages

| Stage | What it delivers | Status |
|---|---|---|
| 1. Revamp | The two live calculators (Daily Protein Target, Diabetes Risk Check), homepage, health-tools hub, landing pages, linking and governance | Built and on staging; production release (HG-03) still to come |
| 2. Membership | Accounts, goals, four four-week tracks, Premium through Stripe and Paid Memberships Pro, meal plans, workouts, challenges, food diary, Healthy Weight Check, SEO, AEO and speed | Built, on staging; goes live with Stage 3 |
| 3. Goal journey, member features and launch | Google, Apple and email-code sign-in; a goal with a guided plan; rule-based decision engine; dashboard; full food tracker; health logging; new calculators and tracks; Free and Premium; GA4; the paid launch | **In progress** from 2 Nov 2026 |
| 4. Mobile app | iPhone and Android apps on the same account, data and Premium | Not started |
| 5. Smart features | AI food helpers, health-app imports, languages, support chatbot, AI suggestions | Not started |

The requirements for all stages (v0.3) and the data model (v0.2) live outside the repository in `01 Requirements/`; `docs/stage3/03_Test_Criteria_Stage3.md` is the extract of every Stage 3 check. The project tracker is `Lifeline_Daily_To_Do.md` in the project folder; its task IDs (P3-10 and so on) name the packages.

## Stage 3 in the repository

- **Build method:** `docs/stage3/01_Build_Method_Stage3.md` (packages, order, file scopes, switches, merge rules).
- **Rollback:** `docs/stage3/02_Rollback_Stage3.md`.
- **Stage 3 code:** `site/public_html/wp-content/plugins/lld-members/includes/stage3/` — `class-schema-stage3.php` (tables), `class-features.php` (switches), `class-data.php` (member-scoped, sealed data), `class-api.php` (the members' programming interface, `/wp-json/lld-members/v1/me/<type>`), `class-erasure-stage3.php`, `class-cli-stage3.php`. Screens go in `includes/stage3/screens/`, styles in `assets/stage3.css`.
- **Stage 3 tests:** `tests/stage3/` (Vitest `*.test.mjs`, Playwright `e2e/*.spec.mjs`, key journeys in `key-journeys/`). Guards: `tests/ci/stage3-scope.mjs`, CI jobs `component-scope` and `stage3-guards`.
- **Tool rules:** Cursor `.cursor/rules/stage3.mdc`; Antigravity and Codex follow the build method §4 and their briefs.

Stage 3 rules, on top of the rules below:
- **Never break Stage 2.** Every Stage 3 feature sits behind its own switch (off, test accounts, everyone) and stays off for ordinary members until the owner approves it. The full Stage 2 suite must pass on every Stage 3 pull request (NFR3-01 to NFR3-06).
- **Database changes only add** (NFR3-04). Stage 2 tests cannot be edited, skipped or deleted without a decision in `docs/stage3/stage2-test-changes.json` (NFR3-02).
- **Calculators:** a method sheet is researched and drafted at the start of each build and signed off by the owner before its code is written (NFR3-14). Sheets live in `04 Health Tools/Stage 3/` outside the repository.
- **Decision engine:** rules and wording are signed off by the owner before they run (NFR3-17).
- **Content** (tracks, challenges, messages, safety text) is drafted outside the repository; members see only owner-approved versions.
- **Safety** is never paid for, and the safety matrix applies to every new feature (FR3-69).
- **Analytics:** no Google Analytics in the member area or on calculator results, and no health data in any event (NFR3-16).

## Where things are (inside the repository)

- `site/public_html/` is the only part that is deployed.
- The calculators are in `site/public_html/tools/` (clinically frozen).
- The Stage 1 mu-plugin is `site/public_html/wp-content/mu-plugins/lld-tools/`.
- The Stage 2 plugin is `site/public_html/wp-content/plugins/lld-members/`; Stage 2 tests are in `tests/stage2/`; CI guards are in `tests/ci/` and `.github/workflows/ci-guards.yml`.
- Stage 2 documents: `docs/stage2/01` to `04`. Stage 1: `docs/01` to `05` and `CLAUDE.md`.

## Environments

- **Staging:** https://stg.lifelinedaily.com. Every merge into `staging` deploys automatically (`deploy-staging`: backup, rsync, `wp lld-members db-update`, smoke tests, key journeys). Hidden from search; mail caught; own salts and data key.
- **Production:** https://lifelinedaily.com. Released only through rc tags after the owner's signed approvals. **Never change production without the owner's explicit approval.**
- **Local Stage 2 and 3 site:** http://localhost:8090 (`tools/local/stage2-local.ps1`). Port 8080 is the production snapshot: never test against it.

## Rules every agent follows

- **Read the documents first.** The documents decide; apply recorded defaults instead of asking.
- **Pull request titles name the package:** `S3-Pnn:` for Stage 3; `S2-Cn:`, `S2-Ln:` for Stage 2; `SPEC:` for documents only; `<FR1-nn>:` for Stage 1. `tests/ci/stage2-scope.mjs` and `tests/ci/stage3-scope.mjs` enforce which files each package may touch.
- **Merge only when every check has finished and passed** (about 30). A loop that times out never merges.
- **Never weaken a test.** Test names equal check IDs, for example `TC-FR3-27-AS-02`.
- **The calculators are clinically frozen.** Never change frozen engine blocks, wording or fixture `expected` values without a ratified decision (CI `engine-freeze`, `oracle-guard`). The plugin never replaces the Stage 1 landing pages.
- **Health and personal data:** none real in code, tests or logs; emails never contain health details; content reaches members only after the owner approves that exact version.
- **Secrets:** Stripe, Google, Apple and GA4 keys go only in staging's `wp-config.php` or GitHub secrets, never in the repository, chat or logs. No live keys on staging. Agents never type passwords or keys into web forms and never create accounts.
- **Hostinger quirks:** `wp db query` fails (`proc_open` disabled); use `wp eval`. `wp lld pages` and `wp lld home` with no subcommand apply changes immediately.
- **Line endings:** some files are CRLF; keep each file's existing endings. **Windows:** the shell is Git Bash; use `python`, not `python3`.

## Who does what in Stage 3

| Tool | Work (tracker IDs) |
|---|---|
| **Claude (lead)** | Foundations P3-10 to P3-15 and the members' interface (done first); sign-in P3-20 to P3-22; membership, prices and pause P3-60 to P3-63; decision engine P3-34; analytics P3-70 to P3-72; staging deploys and launch P3-90 to P3-95; joining every tool's branch, full CI, Codex review, merge. Separate Claude sessions: method sheets, safety matrix, engine rules, content drafts |
| **Cursor** | Server-side code in `includes/stage3/`: food P3-40 to P3-43; health logging P3-50; goal, baseline, plan and check-in logic P3-31, P3-32; reminders, consent, export and deletion P3-85, P3-86; calculator engines P3-56 to P3-5B. **Delegated in full from 3 Nov 2026** (P3-31, P3-32, P3-41 to P3-43, P3-50, P3-56 to P3-5B, P3-85, P3-86): Cursor builds, tests, has Codex review, decides when to merge and deploy to staging, and checks staging (build method §7 rule 6) |
| **Antigravity** | Every Stage 3 screen (P3-30 to P3-33, food and logging screens, barcode P3-44, cookie banner, add to home screen), then visual checks at phone and desktop sizes on staging (NFR3-12, NFR3-13; P3-83) |
| **Codex** | Tests from the checks, package by package; the Stage 2 key-journeys set P3-11; P0-03 test updates to ratified decisions; speed, Search Console and direct-answer work P3-80 to P3-82; an independent review of every package before Claude merges it |

Order: Claude's foundations first; then Cursor, Antigravity and Codex in parallel, each in its own worktree and its own files; Claude joins the branches on one branch, runs the full CI, Codex reviews, one pull request is merged when every check has passed. Cursor's delegated packages are the exception: it merges and deploys them to staging itself, when it judges them ready (build method §7 rule 6).
