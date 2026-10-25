# Lifeline Daily — Stage 2 Test Criteria (approved by the owner)

**How every Stage 2 check is proven: the test type, the test data, the steps and when it runs**

**Approved by the owner on 25 October 2026.** Prepared 25 October 2026 from the final Stage 2 Requirements (209 numbered checks) and the approved Stage 2 Data Model. Written in plain language. The wording of each check is copied from the requirements, and this document adds how it is tested. If the two ever differ, the requirements win, and this document is corrected.

---

## 1. About this document

The requirements give every requirement numbered checks, for example FR2-01 AS-03. This document says how each check is carried out, so that anyone running the tests gets the same answer.

**Rules for every test:**

1. **One test per check, named after it.** The test for FR2-01 AS-03 is called `TC-FR2-01-AS-03`, as in Stage 1. A check counts as passed only when its test passes.
2. **Tests may not be weaker than the check.** A test that proves less than the check's wording does not count.
3. **Test data only.** Only the made-up members, contacts and content in section 4 are used. Real personal or health data is never used.
4. **Payments use Stripe test mode only,** with Stripe's published test cards and test clocks. No real money moves.
5. **Emails go to Mailpit,** a local mailbox that catches every email, as in Stage 1.
6. **Owner checks are recorded.** Each [O] check is recorded as a dated line, signed by the owner, in `approvals/stage2-owner-checks.json`.
7. **The Stage 1 protections run on every change:** the calculator freeze, the safety tests and the wording fingerprints.

**What "pass" means for the launch.** Stage 2 can launch only when every Must check has passed on staging, against the release candidate, within the 7 days before launch. Any exception must be approved by the owner in writing (requirements section 9).

## 2. Kinds of test

| Code | Kind | What it covers | Tool |
|---|---|---|---|
| **U** | Unit | Rules worked out in code: programme day, completion, age roll-forward, unit conversion, safety matrix, billing arithmetic | Vitest (JavaScript) and PHPUnit (PHP) |
| **I** | Integration | The Lifeline Daily plugin working with WordPress, the database and the membership plugin, including web requests to the plugin | PHPUnit with a test WordPress, plus HTTP requests |
| **E** | End-to-end | A browser using the site the way a member does | Playwright, with WebKit (Safari engine) and Chromium (Chrome and Edge engine) |
| **P** | Payments | Checkout, renewals, failures, refunds, rewards and Stripe notices | Stripe test mode, Stripe test clocks, and the Stripe command-line tool for sending and replaying notices |
| **M** | Email | What is sent, to whom, when, and what it contains | Mailpit |
| **C** | Content and crawl | Site crawl, redirects, links, structured data, consoles, content counts | Repository crawler, Google Rich Results Test, console exports |
| **S** | Security and privacy | Access checks, the erasure checker, the leak scanner, the analytics allowlist checker, and a security scan | Repository tools (section 5), OWASP ZAP |
| **F** | Performance | Page speed in the lab, and real-visitor data when available | Lighthouse user flows, Google PageSpeed |
| **X** | Accessibility | Automated scan plus the manual WCAG checklist | axe, plus a checklist |
| **O** | Owner check | Things only a person can judge | Recorded in `approvals/stage2-owner-checks.json` |

## 3. Where and when tests run

| Where | What runs | When |
|---|---|---|
| **On every change** (GitHub Actions) | U, I, the E core journeys in both browser engines, S scanners (leak, allowlist, access, erasure on test data), Stage 1 protections | Every pull request; a change cannot merge unless they pass |
| **Staging, nightly** | Full E, P with test clocks, M, S security scan, X automated scan | Every night, and before launch |
| **Staging, weekly** | C full crawl, F lab tests | Every Monday, and before launch |
| **Production, monthly** | The SEO runbook checks (FR2-22) and the real-visitor speed data (FR2-27 AS-03) | First Monday of each month |
| **Before launch, once** | All of the above on the release candidate, the backup restore (NFR2-06 AS-01), the real-phone checks (NFR2-03 AS-03), and every owner check | Within the 7 days before launch |

**Controlling time.** Many checks depend on dates, for example ticking an activity 2 days late, nudges after 5 days, the 14-day refund window or 24 months of inactivity.

- **On test and staging sites,** the plugin has a test clock that sets "today" for one test member. It is switched off on production and cannot be switched on there.
- **For payments,** Stripe test clocks move a test customer's subscription forward in time.

## 4. Test data

### 4.1 Test members

All are made-up. Each has its own email, such as `tm-04@test.lifelinedaily.invalid`, and the Stripe test card shown. The `.invalid` ending means no real email can ever be reached.

| Code | Member | Set-up |
|---|---|---|
| TM-01 | Visitor | Signed out, no account |
| TM-02 | Free, no health consent | Account confirmed; health consent off |
| TM-03 | Free, health consent | No conditions declared; Healthy Weight, week 1 |
| TM-04 | Premium monthly | Blood Sugar Balance; prediabetes; UK; card 4242 4242 4242 4242 |
| TM-05 | Premium yearly | Fitness and Strength; no conditions declared; US |
| TM-06 | Premium, pregnancy | Pregnancy or breastfeeding declared |
| TM-07 | Premium, eating disorder | Eating disorder, now or in the past, declared |
| TM-08 | Premium, kidney disease | Kidney disease declared |
| TM-09 | Premium, several conditions | Type 2 diabetes, high blood pressure and high cholesterol declared |
| TM-10 | Premium, conditions not answered | Health consent on, conditions not answered |
| TM-11 | Cancelled, still in paid period | Monthly; cancelled on day 10 of the period |
| TM-12 | Premium ended by failed payment | Monthly; renewal card 4000 0000 0000 0341 (charge fails); mid-week of a track |
| TM-13 | Refunded | Yearly; refunded on day 10; mid-week of a track |
| TM-14 | Local currency, UK | Billed in GBP |
| TM-15 | Local currency, Germany | Billed in EUR |
| TM-16 | US, no sales tax | State with no sales tax |
| TM-17 | Referrer | Yearly; has a referral link |
| TM-18 | Referred friend | Monthly; joins through TM-17's link |
| TM-19 | Inactive free account | Last sign-in set 23 months ago with the test clock |
| TM-20 | Owner admin | Owner role; two-step sign-in on |
| TM-21 | Sleep and Stress screening | Premium; health consent on; about to start Sleep and Stress |

**Email-list contacts:**

| Code | Contact |
|---|---|
| EC-01 | Confirmed, Blood Sugar Balance waitlist |
| EC-02 | Unconfirmed |
| EC-03 | Confirmed, then unsubscribed |
| EC-04 | Confirmed, two guides and one waitlist |

### 4.2 Test content

| Code | Content |
|---|---|
| CF-01 | A complete test track: 4 themes, 4 lessons, 28 activities, 4 weekly tasks, all approved |
| CF-02 | Recipes carrying each safety tag, plus "general safe" recipes with none |
| CF-03 | One meal plan template per track for 4 weeks, with swap options |
| CF-04 | Workout plans: 3 levels, home and equipment versions, 4 weeks each |
| CF-05 | One monthly challenge with 28 activities and a badge |
| CF-06 | The safety matrix, approved |
| CF-07 | 12 articles, 5 of them reviewed for each test goal, plus suggestion rules |
| CF-08 | Badges, including weight-loss badges |
| CF-09 | An approved activity with an unapproved edit waiting |
| CF-10 | A goal with only 4 reviewed articles, and one with 5 |

### 4.3 Sentinel values

Every test member's health fields are filled with unusual made-up values, called **sentinels**, as in Stage 1. Examples are a waist of 87.3 cm, a diary description of "SENTINEL-DIARY-7731" and a "Something else" text of "SENTINEL-ELSE-5520". The leak scanner (section 5) searches for these values anywhere they must never appear, so a leak is found by an exact match.

### 4.4 Payment test data

