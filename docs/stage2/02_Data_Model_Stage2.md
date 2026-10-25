# Lifeline Daily — Stage 2 Data Model (approved by the owner)

**What information Stage 2 keeps, where it is kept, who can see it, and when it is deleted**

**Approved by the owner on 25 October 2026, with decisions DM-1 to DM-16.** Amended by the owner the same day: the billing email is kept on orders for tax records (DM-10, P3), and the test clock record F9 is added for test and staging sites. Prepared 25 October 2026 from the final Stage 2 Requirements, including the amendments made that day (decisions D36 to D47). Revised the same day after an independent review. Written in plain language, with a technical appendix (Appendix T) for the build. Requirement numbers such as FR2-09, and decision numbers such as D40, refer to the requirements document.

---

## 1. About this document

The requirements say what the website must do. This document says what information it must keep to do it. For each group of information it lists:

- what is kept, field by field, with an example;
- where it is kept;
- the consent it needs, using the consents in requirements section 8.1;
- who can see it;
- how long it is kept.

**Consent labels:**

- **Account:** needed to run the account, under the terms of use or terms of sale.
- **Health:** stored only while the member's health consent is on.
- **Marketing:** stored only while the person's marketing consent is on.
- **None:** content written by the owner, with no personal data.

**Naming.** Record groups are named by area letter: A1, B5, C8, P3 and so on. Protections are S1 to S10. Data model decisions are DM-1 onwards. None of these share a prefix with the requirements' decision numbers, D1 to D47.

Stage 1 kept no personal or health data. Stage 2 is the first stage that does, so this document also sets the protections for it (section 10).

## 2. Rules that shape every part of the data model

1. **Keep only what a requirement needs.** No field exists without a requirement that uses it.
2. **Members' data lives in Lifeline Daily's own tables, not scattered through WordPress.** This makes three things simple: deleting everything about a member, downloading it, and sharing it with the Stage 3 app.
3. **Health data is kept apart from account data.** It is stored only while health consent is on. Withdrawing consent deletes it in one step, and records an erasure.
4. **Content written by the owner contains no personal data.** It lives in WordPress as editable content. Every published version is kept, so a member's history always points to the exact version they saw (T2).
5. **Payments are kept by Stripe and the membership plugin.** Card details are never kept anywhere on the website.
6. **Measurements are stored in metric only:** centimetres, kilograms and minutes. Imperial units are converted when shown.
7. **Event times are stored in UTC**, the world reference time. **Programme days are stored as the member's own calendar date**, so a day means the same thing to the member and to the website (T3).
8. **Every amount of money is stored with its currency, in that currency's smallest unit.** For example, US$7.99 is stored as 799 USD, and £6.49 as 649 GBP. The catalogue price, the amount charged, the tax inside it and any refund are stored separately (P3).
9. **Figures worked out from other data are not stored.** BMI, waist-to-height ratio, the current programme day, weekly summaries and shopping lists are calculated when needed. **One named exception:** the completion figure used to set a weekly plan's level is kept with the plan, so the plan can be explained later (C10).
10. **Every piece of content shown to members has an approval record for its exact version.** Content without one is never shown.

## 3. The big picture

| Area | What it covers | Personal data? |
|---|---|---|
| **A. Accounts and consent** | Who the member is, their settings, consent choices and sign-in records | Yes |
| **B. Content library** | Everything the owner writes: tracks, lessons, activities, recipes, meal plans, workouts, challenges, goals, tags, the safety matrix and articles | No |
| **C. Member health journey** | Goals, profile, tracks, check-ins, food diary, meal plans, workouts, plans and reviews, badges | Yes, health data |
| **P. Payments and referrals** | Plans, subscriptions, orders, discount codes, referral rewards, Stripe notices | Yes |
| **E. Email list** | Visitors who requested a starter guide or joined a waitlist | Yes |
| **F. Operations** | Erasure list, admin log, support messages, surveys and testimonials, reports | Some |

**How the areas connect:**

- **A member account (A)** has one health profile, goals, track enrolments, check-ins and diary entries (C). It also has one subscription with its orders and rewards (P).
- **A track enrolment (C)** points to a track (B).
- **Each activity done (C)** points to the exact version of an activity or workout session (B).
- **Each meal plan given to a member (C)** points to the exact version of a meal plan template and its recipes (B).
- **The safety matrix (B)** reads the member's health profile (C) to decide what content can be shown.
- **Nothing in the content library (B)** points back to a member.

## 4. Area A — Accounts and consent

### A1. Member account

Uses WordPress's own user account, plus a Lifeline Daily member settings record.

| Field | What it holds | Example |
|---|---|---|
| Member number | Permanent internal number, never reused | 10482 |
| Email | Sign-in email; unique | sam@example.com |
| Password | Stored only in scrambled (hashed) form, never readable | — |
| First name, last name | As entered at registration | Sam, Lee |
| Display name | Optional, shown only to the member | Sammy |
| Profile photo | Optional; JPG, PNG or HEIC up to 10 MB, kept as a protected file (S2) | — |
| Age | In years, as the member entered it; 18 or over (FR2-01) | 47 |
| Age entered on | The date the age was entered or last corrected. Age moves forward one year on each anniversary of this date | 2026-11-02 |
| Time zone | For reminders and programme days. A change applies from the member's next calendar day (T3) | Europe/London |
| Units | Metric or imperial, for display only | imperial |
| Role | Member, or owner admin | member |
| Arrived from | The first tagged link the person arrived by, saved at registration (FR2-17) | facebook-post-1029 |
| Email confirmed on | When the email was confirmed | 2026-11-02 09:14 UTC |
| Joined on | Account creation date | 2026-11-02 |
| Last signed in on | Used for nudges (FR2-12) and inactive-account deletion (NFR2-01) | 2026-11-20 |
| Two-step sign-in | On or off; always on for the owner admin | off |

