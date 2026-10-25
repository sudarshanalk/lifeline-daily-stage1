# Lifeline Daily — Stage 2 Build Method (draft for owner review)

**Who builds what, in what order, how work is handed over, reviewed and deployed — kept simple**

Prepared 25 October 2026. Follows the approved Stage 2 Requirements, Screen Design Specification, Data Model and Test Criteria. Written in plain language.

---

## 1. The three roles

| Role | Who | Does |
|---|---|---|
| **Builder** | **Cursor** (AI coding assistant in the Cursor editor) | Writes the Stage 2 membership plugin: every member screen, email and owner screen, the payments integration, the link to the two calculators, and the automated tests for its work |
| **Lead** | **Claude** (Claude Code) | Prepares the repository and staging; reviews and merges Cursor's work; deploys to staging and runs the staging tests; does the whole search and content workstream; drafts content for the owner to approve; keeps the project tracker current; deploys to production after the owner's approval |
| **Owner** | **The owner** | Decides; approves content, wording, screens on staging and legal pages; supplies accounts and keys; does the owner checks; gives the launch approval |

**Clear boundary.** Cursor and Claude never edit the same files. Section 4 lists exactly which files belong to whom. If either needs a change in the other's files, it asks in the pull request, and the owner of those files makes it.

## 2. Simple process

This is the light process agreed after Stage 1 (decision DEC-84). Specifically:

- **No** per-requirement review agents, evidence bundles, mutation proofs or worktree farms.
- **One work package, one branch, one pull request.**
- **Automated checks are the gate.** A pull request merges only when its tests and the Stage 1 calculator protections pass.
- **Claude reviews each pull request once** against the requirements, screen specification and test criteria, and either merges it or returns it with numbered comments.
- **Owner decisions are made in chat** and recorded in the requirements' decision list. Cursor never invents a decision; it asks in the pull request.

## 3. Phase 0 — Claude prepares before Cursor starts

| No. | Task | Done when |
|---|---|---|
| P0-1 | Add the Stage 2 documents to the repository under `docs/stage2/`, plus the Cursor rules file (`.cursor/rules/stage2.mdc`) | Merged to `staging` |
| P0-2 | Update the automated checks for Stage 2: pull-request titles `S2-Cn:` (Cursor packages), `S2-Ln:` (lead work) and `SPEC:`; Stage 2 test names `TC-FR2-…`, `TC-NFR2-…` and `TC-UI2-…`; file-scope rules from section 4 | A test pull request with each title passes |
| P0-3 | Extend the deploy script so it also sends `site/public_html/wp-content/plugins/lld-members/`, and runs the plugin's database update command after each staging deploy | A staging dry run shows the new path |
| P0-4 | Prepare staging: install Paid Memberships Pro; Stripe **test mode** keys (from the owner); a staging email catcher, so staging never emails real people; the test clock switched on for staging only; and staging kept out of search engines | Each item checked and recorded in the tracker |
| P0-5 | Stage 1 clinical sign-off with the owner, using the interactive sign-off page, then the Stage 1 release to production | Signed, and Stage 1 live (FR2-24) |
| P0-6 | Set up the local environment for Cursor: the existing local WordPress copy (`http://localhost:8080`), Mailpit, test members and test content from the Test Criteria (section 4) | Cursor can run the end-to-end tests locally |

## 4. Who owns which files

| Area | Owner of changes | Path |
|---|---|---|
| **Membership plugin** (new) | **Cursor** | `site/public_html/wp-content/plugins/lld-members/` |
| **Its tests** | **Cursor** | `tests/stage2/` |
| **Shared test tools**: erasure checker, leak scanner, allowlist checker, access and safety runners, signed-out prober | **Cursor** | `tests/stage2/tools/` |
| **Calculator page addition** (FR2-23 only, package C9) | **Cursor**, with extra review | One small new script and its include in the two tool pages. Never the calculation, the wording or anything inside the frozen blocks |
| Search and content code: article additions (SCR-43, SCR-44), structured data, llms.txt, robots.txt, IndexNow, speed settings | **Claude** | `site/public_html/wp-content/mu-plugins/lld-tools/` |
| Stage 2 documents | **Claude**, after owner decisions | `docs/stage2/` (copied from `spec/stage2/`) |
| Automated checks and deployment | **Claude** | `.github/workflows/`, `deploy/` |
| Stage 1 calculators, their wording and approvals | **Nobody** without an owner decision | `site/public_html/tools/`, `approvals/`, `manifests/`, `copy/` |
| Content: tracks, lessons, activities, recipes, meal plans, workouts, challenges, safety matrix, guides, emails' wording | **Owner approves**; Claude drafts | Entered in WordPress on staging through the owner screens |

