# Lifeline Daily — Stage 2 Requirements (final, approved by the owner)

**Stage 2: Membership, personalisation and first paying customers**

**Final. Approved by the owner on 25 October 2026, and amended the same day after a second independent review (decisions D36 to D48). Amended on 27 October 2026 to add the free Healthy Weight Check (FR2-39, decisions D49 to D56).** Includes every change agreed with the owner, the fixes from the independent review of the earlier draft, the ongoing Premium package, the simple food diary and yearly-first pricing. Written in plain language, with test criteria for every requirement.

---

## 1. About this document

This document says what Stage 2 must deliver, in words a non-technical reader can check. Each requirement has a number, a description and its test criteria. A requirement is done only when every one of its checks passes.

Each check is numbered (AS-01, AS-02 and so on, referred to as, for example, FR2-01 AS-03) and marked with how it is proven:

- **[A] Automated.** A test runs it on every change, or on a schedule, and keeps the result.
- **[O] Owner check.** The owner checks it and records the date. These are kept to things only a person can judge.

The detailed test steps and test data belong in the Stage 2 Test Criteria document, which follows the Stage 2 Data Model. That document must not weaken any check listed here.

**Must** requirements are needed before Stage 2 can launch. **Should** requirements are wanted but can move later if time runs short.

Section 7 lists the requirements. Section 8 sets out rules that apply across all of them: data and consent, safety order, programme and access, billing, and data leaving the website. Where a requirement and a section 8 rule overlap, the section 8 rule wins.

Requirement numbers stay the same as in earlier drafts. Requirements added during review take the next free numbers.

## 2. The goal of Stage 2

Stage 2 turns Lifeline Daily from a free blog with free calculators into a website where people sign up, follow a personal health plan and pay for it, ideally yearly. It is the first revenue milestone. Stage 3, the mobile app, is the recurring-revenue growth milestone.

**What Premium sells: "your personal health plan".** It has two parts:

1. **Four starter tracks.** Each track is a four-week programme. All four are available at launch.

| Track | Who it is for | Calculator that leads into it |
|---|---|---|
| Healthy Weight | People who want to reach and keep a healthy weight | Healthy Weight Check |
| Blood Sugar Balance | People with raised diabetes risk or prediabetes who want to lower it | Diabetes Risk Check |
| Fitness and Strength | Adults of any age who want to get fitter and stronger | Daily Protein Target |
| Sleep and Stress | People who want better sleep and less day-to-day stress | — |

2. **Ongoing value, which keeps members subscribing all year:**
   - weekly meal plans with shopping lists;
   - a new challenge every month;
   - progressive workout plans;
   - a simple food diary;
   - long-term progress tracking with milestones;
   - a private members' Facebook group with a monthly live Q&A with the owner;
   - Premium in the Stage 3 app, included for yearly members.

Stage 2 is website only. The site is fully in English and serves a global audience.

**Target launch date: 31 October 2026**, set by the owner.

## 3. What is free and what is paid

| Free for everyone | Free with an account | Premium |
|---|---|---|
| Daily Protein Target calculator | General wellbeing suggestions | Weeks 2 to 4 of every track |
| Diabetes Risk Check and Healthy Weight Check | Health Goal Selector (with health consent) | Weekly meal plans and shopping lists |
| All public blog articles | Personal Health Dashboard | A new monthly challenge |
| Free starter guide for each track | Week 1 of each track, once per track | Progressive workout plans |
| | Food diary during week 1 | Food diary, ongoing |
| | | Weekly Action Plan and Weekly Progress Review |
| | | Long-term progress, charts and milestones |
| | | Members' Facebook group and monthly live Q&A |
| | | Premium-only articles |
| | | Premium in the Stage 3 app (yearly members) |

**Price, yearly first:**

- **US$59.99 a year** is shown first, as the "best value" option. That works out to US$5 a month, a saving of 37 %.
- **US$7.99 a month** is offered as the low-commitment way to start.

One Premium plan covers everything. Stripe's own checkout page shows the price in the customer's local currency. Renewals in local currency follow that month's exchange rate. There is no separate free trial, because week 1 of every track is free.

The calculators, all safety information and the medical disclaimer are never behind the paywall.

## 4. What is not in Stage 2

- The mobile app (Stage 3).
- A full calorie and macronutrient food tracker with a food database (Stage 3). Stage 2 has the simple food diary in FR2-37, and its entries carry over to the Stage 3 tracker.
- Daily logging of blood pressure, activity or sleep (Stage 3). Stage 2 has the weekly check-in in FR2-09.
- Further new calculators (Stage 3 or later). The Healthy Weight Check is part of Stage 2 (FR2-39, decision D49).
- Barcode scanning, recipe calculation, photo analysis, health-app imports, other languages (Stage 4).
- A support chatbot (Stage 4). Stage 2 support is by email (FR2-30).
- AI that writes personal health advice directly for a member (Stage 4). In Stage 2, AI helps the owner, and the owner approves everything it produces.
- Pausing a subscription. A member cancels and rejoins instead. Pausing may be added later.
- Video lessons at launch. Lessons are text, and video can be added later.
- Community features on the website. The members' group runs on Facebook (FR2-35).
- Any change to how the two existing calculators calculate.
- Replacing the website's theme.
- Signing in with Google, Facebook or Apple.
- A new backup system. The Hostinger daily backup is used.
- The Meta (Facebook) Pixel. Tagged links are used instead.

## 5. Who uses Stage 2

| Person | What they do |
|---|---|
| Visitor | Reads articles, uses the calculators, downloads a starter guide, joins a waitlist |
| Free member | Has an account, sees suggestions, and, with health consent, chooses goals and does week 1 of a track |
| Premium member | Pays and gets everything in the Premium column of section 3 |
| Owner | Writes and approves all content, approves AI suggestions, runs the monthly live Q&A, sees reports, handles refunds and support |
| Clinical reviewer | The owner |
| Privacy reviewer | The owner |

The owner holds every role. The owner's account can use every feature and preview the site as a visitor, a free member or a Premium member (NFR2-08).

## 6. How Stage 2 will be built on the existing WordPress site

- **Accounts.** WordPress's own user accounts are used. Members get a "member" account that cannot reach the WordPress admin area.
- **Plans, payments and locked content.** The Paid Memberships Pro plugin, using Stripe's hosted checkout page, handles:
  - plans and checkout;
  - renewals and failed payments;
  - cancellation, discount codes and receipts;
  - locking Premium content.

  Referral rewards are handled as described in section 8.4.
- **Goals, tracks, programme, meal plans, food diary, dashboard and personalisation.** These are built as a small Lifeline Daily plugin.
- **Personalisation in three layers:**
  1. The owner tags content from one managed tag list.
  2. The member's goals, track and profile are matched to the tags by rules the owner can see and change, after the safety rules in section 8.2.
  3. AI helps the owner by suggesting tags, article updates, meal plans and activity matches. The owner approves each one before members see it.
- **Ready for the Stage 3 app.** All member data stays in WordPress and can be shared with the Stage 3 app through a secure connection, so members keep one account.

## 7. Requirements

### 7.1 Accounts and privacy

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-01 | **Registration and sign-in (Must).** A visitor creates an account with an email and a password, using WordPress accounts. **Before first sign-in:** the member must confirm their email. **Passwords:** at least 10 characters, with a show/hide "eye" button on every password box. **Signing in:** "Remember me" keeps a member signed in for 30 days on that device. After 5 wrong passwords, sign-in pauses for 15 minutes. A forgotten password is reset by an email link, and resetting it signs the account out on every other device. **Age:** the visitor enters their age in years. Anyone who enters under 18 is told politely that the service is not for them. No ID check is made. A covering statement says that Lifeline Daily relies on the age the member gives, and that content and results are based on it. | **AS-01** [A] Registering creates an unconfirmed account. Sign-in is refused until the email link is clicked. The link works once and expires after 24 hours. **AS-02** [A] A 9-character password is refused and a 10-character one is accepted. The eye button shows and hides the password on every password box. **AS-03** [A] Five wrong passwords in a row block sign-in for 15 minutes. The message does not reveal whether the email is registered. **AS-04** [A] A password reset link works once and expires after 60 minutes. After a reset, a session left open on a second device is signed out. **AS-05** [A] An age of 17 is refused and nothing is stored. An age of 18 is accepted. The covering statement is shown on the registration page. **AS-06** [A] With "Remember me" ticked, the member is still signed in 29 days later and is signed out after 30. Without it, closing the browser ends the session. |
| FR2-02 | **Member profile (Must).** One place to see and change: name, email, password, age, time zone, goals, track, health profile, email preferences and plan. **Age:** the member can correct it at any time, but not to under 18. It moves forward one year on each anniversary of the day it was entered. **Display name and photo:** both optional. The photo is a JPG, PNG or HEIC of 10 MB or less, resized and stripped of location data. It is shown only to the member, can be removed at any time, and is deleted with the account. **Email changes:** need confirmation from the new address. **Also shown:** the join date and the next renewal date. | **AS-01** [A] Every listed field can be changed, and the change is still there after reloading. **AS-02** [A] A JPG, PNG or HEIC photo of 10 MB or less is accepted. A larger file or another format is refused. The stored photo has no location data, and cannot be opened by a signed-out visitor or another member. **AS-03** [A] An email change takes effect only after the link sent to the new address is clicked. **AS-04** [A] The join date equals the account creation date. The next renewal date matches the active Stripe subscription, or shows "not applicable" for a free account. **AS-05** [A] Changing age to 17 is refused. An age entered 1 year ago shows 1 year higher today. |
| FR2-03 | **Consent (Must).** After confirming their email, and before choosing any goal, the member accepts the terms of use and privacy policy. They then choose, separately, whether to allow Lifeline Daily to store their health information, and whether to receive marketing emails. Both are off by default and can be changed at any time. Before health consent is switched off, the member is warned what will be deleted. Each consent change is recorded with its date. Withdrawing health consent is recorded as an erasure, so a restored backup cannot bring the data or the old consent back (section 8.1). What each consent covers, and what still works without health consent, is set out in section 8.1. | **AS-01** [A] After confirming their email, a member cannot reach the goal step until the terms are accepted. **AS-02** [A] The health and marketing consent boxes start unticked. **AS-03** [A] With health consent off, every attempt to save goals, profile, check-in, diary or saved-result data is refused. **AS-04** [A] Switching health consent off shows the warning. Afterwards no health data for that member remains in any table listed in the Data Model, including health-related survey answers and testimonials. Their diary photos cannot be opened and are removed from file storage. Billing continues unchanged. **AS-05** [A] Every consent change is stored with its date. **AS-06** [A] Health consent is withdrawn, then a backup from before the withdrawal is restored. After the erasure list is re-applied, consent is still withdrawn, no health data has returned, and the site was not reachable by members until this was done. |
| FR2-04 | **Download and delete my data (Must).** **Download:** a member can download all their data as one ZIP file, made when asked for and not kept afterwards. It holds a readable summary, the data itself, and the member's photos, but never passwords or sign-in keys. **Delete:** a member can delete their account after confirming with their password. Deletion closes the account and ends access immediately. Any subscription is cancelled at Stripe in the same step. If that cancellation fails, the owner is alerted and must cancel it by hand within 1 working day. **Removal:** within 24 hours, all personal data is removed from the live site, including uploaded files and support messages. **Kept on purpose:** only the exceptions in section 8.1. **Backups:** Hostinger backups expire within 30 days. Every erasure is recorded on an erasure list, and a copy is emailed to the owner's admin mailbox each day. If a backup is restored, the site stays closed to members until the erasure list is re-applied, so deleted accounts do not come back. **Confirmation:** the member gets a confirmation email. | **AS-01** [A] The ZIP download contains every record held for the test member, checked against every table in the Data Model, with photos included, and no password or key. No copy is left on the server. **AS-02** [A] Deletion asks for the password, ends access at once, and cancels the Stripe test subscription. **AS-03** [A] Within 24 hours, no table, uploaded file, cache or log holds the member's personal data, except the section 8.1 exceptions. **AS-04** [A] When Stripe cancellation is made to fail in a test, the owner receives an alert. **AS-05** [A] Restoring a backup taken before the deletion, then re-applying the erasure list from the owner's mailbox copy, leaves the member deleted. Members could not reach the site until this was done. **AS-06** [A] A deletion confirmation email is sent, and the daily erasure-list email reaches the owner. |