- **Consent:** Account.
- **Who sees it:** the member, and the owner when giving support.
- **How long:** until account deletion. Free accounts with no sign-in for 24 months are deleted, after a warning 30 days before the worked-out deletion date.

### A2. Consent history

One line is added for every consent change. The current consent is the latest line of each type.

| Field | What it holds | Example |
|---|---|---|
| Member | Which account | 10482 |
| Consent type | Terms, privacy policy, terms of sale, health, or marketing | health |
| Given or withdrawn | Which way it changed | withdrawn |
| Document version | The version of the terms or policy agreed to | privacy-2026-11 |
| Date and time | When | 2026-12-01 18:02 UTC |

- **Consent:** Account. This history is the proof of consent.
- **How long:** until account deletion.
- **Withdrawing health consent** also adds a line to the erasure list (F1).

### A3. Email preferences

| Field | What it holds | Example |
|---|---|---|
| Welcome, daily reminder (with time), weekly-review-ready, end-of-track, nudges | Each on or off; the reminder time is in the member's time zone | reminder on, 07:30 |
| Nudges sent since last sign-in | 0, 1 or 2; nudges stop at 2 (FR2-12) | 1 |
| Last nudge sent on | Date | 2026-11-25 |
| Unsubscribe codes | Single-use codes behind each unsubscribe link, stored scrambled | — |

- **Consent:** Account, with each optional email chosen by the member.
- **How long:** until account deletion.

### A4. Sign-in and security records

| Record | What it holds | Kept for |
|---|---|---|
| Email confirmation code | Scrambled code and expiry | 24 hours |
| Password reset code | Scrambled code and expiry | 60 minutes |
| Email change code | New address, scrambled code and expiry | 24 hours |
| Two-step sign-in code | Scrambled code and expiry | 10 minutes |
| Failed sign-ins | Count and lock-until time per account | 24 hours |
| Website sessions | One per device. "Remember me" lasts 30 days; others end when the browser closes. All end when the password is reset | Until they end |
| App keys (Stage 3) | One per app installation: owner, created on, expires on, withdrawn on (T5) | Until expiry, withdrawal, password reset or account deletion |

All of these are deleted automatically when they expire or are used.

## 5. Area B — Content library (written by the owner, no personal data)

Everything here is WordPress content the owner edits on screen. **Every published version is kept** and carries an approval record (B16). Members only ever see approved versions (T2).

### B1. Goals

| Field | What it holds | Example |
|---|---|---|
| Name | Shown to members | Blood Sugar Balance |
| Kind | Track goal or free goal | track goal |
| Linked track | For track goals | Blood Sugar Balance |
| Shown to members | Worked out automatically: at least 5 reviewed articles, and for a track at least 7 activities (FR2-05) | yes |
| Order | Position in the list | 2 |

### B2. Tags

One managed list, so the same tag is never spelt two ways.

| Kind | Purpose | Examples |
|---|---|---|
| Goal and track tags | Match content to goals and tracks | healthy-weight, blood-sugar, fitness, sleep-stress, heart, gut, ageing, energy |
| Person tags | Match content to kinds of people | over-50, vegetarian, vegan, beginner |
| Safety tags | Let the safety matrix exclude content (B13) | weight-loss, fasting, high-intensity, high-protein, high-salt, high-saturated-fat, pregnancy-unsafe-food, alcohol, calorie-mention, portion-question |

**Breath-holding is a banned tag:** saving content with it is refused (D36). The banned tag is a backstop. The owner's content review is the real control that keeps breath-holding exercises out (NFR2-07).

### B3. Tracks

| Field | What it holds | Example |
|---|---|---|
| Name, short description | Shown on the dashboard and sales page | Healthy Weight |
| Sales page, starter guide | Links (FR2-16, FR2-18) | /programmes/healthy-weight/ |
| Check-in measures | The measures this track uses (FR2-09) | weight, waist |
| Status | Draft, waitlist or live (FR2-28) | waitlist |

### B4. Weeks of a track

| Field | What it holds | Example |
|---|---|---|
| Track, week number | 1 to 4 | Healthy Weight, 2 |
| Theme, weekly task | Shown to members | Smarter snacking; plan three snack swaps |

### B5. Lessons

| Field | What it holds | Example |
|---|---|---|
| Track and week | Where it belongs | Healthy Weight, week 2 |
| Title and text | Up to 1,000 words | Why snacks add up |
| Video link | Empty at launch | — |
| Tags | From B2 | weight-loss |

### B6. Activities

Used by tracks, challenges and weekly action plans.

| Field | What it holds | Example |
|---|---|---|
| Belongs to | A track, a challenge, or the action library only | Healthy Weight |
| Week and day | Week 1 to 4, day 1 to 7; empty for library-only actions | week 2, day 3 |
| Title and instructions | Shown to members | Swap one sugary drink for water |
| Link | Optional article, tool, recipe or workout | — |
| Difficulty | Easier, standard or stretch (FR2-10) | standard |
| In the action library | Whether weekly plans can use it | yes |
| Tags | Goal, person and safety tags | weight-loss, beginner |

