# Lifeline Daily — Stage 1 Test and Acceptance Criteria v1.2

Supersedes TP v1.1. One test-case page per requirement (TC-<REQ-ID>), each assertion with its own ID, locator or method, and fixture. A requirement is `accepted` when every AS/NEG assertion passes, every listed mutation has been shown to turn a test red, the evidence bundle validates, the reviewer agent returns PASS, and (hybrid items only) the human-gate artefact exists.

Test families and tooling (fixed): **unit** = Vitest over the extracted engine modules; **php** = PHPUnit + Guzzle against the endpoints with Mailpit; **e2e** = Playwright (Chromium, WebKit, Firefox, Pixel 5, iPhone 12); **crawl** = custom crawler (`tools/crawl.js`) producing JSON; **a11y** = axe-core via Playwright + Lighthouse; **perf** = Lighthouse CI; **sec** = gitleaks, OWASP ZAP baseline, WPScan, header checks; **oracle** = ORC containers; **artefact** = JSON Schema validation (`make validate`); **wpcli** = WP-CLI assertions.

Locators: tools expose stable `data-test` attributes added in Stage 1 (allowed shell change): `data-test="banners|headline|metrics|interp|advice|methodology|disclaimer|email-card|result|error|loading|unavailable"`. Existing IDs referenced where already present (`#headlineZone`, `#riskNum`, `#results`, `#emailOk`, `#emailErr`, `#consent`, `#company`).

---

## 1. Fixture catalogue

Fixture files live in `fixtures/<tool>/<group>.json` and validate against SCH-05. Revised 22 Oct 2026: site fixtures use live category slugs (DEC-13); ORC-01 contract per DEC-33. `expected` is empty in this pack; the baseline runner (P-A0) fills `oracle: characterisation` values, the oracle runners fill `oracle: clinical` values. Input vectors are fixed here.

### 1.1 Protein fixtures (FX-PR-*)

Common defaults unless stated: metric, age 40, male, 175 cm, 75 kg, ethnicity `White`, activity `moderate`, resistance `0`, goal `maintain`, diet `omnivore`, meals 3, kidney `no`, liver `no`, preg `no`.

| ID | Variation from default | Purpose |
|---|---|---|
| FX-PR-NRM-01 | defaults | baseline lightMod band 1.0–1.4 |
| FX-PR-NRM-02 | activity sedentary | floor 0.8–1.0 |
| FX-PR-NRM-03 | activity light | lightMod |
| FX-PR-NRM-04 | activity vigorous | 1.2–1.6, training flag true |
| FX-PR-NRM-05 | resistance 1-2 | resLow |
| FX-PR-NRM-06 | resistance 3-4 | resMid |
| FX-PR-NRM-07 | resistance 5+ | resHigh |
| FX-PR-NRM-08 | goal build | buildMuscle 1.6–2.0 |
| FX-PR-NRM-09 | goal fatloss, resistance 0, activity moderate | fatLossOnly 1.2–1.5 |
| FX-PR-NRM-10 | goal fatloss, resistance 3-4 | fatLossTrain 1.6–2.0 |
| FX-PR-NRM-11 | goal ageing, age 50 | ageing alone adds no band |
| FX-PR-NRM-12 | diet vegetarian | ×1.1 |
| FX-PR-NRM-13 | diet vegan | ×1.2 |
| FX-PR-NRM-14 | female, preg no | sex has no band effect |
| FX-PR-AGE-01 | age 18 | lower boundary valid |
| FX-PR-AGE-02 | age 64, sedentary | no older band |
| FX-PR-AGE-03 | age 65, sedentary | older 1.0–1.2 |
| FX-PR-AGE-04 | age 65, resistance 3-4 | olderActive 1.2–1.5 vs resMid |
| FX-PR-AGE-05 | age 99 | upper boundary valid |
| FX-PR-BMI-01 | 175 cm, 91.5 kg (BMI 29.9), White | actual weight basis |
| FX-PR-BMI-02 | 175 cm, 92.0 kg (BMI 30.04), White | adjusted basis: 24.9×1.75²=76.3 + 0.4×(92−76.3) |
| FX-PR-BMI-03 | 175 cm, 92.0 kg, ethnicity Indian | adjusted with 22.9 bound |
| FX-PR-BMI-04 | 160 cm, 77 kg (BMI 30.1), Indian | second adjusted case |
| FX-PR-CAP-01 | vegan, resistance 5+, goal build | 2.0 cap after ×1.2 |
| FX-PR-CAP-02 | vegetarian, resistance 3-4 | hi 2.0×1.1 → capped |
| FX-PR-CAP-03 | omnivore, resistance 5+, goal build | hi exactly 2.0, not capped flag |
| FX-PR-UNIT-01 | imperial twin of NRM-01 (5 ft 8.9 in, 165.3 lb) | conversion equality |
| FX-PR-UNIT-02 | imperial twin of BMI-02 (st/lb) | conversion equality |
| FX-PR-UNIT-03 | height in metres 1.75 | unit m |
| FX-PR-MEAL-01 | meals 5, sedentary 60 kg female | perMeal clamps to 20 |
| FX-PR-MEAL-02 | meals 2, resistance 5+, 95 kg | perMeal clamps to 40 |
| FX-PR-MEAL-03 | meals 4, defaults | unclamped |
| FX-PR-REN-01 | kidney yes | suppression, defaults |
| FX-PR-REN-02 | kidney yes, vegan, resistance 5+, goal build | suppression beats athlete bands |
| FX-PR-REN-03 | kidney yes, female pregnant | suppression beats pregnancy |
| FX-PR-REN-04 | kidney yes, liver yes, age 70 | suppression beats clinical |
| FX-PR-UNS-01 | kidney unsure | caution + provisional result |
| FX-PR-PRG-01 | female pregnant, resistance 5+ | 1.1 g/kg, no stacking |
| FX-PR-PRG-02 | female pregnant, goal build, vegan | 1.1 g/kg, no diet multiplier (as deployed; ORC-02 to confirm) |
| FX-PR-PRG-03 | female breastfeeding, activity vigorous | 1.3 g/kg |
| FX-PR-PRG-04 | female breastfeeding, goal fatloss, resistance 3-4 | 1.3 g/kg |
| FX-PR-LIV-01 | liver yes, sedentary | recovery band 1.2–1.5 + clinical banner |
| FX-PR-LIV-02 | goal recovery, liver no | recovery band |
| FX-PR-UWT-01 | 175 cm, 56 kg (BMI 18.3) | underweight framing |
| FX-PR-UWT-02 | 175 cm, 52 kg, goal fatloss | underweight + fatloss, no deficit wording |
| FX-PR-INV-01 | age 17 | ineligible |
| FX-PR-INV-02 | age 100 | out of range |
| FX-PR-INV-03 | height 139 cm | out of range |
| FX-PR-INV-04 | height 211 cm | out of range |
| FX-PR-INV-05 | weight below minimum (per deployed validate) | out of range |
| FX-PR-INV-06 | weight above maximum | out of range |
| FX-PR-INV-07 | sex missing | required |
| FX-PR-INV-08 | kidney missing | required |

### 1.2 Diabetes fixtures (FX-DM-*)

Common defaults: metric, sex Male, ageBand 47, 170 cm, 75 kg (BMI 26.0), ethnicity Indian, smoke Non, family none, htn 0, cvd 0, gdm 0, pcos 0, optional all 0, activity moderate, waist absent, hasSym no, emergency unchecked.

