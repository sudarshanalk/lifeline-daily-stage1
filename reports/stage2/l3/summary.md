# FR2-20 (L3) — blog article SEO crawl, before

Crawl of **production** (`https://lifelinedaily.com`) on 2026-10-25 with `node tools/lead/seo-crawl.mjs --base https://lifelinedaily.com --out reports/stage2/l3/crawl-before.json` (user agent `lld-lead-audit`, at most 2 requests a second to the site, GET/HEAD only; 1159 requests in 303 s). Raw result: `crawl-before.json`; one row per article: `crawl-before-articles.csv`. For the after crawl (AS-01) run the same command with `--out reports/stage2/l3/crawl-after.json`; for staging use `--base https://stg.lifelinedaily.com`.

**Important context.** The Stage 1 plugin `lld-tools` is on staging but **not on production**: its `assets/lld-tools.css` answers 404 on production and 200 on staging, and `/blood-sugar-control-foods/` answers 404 on production but 301 on staging. So this crawl shows the site without the Stage 1 legacy redirects, gone-tag hiding, calculator call-to-action and review byline. Most serious issues below are already fixed in code and only need that plugin on production.

## What was crawled

| | Count |
|---|---:|
| URLs in the Yoast sitemaps (5 sitemaps) | 193 |
| URLs crawled (sitemaps + links found on pages) | 247 |
| HTML pages answering 200 | 231 |
| Articles (posts) | 103 |
| Pages + home | 7 + 1 |
| Category / tag / author archives (first page) | 18 / 65 / 1 |
| Paged archives (/page/2/ …) | 36 |
| Distinct internal link targets checked | 347 |
| Distinct external URLs checked (linked from articles and pages) | 792 |

## Headline numbers

| | Before |
|---|---:|
| Serious issues | **64** |
| Minor issues | 344 |
| Info (not counted) | 169 |
| Articles with at least one serious issue | 30 of 103 |
| Articles with at least one minor issue | **103 of 103 (100 %)**; target is 2 % or less (at most 2 articles) |
| Broken internal link targets (distinct) | 13 |
| Retired / legacy URLs answering a redirect or 410 | 0 of 10 (all answer 404 on production) |
| Health articles showing author **and** reviewer | 0 of 103 (author on all, reviewer on none) |

How far the planned fixes bring the share of articles with minor issues:

| After fixing … | Articles still with a minor issue |
|---|---:|
| nothing (today) | 103 (100.0 %) |
| reviewer byline on every article | 85 (82.5 %) |
| + calculator link on every matching article | 43 (41.7 %) |
| + an in-content link to every orphan | 16 (15.5 %) |
| + external links, titles and the 5 video-only posts | 0 |

## Severity rules

Defined in the script (`SEVERITY`) and copied into `crawl-before.json` → `severity_definitions`.

- **Serious:** page answers 4xx/5xx; broken internal link (target 4xx/5xx, including links to 410 tag archives); missing title; missing or duplicate meta description on an article; duplicate title on an indexable page; no H1 or more than one H1; noindex on an article; canonical pointing to another URL; internal link through more than 1 redirect hop.
- **Minor:** title over 60 or under 30 characters; description over 160 or under 70 characters (or missing on a non-article page); image without alt text; broken external link (404/410/5xx/host not found); orphan article; no link to the matching calculator; health article without author or reviewer; article body under 300 words.
- **Info (not counted):** internal link with exactly one redirect; external host refused a bot (401/403/429/999) or timed out; site brand ("Lifeline Daily") shown as author; missing canonical; noindex on a non-article page.
- Every post counts as a health article. Orphan means no link from inside the body of another article or page; links from category, tag, author and paged archives and the sitemap do not count.

## Totals by issue type

