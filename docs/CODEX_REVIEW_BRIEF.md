# Independent review brief — Stage 1 Decision Register v1.2

Paste this into Codex (or any independent agent) with the folder `C:\MyProjects\lifeline-daily\v1_2` and the two reference documents from `Lifeline_Daily_Four_Stage_v1_1_Pack.zip/Original_Tool_References/` available.

## Task
Verify `05_Decision_Register_v1_2.md` (30 decisions DEC-01…DEC-30 and gates HG-01…HG-03). You are an independent reviewer. Do not edit any file. Produce a report, not fixes.

## For each DEC row, check three things
1. **Factual basis.** Where the row cites the live site, confirm against the public source: `https://lifelinedaily.com/tools/diabetes-risk-tool.html`, `https://lifelinedaily.com/tools/protein-requirement-tool.html`, `https://lifelinedaily.com/health-tools/`, `https://lifelinedaily.com/page-sitemap.xml`. Specifically confirm: QDiabetes-2018 Model A coefficients present (DEC-01); `bandFor` thresholds 5.6/10/20 (DEC-04); IDRS tiers `<30`, `<60` (DEC-05); waist-to-height ratio with 0.5/0.6, no hip input (DEC-06); `tds:0` (DEC-07); age captured as band midpoints 27…82 (DEC-29); iframe src uses `www.` host and fixed `min-height` (DEC-15); which policy pages exist (DEC-12); no healthy-weight tool on hub or sitemap (DEC-26).
2. **Internal consistency.** Every DEC referenced in `01_SRS_Stage1_v1_2.md`, `03_Test_Criteria_Stage1_v1_2.md` and `registry/Stage1_Requirements_Registry_v1_2.json` exists in the register with the same selected default; the `ratify_at` gate in the register matches the registry JSON; requirements marked `hybrid` reference a human gate consistent with their decisions.
3. **Soundness of the default.** Is the selected default defensible for a UK-facing public health site with medical screening tools, given the stated rationale? Flag any default that a product owner, clinician or privacy officer would likely reject, and say why. Pay particular attention to DEC-02 (CRAN QDiabetes as oracle: check the package exposes `QDR2018A` with those argument names and that Townsend can be fixed at 0), DEC-03 (sealed second implementation as oracle), DEC-08 (automatic withdrawal on fidelity failure), DEC-09 (email allowed during emergency result), DEC-11 (Plausible cookieless analytics without consent banner under UK PECR), DEC-16 (rate limit numbers), DEC-21 (consent as lawful basis incl. Article 9(2)(a)).

## Also check
- Are there decisions an autonomous build agent would still need that are missing from the register? List them with a proposed default.
- Any contradiction between two rows.
- Any row whose default cannot be verified by the automated assertions that cite it (look up the TC page).

## Output format
Markdown table: `DEC id | factual basis (confirmed / not confirmed / not checkable) | consistency (ok / issue) | soundness (ok / concern) | notes`. Then a short list of missing decisions and contradictions. End with an overall verdict: register usable as-is, usable with listed edits, or not usable.
