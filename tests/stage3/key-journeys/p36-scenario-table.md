# P3-36: 56 journey scenarios

Every row runs as its own test in `p36-journeys.spec.mjs` (56 tests, in Chromium and WebKit). The last column is what the journey tests report of 13 November 2026 found by hand, before the P3-36 fixes: it is history, not a test result. Each scenario is named after the main criterion it exercises; a journey is a smoke test of that step and does not replace the detailed tests of the criterion. Google sign-in and card payment use the CI stand-ins (the provider test route, and a signed Stripe notice); a real Google account and a real card are not tested here. The spec turns on the switches staging has on and CI leaves off (`terms_first`, `prices_launch`, `access_tiers`, `membership_card`, `pause`) and puts them back afterwards.

| Scenario | Source step | Primary assertion | Historical result |
|---|---|---|---|
| UC1-S1 | Open the homepage | TC-NFR3-03-AS-01 | Pass |
| UC1-S2 | Open the health tools hub | TC-NFR3-03-AS-01 | Pass |
| UC1-S3 | Open pricing | TC-FR3-62-AS-01 | Pass |
| UC1-S4 | Open a calculator | TC-NFR3-03-AS-01 | Pass |
| UC2-S1 | Fill in Create account | TC-FR2-01-AS-01 | Pass |
| UC2-S2 | Confirmation email arrives | TC-FR2-01-AS-01 | Pass |
| UC2-S3 | Open the link | TC-FR2-01-AS-01 | Pass |
| UC2-S4 | Continue to sign in | TC-FR2-03-AS-01 | Pass |
| UC2-S5 | Accept terms and health information | TC-FR2-03-AS-01 | Pass |
| UC2-S6 | Sign in later without having agreed | TC-FR3-113-AS-01 | Fail |
| UC3-S1 | Sign in with email and password | TC-FR3-04-AS-04 | Pass |
| UC3-S2 | Sign out, then open a member page | TC-FR3-07-AS-02 | Problem |
| UC3-S3 | Wrong password | TC-FR2-01-AS-03 | Pass |
| UC3-S4 | Sign in with an emailed 6-digit code | TC-FR3-04-AS-01 | Pass |
| UC3-S5 | Forgot password: reset link, new password | TC-NFR3-03-AS-01 | Pass |
| UC3-S6 | Old password after a reset | TC-NFR3-03-AS-01 | Pass |
| UC3-S7 | Continue with Google | TC-FR3-01-AS-01 | Not tested |
| UC4-S1 | Save the health profile | TC-FR3-69-AS-06 | Problem |
| UC4-S2 | Open the goal before the profile is complete | TC-FR3-09-AS-08 | Problem |
| UC4-S3 | Lose weight, target 84 kg, Save my goal | TC-FR3-10-AS-01 | Pass |
| UC4-S4 | Goal page afterwards | TC-FR3-11-AS-01 | Pass |
| UC4-S5 | Progress page | TC-FR3-13-AS-03 | Pass |
| UC5-S1 | Mark the next step done | TC-FR3-12-AS-02 | Pass |
| UC5-S2 | Quick log: +1 glass of water | TC-FR3-43-AS-01 | Pass |
| UC5-S3 | Follow Today's links | TC-FR3-18-AS-01 | Fail |
| UC6-S1 | Find a screen to log blood pressure or weight | TC-FR3-38-AS-01 | Fail |
| UC6-S2 | Log a reading with Conditions unanswered (interface) | TC-FR3-69-AS-05 | Problem |
| UC6-S3 | Log weight, 2 blood pressures, glucose (interface) | TC-FR3-38-AS-01 | Pass |
| UC6-S4 | Log an unsafe 186/122 (interface) | TC-FR3-46-AS-01 | Pass |
| UC6-S5 | Today shows latest readings | TC-FR3-18-AS-01 | Pass |
| UC7-S1 | Simple diary: water, hunger, energy | TC-FR2-37-AS-01 | Pass |
| UC7-S2 | Find a screen to search for a food | TC-FR3-26-AS-01 | Fail |
| UC7-S3 | Search “banana” (interface) | TC-FR3-26-AS-01 | Pass |
| UC7-S4 | Log 120 g banana for breakfast (interface) | TC-FR3-27-AS-01 | Pass |
| UC7-S5 | Open barcode scanning as Free | TC-FR3-63-AS-04 | Pass |
| UC8-S1 | Start Healthy Weight today | TC-NFR3-03-AS-01 | Pass |
| UC8-S2 | Progress is consistent for a new track | TC-FR3-112-AS-02 | Pass |
| UC8-S3 | A track that ended with days not done | TC-FR3-112-AS-03 | Not tested |
| UC9-S1 | Enter this week's weight and save | TC-FR3-14-AS-03 | Pass |
| UC10-S1 | Free member opens meals, workout, challenge, badges | TC-FR3-63-AS-04 | Pass |
| UC10-S2 | Start an upgrade | TC-FR3-62-AS-03 | Pass |
| UC10-S3 | Pay with a card | TC-FR3-62-AS-03 | Not tested |
| UC10-S4 | Premium (free, from the Owner) unlocks meals and workout | TC-FR3-80-AS-01 | Pass |
| UC10-S5 | Premium shown the same everywhere | TC-FR3-64-AS-04 | Fail |
| UC10-S6 | Pause, cancel, renewal | TC-FR3-65-AS-06 | Not tested |
| UC11-S1 | Change the display name | TC-FR3-06-AS-02 | Pass |
| UC11-S2 | Download all my data | TC-FR3-60-AS-01 | Pass |
| UC11-S3 | Send a message from Help | TC-FR2-30-AS-01 | Pass |
| UC11-S4 | Set reminders | TC-FR3-56-AS-04 | Not tested |
| UC11-S5 | Delete my account: wrong password, then right | TC-FR2-04-AS-02 | Pass |
| UC11-S6 | Data is removed after deletion | TC-FR3-61-AS-01 | Pass |
| UC12-S1 | Find a member by email | TC-FR3-75-AS-01 | Pass |
| UC12-S2 | Give free Premium for 30 days | TC-FR3-80-AS-01 | Pass |
| UC12-S3 | Remove free Premium | TC-FR3-80-AS-03 | Pass |
| UC12-S4 | Switch a calculator off | TC-FR3-89-AS-01 | Pass |
| UC12-S5 | Switch it back on | TC-FR3-89-AS-03 | Pass |

The features these journeys depend on (Food, Log a reading, Today's links, the Premium status, terms before the member area, the tap controls) are all merged. The suite runs inside the three existing Stage 3 browser jobs.
