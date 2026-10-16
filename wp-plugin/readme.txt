=== JHMG AI Editor for Divi 5 ===
Contributors:      lucaslopvet
Tags:              divi, divi 5, ai, mcp, editor
Requires at least: 6.0
Tested up to:      7.1
Stable tag:        4.12.0
Requires PHP:      8.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Edit and build Divi 5 pages and Divi Projects in plain English with the AI assistant you already use. Every change is checked before it is saved.

== Description ==

Tell your AI assistant what you want changed on your Divi 5 website, in your own words, and it makes the change for you. A built-in checker tests every change the AI makes before it is saved.

= How it works (in plain words) =

1. Connect your own AI assistant (Claude, ChatGPT, Cursor or Copilot) to your site with one key. It is copy and paste, and takes about two minutes.
2. Tell it what you want in plain English, for example "change the phone number on the Contact page" or "build me a landing page for my dental clinic".
3. The plugin's built-in guides and proven section patterns steer the AI, so it builds real, good-looking Divi 5 pages instead of guessing. It uses your own Media Library images when it can.
4. Before anything is saved, a built-in checker tests the page against fixed rules. If something is wrong, the change is refused with the exact reason and the AI fixes it. If it is fine, it is saved, and the previous version is kept so you can undo.

= What makes it smart =

* **The checker is not AI.** It follows fixed rules: same page in, same verdict out. The AI can be creative, but every save it makes is checked first, so a broken page is never saved by an AI edit.
* **It knows Divi 5 well.** The checker knows more than 80 Divi 5 block types (modules, sections, rows and columns) and which ones are allowed inside which.
* **Rules come from real Divi 5, not guesses.** The rules come from real Divi 5 page exports and, for 24 newer modules (and their child items), from Divi's own module definitions, verified by rendering them in real Divi 5.15.
* **Understands Divi 5.15 components.** Pages that use shared components (reusable sections, rows and modules, including ones with Slots) can be read and edited. Your AI can see which components your site has and is told when a change would do nothing because the component owns that part.
* **Warns before a page is hollowed out.** If a save would remove more than half of a page's text, images or modules, your AI is told and can put it back. Nothing is stopped: a deliberate rewrite is still yours to make.
* **Small changes stay small.** Changing one phone number replaces just that text. The rest of the page is not rebuilt or touched.
* **Preview before saving.** Your AI can ask what a change would do before it saves anything. It gets a list of the blocks and settings that would change, and the checker's verdict. Nothing is saved until it repeats the request for real.
* **Matches your site.** Your AI can look up your site's own Divi colors and presets and reuse them, and it is told when a page uses one your site does not have. It cannot create or change presets or colors: that stays in Divi's Visual Builder.
* **Proven building blocks.** 17 ready-made section patterns (heroes, feature grids, testimonials, contact forms and more), each one tested against the checker, plus landing-page guidance on the order of sections, headlines and calls to action.
* **Sensible images.** The AI looks in your Media Library first. When nothing there fits, it uses a built-in pack of 44 original images that ships with the plugin.
* **One-click undo.** The previous version of each page or Divi Project the AI changes is kept (up to the last 10). Restore it from the plugin's Dashboard with one click, or just ask your AI to undo.
* **Works with the AI assistant you already use.** Claude, ChatGPT, Gemini, Cursor, VS Code Copilot, or any assistant that supports MCP.
* **Nothing leaves your site through the plugin.** It uses no third-party services. Your AI assistant talks directly to your own site.

= Examples to try =

* "Change the phone number on the Contact page to 555-0142."
* "Make the main heading on the Home page say 'Fresh bread every morning'."
* "Build me a landing page for my dental clinic, with a booking button and three patient reviews."
* "Add a section with our opening hours under the hero on the About page."
* "Undo the last change you made to the Services page."

= What your AI assistant can do (21 tools) =

Read and check:

* `list_divi_pages`: list the pages built with Divi 5 that you can edit (pass post_type project to list Divi Projects).
* `get_page_layout`: read the current layout of a page or Divi Project. For a big page your assistant can read just an outline (every block with its place, size and the start of its text) or one block, which costs a fraction of reading the whole page.
* `list_global_styles`: list your site's own Divi colors and presets so the AI can reuse them (read-only).
* `get_site_structure`: list your site's custom post types, taxonomies, image sizes, Divi 5.15 components (name, kind, Slots, how many pages use each) and Advanced Custom Fields field groups so pages fit your site (read-only: field names and the keys your posts use, never values here or theme files; available over MCP and the REST API, not in the ChatGPT Action).
* `get_custom_fields`: read the custom fields of one page or Divi Project you can edit, with their values, so your assistant can write copy that fits and use the right field names (read-only: keys WordPress protects, or that look like passwords, API keys or other secrets, are never shown; available over MCP and the REST API, not in the ChatGPT Action).
* `list_products`: list and count your WooCommerce products with their prices, stock status and categories (read-only; needs the permission to edit products; available over MCP and the REST API, not in the ChatGPT Action).
* `get_product`: read one WooCommerce product in full, including its variations (read-only; never downloadable files, purchase notes or custom fields).
* `validate_layout`: check a layout without saving anything.

Edit and create:

* `edit_page_content`: change one piece of text (an email, phone number, price or sentence) without rebuilding the page or Divi Project. Add `dry_run` to preview the change without saving it.
* `update_page_layout`: save a new layout for a page or Divi Project, after the checker approves it. Add `dry_run` to preview what would change without saving it.
* `create_page`: create a new page. It is always saved as a draft for you to review and publish.
* `html_to_divi`: turn HTML you already have (a page your assistant or another tool wrote) into a Divi 5 layout: the structure, all the copy, the links and the images. It previews by default and saves a new draft only when asked. Scripts, forms, embeds and site navigation are never converted and are listed in a report; colors and fonts are not converted yet (available over MCP and the REST API, not in the ChatGPT Action).

Undo:

* `list_page_history`, `get_page_history_entry`, `restore_page_version`: see the saved earlier versions of a page or Divi Project and bring one back.

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

= Add-on =

A separate, optional Pro add-on is available (details at https://divi5lab.com/plugins/divi-5-ai-editor). This plugin is complete on its own and works the same without it.

= Compatible AI assistants =

* Claude on the web (claude.ai custom connector), by signing in to your own site (turn it on in Settings; needs a public https address)
* Claude (Desktop and Claude Code), through MCP (Claude Desktop needs Node.js installed)
* Gemini CLI, through MCP
* Cursor and Windsurf, through MCP
* VS Code with GitHub Copilot, through MCP
* ChatGPT, through a custom GPT Action (OpenAPI)
* Any other MCP client, or any HTTP client through the REST API

Works with Divi 5. Divi is a trademark of Elegant Themes. This plugin is not affiliated with, endorsed by, or sponsored by Elegant Themes.

= Privacy =

The plugin stores these things in your own WordPress database:

* The API key (and which user it belongs to).
* Only if you turn on sign-in for Claude on the web: the apps that registered, and their access and refresh tokens, in a table in your own database. The tokens are stored only as one-way hashes and you can disconnect any app, or turn the feature off and remove everything, from the Settings screen.
* An activity log of AI actions: the action, the page ID, the result, the assistant's name (or, when it is not recognised, the first 80 characters of its user-agent string) and a hashed IP address (hashed with your site's own secret key, so the IP itself is not stored). You can clear it from the Settings screen.
* Page history for undo: up to the last 10 previous versions of each page the AI changes (fewer for very large pages, about 768 KB per page at most). It is kept in a table in your own database.

Nothing is sent anywhere by the plugin. All of this is removed when you delete the plugin.

== External services ==

This plugin does not connect to any external service. Your AI assistant connects to your own site using the API key you generate (or, if you turn it on, by signing in to your own site from Claude on the web); no data passes through the plugin author's servers.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New, search for "JHMG AI Editor for Divi 5", then install and activate it. (Or upload the `jhmg-ai-editor-for-divi-5` folder to `/wp-content/plugins/` and activate it on the Plugins screen.)
2. Open **AI Editor** in your WordPress admin sidebar.
3. Go to **Settings**, choose your AI assistant and copy the ready-made setup into it.
4. Ask your assistant to list your Divi 5 pages to check that it is connected.