| ID | Variation | Purpose |
|---|---|---|
| FX-DM-QD-01…24 | ageBand ∈ {27,32,…,82} × sex ∈ {Male, Female} | age/sex grid, Indian |
| FX-DM-QD-25…48 | same grid, ethnicity WhiteNA | second ethnicity |
| FX-DM-QD-49…55 | ethnicity ∈ {Pakistani, Bangladeshi, OtherAsian, BlackCaribbean, BlackAfrican, Chinese, Other} | remaining coefficients |
| FX-DM-QD-56…59 | smoke ∈ {Ex, Light, Moderate, Heavy} | smoking coefficients |
| FX-DM-QD-60…68 | one binary on at a time: htn, cvd, gdm (Female), pcos (Female), learn, psy, ster, stat, apsy | binary coefficients |
| FX-DM-QD-69…70 | family one (mother) / both (mother+father) | `fhdm` is binary in QDiabetes, so both give identical % (DEC-33); they differ only in IDRS |
| FX-DM-QD-71…74 | BMI 20.0, 30.0, 39.9, 40.0 (weights at 170 cm: 57.8, 86.7, 115.3, 115.6 kg) | BMI polynomial |
| FX-DM-QD-CLAMP-01…04 | BMI 19.0 (54.9 kg) and 41.0 (118.5 kg), each sex | equals output at 20 / 40 |
| FX-DM-BAND-01…12 | engineered by the oracle runner: profile A Male 47 Indian (all three thresholds, reachable range ≈ 2.1–44 %), profile B Female 57 WhiteNA (5.6 and 10 only; max ≈ 13.6 % because BMI clamps at 40), profile C Female 62 Bangladeshi (20 %). Search weight in 0.1 kg steps for the pair straddling each threshold; both sides stored with the ORC-01 value. If a threshold is unreachable for a profile the runner records it and substitutes a reachable profile | band edges (6 thresholds × 2 sides) |
| FX-DM-IDRS-01…06 | IDRS 20 (age 37, waist 85, vigorous, none), 30 (age 37, waist 85, moderate, none), 50 (age 52, waist 85, mild, none), 60 (age 52, waist 95, mild, none), 0 (age 27, waist 85, vigorous, none), 70 (age 52, waist 100, mild, none). Corrected 22 Oct 2026 (review F04): the earlier vectors scored 0/30/60/70 and could not detect MUT-08 | tiers low/moderate/moderate/high/low/high |
| FX-DM-WHTR-01…04 | height 170, waist 83 (0.488), 85 (0.500), 101 (0.594), 102 (0.600) | WHtR cut-offs |
| FX-DM-CONC-01 | ageBand 27, BMI 22, WhiteNA (low %) + waist 102 + activity none + family both (IDRS high) | concordance note |
| FX-DM-CONC-02 | ageBand 27, BMI 22, waist 80, vigorous | no note |
| FX-DM-NOWAIST-01 | defaults | needWaist text |
| FX-DM-EMG-01 | defaults + hasSym yes + emergency checked | suppression with low risk |
| FX-DM-EMG-02 | ageBand 72, BMI 35, Bangladeshi, family both, emergency + thirst | suppression with high risk |
| FX-DM-SYM-01 | hasSym yes, thirst | classic banner |
| FX-DM-SYM-02 | hasSym yes, fatigue only | no banner |
| FX-DM-SYM-03 | hasSym yes, polyuria + wtloss | classic banner |
| FX-DM-INV-01…05 | sex missing; height 139; weight 181; ethnicity missing; ageBand missing | validation |

### 1.3 Site fixtures (FX-WP-*) seeded into staging by `make seed`

| ID | Content | Expected behaviour |
|---|---|---|
| FX-WP-URLS | `fixtures/site/url-sample.json`, 30 URLs (SRS §13) | regression sample |
| FX-WP-POST-01 | published post, category `nutrition-for-healthy-living` | protein CTA |
| FX-WP-POST-02 | published post, category `health-conditions`, tag `diabetes` | diabetes CTA (tag rule) |
| FX-WP-POST-03 | published post, category `sleep-and-recovery` | no CTA |
| FX-WP-POST-04 | `food-nutrition` post, `lld_tool_override=none` | no CTA |
| FX-WP-POST-05 | draft `nutrition-for-healthy-living` post | not in link map, not in Related reading |
| FX-WP-POST-06 | published, `lld_next_review_date` = yesterday | appears in review-report |
| FX-WP-POST-07 | `fitness` post, override `diabetes` | diabetes CTA (override) |
| FX-WP-POST-08 | published `healthy-eating-for-longevity`, `lld_review_status=pending` | excluded from Related reading |
| FX-WP-POST-09 | published `fitness` post, tag `blood-sugar` | diabetes CTA (tag beats category) |
| FX-WP-POST-10 | published `metabolic-health` post, no tags | diabetes CTA (category) |
| FX-WP-USERS | contributor, editor, admin accounts | role tests |
| FX-WP-TIDY | the six empty category slugs and their DEC-39…42 dispositions | FR1-23 |

### 1.4 Mail fixtures (FX-MAIL-*) — HTTP bodies for the endpoints

| ID | Body | Expected |
|---|---|---|
| FX-MAIL-01 | protein standard payload from NRM-01 result | 200, `protein_standard` template |
| FX-MAIL-02 | diabetes standard payload from QD-01 | 200, `diabetes_standard` |
| FX-MAIL-REN-01 | `suppressed:true`, numeric nulls | 200, `protein_renal`, no digits+g |
| FX-MAIL-REN-02 | `suppressed:true` **with forged** `targetG:150` | 200, `protein_renal`, no digits+g (server ignores) |
| FX-MAIL-EMG-01 | `emergency:true`, `riskPercent:3.2` | 200, `diabetes_emergency`, no `%` |
| FX-MAIL-SYM-01 | `symptomatic:true` | 200, `diabetes_symptomatic`, banner text before % |
| FX-MAIL-NEG-01 | consent false | 400, no mail |
| FX-MAIL-NEG-02 | email `not-an-email` | 400 |
| FX-MAIL-NEG-03 | company `"x"` | 200 `{status:"ok"}`, no mail, log outcome `honeypot` |
| FX-MAIL-NEG-04 | body 9 KB | 413 or 400 |
| FX-MAIL-NEG-05 | `Content-Type: text/plain` | 400 |
| FX-MAIL-NEG-06 | firstName `<img src=x onerror=alert(1)>` | 200, escaped in mail |
| FX-MAIL-NEG-07 | tool `"other"` | 400 |
| FX-MAIL-RATE-01 | 6 × FX-MAIL-01 within 10 min, same IP | 6th → 429 |

### 1.5 Shared data files

- `fixtures/required-phrases.json`: `notDiagnosis` ("not a diagnosis"), `urgentCare` ("same-day medical assessment" or approved equivalent), `methodology.engine` ("QDiabetes-2018 (Model A)"), `methodology.calibration` ("UK"), `methodology.townsend` ("deprivation score", the live wording, DEC-07), `methodology.type2` ("type 2"), `methodology.symptomDesign` ("symptom"), `protein.scope` ("18 and over").
- `fixtures/forbidden-terms.json`: for underweight state: `deficit`, `lose weight`, `cut calories`, `burn`; globally: `diagnos(e|is) you`, `waist-hip`.
- `fixtures/sentinel-values.json`: unique synthetic inputs used in privacy flows, e.g. firstName `Zq7Sentinel`, email `zq7sentinel@example.test`, weight 83.7, waist 97.3, so any leak is grep-able.

## 2. Independent oracles

| ID | Implementation | Scope | Tolerance | Independence argument |
|---|---|---|---|---|
| ORC-01 | R 4.x container, `QDiabetes` 1.0-2 from the CRAN Archive tarball (package archived on CRAN June 2026; SHA-256 pinned; AGPL-3), `QDR2018A(sex, age, bmi, ethn, smoke, tds=0, fhdm, htn, cvd, gdm, pcos, learn, psy, ster, stat, apsy)`; age = band midpoint; BMI = weight/height² then clamp 20–40; `fhdm = any(mother, father, sibling)` (DEC-33) | FX-DM-QD-*, BAND, CLAMP, CONC | abs(js − r) ≤ 0.05 pp on **unrounded** values | Independent implementation of the same published algorithm |
| ORC-02 | Python module written by sealed agent from reference document §2–§5 | all FX-PR-* except INV | ±1 g on targetG/lowG/highG/perMeal; exact on gPerKg (2 dp), suppressed, branch | Author never saw the JS; discrepancies are DEC rows |
| ORC-03 | Python, IDRS from Mohan et al. 2005 (age <35:0, 35–49:20, ≥50:30; waist M <90:0, 90–99:10, ≥100:20 / F <80:0, 80–89:10, ≥90:20; family none 0, one 10, both 20; tiers <30, 30–59, ≥60 per DEC-05) **plus the live 4-level activity mapping** vigorous 0 / moderate 10 / mild 20 / none 30, which is an adaptation of the published 3-level item and is ratified at HG-02 | FX-DM-IDRS-*, CONC | exact | Published points table; activity item is characterisation (DEC-05) |
| ORC-04 | WHtR = waist/height; <0.5 healthy, <0.6 raised, else high (DEC-06) | FX-DM-WHTR-* | exact | Published cut-offs |

