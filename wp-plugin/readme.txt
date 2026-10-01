=== JHMG AI Editor for Divi 5 ===
Contributors:      lucaslopvet
Tags:              divi, divi 5, ai, mcp, editor
Requires at least: 6.0
Tested up to:      7.1
Stable tag:        4.0.0
Requires PHP:      8.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Edit and build Divi 5 pages in plain English with the AI assistant you already use. Every change is checked before it is saved.

== Description ==

Tell your AI assistant what you want changed on your Divi 5 website, in your own words, and it makes the change for you. A built-in checker makes sure a broken page is never saved.

= How it works (in plain words) =

1. Connect your own AI assistant (Claude, ChatGPT, Cursor or Copilot) to your site with one key. It is copy and paste, and takes about two minutes.
2. Tell it what you want in plain English, for example "change the phone number on the Contact page" or "build me a landing page for my dental clinic".
3. The plugin's built-in guides and proven section patterns steer the AI, so it builds real, good-looking Divi 5 pages instead of guessing. It uses your own Media Library images when it can.
4. Before anything is saved, a built-in checker tests the page against fixed rules. If something is wrong, the change is refused with the exact reason and the AI fixes it. If it is fine, it is saved, and the previous version is kept so you can undo.

= What makes it smart =

* **The checker is not AI.** It follows fixed rules: same page in, same verdict out. The AI can be creative, but it can never save a broken page.
* **It knows Divi 5 well.** The checker knows more than 80 Divi 5 block types (modules, sections, rows and columns) and which ones are allowed inside which.
* **Rules come from real Divi 5 pages.** Every rule was taken from pages exported from real Divi 5 sites, not from guesses.
* **Small changes stay small.** Changing one phone number replaces just that text. The rest of the page is not rebuilt or touched.
* **Proven building blocks.** 17 ready-made section patterns (heroes, feature grids, testimonials, contact forms and more), each one tested against the checker, plus landing-page guidance on the order of sections, headlines and calls to action.
* **Sensible images.** The AI looks in your Media Library first. When nothing there fits, it uses a built-in pack of 44 original images that ships with the plugin.
* **One-click undo.** The previous version of each page the AI changes is kept (up to the last 10). Restore it from the plugin's Dashboard with one click, or just ask your AI to undo.
* **Works with the AI assistant you already use.** Claude, ChatGPT, Cursor, VS Code Copilot, or any assistant that supports MCP.
* **Nothing leaves your site through the plugin.** It uses no third-party services. Your AI assistant talks directly to your own site.

= Examples to try =

* "Change the phone number on the Contact page to 555-0142."
* "Make the main heading on the Home page say 'Fresh bread every morning'."
* "Build me a landing page for my dental clinic, with a booking button and three patient reviews."
* "Add a section with our opening hours under the hero on the About page."
* "Undo the last change you made to the Services page."

= What your AI assistant can do (15 tools) =

Read and check:

* `list_divi_pages`: list the pages built with Divi 5.
* `get_page_layout`: read a page's current layout.
* `validate_layout`: check a layout without saving anything.

Edit and create:

* `edit_page_content`: change one piece of text (an email, phone number, price or sentence) without rebuilding the page.
* `update_page_layout`: save a new layout for a page, after the checker approves it.
* `create_page`: create a new page. It is always saved as a draft for you to review and publish.

Undo:

* `list_page_history`, `get_page_history_entry`, `restore_page_version`: see the saved earlier versions of a page and bring one back.

Guides that help the AI build good pages:

* `get_style_guide`: how to build styled Divi 5 layouts, using real Divi 5 settings.
* `get_landing_guide`: how to build a landing page that turns visitors into customers.
* `get_site_guide`: how to plan a site with several pages that link to each other.
* `get_section_recipes`: the 17 ready-made section patterns.
* `get_image_guide`: how to pick the right image for each section, including the built-in image pack.

Images:

* `list_media_images`: look through the images already in your Media Library (read-only; nothing is uploaded, changed or deleted).

= Images =

The plugin includes a pack of 44 original images (hero backgrounds, card and section images, people placeholders and logo placeholders) made for this plugin and bundled inside it. Your AI uses your own Media Library images first and the built-in pack when nothing fits. Nothing is downloaded from other websites.

= Pro add-on =