= Requirements =

* WordPress 6.0 or later
* PHP 8.1 or later
* The Divi 5 theme (Divi 4 and its shortcode format are not supported)
* Pretty permalinks (Settings → Permalinks, any option except "Plain")
* For Claude Desktop: Node.js (free, from nodejs.org)
* For Claude on the web (claude.ai): your site must be reachable over public HTTPS
* For ChatGPT: your site must be reachable over public HTTPS, and creating a custom GPT needs a paid ChatGPT plan

== Frequently Asked Questions ==

= Does the AI get administrator access to my site? =

No. The API key works as the WordPress user who created it, and the AI can only use this plugin's tools. Every request checks that this user can still edit pages, and every page listing, read or change, every new page and every Media Library lookup re-checks that user's WordPress permissions (pages they cannot edit are not even listed). The tools can only list, read, check, edit and undo the content of pages and Divi Projects, create pages (as drafts), read Media Library images (read-only), read your site's Divi presets and colors (read-only), read how your site is set up (post types, taxonomies, image sizes and ACF field definitions, read-only), read the custom fields of a page or Divi Project you can edit (read-only), and read your WooCommerce products (read-only). They cannot install plugins, change users, settings, menus or the front page, or run PHP or other server-side code.

= Can the AI add custom CSS, JavaScript or PHP to my site? =

No. The checker refuses any layout that contains custom CSS, a Divi Code module, script, style or iframe markup, a javascript: link or an event attribute, and the plugin has no tool that saves CSS, JavaScript or PHP or changes site settings. Anything already on a page, for example custom CSS you added in the Visual Builder, is kept: the AI cannot add or change code (it can only remove it).

= Where do images come from? =

From your own Media Library first. When nothing there fits, the AI uses the built-in image pack that ships inside the plugin. Nothing is downloaded from third-party websites.

= Is my data sent anywhere? =

No. The plugin does not send your data anywhere and uses no third-party services. Your AI assistant connects directly to your site with your key. The page content your assistant reads is handled by the AI service you chose (for example Anthropic or OpenAI), under that service's own terms.

= Can the AI break my pages? =

The AI can make mistakes, but a broken page is never saved by an AI edit. The checker runs before every save. If a layout breaks a rule, it is refused, nothing is saved, and the AI gets the exact reason so it can fix it. If you do not like a change that was saved, restore the previous version from the Dashboard: undo puts back your own earlier version exactly as it was, and when your AI assistant does the restore it is told whether that version passes the checker.

= Is the API key safe? =

The key is a long random code stored in your WordPress database and shown only to administrators on the plugin's Settings screen. Keep it private, like a password. You can make a new one at any time with the Regenerate button, which stops the old one from working.

= Is it dangerous to let AI edit my site? Is there an approval step? =

Every save is checked by a fixed set of rules (no AI is involved in the check) and a broken layout is refused. You can ask your assistant for a preview first (a dry run shows what would change and saves nothing), new pages are always drafts, and every save can be undone from the Dashboard. The plugin cannot change your site settings, users or plugins, and cannot add custom code. If you want a stricter rule, turn on Approval mode in Settings ("Require a preview before every save"): your assistant must then preview every edit to a page's content before it can save it. Restoring an earlier version is the undo and needs no preview.

= Why do I need this if Claude can already control a browser? =

The plugin edits the Divi 5 layout data directly, so it is faster and more precise, every change is checked before it is saved, and every save can be undone. An assistant that drives a browser works from screenshots and clicks, and nothing stops it from saving a broken layout.

= Does the checking use up my AI tokens? =

No. The check runs in PHP on your own server. Your assistant only reads what the plugin sends back: the tool list it loads once per conversation (about 4,000 tokens), reading the page it is editing (about 300 to 16,500 tokens depending on its size), and the details of what was wrong when a layout is refused (about 400 tokens for one unknown block). These are estimates; the real count depends on the model.

= Does it work with Gemini? =