Oracle results (SCH-06) are the only permitted source for `oracle: clinical` expected values. Disagreement handling follows DEC-08 precedence: ORC-01 disagreement on the deployed diabetes hash → staging withdrawal + DEC row; ORC-02/ORC-03 disagreement → live behaviour preserved, requirement `blocked`, DEC row for HG-02; oracle infrastructure failure → CI failure, no status change.

## 3. Mandatory mutations

Each mutation is a patch in `tests/mutations/MUT-NN.patch`, applied by `make mutate`, and must make at least one named test fail. Evidence records which tests went red.

| ID | Mutation | Must redden |
|---|---|---|
| MUT-01 | Delete `if (s.kidney === 'yes') return {suppressed:true…}` | TC-FR1-08-AS-01/02/03 |
| MUT-02 | `submit-protein.php`: always select `protein_standard` | TC-FR1-08-AS-04, TC-FR1-14-AS-07 |
| MUT-03 | Remove pregnancy early return | TC-FR1-09-AS-01 |
| MUT-04 | Change Female `s0` 0.98622727… → 0.980 | TC-FR1-10-AS-01 |
| MUT-05 | Remove `suppress=true` in emergency branch | TC-FR1-11-AS-01 |
| MUT-06 | `bandFor`: 5.6 → 6.0 | TC-FR1-12-AS-01, TC-FR1-12-AS-09 |
| MUT-07 | Cap 2.0 → 2.5 | TC-FR1-07-AS-01/02 (FX-PR-CAP) |
| MUT-08 | IDRS tier threshold 60 → 50 | TC-FR1-12-AS-02 (via FX-DM-IDRS-03, score 50) |
| MUT-09 | WHtR 0.5 → 0.55 | TC-FR1-12-AS-03 |
| MUT-10 | Alter `approvals/protein.json.version_hash` by one hex digit | TC-FR1-06-AS-02 |
| MUT-11 | Add prop `riskPercent` to `tool_complete` event | TC-FR1-16-AS-01 |
| MUT-12 | `postMessage(…, '*')` restored | TC-FR1-21-AS-02 |
| MUT-13 | `status.json` withdrawn but PHP ignores it | TC-FR1-15-AS-01 (503 check) |
| MUT-14 | Remove `role="alert"` from emergency banner | TC-NFR1-05-AS-03, TC-UI1-05-AS-02 |

---

## 4. Test-case pages — functional requirements

### TC-FR1-01 Existing implementation audit and characterisation baseline
Family: artefact, crawl, e2e, unit. Preconditions: staging clone reachable; read access to `public_html/tools`; empty `fixtures/**/expected`. Fixtures: all FX-PR, FX-DM, FX-WP-URLS.
Steps: run P-A0. Assertions:

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-01-AS-01 | `reports/as-is/inventory.json` validates SCH-02 set; 2 manifests; `version_hash` equals SHA-256 of served file fetched during the run | artefact + curl |
| TC-FR1-01-AS-02 | 100 % of fixtures have `expected` with `oracle: characterisation` and `source_version` = manifest hash; INV fixtures have `expected.error: true` | artefact |
| TC-FR1-01-AS-03 | 24 baseline PNGs exist (6 pages × 4 widths), each > 10 KB | file |
| TC-FR1-01-AS-04 | `crawl-before.json` has 30 entries, all status 200, canonical captured | crawl |
| TC-FR1-01-AS-05 | discrepancy ledger: 0 entries with `status: unclassified`; each entry has `evidence` file:line | artefact |
| TC-FR1-01-AS-06 | `privacy/data-flow.json` lists every key in Data Model §4.1/§4.2 | artefact |
NEG-01: run against a tool file with a changed hash → runner stops for that tool and records a blocker. Human gate: HG-01. Evidence: EV-FR1-01-*.

### TC-FR1-02 Preserve WordPress and Newspaper
Family: crawl, wpcli, artefact. Preconditions: `crawl-before.json`; staging after all Stage 1 merges. Fixtures: FX-WP-URLS.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-02-AS-01 | for all 30 URLs: status, final URL, canonical, title, meta description, robots, H1 identical; body word count within ±2 % | crawl diff |
| TC-FR1-02-AS-02 | sitemap URL set after ⊇ before | crawl |
| TC-FR1-02-AS-03 | `git diff --stat origin/baseline -- wp-content/themes/` empty | git |
| TC-FR1-02-AS-04 | 20 media URLs from before-crawl return 200 | crawl |
| TC-FR1-02-AS-05 | `wp role list --format=json` and `wp cap list` for 5 roles identical to baseline | wpcli |
NEG-01: inject a 404 into the sample → AS-01 fails.

### TC-FR1-03 Tools-first homepage
Family: e2e, crawl. Preconditions: FR1-04 accepted; homepage uses `[lld_tools_hub compact=1]`. Fixtures: FX-WP-POST-01 (recent post).

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-03-AS-01 | at 375×812, `[data-test=tools-row]` boundingBox.top ≤ 1218 px with scrollY 0 | e2e |
| TC-FR1-03-AS-02 | from `/`, each landing page reached in ≤ 2 clicks on 375 and 1440 (path recorded; menu open counts as 1, DEC-30) | e2e |
| TC-FR1-03-AS-03 | a post with date ≤ 30 days reached in ≤ 3 clicks | e2e |
| TC-FR1-03-AS-04 | search form present; `?s=protein` returns ≥ 1 result | e2e |
| TC-FR1-03-AS-05 | `h2` texts include "Tools" before "Learn" | e2e |
| TC-FR1-03-AS-06 | no console errors; 0 links ≥ 400 on `/` | e2e + crawl |
| TC-FR1-03-AS-07 | at 375 px `[data-test=tools-row]` is the first `section`/row after `header`; at 1440 px its previous sibling is the hero block (DEC-23) | e2e |
NEG-01: remove shortcode → AS-01/AS-05 fail.

### TC-FR1-04 Tools hub and landing pages
Family: e2e, artefact. Fixtures: `status.json` default; `content/tool-cards.json`.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-04-AS-01 | `/health-tools/` has exactly 2 `[data-tool-card][data-status=active]` | e2e |
| TC-FR1-04-AS-02 | each card CTA `href` → 200 and equals card `landing_url` | e2e |
| TC-FR1-04-AS-03 | `[data-status=label_only] a` count = 0 | e2e |
| TC-FR1-04-AS-04 | both landing pages: `h2` sequence = [Purpose, Who it is for, What you will enter, Limitations, How it works, Privacy, Try the tool, Related reading] | e2e |
| TC-FR1-04-AS-05 | How it works contains engine name and `deployed_version` prefix (8 hex) equal to manifest | e2e + artefact |
| TC-FR1-04-AS-06 | rendered card fields equal `tool-cards.json` | e2e |
NEG-01: add a third card with `status: active` to `status.json` (sandbox) → AS-01 fails.

### TC-FR1-05 Blog-to-tool and tool-to-blog linking
Family: wpcli, crawl, e2e. Fixtures: FX-WP-POST-01…08.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-05-AS-01 | `wp lld links generate` output validates SCH-05b; crawl of all published posts: CTA present ⇔ link-map decision ≠ none | wpcli + crawl |
| TC-FR1-05-AS-02 | POST-01 protein; POST-02 diabetes; POST-03 none; POST-04 none; POST-07 diabetes; POST-09 diabetes (tag beats category); POST-10 diabetes (category) | crawl |
| TC-FR1-05-AS-03 | CTA href = landing URL + `?utm_source=article&utm_content=<post_id>` → 200 | crawl |
| TC-FR1-05-AS-04 | Related reading on both landing pages excludes POST-05 (draft) and POST-08 (pending) | e2e |
| TC-FR1-05-AS-05 | post content hash with CTA element removed equals pre-change hash | crawl |
| TC-FR1-05-AS-06 | CTA at 320 px: `scrollWidth <= innerWidth` | e2e |
NEG-01: publish POST-05 without review status → not in Related reading until approved.

