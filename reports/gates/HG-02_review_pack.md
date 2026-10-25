# Lifeline Daily — Stage 1 Clinical Sign-off (HG-02) — Review Pack

Prepared 25 October 2026 for the owner, acting as clinical reviewer. Estimated review time: 2 to 3 hours.

---

## 1. What you are signing

HG-02 is the clinical sign-off for Stage 1. By signing, you confirm, as clinical reviewer, that:

1. The **Daily Protein Target** calculator uses the right method, safety rules and wording for a general-information tool.
2. The **Diabetes Risk Check** reproduces the published QDiabetes-2018 method correctly and handles symptoms and emergencies safely.
3. The **wording** users see, on screen, in emails and on the disclaimer page, is clinically acceptable.
4. Both tools are **general health information and screening encouragement**, not medical devices. They do not diagnose or treat.
5. The **clinical decisions** in Part E are approved as written, or changed where you say so.

After you sign, the 20 Stage 1 items waiting for this gate can be closed. Then only the release sign-off (HG-03) stands between Stage 1 and the live website.

## 2. How to review

Open these staging pages on your phone and on a computer:

| Page | Address |
|---|---|
| Protein calculator landing page | https://stg.lifelinedaily.com/health-tools/protein/ |
| Diabetes Risk Check landing page | https://stg.lifelinedaily.com/health-tools/diabetes-risk-check/ |
| Health Tools hub | https://stg.lifelinedaily.com/health-tools/ |
| Medical Disclaimer and Urgent Care | https://stg.lifelinedaily.com/medical-disclaimer/ |
| Privacy Policy | https://stg.lifelinedaily.com/privacy-policy/ |

For each part below, run the test scenarios, read the wording, and tick the box. Note anything you want changed. At the end, answer the decisions in Part F and sign as described in section 9.

## 3. Part A — Daily Protein Target

### A1. What the tool is for

| Item | Current wording (from the approval record) |
|---|---|
| Intended use | An educational screening aid that estimates a daily protein target, in grams per day, grams per kg and per meal, for healthy adults from published population guidelines. It is not a prescription, a diagnosis or individual clinical nutrition advice. |
| Who can use it | Adults aged 18 to 99 with healthy kidney function. Not for children, people on dialysis, or people under medical nutrition therapy. |
| Main sources | Institute of Medicine 2005 (RDA 0.8 g/kg; pregnancy 1.1; breastfeeding 1.3). International Society of Sports Nutrition 2017 (1.4 to 2.0 g/kg). Academy of Nutrition and Dietetics with Dietitians of Canada and ACSM 2016 (1.2 to 2.0 g/kg). PROT-AGE 2013 and ESPEN 2014 (older adults, 1.0 to 1.2 g/kg and above). KDOQI 2020 (kidney disease). WHO 2004 and NICE PH46 (Asian BMI cut-offs). |

- [ ] Intended use and eligibility are acceptable.
- [ ] Sources are appropriate and current.

### A2. Safety scenarios to try

| # | Enter this | You should see |
|---|---|---|
| P1 | Kidney disease: **Yes** | **No number at all.** A message to speak to a kidney doctor or dietitian before changing protein intake. |
| P2 | Kidney disease: **Not sure** | A caution message to check with a doctor first. |
| P3 | Pregnant or breastfeeding | The pregnancy or breastfeeding target (1.1 or 1.3 g/kg) with its own note. |
| P4 | "Liver disease or recovering from serious illness, injury or surgery": **Yes** | A target based on the recovery band (1.2 to 1.5 g/kg), with a "Confirm this with your clinician" message above it. |
| P5 | BMI under 18.5 (for example 160 cm, 45 kg) | The standard result, with a message that it is a supportive floor and nothing asks them to eat less, plus a pointer to a GP or dietitian if eating is a worry. |
| P6 | Healthy adult, 40 years, 70 kg, moderately active | A target with a range and a per-meal split, plus the "not a diagnosis" statement. |

- [ ] All six scenarios behave as described.

### A3. Independent check of the calculations

A second protein calculator was written separately, from the design document only, without looking at the live code. It was run on 53 test cases.

| Result | Count |
|---|---|
| Agree with the live calculator | 51 |
| Differ | 2 |

The 2 differences are explained in Part E, decision **DEC-44**. They affect only vegetarians and vegans in the highest band, by 7 g a day or less.