Yes. It has been tested with Gemini CLI: copy the one command from the Gemini tab on the Settings screen. Any assistant that supports remote MCP servers can connect.

= Does it work with WooCommerce? =
Yes, for reading. Your assistant can list and count your products and read one product in full (name, description, price, stock, categories, variations), so it can answer store questions and write shop pages that match your catalogue. It never changes a product: prices and stock are not touched by this plugin.

= Can it read my ACF fields, custom post types or functions.php? =

It can read how your site is set up: custom post types, taxonomies, image sizes and, when Advanced Custom Fields is active, its field groups and fields (names, labels, types and choices). It reads custom field values only when your assistant asks about one specific page or Project you can edit, never ones WordPress protects or that look like passwords, API keys or other secrets, and it never reads your theme files or `functions.php`. This is available to assistants that connect over MCP or the REST API, not to the ChatGPT Action.

= Can it create global colors and presets? =

No. It reads the ones you already have, reuses them, and warns when a layout uses one your site does not have. It never creates, changes or deletes them.

= Does it work on a local site? =

Assistants that run on your own computer (Claude Code, Claude Desktop, Cursor, VS Code, Gemini CLI) should be able to reach a local site. Use the exact http:// address your local site shows, and turn on pretty permalinks. ChatGPT and other assistants that run in the cloud cannot reach a local site; they need a public https:// address. Local tools such as Laragon, Local or MAMP work with the assistants that run on your computer.

= I get an error on my Mac when I connect Claude Desktop =

Claude Desktop connects through a small helper that needs Node.js. Install the LTS version from nodejs.org, then fully quit Claude Desktop (Cmd+Q) and open it again. The Claude tab on the Settings screen has the details.

= Does it use up my Claude or ChatGPT plan? =

The plugin has no AI of its own, so it adds no AI cost. Your own assistant does the thinking, and its usage counts against whatever plan or API account you already use with it. A small edit costs very little; building a whole page costs more, because your assistant writes the layout. The plugin's checking runs on your server and is free.

= How is this different from Divi AI? =

Divi AI is Elegant Themes' own AI inside the Divi builder. This plugin does something different: it lets the AI assistant you already use (Claude, ChatGPT, Gemini, Cursor, Copilot) read and edit your Divi 5 pages from outside the builder, in plain English. Every change is checked before it is saved and can be undone.

= How is it different from other AI plugins for WordPress? =

It is built for one job: editing Divi 5 layouts safely on a live site. It knows the Divi 5 page format, refuses any layout that is broken before it is saved, offers a preview of every change, makes new pages drafts, and keeps an undo history. It does not run code on your site and does not change your site settings.

= Can I use Claude on the web (claude.ai) without installing anything? =

Yes. On the Settings screen, turn on sign-in for Claude and copy the address it shows. In claude.ai open Customize → Connectors, choose "Add custom connector" and paste the address. Claude opens your own site: you sign in to WordPress and press Allow. There is no Node.js, no config file and no API key to paste. Claude then works as you, with exactly the permissions your account has, and every save is still checked and undoable. You can disconnect it from the Settings screen at any time. It needs your site to be reachable over public https (Claude connects from the internet), so it does not work on a local site; the other ways of connecting are unchanged. It is off until you turn it on.

= Can it turn my HTML page into a Divi page? =

Yes, for the content. Give your assistant the HTML (for example a page it wrote for you) and it can convert it with `html_to_divi`: the structure (sections, columns for card grids, headings, text, buttons, images), every word and every link. It previews first and tells you what was not converted; with one more step it saves a draft. Scripts, forms, embedded videos, site navigation and CSS are never converted, colors and fonts are not converted yet, and images become placeholders for you to replace with your own. It does not fetch web addresses. For a pixel-accurate copy of a live web page you need a dedicated URL-to-Divi converter instead.

= Can the AI change a page's title? =

Yes. Ask your assistant to rename the page and it uses the update_page_layout tool with a new title (the page can be a Divi page or a Divi Project). This changes the WordPress page title: what shows in the page list and, by default, the browser tab. It is not the same as the big headline (the h1) inside the page layout, which is part of the layout and is changed separately; if the two match, your assistant should ask whether you want both changed. It changes only the title: not the page address (slug). A title change is not covered by Undo, so your assistant tells you the old title when it changes one, and a title change is not listed under "Recent AI edits" on the dashboard, because that list shows saved versions you can restore and a title change has none. You can ask for a preview first.