### B7. Monthly challenges

| Field | What it holds | Example |
|---|---|---|
| Month | Year and month it runs | 2026-12 |
| Title and description | Shown to members | 28-day fibre boost |
| Activities | 28 activities from B6 | — |
| Finish badge | From B12 | Fibre finisher |

### B8. Recipes

| Field | What it holds | Example |
|---|---|---|
| Title, photo, method | Shown to members | Lentil and spinach soup |
| Ingredients | Name, amount and metric unit for each | red lentils, 150, g |
| Servings, meal type | Portions; breakfast, lunch, dinner or snack | 2, lunch |
| Diet suitability | Everyone, vegetarian, vegan, and others the owner adds | vegetarian, vegan |
| Track suitability | Which tracks it suits | blood-sugar |
| Tags | Including safety tags | high-protein |
| AI-drafted | Yes or no | yes |

### B9. Meal plan templates

| Field | What it holds | Example |
|---|---|---|
| Track and diet type | Which members it is for | Blood Sugar Balance, vegetarian |
| Week number | At least 4 per track at launch (FR2-32) | 1 |
| Days and meals | For each of 7 days: breakfast, lunch, dinner and snack, each pointing to a recipe version | Monday lunch = Lentil and spinach soup |
| Swap options | Other recipes allowed for each meal | — |

### B10. Workout plans

| Field | What it holds | Example |
|---|---|---|
| Level, version | Beginner, intermediate or active; home or basic equipment | beginner, home |
| Week number | At least 4 per level at launch (FR2-34) | 1 |
| Sessions | Each session lists exercises: name, instructions, repetitions or seconds, rest seconds | Squat to chair, 10 reps, rest 30 s |
| Safety advice | Shown before every session | Warm up for 5 minutes. Stop if you feel pain. |
| Tags | Including safety tags | high-intensity |

### B11. Suggestion rules

| Field | What it holds | Example |
|---|---|---|
| When | A goal or track, plus optional person tags | Blood Sugar Balance, vegetarian |
| Suggest | An ordered list of articles, tools, activities or recipes | — |
| Reason shown | One line, shown to the member | Because you chose Blood Sugar Balance |
| Priority | The current track comes first (FR2-10) | 1 |

### B12. Badges

| Field | What it holds | Example |
|---|---|---|
| Name and picture | Shown to members | 5 kg down |
| Rule | What earns it | weight down 5 kg or more from first check-in |
| Is a weight-loss badge | Hidden by the safety matrix where required | yes |

### B13. Safety matrix rows

One row per situation in requirements section 8.2.

| Field | What it holds | Example |
|---|---|---|
| Situation | Unknown (no health consent, or conditions not answered), no conditions declared, or a condition | pregnancy or breastfeeding |
| Remove content tagged | Safety tags never shown | weight-loss, fasting, high-intensity, alcohol, pregnancy-unsafe-food |
| Hide weight-loss badges | Yes or no | yes |
| Tracks not offered | Tracks this member cannot start | Healthy Weight |
| Note shown | Shown with the content, or when nothing suitable is left | Talk to your midwife or doctor about weight and exercise during pregnancy |

When a member has more than one condition, the exclusions of every matching row are combined. The matrix is versioned and approved like other content.

### B14. Screening and reflection wording

- **The Sleep and Stress screening (FR2-38):** the wording of the three questions and their responses. Members' answers are **never stored**.
- **The three reflection questions (FR2-09):** each answer points to the exact version of the question it answers.

### B15. Articles and the article inventory

**Articles** are existing WordPress posts. They keep the Stage 1 review fields: review owner, reviewed date, next review date and review status. Stage 2 adds tags, a "Premium only" flag, the new category (FR2-26), the direct answer, frequently asked questions and a dated sources list (FR2-21).

**The article inventory (FR2-26)** has one row per published article or draft. Each row holds:

- the article and its status today;
- its current and new category;
- the proposed fate and reason;
- the owner's decision and date;
- where it redirects to, or "gone";
- the date the fate was carried out.

### B16. Approval records

Every content version carries one. Members see only approved versions.

| Field | What it holds | Example |
|---|---|---|
| Version number | Increases with each edit | 3 |
| Content fingerprint | A short code calculated from the text, safety tags, other tags, and the versions of any linked recipes or activities. Any change produces a new code, which needs a fresh approval | 9f2c41ab |
| Approved by and on | The owner, and the date | owner, 2026-10-28 |
| AI-assisted | Yes or no | yes |

The owner's clinical qualifications are recorded once, with the first content approval (NFR2-07).

### B17. Other owner content

- **Live Q&A schedule:** the date, time and link of each monthly session (FR2-35).
- **Help page:** an ordinary WordPress page (FR2-30).
- **Discount codes:** kept by the membership plugin (P4).

## 6. Area C — Member health journey (health data)

**Everything in this area needs health consent.** Withdrawing health consent or deleting the account removes all of it, together with the related survey answers in F4 and any diary photos, and records an erasure (F1).

### C1. Goals chosen

