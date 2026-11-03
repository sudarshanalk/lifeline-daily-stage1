# Stage 2 key journeys (P3-11, NFR3-03)

The signed-in journeys run here as Playwright specs against the CI WordPress (`tests/ci/stage2-wp.sh`), through `tests/stage3/e2e/playwright.config.mjs`, one spec per journey:

| Journey | Spec | Check |
|---|---|---|
| Sign up | `sign-up.spec.mjs` | TC-NFR3-03-AS-01 |
| Sign in | `sign-in.spec.mjs` | TC-NFR3-03-AS-01 |
| Choose a goal | `goal.spec.mjs` | TC-NFR3-03-AS-01 |
| Start a track | `track.spec.mjs` | TC-NFR3-03-AS-01 |
| Open approved content | `content.spec.mjs` | TC-NFR3-03-AS-01 |
| Food diary | `diary.spec.mjs` | TC-NFR3-03-AS-01 |
| Healthy Weight Check | `healthy-weight.spec.mjs` | TC-NFR3-03-AS-01 |
| Account and privacy pages | `account.spec.mjs` | TC-NFR3-03-AS-01 |

Codex writes these from the Stage 2 journeys already covered in `tests/stage2/e2e/` (reuse `tests/stage2/e2e/helpers.mjs`; never edit the Stage 2 specs). `deploy/key-journeys.sh` runs the public half of each journey after every staging deploy. With every Stage 3 switch off, these specs must pass unchanged, and TC-NFR3-06-AS-01 compares screenshots of the same pages before and after a Stage 3 deploy.