| Item | Value |
|---|---|
| Successful card | 4242 4242 4242 4242 |
| Card that is accepted but fails when charged | 4000 0000 0000 0341 |
| Card needing extra authentication | 4000 0025 0000 3155 |
| Time travel | Stripe test clocks, one per test member that needs renewals |
| Notices | Sent and replayed with the Stripe command-line tool. Forged notices are made by changing the signature |
| Local currency | Test customers with addresses in GB, DE and US |

## 5. Shared test tools

These tools are built once and used by many checks.

| Tool | What it does | Used by |
|---|---|---|
| **Erasure checker** | Lists every table and file store in the Data Model, and reports any record or file still linked to a member or containing their sentinel values | FR2-03, FR2-04, NFR2-01 |
| **Leak scanner** | Searches emails in Mailpit, server logs, caches, analytics requests, AI requests and data sent to Stripe for sentinel values and health fields | FR2-11, FR2-12, FR2-17, FR2-37, section 8.5 |
| **Allowlist checker** | Compares every request sent to Plausible during a journey with the requirements section 8.5 allowlist, and fails on anything else | FR2-17 |
| **Access-matrix runner** | Signs in as each member state, TM-02 to TM-13, and tries every content type, comparing the result with requirements section 8.3 | FR2-15, FR2-37, FR2-08 |
| **Safety-matrix runner** | For each safety matrix row, signs in as the matching member and tries every route to excluded content: dashboard, direct link, swap, plan, meal plan, workout, challenge, badge and download | FR2-25, FR2-32, FR2-36, section 8.2 |
| **Signed-out prober** | Requests every member page, member data address and member file while signed out | FR2-21, NFR2-02, NFR2-06 |
| **Owner-check recorder** | A short form that adds a dated, signed line to `approvals/stage2-owner-checks.json` | All [O] checks |

## 6. Test pages, by requirement

Each table lists the requirement's checks, copied from the requirements. For each one it gives:

- **Kind:** the kind of test, from section 2;
- **Data:** the test data, from section 4;
- **How it is tested:** the steps and what must be seen.

### Accounts and privacy

#### FR2-01 — Registration and sign-in (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-01-AS-01 [A] | Registering creates an unconfirmed account. Sign-in is refused until the email link is clicked. The link works once and expires after 24 hours. | E, M | TM-01 | Register a new account. Try to sign in before confirming, which must be refused. Open the confirmation email in Mailpit and click the link, which works. Click it again, which is refused. Set the test clock 25 hours ahead on a fresh link, and it must be refused. |
| TC-FR2-01-AS-02 [A] | A 9-character password is refused and a 10-character one is accepted. The eye button shows and hides the password on every password box. | E | TM-01 | Try a 9-character password, which is refused, then a 10-character one, which is accepted. On each password box (registration, sign-in, reset, change), click the eye button and confirm the box switches between hidden and shown. |
| TC-FR2-01-AS-03 [A] | Five wrong passwords in a row block sign-in for 15 minutes. The message does not reveal whether the email is registered. | E, I | TM-03 | Enter a wrong password 5 times; the 6th attempt with the right password is refused. Set the test clock ahead 15 minutes, and it is accepted. Compare the message for a registered and an unregistered email; they must be identical. |
| TC-FR2-01-AS-04 [A] | A password reset link works once and expires after 60 minutes. After a reset, a session left open on a second device is signed out. | E, M | TM-03 | Request a reset and use the link, which works; a second use is refused. Set the clock 61 minutes ahead on a fresh link, which is refused. Open a session in a second browser first; after the reset, that session's next page load shows the sign-in page. |
| TC-FR2-01-AS-05 [A] | An age of 17 is refused and nothing is stored. An age of 18 is accepted. The covering statement is shown on the registration page. | E, I | TM-01 | Register with age 17: refused, with the polite message, and the database has no user for that email. Register with age 18: accepted. The covering statement text is present on the registration page. |
| TC-FR2-01-AS-06 [A] | With "Remember me" ticked, the member is still signed in 29 days later and is signed out after 30. Without it, closing the browser ends the session. | E | TM-03 | Sign in with "Remember me". Move the test clock forward 29 days: still signed in. 30 days and 1 minute: signed out. Sign in without it, close and reopen the browser context: signed out. |

#### FR2-02 — Member profile (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-02-AS-01 [A] | Every listed field can be changed, and the change is still there after reloading. | E | TM-03 | Change each field in turn (name, email after confirmation, password, age, time zone, goals, track, profile, email preferences), reload the page, and confirm the new value shows. |
| TC-FR2-02-AS-02 [A] | A JPG, PNG or HEIC photo of 10 MB or less is accepted. A larger file or another format is refused. The stored photo has no location data, and cannot be opened by a signed-out visitor or another member. | E, S | TM-03 | Upload a 9.9 MB JPG, a PNG and a HEIC: accepted. Upload a 10.1 MB file and a GIF: refused. Read the stored file and confirm it has no location data. Request the photo's address while signed out, and while signed in as TM-04: both refused. |
| TC-FR2-02-AS-03 [A] | An email change takes effect only after the link sent to the new address is clicked. | E, M | TM-03 | Change the email. The old address keeps working until the link sent to the new address is clicked, then only the new one works. |
| TC-FR2-02-AS-04 [A] | The join date equals the account creation date. The next renewal date matches the active Stripe subscription, or shows "not applicable" for a free account. | I, P | TM-03, TM-04 | For TM-03 (free), the join date equals the account creation date and renewal shows "not applicable". For TM-04, the renewal date equals the Stripe subscription's current period end. |
| TC-FR2-02-AS-05 [A] | Changing age to 17 is refused. An age entered 1 year ago shows 1 year higher today. | U, E | TM-03 | Try to save age 17: refused. Set "age entered on" to 1 year and 1 day ago with the test clock: the shown age is one higher than entered. |

#### FR2-03 — Consent (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-03-AS-01 [A] | After confirming their email, a member cannot reach the goal step until the terms are accepted. | E | TM-01 | Register and confirm, then try to open the goal step directly by its address before accepting the terms; the consent step is shown instead. |
| TC-FR2-03-AS-02 [A] | The health and marketing consent boxes start unticked. | E | TM-01 | On the consent step, read the state of the health and marketing boxes: both unticked. |
| TC-FR2-03-AS-03 [A] | With health consent off, every attempt to save goals, profile, check-in, diary or saved-result data is refused. | I, E | TM-02 | For each kind of health record (goal, profile, check-in, diary entry, saved result), try to save it through the page and through the plugin's web address. Every attempt is refused, and the database is unchanged. |
| TC-FR2-03-AS-04 [A] | Switching health consent off shows the warning. Afterwards no health data for that member remains in any table listed in the Data Model, including health-related survey answers and testimonials. Their diary photos cannot be opened and are removed from file storage. Billing continues unchanged. | E, S, P | TM-04 with sentinels | Switch health consent off; the warning lists what will be deleted. Then run the erasure checker: no Area C record, no "what changed for you" answer and no diary photo remains, and the photo addresses return "not found". Check Stripe: the subscription is unchanged. |
| TC-FR2-03-AS-05 [A] | Every consent change is stored with its date. | I | TM-03 | Change each consent; the consent history has one new line per change, with the date and the document version. |
| TC-FR2-03-AS-06 [A] | Health consent is withdrawn, then a backup from before the withdrawal is restored. After the erasure list is re-applied, consent is still withdrawn, no health data has returned, and the site was not reachable by members until this was done. | S, I | TM-04 with sentinels | Take a database backup. Withdraw health consent. Restore the backup on staging. Confirm the site shows maintenance mode, then run the restore procedure (Data Model T6) using the erasure-list email from Mailpit. Run the erasure checker: nothing has returned, and the consent history shows the withdrawal as the latest line. |

