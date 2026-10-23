# FR1-01 audit, part 2: WordPress installation and database

Source: owner-supplied production snapshot (`lifeline-daily-local/`, 23 Oct 2026) imported into a local MariaDB (`lifelinedaily_local`, 81 tables) and read with SQL and WP-CLI. Secrets were not extracted; where an option contains credentials (WP Mail SMTP, Hostinger plugins) only the non-secret fields are reported.

## 1. Platform

| Item | Value |
|---|---|
| WordPress | 7.1.3 |
| Theme | Newspaper 12.7.9 (active; `template` and `stylesheet` = Newspaper). Twenty Twenty-Three 1.7 present, inactive |
| Permalinks | `/%postname%/` |
| Indexing | `blog_public = 1` (production indexable) |
| Default category | ID 1 = `uncategorised` (confirms DEC-41: must be changed before deletion) |
| Users | 1 administrator, 3 editors, **60 subscribers** |
| Published posts | 103 (matches the public inventory) |
| Unpublished posts | 102 (draft / pending / private / scheduled) — never to appear in Related reading (FR1-05 AS-04) |
| Pages | 8 published: `homepage` (ID 4113, static front page), `health-tools` (6107), `protein` (6102), `diabetes-risk-check` (6066), `about-us`, `privacy-policy`, `terms-conditions`, `contact-us`; 3 drafts (`tds-my-account`, `tds-login-register-2`, one untitled) |

## 2. Active plugins (14, from `active_plugins`)

classic-editor · cookie-law-info (CookieYes 3.5.7) · hostinger-ai-assistant · hostinger · link-whisper 0.9.8 · litespeed-cache 7.9.2 · mammoth-docx-converter · td-cloud-library 3.9.6 · td-composer 5.4.8 · tinymce-advanced · wordpress-seo-premium 23.8 · wordpress-seo 28.7 · wp-mail-smtp 4.10.3 · wpforms-lite 2.0.2.2

Implications: tagDiv Composer and Cloud Library are active, so the homepage and templates are Composer-built (DEC-23 path: content edit, not theme edit). Yoast handles canonicals, sitemaps and robots (use it for the `the-report` noindex, DEC-40). WP Mail SMTP routes WordPress mail (not the tool endpoints, which use their own PHPMailer).

## 3. LiteSpeed Cache settings (production values)

| Setting | Value | Consequence |
|---|---|---|
| Page cache | on, mobile cache on, public TTL 604800 s (7 days) | `/tools/*` and `status.json` must be excluded (DEC-31); withdrawal would otherwise be invisible for up to 7 days |
| Cache exclusions | `[]` (none) | to be added |
| Image lazy-load | on | — |
| **Iframe lazy-load** | **on** | breaks the embed assertions; exclude the tool iframes (DEC-31, DEC-15) |
| CSS minify, JS minify, JS defer | on | tool pages are outside WordPress and unaffected; the `lld-tools` plugin scripts must tolerate defer |
| CDN, object cache | off | — |
| Browser cache | on | tool HTML needs explicit `no-cache` headers (FR1-20) |

## 4. CookieYes

Plugin active (version 3.5.7); configuration is stored in its own tables/options and the banner on the live site shows an "Analytics" category defaulting off. Full category and wording export to be taken on staging (DEC-32).

## 5. WordPress mail (not the tools)

WP Mail SMTP: mailer `smtp`, host `smtp.hostinger.com`, port 465 SSL, from `info@lifelinedaily.com`, from-name "Lifeline Daily", from forced. The credentials are stored in the database option (encrypted string present in the dump). **The dump therefore contains production mail credentials and 60 subscribers' personal data: it must never be committed, shared or served** (already excluded by `.gitignore`).

## 6. Menus

| Menu | Location(s) | Items |
|---|---|---|
| `header_menu` (ID 78) | header-menu, header-menu-mobile | Fitness · Food & Nutrition (Diet Plans, Healthy Eating, Nutrition) · Health Conditions · Wellness (Gut Health, Longevity, Mental Health, Sex & Relationship, Sleep) · **Health Tools** (Daily Protein Target, Diabetes Risk Check) · **The Report** (category archive, 0 posts → DEC-40 removes this item) |
| `footer-menu` (ID 15) | footer-menu, footer-menu-mobile | About · Contact · Privacy Policy · Terms & Conditions (= DEC-43 baseline set) |
| `td-demo-*` (293, 294) | demo | unused Newspaper demo menus; leave |

Exports: `menu-header.json`, `menu-footer.json`.

## 7. Taxonomy

22 categories; the six empty ones and their IDs: `uncategorised` 1, `stress` 617, `womens-health` 619, `healthy-living` 620 (all children of Wellness 536 except 1), `health_tools` 621, `the-report` 622. Yoast `noindex-tax-category` is false (category archives are indexed), so the four deletions need 301s and `the-report` needs a per-term noindex (DEC-39…42).

## 8. Tool landing pages and hub (as stored)

- `/health-tools/protein/` content is a single `<iframe src="https://www.lifelinedaily.com/tools/protein-requirement-tool.html" style="height:1600px">`.
- `/health-tools/diabetes-risk-check/` likewise with `min-height:2200px` and `id="lld-tool-frame"`.
- `/health-tools/` is an iframe of `tools/health-tools-hub.html` (760 px), which carries the "Healthy Weight Check — Coming soon" card.
- No `message` listener anywhere in page content; LiteSpeed rewrites these iframes to lazy-load on output. Confirms DEC-15/DEC-31/DEC-34 and the FR1-04/FR1-21 scope: the plugin shortcodes replace these three iframes.

## 9. Local environment established

- MariaDB 12.3 (already installed) running a dedicated instance on 127.0.0.1:3399 with its own data directory under `local-stack/`; database `lifelinedaily_local`; URLs rewritten to `http://localhost:8080` with WP-CLI `search-replace` (4,447 replacements, GUIDs skipped).
- PHP 8.1 built-in server with `tools-bin/router.php` (serves files, mirrors the `api/` deny-all) at `http://localhost:8080`.
- The preparer's must-use safety plugin keeps all plugins disabled, blocks mail and forces noindex locally; Newspaper renders without Composer until tagDiv plugins are selectively enabled for layout work.

## 10. Items still unknown (need staging SSH or owner answers)

1. Production `tools/api/config.php` values: `transport`, `bcc_owner`, `allowed_origins`, `ratelimit` (see audit part 1, A-01/A-02/A-05).
2. Contents of production `tools/api/outbox/` and `api/state/` (counts and oldest date).
3. CookieYes category configuration and banner wording (export from staging admin).
4. Hostinger `hcdn` real-client-IP header name (DEC-35).
