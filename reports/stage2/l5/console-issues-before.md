# S2-L5 / FR2-22: search console issues, "before" (AS-01) with a fix or a reason for each (AS-02)

Read on 25 October 2026 from Google Search Console (domain property `lifelinedaily.com`) and Bing Webmaster Tools (`https://lifelinedaily.com/`), signed in as the owner, read-only. Nothing was changed in either console. Google's charts end on 13 to 16 October 2026, so Google's data lags the site by about ten days.

**Status values:** *Fix* means a change is made and then confirmed in the console; *Reason* means the console entry is expected and stays, with the written reason; *Owner* means it needs an owner decision or a console action.

## Google Search Console

### Overview

| Report | Result |
|---|---|
| Page indexing | 92 indexed, 250 not indexed (8 reasons) |
| Core Web Vitals | No data on mobile or desktop (too little Chrome traffic; matches FR2-27 AS-03) |
| HTTPS | 50 HTTPS, 0 non-HTTPS |
| Breadcrumbs | 99 valid, 0 invalid |
| Profile page | 1 valid, 0 invalid |
| Manual actions | No issues detected |
| Security issues | No issues detected |

### Page indexing: why pages are not indexed

| Reason | Pages | What they are | Status | Fix or reason |
|---|---|---|---|---|
| Excluded by "noindex" tag | 45 | 41 comment feeds and embed links (`/…/feed/`, `/…/embed/`), the homepage feed, the comments feed, one author archive, and the LiteSpeed file `guest.vary.php` | Reason | These are machine endpoints, correctly kept out of Google by Yoast. Nothing to index. |
| Not found (404) | 9 | 7 are wildcard patterns, not pages (`/*`, `/wp-admin/*`, `/wp-content/*`, `/wp-content/plugins/*`, `/wp-content/themes/Newspaper/*`, `/wp-content/uploads/*`, `/wp-*.php`); `/tag/abdominal-obesity/`; `/atkins-diet-guide/` | Fix (2) / Reason (7) | The wildcards were read from text as if they were links: they are not real addresses and drop out on their own. `/tag/abdominal-obesity/` now answers 410 Gone, as the owner set in Yoast. `/atkins-diet-guide/` gets a 301 to `/atkins-diet-how-it-works-guide/` in this change. |
| Page with redirect | 6 | `http://` and `www.` versions of the homepage; `/dm-risk-test/`, `/health-tools/dm-risk-test/`, `/health-tools/diabetes-risk/` | Reason | Intended redirects to the canonical addresses. |
| Blocked due to other 4xx | 1 | `/wp-admin/admin-ajax.php` | Reason | A WordPress endpoint for scripts, not a page. |
| Blocked due to access forbidden (403) | 1 | `/wp-content/plugins/cookie-law-info/lite/frontend/images/` | Reason | A plugin folder; folder listings are refused by design. |
| Alternative page with proper canonical | 1 | `/category/longevity/` | Fix | Replaced by the new category tree (S2-L2): it becomes `/category/healthy-ageing/longevity/`, and the old address answers 301. |
| Crawled, currently not indexed | 151 | 54 articles, 53 feeds, 31 tag archives, 8 category archives, 5 author archives | Fix (articles, categories) / Owner (tags, authors) / Reason (feeds) | See below. |
| Discovered, currently not indexed | 36 | 34 tag archives, `/category/video/`, `/glycaemic-index-blood-sugar-control/` | Owner (tags) / Fix (video, article) | Video becomes a noindex display category (S2-L2). The article gets internal links from its hub (S2-L2/L3). |
| Server error (5xx) | 0 | — | — | — |