One limit applies to this check. Part of the design document was copied from the live code, so this check catches coding mistakes, not a wrong guideline figure. That is why section A1 asks you to confirm the guideline figures themselves.

- [ ] I accept the independent check, subject to my DEC-44 decision.

### A4. Known limitations, shown to users in the method section

- These are population guideline figures, not individual requirements.
- The vegetarian and vegan multipliers (1.1 and 1.2) are an extrapolation from digestibility research, not a guideline figure.
- The tool does not account for kidney function tests, protein quality or energy balance, nor for conditions beyond the questions it asks.

- [ ] The limitations are acceptable and clearly shown.

## 4. Part B — Diabetes Risk Check

### B1. What the tool is for

| Item | Current wording (from the approval record) |
|---|---|
| Intended use | An educational screening aid that estimates a person's 10-year risk of developing type 2 diabetes from self-reported answers, without blood tests. It encourages a conversation with a clinician where appropriate. It does not diagnose diabetes or any other condition. |
| Who can use it | Adults aged 25 to 84 without a diabetes diagnosis, which is the age range the method was built on. |
| Method | QDiabetes-2018 Model A (Hippisley-Cox and Coupland, BMJ 2017). Secondary indicators: the Indian Diabetes Risk Score (Mohan 2005) and the waist-to-height ratio (Ashwell 2016). Symptom and emergency rules are based on Diabetes UK guidance. |

- [ ] Intended use, eligibility and method are acceptable.

### B2. Independent check of the calculations, completed 25 October 2026

The official QDiabetes R package, the published reference implementation, was run on 78 test cases. Its results were compared with the live calculator.

| Result | Value |
|---|---|
| Test cases compared | 78 |
| Agreement | 78 of 78 |
| Largest difference | 0.000000 percentage points |
| Range of risks tested | 0.25 % to 44.26 % |

The live calculator reproduces the published method exactly. The Indian Diabetes Risk Score and the waist-to-height ratio were also checked by separate independent calculations, with 8 and 4 cases respectively.

- [ ] I accept the independent check.

### B3. Safety scenarios to try

| # | Enter this | You should see |
|---|---|---|
| D1 | Any **emergency** symptom, for example vomiting, drowsiness or confusion | **No percentage.** An urgent-care message at the top, and the email shows no number. |
| D2 | **Classic symptoms**, for example excessive thirst, passing a lot of urine, or unexplained weight loss | A same-day-assessment banner above the result. |
| D3 | A low-risk adult, 30 years, healthy weight, no family history | A low band with reassurance and general advice. |
| D4 | A higher-risk adult, 60 years, BMI 32, family history, high blood pressure | A High or Very high band, with advice to arrange a blood test (fasting glucose or HbA1c) with a doctor soon, and the secondary indicators shown. |
| D5 | Waist exactly half of height, for example 85 cm waist and 170 cm height | Waist-to-height ratio shown as **raised** (0.5 counts as raised). |

- [ ] All five scenarios behave as described.

### B4. Known limitations, shown to users in the method section

- QDiabetes was built and tested on UK patients. Its accuracy outside the UK is not established, and the tool says so.
- A UK deprivation score is fixed at a neutral average. This can under- or overstate an individual's risk.
- All answers are self-reported, and no blood tests are used.

- [ ] The limitations are acceptable and clearly shown.

## 5. Part C — Wording

The tools' user-facing wording is held in one frozen list of **112 entries**: 54 for the diabetes tool, 40 for the protein tool, and 18 for the result emails. Any change after your sign-off needs a new approval.

Read these on staging, and in the emails if you send yourself a test result:

- [ ] Kidney-disease messages (protein).
- [ ] Pregnancy and breastfeeding message (protein).
- [ ] Emergency and symptom messages (diabetes).
- [ ] "Not a diagnosis" statement on every result.
- [ ] Result email wording for both tools, including the emergency email.
- [ ] Medical Disclaimer and Urgent Care page.
- [ ] Consent checkbox wording for result emails.

**A change is needed for the global audience.** The urgent-care wording is written for the UK, Sri Lanka and India:

