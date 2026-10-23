# Lifeline Daily — Stage 1 Data Model v1.2

Supersedes DM v1.1. Stage 1 adds **no database tables for personal or health data**. All new persistent data is one of: WordPress post/page meta (editorial), a static JSON file served to the browser (`status.json`), or a repository-controlled JSON artefact validated by a JSON Schema (SCH-xx in `schemas/`). This document is the authority for field names; the schemas are the authority for types and constraints; CI validates every artefact.

## 1. Data boundary

| Allowed in Stage 1 | Not allowed |
|---|---|
| WordPress native content and post meta (SCH-13 keys) | New tables for visitors, results, goals, food, payments |
| `status.json` (SCH-08) in `public_html/tools/` | Server-side storage of inputs, results or email addresses beyond the SMTP transaction (DEC-21; if the audit finds existing persistence it is documented and either removed or approved at HG-03) |
| Repository artefacts: manifests, approvals, fixtures, oracle results, evidence, releases, incidents, link map, tool cards, copy deck, data-flow inventory | Any health value in analytics, logs, URLs, cookies, local storage |
| Aggregate analytics events (SCH-09 allow-list) in Plausible | Person-level analytics identifiers created or sent by us (Plausible's processor-side daily salted hash is documented in the DPIA, DEC-11) |

## 2. Entity-relationship model

```
                 ┌────────────────────┐ 1      N ┌─────────────────────────┐
                 │ ToolManifest (SCH-02)├─────────┤ ToolVersion (in manifest)│
                 │ tool_slug (PK)       │         │ version_hash (PK)        │
                 └───────┬──────────────┘         └──────┬───────────┬──────┘
                         │1                               │1          │1
                         │                                │           │
                         │N                               │N          │N
┌────────────────────────┴──┐              ┌──────────────┴─────┐ ┌───┴────────────────────┐
│ ToolStatus (SCH-08)       │              │ MethodologyApproval│ │ Fixture (SCH-05)        │
│ status.json entry         │              │ (SCH-03)           │ │ fixture_id (PK)         │
│ tool_slug (FK), status    │              │ approval_id (PK)   │ │ tool_slug, source_version│
│ approved_version (FK)─────┼──────────────┤ version_hash (FK)  │ │ oracle, inputs, expected│
└───────────────────────────┘              │ copy_hashes[]      │ └───┬─────────────────────┘
                                           └────────────────────┘     │N
                                                                      │
┌───────────────────────┐ N      1 ┌────────────────────────┐    ┌────┴──────────────────┐
│ ArticleToolLink (SCH-05b)├────────┤ WP Post / Page (native) │    │ OracleResult (SCH-06) │
│ wp_post_id (FK)        │          │ ID (PK), meta SCH-13    │    │ oracle_id, fixture_id │
│ tool_slug (FK)         │          └────────────────────────┘    │ expected               │
│ decision, source       │                                        └───────────────────────┘
└───────────────────────┘
┌──────────────────────┐   ┌───────────────────────┐   ┌───────────────────────┐
│ ToolCard (SCH-04)    │   │ EvidenceBundle (SCH-06e)│  │ ReleaseRecord (SCH-10) │
│ tool_slug (FK)       │   │ ev_id (PK), req_id,    │   │ release_id (PK)        │
│ display fields       │   │ commit, tc_results[]   │   │ tool_hashes[], ev_ids[]│
└──────────────────────┘   └───────────────────────┘   │ approval_ids[], backup │
┌──────────────────────┐   ┌───────────────────────┐   └───────────────────────┘
│ ClinicalIncident     │   │ AnalyticsEvent (SCH-09)│  ┌───────────────────────┐
│ (SCH-11)             │   │ transient, not stored  │   │ EndpointLogLine (SCH-07)│
│ incident_id, tool,   │   │ by us                  │   │ transient, 30 days      │
│ affected_version     │   └───────────────────────┘   └───────────────────────┘
└──────────────────────┘
```

Cardinalities and invariants:
- INV-01 Exactly two `ToolManifest` entries (`protein`, `diabetes`). CI fails on any other count.
- INV-02 A manifest has 1..N versions; exactly one has `is_live: true`.
- INV-03 `ToolStatus.approved_version` must equal a `MethodologyApproval.version_hash` with `decision: approved` whenever `status = active`.
- INV-04 Every `Fixture.source_version` equals a known `ToolVersion.version_hash`; `expected` with `oracle: characterisation` is written only by the baseline runner; `oracle: clinical` only by the oracle runner (ORC) or a signed commit.
- INV-05 `ArticleToolLink` references only published posts and only the two slugs; `decision ∈ {protein, diabetes, none}`.
- INV-06 `AnalyticsEvent` props are restricted to the SCH-09 allow-list; any other key fails the sentinel test.
- INV-07 `EndpointLogLine` has no free-text field.
- INV-08 Email request payloads (section 4) are never written to disk by our code.

## 3. Schema register

| ID | File | Describes | Written by | Validated in |
|---|---|---|---|---|
| SCH-01 | `schemas/registry.schema.json` | `registry/Stage1_Requirements_Registry_v1_2.json`; cross-reference integrity (every TC/FX/DEC/HG referenced exists) | pack author, orchestrator (status field) | every CI run |
| SCH-02 | `schemas/tool-manifest.schema.json` | `manifests/<slug>.json`: slug, live_url, landing_url, endpoint, versions[] {version_hash, engine_hashes{}, captured_at, is_live} | P-A0 | CI |
| SCH-03 | `schemas/methodology-approval.schema.json` | `approvals/<slug>.json`: version_hash, intended_use, eligibility, sources[], limitations[], regulatory_statement, copy_hashes{}, oracle_report, clinical_signoff{name,date,decision} | agent drafts, clinician signs | CI + HG-02 |
| SCH-04 | `schemas/tool-card.schema.json` | `content/tool-cards.json` | agent | CI, TC-UI1-02 |
| SCH-05 | `schemas/fixture.schema.json` | `fixtures/**/*.json` | P-A0, ORC runner | CI, oracle-guard |
| SCH-05b | `schemas/article-tool-link.schema.json` | `content/article-tool-links.json` | plugin generator (`wp lld links generate`) | CI, TC-FR1-05 |
| SCH-06 | `schemas/oracle-result.schema.json` | `oracles/<id>/results.json` | ORC containers | CI |
| SCH-06e | `schemas/evidence.schema.json` | `evidence/<REQ>/<run>.json` | test runner | CI, PR check |
| SCH-07 | `schemas/endpoint-log.schema.json` | each log line from `submit*.php` | PHP endpoints | TC-NFR1-16 |
| SCH-08 | `schemas/tool-status.schema.json` | `public_html/tools/status.json` | admin (manual) or fidelity-gate | CI, runtime JS |
| SCH-09 | `schemas/analytics-event.schema.json` | Plausible custom event name + props allow-list | tool JS, plugin | TC-FR1-16 |
| SCH-10 | `schemas/release-record.schema.json` | `releases/<tag>.json` | P-E | G-06 |
| SCH-11 | `schemas/clinical-incident.schema.json` | `incidents/<id>.json` | reviewer/admin | TC-FR1-15 |
| SCH-12 | `schemas/data-flow.schema.json` | `privacy/data-flow.json` | agent at FR1-01 | TC-NFR1-01 |
| SCH-13 | `schemas/wp-meta.schema.json` | WordPress post meta key set and allowed values | plugin registers | TC-FR1-18 |
| SCH-14 | `schemas/planned-categories.schema.json` | `content/planned-categories.json`: slugs removed in FR1-23 to be re-created when content exists | `wp lld tidy apply` | TC-FR1-23 |
| SCH-15 | `schemas/gate-record.schema.json` | `approvals/hg-01.json`, `hg-02.json`, `hg-03.json`: gate id, decision, signer, date, ratified DEC ids, tag (HG-03) | humans at the gates | `clinical-gate`, `release-gate` |
| SCH-16 | `schemas/copy-approval.schema.json` | `approvals/copy.json`: hash per copy source (`messages{}`, `templates{}`, `inline{}`, `pages{}`), reviewer, date | agent drafts, clinician signs | TC-UI1-11, TC-FR1-17 |

## 4. Frozen interface contracts (deployed; key names confirmed from the endpoint source on 23 Oct 2026)

These are preserved byte-for-byte in key names during Stage 1 (NFR1-04). Server validation (FR1-14) is added without changing keys.

### 4.1 Protein POST `./submit-protein.php` (flat JSON)

| Key | Type | Classification | Rule |
|---|---|---|---|
| tool | `"protein"` | technical | required |
| firstName | string ≤ 60 | identifier | optional, escaped |
| email | string RFC 5322 | identifier | required |
| consent | `true` | technical | required true |
| suppressed | boolean | health (derived) | when true, all numeric keys must be null |
| targetG, lowG, highG | integer ∣ null | health | null when suppressed |
| gPerKg | number ∣ null | health | null when suppressed |
| perMeal | integer ∣ null | health | 20–40 or null |
| meals | integer 2–5 | health | |
| sources | string[] | technical | `[]` when suppressed |
| timestamp | ISO 8601 | technical | |
| company | `""` | technical | honeypot, must be empty |

Server selects template: `suppressed === true` → `protein_renal` (no numeric tokens exist in that template); otherwise `protein_standard`. Confirmed in `submit-protein.php`: numeric fields of a suppressed payload are never read. Current validation only checks `consent`, email syntax, `tool` and `targetG` 20–400; the remaining ranges in this table are added by FR1-14. Confirmed in `submit-protein.php`: numeric fields of a suppressed payload are never read. Current validation only checks `consent`, email syntax, `tool` and `targetG` 20–400; the remaining ranges in this table are added by FR1-14.

### 4.2 Diabetes POST `./submit.php` (JSON)

| Key | Type | Classification | Rule |
|---|---|---|---|
| tool | `"diabetes-risk"` | technical | required |
| firstName, email, consent, timestamp, company | as 4.1 | | |
| riskPercent | number 1 dp ∣ null | health | **null when emergency**: client nulls it and server ignores any value (DEC-09) |
| riskBand | `Low|Moderate|High|Very high` ∣ null | health | null when emergency (same two layers). Mapping: engine key `low|moderate|high|veryHigh` → payload label `Low|Moderate|High|Very high` → email template key `low|moderate|high|veryhigh` |
| bmi | number 1 dp | health | 10–80 |
| whtr | number 2 dp ∣ null | health | |
| idrsScore | integer 0–100 ∣ null | health | |
| idrsTier | `low|moderate|high` ∣ null | health | |
| symptomatic | boolean | health | |
| emergency | boolean | health | |
| measurements.heightCm | integer 140–210 | health | |
| measurements.weightKg | integer 40–180 | health | |
| measurements.waistCm | integer 50–200 ∣ null | health | |

Server selects template: `emergency === true` → `diabetes_emergency` (no `{{riskPercent}}` token); `symptomatic === true` → `diabetes_symptomatic` (banner first, % allowed); otherwise the band template `low|moderate|high|veryhigh` keyed from `riskBand`. Confirmed in `render.php`/`validate.php`: a payload must carry a numeric `riskPercent` in 0–100 or an override flag; `bmi`, `whtr`, `idrsScore` and measurements are escaped but not range-checked today (added by FR1-14).

### 4.3 `status.json` (SCH-08)

```json
{
  "updated_at": "2026-11-01T10:00:00Z",
  "tools": {
    "protein":  { "status": "active", "approved_version": "a10d21b0…275ca", "message": null, "since": null },
    "diabetes": { "status": "active", "approved_version": "27c0e33c…96292", "message": null, "since": null }
  },
  "labels": [ { "slug": "healthy-weight", "label": "Healthy weight (planned)", "status": "label_only" } ]
}
```
Served with `Cache-Control: no-store`. `status ∈ {active, withdrawn, label_only}` (DEC-24). When `withdrawn`, `message` is the approved unavailable copy (copy deck key `unavailable.<slug>`).

### 4.4 Analytics event (SCH-09)

Event names: `tool_landing_view`, `tool_start`, `tool_complete`, `tool_unavailable_view`, `article_cta_click`, `email_sent`. Props: `tool ∈ {protein, diabetes}`, `source ∈ {article, hub, home, nav, campaign, direct}`. Nothing else. UTM parameters are read by Plausible from the URL; our code never copies them into props.

### 4.5 Endpoint log line (SCH-07)

`{"ts":"…","tool":"protein","outcome":"sent|rejected_validation|rejected_rate|honeypot|smtp_error|withdrawn","ip_hash":"<sha256(ip+daily_salt)>"}` — no other fields.

## 5. WordPress storage (SCH-13, registered by `lld-tools` plugin)

| Meta key | Applies to | Values | Used by |
|---|---|---|---|
| `lld_review_owner` | page, post | string | FR1-18 display |
| `lld_reviewed_date` | page, post | `YYYY-MM-DD` | FR1-18 |
| `lld_next_review_date` | page, post | `YYYY-MM-DD` | `wp lld review-report` |
| `lld_review_status` | page, post | `approved|pending|superseded` | Related reading filter (FR1-05) |
| `lld_tool_override` | post | `protein|diabetes|none|""` | link map (FR1-05) |
| `lld_tool_slug` | page | `protein|diabetes` | marks landing pages |

Options: `lld_tools_settings` (analytics domain, status.json URL, `iframe_origin` per environment per DEC-34, LiteSpeed purge targets). No other options or tables.

Shortcodes: `[lld_tools_hub compact=0|1]`, `[lld_tool slug=…]` (renders iframe or unavailable card from `status.json`), `[lld_tool_cta slug=… post_id=…]`. WP-CLI: `wp lld links generate`, `wp lld review-report`, `wp lld status get|set <slug> <status> --version=<hash> --message=<key>`.

## 6. Repository artefact layout

```
repo/
  manifests/{protein,diabetes}.json          SCH-02
  approvals/{protein,diabetes}.json SCH-03 · copy.json SCH-16 · hg-0{1,2,3}.json SCH-15
  fixtures/protein/*.json  fixtures/diabetes/*.json  fixtures/site/*.json  fixtures/mail/*.json   SCH-05
  fixtures/{required-phrases,forbidden-terms,sentinel-values}.json
  oracles/orc-01-qdiabetes-r/  orc-02-protein-ref/  orc-03-idrs/  orc-04-whtr/   → results.json SCH-06
  evidence/<REQ-ID>/<run-id>.json + artefacts/                SCH-06e
  content/{tool-cards,article-tool-links}.json                SCH-04, SCH-05b
  copy/{protein-messages,diabetes-messages,templates}.json    extracted copy deck
  privacy/data-flow.json  privacy/dpia-screening.md           SCH-12
  releases/<tag>.json                                          SCH-10
  incidents/<id>.json                                          SCH-11
  design/tokens.json  design/baselines/<page>-<width>.png
  site/public_html/tools/…                                     deployable overlay: tool files, endpoints, status.json
  site/public_html/wp-content/mu-plugins/lld-tools/            deployable overlay: the plugin
  deploy/                                                      deploy.sh, environments/*.env.example, runbooks, DEPLOYMENT.md
  .github/workflows/                                           ci.yml, deploy-staging.yml, deploy-production.yml
  tools/  tests/  design/                                      dev tooling, tests, canvas sources and visual baselines
  registry/Stage1_Requirements_Registry_v1_2.json             SCH-01
  schemas/*.schema.json
```

**Deployment rule.** Only `site/` is ever copied to a server, by `deploy/deploy.sh` (rsync over SSH) into the WordPress document root, excluding `api/config.php`, `api/outbox/`, `api/state/` and `tools/status.json` (the server copy is the source of truth; `wp lld status set` is its only writer). `deploy.sh` substitutes `__LLD_ORIGIN__` in tool HTML and the managed `.htaccess` block from the environment file (DEC-34). WordPress core, themes, plugins and uploads are never deployed from Git; they are managed in WordPress. Staging deploys on every merge to `staging`; production deploys only from an `rc-*` tag after HG-03 (release-gate check).

## 7. Data-protection controls

| ID | Control | Verified by |
|---|---|---|
| DP-01 | Field inventory of 4.1/4.2 with classification and lawful basis (DEC-21) | TC-NFR1-01-AS-01 |
| DP-02 | Health fields may travel only to screen and SMTP | TC-NFR1-01-AS-02, TC-FR1-16-AS-01 |
| DP-03 | No cookies/local storage of inputs or results | TC-FR1-13-AS-02 |
| DP-04 | Logs are SCH-07 only, 30-day retention, salted IP hash | TC-NFR1-16 |
| DP-05 | Synthetic data only in dev/staging/CI; Mailpit sink | TC-NFR1-14-AS-01 |
| DP-06 | Endpoint interfaces frozen except approved, test-covered safety/security change | NFR1-04 CI path filter |
| DP-07 | Secrets via environment/config outside web root; gitleaks | TC-NFR1-03-AS-01 |

## 8. Closed questions from v1.1

| v1.1 question | Answer |
|---|---|
| Which pages/scripts under `/tools/`? | `protein-requirement-tool.html`, `diabetes-risk-tool.html`, `submit-protein.php`, `submit.php`, `api/{config,mailer,templates,templates-protein}.php` (directory listing 403) |
| Does the diabetes code include QDiabetes/IDRS/WHR? | QDiabetes-2018 Model A, IDRS, waist-to-height ratio (not WHR) — DEC-01, DEC-06 |
| Healthy-weight tool deployed? | No — DEC-26 |
| Does `submit*.php` persist? | No database or log persistence (source read 23 Oct 2026). Rate-limit files hold `md5(ip)` only. Full emails persist only if production `transport` is `log` (DEC-46, pending verification) |
| What does the email provider store? | FR1-01 to document from provider dashboard/config |

## 9. Stage 2+ (not designed here)

Stage 2 introduces member identity, goals, enrolments, optional saved results, subscriptions and a separate backend; Stage 3 adds measurements and nutrition; Stage 4 adds the shared dashboard and consented imports. DM v1.0 remains a proposal to be rebaselined after Stage 1 release.