#### FR2-04 — Download and delete my data (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-04-AS-01 [A] | The ZIP download contains every record held for the test member, checked against every table in the Data Model, with photos included, and no password or key. No copy is left on the server. | E, S | TM-04 with sentinels | Request the download. Unzip it: compare every record with the database, table by table, using the erasure checker's list; photos are present. Search the file for password hashes and keys: none. Check the server's file storage: no copy left. |
| TC-FR2-04-AS-02 [A] | Deletion asks for the password, ends access at once, and cancels the Stripe test subscription. | E, P | TM-04 | Delete the account: the password is asked for, the session ends at once, and the Stripe test subscription shows as cancelled. |
| TC-FR2-04-AS-03 [A] | Within 24 hours, no table, uploaded file, cache or log holds the member's personal data, except the section 8.1 exceptions. | S | TM-04 with sentinels | After deletion, set the test clock 24 hours ahead and run the erasure checker across tables, file storage, caches and logs. Only the tax fields, the erasure-list line and anonymised admin-log lines remain. |
| TC-FR2-04-AS-04 [A] | When Stripe cancellation is made to fail in a test, the owner receives an alert. | P, M | TM-04 | Make Stripe's cancellation fail, using a test setting that refuses the call, then delete the account. The owner receives the alert email. |
| TC-FR2-04-AS-05 [A] | Restoring a backup taken before the deletion, then re-applying the erasure list from the owner's mailbox copy, leaves the member deleted. Members could not reach the site until this was done. | S, I | TM-04 | Take a backup, delete the account, restore the backup, and run the restore procedure from the erasure-list email. The member does not exist afterwards, and members were shown maintenance mode until it finished. |
| TC-FR2-04-AS-06 [A] | A deletion confirmation email is sent, and the daily erasure-list email reaches the owner. | M | TM-04 | After deletion, Mailpit holds the deletion confirmation for the member, and the next daily erasure-list email to the owner contains the deletion. |

### Personalisation

#### FR2-05 — Goals and tracks (Must, needs health consent).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-05-AS-01 [A] | A fourth goal cannot be selected. | E | TM-03 | Choose three goals, then try a fourth: it cannot be selected. |
| TC-FR2-05-AS-02 [A] | Skipping the goal step leads to the dashboard with the general wellbeing path. | E | TM-03 | Skip the goal step: the dashboard opens and shows the general wellbeing path. |
| TC-FR2-05-AS-03 [A] | A goal with 4 reviewed articles is hidden. With 5 articles, and 7 activities for a track goal, it appears. | I, E | CF-10 | With CF-10 loaded, the goal with 4 reviewed articles is not listed, and the one with 5 (and 7 activities for its track) is. |
| TC-FR2-05-AS-04 [O] | The owner adds, renames and removes a goal without a developer. | O | — | The owner adds, renames and removes a goal from the admin screen, and records the result. |
| TC-FR2-05-AS-05 [A] | "Something else" text is saved only when health consent is on. | I, E | TM-02, TM-03 | As TM-03, type "Something else" text: saved. As TM-02, without health consent: the text box is not offered, and posting directly is refused. |

#### FR2-25 — Optional health profile (Must, needs health consent).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-25-AS-01 [A] | The profile saves with any combination of fields left blank. | E | TM-03 | Save the profile with every field empty, then with each field alone: all saves succeed. |
| TC-FR2-25-AS-02 [A] | Unrealistic values, such as a height of 300 cm or a weight of 5 kg, ask the member to check. | E | TM-03 | Enter height 300 cm, then weight 5 kg: each asks the member to check before saving. |
| TC-FR2-25-AS-03 [A] | Metric and imperial entries store the same value, for example 5 ft 10 in and 177.8 cm. | U, E | TM-03 | Enter 5 ft 10 in in imperial mode; the stored value is 177.8 cm. Repeat for weight and waist. |
| TC-FR2-25-AS-04 [A] | With no height, no BMI or waist-to-height ratio is shown, and the next check-in asks for height once. | E | TM-03 | Remove height: no BMI or waist-to-height ratio appears anywhere. At the next check-in, height is asked once; after it is entered, it is not asked again. |
| TC-FR2-25-AS-05 [A] | For every row of the safety matrix, there is one test. After the condition is saved, excluded content cannot be seen, opened or chosen anywhere: dashboard, direct link, swap, plan, meal plan, workout, challenge, badge or download. | S | TM-03, TM-06 to TM-10, CF-02 to CF-08 | Run the safety-matrix runner. For each matrix row, save the condition on the profile, then try every route to excluded content. Each attempt must show nothing excluded, or be refused. |
| TC-FR2-25-AS-06 [A] | The profile reminder is sent once only. | M, I | TM-03 | After sign-up, set the clock forward 30 days: exactly one profile reminder is in Mailpit. |

#### FR2-38 — Sleep and Stress safety screening (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-38-AS-01 [A] | Answering yes to self-harm, alone or with other answers, shows the emergency message with its examples and findahelpline.com, and no track offer appears in that session. | E | TM-21 | Start Sleep and Stress and answer yes to self-harm, then repeat with yes to all three: each time the screen shows the emergency message with its examples and findahelpline.com, and no option to start the track appears in that session. |
| TC-FR2-38-AS-02 [A] | Answering yes to panic shows doctor advice and then allows the member to continue. | E | TM-21 | Answer yes to panic only: doctor advice shows, then a continue button starts the track. |
| TC-FR2-38-AS-03 [A] | Answering yes to low mood shows doctor advice and then allows the member to continue. | E | TM-21 | Answer yes to low mood only: doctor advice shows, then a continue button starts the track. |
| TC-FR2-38-AS-04 [A] | After screening, no screening answers exist in the database or the logs. | S | TM-21 | After each screening, search the database and logs for the screening answers: none are found. |
| TC-FR2-38-AS-05 [A] | The screening appears every time the track is started, including after signing out and on another device. | E | TM-21 | Start the track, sign out, sign in on a second browser, and start the track again: the screening appears both times. |
| TC-FR2-38-AS-06 [A] | No content anywhere carries the breath-holding tag, and saving content with that tag is refused. | I | CF-01 | Search all content for the breath-holding tag: none. Try to save content with it: refused. |
| TC-FR2-38-AS-07 [O] | The owner approves the wording of all three responses. | O | — | The owner approves the wording of the three responses, and records it. |

#### FR2-06 — Content personalisation engine (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-06-AS-01 [A] | The dashboard never shows more than 6 suggestions, and each one has a reason line. | E | TM-04, CF-07 | Count the suggestions on the dashboard: 6 or fewer, each with a non-empty reason line. |
| TC-FR2-06-AS-02 [A] | An item opened within 30 days is not shown while at least 6 others are available. The oldest-opened items return first when fewer remain. | I, E | TM-04, CF-07 | Open one suggestion. With at least 6 others available, it does not reappear for 30 days, which is checked by moving the test clock. Reduce the available items below 6: the oldest-opened returns first. |
| TC-FR2-06-AS-03 [A] | "Not for me" hides that one item, and similar items still appear. | E | TM-04, CF-07 | Choose "not for me" on one item: it disappears, and a similar item with the same tags still appears. |
| TC-FR2-06-AS-04 [A] | Content excluded by safety rules never appears, even when the list would be empty, and the empty-state message is shown. | S | TM-06, TM-08 | With every eligible item excluded by the safety matrix, the dashboard shows the empty-state message, and no excluded item appears. |
| TC-FR2-06-AS-05 [A] | An unreviewed article is never suggested. | I | CF-07 | Mark one article as not reviewed: it never appears in any member's suggestions. |
| TC-FR2-06-AS-06 [A] | The monthly "Something else" report and the weekly short-content note reach the owner. | M | TM-20 | Move the clock to the monthly and weekly report dates: both emails reach the owner in Mailpit with the expected content. |
| TC-FR2-06-AS-07 [A] | When a matching goal goes live, members who asked for it are emailed, only if they consented to emails. | M | TM-03, TM-02 | With a matching "Something else" request from each, switch a new goal live. Only the member who agreed to emails gets the email. |