| Field | What it holds | Example |
|---|---|---|
| Member, goal, order | Up to 3 goals; no goal twice; order 1 to 3 | 10482, Blood Sugar Balance, 1 |
| Chosen on | Date | 2026-11-02 |

### C2. "Something else" requests

| Field | What it holds | Example |
|---|---|---|
| Member, written on | Which account and when | — |
| Text | What the member typed; encrypted (S3) | Help with menopause sleep |
| Theme | Filled in by the monthly grouping | Menopause |
| Told when live | Date the member was emailed about a matching new goal | — |

### C3. Health profile

At most one per member.

| Field | What it holds | Example |
|---|---|---|
| Member | Which account | 10482 |
| Sex | Female, male, or prefer not to say | female |
| Height, weight, waist | Centimetres and kilograms; weight and waist are the latest values | 165, 72.4, 84 |
| Activity level | Low, some, active or very active | some |
| Diet type | Everything, vegetarian, vegan or other | vegetarian |
| Ethnicity | Same list as the diabetes calculator | Indian |
| Conditions answered | "Not answered", "none declared", or "conditions declared". "None" can't be combined with a condition | conditions declared |
| Conditions | Any of: type 2 diabetes, prediabetes, high blood pressure, high cholesterol, kidney disease, pregnancy or breastfeeding, eating disorder now or in the past | prediabetes |
| Last updated on, reminder sent on | Dates | 2026-11-02 |

- **Age** comes from the account (A1).
- **BMI and waist-to-height ratio** are always worked out from the current height, including on past check-ins. This is deliberate, because adult height rarely changes.

### C4. Saved calculator results (FR2-23)

| Field | What it holds | Example |
|---|---|---|
| Member, calculator, saved on | Which result | 10482, diabetes, 2026-11-02 |
| Headline result, unit, band | As the calculator showed them | 7.2, % 10-year risk, Moderate |
| Calculator version | Which approved version produced it | 2fb5571e |

The answers entered into a calculator are **never** stored.

### C5. Track enrolments

One record each time a member starts or repeats a track.

| Field | What it holds | Example |
|---|---|---|
| Enrolment number | Permanent internal number | 5521 |
| Member, track, attempt | Attempt 1 for the first time, 2 for a repeat, and so on | 10482, Healthy Weight, 1 |
| Active periods | Each period the track was active, as first and last local dates. A switch to another track closes the period; resuming opens a new one | 2026-11-02 to 2026-11-15; 2026-12-01 onwards |
| Status | Active, set aside, finished, or stopped (Premium ended) | active |
| Free week used | Yes once day 1 has opened for this track. Lost when health data is erased, which resets the free week (D45) | yes |
| Last open day | Set when Premium ends: the last local date of the current track week (requirements section 8.3) | 2026-12-06 |
| Finished on | The local date day 28 was reached | — |

The programme day is worked out from the active periods (T3).

### C6. Challenge participation

| Field | What it holds | Example |
|---|---|---|
| Member, challenge version | Which account and challenge | 2026-12 Fibre boost, version 1 |
| Joined on, finished on | Local dates | 2026-12-01 |

### C7. Workout progress

At most one active workout plan per member.

| Field | What it holds | Example |
|---|---|---|
| Member, level, version | The plan the member chose | beginner, home |
| Started on | Local date | 2026-11-03 |
| Current week | Moves on after 7 days, or when the member chooses to move up | 2 |
| Changes | Each level change or restart, with its date | intermediate from 2026-12-01 |

### C8. Activities and sessions done

One result per scheduled occurrence.

| Field | What it holds | Example |
|---|---|---|
| Member | Which account | 10482 |
| What | The exact version of an activity, or of a workout session | Swap one sugary drink, version 2 |
| Part of | Enrolment and programme day, challenge and day, weekly plan, or workout week and session | enrolment 5521, day 10 |
| For local date | The day it belongs to | 2026-11-11 |
| Result | Done or skipped | done |
| Recorded at | UTC time; must be within 2 days of the local date (FR2-09) | 2026-11-12 08:10 UTC |

### C9. Check-ins

One per enrolment week, or one per calendar month for monthly check-ins.

| Field | Unit | Used by |
|---|---|---|
| Member, enrolment week or month, local date | — | All |
| Measures used | Which measures this check-in recorded. Monthly check-ins start with those of the last track finished, and the member can change them (D47) | All |
| Weight, waist | kg, cm | Healthy Weight, Blood Sugar Balance, monthly |
| Activity minutes this week | minutes | Blood Sugar Balance, Fitness and Strength |
| Chair stands in 30 seconds, push-ups, strength sessions | count | Fitness and Strength |
| Average sleep | hours, one decimal place | Sleep and Stress |
| Sleep quality, stress level | 1 to 5 | Sleep and Stress |
| Tests skipped | Which tests were skipped for safety | Fitness and Strength |

Members with the eating-disorder flag may leave weight empty.

### C10. Reflection answers (Sleep and Stress)

| Field | What it holds | Example |
|---|---|---|
| Member, enrolment, when | Start, or end of week 4 | start |
| Answers | Each linked to its exact question version | 3, 2, 4 |

### C11. Weekly action plans

One per enrolment week.

