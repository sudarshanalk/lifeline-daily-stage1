# Lifeline Daily — Stage 3 build method (P3-01)

Version 1.0, 2 November 2026. Kept light on purpose: the requirements (v0.3) and data model (v0.2) in `01 Requirements/` decide what is built; this document says how the work is cut up, who does which piece, where each piece may write, and how it is merged. The project-wide rules are in `AGENTS.md`.

## 1. What Stage 3 must never do

1. **Break Stage 2.** The full Stage 2 suite (`stage2-tests`, three `stage2-e2e` jobs, `saf`) runs on every Stage 3 pull request and must pass (NFR3-01).
2. **Weaken a Stage 2 test.** `stage3-guards` fails any Stage 3 pull request that edits, skips or deletes a file under `tests/stage2/` unless `docs/stage3/stage2-test-changes.json` records an owner decision for that file (NFR3-02).
3. **Change a Stage 2 table.** Stage 3 database changes only add tables and columns. `stage3-guards` reads the schema diff and fails any `DROP`, `MODIFY`, `CHANGE` or `RENAME`, and any line removed from `class-schema.php` (NFR3-04).
4. **Leave its files.** `tests/ci/stage3-scope.mjs` knows which files each package may touch (section 4). A change to one of the declared Stage 2 files is allowed but labels the pull request `lead-review` (NFR3-05).
5. **Show a feature before the owner approves it.** Every Stage 3 feature sits behind a switch (`LLD_Members_Features`), default `off`; `test_accounts` on staging while the owner looks; `everyone` only when the owner says so (NFR3-06).
6. **Touch the frozen calculators or any production setting.** Unchanged from Stages 1 and 2.

## 2. Packages, order and tools

Packages carry the tracker IDs (`Lifeline_Daily_To_Do.md`). Pull request titles are `S3-Pnn: <name>` (for example `S3-P40: food search`), or `S3-Pnn-Pmm:` for one pull request that joins several of one tool's packages.

