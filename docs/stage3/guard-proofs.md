# Guard proofs (TC-NFR3-01-AS-02, NFR3-02, NFR3-04)

A deliberately broken pull request, closed unmerged, so that each guard can be seen to catch its break. Run by the lead on 3 November 2026 as pull request #170 (`S3-P10: guard proof (deliberately broken, do not merge)`, commit 81c1cd4 on `staging` at 132e5db), standing in for Codex.

## The three breaks

| # | Break | File |
|---|---|---|
| 1 | A Stage 2 page broken: the join page's heading changed from "Create account" | `includes/class-account.php` |
| 2 | A Stage 2 test skipped: `test.skip('TC-FR2-23-AS-01', …)` with no entry in `docs/stage3/stage2-test-changes.json` | `tests/stage2/calculator-link.test.mjs` |
| 3 | A column dropped in the Stage 2 schema: `ALTER TABLE … DROP COLUMN resend_count` in `upgrade()` | `includes/class-schema.php` |

## What caught what

| Job | Result | Caught |
|---|---|---|
| `stage3-guards` (`tests/ci/stage3-scope.mjs --guards`) | **failed** | Break 3: "a database change that drops, changes or renames (NFR3-04)". Break 2, twice: "a Stage 2 test changed without a decision in docs/stage3/stage2-test-changes.json (NFR3-02)" and "a Stage 2 test is skipped, limited or marked to-do (NFR3-02)" |
| `component-scope` (routes `S3-` titles to `stage3-scope.mjs --title`) | **failed** | Break 1: `class-account.php` is a Stage 2 plugin file the package does not declare (NFR3-05); break 2: `calculator-link.test.mjs` may change only with a decision (NFR3-02). `class-schema.php` is a declared Stage 2 file, so it was labelled `lead-review`, not failed |
| `stage2-tests` (`tests/stage2/schema-additive.test.mjs`) | **failed** | Break 3: "database update adds tables and keys without narrowing columns" matched `DROP COLUMN` |
| `stage2-e2e` (3 jobs), `stage3-e2e` (3 jobs) | passed | **Not** break 1: the sign-up journey filled the form and followed the confirmation link without asserting the page heading, so the broken heading passed the browser journeys |
| The other 23 checks (and `assertion-map` skipped) | passed | Nothing to catch |

Merge was blocked: 3 of 33 checks failing, "Merge only when every check has finished and passed".

## What changed because of the proof

- `tests/stage3/key-journeys/sign-up.spec.mjs` now asserts the join page's heading ("Create account") before filling the form, so a broken Stage 2 page fails the journey itself, not only the scope check (same pull request as this record).
- Break 2 is caught three ways (two guard messages and the scope check), break 3 two ways (the guard and the Stage 2 schema test); break 1 relied on the scope check alone until the journey assertion above.

## Repeating the proof

Make a branch from `staging`, apply the three breaks, open the pull request with an `S3-Pnn:` title, wait for every check, record the failing jobs here, close the pull request with `--delete-branch`. Never merge it.