| Severity | Issue | Count | Articles affected | Meaning |
|---|---|---:|---:|---|
| serious | `broken_internal_link` | 51 | 30 | Internal link target answers 4xx/5xx or fails |
| serious | `title_duplicate` | 10 | 0 | Title is used by another indexable page |
| serious | `h1_multiple` | 2 | 0 | More than one H1 |
| serious | `canonical_mismatch` | 1 | 0 | Canonical points to a different URL |
| minor | `title_too_long` | 6 | 5 | Title over 60 characters |
| minor | `title_too_short` | 5 | 1 | Title under 30 characters |
| minor | `description_too_long` | 2 | 0 | Meta description over 160 characters |
| minor | `description_missing_nonarticle` | 117 | 0 | Non-article page has no meta description |
| minor | `external_link_broken` | 9 | 6 | External link answers 404/410/5xx or the host does not resolve |
| minor | `orphan_article` | 34 | 34 | Article has no in-content link from another article or page |
| minor | `calculator_link_missing` | 63 | 63 | Article topic matches a calculator but the article does not link to it |
| minor | `reviewer_missing` | 103 | 103 | Health article shows no reviewer ("Reviewed by") |
| minor | `thin_content` | 5 | 5 | Article body under 300 words |
| info | `internal_link_redirect` | 6 | 4 | Internal link goes through exactly 1 redirect (works, but should point at the final URL) |
| info | `external_link_blocked` | 60 | 37 | External host refused the check (401/403/429/999) — not verifiable by a bot |
| info | `author_generic` | 103 | 103 | Author shown is the site brand, not a named person |

External links: 723 fine, 9 broken, 57 refused the bot (info), 3 other client errors (405 from nia.nih.gov, 402 from two verywell pages; they work in a browser, not counted), no timeouts.

## All serious issues

51 of the 64 are broken internal links (next section). The other 13:

| Page | Issue | Detail |
|---|---|---|
| / | h1_multiple | the header logo is an H1, printed 4 times (desktop, mobile, sticky); Terms has no H1 of its own |
| /terms-conditions/ | h1_multiple | the header logo is an H1, printed 4 times (desktop, mobile, sticky); Terms has no H1 of its own |
| /category/fitness/ | title_duplicate | same title as /tag/fitness/ |
| /category/health-conditions/ | title_duplicate | same title as /tag/health-conditions/ |
| /category/wellness/mental-health/ | title_duplicate | same title as /tag/mental-health/ |
| /category/metabolic-health/ | title_duplicate | same title as /tag/metabolic-health/ |
| /category/video/ | canonical_mismatch | Yoast canonical is /category/news/video/, which also answers 200: two live URLs for one archive |
| /tag/fitness/ | title_duplicate | same title as /category/fitness/ |
| /tag/health-conditions/ | title_duplicate | same title as /category/health-conditions/ |
| /tag/healthy-living/ | title_duplicate | same title as /category/wellness/healthy-living/ |
| /tag/mental-health/ | title_duplicate | same title as /category/wellness/mental-health/ |
| /tag/metabolic-health/ | title_duplicate | same title as /category/metabolic-health/ |
| /category/wellness/healthy-living/ | title_duplicate | same title as /tag/healthy-living/ |

The 10 duplicate titles are 5 category/tag pairs with the same name (Fitness, Health Conditions, Mental Health, Metabolic Health, Healthy Living). No article has a missing or duplicate title or description, no article is noindex, and every article has exactly one H1.

## Broken internal links

13 distinct dead targets, linked 51 times from 30 articles. 45 links are in article bodies (old slugs, 404); 6 are tag links to tag archives the owner set to 410 (Gone) in Yoast (`cancers`, `supplement`).

| Dead target | Status | Links | Fixed on staging? |
|---|---:|---:|---|
| /reduce-chronic-inflammation-naturally/ | 404 | 8 | redirects.json → /reduce-chronic-inflammation-causes-tips/ |
| /healthy-fats-good-vs-bad-fats/ | 404 | 8 | redirects.json → /healthy-fats-vs-bad-fats-what-your-body-needs/ |
| /high-blood-pressure-causes-treatment/ | 404 | 7 | redirects.json → /high-blood-pressure-causes-symptoms-treatment/ |
| /blood-sugar-control-foods/ | 404 | 7 | redirects.json → /foods-that-control-blood-sugar-naturally/ |
| /tag/cancers/ | 410 | 5 | hidden by `hide_gone_tags` |
| /improve-gut-health-microbiome/ | 404 | 4 | redirects.json → /gut-microbiome-how-it-impacts-your-health-tips-to-improve-gut-health/ |
| /prevent-type-2-diabetes-strategies/ | 404 | 4 | redirects.json → /how-to-prevent-type-2-diabetes/ |
| /why-heart-specialists-are-obsessed-with-this-ancient-mediterranean-secret/ | 404 | 2 | redirects.json → /mediterranean-diet-for-heart-health/ |
| /diabetes-complications-uncontrolled-blood-sugar/ | 404 | 2 | redirects.json → /diabetes-complications-explained/ |
| /beginners-guide-to-starting-a-yoga-practice-for-health-benefits/ | 404 | 1 | redirects.json → /yoga-for-beginners-how-to-start/ |
| /omega-3-foods-heart-brain-health/ | 404 | 1 | **no, needs a new redirects.json entry** |
| /dash-diet-for-blood-pressure-your-practical-guide/ | 404 | 1 | **no, needs a new redirects.json entry** |
| /tag/supplement/ | 410 | 1 | hidden by `hide_gone_tags` |

