# Stage 3 test criteria (extract)

Extracted on 2 November 2026 from `01 Requirements/Lifeline_Daily_Requirements_All_Stages.md` v0.3 by the lead. Every `TC-FR3-nn-AS-nn` and `TC-NFR3-nn-AS-nn` used under `tests/stage3/` must appear here (`stage3-guards`). Do not edit by hand: re-extract from the requirements when they change.

#### FR3-01 Sign in with Google

Stage 3 · Sign-in and account · Must · Status: To be built

Members can create an account and sign in with their Google account. Only their name, email and Google's account ID are taken from Google. The Stage 2 agreements and health consent are still asked for before the member continues.

| Test ID | What the test checks |
|---|---|
| TC-FR3-01-AS-01 | Sign up with a test Google account: an account is created with the Google name and email, and the Agreements step is shown next. |
| TC-FR3-01-AS-02 | Sign out, then sign in with the same Google account: the member returns to the same account. |
| TC-FR3-01-AS-03 | No Google data other than name, email and Google's account ID is stored. |

#### FR3-02 Sign in with Apple

Stage 3 · Sign-in and account · Must · Status: To be built

Members can create an account and sign in with their Apple account, including when they choose Apple's 'Hide my email'. The agreements and health consent are still asked for.

| Test ID | What the test checks |
|---|---|
| TC-FR3-02-AS-01 | Sign up with a test Apple account: an account is created and the Agreements step is shown next. |
| TC-FR3-02-AS-02 | Sign up with 'Hide my email': the account works and emails reach the Apple relay address. |

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

A Google or Apple sign-in is matched to an existing account only when Google or Apple confirms the email is verified; otherwise the member must sign in the usual way first and link it from the Account hub. The account is recognised by the provider's account ID, so a later change of email at Google or Apple keeps the same account. A member cannot remove their last way to sign in. A member with no password confirms account deletion or other sensitive changes with an email code. Signing out everywhere ends all sessions, on the website and the app.

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

#### FR3-07 Account menu on desktop

Stage 3 · Sign-in and account · Could · Status: To be built

On a desktop screen, clicking the member's name opens a small menu with Profile, My subscription, My data, Help and Sign out. (U5.)

| Test ID | What the test checks |
|---|---|
| TC-FR3-07-AS-01 | At 1280 pixels wide, click the name chip: a menu with the five links opens. |
| TC-FR3-07-AS-02 | Each link goes to the right page, and Sign out signs the member out. |
| TC-FR3-07-AS-03 | The menu can be opened and used with the keyboard alone. |

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

#### FR3-10 Starting measurements

Stage 3 · Goal journey · Must · Status: To be built

When a goal is set, the member records the starting measurements that matter for it, such as weight, waist, blood pressure, activity or sleep. These are the baseline for showing progress.

| Test ID | What the test checks |
|---|---|
| TC-FR3-10-AS-01 | Set a weight-loss goal: the member is asked for starting weight and waist, and these are saved as the baseline. |
| TC-FR3-10-AS-02 | Set a blood pressure goal: the member is asked for a starting reading. |
| TC-FR3-10-AS-03 | The member can skip a measurement and add it later; progress is shown once it exists. |

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

A new track for members who have reached a weight goal, focused on keeping the weight off.

| Test ID | What the test checks |
|---|---|
| TC-FR3-20-AS-01 | The track has owner-approved content for every week. |
| TC-FR3-20-AS-02 | A member who reaches a weight-loss goal is offered this track. |

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

Lifeline Daily suggests recipes from the owner-approved recipe library that fit the member's goal, plan, calories left for the day, dislikes and allergies. No recipe is written by AI. Premium.

| Test ID | What the test checks |
|---|---|
| TC-FR3-36-AS-01 | A test member with a nut allergy is never shown a recipe that contains nuts. |
| TC-FR3-36-AS-02 | Every suggested recipe fits within the member's calories left for the day. |
| TC-FR3-36-AS-03 | Every suggested recipe is in the approved library. |

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

#### FR3-62 Plans and prices at launch

Stage 3 · Paid membership · Must · Status: To be built

Lifeline Daily launches after Stage 3. Premium costs US$9.99 a month or US$69.99 a year (about 42% cheaper, a saving of US$49.89). Prices include any sales tax, so customers pay exactly the price shown (carried forward from FR2-13). The same price applies on the website and, later, in the app. Members who join keep their price if it rises later, including after a pause. This replaces the Stage 2 prices in FR2-13.

| Test ID | What the test checks |
|---|---|
| TC-FR3-62-AS-01 | The pricing page shows US$9.99 a month and US$69.99 a year, with the yearly saving, both marked 'tax included'. |
| TC-FR3-62-AS-02 | For test customers in the UK, Germany and the US, the amount charged equals the amount shown. |
| TC-FR3-62-AS-03 | A Stripe test checkout for each plan charges the price shown. |
| TC-FR3-62-AS-04 | A test member on an older price keeps it when the price for new members is changed. |

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

#### FR3-70 Weekly owner report

Stage 3 · Analytics · Must · Status: To be built

The Monday report from Stage 2 (FR2-17) continues after the switch to Google Analytics. Sign-ups, upgrades, renewals, pauses, cancellations, revenue and member numbers come from Lifeline Daily's own database and Stripe; visits and calculator use come from Google Analytics totals. No personal or health data is included.

| Test ID | What the test checks |
|---|---|
| TC-FR3-70-AS-01 | On a Monday, a test report is caught by the mail catcher with sign-ups, upgrades, renewals, pauses, cancellations, revenue and member numbers matching the test data. |
| TC-FR3-70-AS-02 | The report contains no names, emails or health values. |

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

Each Stage 3 feature can be switched on or off on its own. On staging it is visible only to test accounts until the owner approves it, so the Stage 2 screens the owner is checking stay as they are.

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

Email codes are random 6-digit numbers that last 10 minutes, work once and are limited to a few tries and requests. Google and Apple sign-in use their official secure methods and only ask for name and email.

| Test ID | What the test checks |
|---|---|
| TC-NFR3-18-AS-01 | Codes are produced by the system's secure random generator (checked in code review and by an automated check that the insecure generators are not used). |
| TC-NFR3-18-AS-02 | Replaying a used code fails, and two simultaneous attempts with the same code let only one succeed. |
| TC-NFR3-18-AS-03 | Code tries and requests beyond the limits are refused. |
| TC-NFR3-18-AS-04 | The Google and Apple sign-in requests ask only for name and email. |
