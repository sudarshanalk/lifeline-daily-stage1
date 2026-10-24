# Lifeline Daily — Software Requirements Specification, Stage 1 — v1.2

Status: baseline for AI-driven build. Supersedes SRS v1.1 (20 Oct 2026). Revised 22 Oct 2026 after independent review of the decision register (see `05_Decision_Register_v1_2.md`, DEC-31…38). Stages 2–4 remain a roadmap (Appendix A) and are unchanged from v1.1.

Related: `02_Data_Model_Stage1_v1_2.md`, `03_Test_Criteria_Stage1_v1_2.md`, `04_AI_Development_Loop_v1_2.md`, `05_Decision_Register_v1_2.md`, `registry/Stage1_Requirements_Registry_v1_2.json`.

---

## 0. How to read this pack (for the product owner)

Five documents, read in this order: this SRS (what is built and how each item is accepted), the Decision Register (every choice already made for the agent, and the three points where you sign), the Data Model (what data exists and where), the Test Criteria (the exact checks per requirement), and the AI Development Loop (how the agent works through it). You sign three times: **HG-01** after the baseline audit (ratify the decisions marked HG-01 and supply the open owner inputs listed at the end of the Decision Register), **HG-02** with your clinical reviewer (methods, copy, oracle results), **HG-03** before production release (privacy and release record).

Glossary. **Oracle**: an independent source of correct answers used to check a calculation (here the CRAN QDiabetes package, and a second protein engine written only from the clinical reference). **Characterisation fixture**: a recorded "what the live engine does today" value; it proves nothing changed, not that it is correct. **Mutation**: a deliberate break of the code to prove a test would catch it. **Hybrid requirement**: automated checks plus one human signature. **Sentinel value**: a unique fake input used to detect leaks to analytics or logs. **SAF**: the mandatory safety test suite. **P-A0, P-B…**: the agent prompts in the Development Loop. **Mailpit**: a local inbox that captures test emails. **Playwright**: the browser automation tool used for end-to-end tests. **Canonical URL**: the address search engines should treat as the original. **Must-use plugin**: a WordPress plugin that is always active and lives in its own folder.

## 1. What changed from v1.1 and why

1. **Rebaselined to the live system.** v1.1 treated the diabetes engine as unknown and the tools hub as new. Both were checked against the public site on 20 Oct 2026 (section 3). The deployed diabetes tool is a QDiabetes-2018 Model A port; the hub, navigation entry and both landing pages already exist. Requirements now say "verify" or "improve" where v1.1 said "identify" or "add".
2. **Every acceptance criterion is split into automated assertions and, where unavoidable, one of three human gates (HG-01…HG-03).** Nothing requires a human to interpret prose.
3. **Independent oracles replace per-case clinical arithmetic** (DEC-02, DEC-03): the CRAN QDiabetes package and a sealed reference implementation of the protein engine. Clinicians sign the method once, not each number.
4. **All open questions are closed by default decisions** in `05_Decision_Register_v1_2.md`. An agent never asks; it applies the DEC row and cites it.
5. **New requirements** cover gaps found in review: cache control and purge (FR1-20), standalone-page hardening (FR1-21), independent oracle implementation (FR1-22), browser support (NFR1-12), monitoring (NFR1-13), email deliverability and sandbox (NFR1-14), embed security policy (NFR1-15), log retention (NFR1-16), iframe behaviour (UI1-10), copy-deck freeze (UI1-11).
6. **Corrections:** waist-hip ratio → waist-to-height ratio throughout (DEC-06); IDRS tier gap closed (DEC-05); "five named policies" enumerated (DEC-12); 30-URL sample defined (section 13).

## 2. Scope and controlling rules

Stage 1 = the two deployed public tools (Daily Protein Target, Diabetes Risk Check) preserved and verified, plus WordPress homepage, hub, landing-page, linking, analytics and governance improvements, and the small category tidy-up in FR1-23. Nothing else. Member accounts, new calculators, persistent tracking, payments and the mobile app are Stages 2–4.

Source hierarchy: (1) served source of the live tools and WordPress staging clone, hashed in the AS-IS report; (2) this SRS v1.2 and the Decision Register; (3) `Protein-Tool-Engine-Reference.docx` (16 Jul 2026) and `Diabetes Risk Screening Tool.docx` as design notes; (4) SRS v1.0 for context. A discrepancy between (1) and (3) is recorded as a DEC row with `status: open` and the live behaviour is preserved until the row is decided at HG-02.

Rules the agent cannot override: no change to any **frozen object** (the canonical list is the key set of `engine_hashes` in `manifests/<slug>.json`: for protein `BANDS`, `DIET_MULT`, `PREG_GKG`, `cutoffs`, `computeProtein`, `clampMeal`; for diabetes `ENGINE`, `bandFor`, `idrs`, `whtr`, and the emergency/symptom priority block of the result renderer; plus both PHP template-selection branches) without a DEC row ratified at HG-02; no production deployment without HG-03; no editing of a fixture's `expected` value except by the oracle runner (ORC-01/02) or a signed clinical fixture.

## 3. AS-IS baseline (verified 20–23 Oct 2026: public HTML, then the owner-supplied production snapshot)

