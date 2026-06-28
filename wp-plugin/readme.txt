=== AI Editor for Divi 5 ===
Contributors:      jhmg
Tags:              divi, divi 5, ai, editor, page builder
Requires at least: 6.0
Tested up to:      7.0
Stable tag:        2.13.0
Requires PHP:      8.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Edit your Divi 5 pages with plain-English instructions from any AI assistant — changes are validated before they land.

== Description ==

AI Editor for Divi 5 connects your WordPress site to AI assistants (Claude, Cursor, VS Code Copilot, ChatGPT) via the Model Context Protocol (MCP) and a standard REST API. You describe what you want in plain English; the AI edits the Divi 5 layout and the plugin's built-in validator checks the result before anything touches the database.

**How it works**

1. You connect your AI assistant once using the API key shown in Settings.
2. You type a plain-English instruction: *"Change the hero heading on Home to 'Welcome back'"*.
3. The AI fetches the live page layout, applies your change, and submits it back.
4. The validator checks every Divi 5 block type, required attribute, and nesting rule deterministically — no AI involved.
5. Valid layouts are saved instantly. Invalid layouts return exact violation messages so the AI self-corrects and retries.

**Seven tools your AI gets**

* `list_divi_pages` — list all pages built with Divi 5
* `get_style_guide` — real Divi 5 structure + styling vocabulary so the AI builds styled, not plain, layouts
* `get_section_recipes` — a library of complete, validated section patterns (hero, feature grid, split, slider, CTA, footer) the AI assembles pages from
* `get_page_layout` — read the current layout of any page
* `validate_layout` — dry-run a change without saving
* `update_page_layout` — validate then save (the live edit tool)
* `create_page` — build a brand-new page from scratch (premium — requires a license)

**Compatible AI assistants**

* Claude Desktop (MCP)
* Cursor / Windsurf (MCP)
* VS Code + GitHub Copilot (MCP)
* ChatGPT Custom GPT (OpenAPI actions)
* Any HTTP client via the REST API

**Privacy**

The plugin stores a single API key and an optional usage log in your WordPress database. No data is sent to any external server. All AI communication goes directly between your AI assistant and your WordPress site.

== Installation ==

1. Upload the `ai-editor-divi5` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings → AI Editor for Divi 5**.
4. Copy the API key and follow the step-by-step instructions for your AI assistant.

**Requirements**

* WordPress 6.0 or later
* PHP 8.1 or later
* Divi 5 (not Divi 4 / classic shortcode mode)
* Pretty permalinks enabled (Settings → Permalinks — any option except Plain)

== Frequently Asked Questions ==

= Does this work with Divi 4? =

No. Divi 4 uses PHP shortcodes. This plugin is built specifically for the Divi 5 JSON block format introduced in late 2024.

= Is the API key secure? =

Yes. The key is a 32-byte random hex string stored in `wp_options`. It is never exposed in page source or JavaScript. All requests require the key as a Bearer token over HTTPS. You can regenerate it at any time from the Settings page.

= Can the AI break my pages? =

The validator runs before every save. If the AI produces an invalid layout it is rejected and the AI receives the exact list of violations so it can self-correct. Your live page is never modified by an invalid layout.

= Does this work with staging or multisite? =

The plugin works on any standard WordPress install, including staging environments. Multisite is not officially supported in this version.

= What AI assistants are supported? =

Any assistant that supports MCP Streamable HTTP (Claude Desktop, Cursor, Windsurf, VS Code Copilot) or OpenAPI REST actions (ChatGPT Custom GPTs). The REST API is also directly accessible from scripts, Zapier, Make, or any HTTP client.

= Will this slow down my site? =

No. The plugin registers REST routes and an admin page but adds no front-end scripts or database queries to page loads.

== Screenshots ==

1. The Settings page — API key, connection instructions, and usage stats side by side.
2. Claude Desktop connected and editing a Divi 5 page from a plain-English prompt.
3. The validator blocking an invalid layout and returning violation details to the AI.

== Changelog ==