= Does this work with Divi 4? =

No. Divi 4 stores pages as shortcodes. This plugin is made for the Divi 5 page format.

= Can it edit pages that use modules from other Divi add-on plugins? =
Not yet. The checker only knows Divi's own modules, so it refuses to save a page that contains a module from another plugin (for example Divi Pixel or Divi Plus), even for a text change. Your assistant can still read such a page, and it can edit every other page. Nothing is changed on a refused save, and the reply tells your assistant exactly which block was not recognised.

= Does it work on staging sites or multisite? =

It works on any standard WordPress site, including staging sites. Multisite is not officially supported yet.

= Will this slow down my site? =

No. The plugin adds no scripts or styles to your public pages. It only adds its admin screen and the connection endpoints your AI assistant uses.

== Screenshots ==

1. The Dashboard: your setup progress and your results.
2. Settings: one API key, a copy-and-paste setup for Claude, Cursor, VS Code, Gemini, ChatGPT or any MCP client, and a Test connection button that checks your setup and shows plain-English errors if anything is wrong.
3. Features: everything your AI assistant can do on your Divi 5 site.

== Changelog ==

= 4.12.0 =
* New: works with Divi 5.15. Pages that use Divi 5.15 components (a section, row or module saved once and reused) and their Slots are now accepted, so your assistant can read and edit them instead of being refused. Verified by saving real builder-made components and rendering them in Divi 5.15.1. A Slot in a row or section, a row or another Slot inside a Slot, and a column saved as a component are still refused until they have been checked the same way.
* New: your assistant can see your site's components with `get_site_structure` (name, kind, Slot ids, how many pages use each; never their content) and is told when a change inside a component would not show, because Divi displays the component's own content on every page that uses it.
* New: your assistant is told when a save would remove more than half of a page's text, images or modules, so a rewrite from a partial copy is caught. It is a warning, not a refusal, and Undo is available.
* New: for a big page your assistant can read an outline or a single block instead of the whole layout (`get_page_layout` with `view`). On the real pages used for testing an outline is about a sixth of the size of the full layout.
* Improved: a refusal is much shorter. The first violation about a block keeps its list of allowed blocks, later ones point back to it, and at most 25 are listed. In testing, a refusal that lists 96 nesting mistakes dropped from about 36,000 tokens to about 2,000.
* Improved: a style value with an unclosed quote or bracket (for example `calc(10px`) is now refused. Divi writes it into the page's stylesheet as it is, and on Divi 5.15.1 it made every section lose its custom styling.
* Improved: verified on Divi 5.15.1 (converter, style shapes, global styles, page titles and undo all re-checked on it).

= 4.11.0 =
* New: your AI assistant can now change the title of an existing page or Divi Project. Ask it to rename a page and it changes only the WordPress title (what shows in the page list and, by default, the browser tab), or the title and the layout together. It can preview the change first, follows Approval mode, only touches pages you can edit, and never changes the page address (slug) or leaves the layout rewritten. A title change is not covered by Undo, so your assistant tells you the old title and can set it back with one more request.
* Improved: the style guide now explains that the page title and the big headline inside the layout are different things, so a request to "change the title" is no longer answered by editing only the headline.