| Item | Finding | Reference |
|---|---|---|
| Site | WordPress + Newspaper theme; nav: Fitness, Food & Nutrition, Health Conditions, Wellness, Health Tools (Daily Protein Target, Diabetes Risk Check), The Report | CMP-01 |
| Homepage | Article feed; tools appear only in the menu | FR1-03 |
| Hub | `/health-tools/` iframes `https://www.lifelinedaily.com/tools/health-tools-hub.html`, a standalone page with two tool cards and a non-interactive "Healthy Weight Check — Coming soon" card | FR1-04, DEC-26 |
| Landing pages | `/health-tools/protein/`, `/health-tools/diabetes-risk-check/`; each iframes a standalone HTML tool from `https://www.lifelinedaily.com/tools/…` via LiteSpeed lazy-load (`src="about:blank"`, `data-litespeed-src`); diabetes iframe `min-height:2200px`, protein `height:1600px`; **no `message` listener** on either page, and the two tools post different message shapes to `'*'`; canonical is apex host | FR1-21, UI1-10, DEC-15, DEC-31 |
| Protein tool | `/tools/protein-requirement-tool.html` (SHA-256 `a10d21b0e1c16bea5bf6e4fe8591b5b7ccb5c3bf273b733aa1f889059b1275ca`). Engine matches reference §4: `BANDS`, 0.4 adjusted-body-weight rule when BMI ≥ 30 and weight > upper-healthy weight, `DIET_MULT` 1.0/1.1/1.2, `PREG_GKG` 1.1/1.3, cap 2.0, per-meal clamp 20–40 g, renal `suppressed:true` early return, `renalUnsure` banner. Inputs: age 18–99, height 140–210 cm, sex, pregnancy (female), ethnicity (Asian cut-off 22.9 else 24.9), activity sedentary/light/moderate/vigorous, resistance 0/1-2/3-4/5+, goal maintain/fatloss/build/ageing/recovery, diet, meals 2–5, kidney no/yes/unsure, liver no/yes. POSTs flat JSON to `./submit-protein.php`. | CMP-02, CMP-04 |
| Diabetes tool | `/tools/diabetes-risk-tool.html` (SHA-256 `27c0e33ce3b98aea495a2f13fca112e32007d9d478ab30ee70401a39ab496292`). QDiabetes-2018 Model A: sex-specific `s0`, ethnicity (9), smoking (5), age and BMI fractional polynomials, BMI clamped 20–40, Townsend fixed 0, binaries htn/cvd/gdm/pcos/learn/psy/ster/stat/apsy, family history mother/father/sibling → none/one/both. Age as band midpoints 27…82. Bands 5.6/10/20. IDRS (age, waist, activity, family) tiers <30/<60/≥60. WHtR 0.5/0.6. Symptom layer: classic (thirst, polyuria, weight loss) → banner above score; emergency → banner and score suppressed on screen (client still POSTs riskPercent today, DEC-09 changes this). Methodology copy says "a UK deprivation score", not "Townsend" (DEC-07). POSTs JSON incl. riskPercent, riskBand, bmi, whtr, idrsScore, idrsTier, symptomatic, emergency, heightCm, weightKg, waistCm to `./submit.php`. | CMP-03, CMP-05 |
| Protected paths | `/tools/`, `/tools/api/config.php`, `/tools/api/templates-protein.php`, `/tools/api/mailer.php` → 403 (deny-all `.htaccess` in `api/`, `outbox/`, `state/`); `/tools/status.json` → 404 (does not exist yet) | NFR1-03, DEC-10 |
| Endpoints (from source) | Diabetes `submit.php`: POST only, optional origin allow-list, per-IP flat-file rate limit on `REMOTE_ADDR`, honeypot → silent 200, validation → 422, SMTP failure → 502; template chosen emergency → symptomatic → band, and the emergency template has no percentage token. Protein `submit-protein.php`: **no rate limit, no origin check**, honeypot → silent 200, `targetG` 20–400 checked when not suppressed, renal template chosen unconditionally when `suppressed`, send failure → 500. Neither checks `Content-Type` or body size. Shared `mailer.php` supports `transport: log` (writes whole emails to `api/outbox/`) and an optional `bcc_owner` copy | FR1-14, NFR1-03, NFR1-10, DEC-16, DEC-21, DEC-46 |
| Persistence | None in the database or logs; only rate-limit files (`md5(ip)` + timestamps) and, if `transport` is `log`, full emails in `api/outbox/`. Production `config.php` values are unknown (DEC-46) | NFR1-01, DEC-21 |
| WordPress platform | WordPress 7.1.3, Newspaper 12.7.9 with tagDiv Composer 5.4.8 and Cloud Library 3.9.6; 14 active plugins incl. Yoast SEO + Premium, LiteSpeed Cache 7.9.2, CookieYes 3.5.7, WP Mail SMTP, Link Whisper, WPForms Lite; permalinks `/%postname%/`; 1 administrator, 3 editors, 60 subscribers; 103 published and 102 unpublished posts; default category = Uncategorised | FR1-02, FR1-18, FR1-23, NFR1-01 |
| Menus | Header menu (16 items) includes Health Tools with the two tool pages and The Report; footer menu = About, Contact, Privacy Policy, Terms & Conditions | DEC-40, DEC-43 |
| LiteSpeed settings | Page cache on (public TTL 7 days, mobile cache on, no exclusions), image and **iframe** lazy-load on, CSS/JS minify and JS defer on, CDN and object cache off | DEC-31, FR1-20 |
| Hosting | Hostinger shared hosting behind `hcdn`; LiteSpeed Cache active (`X-LiteSpeed-Cache: hit`); host emits `Content-Security-Policy: upgrade-insecure-requests`; no HSTS, X-Robots-Tag or frame-ancestors. Deployment is an rsync overlay of `site/` (Data Model §6) | DEC-31, DEC-35 |
| Consent banner | CookieYes (`cookie-law-info`) banner active with an "Analytics" category defaulting off | DEC-11, DEC-32 |
| Taxonomy | 103 published posts, 22 categories (6 empty: `womens-health`, `stress`, `healthy-living`, `health_tools`, `the-report`, `uncategorised`), 100+ tags, 8 pages (inventory `reports_live_inventory.json`, 23 Oct 2026). Category slugs include `nutrition-for-healthy-living`, `healthy-eating-for-longevity`, `healthy-diet-plans-for-longevity`, `food-nutrition`, `fitness`, `health-conditions`, `metabolic-health`, `gut-health`; tags `diabetes` (6 posts), `blood-sugar` (1), `insulin-resistance` (1) | DEC-13 |
| Sitemap | 8 pages incl. hub and both landing pages; standalone `/tools/*.html` not listed, indexable | DEC-14 |
| Policies | Privacy, Terms, About, Contact exist; no medical disclaimer / urgent-care page | DEC-12 |
| Analytics in tools | No gtag/fbq/dataLayer/plausible in tool HTML or WordPress pages | FR1-16 |

## 4. Components

| ID | Component | Location | Owner of change |
|---|---|---|---|
| CMP-01 | WordPress core, Newspaper theme, posts, menus | WP staging clone | Frozen except menus/pages via WP admin or WP-CLI |
| CMP-02 | Protein tool page | `site/public_html/tools/protein-requirement-tool.html` | Shell/copy editable; engine frozen |
| CMP-03 | Diabetes tool page | `site/public_html/tools/diabetes-risk-tool.html` | Shell/copy editable; engine frozen |
| CMP-04 | Protein submit endpoint + template | `site/public_html/tools/submit-protein.php`, `site/public_html/tools/api/templates-protein.php` | Security/safety changes only |
| CMP-05 | Diabetes submit endpoint + template | `site/public_html/tools/submit.php`, `site/public_html/tools/api/templates.php` | Security/safety changes only |
| CMP-06 | Shared mailer and config | `site/public_html/tools/api/mailer.php`, `api/config.php` (server-side only, from environment; `config.example.php` in Git) | Change only with both-tool regression (NFR1-04) |
| CMP-07 | Tool status file | `site/public_html/tools/status.json` | New (DEC-10) |
| CMP-08 | `lld-tools` must-use plugin | `site/public_html/wp-content/mu-plugins/lld-tools/` | New (DEC-22) |
| CMP-09 | Homepage, hub page, two landing pages, medical disclaimer page | WP pages | New/edited content via plugin shortcodes |
| CMP-10 | Web server config | `site/public_html/tools/.htaccess` and a managed `# BEGIN lld` block in the root `.htaccess`: headers, CSP, cache control | New (DEC-14/16/28) |
| CMP-11 | Analytics (Plausible) | script tag in theme header + event calls in CMP-02/03/08 | New (DEC-11) |
| CMP-12 | Repository artefacts: manifests, approvals, fixtures, evidence, link map | `repo/` (see 04) | New |
| CMP-13 | CI and deployment | `.github/workflows/` (ci, deploy-staging, deploy-production) and `deploy/` | New |
| CMP-14 | Independent oracles ORC-01 (R/QDiabetes), ORC-02 (Python protein reference), ORC-03 (IDRS from Mohan 2005), ORC-04 (WHtR cut-offs) | `oracles/` | New (FR1-22) |

## 5. Actors

| ID | Actor | Permitted | Not permitted |
|---|---|---|---|
| ACT-01 | Public visitor | Read, use both tools, request result email | Access another visitor's result; no account exists |
| ACT-02 | WordPress editor/publisher | Edit content, menus, CTAs, review metadata | Change engine or clinical copy hashes |
| ACT-03 | Clinical reviewer | Sign HG-02; approve DEC rows marked HG-02 | Edit code |
| ACT-04 | Site administrator / release owner | Sign HG-01, HG-03; toggle `status.json`; deploy | Bypass CI gates |
| ACT-05 | AI implementer agent | Everything in 04 §4 on staging and repo | Deploy to production; edit `expected` values; edit `ENGINE`/`BANDS` without ratified DEC |
| ACT-06 | AI reviewer agent | Read-only review, run tests, emit PASS/FAIL | Edit implementation or tests |
| ACT-07 | AI oracle agent (sealed) | Write ORC-02 from the reference document | Read tool HTML/JS |
| ACT-08 | External services (SMTP, Hostinger/LiteSpeed/hcdn, Plausible, CookieYes) | Operate under configuration | Receive health values (analytics) |

## 6. Use cases

Each UC maps 1:1 to an E2E journey of the same number in `03_Test_Criteria_Stage1_v1_2.md` §7.

### UC1-01 Visitor uses the protein tool from a campaign link
Actor ACT-01. Main flow: open `/health-tools/protein/?utm_source=facebook&utm_campaign=X` → landing copy (purpose, eligibility, inputs, limitations, privacy) → iframe tool → enter eligible inputs → result with headline, interpretation, sources, next step → optional email. Variations: V1 invalid units/ranges → inline error, no result; V2 age 17 → ineligible message; V3 kidney = yes → no numeric output, renal advice; V4 kidney = unsure → caution above result; V5 email declined → result still shown; V6 tool withdrawn → unavailable state. Requirements: FR1-03, FR1-04, FR1-07, FR1-08, FR1-09, FR1-13, FR1-14, FR1-16, UI1-04, UI1-05, UI1-06, UI1-08.

### UC1-02 Visitor uses the diabetes risk check
Actor ACT-01. Main flow: landing page → tool → inputs incl. optional waist and medical history → result: % + band + interpretation + secondary panel + methodology + email. Variations: V1 emergency flag → urgent banner, no %; V2 classic symptom → symptom banner above %; V3 no waist → secondary panel shows "add waist" note; V4 band low but IDRS high → concordance note; V5 withdrawn → unavailable. Requirements: FR1-10, FR1-11, FR1-12, FR1-13, FR1-14, FR1-15, UI1-05, UI1-08.