#### FR2-07 — Personal Health Dashboard (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-07-AS-01 [A] | Dashboard sections appear in the stated order. | E | TM-04 | Read the dashboard sections in page order and compare them with the order in FR2-07. |
| TC-FR2-07-AS-02 [A] | A free member sees the "what Premium adds" box, and a Premium member does not. | E | TM-03, TM-04 | The "what Premium adds" box appears for TM-03 and not for TM-04. |
| TC-FR2-07-AS-03 [A] | A monthly member past their first month sees the switch-to-yearly prompt, and a yearly member does not. | E, P | TM-04, TM-05 | Move TM-04's test clock past the first month: the switch-to-yearly prompt shows. TM-05, yearly: it never shows. |
| TC-FR2-07-AS-04 [A] | The dashboard is fully usable on a 360-pixel-wide screen. | E | TM-04 | At 360 pixels wide, every dashboard control can be used, and nothing needs sideways scrolling. |

### The Healthy Habits Programme and ongoing Premium

#### FR2-08 — Programme engine and four tracks (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-08-AS-01 [O] | Each of the four tracks has 4 themes, 4 lessons of no more than 1,000 words, 28 activities and 4 tasks, all with an approval record. | O | CF-01 and the four real tracks | The owner confirms the counts and the approval records for each real track, and records it. |
| TC-FR2-08-AS-02 [A] | A free member cannot open day 8 of a track. A Premium member can. | E | TM-03, TM-04 | Move TM-03 to day 8 of a track: day 8 is locked with an upgrade prompt. TM-04 can open it. |
| TC-FR2-08-AS-03 [A] | Content the owner edits appears to members without developer work, once approved. | E | TM-20, TM-04 | The owner edits an activity and approves it in the admin screen. TM-04 then sees the new text, with no code change or deployment. |
| TC-FR2-08-AS-04 [A] | Content without an approval record is never shown to members. | I | CF-09 | Content without an approval record does not appear in any page, plan, suggestion or download. |
| TC-FR2-08-AS-05 [A] | After an approved activity's instructions are edited, members still see the old approved text, and the new text appears only after re-approval. | E | CF-09, TM-04 | Edit an approved activity without approving: TM-04 still sees the old text. Approve it: TM-04 sees the new text. |

#### FR2-09 — Progress and weekly check-in (Must, needs health consent).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-09-AS-01 [A] | An activity can be marked up to 2 days late, but not 3. | E, I | TM-03 | Tick an activity 2 days after its day: accepted. Tick one 3 days after: refused. |
| TC-FR2-09-AS-02 [A] | Each track's check-in shows exactly the measures listed for it, and no others. | E | TM-03 to TM-05, TM-21 | For each track, open the check-in and compare the measures shown with the list in FR2-09. |
| TC-FR2-09-AS-03 [A] | BMI and waist-to-height ratio are correct for 10 test cases. | U | — | Ten prepared height, weight and waist cases, including the boundaries 0.5 and 0.6 of waist-to-height ratio, give the expected BMI and ratio. |
| TC-FR2-09-AS-04 [A] | The chair stand safety instructions appear before the test every time. | E | TM-05 | Open the Fitness and Strength check-in three times: the chair-stand safety instructions appear before the test every time. |
| TC-FR2-09-AS-05 [A] | Charts show the change since week 1. A forbidden-words check finds no diagnosis wording. | E, S | TM-04 | Enter two check-ins: the chart shows the change from week 1. A wording check of the page finds no diagnosis words. |
| TC-FR2-09-AS-06 [A] | Blood glucose is not requested anywhere. | S | All | Search every page, form and email template: blood glucose is never requested. |
| TC-FR2-09-AS-07 [A] | Without health consent, the check-in is not available. | E, I | TM-02 | The check-in is not offered, and posting one directly is refused. |

#### FR2-10 — Personalised Weekly Action Plan (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-10-AS-01 [A] | Each week's plan has 3 to 5 actions, and never more than 5. | U, I | TM-04 | Over 10 generated weeks with varied completion, every plan has 3 to 5 actions. |
| TC-FR2-10-AS-02 [A] | Completion of 49 % gives an easier plan. 50 % and 80 % keep the same level. 81 % replaces one action with a stretch action, keeping the count the same. | U | — | Completion of 49 %, 50 %, 80 % and 81 % gives easier, same, same and stretch, and the stretch plan has the same number of actions as before. |
| TC-FR2-10-AS-03 [A] | A missing check-in does not change the plan. | U | TM-04 | Generate a plan with and without the previous week's check-in: the plans are identical. |
| TC-FR2-10-AS-04 [A] | One swap a week is allowed, and a second is refused. | E | TM-04 | Swap the plan once: accepted. A second swap in the same week: refused. |
| TC-FR2-10-AS-05 [A] | A free member receives no plan. | E, I | TM-03 | No plan appears for a free member, and requesting one directly is refused. |
| TC-FR2-10-AS-06 [A] | With only 2 safe actions, the plan shows 2 and the note. With none, it shows the empty-plan message. | U | CF-06 | With only 2 safe actions left after the safety matrix, the plan has 2 and shows the note. With none, it shows the empty-plan message. |

#### FR2-11 — Weekly Progress Review (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-11-AS-01 [A] | The review appears on day 7 of each track week. | E | TM-04 | Move the test clock to day 7 of each track week: the review appears that day and not before. |
| TC-FR2-11-AS-02 [A] | The review email contains only the "ready" notice and a link. A content check finds no numbers or health words. | M, S | TM-04 with sentinels | Read the review email in Mailpit: it contains only the notice and a link. The leak scanner finds no sentinel values or health words in it. |
| TC-FR2-11-AS-03 [A] | The week-4 review suggests the next track or the monthly challenge. | E | TM-04 | At day 28, the review suggests the next track or the monthly challenge. |
| TC-FR2-11-AS-04 [O] | The owner confirms the review wording is encouraging. | O | — | The owner reads three sample reviews, for good, average and missed weeks, and confirms the tone is encouraging. |

#### FR2-12 — Programme emails and reminders (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-12-AS-01 [A] | The daily reminder arrives within 10 minutes of the chosen time, in the member's own time zone, including on the days clocks change. | M, U | TM-04 in London, TM-05 in New York | Set a reminder at 07:30. Each email arrives between 07:30 and 07:40 local time, including on the day each zone changes its clocks. |
| TC-FR2-12-AS-02 [A] | The first nudge goes 5 days after the last sign-in, the second 5 days later, and none after that until the member signs in. | M | TM-03 | Move the clock: the first nudge on day 5 after the last sign-in, the second on day 10, and none on day 15. Sign in, then wait 5 days: a nudge is sent again. |
| TC-FR2-12-AS-03 [A] | Every non-transactional email has a working one-click unsubscribe. | M, E | TM-03 | Open every non-transactional email in Mailpit, click unsubscribe while signed out: the preference turns off, and a second click shows "already unsubscribed". |
| TC-FR2-12-AS-04 [A] | A scan of every email template filled with test data finds no health details. | S | All, with sentinels | Fill every email template with each test member's data. The leak scanner finds no sentinel values or health fields. |
| TC-FR2-12-AS-05 [A] | Opt-in emails are sent only after the member has chosen them. | M | TM-03 | With every optional email off, only transactional emails appear in Mailpit over a simulated month. |

#### FR2-32 — Weekly meal plans and shopping lists (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-32-AS-01 [A] | A Premium member receives 7 days of meals each week, matching their track and diet type. | E | TM-04, TM-05 | Each week, a Premium member has 7 days of meals, matching their track and diet type. |
| TC-FR2-32-AS-02 [A] | Safety rules are respected. For example, a pregnancy flag gives no weight-loss portions. | S | TM-06 to TM-09, CF-02 | The safety-matrix runner confirms no excluded recipe appears in any meal plan or swap list for each matrix row. |
| TC-FR2-32-AS-03 [A] | The shopping list prints and downloads as a PDF. | E | TM-04 | Print the shopping list and download the PDF. The ingredients match the week's recipes. |
| TC-FR2-32-AS-04 [A] | Any meal can be swapped for an alternative. | E | TM-04 | Swap a meal: the alternatives are shown, the choice is saved, and the shopping list updates. |
| TC-FR2-32-AS-05 [O] | At launch there are at least 4 weeks of plans per track, all approved. | O | Real content | The owner confirms at least 4 weeks per track are approved, and records it. |
| TC-FR2-32-AS-06 [A] | A free member cannot open the meal plans. | E, I | TM-03 | Opening a meal plan page or address as a free member shows the upgrade prompt, never the plan. |

