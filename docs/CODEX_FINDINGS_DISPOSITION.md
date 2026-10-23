# Disposition of Codex review findings (22 Oct 2026)

Source: `CODEX_REVIEW_HANDOFF_FOR_CLAUDE.md`. Each finding was re-checked against the live site or the pack before action. All accepted fixes are applied to the Markdown, registry JSON, fixtures and Word mirrors; validation (SCH-01, SCH-05, Draft-07, assertion cross-reference) is green.

| Finding | Verdict | Verification | Correction applied | Open for human |
|---|---|---|---|---|
| F01 Human-gate deadlock | **Accepted** | Confirmed by reading the state machine: hybrid items could never reach `accepted` before HG-02, so wave-2 dependants would never become `ready`. | New state `verified` (automated green + reviewer PASS) satisfies `depends_on`; `accepted` adds the HG artefact; release gates require `accepted`; P-E triggers on `verified`; clinical/release gates are tag checks not PR checks. SRS §7, Loop §2, §4, registry schema enum. | — |
| F02 Protein hash mismatch | **Accepted (transcription error)** | 20 Oct snapshot and two fresh fetches all hash `…bea5bf6…`; registry had `…bea4ff6…`. Not a file change. | Registry, SRS §3 and DEC-01 now carry the full correct hash. P-A0 stop condition now references registry `live_hashes`. | — |
| F03 ORC-01 API / package | **Accepted** | Codex's `QDR2018A` signature check (bmi, ht, wt before ethn; `gdm`; archived 11 Jun 2026) consistent with the first review. | DEC-02/33: named arguments only, pass `bmi`, archive tarball 1.0-2 with SHA-256, R version and container digest; unrounded ±0.05 pp tolerance. TC §2 and TC-FR1-10 updated. | HG-02 ratifies |
| F04 IDRS fixtures wrong | **Accepted (my error)** | Recomputed with the live formula: old vectors scored 0/30/60/70. | Six vectors now score 20/30/50/60/0/70; score 50 is the MUT-08 detector. `fixtures/diabetes/idrs.json`, TC-FR1-12-AS-02, MUT table. | — |
| F05 BAND coverage | **Accepted** | Generator bug truncated 12 cases to 8; Codex's engine sweep shows Female 57 WhiteNA cannot reach 20 % (BMI clamp). | 12 fixtures across three profiles (Female 62 Bangladeshi for 20 %); new TC-FR1-12-AS-09 tests `bandFor` directly at six edge values; DEC-04 records that classification uses the unrounded value and flags the rounding question for HG-02. | HG-02: classify on raw or rounded value |
| F06 Withdrawal conflicts | **Accepted** | Confirmed contradiction between DEC-08 and TC §2; status-fetch failure allowed calculation. | DEC-08 precedence (deployed-hash fidelity failure → staging withdrawal; ORC-02/03 disagreement → preserve + block; infra failure → CI fail). DEC-10 fail-closed when `status.json` is missing or invalid; TC-NFR1-10-AS-02 and TC §2 rewritten. DEC-08 now `ratify_at: HG-01`. | HG-01 ratifies |
| F07 Analytics | **Accepted** | Event-name mismatch confirmed in TC-FR1-16-AS-02. Plausible's processor-side daily hash is documented behaviour. | Names normalised to SCH-09; DEC-11 records processor-held pseudonymous data, the PECR statistical-purpose conditions (clear information, free objection) and removes the GA4 fallback in favour of gating Plausible under the existing CookieYes banner (DEC-32). Data Model §1 clarified. | HG-03: privacy reviewer confirms route |
| F08 Consent / decision status | **Accepted** | `blocked` is not a decision status; ready rule omitted `overridden`. | DEC-21 defines no-persistence precisely, consent evidence (string version + timestamp in the email), status becomes `open` with FR1-14 `blocked`; ready rule now accepts `overridden`. | HG-03 |
| F09 DEC-26 hub label | **Accepted** | Fetched `/tools/health-tools-hub.html`: contains "Healthy Weight Check" and "Coming soon — BMI, waist-to-height…". The hub page is itself an iframe of that file. | DEC-26 and SRS §3 corrected; calculator remains out of scope; label may persist as `label_only`; FR1-04 now says the shortcode replaces the hub iframe. | — |
| F10a TC-FR1-09-AS-05 text vs hash | **Accepted** | Wording error. | Hash of extracted text vs approval hash. | — |
| F10b Log number ban vs `ip_hash` | **Accepted** | SCH-07 hex field would trip the regex. | Field-level validation plus sentinel/email/symptom scan. | — |
| F10c Honeypot counter vs log | **Accepted** | DEC-16 and TC disagreed. | One SCH-07 line with `outcome: honeypot`. | — |
| F10d DEC-13 schema ref / precedence | **Already resolved** in the same-day revision (SCH-05b, tag rule wins, live slugs). | — | — | — |
| F10e DEC-18 browser claims | **Accepted** | Emulation ≠ real devices; traffic figure unsupported. | Rationale rewritten; optional real-device pass at HG-03. | — |
| F10f DEC-23 placement untested | **Accepted** | — | New TC-FR1-03-AS-07 DOM-order assertion. | — |
| F10g DEC-25 copy inventory incomplete | **Accepted** | Inline HTML and page copy were outside the hash set. | `copy/inventory.json` covering MESSAGES, templates, `data-copy` inline strings, landing and disclaimer pages; UI1-11 updated. | HG-02 |
| F10h DEC-19 achieved RPO | **Accepted** | — | New TC-NFR1-08-AS-05 (7-day timestamp gap ≤ 24 h). | — |
| F10i ORC-02 isolation evidence | **Accepted as a limitation** | A file-access log cannot prove absence of prior knowledge. | DEC-03 states the limit; primary-source band check (TC-FR1-07-AS-08) remains the second layer. | HG-02 ratifies band table |
| Codex's own corrections to its first report (8 items) | **Noted** | Consistent with the dispositions above; none required further change. | — | — |

## Disputed or not adopted
None. Every finding was either confirmed and fixed, or already resolved in the same-day revision.

## Resulting pack state
49 requirements, 38 decisions, 219 assertions, 192 fixture vectors (BAND 12, IDRS 6), 14 schemas, 14 mutations. Human decisions still pending are exactly those scheduled for HG-01, HG-02 and HG-03 in `05_Decision_Register_v1_2.md`.
