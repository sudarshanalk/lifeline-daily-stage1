Must-use plugin lld-tools is built in waves 1-5 (FR1-03/04/05/15/18). Deployed as part of site/.

## Embed contract (FR1-21, DEC-15, DEC-34) — delivered in wave 1

`assets/lld-tool-frame.js` is the parent-side half of the tool embed. The `[lld_tool slug=…]` shortcode (FR1-04) renders
exactly this markup; until it exists the two landing pages carry it as page content (CMP-09, edited in WordPress):

```html
<script>window.lld_tools_settings = { iframe_origin: 'https://lifelinedaily.com' };</script>   <!-- per environment -->
<script src="/wp-content/mu-plugins/lld-tools/assets/lld-tool-frame.js"></script>
<iframe class="lld-tool-frame" id="lld-tool-frame" title="Daily Protein Target"
        src="https://lifelinedaily.com/tools/protein-requirement-tool.html" loading="eager"></iframe>
```

Order and values matter: the `lld_tools_settings` script must come **before** the listener script, and `iframe_origin` is a
bare origin (`https://lifelinedaily.com`, no trailing slash, no path) — anything else fails closed and the iframe stays at the
600 px fallback.

Rules the listener enforces: messages are accepted only when `event.origin === lld_tools_settings.iframe_origin`; the only
schema is `{type:'lld-tool-height', height}`; height is clamped to 600–20000 px; the iframe starts at the 600 px fallback,
has no fixed `height`/`min-height`, and must not be rewritten by LiteSpeed lazy-load (`data-litespeed-src`/`about:blank`) —
exclude the tool iframes in LiteSpeed Cache → Page Optimization → Lazy Load Iframe exclusions (DEC-31). The tool pages post
to `__LLD_ORIGIN__`, substituted per environment at deploy time, never `'*'`.

Test double of the landing page: `tests/e2e/harness/landing.html`; unit test of the listener: `tests/static/TC-FR1-21.test.mjs`.

## Theme layer (S2-L1, Interface Pack) — 28 Oct 2026

`assets/lld-theme.css` restyles the Newspaper Cloud Library templates and the plugin's own blocks to the owner-approved
Interface Pack (`handover/HANDOVER_TO_CLAUDE_INTERFACE_PACK.md`, prototype `handover/interface_pack_hub.html`): dark
`#1a1a1a` header with the mint tagline, `#f8faf9` canvas, 16 px cards, the 780 px reading column at 17 px / 1.75, the
emerald direct-answer box, card-style article lists, pills for sub-topics, the dark footer. Presentation only: the theme
files stay untouched (DEC-22), the calculator pages stay byte-frozen (only the page around the iframe is styled), and the
member screens keep their own shell in `lld-members` until Cursor aligns them.

- Tokens live in the file's single `:root` block and in `design/tokens.json` → `theme`; TC-UI1-09 lints the file.
- Fonts are self-hosted in `assets/fonts/` (latin variable woff2 for Plus Jakarta Sans and Inter, `font-display: swap`,
  preloaded from `wp_head`), so no request goes to Google Fonts.
- Loaded after `lld-tools.css` on every front-end page. To switch it off for a check, dequeue the `lld-theme` handle.

## Category hub block (S2-L4, SCR-44) — 28 Oct 2026

`category-hub.php` renders `[lld_category_hub]` for the category being viewed (handover section 5.2, prototype package 3):
the magazine hero (flagship guide with its 1:1 featured image, "Medically reviewed" chip, excerpt and "Read full guide";
two trending articles with 96 px square thumbnails), the topic tool callout (a `data/tool-cards.json` card) and the Stage 2
track banner ("Start 7-day trial" to `/members/join/`, shown only while `lld-members` is active). Styles are section 5b of
`assets/lld-theme.css`.

- Choices per top category live in `data/categories.json` → `tree[].hub` (`flagship` post id or slug, `trending` ids,
  `tool`, `track`); a child category uses its parent's tool and track. Defaults (owner question 36): flagship = the newest
  article whose review byline says it is medically reviewed (else the newest), trending = the next two newest.
- **Placement:** `wp lld hub apply [--dry-run] [--template=<id>] [--backup-dir=<dir>]` edits the Newspaper Cloud Library
  category template (the theme's `tdb_category_template` setting; post 4139 on staging): the template content is plain
  Composer shortcode text, so a `[vc_row … el_class="lld-hub-row"][vc_column][lld_category_hub][/vc_column][/vc_row]` row is
  inserted directly above the row that holds `[tdb_loop]`, in `post_content` and the Composer's `tdc_content` copy alike,
  after saving both to `~/lld-backups/<env>/hub-template-<id>-<time>.json`. The row is hidden on page 2 onwards
  (`row_hide_on_pagination`) and the shortcode also returns nothing when paged. `wp lld hub revert` restores the saved
  original. Editing the template was chosen over the output-buffer hook because the Composer row keeps the block inside the
  theme's own grid and spacing; the hook stays reserved for the logo H1 fix.
- Pure builders (`lld_hub_entry`, `lld_hub_pick`, `lld_hub_markup`, `lld_hub_template_insert` / `_revert`) are tested in
  PHP CLI without WordPress: `tests/lead/TC-FR2-26-hub.test.mjs`.