#### FR2-33 — Monthly challenge (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-33-AS-01 [O] | The first challenge is ready and approved at launch. | O | Real content | The owner confirms the first challenge is approved before launch, and records it. |
| TC-FR2-33-AS-02 [A] | A Premium member can join a challenge with or without a track, and receives a badge on finishing. | E | TM-04, TM-05 | TM-05, not in a track, and TM-04, in a track, both join. After finishing 28 days with the test clock, both have the badge. |
| TC-FR2-33-AS-03 [A] | An unapproved challenge is not visible to members. | I | CF-09 | An unapproved challenge does not appear anywhere for members. |
| TC-FR2-33-AS-04 [A] | A Premium member without health consent can open every challenge day, and nothing about their progress is stored. | E, S | TM-02 upgraded to Premium | Open every day of a challenge. The erasure checker confirms no progress records exist for this member. |

#### FR2-34 — Progressive workout plans (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-34-AS-01 [O] | At launch there are 3 levels, each with a home and an equipment version, and at least 4 weeks each. | O | Real content | The owner confirms the 3 levels, 2 versions and 4 weeks each are approved, and records it. |
| TC-FR2-34-AS-02 [A] | The timer works, and the safety advice appears before each workout. | E | TM-05 | Start a workout: the safety advice appears first, and the timer counts each exercise and rest correctly. |
| TC-FR2-34-AS-03 [O] | The owner confirms each week steps up from the one before. | O | Real content | The owner confirms each week steps up from the one before, and records it. |
| TC-FR2-34-AS-04 [A] | A Premium member without health consent can use every workout, and nothing about their progress is stored. | E, S | TM-02 upgraded to Premium | Complete every workout of week 1. The erasure checker confirms no progress records exist. |

#### FR2-35 — Members' Facebook group and monthly live Q&A (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-35-AS-01 [O] | The group is private, and only Premium members are admitted, checked against an exported member list. | O | — | The owner confirms the group is private, and compares its members with the exported member list, and records it. |
| TC-FR2-35-AS-02 [A] | The dashboard shows the next live Q&A date and the Facebook privacy notice. | E | TM-04 | The dashboard shows the next live Q&A date and the Facebook privacy notice. |
| TC-FR2-35-AS-03 [A] | Each week the owner receives the list of people whose Premium has ended. Each removal is recorded with its date, and the log shows none overdue beyond 7 days. | M, I | TM-12 | When Premium ends, TM-12 appears on the next weekly list. Record a removal: it is stored with its date. A removal not recorded within 7 days is flagged. |

#### FR2-36 — Long-term progress and milestones (Must, Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-36-AS-01 [A] | The monthly check-in is available after a track ends, showing the measures of the last track finished. The member can change them. | E | TM-05 | After finishing Fitness and Strength, the monthly check-in shows that track's measures. Change them: the change is saved. |
| TC-FR2-36-AS-02 [A] | Charts cover every month since joining. | E | TM-04 | With check-ins across 4 months, made with the test clock, the chart covers all 4. |
| TC-FR2-36-AS-03 [A] | Badges are awarded at their stated thresholds. Weight-loss badges never appear for members with a pregnancy or eating-disorder flag. | I, S | TM-04, TM-06, TM-07 | Badges are awarded at their thresholds for TM-04. For TM-06 and TM-07, weight-loss badges are never awarded or shown. |

#### FR2-37 — Simple food diary (Must, needs health consent; free during each free week, then Premium).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-37-AS-01 [A] | A Premium member can log a meal in 3 taps or fewer using "ate the planned meal". A free member can log a meal by description. | E | TM-04, TM-03 | TM-04 logs "ate the planned meal" in 3 taps or fewer. TM-03 logs a meal by description. |
| TC-FR2-37-AS-02 [A] | No calorie numbers appear anywhere in the diary. | S | TM-04 | Search all diary pages and responses for calorie values: none. |
| TC-FR2-37-AS-03 [A] | The eating-disorder flag hides the portion questions. | E | TM-07 | The portion question is not shown, and posting a portion directly is refused. |
| TC-FR2-37-AS-04 [A] | The weekly summary counts are correct for a prepared test week. | U | Prepared week | A prepared week of entries gives the expected summary counts. |
| TC-FR2-37-AS-05 [A] | A free member finishes one track's free week and the diary becomes read-only. Starting another track's free week reopens it, and earlier entries are still there. | E | TM-03 | At the end of the first free week the diary is read-only. Start another track's free week: it opens again, and the earlier entries are still there. |
| TC-FR2-37-AS-06 [A] | Diary entries never appear in emails, are deleted with the account, and are included in the data download. | S, M | TM-04 with sentinels | The leak scanner finds no diary sentinel in emails. After deletion, the erasure checker finds no diary records. The data download contains the diary. |
| TC-FR2-37-AS-07 [A] | A diary photo of a refused format or over 10 MB is rejected. A stored photo has no location data and cannot be opened by anyone except its member. | E, S | TM-04 | Upload a GIF and an 11 MB photo: refused. A valid photo is stored without location data. It can't be opened while signed out or as another member. |

### Paid membership

#### FR2-13 — Plans and prices (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-13-AS-01 [A] | The pricing page shows US$59.99 a year first, marked "best value", and US$7.99 a month second, both marked "tax included". | E | TM-01 | The pricing page shows yearly first with "best value" and monthly second, both labelled "tax included". |
| TC-FR2-13-AS-02 [A] | For test customers in the UK, an EU country and the US, the amount charged equals the price shown, with tax inside it. | P | TM-14, TM-15, TM-16 | Check out as each. The amount charged equals the amount shown at checkout, and Stripe's tax breakdown shows the tax inside it (GB VAT, DE VAT, US none). |
| TC-FR2-13-AS-03 [A] | The launch code gives 50 % off the first month only, and stops working after 30 days. | P | TM-18 | Use the launch code: the first month is half price, the second month is full price. Move the clock 31 days past launch: the code is refused. |
| TC-FR2-13-AS-04 [A] | The owner can change prices and create codes from the admin screen. | E | TM-20 | The owner changes the monthly price and creates a code in the admin screen. A new checkout uses the new price, and the code works. |
| TC-FR2-13-AS-05 [A] | Yearly members are recorded as entitled to Premium in the Stage 3 app. | I | TM-05 | TM-05's record shows Stage 3 app Premium entitlement. TM-04, monthly, does not. |