### 7.2 Personalisation

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-05 | **Goals and tracks (Must, needs health consent).** **Choosing:** a member chooses up to three goals, as a short step after consent that they can skip, and can change them at any time. **Paid-track goals:** Healthy Weight, Blood Sugar Balance, Fitness and Strength, Sleep and Stress. **Free goals:** heart health and blood pressure, gut health, healthy ageing, energy. **"Something else":** the list ends with this option, which opens an optional text box. **When a goal appears:** only once it has at least 5 reviewed articles, and, for a track, at least 7 activities. **Owner control:** goals can be added, renamed or removed without a developer. | **AS-01** [A] A fourth goal cannot be selected. **AS-02** [A] Skipping the goal step leads to the dashboard with the general wellbeing path. **AS-03** [A] A goal with 4 reviewed articles is hidden. With 5 articles, and 7 activities for a track goal, it appears. **AS-04** [O] The owner adds, renames and removes a goal without a developer. **AS-05** [A] "Something else" text is saved only when health consent is on. |
| FR2-25 | **Optional health profile (Must, needs health consent).** **Fields, all optional:** sex, height, weight, waist, activity level, diet type and ethnicity. Age comes from the account. **Conditions, from a short list:** type 2 diabetes, prediabetes, high blood pressure, high cholesterol, kidney disease, pregnancy or breastfeeding, eating disorder now or in the past, or none of these. **Calculator results:** the latest saved results can be kept. **Units:** metric or imperial. **Validation:** values outside realistic ranges are queried, and any value can be corrected later. **Missing height:** BMI and waist-to-height ratio are not shown, and the next check-in asks for it once. **Reminder:** the member is reminded once to complete the profile. **Safety:** the safety matrix in section 8.2 applies the moment a profile change is saved. | **AS-01** [A] The profile saves with any combination of fields left blank. **AS-02** [A] Unrealistic values, such as a height of 300 cm or a weight of 5 kg, ask the member to check. **AS-03** [A] Metric and imperial entries store the same value, for example 5 ft 10 in and 177.8 cm. **AS-04** [A] With no height, no BMI or waist-to-height ratio is shown, and the next check-in asks for height once. **AS-05** [A] For every row of the safety matrix, there is one test. After the condition is saved, excluded content cannot be seen, opened or chosen anywhere: dashboard, direct link, swap, plan, meal plan, workout, challenge, badge or download. **AS-06** [A] The profile reminder is sent once only. |
| FR2-38 | **Sleep and Stress safety screening (Must).** Before joining Sleep and Stress, three separate questions are asked, each with its own response, approved by the owner as clinical reviewer. **Thoughts of self-harm:** immediate help is shown: "If you are in danger now, call your local emergency number", with common examples (999 in the UK, 911 in the US and Canada, 112 in the EU and India, 000 in Australia), and the global helpline directory findahelpline.com to find local support in any country. The track is not offered in that session, and this response overrides any other answer. **Panic attacks:** advice to see a doctor is shown first, then the member may continue. **Low mood for 2 weeks or more:** advice to see a doctor is shown, then the member may continue. **What is kept:** answers are used only for that decision and are not stored. **Repeats:** the screening is repeated each time the track is started. **Breathing exercises:** no breath-holding exercise is used anywhere on Lifeline Daily, for any member, so no answer needs to be remembered. | **AS-01** [A] Answering yes to self-harm, alone or with other answers, shows the emergency message with its examples and findahelpline.com, and no track offer appears in that session. **AS-02** [A] Answering yes to panic shows doctor advice and then allows the member to continue. **AS-03** [A] Answering yes to low mood shows doctor advice and then allows the member to continue. **AS-04** [A] After screening, no screening answers exist in the database or the logs. **AS-05** [A] The screening appears every time the track is started, including after signing out and on another device. **AS-06** [A] No content anywhere carries the breath-holding tag, and saving content with that tag is refused. **AS-07** [O] The owner approves the wording of all three responses. |
| FR2-06 | **Content personalisation engine (Must).** **Order:** suggestions follow the order in section 8.2: safety, then access, then matching. **What appears:** up to 6 suggestions on the dashboard, with a "see more" link. They mix articles, activities, meal ideas and tools, including at least one tool where relevant. Each shows a one-line reason. **Repeats:** an item opened in the last 30 days is not repeated, unless fewer than 6 safe items remain; then the oldest-opened come back first. **"Not for me":** hides that one item only. **Running short:** gaps are filled from a general wellbeing path, labelled as such. If nothing safe is left, a short message says so, and an empty list is allowed. Members without health consent, or who chose only "Something else", get the general path. **Owner reports:** a monthly report of "Something else" answers grouped by theme, handled as section 8.5 describes, and a weekly note of goals short of content. **New goals:** when a goal goes live, members who asked for it are emailed if they agreed to emails. **Reviewed content only:** only reviewed articles are suggested. | **AS-01** [A] The dashboard never shows more than 6 suggestions, and each one has a reason line. **AS-02** [A] An item opened within 30 days is not shown while at least 6 others are available. The oldest-opened items return first when fewer remain. **AS-03** [A] "Not for me" hides that one item, and similar items still appear. **AS-04** [A] Content excluded by safety rules never appears, even when the list would be empty, and the empty-state message is shown. **AS-05** [A] An unreviewed article is never suggested. **AS-06** [A] The monthly "Something else" report and the weekly short-content note reach the owner. **AS-07** [A] When a matching goal goes live, members who asked for it are emailed, only if they consented to emails. |
| FR2-07 | **Personal Health Dashboard (Must).** Order on the screen: greeting, then today's activity (track name and week), then this week's plan, today's meals, food diary, progress, suggestions and membership. Free members see a short "what Premium adds" box after progress. Monthly members see a "switch to yearly" prompt after their first month. It works well on a phone. | **AS-01** [A] Dashboard sections appear in the stated order. **AS-02** [A] A free member sees the "what Premium adds" box, and a Premium member does not. **AS-03** [A] A monthly member past their first month sees the switch-to-yearly prompt, and a yearly member does not. **AS-04** [A] The dashboard is fully usable on a 360-pixel-wide screen. |

