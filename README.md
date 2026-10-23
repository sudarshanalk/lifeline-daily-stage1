# Lifeline Daily — Stage 1 build repository

Specification pack: `docs/` (SRS v1.2, data model, test criteria, AI development loop, decision register).
Machine-readable: `registry/`, `schemas/`, `fixtures/`. Agent instructions: `CLAUDE.md`.

Branches: `staging` (default, all PRs target it), `production` (release tags only).
No credentials are ever committed; `public_html/tools/api/config.php` is ignored and configured from the environment.