| Field | What it holds | Example |
|---|---|---|
| Member, enrolment, week | Which plan | 5521, week 2 |
| Actions | 3 to 5 action versions from the library | — |
| Level | Easier, same, or stretch swapped in (FR2-10) | same |
| Completion used | **Named exception to rule 9:** activities done, activities available, the percentage, and the rule version, kept so the plan can be explained later | 9 of 14, 64 %, rule 1 |
| Swapped | Yes or no | no |
| Created on | Local date | 2026-11-09 |

### C12. Weekly reviews

| Field | What it holds | Example |
|---|---|---|
| Member, enrolment, week | Which review | — |
| Ready on | Local date; triggers the neutral email (FR2-11) | 2026-11-09 |
| Next steps | 1 or 2 activity versions | — |

The summary itself is built fresh from C8, C9 and C13 whenever it is opened, so a late tick shows up (requirements section 8.3).

### C13. Food diary

**Meal entries:**

| Field | What it holds | Example |
|---|---|---|
| Member, local date, meal | Breakfast, lunch, dinner or snack | 2026-11-04, lunch |
| Entry type | Ate the planned meal (Premium), or own description | own description |
| Planned recipe | The exact recipe version, when "ate the planned meal" is chosen | Lentil soup, version 2 |
| Description | Encrypted (S3) | Chicken salad and bread |
| Photo | Optional; JPG, PNG or HEIC up to 10 MB, kept as a protected file (S2) | — |
| Vegetables, protein source, sugary drink | Yes or no each | yes, yes, no |
| Portion | Small, normal or large; empty when the eating-disorder flag hides it | normal |
| Recorded at | UTC time | 2026-11-04 12:40 UTC |

**Daily entries:** one per member per local date.

| Field | What it holds | Example |
|---|---|---|
| Water, hunger, energy | Glasses; 1 to 5; 1 to 5 | 6, 3, 4 |

No calorie values are stored. Entries are built to carry over to the Stage 3 tracker.

### C14. Meal plans given to members

One per member per week.

| Field | What it holds | Example |
|---|---|---|
| Member, week starting | Local date | 2026-11-02 |
| Template version | Fixed when the week is given, so later edits don't change it | Blood Sugar vegetarian week 1, version 3 |
| Swaps | Each swapped meal and the recipe version chosen | Tuesday dinner = Chickpea curry, version 1 |

### C15. Suggestion history (FR2-06)

| Field | What it holds | Example |
|---|---|---|
| Member, item | Which suggestion | Article 1432 |
| Last opened on | For the 30-day no-repeat rule | 2026-11-04 |
| Hidden on | When "not for me" was chosen | — |

### C16. Badges earned

| Field | What it holds | Example |
|---|---|---|
| Member, badge, earned on | One per member per badge, except challenge badges, which can be earned once per challenge | 90 days active, 2027-01-30 |

### C17. Never stored

- the Sleep and Stress screening answers (FR2-38);
- the answers typed into either calculator;
- BMI, waist-to-height ratio, the current programme day, weekly summaries and shopping lists, which are always worked out fresh.

## 7. Area P — Payments and referrals

**Who decides access.** Stripe is the authority for whether a payment succeeded. The website's access always follows the mapping in T4.

### P1. Plans

Kept by Paid Memberships Pro as "membership levels".

| Plan | Catalogue price | Notes |
|---|---|---|
| Free | — | Any account without a Premium subscription |
| Premium yearly | 5999 USD a year, tax included | Shown first; includes Premium in the Stage 3 app |
| Premium monthly | 799 USD a month, tax included | |

### P2. Subscriptions

Kept by the membership plugin and Stripe. The website stores only what it needs to decide access.

| Field | What it holds | Example |
|---|---|---|
| Member, plan | Which account and plan | 10482, yearly |
| Website access state | Free, Premium, cancelled but in paid period, Premium ended, or refunded, worked out from Stripe as T4 describes | Premium |
| Stripe state | The latest state confirmed with Stripe | active |
| Started on, current period ends on | Dates | 2026-11-02, 2027-11-02 |
| Locked price | Amount and currency (requirements section 8.4) | 5999 USD |
| Pending switch to yearly | Date asked for; can be cancelled until the next renewal | — |
| Premium ended on, and why | Expired, failed payment or refunded | — |
| Renewal reminder sent on | Yearly plans | — |
| Stripe customer and subscription references | Links to Stripe; no card data | cus_…, sub_… |

### P3. Orders: payments, refunds and failed attempts

Kept by the membership plugin. One record per payment, refund or failed attempt. Refunds and failed attempts point to the payment they belong to.

| Field | What it holds | Example |
|---|---|---|
| Order number, member, date | The order | 88102, 10482, 2026-11-02 |
| Kind | Payment, refund, or failed attempt | payment |
| Related payment | For refunds and failed attempts | — |
| Catalogue price | In USD | 5999 USD |
| Amount charged | Amount and currency actually charged, tax included | 4999 GBP |
| Tax included | Amount and currency of the tax part | 833 GBP |
| Discount applied | Code or referral reward, with the amount and currency | LAUNCH50, 400 USD |
| Status | Pending, succeeded, failed or refunded | succeeded |
| Stripe payment and invoice references | Links to Stripe | pi_…, in_… |
| Billing name, email and country | For tax records; no street address (DM-10) | Sam Lee, sam@example.com, GB |

**After account deletion,** only the tax fields are kept: name, email, amounts, currencies, tax, date, country and Stripe references. They are not linked to any health data.

### P4. Discount codes