| Where | Current wording |
|---|---|
| Diabetes tool, unwell message | "...call NHS 111, or 999 in an emergency." |
| Disclaimer page | "United Kingdom: call 999 in an emergency, or NHS 111 for urgent advice. Sri Lanka: call 1990 (Suwa Seriya ambulance). India: call 112. Elsewhere, call your local emergency number." |

Lifeline Daily now serves a global English-speaking audience, so decision **C1** in Part F proposes neutral wording.

## 6. Part D — Medical-device position

Both approval records state the same working position. The tools give general wellbeing information from published methods. They do not diagnose, treat or recommend treatment. Every result and email says "not a diagnosis" and points to a clinician. So they are run as educational and screening information services, not as medical devices.

- [ ] I confirm this position.

## 7. Part E — Clinical decisions to approve

These are the decisions in the Decision Register that need a clinical view.

| Decision | What it says | Your choice |
|---|---|---|
| DEC-04 | Diabetes risk bands: Low below 5.6 %, Moderate 5.6 to 9.9 %, High 10 to 19.9 %, Very high 20 % and over. 5.6 % matches the NHS Health Check blood-test trigger. The band uses the unrounded value, so a displayed "5.6 %" can show as Low if the true value is 5.55 to 5.599. | [ ] Approve as is  [ ] Classify on the rounded value |
| DEC-05 | Indian Diabetes Risk Score tiers: low below 30, moderate 30 to 59, high 60 and over. Activity uses 4 levels (0, 10, 20, 30) instead of the published 3 (0, 20, 30). | [ ] Approve  [ ] Change |
| DEC-06 | Waist-to-height ratio: 0.5 and over is raised, 0.6 and over is high. | [ ] Approve  [ ] Change |
| DEC-07 | UK deprivation score fixed at a neutral 0, and disclosed to users. | [ ] Approve  [ ] Change |
| DEC-09 | In an emergency result, the email is still allowed but never contains a percentage. | [ ] Approve  [ ] Block emails in emergencies |
| DEC-29 | Age is entered in 5-year bands, and the midpoint is used, as the live tool does. | [ ] Approve  [ ] Change |
| DEC-37 | Urgent-care numbers for non-UK visitors. Replaced by decision C1 below. | See C1 |
| DEC-44 | Protein, vegetarian and vegan users in the top band: today the multiplier is applied, each end of the range is capped at 2.0 g/kg, then the midpoint is used, giving 1.96 g/kg (147 g for 75 kg). The alternative applies the multiplier and then caps the midpoint, giving 2.0 g/kg (150 g). | [ ] Keep current (1.96)  [ ] Change to 2.0 |
| DEC-45 | Protein headline target is the midpoint of the winning band, with the range shown. | [ ] Approve  [ ] Change |
| DEC-85 | Landing-page order: the tool comes before the Limitations, How it works and Privacy sections. | [ ] Approve  [ ] Change |

**Technical decisions.** These 43 rows record how the system was built and tested and carry no clinical content: DEC-02, DEC-03, DEC-12, DEC-25, DEC-33 and DEC-47 to DEC-84. Examples are where code lives, how tests run and which files a change may touch. You can approve them as a block.

- [ ] I approve the technical decisions as a block.

## 8. Part F — Decisions needed from you

| # | Decision | Suggested answer |
|---|---|---|
| C1 | Urgent-care wording for a global audience | "If you feel very unwell, get medical help today. **In an emergency, call your local emergency number now**, for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, or 000 in Australia. In the UK you can also call NHS 111 for urgent advice." The same wording goes in the diabetes tool, the emergency emails and the disclaimer page. This is a wording change only; the calculation is not touched. It is made and re-checked before you sign. |
| C2 | Limitation wording in the diabetes method section | "QDiabetes was developed using UK health records. Its accuracy in other countries has not been established." This replaces the mention of Sri Lanka and India. |

## 9. How to sign

Reply in the chat with one of these:

- **"HG-02 approved"**, with any choices from Parts E and F that differ from the suggestions. I then make any wording changes, re-run the safety checks, and fill in the three approval records with your name and today's date.
- **"HG-02 approved with changes"**, followed by your list of changes. I make them and send the pack back for a final yes.
- **"HG-02 not approved"**, with your reasons.

The approval records you sign are the protein record, the diabetes record, the wording record and the HG-02 gate record.

| Name | Role | Date | Decision |
|---|---|---|---|
| | Clinical reviewer | | |
