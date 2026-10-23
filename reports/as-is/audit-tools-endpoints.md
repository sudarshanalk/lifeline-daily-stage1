# FR1-01 audit, part 1: tool files and PHP endpoints

Source: production snapshot supplied by the owner on 23 Oct 2026 (`lifeline-daily-local/`, prepared copy; production `wp-config.php`, `tools/api/config.php` and root `.htaccess` were replaced with local versions by the preparer, so their production values are **not** in this audit). Live-site HTML was fetched 20–23 Oct 2026 for hash comparison.

## 1. File inventory and hashes (SHA-256)

| File | Hash | Matches live? |
|---|---|---|
| `tools/protein-requirement-tool.html` | `a10d21b0e1c16bea5bf6e4fe8591b5b7ccb5c3bf273b733aa1f889059b1275ca` | yes |
| `tools/diabetes-risk-tool.html` | `27c0e33ce3b98aea495a2f13fca112e32007d9d478ab30ee70401a39ab496292` | yes |
| `tools/health-tools-hub.html` | `d29065ad232adbd4131000c441f3783545382fc931d80e7081637a1e82673a8e` | not compared (hub iframe) |
| `tools/submit.php` (diabetes) | `b7dd197892b71f47758ef6929a7ebfbd19df10867ffe623450334abff76dde10` | server-side |
| `tools/submit-protein.php` | `a8f575f907cdf49f9341e23b0ba7ca2b72259d46296a268eeaaa1fa404ceb64f` | server-side |
| `tools/api/validate.php` | `0d02f48fefedc039308eaa86bd7ba97681c23abae7ac0b1d718cafe64b29815b` | |
| `tools/api/render.php` | `4cff985b6c5b3e54d8f8e476a4a88c8d3e2d998db519a115484b73e3d112f298` | |
| `tools/api/mailer.php` | `40fc7099b728886bd565d611c26e6f712bb9663691611cbb6d671bba44a83f5e` | |
| `tools/api/ratelimit.php` | `031b03a79bdf2b81cc6f269330a50da2eb9c919cc97b161b1142a1fa4800c9aa` | |
| `tools/api/templates.php` | `ca2aea12f82ba48d66050aa634dc2fe795a9ad2a1a083c1079f299466d826050` | |
| `tools/api/templates-protein.php` | `daa9428655d79dcef46ac3dff5a245010ec1bec1acfd10b0959217c83091cd5b` | |
| `tools/api/.htaccess` | `309f9e4cec522654a0b2a5ace8445b12a6656a12acddb1a889d401cc713ae263` | deny-all, confirmed live (403) |
| `tools/api/config.php` | local replacement, not production | **unknown** |
| `tools/api/PHPMailer/*` | PHPMailer bundled (3 files) | |

Both public tool pages are byte-identical to what the live site serves. The baseline hashes in the registry are confirmed.

## 2. How the two pipelines actually work

**Diabetes (`submit.php` → `validate.php` → `render.php` → `mailer.php`, `ratelimit.php`).**
- POST only (405 otherwise); optional origin allow-list from config (403); per-IP flat-file rate limit in `api/state/` keyed by `md5(REMOTE_ADDR)` with configurable max/window; JSON body required (400); honeypot `company` → silent 200 with no send; validation errors → 422; render; send; SMTP failure → 502.
- Validation checks: consent `=== true`, email syntax, tool id non-empty, either a numeric `riskPercent` in 0–100 or an override flag (`emergency`/`symptomatic`). No content-type check, no body-size limit, no range checks on bmi/measurements.
- Template selection mirrors the on-screen priority: `emergency` first (template has **no `{{riskPercent}}` token**, so a forged percentage cannot leak), then `symptomatic` (percentage shown as secondary), then band templates. All interpolated values are HTML-escaped.
- `REMOTE_ADDR` is used for rate limiting; behind Hostinger's CDN this is the edge IP unless the real-IP header is honoured (DEC-35 stands).