### TC-FR1-06 Clinical method provenance
Family: artefact, ci. Fixtures: `approvals/*.json`, manifests. Mutation: MUT-10.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-06-AS-01 | `approvals/protein.json`, `approvals/diabetes.json` validate SCH-03 | artefact |
| TC-FR1-06-AS-02 | `provenance-check` fails when staged file hash ≠ approved hash (MUT-10) | ci |
| TC-FR1-06-AS-03 | landing page methodology `deployed_version` = approval `version_hash` | e2e |
| TC-FR1-06-AS-04 | `engine-freeze` job passes on the current tree and fails on MUT-04/06/07 | ci |
Human gate: HG-02 (`clinical_signoff.decision: approved`).

### TC-FR1-07 Protein engine preservation and verification
Family: unit, e2e, oracle. Fixtures: FX-PR-NRM, AGE, BMI, CAP, UNIT, MEAL, INV. Mutation: MUT-07.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-07-AS-01 | for each fixture: engine output equals characterisation `expected` (targetG, lowG, highG, gPerKg, perMeal, basisKg, capped, sources) | unit |
| TC-FR1-07-AS-02 | for each fixture: engine output within ORC-02 tolerance; any disagreement → blocker + DEC row | oracle |
| TC-FR1-07-AS-03 | FX-PR-INV-01…08 → `#err` visible with specific message; `#results` hidden | e2e |
| TC-FR1-07-AS-04 | UNIT-01 = NRM-01, UNIT-02 = BMI-02, UNIT-03 = NRM-01 on all numeric outputs | unit |
| TC-FR1-07-AS-05 | frozen object hashes equal manifest `engine_hashes` | ci |
| TC-FR1-07-AS-06 | BMI-01 basisKg = 91.5; BMI-02 basisKg = 76.3 + 0.4×15.7 ± 0.1; BMI-03 uses 22.9 bound | unit |
| TC-FR1-07-AS-07 | MEAL-01 perMeal = 20; MEAL-02 perMeal = 40 | unit |
| TC-FR1-07-AS-08 | every `BANDS` row (lo, hi, src) matches an entry in `oracles/orc-02-protein-ref/primary-sources.json` transcribed from reference §2 (IOM 0.8; WHO 0.83; PROT-AGE 1.0–1.2; ESPEN 1.0–1.2 / 1.2–1.5; ISSN 1.4–2.0; ACSM 1.2–2.0; pregnancy 1.1; lactation 1.3); any row whose figures are not derivable from a listed source is reported for HG-02 (DEC-03) | static + artefact |
Human gate: HG-02 signs ORC-02 report.

### TC-FR1-08 Protein renal suppression
Family: unit, e2e, php, network. Fixtures: FX-PR-REN-01…04, FX-PR-UNS-01, FX-MAIL-REN-01/02, sentinel values. Mutations: MUT-01, MUT-02.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-08-AS-01 | REN-01…04 → `suppressed === true`, `reason: 'renal'`, no `targetG/lowG/highG/gPerKg/perMeal` keys | unit |
| TC-FR1-08-AS-02 | e2e REN-01…04: `[data-test=result]` innerText matches no `/\d+(\.\d+)?\s?(g\b|gram|g\/kg)/i`; `renalStop.title` text present | e2e |
| TC-FR1-08-AS-03 | intercepted POST body: `suppressed:true`, numeric keys all null, `sources: []` | e2e route |
| TC-FR1-08-AS-04 | FX-MAIL-REN-01 and REN-02 → Mailpit message uses renal template; subject+body match no digits+g regex | php |
| TC-FR1-08-AS-05 | during REN-01 flow, all requests except `submit-protein.php` and same-origin assets contain no sentinel numeric values | e2e network |
| TC-FR1-08-AS-06 | UNS-01: `renalUnsure` banner `[role=alert]` visible, DOM index < result index; result numeric present | e2e |
| TC-FR1-08-AS-07 | log lines for the run contain no numeric result and no email | php log scan |
Human gate: HG-02 (copy hashes of `renalStop`, `renalUnsure`).

### TC-FR1-09 Protein life-stage and safety branches
Family: unit, e2e, oracle. Fixtures: FX-PR-PRG-01…04, LIV-01/02, UWT-01/02. Mutation: MUT-03.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-09-AS-01 | PRG-01/02 gPerKg = 1.1; PRG-03/04 = 1.3; `branch: 'pregnancy'`; `dietMult: 1.0`; `capped: false`; sources length 1 | unit |
| TC-FR1-09-AS-02 | LIV-01/02: sources include "ESPEN"; e2e `clinical` banner visible | unit + e2e |
| TC-FR1-09-AS-03 | UWT-01/02: `underweight` banner visible; result innerText contains none of forbidden-terms.underweight | e2e |
| TC-FR1-09-AS-04 | all PRG/LIV/UWT within ORC-02 tolerance | oracle |
| TC-FR1-09-AS-05 | PRG fixtures: SHA-256 of the extracted `pregnancy` banner text equals the approved hash in `approvals/protein.json.copy_hashes.pregnancy` | e2e |
Human gate: HG-02 (copy).

### TC-FR1-10 Diabetes engine fidelity verification
Family: oracle, unit, ci. Fixtures: FX-DM-QD-01…74, CLAMP-01…04. Mutation: MUT-04.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-10-AS-01 | for every QD fixture: abs(js.pct − orc01.pct) ≤ 0.05 on unrounded values (DEC-02); displayed 1-dp value equals round(js.pct, 1) | oracle |
| TC-FR1-10-AS-02 | CLAMP-01/02 outputs equal QD at BMI 20 for same sex; CLAMP-03/04 equal BMI 40 | unit |
| TC-FR1-10-AS-03 | methodology panel contains attribution "QDiabetes-2018" and licence statement from approval record | e2e |
| TC-FR1-10-AS-04 | in sandbox, MUT-04 makes `fidelity-gate` write **staging** `status.json` diabetes `withdrawn` with message key `unavailable.diabetes` and emit an alert; the job has no production credential (asserted by env inspection) | ci |
| TC-FR1-10-AS-05 | ORC-01 results.json pins the tarball SHA-256, package version 1.0-2, R version, and records AGPL-3 / research-use disclaimer | artefact |
Human gate: HG-02.

### TC-FR1-11 Diabetes symptom escalation
Family: e2e, php, unit. Fixtures: FX-DM-EMG-01/02, SYM-01…03, FX-MAIL-EMG-01, FX-MAIL-SYM-01. Mutation: MUT-05.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-11-AS-01 | EMG-01/02: first child of `[data-test=banners]` has class `emergency`; `#headlineZone` innerText contains no `%`; `#riskNum` absent; "Risk estimate paused" present | e2e |
| TC-FR1-11-AS-02 | FX-MAIL-EMG-01 → `diabetes_emergency` template; body contains no `%` and contains required-phrases.urgentCare | php |
| TC-FR1-11-AS-03 | SYM-01/03: `.banner.symptom` DOM index < `#headlineZone` index; `#riskNum` present with `%` | e2e |
| TC-FR1-11-AS-04 | SYM-02: no `.banner.symptom` | e2e |
| TC-FR1-11-AS-05 | at 375 and 1440 after result: banner top < `[data-test=email-card]` top | e2e |
| TC-FR1-11-AS-06 | intercepted client POST for EMG-01: `emergency:true`, `riskPercent:null`, `riskBand:null` (DEC-09 client layer); server uses emergency template even when a forged `riskPercent` is supplied (FX-MAIL-EMG-01) | e2e + php |
Human gate: HG-02 (copy `emergency.*`, `symptomatic.*`).