= 4.10.0 =
* New: connect Claude on the web (claude.ai) with nothing to install. Turn on sign-in for Claude on the Settings screen, add the address it shows under Customize → Connectors in claude.ai, sign in to your own site and press Allow. Claude then works as you, with the permissions your account has, and every save is still checked and undoable. It is off until you turn it on, needs a public https address, and you can disconnect any app, or turn it off and remove everything, from the Settings screen.
* New: `html_to_divi` turns HTML you already have into a Divi 5 page: the structure, every word, every link and the images. It previews first and tells you what it could not convert (scripts, forms, embeds, site navigation and CSS are never converted); with one more step it saves a draft. Colors and fonts are not converted yet.
* New: a Test connection button on the Settings screen checks the address, the key and the permissions, and says in plain English what is wrong (for example a firewall blocking the REST API, plain permalinks, or a key owner who cannot edit pages).
* Improved: the style guide now teaches the per-device settings that were checked on a real Divi: a smaller headline on phones, text size and alignment per device, spacing per device, and columns that stack on a phone. The image guide teaches how to make images of different shapes the same size with Divi's own settings.
* Improved: the Settings screen explains that the ChatGPT route needs a paid ChatGPT plan, and mentions Laragon next to the other local tools. New FAQ answers cover plan usage, Divi AI, other AI plugins, Claude on the web and HTML conversion.
* Changed: 21 tools instead of 20 (`html_to_divi`).

= 4.9.0 =
* New: your AI assistant can now see your WooCommerce products. `list_products` lists and counts them with their prices, stock status and categories, and `get_product` reads one product in full, including its variations. Both are read-only: they never show downloadable files, purchase notes, customer or order data, or the text of a password-protected product, and they need the permission to edit products (shop managers and administrators have it). Available over MCP and the REST API, not in the ChatGPT Action.
* Improved: `get_site_structure` now also says how many items each content type has, so your assistant can answer "how many products (or pages, or posts) do I have?".

= 4.8.0 =
* New: your AI assistant can edit Divi Projects, not only pages. Every tool that reads, checks, saves, previews or undoes a page's layout now also works on a Divi Project, with the same checks, history and Approval mode. `list_divi_pages` takes an optional `post_type` (page or project) to list them. New pages are still always drafts, and posts and other content types are still refused.
* New: `get_custom_fields` lets your assistant read the custom fields of one page or Divi Project you can edit, with their values, so the copy it writes fits and it uses the right field names. It is read-only. Keys WordPress keeps private, and fields that look like passwords or tokens, are never shown, and long values are cut. Available over MCP and the REST API, not in the ChatGPT Action.
* Improved: `get_site_structure` now also lists the custom field names your posts use (names only, never values), and the style guide explains how to show a custom field in a module.
* Fixed: a page with an Email Optin (signup) module that has custom fields can now be edited by your AI assistant. Before, the check did not recognise those custom fields and refused to save any change to that page, even one far from the form.

= 4.7.0 =
* New: your AI assistant can now use your Divi number and text variables in the pages it builds (for example a padding or heading-size variable, or a shared line of text), the same way it already reuses your global colors. The style guide it reads explains exactly where this works, and `list_global_styles` points it there.
* New: when a layout uses a variable your site does not have, the check now tells your assistant (Divi would silently drop that value). This is advice only and never blocks a save.
* As before, the plugin only reads: variables, colors and presets are created in Divi itself.
* New: your AI assistant can show a custom field's value in a module, for example an Advanced Custom Fields name, photo or link, and the style guide it reads explains exactly where this works. When a layout uses a custom field your site does not have, the check now tells your assistant (Divi would show an empty module). This is advice only and never blocks a save.
* Improved: when Advanced Custom Fields is active, `get_site_structure` now also says where each field group applies (for example "post_type == page"), so your assistant only uses fields that exist on the page it is editing.
* Improved: the Settings screen now shows one line saying when your AI assistant last reached your site and what it did, or that no request has arrived yet, so you can tell at a glance whether the connection works.
* Improved: with Approval mode on, a preview now only counts while the page is still as it was when previewed. If the page changed in the meantime (for example you saved it in the Divi builder), your assistant has to preview again.
* Fixed: an assistant that sends the standard MCP notifications other than the first one (such as a cancellation) no longer gets an error back.

= 4.6.0 =
* New: your AI assistant can look up how your site is set up with `get_site_structure`: custom post types, taxonomies, image sizes and, when Advanced Custom Fields is active, its field groups and fields (names, labels, types and choices; never field values). Read-only.
* New: Approval mode (Settings screen, off by default). When it is on, your AI assistant must preview every edit to a page's content before it can save it.
* Improved: clearer setup on the Settings screen. Claude Desktop now gets the working setup (it connects through a small Node.js helper, so the screen says Node.js is needed and what to do if Mac or Windows reports a missing `npx`), Gemini CLI has its own tab, and there is a note on which assistants can reach a local site. The Features screen says that your global colors, variables and presets are read-only.
* Fixed: the connection check (`ping`) now answers exactly as the MCP specification asks, which strict clients require.
* New: FAQ entries on safety, token use, Gemini, local sites and the Mac connection error.