= 2.13.0 =
* Redesigned admin into a guided, SaaS-style experience: a single top-level "AI Editor" menu with Dashboard, Features, Settings and Upgrade views. Dashboard shows a welcome, setup-progress checklist, one clear primary action, your results (edits processed / saved / blocked), and contextual recommendations. Features and Upgrade present Free vs Premium as benefit-framed value (locked-but-visible), not banners. Empty states throughout. Fully escaped, nonce + capability protected, i18n-ready.

= 2.12.0 =
* Added safe site-wide custom CSS: the premium set_custom_css tool writes into a managed block in WordPress Additional CSS (preserving your own CSS), unlocking true backdrop-filter glassmorphism and keyframe animations. CSS cannot execute code.
* Admin page: professional refresh with a clear Free vs Premium capabilities breakdown (live license badge), accurate module count, and broader feature copy.

= 2.11.0 =
* Safe AI-assisted PHP: new propose_php_snippet tool lets the AI draft PHP features (custom post types, hooks, form handlers, integrations) as REVIEWED PROPOSALS. Nothing is executed or written to the site � proposals appear under Settings → Code Proposals for a human to review and apply manually, so the API key never becomes a code-execution credential.

= 2.10.0 =
* Full-site generation (phase 1): new get_site_guide blueprint tool, create_page now accepts a slug for predictable cross-linking, and premium set_front_page + set_primary_menu tools (MCP + REST) wire the homepage and theme navigation. The AI can now build an entire multi-page website from one brief.

= 2.9.2 =
* Expanded the section recipe library to 16 with genericized archetypes mined from the second site: animated number-counter stats, a blog/news feed, and an icon values row.

= 2.9.1 =
* Style guide gains vocabulary mined from the second site: the top-level css freeForm key for custom CSS (enables true backdrop-filter glassmorphism, keyframe animations, pseudo-elements), full-viewport section height (vh), and background-image blend modes.

= 2.9.0 =
* Integrated a second production site (15 pages): added divi/post-nav, allowed divi/global-layout inside a section, and retired the over-strict "leaf module must not have children" rule (real Divi 5 saves text, number-counter, image, tab, contact-field and more as paired blocks). All pages from both sites now validate.

= 2.8.2 =
* Style guide now instructs the AI to collapse multi-column rows to a single column on mobile (row layout.phone.flexDirection=column), so generated pages stack cleanly on phones.

= 2.8.1 =
* Style guide now teaches the side-by-side buttons pattern: wrap a CTA pair in a nested row whose single column is flex row-direction, so two buttons sit on one line (stacking on mobile) instead of stacking vertically.

= 2.8.0 =
* Added divi/number-counter (animated stat counter) to the schema and style guide.
* New SEO rule: a page may have at most one h1 (MULTIPLE_H1) — additional headings must use h2-h6 via headingLevel.
* Fixed border-radius guidance: set all four corners explicitly (sync:on does not propagate one corner to the others at render).

= 2.7.0 =
* Expanded the section recipe library from 7 to 13 genericized, validated patterns mined from real pages: hero/CTA, section intro, 3- and 4-column card grids, blurb features, icon features, split image+text, contact form, slider, image gallery, image carousel, testimonial, and newsletter+social. All example copy and media are genericized (no real content) and every recipe is validated in the test suite.

= 2.6.1 =
* Added 7 more modules from a 52-module coverage export: divi/fullwidth-header, divi/gallery, divi/login, divi/instagram-feed, divi/menu (leaf modules), and divi/timeline + divi/timeline-item. A page exercising all 52 Divi 5 module types now validates.

= 2.6.0 =
* Enriched the style guide with vocabulary mined from a real production site: font family/weight/style (serif headings + sans body, uppercase/italic, letter-spacing), flex layout for reliable multi-column (display:flex + columnGap/rowGap + flexType child sizing), width/centering, background-image positioning, gradient direction, and absolute positioning. The AI can now build richer, properly-laid-out, typographically-styled pages.

