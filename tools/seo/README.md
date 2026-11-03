# tools/seo — checks for P3-80, P3-81 and P3-82

Three read-only checking tools for the Stage 3 search and speed packages. They send GET (and HEAD for external source links) only, at most two requests in flight and 200 ms apart, with the user agent `lld-seo-check`. `--auth user:pass` (staging behind a password) is sent only to the base URL's host, never to a host a redirect or a sitemap entry leads to. The judging logic is in `lib.mjs` and is unit-tested without a network in `tests/stage3/seo/` (test names are the check IDs). The tools themselves are run by hand by the lead against staging; nothing here runs in CI against a live site.

Reports go to `C:\MyProjects\lifeline-daily\05 Reports\Stage3\` (outside the repository; never committed). Each tool writes a JSON report and a Markdown summary next to it (`--out x.json` also writes `x.md`), prints the summary to stderr, and exits 1 when anything fails.

Node needs the repository's `node_modules` (`npm ci`); `speed.mjs` also needs Playwright's Chromium (`npx playwright install chromium`).

## P3-80 — direct answers, sources and FAQ markup (FR3-24)

```
node tools/seo/direct-answers.mjs https://stg.lifelinedaily.com --out "C:\MyProjects\lifeline-daily\05 Reports\Stage3\direct-answers-stg.json"
```

Reads `/sitemap_index.xml`, takes every article from the `post-sitemap*.xml` entries, fetches each page once and judges:

| Check | What passes |
|---|---|
| TC-FR3-24-AS-01 | A `.lld-answer[data-test="direct-answer"]` block (the `[lld_answer]` shortcode) that is the first content of the article body, 1 to 60 words: the same rule as the mu-plugin's `lld_search_aeo_row` (nothing but whitespace before `[lld_answer]`) |
| TC-FR3-24-AS-02 | A `section.lld-sources[data-test="sources"]` (`[lld_sources]`) with at least one absolute link that answers 2xx once its redirects are followed (a redirect loop, more than 5 hops or a redirect without `Location` is an error, never a working link) |
| TC-FR3-24-AS-03 | When the page has `section.lld-faq[data-test="faq"]` (`[lld_faq]`), a `FAQPage` JSON-LD block that parses and whose `Question` names equal the visible `.lld-faq__q` headings. Articles without a FAQ section need no markup; FAQPage markup with no visible section is reported |

Options: `--limit n` (first n articles: the report is marked **partial** and is not a pass for the whole site), `--no-links` (skip the external source-link check), `--auth user:pass`, `--md file`. A post sitemap that cannot be read fails the run (exit 1), since its articles were never checked.

The Markdown summary lists the failing articles by URL under each check. The lead fixes the articles (shortcodes in the article text) and re-runs.

## P3-81 — Search Console and Bing fixes (FR3-25)

```
node tools/seo/search-console.mjs https://stg.lifelinedaily.com --redirects "C:\MyProjects\lifeline-daily\05 Reports\Stage3\old-addresses.txt" --not-indexed "C:\MyProjects\lifeline-daily\05 Reports\Stage3\not-indexed.csv" --out "C:\MyProjects\lifeline-daily\05 Reports\Stage3\search-console-stg.json"
```

| Check | Input | What passes |
|---|---|---|
| TC-FR3-25-AS-01 | `--redirects`: the old addresses, one per line (paths or full URLs, `#` comments allowed), taken by the lead from the 404 and dead-link lists in `05 Reports\Search_Console_and_Bing_Issues.md` | Each redirects with 301 or 308 (up to three permanent hops) to a page that answers 200 and is not `noindex` |
| TC-FR3-25-AS-02 | the site's `/sitemap_index.xml` (every sub-sitemap walked; a sub-sitemap that cannot be read fails the run; `--skip-sitemap` to leave it out) | Every URL answers 200, has no `noindex` meta or `X-Robots-Tag` (the `googlebot: noindex` form and a past `unavailable_after` count), has no canonical pointing elsewhere, and is allowed by `robots.txt`. Staging's `robots.txt` closes the whole site on purpose (`Disallow: /`): the report says so and does not apply the robots rule there; on production it applies |
| TC-FR3-25-AS-03 | `--not-indexed`: a CSV the lead produces from the report's "crawled but not indexed" list, header `slug,action,note`, actions `improved`, `merged` (note = the target slug), `removed`, `kept` (note = the reason) | Every row has a recorded action; `merged` slugs redirect permanently and `removed` slugs answer 404/410 or redirect |

The report in `05 Reports\` lists those articles as a comma-separated run of slugs in prose, not as a table with actions, so the CSV is the structured record; keep it in `05 Reports\Stage3\` and update it as articles are improved, merged or removed.

## P3-82 — lab speed (NFR3-11)

```
node tools/seo/speed.mjs https://stg.lifelinedaily.com --out "C:\MyProjects\lifeline-daily\05 Reports\Stage3\speed-stg.json"
node tools/seo/speed.mjs https://stg.lifelinedaily.com --pages home,/foods-that-control-blood-sugar-naturally/,/mediterranean-diet-and-diabetes/,/diabetes-complications-explained/ --runs 3
```

Without `--pages` it takes the home page and the first three articles of the post sitemap. Same lab profile as `tools/lead/lab-vitals.mjs`: Playwright Chromium emulating a mid-range phone on 4G (412x915, CPU 4x slower, ~9 Mbps / 60 ms, cold cache), three runs per page, medians judged; a page with any errored run fails.

| Metric | Budget | How it is measured |
|---|---|---|
| LCP | < 2.5 s | `largest-contentful-paint` PerformanceObserver |
| INP | < 200 ms | Event Timing API; the worst of two scripted taps, the mobile menu toggle (then closed) and one in-page non-navigating button, as `tools/lead/lab-vitals.mjs` does. When neither is found, **TBT (< 200 ms) stands in as the lab proxy** and the report says so in the "Judged on" column |
| CLS | < 0.1 | `layout-shift` PerformanceObserver, largest session window |

These are LAB results, not real-visitor data. TC-NFR3-11-AS-02 (saving a log entry shows the result in under 1 second) needs a signed-in member and belongs to P3-50's tests; it is not run here.

## Tests

```
npx vitest run -c tests/vitest.config.mjs tests/stage3/seo
```

`TC-FR3-24-AS-01..03`, `TC-FR3-25-AS-01..03` and `TC-NFR3-11-AS-01` run the judging functions over fixture HTML, XML, robots.txt, redirect chains and CSV strings. No network.
