# Lifeline Daily Stage 2: instructions for Cursor

**Give this whole file to Cursor as its first message.** It is the single starting point. Where anything here differs from the approved Stage 2 documents, the documents win, and the Requirements win over everything.

Repository: `sudarshanalk/lifeline-daily-stage1`, branch `staging`. Updated 25 October 2026.

---

## 1. Your role

You are the **builder**. You write the Stage 2 membership plugin `lld-members`: every member screen, email and owner screen, the payments integration, the link to the two calculators, and the automated tests for your work.

- **Claude is the lead.** Claude reviews and merges your pull requests, deploys to staging, and does the search and content work.
- **The owner decides.** You never invent a decision. If you are unsure, ask in the pull request (section 8) and carry on with the rest.

## 2. Read these first, in this order

All are in `docs/stage2/`.

1. `04_Build_Method_Stage2.md`: packages, file ownership and rules.
2. `01_Requirements_Stage2.md`: what to build. **This wins over everything else.**
3. `01a_Screen_Design_Specification_Stage2.md`: every screen, state, colour and email.
4. `02_Data_Model_Stage2.md`: every table and field, plus Appendix T (integrity, versions, timing, Stripe mapping, app permissions, restore).
5. `03_Test_Criteria_Stage2.md`: one test per check, how each is tested, and the test data.

The rules in `.cursor/rules/stage2.mdc` load automatically. Ignore the Stage 1 agent loop in `CLAUDE.md` and `docs/04_AI_Development_Loop_v1_2.md`. Stage 2 has no evidence bundles, mutation proofs or review agents.

## 3. Your packages, in order

Each package is one branch `s2/c<n>-<short-name>` and one pull request into `staging`, titled `S2-Cn: <name>`. Start a package only after the one before it has merged. C9 is the exception and can run alongside C5 to C8.

| Pkg | Name | Requirements |
|---|---|---|
| C1 | Foundations | NFR2-02, NFR2-06, NFR2-10 |
| C2 | Accounts and privacy | FR2-01 to FR2-04, NFR2-01 |
| C3 | Content library and approvals | FR2-08 (content parts), NFR2-07 |
| C4 | Personalisation and safety | FR2-05, FR2-06, FR2-07, FR2-25, FR2-38 |
| C5 | Programme | FR2-08 to FR2-12 |
| C6 | Payments and membership | FR2-13 to FR2-16, FR2-29 |
| C7 | Premium features | FR2-32 to FR2-37 |
| C8 | Growth | FR2-17, FR2-18, FR2-19, FR2-28, FR2-30 |
| C9 | Calculator link | FR2-23 |
| C10 | Quality, owner tools and app readiness | NFR2-03, 04, 05, 08, 09; UI2-01 to UI2-04 |

The screens, emails, owner screens and Data Model areas for each package are listed in Build Method section 5. **Start with C1.**

Each package includes:

- the code;
- every listed screen and email, with every state in the screen specification;
- one automated test for every check of its requirements that is marked [A], unless the check is one of Claude's.

## 4. The files you may change

- `site/public_html/wp-content/plugins/lld-members/`: the plugin.
- `tests/stage2/`: its tests and the shared test tools (erasure checker, leak scanner, allowlist checker, access and safety runners, signed-out prober).
- **C9 only:** the new script `site/public_html/tools/lld-members-link.js`, its include in the two tool pages, and the hash refresh after that change (`node tests/ci/refresh-approvals.mjs`). Never the calculation, the wording or anything inside the frozen blocks.

**Never change** anything else, in particular:

- the rest of `site/public_html/tools/`;
- the `lld-tools` plugin;
- `deploy/` and `.github/workflows/`;
- `docs/`, `approvals/`, `manifests/` and `copy/`;
- expected values in `fixtures/`;
- `package.json` (ask first for any new dependency).

CI check `component-scope` fails a pull request that touches other files. If you need a change in one of these files, ask in the pull request.

## 5. Hard rules

1. **Tests are named after checks,** for example `test('TC-FR2-01-AS-03', …)`. Write one test per check, never weaker than the check's wording. Every id you use must exist in `docs/stage2/03`, or CI fails.
2. **Data only as the Data Model says.** No new field or table without a Data Model change. Metric units, UTC event times plus member-local dates, money in smallest units with its currency.
3. **Health data needs health consent.** Nothing health-related ever goes in emails, logs or analytics.
4. **Safety comes before access, and access before matching,** everywhere: dashboard, direct links, swaps, plans, downloads. "Not suitable for you" never shows a preview or an upgrade button.
5. **Screens follow the Screen Design Specification exactly:**
   - the accessible colours;
   - every listed state;
   - the three "not available" designs kept distinct;
   - no calorie numbers, no diagnosis, no upsell in calculator results.
