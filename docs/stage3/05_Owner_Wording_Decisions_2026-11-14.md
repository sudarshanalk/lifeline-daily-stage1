# Owner wording decisions — 14 November 2026

Owner's answer, in chat (14 November 2026):

> E1 A, E2 A, E3 A, E4 A, E5 A, E6 A, E7 title A, E7 badge A, E8 A. Log: all suggestions yes; all other lines confirmed. D1 A, D2a A, D2b A (Easy minutes: count), D3 A.

## D1

No weigh-in reminders in pregnancy or breastfeeding, as well as for the eating-disorder flag. The Account tab already hid them through `hide_weight` (pregnancy's `share:weight_tick`). `gate_type()` now uses `weigh_in_hidden()`, and the pregnancy reminders row also lists `reminder:weigh_in`, so the programming interface matches.

## D2a

Glucose symptom labels stay the matrix words: Vomiting, Drowsiness, Deep breathing, Stomach pain. The question above them is "Do you have any of these right now?" (for Antigravity's Log a reading page).

## D2b

Effort labels: "Easy: you can sing", "Moderate: you can talk but not sing", "Hard: only a few words at a time", stored as light, moderate, vigorous. Easy minutes count towards the 150 minutes like moderate ones; vigorous still counts double (for Antigravity with FR3-41).

## D3

No banner after a single mood reading. R-SAFE-04 shows through the insight card, text unchanged.

## E8

Today's diary link label is "Open the diary". The signed T-STEP-05 catalogue string still ends with `[Log something]`; changing that hash needs a lead update to `approvals/stage3/decision-rules.json`, so the catalogue string is left for the lead.