### 7.3 The Healthy Habits Programme and ongoing Premium

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-08 | **Programme engine and four tracks (Must).** One engine runs the tracks and the monthly challenges. **Each track has:** 4 weekly themes, 4 lessons of 5 minutes or less, 28 daily activities and 4 weekly tasks. Lessons are text, and video can be added later. **Rules:** progression, switching, repeating, completion and timing follow section 8.3. **Owner control:** the owner can add and edit content at any time. **Approval:** all content is approved by the owner, as clinical reviewer, before it is published. An approval covers one exact version. After any edit, members keep seeing the last approved version until the new one is approved. | **AS-01** [O] Each of the four tracks has 4 themes, 4 lessons of no more than 1,000 words, 28 activities and 4 tasks, all with an approval record. **AS-02** [A] A free member cannot open day 8 of a track. A Premium member can. **AS-03** [A] Content the owner edits appears to members without developer work, once approved. **AS-04** [A] Content without an approval record is never shown to members. **AS-05** [A] After an approved activity's instructions are edited, members still see the old approved text, and the new text appears only after re-approval. |
| FR2-09 | **Progress and weekly check-in (Must, needs health consent).** **Activity progress:** each activity is marked done or skipped, up to 2 days late. The dashboard shows activities completed, days in a row and weekly consistency. **Weekly check-in**, optional, uses measures that depend on the track. **Healthy Weight:** weight and waist, with BMI and waist-to-height ratio worked out and classed as in FR2-39. **Blood Sugar Balance:** weight, waist, waist-to-height ratio and minutes of activity. **Fitness and Strength:** a 30-second chair stand count, push-ups, minutes of activity and number of strength sessions. The chair stand comes with safety instructions: use a sturdy chair with no wheels, placed against a wall; stop if dizzy or in pain; skip it if you have balance problems, a recent injury or a doctor's restriction. **Sleep and Stress:** average sleep hours, sleep quality from 1 to 5 and stress level from 1 to 5. Three owner-written questions are also asked at the start and at the end of week 4. These are labelled as a personal reflection, not a validated measure. **Not asked:** blood glucose. **What the member sees:** their own numbers, the change since week 1 and a small chart, with plain guidance on recommended ranges. They never see a diagnosis. **Evidence:** the evidence and limits of each measure are in Appendix B. | **AS-01** [A] An activity can be marked up to 2 days late, but not 3. **AS-02** [A] Each track's check-in shows exactly the measures listed for it, and no others. **AS-03** [A] BMI and waist-to-height ratio are correct for 10 test cases. **AS-04** [A] The chair stand safety instructions appear before the test every time. **AS-05** [A] Charts show the change since week 1. A forbidden-words check finds no diagnosis wording. **AS-06** [A] Blood glucose is not requested anywhere. **AS-07** [A] Without health consent, the check-in is not available. |
| FR2-10 | **Personalised Weekly Action Plan (Must, Premium).** **What it is:** each week, 3 to 5 actions from the track's library, matched to goals, profile and last week's completion, after the safety matrix. **How completion counts:** activities done divided by activities available that week. Skipped activities count as not done, and a missing check-in does not change the plan. **Difficulty:** below 50 % gives an easier plan, 50 % to 80 % inclusive keeps the same level, and above 80 % swaps one action for a stretch action, so the plan never exceeds 5. **Not enough actions:** if fewer than 3 safe actions exist, the plan shows those available with a short note. If none exist, the plan says so. **Competing goals:** the member's current track comes first, then other goals in the order chosen. **Swapping:** the plan can be swapped once a week. **Library:** the owner writes the library and the rules. | **AS-01** [A] Each week's plan has 3 to 5 actions, and never more than 5. **AS-02** [A] Completion of 49 % gives an easier plan. 50 % and 80 % keep the same level. 81 % replaces one action with a stretch action, keeping the count the same. **AS-03** [A] A missing check-in does not change the plan. **AS-04** [A] One swap a week is allowed, and a second is refused. **AS-05** [A] A free member receives no plan. **AS-06** [A] With only 2 safe actions, the plan shows 2 and the note. With none, it shows the empty-plan message. |
| FR2-11 | **Weekly Progress Review (Must, Premium).** At the end of each week, the dashboard shows what the member completed, the change in their check-in and diary, and 1 or 2 next steps. It always ends encouragingly and never shames a missed week. The week-4 review suggests the next track or the monthly challenge. **The email is neutral:** "Your weekly review is ready", with a link. It contains no results, numbers or health details. Free members see a shorter review on the dashboard with an invitation to upgrade. | **AS-01** [A] The review appears on day 7 of each track week. **AS-02** [A] The review email contains only the "ready" notice and a link. A content check finds no numbers or health words. **AS-03** [A] The week-4 review suggests the next track or the monthly challenge. **AS-04** [O] The owner confirms the review wording is encouraging. |
| FR2-12 | **Programme emails and reminders (Must).** **Transactional emails, always sent:** account, payment, receipt and deletion emails. **Other emails, each opt-in and changeable:** a welcome email; a daily reminder at a time the member chooses, in their own time zone; the weekly-review-ready notice; and the end-of-track email. **Nudges:** after 5 days without signing in, one "pick up where you left off" email is sent. If there is still no sign-in 5 days later, a second is sent. After that, nudges stop until the member signs in again. **Unsubscribing:** every non-transactional email has one-click unsubscribe. **Content:** emails never contain calculator results, check-in numbers, diary entries or other health details. | **AS-01** [A] The daily reminder arrives within 10 minutes of the chosen time, in the member's own time zone, including on the days clocks change. **AS-02** [A] The first nudge goes 5 days after the last sign-in, the second 5 days later, and none after that until the member signs in. **AS-03** [A] Every non-transactional email has a working one-click unsubscribe. **AS-04** [A] A scan of every email template filled with test data finds no health details. **AS-05** [A] Opt-in emails are sent only after the member has chosen them. |
| FR2-32 | **Weekly meal plans and shopping lists (Must, Premium).** **What it is:** each week, 7 days of meals matched to the member's track, diet type and safety rules. For example, blood-sugar friendly for Blood Sugar Balance, protein at every meal for Fitness and Strength, and no weight-loss portions where a safety rule applies. **Shopping list:** printable, with a downloadable PDF. **Swapping:** the member can swap any meal for an alternative. **How plans are made:** built from an owner-approved recipe library; AI may draft recipes and plans, and the owner approves them. **At launch:** at least 4 weeks of plans per track, then new weeks added ahead of members' progress. | **AS-01** [A] A Premium member receives 7 days of meals each week, matching their track and diet type. **AS-02** [A] Safety rules are respected. For example, a pregnancy flag gives no weight-loss portions. **AS-03** [A] The shopping list prints and downloads as a PDF. **AS-04** [A] Any meal can be swapped for an alternative. **AS-05** [O] At launch there are at least 4 weeks of plans per track, all approved. **AS-06** [A] A free member cannot open the meal plans. |
| FR2-33 | **Monthly challenge (Must, Premium).** **What it is:** a new 4-week challenge every month, for example "28-day fibre boost", "Strength in 20 minutes" or "Sugar reset". Each has daily actions, a weekly review and a finish badge, and runs on the programme engine. **Who can join:** any Premium member, whether or not they are in a track. Without health consent, a member can follow a challenge, but no progress is recorded. **At launch:** the first challenge is ready. The owner approves each new challenge before the month starts. | **AS-01** [O] The first challenge is ready and approved at launch. **AS-02** [A] A Premium member can join a challenge with or without a track, and receives a badge on finishing. **AS-03** [A] An unapproved challenge is not visible to members. **AS-04** [A] A Premium member without health consent can open every challenge day, and nothing about their progress is stored. |
| FR2-34 | **Progressive workout plans (Must, Premium).** **What it is:** workout plans at three levels: beginner, intermediate and active. Each has a home version with no equipment and a version with basic equipment, and steps up each week. **Each workout:** simple step-by-step instructions, a built-in timer, and safety advice on warming up, stopping if in pain and checking with a doctor if needed. Without health consent, workouts can be used, but no progress is recorded. **At launch:** at least 4 weeks per level. | **AS-01** [O] At launch there are 3 levels, each with a home and an equipment version, and at least 4 weeks each. **AS-02** [A] The timer works, and the safety advice appears before each workout. **AS-03** [O] The owner confirms each week steps up from the one before. **AS-04** [A] A Premium member without health consent can use every workout, and nothing about their progress is stored. |
| FR2-35 | **Members' Facebook group and monthly live Q&A (Must, Premium).** **The group:** a private Facebook group for Premium members only. Joining is optional, and the dashboard explains that Facebook's own privacy terms apply there. The owner approves join requests against the member list. People whose Premium has ended are removed within 7 days, and each removal is recorded. **The live Q&A:** a one-hour session each month, held as a Facebook Live in the group, with the date shown on the dashboard. No personal health advice is given live. | **AS-01** [O] The group is private, and only Premium members are admitted, checked against an exported member list. **AS-02** [A] The dashboard shows the next live Q&A date and the Facebook privacy notice. **AS-03** [A] Each week the owner receives the list of people whose Premium has ended. Each removal is recorded with its date, and the log shows none overdue beyond 7 days. |
| FR2-36 | **Long-term progress and milestones (Must, Premium).** After a track ends, a monthly check-in continues. It uses the measures of the last track the member finished, and the member can change which measures they track. Charts cover all months since joining. Milestone badges mark points such as "5 kg down", "90 days active" or "first challenge finished". Weight-loss badges are not shown to members with a pregnancy or eating-disorder flag. | **AS-01** [A] The monthly check-in is available after a track ends, showing the measures of the last track finished. The member can change them. **AS-02** [A] Charts cover every month since joining. **AS-03** [A] Badges are awarded at their stated thresholds. Weight-loss badges never appear for members with a pregnancy or eating-disorder flag. |
| FR2-37 | **Simple food diary (Must, needs health consent; free during each free week, then Premium).** **Logging each meal:** breakfast, lunch, dinner and snacks, each in a few taps. A Premium member taps "ate the planned meal" or writes a short description. A free member writes a short description. Either may add a photo: a JPG, PNG or HEIC of 10 MB or less, resized, stripped of location data, private to the member, and deleted with the account. **Quick checks per meal:** vegetables yes or no, a protein source yes or no, a sugary drink yes or no, and portion as small, normal or large. **Each day:** glasses of water, plus hunger and energy ratings from 1 to 5. **No calories:** calorie numbers are never shown. **Eating-disorder flag:** members with this flag do not see portion questions. **Weekly summary:** shown on the dashboard, for example "Vegetables at 9 of 14 meals". **Track focus:** Blood Sugar Balance highlights sugary drinks and refined carbohydrates; Fitness and Strength highlights protein at each meal. **Free members:** the diary is open during each track's free week. Outside those weeks it is read-only, and history is always kept. **Privacy and carry-over:** entries are never emailed, are deleted with the account, and carry over to the Stage 3 tracker. | **AS-01** [A] A Premium member can log a meal in 3 taps or fewer using "ate the planned meal". A free member can log a meal by description. **AS-02** [A] No calorie numbers appear anywhere in the diary. **AS-03** [A] The eating-disorder flag hides the portion questions. **AS-04** [A] The weekly summary counts are correct for a prepared test week. **AS-05** [A] A free member finishes one track's free week and the diary becomes read-only. Starting another track's free week reopens it, and earlier entries are still there. **AS-06** [A] Diary entries never appear in emails, are deleted with the account, and are included in the data download. **AS-07** [A] A diary photo of a refused format or over 10 MB is rejected. A stored photo has no location data and cannot be opened by anyone except its member. |