### UC1-03 Editor links an article to a tool
Actor ACT-02. Main flow: open post in category `nutrition-for-healthy-living` → CTA block appears automatically from link map → preview at 375/1440 → publish → crawler confirms link and canonical. Variations: V1 post in unrelated category → no CTA; V2 editor sets `lld_tool_override=none` → CTA removed; V3 editor sets override to other tool → CTA switches; V4 contributor role → cannot publish. Requirements: FR1-02, FR1-05, FR1-18, UI1-07.

### UC1-04 Clinical reviewer withdraws a tool
Actors ACT-03, ACT-04. Main flow: reviewer raises incident (SCH-11) → admin sets `status.json` diabetes → `withdrawn` → within 60 s hub card, landing page, direct URL, iframe and `submit.php` all show/return unavailable → other tool unaffected → restore after reapproval. Variations: V1 cached copy served → test fails release; V2 restore with a version hash not in an approval record → blocked. Requirements: FR1-06, FR1-15, FR1-20, NFR1-11, UI1-08.

### UC1-05 Publisher measures a campaign
Actors ACT-02, ACT-08. Main flow: publish social post with UTM link → visitors land, click, complete → Plausible shows counts by utm_campaign for events `tool_landing_view`, `tool_start`, `tool_complete`, `article_cta_click`. Variations: V1 visitor blocks scripts → no event, tool still works; V2 any health field appears in an event → test fails. Requirements: FR1-16, NFR1-01.

### UC1-06 Keyboard and screen-reader visitor completes both tools
Actor ACT-01 using keyboard only and a screen reader. Main flow: homepage → Tools section → landing page → tool: every control reachable in logical order, labels announced, validation errors announced via `role=alert`, safety banners announced first, result headings in order. Requirements: NFR1-05, UI1-04, UI1-05, FR1-17.

### UC1-07 Release owner ships Stage 1
Actor ACT-04. Main flow: all MUST items `accepted` → P-E release candidate → backup → restore drill in staging → HG-03 → deploy → 24 h smoke. Variations: V1 smoke fails → rollback within RTO. Requirements: FR1-19, NFR1-08, NFR1-09, NFR1-13.

## 7. Requirement format

Status lifecycle: `draft → ready → in_progress → review → verified → accepted → released` (`blocked` from any active state). `verified` = automated assertions green and reviewer PASS; it satisfies dependencies. `accepted` additionally requires the human-gate artefact for hybrid items (see 04 §2). The `decisions`, `fixtures` and `mutations` lists in the registry are authoritative; the DEC references in the requirement headers below are a convenience and are regenerated from the registry (DEC-38). Each requirement has: **ID, title, priority** (MUST = release blocker; SHOULD = may be deferred by a DEC row), **type** (`behaviour` = code; `process` = artefact), **automation** (`auto` = fully automated; `hybrid` = automated plus a human gate), **depends_on**, **components**, **must_not_touch**, **statement**, **acceptance** as automated assertions (full detail in TC page) and **human gate**, plus **fixtures**, **decisions**, **use cases**. The registry JSON holds the same fields.

---

## 8. Functional requirements

### FR1-01 Existing implementation audit and characterisation baseline — MUST, process, hybrid
depends_on: — · components: CMP-01…CMP-06 · must_not_touch: all code · UC: all · TC-FR1-01 · HG-01

Statement. Produce the AS-IS report: inventory of WordPress (version, plugins, theme version, caching layer, server), both tool HTML files, both PHP endpoints, shared mailer/config, server headers, DNS/host behaviour, email transport, any persistence (files, DB tables, third-party CRM), logs and retention. Hash every file (SHA-256). Record characterisation outputs for every fixture in `fixtures/*.json` (`oracle: characterisation`), visual baselines at 320/375/768/1440 for homepage, hub, both landing pages and both tools, and a crawl of the 30-URL sample (section 13). Mark each v1.1 and reference-document statement as confirmed, corrected or unknown.

Acceptance. AS-01 `reports/as-is/inventory.json` validates against SCH-02 and lists ≥ 2 tool manifests with hashes matching served files. AS-02 every fixture file has `expected` filled with `oracle: characterisation` and `source_version` equal to the manifest hash. AS-03 visual baseline PNGs exist for 6 pages × 4 widths. AS-04 crawl report lists 30 URLs with status 200 and canonical. AS-05 discrepancy ledger has zero rows with `status: unclassified`. HG-01 ratifies.

### FR1-02 Preserve WordPress and Newspaper — MUST, behaviour, auto
depends_on: FR1-01 · components: CMP-01 · must_not_touch: theme files, posts · UC1-03 · TC-FR1-02

Statement. Keep the live WordPress installation, Newspaper theme, articles, media, menus, metadata, URLs and editorial roles. All work happens in a staging clone; no theme replacement, no theme file edits (new behaviour lives in CMP-08). The only permitted taxonomy and menu changes are those enumerated in FR1-23 (DEC-39…42) and the Health Tools menu items; the regression crawl treats those URLs as expected redirects, not failures.

Acceptance. AS-01 before/after crawl of the 30-URL sample: identical status codes, canonical, title, meta description, `robots`, H1 and word count ± 2 %. AS-02 sitemap diff: no removed URLs other than the FR1-23 set (four deleted archives, which must 301 to their DEC targets, and `the-report`, which leaves the sitemap when marked noindex). AS-03 `git diff` of `wp-content/themes/` is empty. AS-04 media sample (20 images) returns 200. AS-05 WP roles and capabilities unchanged (WP-CLI `role list` diff empty).

### FR1-03 Tools-first homepage — MUST, behaviour, auto
depends_on: FR1-01, FR1-04 · components: CMP-08, CMP-09 · UC1-01, UC1-02 · TC-FR1-03 · DEC-23, DEC-30

Statement. Add a "Tools" row rendered by `[lld_tools_hub compact=1]` showing the two active tool cards, placed directly below the header on mobile and below the hero on desktop, followed by a "Learn" row of latest articles. Site search and category navigation remain.

Acceptance. AS-01 at 375 px the Tools row's top edge is within the first 1.5 viewport heights (≤ 1218 px from top) before any scroll. AS-02 each tool landing page is reachable from `/` in ≤ 2 actions (DEC-30) on 375 and 1440, measured by Playwright click path. AS-03 a post published in the last 30 days is reachable in ≤ 3 actions. AS-04 search form present and returns results for "protein". AS-05 headings "Tools" and "Learn" exist as `h2` in that order. AS-06 no console errors; no broken links on `/`. AS-07 DOM order: at 375 px the Tools row is the first content section after the header; at 1440 px it immediately follows the hero block (DEC-23).

### FR1-04 Tools hub and landing pages — MUST, behaviour, auto
depends_on: FR1-01 · components: CMP-08, CMP-09, CMP-07 · UC1-01, UC1-02 · TC-FR1-04 · DEC-24, DEC-26

Statement. Replace the current hub iframe on `/health-tools/` with `[lld_tools_hub]` rendering exactly two active cards whose status comes from `status.json`; each card shows name, one-line purpose, time to complete, inputs needed, eligibility, status label, CTA and one linked reviewed article. Each landing page has sections, in order: Purpose, Who it is for (eligibility), What you will enter, the tool embed (headed "Check your Diabetes Risk" or "Calculate My Protein Need"), Limitations, How it works (methodology summary with source and version), Privacy, Related reading (owner decision DEC-85). Future tools may appear only as `label_only` chips with no link.

Acceptance. AS-01 hub DOM has exactly 2 `[data-tool-card][data-status=active]`. AS-02 each active card's CTA resolves 200 to its landing page. AS-03 zero anchors inside `[data-status=label_only]`. AS-04 both landing pages contain the 8 section headings in order (`h2` text match). AS-05 methodology section names the engine and the manifest `deployed_version`. AS-06 card data equals `content/tool-cards.json` (SCH-04).

### FR1-05 Blog-to-tool and tool-to-blog linking — MUST, behaviour, auto
depends_on: FR1-04 · components: CMP-08 · UC1-03 · TC-FR1-05 · DEC-13

Statement. Generate `content/article-tool-links.json` (SCH-05b) by the DEC-13 slug-based rule (tag rule first, then category rule), honour per-post override meta `lld_tool_override` (`protein|diabetes|none`), and render `[lld_tool_cta]` automatically after the first `h2` (or at the end) of qualifying published posts. Each landing page's Related reading lists up to 3 reviewed posts from the link map with `review_status: approved`. Article text is not modified.

Acceptance. AS-01 for every published post the rendered CTA presence equals the link-map decision (crawl all posts). AS-02 posts with override `none` have no CTA; override `protein` shows protein CTA. AS-03 CTA links resolve 200 with `?utm_source=article&utm_content=<post_id>`. AS-04 draft/private posts never appear in Related reading. AS-05 post content hash excluding the CTA block is unchanged. AS-06 CTA renders without horizontal overflow at 320 px.

