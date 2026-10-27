# Lifeline Daily — Stage 2 Screen Design Specification (approved by the owner)

**What every Stage 2 screen must show, in what order, in which states, and how it must look and behave**

**Approved by the owner on 25 October 2026.** Prepared 25 October 2026 as a companion to the approved Stage 2 Requirements, Data Model and Test Criteria. Revised the same day after an independent review. Amended on 27 October 2026 to add SCR-45, the Healthy Weight Check (requirements FR2-39). Written in plain language. It is the brief for designing the screens. The designs are made as clickable prototypes in the owner's design canvas and approved against this document.

---

## 1. About this document

The requirements say what Stage 2 must do. This document says what each screen must contain and how it must behave, so that it does it.

**For each screen it gives:**

- **purpose:** what the screen is for;
- **who sees it:** which visitors or members;
- **content, top to bottom:** what appears, in order, on a phone;
- **states:** the variations to design, in addition to the standard states in section 4;
- **rules:** things the design must not get wrong;
- **requirements:** which requirements it serves.

**Numbering.** Screens are numbered in groups:

- SCR-01 to SCR-29: joining and the daily experience;
- SCR-30 to SCR-39: account, payments and help;
- SCR-40 to SCR-49: public pages.

Gaps leave room for additions. Emails are EM-01 to EM-21, owner screens are AD-01 to AD-14, and design decisions are UI-1 onwards.

**Order of authority.** If this document and the requirements disagree, the requirements win, and this document is corrected. Nothing here adds a feature that the requirements do not have.

**How it is used:**