### 7.4 Paid membership

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-13 | **Plans and prices (Must).** **Plans:** Free and Premium. Premium is US$59.99 a year, shown first as "best value", or US$7.99 a month. Prices are shown **including any sales tax**, so the customer pays the price shown. It covers everything in the Premium column of section 3. **The app:** yearly members get Premium in the Stage 3 app at no extra cost. **Owner control:** the owner can change prices and create discount codes. **At launch:** a code gives 50 % off the first month and is valid for 30 days. **Other rules:** price locks, discounts and referrals follow section 8.4. | **AS-01** [A] The pricing page shows US$59.99 a year first, marked "best value", and US$7.99 a month second, both marked "tax included". **AS-02** [A] For test customers in the UK, an EU country and the US, the amount charged equals the price shown, with tax inside it. **AS-03** [A] The launch code gives 50 % off the first month only, and stops working after 30 days. **AS-04** [A] The owner can change prices and create codes from the admin screen. **AS-05** [A] Yearly members are recorded as entitled to Premium in the Stage 3 app. |
| FR2-14 | **Payments, renewals and cancellation (Must).** **Payment:** through Stripe's hosted checkout page via Paid Memberships Pro. Card details never touch the website. Stripe Tax works out the tax inside the price for each country. Apple Pay and Google Pay are offered on devices that support them. **Renewals:** automatic, with a receipt each time. Yearly members get a reminder 7 days before renewal. **Switching monthly to yearly:** takes effect at the next monthly renewal. Until then, it can be cancelled. **Failed payments:** retried for 7 days, with emails. Then Premium ends, following section 8.3. **Cancelling:** a few clicks from the dashboard. Access continues to the end of the paid period. **Refunds:** of the first payment within 14 days, issued by the owner from the admin screen. **Payment notices:** Stripe notices are checked as genuine before use. A notice repeated or arriving out of order never grants or removes access wrongly. A payment that does not match a member's access is flagged to the owner daily. | **AS-01** [A] Checkout runs on Stripe's hosted page, and no card fields exist on the website. **AS-02** [A] A test customer from another country sees the price in their local currency. Apple Pay appears in Safari on an Apple device, and Google Pay in Chrome where set up. **AS-03** [A] Each renewal creates a receipt. Yearly members receive the reminder 7 days before renewal. **AS-04** [A] A failed payment is retried for 7 days, then Premium ends as section 8.3 describes. **AS-05** [A] Cancelling takes 3 clicks or fewer from the dashboard, and access continues to the end of the paid period. **AS-06** [A] A refund within 14 days can be issued from the admin screen. **AS-07** [A] A forged notice is rejected. A repeated notice changes nothing. A cancellation notice arriving before its payment notice leaves the correct final state. A deliberate mismatch is flagged to the owner the next day. **AS-08** [A] A pending monthly-to-yearly switch can be cancelled before the renewal date, and the member then stays monthly. |
| FR2-15 | **What Premium unlocks (Must).** Access for each member state is set out in section 8.3, including what happens when Premium ends or is refunded. Premium content unlocks immediately after payment. A locked page shows a short preview and an upgrade button. The calculators, safety information and disclaimer are never locked. | **AS-01** [A] Access for one test member in each state matches the section 8.3 table exactly. **AS-02** [A] Premium unlocks within 1 minute of payment. **AS-03** [A] A locked page shows a preview and an upgrade button. **AS-04** [A] The calculators, safety information and disclaimer open for a signed-out visitor. **AS-05** [A] Premium is ended mid-week by expiry, and separately by refund. In both cases, the rest of the current track week stays open until its last day, and every other Premium item locks at once. On the next day nothing new opens. |
| FR2-16 | **Sales pages and upgrade journey (Must).** **Pages:** a general pricing page, with yearly shown first, and one sales page per track. Each sales page shows the weekly themes, a sample activity, a sample meal plan day, who it is for and not for, and testimonials once available. Each is written to rank in search. **Upgrade invitations:** after week 1, on the dashboard and in the weekly review, never pushy. **Below each calculator:** on its landing page, a gentle link to the related track. There is no price or upsell on calculator results. | **AS-01** [A] The pricing page and the four sales pages exist with every required section. **AS-02** [A] No price or upsell appears inside calculator results, and the track link sits below each calculator. **AS-03** [A] Each sales page has its own title and description and is listed in the sitemap. **AS-04** [O] The owner confirms the upgrade invitations are not pushy. |

### 7.5 Growth and measurement

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-17 | **Conversion analytics (Must).** **The report:** one page, emailed to the owner each Monday. **Per track:** visits, calculator use, registrations, track starts, week 1 completions, upgrades, monthly-to-yearly switches, renewals, cancellations and cancellation reasons. **Overall:** monthly recurring revenue, active Premium members, and the yearly and monthly split. **Sources:** the first tagged link a person arrives by, such as a Facebook post, is saved on their account when they register. Renewals and cancellations are traced back to that source inside the website, never through analytics. A sign-up or payment is credited once only. The funnel comes from Plausible and payments from Stripe. **Privacy:** only the events and fields on the section 8.5 allowlist may be sent to analytics. | **AS-01** [A] The Monday report arrives with every listed figure for a prepared test week. **AS-02** [A] Report figures match Stripe and Plausible for the test data. **AS-03** [A] A sign-up through a tagged link is credited once, and that member's later renewal and cancellation are reported under the same source. **AS-04** [A] Every request sent to Plausible during a full test journey matches the section 8.5 allowlist exactly. Anything else fails the test, as does a web address carrying an email, a name or a profile value. |
| FR2-18 | **Free starter guides and email sign-up (Must).** **Guides:** one free guide per track, offered on matching articles and below each calculator. **Sign-up:** double opt-in, with an unsubscribe link in every email. **Sending:** through the website's mail system at first, moving to a mailing service if the list passes about 1,000 people. | **AS-01** [A] Each track's guide is delivered only after the double opt-in link is clicked. **AS-02** [A] Unsubscribe works. **AS-03** [A] The guide offer appears on matching articles and below the calculators. |
| FR2-19 | **Feedback and testimonials (Must).** **Week 1:** members are asked how likely they are to recommend Lifeline Daily, from 0 to 10, plus one open question. **Week 4:** the same, plus "what changed for you?" and permission to quote them by first name and country. **Testimonials:** appear only after owner approval and never claim medical results. | **AS-01** [A] The surveys appear at the end of week 1 and week 4. **AS-02** [A] A testimonial is not visible until the owner approves it, and shows only first name and country. **AS-03** [O] No published testimonial claims a medical result. |
| FR2-28 | **Waitlist pages (Must).** Before launch, each track has a "coming soon" page with a waitlist, using the starter-guide sign-up. At launch, waitlist members who confirmed their sign-up and have not unsubscribed are emailed with the launch discount. | **AS-01** [A] Four waitlist pages are live before launch, and sign-up works on each. **AS-02** [A] The launch email reaches every confirmed, still-subscribed waitlist contact, and no unconfirmed or unsubscribed contact. |
| FR2-29 | **Referral reward (Must).** Each member has a personal referral link on their dashboard. The reward is a discount on the member's next payment, worth the same in any currency. Rewards follow section 8.4. | **AS-01** [A] Each member's dashboard shows their referral link. Each worked example in section 8.4 passes as a test. **AS-02** [A] A fourth referral in the same calendar year earns no reward, and a payment refunded within 14 days earns none. **AS-03** [A] Referring yourself, by the same email or the same card, earns no reward. **AS-04** [A] If processing stops after the referrer is rewarded, retrying rewards the friend without rewarding the referrer twice. **AS-05** [A] For a member billed in local currency, the reward reduces the next payment by the stated share in that currency. |