#### FR2-14 — Payments, renewals and cancellation (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-14-AS-01 [A] | Checkout runs on Stripe's hosted page, and no card fields exist on the website. | E, S | TM-04 | Checkout opens on Stripe's own page. Scan the website's pages: no card number fields exist. |
| TC-FR2-14-AS-02 [A] | A test customer from another country sees the price in their local currency. Apple Pay appears in Safari on an Apple device, and Google Pay in Chrome where set up. | P, O | TM-14; owner's devices | TM-14 sees the price in GBP. On an Apple device in Safari and an Android phone in Chrome, the owner confirms Apple Pay and Google Pay appear, and records it. |
| TC-FR2-14-AS-03 [A] | Each renewal creates a receipt. Yearly members receive the reminder 7 days before renewal. | P, M | TM-04, TM-05 | Advance the test clocks through a renewal: a receipt email arrives. For TM-05, the reminder arrives 7 days before renewal. |
| TC-FR2-14-AS-04 [A] | A failed payment is retried for 7 days, then Premium ends as section 8.3 describes. | P | TM-12 | Advance through a renewal with the failing card: retries happen over 7 days, then the access state becomes "Premium ended" (Data Model T4). |
| TC-FR2-14-AS-05 [A] | Cancelling takes 3 clicks or fewer from the dashboard, and access continues to the end of the paid period. | E | TM-11 | Cancel from the dashboard in 3 clicks or fewer. Access continues until the period end, then ends. |
| TC-FR2-14-AS-06 [A] | A refund within 14 days can be issued from the admin screen. | E, P | TM-20, TM-13 | The owner refunds TM-13's first payment within 14 days from the admin screen. Stripe shows the refund. |
| TC-FR2-14-AS-07 [A] | A forged notice is rejected. A repeated notice changes nothing. A cancellation notice arriving before its payment notice leaves the correct final state. A deliberate mismatch is flagged to the owner the next day. | P | TM-04 | Send a notice with a forged signature: rejected. Replay a real notice: nothing changes. Send a cancellation notice before its payment notice: the final state is correct. Make access and Stripe disagree: the next day's check flags it to the owner. |
| TC-FR2-14-AS-08 [A] | A pending monthly-to-yearly switch can be cancelled before the renewal date, and the member then stays monthly. | P | TM-04 | Ask to switch to yearly, then cancel the switch before renewal. At renewal, the member is still monthly. |

#### FR2-15 — What Premium unlocks (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-15-AS-01 [A] | Access for one test member in each state matches the section 8.3 table exactly. | S | TM-02 to TM-13 | The access-matrix runner signs in as each state and tries every content type. The results match requirements section 8.3 exactly. |
| TC-FR2-15-AS-02 [A] | Premium unlocks within 1 minute of payment. | P, E | TM-02 | Complete checkout. Premium content opens within 1 minute. |
| TC-FR2-15-AS-03 [A] | A locked page shows a preview and an upgrade button. | E | TM-03 | Open a locked page: a preview and an upgrade button appear, never the full content. |
| TC-FR2-15-AS-04 [A] | The calculators, safety information and disclaimer open for a signed-out visitor. | E | TM-01 | Both calculators, the safety information and the disclaimer open while signed out. |
| TC-FR2-15-AS-05 [A] | Premium is ended mid-week by expiry, and separately by refund. In both cases, the rest of the current track week stays open until its last day, and every other Premium item locks at once. On the next day nothing new opens. | P, E | TM-12, TM-13 | On day 3 of a track week, end Premium for TM-12 by expiry and refund TM-13. The rest of that week stays open to its last day. Meal plans, workouts and challenges lock at once. The next day, nothing new opens. |

#### FR2-16 — Sales pages and upgrade journey (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-16-AS-01 [A] | The pricing page and the four sales pages exist with every required section. | C | — | The crawler finds the pricing page and the four track sales pages, each with every required section. |
| TC-FR2-16-AS-02 [A] | No price or upsell appears inside calculator results, and the track link sits below each calculator. | E, S | TM-01 | Use both calculators to a result: no price or upsell appears in the result. The track link appears below each calculator, on the landing page. |
| TC-FR2-16-AS-03 [A] | Each sales page has its own title and description and is listed in the sitemap. | C | — | Each sales page has its own title and description, and appears in the sitemap. |
| TC-FR2-16-AS-04 [O] | The owner confirms the upgrade invitations are not pushy. | O | — | The owner reviews every upgrade invitation and confirms none is pushy, and records it. |

### Growth and measurement

#### FR2-17 — Conversion analytics (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-17-AS-01 [A] | The Monday report arrives with every listed figure for a prepared test week. | M, I | Prepared test week | Load a prepared week of activity. The Monday report email in Mailpit has every listed figure, with the expected values. |
| TC-FR2-17-AS-02 [A] | Report figures match Stripe and Plausible for the test data. | I, P | Prepared test week | The report's figures equal the totals from Stripe test mode and Plausible's test site for the same week. |
| TC-FR2-17-AS-03 [A] | A sign-up through a tagged link is credited once, and that member's later renewal and cancellation are reported under the same source. | E, P | TM-18 | Arrive by a tagged link and register, then renew and cancel using the test clock. The report counts the sign-up once, and shows the renewal and cancellation under the same source. |
| TC-FR2-17-AS-04 [A] | Every request sent to Plausible during a full test journey matches the section 8.5 allowlist exactly. Anything else fails the test, as does a web address carrying an email, a name or a profile value. | S | Full journey, TM-01 to TM-04 | The allowlist checker records every request to Plausible during the journey. Every one matches the allowlist, and any web address with an email, name or profile value fails the test. |

#### FR2-18 — Free starter guides and email sign-up (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-18-AS-01 [A] | Each track's guide is delivered only after the double opt-in link is clicked. | E, M | EC-02 | Request a guide: only the confirmation email arrives. Click it: the guide arrives. |
| TC-FR2-18-AS-02 [A] | Unsubscribe works. | M | EC-01 | Click unsubscribe: no further marketing email is sent. |
| TC-FR2-18-AS-03 [A] | The guide offer appears on matching articles and below the calculators. | C | — | The crawler finds the guide offer on articles tagged for each track, and below both calculators. |

#### FR2-19 — Feedback and testimonials (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-19-AS-01 [A] | The surveys appear at the end of week 1 and week 4. | E | TM-04 | The survey appears at the end of week 1 and week 4, using the test clock. |
| TC-FR2-19-AS-02 [A] | A testimonial is not visible until the owner approves it, and shows only first name and country. | E, I | TM-04, TM-20 | Submit a testimonial with permission. It is not visible on the site until the owner approves it. Then it shows only the first name and country. |
| TC-FR2-19-AS-03 [O] | No published testimonial claims a medical result. | O | — | The owner confirms no published testimonial claims a medical result, and records it. |

#### FR2-28 — Waitlist pages (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-28-AS-01 [A] | Four waitlist pages are live before launch, and sign-up works on each. | C, E | EC-02 | Before launch, the four waitlist pages exist, and sign-up completes on each. |
| TC-FR2-28-AS-02 [A] | The launch email reaches every confirmed, still-subscribed waitlist contact, and no unconfirmed or unsubscribed contact. | M | EC-01 to EC-04 | Send the launch email. EC-01 and EC-04 receive it. EC-02 (unconfirmed) and EC-03 (unsubscribed) do not. |

#### FR2-29 — Referral reward (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-29-AS-01 [A] | Each member's dashboard shows their referral link. Each worked example in section 8.4 passes as a test. | P | TM-17, TM-18 | Each worked example in requirements section 8.4 is run with Stripe test clocks, and the payments match the examples. |
| TC-FR2-29-AS-02 [A] | A fourth referral in the same calendar year earns no reward, and a payment refunded within 14 days earns none. | P | TM-17 | Create 4 qualifying referrals in one calendar year: only 3 rewards. Refund a friend within 14 days: that referral earns no reward. |
| TC-FR2-29-AS-03 [A] | Referring yourself, by the same email or the same card, earns no reward. | P | TM-17 | Refer an account with the same email, and one using the same test card: no reward for either. |
| TC-FR2-29-AS-04 [A] | If processing stops after the referrer is rewarded, retrying rewards the friend without rewarding the referrer twice. | P, I | TM-17, TM-18 | Stop processing after the referrer's reward is created. Retry: the friend's reward is created, and the referrer still has exactly one. |
| TC-FR2-29-AS-05 [A] | For a member billed in local currency, the reward reduces the next payment by the stated share in that currency. | P | TM-14 as friend | For a member billed in GBP, the reward reduces the next GBP payment by the stated share. |

### Website health, content and search visibility

