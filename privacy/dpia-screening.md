# DPIA screening — Lifeline Daily health tools (Stage 1)

Drafted by the build agent for the privacy reviewer (NFR1-01, DEC-21, DEC-36); approved at HG-03. The nine screening questions follow the ICO's DPIA screening checklist (the EDPB/WP248 high-risk criteria the ICO adopts). The data-flow inventory is `privacy/data-flow.json` (SCH-12).

**Processing in scope.** Two calculators (Daily Protein Target, Diabetes Risk Check) run in the visitor's browser. Nothing entered is sent to Lifeline Daily unless the visitor asks for the result by e-mail and ticks the consent box; then the endpoint sends one e-mail through the site's mail provider and keeps nothing. Endpoint logs hold only timestamp, tool, outcome code and a daily-salted IP hash, for 30 days (DEC-20). Analytics is cookieless Plausible counting page and tool events with no form values (DEC-11, FR1-16).

| # | Screening question | Answer | Reason |
|---|---|---|---|
| 1 | Evaluation or scoring, including profiling and predicting? | Yes — limited | The diabetes tool estimates a 10-year risk and the protein tool a daily target. The estimate is shown to the visitor only; Lifeline Daily keeps no profile and makes no prediction about anyone. |
| 2 | Automated decision-making with legal or similarly significant effect? | No | The result is educational screening information. No decision about the visitor is taken, and the visitor is told to speak to a clinician. |
| 3 | Systematic monitoring of individuals? | No | No accounts, tracking cookies or cross-site identifiers. Analytics is aggregate and cookieless. |
| 4 | Special category or highly personal data? | Yes | Health inputs and results (Art. 9). They stay in the browser unless the visitor gives explicit consent to e-mail the result (DEC-21); they are never logged or sent to analytics. |
| 5 | Processing on a large scale? | No | A small editorial site; no database of results is created. |
| 6 | Matching or combining datasets? | No | No linking of tool data with any other dataset. |
| 7 | Data concerning vulnerable data subjects? | Possibly | Visitors may be unwell or anxious about their health. Mitigations: emergency and symptom banners take priority over any number, urgent-care guidance (DEC-37), plain-language copy reviewed at HG-02, and a protein tool limited to adults. |
| 8 | Innovative use or new technological or organisational solutions? | No | Published, established calculation methods run client-side; no AI or novel technique. |
| 9 | Processing that prevents data subjects exercising a right or using a service? | No | The tools are free and work without an e-mail address or any account; nothing is stored that a right would need to reach. |

**Outcome.** Two criteria are met in part (1, 4) and one possibly (7). Because health data never leaves the visitor's browser except in a single consented e-mail that is not stored, no high-risk processing remains after the mitigations above. Screening recommendation: a full DPIA is not required for Stage 1; record this screening, the data-flow inventory and the Privacy Policy update (DEC-21) at HG-03, and re-screen if any result is ever stored or analytics carries form values.

**Reviewer sign-off (HG-03):** recorded in the HG-03 gate record, not in this file.
