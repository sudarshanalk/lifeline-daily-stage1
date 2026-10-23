# Lifeline Daily — Stage 1 build repository

You are working on Stage 1 of the Lifeline Daily revamp (SRS v1.2). Read these before any task, in this order:
1. `docs/05_Decision_Register_v1_2.md` — apply defaults, never ask.
2. `registry/Stage1_Requirements_Registry_v1_2.json` — the entry for your requirement is your scope.
3. `docs/03_Test_Criteria_Stage1_v1_2.md` — your TC page defines the assertions you must implement as tests, by ID.
4. `docs/01_SRS_Stage1_v1_2.md` §3–§4 and `docs/02_Data_Model_Stage1_v1_2.md` §4–§6 for components and contracts.

## Hard rules
- Work on exactly one requirement ID per branch: `req/<ID>`; PR title `<ID>: <title>`.
- Touch only files listed in that requirement's `components`. CI `component-scope` will fail otherwise.
- Never modify these (CI `engine-freeze`): in `public_html/tools/protein-requirement-tool.html` → `BANDS`, `DIET_MULT`, `PREG_GKG`, `computeProtein`, `clampMeal`, `cutoffs`; in `public_html/tools/diabetes-risk-tool.html` → `ENGINE`, `bandFor`, `idrs`, `whtr`, the emergency/symptom priority block in `render*`. A change needs a DEC row with `status: ratified`, `ratify_at: HG-02`.
- Never edit `expected` in `fixtures/**` (CI `oracle-guard`). Never edit acceptance criteria or TC pages.
- Test names must equal assertion IDs, e.g. `test('TC-FR1-08-AS-03', …)`. Import the fixture the TC names.
- Red before green: for new behaviour, record the failing run id in your output JSON.
- Mutations listed in the TC page must be applied, shown to turn a test red, and reverted.
- No real personal or health data anywhere. Use `fixtures/sentinel-values.json` for sentinel inputs in privacy tests.
- Emails go to Mailpit (`MAILPIT_URL`). Production SMTP credentials are never present in dev/staging.
- All user-facing clinical copy lives in `MESSAGES` objects and `api/templates*.php`; changing a key's text sets `copy_changed: true` in your output and requires HG-02.
- Finish with the output JSON from `docs/04_AI_Development_Loop_v1_2.md` §4.7. Nothing else is parsed.

## Commands
- `make up` — local stack (WordPress, MariaDB, tools, Mailpit, oracles, Playwright).
- `make test REQ=FR1-08` — unit + php-integration + e2e filtered to a requirement, then SAF suite.
- `make saf` — both tools' mandatory safety regression.
- `make oracle ORC=01|02|03|04` — run an oracle and refresh `oracles/*/results.json` (bot identity only).
- `make mutate MUT=01` — apply a mutation patch; `make unmutate`.
- `make evidence REQ=FR1-08` — build the evidence bundle.
- `make validate` — validate every artefact against `schemas/`.
- `wp lld …` — plugin CLI (see Data Model §5).

## When blocked
Emit `blockers[]` with a proposed DEC row (`question`, `options`, `recommended_default`, `affects`) in the output JSON and stop. Do not guess, do not ask in chat, do not widen scope.
