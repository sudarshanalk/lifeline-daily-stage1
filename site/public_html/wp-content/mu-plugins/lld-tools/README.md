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