### FR1-06 Clinical method provenance — MUST, process, hybrid
depends_on: FR1-01 · components: CMP-12 · TC-FR1-06 · HG-02 · DEC-25

Statement. Maintain one methodology approval record per tool (SCH-03) referencing the exact file hash, engine object hashes, intended use, eligibility, sources, limitations, regulatory position statement, reviewer and dates. Records exist from wave 1 with `clinical_signoff.decision: pending`; whenever a shell change alters the file hash while `engine_hashes` are unchanged, the implementer updates `version_hash` in the record in the same PR. `provenance-check` on **staging** accepts `pending` or `approved`; on **production** it requires `approved` (set at HG-02). Assertions that compare a page or copy hash to the approval record compare to the record's current value regardless of sign-off state; the sign-off itself is checked by `clinical-gate`.

Acceptance. AS-01 `approvals/protein.json` and `approvals/diabetes.json` validate against SCH-03. AS-02 CI job `provenance-check` fails when the staged file hash ≠ approved hash (tested by MUT-10). AS-03 landing page methodology section displays `deployed_version` equal to the approval record. AS-04 `git log` of engine objects shows no change without a linked DEC. HG-02 signs the records.

### FR1-07 Protein engine preservation and verification — MUST, behaviour, hybrid
depends_on: FR1-01, FR1-22 · components: CMP-02 · must_not_touch: `BANDS`, `DIET_MULT`, `PREG_GKG`, `computeProtein`, `clampMeal`, `cutoffs` · UC1-01 · TC-FR1-07 · HG-02 · DEC-03

Statement. Preserve the deployed protein engine exactly. Verify it against ORC-02 for all FX-PR fixtures: basis weight (actual vs adjusted), band collection and highest-band rule, age ≥ 65 bands, training flag, goal bands, diet multiplier, 2.0 cap, per-meal clamp, unit conversion, validation ranges 18–99 / 140–210 cm / weight range, 18 and 99 boundaries.

Acceptance. AS-01 engine unit tests pass for all FX-PR-NRM, FX-PR-BMI, FX-PR-AGE, FX-PR-CAP, FX-PR-UNIT fixtures against characterisation values. AS-02 ORC-02 agreement: targetG, lowG, highG, perMeal within ±1 g and gPerKg exact at 2 dp for every fixture, except fixtures listed under a DEC row with a preserve-deployed default (`known_deviations` in the registry: FX-PR-CAP-01, CAP-02 → DEC-44), which are compared to characterisation only and recorded in evidence as `known_deviation: DEC-44` until the row is ratified or overridden; any other disagreement creates a DEC row and blocks. AS-03 invalid fixtures FX-PR-INV produce an error message and no result in the DOM. AS-04 imperial fixtures yield the same result as their metric twins. AS-05 hash of engine objects unchanged from manifest. AS-06 basis-weight arithmetic; AS-07 per-meal clamp (see TC). AS-08 every `BANDS` row's lo/hi and cited source are consistent with the primary-source table in the reference document §2 (DEC-03); mismatches become DEC rows. HG-02 signs the ORC-02 report and the band-table check.

### FR1-08 Protein renal suppression — MUST, behaviour, hybrid
depends_on: FR1-01 · components: CMP-02, CMP-04 · must_not_touch: renal early return · UC1-01 · TC-FR1-08 · SAF-PR-01, SAF-PR-02 · HG-02 (copy)

Statement. When kidney = yes: no numeric protein value (target, range, g/kg, per-meal) in the DOM, the POST payload, the sent email, any log or any analytics request; `MESSAGES.renalStop` is shown. When kidney = unsure: `MESSAGES.renalUnsure` banner precedes the result. Two-layer enforcement: client `suppressed` flag and server-side unconditional selection of the renal template.

Acceptance. AS-01 unit: FX-PR-REN-01…04 → `suppressed === true`, no numeric fields. AS-02 E2E: result container text matches no `/\d+(\.\d+)?\s?(g|gram|g\/kg)/`. AS-03 captured POST has `suppressed:true` and null numeric fields. AS-04 server test: POST with `suppressed:true` but forged numeric fields → sent email (Mailpit) contains no digits followed by g/gram and uses renal template. AS-05 no outbound request except `submit-protein.php` during the flow carries numeric result fields. AS-06 FX-PR-UNS-01 → `renalUnsure` banner visible, `role=alert`, precedes result in DOM order. MUT-01, MUT-02 must turn tests red.

### FR1-09 Protein life-stage and safety branches — MUST, behaviour, hybrid
depends_on: FR1-07 · components: CMP-02 · UC1-01 · TC-FR1-09 · SAF-PR-03 · HG-02 (copy)

Statement. Pregnancy 1.1 g/kg and lactation 1.3 g/kg are computed on entered (pre-pregnancy) weight, returned before the standard path, never combined with activity/training/goal bands; `MESSAGES.pregnancy` banner shown. Liver = yes or goal = recovery adds the ESPEN 1.2–1.5 band and `MESSAGES.clinical` banner. BMI < 18.5 computes on actual weight with `MESSAGES.underweight` and `MESSAGES.eatingConcern` wording, no deficit language.

Acceptance. AS-01 FX-PR-PRG-01…04 (pregnant/lactating × resistance 5+ / goal build) → gPerKg exactly 1.1 or 1.3, `branch: pregnancy`. AS-02 FX-PR-LIV-01 → sources include ESPEN, clinical banner visible. AS-03 FX-PR-UWT-01 → underweight banner, result text contains none of the words in `fixtures/forbidden-terms.json` (deficit, lose, cut). AS-04 ORC-02 agreement on all FX-PR-PRG. MUT-03 turns AS-01 red.

### FR1-10 Diabetes engine fidelity verification — MUST, behaviour, hybrid
depends_on: FR1-01, FR1-22 · components: CMP-03 · must_not_touch: `ENGINE`, `bandFor`, `idrs`, `whtr` · UC1-02 · TC-FR1-10 · HG-02 · DEC-01, DEC-02, DEC-08

Statement. Confirm by test, not by reading, that the deployed `ENGINE.QDR2018A` reproduces the published QDiabetes-2018 Model A: for every FX-DM-QD fixture, the JS output must equal ORC-01 (R `QDiabetes` 1.0-2 from the CRAN Archive, `QDR2018A`, `tds=0`, mapping per DEC-33) within 0.05 percentage points compared **unrounded**. Record the algorithm licence (CC BY 4.0), the package licence (AGPL-3, research use) and attribution text in the approval record. If any fixture fails, CI sets diabetes `status: withdrawn` on **staging** and opens a DEC row; production withdrawal is applied by ACT-04 within 4 h (DEC-08).

Acceptance. AS-01 ORC-01 report shows 100 % of FX-DM-QD within tolerance (74 cases). AS-02 BMI clamp: FX-DM-QD-CLAMP (BMI 19 and 41) equal outputs at 20 and 40. AS-03 attribution and licence text present in methodology panel. AS-04 CI step `fidelity-gate` writes **staging** `status.json` withdrawn on failure (tested by MUT-04 in a sandbox copy) and raises an alert; no production credential is available to CI. AS-05 ORC-01 pins package tarball SHA-256 and R version. HG-02 signs.

### FR1-11 Diabetes symptom escalation — MUST, behaviour, hybrid
depends_on: FR1-01 · components: CMP-03, CMP-05 · must_not_touch: priority logic in render · UC1-02 · TC-FR1-11 · SAF-DM-01, SAF-DM-02 · HG-02 (copy) · DEC-09

Statement. Emergency flag → `MESSAGES.emergency` banner first, headline "Risk estimate paused", no percentage or band anywhere on screen, in the POST payload (client nulls `riskPercent`/`riskBand`, DEC-09) or in email (server selects `diabetes_emergency` template regardless of payload). Any classic symptom (thirst, polyuria, weight loss) → `MESSAGES.symptomatic` banner above the headline; percentage still shown below. Non-classic symptoms alone do not trigger the banner. The email form never has higher visual priority than the banner.

