# Stage 2 tests that Stage 3 changes (P0-03, decision I-19)

The requirements (v0.3, section 6, "Stage 2 behaviour that Stage 3 changes") name the Stage 2 behaviour each Stage 3 feature replaces. The rule: **with the feature's switch off, the Stage 2 test stays exactly as it is; with it on, the new requirement's tests apply.** Every switch is off for ordinary members today, so no Stage 2 test changes yet. This page maps each row to the test files that will need an entry in `docs/stage3/stage2-test-changes.json` (decision, file, change) in the pull request that turns the switch to `everyone`; the guard (`tests/ci/stage3-scope.mjs --guards`, NFR3-02) refuses any edit to a Stage 2 test without that entry.

| Stage 2 | Stage 2 tests today | With the switch on | New requirement | Switch | Entry needed when |
|---|---|---|---|---|---|
| FR2-01 confirmation link | `tests/stage2/e2e/accounts.spec.mjs` (confirm link), `tests/stage3/key-journeys/sign-up.spec.mjs` (`?token=` link) | Email confirmed with a 6-digit code, signed in straight away | FR3-03 | `sign_in_code` | P3-21 goes to everyone |
| FR2-05 goals | `tests/stage2/e2e/personal.spec.mjs` (TC-FR2-05-AS-01, AS-02), `tests/stage3/key-journeys/goal.spec.mjs` | One main goal plus one extra; required with health consent | FR3-09 | `goals` | P3-31 goes to everyone |
| FR2-09 no glucose anywhere | `tests/stage2/e2e/programme.spec.mjs` (TC-FR2-09-AS-06) | Blood glucose logging with approved warning levels | FR3-45 | `health_logging` | P3-50 goes to everyone |
| FR2-37 no calorie number in the diary | `tests/stage2/e2e/premium.spec.mjs` (TC-FR2-37-AS-02), `tests/stage3/key-journeys/diary.spec.mjs` | Calorie-based food tracker (except eating-disorder flag) | FR3-27 to FR3-29, FR3-69 | `food_tracker` | P3-41 goes to everyone |
| FR2-37 free-week rule | `tests/stage2/e2e/premium.spec.mjs` (diary free week) | Basic logging free all the time, 14 days shown | FR3-63 | `access_tiers` | P3-61 goes to everyone |
| FR2-13 prices | `tests/stage2/e2e/account-review.spec.mjs`, `tests/stage2/e2e/payments.spec.mjs` (TC-FR2-13-AS-01) | US$69.99 a year, US$9.99 a month, tax included | FR3-62 | `prices_launch` | P3-60 goes to everyone |
| FR2-17 analytics | `tests/stage2/e2e/growth.spec.mjs`, `tests/stage2/growth-analytics.test.mjs` | Google Analytics 4 with a cookie banner; weekly report from own data | FR3-66, FR3-67, FR3-70 | `analytics`, `cookie_banner`, `weekly_report` | P3-70 to P3-72 go to everyone |
| FR2-35 Facebook group | `tests/stage2/e2e/growth.spec.mjs` (group checks) | Deferred; switched off | none | always off | never (the Stage 2 tests keep passing with the feature off) |
| FR1-04, UI1-01 two calculator cards | `tests/artefact/TC-FR1-04.test.mjs`, `tests/e2e/TC-FR1-04.spec.mjs` | Every live calculator has a card | FR3-47 to FR3-54 | `calculators_new` | the first new calculator card goes to everyone |
| FR1-17 UK emergency numbers first | `tests/e2e/TC-FR1-17.spec.mjs` (TC-FR1-17-AS-03) | Global urgent-care wording | FR3-46 | already live | already handled in Stage 2 (the test was updated with the merged wording) |

How to use this page: when a package's pull request turns a switch to `everyone` for the first time, add one object per touched Stage 2 test to `stage2-test-changes.json` — `{"file": "<path>", "decision": "I-19", "change": "<one line>"}` — change the test to the new behaviour (never weaken it: the old assertion is replaced by the new requirement's assertion, named after the new check), and keep the row here current. The Stage 3 tests for the new requirement already run with the switch at `test_accounts`.

File names were checked on 3 November 2026; re-check with `grep -rl <check id> tests` when a switch goes to everyone, since the suites are still being added to.