**The 54 articles Google crawled but chose not to index.** This is the main search problem on the site. Most of them sit in clusters of near-duplicate articles (keto: 6, Mediterranean: 3, cardio: 4, belly fat: 2, colon cancer: 3, beginners' yoga: 2), are thin, or have few links from other articles. The fixes are already planned:

- the merges in the article review (S2-L2), which fold the weaker duplicate into the stronger article with a 301;
- one hub article per category linking its articles and track (S2-L2, SCR-44);
- direct answers, FAQs, dated sources and the reviewer byline (S2-L4, FR1-18);
- links from each article to its calculator and to related articles (S2-L3).

After those land, each article is resubmitted through URL Inspection and the report is re-read.

Articles in this group: ways-to-reduce-belly-fat, benefits-of-regular-exercise, gut-bacteria-colon-cancer-risk-prevention, atkins-diet-how-it-works-guide, exercise-for-brain-health-benefits, foods-that-control-blood-sugar-naturally, health-benefits-of-eating-oats, fermented-foods-gut-health-benefits, pfas-hormonal-disruption, interval-training-for-beginners-guide, signs-keto-is-working, healthy-fats-vs-bad-fats-what-your-body-needs, understanding-depression-causes-symptoms-and-treatments, foods-to-reduce-belly-fat, natural-ways-to-lower-high-blood-pressure, foods-that-raise-cholesterol-what-to-eat-instead, benefits-of-sleep-what-happens-while-you-sleep, early-colon-cancer-symptoms-younger-adults, yoga-for-beginners-how-to-start, paleo-diet-what-to-eat-guide, 15-minute-no-equipment-hiit-workouts, core-exercises-for-beginners, types-of-keto-diets, health-benefits-of-cycling, colon-cancer-in-younger-adults-rising-rates, benefits-of-protein, mindful-eating-eat-less-feel-better, insulin-resistance-what-it-is-and-why-it-matters-2, mediterranean-diet-guide, best-keto-foods, types-of-dietary-fiber-why-type-matters-more-than-amount, lifestyle-changes-for-anxiety-that-actually-work, h-pylori-stomach-cancer-causes-prevention, omega-3-foods-heart-brain-joint-health, cardio-workouts, mediterranean-diet-for-heart-health, mediterranean-diet-and-diabetes, nordic-diet-heart-brain-health, keto-diet-for-beginners, benefits-of-keto-diet, foods-to-avoid-on-keto, mix-of-exercises-for-longevity, low-carb-diet-guide, sleep-and-hunger-hormones-why-bad-sleep-makes-you-hungrier-2, benefits-of-eating-fatty-fish, dash-diet-for-blood-pressure-guide, vegan-diet-types-benefits-guide, types-of-olive-oil-explained, how-to-sleep-better-naturally, how-to-improve-gut-health, basic-yoga-poses-for-beginners, cardio-for-beginners, how-to-start-cardio-for-beginners; plus the old tool address `https://www.lifelinedaily.com/tools/diabetes-risk-tool.html` (the tool page is meant to be embedded, not indexed: Reason).

**Tag archives (65 across both groups).** 123 of the site's 190 tags are empty (S2-L1), and tag pages repeat the category pages. **Owner decided 25 October 2026: noindex.** Set in Yoast on staging; `wp lld search setup` sets it on production at the Stage 1 release. Empty tags stop being linked with the demo clean-up (S2-L1).

**Author archives (5).** The site publishes under the brand. **Owner decided 25 October 2026: noindex.** Set in Yoast on staging; production at the Stage 1 release with `wp lld search setup`. This is separate from the author address built from an account's email address, which is listed as an open owner item.

**Feeds (53).** Comment and tag feeds: Reason, the same as the noindex group.

### Sitemaps

| Sitemap | Submitted | Last read | Status | Pages |
|---|---|---|---|---|
| `/sitemap_index.xml` | 13 May 2023 | 21 Oct 2026 | Success | 193 |
| `/post-sitemap.xml` | 27 Jan 2024 | 18 Oct 2026 | Success | 103 |
| `https://lifelinedaily.com/` | 13 May 2023 | 13 May 2023 | 1 error | 0 |

The third entry was the homepage submitted as if it were a sitemap. **Fixed 25 October 2026:** removed in Search Console with the owner's approval; the list now shows only the two working sitemaps.

## Bing Webmaster Tools

### Site Explorer

Crawl information: 194 indexed, 3 errors, 49 warnings, 11 excluded (257 addresses in the last 6 months, 3 backlinks).

| Filter | Addresses | Status | Fix or reason |
|---|---|---|---|
| Dead links (404 to 410) | 49 warnings; Bing shows the first 20, all old article addresses (for example `migraine-headaches-triggers-symptoms-relief`, `type-2-diabetes-causes-symptoms-treatment`, `insulin-resistance-guide`, `mediterranean-diet-benefits-guide`, `tips-for-restful-sleep`) | Fix | These are addresses of articles that were live and later renamed or unpublished. 23 of the 25 dead addresses seen in both consoles already have a proposed redirect target in the article review (S2-L2); they are applied with the approved fates. The other 2 get a 301 now: `/atkins-diet-guide/` to `/atkins-diet-how-it-works-guide/`, and `/the-top-7-health-benefits-of-cycling/` to `/health-benefits-of-cycling/`. The full list of 49 needs the console's Download, which saves a file: to be taken on the "after" pass with the owner's agreement. |
| Server errors (403, 5xx) | 3: `lifestyle-changes-for-cancer-prevention`, `enhance-sexual-performance-strategies`, `gut-microbiome-101-guide` | Fix | The 403s were temporary; all three now answer 404. They are old addresses covered by the article review's redirect proposals. |
| Not yet crawled | 4: `the-top-7-health-benefits-of-cycling` (404), `erectile-dysfunction-101`, `health-benefits-of-fatty-fish`, `painful-sex-understanding-dyspareunia` | Fix | Cycling: 301 in this change. The other three are draft slugs covered by the article review. |
| Redirecting | 9 old slugs (for example `high-blood-pressure-a-comprehensive-guide-to-causes-symptoms-and-treatments`) | Reason | Working redirects from Yoast Premium. |
| Other issues | 7 old slugs (for example `dash-diet-for-blood-pressure-your-practical-guide`, `insulin-101-guide`) | Fix | Redirect chains or dead ends; covered by the redirect audit in the article review (S2-L2) and the new `redirects.json` entries. |
| robots.txt disallowed, noindex, canonical source, guidelines issues | none | — | — |

### Recommendations

| Recommendation | Status | Reason |
|---|---|---|
| "Your site does not have enough inbound links from high quality domains." | Reason | Not a technical fault. It improves with the hub articles, the starter guides and owner-led outreach (social profiles, partner sites). Tracked as content work, not a console fix. |

### IndexNow

The site's own IndexNow sender with a log is built (S2-L5) and switches on with the Stage 1 release, when `wp lld search setup` runs on production. Bing then hears about every new, updated and removed article.

## Summary

| | Fix | Reason | Owner |
|---|---|---|---|
| Google | 404 (2 real), canonical (1), 54 articles, 8 categories, video category, glycaemic article, tag archives (65) and author archives (5) noindex, homepage-as-sitemap entry removed | noindex (45), wildcard 404s (7), redirects (6), admin-ajax (1), plugin folder (1), feeds (53), old tool page (1) | — |
| Bing | Dead links (49), 403s now 404 (3), not yet crawled (4), other issues (7) | Redirecting (9), backlinks recommendation | Full dead-link download for the "after" pass |

The "after" pass re-reads both consoles once the Stage 1 release and the approved article fates are live, and asks each console to validate the fixes (a console action, done with the owner's agreement).