Every link (source → target → status):

| Source article | Target | Status | Where |
|---|---|---:|---|
| /mix-of-exercises-for-longevity/ | /beginners-guide-to-starting-a-yoga-practice-for-health-benefits/ | 404 | article body |
| /atkins-diet-how-it-works-guide/ | /blood-sugar-control-foods/ | 404 | article body |
| /benefits-of-keto-diet/ | /blood-sugar-control-foods/ | 404 | article body |
| /best-keto-foods/ | /blood-sugar-control-foods/ | 404 | article body |
| /foods-to-reduce-belly-fat/ | /blood-sugar-control-foods/ | 404 | article body |
| /keto-diet-for-beginners/ | /blood-sugar-control-foods/ | 404 | article body |
| /types-of-keto-diets/ | /blood-sugar-control-foods/ | 404 | article body |
| /what-is-fibermaxxing/ | /blood-sugar-control-foods/ | 404 | article body |
| /best-keto-foods/ | /dash-diet-for-blood-pressure-your-practical-guide/ | 404 | article body |
| /mediterranean-diet-and-diabetes/ | /diabetes-complications-uncontrolled-blood-sugar/ | 404 | article body |
| /mediterranean-diet-for-heart-health/ | /diabetes-complications-uncontrolled-blood-sugar/ | 404 | article body |
| /atkins-diet-how-it-works-guide/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /keto-diet-for-beginners/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /mediterranean-diet-and-diabetes/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /mediterranean-diet-for-heart-health/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /paleo-diet-what-to-eat-guide/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /types-of-keto-diets/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /vegan-diet-for-beginners-guide/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /vegan-diet-types-benefits-guide/ | /healthy-fats-good-vs-bad-fats/ | 404 | article body |
| /cardio-workouts/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /dash-diet-for-blood-pressure-guide/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /health-benefits-of-cycling/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /mediterranean-diet-for-heart-health/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /mix-of-exercises-for-longevity/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /vegan-diet-for-beginners-guide/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /yoga-for-beginners-how-to-start/ | /high-blood-pressure-causes-treatment/ | 404 | article body |
| /colon-cancer-in-younger-adults-rising-rates/ | /improve-gut-health-microbiome/ | 404 | article body |
| /fermented-foods-gut-health-benefits/ | /improve-gut-health-microbiome/ | 404 | article body |
| /gut-bacteria-colon-cancer-risk-prevention/ | /improve-gut-health-microbiome/ | 404 | article body |
| /vegan-diet-types-benefits-guide/ | /improve-gut-health-microbiome/ | 404 | article body |
| /best-keto-foods/ | /omega-3-foods-heart-brain-health/ | 404 | article body |
| /benefits-of-regular-exercise/ | /prevent-type-2-diabetes-strategies/ | 404 | article body |
| /low-carb-diet-guide/ | /prevent-type-2-diabetes-strategies/ | 404 | article body |
| /mediterranean-diet-and-diabetes/ | /prevent-type-2-diabetes-strategies/ | 404 | article body |
| /paleo-diet-what-to-eat-guide/ | /prevent-type-2-diabetes-strategies/ | 404 | article body |
| /benefits-of-keto-diet/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /healthy-fats-vs-bad-fats-what-your-body-needs/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /mediterranean-diet-and-diabetes/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /mediterranean-diet-for-heart-health/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /omega-3-foods-heart-brain-joint-health/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /signs-keto-is-working/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /vegan-diet-types-benefits-guide/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /why-belly-fat-is-more-dangerous-than-you-think/ | /reduce-chronic-inflammation-naturally/ | 404 | article body |
| /colon-cancer-in-younger-adults-rising-rates/ | /tag/cancers/ | 410 | tag list |
| /early-colon-cancer-symptoms-younger-adults/ | /tag/cancers/ | 410 | tag list |
| /gut-bacteria-colon-cancer-risk-prevention/ | /tag/cancers/ | 410 | tag list |
| /lifestyle-factors-colon-cancer-younger-people/ | /tag/cancers/ | 410 | tag list |
| /pfas-cancer-risk/ | /tag/cancers/ | 410 | tag list |
| /omega-3-foods-heart-brain-joint-health/ | /tag/supplement/ | 410 | tag list |
| /benefits-of-keto-diet/ | /why-heart-specialists-are-obsessed-with-this-ancient-mediterranean-secret/ | 404 | article body |
| /cardio-for-beginners/ | /why-heart-specialists-are-obsessed-with-this-ancient-mediterranean-secret/ | 404 | article body |