### TC-FR1-12 Diabetes interpretation and secondary indicators
Family: unit, e2e, oracle. Fixtures: FX-DM-BAND-01…12, IDRS-01…06, WHTR-01…04, CONC-01/02, NOWAIST-01. Mutations: MUT-06, MUT-08, MUT-09.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-12-AS-01 | BAND-01…12: displayed label = band of the ORC-01 unrounded percentage per DEC-04 | unit + e2e |
| TC-FR1-12-AS-02 | IDRS-01…06 → scores 20/30/50/60/0/70; tiers low/moderate/moderate/high/low/high (ORC-03; activity item is characterisation, DEC-05). IDRS-03 (score 50) is the MUT-08 detector | unit |
| TC-FR1-12-AS-03 | WHTR-01…04 → healthy/raised/raised/high (ORC-04) | unit |
| TC-FR1-12-AS-04 | CONC-01 shows `MESSAGES.reconcile`; CONC-02 does not | e2e |
| TC-FR1-12-AS-05 | NOWAIST-01: `secondary.needWaist` text; no IDRS/WHtR numbers | e2e |
| TC-FR1-12-AS-06 | methodology innerText contains all `required-phrases.methodology.*` | e2e |
| TC-FR1-12-AS-07 | grep of CMP-03 and both landing pages for `waist-hip|waist hip|WHR` = 0 | static |
| TC-FR1-12-AS-08 | result shows "Source: QDiabetes-2018 Model A · version <8 hex>" | e2e |
| TC-FR1-12-AS-09 | `bandFor(5.59)=low`, `bandFor(5.60)=moderate`, `bandFor(9.99)=moderate`, `bandFor(10.00)=high`, `bandFor(19.99)=high`, `bandFor(20.00)=veryHigh` (unrounded input, DEC-04); MUT-06 detector | unit |
Human gate: HG-02.

### TC-FR1-13 Guest access without account
Family: e2e. Fixtures: FX-PR-NRM-01, FX-DM-QD-01.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-13-AS-01 | fresh incognito context completes both flows to result | e2e |
| TC-FR1-13-AS-02 | after result: `context.cookies()` for tool origin unchanged from before; `localStorage.length === 0 && sessionStorage.length === 0` | e2e |
| TC-FR1-13-AS-03 | `page.reload()` → `#results` hidden; every input empty/unselected | e2e |
| TC-FR1-13-AS-04 | `location.search + location.hash` contains none of the entered values | e2e |

### TC-FR1-14 Result email path
Family: php, e2e, log. Fixtures: FX-MAIL-*. Preconditions: Mailpit empty; rate-limit store reset.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-14-AS-01 | FX-MAIL-01/02 → 200 `{status:'ok'}`; exactly 1 message each; template per §1.4 | php |
| TC-FR1-14-AS-02 | NEG-01/02/05/07 → 400, 0 mail; NEG-04 → 413 or 400; NEG-03 → 200, 0 mail, one SCH-07 line with `outcome: honeypot` (DEC-16) | php |
| TC-FR1-14-AS-03 | RATE-01: responses 1–5 = 200, 6th = 429 with JSON `{status:'rate_limited'}` | php |
| TC-FR1-14-AS-04 | NEG-06: mail HTML contains `&lt;img` not `<img` | php |
| TC-FR1-14-AS-05 | Mailpit stopped: POST → 502; e2e shows `MESSAGES.emailFailed`; `#results` still visible | php + e2e |
| TC-FR1-14-AS-06 | endpoint log for the run: every line validates SCH-07 (only `ts`, `tool`, `outcome`, `ip_hash`); no line contains `@`, any sentinel value, or any symptom key | log |
| TC-FR1-14-AS-07 | FX-MAIL-REN-01/02 → renal template; FX-MAIL-EMG-01 → emergency template; FX-MAIL-SYM-01 → symptomatic template | php |
| TC-FR1-14-AS-08 | no mailing-list call: no outbound request from PHP other than SMTP (egress capture in container) | php |
Human gate: HG-03 (DEC-21).

### TC-FR1-15 Safe withdrawal and rollback
Family: e2e, php, wpcli, ci. Fixtures: `status.json` variants; FX-PR-NRM-01; FX-DM-QD-01. Mutation: MUT-13.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-15-AS-01 | `wp lld status set diabetes withdrawn --message=unavailable.diabetes` → within 60 s: hub card `[data-status=withdrawn]` label "Temporarily unavailable"; landing page has no `iframe`; direct tool URL shows `[data-test=unavailable]` and no `form`; `submit.php` → 503 `{status:'withdrawn'}`; protein NRM-01 passes | e2e + php |
| TC-FR1-15-AS-02 | `status set diabetes active --version=<approved>` → all routes active; QD-01 passes | e2e |
| TC-FR1-15-AS-03 | `status set diabetes active --version=deadbeef…` → command fails and `provenance-check` fails | wpcli + ci |
| TC-FR1-15-AS-04 | unavailable state has links to `/health-tools/` and one `lld_review_status=approved` article, both 200 | e2e |
| TC-FR1-15-AS-05 | `curl -I status.json` → `Cache-Control: no-store` | http |
| TC-FR1-15-AS-06 | runbook `docs/runbook-withdrawal.md` contains the toggle command, `wp litespeed-purge` step, the 4-hour production SLA (DEC-08) and a dated drill record | artefact |

### TC-FR1-16 Privacy-preserving conversion measurement
Family: e2e network, artefact. Fixtures: sentinel values; FX-PR-REN-01, FX-DM-EMG-01, FX-DM-QD-01. Mutation: MUT-11.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-16-AS-01 | capture all requests to analytics host during 3 flows; every event name ∈ SCH-09 names; every prop key ∈ {tool, source}; no sentinel value or health key in URL, body or headers | e2e network |
| TC-FR1-16-AS-02 | event counts per flow: `tool_landing_view` 1, `tool_start` 1, `tool_complete` 1 (or `tool_unavailable_view` 1 when withdrawn); `email_sent` 1 when mail sent; names exactly as SCH-09 | e2e |
| TC-FR1-16-AS-03 | analytics host blocked → flows complete; console has no `error` level entries; with CookieYes Analytics category declined, behaviour matches the DEC-11 choice ratified at HG-03 (unconditional load, or no Plausible request) | e2e |
| TC-FR1-16-AS-04 | Plausible stub reports counts equal to AS-02 grouped by `utm_campaign` from the test URL | stub API |
Human gate: HG-03 (DEC-11).

### TC-FR1-17 Policies, education and safe navigation
Family: e2e, crawl, artefact. Fixtures: FX-DM-EMG-01, FX-PR-NRM-01.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-17-AS-01 | five DEC-12 URLs → 200; from each landing page and each tool result zone, Tab traversal reaches a link to each; footer anchors equal baseline set (About, Contact, Privacy Policy, Terms & Conditions) and contain no disclaimer link (DEC-43) | e2e |
| TC-FR1-17-AS-02 | both tools' `[data-test=disclaimer]` contain required-phrases.notDiagnosis and a link to `/medical-disclaimer/` | e2e |
| TC-FR1-17-AS-03 | EMG-01 result contains required-phrases.urgentCare and link to `/medical-disclaimer/#urgent-care`; disclaimer page lists 999, 111, 1990 and 112 (DEC-37) | e2e |
| TC-FR1-17-AS-04 | hash of `/medical-disclaimer/` main content equals `approvals/copy.json.pages.medical-disclaimer` | artefact |
Human gate: HG-02 (disclaimer copy).

### TC-FR1-18 Editorial ownership and review metadata
Family: wpcli, e2e. Fixtures: FX-WP-POST-06/08, FX-WP-USERS.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-18-AS-01 | both landing pages render "Reviewed by {owner} on {date}" from meta | e2e |
| TC-FR1-18-AS-02 | `wp lld review-report --format=json` includes POST-06 and POST-08, excludes POST-01 | wpcli |
| TC-FR1-18-AS-03 | `wp post update <id> --post_status=publish --user=contributor` fails; `--user=editor` succeeds | wpcli |
| TC-FR1-18-AS-04 | Related reading excludes POST-08 | e2e |
| TC-FR1-18-AS-05 | meta keys registered with sanitisers (`wp lld meta list` equals SCH-13) | wpcli |

