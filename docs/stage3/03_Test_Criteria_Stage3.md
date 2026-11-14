# Stage 3 test criteria (extract)

Extracted on 14 November 2026 from `01 Requirements/Lifeline_Daily_Requirements_All_Stages.md` v0.4 by the lead. Every `TC-FR3-nn-AS-nn` and `TC-NFR3-nn-AS-nn` used under `tests/stage3/` must appear here (`stage3-guards`). Do not edit by hand: re-extract from the requirements when they change.

#### FR3-01 Sign in with Google

Stage 3 · Sign-in and account · Must · Status: To be built

Members can create an account and sign in with their Google account. Only their name, email and Google's account ID are taken from Google. The Stage 2 agreements and health consent are still asked for before the member continues.

| Test ID | What the test checks |
|---|---|
| TC-FR3-01-AS-01 | Sign up with a test Google account: an account is created with the Google name and email, and the Agreements step is shown next. |
| TC-FR3-01-AS-02 | Sign out, then sign in with the same Google account: the member returns to the same account. |
| TC-FR3-01-AS-03 | No Google data other than name, email and Google's account ID is stored. |

#### FR3-03 Email code at sign-up

Stage 3 · Sign-in and account · Must · Status: To be built

When a member signs up with their email, a 6-digit code is emailed to confirm it. Typing the code confirms the email and signs them in straight away, with no second sign-in. This replaces the confirmation link and covers account review item U6.

| Test ID | What the test checks |
|---|---|
| TC-FR3-03-AS-01 | Sign up with a test email: a 6-digit code is caught by the staging mail catcher. |
| TC-FR3-03-AS-02 | Typing the correct code signs the member in and shows the Agreements step. |
| TC-FR3-03-AS-03 | A wrong code is refused; after 5 wrong tries a new code must be requested. |
| TC-FR3-03-AS-04 | A code older than 10 minutes, or already used, is refused. |

#### FR3-04 Sign in with an email code

Stage 3 · Sign-in and account · Must · Status: To be built

Members can sign in by asking for a 6-digit code by email, as well as with their password. Codes last 10 minutes, work once and allow only a few tries.

| Test ID | What the test checks |
|---|---|
| TC-FR3-04-AS-01 | Ask for a sign-in code for a test account: the code arrives and signs the member in. |
| TC-FR3-04-AS-02 | The same code does not work a second time. |
| TC-FR3-04-AS-03 | More than 5 code requests in an hour for one email are refused with a clear message. |
| TC-FR3-04-AS-04 | Password sign-in still works for the same account. |

#### FR3-05 One account per email

Stage 3 · Sign-in and account · Must · Status: To be built

A Google sign-in (and an Apple sign-in, once FR4-30 is built) is matched to an existing account only when the provider confirms the email is verified; otherwise the member must sign in the usual way first and link it from the Account hub. The account is recognised by the provider's account ID, so a later change of email at the provider keeps the same account. A member cannot remove their last way to sign in. A member with no password confirms account deletion or other sensitive changes with an email code. Signing out everywhere ends all sessions, on the website and the app.

| Test ID | What the test checks |
|---|---|
| TC-FR3-05-AS-01 | Create a password account, then sign in with Google using the same verified email: the same account opens and no new account exists. |
| TC-FR3-05-AS-02 | Sign in with a provider account whose email is not verified: no automatic link is made and the member is asked to sign in first. |
| TC-FR3-05-AS-03 | Change the email on the test Google account, then sign in again: the same Lifeline Daily account opens. |
| TC-FR3-05-AS-04 | Try to unlink the only remaining sign-in method: it is refused with a clear message. |
| TC-FR3-05-AS-05 | A member with no password deletes their account after confirming with an email code. |
| TC-FR3-05-AS-06 | 'Sign out everywhere' ends every open session on the website and the app. |

#### FR3-06 Account hub

Stage 3 · Sign-in and account · Must · Status: To be built