## 5. Cursor's work packages, in order

Each package is one pull request titled `S2-Cn: <name>`. A package may start once the one before it has merged, except where marked "can run alongside".

| Pkg | Name | Requirements | Screens, emails, owner screens | Data Model |
|---|---|---|---|---|
| **C1** | Foundations | NFR2-02, NFR2-06, NFR2-10 | Shared components and standard states (screen spec sections 3 to 6) | Plugin set-up, all tables (Areas A, C, P, E, F), integrity rules (T1), test clock, erasure routine (S6, T6), protected photo store (S2), encryption (S3) |
| **C2** | Accounts and privacy | FR2-01, FR2-02, FR2-03, FR2-04, NFR2-01 | SCR-02 to SCR-06, SCR-30, SCR-35 to SCR-37; EM-01 to EM-03, EM-11, EM-13, EM-16, EM-20 | A1 to A4, F1 |
| **C3** | Content library and approvals | FR2-08 (content parts), NFR2-07 | AD-01, AD-09 to AD-14 | Area B, B16, T2 |
| **C4** | Personalisation and safety | FR2-05, FR2-06, FR2-07, FR2-25, FR2-38 | SCR-07 to SCR-09, SCR-13, SCR-20, SCR-25, SCR-26; EM-14, EM-15, EM-19 | C1 to C4, C15, B13 |
| **C5** | Programme | FR2-08, FR2-09, FR2-10, FR2-11, FR2-12 | SCR-10 to SCR-12, SCR-14; EM-04 to EM-08 | C5, C8 to C12, T3 |
| **C6** | Payments and membership | FR2-13, FR2-14, FR2-15, FR2-16, FR2-29 | SCR-01, SCR-24, SCR-31, SCR-32; EM-09, EM-10, EM-17, EM-21; AD-02 to AD-04 | Area P, T4 |
| **C7** | Premium features | FR2-32 to FR2-37 | SCR-15 to SCR-19, SCR-21, SCR-22; AD-07, AD-13 | C6, C7, C13, C14, C16, F7 |
| **C8** | Growth | FR2-17, FR2-18, FR2-19, FR2-28, FR2-30 | SCR-33, SCR-34, SCR-40 to SCR-42; EM-12, EM-18; AD-06 | Area E, F3 to F6 |
| **C9** | Calculator link (can run alongside C5 to C8) | FR2-23 | SCR-23 | C4 |
| **C10** | Quality, owner tools and app readiness | NFR2-03, NFR2-04, NFR2-05, NFR2-08, NFR2-09, UI2-01 to UI2-04 | All screens: accessibility and speed pass; AD-08, plus a check that every owner task in NFR2-08 works from the owner screens built in C2 to C8 | Section 12, T5 |

**Each package includes:**

- the code;
- the screens and emails listed, with every state in the screen specification;
- the automated tests for every check of its requirements that is marked [A] and is not one of Claude's (section 6).

## 6. Claude's work packages

Titled `S2-Ln: <name>`. These run alongside Cursor's packages.

| Pkg | Name | Requirements |
|---|---|---|
| **L1** | Site health and speed | FR2-27 |
| **L2** | Article inventory, category restructure and article review | FR2-26 |
| **L3** | SEO clean-up | FR2-20 |
| **L4** | AEO and GEO: direct answers, FAQ markup, llms.txt, AI crawler access | FR2-21; SCR-43, SCR-44 |
| **L5** | Google Search Console and Bing Webmaster Tools issues, IndexNow and the monthly checklist | FR2-22 |
| **L6** | Content drafting for owner approval: track content, recipes and meal plans, workouts, challenges, safety matrix wording, screening wording, starter guides, email wording, legal pages | FR2-08, FR2-31 to FR2-34, FR2-38, FR2-18 |
| **L7** | Staging tests: payments with test clocks, crawl, performance, security scan, accessibility | Test Criteria section 3 |
| **L8** | Launch: release checklist, production deploy, 24-hour check | Requirements section 9 |

## 7. Rules for Cursor

These are also in `.cursor/rules/stage2.mdc`, which Cursor reads automatically.