### TC-FR1-19 Staging and production release
Family: artefact, e2e smoke, ops. Preconditions: all MUST accepted.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-19-AS-01 | `releases/<tag>.json` validates SCH-10; for every MUST ID an `accepted` evidence id exists | artefact |
| TC-FR1-19-AS-02 | restore drill record dated ≤ 7 days before tag | artefact |
| TC-FR1-19-AS-03 | smoke suite (hub, 2 landing, 2 tools happy, status.json, 2 mails to sink) green at +0 h, +1 h, +24 h | e2e |
| TC-FR1-19-AS-04 | rollback drill: deploy previous tag to staging → smoke green; duration recorded < RTO | ops |
Human gate: HG-03.

### TC-FR1-23 Category tidy-up
Family: wpcli, crawl, artefact. Preconditions: baseline crawl and `reports_live_inventory.json` captured; staging. Fixtures: FX-WP-URLS, FX-WP-TIDY (the six empty-category slugs).

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-23-AS-01 | after `wp lld tidy apply`: `wp term list category --fields=slug` lacks `womens-health`, `healthy-living`, `stress`, `uncategorised`; contains `the-report` | wpcli |
| TC-FR1-23-AS-02 | `/category/wellness/womens-health/`, `/category/wellness/healthy-living/`, `/category/wellness/stress/` → 301 to `/category/wellness/`; `/category/uncategorised/` → 301 to `/`; `/category/the-report/` → 200 with `<meta name="robots" content="noindex">` or `X-Robots-Tag: noindex` | crawl |
| TC-FR1-23-AS-03 | primary menu items (`wp menu item list`) contain no URL ending `/the-report/` | wpcli |
| TC-FR1-23-AS-04 | for all 103 baseline posts, category slug set equals the baseline inventory | wpcli + artefact |
| TC-FR1-23-AS-05 | `wp option get default_category` equals the `wellness` term ID | wpcli |
| TC-FR1-23-AS-06 | `content/planned-categories.json` validates (SCH-14) and lists `healthy-living` and `stress` with parent `wellness` | artefact |
| TC-FR1-23-AS-07 | footer anchors on `/` equal the baseline footer anchor set | crawl |
NEG-01: running `wp lld tidy apply` twice is idempotent (second run changes nothing). NEG-02: a post assigned only to a deleted category (seeded on staging) blocks the run with an error rather than silently moving it.

### TC-FR1-20 Cache control and purge
Family: http, e2e.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-20-AS-01 | `status.json` `Cache-Control: no-store`; tool HTML `no-cache, must-revalidate` + `ETag`; `submit*.php` `no-store` | http |
| TC-FR1-20-AS-02 | toggle status → second request through full stack within 60 s reflects new state; `X-LiteSpeed-Cache` header absent or `miss` on `/tools/*` and `status.json` (DEC-31) | e2e + http |
| TC-FR1-20-AS-03 | modify tool HTML comment on staging → ETag changes on next request | http |

### TC-FR1-21 Standalone tool page hardening
Family: static, unit, e2e, http. Mutation: MUT-12.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-21-AS-01 | landing HTML iframe `src` starts with `https://lifelinedaily.com/tools/`; no `data-litespeed-src` / `about:blank` on the iframe (lazy-load excluded, DEC-31) | crawl |
| TC-FR1-21-AS-02 | both tools post `{type:'lld-tool-height', height}` to the configured origin, never `'*'` (static); parent listener unit test: message from `https://evil.example` ignored, message from configured origin applied (DEC-34) | static + unit |
| TC-FR1-21-AS-03 | at 320/375/768/1440 after result: `iframe.clientHeight − contentDocument.scrollHeight| ≤ 20`; no inner scrollbar | e2e |
| TC-FR1-21-AS-04 | `/tools/*.html`: no `noindex` header or meta; HTML `<link rel=canonical>` = landing URL (DEC-14); HSTS header present (DEC-35) | http |
| TC-FR1-21-AS-05 | page on `http://localhost:9999` framing the tool → frame blocked (Playwright `frame.url()` about:blank / CSP error) | e2e |
| TC-FR1-21-AS-06 | `<noscript>` contains link to standalone page | static |

### TC-FR1-22 Independent oracles
Family: ci, artefact.

| ID | Assertion | Method |
|---|---|---|
| TC-FR1-22-AS-01 | `make oracle ORC=01..04` succeeds from clean checkout in CI | ci |
| TC-FR1-22-AS-02 | `oracles/orc-02-protein-ref/session-log.json` file-access list contains no `*.html|*.js` | artefact |
| TC-FR1-22-AS-03 | each results.json validates SCH-06 and covers every fixture id in scope (set equality) | artefact |
| TC-FR1-22-AS-04 | results.json records R/package/Python versions, tarball SHA-256 and source hashes; ORC-02 ships `primary-sources.json` for TC-FR1-07-AS-08 | artefact |

---

## 5. Test-case pages — non-functional requirements

### TC-NFR1-01 Privacy and special-category data
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-01-AS-01 | `privacy/data-flow.json` validates SCH-12; key set ⊇ static scan of payload builders and templates | artefact + static |
| TC-NFR1-01-AS-02 | every field with `classification: health` has `destinations ⊆ {screen, smtp}` | artefact |
| TC-NFR1-01-AS-03 | TC-FR1-16-AS-01 passes | e2e |
| TC-NFR1-01-AS-04 | `privacy/dpia-screening.md` has no `TODO` and all 9 ICO screening questions answered | artefact |
Human gate: HG-03.

### TC-NFR1-02 Clinical governance
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-02-AS-01 | approval records: `clinical_signoff.{name,date,decision}` present, decision `approved` | artefact |
| TC-NFR1-02-AS-02 | registry: 0 DEC rows with `ratify_at: HG-02` and status ∉ {ratified, overridden} | artefact |
| TC-NFR1-02-AS-03 | `clinical-gate` CI job green | ci |
Human gate: HG-02.

### TC-NFR1-03 Security
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-03-AS-01 | gitleaks clean; served HTML/JS grep `smtp|passw|apikey|secret` = 0 | sec |
| TC-NFR1-03-AS-02 | ZAP baseline on 6 URLs + 2 endpoints: 0 High; Medium listed in `security/triage.json` with disposition | sec |
| TC-NFR1-03-AS-03 | WPScan: 0 components with known vulnerabilities | sec |
| TC-NFR1-03-AS-04 | headers on 6 URLs: HSTS, CSP, X-Content-Type-Options, Referrer-Policy, Permissions-Policy present | http |
| TC-NFR1-03-AS-05 | FX-MAIL-NEG-06 payload never appears unescaped in any HTTP response or email | php |
| TC-NFR1-03-AS-06 | `/tools/`, `/tools/api/config.php`, `/tools/api/mailer.php`, `/tools/api/templates*.php` → 403 | http |
| TC-NFR1-03-AS-07 | TC-FR1-14-AS-03 (rate limit) passes | php |

### TC-NFR1-04 Isolation of the two pipelines
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-04-AS-01 | CI run for a commit touching `api/mailer.php` contains jobs `saf-protein` and `saf-diabetes` | ci |
| TC-NFR1-04-AS-02 | both SAF suites green on that run | ci |
| TC-NFR1-04-AS-03 | SAF-CROSS-01: FX-MAIL-01 and FX-MAIL-02 both delivered after the change | php |

### TC-NFR1-05 Accessibility
States: home, hub, 2 landing, protein form, protein renal result, protein normal result, diabetes form, diabetes emergency result, diabetes normal result, unavailable state (11).
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-05-AS-01 | axe: 0 critical, 0 serious on 11 states | a11y |
| TC-NFR1-05-AS-02 | keyboard-only script completes both tools; focus order = DOM order; no element traps focus | e2e |
| TC-NFR1-05-AS-03 | all inputs/option groups labelled; `[data-test=error]` and banners have `role=alert` or `aria-live=assertive` | e2e |
| TC-NFR1-05-AS-04 | computed contrast of band colours and banner text/background ≥ 4.5:1 | a11y |
| TC-NFR1-05-AS-05 | Lighthouse accessibility ≥ 95 on 6 pages | perf |
| TC-NFR1-05-AS-06 | heading levels never skip | a11y |
Mutation: MUT-14.