Kept by the membership plugin. Each code holds the code itself, the percentage off the first payment, the plans it applies to, valid-from and valid-to dates, and the number of times used.

### P5. Referrals and referral rewards (FR2-29)

**Referral:** one record per friend who signs up through a link.

| Field | What it holds | Example |
|---|---|---|
| Referral number, referrer, code | The link used | 301, 10482, sam-7k2q |
| Friend | The new account | 10611 |
| Friend's first payment | The order (P3) | 88140 |
| Status | Waiting (inside 14 days), qualified, or not qualified (refunded, over the yearly limit, or self-referral) | waiting |
| Self-referral check | Whether the friend's email or card matched the referrer's, using Stripe's card fingerprint, never the card number | no match |

**Reward:** one record for each person rewarded. A qualified referral creates two rewards, one for the referrer and one for the friend.

| Field | What it holds | Example |
|---|---|---|
| Reward number, referral, recipient | Who gets it | 612, 301, 10482 |
| Form | One month free (monthly), or 13 % off the next renewal (yearly), as a Stripe discount (D44) | 13 % off |
| Operation key | A unique key, so a retry can never create the reward twice | ref-301-referrer |
| Status | Pending, applied to a future payment, used, failed (to retry), or withdrawn | applied |
| Stripe discount reference | Link to the Stripe discount | di_… |
| Used on order | The payment it reduced | 88231 |
| Withdrawn on, and why | Only before it is used | — |

The limit of 3 rewards per calendar year is counted from qualified referrals. Rewards waiting to be used are lost if the account is deleted.

### P6. Stripe notices

| Field | What it holds | Kept for |
|---|---|---|
| Notice reference, type, received at | As sent by Stripe | 90 days |
| Processing state | Received, processed, failed or retrying, with the number of attempts and the last error | 90 days |

Each notice is processed once. When processing a notice, the website asks Stripe for the current state of that subscription or payment rather than trusting the notice's content or order. An older notice therefore cannot overwrite newer information (T4). A daily check compares access with Stripe and flags any mismatch to the owner.

## 8. Area E — Email list (visitors who are not members)

### E1. Contacts

| Field | What it holds | Example |
|---|---|---|
| Contact number, email, first name | First name is optional | alex@example.com |
| Source | The tagged link they came from | facebook-post-1029 |
| Marketing consent history | Each confirmation and unsubscribe, with dates | confirmed 2026-10-26 |

### E2. Requests

One per guide requested or waitlist joined, so a contact can ask for several.

| Field | What it holds | Example |
|---|---|---|
| Contact, kind, track | Guide or waitlist, and which track | waitlist, Blood Sugar Balance |
| Requested on | Date | 2026-10-26 |

**Rules for the email list:**

- **Before confirmation:** the confirmation email is the only message sent.
- **Unsubscribed contacts** are deleted 30 days after unsubscribing. Unconfirmed contacts are deleted after 30 days.
- **When a contact registers:** their requests and marketing consent history move into the member account. An unsubscribed contact stays unsubscribed. Registering never turns marketing back on.

## 9. Area F — Operations

| Record | What it holds | Personal? | How long |
|---|---|---|---|
| **F1. Erasure list** | One line per account deletion or health-consent withdrawal: member number, scrambled email, what was erased, date. A copy of each day's new lines is emailed to the owner's admin mailbox, so a website restore cannot remove it | Minimal | 60 days |
| **F2. Admin log** | Approvals, article fates, refunds, rewards withdrawn, price changes, preview-mode use | Owner, and members named in money actions | 2 years. When a member is deleted, their name and email are removed from log lines, which keep only the member number |
| **F3. Support messages** | Name, email, message, plan, date (FR2-30) | Yes | 2 years, or until account deletion if sooner |
| **F4. Surveys and testimonials** | Week 1 and week 4 answers, quote text, permission to quote, owner approval date, display name as first name and country (FR2-19) | Yes; "what changed for you" is health data | Until permission is withdrawn or the account is deleted. "What changed" answers are also deleted when health consent is withdrawn |
| **F5. Monthly "Something else" grouping** | Themes and counts only | No | 2 years |
| **F6. Weekly report** | Totals only (FR2-17) | No | 2 years |
| **F7. Facebook group removals** | Member number, date Premium ended, date removed; removals more than 7 days late are flagged (FR2-35) | Minimal | 1 year, or until account deletion |
| **F8. Error logs** | Technical errors, no personal or health data | No | 30 days |
| **F9. Test clock (test and staging sites only)** | One "today" setting per test member, used by the automated tests (Test Criteria section 3). It is never created, read or switched on in production | Test members only | Removed with the test member or its erasure; never present in production |

## 10. Protections for personal and health data