### 7.6 Website health, content and search visibility

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-27 | **WordPress site health and speed (Must).** **Measure first:** the homepage, an article, a category page and a landing page, on mobile, with Google PageSpeed. **Fix, without a new theme:** remove unused plugins (14 are active today) and unused Newspaper demo content and menus; convert images to WebP; tune the cache; fix PHP errors in the server log; update plugins after testing on staging. **Target:** Core Web Vitals "good" on mobile for all four page types. Real-visitor data from Google is used once enough exists. Until then, a scripted lab test is used, which also performs a set click on each page to measure responsiveness. | **AS-01** [A] Mobile PageSpeed reports for the four page types are saved, before and after. **AS-02** [A] Lab test with a simulated mid-range phone on 4G, with a scripted tap on the main menu and one in-page button: loading under 2.5 seconds, layout shift under 0.1, and response to the tap under 200 milliseconds on all four page types. Results are labelled as lab results. **AS-03** [A] Once Google reports real-visitor data, all four page types show "good". Until then the report says the data is not yet available. **AS-04** [A] Unused plugins are removed, and the server log shows no PHP errors over 24 hours. **AS-05** [A] Images are served as WebP. |
| FR2-26 | **Category restructure and article review (Must).** **New categories (subcategories in brackets):** Weight & Healthy Eating (Healthy Eating, Nutrition, Weight Management); Blood Sugar & Metabolic Health (Diabetes Prevention, Metabolic Health); Fitness & Strength (Exercise, Strength, Movement); Sleep & Stress (Sleep, Stress, Mental Wellbeing); Heart Health (Blood Pressure, Cholesterol); Gut Health; Healthy Ageing (Longevity); Sex & Relationships, its own top-level category; Health Conditions (Other conditions). **Redirects and addresses:** old category pages redirect to the new ones, and article addresses do not change. **Display and retired categories:** Featured, Trending and Video stay as hidden display categories, kept out of search. The Health Intelligence Report category is removed and redirected. **Fate of every article:** all published articles and drafts (103 and 102 as of 23 October 2026) go into one inventory, each with a proposed fate and reason. **Published:** kept, updated, merged (redirect), or retired (redirect to the closest good article, or a "gone" page when none fits). **Drafts:** published after update, merged, held, or discarded to the WordPress bin. **How fates are decided:** by fit to a category and track, medical currency, search demand, duplication and quality. AI proposes each fate, the owner approves it, and nothing changes without approval. **Order of work:** most-visited and track-related articles first. **Hub articles:** each category gets one main guide article linking to its articles and its track. **New articles:** about 4 a month per track category, filling the gaps shown by "Something else" answers and search queries. | **AS-01** [A] The category tree matches the agreed list exactly. **AS-02** [A] Every old category address redirects (301) to its new one. A crawl comparison shows article addresses unchanged. **AS-03** [A] The inventory lists every published article and draft, each with a fate, a reason and an owner approval date. **AS-04** [A] The change log shows no fate applied without approval. **AS-05** [A] Each category has a main guide article linking its articles and its track. **AS-06** [A] The hidden display categories carry noindex. |
| FR2-20 | **Blog article SEO clean-up (Must).** **Crawls:** a full crawl before and after, with both reports kept. **What gets fixed:** all serious issues and most minor ones, leaving no more than 2 % of articles with minor issues. **Issues covered:** titles and descriptions, broken links, image descriptions, orphan articles, and links to the matching calculator and track. Health articles show author and reviewer. **Removed articles:** retired or merged articles redirect, or show a "gone" page, never "page not found". | **AS-01** [A] Full crawl reports from before and after are saved. **AS-02** [A] After the work there are zero serious issues, and no more than 2 % of articles have minor issues. **AS-03** [A] There are no broken internal links, and every retired article returns a redirect or a "gone" page. **AS-04** [A] Every health article shows its author and reviewer. |
| FR2-21 | **Answer-engine and AI-assistant optimisation (AEO and GEO) (Must).** **Article openings:** main articles and calculator pages open with a direct answer of 60 words or fewer, followed by marked-up frequently asked questions. **llms.txt:** a file describes the site for AI assistants. **AI crawler access:** the main AI assistants may read public articles. Member pages are protected by sign-in, and are also excluded in robots.txt. **Sources:** every health article lists dated sources. | **AS-01** [A] Target articles open with a direct answer of 60 words or fewer. **AS-02** [A] FAQ markup passes Google's Rich Results Test. **AS-03** [A] llms.txt opens. **AS-04** [A] Signed out, every member page, member data address and member file returns the sign-in page or "not allowed", never content. robots.txt also excludes them. **AS-05** [A] Every health article has a dated sources section. |
| FR2-22 | **Fix Google Search Console and Bing Webmaster Tools issues (Must).** Both consoles are already verified. **Working through issues:** export each console's issue list, fix every issue or record a written reason, and confirm the fixes in both. **IndexNow:** tells Bing, which powers Edge search, about new and updated articles. **Monthly checklist:** under 30 minutes, sent to the owner as a reminder email. **Access:** the owner gives restricted-user access or exports. | **AS-01** [A] Console issue exports are saved, before and after. **AS-02** [A] Every issue is marked fixed, with the console confirming it, or has a written reason. **AS-03** [A] Publishing an article sends an IndexNow notice, recorded in a log. **AS-04** [A] The monthly reminder email is sent. |

### 7.7 The calculators

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-23 | **Existing calculators unchanged (Must).** **Unchanged:** both calculators stay free and work without an account. Their calculation code and wording are not changed. **Saving results:** a small addition to each calculator page, outside the calculation, sends the finished result to its landing page. The two calculators already use this kind of page-to-page message to set their height. Below the calculator, a signed-in member with health consent sees "Save this result to my profile". Only the headline result and band are saved, never the answers entered. **Track link:** the related-track link sits in the same place. The Healthy Weight Check (FR2-39) gets the same save button and track link. **Protection:** the Stage 1 automated checks run on every change and fail the build if the calculation or its wording changes. | **AS-01** [A] The Stage 1 calculation and wording checks pass on every change, and a deliberate calculation change fails the build. **AS-02** [A] The save button appears only for signed-in members with health consent, and saves only the headline result and band. **AS-03** [A] Signed out, each calculator behaves exactly as before, checked by the Stage 1 safety suite. |
| FR2-39 | **Healthy Weight Check (Must, free, no account).** A third free calculator, replacing the "Healthy weight (planned)" card. **What it works out:** BMI to 1 decimal place and its category; the waist-to-height ratio to 2 decimal places and its band, when a waist is given; and the healthy weight range for the height, labelled "a guide for your height, not a target". **Method (NICE NG246, 2025):** standard BMI categories: under 18.5 underweight, 18.5 to 24.9 healthy, 25.0 to 29.9 overweight, 30.0 or more obesity (class 2 from 35.0, class 3 from 40.0). For a South Asian, Chinese, other Asian, Middle Eastern, Black African or African-Caribbean family background: 18.5 to 22.9 healthy, 23.0 to 27.4 overweight, 27.5 or more obesity (class 2 from 32.5, class 3 from 37.5, the usual 2.5 reduction). Waist-to-height ratio: 0.40 to 0.49 healthy, 0.50 to 0.59 increased, 0.60 or more high; below 0.40 the ratio is shown with no band; at a BMI of 35.0 or more the ratio is not shown. **Rounding:** BMI and ratio are rounded half-up to the places shown, and the category and band follow the value shown, so the label always matches the number. The healthy range covers every weight whose BMI, as shown, is in the healthy category (18.5 to 24.9, or 22.9), worked out from the height and rounded inwards to 0.1 kg, or to whole pounds in imperial, so each end is itself classed as healthy. **Questions, in order:** age in whole years (18 to 99); pregnant or breastfeeding (yes, no, not sure), asked of everyone, with no sex question; an eating disorder now or in the past, or worries about eating (yes, no, prefer not to say); height (120 to 230 cm) and weight (25 to 300 kg), metric or imperial; waist, optional (50 to 200 cm), with measuring instructions; family background (White; Indian; Pakistani; Bangladeshi; Other South Asian; Chinese; Other Asian; Middle Eastern; Black African; Black Caribbean (African-Caribbean); Mixed or other, which asks whether the background includes any of the listed groups; Prefer not to say, which uses the standard thresholds with a note); weight lost in recent months without trying (yes or no). **Safety, stops first:** under 18, pregnant or breastfeeding (yes or not sure), and an eating-disorder answer of yes each stop with an owner-approved message and no numbers; the eating-disorder message links findahelpline.com. Unintended weight loss shows a see-your-doctor banner above the result. Underweight, obesity class 3 and age 65 or over each add an owner-approved note. Results never show calorie numbers, a weight-loss target, a price, an upsell or diagnosis wording, and always show the method, limits, sources, version and disclaimer. **Onward links:** the track link and starter guide below the calculator are hidden after a stop, an underweight result or the weight-loss banner, and for a member whose profile excludes the Healthy Weight track (section 8.2). **Data:** answers stay in the browser and are never stored or sent. Saving (FR2-23) is a separate click for a signed-in member with health consent and sends only the BMI, its unit, its category and the approved version. No email of results. **Known differences:** the Daily Protein Target keeps its frozen cut-offs, which lower the range for Asian backgrounds only, and the Diabetes Risk Check keeps its frozen waist-to-height bands; both are explained in this tool's method section (D56). **Protection and release:** the same freeze, wording fingerprints, safety suite, independent reference calculation (ORC-05) and withdrawal switch as the other calculators. It reaches production only after the owner's clinical sign-off of the exact version. | **AS-01** [A] BMI and its category are correct for both threshold sets at every boundary and rounding transition, and agree with the independent reference on every case. **AS-02** [A] The waist-to-height ratio and its band are correct, no ratio is shown at a BMI of 35.0 or more, and the band agrees with the Diabetes Risk Check across their shared range. **AS-03** [A] The healthy weight range for the height is correct, rounded inwards, and every weight at its ends is classed as healthy. **AS-04** [A] Imperial entries give the same result as their metric twins. **AS-05** [A] Missing or invalid values show the field error and an error summary, and no result. A missing waist shows the "add your waist" note; an invalid waist is an error. **AS-06** [A] An age of 17 or 100 shows its message and no result. **AS-07** [A] Pregnant or breastfeeding, answered yes or not sure, stops with the midwife message and no numbers. The question is asked of everyone, and there is no sex question. **AS-08** [A] An eating-disorder answer of yes stops with the support message and findahelpline.com, and no numbers, at any BMI. **AS-09** [A] Unintended weight loss shows the see-your-doctor banner as an alert above the result, and every stop takes priority over every banner. **AS-10** [A] Underweight, obesity class 3 and age 65 or over each show their required note, and the underweight wording has no weight-loss words. **AS-11** [A] No result shows a calorie number, a weight-loss target, a price, an upsell or diagnosis wording, and every result shows its method, sources, version, limits, disclaimer and waist instructions. **AS-12** [A] The calculator and its wording are frozen. Deliberate changes to arithmetic, a threshold, a safety rule or a safety message each fail the build, and the two older calculators' protections still fail on their own deliberate changes. **AS-13** [A] When withdrawn, the hub card, landing page and direct address show the unavailable state with no usable form, saving is refused, and production deploy fails without the owner's sign-off of the exact version. **AS-14** [A] Saving stores only the headline, unit, band and version, is offered only to signed-in members with health consent after an eligible result, is cleared by any edit, ignores messages from any other frame, and no request, address or log carries an answer. **AS-15** [A] The track link and starter guide below the calculator are hidden after every stop, an underweight result and the weight-loss banner, and for a member whose profile excludes the Healthy Weight track; otherwise they are shown. **AS-16** [A] The Health Tools page shows three live calculator cards and no "planned" card. **AS-17** [A] The landing page has its own title, description and canonical address, is in the sitemap, embeds one calculator with valid structured data and a working related-article link, and the calculator still works with the membership plugin switched off. **AS-18** [A] The tool is part of the phone and accessibility journeys: keyboard-only use, focus on errors and results, 320-pixel reflow, 360/768/1280-pixel screens, WebKit and Chromium, and no serious accessibility findings. **AS-19** [O] The owner approves the thresholds, the background mapping (including the mixed-background question) and all clinical wording, recorded with the NICE NG246 version and the date it was read. **AS-20** [O] A screen-reader run of the tool on one phone and one computer is completed and recorded. |
| FR2-24 | **Stage 1 sign-off and release (Must).** The owner gives the Stage 1 clinical sign-off at the start of Stage 2 implementation, using the prepared review pack. Stage 1 is released to the live site before Stage 2 goes live. | **AS-01** [O] The Stage 1 clinical sign-off record is signed and dated at the start of Stage 2 implementation. **AS-02** [A] A Stage 1 release exists on production before Stage 2 goes live. |