**Retired URLs (AS-03).** The 10 source paths in `lld-tools/data/redirects.json` all answer **404** on production (expected: 9 × 301 and a 302 for `/healthy-weight/`). This crawl found two more dead slugs that are not in that file: `/omega-3-foods-heart-brain-health/` (live article: `/omega-3-foods-heart-brain-joint-health/`) and `/dash-diet-for-blood-pressure-your-practical-guide/` (live article: `/dash-diet-for-blood-pressure-guide/`).

Internal links with one redirect (info; they work): 3 links to `/from-walking-to-hiit-your-ultimate-guide-to-cardiovascular-exercise/` → `/cardio-workouts/`; `/interval-training-for-beginners-guide/2` → itself; and on Privacy Policy, `https://www.lifelinedaily.com/` and `/contact-us` without the trailing slash.

## Orphan articles

20 articles have **no inbound link at all** from a non-listing page; they can only be reached through category and tag archives and the sitemap. 34 have **no link from inside another article or page body** (this is the minor `orphan_article` issue). The large "any" counts come from the theme's latest-posts widgets, which list the newest posts on every page.

| Article | Inbound links (any) | In-content |
|---|---:|---:|
| /atkins-diet-how-it-works-guide/ | 0 | 0 |
| /basic-yoga-poses-for-beginners/ | 0 | 0 |
| /benefits-of-eating-fatty-fish/ | 0 | 0 |
| /cardio-for-beginners/ | 0 | 0 |
| /early-colon-cancer-symptoms-younger-adults/ | 0 | 0 |
| /foods-to-avoid-on-keto/ | 0 | 0 |
| /foods-to-reduce-belly-fat/ | 0 | 0 |
| /h-pylori-stomach-cancer-causes-prevention/ | 0 | 0 |
| /high-protein-foods/ | 0 | 0 |
| /lifestyle-changes-for-anxiety-that-actually-work/ | 0 | 0 |
| /mediterranean-diet-for-heart-health/ | 0 | 0 |
| /natural-ways-to-lower-high-blood-pressure/ | 0 | 0 |
| /paleo-diet-what-to-eat-guide/ | 0 | 0 |
| /pfas-chemicals-health-risks/ | 0 | 0 |
| /sleep-exercise-and-weight-loss/ | 0 | 0 |
| /types-of-keto-diets/ | 0 | 0 |
| /understanding-depression-causes-symptoms-and-treatments/ | 0 | 0 |
| /vegan-diet-for-beginners-guide/ | 0 | 0 |
| /ways-to-improve-intimacy-relationship/ | 0 | 0 |
| /yoga-for-beginners-how-to-start/ | 0 | 0 |
| /anti-inflammatory-gut-bacteria-2/ | 1 | 0 |
| /foods-that-make-you-feel-full/ | 1 | 0 |
| /gut-health-weight-loss-more-than-calories/ | 1 | 0 |
| /is-pilates-enough-exercise/ | 1 | 0 |
| /pilates-for-core-strength/ | 1 | 0 |
| /the-science-of-orgasms-surprising-health-benefits-you-didnt-know/ | 1 | 0 |
| /pfas-in-food-sources-exposure/ | 8 | 0 |
| /how-to-remove-pfas-from-body/ | 102 | 0 |
| /pfas-cancer-risk/ | 102 | 0 |
| /sleep-and-anxiety-cycle/ | 103 | 0 |
| /japanese-diet-secrets-simple-lessons-better-health/ | 109 | 0 |
| /exercise-for-depression/ | 110 | 0 |
| /lifestyle-and-depression/ | 110 | 0 |
| /social-media-mental-health/ | 110 | 0 |

