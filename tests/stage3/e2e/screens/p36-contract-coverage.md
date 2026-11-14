# P3-36 Food/Readings coverage matrix

63 ratified assertion rows. This is a coverage map, not a claim that all are green. B1/B2 remain unmerged.

| Assertion | Coverage / limitation | Required behaviour |
|---|---|---|
| TC-FR3-26-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Search 'banana': results appear within 1 second and the first results are bananas, with calories per portion. |
| TC-FR3-26-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | A search with no match shows a clear message and a button to add a custom food. |
| TC-FR3-26-AS-03 | Existing: tests/stage3/food-import/TC-FR3-26.test.mjs | Search works with British and American spellings (for example yoghurt and yogurt). |
| TC-FR3-27-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Add 150 g of cooked rice to lunch: it appears under lunch with the right calories for 150 g. |
| TC-FR3-27-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | Edit the portion to 200 g: the entry and the day's totals update. |
| TC-FR3-27-AS-03 | New screen contract: p36-food.spec.mjs (see loop findings) | Delete the entry: it disappears and the totals go down. |
| TC-FR3-27-AS-04 | New screen contract: p36-food.spec.mjs (see loop findings) | Choose a past day: that day's entries are shown by meal. |
| TC-FR3-28-AS-01 | Existing backend: food-log.test.mjs / calorie-target.test.mjs; calculator-to-screen integration not newly tested | A test member who completes the calorie calculator gets a daily target equal to the calculator's result. |
| TC-FR3-28-AS-02 | Existing backend: food-log.test.mjs / calorie-target.test.mjs; calculator-to-screen integration not newly tested | Trying to set a calorie target below the safe minimum shows a warning and is not saved. |
| TC-FR3-28-AS-03 | Existing backend: food-log.test.mjs / calorie-target.test.mjs; calculator-to-screen integration not newly tested | The protein target matches the member's Daily Protein Target result. |
| TC-FR3-29-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Log three foods with known values: the day's totals equal the sum of the three. |
| TC-FR3-29-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | The amount left equals the target minus the total. |
| TC-FR3-29-AS-03 | New screen contract: p36-food.spec.mjs (see loop findings) | Going over the target shows the amount over, without alarming wording. |
| TC-FR3-30-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Create a custom food: it appears in the member's own search results. |
| TC-FR3-30-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | Another test member cannot find it. |
| TC-FR3-30-AS-03 | New screen contract: p36-food.spec.mjs (see loop findings) | Deleting the custom food keeps past diary entries that used it. |
| TC-FR3-31-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Log a food: it appears at the top of Recent. |
| TC-FR3-31-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | Mark a food as a favourite: it appears in Favourites and can be logged with one tap. |
| TC-FR3-32-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Copy yesterday's breakfast to today: the same entries appear under today's breakfast. |
| TC-FR3-32-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | Copy a whole day: every meal is copied and the totals match the original day. |
| TC-FR3-33-AS-01 | Existing backend: food-earlier.test.mjs; full legacy screen/photo parity not newly tested | A test member's Stage 2 entry 'homemade curry, normal portion, with photo' appears on its date and meal as an earlier diary entry with its description, portion and photo. |
| TC-FR3-33-AS-02 | Existing backend: food-earlier.test.mjs; full legacy screen/photo parity not newly tested | Earlier diary entries add nothing to the day's calorie or nutrient totals. |
| TC-FR3-33-AS-03 | Existing backend: food-earlier.test.mjs; full legacy screen/photo parity not newly tested | Every field of every Stage 2 diary entry is unchanged after Stage 3 is switched on (field-by-field comparison, not only counts). |
| TC-FR3-33-AS-04 | Existing backend: food-earlier.test.mjs; full legacy screen/photo parity not newly tested | Switching Stage 3 off and on again shows no duplicate entries. |
| TC-FR3-34-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | A test member with 7 logged days sees the correct average calories for the week. |
| TC-FR3-34-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | The number of days on target is correct. |
| TC-FR3-34-AS-03 | New screen contract: p36-food.spec.mjs (see loop findings) | The tip comes from the owner-approved tip list. |
| TC-FR3-35-AS-01 | New screen contract: p36-food.spec.mjs (see loop findings) | Create a recipe with 3 ingredients and 4 servings: nutrition per serving equals the ingredient totals divided by 4. |
| TC-FR3-35-AS-02 | New screen contract: p36-food.spec.mjs (see loop findings) | Log one serving: it appears in the diary with the per-serving values. |
| TC-FR3-35-AS-03 | New screen contract: p36-food.spec.mjs (see loop findings) | Edit an ingredient: the per-serving values update. |
| TC-FR3-36-AS-01 | Existing backend: food-recipes.test.mjs; suggestion-screen UI remains unfinished | A test member with a nut allergy is never shown a recipe that contains nuts. |
| TC-FR3-36-AS-02 | Existing backend: food-recipes.test.mjs; suggestion-screen UI remains unfinished | Every suggested recipe fits within the member's calories left for the day. |
| TC-FR3-36-AS-03 | Existing backend: food-recipes.test.mjs; suggestion-screen UI remains unfinished | Every suggested recipe is in the approved library. |
| TC-FR3-37-AS-01 | Existing screen: tests/stage3/e2e/screens/barcode-scan.spec.mjs | Scan a test barcode that is in the database: the product and its nutrition are shown, ready to log. |
| TC-FR3-37-AS-02 | Existing screen: tests/stage3/e2e/screens/barcode-scan.spec.mjs | Scan a test barcode that is not in the database: the member is offered 'Add this product'. |
| TC-FR3-37-AS-03 | Existing screen: tests/stage3/e2e/screens/barcode-scan.spec.mjs | If the member refuses camera access, typed search still works and a short message explains why scanning is unavailable. |
| TC-FR3-38-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record 128/82 with pulse 70: it appears in the list and on the 7-day chart. |
| TC-FR3-38-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and then delete the reading: the list and chart update each time. |
| TC-FR3-38-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Choose a date range: only readings in that range are shown. |
| TC-FR3-38-AS-04 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | A reading of 400/20 is refused as not possible, with a message to check the numbers. |
| TC-FR3-39-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record 80 kg, then switch units to pounds: the reading shows as 176.4 lb. |
| TC-FR3-39-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete readings: the list and chart update. |
| TC-FR3-39-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | The chart shows the trend over the chosen period. |
| TC-FR3-40-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record 90 cm: it appears in the list and chart. |
| TC-FR3-40-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete readings: the list and chart update. |
| TC-FR3-40-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | The page links to the guide on how to measure the waist correctly. |
| TC-FR3-41-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record a 30-minute brisk walk: it appears in today's list and adds 30 minutes to the weekly total. |
| TC-FR3-41-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete entries: totals update. |
| TC-FR3-41-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | The weekly chart shows minutes against the 150-minute guide line. |
| TC-FR3-42-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record bedtime 23:00 and wake time 07:00: the entry shows 8 hours. |
| TC-FR3-42-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | A night that crosses midnight is counted on the wake-up date. |
| TC-FR3-42-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete entries: the list and chart update. |
| TC-FR3-43-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Tap the water tile twice: today's water total goes up by two glasses. |
| TC-FR3-43-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete entries: the total updates. |
| TC-FR3-44-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record a mood of 2 out of 5: it appears in the list and trend. |
| TC-FR3-44-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete entries: the list and trend update. |
| TC-FR3-44-AS-03 | Existing backend: health-log.test.mjs; support belongs to Today insight; no new reading-page banner per lead | Several very low moods in a row show the owner-approved support message with help-line information. |
| TC-FR3-45-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record 5.6 mmol/L before breakfast, then switch units: it shows as 101 mg/dL. |
| TC-FR3-45-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Edit and delete readings: the list and chart update. |
| TC-FR3-45-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | A reading below the owner-approved low level shows the urgent warning wording. |
| TC-FR3-46-AS-01 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record a blood pressure of 185/125: the urgent-care wording is shown at once, for Free and Premium test members alike. |
| TC-FR3-46-AS-02 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | Record a normal reading: no warning is shown. |
| TC-FR3-46-AS-03 | New screen contract: p36-readings.spec.mjs (discovery blocked until duplicate title fixed) | The wording shown matches the frozen approved text exactly. |

## Two-round findings / unfinished

1. Food weekly-stat has locator retains outer ancestor; fix relative label locator.
2. Readings declares TC-FR3-41-AS-01 twice at root, so final discovery fails; combine step assertion with original activity test.
3. Readings history uses first table; name history Recent readings, distinct from chart-data tables.
4. Proposed accessible chart contract: each graph has image name `<type> trend` (Blood sugar for glucose), activity `Weekly activity`; chart-data tables named `<chart name> data`; activity figure contains the 150-minute guide. These are plain accessibility labels, no clinical verdicts.
5. UI suggestions, kosher/fasting criteria and dialog focus-trap coverage remain unfinished; existing backend coverage is not represented as new UI coverage. Free 14-day screen restriction is in readings; export half is existing privacy/export coverage.
6. No local run: shared local site claimed by another tool; standing orders require CI rather than waiting.

Loop: separate Food and Readings reviewer sessions, each two rounds; three must-fixes above remain for lead. Syntax and --names pass. Initial discovery passed (102 cases); final discovery is blocked by duplicate-title finding. No feature skip, clinical copy, Stage 2 edit or plugin edit.