| Order | Package | Tool | What it holds |
|---|---|---|---|
| 1 | P3-10 to P3-15 | Claude | Foundations: guards, switches, schema, rollback, the members' programming interface, the erasure checker. **This is the first pull request; nothing else starts before it is merged.** |
| 2 | P3-11 | Codex | The Stage 2 key-journeys test set (`tests/stage3/key-journeys/`), run in CI and after each staging deploy |
| 2 | P3-30 to P3-33 | Antigravity (screens) with Cursor (logic P3-31, P3-32) | Account hub, goal journey, Today, progress, dashboard |
| 2 | P3-40 to P3-44 | Cursor (P3-40 to P3-43), Antigravity (screens, P3-44) | Food tracker |
| 2 | P3-50, P3-51 | Cursor | Health logging; urgent-care levels only after sign-off |
| 2 | P3-85, P3-86 | Cursor | Reminders, sharing, consent, download, deletion |
| 3 | P3-20 to P3-22 | Claude | Sign-in (needs the owner's Google and Apple settings) |
| 3 | P3-55 to P3-5B | Cursor, after each method sheet is signed off (NFR3-14) | Calculators |
| 3 | P3-34 | Claude, after P3-02 is signed off | Decision engine |
| 3 | P3-60 to P3-63 | Claude | Prices, access, membership card, pause (P3-60 needs the Stripe test keys) |
| 3 | P3-70 to P3-72 | Claude | GA4, cookie banner, weekly report (needs the GA4 property) |
| 3 | P3-73 to P3-77 | Content session, then `wp lld content load` | Owner-approved content only |
| 4 | P3-80 to P3-83 | Codex (80 to 82), Antigravity (83) | Search, speed, phone layout and accessibility |
| 5 | P3-90 to P3-95 | Claude and owner | Launch |

Order 2 packages run in parallel once the foundations are merged. A package that depends on an owner input (Stripe keys, GA4, Google and Apple settings, a signed method sheet) is built as far as it can be and finished when the input arrives.

## 3. How a package is built

1. One worktree and one branch per package: `git --git-dir=Code/lifeline-daily.git worktree add Code/<name>-wt -b s3/p<nn>-<short-name> origin/staging`.
2. Read the package's requirements (FR3/NFR3 and their TC-… rows) and the data model records it touches before writing anything.
3. Tests are named after their check IDs (`TC-FR3-27-AS-02`). `stage3-guards` checks that every Stage 3 test ID used under `tests/stage3/` is a check in the requirements extract `docs/stage3/03_Test_Criteria_Stage3.md`.
4. New code goes under `site/public_html/wp-content/plugins/lld-members/includes/stage3/` (one class per area, loaded by `stage3/loader.php`), new tests under `tests/stage3/`. Screens use the Stage 2 components (`includes/components.php`) and the Stage 2 stylesheet; new styles go in `assets/stage3.css`.
5. Every member-facing route, screen and interface answer calls, in this order: the feature switch, the safety flags (`LLD_Members_Safety`), access (`LLD_Members_Access`), then the data. A feature that is off behaves exactly as Stage 2 did.
6. Health data goes through `LLD_Members_Stage3_Data` (encrypted with the site data key, scoped to the member) and is never written to logs, emails, analytics or error reports.
7. Database changes go in `stage3/class-schema-stage3.php` only: `create()` for new tables, `ensure_column()` for new columns. Never edit `class-schema.php`'s existing statements.
8. Open the pull request with the template in section 6. Ask questions in the pull request under "Questions for the lead" and carry on with the rest.

## 4. File scopes (enforced by `tests/ci/stage3-scope.mjs`)

| Tool | May write | Never |
|---|---|---|
| Cursor | `…/lld-members/includes/stage3/**` (its packages' classes), `tests/stage3/**` | Screens' markup, Stage 2 classes, `.github/`, `deploy/`, `docs/`, calculators, approvals, manifests, copy |
| Antigravity | `…/lld-members/includes/stage3/screens/**`, `…/lld-members/assets/stage3.css`, `…/lld-members/assets/stage3.js`, `tests/stage3/look/**`, `tests/stage3/e2e/screens/**` | Logic classes, Stage 2 classes, Stage 2 assets, `.github/`, `deploy/`, `docs/` |
| Codex | `tests/stage3/**`, `tests/ci/**`, `.github/workflows/**`, `tools/seo/**` (the P3-80 to P3-82 checking tools, 3 Nov 2026), `tests/stage2/**` only for P0-03 with a recorded decision | Plugin code, `deploy/`, calculators. Because `tests/ci/**` holds the guards that constrain Codex, every change there is lead-reviewed before merge |
| Claude (lead) | Everything the lead owned in Stage 2, plus `includes/stage3/**` for its own packages, `docs/stage3/**`, `deploy/**`, `AGENTS.md`, `.cursor/**` | Calculators, approvals, manifests, copy without an owner decision |

**Declared Stage 2 files** (any tool may touch, but the pull request is labelled `lead-review`): `includes/class-schema.php` (new `ensure_column` lines only), `includes/class-plugin.php` (new hooks and rewrites only), `includes/class-screens.php` (new screen names only), `includes/class-rest.php` (new routes only), `includes/class-erasure.php` (new hooks only), `includes/class-privacy.php` (checker additions only), `includes/class-cli.php` (new commands only), `includes/class-safety.php` (new feature rows only), `assets/lld-members.js` and `assets/lld-members.css` (additions only), `tests/stage2/data-model-map.json` and `tests/stage2/personal-columns.json` (new columns only, with the data model v0.2 as the decision). **Lead-only Stage 2 files:** `includes/class-billing.php` and `includes/class-stripe.php` may change in the lead's own packages (P3-60 to P3-63: real Stripe behind the `stripe_api` switch, prices, pause), labelled `lead-review`; no other tool's package may touch them (`tests/ci/stage3-scope.mjs` `LEAD_STAGE2`). **Stage 2 files opened by an owner decision:** `includes/class-personal.php` for `S3-P03` only (Owner_Questions 93 = B, 3 Nov 2026: the Sleep and Stress screening uses the approved wording file `screening-sleep-and-stress.md`, as the Stage 3 engine does), labelled `lead-review` (`DECISION_STAGE2`).

## 5. Feature switches

**Switches retired (owner, 3 Nov 2026).** The whole system launches together, so every Stage 3 feature is always on: staging has `wp lld-members feature all-on` set, and features added later are on too. New code does not need a switch check or a catalogue entry, and nobody sets switches. The existing `LLD_Members_Features::enabled()` calls stay in place (CI still sets its own states so the Stage 2 suite runs as before); the admin panel (requirements in progress) will bring a proper on/off later. The rest of this section describes the mechanism as built.

`LLD_Members_Features::enabled( $feature, $member_id = 0 )` answers `off`, `test_accounts` or `everyone` from the option `lld_feature_switches` (data model: Feature switch). Test accounts are the members whose emails are listed in the option `lld_test_account_emails`, plus the owner admin. On staging:

```
wp lld-members feature list
wp lld-members feature food_tracker test_accounts
wp lld-members feature food_tracker everyone --approved-by="Owner, 14 Nov 2026"
```

The catalogue of switches is in `LLD_Members_Features::catalog()`; a package adds its own feature there in the same pull request. `everyone` is set only on the owner's say-so (P3-92), and the `approved_by` text is recorded with it.

## 6. Pull request template

```
S3-Pnn: <name>

Requirements: FR3-nn … (checks TC-…)
Data model records: …
Feature switch: <name> (off by default)
Declared Stage 2 files touched: none | <list and why>
Tests: <files>; full Stage 2 suite green
Questions for the lead: none | …
```

## 7. Merge rules

1. Claude joins each tool's branches on one integration branch, runs the full CI, asks Codex for an independent review, then merges one pull request into `staging` only when every check (about 30) has finished and passed. A watcher timeout never merges.
2. After the deploy: `curl` checks, `wp lld-members db-update` output, then the key-journeys check (`deploy/key-journeys.sh`).
3. Rollback follows `docs/stage3/02_Rollback_Stage3.md` (files through `deploy/rollback.sh`, database from the pre-deploy backup) and is rehearsed on staging before the first Stage 3 feature is switched to `everyone` (NFR3-07).
4. A calculator's fixtures are first written from its owner-signed method sheet (NFR3-14): a **new** file under `fixtures/<tool>/` for a Stage 3 tool may land in an `S3-P` pull request by the lead; every later change to an `expected` value needs the oracle runner or a reviewer-signed commit, as in Stage 1 (`tests/ci/oracle-guard.mjs`). The approval record goes in `approvals/stage3/<tool>.json` and the Stage 2 fixture folders keep the Stage 1 rule in full.
5. The calculator engines (P3-55 to P3-5B) were Cursor's in §2; from 3 November 2026 the lead builds any engine Cursor has not started, from the signed sheet, with the same oracle and fixture rules (`tests/ci/stage3-scope.mjs` lists both tools for those packages). A fixture's `expected` value may change only to the value the tool's own oracle derives (its `results.json` carries the committed script's hash, the script is new or unchanged against the base, and CI re-runs it: `tests/ci/oracle-rerun.py`), recorded as an owner decision.
6. **Withdrawn by the owner on 14 November 2026:** no tool merges or deploys its own work. Every package, including the delegated ones, opens a pull request, runs builder, reviewer and checker inside the tool at most twice, then goes to Claude, who reviews it and merges only when every check has finished and passed (member area build list P3-36).
   > Withdrawn, kept for the record only: ~~Delegated to Cursor in full (owner, 3 November 2026): P3-31, P3-32 (logic), P3-41 to P3-43, P3-50, P3-56, P3-5B, P3-85 and P3-86 (P3-57 to P3-5A were taken over by the lead on 3 November 2026, before Cursor started them; rule 5). For these Cursor replaces the lead in rules 1, 2 and 5: it opens one pull request per package, has Codex review it, decides itself when to merge and deploy it to staging (the all-checks-passed rule in rule 1 and `AGENTS.md` does not bind these packages), and does the after-deploy checks on staging. It may also run `deploy-staging` by hand (`gh workflow run deploy-staging.yml --ref staging`). P3-31 and P3-32 share a branch with Antigravity's screens only by agreement; otherwise logic merges first, screens follow. Rule 4's fixtures for P3-56 to P3-5B are already on `staging` from the signed sheets, so Cursor builds to them and never edits an `expected` value.~~

## 8. Owner inputs that gate packages

| Input | Gates | Where it goes |
|---|---|---|
| Stripe (UK) test keys | P3-60, P3-90 | staging `wp-config.php` and GitHub secrets only |
| Google Analytics 4 property (measurement ID) | P3-70 | staging `wp-config.php` (`LLD_GA4_ID`) |
| Google sign-in client ID and secret | P3-20 | staging `wp-config.php` |
| Apple Developer account, Services ID, key | P3-20 (web), P4-03 | staging `wp-config.php`; the account itself is also needed for Stage 4 |
| Signed method sheets | P3-55 to P3-5B | `04 Health Tools/Stage 3/` |
| Signed rule catalogue and safety matrix | P3-34, every feature | `04 Health Tools/Stage 3/` |