## Author and reviewer (AS-04)

| | Articles |
|---|---:|
| Author shown (theme byline) | 103 of 103; all show "Lifeline Daily", the site brand, not a named person |
| Reviewer / "Reviewed by" shown | 0 of 103 |
| JSON-LD `reviewedBy` | 0 |

All 103 articles miss a reviewer, so all 103 are listed in the CSV with `reviewer_missing`. The `lld-tools` review byline ("Reviewed by … on …") only appears when a post has `lld_review_owner` and a valid `lld_reviewed_date`; on production nothing renders because the plugin is not there.

## Matching track and calculator

Calculator follows the DEC-13 rule in `lld-tools/data/links.json` (diabetes tags, then the metabolic-health category, then the five food and fitness categories → protein). Track is a keyword match on title, slug, tags and category (rule in the script). The tracks do not exist yet, so this only records the match. **No article links to a calculator from its body today**; the only calculator links are in the site menu.

| Track | Protein calculator | Diabetes calculator | No calculator | Total |
|---|---:|---:|---:|---:|
| Healthy Weight | 18 | 0 | 2 | 20 |
| Blood Sugar Balance | 1 | 6 | 0 | 7 |
| Fitness and Strength | 17 | 0 | 1 | 18 |
| Sleep and Stress | 3 | 0 | 12 | 15 |
| none | 17 | 1 | 25 | 43 |
| **Total** | 56 | 7 | 40 | 103 |

The track and calculator for each article are in `crawl-before-articles.csv` (columns `matched_track`, `matched_calculator`). The 43 "none" articles are mostly gut health, cancer, heart, PFAS and relationship topics. One Blood Sugar article (`/why-blood-sugar-spikes-make-you-hungry/`) gets the protein calculator from its category under DEC-13; check whether its `lld_tool_override` should be `diabetes`.

## Other minor issues on articles

| Article | Issue | Detail |
|---|---|---|
| /gut-microbiome-how-it-impacts-your-health-tips-to-improve-gut-health/ | thin_content | 0 words, video embed only |
| /beyond-the-blues-understanding-depression/ | thin_content | 0 words, video embed only |
| /your-brain-grows-2-larger-from-daily-exercises/ | thin_content | 0 words, video embed only |
| /the-science-of-orgasms-surprising-health-benefits-you-didnt-know/ | title_too_long | 61 chars |
| /the-science-of-orgasms-surprising-health-benefits-you-didnt-know/ | thin_content | 0 words, video embed only |
| /how-much-do-you-know-about-high-blood-pressure-2/ | thin_content | 0 words, video embed only |
| /foods-to-avoid-on-keto/ | external_link_broken | https://fdc.nal.usda.gov/food-details/173944/nutrients -> 404 |
| /foods-to-avoid-on-keto/ | external_link_broken | https://fdc.nal.usda.gov/food-details/173735/nutrients -> 404 |
| /foods-to-avoid-on-keto/ | external_link_broken | https://fdc.nal.usda.gov/food-details/746782/nutrients -> 404 |
| /cardio-for-beginners/ | external_link_broken | https://thewildmomentum.com/how-smart-goals-changed-my-life/ -> 404 |
| /mediterranean-diet-and-diabetes/ | title_too_long | 62 chars |
| /mediterranean-diet-for-heart-health/ | title_too_long | 65 chars |
| /lifestyle-factors-colon-cancer-younger-people/ | external_link_broken | https://coloncancerfoundation.org/ultra-processed-foods-a-risk-factor-for-colorectal-cancer/ -> 404 |
| /insulin-resistance-what-it-is-and-why-it-matters-2/ | title_too_long | 61 chars |
| /gut-health-and-immunity-connected/ | title_too_long | 61 chars |
| /glycaemic-index-blood-sugar-control/ | external_link_broken | https://glycemicindex.com/ -> 500 |
| /glycaemic-index-blood-sugar-control/ | external_link_broken | https://glycemicindex.com/2021/11/watermelon/ -> 500 |
| /benefits-of-eating-fatty-fish/ | title_too_short | 29 chars |
| /pfas-chemicals-health-risks/ | external_link_broken | https://www.michigan.gov/pfasresponse/-/media/Project/Websites/PFAS-Response/Health/Fact-Sheet-How-PFAS-Enters-Body.pdf -> 404 |
| /sleep-and-anxiety-cycle/ | external_link_broken | https://jcsm.aasm.org/doi/10.5664/jcsm.8986 -> 404 |