Acceptance. AS-01 FX-DM-EMG-01 (emergency + low-risk inputs): banner `.banner.emergency` is first child of banners zone; DOM contains no `%` in `#headlineZone`; `riskNum` absent. AS-02 POST for FX-DM-EMG-01 → email body contains no `%` and contains the urgent-care sentence; intercepted client POST has `riskPercent: null`, `riskBand: null`. AS-03 FX-DM-SYM-01 (thirst only, low risk): `.banner.symptom` precedes `#headlineZone`; percentage present. AS-04 FX-DM-SYM-02 (fatigue only): no symptom banner. AS-05 banner `getBoundingClientRect().top` < email card top at 375 and 1440. MUT-05 turns AS-01 red.

### FR1-12 Diabetes interpretation and secondary indicators — MUST, behaviour, hybrid
depends_on: FR1-10 · components: CMP-03 · UC1-02 · TC-FR1-12 · SAF-DM-03, SAF-DM-04 · HG-02 · DEC-04…07, DEC-29

Statement. Bands: <5.6 low, 5.6–<10 moderate, 10–<20 high, ≥20 very high. IDRS per ORC-03 with tiers <30/30–59/≥60. WHtR per ORC-04 with <0.5 healthy, 0.5–<0.6 raised, ≥0.6 high. Concordance note when band = low and (IDRS = high or WHtR = high). Without waist, secondary panel shows `MESSAGES.secondary.needWaist`. Methodology panel discloses QDiabetes-2018 Model A, UK calibration caveat, the fixed deprivation score (live wording "a UK deprivation score … neutral average", DEC-07), symptom-override design, type 2 only, not a diagnosis, source version.

Acceptance. AS-01 FX-DM-BAND-01…12 (inputs engineered by the ORC-01 runner to straddle 5.6, 10 and 20 % for reachable profiles) map to the expected band label. AS-02 FX-DM-IDRS-01…06 (scores 20/30/50/60/0/70) → tier per ORC-03; the score-50 case detects MUT-08. AS-03 FX-DM-WHTR-01…04 (0.49/0.50/0.59/0.60) → band per ORC-04. AS-04 FX-DM-CONC-01 → concordance note visible; FX-DM-CONC-02 (low/low) → absent. AS-05 FX-DM-NOWAIST-01 → needWaist text, no IDRS/WHtR values. AS-06 methodology text contains every `methodology.*` phrase in `fixtures/required-phrases.json`. AS-07 no "waist-hip" string anywhere in CMP-03 or CMP-09. AS-08 result shows source and version. AS-09 `bandFor` unit test at 5.59/5.60/9.99/10.00/19.99/20.00 → low/moderate/moderate/high/high/very high (classification on the unrounded value, DEC-04).

### FR1-13 Guest access without account — MUST, behaviour, auto
depends_on: FR1-01 · components: CMP-02, CMP-03 · UC1-01, UC1-02 · TC-FR1-13

Statement. Both tools complete without sign-in, cookies or local storage of inputs/results. A page reload shows an empty form. No result is retrievable by URL.

Acceptance. AS-01 Playwright incognito contexts complete FX-PR-NRM-01 and FX-DM-QD-01. AS-02 after completion, `document.cookie` has no new cookies and `localStorage`/`sessionStorage` lengths are 0 for the tool origin. AS-03 reload → result container hidden, inputs empty. AS-04 result URL has no query/hash containing inputs or results.

### FR1-14 Result email path — MUST, behaviour, hybrid
depends_on: FR1-01, FR1-08, FR1-11 · components: CMP-04, CMP-05, CMP-06 · UC1-01, UC1-02 · TC-FR1-14 · DEC-16, DEC-21, DEC-27 · HG-03 (DEC-21)

