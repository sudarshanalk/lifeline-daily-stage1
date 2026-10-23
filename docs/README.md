# Lifeline Daily — Stage 1 specification pack v1.2

Supersedes v1.1 (20 Oct 2026). Rebaselined against the live site and restructured so that an AI coding agent (Claude Code or equivalent) can build and verify Stage 1 with three human sign-off points only.

## Files

| # | File | Purpose |
|---|------|---------|
| 00 | `README.md` | This index, identifier scheme, how to run the loop |
| 01 | `01_SRS_Stage1_v1_2.md` | Scope, AS-IS baseline, actors, use cases UC1-xx, requirements FR1/NFR1/UI1 in loop-ready form |
| 02 | `02_Data_Model_Stage1_v1_2.md` | Entities, ERD, frozen interface contracts, WordPress storage, schema register SCH-xx |
| 03 | `03_Test_Criteria_Stage1_v1_2.md` | One test page per requirement (TC-xx), assertions, fixtures FX-xx, safety suite SAF-xx, mutations MUT-xx, E2E journeys |
| 04 | `04_AI_Development_Loop_v1_2.md` | Repository layout, state machine, agent roles, prompts P-A0…P-E with output schemas, CLAUDE.md template, CI gates |
| 05 | `05_Decision_Register_v1_2.md` | Every decision DEC-xx with the default already selected; owner can override by editing one row |
| — | `registry/Stage1_Requirements_Registry_v1_2.json` | Machine-readable registry: all requirements, dependencies, assertions, fixtures, gates |
| — | `schemas/*.schema.json` | JSON Schemas SCH-01…SCH-12 for manifests, approvals, fixtures, evidence, events, status |
| — | `fixtures/*.json` | Fixture input vectors for both tools and the site (expected values filled by the loop, see 03 §3) |
| — | `templates/CLAUDE.md` | Drop-in project instructions for the build repository |
| — | `docx/*.docx` | Word copies of the six documents, generated from the Markdown (Markdown is the source of truth) |
| — | `CODEX_REVIEW_BRIEF.md` | Brief for an independent review of the decision register; the 22 Oct 2026 reviews' findings are incorporated (DEC-31…38) |
| — | `CODEX_FINDINGS_DISPOSITION.md` | Finding-by-finding disposition of the Codex review (all accepted and applied) |

## Identifier scheme (use these exactly, everywhere)

| Prefix | Meaning | Format | Example |
|---|---|---|---|
| FR1- | Stage 1 functional requirement | FR1-NN | FR1-08 |
| NFR1- | Stage 1 non-functional requirement | NFR1-NN | NFR1-03 |
| UI1- | Stage 1 user-interface requirement | UI1-NN | UI1-05 |
| UC1- | Stage 1 use case | UC1-NN | UC1-04 |
| E2E- | End-to-end acceptance journey (1:1 with UC1) | E2E-NN | E2E-04 |
| TC- | Test case page for a requirement | TC-<REQ-ID> | TC-FR1-08 |
| AS- | Automated assertion inside a test case | TC-<REQ-ID>-AS-NN | TC-FR1-08-AS-03 |
| NEG- | Negative assertion inside a test case | TC-<REQ-ID>-NEG-NN | TC-FR1-14-NEG-02 |
| FX- | Fixture (input vector, optional expected) | FX-<TOOL>-<GROUP>-NN; tools PR, DM, WP, MAIL | FX-PR-REN-01 |
| SAF- | Mandatory safety scenario (points to FX ids) | SAF-<TOOL>-NN | SAF-DM-01 |
| MUT- | Mandatory mutation that must turn a test red | MUT-NN | MUT-03 |
| CMP- | Component (file, page, endpoint) | CMP-NN | CMP-04 |
| DEC- | Decision with selected default | DEC-NN | DEC-07 |
| HG- | Human gate (only three exist) | HG-NN | HG-02 |
| G- | Release gate | G-NN | G-04 |
| SCH- | JSON Schema | SCH-NN | SCH-03 |
| EV- | Evidence bundle for a run | EV-<REQ-ID>-<run-id> | EV-FR1-08-20261101-1430 |
| ORC- | Independent oracle | ORC-NN | ORC-01 |
| ACT- | Actor | ACT-NN | ACT-05 |

Rules: an ID is never reused or renumbered; retired items keep their ID and get `status: retired`. New items take the next free number. Every requirement lists its UC, TC, FX, CMP, DEC and HG references; every TC lists its requirement; the registry JSON is the authoritative cross-reference and is validated in CI (`SCH-01`).

## How the pack is used

1. Owner reads `05_Decision_Register_v1_2.md`, changes any default they disagree with (10 minutes), and signs HG-01 after the baseline run.
2. The orchestrator (Claude Code) clones the live tool files into the repo, runs prompt P-A0 (baseline), then iterates P-B/P-C/P-D over requirements whose `status` is `ready`, in dependency order from the registry.
3. HG-02 (clinical batch) is the only mid-project human touchpoint; HG-03 is production release.
4. Everything else, including numerical verification of both engines, is automated through independent oracles ORC-01…ORC-04 (see 03 §2).