= 2.5.0 =
* Major validator robustness from a real 25-page production site: now supports divi/group + divi/group-carousel (flex containers), divi/global-layout (Theme Builder globals, no builderVersion), and divi/code / divi/sidebar / divi/testimonial modules. Also accepts top-level divi/section blocks (pages need not be wrapped in divi/placeholder) and a paired divi/text wrapping nested text. All 25 real pages validate.

= 2.4.0 =
* Added get_section_recipes tool (MCP) and GET /section-recipes endpoint (REST + OpenAPI): a library of complete, validated Divi 5 section patterns (hero, feature grids, split, slider, CTA, footer) derived from real exports. The AI assembles well-composed pages from proven sections instead of building from scratch. Each recipe is validated in the test suite.

= 2.3.1 =
* Style guide now covers the glassmorphism (glass-card) look using confirmed semi-transparent-background shapes, notes that true backdrop-blur needs custom CSS, and adds aesthetic-variety + contrast guidance.

= 2.3.0 =
* Added get_style_guide tool (MCP) and GET /style-guide endpoint (REST + OpenAPI): serves real Divi 5 structure rules and styling attribute shapes mined from real exports, so AI assistants produce styled, creative layouts instead of plain ones. create_page and update_page_layout now point the AI to it.

= 2.2.3 =
* Validator now supports Divi 5 specialty nesting: divi/row-inner and divi/column-inner, plus a divi/column placed directly in a section (confirmed via real exports). Documented CSS filter and entrance-animation attribute shapes in docs/STYLE.md.

= 2.2.2 =
* Validator now recognizes the divi/divider module (confirmed via real export). Documented many real styling attribute shapes (box-shadow, transform, hover, z-index, overflow, scroll-fade, divider) in docs/STYLE.md.

= 2.2.1 =
* Validator now recognizes the divi/social-media-follow module and its divi/social-media-follow-network children (confirmed via real export). Pages using social-follow icons no longer fail validation.

= 2.2.0 =
* Validator now supports nested rows (a divi/row inside a divi/column, recursing to any depth) — confirmed against a real Divi 5 export. Previously these valid layouts were wrongly rejected.
* create_page and update_page_layout now instruct AI assistants to use https://picsum.photos placeholders for images when the user has not supplied a specific image URL, so generated pages are never left with blank images.

= 2.1.2 =
* Fixed content corruption on save: page content is now wp_slash()'d before wp_insert_post/wp_update_post, which run wp_unslash internally. Previously backslashes in escaped HTML (e.g. < for <) were stripped, breaking text modules. Affects create_page and update_page_layout (MCP + REST).

= 2.1.1 =
* create_page now sets the Divi 5 builder meta (_et_pb_use_divi_5, _et_pb_use_builder) so new pages open directly in the Divi 5 editor and appear in list_divi_pages.

= 2.1.0 =
* Added premium `create_page` tool (MCP) and `POST /pages` REST endpoint — create brand-new pages, validated before they are written.
* Added self-hosted license activation (offline Ed25519 key verification, no license server or phone-home) under Settings → License.
* New pages are always created as drafts for the site owner to review and publish.
* Premium calls without an active license are rejected with an upgrade message and create nothing.

= 2.0.0 =
* Renamed to AI Editor for Divi 5 to reflect the AI editing focus.
* Added PHP MCP server (Streamable HTTP, protocol 2024-11-05) — no Node.js required.
* Added OpenAPI 3.1.0 spec endpoint for ChatGPT Custom GPT integration.
* Added two-column admin UI with connection instructions and usage dashboard.
* Added single-key Bearer token authentication with Apache rewrite compatibility.
* Added deterministic Divi 5 layout validator (pure PHP, zero AI inference).
* Added usage logging with per-AI-assistant breakdown.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.1.0 =
Adds the premium create_page feature and license activation. Existing free features are unchanged and no reconfiguration is needed.

= 2.0.0 =
Full rename and rewrite. Deactivate version 1.x before activating 2.0.0. Your API key will be regenerated on first activation.