**Protein (`submit-protein.php` → `templates-protein.php` → `mailer.php`).**
- No method check, no origin check, **no rate limit**; honeypot → silent 200; validation: consent, email syntax, `tool === 'protein'`; if not suppressed, `targetG` numeric and 20–400 (422 otherwise). No range checks on `perMeal`, `meals`, `gPerKg`.
- Renal template is selected first and unconditionally when `suppressed` is truthy; numeric fields in a suppressed payload are never read, so forged numbers cannot leak into the renal email (confirms the reference document's two-layer claim). Send failure → **500** (diabetes returns 502).

**Shared mailer.** Two transports: `log` writes the complete email, including recipient and health result, to `api/outbox/*.html`; `smtp` sends via PHPMailer with optional `reply_to` and **`bcc_owner`** (a BCC copy of every result email to an owner address, if configured). SMTP errors go to `error_log` without payload.

## 3. Persistence and privacy findings (feed NFR1-01, DEC-21)

| ID | Finding | Severity | Action |
|---|---|---|---|
| A-01 | `transport => 'log'` would persist every result email (address + health values) on disk in `api/outbox/`. Whether production uses `log` or `smtp` is **unknown**: production `config.php` was not supplied. | High if `log` in production | Owner to confirm production `transport` (value only, no secrets). If `log`, this is health-data persistence and DEC-21 flips to `open`. |
| A-02 | `bcc_owner` can copy every health-result email to an owner mailbox. Production value **unknown**. | High if set | Owner to confirm whether `bcc_owner` is set in production. If set, it is a disclosure to a third mailbox and must be in the Privacy Policy or removed. |
| A-03 | Rate-limit state files store `md5(ip)` only, no payload. | Low | Acceptable; note in data-flow inventory as technical data, retention = window. |
| A-04 | No persistence anywhere else: no database writes, no logs with payload. | — | Confirms DEC-21 default, subject to A-01/A-02. |
| A-05 | Diabetes `allowed_origins` and `ratelimit.enabled` production values unknown. | Medium | Owner to confirm. |

## 4. Security and robustness findings (feed NFR1-03, FR1-14)

| ID | Finding | Requirement |
|---|---|---|
| S-01 | Protein endpoint has no rate limit and no origin check. | FR1-14, DEC-16: add shared rate limit to both. |
| S-02 | Neither endpoint checks `Content-Type` or body size. | FR1-14 AS-02 (NEG-04, NEG-05) will fail until added. |
| S-03 | Protein send failure returns 500, diabetes 502. Inconsistent with UI error handling. | NFR1-10: align on 502. |
| S-04 | Rate limiter uses `REMOTE_ADDR`; behind `hcdn` all visitors may share one IP. | DEC-35: trusted-proxy header. |
| S-05 | Honeypot handling is silent 200 in both (matches DEC-16); diabetes logs nothing, protein logs nothing. SCH-07 logging does not exist yet. | NFR1-16: implement. |
| S-06 | `api/` is deny-all via `.htaccess` and confirmed 403 live; `outbox/` also denied. | NFR1-03 AS-06 passes today. |
| S-07 | Output escaping is consistent (`htmlspecialchars` on every interpolated value). | NFR1-03 AS-05 expected to pass. |
| S-08 | Protein payload numeric fields (`perMeal`, `meals`, `gPerKg`) are cast but not range-checked. | FR1-14: add ranges per Data Model §4.1. |

## 5. WordPress environment (from the snapshot)

- WordPress core with Newspaper 12.7.9 (tagDiv Composer 5.4.8, Cloud Library 3.9.6); Twenty Twenty-Three present but inactive.
- Plugins on disk: Classic Editor 1.7.0, CookieYes (cookie-law-info) 3.5.7, Hostinger 3.0.80, Hostinger AI Assistant 3.1.5, Link Whisper 0.9.8, LiteSpeed Cache 7.9.2, Mammoth .docx converter 1.22.0, TinyMCE Advanced 5.10.1, Yoast SEO 28.7 + Yoast SEO Premium 23.8, WP Mail SMTP 4.10.3, WPForms Lite 2.0.2.2. Activation state to be read from the database via WP-CLI.
- Database dump: 49.5 MB, SHA-256 `5da993271303458b0584910971b03e2ccf31aac152c57aad890db569906722dd`; contains tagDiv `tds_*` tables.
- Preparer's local safeguards: must-use plugin disables all plugins and mail and forces noindex; `WP_HTTP_BLOCK_EXTERNAL`, cron disabled, fresh salts, log-only mail transport. These are local-only and must never be deployed.

## 6. Open items for the owner (no secrets)

1. Production `tools/api/config.php` **non-secret values**: `transport`, `bcc_owner` (set or empty), `reply_to`, `allowed_origins`, `ratelimit` block. Paste them with the SMTP username and password removed, or grant staging SSH so I can read the staging copy, which Hostinger clones from production.
2. Whether `api/outbox/` and `api/state/` on **production** contain files (count and oldest date only).
