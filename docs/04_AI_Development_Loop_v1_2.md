# Lifeline Daily — Stage 1 AI Development Loop v1.2

Operating manual for building Stage 1 with Claude Code (or equivalent) with three human touchpoints (HG-01, HG-02, HG-03). Everything else is decided by `05_Decision_Register_v1_2.md`, verified by `03_Test_Criteria_Stage1_v1_2.md`, and tracked in `registry/Stage1_Requirements_Registry_v1_2.json`.

## 1. Repository and environment

- One Git repository (layout in `02_Data_Model_Stage1_v1_2.md` §6). `templates/CLAUDE.md` is copied to the repo root.
- Environments: **local** (Docker Compose: WordPress + MariaDB + PHP tools dir + Mailpit + Plausible stub + R oracle + Python oracle + Playwright), **staging** (clone of production, synthetic data, Mailpit), **production** (deploy only via P-E after HG-03).
- CI (GitHub Actions or equivalent) jobs: `validate-artefacts` (all SCH), `registry-integrity` (SCH-01), `provenance-check` (FR1-06), `oracle-guard` (NFR1-09 AS-03), `engine-freeze` (hash compare of frozen objects), `unit`, `php-integration`, `e2e` (Playwright, 5 projects), `a11y`, `security` (gitleaks, ZAP baseline, WPScan), `perf` (Lighthouse CI), `crawl` (site regression), `fidelity-gate` (FR1-10, may write `status.json` withdrawn), `clinical-gate` (NFR1-02), `evidence` (SCH-06e bundle per run). Branch protection requires all.

## 2. Requirement state machine

```
draft ──► ready ──► in_progress ──► review ──► verified ──► accepted ──► released
             ▲           │             │            │
             │           ▼             ▼            ▼ (hybrid: waits for HG artefact)
             └──────── blocked ◄───────┴────────────┘
```

| State | Entry condition | Who sets |
|---|---|---|
| `draft` | exists in registry, missing fixtures or TC detail | pack author |
| `ready` | TC page exists, all referenced FX files exist, all `depends_on` are `verified` or `accepted`, all DEC rows referenced are `default`, `ratified` or `overridden` (not `open`) | orchestrator (automatic) |
| `in_progress` | picked by P-B | orchestrator |
| `review` | P-B output complete, CI green on branch | orchestrator |
| `verified` | P-C returns PASS, CI green, PR merged to `staging`. Satisfies other requirements' `depends_on`. For `auto` items the orchestrator promotes to `accepted` immediately | orchestrator |
| `accepted` | `verified` plus, for `hybrid` items, the HG artefact with `decision: approved` | orchestrator |
| `blocked` | P-B or P-C emits a `blocker` (new DEC row needed, oracle disagreement, human gate pending) | agent |
| `released` | included in a release record deployed to production | P-E |

Deadlock avoidance (review finding F01): human gates HG-02 and HG-03 are batch events, so hybrid items sit in `verified` until the gate; dependants build on `verified`. Release gates G-03…G-06 require `accepted`. P-E is triggered when every MUST item is at least `verified`; HG-03 items become `accepted` when `approvals/hg-03.json` is signed, and only then is the deploy command run. Branch protection requires the automated jobs; `clinical-gate` and `release-gate` are release-tag checks, not PR checks.

The orchestrator picks the lowest-numbered `ready` requirement in dependency order (section 5). It never picks `draft` or `blocked`.

## 3. Agent roles

| Role | Model session | May write | May not |
|---|---|---|---|
| Orchestrator | long-running Claude Code session | registry `status`, branch/PR creation, evidence index | code, tests, fixtures |
| Implementer (P-B, P-D) | fresh session per requirement, context = CLAUDE.md + registry entry + TC page + referenced DEC rows + components | code, tests, copy extraction, artefacts for its requirement | `expected` in fixtures; frozen engine objects; other requirements' files; `status` field |
| Reviewer (P-C) | fresh session, read-only tools + test execution | review report | implementation, tests, fixtures, acceptance criteria |
| Oracle author (P-O2) | sealed session with access only to `Protein-Tool-Engine-Reference.docx` and `oracles/orc-02-protein-ref/` | ORC-02 code | anything else (transcript file-access list is evidence) |
| Baseline runner (P-A0) | fresh session | manifests, characterisation `expected`, baselines, AS-IS report | code |
| Release agent (P-E) | fresh session | release record, deployment plan | production (only ACT-04 runs the deploy step) |

## 4. Prompts

Every prompt ends with the same output contract (section 4.7). Placeholders in `{}`.

