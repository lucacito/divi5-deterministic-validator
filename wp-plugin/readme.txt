=== AI Editor for Divi 5 ===
Contributors:      jhmg
Tags:              divi, divi 5, ai, editor, page builder
Requires at least: 6.0
Tested up to:      7.0
Stable tag:        2.2.1
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

**Five tools your AI gets**

* `list_divi_pages` — list all pages built with Divi 5
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
