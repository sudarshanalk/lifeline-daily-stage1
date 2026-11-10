# Healthy Weight programme engine (P3-35, package P1)

Pure PHP, no WordPress and no database. Built from the signed rule catalogue v1.3 section 9: the rule draft
(`P3-02-Healthy-Weight-Programme-Rules-Draft.md`), the templates pack (`P3-02-Healthy-Weight-Templates.md`, which wins
where the two differ), R-HW-BLOCK-03 (`P3-02-R-HW-BLOCK-03-Festival-Timing-Change-Draft.md`) and the P3-35, P3-55 and
P3-56 method sheets. The wording is `LLD_Members_Rule_Catalogue::HW_TEMPLATES`, hashed in
`approvals/stage3/decision-rules.json`. Fixtures: `fixtures/healthy-weight-programme/`. Tests: `tests/stage3/hw-engine/`.

| Class | What it does |
|---|---|
| `LLD_Members_HW_Where` | The Healthy Weight Check engine ported line by line (`assess`, `range`, `waist_band`, plus `validate` for the Check's form checks and units), the D3-54 `background_group`, and `where_now` for a member |
| `LLD_Members_HW_Rules` | The other R-HW rules: each function takes plain arrays and returns plain arrays naming the rule that decided (shapes in the docblocks) |
| `LLD_Members_HW_Festival` | R-HW-BLOCK-02 `offer` and the R-HW-BLOCK-03 `schedule` day map |

## Readings chosen where the signed text is silent or ambiguous

Each is the reading closest to the signed text. A change needs a new decision.

**Pace and safety**
1. A skipped answer never triggers the gentle or the faster pace; faster also needs age and weight known (to check 65+ and the cap).
2. At most two gentle reasons are shown, in the rule's order (templates pack).
3. Pregnancy "not sure" stops Healthy Weight, as the Check does.

**Daily steps**
4. Barrier first moves the activity and shifts the others down one day (order kept). If the first barrier-tagged activity in weeks 1 to 2 were in week 2, it would trade places with week 1's day 3; no approved tag set does this today.
5. Barrier "no time" has a step tag (`short-activity`) but no focus block in R-HW-BLOCK-01, so step 3 is skipped for it.
6. A slot left out by R-HW-STEP-02 takes the next unused action-library item that shares a tag with the week's theme: the tags given by the caller, or else the rule-bearing habit tags of that week's activities, in order. Drink-swap and alcohol items are never picked for members those rules exclude.
7. Where an added step goes: the signed rule's slot (one left out by R-HW-STEP-02, otherwise day 6), then day 4, then day 2. It never goes over another rule's step. Day 5 is kept for the alcohol step.
8. "Eats out less than once a week": the eating-out plan HW-W3-A7 moves to week 8 (placed as in 7). Week 8's own social meal plan stays.
9. Heavy daily drinkers ("most days", 5 or more): the track's alcohol item HW2-W4-A4 becomes HW-AL-03 (content question H5 = A). Week 8 then has HW-AL-03 on day 4 and on day 5.
10. Versions from week 2 with no history: standard. A "time", "work" or "illness" check-in answer sets every step of next week to easier. In weeks 1 to 4 under the 10-minute cap, a walking step is at most standard, because the stretch walk is 15 minutes.
11. Exercise minutes: "some" starts at 15 and adds 5 a week up to 30. After the 10-minute cap of weeks 1 to 4, minutes add 5 a week from 10 (the gentler reading). "Very active" keeps the member's own exercise (no minutes).
12. Strength sessions (active: 1 a week from week 3; very active: 2 a week from week 1) are returned as the week's exercise prescription, not as a daily step, so every day keeps one step. The ids come from strength-tagged approved items.
13. Enjoyment uses an activity's `variants` map where an approved version exists. None exists yet.

**Notes**
14. The default order is a queue. A note brought forward does not leave the queue; when its slot comes round within 30 days it is skipped. This gives member B's worked example in the approved notes file.
15. A candidate note shown in the last 30 days does not qualify, so the next rule may bring its note forward that day.
16. R-HW-NOTE-02 brings both notes forward ("Stress and eating" day 2, "Sleep and appetite" day 4) when sleep is under 7 hours **or** stress is 7 or more (FR3-109 and the approved notes table).
17. Low mood: only during the 7 days after R-SAFE-04 are the other note rules paused. A mood of 2 or less without R-SAFE-04 brings the kindness note but pauses nothing. In those 7 days every R-HW rule whose catalogue `low_mood` flag is false pauses (package P2 applies it): no quick questions, "Add a drink", reason line, block suggestion, plateau or celebration card, drinks line or R-HW-STEP-02 notice, and the steps are the track's own order in the standard version (no version is recorded). Safety, the calorie target and R-HW-NOTE-01 stay, and so do the plan's stored numbers (where you are now, the pace and the milestones).
18. R-HW-WEEK-01's notes (family, cravings, stress) are held as `pending` and brought forward one a day, after the R-HW-NOTE rules.
19. The weight rise is taken to 0.1 kg, so 80.0 to 80.3 counts as 0.3.

**Reminders and the drinks shortcut**
20. An "evening snacker" has a usual snacking time at or after 18:00. "after 9 pm" is read as 21:00, so the reminder is at 20:30.
21. A shift worker with no wake-up time gets no reminders and `ask_wake_up`. With one, the step reminder is 30 minutes after waking (fixture HWR-01b). No reminder falls between bedtime and waking; without a bedtime given, bedtime is 16 hours after waking (the D3-60 reading).
22. The "Add a drink" shortcut shows from 18:00 to midnight. For shift and night workers with a wake-up time it shows from 10 hours after they wake (D3-60, catalogue v1.3.1) until their next wake-up, when their new day starts; without a wake-up time, from 18:00.

**Support cards**
23. At the first two check-ins the card shows only when the caller says the answers are `unchanged` since joining.

**Focus blocks**
24. The festival window is 14 to 42 days inclusive (2 to 6 weeks; HWB-03c, at exactly 14, is offered).
25. "{n} weeks" in the festival reason is the nearest whole week.
26. When festival windows overlap, the earliest festival is offered.
27. "Irregular" hours do not count as shift work (the rule says shifts or nights).
28. When every match is already finished, nothing is suggested and the member chooses. A reached goal returns Keep It Off. Weeks 1 to 8 get no block.

**R-HW-BLOCK-03**
29. The member's weeks end on the check-in weekday. The block is shortened when T falls on or before the check-in day.
30. In a shortened block, `day` counts 1 to 13 across weeks 1 and 2.
31. -ALT items follow FB08-Q1: W1-A2-ALT for members who don't fast; W3-A2 to -A4-ALT for members who don't fast and for those with type 2 diabetes.
32. A repeat week's version is marked `R-HW-STEP-03`, because the caller has the completion figures.
33. A calendar move applies from the day after it is recorded (HWB-03e: recorded Sun 7 Feb, Mon 8 Feb is the free day). E moves with S unless a new E is given.
34. If the current block runs out before T, the caller's `next_block` starts.

**Plateau, questions, food, milestones**
35. The plateau is the signed engine's R-ADJ-02 (the 21 days before today), called directly.
36. Quick questions with no signed rule are never asked: "stress eating" and "what they have tried before". Each other question names the rule that reads it.
37. Allergies are also matched through food-rule tags (shellfish, egg, milk or dairy, fish).
38. With more than one preference, quick comes first, then low cost, then cuisine. Fewer than 5 items, counted after any calories-left filter, gives the empty state.
39. The halal vanilla and soy-sauce line (FT-Q3 = A) is returned once per list, to Jain members too, after the approved Jain line (Food-Rule-Tags-Jain.md, J1 to J5 = A: vegetarian, no egg, root vegetables, mushrooms, honey or alcohol).
40. Milestones that land on the same weight are not merged. kg is shown to 0.1 ("60.0 kg").
41. When several milestones are passed at once, the furthest one is celebrated and the others are marked passed.
42. "{weeks} weeks to go" is the weeks to the goal date, rounded up. The event weight never goes below the goal.

**Tiers**
43. `programme_rules()['tier']` follows question 12, and Owner Question 163 (10 Nov 2026) for the six it did not name: R-HW-STEP-06 premium (the R-HW-SAFE-04 limits stay free), R-HW-ALC-02 free, R-HW-FOOD-03 premium (its safety lines free), R-HW-REM-01 free, R-HW-QOD-01 free, and R-HW-WEEK-01 `split` with `tiers` {question: free, next_week: premium, illness_wording: free}. The caller applies the split.

**Requirement corrections followed (signed method over requirement text)**
44. FR3-101 AS-01: the healthy-range milestone is 66.2 kg, not 66.
45. FR3-100 AS-01: the pace is 0.25 for confidence 3, not "0.25 or 0.5".
46. FR3-109 and FR3-110: the R-HW-BLOCK-01 order applies (plateau, festival, barrier, stress 7+, sleep under 7, alcohol, eating out, shifts, getting stronger).

**Wiring (package P2, after the independent review of 10 Nov 2026)**
47. R-HW-SAFE-01 runs on the member's situations every time the programme is read, not only when joining: pregnancy, or an eating disorder (the Stage 2 flag or the Check's own question) reported later, stops it. Every view then returns the SCR-26 stop with the safety matrix's note and no number at all; nightly runs nothing, and the Stage 2 day items are left exactly as they are.
48. The Stage 2 profile holds only yes or no for an eating disorder, so "where you are now" asks the Healthy Weight Check's own question (copy `q.eat`, `options.eat`) once, as a sealed programme answer: "yes" stops, "prefer not to say" is the engine's `pnts`: paces 0.25 and 0.5 (by a date too, refused with the cap 0.5 and the safe date at 0.5), and the member sees the calorie target and the other numbers (Owner Question 164, 10 Nov 2026). Numbers are hidden only for "yes" and the Stage 2 flag.
49. Writes happen only for the member's own today: a view of another date is read-only, and nightly() for another date returns `not_today`.
50. A focus block finishes on the day after its 28 days (plus the days a festival block paused it); the festival block finishes after its week 4. finished_at is written on the member's own today.
51. The alcohol part of R-HW-STEP-02 is part of the Free food-rules filter: a member who never drinks, or whose food rules exclude alcohol (halal, Jain), gets no alcohol step at any tier; a Free member who drinks keeps the track's own item.
52. The plateau card is Premium; whenever the check does not run, no plateau is kept.