#### FR2-27 — WordPress site health and speed (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-27-AS-01 [A] | Mobile PageSpeed reports for the four page types are saved, before and after. | F | — | Run the four page types through PageSpeed on mobile before and after the work, and keep the reports. |
| TC-FR2-27-AS-02 [A] | Lab test with a simulated mid-range phone on 4G, with a scripted tap on the main menu and one in-page button: loading under 2.5 seconds, layout shift under 0.1, and response to the tap under 200 milliseconds on all four page types. Results are labelled as lab results. | F | — | Run the Lighthouse user flow on a simulated mid-range phone on 4G, with the scripted taps. Report loading, layout shift and tap response per page type, labelled as lab results. |
| TC-FR2-27-AS-03 [A] | Once Google reports real-visitor data, all four page types show "good". Until then the report says the data is not yet available. | F | — | Each month, read the real-visitor data. Pass when all four page types show "good", or the report says the data is not yet available. |
| TC-FR2-27-AS-04 [A] | Unused plugins are removed, and the server log shows no PHP errors over 24 hours. | I, C | — | Compare the plugin list with the agreed removals. Read 24 hours of the server log: no PHP errors. |
| TC-FR2-27-AS-05 [A] | Images are served as WebP. | C | — | The crawler requests images with a browser that accepts WebP: WebP is returned. |

#### FR2-26 — Category restructure and article review (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-26-AS-01 [A] | The category tree matches the agreed list exactly. | I | — | Compare the live category tree with the agreed list. |
| TC-FR2-26-AS-02 [A] | Every old category address redirects (301) to its new one. A crawl comparison shows article addresses unchanged. | C | — | Request every old category address: each returns a permanent redirect to its new one. Crawl articles before and after: their addresses are unchanged. |
| TC-FR2-26-AS-03 [A] | The inventory lists every published article and draft, each with a fate, a reason and an owner approval date. | I | — | The inventory has one row for every published article and draft in WordPress, each with a fate, reason and approval date. |
| TC-FR2-26-AS-04 [A] | The change log shows no fate applied without approval. | I | — | Every fate carried out appears in the admin log after its approval date. |
| TC-FR2-26-AS-05 [A] | Each category has a main guide article linking its articles and its track. | C | — | Each category's main guide article links to every article in the category and to its track. |
| TC-FR2-26-AS-06 [A] | The hidden display categories carry noindex. | C | — | Featured, Trending and Video pages carry noindex. |

#### FR2-20 — Blog article SEO clean-up (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-20-AS-01 [A] | Full crawl reports from before and after are saved. | C | — | Both crawl reports are kept in the repository. |
| TC-FR2-20-AS-02 [A] | After the work there are zero serious issues, and no more than 2 % of articles have minor issues. | C | — | The after-crawl shows zero serious issues, and at most 2 % of articles with minor issues. |
| TC-FR2-20-AS-03 [A] | There are no broken internal links, and every retired article returns a redirect or a "gone" page. | C | — | No internal link returns "not found". Every retired address returns a permanent redirect or "gone". |
| TC-FR2-20-AS-04 [A] | Every health article shows its author and reviewer. | C | — | Every health article shows its author and reviewer. |

#### FR2-21 — Answer-engine and AI-assistant optimisation (AEO and GEO) (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-21-AS-01 [A] | Target articles open with a direct answer of 60 words or fewer. | C | — | Count the words of each target article's opening answer: 60 or fewer. |
| TC-FR2-21-AS-02 [A] | FAQ markup passes Google's Rich Results Test. | C | — | Each target page passes Google's Rich Results Test for FAQ markup. |
| TC-FR2-21-AS-03 [A] | llms.txt opens. | C | — | llms.txt returns successfully. |
| TC-FR2-21-AS-04 [A] | Signed out, every member page, member data address and member file returns the sign-in page or "not allowed", never content. robots.txt also excludes them. | S | TM-01 | The signed-out prober requests every member page, member data address and member file: each returns the sign-in page or "not allowed". robots.txt lists them as excluded. |
| TC-FR2-21-AS-05 [A] | Every health article has a dated sources section. | C | — | Every health article has a sources section with dates. |

#### FR2-22 — Fix Google Search Console and Bing Webmaster Tools issues (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-22-AS-01 [A] | Console issue exports are saved, before and after. | C | — | Both console issue exports, before and after, are kept in the repository. |
| TC-FR2-22-AS-02 [A] | Every issue is marked fixed, with the console confirming it, or has a written reason. | C | — | Every issue in the before-export is marked fixed with console confirmation, or has a written reason. |
| TC-FR2-22-AS-03 [A] | Publishing an article sends an IndexNow notice, recorded in a log. | I | — | Publish a test article on staging: an IndexNow notice is logged. |
| TC-FR2-22-AS-04 [A] | The monthly reminder email is sent. | M | TM-20 | On the first Monday of the month, the checklist reminder reaches the owner. |

### Protecting what is already built

#### FR2-23 — Existing calculators unchanged (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-23-AS-01 [A] | The Stage 1 calculation and wording checks pass on every change, and a deliberate calculation change fails the build. | U, I | Stage 1 tests | The Stage 1 calculation and wording checks pass on every change. A deliberate one-character change to a calculation fails the build. |
| TC-FR2-23-AS-02 [A] | The save button appears only for signed-in members with health consent, and saves only the headline result and band. | E | TM-02, TM-03 | After a result, the save button appears for TM-03 and not for TM-02 or a signed-out visitor. The saved record holds only the headline result and band. |
| TC-FR2-23-AS-03 [A] | Signed out, each calculator behaves exactly as before, checked by the Stage 1 safety suite. | E | Stage 1 safety suite | The Stage 1 safety suite passes, signed out, against Stage 2 staging. |

#### FR2-24 — Stage 1 sign-off and release (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-24-AS-01 [O] | The Stage 1 clinical sign-off record is signed and dated at the start of Stage 2 implementation. | O | — | The Stage 1 clinical sign-off record is dated no later than the first Stage 2 build change. |
| TC-FR2-24-AS-02 [A] | A Stage 1 release exists on production before Stage 2 goes live. | C | — | Production shows the Stage 1 release version before the Stage 2 release is deployed. |

### Support and legal

#### FR2-30 — Customer support (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-30-AS-01 [A] | The contact form reaches the owner, showing the plan when the member is signed in. | E, M | TM-04 | Send a message signed in: the owner's email shows the plan. Send one signed out: it arrives without a plan. |
| TC-FR2-30-AS-02 [A] | The help page covers billing, cancelling, refunds, referrals and data. | C | — | The help page has a section for billing, cancelling, refunds, referrals and data. |
| TC-FR2-30-AS-03 [O] | The owner tracks reply times against the 2-working-day aim. | O | — | Each month, the owner records reply times against the 2-working-day aim. |

#### FR2-31 — Legal pages (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-FR2-31-AS-01 [O] | All three legal pages carry an owner approval date earlier than their publication date. | O | — | Each legal page's approval date is earlier than its publication date. |
| TC-FR2-31-AS-02 [A] | The terms of sale cover every listed topic, and are linked from the pricing page and checkout. | C | — | The terms of sale contain each listed topic, and the pricing page and checkout link to them. |

### Quality

#### NFR2-01 — Privacy (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-01-AS-01 [A] | Every table and file store holding personal data appears in the Data Model, with the same fields. Any extra personal field or store fails the test. | S | All | The erasure checker's list of tables and file stores is compared with the live database and file storage. Any extra personal field or store fails. |
| TC-NFR2-01-AS-02 [O] | The data-protection assessment is signed. | O | — | The owner signs the data-protection assessment, and records it. |
| TC-NFR2-01-AS-03 [A] | In a clock-shifted test, the warning goes exactly 30 days before the worked-out deletion date, and the account is deleted on that date unless the member signs in. | M, I | TM-19 | Move the clock: the warning arrives exactly 30 days before the worked-out deletion date, and the account is deleted on that date. Repeat with a sign-in after the warning: the account is kept. |