### 7.8 Support and legal

| Req no. | Description | Test criteria |
|---|---|---|
| FR2-30 | **Customer support (Must).** **How:** email, through a contact form. When the member is signed in, the form also shows the owner their plan. **Reply time:** aim to reply within 2 working days. **Help page:** answers common questions about billing, cancelling, refunds, referrals and data. **Chatbot:** Stage 4. | **AS-01** [A] The contact form reaches the owner, showing the plan when the member is signed in. **AS-02** [A] The help page covers billing, cancelling, refunds, referrals and data. **AS-03** [O] The owner tracks reply times against the 2-working-day aim. |
| FR2-31 | **Legal pages (Must).** **Pages:** an updated privacy policy, updated terms of use, and new terms of sale. The terms of sale cover price, yearly and monthly plans, renewal, cancellation, refunds, discounts and referral rewards. **Approval:** all are drafted for the owner and published only after the owner approves them. A lawyer's review is advised but is the owner's decision. | **AS-01** [O] All three legal pages carry an owner approval date earlier than their publication date. **AS-02** [A] The terms of sale cover every listed topic, and are linked from the pricing page and checkout. |

### 7.9 Quality

| Req no. | Description | Test criteria |
|---|---|---|
| NFR2-01 | **Privacy (Must).** **Law:** UK and EU GDPR applies to every member, wherever they live. **Data and consent:** the full list of what is kept, its consent and how long it is kept is the Stage 2 Data Model, summarised in section 8.1. What leaves the website is in section 8.5. **Assessment:** a one-page data-protection assessment covers both. **Inactive accounts:** a free account with no sign-in for 24 months is deleted. The deletion date is worked out from the last sign-in, and the warning email goes exactly 30 days before it. | **AS-01** [A] Every table and file store holding personal data appears in the Data Model, with the same fields. Any extra personal field or store fails the test. **AS-02** [O] The data-protection assessment is signed. **AS-03** [A] In a clock-shifted test, the warning goes exactly 30 days before the worked-out deletion date, and the account is deleted on that date unless the member signs in. |
| NFR2-02 | **Security (Must).** **Access:** members only ever see their own data. **Passwords:** stored hashed, and sign-in is limited as in FR2-01. **Two-step sign-in:** by email code, optional for members and required for the owner's admin account. **Before launch:** a security scan runs, with no serious findings left open. | **AS-01** [A] Member A cannot read member B's data through any page or address. Every member route is tried automatically. **AS-02** [A] The database shows hashed passwords only. **AS-03** [A] Admin sign-in requires the email code. A member who opts in is asked for it too. **AS-04** [A] The security scan shows no high or medium findings left open. |
| NFR2-03 | **Works on phones (Must).** Every page in the member journey works on screens from 360 pixels wide up to desktop. Automated tests use the Safari (WebKit) and Chrome (Chromium) browser engines with phone screen sizes. Before launch, the full journey is also checked once on a real iPhone with Safari and a real Android phone with Chrome. | **AS-01** [A] Screenshots of every journey page at 360, 768 and 1280 pixels show no sideways scrolling and nothing overlapping. **AS-02** [A] Journey tests pass in the WebKit and Chromium engines at phone and desktop sizes. **AS-03** [O] The full journey is completed once on a real iPhone with Safari and a real Android phone with Chrome, and recorded. |
| NFR2-04 | **Accessible (Must).** WCAG 2.2 level AA across the whole journey, including landing and sales pages, registration, consent, the member pages and emails. A checklist maps each applicable WCAG criterion to an automated or a manual check. Stripe's hosted checkout page is Stripe's responsibility and is checked for keyboard use. | **AS-01** [A] The automated accessibility scan finds no serious or critical issues on any journey page. **AS-02** [A] A keyboard-only run goes from registration to the dashboard and the food diary, and every focused item is visible. **AS-03** [O] Every manual item on the WCAG checklist is checked and recorded, including a full run with a screen reader on one phone and one computer. |
| NFR2-05 | **Fast (Should).** Member pages load in about 2 seconds on a 4G phone connection. Public page speed is covered by FR2-27. | **AS-01** [A] The dashboard loads in 2 seconds or less on simulated 4G, taking the median of 5 runs. |
| NFR2-06 | **Backups and resilience (Must).** **Backups:** the Hostinger daily backup is used, so at most 1 day of data can be lost. A full restore is tested once before launch and must be possible within 1 day. **When membership fails:** if the membership plugins fail or are switched off, the blog, the calculators and their landing pages keep working. Premium content and member data stay hidden, because WordPress cannot show them without the Lifeline Daily plugin. If Stripe is unreachable, existing members keep their access, and new checkouts show a "try again shortly" message. | **AS-01** [O] A full restore on staging is completed within 1 day and recorded. **AS-02** [A] With each membership plugin switched off in turn on staging, the blog, calculators and landing pages pass the smoke test, and no Premium content, member page or member file can be opened. **AS-03** [A] With Stripe made unreachable, existing members keep access, and checkout shows the try-again message. |
| NFR2-07 | **Safe health content (Must).** **Approval:** all health content, including AI-drafted text, meal plans and workouts, is approved by the owner before members see it. This approval is the main safety control. **Wording:** stays general wellbeing, not diagnosis or treatment. **Safety links and warnings:** every stress and mood page links to urgent help. Sleep content advises seeing a doctor about loud snoring with daytime sleepiness. Blood Sugar Balance tells anyone on diabetes medication to talk to their doctor first. **Reviewer's competence:** the owner records their relevant clinical qualifications with the content sign-off, and uses an outside reviewer for any content beyond them. | **AS-01** [A] No member-facing content exists without an approval record for its current version. **AS-02** [A] Every stress and mood page has an urgent-help link. The snoring advice and the diabetes-medication note are present. **AS-03** [A] A wording check flags phrases such as "cures" or "treats" for the owner to look at. It supports the owner's review and does not replace it. **AS-04** [O] The owner's qualifications are recorded with the first content approval. |
| NFR2-08 | **Owner admin (Must).** Without a developer, the owner can: use every feature; preview the site as a visitor, a free member or a Premium member; see members and their plans; issue refunds; create discount codes; see and correct referral rewards; approve content, meal plans, AI suggestions and testimonials; manage Facebook group membership against the member list; read the weekly report; and see "Something else" requests. | **AS-01** [O] The owner completes every listed admin task without a developer, in one checklist run. **AS-02** [A] Preview mode shows exactly what each member state sees. |
| NFR2-09 | **Ready for the Stage 3 app (Must).** Accounts, goals, track, profile, progress, food diary and subscriptions can be used by the Stage 3 app without members signing up again. | **AS-01** [A] A test app connection signs in with a member account and reads that member's goals, track, profile, progress, diary and plan. It cannot read another member's data. |
| NFR2-10 | **Simple build (Must).** The light process from Stage 1. Automated checks protect the calculators and payments. Established plugins are used where they fit. | **AS-01** [A] Every change runs the Stage 1 calculator checks and the payment tests. |

### 7.10 How it looks

| Req no. | Description | Test criteria |
|---|---|---|
| UI2-01 | **Consistent look (Must).** Member pages match the current site style, with clear headings, large buttons and plain words. | **AS-01** [A] Snapshots of the member pages, once approved by the owner, become the baseline, and later visual changes are flagged. **AS-02** [O] The owner confirms the look matches the site. |
| UI2-02 | **Dashboard and track screens (Must).** The dashboard follows the order in FR2-07, so today's activity comes first. Progress is shown simply, for example "9 of 28 done", with small charts. | **AS-01** [A] At 360 pixels wide, the dashboard order matches FR2-07, and progress reads as "n of 28 done". |
| UI2-03 | **Pricing and sales pages (Must).** Yearly is shown first. The price, what's included and how to cancel are visible without scrolling on a phone. | **AS-01** [A] On a 360 by 640 pixel screen, yearly is shown first, and the price, what's included and how to cancel are visible without scrolling. |
| UI2-04 | **Emails (Must).** Short and phone-friendly, with the Lifeline Daily logo and no hidden tracking. | **AS-01** [A] Emails are 600 pixels wide or less, include the logo, and contain no tracking pixels or remote scripts. |

## 8. Rules that apply across Stage 2

### 8.1 Data, consent and how long it is kept

This is a summary. The Stage 2 Data Model is the full list, field by field, and the tests check against it.