### P-A0 Baseline reconnaissance and characterisation
```
You are the baseline runner for Lifeline Daily Stage 1 (SRS v1.2). Read CLAUDE.md, 01_SRS §3–§4, 02_Data_Model §4, 05_Decision_Register. Do not change any product code.
1. Inventory WordPress staging (version, plugins, theme version, cache layer, server headers) and public_html/tools/* (files, SHA-256, sizes). Write manifests/{protein,diabetes}.json (SCH-02) and reports/as-is/inventory.json.
2. For every fixture in fixtures/protein and fixtures/diabetes with oracle "characterisation" and empty expected, run the deployed engine (Playwright against the staging tool page, or node vm over the extracted engine) and fill expected exactly as produced. Set source_version to the manifest hash.
3. Capture visual baselines (6 pages × 320/375/768/1440) into design/baselines/.
4. Generate fixtures/site/url-sample.json by the 30-URL rule (SRS §13) and crawl it into reports/as-is/crawl-before.json.
5. Inspect submit-protein.php, submit.php, api/*.php for persistence, logging and SMTP; write privacy/data-flow.json (SCH-12) and list unknowns.
6. Produce reports/as-is/discrepancy-ledger.json: each statement in v1.1, the two reference documents and SRS v1.2 §3 marked confirmed | corrected | unknown with file:line evidence.
Stop conditions: a file hash differs from the registry `live_hashes` (report, do not proceed to step 2 for that tool); persistence of health data found (record, mark DEC-21 open). Also record: LiteSpeed Cache settings, CookieYes configuration, live category/tag slugs (DEC-13), and the forwarded-IP header name (DEC-35).
```

### P-B Implement one requirement
```
Implement ONLY {REQ-ID} from SRS v1.2. Inputs: registry entry, TC-{REQ-ID}, DEC rows {list}, components {list}, must_not_touch {list}.
1. List affected files. Confirm by hash that frozen objects (engine-freeze list) are unchanged before and after.
2. Load fixtures {FX list}. Write the automated assertions TC-{REQ-ID}-AS-* and NEG-* as tests in the designated families. Run them: they must FAIL where new behaviour is required (record the red run id). Where behaviour already exists (verification-only requirements), record the green run and proceed.
3. Make the minimal change. No edits outside the component list. No change to fixtures/**/expected. No change to acceptance criteria.
4. Run: unit + php-integration + e2e for this requirement, then both tools' full regression (SAF suite), a11y and security quick scan.
5. Apply mutations MUT-* listed in TC-{REQ-ID}: each must turn at least one test red; revert.
6. Write evidence/{REQ-ID}/{run-id}.json (SCH-06e) and open a PR titled "{REQ-ID}: {title}" against staging.
Stop and emit blocker if: oracle disagreement; a DEC row is needed; a frozen object must change; a human gate artefact is missing for a hybrid requirement.
```

### P-C Independent review
```
Review PR for {REQ-ID} as an independent agent. You may read everything and run tests. You may not edit code, tests, fixtures or criteria.
Check: (a) diff touches only listed components; (b) frozen hashes unchanged; (c) every TC-{REQ-ID}-AS-* and NEG-* exists as a test, maps to the right fixture, and asserts what the TC page says — not a weaker proxy; (d) mutation evidence shows red; (e) SAF suite green for both tools; (f) privacy sentinel scan green; (g) no change to expected values; (h) copy changes have approval or are flagged for HG-02.
Try at least three additional edge cases of your own and report them.
Output PASS or FAIL with reasons keyed by AS/NEG id. FAIL on any weakened assertion.
```

### P-D Repair
```
Repair only the FAIL items from the reviewer for {REQ-ID}: {list}. Same constraints as P-B. Re-run everything that previously passed. If a fix requires touching another requirement's component, stop and emit blocker.
```

### P-O2 Sealed oracle author
```
You will implement a reference protein engine in Python from the document Protein-Tool-Engine-Reference.docx sections 2–5 only. You have no access to any HTML, JavaScript or the deployed tool, and must not request it. Implement: basis weight rule, band collection with highest-band rule, age ≥ 65 bands, training flag, goal bands, diet multiplier, 2.0 cap, per-meal clamp 20–40, pregnancy/lactation branch, renal suppression. Read fixtures/protein/*.json inputs and write oracles/orc-02-protein-ref/results.json (SCH-06). Where the document is ambiguous, implement the literal reading and list the ambiguity in results.json.notes.
```

### P-E Release candidate
```
All MUST requirements show status verified or accepted. Create tag rc-{date}. Run the full pipeline including restore drill (NFR1-08) and rollback drill (FR1-19 AS-04) on staging. Produce releases/{tag}.json (SCH-10) with tool hashes, approval ids, evidence ids, backup id, deploy and rollback commands. Produce approvals/hg-03.json skeleton for the release owner and privacy reviewer. Do not deploy.
```

### 4.7 Output contract (all prompts)
```json
{
  "req_id": "FR1-08",
  "run_id": "20261101-1430",
  "commit": "<sha>",
  "files_changed": ["…"],
  "tests_added": ["TC-FR1-08-AS-01", "…"],
  "red_run": "<ci run id or null>",
  "green_run": "<ci run id>",
  "mutations": [{"id": "MUT-01", "red": true}],
  "evidence": "evidence/FR1-08/20261101-1430.json",
  "blockers": [],
  "decisions_needed": [],
  "copy_changed": false,
  "status_request": "review"   // or "verified", "blocked"
}
```
The orchestrator parses this; prose outside it is ignored.