1. Screens are designed in three batches (section 12), as clickable prototypes in the design canvas (https://claude.ai/artifact/27nkBo9HzLZKqq6MyUVLoa).
2. The owner reviews each batch against this document and approves it.
3. Approved screens become the visual baseline for the automated look checks (UI2-01 AS-01).
4. Behaviour that a screenshot can't show is checked through the accessibility notes (section 3.4) and the WCAG checklist (NFR2-04).
5. The four early Stage 2 artboards in the canvas (S2_Join, S2_Dashboard, S2_Membership, S2_Programme) predate the requirements and are replaced.

## 2. Design principles

1. **Phone first.** Designed at 360 pixels wide first. Checked at 320, 360, 768 and 1280 pixels, and at 200 % text size (section 3.4).
2. **The site's existing look,** with accessible versions of its colours (section 3.1). No new visual style (UI2-01).
3. **One main action per screen,** in a large green button.
4. **Plain, calm words.** Short sentences, no jargon, no fear, no pressure. Upgrade invitations are friendly and never pushy (FR2-16).
5. **Safety is always visible.** Safety notes, urgent-help messages and "see your doctor" advice are never hidden behind a tap, never in small print, and never behind the paywall.
6. **Safety is never sold.** Content excluded for safety never shows an upgrade button or a preview (section 5).
7. **Accessible to everyone.** WCAG 2.2 level AA (NFR2-04):
   - text contrast at least 4.5 to 1, and 3 to 1 for large text and for control borders;
   - tap targets at least 44 by 44 pixels;
   - every field has a visible label;
   - errors are given in words next to the field;
   - focus is always visible and never hidden behind a fixed bar.
8. **No dark patterns.** No pre-ticked boxes, no hidden cancel, no countdown timers, no guilt wording.
9. **What is never shown:**
   - calorie numbers (FR2-37);
   - a diagnosis;
   - trends or comparisons of calculator results;
   - a price or upsell inside a calculator result (FR2-16);
   - another member's information.
10. **Access follows confirmed facts.** A screen never shows Premium as active, or billing as stopped, until Stripe has confirmed it.

## 3. Look, feel and behaviour

### 3.1 Colours, with contrast checked

| Use | Colour | Contrast | Notes |
|---|---|---|---|
| **Main buttons** (white text) | #157a42 | 5.39 to 1 | Accessible version of the site green |
| Main button, hover and pressed | #0f5c31 | Higher | Darker on hover and press |
| **Text links** on white | #157a42 | 5.39 to 1 | Always underlined in body text |
| Brand highlight (decoration only, never text) | #3fd07f or #1f9d57 | — | For badges, progress fill and icons; not for text or white-text buttons |
| Page background, cards | #f7f8f8, #ffffff | — | |
| Main text | #161a17 | 16.5 to 1 | |
| Secondary text | #63706a | 4.87 to 1 on the page background | Never smaller than 14 px |
| **Form field border** | #7d8a84 | At least 3 to 1 | The pale line colour #e4eae6 is decoration only, never a field's only edge |
| Focus ring | #157a42, 3 px, with a 2 px white gap | — | On every interactive item |
| Disabled button | #e4eae6 background, #63706a text | — | Disabled buttons also say why, in words nearby |
| Caution text and border | #94600c on a light amber background | At least 4.5 to 1 | The earlier #a8690d measured 4.48 to 1 and is not used for text |
| Safety and urgent messages | #b5302f on a light red background | 6.12 to 1 on white | |
| Header and footer | #1c1c1c | — | |

The calculators' band colours are used only to show a saved calculator result exactly as the calculator showed it.

### 3.2 Type, shape and spacing

| Item | Rule |
|---|---|
| Typeface | Inter, falling back to the system font |
| Text sizes on a phone | Body 16 px; small print at least 14 px; screen title 24 px; section heading 18 px |
| Corners | 18 px on cards; buttons fully rounded |
| Spacing | 16 px side margins on phones; 8 px steps |
| Larger text | Layouts grow downwards when text is enlarged, and text is never shrunk or cut off to fit |

### 3.3 Shared components

| Component | Description | Used on |
|---|---|---|
| Main and secondary buttons | Full width on phones. Normal, hover, pressed, focus, disabled and busy states ("Saving…" with a spinner) | All |
| Text field | Label above, hint below, error below in words, with a 3-to-1 border | All forms |
| Password field | Show/hide eye button (FR2-01) | SCR-02, SCR-04, SCR-05, SCR-30 |
| Option list | Large tappable choices | SCR-07, SCR-08, SCR-13, SCR-25 |
| Step indicator | Shows the steps of the member's actual route ("Account, Email, Agreements, Goals"), never a fixed count | SCR-02 to SCR-07 |
| Card | White, rounded, title, short line, one action | All |
| Suggestion card | Type label, title, one-line reason, reviewer and date, one action, "not for me" (UI2-02) | SCR-09, SCR-20 |
| Activity card | Week and day, title, instructions, link, Done and Skip | SCR-10, SCR-12, SCR-17 |
| Progress bar and count | For example "9 of 28 done" | SCR-09, SCR-10, SCR-21 |
| Small chart | Simple line or bars, with a "Show as table" option underneath giving the same numbers | SCR-11, SCR-21 |
| Badge | Picture and name | SCR-21 |
| Safety banner | Red, with an icon, heading and action. Never dismissible | Section 5; SCR-08 |
| Caution note | Amber, for "check with your doctor" notes | Where the safety matrix applies |
| Info note | Mint, for tips | All |
| Membership lock | See section 5 | Premium items |
| Health-consent notice | See section 5 | Personalised features |
| "Not suitable for you" notice | See section 5 | Safety exclusions |
| Empty state | A friendly line and the next useful action | All lists |
| Timer | Large count-down with start and pause. Sound off by default | SCR-19 |
| Toast | A short message after saving, such as "Saved" or "Undo" | All editable screens |
| Member menu | See section 6 | All member screens |

### 3.4 Accessibility and behaviour notes

These are designed into the shared components, so every screen inherits them.

| Topic | Rule |
|---|---|
| **Reflow** | Every screen works at 320 pixels wide and at 200 % text size, with no sideways scrolling, except for wide tables, which scroll inside their own box |
| **Bottom bar and keyboard** | The bottom member bar hides while the on-screen keyboard is open. It never covers a focused field or button |
| **Focus after an error** | On a failed submit, focus moves to an error summary at the top that links to each field |
| **Focus in dialogs** | Focus moves into the dialog, stays there, and returns to the button that opened it when the dialog closes |
| **Focus after moving screens** | Focus goes to the new screen's main heading |
| **Announcements** | Saves, uploads, errors and "Saved" toasts are announced to screen readers politely, without interrupting |
| **Charts** | Every chart has a table with the same numbers, and a one-line summary such as "Waist down 3 cm since week 1" |
| **Timer** | Announces each new exercise and the final seconds of each stage, never every second |
| **Motion** | No auto-playing animation. Respects the device's "reduce motion" setting |
| **Links and buttons** | Names make sense on their own: "Swap Tuesday dinner", not "Swap" |

## 4. Standard states for every screen

Every screen that loads or saves something is designed with these states, as well as its own states in sections 7 to 10:

| State | What it shows |
|---|---|
| **Loading** | A light placeholder shaped like the content, never a blank page |
| **Saving** | The button shows "Saving…", and the screen stays usable for reading |
| **Saved** | A toast confirmation, with "Undo" where the action can be undone |
| **Save failed** | A plain message: "We couldn't save that. Check your connection and try again", plus "Try again". The member's entry is kept |
| **No connection** | A banner saying "You're offline". Entries wait and are retried, or the member is told they weren't saved |
| **Not allowed** | Signed out: the sign-in screen, then a return to where the member was. Another member's address: "This page isn't available" |
| **Membership lock, health consent needed, not suitable** | Section 5 |

## 5. Three reasons something isn't available

They look different, so a member always knows why.

| Reason | Design | Never |
|---|---|---|
| **Membership lock:** the item is Premium | A faded preview, a lock icon, "Part of Premium", one line on what Premium adds, the price, and an upgrade button (FR2-15) | Shown for content the member is not allowed for safety reasons |
| **Health consent needed:** a personalised feature needs health consent | An explanation of what the feature needs and why, a link to switch on health consent, and what is available without it, such as general meal plans or workouts | Pressure to switch consent on |
| **Not suitable for you:** the safety matrix excludes it | No preview and no upgrade. A short reason, for example "Not suggested during pregnancy". The matrix note, where there is one, and a link to a safe alternative | An upgrade button, a preview, or any way to open the content |

**Where these apply:**

- the dashboard and suggestions;
- direct and saved links;
- swaps;
- plans;
- meal plans and recipes;
- workouts;
- challenges;
- badges;
- downloads and printed shopping lists.

**After a profile change,** content that has just become unsuitable switches to "not suitable for you" at once, including on screens already open, which are refreshed.

## 6. Navigation map

**Signed-out header:** Health Tools (three calculators), Articles (categories), Programmes (the four tracks), Pricing, Sign in, Join free.

**Member menu.** On a phone, a bottom bar with five items. On a desktop, the same items in the site header.

| Item | Goes to | Contains |
|---|---|---|
| **Today** | SCR-09 Dashboard | Today's activity, plan, meals, diary shortcut, progress, suggestions |
| **Track** | SCR-10 Track overview | Current track, weekly check-in (SCR-11), weekly plan (SCR-12), weekly review (SCR-14), start, switch or resume (SCR-25) |
| **Meals** | SCR-15 Meal plan | Week, recipes, shopping list |
| **Diary** | SCR-16 Food diary | Today, log a meal, weekly summary |
| **More** | A simple list | Workouts (SCR-18), Monthly challenge (SCR-17), Progress and badges (SCR-21), Suggestions (SCR-20), Members' group (SCR-22), Profile and settings (SCR-30), My subscription (SCR-31), My data (SCR-35), Help (SCR-33), Sign out |

**Main journeys:**

1. **Joining:** SCR-02 Register, SCR-03 Confirm, SCR-06 Agreements, SCR-07 Goals, SCR-25 Start a track (through SCR-08 for Sleep and Stress), SCR-09 Dashboard.
2. **Upgrading:** SCR-24 Membership lock or SCR-01 Pricing, then SCR-32 Checkout handover, Stripe, SCR-32 Return.
3. **Daily use:** SCR-09, SCR-10, SCR-16, SCR-11 weekly, SCR-14 weekly.
4. **Leaving:** SCR-31 Cancel, SCR-36 Withdraw consent, or SCR-36 Delete account.

## 7. Screens — joining and starting

### SCR-01 Pricing page

- **Purpose:** explain free and Premium, and start joining or upgrading.
- **Who:** everyone.
- **Content, top to bottom:**
  1. **A short block at the top:** yearly first ("US$59.99 a year, best value, tax included"), monthly ("US$7.99 a month, tax included"), three lines on what Premium includes, one line on how to cancel and the refund, and the main button.
  2. The full list of free and Premium features, matching requirements section 3.
  3. Questions and answers.
- **States:** visitor; free member ("Upgrade"); Premium member ("You're Premium"); launch discount active.
- **Rules:** on a 360 by 640 screen at normal text size, the top block is fully visible without scrolling (UI2-03). The local currency appears only at Stripe's checkout.
- **Requirements:** FR2-13, FR2-16, UI2-03.

### SCR-02 Register

- **Content:**
  1. First name and last name.
  2. Email.
  3. Password, with the eye button and "at least 10 characters".
  4. Age in years.
  5. The covering statement: "Lifeline Daily relies on the age you give us. Content and results are based on it."
  6. Links to the terms and privacy policy.
  7. "Create account".
  8. "Already a member? Sign in."
- **States:** email already used; password too short; under 18 (a polite message that membership is for adults, with links to the free calculators and articles).
- **Requirements:** FR2-01.

### SCR-03 Check your email, and confirmed

- **Content:** "We've sent a link to [email]"; resend; after confirming, "Email confirmed" with "Continue".
- **States:** link expired (resend); link already used ("Already confirmed — sign in"); resend limit reached ("Please wait a few minutes").
- **Requirements:** FR2-01.

### SCR-04 Sign in

- **Content:** email; password with eye button; "Remember me for 30 days"; "Sign in"; "Forgot password?"; "Join free".
- **States:**
  - wrong details: one message that never reveals whether the email exists;
  - locked for 15 minutes;
  - two-step code step: 6-digit code entry with resend;
  - returning to the page the member wanted.
- **Requirements:** FR2-01, NFR2-02.

### SCR-05 Reset password

- **Steps:**
  1. Enter email. The reply is always "If an account exists, we've sent a link", which never reveals whether an account exists.
  2. Check your email.
  3. New password, with the eye button.
  4. "Password changed. Other devices have been signed out."
- **States:** link expired; link already used; resend.
- **Requirements:** FR2-01.

### SCR-06 Agreements and consent

- **Content, top to bottom:**
  1. Terms and privacy: a required tick box with links.
  2. **Health information:** an optional switch, off. A plain list of what it allows (goals, tracks, profile, diary, check-ins) and what works without it.
  3. **Marketing emails:** an optional switch, off.
  4. "Continue".
- **Rules:** no wording pushes the member to switch anything on.
- **Requirements:** FR2-03, NFR2-01.

### SCR-07 Choose your goals

- **Content:** "Choose up to 3 goals"; the four track goals with one-line descriptions; the free goals; "Something else" (opens a text box); "Continue" and "Skip for now".
- **States:**
  - three chosen: the others are disabled, with "You can choose up to 3";
  - without health consent: replaced by the health-consent notice (section 5);
  - goals not yet available are not listed.
- **Requirements:** FR2-05.

### SCR-25 Start, switch, resume or repeat a track

- **Purpose:** choose which one track to follow, and when (FR2-08, requirements section 8.3).
- **Content:**
  1. The member's track goals first, then the other tracks. Each shows a one-line promise and its status: "Free week available", "Free week used", "In progress, week 2", "Set aside at day 9", or "Finished".
  2. Choose a track.
  3. Start date: today or tomorrow.
  4. "Start".
- **States to design:**
  - **Starting** the first track, with the free week available.
  - **Free week used, free member:** starting needs Premium (membership lock).
  - **Switching:** shown only at the end of a week. "Your progress on [track] is kept, and you can resume it later."
  - **Mid-week:** "You can switch at the end of this week on [date]."
  - **Resuming** a set-aside track: "You'll continue from day 10."
  - **Repeating** a finished track (Premium).
  - **Sleep and Stress:** always opens SCR-08 first, including on resume and repeat.
  - **Not suitable:** for example Healthy Weight during pregnancy, using the "not suitable for you" design.
- **Requirements:** FR2-08, FR2-38, FR2-25.

### SCR-08 Sleep and Stress safety check

- **Content:**
  1. A short explanation of why we ask.
  2. Three separate yes or no questions: thoughts of self-harm; panic attacks; low mood for 2 weeks or more.
  3. "Continue", available only when all three are answered.
- **Responses:**
  - **Self-harm, yes, alone or with any other answer.** A red safety banner: "If you are in danger now, call your local emergency number", with the common examples (999 in the UK, 911 in the US and Canada, 112 in the EU and India, 000 in Australia). Below it, a findahelpline.com button "Find support in your country", and kind words. No option to start the track. This response overrides every other answer.
  - **Panic, yes:** an amber note advising seeing a doctor, then "Continue".
  - **Low mood, yes:** an amber note advising seeing a doctor, then "Continue".
  - **Panic and low mood, both yes:** both notes together, then "Continue".
- **Rules:**
  - The wording is approved by the owner.
  - The self-harm response is never shortened, hidden or skippable.
  - Going back with the browser, or returning later, shows the questions again, unanswered. Answers are never stored.
- **Requirements:** FR2-38 (amended by decision D48).

## 8. Screens — the daily experience

### SCR-09 Dashboard

- **Order, fixed (FR2-07):**
  1. Greeting, with display name and photo.
  2. **Today's activity,** with track name, week and day.
  3. This week's plan.
  4. Today's meals.
  5. Food diary shortcut.
  6. Progress ("9 of 28 done").
  7. Suggestions (up to 6).
  8. Membership.
- **States:**
  - free member, with "what Premium adds" after progress;
  - Premium;
  - monthly member after month 1, with the switch-to-yearly prompt (SCR-31 rules);
  - no health consent: the general path, with no track, diary or check-in;
  - no track yet: "Start a track" leads to SCR-25;
  - track set aside;
  - pregnant member: Healthy Weight not offered, with its note;
  - Premium ended mid-week: "Your current week stays open until [date]";
  - suggestions empty: a safe message.
- **Requirements:** FR2-07, UI2-02, FR2-06, requirements section 8.3.

### SCR-10 Track overview and day

- **Overview:** the four weeks with themes, each shown as done, open or locked; track progress; and links to the check-in, plan, review and SCR-25.
- **Day view:**
  1. Activity card (instructions, link, Done and Skip).
  2. The lesson, when there is one.
  3. The weekly task.
  4. "Catch up": the previous 2 days that can still be marked.
- **States:**
  - activity already marked, with an option to change it;
  - 3 days late: can no longer be marked, with a note;
  - free member on day 8: membership lock;
  - not suitable: a replacement activity;
  - resumed track: "Welcome back, day 10";
  - Premium ended: the current week open until its last day, then locked;
  - finished: next track or challenge suggested;
  - save failed.
- **Requirements:** FR2-08, FR2-09.

### SCR-11 Weekly check-in

- **One design per track,** using exactly that track's measures (FR2-09).
- **Fitness and Strength:** the safety instructions come before the tests, with "Skip this test".
- **Also shown:**
  - height asked once if missing;
  - metric or imperial;
  - after saving, the change since week 1, a small chart with its table, and plain range guidance.
- **Also designed:** the monthly check-in; the eating-disorder state (weight optional); and the Sleep and Stress reflection questions, labelled as personal reflection.
- **States:**
  - first check-in, with no comparison yet: "This is your starting point";
  - a check-in skipped last week;
  - correcting this week's entry;
  - not enough data for a chart.
- **Rules:** no diagnosis wording, and no red-or-green judgement of the member's numbers.
- **Requirements:** FR2-09, FR2-36.

### SCR-12 Weekly action plan

- **Content:** 3 to 5 action cards; the level ("A lighter week" or "One stretch goal"); why the plan was chosen; "Swap my plan".
- **States:**
  - swap already used: "You can swap again next week";
  - no safe alternative for a swap;
  - fewer than 3 safe actions, with a note;
  - none;
  - free member: membership lock.
- **Requirements:** FR2-10.

### SCR-13 Health profile

- **Content:**
  1. Sex.
  2. Height, weight and waist, with a unit switch and "how to measure your waist".
  3. Activity level.
  4. Diet type.
  5. Ethnicity.
  6. Conditions: "None", "Prefer not to answer", or one or more conditions.
  7. Saved calculator results, shown exactly as the calculator showed them.
- **States:**
  - check-your-value prompts;
  - a warning before saving a condition that hides content, for example "Because you are pregnant, the Healthy Weight track and weight-loss content won't be shown";
  - "None" can't be selected with a condition.
- **Requirements:** FR2-25, FR2-23.

### SCR-14 Weekly review

- **Content:** what was done; the check-in change; the diary summary; 1 or 2 next steps; an encouraging close.
- **States:**
  - good week;
  - average week;
  - missed week, which never shames the member;
  - no check-in this week;
  - not enough data: "Not enough this week to compare";
  - week 4, suggesting the next track or challenge;
  - free member: a shorter review with an invitation.
- **Requirements:** FR2-11.

### SCR-15 Meal plan week, recipe and shopping list

- **Week view:** 7 days with breakfast, lunch, dinner and snack, and a "Swap" button on each meal.
- **Recipe view:** photo, ingredients in the member's units, method, servings and suitability.
- **Shopping list:** grouped by type of food, with tick boxes, "Print" and "Download PDF". **Ticks are remembered on that device and browser only** (UI-3).
- **States:**
  - personalised plan;
  - general plan for a Premium member without health consent, labelled "General plan";
  - safety exclusions, with fewer swaps and a note;
  - no safe swap for a meal;
  - no safe meals at all: a safe message and advice to contact support;
  - PDF couldn't be made: "Try again";
  - free member: membership lock showing one sample day.
- **Rules:** no calories.
- **Requirements:** FR2-32.

### SCR-16 Food diary

- **Today view:** four meals, each showing "Logged" or "Add".
- **Log a meal:**
  - **Premium:** "I ate the planned meal", which saves in one tap and shows a toast with "Undo" and "Add details".
  - **Everyone:** a short description; an optional photo; three yes or no checks (vegetables, protein source, sugary drink); and a separate **portion size** control (small, normal or large).
- **Photo:** JPG, PNG or HEIC, up to 10 MB. Shows upload progress, with replace and remove. "Retry" if the upload fails, and "This file is too large or not a supported type" if refused.
- **Day extras:** glasses of water with plus and minus buttons; hunger and energy from 1 to 5.
- **Weekly summary:** for example "Vegetables at 9 of 14 meals", with the track focus.
- **States:**
  - editing an entry;
  - free member in a free week;
  - free member outside a free week: read-only, with an invitation;
  - eating-disorder flag: no portion control;
  - no health consent: the health-consent notice.
- **Rules:** no calories anywhere.
- **Requirements:** FR2-37.

### SCR-17 Monthly challenge

- **Content:** this month's challenge; "Join"; today's action; progress through 28 days; finish badge.
- **States:** not joined; joined; finished; without health consent (usable, with "progress isn't saved"); free member (membership lock).
- **Requirements:** FR2-33.

### SCR-18 Workout plans

- **Content:** choose a level and version (home or equipment); this week's sessions; "Move up a level".
- **Rules (UI-4):** the member can move up a level at any time. After finishing a level's 4 weeks, they are invited to move up.
- **States:** without health consent (usable, "progress isn't saved"); free member (membership lock).
- **Requirements:** FR2-34.

### SCR-19 Workout session

- **Content:**
  1. Safety advice first, with "I'm ready".
  2. Each exercise with plain instructions, the timer, then rest.
  3. "Done".
- **Rules:** large one-hand buttons. The timer follows section 3.4. The screen-awake feature is not used (UI-2).
- **Requirements:** FR2-34.

### SCR-20 Suggestions list

- **Content:** all suggestions as cards with "not for me". General wellbeing items are labelled.
- **States:** a safe empty state.
- **Requirements:** FR2-06.

### SCR-21 Progress and badges

- **Content:** activity totals, check-in charts across all months (with tables), and badges.
- **States:**
  - no history yet;
  - pregnancy or eating-disorder flag: weight-loss badges never shown;
  - Premium ended: history readable, with "Download my data".
- **Requirements:** FR2-36.

### SCR-22 Members' group and live Q&A

- **Content:** the next live Q&A date; "Join the private Facebook group"; the note that Facebook's own privacy terms apply.
- **States:**
  - free member: membership lock;
  - Premium ended: "You'll be removed from the group within 7 days";
  - join request sent: "The owner will approve your request".
- **Requirements:** FR2-35.

### SCR-23 Calculator landing page additions

- **Additions below the unchanged calculator:**
  - "Save this result to my profile", for signed-in members with health consent, with a "Saved" state;
  - the gentle link to the related track;
  - the starter-guide block (SCR-42).
- **Rules:** the calculator and its result are not changed. No price or upsell appears in or beside the result.
- **Applies to:** all three calculators. For the Healthy Weight Check, the save button appears only after an eligible result, and the track link and starter-guide block are hidden after a stop, an underweight result or the weight-loss banner, and for a member whose profile excludes the Healthy Weight track (SCR-45).
- **Requirements:** FR2-23, FR2-16, FR2-18, FR2-39.

### SCR-24 Membership lock

The full-page version of the membership lock (section 5), for direct links to Premium pages.

- **Rules:** never a blank page. Never used for safety exclusions.
- **Requirements:** FR2-15.

### SCR-26 Not suitable for you

The full-page version of the "not suitable for you" design (section 5), for direct links, saved links and downloads of excluded content.

- **Requirements:** FR2-25, requirements section 8.2.

## 9. Screens — account, payments and help

### SCR-30 Profile and settings

- **Sections:**
  - name, email, password and two-step sign-in;
  - age, which can't be set to under 18;
  - time zone and units;
  - display name and photo, with remove;
  - email preferences, each a switch, plus the reminder time;
  - consents, each with a switch and its date;
  - links to My subscription (SCR-31), My data (SCR-35) and Delete account (SCR-36).
- **States:**
  - email change pending: "Check [new address] to confirm. Your current email still works until then";
  - email change link expired;
  - photo removed;
  - save failed.
- **Requirements:** FR2-02, FR2-03, FR2-12, NFR2-02.

### SCR-31 My subscription

- **Content:**
  - plan, the member's own current price (tax included) and next renewal date;
  - receipts;
  - referral link, with "Copy" and "Share";
  - rewards waiting, each saying what it is ("One month free" or "13 % off your next yearly renewal"), when it applies ("after [friend]'s 14 days, on [date]") and which payment it will reduce;
  - "Cancel" in 3 clicks or fewer.
- **Switch to yearly** (monthly members only): the member's current monthly price, the yearly price, the date it would start, and the member's actual saving, worked out from their own prices.
- **States:**
  - pending switch, with "Undo";
  - cancelled, still in the paid period: "Premium until [date]";
  - Premium ended or refunded: what stays readable;
  - payment failed and retrying: "Update your card";
  - cancellation processing: "We're confirming your cancellation with our payment provider". It never says billing has stopped until Stripe confirms;
  - free member.
- **Requirements:** FR2-13, FR2-14, FR2-29.

### SCR-32 Checkout handover and return

- **Before Stripe:** "You'll pay securely with Stripe. We never see your card details."
- **Return states:**
  1. **Confirmed:** "Welcome to Premium", with what to do first.
  2. **Being confirmed:** "We're confirming your payment. This usually takes less than a minute. Please don't pay again." Shows "Check again", and switches automatically when confirmed.
  3. **Cancelled at checkout:** "No payment was taken", with "Back to pricing".
  4. **Failed:** "The payment didn't go through. You haven't been charged", with "Try again".
  5. **Temporarily unavailable:** "We can't reach our payment provider right now. Please try again shortly."
- **Rules:** Premium unlocks only when Stripe confirms the payment, never from the return page alone. Stripe's own page is not redesigned.
- **Requirements:** FR2-14, NFR2-06.

### SCR-33 Help and contact

- **Content:** questions and answers on billing, cancelling, refunds, referrals and data; the contact form; "We aim to reply within 2 working days".
- **States:** message sent; send failed.
- **Requirements:** FR2-30.

### SCR-34 Surveys

- **Content:** the week 1 and week 4 surveys. A 0 to 10 scale, one open question, and at week 4 "What changed for you?" plus a separate permission tick box to quote first name and country.
- **Requirements:** FR2-19.

### SCR-35 My data

- **Content:** "Download all my data". One ZIP file holding a readable summary, the data and your photos (profile and diary). Passwords and security keys are never included.
- **States:** preparing ("This can take a minute"); ready ("Download"); failed ("Try again").
- **Requirements:** FR2-04, NFR2-01.

### SCR-36 Withdraw health consent, and delete account

Two separate flows. Each says plainly what will and won't happen.

**Withdraw health consent:**

1. **Explanation.** It lists what will be deleted: your goals, track progress, health profile, check-ins, food diary and diary photos, saved results and "what changed" survey answers. It also says that personalised tracks and tracking will stop. In bold: **"Your subscription continues. To stop paying, cancel it separately."** It links to SCR-31.
2. **Confirm.**
3. **Done.** It confirms what was deleted, and what still works.

**Delete account:**

1. **Explanation.** It lists what will be deleted, and what is kept for tax law: your name, email, payment amounts and dates. Any subscription will be cancelled.
2. **Download your data first?** A link to SCR-35.
3. **Confirm with your password.**
4. **Processing.**
5. **Done.** "Your account is closed. Your data will be removed within 24 hours."
6. **Partial failure state:** "Your account is closed. We're finishing cancelling your subscription with our payment provider and will email you when it's done." It never claims billing has stopped until Stripe confirms.

**Requirements:** FR2-03, FR2-04.

### SCR-37 Small confirmation pages

- **Email change confirmed:** "Your email is now [address]".
- **Unsubscribed:** "You won't get these emails any more", with a link to email preferences. A second click shows "Already unsubscribed".
- **Link expired:** an explanation, with a way to get a new link.
- **Requirements:** FR2-02, FR2-12.

## 10. Screens — public pages and articles

### SCR-40 Track sales page

One design, used for all four tracks.

- **Content, top to bottom:**
  1. **A short block at the top:** headline, price (yearly first, tax included), three lines on what's included, one line on how to cancel, and the main button.
  2. Who it's for and who it's not for.
  3. The four weekly themes.
  4. A sample activity and a sample meal plan day.
  5. Testimonials, once available.
  6. Questions and answers.
  7. The safety note where relevant, for example for pregnancy.
- **Rules:** the top block passes the same no-scroll rule as SCR-01 (UI2-03).
- **Requirements:** FR2-16, UI2-03.

### SCR-41 Waitlist page ("coming soon")

- **Content:** the track's promise, the four themes, an email sign-up, and the launch discount note.
- **States:** "Check your email to confirm"; confirmed.
- **Requirements:** FR2-28.

### SCR-42 Starter-guide sign-up block

- **Content:** the guide name, an email field and a button.
- **States:** confirm your email; confirmed, with a download link; already signed up.
- **Requirements:** FR2-18.

### SCR-43 Article additions

- **Content:**
  1. A direct-answer box at the top, 60 words or fewer.
  2. A frequently asked questions section.
  3. An author and review box: photo, name, credentials, and "Reviewed by [name] on [date]".
  4. A sources list with dates.
  5. The related track link and the starter-guide block.
- **Requirements:** FR2-20, FR2-21, and the Stage 1 review details (FR1-18).

### SCR-44 Category hub page

- **Content:** the category's main guide article, the article list, and the related track.
- **Requirements:** FR2-26.

### SCR-45 Healthy Weight Check

- **Where:** the calculator page, embedded in its landing page `/health-tools/healthy-weight-check/`, with the SCR-23 additions below it. Its card on the Health Tools page replaces "Healthy weight (planned)".
- **Form, in order:**
  1. Age in years.
  2. "Are you pregnant or breastfeeding?" Yes, No, Not sure.
  3. "Do you have, or have you had, an eating disorder, or are you worried about your eating?" Yes, No, Prefer not to say, with one line on why it is asked.
  4. Height and weight, each with its own unit choice and a switch-all-units button.
  5. Waist, optional, with "How to measure your waist".
  6. Family background, as tap buttons; "Mixed or other" opens one follow-up question.
  7. "Have you lost weight in recent months without trying?" Yes, No.
- **Result, in order:** any banner; the BMI and its category; the healthy weight range "a guide for your height, not a target"; the waist-to-height ratio and band, or the "add your waist" note, or the BMI-35 note; what it means; limits; method and sources with the version; disclaimer.
- **States:**
  - empty form; field errors with an error summary at the top;
  - result, standard thresholds; result, lower thresholds;
  - no waist; ratio below 0.40; BMI 35.0 or more;
  - underweight, with the eating-support line;
  - obesity class 3 note; age 65 or over note;
  - weight-loss banner above the result;
  - stop: under 18; stop: pregnancy or breastfeeding; stop: eating disorder (with findahelpline.com). A stop shows no numbers;
  - unavailable (withdrawn), and could-not-check-status with a retry button.
- **Rules:** category text never relies on colour alone. Never a calorie number, a weight-loss target, a price or an upsell.
- **Requirements:** FR2-39, FR2-23.

## 11. Emails

All emails are phone-friendly, at most 600 pixels wide, with the logo, short wording, one button and no tracking pixels. Non-transactional emails end with one-click unsubscribe (UI2-04). **No email contains health details.**

**To members and contacts:**

| No. | Email | Main content | Requirements |
|---|---|---|---|
| EM-01 | Confirm your email | Link, valid for 24 hours | FR2-01 |
| EM-02 | Reset your password | Link, valid for 60 minutes | FR2-01 |
| EM-03 | Two-step sign-in code | 6-digit code, valid for 10 minutes | NFR2-02 |
| EM-04 | Welcome | What to do first | FR2-12 |
| EM-05 | Daily reminder | "Today's activity is ready" | FR2-12 |
| EM-06 | Weekly review ready | Notice and link only | FR2-11 |
| EM-07 | Pick up where you left off | One friendly nudge | FR2-12 |
| EM-08 | End of track | Congratulations and the next step | FR2-12 |
| EM-09 | Receipt and yearly renewal reminder | Amount, tax included, plan, date | FR2-14 |
| EM-10 | Payment failed | "Update your card" | FR2-14 |
| EM-11 | Account deleted, and subscription cancellation confirmed | First name only | FR2-04 |
| EM-12 | Starter guide and waitlist | Confirm sign-up; guide link; launch announcement with discount | FR2-18, FR2-28 |
| EM-13 | Confirm your new email address | Link to the new address; a notice to the old one | FR2-02 |
| EM-14 | Complete your profile | The single profile reminder | FR2-25 |
| EM-15 | A goal you asked for is now available | Link to choose it | FR2-06 |
| EM-16 | Inactive account warning | Deletion date, and "Sign in to keep your account" | NFR2-01 |
| EM-17 | Your referral reward | What it is and which payment it reduces | FR2-29 |

**To the owner:**

| No. | Email | Requirements |
|---|---|---|
| EM-18 | Weekly report (Monday) | FR2-17 |
| EM-19 | Monthly "Something else" themes and content gaps | FR2-06 |
| EM-20 | Daily erasure list | FR2-04 |
| EM-21 | Alerts: Stripe cancellation failed, payment and access mismatch, removal overdue, monthly SEO checklist | FR2-04, FR2-14, FR2-35, FR2-22 |

## 12. Owner screens

These sit inside the WordPress admin area, in its standard look, and must be usable without a developer (NFR2-08). They are designed lightly, as a list of fields and actions rather than polished screens.

| No. | Owner task | How it is done | Requirements |
|---|---|---|---|
| AD-01 | Approve content | New approvals queue: what changed, whether AI-drafted, approve or send back | FR2-08, NFR2-07 |
| AD-02 | Find and help a member; refund | New members screen | NFR2-08, FR2-14 |
| AD-03 | Prices and discount codes | Paid Memberships Pro's own screens | FR2-13 |
| AD-04 | Referral rewards | New simple list: correct or withdraw | FR2-29 |
| AD-05 | Article inventory and fates | New list screen | FR2-26 |
| AD-06 | Reports | New screen with the weekly report, "Something else" themes and content gaps | FR2-17, FR2-06 |
| AD-07 | Facebook group removals | New simple list with "Mark removed" | FR2-35 |
| AD-08 | Preview as visitor, free or Premium | New switch in the admin bar | NFR2-08 |
| AD-09 | Goals and tags | WordPress's standard category-style screens | FR2-05 |
| AD-10 | Safety matrix | New table editor; changes go through approval | FR2-25 |
| AD-11 | Tracks, weeks, lessons, activities, challenges | WordPress's standard edit screens, with extra fields | FR2-08, FR2-33 |
| AD-12 | Recipes, meal plans, workout plans | WordPress's standard edit screens, with extra fields | FR2-32, FR2-34 |
| AD-13 | Live Q&A schedule | A simple settings screen | FR2-35 |
| AD-14 | Suggestion rules and badges | WordPress's standard edit screens, with extra fields | FR2-06, FR2-36 |

## 13. Design decisions

| # | Decision | Answer |
|---|---|---|
| UI-1 | Emergency numbers | No per-country list. A fixed message with common examples, plus findahelpline.com (requirements decision D48) |
| UI-2 | Keeping the screen awake during workouts | Not used |
| UI-3 | Shopping list ticks | Remembered on that device and browser only |
| UI-4 | Moving up a workout level | Any time; invited after finishing a level's 4 weeks |
| UI-5 | Button and link colour | #157a42, the accessible version of the site green |
| UI-6 | Caution colour | #94600c |

## 14. Design batches and approval

| Batch | Screens | Done when |
|---|---|---|
| **1. Joining and the core journey** | Sections 3 to 6 (shared components, states, navigation); SCR-01 to SCR-12, SCR-24 to SCR-26, SCR-32 | The owner approves |
| **2. Premium features** | SCR-13 to SCR-23 | The owner approves |
| **3. Account, public pages, emails and owner screens** | SCR-30, SCR-31, SCR-33 to SCR-37, SCR-40 to SCR-45, EM-01 to EM-21, AD-01 to AD-14 | The owner approves |

**Each batch is delivered as:**

- clickable prototypes at 360 and 1280 pixels wide, with spot checks at 320 and 768 pixels and at 200 % text;
- every state listed, using the made-up test members from the Test Criteria, for example TM-06 for pregnancy.

**After approval,** screens are exported to the repository (`design/canvas/project/`) and become the visual baseline for UI2-01. A later change to an approved screen needs a fresh approval.

## 15. Check against the requirements

| Requirement | Screens |
|---|---|
| FR2-01 | SCR-02, SCR-03, SCR-04, SCR-05; EM-01, EM-02 |
| FR2-02 | SCR-30, SCR-37; EM-13 |
| FR2-03 | SCR-06, SCR-30, SCR-36 |
| FR2-04 | SCR-35, SCR-36; EM-11, EM-20, EM-21 |
| FR2-05 | SCR-07; AD-09 |
| FR2-06 | SCR-09, SCR-20; EM-15, EM-19; AD-06, AD-14 |
| FR2-07 | SCR-09 |
| FR2-08 | SCR-10, SCR-25; AD-01, AD-11 |
| FR2-09 | SCR-10, SCR-11 |
| FR2-10 | SCR-12 |
| FR2-11 | SCR-14; EM-06 |
| FR2-12 | SCR-30, SCR-37; EM-04, EM-05, EM-07, EM-08 |
| FR2-13 | SCR-01, SCR-31; AD-03 |
| FR2-14 | SCR-31, SCR-32; EM-09, EM-10, EM-21; AD-02 |
| FR2-15 | SCR-24; section 5 |
| FR2-16 | SCR-01, SCR-23, SCR-40 |
| FR2-17 | AD-06; EM-18 |
| FR2-18 | SCR-23, SCR-42; EM-12 |
| FR2-19 | SCR-34 |
| FR2-20, FR2-21 | SCR-43 |
| FR2-22 | EM-21 (monthly checklist); otherwise behind-the-scenes work |
| FR2-23 | SCR-13, SCR-23 |
| FR2-24 | No screen |
| FR2-25 | SCR-13, SCR-25, SCR-26; EM-14; AD-10 |
| FR2-26 | SCR-44; AD-05 |
| FR2-27 | No screen; behind-the-scenes work |
| FR2-28 | SCR-41; EM-12 |
| FR2-29 | SCR-31; EM-17; AD-04 |
| FR2-30 | SCR-33 |
| FR2-31 | Legal pages use the site's existing page design; linked from SCR-01, SCR-02, SCR-06 and SCR-32 |
| FR2-32 | SCR-15; AD-12 |
| FR2-33 | SCR-17; AD-11 |
| FR2-34 | SCR-18, SCR-19; AD-12 |
| FR2-35 | SCR-22; EM-21; AD-07, AD-13 |
| FR2-36 | SCR-11, SCR-21; AD-14 |
| FR2-37 | SCR-16 |
| FR2-38 | SCR-08, SCR-25 |
| FR2-39 | SCR-45, SCR-23 |
| NFR2-01 | SCR-06, SCR-30, SCR-35, SCR-36; EM-16 |
| NFR2-02 | SCR-04, SCR-30; EM-03 |
| NFR2-03 | All screens (sections 2 and 3.4) |
| NFR2-04 | All screens (sections 2, 3.1 and 3.4) |
| NFR2-05 | All screens: pages kept light, with compressed images, no autoplay video and no heavy animation |
| NFR2-06 | SCR-32 |
| NFR2-07 | Section 5, safety banner and caution note |
| NFR2-08 | AD-01 to AD-14 |
| NFR2-09, NFR2-10 | No screen; behind-the-scenes work |
| UI2-01 | Section 3 |
| UI2-02 | SCR-09 |
| UI2-03 | SCR-01, SCR-40 |
| UI2-04 | Section 11 |