Statement. Keep the two separate endpoints and their payload keys. Close the gaps found in the audit: add the shared rate limit and origin allow-list to the protein endpoint, add `Content-Type` and body-size checks (≤ 8 KB, JSON only) to both, range-check `perMeal`, `meals`, `gPerKg`, `bmi` and measurements per Data Model §4, and return 502 (not 500) on send failure in both. Server validates: email RFC 5322 syntax, `consent === true`, `company` empty, tool enum. Reject otherwise with 400 JSON on both endpoints (the diabetes endpoint's current 422 changes to 400). Rate limit per DEC-16 using the CDN forwarded client IP (DEC-35). Honeypot per DEC-16. Templates escape all interpolated values. No marketing list, no persistence beyond the SMTP transaction (if audit finds persistence → DEC-21 blocked). Delivery failure → 502 JSON and friendly UI message.

Acceptance. AS-01 valid POST (FX-MAIL-01/02) → 200, one message in Mailpit with expected template. AS-02 NEG: missing consent, bad email, non-empty honeypot (200 but no mail), oversize body, wrong content-type → expected status, zero mail. AS-03 6th POST within 10 min from same IP → 429. AS-04 payload with `<script>` in firstName → email HTML has it escaped. AS-05 SMTP down → 502 and UI shows `MESSAGES.emailFailed`; calculator result still visible. AS-06 server log lines for the run validate against SCH-07 field by field and contain no sentinel value, email address or symptom key. AS-07 FX-MAIL-REN-01 → renal template; FX-MAIL-EMG-01 → emergency template.

### FR1-15 Safe withdrawal and rollback — MUST, behaviour, auto
depends_on: FR1-04, FR1-20 · components: CMP-07, CMP-02, CMP-03, CMP-04, CMP-05, CMP-08 · UC1-04 · TC-FR1-15 · SAF-WITHDRAW-01 · DEC-10, DEC-24

Statement. `status.json` (SCH-08) on the server is the source of truth and controls each tool: `tools.<slug>.status ∈ {active, withdrawn}` with `message`, `since`, `approved_version`; planned tools appear only as `labels[]` entries with `label_only` (DEC-24). It is excluded from deploys; its only writer is `wp lld status set`, which validates the version against the approval records, writes the file and purges the configured LiteSpeed targets (DEC-10). Tool JS fetches it (`cache: 'no-store'`) before rendering and shows the unavailable state when not active; PHP endpoints return 503 JSON when not active; the plugin renders an unavailable card instead of the iframe; hub card shows the status label. Restore requires `approved_version` to equal a hash in an approval record (CI check). The runbook records the one-line toggle command and the LiteSpeed purge step (DEC-31). CI may toggle staging only; production is toggled by ACT-04 (DEC-08).

Acceptance. AS-01 set diabetes withdrawn → within 60 s: hub card label "Temporarily unavailable", landing page has no iframe, direct `/tools/diabetes-risk-tool.html` shows unavailable state and no form, `submit.php` → 503; protein unchanged (FX-PR-NRM-01 passes). AS-02 restore → all routes active, FX-DM-QD-01 passes. AS-03 `wp lld status set diabetes active --version=<hash not in approvals>` exits non-zero and leaves `status.json` unchanged. AS-04 unavailable state links to `/health-tools/` and one educational article. AS-05 `status.json` response header `Cache-Control: no-store`.

### FR1-16 Privacy-preserving conversion measurement — SHOULD, behaviour, hybrid
depends_on: FR1-04, FR1-05 · components: CMP-11, CMP-02, CMP-03, CMP-08 · UC1-05 · TC-FR1-16 · DEC-11 · HG-03

Statement. Plausible events with the allow-list in SCH-09: `tool_landing_view`, `tool_start`, `tool_complete`, `tool_unavailable_view`, `article_cta_click`, `email_sent` (count only), each with props `tool` and `source` (`article|hub|home|nav|campaign|direct`). UTM handled by Plausible. No other props. The existing CookieYes banner is kept (DEC-32); Plausible loads as a cookieless script, or under the banner's Analytics category if the privacy reviewer so decides at HG-03 (DEC-11). No events fire when the script is blocked and tools still work.

Acceptance. AS-01 during FX-PR-REN-01, FX-DM-EMG-01 and FX-DM-QD-01 flows, every outbound request to the analytics host is captured; union of all query strings and bodies contains none of the values in `fixtures/sentinel-values.json` (unique synthetic numbers and strings entered) and no key outside SCH-09. AS-02 each event fires exactly once per action. AS-03 with analytics host blocked, flows complete and no console error above warning. AS-04 Plausible API (or stub) reports counts equal to the test's actions.

### FR1-17 Policies, education and safe navigation — MUST, behaviour, hybrid
depends_on: FR1-04 · components: CMP-09, CMP-02, CMP-03, CMP-08 (DEC-76) · UC1-06 · TC-FR1-17 · DEC-12 · HG-02 (disclaimer copy)

Statement. The five DEC-12 pages are reachable by keyboard from each landing page (all five) and from each tool result zone (disclaimer and privacy links in the result zone; the standalone `/tools/*.html` pages gain a minimal footer with all five links). The WordPress footer keeps its four existing links and is otherwise unchanged (DEC-43). The new `/medical-disclaimer/` page covers: screening not diagnosis, when to seek urgent care (UK 999 / NHS 111 first, then Sri Lanka 1990, India 112, "or your local emergency number", DEC-37), tool limitations, and contact. Copy is drafted by the agent and approved per DEC-36. Result zones of both tools state "not a diagnosis" and link to the disclaimer.

Acceptance. AS-01 the five URLs return 200; all five are reachable by keyboard (Tab) on each landing page and on each standalone tool page; the result zone contains links to the disclaimer and privacy pages; the WordPress footer link set equals the baseline (About, Contact, Privacy Policy, Terms & Conditions). AS-02 result zone of both tools (any fixture) contains the phrase from `required-phrases.json#notDiagnosis` and a link to `/medical-disclaimer/`. AS-03 emergency fixture result contains the urgent-care phrase and a link to the disclaimer's urgent-care anchor. AS-04 copy hash of `/medical-disclaimer/` equals the approved hash in `approvals/copy.json` (after HG-02).

### FR1-18 Editorial ownership and review metadata — MUST, behaviour, auto
depends_on: FR1-04 · components: CMP-08, CMP-01 · UC1-03 · TC-FR1-18

Statement. Plugin adds post/page meta `lld_review_owner`, `lld_reviewed_date`, `lld_next_review_date`, `lld_tool_override`, `lld_review_status` (`approved|pending|superseded`), editable by editors and above. Landing pages and Related-reading posts display "Reviewed by {owner} on {date}". WP-CLI command `wp lld review-report` lists items with `next_review_date < today` or status ≠ approved. Standard WP roles unchanged: contributor drafts, editor publishes, subscriber nothing.

Acceptance. AS-01 meta fields present on both landing pages and visible in rendered HTML. AS-02 `wp lld review-report` output includes a seeded stale post and excludes a current one. AS-03 role tests: contributor cannot publish (WP-CLI `--user` simulation returns error), editor can. AS-04 Related reading excludes posts with `lld_review_status != approved`.

### FR1-19 Staging and production release — MUST, process, hybrid
depends_on: all MUST · components: CMP-13 · UC1-07 · TC-FR1-19 · HG-03

Statement. P-E runs when every MUST requirement is at least `verified`. Release candidate = tagged commit with release record (SCH-10) listing tool hashes, approval IDs, backup ID, evidence IDs for every MUST requirement, rollback command. HG-03 moves hybrid items to `accepted`; G-06 requires every MUST `accepted`. Deployment is the `deploy-production` workflow, approved by ACT-04 in the protected environment. Post-deploy smoke suite runs at +0 h, +1 h, +24 h.

Acceptance. AS-01 `releases/<tag>.json` validates against SCH-10 and, for every MUST ID, references an evidence bundle whose `results[].outcome` are all `pass` and `reviewer.verdict` is `PASS`, with registry status ≥ `verified`. AS-02 restore drill log (NFR1-08) dated within 7 days of the tag. AS-03 smoke suite (hub, both landing pages, both tools happy path, both emails to Mailpit-equivalent sink or seeded inbox, status.json) passes at all three times. AS-04 rollback drill: deploy previous tag to staging and smoke passes.

### FR1-20 Cache control and purge — MUST, behaviour, auto
depends_on: FR1-01 · components: CMP-10, CMP-07 · UC1-04 · TC-FR1-20 · DEC-10

Statement. `status.json`: `Cache-Control: no-store`. Tool HTML: `Cache-Control: no-cache, must-revalidate` with `ETag`. PHP endpoints: `Cache-Control: no-store`. LiteSpeed Cache is live: `/tools/*` is excluded from page cache and the iframe from lazy-load; the runbook includes `wp litespeed-purge` for hub and landing pages; the withdrawal test runs through the cache (DEC-31).

Acceptance. AS-01 headers as specified on the four paths. AS-02 after toggling status, a second request within 60 s through the full stack (LiteSpeed included) returns the new state; `X-LiteSpeed-Cache` is absent or `miss` on `/tools/*`. AS-03 tool HTML modified on staging → next request returns new ETag.

### FR1-21 Standalone tool page hardening — MUST, behaviour, auto
depends_on: FR1-01 · components: CMP-02, CMP-03, CMP-09, CMP-10 · UC1-01, UC1-02 · TC-FR1-21 · DEC-14, DEC-15, DEC-28

Statement. Iframe `src` uses the environment's configured origin (apex on production) and is excluded from LiteSpeed lazy-load (DEC-31). Both tools post one message schema `{type:'lld-tool-height', height}` to the configured origin only, substituted from the `__LLD_ORIGIN__` placeholder at deploy time (DEC-34); the `lld-tools` parent listener verifies `event.origin`. Fixed `height`/`min-height` removed; height set from messages with a 600 px fallback. `<noscript>` fallback link to the standalone page. `/tools/*.html` carry `<link rel="canonical">` to the landing page; no `noindex` (DEC-14). CSP per DEC-28; HSTS per DEC-35.

Acceptance. AS-01 landing HTML iframe has a real `src="<configured origin>/tools/…"` (not `about:blank`/`data-litespeed-src`). AS-02 tool JS source contains no `postMessage(…, '*')`; parent listener rejects a message from a different origin (unit test). AS-03 at 320/375/768/1440 the iframe height equals content height ± 20 px after result render; no inner scrollbar. AS-04 `/tools/*.html` has no `noindex` header or meta; HTML has canonical to the landing page. AS-05 CSP headers present as DEC-28; a test page on another origin cannot frame the tool (Playwright reports blocked).

### FR1-22 Independent oracles — MUST, process, auto
depends_on: FR1-01 · components: CMP-14 · TC-FR1-22 · DEC-02, DEC-03

Statement. ORC-01: Dockerised R script using `QDiabetes` 1.0-2 installed from the CRAN Archive tarball (SHA-256 pinned; package archived on CRAN June 2026) producing `QDR2018A` risk for each FX-DM-QD input per DEC-33 (tds 0, BMI from height/weight then clamp, age = band midpoint, `fhdm = any(family)`, argument `gdm`). ORC-02: Python module implementing Protein-Tool-Engine-Reference §4–§5, written by ACT-07 in a sealed session whose transcript proves no access to CMP-02. ORC-03: IDRS per Mohan et al. 2005 points table with the live 4-level activity mapping (characterisation for that item, DEC-05). ORC-04: WHtR cut-offs. Each oracle writes `oracles/<id>/results.json` (SCH-06) consumed by TC-FR1-07/10/12.

Acceptance. AS-01 oracle containers build and run from clean checkout in CI. AS-02 ORC-02 session log exists with file-access list excluding `*.html`. AS-03 each oracle result file validates against SCH-06 and covers every fixture ID in its scope. AS-04 oracle versions pinned (R package version, Python hash) and recorded in results.

### FR1-23 Category tidy-up — MUST, behaviour, auto
depends_on: FR1-01 · components: CMP-01, CMP-08, CMP-10 · UC1-03 · TC-FR1-23 · DEC-39, DEC-40, DEC-41, DEC-42 · HG-01

Statement. Apply exactly these editorial changes via WP-CLI on staging, scripted and re-runnable (`wp lld tidy apply`), after the baseline crawl: delete `womens-health`, `healthy-living`, `stress` (all 0 posts) with 301 redirects to `/category/wellness/`; set the default category to `wellness` then delete `uncategorised` with a redirect to `/`; remove `the-report` from the primary menu and set its archive to `noindex`, keeping the term; record `healthy-living` and `stress` in `content/planned-categories.json` with their slugs and parent. No post changes its categories as a result. The footer is not changed.

Acceptance. AS-01 after `wp lld tidy apply`, `wp term list category` lacks the four deleted slugs and `the-report` remains. AS-02 the four deleted archive URLs return 301 to their DEC targets; the `the-report` archive returns 200 with `noindex`. AS-03 primary menu has no item linking to `the-report`. AS-04 every post's category set is byte-identical to the baseline inventory. AS-05 `wp option get default_category` is the `wellness` term ID. AS-06 `content/planned-categories.json` validates and lists the two slugs. AS-07 footer link set equals baseline (DEC-43).

---

## 9. Non-functional requirements

### NFR1-01 Privacy and special-category data — MUST, process, hybrid
depends_on: FR1-01 · TC-NFR1-01 · DEC-11, DEC-20, DEC-21 · HG-03

Statement. Data-flow inventory (SCH-12) listing every field in both POST payloads, email templates, logs and analytics with classification (`identifier|health|technical`), destination, lawful basis, retention. No new health datastore. DPIA screening checklist completed.

Acceptance. AS-01 `privacy/data-flow.json` validates against SCH-12 and contains every key found by static scan of both payload builders and both templates. AS-02 every `health` field has destination ∈ {`screen`, `smtp`} only. AS-03 sentinel scan: during the three sentinel flows no request to any host other than the site origin contains a sentinel value, whether or not analytics is installed (holds even if FR1-16 is deferred). AS-04 DPIA checklist file complete (no `TODO`). HG-03 signs.

### NFR1-02 Clinical governance — MUST, process, hybrid
depends_on: FR1-06, FR1-07, FR1-10, FR1-12 · TC-NFR1-02 · HG-02

Statement. Named clinical reviewer; signed approval records; ORC reports; copy deck hashes; open DEC rows marked HG-02 all `ratified` or `overridden`.

Acceptance. AS-01 approval records have `clinical_signoff.name`, `date`, `decision: approved`. AS-02 registry has zero DEC rows with `ratify_at: HG-02` and `status: default|open` at release. AS-03 CI `clinical-gate` job green.

### NFR1-03 Security — MUST, behaviour, auto
depends_on: FR1-01, FR1-14, FR1-21 · TC-NFR1-03 · DEC-16, DEC-28

Statement. HTTPS only with HSTS added in `.htaccess` (DEC-35); no secrets in served assets or repo (gitleaks); 403 on `/tools/`, `/tools/api/*`; OWASP ZAP baseline scan with zero High; WPScan with zero known-vulnerable plugin at release; rate limiting; output escaping; security headers (CSP, X-Content-Type-Options, Referrer-Policy `strict-origin-when-cross-origin`, Permissions-Policy).

Acceptance. AS-01 gitleaks and `grep` of served HTML/JS for `smtp|password|apikey` empty. AS-02 ZAP baseline report: 0 High, Medium triaged in `security/triage.json`. AS-03 WPScan: 0 vulnerable components. AS-04 header checks on 6 URLs. AS-05 reflected-input test: `firstName="<img src=x onerror=alert(1)>"` never appears unescaped in any response or email. AS-06 403 checks on protected paths.

### NFR1-04 Isolation of the two pipelines — MUST, behaviour, auto
depends_on: FR1-14 · TC-NFR1-04 · SAF-CROSS-01

Statement. Any commit touching CMP-06, CMP-10, CMP-08 or theme header triggers both tools' full regression (engine unit, E2E happy, renal, emergency, email).

Acceptance. AS-01 CI path filter proven: a commit changing `mailer.php` runs both suites (job names present in run log). AS-02 both suites green after each such commit. AS-03 Mailpit receives one protein and one diabetes mail in the cross test.

### NFR1-05 Accessibility — MUST, behaviour, auto
depends_on: FR1-03, FR1-04 · TC-NFR1-05 · UC1-06

Statement. WCAG 2.2 AA on homepage, hub, both landing pages, both tools (form and result states incl. renal, emergency, symptom, unavailable).

Acceptance. AS-01 axe-core: 0 `critical` and 0 `serious` violations on the 11 states enumerated in TC-NFR1-05. AS-02 keyboard script completes both tools; focus order equals DOM order; no focus trap. AS-03 every input has an associated label; errors use `role=alert` or `aria-live=assertive`; banners use `role=alert`. AS-04 contrast of band colours and banners ≥ 4.5:1 (computed). AS-05 Lighthouse accessibility ≥ 95 on all 6 pages. AS-06 heading outline has no skipped levels.

### NFR1-06 Mobile performance — SHOULD, behaviour, auto
depends_on: FR1-03 · TC-NFR1-06 · DEC-17

Acceptance. AS-01 LCP ≤ 2.5 s and CLS ≤ 0.1 (median of 5, DEC-17 preset) on the 4 URLs. AS-02 Plausible script loaded `defer`; no render-blocking third-party script added. AS-03 regression vs baseline > 10 % fails.

### NFR1-07 SEO preservation — MUST, behaviour, auto
depends_on: FR1-02, FR1-21 · TC-NFR1-07 · DEC-14

Acceptance. AS-01 crawl diff (FR1-02 AS-01) clean. AS-02 sitemap includes hub, both landing pages, disclaimer page; excludes `/tools/*.html`. AS-03 `/tools/*.html` carry canonical to landing pages and no `noindex`; landing pages have no `noindex` (DEC-14). AS-04 structured data on landing pages validates (schema.org `WebApplication` or `MedicalWebPage`, Google Rich Results test or `structured-data-testing-tool`). AS-05 no redirect chains > 1 on the 30-URL sample.

### NFR1-08 Backup and restore — MUST, process, auto
depends_on: FR1-01 · TC-NFR1-08 · DEC-19

Acceptance. AS-01 backup script produces DB dump + `wp-content` + `public_html/tools` archive with manifest of hashes. AS-02 restore into a clean staging container; hashes of both tool files equal manifest; 10 sample posts return 200; both tools complete FX happy path; Mailpit receives test mail. AS-03 restore duration < 4 h recorded. AS-04 retention policy configured (DEC-19). AS-05 backup timestamps over a 7-day window show no gap > 24 h (RPO evidence).

### NFR1-09 Reproducible AI development — MUST, process, auto
depends_on: — · TC-NFR1-09

Acceptance. AS-01 every merged PR title matches `^(FR1|NFR1|UI1)-\d{2}:` and body links an evidence bundle (CI check). AS-02 branch protection requires all CI jobs. AS-03 `fixtures/**/expected` changes allowed only from the oracle runner job or a commit signed by the clinical reviewer key (CI check `oracle-guard`). AS-04 evidence bundle includes commit SHA and tool hashes.

### NFR1-10 Error handling and observability — MUST, behaviour, auto
depends_on: FR1-14, FR1-15 · TC-NFR1-10 · DEC-20

Acceptance. AS-01 simulated 400/429/502/503 from endpoints → UI shows the specific friendly message and a retry or alternative. AS-02 offline (network blocked) → fetch of `status.json` fails → tool renders the "can't confirm this tool is available" notice with a retry control and does **not** calculate (fail closed, DEC-10); retry after restoring the network succeeds. AS-03 log scan after full suite: no email, numeric result or symptom key; only SCH-07 fields.

### NFR1-11 Graceful clinical feature disable — MUST, behaviour, auto
depends_on: FR1-15, FR1-10 · TC-NFR1-11 · DEC-08

Acceptance. AS-01 `fidelity-gate` failure writes withdrawn status (sandbox). AS-02 withdrawn tool shows the unavailable state on all routes; other tool unaffected (SAF-WITHDRAW-01). AS-03 attempting to set `active` with unapproved version fails CI.

### NFR1-12 Browser and device support — MUST, behaviour, auto
depends_on: FR1-03 · TC-NFR1-12 · DEC-18

Acceptance. AS-01 Playwright projects Chromium, WebKit, Firefox desktop + mobile Chrome (Pixel 5) + mobile Safari (iPhone 12) complete FX-PR-NRM-01 and FX-DM-QD-01. AS-02 no horizontal scroll at 320 px on 6 pages.

### NFR1-13 Monitoring — SHOULD, behaviour, auto
depends_on: FR1-19 · TC-NFR1-13

Acceptance. AS-01 uptime check (5-min interval) on hub and both standalone tools with alert to owner email. AS-02 synthetic daily check loads both tools and asserts the form renders. AS-03 alert fires in a staged outage test.

### NFR1-14 Email deliverability and sandbox — MUST, behaviour, auto
depends_on: FR1-14 · TC-NFR1-14 · DEC-27

Acceptance. AS-01 staging and CI use Mailpit; `config.php` on staging has no production credentials (env-based). AS-02 production sending domain has SPF, DKIM and DMARC records (DNS check). AS-03 email templates render in text and HTML; HTML passes an HTML-email lint; subject lines fixed per template.

### NFR1-15 Embed security policy — MUST, behaviour, auto
depends_on: FR1-21 · TC-NFR1-15 · DEC-28

Acceptance. AS-01 CSP headers as DEC-28 on landing and tool pages. AS-02 external framing blocked (FR1-21 AS-05). AS-03 CSP report-only phase shows zero violations across the full E2E suite before enforcing.

### NFR1-16 Logging and retention — MUST, behaviour, auto
depends_on: FR1-14 · TC-NFR1-16 · DEC-20

Acceptance. AS-01 endpoint log lines validate against SCH-07 (timestamp, tool, outcome, hashed_ip). AS-02 logrotate or equivalent configured for 30 days. AS-03 IP hash uses a daily salt (two requests on different days from same IP hash differently in test).

---

## 10. User-interface requirements

### UI1-01 Homepage hierarchy — MUST, behaviour, auto
depends_on: FR1-03 · TC-UI1-01 · DEC-23, DEC-30

Acceptance. AS-01 FR1-03 AS-01/02/03/05. AS-02 Tools row has exactly 2 cards at all 4 widths; Learn row has ≥ 4 articles. AS-03 visual snapshot matches signed baseline (UI1-09) within 0.5 % pixel diff. Optional moderated usability session (5 users) is recorded as evidence if performed but is not a gate.

### UI1-02 Tool cards and landing pages — MUST, behaviour, auto
depends_on: FR1-04 · TC-UI1-02

Acceptance. AS-01 card fields rendered equal `content/tool-cards.json` (SCH-04): name, purpose ≤ 120 chars, time, inputs list, eligibility, status label, CTA, related article. AS-02 CTA opens correct landing page; landing page embeds the correct tool (iframe src). AS-03 status label text equals `status.json` mapping.

### UI1-03 Responsive layout — MUST, behaviour, auto
depends_on: FR1-03, FR1-04, FR1-21 · TC-UI1-03

Acceptance. AS-01 at 320/375/768/1440: `document.scrollingElement.scrollWidth <= innerWidth` on 6 pages in form and result states. AS-02 primary CTA fully within viewport after scroll-into-view, not covered (elementFromPoint test). AS-03 result tables/metrics wrap, no clipping (overflow check on `.metrics`).

### UI1-04 Calculator input clarity — MUST, behaviour, auto
depends_on: FR1-07, FR1-12 · TC-UI1-04

Acceptance. AS-01 every input/option group has a programmatic label and unit text where applicable. AS-02 submitting with each required field missing shows the specific error, `role=alert`, and no result. AS-03 conditional prompts (pregnancy for female, symptom list when hasSym = yes, waist unlocks secondary) appear/disappear correctly. AS-04 out-of-range values show range text with both unit systems.

### UI1-05 Clinically safe result layout — MUST, behaviour, hybrid
depends_on: FR1-08, FR1-11 · TC-UI1-05 · SAF-PR-01, SAF-DM-01 · HG-02

Acceptance. AS-01 DOM order in result: banners → headline → metrics → interpretation → advice → methodology → disclaimer → email. AS-02 emergency/renal banners: `role=alert`, first in DOM, contrast ≥ 4.5:1, visible without scroll at 375 px after result render. AS-03 screenshots of renal, unsure, emergency, symptomatic, low/high band states match baseline. AS-04 heading levels: result headline is `h2`, sections `h3`.

### UI1-06 Email consent interface — MUST, behaviour, auto
depends_on: FR1-14 · TC-UI1-06

Acceptance. AS-01 consent checkbox default unchecked; no marketing checkbox exists; consent label text equals the approved copy hash (explicit health-data consent with Privacy Policy link, DEC-21/DEC-36). AS-02 sending disabled until email valid and consent checked. AS-03 skipping email leaves result fully visible. AS-04 no pre-ticked boxes anywhere in the tools (DOM scan).

### UI1-07 Navigation and educational links — MUST, behaviour, auto
depends_on: FR1-05, FR1-17 · TC-UI1-07

Acceptance. AS-01 internal crawler from `/` (depth 3) finds 0 links with status ≥ 400 and 0 redirect loops. AS-02 each landing page has a breadcrumb `Home › Health Tools › {Tool}` with working links. AS-03 unavailable state links resolve.

### UI1-08 Loading, unavailable and error states — MUST, behaviour, auto
depends_on: FR1-15, NFR1-10 · TC-UI1-08

Acceptance. AS-01 while `status.json` is pending, a visible loading indicator with `aria-busy=true` is shown; removed after load. AS-02 withdrawn state: heading, approved message, links to hub and one article; no form controls. AS-03 network failure during email shows error and retry button. AS-04 axe clean on all three states.

### UI1-09 Visual consistency — SHOULD, behaviour, auto
depends_on: FR1-03, FR1-04 · TC-UI1-09

Acceptance. AS-01 `design/tokens.json` exists (extracted from live CSS: `--brand-green #3fd07f`, `--accent #1f9d57`, `--accent-dark #157a42`, `--charcoal #1c1c1c`, band colours, radius 18 px, shadows) and both tools and plugin CSS reference tokens (no hard-coded hex outside tokens file, lint rule). AS-02 visual snapshots of 6 pages × 4 widths within 0.5 % of baseline approved at HG-01, and any intentional change updates baseline in the same PR.

### UI1-10 Iframe embed behaviour — MUST, behaviour, auto
depends_on: FR1-21 · TC-UI1-10

Acceptance. AS-01 iframe has `title`, `loading="lazy"` is NOT used for the tool (above-fold), and `allow` attribute minimal. AS-02 height auto-adjusts on form→result transition within 500 ms (height delta observed). AS-03 `<noscript>` link present and resolves. AS-04 on message from wrong origin, height unchanged (unit test of listener).

### UI1-11 Copy deck freeze — MUST, process, hybrid
depends_on: FR1-06 · TC-UI1-11 · DEC-25 · HG-02

Acceptance. AS-01 `copy/inventory.json` (DEC-25) enumerates every copy source; `copy/*.json` are extracted from them at build and each entry's text hash equals the approval records. AS-02 CI fails if a `MESSAGES` key changes without a new approval record version. AS-03 required phrases (`required-phrases.json`) and forbidden terms (`forbidden-terms.json`) checks pass on all copy files and templates.

---

## 11. Release gates

| Gate | Condition (all machine-checked unless marked HG) | Evidence |
|---|---|---|
| G-01 Baseline | FR1-01 accepted; manifests and hashes in repo; HG-01 signed | EV-FR1-01-* , `approvals/hg-01.json` |
| G-02 Safety | TC-FR1-08, FR1-09, FR1-11, FR1-12, FR1-14 green incl. Mailpit assertions; MUT-01…09 proven red-then-green | CI run id |
| G-03 Clinical | ORC-01/02 reports 100 % within tolerance; approval records signed; HG-02 signed; zero open HG-02 DEC rows | `approvals/*.json`, `oracles/*/results.json` |
| G-04 Quality | Every MUST FR/NFR/UI `accepted`; MUT-10…14 proven; ZAP 0 High; axe 0 critical/serious; SHOULD items accepted or deferred by DEC | registry status report |
| G-05 Site regression | FR1-02, NFR1-07, UI1-07 green; restore and rollback drills recorded | EV bundles |
| G-06 Release | Release record valid; HG-03 signed | `releases/<tag>.json`, `approvals/hg-03.json` |

## 12. Definition of done (Stage 1)

All 23 FR, 16 NFR and 11 UI requirements have `status: accepted` (MUST) or `deferred` with a DEC row (SHOULD); every evidence bundle validates against SCH-06; gates G-01…G-06 satisfied; production smoke at +24 h green.

## 13. The 30-URL regression sample (section 13 reference)

Deterministic rule, generated by `tools/sample-urls.js` at FR1-01 and frozen in `fixtures/site/url-sample.json`: the 8 page-sitemap URLs; the 5 category archive URLs in primary navigation; the 12 most recent posts by `post-sitemap.xml` lastmod; the 5 most-viewed posts if a view counter exists, otherwise the next 5 posts by lastmod. Total 30.

---

## Appendix A — Stages 2–4 roadmap (unchanged from v1.1)

**Stage 2 Website membership and improvement MVP.** Secure registration, recovery, consent and adult eligibility; Health Goal Selector (≤ 3 goals); explainable personalised suggestions from reviewed categories; four-week Healthy Habits programme; member dashboard, export/deletion, notifications; optional premium membership; shared backend/API, clinical tool registry, entitlements, admin reports; reuse of the two public tools with save-result only after privacy approval.

**Stage 3 Cross-platform mobile application.** Flutter iOS/Android; onboarding; Home/Tools/Progress/Learn/Profile; BP, weight, waist logs and trends; protein/energy/fibre targets, food tracker, saved meals; goal/programme sync; bookmarks; subscriptions/restore; activity and sleep diary; reminders; shareable summary; offline reading; feedback; secure identity; feature flags.

**Stage 4 Unified advanced health platform.** Logged-in web dashboard sharing mobile identity; enhanced explainable recommendations, editorial approvals, portability; barcode lookup with provenance; optional recipe builder and AI food-photo suggestions; optional consented health-platform imports; cross-platform privacy-preserving analytics; reassess Newspaper theme; localisation and community optional.

Stage 2 scope is approved only after Stage 1 release.