| # | Protection | What it means |
|---|---|---|
| S1 | **Members see only their own data** | Every page and every app request checks that the data belongs to the signed-in member (NFR2-02). |
| S2 | **Photos are never public** | Profile and diary photos are stored in a folder the web server will not serve directly. They are resized to at most 1,600 pixels and stripped of location data, and shown only to their member, after a check. |
| S3 | **Free text is encrypted** | "Something else" text and diary descriptions are encrypted, with the key kept outside the database. This protects against a copied database. A full hosting backup also contains the key, so it does not protect against a stolen full backup. The owner keeps a private offline copy of the key, so the data can be recovered if the server is rebuilt. |
| S4 | **Passwords, codes and keys are scrambled** | Stored only in hashed form. |
| S5 | **Downloads and caches** | Data downloads are made on request and not kept. Member pages are never cached. Caches and logs never hold health data. |
| S6 | **One erasure routine** | One routine removes a member from every table and file store in this document. It keeps only the exceptions listed in S7, and is used for account deletion, inactive-account deletion and health-consent withdrawal. For a consent withdrawal it removes Area C, F4 health answers and diary photos only. |
| S7 | **Exceptions, listed once** | After an account is deleted, only three things remain: the tax fields in P3, the erasure list line in F1 (60 days), and money-related admin log lines with the name and email removed (F2). Nothing else. |
| S8 | **Restores re-apply erasures before reopening** | Hostinger backups expire within 30 days. After any restore, the site stays closed to members until every erasure from the owner's mailbox copy is re-applied (T6). |
| S9 | **Nothing health-related leaves the site** | Only what requirements section 8.5 allows goes to Stripe, Plausible, email, AI or Facebook. |
| S10 | **Test data only** | Development and staging use made-up members only. |

## 11. The data download (FR2-04)

One ZIP file, made when asked for and not kept on the server. It contains:

- **A readable summary**, as a web page.
- **The data**, as one structured file with a version number, so the Stage 3 app can import it. On import, the app trusts the profile and journey data, but never plan, payment or role fields; those come from Stripe and the website.
- **The member's photos**: the profile photo and diary photos.

**Included:**

- account and settings (A1, A3);
- consent history (A2);
- goals and "Something else" requests (C1, C2);
- health profile and saved results (C3, C4);
- tracks, challenges, workouts and results (C5 to C8);
- check-ins and reflections (C9, C10);
- plans and reviews (C11, C12);
- food diary (C13);
- meal plans (C14);
- suggestion history (C15);
- badges (C16);
- subscription, orders and rewards (P2, P3, P5);
- email-list requests (E2);
- support messages, surveys and testimonials (F3, F4).

**Never included:** passwords, codes, app keys and other security records (A4).

## 12. Connection for the Stage 3 app (NFR2-09)

The app signs in with the member's own account and receives an app key (A4). It can do only what T5 allows. In short:

- It can read the member's own data and update their profile and journey.
- It can only **ask** for billing actions, which the website carries out through Stripe.
- It can never confirm a payment, grant Premium, award a reward, change a role, approve content, or touch another member's data.

## 13. Data model decisions (approved 25 October 2026)

| # | Decision | Proposed |
|---|---|---|
| DM-1 | Where member data lives | Lifeline Daily's own tables |
| DM-2 | Units | Metric stored; imperial shown on request |
| DM-3 | Photos | Protected folder, resized, location data removed |
| DM-4 | Free text | Encrypted; key outside the database, with an offline copy kept by the owner |
| DM-5 | Referral reward | A Stripe discount on the next payment (D44) |
| DM-6 | Suggestion history | Kept only with health consent |
| DM-7 | Email list | Contacts and requests kept separately; deleted 30 days after unsubscribing or an unconfirmed sign-up |
| DM-8 | Erasure list | Kept 60 days, with a daily copy emailed to the owner (D46) |
| DM-9 | Support messages and admin log | 2 years; support messages deleted with the account; admin log lines anonymised |
| DM-10 | Billing details | Name, billing email and country, kept for tax records; no street address (amended by the owner 25 October 2026) |
| DM-11 | Age | Age in years plus date entered (D42) |
| DM-12 | Member source | First tagged link saved on the account |
| DM-13 | Money | Every amount stored with its own currency |
| DM-14 | Content history | Every approved version kept; members' records point to versions |
| DM-15 | Programme days | Member's local calendar date; active periods recorded per enrolment |
| DM-16 | Free week | Not kept separately; resets when health data is erased (D45) |

## 14. Check against the final requirements

| Requirement | Data that supports it |
|---|---|
| FR2-01, FR2-02, NFR2-02 | A1, A4 |
| FR2-03, NFR2-01 | A2, F1, Area C consent rule, section 10 |
| FR2-04 | S6, S7, S8, F1, section 11 |
| FR2-05, FR2-06 | B1, B2, B11, C1, C2, C15, F5 |
| FR2-07 | Reads A1, C5, C11, C13, C14, P2 |
| FR2-08 | B3, B4, B5, B6, B16, C5, C8, T2, T3 |
| FR2-09 | C3, C8, C9, C10 |
| FR2-10, FR2-11 | B6, B11, C11, C12 |
| FR2-12 | A1 time zone, A3 |
| FR2-13, FR2-14, FR2-15 | P1, P2, P3, P4, P6, T4 |
| FR2-16 | B3 |
| FR2-17 | A1 "arrived from", F6, requirements section 8.5 allowlist |
| FR2-18, FR2-28 | E1, E2, B3 |
| FR2-19 | F4 |
| FR2-20, FR2-21, FR2-26 | B15 |
| FR2-22, FR2-27 | No member data needed |
| FR2-23 | C4 |
| FR2-24 | Stage 1 records, unchanged |
| FR2-25 | C3, B13 |
| FR2-29 | P5 |
| FR2-30 | F3, B17 |
| FR2-31 | A2 document versions |
| FR2-32 | B8, B9, C14 |
| FR2-33 | B7, C6 |
| FR2-34 | B10, C7, C8 |
| FR2-35 | B17, F7 |
| FR2-36 | B12, C9, C16 |
| FR2-37 | C13 |
| FR2-38 | B14; answers never stored |
| NFR2-06 | S8, F1, T6 |
| NFR2-07 | B13, B16 |
| NFR2-08 | F2 |
| NFR2-09 | Section 12, T5 |
| NFR2-03, NFR2-04, NFR2-05, NFR2-10, UI2-01, UI2-02, UI2-03, UI2-04 | No stored data needed |