### TC-NFR1-06 Mobile performance
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-06-AS-01 | Lighthouse CI (DEC-17): median LCP ≤ 2500 ms, CLS ≤ 0.1 on 4 URLs | perf |
| TC-NFR1-06-AS-02 | analytics script tag has `defer`; no new synchronous third-party script in `<head>` | static |
| TC-NFR1-06-AS-03 | LCP regression vs `perf/baseline.json` ≤ 10 % | perf |

### TC-NFR1-07 SEO preservation
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-07-AS-01 | TC-FR1-02-AS-01 passes | crawl |
| TC-NFR1-07-AS-02 | page sitemap includes hub, 2 landing, `/medical-disclaimer/`; excludes `/tools/*.html` | crawl |
| TC-NFR1-07-AS-03 | `/tools/*.html` canonical to landing page and no `noindex`; landing pages no `noindex` | http |
| TC-NFR1-07-AS-04 | JSON-LD on landing pages validates (schema.org type `MedicalWebPage` or `WebApplication`) | static |
| TC-NFR1-07-AS-05 | 0 redirect chains > 1 hop on sample | crawl |

### TC-NFR1-08 Backup and restore
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-08-AS-01 | `make backup` produces DB dump, `wp-content`, `public_html/tools`, manifest of SHA-256 | ops |
| TC-NFR1-08-AS-02 | `make restore` into clean container: tool hashes = manifest; 10 posts 200; FX-PR-NRM-01 and FX-DM-QD-01 pass; FX-MAIL-01 delivered to Mailpit | ops + e2e |
| TC-NFR1-08-AS-03 | restore duration logged < 4 h | ops |
| TC-NFR1-08-AS-04 | retention config matches DEC-19 | artefact |
| TC-NFR1-08-AS-05 | backup manifest timestamps over the last 7 days: max gap ≤ 24 h | artefact |

### TC-NFR1-09 Reproducible AI development
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-09-AS-01 | PR title regex check job present and enforced; body contains `evidence/` path | ci |
| TC-NFR1-09-AS-02 | branch protection on `staging` requires all 15 jobs (API query) | ci |
| TC-NFR1-09-AS-03 | test PR changing `fixtures/protein/nrm.json#expected` by a non-bot author fails `oracle-guard` | ci |
| TC-NFR1-09-AS-04 | every evidence bundle has `commit` and `tool_hashes` | artefact |

### TC-NFR1-10 Error handling and observability
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-10-AS-01 | route-mock endpoint → 400/429/502/503: UI shows distinct messages (`emailFailed`, rate message, unavailable) and a retry control where applicable | e2e |
| TC-NFR1-10-AS-02 | block `status.json` → tool renders the "can't confirm this tool is available" notice with retry, form submit disabled, no result (fail closed, DEC-10); unblock + retry → calculation works | e2e |
| TC-NFR1-10-AS-03 | after full suite, endpoint logs validate SCH-07 line by line | log |

### TC-NFR1-11 Graceful clinical feature disable
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-11-AS-01 | TC-FR1-10-AS-04 | ci |
| TC-NFR1-11-AS-02 | SAF-WITHDRAW-01 (= TC-FR1-15-AS-01) | e2e |
| TC-NFR1-11-AS-03 | TC-FR1-15-AS-03 | ci |

### TC-NFR1-12 Browser and device support
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-12-AS-01 | Playwright projects chromium, webkit, firefox, Pixel 5, iPhone 12 complete FX-PR-NRM-01 and FX-DM-QD-01 | e2e |
| TC-NFR1-12-AS-02 | 320 px: `scrollWidth <= innerWidth` on 6 pages | e2e |

### TC-NFR1-13 Monitoring
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-13-AS-01 | uptime monitor config lists hub + 2 standalone tools, 5-min interval, alert email set | artefact |
| TC-NFR1-13-AS-02 | daily synthetic job loads both tools and asserts `form` present | ci schedule |
| TC-NFR1-13-AS-03 | staged outage (return 503 for 10 min on staging) → alert received | ops |

### TC-NFR1-14 Email deliverability and sandbox
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-14-AS-01 | staging `config.php` reads SMTP from env; values are Mailpit; grep for production host = 0 | static |
| TC-NFR1-14-AS-02 | DNS: SPF includes sending provider; DKIM selector resolves; DMARC policy present | dns |
| TC-NFR1-14-AS-03 | 6 templates render text + HTML; HTML passes `html-validate` email preset; subjects equal `copy/templates.json` | php |

### TC-NFR1-15 Embed security policy
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-15-AS-01 | CSP headers per DEC-28 on landing and tool pages | http |
| TC-NFR1-15-AS-02 | TC-FR1-21-AS-05 | e2e |
| TC-NFR1-15-AS-03 | with `Content-Security-Policy-Report-Only`, full E2E suite produces 0 reports | e2e |

### TC-NFR1-16 Logging and retention
| ID | Assertion | Method |
|---|---|---|
| TC-NFR1-16-AS-01 | log lines validate SCH-07 | log |
| TC-NFR1-16-AS-02 | logrotate config: 30 days | artefact |
| TC-NFR1-16-AS-03 | same IP, salt for day N vs N+1 → different `ip_hash` | php |

---

## 6. Test-case pages — user-interface requirements

### TC-UI1-01 Homepage hierarchy
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-01-AS-01 | TC-FR1-03-AS-01/02/03/05 | e2e |
| TC-UI1-01-AS-02 | `[data-test=tools-row] [data-tool-card]` count = 2 at 4 widths; `[data-test=learn-row] article` ≥ 4 | e2e |
| TC-UI1-01-AS-03 | visual diff vs `design/baselines/home-<w>.png` ≤ 0.5 % | e2e visual |
Optional usability session recorded in `evidence/UI1-01/usability.md` if performed.

### TC-UI1-02 Tool cards and landing pages
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-02-AS-01 | card fields = `tool-cards.json` (name, purpose ≤ 120 chars, time, inputs, eligibility, status, cta, related) | e2e |
| TC-UI1-02-AS-02 | CTA → landing page whose iframe src ends with the tool's filename | e2e |
| TC-UI1-02-AS-03 | status label text = mapping of `status.json` (active → "Available now", withdrawn → "Temporarily unavailable", label_only → "Planned") | e2e |

### TC-UI1-03 Responsive layout
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-03-AS-01 | 6 pages × 4 widths × {form, result}: `scrollWidth <= innerWidth` | e2e |
| TC-UI1-03-AS-02 | primary CTA: after `scrollIntoView`, `elementFromPoint(center)` is the CTA or its descendant | e2e |
| TC-UI1-03-AS-03 | `[data-test=metrics]` children: no `scrollWidth > clientWidth` | e2e |

### TC-UI1-04 Calculator input clarity
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-04-AS-01 | every `input, select, [role=group]` has accessible name (axe `label` rule + computed name non-empty); unit text adjacent to numeric inputs | a11y |
| TC-UI1-04-AS-02 | for each required field omitted (FX-PR-INV-07/08, FX-DM-INV-01/04/05): specific error visible with `role=alert`; results hidden | e2e |
| TC-UI1-04-AS-03 | sex female → pregnancy group visible; male → hidden and `preg='no'`; hasSym yes → `#symList` visible; waist entered → secondary chips update | e2e |
| TC-UI1-04-AS-04 | FX-PR-INV-03/04: error text contains both "140 cm" and "4ft 7in" | e2e |