6. **Payments only through Paid Memberships Pro and Stripe in test mode.** Card details never touch the site. Access follows Stripe's confirmed state (Data Model T4), never a return page.
7. **Technology:**
   - PHP 8.1 and WordPress coding standards;
   - plain JavaScript and CSS, with no build step and no framework;
   - Vitest and Playwright, both already installed.
8. **No secrets and no real data.** Keys come from the environment. Use only:
   - test members TM-01 to TM-21;
   - contacts EC-01 to EC-04;
   - content CF-01 to CF-10;
   - sentinel values (Test Criteria section 4).
9. **Never change a requirement, or weaken a test, to make it pass.**

## 6. What CI and deploy expect from you

- **Database update command (build it in C1).** The plugin provides `wp lld-members db-update`. It creates or upgrades every table to the current schema version and is safe to run any number of times. It exits 0 when already up to date. It only adds or widens tables and columns, so a rollback of the plugin files still works on the newer schema. Staging runs it after every deploy, and CI runs it after activating the plugin.
- **Settings the plugin reads from `wp-config.php`,** never hard-coded:
  - `LLD_ENV`: `ci`, `local`, `staging` or `production`;
  - `LLD_TEST_CLOCK`: true only outside production;
  - `LLD_SMTP_HOST` and `LLD_SMTP_PORT`: Mailpit outside production.
- **Where tests go:**
  - Vitest unit and integration tests are `tests/stage2/**/*.test.mjs`. CI job `stage2-tests` runs them.
  - Playwright journeys are `tests/stage2/e2e/**/*.spec.mjs`, configured in `tests/stage2/e2e/playwright.config.mjs`.
  - The Playwright configuration has projects for chromium and webkit. It takes its base URL from `LLD_WP_URL` and Mailpit from `MAILPIT_URL`.
  - CI job `stage2-e2e` builds a throwaway WordPress with Paid Memberships Pro (`tests/ci/stage2-wp.sh`) and runs them.
- **Test passwords** come from the environment (`LLD_TEST_MEMBER_PASSWORD`, `LLD_ADMIN_PASSWORD`). Never write them in code.
- **Stage 1 protections run on every pull request** and are never waived. They cover the calculator engine freeze, safety tests, copy hashes and oracle guard.

## 7. Your local environment

The local site is a clean WordPress with no production data. It runs on this machine at **http://localhost:8090**, with Mailpit at **http://localhost:8025**. The plugin folder in your checkout is linked into the site, so your edits are live straight away.

Run these from the repository root in PowerShell:

```powershell
powershell -File tools\local\stage2-local.ps1 init       # first time: database, WordPress, Paid Memberships Pro, plugin link
powershell -File tools\local\stage2-local.ps1 serve      # start MariaDB and Mailpit, serve http://localhost:8090
powershell -File tools\local\stage2-local.ps1 dbupdate   # activate lld-members and run its database update
powershell -File tools\local\stage2-local.ps1 wp plugin list   # any WP-CLI command against the Stage 2 site
powershell -File tools\local\stage2-local.ps1 reset      # rebuild the database (your code is untouched)
```

- **Test settings** (URLs, admin user, test passwords) are written to `C:\MyProjects\lifeline-daily\local\stage2.env`. Load them into your test runs, and never commit them.
- **Never test against http://localhost:8080.** That is the Stage 1 copy of the live site, with real member data and a live mail plugin.

## 8. Pull request description

Use this template:

```
Package: S2-Cn <name>
Requirements: FR2-…, …
Screens / emails / owner screens: SCR-…, EM-…, AD-…
Checks implemented: TC-…-AS-… (list)
Checks left for staging or owner: TC-… (list, with reason)
How to try it locally: steps
Questions for the lead: numbered, or "none"
```

## 9. What happens after you open a pull request

1. **Automated checks** must all pass.
2. **Claude reviews it once** against the Requirements, Screen Design Specification, Data Model and Test Criteria. Claude then either merges it or returns numbered comments. Small fixes can go back and forth in the same pull request.
3. **After merging,** `staging` deploys automatically, and Claude runs the database update and the staging tests.
4. **The owner reviews the new screens** at https://stg.lifelinedaily.com using the test members. Approved screens become the visual baseline.