Images: 146 article images (body and featured image) checked; **all have alt text**. Non-article pages: 117 have no meta description (65 tag, 11 category, 32 paged archive pages, the author archive, home, About, Contact, Privacy, Terms and the three Health Tools pages). 4 page titles are under 30 characters (home "Homepage - Lifeline Daily", About, Contact, Health Tools). The Longevity category title is 63 characters; the Longevity and Sleep category descriptions are 163 and 161 characters.

## Fix plan (priority order)

1. **Deploy `lld-tools` to production**, *code (already built and on staging)*. Turns 43 of the 45 broken body links into one-hop 301s to the live article (`redirects.json`), hides the two 410 tags from tag lists (6 links), adds the calculator call-to-action to the 63 matching articles, renders the review byline, removes the deleted Healthy Living category (one duplicate-title pair, `tidy.json`), and makes the 10 retired URLs answer a redirect (AS-03).
2. **Add the two missing redirects** to `lld-tools/data/redirects.json`, *code (data file in the plugin)*: `/omega-3-foods-heart-brain-health/` → `/omega-3-foods-heart-brain-joint-health/` and `/dash-diet-for-blood-pressure-your-practical-guide/` → `/dash-diet-for-blood-pressure-guide/`. This clears the last 2 broken internal links.
3. **Point the 45 body links straight at the live slugs**, *data (post content edits in the 27 affected articles)*. The redirects then stay only as a safety net and links stop costing a hop.
4. **Review metadata on all 103 articles**, *data*: `lld_review_owner`, `lld_reviewed_date`, `lld_review_status = approved`. Needs reviewer name(s) and review dates from the owner. Optional *code*: add `reviewedBy` to the Yoast Article schema. Consider named authors instead of "Lifeline Daily" (info today, but a trust signal for health content). Also change the author's public slug: the author archive URL is built from the account's e-mail address (redacted as `/author/[redacted]/` in these reports), *data (user nicename)*.
5. **Duplicate category/tag titles** (4 pairs left after step 1), *data / Yoast settings*: merge the tags `fitness`, `health-conditions`, `mental-health`, `metabolic-health` into their categories (with a redirect or 410), or set those tag archives to noindex in Yoast.
6. **H1 on Home and Terms**, *code (theme header template)*: the header logo is an H1 on those templates and appears 4 times; make the logo a non-heading element, give Terms its own H1 and give Home one visible H1.
7. **Video category canonical**, *data*: the Video category's canonical is `/category/news/video/`; fix the category parent or permalink so only one URL is live and canonical, or 301 the other.
8. **Orphans**, *data*: add an in-content link to each of the 34 orphan articles from a related article (20 have no inbound link from anywhere). The calculator call-to-action from step 1 does not fix this.
9. **Broken external links**, *data*: replace the 9 dead links in 6 articles (3 USDA FoodData Central pages, 2 glycemicindex.com pages answering 500, AASM journal, Colon Cancer Foundation, michigan.gov PDF, thewildmomentum.com).
10. **Video-only posts** (5 articles, 0 words of text), *data*: add a summary or transcript of at least 300 words, or merge or retire them with a 301 or 410.
11. **Titles and descriptions**, *data (Yoast)*: shorten the 5 article titles over 60 characters and lengthen the 1 under 30; set Yoast meta-description templates for category and tag archives and write descriptions for the 8 pages; fix the Longevity and Sleep category title and description lengths.
12. **Re-crawl** production with the same command into `crawl-after.json`. AS-02 needs 0 serious issues and at most 2 articles with minor issues.

Steps 1, 2 and 6 are code; everything else is content or Yoast settings.
