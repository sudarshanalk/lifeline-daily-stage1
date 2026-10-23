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
