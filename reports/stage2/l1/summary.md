# S2-L1 / FR2-27: site health and speed, "before" measurements

Measured 25 October 2026, read-only. Production for speed, images and cache; staging for plugins, demo content and settings (staging runs the same 14 plugins at the same versions). Production's server log was also read.

## 1. The four pages

| Type | URL | Why this one |
|---|---|---|
| Homepage | https://lifelinedaily.com/ | — |
| Article | https://lifelinedaily.com/exercise-for-depression/ | The article the homepage links to most (10 links) |
| Category | https://lifelinedaily.com/category/food-nutrition/ | Main category, 12 links from the homepage |
| Landing | https://lifelinedaily.com/health-tools/protein/ | Landing page for the health tools |

## 2. Google PageSpeed, mobile (AS-01 "before")

The public PageSpeed API refused every call with HTTP 429 (the shared free daily quota was used up). The same test was run on pagespeed.web.dev instead, and a condensed Lighthouse result was saved for each page in `before/psi-<type>.json`, with the full report link (`reportUrl`) and the API error. For the "after" run, use an API key so the full JSON can be saved.

| Page | Score | LCP | CLS | TBT |
|---|---|---|---|---|
| Homepage | 99 | 1.7 s | 0 | 0 ms |
| Article | 99 | 1.6 s | 0 | 0 ms |
| Category | 100 | 1.4 s | 0 | 10 ms |
| Landing | 100 | 1.3 s | 0 | 0 ms |

**These scores are too good to trust.** Lighthouse loaded 0 scripts on every page: LiteSpeed Guest Mode (`guest=1`, `guest_optm=1`) sends it a stripped first-visit page, while a real phone gets the full page with 16 to 22 deferred scripts. The lab test below (AS-02) is the real check.

## 3. Lab test, simulated mid-range phone on 4G (AS-02): lab results

`tools/lead/lab-vitals.mjs`: Playwright Chromium, 412×915 touch screen, mobile user agent, CPU slowed 4×, network 9 / 1.5 Mbps with 60 ms latency, empty cache, 3 runs per page, median. It taps the main menu toggle and the cookie banner's "Customise" button (opens the preferences panel, grants no consent). INP is the slower of the two taps. Output: `before/lab.json` (`"kind": "lab"`).

| Page | LCP (< 2500 ms) | CLS (< 0.1) | INP (< 200 ms) |
|---|---|---|---|
| Homepage | 2884 ms **fail** | 0 pass | 280 ms **fail** |
| Article | 2712 ms **fail** | 0.011 pass | 232 ms **fail** |
| Category | 2524 ms **fail** (borderline) | 0.013 pass | 144 ms pass |
| Landing | 1640 ms pass | 0 pass | 112 ms pass |

- **LCP:** the server is fast (TTFB 20 to 40 ms, cache hit). The delay is the main image: it is lazy-loaded (LiteSpeed `media-lazy=1`) and fades in through Newspaper's image animation, and on the homepage it is the full-size original, not a thumbnail.
- **INP:** the slow tap is always the menu toggle. Most of it is drawing the menu (about 1,400 DOM elements), and the menu panel asks for a background image (`/wp-content/uploads/2025/05/17.jpg`) that returns 404 each time.

## 4. Real-visitor data (AS-03)

**Not yet available.** Google has no Chrome UX Report data for the four pages or the site.

## 5. Plugins (AS-04)