= 4.5.0 =
* Fixed: the "Recent AI edits" list on the dashboard now shows only pages you can restore. Edits to other kinds of layouts, such as a header or footer saved by an add-on, no longer appear there with a Restore button that cannot work.

= 4.4.0 =
* Safer: your AI assistant can no longer save custom CSS, JavaScript or PHP through a page layout. The checker refuses custom-CSS fields, Divi Code modules, script, style and iframe markup, javascript: links and event attributes, and says exactly where. Code already on a page, for example custom CSS you added in the Visual Builder, stays as it is.
* New: your AI assistant can look up your site's own Divi colors and presets, so the pages it builds can match your site. A new read-only tool, `list_global_styles`, lists them.
* New: when a page uses a color or preset your site does not have, the save and preview results now say so. The save still goes ahead, and Divi shows that part of the page with its default look.
* It cannot create or change presets or colors.

= 4.2.0 =
* New: preview before saving. `update_page_layout` and `edit_page_content` accept `dry_run`: they return the checker's verdict and a short list of the blocks and settings that would change, and save nothing.

= 4.1.0 =
* Undo history is now kept in its own table in your database instead of in each page's custom fields. Pages and the front end no longer load it, and copying or exporting a page no longer carries its history along.
* Existing undo history is moved over automatically. Nothing changes in how undo works or in what your AI assistant sees.
* Going back to version 4.0.0 afterwards is not supported: it would not find the moved history.
* Deleting a page still deletes its undo history.

= 4.0.0 =
* Renamed to JHMG AI Editor for Divi 5 (plugin folder and slug: `jhmg-ai-editor-for-divi-5`).
* `create_page` is part of this plugin: create new pages, always saved as drafts.
* New: `list_media_images` lets your AI reuse the images already in your Media Library (read-only).
* New: a built-in pack of 44 original images. Section patterns and guides now use it, so no images are loaded from other websites.
* Undo history (`list_page_history`, `get_page_history_entry`, `restore_page_version`) is part of this plugin.
* Removed every tool that changed site settings or saved CSS or PHP code, and all licensing code, from this plugin.
* The plugin makes no calls to external services and loads no remote files.
* Dashboard: one card about the separate add-on, which you can dismiss.

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
* A guided connection screen for Claude, Cursor, VS Code, Gemini, ChatGPT and other MCP clients.

= 2.0.0 to 3.0.0 =
* MCP server and REST API, OpenAPI spec for ChatGPT, the deterministic Divi 5 checker, the style, site, landing and image guides, the section pattern library and the admin screens.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 4.7.0 =
Your AI assistant can reuse your Divi number and text variables and is warned about ones that do not exist. Nothing changes unless your assistant uses them.

= 4.6.0 =
Adds a read-only look-up of your site's post types, taxonomies, image sizes and ACF fields for your AI assistant, an optional Approval mode, and clearer setup instructions. Nothing changes unless your AI assistant uses it or you turn Approval mode on.

= 4.5.0 =
The dashboard's recent-edits list only shows pages you can restore. Nothing else changes.

= 4.4.0 =
Adds a read-only look-up of your site's Divi colors and presets for your AI assistant, and a note when a page uses one your site does not have. Nothing changes unless your AI assistant uses it.

= 4.2.0 =
Adds an optional preview (`dry_run`) to the two tools that save pages. Nothing changes unless your AI assistant uses it.

= 4.1.0 =
Undo history moves to its own database table. Your existing history is moved over automatically after the update; there is nothing to do.

= 4.0.0 =
New name and folder (`jhmg-ai-editor-for-divi-5`). Creating pages, undo and the new Media Library tool are included. Tools that changed site settings or saved CSS or PHP code are no longer part of this plugin.