## 5. Build order (dependency-resolved)

| Wave | Requirements | Gate reached |
|---|---|---|
| 0 | FR1-01 (P-A0), FR1-22 oracles, NFR1-09 CI skeleton | G-01 after HG-01 |
| 1 | FR1-06, FR1-20, FR1-21, NFR1-15, NFR1-16, NFR1-03 | |
| 2 | FR1-07, FR1-08, FR1-09, FR1-10, FR1-11, FR1-12, FR1-13, UI1-04, UI1-05, UI1-11 | G-02 |
| 3 | FR1-14, NFR1-04, NFR1-14, NFR1-10, UI1-06 | |
| 4 | FR1-15, NFR1-11, UI1-08, UI1-10 | |
| 5 | FR1-04, FR1-03, FR1-05, FR1-18, FR1-17, FR1-23, UI1-01, UI1-02, UI1-03, UI1-07, UI1-09, NFR1-05, NFR1-12 | |
| 6 | FR1-16, NFR1-01, NFR1-06, NFR1-07, FR1-02 (final diff) | HG-02 → G-03, G-04, G-05 |
| 7 | NFR1-08, NFR1-13, FR1-19 (P-E) | HG-03 → G-06 |

Within a wave, items are independent and may run in parallel sessions on separate branches; two sessions never share a component.

## 6. Evidence bundle (SCH-06e)

```json
{
  "ev_id": "EV-FR1-08-20261101-1430",
  "req_id": "FR1-08", "tc_id": "TC-FR1-08",
  "commit": "<sha>", "tool_hashes": {"protein": "…", "diabetes": "…"},
  "fixtures": ["FX-PR-REN-01", "…"],
  "results": [{"assertion": "TC-FR1-08-AS-01", "outcome": "pass", "artefact": "artefacts/as-01.log"}],
  "mutations": [{"id": "MUT-01", "red": true, "run": "…"}],
  "reviewer": {"agent": "P-C", "verdict": "PASS", "report": "artefacts/review.md"},
  "human_gate": {"id": "HG-02", "artefact": "approvals/protein.json", "status": "pending"},
  "started_at": "…", "finished_at": "…"
}
```
Logs in artefacts are scrubbed by the runner (regex for emails, numeric results, symptom keys) before storage.

## 7. Guard rails enforced by CI, not by prompt text

| Guard | Mechanism |
|---|---|
| Production boundary | No CI job holds a production credential; `fidelity-gate` writes staging `status.json` only and alerts ACT-04 (DEC-08) |
| Frozen engine | `engine-freeze` job compares SHA-256 of extracted `ENGINE`, `BANDS`, `DIET_MULT`, `PREG_GKG`, `bandFor`, `idrs`, `whtr`, `computeProtein`, renal/emergency branches with manifest `engine_hashes`; fails unless PR references a DEC row with `status: ratified` and `ratify_at: HG-02` |
| Oracle integrity | `oracle-guard` fails any PR that changes `fixtures/**/expected` unless author is the oracle runner bot or commit is signed by the clinical reviewer key |
| Weakened tests | `assertion-map` job checks that every AS/NEG id in the TC page appears as a test title and that the test file imports the fixture named |
| Provenance | `provenance-check` compares staged tool hashes with approval records before any deploy job |
| Copy | `copy-hash` job compares extracted `MESSAGES` and template hashes with `approvals/*.copy_hashes` |
| Privacy | `sentinel` job runs the three sentinel flows with network capture and fails on any leak |
| Scope | `component-scope` job fails a PR whose diff touches files outside the registry `components` of its requirement |
| Human gates | `clinical-gate` and `release-gate` jobs check the HG artefacts' `decision: approved` |

## 8. Human touchpoints

| Gate | Trigger | Artefact the human edits | Time budget |
|---|---|---|---|
| HG-01 | Wave 0 complete; orchestrator posts AS-IS summary and the DEC rows to ratify | `approvals/hg-01.json` (`decision: approved`, list of ratified DEC ids) | ~1 hour |
| HG-02 | Wave 6 complete; orchestrator posts both approval records, ORC reports, copy deck diff vs live | `approvals/protein.json`, `approvals/diabetes.json`, `approvals/copy.json` (sign), DEC rows marked HG-02 | ~2–3 hours clinical review |
| HG-03 | P-E complete | `approvals/hg-03.json` (owner + privacy), then run the deploy command | ~1 hour |

Anything else the agent thinks needs a human becomes a `blocker` with a proposed DEC row; the owner edits the register and the loop resumes. There is no chat-based approval.

## 9. Non-negotiable

A coding agent must never claim clinical correctness by comparing code to tests derived from that same code. Correctness claims rest on ORC-01…ORC-04 and the signed approval records. Characterisation fixtures prove only "unchanged", and the evidence bundle must label them as such.