The single long Settings form is replaced by an account hub with separate sections: Profile, Sign-in and security, Notifications, Privacy and consents, and Membership. Each section has its own Save button. (Account review item U3.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-06-AS-01 | Open Account: the five sections are listed and each opens its own page. |
| TC-FR3-06-AS-02 | Change the display name in Profile and press Save: only Profile is saved and a confirmation is shown. |
| TC-FR3-06-AS-03 | Privacy and consents lists every consent the member has given, with the date, and each can be withdrawn. |
| TC-FR3-06-AS-04 | Open the old long settings address (?all=1) as a member: the matching section of Account opens instead. |
| TC-FR3-06-AS-05 | Switch to imperial units in Profile: the health profile, the check-in and the logging pages show feet, inches and pounds, and none of them has its own units switch. |

#### FR3-07 Account menu on desktop

Stage 3 · Sign-in and account · Could · Status: To be built

On a desktop screen, clicking the member's name opens a small menu with Profile, My subscription, My data, Help and Sign out. (U5.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-07-AS-01 | At 1280 pixels wide, click the name chip: a menu with the five links opens. |
| TC-FR3-07-AS-02 | Each link goes to the right page, and Sign out signs the member out. |
| TC-FR3-07-AS-03 | The menu can be opened and used with the keyboard alone. |
| TC-FR3-07-AS-04 | On every member page and every My account → Admin page, the name chip opens the same menu, and Sign out there signs the member out. |
| TC-FR3-07-AS-05 | At 360 pixels wide, the menu in the header holds Sign out. |
| TC-FR3-07-AS-06 | Sign-in and security offers signing out of this device only, next to signing out everywhere; the first ends only the current browser's session. |

#### FR3-08 Helpful empty screens

Stage 3 · Sign-in and account · Should · Status: To be built

Every list that can be empty (for example a new blood pressure log) shows a friendly picture and one useful first step, such as how to measure blood pressure correctly. (U9.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-08-AS-01 | A new test member opens each log and list: none shows a blank area; each shows a picture, a short message and one action button. |
| TC-FR3-08-AS-02 | The empty blood pressure log links to the guide on measuring blood pressure. |

#### FR3-09 Set a goal when joining

Stage 3 · Goal journey · Must · Status: To be built

Every new member who gives health consent sets one main goal when joining, and may add one extra goal. A member who declines health consent, or withdraws it later, is never blocked: they use the general wellbeing path (articles, calculators and tracks) with nothing health-related stored, and their subscription is unaffected. A goal is measurable, with a target and a date (for example 'lose 6 kg by March' or 'walk 150 minutes a week'). It takes under a minute, and a 'Help me choose' option suggests a goal. Goals must be safe: no weight goal below a healthy BMI, no loss faster than about 1 kg a week, and blood pressure or glucose targets come from the member's doctor or a guideline default with 'check with your doctor'. Free members can set a goal.

| Test ID | What the test checks |
|---|---|
| TC-FR3-09-AS-01 | A new test member with health consent cannot reach the dashboard without setting a main goal, and can finish in under 1 minute. |
| TC-FR3-09-AS-02 | A new test member who declines health consent reaches the dashboard on the general wellbeing path, and no goal or health data is stored. |
| TC-FR3-09-AS-03 | A Premium test member who withdraws health consent keeps Premium access to non-personal features and is billed as before. |
| TC-FR3-09-AS-04 | 'Help me choose' suggests a goal based on the member's answers. |
| TC-FR3-09-AS-05 | A weight goal below a healthy BMI, or faster than the safe rate, is refused with a gentle message and a safe suggestion. |
| TC-FR3-09-AS-06 | A member can add one extra goal but not a third. |
| TC-FR3-09-AS-07 | A member who answers a pregnancy, eating-disorder or medicine safety question sees the safety wording before setting a goal. |
| TC-FR3-09-AS-08 | A member who has not answered the health questions is taken to them while setting the goal, and the goal is saved once they are answered. |

#### FR3-10 Starting measurements

Stage 3 · Goal journey · Must · Status: To be built

When a goal is set, the member records the starting measurements that matter for it, such as weight, waist, blood pressure, activity or sleep. These are the baseline for showing progress.

| Test ID | What the test checks |
|---|---|
| TC-FR3-10-AS-01 | Set a weight-loss goal: the member is asked for starting weight and waist, and these are saved as the baseline. |
| TC-FR3-10-AS-02 | Set a blood pressure goal: the member is asked for a starting reading. |
| TC-FR3-10-AS-03 | A blood pressure goal holds both a top and a bottom target (the doctor's, or the defaults 135 and 85), and is reached only when the 7-day averages of both numbers are at or below their targets (owner decision, 3 Nov 2026). |
| TC-FR3-10-AS-04 | The member can skip a measurement and add it later; progress is shown once it exists. |

#### FR3-11 Personal plan from the goal

Stage 3 · Goal journey · Must · Status: To be built

From the goal, Lifeline Daily builds a personal plan: the best track to start with, daily food and activity targets, meal and workout plans, and what to log and when. It replaces the separate welcome step (U7). Premium members get the full guided plan; Free members see the suggested track and their goal progress.

| Test ID | What the test checks |
|---|---|
| TC-FR3-11-AS-01 | A Premium test member with the goal 'lose 6 kg in 16 weeks' gets a plan with the Healthy Weight track, a calorie target from the calorie calculator, a meal plan and a weekly weigh-in. |
| TC-FR3-11-AS-02 | A Premium test member with a blood pressure goal is offered the Heart Health track and a blood pressure logging schedule. |
| TC-FR3-11-AS-03 | A Free test member sees the suggested track and their goal progress, with an invitation to upgrade for the full plan. |

#### FR3-12 Coaching Today screen

Stage 3 · Goal journey · Must · Status: To be built

The Today screen opens on one clear next step towards the member's goal, with Done and Skip buttons, a weekly ring (for example 3 of 7 days) and quick-log tiles for water, meals and mood. The next step is chosen by the decision engine (FR3-17) from the goal, the plan and recent logs. A missed day lowers the weekly count but never wipes out progress. (U2.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-12-AS-01 | Sign in as a test member on plan day 3: the Today screen shows one Next step card, with Done and Skip. |
| TC-FR3-12-AS-02 | Press Done: the card changes to the next activity and the weekly ring goes up by one day. |
| TC-FR3-12-AS-03 | For a test member with a blood pressure goal and no reading in 7 days, the Next step card suggests a blood pressure check. |
| TC-FR3-12-AS-04 | Leave one day without activity: the weekly ring shows the lower count and long-term progress is unchanged. |

#### FR3-13 Progress towards the goal

Stage 3 · Goal journey · Must · Status: To be built

The member sees how far they are towards their goal (for example '3.2 of 6 kg, 53%'), the trend, and whether they are on track for the target date. Free and Premium members both see this.

| Test ID | What the test checks |
|---|---|
| TC-FR3-13-AS-01 | A test member who started at 90 kg with a goal of 84 kg and now weighs 86.8 kg sees '3.2 of 6 kg' and 53%. |
| TC-FR3-13-AS-02 | A member whose trend will reach the goal by the date sees 'On track'; one whose trend will not sees 'Behind'. |
| TC-FR3-13-AS-03 | Free and Premium test members both see goal progress. |

#### FR3-14 Weekly check-in that adjusts the plan

Stage 3 · Goal journey · Must · Status: To be built

Each week the member checks in. If they are behind, the member is offered an adjusted plan (for example a more realistic date, an easier activity target or a different meal plan) instead of giving up; the plan changes only when the member accepts it. Adjustments are Premium and always stay within safe limits.

| Test ID | What the test checks |
|---|---|
| TC-FR3-14-AS-01 | A Premium test member behind on a weight goal for 2 weeks is offered an adjusted plan with a later date or changed targets. |
| TC-FR3-14-AS-02 | An adjusted plan never sets a calorie target below the safe minimum or a weight loss faster than the safe rate. |
| TC-FR3-14-AS-03 | A Free test member sees the check-in result with an invitation to upgrade for adjustments. |

#### FR3-15 Goal reached, then the next goal

Stage 3 · Goal journey · Must · Status: To be built

When a member reaches their goal, Lifeline Daily celebrates it and offers to keep the result (for example the Keep It Off track) or to set a new goal.

| Test ID | What the test checks |
|---|---|
| TC-FR3-15-AS-01 | A test member who reaches their goal weight sees a 'Goal reached' screen. |
| TC-FR3-15-AS-02 | The screen offers 'Keep it off' and 'Set a new goal', and each starts the right next step. |

#### FR3-16 Progress page with health trends

Stage 3 · Goal journey · Must · Status: To be built

The progress page shows streaks, weekly bars and badge pictures, with a 'Show as table' option kept for accessibility. It also shows health trends (weight, waist and blood pressure) next to activity. Badges reward healthy habits, never the amount of weight lost. (U8.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-16-AS-01 | A test member with 14 days of logs sees weekly bars for the last 4 weeks and their current streak. |
| TC-FR3-16-AS-02 | 'Show as table' shows the same numbers as the chart. |
| TC-FR3-16-AS-03 | The weight, waist and blood pressure trends match the member's logged values. |
| TC-FR3-16-AS-04 | No badge name or text mentions kilograms or pounds lost. |

#### FR3-17 Personal insights from the member's data (decision engine)

Stage 3 · Goal journey · Must · Status: To be built

A rule-based decision engine, without AI, runs each night for each member. It works out averages, trends and comparisons from their logs (for example blood pressure after short nights compared with longer nights), checks them against a library of insight rules, and shows at most 1 to 3 insights a week, each in owner-approved wording. The same engine chooses Today's next step and decides when the plan needs adjusting. Insights are Premium.

| Test ID | What the test checks |
|---|---|
| TC-FR3-17-AS-01 | For a test member with 14 nights of sleep paired with blood pressure readings that are 6 points higher after short nights, the sleep and blood pressure insight is shown. |
| TC-FR3-17-AS-02 | For a test member with only 10 paired nights, no such insight is shown. |
| TC-FR3-17-AS-03 | No member sees more than 3 insights in a week, and the same insight is not repeated within 4 weeks. |
| TC-FR3-17-AS-04 | Every insight shown matches an approved template exactly, with the member's own numbers filled in. |

#### FR3-18 Member dashboard

Stage 3 · Member dashboard · Must · Status: To be built

A signed-in member has one dashboard page that brings together their goal progress, today's logs, their plan and their health trends. It is the main page members return to, and the same information is later shown in the app.

| Test ID | What the test checks |
|---|---|
| TC-FR3-18-AS-01 | A test member with food, weight and blood pressure logs sees goal progress, today's totals and the latest readings on the dashboard. |
| TC-FR3-18-AS-02 | The dashboard shows the current track and week, matching the programme page. |
| TC-FR3-18-AS-03 | A Free test member sees the last 14 days only, with an invitation to upgrade for full history and insights. |

#### FR3-19 Heart Health track

Stage 3 · Tracks and content · Must · Status: To be built

A new four-week track on lowering blood pressure and cholesterol, using blood pressure logging and the heart risk check. Members see it only after the owner approves each item.

| Test ID | What the test checks |
|---|---|
| TC-FR3-19-AS-01 | The track has four weeks of owner-approved content. |
| TC-FR3-19-AS-02 | A member with a blood pressure goal is offered this track. |
| TC-FR3-19-AS-03 | No unapproved item is visible to members. |

#### FR3-20 Keep It Off track

Stage 3 · Tracks and content · Must · Status: To be built

A new track for members who have reached a weight goal, focused on keeping the weight off. The member keeps a daily calorie target as a guide, set at the level that keeps their weight steady (the signed P3-55 'maintain' setting, no deficit); logging stays optional but encouraged, and the track's wording is updated so nothing contradicts it (owner, 10 Nov 2026). Members with an eating-disorder flag never see a calorie number.

| Test ID | What the test checks |
|---|---|
| TC-FR3-20-AS-01 | The track has owner-approved content for every week. |
| TC-FR3-20-AS-02 | A member who reaches a weight-loss goal is offered this track. |
| TC-FR3-20-AS-03 | A test member who reaches their goal and starts Keep It Off gets a target equal to their daily use rounded to 10 (no deficit), shown as a guide, and no approved Keep It Off item says calorie numbers never appear. |

#### FR3-21 Healthy Weight, level 2

Stage 3 · Tracks and content · Should · Status: To be built

A second level of the Healthy Weight track for members who have finished level 1.

| Test ID | What the test checks |
|---|---|
| TC-FR3-21-AS-01 | A member who has finished Healthy Weight is offered level 2. |
| TC-FR3-21-AS-02 | Level 2 content is owner-approved and differs from level 1. |

#### FR3-22 Blood Sugar Balance, level 2

Stage 3 · Tracks and content · Should · Status: To be built

A second level of the Blood Sugar Balance track for members who have finished level 1.

| Test ID | What the test checks |
|---|---|
| TC-FR3-22-AS-01 | A member who has finished Blood Sugar Balance is offered level 2. |
| TC-FR3-22-AS-02 | Level 2 content is owner-approved and differs from level 1. |

#### FR3-23 A monthly challenge for every month

Stage 3 · Tracks and content · Must · Status: To be built

There is an owner-approved monthly challenge for each of the twelve months, not only December. The Stage 2 challenge feature (FR2-33) is reused.

_Related decision: I-03._

| Test ID | What the test checks |
|---|---|
| TC-FR3-23-AS-01 | For each month of the year, the challenge page shows an approved challenge when the date is set to that month. |
| TC-FR3-23-AS-02 | No challenge is shown to members unless its exact version is approved. |

#### FR3-24 Direct answers and sources in articles

Stage 3 · Tracks and content · Must · Status: To be built

Each health article starts with a short direct answer to its main question and lists its sources, so search engines and AI assistants can quote it correctly. This finishes the work started in Stage 2 (FR2-21).

_Related decision: I-03._

| Test ID | What the test checks |
|---|---|
| TC-FR3-24-AS-01 | Every published article has a direct-answer block in its first section. |
| TC-FR3-24-AS-02 | Every published article has a Sources section with at least one working link. |
| TC-FR3-24-AS-03 | Articles with a question-and-answer section carry valid FAQ markup. |

#### FR3-25 Search Console and Bing issues fixed

Stage 3 · Tracks and content · Must · Status: To be built

Fix the issues reported by Google Search Console and Bing Webmaster Tools, such as articles that are crawled but not indexed and old addresses that return 'not found'. This finishes the work started in Stage 2 (FR2-22).

_Related decision: I-03._

| Test ID | What the test checks |
|---|---|
| TC-FR3-25-AS-01 | Every old address in the 404 list redirects permanently to a live page. |
| TC-FR3-25-AS-02 | The sitemap lists only pages that return success and are allowed to be indexed. |
| TC-FR3-25-AS-03 | Every article in the 'crawled but not indexed' list has been improved, merged or removed, as recorded in the issue report. |

#### FR3-26 Food search

Stage 3 · Food tracker · Must · Status: To be built

Members can search a food database by name and pick a food. Results show the calories and main nutrients for a standard portion.

_Related decision: I-07._

| Test ID | What the test checks |
|---|---|
| TC-FR3-26-AS-01 | Search 'banana': results appear within 1 second and the first results are bananas, with calories per portion. |
| TC-FR3-26-AS-02 | A search with no match shows a clear message and a button to add a custom food. |
| TC-FR3-26-AS-03 | Search works with British and American spellings (for example yoghurt and yogurt). |

#### FR3-27 Food logging

Stage 3 · Food tracker · Must · Status: To be built

Members can add a food to breakfast, lunch, dinner or snacks on any day, choose the portion size, and edit or delete the entry later. They can view each day by meal. This replaces the Stage 2 simple food diary (FR2-37) and is free. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-27-AS-01 | Add 150 g of cooked rice to lunch: it appears under lunch with the right calories for 150 g. |
| TC-FR3-27-AS-02 | Edit the portion to 200 g: the entry and the day's totals update. |
| TC-FR3-27-AS-03 | Delete the entry: it disappears and the totals go down. |
| TC-FR3-27-AS-04 | Choose a past day: that day's entries are shown by meal. |

#### FR3-28 Daily calorie and nutrient target

Stage 3 · Food tracker · Must · Status: To be built

Each member has a daily target for calories, protein, carbohydrate, fat and fibre, set from the Daily calorie target calculator (FR3-47) and the Daily Protein Target. Premium members can set their own targets within safe limits.

| Test ID | What the test checks |
|---|---|
| TC-FR3-28-AS-01 | A test member who completes the calorie calculator gets a daily target equal to the calculator's result. |
| TC-FR3-28-AS-02 | Trying to set a calorie target below the safe minimum shows a warning and is not saved. |
| TC-FR3-28-AS-03 | The protein target matches the member's Daily Protein Target result. |

#### FR3-29 Daily totals against the target

Stage 3 · Food tracker · Must · Status: To be built

The food diary shows the day's totals for calories, protein, carbohydrate, fat and fibre, and how much is left against the target.

| Test ID | What the test checks |
|---|---|
| TC-FR3-29-AS-01 | Log three foods with known values: the day's totals equal the sum of the three. |
| TC-FR3-29-AS-02 | The amount left equals the target minus the total. |
| TC-FR3-29-AS-03 | Going over the target shows the amount over, without alarming wording. |

#### FR3-30 Custom foods

Stage 3 · Food tracker · Must · Status: To be built

Premium members can create their own foods with a name, portion and nutrition values, and edit or delete them. Custom foods are private to the member.

| Test ID | What the test checks |
|---|---|
| TC-FR3-30-AS-01 | Create a custom food: it appears in the member's own search results. |
| TC-FR3-30-AS-02 | Another test member cannot find it. |
| TC-FR3-30-AS-03 | Deleting the custom food keeps past diary entries that used it. |

#### FR3-31 Favourite and recent foods

Stage 3 · Food tracker · Must · Status: To be built

Members can mark foods as favourites and see their recently logged foods, so they can log common foods with one tap.

| Test ID | What the test checks |
|---|---|
| TC-FR3-31-AS-01 | Log a food: it appears at the top of Recent. |
| TC-FR3-31-AS-02 | Mark a food as a favourite: it appears in Favourites and can be logged with one tap. |

#### FR3-32 Copy a meal or a day

Stage 3 · Food tracker · Should · Status: To be built

Premium members can copy a whole meal or a whole day to another day.

| Test ID | What the test checks |
|---|---|
| TC-FR3-32-AS-01 | Copy yesterday's breakfast to today: the same entries appear under today's breakfast. |
| TC-FR3-32-AS-02 | Copy a whole day: every meal is copied and the totals match the original day. |

#### FR3-33 Stage 2 food diary carried over

Stage 3 · Food tracker · Must · Status: To be built

Entries made in the simple Stage 2 food diary are not converted into numbers. They stay as they are (description, photo, portion size, quick checks, hunger and energy) and appear in the new food tracker on their date and meal as 'Earlier diary entry, nutrition unknown'. They are read-only, count towards no totals, and are included in 'Download my data'.

| Test ID | What the test checks |
|---|---|
| TC-FR3-33-AS-01 | A test member's Stage 2 entry 'homemade curry, normal portion, with photo' appears on its date and meal as an earlier diary entry with its description, portion and photo. |
| TC-FR3-33-AS-02 | Earlier diary entries add nothing to the day's calorie or nutrient totals. |
| TC-FR3-33-AS-03 | Every field of every Stage 2 diary entry is unchanged after Stage 3 is switched on (field-by-field comparison, not only counts). |
| TC-FR3-33-AS-04 | Switching Stage 3 off and on again shows no duplicate entries. |

#### FR3-34 Weekly nutrition summary

Stage 3 · Food tracker · Should · Status: To be built

Each week, Premium members see their average daily calories and nutrients, the days they met their target, and one helpful tip.

| Test ID | What the test checks |
|---|---|
| TC-FR3-34-AS-01 | A test member with 7 logged days sees the correct average calories for the week. |
| TC-FR3-34-AS-02 | The number of days on target is correct. |
| TC-FR3-34-AS-03 | The tip comes from the owner-approved tip list. |

#### FR3-35 Recipe nutrition calculation

Stage 3 · Food tracker · Must · Status: To be built

Premium members can create a recipe from ingredients and a number of servings, and see the nutrition per serving. They can save, edit and delete recipes, and log a serving like any food.

| Test ID | What the test checks |
|---|---|
| TC-FR3-35-AS-01 | Create a recipe with 3 ingredients and 4 servings: nutrition per serving equals the ingredient totals divided by 4. |
| TC-FR3-35-AS-02 | Log one serving: it appears in the diary with the per-serving values. |
| TC-FR3-35-AS-03 | Edit an ingredient: the per-serving values update. |

#### FR3-36 Recipe suggestions from the approved library

Stage 3 · Food tracker · Must · Status: To be built

Lifeline Daily suggests recipes from the owner-approved recipe library that fit the member's goal, plan, calories left for the day, dislikes, allergies and food rules. Each approved recipe carries owner-approved food-rule tags worked out from its ingredients (for example contains poultry, beef, fish, shellfish, egg, dairy, meat and dairy together, onion or garlic, grains or pulses), and a recipe is suggested only when it meets every food rule the member chose: halal, kosher, no pork, no beef, pescatarian, vegetarian, vegan, no eggs, dairy-free, no onion or garlic, and 'no grains or pulses on fasting days' on the member's fasting days (owner, 10 Nov 2026). Halal and kosher members are shown 'Use halal (or kosher) meat and chicken in these recipes'. Buckwheat is in the allergen list. No recipe is written by AI. Premium.

| Test ID | What the test checks |
|---|---|
| TC-FR3-36-AS-01 | A test member with a nut allergy is never shown a recipe that contains nuts. |
| TC-FR3-36-AS-02 | Every suggested recipe fits within the member's calories left for the day. |
| TC-FR3-36-AS-03 | Every suggested recipe is in the approved library. |
| TC-FR3-36-AS-04 | A kosher test member is never shown a recipe tagged meat and dairy together, shellfish or pork, and sees the kosher meat line with the rest. |
| TC-FR3-36-AS-05 | On a test member's 'no grains or pulses' fasting day, only recipes without grains or pulses are suggested, such as the approved fasting-day recipes. |

#### FR3-37 Barcode scanning in the browser

Stage 3 · Food tracker · Should · Status: To be built

On a phone, Premium members can scan the barcode of a packaged food with the camera in the web browser and log it. If the product is not found, they can add it as a custom food.

_Related decision: I-05._

| Test ID | What the test checks |
|---|---|
| TC-FR3-37-AS-01 | Scan a test barcode that is in the database: the product and its nutrition are shown, ready to log. |
| TC-FR3-37-AS-02 | Scan a test barcode that is not in the database: the member is offered 'Add this product'. |
| TC-FR3-37-AS-03 | If the member refuses camera access, typed search still works and a short message explains why scanning is unavailable. |

#### FR3-38 Blood pressure logging

Stage 3 · Health logging · Must · Status: To be built

Members can record a blood pressure reading (top and bottom numbers, pulse, date and time), edit or delete it, view their readings and see a trend chart. Free members see the last 14 days; Premium members see their full history and long-term trends.

_Related decision: I-08._

| Test ID | What the test checks |
|---|---|
| TC-FR3-38-AS-01 | Record 128/82 with pulse 70: it appears in the list and on the 7-day chart. |
| TC-FR3-38-AS-02 | Edit and then delete the reading: the list and chart update each time. |
| TC-FR3-38-AS-03 | Choose a date range: only readings in that range are shown. |
| TC-FR3-38-AS-04 | A reading of 400/20 is refused as not possible, with a message to check the numbers. |

#### FR3-39 Weight logging

Stage 3 · Health logging · Must · Status: To be built

Members can record their weight in kilograms or pounds, edit or delete it, view their history and see a trend chart. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-39-AS-01 | Record 80 kg, then switch units to pounds: the reading shows as 176.4 lb. |
| TC-FR3-39-AS-02 | Edit and delete readings: the list and chart update. |
| TC-FR3-39-AS-03 | The chart shows the trend over the chosen period. |

#### FR3-40 Waist logging

Stage 3 · Health logging · Must · Status: To be built

Members can record their waist in centimetres or inches, edit or delete it, view their history and see a trend chart. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-40-AS-01 | Record 90 cm: it appears in the list and chart. |
| TC-FR3-40-AS-02 | Edit and delete readings: the list and chart update. |
| TC-FR3-40-AS-03 | The page links to the guide on how to measure the waist correctly. |

#### FR3-41 Activity logging

Stage 3 · Health logging · Must · Status: To be built

Members can record activity (type, minutes and effort, or steps), edit or delete it, view their history and see weekly totals against the recommended 150 minutes of moderate activity. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-41-AS-01 | Record a 30-minute brisk walk: it appears in today's list and adds 30 minutes to the weekly total. |
| TC-FR3-41-AS-02 | Edit and delete entries: totals update. |
| TC-FR3-41-AS-03 | The weekly chart shows minutes against the 150-minute guide line. |

#### FR3-42 Sleep logging

Stage 3 · Health logging · Must · Status: To be built

Members can record their sleep (bedtime, wake time and how rested they feel), edit or delete it, view their history and see a trend chart. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-42-AS-01 | Record bedtime 23:00 and wake time 07:00: the entry shows 8 hours. |
| TC-FR3-42-AS-02 | A night that crosses midnight is counted on the wake-up date. |
| TC-FR3-42-AS-03 | Edit and delete entries: the list and chart update. |

#### FR3-43 Water logging

Stage 3 · Health logging · Must · Status: To be built

Members can record glasses or millilitres of water, edit or delete entries and see their daily total. The Today screen's quick-log tile uses this. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-43-AS-01 | Tap the water tile twice: today's water total goes up by two glasses. |
| TC-FR3-43-AS-02 | Edit and delete entries: the total updates. |

#### FR3-44 Mood logging

Stage 3 · Health logging · Must · Status: To be built

Members can record their mood on a simple five-point scale with an optional note, edit or delete it, and see a trend. The Today screen's quick-log tile uses this. Free members see the last 14 days; Premium members see their full history and long-term trends.

| Test ID | What the test checks |
|---|---|
| TC-FR3-44-AS-01 | Record a mood of 2 out of 5: it appears in the list and trend. |
| TC-FR3-44-AS-02 | Edit and delete entries: the list and trend update. |
| TC-FR3-44-AS-03 | Several very low moods in a row show the owner-approved support message with help-line information. |

#### FR3-45 Blood glucose logging

Stage 3 · Health logging · Should · Status: To be built

Members can record a blood glucose reading in mmol/L or mg/dL, with the time and whether it was before or after a meal, edit or delete it, and see a trend chart. Only owner-approved warning levels are used; there is no further interpretation. Free members see the last 14 days; Premium members see their full history and long-term trends.

_Related decision: I-12._

| Test ID | What the test checks |
|---|---|
| TC-FR3-45-AS-01 | Record 5.6 mmol/L before breakfast, then switch units: it shows as 101 mg/dL. |
| TC-FR3-45-AS-02 | Edit and delete readings: the list and chart update. |
| TC-FR3-45-AS-03 | A reading below the owner-approved low level shows the urgent warning wording. |

#### FR3-46 Urgent-care warning for unsafe readings

Stage 3 · Health logging · Must · Status: To be built

When a member records a blood pressure or blood glucose reading at a dangerous level, the owner-approved global urgent-care wording is shown straight away, before anything else. This is free for everyone, always. The levels and wording are clinically signed off and frozen like the calculators.

_Related decision: I-08._

| Test ID | What the test checks |
|---|---|
| TC-FR3-46-AS-01 | Record a blood pressure of 185/125: the urgent-care wording is shown at once, for Free and Premium test members alike. |
| TC-FR3-46-AS-02 | Record a normal reading: no warning is shown. |
| TC-FR3-46-AS-03 | The wording shown matches the frozen approved text exactly. |

#### FR3-47 Daily calorie target calculator

Stage 3 · Calculators · Must · Status: To be built

Works out a member's daily calorie needs from age, sex, height, weight and activity, adjusted for their goal, with a safe minimum so no one is given a crash diet. It sets the food tracker target. Free to use.

_Related decision: I-09._

| Test ID | What the test checks |
|---|---|
| TC-FR3-47-AS-01 | Every result matches the approved reference fixtures for the chosen formula. |
| TC-FR3-47-AS-02 | No result is below the approved safe minimum. |
| TC-FR3-47-AS-03 | A member who is pregnant or under 18 is shown the safety wording instead of a weight-loss target. |

#### FR3-48 Goal weight timeline

Stage 3 · Calculators · Must · Status: To be built

Shows how long it will take to reach a goal weight at a safe rate (about 0.5 to 1 kg a week) and the weekly target. Used when setting a weight goal.

_Related decision: I-09._

| Test ID | What the test checks |
|---|---|
| TC-FR3-48-AS-01 | Every result matches the approved reference fixtures. |
| TC-FR3-48-AS-02 | A goal weight that gives a BMI below the healthy range is not accepted and shows a gentle message. |
| TC-FR3-48-AS-03 | The weekly rate never exceeds the approved safe maximum. |

#### FR3-49 Heart attack and stroke risk check

Stage 3 · Calculators · Must · Status: To be built

Estimates the 10-year risk of heart attack or stroke using the WHO 2019 risk charts, and shows which change would lower it most, for example blood pressure, smoking or weight.

_Related decision: I-10._

| Test ID | What the test checks |
|---|---|
| TC-FR3-49-AS-01 | Every result matches the approved reference fixtures. |
| TC-FR3-49-AS-02 | The result names the factor whose change lowers the risk most. |
| TC-FR3-49-AS-03 | Inputs outside the method's valid age range show a clear 'not suitable' message. |

#### FR3-50 Blood pressure reading check

Stage 3 · Calculators · Must · Status: To be built

Explains what a single reading or a 7-day average means, using the International Society of Hypertension 2020 categories, and shows the urgent-care wording for dangerous readings.

_Related decision: I-08._

| Test ID | What the test checks |
|---|---|
| TC-FR3-50-AS-01 | Each category boundary gives the approved category in the reference fixtures. |
| TC-FR3-50-AS-02 | A dangerous reading shows the urgent-care wording. |
| TC-FR3-50-AS-03 | The 7-day average uses the member's logged readings when they are signed in. |

#### FR3-51 Metabolic health check

Stage 3 · Calculators · Must · Status: To be built

Uses waist, blood pressure, blood glucose and blood fat results to check for metabolic syndrome (IDF criteria), explains each result, and suggests the lifestyle changes that help.

_Related decision: I-09._

| Test ID | What the test checks |
|---|---|
| TC-FR3-51-AS-01 | Every result matches the approved reference fixtures. |
| TC-FR3-51-AS-02 | Results accept mmol/L and mg/dL and give the same answer. |
| TC-FR3-51-AS-03 | Missing lab values give a partial result that says which values are missing. |

#### FR3-52 Fitness age

Stage 3 · Calculators · Should · Status: To be built

Estimates cardio fitness and 'fitness age' from resting heart rate, age, sex, waist and activity, and shows how it can improve.

_Related decision: I-09._

| Test ID | What the test checks |
|---|---|
| TC-FR3-52-AS-01 | Every result matches the approved reference fixtures. |
| TC-FR3-52-AS-02 | For a signed-in Premium member, the result shows the change since the last check. |

#### FR3-53 Heart-rate training zones

Stage 3 · Calculators · Should · Status: To be built

Gives a member's heart-rate zones for easy, moderate and hard exercise, used by the workout plans.

_Related decision: I-09._

| Test ID | What the test checks |
|---|---|
| TC-FR3-53-AS-01 | Every result matches the approved reference fixtures. |
| TC-FR3-53-AS-02 | A member who says they take heart-rate-lowering medicine is shown the 'talk to your doctor' wording instead of zones. |

#### FR3-54 Sleep and stress check

Stage 3 · Calculators · Should · Status: To be built

A short validated questionnaire, free for commercial use, that scores sleep and stress, shows whether they need attention, and points to the right part of the Sleep and Stress track.

_Related decision: I-11._

| Test ID | What the test checks |
|---|---|
| TC-FR3-54-AS-01 | Every score matches the questionnaire's official scoring for the reference answers. |
| TC-FR3-54-AS-02 | A high score that signals a risk shows the approved support wording and help-line information. |

#### FR3-55 Saved calculator results over time

Stage 3 · Calculators · Must · Status: To be built

All calculators are free to use. As in Stage 2, any signed-in member with health consent can save a calculator's headline result. Premium members also keep a history of results, re-check every three months and see how each result has changed.

| Test ID | What the test checks |
|---|---|
| TC-FR3-55-AS-01 | A Free test member with health consent can save the headline result, as in Stage 2. |
| TC-FR3-55-AS-02 | A Premium test member saves two heart risk results at different dates: both are shown with the change between them. |
| TC-FR3-55-AS-03 | A Free test member sees only the latest saved result, with an invitation to upgrade for history and comparisons. |

#### FR3-56 Reminders

Stage 3 · Engagement · Must · Status: To be built

Members can choose reminders to log, work out or weigh in, by email or browser notification, at a time they choose. Free members can have one daily reminder; Premium members can have as many as they like. Reminder emails never contain health details.

| Test ID | What the test checks |
|---|---|
| TC-FR3-56-AS-01 | Set a daily logging reminder for 08:00: a reminder is caught by the staging mail catcher at 08:00 in the member's time zone. |
| TC-FR3-56-AS-02 | The reminder text contains no readings, weights or health words from the member's data. |
| TC-FR3-56-AS-03 | A Free test member cannot add a second reminder; a Premium test member can. |
| TC-FR3-56-AS-04 | Turning a reminder off stops it the next day. |

#### FR3-57 Shareable progress summary

Stage 3 · Engagement · Should · Status: To be built

Members can create a summary picture or link of their progress (for example days active and challenges finished) to share. Health numbers are left out unless the member chooses to include them.

| Test ID | What the test checks |
|---|---|
| TC-FR3-57-AS-01 | Create a summary: by default it contains no weight, blood pressure or other health numbers. |
| TC-FR3-57-AS-02 | Tick 'include weight change': the summary then shows it. |
| TC-FR3-57-AS-03 | A shared link stops working after the member deletes it. |

#### FR3-58 Add to home screen (installable website)

Stage 3 · Engagement · Should · Status: To be built

Members can add the website to their phone's home screen, where it opens full-screen with its own icon, like an app. This is a bridge until the Stage 4 app.

_Related decision: I-17._

| Test ID | What the test checks |
|---|---|
| TC-FR3-58-AS-01 | The site passes the browser's 'installable' check (valid manifest, icon and service worker). |
| TC-FR3-58-AS-02 | Once installed, it opens on the member dashboard without the browser address bar. |
| TC-FR3-58-AS-03 | Pages that need a connection show a friendly 'you are offline' page when there is none. |

#### FR3-59 Consent for the new health data

Stage 3 · Member data and privacy · Must · Status: To be built

Before a member first logs health data such as blood pressure or glucose, they give clear consent to storing it. They can withdraw it at any time, and withdrawing deletes that data. Members without health consent use the general wellbeing path (FR3-09).

| Test ID | What the test checks |
|---|---|
| TC-FR3-59-AS-01 | A test member who has not consented is asked for consent before their first health log. |
| TC-FR3-59-AS-02 | Without consent, nothing is saved. |
| TC-FR3-59-AS-03 | Withdrawing consent deletes the member's health logs and records the withdrawal date. |

#### FR3-60 Food and health logs in 'Download my data'

Stage 3 · Member data and privacy · Must · Status: To be built

The Stage 2 'Download my data' file also includes all food and health logs, goals and saved results, whatever the member's plan.

| Test ID | What the test checks |
|---|---|
| TC-FR3-60-AS-01 | Download my data for a test member with logs: the file contains every food and health entry, including entries older than 14 days. |
| TC-FR3-60-AS-02 | The numbers in the file match those shown on screen. |

#### FR3-61 Food and health logs deleted with the account

Stage 3 · Member data and privacy · Must · Status: To be built

When a member deletes their account, all their food and health logs, goals, custom foods and recipes are deleted too, following the Stage 2 deletion rules.

| Test ID | What the test checks |
|---|---|
| TC-FR3-61-AS-01 | Delete a test account with logs: no food or health entries remain for that member in the database. |
| TC-FR3-61-AS-02 | Shared progress links made by that member stop working. |
| TC-FR3-61-AS-03 | After an account is deleted, no table holds the member's email, including the sign-in lock records. |

#### FR3-62 Plans and prices at launch

Stage 3 · Paid membership · Must · Status: To be built

Lifeline Daily launches after Stage 3. Premium costs US$9.99 a month or US$69.99 a year (about 42% cheaper, a saving of US$49.89). Prices are in US dollars and include any sales tax, so a member paying in US dollars pays exactly the price shown (carried forward from FR2-13). Payments run through Stripe Managed Payments, where Stripe's partner Onelink is the seller and handles sales tax worldwide (owner decision, 3 November 2026). On Stripe's page a member may choose to pay in their own currency, at a converted amount that includes a conversion fee, so the pricing page says 'Prices in US$; your bank or Stripe may convert'. Receipts, invoices and refund notes come from Onelink, and statements show 'Sold through Onelink'; the site's own confirmation email says the receipt comes from Onelink and is not called a receipt. The same price applies on the website and, later, in the app. Members who join keep their price if it rises later, including after a pause. This replaces the Stage 2 prices in FR2-13.

| Test ID | What the test checks |
|---|---|
| TC-FR3-62-AS-01 | The pricing page shows US$9.99 a month and US$69.99 a year, with the yearly saving, both marked 'tax included'. |
| TC-FR3-62-AS-02 | For test customers in the UK, Germany and the US paying in US dollars, the amount charged equals the amount shown. |
| TC-FR3-62-AS-03 | A Stripe test checkout for each plan charges the price shown. |
| TC-FR3-62-AS-04 | A test member on an older price keeps it when the price for new members is changed. |
| TC-FR3-62-AS-05 | The pricing page shows the line 'Prices in US$; your bank or Stripe may convert'. |
| TC-FR3-62-AS-06 | The confirmation email after a test checkout says the receipt comes from Onelink and does not use the words 'Your receipt'. |

#### FR3-63 Free and Premium access

Stage 3 · Paid membership · Must · Status: To be built

Everything is available on both the website and the app; only the plan decides access. Free: articles, all calculators with one-off results, all safety warnings, account, a main and extra goal with progress, week 1 of each track, basic food and health logging with the last 14 days of history and simple 14-day charts, saving a calculator's headline result (as in Stage 2), one daily reminder, shareable summary, and download or delete of all their data. Premium: everything, including the full guided plan and weekly adjustments, all track weeks and levels, full history, long-term trends and insights, saved calculator history and comparisons, food tracker extras (barcode, own targets, custom foods, copy meals, recipes, weekly summary), meal and workout plans, monthly challenges, unlimited reminders, and later the app's offline features, imports and AI helpers. Safety features are never paid.

_Related decision: I-06._

| Test ID | What the test checks |
|---|---|
| TC-FR3-63-AS-01 | A Free test member can log food and health data on any day. |
| TC-FR3-63-AS-02 | A Free test member sees only the last 14 days of history on screen, but 'Download my data' includes everything. |
| TC-FR3-63-AS-03 | A Free test member sees goal progress and 'On track' or 'Behind' (FR3-13) but no long-term trend charts. |
| TC-FR3-63-AS-04 | A Free test member trying a Premium feature sees a short explanation and an upgrade button, never an error. |
| TC-FR3-63-AS-05 | A Premium test member can use every Stage 3 feature. |
| TC-FR3-63-AS-06 | Urgent-care warnings appear for Free and Premium test members alike. |

#### FR3-64 Membership card

Stage 3 · Paid membership · Should · Status: To be built

My subscription shows a membership card: the plan and price, the renewal date, the yearly saving, what Premium includes, and what the member has done with it (for example workouts completed and days logged), with buttons to manage the plan. (U4.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-64-AS-01 | A yearly Premium test member's card shows the yearly plan, price, renewal date and saving. |
| TC-FR3-64-AS-02 | The card shows the member's own counts of completed workouts and logged days, matching the progress page. |
| TC-FR3-64-AS-03 | A Free test member sees an upgrade card instead. |
| TC-FR3-64-AS-04 | A member given free Premium by the Owner sees Premium on the name chip, in Account → Membership and on My subscription, with its end date, and no offer to buy Premium. |

#### FR3-65 Pause subscription

Stage 3 · Paid membership · Must · Status: To be built

Monthly Premium members can pause for 1, 2 or 3 months instead of cancelling. The pause starts at the end of the month already paid for, and the renewal date moves back by the length of the pause. While paused there is no charge and the member has Free access; their data is kept. Premium returns automatically at the end, at the member's original price. If the first payment after the pause fails, the Stage 2 failed-payment rules apply. Yearly members cannot pause. The cancellation page offers a pause first.

| Test ID | What the test checks |
|---|---|
| TC-FR3-65-AS-01 | A monthly test member renewing on 10 March pauses for 1 month: Premium continues to 10 March, there is no charge on 10 March, and the next charge is on 10 April. |
| TC-FR3-65-AS-02 | During the pause the member has Free access and none of their data is deleted. |
| TC-FR3-65-AS-03 | At the end of the pause, Premium returns and the charge is the member's original price. |
| TC-FR3-65-AS-04 | A failed payment at the end of the pause follows the Stage 2 failed-payment retries. |
| TC-FR3-65-AS-05 | A yearly test member is not offered a pause. |
| TC-FR3-65-AS-06 | Starting to cancel shows the pause option before the final cancel button. |
| TC-FR3-65-AS-07 | A member can pause once in any 12 months: within 12 months of their last pause they are offered no pause, a new pause is refused with the date they can pause again, and a pause they undid does not count (owner, 8 Nov 2026). |

#### FR3-66 Google Analytics 4

Stage 3 · Analytics · Must · Status: To be built

Google Analytics 4 measures visitors and conversions on public pages: articles, calculator pages (not their inputs or results), pricing, sign-up and checkout steps. It replaces Plausible for now; the Plausible code stays switched off for later (FR1-16, FR2-17).

| Test ID | What the test checks |
|---|---|
| TC-FR3-66-AS-01 | After cookies are accepted, opening the pricing page sends a page view to GA4. |
| TC-FR3-66-AS-02 | Starting checkout sends a 'begin_checkout' event with no personal or health details. |
| TC-FR3-66-AS-03 | The Plausible script is not loaded on any page. |
| TC-FR3-66-AS-04 | On a public calculator, moving from the input screen to the result (with or without a page reload) sends no page view or event containing inputs or results. |

#### FR3-67 Cookie consent banner

Stage 3 · Analytics · Must · Status: To be built

A cookie banner lets visitors accept or reject analytics cookies with equal ease, using Google Consent Mode. Nothing is sent to Google Analytics until the visitor accepts, and they can change their choice at any time from the footer.

| Test ID | What the test checks |
|---|---|
| TC-FR3-67-AS-01 | On a first visit, the banner shows Accept and Reject buttons of equal size. |
| TC-FR3-67-AS-02 | Before Accept, no request is sent to Google Analytics. |
| TC-FR3-67-AS-03 | After Reject, no Google Analytics cookie is set and no request is sent. |
| TC-FR3-67-AS-04 | The footer link reopens the banner and the choice can be changed. |

#### FR3-68 What happens on downgrade or pause

Stage 3 · Paid membership · Must · Status: To be built

When Premium ends, or during a pause, nothing is deleted. Premium-only items become read-only or paused: custom foods and recipes can be viewed and still appear in past diary days but cannot be added to new days; extra reminders are paused, keeping the first one; saved calculator history is kept but only the latest result is shown. Everything returns when the member upgrades again.

| Test ID | What the test checks |
|---|---|
| TC-FR3-68-AS-01 | Downgrade a Premium test member with custom foods, recipes and three reminders: nothing is deleted, the first reminder still runs, and the other two are paused. |
| TC-FR3-68-AS-02 | After the downgrade, past diary days still show their custom foods and recipes, but they cannot be added to a new day. |
| TC-FR3-68-AS-03 | Upgrade again: every custom food, recipe, reminder and saved result is available as before. |

#### FR3-69 Safety rules across the food tracker and plans

Stage 3 · Clinical safety · Must · Status: To be built

The Stage 2 safety matrix is extended to every Stage 3 feature. For a member with an eating-disorder flag, no calorie target, calorie number, remaining allowance, portion question, calorie-based recipe suggestion or weight-loss plan is shown anywhere, including through the app's programming interface; the food tracker becomes a simple meal log. For a member with kidney disease, no protein target is shown, as in the Stage 1 calculator. When a member changes their health profile, the rules apply at once, including to plans already created.

| Test ID | What the test checks |
|---|---|
| TC-FR3-69-AS-01 | For a test member with an eating-disorder flag, no calorie number, target, allowance or portion question appears on any tracker, plan, recipe or dashboard screen. |
| TC-FR3-69-AS-02 | For the same member, requests for calorie data through the programming interface are refused. |
| TC-FR3-69-AS-03 | For a test member with kidney disease, no protein target appears anywhere. |
| TC-FR3-69-AS-04 | Add the eating-disorder flag to a member who already has a weight-loss plan: the plan's calorie targets disappear at once and a safe plan is offered. |
| TC-FR3-69-AS-05 | A member whose conditions answer is missing who tries to log a reading or set a goal is told which question is missing, with a link to it, and nothing is stored. |
| TC-FR3-69-AS-06 | Health details in the health profile cannot be saved until the conditions question is answered; 'None of these' and 'Prefer not to answer' both count as answers. |

#### FR3-70 Weekly owner report

Stage 3 · Analytics · Must · Status: To be built

Removed by the owner on 4 November 2026: no weekly email is sent, and the owner reads these figures in Stripe, Google Analytics and the admin panel instead. The text below is kept for the record. The Monday report from Stage 2 (FR2-17) continues after the switch to Google Analytics. Sign-ups, upgrades, renewals, pauses, cancellations, revenue and member numbers come from Lifeline Daily's own database and Stripe; visits and calculator use come from Google Analytics totals. No personal or health data is included.

| Test ID | What the test checks |
|---|---|
| TC-FR3-70-AS-01 | On a Monday, a test report is caught by the mail catcher with sign-ups, upgrades, renewals, pauses, cancellations, revenue and member numbers matching the test data. |
| TC-FR3-70-AS-02 | The report contains no names, emails or health values. |

#### FR3-71 Owner and Support admin roles

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

The admin panel has two roles. The Owner can do everything. A Support helper can find members, see their account details, resend account emails, add private notes, and pause or resume a membership when the member asks. Support cannot delete a member, send a data download, give or remove Premium, end a membership, issue refunds or change payments, mark test accounts, block or unblock, change features, approve content, manage admins, or see other admins' actions and payment counts. Only the Owner adds or removes Support helpers, and each helper has their own account. Every action checks the role on the server, not only by hiding buttons. Both roles work in My account → Admin (FR3-87), and a Support helper never enters the WordPress admin (FR3-90). Section 3 lists every privilege.

| Test ID | What the test checks |
|---|---|
| TC-FR3-71-AS-01 | A Support test account can search members, open an account, resend a sign-in email, add a note, and pause and resume a membership. |
| TC-FR3-71-AS-02 | For each Owner-only action, a Support test account sees no button, and a direct request to the action is refused. |
| TC-FR3-71-AS-03 | Only an Owner account can add or remove a Support helper. |

#### FR3-72 Two admin areas: My account → Admin, and content in the WordPress admin

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

Running members, tools and money happens in My account → Admin (FR3-87): Overview, Members, Tools, Features, Activity log, Helpers, Payments, Weekly report and Group removals. Content and the site stay in the WordPress admin, where the menu is called 'Lifeline Daily content' and holds Goals, Tracks, Weeks, Lessons, Activities, Challenges, Recipes, Meal plans, Workout plans, Suggestion rules, Badges, Screening wording, Tags, Approvals, Safety matrix, Live Q&A, Testimonials and Preview. The moved pages leave the WordPress admin only after their My account versions pass every check; until then both work. The old WordPress-admin address of a moved page takes the Owner to the same page in My account.

| Test ID | What the test checks |
|---|---|
| TC-FR3-72-AS-01 | The WordPress admin's Lifeline Daily content menu lists exactly the content screens named above, and none of the moved pages. |
| TC-FR3-72-AS-02 | Each content screen still opens at its old address (for example admin.php?page=lld-approvals). |
| TC-FR3-72-AS-03 | The old WordPress-admin address of each moved page (for example admin.php?page=lld-payments) takes the Owner to the same page in My account → Admin. |
| TC-FR3-72-AS-04 | Every action on a moved page gives the same result in My account as it did in the WordPress admin (the FR3-71 to FR3-86 tests run against the My account pages). |

#### FR3-73 Overview page with counts only

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

The first admin page shows counts only: members in total, new this week and signed in within 7 and 30 days; Free, Premium, paused and cancelled members; monthly and yearly plans; open failed payments; content waiting for approval; unanswered Live Q&A questions; each feature and its state; and the last 10 admin actions. It never shows health figures, even as totals. Support sees the same page without the payment counts and the activity log. The page is in My account → Admin. Test members are counted apart from real members: the counts show real members only, with the number of test members shown separately.

| Test ID | What the test checks |
|---|---|
| TC-FR3-73-AS-01 | With known test data, every count on the Overview matches the test data. |
| TC-FR3-73-AS-02 | The Overview page contains none of the test members' health values, goals or track names. |
| TC-FR3-73-AS-03 | A Support test account sees no payment counts and no activity log on the Overview. |

#### FR3-74 Emergency off for a feature

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

Every Stage 3 feature is on (decision of 3 Nov 2026). The Features page in My account → Admin lists each feature by its plain name with a one-line description and one simple on/off toggle. Flipping the toggle saves at once and shows 'Saved' with an Undo link, and members stop seeing a feature that is off on their next page load. A reason box beside the toggle is optional. There is no 'test accounts only' choice on the screen (the command line keeps that state for the automated tests only, NFR3-06), and turning a feature back on needs no typed name, note or fresh code. Each feature shows its change history (who, when, from and to, reason), including changes made on the command line. The tools' switches are on the Tools page (FR3-88). The list of test-account emails is edited on this page. Full administrators (the Owner) count as test accounts automatically; Support helpers do not, unless their email is added to the list. Support can view the page but not change it.

| Test ID | What the test checks |
|---|---|
| TC-FR3-74-AS-01 | Turn a feature off: an ordinary test member no longer sees it on the next page load, and the change appears in its history. |
| TC-FR3-74-AS-02 | Flipping the toggle on or off, with or without a reason, saves at once, shows 'Saved' with Undo, and is recorded in the history and the activity log (with the reason when one was given). |
| TC-FR3-74-AS-03 | A change made with 'wp lld-members feature' on the command line appears in the feature's history. |
| TC-FR3-74-AS-04 | An email added to the test-account list on the page is treated as a test account. |
| TC-FR3-74-AS-05 | A Support test account cannot change any feature or the test-account list. |
| TC-FR3-74-AS-06 | The toggle offers only on and off; no 'test accounts only' choice appears on the page. |
| TC-FR3-74-AS-07 | Undo straight after a change puts the feature back to its earlier state and records that too. |

#### FR3-75 Find a member

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

Owner and Support can search members by email, name or member number, and filter by plan (Free, Premium), status (active, paused, cancelled, blocked) and test account.

| Test ID | What the test checks |
|---|---|
| TC-FR3-75-AS-01 | Searching by part of an email, a name and a member number each finds the matching test member. |
| TC-FR3-75-AS-02 | Each filter returns only the matching test members. |

#### FR3-76 Member account page without health data

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

A member's admin page shows name, email, sign-in methods (email, Google, and Apple from Stage 4), date joined, last sign-in, plan, status, renewal date, payment status from Stripe (never card details), whether it is a test account, Premium grants, AI allowance use (Stage 5) and private notes. It never shows the member's goal, track, health answers, health readings, food diary or calculator results, because even a track choice hints at a health condition. There is no way to sign in as the member.

| Test ID | What the test checks |
|---|---|
| TC-FR3-76-AS-01 | For a test member with health data, the page shows each listed account detail correctly. |
| TC-FR3-76-AS-02 | The page contains none of that member's health values, goal, track names, diary entries or calculator results. |
| TC-FR3-76-AS-03 | No admin page offers to sign in as, or view the site as, a member. |

#### FR3-77 Resend account emails

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

Owner and Support can resend a member's sign-in code, email confirmation or welcome email. The emails are the standard ones and contain no health details.

| Test ID | What the test checks |
|---|---|
| TC-FR3-77-AS-01 | Resend each email type for a test member: the email is caught by the mail catcher and the action appears in the activity log. |

#### FR3-78 Private notes on a member

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

Owner and Support can add private notes to a member, for example about a support request. Members never see them. The note box reminds the writer not to include health details. Notes are deleted with the account.

| Test ID | What the test checks |
|---|---|
| TC-FR3-78-AS-01 | Add a note: it shows on the member's admin page with who wrote it and when, and is never shown to the member. |
| TC-FR3-78-AS-02 | The note box shows the reminder 'No health details in notes'. |

#### FR3-79 Pause or resume a membership for a member

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

Owner and Support can pause or resume a Premium membership when the member asks, with the same rules as the member's own pause (FR3-65). Stripe is updated to match.

| Test ID | What the test checks |
|---|---|
| TC-FR3-79-AS-01 | Pause a monthly test member for 1 month: the pause follows the FR3-65 rules and Stripe's renewal date moves to match. |
| TC-FR3-79-AS-02 | Resume the member: Premium returns and Stripe matches. |

#### FR3-80 Give or remove free Premium

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

The Owner can give a member free Premium with an end date and a reason, kept separate from Stripe, and remove it again. When it ends, the member returns to Free with the downgrade rules (FR3-68).

| Test ID | What the test checks |
|---|---|
| TC-FR3-80-AS-01 | Give a Free test member Premium until a set date: they get Premium at once, and no Stripe charge or subscription is created. |
| TC-FR3-80-AS-02 | On the end date the member returns to Free, with nothing deleted. |
| TC-FR3-80-AS-03 | Giving or removing Premium is refused without a reason. |

#### FR3-81 End a membership

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

The Owner can end a member's Premium either at the end of the paid period or straight away, with a reason. The Stripe subscription is cancelled to match. Refunds stay in Stripe: the member's page has no refund button, only a link that opens this member's customer page in the Stripe dashboard (Owner only).

| Test ID | What the test checks |
|---|---|
| TC-FR3-81-AS-01 | End a test membership at the end of the period: Stripe shows it cancelling on that date and the member keeps Premium until then. |
| TC-FR3-81-AS-02 | End a test membership now: Premium stops at once and Stripe shows it cancelled. |
| TC-FR3-81-AS-03 | A paying test member's page shows the Stripe link to the Owner, no refund button to anyone, and no Stripe link to Support. |

#### FR3-82 Mark or unmark a test account

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

The Owner can mark a member as a test account, or unmark them, with a reason. This updates the same test-account list as the Features page.

| Test ID | What the test checks |
|---|---|
| TC-FR3-82-AS-01 | Mark a member as a test account: they are labelled 'Test', left out of member counts and revenue, and the email appears in the Features page list. |

#### FR3-83 Block or unblock a member

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

The Owner can block a member with a reason. A blocked member cannot sign in and is signed out at once, on the website and through the programming interface. While a paying member is blocked their billing is paused, using the same pause as FR3-65 but with no time limit and not counting towards the member's own pauses. Unblocking restores sign-in and restarts billing. The Owner can still end the membership separately (FR3-81).

| Test ID | What the test checks |
|---|---|
| TC-FR3-83-AS-01 | Block a signed-in test member: their next page load and programming-interface request are refused, and sign-in is refused. |
| TC-FR3-83-AS-02 | Block a paying test member: Stripe shows the subscription paused; unblock: Stripe shows it active again and the member's own pauses are unchanged. |
| TC-FR3-83-AS-03 | Unblock the member: they can sign in again with nothing else changed. |

#### FR3-84 Delete a member

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

The Owner can delete a member. It runs exactly the same erasure as the member's own deletion request (FR2-04, extended by FR3-61), after the Owner types the member's email, gives a reason and enters the two-step code again.

| Test ID | What the test checks |
|---|---|
| TC-FR3-84-AS-01 | Delete a test member from the panel: the erasure checker finds nothing left, the same as after the member's own request. |
| TC-FR3-84-AS-02 | Deleting is refused without the typed email, the reason and a fresh two-step code. |

#### FR3-85 Send the member their data download

Stage 3 · Admin panel · Must · Status: Built, on staging (#217)

The Owner can send a member a link to their own data download. The member signs in to download it. The admin never sees the file or its contents.

| Test ID | What the test checks |
|---|---|
| TC-FR3-85-AS-01 | Send the link: an email with the link is caught by the mail catcher, and the download needs the member to sign in. |
| TC-FR3-85-AS-02 | No admin page or request returns the member's download file. |

#### FR3-86 Admin activity log

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

The Stage 2 admin log is extended so that every admin action, from both roles and from the command line, is recorded: admin sign-ins and failed attempts, every member action, feature and test-account changes, and adding or removing helpers. Each entry has who, when, what, the member number, the before and after state and the reason. A reason is required for deleting, giving or removing Premium, ending a membership, blocking or unblocking, test-account changes, and adding or removing a helper. A reason for turning a feature or tool off or on is optional (FR3-74). Entries can never be edited or deleted, contain no health data, and do not copy note text. The Owner sees the whole log and can download it as a CSV file; Support sees only their own actions. Entries are kept for 2 years. When a member is deleted, their entries keep only the member number.

| Test ID | What the test checks |
|---|---|
| TC-FR3-86-AS-01 | Each action listed above creates one log entry with who, when, what, member number, before and after, and reason. |
| TC-FR3-86-AS-02 | Each risky action listed above is refused without a reason. |
| TC-FR3-86-AS-03 | No admin page, request or command can edit or delete a log entry. |
| TC-FR3-86-AS-04 | A Support test account sees only its own entries; the Owner's CSV download matches the log. |
| TC-FR3-86-AS-05 | After a test member is deleted, their log entries show the member number and no name or email. |
| TC-FR3-86-AS-06 | Entries older than 2 years are removed automatically. |

#### FR3-87 Admin pages in My account

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

The Owner and Support helpers reach their admin pages from My account → Admin, in the site's own design, at addresses under /members/admin/. Members never see the Admin link and get 'not found' at those addresses. The Owner's Admin section has a link, 'Content and site settings (WordPress)', that opens the WordPress admin without signing in again, and the WordPress admin has a link back. Sign-in, the email code, the 30-minute and 12-hour limits, reasons, confirmations and the activity log work as in FR3-71 to FR3-86 and NFR3-19 to NFR3-23.

| Test ID | What the test checks |
|---|---|
| TC-FR3-87-AS-01 | The Owner's My account shows an Admin link with Overview, Members, Tools, Features, Activity log, Helpers, Payments, Weekly report and Group removals. |
| TC-FR3-87-AS-02 | A Support test account's Admin section shows only Overview and Members. |
| TC-FR3-87-AS-03 | A Free and a Premium test member see no Admin link, and every /members/admin/ address answers 'not found' for them. |
| TC-FR3-87-AS-04 | From Admin, the Owner opens the WordPress admin without a second sign-in, and its link returns to Admin. A Support account has no such link. |

#### FR3-88 Tools page

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

One Tools page lists all 11 tools: the three standalone tools (Daily Protein Target, Diabetes Risk Check, Healthy Weight Check), the six calculator pages (Heart Risk Check, Blood Pressure Check, Metabolic Health Check, Fitness Age, Heart Rate Zones, Sleep and Stress Check) and the two members-only calculators (Calorie Target, Goal Timeline). For each it shows the name, its address, whether it is on or off, and when, by whom and why it last changed. Each tool has a simple on/off toggle with an optional reason, as in FR3-74, and a master toggle turns all eight newer calculators off at once. The page never shows results or any member's data.

| Test ID | What the test checks |
|---|---|
| TC-FR3-88-AS-01 | The page lists the 11 tools with their state, and the states on the page match the switches stored. |
| TC-FR3-88-AS-02 | Turning one tool off removes its hub card and its page for a test member on the next page load, and leaves every other tool working. |
| TC-FR3-88-AS-03 | Turning the master toggle off removes all eight newer calculators; the three standalone tools are unaffected. |
| TC-FR3-88-AS-04 | Each tool's toggle offers only on and off, and the label shows the tool's plain name. |
| TC-FR3-88-AS-05 | Every change is in the tool's history and the activity log, with the reason when one was given; a Support account sees the toggles but cannot change them. |

#### FR3-89 Switching off a standalone tool

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

Turning off one of the three standalone tools uses their approved Stage 1 switch (tools/status.json, FR1-15): its card on the homepage and the Health Tools hub says 'Temporarily unavailable', its landing page and its file address (for example /tools/protein-requirement-tool.html) show the approved paused text instead of the form, and its email-the-result step is refused. A tool can be turned back on from the page only while its approved file is still the one deployed. It does not change the tool's frozen calculation, wording or fixtures (FR2-23, NFR3-14), so CI's engine-freeze and oracle-guard checks still pass. Turning it back on restores everything as it was.

| Test ID | What the test checks |
|---|---|
| TC-FR3-89-AS-01 | With a standalone tool off, its card on the hub says 'Temporarily unavailable' and tools/status.json shows it withdrawn. |
| TC-FR3-89-AS-02 | With it off, the tool's own page shows the approved paused text and no form, and any email-the-result step is refused. |
| TC-FR3-89-AS-03 | Turning it back on restores the card, the page and the email step, and engine-freeze and oracle-guard pass unchanged. |

#### FR3-90 Support helpers kept out of the WordPress admin

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

A Support helper never enters the WordPress admin. Any WordPress-admin address sends them to My account → Admin, and the WordPress admin bar is not shown to them. The background addresses that forms use (admin-post.php and admin-ajax.php) still work for the actions their role allows, and refuse everything else.

| Test ID | What the test checks |
|---|---|
| TC-FR3-90-AS-01 | A Support test account opening /wp-admin/, /wp-admin/profile.php or /wp-admin/edit.php is sent to My account → Admin. |
| TC-FR3-90-AS-02 | A Support test account sees no WordPress admin bar on any page. |
| TC-FR3-90-AS-03 | A Support test account can still add a note and pause a membership from My account, and a direct request for an Owner-only action is refused. |

#### FR3-91 Premium test member for the Owner

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

The Owner can see and test the site exactly as a paying member does (every tool, guide, track week, plan and challenge) without paying. On the Members page the Owner can make any member account a Premium test member with one action and a reason: it is marked as a test account and given free Premium with no end date, with no Stripe charge or subscription. The account then sees everything a paying Premium member sees, under the same safety rules. A Premium test member is labelled 'Test' on every admin page, is left out of member counts, revenue, the weekly report and analytics, and never receives marketing emails. Up to 5 accounts can be Premium test members. The Owner signs in to it with its own email and password or code, as any member does; it is never the Owner's admin account and never a way to sign in as a real member (FR3-76). Removing the status returns the account to Free.

| Test ID | What the test checks |
|---|---|
| TC-FR3-91-AS-01 | After making a Free test account a Premium test member, it can open every Premium item listed in FR3-63 and every track week, and no Stripe charge or subscription exists. |
| TC-FR3-91-AS-02 | The Premium test member sees exactly the same screens, tools and content as a paying Premium test member. |
| TC-FR3-91-AS-03 | The Overview, the weekly report and the revenue figures do not count the Premium test member, and it is labelled 'Test' on the Members page. |
| TC-FR3-91-AS-04 | Safety rules still apply: with an eating-disorder flag in its health profile, the Premium test member sees no calorie numbers. |
| TC-FR3-91-AS-05 | Removing the status returns the account to Free with nothing deleted, and both changes are in the activity log with the reason. |
| TC-FR3-91-AS-06 | A 6th Premium test member is refused with a message saying the limit is 5. |

#### FR3-92 Reset a test member

Stage 3 · Admin panel · Should · Status: Built, on staging (#222, 6 Nov 2026)

So the Owner can walk through joining, goal setting and the first week again, the Owner can reset a test member to a clean start: goals, plans, logs, saved results and track progress are deleted, and the account, its sign-in and its Premium test status stay. Only accounts marked as test accounts can be reset; it needs a reason and the typed word RESET, and it is recorded in the activity log. The reset deletes the member's own data without anyone seeing it, so NFR3-19 still holds.

| Test ID | What the test checks |
|---|---|
| TC-FR3-92-AS-01 | Resetting a test member with goals and logs leaves the account and its Premium test status, and the member starts again at goal setting. |
| TC-FR3-92-AS-02 | The reset is refused for an account that is not a test account, and without the reason or the typed word. |

#### FR3-93 Staging emails for the Owner

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

Staging never sends real email; it catches every message (NFR1-14). On staging only, a Staging emails page in My account → Admin shows the Owner the caught emails, newest first, with the recipient, subject, time and text, so the Owner can read sign-in codes and see the emails members would get, without a developer. The page and its address exist only where the staging setting is on. Support cannot open it.

| Test ID | What the test checks |
|---|---|
| TC-FR3-93-AS-01 | On staging, after the Owner asks for a sign-in code, the newest message on the page holds that code. |
| TC-FR3-93-AS-02 | A Support account and a member get 'not found' at the page's address. |
| TC-FR3-93-AS-03 | With the staging setting absent, the page does not exist for anyone. |

#### FR3-94 A switch change shows at once

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

Turning a tool or feature off or on also clears the site's page cache for the pages that show it (the homepage, the Health Tools hub and the tool's own page), so signed-out visitors never see a card for a tool that is off.

| Test ID | What the test checks |
|---|---|
| TC-FR3-94-AS-01 | After a signed-out visit has cached the hub, turning a tool off removes its card from the next signed-out visit to the hub. |

#### FR3-95 Editors limited to articles and pages

Stage 3 · Admin panel · Must · Status: Built, on staging (#222, 6 Nov 2026)

Editors write and edit articles and pages only. They cannot open, add or change Lifeline Daily content (goals, tracks, weeks, lessons, activities, challenges, recipes, meal plans, workouts, rules, badges, wording), approvals or the safety matrix, even by typing the address; those are the Owner's. (These content types use WordPress's standard post permissions today, which Editors have.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-95-AS-01 | An Editor test account can edit an article and a page. |
| TC-FR3-95-AS-02 | An Editor test account opening edit.php?post_type=lld_recipe, or saving a change to a recipe, is refused. |

#### FR3-96 Retire Paid Memberships Pro

Stage 3 · Paid membership · Must · Status: Built, on staging (#223, 6 Nov 2026); deactivated on staging, files and tables kept

Lifeline Daily Members already runs Premium: prices, Stripe checkout, subscriptions, pause, cancellation, free Premium and blocking. Paid Memberships Pro is retired so there is one membership system with one set of rules. Lifeline Daily Members stops copying the two Premium plans into Paid Memberships Pro's levels list and stops relying on it in member deletion. Every page, shortcode, menu and sign-in step that came from it is checked and replaced or removed, including its 'Suspicious activity' sign-in block and its Memberships menu. Its records are backed up, then the plugin is deactivated and removed. The Stage 2 build documents and AGENTS.md, which say Premium runs 'through Stripe and Paid Memberships Pro', are updated.

| Test ID | What the test checks |
|---|---|
| TC-FR3-96-AS-01 | With Paid Memberships Pro deactivated, the full Stage 2 and Stage 3 test suites pass, including joining, checkout, renewal, pause, cancellation, free Premium and deletion. |
| TC-FR3-96-AS-02 | No page, menu or sign-in step on the site comes from Paid Memberships Pro, and no Lifeline Daily code refers to it. |
| TC-FR3-96-AS-03 | Free, Premium, paused and cancelled test members keep exactly the same access before and after it is removed. |
| TC-FR3-96-AS-04 | A backup of its records is taken before removal, and after removal no leftover record names a member. |

#### FR3-97 Where you are now, before the goal

Stage 3 · Healthy Weight programme · Must · Status: To be built

Before a weight goal is set, the member enters or confirms their weight, height and waist. Lifeline Daily works out BMI and waist-to-height ratio with the same calculations as the Healthy Weight Check (FR2-39) and explains in plain words what each result means, using the standard bands: BMI 18.5 to 24.9 healthy, 25 to 29.9 overweight and 30 or more obesity, with the lower cut-offs of 23 and 27.5 for people of South Asian, Chinese, other Asian, Middle Eastern, Black African or African-Caribbean background (owner, D3-54, 6 Nov 2026, signed method sheet: taken from the health profile when it names one of these; when it says 'White / not stated', 'Other / mixed' or nothing, the Healthy Weight Check's own background question is asked once, with its mixed-background follow-up, and saved as a programme answer without changing the profile; 'prefer not to say' uses the standard bands); waist-to-height ratio under 0.5 healthy, 0.5 to 0.59 increased risk and 0.6 or more high risk. It also shows the healthy weight range for the member's height, rounded inwards exactly as the Healthy Weight Check does, so every weight in it shows a healthy BMI. It reuses the Check's engine and approved result wording, including its rules for a BMI of 35 or more (no waist-to-height ratio) and a ratio under 0.40. Results are never shown as a diagnosis or in red and green. BMI bands are not shown during pregnancy or for anyone under 18. The member also confirms their activity level, choosing one of the four levels of the signed calorie method sheet (P3-55): low, 'Mostly sitting; little planned exercise'; some, 'Light activity or exercise 1 to 3 days a week'; active, 'Moderate exercise 3 to 5 days a week'; very active, 'Hard exercise 6 to 7 days a week'. It is filled in from the Stage 2 health profile when there, and a change is saved back to the profile. The member can skip waist and add it later. The bands and wording follow the method sheet `P3-35-Where-You-Are-Now-Method-Sheet.md`, signed by the owner on 6 Nov 2026 (NFR3-14), which reuses the Healthy Weight Check's approved wording.

| Test ID | What the test checks |
|---|---|
| TC-FR3-97-AS-01 | A test member of 163 cm and 80 kg with a 98 cm waist sees BMI 30.1 (obesity), waist-to-height ratio 0.60 (high risk) and a healthy range of 49.1 to 66.2 kg, before the goal step. |
| TC-FR3-97-AS-02 | The same member with a South Asian background in their health profile sees the 23 and 27.5 cut-offs, and a healthy range of 49.1 to 60.9 kg. |
| TC-FR3-97-AS-03 | Every BMI and waist-to-height result matches the Healthy Weight Check's engine for the same inputs on its frozen test cases. |
| TC-FR3-97-AS-04 | A member who says they are pregnant sees no BMI band and is shown the pregnancy safety wording. |
| TC-FR3-97-AS-05 | Skipping waist shows the BMI result alone, and the waist result appears once waist is added. |
| TC-FR3-97-AS-06 | A member whose health profile says 'Other / mixed' is asked the Check's background question once; answering Middle Eastern gives the 23 and 27.5 cut-offs, and the health profile answer is unchanged. |
| TC-FR3-97-AS-07 | A member whose health profile says 'some' activity sees that level already chosen, and can change it before going on. |
| TC-FR3-97-AS-08 | The four activity levels and their wording match the P3-55 method sheet exactly, and a changed level is saved to the health profile. |

#### FR3-98 Eight quick questions when joining

Stage 3 · Healthy Weight programme · Must · Status: To be built

After choosing a weight goal and before choosing a pace, a member with health consent answers eight quick questions, each one tap or a short choice: their reason or an event they are aiming for, their biggest barrier, how much time they have on a typical day, their work pattern (days, shifts, nights or irregular), how confident they feel (1 to 10), how long they usually sleep (under 6 hours, 6 to 7, 7 to 9, over 9), their stress level (1 to 10) and how often they have an alcoholic drink (never, less than once a week, 1 to 2 days a week, 3 to 4 days, most days). A member who drinks is asked one more tap: how many drinks on a day they drink (1 to 2, 3 to 4, 5 or more), shown as pictures of standard drinks rather than units, which differ between countries. A member whose food rules in the health profile or food preferences exclude alcohol is not asked, and 'never' is recorded. A member who works shifts, nights or irregular hours is also asked, optionally, when they usually wake up, so reminders fit their day (FR3-56). Sleep, stress and alcohol are asked at joining because they change the plan from day 1 (FR3-109, FR3-110). Each question shows a one-line reason for asking. Every question can be skipped, and the step takes about a minute. The answers shape the recommended pace (FR3-100), the order of activities (FR3-103), the first focus block (FR3-104), the sleep and stress steps (FR3-109) and the alcohol steps (FR3-110), each through a rule the owner signs off (NFR3-17). Answers are health data: they need health consent, are in 'Download my data', are deleted with the account or when consent is withdrawn, and never appear in emails, analytics or admin pages.

| Test ID | What the test checks |
|---|---|
| TC-FR3-98-AS-01 | A new test member with health consent sees the eight questions after choosing a weight goal, each with its reason, and can finish in about 1 minute. |
| TC-FR3-98-AS-02 | The sleep question offers exactly under 6 hours, 6 to 7, 7 to 9 and over 9, the stress question 1 to 10, and the alcohol question never, less than once a week, 1 to 2 days a week, 3 to 4 days and most days. |
| TC-FR3-98-AS-03 | Skipping every question still leads to the pace step, with the standard recommendation. |
| TC-FR3-98-AS-04 | A member who declines health consent is not asked, and nothing is stored. |
| TC-FR3-98-AS-05 | The answers are in 'Download my data' and are gone after the account is deleted. |
| TC-FR3-98-AS-06 | No email, analytics event or admin page contains an answer. |
| TC-FR3-98-AS-07 | Answering 'never' to alcohol skips the drinks-a-day tap; any other answer shows it, with pictures of standard drinks and the choices 1 to 2, 3 to 4 and 5 or more. |
| TC-FR3-98-AS-08 | A member whose food preferences say halal is not asked about alcohol, and 'never' is recorded. |
| TC-FR3-98-AS-09 | A member who chooses 'shifts' or 'nights' is offered the optional wake-up time question; a member on regular days is not, and their reminders start at 08:00. |

#### FR3-99 Getting-to-know-you questions

Stage 3 · Healthy Weight programme · Must · Status: To be built

When the member starts Healthy Weight, they are offered a short set of questions: eating pattern and meals a day, when they snack, sugary drinks, how often they eat out or have takeaways, cooking time and skill, food budget, food rules and allergies (for example halal, kosher, vegetarian, 'no grains or pulses on fasting days' or a fast they keep, chosen from the approved festival calendar), favourite cuisines, current activity and what they enjoy, stress eating, and what they have tried before (sleep, stress and alcohol are asked at joining, FR3-98). They can answer now or later; unanswered questions appear one or two a day on the Today screen. The member can change any answer from the Account hub. Each question has a written rule for what its answer changes, signed off by the owner (NFR3-17); a question without a rule is not asked. Answers already given in the health profile (FR2-25) or food preferences (FR3-36) are reused, not asked again. The privacy rules of FR3-98 apply.

| Test ID | What the test checks |
|---|---|
| TC-FR3-99-AS-01 | Starting Healthy Weight offers the question set, and choosing 'Later' starts the track with none answered. |
| TC-FR3-99-AS-02 | With questions unanswered, the Today screen shows at most 2 a day until all are answered or skipped. |
| TC-FR3-99-AS-03 | A question already answered in the health profile or food preferences is not asked again. |
| TC-FR3-99-AS-04 | Every question in the set has a signed rule in the rule catalogue, and CI fails if one does not. |
| TC-FR3-99-AS-05 | Changing an answer in the Account hub changes the plan from the next day. |
| TC-FR3-99-AS-06 | A member with an eating-disorder flag is not asked any portion or calorie question. |
| TC-FR3-99-AS-07 | During a dawn-to-sunset fast of several days that the member keeps, their meal plan switches to the approved fasting meal plan (pre-dawn meal, breaking the fast, evening meal) with the same calorie target, and switches back the day after it ends; a member with type 2 diabetes keeps their usual plan and sees 'If you are planning to fast, follow the plan you agreed with your doctor or diabetes team.' |

#### FR3-100 Recommended pace

Stage 3 · Healthy Weight programme · Must · Status: To be built

After the quick questions, Lifeline Daily recommends a weekly pace of weight loss from the member's answers and shows the date it gives. The rule the owner signed off (6 Nov 2026): 0.25 kg a week if confidence is 4 or less, time is under 10 minutes, the member works shifts, nights or irregular hours, or stress is 8 or more; 0.75 kg a week only if confidence is 8 or more, time is over 30 minutes, stress is 6 or less, sleep is 7 hours or more, the member is under 65 and 0.75 is within their safe limit; otherwise 0.5 kg a week. When both apply, the gentler pace wins. 1 kg a week is never recommended, though a member may choose it within their safe limit. Weeks to the goal are rounded up (P3-56). The member can choose another pace within the safe limits already built into goal setting (0.25 to 1 kg a week, with the lower limits for people over 65 and others), or pick a date instead. The recommended pace, the chosen pace and the rule that made the recommendation are saved. Wording such as 'faster results' is never used.

| Test ID | What the test checks |
|---|---|
| TC-FR3-100-AS-01 | A test member with 20 kg to lose, 10 minutes a day and confidence 3 out of 10 is recommended 0.25 kg a week, with the matching date. |
| TC-FR3-100-AS-02 | A test member with time, support and confidence 8 out of 10 can choose 0.75 kg a week, but never more than their safe limit. |
| TC-FR3-100-AS-03 | A test member of 80 kg with confidence 9, over 30 minutes, stress 4 and sleep 7 to 9 is recommended 0.75 kg a week; the same member with stress 8 is recommended 0.25, and with sleep 6 to 7 is recommended 0.5. |
| TC-FR3-100-AS-04 | A 20 kg loss at 0.75 kg a week from 6 November 2026 shows 27 weeks and 14 May 2027 (weeks rounded up). |
| TC-FR3-100-AS-05 | Choosing a pace above the member's safe limit is refused with a gentle message and the safe suggestion. |
| TC-FR3-100-AS-06 | The recommended pace, the chosen pace and the rule are saved with the goal. |

#### FR3-101 Weight milestones

Stage 3 · Healthy Weight programme · Must · Status: To be built

A weight goal is split into milestones: 5% of the starting weight, 10%, reaching the healthy range for the member's height, and the goal itself. Milestones already passed, or beyond the goal, are left out. The progress page shows them on the way to the goal. Reaching one shows an approved celebration, explains in a sentence what it means for health, and asks a few of the getting-to-know-you questions again in case life has changed; the plan is updated from the answers. Badges reward the milestone, never the amount of weight lost (FR3-16).

| Test ID | What the test checks |
|---|---|
| TC-FR3-101-AS-01 | A member of 163 cm starting at 80 kg with a 60 kg goal has milestones at 76 kg, 72 kg, 66.2 kg and 60 kg. |
| TC-FR3-101-AS-02 | A member whose goal weight is above the top of the healthy range has no healthy-range milestone. |
| TC-FR3-101-AS-03 | Reaching 76 kg shows the 5% celebration once, followed by the re-check questions. |
| TC-FR3-101-AS-04 | No badge or message names the amount of weight lost. |

#### FR3-102 Calorie counting in Healthy Weight

Stage 3 · Healthy Weight programme · Must · Status: To be built

At launch, members following Healthy Weight track their eating with the food tracker against their daily calorie target (FR3-28, FR3-47). The target uses the activity level the member confirmed (FR3-97) through the signed calorie method (P3-55), and the plan shows 'Your body uses about {tdee} kcal a day at your activity level.' Changing the activity level, in the Account hub or at a check-in, works the target out again at once. Weeks 1 and 2 of the track include a 'Counting made easy' guide: logging quickly, estimating home-cooked food, using favourites and copied meals, and what to do on days they cannot log. The Today screen offers one-tap logging of a meal they often repeat. The track's lessons still teach plates, swaps and habits, and their wording is updated so nothing contradicts counting. Members with an eating-disorder flag cannot start Healthy Weight and never see calorie numbers anywhere (FR3-69). The plate method, with an AI plate photo check, comes later (FR5-33); members will then choose between the two.

| Test ID | What the test checks |
|---|---|
| TC-FR3-102-AS-01 | A Premium test member who starts Healthy Weight with a weight goal has a calorie target and the food tracker on the Today screen. |
| TC-FR3-102-AS-02 | The 'Counting made easy' guide appears in weeks 1 and 2 and has an approval record. |
| TC-FR3-102-AS-03 | A test member with the P3-55 fixture CAL-03 details (female, 45, 160 cm, 80 kg, 0.5 kg a week) gets 1,390 kcal at 'some', 1,640 kcal at 'active' and the 1,200 kcal safe minimum at 'low', matching the ORC-06 oracle. |
| TC-FR3-102-AS-04 | The plan shows the 'Your body uses about {tdee} kcal a day at your activity level' line with the engine's energy figure for the chosen level. |
| TC-FR3-102-AS-05 | Changing the activity level from 'some' to 'active' in the Account hub changes the target on the Today screen and the food tracker at once. |
| TC-FR3-102-AS-06 | A meal logged yesterday can be logged again from the Today screen with one tap. |
| TC-FR3-102-AS-07 | No approved Healthy Weight lesson or activity says that calorie numbers never appear. |
| TC-FR3-102-AS-08 | A member with an eating-disorder flag sees no calorie number on any Healthy Weight or Today screen. |

#### FR3-103 Personalised Healthy Weight activities

Stage 3 · Healthy Weight programme · Must · Status: To be built

Every Healthy Weight activity carries habit tags (for example sugary drinks, snacking, evening eating, eating out, activity or sleep) and has an approved easier and stretch version as well as the standard one. From the member's answers and logs, the plan gives first the activities aimed at their biggest barrier, leaves out those for a habit the member already has (for example 'Swap one sugary drink' for someone who drinks none), and uses the easier version for members short of time or low in confidence and the stretch version for confident members who are doing well. Safety rules always apply first. Each week keeps its theme, and every day still has one activity.

| Test ID | What the test checks |
|---|---|
| TC-FR3-103-AS-01 | A test member who says they drink no sugary drinks is never given 'Swap one sugary drink'. |
| TC-FR3-103-AS-02 | A member whose biggest barrier is evening snacking gets the snacking activities first in week 3. |
| TC-FR3-103-AS-03 | A member with 10 minutes a day gets the easier versions; a member with confidence 8 who did over 80% last week gets a stretch version. |
| TC-FR3-103-AS-04 | Every activity in Healthy Weight levels 1 and 2 has at least one habit tag and approved easier and stretch versions. |
| TC-FR3-103-AS-05 | Each day of the track shows exactly one activity, and no item excluded by the safety rules appears. |

#### FR3-104 Focus blocks after week 8

Stage 3 · Healthy Weight programme · Must · Status: To be built

After Healthy Weight levels 1 and 2 (8 weeks), a member who has not reached their goal carries on with 4-week focus blocks, each built like a track (a weekly lesson, daily activities, a weekly task and daily notes). There are nine: evening and night eating; stress and emotional eating; eating out and social life; breaking a plateau; alcohol; sleep and weight; getting stronger; festivals and fasting (for example Ramadan, Diwali and Christmas, offered before the date); and shift work. The decision engine suggests the next block from the member's answers and logs, with a one-line reason, and the member can choose a different one. All nine are drafted together, and members see only owner-approved content. Blocks continue until the goal is reached; then Keep It Off is offered (FR3-20). Festivals and fasting is timed by a rule the owner signed (R-HW-BLOCK-03, 10 Nov 2026): its week 3 starts on the festival's first day from the approved festival calendar; the block the member is in carries on in whole weeks and pauses; for a fast longer than 7 days, week 3 repeats until it ends; week 4 starts the day after; the paused block then resumes. It can run again for a different festival, is not offered in weeks 1 to 8, and the plateau check does not run while it runs.

| Test ID | What the test checks |
|---|---|
| TC-FR3-104-AS-01 | A member finishing week 8 short of their goal is offered a focus block with a one-line reason, and can choose another. |
| TC-FR3-104-AS-02 | A member who said they snack after dinner is suggested 'Evening and night eating' first. |
| TC-FR3-104-AS-03 | 'Festivals and fasting' is offered before Ramadan only to members who said they keep that fast. |
| TC-FR3-104-AS-04 | Each of the nine blocks has 4 weeks of owner-approved content, and an unapproved item is never shown. |
| TC-FR3-104-AS-05 | A member who reaches their goal during a block is offered Keep It Off. |
| TC-FR3-104-AS-06 | For a test 30-day fast starting Monday 8 February 2027 and accepted on 1 January 2027, the block's week 3 starts on 8 February, its steps repeat until 9 March, and week 4 runs 10 to 16 March (fixture HWB-03a). |
| TC-FR3-104-AS-07 | No plateau message is shown while a test member is in Festivals and fasting. |

#### FR3-105 Daily mindset notes

Stage 3 · Healthy Weight programme · Must · Status: To be built

The Today screen shows a one-minute mindset note each day (60 to 100 words, with one 'Try this today' line) based on everyday psychology: noticing triggers, the all-or-nothing trap, kindness after a slip and planning ahead. Weeks 1 to 8 have 56 notes, with weekly themes: starting well; counting without stress; triggers and cravings; when motivation dips; habits that stick; planning and surroundings; confidence and your body; and stress, setbacks and the long run. Each focus block adds 28 of its own. Notes are in Lifeline Daily's own words and promise no weight loss, and any note about stress or mood carries the approved support wording. Members see only approved notes.

| Test ID | What the test checks |
|---|---|
| TC-FR3-105-AS-01 | On each of days 1 to 56 a Premium test member sees one note on the Today screen, with that week's theme. |
| TC-FR3-105-AS-02 | All 56 notes and each block's 28 notes have approval records, and an unapproved note is never shown. |
| TC-FR3-105-AS-03 | Every note about stress or mood shows the approved support wording. |
| TC-FR3-105-AS-04 | A note can be read in full on a 360-pixel-wide screen with no sideways scrolling. |

#### FR3-106 Notes chosen from the member's data

Stage 3 · Healthy Weight programme · Should · Status: To be built

The decision engine can bring a note forward when the member's data shows a need: no food logged for 3 days brings 'Missed a log? Just log the next meal'; weight up by 0.3 kg or more on last week brings 'Weight goes up and down'; a plateau (FR3-108) brings 'Plateaus are part of the process'; a low mood log brings 'Talk to yourself like a friend', with the approved support wording. Each rule is signed off by the owner (NFR3-17). A note brought forward replaces that day's note, which moves to the next day, and no note is shown twice within 30 days.

| Test ID | What the test checks |
|---|---|
| TC-FR3-106-AS-01 | A test member with no food logged for 3 days sees the missed-log note the next day. |
| TC-FR3-106-AS-02 | A member whose weigh-ins meet the plateau rule (FR3-108) sees the plateau note. |
| TC-FR3-106-AS-03 | A weigh-in 0.4 kg above last week's brings 'Weight goes up and down'; a rise of 0.2 kg does not. |
| TC-FR3-106-AS-04 | A low mood log brings the kindness note with the support wording, and never a weight-loss message. |
| TC-FR3-106-AS-05 | The note it replaced appears the following day, and no note repeats within 30 days. |

#### FR3-107 'What got in the way?' at the weekly check-in

Stage 3 · Healthy Weight programme · Must · Status: To be built

The weekly check-in adds one optional question, 'What got in the way this week?', with quick choices (time, work, family, cravings, stress, social events, illness, nothing) and a short free-text box. The answer feeds the next week's plan, the suggested focus block and the notes, through rules the owner signs off. Like the other answers, it is health data: it is never read by staff, sent in emails or used in analytics.

| Test ID | What the test checks |
|---|---|
| TC-FR3-107-AS-01 | The check-in shows the question, and skipping it still completes the check-in. |
| TC-FR3-107-AS-02 | Choosing 'cravings' two weeks running makes the next suggestion a block or activities about cravings. |
| TC-FR3-107-AS-03 | The answer is in 'Download my data' and in no email or analytics event. |

#### FR3-108 Plateau rule

Stage 3 · Healthy Weight programme · Must · Status: To be built

When a member with a weight goal has logged weight at least 4 times in 21 days, with no more than 0.5 kg between the highest and lowest reading (the signed rule R-ADJ-02), and has not reached the goal, Lifeline Daily treats it as a plateau. It explains plateaus kindly, offers the 'Breaking a plateau' block or its activities, and suggests that calorie-counting members check their logging is complete. It never lowers the calorie target below the safe minimum (FR3-14). Members with an eating-disorder flag get no plateau message.

| Test ID | What the test checks |
|---|---|
| TC-FR3-108-AS-01 | Weights of 80.1, 80.0, 80.2 and 80.1 kg within 21 days bring the plateau message and the block offer. |
| TC-FR3-108-AS-02 | Only 3 weigh-ins in 21 days, or a range of 0.6 kg, do not. |
| TC-FR3-108-AS-03 | A falling trend of 0.5 kg a week does not. |
| TC-FR3-108-AS-04 | No suggested change sets a calorie target below the safe minimum. |
| TC-FR3-108-AS-05 | A member with an eating-disorder flag sees no plateau message. |

#### FR3-109 Sleep and stress answers shape the plan

Stage 3 · Healthy Weight programme · Must · Status: To be built

The sleep and stress answers from joining (FR3-98) change the plan from day 1, through rules the owner signs off (NFR3-17). Sleep under 7 hours or stress 7 or more: the week 1 mindset note on sleep, stress and appetite is brought forward, one sleep or stress step a week is added to weeks 1 to 8, and at week 9 the 'Stress and emotional eating' or 'Sleep and weight' focus block is suggested in the signed order (R-HW-BLOCK-01: plateau, festival and biggest barrier come first). Stress 8 or more: the gentle pace of 0.25 kg a week is suggested, with the reason. Stress 9 or 10, or sleep under 6 hours: the approved support wording is shown ('if this goes on for more than two weeks, talk to your doctor') at joining and again at the first two weekly check-ins if the answer has not changed, the Sleep and stress check (FR3-54) is offered, and the Sleep and Stress track is offered as the optional extra goal, which keeps its own safety questions (FR2-38). When both a sleep step and a stress step apply, they alternate weeks (sleep in odd weeks, stress in even), and each replaces one activity so there is still one step a day. The answers are health data: they need health consent, are in 'Download my data', are deleted with the account or when consent is withdrawn, and never appear in emails or analytics.

| Test ID | What the test checks |
|---|---|
| TC-FR3-109-AS-01 | A test member who answers sleep 6 to 7 hours sees the sleep, stress and appetite note in week 1 and one sleep or stress step in each of weeks 1 to 8. |
| TC-FR3-109-AS-02 | With no plateau, no festival due, biggest barrier 'not sure' and stress under 7, the same member is suggested 'Sleep and weight' at week 9; a member with stress 7 and sleep 7 to 9 is suggested 'Stress and emotional eating' (the signed R-HW-BLOCK-01 order). |
| TC-FR3-109-AS-03 | A member with stress 8 is suggested 0.25 kg a week with the reason, and can still choose another safe pace. |
| TC-FR3-109-AS-04 | A member with stress 9, or sleep under 6 hours, sees the approved support wording and an offer of the Sleep and Stress track as the extra goal. |
| TC-FR3-109-AS-05 | Accepting that offer starts the Sleep and Stress safety questions (FR2-38) before the track. |
| TC-FR3-109-AS-06 | With stress 9 unchanged, the support wording shows again at the first and second weekly check-ins and not at the third. |
| TC-FR3-109-AS-07 | A member with stress 8 and sleep under 6 hours gets a sleep step in weeks 1, 3, 5 and 7 and a stress step in weeks 2, 4, 6 and 8, and each day still has exactly one step. |
| TC-FR3-109-AS-08 | A member with sleep 7 to 9 hours and stress 5 gets none of these changes. |
| TC-FR3-109-AS-09 | The sleep and stress answers are in 'Download my data' and in no email or analytics event. |

#### FR3-110 Alcohol answers shape the plan

Stage 3 · Healthy Weight programme · Must · Status: To be built

The alcohol answers from joining (FR3-98) change the plan from day 1, through rules the owner signs off (NFR3-17). Drinks count against the daily calorie target like food, and the target itself does not change (FR3-102). For a member who drinks, the plan shows 'Your usual week of drinks is about {weekly} kcal, roughly {daily} a day', worked out from the two answers with about 130 kcal a pictured drink (owner, D3-56, 6 Nov 2026; one pictured drink is about 12.5 g of alcohol), recorded in the method sheet with the 'where you are now' bands (NFR3-14); members with an eating-disorder flag never see it. A member who drinks gets an evening 'Add a drink' shortcut on the Today screen that opens the food tracker's drinks in standard sizes (glass of wine, bottle or pint of beer or cider, single spirit), with mixers as separate items. Drinking 3 to 4 days a week or more, or 5 or more drinks on a drinking day: one alcohol-free-day step a week is added to weeks 1 to 8 (for a member who drinks most days with 5 or more a time, 'one drink fewer on one day' instead, never an alcohol-free day or 'stop' wording), and the 'Alcohol' focus block is suggested at week 9 in the signed order (R-HW-BLOCK-01: after plateau, festival, biggest barrier, stress and sleep; FR3-104). A member who answers 'never', or whose food rules exclude alcohol, gets no alcohol activity, note or shortcut. Above the drinking limit (more than 8 pictured drinks a week, about 100 g of alcohol, owner, D3-55, 6 Nov 2026, worked out from the two answers), or 5 or more drinks on a drinking day, the approved support card is shown, at joining and at the first two check-ins if the answers have not changed, to Free and Premium members alike: less is better for health and weight, help is available, and anyone who drinks heavily every day should talk to their doctor before cutting down suddenly, because stopping suddenly can be dangerous. No weight-loss message is shown beside the card. The answers are health data: they need health consent, are in 'Download my data', are deleted with the account or when consent is withdrawn, and never appear in emails, analytics or admin pages.

| Test ID | What the test checks |
|---|---|
| TC-FR3-110-AS-01 | A test member who drinks 1 to 2 days a week, 3 to 4 drinks a time, sees 'about 680 kcal a week, roughly 100 a day' at 130 kcal a drink, and their calorie target is the same as with 'never'. |
| TC-FR3-110-AS-02 | A drink logged from the evening 'Add a drink' shortcut counts against that day's calorie target in the food tracker. |
| TC-FR3-110-AS-03 | The staging food tracker offers wine, beer, cider and spirits in standard sizes, with mixers as separate items. |
| TC-FR3-110-AS-04 | A member who drinks 3 to 4 days a week gets one alcohol-free-day step in each of weeks 1 to 8 and, with no plateau, no festival due, biggest barrier 'not sure', stress under 7 and sleep 7 hours or more, is suggested 'Alcohol' at week 9 (the signed R-HW-BLOCK-01 order). |
| TC-FR3-110-AS-05 | A member who answers 'never', or whose food rules exclude alcohol, sees no alcohol activity, note or shortcut in weeks 1 to 8. |
| TC-FR3-110-AS-06 | A member who drinks most days, 3 to 4 drinks a time (about 21 a week), sees the support card with the doctor wording on both Free and Premium, and no weight-loss message beside it. |
| TC-FR3-110-AS-07 | A member who drinks less than once a week but 5 or more drinks a time also sees the support card. |
| TC-FR3-110-AS-08 | A member who drinks 3 to 4 days a week, 3 to 4 drinks a time (about 12 a week), sees the support card; a member who drinks 1 to 2 days a week, 3 to 4 a time (about 5 a week), does not. |
| TC-FR3-110-AS-09 | A member who drinks most days, 5 or more a time, gets 'Have one drink fewer on one day this week' and never an alcohol-free-day step, with the support card shown first. |
| TC-FR3-110-AS-10 | A member with an eating-disorder flag sees no drink calorie figure. |
| TC-FR3-110-AS-11 | The alcohol answers are in 'Download my data' and in no email, analytics event or admin page. |

#### FR3-111 A Library members can search

Stage 3 · Member area · Must · Status: To be built

The member Library shows each approved item once, as a card with its type and, where the item has one, its length. A search box and filters by type (recipes, workout plans, challenges, lessons) let a member find an item quickly. (Member area build list A8, 14 Nov 2026.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-111-AS-01 | Type part of a known approved title in the search box: that item is listed and items that don't match are hidden. |
| TC-FR3-111-AS-02 | Choose one type filter: only items of that type are listed; clearing it lists every type again. |
| TC-FR3-111-AS-03 | An approved item used on two tracks appears once, naming both tracks. |
| TC-FR3-111-AS-04 | An unapproved or withdrawn item never appears, whatever the search or filter. |
| TC-FR3-111-AS-05 | At 360 pixels wide the search and filters work, and both can be used with the keyboard alone. |

#### FR3-112 Honest track progress

Stage 3 · Member area · Must · Status: To be built

A track week shows a tick and 'Completed' only when all seven of its days were done. Every past week shows how many days were done, so an ended track never looks finished when it wasn't. (Member area build list A2, 14 Nov 2026.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-112-AS-01 | A week with all seven days done shows a tick and 'Completed'. |
| TC-FR3-112-AS-02 | A past week with fewer than seven days done shows no tick and 'N of 7 days done'. |
| TC-FR3-112-AS-03 | A track that ended with no days done shows no tick and no 'Completed' on any week, and the total reads '0 of 28 done'. |

#### FR3-113 Terms before the member area

Stage 3 · Member area · Must · Status: To be built

A member who has not accepted the current terms is taken to Agreements before any member page. Calculators, articles and Help stay open to them. (Member area build list A6, 14 Nov 2026.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-113-AS-01 | A confirmed member who has not accepted the terms signs in: Agreements opens before Today. |
| TC-FR3-113-AS-02 | The same member opens /members/tracks/ directly: Agreements opens instead, and no track can be started. |
| TC-FR3-113-AS-03 | The same member can still open the calculators, articles and Help. |
| TC-FR3-113-AS-04 | After accepting, the member is taken to the page they were going to. |

#### NFR3-01 Stage 2 keeps working

Stage 3 · Protecting Stage 2 · Must · Status: To be built

No Stage 3 change may break anything built in Stage 2. Every Stage 3 pull request runs the full Stage 2 test suite, and any failure blocks the merge.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-01-AS-01 | For each Stage 3 pull request, the full set of CI checks, including all three browser test jobs, has finished and passed before merging. |
| TC-NFR3-01-AS-02 | A deliberately broken Stage 2 page in a test pull request is caught and blocks the merge. |

#### NFR3-02 Stage 2 tests cannot be weakened

Stage 3 · Protecting Stage 2 · Must · Status: To be built

A Stage 3 pull request cannot change, skip or delete a Stage 2 test, except where a recorded owner decision changes the Stage 2 behaviour (for example the new prices). An automatic guard checks this.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-02-AS-01 | A test pull request that edits a Stage 2 test file without a recorded decision is failed by the guard. |
| TC-NFR3-02-AS-02 | A test pull request that marks a Stage 2 test as skipped is failed by the guard. |

#### NFR3-03 Stage 2 key journeys always tested

Stage 3 · Protecting Stage 2 · Must · Status: To be built

A set of key Stage 2 journeys (sign up, sign in, choose a goal, start a track, open approved content, food diary, Healthy Weight Check, account and privacy pages) is tested on every change and after every staging deploy.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-03-AS-01 | The key-journeys test set exists and covers each journey named above. |
| TC-NFR3-03-AS-02 | The key-journeys tests run in CI and in the smoke tests after each staging deploy. |

#### NFR3-04 Database changes only add

Stage 3 · Protecting Stage 2 · Must · Status: To be built

Stage 3 database changes may only add new tables and columns. They never rename, delete or change Stage 2 ones. An automatic check reads every database change.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-04-AS-01 | A test database change that drops or renames a Stage 2 column is failed by the check. |
| TC-NFR3-04-AS-02 | Running the Stage 3 database update on a copy of staging leaves every Stage 2 table and column unchanged. |

#### NFR3-05 Stage 3 stays within its files

Stage 3 · Protecting Stage 2 · Must · Status: To be built

Stage 3 work may only change its own files, plus a short declared list of Stage 2 files. The scope guard enforces this, and changes to the declared Stage 2 files need the lead's review.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-05-AS-01 | A test pull request that changes an undeclared Stage 2 file is failed by the scope guard. |
| TC-NFR3-05-AS-02 | A pull request that changes a declared Stage 2 file is labelled for lead review. |

#### NFR3-06 Each Stage 3 feature has an on/off switch

Stage 3 · Protecting Stage 2 · Must · Status: To be built

Since 3 November 2026 every Stage 3 feature is on, because the whole system launches together (owner decision); features added later are on too. Each feature keeps its own switch (off, test accounts, everyone) only so that it can be turned off in an emergency from the admin panel (FR3-74). The tests below check that the switch still works.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-06-AS-01 | With all Stage 3 switches off, the key Stage 2 journeys look and behave exactly as before (screenshot comparison). |
| TC-NFR3-06-AS-02 | A feature switched on for test accounts only is not visible to an ordinary member account. |

#### NFR3-07 Quick rollback

Stage 3 · Protecting Stage 2 · Must · Status: To be built

There is a written, tested rollback: restore the pre-deploy backup and deploy the previous version, in less than 15 minutes.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-07-AS-01 | A rehearsal on staging restores the previous version in under 15 minutes, and the key journeys pass afterwards. |

#### NFR3-08 One launch after Stage 3

Stage 3 · Launch · Must · Status: To be built

Paid membership (Stages 2 and 3 together) goes live once, after Stage 3 has been checked: payments, crawl, performance, security and accessibility tests, then the owner's approval. The live site is released only from an approved release-candidate tag.

_Related decision: I-03._

| Test ID | What the test checks |
|---|---|
| TC-NFR3-08-AS-01 | The production release workflow only accepts an approved release-candidate tag. |
| TC-NFR3-08-AS-02 | The release checklist records a pass for payments, crawl, performance, security and accessibility tests before the tag is approved. |

#### NFR3-09 Data ready for the app

Stage 3 · Data and privacy · Must · Status: To be built

All Stage 3 data (goals, food, logs, targets, recipes, insights) is stored so that the Stage 4 app can read and write it through the same secure interface. This extends NFR2-09.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-09-AS-01 | Every Stage 3 data type can be read and written through the members' programming interface by a signed-in test member. |
| TC-NFR3-09-AS-02 | A member can never read another member's data through that interface. |

#### NFR3-10 Health data protected

Stage 3 · Data and privacy · Must · Status: To be built

Health logs are stored protected with the site's data key, are only visible to the member, and never appear in server logs, emails, analytics or error reports.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-10-AS-01 | Health values in the database are not readable as plain text. |
| TC-NFR3-10-AS-02 | A search of server logs, caught emails and analytics events after a full test run finds no health values. |

#### NFR3-11 Fast pages

Stage 3 · Quality · Must · Status: To be built

The home page and article pages pass Google's speed measures (LCP, INP and CLS) on mobile, and logging pages respond quickly. This finishes the Stage 2 speed work (FR2-27, NFR2-05).

_Related decision: I-03._

| Test ID | What the test checks |
|---|---|
| TC-NFR3-11-AS-01 | Lab tests of the home page and three article pages on a mobile profile show LCP under 2.5 seconds, INP under 200 ms and CLS under 0.1. |
| TC-NFR3-11-AS-02 | Saving a log entry shows the result in under 1 second on staging. |

#### NFR3-12 Easy to use on a phone

Stage 3 · Quality · Must · Status: To be built

Every Stage 3 page works well on a phone from 360 pixels wide, with large tap targets, so logging takes only a few taps.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-12-AS-01 | At 360 pixels wide, no Stage 3 page scrolls sideways. |
| TC-NFR3-12-AS-02 | Every button and link is at least 44 by 44 pixels. |
| TC-NFR3-12-AS-03 | Logging a blood pressure reading from the Today screen takes no more than 4 taps. |

#### NFR3-13 Accessible

Stage 3 · Quality · Must · Status: To be built

Stage 3 pages meet the WCAG 2.2 AA accessibility standard. Every chart has a table view.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-13-AS-01 | An automated accessibility scan of every Stage 3 page finds no serious or critical problems. |
| TC-NFR3-13-AS-02 | Every chart has a 'Show as table' option with the same numbers. |

#### NFR3-14 Calculators and warning levels signed off and frozen

Stage 3 · Clinical safety · Must · Status: To be built

Every new calculator, goal safety limit, warning level and safety wording is signed off by the owner as clinical reviewer before members see it, and then frozen in the same way as the Stage 1 calculators. Each calculator's method sheet is drafted at the start of its build: the lead researches the best available evidence-based method, writes the sheet (inputs, who it is for and not for, missing data, units, rounding, exact limits, wording and independently checked expected results), and brings any issues to the owner with options and a recommendation. The owner signs the sheet off before the calculator's code is written.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-14-AS-01 | Each new calculator has a signed-off method sheet and reference fixture file before its code is merged, and the engine-freeze check covers it. |
| TC-NFR3-14-AS-02 | Changing a frozen formula, limit, warning level or wording without a recorded decision fails CI. |

#### NFR3-15 Trusted food data

Stage 3 · Quality · Must · Status: To be built

Food data starts with free sources (USDA FoodData Central for basic foods, Open Food Facts for packaged foods), is shown with its source, and is built so a licensed database can be added later without changing the tracker.

_Related decision: I-07._

| Test ID | What the test checks |
|---|---|
| TC-NFR3-15-AS-01 | Every food shows its data source. |
| TC-NFR3-15-AS-02 | The source licence terms are recorded and the attribution is shown where the licence requires it. |
| TC-NFR3-15-AS-03 | Switching the food source in a test setting does not change any tracker screen or test result other than the food values. |

#### NFR3-16 Analytics never sees health data

Stage 3 · Data and privacy · Must · Status: To be built

The Google Analytics tag is never loaded in the member area or on calculator result screens, and no analytics event carries health data (readings, results, goals, track names tied to a person) or member IDs.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-16-AS-01 | After accepting cookies, a full test run of the member area and calculator results sends no request to Google Analytics. |
| TC-NFR3-16-AS-02 | A check of every analytics event sent during the test run finds no health values, goal names or member IDs. |

#### NFR3-17 Insight rules are safe and tested

Stage 3 · Clinical safety · Must · Status: To be built

Every decision-engine rule has a minimum amount of data and a minimum difference before it can fire, uses owner-approved wording, and has made-up test data that must and must not trigger it. Rules and wording are frozen once approved. Before the engine is built, the owner signs off the first rule catalogue: for each rule the exact condition, calculation window, how missing days and several readings a day are handled, which rule wins when rules disagree, what happens before there is enough data, and test data at the boundary.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-17-AS-01 | For every rule, its 'should fire' test data fires it and its 'should not fire' test data does not. |
| TC-NFR3-17-AS-02 | Every rule's message matches an approved template. |
| TC-NFR3-17-AS-03 | Changing an approved rule or its wording without a recorded decision fails CI. |

#### NFR3-18 Sign-in codes and social sign-in are secure

Stage 3 · Data and privacy · Must · Status: To be built

Email codes are random 6-digit numbers that last 10 minutes, work once and are limited to a few tries and requests. Google sign-in, and Apple sign-in when FR4-30 is built, use their official secure methods and only ask for name and email.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-18-AS-01 | Codes are produced by the system's secure random generator (checked in code review and by an automated check that the insecure generators are not used). |
| TC-NFR3-18-AS-02 | Replaying a used code fails, and two simultaneous attempts with the same code let only one succeed. |
| TC-NFR3-18-AS-03 | Code tries and requests beyond the limits are refused. |
| TC-NFR3-18-AS-04 | The Google sign-in request (and Apple's, once FR4-30 is built) asks only for name and email. |

#### NFR3-19 No health data in the admin panel

Stage 3 · Data and privacy · Must · Status: Built, on staging (#217)

No admin page, count, export, log entry or admin request shows members' health readings, food diaries, health answers, goals, tracks or calculator results, for any role, the Owner included. A member's data leaves their account only through their own download.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-19-AS-01 | After a full test run with seeded health data, a search of every admin page, the activity log and its CSV download finds none of the seeded health values, goals or track names. |
| TC-NFR3-19-AS-02 | A check of every admin request finds none that reads the health, food, goal or calculator tables. |

#### NFR3-20 Secure admin sign-in

Stage 3 · Security · Must · Status: Built, on staging (#222, 6 Nov 2026)

Every admin role (Owner and Support) enters an emailed 6-digit code at every sign-in, extending NFR2-02. The code is asked for again before deleting a member and before adding or removing a helper; feature and tool toggles need no code (FR3-74). Admin sessions end after 30 minutes without activity and always after 12 hours. After 5 wrong passwords or codes the account is locked for 15 minutes and the Owner is emailed. The same rules apply to My account → Admin, and to the WordPress admin for the Owner.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-20-AS-01 | A Support test account must enter an email code to sign in. |
| TC-NFR3-20-AS-02 | Deleting a member, and adding or removing a helper, each ask for a fresh code; a feature toggle does not. |
| TC-NFR3-20-AS-03 | An admin session is signed out after 30 minutes idle, and after 12 hours even when active. |
| TC-NFR3-20-AS-04 | The 6th failed attempt is refused for 15 minutes and an alert email is caught by the mail catcher. |

#### NFR3-21 Limits and alerts on admin actions

Stage 3 · Security · Must · Status: Built, on staging (#217)

Each admin can delete at most 5 members an hour; further deletions wait. The Owner is emailed when a helper is added or removed and when any admin signs in from a new device or browser.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-21-AS-01 | A 6th deletion within an hour by the same admin is refused with a message saying when it is allowed again. |
| TC-NFR3-21-AS-02 | Adding a helper, removing a helper, and signing in from a new browser each send an alert email to the Owner. |

#### NFR3-22 Admin panel works on a phone and is hidden

Stage 3 · Quality · Must · Status: Built, on staging (#222, 6 Nov 2026)

Every new admin page works on a phone from 360 pixels wide and meets WCAG 2.2 AA, as the member pages do. Admin pages never load Google Analytics, are never indexed by search engines and are never cached. This covers every page in My account → Admin.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-22-AS-01 | At 360 pixels wide, no new admin page scrolls sideways, and an automated accessibility scan finds no serious or critical problems. |
| TC-NFR3-22-AS-02 | No admin page sends a request to Google Analytics, and each is marked noindex. |

#### NFR3-23 Erasure checker covers the admin records

Stage 3 · Data and privacy · Must · Status: Built, on staging (#217)

The erasure checker that confirms a deleted member leaves nothing behind also covers private notes and Premium grants, and checks that activity log entries keep only the member number.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-23-AS-01 | After a test member with notes, a Premium grant and log entries is deleted, the erasure checker finds no note or grant, and log entries with only the member number. |

#### NFR3-24 Admin screens are friendly and modern

Stage 3 · Quality · Must · Status: Built, on staging (#222, 6 Nov 2026)

This covers the admin module: every screen in My account → Admin, and the Lifeline Daily screens the Owner still uses in the WordPress admin. Each is easy to use without training and looks as modern as the member pages, in the site's own design (UI2-01), not the plain WordPress-admin look. Plain words, never code names ('Heart Risk Check', not calc_heart_risk). Simple controls: on/off toggles for switches, clear buttons, and choices shown as options rather than typed in. Cards and a clear layout that starts with what matters most and fits a phone from 360 pixels wide (NFR3-22). Search and filters on every list longer than one screen. Instant feedback: every action shows 'Saved' (with Undo where it can be undone) or a plain message saying what went wrong and what to do. Helpful empty screens. A confirmation only for actions that cannot be undone (deleting a member, ending a membership), stating exactly what will happen. One look across every admin page. The admin screens are designed and approved by the owner before they are built, as the member screens were.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-24-AS-01 | No admin page shows a code name (a word with an underscore or a calc_ prefix) as a label. |
| TC-NFR3-24-AS-02 | Every admin page uses the site's design styles, and a visual check at phone and desktop sizes matches the approved screen designs. |
| TC-NFR3-24-AS-03 | Every admin action shows a success or error message, and every list longer than one screen has a search box. |
| TC-NFR3-24-AS-04 | The Owner completes a set list of everyday tasks (find a member, pause them, turn a tool off and on, read a staging email) without help, in a usability check. |
| TC-NFR3-24-AS-05 | An automated accessibility scan of every admin page finds no serious or critical problems. |