| Data | When collected | Consent needed | How long kept |
|---|---|---|---|
| Email, password, name, age, time zone, the first tagged link the person arrived by | Registration | Terms of use (contract) | Until account deletion, or 24 months of inactivity for free accounts |
| Display name, profile photo | Profile, optional | Terms of use | Until removed or account deletion |
| Consent choices and dates | Consent step | — | Until account deletion |
| Email preferences, nudge counts | Profile | Terms of use | Until account deletion |
| Goals, track, "Something else" text | After consent | **Health consent** | Until changed, health consent withdrawn, or deletion |
| Health profile, saved calculator results | Profile, optional | **Health consent** | As above |
| Check-ins, activity progress, food diary and diary photos, meal plan choices, weekly plans and reviews, suggestion history, badges | During tracks and challenges | **Health consent** | As above |
| Sleep and Stress screening answers | Before the track | Used only at that moment | **Not stored** |
| Plan, subscription, payments, referral rewards | Checkout | Terms of sale (contract) | Until account deletion, except the tax fields below |
| Survey answers and testimonials | Week 1 and week 4 surveys | Terms of use; health consent for "what changed for you"; separate permission to quote | Until permission is withdrawn or account deletion. Answers to "what changed for you" are also deleted when health consent is withdrawn |
| Support messages | Contact form | Terms of use | 2 years, or until account deletion if sooner |
| Starter guide and waitlist sign-ups (non-members) | Sign-up form | **Marketing consent** | Until unsubscribed, then deleted within 30 days; unconfirmed sign-ups deleted after 30 days |

**Kept on purpose after an account is deleted.** Only two things are kept:

- **Tax fields:** name, email, amount, currency, tax, date, country and the Stripe payment reference, as tax law requires. They are not linked to any health data.
- **Erasure list:** for each account deletion or health-consent withdrawal, the member number, scrambled email, what was erased and the date. Kept for 60 days, with a daily copy emailed to the owner's admin mailbox, so a restored backup cannot bring the data back.
- **Owner's admin log:** entries about refunds and other money actions are kept, with the member's name and email removed.

**Files and copies.**

- **Photos** are stored where the web server will not show them publicly, and are deleted with the data they belong to.
- **Data downloads** are created when asked for and not kept.
- **Caches and logs** never hold health data. Logs hold no personal data, and are kept for 30 days.

**Without health consent, a member can still:**

- use the calculators;
- read articles;
- download starter guides;
- see general wellbeing suggestions;
- buy and manage Premium;
- use the general meal plans, workouts and challenges, without any progress being recorded.

**Without health consent, a member cannot:** choose goals or tracks, keep a profile, check-ins, a food diary or saved results.

**Withdrawing health consent** deletes all the data marked "health consent" above, including diary photos.

### 8.2 Safety comes first

Every suggestion, plan, meal, workout, challenge day, badge and activity is chosen in this order:

1. **Safety.** The safety matrix below removes excluded content first.
2. **Access.** The member's plan and the access rules in section 8.3 are applied.
3. **Matching and progression.** Goals, track and the difficulty rules are applied.

**What this means in practice:**

- **Nothing overrides safety.** No rule, including "never empty" or "stretch action", can bring back excluded content. A safe empty state with a short message is allowed.
- **Changes take effect at once.** The moment a profile change is saved, excluded content can no longer be seen, opened or chosen anywhere: the dashboard, direct links, swaps, plans, meal plans, workouts, challenges, badges or downloads. Anything already assigned is replaced or removed.
- **No breath-holding.** No breath-holding exercise is used anywhere on Lifeline Daily, for any member.
- **Tagging content.** Every content item is tagged by the owner with any safety tags that apply.

**Safety matrix**

These are the rules at launch. The wording is drafted for the owner, who approves it as clinical reviewer before launch.

| Member's situation | Content excluded (safety tags) | Offered instead | If nothing suitable is left |
|---|---|---|---|
| **Unknown:** no health consent, or no profile | Anything tagged weight-loss, fasting, high-intensity, high-protein, high-salt, high-saturated-fat, pregnancy-unsafe-food or alcohol | Content with none of these tags (the "general safe" set) | "Complete your health profile for more suggestions" |
| **No conditions** (profile says "none") | Nothing beyond the member's own choices | Everything | — |
| **Kidney disease** | high-protein | Standard-protein recipes and activities. The protein calculator's kidney message is shown | "Your kidney team should set your protein target" |
| **Pregnancy or breastfeeding** | weight-loss, fasting, high-intensity, alcohol, pregnancy-unsafe-food; weight-loss badges | Pregnancy-safe recipes and gentle activity. **Healthy Weight track not offered** | "Talk to your midwife or doctor about weight and exercise during pregnancy" |
| **Eating disorder, now or in the past** | weight-loss, fasting, calorie-mention, portion-question; weight-loss badges. Weight is optional in check-ins | **Healthy Weight track not offered**; other tracks without weight goals | "For support with eating, talk to your doctor" plus a helpline link |
| **Type 2 diabetes** | fasting | Blood-sugar-friendly content. Before exercise: "If you take insulin or medicine that can cause low blood sugar, check with your doctor" | "Talk to your diabetes team" |
| **Prediabetes** | Nothing extra | Blood-sugar-friendly content first | — |
| **High blood pressure** | high-salt. high-intensity content carries a "check with your doctor first" note | Lower-salt recipes | "Talk to your doctor before starting intense exercise" |
| **High cholesterol** | high-saturated-fat | Heart-friendly recipes | — |
| **More than one condition** | Everything excluded by any of them | Content that passes all of them | The first applicable message |

### 8.3 Programme and access rules

**Programme rules**

| Topic | Rule |
|---|---|
| What a day is | A calendar day in the member's own time zone. Clock changes do not add or remove a day |
| What a week is | 7 days from the day the member starts the track, not a calendar week |
| Missed activities | Do not delay progress; the track moves on day by day |
| Ticking late | An activity can be marked done up to 2 days late. The weekly review shows the latest figures whenever it is opened |
| Finishing a track | Reaching day 28, whatever was skipped |
| Tracks at once | One track at a time |
| Switching | Allowed at the end of any week. Progress on the old track is kept, and the member can resume it later |
| Free week 1 | Once per track, so up to four free weeks in total |
| Repeating | Premium members can repeat any finished track |
| Challenges | A Premium member can follow a monthly challenge alongside a track, or on its own |

**Access by member state**

| Member state | Tracks | Meal plans, workouts, challenges | Food diary and check-ins | Own history | Members' group |
|---|---|---|---|---|---|
| **Free** | Week 1 of each track, once | No | Diary and check-ins during each free week; read-only otherwise | Readable and downloadable | No |
| **Premium** | Everything | Yes | Yes | Readable and downloadable | Yes |
| **Cancelled, still in paid period** | Everything until the period ends | Yes, until it ends | Yes, until it ends | Readable and downloadable | Yes, until it ends |
| **Premium ended (expired, or failed payment after 7 days)** | The **current track week** stays open to its last day, including its lessons, activities and check-in. No later week opens | Lock at once | Diary and check-ins open only for the current track week; then read-only | Readable and downloadable, always | Removed within 7 days |
| **Refunded** | Same as "Premium ended": the current track week finishes, nothing later opens | Lock at once | As "Premium ended" | Readable and downloadable, always | Removed within 7 days |

### 8.4 Billing policy

| Topic | Rule | Example |
|---|---|---|
| Tax | Prices are shown and charged **including any sales tax**. Stripe Tax works out the tax inside the price for each country | A UK customer pays US$7.99, which includes UK VAT; a US customer in a state with no tax also pays US$7.99 |
| Price lock | Existing subscribers keep their price when prices rise. The lock ends if they cancel and the paid period runs out | Joined at US$59.99, price rises to US$69.99: they renew at US$59.99. If they cancel and rejoin later, they pay US$69.99 |
| Switching monthly to yearly | Takes effect at the next monthly renewal and can be cancelled until then | A monthly member switches mid-month, then changes their mind before renewal: they stay monthly |
| Launch discount | 50 % off the first month only, for monthly sign-ups within 30 days of launch | First month US$4.00, then US$7.99 |
| Referral reward | Once the friend's first payment passes its 14-day refund period, both the referrer and the friend get a discount on their next payment: **one month free** for monthly members, or **13 % off** the next renewal for yearly members, which is worth about US$7.99. It works the same in any currency | A yearly member's next renewal: US$59.99 less 13 % = US$52.19. A monthly member's next month is free |
| Referral limit | Up to 3 referral rewards per member in each calendar year (January to December) | A fourth referral in the same year earns no reward |
| Self-referral | No reward when the friend's email or card matches the referrer's | — |
| More than one reward | Rewards waiting to be used apply to the following payments, one per payment. They are lost if the account is deleted, and are never paid out as cash | A monthly member with 2 rewards pays nothing for the next 2 months |
| Combining offers | A discount code and a referral reward can't be used on the same payment | A friend who used the launch code gets their referral reward on a later payment |
| Refunds | Full refund of the first payment within 14 days, monthly or yearly. A payment refunded within 14 days earns no referral reward | A yearly member refunded on day 10 gets US$59.99 back, and their referrer gets no reward |
| Failed payment | Retried for 7 days, then Premium ends as section 8.3 describes | — |

### 8.5 Data that leaves the website

| Goes to | What is sent | What is never sent |
|---|---|---|
| Stripe | Name, email, plan, amount, country for tax | Any health data, goals or tracks |
| Plausible (analytics) | Only the allowlist below. No cookies and no personal identifiers | Anything not on the allowlist |
| Email delivery (Hostinger mail, later a mailing service) | Email address, first name, email content | Health details (FR2-12) |
| AI assistant | Public articles and draft content, for tagging and drafting. "Something else" text, for monthly grouping only, after these are removed: names, email addresses, phone numbers, street addresses and dates of birth, including any written inside the text. Sent only to a provider that does not keep or train on the data | Anything else about members |
| Facebook | Nothing from the website. Members join the group themselves | Member lists, health data |

**Analytics allowlist (Plausible).** Nothing else may be sent.

| Event | Fields allowed | Example |
|---|---|---|
| Page view | Page address with no query string, except the tagged-link fields utm_source, utm_medium and utm_campaign | /programmes/blood-sugar/?utm_source=facebook |
| calculator_used | calculator: protein, diabetes or healthy-weight | — |
| registered | none | — |
| track_started | track name | track: blood-sugar |
| week1_completed | track name | — |
| upgraded | plan: monthly or yearly | — |
| switched_to_yearly | none | — |
| starter_guide_requested | track name | — |
| waitlist_joined | track name | — |