A separate Pro add-on (sold separately at https://divi5lab.com/plugins/divi-5-ai-editor) adds live stock-photo sourcing and site-level tools.

= Compatible AI assistants =

* Claude (Desktop and Claude Code), through MCP
* Cursor and Windsurf, through MCP
* VS Code with GitHub Copilot, through MCP
* ChatGPT, through a custom GPT Action (OpenAPI)
* Any other MCP client, or any HTTP client through the REST API

Works with Divi 5. Not affiliated with, endorsed by, or sponsored by Elegant Themes. Divi is a trademark of Elegant Themes; this is an independent plugin and is not affiliated with Elegant Themes.

= Privacy =

The plugin stores these things in your own WordPress database:

* The API key (and which user it belongs to).
* An activity log of AI actions: the action, the page, the result, the assistant's name and a hashed IP address. You can clear it from the Settings screen.
* Page history for undo: up to the last 10 previous versions of each page the AI changes (fewer for very large pages, about 768 KB per page at most).

Nothing is sent anywhere by the plugin. All of this is removed when you delete the plugin.

== External services ==

This plugin does not connect to any external service. Your AI assistant connects to your own site using the API key you generate; no data passes through the plugin author's servers.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New, search for "JHMG AI Editor for Divi 5", then install and activate it. (Or upload the `jhmg-ai-editor-for-divi-5` folder to `/wp-content/plugins/` and activate it on the Plugins screen.)
2. Open the **AI Editor** menu in your WordPress admin.
3. Go to **Settings**, choose your AI assistant and copy the ready-made setup into it.
4. Ask your assistant to list your Divi 5 pages to check that it is connected.

= Requirements =

* WordPress 6.0 or later
* PHP 8.1 or later
* The Divi 5 theme (Divi 4 and its shortcode format are not supported)
* Pretty permalinks (Settings → Permalinks, any option except "Plain")
* For ChatGPT: your site must be reachable over public HTTPS

== Frequently Asked Questions ==

= Does the AI get administrator access to my site? =

No. The API key works as the WordPress user who created it, and the AI can only use this plugin's tools. Every page read or change, every new page and every Media Library lookup re-checks that user's WordPress permissions. The tools can only list, read, check, edit, create (as a draft) and undo page content, and read Media Library images (read-only). They cannot install plugins, change users, settings, menus or the front page, or run code.

= Where do images come from? =

From your own Media Library first. When nothing there fits, the AI uses the built-in image pack that ships inside the plugin. Nothing is downloaded from third-party websites.

= Is my data sent anywhere? =

No. The plugin does not send your data anywhere and uses no third-party services. Your AI assistant connects directly to your site with your key. The page content your assistant reads is handled by the AI service you chose (for example Anthropic or OpenAI), under that service's own terms.

= Can the AI break my pages? =

The AI can make mistakes, but it cannot save a broken page. The checker runs before every save. If a layout breaks a rule, it is refused, nothing is saved, and the AI gets the exact reason so it can fix it. If you do not like a change that was saved, restore the previous version from the Dashboard.

= Is the API key safe? =

The key is a long random code stored in your WordPress database and shown only to administrators on the plugin's Settings screen. Keep it private, like a password. You can make a new one at any time with the Regenerate button, which stops the old one from working.

= Does this work with Divi 4? =

No. Divi 4 stores pages as shortcodes. This plugin is made for the Divi 5 page format.

= Does it work on staging sites or multisite? =

It works on any standard WordPress site, including staging sites. Multisite is not officially supported yet.

= Will this slow down my site? =

No. The plugin adds no scripts or styles to your public pages. It only adds its admin screen and the connection endpoints your AI assistant uses.

== Screenshots ==

1. The Dashboard: your setup progress, your results and recent AI edits you can undo.
2. Settings: one API key and a copy-and-paste setup for Claude, Cursor, VS Code, ChatGPT or any MCP client.
3. Features: everything your AI assistant can do on your Divi 5 site.

== Changelog ==

= 4.0.0 =
* Renamed to JHMG AI Editor for Divi 5 (plugin folder and slug: `jhmg-ai-editor-for-divi-5`).
* `create_page` is part of this plugin: create new pages, always saved as drafts.
* New: `list_media_images` lets your AI reuse the images already in your Media Library (read-only).
* New: a built-in pack of 44 original images. Section patterns and guides now use it, so no images are loaded from other websites.
* Undo history (`list_page_history`, `get_page_history_entry`, `restore_page_version`) is part of this plugin.
* Removed the front page, menu, custom CSS and PHP proposal tools and all licensing code from this plugin. Site-level tools are offered in a separate add-on.
* The plugin makes no calls to external services and loads no remote files.
* Dashboard: one card about the separate Pro add-on, which you can dismiss.

= 3.5.0 =
* Undo for AI edits: the previous version of a page is kept when the AI saves it, and can be restored by the AI or from the Dashboard.

= 3.4.0 =
* Support for 24 more Divi 5 modules, each verified to render on Divi 5.14.
* A notice when your Divi is newer than the newest version this plugin was verified on.

= 3.3.0 =
* Prepared for the WordPress.org directory. Verified on WordPress 7.1 and Divi 5.14.

= 3.2.0 =
* Surgical edits: change one piece of text without rebuilding the page (`edit_page_content`).

= 3.1.0 =
* A guided connection screen for Claude, Cursor, VS Code, ChatGPT and other MCP clients.

= 2.0.0 to 3.0.0 =
* MCP server and REST API, OpenAPI spec for ChatGPT, the deterministic Divi 5 checker, the style, site, landing and image guides, the section pattern library and the admin screens.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 4.0.0 =
New name and folder (`jhmg-ai-editor-for-divi-5`). Creating pages, undo and the new Media Library tool are included. The front page, menu, custom CSS and PHP proposal tools are no longer part of this plugin.