| Plugin | Evidence of use | Proposal |
|---|---|---|
| Advanced Editor Tools 5.10.1 | Admin editor toolbar only | Keep for now; remove if editors don't use it |
| Classic Editor 1.7.0 | All content is classic-editor HTML | Keep |
| CookieYes 3.5.7 | Cookie banner on every page | Keep |
| Hostinger AI Assistant 3.1.5 | No sign of use; appears in the "Commands out of sync" errors | **Remove** |
| Hostinger Tools 3.0.80 | Force HTTPS, maintenance switch (its llms.txt is now off on staging) | Keep |
| Link Whisper 0.9.8 | Click-tracking script and an uncached admin-ajax call on every page; PHP warnings; inserted links are plain HTML and stay | **Remove** |
| LiteSpeed Cache 7.9.2 | Page cache, minify, defer, lazy images, Guest Mode | Keep, retune |
| Mammoth .docx converter 1.22.0 | No traces in content | **Remove** |
| tagDiv Cloud Library 3.9.6 | Builds 8 Newspaper templates | Keep |
| tagDiv Composer 5.4.8 | Page builder for homepage and templates | Keep |
| WPForms Lite 2.0.2.2 | The contact form | Keep; update to 2.0.3 after testing on staging |
| WP Mail SMTP 4.10.3 | Sends WordPress mail | Keep |
| Yoast SEO 28.7 | SEO meta and schema | Keep |
| Yoast SEO Premium 23.8 | 272 redirects; five major versions behind Free, no update offered (licence probably lapsed) | Keep for the redirects; renew and update, or move redirects to the server and remove |

Result: 14 → 11 active plugins.

## 6. Newspaper demo content (staging)

- **Menus:** `td-demo-header-menu` and `td-demo-footer-menu` are unused.
- **Templates:** 15 tdb templates, 8 in use, 7 unused (2051, 4145, 4288, 5063, 4217, 4302, 4133).
- **Draft demo pages:** "My account" (8), "Popups" (2939), "Login/Register" (4263).
- **Opt-in Builder leftovers (plugin not active):** 13 `tds_email` posts (these held the email addresses that leaked into the generated llms.txt), 1 `tds_locker`, 12 `tds_*` tables.
- 4 demo attachments; 123 of 190 tags are empty.
- The mobile menu background image `/wp-content/uploads/2025/05/17.jpg` returns 404.

## 7. PHP errors (AS-04), 18 to 25 October

| Site | Message | Source | Count |
|---|---|---|---|
| Production | Database "Commands out of sync" on shutdown | core cron (13), Hostinger plugins' Jetpack autoloader (4), Action Scheduler (3) | 20 |
| Production | include_once of a missing tagDiv Cloud Library file | plugin update on 21 Oct | 2 |
| Production | Undefined property `$package` | plugin upgrader, 21 Oct | 1 |
| Staging | "Commands out of sync" | core cron, Action Scheduler, Yoast | 7 |
| Staging | Not errors: JSON telemetry lines from the LLD health tools written to the PHP error log | Stage 1 tool endpoints | 34 |

The telemetry lines would break the "no PHP errors" check, so they should go to their own log file.

## 8. Images and cache (AS-05)

Every site-hosted photo is already served as WebP, converted on the fly by the Hostinger CDN (`Server: hcdn`). The only non-WebP rasters are 5 PNGs hotlinked from `codahosted.io` in the article. LiteSpeed's own WebP conversion is not running: `img_optm-webp=1` but QUIC.cloud is not linked. Page cache hits on homepage, category and landing; no `Cache-Control` on HTML.

## 9. Proposed fixes, in priority order

1. **Main image not lazy-loaded and no fade-in** on homepage, article and category; homepage block uses a resized thumbnail. Main LCP fix. Staging first.
2. **Mobile menu:** remove the 404 background image, re-measure the tap; simplify the panel if still over 200 ms. Staging first.
3. **Remove Hostinger AI Assistant, Mammoth and Link Whisper.** Staging first: deactivate, test, then delete.
4. **Remove demo leftovers:** demo menus, 7 unused templates, 3 demo pages, demo attachments, the `tds_email` and `tds_locker` posts. Staging first.
5. **Clean the server log:** tool telemetry to its own file (Stage 1 tool code: owner decision), real server cron, then the 24-hour log watch.
6. **PageSpeed:** decide on Guest Mode; use an API key for the "after" PSI JSON.
7. **Images:** link QUIC.cloud (owner's account) or turn off LiteSpeed's idle WebP jobs; replace the 5 hotlinked PNGs.
8. **Updates:** WPForms 2.0.3; renew and update Yoast Premium (licence: owner input) or move its redirects.
9. **Optional:** Advanced Editor Tools, empty tags, the homepage link to the empty report category.

Side note: staging's `wp-config.php` uses the same authentication keys and salts as production; staging should get its own.