### TC-UI1-05 Clinically safe result layout
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-05-AS-01 | result children order: banners, headline, metrics, interp, advice, methodology, disclaimer, email-card (data-test sequence) | e2e |
| TC-UI1-05-AS-02 | REN-01 and EMG-01 banners: `role=alert`, first in result, contrast ≥ 4.5:1, fully within viewport at 375 after render | e2e + a11y |
| TC-UI1-05-AS-03 | screenshots of REN-01, UNS-01, EMG-01, SYM-01, BAND low, BAND very high at 375 and 1440 match baselines ≤ 0.5 % | visual |
| TC-UI1-05-AS-04 | result headline is `h2`; section headings `h3` | e2e |
Mutation: MUT-14. Human gate: HG-02.

### TC-UI1-06 Email consent interface
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-06-AS-01 | `#consent` unchecked on load; no other checkbox in email card; label text hash = approved copy deck (explicit health-data consent with Privacy Policy link, DEC-21) | e2e |
| TC-UI1-06-AS-02 | send button disabled (or validation blocks) until valid email and consent | e2e |
| TC-UI1-06-AS-03 | skipping email: `#results` visible and complete | e2e |
| TC-UI1-06-AS-04 | `input[type=checkbox]:checked` count on load = 0 in both tools | e2e |

### TC-UI1-07 Navigation and educational links
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-07-AS-01 | crawler from `/` depth 3: 0 status ≥ 400, 0 loops | crawl |
| TC-UI1-07-AS-02 | landing pages: breadcrumb `Home › Health Tools › {Tool}`; each crumb 200 | e2e |
| TC-UI1-07-AS-03 | unavailable-state links 200 | e2e |

### TC-UI1-08 Loading, unavailable and error states
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-08-AS-01 | delay `status.json` 2 s → `[data-test=loading][aria-busy=true]` visible, removed after load | e2e |
| TC-UI1-08-AS-02 | withdrawn: `h2` heading, message = copy deck `unavailable.<slug>`, links to hub + article, `form` count 0 | e2e |
| TC-UI1-08-AS-03 | email network failure → `#emailErr` text and `#sendBtn` re-enabled with "try again" | e2e |
| TC-UI1-08-AS-04 | axe clean on loading, withdrawn, error states | a11y |

### TC-UI1-09 Visual consistency
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-09-AS-01 | `design/tokens.json` exists; stylelint rule `color-no-hex` passes on plugin CSS and tool `<style>` except tokens block | lint |
| TC-UI1-09-AS-02 | 24 visual snapshots ≤ 0.5 % diff vs baseline, or baseline updated in same PR with reviewer PASS | visual |

### TC-UI1-10 Iframe embed behaviour
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-10-AS-01 | iframe has `title`; no `loading="lazy"`; `allow` attribute absent or empty | static |
| TC-UI1-10-AS-02 | form → result: iframe height changes within 500 ms to content height ± 20 | e2e |
| TC-UI1-10-AS-03 | `<noscript>` link → 200 | static + http |
| TC-UI1-10-AS-04 | listener ignores message from other origin (unit) | unit |

### TC-UI1-11 Copy deck freeze
| ID | Assertion | Method |
|---|---|---|
| TC-UI1-11-AS-01 | `copy/inventory.json` lists every source (MESSAGES, templates, `data-copy` inline strings, landing and disclaimer pages); `make copy-extract` regenerates `copy/*.json`; every entry hash equals `approvals/*.copy_hashes` | ci |
| TC-UI1-11-AS-02 | change one `MESSAGES` string in a sandbox → `copy-hash` job fails | ci |
| TC-UI1-11-AS-03 | required phrases present and forbidden terms absent across `copy/*.json` and templates | static |
Human gate: HG-02.

---

## 7. Mandatory safety regression suite (runs on every PR)

| SAF ID | Fixtures | Assertions re-used | Gate |
|---|---|---|---|
| SAF-PR-01 Renal yes | FX-PR-REN-01…04, FX-MAIL-REN-01/02 | TC-FR1-08-AS-01…05, 07 | G-02 |
| SAF-PR-02 Renal unsure | FX-PR-UNS-01 | TC-FR1-08-AS-06 | G-02 |
| SAF-PR-03 Pregnancy/lactation | FX-PR-PRG-01…04 | TC-FR1-09-AS-01, 04, 05 | G-02 |
| SAF-PR-04 Cap and basis weight | FX-PR-CAP-01…03, FX-PR-BMI-01…04 | TC-FR1-07-AS-01, 02, 06 | G-03 |
| SAF-DM-01 Emergency | FX-DM-EMG-01/02, FX-MAIL-EMG-01 | TC-FR1-11-AS-01, 02, 05, 06 | G-02 |
| SAF-DM-02 Classic symptoms | FX-DM-SYM-01…03, FX-MAIL-SYM-01 | TC-FR1-11-AS-03, 04 | G-02 |
| SAF-DM-03 Band boundaries | FX-DM-BAND-01…12 | TC-FR1-12-AS-01, TC-FR1-10-AS-01 | G-03 |
| SAF-DM-04 Secondary indicators | FX-DM-IDRS, WHTR, CONC, NOWAIST | TC-FR1-12-AS-02…05 | G-03 |
| SAF-CROSS-01 Shared change | FX-PR-NRM-01, FX-DM-QD-01, FX-MAIL-01/02 | TC-NFR1-04-AS-01…03 | G-02 |
| SAF-WITHDRAW-01 Withdrawal | status variants | TC-FR1-15-AS-01, 02 | G-02 |

## 8. End-to-end acceptance journeys (1:1 with UC1-xx)

| E2E | Journey | Steps (Playwright spec name) | Assertions re-used |
|---|---|---|---|
| E2E-01 | UC1-01 campaign visitor, protein | `e2e/journeys/e2e-01.spec.ts`: open landing with UTM → read sections → complete NRM-01 → email FX-MAIL-01; variations INV-01, REN-01, UNS-01, decline email, withdrawn | TC-FR1-03/04/07/08/13/14/16, UI1-04/05/06/08 |
| E2E-02 | UC1-02 diabetes | `e2e-02.spec.ts`: QD-01 happy → EMG-01 → SYM-01 → NOWAIST-01 → CONC-01 → withdrawn | TC-FR1-10/11/12/13/14/15, UI1-05/08 |
| E2E-03 | UC1-03 editor links article | `e2e-03.spec.ts`: login editor → POST-01 shows CTA → set override none → no CTA → contributor cannot publish | TC-FR1-02/05/18, UI1-07 |
| E2E-04 | UC1-04 withdrawal | `e2e-04.spec.ts`: incident file → `wp lld status set` → all routes → restore with approved version → restore with bad version fails | TC-FR1-06/15/20, NFR1-11, UI1-08 |
| E2E-05 | UC1-05 campaign measurement | `e2e-05.spec.ts`: UTM link → events captured → stub counts; blocked-script variant | TC-FR1-16, NFR1-01 |
| E2E-06 | UC1-06 keyboard and screen reader | `e2e-06.spec.ts`: Tab-only traversal of both tools; axe on each state | TC-NFR1-05, UI1-04/05, FR1-17 |
| E2E-07 | UC1-07 release | `ops/release-drill.sh`: backup → restore → smoke → rollback | TC-FR1-19, NFR1-08/09/13 |

## 9. Evidence and completion rules

- Evidence bundle per run validates SCH-06e; artefact logs scrubbed (emails, numbers ≥ 2 digits except timestamps, symptom keys).
- A MUST requirement is `verified` when all AS/NEG are green on the same commit, listed MUTs recorded red and the reviewer returns PASS; it is `accepted` when, for hybrid items, the HG artefact has `decision: approved`. Dependants may build on `verified`; release gates require `accepted` (04 §2).
- No test may be deleted or weakened; the `assertion-map` CI job enforces presence and fixture import for every ID in this document.
- Characterisation `expected` values prove "unchanged", never "correct". Correctness is claimed only for assertions backed by ORC-01…ORC-04 and recorded as `oracle: clinical`.

## 10. Definition of done

23 FR + 16 NFR + 11 UI requirements `accepted` (MUST) or `deferred` by DEC (SHOULD: FR1-16, NFR1-06, NFR1-13, UI1-09); SAF suite green; MUT-01…14 all proven; G-01…G-06 satisfied; +24 h production smoke green.