---

## Appendix T — Technical rules for the build

### T1. Record numbers and integrity rules

Every record has a permanent internal number that is never reused. Links between records use these numbers.

| Record | Must always be true |
|---|---|
| A1 Member account | Email unique. Age 18 or over |
| A2 Consent history | Lines are only ever added, never edited |
| C1 Goals chosen | At most 3 per member; no goal twice; order values 1 to 3 unique per member |
| C3 Health profile | At most one per member. "None declared" can't be combined with any condition |
| C5 Track enrolments | At most one active enrolment per member. Active periods never overlap |
| C7 Workout progress | At most one active workout plan per member |
| C8 Results | One result per member, per occurrence: the same activity version on the same programme day of the same enrolment, challenge, plan or workout week |
| C9 Check-ins | One per enrolment week, or one per member per calendar month for monthly check-ins |
| C11 Weekly plans | One per enrolment week; 3 to 5 actions |
| C13 Daily diary entries | One per member per local date |
| C14 Meal plans given | One per member per week starting date |
| C16 Badges earned | One per member per badge; challenge badges once per challenge |
| P3 Orders | Each Stripe payment, refund or failed attempt appears once |
| P5 Rewards | Each qualified referral creates exactly two rewards, each with a unique operation key |
| P6 Stripe notices | Each notice reference is processed once |
| E2 Requests | One per contact per kind per track |

**Removing linked records.**

- Deleting a member removes all their records as S6 describes.
- Content is never hard-deleted while any member record points to one of its versions. It is retired instead.

### T2. Content versions

1. Saving an edit to any content item creates a new version, with a new fingerprint (B16).
2. A new version is not shown to members until approved. Until then, members see the last approved version.
3. Member records always store the version they used: activities done (C8), recipes in meal plans and the diary (C13, C14), questions answered (C10), plan actions (C11) and the safety matrix in force.
4. Old approved versions are kept for as long as any member record points to them.

### T3. Programme timing

1. **Local dates.** A member's programme day is a calendar date in their own time zone. Event times are stored in UTC. The local date is worked out at the time of the event and stored with it.
2. **Time-zone changes** apply from the member's next local date. Days already recorded are not changed.
3. **Programme day** = the number of local dates covered by the enrolment's active periods, counting the first date as day 1. Missed activities do not change it.
4. **Track week** = programme days 1 to 7 are week 1, days 8 to 14 week 2, and so on.
5. **Switching** closes the current active period on the last day of the current week. **Resuming** opens a new period starting the next day. The day count continues from where it stopped.
6. **When Premium ends** (P2), the enrolment's last open day is set to the local date on which the current track week ends. Nothing after that date opens until Premium is restored.
7. **Ticking late:** a result can be recorded up to 2 local dates after the date it belongs to.

### T4. From Stripe to website access

**How notices are handled.**

- Every Stripe notice is checked as genuine, recorded (P6) and processed once.
- For each notice, the website fetches the current state of the subscription or payment from Stripe. It never relies on the notice's own content or order.
- If processing fails, it is retried, up to 5 times over 24 hours, then flagged to the owner.

**Mapping from Stripe to the website's access state:**

| Stripe state | Website access state |
|---|---|
| Checkout started, not completed | Free |
| Active, paid | Premium |
| Active, set to cancel at the end of the period | Cancelled but in paid period |
| Payment failed, retrying (up to 7 days) | Premium (grace period) |
| Retries exhausted, or subscription ended | Premium ended. The current track week finishes (T3, rule 6) |
| First payment refunded | Refunded. The current track week finishes |
| Pending switch to yearly | Unchanged until the next renewal; the switch can be cancelled until then |

### T5. Stage 3 app permissions

| The app may | The app may not |
|---|---|
| Read the member's own account, profile, journey, subscription and orders | Read any other member's data |
| Update the member's own profile, goals, track choice, results, check-ins and diary, under the same consent and safety rules as the website | Confirm a payment, grant Premium, change a plan's price, or create or alter orders, rewards or discount codes |
| Ask for a billing action, such as switching to yearly or cancelling, which the website then carries out through Stripe | Change a role, an approval or the safety matrix |
| Request the member's data download | Bypass the erasure routine |

**App keys** belong to one member and one app installation. They expire after 90 days unless renewed. They can be withdrawn from the member's profile, and end automatically when the password is reset or the account is deleted.

### T6. Erasure and restore procedure

1. **Each erasure** (account deletion or health-consent withdrawal) writes a line to the erasure list (F1) and runs the erasure routine (S6).
2. **Each day,** the new erasure lines are emailed to the owner's admin mailbox.
3. **After any backup restore:**
   - the site is put into maintenance mode, closed to members;
   - every erasure line dated after the backup is re-applied, from the mailbox copies;
   - a check confirms none of those members' erased data is present;
   - only then is the site reopened.
4. **The restore test before launch** (NFR2-06) includes this procedure.