Track names are allowed because they describe which programme page was used, never a person's health. Page addresses must never contain an email, a name, a member number or any profile, check-in or calculator value. Renewals, cancellations and revenue are never sent to analytics. They are counted inside the website (FR2-17).

## 9. Sign-offs

The owner gives every sign-off.

| Sign-off | When | What is confirmed |
|---|---|---|
| Stage 2 requirements | Signed 25 October 2026 | This document is final |
| Stage 1 clinical sign-off | Start of Stage 2 implementation | The calculators, their wording and the clinical decisions, using the prepared review pack |
| Healthy Weight Check clinical sign-off | Before the tool reaches production | The thresholds, the background mapping and all its wording (FR2-39 AS-19) |
| Stage 2 content approval | Before each piece of content is published | Lessons, activities, meal plans, workouts, challenges, screening wording, safety rules, guides, AI-drafted text. The owner's clinical qualifications are recorded with the first approval |
| Stage 2 legal and privacy | Before launch | Privacy policy, terms of use, terms of sale, data-protection assessment |
| Stage 2 launch | Before go-live | Every Must requirement meets its test criteria, with any exception approved in writing by the owner, and Stage 1 is released |

## 10. Decisions (all settled)

| # | Decision | Answer |
|---|---|---|
| D1 | Audience | Fully English, global |
| D2 | Payment provider | Stripe, through its hosted checkout page |
| D3 | Free trial | None; week 1 of each track is free |
| D4 | Refunds | First payment within 14 days |
| D5 | Price | US$59.99 a year (shown first) or US$7.99 a month |
| D6 | Search and content work | Must |
| D7 | Starter guides and feedback | Kept |
| D8 | Content author and clinical reviewer | The owner |
| D9 | Privacy reviewer | The owner |
| D10 | Membership plugin | Paid Memberships Pro |
| D11 | Backups | Hostinger daily backup |
| D12 | Health profile | Kept |
| D13 | AI use | AI assists the owner; the owner approves everything |
| D14 | Stress questionnaire | No licensed scale; owner-written questions labelled as reflection |
| D15 | Tracks | Four, all at launch |
| D16 | Tracks at once | One |
| D17 | Video lessons | Text first |
| D18 | Launch discount | 50 % off the first month |
| D19 | Pause | Dropped; may come later |
| D20 | Referral reward | A discount on the next payment for each, worth about US$7.99; rules in section 8.4 (amended by D44) |
| D21 | Meta Pixel | Not used |
| D22 | Sex & Relationships | Its own top-level category |
| D23 | The Health Intelligence Report | Category removed |
| D24 | Inactive free accounts | Deleted after 24 months, with a warning |
| D25 | Two-step sign-in | Optional for members; required for admin |
| D26 | Support | Email form; chatbot in Stage 4 |
| D27 | Legal pages | Drafted for the owner; the owner approves |
| D28 | Stage 1 sign-off | Start of Stage 2 implementation |
| D29 | Target launch | 31 October 2026 |
| D30 | Ongoing Premium value | Meal plans, monthly challenge, workouts, members' group and live Q&A, long-term progress, app included for yearly members |
| D31 | Food diary | Simple, habit-based, no calories; the full tracker comes in Stage 3 |
| D32 | Pricing emphasis | Yearly first |
| D33 | Chair stand test | Kept, with safety instructions |
| D34 | "Something else" grouping | AI, after personal details are removed |
| D35 | Without health consent | No goals, tracks, profile, diary or check-ins; general path only |
| D36 | Breath-holding exercises | None, for any member |
| D37 | Safety matrix | Drafted for the owner; approved by the owner as clinical reviewer |
| D38 | When Premium ends or is refunded | The current track week finishes; everything else locks at once |
| D39 | Support messages | Deleted with the account |
| D40 | Prices | Shown including any sales tax |
| D41 | Referral limit | 3 a calendar year; self-referral blocked |
| D42 | Age | Age in years, not date of birth; moves forward yearly; correctable, never under 18; covering statement |
| D43 | Free-week diary | Open during each track's free week |
| D44 | Referral reward form | A discount on the next payment (one month free, or 13 % off a yearly renewal), not dollar credit, so it works in any currency |
| D45 | Free week after erasure | Withdrawing health consent or deleting the account resets which free weeks were used; no separate record is kept |
| D46 | Off-site copy of the erasure list | Emailed to the owner's admin mailbox each day |
| D47 | Monthly check-in measures | The measures of the last track finished, changeable by the member |
| D48 | Emergency numbers | No per-country list. A fixed message with common examples plus findahelpline.com |
| D49 | Healthy Weight Check | Part of Stage 2 (FR2-39). It goes live after its own checks and the owner's clinical sign-off, independent of the 31 October launch |
| D50 | Healthy Weight Check method | NICE NG246 BMI and waist-to-height bands; lower BMI thresholds for the listed family backgrounds; a follow-up question for mixed backgrounds; Black Caribbean counts as African-Caribbean |
| D51 | Healthy Weight Check data | Answers stay in the browser. Saving is a separate click of the result, unit, band and version only. No email of results and no new server endpoint |
| D52 | Healthy Weight Check safety | Stops for under 18, pregnancy or breastfeeding (including "not sure") and an eating disorder; banner for unintended weight loss; notes for underweight, obesity class 3 and age 65 or over; no sex question. Breastfeeding is included as the site's cautious policy |
| D53 | Files for the Healthy Weight Check | The lead builds it (package S2-L9) and may change only the files listed in the Build Method, section 4.1 |
| D54 | "Planned" card | Hidden for launch; replaces Stage 1 decision DEC-26 |
| D55 | Rounding in the Healthy Weight Check | Category and band follow the value shown (BMI 1 decimal place, ratio 2 decimal places), rounded half-up |
| D56 | Protein tool cut-offs | Unchanged in Stage 2. Aligning them with NICE NG246 is proposed after launch, at the Stage 1 clinical gate |

## Appendix A — Content categories and the tracks they feed

| Category | Feeds |
|---|---|
| Weight & Healthy Eating | Healthy Weight track, meal plans |
| Blood Sugar & Metabolic Health | Blood Sugar Balance track |
| Fitness & Strength | Fitness and Strength track, workout plans |
| Sleep & Stress | Sleep and Stress track |
| Heart Health, Gut Health, Healthy Ageing, Sex & Relationships, Health Conditions | Free goals, general wellbeing path, monthly challenges |

## Appendix B — Evidence behind the weekly check-in measures

| Measure | Evidence | Limits for Lifeline Daily |
|---|---|---|
| BMI, with lower thresholds for some family backgrounds | NICE NG246 (2025): adults of South Asian, Chinese, other Asian, Middle Eastern, Black African or African-Caribbean family background have higher cardiometabolic risk at a lower BMI, so overweight starts at 23 and obesity at 27.5 | A screening measure, not a diagnosis. Less reliable for older adults, very muscular people and after limb loss; not used in pregnancy |
| Waist and waist-to-height ratio | NICE NG246 (2025, reviewed January 2026): waist-to-height ratio 0.4 to 0.49 is healthy, 0.5 to 0.59 increased, 0.6 or more high, for adults with a BMI under 35. People should keep their waist under half their height. Ashwell and Gibson, BMJ Open 2016 | Not used as a band for a BMI of 35 or more. Self-measured, so instructions on how to measure are shown |
| Weight and activity (Blood Sugar Balance) | Diabetes Prevention Program Research Group, NEJM 2002;346:393–403: lifestyle change aiming for at least 7 % weight loss and at least 150 minutes of activity a week cut type 2 diabetes incidence by 58 % over about 3 years, in adults with raised blood glucose | The trial included supervised coaching. Lifeline Daily promises no such result |
| Blood glucose not used | HbA1c reflects about three months, and most members have no meter | — |
| 30-second chair stand | US CDC STEADI assessment protocol | Designed for older adults, with scoring bands by age and a helper standing by. Used here as a personal progress count, not scored against bands, with safety instructions |
| Push-ups | Yang et al., JAMA Network Open 2019;2:e188341: in 1,104 male firefighters, more push-ups were linked to lower heart-risk events over 10 years | Studied only in active, middle-aged men. Used here as a personal progress count only, with no risk claims |
| Activity minutes and strength sessions | WHO guidelines on physical activity and sedentary behaviour (2020): 150 to 300 minutes of moderate activity a week, plus muscle strengthening on at least 2 days | — |
| Sleep hours | Watson et al., joint consensus statement of the American Academy of Sleep Medicine and Sleep Research Society, Sleep 2015;38(6):843–844: adults should sleep at least 7 hours a night regularly | — |
| Sleep quality, stress rating, owner-written questions | Single-question ratings for personal tracking | Not validated measures, and labelled as personal reflection. The Perceived Stress Scale is copyrighted, so it is not used |

**References**

- NICE NG246, Overweight and obesity management: https://www.nice.org.uk/guidance/ng246
- Ashwell M, Gibson S. Waist-to-height ratio as an indicator of early health risk. BMJ Open 2016;6:e010159
- Diabetes Prevention Program Research Group. Reduction in the incidence of type 2 diabetes with lifestyle intervention or metformin. NEJM 2002;346(6):393–403. doi:10.1056/NEJMoa012512. https://pubmed.ncbi.nlm.nih.gov/11832527/
- CDC STEADI 30-Second Chair Stand assessment: https://www.cdc.gov/steadi/media/pdfs/STEADI-Assessment-30Sec-508.pdf
- Yang J, Christophi CA, Farioli A, et al. Association between push-up exercise capacity and future cardiovascular events among active adult men. JAMA Network Open 2019;2(2):e188341
- WHO guidelines on physical activity and sedentary behaviour (2020): https://www.who.int/publications/i/item/9789240015128
- Watson NF, et al. Recommended amount of sleep for a healthy adult. Sleep 2015;38(6):843–844. https://pmc.ncbi.nlm.nih.gov/articles/PMC4434546/
- Find a Helpline, global directory of crisis helplines: https://findahelpline.com