#### NFR2-02 — Security (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-02-AS-01 [A] | Member A cannot read member B's data through any page or address. Every member route is tried automatically. | S | TM-03, TM-04 | Signed in as TM-03, request every TM-04 page and data address: each is refused. |
| TC-NFR2-02-AS-02 [A] | The database shows hashed passwords only. | I | — | Read the stored passwords: all are hashes. |
| TC-NFR2-02-AS-03 [A] | Admin sign-in requires the email code. A member who opts in is asked for it too. | E, M | TM-20, TM-03 | TM-20 must enter the email code to sign in. TM-03 turns it on, and is then asked for it. |
| TC-NFR2-02-AS-04 [A] | The security scan shows no high or medium findings left open. | S | — | Run the OWASP ZAP scan against staging: no high or medium findings left open. |

#### NFR2-03 — Works on phones (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-03-AS-01 [A] | Screenshots of every journey page at 360, 768 and 1280 pixels show no sideways scrolling and nothing overlapping. | E | All journeys | Screenshots of every journey page at 360, 768 and 1280 pixels are checked for sideways scrolling and overlaps. |
| TC-NFR2-03-AS-02 [A] | Journey tests pass in the WebKit and Chromium engines at phone and desktop sizes. | E | All journeys | The journeys run in the WebKit and Chromium engines at phone and desktop sizes. |
| TC-NFR2-03-AS-03 [O] | The full journey is completed once on a real iPhone with Safari and a real Android phone with Chrome, and recorded. | O | Owner's phones | The owner completes the full journey once on a real iPhone with Safari and a real Android phone with Chrome, and records it. |

#### NFR2-04 — Accessible (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-04-AS-01 [A] | The automated accessibility scan finds no serious or critical issues on any journey page. | X | All journeys | axe runs on every journey page: no serious or critical issues. |
| TC-NFR2-04-AS-02 [A] | A keyboard-only run goes from registration to the dashboard and the food diary, and every focused item is visible. | X, E | TM-01 | A keyboard-only run goes from registration to the dashboard and the diary, with a visible focus at each step. |
| TC-NFR2-04-AS-03 [O] | Every manual item on the WCAG checklist is checked and recorded, including a full run with a screen reader on one phone and one computer. | O | WCAG checklist | Each manual checklist item is checked, including a screen-reader run on one phone and one computer, and recorded. |

#### NFR2-05 — Fast (Should).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-05-AS-01 [A] | The dashboard loads in 2 seconds or less on simulated 4G, taking the median of 5 runs. | F | TM-04 | Load the dashboard 5 times on simulated 4G. The median is 2 seconds or less. |

#### NFR2-06 — Backups and resilience (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-06-AS-01 [O] | A full restore on staging is completed within 1 day and recorded. | O, S | — | Restore a full backup on staging, following Data Model T6. The owner records the time taken, which must be within 1 day. |
| TC-NFR2-06-AS-02 [A] | With each membership plugin switched off in turn on staging, the blog, calculators and landing pages pass the smoke test, and no Premium content, member page or member file can be opened. | E, S | TM-01, TM-04 | Switch off each membership plugin in turn. The Stage 1 smoke test passes, and the signed-out prober finds no Premium content, member page or member file. |
| TC-NFR2-06-AS-03 [A] | With Stripe made unreachable, existing members keep access, and checkout shows the try-again message. | P, E | TM-04, TM-02 | Block the connection to Stripe. TM-04 keeps access. TM-02's checkout shows the try-again message. |

#### NFR2-07 — Safe health content (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-07-AS-01 [A] | No member-facing content exists without an approval record for its current version. | I | CF-09 | Every member-facing content item's current version has an approval record. |
| TC-NFR2-07-AS-02 [A] | Every stress and mood page has an urgent-help link. The snoring advice and the diabetes-medication note are present. | C | — | Every stress and mood page has an urgent-help link. The snoring advice and the diabetes-medication note are present. |
| TC-NFR2-07-AS-03 [A] | A wording check flags phrases such as "cures" or "treats" for the owner to look at. It supports the owner's review and does not replace it. | C, O | — | The wording check lists flagged phrases. The owner reviews each one and records the decision. |
| TC-NFR2-07-AS-04 [O] | The owner's qualifications are recorded with the first content approval. | O | — | The owner's qualifications are present with the first content approval. |

#### NFR2-08 — Owner admin (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-08-AS-01 [O] | The owner completes every listed admin task without a developer, in one checklist run. | O | TM-20 | The owner completes every admin task in the list in one run, and records it. |
| TC-NFR2-08-AS-02 [A] | Preview mode shows exactly what each member state sees. | E | TM-20 | Preview the site as a visitor, a free member and a Premium member. Each preview matches what TM-01, TM-03 and TM-04 actually see. |

#### NFR2-09 — Ready for the Stage 3 app (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-09-AS-01 [A] | A test app connection signs in with a member account and reads that member's goals, track, profile, progress, diary and plan. It cannot read another member's data. | I, S | TM-04, TM-03 | A test app connection signs in as TM-04, reads each data group and updates the profile. Trying to read TM-03's data, create an order, grant Premium, award a reward or change a role is refused. |

#### NFR2-10 — Simple build (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-NFR2-10-AS-01 [A] | Every change runs the Stage 1 calculator checks and the payment tests. | C | — | Open a test pull request: the Stage 1 calculator checks and the payment tests run, and must pass before merging. |

### How it looks

#### UI2-01 — Consistent look (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-UI2-01-AS-01 [A] | Snapshots of the member pages, once approved by the owner, become the baseline, and later visual changes are flagged. | E | Member pages | Take snapshots of the member pages. Once the owner approves them as the baseline, later visual changes are flagged. |
| TC-UI2-01-AS-02 [O] | The owner confirms the look matches the site. | O | — | The owner confirms the look matches the site, and records it. |

#### UI2-02 — Dashboard and track screens (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-UI2-02-AS-01 [A] | At 360 pixels wide, the dashboard order matches FR2-07, and progress reads as "n of 28 done". | E | TM-04 | At 360 pixels wide, the dashboard order matches FR2-07, and progress reads "n of 28 done". |

#### UI2-03 — Pricing and sales pages (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-UI2-03-AS-01 [A] | On a 360 by 640 pixel screen, yearly is shown first, and the price, what's included and how to cancel are visible without scrolling. | E | TM-01 | On a 360 by 640 pixel screen, the pricing page shows yearly first, and the price, what's included and how to cancel, without scrolling. |

#### UI2-04 — Emails (Must).

| Check | What must be true | Kind | Data | How it is tested |
|---|---|---|---|---|
| TC-UI2-04-AS-01 [A] | Emails are 600 pixels wide or less, include the logo, and contain no tracking pixels or remote scripts. | M | All templates | In Mailpit, every email is 600 pixels wide or less, shows the logo, and loads no tracking pixel or remote script. |

## 7. Coverage

Every one of the **209 checks** in the final requirements has exactly one test in section 6. The generator that builds this document stops with an error if a check has no test, or a test has no check.

Number of checks using each kind of test (a check can use more than one):

| Kind | Checks |
|---|---|
| U, Unit | 10 |
| I, Integration | 37 |
| E, End-to-end | 91 |
| P, Payments | 23 |
| M, Email | 25 |
| C, Content and crawl | 25 |
| S, Security and privacy | 31 |
| F, Performance | 4 |
| X, Accessibility | 2 |
| O, Owner check | 23 |

## 8. Records kept from testing

| Record | Where | Kept |
|---|---|---|
| Automated test results per change | GitHub Actions run, linked from the pull request | As GitHub keeps them |
| Nightly and weekly staging results | `reports/stage2/` in the repository, one file per run | Until Stage 3 |
| Crawl, console and PageSpeed reports | `reports/seo/` | Until Stage 3 |
| Owner checks | `approvals/stage2-owner-checks.json` | Permanently |
| Launch evidence | One summary listing every check, its last passing run and date: `releases/stage2.json` | Permanently |

No test record may contain real personal or health data. Test members and sentinel values only.
