# FR2-20 (L3) and FR2-21 (L4) — staging after the article updates, 25 October 2026

Crawl of **staging** with `node tools/lead/seo-crawl.mjs --base https://stg.lifelinedaily.com --out reports/stage2/l3/crawl-staging-2026-10-25.json --rate 4 --redirects site/public_html/wp-content/mu-plugins/lld-tools/data/redirects.json`. Raw result: `crawl-staging-2026-10-25.json` and `crawl-staging-2026-10-25-articles.csv`. Answer-engine check: `wp lld aeo report --format=csv` on staging, saved as `reports/stage2/l4/aeo-report-staging-2026-10-25.csv`. Production is unchanged; the before crawl of production is `summary.md`.

## Headline numbers

| | Production before | Staging after |
|---|---:|---:|
| Serious issues (excluding staging's site-wide noindex) | 64 | **0** |
| Broken internal link targets | 13 | **0** |
| Article body links through a redirect | 6 | 0 (2 left on non-article pages) |
| Orphan articles (no in-content link) | 34 | **0** |
| Articles missing the calculator link | 63 | **0** |
| Article titles outside 30-60 characters | 6 | **0** |
| Broken external links in articles | 9 | **0** (dead links unwrapped, text kept) |
| Articles with a minor issue other than the reviewer byline | 85 | **5** (the video-only posts, thin content) |
| Articles showing a reviewer | 0 | 0 (owner sign-off pending) |
| Retired URLs answering a redirect | 0 of 10 | 17 of 18 (the 18th is fixed in this change, see below) |
| Priority-1 articles with direct answer, FAQ and dated sources | 0 | **37 of 37** (`answer_ok` and `sources_ok` on all 37) |

**Staging noindex.** Staging is hidden from search, so every article reports `noindex_article` (103, serious by the script's rule). This is the staging setting, not an article fault; production articles are indexable (`summary.md`).

## What changed

On staging, in the database (each post backed up first, outside the repository):

- 52 body links in 26 articles pointed straight at the live article instead of an old slug.
- 30 articles gained a "Read next" line linking the 34 orphan articles.
- SEO titles fixed on 6 articles (4275, 4992, 4346, 980, 5711, 5822).
- "your GP" changed to "your doctor" in 4 articles; 9 dead external links unwrapped in 6 articles.
- 37 priority-1 articles (published, fate keep or update) carry `[lld_answer]` as the first block, a 3 to 5 question `[lld_faq]` and a dated `[lld_sources]` list, with `lld_review_status = pending`. Every source was opened before it was cited. Medical claims in the articles were not changed; doubtful ones are listed for the owner.

In code (lld-tools, PR #70 and this change):

- Each article ends with a link to its top-level category hub (`hub_link`).
- The DEC-13 calculator rule now includes the S2-L2 category slugs, with the same meaning as before.
- One H1 per page: the theme's extra logo H1s on Home and Terms become `div.lld-logo-h1`.
- `redirects.json`: old addresses of three drafts the owner approved for discard go to the live article. `/boost-immunity-naturally/` (the dead end of an existing Yoast redirect from the fourth draft's address) now goes to `/gut-health-and-immunity-connected/`.

## Left open

- **Reviewer byline (103 articles):** needs the owner's review of the answer blocks, then `lld_review_owner`, `lld_reviewed_date` and `lld_review_status = approved`.
- **Video-only posts (5):** thin content. Each needs a written summary, or a merge or retire decision.
- **Non-article pages:** 124 archive and page meta descriptions are missing; 4 page titles are under 30 characters (Home, About, Contact, Health Tools); the Longevity and Weight & Healthy Eating category titles are over 60.
- **Article fates:** no merge, retire or publish has been applied. The owner approved 10 draft discards; moving them to the WordPress bin on staging is still to do.
- **Priority 2 and 3, and priority-1 drafts:** no answer blocks yet. The 17 priority-1 drafts get theirs when they are rewritten for publishing.