1. **Read first:** `docs/stage2/01_Requirements_Stage2.md`, `01a_Screen_Design_Specification_Stage2.md`, `02_Data_Model_Stage2.md`, `03_Test_Criteria_Stage2.md` and this document. The requirements win over everything else.
2. **One package per branch:** branch `s2/c<n>-<short-name>`, pull request titled `S2-Cn: <name>`, into `staging`.
3. **Stay in your files** (section 4). Never edit the calculators (except package C9's single addition), `deploy/`, `.github/workflows/`, `docs/`, `approvals/`, `manifests/`, `copy/` or the `lld-tools` plugin.
4. **Name tests after the checks:** for example `TC-FR2-01-AS-03`. Write one test per check, never weaker than the check's wording.
5. **Data only as the Data Model says:**
   - no new fields or tables without a Data Model change;
   - metric units, UTC times, money with its currency;
   - health data only with health consent;
   - nothing health-related in emails, logs or analytics.
6. **Screens exactly as the Screen Design Specification says:**
   - the accessible colours;
   - every listed state;
   - the three "not available" designs kept distinct;
   - no calorie numbers, no diagnosis, no upsell in calculator results.
7. **Safety first:** the safety matrix is applied before access and matching, everywhere, including direct links, swaps and downloads.
8. **Payments only through Paid Memberships Pro and Stripe.**
   - Card details never touch the site.
   - Access follows Stripe's confirmed state (Data Model T4), never a return page.
   - Test mode only.
9. **Technology:**
   - PHP 8.1 and WordPress coding standards;
   - plain JavaScript and CSS, with no build step and no front-end framework;
   - Playwright and Vitest for tests, as already installed;
   - no new dependency without asking in the pull request.
10. **No secrets and no real data.** Keys come from the environment. Tests use only the test members, test content and sentinel values in the Test Criteria.
11. **Unsure? Don't guess.** Write the question under "Questions" in the pull request, and continue with the rest. Never change a requirement or a test's meaning to make it pass.
12. **Pull request description** uses this template:

```
Package: S2-Cn <name>
Requirements: FR2-…, …
Screens / emails / owner screens: SCR-…, EM-…, AD-…
Checks implemented: TC-…-AS-… (list)
Checks left for staging or owner: TC-… (list, with reason)
How to try it locally: steps
Questions for the lead: numbered, or "none"
```

## 8. How Claude reviews and deploys

1. **Automated checks** must all pass. The Stage 1 calculator protections are never waived.
2. **Review against the documents:** every listed check has a test that is not weaker than its wording; the data stored matches the Data Model; the screens show every state; safety and privacy rules hold.
3. **Outcome:** Claude either merges into `staging`, or returns numbered review comments. Small fixes may go back and forth in the same pull request.
4. **After merging,** `staging` deploys automatically. Claude then runs the plugin database update and the staging test suites, and records the result in the project tracker.
5. **The owner reviews the new screens on staging,** at https://stg.lifelinedaily.com, using the test members. The owner's screen approval is recorded. Approved screens become the visual baseline (UI2-01).

## 9. Environments and accounts

| Environment | Used by | Notes |
|---|---|---|
| Local | Cursor and Claude | Existing local WordPress copy at `http://localhost:8080`; MariaDB on port 3399; Mailpit for emails; test clock on |
| Staging | Claude deploys; owner reviews | https://stg.lifelinedaily.com; Stripe test mode; emails caught, never delivered; hidden from search engines |
| Production | Claude, only after the owner's launch approval | https://lifelinedaily.com |

**Owner inputs needed:**

- Stripe account and test keys;
- Stripe live keys at launch;
- Google Search Console and Bing Webmaster Tools access (OI-11);
- the social profile links and logo for the site description;
- the Facebook group;
- the owner's clinical qualifications for the content sign-off.

## 10. Launch

Stage 2 launches when all of these are true:

1. Stage 1 is released (FR2-24).
2. Every Must check has passed on staging within 7 days, with any exception approved in writing by the owner (Test Criteria section 1).
3. All owner checks are recorded.
4. The owner gives the launch approval.

Claude then deploys to production, switches Stripe to live keys, and runs the 24-hour check.

## 11. Keeping track

- **The interactive project tracker** lists every Phase 0 task, every package (C1 to C10, L1 to L8) and every owner input, with its owner (Cursor, Claude or owner) and status.
- **Claude updates it** after each merge, deploy and test run.
- **The owner can update it** too.
