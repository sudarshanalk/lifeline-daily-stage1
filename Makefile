# Lifeline Daily Stage 1 — developer entry points (CLAUDE.md "Commands"). Thin wrappers over tests/ and oracles/.
# Needs: node 24, PHP 8.1, python 3 with jsonschema, Playwright browsers (`npx playwright install chromium`).
# `make up` is the Docker Compose stack described in docs/04 §1; on this build machine Docker needs WSL 2 (OI-4), so the
# local alternative is tools/local/local-stack.ps1 (WordPress + MariaDB) plus the .htaccess emulator the tests start themselves.
SHELL := bash
NODE_BIN := npx
VITEST := $(NODE_BIN) vitest run -c tests/vitest.config.mjs
PLAYWRIGHT := $(NODE_BIN) playwright test -c tests/e2e/playwright.config.mjs
PY := $(shell command -v python3 >/dev/null 2>&1 && echo python3 || echo python)
REQ ?=
ORC ?= all
MUT ?=

.PHONY: help up test unit php e2e saf oracle mutate unmutate evidence validate ci-checks sentinel copy-extract

help:
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | sed 's/:.*## /\t/'

up: ## local stack (Docker Compose when available; otherwise tools/local/local-stack.ps1)
	@if docker info >/dev/null 2>&1 && [ -f docker-compose.yml ]; then docker compose up -d; else echo "Docker unavailable (OI-4): run tools/local/local-stack.ps1 start|serve; tests start the .htaccess emulator themselves"; fi

test: unit php e2e saf ## unit + php-integration + e2e filtered to REQ (REQ=FR1-08), then the SAF suite

unit: ## vitest families unit/ci/http/static/artefact (filtered by REQ when set)
	$(VITEST) $(if $(REQ),tests/unit tests/ci tests/http tests/static tests/artefact -t "TC-$(REQ)",tests/unit tests/ci tests/http tests/static tests/artefact)

php: ## php-integration family (PHP built-in server, log transport as the Mailpit stand-in)
	$(VITEST) tests/php $(if $(REQ),-t "TC-$(REQ)",)

e2e: ## Playwright journeys (filtered by REQ when set)
	$(PLAYWRIGHT) $(if $(REQ),--grep "TC-$(REQ)",)

saf: ## both tools' mandatory safety regression
	$(VITEST) tests/saf

oracle: ## run an oracle: make oracle ORC=01|02|03|04|all (refreshes oracles/*/results.json)
	$(PY) -I oracles/run.py $(ORC)

mutate: ## apply a mutation patch: make mutate MUT=01
	node tests/lib/mutate.mjs apply MUT-$(MUT)

unmutate: ## revert it: make unmutate MUT=01
	node tests/lib/mutate.mjs revert MUT-$(MUT)

evidence: ## build the evidence bundle: make evidence REQ=FR1-08 RUN=YYYYMMDD-HHMM VITEST_JSON=path.json [PW_JSON=path.json]
	node tests/lib/evidence.mjs --req $(REQ) --run $(RUN) --vitest $(VITEST_JSON) $(if $(PW_JSON),--playwright $(PW_JSON),)

validate: ## every JSON artefact against schemas/
	$(PY) -I tests/ci/validate-artefacts.py

ci-checks: ## the PR guard jobs locally: provenance, engine-freeze, copy-hash, component-scope, assertion-map, oracle-guard
	node tests/ci/provenance-check.mjs && node tests/ci/engine-freeze.mjs && node tests/ci/copy-hash.mjs && node tests/ci/component-scope.mjs $(if $(REQ),--req $(REQ),) && node tests/ci/assertion-map.mjs $(if $(REQ),--req $(REQ),--all) && node tests/ci/oracle-guard.mjs

sentinel: ## privacy leak scan with fixtures/sentinel-values.json
	node tests/ci/sentinel.mjs

copy-extract: ## regenerate the copy deck copy/*.json + inventory (UI1-11, DEC-25); CHECK=1 only verifies
	node tests/ci/copy-extract.mjs $(if $(CHECK),--check,)
