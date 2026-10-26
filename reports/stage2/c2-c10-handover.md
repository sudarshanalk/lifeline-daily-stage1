# Handover: C8 Growth, C9 Calculator link, C10 Quality and app readiness

Branch `fix/growth-quality`, draft PR #88 (do not merge). This note covers each automatic [A] check in packages C8 to C10.

## How to read the status

- **Pass**: the test passed on the stated engine in the latest CI run of PR #88.
- **Pending (webkit)**: in the latest run, the WebKit job was cancelled during `playwright install --with-deps webkit`, before any test ran. The result comes from the next run.
- **Fail**: a real finding. The reason is given.

"Chromium" is the `stage2-e2e (chromium)` job on commit `05751fe`, which passed every C8 to C10 check. The fixes after it are marked Pending. Vitest results come from the `stage2-tests` job.

## C8 Growth

| Check | Test | Chromium | WebKit | Notes |
|---|---|---|---|---|
| TC-FR2-17-AS-01 | growth.spec | Pass | Pending | Real flows: the join form, the tracks form, the test clock to day 8, signed Stripe checkout, renewal with the switch to yearly, and cancellation notices. The weekly cron runs, and Plausible stats come from a stub on the test site. |
| TC-FR2-17-AS-02 | growth.spec | Pass | Pending | MRR, active and monthly counts after a signed checkout notice. The Plausible Stats API queries name the site and the report week. |
| TC-FR2-17-AS-03 | growth.spec | Pass | Pending | A tagged arrival, then the join form, renewal and cancellation. Replayed Stripe notices credit nothing twice, and a second tagged link is ignored. |
| TC-FR2-17-AS-04 | growth.spec + growth-analytics.test (Vitest) | Pass | Pending | Every Plausible request has top-level `name`, `url` and `domain`, and its props match section 8.5. Nothing is sent without `LLD_PLAUSIBLE_DOMAIN`. Vitest compares the plugin allowlist with the requirements table. |
| TC-FR2-18-AS-01 | growth.spec | Pass | Pending | Token links (never the email), a tampered link, and a second guide for a confirmed contact. |
| TC-FR2-18-AS-02 | growth.spec | Pass | Pending | GET asks and POST unsubscribes. Covers the List-Unsubscribe headers, one-click POST, a tampered link, and the DM-7 sweep at 29 and 31 days. |
| TC-FR2-18-AS-03 | growth.spec | Pass | Pending | |
| TC-FR2-19-AS-01 | growth.spec | Pass | Pending | |
| TC-FR2-19-AS-02 | growth.spec | Pass | Pending | The owner sees the quote. Covers approve, member withdrawal from More, and owner reject. |
| TC-FR2-28-AS-01 | growth.spec | Pass | Pending | |
| TC-FR2-28-AS-02 | growth.spec | Pass | Pending | Unsubscribing now goes through the button. |
| TC-FR2-30-AS-01 | growth.spec | Pass | Pending | |
| TC-FR2-30-AS-02 | growth.spec | Pass | Pending | |

## C9 Calculator link

| Check | Test | Chromium | WebKit | Notes |
|---|---|---|---|---|
| TC-FR2-23-AS-01 | calculator-link.test (Vitest) | Pass | n/a | Frozen engine hashes are unchanged, and the tool pages are not edited. |
| TC-FR2-23-AS-02 | calculator.spec + calculator-link.test (Vitest) | Pass | Pending | A withdrawn result (kidney disease) empties and hides the save form. Pregnancy and breastfeeding save, with the unit line as the band. |
| TC-FR2-23-AS-03 | calculator.spec | Pass | Pending | |

## C10 Quality and app readiness

| Check | Test | Chromium | WebKit | Notes |
|---|---|---|---|---|
| TC-NFR2-03-AS-01 | quality.spec | Pass | Pending | 16 open pages and 26 member screens at 360, 768 and 1280 px. WebKit gives file, date and time inputs a wide intrinsic size, which made the diary and settings pages 3 px too wide at 360 px. The CSS now caps form controls at 100% and lets flex children shrink. |
| TC-NFR2-03-AS-02 | quality.spec | Pass | Pending | |
| TC-NFR2-04-AS-01 | quality.spec | Pending | Pending | axe on every journey page (16 open pages, 26 member screens). The first run found `/members/foundations/` (the owner-only component sheet) answering members with an empty 404 that had no title and no language. It now answers with a proper "Page not found" page. The calculator frames are excluded because they are Stage 1 pages with their own checks. |
| TC-NFR2-04-AS-02 | quality.spec | Pass | Pending | Keyboard-only registration: join, confirm, then sign in by keyboard (confirming never signs anyone in), agreements and goals. Then the dashboard and the diary. WebKit leaves links out of the Tab order (Option+Tab), so there links are focused directly and opened with Enter. |
| TC-NFR2-05-AS-01 | quality.spec | Pass | Pending | Chromium uses DevTools network emulation. WebKit holds back each response by the 150 ms latency plus its size at 1.6 Mbps. |
| TC-NFR2-08-AS-02 | quality.spec | Pass | Pending | Banner, switch, Exit preview, and another member unaffected by the preview. |
| TC-NFR2-09-AS-01 | quality.spec | Pass | Pending | Sign-in through wp_authenticate, scope to /app/ routes, one key per installation, 90-day expiry, withdrawal, revocation on password change, two-step and the sign-in pause. |
| TC-UI2-01-AS-01 | quality.spec | Pass | Pending | |
| TC-UI2-02-AS-01 | quality.spec | Pass | Pending | |
| TC-UI2-03-AS-01 | quality.spec | Pass | Pending | |
| TC-UI2-04-AS-01 | quality.spec | Pass | Pending | The logo is inside the email (cid), with one button and no remote content. The layout applies only to this plugin's own emails. |

## Failures in the same run that are not C8 to C10

These are on files other agents are fixing:
- TC-FR2-01-AS-01 (accounts)
- TC-FR2-02-AS-02 and TC-FR2-02-AS-03 (accounts-privacy)
- TC-FR2-16-AS-03 (payments)
- TC-FR2-06-AS-06 (personal)

## Configuration the owner sets

- `LLD_PLAUSIBLE_DOMAIN`: the Plausible site domain. Until it is set, no analytics request is sent.
- `LLD_PLAUSIBLE_STATS_KEY`: a read-only Stats API key. Until it is set, the Monday report says "Plausible is not connected" in place of visits and calculator use.
